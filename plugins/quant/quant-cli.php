<?php

/**
 * Implements example command.
 */
class Quant_CLI {

    private $runningProcs = [];
    private $batchTypes = [
        'pages' => [
            'class' => 'QuantPageBatch',
            'includePath' => __DIR__.'/src/Seed/PageBatch.php',
        ],
        'posts' => [
            'class' => 'QuantPostBatch',
            'includePath' => __DIR__.'/src/Seed/PostBatch.php',
        ],
        'custom_posts' => [
            'class' => 'QuantCustomPostTypesBatch',
            'includePath' => __DIR__.'/src/Seed/CustomPostTypes.php',
        ],
        'categories' => [
            'class' => 'QuantCategoryBatch',
            'includePath' => __DIR__.'/src/Seed/CategoryBatch.php',
        ],
        'tags' => [
            'class' => 'QuantTagBatch',
            'includePath' => __DIR__.'/src/Seed/TagBatch.php',
        ],
        'custom_taxonomies' => [
            'class' => 'QuantCustomTaxonomiesBatch',
            'includePath' => __DIR__.'/src/Seed/CustomTaxonomies.php',
        ],
        'home' => [
            'class' => 'QuantHomeBatch',
            'includePath' => __DIR__.'/src/Seed/HomeBatch.php',
        ],
        'theme_assets' => [
            'class' => 'QuantThemeAssetsBatch',
            'includePath' => __DIR__.'/src/Seed/ThemeAssetsBatch.php',
        ],
        'custom_routes' => [
            'class' => 'QuantCustomRoutesBatch',
            'includePath' => __DIR__.'/src/Seed/CustomRoutesBatch.php',
        ],
        'archives' => [
            'class' => 'QuantArchivesBatch',
            'includePath' => __DIR__.'/src/Seed/ArchivesBatch.php',
        ],
        'media_assets' => [
            'class' => 'QuantMediaAssetsBatch',
            'includePath' => __DIR__.'/src/Seed/MediaAssetsBatch.php',
        ],
    ];

    function __construct() {
        require_once(__DIR__.'/wp-batch-processing/includes/class-bp-helper.php');
        require_once(__DIR__.'/wp-batch-processing/includes/class-bp-singleton.php');
        require_once(__DIR__.'/wp-batch-processing/includes/class-batch-item.php');
        require_once(__DIR__.'/wp-batch-processing/includes/class-batch.php');
        require_once(__DIR__.'/wp-batch-processing/includes/class-batch-processor.php');
        require_once(__DIR__.'/wp-batch-processing/includes/class-batch-ajax-handler.php');
        require_once(__DIR__.'/wp-batch-processing/includes/class-batch-list-table.php');
        require_once(__DIR__.'/wp-batch-processing/includes/class-batch-processor-admin.php');
    }

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
            $cmd = "wp quant process_queue_single $queue --allow-root --path=" . ABSPATH;
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
