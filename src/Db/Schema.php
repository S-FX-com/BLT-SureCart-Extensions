<?php
/**
 * Local database schema. Order data lives in SureCart's cloud — this
 * plugin needs its own durable record of what it has purchased so a
 * retried or duplicated trigger can never buy a second label.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Schema
 */
final class Schema {

	const DB_VERSION_OPTION = 'blt_sce_db_version';
	const DB_VERSION        = '1.0.0';

	/**
	 * Shipments table name (unprefixed, without $wpdb->prefix).
	 *
	 * @return string
	 */
	public static function shipments_table() {
		global $wpdb;

		return $wpdb->prefix . 'blt_sce_shipments';
	}

	/**
	 * Logs table name (unprefixed, without $wpdb->prefix).
	 *
	 * @return string
	 */
	public static function logs_table() {
		global $wpdb;

		return $wpdb->prefix . 'blt_sce_logs';
	}

	/**
	 * Create or upgrade tables. Safe to call on every activation and on
	 * plugins_loaded when the stored version is behind — dbDelta() only
	 * applies the diff.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$shipments       = self::shipments_table();
		$logs            = self::logs_table();

		$sql = "CREATE TABLE {$shipments} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			surecart_order_id varchar(64) NOT NULL,
			surecart_fulfillment_id varchar(64) DEFAULT NULL,
			shippo_shipment_id varchar(64) DEFAULT NULL,
			shippo_transaction_id varchar(64) DEFAULT NULL,
			shippo_rate_id varchar(64) DEFAULT NULL,
			carrier varchar(64) DEFAULT NULL,
			service_token varchar(64) DEFAULT NULL,
			tracking_number varchar(128) DEFAULT NULL,
			tracking_url text,
			label_url text,
			amount_cents int DEFAULT NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			last_status_at datetime DEFAULT NULL,
			attempts smallint NOT NULL DEFAULT 0,
			last_error text,
			payload longtext,
			created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY surecart_order_id (surecart_order_id),
			KEY status (status),
			KEY tracking_number (tracking_number)
		) {$charset_collate};";

		dbDelta( $sql );

		$sql = "CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			shipment_id bigint(20) unsigned DEFAULT NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			message varchar(255) NOT NULL,
			context longtext,
			created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY shipment_id (shipment_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Whether the shipments table already exists — used defensively by
	 * code that may run before an upgrade routine has had a chance to run.
	 *
	 * @return bool
	 */
	public static function shipments_table_exists() {
		global $wpdb;

		$table = self::shipments_table();

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Whether the logs table already exists.
	 *
	 * @return bool
	 */
	public static function logs_table_exists() {
		global $wpdb;

		$table = self::logs_table();

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Drop all tables. Only ever called from uninstall.php, and only when
	 * the site owner has explicitly opted in to destroying data.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( self::shipments_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( self::logs_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		delete_option( self::DB_VERSION_OPTION );
	}
}
