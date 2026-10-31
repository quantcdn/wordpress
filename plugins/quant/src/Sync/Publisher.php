<?php

declare(strict_types=1);

namespace Quant\Sync;

use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Sync\Renderer\RenderedContent;
use RuntimeException;

/**
 * Sends rendered content to Quant and performs the follow-up work every page
 * needs: uploading the assets the API reports and queueing paginated pages
 * linked from the markup.
 */
class Publisher
{
    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly Settings $settings,
        private readonly SyncQueueInterface $queue,
        private readonly AttachmentUploader $attachments
    ) {
    }

    /**
     * @throws RuntimeException when the API rejects the content
     */
    public function publish(RenderedContent $content): void
    {
        $response = $this->api->publish($content->url, $content->markup, $content->info, $content->options);

        if (!$response->success) {
            throw new RuntimeException(esc_html("API error publishing {$content->url}: {$response->message}"));
        }

        $attachments = $response->data['attachments'] ?? null;
        if (is_array($attachments)) {
            $this->attachments->upload($attachments);
        }

        $this->purgeVariants([$content->url]);
        $this->followLinks($content);
    }

    /**
     * Purge /path and /path/ so neither variant serves a stale copy (as 1.6.0 did).
     * A failed purge does not fail the job: the content itself was published.
     *
     * @param array<string> $paths
     */
    private function purgeVariants(array $paths): void
    {
        /** This filter is documented in v2.0.0; return false to skip the purge after a publish or unpublish. */
        if (!apply_filters('quant_purge_after_publish', true, $paths)) {
            return;
        }

        $this->api->purge($paths);
    }

    /**
     * Unpublish one or more paths. Missing content is not an error.
     *
     * @param array<string> $paths
     */
    public function unpublish(array $paths): void
    {
        $errors = [];
        foreach (array_unique($paths) as $path) {
            $response = $this->api->unpublish($path);
            if (!$response->success) {
                $errors[] = "{$path}: {$response->message}";
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(esc_html('API error unpublishing: ' . implode('; ', $errors)));
        }

        $this->purgeVariants(array_values(array_unique($paths)));
    }

    /**
     * Queue the paginated pages a listing links to.
     */
    private function followLinks(RenderedContent $content): void
    {
        if (empty($this->settings->getStaticConfig()['follow_links'])) {
            return;
        }

        foreach (LinkDiscovery::paginationLinks($content->markup) as $path) {
            if ($path === $content->url) {
                continue;
            }

            if ($this->queue->hasRecentJob(SyncType::Route, $path, SyncAction::Publish, 600)) {
                continue;
            }

            $this->queue->push(new SyncJob(SyncType::Route, $path, SyncAction::Publish, ['discovered_from' => $content->url]));
        }
    }
}
