<?php

use Quant\Client;

if ( class_exists( 'WP_Batch' ) ) {
	/**
	 * Class QuantPageBatch
	 */
	class QuantPageBatch extends WP_Batch {

		/**
		 * Unique identifier of each batch
		 * @var string
		 */
		public $id = 'quant_pages';

		/**
		 * Describe the batch
		 * @var string
		 */
		public $title = 'All pages';

		/**
		 * To setup the batch data use the push() method to add WP_Batch_Item instances to the queue.
		 *
		 * Note: If the operation of obtaining data is expensive, cache it to avoid slowdowns.
		 *
		 * @return void
		 */
		public function setup() {

			$posts = get_pages();

			foreach ( $posts as $post ) {
				$this->push( new WP_Batch_Item( $post->ID, array( 'post_id' => $post->ID ) ) );
            }

            $this->client = new Client();


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

			$post_id = $item->get_value( 'post_id' );

			$this->client->sendPost($post_id);
			return true;
		}

		/**
		 * Called when specific process is finished (all items were processed).
		 * This method can be overriden in the process class.
		 * @return void
		 */
		public function finish() {
			// Do something after process is finished.
			// You have $this->items, etc.
		}
	}
}