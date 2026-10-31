<?php

declare(strict_types=1);

namespace Quant\Api;

use Quant\Core\Settings;

/**
 * Content API v1 client.
 *
 * Paths follow the Quant Content API: markup and files go to POST /v1, files
 * as multipart with a Quant-File-Url header, unpublish is PATCH /v1/unpublish
 * with Quant-Url, purge is POST /v1/purge with Quant-Url.
 */
class QuantContentApi implements ContentApiInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClientInterface $http
    ) {
    }

    public function ping(): ApiResponse
    {
        return $this->parse($this->http->get($this->url('/ping'), $this->headers()));
    }

    public function publish(string $url, string $markup, array $info = [], array $options = []): ApiResponse
    {
        $body = [
            'url' => $this->normalisePath($url),
            'content' => $markup,
            'published' => (bool) ($options['published'] ?? true),
            'content_timestamp' => (int) ($options['content_timestamp'] ?? time()),
            'find_attachments' => (bool) ($options['find_attachments'] ?? true),
        ];

        $filteredInfo = $this->filterInfo($info);
        if ($filteredInfo !== []) {
            $body['info'] = $filteredInfo;
        }

        foreach (['search_record', 'proxy_override', 'headers', 'transitions'] as $key) {
            if (array_key_exists($key, $options) && $options[$key] !== null) {
                $body[$key] = $options[$key];
            }
        }

        return $this->parse($this->http->post($this->url(''), $this->headers(), $body));
    }

    public function unpublish(string $url): ApiResponse
    {
        $response = $this->http->patch(
            $this->url('/unpublish'),
            $this->headers(['Quant-Url' => $this->normalisePath($url)])
        );

        $parsed = $this->parse($response);

        // Unpublishing something that is already gone is a success for the caller.
        if (!$parsed->success && $this->isAlreadyUnpublished($parsed)) {
            return ApiResponse::success($parsed->data, 'Already unpublished');
        }

        return $parsed;
    }

    public function uploadFile(string $path, string $localPath): ApiResponse
    {
        if (!is_file($localPath) || !is_readable($localPath)) {
            return ApiResponse::error("File not found: {$localPath}");
        }

        $response = $this->http->upload(
            $this->url(''),
            $this->headers(['Quant-File-Url' => $this->normalisePath($path)]),
            $localPath
        );

        $parsed = $this->parse($response);

        if (!$parsed->success && str_contains(strtolower($parsed->message), 'md5 already matches')) {
            return ApiResponse::success($parsed->data, 'File unchanged');
        }

        return $parsed;
    }

    public function createRedirect(string $from, string $to, int $code = 301): ApiResponse
    {
        $from = $this->normalisePath($from);
        $to = $this->normaliseDestination($to);

        if ($from === $to) {
            return ApiResponse::error('Redirect source and destination are the same');
        }

        return $this->parse($this->http->post($this->url('/redirect'), $this->headers(), [
            'url' => $from,
            'redirect_url' => $to,
            'redirect_http_code' => in_array($code, [301, 302, 307, 308], true) ? $code : 301,
            'published' => true,
        ]));
    }

    public function purge(string|array $paths): ApiResponse
    {
        $errors = [];

        foreach ($this->purgeVariants((array) $paths) as $variant) {
            $response = $this->http->post($this->url('/purge'), $this->headers(['Quant-Url' => $variant]));
            $parsed = $this->parse($response);
            if (!$parsed->success) {
                $errors[] = "{$variant}: {$parsed->message}";
            }
        }

        if ($errors !== []) {
            return ApiResponse::error('Failed to purge: ' . implode('; ', $errors));
        }

        return ApiResponse::success([], 'Cache purged successfully');
    }

    public function searchStatus(): ApiResponse
    {
        return $this->parse($this->http->get($this->url('/search'), $this->headers()));
    }

    public function sendSearchRecords(array $records): ApiResponse
    {
        if ($records === []) {
            return ApiResponse::success([], 'No records');
        }

        return $this->parse($this->http->post($this->url('/search'), $this->headers(), array_values($records)));
    }

    public function clearSearchIndex(): ApiResponse
    {
        return $this->parse($this->http->delete($this->url('/search/all'), $this->headers()));
    }

    public function addSearchFacets(array $keys): ApiResponse
    {
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));

        return $this->parse($this->http->post($this->url('/search/facet'), $this->headers(), $keys));
    }

    public function urlMeta(array $urls): ApiResponse
    {
        $urls = array_map(fn(string $url): string => $this->normalisePath($url), $urls);

        return $this->parse($this->http->post($this->url('/url-meta'), $this->headers(), ['Quant-Url' => array_values($urls)]));
    }

    /**
     * Expand paths to the trailing-slash variants Quant may have cached.
     *
     * @param array<string> $paths
     * @return array<string>
     */
    private function purgeVariants(array $paths): array
    {
        $variants = [];

        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }

            if (str_contains($path, '*')) {
                $variants[] = '/' . ltrim($path, '/');
                continue;
            }

            $base = $this->normalisePath($path);
            $variants[] = $base;
            if ($base !== '/' && !str_contains($base, '?')) {
                $variants[] = $base . '/';
            }
        }

        return array_values(array_unique($variants));
    }

    private function url(string $path): string
    {
        return $this->settings->getCredentials()['endpoint'] . '/v1' . $path;
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function headers(array $extra = []): array
    {
        $credentials = $this->settings->getCredentials();

        return array_merge([
            'Quant-Token' => $credentials['token'],
            'Quant-Project' => $credentials['project'],
            'Quant-Customer' => $credentials['organization'],
        ], $extra);
    }

    /**
     * Keep only the `info` keys the API accepts and trim them to its limits.
     *
     * @param array<string, mixed> $info
     * @return array<string, string>
     */
    private function filterInfo(array $info): array
    {
        $limits = [
            'author_user' => 128,
            'author_name' => 128,
            'author_email' => 255,
            'log' => 255,
            'custom_1' => 255,
            'custom_2' => 255,
            'source' => 50,
        ];

        $filtered = [];
        foreach ($limits as $key => $limit) {
            if (isset($info[$key]) && is_scalar($info[$key]) && (string) $info[$key] !== '') {
                $filtered[$key] = mb_substr((string) $info[$key], 0, $limit);
            }
        }

        return $filtered;
    }

    /**
     * Relative path starting with one slash and without a trailing slash (except `/`).
     */
    private function normalisePath(string $path): string
    {
        $path = trim($path);
        if (preg_match('#^https?://#i', $path) === 1) {
            $parsed = wp_parse_url($path);
            $path = ($parsed['path'] ?? '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        }

        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;

        if ($path !== '/' && !str_contains($path, '?')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * Redirect destinations may be absolute URLs.
     */
    private function normaliseDestination(string $to): string
    {
        $to = trim($to);
        if (preg_match('#^https?://#i', $to) === 1) {
            return $to;
        }

        return $this->normalisePath($to);
    }

    private function isAlreadyUnpublished(ApiResponse $response): bool
    {
        $message = strtolower($response->message);

        return $response->statusCode === 404
            || str_contains($message, 'already unpublished')
            || str_contains($message, 'not found');
    }

    /**
     * @param array{code?: int, body?: string, error?: string} $response
     */
    private function parse(array $response): ApiResponse
    {
        if (isset($response['error'])) {
            return ApiResponse::error((string) $response['error'], 0);
        }

        $code = (int) ($response['code'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $body = is_array($decoded) ? $decoded : [];

        if ($code >= 200 && $code < 300 && empty($body['error'])) {
            return ApiResponse::success($body);
        }

        $message = $body['message'] ?? $body['errorMsg'] ?? null;
        if (!is_string($message) || $message === '') {
            $message = is_string($body['error'] ?? null) ? $body['error'] : "HTTP {$code}";
        }

        return ApiResponse::error($message, $code, $body);
    }
}
