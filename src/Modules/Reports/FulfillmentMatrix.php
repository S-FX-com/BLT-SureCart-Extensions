<?php
/**
 * Turns a stream of SureCart orders into the fulfillment matrix: one row
 * per customer, one column per product variant, quantity in each cell.
 *
 * This is the shape a manufacturer order and a bulk-fulfillment run both
 * want — "who ordered which shirt in which size, and how many" — rather
 * than the order-by-order view SureCart's own admin gives you.
 *
 * Hook-free and HTTP-free by design: orders are fed in by ReportRunner
 * from inside an Action Scheduler job, and every field name read here is
 * one verified in tasks/00-discovery.md §H.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FulfillmentMatrix
 */
final class FulfillmentMatrix {

	/**
	 * Apparel sizes in the order a manufacturer expects to read them, used
	 * to sort size columns when SureCart gives us no variant `position` to
	 * sort by. Without this, columns come out alphabetically (L, M, S, XL),
	 * which is useless on a cut sheet.
	 *
	 * Keys are normalized (lowercased, spaces and hyphens stripped).
	 *
	 * @var array<string,int>
	 */
	private const SIZE_ORDER = array(
		'xxxs'  => 10,
		'3xs'   => 10,
		'xxs'   => 20,
		'2xs'   => 20,
		'xs'    => 30,
		'xsmall' => 30,
		'extrasmall' => 30,
		's'     => 40,
		'sm'    => 40,
		'small' => 40,
		'm'     => 50,
		'md'    => 50,
		'med'   => 50,
		'medium' => 50,
		'l'     => 60,
		'lg'    => 60,
		'large' => 60,
		'xl'    => 70,
		'extralarge' => 70,
		'xlarge' => 70,
		'xxl'   => 80,
		'2xl'   => 80,
		'2x'    => 80,
		'xxxl'  => 90,
		'3xl'   => 90,
		'3x'    => 90,
		'xxxxl' => 100,
		'4xl'   => 100,
		'4x'    => 100,
		'xxxxxl' => 110,
		'5xl'   => 110,
		'5x'    => 110,
		'6xl'   => 120,
		'6x'    => 120,
	);

	/**
	 * Accumulated customer rows, keyed by customer key.
	 *
	 * @var array<string,array>
	 */
	private $rows = array();

	/**
	 * Accumulated column definitions, keyed by column key.
	 *
	 * @var array<string,array>
	 */
	private $columns = array();

	/**
	 * Quantities: [ customer key ][ column key ] => int.
	 *
	 * @var array<string,array<string,int>>
	 */
	private $cells = array();

	/**
	 * Whether to collect shipping-address columns.
	 *
	 * @var bool
	 */
	private $include_address;

	/**
	 * Orders that contributed at least one line item.
	 *
	 * @var int
	 */
	private $orders_counted = 0;

	/**
	 * Constructor.
	 *
	 * @param bool $include_address Whether to collect shipping-address columns.
	 */
	public function __construct( $include_address = false ) {
		$this->include_address = (bool) $include_address;
	}

