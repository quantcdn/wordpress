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
    private const TABLE_NAME = 'quant_sync_queue';

    private const STATUS_PENDING = 'pending';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_FAILED = 'failed';
    private const STATUS_COMPLETED = 'completed';

    private string $tableName;

    public function __construct(
        private readonly wpdb $wpdb,
    ) {
        $this->tableName = $this->wpdb->prefix . self::TABLE_NAME;
    }

    public function push(SyncJob $job): void
    {
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
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );
    }

    /**
     * @param array<SyncJob> $jobs
     */
    public function pushBatch(array $jobs): void
    {
        if ($jobs === []) {
            return;
        }

        // Build bulk insert query for efficiency
        $placeholders = [];
        $values = [];

        foreach ($jobs as $job) {
            $placeholders[] = '(%s, %s, %s, %s, %s, %d, %s)';
            $values[] = $job->id;
            $values[] = $job->type->value;
            $values[] = $job->objectId;
            $values[] = $job->action->value;
            $values[] = self::STATUS_PENDING;
            $values[] = 0;
            $values[] = $job->createdAt->format('Y-m-d H:i:s');
        }

        $sql = sprintf(
            'INSERT INTO %s (job_id, type, object_id, action, status, attempts, created_at) VALUES %s',
            $this->tableName,
            implode(', ', $placeholders)
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is built safely with placeholders
        $this->wpdb->query($this->wpdb->prepare($sql, $values));
    }

    /**
     * @return array<SyncJob>
     */
    public function fetchPending(int $limit = 50): array
    {
        // Fetch pending jobs
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely constructed
        $sql = $this->wpdb->prepare(
            "SELECT job_id, type, object_id, action, created_at
             FROM {$this->tableName}
             WHERE status = %s
             ORDER BY created_at ASC
             LIMIT %d",
            self::STATUS_PENDING,
            $limit
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!$rows) {
            return [];
        }

        // Mark these jobs as processing
        $jobIds = array_column($rows, 'job_id');
        $this->updateStatusBatch($jobIds, self::STATUS_PROCESSING);

        // Convert rows to SyncJob objects
        $jobs = [];
        foreach ($rows as $row) {
            $jobs[] = SyncJob::fromDatabaseRow($row);
        }

        return $jobs;
    }

    public function markCompleted(string $jobId): void
    {
        $this->wpdb->update(
            $this->tableName,
            [
                'status' => self::STATUS_COMPLETED,
                'processed_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['job_id' => $jobId],
            ['%s', '%s'],
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
            $error,
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
        $results = $this->wpdb->get_results($sql, ARRAY_A);

        $counts = [
            self::STATUS_PENDING => 0,
            self::STATUS_PROCESSING => 0,
            self::STATUS_FAILED => 0,
            self::STATUS_COMPLETED => 0,
        ];

        $total = 0;
        foreach ($results as $row) {
            $status = $row['status'];
            $count = (int) $row['count'];
            if (isset($counts[$status])) {
                $counts[$status] = $count;
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

        $placeholders = array_fill(0, count($jobIds), '%s');
        $placeholderString = implode(', ', $placeholders);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and placeholders are safely constructed
        $sql = $this->wpdb->prepare(
            "UPDATE {$this->tableName} SET status = %s WHERE job_id IN ($placeholderString)",
            array_merge([$status], $jobIds)
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
        $this->wpdb->query($sql);
    }
}
