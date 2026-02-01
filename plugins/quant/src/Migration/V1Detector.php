<?php

declare(strict_types=1);

namespace Quant\Migration;

use wpdb;

/**
 * Detects v1 QuantCDN plugin settings and tables.
 *
 * V1 used these options:
 * - wp_quant_settings (main settings: api_account, api_project, api_token, api_endpoint, webserver_url, webserver_host)
 * - wp_quant_seed (seed settings: 404_route, custom_routes, domains_strip)
 * - wp_quant_cron_settings (cron settings)
 * - {prefix}quant_seed_queue (queue table)
 */
final class V1Detector
{
    private const V1_SETTINGS_OPTION = 'wp_quant_settings';
    private const V1_SEED_OPTION = 'wp_quant_seed';
    private const V1_CRON_OPTION = 'wp_quant_cron_settings';

    private const V1_OPTIONS = [
        'wp_quant_settings',
        'wp_quant_seed',
        'wp_quant_cron_settings',
    ];

    private const V1_TABLE = 'quant_seed_queue';

    private const V2_OPTION_CREDENTIALS = 'quant_credentials';

    public function __construct(
        private readonly wpdb $wpdb
    ) {}

    /**
     * Check if v1 settings exist.
     */
    public function hasV1Settings(): bool
    {
        $settings = get_option(self::V1_SETTINGS_OPTION, []);

        // Check if we have v1 API credentials
        return !empty($settings['api_account']) || !empty($settings['api_project']) || !empty($settings['api_token']);
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
     * @return array{organization: string, project: string, token: string, endpoint: string, webserver_url: string, webserver_host: string, route_404: string, custom_routes: array, domain_rewrites: array}
     */
    public function getV1Settings(): array
    {
        $settings = get_option(self::V1_SETTINGS_OPTION, []);
        $seedSettings = get_option(self::V1_SEED_OPTION, []);

        // Parse custom routes (newline separated in v1)
        $customRoutes = [];
        if (!empty($seedSettings['custom_routes'])) {
            $customRoutes = array_filter(array_map('trim', explode("\n", $seedSettings['custom_routes'])));
        }

        // Parse domain rewrites (newline separated in v1)
        $domainRewrites = [];
        if (!empty($seedSettings['domains_strip'])) {
            $domainRewrites = array_filter(array_map('trim', explode("\n", $seedSettings['domains_strip'])));
        }

        return [
            // API credentials
            'organization' => (string) ($settings['api_account'] ?? ''),
            'project' => (string) ($settings['api_project'] ?? ''),
            'token' => (string) ($settings['api_token'] ?? ''),
            'endpoint' => (string) ($settings['api_endpoint'] ?? 'https://api.quantcdn.io'),
            // Webserver config
            'webserver_url' => (string) ($settings['webserver_url'] ?? ''),
            'webserver_host' => (string) ($settings['webserver_host'] ?? ''),
            // Seed settings
            'route_404' => (string) ($seedSettings['404_route'] ?? '/404'),
            'custom_routes' => $customRoutes,
            'domain_rewrites' => $domainRewrites,
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
