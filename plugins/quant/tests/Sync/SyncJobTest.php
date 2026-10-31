<?php

declare(strict_types=1);

namespace Quant\Tests\Sync;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncType;

#[CoversClass(SyncJob::class)]
#[CoversClass(SyncType::class)]
final class SyncJobTest extends TestCase
{
    #[Test]
    public function it_creates_a_job_with_an_id_and_timestamp(): void
    {
        $job = new SyncJob(SyncType::Post, '123', SyncAction::Publish);

        $this->assertNotEmpty($job->id);
        $this->assertInstanceOf(DateTimeImmutable::class, $job->createdAt);
        $this->assertSame([], $job->meta);
    }

    #[Test]
    public function it_generates_unique_ids(): void
    {
        $a = new SyncJob(SyncType::Post, '1', SyncAction::Publish);
        $b = new SyncJob(SyncType::Post, '1', SyncAction::Publish);

        $this->assertNotSame($a->id, $b->id);
    }

    #[Test]
    public function it_encodes_meta_as_json_and_null_when_empty(): void
    {
        $withMeta = new SyncJob(SyncType::Redirect, '/old', SyncAction::Publish, ['to' => '/new', 'code' => 301]);
        $without = new SyncJob(SyncType::Post, '1', SyncAction::Publish);

        $this->assertSame('{"to":"\/new","code":301}', $withMeta->encodeMeta());
        $this->assertNull($without->encodeMeta());
    }

    #[Test]
    public function it_restores_meta_from_a_database_row(): void
    {
        $job = SyncJob::fromDatabaseRow([
            'job_id' => 'abc',
            'type' => 'redirect',
            'object_id' => '/old',
            'action' => 'publish',
            'created_at' => '2026-01-15 10:00:00',
            'meta' => '{"to":"/new","code":301}',
        ]);

        $this->assertSame('abc', $job->id);
        $this->assertSame(SyncType::Redirect, $job->type);
        $this->assertSame(['to' => '/new', 'code' => 301], $job->meta);
        $this->assertSame('2026-01-15 10:00:00', $job->createdAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_tolerates_missing_or_broken_meta_in_a_row(): void
    {
        $row = ['job_id' => 'a', 'type' => 'post', 'object_id' => '1', 'action' => 'publish', 'created_at' => '2026-01-15 10:00:00'];

        $this->assertSame([], SyncJob::fromDatabaseRow($row)->meta);
        $this->assertSame([], SyncJob::fromDatabaseRow($row + ['meta' => '{not json'])->meta);
        $this->assertSame([], SyncJob::fromDatabaseRow($row + ['meta' => ''])->meta);
    }

    #[Test]
    public function it_serialises_to_json(): void
    {
        $job = new SyncJob(SyncType::Page, '5', SyncAction::Unpublish, ['url' => '/about'], 'fixed-id', new DateTimeImmutable('2026-01-15T10:00:00+00:00'));

        $this->assertSame([
            'id' => 'fixed-id',
            'type' => 'page',
            'objectId' => '5',
            'action' => 'unpublish',
            'meta' => ['url' => '/about'],
            'createdAt' => '2026-01-15T10:00:00+00:00',
        ], $job->jsonSerialize());
    }

    #[Test]
    public function sync_types_know_whether_they_are_posts_or_routes(): void
    {
        $this->assertTrue(SyncType::Post->isPostBacked());
        $this->assertTrue(SyncType::Media->isPostBacked());
        $this->assertFalse(SyncType::Taxonomy->isPostBacked());

        $this->assertTrue(SyncType::Home->isRoute());
        $this->assertTrue(SyncType::Sitemap->isRoute());
        $this->assertTrue(SyncType::NotFound->isRoute());
        $this->assertFalse(SyncType::Post->isRoute());
        $this->assertFalse(SyncType::Redirect->isRoute());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_shares_a_dedupe_key_between_publish_and_unpublish(): void
    {
        $publish = new SyncJob(SyncType::Post, '5', SyncAction::Publish);
        $unpublish = new SyncJob(SyncType::Post, '5', SyncAction::Unpublish, ['url' => '/x']);

        $this->assertSame($publish->dedupeKey(), $unpublish->dedupeKey());
        $this->assertSame(32, strlen($publish->dedupeKey()));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_gives_purges_and_other_objects_their_own_dedupe_key(): void
    {
        $publish = new SyncJob(SyncType::Post, '5', SyncAction::Publish);

        $this->assertNotSame($publish->dedupeKey(), (new SyncJob(SyncType::Post, '5', SyncAction::Purge))->dedupeKey());
        $this->assertNotSame($publish->dedupeKey(), (new SyncJob(SyncType::Post, '6', SyncAction::Publish))->dedupeKey());
        $this->assertNotSame($publish->dedupeKey(), (new SyncJob(SyncType::Page, '5', SyncAction::Publish))->dedupeKey());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_includes_the_listing_page_in_the_dedupe_key(): void
    {
        $page1 = new SyncJob(SyncType::Taxonomy, '7', SyncAction::Publish, ['taxonomy' => 'category', 'page' => 1]);
        $page2 = new SyncJob(SyncType::Taxonomy, '7', SyncAction::Publish, ['taxonomy' => 'category', 'page' => 2]);
        $noPage = new SyncJob(SyncType::Taxonomy, '7', SyncAction::Publish, ['taxonomy' => 'category']);

        $this->assertNotSame($page1->dedupeKey(), $page2->dedupeKey());
        $this->assertSame($page1->dedupeKey(), $noPage->dedupeKey());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_ignores_other_meta_in_the_dedupe_key(): void
    {
        $a = new SyncJob(SyncType::Redirect, '/old', SyncAction::Publish, ['to' => '/a']);
        $b = new SyncJob(SyncType::Redirect, '/old', SyncAction::Publish, ['to' => '/b']);

        $this->assertSame($a->dedupeKey(), $b->dedupeKey());
    }
}
