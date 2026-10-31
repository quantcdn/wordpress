<?php

declare(strict_types=1);

namespace Quant\Sync\Renderer;

use WP_Post;

interface ContentRendererInterface
{
    /**
     * Render a post, page or custom post type entry.
     *
     * @throws \RuntimeException when the post cannot be rendered
     * @throws \Quant\Sync\Exception\RedirectResponseException when the server redirects
     * @throws \Quant\Sync\Exception\UnpublishedContentException when the post is not public
     */
    public function render(int $postId): RenderedContent;

    public function supports(WP_Post $post): bool;
}
