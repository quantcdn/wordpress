<?php

declare(strict_types=1);

namespace Quant\Core;

use Quant\Sync\DatabaseQueue;
use Quant\Sync\JobProcessor;
use Quant\Sync\Seeder;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;

/**
 * WP-Cron integration: drains the queue in the background and runs the
 * optional scheduled sync.
 */
class Scheduler
{
    public const PROCESS_HOOK = 'quant_process_queue';
    /** One-off run scheduled right after jobs are queued (a distinct hook, so WP-Cron does not treat it as a duplicate of the recurring event). */
    public const PROCESS_SOON_HOOK = 'quant_process_queue_soon';
    private const MAINTENANCE_TRANSIENT = 'quant_queue_maintenance';

    private bool $spawnRequested = false;
    public const SCHEDULED_SYNC_HOOK = 'quant_scheduled_sync';
    public const INTERVAL = 'quant_every_minute';

    /** Unix time of the last scheduled queue run (processQueue). */
    public const OPTION_LAST_RUN = 'quant_last_queue_run';

    /** A run within this many seconds means something runs the queue in the background. */
    public const RECENT_RUN = 900;

    /**
     * Whether the queue (and its retryDue backoff) runs in the background:
     * WP-Cron is enabled, or a scheduled run happened recently. The second
     * case covers DISABLE_WP_CRON with a system cron that calls wp-cron.php.
     */
    public static function backgroundProcessingEnabled(): bool
    {
        $cronDisabled = self::wpCronDisabled();

        return self::isBackgroundActive($cronDisabled, $cronDisabled ? (int) get_option(self::OPTION_LAST_RUN, 0) : 0, time());
    }

    public static function isBackgroundActive(bool $cronDisabled, int $lastRun, int $now): bool
    {
        return !$cronDisabled || ($lastRun > 0 && $now - $lastRun <= self::RECENT_RUN);
    }

    public function __construct(
        private readonly Settings $settings,
        private readonly SyncQueueInterface $queue,
        private readonly JobProcessor $processor,
        private readonly Seeder $seeder
    ) {
    }

    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'addSchedules']);
        add_action(self::PROCESS_HOOK, [$this, 'processQueue']);
        add_action(self::PROCESS_SOON_HOOK, [$this, 'processQueue']);
        add_action(self::SCHEDULED_SYNC_HOOK, [$this, 'runScheduledSync']);
        add_action('quant_jobs_queued', [$this, 'ensureProcessing']);
        add_action('init', [$this, 'ensureSchedules'], 20);
    }

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function addSchedules(array $schedules): array
    {
        $schedules[self::INTERVAL] = [
            'interval' => 60,
            'display' => __('Every minute (QuantCDN)', 'quant'),
        ];

        return $schedules;
    }

    /**
     * Make sure the recurring events match the settings.
     */
    public function ensureSchedules(): void
    {
        if (!wp_next_scheduled(self::PROCESS_HOOK)) {
            wp_schedule_event(time() + 60, self::INTERVAL, self::PROCESS_HOOK);
        }

        $schedule = $this->settings->getSchedule();
        $next = wp_next_scheduled(self::SCHEDULED_SYNC_HOOK);

        if (empty($schedule['enabled']) || !$this->settings->isStaticMode()) {
            if ($next) {
                wp_clear_scheduled_hook(self::SCHEDULED_SYNC_HOOK);
            }
            return;
        }

        $current = wp_get_schedule(self::SCHEDULED_SYNC_HOOK);
        if (!$next || $current !== $schedule['interval']) {
            wp_clear_scheduled_hook(self::SCHEDULED_SYNC_HOOK);
            wp_schedule_event(time() + 300, $this->validInterval($schedule['interval']), self::SCHEDULED_SYNC_HOOK);
        }
    }

    /**
     * Run a processing pass as soon as possible after jobs were queued.
     */
    public function ensureProcessing(): void
    {
        if (!wp_next_scheduled(self::PROCESS_SOON_HOOK)) {
            wp_schedule_single_event(time(), self::PROCESS_SOON_HOOK);
        }

        if (!$this->spawnRequested && self::shouldSpawnCron(defined('WP_CLI') && WP_CLI, wp_doing_cron(), self::wpCronDisabled())) {
            $this->spawnRequested = true;
            add_action('shutdown', [$this, 'spawnCron'], 100);
        }
    }

    /**
     * True when the site owner turned WP-Cron off with DISABLE_WP_CRON.
     */
    public static function wpCronDisabled(): bool
    {
        return defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    }

    /**
     * Whether to start WP-Cron at the end of this request.
     *
     * - Not from WP-CLI: there the site URL is the stored (often public,
     *   static) domain, and spawn_cron() would only set a lock that delays
     *   the next run.
     * - Not inside a cron run.
     * - Not when DISABLE_WP_CRON is set. The site owner turned off the
     *   request-time cron; a system cron that calls wp-cron.php (or the
     *   admin's progress card) runs the queue instead. A recent system cron
     *   run makes backgroundProcessingEnabled() true, but it is not consent
     *   to start WP-Cron from a web request.
     */
    public static function shouldSpawnCron(bool $cli, bool $doingCron, bool $cronDisabled): bool
    {
        return !$cli && !$doingCron && !$cronDisabled;
    }

    /**
     * Start WP-Cron in the background so queued jobs run without waiting for the next visit.
     */
    public function spawnCron(): void
    {
        spawn_cron();
    }

    public function processQueue(): void
    {
        if (!$this->settings->hasValidCredentials()) {
            return;
        }

        update_option(self::OPTION_LAST_RUN, time(), false);
        $this->maintain();

        /** This filter is documented in v2.0.0; it sets how many jobs one cron run processes. */
        $limit = (int) apply_filters('quant_cron_batch_size', 25);
        /** This filter is documented in v2.0.0; it sets how many seconds one cron run may spend. */
        $budget = (int) apply_filters('quant_cron_time_budget', 40);

        $result = $this->processor->run($limit, $budget);

        if ($result->processed + $result->failed >= $limit && $this->queue->getStatus()->pending > 0) {
            wp_schedule_single_event(time(), self::PROCESS_SOON_HOOK);
        }
    }

    /**
     * Retry failed jobs with backoff, and prune old rows once a day.
     */
    private function maintain(): void
    {
        if (!$this->queue instanceof DatabaseQueue) {
            return;
        }

        $this->queue->retryDue();

        if (get_transient(self::MAINTENANCE_TRANSIENT) === false) {
            set_transient(self::MAINTENANCE_TRANSIENT, 1, DAY_IN_SECONDS);
            $this->queue->pruneCompleted();
        }
    }

    public function runScheduledSync(): void
    {
        $schedule = $this->settings->getSchedule();
        if (empty($schedule['enabled']) || !$this->settings->isStaticMode()) {
            return;
        }

        $types = $schedule['types'];
        $this->seeder->seed($types);

        foreach ($schedule['custom_routes'] as $route) {
            $this->queue->push(new SyncJob(SyncType::CustomRoute, '/' . ltrim($route, '/'), SyncAction::Publish));
        }

        $this->ensureProcessing();
    }

    private function validInterval(string $interval): string
    {
        return array_key_exists($interval, wp_get_schedules()) ? $interval : 'daily';
    }
}
