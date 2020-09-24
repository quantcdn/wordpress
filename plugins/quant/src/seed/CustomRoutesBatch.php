<?php

use Quant\Client;

if ( class_exists( 'WP_Batch' ) ) {
	/**
	 * Class QuantCustomRoutesBatch
	 */
	class QuantCustomRoutesBatch extends WP_Batch {

		/**
		 * Unique identifier of each batch
		 * @var string
		 */
		public $id = 'quant_custom_routes';

		/**
		 * Describe the batch
		 * @var string
		 */
		public $title = 'Custom routes and 404 page';

		/**
		 * To setup the batch data use the push() method to add WP_Batch_Item instances to the queue.
		 *
		 * Note: If the operation of obtaining data is expensive, cache it to avoid slowdowns.
		 *
		 * @return void
		 */
		public function setup() {

			$this->client = new Client();

			$seedOptions = get_option(QUANT_SEED_KEY);
			$routes = explode("\n", $seedOptions['custom_routes']);

			foreach ($routes as $i => $route) {
				$this->push( new WP_Batch_Item( $i, array( 'route' => $route ) ) );
			}

			// Special case for 404 page.
			if (!empty($seedOptions['404_route'])) {
				$this->push( new WP_Batch_Item( count($routes) + 1, array( 'route' => $seedOptions['404_route'], 'is_404' => true ) ) );
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
		 * @param WP_Batch_Item $item
		 *
		 * @return bool|\WP_Error
		 */
		public function process( $item ) {
			$route = $item->get_value( 'route' );
			$is_404 = $item->get_value( 'is_404' );

			if ($is_404) {
				error_log("SEEDING THE 404 ROUTE");
				$this->client->send404Route($route);
				return true;
			}

			$this->client->sendRoute($route);
			return true;
		}

	}
}