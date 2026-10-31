<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes every option, transient, cron event and table the plugin created,
 * and the 1.x options the migration may have left behind, on every site of
 * a multisite network.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up the current site.
 */
function quant_uninstall_site(): void
{
    global $wpdb;

    $options = [
        // 2.x
        'quant_version',
        'quant_db_version',
        'quant_mode',
        'quant_credentials',
        'quant_static_config',
        'quant_sync_settings',
        'quant_schedule',
        'quant_search_settings',
        'quant_search_pages',
        'quant_settings',
        'quant_internal_token',
        'quant_migration_complete',
        'quant_migration_date',
        'quant_migrated_from',
        'quant_upgrade_notice',
        'quant_oauth_token',
        'quant_oauth_user',
        'quant_oauth_organizations',
        // 1.x
        'wp_quant_settings',
        'wp_quant_seed',
        'wp_quant_cron_settings',
    ];

    foreach ($options as $option) {
        delete_option($option);
    }

    delete_transient('quant_search_index_config');
    delete_transient('quant_queue_maintenance');

    // 1.x batch state (batch_quant_*) and OAuth state transients.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('batch_quant_') . '%',
            $wpdb->esc_like('_transient_quant_oauth_state_') . '%',
            $wpdb->esc_like('_transient_timeout_quant_oauth_state_') . '%'
        )
    );

    foreach (['quant_process_queue', 'quant_process_queue_soon', 'quant_scheduled_sync', 'quant_cronjob'] as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}quant_sync_queue");
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $quant_site_id) {
        switch_to_blog((int) $quant_site_id);
        quant_uninstall_site();
        restore_current_blog();
    }
} else {
    quant_uninstall_site();
}
