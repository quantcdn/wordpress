<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Quant\Api\ContentApiInterface;
use Quant\Api\ApiResponse;
use Quant\Sync\MediaSync;
use Quant\Sync\FileSyncResult;

#[CoversClass(MediaSync::class)]
final class MediaSyncTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ContentApiInterface $api;
    private MediaSync $mediaSync;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->mediaSync = new MediaSync($this->api);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_syncs_an_attachment_successfully(): void
    {
        $attachmentId = 123;
        $localPath = sys_get_temp_dir() . '/test-image-' . uniqid() . '.jpg';
        $cdnPath = '/wp-content/uploads/2024/01/image.jpg';

        file_put_contents($localPath, 'fake image content');

        try {
            Functions\when('get_post')
                ->justReturn($this->createAttachmentPost($attachmentId, 'image/jpeg'));

            Functions\when('get_attached_file')
                ->justReturn($localPath);

            Functions\when('wp_get_attachment_url')
                ->justReturn('https://example.com/wp-content/uploads/2024/01/image.jpg');

            Functions\when('wp_parse_url')
                ->alias('parse_url');

            Functions\when('wp_get_attachment_metadata')
                ->justReturn([]);

            $this->api->shouldReceive('uploadFile')
                ->once()
                ->with($cdnPath, $localPath)
                ->andReturn(ApiResponse::success(['quant_revision' => 1]));

            $result = $this->mediaSync->sync($attachmentId);

            $this->assertInstanceOf(FileSyncResult::class, $result);
            $this->assertTrue($result->success);
            $this->assertSame($cdnPath, $result->path);
        } finally {
            @unlink($localPath);
        }
    }

    #[Test]
    public function it_returns_failure_when_attachment_not_found(): void
    {
        Functions\when('get_post')
            ->justReturn(null);

        $result = $this->mediaSync->sync(999);

        $this->assertInstanceOf(FileSyncResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('not found', $result->message);
    }

    #[Test]
    public function it_returns_failure_when_post_is_not_attachment(): void
    {
        $post = $this->createAttachmentPost(123, 'image/jpeg');
        $post->post_type = 'post';

        Functions\when('get_post')
            ->justReturn($post);

        $result = $this->mediaSync->sync(123);

        $this->assertInstanceOf(FileSyncResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('not an attachment', $result->message);
    }

    #[Test]
    public function it_returns_failure_when_file_does_not_exist(): void
    {
        $attachmentId = 123;

        Functions\when('get_post')
            ->justReturn($this->createAttachmentPost($attachmentId, 'image/jpeg'));

        Functions\when('get_attached_file')
            ->justReturn('/non/existent/file.jpg');

        $result = $this->mediaSync->sync($attachmentId);

        $this->assertInstanceOf(FileSyncResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('File not found', $result->message);
    }

    #[Test]
    public function it_returns_failure_when_no_attachment_url(): void
    {
        $attachmentId = 123;
        $localPath = sys_get_temp_dir() . '/test-image-' . uniqid() . '.jpg';
        file_put_contents($localPath, 'fake image content');

        try {
            Functions\when('get_post')
                ->justReturn($this->createAttachmentPost($attachmentId, 'image/jpeg'));

            Functions\when('get_attached_file')
                ->justReturn($localPath);

            Functions\when('wp_get_attachment_url')
                ->justReturn(false);

            $result = $this->mediaSync->sync($attachmentId);

            $this->assertInstanceOf(FileSyncResult::class, $result);
            $this->assertFalse($result->success);
            $this->assertStringContainsString('Could not determine', $result->message);
        } finally {
            @unlink($localPath);
        }
    }

    #[Test]
    public function it_returns_failure_when_api_upload_fails(): void
    {
        $attachmentId = 123;
        $localPath = sys_get_temp_dir() . '/test-image-' . uniqid() . '.jpg';
        file_put_contents($localPath, 'fake image content');

        try {
            Functions\when('get_post')
                ->justReturn($this->createAttachmentPost($attachmentId, 'image/jpeg'));

            Functions\when('get_attached_file')
                ->justReturn($localPath);

            Functions\when('wp_get_attachment_url')
                ->justReturn('https://example.com/wp-content/uploads/2024/01/image.jpg');

            Functions\when('wp_parse_url')
                ->alias('parse_url');

            Functions\when('wp_get_attachment_metadata')
                ->justReturn([]);

            $this->api->shouldReceive('uploadFile')
                ->once()
                ->andReturn(ApiResponse::error('Server error', 500));

            $result = $this->mediaSync->sync($attachmentId);

            $this->assertInstanceOf(FileSyncResult::class, $result);
            $this->assertFalse($result->success);
            $this->assertStringContainsString('Server error', $result->message);
        } finally {
            @unlink($localPath);
        }
    }

    #[Test]
    public function it_syncs_image_sizes_for_attachments(): void
    {
        $attachmentId = 123;
        $baseDir = sys_get_temp_dir() . '/test-uploads-' . uniqid();
        mkdir($baseDir, 0755, true);

        $mainFile = $baseDir . '/image.jpg';
        $thumbnailFile = $baseDir . '/image-150x150.jpg';
        $mediumFile = $baseDir . '/image-300x200.jpg';

        file_put_contents($mainFile, 'main image');
        file_put_contents($thumbnailFile, 'thumbnail');
        file_put_contents($mediumFile, 'medium');

        try {
            Functions\when('get_post')
                ->justReturn($this->createAttachmentPost($attachmentId, 'image/jpeg'));

            Functions\when('get_attached_file')
                ->justReturn($mainFile);

            Functions\when('wp_get_attachment_url')
                ->justReturn('https://example.com/wp-content/uploads/2024/01/image.jpg');

            Functions\when('wp_parse_url')
                ->alias('parse_url');

            Functions\when('wp_get_attachment_metadata')
                ->justReturn([
                    'width' => 1920,
                    'height' => 1080,
                    'file' => '2024/01/image.jpg',
                    'sizes' => [
                        'thumbnail' => [
                            'file' => 'image-150x150.jpg',
                            'width' => 150,
                            'height' => 150,
                            'mime-type' => 'image/jpeg',
                        ],
                        'medium' => [
                            'file' => 'image-300x200.jpg',
                            'width' => 300,
                            'height' => 200,
                            'mime-type' => 'image/jpeg',
                        ],
                    ],
                ]);

            // Expect upload for main file + 2 sizes
            $this->api->shouldReceive('uploadFile')
                ->times(3)
                ->andReturn(ApiResponse::success(['quant_revision' => 1]));

            $result = $this->mediaSync->sync($attachmentId);

            $this->assertTrue($result->success);
            $this->assertArrayHasKey('sizes_synced', $result->meta);
            $this->assertSame(2, $result->meta['sizes_synced']);
        } finally {
            @unlink($mainFile);
            @unlink($thumbnailFile);
            @unlink($mediumFile);
            @rmdir($baseDir);
        }
    }

    #[Test]
    public function it_continues_syncing_when_some_sizes_fail(): void
    {
        $attachmentId = 123;
        $baseDir = sys_get_temp_dir() . '/test-uploads-' . uniqid();
        mkdir($baseDir, 0755, true);

        $mainFile = $baseDir . '/image.jpg';
        $thumbnailFile = $baseDir . '/image-150x150.jpg';

        file_put_contents($mainFile, 'main image');
        file_put_contents($thumbnailFile, 'thumbnail');

        try {
            Functions\when('get_post')
                ->justReturn($this->createAttachmentPost($attachmentId, 'image/jpeg'));

            Functions\when('get_attached_file')
                ->justReturn($mainFile);

            Functions\when('wp_get_attachment_url')
                ->justReturn('https://example.com/wp-content/uploads/2024/01/image.jpg');

            Functions\when('wp_parse_url')
                ->alias('parse_url');

            Functions\when('wp_get_attachment_metadata')
                ->justReturn([
                    'sizes' => [
                        'thumbnail' => [
                            'file' => 'image-150x150.jpg',
                        ],
                        'medium' => [
                            'file' => 'image-300x200.jpg', // This file doesn't exist
                        ],
                    ],
                ]);

            // Main file succeeds, thumbnail succeeds, medium is skipped (file missing)
            $this->api->shouldReceive('uploadFile')
                ->times(2)
                ->andReturn(ApiResponse::success(['quant_revision' => 1]));

            $result = $this->mediaSync->sync($attachmentId);

            $this->assertTrue($result->success);
            $this->assertArrayHasKey('sizes_synced', $result->meta);
            $this->assertSame(1, $result->meta['sizes_synced']);
        } finally {
            @unlink($mainFile);
            @unlink($thumbnailFile);
            @rmdir($baseDir);
        }
    }

    #[Test]
    public function it_syncs_pdf_without_image_sizes(): void
    {
        $attachmentId = 123;
        $localPath = sys_get_temp_dir() . '/test-document-' . uniqid() . '.pdf';
        file_put_contents($localPath, 'fake pdf content');

        try {
            Functions\when('get_post')
                ->justReturn($this->createAttachmentPost($attachmentId, 'application/pdf'));

            Functions\when('get_attached_file')
                ->justReturn($localPath);

            Functions\when('wp_get_attachment_url')
                ->justReturn('https://example.com/wp-content/uploads/2024/01/document.pdf');

            Functions\when('wp_parse_url')
                ->alias('parse_url');

            Functions\when('wp_get_attachment_metadata')
                ->justReturn([]);

            $this->api->shouldReceive('uploadFile')
                ->once()
                ->andReturn(ApiResponse::success(['quant_revision' => 1]));

            $result = $this->mediaSync->sync($attachmentId);

            $this->assertTrue($result->success);
            $this->assertArrayNotHasKey('sizes_synced', $result->meta);
        } finally {
            @unlink($localPath);
        }
    }

    #[Test]
    public function it_returns_attachment_cdn_path(): void
    {
        $attachmentId = 123;

        Functions\when('wp_get_attachment_url')
            ->justReturn('https://example.com/wp-content/uploads/2024/01/image.jpg');

        Functions\when('wp_parse_url')
            ->alias('parse_url');

        $path = $this->mediaSync->getAttachmentPath($attachmentId);

        $this->assertSame('/wp-content/uploads/2024/01/image.jpg', $path);
    }

    #[Test]
    public function it_returns_null_when_attachment_url_is_false(): void
    {
        $attachmentId = 999;

        Functions\when('wp_get_attachment_url')
            ->justReturn(false);

        $path = $this->mediaSync->getAttachmentPath($attachmentId);

        $this->assertNull($path);
    }

    /**
     * Create a mock WP_Post for an attachment.
     */
    private function createAttachmentPost(int $id, string $mimeType): \WP_Post
    {
        $post = Mockery::mock(\WP_Post::class);
        $post->ID = $id;
        $post->post_type = 'attachment';
        $post->post_status = 'inherit';
        $post->post_mime_type = $mimeType;
        $post->post_title = 'Test Attachment';

        return $post;
    }
}
