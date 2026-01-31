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
use Quant\Sync\Renderer\PostRenderer;
use Quant\Sync\Renderer\RenderedContent;
use WP_Post;

#[CoversClass(PostRenderer::class)]
final class PostRendererTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private PostRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->renderer = new PostRenderer();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_supports_published_posts(): void
    {
        $post = $this->createMockPost('post', 'publish');

        $this->assertTrue($this->renderer->supports($post));
    }

    #[Test]
    public function it_supports_published_pages(): void
    {
        $post = $this->createMockPost('page', 'publish');

        $this->assertTrue($this->renderer->supports($post));
    }

    #[Test]
    public function it_supports_public_custom_post_types(): void
    {
        $post = $this->createMockPost('product', 'publish');

        $postTypeObject = Mockery::mock('stdClass');
        $postTypeObject->public = true;

        Functions\when('get_post_type_object')
            ->justReturn($postTypeObject);

        $this->assertTrue($this->renderer->supports($post));
    }

    #[Test]
    public function it_does_not_support_private_custom_post_types(): void
    {
        $post = $this->createMockPost('revision', 'publish');

        $postTypeObject = Mockery::mock('stdClass');
        $postTypeObject->public = false;

        Functions\when('get_post_type_object')
            ->justReturn($postTypeObject);

        $this->assertFalse($this->renderer->supports($post));
    }

    #[Test]
    public function it_does_not_support_draft_posts(): void
    {
        $post = $this->createMockPost('post', 'draft');

        $this->assertFalse($this->renderer->supports($post));
    }

    #[Test]
    public function it_does_not_support_pending_posts(): void
    {
        $post = $this->createMockPost('post', 'pending');

        $this->assertFalse($this->renderer->supports($post));
    }

    #[Test]
    public function it_does_not_support_private_posts(): void
    {
        $post = $this->createMockPost('post', 'private');

        $this->assertFalse($this->renderer->supports($post));
    }

    #[Test]
    public function it_does_not_support_unknown_custom_post_types(): void
    {
        $post = $this->createMockPost('unknown_type', 'publish');

        Functions\when('get_post_type_object')
            ->justReturn(null);

        $this->assertFalse($this->renderer->supports($post));
    }

    #[Test]
    public function it_renders_a_post_successfully(): void
    {
        $post = $this->createMockPost('post', 'publish', 123);
        $post->post_title = 'Test Post';
        $post->post_modified_gmt = '2024-01-15 10:00:00';
        $post->post_date_gmt = '2024-01-10 09:00:00';

        $htmlContent = '<html><body>Hello World</body></html>';

        Functions\when('get_post')
            ->justReturn($post);

        Functions\when('get_permalink')
            ->justReturn('https://example.com/blog/test-post');

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

        $result = $this->renderer->render(123);

        $this->assertInstanceOf(RenderedContent::class, $result);
        $this->assertSame('/blog/test-post', $result->url);
        $this->assertSame($htmlContent, $result->markup);
        $this->assertSame('post', $result->meta['post_type']);
        $this->assertSame('Test Post', $result->meta['title']);
        $this->assertSame('2024-01-15 10:00:00', $result->meta['modified']);
        $this->assertSame('2024-01-10 09:00:00', $result->meta['published']);
    }

    #[Test]
    public function it_returns_null_when_post_not_found(): void
    {
        Functions\when('get_post')
            ->justReturn(null);

        $result = $this->renderer->render(999);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_when_permalink_is_false(): void
    {
        $post = $this->createMockPost('post', 'publish', 123);

        Functions\when('get_post')
            ->justReturn($post);

        Functions\when('get_permalink')
            ->justReturn(false);

        $result = $this->renderer->render(123);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_on_http_error(): void
    {
        $post = $this->createMockPost('post', 'publish', 123);

        Functions\when('get_post')
            ->justReturn($post);

        Functions\when('get_permalink')
            ->justReturn('https://example.com/test');

        $wpError = Mockery::mock('WP_Error');

        Functions\when('wp_remote_get')
            ->justReturn($wpError);

        Functions\when('is_wp_error')
            ->justReturn(true);

        $result = $this->renderer->render(123);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_on_non_200_status(): void
    {
        $post = $this->createMockPost('post', 'publish', 123);

        Functions\when('get_post')
            ->justReturn($post);

        Functions\when('get_permalink')
            ->justReturn('https://example.com/test');

        Functions\when('wp_remote_get')
            ->justReturn([
                'response' => ['code' => 404],
                'body' => '',
            ]);

        Functions\when('is_wp_error')
            ->justReturn(false);

        Functions\when('wp_remote_retrieve_response_code')
            ->justReturn(404);

        $result = $this->renderer->render(123);

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_on_empty_body(): void
    {
        $post = $this->createMockPost('post', 'publish', 123);

        Functions\when('get_post')
            ->justReturn($post);

        Functions\when('get_permalink')
            ->justReturn('https://example.com/test');

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

        $result = $this->renderer->render(123);

        $this->assertNull($result);
    }

    #[Test]
    public function it_extracts_path_from_url_with_trailing_slash(): void
    {
        $post = $this->createMockPost('page', 'publish', 456);
        $post->post_title = 'About Us';
        $post->post_modified_gmt = '2024-01-15 10:00:00';
        $post->post_date_gmt = '2024-01-10 09:00:00';

        Functions\when('get_post')
            ->justReturn($post);

        Functions\when('get_permalink')
            ->justReturn('https://example.com/about/');

        Functions\when('wp_remote_get')
            ->justReturn([
                'response' => ['code' => 200],
                'body' => '<html></html>',
            ]);

        Functions\when('is_wp_error')
            ->justReturn(false);

        Functions\when('wp_remote_retrieve_response_code')
            ->justReturn(200);

        Functions\when('wp_remote_retrieve_body')
            ->justReturn('<html></html>');

        Functions\when('wp_parse_url')
            ->alias('parse_url');

        $result = $this->renderer->render(456);

        $this->assertSame('/about/', $result->url);
    }

    #[Test]
    public function it_handles_root_url(): void
    {
        $post = $this->createMockPost('page', 'publish', 1);
        $post->post_title = 'Home';
        $post->post_modified_gmt = '2024-01-15 10:00:00';
        $post->post_date_gmt = '2024-01-10 09:00:00';

        Functions\when('get_post')
            ->justReturn($post);

        Functions\when('get_permalink')
            ->justReturn('https://example.com/');

        Functions\when('wp_remote_get')
            ->justReturn([
                'response' => ['code' => 200],
                'body' => '<html></html>',
            ]);

        Functions\when('is_wp_error')
            ->justReturn(false);

        Functions\when('wp_remote_retrieve_response_code')
            ->justReturn(200);

        Functions\when('wp_remote_retrieve_body')
            ->justReturn('<html></html>');

        Functions\when('wp_parse_url')
            ->alias('parse_url');

        $result = $this->renderer->render(1);

        $this->assertSame('/', $result->url);
    }

    #[Test]
    public function it_returns_null_for_unsupported_post_status(): void
    {
        $post = $this->createMockPost('post', 'draft', 123);

        Functions\when('get_post')
            ->justReturn($post);

        $result = $this->renderer->render(123);

        $this->assertNull($result);
    }

    /**
     * Create a mock WP_Post object.
     */
    private function createMockPost(string $postType, string $status, int $id = 1): WP_Post
    {
        $post = Mockery::mock(WP_Post::class);
        $post->ID = $id;
        $post->post_type = $postType;
        $post->post_status = $status;
        $post->post_title = 'Test Post';
        $post->post_modified_gmt = '2024-01-15 10:00:00';
        $post->post_date_gmt = '2024-01-10 09:00:00';

        return $post;
    }
}
