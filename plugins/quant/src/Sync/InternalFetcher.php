<?php

declare(strict_types=1);

namespace Quant\Sync;

use Quant\Core\Settings;
use RuntimeException;

/**
 * Fetches pages from the local web server the way the Quant edge will see them.
 *
 * The request goes to the configured Webserver URL (default: the site URL)
 * with the configured Host header, carries the internal token so WordPress
 * can recognise it, and does not follow redirects so that redirects can be
 * recorded as such.
 */
class InternalFetcher
{
    public const TOKEN_HEADER = 'Quant-Token';
    public const TOKEN_OPTION = 'quant_internal_token';

    public function __construct(
        private readonly Settings $settings
    ) {
    }

    /**
     * Fetch a path (or absolute URL) from the local server.
     *
     * @throws RuntimeException when the HTTP request itself fails
     */
    public function fetch(string $pathOrUrl, int $timeout = 60): FetchResult
    {
        $original = $this->toAbsoluteUrl($pathOrUrl);
        $url = $this->toInternalUrl($original);
        $args = $this->requestArgs($timeout);

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            throw new RuntimeException("Unable to connect to local webserver at {$url}: " . $response->get_error_message());
        }

        $location = wp_remote_retrieve_header($response, 'location');
        $contentType = wp_remote_retrieve_header($response, 'content-type');

        return new FetchResult(
            status: (int) wp_remote_retrieve_response_code($response),
            body: (string) wp_remote_retrieve_body($response),
            contentType: is_string($contentType) ? $contentType : '',
            redirectLocation: is_string($location) && $location !== '' ? $location : null,
            requestedUrl: $url,
        );
    }

    /**
     * The URL the request is sent to, after the Webserver URL override.
     */
    public function toInternalUrl(string $absoluteUrl): string
    {
        $config = $this->settings->getStaticConfig();
        $webserverUrl = rtrim((string) $config['webserver_url'], '/');

        $parsed = wp_parse_url($absoluteUrl);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';

        $url = $webserverUrl !== '' ? $webserverUrl . $path . $query : $absoluteUrl;

        /** This filter is documented in v2.0.0; it adjusts the URL used for internal rendering. */
        return (string) apply_filters('quant_internal_fetch_url', $url, $absoluteUrl);
    }

    /**
     * The Host header value sent with internal requests, or '' for none.
     */
    public function hostHeader(): string
    {
        $config = $this->settings->getStaticConfig();
        $host = trim((string) $config['webserver_host']);

        if ($host !== '') {
            return $host;
        }

        // When a Webserver URL is set but no Host header, WordPress still
        // needs the public host to build correct absolute URLs.
        if (trim((string) $config['webserver_url']) !== '') {
            $home = wp_parse_url(home_url());
            $host = $home['host'] ?? '';
            if (isset($home['port'])) {
                $host .= ':' . $home['port'];
            }
        }

        return $host;
    }

    public function internalToken(): string
    {
        $token = get_option(self::TOKEN_OPTION);

        return is_string($token) ? $token : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function requestArgs(int $timeout): array
    {
        $credentials = $this->settings->getCredentials();

        $headers = [
            self::TOKEN_HEADER => $this->internalToken(),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'User-Agent' => 'QuantCDN-WordPress/' . (defined('QUANT_VERSION') ? QUANT_VERSION : '2.0.0'),
        ];

        $host = $this->hostHeader();
        if ($host !== '') {
            $headers['Host'] = $host;
        }

        $args = [
            'timeout' => $timeout,
            'redirection' => 0,
            'sslverify' => !$credentials['disable_tls_verify'],
            'headers' => $headers,
            // Pass basic auth through when the admin request carried it.
            'cookies' => [],
        ];

        if (!empty($_SERVER['PHP_AUTH_USER'])) {
            $args['headers']['Authorization'] = 'Basic ' . base64_encode(
                $_SERVER['PHP_AUTH_USER'] . ':' . ($_SERVER['PHP_AUTH_PW'] ?? '')
            );
        }

        /** This filter is documented in v2.0.0; it adjusts the request args used for internal rendering. */
        return (array) apply_filters('quant_internal_fetch_args', $args);
    }

    private function toAbsoluteUrl(string $pathOrUrl): string
    {
        if (preg_match('#^https?://#i', $pathOrUrl) === 1) {
            return $pathOrUrl;
        }

        return home_url('/' . ltrim($pathOrUrl, '/'));
    }
}
