<?php
/**
 * Plugin Name: GeoPrice for PMPro
 * Plugin URI:  https://gavamedia.com/plugins/geoprice-for-pmpro
 * Description: Variable geographic pricing for Paid Memberships Pro. Set country-specific membership prices in USD and display converted local currency amounts to visitors.
 * Version:     1.0.0
 * Author:      GAVAMEDIA Corporation
 * Author URI:  https://gavamedia.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: geoprice-for-pmpro
 * Domain Path: /languages
 * Requires Plugins: paid-memberships-pro
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @copyright 2024-2026 GAVAMEDIA Corporation (https://gavamedia.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

/*
 * ==========================================================================
 * PLUGIN OVERVIEW — GeoPrice for PMPro
 * ==========================================================================
 *
 * PURPOSE:
 *   This plugin is an add-on for Paid Memberships Pro (PMPro) that allows
 *   site administrators to define custom membership pricing on a per-country
 *   basis. All prices are stored in USD as a single consistent baseline.
 *   Visitors see prices converted into their local currency using live
 *   exchange rates, but the actual charge processed by the payment gateway
 *   (Stripe, PayPal, etc.) is the country-specific USD amount.
 *
 * HIGH-LEVEL FLOW:
 *
 *   1. ADMIN CONFIGURATION (admin-level-pricing.php):
 *      - On each PMPro membership level edit page, a "Geographic Pricing"
 *        section is injected below the existing billing settings.
 *      - The admin enters custom USD prices (initial_payment, billing_amount)
 *        for specific countries. The top 20 countries by population are shown
 *        by default; a "Show All Countries" button reveals all ~195 countries.
 *      - Prices are stored as JSON in PMPro's level meta table using the
 *        key 'geoprice_prices'. Example stored value:
 *        {"CA":{"initial_payment":"29.00","billing_amount":"29.00"},
 *         "MX":{"initial_payment":"19.00","billing_amount":"19.00"}}
 *
 *   2. VISITOR GEOLOCATION (geolocation.php):
 *      - When a visitor loads a page with pricing, their IP address is used
 *        to determine their country via a free geolocation API (ip-api.com
 *        or ipapi.co — configurable in settings).
 *      - The detected country is cached in a WordPress transient (keyed by
 *        IP hash, 24-hour TTL) and in a browser cookie to avoid repeat API
 *        calls on subsequent page loads.
 *      - Admins can simulate any country by appending ?geoprice_country=XX
 *        to any URL (e.g., ?geoprice_country=CA to test Canadian pricing).
 *
 *   3. CURRENCY CONVERSION (currency.php):
 *      - Exchange rates (USD-based) are fetched from a free API (open.er-api.com
 *        by default, or Open Exchange Rates with an API key).
 *      - Rates are cached in wp_options and refreshed daily by a wp_cron job
 *        scheduled on plugin activation. A manual "Refresh Rates Now" button
 *        is available in the settings page.
 *      - Conversion is a simple multiplication: USD_amount * rate = local_amount.
 *
 *   4. FRONTEND DISPLAY (frontend.php):
 *      - The `pmpro_level_cost_text` filter is hooked (priority 20) to replace
 *        PMPro's default cost text with the visitor's local currency equivalent.
 *        Example: A $29 USD price for a Canadian visitor becomes "CA$38.50 per
 *        Month (approximately)."
 *      - The "(approximately)" label is shown because exchange rates fluctuate
 *        and are only refreshed daily. This can be toggled off in settings.
 *
 *   5. CHECKOUT ENFORCEMENT (frontend.php):
 *      - The `pmpro_checkout_level` filter is hooked (priority 20) to override
 *        the level's initial_payment and billing_amount with the country-specific
 *        USD price BEFORE the payment gateway processes the charge.
 *      - This is the critical security/accuracy hook: it ensures the gateway
 *        charges the correct country-specific USD amount regardless of what
 *        the visitor saw displayed in their local currency.
 *      - Example: Canadian visitor sees "CA$38.50/month" on the frontend, but
 *        checkout overrides the level to $29.00 USD, and Stripe charges $29.00.
 *
 * FILE STRUCTURE:
 *   geoprice-for-pmpro.php .............. This file. Plugin bootstrap, constants,
 *                                          dependency check, cron scheduling.
 *   includes/countries.php .............. Static data: all countries with ISO codes,
 *                                          currency codes, currency symbols, and
 *                                          the "top 20 by population" list.
 *   includes/geolocation.php ........... IP-to-country detection with multi-provider
 *                                          support, caching (transient + cookie),
 *                                          and admin testing override.
 *   includes/currency.php .............. Exchange rate fetching from external APIs,
 *                                          caching in wp_options, conversion math,
 *                                          and price formatting.
 *   includes/admin-settings.php ........ Global plugin settings page (registered
 *                                          under PMPro's admin menu). Controls for
 *                                          enable/disable, geolocation provider,
 *                                          exchange rate provider, API keys, etc.
 *   includes/admin-level-pricing.php ... Per-country pricing UI on the PMPro
 *                                          membership level edit page. Renders the
 *                                          country pricing table and saves data.
 *   includes/frontend.php .............. Frontend hooks: cost text filter for display,
 *                                          checkout level filter for price enforcement,
 *                                          and inline CSS.
 *   assets/css/admin.css ............... Admin-only CSS for the country pricing table.
 *   assets/js/admin.js ................. Admin-only JS for show/hide countries,
 *                                          search filter, and row highlighting.
 *
 * PMPRO HOOKS USED:
 *   - Action: pmpro_membership_level_after_other_settings — injects the country
 *     pricing table into the level edit form.
 *   - Action: pmpro_save_membership_level — saves country pricing data when the
 *     level edit form is submitted.
 *   - Filter: pmpro_level_cost_text — replaces the cost display text with the
 *     visitor's local currency equivalent.
 *   - Filter: pmpro_checkout_level — overrides initial_payment and billing_amount
 *     with the country-specific USD price at checkout time.
 *
 * DATA STORAGE:
 *   - Per-level country prices: stored in the `wp_pmpro_membership_levelmeta`
 *     table with meta_key = 'geoprice_prices' and meta_value = JSON string.
 *   - Exchange rates: stored in wp_options as 'geoprice_exchange_rates' (array).
 *   - Last rate update timestamp: 'geoprice_rates_updated' (int, Unix timestamp).
 *   - Plugin settings: individual wp_options entries prefixed with 'geoprice_'.
 *   - Geolocation cache: WordPress transients prefixed with 'geoprice_geo_'.
 *   - Visitor country cookie: 'geoprice_country' (2-letter code, 24-hour TTL).
 *
 * WORDPRESS APIs USED:
 *   - Options API (get_option, update_option) for settings and rate storage.
 *   - Transients API (get_transient, set_transient) for IP geolocation caching.
 *   - HTTP API (wp_remote_get) for external API calls.
 *   - Cron API (wp_schedule_event, wp_clear_scheduled_hook) for daily rate refresh.
 *   - Settings API (register_setting, settings_fields) for the settings page.
 *   - PMPro's Metadata API (get/update/delete_pmpro_membership_level_meta)
 *     for per-level country pricing storage.
 */