	/**
	 * Fold one order into the matrix.
	 *
	 * @param object $order Order model with the REPORT_ORDER_EXPANDS expansions.
	 * @return bool Whether the order contributed anything.
	 */
	public function add_order( $order ) {
		$checkout = is_object( $order ) && isset( $order->checkout ) && is_object( $order->checkout ) ? $order->checkout : null;

		if ( ! $checkout ) {
			return false;
		}

		$line_items = $this->line_items( $checkout );

		if ( empty( $line_items ) ) {
			return false;
		}

		$identity     = $this->customer_identity( $checkout );
		$customer_key = $this->customer_key( $identity, $order );

		if ( ! isset( $this->rows[ $customer_key ] ) ) {
			$this->rows[ $customer_key ] = array(
				'name'          => $identity['name'],
				'email'         => $identity['email'],
				'order_numbers' => array(),
				'order_count'   => 0,
				'address'       => $this->include_address ? $this->address( $checkout ) : array(),
			);
		}

		$row = &$this->rows[ $customer_key ];

		// A customer's name can be blank on one checkout and present on
		// another; keep the first non-empty one we see.
		if ( '' === $row['name'] && '' !== $identity['name'] ) {
			$row['name'] = $identity['name'];
		}

		if ( $this->include_address && empty( $row['address'] ) ) {
			$row['address'] = $this->address( $checkout );
		}

		$order_number = '';

		if ( isset( $order->number ) && '' !== (string) $order->number ) {
			$order_number = (string) $order->number;
		} elseif ( isset( $order->id ) ) {
			$order_number = (string) $order->id;
		}

		if ( '' !== $order_number && ! in_array( $order_number, $row['order_numbers'], true ) ) {
			$row['order_numbers'][] = $order_number;
			++$row['order_count'];
		}

		unset( $row );

		$contributed = false;

		foreach ( $line_items as $line_item ) {
			$quantity = isset( $line_item->quantity ) ? (int) $line_item->quantity : 0;

			if ( $quantity <= 0 ) {
				continue;
			}

			$column = $this->column_for( $line_item );

			if ( null === $column ) {
				continue;
			}

			if ( ! isset( $this->columns[ $column['key'] ] ) ) {
				$this->columns[ $column['key'] ] = $column;
			}

			if ( ! isset( $this->cells[ $customer_key ][ $column['key'] ] ) ) {
				$this->cells[ $customer_key ][ $column['key'] ] = 0;
			}

			$this->cells[ $customer_key ][ $column['key'] ] += $quantity;
			$contributed                                     = true;
		}

		if ( $contributed ) {
			++$this->orders_counted;
		}

		return $contributed;
	}

	/**
	 * Build the finished table: header row, one row per customer, and a
	 * trailing TOTALS row (the per-size totals a manufacturer actually
	 * orders from).
	 *
	 * @return array{header: string[], rows: array[], totals: array, counts: array}
	 */
	public function to_table() {
		$columns = $this->sorted_columns();

		$header = array(
			__( 'Customer Name', 'blt-surecart-extensions' ),
			__( 'Customer Email', 'blt-surecart-extensions' ),
		);

		if ( $this->include_address ) {
			$header[] = __( 'Ship To Name', 'blt-surecart-extensions' );
			$header[] = __( 'Address Line 1', 'blt-surecart-extensions' );
			$header[] = __( 'Address Line 2', 'blt-surecart-extensions' );
			$header[] = __( 'City', 'blt-surecart-extensions' );
			$header[] = __( 'State', 'blt-surecart-extensions' );
			$header[] = __( 'Postal Code', 'blt-surecart-extensions' );
			$header[] = __( 'Country', 'blt-surecart-extensions' );
		}

		$header[] = __( 'Orders', 'blt-surecart-extensions' );
		$header[] = __( 'Order Count', 'blt-surecart-extensions' );

		foreach ( $columns as $column ) {
			$header[] = $column['label'];
		}

		$header[] = __( 'Total Items', 'blt-surecart-extensions' );

		$rows            = array();
		$column_totals   = array_fill_keys( array_keys( $this->columns ), 0 );
		$grand_total     = 0;
		$total_orders    = 0;
		$sorted_row_keys = $this->sorted_row_keys();

		foreach ( $sorted_row_keys as $customer_key ) {
			$row_meta  = $this->rows[ $customer_key ];
			$row       = array( $row_meta['name'], $row_meta['email'] );
			$row_total = 0;

			if ( $this->include_address ) {
				$address = $row_meta['address'];

				foreach ( array( 'name', 'line_1', 'line_2', 'city', 'state', 'postal_code', 'country' ) as $part ) {
					$row[] = isset( $address[ $part ] ) ? $address[ $part ] : '';
				}
			}

			$row[]         = implode( ', ', $row_meta['order_numbers'] );
			$row[]         = (string) $row_meta['order_count'];
			$total_orders += (int) $row_meta['order_count'];

			foreach ( $columns as $column ) {
				$quantity = isset( $this->cells[ $customer_key ][ $column['key'] ] ) ? (int) $this->cells[ $customer_key ][ $column['key'] ] : 0;

				// Blank rather than 0 keeps the grid readable — a
				// spreadsheet full of zeroes hides the numbers that matter.
				$row[] = $quantity > 0 ? (string) $quantity : '';

				$column_totals[ $column['key'] ] += $quantity;
				$row_total                       += $quantity;
			}

			$row[]        = (string) $row_total;
			$grand_total += $row_total;
			$rows[]       = $row;
		}

		// The label cell carries the customer count, so the row is
		// self-describing once it's sitting in a spreadsheet detached from
		// this screen. Every numeric cell below stays a true column total:
		// "Order Count" totals orders, not customers.
		$totals = array(
			sprintf(
				/* translators: %d: number of customers in the report */
				_n( 'TOTALS (%d customer)', 'TOTALS (%d customers)', count( $rows ), 'blt-surecart-extensions' ),
				count( $rows )
			),
			'',
		);

		if ( $this->include_address ) {
			$totals = array_merge( $totals, array_fill( 0, 7, '' ) );
		}

		$totals[] = '';
		$totals[] = (string) $total_orders;

		foreach ( $columns as $column ) {
			$totals[] = (string) $column_totals[ $column['key'] ];
		}

		$totals[] = (string) $grand_total;

		return array(
			'header' => $header,
			'rows'   => $rows,
			'totals' => $totals,
			'counts' => array(
				'row_count'    => count( $rows ),
				'column_count' => count( $columns ),
				'item_count'   => $grand_total,
			),
		);
	}

