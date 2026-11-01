<?php

declare(strict_types=1);

namespace Quant\Sync\Handler;

use Quant\Sync\Exception\RedirectResponseException;
use Quant\Sync\Exception\UnpublishedContentException;
use Quant\Sync\Publisher;
use Quant\Sync\Renderer\TermRenderer;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;

/**
 * Publishes taxonomy term listing pages.
 */
final class TermPublishHandler implements HandlerInterface
{
    public function __construct(
        private readonly TermRenderer $renderer,
        private readonly Publisher $publisher,
        private readonly SyncQueueInterface $queue
    ) {
    }

    public function supports(SyncJob $job): bool
    {
        return $job->type === SyncType::Taxonomy && $job->action === SyncAction::Publish;
    }

    public function handle(SyncJob $job): void
    {
        $taxonomy = (string) ($job->meta['taxonomy'] ?? '');
        if ($taxonomy === '') {
            $taxonomy = $this->guessTaxonomy((int) $job->objectId);
        }

        try {
            $rendered = $this->renderer->render((int) $job->objectId, $taxonomy, (int) ($job->meta['page'] ?? 1));
        } catch (RedirectResponseException $redirect) {
            $this->queue->push(new SyncJob(SyncType::Redirect, $redirect->from, SyncAction::Publish, [
                'to' => $redirect->to,
                'code' => $redirect->statusCode,
            ]));
            return;
        } catch (UnpublishedContentException $unpublished) {
            $this->publisher->unpublish([$unpublished->path]);
            return;
        }

        $this->publisher->publish($rendered);
    }

    private function guessTaxonomy(int $termId): string
    {
        $term = get_term($termId);

        return $term instanceof \WP_Term ? $term->taxonomy : 'category';
    }
}
