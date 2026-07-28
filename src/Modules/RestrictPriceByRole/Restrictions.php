<?php
/**
 * The restriction map: which WordPress roles may access which SureCart
 * price. Pure option access + evaluation — no hooks, no HTTP.
 *
 * The option key is intentionally the one the standalone
 * "SureCart - Restrict Price by User Role" plugin used, so a site
 * migrating from the standalone plugin to this module keeps its
 * configured restrictions with no migration step.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\RestrictPriceByRole;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Restrictions
 */
final class Restrictions {

	const OPTION_KEY = 'scrpbr_restrictions';

	/**
	 * Restricted price IDs for the current user, computed once per request.
	 *
	 * @var string[]|null
	 */
	private $restricted_ids_cache = null;

	/**
	 * The saved restriction map.
	 *
	 * @return array<string, string[]> price_id => allowed role slugs.
	 */
	public function get_map() {
		$map = get_option( self::OPTION_KEY, array() );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Persist the restriction map.
	 *
	 * @param array<string, string[]> $map price_id => allowed role slugs.
	 * @return void
	 */
	public function save_map( array $map ) {
		update_option( self::OPTION_KEY, $map );
	}

	/**
	 * Whether a given price is restricted for the current user.
	 *
	 * @param string $price_id SureCart price ID (e.g. "pri_abc123").
	 * @return bool True when the current user may NOT access the price.
	 */
	public function is_restricted_for_current_user( $price_id ) {
		$map = $this->get_map();

		// Price has no restriction rule — available to everyone.
		if ( empty( $map[ $price_id ] ) ) {
			return false;
		}

		// Guest users cannot satisfy any role requirement.
		if ( ! is_user_logged_in() ) {
			return true;
		}

		$user_roles = (array) wp_get_current_user()->roles;

		// Restricted if the user holds none of the allowed roles.
		return empty( array_intersect( $map[ $price_id ], $user_roles ) );
	}

	/**
	 * Every price ID restricted for the current user, cached per request —
	 * the frontend consults this from several hooks on the same page load.
	 *
	 * @return string[]
	 */
	public function restricted_price_ids() {
		if ( null !== $this->restricted_ids_cache ) {
			return $this->restricted_ids_cache;
		}

		$restricted = array();

		foreach ( array_keys( $this->get_map() ) as $price_id ) {
			if ( $this->is_restricted_for_current_user( $price_id ) ) {
				$restricted[] = $price_id;
			}
		}

		$this->restricted_ids_cache = $restricted;

		return $restricted;
	}
}
