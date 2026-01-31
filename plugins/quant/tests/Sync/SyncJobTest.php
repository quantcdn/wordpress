<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\CoversClass;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;
use Quant\Sync\SyncAction;
use DateTimeImmutable;
use JsonSerializable;

#[CoversClass(SyncJob::class)]
final class SyncJobTest extends TestCase
{
    #[Test]
    public function it_creates_a_sync_job_with_required_properties(): void
    {
        $job = new SyncJob(
            type: SyncType::Post,
            objectId: '123',
            action: SyncAction::Publish
        );

        $this->assertSame(SyncType::Post, $job->type);
        $this->assertSame('123', $job->objectId);
        $this->assertSame(SyncAction::Publish, $job->action);
    }

    #[Test]
    public function it_auto_generates_a_unique_id(): void
    {
        $job1 = new SyncJob(
            type: SyncType::Post,
            objectId: '123',
            action: SyncAction::Publish
        );

        $job2 = new SyncJob(
            type: SyncType::Post,
            objectId: '123',
            action: SyncAction::Publish
        );

        $this->assertNotEmpty($job1->id);
        $this->assertNotEmpty($job2->id);
        $this->assertNotSame($job1->id, $job2->id);
    }

    #[Test]
    public function it_sets_created_at_timestamp(): void
    {
        $before = new DateTimeImmutable();
        $job = new SyncJob(
            type: SyncType::Page,
            objectId: '456',
            action: SyncAction::Unpublish
        );
        $after = new DateTimeImmutable();

        $this->assertInstanceOf(DateTimeImmutable::class, $job->createdAt);
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $job->createdAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $job->createdAt->getTimestamp());
    }

    #[Test]
    public function it_accepts_optional_meta_array(): void
    {
        $meta = ['revision' => 5, 'author' => 'admin'];

        $job = new SyncJob(
            type: SyncType::Post,
            objectId: '123',
            action: SyncAction::Publish,
            meta: $meta
        );

        $this->assertSame($meta, $job->meta);
    }

    #[Test]
    public function it_defaults_meta_to_empty_array(): void
    {
        $job = new SyncJob(
            type: SyncType::Post,
            objectId: '123',
            action: SyncAction::Publish
        );

        $this->assertSame([], $job->meta);
    }

    #[Test]
    public function it_implements_json_serializable(): void
    {
        $job = new SyncJob(
            type: SyncType::Media,
            objectId: '789',
            action: SyncAction::Purge,
            meta: ['filename' => 'image.jpg']
        );

        $this->assertInstanceOf(JsonSerializable::class, $job);
    }

    #[Test]
    public function it_serializes_to_json_correctly(): void
    {
        $job = new SyncJob(
            type: SyncType::Media,
            objectId: '789',
            action: SyncAction::Purge,
            meta: ['filename' => 'image.jpg']
        );

        $serialized = $job->jsonSerialize();

        $this->assertIsArray($serialized);
        $this->assertSame($job->id, $serialized['id']);
        $this->assertSame('media', $serialized['type']);
        $this->assertSame('789', $serialized['objectId']);
        $this->assertSame('purge', $serialized['action']);
        $this->assertSame(['filename' => 'image.jpg'], $serialized['meta']);
        $this->assertSame($job->createdAt->format('c'), $serialized['createdAt']);
    }

    #[Test]
    public function it_works_with_all_sync_types(): void
    {
        $types = [
            SyncType::Post,
            SyncType::Page,
            SyncType::CustomPostType,
            SyncType::Taxonomy,
            SyncType::Archive,
            SyncType::Media,
            SyncType::ThemeAsset,
            SyncType::Redirect,
            SyncType::CustomRoute,
        ];

        foreach ($types as $type) {
            $job = new SyncJob(
                type: $type,
                objectId: '1',
                action: SyncAction::Publish
            );
            $this->assertSame($type, $job->type);
        }
    }

    #[Test]
    public function it_works_with_all_sync_actions(): void
    {
        $actions = [
            SyncAction::Publish,
            SyncAction::Unpublish,
            SyncAction::Purge,
        ];

        foreach ($actions as $action) {
            $job = new SyncJob(
                type: SyncType::Post,
                objectId: '1',
                action: $action
            );
            $this->assertSame($action, $job->action);
        }
    }
}
