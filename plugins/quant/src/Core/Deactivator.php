<?php

declare(strict_types=1);

namespace Quant\Core;

class Deactivator
{
    public static function deactivate(): void
    {
        // Clear scheduled events
        wp_clear_scheduled_hook('quant_sync_cron');

        flush_rewrite_rules();
    }
}
