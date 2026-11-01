# QuantCDN WordPress Plugin v2.0 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete rewrite of QuantCDN WordPress plugin with React UI, two operational modes (Static/CDN-Only), and modern PHP architecture.

**Architecture:** Service container with dependency injection, interface-based API client (SDK-swap-ready), queue-based content sync, React admin via @wordpress/scripts. Settings stored in wp_options, sync queue in custom table.

**Tech Stack:** PHP 8.1+, WordPress 6.0+, React 18, @wordpress/scripts, @wordpress/components, Composer/PSR-4

**Design Document:** `docs/plans/2026-01-31-v2-complete-overhaul-design.md`

---

## Phase 1a: Foundation

### Task 1: Initialize Composer and PSR-4 Autoloading

**Files:**
- Create: `plugins/quant/composer.json`
- Modify: `plugins/quant/quant.php`

**Step 1: Create composer.json**

```json
{
    "name": "quantcdn/wordpress-plugin",
    "description": "QuantCDN integration for WordPress",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0",
        "brain/monkey": "^2.6",
        "mockery/mockery": "^1.6"
    },
    "autoload": {
        "psr-4": {
            "Quant\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Quant\\Tests\\": "tests/"
        }
    },
    "config": {
        "optimize-autoloader": true,
        "sort-packages": true
    }
}
```

**Step 2: Install dependencies**

Run: `cd plugins/quant && composer install`
Expected: `vendor/` directory created with autoloader

**Step 3: Update main plugin file to use autoloader**

Replace `plugins/quant/quant.php` with:

```php
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
```

**Step 4: Commit**

```bash
git add plugins/quant/composer.json plugins/quant/quant.php
git commit -m "feat: initialize Composer and PSR-4 autoloading for v2"
```

---

### Task 2: Create Service Container

**Files:**
- Create: `plugins/quant/src/Core/Container.php`
- Create: `plugins/quant/tests/Core/ContainerTest.php`

**Step 1: Write failing tests for container**

Create `plugins/quant/tests/Core/ContainerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Tests\Core;

use PHPUnit\Framework\TestCase;
use Quant\Core\Container;

class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function test_can_bind_and_resolve_closure(): void
    {
        $this->container->bind('test', fn() => 'hello');

        $this->assertSame('hello', $this->container->get('test'));
    }

    public function test_can_bind_and_resolve_class(): void
    {
        $this->container->bind('test', TestService::class);

        $this->assertInstanceOf(TestService::class, $this->container->get('test'));
    }

    public function test_resolves_singleton_once(): void
    {
        $this->container->singleton('test', fn() => new TestService());

        $first = $this->container->get('test');
        $second = $this->container->get('test');

        $this->assertSame($first, $second);
    }

    public function test_auto_resolves_class_with_dependencies(): void
    {
        $this->container->bind(DependencyInterface::class, TestDependency::class);

        $service = $this->container->make(ServiceWithDependency::class);

        $this->assertInstanceOf(ServiceWithDependency::class, $service);
        $this->assertInstanceOf(TestDependency::class, $service->dependency);
    }

    public function test_throws_when_key_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->container->get('nonexistent');
    }
}

// Test fixtures
class TestService {}

interface DependencyInterface {}

class TestDependency implements DependencyInterface {}

class ServiceWithDependency
{
    public function __construct(public DependencyInterface $dependency) {}
}
```

**Step 2: Create phpunit.xml**

Create `plugins/quant/phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

Create `plugins/quant/tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
```

**Step 3: Run tests to verify they fail**

Run: `cd plugins/quant && ./vendor/bin/phpunit tests/Core/ContainerTest.php`
Expected: FAIL with "Class Quant\Core\Container not found"

**Step 4: Implement Container**

Create `plugins/quant/src/Core/Container.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

class Container
{
    private array $bindings = [];
    private array $singletons = [];
    private array $instances = [];

    public function bind(string $key, Closure|string $resolver): void
    {
        $this->bindings[$key] = $resolver;
    }

    public function singleton(string $key, Closure|string $resolver): void
    {
        $this->singletons[$key] = $resolver;
    }

    public function instance(string $key, object $instance): void
    {
        $this->instances[$key] = $instance;
    }

    public function get(string $key): mixed
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        if (isset($this->singletons[$key])) {
            $this->instances[$key] = $this->resolve($this->singletons[$key]);
            unset($this->singletons[$key]);
            return $this->instances[$key];
        }

        if (isset($this->bindings[$key])) {
            return $this->resolve($this->bindings[$key]);
        }

        throw new RuntimeException("No binding found for: {$key}");
    }

    public function has(string $key): bool
    {
        return isset($this->bindings[$key])
            || isset($this->singletons[$key])
            || isset($this->instances[$key]);
    }

    public function make(string $class): object
    {
        if ($this->has($class)) {
            return $this->get($class);
        }

        return $this->build($class);
    }

    private function resolve(Closure|string $resolver): mixed
    {
        if ($resolver instanceof Closure) {
            return $resolver($this);
        }

        return $this->build($resolver);
    }

    private function build(string $class): object
    {
        $reflector = new ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Class {$class} is not instantiable");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                if ($param->isDefaultValueAvailable()) {
                    $dependencies[] = $param->getDefaultValue();
                    continue;
                }
                throw new RuntimeException(
                    "Cannot resolve parameter: {$param->getName()} in {$class}"
                );
            }

            $dependencies[] = $this->make($type->getName());
        }

        return $reflector->newInstanceArgs($dependencies);
    }
}
```

**Step 5: Run tests to verify they pass**

Run: `cd plugins/quant && ./vendor/bin/phpunit tests/Core/ContainerTest.php`
Expected: OK (5 tests, 6 assertions)

**Step 6: Commit**

```bash
git add plugins/quant/src/Core/Container.php plugins/quant/tests/ plugins/quant/phpunit.xml
git commit -m "feat: add service container with dependency injection"
```

---

### Task 3: Create Plugin Bootstrap and Settings Manager

**Files:**
- Create: `plugins/quant/src/Core/Plugin.php`
- Create: `plugins/quant/src/Core/Settings.php`
- Create: `plugins/quant/src/Core/Activator.php`
- Create: `plugins/quant/src/Core/Deactivator.php`
- Create: `plugins/quant/tests/Core/SettingsTest.php`

**Step 1: Write failing tests for Settings**

Create `plugins/quant/tests/Core/SettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Tests\Core;

