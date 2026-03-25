<?php
/**
 * Per-country pricing UI on the PMPro membership level edit page.
 *
 * This file adds a "Geographic Pricing" collapsible section to the PMPro
 * membership level edit form (wp-admin/admin.php?page=pmpro-membershiplevels&edit=X),
 * positioned directly below Billing Details as its own top-level collapsible
 * section with PMPro's native toggle UI. It allows the admin to set custom
 * USD prices for specific countries.
 *
 * HOW IT INTEGRATES WITH PMPRO:
 *   PMPro's level edit form fires several action hooks at different points.
 *   We use two of them:
 *
 *   1. `pmpro_membership_level_after_trial_settings` (priority 10):
 *      Fires at the end of the Billing Details section (after Trial Settings).
 *      We close PMPro's section wrappers, render our own standalone collapsible
 *      section using PMPro's native pmpro_section markup, then re-open the
 *      wrappers so PMPro's own closing tags don't produce broken HTML.
 *      This positions our section directly BELOW Billing Details and ABOVE
 *      Expiration Settings, which is the natural location for pricing config.
 *
 *   2. `pmpro_save_membership_level` (priority 10):
 *      Fires after PMPro has saved the level to the database (including its
 *      own fields like name, billing_amount, etc.). We receive the saved level
 *      ID and use it to save our per-country pricing data to the level meta table.
 *
 * DATA MODEL:
 *   Per-country prices are stored as a single JSON string in PMPro's level meta:
 *     - Table: wp_pmpro_membership_levelmeta
 *     - meta_key: 'geoprice_prices'
 *     - meta_value: JSON object mapping country codes to price objects.
 *
 *   Example stored value:
 *   {
 *     "CA": { "initial_payment": "29.00", "billing_amount": "29.00" },
 *     "MX": { "initial_payment": "19.00", "billing_amount": "19.00" },
 *     "IN": { "initial_payment": "9.00", "billing_amount": "9.00" }
 *   }
 *
 *   Each country entry has up to two keys:
 *     - initial_payment: The one-time payment charged at signup (in USD).
 *     - billing_amount: The recurring payment amount (in USD).
 *   Both are optional — if a country entry only has billing_amount, the level's
 *   default initial_payment is used for that country (and vice versa).
 *
 *   Countries with no entry use the level's default pricing (set in PMPro's
 *   own billing fields above our section).
 *
 * ADMIN UI DESIGN:
 *   - The table shows all ~195 countries, but only the top 20 (by population)
 *     are visible by default. The rest are hidden and revealed by "Show All Countries."
 *   - Countries that already have saved prices are always visible, even if they're
 *     not in the top 20. This prevents confusion when a price is set for a
 *     lesser-known country and the admin can't see it.
 *   - Each row has two input fields (initial_payment, billing_amount) with a "$"
 *     prefix and "default" placeholder text. Empty fields mean "use level default."
 *   - Rows with prices entered are highlighted green for quick visual scanning.
 *   - A search/filter input lets the admin quickly find any country by name.
 *
 * FORM FIELD NAMING:
 *   Inputs are named: geoprice_prices[{COUNTRY_CODE}][initial_payment]
 *                      geoprice_prices[{COUNTRY_CODE}][billing_amount]
 *   PHP receives this as: $_POST['geoprice_prices']['CA']['initial_payment'] = '29.00'
 *   This nested array structure makes it easy to iterate and save.
 *
 * SECURITY:
 *   - A dedicated nonce field (geoprice_nonce) is added to the form and verified
 *     on save. This prevents CSRF attacks from tricking an admin into saving
 *     malicious pricing data.
 *   - Country codes are validated against the known country list (rejects injection).
 *   - Price values are sanitized and validated as numeric before storage.
 *   - User capability is checked (pmpro_membershiplevels) before saving.
 *
 * @copyright 2024-2026 GAVAMEDIA Corporation (https://gavamedia.com)
 * @license   GPL-2.0-or-later
 * @package   GeoPrice_For_PMPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue admin CSS and JS assets on the level edit page.
 *
 * CONDITIONAL LOADING:
 *   We only load our assets on the specific admin page where they're needed:
 *   the PMPro membership level editor. This is identified by:
 *     1. The hook suffix 'memberships_page_pmpro-membershiplevels'
 *        (PMPro's registered admin page).
 *     2. The 'edit' query parameter being present (we're editing a level,
 *        not viewing the level list).
 *
 *   This prevents our CSS/JS from being loaded on unrelated admin pages,
 *   which would be wasteful and could cause conflicts.
 *
 * ASSETS:
 *   - admin.css: Styles for the country pricing table (borders, spacing, colors).
 *   - admin.js: jQuery script for show/hide countries, search filter, and
 *     row highlighting. Depends on jQuery (which WordPress already loads in admin).
 *
 * @param string $hook The WordPress admin page hook suffix. Each admin page has a
 *                     unique hook suffix used for conditional asset loading.
 * @return void
 */
