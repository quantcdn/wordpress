<?php

declare(strict_types=1);

namespace Quant\Search;

use Quant\Api\ContentApiInterface;
use Quant\Sync\Paths;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use WP_Error;

/**
 * Keeps the WordPress page, Quant facets and the static copy in step with a
 * search page definition.
 */
class SearchPageSync
{
    public function __construct(
        private readonly SearchPageRepository $pages,
        private readonly ContentApiInterface $api,
        private readonly SyncQueueInterface $queue,
        private readonly SearchRecordBuilder $records
    ) {
    }

    /**
     * Save a definition, ensure its WordPress page exists, declare facets and queue the page.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function save(array $input): array|WP_Error
    {
        $page = $this->pages->save($input);

        $pageId = $this->ensureWordPressPage($page);
        if ($pageId instanceof WP_Error) {
            return $pageId;
        }

        if ($pageId !== (int) $page['page_id']) {
            $page = $this->pages->save(array_merge($page, ['page_id' => $pageId]));
        }

        $this->declareFacets($page);
        $this->queuePage($page);

        return $page;
    }

    public function delete(string $id): bool
    {
        $page = $this->pages->find($id);
        if ($page === null) {
            return false;
        }

        if ((int) $page['page_id'] > 0) {
            $permalink = get_permalink((int) $page['page_id']);
            if (is_string($permalink)) {
                $this->queue->push(new SyncJob(SyncType::Page, (string) $page['page_id'], SyncAction::Unpublish, [
                    'url' => Paths::normalise(Paths::fromUrl($permalink)),
                ]));
            }
            wp_trash_post((int) $page['page_id']);
        }

        return $this->pages->delete($id);
    }

    /**
     * The WordPress page that renders this search page: created at the route when missing.
     *
     * @param array<string, mixed> $page
     */
    private function ensureWordPressPage(array $page): int|WP_Error
    {
        $existing = (int) $page['page_id'] > 0 ? get_post((int) $page['page_id']) : null;
        $slug = trim($page['route'], '/');
        $content = sprintf('[quant_search page="%s"]', esc_attr($page['id']));

        $data = [
            'post_type' => 'page',
            'post_title' => $page['title'] ?: $page['label'],
            'post_name' => $slug,
            'post_content' => $content,
            'post_status' => $page['status'] ? 'publish' : 'draft',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ];

        if ($existing instanceof \WP_Post && $existing->post_status !== 'trash') {
            $data['ID'] = $existing->ID;
            if (!str_contains($existing->post_content, '[quant_search')) {
                // The editor replaced the shortcode; keep their content.
                unset($data['post_content']);
            }
            $result = wp_update_post($data, true);
        } else {
            $result = wp_insert_post($data, true);
        }

        return $result instanceof WP_Error ? $result : (int) $result;
    }

    /**
     * Register the facets of every enabled search page.
     *
     * A page registers its facets when it is saved. A project that moved to
     * another backend starts with an empty index, so a re-index registers
     * them again. Hooked to quant_seeded.
     *
     * @param array<string, int> $counts Jobs queued per seed type.
     */
    public function onSeeded(array $counts): void
    {
        if (!array_key_exists('search', $counts)) {
            return;
        }

        foreach ($this->pages->all() as $page) {
            if (!empty($page['status'])) {
                $this->declareFacets($page);
            }
        }
    }

    /**
     * @param array<string, mixed> $page
     */
    private function declareFacets(array $page): void
    {
        $lang = $this->records->defaultLanguage();
        $keys = $this->pages->facetKeys($page, $lang);
        foreach ($page['languages'] as $language) {
            $keys = array_merge($keys, $this->pages->facetKeys($page, $language));
        }

        $this->api->addSearchFacets(array_values(array_unique($keys)));
        // A newly registered facet shows on the next render, not after the cache expires.
        Frontend::clearIndexCache();
    }

    /**
     * @param array<string, mixed> $page
     */
    private function queuePage(array $page): void
    {
        if ((int) $page['page_id'] <= 0) {
            return;
        }

        $this->queue->push(new SyncJob(SyncType::Page, (string) $page['page_id'], $page['status'] ? SyncAction::Publish : SyncAction::Unpublish));
    }
}
