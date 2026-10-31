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

        foreach ([home_url(), site_url()] as $url) {
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
