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
use Quant\Sync\Renderer\RenderedContent;
use Quant\Sync\Renderer\RouteRenderer;
use Quant\Sync\Renderer\TermRenderer;
use Quant\Tests\Support\WordPressStubs;
use RuntimeException;
use WP_Taxonomy;

#[CoversClass(TermRenderer::class)]
final class TermRendererTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private RouteRenderer&Mockery\MockInterface $routes;
    private TermRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $GLOBALS['wp_rewrite'] = new class {
            public string $pagination_base = 'page';
            public function using_permalinks(): bool { return true; }
        };

        Functions\when('get_taxonomy')->alias(static fn(string $name) => match ($name) {
            'category', 'post_tag', 'genre' => new WP_Taxonomy($name),
            'hidden' => new WP_Taxonomy($name, false),
            default => false,
        });
        Functions\when('get_option')->alias(static fn(string $k, $d = false) => $k === 'posts_per_page' ? 10 : $d);

        $this->routes = Mockery::mock(RouteRenderer::class);
        $this->renderer = new TermRenderer($this->routes);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_rewrite']);
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_supports_public_taxonomies_only(): void
    {
        $this->assertTrue($this->renderer->supports($this->makeTerm(1, 'category')));
        $this->assertTrue($this->renderer->supports($this->makeTerm(2, 'genre')));
        $this->assertFalse($this->renderer->supports($this->makeTerm(3, 'hidden')));
        $this->assertFalse($this->renderer->supports($this->makeTerm(4, 'nav_menu')));
        $this->assertFalse($this->renderer->supports($this->makeTerm(5, 'missing')));
    }

    #[Test]
    public function it_renders_the_first_page_of_a_term(): void
    {
        $term = $this->makeTerm(7, 'category', 'News');
        Functions\when('get_term')->justReturn($term);
        Functions\when('get_term_link')->justReturn('https://example.com/category/news/');

        $this->routes->shouldReceive('render')->once()->with('/category/news/')
            ->andReturn(new RenderedContent('/category/news', '<html/>', [], ['published' => true]));

        $content = $this->renderer->render(7, 'category');

        $this->assertSame('/category/news', $content->url);
        $this->assertSame('category #7 (News)', $content->info['log']);
        $this->assertSame('wordpress', $content->info['source']);
    }

    #[Test]
    public function it_renders_a_paginated_term_page(): void
    {
        Functions\when('get_term')->justReturn($this->makeTerm(7, 'category', 'News', 25));
        Functions\when('get_term_link')->justReturn('https://example.com/category/news/');

        $this->routes->shouldReceive('render')->once()->with('/category/news/page/3/')
            ->andReturn(new RenderedContent('/category/news/page/3', '<html/>'));

        $this->assertSame('/category/news/page/3', $this->renderer->render(7, 'category', 3)->url);
    }

    #[Test]
    public function it_lists_every_page_path_of_a_term(): void
    {
        Functions\when('get_term_link')->justReturn('https://example.com/category/news/');

        $paths = $this->renderer->pagePaths($this->makeTerm(7, 'category', 'News', 25));

        $this->assertSame(['/category/news/', '/category/news/page/2/', '/category/news/page/3/'], $paths);
    }

    #[Test]
    public function it_fails_for_a_missing_term(): void
    {
        Functions\when('get_term')->justReturn(null);

        $this->expectException(RuntimeException::class);

        $this->renderer->render(99, 'category');
    }

    #[Test]
    public function it_fails_when_the_link_is_an_error(): void
    {
        Functions\when('get_term')->justReturn($this->makeTerm(7));
        Functions\when('get_term_link')->justReturn(new \WP_Error('invalid_term', 'Invalid'));

        $this->expectException(RuntimeException::class);

        $this->renderer->render(7, 'category');
    }

    #[Test]
    public function it_signals_unpublished_for_a_private_taxonomy(): void
    {
        Functions\when('get_term')->justReturn($this->makeTerm(7, 'hidden'));
        Functions\when('get_term_link')->justReturn('https://example.com/hidden/x/');

        $this->expectException(UnpublishedContentException::class);

        $this->renderer->render(7, 'hidden');
    }
}
