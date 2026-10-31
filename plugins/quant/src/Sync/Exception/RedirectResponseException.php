<?php

declare(strict_types=1);

namespace Quant\Sync\Exception;

use RuntimeException;

/**
 * The local server answered a render request with a redirect.
 *
 * The processor records a Quant redirect instead of a page.
 */
final class RedirectResponseException extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly int $statusCode
    ) {
        parent::__construct("Redirect {$statusCode} from {$from} to {$to}");
    }
}
