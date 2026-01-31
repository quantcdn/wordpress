<?php

declare(strict_types=1);

namespace Quant\Sync;

final readonly class SyncResult
{
    /**
     * @param array<string> $errors
     */
    public function __construct(
        public int $processed,
        public int $failed,
        public array $errors = [],
    ) {
    }
}
