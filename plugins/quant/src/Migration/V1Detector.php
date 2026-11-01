<?php

declare(strict_types=1);

namespace Quant\Migration;

use wpdb;

/**
 * Detects v1 (1.x) plugin settings.
 *
 * V1 stored:
 * - wp_quant_settings: enabled, purge_on_save, disable_tls_verify, http_request_timeout,
 *   webserver_url, webserver_host, api_endpoint, api_account, api_project, api_token
 * - wp_quant_seed: 404_route, custom_routes (newline separated), domains_strip (newline separated)
 * - wp_quant_cron_settings: cron_enabled, cron_schedule, cron_home, cron_posts, cron_pages,
 *   cron_categories, cron_tags, cron_custom_routes
 * - batch state as wp_options rows named batch_quant_*_processed / _claimed
 */
final class V1Detector
{
    public const V1_SETTINGS_OPTION = 'wp_quant_settings';
    public const V1_SEED_OPTION = 'wp_quant_seed';
    public const V1_CRON_OPTION = 'wp_quant_cron_settings';

    private const V1_OPTIONS = [
        self::V1_SETTINGS_OPTION,
        self::V1_SEED_OPTION,
        self::V1_CRON_OPTION,
    ];

    private const V2_OPTION_CREDENTIALS = 'quant_credentials';

    public function __construct(
        private readonly wpdb $wpdb
    ) {
    }

    public function hasV1Settings(): bool
    {
        $settings = get_option(self::V1_SETTINGS_OPTION, []);
        if (!is_array($settings)) {
            return false;
        }

        return !empty($settings['api_account']) || !empty($settings['api_project']) || !empty($settings['api_token']);
    }

    public function hasV2Settings(): bool
    {
        $credentials = get_option(self::V2_OPTION_CREDENTIALS, []);

        return is_array($credentials)
            && !empty($credentials['organization'])
            && !empty($credentials['project'])
            && !empty($credentials['token']);
    }

    public function needsMigration(): bool
    {
        return $this->hasV1Settings() && !$this->hasV2Settings();
    }

    public function isFreshInstall(): bool
    {
        return !$this->hasV1Settings() && !$this->hasV2Settings();
    }

    /**
     * All v1 settings in v2 terms.
     *
     * @return array<string, mixed>
     */
    public function getV1Settings(): array
    {
        $settings = $this->option(self::V1_SETTINGS_OPTION);
        $seed = $this->option(self::V1_SEED_OPTION);
        $cron = $this->option(self::V1_CRON_OPTION);

        return [
            'organization' => (string) ($settings['api_account'] ?? ''),
            'project' => (string) ($settings['api_project'] ?? ''),
            'token' => (string) ($settings['api_token'] ?? ''),
            'endpoint' => (string) ($settings['api_endpoint'] ?? 'https://api.quantcdn.io'),
            'disable_tls_verify' => !empty($settings['disable_tls_verify']),
            'http_request_timeout' => (int) ($settings['http_request_timeout'] ?? 30) ?: 30,
            'auto_sync' => !empty($settings['enabled']),
            'auto_purge' => !isset($settings['purge_on_save']) || !empty($settings['purge_on_save']),
            'webserver_url' => (string) ($settings['webserver_url'] ?? ''),
            'webserver_host' => (string) ($settings['webserver_host'] ?? ''),
            'route_404' => (string) ($seed['404_route'] ?? '/404'),
            'custom_routes' => $this->lines($seed['custom_routes'] ?? '/robots.txt'),
            'domain_rewrites' => $this->lines($seed['domains_strip'] ?? ''),
            'schedule' => $this->schedule($cron),
        ];
    }

    /**
     * Number of v1 batch state rows still in wp_options.
     */
    public function getV1QueueCount(): int
    {
        $wpdb = $this->wpdb;
        $like = $wpdb->esc_like('batch_quant_') . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off count during migration.
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like));

        return (int) $count;
    }

    /**
     * @return array<string>
     */
    public function getV1OptionNames(): array
    {
        return self::V1_OPTIONS;
    }

    /**
     * @return array{needs_migration: bool, fresh_install: bool, has_v1_settings: bool, has_v2_settings: bool, v1_queue_count: int}
     */
    public function getStatus(): array
    {
        return [
            'needs_migration' => $this->needsMigration(),
            'fresh_install' => $this->isFreshInstall(),
            'has_v1_settings' => $this->hasV1Settings(),
            'has_v2_settings' => $this->hasV2Settings(),
            'v1_queue_count' => $this->getV1QueueCount(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function option(string $name): array
    {
        $value = get_option($name, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $cron
     * @return array{enabled: bool, interval: string, types: array<string>, custom_routes: array<string>}
     */
    private function schedule(array $cron): array
    {
        $types = [];
        foreach (['home' => 'home', 'posts' => 'post', 'pages' => 'page', 'categories' => 'taxonomy', 'tags' => 'taxonomy'] as $key => $type) {
            if (!empty($cron["cron_{$key}"])) {
                $types[] = $type;
            }
        }

        $interval = (string) ($cron['cron_schedule'] ?? 'daily');

        return [
            'enabled' => !empty($cron['cron_enabled']),
            'interval' => in_array($interval, ['hourly', 'twicedaily', 'daily', 'weekly'], true) ? $interval : 'daily',
            'types' => array_values(array_unique($types)),
            'custom_routes' => $this->lines($cron['cron_custom_routes'] ?? ''),
        ];
    }

    /**
     * @return array<string>
     */
    private function lines(mixed $value): array
    {
        if (is_array($value)) {
            $lines = $value;
        } else {
            $lines = explode("\n", (string) $value);
        }

        return array_values(array_filter(array_map(static fn($l): string => trim((string) $l), $lines)));
    }
}
