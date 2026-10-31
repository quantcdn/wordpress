<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Removes every option, transient and table the v2 plugin created, and the
 * v1 options that the migration may have left behind.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$quantOptions = [
    // v2
    'quant_version',
    'quant_db_version',
    'quant_mode',
    'quant_credentials',
    'quant_static_config',
    'quant_sync_settings',
    'quant_schedule',
    'quant_settings',
    'quant_internal_token',
    'quant_migration_complete',
    'quant_migration_date',
    'quant_oauth_token',
    'quant_oauth_user',
    'quant_oauth_organizations',
    // v1
    'wp_quant_settings',
    'wp_quant_seed',
    'wp_quant_cron_settings',
];

foreach ($quantOptions as $option) {
    delete_option($option);
}

// v1 batch-processing state lived in wp_options as batch_quant_*_processed / _claimed.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('batch_quant_') . '%'
    )
);

// OAuth state transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_quant_oauth_state_') . '%',
        $wpdb->esc_like('_transient_timeout_quant_oauth_state_') . '%'
    )
);

wp_clear_scheduled_hook('quant_process_queue');
wp_clear_scheduled_hook('quant_scheduled_sync');

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}quant_sync_queue");
