<?php
/**
 * Make an Offer settings: a single serialized option (key carried over
 * from the standalone sc-make-an-offer scaffold so a future migration is
 * a no-op), plus wp-config constant overrides for the Stripe secret so
 * it can stay out of the database entirely — same pattern as the Shippo
 * token.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 */
final class Settings {

	const OPTION_KEY = 'sc_make_an_offer_settings';

	/**
	 * Cached settings for this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Defaults for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'expiry_days'            => 3,
			'min_pct'                => 0,
			'notify_email'           => '',
			'auto_accept_pct'        => 0,
			'allow_counter'          => true,
			'resubmit_policy'        => 'reject', // 'reject' or 'supersede'.
			'stripe_secret_key'      => '',
			'stripe_publishable_key' => '',
			'stripe_account_id'      => '',
		);
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			self::$cache = wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
		}

		return self::$cache;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Persist settings (merged over existing so partial saves are safe).
	 *
	 * @param array $values Values to merge and save.
	 * @return void
	 */
	public static function save( array $values ) {
		$merged = array_merge( self::all(), $values );
		update_option( self::OPTION_KEY, $merged );
		self::$cache = $merged;
	}

	/**
	 * Whether the Stripe secret key comes from a wp-config constant.
	 *
	 * @return bool
	 */
	public static function secret_key_is_constant_defined() {
		return defined( 'BLT_SCE_STRIPE_SECRET_KEY' ) && '' !== BLT_SCE_STRIPE_SECRET_KEY;
	}

	/**
	 * The active Stripe secret key — constant first, then the option.
	 * Never exposed to the browser; only ever read server-side.
	 *
	 * @return string
	 */
	public static function stripe_secret_key() {
		if ( self::secret_key_is_constant_defined() ) {
			return BLT_SCE_STRIPE_SECRET_KEY;
		}

		return (string) self::get( 'stripe_secret_key' );
	}

	/**
	 * The Stripe publishable key (safe for the browser).
	 *
	 * @return string
	 */
	public static function stripe_publishable_key() {
		return (string) self::get( 'stripe_publishable_key' );
	}

	/**
	 * Optional Stripe connected-account ID (acct_…).
	 *
	 * @return string
	 */
	public static function stripe_account_id() {
		return (string) self::get( 'stripe_account_id' );
	}

	/**
	 * The email address merchant notifications go to.
	 *
	 * @return string
	 */
	public static function notify_email() {
		$email = sanitize_email( (string) self::get( 'notify_email' ) );

		return $email ? $email : get_bloginfo( 'admin_email' );
	}

	/**
	 * Days before a pending offer auto-expires (minimum 1).
	 *
	 * @return int
	 */
	public static function expiry_days() {
		return max( 1, (int) self::get( 'expiry_days' ) );
	}
}
