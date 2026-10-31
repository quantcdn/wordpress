<?php

declare(strict_types=1);

namespace Quant\Core;

use Quant\Sync\DatabaseQueue;

class Activator
{
    public const DB_VERSION = '2.0.2';

    public static function activate(): void
    {
        self::createTables();
        self::setDefaultOptions();
        self::ensureInternalToken();
    }

    /**
     * Upgrade the schema when the plugin files were updated without a re-activation.
     */
    public static function maybeUpgrade(): void
    {
        if (get_option('quant_db_version') === self::DB_VERSION) {
            return;
        }

        self::createTables();
        self::ensureInternalToken();
    }

    private static function createTables(): void
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $tableName = $wpdb->prefix . DatabaseQueue::TABLE_NAME;

        // dbDelta needs two spaces after PRIMARY KEY and one field per line.
        $sql = "CREATE TABLE {$tableName} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id VARCHAR(36) NOT NULL,
            type VARCHAR(32) NOT NULL,
            object_id VARCHAR(255) NOT NULL,
            action VARCHAR(20) NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            attempts TINYINT DEFAULT 0,
            error_message TEXT NULL,
            meta LONGTEXT NULL,
            claim VARCHAR(36) NULL,
            created_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY idx_status (status),
            KEY idx_type_object (type, object_id(191)),
            KEY idx_job_id (job_id),
            KEY idx_claim (claim)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('quant_db_version', self::DB_VERSION);
    }

    private static function setDefaultOptions(): void
    {
        if (get_option('quant_version') === false) {
            update_option('quant_version', QUANT_VERSION);
        }
    }

    /**
     * A secret the plugin sends on internal render requests so WordPress can
     * recognise them (for example to skip canonical redirects).
     */
    private static function ensureInternalToken(): void
    {
        if (get_option('quant_internal_token')) {
            return;
        }

        update_option('quant_internal_token', bin2hex(random_bytes(16)), false);
    }
}
