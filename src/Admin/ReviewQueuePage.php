<?php
/**
 * "Review Queue" admin screen: held shipments (guardrail holds, and
 * everything awaiting manual purchase when auto-purchase is off) with a
 * one-click purchase action.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Admin;

use BLT\SCE\Modules\ShippoFulfillment\ReviewQueue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReviewQueuePage
 */
final class ReviewQueuePage {

	const CAPABILITY   = 'manage_options';
	const NONCE_ACTION = 'blt_sce_review_queue_action';

	/**
	 * Review queue service.
	 *
	 * @var ReviewQueue
	 */
	private $queue;

	/**
	 * Constructor.
	 *
	 * @param ReviewQueue $queue Review queue service.
	 */
	public function __construct( ReviewQueue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * Register WP hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Register the submenu page under the plugin's top-level menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'blt-sce-modules',
			__( 'Review Queue', 'blt-surecart-extensions' ),
			__( 'Review Queue', 'blt-surecart-extensions' ),
			self::CAPABILITY,
			'blt-sce-review-queue',
			array( $this, 'render' )
		);
	}

	/**
	 * Handle a queued "purchase now" click, then render the table.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( isset( $_GET['blt_sce_action'], $_GET['shipment_id'] ) && 'purchase' === $_GET['blt_sce_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$shipment_id = (int) $_GET['shipment_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			check_admin_referer( 'blt_sce_shipment_action_purchase_' . $shipment_id );

			$result = $this->queue->purchase_now( $shipment_id );

			// Redirect (PRG pattern) rather than falling through to render
			// on the same request — otherwise reloading the page resubmits
			// the same purchase-now click and could enqueue a second
			// purchase job for the same shipment before the first job has
			// had a chance to flip its status.
			$redirect = remove_query_arg( array( 'blt_sce_action', 'shipment_id', '_wpnonce' ) );

			if ( is_wp_error( $result ) ) {
				$redirect = add_query_arg( 'blt_sce_error', rawurlencode( $result->get_error_message() ), $redirect );
			} else {
				$redirect = add_query_arg( 'blt_sce_purchased', $shipment_id, $redirect );
			}

			wp_safe_redirect( $redirect );
			exit;
		}

		if ( isset( $_GET['blt_sce_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['blt_sce_error'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['blt_sce_purchased'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Purchase enqueued — it will be attempted within a minute.', 'blt-surecart-extensions' )
			);
		}

		$page   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = $this->queue->list_held(
			array(
				'page'     => $page,
				'per_page' => 20,
			)
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Review Queue', 'blt-surecart-extensions' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Orders held for a guardrail reason, or quoted and waiting because auto-purchase is off.', 'blt-surecart-extensions' ) . '</p>';

		if ( empty( $result['rows'] ) ) {
			echo '<p>' . esc_html__( 'Nothing waiting for review.', 'blt-surecart-extensions' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'blt-surecart-extensions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'blt-surecart-extensions' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'blt-surecart-extensions' ) . '</th>';
		echo '<th>' . esc_html__( 'Updated', 'blt-surecart-extensions' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'blt-surecart-extensions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $result['rows'] as $row ) {
			$purchase_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'           => 'blt-sce-review-queue',
						'blt_sce_action' => 'purchase',
						'shipment_id'    => $row->id,
					),
					admin_url( 'admin.php' )
				),
				'blt_sce_shipment_action_purchase_' . $row->id
			);

			echo '<tr>';
			echo '<td>' . esc_html( $row->surecart_order_id ) . '</td>';
			echo '<td>' . esc_html( ucwords( str_replace( '_', ' ', $row->status ) ) ) . '</td>';
			echo '<td>' . ( $row->last_error ? esc_html( $row->last_error ) : '&#8212;' ) . '</td>';
			echo '<td>' . esc_html( $row->updated_at ) . '</td>';
			echo '<td><a class="button button-primary" href="' . esc_url( $purchase_url ) . '">' . esc_html__( 'Purchase now', 'blt-surecart-extensions' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}
}
