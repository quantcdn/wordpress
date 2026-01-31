<?php

declare(strict_types=1);

namespace Quant\Tests\Hook;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Quant\Core\Settings;
use Quant\Hook\ContentSyncSubscriber;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use WP_Post;
use WP_Term;

#[CoversClass(ContentSyncSubscriber::class)]
final class ContentSyncSubscriberTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private SyncQueueInterface $queue;
    private Settings $settings;
    private ContentSyncSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->queue = Mockery::mock(SyncQueueInterface::class);
        $this->settings = Mockery::mock(Settings::class);
        $this->subscriber = new ContentSyncSubscriber($this->queue, $this->settings);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // =========================================================================
    // Registration Tests
    // =========================================================================

    #[Test]
    public function it_registers_all_required_hooks(): void
    {
        Functions\expect('add_action')
            ->with('save_post', Mockery::type('array'), 10, 3)
            ->once();

        Functions\expect('add_action')
            ->with('delete_post', Mockery::type('array'), 10, 2)
            ->once();

        Functions\expect('add_action')
            ->with('transition_post_status', Mockery::type('array'), 10, 3)
            ->once();

        Functions\expect('add_action')
            ->with('created_term', Mockery::type('array'), 10, 3)
            ->once();

        Functions\expect('add_action')
            ->with('edited_term', Mockery::type('array'), 10, 3)
            ->once();

        Functions\expect('add_action')
            ->with('delete_term', Mockery::type('array'), 10, 4)
            ->once();

        Functions\expect('add_action')
            ->with('add_attachment', Mockery::type('array'), 10, 1)
            ->once();

        Functions\expect('add_action')
            ->with('edit_attachment', Mockery::type('array'), 10, 1)
            ->once();

        Functions\expect('add_action')
            ->with('delete_attachment', Mockery::type('array'), 10, 1)
            ->once();

        $this->subscriber->register();
    }

    // =========================================================================
    // Mode and Settings Check Tests
    // =========================================================================

    #[Test]
    public function it_does_not_queue_when_not_in_static_mode(): void
    {
        $this->settings->shouldReceive('isStaticMode')
            ->andReturn(false);

        $this->queue->shouldNotReceive('push');

        $post = $this->createPost(123, 'post', 'publish');
        $this->subscriber->onPostSave(123, $post, false);
    }

    #[Test]
    public function it_does_not_queue_when_auto_sync_is_disabled(): void
    {
        $this->settings->shouldReceive('isStaticMode')
            ->andReturn(true);
        $this->settings->shouldReceive('getSyncSettings')
            ->andReturn(['auto_sync' => false]);

        $this->queue->shouldNotReceive('push');

        $post = $this->createPost(123, 'post', 'publish');
        $this->subscriber->onPostSave(123, $post, false);
    }

    // =========================================================================
    // Post Hook Tests
    // =========================================================================

    #[Test]
    public function it_queues_published_post_on_save(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::Post
                    && $job->objectId === '123'
                    && $job->action === SyncAction::Publish
                    && $job->meta['post_type'] === 'post';
            }));

        $post = $this->createPost(123, 'post', 'publish');
        $this->subscriber->onPostSave(123, $post, false);
    }

    #[Test]
    public function it_queues_page_with_correct_type(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::Page
                    && $job->objectId === '456'
                    && $job->action === SyncAction::Publish
                    && $job->meta['post_type'] === 'page';
            }));

        $post = $this->createPost(456, 'page', 'publish');
        $this->subscriber->onPostSave(456, $post, false);
    }

    #[Test]
    public function it_queues_custom_post_type_with_correct_type(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::CustomPostType
                    && $job->objectId === '789'
                    && $job->action === SyncAction::Publish
                    && $job->meta['post_type'] === 'product';
            }));

        $post = $this->createPost(789, 'product', 'publish');
        $this->subscriber->onPostSave(789, $post, false);
    }

    #[Test]
    public function it_does_not_queue_draft_post_on_save(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldNotReceive('push');

        $post = $this->createPost(123, 'post', 'draft');
        $this->subscriber->onPostSave(123, $post, false);
    }

    #[Test]
    public function it_skips_revision_post_type(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldNotReceive('push');

        $post = $this->createPost(123, 'revision', 'publish');
        $this->subscriber->onPostSave(123, $post, false);
    }

    #[Test]
    public function it_skips_auto_draft_post_type(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldNotReceive('push');

        $post = $this->createPost(123, 'auto-draft', 'publish');
        $this->subscriber->onPostSave(123, $post, false);
    }

    #[Test]
    public function it_skips_nav_menu_item_post_type(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldNotReceive('push');

        $post = $this->createPost(123, 'nav_menu_item', 'publish');
        $this->subscriber->onPostSave(123, $post, false);
    }

    #[Test]
    public function it_skips_wp_template_post_type(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldNotReceive('push');

        $post = $this->createPost(123, 'wp_template', 'publish');
        $this->subscriber->onPostSave(123, $post, false);
    }

    #[Test]
    public function it_queues_unpublish_on_post_delete(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::Post
                    && $job->objectId === '123'
                    && $job->action === SyncAction::Unpublish;
            }));

        $post = $this->createPost(123, 'post', 'publish');
        $this->subscriber->onPostDelete(123, $post);
    }

    #[Test]
    public function it_does_not_queue_on_delete_for_ignored_post_type(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldNotReceive('push');

        $post = $this->createPost(123, 'revision', 'publish');
        $this->subscriber->onPostDelete(123, $post);
    }

    #[Test]
    public function it_queues_on_transition_from_draft_to_publish(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::Post
                    && $job->objectId === '123'
                    && $job->action === SyncAction::Publish;
            }));

        $post = $this->createPost(123, 'post', 'publish');
        $this->subscriber->onTransitionPostStatus('publish', 'draft', $post);
    }

    #[Test]
    public function it_queues_unpublish_on_transition_from_publish_to_draft(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::Post
                    && $job->objectId === '123'
                    && $job->action === SyncAction::Unpublish;
            }));

        $post = $this->createPost(123, 'post', 'draft');
        $this->subscriber->onTransitionPostStatus('draft', 'publish', $post);
    }

    #[Test]
    public function it_queues_unpublish_on_transition_from_publish_to_trash(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::Post
                    && $job->objectId === '123'
                    && $job->action === SyncAction::Unpublish;
            }));

        $post = $this->createPost(123, 'post', 'trash');
        $this->subscriber->onTransitionPostStatus('trash', 'publish', $post);
    }

    #[Test]
    public function it_does_not_queue_on_transition_between_non_publish_statuses(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldNotReceive('push');

        $post = $this->createPost(123, 'post', 'pending');
        $this->subscriber->onTransitionPostStatus('pending', 'draft', $post);
    }

    #[Test]
    public function it_does_not_queue_on_same_status_transition(): void
    {
        $this->enableAutoSync();

        // When transitioning publish -> publish, don't queue from here
        // (save_post will handle the update)
        $this->queue->shouldNotReceive('push');

        $post = $this->createPost(123, 'post', 'publish');
        $this->subscriber->onTransitionPostStatus('publish', 'publish', $post);
    }

    // =========================================================================
    // Term Hook Tests
    // =========================================================================

    #[Test]
    public function it_queues_new_term_for_sync(): void
    {
        $this->enableAutoSync();

        Functions\when('taxonomy_exists')
            ->justReturn(true);

        Functions\when('is_taxonomy_viewable')
            ->justReturn(true);

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::Taxonomy
                    && $job->objectId === '10'
                    && $job->action === SyncAction::Publish
                    && $job->meta['taxonomy'] === 'category';
            }));

        $this->subscriber->onTermCreated(10, 10, 'category');
    }

    #[Test]
    public function it_queues_edited_term_for_sync(): void
    {
        $this->enableAutoSync();

        Functions\when('taxonomy_exists')
            ->justReturn(true);

        Functions\when('is_taxonomy_viewable')
            ->justReturn(true);

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::Taxonomy
                    && $job->objectId === '10'
                    && $job->action === SyncAction::Publish
                    && $job->meta['taxonomy'] === 'post_tag';
            }));

        $this->subscriber->onTermEdited(10, 10, 'post_tag');
    }

    #[Test]
    public function it_queues_term_unpublish_on_delete(): void
    {
        $this->enableAutoSync();

        Functions\when('taxonomy_exists')
            ->justReturn(true);

        Functions\when('is_taxonomy_viewable')
            ->justReturn(true);

        $term = $this->createTerm(10, 'category');

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::Taxonomy
                    && $job->objectId === '10'
                    && $job->action === SyncAction::Unpublish
                    && $job->meta['taxonomy'] === 'category';
            }));

        $this->subscriber->onTermDelete(10, 10, 'category', $term);
    }

    #[Test]
    public function it_skips_internal_taxonomy_nav_menu(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldNotReceive('push');

        $this->subscriber->onTermCreated(10, 10, 'nav_menu');
    }

    #[Test]
    public function it_skips_internal_taxonomy_link_category(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldNotReceive('push');

        $this->subscriber->onTermCreated(10, 10, 'link_category');
    }

    #[Test]
    public function it_skips_internal_taxonomy_post_format(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldNotReceive('push');

        $this->subscriber->onTermCreated(10, 10, 'post_format');
    }

    #[Test]
    public function it_skips_non_viewable_taxonomy(): void
    {
        $this->enableAutoSync();

        Functions\when('taxonomy_exists')
            ->justReturn(true);

        Functions\when('is_taxonomy_viewable')
            ->justReturn(false);

        $this->queue->shouldNotReceive('push');

        $this->subscriber->onTermCreated(10, 10, 'custom_private_tax');
    }

    #[Test]
    public function it_does_not_queue_term_delete_when_term_is_not_object(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldNotReceive('push');

        // When deleted term is not an object (e.g., WP_Error or null)
        $this->subscriber->onTermDelete(10, 10, 'category', null);
    }

    // =========================================================================
    // Attachment Hook Tests
    // =========================================================================

    #[Test]
    public function it_queues_new_attachment_for_sync(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::Media
                    && $job->objectId === '500'
                    && $job->action === SyncAction::Publish;
            }));

        $this->subscriber->onAddAttachment(500);
    }

    #[Test]
    public function it_queues_edited_attachment_for_sync(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::Media
                    && $job->objectId === '501'
                    && $job->action === SyncAction::Publish;
            }));

        $this->subscriber->onEditAttachment(501);
    }

    #[Test]
    public function it_queues_deleted_attachment_for_unpublish(): void
    {
        $this->enableAutoSync();

        $this->queue->shouldReceive('push')
            ->once()
            ->with(Mockery::on(function (SyncJob $job) {
                return $job->type === SyncType::Media
                    && $job->objectId === '502'
                    && $job->action === SyncAction::Unpublish;
            }));

        $this->subscriber->onDeleteAttachment(502);
    }

    #[Test]
    public function it_does_not_queue_attachment_when_not_in_static_mode(): void
    {
        $this->settings->shouldReceive('isStaticMode')
            ->andReturn(false);

        $this->queue->shouldNotReceive('push');

        $this->subscriber->onAddAttachment(500);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function enableAutoSync(): void
    {
        $this->settings->shouldReceive('isStaticMode')
            ->andReturn(true);
        $this->settings->shouldReceive('getSyncSettings')
            ->andReturn(['auto_sync' => true]);
    }

    private function createPost(int $id, string $postType, string $status): WP_Post
    {
        $post = Mockery::mock(WP_Post::class);
        $post->ID = $id;
        $post->post_type = $postType;
        $post->post_status = $status;

        return $post;
    }

    private function createTerm(int $id, string $taxonomy): WP_Term
    {
        $term = Mockery::mock(WP_Term::class);
        $term->term_id = $id;
        $term->taxonomy = $taxonomy;

        return $term;
    }
}
