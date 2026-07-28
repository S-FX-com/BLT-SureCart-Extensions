<?php
/**
 * Thin HTTP client for SureCart's platform REST API (api.surecart.com),
 * authenticated with a merchant-created API token. Same conventions as
 * ShippoClient/StripeClient: every call logged, explicit timeout, only
 * ever called inside an Action Scheduler job.
 *
 * This exists alongside SureCartGateway (which uses SureCart's PHP
 * models) because the checkout lifecycle verbs this plugin needs —
 * finalize with manual payment, manually_pay — are documented REST
 * endpoints but are NOT documented on the PHP models, whose docs only
 * cover CRUD. Endpoints, wrappers, and field names below are all sourced
 * from tasks/00-discovery.md §G — nothing here is guessed.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Api;

use BLT\SCE\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SureCartApiClient
 */
final class SureCartApiClient {

	const BASE_URL = 'https://api.surecart.com';

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * SureCart API token (Bearer).
	 *
	 * @var string
	 */
	private $api_token;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger    Shared logger.
	 * @param string $api_token SureCart API token.
	 */
	public function __construct( Logger $logger, $api_token ) {
		$this->logger    = $logger;
		$this->api_token = (string) $api_token;
	}

	/**
	 * Whether a token is configured at all.
	 *
	 * @return bool
	 */
	public function has_token() {
		return '' !== $this->api_token;
	}

	/**
	 * POST /v1/prices — create a price. With `ad_hoc: true` the price
	 * accepts customer-defined amounts and `amount` is not required.
	 *
	 * @param array $price Price fields (product_id, ad_hoc, name, …).
	 * @return array|\WP_Error
	 */
	public function create_price( array $price ) {
		return $this->request( 'POST', '/v1/prices', array( 'price' => $price ) );
	}

	/**
	 * POST /v1/manual_payment_methods — create a manual payment method
	 * (only `name` is required).
	 *
	 * @param array $manual_payment_method Fields (name, description, …).
	 * @return array|\WP_Error
	 */
	public function create_manual_payment_method( array $manual_payment_method ) {
		return $this->request( 'POST', '/v1/manual_payment_methods', array( 'manual_payment_method' => $manual_payment_method ) );
	}

	/**
	 * POST /v1/checkouts — create a draft checkout. Line items accept
	 * `{ price, quantity, ad_hoc_amount, variant }`; `email` associates
	 * the existing customer with that address if one exists.
	 *
	 * @param array $checkout Checkout fields (email, line_items, metadata, …).
	 * @return array|\WP_Error
	 */
	public function create_checkout( array $checkout ) {
		return $this->request( 'POST', '/v1/checkouts', array( 'checkout' => $checkout ) );
	}

	/**
	 * PATCH /v1/checkouts/{id}/finalize?manual_payment=true — finalize
	 * the checkout while skipping payment-processor integration
	 * ("Skip payment processor integration", per the finalize docs).
	 *
	 * @param string $checkout_id              Checkout UUID.
	 * @param string $manual_payment_method_id Optional manual payment method UUID for the order's payment label.
	 * @return array|\WP_Error
	 */
	public function finalize_checkout_manual( $checkout_id, $manual_payment_method_id = '' ) {
		$query = array( 'manual_payment' => 'true' );

		if ( '' !== $manual_payment_method_id ) {
			$query['manual_payment_method_id'] = $manual_payment_method_id;
		}

		return $this->request( 'PATCH', '/v1/checkouts/' . rawurlencode( $checkout_id ) . '/finalize?' . http_build_query( $query ) );
	}

	/**
	 * PATCH /v1/checkouts/{id}/manually_pay — mark a finalized checkout
	 * as paid. Per the docs: "associated purchases and subscriptions
	 * will be created", and the response carries the new order UUID.
	 *
	 * @param string $checkout_id Checkout UUID.
	 * @return array|\WP_Error
	 */
	public function manually_pay_checkout( $checkout_id ) {
		return $this->request( 'PATCH', '/v1/checkouts/' . rawurlencode( $checkout_id ) . '/manually_pay' );
	}

	/**
	 * Low-level request wrapper: Bearer auth, JSON body, explicit
	 * timeout, structured logging of every call.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path beginning with '/', may include a query string.
	 * @param array|null $body   Request body to JSON-encode, or null for no body.
	 * @return array|\WP_Error Decoded JSON response as an array, or WP_Error.
	 */
	private function request( $method, $path, ?array $body = null ) {
		if ( ! $this->has_token() ) {
			return new \WP_Error( 'blt_sce_no_sc_token', __( 'No SureCart API token is configured.', 'blt-surecart-extensions' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$start    = microtime( true );
		$response = wp_remote_request( self::BASE_URL . $path, $args );
		$duration = ( microtime( true ) - $start ) * 1000;

		$log_path = preg_replace( '/\?.*$/', '', $path );

		if ( is_wp_error( $response ) ) {
			$this->logger->api_call( 'surecart', $method, $log_path, null, $duration );

			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		$this->logger->api_call( 'surecart', $method, $log_path, $status_code, $duration );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new \WP_Error(
				'blt_sce_surecart_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: response body */
					__( 'SureCart API error (HTTP %1$d): %2$s', 'blt-surecart-extensions' ),
					$status_code,
					is_array( $decoded ) ? wp_json_encode( $decoded ) : substr( (string) $raw_body, 0, 500 )
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
