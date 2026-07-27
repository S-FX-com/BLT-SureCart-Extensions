<?php
/**
 * Uninstall cleanup. Drops local tables and options ONLY if the site
 * owner has explicitly opted in via Settings → Export/Import → Danger
 * zone — fulfillment history is never destroyed by default.
 *
 * @package BLT\SCE
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'blt_sce_delete_data_on_uninstall', false ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

\BLT\SCE\Db\Schema::uninstall();

$options = array(
	'blt_sce_enabled_modules',
	'blt_sce_delete_data_on_uninstall',
	'blt_sce_verbose_logging',
	'blt_sce_shippo_api_token',
	'blt_sce_shippo_ship_from',
	'blt_sce_shippo_reconcile_after_hours',
	'blt_sce_shippo_kill_switch',
	'blt_sce_shippo_auto_purchase',
	'blt_sce_shippo_mode',
	'blt_sce_shippo_rate_ceiling_cents',
	'blt_sce_shippo_rate_ceiling_percent',
	'blt_sce_shippo_allowed_countries',
	'blt_sce_shippo_allow_military',
	'blt_sce_shippo_parcels',
	'blt_sce_shippo_sku_parcel_map',
	'blt_sce_shippo_default_parcel_id',
	'blt_sce_shippo_service_rules',
	'blt_sce_shippo_webhook_token',
	'blt_sce_shippo_webhook_ip_allowlist',
	'blt_sce_shippo_webhook_hmac_secret',
);

foreach ( $options as $option ) {
	delete_option( $option );
}
