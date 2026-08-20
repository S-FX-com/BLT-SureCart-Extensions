<?php
/**
 * Plugin Name:       BLT SureCart Extensions
 * Plugin URI:        https://s-fx.com/plugins/blt-surecart-extensions
 * Description:       Umbrella extension plugin adding modular capabilities to SureCart. Modules (each independently toggleable): Shippo Fulfillment, Restrict Price by Role, Make an Offer, and Reports.
 * Version:           0.4.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  surecart
 * Author:            S-FX.com
 * Author URI:        https://www.s-fx.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blt-surecart-extensions
 * Domain Path:       /languages
 *
 * @package BLT\SCE
 */

namespace BLT\SCE;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLT_SCE_VERSION', '0.4.0' );
define( 'BLT_SCE_FILE', __FILE__ );
define( 'BLT_SCE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BLT_SCE_URL', plugin_dir_url( __FILE__ ) );
define( 'BLT_SCE_BASENAME', plugin_basename( __FILE__ ) );

// SureCart's current published version as of this plugin's discovery
// phase (2026-07-27, from the SureCart readme.txt "Stable tag"). This is
// NOT a verified-compatible floor — no live SureCart install was
// available to actually test against during this build. Confirm against
// a real site before assuming compatibility with older SureCart versions.
define( 'BLT_SCE_SURECART_VERSION_AT_BUILD_TIME', '4.6.2' );

/**
 * Composer autoloader (bundles plugin-update-checker and Action Scheduler).
 */
if ( file_exists( BLT_SCE_PATH . 'vendor/autoload.php' ) ) {
	require_once BLT_SCE_PATH . 'vendor/autoload.php';
}

/**
 * Action Scheduler must be loaded on plugins_loaded, before it's used.
 * Bundling is safe: multiple plugins can bundle it, only the newest
 * version present wins the load, per the library's own protections.
 * https://actionscheduler.org/usage/#load-order
 */
if ( file_exists( BLT_SCE_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once BLT_SCE_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

/**
 * BLT family shared layer: the BLT mark, the family update policy, and the
 * one encrypted store of shared connection settings.
 *
 * Registered here during load — NOT inside a hook — because the library
 * elects the newest copy present on the site on `plugins_loaded` priority 0
 * and the registry has to be complete by then. It also has to be required
 * before anything calls BLT_Family_Updates::apply(), which
 * Support\UpdateChecker does on `plugins_loaded` priority 20.
 *
 * No 'update_slug' key: this plugin builds its update checker with an
 * explicit 'blt-surecart-extensions' slug (see Support\UpdateChecker), so
 * the checker's slug already matches the registered one.
 *
 * Declaring a group only makes it offerable on the shared BLT screen. It
 * shares nothing until the site owner opts this plugin into that group
 * there, and the default is off — see the fallbacks in
 * Support\UpdateChecker and Modules\MakeAnOffer\Settings.
 */
require_once BLT_SCE_PATH . 'includes/blt-family/bootstrap.php';

\blt_family_register(
	BLT_SCE_FILE,
	array(
		'name'    => 'BLT SureCart Extensions',
		'slug'    => 'blt-surecart-extensions',
		'version' => BLT_SCE_VERSION,
		'menu'    => 'blt-sce-modules',
		'groups'  => array( 'github', 'stripe', 'surecart' ),
	)
);

/**
 * Boots the plugin once all plugins are loaded, so we can reliably check
 * whether SureCart is active before touching any of its classes/hooks.
 */
function blt_sce_boot() {
	if ( ! Plugin::dependencies_met() ) {
		add_action( 'admin_notices', array( Plugin::class, 'render_missing_dependency_notice' ) );
		return;
	}

	Plugin::instance()->init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\blt_sce_boot', 20 );

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
