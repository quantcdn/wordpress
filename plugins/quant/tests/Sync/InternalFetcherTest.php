<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Sync\InternalFetcher;
use Quant\Tests\Support\WordPressStubs;
use RuntimeException;

#[CoversClass(InternalFetcher::class)]
final class InternalFetcherTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    /** @var array<string, mixed> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions('http://localhost:8088');

        Functions\when('get_option')->alias(static fn(string $k, $d = false) => $k === 'quant_internal_token' ? 'secret-token' : $d);
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn(array $r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(static fn(array $r) => $r['body']);
        Functions\when('wp_remote_retrieve_header')->alias(static fn(array $r, string $h) => $r['headers'][$h] ?? '');
        Functions\when('wp_remote_get')->alias(function (string $url, array $args) {
            $this->captured = ['url' => $url, 'args' => $args];
            return ['response' => ['code' => 200], 'body' => '<html/>', 'headers' => ['content-type' => 'text/html', 'location' => '']];
        });
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_fetches_the_site_url_by_default_with_the_internal_token_and_no_redirects(): void
    {
        $fetcher = new InternalFetcher($this->mockSettings());

        $result = $fetcher->fetch('/about/');

        $this->assertSame('http://localhost:8088/about/', $this->captured['url']);
        $this->assertSame('secret-token', $this->captured['args']['headers']['Quant-Token']);
        $this->assertSame(0, $this->captured['args']['redirection']);
        $this->assertTrue($this->captured['args']['sslverify']);
        $this->assertArrayNotHasKey('Host', $this->captured['args']['headers']);
        $this->assertSame(200, $result->status);
        $this->assertTrue($result->isOk());
        $this->assertSame('http://localhost:8088/about/', $result->requestedUrl);
    }

    #[Test]
    public function it_uses_the_webserver_url_and_derives_the_host_header(): void
    {
        $fetcher = new InternalFetcher($this->mockSettings(static: ['webserver_url' => 'http://localhost/']));

        $fetcher->fetch('/about/?x=1');

        $this->assertSame('http://localhost/about/?x=1', $this->captured['url']);
        $this->assertSame('localhost:8088', $this->captured['args']['headers']['Host']);
    }

    #[Test]
    public function it_prefers_the_configured_host_header(): void
    {
        $fetcher = new InternalFetcher($this->mockSettings(static: ['webserver_url' => 'http://nginx:8080', 'webserver_host' => 'www.example.com']));

        $fetcher->fetch('https://www.example.com/page/');

        $this->assertSame('http://nginx:8080/page/', $this->captured['url']);
        $this->assertSame('www.example.com', $this->captured['args']['headers']['Host']);
        $this->assertSame('www.example.com', $fetcher->hostHeader());
    }

    #[Test]
    public function it_disables_tls_verification_when_configured(): void
    {
        $fetcher = new InternalFetcher($this->mockSettings(credentials: ['disable_tls_verify' => true]));

        $fetcher->fetch('/');

        $this->assertFalse($this->captured['args']['sslverify']);
    }

    #[Test]
    public function it_passes_basic_auth_through(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'editor';
        $_SERVER['PHP_AUTH_PW'] = 'pw';
        $fetcher = new InternalFetcher($this->mockSettings());

        $fetcher->fetch('/');

        $this->assertSame('Basic ' . base64_encode('editor:pw'), $this->captured['args']['headers']['Authorization']);
    }

    #[Test]
    public function it_reports_redirects(): void
    {
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 301], 'body' => '', 'headers' => ['location' => 'http://localhost:8088/new/']]);
        $fetcher = new InternalFetcher($this->mockSettings());

        $result = $fetcher->fetch('/old/');

        $this->assertTrue($result->isRedirect());
        $this->assertSame('http://localhost:8088/new/', $result->redirectLocation);
    }

    #[Test]
    public function it_throws_when_the_request_fails(): void
    {
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('http_request_failed', 'Connection refused'));
        $fetcher = new InternalFetcher($this->mockSettings());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to connect to local webserver');

        $fetcher->fetch('/');
    }
}