use PHPUnit\Framework\TestCase;
use Quant\Core\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;

class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_returns_option_value(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('quant_settings', [])
            ->andReturn(['key' => 'value']);

        $settings = new Settings();

        $this->assertSame('value', $settings->get('key'));
    }

    public function test_get_returns_default_when_key_missing(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('quant_settings', [])
            ->andReturn([]);

        $settings = new Settings();

        $this->assertSame('default', $settings->get('missing', 'default'));
    }

    public function test_set_updates_option(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('quant_settings', [])
            ->andReturn(['existing' => 'value']);

        Functions\expect('update_option')
            ->once()
            ->with('quant_settings', ['existing' => 'value', 'new' => 'data'])
            ->andReturn(true);

        $settings = new Settings();
        $settings->set('new', 'data');
    }

    public function test_get_mode_returns_plugin_mode(): void
    {
        Functions\expect('get_option')
            ->with('quant_mode', 'static')
            ->andReturn('cdn_only');

        $settings = new Settings();

        $this->assertSame('cdn_only', $settings->getMode());
    }

    public function test_is_static_mode(): void
    {
        Functions\expect('get_option')
            ->with('quant_mode', 'static')
            ->andReturn('static');

        $settings = new Settings();

        $this->assertTrue($settings->isStaticMode());
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `cd plugins/quant && ./vendor/bin/phpunit tests/Core/SettingsTest.php`
Expected: FAIL with "Class Quant\Core\Settings not found"

**Step 3: Implement Settings**

Create `plugins/quant/src/Core/Settings.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Core;

class Settings
{
    private const OPTION_SETTINGS = 'quant_settings';
    private const OPTION_MODE = 'quant_mode';
    private const OPTION_CREDENTIALS = 'quant_credentials';
    private const OPTION_STATIC_CONFIG = 'quant_static_config';
    private const OPTION_SYNC_SETTINGS = 'quant_sync_settings';
    private const OPTION_SCHEDULE = 'quant_schedule';

    private ?array $cache = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();
        return $settings[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $settings = $this->all();
        $settings[$key] = $value;
        update_option(self::OPTION_SETTINGS, $settings);
        $this->cache = $settings;
    }

    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = get_option(self::OPTION_SETTINGS, []);
        }
        return $this->cache;
    }

    public function getMode(): string
    {
        return get_option(self::OPTION_MODE, 'static');
    }

    public function setMode(string $mode): void
    {
        update_option(self::OPTION_MODE, $mode);
    }

    public function isStaticMode(): bool
    {
        return $this->getMode() === 'static';
    }

    public function isCdnOnlyMode(): bool
    {
        return $this->getMode() === 'cdn_only';
    }

    public function getCredentials(): array
    {
        return get_option(self::OPTION_CREDENTIALS, [
            'organization' => '',
            'project' => '',
            'token' => '',
            'endpoint' => 'https://api.quantcdn.io',
        ]);
    }

    public function setCredentials(array $credentials): void
    {
        update_option(self::OPTION_CREDENTIALS, $credentials);
    }

    public function hasValidCredentials(): bool
    {
        $creds = $this->getCredentials();
        return !empty($creds['organization'])
            && !empty($creds['project'])
            && !empty($creds['token']);
    }

    public function getStaticConfig(): array
    {
        return get_option(self::OPTION_STATIC_CONFIG, [
            'custom_routes' => [],
            'route_404' => '/404',
            'domain_rewrites' => [],
        ]);
    }

    public function setStaticConfig(array $config): void
    {
        update_option(self::OPTION_STATIC_CONFIG, $config);
    }

    public function getSyncSettings(): array
    {
        return get_option(self::OPTION_SYNC_SETTINGS, [
            'auto_sync' => true,
            'auto_purge' => true,
            'sync_drafts' => false,
        ]);
    }

    public function setSyncSettings(array $settings): void
    {
        update_option(self::OPTION_SYNC_SETTINGS, $settings);
    }

    public function getSchedule(): array
    {
        return get_option(self::OPTION_SCHEDULE, [
            'enabled' => false,
            'interval' => 'hourly',
            'types' => ['post', 'page'],
        ]);
    }

    public function setSchedule(array $schedule): void
    {
        update_option(self::OPTION_SCHEDULE, $schedule);
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `cd plugins/quant && ./vendor/bin/phpunit tests/Core/SettingsTest.php`
Expected: OK (5 tests, 5 assertions)

**Step 5: Create Plugin bootstrap**

Create `plugins/quant/src/Core/Plugin.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Core;

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

        // API services will be registered here
        // $this->container->singleton(Api\ContentApiInterface::class, ...);

        // Mode-specific services
        // if ($this->container->get(Settings::class)->isStaticMode()) {
        //     $this->registerStaticModeServices();
        // }
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
            return;
        }

        $assets = require $assetFile;

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
        // Routes will be registered by individual controllers
    }
}
```

**Step 6: Create Activator and Deactivator**

Create `plugins/quant/src/Core/Activator.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Core;

class Activator
{
    public static function activate(): void
    {
        self::createTables();
        self::setDefaultOptions();

        flush_rewrite_rules();
    }

    private static function createTables(): void
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $tableName = $wpdb->prefix . 'quant_sync_queue';

        $sql = "CREATE TABLE {$tableName} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id VARCHAR(36) NOT NULL,
            type VARCHAR(20) NOT NULL,
            object_id VARCHAR(255) NOT NULL,
            action VARCHAR(20) NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            attempts TINYINT DEFAULT 0,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            processed_at DATETIME NULL,
            INDEX idx_status (status),
            INDEX idx_type_object (type, object_id)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('quant_db_version', '2.0.0');
    }

    private static function setDefaultOptions(): void
    {
        if (get_option('quant_version') === false) {
            update_option('quant_version', QUANT_VERSION);
        }
    }
}
```

Create `plugins/quant/src/Core/Deactivator.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Core;

