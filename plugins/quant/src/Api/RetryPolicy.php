<?php

declare(strict_types=1);

namespace Quant\Api;

use Closure;

/**
 * Retries a request inside one processing run when Quant did not answer or
 * answered with a gateway error. Use it only for idempotent calls (publish
 * and upload, which replace the content at a URL).
 *
 * The queue retries failed jobs later (DatabaseQueue::retryDue), but only
 * when WP-Cron runs. A short retry here clears most brief stalls in the same
 * run, so an editor who pushes from the admin does not see a failure.
 */
final class RetryPolicy
{
    /** Statuses that mean a proxy or the API was briefly unavailable. */
    public const RETRY_STATUSES = [502, 503, 504];

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param array<int> $delaysMs Wait before each retry; the count is the retry limit.
     * @param int $maxJitterMs Random extra wait per retry, so parallel workers do not retry together.
     * @param float $maxElapsedSeconds No retry starts after this much time since the first attempt.
     *   A request that timed out already used its full timeout; this keeps one job from
     *   holding a run for several timeouts in a row.
     * @param (Closure(int): void)|null $sleep Waits the given milliseconds (injected in tests).
     * @param (Closure(): float)|null $clock Current time in seconds (injected in tests).
     */
    public function __construct(
        private readonly array $delaysMs = [1000, 3000],
        private readonly int $maxJitterMs = 500,
        private readonly float $maxElapsedSeconds = 45.0,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * True for a transport failure (no answer) or a gateway status. A local
     * refusal (unreadable file, size limit) also has code 0 but is not a
     * transport failure, and a retry cannot fix it.
     *
     * @param array<string, mixed> $response
     */
    public static function isRetryable(array $response): bool
    {
        $code = (int) ($response['code'] ?? 0);
        if ($code === 0) {
            return !empty($response['transport']);
        }

        return in_array($code, self::RETRY_STATUSES, true);
    }

    /**
     * @param Closure(): array<string, mixed> $send
     * @return array<string, mixed> The last response; 'attempts' is set when there was more than one.
     */
    public function run(Closure $send): array
    {
        $started = ($this->clock)();
        $response = $send();
        $attempts = 1;

        foreach ($this->delaysMs as $delay) {
            $wait = $delay + ($this->maxJitterMs > 0 ? random_int(0, $this->maxJitterMs) : 0);
            if (!self::isRetryable($response) || !$this->hasTimeFor($started, $wait)) {
                break;
            }

            ($this->sleep)($wait);
            $response = $send();
            $attempts++;
        }

        if ($attempts > 1) {
            $response['attempts'] = $attempts;
        }

        return $response;
    }

    private function hasTimeFor(float $started, int $waitMs): bool
    {
        return ($this->clock)() - $started + ($waitMs / 1000) <= $this->maxElapsedSeconds;
    }
}
