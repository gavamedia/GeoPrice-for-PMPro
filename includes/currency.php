<?php
/**
 * Currency exchange rate management for GeoPrice for PMPro.
 *
 * This file handles fetching, caching, and using exchange rates to convert
 * USD prices into local currencies for display to visitors.
 *
 * ARCHITECTURE:
 *   Exchange rates are fetched from an external API and stored in wp_options
 *   as a flat associative array: { "CAD": 1.35, "EUR": 0.92, "GBP": 0.79, ... }.
 *   All rates are relative to USD (i.e., 1 USD = X units of the target currency).
 *
 * REFRESH STRATEGY:
 *   - Rates are refreshed once daily via a wp_cron job ('geoprice_pmpro_refresh_rates')
 *     that was scheduled on plugin activation (see geoprice-for-pmpro.php).
 *   - A manual "Refresh Rates Now" button is available in the settings page
 *     (see admin-settings.php) for immediate updates.
 *   - If no rates are cached yet (first run, or option was deleted), the first
 *     call to geoprice_get_exchange_rates() triggers a synchronous fetch.
 *
 * WHY DAILY REFRESH IS SUFFICIENT:
 *   This plugin displays "approximately" converted prices for informational
 *   purposes. The actual charge is in USD (a fixed country-specific USD amount).
 *   Daily rate updates provide a reasonable approximation while staying well
 *   within free API tier limits (e.g., ExchangeRate-API allows 1,500 req/month).
 *
 * SUPPORTED PROVIDERS:
 *   1. ExchangeRate-API (default): Free, no API key needed. Uses open.er-api.com.
 *      Returns 160+ currency rates. Updates daily on their end.
 *   2. Open Exchange Rates: Requires a free App ID (signup at openexchangerates.org).
 *      Returns 170+ currency rates. Free tier allows 1,000 req/month.
 *
 * DATA FLOW:
 *   geoprice_fetch_exchange_rates()        — Called by wp_cron or manual refresh.
 *     └─ geoprice_fetch_from_*()           — Makes the HTTP request to the API.
 *          └─ update_option(rates + time)  — Stores the result in wp_options.
 *
 *   geoprice_convert_usd_to_currency()     — Called by frontend.php to convert
 *     └─ geoprice_get_exchange_rates()     —   prices for display.
 *          └─ get_option(rates)            — Reads cached rates from wp_options.
 *
 *   geoprice_format_price()                — Called by frontend.php to format
 *                                             the converted amount with the
 *                                             correct currency symbol and decimals.
 *
 * @copyright 2024-2026 GAVAMEDIA Corporation (https://gavamedia.com)
 * @license   GPL-2.0-or-later
 * @package   GeoPrice_For_PMPro
 */

defined( 'ABSPATH' ) || exit;

/*
 * --------------------------------------------------------------------------
 * Cron event handler registration.
 * --------------------------------------------------------------------------
 * This connects the 'geoprice_pmpro_refresh_rates' cron event (scheduled in
 * geoprice-for-pmpro.php on activation) to the geoprice_fetch_exchange_rates()
 * function. When wp-cron fires this event (once daily), it calls our fetch
 * function to pull fresh rates from the configured API.
 */
add_action( 'geoprice_pmpro_refresh_rates', 'geoprice_fetch_exchange_rates' );

/**
 * Fetch exchange rates from the configured provider and store them.
 *
 * This function is called in two contexts:
 *   1. By the daily wp_cron job (automatic, in the background).
 *   2. By the "Refresh Rates Now" button in admin settings (manual, synchronous).
 *
 * WHAT IT DOES:
 *   1. Reads the 'geoprice_rate_provider' option to determine which API to call.
 *   2. Calls the appropriate fetch function to get rates from the external API.
 *   3. If successful, saves the rates array and a timestamp to wp_options.
 *
 * OPTION STORAGE:
 *   - 'geoprice_exchange_rates': Array of { currency_code => float_rate }.
 *     Example: { "CAD": 1.3512, "EUR": 0.9234, "JPY": 149.87 }
 *     The third parameter (false) to update_option disables autoloading,
 *     meaning this data isn't loaded on every WordPress page load — only
 *     when explicitly requested via get_option(). This is a performance
 *     optimization since rates are only needed on pages with pricing display.
 *   - 'geoprice_rates_updated': Unix timestamp of the last successful fetch.
 *     Used by the settings page to show "Last updated: March 25, 2026 at 3:42 PM".
 *
 * @return bool True if rates were successfully fetched and stored, false on failure.
 */
