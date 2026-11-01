<?php

declare(strict_types=1);

namespace Quant\Api;

class ApiResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message = '',
        public readonly array $data = [],
        public readonly int $statusCode = 200
    ) {}

    public static function success(array $data = [], string $message = ''): self
    {
        return new self(true, $message, $data);
    }

    public static function error(string $message, int $statusCode = 400, array $data = []): self
    {
        return new self(false, $message, $data, $statusCode);
    }
}
