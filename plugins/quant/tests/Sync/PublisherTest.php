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
use Quant\Sync\Publisher;
use Quant\Sync\Renderer\RenderedContent;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use Quant\Tests\Support\WordPressStubs;
use RuntimeException;

#[CoversClass(Publisher::class)]
final class PublisherTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private ContentApiInterface&Mockery\MockInterface $api;
    private SyncQueueInterface&Mockery\MockInterface $queue;
    private AttachmentUploader&Mockery\MockInterface $attachments;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $this->api = Mockery::mock(ContentApiInterface::class);
        $this->queue = Mockery::mock(SyncQueueInterface::class);
        $this->attachments = Mockery::mock(AttachmentUploader::class);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function publisher(array $static = []): Publisher
    {
        return new Publisher($this->api, $this->mockSettings(static: $static), $this->queue, $this->attachments);
    }

    #[Test]
    public function it_publishes_uploads_attachments_and_queues_next_pages(): void
    {
        $content = new RenderedContent('/blog', '<link rel="next" href="https://example.com/blog/page/2/">', ['source' => 'wordpress'], ['published' => true]);
        $attachments = ['js' => [['path' => '/a.js']]];

        $this->api->shouldReceive('publish')->once()->with('/blog', $content->markup, ['source' => 'wordpress'], ['published' => true])
            ->andReturn(ApiResponse::success(['attachments' => $attachments]));
        $this->attachments->shouldReceive('upload')->once()->with($attachments)->andReturn(['uploaded' => 1, 'skipped' => 0, 'errors' => []]);
        $this->queue->shouldReceive('hasRecentJob')->once()->with(SyncType::Route, '/blog/page/2', SyncAction::Publish, 600)->andReturn(false);
        $this->queue->shouldReceive('push')->once()->with(Mockery::on(static fn(SyncJob $job): bool =>
            $job->type === SyncType::Route && $job->objectId === '/blog/page/2' && $job->meta === ['discovered_from' => '/blog']));
        $this->api->shouldReceive('purge')->once()->with(['/blog'])->andReturn(ApiResponse::success());

        $this->publisher()->publish($content);
    }

    #[Test]
    public function it_uploads_non_html_routes_with_an_extension_as_files(): void
    {
        $content = new RenderedContent('/wp-sitemap.xml', '<?xml version="1.0"?><urlset/>', [], ['headers' => ['content-type' => 'application/xml']]);

        $this->api->shouldReceive('uploadContents')->once()->with('/wp-sitemap.xml', $content->markup)->andReturn(ApiResponse::success());
        $this->api->shouldReceive('purge')->once()->with(['/wp-sitemap.xml'])->andReturn(ApiResponse::success());
        $this->api->shouldNotReceive('publish');

        $this->publisher()->publish($content);
    }

    #[Test]
    public function it_publishes_non_html_routes_without_an_extension_as_markup(): void
    {
        $content = new RenderedContent('/feed', '<rss/>', [], ['headers' => ['content-type' => 'application/rss+xml']]);

        $this->api->shouldReceive('publish')->once()->andReturn(ApiResponse::success([]));
        $this->api->shouldReceive('purge')->once()->andReturn(ApiResponse::success());
        $this->api->shouldNotReceive('uploadContents');

        $this->publisher(['follow_links' => false])->publish($content);
    }

    #[Test]
    public function it_fails_when_a_file_route_upload_fails(): void
    {
        $content = new RenderedContent('/robots.txt', 'User-agent: *', [], ['headers' => ['content-type' => 'text/plain']]);
        $this->api->shouldReceive('uploadContents')->once()->andReturn(ApiResponse::error('Bad gateway', 502));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API error uploading /robots.txt: Bad gateway');

        $this->publisher()->publish($content);
    }

    #[Test]
    public function it_skips_recently_queued_pages_and_itself(): void
    {
        $content = new RenderedContent('/blog/page/2', '<a class="page-numbers" href="/blog/page/2/">2</a><a class="page-numbers" href="/blog/page/3/">3</a>');
        $this->api->shouldReceive('publish')->once()->andReturn(ApiResponse::success());
        $this->queue->shouldReceive('hasRecentJob')->once()->with(SyncType::Route, '/blog/page/3', SyncAction::Publish, 600)->andReturn(true);
        $this->queue->shouldNotReceive('push');
        $this->api->shouldReceive('purge')->once()->andReturn(ApiResponse::success());

        $this->publisher()->publish($content);
    }

    #[Test]
    public function it_does_not_follow_links_when_disabled(): void
    {
        $content = new RenderedContent('/blog', '<link rel="next" href="/blog/page/2/">');
        $this->api->shouldReceive('publish')->once()->andReturn(ApiResponse::success());
        $this->queue->shouldNotReceive('hasRecentJob');
        $this->api->shouldReceive('purge')->once()->andReturn(ApiResponse::success());

        $this->publisher(['follow_links' => false])->publish($content);
    }

    #[Test]
    public function it_throws_when_the_api_rejects_the_content(): void
    {
        $this->api->shouldReceive('publish')->once()->andReturn(ApiResponse::error('Invalid credentials', 403));
        $this->api->shouldNotReceive('purge');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API error publishing /x: Invalid credentials');

        $this->publisher()->publish(new RenderedContent('/x', 'y'));
    }

    #[Test]
    public function it_unpublishes_each_path_once_and_aggregates_errors(): void
    {
        $this->api->shouldReceive('unpublish')->once()->with('/a')->andReturn(ApiResponse::success());
        $this->api->shouldReceive('unpublish')->once()->with('/b')->andReturn(ApiResponse::error('nope'));
        $this->api->shouldNotReceive('purge');

        try {
            $this->publisher()->unpublish(['/a', '/b', '/a']);
            $this->fail('Expected an exception');
        } catch (RuntimeException $e) {
            $this->assertSame('API error unpublishing: /b: nope', $e->getMessage());
        }
    }

    #[Test]
    public function it_purges_both_slash_variants_after_a_publish_even_if_the_purge_fails(): void
    {
        $this->api->shouldReceive('publish')->once()->andReturn(ApiResponse::success());
        // The API client expands /about into /about and /about/.
        $this->api->shouldReceive('purge')->once()->with(['/about'])->andReturn(ApiResponse::error('purge down', 500));

        $this->publisher(['follow_links' => false])->publish(new RenderedContent('/about', '<p>x</p>'));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_purges_after_unpublishing(): void
    {
        $this->api->shouldReceive('unpublish')->twice()->andReturn(ApiResponse::success());
        $this->api->shouldReceive('purge')->once()->with(['/a', '/b'])->andReturn(ApiResponse::success());

        $this->publisher()->unpublish(['/a', '/b', '/a']);
    }

    #[Test]
    public function it_skips_the_purge_when_the_filter_says_so(): void
    {
        \Brain\Monkey\Filters\expectApplied('quant_purge_after_publish')->twice()->andReturn(false);
        $this->api->shouldReceive('publish')->once()->andReturn(ApiResponse::success());
        $this->api->shouldReceive('unpublish')->once()->andReturn(ApiResponse::success());
        $this->api->shouldNotReceive('purge');

        $publisher = $this->publisher(['follow_links' => false]);
        $publisher->publish(new RenderedContent('/about', '<p>x</p>'));
        $publisher->unpublish(['/about']);
    }
}
