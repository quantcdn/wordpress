<?php

declare(strict_types=1);

namespace Quant\Hook;

use Quant\Core\Settings;
use Quant\Sync\Paths;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use WP_Post;
use WP_Term;

/**
 * Queues content for Quant when it changes. Active in Static mode with
 * auto-sync on.
 *
 * Paths are captured before an object is removed or renamed, because the
 * permalink of a trashed or deleted object can no longer be computed.
 */
class ContentSyncSubscriber
{
    public const IGNORED_POST_TYPES = [
        'revision',
        'auto-draft',
        'customize_changeset',
        'custom_css',
        'oembed_cache',
        'nav_menu_item',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'wp_font_family',
        'wp_font_face',
    ];

    public const IGNORED_TAXONOMIES = [
        'nav_menu',
        'link_category',
        'post_format',
        'wp_theme',
        'wp_template_part_area',
        'wp_pattern_category',
    ];

    /** @var array<int, string> Permalink paths captured before a post update */
    private array $previousPaths = [];

    /** @var array<int, array<string>> Term paths captured before deletion */
    private array $termPaths = [];

    public function __construct(
        private readonly SyncQueueInterface $queue,
        private readonly Settings $settings
    ) {
    }

    public function register(): void
    {
        add_action('pre_post_update', [$this, 'onPrePostUpdate'], 10, 2);
        add_action('wp_trash_post', [$this, 'onPrePostUpdate'], 10, 1);
        add_action('save_post', [$this, 'onPostSave'], 1000, 3);
        add_action('delete_post', [$this, 'onPostDelete'], 10, 2);
        add_action('transition_post_status', [$this, 'onTransitionPostStatus'], 10, 3);

        add_action('created_term', [$this, 'onTermChanged'], 10, 3);
        add_action('edited_term', [$this, 'onTermChanged'], 10, 3);
        add_action('pre_delete_term', [$this, 'onPreDeleteTerm'], 10, 2);
        add_action('delete_term', [$this, 'onTermDelete'], 10, 4);

        add_action('add_attachment', [$this, 'onAttachmentChanged'], 10, 1);
        add_action('edit_attachment', [$this, 'onAttachmentChanged'], 10, 1);
        add_action('delete_attachment', [$this, 'onAttachmentDelete'], 10, 1);

        add_action('update_option_show_on_front', [$this, 'onFrontPageChanged']);
        add_action('update_option_page_on_front', [$this, 'onFrontPageChanged']);
        add_action('update_option_page_for_posts', [$this, 'onFrontPageChanged']);
    }

    // ---------------------------------------------------------------- posts

    /**
     * Remember the public path of a post before it changes.
     *
     * @param int $postId
     * @param array<string, mixed>|null $data Unused; present for the pre_post_update signature.
     */
    public function onPrePostUpdate(int $postId, ?array $data = null): void
    {
        if (!$this->active()) {
            return;
        }

        $post = get_post($postId);
        if ($post instanceof WP_Post && $post->post_status === 'publish' && !$this->ignoredPost($post)) {
            $permalink = get_permalink($post);
            if (is_string($permalink)) {
                $this->previousPaths[$postId] = Paths::normalise(Paths::fromUrl($permalink));
            }
        }
    }

