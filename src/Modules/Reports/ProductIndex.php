<?php
/**
 * Cached SureCart product list backing the report screen's product picker.
 *
 * The picker needs product names and IDs, which means a SureCart call — and
 * rule 1 forbids that during an admin page render. So the list is served
 * from an option-backed cache that an Action Scheduler job populates: the
 * page renders from whatever is cached, and asks the job to refresh when
 * the cache is cold or stale. Nothing here ever makes an HTTP call.
 *
 * An option rather than a transient, deliberately: a transient can vanish
 * at any time on a site with an external object cache, which would leave
 * the picker empty at random. This list is cheap to store and its staleness
 * is displayed, so persistence is the better trade.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductIndex
 */
final class ProductIndex {

	const OPTION_PRODUCTS  = 'blt_sce_reports_products';
	const OPTION_REFRESHED = 'blt_sce_reports_products_refreshed';

	/**
	 * How old the cache can get before the screen offers to refresh it.
	 */
	const STALE_AFTER = 12 * HOUR_IN_SECONDS;

	/**
	 * Cached products as a list of id/name pairs, in the order SureCart
	 * returned them (sorted by name).
	 *
	 * @return array[] Each: {id: string, name: string}.
	 */
	public function all() {
		$products = get_option( self::OPTION_PRODUCTS, array() );

		return is_array( $products ) ? $products : array();
	}

	/**
	 * Replace the cached list.
	 *
	 * @param array[] $products Each: {id: string, name: string}.
	 * @return void
	 */
	public function store( array $products ) {
		update_option( self::OPTION_PRODUCTS, $products, false );
		update_option( self::OPTION_REFRESHED, time(), false );
	}

	/**
	 * Whether a refresh has never run.
	 *
	 * Keyed on the refresh timestamp rather than on the list being empty, so
	 * a store that genuinely has no products doesn't look permanently cold
	 * and re-queue a refresh job forever.
	 *
	 * @return bool
	 */
	public function is_cold() {
		return 0 === $this->refreshed_at();
	}

	/**
	 * Whether the cache is old enough to be worth refreshing.
	 *
	 * @return bool
	 */
	public function is_stale() {
		return ( time() - $this->refreshed_at() ) > self::STALE_AFTER;
	}

	/**
	 * When the cache was last written, as a unix timestamp (0 if never).
	 *
	 * @return int
	 */
	public function refreshed_at() {
		return (int) get_option( self::OPTION_REFRESHED, 0 );
	}

	/**
	 * Resolve a product ID to its cached name, for labelling a report whose
	 * parameters name specific products.
	 *
	 * @param string $product_id SureCart product ID.
	 * @return string Cached name, or the ID when it isn't in the cache.
	 */
	public function name( $product_id ) {
		foreach ( $this->all() as $product ) {
			if ( isset( $product['id'] ) && $product['id'] === $product_id ) {
				return isset( $product['name'] ) ? $product['name'] : $product_id;
			}
		}

		return $product_id;
	}

	/**
	 * Forget everything, so the next refresh starts clean.
	 *
	 * @return void
	 */
	public function clear() {
		delete_option( self::OPTION_PRODUCTS );
		delete_option( self::OPTION_REFRESHED );
	}
}
