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
 * Class Quant_WP_Batch_Processing_Ajax_Handler
 */
class Quant_WP_Batch_Processing_Ajax_Handler {

	use Quant_WP_BP_Singleton;

	/**
	 * Setup the ajax endpoints
	 */
	protected function init() {
		add_action( 'wp_ajax_dg_process_next_batch_item', array( $this, 'process_next_item' ) );
		add_action( 'wp_ajax_dg_restart_batch', array( $this, 'restart_batch' ) );
	}

	/**
	 * This is used to handle the processing of each item
	 * and return the status to inform the user.
	 */
	public function process_next_item() {

		// Check ajax referrer
		if ( ! check_ajax_referer( Quant_WP_Batch_Processor_Admin::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array(
				'message' => 'Permission denied.',
			) );
			exit();
		}

		// Validate the batch id.
		$batch_id = isset( $_REQUEST['batch_id'] ) ? sanitize_key($_REQUEST['batch_id']) : false;
		if ( ! $batch_id ) {
			wp_send_json_error( array(
				'message' => 'Invalid batch id',
			) );
			exit();
		}


		// When batch is 'all' find the first batch with an item to process.
		if ($batch_id == 'all') {

			$total_items      = 0;
			$total_processed  = 0;
			$percentage       = 0;

			$batches = Quant_WP_Batch_Processor::get_instance()->get_batches();

			foreach ($batches as $i => $batch_count ) {
				$total_items      += $batch_count->get_items_count();
				$total_processed  += $batch_count->get_processed_count();
			}

			foreach ($batches as $i => $batch ) {

				// Process the next item.
				$next_item = $batch->get_next_item();

				// All batches are finished.
				$is_finished = ( false === $next_item && ($i == count($batches) - 1) );

				// Break the loop and process the item.
				if (!$is_finished && !empty($next_item)) {
					break;
				}
			}

			$percentage  = round(($total_processed / $total_items) * 100, 2);
		}
		else {

			// Get the batch object
			$batch = Quant_WP_Batch_Processor::get_instance()->get_batch( $batch_id );

			$total_items      = $batch->get_items_count();
			$total_processed  = $batch->get_processed_count();
			$percentage       = $batch->get_percentage();

			// Process the next item.
			$next_item = $batch->get_next_item();

			// No next item for processing. The batch processing is finished, probably.
			$is_finished = ( false === $next_item );

			if ($is_finished) {
				$batch->finish();
			}
		}

		if ( $is_finished ) {
			wp_send_json_success( array(
				'message'         => apply_filters( 'dg_batch_item_error_message', __( 'Processing finished.', 'quant-wp-batch-processing' ) ),
				'is_finished'     => 1,
				'total_processed' => $total_processed,
				'total_items'     => $total_items,
				'percentage'      => $percentage,
			) );
		} else {
			@set_time_limit( 0 );
			$response = $batch->process( $next_item );
			$batch->mark_as_processed( $next_item->id );
			if ( is_wp_error( $response ) ) {
				$error_message = apply_filters( 'dg_batch_item_error_message', 'Error processing item with id ' . $next_item->id . ': ' . $response->get_error_message(), $next_item );
				wp_send_json_error( array(
					'message'         => $error_message,
					'is_finished'     => 0,
					'total_processed' => $total_processed,
					'total_items'     => $total_items,
					'percentage'      => $percentage,
				) );
			} else {
				$success_message = apply_filters( 'dg_batch_item_success_message', 'Processed item with id ' . $next_item->id, $next_item );
				wp_send_json_success( array(
					'message'         => $success_message,
					'is_finished'     => 0,
					'total_processed' => $total_processed,
					'total_items'     => $total_items,
					'percentage'      => $percentage,
				) );
			}
		}
		exit;
	}

	/**
	 * Used to restart the batch.
	 * Just clear the data.
	 */
	public function restart_batch() {
		// Check ajax referrer
		if ( ! check_ajax_referer( Quant_WP_Batch_Processor_Admin::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array(
				'message' => 'Permission denied.',
			) );
			exit;
		}
		// Validate the batch id.
		$batch_id = isset( $_REQUEST['batch_id'] ) ? sanitize_key($_REQUEST['batch_id']) : false;
		if ( ! $batch_id ) {
			wp_send_json_error( array(
				'message' => 'Invalid batch id',
			) );
			exit;
		}

		// All batches.
		if ($batch_id == 'all') {
			$batches = Quant_WP_Batch_Processor::get_instance()->get_batches();
			foreach ($batches as $batch ) {
				// Restart the batch.
				$batch->restart();
			}
		}
		else {
			// Get the batch object
			$batch = Quant_WP_Batch_Processor::get_instance()->get_batch( $batch_id );
			// Restart the batch.
			$batch->restart();
		}

		// Send json
		wp_send_json_success();
	}
}

// Init
Quant_WP_Batch_Processing_Ajax_Handler::get_instance();