class Deactivator
{
    public static function deactivate(): void
    {
        // Clear scheduled events
        wp_clear_scheduled_hook('quant_sync_cron');

        flush_rewrite_rules();
    }
}
```

**Step 7: Commit**

```bash
git add plugins/quant/src/Core/
git commit -m "feat: add Plugin bootstrap, Settings, Activator, and Deactivator"
```

---

### Task 4: Create API Client Interface and Implementation

**Files:**
- Create: `plugins/quant/src/Api/ApiResponse.php`
- Create: `plugins/quant/src/Api/ContentApiInterface.php`
- Create: `plugins/quant/src/Api/QuantContentApi.php`
- Create: `plugins/quant/src/Api/HttpClient.php`
- Create: `plugins/quant/tests/Api/QuantContentApiTest.php`

**Step 1: Write failing tests for API client**

Create `plugins/quant/tests/Api/QuantContentApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Tests\Api;

use PHPUnit\Framework\TestCase;
use Quant\Api\ApiResponse;
use Quant\Api\ContentApiInterface;
use Quant\Api\HttpClientInterface;
use Quant\Api\QuantContentApi;
use Quant\Core\Settings;
use Brain\Monkey;
use Mockery;

class QuantContentApiTest extends TestCase
{
    private QuantContentApi $api;
    private HttpClientInterface $http;
    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->http = Mockery::mock(HttpClientInterface::class);
        $this->settings = Mockery::mock(Settings::class);

        $this->settings->allows('getCredentials')->andReturn([
            'organization' => 'test-org',
            'project' => 'test-project',
            'token' => 'test-token',
            'endpoint' => 'https://api.quantcdn.io',
        ]);

