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
    /** @var array<string, string> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->options = ['date_format' => 'Y-m-d', 'time_format' => 'H:i'];
        Functions\when('get_option')->alias(fn(string $key, $default = false) => $this->options[$key] ?? $default);
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
    public function a_time_format_that_shows_the_zone_gets_no_second_zone(): void
    {
        $at = (int) strtotime('2026-11-14 10:00:00 UTC');

        $this->options['time_format'] = 'H:i T';
        $this->assertSame('21:00 AEDT', SiteTime::time($at));

        $this->options['time_format'] = 'H:i P';
        $this->assertSame('21:00 +11:00', SiteTime::time($at));

        $this->options['time_format'] = 'H:i O';
        $this->assertSame('21:00 +1100', SiteTime::time($at));

        $this->options['time_format'] = 'H:i e';
        $this->assertSame('21:00 Australia/Sydney', SiteTime::time($at));
    }

    #[Test]
    public function an_escaped_zone_letter_is_text_and_the_zone_is_added(): void
    {
        // "\T" is a literal T (as in ISO dates), not the zone.
        $this->options['time_format'] = '\\TH:i';

        $this->assertSame('T21:00 AEDT', SiteTime::time((int) strtotime('2026-11-14 10:00:00 UTC')));
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
