<?php
/**
 * The Reports module: aggregate reports over SureCart sales data.
 *
 * Ships one report, the Fulfillment Report — every order in a date range
 * (optionally narrowed to selected products) collapsed into one row per
 * customer with a column per product variant holding the quantity ordered,
 * plus a TOTALS row. That's the shape needed to place a manufacturing order
 * and to drive a bulk-fulfillment run, neither of which SureCart's
 * order-by-order admin gives you.
 *
 * Read-only: this module never writes to SureCart, never spends money, and
 * has no bearing on checkout. It only reads orders and writes a CSV.
 *
 * This is the only file that touches WordPress hooks for this module —
 * everything else is plain, hook-free service classes.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules\Reports;

use BLT\SCE\Api\SureCartGateway;
use BLT\SCE\Db\ReportRepository;
use BLT\SCE\Modules\ModuleInterface;
use BLT\SCE\Support\Logger;
use BLT\SCE\Support\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Module
 */
final class Module implements ModuleInterface {

	const SLUG = 'reports';

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Report index repository.
	 *
	 * @var ReportRepository
	 */
	private $repository;

	/**
	 * File storage.
	 *
	 * @var ReportStorage
	 */
	private $storage;

	/**
	 * Product picker cache.
	 *
	 * @var ProductIndex
	 */
	private $products;

	/**
	 * Job runner.
	 *
	 * @var ReportRunner
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Shared logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger     = $logger;
		$this->repository = new ReportRepository();
		$this->storage    = new ReportStorage();
		$this->products   = new ProductIndex();
		$this->runner     = new ReportRunner(
			new SureCartGateway(),
			$this->repository,
			$this->storage,
			$this->products,
			$logger
		);
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
		return __( 'Reports', 'blt-surecart-extensions' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Generates a Fulfillment Report CSV: sales over a date range and product selection, itemized as one row per customer with a quantity column for each product variant (e.g. each shirt size).', 'blt-surecart-extensions' );
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
			$unmet[] = __( 'Action Scheduler is not available — reports are built in the background and cannot run without it.', 'blt-surecart-extensions' );
		}

		if ( ! class_exists( '\SureCart\Models\Order' ) ) {
			$unmet[] = __( 'SureCart model classes are not available — orders cannot be read.', 'blt-surecart-extensions' );
		}

		// Deliberately no reports-table check here: unmet_requirements() runs
		// on every request via ModuleRegistry::boot_enabled(), and a SHOW
		// TABLES per page load is not worth a diagnostic that
		// Schema::maybe_upgrade() has already made near-impossible. A missing
		// table surfaces as an error when a report is actually requested.
		return $unmet;
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot() {
		add_action( ReportRunner::HOOK_RUN, array( $this->runner, 'run' ) );
		add_action( ReportRunner::HOOK_REFRESH_PRODUCTS, array( $this->runner, 'refresh_products' ) );

		if ( is_admin() ) {
			( new AdminPage( $this->repository, $this->storage, $this->products ) )->hooks();
		}
	}
}
