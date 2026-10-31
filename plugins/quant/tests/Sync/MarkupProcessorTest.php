<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Sync\MarkupProcessor;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(MarkupProcessor::class)]
final class MarkupProcessorTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions('http://localhost:8088');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_strips_the_home_host_with_and_without_port(): void
    {
        $processor = new MarkupProcessor($this->mockSettings());
        $html = '<a href="http://localhost:8088/about/">x</a><img src="//localhost:8088/i.png"><link href="http://localhost/style.css">';

        $this->assertSame('<a href="/about/">x</a><img src="/i.png"><link href="/style.css">', $processor->process($html));
    }

    #[Test]
    public function it_strips_json_escaped_urls(): void
    {
        $processor = new MarkupProcessor($this->mockSettings());

        $this->assertSame('{"url":"\/wp-json\/"}', $processor->process('{"url":"http:\/\/localhost:8088\/wp-json\/"}'));
    }

    #[Test]
    public function it_strips_configured_domain_rewrites_and_the_webserver_host(): void
    {
        $processor = new MarkupProcessor($this->mockSettings(static: [
            'domain_rewrites' => ['https://staging.example.com/', 'cdn.example.com'],
            'webserver_host' => 'www.example.com',
        ]));
        $html = '<a href="https://staging.example.com/a">a</a><script src="https://cdn.example.com/b.js"></script><a href="https://www.example.com/c">c</a>';

        $this->assertSame('<a href="/a">a</a><script src="/b.js"></script><a href="/c">c</a>', $processor->process($html));
    }

    #[Test]
    public function it_upgrades_remaining_http_links_to_https(): void
    {
        $processor = new MarkupProcessor($this->mockSettings());

        $this->assertSame('<a href="https://other.com/x">x</a>', $processor->process('<a href="http://other.com/x">x</a>'));
    }

    #[Test]
    public function it_does_not_strip_other_hosts_that_merely_start_with_the_home_host(): void
    {
        $processor = new MarkupProcessor($this->mockSettings());

        $this->assertSame('<a href="https://localhost.example.org/x">x</a>', $processor->process('<a href="https://localhost.example.org/x">x</a>'));
    }

    #[Test]
    public function it_leaves_markup_alone_when_strip_host_is_off(): void
    {
        $processor = new MarkupProcessor($this->mockSettings(static: ['strip_host' => false]));
        $html = '<a href="http://localhost:8088/about/">x</a>';

        $this->assertSame($html, $processor->process($html));
    }

    #[Test]
    public function it_lists_hosts_longest_first(): void
    {
        $processor = new MarkupProcessor($this->mockSettings());

        $hosts = $processor->hostsToStrip(['domain_rewrites' => ['a.example.com'], 'webserver_host' => '']);

        $this->assertSame('localhost:8088', $hosts[0]);
        $this->assertContains('localhost', $hosts);
        $this->assertContains('a.example.com', $hosts);
    }
}
