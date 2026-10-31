<?php

declare(strict_types=1);

namespace Quant\Sync;

use Quant\Core\Settings;
use Quant\Sync\Handler\HandlerInterface;
use RuntimeException;
use Throwable;

/**
 * Runs queued jobs. Used by the REST API, WP-CLI and WP-Cron alike.
 */
class JobProcessor
{
    /** @var array<HandlerInterface> */
    private array $handlers;

    /**
     * @param array<HandlerInterface> $handlers
     */
    public function __construct(
        private readonly SyncQueueInterface $queue,
        private readonly Settings $settings,
        array $handlers
    ) {
        $this->handlers = $handlers;
    }

    /**
     * Process one job. Exceptions propagate to the caller.
     */
    public function process(SyncJob $job): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($job)) {
                $handler->handle($job);
                return;
            }
        }

        throw new RuntimeException("No handler for {$job->type->value} {$job->action->value}");
    }

    /**
     * Fetch up to $limit pending jobs and process them within the time budget.
     *
     * @param int $limit  Jobs to fetch
     * @param int $timeBudget  Seconds to spend; remaining jobs are released
     */
    public function run(int $limit = 20, int $timeBudget = 25): SyncResult
    {
        if (!$this->settings->hasValidCredentials()) {
            return new SyncResult(0, 0, ['Credentials not configured']);
        }

        $this->queue->recoverStale();

        $jobs = $this->queue->fetchPending($limit);
        $started = microtime(true);
        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($jobs as $index => $job) {
            if (microtime(true) - $started > $timeBudget) {
                $this->queue->release(array_map(static fn(SyncJob $j): string => $j->id, array_slice($jobs, $index)));
                break;
            }

            if ($this->runOne($job, $errors)) {
                $processed++;
            } else {
                $failed++;
            }
        }

        return new SyncResult($processed, $failed, $errors);
    }

    /**
     * @param array<string> $errors
     */
    private function runOne(SyncJob $job, array &$errors): bool
    {
        try {
            $this->process($job);
            $this->queue->markCompleted($job->id);

            return true;
        } catch (Throwable $e) {
            $message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            $this->queue->markFailed($job->id, $message);
            $errors[] = "{$job->type->value} {$job->objectId}: {$message}";

            /** This action is documented in v2.0.0; it fires when a sync job fails. */
            do_action('quant_sync_job_failed', $job, $e);

            return false;
        }
    }
}
