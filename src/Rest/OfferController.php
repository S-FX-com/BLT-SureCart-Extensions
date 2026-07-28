<?php
/**
 * Customer-facing REST endpoints for the Make an Offer module, at the
 * standalone scaffold's namespace (sc-offer/v1) so the documented
 * frontend flow carries over unchanged:
 *
 *   POST /submit                   create offer + SetupIntent
 *   POST /confirm                  verify card vaulted, notify merchant
 *   GET  /counter/{id}/respond     customer accepts/declines a counter
 *
 * Merchant actions (accept/decline/counter) are NOT REST — they're
 * nonce-checked admin-post actions on the Offers screen.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Rest;

use BLT\SCE\Modules\MakeAnOffer\EmailNotifier;
use BLT\SCE\Modules\MakeAnOffer\OfferManager;
use BLT\SCE\Modules\MakeAnOffer\OfferPostType;
use BLT\SCE\Modules\MakeAnOffer\OfferRepository;
use BLT\SCE\Modules\MakeAnOffer\ProductCatalog;
use BLT\SCE\Modules\MakeAnOffer\Settings;
use BLT\SCE\Modules\MakeAnOffer\StripeService;
use BLT\SCE\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OfferController
 */
final class OfferController {

	const REST_NAMESPACE = 'sc-offer/v1';

	/**
	 * Repository.
	 *
	 * @var OfferRepository
	 */
	private $repository;

	/**
	 * Stripe flows.
	 *
	 * @var StripeService
	 */
	private $stripe;

	/**
	 * Lifecycle orchestration.
	 *
	 * @var OfferManager
	 */
	private $manager;

	/**
	 * Product lookups.
	 *
	 * @var ProductCatalog
	 */
	private $catalog;

	/**
	 * Emails.
	 *
	 * @var EmailNotifier
	 */
	private $emails;

	/**
	 * Constructor.
	 *
	 * @param OfferRepository $repository Offer repository.
	 * @param StripeService   $stripe     Stripe flows.
	 * @param OfferManager    $manager    Lifecycle orchestration.
	 * @param ProductCatalog  $catalog    Product lookups.
	 * @param EmailNotifier   $emails     Email notifications.
	 */
	public function __construct( OfferRepository $repository, StripeService $stripe, OfferManager $manager, ProductCatalog $catalog, EmailNotifier $emails ) {
		$this->repository = $repository;
		$this->stripe     = $stripe;
		$this->manager    = $manager;
		$this->catalog    = $catalog;
		$this->emails     = $emails;
	}

