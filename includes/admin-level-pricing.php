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
 * ADMIN UI DESIGN:
 *   - By default, only US, CA, and MX are shown in the pricing table.
 *   - An "+ Add Country" button opens a modal popup with all ~195 countries.
 *   - The modal supports search/filter, sorting (alphabetical, population),
 *     and grouping by continent.
 *   - Each country in the modal has an "+ Add" button that instantly adds it
 *     to the pricing table (no page refresh).
 *   - Each country row in the table has a "Remove" button to remove it (no
 *     page refresh). Removing clears that country's prices on next save.
 *   - Countries with saved prices are always shown in the table on page load.
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
 * LOCALIZED DATA:
 *   We pass the full country dataset to JavaScript via wp_localize_script().
 *   This gives the modal popup access to all countries, their continents,
 *   populations, and currencies for client-side sorting/filtering/grouping.
 *
 * @param string $hook The WordPress admin page hook suffix.
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
		'geoprice-admin',
		GEOPRICE_PMPRO_URL . 'assets/css/admin.css',
		array(),
		GEOPRICE_PMPRO_VERSION
	);
	wp_enqueue_script(
		'geoprice-admin',
		GEOPRICE_PMPRO_URL . 'assets/js/admin.js',
		array( 'jquery' ),
		GEOPRICE_PMPRO_VERSION,
		true
	);

	/*
	 * Pass the full country dataset to JavaScript so the modal popup can
	 * render, sort, filter, and group countries entirely client-side.
	 * This avoids AJAX round-trips for what is static reference data.
	 */
	$countries  = geoprice_get_all_countries();
	$js_countries = array();
	foreach ( $countries as $code => $data ) {
		$js_countries[ $code ] = array(
			'name'       => $data['name'],
			'currency'   => $data['currency'],
			'continent'  => $data['continent'],
			'population' => $data['population'],
		);
	}
	wp_localize_script( 'geoprice-admin', 'geoPriceData', array(
		'countries' => $js_countries,
	) );
}
add_action( 'admin_enqueue_scripts', 'geoprice_admin_enqueue_scripts' );

/**
 * Render the per-country pricing fields on the level edit page.
 *
 * This is the main admin UI function. It outputs the "Geographic Pricing" section
 * with:
 *   - A compact pricing table showing only active countries (defaults + saved).
 *   - An "+ Add Country" button that opens a modal for adding more countries.
 *   - A hidden modal popup with search, sort, and group-by-continent features.
 *
 * SECTION PLACEMENT TECHNIQUE:
 *   We hook into `pmpro_membership_level_after_trial_settings` which fires
 *   INSIDE the Billing Details section. We close PMPro's wrappers, render our
 *   own section, then re-open dummy wrappers for valid HTML.
 *
 * @param object $level The PMPro membership level object.
 * @return void
 */
