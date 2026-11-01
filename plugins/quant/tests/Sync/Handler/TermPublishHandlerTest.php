<?php

declare(strict_types=1);

namespace Quant\Tests\Sync\Handler;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Sync\Exception\RedirectResponseException;
use Quant\Sync\Handler\TermPublishHandler;
use Quant\Sync\Publisher;
use Quant\Sync\Renderer\RenderedContent;
use Quant\Sync\Renderer\RouteRenderer;
use Quant\Sync\Renderer\TermRenderer;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use Quant\Tests\Support\WordPressStubs;
use WP_Taxonomy;

#[CoversClass(TermPublishHandler::class)]
final class TermPublishHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private RouteRenderer&Mockery\MockInterface $routes;
    private Publisher&Mockery\MockInterface $publisher;
    private SyncQueueInterface&Mockery\MockInterface $queue;
    private TermPublishHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $GLOBALS['wp_rewrite'] = new class {
            public string $pagination_base = 'page';
            public function using_permalinks(): bool { return true; }
        };
        Functions\when('get_taxonomy')->alias(static fn(string $n) => $n === 'hidden' ? new WP_Taxonomy($n, false) : new WP_Taxonomy($n));
        Functions\when('get_term')->alias(fn(int $id, string $tax = '') => $this->makeTerm($id, $tax ?: 'post_tag', 'Alpha'));
        Functions\when('get_term_link')->justReturn('https://example.com/tag/alpha/');
        Functions\when('get_option')->alias(static fn(string $k, $d = false) => $k === 'posts_per_page' ? 10 : $d);

        $this->routes = Mockery::mock(RouteRenderer::class);
        $this->publisher = Mockery::mock(Publisher::class);
        $this->queue = Mockery::mock(SyncQueueInterface::class);
        $this->handler = new TermPublishHandler(new TermRenderer($this->routes), $this->publisher, $this->queue);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_rewrite']);
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_supports_taxonomy_publish_jobs(): void
    {
        $this->assertTrue($this->handler->supports(new SyncJob(SyncType::Taxonomy, '1', SyncAction::Publish)));
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Taxonomy, '1', SyncAction::Unpublish)));
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Post, '1', SyncAction::Publish)));
    }

    #[Test]
    public function it_renders_the_requested_page_of_the_term(): void
    {
        $this->routes->shouldReceive('render')->once()->with('/tag/alpha/page/2/')->andReturn(new RenderedContent('/tag/alpha/page/2', 'x'));
        $this->publisher->shouldReceive('publish')->once()->with(Mockery::on(static fn(RenderedContent $c): bool => $c->url === '/tag/alpha/page/2'));

        $this->handler->handle(new SyncJob(SyncType::Taxonomy, '3', SyncAction::Publish, ['taxonomy' => 'post_tag', 'page' => 2]));
    }

    #[Test]
    public function it_guesses_the_taxonomy_from_the_term_when_meta_is_missing(): void
    {
        $this->routes->shouldReceive('render')->once()->with('/tag/alpha/')->andReturn(new RenderedContent('/tag/alpha', 'x'));
        $this->publisher->shouldReceive('publish')->once();

        $this->handler->handle(new SyncJob(SyncType::Taxonomy, '3', SyncAction::Publish));
    }

    #[Test]
    public function it_queues_redirects_and_unpublishes_private_taxonomies(): void
    {
        $this->routes->shouldReceive('render')->once()->andThrow(new RedirectResponseException('/tag/alpha', '/tags/alpha', 301));
        $this->queue->shouldReceive('push')->once()->with(Mockery::on(static fn(SyncJob $j): bool => $j->type === SyncType::Redirect && $j->meta['to'] === '/tags/alpha'));
        $this->handler->handle(new SyncJob(SyncType::Taxonomy, '3', SyncAction::Publish, ['taxonomy' => 'post_tag']));

        $this->publisher->shouldReceive('unpublish')->once()->with(['/tag/alpha/']);
        $this->handler->handle(new SyncJob(SyncType::Taxonomy, '4', SyncAction::Publish, ['taxonomy' => 'hidden']));
    }
}
