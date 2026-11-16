<?php

declare(strict_types=1);

namespace Quant\Sync;

/**
 * The outcome of one processing run, read from the queue table.
 *
 * A run starts at $since (Unix time, taken on the server before the jobs were
 * queued). Its finished jobs are the completed and failed rows processed at or
 * after $since; its remaining jobs are every pending or processing row. The
 * counts do not depend on what processed the jobs: the admin's browser,
 * WP-Cron, a system cron or WP-CLI.
 */
final class QueueRun
{
    /**
     * @param array<int, string> $errors Short messages of the newest failed jobs in the run.
     */
    public function __construct(
        public readonly int $since,
        public readonly int $pending,
        public readonly int $processing,
        public readonly int $completed,
        public readonly int $failed,
        public readonly array $errors = [],
    ) {
    }

    public function remaining(): int
    {
        return $this->pending + $this->processing;
    }

    /**
     * True when no job of the run is waiting or in progress.
     */
    public function finished(): bool
    {
        return $this->remaining() === 0;
    }

    /**
     * @return array{since: int, pending: int, processing: int, completed: int, failed: int, remaining: int, total: int, finished: bool, errors: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'since' => $this->since,
            'pending' => $this->pending,
            'processing' => $this->processing,
            'completed' => $this->completed,
            'failed' => $this->failed,
            'remaining' => $this->remaining(),
            'total' => $this->completed + $this->failed + $this->remaining(),
            'finished' => $this->finished(),
            'errors' => $this->errors,
        ];
    }
}
