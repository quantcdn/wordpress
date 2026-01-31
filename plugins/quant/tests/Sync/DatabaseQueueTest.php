<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Quant\Sync\DatabaseQueue;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use Quant\Sync\SyncAction;
use Quant\Sync\QueueStatus;
use wpdb;

#[CoversClass(DatabaseQueue::class)]
final class DatabaseQueueTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private wpdb $wpdb;
    private DatabaseQueue $queue;
    private string $tableName = 'wp_quant_sync_queue';

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = Mockery::mock(wpdb::class);
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->rows_affected = 0;

        $this->queue = new DatabaseQueue($this->wpdb);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_pushes_a_single_job_to_the_database(): void
    {
        $job = new SyncJob(
            type: SyncType::Post,
            objectId: '123',
            action: SyncAction::Publish
        );

        $this->wpdb
            ->shouldReceive('insert')
            ->once()
            ->with(
                $this->tableName,
                Mockery::on(function (array $data) use ($job): bool {
                    return $data['job_id'] === $job->id
                        && $data['type'] === 'post'
                        && $data['object_id'] === '123'
                        && $data['action'] === 'publish'
                        && $data['status'] === 'pending'
                        && $data['attempts'] === 0
                        && isset($data['created_at']);
                }),
                ['%s', '%s', '%s', '%s', '%s', '%d', '%s']
            );

        $this->queue->push($job);
    }

    #[Test]
    public function it_pushes_batch_of_jobs_efficiently(): void
    {
        $job1 = new SyncJob(SyncType::Post, '1', SyncAction::Publish);
        $job2 = new SyncJob(SyncType::Page, '2', SyncAction::Unpublish);
        $job3 = new SyncJob(SyncType::Media, '3', SyncAction::Purge);

        $this->wpdb
            ->shouldReceive('prepare')
            ->once()
            ->with(
                Mockery::on(function (string $sql): bool {
                    return str_contains($sql, 'INSERT INTO')
                        && str_contains($sql, 'VALUES')
                        && substr_count($sql, '(%s, %s, %s, %s, %s, %d, %s)') === 3;
                }),
                Mockery::type('array')
            )
            ->andReturn('prepared_sql');

        $this->wpdb
            ->shouldReceive('query')
            ->once()
            ->with('prepared_sql');

        $this->queue->pushBatch([$job1, $job2, $job3]);
    }

    #[Test]
    public function it_handles_empty_batch(): void
    {
        $this->wpdb->shouldNotReceive('prepare');
        $this->wpdb->shouldNotReceive('query');

        $this->queue->pushBatch([]);
    }

    #[Test]
    public function it_fetches_pending_jobs_and_marks_as_processing(): void
    {
        $rows = [
            [
                'job_id' => 'job-1',
                'type' => 'post',
                'object_id' => '123',
                'action' => 'publish',
                'created_at' => '2024-01-15 10:00:00',
            ],
            [
                'job_id' => 'job-2',
                'type' => 'page',
                'object_id' => '456',
                'action' => 'unpublish',
                'created_at' => '2024-01-15 10:01:00',
            ],
        ];

        // First prepare call for SELECT query
        $this->wpdb
            ->shouldReceive('prepare')
            ->once()
            ->with(
                Mockery::on(fn(string $sql): bool => str_contains($sql, 'SELECT') && str_contains($sql, 'status = %s')),
                'pending',
                50
            )
            ->andReturn('select_sql');

        $this->wpdb
            ->shouldReceive('get_results')
            ->once()
            ->with('select_sql', ARRAY_A)
            ->andReturn($rows);

        // Second prepare call for UPDATE query
        $this->wpdb
            ->shouldReceive('prepare')
            ->once()
            ->with(
                Mockery::on(fn(string $sql): bool => str_contains($sql, 'UPDATE') && str_contains($sql, 'status')),
                Mockery::type('array')
            )
            ->andReturn('update_sql');

        $this->wpdb
            ->shouldReceive('query')
            ->once()
            ->with('update_sql');

        $jobs = $this->queue->fetchPending(50);

        $this->assertCount(2, $jobs);
        $this->assertInstanceOf(SyncJob::class, $jobs[0]);
        $this->assertSame('job-1', $jobs[0]->id);
        $this->assertSame(SyncType::Post, $jobs[0]->type);
        $this->assertSame('123', $jobs[0]->objectId);
        $this->assertSame(SyncAction::Publish, $jobs[0]->action);

        $this->assertSame('job-2', $jobs[1]->id);
        $this->assertSame(SyncType::Page, $jobs[1]->type);
    }

    #[Test]
    public function it_returns_empty_array_when_no_pending_jobs(): void
    {
        $this->wpdb
            ->shouldReceive('prepare')
            ->once()
            ->andReturn('select_sql');

        $this->wpdb
            ->shouldReceive('get_results')
            ->once()
            ->with('select_sql', ARRAY_A)
            ->andReturn([]);

        $jobs = $this->queue->fetchPending();

        $this->assertSame([], $jobs);
    }

    #[Test]
    public function it_marks_job_as_completed(): void
    {
        $this->wpdb
            ->shouldReceive('update')
            ->once()
            ->with(
                $this->tableName,
                Mockery::on(function (array $data): bool {
                    return $data['status'] === 'completed'
                        && isset($data['processed_at']);
                }),
                ['job_id' => 'job-123'],
                ['%s', '%s'],
                ['%s']
            );

        $this->queue->markCompleted('job-123');
    }

    #[Test]
    public function it_marks_job_as_failed_with_error_message(): void
    {
        $this->wpdb
            ->shouldReceive('prepare')
            ->once()
            ->with(
                Mockery::on(fn(string $sql): bool => str_contains($sql, 'UPDATE')
                    && str_contains($sql, 'error_message')
                    && str_contains($sql, 'attempts = attempts + 1')),
                'failed',
                'Connection timeout',
                Mockery::type('string'),
                'job-456'
            )
            ->andReturn('update_sql');

        $this->wpdb
            ->shouldReceive('query')
            ->once()
            ->with('update_sql');

        $this->queue->markFailed('job-456', 'Connection timeout');
    }

    #[Test]
    public function it_returns_queue_status_with_counts(): void
    {
        $results = [
            ['status' => 'pending', 'count' => 10],
            ['status' => 'processing', 'count' => 5],
            ['status' => 'failed', 'count' => 2],
            ['status' => 'completed', 'count' => 100],
        ];

        $this->wpdb
            ->shouldReceive('get_results')
            ->once()
            ->with(
                Mockery::on(fn(string $sql): bool => str_contains($sql, 'SELECT status, COUNT(*)')),
                ARRAY_A
            )
            ->andReturn($results);

        $status = $this->queue->getStatus();

        $this->assertInstanceOf(QueueStatus::class, $status);
        $this->assertSame(10, $status->pending);
        $this->assertSame(5, $status->processing);
        $this->assertSame(2, $status->failed);
        $this->assertSame(117, $status->total);
    }

    #[Test]
    public function it_returns_zero_counts_when_queue_is_empty(): void
    {
        $this->wpdb
            ->shouldReceive('get_results')
            ->once()
            ->andReturn([]);

        $status = $this->queue->getStatus();

        $this->assertSame(0, $status->pending);
        $this->assertSame(0, $status->processing);
        $this->assertSame(0, $status->failed);
        $this->assertSame(0, $status->total);
    }

    #[Test]
    public function it_clears_pending_jobs(): void
    {
        $this->wpdb
            ->shouldReceive('delete')
            ->once()
            ->with(
                $this->tableName,
                ['status' => 'pending'],
                ['%s']
            );

        $this->queue->clear();
    }

    #[Test]
    public function it_retries_failed_jobs_and_returns_count(): void
    {
        $this->wpdb
            ->shouldReceive('prepare')
            ->once()
            ->with(
                Mockery::on(fn(string $sql): bool => str_contains($sql, 'UPDATE')
                    && str_contains($sql, "status = %s")
                    && str_contains($sql, "WHERE status = %s")),
                'pending',
                'failed'
            )
            ->andReturn('update_sql');

        $this->wpdb
            ->shouldReceive('query')
            ->once()
            ->with('update_sql');

        $this->wpdb->rows_affected = 3;

        $count = $this->queue->retry();

        $this->assertSame(3, $count);
    }

    #[Test]
    public function it_returns_zero_when_no_failed_jobs_to_retry(): void
    {
        $this->wpdb
            ->shouldReceive('prepare')
            ->once()
            ->andReturn('update_sql');

        $this->wpdb
            ->shouldReceive('query')
            ->once();

        $this->wpdb->rows_affected = 0;

        $count = $this->queue->retry();

        $this->assertSame(0, $count);
    }

    #[Test]
    public function it_respects_limit_when_fetching_pending_jobs(): void
    {
        $this->wpdb
            ->shouldReceive('prepare')
            ->once()
            ->with(
                Mockery::on(fn(string $sql): bool => str_contains($sql, 'LIMIT %d')),
                'pending',
                10 // The limit
            )
            ->andReturn('select_sql');

        $this->wpdb
            ->shouldReceive('get_results')
            ->once()
            ->andReturn([]);

        $this->queue->fetchPending(10);
    }
}
