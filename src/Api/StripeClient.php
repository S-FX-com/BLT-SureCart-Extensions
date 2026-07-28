<?php
/**
 * Thin HTTP client for the Stripe API, following the same conventions as
 * ShippoClient: every call is logged (request id, duration, outcome) and
 * has an explicit timeout. No Stripe SDK — the four endpoints this plugin
 * needs don't justify vendoring one.
 *
 * Card data never touches this client: the browser talks to Stripe.js
 * directly, and this client only ever handles Stripe object IDs
 * (cus_…, seti_…, pm_…, pi_…).
 *
 * When a connected-account ID is configured, it is sent as the
 * Stripe-Account header so calls operate on the connected account rather
 * than the platform account (required when vaulted payment methods live
 * on a connected account — see the Make an Offer module settings).
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Api;

use BLT\SCE\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StripeClient
 */
final class StripeClient {

	const BASE_URL = 'https://api.stripe.com';

	/**
	 * Pinned API version so Stripe behavior doesn't shift under us when
	 * the account's default version changes.
	 */
	const API_VERSION = '2024-06-20';

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Stripe secret key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Optional connected account ID (acct_…), sent as Stripe-Account.
	 *
	 * @var string
	 */
	private $stripe_account;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger         Shared logger.
	 * @param string $secret_key     Stripe secret key.
	 * @param string $stripe_account Optional connected account ID.
	 */
	public function __construct( Logger $logger, $secret_key, $stripe_account = '' ) {
		$this->logger         = $logger;
		$this->secret_key     = (string) $secret_key;
		$this->stripe_account = (string) $stripe_account;
	}

	/**
	 * POST /v1/customers — create a customer to vault the payment method
	 * against (off-session charges require the payment method to be
	 * attached to a customer).
	 *
	 * @param string $email Customer email.
	 * @param string $name  Customer name.
	 * @return array|\WP_Error
	 */
	public function create_customer( $email, $name ) {
		return $this->request(
			'POST',
			'/v1/customers',
			array(
				'email' => $email,
				'name'  => $name,
			)
		);
	}

	/**
	 * POST /v1/setup_intents — vault a card for a later off-session charge.
	 *
	 * @param string $customer_id Stripe customer ID.
	 * @param array  $metadata    Key/value metadata (e.g. offer post ID).
	 * @return array|\WP_Error
	 */
	public function create_setup_intent( $customer_id, array $metadata = array() ) {
		return $this->request(
			'POST',
			'/v1/setup_intents',
			array(
				'customer'             => $customer_id,
				'usage'                => 'off_session',
				'payment_method_types' => array( 'card' ),
				'metadata'             => $metadata,
			)
		);
	}

	/**
	 * GET /v1/setup_intents/{id} — used to verify the browser-reported
	 * confirmation server-side before trusting it.
	 *
	 * @param string $setup_intent_id SetupIntent ID.
	 * @return array|\WP_Error
	 */
	public function retrieve_setup_intent( $setup_intent_id ) {
		return $this->request( 'GET', '/v1/setup_intents/' . rawurlencode( $setup_intent_id ) );
	}

	/**
	 * POST /v1/payment_intents — charge a vaulted payment method
	 * off-session, confirming immediately.
	 *
	 * @param int    $amount_cents    Amount in cents (Stripe's native unit).
	 * @param string $currency        Lowercase ISO currency code.
	 * @param string $customer_id     Stripe customer ID.
	 * @param string $payment_method  Vaulted PaymentMethod ID.
	 * @param array  $metadata        Key/value metadata.
	 * @param string $idempotency_key Idempotency key so a retried job can't double-charge.
	 * @return array|\WP_Error
	 */
	public function create_payment_intent( $amount_cents, $currency, $customer_id, $payment_method, array $metadata, $idempotency_key ) {
		return $this->request(
			'POST',
			'/v1/payment_intents',
			array(
				'amount'         => (int) $amount_cents,
				'currency'       => $currency,
				'customer'       => $customer_id,
				'payment_method' => $payment_method,
				'off_session'    => 'true',
				'confirm'        => 'true',
				'metadata'       => $metadata,
			),
			$idempotency_key
		);
	}

	/**
	 * POST /v1/payment_methods/{id}/detach — release a vaulted card when
	 * an offer is declined, expired, or cancelled.
	 *
	 * @param string $payment_method_id PaymentMethod ID.
	 * @return array|\WP_Error
	 */
	public function detach_payment_method( $payment_method_id ) {
		return $this->request( 'POST', '/v1/payment_methods/' . rawurlencode( $payment_method_id ) . '/detach' );
	}

	/**
	 * Low-level request wrapper: auth header, form-encoded body (Stripe's
	 * wire format), explicit timeout, structured logging of every call.
	 *
	 * @param string     $method          HTTP method.
	 * @param string     $path            Path beginning with '/'.
	 * @param array|null $params          Request params, form-encoded, or null for no body.
	 * @param string     $idempotency_key Optional Idempotency-Key header value.
	 * @return array|\WP_Error Decoded JSON response as an array, or WP_Error.
	 */
	private function request( $method, $path, ?array $params = null, $idempotency_key = '' ) {
		if ( '' === $this->secret_key ) {
			return new \WP_Error( 'blt_sce_no_stripe_key', __( 'No Stripe secret key is configured.', 'blt-surecart-extensions' ) );
		}

		$headers = array(
			'Authorization'  => 'Bearer ' . $this->secret_key,
			'Stripe-Version' => self::API_VERSION,
			'Content-Type'   => 'application/x-www-form-urlencoded',
		);

		if ( '' !== $this->stripe_account ) {
			$headers['Stripe-Account'] = $this->stripe_account;
		}

		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => $headers,
		);

		if ( null !== $params ) {
			$args['body'] = http_build_query( $params );
		}

		$start    = microtime( true );
		$response = wp_remote_request( self::BASE_URL . $path, $args );
		$duration = ( microtime( true ) - $start ) * 1000;

		if ( is_wp_error( $response ) ) {
			$this->logger->api_call( 'stripe', $method, $path, null, $duration );

			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$request_id  = wp_remote_retrieve_header( $response, 'request-id' );
		$decoded     = json_decode( wp_remote_retrieve_body( $response ), true );

		$this->logger->api_call( 'stripe', $method, $path, $status_code, $duration, $request_id ? $request_id : null );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$stripe_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : '';

			return new \WP_Error(
				'blt_sce_stripe_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: Stripe error message */
					__( 'Stripe API error (HTTP %1$d): %2$s', 'blt-surecart-extensions' ),
					$status_code,
					$stripe_message ? $stripe_message : __( 'unknown error', 'blt-surecart-extensions' )
				),
				array(
					'status' => $status_code,
					'body'   => $decoded,
				)
			);
		}

		return is_array( $decoded ) ? $decoded : array();
	}
}
