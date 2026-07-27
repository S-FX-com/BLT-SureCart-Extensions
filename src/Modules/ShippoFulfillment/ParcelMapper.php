<?php
/**
 * Maps order line item SKUs to a configured parcel definition.
 *
 * Multi-package orders are out of scope for v1 — one parcel per order.
 * If line items resolve to more than one distinct parcel, the caller
 * must route to the review queue rather than guessing which one to use.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\ShippoFulfillment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ParcelMapper
 */
final class ParcelMapper {

	const OPT_PARCELS        = 'blt_sce_shippo_parcels';
	const OPT_SKU_MAP        = 'blt_sce_shippo_sku_parcel_map';
	const OPT_DEFAULT_PARCEL = 'blt_sce_shippo_default_parcel_id';

	const REASON_NONE               = '';
	const REASON_NO_PARCEL_RESOLVED = 'no_parcel_resolved';
	const REASON_MULTIPLE_PARCELS   = 'multiple_parcels';
	const REASON_PARCEL_NOT_FOUND   = 'parcel_not_found';

	/**
	 * All configured parcel definitions, keyed by parcel id.
	 *
	 * @return array<string, array>
	 */
	public function get_parcels() {
		$parcels = get_option( self::OPT_PARCELS, array() );

		return is_array( $parcels ) ? $parcels : array();
	}

	/**
	 * A single parcel definition by id.
	 *
	 * @param string $parcel_id Parcel id.
	 * @return array|null
	 */
	public function get_parcel( $parcel_id ) {
		$parcels = $this->get_parcels();

		return isset( $parcels[ $parcel_id ] ) ? $parcels[ $parcel_id ] : null;
	}

	/**
	 * SKU => parcel id map.
	 *
	 * @return array<string, string>
	 */
	public function get_sku_map() {
		$map = get_option( self::OPT_SKU_MAP, array() );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * The fallback parcel id used for unmapped SKUs, if configured.
	 *
	 * @return string
	 */
	public function get_default_parcel_id() {
		return (string) get_option( self::OPT_DEFAULT_PARCEL, '' );
	}

	/**
	 * Resolve the single parcel to use for an order's line item SKUs.
	 *
	 * @param string[] $skus SKUs present on the order's line items.
	 * @return array{parcel: array|null, multi_parcel: bool, reason: string}
	 */
	public function resolve( array $skus ) {
		$sku_map      = $this->get_sku_map();
		$resolved_ids = array();
		$has_unmapped = false;

		foreach ( $skus as $sku ) {
			if ( isset( $sku_map[ $sku ] ) && '' !== $sku_map[ $sku ] ) {
				$resolved_ids[ $sku_map[ $sku ] ] = true;
			} else {
				$has_unmapped = true;
			}
		}

		if ( $has_unmapped ) {
			$default = $this->get_default_parcel_id();

			if ( '' !== $default ) {
				$resolved_ids[ $default ] = true;
			}
		}

		$distinct_ids = array_keys( $resolved_ids );

		if ( empty( $distinct_ids ) ) {
			return array(
				'parcel'       => null,
				'multi_parcel' => false,
				'reason'       => self::REASON_NO_PARCEL_RESOLVED,
			);
		}

		if ( count( $distinct_ids ) > 1 ) {
			return array(
				'parcel'       => null,
				'multi_parcel' => true,
				'reason'       => self::REASON_MULTIPLE_PARCELS,
			);
		}

		$parcel = $this->get_parcel( $distinct_ids[0] );

		if ( null === $parcel ) {
			return array(
				'parcel'       => null,
				'multi_parcel' => false,
				'reason'       => self::REASON_PARCEL_NOT_FOUND,
			);
		}

		return array(
			'parcel'       => $parcel,
			'multi_parcel' => false,
			'reason'       => self::REASON_NONE,
		);
	}
}
