<?php

declare(strict_types=1);

namespace Quant\Sync\Renderer;

use Quant\Sync\Exception\UnpublishedContentException;
use Quant\Sync\Paths;
use RuntimeException;
use WP_Post;

/**
 * Renders posts, pages and custom post type entries.
 */
final class PostRenderer implements ContentRendererInterface
{
    private const BUILTIN_POST_TYPES = ['post', 'page'];

    public function __construct(
        private readonly RouteRenderer $routes
    ) {
    }

    public function supports(WP_Post $post): bool
    {
        if ($post->post_status !== 'publish') {
            return false;
        }

        if (in_array($post->post_type, self::BUILTIN_POST_TYPES, true)) {
            return true;
        }

        $postTypeObject = get_post_type_object($post->post_type);

        return $postTypeObject !== null && $postTypeObject->public === true && $postTypeObject->publicly_queryable !== false;
    }

    public function render(int $postId): RenderedContent
    {
        $post = get_post($postId);

        if (!$post instanceof WP_Post) {
            throw new RuntimeException(esc_html("Post #{$postId} not found"));
        }

        $permalink = get_permalink($post);
        if (!is_string($permalink) || $permalink === '') {
            throw new RuntimeException(esc_html("Cannot get permalink for post #{$postId}"));
        }

        if (!$this->supports($post)) {
            $reason = "Post #{$postId} ({$post->post_type}) is not public (status: {$post->post_status})";
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries paths to the job handler; the message is escaped in the factory.
            throw UnpublishedContentException::forPath(Paths::fromUrl($permalink), $reason);
        }

        if (!empty($post->post_password)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries paths to the job handler; the message is escaped in the factory.
            throw UnpublishedContentException::forPath(Paths::fromUrl($permalink), "Post #{$postId} is password protected");
        }

        $path = Paths::fromUrl($permalink);
        $publishAs = $this->isFrontPage($post) ? '/' : null;

        $rendered = $this->routes->render($path, $publishAs);

        return new RenderedContent(
            url: $rendered->url,
            markup: $rendered->markup,
            info: $this->buildInfo($post),
            options: array_merge($rendered->options, [
                'content_timestamp' => $this->timestamp($post),
            ]),
        );
    }

    /**
     * @return array<string, string>
     */
    private function buildInfo(WP_Post $post): array
    {
        $info = ['source' => 'wordpress'];

        $author = get_the_author_meta('display_name', (int) $post->post_author);
        if (is_string($author) && $author !== '') {
            $info['author_name'] = $author;
        }

        $info['log'] = sprintf('%s #%d modified %s', $post->post_type, $post->ID, $post->post_modified_gmt);

        return $info;
    }

    private function timestamp(WP_Post $post): int
    {
        $timestamp = strtotime($post->post_modified_gmt . ' UTC');

        return $timestamp === false ? time() : $timestamp;
    }

    private function isFrontPage(WP_Post $post): bool
    {
        return $post->post_type === 'page'
            && get_option('show_on_front') === 'page'
            && (int) get_option('page_on_front') === $post->ID;
    }
}
