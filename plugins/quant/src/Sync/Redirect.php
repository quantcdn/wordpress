<?php

declare(strict_types=1);

namespace Quant\Sync;

/**
 * Value object representing a redirect.
 */
final class Redirect
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly int $statusCode = 301
    ) {
    }
}
