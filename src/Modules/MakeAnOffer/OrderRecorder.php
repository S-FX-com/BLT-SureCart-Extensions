<?php
/**
 * Records an accepted (and already Stripe-charged) offer as a real
 * SureCart order, so accepted offers flow into SureCart's orders,
 * customer records, purchases — and the Shippo Fulfillment module's
 * order-paid trigger.
 *
 * Flow (every endpoint verified in tasks/00-discovery.md §G):
 *   1. Ensure a "pay what you want" (ad_hoc) price exists for the
 *      product (created once, cached in an option).
 *   2. Ensure a manual payment method exists so the order carries a
 *      meaningful payment label (created once, cached in an option).
 *   3. POST /v1/checkouts with the ad_hoc line item at the accepted
 *      amount, the customer email, and audit metadata (offer ID +
 *      Stripe PaymentIntent ID).
 *   4. PATCH …/finalize?manual_payment=true — no processor involved;
 *      the money already moved via Stripe.
 *   5. PATCH …/manually_pay — SureCart marks the checkout paid and
 *      creates the order (and purchases).
 *
 * Hook-free; runs only inside an Action Scheduler job. Failure here
 * never un-accepts an offer — the Stripe charge is the financial truth,
 * and recording is retryable from the offer detail screen.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

use BLT\SCE\Api\SureCartApiClient;
use BLT\SCE\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderRecorder
 */
final class OrderRecorder {

	const OPT_MANUAL_PAYMENT_METHOD = 'blt_sce_offer_sc_mpm_id';
	const OPT_ADHOC_PRICE_MAP       = 'blt_sce_offer_sc_adhoc_prices';

	/**
	 * SureCart platform API client.
	 *
	 * @var SureCartApiClient
	 */
	private $client;

	/**
	 * Repository.
	 *
	 * @var OfferRepository
	 */
	private $repository;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param SureCartApiClient $client     SureCart platform API client.
	 * @param OfferRepository   $repository Offer repository.
	 * @param Logger            $logger     Shared logger.
	 */
	public function __construct( SureCartApiClient $client, OfferRepository $repository, Logger $logger ) {
		$this->client     = $client;
		$this->repository = $repository;
		$this->logger     = $logger;
	}

	/**
	 * Record one accepted offer as a manually-paid SureCart order.
	 *
	 * @param object $offer        Offer object (already accepted).
	 * @param int    $amount_cents Amount that was actually charged.
	 * @return string|\WP_Error The new SureCart order UUID.
	 */
	public function record( $offer, $amount_cents ) {
		if ( '' !== $offer->sc_order_id ) {
			return $offer->sc_order_id; // Already recorded — idempotent.
		}

		if ( ! $this->client->has_token() ) {
			return new \WP_Error( 'blt_sce_no_sc_token', __( 'No SureCart API token is configured (Offer Settings).', 'blt-surecart-extensions' ) );
		}

		$price_id = $this->ensure_adhoc_price( $offer->product_id, $offer->product_name );

		if ( is_wp_error( $price_id ) ) {
			return $price_id;
		}

		$payment_method_id = $this->ensure_manual_payment_method();

		if ( is_wp_error( $payment_method_id ) ) {
			return $payment_method_id;
		}

		$line_item = array(
			'price'         => $price_id,
			'quantity'      => 1,
			'ad_hoc_amount' => (int) $amount_cents,
		);

		if ( '' !== $offer->variant_id ) {
			$line_item['variant'] = $offer->variant_id;
		}

		$checkout = $this->client->create_checkout(
			array(
				'email'      => $offer->customer_email,
				'metadata'   => array(
					'blt_sce_offer_id'         => (string) $offer->id,
					'blt_sce_stripe_pi_id'     => $offer->stripe_pi_id,
					'blt_sce_offer_list_price' => (string) $offer->list_price,
				),
				'line_items' => array( $line_item ),
			)
		);

		if ( is_wp_error( $checkout ) ) {
			return $checkout;
		}

		if ( empty( $checkout['id'] ) ) {
			return new \WP_Error( 'blt_sce_sc_no_checkout_id', __( 'SureCart checkout was created but returned no ID.', 'blt-surecart-extensions' ) );
		}

		$finalized = $this->client->finalize_checkout_manual( $checkout['id'], $payment_method_id );

		if ( is_wp_error( $finalized ) ) {
			return $finalized;
		}

		$paid = $this->client->manually_pay_checkout( $checkout['id'] );

		if ( is_wp_error( $paid ) ) {
			return $paid;
		}

		// The order comes back as a UUID string, or expanded as an object.
		$order_id = '';

		if ( ! empty( $paid['order'] ) ) {
			$order_id = is_array( $paid['order'] ) ? (string) ( $paid['order']['id'] ?? '' ) : (string) $paid['order'];
		}

		if ( '' === $order_id ) {
			return new \WP_Error( 'blt_sce_sc_no_order', __( 'SureCart marked the checkout paid but returned no order ID.', 'blt-surecart-extensions' ) );
		}

		$this->repository->set_meta( $offer->id, OfferRepository::META_SC_ORDER_ID, $order_id );

		$this->logger->info(
			'Accepted offer recorded as SureCart order.',
			array(
				'offer_id'    => $offer->id,
				'sc_order_id' => $order_id,
				'checkout_id' => $checkout['id'],
			)
		);

		return $order_id;
	}

