<?php

declare(strict_types=1);

namespace Quant\Api;

/**
 * Minimal HTTP client contract.
 *
 * Every method returns ['code' => int, 'body' => string, 'headers' => array]
 * or, when the transport failed, ['code' => 0, 'body' => '', 'error' => string].
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string}
     */
    public function get(string $url, array $headers = []): array;

    /**
     * JSON POST.
     *
     * @param array<string, string> $headers
     * @param array<mixed> $body
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string}
     */
    public function post(string $url, array $headers = [], array $body = []): array;

    /**
     * @param array<string, string> $headers
     * @param array<mixed> $body
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string}
     */
    public function patch(string $url, array $headers = [], array $body = []): array;

    /**
     * @param array<string, string> $headers
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string}
     */
    public function delete(string $url, array $headers = []): array;

    /**
     * Multipart POST of one file in the `data` field.
     *
     * @param array<string, string> $headers
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string}
     */
    public function upload(string $url, array $headers, string $filePath, string $fieldName = 'data'): array;

    /**
     * Multipart POST of in-memory contents in the `data` field.
     *
     * @param array<string, string> $headers
     * @return array{code: int, body: string, headers?: array<string, mixed>, error?: string}
     */
    public function uploadContents(string $url, array $headers, string $contents, string $filename, string $fieldName = 'data'): array;
}
