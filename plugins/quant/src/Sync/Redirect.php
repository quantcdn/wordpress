<?php

declare(strict_types=1);

namespace Quant\Sync;

/**
 * Value object representing a redirect.
 */
final readonly class Redirect
{
    public function __construct(
        public string $from,
        public string $to,
        public int $statusCode = 301
    ) {
    }
}
