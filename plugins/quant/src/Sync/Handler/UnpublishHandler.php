<?php

declare(strict_types=1);

namespace Quant\Sync\Handler;

use Quant\Sync\Paths;
use Quant\Sync\Publisher;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use RuntimeException;
use WP_Post;
use WP_Term;

/**
 * Unpublishes content. The paths come from the job meta (captured before
 * deletion) and fall back to the current permalink when the object still exists.
 */
final class UnpublishHandler implements HandlerInterface
{
    public function __construct(
        private readonly Publisher $publisher
    ) {
    }

    public function supports(SyncJob $job): bool
    {
        return $job->action === SyncAction::Unpublish && $job->type !== SyncType::Redirect;
    }

    public function handle(SyncJob $job): void
    {
        $paths = $this->pathsFromMeta($job);

        if ($paths === []) {
            $paths = $this->derivePaths($job);
        }

        if ($paths === []) {
            throw new RuntimeException(esc_html("Cannot determine the path to unpublish for {$job->type->value} #{$job->objectId}"));
        }

        $this->publisher->unpublish(array_map([Paths::class, 'normalise'], $paths));
    }

    /**
     * @return array<string>
     */
    private function pathsFromMeta(SyncJob $job): array
    {
        $paths = [];
        if (!empty($job->meta['url']) && is_string($job->meta['url'])) {
            $paths[] = $job->meta['url'];
        }
        foreach ((array) ($job->meta['urls'] ?? []) as $url) {
            if (is_string($url) && $url !== '') {
                $paths[] = $url;
            }
        }

        return $paths;
    }

    /**
     * @return array<string>
     */
    private function derivePaths(SyncJob $job): array
    {
        if ($job->type->isRoute()) {
            return [(string) ($job->meta['path'] ?? $job->objectId)];
        }

        if ($job->type === SyncType::Taxonomy) {
            $term = get_term((int) $job->objectId, (string) ($job->meta['taxonomy'] ?? ''));
            $link = $term instanceof WP_Term ? get_term_link($term) : null;

            return is_string($link) ? [Paths::fromUrl($link)] : [];
        }

        if ($job->type === SyncType::Media) {
            $url = wp_get_attachment_url((int) $job->objectId);

            return is_string($url) ? [Paths::fromUrl($url)] : [];
        }

        $post = get_post((int) $job->objectId);
        if (!$post instanceof WP_Post) {
            return [];
        }

        $permalink = get_permalink($post);

        return is_string($permalink) ? [Paths::fromUrl($permalink)] : [];
    }
}
