<?php

declare(strict_types=1);

namespace Quant\Sync\Renderer;

/**
 * Value object representing rendered content from a WordPress post.
 *
 * @phpstan-type ContentMeta array{
 *     post_type: string,
 *     title: string,
 *     modified: string,
 *     published: string
 * }
 */
final class RenderedContent
{
    /**
     * @param string $url The URL path (e.g., /blog/my-post)
     * @param string $markup The full rendered HTML
     * @param array<string, mixed> $meta Metadata about the content
     */
    public function __construct(
        public readonly string $url,
        public readonly string $markup,
        public readonly array $meta = [],
    ) {
    }
}
