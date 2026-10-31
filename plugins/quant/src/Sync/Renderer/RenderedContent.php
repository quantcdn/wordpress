<?php

declare(strict_types=1);

namespace Quant\Sync\Renderer;

/**
 * A rendered page ready for the Content API.
 */
final class RenderedContent
{
    /**
     * @param string $url      The path Quant serves the page from (for example /blog/my-post)
     * @param string $markup   The processed HTML
     * @param array<string, mixed> $info     `info` metadata: author_name, log, source
     * @param array<string, mixed> $options  Publish options: content_timestamp, published, headers, proxy_override, search_record
     */
    public function __construct(
        public readonly string $url,
        public readonly string $markup,
        public readonly array $info = [],
        public readonly array $options = [],
    ) {
    }

    public function withOption(string $key, mixed $value): self
    {
        return new self($this->url, $this->markup, $this->info, array_merge($this->options, [$key => $value]));
    }
}
