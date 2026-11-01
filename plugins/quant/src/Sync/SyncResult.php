<?php

declare(strict_types=1);

namespace Quant\Sync;

final class SyncResult
{
    /**
     * @param array<string> $errors
     */
    public function __construct(
        public readonly int $processed,
        public readonly int $failed,
        public readonly array $errors = [],
    ) {
    }
}
