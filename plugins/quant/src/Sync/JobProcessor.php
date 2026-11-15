<?php

declare(strict_types=1);

namespace Quant\Sync;

use Closure;
use Quant\Api\TransportError;
use Quant\Core\Scheduler;
use Quant\Core\Settings;
use Quant\Sync\Exception\QuantApiException;
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

    /** @var Closure(): bool */
    private readonly Closure $backgroundProcessing;

    /**
     * @param array<HandlerInterface> $handlers
     * @param (Closure(): bool)|null $backgroundProcessing Whether WP-Cron retries failed jobs (injected in tests).
     */
    public function __construct(
        private readonly SyncQueueInterface $queue,
        private readonly Settings $settings,
        array $handlers,
        ?Closure $backgroundProcessing = null
    ) {
        $this->handlers = $handlers;
        $this->backgroundProcessing = $backgroundProcessing ?? Scheduler::backgroundProcessingEnabled(...);
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

        throw new RuntimeException(esc_html("No handler for {$job->type->value} {$job->action->value}"));
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
            // Messages are HTML-escaped when thrown; store them as plain text.
            $message = $e->getMessage() !== '' ? html_entity_decode($e->getMessage(), ENT_QUOTES | ENT_HTML5) : get_class($e);
            $detail = $e instanceof QuantApiException ? $e->detail : '';
            $message = $this->withNextStep($message, $job, $e);
            $this->queue->markFailed($job->id, $message, $detail);
            $errors[] = "{$job->type->value} {$job->objectId}: {$message}";

            /** This action is documented in v2.0.0; it fires when a sync job fails. */
            do_action('quant_sync_job_failed', $job, $e);

            return false;
        }
    }

    /**
     * Tell the editor what happens next after a transient failure, and only
     * what is true: DatabaseQueue::retryDue requeues a failed job while it has
     * attempts left, but only when WP-Cron runs the queue.
     */
    private function withNextStep(string $message, SyncJob $job, Throwable $e): string
    {
        if (!$e instanceof QuantApiException || !$e->transient) {
            return $message;
        }

        $attemptsLeft = $job->attempts + 1 < DatabaseQueue::MAX_ATTEMPTS;
        $next = $attemptsLeft && ($this->backgroundProcessing)() ? TransportError::NEXT_AUTOMATIC : TransportError::NEXT_MANUAL;

        return "{$message} {$next}";
    }
}
