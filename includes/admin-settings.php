<?php
/**
 * Global plugin settings page for GeoPrice for PMPro.
 *
 * @copyright 2024-2026 GAVAMEDIA Corporation (https://gavamedia.com)
 * @license   GPL-2.0-or-later
 *
 * This file registers a settings page under PMPro's admin menu (Memberships → GeoPrice)
 * where the site administrator configures the plugin's global behavior:
 *
 *   - Enable/disable the entire plugin (without deactivating it).
 *   - Choose the default country for fallback when geolocation fails.
 *   - Select which geolocation provider to use (ip-api.com or ipapi.co).
 *   - Select which exchange rate provider to use (ExchangeRate-API or Open Exchange Rates).
 *   - Enter an API key for Open Exchange Rates (if selected).
 *   - Toggle the "(approximately)" label shown next to converted prices.
 *   - View the last exchange rate refresh timestamp and manually trigger a refresh.
 *   - See instructions for the admin country testing override (?geoprice_country=XX).
 *
 * HOW SETTINGS ARE STORED:
 *   Each setting is stored as a separate wp_options row. This uses WordPress's
 *   Settings API (register_setting + settings_fields) which provides:
 *     - Automatic nonce verification on form submission.
 *     - Sanitization callbacks (sanitize_text_field) applied before saving.
 *     - Default values when the option hasn't been set yet.
 *     - Integration with options.php for saving (the form posts to options.php,
 *       which validates the nonce and option group, runs sanitization, and saves).
 *
 * SETTINGS REFERENCE:
 *   Option name                 | Type   | Default           | Purpose
 *   --------------------------- | ------ | ----------------- | --------------------------------
 *   geoprice_enabled            | string | '1'               | Master on/off switch
 *   geoprice_default_country    | string | 'US'              | Fallback country code
 *   geoprice_geo_provider       | string | 'ipapi'           | Geolocation API provider
 *   geoprice_rate_provider      | string | 'exchangerate-api'| Exchange rate API provider
 *   geoprice_oxr_app_id         | string | ''                | Open Exchange Rates API key
 *   geoprice_show_approx        | string | '1'               | Show "(approximately)" label
 *   geoprice_trusted_ip_header  | string | 'REMOTE_ADDR'     | Trusted IP header for proxies
 *
 * WHY STRINGS INSTEAD OF BOOLEANS:
 *   WordPress checkboxes submit '1' when checked and nothing when unchecked.
 *   The Settings API doesn't have a built-in boolean type — using string type
 *   with '1'/'0' comparison is the standard WordPress pattern for checkbox options.
 *
 * @package GeoPrice_For_PMPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the GeoPrice settings page under PMPro's admin menu.
 *
 * WHY UNDER PMPRO'S MENU:
 *   Since this plugin is an add-on for PMPro, it makes sense to group its
 *   settings with PMPro's other admin pages rather than creating a new top-level
 *   menu entry. The parent slug 'pmpro-dashboard' places our page as a submenu
 *   item under "Memberships" in the admin sidebar.
 *
 * CAPABILITY:
 *   We use 'pmpro_membershiplevels' — the same capability PMPro requires for
 *   managing membership levels. This ensures that only users who can edit
 *   membership levels can also configure geo-pricing for those levels.
 *
 * PRIORITY 20:
 *   We hook at priority 20 (after PMPro's own menu registration at priority 10)
 *   to ensure the parent menu exists before we add our submenu item.
 */
function geoprice_admin_menu() {
	add_submenu_page(
		'pmpro-dashboard',                                        // Parent menu slug (PMPro's top-level menu).
		__( 'GeoPrice Settings', 'geoprice-for-pmpro' ),         // Browser tab title.
		__( 'GeoPrice', 'geoprice-for-pmpro' ),                  // Sidebar menu label.
		'pmpro_membershiplevels',                                 // Required capability.
		'geoprice-settings',                                      // URL slug: admin.php?page=geoprice-settings.
		'geoprice_settings_page'                                  // Callback function to render the page.
	);
}
add_action( 'admin_menu', 'geoprice_admin_menu', 20 );

