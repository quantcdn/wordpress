<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define WordPress constants for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('QUANT_VERSION')) {
    define('QUANT_VERSION', '2.0.0');
}

if (!defined('QUANT_PLUGIN_DIR')) {
    define('QUANT_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!defined('QUANT_PLUGIN_URL')) {
    define('QUANT_PLUGIN_URL', 'http://localhost/wp-content/plugins/quant/');
}

// WordPress constants used by wpdb
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

// Stub WP_Error class for testing
if (!class_exists('WP_Error')) {
    // phpcs:ignore
    class WP_Error
    {
        protected string $code = '';
        protected string $message = '';
        protected array $data = [];

        public function __construct(string $code = '', string $message = '', mixed $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = is_array($data) ? $data : [];
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

// Stub WP_Post class for testing
if (!class_exists('WP_Post')) {
    // phpcs:ignore
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_title = '';
        public string $post_content = '';
        public string $post_excerpt = '';
        public string $post_date = '';
        public string $post_date_gmt = '';
        public string $post_modified = '';
        public string $post_modified_gmt = '';
        public int $post_author = 0;
        public string $post_name = '';
        public int $post_parent = 0;
        public string $guid = '';
        public int $menu_order = 0;
        public string $post_mime_type = '';
        public string $comment_status = 'open';
        public string $ping_status = 'open';
        public int $comment_count = 0;
        public string $filter = 'raw';
    }
}

// Stub wpdb class for testing
if (!class_exists('wpdb')) {
    // phpcs:ignore
    class wpdb
    {
        public string $prefix = '';
        public int $rows_affected = 0;

        public function insert(string $table, array $data, ?array $format = null): int|false
        {
            return 1;
        }

        public function update(string $table, array $data, array $where, ?array $format = null, ?array $where_format = null): int|false
        {
            return 1;
        }

        public function delete(string $table, array $where, ?array $where_format = null): int|false
        {
            return 1;
        }

        public function prepare(string $query, mixed ...$args): string|null
        {
            return '';
        }

        public function query(string $query): int|bool
        {
            return 1;
        }

        public function get_results(string $query, string $output = 'OBJECT'): array|object|null
        {
            return [];
        }

        public function get_charset_collate(): string
        {
            return 'utf8mb4_unicode_ci';
        }
    }
}