function geoprice_level_pricing_fields( $level ) {
	$countries      = geoprice_get_all_countries();
	$default_codes  = geoprice_get_default_countries();
	$saved_prices   = array();

	/*
	 * Load existing per-country prices from level meta.
	 * Only attempt this if the level has been saved (has an ID).
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
	 * Determine which countries to show in the table on page load:
	 *   1. Default countries (US, CA, MX) — always shown.
	 *   2. Any country with saved pricing data — always shown.
	 * Merge and deduplicate.
	 */
	$active_codes = array_unique( array_merge( $default_codes, array_keys( $saved_prices ) ) );

	wp_nonce_field( 'geoprice_save_prices', 'geoprice_nonce' );
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

			<table class="geoprice-country-table widefat" id="geoprice-country-table">
				<thead>
					<tr>
						<th class="geoprice-col-country"><?php esc_html_e( 'Country', 'geoprice-for-pmpro' ); ?></th>
						<th class="geoprice-col-currency"><?php esc_html_e( 'Local Currency', 'geoprice-for-pmpro' ); ?></th>
						<th class="geoprice-col-price"><?php esc_html_e( 'Initial Payment (USD)', 'geoprice-for-pmpro' ); ?></th>
						<th class="geoprice-col-price"><?php esc_html_e( 'Renewal Amount (USD)', 'geoprice-for-pmpro' ); ?></th>
						<th class="geoprice-col-actions">&nbsp;</th>
					</tr>
				</thead>
				<tbody id="geoprice-country-tbody">
					<?php
					/*
					 * Render only the active countries (defaults + saved).
					 * The JS will handle adding more rows dynamically via the modal.
					 */
					foreach ( $active_codes as $code ) {
						if ( isset( $countries[ $code ] ) ) {
							geoprice_render_country_row( $code, $countries[ $code ], $saved_prices );
						}
					}
					?>
				</tbody>
			</table>

			<p class="geoprice-actions-wrap">
				<button type="button" class="button button-secondary" id="geoprice-add-country-btn">
					<span class="dashicons dashicons-plus-alt2" style="vertical-align: middle; margin-top: -2px;"></span>
					<?php esc_html_e( 'Add Country', 'geoprice-for-pmpro' ); ?>
				</button>
			</p>

			<!--
				Country picker modal.
				Hidden by default; shown when "+ Add Country" is clicked.
				The list inside is rendered dynamically by admin.js using
				country data passed via wp_localize_script().
			-->
			<div id="geoprice-modal-overlay" class="geoprice-modal-overlay" style="display:none;">
				<div class="geoprice-modal">
					<div class="geoprice-modal-header">
						<h3><?php echo esc_html__( 'Add Country', 'geoprice-for-pmpro' ); ?></h3>
						<button type="button" class="geoprice-modal-close" aria-label="<?php esc_attr_e( 'Close', 'geoprice-for-pmpro' ); ?>">&times;</button>
					</div>
					<div class="geoprice-modal-controls">
						<input type="text"
							id="geoprice-modal-search"
							placeholder="&#x1F50D; <?php esc_attr_e( 'Search countries...', 'geoprice-for-pmpro' ); ?>"
							autocomplete="off" />
						<select id="geoprice-modal-sort">
							<option value="alpha"><?php esc_html_e( 'Sort: A → Z', 'geoprice-for-pmpro' ); ?></option>
							<option value="population"><?php esc_html_e( 'Sort: Population', 'geoprice-for-pmpro' ); ?></option>
						</select>
						<label class="geoprice-modal-group-label">
							<input type="checkbox" id="geoprice-modal-group" />
							<?php esc_html_e( 'Group by Continent', 'geoprice-for-pmpro' ); ?>
						</label>
					</div>
					<div class="geoprice-modal-list" id="geoprice-modal-list">
						<!-- Populated dynamically by admin.js -->
					</div>
				</div>
			</div>

		</div> <!-- end pmpro_section_inside (Geographic Pricing) -->
	</div> <!-- end pmpro_section (Geographic Pricing) -->

	<?php
	/*
	 * Step 3: Re-open dummy wrappers so PMPro's closing </div></div> tags
	 * (which follow this hook) produce valid HTML.
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
 *   - Renewal Amount input field (USD amount, or empty for "use default").
 *   - Remove button to remove the country from the table.
 *
 * The row's data-code attribute is used by admin.js to identify which country
 * this row represents (for duplicate prevention and removal).
 *
 * @param string $code         ISO 3166-1 alpha-2 country code (e.g., 'CA', 'MX').
 * @param array  $data         Country data array with 'name', 'currency', etc.
 * @param array  $saved_prices All saved prices for this level.
 * @return void
 */
function geoprice_render_country_row( $code, $data, $saved_prices ) {
	$initial = isset( $saved_prices[ $code ]['initial_payment'] ) ? $saved_prices[ $code ]['initial_payment'] : '';
	$billing = isset( $saved_prices[ $code ]['billing_amount'] ) ? $saved_prices[ $code ]['billing_amount'] : '';
	?>
	<tr data-code="<?php echo esc_attr( $code ); ?>">
		<td class="geoprice-col-country">
			<strong><?php echo esc_html( $data['name'] ); ?></strong>
			<span class="geoprice-country-code">(<?php echo esc_html( $code ); ?>)</span>
		</td>
		<td class="geoprice-col-currency">
			<?php echo esc_html( $data['currency'] ); ?>
		</td>
		<td class="geoprice-col-price">
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
		<td class="geoprice-col-actions">
			<button type="button" class="button button-link-delete geoprice-remove-btn" title="<?php esc_attr_e( 'Remove', 'geoprice-for-pmpro' ); ?>">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</td>
	</tr>
	<?php
}

/**
 * Save per-country pricing data when a membership level is saved.
 *
 * WHEN THIS FIRES:
 *   PMPro fires `pmpro_save_membership_level` after it has saved the level's
 *   own data to the pmpro_membership_levels table. The level ID ($level_id)
 *   is guaranteed to exist at this point.
 *
 * WHAT THIS DOES:
 *   1. Verifies our nonce (CSRF protection).
 *   2. Checks user capability.
 *   3. Iterates through the submitted geoprice_prices array.
 *   4. Validates each country code against the known list.
 *   5. Validates each price as numeric, rejects negatives.
 *   6. Normalizes prices to 2-decimal format.
 *   7. Saves the final array as JSON in level meta.
 *
 * NOTE: Only countries with rows in the table (added via the modal or defaults)
 * will have form inputs submitted. Countries that were "removed" have no inputs
 * in the DOM, so they won't appear in $_POST and their prices are effectively
 * deleted on save.
 *
 * @param int $level_id The ID of the membership level that was just saved.
 * @return void
 */
function geoprice_save_level_pricing( $level_id ) {
	if ( ! isset( $_POST['geoprice_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['geoprice_nonce'] ) ), 'geoprice_save_prices' ) ) {
		return;
	}

	if ( ! current_user_can( 'pmpro_membershiplevels' ) ) {
		return;
	}

	$prices = array();

	if ( ! empty( $_POST['geoprice_prices'] ) && is_array( $_POST['geoprice_prices'] ) ) {
		$countries = geoprice_get_all_countries();

		foreach ( $_POST['geoprice_prices'] as $country_code => $values ) {
			$country_code = sanitize_text_field( $country_code );

			if ( ! isset( $countries[ $country_code ] ) ) {
				continue;
			}

			$initial = isset( $values['initial_payment'] ) ? sanitize_text_field( $values['initial_payment'] ) : '';
			$billing = isset( $values['billing_amount'] ) ? sanitize_text_field( $values['billing_amount'] ) : '';

			if ( '' !== $initial || '' !== $billing ) {
				$entry = array();

				if ( '' !== $initial && is_numeric( $initial ) ) {
					$initial_val = abs( (float) $initial );
					if ( $initial_val > 0 ) {
						$entry['initial_payment'] = number_format( $initial_val, 2, '.', '' );
					}
				}
				if ( '' !== $billing && is_numeric( $billing ) ) {
					$billing_val = abs( (float) $billing );
					if ( $billing_val > 0 ) {
						$entry['billing_amount'] = number_format( $billing_val, 2, '.', '' );
					}
				}

				if ( ! empty( $entry ) ) {
					$prices[ $country_code ] = $entry;
				}
			}
		}
	}

	update_pmpro_membership_level_meta( $level_id, 'geoprice_prices', wp_json_encode( $prices ) );
}
add_action( 'pmpro_save_membership_level', 'geoprice_save_level_pricing' );