/**
 * Register all plugin settings with the WordPress Settings API.
 *
 * WHY register_setting():
 *   WordPress's Settings API provides a secure, standardized way to handle
 *   option saving. By registering each option here with its sanitize_callback,
 *   WordPress automatically:
 *     1. Verifies the nonce when the form is submitted to options.php.
 *     2. Checks that the option belongs to the 'geoprice_settings' group.
 *     3. Runs sanitize_text_field() on the submitted value before saving.
 *     4. Stores the sanitized value in wp_options.
 *   This eliminates the need for manual nonce checks and sanitization in a
 *   custom save handler.
 */
function geoprice_register_settings() {
	/*
	 * geoprice_enabled: Master switch for the entire plugin.
	 * When '0' (unchecked), all frontend hooks (cost text filter, checkout
	 * level override) are bypassed — visitors see standard PMPro pricing.
	 * The admin side (country pricing table on level edit pages) still works
	 * so admins can configure prices even while the plugin is "off".
	 */
	register_setting( 'geoprice_settings', 'geoprice_enabled', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '1',
	) );

	/*
	 * geoprice_default_country: Fallback when geolocation fails.
	 * Used when: the visitor's IP can't be resolved (API down, rate-limited),
	 * the IP is a localhost address, or no cookie/transient cache exists.
	 * Typically set to the country where most visitors come from.
	 *
	 * Uses a whitelist sanitize callback instead of generic sanitize_text_field.
	 * The callback validates that the submitted value is a known country code.
	 * If an attacker manipulates the POST data to submit an invalid country
	 * code, it falls back to the default 'US'.
	 */
	register_setting( 'geoprice_settings', 'geoprice_default_country', array(
		'type'              => 'string',
		'sanitize_callback' => 'geoprice_sanitize_default_country',
		'default'           => 'US',
	) );

	/*
	 * geoprice_geo_provider: Which IP geolocation API to use.
	 * Options: 'ipapi' (default, HTTPS, secure) or 'ip-api' (HTTP-only free tier).
	 *
	 * Default changed from 'ip-api' to 'ipapi' because ipapi.co uses HTTPS,
	 * preventing man-in-the-middle attacks on the geolocation response.
	 * ip-api.com's free tier only supports HTTP.
	 *
	 * Uses a whitelist sanitize callback to ensure only known provider
	 * values are accepted. Rejects arbitrary strings.
	 */
	register_setting( 'geoprice_settings', 'geoprice_geo_provider', array(
		'type'              => 'string',
		'sanitize_callback' => 'geoprice_sanitize_geo_provider',
		'default'           => 'ipapi',
	) );

	/*
	 * geoprice_rate_provider: Which exchange rate API to use.
	 * Options: 'exchangerate-api' (default, free, no key) or
	 * 'openexchangerates' (free tier, requires App ID).
	 * See currency.php for API details and response handling.
	 *
	 * Uses a whitelist sanitize callback to ensure only known provider
	 * values are accepted.
	 */
	register_setting( 'geoprice_settings', 'geoprice_rate_provider', array(
		'type'              => 'string',
		'sanitize_callback' => 'geoprice_sanitize_rate_provider',
		'default'           => 'exchangerate-api',
	) );

	/*
	 * geoprice_oxr_app_id: API key for Open Exchange Rates.
	 * Only used when geoprice_rate_provider is 'openexchangerates'.
	 * The admin gets this from https://openexchangerates.org/signup/free.
	 * Empty by default — the plugin works without it using ExchangeRate-API.
	 */
	register_setting( 'geoprice_settings', 'geoprice_oxr_app_id', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );

	/*
	 * geoprice_show_approx: Whether to show "(approximately)" next to
	 * converted local currency amounts on the frontend.
	 * Recommended: ON ('1'), because exchange rates are only refreshed daily
	 * and the displayed local amount may differ slightly from the real-time
	 * equivalent. Transparency builds trust with international visitors.
	 */
	register_setting( 'geoprice_settings', 'geoprice_show_approx', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '1',
	) );

	/*
	 * Trusted IP header for proxy environments.
	 *
	 * By default, the plugin only trusts REMOTE_ADDR (the TCP connection IP)
	 * for geolocation. Sites behind a reverse proxy or CDN need to configure
	 * which header their proxy populates with the real client IP.
	 *
	 * Options:
	 *   - 'REMOTE_ADDR'            → Direct connection (no proxy). Default and safest.
	 *   - 'HTTP_CF_CONNECTING_IP'  → Cloudflare CDN.
	 *   - 'HTTP_X_FORWARDED_FOR'   → Generic load balancer / reverse proxy.
	 *   - 'HTTP_X_REAL_IP'         → Nginx reverse proxy.
	 *
	 * IMPORTANT: Only set this to a proxy header if your site is actually
	 * behind that proxy. If set incorrectly, an attacker could spoof their
	 * IP address by sending a fake header. See geolocation.php for details.
	 *
	 * Uses a whitelist sanitize callback to restrict to known safe values.
	 */
	register_setting( 'geoprice_settings', 'geoprice_trusted_ip_header', array(
		'type'              => 'string',
		'sanitize_callback' => 'geoprice_sanitize_trusted_ip_header',
		'default'           => 'REMOTE_ADDR',
	) );
}
add_action( 'admin_init', 'geoprice_register_settings' );

