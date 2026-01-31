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
use Quant\Sync\Renderer\TaxonomyRenderer;
use Quant\Sync\Renderer\RenderedContent;
use WP_Term;

#[CoversClass(TaxonomyRenderer::class)]
final class TaxonomyRendererTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private TaxonomyRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->renderer = new TaxonomyRenderer();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_supports_categories(): void
    {
        $term = $this->createMockTerm('category', 1, 'Uncategorized');

        $taxonomyObject = Mockery::mock('stdClass');
        $taxonomyObject->public = true;

        Functions\when('get_taxonomy')
            ->justReturn($taxonomyObject);

        $this->assertTrue($this->renderer->supports($term));
    }

    #[Test]
    public function it_supports_tags(): void
    {
        $term = $this->createMockTerm('post_tag', 2, 'WordPress');

        $taxonomyObject = Mockery::mock('stdClass');
        $taxonomyObject->public = true;

        Functions\when('get_taxonomy')
            ->justReturn($taxonomyObject);

        $this->assertTrue($this->renderer->supports($term));
    }

    #[Test]
    public function it_supports_public_custom_taxonomies(): void
    {
        $term = $this->createMockTerm('product_cat', 3, 'Electronics');

        $taxonomyObject = Mockery::mock('stdClass');
        $taxonomyObject->public = true;

        Functions\when('get_taxonomy')
            ->justReturn($taxonomyObject);

        $this->assertTrue($this->renderer->supports($term));
    }

    #[Test]
    public function it_does_not_support_nav_menu_taxonomy(): void
    {
        $term = $this->createMockTerm('nav_menu', 4, 'Main Menu');

        $this->assertFalse($this->renderer->supports($term));
    }

    #[Test]
    public function it_does_not_support_link_category_taxonomy(): void
    {
        $term = $this->createMockTerm('link_category', 5, 'Blogroll');

        $this->assertFalse($this->renderer->supports($term));
    }

    #[Test]
    public function it_does_not_support_post_format_taxonomy(): void
    {
        $term = $this->createMockTerm('post_format', 6, 'aside');

        $this->assertFalse($this->renderer->supports($term));
    }

    #[Test]
    public function it_does_not_support_wp_theme_taxonomy(): void
    {
        $term = $this->createMockTerm('wp_theme', 7, 'Twenty Twenty');

        $this->assertFalse($this->renderer->supports($term));
    }

    #[Test]
    public function it_does_not_support_wp_template_part_area_taxonomy(): void
    {
        $term = $this->createMockTerm('wp_template_part_area', 8, 'header');

        $this->assertFalse($this->renderer->supports($term));
    }

    #[Test]
    public function it_does_not_support_private_taxonomies(): void
    {
        $term = $this->createMockTerm('custom_private_tax', 9, 'Private');

        $taxonomyObject = Mockery::mock('stdClass');
        $taxonomyObject->public = false;

        Functions\when('get_taxonomy')
            ->justReturn($taxonomyObject);

        $this->assertFalse($this->renderer->supports($term));
    }

    #[Test]
    public function it_does_not_support_unknown_taxonomies(): void
    {
        $term = $this->createMockTerm('unknown_taxonomy', 10, 'Unknown');

        Functions\when('get_taxonomy')
            ->justReturn(null);

        $this->assertFalse($this->renderer->supports($term));
    }

    #[Test]
    public function it_renders_a_category_term_successfully(): void
    {
        $term = $this->createMockTerm('category', 1, 'Technology');

        $taxonomyObject = Mockery::mock('stdClass');
        $taxonomyObject->public = true;

        $htmlContent = '<html><body>Category Archive</body></html>';

        Functions\when('get_term')
            ->justReturn($term);

        Functions\when('get_taxonomy')
            ->justReturn($taxonomyObject);

        Functions\when('get_term_link')
            ->justReturn('https://example.com/category/technology/');

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

        $result = $this->renderer->render(1);

        $this->assertInstanceOf(RenderedContent::class, $result);
        $this->assertSame('/category/technology/', $result->url);
        $this->assertSame($htmlContent, $result->markup);
        $this->assertSame('category', $result->meta['taxonomy']);
        $this->assertSame('Technology', $result->meta['term_name']);
        $this->assertSame('technology', $result->meta['term_slug']);
    }

    #[Test]
    public function it_renders_a_tag_term_successfully(): void
    {
        $term = $this->createMockTerm('post_tag', 2, 'WordPress');

        $taxonomyObject = Mockery::mock('stdClass');
        $taxonomyObject->public = true;

        $htmlContent = '<html><body>Tag Archive</body></html>';

        Functions\when('get_term')
            ->justReturn($term);

        Functions\when('get_taxonomy')
            ->justReturn($taxonomyObject);

        Functions\when('get_term_link')
            ->justReturn('https://example.com/tag/wordpress/');

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

        $result = $this->renderer->render(2);

        $this->assertInstanceOf(RenderedContent::class, $result);
        $this->assertSame('/tag/wordpress/', $result->url);
        $this->assertSame($htmlContent, $result->markup);
        $this->assertSame('post_tag', $result->meta['taxonomy']);
        $this->assertSame('WordPress', $result->meta['term_name']);
    }

    #[Test]
    public function it_returns_null_when_term_not_found(): void
    {
        Functions\when('get_term')
            ->justReturn(null);

        $result = $this->renderer->render(999);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_get_term_returns_wp_error(): void
    {
        $wpError = Mockery::mock('WP_Error');

        Functions\when('get_term')
            ->justReturn($wpError);

        Functions\when('is_wp_error')
            ->justReturn(true);

        $result = $this->renderer->render(999);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_term_link_is_wp_error(): void
    {
        $term = $this->createMockTerm('category', 1, 'Tech');

        $taxonomyObject = Mockery::mock('stdClass');
        $taxonomyObject->public = true;

        $wpError = Mockery::mock('WP_Error');

        Functions\when('get_term')
            ->justReturn($term);

        Functions\when('get_taxonomy')
            ->justReturn($taxonomyObject);

        Functions\when('get_term_link')
            ->justReturn($wpError);

        Functions\when('is_wp_error')
            ->justReturn(true);

        $result = $this->renderer->render(1);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_for_unsupported_taxonomy(): void
    {
        $term = $this->createMockTerm('nav_menu', 1, 'Main Menu');

        Functions\when('get_term')
            ->justReturn($term);

        // is_wp_error should return false for the term
        Functions\when('is_wp_error')
            ->alias(function ($thing) use ($term) {
                return $thing !== $term && $thing instanceof \WP_Error;
            });

        $result = $this->renderer->render(1);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_on_http_error(): void
    {
        $term = $this->createMockTerm('category', 1, 'Tech');

        $taxonomyObject = Mockery::mock('stdClass');
        $taxonomyObject->public = true;

        $wpError = Mockery::mock('WP_Error');

        Functions\when('get_term')
            ->justReturn($term);

        Functions\when('get_taxonomy')
            ->justReturn($taxonomyObject);

        Functions\when('get_term_link')
            ->justReturn('https://example.com/category/tech/');

        Functions\when('wp_remote_get')
            ->justReturn($wpError);

        // Handle is_wp_error for both term and http response
        Functions\when('is_wp_error')
            ->alias(function ($thing) use ($wpError) {
                return $thing === $wpError;
            });

        Functions\when('apply_filters')
            ->returnArg(2);

        $result = $this->renderer->render(1);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_on_non_200_status(): void
    {
        $term = $this->createMockTerm('category', 1, 'Tech');

        $taxonomyObject = Mockery::mock('stdClass');
        $taxonomyObject->public = true;

        Functions\when('get_term')
            ->justReturn($term);

        Functions\when('get_taxonomy')
            ->justReturn($taxonomyObject);

        Functions\when('get_term_link')
            ->justReturn('https://example.com/category/tech/');

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

        $result = $this->renderer->render(1);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_on_empty_body(): void
    {
        $term = $this->createMockTerm('category', 1, 'Tech');

        $taxonomyObject = Mockery::mock('stdClass');
        $taxonomyObject->public = true;

        Functions\when('get_term')
            ->justReturn($term);

        Functions\when('get_taxonomy')
            ->justReturn($taxonomyObject);

        Functions\when('get_term_link')
            ->justReturn('https://example.com/category/tech/');

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

        $result = $this->renderer->render(1);

        $this->assertNull($result);
    }

    #[Test]
    public function it_includes_term_id_in_meta(): void
    {
        $term = $this->createMockTerm('category', 42, 'News');

        $taxonomyObject = Mockery::mock('stdClass');
        $taxonomyObject->public = true;

        $htmlContent = '<html><body>News Archive</body></html>';

        Functions\when('get_term')
            ->justReturn($term);

        Functions\when('get_taxonomy')
            ->justReturn($taxonomyObject);

        Functions\when('get_term_link')
            ->justReturn('https://example.com/category/news/');

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

        $result = $this->renderer->render(42);

        $this->assertSame(42, $result->meta['term_id']);
    }

    /**
     * Create a mock WP_Term object.
     */
    private function createMockTerm(string $taxonomy, int $termId, string $name): WP_Term
    {
        $term = Mockery::mock(WP_Term::class);
        $term->term_id = $termId;
        $term->taxonomy = $taxonomy;
        $term->name = $name;
        $term->slug = strtolower(str_replace(' ', '-', $name));

        return $term;
    }
}
