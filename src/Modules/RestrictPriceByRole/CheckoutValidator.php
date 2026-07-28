<?php
/**
 * Server-side enforcement gate (Layer 4). Even if a restricted price
 * somehow ends up in the cart (JS disabled, direct API call), this filter
 * rejects the checkout with a human-readable error.
 *
 * The `surecart/checkout/validate` filter is carried over verbatim from
 * the shipped standalone "Restrict Price by User Role" v1.0.0 plugin.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\RestrictPriceByRole;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CheckoutValidator
 */
final class CheckoutValidator {

	/**
	 * Restriction map service.
	 *
	 * @var Restrictions
	 */
	private $restrictions;

	/**
	 * Constructor.
	 *
	 * @param Restrictions $restrictions Restriction map service.
	 */
	public function __construct( Restrictions $restrictions ) {
		$this->restrictions = $restrictions;
	}

	/**
	 * Register the checkout validation filter.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'surecart/checkout/validate', array( $this, 'validate_checkout_prices' ), 10, 3 );
	}

	/**
	 * Validate that the current user is allowed to purchase every price
	 * present in the checkout's line items.
	 *
	 * @param \WP_Error        $errors  Existing validation errors.
	 * @param array            $args    Checkout form data (line_items, email, metadata…).
	 * @param \WP_REST_Request $request The originating REST request.
	 * @return \WP_Error
	 */
	public function validate_checkout_prices( $errors, $args, $request ) {
		unset( $request );

		if ( empty( $this->restrictions->get_map() ) ) {
			return $errors;
		}

		$line_items = ! empty( $args['line_items'] ) ? $args['line_items'] : array();

		foreach ( $line_items as $line_item ) {
			$price_id = $this->extract_price_id( $line_item );

			if ( $price_id && $this->restrictions->is_restricted_for_current_user( $price_id ) ) {
				$errors->add(
					'blt_sce_rpbr_restricted_price',
					esc_html__(
						'You do not have permission to purchase one or more items in your cart. Please contact support for assistance.',
						'blt-surecart-extensions'
					)
				);
				// One error is sufficient — no need to enumerate every blocked price.
				break;
			}
		}

		return $errors;
	}

	/**
	 * Pull the price ID out of a line item regardless of its shape.
	 *
	 * SureCart may pass line items as objects or associative arrays, and the
	 * "price" field may itself be an object/array (expanded) or a string ID.
	 *
	 * @param mixed $line_item A single line item.
	 * @return string Price ID or empty string.
	 */
	private function extract_price_id( $line_item ) {
		// Object form.
		if ( is_object( $line_item ) && ! empty( $line_item->price ) ) {
			return is_object( $line_item->price ) ? $line_item->price->id : (string) $line_item->price;
		}

		// Array form.
		if ( is_array( $line_item ) && ! empty( $line_item['price'] ) ) {
			if ( is_array( $line_item['price'] ) ) {
				return ! empty( $line_item['price']['id'] ) ? $line_item['price']['id'] : '';
			}
			return (string) $line_item['price'];
		}

		return '';
	}
}