    public function onPostSave(int $postId, WP_Post $post, bool $update): void
    {
        if (!$this->active() || $this->ignoredPost($post) || wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        $this->queuePost($post, SyncAction::Publish);
        $this->queueSlugChange($post);
        $this->queueRelated($post);
    }

    public function onTransitionPostStatus(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if (!$this->active() || $this->ignoredPost($post) || $newStatus === $oldStatus) {
            return;
        }

        if ($oldStatus === 'publish' && $newStatus !== 'publish') {
            $this->queueUnpublishPost($post);
            $this->queueRelated($post);
        }
    }

    public function onPostDelete(int $postId, ?WP_Post $post = null): void
    {
        $post = $post ?? get_post($postId);
        if (!$this->active() || !$post instanceof WP_Post || $this->ignoredPost($post)) {
            return;
        }

        // A trashed post was unpublished when it was trashed.
        if (in_array($post->post_status, ['trash', 'auto-draft', 'draft', 'inherit'], true)) {
            return;
        }

        $this->queueUnpublishPost($post);
    }

    // ---------------------------------------------------------------- terms

    public function onTermChanged(int $termId, int $ttId, string $taxonomy): void
    {
        if (!$this->active() || $this->ignoredTaxonomy($taxonomy)) {
            return;
        }

        $this->queue->push(new SyncJob(SyncType::Taxonomy, (string) $termId, SyncAction::Publish, ['taxonomy' => $taxonomy, 'page' => 1]));
    }

    public function onPreDeleteTerm(int $termId, string $taxonomy): void
    {
        if (!$this->active() || $this->ignoredTaxonomy($taxonomy)) {
            return;
        }

        $term = get_term($termId, $taxonomy);
        if (!$term instanceof WP_Term) {
            return;
        }

        $link = get_term_link($term);
        if (!is_string($link)) {
            return;
        }

        $paths = [];
        for ($page = 1, $pages = Paths::pageCount((int) $term->count); $page <= $pages; $page++) {
            $paths[] = Paths::normalise(Paths::fromUrl(Paths::paginated($link, $page)));
        }
        $this->termPaths[$termId] = $paths;
    }

    public function onTermDelete(int $termId, int $ttId, string $taxonomy, mixed $deletedTerm): void
    {
        if (!$this->active() || $this->ignoredTaxonomy($taxonomy) || !is_object($deletedTerm)) {
            return;
        }

        $paths = $this->termPaths[$termId] ?? [];
        unset($this->termPaths[$termId]);

        $this->queue->push(new SyncJob(SyncType::Taxonomy, (string) $termId, SyncAction::Unpublish, [
            'taxonomy' => $taxonomy,
            'urls' => $paths,
        ]));
    }

    // ---------------------------------------------------------- attachments

    public function onAttachmentChanged(int $attachmentId): void
    {
        if (!$this->active()) {
            return;
        }

        $this->queue->push(new SyncJob(SyncType::Media, (string) $attachmentId, SyncAction::Publish));
    }

    public function onAttachmentDelete(int $attachmentId): void
    {
        if (!$this->active()) {
            return;
        }

        $paths = $this->attachmentFilePaths($attachmentId);

        $this->queue->push(new SyncJob(SyncType::Media, (string) $attachmentId, SyncAction::Unpublish, ['urls' => $paths]));
    }

    // ---------------------------------------------------------------- misc

    public function onFrontPageChanged(): void
    {
        if (!$this->active()) {
            return;
        }

        $this->queue->push(new SyncJob(SyncType::Home, '/', SyncAction::Publish));
    }

    // ------------------------------------------------------------- helpers

    private function active(): bool
    {
        return $this->settings->isStaticMode() && !empty($this->settings->getSyncSettings()['auto_sync']);
    }

    private function ignoredPost(WP_Post $post): bool
    {
        if (in_array($post->post_type, self::IGNORED_POST_TYPES, true) || $post->post_type === 'attachment') {
            return true;
        }

        $type = get_post_type_object($post->post_type);

        return $type === null || ($type->public !== true && !in_array($post->post_type, ['post', 'page'], true));
    }

    private function ignoredTaxonomy(string $taxonomy): bool
    {
        if (in_array($taxonomy, self::IGNORED_TAXONOMIES, true) || !taxonomy_exists($taxonomy)) {
            return true;
        }

        return !is_taxonomy_viewable($taxonomy);
    }

    private function queuePost(WP_Post $post, SyncAction $action, array $meta = []): void
    {
        $this->queue->push(new SyncJob($this->typeFor($post), (string) $post->ID, $action, $meta + ['post_type' => $post->post_type]));
    }

    private function queueUnpublishPost(WP_Post $post): void
    {
        $path = $this->previousPaths[$post->ID] ?? null;
        if ($path === null) {
            $permalink = get_permalink($post);
            $path = is_string($permalink) ? Paths::normalise(Paths::fromUrl($permalink)) : null;
        }

        $meta = $path !== null ? ['url' => preg_replace('/__trashed(-\d+)?$/', '', $path)] : [];
        $this->queuePost($post, SyncAction::Unpublish, $meta);
    }

    /**
     * A published post whose permalink changed: retire the old path and redirect it.
     */
    private function queueSlugChange(WP_Post $post): void
    {
        $previous = $this->previousPaths[$post->ID] ?? null;
        unset($this->previousPaths[$post->ID]);

        $permalink = get_permalink($post);
        if ($previous === null || !is_string($permalink)) {
            return;
        }

        $current = Paths::normalise(Paths::fromUrl($permalink));
        if ($current === $previous) {
            return;
        }

        $this->queue->push(new SyncJob(SyncType::Redirect, $previous, SyncAction::Publish, ['to' => $current, 'code' => 301]));
    }

    /**
     * Listing pages that show this post: home, its terms, its author and its post type archive.
     */
    private function queueRelated(WP_Post $post): void
    {
        if (empty($this->settings->getSyncSettings()['sync_related'] ?? true)) {
            return;
        }

        $this->queue->push(new SyncJob(SyncType::Home, '/', SyncAction::Publish));

        foreach (get_object_taxonomies($post, 'objects') as $taxonomy) {
            if ($this->ignoredTaxonomy($taxonomy->name)) {
                continue;
            }
            $terms = get_the_terms($post, $taxonomy->name);
            foreach (is_array($terms) ? $terms : [] as $term) {
                $this->queue->push(new SyncJob(SyncType::Taxonomy, (string) $term->term_id, SyncAction::Publish, [
                    'taxonomy' => $taxonomy->name,
                    'page' => 1,
                ]));
            }
        }

        $archive = get_post_type_archive_link($post->post_type);
        if (is_string($archive)) {
            $this->queue->push(new SyncJob(SyncType::Archive, Paths::normalise(Paths::fromUrl($archive)), SyncAction::Publish, ['post_type' => $post->post_type]));
        }

        if ($post->post_type === 'post' && (int) $post->post_author > 0) {
            $author = get_author_posts_url((int) $post->post_author);
            $this->queue->push(new SyncJob(SyncType::Author, Paths::normalise(Paths::fromUrl($author)), SyncAction::Publish, ['user' => (int) $post->post_author]));
        }
    }

    /**
     * @return array<string>
     */
    private function attachmentFilePaths(int $attachmentId): array
    {
        $paths = [];
        $url = wp_get_attachment_url($attachmentId);
        if (is_string($url)) {
            $paths[] = Paths::normalise(Paths::fromUrl($url));
        }

        $metadata = wp_get_attachment_metadata($attachmentId);
        if (is_string($url) && !empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $dir = dirname(Paths::fromUrl($url));
            foreach ($metadata['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $paths[] = Paths::normalise($dir . '/' . $size['file']);
                }
            }
        }

        return array_values(array_unique($paths));
    }

    private function typeFor(WP_Post $post): SyncType
    {
        return match ($post->post_type) {
            'post' => SyncType::Post,
            'page' => SyncType::Page,
            default => SyncType::CustomPostType,
        };
    }
}
