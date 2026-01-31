<?php

declare(strict_types=1);

namespace Quant\Sync\Renderer;

use WP_Post;

/**
 * Renders posts, pages, and custom post types by fetching their HTML via HTTP.
 */
final class PostRenderer implements ContentRendererInterface
{
    /**
     * Supported post statuses for rendering.
     *
     * @var array<string>
     */
    private const SUPPORTED_STATUSES = ['publish'];

    /**
     * Built-in post types that are always supported.
     *
     * @var array<string>
     */
    private const BUILTIN_POST_TYPES = ['post', 'page'];

    /**
     * {@inheritdoc}
     */
    public function supports(WP_Post $post): bool
    {
        if (!in_array($post->post_status, self::SUPPORTED_STATUSES, true)) {
            return false;
        }

        if (in_array($post->post_type, self::BUILTIN_POST_TYPES, true)) {
            return true;
        }

        return $this->isPublicCustomPostType($post->post_type);
    }

    /**
     * {@inheritdoc}
     */
    public function render(int $postId): ?RenderedContent
    {
        $post = get_post($postId);

        if (!$post instanceof WP_Post) {
            return null;
        }

        if (!$this->supports($post)) {
            return null;
        }

        $permalink = get_permalink($post);

        if ($permalink === false) {
            return null;
        }

        $markup = $this->fetchRenderedHtml($permalink);

        if ($markup === null) {
            return null;
        }

        $url = $this->extractPath($permalink);

        return new RenderedContent(
            url: $url,
            markup: $markup,
            meta: $this->buildMeta($post),
        );
    }

    /**
     * Check if a post type is a public custom post type.
     */
    private function isPublicCustomPostType(string $postType): bool
    {
        $postTypeObject = get_post_type_object($postType);

        if ($postTypeObject === null) {
            return false;
        }

        return $postTypeObject->public === true;
    }

    /**
     * Fetch the rendered HTML from the given URL.
     */
    private function fetchRenderedHtml(string $url): ?string
    {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            // SSL verification enabled by default. Use 'quant_renderer_ssl_verify' filter
            // to disable in development environments with self-signed certificates.
            'sslverify' => apply_filters('quant_renderer_ssl_verify', true),
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $statusCode = wp_remote_retrieve_response_code($response);

        if ($statusCode !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);

        if (empty($body)) {
            return null;
        }

        return $body;
    }

    /**
     * Extract the path from a full URL.
     */
    private function extractPath(string $url): string
    {
        $parsed = wp_parse_url($url);

        $path = $parsed['path'] ?? '/';

        if ($path === '') {
            $path = '/';
        }

        return $path;
    }

    /**
     * Build metadata array for the rendered content.
     *
     * @return array{post_type: string, title: string, modified: string, published: string}
     */
    private function buildMeta(WP_Post $post): array
    {
        return [
            'post_type' => $post->post_type,
            'title' => $post->post_title,
            'modified' => $post->post_modified_gmt,
            'published' => $post->post_date_gmt,
        ];
    }
}
