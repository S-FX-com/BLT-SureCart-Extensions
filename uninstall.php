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
	// Restrict Price by Role module (key shared with the retired
	// standalone plugin, deleted only under this same explicit opt-in).
	'scrpbr_restrictions',
	// Make an Offer module.
	'sc_make_an_offer_settings',
	'blt_sce_offer_sc_mpm_id',
	'blt_sce_offer_sc_adhoc_prices',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Make an Offer: remove offer records (sc_offer posts + their meta).
// Statuses are listed explicitly — the plugin isn't loaded during
// uninstall, so the custom statuses aren't registered and 'any' would
// not match them.
$offer_ids = get_posts(
	array(
		'post_type'      => 'sc_offer',
		'post_status'    => array( 'offer_pending', 'offer_accepted', 'offer_declined', 'offer_expired', 'offer_countered', 'offer_cancelled' ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $offer_ids as $offer_id ) {
	wp_delete_post( $offer_id, true );
}
