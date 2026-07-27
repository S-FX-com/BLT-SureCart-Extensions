<?php
/**
 * Reads orders and writes fulfillments through SureCart's PHP models —
 * the documented approach (developer.surecart.com/documentation/php-models.md),
 * confirmed to make synchronous HTTP calls, which is why every call here
 * only ever happens inside an Action Scheduler job, never a page request.
 *
 * Field names and expand paths below are all sourced from
 * tasks/00-discovery.md §B/§C — nothing here is guessed.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Api;

use SureCart\Models\Fulfillment;
use SureCart\Models\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SureCartGateway
 */
final class SureCartGateway {

	/**
	 * Expand paths needed to get line items (with SKU-bearing price/variant)
	 * and the shipping address in a single order retrieval.
	 *
	 * @var string[]
	 */
	const ORDER_EXPANDS = array(
		'checkout',
		'checkout.line_items',
		'checkout.shipping_address',
		'line_item.price',
		'line_item.variant',
		'price.product',
	);

	/**
	 * Retrieve an order with everything this module needs expanded.
	 *
	 * @param string $order_id SureCart order UUID.
	 * @return \SureCart\Models\Order|\WP_Error
	 */
	public function get_order( $order_id ) {
		return Order::with( self::ORDER_EXPANDS )->find( $order_id );
	}

	/**
	 * Extract everything the label-purchase flow needs from an order into
	 * a plain array: destination address (Shippo field names), order
	 * total in cents, and line items with resolved SKUs.
	 *
	 * @param object $order Order model (as returned by get_order()).
	 * @return array|\WP_Error {
	 *     @type string $checkout_id        Checkout UUID.
	 *     @type array  $shipping_address   Shippo-shaped address (street1, street2, city, state, zip, country, name).
	 *     @type int    $order_total_cents  Checkout total_amount, already integer cents.
	 *     @type array  $line_items         Each: {line_item_id, sku, quantity}.
	 * }
	 */
	public function extract_shipping_context( $order ) {
		$checkout = is_object( $order ) && isset( $order->checkout ) ? $order->checkout : null;

		if ( ! is_object( $checkout ) ) {
			return new \WP_Error( 'blt_sce_no_checkout', __( 'Order has no expanded checkout.', 'blt-surecart-extensions' ) );
		}

		$address = isset( $checkout->shipping_address ) && is_object( $checkout->shipping_address ) ? $checkout->shipping_address : null;

		if ( ! $address ) {
			return new \WP_Error( 'blt_sce_no_shipping_address', __( 'Order has no shipping address — likely a digital-only order.', 'blt-surecart-extensions' ) );
		}

		$shipping_address = array(
			'name'    => isset( $address->name ) ? $address->name : '',
			'street1' => isset( $address->line_1 ) ? $address->line_1 : '',
			'street2' => isset( $address->line_2 ) ? $address->line_2 : '',
			'city'    => isset( $address->city ) ? $address->city : '',
			'state'   => isset( $address->state ) ? $address->state : '',
			'zip'     => isset( $address->postal_code ) ? $address->postal_code : '',
			'country' => isset( $address->country ) ? $address->country : '',
		);

		$line_items_raw = isset( $checkout->line_items ) ? $checkout->line_items : null;
		$line_items_raw = is_object( $line_items_raw ) && isset( $line_items_raw->data ) ? $line_items_raw->data : $line_items_raw;

		if ( empty( $line_items_raw ) || ! is_array( $line_items_raw ) ) {
			return new \WP_Error( 'blt_sce_no_line_items', __( 'Order has no line items.', 'blt-surecart-extensions' ) );
		}

		$line_items = array();

		foreach ( $line_items_raw as $line_item ) {
			$sku = null;

			if ( isset( $line_item->variant ) && is_object( $line_item->variant ) && ! empty( $line_item->variant->sku ) ) {
				$sku = $line_item->variant->sku;
			} elseif ( isset( $line_item->price->product ) && is_object( $line_item->price->product ) && ! empty( $line_item->price->product->sku ) ) {
				$sku = $line_item->price->product->sku;
			}

			$line_items[] = array(
				'line_item_id' => $line_item->id,
				'sku'          => $sku,
				'quantity'     => isset( $line_item->quantity ) ? (int) $line_item->quantity : 1,
			);
		}

		return array(
			'checkout_id'       => $checkout->id,
			'shipping_address'  => $shipping_address,
			'order_total_cents' => isset( $checkout->total_amount ) ? (int) $checkout->total_amount : 0,
			'line_items'        => $line_items,
		);
	}

	/**
	 * Create a SureCart fulfillment with tracking details attached.
	 * `shipment_status` is included best-effort (see discovery §B item 2)
	 * — SureCart may or may not honor it; our own local status is always
	 * authoritative regardless.
	 *
	 * @param string      $order_id          SureCart order UUID.
	 * @param array       $fulfillment_items Each: {line_item: UUID, quantity: int}.
	 * @param string      $tracking_number   Carrier tracking number.
	 * @param string|null $tracking_url      Carrier tracking URL, if known.
	 * @param string|null $shipment_status   One of SureCart's fulfillment shipment_status enum values.
	 * @return object|\WP_Error
	 */
	public function create_fulfillment( $order_id, array $fulfillment_items, $tracking_number, $tracking_url, $shipment_status = null ) {
		$data = array(
			'order'             => $order_id,
			'fulfillment_items' => $fulfillment_items,
			'trackings'         => array(
				array(
					'number' => $tracking_number,
					'url'    => $tracking_url,
				),
			),
		);

		if ( $shipment_status ) {
			$data['shipment_status'] = $shipment_status;
		}

		return Fulfillment::create( $data );
	}

	/**
	 * Update an existing fulfillment's tracking and/or shipment_status.
	 *
	 * @param string      $fulfillment_id  SureCart fulfillment UUID.
	 * @param string|null $tracking_number Carrier tracking number, if changed.
	 * @param string|null $tracking_url    Carrier tracking URL, if changed.
	 * @param string|null $shipment_status One of SureCart's fulfillment shipment_status enum values.
	 * @return object|\WP_Error
	 */
	public function update_fulfillment( $fulfillment_id, $tracking_number = null, $tracking_url = null, $shipment_status = null ) {
		$data = array( 'id' => $fulfillment_id );

		if ( null !== $tracking_number ) {
			$data['trackings'] = array(
				array(
					'number' => $tracking_number,
					'url'    => $tracking_url,
				),
			);
		}

		if ( $shipment_status ) {
			$data['shipment_status'] = $shipment_status;
		}

		return Fulfillment::update( $data );
	}
}
