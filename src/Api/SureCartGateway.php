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

use BLT\SCE\Support\Obj;
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
		} catch ( \Throwable $e ) {
			// Throwable, not Exception: a query-builder method that doesn't
			// exist throws \Error, which an Exception-only catch would let
			// escape and kill the job mid-report.
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
		} catch ( \Throwable $e ) {
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
		$data       = self::read( $result, 'data' );
		$pagination = self::read( $result, 'pagination' );

		// No `data` member means this isn't the documented envelope. Rather
		// than treat that as "no records" — which silently produces an empty
		// report and looks like a store with no orders — fall back through the
		// other shapes a model query can hand back.
		if ( null === $data ) {
			if ( is_array( $result ) && self::is_list( $result ) ) {
				// paginate() returned the records themselves, unwrapped.
				$data = $result;
			} elseif ( $result instanceof \Traversable ) {
				$data = iterator_to_array( $result );
			} elseif ( is_object( $result ) && method_exists( $result, 'toArray' ) ) {
				$as_array = $result->toArray();

				if ( is_array( $as_array ) ) {
					$data = self::read( $as_array, 'data' );

					if ( null === $data && self::is_list( $as_array ) ) {
						$data = $as_array;
					}

					if ( null === $pagination ) {
						$pagination = self::read( $as_array, 'pagination' );
					}
				}
			}
		}

		if ( $data instanceof \Traversable ) {
			$data = iterator_to_array( $data );
		}

		$pick = static function ( $bag, $key, $default ) {
			$value = self::read( $bag, $key );

			return null === $value ? $default : (int) $value;
		};

		return array(
			'data'  => is_array( $data ) ? array_values( $data ) : array(),
			'count' => $pick( $pagination, 'count', 0 ),
			'page'  => $pick( $pagination, 'page', (int) $requested_page ),
			'limit' => $pick( $pagination, 'limit', (int) $requested_limit ),
			'shape' => self::describe_shape( $result ),
		);
	}

	/**
	 * Read a member from an array or object without trusting isset().
	 *
	 * SureCart's models expose attributes through a magic __get(), and
	 * isset()/property_exists() both report false for those unless the class
	 * also implements __isset(). An isset() guard on such an object therefore
	 * reads as "the field isn't there" when the field is present — which is
	 * how an entire response can silently come back empty.
	 *
	 * @param mixed  $bag Array, object, or anything else.
	 * @param string $key Member name.
	 * @return mixed Null when genuinely absent.
	 */
	private static function read( $bag, $key ) {
		return Obj::get( $bag, $key );
	}

	/**
	 * Whether an array is a sequential list rather than a keyed envelope.
	 * (array_is_list() is PHP 8.1+; this plugin supports 7.4.)
	 *
	 * @param array $value Array to test.
	 * @return bool
	 */
	private static function is_list( array $value ) {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Short description of a response's runtime shape, for diagnostics. This
	 * exists because the only way to know what a model query really returns
	 * on a live store is to look — and a report that comes back empty needs to
	 * say whether SureCart returned nothing or we failed to read what it did.
	 *
	 * @param mixed $result Raw response.
	 * @return string
	 */
	public static function describe_shape( $result ) {
		if ( is_array( $result ) ) {
			return sprintf(
				'array(%d) keys[%s]',
				count( $result ),
				implode( ',', array_slice( array_map( 'strval', array_keys( $result ) ), 0, 6 ) )
			);
		}

		if ( is_object( $result ) ) {
			return sprintf(
				'%s props[%s]%s%s',
				get_class( $result ),
				implode( ',', array_slice( array_keys( get_object_vars( $result ) ), 0, 6 ) ),
				method_exists( $result, '__get' ) ? ' +__get' : '',
				$result instanceof \Traversable ? ' +Traversable' : ''
			);
		}

		return gettype( $result );
	}

	/**
	 * Diagnostic-only: ask for a single order with no filters at all, to
	 * establish whether the store returns orders through the models and how
	 * many it reports. Never used to build a report — only to explain one that
	 * came back empty, so a user's filters are never silently ignored.
	 *
	 * @return array|\WP_Error Same envelope as list_orders_page().
	 */
	public function probe_orders() {
		try {
			$result = Order::paginate(
				array(
					'per_page' => 1,
					'page'     => 1,
				)
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'blt_sce_order_probe_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::normalize_page( $result, 1, 1 );
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
		$checkout = Obj::obj( $order, 'checkout' );

		if ( ! is_object( $checkout ) ) {
			return new \WP_Error( 'blt_sce_no_checkout', __( 'Order has no expanded checkout.', 'blt-surecart-extensions' ) );
		}

		$address = Obj::obj( $checkout, 'shipping_address' );

		if ( ! $address ) {
			return new \WP_Error( 'blt_sce_no_shipping_address', __( 'Order has no shipping address — likely a digital-only order.', 'blt-surecart-extensions' ) );
		}

		$shipping_address = array(
			'name'    => Obj::str( $address, 'name' ),
			'street1' => Obj::str( $address, 'line_1' ),
			'street2' => Obj::str( $address, 'line_2' ),
			'city'    => Obj::str( $address, 'city' ),
			'state'   => Obj::str( $address, 'state' ),
			'zip'     => Obj::str( $address, 'postal_code' ),
			'country' => Obj::str( $address, 'country' ),
		);

		$line_items_raw = Obj::items( $checkout, 'line_items' );

		if ( empty( $line_items_raw ) ) {
			return new \WP_Error( 'blt_sce_no_line_items', __( 'Order has no line items.', 'blt-surecart-extensions' ) );
		}

		$line_items = array();

		foreach ( $line_items_raw as $line_item ) {
			$sku = Obj::str( Obj::obj( $line_item, 'variant' ), 'sku' );

			if ( '' === $sku ) {
				$sku = Obj::str( Obj::obj( Obj::obj( $line_item, 'price' ), 'product' ), 'sku' );
			}

			$quantity = Obj::int( $line_item, 'quantity', 1 );

			$line_items[] = array(
				'line_item_id' => Obj::str( $line_item, 'id' ),
				'sku'          => '' !== $sku ? $sku : null,
				'quantity'     => $quantity > 0 ? $quantity : 1,
			);
		}

		return array(
			'checkout_id'       => Obj::str( $checkout, 'id' ),
			'shipping_address'  => $shipping_address,
			'order_total_cents' => Obj::int( $checkout, 'total_amount' ),
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
