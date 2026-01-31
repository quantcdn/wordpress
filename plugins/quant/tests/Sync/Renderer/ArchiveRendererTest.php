<?php

declare(strict_types=1);

namespace Quant\Tests\Sync\Renderer;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Quant\Sync\Renderer\ArchiveRenderer;
use Quant\Sync\Renderer\RenderedContent;

#[CoversClass(ArchiveRenderer::class)]
final class ArchiveRendererTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ArchiveRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->renderer = new ArchiveRenderer();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // Date Archive Tests

    #[Test]
    public function it_renders_yearly_archive_successfully(): void
    {
        $htmlContent = '<html><body>Yearly Archive 2024</body></html>';

        Functions\when('get_year_link')
            ->justReturn('https://example.com/2024/');

        Functions\when('wp_remote_get')
            ->justReturn([
                'response' => ['code' => 200],
                'body' => $htmlContent,
            ]);

        Functions\when('is_wp_error')
            ->justReturn(false);

        Functions\when('wp_remote_retrieve_response_code')
            ->justReturn(200);

        Functions\when('wp_remote_retrieve_body')
            ->justReturn($htmlContent);

        Functions\when('wp_parse_url')
            ->alias('parse_url');

        Functions\when('apply_filters')
            ->returnArg(2);

        $result = $this->renderer->renderDateArchive(2024);

        $this->assertInstanceOf(RenderedContent::class, $result);
        $this->assertSame('/2024/', $result->url);
        $this->assertSame($htmlContent, $result->markup);
        $this->assertSame('date', $result->meta['archive_type']);
        $this->assertSame(2024, $result->meta['year']);
        $this->assertNull($result->meta['month']);
        $this->assertNull($result->meta['day']);
    }

    #[Test]
    public function it_renders_monthly_archive_successfully(): void
    {
        $htmlContent = '<html><body>Monthly Archive January 2024</body></html>';

        Functions\when('get_month_link')
            ->justReturn('https://example.com/2024/01/');

        Functions\when('wp_remote_get')
            ->justReturn([
                'response' => ['code' => 200],
                'body' => $htmlContent,
            ]);

        Functions\when('is_wp_error')
            ->justReturn(false);

        Functions\when('wp_remote_retrieve_response_code')
            ->justReturn(200);

        Functions\when('wp_remote_retrieve_body')
            ->justReturn($htmlContent);

        Functions\when('wp_parse_url')
            ->alias('parse_url');

        Functions\when('apply_filters')
            ->returnArg(2);

        $result = $this->renderer->renderDateArchive(2024, 1);

        $this->assertInstanceOf(RenderedContent::class, $result);
        $this->assertSame('/2024/01/', $result->url);
        $this->assertSame($htmlContent, $result->markup);
        $this->assertSame('date', $result->meta['archive_type']);
        $this->assertSame(2024, $result->meta['year']);
        $this->assertSame(1, $result->meta['month']);
        $this->assertNull($result->meta['day']);
    }

    #[Test]
    public function it_renders_daily_archive_successfully(): void
    {
        $htmlContent = '<html><body>Daily Archive January 15, 2024</body></html>';

        Functions\when('get_day_link')
            ->justReturn('https://example.com/2024/01/15/');

        Functions\when('wp_remote_get')
            ->justReturn([
                'response' => ['code' => 200],
                'body' => $htmlContent,
            ]);

        Functions\when('is_wp_error')
            ->justReturn(false);

        Functions\when('wp_remote_retrieve_response_code')
            ->justReturn(200);

        Functions\when('wp_remote_retrieve_body')
            ->justReturn($htmlContent);

        Functions\when('wp_parse_url')
            ->alias('parse_url');

        Functions\when('apply_filters')
            ->returnArg(2);

        $result = $this->renderer->renderDateArchive(2024, 1, 15);

        $this->assertInstanceOf(RenderedContent::class, $result);
        $this->assertSame('/2024/01/15/', $result->url);
        $this->assertSame($htmlContent, $result->markup);
        $this->assertSame('date', $result->meta['archive_type']);
        $this->assertSame(2024, $result->meta['year']);
        $this->assertSame(1, $result->meta['month']);
        $this->assertSame(15, $result->meta['day']);
    }

    #[Test]
    public function it_returns_null_on_date_archive_http_error(): void
    {
        $wpError = Mockery::mock('WP_Error');

        Functions\when('get_year_link')
            ->justReturn('https://example.com/2024/');

        Functions\when('wp_remote_get')
            ->justReturn($wpError);

        Functions\when('is_wp_error')
            ->justReturn(true);

        Functions\when('apply_filters')
            ->returnArg(2);

        $result = $this->renderer->renderDateArchive(2024);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_on_date_archive_non_200_status(): void
    {
        Functions\when('get_year_link')
            ->justReturn('https://example.com/2024/');

        Functions\when('wp_remote_get')
            ->justReturn([
                'response' => ['code' => 404],
                'body' => '',
            ]);

        Functions\when('is_wp_error')
            ->justReturn(false);

        Functions\when('wp_remote_retrieve_response_code')
            ->justReturn(404);

        Functions\when('apply_filters')
            ->returnArg(2);

        $result = $this->renderer->renderDateArchive(2024);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_on_date_archive_empty_body(): void
    {
        Functions\when('get_year_link')
            ->justReturn('https://example.com/2024/');

        Functions\when('wp_remote_get')
            ->justReturn([
                'response' => ['code' => 200],
                'body' => '',
            ]);

        Functions\when('is_wp_error')
            ->justReturn(false);

        Functions\when('wp_remote_retrieve_response_code')
            ->justReturn(200);

        Functions\when('wp_remote_retrieve_body')
            ->justReturn('');

        Functions\when('apply_filters')
            ->returnArg(2);

        $result = $this->renderer->renderDateArchive(2024);

        $this->assertNull($result);
    }

    // Post Type Archive Tests

    #[Test]
    public function it_renders_post_type_archive_successfully(): void
    {
        $htmlContent = '<html><body>Products Archive</body></html>';

        $postTypeObject = Mockery::mock('stdClass');
        $postTypeObject->has_archive = true;
        $postTypeObject->public = true;
        $postTypeObject->label = 'Products';

        Functions\when('get_post_type_object')
            ->justReturn($postTypeObject);

        Functions\when('get_post_type_archive_link')
            ->justReturn('https://example.com/products/');

        Functions\when('wp_remote_get')
            ->justReturn([
                'response' => ['code' => 200],
                'body' => $htmlContent,
            ]);

        Functions\when('is_wp_error')
            ->justReturn(false);

        Functions\when('wp_remote_retrieve_response_code')
            ->justReturn(200);

        Functions\when('wp_remote_retrieve_body')
            ->justReturn($htmlContent);

        Functions\when('wp_parse_url')
            ->alias('parse_url');

        Functions\when('apply_filters')
            ->returnArg(2);

        $result = $this->renderer->renderPostTypeArchive('product');

        $this->assertInstanceOf(RenderedContent::class, $result);
        $this->assertSame('/products/', $result->url);
        $this->assertSame($htmlContent, $result->markup);
        $this->assertSame('post_type', $result->meta['archive_type']);
        $this->assertSame('product', $result->meta['post_type']);
        $this->assertSame('Products', $result->meta['label']);
    }

    #[Test]
    public function it_returns_null_for_post_type_without_archive(): void
    {
        $postTypeObject = Mockery::mock('stdClass');
        $postTypeObject->has_archive = false;
        $postTypeObject->public = true;

        Functions\when('get_post_type_object')
            ->justReturn($postTypeObject);

        $result = $this->renderer->renderPostTypeArchive('page');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_for_non_public_post_type(): void
    {
        $postTypeObject = Mockery::mock('stdClass');
        $postTypeObject->has_archive = true;
        $postTypeObject->public = false;

        Functions\when('get_post_type_object')
            ->justReturn($postTypeObject);

        $result = $this->renderer->renderPostTypeArchive('revision');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_for_unknown_post_type(): void
    {
        Functions\when('get_post_type_object')
            ->justReturn(null);

        $result = $this->renderer->renderPostTypeArchive('unknown_type');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_post_type_archive_link_is_false(): void
    {
        $postTypeObject = Mockery::mock('stdClass');
        $postTypeObject->has_archive = true;
        $postTypeObject->public = true;

        Functions\when('get_post_type_object')
            ->justReturn($postTypeObject);

        Functions\when('get_post_type_archive_link')
            ->justReturn(false);

        $result = $this->renderer->renderPostTypeArchive('product');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_on_post_type_archive_http_error(): void
    {
        $postTypeObject = Mockery::mock('stdClass');
        $postTypeObject->has_archive = true;
        $postTypeObject->public = true;

        $wpError = Mockery::mock('WP_Error');

        Functions\when('get_post_type_object')
            ->justReturn($postTypeObject);

        Functions\when('get_post_type_archive_link')
            ->justReturn('https://example.com/products/');

        Functions\when('wp_remote_get')
            ->justReturn($wpError);

        Functions\when('is_wp_error')
            ->justReturn(true);

        Functions\when('apply_filters')
            ->returnArg(2);

        $result = $this->renderer->renderPostTypeArchive('product');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_on_post_type_archive_non_200_status(): void
    {
        $postTypeObject = Mockery::mock('stdClass');
        $postTypeObject->has_archive = true;
        $postTypeObject->public = true;

        Functions\when('get_post_type_object')
            ->justReturn($postTypeObject);

        Functions\when('get_post_type_archive_link')
            ->justReturn('https://example.com/products/');

        Functions\when('wp_remote_get')
            ->justReturn([
                'response' => ['code' => 500],
                'body' => 'Error',
            ]);

        Functions\when('is_wp_error')
            ->justReturn(false);

        Functions\when('wp_remote_retrieve_response_code')
            ->justReturn(500);

        Functions\when('apply_filters')
            ->returnArg(2);

        $result = $this->renderer->renderPostTypeArchive('product');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_on_post_type_archive_empty_body(): void
    {
        $postTypeObject = Mockery::mock('stdClass');
        $postTypeObject->has_archive = true;
        $postTypeObject->public = true;

        Functions\when('get_post_type_object')
            ->justReturn($postTypeObject);

        Functions\when('get_post_type_archive_link')
            ->justReturn('https://example.com/products/');

        Functions\when('wp_remote_get')
            ->justReturn([
                'response' => ['code' => 200],
                'body' => '',
            ]);

        Functions\when('is_wp_error')
            ->justReturn(false);

        Functions\when('wp_remote_retrieve_response_code')
            ->justReturn(200);

        Functions\when('wp_remote_retrieve_body')
            ->justReturn('');

        Functions\when('apply_filters')
            ->returnArg(2);

        $result = $this->renderer->renderPostTypeArchive('product');

        $this->assertNull($result);
    }

    #[Test]
    public function it_handles_post_type_with_string_has_archive(): void
    {
        // When has_archive is a string, it's the archive slug (e.g., 'products' for CPT 'product')
        $postTypeObject = Mockery::mock('stdClass');
        $postTypeObject->has_archive = 'shop';
        $postTypeObject->public = true;
        $postTypeObject->label = 'Products';

        $htmlContent = '<html><body>Shop Archive</body></html>';

        Functions\when('get_post_type_object')
            ->justReturn($postTypeObject);

        Functions\when('get_post_type_archive_link')
            ->justReturn('https://example.com/shop/');

        Functions\when('wp_remote_get')
            ->justReturn([
                'response' => ['code' => 200],
                'body' => $htmlContent,
            ]);

        Functions\when('is_wp_error')
            ->justReturn(false);

        Functions\when('wp_remote_retrieve_response_code')
            ->justReturn(200);

        Functions\when('wp_remote_retrieve_body')
            ->justReturn($htmlContent);

        Functions\when('wp_parse_url')
            ->alias('parse_url');

        Functions\when('apply_filters')
            ->returnArg(2);

        $result = $this->renderer->renderPostTypeArchive('product');

        $this->assertInstanceOf(RenderedContent::class, $result);
        $this->assertSame('/shop/', $result->url);
    }
}
