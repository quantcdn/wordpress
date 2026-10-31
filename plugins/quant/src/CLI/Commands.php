<?php

declare(strict_types=1);

namespace Quant\CLI;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Search\SearchRecordBuilder;
use Quant\Sync\JobProcessor;
use Quant\Sync\Paths;
use Quant\Sync\Seeder;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use WP_CLI;

/**
 * Manage the QuantCDN integration.
 *
 * The v1 commands (`info`, `reset_queue`, `process_queue`) keep working.
 */
class Commands
{
    /**
     * v1 queue names and the v2 seed types they map to.
     */
    private const LEGACY_QUEUES = [
        'pages' => ['page'],
        'posts' => ['post'],
        'custom_posts' => ['__cpt__'],
        'categories' => ['taxonomy'],
        'tags' => ['taxonomy'],
        'custom_taxonomies' => ['taxonomy'],
        'home' => ['home'],
        'theme_assets' => ['theme_assets'],
        'custom_routes' => ['custom_routes'],
        'archives' => ['archives'],
        'media_assets' => ['media'],
        'redirects' => ['redirects'],
    ];

    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings,
        private readonly SyncQueueInterface $queue,
        private readonly JobProcessor $processor,
        private readonly Seeder $seeder,
        private readonly SearchRecordBuilder $search
    ) {
    }

    /**
     * Show connection status, mode and queue counts.
     *
     * ## EXAMPLES
     *
     *     wp quant status
     *
     * @when after_wp_load
     */
    public function status(array $args, array $assocArgs): void
    {
        $credentials = $this->settings->getCredentials();
        $status = $this->queue->getStatus();

        WP_CLI::log('QuantCDN ' . QUANT_VERSION);
        WP_CLI::log(sprintf('Mode:          %s', $this->settings->getMode()));
        WP_CLI::log(sprintf('Organization:  %s', $credentials['organization'] ?: '(not set)'));
        WP_CLI::log(sprintf('Project:       %s', $credentials['project'] ?: '(not set)'));
        WP_CLI::log(sprintf('Endpoint:      %s', $credentials['endpoint']));
        WP_CLI::log(sprintf('Queue:         %d pending, %d processing, %d failed, %d total', $status->pending, $status->processing, $status->failed, $status->total));

        if (!$this->settings->hasValidCredentials()) {
            WP_CLI::warning('Credentials are not configured.');
            return;
        }

        $response = $this->api->ping();
        if ($response->success) {
            $search = !empty($response->data['config']['search_enabled']) ? 'enabled' : 'disabled';
            WP_CLI::success("Connected to the Quant API (search {$search}).");
        } else {
            WP_CLI::error("Connection failed: {$response->message}");
        }
    }

    /**
     * Test the API connection.
     *
     * @when after_wp_load
     */
    public function ping(array $args, array $assocArgs): void
    {
        $this->requireCredentials();
        $response = $this->api->ping();
        $response->success ? WP_CLI::success('pong') : WP_CLI::error($response->message);
    }

    /**
     * Purge the edge cache.
     *
     * ## OPTIONS
     *
     * [<path>...]
     * : One or more paths to purge.
     *
     * [--all]
     * : Purge everything.
     *
     * [--paths=<file>]
     * : Read paths from a file, one per line.
     *
     * ## EXAMPLES
     *
     *     wp quant purge /blog/my-post
     *     wp quant purge --all
     *
     * @when after_wp_load
     */
    public function purge(array $args, array $assocArgs): void
    {
        $this->requireCredentials();

        $paths = $args;
        if (isset($assocArgs['all'])) {
            $paths = ['/*'];
        }
        if (!empty($assocArgs['paths'])) {
            $file = (string) $assocArgs['paths'];
            if (!is_readable($file)) {
                WP_CLI::error("Cannot read {$file}");
            }
            $paths = array_merge($paths, array_filter(array_map('trim', file($file) ?: [])));
        }

        if ($paths === []) {
            WP_CLI::error('Specify a path, --all, or --paths=<file>.');
        }

        $response = $this->api->purge($paths);
        $response->success
            ? WP_CLI::success(sprintf('Purged %d path(s).', count($paths)))
            : WP_CLI::error($response->message);
    }

    /**
     * Queue content for sync.
     *
     * ## OPTIONS
     *
     * [<post_id>...]
     * : Post IDs to sync.
     *
     * [--all]
     * : Queue everything the Content page's "Seed All" queues.
     *
     * [--type=<type>]
     * : Queue one type: post, page, media, taxonomy, home, archives, authors,
     *   theme_assets, custom_routes, sitemaps, redirects, search, or a custom post type.
     *
     * [--process]
     * : Process the queue after queueing.
     *
     * [--immediate]
     * : Alias of --process.
     *
     * ## EXAMPLES
     *
     *     wp quant sync --all --process
     *     wp quant sync --type=page
     *     wp quant sync 123 456 --process
     *
     * @when after_wp_load
     */
    public function sync(array $args, array $assocArgs): void
    {
        $this->requireCredentials();
        $this->requireStaticMode();

        if ($args !== []) {
            $this->queuePosts(array_map('intval', $args));
        } elseif (isset($assocArgs['all'])) {
            $this->report($this->seeder->seed(['all']));
        } elseif (!empty($assocArgs['type'])) {
            $this->report($this->seeder->seed([(string) $assocArgs['type']]));
        } else {
            WP_CLI::error('Specify post IDs, --all, or --type=<type>.');
        }

        if (isset($assocArgs['process']) || isset($assocArgs['immediate'])) {
            $this->drain(PHP_INT_MAX, 50);
        } else {
            WP_CLI::log('Run "wp quant queue process --all" to push the queued items.');
        }
    }

    /**
     * Unpublish content from Quant.
     *
     * ## OPTIONS
     *
     * [<path>...]
     * : Paths to unpublish.
     *
     * [--post=<id>]
     * : Unpublish a post by ID.
     *
     * @when after_wp_load
     */
    public function unpublish(array $args, array $assocArgs): void
    {
        $this->requireCredentials();

        $paths = array_map([Paths::class, 'normalise'], $args);
        if (!empty($assocArgs['post'])) {
            $permalink = get_permalink((int) $assocArgs['post']);
            if (!is_string($permalink)) {
                WP_CLI::error('Post not found.');
            }
            $paths[] = Paths::normalise(Paths::fromUrl($permalink));
        }

        if ($paths === []) {
            WP_CLI::error('Specify a path or --post=<id>.');
        }

        foreach ($paths as $path) {
            $response = $this->api->unpublish($path);
            $response->success ? WP_CLI::success("Unpublished {$path}") : WP_CLI::warning("{$path}: {$response->message}");
        }
    }

    /**
     * Manage the sync queue.
     *
     * ## OPTIONS
     *
     * <action>
     * : status, process, clear, retry, errors
     *
     * [--limit=<n>]
     * : Jobs per batch (default 50).
     *
     * [--all]
     * : Keep processing until the queue is empty.
     *
     * [--threads=<n>]
     * : Run n worker processes in parallel (process only, max 20).
     *
     * ## EXAMPLES
     *
     *     wp quant queue status
     *     wp quant queue process --all --threads=5
     *     wp quant queue retry
     *
     * @when after_wp_load
     */
    public function queue(array $args, array $assocArgs): void
    {
        $action = $args[0] ?? '';
        $limit = max(1, (int) ($assocArgs['limit'] ?? 50));

        match ($action) {
            'status' => $this->queueStatus(),
            'process' => $this->queueProcess($limit, isset($assocArgs['all']), (int) ($assocArgs['threads'] ?? 1)),
            'clear' => $this->queueClear(),
            'retry' => WP_CLI::success(sprintf('%d failed job(s) queued for retry.', $this->queue->retry())),
            'errors' => $this->queueErrors(),
            default => WP_CLI::error('Unknown action. Use: status, process, clear, retry, errors.'),
        };
    }

    /**
     * Rebuild the Quant Search index from WordPress content.
     *
     * ## OPTIONS
     *
     * [--clear]
     * : Clear the index first.
     *
     * [--process]
     * : Process the queue after queueing.
     *
     * @when after_wp_load
     */
    public function search(array $args, array $assocArgs): void
    {
        $this->requireCredentials();

        if (!$this->search->enabled()) {
            WP_CLI::error('Enable Quant Search records in the plugin settings first.');
        }

        if (isset($assocArgs['clear'])) {
            $response = $this->api->clearSearchIndex();
            $response->success ? WP_CLI::log('Search index cleared.') : WP_CLI::error($response->message);
        }

        $this->report($this->seeder->seed(['search']));

        if (isset($assocArgs['process'])) {
            $this->drain(PHP_INT_MAX, 50);
        }
    }

    // ----------------------------------------------------------- v1 aliases

    /**
     * (v1) Show the queue status.
     *
     * @when after_wp_load
     * @subcommand info
     */
    public function info(array $args, array $assocArgs): void
    {
        $this->status($args, $assocArgs);
    }

    /**
     * (v1) Reset a queue: drop its pending jobs and queue its content again.
     *
     * ## OPTIONS
     *
     * <queue>
     * : pages, posts, custom_posts, categories, tags, custom_taxonomies, home,
     *   theme_assets, custom_routes, archives, media_assets, redirects, or all.
     *
     * @when after_wp_load
     * @subcommand reset_queue
     */
    public function resetQueue(array $args, array $assocArgs): void
    {
        $this->requireCredentials();
        $this->requireStaticMode();

        $types = $this->legacyTypes((string) ($args[0] ?? ''));
        $this->queue->clearTypes([]);
        $this->report($this->seeder->seed($types));
        WP_CLI::success('Queue reset.');
    }

    /**
     * (v1) Process a queue.
     *
     * ## OPTIONS
     *
     * <queue>
     * : A v1 queue name or all. All pending jobs are processed regardless of the name.
     *
     * [--threads=<threads>]
     * : Worker processes to run in parallel.
     * ---
     * default: 5
     * ---
     *
     * @when after_wp_load
     * @subcommand process_queue
     */
    public function processQueueLegacy(array $args, array $assocArgs): void
    {
        $this->requireCredentials();
        $this->queueProcess(50, true, (int) ($assocArgs['threads'] ?? 5));
    }

    /**
     * (v1, internal) Worker used by --threads.
     *
     * @when after_wp_load
     * @subcommand process_queue_single
     */
    public function processQueueSingle(array $args, array $assocArgs): void
    {
        $this->requireCredentials();
        $this->drain(PHP_INT_MAX, 25);
    }

    // -------------------------------------------------------------- helpers

    private function requireCredentials(): void
    {
        if (!$this->settings->hasValidCredentials()) {
            WP_CLI::error('QuantCDN credentials are not configured. Configure them in QuantCDN > Settings or with QUANT_* environment variables.');
        }
    }

    private function requireStaticMode(): void
    {
        if (!$this->settings->isStaticMode()) {
            WP_CLI::error('Content sync is only available in Static mode. Current mode: ' . $this->settings->getMode());
        }
    }

    /**
     * @param array<int> $ids
     */
    private function queuePosts(array $ids): void
    {
        $jobs = [];
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post instanceof \WP_Post) {
                WP_CLI::warning("Post {$id} not found.");
                continue;
            }
            $type = match ($post->post_type) {
                'post' => SyncType::Post,
                'page' => SyncType::Page,
                'attachment' => SyncType::Media,
                default => SyncType::CustomPostType,
            };
            $jobs[] = new SyncJob($type, (string) $id, SyncAction::Publish, ['post_type' => $post->post_type]);
        }

        $this->queue->pushBatch($jobs);
        WP_CLI::success(sprintf('Queued %d post(s).', count($jobs)));
    }

    /**
     * @param array{queued: int, types: array<string, int>} $result
     */
    private function report(array $result): void
    {
        foreach ($result['types'] as $type => $count) {
            WP_CLI::log(sprintf('  %-16s %d', $type, $count));
        }
        WP_CLI::success(sprintf('Queued %d item(s).', $result['queued']));
    }

    private function queueStatus(): void
    {
        $status = $this->queue->getStatus();
        $completed = $status->total - $status->pending - $status->processing - $status->failed;

        WP_CLI\Utils\format_items('table', [[
            'pending' => $status->pending,
            'processing' => $status->processing,
            'failed' => $status->failed,
            'completed' => $completed,
            'total' => $status->total,
        ]], ['pending', 'processing', 'failed', 'completed', 'total']);
    }

    private function queueProcess(int $limit, bool $all, int $threads): void
    {
        $this->requireCredentials();

        $threads = max(1, min(20, $threads));
        if ($threads > 1) {
            $this->runWorkers($threads);
            return;
        }

        $this->drain($all ? PHP_INT_MAX : 1, $limit);
    }

    /**
     * Process batches until the queue is empty or $maxBatches is reached.
     */
    private function drain(int $maxBatches, int $limit): void
    {
        $processed = 0;
        $failed = 0;
        $batches = 0;

        while ($batches < $maxBatches) {
            $result = $this->processor->run($limit, 600);
            $processed += $result->processed;
            $failed += $result->failed;
            $batches++;

            foreach ($result->errors as $error) {
                WP_CLI::warning($error);
            }

            if ($result->processed + $result->failed === 0 || $this->queue->getStatus()->pending === 0) {
                break;
            }
        }

        $message = sprintf('Processed %d item(s), %d failed.', $processed, $failed);
        $failed > 0 ? WP_CLI::warning($message) : WP_CLI::success($message);
    }

    /**
     * Spawn worker processes that each drain the queue.
     */
    private function runWorkers(int $threads): void
    {
        $this->queue->recoverStale(0);
        $command = $this->workerCommand();
        $procs = [];

        for ($i = 0; $i < $threads; $i++) {
            $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($proc)) {
                $procs[] = [$proc, $pipes];
            }
        }

        WP_CLI::log(sprintf('Started %d worker(s).', count($procs)));

        foreach ($procs as [$proc, $pipes]) {
            WP_CLI::log(trim((string) stream_get_contents($pipes[1])));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }

        $status = $this->queue->getStatus();
        WP_CLI::success(sprintf('Workers finished. %d pending, %d failed.', $status->pending, $status->failed));
    }

    private function workerCommand(): string
    {
        $parts = ['wp', 'quant', 'queue', 'process', '--all', '--path=' . escapeshellarg(ABSPATH)];

        foreach (['url', 'allow-root', 'skip-plugins', 'skip-themes'] as $flag) {
            if (WP_CLI::has_config($flag) && WP_CLI::get_config($flag)) {
                $value = WP_CLI::get_config($flag);
                $parts[] = $value === true ? "--{$flag}" : "--{$flag}=" . escapeshellarg((string) $value);
            }
        }

        return implode(' ', $parts);
    }

    private function queueClear(): void
    {
        $pending = $this->queue->getStatus()->pending;
        if ($pending === 0) {
            WP_CLI::log('No pending items to clear.');
            return;
        }

        WP_CLI::confirm(sprintf('Clear %d pending item(s)?', $pending));
        $this->queue->clear();
        WP_CLI::success(sprintf('Cleared %d pending item(s).', $pending));
    }

    private function queueErrors(): void
    {
        if (!$this->queue instanceof \Quant\Sync\DatabaseQueue) {
            WP_CLI::log('Not available for this queue backend.');
            return;
        }

        $errors = $this->queue->recentErrors(25);
        if ($errors === []) {
            WP_CLI::success('No failed jobs.');
            return;
        }

        WP_CLI\Utils\format_items('table', $errors, ['type', 'object_id', 'error_message', 'processed_at']);
    }

    /**
     * @return array<string>
     */
    private function legacyTypes(string $queue): array
    {
        if ($queue === 'all') {
            return ['all'];
        }

        if (!isset(self::LEGACY_QUEUES[$queue])) {
            WP_CLI::error('Invalid queue name. Valid names: ' . implode(', ', array_keys(self::LEGACY_QUEUES)) . ', all');
        }

        $types = self::LEGACY_QUEUES[$queue];

        return $types === ['__cpt__'] ? $this->seeder->customPostTypes() : $types;
    }
}
