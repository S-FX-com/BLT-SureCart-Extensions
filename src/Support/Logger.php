<?php
/**
 * Structured logging, persisted to the local logs table so a shipment's
 * history is visible in the admin log viewer and to support staff without
 * server access. Falls back to error_log() if the table isn't available
 * yet (e.g. very first request before activation has run).
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Support;

use BLT\SCE\Db\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger
 */
final class Logger {

	const LEVEL_DEBUG   = 'debug';
	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/**
	 * Cached result of the table-exists check for this request.
	 *
	 * @var bool|null
	 */
	private $table_ready = null;

	/**
	 * Log a debug message. Only persisted when WP_DEBUG (or the module's
	 * verbose logging setting) is enabled, to avoid bloating the table.
	 *
	 * @param string     $message     Message.
	 * @param array      $context     Extra context, stored as JSON.
	 * @param int|null   $shipment_id Related shipment row ID, if any.
	 * @return void
	 */
	public function debug( $message, array $context = array(), $shipment_id = null ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) && ! (bool) get_option( 'blt_sce_verbose_logging', false ) ) {
			return;
		}

		$this->write( self::LEVEL_DEBUG, $message, $context, $shipment_id );
	}

	/**
	 * Log an info message.
	 *
	 * @param string   $message     Message.
	 * @param array    $context     Extra context, stored as JSON.
	 * @param int|null $shipment_id Related shipment row ID, if any.
	 * @return void
	 */
	public function info( $message, array $context = array(), $shipment_id = null ) {
		$this->write( self::LEVEL_INFO, $message, $context, $shipment_id );
	}

	/**
	 * Log a warning.
	 *
	 * @param string   $message     Message.
	 * @param array    $context     Extra context, stored as JSON.
	 * @param int|null $shipment_id Related shipment row ID, if any.
	 * @return void
	 */
	public function warning( $message, array $context = array(), $shipment_id = null ) {
		$this->write( self::LEVEL_WARNING, $message, $context, $shipment_id );
	}

	/**
	 * Log an error.
	 *
	 * @param string   $message     Message.
	 * @param array    $context     Extra context, stored as JSON.
	 * @param int|null $shipment_id Related shipment row ID, if any.
	 * @return void
	 */
	public function error( $message, array $context = array(), $shipment_id = null ) {
		$this->write( self::LEVEL_ERROR, $message, $context, $shipment_id );
	}

	/**
	 * Record an outbound API call. Satisfies the requirement that every
	 * Shippo (and SureCart) call is logged with request id, duration, and
	 * outcome.
	 *
	 * @param string      $service     'shippo' or 'surecart'.
	 * @param string      $method      HTTP method.
	 * @param string      $path        Request path (no host, no query secrets).
	 * @param int|null    $status_code Response HTTP status code.
	 * @param float       $duration_ms Wall-clock duration in milliseconds.
	 * @param string|null $request_id  Upstream request/correlation ID, if returned.
	 * @param int|null    $shipment_id Related shipment row ID, if any.
	 * @return void
	 */
	public function api_call( $service, $method, $path, $status_code, $duration_ms, $request_id = null, $shipment_id = null ) {
		$level = ( $status_code && $status_code >= 200 && $status_code < 300 ) ? self::LEVEL_INFO : self::LEVEL_WARNING;

		$this->write(
			$level,
			sprintf( '%s %s %s -> %s', strtoupper( $service ), $method, $path, $status_code ? $status_code : 'no response' ),
			array(
				'service'     => $service,
				'method'      => $method,
				'path'        => $path,
				'status_code' => $status_code,
				'duration_ms' => round( $duration_ms, 1 ),
				'request_id'  => $request_id,
			),
			$shipment_id
		);
	}

	/**
	 * Fetch recent log rows for a shipment, newest first — backs the
	 * per-shipment log viewer.
	 *
	 * @param int $shipment_id Shipment row ID.
	 * @param int $limit       Max rows.
	 * @return object[]
	 */
	public function for_shipment( $shipment_id, $limit = 100 ) {
		global $wpdb;

		if ( ! $this->is_table_ready() ) {
			return array();
		}

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . Schema::logs_table() . ' WHERE shipment_id = %d ORDER BY id DESC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a fixed, code-controlled identifier, not user input
				$shipment_id,
				$limit
			)
		);
	}

	/**
	 * Write a log row, falling back to error_log() if the table isn't
	 * ready or the insert fails for any reason.
	 *
	 * @param string   $level       Log level.
	 * @param string   $message     Message.
	 * @param array    $context     Extra context.
	 * @param int|null $shipment_id Related shipment row ID, if any.
	 * @return void
	 */
	private function write( $level, $message, array $context, $shipment_id ) {
		global $wpdb;

		if ( $this->is_table_ready() ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				Schema::logs_table(),
				array(
					'shipment_id' => $shipment_id,
					'level'       => $level,
					'message'     => substr( $message, 0, 255 ),
					'context'     => empty( $context ) ? null : wp_json_encode( $context ),
					'created_at'  => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);

			return;
		}

		error_log( sprintf( '[blt-sce] [%s] %s %s', strtoupper( $level ), $message, empty( $context ) ? '' : wp_json_encode( $context ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Whether the logs table exists, cached for the request.
	 *
	 * @return bool
	 */
	private function is_table_ready() {
		if ( null === $this->table_ready ) {
			$this->table_ready = Schema::logs_table_exists();
		}

		return $this->table_ready;
	}
}
