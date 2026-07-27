<?php
/**
 * WP_List_Table for the Shipments admin screen: every shipment row this
 * plugin has ever touched, with status, carrier, tracking, cost, label
 * download, and row actions.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Admin;

use BLT\SCE\Db\ShipmentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class ShipmentsListTable
 */
final class ShipmentsListTable extends \WP_List_Table {

	/**
	 * Shipment repository.
	 *
	 * @var ShipmentRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param ShipmentRepository $repository Shipment repository.
	 */
	public function __construct( ShipmentRepository $repository ) {
		$this->repository = $repository;

		parent::__construct(
			array(
				'singular' => 'shipment',
				'plural'   => 'shipments',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'order'    => __( 'Order', 'blt-surecart-extensions' ),
			'status'   => __( 'Status', 'blt-surecart-extensions' ),
			'carrier'  => __( 'Carrier', 'blt-surecart-extensions' ),
			'tracking' => __( 'Tracking', 'blt-surecart-extensions' ),
			'cost'     => __( 'Cost', 'blt-surecart-extensions' ),
			'label'    => __( 'Label', 'blt-surecart-extensions' ),
			'updated'  => __( 'Updated', 'blt-surecart-extensions' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array>
	 */
	protected function get_sortable_columns() {
		return array(
			'status'  => array( 'status', false ),
			'cost'    => array( 'amount_cents', false ),
			'updated' => array( 'updated_at', true ),
		);
	}

	/**
	 * Bulk-visible row actions per status.
	 *
	 * @param object $row Shipment row.
	 * @return array<string, string>
	 */
	private function row_actions_for( $row ) {
		$actions = array();
		$base    = add_query_arg(
			array(
				'page'        => 'blt-sce-shipments',
				'shipment_id' => $row->id,
			),
			admin_url( 'admin.php' )
		);

		if ( in_array( $row->status, array( ShipmentRepository::STATUS_REVIEW, ShipmentRepository::STATUS_QUOTED ), true ) ) {
			$actions['purchase'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( 'blt_sce_action', 'purchase', $base ), 'blt_sce_shipment_action_purchase_' . $row->id ) ),
				esc_html__( 'Purchase now', 'blt-surecart-extensions' )
			);
		}

		if ( ShipmentRepository::STATUS_FAILED === $row->status ) {
			$actions['retry'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( 'blt_sce_action', 'retry', $base ), 'blt_sce_shipment_action_retry_' . $row->id ) ),
				esc_html__( 'Retry', 'blt-surecart-extensions' )
			);
		}

		if ( in_array( $row->status, array( ShipmentRepository::STATUS_PURCHASED, ShipmentRepository::STATUS_SHIPPED, ShipmentRepository::STATUS_IN_TRANSIT ), true ) ) {
			$actions['void'] = sprintf(
				'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
				esc_url( wp_nonce_url( add_query_arg( 'blt_sce_action', 'void', $base ), 'blt_sce_shipment_action_void_' . $row->id ) ),
				esc_attr__( 'Void this label and request a Shippo refund?', 'blt-surecart-extensions' ),
				esc_html__( 'Void', 'blt-surecart-extensions' )
			);
		}

		$actions['logs'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( 'blt_sce_view', 'logs', $base ) ),
			esc_html__( 'View log', 'blt-surecart-extensions' )
		);

