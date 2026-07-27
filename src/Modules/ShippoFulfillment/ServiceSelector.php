<?php
/**
 * Picks one rate from Shippo's rates[] array per the configured
 * strategy and allowed-service-token priority list.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\ShippoFulfillment;

use BLT\SCE\Support\Money;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ServiceSelector
 */
final class ServiceSelector {

	const OPT_RULES = 'blt_sce_shippo_service_rules';

	const STRATEGY_CHEAPEST = 'cheapest';
	const STRATEGY_FASTEST  = 'fastest';
	const STRATEGY_PRIORITY = 'priority';

	/**
	 * Available strategies, for the settings dropdown.
	 *
	 * @return array<string, string>
	 */
	public static function strategies() {
		return array(
			self::STRATEGY_CHEAPEST => __( 'Cheapest', 'blt-surecart-extensions' ),
			self::STRATEGY_FASTEST  => __( 'Fastest', 'blt-surecart-extensions' ),
			self::STRATEGY_PRIORITY => __( 'Priority order (first allowed token with a rate)', 'blt-surecart-extensions' ),
		);
	}

	/**
	 * Configured rules.
	 *
	 * @return array{strategy: string, allowed_tokens: string[]}
	 */
	public static function get_rules() {
		return wp_parse_args(
			get_option( self::OPT_RULES, array() ),
			array(
				'strategy'       => self::STRATEGY_CHEAPEST,
				'allowed_tokens' => array(),
			)
		);
	}

	/**
	 * Select one rate from a Shippo shipment's rates[] array.
	 *
	 * @param array $rates Array of Shippo Rate objects (as associative arrays).
	 * @return array|null The selected rate, or null if nothing qualifies.
	 */
	public function select( array $rates ) {
		$rules   = self::get_rules();
		$allowed = $rules['allowed_tokens'];

		$candidates = empty( $allowed )
			? $rates
			: array_values(
				array_filter(
					$rates,
					static function ( $rate ) use ( $allowed ) {
						$token = isset( $rate['servicelevel']['token'] ) ? $rate['servicelevel']['token'] : '';

						return in_array( $token, $allowed, true );
					}
				)
			);

		if ( empty( $candidates ) ) {
			return null;
		}

		if ( self::STRATEGY_PRIORITY === $rules['strategy'] && ! empty( $allowed ) ) {
			foreach ( $allowed as $token ) {
				foreach ( $candidates as $rate ) {
					if ( isset( $rate['servicelevel']['token'] ) && $rate['servicelevel']['token'] === $token ) {
						return $rate;
					}
				}
			}

			return null;
		}

		if ( self::STRATEGY_FASTEST === $rules['strategy'] ) {
			usort(
				$candidates,
				static function ( $a, $b ) {
					$a_days = isset( $a['estimated_days'] ) ? (int) $a['estimated_days'] : PHP_INT_MAX;
					$b_days = isset( $b['estimated_days'] ) ? (int) $b['estimated_days'] : PHP_INT_MAX;

					return $a_days <=> $b_days;
				}
			);

			return $candidates[0];
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				$a_cents = Money::decimal_string_to_cents( isset( $a['amount'] ) ? $a['amount'] : '0' );
				$b_cents = Money::decimal_string_to_cents( isset( $b['amount'] ) ? $b['amount'] : '0' );

				return $a_cents <=> $b_cents;
			}
		);

		return $candidates[0];
	}
}
