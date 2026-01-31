<?php

declare(strict_types=1);

namespace Quant\Api;

interface HttpClientInterface
{
    public function get(string $url, array $headers = []): array;

    public function post(string $url, array $headers = [], array $body = []): array;

    public function patch(string $url, array $headers = [], array $body = []): array;
}
