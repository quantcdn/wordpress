<?php

/**
 * Plugin Name: Quant
 * Description: QuantCDN static edge integration
 * Author: www.quantcdn.io
 * Plugin URI: https://www.quantcdn.io
 * Version: 1.5.1
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once(__DIR__.'/src/App.php');
require_once(__DIR__.'/wp-batch-processing/wp-batch-processing.php');

if (!function_exists('is_plugin_active')) {
  include_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

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
function quant_activate()
{
    add_option(QUANT_SETTINGS_KEY, [
        'enabled' => 0,
        'api_endpoint' => 'https://api.quantcdn.io',
        'webserver_url' => '',
        'webserver_host' => '',
        'api_project' => '',
        'api_account' => '',
        'api_token' => '',
        'disable_tls_verify' => true,
        'http_request_timeout' => 15,
    ]);

    add_option(QUANT_CRON_SETTINGS_KEY, [
        'cron_enabled' => false,
        'cron_schedule' => '',
    ]);

    add_option(QUANT_SEED_KEY, [
        'custom_routes' => '',
        '404_route' => '',
    ]);

    // Generate a random string for internal token.
    $token = get_option("quant_internal_token");

    if (empty($token)) {
        update_option("quant_internal_token", substr(str_shuffle(MD5(microtime())), 0, 10));
    }
}

register_activation_hook(__FILE__, 'quant_activate');

function quant_deactivate() {
    delete_option("quant_internal_token");
    delete_option(QUANT_SETTINGS_KEY);
    delete_option(QUANT_CRON_SETTINGS_KEY);
    delete_option(QUANT_SEED_KEY);
}

register_deactivation_hook(__FILE__, 'quant_deactivate');


function quant_load() {
    include_once('src/Client.php');
}

add_action('plugins_loaded', 'quant_load');


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
    // Initialise redirection support.
    if ( is_plugin_active('redirection/redirection.php') ) {
        $batch = new QuantRedirectionBatch();
        Quant_WP_Batch_Processor::get_instance()->register( $batch );
    }

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

    if (!file_exists($query['path'])) {
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
