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
use Quant\Search\SearchRecordBuilder;
use Quant\Sync\Exception\RedirectResponseException;
use Quant\Sync\Exception\UnpublishedContentException;
use Quant\Sync\Handler\PostPublishHandler;
use Quant\Sync\Publisher;
use Quant\Sync\Renderer\ContentRendererInterface;
use Quant\Sync\Renderer\RenderedContent;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(PostPublishHandler::class)]
#[CoversClass(RedirectResponseException::class)]
#[CoversClass(UnpublishedContentException::class)]
final class PostPublishHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private ContentRendererInterface&Mockery\MockInterface $renderer;
    private Publisher&Mockery\MockInterface $publisher;
    private SearchRecordBuilder&Mockery\MockInterface $search;
    private SyncQueueInterface&Mockery\MockInterface $queue;
    private PostPublishHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->renderer = Mockery::mock(ContentRendererInterface::class);
        $this->publisher = Mockery::mock(Publisher::class);
        $this->search = Mockery::mock(SearchRecordBuilder::class);
        $this->queue = Mockery::mock(SyncQueueInterface::class);
        $this->handler = new PostPublishHandler($this->renderer, $this->publisher, $this->search, $this->queue);

        Functions\when('get_post')->justReturn($this->makePost(1));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_supports_publish_jobs_for_post_types_only(): void
    {
        $this->assertTrue($this->handler->supports(new SyncJob(SyncType::Post, '1', SyncAction::Publish)));
        $this->assertTrue($this->handler->supports(new SyncJob(SyncType::CustomPostType, '1', SyncAction::Publish)));
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Post, '1', SyncAction::Unpublish)));
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Media, '1', SyncAction::Publish)));
    }

    #[Test]
    public function it_renders_adds_the_search_record_and_publishes(): void
    {
        $rendered = new RenderedContent('/p', '<html/>', ['source' => 'wordpress'], ['published' => true]);
        $this->renderer->shouldReceive('render')->once()->with(1)->andReturn($rendered);
        $this->search->shouldReceive('enabled')->andReturn(true);
        $this->search->shouldReceive('forPost')->once()->andReturn(['title' => 'Post 1']);
        $this->publisher->shouldReceive('publish')->once()->with(Mockery::on(static fn(RenderedContent $c): bool =>
            $c->url === '/p' && $c->options['search_record'] === ['title' => 'Post 1'] && $c->options['published'] === true));

        $this->handler->handle(new SyncJob(SyncType::Post, '1', SyncAction::Publish));
    }

    #[Test]
    public function it_leaves_the_search_record_out_when_search_is_disabled(): void
    {
        $this->renderer->shouldReceive('render')->andReturn(new RenderedContent('/p', 'x'));
        $this->search->shouldReceive('enabled')->andReturn(false);
        $this->search->shouldNotReceive('forPost');
        $this->publisher->shouldReceive('publish')->once()->with(Mockery::on(static fn(RenderedContent $c): bool => !isset($c->options['search_record'])));

        $this->handler->handle(new SyncJob(SyncType::Post, '1', SyncAction::Publish));
    }

    #[Test]
    public function it_queues_a_redirect_when_the_page_redirects(): void
    {
        $this->renderer->shouldReceive('render')->andThrow(new RedirectResponseException('/old', '/new', 301));
        $this->queue->shouldReceive('push')->once()->with(Mockery::on(static fn(SyncJob $j): bool =>
            $j->type === SyncType::Redirect && $j->objectId === '/old' && $j->meta === ['to' => '/new', 'code' => 301]));
        $this->publisher->shouldNotReceive('publish');

        $this->handler->handle(new SyncJob(SyncType::Post, '1', SyncAction::Publish));
    }

    #[Test]
    public function it_unpublishes_content_that_is_no_longer_public(): void
    {
        $this->renderer->shouldReceive('render')->andThrow(new UnpublishedContentException('/draft', 'draft'));
        $this->publisher->shouldReceive('unpublish')->once()->with(['/draft']);

        $this->handler->handle(new SyncJob(SyncType::Page, '1', SyncAction::Publish));
    }

    #[Test]
    public function it_lets_other_render_errors_propagate(): void
    {
        $this->renderer->shouldReceive('render')->andThrow(new \RuntimeException('HTTP 500'));

        $this->expectException(\RuntimeException::class);

        $this->handler->handle(new SyncJob(SyncType::Post, '1', SyncAction::Publish));
    }
}
