<?php

declare(strict_types=1);

namespace Quant\Tests\Hook;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Api\ApiResponse;
use Quant\Api\ContentApiInterface;
use Quant\Core\Settings;
use Quant\Hook\CachePurgeSubscriber;
use Quant\Tests\Support\WordPressStubs;

#[CoversClass(CachePurgeSubscriber::class)]
final class CachePurgeSubscriberTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private ContentApiInterface&Mockery\MockInterface $api;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        Functions\when('is_post_type_viewable')->alias(static fn(string $t): bool => in_array($t, ['post', 'page', 'book'], true));
        Functions\when('is_taxonomy_viewable')->alias(static fn(string $t): bool => $t !== 'nav_menu');
        Functions\when('get_permalink')->justReturn('https://example.com/blog/hello/');
        Functions\when('get_object_taxonomies')->justReturn(['category', 'nav_menu']);
        Functions\when('get_the_terms')->alias(fn(\WP_Post $p, string $tax) => $tax === 'category' ? [$this->makeTerm(5)] : false);
        Functions\when('get_term_link')->justReturn('https://example.com/category/news/');
        Functions\when('get_post_type_archive_link')->justReturn('https://example.com/blog/');
        Functions\when('get_author_posts_url')->justReturn('https://example.com/author/jane/');

        $this->api = Mockery::mock(ContentApiInterface::class);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    #[Test]
    public function it_purges_in_cdn_only_mode_and_in_static_mode_without_auto_sync(): void
    {
        $this->api->shouldReceive('purge')->twice()->andReturn(ApiResponse::success());
        Actions\expectDone('quant_purged')->twice();

        (new CachePurgeSubscriber($this->api, $this->mockSettings(mode: Settings::MODE_CDN_ONLY)))->onPostSave(1, $this->makePost(1), true);
        (new CachePurgeSubscriber($this->api, $this->mockSettings(sync: ['auto_sync' => false])))->onPostSave(1, $this->makePost(1), true);
    }

    #[Test]
    public function it_stays_quiet_when_static_sync_publishes_or_purge_is_off_or_credentials_are_missing(): void
    {
        $this->api->shouldNotReceive('purge');

        (new CachePurgeSubscriber($this->api, $this->mockSettings()))->onPostSave(1, $this->makePost(1), true);
        (new CachePurgeSubscriber($this->api, $this->mockSettings(sync: ['auto_purge' => false], mode: Settings::MODE_CDN_ONLY)))->onPostSave(1, $this->makePost(1), true);
        (new CachePurgeSubscriber($this->api, $this->mockSettings(credentials: ['token' => ''], mode: Settings::MODE_CDN_ONLY)))->onPostSave(1, $this->makePost(1), true);
    }

    #[Test]
    public function it_purges_the_page_and_every_listing_that_shows_it(): void
    {
        $subscriber = new CachePurgeSubscriber($this->api, $this->mockSettings(mode: Settings::MODE_CDN_ONLY));

        $this->assertSame(
            ['/', '/blog/hello/', '/category/news/', '/blog/', '/author/jane/'],
            $subscriber->pathsForPost($this->makePost(1))
        );
        $this->assertNotContains('/author/jane/', $subscriber->pathsForPost($this->makePost(2, 'page')));
    }

    #[Test]
    public function it_ignores_drafts_and_hidden_post_types(): void
    {
        $this->api->shouldNotReceive('purge');
        $subscriber = new CachePurgeSubscriber($this->api, $this->mockSettings(mode: Settings::MODE_CDN_ONLY));

        $subscriber->onPostSave(1, $this->makePost(1, 'post', 'draft'), true);
        $subscriber->onPostSave(2, $this->makePost(2, 'revision'), true);
        $subscriber->onPostSave(3, $this->makePost(3, 'hidden_type'), true);
    }

    #[Test]
    public function it_purges_on_trash_and_term_changes(): void
    {
        $subscriber = new CachePurgeSubscriber($this->api, $this->mockSettings(mode: Settings::MODE_CDN_ONLY));
        Functions\when('get_post')->justReturn($this->makePost(1));

        $this->api->shouldReceive('purge')->once()->with(Mockery::on(static fn(array $p): bool => in_array('/blog/hello/', $p, true)))->andReturn(ApiResponse::success());
        $subscriber->onPostTrash(1);

        $this->api->shouldReceive('purge')->twice()->with(['/category/news/'])->andReturn(ApiResponse::success());
        $subscriber->onTermEdit(5, 50, 'category');
        $subscriber->onTermDeletePre(5, 'category');
    }
}
