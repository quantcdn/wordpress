<?php

declare(strict_types=1);

namespace Quant\Sync\Handler;

use Quant\Api\ContentApiInterface;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use RuntimeException;

/**
 * Creates and removes redirects. The job's object id is the source path.
 */
final class RedirectHandler implements HandlerInterface
{
    public function __construct(
        private readonly ContentApiInterface $api
    ) {
    }

    public function supports(SyncJob $job): bool
    {
        return $job->type === SyncType::Redirect && $job->action !== SyncAction::Purge;
    }

    public function handle(SyncJob $job): void
    {
        $from = (string) ($job->meta['source'] ?? $job->objectId);

        if ($job->action === SyncAction::Unpublish) {
            $response = $this->api->unpublish($from);
            if (!$response->success) {
                throw new RuntimeException(esc_html("API error removing redirect {$from}: {$response->message}"));
            }
            return;
        }

        $to = (string) ($job->meta['to'] ?? $job->meta['destination'] ?? '');
        if ($to === '') {
            throw new RuntimeException(esc_html("Missing destination for redirect {$from}"));
        }

        $response = $this->api->createRedirect($from, $to, (int) ($job->meta['code'] ?? 301));
        if (!$response->success) {
            throw new RuntimeException(esc_html("API error publishing redirect {$from}: {$response->message}"));
        }
    }
}
