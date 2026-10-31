<?php

declare(strict_types=1);

namespace Quant\Tests\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Core\Activator;
use Quant\Core\Settings;
use Quant\Core\Upgrader;
use Quant\Migration\V1Detector;
use Quant\Migration\V1Migrator;

#[CoversClass(Upgrader::class)]
final class UpgraderTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var array<string, mixed> */
    private array $options = [];
    private Upgrader $upgrader;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(fn(string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('__')->returnArg();

        // Schema already current, so Activator::maybeUpgrade() is a no-op without a database.
        $this->options['quant_db_version'] = Activator::DB_VERSION;
        $this->options['quant_internal_token'] = 'tok';

        $wpdb = Mockery::mock(\wpdb::class);
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturnUsing(static fn(string $sql): string => $sql);
        $wpdb->shouldReceive('get_var')->andReturn('0');

        $detector = new V1Detector($wpdb);
        $this->upgrader = new Upgrader($detector, new V1Migrator($detector, new Settings(), $wpdb));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_does_nothing_when_the_version_is_current(): void
    {
        $this->options[Upgrader::OPTION_VERSION] = QUANT_VERSION;

        $this->upgrader->maybeUpgrade();

        $this->assertArrayNotHasKey(Upgrader::OPTION_NOTICE, $this->options);
    }

    #[Test]
    public function it_migrates_v1_settings_automatically_on_first_load_after_the_update(): void
    {
        $this->options[Upgrader::OPTION_VERSION] = '1.6.0';
        $this->options[V1Detector::V1_SETTINGS_OPTION] = ['api_account' => 'acme', 'api_project' => 'site', 'api_token' => 'tok', 'enabled' => 1];

        $this->upgrader->maybeUpgrade();

        $this->assertSame(QUANT_VERSION, $this->options[Upgrader::OPTION_VERSION]);
        $this->assertSame('acme', $this->options[Settings::OPTION_CREDENTIALS]['organization']);
        $this->assertSame('success', $this->options[Upgrader::OPTION_NOTICE]['type']);
        $this->assertTrue($this->options['quant_migration_complete']);
    }

    #[Test]
    public function it_warns_when_the_v1_settings_are_incomplete(): void
    {
        $this->options[Upgrader::OPTION_VERSION] = '1.6.0';
        $this->options[V1Detector::V1_SETTINGS_OPTION] = ['api_account' => 'acme'];

        $this->upgrader->maybeUpgrade();

        $this->assertSame('warning', $this->options[Upgrader::OPTION_NOTICE]['type']);
        $this->assertArrayNotHasKey(Settings::OPTION_CREDENTIALS, $this->options);
    }

    #[Test]
    public function it_records_the_version_on_a_fresh_install_without_a_notice(): void
    {
        $this->upgrader->maybeUpgrade();

        $this->assertSame(QUANT_VERSION, $this->options[Upgrader::OPTION_VERSION]);
        $this->assertArrayNotHasKey(Upgrader::OPTION_NOTICE, $this->options);
    }
}
