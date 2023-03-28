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
	 * Class QuantArchivesBatch
	 */
	class QuantArchivesBatch extends Quant_WP_Batch {

		/**
		 * Unique identifier of each batch
		 * @var string
		 */
		public $id = 'quant_archives';

		/**
		 * Describe the batch
		 * @var string
		 */
		public $title = 'Archives';

		/**
		 * Retrieves the archives and determines URLs and pagination.
		 *
		 * @todo: Replace with more programmatic approach (e.g replicate wp_get_archives)
		 * wp_get_archives returns a string that needs parsing and splitting into chunks.
		 *
		 * @return void
		 */
		public function setup() {

			$this->client = new Client();

			$args = [
				'echo' => FALSE,
				'format'          => 'custom',
				'before'          => '',
				'after'           => '|',
				'show_post_count' => TRUE
			];

			$archives = wp_get_archives($args);

			if (!empty($archives) ) {

				// Determine number of pages for pagination iteration.
				$ppp = get_option( 'posts_per_page' );

				$years_arr = explode('|', $archives);

				foreach($years_arr as $year_index => $year_item) {
					$year_row = trim($year_item);
					preg_match('/href=["\']?([^"\'>]+)["\']>(.+)<\/a>(.+)/', $year_row, $year_vars);

					if (empty($years_match[3])) {
						continue;
					}

					$count = (int) filter_var($year_vars[3], FILTER_SANITIZE_NUMBER_INT);
					$pages = ceil($count / $ppp);
					$url = wp_make_link_relative($year_vars[1]);

					if (!empty($year_vars)) {
						$this->push( new Quant_WP_Batch_Item( $year_index, array(
								'route' => "{$url}",
							)
						));
					}

					// Add pagination on archives pages.
					for ($i = 1; $i <= $pages; $i++) {
						// Batch items need unique integer ids
						$itemId = $year_index + (10000000 + $i);
						$this->push( new Quant_WP_Batch_Item( $itemId, array(
								'route' => "{$url}page/{$i}/",
							)
						));
					}
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
			$this->client->sendRoute($route);
			return true;
		}

	}
}
