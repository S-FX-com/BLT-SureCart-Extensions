<?php
/**
 * Contract every BLT SCE module must implement.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ModuleInterface {

	/**
	 * Unique, stable module slug (used as the option key suffix for
	 * enabled/disabled state and as the settings tab slug).
	 *
	 * @return string
	 */
	public function slug();

	/**
	 * Human-readable module name, for the Modules admin screen.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Short human-readable description, for the Modules admin screen.
	 *
	 * @return string
	 */
	public function description();

	/**
	 * Other module slugs this module requires to be enabled.
	 *
	 * @return string[]
	 */
	public function dependencies();

	/**
	 * Check environment/config prerequisites beyond module dependencies
	 * (e.g. an API token being configured). Returning a non-empty array
	 * blocks the module from booting and is surfaced on the Modules page.
	 *
	 * @return string[] Array of human-readable unmet-requirement messages. Empty when satisfied.
	 */
	public function unmet_requirements();

	/**
	 * Register all hooks for this module. Called only when the module is
	 * enabled and its dependencies/requirements are satisfied. Must be
	 * fully undoable by simply not calling this — a disabled module must
	 * leave no hooks registered.
	 *
	 * @return void
	 */
	public function boot();
}
