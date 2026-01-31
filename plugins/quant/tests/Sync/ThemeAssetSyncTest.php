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
use Quant\Sync\ThemeAssetSync;
use Quant\Sync\FileSyncResult;

#[CoversClass(ThemeAssetSync::class)]
final class ThemeAssetSyncTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private ContentApiInterface $api;
    private ThemeAssetSync $themeAssetSync;
    private string $testThemeDir;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->themeAssetSync = new ThemeAssetSync($this->api);

        // Create a temporary theme directory for testing
        $this->testThemeDir = sys_get_temp_dir() . '/test-theme-' . uniqid();
        mkdir($this->testThemeDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up test theme directory
        $this->removeDirectory($this->testThemeDir);

        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_syncs_a_single_asset_successfully(): void
    {
        $localPath = $this->testThemeDir . '/style.css';
        $cdnPath = '/wp-content/themes/my-theme/style.css';

        file_put_contents($localPath, 'body { color: red; }');

        $this->api->shouldReceive('uploadFile')
            ->once()
            ->with($cdnPath, $localPath)
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $result = $this->themeAssetSync->syncAsset($localPath, $cdnPath);

        $this->assertInstanceOf(FileSyncResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame($cdnPath, $result->path);
    }

    #[Test]
    public function it_returns_failure_when_local_file_does_not_exist(): void
    {
        $localPath = $this->testThemeDir . '/nonexistent.css';
        $cdnPath = '/wp-content/themes/my-theme/nonexistent.css';

        $result = $this->themeAssetSync->syncAsset($localPath, $cdnPath);

        $this->assertInstanceOf(FileSyncResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('File not found', $result->message);
    }

    #[Test]
    public function it_returns_failure_when_api_upload_fails(): void
    {
        $localPath = $this->testThemeDir . '/style.css';
        $cdnPath = '/wp-content/themes/my-theme/style.css';

        file_put_contents($localPath, 'body { color: red; }');

        $this->api->shouldReceive('uploadFile')
            ->once()
            ->andReturn(ApiResponse::error('Upload failed', 500));

        $result = $this->themeAssetSync->syncAsset($localPath, $cdnPath);

        $this->assertInstanceOf(FileSyncResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('Upload failed', $result->message);
    }

    #[Test]
    public function it_syncs_all_assets_from_active_theme(): void
    {
        // Create test assets
        mkdir($this->testThemeDir . '/css', 0755, true);
        mkdir($this->testThemeDir . '/js', 0755, true);
        mkdir($this->testThemeDir . '/images', 0755, true);

        file_put_contents($this->testThemeDir . '/style.css', 'body {}');
        file_put_contents($this->testThemeDir . '/css/main.css', 'h1 {}');
        file_put_contents($this->testThemeDir . '/js/app.js', 'console.log("hi");');
        file_put_contents($this->testThemeDir . '/images/logo.png', 'fake png');

        Functions\when('get_stylesheet_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_template_directory')
            ->justReturn($this->testThemeDir); // Same directory (not a child theme)

        Functions\when('get_stylesheet_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        Functions\when('get_template_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        // Expect 4 file uploads
        $this->api->shouldReceive('uploadFile')
            ->times(4)
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $results = $this->themeAssetSync->syncActiveTheme();

        $this->assertIsArray($results);
        $this->assertCount(4, $results);

        foreach ($results as $result) {
            $this->assertInstanceOf(FileSyncResult::class, $result);
            $this->assertTrue($result->success);
        }
    }

    #[Test]
    public function it_syncs_assets_from_both_child_and_parent_themes(): void
    {
        $parentThemeDir = sys_get_temp_dir() . '/test-parent-theme-' . uniqid();
        mkdir($parentThemeDir, 0755, true);

        try {
            // Child theme assets
            file_put_contents($this->testThemeDir . '/style.css', 'child styles');

            // Parent theme assets
            file_put_contents($parentThemeDir . '/style.css', 'parent styles');
            file_put_contents($parentThemeDir . '/script.js', 'parent script');

            Functions\when('get_stylesheet_directory')
                ->justReturn($this->testThemeDir);

            Functions\when('get_template_directory')
                ->justReturn($parentThemeDir);

            Functions\when('get_stylesheet_directory_uri')
                ->justReturn('https://example.com/wp-content/themes/child-theme');

            Functions\when('get_template_directory_uri')
                ->justReturn('https://example.com/wp-content/themes/parent-theme');

            // Expect 3 file uploads (1 child + 2 parent)
            $this->api->shouldReceive('uploadFile')
                ->times(3)
                ->andReturn(ApiResponse::success(['quant_revision' => 1]));

            $results = $this->themeAssetSync->syncActiveTheme();

            $this->assertIsArray($results);
            $this->assertCount(3, $results);
        } finally {
            $this->removeDirectory($parentThemeDir);
        }
    }

    #[Test]
    public function it_includes_fonts_in_sync(): void
    {
        mkdir($this->testThemeDir . '/fonts', 0755, true);
        file_put_contents($this->testThemeDir . '/fonts/roboto.woff2', 'font data');
        file_put_contents($this->testThemeDir . '/fonts/roboto.woff', 'font data');
        file_put_contents($this->testThemeDir . '/fonts/roboto.ttf', 'font data');

        Functions\when('get_stylesheet_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_template_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_stylesheet_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        Functions\when('get_template_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        $this->api->shouldReceive('uploadFile')
            ->times(3)
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $results = $this->themeAssetSync->syncActiveTheme();

        $this->assertCount(3, $results);
    }

    #[Test]
    public function it_handles_nested_directories(): void
    {
        mkdir($this->testThemeDir . '/assets/css', 0755, true);
        mkdir($this->testThemeDir . '/assets/js/vendor', 0755, true);

        file_put_contents($this->testThemeDir . '/assets/css/main.css', 'css');
        file_put_contents($this->testThemeDir . '/assets/js/app.js', 'js');
        file_put_contents($this->testThemeDir . '/assets/js/vendor/jquery.js', 'jquery');

        Functions\when('get_stylesheet_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_template_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_stylesheet_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        Functions\when('get_template_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        $this->api->shouldReceive('uploadFile')
            ->times(3)
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $results = $this->themeAssetSync->syncActiveTheme();

        $this->assertCount(3, $results);

        // Verify paths are correctly nested
        $paths = array_map(fn($r) => $r->path, $results);
        $this->assertContains('/wp-content/themes/my-theme/assets/css/main.css', $paths);
        $this->assertContains('/wp-content/themes/my-theme/assets/js/app.js', $paths);
        $this->assertContains('/wp-content/themes/my-theme/assets/js/vendor/jquery.js', $paths);
    }

    #[Test]
    public function it_skips_php_files(): void
    {
        file_put_contents($this->testThemeDir . '/style.css', 'css');
        file_put_contents($this->testThemeDir . '/functions.php', '<?php // code');
        file_put_contents($this->testThemeDir . '/header.php', '<?php // code');

        Functions\when('get_stylesheet_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_template_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_stylesheet_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        Functions\when('get_template_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        // Only 1 CSS file, PHP files should be skipped
        $this->api->shouldReceive('uploadFile')
            ->once()
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $results = $this->themeAssetSync->syncActiveTheme();

        $this->assertCount(1, $results);
    }

    #[Test]
    public function it_includes_svg_files(): void
    {
        mkdir($this->testThemeDir . '/images', 0755, true);
        file_put_contents($this->testThemeDir . '/images/icon.svg', '<svg></svg>');

        Functions\when('get_stylesheet_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_template_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_stylesheet_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        Functions\when('get_template_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        $this->api->shouldReceive('uploadFile')
            ->once()
            ->andReturn(ApiResponse::success(['quant_revision' => 1]));

        $results = $this->themeAssetSync->syncActiveTheme();

        $this->assertCount(1, $results);
        $this->assertStringContainsString('.svg', $results[0]->path);
    }

    #[Test]
    public function it_returns_empty_array_when_no_assets_found(): void
    {
        // Empty theme directory (no static assets)
        Functions\when('get_stylesheet_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_template_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_stylesheet_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        Functions\when('get_template_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        $results = $this->themeAssetSync->syncActiveTheme();

        $this->assertIsArray($results);
        $this->assertCount(0, $results);
    }

    #[Test]
    public function it_continues_syncing_when_some_assets_fail(): void
    {
        file_put_contents($this->testThemeDir . '/style.css', 'css');
        file_put_contents($this->testThemeDir . '/app.js', 'js');

        Functions\when('get_stylesheet_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_template_directory')
            ->justReturn($this->testThemeDir);

        Functions\when('get_stylesheet_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        Functions\when('get_template_directory_uri')
            ->justReturn('https://example.com/wp-content/themes/my-theme');

        // First succeeds, second fails
        $this->api->shouldReceive('uploadFile')
            ->twice()
            ->andReturn(
                ApiResponse::success(['quant_revision' => 1]),
                ApiResponse::error('Upload failed', 500)
            );

        $results = $this->themeAssetSync->syncActiveTheme();

        $this->assertCount(2, $results);

        $successCount = count(array_filter($results, fn($r) => $r->success));
        $failureCount = count(array_filter($results, fn($r) => !$r->success));

        $this->assertSame(1, $successCount);
        $this->assertSame(1, $failureCount);
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
