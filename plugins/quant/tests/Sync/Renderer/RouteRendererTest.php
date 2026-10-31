<?php

declare(strict_types=1);

namespace Quant\Tests\Sync\Renderer;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Sync\Exception\RedirectResponseException;
use Quant\Sync\FetchResult;
use Quant\Sync\InternalFetcher;
use Quant\Sync\MarkupProcessor;
use Quant\Sync\Renderer\RouteRenderer;
use Quant\Tests\Support\WordPressStubs;
use RuntimeException;

#[CoversClass(RouteRenderer::class)]
#[CoversClass(FetchResult::class)]
final class RouteRendererTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private InternalFetcher&Mockery\MockInterface $fetcher;
    private RouteRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $this->fetcher = Mockery::mock(InternalFetcher::class);
        $markup = Mockery::mock(MarkupProcessor::class);
        $markup->shouldReceive('process')->andReturnUsing(static fn(string $m): string => '[processed]' . $m)->byDefault();

        $this->renderer = new RouteRenderer($this->fetcher, $markup, $this->mockSettings());
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_renders_a_route_and_normalises_the_path(): void
    {
        $this->fetcher->shouldReceive('fetch')->once()->with('/blog/page/2/')
            ->andReturn(new FetchResult(200, '<html>page 2</html>', 'text/html; charset=UTF-8', null, 'http://localhost/blog/page/2/'));

        $content = $this->renderer->render('/blog/page/2/');

        $this->assertSame('/blog/page/2', $content->url);
        $this->assertSame('[processed]<html>page 2</html>', $content->markup);
        $this->assertSame(['source' => 'wordpress'], $content->info);
        $this->assertTrue($content->options['published']);
        $this->assertTrue($content->options['find_attachments']);
        $this->assertArrayNotHasKey('headers', $content->options, 'HTML needs no content type override');
        $this->assertArrayNotHasKey('proxy_override', $content->options);
    }

    #[Test]
    public function it_publishes_under_an_alternative_path(): void
    {
        $this->fetcher->shouldReceive('fetch')->once()->andReturn(new FetchResult(404, '<html>not found</html>', 'text/html'));

        $content = $this->renderer->render('/404', '/_quant404', true);

        $this->assertSame('/_quant404', $content->url);
    }

    #[Test]
    public function it_passes_non_html_content_types_through(): void
    {
        $this->fetcher->shouldReceive('fetch')->once()->andReturn(new FetchResult(200, '<?xml version="1.0"?><urlset/>', 'application/xml; charset=UTF-8'));

        $content = $this->renderer->render('/wp-sitemap.xml');

        $this->assertSame(['content-type' => 'application/xml'], $content->options['headers']);
    }

    #[Test]
    public function it_adds_proxy_override_when_configured(): void
    {
        $settings = $this->mockSettings(static: ['proxy_override' => true]);
        $markup = Mockery::mock(MarkupProcessor::class);
        $markup->shouldReceive('process')->andReturnArg(0);
        $renderer = new RouteRenderer($this->fetcher, $markup, $settings);
        $this->fetcher->shouldReceive('fetch')->once()->andReturn(new FetchResult(200, 'x', 'text/html'));

        $this->assertTrue($renderer->render('/')->options['proxy_override']);
    }

    #[Test]
    public function it_throws_a_redirect_exception_on_redirects(): void
    {
        $this->fetcher->shouldReceive('fetch')->once()->andReturn(new FetchResult(301, '', '', 'https://example.com/new-place/'));

        try {
            $this->renderer->render('/old-place/');
            $this->fail('Expected a redirect exception');
        } catch (RedirectResponseException $e) {
            $this->assertSame('/old-place', $e->from);
            $this->assertSame('/new-place', $e->to);
            $this->assertSame(301, $e->statusCode);
        }
    }

    #[Test]
    public function it_fails_on_non_200_responses(): void
    {
        $this->fetcher->shouldReceive('fetch')->once()->andReturn(new FetchResult(500, 'error', 'text/html', null, 'http://localhost/x'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500 response for /x (http://localhost/x)');

        $this->renderer->render('/x');
    }

    #[Test]
    public function it_reports_a_404_or_410_as_content_to_unpublish(): void
    {
        foreach ([404, 410] as $status) {
            $fetcher = Mockery::mock(\Quant\Sync\InternalFetcher::class);
            $fetcher->shouldReceive('fetch')->once()->andReturn(new FetchResult($status, 'gone', 'text/html'));
            $markup = Mockery::mock(MarkupProcessor::class);
            $renderer = new RouteRenderer($fetcher, $markup, $this->mockSettings());

            try {
                $renderer->render('/missing/');
                $this->fail('Expected UnpublishedContentException');
            } catch (\Quant\Sync\Exception\UnpublishedContentException $e) {
                $this->assertSame('/missing', $e->path);
                $this->assertStringContainsString((string) $status, $e->getMessage());
            }
        }
    }

    #[Test]
    public function it_accepts_a_404_body_when_allowed(): void
    {
        $this->fetcher->shouldReceive('fetch')->once()->andReturn(new FetchResult(404, '<p>Not found</p>', 'text/html'));

        $content = $this->renderer->render('/404', '/_quant404', true);

        $this->assertSame('/_quant404', $content->url);
    }

    #[Test]
    public function it_fails_on_an_empty_body(): void
    {
        $this->fetcher->shouldReceive('fetch')->once()->andReturn(new FetchResult(200, '', 'text/html'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Empty response body');

        $this->renderer->render('/empty');
    }
}
