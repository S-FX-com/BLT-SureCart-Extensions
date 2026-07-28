<?php
/**
 * All sc_offer reads/writes live here — post + meta bundled into one
 * plain object so callers never touch get_post_meta() key strings.
 * Meta keys are the standalone scaffold's `_sc_offer_*` names.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OfferRepository
 */
final class OfferRepository {

	const META_PRODUCT_ID     = '_sc_offer_product_id';
	const META_PRODUCT_NAME   = '_sc_offer_product_name';
	const META_VARIANT_ID     = '_sc_offer_variant_id';
	const META_AMOUNT         = '_sc_offer_amount';
	const META_LIST_PRICE     = '_sc_offer_list_price';
	const META_CURRENCY       = '_sc_offer_currency';
	const META_MESSAGE        = '_sc_offer_message';
	const META_CUSTOMER_EMAIL = '_sc_offer_customer_email';
	const META_CUSTOMER_NAME  = '_sc_offer_customer_name';
	const META_STRIPE_CUST_ID = '_sc_offer_stripe_customer_id';
	const META_STRIPE_PM_ID   = '_sc_offer_stripe_pm_id';
	const META_STRIPE_SI_ID   = '_sc_offer_stripe_si_id';
	const META_STRIPE_PI_ID   = '_sc_offer_stripe_pi_id';
	const META_SC_ORDER_ID    = '_sc_offer_sc_order_id';
	const META_EXPIRES_AT     = '_sc_offer_expires_at';
	const META_COUNTER_AMOUNT = '_sc_offer_counter_amount';
	const META_PM_CONFIRMED   = '_sc_offer_pm_confirmed';
	const META_CAPTURE_ERROR  = '_sc_offer_capture_error';

