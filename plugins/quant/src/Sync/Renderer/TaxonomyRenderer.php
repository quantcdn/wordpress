<?php

declare(strict_types=1);

namespace Quant\Sync\Renderer;

use WP_Term;

/**
 * Renders taxonomy term archive pages by fetching their HTML via HTTP.
 */
final class TaxonomyRenderer
{
    /**
     * Internal taxonomies that should not be synced.
     *
     * @var array<string>
     */
    private const INTERNAL_TAXONOMIES = [
        'nav_menu',
        'link_category',
        'post_format',
        'wp_theme',
        'wp_template_part_area',
        'wp_pattern_category',
    ];

    /**
     * Check if the taxonomy term is supported for rendering.
     */
    public function supports(WP_Term $term): bool
    {
        if (in_array($term->taxonomy, self::INTERNAL_TAXONOMIES, true)) {
            return false;
        }

        return $this->isPublicTaxonomy($term->taxonomy);
    }

    /**
     * Render a taxonomy term archive page.
     */
    public function render(int $termId): ?RenderedContent
    {
        $term = get_term($termId);

        if (!$term instanceof WP_Term) {
            return null;
        }

        if (is_wp_error($term)) {
            return null;
        }

        if (!$this->supports($term)) {
            return null;
        }

        $termLink = get_term_link($term);

        if (is_wp_error($termLink)) {
            return null;
        }

        $markup = $this->fetchRenderedHtml($termLink);

        if ($markup === null) {
            return null;
        }

        $url = $this->extractPath($termLink);

        return new RenderedContent(
            url: $url,
            markup: $markup,
            meta: $this->buildMeta($term),
        );
    }

    /**
     * Check if a taxonomy is public.
     */
    private function isPublicTaxonomy(string $taxonomy): bool
    {
        $taxonomyObject = get_taxonomy($taxonomy);

        if ($taxonomyObject === null || $taxonomyObject === false) {
            return false;
        }

        return $taxonomyObject->public === true;
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
     * Build metadata array for the rendered term.
     *
     * @return array{taxonomy: string, term_id: int, term_name: string, term_slug: string}
     */
    private function buildMeta(WP_Term $term): array
    {
        return [
            'taxonomy' => $term->taxonomy,
            'term_id' => $term->term_id,
            'term_name' => $term->name,
            'term_slug' => $term->slug,
        ];
    }
}
