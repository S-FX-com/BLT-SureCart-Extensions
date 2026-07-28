<?php
/**
 * The Restrict Price by User Role module: hides SureCart prices from
 * users who don't hold an allowed WordPress role, and rejects checkout
 * attempts for restricted prices server-side. Consolidated from the
 * standalone "SureCart - Restrict Price by User Role" plugin.
 *
 * This is the only file that touches WordPress hooks for this module —
 * everything else is plain, hook-free service classes.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\RestrictPriceByRole;

use BLT\SCE\Modules\ModuleInterface;
use BLT\SCE\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Module
 */
final class Module implements ModuleInterface {

	const SLUG = 'restrict-price-by-role';

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Restriction map service, shared by every component of this module.
	 *
	 * @var Restrictions
	 */
	private $restrictions;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Shared logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger       = $logger;
		$this->restrictions = new Restrictions();
	}

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Restrict Price by Role', 'blt-surecart-extensions' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Restricts SureCart product prices to specific WordPress user roles: hides restricted prices on the frontend and blocks them at checkout.', 'blt-surecart-extensions' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function dependencies() {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function unmet_requirements() {
		$unmet = array();

		if ( ! class_exists( '\SureCart\Models\Product' ) ) {
			$unmet[] = __( 'SureCart model classes are not available — the price restrictions screen cannot list products.', 'blt-surecart-extensions' );
		}

		return $unmet;
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot() {
		( new CheckoutValidator( $this->restrictions ) )->hooks();

		if ( is_admin() ) {
			( new AdminPage( $this->restrictions ) )->hooks();
		} else {
			( new Frontend( $this->restrictions ) )->hooks();
		}
	}
}
