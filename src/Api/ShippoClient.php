<?php
/**
 * Thin HTTP client for the Shippo API. Every call is logged (request id,
 * duration, outcome) per the engineering rules; every call has an
 * explicit timeout and only ever runs inside an Action Scheduler job.
 *
 * Endpoints, methods, and field names below are all sourced from
 * tasks/00-discovery.md §D — nothing here is guessed.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Api;

use BLT\SCE\Admin\SettingsPage;
use BLT\SCE\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ShippoClient
 */
final class ShippoClient {

	const BASE_URL = 'https://api.goshippo.com';

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Shared logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * The configured Shippo API token.
	 *
	 * @return string
	 */
	public function token() {
		return SettingsPage::shippo_token();
	}

	/**
	 * POST /shipments — quote rates for a shipment. async:false returns
	 * rates[] inline (confirmed synchronous behavior).
	 *
	 * @param array    $address_from Shippo AddressCreateRequest shape.
	 * @param array    $address_to   Shippo AddressCreateRequest shape.
	 * @param array    $parcel       Shippo ParcelCreateRequest shape.
	 * @param int|null $shipment_id  Local shipment row id, for logging.
	 * @return array|\WP_Error Decoded Shipment response, or WP_Error.
	 */
	public function create_shipment( array $address_from, array $address_to, array $parcel, $shipment_id = null ) {
		return $this->request(
			'POST',
			'/shipments/',
			array(
				'address_from' => $address_from,
				'address_to'   => $address_to,
				'parcels'      => array( $parcel ),
				'async'        => false,
			),
			$shipment_id
		);
	}

	/**
	 * POST /transactions — purchase a label for a previously quoted rate.
	 *
	 * @param string   $rate_id         Shippo Rate object_id.
	 * @param string   $label_file_type One of Shippo's LabelFileTypeEnum values.
	 * @param string   $metadata        Free-text metadata (e.g. the SureCart order id).
	 * @param int|null $shipment_id     Local shipment row id, for logging.
	 * @return array|\WP_Error Decoded Transaction response, or WP_Error.
	 */
	public function purchase_transaction( $rate_id, $label_file_type, $metadata, $shipment_id = null ) {
		return $this->request(
			'POST',
			'/transactions',
			array(
				'rate'            => $rate_id,
				'async'           => false,
				'label_file_type' => $label_file_type,
				'metadata'        => $metadata,
			),
			$shipment_id
		);
	}

	/**
	 * GET /v2/addresses/validate — validate a destination address before
	 * purchase. Returns `valid = null` (rather than false) when the
	 * service itself could not be reached/could not answer, so callers
	 * can distinguish "confirmed bad address" from "couldn't check" —
	 * Guardrails treats both as a hold, but the log/local error differs.
	 *
	 * @param array    $address     Fields: street1, city, state, zip, country (SureCart-mapped names).
	 * @param int|null $shipment_id Local shipment row id, for logging.
	 * @return array{valid: bool|null, raw: array|null}
	 */
	public function validate_address( array $address, $shipment_id = null ) {
		$query = array(
			'address_line_1' => isset( $address['street1'] ) ? $address['street1'] : '',
			'city_locality'  => isset( $address['city'] ) ? $address['city'] : '',
			'state_province' => isset( $address['state'] ) ? $address['state'] : '',
			'postal_code'    => isset( $address['zip'] ) ? $address['zip'] : '',
			'country_code'   => isset( $address['country'] ) ? $address['country'] : '',
		);

		$result = $this->request( 'GET', '/v2/addresses/validate?' . http_build_query( $query ), null, $shipment_id );

		if ( is_wp_error( $result ) ) {
			return array(
				'valid' => null,
				'raw'   => null,
			);
		}

		$value = isset( $result['analysis']['validation_result']['value'] ) ? $result['analysis']['validation_result']['value'] : null;

		if ( null === $value ) {
			return array(
				'valid' => null,
				'raw'   => $result,
			);
		}

		return array(
			'valid' => 'valid' === $value,
			'raw'   => $result,
		);
	}

