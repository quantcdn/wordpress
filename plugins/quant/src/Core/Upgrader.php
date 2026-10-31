<?php

declare(strict_types=1);

namespace Quant\Core;

use Quant\Migration\V1Detector;
use Quant\Migration\V1Migrator;

/**
 * Runs once per plugin version: schema upgrade and the automatic v1 migration.
 *
 * Existing customers update the plugin and keep working. Their v1 settings
 * are copied to the v2 options on the first request after the update, and a
 * dismissible notice points at the review page.
 */
class Upgrader
{
    public const OPTION_VERSION = 'quant_version';
    public const OPTION_NOTICE = 'quant_upgrade_notice';

    public function __construct(
        private readonly V1Detector $detector,
        private readonly V1Migrator $migrator
    ) {
    }

    public function register(): void
    {
        add_action('init', [$this, 'maybeUpgrade'], 1);
        add_action('admin_notices', [$this, 'renderNotice']);
        add_action('admin_post_quant_dismiss_upgrade_notice', [$this, 'dismissNotice']);
    }

    public function maybeUpgrade(): void
    {
        $installed = (string) get_option(self::OPTION_VERSION, '');

        if ($installed === QUANT_VERSION && get_option('quant_db_version') === Activator::DB_VERSION) {
            return;
        }

        Activator::maybeUpgrade();
        $this->migrateFromV1();

        update_option(self::OPTION_VERSION, QUANT_VERSION);
    }

    public function renderNotice(): void
    {
        $notice = get_option(self::OPTION_NOTICE);
        if (!is_array($notice) || !current_user_can('manage_options')) {
            return;
        }

        $dismiss = wp_nonce_url(admin_url('admin-post.php?action=quant_dismiss_upgrade_notice'), 'quant_dismiss_upgrade_notice');

        printf(
            '<div class="notice notice-%s"><p>%s <a href="%s">%s</a> <a href="%s" style="margin-left:1em">%s</a></p></div>',
            esc_attr($notice['type'] ?? 'info'),
            esc_html((string) ($notice['message'] ?? '')),
            esc_url(admin_url('admin.php?page=quant#/migration')),
            esc_html__('Review settings', 'quant'),
            esc_url($dismiss),
            esc_html__('Dismiss', 'quant')
        );
    }

    public function dismissNotice(): void
    {
        check_admin_referer('quant_dismiss_upgrade_notice');
        delete_option(self::OPTION_NOTICE);
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    private function migrateFromV1(): void
    {
        if (!$this->detector->needsMigration() || $this->migrator->isMigrationComplete()) {
            return;
        }

        $result = $this->migrator->migrate();

        update_option(self::OPTION_NOTICE, $result->success
            ? [
                'type' => 'success',
                'message' => __('QuantCDN 2.0: your existing settings were migrated automatically. Content pushes continue to work.', 'quant'),
            ]
            : [
                'type' => 'warning',
                'message' => __('QuantCDN 2.0 could not migrate your 1.x settings automatically: ', 'quant') . implode('; ', $result->errors),
            ], false);
    }
}
