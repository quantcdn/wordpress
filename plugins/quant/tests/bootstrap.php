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
