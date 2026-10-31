<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\ApiResponse;
use Quant\Api\ContentApiInterface;
use Quant\Sync\AttachmentUploader;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(AttachmentUploader::class)]
final class AttachmentUploaderTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private ContentApiInterface&Mockery\MockInterface $api;
    private AttachmentUploader $uploader;
    private string $dir;
    private string $relative;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $this->dir = ABSPATH . 'wp-content/att-' . uniqid();
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/app.js', 'console.log(1)');
        file_put_contents($this->dir . '/style.css', 'a{}');
        $this->relative = substr($this->dir, strlen(rtrim(ABSPATH, '/')));

        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->uploader = new AttachmentUploader($this->api);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_flattens_every_attachment_group(): void
    {
        $items = $this->uploader->flatten([
            'js' => [['path' => '/a.js']],
            'css' => [['path' => '/b.css'], 'not-an-array'],
            'media' => ['images' => [['path' => '/c.png']], 'documents' => [['path' => '/d.pdf']], 'video' => [], 'audio' => [['path' => '/e.mp3']]],
            'fonts' => [['path' => '/f.woff']],
            'forms' => [['path' => '/ignored']],
        ]);

        $this->assertSame(['/a.js', '/b.css', '/c.png', '/d.pdf', '/e.mp3', '/f.woff'], array_column($items, 'path'));
    }

    #[Test]
    public function it_uploads_local_files_that_changed_and_skips_the_rest(): void
    {
        $this->api->shouldReceive('uploadFile')->once()
            ->with($this->relative . '/app.js', $this->dir . '/app.js')
            ->andReturn(ApiResponse::success());

        $summary = $this->uploader->upload([
            'js' => [
                ['path' => $this->relative . '/app.js?ver=6.5', 'original_path' => 'https://example.com' . $this->relative . '/app.js?ver=6.5'],
                ['path' => $this->relative . '/style.css', 'original_path' => $this->relative . '/style.css', 'existing_md5' => md5('a{}')],
                ['path' => '/wp-includes/missing.js', 'original_path' => '/wp-includes/missing.js'],
                ['path' => '/cdn.js', 'original_path' => 'https://cdn.other.com/cdn.js'],
                ['path' => '/css', 'original_path' => '/css'],
                ['path' => '//evil.com/x.js', 'original_path' => '//evil.com/x.js'],
            ],
        ]);

        $this->assertSame(1, $summary['uploaded']);
        $this->assertSame(5, $summary['skipped']);
        $this->assertSame([], $summary['errors']);
    }

    #[Test]
    public function it_does_not_upload_the_same_file_twice_in_one_process(): void
    {
        $this->api->shouldReceive('uploadFile')->once()->andReturn(ApiResponse::success());

        $item = ['path' => $this->relative . '/app.js', 'original_path' => $this->relative . '/app.js'];
        $this->uploader->upload(['js' => [$item]]);
        $summary = $this->uploader->upload(['js' => [$item]]);

        $this->assertSame(0, $summary['uploaded']);
        $this->assertSame(1, $summary['skipped']);
    }

    #[Test]
    public function it_collects_upload_errors(): void
    {
        $this->api->shouldReceive('uploadFile')->once()->andReturn(ApiResponse::error('quota exceeded'));

        $summary = $this->uploader->upload(['css' => [['path' => $this->relative . '/style.css', 'original_path' => $this->relative . '/style.css']]]);

        $this->assertSame(0, $summary['uploaded']);
        $this->assertSame([$this->relative . '/style.css: quota exceeded'], $summary['errors']);
    }
}
