<?php

declare(strict_types=1);

namespace Quant\Hook;

use Quant\Core\Settings;
use Quant\Redirects\RedirectProvider;
use Quant\Sync\SyncAction;
use Quant\Sync\SyncJob;
use Quant\Sync\SyncQueueInterface;
use Quant\Sync\SyncType;

/**
 * Keeps Quant redirects in step with the Redirection plugin.
 */
class RedirectionSubscriber
{
    public function __construct(
        private readonly SyncQueueInterface $queue,
        private readonly Settings $settings,
        private readonly RedirectProvider $provider
    ) {
    }

    public function register(): void
    {
        add_action('redirection_redirect_updated', [$this, 'onRedirectUpdated'], 1000, 1);
        add_action('redirection_redirect_deleted', [$this, 'onRedirectDeleted'], 1000, 1);
    }

    /**
     * @param int|object $redirect The new item's id, or the previous item on update.
     */
    public function onRedirectUpdated(mixed $redirect): void
    {
        if (!$this->active() || !class_exists('Red_Item')) {
            return;
        }

        $item = $this->reload($redirect);
        if ($item === null) {
            return;
        }

        $converted = $this->provider->fromRedirectionItem($item);
        if ($converted === null) {
            return;
        }

        $this->queue->push(new SyncJob(SyncType::Redirect, $converted->from, SyncAction::Publish, [
            'to' => $converted->to,
            'code' => $converted->statusCode,
        ]));
    }

    public function onRedirectDeleted(mixed $redirect): void
    {
        if (!$this->active() || !is_object($redirect) || !method_exists($redirect, 'get_url')) {
            return;
        }

        $from = '/' . ltrim((string) $redirect->get_url(), '/');
        $this->queue->push(new SyncJob(SyncType::Redirect, $from, SyncAction::Unpublish));
    }

    private function active(): bool
    {
        return $this->settings->isStaticMode() && !empty($this->settings->getSyncSettings()['auto_sync']);
    }

    private function reload(mixed $redirect): ?object
    {
        if (is_int($redirect)) {
            $item = \Red_Item::get_by_id($redirect);
            return is_object($item) ? $item : null;
        }

        if (!is_object($redirect) || !method_exists($redirect, 'get_id')) {
            return null;
        }

        if (class_exists('Red_Module') && method_exists($redirect, 'get_group_id')) {
            \Red_Module::flush($redirect->get_group_id());
        }

        $item = \Red_Item::get_by_id($redirect->get_id());

        return is_object($item) ? $item : null;
    }
}
