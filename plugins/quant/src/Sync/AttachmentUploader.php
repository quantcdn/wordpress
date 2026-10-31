<?php

declare(strict_types=1);

namespace Quant\Sync;

use Quant\Api\ContentApiInterface;

/**
 * Uploads the local files the Content API reports as attachments of a page.
 *
 * The API scans published markup for scripts, stylesheets and media and
 * returns their paths with the md5 it already holds. Files that exist on
 * disk and differ are uploaded, so plugin and core assets reach the edge
 * without being theme assets or media library items.
 */
class AttachmentUploader
{
    /** @var array<string, string> path => md5 uploaded in this process */
    private array $uploaded = [];

    public function __construct(
        private readonly ContentApiInterface $api
    ) {
    }

    /**
     * @param array<string, mixed> $attachments The `attachments` member of a publish response
     * @return array{uploaded: int, skipped: int, errors: array<string>}
     */
    public function upload(array $attachments): array
    {
        $summary = ['uploaded' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($this->flatten($attachments) as $item) {
            $outcome = $this->uploadOne($item);
            if ($outcome === true) {
                $summary['uploaded']++;
            } elseif ($outcome === false) {
                $summary['skipped']++;
            } else {
                $summary['errors'][] = $outcome;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $attachments
     * @return array<int, array<string, mixed>>
     */
    public function flatten(array $attachments): array
    {
        $media = is_array($attachments['media'] ?? null) ? $attachments['media'] : [];

        $groups = [
            $attachments['js'] ?? [],
            $attachments['css'] ?? [],
            $media['images'] ?? [],
            $media['documents'] ?? [],
            $media['video'] ?? [],
            $media['audio'] ?? [],
            $attachments['fonts'] ?? [],
        ];

        $items = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            foreach ($group as $item) {
                if (is_array($item) && isset($item['path'])) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     * @return bool|string true uploaded, false skipped, string error
     */
    private function uploadOne(array $item): bool|string
    {
        $url = $this->servableUrl($item);
        if ($url === null) {
            return false;
        }

        $servedPath = Paths::normalise(strtok($url, '?') ?: $url);
        $file = Paths::localFile($url);
        $md5 = $file !== null ? md5_file($file) : false;
        if ($file === null || $md5 === false) {
            return false;
        }

        if (($item['existing_md5'] ?? null) === $md5 || ($this->uploaded[$servedPath] ?? null) === $md5) {
            return false;
        }

        $response = $this->api->uploadFile($servedPath, $file);
        if (!$response->success) {
            return "{$servedPath}: {$response->message}";
        }

        $this->uploaded[$servedPath] = $md5;

        return true;
    }

    /**
     * The local, root-relative URL of an attachment, or null when Quant cannot serve it from this site.
     *
     * @param array<string, mixed> $item
     */
    private function servableUrl(array $item): ?string
    {
        $original = (string) ($item['original_path'] ?? $item['path']);
        if (!Paths::isLocal($original)) {
            return null;
        }

        $url = urldecode((string) $item['path']);
        if ($url === '/css' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        return $url;
    }
}
