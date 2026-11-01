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
 *
 * Paths are collected during the request and purged once at shutdown, after
 * the response has been sent where the server allows it, so a save is not
 * slowed down by the API and the block editor's double save purges once.
 */
class CachePurgeSubscriber
{
    /** @var array<string, true> */
    private array $pending = [];

    /** @var array<int, array<string>> Paths a post had before an update */
    private array $previous = [];

    private bool $flushRegistered = false;

    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings
    ) {
    }

    public function register(): void
    {
        add_action('pre_post_update', [$this, 'onPrePostUpdate'], 10, 1);
        add_action('wp_after_insert_post', [$this, 'onAfterInsertPost'], 1000, 2);
        add_action('transition_post_status', [$this, 'onTransition'], 10, 3);
        add_action('before_delete_post', [$this, 'onPostDelete'], 10, 1);
        add_action('edited_term', [$this, 'onTermEdit'], 10, 3);
        add_action('pre_delete_term', [$this, 'onTermDeletePre'], 10, 2);
    }

    /**
     * Remember the paths of a published post before it changes (slug, terms).
     */
    public function onPrePostUpdate(int $postId): void
    {
        $post = get_post($postId);
        if (!$this->active() || !$post instanceof WP_Post || $this->ignored($post) || $post->post_status !== 'publish') {
            return;
        }

        $this->previous[$postId] = $this->pathsForPost($post);
    }

    public function onAfterInsertPost(int $postId, ?WP_Post $post = null): void
    {
        $post = $post ?? get_post($postId);
        if (!$this->active() || !$post instanceof WP_Post || $this->ignored($post) || $post->post_status !== 'publish') {
            return;
        }

        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        $this->queue(array_merge($this->previous[$postId] ?? [], $this->pathsForPost($post)));
        unset($this->previous[$postId]);
    }

    /**
     * A published post became draft, private, pending or trash: purge its old paths.
     */
    public function onTransition(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($oldStatus !== 'publish' || $newStatus === 'publish' || !$this->active() || $this->ignored($post)) {
            return;
        }

        $this->queue($this->previous[$post->ID] ?? $this->pathsForPost($post));
    }

    public function onPostDelete(int $postId): void
    {
        $post = get_post($postId);
        if (!$this->active() || !$post instanceof WP_Post || $this->ignored($post) || $post->post_status !== 'publish') {
            return;
        }

        $this->queue($this->pathsForPost($post));
    }

    public function onTermEdit(int $termId, int $ttId, string $taxonomy): void
    {
        if ($this->active()) {
            $this->queue($this->pathsForTerm($termId, $taxonomy));
        }
    }

    public function onTermDeletePre(int $termId, string $taxonomy): void
    {
        if ($this->active()) {
            $this->queue($this->pathsForTerm($termId, $taxonomy));
        }
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
            $paths[] = preg_replace('/__trashed(-\d+)?\/?$/', '', Paths::fromUrl($permalink)) ?? Paths::fromUrl($permalink);
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
     * Purge everything collected in this request. Runs at shutdown.
     */
    public function flush(): void
    {
        $paths = array_keys($this->pending);
        $this->pending = [];

        if ($paths === []) {
            return;
        }

        if (function_exists('fastcgi_finish_request') && !(defined('WP_CLI') && WP_CLI)) {
            fastcgi_finish_request();
        }

        $response = $this->api->purge($paths);

        /** This action is documented in v2.0.0; it fires after an automatic purge. */
        do_action('quant_purged', $paths, $response);
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
    private function queue(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '') {
                $this->pending[$path] = true;
            }
        }

        if ($this->pending !== [] && !$this->flushRegistered) {
            $this->flushRegistered = true;
            add_action('shutdown', [$this, 'flush'], 5);
        }
    }
}