/*
 * --------------------------------------------------------------------------
 * Security guard: prevent direct file access.
 * --------------------------------------------------------------------------
 * ABSPATH is defined by WordPress during bootstrap. If this file is loaded
 * directly (e.g., by navigating to it in a browser), ABSPATH won't exist
 * and we immediately exit to prevent any code execution outside of WordPress.
 */
defined( 'ABSPATH' ) || exit;

/*
 * --------------------------------------------------------------------------
 * Plugin constants.
 * --------------------------------------------------------------------------
 * These constants provide a single source of truth for the plugin version,
 * filesystem path, URL, and basename. They are used throughout the plugin
 * for asset enqueuing, file includes, and registering hooks.
 *
 * GEOPRICE_PMPRO_VERSION — Used for cache-busting when enqueuing CSS/JS assets.
 *                          Bump this when releasing a new version.
 * GEOPRICE_PMPRO_DIR     — Absolute filesystem path to this plugin's directory,
 *                          with trailing slash. Used for require_once includes.
 * GEOPRICE_PMPRO_URL     — URL to this plugin's directory, with trailing slash.
 *                          Used for wp_enqueue_style/script src parameters.
 * GEOPRICE_PMPRO_BASENAME — Plugin basename (e.g., "geoprice-for-pmpro/geoprice-for-pmpro.php").
 *                           Used by the plugin_action_links filter to add a
 *                           "Settings" link on the Plugins page.
 */
