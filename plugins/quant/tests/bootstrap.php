<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
// Patchwork first, so Brain Monkey can redefine the fallbacks below per test.
require_once dirname(__DIR__) . '/vendor/antecedent/patchwork/Patchwork.php';
require_once __DIR__ . '/Support/wp-fallbacks.php';
require_once __DIR__ . '/Support/FakeRedirection.php';

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

foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'KB_IN_BYTES' => 1024, 'MB_IN_BYTES' => 1048576] as $quantConstant => $quantValue) {
    if (!defined($quantConstant)) {
        define($quantConstant, $quantValue);
    }
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
        public string $post_password = '';
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

// Stub WP_Term class for testing
if (!class_exists('WP_Term')) {
    // phpcs:ignore
    class WP_Term
    {
        public int $term_id = 0;
        public string $name = '';
        public string $slug = '';
        public string $taxonomy = 'category';
        public int $count = 0;
        public int $parent = 0;
        public string $description = '';
    }
}

// Stub WP_Taxonomy class for testing
if (!class_exists('WP_Taxonomy')) {
    // phpcs:ignore
    class WP_Taxonomy
    {
        public string $name = '';
        public bool $public = true;
        public ?bool $publicly_queryable = true;
        public object $labels;

        public function __construct(string $name = '', bool $public = true, ?bool $publiclyQueryable = true)
        {
            $this->name = $name;
            $this->public = $public;
            $this->publicly_queryable = $publiclyQueryable;
            $this->labels = (object) ['name' => ucfirst($name), 'singular_name' => ucfirst($name)];
        }
    }
}

// Stub WP_Post_Type class for testing
if (!class_exists('WP_Post_Type')) {
    // phpcs:ignore
    class WP_Post_Type
    {
        public string $name = '';
        public bool $public = true;
        public ?bool $publicly_queryable = true;
        public bool|string $has_archive = false;
        public object $labels;

        public function __construct(string $name = '', bool $public = true, ?bool $publiclyQueryable = true, bool|string $hasArchive = false)
        {
            $this->name = $name;
            $this->public = $public;
            $this->publicly_queryable = $publiclyQueryable;
            $this->has_archive = $hasArchive;
            $this->labels = (object) ['name' => ucfirst($name) . 's', 'singular_name' => ucfirst($name)];
        }
    }
}

// Stub WP_REST_Request class for testing
if (!class_exists('WP_REST_Request')) {
    // phpcs:ignore
    class WP_REST_Request
    {
        /** @param array<string, mixed> $params */
        public function __construct(private array $params = [], private ?array $json = null)
        {
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function get_params(): array
        {
            return $this->params;
        }

        public function get_json_params(): ?array
        {
            return $this->json;
        }
    }
}

// Stub WP_Admin_Bar class for testing
if (!class_exists('WP_Admin_Bar')) {
    // phpcs:ignore
    class WP_Admin_Bar
    {
        public array $nodes = [];

        public function add_node(array $node): void
        {
            $this->nodes[] = $node;
        }
    }
}

// Stub WP_User class for testing
if (!class_exists('WP_User')) {
    // phpcs:ignore
    class WP_User
    {
        public int $ID = 0;
    }
}