/**
 * ==========================================================================
 * WHITELIST SANITIZE CALLBACKS
 * ==========================================================================
 *
 * These callbacks replace the generic sanitize_text_field() for dropdown
 * settings. Instead of accepting any string, they validate the submitted
 * value against a whitelist of allowed values. If the value isn't in the
 * whitelist, they return the default.
 *
 * WHY THIS MATTERS:
 *   sanitize_text_field() only strips HTML tags and extra whitespace — it
 *   doesn't validate that the value is one of the expected dropdown options.
 *   An attacker who manipulates the POST data could set a dropdown to an
 *   unexpected value (e.g., setting geoprice_geo_provider to 'malicious'),
 *   which could cause unpredictable behavior in switch statements that
 *   assume only known values.
 *
 *   With whitelist validation, the value is guaranteed to be one of the
 *   expected options, making all downstream code predictable and safe.
 */

/**
 * Sanitize the default country setting.
 *
 * Validates that the submitted value is a known ISO 3166-1 alpha-2 country
 * code from our countries list. Falls back to 'US' if not recognized.
 *
 * @param string $value The submitted value from the settings form.
 * @return string A valid country code, or 'US' if invalid.
 */
function geoprice_sanitize_default_country( $value ) {
	$value     = sanitize_text_field( $value );
	$countries = geoprice_get_all_countries();
	if ( isset( $countries[ $value ] ) ) {
		return $value;
	}
	return 'US';
}

/**
 * Sanitize the geolocation provider setting.
 *
 * Only accepts 'ipapi' or 'ip-api'. Falls back to 'ipapi' (the secure
 * HTTPS default) if an unrecognized value is submitted.
 *
 * @param string $value The submitted value from the settings form.
 * @return string A valid provider slug, or 'ipapi' if invalid.
 */
function geoprice_sanitize_geo_provider( $value ) {
	$value   = sanitize_text_field( $value );
	$allowed = array( 'ipapi', 'ip-api' );
	if ( in_array( $value, $allowed, true ) ) {
		return $value;
	}
	return 'ipapi';
}

/**
 * Sanitize the exchange rate provider setting.
 *
 * Only accepts 'exchangerate-api' or 'openexchangerates'. Falls back to
 * 'exchangerate-api' if an unrecognized value is submitted.
 *
 * @param string $value The submitted value from the settings form.
 * @return string A valid provider slug, or 'exchangerate-api' if invalid.
 */
function geoprice_sanitize_rate_provider( $value ) {
	$value   = sanitize_text_field( $value );
	$allowed = array( 'exchangerate-api', 'openexchangerates' );
	if ( in_array( $value, $allowed, true ) ) {
		return $value;
	}
	return 'exchangerate-api';
}

