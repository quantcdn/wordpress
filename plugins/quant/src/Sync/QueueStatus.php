<?php

declare(strict_types=1);

namespace Quant\Sync;

final class QueueStatus
{
    public function __construct(
        public readonly int $pending,
        public readonly int $processing,
        public readonly int $failed,
        public readonly int $total,
    ) {
    }
}
