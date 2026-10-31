<?php

declare(strict_types=1);

namespace Quant\Tests\Support;

use Quant\Api\HttpClientInterface;

/**
 * Records every call and answers from a queue of canned responses.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** @var array<int, array<string, mixed>> */
    private array $responses = [];

    /** @var array<string, mixed>|null */
    private ?array $default = null;

    /**
     * @param array<string, mixed> $response
     */
    public function respondWith(array $response): self
    {
        $this->responses[] = $response;
        return $this;
    }

    public function respondOk(array $body = []): self
    {
        return $this->respondWith(['code' => 200, 'body' => json_encode($body)]);
    }

    /**
     * @param array<string, mixed> $response
     */
    public function alwaysRespond(array $response): self
    {
        $this->default = $response;
        return $this;
    }

    public function get(string $url, array $headers = []): array
    {
        return $this->record('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array $body = []): array
    {
        return $this->record('POST', $url, $headers, $body);
    }

    public function patch(string $url, array $headers = [], array $body = []): array
    {
        return $this->record('PATCH', $url, $headers, $body);
    }

    public function delete(string $url, array $headers = []): array
    {
        return $this->record('DELETE', $url, $headers);
    }

    public function upload(string $url, array $headers, string $filePath, string $fieldName = 'data'): array
    {
        return $this->record('UPLOAD', $url, $headers, ['file' => $filePath, 'field' => $fieldName]);
    }

    /**
     * @return array<string, mixed>
     */
    public function last(): array
    {
        return $this->calls[count($this->calls) - 1];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function record(string $method, string $url, array $headers, array $body = []): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->responses !== []) {
            return array_shift($this->responses);
        }

        return $this->default ?? ['code' => 200, 'body' => '{}'];
    }
}