/**
 * Sanitize the trusted IP header setting.
 *
 * Only accepts values from the allowed headers whitelist in geolocation.php.
 * Falls back to 'REMOTE_ADDR' (the safest default) if an unrecognized
 * value is submitted.
 *
 * SECURITY IMPORTANCE:
 *   If this setting could be set to an arbitrary server variable name, an
 *   attacker with admin access (or a corrupted option value) could point
 *   IP detection at any $_SERVER variable, potentially causing unexpected
 *   behavior. The whitelist ensures only known proxy headers are accepted.
 *
 * @param string $value The submitted value from the settings form.
 * @return string A valid $_SERVER key, or 'REMOTE_ADDR' if invalid.
 */
function geoprice_sanitize_trusted_ip_header( $value ) {
	$value   = sanitize_text_field( $value );
	$allowed = array(
		'REMOTE_ADDR',
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_REAL_IP',
	);
	if ( in_array( $value, $allowed, true ) ) {
		return $value;
	}
	return 'REMOTE_ADDR';
}

/**
 * Render the GeoPrice settings page HTML.
 *
 * FORM STRUCTURE:
 *   The form posts to options.php (WordPress's built-in option save handler).
 *   settings_fields('geoprice_settings') outputs:
 *     - A hidden nonce field for security verification.
 *     - A hidden 'option_page' field identifying this settings group.
 *   WordPress handles the POST, validates the nonce, runs sanitization
 *   callbacks, saves each registered option, and redirects back to this page.
 *
 * @return void
 */
