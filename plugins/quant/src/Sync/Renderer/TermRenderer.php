<?php

declare(strict_types=1);

namespace Quant\Sync\Renderer;

use Quant\Sync\Exception\UnpublishedContentException;
use Quant\Sync\Paths;
use RuntimeException;
use WP_Term;

/**
 * Renders taxonomy term archive pages, including paginated pages.
 */
final class TermRenderer
{
    private const INTERNAL_TAXONOMIES = [
        'nav_menu',
        'link_category',
        'post_format',
        'wp_theme',
        'wp_template_part_area',
        'wp_pattern_category',
    ];

    public function __construct(
        private readonly RouteRenderer $routes
    ) {
    }

    public function supports(WP_Term $term): bool
    {
        if (in_array($term->taxonomy, self::INTERNAL_TAXONOMIES, true)) {
            return false;
        }

        $taxonomy = get_taxonomy($term->taxonomy);

        return $taxonomy instanceof \WP_Taxonomy && $taxonomy->public === true && $taxonomy->publicly_queryable !== false;
    }

    /**
     * @param int $page Listing page to render (1 for the term page itself)
     */
    public function render(int $termId, string $taxonomy, int $page = 1): RenderedContent
    {
        $term = get_term($termId, $taxonomy);

        if (!$term instanceof WP_Term) {
            throw new RuntimeException(esc_html("Term #{$termId} not found in {$taxonomy}"));
        }

        $link = get_term_link($term);
        if (!is_string($link)) {
            throw new RuntimeException(esc_html("Cannot get link for term #{$termId}"));
        }

        if (!$this->supports($term)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries paths to the job handler; the message is escaped in the factory.
            throw UnpublishedContentException::forPath(Paths::fromUrl($link), "Taxonomy {$taxonomy} is not public");
        }

        $url = Paths::paginated($link, $page);
        $rendered = $this->routes->render(Paths::fromUrl($url));

        return new RenderedContent(
            url: $rendered->url,
            markup: $rendered->markup,
            info: [
                'source' => 'wordpress',
                'log' => sprintf('%s #%d (%s)', $taxonomy, $term->term_id, $term->name),
            ],
            options: $rendered->options,
        );
    }

    /**
     * Paths of every page of a term listing, used for unpublishing.
     *
     * @return array<string>
     */
    public function pagePaths(WP_Term $term): array
    {
        $link = get_term_link($term);
        if (!is_string($link)) {
            return [];
        }

        $paths = [];
        for ($page = 1, $pages = Paths::pageCount((int) $term->count); $page <= $pages; $page++) {
            $paths[] = Paths::fromUrl(Paths::paginated($link, $page));
        }

        return $paths;
    }
}
