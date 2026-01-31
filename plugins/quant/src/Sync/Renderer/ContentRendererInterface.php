<?php

declare(strict_types=1);

namespace Quant\Sync\Renderer;

use WP_Post;

interface ContentRendererInterface
{
    public function render(int $postId): ?RenderedContent;

    public function supports(WP_Post $post): bool;
}