	/**
	 * Create a new pending offer.
	 *
	 * @param array $data Keys: product_id, product_name, variant_id,
	 *                    amount (cents), list_price (cents), currency,
	 *                    message, customer_email, customer_name.
	 * @return int|\WP_Error New offer post ID.
	 */
	public function create( array $data ) {
		$expires_at = time() + ( Settings::expiry_days() * DAY_IN_SECONDS );

		$post_id = wp_insert_post(
			array(
				'post_type'   => OfferPostType::POST_TYPE,
				'post_status' => OfferPostType::STATUS_PENDING,
				'post_title'  => sprintf(
					/* translators: 1: customer email, 2: product name */
					__( 'Offer from %1$s — %2$s', 'blt-surecart-extensions' ),
					$data['customer_email'],
					$data['product_name']
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$meta = array(
			self::META_PRODUCT_ID     => $data['product_id'],
			self::META_PRODUCT_NAME   => $data['product_name'],
			self::META_VARIANT_ID     => isset( $data['variant_id'] ) ? $data['variant_id'] : '',
			self::META_AMOUNT         => (int) $data['amount'],
			self::META_LIST_PRICE     => (int) $data['list_price'],
			self::META_CURRENCY       => $data['currency'],
			self::META_MESSAGE        => isset( $data['message'] ) ? $data['message'] : '',
			self::META_CUSTOMER_EMAIL => $data['customer_email'],
			self::META_CUSTOMER_NAME  => isset( $data['customer_name'] ) ? $data['customer_name'] : '',
			self::META_EXPIRES_AT     => $expires_at,
			self::META_PM_CONFIRMED   => 0,
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		return $post_id;
	}

	/**
	 * Load an offer (post + meta) as one plain object.
	 *
	 * @param int $offer_id Offer post ID.
	 * @return object|null
	 */
	public function find( $offer_id ) {
		$post = get_post( (int) $offer_id );

		if ( ! $post || OfferPostType::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return (object) array(
			'id'                 => (int) $post->ID,
			'status'             => $post->post_status,
			'created_at'         => $post->post_date_gmt,
			'product_id'         => (string) get_post_meta( $post->ID, self::META_PRODUCT_ID, true ),
			'product_name'       => (string) get_post_meta( $post->ID, self::META_PRODUCT_NAME, true ),
			'variant_id'         => (string) get_post_meta( $post->ID, self::META_VARIANT_ID, true ),
			'amount'             => (int) get_post_meta( $post->ID, self::META_AMOUNT, true ),
			'list_price'         => (int) get_post_meta( $post->ID, self::META_LIST_PRICE, true ),
			'currency'           => (string) get_post_meta( $post->ID, self::META_CURRENCY, true ),
			'message'            => (string) get_post_meta( $post->ID, self::META_MESSAGE, true ),
			'customer_email'     => (string) get_post_meta( $post->ID, self::META_CUSTOMER_EMAIL, true ),
			'customer_name'      => (string) get_post_meta( $post->ID, self::META_CUSTOMER_NAME, true ),
			'stripe_customer_id' => (string) get_post_meta( $post->ID, self::META_STRIPE_CUST_ID, true ),
			'stripe_pm_id'       => (string) get_post_meta( $post->ID, self::META_STRIPE_PM_ID, true ),
			'stripe_si_id'       => (string) get_post_meta( $post->ID, self::META_STRIPE_SI_ID, true ),
			'stripe_pi_id'       => (string) get_post_meta( $post->ID, self::META_STRIPE_PI_ID, true ),
			'sc_order_id'        => (string) get_post_meta( $post->ID, self::META_SC_ORDER_ID, true ),
			'expires_at'         => (int) get_post_meta( $post->ID, self::META_EXPIRES_AT, true ),
			'counter_amount'     => (int) get_post_meta( $post->ID, self::META_COUNTER_AMOUNT, true ),
			'pm_confirmed'       => (bool) get_post_meta( $post->ID, self::META_PM_CONFIRMED, true ),
			'capture_error'      => (string) get_post_meta( $post->ID, self::META_CAPTURE_ERROR, true ),
		);
	}

	/**
	 * Transition an offer to a new status.
	 *
	 * @param int    $offer_id Offer post ID.
	 * @param string $status   One of OfferPostType::STATUS_*.
	 * @return void
	 */
	public function set_status( $offer_id, $status ) {
		if ( ! array_key_exists( $status, OfferPostType::statuses() ) ) {
			return;
		}

		wp_update_post(
			array(
				'ID'          => (int) $offer_id,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Update a single meta field by its constant key.
	 *
	 * @param int    $offer_id Offer post ID.
	 * @param string $meta_key One of the META_* constants.
	 * @param mixed  $value    Value to store.
	 * @return void
	 */
	public function set_meta( $offer_id, $meta_key, $value ) {
		update_post_meta( (int) $offer_id, $meta_key, $value );
	}

	/**
	 * The customer's currently active (pending or countered) offer for a
	 * product, if any — enforces one active offer per email per product.
	 *
	 * @param string $email      Customer email.
	 * @param string $product_id SureCart product ID.
	 * @return int|null Offer post ID, or null when none.
	 */
	public function find_active_for( $email, $product_id ) {
		$ids = get_posts(
			array(
				'post_type'      => OfferPostType::POST_TYPE,
				'post_status'    => array( OfferPostType::STATUS_PENDING, OfferPostType::STATUS_COUNTERED ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => self::META_CUSTOMER_EMAIL,
						'value' => $email,
					),
					array(
						'key'   => self::META_PRODUCT_ID,
						'value' => $product_id,
					),
				),
			)
		);

		return $ids ? (int) $ids[0] : null;
	}

	/**
	 * Pending/countered offers whose expiry timestamp has passed — the
	 * hourly expiration sweep's work list.
	 *
	 * @param int $limit Max IDs to return per sweep.
	 * @return int[]
	 */
	public function expired_offer_ids( $limit = 50 ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => OfferPostType::POST_TYPE,
					'post_status'    => array( OfferPostType::STATUS_PENDING, OfferPostType::STATUS_COUNTERED ),
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => self::META_EXPIRES_AT,
							'value'   => time(),
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
					),
				)
			)
		);
	}

	/**
	 * Count of offers awaiting a merchant decision — backs the admin bar
	 * badge and the list table view counts.
	 *
	 * @return int
	 */
	public function pending_count() {
		$counts = wp_count_posts( OfferPostType::POST_TYPE );

		return isset( $counts->{OfferPostType::STATUS_PENDING} ) ? (int) $counts->{OfferPostType::STATUS_PENDING} : 0;
	}

	/**
	 * Paginated offers for the admin list table, newest first.
	 *
	 * @param string $status   Status filter, or '' for all offer statuses.
	 * @param int    $page     1-based page.
	 * @param int    $per_page Rows per page.
	 * @return array{items: object[], total: int}
	 */
	public function paginate( $status, $page, $per_page ) {
		$query = new \WP_Query(
			array(
				'post_type'      => OfferPostType::POST_TYPE,
				'post_status'    => $status ? $status : array_keys( OfferPostType::statuses() ),
				'posts_per_page' => $per_page,
				'paged'          => max( 1, (int) $page ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		$items = array();

		foreach ( $query->posts as $post_id ) {
			$offer = $this->find( $post_id );

			if ( $offer ) {
				$items[] = $offer;
			}
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}
}
