<?php

use Quant\Client;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once(__DIR__.'/../quant-cli.php');
}

if (!function_exists('is_plugin_active')) {
  include_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

if ( is_plugin_active('redirection/redirection.php') ) {
    require_once(__DIR__.'/includes/redirection_functions.php');
}

if (!empty($_SERVER['HTTP_QUANT_TOKEN'])) {

    $token = get_option('quant_internal_token');

    if ($token == $_SERVER['HTTP_QUANT_TOKEN']) {
        remove_action('template_redirect', 'redirect_canonical');
    }

}

function quant_get_all_taxonomies() {
    $all = [];

    $taxonomies = get_taxonomies();
    foreach ($taxonomies as $taxonomy) {
        $all[] = $taxonomy;
    }

    return $all;
}

function quant_get_all_types() {

    $all = [];

    $types = get_post_types();
    foreach ($types as $type) {
        $all[] = $type;
    }

    return $all;

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
        $enabled = $options['enabled'] ?? false;
        return boolval($enabled);
        // return boolval($options['enabled']);
    }
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
        if (!quant_is_enabled()) {
            return;
        }

        // @todo: Support draft/scheduled posts
        if (get_post_status($id) !== 'publish') {
            quant_unpublish_post($id);
            return;
        }

        $client = new Client();
        $client->sendPost($id);
    }
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
}


/**
 * Generate save/update/delete hooks for all taxonomies.
 */

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
            require_once(__DIR__.'/seed/CustomCronRoutesBatch.php');
            $batch = new QuantCustomCronRoutesBatch();
            $batch->restart();
            while ($next_item = $batch->get_next_item()) {
                $response = $batch->process( $next_item );
                $batch->mark_as_processed( $next_item->id );
            }
            $batch->finish();
        }

        if ($cronOptions['cron_home']) {
            require_once(__DIR__.'/seed/HomeBatch.php');
            $batch = new QuantHomeBatch();
            $batch->restart();
            while ($next_item = $batch->get_next_item()) {
                $response = $batch->process( $next_item );
                $batch->mark_as_processed( $next_item->id );
            }
            $batch->finish();
        }

        if ($cronOptions['cron_posts']) {
            require_once(__DIR__.'/seed/PostBatch.php');
            $batch = new QuantPostBatch();
            $batch->restart();
            while ($next_item = $batch->get_next_item()) {
                $response = $batch->process( $next_item );
                $batch->mark_as_processed( $next_item->id );
            }
            $batch->finish();
        }

        if ($cronOptions['cron_pages']) {
            require_once(__DIR__.'/seed/PageBatch.php');
            $batch = new QuantPageBatch();
            $batch->restart();
            while ($next_item = $batch->get_next_item()) {
                $response = $batch->process( $next_item );
                $batch->mark_as_processed( $next_item->id );
            }
            $batch->finish();
        }

        if ($cronOptions['cron_categories']) {
            require_once(__DIR__.'/seed/CategoryBatch.php');
            $batch = new QuantCategoryBatch();
            $batch->restart();
            while ($next_item = $batch->get_next_item()) {
                $response = $batch->process( $next_item );
                $batch->mark_as_processed( $next_item->id );
            }
            $batch->finish();
        }

        if ($cronOptions['cron_tags']) {
            require_once(__DIR__.'/seed/TagBatch.php');
            $batch = new QuantTagBatch();
            $batch->restart();
            while ($next_item = $batch->get_next_item()) {
                $response = $batch->process( $next_item );
                $batch->mark_as_processed( $next_item->id );
            }
            $batch->finish();
        }
    }
    add_action ('quant_cronjob', 'quant_cron_run');
}

if (!function_exists('quant_init_hooks')) {
    /**
     * Run all the init hooks (ensure this happens last).
     *
     * @return void
     */

    function quant_init_hooks()
    {
        if (!quant_is_enabled()) {
            return;
        }

        // Initialise taxonomy create/edit hooks.
        $taxonomies = quant_get_all_taxonomies();
        foreach ($taxonomies as $taxonomy) {
            add_action("edit_${taxonomy}", 'quant_save_category', 1000);
            add_action("create_${taxonomy}", 'quant_save_category', 1000);
        }

        // Initialise save hook for all post types.
        $types = quant_get_all_types();
        foreach ($types as $type) {
            add_action("save_${type}", 'quant_save_post', 1000);
        }

        // Initialise unpublish/trash post hook.
        add_action('wp_trash_post', 'quant_unpublish_post', 1000);

        // Initialise unpublish/trash category hook.
        add_action('delete_category', 'quant_delete_category', 1000);

        // Initialise redirection hooks (if plugin is active).
        if ( is_plugin_active('redirection/redirection.php') ) {
          add_action('redirection_redirect_updated', 'quant_redirection_redirect_updated', 1000);
          add_action('redirection_redirect_deleted', 'quant_redirection_redirect_deleted', 1000);
        }
    }

    // Init cron.
    add_action( 'init', 'quant_cron_setup' );

    // Init other quant init hooks (with weight).
    add_action( 'init', 'quant_init_hooks', 1000 );

}
