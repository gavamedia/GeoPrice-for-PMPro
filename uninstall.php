<?php
/**
 * Uninstall handler for GeoPrice for PMPro.
 *
 * This file cleans up all plugin data from the database when the plugin is
 * DELETED (not just deactivated) via the WordPress admin.
 *
 * WordPress calls this file automatically when a user clicks "Delete" on the
 * Plugins page. It runs outside of the normal plugin lifecycle, so none of
 * our plugin files are loaded — we must reference option names and table
 * names directly.
 *
 * WHAT GETS CLEANED UP:
 *   1. Plugin settings from wp_options (geoprice_* options).
 *   2. Cached exchange rates and timestamps from wp_options.
 *   3. Geolocation transients from wp_options (geoprice_geo_* transients).
 *   4. Per-level country pricing from wp_pmpro_membership_levelmeta.
 *   5. Any remaining scheduled cron events.
 *
 * WHY THIS MATTERS:
 *   Without cleanup, orphaned data persists indefinitely in the database after
 *   plugin deletion. This is a minor privacy concern (IP-based geolocation
 *   transients contain country codes keyed by IP hashes) and a database hygiene
 *   issue (stale options and meta rows accumulate over time).
 *
 * SAFETY:
 *   The WP_UNINSTALL_PLUGIN constant is only defined by WordPress when this
 *   file is called through the legitimate plugin deletion process. If someone
 *   tries to load this file directly, the check fails and we exit immediately.
 *
 * @copyright 2024-2026 GAVAMEDIA Corporation (https://gavamedia.com)
 * @license   GPL-2.0-or-later
 * @package   GeoPrice_For_PMPro
 */

/* Security: only run when called by WordPress's uninstall process. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * 1. Delete all plugin settings from wp_options.
 *    These are the options registered in admin-settings.php.
 */
$options_to_delete = array(
	'geoprice_enabled',
	'geoprice_default_country',
	'geoprice_geo_provider',
	'geoprice_rate_provider',
	'geoprice_oxr_app_id',
	'geoprice_show_approx',
	'geoprice_exchange_rates',
	'geoprice_rates_updated',
	'geoprice_trusted_ip_header',
);

foreach ( $options_to_delete as $option ) {
	delete_option( $option );
}

/*
 * 2. Delete all geolocation transients.
 *    These are keyed as 'geoprice_geo_' + sha256(ip) and stored in wp_options
 *    as '_transient_geoprice_geo_*' and '_transient_timeout_geoprice_geo_*'.
 *    We use a direct database query because there's no WordPress API to
 *    delete transients by prefix.
 */
global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_geoprice_geo_%'
	    OR option_name LIKE '_transient_timeout_geoprice_geo_%'"
);

/*
 * 3. Delete per-level country pricing from PMPro's level meta table.
 *    The table may not exist if PMPro was already uninstalled, so we
 *    check for its existence first to avoid SQL errors.
 */
$levelmeta_table = $wpdb->prefix . 'pmpro_membership_levelmeta';
$table_exists    = $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
		DB_NAME,
		$levelmeta_table
	)
);

if ( $table_exists ) {
	$wpdb->delete( $levelmeta_table, array( 'meta_key' => 'geoprice_prices' ) );
	$wpdb->delete( $levelmeta_table, array( 'meta_key' => 'geoprice_active_countries' ) );
}

/*
 * 4. Clear any remaining scheduled cron events.
 *    This should already be done by the deactivation hook, but in case the
 *    plugin was deleted without being deactivated first (rare edge case),
 *    we clean it up here too.
 */
wp_clear_scheduled_hook( 'geoprice_pmpro_refresh_rates' );
