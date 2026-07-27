<?php
/**
 * Receives Shippo tracking webhooks. Security per tasks/00-discovery.md
 * §D: HMAC requires a manual Shippo-side setup step (support ticket) and
 * its exact wire header name/casing is unverified from docs, so it is
 * layered in only as an optional add-on — the URL token (self-service)
 * plus an optional IP allowlist are the default, verified mechanisms.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Rest;

use BLT\SCE\Modules\ShippoFulfillment\StatusSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ShippoWebhookController
 */
final class ShippoWebhookController {

	const NAMESPACE_V1             = 'blt-sce/v1';
	const ROUTE                    = '/shippo-tracking';
	const OPT_TOKEN                = 'blt_sce_shippo_webhook_token';
	const OPT_IP_ALLOWLIST_ENABLED = 'blt_sce_shippo_webhook_ip_allowlist';
	const OPT_HMAC_SECRET          = 'blt_sce_shippo_webhook_hmac_secret';

	/**
	 * Shippo's published outbound webhook IPs (tasks/00-discovery.md §D).
	 *
	 * @var string[]
	 */
	const SHIPPO_IPS = array(
		'52.4.41.98',
		'52.23.121.194',
		'52.44.110.80',
		'54.81.253.187',
		'54.81.255.221',
		'34.248.247.69',
		'34.253.119.130',
		'52.214.174.64',
		'54.72.179.250',
	);

	/**
	 * Candidate header names for the HMAC signature — the docs only show
	 * a CGI-style env var rendering (HTTP_SHIPPO_AUTH_SIGNATURE), not a
	 * confirmed literal wire header, so several plausible casings are
	 * tried rather than assuming one.
	 *
	 * @var string[]
	 */
	const HMAC_HEADER_CANDIDATES = array( 'Shippo-Auth-Signature', 'X-Shippo-Auth-Signature', 'Shippo-Signature' );

	/**
	 * Status sync service.
	 *
	 * @var StatusSync
	 */
	private $status_sync;

	/**
	 * Constructor.
	 *
	 * @param StatusSync $status_sync Status sync service.
	 */
	public function __construct( StatusSync $status_sync ) {
		$this->status_sync = $status_sync;
	}

	/**
	 * Register the REST route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'verify_request' ),
			)
		);
	}

	/**
	 * The full callback URL, including the security token, to register
	 * with Shippo and to use for the IP-allowlist-only alternative.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return add_query_arg( 'token', self::token(), rest_url( self::NAMESPACE_V1 . self::ROUTE ) );
	}

	/**
	 * The webhook URL token, generated once and stored.
	 *
	 * @return string
	 */
	public static function token() {
		$token = get_option( self::OPT_TOKEN, '' );

		if ( '' === $token ) {
			$token = wp_generate_password( 32, false, false );
			update_option( self::OPT_TOKEN, $token );
		}

		return $token;
	}

	/**
	 * Verify an incoming request is really from Shippo before processing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function verify_request( \WP_REST_Request $request ) {
		if ( ! hash_equals( self::token(), (string) $request->get_param( 'token' ) ) ) {
			return false;
		}

		if ( get_option( self::OPT_IP_ALLOWLIST_ENABLED, false ) ) {
			$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

			/**
			 * Filters the allowlisted Shippo webhook source IPs, in case
			 * Shippo publishes additional ranges in the future.
			 *
			 * @param string[] $ips Allowlisted IPs.
			 */
			$allowed = apply_filters( 'blt_sce_shippo_webhook_ips', self::SHIPPO_IPS );

			if ( ! in_array( $remote_ip, $allowed, true ) ) {
				return false;
			}
		}

		return $this->maybe_verify_hmac( $request );
	}

	/**
	 * If an HMAC secret is configured, verify the signature when a
	 * recognizable header is present. Deliberately does not hard-fail
	 * when no candidate header is found at all — we can't confirm the
	 * real wire header name from documentation, and hard-failing on an
	 * unverified assumption risks silently dropping every legitimate
	 * webhook. It only ever rejects when a header IS found but the
	 * signature does NOT match.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function maybe_verify_hmac( \WP_REST_Request $request ) {
		$secret = get_option( self::OPT_HMAC_SECRET, '' );

		if ( '' === $secret ) {
			return true;
		}

		$signature_header = null;

		foreach ( self::HMAC_HEADER_CANDIDATES as $candidate ) {
			$value = $request->get_header( $candidate );

			if ( $value ) {
				$signature_header = $value;
				break;
			}
		}

		if ( ! $signature_header ) {
			return true;
		}

		$parts = array();

		foreach ( explode( ',', $signature_header ) as $piece ) {
			$kv = explode( '=', $piece, 2 );

			if ( 2 === count( $kv ) ) {
				$parts[ trim( $kv[0] ) ] = trim( $kv[1] );
			}
		}

		if ( ! isset( $parts['t'], $parts['v1'] ) ) {
			return true;
		}

		$signed_payload = $parts['t'] . '.' . $request->get_body();
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

		return hash_equals( $expected, $parts['v1'] );
	}

	/**
	 * Handle the tracking update.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( is_array( $body ) ) {
			$this->status_sync->handle_tracking_update( $body );
		}

		// Acknowledge receipt immediately regardless of internal outcome —
		// processing issues are logged internally; a non-200 here would
		// just cause Shippo to retry the same payload.
		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}
}
