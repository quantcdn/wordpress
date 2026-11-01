<?php

declare(strict_types=1);

namespace Quant\Tests\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\WordPressHttpClient;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(WordPressHttpClient::class)]
final class WordPressHttpClientTest extends TestCase
{
    use WordPressStubs;

    /** @var array<string, mixed> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        Functions\when('wp_remote_request')->alias(function (string $url, array $args): array {
            $this->captured = ['url' => $url, 'args' => $args];
            return ['response' => ['code' => 201], 'body' => '{"ok":true}', 'headers' => ['x-test' => '1']];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn(array $r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn(array $r) => $r['body']);
        Functions\when('wp_remote_retrieve_headers')->alias(static fn(array $r) => $r['headers']);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_sends_json_bodies_with_the_configured_timeout_and_tls_setting(): void
    {
        $client = WordPressHttpClient::withDefaults(45, false);

        $response = $client->post('https://api.test/v1', ['Quant-Token' => 't'], ['url' => '/a']);

        $this->assertSame(201, $response['code']);
        $this->assertSame('{"ok":true}', $response['body']);
        $this->assertSame(['x-test' => '1'], $response['headers']);
        $this->assertSame('POST', $this->captured['args']['method']);
        $this->assertSame(45, $this->captured['args']['timeout']);
        $this->assertFalse($this->captured['args']['sslverify']);
        $this->assertSame('application/json', $this->captured['args']['headers']['Content-Type']);
        $this->assertSame('t', $this->captured['args']['headers']['Quant-Token']);
        $this->assertSame('{"url":"\/a"}', $this->captured['args']['body']);
    }

    #[Test]
    public function the_quant_api_client_always_verifies_tls(): void
    {
        $client = WordPressHttpClient::forQuantApi($this->mockSettings(['disable_tls_verify' => true, 'http_request_timeout' => 20]));

        $client->get('https://api.quantgov.cloud/v1/ping');

        $this->assertTrue($this->captured['args']['sslverify']);
        $this->assertSame(20, $this->captured['args']['timeout']);
    }

    #[Test]
    public function it_resolves_options_per_request(): void
    {
        $timeout = 10;
        $client = new WordPressHttpClient(static function () use (&$timeout): array {
            return ['timeout' => $timeout, 'sslverify' => true];
        });

        $client->get('https://api.test/v1/ping');
        $this->assertSame(10, $this->captured['args']['timeout']);

        $timeout = 99;
        $client->delete('https://api.test/v1/search/all');
        $this->assertSame(99, $this->captured['args']['timeout']);
        $this->assertSame('DELETE', $this->captured['args']['method']);
        $this->assertArrayNotHasKey('body', $this->captured['args']);
    }

    #[Test]
    public function it_builds_a_multipart_body(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'quant') . '.css';
        file_put_contents($file, 'body{color:red}');
        $client = WordPressHttpClient::withDefaults(30, true);

        try {
            $method = new \ReflectionMethod($client, 'uploadBuffered');
            $response = $method->invoke($client, 'https://api.test/v1', ['Quant-File-Url' => '/style.css'], $file, 'data');
        } finally {
            unlink($file);
        }

        $this->assertSame(201, $response['code']);
        $args = $this->captured['args'];
        $this->assertSame('POST', $args['method']);
        $this->assertSame(120, $args['timeout'], 'Uploads get at least two minutes');
        $this->assertStringStartsWith('multipart/form-data; boundary=quantabcdef1234567890', $args['headers']['Content-Type']);
        $this->assertSame('/style.css', $args['headers']['Quant-File-Url']);
        $this->assertStringContainsString("--quantabcdef1234567890\r\nContent-Disposition: form-data; name=\"data\"; filename=\"" . basename($file) . "\"\r\nContent-Type: text/css\r\n\r\nbody{color:red}\r\n--quantabcdef1234567890--\r\n", $args['body']);
    }

    #[Test]
    public function it_uploads_through_the_wordpress_http_api(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'quant') . '.css';
        file_put_contents($file, 'body{color:blue}');
        Functions\when('wp_convert_hr_to_bytes')->justReturn(-1);
        Functions\when('wp_raise_memory_limit')->justReturn(false);

        try {
            $response = WordPressHttpClient::withDefaults(30, true)->upload('https://api.test/v1', ['Quant-File-Url' => '/a.css'], $file);
        } finally {
            unlink($file);
        }

        $this->assertSame(201, $response['code']);
        $this->assertStringContainsString('body{color:blue}', $this->captured['args']['body']);
    }

    #[Test]
    public function it_refuses_a_file_over_the_upload_limit_without_a_request(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'quant');
        file_put_contents($file, str_repeat('x', 2048));
        Functions\when('apply_filters')->alias(static fn(string $hook, $value) => $hook === 'quant_max_upload_bytes' ? 1024 : $value);

        try {
            $response = WordPressHttpClient::withDefaults()->upload('https://api.test/v1', [], $file);
        } finally {
            unlink($file);
        }

        $this->assertSame(0, $response['code']);
        $this->assertStringContainsString('upload limit', $response['error']);
        $this->assertArrayNotHasKey('args', $this->captured);
    }

    #[Test]
    public function it_refuses_a_file_that_does_not_fit_in_php_memory(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'quant');
        file_put_contents($file, str_repeat('x', 4096));
        Functions\when('wp_raise_memory_limit')->justReturn(false);
        Functions\when('wp_convert_hr_to_bytes')->justReturn(memory_get_usage(true) + 1024);

        try {
            $response = WordPressHttpClient::withDefaults()->upload('https://api.test/v1', [], $file);
        } finally {
            unlink($file);
        }

        $this->assertSame(0, $response['code']);
        $this->assertStringContainsString('Not enough PHP memory', $response['error']);
        $this->assertArrayNotHasKey('args', $this->captured);
    }

    #[Test]
    public function it_reports_an_unreadable_upload_without_a_request(): void
    {
        $client = WordPressHttpClient::withDefaults();

        $response = $client->upload('https://api.test/v1', [], '/nope/missing.bin');

        $this->assertSame(0, $response['code']);
        $this->assertStringContainsString('Could not read file', $response['error']);
        $this->assertSame([], $this->captured);
    }

    #[Test]
    public function it_returns_transport_errors(): void
    {
        Functions\when('wp_remote_request')->justReturn(new \WP_Error('http_request_failed', 'timed out'));
        $client = WordPressHttpClient::withDefaults();

        $response = $client->patch('https://api.test/v1/unpublish', ['Quant-Url' => '/x']);

        $this->assertSame(['code' => 0, 'body' => '', 'error' => 'timed out'], $response);
    }
}
