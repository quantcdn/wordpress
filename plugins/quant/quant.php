<?php
/**
 * Plugin Name: QuantCDN
 * Plugin URI: https://www.quantcdn.io/integrations/wordpress
 * Description: Static site generation and CDN integration for WordPress
 * Version: 2.0.0
 * Requires PHP: 8.1
 * Requires at least: 6.0
 * Author: QuantCDN
 * Author URI: https://www.quantcdn.io
 * License: GPL-2.0-or-later
 * Text Domain: quant
 */

declare(strict_types=1);

namespace Quant;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('QUANT_VERSION', '2.0.0');
define('QUANT_PLUGIN_FILE', __FILE__);
define('QUANT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('QUANT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Legacy constants for migration compatibility
define('QUANT_SETTINGS_KEY', 'quant_settings');
define('QUANT_CRON_SETTINGS_KEY', 'quant_cron_settings');
define('QUANT_SEED_KEY', 'quant_seed');

// Autoloader
if (file_exists(QUANT_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once QUANT_PLUGIN_DIR . 'vendor/autoload.php';
}

// Bootstrap
add_action('plugins_loaded', static function (): void {
    if (!class_exists(Core\Plugin::class)) {
        add_action('admin_notices', static function (): void {
            echo '<div class="error"><p>';
            echo esc_html__('QuantCDN requires Composer dependencies. Run: composer install', 'quant');
            echo '</p></div>';
        });
        return;
    }

    Core\Plugin::getInstance()->init();
});

// Activation hook
register_activation_hook(__FILE__, static function (): void {
    if (class_exists(Core\Activator::class)) {
        Core\Activator::activate();
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, static function (): void {
    if (class_exists(Core\Deactivator::class)) {
        Core\Deactivator::deactivate();
    }
});
