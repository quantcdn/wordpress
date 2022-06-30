<?php

/**
 * Manage and seed content via QuantCDN.
 */
class Quant_CLI {

    private $runningProcs = [];
    private $batchTypes = [
        'pages' => [
            'class' => 'QuantPageBatch',
            'includePath' => __DIR__.'/src/seed/PageBatch.php',
        ],
        'posts' => [
            'class' => 'QuantPostBatch',
            'includePath' => __DIR__.'/src/seed/PostBatch.php',
        ],
        'custom_posts' => [
            'class' => 'QuantCustomPostTypesBatch',
            'includePath' => __DIR__.'/src/seed/CustomPostTypes.php',
        ],
        'categories' => [
            'class' => 'QuantCategoryBatch',
            'includePath' => __DIR__.'/src/seed/CategoryBatch.php',
        ],
        'tags' => [
            'class' => 'QuantTagBatch',
            'includePath' => __DIR__.'/src/seed/TagBatch.php',
        ],
        'custom_taxonomies' => [
            'class' => 'QuantCustomTaxonomiesBatch',
            'includePath' => __DIR__.'/src/seed/CustomTaxonomies.php',
        ],
        'home' => [
            'class' => 'QuantHomeBatch',
            'includePath' => __DIR__.'/src/seed/HomeBatch.php',
        ],
        'theme_assets' => [
            'class' => 'QuantThemeAssetsBatch',
            'includePath' => __DIR__.'/src/seed/ThemeAssetsBatch.php',
        ],
        'custom_routes' => [
            'class' => 'QuantCustomRoutesBatch',
            'includePath' => __DIR__.'/src/seed/CustomRoutesBatch.php',
        ],
        'archives' => [
            'class' => 'QuantArchivesBatch',
            'includePath' => __DIR__.'/src/seed/ArchivesBatch.php',
        ],
        'media_assets' => [
            'class' => 'QuantMediaAssetsBatch',
            'includePath' => __DIR__.'/src/seed/MediaAssetsBatch.php',
        ],
        'redirects' => [
            'class' => 'QuantRedirectionBatch',
            'includePath' => __DIR__.'/src/seed/RedirectionBatch.php',
        ],
    ];

    /**
     * Shows Quant queue status.
     *
     * @when after_wp_load
     */
    function info ( $args, $assoc_args ) {

        $items = [];
        foreach ($this->batchTypes as $k => $type) {
            require_once($type['includePath']);
            $batch = new $type['class'];
            $batchItem = [
                'Name' => $k,
                'Title' => $batch->title,
                'Total Items' => $batch->get_items_count(),
                'Processed Items' => $batch->get_processed_count(),
            ];
            $items[] = $batchItem;
        }

        WP_CLI\Utils\format_items( 'table', $items, array( 'Name', 'Title', 'Total Items', 'Processed Items' ) );
    }


    /**
     * Resets a queue.
     *
     * ## OPTIONS
     *
     * <queue>
     * : The name of the queue to reset.
     *
     * @when after_wp_load
     */
    function reset_queue ( $args, $assoc_args ) {

        list( $queue ) = $args;

        if (!in_array( $queue, array_keys($this->batchTypes) ) && $queue != 'all' ) {
            WP_CLI::error( "Invalid queue name. Valid names: " . implode(", ", array_keys($this->batchTypes)) );
        }

        if ($queue == 'all') {
            foreach ($this->batchTypes as $k => $batchType) {
                require_once($batchType['includePath']);
                $batch = new $batchType['class'];
                $batch->restart();
                WP_CLI::success( "Batch $k reset successfully." );
            }
            return;
        }

        $batchType = $this->batchTypes[$queue];
        require_once($batchType['includePath']);
        $batch = new $batchType['class'];
        $batch->restart();

        WP_CLI::success( "Batch reset successfully." );
    }

    /**
     * Perform the actual queue run (in a forked process).
     *
     * ## OPTIONS
     *
     * <queue>
     * : The name of the queue to process.
     *
     * @when after_wp_load
     */
    public function process_queue_single ( $args, $assoc_args ) {

        list( $queue ) = $args;

        if (!in_array( $queue, array_keys($this->batchTypes) )) {
            WP_CLI::error( "Invalid queue name. Valid names: " . implode(", ", array_keys($this->batchTypes)) );
        }

        $processCount = 0;

        $batchType = $this->batchTypes[$queue];
        require_once($batchType['includePath']);
        $batch = new $batchType['class'];

        while ($next_item = $batch->get_next_item()) {

            if ( $batch->is_claimed( $next_item->id ) ) {
                continue;
            }

            $batch->mark_as_claimed( $next_item->id );
            $response = $batch->process( $next_item );
            $batch->mark_as_processed( $next_item->id );
            WP_CLI::success( "Successfully processed item: " . $next_item->id );
            $processCount++;

            // Ensure loop break if required.
            if ($processCount > $batch->get_items_count()) {
                break;
            }

        }
        $batch->finish();

        if ($processCount > 0) {
            WP_CLI::success( "$processCount $queue items completed successfully." );
        }

    }

    /**
     * Process a queue.
     *
     * ## OPTIONS
     *
     * <queue>
     * : The name of the queue to process. Use "all" for everything.
     *
     * [--threads=<threads>]
     * : Specify threads to increase concurrency.
     * ---
     * default: 5
     * ---

     * @when after_wp_load
     */
    function process_queue ( $args, $assoc_args ) {

        list( $queue ) = $args;
        $threads = $assoc_args['threads'];

        if ( !intval( $threads ) ) {
            WP_CLI::error( "Threads must be an integer." );
        }

        // Limit threads to an upper bound of 20.
        if ($threads > 20) {
            $threads = 20;
        }

        if (!in_array( $queue, array_keys($this->batchTypes) ) && $queue != 'all' ) {
            WP_CLI::error( "Invalid queue name. Valid names: " . implode(", ", array_keys($this->batchTypes) ) );
        }

        if ($queue == 'all') {
            foreach ($this->batchTypes as $k => $batchType) {
                $this->process_queue( [$k] , ['threads' => $threads ] );
            }
            return;
        }

        $batchType = $this->batchTypes[$queue];
        require_once($batchType['includePath']);
        $batch = new $batchType['class'];

        // Reset the claims before kicking off.
        $batch->restart_claims();

        for ($i = 0; $i < $threads; $i++) {

            $cmd = "wp quant process_queue_single $queue --path=" . ABSPATH;

            if (WP_CLI::has_config( 'url' )) {
                $cmd .= " --url=" . WP_CLI::get_config( 'url' );
            }

            if (WP_CLI::has_config( 'allow-root' )) {
                $cmd .= ' --allow-root';
            }

            $this->runningProcs[] = proc_open($cmd, [], $pipes, NULL, NULL, ['bypass_shell' => TRUE]);
        }

        // Wait until commands complete.
        foreach ($this->runningProcs as $proc) {
            $procStatus  = proc_get_status( $proc );

            while ( $procStatus['running'] ) {
                $procStatus  = proc_get_status( $proc );
            }
        }

        WP_CLI::success( "Queue $queue processing complete." );
    }


}

WP_CLI::add_command( 'quant', 'Quant_CLI' );
