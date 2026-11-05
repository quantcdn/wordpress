<?php

declare(strict_types=1);

namespace Quant\Auth;

/**
 * A cross-request mutex for the OAuth token refresh, held as a row in the options table.
 *
 * The portal rotates the refresh token on every use and revokes the whole chain when an old one comes
 * back, so only one request may refresh at a time, and it must save the new tokens before it lets go.
 *
 * The lock row is written with INSERT IGNORE: the unique option_name makes that atomic. add_option() is
 * not, because it runs INSERT ... ON DUPLICATE KEY UPDATE and so also "succeeds" when the row exists.
 * This is the pattern of WP_Upgrader::create_lock().
 */
final class RefreshLock
{
    public const OPTION = 'quant_oauth_refresh_lock';

    /** The value of the lock row this request wrote, while it holds the lock. */
    private ?string $held = null;

    /**
     * @param int $staleAfter Seconds after which a lock counts as abandoned. Keep it above the refresh timeout (60 s).
     *                        A waiting request does not wait this long: it gives up after $polls checks.
     * @param int $pollMicros Pause between checks while another request holds the lock.
     * @param int $polls      Checks before a waiting request gives up.
     */
    public function __construct(
        private readonly int $staleAfter = 90,
        private readonly int $pollMicros = 250000,
        private readonly int $polls = 20
    ) {}

    /**
     * Take the lock. Returns false when another request holds it.
     */
    public function acquire(): bool
    {
        if ($this->insert()) {
            return true;
        }

        $since = $this->heldSince();
        if ($since !== null && (int) $since > time() - $this->staleAfter) {
            return false;
        }

        if ($since !== null) {
            // Remove only that stale row: if another request took it over first, its new row stays.
            $this->deleteRow($since);
        }

        return $this->insert();
    }

    /**
     * Release the lock if this request holds it.
     */
    public function release(): void
    {
        if ($this->held === null) {
            return;
        }

        $this->deleteRow($this->held);
        $this->held = null;
    }

    /**
     * Wait until no request holds the lock, or until the wait runs out.
     */
    public function waitForRelease(): void
    {
        for ($i = 0; $i < $this->polls; $i++) {
            usleep($this->pollMicros);
            if ($this->heldSince() === null) {
                return;
            }
        }
    }

    private function insert(): bool
    {
        global $wpdb;

        // The time the lock was taken, and a random part so that no two holders write the same value.
        $stamp = time() . ':' . bin2hex(random_bytes(4));
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
            self::OPTION,
            $stamp
        ));

        if ((int) $inserted !== 1) {
            return false;
        }

        $this->held = $stamp;

        return true;
    }

    /** The value of the lock row (the time it was taken), or null when there is no lock. */
    private function heldSince(): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s",
            self::OPTION
        ));

        return $value === null ? null : (string) $value;
    }

    private function deleteRow(string $value): void
    {
        global $wpdb;

        $wpdb->delete($wpdb->options, ['option_name' => self::OPTION, 'option_value' => $value]);
    }
}
