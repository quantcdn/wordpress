<?php

declare(strict_types=1);

namespace Quant\Tests\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Core\Scheduler;
use Quant\Core\Settings;
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
    public function it_runs_processing_soon_after_jobs_are_queued_unless_a_run_is_imminent(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(time() + 600);
        Functions\expect('wp_schedule_single_event')->once()->with(Mockery::type('int'), Scheduler::PROCESS_HOOK);
        $this->scheduler()->ensureProcessing();

        Functions\when('wp_next_scheduled')->justReturn(time() + 2);
        Functions\expect('wp_schedule_single_event')->never();
        $this->scheduler()->ensureProcessing();
    }

    #[Test]
    public function it_processes_a_batch_and_queues_another_run_when_work_remains(): void
    {
        $this->processor->shouldReceive('run')->once()->with(25, 40)->andReturn(new SyncResult(25, 0));
        $this->queue->shouldReceive('getStatus')->once()->andReturn(new QueueStatus(5, 0, 0, 30));
        Functions\expect('wp_schedule_single_event')->once()->with(Mockery::type('int'), Scheduler::PROCESS_HOOK);

        $this->scheduler()->processQueue();
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
        Functions\expect('wp_schedule_single_event')->once();

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
