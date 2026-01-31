<?php

declare(strict_types=1);

namespace Quant\Core;

use Quant\Api;
use Quant\Admin;
use Quant\Hook;
use Quant\Migration;

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
                $c->get(Settings::class)
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
    }

    private function registerHooks(): void
    {
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', [$this, 'registerAdminMenu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        }

        // REST API
        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        // Cache purge hooks (both modes)
        $this->container->get(Hook\CachePurgeSubscriber::class)->register();
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
    }
}
