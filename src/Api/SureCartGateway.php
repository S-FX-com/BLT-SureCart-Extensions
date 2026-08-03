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
use SureCart\Models\Product;

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
	 * Expand paths for the Reports module's order listing: everything
	 * ORDER_EXPANDS covers, plus the customer object (the order itself
	 * carries no name/email — discovery §H). 7 of the 15 allowed expands,
	 * none deeper than the documented two levels.
	 *
	 * @var string[]
	 */
	const REPORT_ORDER_EXPANDS = array(
		'checkout',
		'checkout.line_items',
		'checkout.customer',
		'checkout.shipping_address',
		'line_item.price',
		'line_item.variant',
		'price.product',
	);

	/**
	 * Maximum page size SureCart allows on a list endpoint ("The number of
	 * items per page. The default is 20 and the maximum is 100").
	 */
	const MAX_PER_PAGE = 100;

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
	 * Fetch one page of orders for a report, expanded down to line items,
	 * variants, products and the customer.
	 *
	 * Blocking HTTP by SureCart's own design, like every other call in this
	 * class — only ever called from inside an Action Scheduler job.
	 *
	 * Note the deliberate absence of any date argument: `GET /v1/orders`
	 * documents no date and no sort parameter (discovery §H), so callers
	 * filter on `created_at` themselves and must not assume page ordering.
	 *
	 * @param int   $page    1-based page number.
	 * @param array $filters Query filters. Recognized keys, all verified as
	 *                       real query params: `status` (string[]),
	 *                       `product_ids` (string[]), `fulfillment_status`
	 *                       (string[]).
	 * @return array|\WP_Error {
	 *     @type array $data       Order objects for this page.
	 *     @type int   $count      Total matching records across all pages.
	 *     @type int   $page       Page returned.
	 *     @type int   $limit      Page size used.
	 * }
	 */
	public function list_orders_page( $page, array $filters = array() ) {
		$query = array( 'page' => max( 1, (int) $page ) );

		// SureCart's array query params are the bracketed names in the docs
		// (status[], product_ids[], fulfillment_status[]); the PHP models'
		// where() takes the unbracketed key with an array value and
		// serializes the brackets itself.
		foreach ( array( 'status', 'product_ids', 'fulfillment_status' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) && is_array( $filters[ $key ] ) ) {
				$query[ $key ] = array_values( $filters[ $key ] );
			}
		}

		try {
			$result = Order::where( $query )
				->with( self::REPORT_ORDER_EXPANDS )
				->paginate(
					array(
						'per_page' => self::MAX_PER_PAGE,
						'page'     => max( 1, (int) $page ),
					)
				);
		} catch ( \Exception $e ) {
			return new \WP_Error( 'blt_sce_order_list_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::normalize_page( $result, $page, self::MAX_PER_PAGE );
	}

	/**
	 * Fetch one page of non-archived products (id + name only), for the
	 * report product picker. Sorted by name — `sort` is a documented param
	 * on `GET /v1/products` (unlike orders).
	 *
	 * Job-only, same as everything else here.
	 *
	 * @param int $page 1-based page number.
	 * @return array|\WP_Error Same envelope as list_orders_page().
	 */
	public function list_products_page( $page ) {
		try {
			$result = Product::where(
				array(
					'archived' => false,
					'sort'     => 'name',
				)
			)->paginate(
				array(
					'per_page' => self::MAX_PER_PAGE,
					'page'     => max( 1, (int) $page ),
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_Error( 'blt_sce_product_list_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::normalize_page( $result, $page, self::MAX_PER_PAGE );
	}

	/**
	 * Flatten a paginated model result into a predictable array envelope.
	 *
	 * The documented shape is `{ object, pagination: { count, limit, page },
	 * data: [] }`, but the models may hand it back as an array or an object
	 * depending on version, so both are handled rather than assumed.
	 *
	 * @param mixed $result           Raw paginate() return value.
	 * @param int   $requested_page   Page that was asked for, used as a fallback.
	 * @param int   $requested_limit  Page size that was asked for, used as a fallback.
	 * @return array
	 */
	private static function normalize_page( $result, $requested_page, $requested_limit ) {
		$data       = array();
		$pagination = null;

		if ( is_array( $result ) ) {
			$data       = isset( $result['data'] ) ? $result['data'] : array();
			$pagination = isset( $result['pagination'] ) ? $result['pagination'] : null;
		} elseif ( is_object( $result ) ) {
			$data       = isset( $result->data ) ? $result->data : array();
			$pagination = isset( $result->pagination ) ? $result->pagination : null;
		}

		$pick = static function ( $bag, $key, $default ) {
			if ( is_array( $bag ) && isset( $bag[ $key ] ) ) {
				return (int) $bag[ $key ];
			}

			if ( is_object( $bag ) && isset( $bag->$key ) ) {
				return (int) $bag->$key;
			}

			return $default;
		};

		return array(
			'data'  => is_array( $data ) ? $data : array(),
			'count' => $pick( $pagination, 'count', 0 ),
			'page'  => $pick( $pagination, 'page', (int) $requested_page ),
			'limit' => $pick( $pagination, 'limit', (int) $requested_limit ),
		);
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
