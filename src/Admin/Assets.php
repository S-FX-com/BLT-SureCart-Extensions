<?php
/**
 * Admin asset loading shared by every screen this plugin registers.
 *
 * One place, because the screens belong to four independently toggleable
 * modules plus the umbrella menu, and none of them should have to know how
 * the house stylesheet is named or versioned.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Assets
 */
final class Assets {

	const HANDLE = 'blt-surecart-extensions-design-system';

	/**
	 * Every admin page slug this plugin registers, module screens included.
	 *
	 * These are `admin.php?page=…` query-string routes, not files, so the
	 * request's `page` var is what identifies one of our screens. A module
	 * that is disabled simply never registers its slug, and the slug then
	 * never resolves — matching a stale one here loads a stylesheet on a
	 * screen that does not exist, which costs nothing.
	 *
	 * @var string[]
	 */
	private static $pages = array(
		'blt-sce-modules',
		'blt-sce-settings',
		'blt-sce-shipments',
		'blt-sce-review-queue',
		'blt-sce-offers',
		'blt-sce-offer-settings',
		'blt-sce-price-restrictions',
		'blt-sce-reports',
	);

	/**
	 * Register WP hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the BLT design system on this plugin's own screens only —
	 * never unconditionally, and never on the front end (this hook is
	 * admin-only, and the front end keeps its own prefixed CSS).
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! self::is_own_screen() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			BLT_SCE_URL . 'assets/css/blt-design-system.css',
			array(),
			BLT_SCE_VERSION
		);
	}

	/**
	 * Whether the current admin request is one of this plugin's screens.
	 *
	 * @return bool
	 */
	public static function is_own_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen identification; no state changes here.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return '' !== $page && in_array( $page, self::$pages, true );
	}
}
