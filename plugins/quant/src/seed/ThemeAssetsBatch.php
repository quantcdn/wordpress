<?php

use Quant\Client;

require_once(__DIR__.'/../../wp-batch-processing/includes/class-bp-singleton.php');
require_once(__DIR__.'/../../wp-batch-processing/includes/class-batch-item.php');
require_once(__DIR__.'/../../wp-batch-processing/includes/class-batch.php');
require_once(__DIR__.'/../../wp-batch-processing/includes/class-batch-processor.php');
require_once(__DIR__.'/../../wp-batch-processing/includes/class-batch-ajax-handler.php');
require_once(__DIR__.'/../../wp-batch-processing/includes/class-batch-list-table.php');
require_once(__DIR__.'/../../wp-batch-processing/includes/class-batch-processor-admin.php');
require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

if ( class_exists( 'Quant_WP_Batch' ) ) {
	/**
	 * Class QuantThemeAssetsBatch
	 */
	class QuantThemeAssetsBatch extends Quant_WP_Batch {

		/**
		 * Unique identifier of each batch
		 * @var string
		 */
		public $id = 'quant_theme_assets';

		/**
		 * Describe the batch
		 * @var string
		 */
		public $title = 'Theme assets (css/js/images/fonts)';

		/**
		 * The regex for asset matching.
		 */
		public $assetRegex = '/^.+(\.jpe?g|\.png|\.svg|\.ttf|\.woff|\.woff2|\.otf|\.ico|\.css|\.js)$/i';

		/**
		 * The iteration count.
		 */
		private $count = 1;

		/**
		 * Process a folder.
		 *
		 * @param $path
		 *   Absolute path on disk to a folder to iterate
		 */
		private function processDirectory($path) {
			$directoryIterator = new \RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
			$iterator = new \RecursiveIteratorIterator($directoryIterator);
			$regex = new \RegexIterator($iterator, $this->assetRegex, \RecursiveRegexIterator::GET_MATCH);

			foreach ($regex as $name => $r) {
				// Skip node_modules.
				if (preg_match('/node_modules/i', $name)) {
					continue;
				}

				$route = str_replace(ABSPATH, '/', $name);
				$this->push( new Quant_WP_Batch_Item( $this->count, array( 'route' => $route, 'file' => $name ) ) );
				$this->count++;
			}
		}

		/**
		 * To setup the batch data use the push() method to add Quant_WP_Batch_Item instances to the queue.
		 *
		 * Note: If the operation of obtaining data is expensive, cache it to avoid slowdowns.
		 *
		 * @return void
		 */
		public function setup() {

			$this->client = new Client();

			$path = get_stylesheet_directory();
			$this->processDirectory($path);

			// Include static elementor assets if present.
			$elementor = WP_PLUGIN_DIR . '/elementor';

			if ( is_dir( $elementor ) && is_plugin_active('elementor/elementor.php') ) {
				$this->processDirectory($elementor);
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