<?php

declare(strict_types=1);

namespace Quant\Sync;

use Quant\Api\ContentApiInterface;
use WP_Post;

/**
 * Syncs WordPress media library attachments to QuantCDN.
 */
final class MediaSync
{
    public function __construct(
        private readonly ContentApiInterface $api
    ) {
    }

    /**
     * Sync a single attachment to QuantCDN.
     */
    public function sync(int $attachmentId): FileSyncResult
    {
        $post = get_post($attachmentId);

        if (!$post instanceof WP_Post) {
            return FileSyncResult::failure('', "Attachment {$attachmentId} not found");
        }

        if ($post->post_type !== 'attachment') {
            return FileSyncResult::failure('', "Post {$attachmentId} is not an attachment");
        }

        $localPath = get_attached_file($attachmentId);
        if (!$localPath || !file_exists($localPath)) {
            return FileSyncResult::failure('', "File not found for attachment {$attachmentId}");
        }

        $cdnPath = $this->getAttachmentPath($attachmentId);
        if ($cdnPath === null) {
            return FileSyncResult::failure('', "Could not determine CDN path for attachment {$attachmentId}");
        }

        // Upload the main file
        $response = $this->api->uploadFile($cdnPath, $localPath);
        if (!$response->success) {
            return FileSyncResult::failure($cdnPath, $response->message);
        }

        // Sync image sizes if this is an image
        $meta = [];
        if ($this->isImage($post)) {
            $sizesSynced = $this->syncImageSizes($attachmentId, $localPath);
            if ($sizesSynced > 0) {
                $meta['sizes_synced'] = $sizesSynced;
            }
        }

        return FileSyncResult::success($cdnPath, $meta);
    }

    /**
     * Get the CDN path for an attachment.
     */
    public function getAttachmentPath(int $attachmentId): ?string
    {
        $url = wp_get_attachment_url($attachmentId);

        if (!$url) {
            return null;
        }

        $parsed = wp_parse_url($url);
        $path = $parsed['path'] ?? '';

        return $path ?: null;
    }

    /**
     * Check if an attachment is an image.
     */
    private function isImage(WP_Post $post): bool
    {
        return str_starts_with($post->post_mime_type, 'image/');
    }

    /**
     * Sync all generated image sizes for an attachment.
     *
     * @return int Number of sizes successfully synced
     */
    private function syncImageSizes(int $attachmentId, string $mainFilePath): int
    {
        $metadata = wp_get_attachment_metadata($attachmentId);

        if (empty($metadata['sizes']) || !is_array($metadata['sizes'])) {
            return 0;
        }

        $baseDir = dirname($mainFilePath);
        $baseUrl = dirname($this->getAttachmentPath($attachmentId) ?? '');
        $sizesSynced = 0;

        foreach ($metadata['sizes'] as $size => $sizeData) {
            if (empty($sizeData['file'])) {
                continue;
            }

            $sizeFilePath = $baseDir . '/' . $sizeData['file'];
            $sizeCdnPath = $baseUrl . '/' . $sizeData['file'];

            if (!file_exists($sizeFilePath)) {
                continue;
            }

            $response = $this->api->uploadFile($sizeCdnPath, $sizeFilePath);
            if ($response->success) {
                $sizesSynced++;
            }
        }

        return $sizesSynced;
    }
}
