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
 * when background processing runs. A short retry here clears most brief
 * stalls in the same run.
 *
 * Use one instance per PHP request (the container holds a single
 * QuantContentApi). The instance keeps request-wide state, so a struggling
 * API does not get three times the requests and one job with many uploads
 * cannot hold a run for minutes:
 * - a circuit breaker: once one call used all its retries and still failed,
 *   later calls get no retries until a call succeeds;
 * - one retry time budget for all calls: the waits and retried attempts of
 *   the whole request together stay within $maxElapsedSeconds.
 */
final class RetryPolicy
{
    /** Statuses that mean a proxy or the API was briefly unavailable. */
    public const RETRY_STATUSES = [502, 503, 504];

    /** @var Closure(int): void */
    private readonly Closure $sleep;

    /** @var Closure(): float */
    private readonly Closure $clock;

    private bool $open = false;

    /** Seconds spent in waits and retried attempts in this request. */
    private float $retrySpent = 0.0;

    /**
     * @param array<int> $delaysMs Wait before each retry; the count is the retry limit.
     * @param int $maxJitterMs Random extra wait per retry, so parallel workers do not retry together.
     * @param float $maxElapsedSeconds A retry starts only when the call so far, the wait and one more
     *   attempt as long as the last one fit in this time, and the same holds for the retry time of
     *   the whole request. A fast failure can retry; a request that used its full timeout retries
     *   only when the timeout is short enough.
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
     * refusal (unreadable file, size limit) or another WP_Error also has
     * code 0 but no 'transport' flag, and a retry cannot fix it.
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
        $lastAttempt = ($this->clock)() - $started;
        $attempts = 1;

        foreach ($this->delaysMs as $delay) {
            $wait = ($delay + ($this->maxJitterMs > 0 ? random_int(0, $this->maxJitterMs) : 0)) / 1000;
            if (!self::isRetryable($response) || !$this->mayRetry($started, $wait, $lastAttempt)) {
                break;
            }

            $retryStart = ($this->clock)();
            ($this->sleep)((int) round($wait * 1000));
            $attemptStart = ($this->clock)();
            $response = $send();
            $lastAttempt = ($this->clock)() - $attemptStart;
            $this->retrySpent += ($this->clock)() - $retryStart;
            $attempts++;
        }

        $this->updateBreaker($response, $attempts);
        if ($attempts > 1) {
            $response['attempts'] = $attempts;
        }

        return $response;
    }

    private function mayRetry(float $started, float $wait, float $lastAttempt): bool
    {
        if ($this->open) {
            return false;
        }

        $callElapsed = ($this->clock)() - $started;

        return $callElapsed + $wait + $lastAttempt <= $this->maxElapsedSeconds
            && $this->retrySpent + $wait + $lastAttempt <= $this->maxElapsedSeconds;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function updateBreaker(array $response, int $attempts): void
    {
        if (!self::isRetryable($response)) {
            $this->open = false;
            return;
        }

        if ($this->delaysMs !== [] && $attempts > count($this->delaysMs)) {
            $this->open = true;
        }
    }
}