        $this->api = new QuantContentApi($this->settings, $this->http);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(ContentApiInterface::class, $this->api);
    }

    public function test_ping_returns_success_on_200(): void
    {
        $this->http->expects('get')
            ->with('https://api.quantcdn.io/v1/ping', Mockery::type('array'))
            ->andReturn(['code' => 200, 'body' => '{"status":"ok"}']);

        $response = $this->api->ping();

        $this->assertTrue($response->success);
    }

    public function test_publish_sends_correct_payload(): void
    {
        $this->http->expects('post')
            ->with(
                'https://api.quantcdn.io/v1/content',
                Mockery::on(fn($headers) =>
                    $headers['Quant-Token'] === 'test-token' &&
                    $headers['Quant-Project'] === 'test-project'
                ),
                Mockery::on(fn($body) =>
                    $body['url'] === '/test-page' &&
                    $body['content'] === '<html>test</html>'
                )
            )
            ->andReturn(['code' => 200, 'body' => '{"success":true}']);

        $response = $this->api->publish('/test-page', '<html>test</html>');

        $this->assertTrue($response->success);
    }

    public function test_purge_sends_correct_headers(): void
    {
        $this->http->expects('post')
            ->with(
                'https://api.quantcdn.io/v1/purge',
                Mockery::on(fn($headers) => $headers['Quant-Url'] === '/blog/post'),
                Mockery::any()
            )
            ->andReturn(['code' => 200, 'body' => '{}']);

        $response = $this->api->purge('/blog/post');

        $this->assertTrue($response->success);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `cd plugins/quant && ./vendor/bin/phpunit tests/Api/QuantContentApiTest.php`
Expected: FAIL with interface/class not found errors

**Step 3: Create ApiResponse value object**

Create `plugins/quant/src/Api/ApiResponse.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Api;

readonly class ApiResponse
{
    public function __construct(
        public bool $success,
        public string $message = '',
        public array $data = [],
        public int $statusCode = 200
    ) {}

    public static function success(array $data = [], string $message = ''): self
    {
        return new self(true, $message, $data);
    }

    public static function error(string $message, int $statusCode = 400, array $data = []): self
    {
        return new self(false, $message, $data, $statusCode);
    }
}
```

**Step 4: Create ContentApiInterface**

Create `plugins/quant/src/Api/ContentApiInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Api;

interface ContentApiInterface
{
    public function ping(): ApiResponse;

    public function publish(string $url, string $markup, array $meta = []): ApiResponse;

    public function unpublish(string $url): ApiResponse;

    public function uploadFile(string $path, string $localPath): ApiResponse;

    public function createRedirect(string $from, string $to, int $code = 301): ApiResponse;

    public function purge(string|array $paths): ApiResponse;
}
```

**Step 5: Create HttpClientInterface and WordPress implementation**

Create `plugins/quant/src/Api/HttpClientInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Api;

interface HttpClientInterface
{
    public function get(string $url, array $headers = []): array;

    public function post(string $url, array $headers = [], array $body = []): array;

    public function patch(string $url, array $headers = [], array $body = []): array;
}
```

Create `plugins/quant/src/Api/WordPressHttpClient.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Api;

use WP_Error;

class WordPressHttpClient implements HttpClientInterface
{
    private int $timeout;

    public function __construct(int $timeout = 30)
    {
        $this->timeout = $timeout;
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array $body = []): array
    {
        return $this->request('POST', $url, $headers, $body);
    }

    public function patch(string $url, array $headers = [], array $body = []): array
    {
        return $this->request('PATCH', $url, $headers, $body);
    }

    private function request(string $method, string $url, array $headers, array $body = []): array
    {
        $args = [
            'method' => $method,
            'timeout' => $this->timeout,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
            ], $headers),
        ];

        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if ($response instanceof WP_Error) {
            return [
                'code' => 0,
                'body' => '',
                'error' => $response->get_error_message(),
            ];
        }

        return [
            'code' => wp_remote_retrieve_response_code($response),
            'body' => wp_remote_retrieve_body($response),
        ];
    }
}
```

**Step 6: Implement QuantContentApi**

Create `plugins/quant/src/Api/QuantContentApi.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Api;

use Quant\Core\Settings;

class QuantContentApi implements ContentApiInterface
{
    private string $endpoint;
    private string $token;
    private string $project;
    private string $organization;

    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClientInterface $http
    ) {
        $credentials = $this->settings->getCredentials();
        $this->endpoint = rtrim($credentials['endpoint'] ?? 'https://api.quantcdn.io', '/');
        $this->token = $credentials['token'] ?? '';
        $this->project = $credentials['project'] ?? '';
        $this->organization = $credentials['organization'] ?? '';
    }

    public function ping(): ApiResponse
    {
        $response = $this->http->get(
            "{$this->endpoint}/v1/ping",
            $this->getHeaders()
        );

        return $this->parseResponse($response);
    }

    public function publish(string $url, string $markup, array $meta = []): ApiResponse
    {
        $url = $this->normalizeUrl($url);

        $body = [
            'url' => $url,
            'content' => $markup,
            'published' => true,
            'content_timestamp' => time(),
        ];

        if (!empty($meta)) {
            $body['info'] = $meta;
        }

        $response = $this->http->post(
            "{$this->endpoint}/v1/content",
            $this->getHeaders(),
            $body
        );

        return $this->parseResponse($response);
    }

    public function unpublish(string $url): ApiResponse
    {
        $url = $this->normalizeUrl($url);

        $response = $this->http->patch(
            "{$this->endpoint}/v1/unpublish",
            array_merge($this->getHeaders(), ['Quant-Url' => $url])
        );

        return $this->parseResponse($response);
    }

    public function uploadFile(string $path, string $localPath): ApiResponse
    {
        // File upload requires special handling with streaming
        // This will be implemented in Task 5 with proper file handling
        return ApiResponse::error('File upload not yet implemented');
    }

    public function createRedirect(string $from, string $to, int $code = 301): ApiResponse
    {
        $from = $this->normalizeUrl($from);

        $response = $this->http->post(
            "{$this->endpoint}/v1/redirect",
            $this->getHeaders(),
            [
                'url' => $from,
                'redirect_url' => $to,
                'redirect_http_code' => $code,
                'published' => true,
            ]
        );

        return $this->parseResponse($response);
    }

    public function purge(string|array $paths): ApiResponse
    {
        $paths = is_array($paths) ? $paths : [$paths];
        $errors = [];

        foreach ($paths as $path) {
            $path = $this->normalizeUrl($path);

            // Purge both with and without trailing slash
            foreach ([$path, rtrim($path, '/') . '/'] as $variant) {
                $response = $this->http->post(
                    "{$this->endpoint}/v1/purge",
                    array_merge($this->getHeaders(), ['Quant-Url' => $variant]),
                    []
                );

                if ($response['code'] !== 200) {
                    $errors[] = "Failed to purge: {$variant}";
                }
            }
        }

        if (!empty($errors)) {
            return ApiResponse::error(implode(', ', $errors));
        }

        return ApiResponse::success([], 'Cache purged successfully');
    }

    private function getHeaders(): array
    {
        return [
            'Quant-Token' => $this->token,
            'Quant-Project' => $this->project,
            'Quant-Organisation' => $this->organization,
        ];
    }

    private function normalizeUrl(string $url): string
    {
        $url = '/' . ltrim($url, '/');

        // Remove trailing slash except for root
        if ($url !== '/' && str_ends_with($url, '/')) {
            $url = rtrim($url, '/');
        }

        return $url;
    }

    private function parseResponse(array $response): ApiResponse
    {
        if (isset($response['error'])) {
            return ApiResponse::error($response['error']);
        }

        $code = $response['code'] ?? 0;
        $body = json_decode($response['body'] ?? '', true) ?: [];

        if ($code >= 200 && $code < 300) {
            return ApiResponse::success($body);
        }

        $message = $body['message'] ?? $body['error'] ?? "HTTP {$code}";
        return ApiResponse::error($message, $code, $body);
    }
}
```

**Step 7: Run tests to verify they pass**

Run: `cd plugins/quant && ./vendor/bin/phpunit tests/Api/QuantContentApiTest.php`
Expected: OK (4 tests)

**Step 8: Commit**

```bash
git add plugins/quant/src/Api/ plugins/quant/tests/Api/
git commit -m "feat: add API client interface and QuantContentApi implementation"
```

---

### Task 5: Initialize React Admin with @wordpress/scripts

**Files:**
- Create: `plugins/quant/package.json`
- Create: `plugins/quant/assets/src/index.js`
- Create: `plugins/quant/assets/src/index.scss`
- Create: `plugins/quant/assets/src/App.jsx`
- Create: `plugins/quant/assets/src/pages/Dashboard.jsx`
- Create: `plugins/quant/assets/src/components/Layout/Layout.jsx`

**Step 1: Create package.json**

Create `plugins/quant/package.json`:

```json
{
    "name": "quant-wordpress-admin",
    "version": "2.0.0",
    "description": "QuantCDN WordPress Admin UI",
    "scripts": {
        "build": "wp-scripts build",
        "start": "wp-scripts start",
        "lint:js": "wp-scripts lint-js",
        "lint:css": "wp-scripts lint-style",
        "format": "wp-scripts format"
    },
    "devDependencies": {
        "@wordpress/scripts": "^27.0.0"
    },
    "dependencies": {
        "@wordpress/api-fetch": "^6.0.0",
        "@wordpress/components": "^27.0.0",
        "@wordpress/data": "^9.0.0",
        "@wordpress/element": "^5.0.0",
        "@wordpress/i18n": "^4.0.0",
        "react-router-dom": "^6.0.0"
    }
}
```

**Step 2: Install dependencies**

Run: `cd plugins/quant && npm install`
Expected: node_modules created

**Step 3: Create main entry point**

Create `plugins/quant/assets/src/index.js`:

```jsx
import { createRoot } from '@wordpress/element';
import { App } from './App';
import './index.scss';

const container = document.getElementById('quant-admin-root');

if (container) {
    const root = createRoot(container);
    root.render(<App />);
}
```

**Step 4: Create App component with routing**

Create `plugins/quant/assets/src/App.jsx`:

```jsx
import { HashRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Layout } from './components/Layout/Layout';
import { Dashboard } from './pages/Dashboard';
import { Cache } from './pages/Cache';
import { Settings } from './pages/Settings';

export function App() {
    return (
        <HashRouter>
            <Layout>
                <Routes>
                    <Route path="/" element={<Navigate to="/dashboard" replace />} />
                    <Route path="/dashboard" element={<Dashboard />} />
                    <Route path="/cache" element={<Cache />} />
                    <Route path="/settings" element={<Settings />} />
                </Routes>
            </Layout>
        </HashRouter>
    );
}
```

**Step 5: Create Layout component**

Create `plugins/quant/assets/src/components/Layout/Layout.jsx`:

```jsx
import { NavLink } from 'react-router-dom';
import { __ } from '@wordpress/i18n';
import './Layout.scss';

export function Layout({ children }) {
    return (
        <div className="quant-admin">
            <header className="quant-admin__header">
                <div className="quant-admin__logo">
                    <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                        <rect width="32" height="32" rx="6" fill="#5c40ff"/>
                        <text x="50%" y="55%" dominantBaseline="middle" textAnchor="middle" fill="white" fontSize="16" fontWeight="bold">Q</text>
                    </svg>
                    <span>QuantCDN</span>
                </div>
            </header>

            <div className="quant-admin__body">
                <nav className="quant-admin__sidebar">
                    <ul>
                        <li>
                            <NavLink to="/dashboard">
                                {__('Dashboard', 'quant')}
                            </NavLink>
                        </li>
                        <li>
                            <NavLink to="/cache">
                                {__('Cache', 'quant')}
                            </NavLink>
                        </li>
                        <li>
                            <NavLink to="/settings">
                                {__('Settings', 'quant')}
                            </NavLink>
                        </li>
                    </ul>
                </nav>

                <main className="quant-admin__content">
                    {children}
                </main>
            </div>
        </div>
    );
}
```

Create `plugins/quant/assets/src/components/Layout/Layout.scss`:

```scss
.quant-admin {
    margin-left: -20px;
    min-height: 100vh;
    background: #f0f0f1;

    &__header {
        background: #1e1e1e;
        padding: 12px 24px;
        display: flex;
        align-items: center;
    }

    &__logo {
        display: flex;
        align-items: center;
        gap: 12px;
        color: #fff;
        font-size: 18px;
        font-weight: 600;
    }

    &__body {
        display: flex;
        min-height: calc(100vh - 56px);
    }

    &__sidebar {
        width: 200px;
        background: #fff;
        border-right: 1px solid #ddd;
        padding: 16px 0;

        ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        a {
            display: block;
            padding: 10px 24px;
            color: #1e1e1e;
            text-decoration: none;

            &:hover {
                background: #f6f7f7;
            }

            &.active {
                background: #5c40ff;
                color: #fff;
            }
        }
    }

    &__content {
        flex: 1;
        padding: 24px;
    }
}
```

**Step 6: Create page components**

Create `plugins/quant/assets/src/pages/Dashboard.jsx`:

```jsx
import { Card, CardHeader, CardBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export function Dashboard() {
    const [status, setStatus] = useState({ loading: true, connected: false });

    useEffect(() => {
        apiFetch({ path: '/quant/v1/status' })
            .then((data) => setStatus({ loading: false, connected: data.connected }))
            .catch(() => setStatus({ loading: false, connected: false }));
    }, []);

    return (
        <div className="quant-dashboard">
            <h1>{__('Dashboard', 'quant')}</h1>

            <div className="quant-dashboard__cards">
                <Card>
                    <CardHeader>
                        <h2>{__('Connection Status', 'quant')}</h2>
                    </CardHeader>
                    <CardBody>
                        {status.loading ? (
                            <p>{__('Checking connection...', 'quant')}</p>
                        ) : status.connected ? (
                            <p className="quant-status--success">
                                {__('Connected to QuantCDN', 'quant')}
                            </p>
                        ) : (
                            <p className="quant-status--error">
                                {__('Not connected. Check your settings.', 'quant')}
                            </p>
                        )}
                    </CardBody>
                </Card>
            </div>
        </div>
    );
}
```

Create `plugins/quant/assets/src/pages/Cache.jsx`:

```jsx
import { useState } from '@wordpress/element';
import {
    Card,
    CardHeader,
    CardBody,
    TextareaControl,
    Button,
    Notice
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export function Cache() {
    const [paths, setPaths] = useState('');
    const [loading, setLoading] = useState(false);
    const [notice, setNotice] = useState(null);

    const handlePurge = async () => {
        if (!paths.trim()) return;

        setLoading(true);
        setNotice(null);

        try {
            const pathList = paths.split('\n').filter(p => p.trim());
            await apiFetch({
                path: '/quant/v1/purge',
                method: 'POST',
                data: { paths: pathList }
            });
            setNotice({ status: 'success', message: __('Cache purged successfully', 'quant') });
            setPaths('');
        } catch (error) {
            setNotice({ status: 'error', message: error.message });
        } finally {
            setLoading(false);
        }
    };

    const handlePurgeAll = async () => {
        if (!confirm(__('Purge entire cache? This cannot be undone.', 'quant'))) {
            return;
        }

        setLoading(true);
        setNotice(null);

        try {
            await apiFetch({
                path: '/quant/v1/purge',
                method: 'POST',
                data: { paths: ['/*'] }
            });
            setNotice({ status: 'success', message: __('Entire cache purged', 'quant') });
        } catch (error) {
            setNotice({ status: 'error', message: error.message });
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="quant-cache">
            <h1>{__('Cache Management', 'quant')}</h1>

            {notice && (
                <Notice status={notice.status} onRemove={() => setNotice(null)}>
                    {notice.message}
                </Notice>
            )}

            <Card>
                <CardHeader>
                    <h2>{__('Purge Cache', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    <TextareaControl
                        label={__('Paths to purge (one per line)', 'quant')}
                        value={paths}
                        onChange={setPaths}
                        placeholder={'/blog/my-post\n/about'}
                        rows={6}
                    />
                    <div style={{ display: 'flex', gap: '12px', marginTop: '16px' }}>
                        <Button
                            variant="primary"
                            onClick={handlePurge}
                            disabled={loading || !paths.trim()}
                        >
                            {__('Purge Selected Paths', 'quant')}
                        </Button>
                        <Button
                            variant="secondary"
                            isDestructive
                            onClick={handlePurgeAll}
                            disabled={loading}
                        >
                            {__('Purge All', 'quant')}
                        </Button>
                    </div>
                </CardBody>
            </Card>
        </div>
    );
}
```

Create `plugins/quant/assets/src/pages/Settings.jsx`:

```jsx
import { useState, useEffect } from '@wordpress/element';
import {
    Card,
    CardHeader,
    CardBody,
    TextControl,
    Button,
    Notice,
    SelectControl
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

export function Settings() {
    const [settings, setSettings] = useState({
        organization: '',
        project: '',
        token: '',
        endpoint: 'https://api.quantcdn.io',
        mode: 'static'
    });
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [notice, setNotice] = useState(null);

    useEffect(() => {
        apiFetch({ path: '/quant/v1/settings' })
            .then(setSettings)
            .catch(() => {})
            .finally(() => setLoading(false));
    }, []);

    const handleSave = async () => {
        setSaving(true);
        setNotice(null);

        try {
            await apiFetch({
                path: '/quant/v1/settings',
                method: 'POST',
                data: settings
            });
            setNotice({ status: 'success', message: __('Settings saved', 'quant') });
        } catch (error) {
            setNotice({ status: 'error', message: error.message });
        } finally {
            setSaving(false);
        }
    };

    const updateSetting = (key, value) => {
        setSettings(prev => ({ ...prev, [key]: value }));
    };

    if (loading) {
        return <p>{__('Loading...', 'quant')}</p>;
    }

    return (
        <div className="quant-settings">
            <h1>{__('Settings', 'quant')}</h1>

            {notice && (
                <Notice status={notice.status} onRemove={() => setNotice(null)}>
                    {notice.message}
                </Notice>
            )}

            <Card>
                <CardHeader>
                    <h2>{__('API Credentials', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    <TextControl
                        label={__('Organization', 'quant')}
                        value={settings.organization}
                        onChange={(v) => updateSetting('organization', v)}
                    />
                    <TextControl
                        label={__('Project', 'quant')}
                        value={settings.project}
                        onChange={(v) => updateSetting('project', v)}
                    />
                    <TextControl
                        label={__('API Token', 'quant')}
                        type="password"
                        value={settings.token}
                        onChange={(v) => updateSetting('token', v)}
                    />
                    <TextControl
                        label={__('API Endpoint', 'quant')}
                        value={settings.endpoint}
                        onChange={(v) => updateSetting('endpoint', v)}
                    />
                </CardBody>
            </Card>

            <Card style={{ marginTop: '16px' }}>
                <CardHeader>
                    <h2>{__('Plugin Mode', 'quant')}</h2>
                </CardHeader>
                <CardBody>
                    <SelectControl
                        label={__('Mode', 'quant')}
                        value={settings.mode}
                        options={[
                            { label: __('Static Site', 'quant'), value: 'static' },
                            { label: __('CDN Only', 'quant'), value: 'cdn_only' }
                        ]}
                        onChange={(v) => updateSetting('mode', v)}
                        help={settings.mode === 'static'
                            ? __('Full content sync to QuantCDN edge', 'quant')
                            : __('Cache purging and CDN management only', 'quant')
                        }
                    />
                </CardBody>
            </Card>

            <div style={{ marginTop: '16px' }}>
                <Button variant="primary" onClick={handleSave} disabled={saving}>
                    {saving ? __('Saving...', 'quant') : __('Save Settings', 'quant')}
                </Button>
            </div>
        </div>
    );
}
```

**Step 7: Create main stylesheet**

Create `plugins/quant/assets/src/index.scss`:

```scss
// QuantCDN Admin Styles

.quant-status {
    &--success {
        color: #00a32a;
    }

    &--error {
        color: #d63638;
    }
}

.quant-dashboard__cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
    margin-top: 16px;
}

.quant-cache,
.quant-settings {
    max-width: 800px;

    .components-notice {
        margin-bottom: 16px;
    }
}
```

**Step 8: Build assets**

Run: `cd plugins/quant && npm run build`
Expected: `assets/build/` created with compiled JS and CSS

**Step 9: Commit**

```bash
git add plugins/quant/package.json plugins/quant/assets/src/
git commit -m "feat: add React admin UI with Dashboard, Cache, and Settings pages"
```

---

### Task 6: Create REST API Endpoints

**Files:**
- Create: `plugins/quant/src/Admin/RestController.php`
- Create: `plugins/quant/tests/Admin/RestControllerTest.php`

**Step 1: Write failing tests for REST endpoints**

Create `plugins/quant/tests/Admin/RestControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Quant\Admin\RestController;
use Quant\Api\ContentApiInterface;
use Quant\Api\ApiResponse;
use Quant\Core\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class RestControllerTest extends TestCase
{
    private RestController $controller;
    private ContentApiInterface $api;
    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->settings = Mockery::mock(Settings::class);
        $this->controller = new RestController($this->api, $this->settings);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_status_returns_connection_state(): void
    {
        $this->api->expects('ping')->andReturn(ApiResponse::success());
        $this->settings->expects('getMode')->andReturn('static');
        $this->settings->expects('hasValidCredentials')->andReturn(true);

        $request = Mockery::mock(\WP_REST_Request::class);
        $response = $this->controller->getStatus($request);

        $this->assertTrue($response['connected']);
        $this->assertSame('static', $response['mode']);
    }

    public function test_purge_calls_api_with_paths(): void
    {
        $this->api->expects('purge')
            ->with(['/path/one', '/path/two'])
            ->andReturn(ApiResponse::success());

        $request = Mockery::mock(\WP_REST_Request::class);
        $request->expects('get_param')->with('paths')->andReturn(['/path/one', '/path/two']);

        $response = $this->controller->purge($request);

        $this->assertTrue($response['success']);
    }

    public function test_get_settings_returns_current_settings(): void
    {
        $this->settings->expects('getCredentials')->andReturn([
            'organization' => 'test-org',
            'project' => 'test-project',
            'token' => 'secret',
            'endpoint' => 'https://api.quantcdn.io',
        ]);
        $this->settings->expects('getMode')->andReturn('static');

        $request = Mockery::mock(\WP_REST_Request::class);
        $response = $this->controller->getSettings($request);

        $this->assertSame('test-org', $response['organization']);
        $this->assertSame('', $response['token']); // Token should be masked
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `cd plugins/quant && ./vendor/bin/phpunit tests/Admin/RestControllerTest.php`
Expected: FAIL with class not found

**Step 3: Implement RestController**

Create `plugins/quant/src/Admin/RestController.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Admin;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class RestController
{
    private const NAMESPACE = 'quant/v1';

    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings
    ) {}

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'getStatus'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/settings', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getSettings'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'saveSettings'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/purge', [
            'methods' => 'POST',
            'callback' => [$this, 'purge'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'paths' => [
                    'required' => true,
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    public function getStatus(WP_REST_Request $request): array
    {
        $connected = false;

        if ($this->settings->hasValidCredentials()) {
            $response = $this->api->ping();
            $connected = $response->success;
        }

        return [
            'connected' => $connected,
            'mode' => $this->settings->getMode(),
            'version' => QUANT_VERSION,
        ];
    }

    public function getSettings(WP_REST_Request $request): array
    {
        $credentials = $this->settings->getCredentials();

        return [
            'organization' => $credentials['organization'] ?? '',
            'project' => $credentials['project'] ?? '',
            'token' => '', // Never expose token
            'endpoint' => $credentials['endpoint'] ?? 'https://api.quantcdn.io',
            'mode' => $this->settings->getMode(),
        ];
    }

    public function saveSettings(WP_REST_Request $request): array|WP_Error
    {
        $organization = sanitize_text_field($request->get_param('organization') ?? '');
        $project = sanitize_text_field($request->get_param('project') ?? '');
        $token = $request->get_param('token');
        $endpoint = esc_url_raw($request->get_param('endpoint') ?? 'https://api.quantcdn.io');
        $mode = sanitize_text_field($request->get_param('mode') ?? 'static');

        // Get existing credentials
        $existing = $this->settings->getCredentials();

        // Only update token if provided (non-empty)
        $credentials = [
            'organization' => $organization,
            'project' => $project,
            'token' => !empty($token) ? $token : ($existing['token'] ?? ''),
            'endpoint' => $endpoint,
        ];

        $this->settings->setCredentials($credentials);

        if (in_array($mode, ['static', 'cdn_only'], true)) {
            $this->settings->setMode($mode);
        }

        return ['success' => true];
    }

    public function purge(WP_REST_Request $request): array|WP_Error
    {
        $paths = $request->get_param('paths');

        if (empty($paths)) {
            return new WP_Error('invalid_paths', 'No paths provided', ['status' => 400]);
        }

        $response = $this->api->purge($paths);

        if (!$response->success) {
            return new WP_Error('purge_failed', $response->message, ['status' => 500]);
        }

        return [
            'success' => true,
            'message' => $response->message,
        ];
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `cd plugins/quant && ./vendor/bin/phpunit tests/Admin/RestControllerTest.php`
Expected: OK (3 tests)

**Step 5: Register REST controller in Plugin**

Update `plugins/quant/src/Core/Plugin.php` registerServices and registerHooks:

```php
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
}

public function registerRestRoutes(): void
{
    $this->container->get(Admin\RestController::class)->register();
}
```

Add use statements at top of Plugin.php:

```php
use Quant\Api;
use Quant\Admin;
```

**Step 6: Commit**

```bash
git add plugins/quant/src/Admin/ plugins/quant/tests/Admin/ plugins/quant/src/Core/Plugin.php
git commit -m "feat: add REST API endpoints for status, settings, and cache purge"
```

---

## Phase 1b: CDN-Only Mode (Cache Management)

### Task 7: Add Auto-Purge on Post Save

**Files:**
- Create: `plugins/quant/src/Hook/CachePurgeSubscriber.php`
- Create: `plugins/quant/tests/Hook/CachePurgeSubscriberTest.php`

**Step 1: Write failing tests**

Create `plugins/quant/tests/Hook/CachePurgeSubscriberTest.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Tests\Hook;

use PHPUnit\Framework\TestCase;
use Quant\Hook\CachePurgeSubscriber;
use Quant\Api\ContentApiInterface;
use Quant\Api\ApiResponse;
use Quant\Core\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class CachePurgeSubscriberTest extends TestCase
{
    private CachePurgeSubscriber $subscriber;
    private ContentApiInterface $api;
    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->settings = Mockery::mock(Settings::class);
        $this->subscriber = new CachePurgeSubscriber($this->api, $this->settings);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_purges_on_post_save_when_enabled(): void
    {
        $this->settings->expects('getSyncSettings')
            ->andReturn(['auto_purge' => true]);

        Functions\expect('get_permalink')
            ->with(123)
            ->andReturn('https://example.com/test-post/');

        Functions\expect('wp_parse_url')
            ->andReturn(['path' => '/test-post/']);

        $this->api->expects('purge')
            ->with('/test-post')
            ->once()
            ->andReturn(ApiResponse::success());

        $post = Mockery::mock(\WP_Post::class);
        $post->ID = 123;
        $post->post_status = 'publish';
        $post->post_type = 'post';

        $this->subscriber->onPostSave(123, $post, true);
    }

    public function test_skips_purge_when_disabled(): void
    {
        $this->settings->expects('getSyncSettings')
            ->andReturn(['auto_purge' => false]);

        $this->api->shouldNotReceive('purge');

        $post = Mockery::mock(\WP_Post::class);
        $post->ID = 123;
        $post->post_status = 'publish';
        $post->post_type = 'post';

        $this->subscriber->onPostSave(123, $post, true);
    }

    public function test_skips_purge_for_revisions(): void
    {
        $this->settings->expects('getSyncSettings')
            ->andReturn(['auto_purge' => true]);

        $this->api->shouldNotReceive('purge');

        $post = Mockery::mock(\WP_Post::class);
        $post->ID = 123;
        $post->post_status = 'publish';
        $post->post_type = 'revision';

        $this->subscriber->onPostSave(123, $post, true);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `cd plugins/quant && ./vendor/bin/phpunit tests/Hook/CachePurgeSubscriberTest.php`
Expected: FAIL

**Step 3: Implement CachePurgeSubscriber**

Create `plugins/quant/src/Hook/CachePurgeSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace Quant\Hook;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use WP_Post;

class CachePurgeSubscriber
{
    private const IGNORED_POST_TYPES = [
        'revision',
        'auto-draft',
        'customize_changeset',
        'oembed_cache',
        'nav_menu_item',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
    ];

    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings
    ) {}

    public function register(): void
    {
        add_action('save_post', [$this, 'onPostSave'], 10, 3);
        add_action('delete_post', [$this, 'onPostDelete'], 10, 2);
        add_action('trashed_post', [$this, 'onPostTrash'], 10, 2);
        add_action('edit_term', [$this, 'onTermEdit'], 10, 3);
        add_action('delete_term', [$this, 'onTermDelete'], 10, 4);
    }

    public function onPostSave(int $postId, WP_Post $post, bool $update): void
    {
        if (!$this->shouldAutoPurge()) {
            return;
        }

        if ($this->shouldIgnorePost($post)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        $this->purgePost($postId);
    }

    public function onPostDelete(int $postId, WP_Post $post): void
    {
        if (!$this->shouldAutoPurge()) {
            return;
        }

        if ($this->shouldIgnorePost($post)) {
            return;
        }

        $this->purgePost($postId);
    }

    public function onPostTrash(int $postId, string $previousStatus): void
    {
        if (!$this->shouldAutoPurge()) {
            return;
        }

        if ($previousStatus === 'publish') {
            $this->purgePost($postId);
        }
    }

    public function onTermEdit(int $termId, int $ttId, string $taxonomy): void
    {
        if (!$this->shouldAutoPurge()) {
            return;
        }

        $this->purgeTerm($termId, $taxonomy);
    }

    public function onTermDelete(int $termId, int $ttId, string $taxonomy, mixed $deletedTerm): void
    {
        if (!$this->shouldAutoPurge()) {
            return;
        }

        if (is_object($deletedTerm)) {
            $this->purgeTerm($termId, $taxonomy);
        }
    }

    private function shouldAutoPurge(): bool
    {
        $settings = $this->settings->getSyncSettings();
        return !empty($settings['auto_purge']);
    }

    private function shouldIgnorePost(WP_Post $post): bool
    {
        return in_array($post->post_type, self::IGNORED_POST_TYPES, true);
    }

    private function purgePost(int $postId): void
    {
        $permalink = get_permalink($postId);

        if (!$permalink) {
            return;
        }

        $path = $this->getPathFromUrl($permalink);

        if ($path) {
            $this->api->purge($path);
        }
    }

    private function purgeTerm(int $termId, string $taxonomy): void
    {
        $link = get_term_link($termId, $taxonomy);

        if (is_wp_error($link)) {
            return;
        }

        $path = $this->getPathFromUrl($link);

        if ($path) {
            $this->api->purge($path);
        }
    }

    private function getPathFromUrl(string $url): ?string
    {
        $parsed = wp_parse_url($url);
        $path = $parsed['path'] ?? '/';

        // Normalize: remove trailing slash except for root
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `cd plugins/quant && ./vendor/bin/phpunit tests/Hook/CachePurgeSubscriberTest.php`
Expected: OK (3 tests)

**Step 5: Register subscriber in Plugin**

Update `plugins/quant/src/Core/Plugin.php`:

Add to registerServices():
```php
// Hook subscribers
$this->container->singleton(
    Hook\CachePurgeSubscriber::class,
    fn($c) => new Hook\CachePurgeSubscriber(
        $c->get(Api\ContentApiInterface::class),
        $c->get(Settings::class)
    )
);
```

Add to registerHooks():
```php
// Cache purge hooks (both modes)
$this->container->get(Hook\CachePurgeSubscriber::class)->register();
```

Add use statement:
```php
use Quant\Hook;
```

**Step 6: Commit**

```bash
git add plugins/quant/src/Hook/ plugins/quant/tests/Hook/ plugins/quant/src/Core/Plugin.php
git commit -m "feat: add auto-purge on post/term save and delete"
```

---

## Summary: Remaining Tasks

The plan continues with these additional tasks (abbreviated for space):

### Phase 1c: Static Mode Core
- **Task 8:** Create SyncQueue table and interface
- **Task 9:** Implement database queue backend
- **Task 10:** Create content renderers (posts, pages, CPTs)
- **Task 11:** Create taxonomy and archive sync
- **Task 12:** Create media and theme asset sync
- **Task 13:** Create redirect sync
- **Task 14:** Implement hook subscribers for auto-sync

### Phase 1d: Static Mode UI
- **Task 15:** Add Content page with sync status browser
- **Task 16:** Add batch operations UI (seed, resync)
- **Task 17:** Add progress tracking component

### Phase 1e: Migration & Polish
- **Task 18:** Create v1 migration detector
- **Task 19:** Create migration wizard UI
- **Task 20:** Add onboarding flow for new installs
- **Task 21:** Add diagnostic tools and error handling

### WP-CLI
- **Task 22:** Create CLI command base
- **Task 23:** Implement purge commands
- **Task 24:** Implement sync commands
- **Task 25:** Implement queue commands

---

## Testing Commands

```bash
# Run all tests
cd plugins/quant && ./vendor/bin/phpunit

# Run specific test file
cd plugins/quant && ./vendor/bin/phpunit tests/Core/ContainerTest.php

# Build React assets
cd plugins/quant && npm run build

# Watch mode for development
cd plugins/quant && npm start
```

---

## Notes

- All file paths are relative to the worktree root
- Each task should result in passing tests before moving to next
- Commit after each task completion
- Run full test suite periodically to catch regressions
