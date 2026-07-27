<?php
/**
 * Safety gates around label purchasing. Auto-purchasing labels spends
 * real money on a client's Shippo account — every check here exists
 * because it is cheap now and expensive to retrofit after a bad label
 * purchase.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\ShippoFulfillment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Guardrails
 */
final class Guardrails {

	const OPT_KILL_SWITCH          = 'blt_sce_shippo_kill_switch';
	const OPT_AUTO_PURCHASE        = 'blt_sce_shippo_auto_purchase';
	const OPT_MODE                 = 'blt_sce_shippo_mode';
	const OPT_RATE_CEILING_CENTS   = 'blt_sce_shippo_rate_ceiling_cents';
	const OPT_RATE_CEILING_PERCENT = 'blt_sce_shippo_rate_ceiling_percent';
	const OPT_ALLOWED_COUNTRIES    = 'blt_sce_shippo_allowed_countries';
	const OPT_ALLOW_MILITARY       = 'blt_sce_shippo_allow_military';

	const MODE_TEST = 'test';
	const MODE_LIVE = 'live';

	/**
	 * US military "state" codes used for APO/FPO/DPO addresses.
	 *
	 * @var string[]
	 */
	const MILITARY_STATE_CODES = array( 'AA', 'AE', 'AP' );

	/**
	 * Global kill switch — halts all purchasing without deactivating the
	 * plugin or touching existing records.
	 *
	 * @return bool
	 */
	public function is_halted() {
		return (bool) get_option( self::OPT_KILL_SWITCH, false );
	}

	/**
	 * Whether the site owner has opted in to automatic purchasing. Ships
	 * off by default — new installs quote and queue for manual approval.
	 *
	 * @return bool
	 */
	public function auto_purchase_enabled() {
		return (bool) get_option( self::OPT_AUTO_PURCHASE, false );
	}

	/**
	 * Configured mode: 'test' or 'live'. Determines which Shippo token is
	 * expected and used.
	 *
	 * @return string
	 */
	public function configured_mode() {
		$mode = get_option( self::OPT_MODE, self::MODE_TEST );

		return self::MODE_LIVE === $mode ? self::MODE_LIVE : self::MODE_TEST;
	}

	/**
	 * Confirm a Shippo token's prefix matches the configured mode. Test
	 * and live must never cross — a misconfiguration here is blocked with
	 * a clear error rather than silently doing the wrong thing.
	 *
	 * @param string $token Shippo API token.
	 * @return true|\WP_Error
	 */
	public function assert_token_matches_mode( $token ) {
		$is_test_token = 0 === strpos( (string) $token, 'shippo_test_' );
		$is_live_token = 0 === strpos( (string) $token, 'shippo_live_' );

		if ( ! $is_test_token && ! $is_live_token ) {
			return new \WP_Error(
				'blt_sce_unrecognized_token',
				__( 'The configured Shippo token does not match a recognized shippo_test_ or shippo_live_ prefix.', 'blt-surecart-extensions' )
			);
		}

		$mode = $this->configured_mode();

		if ( self::MODE_TEST === $mode && ! $is_test_token ) {
			return new \WP_Error(
				'blt_sce_mode_mismatch',
				__( 'BLT SureCart Extensions is set to Test mode but a live Shippo token is configured. Refusing to purchase.', 'blt-surecart-extensions' )
			);
		}

		if ( self::MODE_LIVE === $mode && ! $is_live_token ) {
			return new \WP_Error(
				'blt_sce_mode_mismatch',
				__( 'BLT SureCart Extensions is set to Live mode but a test Shippo token is configured. Refusing to purchase.', 'blt-surecart-extensions' )
			);
		}

		return true;
	}

	/**
	 * Evaluate the destination address: country allowlist, military
	 * addresses, and the result of Shippo address validation.
	 *
	 * @param string    $country          ISO 3166-1 alpha-2 country code.
	 * @param string    $state            State/province/military code.
	 * @param bool|null $address_is_valid Result of Shippo validation; null if validation could not be performed.
	 * @return array{allowed: bool, reasons: string[]}
	 */
	public function evaluate_destination( $country, $state, $address_is_valid ) {
		$reasons = array();
		$country = strtoupper( (string) $country );
		$state   = strtoupper( (string) $state );

		$allowed_countries = get_option( self::OPT_ALLOWED_COUNTRIES, array( 'US' ) );

		if ( ! empty( $allowed_countries ) && ! in_array( $country, array_map( 'strtoupper', (array) $allowed_countries ), true ) ) {
			$reasons[] = sprintf(
				/* translators: %s: destination country code */
				__( 'Destination country %s is not on the allowed list.', 'blt-surecart-extensions' ),
				$country
			);
		}

		$is_military = in_array( $state, self::MILITARY_STATE_CODES, true );

		if ( $is_military && ! get_option( self::OPT_ALLOW_MILITARY, false ) ) {
			$reasons[] = __( 'Destination is an APO/FPO/DPO military address, which is not enabled for auto-purchase.', 'blt-surecart-extensions' );
		}

		if ( false === $address_is_valid ) {
			$reasons[] = __( 'Shippo could not validate the destination address.', 'blt-surecart-extensions' );
		} elseif ( null === $address_is_valid ) {
			$reasons[] = __( 'Destination address validation did not run or returned no result.', 'blt-surecart-extensions' );
		}

		return array(
			'allowed' => empty( $reasons ),
			'reasons' => $reasons,
		);
	}

	/**
	 * Evaluate the quoted rate against the absolute and percent-of-order
	 * ceilings. A $180 label on a $60 order means something is wrong.
	 *
	 * @param int $rate_cents        Quoted rate, in integer cents.
	 * @param int $order_total_cents Order total, in integer cents.
	 * @return array{allowed: bool, reasons: string[]}
	 */
	public function evaluate_rate( $rate_cents, $order_total_cents ) {
		$reasons         = array();
		$ceiling_cents   = (int) get_option( self::OPT_RATE_CEILING_CENTS, 0 );
		$ceiling_percent = (float) get_option( self::OPT_RATE_CEILING_PERCENT, 0 );

		if ( $ceiling_cents > 0 && $rate_cents > $ceiling_cents ) {
			$reasons[] = sprintf(
				/* translators: 1: rate in cents, 2: ceiling in cents */
				__( 'Rate of %1$d cents exceeds the configured absolute ceiling of %2$d cents.', 'blt-surecart-extensions' ),
				$rate_cents,
				$ceiling_cents
			);
		}

		if ( $ceiling_percent > 0 && $order_total_cents > 0 ) {
			$max_allowed = (int) round( $order_total_cents * ( $ceiling_percent / 100 ) );

			if ( $rate_cents > $max_allowed ) {
				$reasons[] = sprintf(
					/* translators: 1: rate in cents, 2: percent ceiling, 3: order total in cents */
					__( 'Rate of %1$d cents exceeds %2$s%% of the %3$d cent order total.', 'blt-surecart-extensions' ),
					$rate_cents,
					$ceiling_percent,
					$order_total_cents
				);
			}
		}

		return array(
			'allowed' => empty( $reasons ),
			'reasons' => $reasons,
		);
	}
}
