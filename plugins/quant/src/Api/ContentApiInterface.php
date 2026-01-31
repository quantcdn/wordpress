<?php

declare(strict_types=1);

namespace Quant\Api;

interface ContentApiInterface
{
    public function ping(): ApiResponse;

    public function publish(string $url, string $markup, array $meta = []): ApiResponse;

    public function unpublish(string $url): ApiResponse;

    public function uploadFile(string $path, string $localPath): ApiResponse;

    public function createRedirect(string $from, string $to, int $code = 301): ApiResponse;

    public function purge(string|array $paths): ApiResponse;
}
