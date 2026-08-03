<?php
/**
 * Central plugin bootstrap: dependency checks, module registry wiring,
 * activation/deactivation lifecycle.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE;

use BLT\SCE\Db\Schema;
use BLT\SCE\Modules\MakeAnOffer\Module as MakeAnOfferModule;
use BLT\SCE\Modules\ModuleRegistry;
use BLT\SCE\Modules\Reports\Module as ReportsModule;
use BLT\SCE\Modules\RestrictPriceByRole\Module as RestrictPriceByRoleModule;
use BLT\SCE\Modules\ShippoFulfillment\Module as ShippoFulfillmentModule;
use BLT\SCE\Support\Logger;
use BLT\SCE\Support\Scheduler;
use BLT\SCE\Support\UpdateChecker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Module registry.
	 *
	 * @var ModuleRegistry
	 */
	private $modules;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {
		$this->logger  = new Logger();
		$this->modules = new ModuleRegistry( $this->logger );
	}

	/**
	 * Boot the plugin: register modules, load admin, wire up services.
	 *
	 * Runs on `plugins_loaded`, after dependencies_met() has already been
	 * confirmed by the bootstrap file.
	 *
	 * @return void
	 */
	public function init() {
		load_plugin_textdomain( 'blt-surecart-extensions', false, dirname( BLT_SCE_BASENAME ) . '/languages' );

		// Activation hooks don't re-run on an in-place plugin update, which
		// is how this plugin ships — so a table introduced by a later
		// version has to be created here instead.
		Schema::maybe_upgrade();

		Scheduler::init();

		// Register modules. Each module is independently toggleable —
		// registering here does not mean it is active; ModuleRegistry
		// checks the stored enabled/disabled state before booting it.
		$this->modules->register( new ShippoFulfillmentModule( $this->logger ) );
		$this->modules->register( new RestrictPriceByRoleModule( $this->logger ) );
		$this->modules->register( new MakeAnOfferModule( $this->logger ) );
		$this->modules->register( new ReportsModule( $this->logger ) );
		$this->modules->boot_enabled();

		if ( is_admin() ) {
			( new Admin\ModulesPage( $this->modules ) )->hooks();
			UpdateChecker::init();
		}
	}

	/**
	 * Get the module registry.
	 *
	 * @return ModuleRegistry
	 */
	public function modules() {
		return $this->modules;
	}

	/**
	 * Get the shared logger.
	 *
	 * @return Logger
	 */
	public function logger() {
		return $this->logger;
	}

	/**
	 * Check whether SureCart is active. This is a best-effort, defensive
	 * check — the authoritative gate is the `Requires Plugins: surecart`
	 * header in the plugin file, which WordPress 6.5+ enforces natively
	 * (greys out activation, shows its own dependency notice). This check
	 * exists so we can also show our own notice and refuse to boot on
	 * older WordPress versions that don't understand that header.
	 *
	 * @return bool
	 */
	public static function dependencies_met() {
		if ( class_exists( '\SureCart\SureCart' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'is_plugin_active' ) ) {
			foreach ( array( 'surecart/surecart.php' ) as $plugin_file ) {
				if ( is_plugin_active( $plugin_file ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Admin notice shown when SureCart is missing or inactive.
	 *
	 * @return void
	 */
	public static function render_missing_dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'BLT SureCart Extensions requires the SureCart plugin to be installed and active.', 'blt-surecart-extensions' )
		);
	}

	/**
	 * Activation callback: create/upgrade local tables.
	 *
	 * Does not require SureCart to be active at activation time — WP runs
	 * activation hooks before `plugins_loaded`, so SureCart's own classes
	 * are not guaranteed loaded yet. Only touches this plugin's own schema.
	 *
	 * @return void
	 */
	public static function activate() {
		Schema::install();
	}

	/**
	 * Deactivation callback: unschedule recurring jobs, keep all data.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Scheduler::unschedule_all();
	}
}
