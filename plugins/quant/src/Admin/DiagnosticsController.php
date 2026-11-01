<?php

declare(strict_types=1);

namespace Quant\Admin;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Migration\V1Detector;
use Quant\Migration\V1Migrator;
use Quant\Sync\DatabaseQueue;
use WP_Error;
use WP_REST_Request;

/**
 * REST endpoints for diagnostics, onboarding state and the v1 migration.
 */
class DiagnosticsController
{
    private const NAMESPACE = 'quant/v1';

    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings,
        private readonly V1Detector $detector,
        private readonly V1Migrator $migrator,
        private readonly DatabaseQueue $queue
    ) {
    }

    public function register(): void
    {
        $permission = ['permission_callback' => [$this, 'checkPermission']];

        register_rest_route(self::NAMESPACE, '/migration/status', ['methods' => 'GET', 'callback' => [$this, 'getMigrationStatus']] + $permission);
        register_rest_route(self::NAMESPACE, '/migration/run', ['methods' => 'POST', 'callback' => [$this, 'runMigration']] + $permission);
        register_rest_route(self::NAMESPACE, '/migration/cleanup', ['methods' => 'POST', 'callback' => [$this, 'cleanupMigration']] + $permission);
        register_rest_route(self::NAMESPACE, '/diagnostics', ['methods' => 'GET', 'callback' => [$this, 'getDiagnostics']] + $permission);
        register_rest_route(self::NAMESPACE, '/onboarding/status', ['methods' => 'GET', 'callback' => [$this, 'getOnboardingStatus']] + $permission);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * @return array<string, mixed>
     */
    public function getMigrationStatus(WP_REST_Request $request): array
    {
        $status = $this->detector->getStatus();
        $v1 = $this->detector->hasV1Settings() ? $this->detector->getV1Settings() : null;

        if ($v1 !== null) {
            $v1['token'] = strlen($v1['token']) > 4 ? '***' . substr($v1['token'], -4) : '****';
        }

        return $status + [
            'migration_complete' => $this->migrator->isMigrationComplete(),
            'migration_date' => $this->migrator->getMigrationDate(),
            'has_v1_table' => false,
            'v1_settings' => $v1,
        ];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function runMigration(WP_REST_Request $request): array|WP_Error
    {
        $result = $this->migrator->migrate();

        if (!$result->success) {
            return new WP_Error('migration_failed', implode('; ', $result->errors), ['status' => 400]);
        }

        return $result->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function cleanupMigration(WP_REST_Request $request): array
    {
        $this->migrator->cleanup();

        return ['success' => true, 'message' => __('v1 data removed', 'quant')];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDiagnostics(WP_REST_Request $request): array
    {
        global $wp_version, $wpdb;

        $api = ['connected' => false, 'error' => null, 'search_enabled' => false];
        if ($this->settings->hasValidCredentials()) {
            $response = $this->api->ping();
            $api = [
                'connected' => $response->success,
                'error' => $response->success ? null : $response->message,
                'search_enabled' => !empty($response->data['config']['search_enabled']),
            ];
        }

        $table = $wpdb->prefix . DatabaseQueue::TABLE_NAME;
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        $status = $this->queue->getStatus();
        $credentials = $this->settings->getCredentials();

        return [
            'environment' => [
                'php_version' => PHP_VERSION,
                'wordpress_version' => $wp_version,
                'plugin_version' => QUANT_VERSION,
                'php_memory_limit' => ini_get('memory_limit'),
                'php_max_execution_time' => ini_get('max_execution_time'),
                'is_multisite' => is_multisite(),
                'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                'next_queue_run' => wp_next_scheduled('quant_process_queue') ?: null,
                'permalink_structure' => get_option('permalink_structure') ?: 'plain',
            ],
            'plugin' => [
                'mode' => $this->settings->getMode(),
                'has_credentials' => $this->settings->hasValidCredentials(),
                'organization' => $credentials['organization'],
                'project' => $credentials['project'],
                'endpoint' => $credentials['endpoint'],
                'overridden' => $this->settings->getOverriddenKeys(),
            ],
            'api' => $api,
            'database' => [
                'v2_table_exists' => $tableExists,
                'v1_table_exists' => false,
                'db_version' => get_option('quant_db_version'),
            ],
            'queue' => [
                'pending' => $status->pending,
                'processing' => $status->processing,
                'failed' => $status->failed,
                'total' => $status->total,
            ],
            'recent_errors' => $tableExists ? $this->queue->recentErrors(10) : [],
            'migration' => [
                'needs_migration' => $this->detector->needsMigration(),
                'migration_complete' => $this->migrator->isMigrationComplete(),
                'migration_date' => $this->migrator->getMigrationDate(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOnboardingStatus(WP_REST_Request $request): array
    {
        $fresh = $this->detector->isFreshInstall();
        $needsMigration = $this->detector->needsMigration();

        return [
            'state' => $fresh ? 'onboarding' : ($needsMigration ? 'migration' : 'configured'),
            'fresh_install' => $fresh,
            'needs_migration' => $needsMigration,
            'has_settings' => $this->detector->hasV2Settings(),
        ];
    }
}
