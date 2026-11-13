<?php

declare(strict_types=1);

namespace Quant\Admin;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Search\Frontend;
use Quant\Search\SearchPageRepository;
use Quant\Search\SearchPageSync;
use Quant\Search\SearchRecordBuilder;
use Quant\Sync\Seeder;
use WP_Error;
use WP_REST_Request;

/**
 * REST endpoints for Quant Search: status, record settings, pages, reindex.
 */
class SearchController
{
    private const NAMESPACE = 'quant/v1';

    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings,
        private readonly SearchPageRepository $pages,
        private readonly SearchPageSync $pageSync,
        private readonly SearchRecordBuilder $records,
        private readonly Seeder $seeder
    ) {
    }

    public function register(): void
    {
        $permission = ['permission_callback' => fn(): bool => current_user_can('manage_options')];

        register_rest_route(self::NAMESPACE, '/search/status', ['methods' => 'GET', 'callback' => [$this, 'status']] + $permission);
        register_rest_route(self::NAMESPACE, '/search/settings', [
            ['methods' => 'GET', 'callback' => [$this, 'getSettings']] + $permission,
            ['methods' => 'POST', 'callback' => [$this, 'saveSettings']] + $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/search/reindex', ['methods' => 'POST', 'callback' => [$this, 'reindex']] + $permission);
        register_rest_route(self::NAMESPACE, '/search/clear', ['methods' => 'POST', 'callback' => [$this, 'clear']] + $permission);
        register_rest_route(self::NAMESPACE, '/search/pages', [
            ['methods' => 'GET', 'callback' => [$this, 'listPages']] + $permission,
            ['methods' => 'POST', 'callback' => [$this, 'savePage']] + $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/search/pages/(?P<id>[a-z0-9_-]+)', [
            ['methods' => 'DELETE', 'callback' => [$this, 'deletePage']] + $permission,
        ]);
        register_rest_route(self::NAMESPACE, '/search/preview/(?P<post_id>\d+)', ['methods' => 'GET', 'callback' => [$this, 'preview']] + $permission);
    }

    /**
     * Project search status: enabled flag, index stats, index config.
     *
     * @return array<string, mixed>
     */
    public function status(WP_REST_Request $request): array
    {
        if (!$this->settings->hasValidCredentials()) {
            return ['configured' => false, 'enabled' => false, 'index' => null, 'stats' => null];
        }

        Frontend::clearIndexCache();
        $ping = $this->api->ping();
        $config = $ping->data['config'] ?? [];
        $enabled = !empty($config['search_enabled']);

        $stats = null;
        if ($enabled) {
            $statusResponse = $this->api->searchStatus();
            $stats = $statusResponse->success ? ($statusResponse->data['index'] ?? null) : null;
        }

        return [
            'configured' => true,
            'connected' => $ping->success,
            'enabled' => $enabled,
            'index' => $config['search_index'] ?? null,
            'backend' => ($config['platform_mode'] ?? '') === 'aws' ? 'typesense' : 'algolia',
            'stats' => $stats,
            'error' => $ping->success ? null : $ping->message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(WP_REST_Request $request): array
    {
        $types = [];
        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if ($type->name !== 'attachment') {
                $types[] = ['name' => $type->name, 'label' => $type->labels->name];
            }
        }

        $taxonomies = [];
        foreach (get_taxonomies(['public' => true], 'objects') as $taxonomy) {
            $taxonomies[] = ['name' => $taxonomy->name, 'label' => $taxonomy->labels->name];
        }

        return [
            'settings' => $this->settings->getSearchSettings(),
            'post_types' => $types,
            'taxonomies' => $taxonomies,
            'image_sizes' => array_values(get_intermediate_image_sizes()),
            'language' => $this->records->defaultLanguage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveSettings(WP_REST_Request $request): array
    {
        $input = (array) $request->get_json_params();
        $current = $this->settings->getSearchSettings();

        $settings = [
            'enabled' => array_key_exists('enabled', $input) ? (bool) $input['enabled'] : $current['enabled'],
            'post_types' => array_values(array_filter(array_map('sanitize_key', (array) ($input['post_types'] ?? $current['post_types'])))),
            'excluded_post_types' => array_values(array_filter(array_map('sanitize_key', (array) ($input['excluded_post_types'] ?? $current['excluded_post_types'])))),
            'summary_source' => in_array($input['summary_source'] ?? $current['summary_source'], ['excerpt', 'content'], true) ? ($input['summary_source'] ?? $current['summary_source']) : 'excerpt',
            'image_size' => sanitize_key((string) ($input['image_size'] ?? $current['image_size'])),
            'replace_wp_search' => array_key_exists('replace_wp_search', $input) ? (bool) $input['replace_wp_search'] : $current['replace_wp_search'],
            'default_page' => sanitize_key((string) ($input['default_page'] ?? $current['default_page'])),
        ];

        $this->settings->setSearchSettings($settings);
        $saved = $this->settings->getSearchSettings();

        return [
            'success' => true,
            'settings' => $saved,
            'push_needed' => $this->pushNeeded($this->formTarget($current), $this->formTarget($saved)),
        ];
    }

    /**
     * Where the search form on every page goes: the route of the resolved default search page, or null when the
     * form is not replaced. Pages already on the edge keep the old form until they are pushed again.
     *
     * @param array<string, mixed>|null $search The search settings; the saved ones when null.
     */
    private function formTarget(?array $search = null): ?string
    {
        $page = $this->pages->defaultPage($search ?? $this->settings->getSearchSettings());

        return $page === null ? null : (string) $page['route'];
    }

    /**
     * Whether a change of the form target needs a push (Static mode; CDN Only pages come from WordPress).
     */
    private function pushNeeded(?string $before, ?string $after): bool
    {
        return $before !== $after && $this->settings->isStaticMode();
    }

    /**
     * Queue a search record for every indexed post.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function reindex(WP_REST_Request $request): array|WP_Error
    {
        if (!$this->records->enabled()) {
            return new WP_Error('search_disabled', __('Enable Quant Search records first.', 'quant'), ['status' => 400]);
        }

        $result = $this->seeder->seed(['search']);

        return ['success' => true, 'queued' => $result['queued']];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function clear(WP_REST_Request $request): array|WP_Error
    {
        $response = $this->api->clearSearchIndex();
        if (!$response->success) {
            return new WP_Error('clear_failed', $response->message, ['status' => 502]);
        }

        return ['success' => true];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPages(WP_REST_Request $request): array
    {
        return array_map(function (array $page): array {
            $page['url'] = (int) $page['page_id'] > 0 ? get_permalink((int) $page['page_id']) : null;
            return $page;
        }, $this->pages->all());
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function savePage(WP_REST_Request $request): array|WP_Error
    {
        $input = (array) $request->get_json_params();
        if (trim((string) ($input['label'] ?? '')) === '' && trim((string) ($input['title'] ?? '')) === '') {
            return new WP_Error('invalid_page', __('A search page needs a label.', 'quant'), ['status' => 400]);
        }

        $before = $this->formTarget();
        $result = $this->pageSync->save($input);
        if ($result instanceof WP_Error) {
            $result->add_data(['status' => 400]);
            return $result;
        }

        $result['url'] = (int) $result['page_id'] > 0 ? get_permalink((int) $result['page_id']) : null;
        // A new route, status or first page can change where the search form goes.
        $result['push_needed'] = $this->pushNeeded($before, $this->formTarget());

        return $result;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function deletePage(WP_REST_Request $request): array|WP_Error
    {
        $id = sanitize_key((string) $request->get_param('id'));
        $before = $this->formTarget();
        if (!$this->pageSync->delete($id)) {
            return new WP_Error('not_found', __('Search page not found.', 'quant'), ['status' => 404]);
        }

        return ['success' => true, 'push_needed' => $this->pushNeeded($before, $this->formTarget())];
    }

    /**
     * The record that would be sent for a post.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function preview(WP_REST_Request $request): array|WP_Error
    {
        $post = get_post((int) $request->get_param('post_id'));
        if (!$post instanceof \WP_Post) {
            return new WP_Error('not_found', __('Post not found.', 'quant'), ['status' => 404]);
        }

        return $this->records->forPost($post);
    }
}
