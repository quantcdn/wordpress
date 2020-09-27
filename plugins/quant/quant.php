<?php

/**
 * Plugin Name: Quant
 * Description: QuantCDN static edge integration
 * Author: Stuart Rowlands
 * Plugin URI: https://www.quantcdn.io
 * Version: 1.0.0
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once(__DIR__.'/src/App.php');
require_once(__DIR__.'/wp-batch-processing/wp-batch-processing.php');

/**
 * Register batch handlers
 */
require_once(__DIR__.'/src/seed/PageBatch.php');

function Quant()
{
    return Quant\App::instance();
}

/**
 * Activate the Quant plugin
 */
function QuantActivate()
{
    // Generate a random string for internal token.
    $token = get_option("quant_internal_token");

    if (empty($token)) {
        update_option("quant_internal_token", substr(str_shuffle(MD5(microtime())), 0, 10));
    }
}

register_activation_hook(__FILE__, [QuantActivate(), Quant()]);
register_deactivation_hook(__FILE__, [Quant(), 'deactivation']);



/**
 * Initialize the batches.
 */
function wp_batch_processing_init() {
    $batch = new QuantPageBatch();
    WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantPostBatch();
    WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantCategoryBatch();
    WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantTagBatch();
    WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantHomeBatch();
    WP_Batch_Processor::get_instance()->register( $batch );

    $seedOptions = get_option(QUANT_SEED_KEY);
    if ($seedOptions['theme_assets']) {
        $batch = new QuantThemeAssetsBatch();
        WP_Batch_Processor::get_instance()->register( $batch );
    }

    $batch = new QuantCustomRoutesBatch();
    WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantArchivesBatch();
    WP_Batch_Processor::get_instance()->register( $batch );

}
add_action( 'wp_batch_processing_init', 'wp_batch_processing_init', 15, 1 );