<?php

declare(strict_types=1);

namespace Quant\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Admin\RestController;
use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Redirects\RedirectProvider;
use Quant\Sync\DatabaseQueue;
use Quant\Sync\InternalFetcher;
use Quant\Sync\JobProcessor;
use Quant\Sync\Seeder;
use Quant\Sync\ThemeAssetSync;
use Quant\Tests\Support\WordPressStubs;
use WP_REST_Request;

#[CoversClass(RestController::class)]
final class RestControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    /** @var array<string, mixed> */
    private array $options = [];
    private RestController $controller;
    private JobProcessor&Mockery\MockInterface $processor;
    private \wpdb&Mockery\MockInterface $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        Functions\when('get_option')->alias(fn(string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('wp_get_schedules')->justReturn(['hourly' => ['display' => 'Hourly'], 'daily' => ['display' => 'Daily'], 'quant_every_minute' => ['display' => 'x']]);
        Functions\when('wp_next_scheduled')->justReturn(false);

        $api = Mockery::mock(ContentApiInterface::class);
        $wpdb = Mockery::mock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $this->wpdb = $wpdb;
        $this->processor = Mockery::mock(JobProcessor::class);

        $this->controller = new RestController(
            $api,
            new Settings(),
            new DatabaseQueue($wpdb),
            $this->processor,
            Mockery::mock(Seeder::class),
            new ThemeAssetSync($api),
            Mockery::mock(RedirectProvider::class),
            Mockery::mock(InternalFetcher::class)
        );

        $this->options[Settings::OPTION_CREDENTIALS] = ['organization' => 'acme', 'project' => 'site', 'token' => 'secret', 'endpoint' => 'https://api.quantcdn.io'];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function saving_only_the_mode_keeps_the_credentials(): void
    {
        $result = $this->controller->saveSettings(new WP_REST_Request([], ['mode' => 'cdn_only']));

        $this->assertTrue($result['success']);
        $this->assertSame('cdn_only', $result['mode']);
        $this->assertSame('acme', $result['organization']);
        $this->assertSame('secret', $this->options[Settings::OPTION_CREDENTIALS]['token']);
    }

    #[Test]
    public function an_empty_token_keeps_the_existing_token(): void
    {
        $result = $this->controller->saveSettings(new WP_REST_Request([], ['organization' => 'other', 'project' => 'site', 'token' => '']));

        $this->assertSame('other', $this->options[Settings::OPTION_CREDENTIALS]['organization']);
        $this->assertSame('secret', $this->options[Settings::OPTION_CREDENTIALS]['token']);
        $this->assertSame('', $result['token'], 'The token is never exposed');
        $this->assertTrue($result['has_token']);
    }

    #[Test]
    public function it_updates_sync_static_and_schedule_settings_from_one_request(): void
    {
        $this->controller->saveSettings(new WP_REST_Request([], [
            'auto_sync' => false,
            'webserver_url' => 'http://localhost/',
            'custom_routes' => "/robots.txt\n/a\n",
            'strip_host' => false,
            'http_request_timeout' => 2,
            'disable_tls_verify' => '1',
            'schedule' => ['enabled' => true, 'interval' => 'hourly', 'types' => ['post', 'Bad Type'], 'custom_routes' => ['/x']],
        ]));

        $result = $this->controller->getSettings(new WP_REST_Request());

        $this->assertFalse($result['auto_sync']);
        $this->assertTrue($result['auto_purge']);
        $this->assertSame('http://localhost', $result['webserver_url']);
        $this->assertSame(['/robots.txt', '/a'], $result['custom_routes']);
        $this->assertFalse($result['strip_host']);
        $this->assertSame(5, $result['http_request_timeout']);
        $this->assertTrue($result['disable_tls_verify']);
        $this->assertSame(['enabled' => true, 'interval' => 'hourly', 'types' => ['post', 'badtype'], 'custom_routes' => ['/x']], $result['schedule']);
        $this->assertSame(['hourly' => 'Hourly', 'daily' => 'Daily'], $result['schedules']);
    }

    #[Test]
    public function it_rejects_unknown_modes_and_schedule_intervals(): void
    {
        $this->controller->saveSettings(new WP_REST_Request([], ['mode' => 'turbo', 'schedule' => ['interval' => 'never']]));

        $result = $this->controller->getSettings(new WP_REST_Request());

        $this->assertSame('static', $result['mode']);
        $this->assertSame('daily', $result['schedule']['interval']);
    }

    #[Test]
    public function processing_without_credentials_is_a_400_error(): void
    {
        $this->options[Settings::OPTION_CREDENTIALS] = ['organization' => '', 'project' => '', 'token' => ''];
        $this->processor->shouldNotReceive('run');

        $result = $this->controller->processQueue(new WP_REST_Request(['limit' => 5]));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_configured', $result->get_error_code());
        $this->assertSame(['status' => 400], $result->get_error_data());
    }

    #[Test]
    public function processing_returns_the_batch_result_and_remaining_count(): void
    {
        $this->processor->shouldReceive('run')->once()->with(5, 25)->andReturn(new \Quant\Sync\SyncResult(4, 1, ['post 9: boom']));
        $this->wpdb->shouldReceive('get_results')->once()->andReturn([['status' => 'pending', 'count' => '3']]);

        $result = $this->controller->processQueue(new WP_REST_Request(['limit' => 5]));

        $this->assertSame(['success' => true, 'processed' => 4, 'failed' => 1, 'remaining' => 3, 'errors' => ['post 9: boom']], $result);
    }

    #[Test]
    public function site_admins_on_multisite_cannot_change_where_the_server_sends_requests(): void
    {
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('current_user_can')->alias(static fn(string $cap): bool => $cap !== 'manage_network_options');
        $this->options[Settings::OPTION_STATIC_CONFIG] = ['webserver_url' => 'http://wordpress', 'webserver_host' => 'example.com'];

        $this->controller->saveSettings(new WP_REST_Request([], [
            'webserver_url' => 'http://169.254.169.254',
            'webserver_host' => 'evil.test',
            'route_404' => '/missing',
        ]));

        $this->assertSame('http://wordpress', $this->options[Settings::OPTION_STATIC_CONFIG]['webserver_url']);
        $this->assertSame('example.com', $this->options[Settings::OPTION_STATIC_CONFIG]['webserver_host']);
        $this->assertSame('/missing', $this->options[Settings::OPTION_STATIC_CONFIG]['route_404']);
    }

    #[Test]
    public function network_admins_on_multisite_can_change_the_webserver_url(): void
    {
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $this->controller->saveSettings(new WP_REST_Request([], ['webserver_url' => 'http://localhost/']));

        $this->assertSame('http://localhost', $this->options[Settings::OPTION_STATIC_CONFIG]['webserver_url']);
    }
}
