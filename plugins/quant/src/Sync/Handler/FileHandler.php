<?php

declare(strict_types=1);

namespace Quant\Sync\Handler;

use Quant\Api\ContentApiInterface;
use Quant\Sync\MediaSync;
use Quant\Sync\Paths;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use Quant\Sync\ThemeAssetSync;
use RuntimeException;

/**
 * Uploads media library items, theme assets and plain files.
 */
final class FileHandler implements HandlerInterface
{
    public function __construct(
        private readonly ContentApiInterface $api,
        private readonly MediaSync $media,
        private readonly ThemeAssetSync $themeAssets
    ) {
    }

    public function supports(SyncJob $job): bool
    {
        return $job->action === SyncAction::Publish
            && in_array($job->type, [SyncType::Media, SyncType::ThemeAsset, SyncType::ThemeAssets, SyncType::File], true);
    }

    public function handle(SyncJob $job): void
    {
        match ($job->type) {
            SyncType::Media => $this->handleMedia((int) $job->objectId),
            SyncType::ThemeAssets => $this->handleAllThemeAssets(),
            default => $this->handleFile($job),
        };
    }

    private function handleMedia(int $attachmentId): void
    {
        $result = $this->media->sync($attachmentId);
        if (!$result->success) {
            throw new RuntimeException($result->message);
        }
    }

    private function handleAllThemeAssets(): void
    {
        $failures = array_filter($this->themeAssets->syncActiveTheme(), static fn($r): bool => !$r->success);
        if ($failures !== []) {
            $first = reset($failures);
            throw new RuntimeException(count($failures) . " theme assets failed, first: {$first->path}: {$first->message}");
        }
    }

    private function handleFile(SyncJob $job): void
    {
        $url = (string) ($job->meta['url'] ?? $job->objectId);
        $file = (string) ($job->meta['file'] ?? '');
        if ($file === '') {
            $file = (string) Paths::localFile($url);
        }

        if ($file === '' || !is_file($file)) {
            throw new RuntimeException("File not found for {$url}");
        }

        $response = $this->api->uploadFile(Paths::normalise(strtok($url, '?') ?: $url), $file);
        if (!$response->success) {
            throw new RuntimeException("API error uploading {$url}: {$response->message}");
        }
    }
}