function geoprice_fetch_exchange_rates() {
	$provider = get_option( 'geoprice_rate_provider', 'exchangerate-api' );
	$rates    = array();

	switch ( $provider ) {
		case 'openexchangerates':
			$rates = geoprice_fetch_from_openexchangerates();
			break;

		case 'exchangerate-api':
		default:
			$rates = geoprice_fetch_from_exchangerate_api();
			break;
	}

	if ( ! empty( $rates ) ) {
		/*
		 * Validate exchange rate integrity before storing.
		 *
		 * Without validation, a compromised API response (from a man-in-the-middle
		 * attack, API bug, or corrupted response) could store wildly incorrect
		 * rates. For example, a rate of 0.0001 for EUR would make a $29 membership
		 * show as "€0.00", and a rate of 999999 would show "€28,999,971.00".
		 *
		 * VALIDATION RULES:
		 *   1. Each rate must be a positive number (> 0). Zero or negative rates
		 *      are mathematically invalid for currency conversion.
		 *   2. Each rate must be below 1,000,000. The most extreme real-world
		 *      exchange rate is about 25,000 (1 USD = ~25,000 VND). A cap of
		 *      1 million provides generous headroom for hyperinflationary currencies
		 *      while still catching clearly corrupted data.
		 *   3. At least 10 rates must pass validation. A response with only a few
		 *      valid rates likely indicates a parsing error or partial response.
		 *
		 * If validation fails, we keep the previously cached rates (if any) rather
		 * than overwriting them with bad data. The next cron run or manual refresh
		 * will try again.
		 */
		$validated_rates = geoprice_validate_exchange_rates( $rates );

		if ( ! empty( $validated_rates ) ) {
			/* Store rates with autoload=false for performance (not needed on every page load). */
			update_option( 'geoprice_exchange_rates', $validated_rates, false );
			/* Store the current time so the admin can see when rates were last refreshed. */
			update_option( 'geoprice_rates_updated', time(), false );
			return true;
		}
	}

	return false;
}

/**
 * Fetch rates from the free ExchangeRate-API (no API key required).
 *
 * API DETAILS:
 *   - Endpoint: https://open.er-api.com/v6/latest/USD
 *   - No authentication required — completely free.
 *   - Returns JSON with "result" status and "rates" object.
 *   - Rates are updated once daily on their servers.
 *   - Supports 160+ currencies.
 *
 * RESPONSE FORMAT:
 *   {
 *     "result": "success",
 *     "base_code": "USD",
 *     "rates": {
 *       "USD": 1,
 *       "CAD": 1.3512,
 *       "EUR": 0.9234,
 *       ...
 *     }
 *   }
 *
 * DATA PROCESSING:
 *   We extract the "rates" object and cast all values to float using array_map.
 *   This ensures consistent numeric types for later multiplication in
 *   geoprice_convert_usd_to_currency().
 *
 * @return array Associative array of { currency_code => float_rate }, or empty
 *               array on failure (network error, API error, unexpected format).
 */
