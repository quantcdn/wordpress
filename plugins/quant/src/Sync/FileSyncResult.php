<?php

declare(strict_types=1);

namespace Quant\Sync;

/**
 * Value object representing the result of syncing a single file.
 */
final class FileSyncResult
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $path,
        public readonly string $message = '',
        public readonly array $meta = []
    ) {
    }

    /**
     * Create a success result.
     *
     * @param array<string, mixed> $meta
     */
    public static function success(string $path, array $meta = []): self
    {
        return new self(true, $path, '', $meta);
    }

    /**
     * Create a failure result.
     */
    public static function failure(string $path, string $message): self
    {
        return new self(false, $path, $message);
    }
}
