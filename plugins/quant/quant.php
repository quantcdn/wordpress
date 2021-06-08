<?php

/**
 * Plugin Name: Quant
 * Description: QuantCDN static edge integration
 * Author: Stuart Rowlands
 * Plugin URI: https://www.quantcdn.io
 * Version: 1.2.1
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
function quant_wp_batch_processing_init() {
    $batch = new QuantPageBatch();
    Quant_WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantPostBatch();
    Quant_WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantCustomPostTypesBatch();
    Quant_WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantCategoryBatch();
    Quant_WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantTagBatch();
    Quant_WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantCustomTaxonomiesBatch();
    Quant_WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantHomeBatch();
    Quant_WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantThemeAssetsBatch();
    Quant_WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantCustomRoutesBatch();
    Quant_WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantArchivesBatch();
    Quant_WP_Batch_Processor::get_instance()->register( $batch );
    $batch = new QuantMediaAssetsBatch();
    Quant_WP_Batch_Processor::get_instance()->register( $batch );

}
add_action( 'quant_wp_batch_processing_init', 'quant_wp_batch_processing_init', 99, 1 );

/**
 * Roundabout way of adding a post field.
 *
 * @param Resource $handle
 *   A cURL resource handler.
 */
function quant_attach_file($handle) {
    $info = curl_getinfo($handle);
    $url = parse_url($info['url']);
    $settings = get_option(QUANT_SETTINGS_KEY);
    $api_endpoint = $settings['api_endpoint'] . '/v1';

    // We're only concerned about intercepting Quant calls.
    if (strpos($info['url'], $api_endpoint) === -1) {
        return;
    }

    // This is not a real API route, this is something specific
    // to catch in this hook so that we can create the file
    // stream with cURL.
    if ($url['path'] != '/v1/file-upload') {
        return;
    }

    parse_str($url['query'], $query);

    if (empty($query['path'])) {
        return;
    }

    // Build the CURL options to stream the files.
    curl_setopt($handle, CURLOPT_URL, $api_endpoint);

    $data['data'] = curl_file_create(
        $query['path'],
        mime_content_type($query['path']),
        basename($query['path'])
    );

    curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
}
add_action('http_api_curl', 'quant_attach_file');


add_filter( 'template_include', 'quant_include_validation_template', 99 );
function quant_include_validation_template( $template ) {

    global $wp;

    if ( strpos( $wp->request, "__quant-validate" ) !== false ) {
        $page_template = QUANT_TEMPLATE_DIR . '/quant-validate.php';
        return $page_template;
    }

	return $template;
}
