<?php
/**
 * The sc_offer custom post type and its custom statuses. Post type and
 * status names, meta keys, and status semantics are carried over from
 * the standalone sc-make-an-offer scaffold so existing documentation
 * (and any future data migration) lines up 1:1.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OfferPostType
 */
final class OfferPostType {

	const POST_TYPE = 'sc_offer';

	const STATUS_PENDING   = 'offer_pending';
	const STATUS_ACCEPTED  = 'offer_accepted';
	const STATUS_DECLINED  = 'offer_declined';
	const STATUS_EXPIRED   = 'offer_expired';
	const STATUS_COUNTERED = 'offer_countered';
	const STATUS_CANCELLED = 'offer_cancelled';

	/**
	 * Register the post type and statuses. Runs on `init`.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'               => __( 'Offers', 'blt-surecart-extensions' ),
				'public'              => false,
				'show_ui'             => false, // The module ships its own Offers screen.
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title' ),
				'map_meta_cap'        => true,
				'capability_type'     => 'post',
			)
		);

		foreach ( self::statuses() as $status => $label ) {
			register_post_status(
				$status,
				array(
					'label'                     => $label,
					'public'                    => false,
					'internal'                  => true,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => false,
					'show_in_admin_status_list' => false,
				)
			);
		}
	}

	/**
	 * Every offer status with its human-readable label.
	 *
	 * @return array<string, string>
	 */
	public static function statuses() {
		return array(
			self::STATUS_PENDING   => __( 'Pending', 'blt-surecart-extensions' ),
			self::STATUS_ACCEPTED  => __( 'Accepted', 'blt-surecart-extensions' ),
			self::STATUS_DECLINED  => __( 'Declined', 'blt-surecart-extensions' ),
			self::STATUS_EXPIRED   => __( 'Expired', 'blt-surecart-extensions' ),
			self::STATUS_COUNTERED => __( 'Countered', 'blt-surecart-extensions' ),
			self::STATUS_CANCELLED => __( 'Cancelled', 'blt-surecart-extensions' ),
		);
	}

	/**
	 * Label for one status, falling back to the raw status string.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_label( $status ) {
		$statuses = self::statuses();

		return isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
	}
}
