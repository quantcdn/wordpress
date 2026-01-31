<?php

declare(strict_types=1);

namespace Quant\Admin;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Migration\V1Detector;
use Quant\Migration\V1Migrator;
use Quant\Sync\DatabaseQueue;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST controller for diagnostics and migration endpoints.
 */
class DiagnosticsController
{
    private const NAMESPACE = 'quant/v1';

    private ?DatabaseQueue $queue = null;

    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings,
        private readonly V1Detector $detector,
        private readonly V1Migrator $migrator
    ) {}

    private function getQueue(): DatabaseQueue
    {
        if ($this->queue === null) {
            global $wpdb;
            $this->queue = new DatabaseQueue($wpdb);
        }
        return $this->queue;
    }

    /**
     * Register REST routes for diagnostics and migration.
     */
    public function register(): void
    {
        // Migration endpoints
        register_rest_route(self::NAMESPACE, '/migration/status', [
            'methods' => 'GET',
            'callback' => [$this, 'getMigrationStatus'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/migration/run', [
            'methods' => 'POST',
            'callback' => [$this, 'runMigration'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/migration/cleanup', [
            'methods' => 'POST',
            'callback' => [$this, 'cleanupMigration'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // Diagnostics endpoint
        register_rest_route(self::NAMESPACE, '/diagnostics', [
            'methods' => 'GET',
            'callback' => [$this, 'getDiagnostics'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // Onboarding status endpoint
        register_rest_route(self::NAMESPACE, '/onboarding/status', [
            'methods' => 'GET',
            'callback' => [$this, 'getOnboardingStatus'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    /**
     * Check if current user has permission.
     */
    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Get migration status.
     */
    public function getMigrationStatus(WP_REST_Request $request): array
    {
        $detectorStatus = $this->detector->getStatus();
        $v1Settings = $this->detector->hasV1Settings() ? $this->detector->getV1Settings() : null;

        // Mask the token for security
        if ($v1Settings !== null && !empty($v1Settings['token'])) {
            $token = $v1Settings['token'];
            $v1Settings['token'] = strlen($token) > 4
                ? '***' . substr($token, -4)
                : '****';
        }

        return [
            'needs_migration' => $detectorStatus['needs_migration'],
            'migration_complete' => $this->migrator->isMigrationComplete(),
            'migration_date' => $this->migrator->getMigrationDate(),
            'fresh_install' => $detectorStatus['fresh_install'],
            'has_v1_settings' => $detectorStatus['has_v1_settings'],
            'has_v2_settings' => $detectorStatus['has_v2_settings'],
            'has_v1_table' => $detectorStatus['has_v1_table'],
            'v1_queue_count' => $detectorStatus['v1_queue_count'],
            'v1_settings' => $v1Settings,
        ];
    }

    /**
     * Run migration from v1 to v2.
     */
    public function runMigration(WP_REST_Request $request): array|WP_Error
    {
        $result = $this->migrator->migrate();

        if (!$result->success) {
            return new WP_Error(
                'migration_failed',
                implode('; ', $result->errors),
                ['status' => 400]
            );
        }

        return $result->toArray();
    }

    /**
     * Clean up v1 data.
     */
    public function cleanupMigration(WP_REST_Request $request): array|WP_Error
    {
        $success = $this->migrator->cleanup();

        if (!$success) {
            return new WP_Error(
                'cleanup_failed',
                'Failed to clean up v1 data',
                ['status' => 500]
            );
        }

        return [
            'success' => true,
            'message' => 'V1 data cleaned up successfully',
        ];
    }

    /**
     * Get diagnostic information.
     */
    public function getDiagnostics(WP_REST_Request $request): array
    {
        global $wp_version;

        // Get API connection status
        $apiConnected = false;
        $apiError = null;
        if ($this->settings->hasValidCredentials()) {
            try {
                $response = $this->api->ping();
                $apiConnected = $response->success;
                if (!$response->success) {
                    $apiError = $response->message;
                }
            } catch (\Exception $e) {
                $apiError = $e->getMessage();
            }
        }

        // Get queue status
        $queueStatus = null;
        try {
            $status = $this->getQueue()->getStatus();
            $queueStatus = [
                'pending' => $status->pending,
                'processing' => $status->processing,
                'failed' => $status->failed,
                'total' => $status->total,
            ];
        } catch (\Exception $e) {
            $queueStatus = ['error' => $e->getMessage()];
        }

        // Check v2 sync table exists
        global $wpdb;
        $v2TableName = $wpdb->prefix . 'quant_sync_queue';
        $v2TableExists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $v2TableName)
        ) === $v2TableName;

        // Get credentials info (masked)
        $credentials = $this->settings->getCredentials();
        $hasCredentials = $this->settings->hasValidCredentials();

        // Get recent errors from queue
        $recentErrors = [];
        if ($v2TableExists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $errors = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT type, object_id, error_message, processed_at
                     FROM {$v2TableName}
                     WHERE status = 'failed' AND error_message IS NOT NULL
                     ORDER BY processed_at DESC
                     LIMIT %d",
                    10
                ),
                ARRAY_A
            );
            $recentErrors = $errors ?: [];
        }

        return [
            'environment' => [
                'php_version' => PHP_VERSION,
                'wordpress_version' => $wp_version,
                'plugin_version' => defined('QUANT_VERSION') ? QUANT_VERSION : '2.0.0',
                'php_memory_limit' => ini_get('memory_limit'),
                'php_max_execution_time' => ini_get('max_execution_time'),
                'is_multisite' => is_multisite(),
            ],
            'plugin' => [
                'mode' => $this->settings->getMode(),
                'has_credentials' => $hasCredentials,
                'organization' => $credentials['organization'] ?? '',
                'project' => $credentials['project'] ?? '',
                'endpoint' => $credentials['endpoint'] ?? 'https://api.quantcdn.io',
            ],
            'api' => [
                'connected' => $apiConnected,
                'error' => $apiError,
            ],
            'database' => [
                'v2_table_exists' => $v2TableExists,
                'v1_table_exists' => $this->detector->hasV1Table(),
            ],
            'queue' => $queueStatus,
            'recent_errors' => $recentErrors,
            'migration' => [
                'needs_migration' => $this->detector->needsMigration(),
                'migration_complete' => $this->migrator->isMigrationComplete(),
                'migration_date' => $this->migrator->getMigrationDate(),
            ],
        ];
    }

    /**
     * Get onboarding status.
     */
    public function getOnboardingStatus(WP_REST_Request $request): array
    {
        $isFreshInstall = $this->detector->isFreshInstall();
        $needsMigration = $this->detector->needsMigration();
        $hasV2Settings = $this->detector->hasV2Settings();

        // Determine what state the user should be in
        $state = 'configured';
        if ($isFreshInstall) {
            $state = 'onboarding';
        } elseif ($needsMigration) {
            $state = 'migration';
        }

        return [
            'state' => $state,
            'fresh_install' => $isFreshInstall,
            'needs_migration' => $needsMigration,
            'has_settings' => $hasV2Settings,
        ];
    }
}
