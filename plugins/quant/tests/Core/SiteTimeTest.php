<?php

declare(strict_types=1);

namespace Quant\Tests\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Core\SiteTime;

/**
 * The queue stores UTC times. The admin screens show them in the site's time zone, with the zone, so a reader
 * in another zone (or a browser that reads "Y-m-d H:i:s" as local time) sees the right time.
 */
#[CoversClass(SiteTime::class)]
final class SiteTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $options = ['date_format' => 'Y-m-d', 'time_format' => 'H:i'];
        Functions\when('get_option')->alias(static fn(string $key, $default = false) => $options[$key] ?? $default);
        // wp_date() formats in the site's time zone (Settings > General); here the site is in Sydney.
        Functions\when('wp_date')->alias(
            static fn(string $format, int $timestamp): string => (new \DateTimeImmutable('@' . $timestamp))
                ->setTimezone(new \DateTimeZone('Australia/Sydney'))
                ->format($format)
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function a_stored_utc_time_shows_in_the_site_time_zone_with_the_zone(): void
    {
        // 10:00 UTC on 14 November is 21:00 in Sydney (AEDT, UTC+11).
        $this->assertSame('2026-11-14 21:00 AEDT', SiteTime::fromUtc('2026-11-14 10:00:00'));
    }

    #[Test]
    public function the_machine_value_is_iso_8601_utc(): void
    {
        $this->assertSame('2026-11-14T10:00:00Z', SiteTime::isoFromUtc('2026-11-14 10:00:00'));
    }

    #[Test]
    public function the_next_background_run_shows_the_time_and_the_zone(): void
    {
        $this->assertSame('21:00 AEDT', SiteTime::time((int) strtotime('2026-11-14 10:00:00 UTC')));
    }

    #[Test]
    public function an_empty_time_is_null(): void
    {
        $this->assertNull(SiteTime::fromUtc(null));
        $this->assertNull(SiteTime::fromUtc(''));
        $this->assertNull(SiteTime::fromUtc('0000-00-00 00:00:00'));
        $this->assertNull(SiteTime::isoFromUtc(null));
    }
}
