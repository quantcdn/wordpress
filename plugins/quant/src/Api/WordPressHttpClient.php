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
    /** Uploads and large publish bodies get at least this timeout (seconds). */
    public const UPLOAD_MIN_TIMEOUT = 120;

    /**
     * A JSON body this size or larger gets UPLOAD_MIN_TIMEOUT. A typical page
     * is 50-300 KB of HTML. At a slow 256 kbit/s uplink (32 KB/s), 512 KB
     * takes about 16 s to send, which leaves about half of the default 30 s
     * timeout for Quant to process the page (find_attachments parses all of
     * it). A larger body could time out on the upload alone, so it gets the
     * same allowance as a file upload.
     */
    public const LARGE_BODY_BYTES = 512 * 1024;

    /**
     * @param Closure(): array{timeout: int, sslverify: bool} $options Resolved per request so settings changes apply immediately.
     */
    public function __construct(
        private readonly Closure $options
    ) {
    }

    /**
     * The Quant API client: the configured timeout, and TLS always verified.
     * "Disable SSL verification" applies only to the loopback fetch of local
     * pages (Quant\Sync\InternalFetcher), never to calls to Quant.
     */
    public static function forQuantApi(\Quant\Core\Settings $settings): self
    {
        return new self(static fn(): array => [
            'timeout' => $settings->getCredentials()['http_request_timeout'],
            'sslverify' => true,
        ]);
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
        if (!is_file($filePath) || !is_readable($filePath)) {
            return ['code' => 0, 'body' => '', 'error' => "Could not read file: {$filePath}"];
        }

        $refusal = $this->sizeRefusal((int) filesize($filePath));
        if ($refusal !== null) {
            return ['code' => 0, 'body' => '', 'error' => "{$refusal}: {$filePath}"];
        }

        return $this->uploadBuffered($url, $headers, $filePath, $fieldName);
    }

    /**
     * The WordPress HTTP API sends the request body from memory, so the file
     * is held about three times over (contents, multipart body, transport).
     * Refuse a file that cannot fit rather than run out of memory mid-batch.
     */
    private function sizeRefusal(int $bytes): ?string
    {
        /** This filter is documented in v2.0.0; it sets the largest file the plugin uploads (bytes). */
        $limit = (int) apply_filters('quant_max_upload_bytes', 256 * MB_IN_BYTES);
        if ($bytes > $limit) {
            return sprintf('File is larger than the %d MB upload limit (quant_max_upload_bytes)', (int) ($limit / MB_IN_BYTES));
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('quant_upload');
        }

        $memoryLimit = function_exists('wp_convert_hr_to_bytes') ? wp_convert_hr_to_bytes((string) ini_get('memory_limit')) : -1;
        if ($memoryLimit <= 0) {
            return null;
        }

        $needed = ($bytes * 3) + (8 * MB_IN_BYTES);
        if (memory_get_usage(true) + $needed > $memoryLimit) {
            return sprintf('Not enough PHP memory to upload a %d MB file (memory_limit %s)', (int) ceil($bytes / MB_IN_BYTES), (string) ini_get('memory_limit'));
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string, detail?: string, transport?: bool}
     */
    private function uploadBuffered(string $url, array $headers, string $filePath, string $fieldName): array
    {
        return $this->uploadContents($url, $headers, (string) file_get_contents($filePath), basename($filePath), $fieldName);
    }

    public function uploadContents(string $url, array $headers, string $contents, string $filename, string $fieldName = 'data'): array
    {
        $boundary = 'quant' . wp_generate_password(24, false);
        $mime = $this->mimeType($filename);

        $payload = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"{$fieldName}\"; filename=\"{$filename}\"\r\n"
            . "Content-Type: {$mime}\r\n\r\n"
            . $contents . "\r\n"
            . "--{$boundary}--\r\n";

        $args = $this->baseArgs('POST', array_merge($headers, [
            'Content-Type' => "multipart/form-data; boundary={$boundary}",
        ]));
        $args['body'] = $payload;
        $args['timeout'] = max($args['timeout'], self::UPLOAD_MIN_TIMEOUT);

        return $this->send($url, $args);
    }

    /**
     * @param array<string, string> $headers
     * @param array<mixed> $body
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string, detail?: string, transport?: bool}
     */
    private function request(string $method, string $url, array $headers, array $body = []): array
    {
        $args = $this->baseArgs($method, array_merge(['Content-Type' => 'application/json'], $headers));

        if ($body !== []) {
            $args['body'] = (string) wp_json_encode($body);
            if (strlen($args['body']) >= self::LARGE_BODY_BYTES) {
                $args['timeout'] = max($args['timeout'], self::UPLOAD_MIN_TIMEOUT);
            }
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
     * WordPress does not pass a connect timeout to its transport, so the
     * Requests library default applies (10 s, for cURL and fsockopen alike).
     * A host that does not accept the connection fails after 10 s, not after
     * the full request timeout.
     *
     * @param array<string, mixed> $args
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string, detail?: string, transport?: bool}
     */
    private function send(string $url, array $args): array
    {
        $response = wp_remote_request($url, $args);

        if ($response instanceof WP_Error) {
            $error = TransportError::fromWpError($response);

            return ['code' => 0, 'body' => '', 'error' => $error['message'], 'detail' => $error['detail'], 'transport' => $error['transport']];
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
