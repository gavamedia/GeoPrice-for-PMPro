<?php
/**
 * Purchasing Power Parity (PPP) data management for GeoPrice for PMPro.
 *
 * Fetches GDP per capita (PPP-adjusted) data from the World Bank Open Data API,
 * computes purchasing power multipliers relative to the United States, and caches
 * the results in wp_options for use in the admin pricing table.
 *
 * HOW PPP PRICING WORKS:
 *   GDP per capita (PPP) measures the average economic output per person, adjusted
 *   for differences in the cost of goods between countries. By comparing each
 *   country's value to the US baseline, we get a ratio that indicates relative
 *   purchasing power. A square root curve is applied to moderate extreme differences
 *   (without it, the poorest countries would show multipliers under 0.10×, which
 *   is typically too aggressive for digital products).
 *
 *   Example (with square root dampening):
 *     - US GDP/capita PPP: ~$80,000 → ratio 1.00 → multiplier 1.00×
 *     - Canada: ~$57,000 → raw 0.71 → multiplier 0.84×
 *     - Mexico: ~$22,000 → raw 0.28 → multiplier 0.53×
 *     - India:  ~$9,000  → raw 0.11 → multiplier 0.33×
 *
 * DATA SOURCE:
 *   World Bank Open Data API (https://data.worldbank.org)
 *   Indicator: NY.GDP.PCAP.PP.CD — GDP per capita, PPP (current international $)
 *   License: Creative Commons Attribution 4.0 (CC BY 4.0)
 *   Update frequency: World Bank publishes annual data, typically available with
 *   a 1-2 year lag (e.g., 2024 data available mid-2025).
 *
 * REFRESH STRATEGY:
 *   A daily WordPress cron event ('geoprice_pmpro_refresh_ppp') calls the
 *   geoprice_maybe_refresh_ppp() function. This function only hits the API
 *   if the cached data is older than 30 days, keeping API usage minimal
 *   while ensuring reasonably fresh data. A manual "Refresh PPP Data Now"
 *   button is available on the GeoPrice settings page.
 *
 * DATA STORAGE:
 *   - geoprice_ppp_ratios   (array)  Raw ratios keyed by ISO 3166-1 alpha-2 code.
 *   - geoprice_ppp_updated  (int)    Unix timestamp of last successful fetch.
 *   - geoprice_ppp_data_year (int)   The most recent data year from the World Bank.
 *
 * @copyright 2024-2026 GAVAMEDIA Corporation (https://gavamedia.com)
 * @license   GPL-2.0-or-later
 * @package   GeoPrice_For_PMPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetch PPP data from the World Bank API and compute ratios.
 *
 * Queries 5 years of GDP per capita (PPP) data and takes the most recent
 * non-null value for each country to handle publication lag.
 *
 * @return bool True on success, false on failure.
 */
