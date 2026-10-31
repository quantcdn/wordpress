<?php

declare(strict_types=1);

namespace Quant\Sync;

interface SyncQueueInterface
{
    /**
     * Queue a job. A job that duplicates a pending job (same type, object and
     * action) is not queued again.
     */
    public function push(SyncJob $job): void;

    /**
     * @param array<SyncJob> $jobs
     */
    public function pushBatch(array $jobs): void;

    /**
     * Fetch pending jobs and mark them as processing.
     *
     * @return array<SyncJob>
     */
    public function fetchPending(int $limit = 50): array;

    /**
     * Mark a job as completed.
     */
    public function markCompleted(string $jobId): void;

    /**
     * Mark a job as failed with an error message.
     */
    public function markFailed(string $jobId, string $error): void;

    public function getStatus(): QueueStatus;

    /**
     * Remove pending jobs.
     */
    public function clear(): void;

    /**
     * Reset failed jobs to pending status.
     *
     * @return int Count of jobs queued for retry
     */
    public function retry(): int;

    /**
     * Reset jobs stuck in processing for longer than $olderThanSeconds.
     *
     * @return int Count of jobs reset
     */
    public function recoverStale(int $olderThanSeconds = 600): int;

    /**
     * Put fetched-but-unprocessed jobs back to pending.
     *
     * @param array<string> $jobIds
     */
    public function release(array $jobIds): void;

    /**
     * True when a job with the same type, object and action was queued or
     * completed within the last $withinSeconds.
     */
    public function hasRecentJob(SyncType $type, string $objectId, SyncAction $action, int $withinSeconds): bool;

    /**
     * Remove pending jobs of the given types (all types when empty).
     *
     * @param array<SyncType> $types
     * @return int Rows removed
     */
    public function clearTypes(array $types): int;
}