	/**
	 * Number of orders that contributed at least one counted line item.
	 *
	 * @return int
	 */
	public function orders_counted() {
		return $this->orders_counted;
	}

	/**
	 * Whether anything at all was aggregated.
	 *
	 * @return bool
	 */
	public function is_empty() {
		return empty( $this->rows );
	}

	/**
	 * Resolve a checkout's line items, whether they arrive as a bare array
	 * or wrapped in SureCart's `{ data: [] }` list envelope.
	 *
	 * @param object $checkout Expanded checkout object.
	 * @return array
	 */
	private function line_items( $checkout ) {
		$line_items = isset( $checkout->line_items ) ? $checkout->line_items : null;

		if ( is_object( $line_items ) && isset( $line_items->data ) ) {
			$line_items = $line_items->data;
		} elseif ( is_array( $line_items ) && isset( $line_items['data'] ) ) {
			$line_items = $line_items['data'];
		}

		return is_array( $line_items ) ? $line_items : array();
	}

	/**
	 * Resolve the customer's display name and email.
	 *
	 * SureCart's documented precedence is followed exactly: the checkout's
	 * own `name` "will take precedence" over the customer record, with
	 * first/last name and the `inherited_*` fields as documented fallbacks.
	 * The order object itself has no name or email at all (discovery §H).
	 *
	 * @param object $checkout Expanded checkout object.
	 * @return array{name: string, email: string}
	 */
	private function customer_identity( $checkout ) {
		$customer = isset( $checkout->customer ) && is_object( $checkout->customer ) ? $checkout->customer : null;

		$name = $this->first_non_empty(
			array(
				isset( $checkout->name ) ? $checkout->name : '',
				$this->join_name(
					isset( $checkout->first_name ) ? $checkout->first_name : '',
					isset( $checkout->last_name ) ? $checkout->last_name : ''
				),
				$customer && isset( $customer->name ) ? $customer->name : '',
				$customer ? $this->join_name(
					isset( $customer->first_name ) ? $customer->first_name : '',
					isset( $customer->last_name ) ? $customer->last_name : ''
				) : '',
				isset( $checkout->inherited_name ) ? $checkout->inherited_name : '',
			)
		);

		$email = $this->first_non_empty(
			array(
				isset( $checkout->email ) ? $checkout->email : '',
				$customer && isset( $customer->email ) ? $customer->email : '',
				isset( $checkout->inherited_email ) ? $checkout->inherited_email : '',
			)
		);

		return array(
			'name'  => $name,
			'email' => $email,
		);
	}

	/**
	 * Grouping key for a customer. Email is the stable identity across
	 * separate orders — a customer who ordered three times should be one
	 * row, not three. Orders with no email at all fall back to their own
	 * order ID so they still appear rather than being silently merged into
	 * one anonymous row.
	 *
	 * @param array  $identity Resolved name/email.
	 * @param object $order    Order object.
	 * @return string
	 */
	private function customer_key( array $identity, $order ) {
		if ( '' !== $identity['email'] ) {
			return 'email:' . strtolower( $identity['email'] );
		}

		if ( isset( $order->id ) ) {
			return 'order:' . (string) $order->id;
		}

		return 'name:' . strtolower( $identity['name'] );
	}

