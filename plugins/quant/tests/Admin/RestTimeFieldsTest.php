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
use Quant\Api\ApiResponse;
use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Redirects\RedirectProvider;
use Quant\Sync\DatabaseQueue;
use Quant\Sync\InternalFetcher;
use Quant\Sync\JobProcessor;
use Quant\Sync\Redirect;
use Quant\Sync\Seeder;
use Quant\Sync\ThemeAssetSync;
use Quant\Tests\Support\WordPressStubs;
use WP_REST_Request;

/**
 * The REST responses carry times for the admin screens in the site time zone: last_synced (ISO 8601 UTC) and
 * last_synced_label on listed items, next_cron and next_cron_label on the status.
 */
#[CoversClass(RestController::class)]
final class RestTimeFieldsTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private const NEXT_RUN = 1794650400; // 2026-11-14 10:00:00 UTC, 21:00 AEDT.

    /** @var array<string, mixed> */
    private array $options = [];
    private RestController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $this->options = [
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            Settings::OPTION_CREDENTIALS => ['organization' => 'acme', 'project' => 'site', 'token' => 'secret', 'endpoint' => 'https://api.quantcdn.io'],
        ];
        Functions\when('get_option')->alias(fn(string $key, $default = false) => $this->options[$key] ?? $default);
        Functions\when('wp_next_scheduled')->justReturn(self::NEXT_RUN);
        Functions\when('wp_date')->alias(
            static fn(string $format, int $timestamp): string => (new \DateTimeImmutable('@' . $timestamp))
                ->setTimezone(new \DateTimeZone('Australia/Sydney'))
                ->format($format)
        );

        $api = Mockery::mock(ContentApiInterface::class);
        $api->allows('ping')->andReturn(ApiResponse::success([]));

        $wpdb = Mockery::mock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $wpdb->allows('prepare')->andReturn('SQL');
        $wpdb->allows('get_results')->andReturnUsing(static fn(string $sql): array => $sql === 'SQL'
            ? [['object_id' => '/old', 'status' => 'completed', 'processed_at' => '2026-11-14 10:00:00']]
            : []);

        $redirects = Mockery::mock(RedirectProvider::class);
        $redirects->allows('all')->andReturn([new Redirect('/old', '/new')]);

        $this->controller = new RestController(
            $api,
            new Settings(),
            new DatabaseQueue($wpdb),
            Mockery::mock(JobProcessor::class),
            Mockery::mock(Seeder::class),
            new ThemeAssetSync($api),
            $redirects,
            Mockery::mock(InternalFetcher::class)
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function the_status_has_the_next_background_run_in_the_site_time_zone(): void
    {
        $status = $this->controller->getStatus(new WP_REST_Request());

        $this->assertSame(self::NEXT_RUN, $status['next_cron']);
        $this->assertSame('21:00 AEDT', $status['next_cron_label']);
    }

    #[Test]
    public function listed_items_have_last_synced_as_utc_iso_and_a_site_time_label(): void
    {
        $request = Mockery::mock(WP_REST_Request::class);
        $request->allows('get_param')->andReturnUsing(static fn(string $key): mixed => ['page' => 1, 'per_page' => 20][$key] ?? null);

        $item = $this->controller->getRedirects($request)['items'][0];

        $this->assertSame('2026-11-14T10:00:00Z', $item['last_synced']);
        $this->assertSame('2026-11-14 21:00 AEDT', $item['last_synced_label']);
    }
}
