<?php
/**
 * CRUD access to the local shipments table. The UNIQUE key on
 * surecart_order_id (enforced in Schema, not just here) is the primary
 * defense against double-purchasing a label.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ShipmentRepository
 */
final class ShipmentRepository {

	const STATUS_PENDING    = 'pending';
	const STATUS_QUOTED     = 'quoted';
	const STATUS_REVIEW     = 'review';
	const STATUS_PURCHASED  = 'purchased';
	const STATUS_SHIPPED    = 'shipped';
	const STATUS_IN_TRANSIT = 'in_transit';
	const STATUS_DELIVERED  = 'delivered';
	const STATUS_EXCEPTION  = 'exception';
	const STATUS_VOIDED     = 'voided';
	const STATUS_FAILED     = 'failed';

	/**
	 * Statuses that will never change on their own again. Everything else
	 * is a candidate for the reconciliation sweep.
	 *
	 * @return string[]
	 */
	public static function terminal_statuses() {
		return array( self::STATUS_DELIVERED, self::STATUS_VOIDED, self::STATUS_FAILED );
	}

	/**
	 * All known statuses, in the rough order a shipment progresses through.
	 *
	 * @return string[]
	 */
	public static function all_statuses() {
		return array(
			self::STATUS_PENDING,
			self::STATUS_QUOTED,
			self::STATUS_REVIEW,
			self::STATUS_PURCHASED,
			self::STATUS_SHIPPED,
			self::STATUS_IN_TRANSIT,
			self::STATUS_DELIVERED,
			self::STATUS_EXCEPTION,
			self::STATUS_VOIDED,
			self::STATUS_FAILED,
		);
	}

	/**
	 * Table name helper.
	 *
	 * @return string
	 */
	private function table() {
		return Schema::shipments_table();
	}

