<?php
/**
 * Cached SureCart product lookups for offer validation. The submit
 * endpoint must validate the offer against the real list price
 * server-side (client-supplied prices are untrustworthy), which needs
 * one SureCart model read. That read is transient-cached so at most one
 * SureCart call per product per 15 minutes escapes into a customer
 * request — the deliberate, bounded exception to the async-only rule,
 * documented in CLAUDE.md.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductCatalog
 */
final class ProductCatalog {

	const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Product name + reference list price (the lowest non-recurring
	 * price, falling back to the lowest price of any kind), cached.
	 *
	 * @param string $product_id SureCart product ID.
	 * @return array{name: string, list_price: int, currency: string}|null Null when the product can't be resolved.
	 */
	public function summary( $product_id ) {
		$product_id = sanitize_text_field( $product_id );

		if ( '' === $product_id ) {
			return null;
		}

		$cache_key = 'blt_sce_offer_product_' . md5( $product_id );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! class_exists( '\SureCart\Models\Product' ) ) {
			return null;
		}

		try {
			$product = \SureCart\Models\Product::with( array( 'prices' ) )->find( $product_id );
		} catch ( \Exception $e ) {
			return null;
		}

		if ( is_wp_error( $product ) || empty( $product->id ) ) {
			return null;
		}

		$best_one_time = null;
		$best_any      = null;
		$currency      = 'usd';

		if ( ! empty( $product->prices->data ) ) {
			foreach ( $product->prices->data as $price ) {
				if ( ! empty( $price->archived ) || ! isset( $price->amount ) ) {
					continue;
				}

				$amount   = (int) $price->amount;
				$currency = ! empty( $price->currency ) ? strtolower( $price->currency ) : $currency;

				if ( empty( $price->recurring_interval ) && ( null === $best_one_time || $amount < $best_one_time ) ) {
					$best_one_time = $amount;
				}

				if ( null === $best_any || $amount < $best_any ) {
					$best_any = $amount;
				}
			}
		}

		$list_price = null !== $best_one_time ? $best_one_time : $best_any;

		if ( null === $list_price ) {
			return null;
		}

		$summary = array(
			'name'       => isset( $product->name ) ? (string) $product->name : $product_id,
			'list_price' => $list_price,
			'currency'   => $currency,
		);

		set_transient( $cache_key, $summary, self::CACHE_TTL );

		return $summary;
	}
}