function geoprice_fetch_ppp_data() {
	$current_year = (int) gmdate( 'Y' );
	$start_year   = $current_year - 4;

	/*
	 * Fetch from World Bank API V2.
	 *   - country/all: includes all countries (aggregates are filtered later).
	 *   - indicator/NY.GDP.PCAP.PP.CD: GDP per capita, PPP (current int'l $).
	 *   - per_page=2000: ensures all entries fit in one page (~270 entities × 5 years).
	 *   - date=YYYY:YYYY: date range to get multiple years for freshness.
	 *   - format=json: JSON response format.
	 */
	$url = sprintf(
		'https://api.worldbank.org/v2/country/all/indicator/NY.GDP.PCAP.PP.CD?format=json&per_page=2000&date=%d:%d',
		$start_year,
		$current_year
	);

	$response = wp_remote_get( $url, array( 'timeout' => 30 ) );

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return false;
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	/*
	 * World Bank API V2 returns a 2-element array:
	 *   [0] = pagination metadata (page, pages, per_page, total)
	 *   [1] = array of data entries
	 */
	if ( ! is_array( $data ) || count( $data ) < 2 || ! is_array( $data[1] ) ) {
		return false;
	}

	/*
	 * Group by country ISO2 code, keeping only the most recent year's value.
	 * The country.id field in the World Bank API uses ISO 3166-1 alpha-2 codes
	 * for individual countries (e.g., "US", "CA", "IN"). Aggregate regions
	 * use codes like "1W", "EU", "OE" which won't match our country list.
	 */
	$country_gdp = array();

	foreach ( $data[1] as $entry ) {
		if ( empty( $entry['country']['id'] ) || null === $entry['value'] ) {
			continue;
		}

		$iso2  = $entry['country']['id'];
		$year  = (int) $entry['date'];
		$value = (float) $entry['value'];

		if ( $value <= 0 ) {
			continue;
		}

		if ( ! isset( $country_gdp[ $iso2 ] ) || $year > $country_gdp[ $iso2 ]['year'] ) {
			$country_gdp[ $iso2 ] = array(
				'year'  => $year,
				'value' => $value,
			);
		}
	}

	/* US GDP per capita is the baseline. Without it, we can't compute ratios. */
	if ( ! isset( $country_gdp['US'] ) || $country_gdp['US']['value'] <= 0 ) {
		return false;
	}

	$us_gdp   = $country_gdp['US']['value'];
	$data_year = $country_gdp['US']['year'];

	/*
	 * Compute the raw PPP ratio for each country: country_value / US_value.
	 * Cap at 1.5 — no country should suggest more than 150% of the US price.
	 * Aggregate region codes are naturally filtered out because they won't
	 * exist in our geoprice_get_all_countries() list.
	 */
	$ratios = array();
	foreach ( $country_gdp as $iso2 => $info ) {
		$raw_ratio       = $info['value'] / $us_gdp;
		$ratios[ $iso2 ] = min( round( $raw_ratio, 4 ), 1.5 );
	}

	/*
	 * Store raw ratios — square root dampening is applied at read time
	 * so the dampening method can be changed without re-fetching data.
	 * autoload=false because this data is only needed on admin pages.
	 */
	update_option( 'geoprice_ppp_ratios', $ratios, false );
	update_option( 'geoprice_ppp_updated', time(), false );
	update_option( 'geoprice_ppp_data_year', $data_year, false );

	return true;
}

/**
 * Get cached raw PPP ratios (country_gdp / US_gdp).
 *
 * @return array Associative array keyed by ISO2 country code, values are raw ratios.
 */
function geoprice_get_ppp_ratios() {
	return get_option( 'geoprice_ppp_ratios', array() );
}

/**
 * Get the dampened PPP multiplier for a specific country.
 *
 * Applies square root dampening to the raw ratio. This moderates extreme
 * differences: raw 0.11 (India) becomes 0.33× instead of 0.11×.
 *
 * @param string $country_code ISO 3166-1 alpha-2 country code.
 * @return float|null The dampened multiplier, or null if no data available.
 */
function geoprice_get_ppp_multiplier( $country_code ) {
	$ratios = geoprice_get_ppp_ratios();
	if ( ! isset( $ratios[ $country_code ] ) || $ratios[ $country_code ] <= 0 ) {
		return null;
	}
	return round( sqrt( $ratios[ $country_code ] ), 2 );
}

/**
 * Get PPP multipliers for all countries that have data.
 *
 * Returns dampened (square root) multipliers ready for use in pricing suggestions.
 * Only includes countries that exist in our country list.
 *
 * @return array Associative array keyed by ISO2 code, values are dampened multipliers.
 */
function geoprice_get_all_ppp_multipliers() {
	$ratios     = geoprice_get_ppp_ratios();
	$countries  = geoprice_get_all_countries();
	$multipliers = array();

	foreach ( $countries as $code => $data ) {
		if ( isset( $ratios[ $code ] ) && $ratios[ $code ] > 0 ) {
			$multipliers[ $code ] = round( sqrt( $ratios[ $code ] ), 2 );
		}
	}

	return $multipliers;
}

/**
 * Maybe refresh PPP data (called by daily cron).
 *
 * Only actually fetches from the World Bank API if the cached data is older
 * than 30 days. This keeps API usage minimal (at most ~12 requests/year)
 * while ensuring data stays reasonably current.
 *
 * @return void
 */
function geoprice_maybe_refresh_ppp() {
	$last_updated = (int) get_option( 'geoprice_ppp_updated', 0 );

	/* Skip if data was fetched within the last 30 days. */
	if ( ( time() - $last_updated ) < 30 * DAY_IN_SECONDS ) {
		return;
	}

	geoprice_fetch_ppp_data();
}
add_action( 'geoprice_pmpro_refresh_ppp', 'geoprice_maybe_refresh_ppp' );
