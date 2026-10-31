<?php

declare(strict_types=1);

namespace Quant\Hook;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Sync\Paths;
use WP_Post;

/**
 * Purges the edge cache when content changes.
 *
 * Applies in CDN-only mode, and in Static mode when automatic sync is off
 * (the same rule as v1). When Static mode syncs automatically, publishing
 * the page already refreshes the edge.
 */
class CachePurgeSubscriber
{
    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings
    ) {
    }

    public function register(): void
    {
        add_action('save_post', [$this, 'onPostSave'], 1000, 3);
        add_action('wp_trash_post', [$this, 'onPostTrash'], 10, 1);
        add_action('before_delete_post', [$this, 'onPostTrash'], 10, 1);
        add_action('edited_term', [$this, 'onTermEdit'], 10, 3);
        add_action('pre_delete_term', [$this, 'onTermDeletePre'], 10, 2);
    }

    public function onPostSave(int $postId, WP_Post $post, bool $update): void
    {
        if (!$this->active() || $this->ignored($post) || $post->post_status !== 'publish') {
            return;
        }

        $this->purge($this->pathsForPost($post));
    }

    public function onPostTrash(int $postId): void
    {
        $post = get_post($postId);
        if (!$this->active() || !$post instanceof WP_Post || $this->ignored($post) || $post->post_status !== 'publish') {
            return;
        }

        $this->purge($this->pathsForPost($post));
    }

    public function onTermEdit(int $termId, int $ttId, string $taxonomy): void
    {
        if (!$this->active()) {
            return;
        }

        $this->purge($this->pathsForTerm($termId, $taxonomy));
    }

    public function onTermDeletePre(int $termId, string $taxonomy): void
    {
        if (!$this->active()) {
            return;
        }

        $this->purge($this->pathsForTerm($termId, $taxonomy));
    }

    /**
     * Paths to purge for a post: the post, home, its terms, author and archive.
     *
     * @return array<string>
     */
    public function pathsForPost(WP_Post $post): array
    {
        $paths = ['/'];

        $permalink = get_permalink($post);
        if (is_string($permalink)) {
            $paths[] = Paths::fromUrl($permalink);
        }

        foreach (get_object_taxonomies($post, 'names') as $taxonomy) {
            if (!is_taxonomy_viewable($taxonomy)) {
                continue;
            }
            $terms = get_the_terms($post, $taxonomy);
            foreach (is_array($terms) ? $terms : [] as $term) {
                $link = get_term_link($term);
                if (is_string($link)) {
                    $paths[] = Paths::fromUrl($link);
                }
            }
        }

        $archive = get_post_type_archive_link($post->post_type);
        if (is_string($archive)) {
            $paths[] = Paths::fromUrl($archive);
        }

        if ($post->post_type === 'post') {
            $paths[] = Paths::fromUrl(get_author_posts_url((int) $post->post_author));
        }

        /** This filter is documented in v2.0.0; it adjusts the paths purged when a post changes. */
        return array_values(array_unique((array) apply_filters('quant_purge_paths', $paths, $post)));
    }

    /**
     * @return array<string>
     */
    private function pathsForTerm(int $termId, string $taxonomy): array
    {
        $link = get_term_link($termId, $taxonomy);

        return is_string($link) ? [Paths::fromUrl($link)] : [];
    }

    private function active(): bool
    {
        $sync = $this->settings->getSyncSettings();
        if (empty($sync['auto_purge']) || !$this->settings->hasValidCredentials()) {
            return false;
        }

        return $this->settings->isCdnOnlyMode() || empty($sync['auto_sync']);
    }

    private function ignored(WP_Post $post): bool
    {
        return in_array($post->post_type, ContentSyncSubscriber::IGNORED_POST_TYPES, true)
            || !is_post_type_viewable($post->post_type);
    }

    /**
     * @param array<string> $paths
     */
    private function purge(array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths)));
        if ($paths === []) {
            return;
        }

        $response = $this->api->purge($paths);

        /** This action is documented in v2.0.0; it fires after an automatic purge. */
        do_action('quant_purged', $paths, $response);
    }
}
