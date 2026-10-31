<?php

declare(strict_types=1);

namespace Quant\Migration;

use Quant\Core\Settings;
use wpdb;

/**
 * Copies v1 settings into the v2 options. The v1 options stay until the
 * administrator chooses to clean them up.
 */
final class V1Migrator
{
    private const OPTION_MIGRATION_COMPLETE = 'quant_migration_complete';
    private const OPTION_MIGRATION_DATE = 'quant_migration_date';
    private const OPTION_MIGRATION_SOURCE = 'quant_migrated_from';

    public function __construct(
        private readonly V1Detector $detector,
        private readonly Settings $settings,
        private readonly wpdb $wpdb
    ) {
    }

    public function isMigrationComplete(): bool
    {
        return (bool) get_option(self::OPTION_MIGRATION_COMPLETE, false);
    }

    public function migrate(): MigrationResult
    {
        $blocker = $this->migrationBlocker();
        if ($blocker !== null) {
            return MigrationResult::failure([$blocker]);
        }

        $v1 = $this->detector->getV1Settings();

        $this->settings->setCredentials([
            'organization' => $v1['organization'],
            'project' => $v1['project'],
            'token' => $v1['token'],
            'endpoint' => $v1['endpoint'] ?: Settings::DEFAULT_ENDPOINT,
            'disable_tls_verify' => $v1['disable_tls_verify'],
            'http_request_timeout' => $v1['http_request_timeout'],
        ]);

        $this->settings->setMode(Settings::MODE_STATIC);

        $this->settings->setSyncSettings([
            'auto_sync' => $v1['auto_sync'],
            'auto_purge' => $v1['auto_purge'],
            'sync_drafts' => false,
        ]);

        $this->settings->setStaticConfig([
            'webserver_url' => $v1['webserver_url'],
            'webserver_host' => $v1['webserver_host'],
            'route_404' => $v1['route_404'] ?: '/404',
            'custom_routes' => $v1['custom_routes'],
            'domain_rewrites' => $v1['domain_rewrites'],
        ]);

        $this->settings->setSchedule($v1['schedule']);

        $this->markMigrationComplete();

        return MigrationResult::success($this->summary($v1));
    }

    /**
     * Why a migration cannot run, or null when it can.
     */
    private function migrationBlocker(): ?string
    {
        if (!$this->detector->hasV1Settings()) {
            return 'No v1 settings found to migrate';
        }

        if ($this->isMigrationComplete()) {
            return 'Migration has already been completed';
        }

        $v1 = $this->detector->getV1Settings();
        if ($v1['organization'] === '' || $v1['project'] === '' || $v1['token'] === '') {
            return 'The v1 settings do not contain a complete set of API credentials';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $v1
     * @return array<string, mixed>
     */
    private function summary(array $v1): array
    {
        return [
            'credentials' => [
                'organization' => $v1['organization'],
                'project' => $v1['project'],
                'endpoint' => $v1['endpoint'],
                'token' => '***' . substr($v1['token'], -4),
            ],
            'mode' => Settings::MODE_STATIC,
            'sync_settings' => ['auto_sync' => $v1['auto_sync'], 'auto_purge' => $v1['auto_purge']],
            'static_config' => [
                'webserver_url' => $v1['webserver_url'] ?: '(not set)',
                'webserver_host' => $v1['webserver_host'] ?: '(not set)',
                'route_404' => $v1['route_404'],
                'custom_routes_count' => count($v1['custom_routes']),
                'domain_rewrites_count' => count($v1['domain_rewrites']),
            ],
            'schedule' => $v1['schedule'],
            'migration_date' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /**
     * Remove the v1 options and batch state.
     */
    public function cleanup(): bool
    {
        foreach ($this->detector->getV1OptionNames() as $option) {
            delete_option($option);
        }

        $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->wpdb->options} WHERE option_name LIKE %s",
                $this->wpdb->esc_like('batch_quant_') . '%'
            )
        );

        return true;
    }

    public function resetMigration(): void
    {
        delete_option(self::OPTION_MIGRATION_COMPLETE);
        delete_option(self::OPTION_MIGRATION_DATE);
        delete_option(self::OPTION_MIGRATION_SOURCE);
    }

    public function getMigrationDate(): ?string
    {
        $date = get_option(self::OPTION_MIGRATION_DATE, null);

        return is_string($date) && $date !== '' ? $date : null;
    }

    private function markMigrationComplete(): void
    {
        update_option(self::OPTION_MIGRATION_COMPLETE, true);
        update_option(self::OPTION_MIGRATION_DATE, gmdate('Y-m-d H:i:s'));
        update_option(self::OPTION_MIGRATION_SOURCE, '1.x');
    }
}
