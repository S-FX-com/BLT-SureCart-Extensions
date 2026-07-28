<?php
/**
 * WP_List_Table for offers: Customer, Product, Offer, List Price,
 * % of List, Status, Submitted, Expires + row actions.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

use BLT\SCE\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class OffersListTable
 */
final class OffersListTable extends \WP_List_Table {

	const PER_PAGE = 20;

	/**
	 * Repository.
	 *
	 * @var OfferRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param OfferRepository $repository Offer repository.
	 */
	public function __construct( OfferRepository $repository ) {
		parent::__construct(
			array(
				'singular' => 'offer',
				'plural'   => 'offers',
				'ajax'     => false,
			)
		);

		$this->repository = $repository;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_columns() {
		return array(
			'customer'  => __( 'Customer', 'blt-surecart-extensions' ),
			'product'   => __( 'Product', 'blt-surecart-extensions' ),
			'amount'    => __( 'Offer', 'blt-surecart-extensions' ),
			'list'      => __( 'List Price', 'blt-surecart-extensions' ),
			'pct'       => __( '% of List', 'blt-surecart-extensions' ),
			'status'    => __( 'Status', 'blt-surecart-extensions' ),
			'submitted' => __( 'Submitted', 'blt-surecart-extensions' ),
			'expires'   => __( 'Expires', 'blt-surecart-extensions' ),
		);
	}

	/**
	 * Status filter views above the table.
	 *
	 * @return array<string, string>
	 */
	protected function get_views() {
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$views   = array();

		$all = array( '' => __( 'All', 'blt-surecart-extensions' ) ) + OfferPostType::statuses();

		foreach ( $all as $status => $label ) {
			$url = add_query_arg(
				array(
					'page'   => 'blt-sce-offers',
					'status' => $status ? $status : false,
				),
				admin_url( 'admin.php' )
			);

			$views[ $status ? $status : 'all' ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $url ),
				$current === $status ? ' class="current"' : '',
				esc_html( $label )
			);
		}

		return $views;
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $status && ! array_key_exists( $status, OfferPostType::statuses() ) ) {
			$status = '';
		}

		$page   = $this->get_pagenum();
		$result = $this->repository->paginate( $status, $page, self::PER_PAGE );

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Format cents in the offer's currency.
	 *
	 * @param object $offer Offer object.
	 * @param int    $cents Amount in cents.
	 * @return string
	 */
	private function money( $offer, $cents ) {
		return strtoupper( $offer->currency ? $offer->currency : 'USD' ) . ' ' . Money::cents_to_decimal_string( $cents );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param object $item        Offer object.
	 * @param string $column_name Column key.
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'product':
				return esc_html( $item->product_name ? $item->product_name : $item->product_id );
			case 'amount':
				return esc_html( $this->money( $item, $item->amount ) );
			case 'list':
				return esc_html( $this->money( $item, $item->list_price ) );
			case 'pct':
				return $item->list_price > 0 ? esc_html( round( $item->amount * 100 / $item->list_price ) . '%' ) : '—';
			case 'submitted':
				return esc_html( get_date_from_gmt( $item->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
			case 'expires':
				return $item->expires_at ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->expires_at ) ) : '—';
			default:
				return '';
		}
	}

	/**
	 * Status column, with capture-failure and awaiting-card annotations.
	 *
	 * @param object $item Offer object.
	 * @return string
	 */
	protected function column_status( $item ) {
		$out = esc_html( OfferPostType::status_label( $item->status ) );

		if ( OfferPostType::STATUS_COUNTERED === $item->status && $item->counter_amount ) {
			$out .= '<br /><span class="description">' . esc_html(
				sprintf(
					/* translators: %s: counter amount */
					__( 'at %s', 'blt-surecart-extensions' ),
					$this->money( $item, $item->counter_amount )
				)
			) . '</span>';
		}

		if ( OfferPostType::STATUS_PENDING === $item->status && ! $item->pm_confirmed ) {
			$out .= '<br /><span class="description">' . esc_html__( 'Awaiting card', 'blt-surecart-extensions' ) . '</span>';
		}

		if ( '' !== $item->capture_error && in_array( $item->status, array( OfferPostType::STATUS_PENDING, OfferPostType::STATUS_COUNTERED ), true ) ) {
			$out .= '<br /><span style="color:#d63638;">' . esc_html__( 'Charge failed — see detail', 'blt-surecart-extensions' ) . '</span>';
		}

		return $out;
	}

	/**
	 * Customer column with row actions.
	 *
	 * @param object $item Offer object.
	 * @return string
	 */
	protected function column_customer( $item ) {
		$detail_url = add_query_arg(
			array(
				'page'  => 'blt-sce-offers',
				'offer' => $item->id,
			),
			admin_url( 'admin.php' )
		);

		$actions = array(
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( $detail_url ), esc_html__( 'View', 'blt-surecart-extensions' ) ),
		);

		if ( in_array( $item->status, array( OfferPostType::STATUS_PENDING, OfferPostType::STATUS_COUNTERED ), true ) && $item->pm_confirmed ) {
			$actions['accept'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( AdminPage::action_url( $item->id, 'accept' ) ),
				esc_html__( 'Accept', 'blt-surecart-extensions' )
			);

			$actions['decline'] = sprintf(
				'<a href="%s" style="color:#d63638;">%s</a>',
				esc_url( AdminPage::action_url( $item->id, 'decline' ) ),
				esc_html__( 'Decline', 'blt-surecart-extensions' )
			);
		}

		return sprintf(
			'<strong><a href="%s">%s</a></strong><br /><span class="description">%s</span>%s',
			esc_url( $detail_url ),
			esc_html( $item->customer_name ? $item->customer_name : $item->customer_email ),
			esc_html( $item->customer_email ),
			$this->row_actions( $actions )
		);
	}
}
