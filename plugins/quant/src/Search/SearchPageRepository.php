<?php

declare(strict_types=1);

namespace Quant\Search;

/**
 * Stores Quant Search page definitions in one option.
 *
 * A search page is a WordPress page that renders the `[quant_search]`
 * shortcode. The definition holds the filters, facets and display options
 * the InstantSearch front end needs. Shape mirrors the Drupal module's
 * `quant_search_page` config entity.
 */
class SearchPageRepository
{
    public const OPTION = 'quant_search_pages';

    public const DEFAULTS = [
        'id' => '',
        'label' => '',
        'status' => true,
        'route' => '/search',
        'page_id' => 0,
        'title' => 'Search',
        'description' => '',
        'post_types' => [],
        'languages' => [],
        'manual_filters' => '',
        'display' => [
            'display_search' => true,
            'display_stats' => true,
            'show_clear_refinements' => true,
            'pagination_enabled' => true,
            'per_page' => 10,
        ],
        'facets' => [],
    ];

    public const FACET_DEFAULTS = [
        'facet_type' => 'taxonomy',
        'taxonomy' => 'category',
        'custom_key' => '',
        'facet_display' => 'checkbox',
        'facet_heading' => '',
        'facet_limit' => 10,
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $pages = get_option(self::OPTION, []);
        if (!is_array($pages)) {
            return [];
        }

        return array_values(array_map([$this, 'normalise'], array_filter($pages, 'is_array')));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $page) {
            if ($page['id'] === $id) {
                return $page;
            }
        }

        return null;
    }

    /**
     * The page attached to a WordPress page ID, if any.
     *
     * @return array<string, mixed>|null
     */
    public function findByPostId(int $postId): ?array
    {
        foreach ($this->all() as $page) {
            if ((int) $page['page_id'] === $postId) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Create or update a page definition. Returns the stored definition.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $page = $this->normalise($input);
        if ($page['id'] === '') {
            $page['id'] = $this->generateId($page['label'] ?: $page['title']);
        }

        $pages = array_filter($this->all(), static fn(array $p): bool => $p['id'] !== $page['id']);
        $pages[] = $page;

        update_option(self::OPTION, array_values($pages), false);

        return $page;
    }

    public function delete(string $id): bool
    {
        $pages = $this->all();
        $remaining = array_values(array_filter($pages, static fn(array $p): bool => $p['id'] !== $id));

        if (count($remaining) === count($pages)) {
            return false;
        }

        update_option(self::OPTION, $remaining, false);

        return true;
    }

    /**
     * Facet attribute keys this page uses, plus the defaults every page filters on.
     *
     * @param array<string, mixed> $page
     * @return array<string>
     */
    public function facetKeys(array $page, string $lang): array
    {
        $keys = ['lang_code', 'content_type'];

        foreach ($page['facets'] as $facet) {
            $keys[] = $this->facetAttribute($facet, $lang);
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * The record attribute a facet refines on.
     *
     * @param array<string, mixed> $facet
     */
    public function facetAttribute(array $facet, string $lang): string
    {
        return match ($facet['facet_type']) {
            'taxonomy' => $facet['taxonomy'] . '_' . $lang,
            'content_type' => 'content_type_' . $lang,
            'language' => 'language_' . $lang,
            'custom' => (string) $facet['custom_key'],
            default => '',
        };
    }

    /**
     * Filter string for a page in the backend's syntax: languages, post types
     * and manual filters.
     *
     * @param array<string, mixed> $page
     */
    public function filterString(array $page, string $backend = SearchBackend::ALGOLIA): string
    {
        $typesense = $backend === SearchBackend::TYPESENSE;
        $filters = [];

        foreach (['lang_code' => 'languages', 'content_type' => 'post_types'] as $field => $key) {
            $parts = array_filter(array_map(static fn(string $value): string => self::equals($field, $value, $typesense), $page[$key]));
            if ($parts !== []) {
                $filters[] = '(' . implode($typesense ? ' || ' : ' OR ', $parts) . ')';
            }
        }

        // Passed through verbatim, so it must use the backend's own syntax.
        if (trim((string) $page['manual_filters']) !== '') {
            $filters[] = trim((string) $page['manual_filters']);
        }

        return implode($typesense ? ' && ' : ' AND ', $filters);
    }

    /**
     * One equality clause. Typesense quotes values with backticks, so a value
     * that contains one is dropped instead of changing the filter's meaning.
     */
    private static function equals(string $field, string $value, bool $typesense): string
    {
        if (!$typesense) {
            return $field . ":'" . str_replace("'", '', $value) . "'";
        }

        return str_contains($value, '`') ? '' : $field . ':=`' . $value . '`';
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalise(array $input): array
    {
        $page = array_merge(self::DEFAULTS, array_intersect_key($input, self::DEFAULTS));

        $page['id'] = sanitize_key((string) $page['id']);
        $page['label'] = sanitize_text_field((string) $page['label']);
        $page['status'] = (bool) $page['status'];
        $page['route'] = '/' . trim(sanitize_text_field((string) $page['route']), '/ ');
        $page['page_id'] = (int) $page['page_id'];
        $page['title'] = sanitize_text_field((string) $page['title']);
        $page['description'] = sanitize_textarea_field((string) $page['description']);
        $page['post_types'] = array_values(array_filter(array_map('sanitize_key', (array) $page['post_types'])));
        $page['languages'] = array_values(array_filter(array_map('sanitize_key', (array) $page['languages'])));
        $page['manual_filters'] = sanitize_text_field((string) $page['manual_filters']);
        $page['display'] = $this->normaliseDisplay((array) $page['display']);
        $page['facets'] = array_values(array_map([$this, 'normaliseFacet'], array_filter((array) $page['facets'], 'is_array')));

        return $page;
    }

    /**
     * @param array<string, mixed> $display
     * @return array<string, mixed>
     */
    private function normaliseDisplay(array $display): array
    {
        $display = array_merge(self::DEFAULTS['display'], array_intersect_key($display, self::DEFAULTS['display']));
        foreach (['display_search', 'display_stats', 'show_clear_refinements', 'pagination_enabled'] as $flag) {
            $display[$flag] = (bool) $display[$flag];
        }
        $display['per_page'] = max(1, min(100, (int) $display['per_page']));

        return $display;
    }

    /**
     * @param array<string, mixed> $facet
     * @return array<string, mixed>
     */
    private function normaliseFacet(array $facet): array
    {
        $facet = array_merge(self::FACET_DEFAULTS, array_intersect_key($facet, self::FACET_DEFAULTS));
        $facet['facet_type'] = in_array($facet['facet_type'], ['taxonomy', 'content_type', 'language', 'custom'], true) ? $facet['facet_type'] : 'taxonomy';
        $facet['taxonomy'] = sanitize_key((string) $facet['taxonomy']);
        $facet['custom_key'] = sanitize_key((string) $facet['custom_key']);
        $facet['facet_display'] = in_array($facet['facet_display'], ['checkbox', 'menu', 'select'], true) ? $facet['facet_display'] : 'checkbox';
        $facet['facet_heading'] = sanitize_text_field((string) $facet['facet_heading']);
        $facet['facet_limit'] = max(1, min(100, (int) $facet['facet_limit']));

        return $facet;
    }

    private function generateId(string $label): string
    {
        $base = sanitize_key(sanitize_title($label)) ?: 'search';
        $id = $base;
        $n = 2;
        while ($this->find($id) !== null) {
            $id = $base . '-' . $n++;
        }

        return $id;
    }
}
