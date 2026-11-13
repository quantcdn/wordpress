<?php

declare(strict_types=1);

namespace Quant\Core;

/**
 * Times for the admin screens, in the site's time zone, with the zone shown.
 *
 * The queue stores times in UTC (gmdate). A browser that parses
 * "2026-11-14 10:00:00" reads it as its own local time, and wp-admin users
 * can be in a different zone from the site, so the plugin formats the times
 * on the server with wp_date() and adds the zone (for example "AEDT" or
 * "+10:00").
 */
final class SiteTime
{
    /**
     * A UTC DATETIME value ("Y-m-d H:i:s") as a date and time in the site's time zone.
     */
    public static function fromUtc(?string $utc): ?string
    {
        $timestamp = self::utcTimestamp($utc);

        return $timestamp === null ? null : self::dateTime($timestamp);
    }

    /**
     * A UTC DATETIME value as ISO 8601 with the zone (for sorting and machines), or null.
     */
    public static function isoFromUtc(?string $utc): ?string
    {
        $timestamp = self::utcTimestamp($utc);

        return $timestamp === null ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * A Unix timestamp as a date and time in the site's time zone, with the zone.
     */
    public static function dateTime(int $timestamp): string
    {
        return (string) wp_date(self::withZone(get_option('date_format', 'F j, Y') . ' ' . get_option('time_format', 'g:i a')), $timestamp);
    }

    /**
     * A Unix timestamp as a time in the site's time zone, with the zone.
     */
    public static function time(int $timestamp): string
    {
        return (string) wp_date(self::withZone((string) get_option('time_format', 'g:i a')), $timestamp);
    }

    /**
     * The format with ' T' (the zone) added, unless it already shows a zone: an unescaped T, e, O or P.
     */
    private static function withZone(string $format): string
    {
        $plain = (string) preg_replace('/\\\\./s', '', $format);

        return strpbrk($plain, 'TeOP') === false ? $format . ' T' : $format;
    }

    private static function utcTimestamp(?string $utc): ?int
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000-00-00')) {
            return null;
        }

        $timestamp = strtotime($utc . ' UTC');

        return $timestamp === false ? null : $timestamp;
    }
}
