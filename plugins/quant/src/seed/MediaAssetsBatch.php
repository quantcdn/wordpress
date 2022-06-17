<?php

use Quant\Client;

require_once(__DIR__.'/../../wp-batch-processing/includes/class-bp-singleton.php');
require_once(__DIR__.'/../../wp-batch-processing/includes/class-batch-item.php');
require_once(__DIR__.'/../../wp-batch-processing/includes/class-batch.php');
require_once(__DIR__.'/../../wp-batch-processing/includes/class-batch-processor.php');
require_once(__DIR__.'/../../wp-batch-processing/includes/class-batch-ajax-handler.php');
require_once(__DIR__.'/../../wp-batch-processing/includes/class-batch-list-table.php');
require_once(__DIR__.'/../../wp-batch-processing/includes/class-batch-processor-admin.php');

if ( class_exists( 'Quant_WP_Batch' ) ) {
	/**
	 * Class QuantMediaAssetsBatch
	 */
	class QuantMediaAssetsBatch extends Quant_WP_Batch {

		/**
		 * Unique identifier of each batch
		 * @var string
		 */
		public $id = 'quant_media_assets';

		/**
		 * Describe the batch
		 * @var string
		 */
		public $title = 'Media assets';

		/**
		 * To setup the batch data use the push() method to add Quant_WP_Batch_Item instances to the queue.
		 *
		 * Note: If the operation of obtaining data is expensive, cache it to avoid slowdowns.
		 *
		 * @return void
		 */
		public function setup() {

			$this->client = new Client();

			$args = array(
				'post_type' => 'attachment',
				'numberposts' => -1,
				'post_status' => null,
				'post_parent' => null, // any parent
				);
			$attachments = get_posts($args);
			if ($attachments) {
				foreach ($attachments as $post) {
					$file = get_attached_file ( $post->ID );
					$url = wp_get_attachment_url ( $post->ID );
					$route = parse_url($url, PHP_URL_PATH);
					$this->push( new Quant_WP_Batch_Item( $post->ID, array( 'route' => $route, 'file' => $file ) ) );
				}
			}
		}

		/**
		 * Handles processing of batch item. One at a time.
		 *
		 * In order to work it correctly you must return values as follows:
		 *
		 * - TRUE - If the item was processed successfully.
		 * - WP_Error instance - If there was an error. Add message to display it in the admin area.
		 *
		 * @param Quant_WP_Batch_Item $item
		 *
		 * @return bool|\WP_Error
		 */
		public function process( $item ) {
			$route = $item->get_value( 'route' );
			$file = $item->get_value( 'file' );
			$this->client->file($route, $file);
			return true;
		}

	}
}