<?php

declare(strict_types=1);

namespace Quant\Tests\Hook;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Core\Settings;
use Quant\Hook\ContentSyncSubscriber;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use Quant\Tests\Support\WordPressStubs;
use WP_Post_Type;
use WP_Taxonomy;

#[CoversClass(ContentSyncSubscriber::class)]
final class ContentSyncSubscriberTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    /** @var array<SyncJob> */
    private array $pushed = [];
    private SyncQueueInterface&Mockery\MockInterface $queue;
    private Settings&Mockery\MockInterface $settings;
    private ContentSyncSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();

        $GLOBALS['wp_rewrite'] = new class {
            public string $pagination_base = 'page';
            public function using_permalinks(): bool { return true; }
        };

        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('get_post_type_object')->alias(static fn(string $t) => match ($t) {
            'post', 'page', 'book' => new WP_Post_Type($t),
            'secret' => new WP_Post_Type($t, false),
            default => in_array($t, ContentSyncSubscriber::IGNORED_POST_TYPES, true) ? new WP_Post_Type($t, false) : null,
        });
        Functions\when('taxonomy_exists')->alias(static fn(string $t): bool => in_array($t, ['category', 'post_tag', 'genre', 'nav_menu'], true));
        Functions\when('is_taxonomy_viewable')->alias(static fn(string $t): bool => $t !== 'nav_menu');
        Functions\when('get_object_taxonomies')->justReturn([]);
        Functions\when('get_the_terms')->justReturn(false);
        Functions\when('get_post_type_archive_link')->justReturn(false);
        Functions\when('get_author_posts_url')->alias(static fn(int $id): string => "https://example.com/author/user-{$id}/");
        Functions\when('get_option')->alias(static fn(string $k, $d = false) => $k === 'posts_per_page' ? 10 : $d);

        $this->queue = Mockery::mock(SyncQueueInterface::class);
        $this->queue->shouldReceive('push')->andReturnUsing(function (SyncJob $job): void {
            $this->pushed[] = $job;
        })->byDefault();
        $this->queue->shouldReceive('pushBatch')->andReturnUsing(function (array $jobs): void {
            foreach ($jobs as $job) {
                $this->pushed[] = $job;
            }
        })->byDefault();

        $this->settings = $this->mockSettings();
        $this->subscriber = new ContentSyncSubscriber($this->queue, $this->settings);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_rewrite']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stub get_object_taxonomies() for both the 'names' and 'objects' forms.
     *
     * @param array<string> $names
     */
    private function taxonomies(array $names): void
    {
        Functions\when('get_object_taxonomies')->alias(static fn($post, string $output = 'names'): array => $output === 'names'
            ? $names
            : array_map(static fn(string $n): WP_Taxonomy => new WP_Taxonomy($n), $names));
    }

    #[Test]
    public function it_registers_the_hooks_it_needs(): void
    {
        $hooks = [];
        Functions\when('add_action')->alias(static function (string $hook, $cb, $priority = 10, $args = 1) use (&$hooks): void {
            $hooks[$hook] = [$priority, $args];
        });

        $this->subscriber->register();

        $this->assertSame([1000, 2], $hooks['wp_after_insert_post']);
        $this->assertArrayNotHasKey('save_post', $hooks);
        $this->assertSame([10, 2], $hooks['pre_post_update']);
        $this->assertArrayHasKey('wp_trash_post', $hooks);
        $this->assertSame([10, 2], $hooks['delete_post']);
        $this->assertSame([10, 3], $hooks['transition_post_status']);
        $this->assertSame([10, 3], $hooks['created_term']);
        $this->assertSame([10, 3], $hooks['edited_term']);
        $this->assertSame([10, 2], $hooks['pre_delete_term']);
        $this->assertSame([10, 4], $hooks['delete_term']);
        $this->assertArrayHasKey('add_attachment', $hooks);
        $this->assertArrayHasKey('edit_attachment', $hooks);
        $this->assertArrayHasKey('delete_attachment', $hooks);
        $this->assertArrayHasKey('update_option_show_on_front', $hooks);
    }

    #[Test]
    public function it_does_nothing_outside_static_mode_or_with_auto_sync_off(): void
    {
        $post = $this->makePost(1);

        $cdn = new ContentSyncSubscriber($this->queue, $this->mockSettings(mode: Settings::MODE_CDN_ONLY));
        $cdn->onPostSave(1, $post, true);

        $off = new ContentSyncSubscriber($this->queue, $this->mockSettings(sync: ['auto_sync' => false]));
        $off->onPostSave(1, $post, true);

        $this->assertSame([], $this->pushed);
    }

    #[Test]
    public function it_queues_a_published_post_with_its_related_listing_pages(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.com/blog/hello/');
        $this->taxonomies(['category', 'nav_menu']);
        Functions\when('get_the_terms')->justReturn([$this->makeTerm(5, 'category')]);
        Functions\when('get_post_type_archive_link')->justReturn('https://example.com/blog/');

        $this->subscriber->onPostSave(1, $this->makePost(1), true);

        $this->assertJobs([
            ['post', '1', 'publish', ['post_type' => 'post']],
            ['home', '/', 'publish', []],
            ['taxonomy', '5', 'publish', ['taxonomy' => 'category', 'page' => 1]],
            ['archive', '/blog', 'publish', ['post_type' => 'post']],
            ['author', '/author/user-1', 'publish', ['user' => 1]],
        ]);
    }

    #[Test]
    public function it_queues_custom_post_types_and_pages_with_the_right_type(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.com/x/');

        $this->subscriber->onPostSave(2, $this->makePost(2, 'page'), true);
        $this->subscriber->onPostSave(3, $this->makePost(3, 'book'), true);

        $types = array_map(static fn(SyncJob $j): string => $j->type->value . ':' . $j->objectId, $this->pushed);
        $this->assertContains('page:2', $types);
        $this->assertContains('custom_post_type:3', $types);
        $this->assertNotContains('author:/author/user-1', $types, 'Only posts have author archives');
    }

    #[Test]
    public function it_ignores_drafts_revisions_autosaves_and_internal_types(): void
    {
        Functions\when('wp_is_post_autosave')->justReturn(true);
        $this->subscriber->onPostSave(1, $this->makePost(1), true);
        Functions\when('wp_is_post_autosave')->justReturn(false);

        $this->subscriber->onPostSave(2, $this->makePost(2, 'post', 'draft'), true);
        $this->subscriber->onPostSave(3, $this->makePost(3, 'revision'), true);
        $this->subscriber->onPostSave(4, $this->makePost(4, 'nav_menu_item'), true);
        $this->subscriber->onPostSave(5, $this->makePost(5, 'attachment'), true);
        $this->subscriber->onPostSave(6, $this->makePost(6, 'secret'), true);

        $this->assertSame([], $this->pushed);
    }

    #[Test]
    public function it_redirects_the_old_permalink_when_the_slug_changes(): void
    {
        $post = $this->makePost(1, 'post', 'publish', 'new-slug');
        $permalink = 'https://example.com/blog/old-slug/';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->alias(static function () use (&$permalink): string {
            return $permalink;
        });

        $this->subscriber->onPrePostUpdate(1, []);
        $permalink = 'https://example.com/blog/new-slug/';
        $this->subscriber->onPostSave(1, $post, true);

        $redirects = array_values(array_filter($this->pushed, static fn(SyncJob $j): bool => $j->type === SyncType::Redirect));
        $this->assertCount(1, $redirects);
        $this->assertSame('/blog/old-slug', $redirects[0]->objectId);
        $this->assertSame(['to' => '/blog/new-slug', 'code' => 301], $redirects[0]->meta);
    }

    #[Test]
    public function it_does_not_redirect_when_the_permalink_is_unchanged(): void
    {
        Functions\when('get_post')->justReturn($this->makePost(1));
        Functions\when('get_permalink')->justReturn('https://example.com/blog/same/');

        $this->subscriber->onPrePostUpdate(1, []);
        $this->subscriber->onPostSave(1, $this->makePost(1), true);

        $this->assertSame([], array_filter($this->pushed, static fn(SyncJob $j): bool => $j->type === SyncType::Redirect));
    }

    #[Test]
    public function it_unpublishes_with_the_captured_path_when_a_post_is_trashed(): void
    {
        $post = $this->makePost(1);
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/blog/hello/');

        $this->subscriber->onPrePostUpdate(1);
        Functions\when('get_permalink')->justReturn('https://example.com/blog/hello__trashed/');
        $post->post_status = 'trash';
        $this->subscriber->onTransitionPostStatus('trash', 'publish', $post);

        $this->assertSame(SyncType::Post, $this->pushed[0]->type);
        $this->assertSame(SyncAction::Unpublish, $this->pushed[0]->action);
        $this->assertSame('/blog/hello', $this->pushed[0]->meta['url']);
        $this->assertSame('home', $this->pushed[1]->type->value, 'Listing pages are refreshed after an unpublish');
    }

    #[Test]
    public function it_strips_the_trashed_suffix_when_no_path_was_captured(): void
    {
        $post = $this->makePost(1);
        $post->post_status = 'trash';
        Functions\when('get_permalink')->justReturn('https://example.com/blog/hello__trashed-2/');

        $this->subscriber->onTransitionPostStatus('trash', 'publish', $post);

        $this->assertSame('/blog/hello', $this->pushed[0]->meta['url']);
    }

    #[Test]
    public function it_ignores_transitions_that_do_not_leave_publish(): void
    {
        $post = $this->makePost(1, 'post', 'draft');

        $this->subscriber->onTransitionPostStatus('pending', 'draft', $post);
        $this->subscriber->onTransitionPostStatus('publish', 'publish', $post);
        $this->subscriber->onTransitionPostStatus('publish', 'draft', $post);

        $this->assertSame([], $this->pushed, 'Publishing is handled by save_post');
    }

    #[Test]
    public function it_unpublishes_on_permanent_deletion_of_a_published_post_only(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.com/blog/bye/');

        $this->subscriber->onPostDelete(1, $this->makePost(1, 'post', 'trash'));
        $this->subscriber->onPostDelete(2, $this->makePost(2, 'post', 'draft'));
        $this->assertSame([], $this->pushed);

        $this->subscriber->onPostDelete(3, $this->makePost(3));
        $this->assertSame(SyncAction::Unpublish, $this->pushed[0]->action);
        $this->assertSame('/blog/bye', $this->pushed[0]->meta['url']);
    }

    #[Test]
    public function it_queues_term_changes_and_skips_hidden_taxonomies(): void
    {
        $this->subscriber->onTermChanged(5, 50, 'category');
        $this->subscriber->onTermChanged(6, 60, 'nav_menu');
        $this->subscriber->onTermChanged(7, 70, 'missing');

        $this->assertJobs([['taxonomy', '5', 'publish', ['taxonomy' => 'category', 'page' => 1]]]);
    }

    #[Test]
    public function it_captures_every_page_of_a_term_before_deletion(): void
    {
        $term = $this->makeTerm(5, 'category', 'News', 25);
        Functions\when('get_term')->justReturn($term);
        Functions\when('get_term_link')->justReturn('https://example.com/category/news/');

        $this->subscriber->onPreDeleteTerm(5, 'category');
        $this->subscriber->onTermDelete(5, 50, 'category', $term);

        $this->assertJobs([['taxonomy', '5', 'unpublish', [
            'taxonomy' => 'category',
            'urls' => ['/category/news', '/category/news/page/2', '/category/news/page/3'],
        ]]]);
    }

    #[Test]
    public function it_ignores_term_deletion_without_a_term_object(): void
    {
        $this->subscriber->onTermDelete(5, 50, 'category', null);

        $this->assertSame([], $this->pushed);
    }

    #[Test]
    public function it_queues_attachments_and_captures_their_files_on_delete(): void
    {
        Functions\when('wp_get_attachment_url')->justReturn('https://example.com/wp-content/uploads/2026/01/a.jpg');
        Functions\when('wp_get_attachment_metadata')->justReturn(['sizes' => ['thumb' => ['file' => 'a-150x150.jpg'], 'bad' => []]]);

        $this->subscriber->onAttachmentChanged(9);
        $this->subscriber->onAttachmentDelete(9);

        $this->assertJobs([
            ['media', '9', 'publish', []],
            ['media', '9', 'unpublish', ['urls' => ['/wp-content/uploads/2026/01/a.jpg', '/wp-content/uploads/2026/01/a-150x150.jpg']]],
        ]);
    }

    #[Test]
    public function it_requeues_the_home_page_when_the_front_page_settings_change(): void
    {
        $this->subscriber->onFrontPageChanged();

        $this->assertJobs([['home', '/', 'publish', []]]);
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>}> $expected
     */
    private function assertJobs(array $expected): void
    {
        $actual = array_map(static fn(SyncJob $j): array => [$j->type->value, $j->objectId, $j->action->value, $j->meta], $this->pushed);

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function it_handles_wp_after_insert_post_like_a_save(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.com/x/');

        $this->subscriber->onAfterInsertPost(2, $this->makePost(2, 'page'));
        Functions\when('get_post')->justReturn($this->makePost(3, 'page'));
        $this->subscriber->onAfterInsertPost(3);

        $types = array_map(static fn(SyncJob $j): string => $j->type->value . ':' . $j->objectId, $this->pushed);
        $this->assertContains('page:2', $types);
        $this->assertContains('page:3', $types);
    }

    #[Test]
    public function it_refreshes_listings_of_terms_the_post_was_removed_from(): void
    {
        $post = $this->makePost(1);
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/blog/hello/');
        $this->taxonomies(['category']);

        // Before the update the post is in category 5; afterwards in category 6.
        Functions\when('get_the_terms')->justReturn([$this->makeTerm(5, 'category')]);
        $this->subscriber->onPrePostUpdate(1, []);
        Functions\when('get_the_terms')->justReturn([$this->makeTerm(6, 'category')]);
        $this->subscriber->onPostSave(1, $post, true);

        $terms = array_values(array_map(
            static fn(SyncJob $j): string => $j->objectId,
            array_filter($this->pushed, static fn(SyncJob $j): bool => $j->type === SyncType::Taxonomy)
        ));
        sort($terms);
        $this->assertSame(['5', '6'], $terms);
    }

    #[Test]
    public function it_does_not_queue_the_home_page_twice_as_the_post_archive(): void
    {
        Functions\when('get_permalink')->justReturn('https://example.com/hello/');
        Functions\when('get_post_type_archive_link')->justReturn('https://example.com/');

        $this->subscriber->onPostSave(1, $this->makePost(1), true);

        $this->assertSame([], array_filter($this->pushed, static fn(SyncJob $j): bool => $j->type === SyncType::Archive));
        $this->assertCount(1, array_filter($this->pushed, static fn(SyncJob $j): bool => $j->type === SyncType::Home));
    }
}
