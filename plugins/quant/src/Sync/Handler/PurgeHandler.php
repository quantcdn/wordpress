<?php

declare(strict_types=1);

namespace Quant\Sync\Handler;

use Quant\Api\ContentApiInterface;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use RuntimeException;

final class PurgeHandler implements HandlerInterface
{
    public function __construct(
        private readonly ContentApiInterface $api
    ) {
    }

    public function supports(SyncJob $job): bool
    {
        return $job->action === SyncAction::Purge;
    }

    public function handle(SyncJob $job): void
    {
        $paths = (array) ($job->meta['paths'] ?? [$job->meta['path'] ?? $job->objectId]);
        $response = $this->api->purge(array_values(array_filter($paths, 'is_string')));

        if (!$response->success) {
            throw new RuntimeException($response->message);
        }
    }
}
