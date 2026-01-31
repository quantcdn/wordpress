<?php

declare(strict_types=1);

namespace Quant\Core;

class Activator
{
    public static function activate(): void
    {
        self::createTables();
        self::setDefaultOptions();

        flush_rewrite_rules();
    }

    private static function createTables(): void
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $tableName = $wpdb->prefix . 'quant_sync_queue';

        $sql = "CREATE TABLE {$tableName} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id VARCHAR(36) NOT NULL,
            type VARCHAR(20) NOT NULL,
            object_id VARCHAR(255) NOT NULL,
            action VARCHAR(20) NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            attempts TINYINT DEFAULT 0,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            INDEX idx_status (status),
            INDEX idx_type_object (type, object_id)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('quant_db_version', '2.0.0');
    }

    private static function setDefaultOptions(): void
    {
        if (get_option('quant_version') === false) {
            update_option('quant_version', QUANT_VERSION);
        }
    }
}
