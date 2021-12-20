<?php
/********************************************************************
 * Copyright (C) 2019 Darko Gjorgjijoski (https://darkog.com)
 *
 * This file is part of WP Batch Processing
 *
 * WP Batch Processing is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * WP Batch Processing is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with WP Batch Processing. If not, see <https://www.gnu.org/licenses/>.
 **********************************************************************/

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access is not allowed.' );
}

/**
 * Class Quant_WP_Batch
 *
 * Extend this class to create your own batch
 *
 * Note: You must register the instance in the quant_processing_init hook
 * in order to show in the admin area.
 *
 * eg. Quant_WP_Batch_Processor::get_instance()->register( $batch );
 */
abstract class Quant_WP_Batch {

	/**
	 * Unique identifier of each batch
	 * @var string
	 */
	public $id = 'my_first_batch';

	/**
	 * Describe the batch
	 * @var string
	 */
	public $title = 'My first batch';

	/**
	 * Data store of batch items
	 * @var Quant_WP_Batch_Item[]
	 */
	protected $items = array();

	/**
	 * Initialize
	 */
	public function __construct() {
		$this->setup();
	}

	/**
	 * To setup the batch data use the push() method to add Quant_WP_Batch_Item instances to the queue.
	 *
	 * Note: If the operation of obtaining data is expensive, cache it to avoid slowdowns.
	 *
	 * @return void
	 */
	abstract public function setup();

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
	abstract public function process( $item );

	/**
	 * Called when specific process is finished (all items were processed).
	 * This method can be overriden in the process class.
	 * @return void
	 */
	public function finish() {}

	/**
	 * Queues the item for processing.
	 *
	 * @param Quant_WP_Batch_Item $item
	 */
	protected function push( $item ) {
		if ( ! is_array( $this->items ) ) {
			$this->items = array();
		}
		$this->items[] = $item;
	}


	/**
	 * Returns false if no items left for processing or Quant_WP_Batch_Item object for processing.
	 *
	 * @param Quant_WP_Batch_Item $item
	 *
	 * @return bool|Quant_WP_Batch_Item
	 */
	public function get_next_item() {
		$processed = $this->get_processed_items();
		foreach ( $this->items as $item ) {
			$claimed = $this->is_claimed( $item->id );

			if ( ! in_array( $item->id, $processed ) && !$claimed ) {
				return $item;
			}
		}

		return false;
	}

	/**
	 * Check if the batch is finished.
	 * @return bool
	 */
	public function is_finished() {
		$is_finished = true;
		foreach ( $this->items as $item ) {
			if ( ! $this->is_processed( $item ) ) {
				$is_finished = false;
			}
		}

		return $is_finished;
	}


	/**
	 * Check if batch item was processed.
	 *
	 * @param Quant_WP_Batch_Item $item
	 *
	 * @return bool
	 */
	public function is_processed( $item ) {
		return get_option( $this->get_db_identifier( $item->id ), FALSE );
	}


	/**
	 * Returns TRUE if item is already claimed
	 *
	 * $param $id
	 *
	 * @return bool
	 */
	public function is_claimed( $id ) {
		return get_option( $this->get_claimed_db_identifier($id), FALSE );
	}

	/**
	 * Returns processed batch item ids
	 * @return array
	 */
	public function get_processed_items() {

		$items = [];

		foreach ($this->items as $item) {
			if ($this->is_processed ( $item ) ) {
				$items[] = $item->id;
			}
		}

		return $items;
	}


	/**
	 * Returns the count of the processed items
	 * @return int
	 */
	public function get_processed_count() {
		return count( $this->get_processed_items() );
	}

	/**
	 * Mark specific id as processed.
	 *
	 * @param int $id
	 */
	public function mark_as_processed( $id ) {
		$processed = $this->get_processed_items();
		array_push( $processed, $id );
		$processed = array_unique( $processed );
		update_option( $this->get_db_identifier( $id ), $processed );
	}

	/**
	 * Mark specific id as claimed.
	 *
	 * @param int $id
	 */
	public function mark_as_claimed( $id ) {
		update_option( $this->get_claimed_db_identifier( $id ), TRUE );
	}

	/**
	 * Returns the count of the total items
	 * @return int
	 */
	public function get_items_count() {
		return count( $this->items );
	}

	/**
	 * Returns the percentage
	 * @return float
	 */
	public function get_percentage() {
		$total_items     = $this->get_items_count();
		$total_processed = $this->get_processed_count();
		$percentage      = ( ! empty( $total_items ) ) ? 100 - ( ( ( $total_items - $total_processed ) / $total_items ) * 100 ) : 0;

		return number_format( (float) $percentage, 2, '.', '' );
	}

	/**
	 * Returns the batch wp_options option_name identifier.
	 * @return string
	 */
	public function get_db_identifier( $id ) {
		return 'batch_' . $this->id . '_' . $id . '_processed';
	}

	/**
	 * Returns the batch wp_options option_name identifier.
	 * @return string
	 */
	public function get_claimed_db_identifier( $item_id ) {
		return 'batch_' . $this->id . '_' . $item_id . '_claimed';
	}

	/**
	 * Resets claims but leaves processed state
	 */
	public function restart_claims() {
		foreach ($this->items as $item) {
			delete_option( $this->get_claimed_db_identifier( $item->id ) );
		}
	}

	/**
	 * Restarts the processed items store
	 */
	public function restart() {
		// Remove all claims and processed items.
		foreach ($this->items as $item) {
			delete_option( $this->get_db_identifier( $item->id ) );
			delete_option( $this->get_claimed_db_identifier( $item->id ) );
		}
	}

}