	/**
	 * Shipping address as a flat array of the documented SureCart address
	 * fields.
	 *
	 * @param object $checkout Expanded checkout object.
	 * @return array
	 */
	private function address( $checkout ) {
		$address = null;

		foreach ( array( 'shipping_address', 'inherited_shipping_address', 'billing_address' ) as $candidate ) {
			if ( isset( $checkout->$candidate ) && is_object( $checkout->$candidate ) ) {
				$address = $checkout->$candidate;
				break;
			}
		}

		if ( ! $address ) {
			return array();
		}

		$out = array();

		foreach ( array( 'name', 'line_1', 'line_2', 'city', 'state', 'postal_code', 'country' ) as $field ) {
			$out[ $field ] = isset( $address->$field ) ? (string) $address->$field : '';
		}

		return $out;
	}

	/**
	 * Build the column definition a line item belongs to.
	 *
	 * @param object $line_item Expanded line item.
	 * @return array|null Column definition, or null when unidentifiable.
	 */
	private function column_for( $line_item ) {
		$variant = isset( $line_item->variant ) && is_object( $line_item->variant ) ? $line_item->variant : null;
		$price   = isset( $line_item->price ) && is_object( $line_item->price ) ? $line_item->price : null;
		$product = $price && isset( $price->product ) && is_object( $price->product ) ? $price->product : null;

		$product_name = $this->first_non_empty(
			array(
				$product && isset( $product->name ) ? $product->name : '',
				$price && isset( $price->name ) ? $price->name : '',
				$variant && isset( $variant->sku ) ? $variant->sku : '',
				$product && isset( $product->sku ) ? $product->sku : '',
			)
		);

		if ( '' === $product_name ) {
			$product_name = __( 'Unknown product', 'blt-surecart-extensions' );
		}

		$variant_label = $this->variant_label( $line_item, $variant );

		// Column identity prefers the variant, since that's the actual
		// manufacturable unit (a specific size of a specific shirt).
		if ( $variant && ! empty( $variant->id ) ) {
			$key = 'variant:' . (string) $variant->id;
		} elseif ( $product && ! empty( $product->id ) ) {
			$key = 'product:' . (string) $product->id . ( '' !== $variant_label ? '|' . $variant_label : '' );
		} elseif ( $price && ! empty( $price->id ) ) {
			$key = 'price:' . (string) $price->id;
		} else {
			$key = 'label:' . strtolower( $product_name . '|' . $variant_label );
		}

		$label = '' !== $variant_label ? $product_name . ' — ' . $variant_label : $product_name;

		return array(
			'key'           => $key,
			'label'         => $label,
			'product_name'  => $product_name,
			'variant_label' => $variant_label,
			'position'      => $variant && isset( $variant->position ) && is_numeric( $variant->position ) ? (int) $variant->position : null,
			'size_rank'     => $this->size_rank( $variant_label ),
			'sku'           => $this->first_non_empty(
				array(
					$variant && isset( $variant->sku ) ? $variant->sku : '',
					$product && isset( $product->sku ) ? $product->sku : '',
				)
			),
		);
	}

	/**
	 * Human-readable variant label ("XL", or "XL / Navy" for multi-option
	 * products).
	 *
	 * `line_item.variant_options` is the documented source ("An array of the
	 * associated variant's options") but the spec declares no element type,
	 * so both scalar and object/array elements are handled. The fallback is
	 * the variant object's own flat `option_1`/`option_2`/`option_3`, which
	 * the spec does confirm as strings. See discovery §H.
	 *
	 * @param object      $line_item Expanded line item.
	 * @param object|null $variant   Expanded variant, if any.
	 * @return string
	 */
	private function variant_label( $line_item, $variant ) {
		$values = array();

		if ( isset( $line_item->variant_options ) && is_array( $line_item->variant_options ) ) {
			foreach ( $line_item->variant_options as $option ) {
				$value = $this->scalarize_option( $option );

				if ( '' !== $value ) {
					$values[] = $value;
				}
			}
		}

		if ( empty( $values ) && $variant ) {
			foreach ( array( 'option_1', 'option_2', 'option_3' ) as $field ) {
				if ( isset( $variant->$field ) && '' !== trim( (string) $variant->$field ) ) {
					$values[] = trim( (string) $variant->$field );
				}
			}
		}

		return implode( ' / ', $values );
	}

