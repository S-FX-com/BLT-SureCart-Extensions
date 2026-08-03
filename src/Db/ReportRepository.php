<?php
/**
 * CRUD access to the local reports table — the index of generated report
 * runs. The CSV payload itself never lives in the database; it's written to
 * a protected uploads subdirectory by Modules\Reports\ReportStorage and
 * referenced here by filename only.
 *
 * As with ShipmentRepository, this is the only place $wpdb is touched for
 * this table.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReportRepository
 */
final class ReportRepository {

	const STATUS_QUEUED   = 'queued';
	const STATUS_RUNNING  = 'running';
	const STATUS_COMPLETE = 'complete';
	const STATUS_FAILED   = 'failed';

	const TYPE_FULFILLMENT = 'fulfillment';

	/**
	 * Table name helper.
	 *
	 * @return string
	 */
	private function table() {
		return Schema::reports_table();
	}

	/**
	 * Create a queued report row and return its ID.
	 *
	 * @param string $type    Report type.
	 * @param array  $params  Request parameters, stored as JSON for audit.
	 * @param int    $user_id User who requested it.
	 * @return int|null Row ID, or null on failure.
	 */
	public function create( $type, array $params, $user_id ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'type'       => (string) $type,
				'status'     => self::STATUS_QUEUED,
				'params'     => wp_json_encode( $params ),
				'created_by' => (int) $user_id,
				'created_at' => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return null;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a single report row.
	 *
	 * @param int $id Row ID.
	 * @return array|null Row as an associative array, or null when missing.
	 */
	public function find( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', (int) $id ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Most recent reports, newest first.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array[] Rows as associative arrays.
	 */
	public function recent( $limit = 25 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' ORDER BY id DESC LIMIT %d', max( 1, (int) $limit ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark a report as running.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	public function mark_running( $id ) {
		$this->update( $id, array( 'status' => self::STATUS_RUNNING ) );
	}

	/**
	 * Mark a report complete, recording the file it produced and the counts
	 * behind it (including how many orders were scanned, so a surprising
	 * result can be reasoned about after the fact).
	 *
	 * @param int    $id       Row ID.
	 * @param string $filename Basename of the generated CSV.
	 * @param array  $counts   Keys: row_count, column_count, item_count, orders_matched, orders_scanned, truncated.
	 * @return void
	 */
	public function mark_complete( $id, $filename, array $counts ) {
		$this->update(
			$id,
			array(
				'status'         => self::STATUS_COMPLETE,
				'filename'       => (string) $filename,
				'row_count'      => isset( $counts['row_count'] ) ? (int) $counts['row_count'] : 0,
				'column_count'   => isset( $counts['column_count'] ) ? (int) $counts['column_count'] : 0,
				'item_count'     => isset( $counts['item_count'] ) ? (int) $counts['item_count'] : 0,
				'orders_matched' => isset( $counts['orders_matched'] ) ? (int) $counts['orders_matched'] : 0,
				'orders_scanned' => isset( $counts['orders_scanned'] ) ? (int) $counts['orders_scanned'] : 0,
				'truncated'      => ! empty( $counts['truncated'] ) ? 1 : 0,
				'last_error'     => null,
				'completed_at'   => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Mark a report failed with a human-readable reason.
	 *
	 * @param int    $id    Row ID.
	 * @param string $error Failure reason.
	 * @return void
	 */
	public function mark_failed( $id, $error ) {
		$this->update(
			$id,
			array(
				'status'       => self::STATUS_FAILED,
				'last_error'   => substr( (string) $error, 0, 1000 ),
				'completed_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Delete a report row.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	public function delete( $id ) {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Decode a row's stored params JSON.
	 *
	 * @param array $row Report row.
	 * @return array Decoded params, or an empty array.
	 */
	public function params( array $row ) {
		if ( empty( $row['params'] ) ) {
			return array();
		}

		$decoded = json_decode( (string) $row['params'], true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Low-level column update.
	 *
	 * @param int   $id   Row ID.
	 * @param array $data Column => value pairs.
	 * @return void
	 */
	private function update( $id, array $data ) {
		global $wpdb;

		$wpdb->update( $this->table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