		return $actions;
	}

	/**
	 * Default column renderer.
	 *
	 * @param object $item        Shipment row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'carrier':
				return $item->carrier ? esc_html( $item->carrier ) : '&#8212;';
			case 'updated':
				return esc_html( $item->updated_at );
			default:
				return '';
		}
	}

	/**
	 * Order column: SureCart order id plus row actions.
	 *
	 * @param object $item Shipment row.
	 * @return string
	 */
	protected function column_order( $item ) {
		/**
		 * Filters the admin URL used to link a shipment row back to its
		 * SureCart order. Left unfiltered by default rather than
		 * guessing at SureCart's admin route.
		 *
		 * @param string $url      Empty string by default.
		 * @param string $order_id SureCart order id.
		 */
		$order_url = apply_filters( 'blt_sce_order_admin_url', '', $item->surecart_order_id );

		$label = $order_url
			? sprintf( '<a href="%s">%s</a>', esc_url( $order_url ), esc_html( $item->surecart_order_id ) )
			: esc_html( $item->surecart_order_id );

		return sprintf( '<strong>%s</strong>%s', $label, $this->row_actions( $this->row_actions_for( $item ) ) );
	}

	/**
	 * Status column, as a colored badge.
	 *
	 * @param object $item Shipment row.
	 * @return string
	 */
	protected function column_status( $item ) {
		$colors = array(
			ShipmentRepository::STATUS_PENDING    => '#999',
			ShipmentRepository::STATUS_QUOTED     => '#996800',
			ShipmentRepository::STATUS_REVIEW     => '#b32d2e',
			ShipmentRepository::STATUS_PURCHASED  => '#2271b1',
			ShipmentRepository::STATUS_SHIPPED    => '#2271b1',
			ShipmentRepository::STATUS_IN_TRANSIT => '#2271b1',
			ShipmentRepository::STATUS_DELIVERED  => '#00a32a',
			ShipmentRepository::STATUS_EXCEPTION  => '#d63638',
			ShipmentRepository::STATUS_VOIDED     => '#666',
			ShipmentRepository::STATUS_FAILED     => '#d63638',
		);
		$color  = isset( $colors[ $item->status ] ) ? $colors[ $item->status ] : '#666';

		return sprintf( '<span style="color:%s;font-weight:600;">%s</span>', esc_attr( $color ), esc_html( ucwords( str_replace( '_', ' ', $item->status ) ) ) );
	}

	/**
	 * Tracking column: tracking number linked to the carrier tracking URL.
	 *
	 * @param object $item Shipment row.
	 * @return string
	 */
	protected function column_tracking( $item ) {
		if ( ! $item->tracking_number ) {
			return '&#8212;';
		}

		if ( $item->tracking_url ) {
			return sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $item->tracking_url ), esc_html( $item->tracking_number ) );
		}

		return esc_html( $item->tracking_number );
	}

	/**
	 * Cost column, formatted from integer cents.
	 *
	 * @param object $item Shipment row.
	 * @return string
	 */
	protected function column_cost( $item ) {
		if ( null === $item->amount_cents ) {
			return '&#8212;';
		}

		return esc_html( number_format_i18n( $item->amount_cents / 100, 2 ) );
	}

	/**
	 * Label column: download link, if a label has been purchased.
	 *
	 * @param object $item Shipment row.
	 * @return string
	 */
	protected function column_label( $item ) {
		if ( ! $item->label_url ) {
			return '&#8212;';
		}

		return sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $item->label_url ), esc_html__( 'Download', 'blt-surecart-extensions' ) );
	}

	/**
	 * Status filter dropdown views, shown above the table.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$views          = array();
		$current_status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base_url       = remove_query_arg( 'status' );

		$views['all'] = sprintf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( $base_url ),
			'' === $current_status ? 'current' : '',
			esc_html__( 'All', 'blt-surecart-extensions' )
		);

		foreach ( ShipmentRepository::all_statuses() as $status ) {
			$views[ $status ] = sprintf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( add_query_arg( 'status', $status, $base_url ) ),
				$current_status === $status ? 'current' : '',
				esc_html( ucwords( str_replace( '_', ' ', $status ) ) )
			);
		}

		return $views;
	}

	/**
	 * Load table items for the current page/filter/search/sort.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$status  = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged   = isset( $_REQUEST['paged'] ) ? max( 1, (int) $_REQUEST['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'id'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = $this->repository->paginated(
			array(
				'status'   => $status,
				'search'   => $search,
				'per_page' => $per_page,
				'page'     => $paged,
				'orderby'  => $orderby,
				'order'    => $order,
			)
		);

		$this->items = $result['rows'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}
}
