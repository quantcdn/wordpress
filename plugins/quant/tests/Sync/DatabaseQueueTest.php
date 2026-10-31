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
use Quant\Sync\DatabaseQueue;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use wpdb;

#[CoversClass(DatabaseQueue::class)]
final class DatabaseQueueTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private wpdb&Mockery\MockInterface $wpdb;
    private DatabaseQueue $queue;
    private string $table = 'wp_quant_sync_queue';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->wpdb = Mockery::mock(wpdb::class);
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->rows_affected = 0;
        // prepare() returns the SQL with placeholders left in place; tests inspect the SQL text.
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(static fn(string $sql, ...$args): string => $sql)->byDefault();

        $this->queue = new DatabaseQueue($this->wpdb);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_pushes_a_job_with_its_meta_and_announces_it(): void
    {
        $job = new SyncJob(SyncType::Taxonomy, '12', SyncAction::Publish, ['taxonomy' => 'post_tag', 'page' => 2]);

        $this->wpdb->shouldReceive('get_var')->once()
            ->with(Mockery::on(static fn(string $sql): bool => str_contains($sql, 'COUNT(*)') && str_contains($sql, 'status = %s')))
            ->andReturn('0');

        $this->wpdb->shouldReceive('insert')->once()->with(
            $this->table,
            Mockery::on(static function (array $data) use ($job): bool {
                return $data['job_id'] === $job->id
                    && $data['type'] === 'taxonomy'
                    && $data['object_id'] === '12'
                    && $data['action'] === 'publish'
                    && $data['status'] === 'pending'
                    && $data['attempts'] === 0
                    && $data['meta'] === '{"taxonomy":"post_tag","page":2}';
            }),
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        Actions\expectDone('quant_jobs_queued')->once();

        $this->queue->push($job);
    }

    #[Test]
    public function it_stores_null_meta_for_a_job_without_meta(): void
    {
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('0');
        $this->wpdb->shouldReceive('insert')->once()->with(
            $this->table,
            Mockery::on(static fn(array $data): bool => $data['meta'] === null),
            Mockery::type('array')
        );

        $this->queue->push(new SyncJob(SyncType::Post, '1', SyncAction::Publish));
    }

    #[Test]
    public function it_skips_a_push_when_an_identical_job_is_pending(): void
    {
        $this->wpdb->shouldReceive('get_var')->once()->andReturn('1');
        $this->wpdb->shouldNotReceive('insert');
        Actions\expectDone('quant_jobs_queued')->never();

        $this->queue->push(new SyncJob(SyncType::Post, '1', SyncAction::Publish));
    }

    #[Test]
    public function it_pushes_a_batch_in_one_insert_without_duplicates(): void
    {
        $jobs = [
            new SyncJob(SyncType::Post, '1', SyncAction::Publish),
            new SyncJob(SyncType::Post, '1', SyncAction::Publish), // duplicate inside the batch
            new SyncJob(SyncType::Page, '2', SyncAction::Unpublish, ['url' => '/about']),
            new SyncJob(SyncType::Media, '3', SyncAction::Publish), // already pending in the table
        ];

        $this->wpdb->shouldReceive('get_results')->once()
            ->with(Mockery::on(static fn(string $sql): bool => str_contains($sql, 'SELECT type, object_id, action')), ARRAY_A)
            ->andReturn([['type' => 'media', 'object_id' => '3', 'action' => 'publish']]);

        $this->wpdb->shouldReceive('query')->once()->with(Mockery::on(static function (string $sql): bool {
            return str_contains($sql, 'INSERT INTO wp_quant_sync_queue')
                && str_contains($sql, 'meta)')
                && substr_count($sql, '(%s, %s, %s, %s, %s, %d, %s, %s)') === 2;
        }));

        Actions\expectDone('quant_jobs_queued')->once();

        $this->queue->pushBatch($jobs);
    }

    #[Test]
    public function it_handles_an_empty_batch(): void
    {
        $this->wpdb->shouldNotReceive('query');
        $this->wpdb->shouldNotReceive('get_results');

        $this->queue->pushBatch([]);
    }

    #[Test]
    public function it_claims_pending_jobs_atomically_and_returns_them_with_meta(): void
    {
        $rows = [
            ['job_id' => 'job-1', 'type' => 'taxonomy', 'object_id' => '7', 'action' => 'publish', 'created_at' => '2026-01-15 10:00:00', 'meta' => '{"taxonomy":"category","page":1}'],
            ['job_id' => 'job-2', 'type' => 'page', 'object_id' => '9', 'action' => 'unpublish', 'created_at' => '2026-01-15 10:01:00', 'meta' => null],
        ];

        $this->wpdb->shouldReceive('query')->once()
            ->with(Mockery::on(static fn(string $sql): bool => str_contains($sql, 'SET status = %s, claim = %s') && str_contains($sql, 'ORDER BY id ASC') && str_contains($sql, 'LIMIT %d')))
            ->andReturn(2);
        $this->wpdb->shouldReceive('get_results')->once()
            ->with(Mockery::on(static fn(string $sql): bool => str_contains($sql, 'WHERE claim = %s AND status = %s')), ARRAY_A)
            ->andReturn($rows);

        $jobs = $this->queue->fetchPending(10);

        $this->assertCount(2, $jobs);
        $this->assertSame(SyncType::Taxonomy, $jobs[0]->type);
        $this->assertSame(['taxonomy' => 'category', 'page' => 1], $jobs[0]->meta);
        $this->assertSame([], $jobs[1]->meta);
        $this->assertSame(SyncAction::Unpublish, $jobs[1]->action);
    }

    #[Test]
    public function it_returns_an_empty_array_when_nothing_could_be_claimed(): void
    {
        $this->wpdb->shouldReceive('query')->once()->andReturn(0);
        $this->wpdb->shouldNotReceive('get_results');

        $this->assertSame([], $this->queue->fetchPending());
    }

    #[Test]
    public function it_marks_a_job_completed_and_clears_its_error(): void
    {
        $this->wpdb->shouldReceive('update')->once()->with(
            $this->table,
            Mockery::on(static fn(array $data): bool => $data['status'] === 'completed' && $data['error_message'] === null && isset($data['processed_at'])),
            ['job_id' => 'job-1'],
            ['%s', '%s', '%s'],
            ['%s']
        );

        $this->queue->markCompleted('job-1');
    }

    #[Test]
    public function it_marks_a_job_failed_and_increments_attempts(): void
    {
        $this->wpdb->shouldReceive('prepare')->once()->with(
            Mockery::on(static fn(string $sql): bool => str_contains($sql, 'attempts = attempts + 1') && str_contains($sql, 'error_message')),
            'failed',
            'Boom',
            Mockery::type('string'),
            'job-1'
        )->andReturn('update_sql');
        $this->wpdb->shouldReceive('query')->once()->with('update_sql');

        $this->queue->markFailed('job-1', 'Boom');
    }

    #[Test]
    public function it_truncates_long_error_messages(): void
    {
        $this->wpdb->shouldReceive('prepare')->once()->with(
            Mockery::type('string'),
            'failed',
            Mockery::on(static fn(string $message): bool => mb_strlen($message) === 2000),
            Mockery::type('string'),
            'job-1'
        )->andReturn('sql');
        $this->wpdb->shouldReceive('query')->once();

        $this->queue->markFailed('job-1', str_repeat('x', 5000));
    }

    #[Test]
    public function it_reports_queue_status_counts(): void
    {
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([
            ['status' => 'pending', 'count' => '4'],
            ['status' => 'processing', 'count' => '1'],
            ['status' => 'failed', 'count' => '2'],
            ['status' => 'completed', 'count' => '30'],
        ]);

        $status = $this->queue->getStatus();

        $this->assertSame(4, $status->pending);
        $this->assertSame(1, $status->processing);
        $this->assertSame(2, $status->failed);
        $this->assertSame(37, $status->total);
    }

    #[Test]
    public function it_reports_zero_counts_for_an_empty_table(): void
    {
        $this->wpdb->shouldReceive('get_results')->once()->andReturn(null);

        $status = $this->queue->getStatus();

        $this->assertSame(0, $status->total);
        $this->assertSame(0, $status->pending);
    }

    #[Test]
    public function it_clears_pending_jobs_only(): void
    {
        $this->wpdb->shouldReceive('delete')->once()->with($this->table, ['status' => 'pending'], ['%s']);

        $this->queue->clear();
    }

    #[Test]
    public function it_retries_failed_jobs_and_returns_the_count(): void
    {
        $this->wpdb->shouldReceive('query')->once()->with(Mockery::on(static fn(string $sql): bool => str_contains($sql, 'error_message = NULL')));
        $this->wpdb->rows_affected = 3;

        $this->assertSame(3, $this->queue->retry());
    }

    #[Test]
    public function it_recovers_stale_processing_jobs(): void
    {
        $this->wpdb->shouldReceive('prepare')->once()->with(
            Mockery::on(static fn(string $sql): bool => str_contains($sql, 'claim = NULL') && str_contains($sql, 'processed_at IS NULL OR processed_at < %s')),
            'pending',
            'processing',
            Mockery::type('string')
        )->andReturn('sql');
        $this->wpdb->shouldReceive('query')->once()->with('sql');
        $this->wpdb->rows_affected = 2;

        $this->assertSame(2, $this->queue->recoverStale(600));
    }

    #[Test]
    public function it_releases_jobs_back_to_pending(): void
    {
        $this->wpdb->shouldReceive('prepare')->once()->with(
            Mockery::on(static fn(string $sql): bool => str_contains($sql, 'job_id IN (%s, %s)')),
            ['pending', 'a', 'b']
        )->andReturn('sql');
        $this->wpdb->shouldReceive('query')->once()->with('sql');

        $this->queue->release(['a', 'b']);
    }

    #[Test]
    public function it_does_nothing_when_releasing_no_jobs(): void
    {
        $this->wpdb->shouldNotReceive('query');

        $this->queue->release([]);
    }

    #[Test]
    public function it_detects_a_recent_job(): void
    {
        $this->wpdb->shouldReceive('prepare')->once()->with(
            Mockery::on(static fn(string $sql): bool => str_contains($sql, 'status IN (%s, %s, %s) AND created_at >= %s')),
            'route',
            '/page/2',
            'publish',
            'pending',
            'processing',
            'completed',
            Mockery::type('string')
        )->andReturn('sql');
        $this->wpdb->shouldReceive('get_var')->once()->with('sql')->andReturn('1');

        $this->assertTrue($this->queue->hasRecentJob(SyncType::Route, '/page/2', SyncAction::Publish, 600));
    }

    #[Test]
    public function it_clears_pending_jobs_of_given_types(): void
    {
        $this->wpdb->shouldReceive('prepare')->once()->with(
            Mockery::on(static fn(string $sql): bool => str_contains($sql, 'DELETE FROM') && str_contains($sql, 'type IN (%s, %s)')),
            ['pending', 'post', 'page']
        )->andReturn('sql');
        $this->wpdb->shouldReceive('query')->once()->with('sql');
        $this->wpdb->rows_affected = 5;

        $this->assertSame(5, $this->queue->clearTypes([SyncType::Post, SyncType::Page]));
    }

    #[Test]
    public function it_clears_everything_pending_when_no_types_are_given(): void
    {
        $this->wpdb->shouldReceive('delete')->once()->with($this->table, ['status' => 'pending'], ['%s']);
        $this->wpdb->rows_affected = 7;

        $this->assertSame(7, $this->queue->clearTypes([]));
    }

    #[Test]
    public function it_lists_recent_errors(): void
    {
        $rows = [['type' => 'post', 'object_id' => '1', 'error_message' => 'HTTP 500', 'processed_at' => '2026-01-01 00:00:00']];
        $this->wpdb->shouldReceive('get_results')->once()
            ->with(Mockery::on(static fn(string $sql): bool => str_contains($sql, 'error_message IS NOT NULL')), ARRAY_A)
            ->andReturn($rows);

        $this->assertSame($rows, $this->queue->recentErrors(5));
    }

    #[Test]
    public function it_returns_the_latest_status_per_object(): void
    {
        $this->wpdb->shouldReceive('get_results')->once()
            ->with(Mockery::on(static fn(string $sql): bool => str_contains($sql, 'ORDER BY id DESC')), ARRAY_A)
            ->andReturn([
                ['object_id' => '1', 'status' => 'pending', 'processed_at' => null],
                ['object_id' => '1', 'status' => 'completed', 'processed_at' => '2026-01-01 10:00:00'],
                ['object_id' => '2', 'status' => 'failed', 'processed_at' => '2026-01-01 11:00:00'],
            ]);

        $statuses = $this->queue->latestStatuses(SyncType::Post, [1, 2, 3]);

        $this->assertSame('pending', $statuses['1']['status']);
        $this->assertSame('2026-01-01 10:00:00', $statuses['1']['processed_at']);
        $this->assertSame('failed', $statuses['2']['status']);
        $this->assertArrayNotHasKey('3', $statuses);
    }

    #[Test]
    public function it_returns_no_statuses_for_no_ids(): void
    {
        $this->wpdb->shouldNotReceive('get_results');

        $this->assertSame([], $this->queue->latestStatuses(SyncType::Post, []));
    }

    #[Test]
    public function it_prunes_old_completed_jobs(): void
    {
        $this->wpdb->shouldReceive('query')->once()->with(Mockery::on(static fn(string $sql): bool => str_contains($sql, 'DELETE FROM') && str_contains($sql, 'processed_at < %s')));
        $this->wpdb->rows_affected = 11;

        $this->assertSame(11, $this->queue->pruneCompleted());
    }
}
