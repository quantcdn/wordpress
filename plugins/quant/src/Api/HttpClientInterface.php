<?php

declare(strict_types=1);

namespace Quant\Api;

/**
 * Minimal HTTP client contract.
 *
 * Every method returns ['code' => int, 'body' => string, 'headers' => array]
 * or, when the request failed, ['code' => 0, 'body' => '', 'error' => string].
 * 'error' is a short message for the admin. When the transport failed (no
 * answer, DNS, TLS), 'transport' is true and 'detail' holds the raw text.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string, detail?: string, transport?: bool}
     */
    public function get(string $url, array $headers = []): array;

    /**
     * JSON POST.
     *
     * @param array<string, string> $headers
     * @param array<mixed> $body
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string, detail?: string, transport?: bool}
     */
    public function post(string $url, array $headers = [], array $body = []): array;

    /**
     * @param array<string, string> $headers
     * @param array<mixed> $body
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string, detail?: string, transport?: bool}
     */
    public function patch(string $url, array $headers = [], array $body = []): array;

    /**
     * @param array<string, string> $headers
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string, detail?: string, transport?: bool}
     */
    public function delete(string $url, array $headers = []): array;

    /**
     * Multipart POST of one file in the `data` field.
     *
     * @param array<string, string> $headers
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string, detail?: string, transport?: bool}
     */
    public function upload(string $url, array $headers, string $filePath, string $fieldName = 'data'): array;

    /**
     * Multipart POST of in-memory contents in the `data` field.
     *
     * @param array<string, string> $headers
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string, detail?: string, transport?: bool}
     */
    public function uploadContents(string $url, array $headers, string $contents, string $filename, string $fieldName = 'data'): array;
}
