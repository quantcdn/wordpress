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
use Quant\Sync\Handler\PurgeHandler;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use RuntimeException;

#[CoversClass(PurgeHandler::class)]
final class PurgeHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ContentApiInterface&Mockery\MockInterface $api;
    private PurgeHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->handler = new PurgeHandler($this->api);
    }

    #[Test]
    public function it_supports_purge_jobs_of_any_type(): void
    {
        $this->assertTrue($this->handler->supports(new SyncJob(SyncType::Post, '1', SyncAction::Purge)));
        $this->assertTrue($this->handler->supports(new SyncJob(SyncType::Route, '/', SyncAction::Purge)));
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Route, '/', SyncAction::Publish)));
    }

    #[Test]
    public function it_purges_paths_from_meta_or_the_object_id(): void
    {
        $this->api->shouldReceive('purge')->once()->with(['/a', '/b'])->andReturn(ApiResponse::success());
        $this->handler->handle(new SyncJob(SyncType::Route, 'x', SyncAction::Purge, ['paths' => ['/a', '/b', 3]]));

        $this->api->shouldReceive('purge')->once()->with(['/c'])->andReturn(ApiResponse::success());
        $this->handler->handle(new SyncJob(SyncType::Route, 'x', SyncAction::Purge, ['path' => '/c']));

        $this->api->shouldReceive('purge')->once()->with(['/d'])->andReturn(ApiResponse::success());
        $this->handler->handle(new SyncJob(SyncType::Route, '/d', SyncAction::Purge));
    }

    #[Test]
    public function it_fails_when_the_purge_fails(): void
    {
        $this->api->shouldReceive('purge')->once()->andReturn(ApiResponse::error('edge down'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('edge down');

        $this->handler->handle(new SyncJob(SyncType::Route, '/d', SyncAction::Purge));
    }
}
