<?php
/**
 * Registers modules, tracks enabled/disabled state, resolves dependencies,
 * and boots only what's enabled and satisfied.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules;

use BLT\SCE\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ModuleRegistry
 */
final class ModuleRegistry {

	const OPTION_ENABLED_MODULES = 'blt_sce_enabled_modules';

	/**
	 * Registered modules, keyed by slug.
	 *
	 * @var ModuleInterface[]
	 */
	private $modules = array();

	/**
	 * Slugs of modules that successfully booted this request.
	 *
	 * @var string[]
	 */
	private $booted = array();

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Shared logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Register a module. Registering does not boot it.
	 *
	 * @param ModuleInterface $module Module instance.
	 * @return void
	 */
	public function register( ModuleInterface $module ) {
		$this->modules[ $module->slug() ] = $module;
	}

	/**
	 * All registered modules.
	 *
	 * @return ModuleInterface[]
	 */
	public function all() {
		return $this->modules;
	}

	/**
	 * Get a single registered module by slug.
	 *
	 * @param string $slug Module slug.
	 * @return ModuleInterface|null
	 */
	public function get( $slug ) {
		return isset( $this->modules[ $slug ] ) ? $this->modules[ $slug ] : null;
	}

	/**
	 * Whether a module is enabled (stored preference). Defaults to enabled
	 * for a module the site owner has never explicitly toggled, so a
	 * freshly installed module registered by an update is available
	 * without a manual step — module-level guardrails (e.g. auto_purchase)
	 * remain the actual safety gate for anything that spends money.
	 *
	 * @param string $slug Module slug.
	 * @return bool
	 */
	public function is_enabled( $slug ) {
		$enabled = get_option( self::OPTION_ENABLED_MODULES, array() );

		return ! isset( $enabled[ $slug ] ) || ! empty( $enabled[ $slug ] );
	}

	/**
	 * Enable or disable a module by slug. Does not affect the current
	 * request's already-booted hooks — takes effect next load.
	 *
	 * @param string $slug    Module slug.
	 * @param bool   $enabled Whether the module should be enabled.
	 * @return void
	 */
	public function set_enabled( $slug, $enabled ) {
		$state          = get_option( self::OPTION_ENABLED_MODULES, array() );
		$state[ $slug ] = (bool) $enabled;
		update_option( self::OPTION_ENABLED_MODULES, $state );
	}

	/**
	 * Boot every registered module that is enabled and whose module
	 * dependencies and environment requirements are satisfied. Order is
	 * dependency-resolved so a module can depend on another module having
	 * booted first.
	 *
	 * @return void
	 */
	public function boot_enabled() {
		$pending = $this->modules;
		$safety  = count( $pending ) + 1;

		while ( $pending && $safety-- > 0 ) {
			$made_progress = false;

			foreach ( $pending as $slug => $module ) {
				if ( ! $this->can_boot_now( $module, $pending ) ) {
					continue;
				}

				unset( $pending[ $slug ] );
				$made_progress = true;

				if ( ! $this->is_enabled( $slug ) ) {
					continue;
				}

				$unmet = $module->unmet_requirements();

				if ( ! empty( $unmet ) ) {
					$this->logger->warning(
						sprintf( 'Module "%s" not booted: unmet requirements.', $slug ),
						array( 'unmet' => $unmet )
					);

					// An enabled module with unmet requirements may still
					// need its admin config screens registered — otherwise a
					// module whose only unmet requirement is a missing API
					// key can never be given one. Modules opt in by
					// implementing boot_admin() (optional, not part of the
					// interface, so existing modules are unaffected).
					if ( is_admin() && method_exists( $module, 'boot_admin' ) ) {
						$module->boot_admin();
					}

					continue;
				}

				$module->boot();
				$this->booted[] = $slug;
			}

			if ( ! $made_progress ) {
				// Remaining modules have dependencies that will never
				// resolve (missing or circular) — log and stop.
				foreach ( $pending as $slug => $module ) {
					$this->logger->warning(
						sprintf( 'Module "%s" not booted: unresolved dependency.', $slug ),
						array( 'dependencies' => $module->dependencies() )
					);
				}
				break;
			}
		}
	}

	/**
	 * Whether a module's declared dependencies have already resolved
	 * (booted, or determined unbootable) among the modules still pending.
	 *
	 * @param ModuleInterface   $module  Module to check.
	 * @param ModuleInterface[] $pending Modules not yet resolved.
	 * @return bool
	 */
	private function can_boot_now( ModuleInterface $module, array $pending ) {
		foreach ( $module->dependencies() as $dependency_slug ) {
			if ( isset( $pending[ $dependency_slug ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Slugs of modules booted this request.
	 *
	 * @return string[]
	 */
	public function booted() {
		return $this->booted;
	}
}
