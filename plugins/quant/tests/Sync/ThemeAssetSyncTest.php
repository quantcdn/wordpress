<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\ApiResponse;
use Quant\Api\ContentApiInterface;
use Quant\Sync\ThemeAssetSync;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(ThemeAssetSync::class)]
final class ThemeAssetSyncTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private ContentApiInterface&Mockery\MockInterface $api;
    private ThemeAssetSync $sync;
    private string $child;
    private string $parent;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $this->child = sys_get_temp_dir() . '/quant-child-' . uniqid();
        $this->parent = sys_get_temp_dir() . '/quant-parent-' . uniqid();
        mkdir($this->child . '/assets/fonts', 0777, true);
        mkdir($this->child . '/node_modules/pkg', 0777, true);
        mkdir($this->parent . '/js', 0777, true);

        file_put_contents($this->child . '/style.css', 'body{}');
        file_put_contents($this->child . '/functions.php', '<?php');
        file_put_contents($this->child . '/assets/logo.svg', '<svg/>');
        file_put_contents($this->child . '/assets/fonts/a.woff2', 'font');
        file_put_contents($this->child . '/node_modules/pkg/index.js', 'skip');
        file_put_contents($this->parent . '/js/app.js', 'js');
        file_put_contents($this->parent . '/screenshot.png', 'png');

        Functions\when('get_stylesheet_directory')->justReturn($this->child);
        Functions\when('get_stylesheet_directory_uri')->justReturn('https://example.com/wp-content/themes/child/');
        Functions\when('get_template_directory')->justReturn($this->parent);
        Functions\when('get_template_directory_uri')->justReturn('https://example.com/wp-content/themes/parent');

        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->sync = new ThemeAssetSync($this->api);
    }

    protected function tearDown(): void
    {
        foreach ([$this->child, $this->parent] as $dir) {
            $this->removeDirectory($dir);
        }
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_scans_child_and_parent_theme_assets_recursively(): void
    {
        $assets = $this->sync->scanActiveTheme();
        $urls = array_column($assets, 'url');
        sort($urls);

        $this->assertSame([
            '/wp-content/themes/child/assets/fonts/a.woff2',
            '/wp-content/themes/child/assets/logo.svg',
            '/wp-content/themes/child/style.css',
            '/wp-content/themes/parent/js/app.js',
            '/wp-content/themes/parent/screenshot.png',
        ], $urls);

        $byUrl = array_column($assets, 'type', 'url');
        $this->assertSame('stylesheet', $byUrl['/wp-content/themes/child/style.css']);
        $this->assertSame('font', $byUrl['/wp-content/themes/child/assets/fonts/a.woff2']);
        $this->assertSame('image', $byUrl['/wp-content/themes/child/assets/logo.svg']);
        $this->assertSame('script', $byUrl['/wp-content/themes/parent/js/app.js']);
    }

    #[Test]
    public function it_does_not_scan_the_parent_twice_when_there_is_no_child_theme(): void
    {
        Functions\when('get_template_directory')->justReturn($this->child);

        $urls = array_column($this->sync->scanActiveTheme(), 'url');

        $this->assertCount(3, $urls);
        $this->assertNotContains('/wp-content/themes/parent/js/app.js', $urls);
    }

    #[Test]
    public function it_uploads_every_scanned_asset(): void
    {
        $this->api->shouldReceive('uploadFile')->times(5)->andReturn(ApiResponse::success());

        $results = $this->sync->syncActiveTheme();

        $this->assertCount(5, $results);
        $this->assertTrue($results[0]->success);
    }

    #[Test]
    public function it_continues_when_some_uploads_fail(): void
    {
        $this->api->shouldReceive('uploadFile')
            ->andReturnUsing(static fn(string $path): ApiResponse => str_ends_with($path, '.css') ? ApiResponse::error('boom') : ApiResponse::success());

        $failures = array_filter($this->sync->syncActiveTheme(), static fn($r): bool => !$r->success);

        $this->assertCount(1, $failures);
        $this->assertSame('boom', reset($failures)->message);
    }

    #[Test]
    public function it_fails_a_single_asset_that_does_not_exist(): void
    {
        $result = $this->sync->syncAsset($this->child . '/missing.css', '/wp-content/themes/child/missing.css');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('File not found', $result->message);
    }

    #[Test]
    public function it_returns_nothing_for_a_missing_theme_directory(): void
    {
        Functions\when('get_stylesheet_directory')->justReturn('/nope/does-not-exist');
        Functions\when('get_template_directory')->justReturn('/nope/does-not-exist');

        $this->assertSame([], $this->sync->scanActiveTheme());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
