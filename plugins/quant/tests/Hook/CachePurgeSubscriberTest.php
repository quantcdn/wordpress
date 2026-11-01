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
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('wp_is_post_revision')->justReturn(false);

        $this->api = Mockery::mock(ContentApiInterface::class);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function cdnOnly(): CachePurgeSubscriber
    {
        return new CachePurgeSubscriber($this->api, $this->mockSettings(mode: Settings::MODE_CDN_ONLY));
    }

    #[Test]
    public function it_registers_the_hooks_it_needs(): void
    {
        foreach (['pre_post_update', 'wp_after_insert_post', 'transition_post_status', 'before_delete_post', 'edited_term', 'pre_delete_term'] as $hook) {
            Actions\expectAdded($hook)->once();
        }
        Actions\expectAdded('save_post')->never();

        $this->cdnOnly()->register();
    }

    #[Test]
    public function it_purges_in_cdn_only_mode_and_in_static_mode_without_auto_sync(): void
    {
        $this->api->shouldReceive('purge')->twice()->andReturn(ApiResponse::success());
        Actions\expectDone('quant_purged')->twice();

        foreach ([$this->cdnOnly(), new CachePurgeSubscriber($this->api, $this->mockSettings(sync: ['auto_sync' => false]))] as $subscriber) {
            $subscriber->onAfterInsertPost(1, $this->makePost(1));
            $subscriber->flush();
        }
    }

    #[Test]
    public function it_collects_paths_and_purges_once_at_shutdown(): void
    {
        Actions\expectAdded('shutdown')->once();
        $subscriber = $this->cdnOnly();

        // The block editor with meta boxes saves twice; the purge happens once.
        $subscriber->onAfterInsertPost(1, $this->makePost(1));
        $subscriber->onAfterInsertPost(1, $this->makePost(1));
        $subscriber->onTermEdit(5, 50, 'category');

        $this->api->shouldReceive('purge')->once()->with(['/', '/blog/hello/', '/category/news/', '/blog/', '/author/jane/'])->andReturn(ApiResponse::success());
        $subscriber->flush();

        // Nothing left to purge.
        $subscriber->flush();
    }

    #[Test]
    public function it_stays_quiet_when_static_sync_publishes_or_purge_is_off_or_credentials_are_missing(): void
    {
        $this->api->shouldNotReceive('purge');

        foreach ([
            new CachePurgeSubscriber($this->api, $this->mockSettings()),
            new CachePurgeSubscriber($this->api, $this->mockSettings(sync: ['auto_purge' => false], mode: Settings::MODE_CDN_ONLY)),
            new CachePurgeSubscriber($this->api, $this->mockSettings(credentials: ['token' => ''], mode: Settings::MODE_CDN_ONLY)),
        ] as $subscriber) {
            $subscriber->onAfterInsertPost(1, $this->makePost(1));
            $subscriber->flush();
        }
    }

    #[Test]
    public function it_purges_the_page_and_every_listing_that_shows_it(): void
    {
        $subscriber = $this->cdnOnly();

        $this->assertSame(
            ['/', '/blog/hello/', '/category/news/', '/blog/', '/author/jane/'],
            $subscriber->pathsForPost($this->makePost(1))
        );
        $this->assertNotContains('/author/jane/', $subscriber->pathsForPost($this->makePost(2, 'page')));
    }

    #[Test]
    public function it_strips_the_trashed_suffix_from_the_permalink(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.com/blog/hello__trashed/');

        $this->assertContains('/blog/hello', $this->cdnOnly()->pathsForPost($this->makePost(1)));
    }

    #[Test]
    public function it_ignores_drafts_revisions_autosaves_and_hidden_post_types(): void
    {
        $this->api->shouldNotReceive('purge');
        $subscriber = $this->cdnOnly();

        $subscriber->onAfterInsertPost(1, $this->makePost(1, 'post', 'draft'));
        $subscriber->onAfterInsertPost(2, $this->makePost(2, 'revision'));
        $subscriber->onAfterInsertPost(3, $this->makePost(3, 'hidden_type'));
        Functions\when('wp_is_post_autosave')->justReturn(true);
        $subscriber->onAfterInsertPost(4, $this->makePost(4));
        $subscriber->flush();
    }

    #[Test]
    public function it_purges_the_old_paths_when_a_published_post_is_unpublished(): void
    {
        $subscriber = $this->cdnOnly();
        Functions\when('get_post')->justReturn($this->makePost(1));

        // Captured before the update, while the post still had its public permalink.
        $subscriber->onPrePostUpdate(1);
        Functions\when('get_permalink')->justReturn('https://example.com/?p=1');

        foreach (['draft', 'private', 'pending', 'trash'] as $status) {
            $subscriber->onTransition($status, 'publish', $this->makePost(1, 'post', $status));
        }

        $this->api->shouldReceive('purge')->once()->with(Mockery::on(static fn(array $p): bool => in_array('/blog/hello/', $p, true)))->andReturn(ApiResponse::success());
        $subscriber->flush();
    }

    #[Test]
    public function it_ignores_transitions_that_do_not_leave_publish(): void
    {
        $this->api->shouldNotReceive('purge');
        $subscriber = $this->cdnOnly();

        $subscriber->onTransition('publish', 'publish', $this->makePost(1));
        $subscriber->onTransition('publish', 'draft', $this->makePost(1));
        $subscriber->onTransition('draft', 'pending', $this->makePost(1));
        $subscriber->flush();
    }

    #[Test]
    public function it_purges_the_old_slug_and_terms_after_an_update(): void
    {
        $subscriber = $this->cdnOnly();
        Functions\when('get_post')->justReturn($this->makePost(1));
        $subscriber->onPrePostUpdate(1);

        Functions\when('get_permalink')->justReturn('https://example.com/blog/renamed/');
        Functions\when('get_term_link')->justReturn('https://example.com/category/tech/');
        $subscriber->onAfterInsertPost(1, $this->makePost(1));

        $this->api->shouldReceive('purge')->once()->with(Mockery::on(static function (array $p): bool {
            return in_array('/blog/hello/', $p, true)
                && in_array('/blog/renamed/', $p, true)
                && in_array('/category/news/', $p, true)
                && in_array('/category/tech/', $p, true);
        }))->andReturn(ApiResponse::success());
        $subscriber->flush();
    }

    #[Test]
    public function it_purges_on_delete_and_term_changes(): void
    {
        $subscriber = $this->cdnOnly();
        Functions\when('get_post')->justReturn($this->makePost(1));

        $subscriber->onPostDelete(1);
        $subscriber->onTermEdit(5, 50, 'category');
        $subscriber->onTermDeletePre(5, 'category');

        $this->api->shouldReceive('purge')->once()->with(Mockery::on(static fn(array $p): bool => in_array('/blog/hello/', $p, true) && in_array('/category/news/', $p, true)))->andReturn(ApiResponse::success());
        $subscriber->flush();
    }

    #[Test]
    public function it_does_not_purge_a_deleted_draft(): void
    {
        $this->api->shouldNotReceive('purge');
        $subscriber = $this->cdnOnly();
        Functions\when('get_post')->justReturn($this->makePost(1, 'post', 'draft'));

        $subscriber->onPostDelete(1);
        $subscriber->flush();
    }
}
