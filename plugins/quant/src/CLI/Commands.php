<?php

declare(strict_types=1);

namespace Quant\CLI;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use Quant\Sync\Renderer\ContentRendererInterface;
use WP_CLI;

/**
 * WP-CLI commands for QuantCDN.
 *
 * Provides command-line interface for cache purging, content syncing,
 * and queue management operations.
 */
class Commands
{
    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings,
        private readonly SyncQueueInterface $queue,
        private readonly ContentRendererInterface $renderer
    ) {
    }

    /**
     * Purge cache from QuantCDN.
     *
     * ## OPTIONS
     *
     * [<path>]
     * : Path to purge (e.g., /blog/my-post)
     *
     * [--all]
     * : Purge all cached content
     *
     * ## EXAMPLES
     *
     *     # Purge a specific path
     *     wp quant purge /blog/my-post
     *
     *     # Purge all cached content
     *     wp quant purge --all
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args       Positional arguments
     * @param array<string, mixed> $assocArgs Named arguments
     */
    public function purge(array $args, array $assocArgs): void
    {
        if (!$this->settings->hasValidCredentials()) {
            WP_CLI::error('QuantCDN credentials are not configured. Please configure in the WordPress admin.');
        }

        $purgeAll = isset($assocArgs['all']);

        if ($purgeAll) {
            $path = '/*';
            WP_CLI::log('Purging all cached content...');
        } elseif (!empty($args[0])) {
            $path = $this->normalizePath($args[0]);
            WP_CLI::log(sprintf('Purging cache for: %s', $path));
        } else {
            WP_CLI::error('Please specify a path to purge or use --all to purge everything.');
        }

        $response = $this->api->purge($path);

        if ($response->success) {
            WP_CLI::success(sprintf('Cache purged successfully for: %s', $path));
        } else {
            WP_CLI::error(sprintf('Failed to purge cache: %s', $response->message));
        }
    }

    /**
     * Sync content to QuantCDN.
     *
     * ## OPTIONS
     *
     * [<post_id>]
     * : Post ID to sync
     *
     * [--all]
     * : Sync all content
     *
     * [--type=<type>]
     * : Content type to sync (post, page, media, taxonomy, archive)
     *
     * [--immediate]
     * : Process immediately instead of queueing (for single post)
     *
     * ## EXAMPLES
     *
     *     # Sync a specific post immediately
     *     wp quant sync 123 --immediate
     *
     *     # Queue a specific post for sync
     *     wp quant sync 123
     *
     *     # Sync all content
     *     wp quant sync --all
     *
     *     # Sync all posts
     *     wp quant sync --type=post
     *
     *     # Sync all pages
     *     wp quant sync --type=page
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args       Positional arguments
     * @param array<string, mixed> $assocArgs Named arguments
     */
    public function sync(array $args, array $assocArgs): void
    {
        if (!$this->settings->hasValidCredentials()) {
            WP_CLI::error('QuantCDN credentials are not configured. Please configure in the WordPress admin.');
        }

        if (!$this->settings->isStaticMode()) {
            WP_CLI::error('Content sync is only available in static mode. Current mode: ' . $this->settings->getMode());
        }

        $syncAll = isset($assocArgs['all']);
        $type = $assocArgs['type'] ?? null;
        $immediate = isset($assocArgs['immediate']);
        $postId = !empty($args[0]) ? (int) $args[0] : null;

        if ($postId !== null) {
            $this->syncPost($postId, $immediate);
            return;
        }

        if ($syncAll) {
            $this->syncAllContent();
            return;
        }

        if ($type !== null) {
            $this->syncByType($type);
            return;
        }

        WP_CLI::error('Please specify a post ID, use --all to sync everything, or use --type=<type> to sync by type.');
    }

    /**
     * Manage the sync queue.
     *
     * ## OPTIONS
     *
     * <action>
     * : Action to perform (status, process, clear, retry)
     *
     * [--limit=<limit>]
     * : Number of items to process (default: 50)
     *
     * ## EXAMPLES
     *
     *     # Show queue status
     *     wp quant queue status
     *
     *     # Process pending queue items
     *     wp quant queue process
     *
     *     # Process 100 items
     *     wp quant queue process --limit=100
     *
     *     # Clear pending items
     *     wp quant queue clear
     *
     *     # Retry failed items
     *     wp quant queue retry
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args       Positional arguments
     * @param array<string, mixed> $assocArgs Named arguments
     */
    public function queue(array $args, array $assocArgs): void
    {
        if (empty($args[0])) {
            WP_CLI::error('Please specify an action: status, process, clear, or retry.');
        }

        $action = $args[0];

        switch ($action) {
            case 'status':
                $this->queueStatus();
                break;

            case 'process':
                $limit = isset($assocArgs['limit']) ? (int) $assocArgs['limit'] : 50;
                $this->queueProcess($limit);
                break;

            case 'clear':
                $this->queueClear();
                break;

            case 'retry':
                $this->queueRetry();
                break;

            default:
                WP_CLI::error(sprintf('Unknown action: %s. Use: status, process, clear, or retry.', $action));
        }
    }

    /**
     * Show connection status.
     *
     * ## EXAMPLES
     *
     *     wp quant status
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args       Positional arguments
     * @param array<string, mixed> $assocArgs Named arguments
     */
    public function status(array $args, array $assocArgs): void
    {
        WP_CLI::log('QuantCDN Connection Status');
        WP_CLI::log('==========================');

        $credentials = $this->settings->getCredentials();
        $hasCredentials = $this->settings->hasValidCredentials();

        WP_CLI::log('');
        WP_CLI::log('Credentials:');
        WP_CLI::log(sprintf('  Configured: %s', $hasCredentials ? 'Yes' : 'No'));

        if ($hasCredentials) {
            WP_CLI::log(sprintf('  Organization: %s', $credentials['organization']));
            WP_CLI::log(sprintf('  Project: %s', $credentials['project']));
            WP_CLI::log(sprintf('  Endpoint: %s', $credentials['endpoint'] ?? 'https://api.quantcdn.io'));
        }

        WP_CLI::log('');
        WP_CLI::log('Mode:');
        WP_CLI::log(sprintf('  Current mode: %s', $this->settings->getMode()));

        if ($hasCredentials) {
            WP_CLI::log('');
            WP_CLI::log('Connection Test:');

            $response = $this->api->ping();

            if ($response->success) {
                WP_CLI::success('Connected to QuantCDN API');
            } else {
                WP_CLI::warning(sprintf('Connection failed: %s', $response->message));
            }
        }
    }

    /**
     * Show plugin information.
     *
     * ## EXAMPLES
     *
     *     wp quant info
     *
     * @when after_wp_load
     *
     * @param array<int, string> $args       Positional arguments
     * @param array<string, mixed> $assocArgs Named arguments
     */
    public function info(array $args, array $assocArgs): void
    {
        global $wp_version;

        WP_CLI::log('QuantCDN Plugin Information');
        WP_CLI::log('===========================');

        WP_CLI::log('');
        WP_CLI::log('Plugin:');
        WP_CLI::log(sprintf('  Version: %s', QUANT_VERSION));
        WP_CLI::log(sprintf('  Mode: %s', $this->settings->getMode()));

        WP_CLI::log('');
        WP_CLI::log('Environment:');
        WP_CLI::log(sprintf('  PHP Version: %s', PHP_VERSION));
        WP_CLI::log(sprintf('  WordPress Version: %s', $wp_version));

        WP_CLI::log('');
        WP_CLI::log('Credentials:');
        WP_CLI::log(sprintf('  Configured: %s', $this->settings->hasValidCredentials() ? 'Yes' : 'No'));

        $status = $this->queue->getStatus();

        WP_CLI::log('');
        WP_CLI::log('Queue Status:');
        WP_CLI::log(sprintf('  Pending: %d', $status->pending));
        WP_CLI::log(sprintf('  Processing: %d', $status->processing));
        WP_CLI::log(sprintf('  Failed: %d', $status->failed));
        WP_CLI::log(sprintf('  Total: %d', $status->total));

        $syncSettings = $this->settings->getSyncSettings();

        WP_CLI::log('');
        WP_CLI::log('Sync Settings:');
        WP_CLI::log(sprintf('  Auto Sync: %s', ($syncSettings['auto_sync'] ?? false) ? 'Enabled' : 'Disabled'));
        WP_CLI::log(sprintf('  Auto Purge: %s', ($syncSettings['auto_purge'] ?? false) ? 'Enabled' : 'Disabled'));
        WP_CLI::log(sprintf('  Sync Drafts: %s', ($syncSettings['sync_drafts'] ?? false) ? 'Enabled' : 'Disabled'));
    }

    /**
     * Sync a specific post.
     */
    private function syncPost(int $postId, bool $immediate): void
    {
        $post = get_post($postId);

        if ($post === null) {
            WP_CLI::error(sprintf('Post not found: %d', $postId));
        }

        if ($immediate) {
            WP_CLI::log(sprintf('Rendering and publishing post %d: %s', $postId, $post->post_title));

            $rendered = $this->renderer->render($postId);

            if ($rendered === null) {
                WP_CLI::error(sprintf('Failed to render post %d. Post may not be published or renderable.', $postId));
            }

            $response = $this->api->publish($rendered->url, $rendered->markup, $rendered->meta);

            if ($response->success) {
                WP_CLI::success(sprintf('Published: %s', $rendered->url));
            } else {
                WP_CLI::error(sprintf('Failed to publish: %s', $response->message));
            }
        } else {
            $syncType = $this->getSyncTypeForPost($post);

            $job = new SyncJob(
                type: $syncType,
                objectId: (string) $postId,
                action: SyncAction::Publish,
            );

            $this->queue->push($job);
            WP_CLI::success(sprintf('Queued post %d for sync: %s', $postId, $post->post_title));
        }
    }

    /**
     * Queue all content for sync.
     */
    private function syncAllContent(): void
    {
        WP_CLI::log('Queueing all content for sync...');

        $jobs = [];
        $counts = [
            'posts' => 0,
            'pages' => 0,
            'media' => 0,
        ];

        // Queue posts
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        foreach ($posts as $postId) {
            $jobs[] = new SyncJob(
                type: SyncType::Post,
                objectId: (string) $postId,
                action: SyncAction::Publish,
            );
            $counts['posts']++;
        }

        // Queue pages
        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        foreach ($pages as $pageId) {
            $jobs[] = new SyncJob(
                type: SyncType::Page,
                objectId: (string) $pageId,
                action: SyncAction::Publish,
            );
            $counts['pages']++;
        }

        // Queue media
        $media = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        foreach ($media as $mediaId) {
            $jobs[] = new SyncJob(
                type: SyncType::Media,
                objectId: (string) $mediaId,
                action: SyncAction::Publish,
            );
            $counts['media']++;
        }

        if (!empty($jobs)) {
            $this->queue->pushBatch($jobs);
        }

        WP_CLI::success(sprintf(
            'Queued %d items for sync: %d posts, %d pages, %d media',
            count($jobs),
            $counts['posts'],
            $counts['pages'],
            $counts['media']
        ));

        WP_CLI::log('Run "wp quant queue process" to process the queue.');
    }

    /**
     * Queue content by type for sync.
     */
    private function syncByType(string $type): void
    {
        $syncType = $this->parseSyncType($type);

        if ($syncType === null) {
            WP_CLI::error(sprintf(
                'Invalid type: %s. Valid types: post, page, media, taxonomy, archive',
                $type
            ));
        }

        WP_CLI::log(sprintf('Queueing all %s content for sync...', $type));

        $jobs = [];

        switch ($syncType) {
            case SyncType::Post:
                $posts = get_posts([
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'numberposts' => -1,
                    'fields' => 'ids',
                ]);

                foreach ($posts as $postId) {
                    $jobs[] = new SyncJob(
                        type: SyncType::Post,
                        objectId: (string) $postId,
                        action: SyncAction::Publish,
                    );
                }
                break;

            case SyncType::Page:
                $pages = get_posts([
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'numberposts' => -1,
                    'fields' => 'ids',
                ]);

                foreach ($pages as $pageId) {
                    $jobs[] = new SyncJob(
                        type: SyncType::Page,
                        objectId: (string) $pageId,
                        action: SyncAction::Publish,
                    );
                }
                break;

            case SyncType::Media:
                $media = get_posts([
                    'post_type' => 'attachment',
                    'post_status' => 'inherit',
                    'numberposts' => -1,
                    'fields' => 'ids',
                ]);

                foreach ($media as $mediaId) {
                    $jobs[] = new SyncJob(
                        type: SyncType::Media,
                        objectId: (string) $mediaId,
                        action: SyncAction::Publish,
                    );
                }
                break;

            case SyncType::Taxonomy:
                // Queue categories
                $categories = get_categories(['hide_empty' => false]);
                foreach ($categories as $category) {
                    $jobs[] = new SyncJob(
                        type: SyncType::Taxonomy,
                        objectId: (string) $category->term_id,
                        action: SyncAction::Publish,
                        meta: ['taxonomy' => 'category'],
                    );
                }

                // Queue tags
                $tags = get_tags(['hide_empty' => false]);
                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        $jobs[] = new SyncJob(
                            type: SyncType::Taxonomy,
                            objectId: (string) $tag->term_id,
                            action: SyncAction::Publish,
                            meta: ['taxonomy' => 'post_tag'],
                        );
                    }
                }
                break;

            case SyncType::Archive:
                // Queue archive pages - use a special identifier
                $jobs[] = new SyncJob(
                    type: SyncType::Archive,
                    objectId: 'main',
                    action: SyncAction::Publish,
                );
                break;

            default:
                WP_CLI::error(sprintf('Type "%s" is not yet supported for bulk sync.', $type));
        }

        if (!empty($jobs)) {
            $this->queue->pushBatch($jobs);
        }

        WP_CLI::success(sprintf('Queued %d %s items for sync.', count($jobs), $type));
        WP_CLI::log('Run "wp quant queue process" to process the queue.');
    }

    /**
     * Show queue status.
     */
    private function queueStatus(): void
    {
        $status = $this->queue->getStatus();

        WP_CLI::log('Queue Status');
        WP_CLI::log('============');
        WP_CLI::log('');
        WP_CLI::log(sprintf('Pending:    %d', $status->pending));
        WP_CLI::log(sprintf('Processing: %d', $status->processing));
        WP_CLI::log(sprintf('Failed:     %d', $status->failed));
        WP_CLI::log(sprintf('Total:      %d', $status->total));

        $completed = $status->total - $status->pending - $status->processing - $status->failed;
        if ($completed > 0) {
            WP_CLI::log(sprintf('Completed:  %d', $completed));
        }
    }

    /**
     * Process queue items.
     */
    private function queueProcess(int $limit): void
    {
        if (!$this->settings->hasValidCredentials()) {
            WP_CLI::error('QuantCDN credentials are not configured.');
        }

        WP_CLI::log(sprintf('Processing up to %d queue items...', $limit));

        $jobs = $this->queue->fetchPending($limit);

        if (empty($jobs)) {
            WP_CLI::log('No pending items in queue.');
            return;
        }

        $processed = 0;
        $failed = 0;
        $progress = \WP_CLI\Utils\make_progress_bar('Processing', count($jobs));

        foreach ($jobs as $job) {
            try {
                $success = $this->processJob($job);

                if ($success) {
                    $this->queue->markCompleted($job->id);
                    $processed++;
                } else {
                    $this->queue->markFailed($job->id, 'Processing failed');
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->queue->markFailed($job->id, $e->getMessage());
                $failed++;
            }

            $progress->tick();
        }

        $progress->finish();

        WP_CLI::success(sprintf('Processed %d items (%d successful, %d failed)', count($jobs), $processed, $failed));
    }

    /**
     * Process a single job.
     */
    private function processJob(SyncJob $job): bool
    {
        switch ($job->action) {
            case SyncAction::Publish:
                return $this->processPublishJob($job);

            case SyncAction::Unpublish:
                return $this->processUnpublishJob($job);

            case SyncAction::Purge:
                return $this->processPurgeJob($job);

            default:
                return false;
        }
    }

    /**
     * Process a publish job.
     */
    private function processPublishJob(SyncJob $job): bool
    {
        switch ($job->type) {
            case SyncType::Post:
            case SyncType::Page:
            case SyncType::CustomPostType:
                $rendered = $this->renderer->render((int) $job->objectId);

                if ($rendered === null) {
                    return false;
                }

                $response = $this->api->publish($rendered->url, $rendered->markup, $rendered->meta);
                return $response->success;

            case SyncType::Media:
                $attachment = get_post((int) $job->objectId);
                if ($attachment === null) {
                    return false;
                }

                $filePath = get_attached_file((int) $job->objectId);
                if ($filePath === false || !file_exists($filePath)) {
                    return false;
                }

                $url = wp_get_attachment_url((int) $job->objectId);
                if ($url === false) {
                    return false;
                }

                $path = wp_parse_url($url, PHP_URL_PATH);
                if ($path === null) {
                    return false;
                }

                $response = $this->api->uploadFile($path, $filePath);
                return $response->success;

            default:
                // Other types not yet implemented
                return false;
        }
    }

    /**
     * Process an unpublish job.
     */
    private function processUnpublishJob(SyncJob $job): bool
    {
        // Need to determine the URL from the job
        // For posts/pages, we can get it from the post
        $post = get_post((int) $job->objectId);

        if ($post === null) {
            // Post deleted, get URL from meta if available
            $url = $job->meta['url'] ?? null;
            if ($url === null) {
                return false;
            }
        } else {
            $permalink = get_permalink($post);
            if ($permalink === false) {
                return false;
            }
            $url = wp_parse_url($permalink, PHP_URL_PATH) ?? '/';
        }

        $response = $this->api->unpublish($url);
        return $response->success;
    }

    /**
     * Process a purge job.
     */
    private function processPurgeJob(SyncJob $job): bool
    {
        $path = $job->meta['path'] ?? null;

        if ($path === null) {
            return false;
        }

        $response = $this->api->purge($path);
        return $response->success;
    }

    /**
     * Clear pending queue items.
     */
    private function queueClear(): void
    {
        $status = $this->queue->getStatus();
        $pending = $status->pending;

        if ($pending === 0) {
            WP_CLI::log('No pending items to clear.');
            return;
        }

        WP_CLI::confirm(sprintf('Are you sure you want to clear %d pending items?', $pending));

        $this->queue->clear();
        WP_CLI::success(sprintf('Cleared %d pending items from queue.', $pending));
    }

    /**
     * Retry failed queue items.
     */
    private function queueRetry(): void
    {
        $status = $this->queue->getStatus();

        if ($status->failed === 0) {
            WP_CLI::log('No failed items to retry.');
            return;
        }

        $count = $this->queue->retry();
        WP_CLI::success(sprintf('Queued %d failed items for retry.', $count));
    }

    /**
     * Normalize a path for purging.
     */
    private function normalizePath(string $path): string
    {
        // Ensure path starts with /
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $path;
    }

    /**
     * Parse a string type into a SyncType enum.
     */
    private function parseSyncType(string $type): ?SyncType
    {
        return match (strtolower($type)) {
            'post' => SyncType::Post,
            'page' => SyncType::Page,
            'media', 'attachment' => SyncType::Media,
            'taxonomy', 'category', 'tag' => SyncType::Taxonomy,
            'archive' => SyncType::Archive,
            default => null,
        };
    }

    /**
     * Get the SyncType for a given post.
     *
     * @param \WP_Post $post
     */
    private function getSyncTypeForPost($post): SyncType
    {
        return match ($post->post_type) {
            'post' => SyncType::Post,
            'page' => SyncType::Page,
            'attachment' => SyncType::Media,
            default => SyncType::CustomPostType,
        };
    }
}
