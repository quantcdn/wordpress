<?php

declare(strict_types=1);

namespace Quant\Core;

use Quant\Api;
use Quant\Admin;
use Quant\Auth;
use Quant\CLI;
use Quant\Hook;
use Quant\Migration;
use Quant\Sync;

class Plugin
{
    private static ?self $instance = null;
    private Container $container;
    private bool $initialized = false;

    private function __construct()
    {
        $this->container = new Container();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->registerServices();
        $this->registerHooks();
        $this->initialized = true;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    private function registerServices(): void
    {
        // Core services
        $this->container->singleton(Settings::class, fn() => new Settings());

        // HTTP client
        $this->container->singleton(
            Api\HttpClientInterface::class,
            fn() => new Api\WordPressHttpClient()
        );

        // API client
        $this->container->singleton(
            Api\ContentApiInterface::class,
            fn($c) => new Api\QuantContentApi(
                $c->get(Settings::class),
                $c->get(Api\HttpClientInterface::class)
            )
        );

        // REST controller
        $this->container->singleton(
            Admin\RestController::class,
            fn($c) => new Admin\RestController(
                $c->get(Api\ContentApiInterface::class),
                $c->get(Settings::class),
                $c->get(Sync\Renderer\ContentRendererInterface::class)
            )
        );

        // Migration services
        $this->container->singleton(
            Migration\V1Detector::class,
            function () {
                global $wpdb;
                return new Migration\V1Detector($wpdb);
            }
        );

        $this->container->singleton(
            Migration\V1Migrator::class,
            function ($c) {
                global $wpdb;
                return new Migration\V1Migrator(
                    $c->get(Migration\V1Detector::class),
                    $c->get(Settings::class),
                    $wpdb
                );
            }
        );

        // Diagnostics controller
        $this->container->singleton(
            Admin\DiagnosticsController::class,
            fn($c) => new Admin\DiagnosticsController(
                $c->get(Api\ContentApiInterface::class),
                $c->get(Settings::class),
                $c->get(Migration\V1Detector::class),
                $c->get(Migration\V1Migrator::class)
            )
        );

        // Hook subscribers
        $this->container->singleton(
            Hook\CachePurgeSubscriber::class,
            fn($c) => new Hook\CachePurgeSubscriber(
                $c->get(Api\ContentApiInterface::class),
                $c->get(Settings::class)
            )
        );

        // Sync services
        $this->container->singleton(
            Sync\SyncQueueInterface::class,
            function () {
                global $wpdb;
                return new Sync\DatabaseQueue($wpdb);
            }
        );

        $this->container->singleton(
            Sync\Renderer\ContentRendererInterface::class,
            fn() => new Sync\Renderer\PostRenderer()
        );

        // CLI commands
        $this->container->singleton(
            CLI\Commands::class,
            fn($c) => new CLI\Commands(
                $c->get(Api\ContentApiInterface::class),
                $c->get(Settings::class),
                $c->get(Sync\SyncQueueInterface::class),
                $c->get(Sync\Renderer\ContentRendererInterface::class)
            )
        );

        // OAuth controller
        $this->container->singleton(
            Auth\OAuthController::class,
            fn($c) => new Auth\OAuthController(
                $c->get(Settings::class)
            )
        );
    }

    private function registerHooks(): void
    {
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', [$this, 'registerAdminMenu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

            // OAuth admin hooks (callback page)
            $this->container->get(Auth\OAuthController::class)->registerAdmin();
        }

        // REST API
        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        // Cache purge hooks (both modes)
        $this->container->get(Hook\CachePurgeSubscriber::class)->register();

        // Internal URL fetch filter (handles Docker/dev environments)
        add_filter('quant_internal_fetch_url', [$this, 'adjustInternalFetchUrl']);

        // Validation endpoint for internal connectivity test (early hook)
        add_action('init', [$this, 'handleValidationRequest'], 1);

        // WP-CLI commands
        $this->registerCliCommands();
    }

    /**
     * Handle the /__quant-validate endpoint for internal connectivity testing.
     */
    public function handleValidationRequest(): void
    {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return;
        }

        $path = wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        if ($path !== '/__quant-validate') {
            return;
        }

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        echo 'quant-ok';
        exit;
    }

    private function registerCliCommands(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        \WP_CLI::add_command('quant', $this->container->get(CLI\Commands::class));
    }

    /**
     * Adjust URLs for internal fetching.
     * Uses webserver_url from settings if configured, otherwise adjusts localhost ports.
     */
    public function adjustInternalFetchUrl(string $url): string
    {
        $settings = $this->container->get(Settings::class);
        $staticConfig = $settings->getStaticConfig();
        $webserverUrl = $staticConfig['webserver_url'] ?? '';

        $parsed = wp_parse_url($url);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';

        // If webserver_url is configured, use it
        if (!empty($webserverUrl)) {
            return rtrim($webserverUrl, '/') . $path . $query;
        }

        // Otherwise, adjust localhost URLs with non-standard ports (Docker fix)
        if (isset($parsed['host']) && $parsed['host'] === 'localhost' && isset($parsed['port']) && $parsed['port'] !== 80) {
            return 'http://localhost' . $path . $query;
        }

        return $url;
    }

    public function registerAdminMenu(): void
    {
        add_menu_page(
            __('QuantCDN', 'quant'),
            __('QuantCDN', 'quant'),
            'manage_options',
            'quant',
            [$this, 'renderAdminPage'],
            'dashicons-cloud',
            80
        );
    }

    public function renderAdminPage(): void
    {
        echo '<div id="quant-admin-root"></div>';
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_quant') {
            return;
        }

        $assetFile = QUANT_PLUGIN_DIR . 'assets/build/index.asset.php';

        if (!file_exists($assetFile)) {
            // Development fallback
            $assets = [
                'dependencies' => ['wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n'],
                'version' => QUANT_VERSION,
            ];
        } else {
            $assets = require $assetFile;
        }

        wp_enqueue_script(
            'quant-admin',
            QUANT_PLUGIN_URL . 'assets/build/index.js',
            $assets['dependencies'],
            $assets['version'],
            true
        );

        wp_enqueue_style(
            'quant-admin',
            QUANT_PLUGIN_URL . 'assets/build/index.css',
            ['wp-components'],
            $assets['version']
        );

        wp_localize_script('quant-admin', 'quantAdmin', [
            'restUrl' => rest_url('quant/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => QUANT_VERSION,
        ]);
    }

    public function registerRestRoutes(): void
    {
        $this->container->get(Admin\RestController::class)->register();
        $this->container->get(Admin\DiagnosticsController::class)->register();
        $this->container->get(Auth\OAuthController::class)->register();
    }
}
