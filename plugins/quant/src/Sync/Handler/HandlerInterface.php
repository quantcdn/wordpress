<?php

declare(strict_types=1);

namespace Quant\Sync\Handler;

use Quant\Sync\SyncJob;

interface HandlerInterface
{
    public function supports(SyncJob $job): bool;

    /**
     * @throws \Throwable on failure; the message is stored with the job
     */
    public function handle(SyncJob $job): void;
}
