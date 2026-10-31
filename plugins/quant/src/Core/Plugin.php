<?php

declare(strict_types=1);

namespace Quant\Core;

use Quant\Admin;
use Quant\Api;
use Quant\Auth;
use Quant\CLI;
use Quant\Hook;
use Quant\Migration;
use Quant\Redirects\RedirectProvider;
use Quant\Search;
use Quant\Sync;

/**
 * Plugin bootstrap: builds the service container and registers hooks.
 */
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
        $c = $this->container;

        $c->singleton(Settings::class, static fn(): Settings => new Settings());

        $c->singleton(Api\HttpClientInterface::class, static fn(Container $c): Api\HttpClientInterface => new Api\WordPressHttpClient(
            static function () use ($c): array {
                $credentials = $c->get(Settings::class)->getCredentials();
                return [
                    'timeout' => $credentials['http_request_timeout'],
                    'sslverify' => !$credentials['disable_tls_verify'],
                ];
            }
        ));

        $c->singleton(Api\ContentApiInterface::class, static fn(Container $c): Api\ContentApiInterface => new Api\QuantContentApi(
            $c->get(Settings::class),
            $c->get(Api\HttpClientInterface::class)
        ));

        $c->singleton(Sync\SyncQueueInterface::class, static function (): Sync\SyncQueueInterface {
            global $wpdb;
            return new Sync\DatabaseQueue($wpdb);
        });
        $c->singleton(Sync\DatabaseQueue::class, static fn(Container $c): Sync\DatabaseQueue => $c->get(Sync\SyncQueueInterface::class));

        $this->registerSyncServices();
        $this->registerSearchServices();
        $this->registerAdminServices();
    }

    private function registerSyncServices(): void
    {
        $c = $this->container;

        $c->singleton(Sync\InternalFetcher::class, static fn(Container $c) => new Sync\InternalFetcher($c->get(Settings::class)));
        $c->singleton(Sync\MarkupProcessor::class, static fn(Container $c) => new Sync\MarkupProcessor($c->get(Settings::class)));
        $c->singleton(Sync\Renderer\RouteRenderer::class, static fn(Container $c) => new Sync\Renderer\RouteRenderer(
            $c->get(Sync\InternalFetcher::class),
            $c->get(Sync\MarkupProcessor::class),
            $c->get(Settings::class)
        ));
        $c->singleton(Sync\Renderer\ContentRendererInterface::class, static fn(Container $c) => new Sync\Renderer\PostRenderer($c->get(Sync\Renderer\RouteRenderer::class)));
        $c->singleton(Sync\Renderer\TermRenderer::class, static fn(Container $c) => new Sync\Renderer\TermRenderer($c->get(Sync\Renderer\RouteRenderer::class)));
        $c->singleton(Sync\AttachmentUploader::class, static fn(Container $c) => new Sync\AttachmentUploader($c->get(Api\ContentApiInterface::class)));
        $c->singleton(Sync\Publisher::class, static fn(Container $c) => new Sync\Publisher(
            $c->get(Api\ContentApiInterface::class),
            $c->get(Settings::class),
            $c->get(Sync\SyncQueueInterface::class),
            $c->get(Sync\AttachmentUploader::class)
        ));
        $c->singleton(Sync\MediaSync::class, static fn(Container $c) => new Sync\MediaSync($c->get(Api\ContentApiInterface::class)));
        $c->singleton(Sync\ThemeAssetSync::class, static fn(Container $c) => new Sync\ThemeAssetSync($c->get(Api\ContentApiInterface::class)));
        $c->singleton(Sync\SitemapDiscovery::class, static fn(Container $c) => new Sync\SitemapDiscovery($c->get(Sync\InternalFetcher::class)));
        $c->singleton(RedirectProvider::class, static fn() => new RedirectProvider());

        $c->singleton(Sync\JobProcessor::class, fn(Container $c) => new Sync\JobProcessor(
            $c->get(Sync\SyncQueueInterface::class),
            $c->get(Settings::class),
            $this->buildHandlers($c)
        ));

        $c->singleton(Sync\Seeder::class, static fn(Container $c) => new Sync\Seeder(
            $c->get(Sync\SyncQueueInterface::class),
            $c->get(Settings::class),
            $c->get(Sync\ThemeAssetSync::class),
            $c->get(Sync\SitemapDiscovery::class),
            $c->get(RedirectProvider::class),
            $c->get(Search\SearchRecordBuilder::class)
        ));

        $c->singleton(Scheduler::class, static fn(Container $c) => new Scheduler(
            $c->get(Settings::class),
            $c->get(Sync\SyncQueueInterface::class),
            $c->get(Sync\JobProcessor::class),
            $c->get(Sync\Seeder::class)
        ));

        $c->singleton(Hook\ContentSyncSubscriber::class, static fn(Container $c) => new Hook\ContentSyncSubscriber(
            $c->get(Sync\SyncQueueInterface::class),
            $c->get(Settings::class)
        ));
        $c->singleton(Hook\RedirectionSubscriber::class, static fn(Container $c) => new Hook\RedirectionSubscriber(
            $c->get(Sync\SyncQueueInterface::class),
            $c->get(Settings::class),
            $c->get(RedirectProvider::class)
        ));
        $c->singleton(Hook\CachePurgeSubscriber::class, static fn(Container $c) => new Hook\CachePurgeSubscriber(
            $c->get(Api\ContentApiInterface::class),
            $c->get(Settings::class)
        ));
        $c->singleton(Hook\InternalRequestHandler::class, static fn() => new Hook\InternalRequestHandler());
    }

    /**
     * The job handlers in dispatch order.
     *
     * @return array<Sync\Handler\HandlerInterface>
     */
    private function buildHandlers(Container $c): array
    {
        return [
            new Sync\Handler\PostPublishHandler(
                $c->get(Sync\Renderer\ContentRendererInterface::class),
                $c->get(Sync\Publisher::class),
                $c->get(Search\SearchRecordBuilder::class),
                $c->get(Sync\SyncQueueInterface::class)
            ),
            new Sync\Handler\TermPublishHandler(
                $c->get(Sync\Renderer\TermRenderer::class),
                $c->get(Sync\Publisher::class),
                $c->get(Sync\SyncQueueInterface::class)
            ),
            new Sync\Handler\RoutePublishHandler(
                $c->get(Sync\Renderer\RouteRenderer::class),
                $c->get(Sync\Publisher::class),
                $c->get(Api\ContentApiInterface::class),
                $c->get(Sync\SyncQueueInterface::class)
            ),
            new Sync\Handler\FileHandler(
                $c->get(Api\ContentApiInterface::class),
                $c->get(Sync\MediaSync::class),
                $c->get(Sync\ThemeAssetSync::class)
            ),
            new Sync\Handler\RedirectHandler($c->get(Api\ContentApiInterface::class)),
            new Sync\Handler\SearchRecordHandler($c->get(Api\ContentApiInterface::class), $c->get(Search\SearchRecordBuilder::class)),
            new Sync\Handler\UnpublishHandler($c->get(Sync\Publisher::class)),
            new Sync\Handler\PurgeHandler($c->get(Api\ContentApiInterface::class)),
        ];
    }

    private function registerSearchServices(): void
    {
        $c = $this->container;

        $c->singleton(Search\SearchRecordBuilder::class, static fn(Container $c) => new Search\SearchRecordBuilder($c->get(Settings::class)));
        $c->singleton(Search\SearchPageRepository::class, static fn() => new Search\SearchPageRepository());
        $c->singleton(Search\SearchPageSync::class, static fn(Container $c) => new Search\SearchPageSync(
            $c->get(Search\SearchPageRepository::class),
            $c->get(Api\ContentApiInterface::class),
            $c->get(Sync\SyncQueueInterface::class),
            $c->get(Search\SearchRecordBuilder::class)
        ));
        $c->singleton(Search\Frontend::class, static fn(Container $c) => new Search\Frontend(
            $c->get(Settings::class),
            $c->get(Search\SearchPageRepository::class),
            $c->get(Search\SearchRecordBuilder::class),
            $c->get(Api\ContentApiInterface::class)
        ));
    }

    private function registerAdminServices(): void
    {
        $c = $this->container;

        $c->singleton(Migration\V1Detector::class, static function (): Migration\V1Detector {
            global $wpdb;
            return new Migration\V1Detector($wpdb);
        });
        $c->singleton(Migration\V1Migrator::class, static function (Container $c): Migration\V1Migrator {
            global $wpdb;
            return new Migration\V1Migrator($c->get(Migration\V1Detector::class), $c->get(Settings::class), $wpdb);
        });
        $c->singleton(Upgrader::class, static fn(Container $c) => new Upgrader(
            $c->get(Migration\V1Detector::class),
            $c->get(Migration\V1Migrator::class)
        ));

        $c->singleton(Admin\RestController::class, static fn(Container $c) => new Admin\RestController(
            $c->get(Api\ContentApiInterface::class),
            $c->get(Settings::class),
            $c->get(Sync\DatabaseQueue::class),
            $c->get(Sync\JobProcessor::class),
            $c->get(Sync\Seeder::class),
            $c->get(Sync\ThemeAssetSync::class),
            $c->get(RedirectProvider::class),
            $c->get(Sync\InternalFetcher::class)
        ));
        $c->singleton(Admin\DiagnosticsController::class, static fn(Container $c) => new Admin\DiagnosticsController(
            $c->get(Api\ContentApiInterface::class),
            $c->get(Settings::class),
            $c->get(Migration\V1Detector::class),
            $c->get(Migration\V1Migrator::class),
            $c->get(Sync\DatabaseQueue::class)
        ));
        $c->singleton(Admin\SearchController::class, static fn(Container $c) => new Admin\SearchController(
            $c->get(Api\ContentApiInterface::class),
            $c->get(Settings::class),
            $c->get(Search\SearchPageRepository::class),
            $c->get(Search\SearchPageSync::class),
            $c->get(Search\SearchRecordBuilder::class),
            $c->get(Sync\Seeder::class)
        ));
        $c->singleton(Admin\SyncStatusUi::class, static fn(Container $c) => new Admin\SyncStatusUi(
            $c->get(Settings::class),
            $c->get(Sync\DatabaseQueue::class)
        ));
        $c->singleton(Auth\OAuthController::class, static fn(Container $c) => new Auth\OAuthController($c->get(Settings::class)));

        $c->singleton(CLI\Commands::class, static fn(Container $c) => new CLI\Commands(
            $c->get(Api\ContentApiInterface::class),
            $c->get(Settings::class),
            $c->get(Sync\SyncQueueInterface::class),
            $c->get(Sync\JobProcessor::class),
            $c->get(Sync\Seeder::class),
            $c->get(Search\SearchRecordBuilder::class)
        ));
    }

    private function registerHooks(): void
    {
        $c = $this->container;

        $c->get(Upgrader::class)->register();
        $c->get(Hook\InternalRequestHandler::class)->register();
        $c->get(Hook\ContentSyncSubscriber::class)->register();
        $c->get(Hook\RedirectionSubscriber::class)->register();
        $c->get(Hook\CachePurgeSubscriber::class)->register();
        $c->get(Scheduler::class)->register();
        $c->get(Search\Frontend::class)->register();
        $c->get(Admin\SyncStatusUi::class)->register();

        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('update_option_' . Settings::OPTION_CREDENTIALS, [Search\Frontend::class, 'clearIndexCache']);

        if (is_admin()) {
            add_action('admin_menu', [$this, 'registerAdminMenu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
            add_action('admin_init', [$this, 'redirectLegacySettingsPage']);
            $c->get(Auth\OAuthController::class)->registerAdmin();
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('quant', $c->get(CLI\Commands::class));
        }
    }

    public function registerRestRoutes(): void
    {
        $this->container->get(Admin\RestController::class)->register();
        $this->container->get(Admin\DiagnosticsController::class)->register();
        $this->container->get(Admin\SearchController::class)->register();
        $this->container->get(Auth\OAuthController::class)->register();
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

        $sections = [
            'dashboard' => __('Dashboard', 'quant'),
            'content' => __('Content', 'quant'),
            'search' => __('Search', 'quant'),
            'cache' => __('Cache', 'quant'),
            'settings' => __('Settings', 'quant'),
            'diagnostics' => __('Diagnostics', 'quant'),
        ];

        foreach ($sections as $slug => $label) {
            add_submenu_page('quant', $label, $label, 'manage_options', 'quant#/' . $slug, [$this, 'renderAdminPage']);
        }
        remove_submenu_page('quant', 'quant');
    }

    /**
     * v1 lived under Settings > QuantCDN. Send bookmarks to the new page.
     */
    public function redirectLegacySettingsPage(): void
    {
        global $pagenow;

        if ($pagenow === 'options-general.php' && ($_GET['page'] ?? '') === 'quant') {
            wp_safe_redirect(admin_url('admin.php?page=quant'));
            exit;
        }
    }

    public function renderAdminPage(): void
    {
        echo '<div id="quant-admin-root"></div>';
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if (!str_contains($hook, 'page_quant')) {
            return;
        }

        $assetFile = QUANT_PLUGIN_DIR . 'assets/build/index.asset.php';
        $assets = file_exists($assetFile)
            ? require $assetFile
            : ['dependencies' => ['wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n'], 'version' => QUANT_VERSION];

        wp_enqueue_script('quant-admin', QUANT_PLUGIN_URL . 'assets/build/index.js', $assets['dependencies'], $assets['version'], true);
        wp_enqueue_style('quant-admin', QUANT_PLUGIN_URL . 'assets/build/index.css', ['wp-components'], $assets['version']);

        wp_localize_script('quant-admin', 'quantAdmin', [
            'restUrl' => rest_url('quant/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => QUANT_VERSION,
            'homeUrl' => home_url('/'),
            'adminUrl' => admin_url('admin.php?page=quant'),
        ]);
    }
}