	/**
	 * POST /refunds/ — request a refund (void) for a purchased label.
	 *
	 * @param string   $transaction_id Shippo Transaction object_id.
	 * @param int|null $shipment_id    Local shipment row id, for logging.
	 * @return array|\WP_Error Decoded Refund response, or WP_Error.
	 */
	public function refund_transaction( $transaction_id, $shipment_id = null ) {
		return $this->request(
			'POST',
			'/refunds/',
			array(
				'transaction' => $transaction_id,
				'async'       => false,
			),
			$shipment_id
		);
	}

	/**
	 * GET /tracks/{carrier}/{tracking_number} — direct tracking lookup,
	 * used by the reconciliation sweep to re-check a shipment that may
	 * have missed its webhook delivery.
	 *
	 * @param string $carrier         Shippo carrier token/slug (e.g. "usps") — lowercase, not the display name.
	 * @param string $tracking_number Carrier tracking number.
	 * @return array|\WP_Error Decoded Track response, or WP_Error.
	 */
	public function get_tracking_status( $carrier, $tracking_number ) {
		return $this->request( 'GET', '/tracks/' . rawurlencode( $carrier ) . '/' . rawurlencode( $tracking_number ), null );
	}

	/**
	 * GET /webhooks/ — list registered account webhooks.
	 *
	 * @return array|\WP_Error
	 */
	public function list_webhooks() {
		return $this->request( 'GET', '/webhooks/' );
	}

	/**
	 * POST /webhooks — register an account-wide webhook, but only if one
	 * for this exact url+event doesn't already exist. Shippo's webhook
	 * registration is explicitly NOT idempotent on url (unlike SureCart's),
	 * and duplicate registrations cause duplicate notifications, so we
	 * guard here rather than relying on the API.
	 *
	 * @param string $url   Callback URL.
	 * @param string $event Shippo event name (e.g. 'track_updated').
	 * @return array|\WP_Error|true Existing/created webhook data, or true if already present without re-fetching.
	 */
	public function ensure_webhook_registered( $url, $event ) {
		$existing = $this->list_webhooks();

		if ( ! is_wp_error( $existing ) && ! empty( $existing['results'] ) && is_array( $existing['results'] ) ) {
			foreach ( $existing['results'] as $webhook ) {
				if ( isset( $webhook['url'], $webhook['event'] ) && $webhook['url'] === $url && $webhook['event'] === $event ) {
					return true;
				}
			}
		}

		return $this->request(
			'POST',
			'/webhooks',
			array(
				'event' => $event,
				'url'   => $url,
			)
		);
	}

	/**
	 * Low-level request wrapper: auth header, JSON body, explicit
	 * timeout, and structured logging of every call.
	 *
	 * @param string   $method      HTTP method.
	 * @param string   $path        Path beginning with '/', may include a query string.
	 * @param array    $body        Request body to JSON-encode, or null for no body.
	 * @param int|null $shipment_id Local shipment row id, for logging.
	 * @return array|\WP_Error Decoded JSON response as an array, or WP_Error.
	 */
	private function request( $method, $path, ?array $body = null, $shipment_id = null ) {
		$token = $this->token();

		if ( '' === $token ) {
			return new \WP_Error( 'blt_sce_no_token', __( 'No Shippo API token is configured.', 'blt-surecart-extensions' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'ShippoToken ' . $token,
				'Content-Type'  => 'application/json',
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
			$this->logger->api_call( 'shippo', $method, $log_path, null, $duration, null, $shipment_id );

			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$request_id  = wp_remote_retrieve_header( $response, 'x-request-id' );
		$raw_body    = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $raw_body, true );

		$this->logger->api_call( 'shippo', $method, $log_path, $status_code, $duration, $request_id ? $request_id : null, $shipment_id );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new \WP_Error(
				'blt_sce_shippo_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: response body */
					__( 'Shippo API error (HTTP %1$d): %2$s', 'blt-surecart-extensions' ),
					$status_code,
					is_array( $decoded ) ? wp_json_encode( $decoded ) : substr( $raw_body, 0, 500 )
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
