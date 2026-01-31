<?php

declare(strict_types=1);

namespace Quant\Hook;

use Quant\Core\Settings;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use WP_Post;

/**
 * Subscribes to WordPress hooks to automatically queue content for syncing to QuantCDN.
 *
 * This subscriber is only active in Static mode when auto_sync is enabled.
 */
class ContentSyncSubscriber
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

    private const IGNORED_TAXONOMIES = [
        'nav_menu',
        'link_category',
        'post_format',
        'wp_theme',
        'wp_template_part_area',
    ];

    public function __construct(
        private readonly SyncQueueInterface $queue,
        private readonly Settings $settings
    ) {}

    public function register(): void
    {
        // Post hooks
        add_action('save_post', [$this, 'onPostSave'], 10, 3);
        add_action('delete_post', [$this, 'onPostDelete'], 10, 2);
        add_action('transition_post_status', [$this, 'onTransitionPostStatus'], 10, 3);

        // Term hooks
        add_action('created_term', [$this, 'onTermCreated'], 10, 3);
        add_action('edited_term', [$this, 'onTermEdited'], 10, 3);
        add_action('delete_term', [$this, 'onTermDelete'], 10, 4);

        // Attachment hooks
        add_action('add_attachment', [$this, 'onAddAttachment'], 10, 1);
        add_action('edit_attachment', [$this, 'onEditAttachment'], 10, 1);
        add_action('delete_attachment', [$this, 'onDeleteAttachment'], 10, 1);
    }

    // =========================================================================
    // Post Hooks
    // =========================================================================

    public function onPostSave(int $postId, WP_Post $post, bool $update): void
    {
        if (!$this->shouldAutoSync()) {
            return;
        }

        if ($this->shouldIgnorePost($post)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        $this->queuePostSync($postId, $post, SyncAction::Publish);
    }

    public function onPostDelete(int $postId, WP_Post $post): void
    {
        if (!$this->shouldAutoSync()) {
            return;
        }

        if ($this->shouldIgnorePost($post)) {
            return;
        }

        $this->queuePostSync($postId, $post, SyncAction::Unpublish);
    }

    public function onTransitionPostStatus(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if (!$this->shouldAutoSync()) {
            return;
        }

        if ($this->shouldIgnorePost($post)) {
            return;
        }

        // Only act on transitions involving 'publish' status
        if ($newStatus === $oldStatus) {
            return;
        }

        if ($newStatus === 'publish' && $oldStatus !== 'publish') {
            // Transitioning to publish - queue for sync
            $this->queuePostSync($post->ID, $post, SyncAction::Publish);
        } elseif ($oldStatus === 'publish' && $newStatus !== 'publish') {
            // Transitioning from publish to something else - unpublish
            $this->queuePostSync($post->ID, $post, SyncAction::Unpublish);
        }
    }

    // =========================================================================
    // Term Hooks
    // =========================================================================

    public function onTermCreated(int $termId, int $ttId, string $taxonomy): void
    {
        if (!$this->shouldAutoSync()) {
            return;
        }

        if ($this->shouldIgnoreTaxonomy($taxonomy)) {
            return;
        }

        $this->queueTermSync($termId, $taxonomy, SyncAction::Publish);
    }

    public function onTermEdited(int $termId, int $ttId, string $taxonomy): void
    {
        if (!$this->shouldAutoSync()) {
            return;
        }

        if ($this->shouldIgnoreTaxonomy($taxonomy)) {
            return;
        }

        $this->queueTermSync($termId, $taxonomy, SyncAction::Publish);
    }

    public function onTermDelete(int $termId, int $ttId, string $taxonomy, mixed $deletedTerm): void
    {
        if (!$this->shouldAutoSync()) {
            return;
        }

        // Only queue if the term was actually an object
        if (!is_object($deletedTerm)) {
            return;
        }

        if ($this->shouldIgnoreTaxonomy($taxonomy)) {
            return;
        }

        $this->queueTermSync($termId, $taxonomy, SyncAction::Unpublish);
    }

    // =========================================================================
    // Attachment Hooks
    // =========================================================================

    public function onAddAttachment(int $attachmentId): void
    {
        if (!$this->shouldAutoSync()) {
            return;
        }

        $this->queueAttachmentSync($attachmentId, SyncAction::Publish);
    }

    public function onEditAttachment(int $attachmentId): void
    {
        if (!$this->shouldAutoSync()) {
            return;
        }

        $this->queueAttachmentSync($attachmentId, SyncAction::Publish);
    }

    public function onDeleteAttachment(int $attachmentId): void
    {
        if (!$this->shouldAutoSync()) {
            return;
        }

        $this->queueAttachmentSync($attachmentId, SyncAction::Unpublish);
    }

    // =========================================================================
    // Private Helper Methods
    // =========================================================================

    private function shouldAutoSync(): bool
    {
        if (!$this->settings->isStaticMode()) {
            return false;
        }

        $settings = $this->settings->getSyncSettings();
        return !empty($settings['auto_sync']);
    }

    private function shouldIgnorePost(WP_Post $post): bool
    {
        return in_array($post->post_type, self::IGNORED_POST_TYPES, true);
    }

    private function shouldIgnoreTaxonomy(string $taxonomy): bool
    {
        if (in_array($taxonomy, self::IGNORED_TAXONOMIES, true)) {
            return true;
        }

        // Check if taxonomy exists and is viewable
        if (!taxonomy_exists($taxonomy)) {
            return true;
        }

        if (!is_taxonomy_viewable($taxonomy)) {
            return true;
        }

        return false;
    }

    private function queuePostSync(int $postId, WP_Post $post, SyncAction $action): void
    {
        $type = $this->determineSyncType($post->post_type);

        $job = new SyncJob(
            type: $type,
            objectId: (string) $postId,
            action: $action,
            meta: ['post_type' => $post->post_type]
        );

        $this->queue->push($job);
    }

    private function queueTermSync(int $termId, string $taxonomy, SyncAction $action): void
    {
        $job = new SyncJob(
            type: SyncType::Taxonomy,
            objectId: (string) $termId,
            action: $action,
            meta: ['taxonomy' => $taxonomy]
        );

        $this->queue->push($job);
    }

    private function queueAttachmentSync(int $attachmentId, SyncAction $action): void
    {
        $job = new SyncJob(
            type: SyncType::Media,
            objectId: (string) $attachmentId,
            action: $action,
            meta: []
        );

        $this->queue->push($job);
    }

    private function determineSyncType(string $postType): SyncType
    {
        return match ($postType) {
            'post' => SyncType::Post,
            'page' => SyncType::Page,
            default => SyncType::CustomPostType,
        };
    }
}