function geoprice_admin_enqueue_scripts( $hook ) {
	/* Only load on PMPro's membership level admin page. */
	if ( 'memberships_page_pmpro-membershiplevels' !== $hook ) {
		return;
	}
	/* Only load when editing a specific level (not the levels list view). */
	if ( empty( $_REQUEST['edit'] ) ) {
		return;
	}

	wp_enqueue_style(
		'geoprice-admin',                              // Handle (unique identifier for this stylesheet).
		GEOPRICE_PMPRO_URL . 'assets/css/admin.css',   // URL to the CSS file.
		array(),                                       // Dependencies (none — standalone styles).
		GEOPRICE_PMPRO_VERSION                         // Version (for cache-busting on plugin updates).
	);
	wp_enqueue_script(
		'geoprice-admin',                              // Handle (unique identifier for this script).
		GEOPRICE_PMPRO_URL . 'assets/js/admin.js',    // URL to the JS file.
		array( 'jquery' ),                             // Dependencies (needs jQuery for DOM manipulation).
		GEOPRICE_PMPRO_VERSION,                        // Version (for cache-busting).
		true                                           // Load in footer (after DOM is ready).
	);
}
add_action( 'admin_enqueue_scripts', 'geoprice_admin_enqueue_scripts' );

/**
 * Render the per-country pricing fields on the level edit page.
 *
 * This is the main admin UI function. It outputs the "Geographic Pricing" section
 * with the country pricing table, show/hide buttons, and search filter.
 *
 * HOW DATA FLOWS:
 *   1. When the page loads, we read any existing prices from level meta.
 *   2. We render each country row with the saved prices (or empty fields).
 *   3. When the admin submits the form, PMPro processes its own fields first,
 *      then fires pmpro_save_membership_level, which triggers our save handler.
 *
 * SECTION PLACEMENT TECHNIQUE:
 *   We hook into `pmpro_membership_level_after_trial_settings` which fires
 *   INSIDE the Billing Details section's inner wrapper. To create our own
 *   standalone collapsible section (rather than being nested inside Billing
 *   Details), we use a standard PMPro add-on technique:
 *
 *   1. Close the current section's </div></div> wrappers (pmpro_section_inside
 *      and pmpro_section).
 *   2. Render our own complete pmpro_section with toggle button and inner content.
 *   3. Re-open <div><div> wrappers so PMPro's own closing </div></div> tags
 *      (which follow the hook) produce valid HTML.
 *
 *   This positions Geographic Pricing as a top-level collapsible section
 *   directly below Billing Details, matching PMPro's native UI.
 *
 * PMPRO HOOK USED:
 *   `pmpro_membership_level_after_trial_settings` receives the $level object
 *   as its only parameter. This object contains the level's current data
 *   (id, name, initial_payment, billing_amount, etc.) as loaded from the database.
 *   We use $level->id to look up our per-country prices from level meta.
 *
 * @param object $level The PMPro membership level object. Key properties:
 *                      - id: (int) Level ID. May be empty for new (unsaved) levels.
 *                      - name: (string) Level name.
 *                      - initial_payment: (string) Default initial payment in USD.
 *                      - billing_amount: (string) Default recurring amount in USD.
 * @return void
 */
