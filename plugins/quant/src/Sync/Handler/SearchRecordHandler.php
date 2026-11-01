<?php

declare(strict_types=1);

namespace Quant\Sync\Handler;

use Quant\Api\ContentApiInterface;
use Quant\Search\SearchRecordBuilder;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use RuntimeException;
use WP_Post;

/**
 * Pushes a search record without republishing the page (search reindex).
 */
final class SearchRecordHandler implements HandlerInterface
{
    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly SearchRecordBuilder $builder
    ) {
    }

    public function supports(SyncJob $job): bool
    {
        return $job->type === SyncType::SearchRecord && $job->action === SyncAction::Publish;
    }

    public function handle(SyncJob $job): void
    {
        $post = get_post((int) $job->objectId);
        if (!$post instanceof WP_Post || $post->post_status !== 'publish') {
            return;
        }

        $record = $this->builder->forPost($post);
        if (!empty($record['skip'])) {
            return;
        }

        $response = $this->api->sendSearchRecords([$record]);
        if (!$response->success) {
            throw new RuntimeException(esc_html("API error indexing {$record['url']}: {$response->message}"));
        }
    }
}