function geoprice_fetch_from_exchangerate_api() {
	$url      = 'https://open.er-api.com/v6/latest/USD';
	$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

	if ( is_wp_error( $response ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	/* Validate response: must have "result": "success" and a "rates" object. */
	if ( ! empty( $body['result'] ) && 'success' === $body['result'] && ! empty( $body['rates'] ) ) {
		return array_map( 'floatval', $body['rates'] );
	}

	return array();
}

/**
 * Fetch rates from Open Exchange Rates (requires an App ID).
 *
 * API DETAILS:
 *   - Endpoint: https://openexchangerates.org/api/latest.json?app_id=YOUR_ID
 *   - Requires a free App ID (signup at openexchangerates.org/signup/free).
 *   - Free tier: 1,000 requests/month, hourly updates, 170+ currencies.
 *   - Returns JSON with "rates" object (no "result" status field).
 *
 * RESPONSE FORMAT:
 *   {
 *     "disclaimer": "...",
 *     "license": "...",
 *     "timestamp": 1711324800,
 *     "base": "USD",
 *     "rates": {
 *       "CAD": 1.3512,
 *       "EUR": 0.9234,
 *       ...
 *     }
 *   }
 *
 * WHY SEPARATE FUNCTION:
 *   The response format differs from ExchangeRate-API (no "result" field, different
 *   error handling), and this provider requires an API key. Keeping the fetch logic
 *   separate makes each provider's code straightforward to understand and maintain.
 *
 * @return array Associative array of { currency_code => float_rate }, or empty
 *               array on failure.
 */
function geoprice_fetch_from_openexchangerates() {
	$app_id = get_option( 'geoprice_oxr_app_id', '' );

	/* Can't call the API without an App ID — return empty to signal failure. */
	if ( empty( $app_id ) ) {
		return array();
	}

	$url      = 'https://openexchangerates.org/api/latest.json?app_id=' . urlencode( $app_id );
	$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

	if ( is_wp_error( $response ) ) {
		return array();
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	/* Open Exchange Rates puts rates directly in $body['rates'] without a status field. */
	if ( ! empty( $body['rates'] ) ) {
		return array_map( 'floatval', $body['rates'] );
	}

	return array();
}

/**
 * Validate an array of exchange rates for integrity and sanity.
 *
 * This function prevents corrupted, manipulated, or nonsensical exchange
 * rates from being stored and used for price display.
 *
 * Without this validation, a compromised API response could cause:
 *   - Prices displayed as $0.00 (rate near zero).
 *   - Absurdly large displayed prices (rate in the millions).
 *   - PHP errors from non-numeric rate values.
 *   - NaN or Infinity from division/multiplication with bad data.
 *
 * VALIDATION APPROACH:
 *   We take a conservative approach: strip out individual bad rates but keep
 *   the good ones. This way, if the API returns mostly valid data with a few
 *   corrupted entries, we still have usable rates for the majority of currencies.
 *
 *   However, if too many rates are invalid (fewer than 10 pass validation),
 *   we reject the entire batch — this indicates a systemic problem with the
 *   API response (wrong endpoint, authentication error returning HTML, etc.).
 *
 * BOUNDS:
 *   - Minimum: > 0 (zero and negative rates are mathematically invalid).
 *   - Maximum: < 1,000,000 (the most extreme real-world rate is ~25,000 VND/USD;
 *     1 million provides generous headroom for hyperinflationary currencies).
 *   - Key format: exactly 3 uppercase alpha characters (ISO 4217 standard).
 *
 * @param array $rates Raw rates array: { 'CAD' => 1.35, 'EUR' => 0.92, ... }.
 * @return array Validated rates array with bad entries removed, or empty array
 *               if fewer than 10 rates passed validation.
 */
function geoprice_validate_exchange_rates( $rates ) {
	if ( ! is_array( $rates ) ) {
		return array();
	}

	$validated = array();

	foreach ( $rates as $currency_code => $rate ) {
		/*
		 * Validate the currency code: must be exactly 3 uppercase alpha chars.
		 * This prevents injection of non-currency keys into the rates array.
		 */
		if ( ! is_string( $currency_code ) || strlen( $currency_code ) !== 3 || ! ctype_alpha( $currency_code ) ) {
			continue;
		}

		$currency_code = strtoupper( $currency_code );

		/*
		 * Validate the rate value: must be a positive number within bounds.
		 * floatval() handles both int and float inputs. We explicitly check
		 * for is_numeric() first to reject strings like "NaN" or "Infinity"
		 * that floatval() would convert to 0 or INF.
		 */
		if ( ! is_numeric( $rate ) ) {
			continue;
		}

		$rate = floatval( $rate );

		if ( $rate <= 0 || $rate >= 1000000 ) {
			continue;
		}

		/*
		 * Additional check: reject NaN and Infinity that might slip through.
		 * is_finite() returns false for NaN, INF, and -INF.
		 */
		if ( ! is_finite( $rate ) ) {
			continue;
		}

		$validated[ $currency_code ] = $rate;
	}

	/*
	 * Require a minimum number of valid rates before accepting the batch.
	 * A real API response should have 100+ currencies. If we only got a
	 * handful of valid rates, something is likely wrong with the response.
	 * We keep the threshold low (10) to be lenient — even a degraded
	 * response with a few dozen currencies is better than nothing.
	 */
	if ( count( $validated ) < 10 ) {
		return array();
	}

	return $validated;
}

/**
 * Get the cached exchange rates, fetching fresh ones if needed.
 *
 * This is the function that other parts of the plugin call to get rates.
 * It provides a lazy-initialization pattern: if no rates are cached yet
 * (e.g., first page load after activation, or the option was manually deleted),
 * it triggers a synchronous fetch rather than returning empty rates.
 *
 * PERFORMANCE NOTE:
 *   The synchronous fetch (on cache miss) adds a brief delay to the first page
 *   load that displays pricing. Subsequent loads read from wp_options, which is
 *   very fast. The daily cron job ensures rates stay fresh without this delay.
 *
 * @return array Associative array of { currency_code => float_rate }.
 *               May be empty if the API is unreachable and no prior rates exist.
 */
function geoprice_get_exchange_rates() {
	$rates = get_option( 'geoprice_exchange_rates', array() );

	/*
	 * If no rates are cached, try to fetch them now (synchronously).
	 * This handles the first-run case: the cron hasn't fired yet, but a
	 * visitor is already viewing the site. Better to show converted prices
	 * (even with a slight delay) than to show no conversion at all.
	 */
	if ( empty( $rates ) ) {
		geoprice_fetch_exchange_rates();
		$rates = get_option( 'geoprice_exchange_rates', array() );
	}

	return $rates;
}

/**
 * Convert a USD amount to a target currency.
 *
 * THE CORE CONVERSION FORMULA:
 *   local_amount = usd_amount * exchange_rate
 *
 *   Example: $29 USD to CAD with rate 1.3512
 *   → 29 * 1.3512 = 39.18 CAD
 *
 * SPECIAL CASES:
 *   - If the target currency IS USD, returns the amount unchanged (no conversion).
 *   - If the rate for the target currency is not available, returns false.
 *     The caller (frontend.php) handles this by falling back to the original
 *     PMPro cost text without any conversion.
 *
 * PRECISION:
 *   Results are rounded to 2 decimal places (standard for most currencies).
 *   Currencies that don't use decimals (e.g., JPY, KRW) are reformatted
 *   later by geoprice_format_price() to 0 decimal places.
 *
 * @param float  $usd_amount    The amount in US Dollars to convert.
 * @param string $currency_code The target ISO 4217 currency code (e.g., 'CAD', 'EUR').
 * @return float|false The converted amount rounded to 2 decimals, or false if the
 *                     exchange rate is not available for the given currency code.
 */
function geoprice_convert_usd_to_currency( $usd_amount, $currency_code ) {
	/* No conversion needed if the target currency is already USD. */
	if ( 'USD' === $currency_code ) {
		return (float) $usd_amount;
	}

	$rates = geoprice_get_exchange_rates();

	/* If we don't have a rate for this currency, we can't convert. */
	if ( empty( $rates[ $currency_code ] ) ) {
		return false;
	}

	return round( (float) $usd_amount * (float) $rates[ $currency_code ], 2 );
}

/**
 * Format a price amount with the correct currency symbol and decimal places.
 *
 * WHAT THIS DOES:
 *   Takes a numeric amount and a currency code, and returns a display-ready
 *   string like "CA$39.18", "€26.78", "¥4,346", etc.
 *
 * ZERO-DECIMAL CURRENCIES:
 *   Some currencies don't use decimal subunits (e.g., JPY has no "cents").
 *   For these, we display 0 decimal places instead of 2. The list of zero-
 *   decimal currencies is based on Stripe's specification (which is the most
 *   commonly used payment processor with PMPro). Showing "¥4,346" is correct;
 *   showing "¥4,346.00" would be wrong and confusing to Japanese visitors.
 *
 * FORMAT:
 *   The symbol is placed BEFORE the number (e.g., "$29.00", "€26.78"). This
 *   is the convention in English and most Western locales. Some currencies
 *   traditionally place the symbol after the number (e.g., "29,00 €" in French),
 *   but we use the prefix format consistently for simplicity.
 *
 * THOUSANDS SEPARATOR:
 *   Uses commas (e.g., "₹2,12,345.00" becomes "₹212,345.00"). Note that this
 *   uses the Western grouping convention (groups of 3), not the Indian lakh
 *   system. Full locale-aware formatting would require the PHP intl extension,
 *   which isn't always available on shared hosting.
 *
 * @param float  $amount        The price amount in the target currency.
 * @param string $currency_code The ISO 4217 currency code (used to look up the
 *                               symbol and determine decimal places).
 * @return string Formatted price string, e.g., "CA$39.18", "¥4,346", "€26.78".
 */
function geoprice_format_price( $amount, $currency_code ) {
	$symbol = geoprice_get_currency_symbol( $currency_code );

	/*
	 * Zero-decimal currencies: these currencies have no minor unit (no "cents").
	 * Example: 1 JPY is the smallest unit — there's no 0.5 JPY.
	 * Showing decimals for these would be incorrect and confusing.
	 *
	 * This list matches Stripe's zero-decimal currency list, since Stripe is
	 * the most common payment gateway used with PMPro.
	 */
	$zero_decimal = array(
		'BIF', // Burundian Franc
		'CLP', // Chilean Peso
		'DJF', // Djiboutian Franc
		'GNF', // Guinean Franc
		'ISK', // Icelandic Krona
		'JPY', // Japanese Yen
		'KMF', // Comorian Franc
		'KRW', // South Korean Won
		'KPW', // North Korean Won
		'PYG', // Paraguayan Guarani
		'RWF', // Rwandan Franc
		'UGX', // Ugandan Shilling
		'VND', // Vietnamese Dong
		'VUV', // Vanuatu Vatu
		'XAF', // Central African CFA Franc
		'XOF', // West African CFA Franc
	);

	if ( in_array( $currency_code, $zero_decimal, true ) ) {
		$formatted = number_format( $amount, 0 );
	} else {
		$formatted = number_format( $amount, 2 );
	}

	/* Prepend the currency symbol: "$29.00", "€26.78", "¥4,346", etc. */
	return $symbol . $formatted;
}
