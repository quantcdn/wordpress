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
	 * Class QuantCategoryBatch
	 */
	class QuantRedirectionBatch extends Quant_WP_Batch {

		/**
		 * Unique identifier of each batch
		 * @var string
		 */
		public $id = 'quant_redirection';

		/**
		 * Describe the batch
		 * @var string
		 */
		public $title = 'Redirects';

		/**
		 * To setup the batch data use the push() method to add Quant_WP_Batch_Item instances to the queue.
		 *
		 * Note: If the operation of obtaining data is expensive, cache it to avoid slowdowns.
		 *
		 * @return void
		 */
		public function setup() {

			// Quit the scene if redirection plugin is not active.
			if ( !is_plugin_active('redirection/redirection.php') ) {
				return;
			}

			// This provides basic support only.
			// URL match only, 301/302 responses, and no regex support.
			// Query parameters will always be respected.
			$redirects = Red_Item::get_all();

			foreach ($redirects as $r) {
				if ($r->is_regex()) {
					continue;
				}

				// We only support URL matches.
				if ($r->get_match_type() != 'url') {
					continue;
				}

				// We only support URL redirects.
				if ($r->get_action_type() != 'url') {
					continue;
				}

				// We only support enabled URL redirects.
				if (!$r->is_enabled()) {
					continue;
				}

				// If the action is not a string we cannot process.
				if (!is_string($r->get_action_data())) {
					continue;
				}

				$code = $r->get_action_code() == '301' ? 301 : 302;

				$this->push( new Quant_WP_Batch_Item( $r->get_id(), array(
					'source' => $r->get_url(),
					'dest' => $r->get_action_data(),
					'code' => $code
				) ) );

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
			$source = $item->get_value( 'source' );
			$dest = $item->get_value( 'dest' );
			$code = $item->get_value( 'code' );
			$this->client->redirect($source, $dest, $code);
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