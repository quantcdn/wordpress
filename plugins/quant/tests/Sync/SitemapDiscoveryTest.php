<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Sync\FetchResult;
use Quant\Sync\InternalFetcher;
use Quant\Sync\SitemapDiscovery;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(SitemapDiscovery::class)]
final class SitemapDiscoveryTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private InternalFetcher&Mockery\MockInterface $fetcher;
    private SitemapDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $this->fetcher = Mockery::mock(InternalFetcher::class);
        $this->discovery = new SitemapDiscovery($this->fetcher);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function fakeCoreServer(bool $enabled = true): object
    {
        $provider = new class {
            public function get_sitemap_entries(): array
            {
                return [['loc' => 'https://example.com/wp-sitemap-posts-post-1.xml'], ['loc' => 'https://example.com/wp-sitemap-posts-page-1.xml'], []];
            }
        };

        return new class ($enabled, $provider) {
            public object $index;
            public object $renderer;
            public object $registry;
            public function __construct(private bool $enabled, object $provider)
            {
                $this->index = new class { public function get_index_url(): string { return 'https://example.com/wp-sitemap.xml'; } };
                $this->renderer = new class {
                    public function get_sitemap_index_stylesheet_url(): string { return 'https://example.com/wp-sitemap-index.xsl'; }
                    public function get_sitemap_stylesheet_url(): string { return 'https://example.com/wp-sitemap.xsl'; }
                };
                $this->registry = new class ($provider) {
                    public function __construct(private object $provider) {}
                    public function get_providers(): array { return ['posts' => $this->provider]; }
                };
            }
            public function sitemaps_enabled(): bool { return $this->enabled; }
        };
    }

    #[Test]
    public function it_lists_core_sitemap_routes_with_stylesheets(): void
    {
        Functions\when('wp_sitemaps_get_server')->justReturn($this->fakeCoreServer());

        $this->assertSame([
            '/wp-sitemap.xml',
            '/wp-sitemap-index.xsl',
            '/wp-sitemap.xsl',
            '/wp-sitemap-posts-post-1.xml',
            '/wp-sitemap-posts-page-1.xml',
        ], $this->discovery->routes());
    }

    #[Test]
    public function it_returns_nothing_when_core_sitemaps_are_disabled(): void
    {
        Functions\when('wp_sitemaps_get_server')->justReturn($this->fakeCoreServer(false));

        $this->assertSame([], $this->discovery->routes());
    }

    #[Test]
    public function it_reads_plugin_sitemap_indexes_and_their_stylesheet(): void
    {
        Functions\when('wp_sitemaps_get_server')->justReturn($this->fakeCoreServer(false));
        Filters\expectApplied('quant_sitemap_indexes')->once()->andReturn(['/sitemap_index.xml']);

        $xml = '<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="https://example.com/main-sitemap.xsl"?>'
            . '<sitemapindex><sitemap><loc>https://example.com/post-sitemap.xml</loc></sitemap>'
            . '<sitemap><loc> https://example.com/page-sitemap.xml </loc></sitemap>'
            . '<sitemap><loc>https://other.com/external.xml</loc></sitemap>'
            . '<sitemap><loc>https://example.com/not-a-sitemap.html</loc></sitemap></sitemapindex>';
        $this->fetcher->shouldReceive('fetch')->once()->with('/sitemap_index.xml', 30)->andReturn(new FetchResult(200, $xml, 'application/xml'));

        $this->assertSame([
            '/sitemap_index.xml',
            '/main-sitemap.xsl',
            '/post-sitemap.xml',
            '/page-sitemap.xml',
        ], $this->discovery->routes());
    }

    #[Test]
    public function it_ignores_an_index_that_cannot_be_fetched(): void
    {
        Functions\when('wp_sitemaps_get_server')->justReturn($this->fakeCoreServer(false));
        Filters\expectApplied('quant_sitemap_indexes')->once()->andReturn(['/sitemap_index.xml', '/broken.xml']);
        $this->fetcher->shouldReceive('fetch')->once()->with('/sitemap_index.xml', 30)->andReturn(new FetchResult(404, '', ''));
        $this->fetcher->shouldReceive('fetch')->once()->with('/broken.xml', 30)->andThrow(new \RuntimeException('down'));

        $this->assertSame([], $this->discovery->routes());
    }
}
