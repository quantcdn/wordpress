<?php

declare(strict_types=1);

namespace Quant\Tests\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\RetryPolicy;

#[CoversClass(RetryPolicy::class)]
final class RetryPolicyTest extends TestCase
{
    /** @var array<int> */
    private array $slept = [];

    private float $now = 1000.0;

    private function policy(int $jitterMs = 0, float $maxElapsed = 45.0): RetryPolicy
    {
        return new RetryPolicy(
            delaysMs: [1000, 3000],
            maxJitterMs: $jitterMs,
            maxElapsedSeconds: $maxElapsed,
            sleep: function (int $ms): void {
                $this->slept[] = $ms;
                $this->now += $ms / 1000;
            },
            clock: fn(): float => $this->now,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $responses
     * @return \Closure(): array<string, mixed>
     */
    private function answers(array $responses, int &$calls): \Closure
    {
        return static function () use (&$responses, &$calls): array {
            $calls++;
            return array_shift($responses) ?? ['code' => 200, 'body' => '{}'];
        };
    }

    private const TIMEOUT = ['code' => 0, 'body' => '', 'error' => 'Quant did not answer in time.', 'detail' => 'raw', 'transport' => true];

    #[Test]
    public function it_returns_the_first_success_without_a_retry(): void
    {
        $calls = 0;
        $response = $this->policy()->run($this->answers([['code' => 200, 'body' => '{}']], $calls));

        $this->assertSame(200, $response['code']);
        $this->assertSame(1, $calls);
        $this->assertSame([], $this->slept);
        $this->assertArrayNotHasKey('attempts', $response);
    }

    #[Test]
    public function it_retries_a_transport_failure_twice_with_growing_delays(): void
    {
        $calls = 0;
        $response = $this->policy()->run($this->answers([self::TIMEOUT, self::TIMEOUT, self::TIMEOUT], $calls));

        $this->assertSame(3, $calls);
        $this->assertSame([1000, 3000], $this->slept);
        $this->assertSame(0, $response['code']);
        $this->assertSame(3, $response['attempts']);
    }

    #[Test]
    public function it_stops_at_the_first_success(): void
    {
        $calls = 0;
        $response = $this->policy()->run($this->answers([['code' => 503, 'body' => ''], ['code' => 201, 'body' => '{}']], $calls));

        $this->assertSame(201, $response['code']);
        $this->assertSame(2, $calls);
        $this->assertSame([1000], $this->slept);
    }

    #[Test]
    public function it_retries_only_502_503_and_504(): void
    {
        foreach ([502, 503, 504] as $code) {
            $this->assertTrue(RetryPolicy::isRetryable(['code' => $code, 'body' => '']), (string) $code);
        }

        foreach ([200, 400, 401, 403, 404, 409, 429, 500, 501] as $code) {
            $this->assertFalse(RetryPolicy::isRetryable(['code' => $code, 'body' => '']), (string) $code);
        }
    }

    #[Test]
    public function it_does_not_retry_a_local_refusal_with_code_zero(): void
    {
        $calls = 0;
        $response = $this->policy()->run($this->answers([['code' => 0, 'body' => '', 'error' => 'Could not read file: /x']], $calls));

        $this->assertSame(1, $calls);
        $this->assertSame([], $this->slept);
        $this->assertSame('Could not read file: /x', $response['error']);
    }

    #[Test]
    public function it_adds_jitter_within_the_limit(): void
    {
        $calls = 0;
        $this->policy(jitterMs: 400)->run($this->answers([self::TIMEOUT, self::TIMEOUT, self::TIMEOUT], $calls));

        $this->assertCount(2, $this->slept);
        $this->assertGreaterThanOrEqual(1000, $this->slept[0]);
        $this->assertLessThanOrEqual(1400, $this->slept[0]);
        $this->assertGreaterThanOrEqual(3000, $this->slept[1]);
        $this->assertLessThanOrEqual(3400, $this->slept[1]);
    }

    #[Test]
    public function it_does_not_start_a_retry_after_the_elapsed_limit(): void
    {
        $calls = 0;
        $slow = function () use (&$calls): array {
            $calls++;
            $this->now += 30.0; // Each attempt waits for the full request timeout.
            return self::TIMEOUT;
        };

        $response = $this->policy(maxElapsed: 45.0)->run($slow);

        // 30 s + 1 s delay fits in 45 s; a second retry at 61 s + 3 s does not.
        $this->assertSame(2, $calls);
        $this->assertSame([1000], $this->slept);
        $this->assertSame(2, $response['attempts']);
    }

    #[Test]
    public function none_never_retries(): void
    {
        $calls = 0;
        RetryPolicy::none()->run($this->answers([self::TIMEOUT, self::TIMEOUT], $calls));

        $this->assertSame(1, $calls);
    }
}