	/**
	 * Coerce one `variant_options` element to a string, whatever shape
	 * SureCart returns it in.
	 *
	 * @param mixed $option Option element.
	 * @return string
	 */
	private function scalarize_option( $option ) {
		if ( is_scalar( $option ) ) {
			return trim( (string) $option );
		}

		$bag = is_object( $option ) ? get_object_vars( $option ) : ( is_array( $option ) ? $option : array() );

		foreach ( array( 'value', 'name', 'option', 'label' ) as $key ) {
			if ( isset( $bag[ $key ] ) && is_scalar( $bag[ $key ] ) ) {
				return trim( (string) $bag[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Rank a variant label against the apparel size order, so columns read
	 * S, M, L, XL rather than alphabetically. Returns null when the label
	 * isn't a recognizable size (a colour, say), leaving those columns to
	 * sort alphabetically among themselves.
	 *
	 * Only the first option segment is considered — for "XL / Navy" the
	 * size is what should drive column order.
	 *
	 * @param string $variant_label Variant label.
	 * @return int|null
	 */
	private function size_rank( $variant_label ) {
		if ( '' === $variant_label ) {
			return null;
		}

		$segments = explode( '/', $variant_label );

		foreach ( $segments as $segment ) {
			$normalized = preg_replace( '/[^a-z0-9]/', '', strtolower( $segment ) );

			if ( isset( self::SIZE_ORDER[ $normalized ] ) ) {
				return self::SIZE_ORDER[ $normalized ];
			}
		}

		return null;
	}

	/**
	 * Columns sorted for a cut sheet: grouped by product, then in the
	 * merchant's own variant order where SureCart gives us one, then by
	 * size, then alphabetically.
	 *
	 * @return array[]
	 */
	private function sorted_columns() {
		$columns = array_values( $this->columns );

		usort(
			$columns,
			static function ( $a, $b ) {
				$by_product = strcasecmp( $a['product_name'], $b['product_name'] );

				if ( 0 !== $by_product ) {
					return $by_product;
				}

				// A variant's own `position` is the merchant's chosen display
				// order, so it wins when both columns have one.
				if ( null !== $a['position'] && null !== $b['position'] && $a['position'] !== $b['position'] ) {
					return $a['position'] < $b['position'] ? -1 : 1;
				}

				$a_rank = null === $a['size_rank'] ? PHP_INT_MAX : $a['size_rank'];
				$b_rank = null === $b['size_rank'] ? PHP_INT_MAX : $b['size_rank'];

				if ( $a_rank !== $b_rank ) {
					return $a_rank < $b_rank ? -1 : 1;
				}

				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return $columns;
	}

	/**
	 * Customer rows sorted by name, then email, so the CSV is stable across
	 * runs instead of following SureCart's undefined pagination order.
	 *
	 * @return string[] Customer keys in output order.
	 */
	private function sorted_row_keys() {
		$keys = array_keys( $this->rows );
		$rows = $this->rows;

		usort(
			$keys,
			static function ( $a, $b ) use ( $rows ) {
				$by_name = strcasecmp( $rows[ $a ]['name'], $rows[ $b ]['name'] );

				if ( 0 !== $by_name ) {
					// Unnamed customers sort last rather than first.
					if ( '' === $rows[ $a ]['name'] ) {
						return 1;
					}

					if ( '' === $rows[ $b ]['name'] ) {
						return -1;
					}

					return $by_name;
				}

				return strcasecmp( $rows[ $a ]['email'], $rows[ $b ]['email'] );
			}
		);

		return $keys;
	}

	/**
	 * First non-empty trimmed string from a list of candidates.
	 *
	 * @param array $candidates Candidate values.
	 * @return string
	 */
	private function first_non_empty( array $candidates ) {
		foreach ( $candidates as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}

			$value = trim( (string) $candidate );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Join a first and last name, tolerating either being blank.
	 *
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return string
	 */
	private function join_name( $first, $last ) {
		return trim( trim( (string) $first ) . ' ' . trim( (string) $last ) );
	}
}