	/**
	 * Register routes. Runs on rest_api_init.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_submit' ),
				'permission_callback' => array( $this, 'verify_rest_nonce' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_confirm' ),
				'permission_callback' => array( $this, 'verify_rest_nonce' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/counter/(?P<id>\d+)/respond',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_counter_response' ),
				// Auth is the HMAC token in the link (the customer has no
				// session) — validated in the handler.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * CSRF check for the browser-driven endpoints: the standard WP REST
	 * nonce, sent as X-WP-Nonce by offer.js. Valid for guests too (it
	 * simply authenticates them as user 0).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function verify_rest_nonce( $request ) {
		return (bool) wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
	}

	/**
	 * POST /submit — validate, create the offer, start card setup, and
	 * return the SetupIntent client_secret for Stripe.js.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_submit( $request ) {
		$product_id = sanitize_text_field( (string) $request->get_param( 'product_id' ) );
		$email      = sanitize_email( (string) $request->get_param( 'email' ) );
		$name       = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$message    = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$amount     = Money::decimal_string_to_cents( (string) $request->get_param( 'amount' ) );

		if ( '' === $product_id || ! is_email( $email ) ) {
			return new \WP_Error( 'blt_sce_offer_invalid', __( 'Please provide a valid email address.', 'blt-surecart-extensions' ), array( 'status' => 400 ) );
		}

		if ( $amount < 1 ) {
			return new \WP_Error( 'blt_sce_offer_invalid', __( 'Offer amount must be greater than zero.', 'blt-surecart-extensions' ), array( 'status' => 400 ) );
		}

		$product = $this->catalog->summary( $product_id );

		if ( null === $product ) {
			return new \WP_Error( 'blt_sce_offer_no_product', __( 'This product is not accepting offers right now.', 'blt-surecart-extensions' ), array( 'status' => 400 ) );
		}

		$min_pct = (int) Settings::get( 'min_pct' );

		// Integer math: amount/list >= pct/100  <=>  amount*100 >= list*pct.
		if ( $min_pct > 0 && $amount * 100 < $product['list_price'] * $min_pct ) {
			return new \WP_Error(
				'blt_sce_offer_too_low',
				sprintf(
					/* translators: %s: minimum offer amount */
					__( 'Your offer is too low. The minimum offer for this product is %s.', 'blt-surecart-extensions' ),
					strtoupper( $product['currency'] ) . ' ' . Money::cents_to_decimal_string( (int) ceil( $product['list_price'] * $min_pct / 100 ) )
				),
				array( 'status' => 400 )
			);
		}

		// One active offer per email per product.
		$existing_id = $this->repository->find_active_for( $email, $product_id );

		if ( $existing_id ) {
			if ( 'supersede' === Settings::get( 'resubmit_policy' ) ) {
				$this->repository->set_status( $existing_id, OfferPostType::STATUS_CANCELLED );
			} else {
				return new \WP_Error( 'blt_sce_offer_duplicate', __( 'You already have an offer pending for this product. Please wait for a response before submitting another.', 'blt-surecart-extensions' ), array( 'status' => 409 ) );
			}
		}

		$offer_id = $this->repository->create(
			array(
				'product_id'     => $product_id,
				'product_name'   => $product['name'],
				'variant_id'     => sanitize_text_field( (string) $request->get_param( 'variant_id' ) ),
				'amount'         => $amount,
				'list_price'     => $product['list_price'],
				'currency'       => $product['currency'],
				'message'        => $message,
				'customer_email' => $email,
				'customer_name'  => $name,
			)
		);

		if ( is_wp_error( $offer_id ) ) {
			return $offer_id;
		}

		$setup = $this->stripe->begin_card_setup( $this->repository->find( $offer_id ) );

		if ( is_wp_error( $setup ) ) {
			// Don't leave an unusable pending offer behind.
			$this->repository->set_status( $offer_id, OfferPostType::STATUS_CANCELLED );

			return new \WP_Error( 'blt_sce_offer_stripe', __( 'We could not start the payment setup. Please try again later.', 'blt-surecart-extensions' ), array( 'status' => 502 ) );
		}

		return rest_ensure_response(
			array(
				'offer_id'      => (int) $offer_id,
				'client_secret' => $setup['client_secret'],
			)
		);
	}

	/**
	 * POST /confirm — after stripe.confirmCardSetup succeeds in the
	 * browser, verify it server-side, notify the merchant, and run the
	 * auto-accept check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_confirm( $request ) {
		$offer_id = (int) $request->get_param( 'offer_id' );
		$offer    = $this->repository->find( $offer_id );

		if ( ! $offer || OfferPostType::STATUS_PENDING !== $offer->status ) {
			return new \WP_Error( 'blt_sce_offer_not_found', __( 'Offer not found.', 'blt-surecart-extensions' ), array( 'status' => 404 ) );
		}

		if ( $offer->pm_confirmed ) {
			return rest_ensure_response( array( 'confirmed' => true ) );
		}

		$result = $this->stripe->confirm_card_setup( $offer );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'blt_sce_offer_confirm_failed', $result->get_error_message(), array( 'status' => 400 ) );
		}

		$offer = $this->repository->find( $offer_id );

		$auto_accepted = $this->manager->maybe_auto_accept( $offer );

		if ( ! $auto_accepted ) {
			$this->emails->merchant_new_offer( $offer );
		}

		return rest_ensure_response( array( 'confirmed' => true ) );
	}

	/**
	 * GET /counter/{id}/respond — customer clicks an accept/decline link
	 * from the counter-offer email. Returns a minimal human-readable
	 * page, since this lands in a browser tab, not an XHR.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void Outputs HTML and exits.
	 */
	public function handle_counter_response( $request ) {
		$offer_id = (int) $request['id'];
		$decision = 'accept' === $request->get_param( 'decision' ) ? 'accept' : 'decline';
		$token    = (string) $request->get_param( 'token' );

		$result = $this->manager->respond_to_counter( $offer_id, $decision, $token );

		if ( is_wp_error( $result ) ) {
			$this->render_counter_page( __( 'Link not valid', 'blt-surecart-extensions' ), $result->get_error_message() );
		}

		if ( 'accept' === $decision ) {
			$this->render_counter_page(
				__( 'Counter-offer accepted', 'blt-surecart-extensions' ),
				__( 'Thank you! Your card will be charged shortly and you will receive a confirmation email.', 'blt-surecart-extensions' )
			);
		}

		$this->render_counter_page(
			__( 'Counter-offer declined', 'blt-surecart-extensions' ),
			__( 'No problem — your card will not be charged. You are welcome to make a new offer any time.', 'blt-surecart-extensions' )
		);
	}

	/**
	 * Output a minimal standalone HTML response for the email-link flow.
	 *
	 * @param string $title   Heading.
	 * @param string $message Body text.
	 * @return void Exits.
	 */
	private function render_counter_page( $title, $message ) {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		echo '<!DOCTYPE html><html><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width,initial-scale=1" /><title>' . esc_html( $title ) . '</title></head>';
		echo '<body style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;max-width:480px;margin:80px auto;padding:0 20px;color:#1e1e1e;">';
		echo '<h1 style="font-size:22px;">' . esc_html( $title ) . '</h1>';
		echo '<p style="line-height:1.6;">' . esc_html( $message ) . '</p>';
		echo '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a></p>';
		echo '</body></html>';
		exit;
	}
}
