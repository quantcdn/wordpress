<?php

declare(strict_types=1);

namespace Quant\Tests\Core;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Core\Scheduler;
use Quant\Core\Settings;
use Quant\Sync\DatabaseQueue;
use Quant\Sync\JobProcessor;
use Quant\Sync\QueueStatus;
use Quant\Sync\Seeder;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncResult;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(Scheduler::class)]
final class SchedulerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private SyncQueueInterface&Mockery\MockInterface $queue;
    private JobProcessor&Mockery\MockInterface $processor;
    private Seeder&Mockery\MockInterface $seeder;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();
        Functions\when('wp_get_schedules')->justReturn(['hourly' => [], 'daily' => [], 'weekly' => []]);

        $this->queue = Mockery::mock(SyncQueueInterface::class);
        $this->processor = Mockery::mock(JobProcessor::class);
        $this->seeder = Mockery::mock(Seeder::class);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function scheduler(array $schedule = [], string $mode = Settings::MODE_STATIC): Scheduler
    {
        $settings = $this->mockSettings(mode: $mode);
        $settings->shouldReceive('getSchedule')->andReturn(array_merge(Settings::SCHEDULE_DEFAULTS, $schedule));

        return new Scheduler($settings, $this->queue, $this->processor, $this->seeder);
    }

    #[Test]
    public function it_adds_a_one_minute_schedule(): void
    {
        $schedules = $this->scheduler()->addSchedules(['hourly' => ['interval' => 3600, 'display' => 'Hourly']]);

        $this->assertSame(60, $schedules[Scheduler::INTERVAL]['interval']);
        $this->assertArrayHasKey('hourly', $schedules);
    }

    #[Test]
    public function it_schedules_queue_processing_and_clears_a_disabled_sync(): void
    {
        Functions\when('wp_next_scheduled')->alias(static fn(string $hook) => $hook === Scheduler::SCHEDULED_SYNC_HOOK ? 123 : false);
        Functions\expect('wp_schedule_event')->once()->with(Mockery::type('int'), Scheduler::INTERVAL, Scheduler::PROCESS_HOOK);
        Functions\expect('wp_clear_scheduled_hook')->once()->with(Scheduler::SCHEDULED_SYNC_HOOK);

        $this->scheduler(['enabled' => false])->ensureSchedules();
    }

    #[Test]
    public function it_reschedules_the_sync_when_the_interval_changes(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(123);
        Functions\when('wp_get_schedule')->justReturn('daily');
        Functions\expect('wp_clear_scheduled_hook')->once()->with(Scheduler::SCHEDULED_SYNC_HOOK);
        Functions\expect('wp_schedule_event')->once()->with(Mockery::type('int'), 'hourly', Scheduler::SCHEDULED_SYNC_HOOK);

        $this->scheduler(['enabled' => true, 'interval' => 'hourly'])->ensureSchedules();
    }

    #[Test]
    public function it_falls_back_to_daily_for_an_unknown_interval(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_get_schedule')->justReturn(false);
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        $scheduled = [];
        Functions\when('wp_schedule_event')->alias(static function (int $t, string $interval, string $hook) use (&$scheduled): bool {
            $scheduled[$hook] = $interval;
            return true;
        });

        $this->scheduler(['enabled' => true, 'interval' => 'every-blue-moon'])->ensureSchedules();

        $this->assertSame('daily', $scheduled[Scheduler::SCHEDULED_SYNC_HOOK]);
    }

    #[Test]
    public function it_schedules_a_one_off_run_and_spawns_cron_once_after_jobs_are_queued(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\expect('wp_schedule_single_event')->twice()->with(Mockery::type('int'), Scheduler::PROCESS_SOON_HOOK);
        Actions\expectAdded('shutdown')->once()->with(\Mockery::type('array'), 100);

        $scheduler = $this->scheduler();
        $scheduler->ensureProcessing();
        $scheduler->ensureProcessing();
    }

    #[Test]
    public function it_does_not_schedule_a_second_one_off_run_or_spawn_inside_cron(): void
    {
        Functions\when('wp_next_scheduled')->alias(static fn(string $hook) => $hook === Scheduler::PROCESS_SOON_HOOK ? time() : false);
        Functions\when('wp_doing_cron')->justReturn(true);
        Functions\expect('wp_schedule_single_event')->never();
        Actions\expectAdded('shutdown')->never();

        $this->scheduler()->ensureProcessing();
    }

    #[Test]
    public function it_processes_a_batch_and_queues_another_run_when_work_remains(): void
    {
        $this->processor->shouldReceive('run')->once()->with(25, 40)->andReturn(new SyncResult(25, 0));
        $this->queue->shouldReceive('getStatus')->once()->andReturn(new QueueStatus(5, 0, 0, 30));
        Functions\expect('wp_schedule_single_event')->once()->with(Mockery::type('int'), Scheduler::PROCESS_SOON_HOOK);

        $this->scheduler()->processQueue();
    }

    #[Test]
    public function it_retries_due_jobs_every_run_and_prunes_once_a_day(): void
    {
        $wpdb = Mockery::mock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->rows_affected = 0;
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn(string $sql): string => $sql);
        $queries = [];
        $wpdb->shouldReceive('query')->andReturnUsing(static function (string $sql) use (&$queries): int {
            $queries[] = $sql;
            return 0;
        });
        $queue = new DatabaseQueue($wpdb);

        $this->processor->shouldReceive('run')->twice()->andReturn(new SyncResult(0, 0));

        $transient = false;
        Functions\when('get_transient')->alias(static function () use (&$transient) {
            return $transient;
        });
        Functions\expect('set_transient')->once()->with('quant_queue_maintenance', 1, Mockery::type('int'))->andReturnUsing(static function () use (&$transient): bool {
            $transient = 1;
            return true;
        });

        $settings = $this->mockSettings();
        $settings->shouldReceive('getSchedule')->andReturn(Settings::SCHEDULE_DEFAULTS);
        $scheduler = new Scheduler($settings, $queue, $this->processor, $this->seeder);
        $scheduler->processQueue();
        $scheduler->processQueue();

        $retries = array_filter($queries, static fn(string $q): bool => str_contains($q, 'attempts < %d'));
        $prunes = array_filter($queries, static fn(string $q): bool => str_contains($q, 'DELETE old FROM'));
        $this->assertCount(2, $retries);
        $this->assertCount(1, $prunes);
    }

    #[Test]
    public function it_does_not_requeue_after_a_partial_batch_or_without_credentials(): void
    {
        $this->processor->shouldReceive('run')->once()->andReturn(new SyncResult(3, 1));
        Functions\expect('wp_schedule_single_event')->never();
        $this->scheduler()->processQueue();

        $settings = $this->mockSettings(credentials: ['token' => '']);
        $this->processor->shouldNotReceive('run')->withArgs(static fn(): bool => false);
        (new Scheduler($settings, $this->queue, $this->processor, $this->seeder))->processQueue();
    }

    #[Test]
    public function it_seeds_the_scheduled_types_and_custom_routes(): void
    {
        $this->seeder->shouldReceive('seed')->once()->with(['home', 'post'])->andReturn(['queued' => 3, 'types' => []]);
        $this->queue->shouldReceive('push')->once()->with(Mockery::on(static fn(SyncJob $j): bool => $j->objectId === '/robots.txt'));
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_doing_cron')->justReturn(true);
        Functions\expect('wp_schedule_single_event')->once()->with(Mockery::type('int'), Scheduler::PROCESS_SOON_HOOK);

        $this->scheduler(['enabled' => true, 'types' => ['home', 'post'], 'custom_routes' => ['robots.txt']])->runScheduledSync();
    }

    #[Test]
    public function it_skips_the_scheduled_sync_when_disabled_or_in_cdn_only_mode(): void
    {
        $this->seeder->shouldNotReceive('seed');

        $this->scheduler(['enabled' => false])->runScheduledSync();
        $this->scheduler(['enabled' => true], Settings::MODE_CDN_ONLY)->runScheduledSync();
    }
}
