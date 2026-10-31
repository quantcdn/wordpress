<?php

declare(strict_types=1);

namespace Quant\Core;

class Deactivator
{
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('quant_process_queue');
        wp_clear_scheduled_hook('quant_scheduled_sync');
    }
}
