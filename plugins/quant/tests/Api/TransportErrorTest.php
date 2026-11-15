<?php

declare(strict_types=1);

namespace Quant\Tests\Api;

use Brain\Monkey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\TransportError;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(TransportError::class)]
final class TransportErrorTest extends TestCase
{
    use WordPressStubs;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function transportFailures(): array
    {
        return [
            'curl timeout' => ['http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received', TransportError::TIMEOUT],
            'fsockopen timeout' => ['http_request_failed', 'fsockopen: connection timed out', TransportError::TIMEOUT],
            'other http_request_failed' => ['http_request_failed', 'A valid URL was not provided.', TransportError::TIMEOUT],
            'dns' => ['http_request_failed', 'cURL error 6: Could not resolve host: api.quantcdn.io', TransportError::DNS],
            'tls certificate' => ['http_request_failed', 'cURL error 60: SSL certificate problem: unable to get local issuer certificate', TransportError::TLS],
            'tls handshake' => ['http_request_failed', 'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL in connection to api.quantcdn.io:443', TransportError::TLS],
            'connection refused' => ['http_request_failed', 'cURL error 7: Failed to connect to api.quantcdn.io port 443: Connection refused', TransportError::CONNECTION],
            'connection reset' => ['http_request_failed', 'cURL error 56: Recv failure: Connection reset by peer', TransportError::CONNECTION],
        ];
    }

    #[Test]
    #[DataProvider('transportFailures')]
    public function it_maps_a_transport_failure_to_a_short_message_and_keeps_the_raw_text(string $code, string $raw, string $expected): void
    {
        $error = TransportError::fromWpError(new \WP_Error($code, $raw));

        $this->assertSame($expected, $error['message']);
        $this->assertSame("{$code}: {$raw}", $error['detail']);
        $this->assertStringNotContainsString('cURL', $error['message']);
    }

    #[Test]
    public function the_reasons_do_not_promise_a_next_step(): void
    {
        // The caller adds the next step, because only the caller knows if it is true.
        $this->assertSame('Quant did not answer in time.', TransportError::TIMEOUT);
        foreach ([TransportError::TIMEOUT, TransportError::DNS, TransportError::TLS, TransportError::CONNECTION, TransportError::UNAVAILABLE, TransportError::SERVER] as $reason) {
            $this->assertStringNotContainsString('try again', $reason);
        }
        $this->assertSame('The plugin will try again.', TransportError::NEXT_AUTOMATIC);
        $this->assertSame('Select Retry failed on the dashboard.', TransportError::NEXT_MANUAL);
        $this->assertSame('Test the connection again in a minute.', TransportError::NEXT_TEST_AGAIN);
    }

    #[Test]
    public function it_maps_gateway_and_server_errors(): void
    {
        $this->assertSame(sprintf(TransportError::UNAVAILABLE, 503), TransportError::forStatus(503));
        $this->assertSame(sprintf(TransportError::UNAVAILABLE, 502), TransportError::forStatus(502));
        $this->assertSame(sprintf(TransportError::UNAVAILABLE, 504), TransportError::forStatus(504));
        $this->assertSame(sprintf(TransportError::SERVER, 500), TransportError::forStatus(500));
        $this->assertStringContainsString('(HTTP 500)', (string) TransportError::forStatus(500));
    }

    #[Test]
    public function it_leaves_other_statuses_to_the_api_message(): void
    {
        $this->assertNull(TransportError::forStatus(200));
        $this->assertNull(TransportError::forStatus(400));
        $this->assertNull(TransportError::forStatus(404));
        $this->assertNull(TransportError::forStatus(429));
    }
}
