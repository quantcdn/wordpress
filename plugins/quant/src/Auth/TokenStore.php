<?php

declare(strict_types=1);

namespace Quant\Auth;

/**
 * Reads and writes the stored OAuth token for the refresh, past every cache.
 *
 * read() goes to the primary database: a replica (HyperDB, LudicrousDB) or the object cache may still
 * hold a token that another request has rotated. replace() is a compare-and-swap: it writes only when
 * the row still holds what this request read, so a refresh never undoes a newer save, a new connect or
 * a disconnect.
 */
final class TokenStore
{
    public const OPTION = 'quant_oauth_token';

    /**
     * The stored token and the raw option value it came from (null when there is no row).
     *
     * @return array{token: array<string, mixed>, raw: ?string}
     */
    public function read(): array
    {
        global $wpdb;

        if (method_exists($wpdb, 'send_reads_to_masters')) {
            $wpdb->send_reads_to_masters();
        }

        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s",
            self::OPTION
        ));
        $raw = is_string($raw) ? $raw : null;
        $token = $raw === null ? null : maybe_unserialize($raw);

        return ['token' => is_array($token) ? $token : [], 'raw' => $raw];
    }

    /**
     * Write $token if the row still holds $expectedRaw. Returns whether it wrote.
     *
     * @param array<string, mixed> $token
     */
    public function replace(string $expectedRaw, array $token): bool
    {
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = BINARY %s",
            maybe_serialize($token),
            self::OPTION,
            $expectedRaw
        ));
        wp_cache_delete(self::OPTION, 'options');

        return (int) $updated === 1;
    }
}
