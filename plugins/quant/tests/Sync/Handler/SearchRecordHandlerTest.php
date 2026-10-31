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
use Quant\Api\ApiResponse;
use Quant\Api\ContentApiInterface;
use Quant\Search\SearchRecordBuilder;
use Quant\Sync\Handler\SearchRecordHandler;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use Quant\Tests\Support\WordPressStubs;
use RuntimeException;

#[CoversClass(SearchRecordHandler::class)]
final class SearchRecordHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private ContentApiInterface&Mockery\MockInterface $api;
    private SearchRecordBuilder&Mockery\MockInterface $builder;
    private SearchRecordHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->builder = Mockery::mock(SearchRecordBuilder::class);
        $this->handler = new SearchRecordHandler($this->api, $this->builder);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_supports_search_record_publish_jobs(): void
    {
        $this->assertTrue($this->handler->supports(new SyncJob(SyncType::SearchRecord, '1', SyncAction::Publish)));
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::SearchRecord, '1', SyncAction::Unpublish)));
    }

    #[Test]
    public function it_sends_the_record_for_a_published_post(): void
    {
        Functions\when('get_post')->justReturn($this->makePost(1));
        $this->builder->shouldReceive('forPost')->once()->andReturn(['url' => '/p', 'title' => 'P']);
        $this->api->shouldReceive('sendSearchRecords')->once()->with([['url' => '/p', 'title' => 'P']])->andReturn(ApiResponse::success());

        $this->handler->handle(new SyncJob(SyncType::SearchRecord, '1', SyncAction::Publish));
    }

    #[Test]
    public function it_skips_missing_unpublished_or_excluded_posts(): void
    {
        $this->api->shouldNotReceive('sendSearchRecords');

        Functions\when('get_post')->justReturn(null);
        $this->handler->handle(new SyncJob(SyncType::SearchRecord, '1', SyncAction::Publish));

        Functions\when('get_post')->justReturn($this->makePost(2, 'post', 'draft'));
        $this->handler->handle(new SyncJob(SyncType::SearchRecord, '2', SyncAction::Publish));

        Functions\when('get_post')->justReturn($this->makePost(3));
        $this->builder->shouldReceive('forPost')->once()->andReturn(['skip' => true]);
        $this->handler->handle(new SyncJob(SyncType::SearchRecord, '3', SyncAction::Publish));
    }

    #[Test]
    public function it_fails_when_the_api_rejects_the_record(): void
    {
        Functions\when('get_post')->justReturn($this->makePost(1));
        $this->builder->shouldReceive('forPost')->andReturn(['url' => '/p']);
        $this->api->shouldReceive('sendSearchRecords')->andReturn(ApiResponse::error('index full'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API error indexing /p: index full');

        $this->handler->handle(new SyncJob(SyncType::SearchRecord, '1', SyncAction::Publish));
    }
}
