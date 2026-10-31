<?php

declare(strict_types=1);

namespace Quant\Core;

use Quant\Sync\JobProcessor;
use Quant\Sync\Seeder;
use Quant\Sync\SyncQueueInterface;

/**
 * WP-Cron integration: drains the queue in the background and runs the
 * optional scheduled sync.
 */
class Scheduler
{
    public const PROCESS_HOOK = 'quant_process_queue';
    public const SCHEDULED_SYNC_HOOK = 'quant_scheduled_sync';
    public const INTERVAL = 'quant_every_minute';

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
        $next = wp_next_scheduled(self::PROCESS_HOOK);
        if ($next && $next <= time() + 5) {
            return;
        }

        wp_schedule_single_event(time(), self::PROCESS_HOOK);
    }

    public function processQueue(): void
    {
        if (!$this->settings->hasValidCredentials()) {
            return;
        }

        /** This filter is documented in v2.0.0; it sets how many jobs one cron run processes. */
        $limit = (int) apply_filters('quant_cron_batch_size', 25);
        /** This filter is documented in v2.0.0; it sets how many seconds one cron run may spend. */
        $budget = (int) apply_filters('quant_cron_time_budget', 40);

        $result = $this->processor->run($limit, $budget);

        if ($result->processed + $result->failed >= $limit && $this->queue->getStatus()->pending > 0) {
            wp_schedule_single_event(time() + 5, self::PROCESS_HOOK);
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
            $this->queue->push(new \Quant\Sync\SyncJob(\Quant\Sync\SyncType::CustomRoute, '/' . ltrim($route, '/'), \Quant\Sync\SyncAction::Publish));
        }

        $this->ensureProcessing();
    }

    private function validInterval(string $interval): string
    {
        return array_key_exists($interval, wp_get_schedules()) ? $interval : 'daily';
    }
}
