<?php

declare(strict_types=1);

namespace Quant\Tests\CLI;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\ContentApiInterface;
use Quant\CLI\Commands;
use Quant\Core\Settings;
use Quant\Search\SearchRecordBuilder;
use Quant\Sync\JobProcessor;
use Quant\Sync\Seeder;
use Quant\Sync\SyncQueueInterface;
use ReflectionMethod;

/**
 * The parts of the WP-CLI command class that do not need WP-CLI itself.
 */
#[CoversClass(Commands::class)]
final class CommandsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private mixed $originalArgv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalArgv = $GLOBALS['argv'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['argv'] = $this->originalArgv;
        parent::tearDown();
    }

    private function commands(): Commands
    {
        return new Commands(
            Mockery::mock(ContentApiInterface::class),
            Mockery::mock(Settings::class),
            Mockery::mock(SyncQueueInterface::class),
            Mockery::mock(JobProcessor::class),
            Mockery::mock(Seeder::class),
            Mockery::mock(SearchRecordBuilder::class)
        );
    }

    private function wpBinary(Commands $commands): string
    {
        return (string) (new ReflectionMethod($commands, 'wpBinary'))->invoke($commands);
    }

    #[Test]
    public function workers_run_the_same_wp_cli_script_with_the_same_php(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'wp-cli');
        $GLOBALS['argv'] = [$script, 'quant', 'queue', 'process'];

        $expected = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg((string) realpath($script));

        try {
            $binary = $this->wpBinary($this->commands());
        } finally {
            unlink($script);
        }

        $this->assertSame($expected, $binary);
    }

    #[Test]
    public function workers_fall_back_to_wp_on_the_path(): void
    {
        $GLOBALS['argv'] = ['wp-not-a-file-' . uniqid()];
        $this->assertSame('wp', $this->wpBinary($this->commands()));

        unset($GLOBALS['argv']);
        $this->assertSame('wp', $this->wpBinary($this->commands()));
    }

    #[Test]
    public function the_worker_runner_never_resets_jobs_other_processes_are_working_on(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/CLI/Commands.php');

        $this->assertStringNotContainsString('recoverStale(0)', $source);
        $this->assertStringContainsString('proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes)', $source);
        $this->assertStringContainsString('$this->processor->run($limit, 300)', $source);
    }
}
