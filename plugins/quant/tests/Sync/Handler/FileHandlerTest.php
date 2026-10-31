<?php

declare(strict_types=1);

namespace Quant\Tests\Sync\Handler;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\ApiResponse;
use Quant\Api\ContentApiInterface;
use Quant\Sync\Handler\FileHandler;
use Quant\Sync\MediaSync;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use Quant\Sync\ThemeAssetSync;
use Quant\Tests\Support\WordPressStubs;
use RuntimeException;

#[CoversClass(FileHandler::class)]
final class FileHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private ContentApiInterface&Mockery\MockInterface $api;
    private FileHandler $handler;
    private string $file;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $this->dir = sys_get_temp_dir() . '/quant-file-handler-' . uniqid();
        mkdir($this->dir);
        $this->file = $this->dir . '/style.css';
        file_put_contents($this->file, 'a{}');

        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->handler = new FileHandler($this->api, new MediaSync($this->api), new ThemeAssetSync($this->api));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @rmdir($this->dir);
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_supports_file_like_publish_jobs(): void
    {
        foreach ([SyncType::Media, SyncType::ThemeAsset, SyncType::ThemeAssets, SyncType::File] as $type) {
            $this->assertTrue($this->handler->supports(new SyncJob($type, '1', SyncAction::Publish)), $type->value);
        }
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Media, '1', SyncAction::Unpublish)));
        $this->assertFalse($this->handler->supports(new SyncJob(SyncType::Post, '1', SyncAction::Publish)));
    }

    #[Test]
    public function it_uploads_a_theme_asset_or_file_from_its_meta(): void
    {
        $this->api->shouldReceive('uploadFile')->once()->with('/wp-content/themes/t/style.css', $this->file)->andReturn(ApiResponse::success());

        $this->handler->handle(new SyncJob(SyncType::ThemeAsset, '/wp-content/themes/t/style.css', SyncAction::Publish, [
            'file' => $this->file,
            'url' => '/wp-content/themes/t/style.css?ver=1',
        ]));
    }

    #[Test]
    public function it_fails_for_a_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found for /missing.css');

        $this->handler->handle(new SyncJob(SyncType::File, '/missing.css', SyncAction::Publish, ['file' => '/nope/missing.css']));
    }

    #[Test]
    public function it_syncs_media_through_media_sync_and_reports_failures(): void
    {
        $attachment = $this->makePost(9, 'attachment');
        $attachment->post_mime_type = 'application/pdf';
        Functions\when('get_post')->justReturn($attachment);
        Functions\when('get_attached_file')->justReturn($this->file);
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/wp-content/uploads/doc.pdf');
        Functions\when('wp_get_attachment_metadata')->justReturn([]);

        $this->api->shouldReceive('uploadFile')->once()->with('/wp-content/uploads/doc.pdf', $this->file)->andReturn(ApiResponse::success());
        $this->handler->handle(new SyncJob(SyncType::Media, '9', SyncAction::Publish));

        $this->api->shouldReceive('uploadFile')->once()->andReturn(ApiResponse::error('too large'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large');
        $this->handler->handle(new SyncJob(SyncType::Media, '9', SyncAction::Publish));
    }

    #[Test]
    public function it_syncs_all_theme_assets_for_the_legacy_job(): void
    {
        Functions\when('get_stylesheet_directory')->justReturn($this->dir);
        Functions\when('get_template_directory')->justReturn($this->dir);
        Functions\when('get_stylesheet_directory_uri')->justReturn('https://example.com/wp-content/themes/t');
        Functions\when('get_template_directory_uri')->justReturn('https://example.com/wp-content/themes/t');

        $this->api->shouldReceive('uploadFile')->atLeast()->once()->andReturn(ApiResponse::success());

        $this->handler->handle(new SyncJob(SyncType::ThemeAssets, 'theme', SyncAction::Publish));
    }
}
