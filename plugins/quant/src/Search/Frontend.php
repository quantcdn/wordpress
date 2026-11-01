<?php

declare(strict_types=1);

namespace Quant\Search;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;

/**
 * Renders Quant Search pages and autocomplete boxes on the public site.
 *
 * Shortcodes: `[quant_search page="id"]` and
 * `[quant_search_autocomplete page="id" placeholder="Search" show_summary="1"]`.
 * Blocks `quant/search` and `quant/search-autocomplete` render the same output.
 * The InstantSearch and Autocomplete libraries are bundled with the plugin's
 * scripts, so the static copy works without WordPress or a third-party CDN.
 */
class Frontend
{

    private const INDEX_TRANSIENT = 'quant_search_index_config';

    public function __construct(
        private readonly Settings $settings,
        private readonly SearchPageRepository $pages,
        private readonly SearchRecordBuilder $records,
        private readonly ContentApiInterface $api
    ) {
    }

    public function register(): void
    {
        add_shortcode('quant_search', [$this, 'renderSearchShortcode']);
        add_shortcode('quant_search_autocomplete', [$this, 'renderAutocompleteShortcode']);
        add_action('init', [$this, 'registerBlocks']);
        add_filter('get_search_form', [$this, 'replaceSearchForm'], 20, 1);
        add_action('template_redirect', [$this, 'redirectNativeSearch'], 1);
    }

