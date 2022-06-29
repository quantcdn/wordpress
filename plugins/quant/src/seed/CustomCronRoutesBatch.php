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
	 * Class QuantCustomCronRoutesBatch
	 */
	class QuantCustomCronRoutesBatch extends Quant_WP_Batch {

		/**
		 * Unique identifier of each batch
		 * @var string
		 */
		public $id = 'quant_custom_cron_routes';

		/**
		 * Describe the batch
		 * @var string
		 */
		public $title = 'Custom routes (cron)';

		/**
		 * To setup the batch data use the push() method to add Quant_WP_Batch_Item instances to the queue.
		 *
		 * Note: If the operation of obtaining data is expensive, cache it to avoid slowdowns.
		 *
		 * @return void
		 */
		public function setup() {

			$this->client = new Client();

			$seedOptions = get_option(QUANT_CRON_SETTINGS_KEY);
			$routes = explode("\n", $seedOptions['cron_custom_routes']);

			foreach ($routes as $i => $route) {

				$route = trim($route);
				if (empty($route)) {
					continue;
				}

				$this->push( new Quant_WP_Batch_Item( $i, array( 'route' => $route ) ) );

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
			$this->client->sendRoute($route);
			return true;
		}

	}
}