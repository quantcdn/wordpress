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
        public readonly array $meta = [],
        public readonly string $detail = '',
        public readonly bool $transient = false
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
     *
     * @param string $detail Raw transport or server text for diagnostics.
     * @param bool $transient The same upload can work later (see ApiResponse::$transient).
     */
    public static function failure(string $path, string $message, string $detail = '', bool $transient = false): self
    {
        return new self(false, $path, $message, [], $detail, $transient);
    }
}
