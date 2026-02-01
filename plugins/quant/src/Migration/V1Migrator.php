<?php

declare(strict_types=1);

namespace Quant\Migration;

use Quant\Core\Settings;
use wpdb;

/**
 * Migrates v1 QuantCDN plugin settings to v2 format.
 */
final class V1Migrator
{
    private const OPTION_MIGRATION_COMPLETE = 'quant_migration_complete';
    private const OPTION_MIGRATION_DATE = 'quant_migration_date';

    public function __construct(
        private readonly V1Detector $detector,
        private readonly Settings $settings,
        private readonly wpdb $wpdb
    ) {}

    /**
     * Check if migration has already been completed.
     */
    public function isMigrationComplete(): bool
    {
        return (bool) get_option(self::OPTION_MIGRATION_COMPLETE, false);
    }

    /**
     * Run the migration from v1 to v2.
     */
    public function migrate(): MigrationResult
    {
        $migrated = [];
        $errors = [];

        // Check if migration is needed
        if (!$this->detector->hasV1Settings()) {
            return MigrationResult::failure(['No v1 settings found to migrate']);
        }

        // Check if already migrated
        if ($this->isMigrationComplete()) {
            return MigrationResult::failure(['Migration has already been completed']);
        }

        try {
            // Step 1: Migrate credentials
            $v1Settings = $this->detector->getV1Settings();
            $credentialsMigrated = $this->migrateCredentials($v1Settings);

            if ($credentialsMigrated) {
                $migrated['credentials'] = [
                    'organization' => $v1Settings['organization'],
                    'project' => $v1Settings['project'],
                    'endpoint' => $v1Settings['endpoint'],
                    'token' => '***' . substr($v1Settings['token'], -4), // Masked
                ];
            } else {
                $errors[] = 'Failed to migrate credentials';
            }

            // Step 2: Set default mode
            $this->settings->setMode('static');
            $migrated['mode'] = 'static';

            // Step 3: Set default sync settings
            $syncSettings = [
                'auto_sync' => true,
                'auto_purge' => true,
                'sync_drafts' => false,
            ];
            $this->settings->setSyncSettings($syncSettings);
            $migrated['sync_settings'] = $syncSettings;

            // Step 4: Migrate static config (webserver, 404, custom routes, domain rewrites)
            $staticConfig = [
                'webserver_url' => $v1Settings['webserver_url'],
                'webserver_host' => $v1Settings['webserver_host'],
                'route_404' => $v1Settings['route_404'],
                'custom_routes' => $v1Settings['custom_routes'],
                'domain_rewrites' => $v1Settings['domain_rewrites'],
            ];
            $this->settings->setStaticConfig($staticConfig);
            $migrated['static_config'] = [
                'webserver_url' => $staticConfig['webserver_url'] ?: '(not set)',
                'webserver_host' => $staticConfig['webserver_host'] ?: '(not set)',
                'route_404' => $staticConfig['route_404'],
                'custom_routes_count' => count($staticConfig['custom_routes']),
                'domain_rewrites_count' => count($staticConfig['domain_rewrites']),
            ];

            // Step 5: Mark migration as complete
            $this->markMigrationComplete();
            $migrated['migration_date'] = gmdate('Y-m-d H:i:s');

        } catch (\Exception $e) {
            $errors[] = 'Migration error: ' . $e->getMessage();
            return MigrationResult::failure($errors, $migrated);
        }

        if (!empty($errors)) {
            return MigrationResult::failure($errors, $migrated);
        }

        return MigrationResult::success($migrated);
    }

    /**
     * Clean up v1 options and tables.
     */
    public function cleanup(): bool
    {
        try {
            // Delete v1 options
            foreach ($this->detector->getV1OptionNames() as $option) {
                delete_option($option);
            }

            // Drop v1 table if it exists
            if ($this->detector->hasV1Table()) {
                $tableName = $this->detector->getV1TableName();
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe
                $this->wpdb->query("DROP TABLE IF EXISTS {$tableName}");
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Reset migration status (for testing or re-migration).
     */
    public function resetMigration(): void
    {
        delete_option(self::OPTION_MIGRATION_COMPLETE);
        delete_option(self::OPTION_MIGRATION_DATE);
    }

    /**
     * Get migration date if completed.
     */
    public function getMigrationDate(): ?string
    {
        $date = get_option(self::OPTION_MIGRATION_DATE, null);
        return $date !== null && $date !== false ? (string) $date : null;
    }

    /**
     * Migrate credentials from v1 format to v2.
     *
     * @param array{organization: string, project: string, token: string, endpoint: string} $v1Settings
     */
    private function migrateCredentials(array $v1Settings): bool
    {
        if (empty($v1Settings['organization']) || empty($v1Settings['project']) || empty($v1Settings['token'])) {
            return false;
        }

        $credentials = [
            'organization' => $v1Settings['organization'],
            'project' => $v1Settings['project'],
            'token' => $v1Settings['token'],
            'endpoint' => $v1Settings['endpoint'] ?: 'https://api.quantcdn.io',
        ];

        $this->settings->setCredentials($credentials);

        return true;
    }

    /**
     * Mark migration as complete.
     */
    private function markMigrationComplete(): void
    {
        update_option(self::OPTION_MIGRATION_COMPLETE, true);
        update_option(self::OPTION_MIGRATION_DATE, gmdate('Y-m-d H:i:s'));
    }
}
