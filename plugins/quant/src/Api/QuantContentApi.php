<?php

declare(strict_types=1);

namespace Quant\Api;

use Quant\Core\Settings;

class QuantContentApi implements ContentApiInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly HttpClientInterface $http
    ) {
    }

    private function getEndpoint(): string
    {
        $credentials = $this->settings->getCredentials();
        return rtrim($credentials['endpoint'] ?? 'https://api.quantcdn.io', '/');
    }

    private function getToken(): string
    {
        return $this->settings->getCredentials()['token'] ?? '';
    }

    private function getProject(): string
    {
        return $this->settings->getCredentials()['project'] ?? '';
    }

    private function getOrganization(): string
    {
        return $this->settings->getCredentials()['organization'] ?? '';
    }

    public function ping(): ApiResponse
    {
        $response = $this->http->get(
            "{$this->getEndpoint()}/v1/ping",
            $this->getHeaders()
        );

        return $this->parseResponse($response);
    }

    public function publish(string $url, string $markup, array $meta = []): ApiResponse
    {
        $url = $this->normalizeUrl($url);

        $body = [
            'url' => $url,
            'content' => $markup,
            'published' => true,
            'content_timestamp' => time(),
        ];

        if (!empty($meta)) {
            $body['info'] = $meta;
        }

        $response = $this->http->post(
            "{$this->getEndpoint()}/v1/content",
            $this->getHeaders(),
            $body
        );

        return $this->parseResponse($response);
    }

    public function unpublish(string $url): ApiResponse
    {
        $url = $this->normalizeUrl($url);

        $response = $this->http->patch(
            "{$this->getEndpoint()}/v1/unpublish",
            array_merge($this->getHeaders(), ['Quant-Url' => $url])
        );

        return $this->parseResponse($response);
    }

    public function uploadFile(string $path, string $localPath): ApiResponse
    {
        // File upload requires special handling with streaming
        // Will be implemented with proper cURL file handling
        if (!file_exists($localPath)) {
            return ApiResponse::error("File not found: {$localPath}");
        }

        // For now, use the WordPress HTTP API with body as file contents
        // This works for smaller files; larger files need streaming
        $fileContents = file_get_contents($localPath);
        if ($fileContents === false) {
            return ApiResponse::error("Could not read file: {$localPath}");
        }

        $mimeType = mime_content_type($localPath) ?: 'application/octet-stream';

        $args = [
            'method' => 'POST',
            'timeout' => 60,
            'headers' => array_merge($this->getHeaders(), [
                'Content-Type' => $mimeType,
                'Quant-File-Url' => $this->normalizeUrl($path),
            ]),
            'body' => $fileContents,
        ];

        $response = wp_remote_request("{$this->getEndpoint()}/v1/file", $args);

        if (is_wp_error($response)) {
            return ApiResponse::error($response->get_error_message());
        }

        return $this->parseResponse([
            'code' => wp_remote_retrieve_response_code($response),
            'body' => wp_remote_retrieve_body($response),
        ]);
    }

    public function createRedirect(string $from, string $to, int $code = 301): ApiResponse
    {
        $from = $this->normalizeUrl($from);

        $response = $this->http->post(
            "{$this->getEndpoint()}/v1/redirect",
            $this->getHeaders(),
            [
                'url' => $from,
                'redirect_url' => $to,
                'redirect_http_code' => $code,
                'published' => true,
            ]
        );

        return $this->parseResponse($response);
    }

    public function purge(string|array $paths): ApiResponse
    {
        $paths = is_array($paths) ? $paths : [$paths];
        $errors = [];

        foreach ($paths as $path) {
            $path = $this->normalizeUrl($path);

            // Purge both with and without trailing slash
            $variants = [$path];
            if ($path !== '/') {
                $variants[] = rtrim($path, '/') . '/';
            }

            foreach ($variants as $variant) {
                $response = $this->http->post(
                    "{$this->getEndpoint()}/v1/purge",
                    array_merge($this->getHeaders(), ['Quant-Url' => $variant]),
                    []
                );

                if (($response['code'] ?? 0) !== 200) {
                    $errors[] = "Failed to purge: {$variant}";
                }
            }
        }

        if (!empty($errors)) {
            return ApiResponse::error(implode(', ', $errors));
        }

        return ApiResponse::success([], 'Cache purged successfully');
    }

    private function getHeaders(): array
    {
        return [
            'Quant-Token' => $this->getToken(),
            'Quant-Project' => $this->getProject(),
            'Quant-Organisation' => $this->getOrganization(),
        ];
    }

    private function normalizeUrl(string $url): string
    {
        $url = '/' . ltrim($url, '/');

        // Remove trailing slash except for root
        if ($url !== '/' && str_ends_with($url, '/')) {
            $url = rtrim($url, '/');
        }

        return $url;
    }

    private function parseResponse(array $response): ApiResponse
    {
        if (isset($response['error'])) {
            return ApiResponse::error($response['error']);
        }

        $code = $response['code'] ?? 0;
        $body = json_decode($response['body'] ?? '', true) ?: [];

        if ($code >= 200 && $code < 300) {
            return ApiResponse::success($body);
        }

        // Extract error message - handle both string errors and boolean error flags
        $message = $body['message'] ?? $body['errorMsg'] ?? null;
        if ($message === null) {
            $message = is_string($body['error'] ?? null) ? $body['error'] : "HTTP {$code}";
        }
        return ApiResponse::error($message, $code, $body);
    }
}
