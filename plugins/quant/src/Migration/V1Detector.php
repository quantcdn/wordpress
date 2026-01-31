<?php

declare(strict_types=1);

namespace Quant\Migration;

use wpdb;

/**
 * Detects v1 QuantCDN plugin settings and tables.
 *
 * V1 used different option names and table structure:
 * - quantcdn_api_account (organization)
 * - quantcdn_api_project (project)
 * - quantcdn_api_token (token)
 * - quantcdn_api_endpoint (endpoint)
 * - {prefix}quantcdn_seed_queue (queue table)
 */
final class V1Detector
{
    private const V1_OPTIONS = [
        'quantcdn_api_account',
        'quantcdn_api_project',
        'quantcdn_api_token',
        'quantcdn_api_endpoint',
    ];

    private const V1_TABLE = 'quantcdn_seed_queue';

    private const V2_OPTION_CREDENTIALS = 'quant_credentials';

    public function __construct(
        private readonly wpdb $wpdb
    ) {}

    /**
     * Check if v1 settings exist.
     */
    public function hasV1Settings(): bool
    {
        foreach (self::V1_OPTIONS as $option) {
            $value = get_option($option, null);
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if v2 credentials are already configured.
     */
    public function hasV2Settings(): bool
    {
        $credentials = get_option(self::V2_OPTION_CREDENTIALS, []);

        return !empty($credentials['organization'])
            && !empty($credentials['project'])
            && !empty($credentials['token']);
    }

    /**
     * Check if migration is needed (has v1 settings but not v2).
     */
    public function needsMigration(): bool
    {
        return $this->hasV1Settings() && !$this->hasV2Settings();
    }

    /**
     * Check if this is a fresh install (no v1, no v2).
     */
    public function isFreshInstall(): bool
    {
        return !$this->hasV1Settings() && !$this->hasV2Settings();
    }

    /**
     * Get all v1 settings.
     *
     * @return array{organization: string, project: string, token: string, endpoint: string}
     */
    public function getV1Settings(): array
    {
        return [
            'organization' => (string) get_option('quantcdn_api_account', ''),
            'project' => (string) get_option('quantcdn_api_project', ''),
            'token' => (string) get_option('quantcdn_api_token', ''),
            'endpoint' => (string) get_option('quantcdn_api_endpoint', 'https://api.quantcdn.io'),
        ];
    }

    /**
     * Check if v1 queue table exists.
     */
    public function hasV1Table(): bool
    {
        $tableName = $this->wpdb->prefix . self::V1_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe
        $result = $this->wpdb->get_var(
            $this->wpdb->prepare('SHOW TABLES LIKE %s', $tableName)
        );

        return $result === $tableName;
    }

    /**
     * Get count of items in v1 queue table.
     */
    public function getV1QueueCount(): int
    {
        if (!$this->hasV1Table()) {
            return 0;
        }

        $tableName = $this->wpdb->prefix . self::V1_TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe
        $count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$tableName}");

        return (int) $count;
    }

    /**
     * Get v1 option names.
     *
     * @return array<string>
     */
    public function getV1OptionNames(): array
    {
        return self::V1_OPTIONS;
    }

    /**
     * Get v1 table name.
     */
    public function getV1TableName(): string
    {
        return $this->wpdb->prefix . self::V1_TABLE;
    }

    /**
     * Get migration status summary.
     *
     * @return array{needs_migration: bool, fresh_install: bool, has_v1_settings: bool, has_v2_settings: bool, has_v1_table: bool, v1_queue_count: int}
     */
    public function getStatus(): array
    {
        return [
            'needs_migration' => $this->needsMigration(),
            'fresh_install' => $this->isFreshInstall(),
            'has_v1_settings' => $this->hasV1Settings(),
            'has_v2_settings' => $this->hasV2Settings(),
            'has_v1_table' => $this->hasV1Table(),
            'v1_queue_count' => $this->getV1QueueCount(),
        ];
    }
}
