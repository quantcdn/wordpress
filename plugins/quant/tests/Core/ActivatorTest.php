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

#[CoversClass(Activator::class)]
final class ActivatorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->options[$key] = $value;
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function wpdb(?string $column): \wpdb&Mockery\MockInterface
    {
        $wpdb = Mockery::mock(\wpdb::class);
        $wpdb->shouldReceive('prepare')->once()
            ->with('SHOW COLUMNS FROM `wp_quant_sync_queue` LIKE %s', 'error_detail')
            ->andReturn('sql');
        $wpdb->shouldReceive('get_var')->once()->with('sql')->andReturn($column);

        return $wpdb;
    }

    #[Test]
    public function it_records_the_schema_version_when_the_new_column_exists(): void
    {
        $this->assertTrue(Activator::markSchemaCurrent($this->wpdb('error_detail'), 'wp_quant_sync_queue'));
        $this->assertSame(Activator::DB_VERSION, $this->options['quant_db_version']);
    }

    #[Test]
    public function it_keeps_the_old_version_when_dbdelta_did_not_add_the_column(): void
    {
        // The upgrade then runs again on the next request.
        $this->assertFalse(Activator::markSchemaCurrent($this->wpdb(null), 'wp_quant_sync_queue'));
        $this->assertArrayNotHasKey('quant_db_version', $this->options);
    }
}
