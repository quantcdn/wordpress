<?php

declare(strict_types=1);

namespace Quant\Admin;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Core\SiteTime;
use Quant\Redirects\RedirectProvider;
use Quant\Sync\DatabaseQueue;
use Quant\Sync\InternalFetcher;
use Quant\Sync\JobProcessor;
use Quant\Sync\Paths;
use Quant\Sync\Seeder;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use Quant\Sync\ThemeAssetSync;
use WP_Error;
use WP_Query;
use WP_REST_Request;

/**
 * REST endpoints behind the admin UI: status, settings, purge, content
 * browsing, seeding and queue control.
 */
class RestController
{
    private const NAMESPACE = 'quant/v1';

    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings,
        private readonly DatabaseQueue $queue,
        private readonly JobProcessor $processor,
        private readonly Seeder $seeder,
        private readonly ThemeAssetSync $themeAssets,
        private readonly RedirectProvider $redirects,
        private readonly InternalFetcher $fetcher
    ) {
    }

    public function register(): void
    {
        $get = fn(string $method): array => ['methods' => 'GET', 'callback' => [$this, $method], 'permission_callback' => [$this, 'checkPermission']];
        $post = fn(string $method): array => ['methods' => 'POST', 'callback' => [$this, $method], 'permission_callback' => [$this, 'checkPermission']];
        $paging = [
            'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 200],
        ];

        register_rest_route(self::NAMESPACE, '/status', $get('getStatus'));
        register_rest_route(self::NAMESPACE, '/settings', [$get('getSettings'), $post('saveSettings')]);
        register_rest_route(self::NAMESPACE, '/purge', $post('purge') + ['args' => [
            'paths' => ['required' => true, 'type' => 'array', 'items' => ['type' => 'string']],
        ]]);
        register_rest_route(self::NAMESPACE, '/content', $get('getContent') + ['args' => $paging + [
            'type' => ['type' => 'string', 'default' => 'post'],
            'subtype' => ['type' => 'string', 'default' => ''],
        ]]);
        register_rest_route(self::NAMESPACE, '/content-types', $get('getContentTypes'));
        register_rest_route(self::NAMESPACE, '/theme-assets', $get('getThemeAssets') + ['args' => $paging]);
        register_rest_route(self::NAMESPACE, '/redirects', $get('getRedirects') + ['args' => $paging]);
        register_rest_route(self::NAMESPACE, '/sync', $post('sync') + ['args' => [
            'items' => ['required' => true, 'type' => 'array'],
            'action' => ['type' => 'string', 'default' => 'sync', 'enum' => ['sync', 'unpublish']],
        ]]);
        register_rest_route(self::NAMESPACE, '/seed', $post('seed') + ['args' => [
            'types' => ['type' => 'array', 'default' => ['all'], 'items' => ['type' => 'string']],
        ]]);
        register_rest_route(self::NAMESPACE, '/queue/status', $get('getQueueStatus'));
        register_rest_route(self::NAMESPACE, '/queue/clear', $post('clearQueue'));
        register_rest_route(self::NAMESPACE, '/queue/retry', $post('retryQueue'));
        register_rest_route(self::NAMESPACE, '/queue/process', $post('processQueue') + ['args' => [
            'limit' => ['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50],
        ]]);
        register_rest_route(self::NAMESPACE, '/queue/errors', $get('getQueueErrors'));
        register_rest_route(self::NAMESPACE, '/test-internal', $post('testInternal'));
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    // --------------------------------------------------------------- status

    /**
     * @return array<string, mixed>
     */
    public function getStatus(WP_REST_Request $request): array
    {
        $connected = false;
        $error = null;
        $searchEnabled = false;

        if ($this->settings->hasValidCredentials()) {
            $response = $this->api->ping();
            $connected = $response->success;
            $error = $response->success ? null : $response->message;
            $searchEnabled = !empty($response->data['config']['search_enabled']);
        }

        $queue = $this->queue->getStatus();
        $nextCron = wp_next_scheduled('quant_process_queue') ?: null;

        return [
            'connected' => $connected,
            'error' => $error,
            'configured' => $this->settings->hasValidCredentials(),
            'mode' => $this->settings->getMode(),
            'version' => QUANT_VERSION,
            'search_enabled' => $searchEnabled,
            'queue' => [
                'pending' => $queue->pending,
                'processing' => $queue->processing,
                'failed' => $queue->failed,
                'completed' => $queue->total - $queue->pending - $queue->processing - $queue->failed,
                'total' => $queue->total,
            ],
            'next_cron' => $nextCron,
            'next_cron_label' => $nextCron === null ? null : SiteTime::time($nextCron),
            'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        ];
    }

    // ------------------------------------------------------------- settings

    /**
     * @return array<string, mixed>
     */
    public function getSettings(WP_REST_Request $request): array
    {
        $credentials = $this->settings->getCredentials();
        $sync = $this->settings->getSyncSettings();
        $static = $this->settings->getStaticConfig();
        $schedule = $this->settings->getSchedule();

        return [
            'organization' => $credentials['organization'],
            'project' => $credentials['project'],
            'token' => '',
            'has_token' => $credentials['token'] !== '',
            'endpoint' => $credentials['endpoint'],
            'disable_tls_verify' => $credentials['disable_tls_verify'],
            'http_request_timeout' => $credentials['http_request_timeout'],
            'mode' => $this->settings->getMode(),
            'auto_sync' => (bool) $sync['auto_sync'],
            'auto_purge' => (bool) $sync['auto_purge'],
            'sync_related' => (bool) ($sync['sync_related'] ?? true),
            'webserver_url' => $static['webserver_url'],
            'webserver_host' => $static['webserver_host'],
            'route_404' => $static['route_404'],
            'custom_routes' => $static['custom_routes'],
            'domain_rewrites' => $static['domain_rewrites'],
            'strip_host' => (bool) $static['strip_host'],
            'proxy_override' => (bool) $static['proxy_override'],
            'sitemap' => (bool) $static['sitemap'],
            'theme_assets' => (bool) $static['theme_assets'],
            'follow_links' => (bool) $static['follow_links'],
            'schedule' => $schedule,
            'schedules' => $this->availableSchedules(),
            'overridden' => $this->settings->getOverriddenKeys(),
        ];
    }

    /**
     * Partial update: only the keys present in the request change.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function saveSettings(WP_REST_Request $request): array|WP_Error
    {
        $input = (array) $request->get_json_params();
        if ($input === []) {
            $input = (array) $request->get_params();
        }

        $this->saveCredentials($input);
        $this->saveMode($input);
        $this->saveSyncSettings($input);
        $this->saveStaticConfig($input);
        $this->saveSchedule($input);

        return ['success' => true] + $this->getSettings($request);
    }

    // ---------------------------------------------------------------- purge

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function purge(WP_REST_Request $request): array|WP_Error
    {
        $paths = array_values(array_filter(array_map('trim', (array) $request->get_param('paths'))));
        if ($paths === []) {
            return new WP_Error('invalid_paths', __('No paths provided', 'quant'), ['status' => 400]);
        }

        if (!$this->settings->hasValidCredentials()) {
            return new WP_Error('not_configured', __('Credentials not configured', 'quant'), ['status' => 400]);
        }

        $response = $this->api->purge($paths);
        if (!$response->success) {
            return new WP_Error('purge_failed', $response->message, ['status' => 502]);
        }

        return ['success' => true, 'message' => $response->message, 'paths' => $paths];
    }

    // -------------------------------------------------------------- content

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function getContent(WP_REST_Request $request): array|WP_Error
    {
        $type = (string) $request->get_param('type');
        $subtype = (string) $request->get_param('subtype');
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        return match ($type) {
            'post', 'page' => $this->postContent($type, $page, $perPage),
            'cpt' => post_type_exists($subtype) ? $this->postContent($subtype, $page, $perPage) : new WP_Error('invalid_type', 'Unknown post type', ['status' => 400]),
            'taxonomy' => $this->taxonomyContent($subtype ?: 'category', $page, $perPage),
            'media' => $this->mediaContent($page, $perPage),
            default => new WP_Error('invalid_type', 'Invalid content type', ['status' => 400]),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getContentTypes(WP_REST_Request $request): array
    {
        $types = [
            ['type' => 'post', 'label' => __('Posts', 'quant'), 'count' => (int) wp_count_posts('post')->publish],
            ['type' => 'page', 'label' => __('Pages', 'quant'), 'count' => (int) wp_count_posts('page')->publish],
        ];

        foreach ($this->seeder->customPostTypes() as $name) {
            $object = get_post_type_object($name);
            $types[] = ['type' => 'cpt', 'subtype' => $name, 'label' => $object->labels->name ?? $name, 'count' => (int) (wp_count_posts($name)->publish ?? 0)];
        }

        foreach (get_taxonomies(['public' => true], 'objects') as $taxonomy) {
            $count = wp_count_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => true]);
            $types[] = ['type' => 'taxonomy', 'subtype' => $taxonomy->name, 'label' => $taxonomy->labels->name, 'count' => is_numeric($count) ? (int) $count : 0];
        }

        $types[] = ['type' => 'media', 'label' => __('Media', 'quant'), 'count' => (int) array_sum((array) wp_count_attachments())];

        foreach ($this->specialTypeCounts() as $type => [$label, $count]) {
            $types[] = ['type' => $type, 'label' => $label, 'count' => $count, 'category' => 'special'];
        }

        return $types;
    }

    /**
     * @return array<string, mixed>
     */
    public function getThemeAssets(WP_REST_Request $request): array
    {
        $assets = $this->themeAssets->scanActiveTheme();
        $items = array_map(static fn(array $asset): array => [
            'id' => md5($asset['url']),
            'title' => basename($asset['url']),
            'url' => $asset['url'],
            'type' => 'theme_asset',
            'file_type' => $asset['type'],
        ], $assets);

        return $this->paginate($this->withStatuses(SyncType::ThemeAsset, $items, 'url'), $request);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRedirects(WP_REST_Request $request): array
    {
        $items = array_map(static fn($redirect): array => [
            'id' => $redirect->from,
            'title' => $redirect->from,
            'url' => $redirect->from,
            'destination' => $redirect->to,
            'code' => $redirect->statusCode,
            'type' => 'redirect',
        ], $this->redirects->all());

        return $this->paginate($this->withStatuses(SyncType::Redirect, $items, 'url'), $request);
    }

    // ----------------------------------------------------------------- sync

    /**
     * Queue selected items.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function sync(WP_REST_Request $request): array|WP_Error
    {
        if (!$this->settings->isStaticMode()) {
            return new WP_Error('invalid_mode', __('Sync is only available in Static mode', 'quant'), ['status' => 400]);
        }

        $action = $request->get_param('action') === 'unpublish' ? SyncAction::Unpublish : SyncAction::Publish;
        $jobs = [];

        foreach ((array) $request->get_param('items') as $item) {
            $job = $this->jobFromItem((array) $item, $action);
            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        if ($jobs === []) {
            return new WP_Error('invalid_items', __('No items provided', 'quant'), ['status' => 400]);
        }

        $this->queue->pushBatch($jobs);

        return ['success' => true, 'queued' => count($jobs)];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function seed(WP_REST_Request $request): array|WP_Error
    {
        if (!$this->settings->isStaticMode()) {
            return new WP_Error('invalid_mode', __('Seed is only available in Static mode', 'quant'), ['status' => 400]);
        }

        $types = array_values(array_filter(array_map('sanitize_key', (array) $request->get_param('types'))));
        $result = $this->seeder->seed($types === [] ? ['all'] : $types);

        return ['success' => true] + $result;
    }

    // ---------------------------------------------------------------- queue

    /**
     * @return array<string, int>
     */
    public function getQueueStatus(WP_REST_Request $request): array
    {
        $status = $this->queue->getStatus();

        return [
            'pending' => $status->pending,
            'processing' => $status->processing,
            'failed' => $status->failed,
            'completed' => $status->total - $status->pending - $status->processing - $status->failed,
            'total' => $status->total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function clearQueue(WP_REST_Request $request): array
    {
        $this->queue->clear();

        return ['success' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function retryQueue(WP_REST_Request $request): array
    {
        return ['success' => true, 'queued' => $this->queue->retry()];
    }

    /**
     * @return array<string, mixed>
     */
    public function processQueue(WP_REST_Request $request): array|WP_Error
    {
        if (!$this->settings->hasValidCredentials()) {
            return new WP_Error('not_configured', __('Credentials are not configured. Open Settings and connect to Quant first.', 'quant'), ['status' => 400]);
        }

        $result = $this->processor->run((int) $request->get_param('limit'), 25);

        return [
            'success' => true,
            'processed' => $result->processed,
            'failed' => $result->failed,
            'remaining' => $this->queue->getStatus()->pending,
            'errors' => $result->errors,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getQueueErrors(WP_REST_Request $request): array
    {
        return $this->queue->recentErrors(25);
    }

    /**
     * Fetch /__quant-validate through the internal fetcher.
     *
     * @return array<string, mixed>
     */
    public function testInternal(WP_REST_Request $request): array
    {
        $original = home_url('/__quant-validate');

        try {
            $result = $this->fetcher->fetch('/__quant-validate', 10);
        } catch (\RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'original_url' => $original, 'adjusted_url' => $this->fetcher->toInternalUrl($original)];
        }

        $ok = $result->status === 200 && trim($result->body) === 'quant-ok';

        return [
            'success' => $ok,
            'message' => $ok ? __('Internal connectivity working', 'quant') : sprintf(
                /* translators: 1: HTTP status code, 2: start of the response body */
                __('HTTP %1$d response: %2$s', 'quant'), $result->status, substr(wp_strip_all_tags($result->body), 0, 100)),
            'original_url' => $original,
            'adjusted_url' => $result->requestedUrl,
            'host_header' => $this->fetcher->hostHeader(),
        ];
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $input
     */
    private function saveCredentials(array $input): void
    {
        $keys = ['organization', 'project', 'token', 'endpoint', 'disable_tls_verify', 'http_request_timeout'];
        $credentials = [];

        foreach ($keys as $key) {
            if (!array_key_exists($key, $input) || $input[$key] === null) {
                continue;
            }
            $credentials[$key] = match ($key) {
                'endpoint' => rtrim(esc_url_raw((string) $input[$key]), '/') ?: Settings::DEFAULT_ENDPOINT,
                'disable_tls_verify' => (bool) $input[$key],
                'http_request_timeout' => max(5, (int) $input[$key]),
                'token' => trim((string) $input[$key]),
                default => sanitize_text_field((string) $input[$key]),
            };
        }

        // An empty token means "keep the current one".
        if (isset($credentials['token']) && $credentials['token'] === '') {
            unset($credentials['token']);
        }

        if ($credentials !== []) {
            $this->settings->setCredentials($credentials);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function saveMode(array $input): void
    {
        if (isset($input['mode']) && in_array($input['mode'], [Settings::MODE_STATIC, Settings::MODE_CDN_ONLY], true)) {
            $this->settings->setMode((string) $input['mode']);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function saveSyncSettings(array $input): void
    {
        $sync = [];
        foreach (['auto_sync', 'auto_purge', 'sync_drafts', 'sync_related'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null) {
                $sync[$key] = (bool) $input[$key];
            }
        }

        if ($sync !== []) {
            $this->settings->setSyncSettings($sync);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function saveStaticConfig(array $input): void
    {
        // On multisite, only network administrators choose where the server
        // sends its own requests.
        if (is_multisite() && !current_user_can('manage_network_options')) {
            unset($input['webserver_url'], $input['webserver_host']);
        }

        $sanitizers = [
            'webserver_url' => static fn($v): string => rtrim(esc_url_raw((string) $v), '/'),
            'webserver_host' => static fn($v): string => sanitize_text_field((string) $v),
            'route_404' => static fn($v): string => sanitize_text_field((string) $v),
            'custom_routes' => [self::class, 'sanitizeLines'],
            'domain_rewrites' => [self::class, 'sanitizeLines'],
            'strip_host' => static fn($v): bool => (bool) $v,
            'proxy_override' => static fn($v): bool => (bool) $v,
            'sitemap' => static fn($v): bool => (bool) $v,
            'theme_assets' => static fn($v): bool => (bool) $v,
            'follow_links' => static fn($v): bool => (bool) $v,
        ];

        $config = $this->sanitizeProvided($input, $sanitizers);

        if ($config !== []) {
            $this->settings->setStaticConfig($config);
        }
    }

    /**
     * Apply a sanitizer to every key the request provided.
     *
     * @param array<string, mixed> $input
     * @param array<string, callable> $sanitizers
     * @return array<string, mixed>
     */
    private function sanitizeProvided(array $input, array $sanitizers): array
    {
        $result = [];
        foreach ($sanitizers as $key => $sanitizer) {
            if (array_key_exists($key, $input) && $input[$key] !== null) {
                $result[$key] = $sanitizer($input[$key]);
            }
        }

        return $result;
    }

    /**
     * A newline separated string or an array becomes a clean list of lines.
     *
     * @return array<string>
     */
    public static function sanitizeLines(mixed $value): array
    {
        $lines = is_string($value) ? explode("\n", $value) : (array) $value;

        return array_values(array_filter(array_map(static fn($l): string => sanitize_text_field(trim((string) $l)), $lines)));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function saveSchedule(array $input): void
    {
        if (!isset($input['schedule']) || !is_array($input['schedule'])) {
            return;
        }

        $schedule = $input['schedule'];
        $this->settings->setSchedule([
            'enabled' => !empty($schedule['enabled']),
            'interval' => array_key_exists((string) ($schedule['interval'] ?? ''), $this->availableSchedules()) ? (string) $schedule['interval'] : 'daily',
            'types' => array_values(array_filter(array_map('sanitize_key', (array) ($schedule['types'] ?? [])))),
            'custom_routes' => array_values(array_filter(array_map(static fn($l): string => sanitize_text_field(trim((string) $l)), (array) ($schedule['custom_routes'] ?? [])))),
        ]);

        do_action('quant_schedule_updated');
    }

    /**
     * @return array<string, string>
     */
    private function availableSchedules(): array
    {
        $schedules = [];
        foreach (wp_get_schedules() as $name => $schedule) {
            if ($name !== 'quant_every_minute') {
                $schedules[$name] = (string) $schedule['display'];
            }
        }

        return $schedules;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function jobFromItem(array $item, SyncAction $action): ?SyncJob
    {
        $type = (string) ($item['type'] ?? '');
        $id = (string) ($item['id'] ?? '');
        if ($type === '' || $id === '') {
            return null;
        }

        return match ($type) {
            'post' => new SyncJob(SyncType::Post, $id, $action),
            'page' => new SyncJob(SyncType::Page, $id, $action),
            'media' => new SyncJob(SyncType::Media, $id, $action),
            'taxonomy' => new SyncJob(SyncType::Taxonomy, $id, $action, ['taxonomy' => (string) ($item['subtype'] ?? ''), 'page' => 1]),
            'theme_asset' => $this->themeAssetJob((string) ($item['url'] ?? ''), $action),
            'redirect' => $this->redirectJob((string) ($item['url'] ?? $id), $action),
            default => post_type_exists($type) ? new SyncJob(SyncType::CustomPostType, $id, $action, ['post_type' => $type]) : null,
        };
    }

    private function themeAssetJob(string $url, SyncAction $action): ?SyncJob
    {
        foreach ($this->themeAssets->scanActiveTheme() as $asset) {
            if ($asset['url'] === $url) {
                return new SyncJob(SyncType::ThemeAsset, Paths::normalise($url), $action, ['file' => $asset['file'], 'url' => $url]);
            }
        }

        return null;
    }

    private function redirectJob(string $from, SyncAction $action): ?SyncJob
    {
        foreach ($this->redirects->all() as $redirect) {
            if ($redirect->from === $from) {
                return new SyncJob(SyncType::Redirect, $from, $action, ['to' => $redirect->to, 'code' => $redirect->statusCode]);
            }
        }

        return null;
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    private function specialTypeCounts(): array
    {
        $counts = [
            'home' => [__('Home Page', 'quant'), count($this->seeder->jobsFor('home'))],
            'archives' => [__('Archives', 'quant'), count($this->seeder->jobsFor('archives'))],
            'authors' => [__('Author Pages', 'quant'), count($this->seeder->jobsFor('authors'))],
            'theme_assets' => [__('Theme Assets', 'quant'), count($this->themeAssets->scanActiveTheme())],
            'custom_routes' => [__('Custom Routes & 404', 'quant'), count($this->seeder->jobsFor('custom_routes'))],
            'sitemaps' => [__('Sitemaps', 'quant'), count($this->seeder->jobsFor('sitemaps'))],
            'redirects' => [__('Redirects', 'quant'), count($this->redirects->all())],
        ];

        if (!empty($this->settings->getSearchSettings()['enabled'])) {
            $counts['search'] = [__('Search Records', 'quant'), count($this->seeder->jobsFor('search'))];
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function postContent(string $postType, int $page, int $perPage): array
    {
        $query = new WP_Query([
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        $items = array_map(static fn(\WP_Post $post): array => [
            'id' => $post->ID,
            'title' => $post->post_title,
            'url' => Paths::fromUrl((string) get_permalink($post)),
            'type' => in_array($postType, ['post', 'page'], true) ? $postType : $postType,
            'modified' => $post->post_modified,
        ], $query->posts);

        $type = match ($postType) {
            'post' => SyncType::Post,
            'page' => SyncType::Page,
            default => SyncType::CustomPostType,
        };

        return [
            'items' => $this->withStatuses($type, $items, 'id'),
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taxonomyContent(string $taxonomy, int $page, int $perPage): array
    {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => true,
            'number' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'orderby' => 'name',
        ]);
        $terms = is_array($terms) ? $terms : [];
        $total = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => true]);
        $total = is_numeric($total) ? (int) $total : 0;

        $items = array_map(static function (\WP_Term $term) use ($taxonomy): array {
            $link = get_term_link($term);
            return [
                'id' => $term->term_id,
                'title' => $term->name,
                'url' => is_string($link) ? Paths::fromUrl($link) : '',
                'type' => 'taxonomy',
                'subtype' => $taxonomy,
                'count' => $term->count,
            ];
        }, $terms);

        return [
            'items' => $this->withStatuses(SyncType::Taxonomy, $items, 'id'),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaContent(int $page, int $perPage): array
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        $items = array_map(static fn(\WP_Post $post): array => [
            'id' => $post->ID,
            'title' => $post->post_title ?: basename((string) get_attached_file($post->ID)),
            'url' => Paths::fromUrl((string) wp_get_attachment_url($post->ID)),
            'type' => 'media',
            'mime_type' => $post->post_mime_type,
        ], $query->posts);

        return [
            'items' => $this->withStatuses(SyncType::Media, $items, 'id'),
            'total' => (int) $query->found_posts,
            'pages' => (int) $query->max_num_pages,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Attach sync_status and last_synced to a list of items.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function withStatuses(SyncType $type, array $items, string $key): array
    {
        $ids = array_map(static fn(array $item): string => $key === 'url' ? Paths::normalise((string) $item[$key]) : (string) $item[$key], $items);
        $statuses = $this->queue->latestStatuses($type, $ids);

        foreach ($items as $i => $item) {
            $status = $statuses[$ids[$i]] ?? null;
            $items[$i]['sync_status'] = match ($status['status'] ?? null) {
                'completed' => 'synced',
                'pending', 'processing' => 'pending',
                'failed' => 'failed',
                default => 'never',
            };
            $items[$i]['last_synced'] = SiteTime::isoFromUtc($status['processed_at'] ?? null);
            $items[$i]['last_synced_label'] = SiteTime::fromUtc($status['processed_at'] ?? null);
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function paginate(array $items, WP_REST_Request $request): array
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = max(1, (int) $request->get_param('per_page'));
        $total = count($items);

        return [
            'items' => array_values(array_slice($items, ($page - 1) * $perPage, $perPage)),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'page' => $page,
            'per_page' => $perPage,
        ];
    }
}
