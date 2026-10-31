<?php

declare(strict_types=1);

namespace Quant\Admin;

use Quant\Core\Settings;
use Quant\Sync\DatabaseQueue;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use WP_Admin_Bar;
use WP_Post;

/**
 * Shows the Quant sync status where editors work: a column in the post
 * lists and an admin bar item on single posts. Equivalent of the Drupal
 * module's page info block.
 */
class SyncStatusUi
{
    public function __construct(
        private readonly Settings $settings,
        private readonly DatabaseQueue $queue
    ) {
    }

    public function register(): void
    {
        if (!$this->settings->isStaticMode()) {
            return;
        }

        add_action('admin_init', [$this, 'registerColumns']);
        add_action('admin_post_quant_sync_post', [$this, 'handleSyncPost']);
        add_action('admin_bar_menu', [$this, 'addAdminBarItem'], 100);
        add_filter('post_row_actions', [$this, 'addRowAction'], 10, 2);
        add_filter('page_row_actions', [$this, 'addRowAction'], 10, 2);
    }

    public function registerColumns(): void
    {
        foreach (get_post_types(['public' => true], 'names') as $postType) {
            if ($postType === 'attachment') {
                continue;
            }
            add_filter("manage_{$postType}_posts_columns", [$this, 'addColumn']);
            add_action("manage_{$postType}_posts_custom_column", [$this, 'renderColumn'], 10, 2);
        }
    }

    /**
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function addColumn(array $columns): array
    {
        $columns['quant_sync'] = __('Quant', 'quant');

        return $columns;
    }

    public function renderColumn(string $column, int $postId): void
    {
        if ($column !== 'quant_sync') {
            return;
        }

        $post = get_post($postId);
        if (!$post instanceof WP_Post) {
            return;
        }

        [$label, $class] = $this->statusFor($post);
        printf('<span class="quant-sync-status quant-sync-status--%s">%s</span>', esc_attr($class), esc_html($label));
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function addRowAction(array $actions, WP_Post $post): array
    {
        if ($post->post_status !== 'publish' || !current_user_can('manage_options')) {
            return $actions;
        }

        $url = add_query_arg(['action' => 'quant_sync_post', 'post' => $post->ID, '_wpnonce' => wp_create_nonce('quant_sync_post_' . $post->ID)], admin_url('admin-post.php'));
        $actions['quant_sync'] = sprintf('<a href="%s">%s</a>', esc_url($url), esc_html__('Push to Quant', 'quant'));

        return $actions;
    }

    /**
     * Row action: queue one post and go back to the list.
     */
    public function handleSyncPost(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer() on the next line.
        $postId = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
        check_admin_referer('quant_sync_post_' . $postId);

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do that.', 'quant'));
        }

        $post = get_post($postId);
        if ($post instanceof WP_Post) {
            $this->queue->push(new SyncJob($this->typeFor($post), (string) $post->ID, SyncAction::Publish, ['post_type' => $post->post_type]));
        }

        wp_safe_redirect(add_query_arg('quant_queued', $postId, wp_get_referer() ?: admin_url('edit.php')));
        exit;
    }

    public function addAdminBarItem(WP_Admin_Bar $bar): void
    {
        if (is_admin() || !is_singular() || !current_user_can('edit_posts')) {
            return;
        }

        $post = get_queried_object();
        if (!$post instanceof WP_Post) {
            return;
        }

        [$label] = $this->statusFor($post);

        $bar->add_node([
            'id' => 'quant-sync-status',
            'title' => sprintf('%s: %s', __('Quant', 'quant'), $label),
            'href' => admin_url('admin.php?page=quant#/content'),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function statusFor(WP_Post $post): array
    {
        $status = $this->queue->latestStatuses($this->typeFor($post), [(string) $post->ID])[(string) $post->ID] ?? null;

        if ($status === null) {
            return [__('Not synced', 'quant'), 'never'];
        }

        return match ($status['status']) {
            'completed' => [__('Synced', 'quant') . ($status['processed_at'] ? ' ' . mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $status['processed_at']) : ''), 'synced'],
            'failed' => [__('Failed', 'quant'), 'failed'],
            default => [__('Pending', 'quant'), 'pending'],
        };
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
