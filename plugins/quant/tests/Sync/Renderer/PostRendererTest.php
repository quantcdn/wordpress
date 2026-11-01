<?php

declare(strict_types=1);

namespace Quant\Tests\Sync\Renderer;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Sync\Exception\UnpublishedContentException;
use Quant\Sync\Renderer\PostRenderer;
use Quant\Sync\Renderer\RenderedContent;
use Quant\Sync\Renderer\RouteRenderer;
use Quant\Tests\Support\WordPressStubs;
use RuntimeException;
use WP_Post_Type;

#[CoversClass(PostRenderer::class)]
#[CoversClass(RenderedContent::class)]
final class PostRendererTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private RouteRenderer&Mockery\MockInterface $routes;
    private PostRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        Functions\when('get_option')->alias(static fn(string $key, $default = false) => match ($key) {
            'show_on_front' => 'posts',
            'page_on_front' => 0,
            default => $default,
        });
        Functions\when('get_the_author_meta')->justReturn('Jane Author');
        Functions\when('get_post_type_object')->alias(static fn(string $type) => match ($type) {
            'post', 'page' => new WP_Post_Type($type),
            'book' => new WP_Post_Type('book'),
            'secret' => new WP_Post_Type('secret', false),
            default => null,
        });

        $this->routes = Mockery::mock(RouteRenderer::class);
        $this->renderer = new PostRenderer($this->routes);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_supports_published_posts_pages_and_public_custom_types_only(): void
    {
        $this->assertTrue($this->renderer->supports($this->makePost(1, 'post')));
        $this->assertTrue($this->renderer->supports($this->makePost(2, 'page')));
        $this->assertTrue($this->renderer->supports($this->makePost(3, 'book')));
        $this->assertFalse($this->renderer->supports($this->makePost(4, 'secret')));
        $this->assertFalse($this->renderer->supports($this->makePost(5, 'unknown')));
        $this->assertFalse($this->renderer->supports($this->makePost(6, 'post', 'draft')));
        $this->assertFalse($this->renderer->supports($this->makePost(7, 'post', 'private')));
    }

    #[Test]
    public function it_renders_a_post_with_author_info_and_timestamp(): void
    {
        $post = $this->makePost(123);
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/blog/hello/');

        $this->routes->shouldReceive('render')->once()->with('/blog/hello/', null)
            ->andReturn(new RenderedContent('/blog/hello', '<html>hi</html>', ['source' => 'wordpress'], ['published' => true, 'content_timestamp' => 1]));

        $content = $this->renderer->render(123);

        $this->assertSame('/blog/hello', $content->url);
        $this->assertSame('<html>hi</html>', $content->markup);
        $this->assertSame('Jane Author', $content->info['author_name']);
        $this->assertSame('wordpress', $content->info['source']);
        $this->assertSame('post #123 modified 2026-01-15 10:00:00', $content->info['log']);
        $this->assertSame(strtotime('2026-01-15 10:00:00 UTC'), $content->options['content_timestamp']);
        $this->assertTrue($content->options['published']);
    }

    #[Test]
    public function it_publishes_the_static_front_page_at_root(): void
    {
        $post = $this->makePost(9, 'page');
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/home-page/');
        Functions\when('get_option')->alias(static fn(string $key, $default = false) => match ($key) {
            'show_on_front' => 'page',
            'page_on_front' => 9,
            default => $default,
        });

        $this->routes->shouldReceive('render')->once()->with('/home-page/', '/')
            ->andReturn(new RenderedContent('/', '<html/>'));

        $this->assertSame('/', $this->renderer->render(9)->url);
    }

    #[Test]
    public function it_omits_author_name_when_empty(): void
    {
        Functions\when('get_the_author_meta')->justReturn('');
        Functions\when('get_post')->justReturn($this->makePost(1));
        Functions\when('get_permalink')->justReturn('https://example.com/p/');
        $this->routes->shouldReceive('render')->andReturn(new RenderedContent('/p', 'x'));

        $this->assertArrayNotHasKey('author_name', $this->renderer->render(1)->info);
    }

    #[Test]
    public function it_fails_when_the_post_does_not_exist(): void
    {
        Functions\when('get_post')->justReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Post #404 not found');

        $this->renderer->render(404);
    }

    #[Test]
    public function it_fails_without_a_permalink(): void
    {
        Functions\when('get_post')->justReturn($this->makePost(1));
        Functions\when('get_permalink')->justReturn(false);

        $this->expectException(RuntimeException::class);

        $this->renderer->render(1);
    }

    #[Test]
    public function it_signals_unpublished_content_for_drafts(): void
    {
        Functions\when('get_post')->justReturn($this->makePost(1, 'post', 'draft'));
        Functions\when('get_permalink')->justReturn('https://example.com/?p=1');

        try {
            $this->renderer->render(1);
            $this->fail('Expected an unpublished exception');
        } catch (UnpublishedContentException $e) {
            $this->assertSame('/?p=1', $e->path);
        }
    }

    #[Test]
    public function it_signals_unpublished_content_for_password_protected_posts(): void
    {
        $post = $this->makePost(1);
        $post->post_password = 'secret';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/locked/');

        $this->expectException(UnpublishedContentException::class);

        $this->renderer->render(1);
    }
}
