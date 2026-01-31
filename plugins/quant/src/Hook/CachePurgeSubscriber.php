<?php

declare(strict_types=1);

namespace Quant\Hook;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use WP_Post;

class CachePurgeSubscriber
{
    private const IGNORED_POST_TYPES = [
        'revision',
        'auto-draft',
        'customize_changeset',
        'oembed_cache',
        'nav_menu_item',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
    ];

    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings
    ) {}

    public function register(): void
    {
        add_action('save_post', [$this, 'onPostSave'], 10, 3);
        add_action('delete_post', [$this, 'onPostDelete'], 10, 2);
        add_action('trashed_post', [$this, 'onPostTrash'], 10, 2);
        add_action('edit_term', [$this, 'onTermEdit'], 10, 3);
        add_action('delete_term', [$this, 'onTermDelete'], 10, 4);
    }

    public function onPostSave(int $postId, WP_Post $post, bool $update): void
    {
        if (!$this->shouldAutoPurge()) {
            return;
        }

        if ($this->shouldIgnorePost($post)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        $this->purgePost($postId);
    }

    public function onPostDelete(int $postId, WP_Post $post): void
    {
        if (!$this->shouldAutoPurge()) {
            return;
        }

        if ($this->shouldIgnorePost($post)) {
            return;
        }

        $this->purgePost($postId);
    }

    public function onPostTrash(int $postId, string $previousStatus): void
    {
        if (!$this->shouldAutoPurge()) {
            return;
        }

        if ($previousStatus === 'publish') {
            $this->purgePost($postId);
        }
    }

    public function onTermEdit(int $termId, int $ttId, string $taxonomy): void
    {
        if (!$this->shouldAutoPurge()) {
            return;
        }

        $this->purgeTerm($termId, $taxonomy);
    }

    public function onTermDelete(int $termId, int $ttId, string $taxonomy, mixed $deletedTerm): void
    {
        if (!$this->shouldAutoPurge()) {
            return;
        }

        if (is_object($deletedTerm)) {
            $this->purgeTerm($termId, $taxonomy);
        }
    }

    private function shouldAutoPurge(): bool
    {
        $settings = $this->settings->getSyncSettings();
        return !empty($settings['auto_purge']);
    }

    private function shouldIgnorePost(WP_Post $post): bool
    {
        return in_array($post->post_type, self::IGNORED_POST_TYPES, true);
    }

    private function purgePost(int $postId): void
    {
        $permalink = get_permalink($postId);

        if (!$permalink) {
            return;
        }

        $path = $this->getPathFromUrl($permalink);

        if ($path) {
            $this->api->purge($path);
        }
    }

    private function purgeTerm(int $termId, string $taxonomy): void
    {
        $link = get_term_link($termId, $taxonomy);

        if (is_wp_error($link)) {
            return;
        }

        $path = $this->getPathFromUrl($link);

        if ($path) {
            $this->api->purge($path);
        }
    }

    private function getPathFromUrl(string $url): ?string
    {
        $parsed = wp_parse_url($url);
        $path = $parsed['path'] ?? '/';

        // Normalize: remove trailing slash except for root
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
