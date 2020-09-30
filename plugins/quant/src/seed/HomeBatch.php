<?php

use Quant\Client;

if ( class_exists( 'Quant_WP_Batch' ) ) {
	/**
	 * Class QuantHomeBatch
	 */
	class QuantHomeBatch extends Quant_WP_Batch {

		/**
		 * Unique identifier of each batch
		 * @var string
		 */
		public $id = 'quant_home';

		/**
		 * Describe the batch
		 * @var string
		 */
		public $title = 'Home (and associated pages)';

		/**
		 * To setup the batch data use the push() method to add Quant_WP_Batch_Item instances to the queue.
		 *
		 * Note: If the operation of obtaining data is expensive, cache it to avoid slowdowns.
		 *
		 * @return void
		 */
		public function setup() {

			$this->client = new Client();

			// Homepage may either be as simple as pushing the "/" route.
			// *or* pagination list when using "latest posts"

			$this->push( new Quant_WP_Batch_Item( 0, array( 'route' => "/" ) ) );

			if ( get_option( 'show_on_front' ) == "page" ) {
				return;
			}

			$posts = get_posts( [ 'nopaging' => true ] );

			// Determine number of pages for pagination iteration.
			$ppp = get_option( 'posts_per_page' );

			$pages = ceil(count($posts) / $ppp);

			// Push paginated results within category.
			for ($i = 1; $i <= $pages; $i++) {
				$this->push( new Quant_WP_Batch_Item( $i, array(
						'route' => "/page/$i/",
					)
				));
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