function geoprice_level_pricing_fields( $level ) {
	$countries    = geoprice_get_all_countries();
	$top_codes    = geoprice_get_top_countries();
	$saved_prices = array();

	/*
	 * Load existing per-country prices from level meta.
	 * Only attempt this if the level has been saved (has an ID).
	 * New levels that haven't been saved yet won't have any meta.
	 */
	if ( ! empty( $level->id ) ) {
		$meta = get_pmpro_membership_level_meta( $level->id, 'geoprice_prices', true );
		if ( ! empty( $meta ) ) {
			$saved_prices = json_decode( $meta, true );
			if ( ! is_array( $saved_prices ) ) {
				$saved_prices = array();
			}
		}
	}

	/*
	 * Add our own nonce field to the form for CSRF protection.
	 * PMPro's form already has its own nonce, but we add a separate one
	 * specifically for our data so we can verify it independently in
	 * our save handler. This follows WordPress security best practices.
	 */
	wp_nonce_field( 'geoprice_save_prices', 'geoprice_nonce' );

	/*
	 * SECTION INJECTION TECHNIQUE:
	 *
	 * This hook fires INSIDE the Content Settings section's pmpro_section_inside div.
	 * PMPro's template will output </div></div> after this hook returns, closing
	 * the Content Settings section.
	 *
	 * To render our own top-level collapsible section, we:
	 *   1. Close the current section's wrappers (</div> pmpro_section_inside,
	 *      </div> pmpro_section).
	 *   2. Output our complete pmpro_section with its own toggle and inner content.
	 *   3. Re-open dummy <div><div> wrappers that PMPro's closing tags will close
	 *      harmlessly — producing valid HTML with no visible side effects.
	 *
	 * This is a well-established pattern used by PMPro add-ons (e.g., PMPro
	 * Variable Pricing, PMPro Sponsored Members) to inject standalone sections
	 * into the level edit page from "after_*_settings" hooks.
	 */
	?>
	<?php // Step 1: Close Billing Details section wrappers. ?>
	</div> <!-- close pmpro_section_inside (Billing Details) -->
	</div> <!-- close pmpro_section (Billing Details) -->

	<?php // Step 2: Render our own standalone pmpro_section. ?>
	<div id="geographic-pricing" class="pmpro_section" data-visibility="shown" data-activated="true">
		<div class="pmpro_section_toggle">
			<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
				<span class="dashicons dashicons-arrow-up-alt2"></span>
				<?php esc_html_e( 'Geographic Pricing', 'geoprice-for-pmpro' ); ?>
			</button>
		</div>
		<div class="pmpro_section_inside">
			<p class="description" style="margin-bottom: 1em;">
				<?php esc_html_e( 'Set custom USD prices per country. Leave blank to use the default prices, set in the "Billing Details" area above. Visitors will see amounts converted to their local currency.', 'geoprice-for-pmpro' ); ?>
			</p>

			<!--
				The pricing table. Each row represents one country with two price fields.
				The table uses PMPro's "widefat" class for consistent admin styling,
				plus our own "geoprice-country-table" class for custom styling.
			-->
			<table class="geoprice-country-table widefat" id="geoprice-country-table">
				<thead>
					<tr>
						<th class="geoprice-col-country"><?php esc_html_e( 'Country', 'geoprice-for-pmpro' ); ?></th>
						<th class="geoprice-col-currency"><?php esc_html_e( 'Local Currency', 'geoprice-for-pmpro' ); ?></th>
						<th class="geoprice-col-price"><?php esc_html_e( 'Initial Payment (USD)', 'geoprice-for-pmpro' ); ?></th>
						<th class="geoprice-col-price"><?php esc_html_e( 'Renewal Amount (USD)', 'geoprice-for-pmpro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					/*
					 * RENDERING ORDER:
					 * 1. Top 20 countries first (always visible) — these are the most
					 *    commonly configured and most likely to be relevant.
					 * 2. All remaining countries (hidden by default, revealed by "Show All").
					 *
					 * Countries in the "extra" group that have saved prices are ALSO
					 * shown initially (see geoprice_render_country_row's $style logic)
					 * so admins can always see what they've configured.
					 */

					// Render top 20 countries (always visible, marked as geoprice-top-country).
					foreach ( $top_codes as $code ) {
						if ( isset( $countries[ $code ] ) ) {
							geoprice_render_country_row( $code, $countries[ $code ], $saved_prices, false );
						}
					}

					// Render remaining countries (hidden by default, marked as geoprice-extra-country).
					foreach ( $countries as $code => $data ) {
						if ( in_array( $code, $top_codes, true ) ) {
							continue; // Already rendered above.
						}
						geoprice_render_country_row( $code, $data, $saved_prices, true );
					}
					?>
				</tbody>
			</table>

			<!--
				Toggle buttons and search filter below the table.
				- "Show All Countries" reveals the hidden geoprice-extra-country rows.
				- "Show Top 20 Only" hides them again (keeping rows with prices visible).
				- The search filter searches ALL countries by name regardless of visibility state.
				These are handled by admin.js.
			-->
			<p class="geoprice-toggle-wrap">
				<button type="button" class="button button-secondary" id="geoprice-show-more">
					<?php esc_html_e( 'Show All Countries', 'geoprice-for-pmpro' ); ?>
				</button>
				<button type="button" class="button button-secondary" id="geoprice-hide-more" style="display:none;">
					<?php esc_html_e( 'Show Top 20 Only', 'geoprice-for-pmpro' ); ?>
				</button>
				<span class="geoprice-filter-wrap">
					<input type="text" id="geoprice-filter" placeholder="<?php esc_attr_e( 'Filter countries...', 'geoprice-for-pmpro' ); ?>" class="regular-text" />
				</span>
			</p>
		</div> <!-- end pmpro_section_inside (Geographic Pricing) -->
	</div> <!-- end pmpro_section (Geographic Pricing) -->

	<?php
	/*
	 * Step 3: Re-open dummy wrappers.
	 *
	 * After this function returns, PMPro's template outputs:
	 *   </div> <!-- end pmpro_section_inside -->
	 *   </div> <!-- end pmpro_section -->
	 *
	 * Those closing tags originally belong to Content Settings, but we already
	 * closed them in Step 1. We must re-open matching <div> tags here so
	 * PMPro's closing tags produce valid HTML. These empty wrappers are
	 * invisible — they contain no content and collapse to zero height.
	 */
	?>
	<div class="pmpro_section" style="display:none;">
	<div class="pmpro_section_inside">
	<?php
}
add_action( 'pmpro_membership_level_after_trial_settings', 'geoprice_level_pricing_fields' );

/**
 * Render a single country row in the pricing table.
 *
 * Each row contains:
 *   - Country name and ISO code (display only).
 *   - Local currency code (display only, informational for the admin).
 *   - Initial Payment input field (USD amount, or empty for "use default").
 *   - Billing Amount input field (USD amount, or empty for "use default").
 *
 * VISIBILITY LOGIC:
 *   - Top 20 countries ($hidden=false): Always visible, class "geoprice-top-country".
 *   - Other countries ($hidden=true): Hidden by default UNLESS they have a saved price.
 *     This ensures the admin always sees countries they've configured prices for,
 *     even without clicking "Show All Countries."
 *
 * DATA ATTRIBUTES:
 *   - data-country: Lowercase country name, used by admin.js for the search filter.
 *     The filter does a simple substring match: typing "can" matches "canada".
 *
 * INPUT VALIDATION:
 *   - pattern="[0-9]*\.?[0-9]*" provides browser-level validation for decimal numbers.
 *   - inputmode="decimal" shows a numeric keyboard on mobile devices.
 *   - Server-side validation in geoprice_save_level_pricing() is the authoritative check.
 *
 * @param string $code         ISO 3166-1 alpha-2 country code (e.g., 'CA', 'MX').
 * @param array  $data         Country data array: { 'name' => string, 'currency' => string }.
 * @param array  $saved_prices All saved prices for this level: { 'CA' => {...}, 'MX' => {...} }.
 * @param bool   $hidden       Whether this country is in the "extra" (non-top-20) group.
 * @return void
 */
function geoprice_render_country_row( $code, $data, $saved_prices, $hidden ) {
	/* Extract saved values for this country (empty string if not set). */
	$initial = isset( $saved_prices[ $code ]['initial_payment'] ) ? $saved_prices[ $code ]['initial_payment'] : '';
	$billing = isset( $saved_prices[ $code ]['billing_amount'] ) ? $saved_prices[ $code ]['billing_amount'] : '';

	/* A row "has a price" if either field has a value — used for highlighting and visibility. */
	$has_price = '' !== $initial || '' !== $billing;

	/* CSS class determines whether the row is top-20 (always shown) or extra (togglable). */
	$row_class = $hidden ? 'geoprice-extra-country' : 'geoprice-top-country';

	/*
	 * Inline style: extra countries are hidden by default, BUT if the row has
	 * a saved price, it's shown regardless. This way, if an admin set a price
	 * for, say, Singapore (not top 20), they'll still see it without having
	 * to expand the full list.
	 */
	$style = ( $hidden && ! $has_price ) ? 'display:none;' : '';
	?>
	<tr class="<?php echo esc_attr( $row_class ); ?>" data-country="<?php echo esc_attr( strtolower( $data['name'] ) ); ?>" style="<?php echo esc_attr( $style ); ?>">
		<td class="geoprice-col-country">
			<strong><?php echo esc_html( $data['name'] ); ?></strong>
			<span class="geoprice-country-code">(<?php echo esc_html( $code ); ?>)</span>
		</td>
		<td class="geoprice-col-currency">
			<?php echo esc_html( $data['currency'] ); ?>
		</td>
		<td class="geoprice-col-price">
			<!-- "$" prefix is visual-only — the input value is the numeric amount. -->
			<span class="geoprice-dollar-prefix">$</span>
			<input type="text"
				name="geoprice_prices[<?php echo esc_attr( $code ); ?>][initial_payment]"
				value="<?php echo esc_attr( $initial ); ?>"
				placeholder="<?php esc_attr_e( 'default', 'geoprice-for-pmpro' ); ?>"
				class="small-text geoprice-price-input"
				pattern="[0-9]*\.?[0-9]*"
				inputmode="decimal" />
		</td>
		<td class="geoprice-col-price">
			<span class="geoprice-dollar-prefix">$</span>
			<input type="text"
				name="geoprice_prices[<?php echo esc_attr( $code ); ?>][billing_amount]"
				value="<?php echo esc_attr( $billing ); ?>"
				placeholder="<?php esc_attr_e( 'default', 'geoprice-for-pmpro' ); ?>"
				class="small-text geoprice-price-input"
				pattern="[0-9]*\.?[0-9]*"
				inputmode="decimal" />
		</td>
	</tr>
	<?php
}

/**
 * Save per-country pricing data when a membership level is saved.
 *
 * WHEN THIS FIRES:
 *   PMPro fires `pmpro_save_membership_level` after it has saved the level's
 *   own data (name, description, initial_payment, billing_amount, etc.) to
 *   the pmpro_membership_levels table. At this point, the level ID ($level_id)
 *   is guaranteed to exist in the database.
 *
 * WHAT THIS DOES:
 *   1. Verifies our nonce (CSRF protection).
 *   2. Checks user capability.
 *   3. Iterates through the submitted geoprice_prices array.
 *   4. Validates each country code against the known list (rejects invalid codes).
 *   5. Validates each price as numeric (rejects non-numeric strings).
 *   6. Normalizes prices to 2-decimal format (e.g., "29" → "29.00").
 *   7. Stores only countries that have at least one price set (strips empty entries).
 *   8. Saves the final array as JSON in level meta.
 *
 * EMPTY FIELDS:
 *   If both initial_payment and billing_amount are empty for a country, that
 *   country is excluded from the saved data. This means: "use the level's
 *   default price for this country" — no custom pricing.
 *
 * STORAGE FORMAT:
 *   The prices array is serialized as JSON and stored in a single level meta entry:
 *     meta_key: 'geoprice_prices'
 *     meta_value: '{"CA":{"initial_payment":"29.00","billing_amount":"29.00"},...}'
 *
 *   Using JSON (rather than separate meta entries per country) keeps the database
 *   clean — one row per level instead of potentially 195 rows per level.
 *
 * @param int $level_id The ID of the membership level that was just saved.
 * @return void
 */
function geoprice_save_level_pricing( $level_id ) {
	/*
	 * Security check 1: Verify our nonce.
	 * The nonce was added to the form by wp_nonce_field() in geoprice_level_pricing_fields().
	 * If the nonce is missing or invalid, this is either a direct POST (not from our form)
	 * or a CSRF attack. Bail silently.
	 */
	if ( ! isset( $_POST['geoprice_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['geoprice_nonce'] ) ), 'geoprice_save_prices' ) ) {
		return;
	}

	/*
	 * Security check 2: Verify user capability.
	 * Even though PMPro already checks this in its own save handler, we check
	 * again here as defense-in-depth. Our nonce alone doesn't guarantee that
	 * the user has the right permissions.
	 */
	if ( ! current_user_can( 'pmpro_membershiplevels' ) ) {
		return;
	}

	$prices = array();

	if ( ! empty( $_POST['geoprice_prices'] ) && is_array( $_POST['geoprice_prices'] ) ) {
		$countries = geoprice_get_all_countries();

		/*
		 * Iterate through each submitted country.
		 * The form submits ALL ~195 countries (even hidden ones), but most
		 * will have empty values. We only store countries with actual prices.
		 */
		foreach ( $_POST['geoprice_prices'] as $country_code => $values ) {
			$country_code = sanitize_text_field( $country_code );

			/*
			 * Validate the country code against our known list.
			 * This prevents injection of arbitrary keys into our JSON data.
			 */
			if ( ! isset( $countries[ $country_code ] ) ) {
				continue;
			}

			$initial = isset( $values['initial_payment'] ) ? sanitize_text_field( $values['initial_payment'] ) : '';
			$billing = isset( $values['billing_amount'] ) ? sanitize_text_field( $values['billing_amount'] ) : '';

			/* Only process if at least one field has a value. */
			if ( '' !== $initial || '' !== $billing ) {
				$entry = array();

				/*
				 * Validate each price as numeric and normalize to 2-decimal format.
				 * is_numeric() accepts integers, floats, and numeric strings.
				 * number_format() normalizes to exactly 2 decimal places:
				 *   "29" → "29.00", "19.5" → "19.50", "9.99" → "9.99".
				 * Non-numeric values (e.g., "abc") are silently dropped.
				 */
				if ( '' !== $initial && is_numeric( $initial ) ) {
					/*
					 * Reject negative price values.
					 * Negative numbers like "-10.00" pass is_numeric() but could
					 * create credits instead of charges if stored.
					 * abs() converts negatives to positive (preserving admin intent),
					 * and > 0 check excludes zero-amount prices.
					 */
					$initial_val = abs( (float) $initial );
					if ( $initial_val > 0 ) {
						$entry['initial_payment'] = number_format( $initial_val, 2, '.', '' );
					}
				}
				if ( '' !== $billing && is_numeric( $billing ) ) {
					/* Same negative-value protection as above. */
					$billing_val = abs( (float) $billing );
					if ( $billing_val > 0 ) {
						$entry['billing_amount'] = number_format( $billing_val, 2, '.', '' );
					}
				}

				/* Only store the entry if at least one valid price was parsed. */
				if ( ! empty( $entry ) ) {
					$prices[ $country_code ] = $entry;
				}
			}
		}
	}

	/*
	 * Save the prices as a JSON string in level meta.
	 *
	 * We ALWAYS save (even if $prices is empty) to clear out any previously
	 * saved prices if the admin removed all country-specific pricing. An empty
	 * JSON object '{}' effectively means "no custom pricing for any country."
	 *
	 * update_pmpro_membership_level_meta() is PMPro's wrapper around WordPress's
	 * update_metadata() function. It stores data in the wp_pmpro_membership_levelmeta
	 * table with the given level ID and meta key.
	 */
	update_pmpro_membership_level_meta( $level_id, 'geoprice_prices', wp_json_encode( $prices ) );
}
add_action( 'pmpro_save_membership_level', 'geoprice_save_level_pricing' );