function geoprice_settings_page() {
	/* Security: verify the current user has the required capability. */
	if ( ! current_user_can( 'pmpro_membershiplevels' ) ) {
		return;
	}

	$countries     = geoprice_get_all_countries();
	$rates_updated = get_option( 'geoprice_rates_updated', 0 );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'GeoPrice for PMPro', 'geoprice-for-pmpro' ); ?></h1>

		<?php if ( get_option( 'geoprice_enabled', '1' ) === '1' && get_option( 'pmpro_gateway' ) === 'stripe' ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'GeoPrice is currently enforcing Stripe billing-address collection, disabling Stripe payment request buttons, and requiring billing-address collection in Stripe Checkout so checkout pricing stays tied to the selected billing country. Keep Stripe dashboard address/CVC checks enabled, and note that issuer-country mismatches are logged for manual review rather than blocked automatically.', 'geoprice-for-pmpro' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<!--
			Form posts to options.php, which is WordPress's built-in handler for
			the Settings API. It verifies the nonce, checks capability, runs
			sanitize callbacks, and saves each registered option to wp_options.
		-->
		<form method="post" action="options.php">
			<?php
			/*
			 * settings_fields() outputs:
			 *   1. <input type="hidden" name="option_page" value="geoprice_settings">
			 *   2. A wp_nonce_field for the 'geoprice_settings-options' action.
			 *   3. An HTTP referer field for redirect-back after save.
			 */
			settings_fields( 'geoprice_settings' );
			?>

			<table class="form-table" role="presentation">
				<!--
					ENABLE/DISABLE TOGGLE
					When unchecked, the checkbox sends no value in POST. WordPress
					Settings API will save the option as empty string (''), which
					our frontend hooks check: if !== '1', they bail early and let
					PMPro display its default pricing unchanged.
				-->
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable GeoPrice', 'geoprice-for-pmpro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="geoprice_enabled" value="1" <?php checked( get_option( 'geoprice_enabled', '1' ), '1' ); ?> />
							<?php esc_html_e( 'Enable geographic pricing and currency display', 'geoprice-for-pmpro' ); ?>
						</label>
					</td>
				</tr>

				<!--
					DEFAULT COUNTRY
					Dropdown of all ~195 countries. This country is used when
					geolocation can't determine the visitor's location (localhost,
					API failure, etc.). The visitor sees pricing as if they were
					in this country.
				-->
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Country', 'geoprice-for-pmpro' ); ?></th>
					<td>
						<select name="geoprice_default_country">
							<?php foreach ( $countries as $code => $data ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>" <?php selected( get_option( 'geoprice_default_country', 'US' ), $code ); ?>>
									<?php echo esc_html( $data['name'] . ' (' . $code . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Fallback country when geolocation is unavailable (e.g., localhost).', 'geoprice-for-pmpro' ); ?>
						</p>
					</td>
				</tr>

				<!--
					GEOLOCATION PROVIDER
					Default is ipapi.co because it uses HTTPS (prevents MITM attacks on
					geolocation responses). ip-api.com's free tier only supports HTTP
					(responses can be intercepted). Both options are free and require no
					API key. Whichever is selected, the other is used as an automatic
					fallback (see geolocation.php).
				-->
				<tr>
					<th scope="row"><?php esc_html_e( 'Geolocation Provider', 'geoprice-for-pmpro' ); ?></th>
					<td>
						<select name="geoprice_geo_provider">
							<option value="ipapi" <?php selected( get_option( 'geoprice_geo_provider', 'ipapi' ), 'ipapi' ); ?>>
								ipapi.co (free, HTTPS, recommended)
							</option>
							<option value="ip-api" <?php selected( get_option( 'geoprice_geo_provider', 'ipapi' ), 'ip-api' ); ?>>
								ip-api.com (free, HTTP only)
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'ipapi.co is recommended because it uses HTTPS, preventing interception of geolocation responses.', 'geoprice-for-pmpro' ); ?>
						</p>
					</td>
				</tr>

				<!--
					TRUSTED IP HEADER
					Configures which server variable to read the visitor's IP from.
					Default: REMOTE_ADDR (direct TCP connection IP, cannot be spoofed).
					Sites behind a CDN or load balancer must configure this to the
					header that their proxy populates with the real client IP.

					WARNING: Setting this to a proxy header on a site that's NOT behind
					that proxy allows visitors to spoof their IP by sending a fake header.
					Only change this if you know your hosting infrastructure.
				-->
				<tr>
					<th scope="row"><?php esc_html_e( 'IP Detection Method', 'geoprice-for-pmpro' ); ?></th>
					<td>
						<select name="geoprice_trusted_ip_header">
							<option value="REMOTE_ADDR" <?php selected( get_option( 'geoprice_trusted_ip_header', 'REMOTE_ADDR' ), 'REMOTE_ADDR' ); ?>>
								<?php esc_html_e( 'Direct connection (REMOTE_ADDR) — Default', 'geoprice-for-pmpro' ); ?>
							</option>
							<option value="HTTP_CF_CONNECTING_IP" <?php selected( get_option( 'geoprice_trusted_ip_header', 'REMOTE_ADDR' ), 'HTTP_CF_CONNECTING_IP' ); ?>>
								<?php esc_html_e( 'Cloudflare (CF-Connecting-IP)', 'geoprice-for-pmpro' ); ?>
							</option>
							<option value="HTTP_X_FORWARDED_FOR" <?php selected( get_option( 'geoprice_trusted_ip_header', 'REMOTE_ADDR' ), 'HTTP_X_FORWARDED_FOR' ); ?>>
								<?php esc_html_e( 'Load Balancer / Proxy (X-Forwarded-For)', 'geoprice-for-pmpro' ); ?>
							</option>
							<option value="HTTP_X_REAL_IP" <?php selected( get_option( 'geoprice_trusted_ip_header', 'REMOTE_ADDR' ), 'HTTP_X_REAL_IP' ); ?>>
								<?php esc_html_e( 'Nginx Proxy (X-Real-IP)', 'geoprice-for-pmpro' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'How to detect visitor IP addresses for geolocation. Only change this if your site is behind a CDN or reverse proxy.', 'geoprice-for-pmpro' ); ?>
						</p>
					</td>
				</tr>

				<!--
					EXCHANGE RATE PROVIDER
					ExchangeRate-API is the default because it requires no signup
					or API key. Open Exchange Rates offers more currencies and
					hourly updates on paid plans, but the free tier is daily
					(same as ExchangeRate-API).
				-->
				<tr>
					<th scope="row"><?php esc_html_e( 'Exchange Rate Provider', 'geoprice-for-pmpro' ); ?></th>
					<td>
						<select name="geoprice_rate_provider" id="geoprice_rate_provider">
							<option value="exchangerate-api" <?php selected( get_option( 'geoprice_rate_provider', 'exchangerate-api' ), 'exchangerate-api' ); ?>>
								ExchangeRate-API (free, no key required)
							</option>
							<option value="openexchangerates" <?php selected( get_option( 'geoprice_rate_provider', 'exchangerate-api' ), 'openexchangerates' ); ?>>
								Open Exchange Rates (requires App ID)
							</option>
						</select>
					</td>
				</tr>

				<!--
					OPEN EXCHANGE RATES APP ID
					Only shown when "Open Exchange Rates" is selected as the provider.
					Visibility is toggled by inline jQuery below. The App ID is sent
					with API requests as a URL parameter for authentication.

					Uses type="password" to prevent the API key from being visible on
					screen (shoulder surfing) and to stop browsers from caching it in
					form autofill (the autocomplete="off" attribute reinforces this).
					The key is still stored as plaintext in wp_options (which is normal
					for WordPress — the database is trusted), but it is not displayed
					in cleartext on the admin page.
				-->
				<tr id="geoprice_oxr_row" style="<?php echo get_option( 'geoprice_rate_provider', 'exchangerate-api' ) !== 'openexchangerates' ? 'display:none;' : ''; ?>">
					<th scope="row"><?php esc_html_e( 'Open Exchange Rates App ID', 'geoprice-for-pmpro' ); ?></th>
					<td>
						<input type="password" name="geoprice_oxr_app_id" value="<?php echo esc_attr( get_option( 'geoprice_oxr_app_id', '' ) ); ?>" class="regular-text" autocomplete="off" />
						<p class="description">
							<?php
							printf(
								/* translators: %s: URL to Open Exchange Rates signup */
								esc_html__( 'Get a free App ID at %s', 'geoprice-for-pmpro' ),
								'<a href="https://openexchangerates.org/signup/free" target="_blank">openexchangerates.org</a>'
							);
							?>
						</p>
					</td>
				</tr>

				<!--
					"APPROXIMATELY" LABEL TOGGLE
					When enabled, converted local currency amounts are displayed
					with "(approximately)" to set expectations that the displayed
					amount is based on daily exchange rates, not real-time.
				-->
				<tr>
					<th scope="row"><?php esc_html_e( 'Show "approximately"', 'geoprice-for-pmpro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="geoprice_show_approx" value="1" <?php checked( get_option( 'geoprice_show_approx', '1' ), '1' ); ?> />
							<?php esc_html_e( 'Show "approximately" label next to converted currency amounts', 'geoprice-for-pmpro' ); ?>
						</label>
					</td>
				</tr>

				<!--
					EXCHANGE RATE STATUS
					Shows when rates were last successfully fetched, and provides
					a manual "Refresh Rates Now" button for immediate updates.
					The button links to this same page with a geoprice_refresh_rates=1
					query parameter and a nonce. See geoprice_handle_refresh_rates()
					below for the handler.
				-->
				<tr>
					<th scope="row"><?php esc_html_e( 'Exchange Rates Status', 'geoprice-for-pmpro' ); ?></th>
					<td>
						<?php if ( $rates_updated ) : ?>
							<p>
								<?php
								/*
								 * Display the last update time using the site's configured
								 * date and time format (Settings → General) for consistency.
								 *
								 * wp_date() (introduced WP 5.3) automatically converts the
								 * Unix timestamp to the site's local timezone configured in
								 * Settings → General → Timezone. This replaces the previous
								 * date_i18n() call which treated the timestamp as UTC and
								 * displayed the wrong time for non-UTC sites.
								 */
								printf(
									/* translators: %s: date/time of last update */
									esc_html__( 'Last updated: %s', 'geoprice-for-pmpro' ),
									esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $rates_updated ) )
								);
								?>
							</p>
						<?php else : ?>
							<p><?php esc_html_e( 'Rates have not been fetched yet.', 'geoprice-for-pmpro' ); ?></p>
						<?php endif; ?>
						<p>
							<!--
								The "Refresh Rates Now" button is an <a> tag (not a form button)
								that links to this page with a special query parameter. The nonce
								prevents CSRF attacks — a malicious link can't trigger rate refresh
								without a valid nonce.
							-->
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=geoprice-settings&geoprice_refresh_rates=1' ), 'geoprice_refresh_rates' ) ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Refresh Rates Now', 'geoprice-for-pmpro' ); ?>
							</a>
						</p>
					</td>
				</tr>

				<!--
					ADMIN TESTING INSTRUCTIONS
					Informational row explaining how to use the ?geoprice_country=XX
					URL parameter to simulate pricing for different countries.
					This is view-only — no form field, just guidance.
				-->
				<tr>
					<th scope="row"><?php esc_html_e( 'Test Country Detection', 'geoprice-for-pmpro' ); ?></th>
					<td>
						<p class="description">
							<?php
							printf(
								/* translators: %s: example URL parameter */
								esc_html__( 'As an admin, append %s to any page URL to simulate a visitor from that country.', 'geoprice-for-pmpro' ),
								'<code>?geoprice_country=CA</code>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>

	<!--
		Inline JS to toggle the Open Exchange Rates App ID field visibility.
		When the rate provider dropdown changes, we show/hide the App ID row.
		This is a small amount of JS, so it's inline rather than in a separate file.
	-->
	<script>
	jQuery(function($) {
		$('#geoprice_rate_provider').on('change', function() {
			$('#geoprice_oxr_row').toggle($(this).val() === 'openexchangerates');
		});
	});
	</script>
	<?php
}

