<?php

declare(strict_types=1);

namespace Quant\Sync\Exception;

use RuntimeException;

/**
 * The content is not publicly available and must be unpublished in Quant.
 */
final class UnpublishedContentException extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        string $reason
    ) {
        parent::__construct($reason);
    }

    /**
     * The path is data for the handler, not output; only the reason is a message.
     */
    public static function forPath(string $path, string $reason): self
    {
        return new self($path, esc_html($reason));
    }
}
