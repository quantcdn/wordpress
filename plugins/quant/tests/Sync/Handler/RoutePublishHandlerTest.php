<?php

declare(strict_types=1);

namespace Quant\Tests\Sync\Handler;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\ApiResponse;
use Quant\Api\ContentApiInterface;
use Quant\Sync\Exception\RedirectResponseException;
use Quant\Sync\Handler\RoutePublishHandler;
use Quant\Sync\Publisher;
use Quant\Sync\Renderer\RenderedContent;
use Quant\Sync\Renderer\RouteRenderer;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use Quant\Tests\Support\WordPressStubs;
use RuntimeException;

#[CoversClass(RoutePublishHandler::class)]
final class RoutePublishHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private RouteRenderer&Mockery\MockInterface $renderer;
    private Publisher&Mockery\MockInterface $publisher;
    private ContentApiInterface&Mockery\MockInterface $api;
    private SyncQueueInterface&Mockery\MockInterface $queue;
    private RoutePublishHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $this->renderer = Mockery::mock(RouteRenderer::class);
        $this->publisher = Mockery::mock(Publisher::class);
        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->queue = Mockery::mock(SyncQueueInterface::class);
        $this->handler = new RoutePublishHandler($this->renderer, $this->publisher, $this->api, $this->queue);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_supports_every_route_type_for_publishing(): void
    {
        foreach ([SyncType::Home, SyncType::Archive, SyncType::Author, SyncType::CustomRoute, SyncType::Route, SyncType::NotFound, SyncType::Sitemap] as $type) {
            $this->assertTrue($this->handler->supports(new SyncJob($type, '/', SyncAction::Publish)), $type->value);
        }
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Home, '/', SyncAction::Unpublish)));
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Post, '1', SyncAction::Publish)));
    }

    #[Test]
    public function it_renders_the_home_page_at_root_even_for_the_legacy_object_id(): void
    {
        $this->renderer->shouldReceive('render')->once()->with('/', null, false)->andReturn(new RenderedContent('/', 'x'));
        $this->publisher->shouldReceive('publish')->once();

        $this->handler->handle(new SyncJob(SyncType::Home, 'home', SyncAction::Publish));
    }

    #[Test]
    public function it_publishes_the_404_page_under_the_quant_path(): void
    {
        $this->renderer->shouldReceive('render')->once()->with('/not-found', '/_quant404', true)->andReturn(new RenderedContent('/_quant404', 'x'));
        $this->publisher->shouldReceive('publish')->once();

        $this->handler->handle(new SyncJob(SyncType::NotFound, '/not-found', SyncAction::Publish, ['publish_as' => '/_quant404']));
    }

    #[Test]
    public function it_uploads_a_custom_route_that_is_a_file(): void
    {
        $file = ABSPATH . 'robots-' . uniqid() . '.txt';
        file_put_contents($file, 'User-agent: *');

        try {
            $this->api->shouldReceive('uploadFile')->once()->with('/' . basename($file), $file)->andReturn(ApiResponse::success());
            $this->renderer->shouldNotReceive('render');

            $this->handler->handle(new SyncJob(SyncType::CustomRoute, '/' . basename($file) . '?v=2', SyncAction::Publish));
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function it_fails_when_a_file_upload_is_rejected(): void
    {
        $file = ABSPATH . 'f-' . uniqid() . '.txt';
        file_put_contents($file, 'x');

        try {
            $this->api->shouldReceive('uploadFile')->once()->andReturn(ApiResponse::error('denied'));
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('denied');

            $this->handler->handle(new SyncJob(SyncType::CustomRoute, '/' . basename($file), SyncAction::Publish));
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function it_queues_a_redirect_for_a_redirecting_route(): void
    {
        $this->renderer->shouldReceive('render')->andThrow(new RedirectResponseException('/blog/page/2', '/blog', 301));
        $this->queue->shouldReceive('push')->once()->with(Mockery::on(static fn(SyncJob $j): bool => $j->type === SyncType::Redirect && $j->objectId === '/blog/page/2'));

        $this->handler->handle(new SyncJob(SyncType::Route, '/blog/page/2', SyncAction::Publish));
    }

    #[Test]
    public function it_treats_a_redirecting_home_page_as_an_error(): void
    {
        $this->renderer->shouldReceive('render')->andThrow(new RedirectResponseException('/', '/en', 302));
        $this->queue->shouldNotReceive('push');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected redirect');

        $this->handler->handle(new SyncJob(SyncType::Home, '/', SyncAction::Publish));
    }

    #[Test]
    public function it_unpublishes_a_route_that_now_returns_404(): void
    {
        $this->renderer->shouldReceive('render')->andThrow(new \Quant\Sync\Exception\UnpublishedContentException('/old-route', 'HTTP 404 response for /old-route'));
        $this->publisher->shouldReceive('unpublish')->once()->with(['/old-route']);
        $this->publisher->shouldNotReceive('publish');

        $this->handler->handle(new SyncJob(SyncType::CustomRoute, '/old-route', SyncAction::Publish));
    }

    #[Test]
    public function it_treats_a_missing_home_page_as_an_error(): void
    {
        $this->renderer->shouldReceive('render')->andThrow(new \Quant\Sync\Exception\UnpublishedContentException('/', 'HTTP 404 response for /'));
        $this->publisher->shouldNotReceive('unpublish');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The home page returned an error');

        $this->handler->handle(new SyncJob(SyncType::Home, '/', SyncAction::Publish));
    }

    #[Test]
    public function it_never_uploads_a_php_file_named_as_a_custom_route(): void
    {
        $file = ABSPATH . 'wp-config-' . uniqid() . '.php';
        file_put_contents($file, '<?php // secrets');

        try {
            $this->api->shouldNotReceive('uploadFile');
            $this->renderer->shouldReceive('render')->once()->andReturn(new \Quant\Sync\Renderer\RenderedContent('/x', 'y'));
            $this->publisher->shouldReceive('publish')->once();

            $this->handler->handle(new SyncJob(SyncType::CustomRoute, '/' . basename($file), SyncAction::Publish));
        } finally {
            unlink($file);
        }
    }
}
