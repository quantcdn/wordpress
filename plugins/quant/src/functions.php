<?php

use Quant\Client;

if (!empty($_SERVER['HTTP_QUANT_TOKEN'])) {

    $token = get_option('quant_internal_token');

    if ($token == $_SERVER['HTTP_QUANT_TOKEN']) {
        remove_action('template_redirect', 'redirect_canonical');
    }

}

if (!function_exists('quant_get_options')) {
    /**
     * Return the plugin settings/options
     *
     * @return array
     */
    function quant_get_options()
    {
        return get_option(QUANT_SETTINGS_KEY);
    }
}

if (!function_exists('quant_is_enabled')) {
    /**
     * Return if Quant is activated or not
     *
     * @return bool
     */
    function quant_is_enabled()
    {
        $options = quant_get_options();
        return boolval($options['enabled']);
    }
    quant_is_enabled();
}

if (!function_exists('quant_save_post')) {
    /**
     * Post updated Quant content on post save.
     *
     * @param int $id
     * @return void
     */
    function quant_save_post($id)
    {
        // @todo: Support draft posts
        if (get_post_status($id) !== 'publish' || !quant_is_enabled()) {
            return;
        }

        $client = new Client();
        $client->sendPost($id);
    }
    add_action('save_post', 'quant_save_post');
}

if (!function_exists('quant_unpublish_post')) {
    /**
     * Unpublish the route via Quant API on deletion.
     *
     * @return void
     */
    function quant_unpublish_post($id)
    {
        if (!quant_is_enabled()) {
            return;
        }

        $client = new Client();
        $permalink = wp_make_link_relative(get_permalink($id));

        // Remove __trashed from permalinks if present.
        $permalink = preg_replace('/__trashed.*$/', '', $permalink);
        $client->unpublish($permalink);

    }
    add_action('trashed_post', 'quant_unpublish_post');
}


if (!function_exists('quant_save_category')) {
    /**
     * Save updated category content to quant.
     *
     * @param int $id
     * @return void
     */
    function quant_save_category($id)
    {
        $client = new Client();
        $client->sendCategory($id);
    }
    add_action('edit_category', 'quant_save_category');
    add_action('create_category', 'quant_save_category');
}

if (!function_exists('quant_delete_category')) {
    /**
     * Unpublish category route on delete.
     *
     * @param int $id
     * @return void
     */
    function quant_delete_category($id)
    {
        // @todo: After category is deleted we cannot retrieve permalink.
        // Need a "before_delete_category" hook.
    }
    add_action('delete_category', 'quant_delete_category');
}

if (!function_exists('quant_cron_setup')) {
    /**
     * Run Quant cron job.
     *
     * @return void
     */
    function quant_cron_setup()
    {
        $options = get_option(QUANT_CRON_SETTINGS_KEY);

        if (!$options['cron_enabled']) {
            quant_cron_deactivate();
            return;
        }

        if( !wp_next_scheduled( 'quant_cronjob' ) ) {
            wp_schedule_event( time(), $options['cron_schedule'], 'quant_cronjob' );
        }
    }
    add_action('wp', 'quant_cron_setup');

    function quant_cron_deactivate() {
        $timestamp = wp_next_scheduled ('quant_cronjob');
        wp_unschedule_event ($timestamp, 'quant_cronjob');
    }
    register_deactivation_hook (__FILE__, 'quant_cron_deactivate');
}

if (!function_exists('quant_cron_run')) {
    /**
     * Run the cron.
     *
     * @return void
     */
    function quant_cron_run()
    {

        $cronOptions = get_option(QUANT_CRON_SETTINGS_KEY);

        if (!$cronOptions['cron_enabled']) {
            return;
        }

        require_once(__DIR__.'/../wp-batch-processing/includes/class-bp-helper.php');
        require_once(__DIR__.'/../wp-batch-processing/includes/class-bp-singleton.php');
        require_once(__DIR__.'/../wp-batch-processing/includes/class-batch-item.php');
        require_once(__DIR__.'/../wp-batch-processing/includes/class-batch.php');
        require_once(__DIR__.'/../wp-batch-processing/includes/class-batch-processor.php');
        require_once(__DIR__.'/../wp-batch-processing/includes/class-batch-ajax-handler.php');
        require_once(__DIR__.'/../wp-batch-processing/includes/class-batch-list-table.php');
        require_once(__DIR__.'/../wp-batch-processing/includes/class-batch-processor-admin.php');


        if (!empty($cronOptions['cron_custom_routes'])) {
            require_once(__DIR__.'/Seed/CustomCronRoutesBatch.php');
            $batch = new QuantCustomCronRoutesBatch();
            $batch->restart();
            while ($next_item = $batch->get_next_item()) {
                $response = $batch->process( $next_item );
                $batch->mark_as_processed( $next_item->id );
            }
            $batch->finish();
        }

        if ($cronOptions['cron_home']) {
            require_once(__DIR__.'/Seed/HomeBatch.php');
            $batch = new QuantHomeBatch();
            $batch->restart();
            while ($next_item = $batch->get_next_item()) {
                $response = $batch->process( $next_item );
                $batch->mark_as_processed( $next_item->id );
            }
            $batch->finish();
        }

        if ($cronOptions['cron_posts']) {
            require_once(__DIR__.'/Seed/PostBatch.php');
            $batch = new QuantPostBatch();
            $batch->restart();
            while ($next_item = $batch->get_next_item()) {
                $response = $batch->process( $next_item );
                $batch->mark_as_processed( $next_item->id );
            }
            $batch->finish();
        }

        if ($cronOptions['cron_pages']) {
            require_once(__DIR__.'/Seed/PageBatch.php');
            $batch = new QuantPageBatch();
            $batch->restart();
            while ($next_item = $batch->get_next_item()) {
                $response = $batch->process( $next_item );
                $batch->mark_as_processed( $next_item->id );
            }
            $batch->finish();
        }

        if ($cronOptions['cron_categories']) {
            require_once(__DIR__.'/Seed/CategoryBatch.php');
            $batch = new QuantCategoryBatch();
            $batch->restart();
            while ($next_item = $batch->get_next_item()) {
                $response = $batch->process( $next_item );
                $batch->mark_as_processed( $next_item->id );
            }
            $batch->finish();
        }

        if ($cronOptions['cron_tags']) {
            require_once(__DIR__.'/Seed/TagBatch.php');
            $batch = new QuantTagBatch();
            $batch->restart();
            while ($next_item = $batch->get_next_item()) {
                $response = $batch->process( $next_item );
                $batch->mark_as_processed( $next_item->id );
            }
            $batch->finish();
        }


        wp_schedule_event( time(), $options['cron_schedule'], 'quant_cronjob' );
    }
    add_action ('quant_cronjob', 'quant_cron_run');
}



if (!function_exists('quant_cron_testing')) {
    /**
     * Development function to test cron every 10 seconds.
     *
     * @return void
     */
    function quant_cron_testing()
    {
        $schedules['every60s'] = array(
            'interval' => 60,
            'display' => __( 'Once per minute' )
        );
        return $schedules;
    }
    add_filter( 'cron_schedules', 'quant_cron_testing' );

}