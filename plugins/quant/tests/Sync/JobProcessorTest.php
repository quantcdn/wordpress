<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Sync\Handler\HandlerInterface;
use Quant\Sync\JobProcessor;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncResult;
use Quant\Sync\SyncType;
use Quant\Tests\Support\WordPressStubs;
use RuntimeException;

#[CoversClass(JobProcessor::class)]
#[CoversClass(SyncResult::class)]
final class JobProcessorTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private SyncQueueInterface&Mockery\MockInterface $queue;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->queue = Mockery::mock(SyncQueueInterface::class);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function handler(SyncType $type, ?callable $onHandle = null): HandlerInterface
    {
        return new class ($type, $onHandle) implements HandlerInterface {
            public array $handled = [];
            public function __construct(private SyncType $type, private $onHandle) {}
            public function supports(SyncJob $job): bool { return $job->type === $this->type; }
            public function handle(SyncJob $job): void
            {
                $this->handled[] = $job->objectId;
                if ($this->onHandle) {
                    ($this->onHandle)($job);
                }
            }
        };
    }

    #[Test]
    public function it_dispatches_to_the_first_supporting_handler(): void
    {
        $posts = $this->handler(SyncType::Post);
        $pages = $this->handler(SyncType::Page);
        $processor = new JobProcessor($this->queue, $this->mockSettings(), [$posts, $pages]);

        $processor->process(new SyncJob(SyncType::Page, '7', SyncAction::Publish));

        $this->assertSame([], $posts->handled);
        $this->assertSame(['7'], $pages->handled);
    }

    #[Test]
    public function it_fails_loudly_without_a_handler(): void
    {
        $processor = new JobProcessor($this->queue, $this->mockSettings(), []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No handler for media publish');

        $processor->process(new SyncJob(SyncType::Media, '1', SyncAction::Publish));
    }

    #[Test]
    public function it_runs_a_batch_marking_jobs_completed_or_failed(): void
    {
        $ok = new SyncJob(SyncType::Post, '1', SyncAction::Publish);
        $bad = new SyncJob(SyncType::Post, '2', SyncAction::Publish);
        $handler = $this->handler(SyncType::Post, static function (SyncJob $job): void {
            if ($job->objectId === '2') {
                throw new RuntimeException('HTTP 500');
            }
        });

        $this->queue->shouldReceive('recoverStale')->once();
        $this->queue->shouldReceive('fetchPending')->once()->with(20)->andReturn([$ok, $bad]);
        $this->queue->shouldReceive('markCompleted')->once()->with($ok->id);
        $this->queue->shouldReceive('markFailed')->once()->with($bad->id, 'HTTP 500');
        Actions\expectDone('quant_sync_job_failed')->once()->with($bad, Mockery::type(RuntimeException::class));

        $result = (new JobProcessor($this->queue, $this->mockSettings(), [$handler]))->run(20, 25);

        $this->assertSame(1, $result->processed);
        $this->assertSame(1, $result->failed);
        $this->assertSame(['post 2: HTTP 500'], $result->errors);
    }

    #[Test]
    public function it_releases_unprocessed_jobs_when_the_time_budget_is_spent(): void
    {
        $a = new SyncJob(SyncType::Post, '1', SyncAction::Publish);
        $b = new SyncJob(SyncType::Post, '2', SyncAction::Publish);
        $handler = $this->handler(SyncType::Post);

        $this->queue->shouldReceive('recoverStale')->once();
        $this->queue->shouldReceive('fetchPending')->once()->andReturn([$a, $b]);
        $this->queue->shouldReceive('release')->once()->with([$a->id, $b->id]);
        $this->queue->shouldNotReceive('markCompleted');

        $result = (new JobProcessor($this->queue, $this->mockSettings(), [$handler]))->run(20, -1);

        $this->assertSame(0, $result->processed);
        $this->assertSame([], $handler->handled);
    }

    #[Test]
    public function it_refuses_to_run_without_credentials(): void
    {
        $this->queue->shouldNotReceive('fetchPending');
        $settings = $this->mockSettings(credentials: ['token' => '']);

        $result = (new JobProcessor($this->queue, $settings, []))->run();

        $this->assertSame(['Credentials not configured'], $result->errors);
    }
}
