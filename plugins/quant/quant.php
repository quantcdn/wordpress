<?php
/**
 * Plugin Name: QuantCDN
 * Plugin URI: https://www.quantcdn.io/integrations/wordpress
 * Description: Static site generation and CDN integration for WordPress
 * Version: 2.0.0
 * Requires PHP: 8.2
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

// v1 option names. Kept so the Quant Cloud `app-wordpress` mu-plugin and the
// v1 migration can find the legacy settings.
define('QUANT_SETTINGS_KEY', 'wp_quant_settings');
define('QUANT_CRON_SETTINGS_KEY', 'wp_quant_cron_settings');
define('QUANT_SEED_KEY', 'wp_quant_seed');

// This file must parse on old PHP so the version notice below can show.
if (version_compare(PHP_VERSION, '8.2', '<')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html(sprintf(
            /* translators: %s: current PHP version */
            __('QuantCDN 2.0 requires PHP 8.2 or newer. This server runs PHP %s. The plugin is inactive until PHP is upgraded.', 'quant'),
            PHP_VERSION
        ));
        echo '</p></div>';
    });
    return;
}

// Autoloader: Composer when present, otherwise a plain PSR-4 loader for src/.
if (file_exists(QUANT_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once QUANT_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (strncmp($class, 'Quant\\', 6) !== 0) {
            return;
        }
        $file = QUANT_PLUGIN_DIR . 'src/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

// Bootstrap
add_action('plugins_loaded', static function (): void {
    Core\Plugin::getInstance()->init();
}, 1);

// Activation hook (handles network activation on multisite)
register_activation_hook(__FILE__, static function (bool $networkWide = false): void {
    if (!class_exists(Core\Activator::class)) {
        return;
    }

    if ($networkWide && is_multisite()) {
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
            switch_to_blog((int) $siteId);
            Core\Activator::activate();
            restore_current_blog();
        }
        return;
    }

    Core\Activator::activate();
});

// Deactivation hook
register_deactivation_hook(__FILE__, static function (): void {
    if (class_exists(Core\Deactivator::class)) {
        Core\Deactivator::deactivate();
    }
});

// New sites on a network-activated multisite get their table on creation.
add_action('wp_initialize_site', static function ($site): void {
    if (!function_exists('is_plugin_active_for_network')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!class_exists(Core\Activator::class) || !is_plugin_active_for_network(plugin_basename(__FILE__))) {
        return;
    }
    switch_to_blog((int) $site->blog_id);
    Core\Activator::activate();
    restore_current_blog();
}, 20);
