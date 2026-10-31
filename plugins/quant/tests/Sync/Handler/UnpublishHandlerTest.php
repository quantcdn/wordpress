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
use Quant\Sync\Handler\UnpublishHandler;
use Quant\Sync\Publisher;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use Quant\Tests\Support\WordPressStubs;
use RuntimeException;

#[CoversClass(UnpublishHandler::class)]
final class UnpublishHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private Publisher&Mockery\MockInterface $publisher;
    private UnpublishHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();
        $this->publisher = Mockery::mock(Publisher::class);
        $this->handler = new UnpublishHandler($this->publisher);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_supports_every_unpublish_job_except_redirects(): void
    {
        $this->assertTrue($this->handler->supports(new SyncJob(SyncType::Post, '1', SyncAction::Unpublish)));
        $this->assertTrue($this->handler->supports(new SyncJob(SyncType::Taxonomy, '1', SyncAction::Unpublish)));
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Redirect, '/a', SyncAction::Unpublish)));
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Post, '1', SyncAction::Publish)));
    }

    #[Test]
    public function it_prefers_the_paths_captured_in_meta(): void
    {
        $this->publisher->shouldReceive('unpublish')->once()->with(['/old-post', '/tag/x', '/tag/x/page/2']);

        $this->handler->handle(new SyncJob(SyncType::Post, '1', SyncAction::Unpublish, [
            'url' => '/old-post/',
            'urls' => ['/tag/x/', '/tag/x/page/2/', ''],
        ]));
    }

    #[Test]
    public function it_derives_paths_for_posts_terms_media_and_routes(): void
    {
        Functions\when('get_post')->justReturn($this->makePost(1));
        Functions\when('get_permalink')->justReturn('https://example.com/hello/');
        $this->publisher->shouldReceive('unpublish')->once()->with(['/hello']);
        $this->handler->handle(new SyncJob(SyncType::Post, '1', SyncAction::Unpublish));

        Functions\when('get_term')->justReturn($this->makeTerm(5));
        Functions\when('get_term_link')->justReturn('https://example.com/category/news/');
        $this->publisher->shouldReceive('unpublish')->once()->with(['/category/news']);
        $this->handler->handle(new SyncJob(SyncType::Taxonomy, '5', SyncAction::Unpublish, ['taxonomy' => 'category']));

        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/wp-content/uploads/a.jpg');
        $this->publisher->shouldReceive('unpublish')->once()->with(['/wp-content/uploads/a.jpg']);
        $this->handler->handle(new SyncJob(SyncType::Media, '9', SyncAction::Unpublish));

        $this->publisher->shouldReceive('unpublish')->once()->with(['/blog/page/2']);
        $this->handler->handle(new SyncJob(SyncType::Route, '/blog/page/2/', SyncAction::Unpublish));
    }

    #[Test]
    public function it_fails_when_no_path_can_be_determined(): void
    {
        Functions\when('get_post')->justReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot determine the path to unpublish for post #1');

        $this->handler->handle(new SyncJob(SyncType::Post, '1', SyncAction::Unpublish));
    }
}
