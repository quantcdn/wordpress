<?php

declare(strict_types=1);

namespace Quant\Sync;

use wpdb;

/**
 * Database-backed implementation of SyncQueueInterface.
 *
 * Stores sync jobs in the WordPress database using the quant_sync_queue table.
 */
final class DatabaseQueue implements SyncQueueInterface
{
    public const TABLE_NAME = 'quant_sync_queue';

    private const STATUS_PENDING = 'pending';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_FAILED = 'failed';
    private const STATUS_COMPLETED = 'completed';

    private const COLUMNS = 'job_id, type, object_id, action, status, attempts, created_at, meta';
    private const ROW_PLACEHOLDERS = '(%s, %s, %s, %s, %s, %d, %s, %s)';

    private string $tableName;

    public function __construct(
        private readonly wpdb $wpdb,
    ) {
        $this->tableName = $this->wpdb->prefix . self::TABLE_NAME;
    }

    public function push(SyncJob $job): void
    {
        if ($this->hasPendingDuplicate($job)) {
            return;
        }

        $this->wpdb->insert(
            $this->tableName,
            [
                'job_id' => $job->id,
                'type' => $job->type->value,
                'object_id' => $job->objectId,
                'action' => $job->action->value,
                'status' => self::STATUS_PENDING,
                'attempts' => 0,
                'created_at' => $job->createdAt->format('Y-m-d H:i:s'),
                'meta' => $job->encodeMeta(),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        $this->announce();
    }

    /**
     * @param array<SyncJob> $jobs
     */
    public function pushBatch(array $jobs): void
    {
        if ($jobs === []) {
            return;
        }

        $jobs = $this->withoutDuplicates($jobs);

        if ($jobs === []) {
            return;
        }

        foreach (array_chunk($jobs, 200) as $chunk) {
            $this->insertChunk($chunk);
        }

        $this->announce();
    }

    /**
     * Let the scheduler know work is waiting.
     */
    private function announce(): void
    {
        if (function_exists('do_action')) {
            /** This action is documented in v2.0.0; it fires after jobs were added to the queue. */
            do_action('quant_jobs_queued');
        }
    }

    /**
     * @return array<SyncJob>
     */
    public function fetchPending(int $limit = 50): array
    {
        // Claim atomically so parallel workers never take the same job.
        $claim = $this->newClaim();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $update = $this->wpdb->prepare(
            "UPDATE {$this->tableName}
             SET status = %s, claim = %s, processed_at = %s
             WHERE status = %s
             ORDER BY id ASC
             LIMIT %d",
            self::STATUS_PROCESSING,
            $claim,
            gmdate('Y-m-d H:i:s'),
            self::STATUS_PENDING,
            $limit
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $claimed = $this->wpdb->query($update);
        if (!$claimed) {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $select = $this->wpdb->prepare(
            "SELECT job_id, type, object_id, action, created_at, meta
             FROM {$this->tableName}
             WHERE claim = %s AND status = %s
             ORDER BY id ASC",
            $claim,
            self::STATUS_PROCESSING
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $rows = $this->wpdb->get_results($select, ARRAY_A) ?: [];

        return array_map(static fn(array $row): SyncJob => SyncJob::fromDatabaseRow($row), $rows);
    }

    private function newClaim(): string
    {
        return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('claim', true);
    }

    public function markCompleted(string $jobId): void
    {
        $this->wpdb->update(
            $this->tableName,
            [
                'status' => self::STATUS_COMPLETED,
                'error_message' => null,
                'processed_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['job_id' => $jobId],
            ['%s', '%s', '%s'],
            ['%s']
        );
    }

    public function markFailed(string $jobId, string $error): void
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $sql = $this->wpdb->prepare(
            "UPDATE {$this->tableName}
             SET status = %s,
                 error_message = %s,
                 attempts = attempts + 1,
                 processed_at = %s
             WHERE job_id = %s",
            self::STATUS_FAILED,
            mb_substr($error, 0, 2000),
            gmdate('Y-m-d H:i:s'),
            $jobId
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $this->wpdb->query($sql);
    }

    public function getStatus(): QueueStatus
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $sql = "SELECT status, COUNT(*) as count
                FROM {$this->tableName}
                GROUP BY status";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- No user input in query
        $results = $this->wpdb->get_results($sql, ARRAY_A) ?: [];

        $counts = [
            self::STATUS_PENDING => 0,
            self::STATUS_PROCESSING => 0,
            self::STATUS_FAILED => 0,
            self::STATUS_COMPLETED => 0,
        ];

        $total = 0;
        foreach ($results as $row) {
            $count = (int) $row['count'];
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = $count;
            }
            $total += $count;
        }

        return new QueueStatus(
            pending: $counts[self::STATUS_PENDING],
            processing: $counts[self::STATUS_PROCESSING],
            failed: $counts[self::STATUS_FAILED],
            total: $total,
        );
    }

    public function clear(): void
    {
        $this->wpdb->delete(
            $this->tableName,
            ['status' => self::STATUS_PENDING],
            ['%s']
        );
    }

    public function retry(): int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $sql = $this->wpdb->prepare(
            "UPDATE {$this->tableName}
             SET status = %s,
                 error_message = NULL,
                 processed_at = NULL
             WHERE status = %s",
            self::STATUS_PENDING,
            self::STATUS_FAILED
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $this->wpdb->query($sql);

        return (int) $this->wpdb->rows_affected;
    }

    public function recoverStale(int $olderThanSeconds = 600): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $olderThanSeconds);

        // processed_at holds the claim time while a job is processing.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $sql = $this->wpdb->prepare(
            "UPDATE {$this->tableName}
             SET status = %s, claim = NULL
             WHERE status = %s AND (processed_at IS NULL OR processed_at < %s)",
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            $cutoff
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $this->wpdb->query($sql);

        return (int) $this->wpdb->rows_affected;
    }

    public function release(array $jobIds): void
    {
        $this->updateStatusBatch(array_values(array_filter($jobIds, 'is_string')), self::STATUS_PENDING);
    }

    public function hasRecentJob(SyncType $type, string $objectId, SyncAction $action, int $withinSeconds): bool
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $withinSeconds);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableName}
             WHERE type = %s AND object_id = %s AND action = %s
               AND status IN (%s, %s, %s) AND created_at >= %s",
            $type->value,
            $objectId,
            $action->value,
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            $cutoff
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        return (int) $this->wpdb->get_var($sql) > 0;
    }

    public function clearTypes(array $types): int
    {
        if ($types === []) {
            $this->clear();
            return (int) $this->wpdb->rows_affected;
        }

        $values = array_map(static fn(SyncType $type): string => $type->value, $types);
        $placeholders = implode(', ', array_fill(0, count($values), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholders are safely constructed
        $sql = $this->wpdb->prepare(
            "DELETE FROM {$this->tableName} WHERE status = %s AND type IN ($placeholders)",
            array_merge([self::STATUS_PENDING], $values)
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $this->wpdb->query($sql);

        return (int) $this->wpdb->rows_affected;
    }

    /**
     * Recent failures for diagnostics.
     *
     * @return array<int, array{type: string, object_id: string, error_message: string, processed_at: string}>
     */
    public function recentErrors(int $limit = 10): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $sql = $this->wpdb->prepare(
            "SELECT type, object_id, error_message, processed_at
             FROM {$this->tableName}
             WHERE status = %s AND error_message IS NOT NULL
             ORDER BY processed_at DESC
             LIMIT %d",
            self::STATUS_FAILED,
            $limit
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Latest status per object for a type, used by the content browser.
     *
     * @param array<string> $objectIds
     * @return array<string, array{status: string, processed_at: ?string}>
     */
    public function latestStatuses(SyncType $type, array $objectIds): array
    {
        $objectIds = array_values(array_map('strval', $objectIds));
        if ($objectIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($objectIds), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholders are safely constructed
        $sql = $this->wpdb->prepare(
            "SELECT object_id, status, processed_at
             FROM {$this->tableName}
             WHERE type = %s AND action = %s AND object_id IN ($placeholders)
             ORDER BY id DESC",
            array_merge([$type->value, SyncAction::Publish->value], $objectIds)
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $rows = $this->wpdb->get_results($sql, ARRAY_A) ?: [];

        $latest = [];
        foreach ($rows as $row) {
            $id = (string) $row['object_id'];
            if (!isset($latest[$id])) {
                $latest[$id] = ['status' => (string) $row['status'], 'processed_at' => $row['processed_at'] ?: null];
            }
            if ($latest[$id]['processed_at'] === null && $row['status'] === self::STATUS_COMPLETED) {
                $latest[$id]['processed_at'] = $row['processed_at'] ?: null;
            }
        }

        return $latest;
    }

    /**
     * Remove completed jobs older than the given age, so the table does not grow without bound.
     *
     * @return int Rows deleted
     */
    public function pruneCompleted(int $olderThanSeconds = 2592000): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $olderThanSeconds);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $sql = $this->wpdb->prepare(
            "DELETE FROM {$this->tableName} WHERE status = %s AND processed_at < %s",
            self::STATUS_COMPLETED,
            $cutoff
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $this->wpdb->query($sql);

        return (int) $this->wpdb->rows_affected;
    }

    /**
     * @param array<SyncJob> $jobs
     */
    private function insertChunk(array $jobs): void
    {
        $placeholders = [];
        $values = [];

        foreach ($jobs as $job) {
            $placeholders[] = self::ROW_PLACEHOLDERS;
            array_push(
                $values,
                $job->id,
                $job->type->value,
                $job->objectId,
                $job->action->value,
                self::STATUS_PENDING,
                0,
                $job->createdAt->format('Y-m-d H:i:s'),
                $job->encodeMeta() ?? ''
            );
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            $this->tableName,
            self::COLUMNS,
            implode(', ', $placeholders)
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is built safely with placeholders
        $this->wpdb->query($this->wpdb->prepare($sql, $values));
    }

    /**
     * Drop jobs that duplicate another job in the batch or a pending job in the table.
     *
     * @param array<SyncJob> $jobs
     * @return array<SyncJob>
     */
    private function withoutDuplicates(array $jobs): array
    {
        $pending = $this->pendingKeys();
        $result = [];

        foreach ($jobs as $job) {
            $key = $this->jobKey($job->type->value, $job->objectId, $job->action->value);
            if (isset($pending[$key])) {
                continue;
            }
            $pending[$key] = true;
            $result[] = $job;
        }

        return $result;
    }

    /**
     * @return array<string, true>
     */
    private function pendingKeys(): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $sql = $this->wpdb->prepare(
            "SELECT type, object_id, action FROM {$this->tableName} WHERE status = %s",
            self::STATUS_PENDING
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $rows = $this->wpdb->get_results($sql, ARRAY_A) ?: [];

        $keys = [];
        foreach ($rows as $row) {
            $keys[$this->jobKey($row['type'], $row['object_id'], $row['action'])] = true;
        }

        return $keys;
    }

    private function hasPendingDuplicate(SyncJob $job): bool
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableName}
             WHERE status = %s AND type = %s AND object_id = %s AND action = %s",
            self::STATUS_PENDING,
            $job->type->value,
            $job->objectId,
            $job->action->value
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        return (int) $this->wpdb->get_var($sql) > 0;
    }

    private function jobKey(string $type, string $objectId, string $action): string
    {
        return $type . '|' . $objectId . '|' . $action;
    }

    /**
     * Update status for multiple jobs at once.
     *
     * @param array<string> $jobIds
     */
    private function updateStatusBatch(array $jobIds, string $status): void
    {
        if ($jobIds === []) {
            return;
        }

        $placeholderString = implode(', ', array_fill(0, count($jobIds), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholders are safely constructed
        $sql = $this->wpdb->prepare(
            "UPDATE {$this->tableName} SET status = %s WHERE job_id IN ($placeholderString)",
            array_merge([$status], $jobIds)
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $this->wpdb->query($sql);
    }
}
