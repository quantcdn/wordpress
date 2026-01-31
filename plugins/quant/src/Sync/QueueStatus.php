<?php

declare(strict_types=1);

namespace Quant\Sync;

final readonly class QueueStatus
{
    public function __construct(
        public int $pending,
        public int $processing,
        public int $failed,
        public int $total,
    ) {
    }
}