    public function registerBlocks(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        // @phpstan-ignore-next-line argument.type (api_version is an int in WP_Block_Type)
        register_block_type('quant/search', [
            'api_version' => 3,
            'title' => __('Quant Search', 'quant'),
            'category' => 'widgets',
            'icon' => 'search',
            'attributes' => ['page' => ['type' => 'string', 'default' => '']],
            'render_callback' => fn(array $attributes): string => $this->renderSearchShortcode($attributes),
            'editor_script' => 'quant-search-blocks',
        ]);

        // @phpstan-ignore-next-line argument.type (api_version is an int in WP_Block_Type)
        register_block_type('quant/search-autocomplete', [
            'api_version' => 3,
            'title' => __('Quant Search Autocomplete', 'quant'),
            'category' => 'widgets',
            'icon' => 'search',
            'attributes' => [
                'page' => ['type' => 'string', 'default' => ''],
                'placeholder' => ['type' => 'string', 'default' => 'Search'],
                'show_summary' => ['type' => 'boolean', 'default' => false],
            ],
            'render_callback' => fn(array $attributes): string => $this->renderAutocompleteShortcode($attributes),
            'editor_script' => 'quant-search-blocks',
        ]);

        $this->registerBlockEditorScript();
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public function renderSearchShortcode(array|string $atts): string
    {
        $atts = shortcode_atts(['page' => ''], is_array($atts) ? $atts : [], 'quant_search');
        $page = $this->resolvePage((string) $atts['page']);
        $index = $this->indexConfig();

        if ($page === null || $index === null) {
            return $this->unavailable($page === null ? __('Search page not found.', 'quant') : __('Quant Search is not enabled for this project.', 'quant'));
        }

        $this->enqueueSearchAssets();

        $config = [
            'index' => $index,
            'filters' => $this->pages->filterString($page, $index['backend']),
            'facets' => $this->facetConfig($page, $index),
            'display' => $page['display'],
            'queryParam' => 'keys',
        ];

        return sprintf(
            '<div class="quant-search" data-quant-search="%s">%s%s</div>',
            esc_attr((string) wp_json_encode($config)),
            $page['description'] !== '' ? '<h4 class="quant-search__description">' . esc_html($page['description']) . '</h4>' : '',
            $this->searchLayout($page, $index)
        );
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public function renderAutocompleteShortcode(array|string $atts): string
    {
        $atts = shortcode_atts(['page' => '', 'placeholder' => __('Search', 'quant'), 'show_summary' => false], is_array($atts) ? $atts : [], 'quant_search_autocomplete');
        $page = $this->resolvePage((string) $atts['page']);
        $index = $this->indexConfig();

        if ($page === null || $index === null) {
            return '';
        }

        $this->enqueueAutocompleteAssets();

        $config = [
            'index' => $index,
            'filters' => $this->pages->filterString($page, $index['backend']),
            'searchPath' => home_url($page['route']),
            'placeholder' => (string) $atts['placeholder'],
            'showSummary' => filter_var($atts['show_summary'], FILTER_VALIDATE_BOOLEAN),
            'queryParam' => 'keys',
        ];

        // The fallback form works without JavaScript; the script replaces it.
        return sprintf(
            '<div class="quant-search-autocomplete" data-quant-autocomplete="%s">%s</div>',
            esc_attr((string) wp_json_encode($config)),
            $this->fallbackForm(home_url($page['route']), (string) $atts['placeholder'])
        );
    }

    /**
     * Point the theme's search form at the default search page.
     */
    public function replaceSearchForm(string $form): string
    {
        $page = $this->defaultPage();
        if ($page === null) {
            return $form;
        }

        $action = esc_url(home_url($page['route']));

        return sprintf(
            '<form role="search" method="get" class="search-form quant-search-form" action="%s">'
            . '<label><span class="screen-reader-text">%s</span>'
            . '<input type="search" class="search-field" placeholder="%s" value="%s" name="keys" /></label>'
            . '<button type="submit" class="search-submit">%s</button></form>',
            $action,
            esc_html__('Search for:', 'quant'),
            esc_attr__('Search …', 'quant'),
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public search term echoed back into the search box.
            esc_attr(isset($_GET['keys']) && is_string($_GET['keys']) ? sanitize_text_field(wp_unslash($_GET['keys'])) : ''),
            esc_html__('Search', 'quant')
        );
    }

    /**
     * Send native `?s=` searches to the default search page.
     */
    public function redirectNativeSearch(): void
    {
        if (!is_search() || is_admin() || defined('QUANT_INTERNAL_REQUEST')) {
            return;
        }

        $page = $this->defaultPage();
        if ($page === null) {
            return;
        }

        wp_safe_redirect(add_query_arg('keys', rawurlencode(get_search_query(false)), home_url($page['route'])), 302);
        exit;
    }

    /**
     * The search backend for the browser, cached for an hour.
     *
     * An unavailable backend is cached for five minutes, and an incomplete
     * one (a partial API failure) is not cached. The Typesense endpoint is a
     * local setting and is added at read time. See SearchBackend.
     *
     * @return array<string, mixed>|null
     */
    public function indexConfig(): ?array
    {
        $cached = get_transient(self::INDEX_TRANSIENT);
        if (is_array($cached)) {
            return SearchBackend::withEndpoint($cached['index'] ?? null);
        }

        if (!$this->settings->hasValidCredentials()) {
            return null;
        }

        $backend = SearchBackend::resolve($this->api);
        if ($backend !== null && !SearchBackend::isComplete($backend)) {
            return null;
        }

        set_transient(self::INDEX_TRANSIENT, ['index' => $backend], $backend === null ? 300 : 3600);

        return SearchBackend::withEndpoint($backend);
    }

    public static function clearIndexCache(): void
    {
        delete_transient(self::INDEX_TRANSIENT);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePage(string $id): ?array
    {
        if ($id !== '') {
            return $this->pages->find($id);
        }

        $post = get_post();
        if ($post instanceof \WP_Post) {
            $page = $this->pages->findByPostId($post->ID);
            if ($page !== null) {
                return $page;
            }
        }

        $all = $this->pages->all();

        return $all[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function defaultPage(): ?array
    {
        $search = $this->settings->getSearchSettings();
        if (empty($search['enabled']) || empty($search['replace_wp_search'])) {
            return null;
        }

        $page = $search['default_page'] !== '' ? $this->pages->find((string) $search['default_page']) : ($this->pages->all()[0] ?? null);

        return $page !== null && !empty($page['status']) ? $page : null;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed>|null $backend
     * @return array<int, array<string, mixed>>
     */
    private function facetConfig(array $page, ?array $backend): array
    {
        $lang = $this->records->defaultLanguage();
        $facets = [];

        foreach ($page['facets'] as $i => $facet) {
            $attribute = $this->pages->facetAttribute($facet, $lang);
            if ($attribute === '') {
                continue;
            }
            $facets[] = [
                'container' => 'quant-facet-' . $i,
                'attribute' => $attribute,
                'display' => $facet['facet_display'],
                'heading' => $facet['facet_heading'] ?: $attribute,
                'limit' => (int) $facet['facet_limit'],
            ];
        }

        return SearchBackend::declaredFacets($facets, $backend);
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed>|null $backend
     */
    private function searchLayout(array $page, ?array $backend): string
    {
        $display = $page['display'];
        $facets = $this->facetConfig($page, $backend);

        $facetMarkup = '';
        if ($facets !== []) {
            $facetMarkup .= '<div class="quant-search__facets">';
            if (!empty($display['show_clear_refinements'])) {
                $facetMarkup .= '<div class="quant-search__clear" data-quant-clear></div>';
            }
            foreach ($facets as $facet) {
                $facetMarkup .= sprintf(
                    '<div class="quant-search__facet"><div class="quant-search__facet-heading">%s</div><div id="%s"></div></div>',
                    esc_html($facet['heading']),
                    esc_attr($facet['container'])
                );
            }
            $facetMarkup .= '</div>';
        }

        return $facetMarkup
            . '<div class="quant-search__content">'
            . (!empty($display['display_search'])
                ? '<div class="quant-search__box" data-quant-searchbox>' . $this->fallbackForm(home_url($page['route']), __('Search…', 'quant')) . '</div>'
                : '')
            . (!empty($display['display_stats']) ? '<div class="quant-search__stats" data-quant-stats></div>' : '')
            . '<div class="quant-search__hits" data-quant-hits></div>'
            . (!empty($display['pagination_enabled']) ? '<div class="quant-search__pagination" data-quant-pagination></div>' : '')
            . '</div>';
    }

    /**
     * A plain search form for visitors without JavaScript or when the
     * search libraries fail to load. Submits `keys` to the search page.
     */
    private function fallbackForm(string $action, string $placeholder): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public search term echoed back into the search box.
        $keys = isset($_GET['keys']) && is_string($_GET['keys']) ? sanitize_text_field(wp_unslash($_GET['keys'])) : '';

        return sprintf(
            '<form role="search" method="get" class="quant-search__fallback" action="%s">'
            . '<label><span class="screen-reader-text">%s</span>'
            . '<input type="search" name="keys" value="%s" placeholder="%s" /></label>'
            . '<button type="submit">%s</button></form>',
            esc_url($action),
            esc_html__('Search for:', 'quant'),
            esc_attr($keys),
            esc_attr($placeholder),
            esc_html__('Search', 'quant')
        );
    }

    private function unavailable(string $message): string
    {
        if (!current_user_can('manage_options')) {
            return '';
        }

        return '<div class="quant-search quant-search--unavailable"><p>' . esc_html($message) . '</p></div>';
    }

    private function enqueueSearchAssets(): void
    {
        $this->enqueueBundle('quant-search', 'search');
    }

    private function enqueueAutocompleteAssets(): void
    {
        $this->enqueueBundle('quant-search-autocomplete', 'autocomplete');
    }

    /**
     * Enqueue a bundle built by wp-scripts. The InstantSearch and Autocomplete
     * libraries are bundled with it, so nothing loads from a third-party CDN.
     */
    private function enqueueBundle(string $handle, string $name): void
    {
        $assetFile = QUANT_PLUGIN_DIR . "assets/build/{$name}.asset.php";
        $asset = file_exists($assetFile) ? require $assetFile : ['dependencies' => [], 'version' => QUANT_VERSION];

        wp_enqueue_style($handle, QUANT_PLUGIN_URL . "assets/build/{$name}.css", [], $asset['version']);
        wp_enqueue_script($handle, QUANT_PLUGIN_URL . "assets/build/{$name}.js", $asset['dependencies'], $asset['version'], true);
    }

    private function registerBlockEditorScript(): void
    {
        $asset = QUANT_PLUGIN_DIR . 'assets/build/blocks.asset.php';
        $deps = ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render'];
        $version = QUANT_VERSION;
        if (file_exists($asset)) {
            $meta = require $asset;
            $deps = $meta['dependencies'] ?? $deps;
            $version = $meta['version'] ?? $version;
        }

        wp_register_script('quant-search-blocks', QUANT_PLUGIN_URL . 'assets/build/blocks.js', $deps, $version, true);
        wp_localize_script('quant-search-blocks', 'quantSearchBlocks', [
            'pages' => array_map(static fn(array $p): array => ['id' => $p['id'], 'label' => $p['label'] ?: $p['title']], $this->pages->all()),
        ]);
    }
}
