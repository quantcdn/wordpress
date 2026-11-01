<?php

declare(strict_types=1);

namespace Quant\Sync\Handler;

use Quant\Search\SearchRecordBuilder;
use Quant\Sync\Exception\RedirectResponseException;
use Quant\Sync\Exception\UnpublishedContentException;
use Quant\Sync\Publisher;
use Quant\Sync\Renderer\ContentRendererInterface;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use WP_Post;

/**
 * Publishes posts, pages and custom post type entries, with their search record.
 */
final class PostPublishHandler implements HandlerInterface
{
    public function __construct(
        private readonly ContentRendererInterface $renderer,
        private readonly Publisher $publisher,
        private readonly SearchRecordBuilder $search,
        private readonly SyncQueueInterface $queue
    ) {
    }

    public function supports(SyncJob $job): bool
    {
        return $job->action === SyncAction::Publish
            && in_array($job->type, [SyncType::Post, SyncType::Page, SyncType::CustomPostType], true);
    }

    public function handle(SyncJob $job): void
    {
        $postId = (int) $job->objectId;

        try {
            $rendered = $this->renderer->render($postId);
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

        $post = get_post($postId);
        if ($post instanceof WP_Post && $this->search->enabled()) {
            $rendered = $rendered->withOption('search_record', $this->search->forPost($post));
        }

        $this->publisher->publish($rendered);
    }
}
