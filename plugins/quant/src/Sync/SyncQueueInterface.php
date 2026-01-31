<?php

declare(strict_types=1);

namespace Quant\Sync;

interface SyncQueueInterface
{
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

    public function clear(): void;

    /**
     * Reset failed jobs to pending status.
     *
     * @return int Count of jobs queued for retry
     */
    public function retry(): int;
}
