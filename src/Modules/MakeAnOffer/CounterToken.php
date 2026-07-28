<?php
/**
 * Signed tokens for the counter-offer email links. The customer isn't
 * logged in when they click "accept counter-offer" in an email, so the
 * link carries an HMAC over the offer ID + counter amount instead of a
 * nonce. The amount is inside the signature, so a token minted for one
 * counter amount can't authorize a charge at a different amount; offer
 * expiry provides the time bound.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CounterToken
 */
final class CounterToken {

	/**
	 * Mint the token for a counter-offer response link.
	 *
	 * @param int $offer_id     Offer post ID.
	 * @param int $amount_cents Counter amount in cents.
	 * @return string
	 */
	public static function make( $offer_id, $amount_cents ) {
		return hash_hmac( 'sha256', 'blt-sce-offer-counter|' . (int) $offer_id . '|' . (int) $amount_cents, wp_salt( 'auth' ) );
	}

	/**
	 * Constant-time verification of a presented token.
	 *
	 * @param string $token        Presented token.
	 * @param int    $offer_id     Offer post ID.
	 * @param int    $amount_cents Counter amount in cents.
	 * @return bool
	 */
	public static function verify( $token, $offer_id, $amount_cents ) {
		return is_string( $token ) && hash_equals( self::make( $offer_id, $amount_cents ), $token );
	}
}
