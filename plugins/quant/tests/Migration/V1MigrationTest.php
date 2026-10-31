<?php

declare(strict_types=1);

namespace Quant\Tests\Migration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Core\Settings;
use Quant\Migration\MigrationResult;
use Quant\Migration\V1Detector;
use Quant\Migration\V1Migrator;

#[CoversClass(V1Detector::class)]
#[CoversClass(V1Migrator::class)]
#[CoversClass(MigrationResult::class)]
final class V1MigrationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var array<string, mixed> */
    private array $options = [];
    private \wpdb&Mockery\MockInterface $wpdb;
    private V1Detector $detector;
    private V1Migrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(fn(string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function (string $key): bool {
            unset($this->options[$key]);
            return true;
        });

        $this->wpdb = Mockery::mock(\wpdb::class);
        $this->wpdb->options = 'wp_options';
        $this->wpdb->shouldReceive('esc_like')->andReturnArg(0)->byDefault();
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(static fn(string $sql): string => $sql)->byDefault();
        $this->wpdb->shouldReceive('get_var')->andReturn('0')->byDefault();

        $this->detector = new V1Detector($this->wpdb);
        $this->migrator = new V1Migrator($this->detector, new Settings(), $this->wpdb);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function seedV1(): void
    {
        $this->options[V1Detector::V1_SETTINGS_OPTION] = [
            'enabled' => '1',
            'purge_on_save' => '0',
            'disable_tls_verify' => '1',
            'http_request_timeout' => '45',
            'webserver_url' => 'http://localhost',
            'webserver_host' => 'www.example.com',
            'api_endpoint' => 'https://api.quantgov.cloud',
            'api_account' => 'acme',
            'api_project' => 'site',
            'api_token' => 'secret-token-1234',
        ];
        $this->options[V1Detector::V1_SEED_OPTION] = [
            '404_route' => '/not-found',
            'custom_routes' => "/robots.txt\n/humans.txt\n",
            'domains_strip' => "cdn.example.com\n",
        ];
        $this->options[V1Detector::V1_CRON_OPTION] = [
            'cron_enabled' => '1',
            'cron_schedule' => 'twicedaily',
            'cron_home' => '1',
            'cron_posts' => '1',
            'cron_categories' => '1',
            'cron_custom_routes' => '/robots.txt',
        ];
    }

    #[Test]
    public function it_detects_fresh_installs_v1_sites_and_migrated_sites(): void
    {
        $this->assertTrue($this->detector->isFreshInstall());
        $this->assertFalse($this->detector->needsMigration());

        $this->seedV1();
        $this->assertTrue($this->detector->hasV1Settings());
        $this->assertTrue($this->detector->needsMigration());
        $this->assertFalse($this->detector->isFreshInstall());

        $this->options['quant_credentials'] = ['organization' => 'a', 'project' => 'b', 'token' => 'c'];
        $this->assertTrue($this->detector->hasV2Settings());
        $this->assertFalse($this->detector->needsMigration());
    }

    #[Test]
    public function it_maps_every_v1_field(): void
    {
        $this->seedV1();

        $v1 = $this->detector->getV1Settings();

        $this->assertSame('acme', $v1['organization']);
        $this->assertSame('https://api.quantgov.cloud', $v1['endpoint']);
        $this->assertTrue($v1['disable_tls_verify']);
        $this->assertSame(45, $v1['http_request_timeout']);
        $this->assertTrue($v1['auto_sync']);
        $this->assertFalse($v1['auto_purge']);
        $this->assertSame('www.example.com', $v1['webserver_host']);
        $this->assertSame('/not-found', $v1['route_404']);
        $this->assertSame(['/robots.txt', '/humans.txt'], $v1['custom_routes']);
        $this->assertSame(['cdn.example.com'], $v1['domain_rewrites']);
        $this->assertSame(['enabled' => true, 'interval' => 'twicedaily', 'types' => ['home', 'post', 'taxonomy'], 'custom_routes' => ['/robots.txt']], $v1['schedule']);
    }

    #[Test]
    public function it_defaults_purge_on_save_to_on_when_v1_never_stored_it(): void
    {
        $this->options[V1Detector::V1_SETTINGS_OPTION] = ['api_account' => 'a', 'api_project' => 'b', 'api_token' => 'c'];

        $v1 = $this->detector->getV1Settings();

        $this->assertTrue($v1['auto_purge']);
        $this->assertFalse($v1['auto_sync']);
        $this->assertSame(['/robots.txt'], $v1['custom_routes']);
        $this->assertSame('daily', $v1['schedule']['interval']);
    }

    #[Test]
    public function it_migrates_everything_into_the_v2_options_and_marks_completion(): void
    {
        $this->seedV1();

        $result = $this->migrator->migrate();

        $this->assertTrue($result->success);
        $this->assertSame('***1234', $result->migrated['credentials']['token']);
        $this->assertTrue($this->migrator->isMigrationComplete());
        $this->assertNotNull($this->migrator->getMigrationDate());

        $settings = new Settings();
        $this->assertSame(['organization' => 'acme', 'project' => 'site', 'token' => 'secret-token-1234', 'endpoint' => 'https://api.quantgov.cloud', 'disable_tls_verify' => true, 'http_request_timeout' => 45], $settings->getCredentials());
        $this->assertSame('static', $settings->getMode());
        $this->assertTrue($settings->getSyncSettings()['auto_sync']);
        $this->assertFalse($settings->getSyncSettings()['auto_purge']);
        $this->assertSame('/not-found', $settings->getStaticConfig()['route_404']);
        $this->assertSame(['cdn.example.com'], $settings->getStaticConfig()['domain_rewrites']);
        $this->assertSame('twicedaily', $settings->getSchedule()['interval']);
        $this->assertArrayHasKey(V1Detector::V1_SETTINGS_OPTION, $this->options, 'v1 options stay until cleanup');
    }

    #[Test]
    public function it_refuses_to_migrate_twice_or_without_v1_settings(): void
    {
        $this->assertSame(['No v1 settings found to migrate'], $this->migrator->migrate()->errors);

        $this->seedV1();
        $this->migrator->migrate();
        $this->assertSame(['Migration has already been completed'], $this->migrator->migrate()->errors);

        $this->migrator->resetMigration();
        $this->options[V1Detector::V1_SETTINGS_OPTION]['api_token'] = '';
        $this->assertStringContainsString('complete set of API credentials', $this->migrator->migrate()->errors[0]);
    }

    #[Test]
    public function it_cleans_up_v1_options_and_batch_state(): void
    {
        $this->seedV1();
        $this->wpdb->shouldReceive('query')->once()->with(Mockery::on(static fn(string $sql): bool => str_contains($sql, 'DELETE FROM wp_options')));

        $this->assertTrue($this->migrator->cleanup());
        $this->assertArrayNotHasKey(V1Detector::V1_SETTINGS_OPTION, $this->options);
        $this->assertArrayNotHasKey(V1Detector::V1_CRON_OPTION, $this->options);
    }

    #[Test]
    public function it_reports_a_status_summary_including_leftover_batch_rows(): void
    {
        $this->seedV1();
        $this->wpdb->shouldReceive('get_var')->andReturn('12');

        $status = $this->detector->getStatus();

        $this->assertTrue($status['needs_migration']);
        $this->assertSame(12, $status['v1_queue_count']);
        $this->assertSame([V1Detector::V1_SETTINGS_OPTION, V1Detector::V1_SEED_OPTION, V1Detector::V1_CRON_OPTION], $this->detector->getV1OptionNames());
    }
}
