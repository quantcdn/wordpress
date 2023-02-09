<?php

namespace Quant;

use Quant\SettingsScreen;
use Quant\Settings;

final class App
{
    /**
     * Singleton instance
     *
     * @var null|App
     */
    private static $instance = null;

    /**
     * Create a new singleton instance
     *
     * @return App
     */
    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Bootstrap the plugin
     *
     * @return void
     */
    public function __construct()
    {
        $this->constants();
        $this->includes(__DIR__, ['App.php']);
        $this->hooks();
    }

    /**
     * Register constants
     *
     * @return void
     */
    protected function constants()
    {
        define( 'QUANT_SETTINGS_KEY', 'wp_quant_settings' );
        define( 'QUANT_CRON_SETTINGS_KEY', 'wp_quant_cron_settings' );
        define( 'QUANT_SEED_KEY', 'wp_quant_seed' );
        define( 'QUANT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
        define( 'QUANT_TEMPLATE_DIR', QUANT_PLUGIN_DIR . '/../templates/' );
    }

    /**
     * Include/require files
     *
     * @param null $dir
     * @param array $exclude
     *
     * @return void
     */
    protected function includes($dir = null, $exclude = [])
    {
        $dir = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($dir);

        foreach ($files as $file) {
            if (! in_array($file->getFilename(), $exclude)) {
                require_once $file->getRealpath();
            }
        }
    }

    /**
     * Register actions & filters
     *
     * @return void
     */
    protected function hooks()
    {
        SettingsScreen::init();
        Settings::init();
    }


    protected function activate() {}

    protected function deactivate() {}

}


function register_quant_app()
{
    $app = new App();
    register_activation_hook(__FILE__, [$app, 'activate']);
    register_deactivation_hook(__FILE__, [$app, 'deactivate']);
}
register_quant_app();
