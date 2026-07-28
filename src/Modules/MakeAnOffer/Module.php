<?php
/**
 * The Make an Offer module: eBay-style offers on SureCart products.
 * Customers submit a price offer with a vaulted card (Stripe SetupIntent
 * via Stripe.js — card data never touches this server); the merchant
 * accepts, declines, or counters from wp-admin; on acceptance the card
 * is charged off-session inside an Action Scheduler job.
 *
 * Consolidated from the standalone sc-make-an-offer scaffold
 * (Blt-SureCart-Offers repo). After a successful charge, the offer is
 * back-filled into SureCart as a real order via the manually-paid
 * checkout flow (OrderRecorder, endpoints verified in
 * tasks/00-discovery.md §G) — orders can't be created directly
 * (SureCart's Orders API is list/retrieve only), and native checkouts
 * expose no authorize-only/manual-capture switch, which is why the
 * charge itself stays on the module's own Stripe integration.
 *
 * This is the only file that touches WordPress hooks for this module —
 * everything else is plain, hook-free service classes.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\MakeAnOffer;

use BLT\SCE\Api\SureCartApiClient;
use BLT\SCE\Modules\ModuleInterface;
use BLT\SCE\Rest\OfferController;
use BLT\SCE\Support\Logger;
use BLT\SCE\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Module
 */
final class Module implements ModuleInterface {

	const SLUG                 = 'make-an-offer';
	const EXPIRE_SWEEP_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Repository.
	 *
	 * @var OfferRepository
	 */
	private $repository;

	/**
	 * Lifecycle orchestration.
	 *
	 * @var OfferManager
	 */
	private $manager;

	/**
	 * REST controller.
	 *
	 * @var OfferController
	 */
	private $rest_controller;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Shared logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger     = $logger;
		$this->repository = new OfferRepository();

		$stripe   = new StripeService( $this->repository, $logger );
		$emails   = new EmailNotifier();
		$recorder = new OrderRecorder(
			new SureCartApiClient( $logger, Settings::surecart_api_token() ),
			$this->repository,
			$logger
		);

		$this->manager         = new OfferManager( $this->repository, $stripe, $emails, $recorder, $logger );
		$this->rest_controller = new OfferController( $this->repository, $stripe, $this->manager, new ProductCatalog(), $emails );
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
		return __( 'Make an Offer', 'blt-surecart-extensions' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'eBay-style offers on SureCart products: customers offer a price with a vaulted card; you accept, decline, or counter from wp-admin, and acceptance charges the card automatically.', 'blt-surecart-extensions' );
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

		if ( ! Scheduler::is_available() ) {
			$unmet[] = __( 'Action Scheduler is not available — offer charging and expiration cannot run.', 'blt-surecart-extensions' );
		}

		if ( '' === Settings::stripe_secret_key() ) {
			$unmet[] = __( 'No Stripe secret key is configured yet (Offer Settings).', 'blt-surecart-extensions' );
		}

		if ( '' === Settings::stripe_publishable_key() ) {
			$unmet[] = __( 'No Stripe publishable key is configured yet (Offer Settings — try "Detect from SureCart").', 'blt-surecart-extensions' );
		}

		return $unmet;
	}

	/**
	 * Called by the registry when the module is enabled but its
	 * requirements (e.g. Stripe keys) are unmet: keep the admin screens
	 * reachable so the keys can actually be entered.
	 *
	 * @return void
	 */
	public function boot_admin() {
		add_action( 'init', array( OfferPostType::class, 'register' ) );
		( new AdminPage( $this->repository, $this->manager ) )->hooks();
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot() {
		add_action( 'init', array( OfferPostType::class, 'register' ) );

		// Action Scheduler job handlers + the hourly expiration sweep.
		add_action( OfferManager::HOOK_CAPTURE, array( $this->manager, 'process_capture' ), 10, 2 );
		add_action( OfferManager::HOOK_RELEASE_PM, array( $this->manager, 'process_release_pm' ) );
		add_action( OfferManager::HOOK_EXPIRE_SWEEP, array( $this->manager, 'process_expire_sweep' ) );
		add_action( OfferManager::HOOK_RECORD_ORDER, array( $this->manager, 'process_record_order' ) );

		add_action(
			'init',
			static function () {
				Scheduler::ensure_recurring( OfferManager::HOOK_EXPIRE_SWEEP, self::EXPIRE_SWEEP_INTERVAL );
			}
		);

		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );

		if ( is_admin() ) {
			( new AdminPage( $this->repository, $this->manager ) )->hooks();
		} else {
			( new Frontend() )->hooks();
		}
	}
}