	/**
	 * Get (or create once and cache) the ad_hoc "Accepted Offer" price
	 * for a product. `ad_hoc: true` means no fixed amount is required —
	 * the checkout line item supplies `ad_hoc_amount`.
	 *
	 * @param string $product_id   SureCart product UUID.
	 * @param string $product_name Product name, for the price label.
	 * @return string|\WP_Error Price UUID.
	 */
	private function ensure_adhoc_price( $product_id, $product_name ) {
		$map = get_option( self::OPT_ADHOC_PRICE_MAP, array() );

		if ( is_array( $map ) && ! empty( $map[ $product_id ] ) ) {
			return $map[ $product_id ];
		}

		$price = $this->client->create_price(
			array(
				'product_id' => $product_id,
				'ad_hoc'     => true,
				'name'       => sprintf(
					/* translators: %s: product name */
					__( 'Accepted Offer — %s', 'blt-surecart-extensions' ),
					$product_name ? $product_name : $product_id
				),
			)
		);

		if ( is_wp_error( $price ) ) {
			return $price;
		}

		if ( empty( $price['id'] ) ) {
			return new \WP_Error( 'blt_sce_sc_no_price_id', __( 'SureCart price was created but returned no ID.', 'blt-surecart-extensions' ) );
		}

		$map                = is_array( $map ) ? $map : array();
		$map[ $product_id ] = $price['id'];
		update_option( self::OPT_ADHOC_PRICE_MAP, $map, false );

		return $price['id'];
	}

	/**
	 * Get (or create once and cache) the manual payment method that
	 * labels these orders in SureCart's admin.
	 *
	 * @return string|\WP_Error Manual payment method UUID.
	 */
	private function ensure_manual_payment_method() {
		$id = get_option( self::OPT_MANUAL_PAYMENT_METHOD, '' );

		if ( '' !== $id ) {
			return $id;
		}

		$method = $this->client->create_manual_payment_method(
			array(
				'name'        => __( 'Make an Offer (charged via Stripe)', 'blt-surecart-extensions' ),
				'description' => __( 'Accepted offer, charged off-session to the card vaulted at offer submission.', 'blt-surecart-extensions' ),
			)
		);

		if ( is_wp_error( $method ) ) {
			return $method;
		}

		if ( empty( $method['id'] ) ) {
			return new \WP_Error( 'blt_sce_sc_no_mpm_id', __( 'SureCart manual payment method was created but returned no ID.', 'blt-surecart-extensions' ) );
		}

		update_option( self::OPT_MANUAL_PAYMENT_METHOD, $method['id'], false );

		return $method['id'];
	}
}
