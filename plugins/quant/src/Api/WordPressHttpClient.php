<?php

declare(strict_types=1);

namespace Quant\Api;

use Closure;
use WP_Error;

/**
 * HttpClientInterface implementation on top of the WordPress HTTP API.
 */
class WordPressHttpClient implements HttpClientInterface
{
    /**
     * @param Closure(): array{timeout: int, sslverify: bool} $options Resolved per request so settings changes apply immediately.
     */
    public function __construct(
        private readonly Closure $options
    ) {
    }

    public static function withDefaults(int $timeout = 30, bool $sslVerify = true): self
    {
        return new self(static fn(): array => ['timeout' => $timeout, 'sslverify' => $sslVerify]);
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array $body = []): array
    {
        return $this->request('POST', $url, $headers, $body);
    }

    public function patch(string $url, array $headers = [], array $body = []): array
    {
        return $this->request('PATCH', $url, $headers, $body);
    }

    public function delete(string $url, array $headers = []): array
    {
        return $this->request('DELETE', $url, $headers);
    }

    public function upload(string $url, array $headers, string $filePath, string $fieldName = 'data'): array
    {
        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            return ['code' => 0, 'body' => '', 'error' => "Could not read file: {$filePath}"];
        }

        $boundary = 'quant' . wp_generate_password(24, false);
        $mime = $this->mimeType($filePath);
        $filename = basename($filePath);

        $payload = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"{$fieldName}\"; filename=\"{$filename}\"\r\n"
            . "Content-Type: {$mime}\r\n\r\n"
            . $contents . "\r\n"
            . "--{$boundary}--\r\n";

        $args = $this->baseArgs('POST', array_merge($headers, [
            'Content-Type' => "multipart/form-data; boundary={$boundary}",
        ]));
        $args['body'] = $payload;
        $args['timeout'] = max($args['timeout'], 120);

        return $this->send($url, $args);
    }

    /**
     * @param array<string, string> $headers
     * @param array<mixed> $body
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string}
     */
    private function request(string $method, string $url, array $headers, array $body = []): array
    {
        $args = $this->baseArgs($method, array_merge(['Content-Type' => 'application/json'], $headers));

        if ($body !== []) {
            $args['body'] = wp_json_encode($body);
        }

        return $this->send($url, $args);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function baseArgs(string $method, array $headers): array
    {
        $options = ($this->options)();

        return [
            'method' => $method,
            'timeout' => (int) ($options['timeout'] ?? 30),
            'sslverify' => (bool) ($options['sslverify'] ?? true),
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string}
     */
    private function send(string $url, array $args): array
    {
        $response = wp_remote_request($url, $args);

        if ($response instanceof WP_Error) {
            return ['code' => 0, 'body' => '', 'error' => $response->get_error_message()];
        }

        $headers = wp_remote_retrieve_headers($response);

        return [
            'code' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
            'headers' => is_object($headers) && method_exists($headers, 'getAll') ? $headers->getAll() : (array) $headers,
        ];
    }

    private function mimeType(string $filePath): string
    {
        $type = wp_check_filetype($filePath)['type'] ?? '';
        if (is_string($type) && $type !== '') {
            return $type;
        }

        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($filePath);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return 'application/octet-stream';
    }
}
