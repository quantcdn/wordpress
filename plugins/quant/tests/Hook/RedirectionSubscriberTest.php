<?php

declare(strict_types=1);

namespace Quant\Tests\Hook;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quant\Core\Settings;
use Quant\Hook\RedirectionSubscriber;
use Quant\Redirects\RedirectProvider;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;
use Quant\Tests\Support\WordPressStubs;
use Red_Item;

#[CoversClass(RedirectionSubscriber::class)]
final class RedirectionSubscriberTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use WordPressStubs;

    private SyncQueueInterface&Mockery\MockInterface $queue;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubCommonWordPressFunctions();
        Red_Item::$items = [1 => new Red_Item(1, '/old', '/new', '302'), 2 => new Red_Item(2, '/regex', '/x', '301', regex: true)];
        Red_Item::$flushed = 0;
        $this->queue = Mockery::mock(SyncQueueInterface::class);
    }

    protected function tearDown(): void
    {
        Red_Item::$items = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    private function subscriber(?Settings $settings = null): RedirectionSubscriber
    {
        return new RedirectionSubscriber($this->queue, $settings ?? $this->mockSettings(), new RedirectProvider());
    }

    #[Test]
    public function it_queues_a_new_redirect_by_id(): void
    {
        $this->queue->shouldReceive('push')->once()->with(Mockery::on(static fn(SyncJob $j): bool =>
            $j->type === SyncType::Redirect && $j->objectId === '/old' && $j->action === SyncAction::Publish && $j->meta === ['to' => '/new', 'code' => 302]));

        $this->subscriber()->onRedirectUpdated(1);
    }

    #[Test]
    public function it_flushes_and_reloads_an_updated_redirect(): void
    {
        $this->queue->shouldReceive('push')->once();

        $this->subscriber()->onRedirectUpdated(Red_Item::get_by_id(1));

        $this->assertSame(1, Red_Item::$flushed);
    }

    #[Test]
    public function it_ignores_redirects_quant_cannot_serve_and_unknown_ids(): void
    {
        $this->queue->shouldNotReceive('push');

        $this->subscriber()->onRedirectUpdated(2);
        $this->subscriber()->onRedirectUpdated(99);
        $this->subscriber()->onRedirectUpdated('garbage');
    }

    #[Test]
    public function it_queues_an_unpublish_when_a_redirect_is_deleted(): void
    {
        $this->queue->shouldReceive('push')->once()->with(Mockery::on(static fn(SyncJob $j): bool =>
            $j->type === SyncType::Redirect && $j->objectId === '/old' && $j->action === SyncAction::Unpublish));

        $this->subscriber()->onRedirectDeleted(Red_Item::get_by_id(1));
    }

    #[Test]
    public function it_is_inactive_outside_static_auto_sync(): void
    {
        $this->queue->shouldNotReceive('push');

        $this->subscriber($this->mockSettings(mode: Settings::MODE_CDN_ONLY))->onRedirectUpdated(1);
        $this->subscriber($this->mockSettings(sync: ['auto_sync' => false]))->onRedirectDeleted(Red_Item::get_by_id(1));
    }
}
