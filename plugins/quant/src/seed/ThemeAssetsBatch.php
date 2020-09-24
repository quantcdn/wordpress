<?php

use Quant\Client;

if ( class_exists( 'WP_Batch' ) ) {
	/**
	 * Class QuantThemeAssetsBatch
	 */
	class QuantThemeAssetsBatch extends WP_Batch {

		/**
		 * Unique identifier of each batch
		 * @var string
		 */
		public $id = 'quant_theme_assets';

		/**
		 * Describe the batch
		 * @var string
		 */
		public $title = 'All theme assets (css/js/images)';

		/**
		 * To setup the batch data use the push() method to add WP_Batch_Item instances to the queue.
		 *
		 * Note: If the operation of obtaining data is expensive, cache it to avoid slowdowns.
		 *
		 * @return void
		 */
		public function setup() {

			$this->client = new Client();

			$path = get_stylesheet_directory();
			$directoryIterator = new \RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
			$iterator = new \RecursiveIteratorIterator($directoryIterator);
			$regex = new \RegexIterator($iterator, '/^.+(.jpe?g|.png|.svg|.ttf|.woff|.woff2|.otf|.ico|.css|.js)$/i', \RecursiveRegexIterator::GET_MATCH);

			$i = 1;
			foreach ($regex as $name => $r) {
				$route = str_replace(ABSPATH, '/', $name);
				$this->push( new WP_Batch_Item( $i, array( 'route' => $route, 'file' => $name ) ) );
				$i++;
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
			$file = $item->get_value( 'file' );
			$this->client->file($route, $file);
			return true;
		}

	}
}