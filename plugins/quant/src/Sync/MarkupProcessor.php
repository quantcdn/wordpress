<?php

declare(strict_types=1);

namespace Quant\Sync;

use Quant\Core\Settings;

/**
 * Rewrites rendered markup for the static edge.
 *
 * Absolute URLs on the site's own host (and on any configured domain
 * rewrite) become relative. Remaining external http:// references are
 * upgraded to https:// because the edge serves over TLS only. This matches
 * v1 and the Drupal module's "Strip host domain from content" option.
 */
class MarkupProcessor
{
    public function __construct(
        private readonly Settings $settings
    ) {
    }

    /**
     * @param string $contentType The response content type; only HTML is rewritten,
     *                            so XML sitemaps and robots.txt keep absolute URLs.
     */
    public function process(string $markup, string $contentType = 'text/html'): string
    {
        $config = $this->settings->getStaticConfig();

        if (empty($config['strip_host']) || !$this->isHtml($contentType)) {
            return $markup;
        }

        foreach ($this->hostsToStrip($config) as $host) {
            $markup = $this->stripHost($markup, $host);
        }

        $markup = $this->stripPrivateHosts($markup);

        $markup = preg_replace('#http://#i', 'https://', $markup) ?? $markup;

        /** This filter is documented in v2.0.0; it adjusts the processed markup before publishing. */
        return (string) apply_filters('quant_processed_markup', $markup);
    }

    /**
     * Hosts (with optional :port) whose absolute URLs become relative.
     *
     * @param array<string, mixed> $config
     * @return array<string>
     */
    public function hostsToStrip(array $config): array
    {
        $hosts = [];

        // home_url() follows WP_HOME, which on many hosts is the request host. The
        // stored home and siteurl (usually the public domain) are stripped too,
        // so the result is the same from a web request, WP-Cron and WP-CLI.
        foreach (array_merge([home_url(), site_url()], $this->storedSiteUrls()) as $url) {
            $parsed = wp_parse_url($url);
            if (!empty($parsed['host'])) {
                $hosts[] = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
                $hosts[] = $parsed['host'];
            }
        }

        $webserverHost = trim((string) ($config['webserver_host'] ?? ''));
        if ($webserverHost !== '') {
            $hosts[] = $webserverHost;
        }

        foreach ((array) ($config['domain_rewrites'] ?? []) as $domain) {
            $domain = trim((string) $domain);
            $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
            $domain = rtrim($domain, '/');
            if ($domain !== '') {
                $hosts[] = $domain;
            }
        }

        // Longest first so host:port is handled before the bare host.
        $hosts = array_values(array_unique($hosts));
        usort($hosts, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $hosts;
    }

    private function isHtml(string $contentType): bool
    {
        $type = strtolower(trim(strtok($contentType, ';') ?: ''));

        return $type === '' || $type === 'text/html' || $type === 'application/xhtml+xml';
    }

    /**
     * The home and siteurl values in the database, ignoring WP_HOME / WP_SITEURL.
     *
     * @return array<string>
     */
    private function storedSiteUrls(): array
    {
        static $urls = null;
        if ($urls !== null) {
            return $urls;
        }

        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return [];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- get_option() would return the WP_HOME override.
        $values = $wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name IN ('home', 'siteurl')");
        $urls = array_values(array_filter(is_array($values) ? $values : [], 'is_string'));

        return $urls;
    }

    /**
     * A public static copy can never load from a private address. Plugins
     * such as Smart Slider cache absolute URLs with whatever host rendered
     * them (an internal container IP, localhost); browsers then fail to load
     * them and ask the visitor for local-network permission. URLs on localhost
     * and private IPv4 ranges become relative.
     */
    private function stripPrivateHosts(string $markup): string
    {
        $host = '(?:localhost|127(?:\.\d{1,3}){3}|10(?:\.\d{1,3}){3}|192\.168(?:\.\d{1,3}){2}|172\.(?:1[6-9]|2\d|3[01])(?:\.\d{1,3}){2})(?::\d+)?';

        $patterns = [
            "#(?:https?:)?//{$host}(?=/)#i",
            "#(?:https?:)?\\\\/\\\\/{$host}(?=\\\\/)#i",
        ];

        return preg_replace($patterns, '', $markup) ?? $markup;
    }

    private function stripHost(string $markup, string $host): string
    {
        $quoted = preg_quote($host, '#');

        // Scheme-relative and absolute forms, plus JSON-escaped slashes (\/) as emitted by wp_json_encode.
        $patterns = [
            "#https?://{$quoted}(?=[/\"'\\s?\#)<>]|$)#i",
            "#//{$quoted}(?=[/\"'\\s?\#)<>]|$)#i",
            "#https?:\\\\/\\\\/{$quoted}(?=\\\\/|\"|$)#i",
        ];

        return preg_replace($patterns, '', $markup) ?? $markup;
    }
}
