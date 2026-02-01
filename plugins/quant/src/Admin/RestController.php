<?php

declare(strict_types=1);

namespace Quant\Admin;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Sync\DatabaseQueue;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use Quant\Sync\SyncAction;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_Query;
use WP_Term_Query;

class RestController
{
    private const NAMESPACE = 'quant/v1';

    private ?DatabaseQueue $queue = null;

    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings
    ) {}

    private function getQueue(): DatabaseQueue
    {
        if ($this->queue === null) {
            global $wpdb;
            $this->queue = new DatabaseQueue($wpdb);
        }
        return $this->queue;
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'getStatus'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/settings', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getSettings'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'saveSettings'],
                'permission_callback' => [$this, 'checkPermission'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/purge', [
            'methods' => 'POST',
            'callback' => [$this, 'purge'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'paths' => [
                    'required' => true,
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ]);

        // Content listing endpoint
        register_rest_route(self::NAMESPACE, '/content', [
            'methods' => 'GET',
            'callback' => [$this, 'getContent'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'type' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'post',
                    'enum' => ['post', 'page', 'cpt', 'taxonomy', 'media'],
                ],
                'subtype' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                ],
                'page' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                ],
                'per_page' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
        ]);

        // Sync endpoint
        register_rest_route(self::NAMESPACE, '/sync', [
            'methods' => 'POST',
            'callback' => [$this, 'sync'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'items' => [
                    'required' => true,
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'id' => ['type' => ['string', 'integer']],
                        ],
                    ],
                ],
                'action' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'sync',
                    'enum' => ['sync', 'unpublish'],
                ],
            ],
        ]);

        // Seed endpoint (Static mode only)
        register_rest_route(self::NAMESPACE, '/seed', [
            'methods' => 'POST',
            'callback' => [$this, 'seed'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'types' => [
                    'required' => false,
                    'type' => 'array',
                    'default' => ['post', 'page', 'media', 'taxonomy'],
                    'items' => ['type' => 'string'],
                ],
            ],
        ]);

        // Queue status endpoint
        register_rest_route(self::NAMESPACE, '/queue/status', [
            'methods' => 'GET',
            'callback' => [$this, 'getQueueStatus'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // Queue clear endpoint
        register_rest_route(self::NAMESPACE, '/queue/clear', [
            'methods' => 'POST',
            'callback' => [$this, 'clearQueue'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // Queue retry endpoint
        register_rest_route(self::NAMESPACE, '/queue/retry', [
            'methods' => 'POST',
            'callback' => [$this, 'retryQueue'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        // Content types endpoint
        register_rest_route(self::NAMESPACE, '/content-types', [
            'methods' => 'GET',
            'callback' => [$this, 'getContentTypes'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    public function getStatus(WP_REST_Request $request): array
    {
        $connected = false;

        if ($this->settings->hasValidCredentials()) {
            $response = $this->api->ping();
            $connected = $response->success;
        }

        return [
            'connected' => $connected,
            'mode' => $this->settings->getMode(),
            'version' => QUANT_VERSION,
        ];
    }

    public function getSettings(WP_REST_Request $request): array
    {
        $credentials = $this->settings->getCredentials();

        return [
            'organization' => $credentials['organization'] ?? '',
            'project' => $credentials['project'] ?? '',
            'token' => '', // Never expose token
            'endpoint' => $credentials['endpoint'] ?? 'https://api.quantcdn.io',
            'mode' => $this->settings->getMode(),
        ];
    }

    public function saveSettings(WP_REST_Request $request): array|WP_Error
    {
        $organization = sanitize_text_field($request->get_param('organization') ?? '');
        $project = sanitize_text_field($request->get_param('project') ?? '');
        $token = $request->get_param('token');
        $endpoint = esc_url_raw($request->get_param('endpoint') ?? 'https://api.quantcdn.io');
        $mode = sanitize_text_field($request->get_param('mode') ?? 'static');

        // Get existing credentials
        $existing = $this->settings->getCredentials();

        // Only update token if provided (non-empty)
        $credentials = [
            'organization' => $organization,
            'project' => $project,
            'token' => !empty($token) ? $token : ($existing['token'] ?? ''),
            'endpoint' => $endpoint,
        ];

        $this->settings->setCredentials($credentials);

        if (in_array($mode, ['static', 'cdn_only'], true)) {
            $this->settings->setMode($mode);
        }

        return ['success' => true];
    }

    public function purge(WP_REST_Request $request): array|WP_Error
    {
        $paths = $request->get_param('paths');

        if (empty($paths)) {
            return new WP_Error('invalid_paths', 'No paths provided', ['status' => 400]);
        }

        $response = $this->api->purge($paths);

        if (!$response->success) {
            return new WP_Error('purge_failed', $response->message, ['status' => 500]);
        }

        return [
            'success' => true,
            'message' => $response->message,
        ];
    }

    /**
     * Get content list with sync status.
     */
    public function getContent(WP_REST_Request $request): array|WP_Error
    {
        $type = $request->get_param('type');
        $subtype = $request->get_param('subtype');
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        switch ($type) {
            case 'post':
            case 'page':
                return $this->getPostContent($type, $page, $perPage);

            case 'cpt':
                return $this->getPostContent($subtype ?: 'post', $page, $perPage);

            case 'taxonomy':
                return $this->getTaxonomyContent($subtype ?: 'category', $page, $perPage);

            case 'media':
                return $this->getMediaContent($page, $perPage);

            default:
                return new WP_Error('invalid_type', 'Invalid content type', ['status' => 400]);
        }
    }

    /**
     * Get posts/pages content.
     */
    private function getPostContent(string $postType, int $page, int $perPage): array
    {
        $query = new WP_Query([
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        $items = [];
        $syncStatuses = $this->getSyncStatuses($postType, wp_list_pluck($query->posts, 'ID'));

        foreach ($query->posts as $post) {
            $items[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'url' => str_replace(home_url(), '', get_permalink($post)),
                'type' => $postType,
                'sync_status' => $syncStatuses[$post->ID] ?? 'never',
                'last_synced' => $this->getLastSyncedDate($postType, $post->ID),
                'modified' => $post->post_modified,
            ];
        }

        return [
            'items' => $items,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Get taxonomy terms content.
     */
    private function getTaxonomyContent(string $taxonomy, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => $perPage,
            'offset' => $offset,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        $totalTerms = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

        if (is_wp_error($terms)) {
            $terms = [];
        }

        $items = [];
        $termIds = wp_list_pluck($terms, 'term_id');
        $syncStatuses = $this->getSyncStatuses('taxonomy', $termIds);

        foreach ($terms as $term) {
            $termLink = get_term_link($term);
            $items[] = [
                'id' => $term->term_id,
                'title' => $term->name,
                'url' => is_string($termLink) ? str_replace(home_url(), '', $termLink) : '',
                'type' => 'taxonomy',
                'subtype' => $taxonomy,
                'sync_status' => $syncStatuses[$term->term_id] ?? 'never',
                'last_synced' => $this->getLastSyncedDate('taxonomy', $term->term_id),
                'count' => $term->count,
            ];
        }

        return [
            'items' => $items,
            'total' => is_numeric($totalTerms) ? (int) $totalTerms : 0,
            'pages' => is_numeric($totalTerms) ? (int) ceil($totalTerms / $perPage) : 0,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Get media content.
     */
    private function getMediaContent(int $page, int $perPage): array
    {
        $query = new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);

        $items = [];
        $syncStatuses = $this->getSyncStatuses('media', wp_list_pluck($query->posts, 'ID'));

        foreach ($query->posts as $post) {
            $items[] = [
                'id' => $post->ID,
                'title' => $post->post_title ?: basename(get_attached_file($post->ID) ?: ''),
                'url' => str_replace(home_url(), '', wp_get_attachment_url($post->ID) ?: ''),
                'type' => 'media',
                'mime_type' => $post->post_mime_type,
                'sync_status' => $syncStatuses[$post->ID] ?? 'never',
                'last_synced' => $this->getLastSyncedDate('media', $post->ID),
            ];
        }

        return [
            'items' => $items,
            'total' => $query->found_posts,
            'pages' => $query->max_num_pages,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Get sync statuses for items from the queue.
     *
     * @param string $type
     * @param array<int|string> $ids
     * @return array<int|string, string>
     */
    private function getSyncStatuses(string $type, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        global $wpdb;
        $tableName = $wpdb->prefix . 'quant_sync_queue';

        // Map type to SyncType value
        $syncType = match ($type) {
            'post' => 'post',
            'page' => 'page',
            'taxonomy' => 'taxonomy',
            'media' => 'media',
            default => $type,
        };

        $placeholders = implode(',', array_fill(0, count($ids), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT object_id, status, MAX(processed_at) as last_processed
             FROM {$tableName}
             WHERE type = %s AND object_id IN ($placeholders)
             GROUP BY object_id, status
             ORDER BY last_processed DESC",
            array_merge([$syncType], array_map('strval', $ids))
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results($sql, ARRAY_A);

        $statuses = [];
        foreach ($results as $row) {
            $objectId = is_numeric($row['object_id']) ? (int) $row['object_id'] : $row['object_id'];
            // Only set if not already set (we want the most recent status)
            if (!isset($statuses[$objectId])) {
                $statuses[$objectId] = match ($row['status']) {
                    'completed' => 'synced',
                    'pending', 'processing' => 'pending',
                    'failed' => 'failed',
                    default => 'never',
                };
            }
        }

        return $statuses;
    }

    /**
     * Get last synced date for an item.
     */
    private function getLastSyncedDate(string $type, int|string $id): ?string
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'quant_sync_queue';

        $syncType = match ($type) {
            'post' => 'post',
            'page' => 'page',
            'taxonomy' => 'taxonomy',
            'media' => 'media',
            default => $type,
        };

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT processed_at FROM {$tableName}
             WHERE type = %s AND object_id = %s AND status = 'completed'
             ORDER BY processed_at DESC LIMIT 1",
            $syncType,
            (string) $id
        ));

        return $result ?: null;
    }

    /**
     * Queue items for sync.
     */
    public function sync(WP_REST_Request $request): array|WP_Error
    {
        if (!$this->settings->isStaticMode()) {
            return new WP_Error('invalid_mode', 'Sync is only available in Static mode', ['status' => 400]);
        }

        $items = $request->get_param('items');
        $action = $request->get_param('action');

        if (empty($items)) {
            return new WP_Error('invalid_items', 'No items provided', ['status' => 400]);
        }

        $syncAction = $action === 'unpublish' ? SyncAction::Unpublish : SyncAction::Publish;
        $jobs = [];

        foreach ($items as $item) {
            $type = $this->mapContentTypeToSyncType($item['type'] ?? 'post');
            if ($type === null) {
                continue;
            }

            $jobs[] = new SyncJob(
                type: $type,
                objectId: (string) ($item['id'] ?? ''),
                action: $syncAction
            );
        }

        if (!empty($jobs)) {
            $this->getQueue()->pushBatch($jobs);
        }

        return [
            'success' => true,
            'queued' => count($jobs),
        ];
    }

    /**
     * Seed all content (Static mode only).
     */
    public function seed(WP_REST_Request $request): array|WP_Error
    {
        if (!$this->settings->isStaticMode()) {
            return new WP_Error('invalid_mode', 'Seed is only available in Static mode', ['status' => 400]);
        }

        $types = $request->get_param('types');
        $totalQueued = 0;

        foreach ($types as $type) {
            switch ($type) {
                case 'post':
                    $totalQueued += $this->seedPostType('post');
                    break;
                case 'page':
                    $totalQueued += $this->seedPostType('page');
                    break;
                case 'media':
                    $totalQueued += $this->seedMedia();
                    break;
                case 'taxonomy':
                    $totalQueued += $this->seedTaxonomies();
                    break;
            }
        }

        return [
            'success' => true,
            'queued' => $totalQueued,
        ];
    }

    /**
     * Seed a post type.
     */
    private function seedPostType(string $postType): int
    {
        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        if (empty($posts)) {
            return 0;
        }

        $syncType = $postType === 'page' ? SyncType::Page : SyncType::Post;
        $jobs = [];

        foreach ($posts as $postId) {
            $jobs[] = new SyncJob(
                type: $syncType,
                objectId: (string) $postId,
                action: SyncAction::Publish
            );
        }

        $this->getQueue()->pushBatch($jobs);

        return count($jobs);
    }

    /**
     * Seed media items.
     */
    private function seedMedia(): int
    {
        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        if (empty($attachments)) {
            return 0;
        }

        $jobs = [];
        foreach ($attachments as $attachmentId) {
            $jobs[] = new SyncJob(
                type: SyncType::Media,
                objectId: (string) $attachmentId,
                action: SyncAction::Publish
            );
        }

        $this->getQueue()->pushBatch($jobs);

        return count($jobs);
    }

    /**
     * Seed taxonomies.
     */
    private function seedTaxonomies(): int
    {
        $taxonomies = get_taxonomies(['public' => true], 'names');
        $jobs = [];

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'fields' => 'ids',
            ]);

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            foreach ($terms as $termId) {
                $jobs[] = new SyncJob(
                    type: SyncType::Taxonomy,
                    objectId: (string) $termId,
                    action: SyncAction::Publish,
                    meta: ['taxonomy' => $taxonomy]
                );
            }
        }

        if (!empty($jobs)) {
            $this->getQueue()->pushBatch($jobs);
        }

        return count($jobs);
    }

    /**
     * Get queue status.
     */
    public function getQueueStatus(WP_REST_Request $request): array
    {
        $status = $this->getQueue()->getStatus();

        return [
            'pending' => $status->pending,
            'processing' => $status->processing,
            'failed' => $status->failed,
            'completed' => $status->total - $status->pending - $status->processing - $status->failed,
            'total' => $status->total,
        ];
    }

    /**
     * Clear the queue.
     */
    public function clearQueue(WP_REST_Request $request): array
    {
        $this->getQueue()->clear();

        return ['success' => true];
    }

    /**
     * Retry failed items.
     */
    public function retryQueue(WP_REST_Request $request): array
    {
        $count = $this->getQueue()->retry();

        return [
            'success' => true,
            'queued' => $count,
        ];
    }

    /**
     * Get available content types.
     */
    public function getContentTypes(WP_REST_Request $request): array
    {
        $types = [];

        // Posts and pages
        $types[] = [
            'type' => 'post',
            'label' => __('Posts', 'quant'),
            'count' => (int) wp_count_posts('post')->publish,
        ];

        $types[] = [
            'type' => 'page',
            'label' => __('Pages', 'quant'),
            'count' => (int) wp_count_posts('page')->publish,
        ];

        // Custom post types
        $customPostTypes = get_post_types([
            'public' => true,
            '_builtin' => false,
        ], 'objects');

        foreach ($customPostTypes as $cpt) {
            $counts = wp_count_posts($cpt->name);
            $types[] = [
                'type' => 'cpt',
                'subtype' => $cpt->name,
                'label' => $cpt->labels->name,
                'count' => (int) ($counts->publish ?? 0),
            ];
        }

        // Taxonomies
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        foreach ($taxonomies as $tax) {
            $count = wp_count_terms(['taxonomy' => $tax->name, 'hide_empty' => false]);
            $types[] = [
                'type' => 'taxonomy',
                'subtype' => $tax->name,
                'label' => $tax->labels->name,
                'count' => is_numeric($count) ? (int) $count : 0,
            ];
        }

        // Media
        $mediaCount = array_sum((array) wp_count_attachments());
        $types[] = [
            'type' => 'media',
            'label' => __('Media', 'quant'),
            'count' => $mediaCount,
        ];

        return $types;
    }

    /**
     * Map content type string to SyncType enum.
     */
    private function mapContentTypeToSyncType(string $type): ?SyncType
    {
        return match ($type) {
            'post' => SyncType::Post,
            'page' => SyncType::Page,
            'taxonomy' => SyncType::Taxonomy,
            'media' => SyncType::Media,
            'archive' => SyncType::Archive,
            'redirect' => SyncType::Redirect,
            default => null,
        };
    }
}
