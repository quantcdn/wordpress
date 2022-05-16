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
	 * Class QuantCustomTaxonomies
	 */

	class QuantCustomTaxonomiesBatch extends Quant_WP_Batch {

		/**
		 * Unique identifier of each batch
		 * @var string
		 */
		public $id = 'quant_custom_taxonomies';

		/**
		 * Describe the batch
		 * @var string
		 */
		public $title = 'Custom taxonomy terms';

		/**
		 * To setup the batch data use the push() method to add Quant_WP_Batch_Item instances to the queue.
		 *
		 * Note: If the operation of obtaining data is expensive, cache it to avoid slowdowns.
		 *
		 * @return void
		 */
		public function setup() {

			$taxonomies = quant_get_all_taxonomies();

			// Determine number of pages for pagination iteration.
			$ppp = get_option( 'posts_per_page' );


			// Batch items for custom taxonomies start at 30000000.
			$c = 0;
			foreach ($taxonomies as $taxonomy) {

				// Core tag/categories have a separate handler.
				if ($taxonomy == "post_tag" || $taxonomy == "category") {
					continue;
				}

				$terms = get_terms( array(
					'taxonomy' => $taxonomy,
					'hide_empty' => false,
					'nopaging' => true,
				) );

				if (empty($terms)) {
					continue;
				}

				foreach ( $terms as $category ) {

					$pages = ceil($category->count / $ppp);

					// Push raw category URL.
					$this->push( new Quant_WP_Batch_Item( $category->term_id, array( 'term_id' => $category->term_id, ) ) );

					// Push paginated results within category.
					for ($i = 1; $i <= $pages; $i++) {

						// Batch items need unique integer ids
						// This will be problematic if term ids are over 10M.
						$uniqueSeed = 30000000 + $c;
						$itemId = $category->term_id + ($uniqueSeed);

						$this->push( new Quant_WP_Batch_Item( $itemId, array(
								'term_id' => $category->term_id,
								'page' => $i,
							)
						));

						$c++;
					}
				}
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
		 * @param Quant_WP_Batch_Item $item
		 *
		 * @return bool|\WP_Error
		 */
		public function process( $item ) {
			$term_id = $item->get_value( 'term_id' );
			$page = $item->get_value( 'page' );
			$this->client->sendCategory($term_id, $page);
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