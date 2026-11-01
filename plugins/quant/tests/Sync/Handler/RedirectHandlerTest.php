<?php

declare(strict_types=1);

namespace Quant\Tests\Sync\Handler;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\ApiResponse;
use Quant\Api\ContentApiInterface;
use Quant\Sync\Handler\RedirectHandler;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use RuntimeException;

#[CoversClass(RedirectHandler::class)]
final class RedirectHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ContentApiInterface&Mockery\MockInterface $api;
    private RedirectHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->handler = new RedirectHandler($this->api);
    }

    #[Test]
    public function it_supports_redirect_publish_and_unpublish_jobs(): void
    {
        $this->assertTrue($this->handler->supports(new SyncJob(SyncType::Redirect, '/a', SyncAction::Publish)));
        $this->assertTrue($this->handler->supports(new SyncJob(SyncType::Redirect, '/a', SyncAction::Unpublish)));
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Redirect, '/a', SyncAction::Purge)));
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Post, '1', SyncAction::Publish)));
    }

    #[Test]
    public function it_creates_a_redirect_from_the_job_meta(): void
    {
        $this->api->shouldReceive('createRedirect')->once()->with('/old', '/new', 302)->andReturn(ApiResponse::success());

        $this->handler->handle(new SyncJob(SyncType::Redirect, '/old', SyncAction::Publish, ['to' => '/new', 'code' => 302]));
    }

    #[Test]
    public function it_accepts_the_legacy_source_and_destination_keys(): void
    {
        $this->api->shouldReceive('createRedirect')->once()->with('/legacy', '/target', 301)->andReturn(ApiResponse::success());

        $this->handler->handle(new SyncJob(SyncType::Redirect, '7', SyncAction::Publish, ['source' => '/legacy', 'destination' => '/target']));
    }

    #[Test]
    public function it_fails_without_a_destination_or_on_api_errors(): void
    {
        try {
            $this->handler->handle(new SyncJob(SyncType::Redirect, '/old', SyncAction::Publish));
            $this->fail('Expected an exception');
        } catch (RuntimeException $e) {
            $this->assertSame('Missing destination for redirect /old', $e->getMessage());
        }

        $this->api->shouldReceive('createRedirect')->once()->andReturn(ApiResponse::error('bad'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API error publishing redirect /old: bad');
        $this->handler->handle(new SyncJob(SyncType::Redirect, '/old', SyncAction::Publish, ['to' => '/new']));
    }

    #[Test]
    public function it_removes_a_redirect_by_unpublishing_its_source(): void
    {
        $this->api->shouldReceive('unpublish')->once()->with('/old')->andReturn(ApiResponse::success());

        $this->handler->handle(new SyncJob(SyncType::Redirect, '/old', SyncAction::Unpublish));
    }
}
