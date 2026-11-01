<?php

declare(strict_types=1);

namespace Quant\Sync;

enum SyncAction: string
{
    case Publish = 'publish';
    case Unpublish = 'unpublish';
    case Purge = 'purge';
}