	/**
	 * Idempotently ensure a shipment row exists for this order.
	 *
	 * Uses INSERT IGNORE against the UNIQUE surecart_order_id key so a
	 * duplicate trigger (same webhook delivered twice, two concurrent
	 * job runs) can never create a second row. The caller inspects
	 * `created` to decide whether this is the first time this order has
	 * been seen.
	 *
	 * @param string $order_id SureCart order ID.
	 * @return array{row: object|null, created: bool}
	 */
	public function find_or_create_for_order( $order_id ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"INSERT IGNORE INTO {$this->table()} (surecart_order_id, status, created_at, updated_at) VALUES (%s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id,
				self::STATUS_PENDING,
				$now,
				$now
			)
		);

		$created = (int) $wpdb->rows_affected > 0;

		return array(
			'row'     => $this->find_by_order_id( $order_id ),
			'created' => $created,
		);
	}

	/**
	 * Find a shipment row by SureCart order ID.
	 *
	 * @param string $order_id SureCart order ID.
	 * @return object|null
	 */
	public function find_by_order_id( $order_id ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE surecart_order_id = %s", $order_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Find a shipment row by primary key.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public function find_by_id( $id ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Find a shipment row by carrier tracking number.
	 *
	 * @param string $tracking_number Carrier tracking number.
	 * @return object|null
	 */
	public function find_by_tracking_number( $tracking_number ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE tracking_number = %s ORDER BY id DESC LIMIT 1", $tracking_number ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Find a shipment row by Shippo transaction ID.
	 *
	 * @param string $transaction_id Shippo transaction object_id.
	 * @return object|null
	 */
	public function find_by_transaction_id( $transaction_id ) {
		global $wpdb;

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE shippo_transaction_id = %s ORDER BY id DESC LIMIT 1", $transaction_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Update a shipment row. Whitelists updatable columns; unknown keys
	 * are silently dropped rather than passed through to the query.
	 *
	 * @param int   $id     Row ID.
	 * @param array $fields Column => value pairs to update.
	 * @return bool
	 */
	public function update( $id, array $fields ) {
		global $wpdb;

		$allowed = array(
			'surecart_fulfillment_id' => '%s',
			'shippo_shipment_id'      => '%s',
			'shippo_transaction_id'   => '%s',
			'shippo_rate_id'          => '%s',
			'carrier'                 => '%s',
			'service_token'           => '%s',
			'tracking_number'         => '%s',
			'tracking_url'            => '%s',
			'label_url'               => '%s',
			'amount_cents'            => '%d',
			'status'                  => '%s',
			'last_error'              => '%s',
			'payload'                 => '%s',
		);

		$data   = array();
		$format = array();

		foreach ( $allowed as $column => $fmt ) {
			if ( array_key_exists( $column, $fields ) ) {
				$data[ $column ] = $fields[ $column ];
				$format[]        = $fmt;
			}
		}

		if ( empty( $data ) ) {
			return false;
		}

		$data['updated_at'] = current_time( 'mysql', true );
		$format[]           = '%s';

		if ( array_key_exists( 'status', $fields ) ) {
			$data['last_status_at'] = current_time( 'mysql', true );
			$format[]               = '%s';
		}

		$result = $wpdb->update( $this->table(), $data, array( 'id' => $id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return false !== $result;
	}

	/**
	 * Reset a shipment for a fresh manual retry: back to pending, attempt
	 * counter and last error cleared.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	public function reset_for_retry( $id ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'status'         => self::STATUS_PENDING,
				'last_error'     => '',
				'attempts'       => 0,
				'last_status_at' => current_time( 'mysql', true ),
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Atomically increment the attempt counter.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	public function increment_attempts( $id ) {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"UPDATE {$this->table()} SET attempts = attempts + 1, updated_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				$id
			)
		);
	}

	/**
	 * Shipments stuck in a non-terminal status for longer than the given
	 * age — candidates for the reconciliation sweep (webhooks get missed).
	 *
	 * @param int $older_than_hours Age threshold in hours.
	 * @param int $limit            Max rows to return per sweep.
	 * @return object[]
	 */
	public function find_stuck( $older_than_hours, $limit = 50 ) {
		global $wpdb;

		$terminal     = self::terminal_statuses();
		$placeholders = implode( ', ', array_fill( 0, count( $terminal ), '%s' ) );
		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - ( HOUR_IN_SECONDS * (int) $older_than_hours ) );

		$sql = "SELECT * FROM {$this->table()}
			WHERE status NOT IN ({$placeholders})
			AND COALESCE(last_status_at, created_at) < %s
			ORDER BY COALESCE(last_status_at, created_at) ASC
			LIMIT %d";

		$params   = $terminal;
		$params[] = $cutoff;
		$params[] = (int) $limit;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Paginated + filterable listing for the admin Shipments screen.
	 *
	 * @param array $args {
	 *     @type string   $status   Optional single status filter.
	 *     @type string[] $statuses Optional multi-status filter; takes precedence over $status.
	 *     @type string   $search   Optional search against order id / tracking number.
	 *     @type int      $per_page Rows per page.
	 *     @type int      $page     1-indexed page number.
	 *     @type string   $orderby  Column to sort by.
	 *     @type string   $order    ASC|DESC.
	 * }
	 * @return array{rows: object[], total: int}
	 */
	public function paginated( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'status'   => '',
			'statuses' => array(),
			'search'   => '',
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'id',
			'order'    => 'DESC',
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		$statuses = array_values( array_intersect( (array) $args['statuses'], self::all_statuses() ) );

		if ( ! empty( $statuses ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$where[]      = "status IN ({$placeholders})";
			$params       = array_merge( $params, $statuses );
		} elseif ( '' !== $args['status'] && in_array( $args['status'], self::all_statuses(), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( '' !== $args['search'] ) {
			$where[]  = '(surecart_order_id LIKE %s OR tracking_number LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$allowed_orderby = array( 'id', 'status', 'created_at', 'updated_at', 'amount_cents' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where_sql}";
		$total     = (int) ( empty( $params )
			? $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$row_params = array_merge( $params, array( $per_page, $offset ) );
		$row_sql    = "SELECT * FROM {$this->table()} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$rows       = $wpdb->get_results( $wpdb->prepare( $row_sql, $row_params ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}
}