/**
 * Handle the "Refresh Rates Now" admin action.
 *
 * This runs on every admin_init. It checks for the geoprice_refresh_rates=1
 * query parameter and, if present, verifies the nonce and user capability
 * before triggering a synchronous exchange rate fetch.
 *
 * WHY admin_init:
 *   We need to process this action before the page renders so we can show a
 *   success/error notice. admin_init fires early enough that we can add an
 *   admin_notices callback that will be picked up when the page HTML is output.
 *
 * SECURITY:
 *   Three checks prevent abuse:
 *   1. The geoprice_refresh_rates query param must be present.
 *   2. The _wpnonce must be valid (generated by wp_nonce_url in the button link).
 *   3. The user must have the 'pmpro_membershiplevels' capability.
 *   All three must pass before the rate fetch is triggered.
 *
 * @return void
 */
function geoprice_handle_refresh_rates() {
	if ( empty( $_GET['geoprice_refresh_rates'] ) || empty( $_GET['_wpnonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'geoprice_refresh_rates' ) ) {
		return;
	}

	if ( ! current_user_can( 'pmpro_membershiplevels' ) ) {
		return;
	}

	$success = geoprice_fetch_exchange_rates();

	/*
	 * Show a success or error notice. We use closures (anonymous functions)
	 * to add the notices inline without needing separate named functions.
	 * These are hooked into admin_notices which fires during page rendering.
	 */
	if ( $success ) {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'Exchange rates refreshed successfully.', 'geoprice-for-pmpro' );
			echo '</p></div>';
		} );
	} else {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-error is-dismissible"><p>';
			esc_html_e( 'Failed to refresh exchange rates. Please check your provider settings.', 'geoprice-for-pmpro' );
			echo '</p></div>';
		} );
	}
}
add_action( 'admin_init', 'geoprice_handle_refresh_rates' );

/**
 * Add a "Settings" link to the plugin's row on the Plugins page.
 *
 * WHY THIS IS HELPFUL:
 *   After activating the plugin, the admin's first question is "where do I
 *   configure this?" A "Settings" link directly on the Plugins page (next to
 *   "Deactivate") provides an immediate, discoverable path to configuration
 *   without needing to hunt through admin menus.
 *
 * HOW IT WORKS:
 *   WordPress fires the 'plugin_action_links_{basename}' filter for each plugin
 *   row on the Plugins page. We prepend our settings link to the beginning of
 *   the links array so it appears as the first action link (before "Deactivate").
 *
 * @param array $links Existing action links (e.g., "Deactivate", "Edit").
 * @return array Modified links array with "Settings" prepended.
 */
function geoprice_plugin_action_links( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=geoprice-settings' ) ) . '">'
		. esc_html__( 'Settings', 'geoprice-for-pmpro' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . GEOPRICE_PMPRO_BASENAME, 'geoprice_plugin_action_links' );
