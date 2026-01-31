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

    public function process(int $limit = 50): SyncResult;

    public function getStatus(): QueueStatus;

    public function clear(): void;

    /**
     * Returns count of jobs queued for retry.
     */
    public function retry(): int;
}