define( 'GEOPRICE_PMPRO_VERSION', '1.0.0' );
define( 'GEOPRICE_PMPRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'GEOPRICE_PMPRO_URL', plugin_dir_url( __FILE__ ) );
define( 'GEOPRICE_PMPRO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Initialize the plugin after all plugins have loaded.
 *
 * WHY `plugins_loaded` HOOK:
 *   We wait for the `plugins_loaded` action (fires after all active plugins are
 *   included) before checking whether PMPro is active. This ensures that PMPro
 *   has had a chance to define its PMPRO_VERSION constant regardless of plugin
 *   load order. If we checked at the top of this file, PMPro might not be loaded
 *   yet and we'd incorrectly report it as missing.
 *
 * DEPENDENCY CHECK:
 *   We check for the PMPRO_VERSION constant (defined in paid-memberships-pro.php)
 *   rather than checking for a specific function or class. This is the standard
 *   way to detect PMPro because it's the first thing PMPro defines and is always
 *   available when PMPro is active.
 *
 * INCLUDE ORDER:
 *   Files are included in dependency order — countries.php first because the
 *   geolocation and currency files reference country/currency data, then the
 *   service layers (geolocation, currency), then the UI layers (admin, frontend).
 *
 * @return void
 */
function geoprice_pmpro_init() {
	/*
	 * If PMPro is not active, show an admin error notice and bail.
	 * We don't load any of our includes because they reference PMPro
	 * functions (like get_pmpro_membership_level_meta) that won't exist.
	 */
	if ( ! defined( 'PMPRO_VERSION' ) ) {
		add_action( 'admin_notices', 'geoprice_pmpro_missing_notice' );
		return;
	}

	/* Data layer: country names, codes, currency mappings, and symbols. */
	require_once GEOPRICE_PMPRO_DIR . 'includes/countries.php';

	/* Service layer: IP-based country detection with caching. */
	require_once GEOPRICE_PMPRO_DIR . 'includes/geolocation.php';

	/* Service layer: exchange rate fetching, caching, and conversion. */
	require_once GEOPRICE_PMPRO_DIR . 'includes/currency.php';

	/* Admin: global plugin settings page under PMPro menu. */
	require_once GEOPRICE_PMPRO_DIR . 'includes/admin-settings.php';

	/* Admin: per-country pricing fields on the level edit page. */
	require_once GEOPRICE_PMPRO_DIR . 'includes/admin-level-pricing.php';

	/* Frontend: cost text display filter and checkout price enforcement. */
	require_once GEOPRICE_PMPRO_DIR . 'includes/frontend.php';
}
add_action( 'plugins_loaded', 'geoprice_pmpro_init' );

/**
 * Display an admin error notice when PMPro is not installed or active.
 *
 * WHY THIS EXISTS:
 *   This plugin is an add-on that extends PMPro's membership level system.
 *   Without PMPro active, our hooks (pmpro_checkout_level, pmpro_level_cost_text,
 *   pmpro_save_membership_level, etc.) would never fire, and calling PMPro
 *   functions like get_pmpro_membership_level_meta() would cause fatal errors.
 *   This notice tells the admin exactly what's wrong so they can fix it.
 *
 * @return void
 */
function geoprice_pmpro_missing_notice() {
	echo '<div class="notice notice-error"><p>';
	esc_html_e( 'GeoPrice for PMPro requires Paid Memberships Pro to be installed and active.', 'geoprice-for-pmpro' );
	echo '</p></div>';
}

/**
 * Plugin activation: schedule the daily exchange rate refresh cron job.
 *
 * WHY A CRON JOB:
 *   Exchange rates change throughout the day, but for membership pricing display
 *   purposes, a daily refresh provides a good balance between accuracy and
 *   performance. We show "(approximately)" next to converted prices to set
 *   expectations that rates are not real-time.
 *
 * HOW IT WORKS:
 *   WordPress cron (wp-cron.php) runs on page loads, not on a real system timer.
 *   When the scheduled time has passed and a visitor loads any page, WordPress
 *   triggers all overdue cron events. The 'geoprice_pmpro_refresh_rates' event
 *   calls geoprice_fetch_exchange_rates() (defined in currency.php), which
 *   fetches fresh rates from the configured API and stores them in wp_options.
 *
 * SAFETY CHECK:
 *   wp_next_scheduled() prevents scheduling duplicate events if the plugin is
 *   deactivated and reactivated. Without this check, each activation would add
 *   another parallel daily schedule, causing redundant API calls.
 *
 * @return void
 */
function geoprice_pmpro_activate() {
	if ( ! wp_next_scheduled( 'geoprice_pmpro_refresh_rates' ) ) {
		wp_schedule_event( time(), 'daily', 'geoprice_pmpro_refresh_rates' );
	}
}
register_activation_hook( __FILE__, 'geoprice_pmpro_activate' );

/**
 * Plugin deactivation: remove the daily exchange rate cron job.
 *
 * WHY CLEANUP IS NEEDED:
 *   If we don't clear the scheduled hook on deactivation, the cron event
 *   would persist in the wp_options cron array and fire on every scheduled
 *   interval — but the callback function wouldn't exist (since our plugin
 *   files aren't loaded when inactive), causing PHP warnings/errors in logs.
 *
 * NOTE:
 *   This does NOT delete stored exchange rates or plugin settings from the
 *   database. Those persist so that if the plugin is reactivated, the admin
 *   doesn't have to reconfigure everything. Full cleanup (deleting all options,
 *   transients, level meta, and cron events) is handled by uninstall.php,
 *   which runs when the plugin is deleted via the WordPress admin.
 *
 * @return void
 */
function geoprice_pmpro_deactivate() {
	wp_clear_scheduled_hook( 'geoprice_pmpro_refresh_rates' );
}
register_deactivation_hook( __FILE__, 'geoprice_pmpro_deactivate' );
