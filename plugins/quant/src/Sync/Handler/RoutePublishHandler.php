<?php

declare(strict_types=1);

namespace Quant\Sync\Handler;

use Quant\Api\ContentApiInterface;
use Quant\Sync\Exception\RedirectResponseException;
use Quant\Sync\Exception\UnpublishedContentException;
use Quant\Sync\Paths;
use Quant\Sync\Publisher;
use Quant\Sync\Renderer\RouteRenderer;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use RuntimeException;

/**
 * Publishes any URL path: home, archives, author pages, custom routes,
 * sitemaps and the 404 page. A custom route that is a file on disk is
 * uploaded instead of rendered.
 */
final class RoutePublishHandler implements HandlerInterface
{
    public const NOT_FOUND_PATH = '/_quant404';

    public function __construct(
        private readonly RouteRenderer $renderer,
        private readonly Publisher $publisher,
        private readonly ContentApiInterface $api,
        private readonly SyncQueueInterface $queue
    ) {
    }

    public function supports(SyncJob $job): bool
    {
        return $job->action === SyncAction::Publish && $job->type->isRoute();
    }

    public function handle(SyncJob $job): void
    {
        $path = $this->pathFor($job);

        if ($job->type === SyncType::CustomRoute) {
            $file = Paths::localFile($path);
            if ($file !== null) {
                $this->uploadFile($path, $file);
                return;
            }
        }

        $publishAs = $job->type === SyncType::NotFound ? self::NOT_FOUND_PATH : ($job->meta['publish_as'] ?? null);

        try {
            $rendered = $this->renderer->render($path, $publishAs, $job->type === SyncType::NotFound);
        } catch (RedirectResponseException $redirect) {
            if ($job->type === SyncType::Home || $job->type === SyncType::NotFound) {
                throw new RuntimeException(esc_html("Unexpected redirect while rendering {$path}: {$redirect->getMessage()}"));
            }
            $this->queue->push(new SyncJob(SyncType::Redirect, $redirect->from, SyncAction::Publish, [
                'to' => $redirect->to,
                'code' => $redirect->statusCode,
            ]));
            return;
        } catch (UnpublishedContentException $gone) {
            if ($job->type === SyncType::Home) {
                throw new RuntimeException(esc_html("The home page returned an error: {$gone->getMessage()}"));
            }
            $this->publisher->unpublish([$gone->path]);
            return;
        }

        $this->publisher->publish($rendered);
    }

    private function pathFor(SyncJob $job): string
    {
        $path = (string) ($job->meta['path'] ?? $job->objectId);

        if ($job->type === SyncType::Home && ($path === 'home' || $path === '')) {
            $path = '/';
        }

        return '/' . ltrim($path, '/');
    }

    private function uploadFile(string $path, string $file): void
    {
        $response = $this->api->uploadFile(Paths::normalise(strtok($path, '?') ?: $path), $file);
        if (!$response->success) {
            throw new RuntimeException(esc_html("API error uploading {$path}: {$response->message}"));
        }
    }
}
