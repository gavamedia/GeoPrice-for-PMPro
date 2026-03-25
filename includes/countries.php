<?php
/**
 * Country, currency code, and currency symbol reference data.
 *
 * This file is a pure data layer — it contains no hooks, no side effects, and no
 * external calls. It provides three categories of data used throughout the plugin:
 *
 *   1. COUNTRY LIST (geoprice_get_all_countries):
 *      Every recognized country with its ISO 3166-1 alpha-2 code (e.g., "CA" for
 *      Canada) and its primary ISO 4217 currency code (e.g., "CAD"). This mapping
 *      is used to determine which currency symbol to display to a visitor after
 *      their country is detected via geolocation.
 *
 *   2. CURRENCY SYMBOLS (geoprice_get_currency_symbols):
 *      A mapping from ISO 4217 currency codes to their display symbols (e.g.,
 *      "USD" => "$", "GBP" => "£", "JPY" => "¥"). Used when formatting converted
 *      prices for frontend display.
 *
 * WHY STATIC DATA (not an API):
 *   Country-to-currency mappings change extremely rarely (maybe once every few
 *   years when a country adopts a new currency). Hardcoding avoids an external
 *   dependency, eliminates a network call, and ensures the plugin works offline.
 *   If a currency mapping changes, a plugin update can include the correction.
 *
 * @copyright 2024-2026 GAVAMEDIA Corporation (https://gavamedia.com)
 * @license   GPL-2.0-or-later
 * @package   GeoPrice_For_PMPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the default countries shown in the pricing table before the admin adds more.
 *
 * @return array Flat array of ISO 3166-1 alpha-2 country codes.
 */
function geoprice_get_default_countries() {
	return array( 'US', 'CA', 'MX' );
}

/**
 * Get all countries with their names, primary currency codes, continents, and populations.
 *
 * STRUCTURE:
 *   Returns an associative array keyed by ISO 3166-1 alpha-2 country code.
 *   Each entry is an array with four keys:
 *     - 'name'       => Human-readable country name (English).
 *     - 'currency'   => ISO 4217 currency code for the country's primary currency.
 *     - 'continent'  => Continent name (Africa, Asia, Europe, North America,
 *                        South America, or Oceania). Central American and Caribbean
 *                        countries are grouped under North America.
 *     - 'population' => Approximate 2024 population in thousands (for sorting, not display).
 *
 * EXAMPLE ENTRY:
 *   'CA' => array( 'name' => 'Canada', 'currency' => 'CAD', 'continent' => 'North America', 'population' => 39000 )
 *
 * HOW THIS IS USED:
 *   - admin-level-pricing.php iterates this list to render one table row per
 *     country in the level edit form.
 *   - admin-settings.php uses it to populate the "Default Country" dropdown.
 *   - geolocation.php validates detected country codes against this list.
 *   - frontend.php calls geoprice_get_country_currency() (below) to look up
 *     the currency code for a detected country, then converts the USD price
 *     to that currency for display.
 *
 * NOTES ON SHARED CURRENCIES:
 *   - Several countries share the same currency code. For example, many Eurozone
 *     countries use 'EUR', West African CFA franc countries use 'XOF', and
 *     Central African CFA franc countries use 'XAF'.
 *   - Some countries use USD as their official currency (e.g., Ecuador, El Salvador,
 *     Marshall Islands). For these, no currency conversion is needed — visitors
 *     from these countries see prices in USD directly.
 *
 * @return array Associative array keyed by ISO 3166-1 alpha-2 country code.
 *               Each value is array{ name: string, currency: string, continent: string, population: int }.
 */
function geoprice_get_all_countries() {
	return array(
		'AF' => array( 'name' => 'Afghanistan', 'currency' => 'AFN', 'continent' => 'Asia', 'population' => 42000 ),
		'AL' => array( 'name' => 'Albania', 'currency' => 'ALL', 'continent' => 'Europe', 'population' => 2800 ),
		'DZ' => array( 'name' => 'Algeria', 'currency' => 'DZD', 'continent' => 'Africa', 'population' => 45600 ),
		'AD' => array( 'name' => 'Andorra', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 80 ),
		'AO' => array( 'name' => 'Angola', 'currency' => 'AOA', 'continent' => 'Africa', 'population' => 36700 ),
		'AG' => array( 'name' => 'Antigua and Barbuda', 'currency' => 'XCD', 'continent' => 'North America', 'population' => 100 ),
		'AR' => array( 'name' => 'Argentina', 'currency' => 'ARS', 'continent' => 'South America', 'population' => 46300 ),
		'AM' => array( 'name' => 'Armenia', 'currency' => 'AMD', 'continent' => 'Asia', 'population' => 2800 ),
		'AU' => array( 'name' => 'Australia', 'currency' => 'AUD', 'continent' => 'Oceania', 'population' => 26500 ),
		'AT' => array( 'name' => 'Austria', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 9100 ),
		'AZ' => array( 'name' => 'Azerbaijan', 'currency' => 'AZN', 'continent' => 'Asia', 'population' => 10200 ),
		'BS' => array( 'name' => 'Bahamas', 'currency' => 'BSD', 'continent' => 'North America', 'population' => 410 ),
		'BH' => array( 'name' => 'Bahrain', 'currency' => 'BHD', 'continent' => 'Asia', 'population' => 1500 ),
		'BD' => array( 'name' => 'Bangladesh', 'currency' => 'BDT', 'continent' => 'Asia', 'population' => 173000 ),
		'BB' => array( 'name' => 'Barbados', 'currency' => 'BBD', 'continent' => 'North America', 'population' => 280 ),
		'BY' => array( 'name' => 'Belarus', 'currency' => 'BYN', 'continent' => 'Europe', 'population' => 9200 ),
		'BE' => array( 'name' => 'Belgium', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 11700 ),
		'BZ' => array( 'name' => 'Belize', 'currency' => 'BZD', 'continent' => 'North America', 'population' => 410 ),
		'BJ' => array( 'name' => 'Benin', 'currency' => 'XOF', 'continent' => 'Africa', 'population' => 13700 ),
		'BT' => array( 'name' => 'Bhutan', 'currency' => 'BTN', 'continent' => 'Asia', 'population' => 790 ),
		'BO' => array( 'name' => 'Bolivia', 'currency' => 'BOB', 'continent' => 'South America', 'population' => 12400 ),
		'BA' => array( 'name' => 'Bosnia and Herzegovina', 'currency' => 'BAM', 'continent' => 'Europe', 'population' => 3200 ),
		'BW' => array( 'name' => 'Botswana', 'currency' => 'BWP', 'continent' => 'Africa', 'population' => 2600 ),
		'BR' => array( 'name' => 'Brazil', 'currency' => 'BRL', 'continent' => 'South America', 'population' => 216000 ),
		'BN' => array( 'name' => 'Brunei', 'currency' => 'BND', 'continent' => 'Asia', 'population' => 450 ),
		'BG' => array( 'name' => 'Bulgaria', 'currency' => 'BGN', 'continent' => 'Europe', 'population' => 6500 ),
		'BF' => array( 'name' => 'Burkina Faso', 'currency' => 'XOF', 'continent' => 'Africa', 'population' => 23000 ),
		'BI' => array( 'name' => 'Burundi', 'currency' => 'BIF', 'continent' => 'Africa', 'population' => 13200 ),
		'CV' => array( 'name' => 'Cabo Verde', 'currency' => 'CVE', 'continent' => 'Africa', 'population' => 600 ),
		'KH' => array( 'name' => 'Cambodia', 'currency' => 'KHR', 'continent' => 'Asia', 'population' => 17400 ),
		'CM' => array( 'name' => 'Cameroon', 'currency' => 'XAF', 'continent' => 'Africa', 'population' => 28600 ),
		'CA' => array( 'name' => 'Canada', 'currency' => 'CAD', 'continent' => 'North America', 'population' => 39000 ),
		'CF' => array( 'name' => 'Central African Republic', 'currency' => 'XAF', 'continent' => 'Africa', 'population' => 5500 ),
		'TD' => array( 'name' => 'Chad', 'currency' => 'XAF', 'continent' => 'Africa', 'population' => 18300 ),
		'CL' => array( 'name' => 'Chile', 'currency' => 'CLP', 'continent' => 'South America', 'population' => 19600 ),
		'CN' => array( 'name' => 'China', 'currency' => 'CNY', 'continent' => 'Asia', 'population' => 1425000 ),
		'CO' => array( 'name' => 'Colombia', 'currency' => 'COP', 'continent' => 'South America', 'population' => 52100 ),
		'KM' => array( 'name' => 'Comoros', 'currency' => 'KMF', 'continent' => 'Africa', 'population' => 840 ),
		'CG' => array( 'name' => 'Congo', 'currency' => 'XAF', 'continent' => 'Africa', 'population' => 6100 ),
		'CD' => array( 'name' => 'Congo (DRC)', 'currency' => 'CDF', 'continent' => 'Africa', 'population' => 102000 ),
		'CR' => array( 'name' => 'Costa Rica', 'currency' => 'CRC', 'continent' => 'North America', 'population' => 5200 ),
		'CI' => array( 'name' => "Cote d'Ivoire", 'currency' => 'XOF', 'continent' => 'Africa', 'population' => 28900 ),
		'HR' => array( 'name' => 'Croatia', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 3800 ),
		'CU' => array( 'name' => 'Cuba', 'currency' => 'CUP', 'continent' => 'North America', 'population' => 11200 ),
		'CY' => array( 'name' => 'Cyprus', 'currency' => 'EUR', 'continent' => 'Asia', 'population' => 1260 ),
		'CZ' => array( 'name' => 'Czech Republic', 'currency' => 'CZK', 'continent' => 'Europe', 'population' => 10900 ),
		'DK' => array( 'name' => 'Denmark', 'currency' => 'DKK', 'continent' => 'Europe', 'population' => 5900 ),
		'DJ' => array( 'name' => 'Djibouti', 'currency' => 'DJF', 'continent' => 'Africa', 'population' => 1100 ),
		'DM' => array( 'name' => 'Dominica', 'currency' => 'XCD', 'continent' => 'North America', 'population' => 73 ),
		'DO' => array( 'name' => 'Dominican Republic', 'currency' => 'DOP', 'continent' => 'North America', 'population' => 11300 ),
		'EC' => array( 'name' => 'Ecuador', 'currency' => 'USD', 'continent' => 'South America', 'population' => 18200 ),
		'EG' => array( 'name' => 'Egypt', 'currency' => 'EGP', 'continent' => 'Africa', 'population' => 112000 ),
		'SV' => array( 'name' => 'El Salvador', 'currency' => 'USD', 'continent' => 'North America', 'population' => 6300 ),
		'GQ' => array( 'name' => 'Equatorial Guinea', 'currency' => 'XAF', 'continent' => 'Africa', 'population' => 1700 ),
		'ER' => array( 'name' => 'Eritrea', 'currency' => 'ERN', 'continent' => 'Africa', 'population' => 3700 ),
		'EE' => array( 'name' => 'Estonia', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 1370 ),
		'SZ' => array( 'name' => 'Eswatini', 'currency' => 'SZL', 'continent' => 'Africa', 'population' => 1200 ),
		'ET' => array( 'name' => 'Ethiopia', 'currency' => 'ETB', 'continent' => 'Africa', 'population' => 126000 ),
		'FJ' => array( 'name' => 'Fiji', 'currency' => 'FJD', 'continent' => 'Oceania', 'population' => 930 ),
		'FI' => array( 'name' => 'Finland', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 5600 ),
		'FR' => array( 'name' => 'France', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 68200 ),
		'GA' => array( 'name' => 'Gabon', 'currency' => 'XAF', 'continent' => 'Africa', 'population' => 2400 ),
		'GM' => array( 'name' => 'Gambia', 'currency' => 'GMD', 'continent' => 'Africa', 'population' => 2700 ),
		'GE' => array( 'name' => 'Georgia', 'currency' => 'GEL', 'continent' => 'Asia', 'population' => 3700 ),
		'DE' => array( 'name' => 'Germany', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 84500 ),
		'GH' => array( 'name' => 'Ghana', 'currency' => 'GHS', 'continent' => 'Africa', 'population' => 34100 ),
		'GR' => array( 'name' => 'Greece', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 10300 ),
		'GD' => array( 'name' => 'Grenada', 'currency' => 'XCD', 'continent' => 'North America', 'population' => 125 ),
		'GT' => array( 'name' => 'Guatemala', 'currency' => 'GTQ', 'continent' => 'North America', 'population' => 18100 ),
		'GN' => array( 'name' => 'Guinea', 'currency' => 'GNF', 'continent' => 'Africa', 'population' => 14200 ),
		'GW' => array( 'name' => 'Guinea-Bissau', 'currency' => 'XOF', 'continent' => 'Africa', 'population' => 2100 ),
		'GY' => array( 'name' => 'Guyana', 'currency' => 'GYD', 'continent' => 'South America', 'population' => 810 ),
		'HT' => array( 'name' => 'Haiti', 'currency' => 'HTG', 'continent' => 'North America', 'population' => 11700 ),
		'HN' => array( 'name' => 'Honduras', 'currency' => 'HNL', 'continent' => 'North America', 'population' => 10400 ),
		'HU' => array( 'name' => 'Hungary', 'currency' => 'HUF', 'continent' => 'Europe', 'population' => 9600 ),
		'IS' => array( 'name' => 'Iceland', 'currency' => 'ISK', 'continent' => 'Europe', 'population' => 380 ),
		'IN' => array( 'name' => 'India', 'currency' => 'INR', 'continent' => 'Asia', 'population' => 1428000 ),
		'ID' => array( 'name' => 'Indonesia', 'currency' => 'IDR', 'continent' => 'Asia', 'population' => 277500 ),
		'IR' => array( 'name' => 'Iran', 'currency' => 'IRR', 'continent' => 'Asia', 'population' => 88600 ),
		'IQ' => array( 'name' => 'Iraq', 'currency' => 'IQD', 'continent' => 'Asia', 'population' => 44500 ),
		'IE' => array( 'name' => 'Ireland', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 5100 ),
		'IL' => array( 'name' => 'Israel', 'currency' => 'ILS', 'continent' => 'Asia', 'population' => 9800 ),
		'IT' => array( 'name' => 'Italy', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 58900 ),
		'JM' => array( 'name' => 'Jamaica', 'currency' => 'JMD', 'continent' => 'North America', 'population' => 2800 ),
		'JP' => array( 'name' => 'Japan', 'currency' => 'JPY', 'continent' => 'Asia', 'population' => 123300 ),
		'JO' => array( 'name' => 'Jordan', 'currency' => 'JOD', 'continent' => 'Asia', 'population' => 11500 ),
		'KZ' => array( 'name' => 'Kazakhstan', 'currency' => 'KZT', 'continent' => 'Asia', 'population' => 19600 ),
		'KE' => array( 'name' => 'Kenya', 'currency' => 'KES', 'continent' => 'Africa', 'population' => 55100 ),
		'KI' => array( 'name' => 'Kiribati', 'currency' => 'AUD', 'continent' => 'Oceania', 'population' => 130 ),
		'KW' => array( 'name' => 'Kuwait', 'currency' => 'KWD', 'continent' => 'Asia', 'population' => 4300 ),
		'KG' => array( 'name' => 'Kyrgyzstan', 'currency' => 'KGS', 'continent' => 'Asia', 'population' => 7000 ),
		'LA' => array( 'name' => 'Laos', 'currency' => 'LAK', 'continent' => 'Asia', 'population' => 7600 ),
		'LV' => array( 'name' => 'Latvia', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 1830 ),
		'LB' => array( 'name' => 'Lebanon', 'currency' => 'LBP', 'continent' => 'Asia', 'population' => 5500 ),
		'LS' => array( 'name' => 'Lesotho', 'currency' => 'LSL', 'continent' => 'Africa', 'population' => 2300 ),
		'LR' => array( 'name' => 'Liberia', 'currency' => 'LRD', 'continent' => 'Africa', 'population' => 5400 ),
		'LY' => array( 'name' => 'Libya', 'currency' => 'LYD', 'continent' => 'Africa', 'population' => 7000 ),
		'LI' => array( 'name' => 'Liechtenstein', 'currency' => 'CHF', 'continent' => 'Europe', 'population' => 40 ),
		'LT' => array( 'name' => 'Lithuania', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 2800 ),
		'LU' => array( 'name' => 'Luxembourg', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 660 ),
		'MG' => array( 'name' => 'Madagascar', 'currency' => 'MGA', 'continent' => 'Africa', 'population' => 30300 ),
		'MW' => array( 'name' => 'Malawi', 'currency' => 'MWK', 'continent' => 'Africa', 'population' => 20900 ),
		'MY' => array( 'name' => 'Malaysia', 'currency' => 'MYR', 'continent' => 'Asia', 'population' => 34300 ),
		'MV' => array( 'name' => 'Maldives', 'currency' => 'MVR', 'continent' => 'Asia', 'population' => 520 ),
		'ML' => array( 'name' => 'Mali', 'currency' => 'XOF', 'continent' => 'Africa', 'population' => 23300 ),
		'MT' => array( 'name' => 'Malta', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 540 ),
		'MH' => array( 'name' => 'Marshall Islands', 'currency' => 'USD', 'continent' => 'Oceania', 'population' => 42 ),
		'MR' => array( 'name' => 'Mauritania', 'currency' => 'MRU', 'continent' => 'Africa', 'population' => 4900 ),
		'MU' => array( 'name' => 'Mauritius', 'currency' => 'MUR', 'continent' => 'Africa', 'population' => 1300 ),
		'MX' => array( 'name' => 'Mexico', 'currency' => 'MXN', 'continent' => 'North America', 'population' => 129000 ),
		'FM' => array( 'name' => 'Micronesia', 'currency' => 'USD', 'continent' => 'Oceania', 'population' => 115 ),
		'MD' => array( 'name' => 'Moldova', 'currency' => 'MDL', 'continent' => 'Europe', 'population' => 2600 ),
		'MC' => array( 'name' => 'Monaco', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 40 ),
		'MN' => array( 'name' => 'Mongolia', 'currency' => 'MNT', 'continent' => 'Asia', 'population' => 3400 ),
		'ME' => array( 'name' => 'Montenegro', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 620 ),
		'MA' => array( 'name' => 'Morocco', 'currency' => 'MAD', 'continent' => 'Africa', 'population' => 37800 ),
		'MZ' => array( 'name' => 'Mozambique', 'currency' => 'MZN', 'continent' => 'Africa', 'population' => 33900 ),
		'MM' => array( 'name' => 'Myanmar', 'currency' => 'MMK', 'continent' => 'Asia', 'population' => 54800 ),
		'NA' => array( 'name' => 'Namibia', 'currency' => 'NAD', 'continent' => 'Africa', 'population' => 2600 ),
		'NR' => array( 'name' => 'Nauru', 'currency' => 'AUD', 'continent' => 'Oceania', 'population' => 13 ),
		'NP' => array( 'name' => 'Nepal', 'currency' => 'NPR', 'continent' => 'Asia', 'population' => 30900 ),
		'NL' => array( 'name' => 'Netherlands', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 17700 ),
		'NZ' => array( 'name' => 'New Zealand', 'currency' => 'NZD', 'continent' => 'Oceania', 'population' => 5200 ),
		'NI' => array( 'name' => 'Nicaragua', 'currency' => 'NIO', 'continent' => 'North America', 'population' => 7000 ),
		'NE' => array( 'name' => 'Niger', 'currency' => 'XOF', 'continent' => 'Africa', 'population' => 27200 ),
		'NG' => array( 'name' => 'Nigeria', 'currency' => 'NGN', 'continent' => 'Africa', 'population' => 224000 ),
		'KP' => array( 'name' => 'North Korea', 'currency' => 'KPW', 'continent' => 'Asia', 'population' => 26200 ),
		'MK' => array( 'name' => 'North Macedonia', 'currency' => 'MKD', 'continent' => 'Europe', 'population' => 1830 ),
		'NO' => array( 'name' => 'Norway', 'currency' => 'NOK', 'continent' => 'Europe', 'population' => 5500 ),
		'OM' => array( 'name' => 'Oman', 'currency' => 'OMR', 'continent' => 'Asia', 'population' => 4600 ),
		'PK' => array( 'name' => 'Pakistan', 'currency' => 'PKR', 'continent' => 'Asia', 'population' => 235800 ),
		'PW' => array( 'name' => 'Palau', 'currency' => 'USD', 'continent' => 'Oceania', 'population' => 18 ),
		'PS' => array( 'name' => 'Palestine', 'currency' => 'ILS', 'continent' => 'Asia', 'population' => 5400 ),
		'PA' => array( 'name' => 'Panama', 'currency' => 'PAB', 'continent' => 'North America', 'population' => 4400 ),
		'PG' => array( 'name' => 'Papua New Guinea', 'currency' => 'PGK', 'continent' => 'Oceania', 'population' => 10300 ),
		'PY' => array( 'name' => 'Paraguay', 'currency' => 'PYG', 'continent' => 'South America', 'population' => 6800 ),
		'PE' => array( 'name' => 'Peru', 'currency' => 'PEN', 'continent' => 'South America', 'population' => 34000 ),
		'PH' => array( 'name' => 'Philippines', 'currency' => 'PHP', 'continent' => 'Asia', 'population' => 117300 ),
		'PL' => array( 'name' => 'Poland', 'currency' => 'PLN', 'continent' => 'Europe', 'population' => 36800 ),
		'PT' => array( 'name' => 'Portugal', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 10400 ),
		'QA' => array( 'name' => 'Qatar', 'currency' => 'QAR', 'continent' => 'Asia', 'population' => 2700 ),
		'RO' => array( 'name' => 'Romania', 'currency' => 'RON', 'continent' => 'Europe', 'population' => 19000 ),
		'RU' => array( 'name' => 'Russia', 'currency' => 'RUB', 'continent' => 'Europe', 'population' => 144200 ),
		'RW' => array( 'name' => 'Rwanda', 'currency' => 'RWF', 'continent' => 'Africa', 'population' => 14100 ),
		'KN' => array( 'name' => 'Saint Kitts and Nevis', 'currency' => 'XCD', 'continent' => 'North America', 'population' => 48 ),
		'LC' => array( 'name' => 'Saint Lucia', 'currency' => 'XCD', 'continent' => 'North America', 'population' => 180 ),
		'VC' => array( 'name' => 'Saint Vincent and the Grenadines', 'currency' => 'XCD', 'continent' => 'North America', 'population' => 110 ),
		'WS' => array( 'name' => 'Samoa', 'currency' => 'WST', 'continent' => 'Oceania', 'population' => 220 ),
		'SM' => array( 'name' => 'San Marino', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 34 ),
		'ST' => array( 'name' => 'Sao Tome and Principe', 'currency' => 'STN', 'continent' => 'Africa', 'population' => 230 ),
		'SA' => array( 'name' => 'Saudi Arabia', 'currency' => 'SAR', 'continent' => 'Asia', 'population' => 36900 ),
		'SN' => array( 'name' => 'Senegal', 'currency' => 'XOF', 'continent' => 'Africa', 'population' => 17900 ),
		'RS' => array( 'name' => 'Serbia', 'currency' => 'RSD', 'continent' => 'Europe', 'population' => 6600 ),
		'SC' => array( 'name' => 'Seychelles', 'currency' => 'SCR', 'continent' => 'Africa', 'population' => 100 ),
		'SL' => array( 'name' => 'Sierra Leone', 'currency' => 'SLL', 'continent' => 'Africa', 'population' => 8600 ),
		'SG' => array( 'name' => 'Singapore', 'currency' => 'SGD', 'continent' => 'Asia', 'population' => 5900 ),
		'SK' => array( 'name' => 'Slovakia', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 5400 ),
		'SI' => array( 'name' => 'Slovenia', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 2100 ),
		'SB' => array( 'name' => 'Solomon Islands', 'currency' => 'SBD', 'continent' => 'Oceania', 'population' => 740 ),
		'SO' => array( 'name' => 'Somalia', 'currency' => 'SOS', 'continent' => 'Africa', 'population' => 18100 ),
		'ZA' => array( 'name' => 'South Africa', 'currency' => 'ZAR', 'continent' => 'Africa', 'population' => 60400 ),
		'KR' => array( 'name' => 'South Korea', 'currency' => 'KRW', 'continent' => 'Asia', 'population' => 51700 ),
		'SS' => array( 'name' => 'South Sudan', 'currency' => 'SSP', 'continent' => 'Africa', 'population' => 11100 ),
		'ES' => array( 'name' => 'Spain', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 48000 ),
		'LK' => array( 'name' => 'Sri Lanka', 'currency' => 'LKR', 'continent' => 'Asia', 'population' => 22200 ),
		'SD' => array( 'name' => 'Sudan', 'currency' => 'SDG', 'continent' => 'Africa', 'population' => 48100 ),
		'SR' => array( 'name' => 'Suriname', 'currency' => 'SRD', 'continent' => 'South America', 'population' => 620 ),
		'SE' => array( 'name' => 'Sweden', 'currency' => 'SEK', 'continent' => 'Europe', 'population' => 10500 ),
		'CH' => array( 'name' => 'Switzerland', 'currency' => 'CHF', 'continent' => 'Europe', 'population' => 8800 ),
		'SY' => array( 'name' => 'Syria', 'currency' => 'SYP', 'continent' => 'Asia', 'population' => 22100 ),
		'TW' => array( 'name' => 'Taiwan', 'currency' => 'TWD', 'continent' => 'Asia', 'population' => 23900 ),
		'TJ' => array( 'name' => 'Tajikistan', 'currency' => 'TJS', 'continent' => 'Asia', 'population' => 10100 ),
		'TZ' => array( 'name' => 'Tanzania', 'currency' => 'TZS', 'continent' => 'Africa', 'population' => 65500 ),
		'TH' => array( 'name' => 'Thailand', 'currency' => 'THB', 'continent' => 'Asia', 'population' => 72000 ),
		'TL' => array( 'name' => 'Timor-Leste', 'currency' => 'USD', 'continent' => 'Oceania', 'population' => 1340 ),
		'TG' => array( 'name' => 'Togo', 'currency' => 'XOF', 'continent' => 'Africa', 'population' => 9100 ),
		'TO' => array( 'name' => 'Tonga', 'currency' => 'TOP', 'continent' => 'Oceania', 'population' => 107 ),
		'TT' => array( 'name' => 'Trinidad and Tobago', 'currency' => 'TTD', 'continent' => 'North America', 'population' => 1530 ),
		'TN' => array( 'name' => 'Tunisia', 'currency' => 'TND', 'continent' => 'Africa', 'population' => 12500 ),
		'TR' => array( 'name' => 'Turkey', 'currency' => 'TRY', 'continent' => 'Asia', 'population' => 85800 ),
		'TM' => array( 'name' => 'Turkmenistan', 'currency' => 'TMT', 'continent' => 'Asia', 'population' => 6500 ),
		'TV' => array( 'name' => 'Tuvalu', 'currency' => 'AUD', 'continent' => 'Oceania', 'population' => 11 ),
		'UG' => array( 'name' => 'Uganda', 'currency' => 'UGX', 'continent' => 'Africa', 'population' => 48600 ),
		'UA' => array( 'name' => 'Ukraine', 'currency' => 'UAH', 'continent' => 'Europe', 'population' => 37000 ),
		'AE' => array( 'name' => 'United Arab Emirates', 'currency' => 'AED', 'continent' => 'Asia', 'population' => 9500 ),
		'GB' => array( 'name' => 'United Kingdom', 'currency' => 'GBP', 'continent' => 'Europe', 'population' => 67700 ),
		'US' => array( 'name' => 'United States', 'currency' => 'USD', 'continent' => 'North America', 'population' => 335000 ),
		'UY' => array( 'name' => 'Uruguay', 'currency' => 'UYU', 'continent' => 'South America', 'population' => 3400 ),
		'UZ' => array( 'name' => 'Uzbekistan', 'currency' => 'UZS', 'continent' => 'Asia', 'population' => 36000 ),
		'VU' => array( 'name' => 'Vanuatu', 'currency' => 'VUV', 'continent' => 'Oceania', 'population' => 330 ),
		'VA' => array( 'name' => 'Vatican City', 'currency' => 'EUR', 'continent' => 'Europe', 'population' => 1 ),
		'VE' => array( 'name' => 'Venezuela', 'currency' => 'VES', 'continent' => 'South America', 'population' => 28400 ),
		'VN' => array( 'name' => 'Vietnam', 'currency' => 'VND', 'continent' => 'Asia', 'population' => 99500 ),
		'YE' => array( 'name' => 'Yemen', 'currency' => 'YER', 'continent' => 'Asia', 'population' => 34400 ),
		'ZM' => array( 'name' => 'Zambia', 'currency' => 'ZMW', 'continent' => 'Africa', 'population' => 20600 ),
		'ZW' => array( 'name' => 'Zimbabwe', 'currency' => 'ZWL', 'continent' => 'Africa', 'population' => 16700 ),
	);
}

/**
 * Get display symbols for all supported currency codes.
 *
 * WHY SYMBOLS MATTER:
 *   Displaying "$29" is ambiguous — it could be USD, CAD, AUD, etc. Using the
 *   localized symbol (e.g., "CA$29", "A$29", "MX$29") makes it immediately clear
 *   which currency is shown. For currencies with unique symbols (e.g., "£", "€",
 *   "¥"), a single character suffices. For currencies that share the "$" symbol,
 *   we use prefixed variants (e.g., "CA$", "NZ$", "HK$") to disambiguate.
 *
 * FALLBACK BEHAVIOR:
 *   If a currency code isn't found in this map, geoprice_get_currency_symbol()
 *   returns the raw ISO code (e.g., "XDR") as a fallback. This ensures prices
 *   are always displayed even for unusual currencies — just without a symbol.
 *
 * @return array Associative array mapping ISO 4217 currency codes to their
 *               display symbols. Example: 'USD' => '$', 'EUR' => '€'.
 */
function geoprice_get_currency_symbols() {
	return array(
		'AED' => 'د.إ', 'AFN' => '؋',   'ALL' => 'L',   'AMD' => '֏',
		'ANG' => 'ƒ',   'AOA' => 'Kz',  'ARS' => '$',   'AUD' => 'A$',
		'AWG' => 'ƒ',   'AZN' => '₼',   'BAM' => 'KM',  'BBD' => 'Bds$',
		'BDT' => '৳',   'BGN' => 'лв',  'BHD' => '.د.ب', 'BIF' => 'FBu',
		'BMD' => '$',    'BND' => 'B$',  'BOB' => 'Bs.',  'BRL' => 'R$',
		'BSD' => '$',    'BTN' => 'Nu.',  'BWP' => 'P',   'BYN' => 'Br',
		'BZD' => 'BZ$',  'CAD' => 'CA$', 'CDF' => 'FC',  'CHF' => 'CHF',
		'CLP' => '$',    'CNY' => '¥',   'COP' => '$',   'CRC' => '₡',
		'CUP' => '₱',   'CVE' => '$',    'CZK' => 'Kč',  'DJF' => 'Fdj',
		'DKK' => 'kr',  'DOP' => 'RD$',  'DZD' => 'د.ج', 'EGP' => 'E£',
		'ERN' => 'Nfk',  'ETB' => 'Br',  'EUR' => '€',   'FJD' => 'FJ$',
		'GBP' => '£',   'GEL' => '₾',   'GHS' => 'GH₵', 'GMD' => 'D',
		'GNF' => 'FG',  'GTQ' => 'Q',    'GYD' => 'G$',  'HNL' => 'L',
		'HRK' => 'kn',  'HTG' => 'G',    'HUF' => 'Ft',  'IDR' => 'Rp',
		'ILS' => '₪',   'INR' => '₹',   'IQD' => 'ع.د', 'IRR' => '﷼',
		'ISK' => 'kr',  'JMD' => 'J$',   'JOD' => 'JD',  'JPY' => '¥',
		'KES' => 'KSh',  'KGS' => 'сом', 'KHR' => '៛',  'KMF' => 'CF',
		'KPW' => '₩',   'KRW' => '₩',   'KWD' => 'د.ك', 'KZT' => '₸',
		'LAK' => '₭',   'LBP' => 'L£',  'LKR' => 'Rs',  'LRD' => 'L$',
		'LSL' => 'L',   'LYD' => 'ل.د',  'MAD' => 'د.م.', 'MDL' => 'L',
		'MGA' => 'Ar',  'MKD' => 'ден',  'MMK' => 'K',   'MNT' => '₮',
		'MRU' => 'UM',  'MUR' => '₨',   'MVR' => 'Rf',   'MWK' => 'MK',
		'MXN' => 'MX$', 'MYR' => 'RM',  'MZN' => 'MT',   'NAD' => 'N$',
		'NGN' => '₦',   'NIO' => 'C$',  'NOK' => 'kr',   'NPR' => 'Rs',
		'NZD' => 'NZ$', 'OMR' => '﷼',   'PAB' => 'B/.',  'PEN' => 'S/.',
		'PGK' => 'K',   'PHP' => '₱',   'PKR' => '₨',   'PLN' => 'zł',
		'PYG' => '₲',   'QAR' => '﷼',   'RON' => 'lei', 'RSD' => 'din.',
		'RUB' => '₽',   'RWF' => 'RF',  'SAR' => '﷼',   'SBD' => 'SI$',
		'SCR' => '₨',   'SDG' => 'ج.س.', 'SEK' => 'kr',  'SGD' => 'S$',
		'SLL' => 'Le',  'SOS' => 'Sh',   'SRD' => '$',   'SSP' => '£',
		'STN' => 'Db',  'SYP' => '£',    'SZL' => 'E',   'THB' => '฿',
		'TJS' => 'SM',  'TMT' => 'T',    'TND' => 'د.ت', 'TOP' => 'T$',
		'TRY' => '₺',   'TTD' => 'TT$', 'TWD' => 'NT$',  'TZS' => 'TSh',
		'UAH' => '₴',   'UGX' => 'USh', 'USD' => '$',    'UYU' => '$U',
		'UZS' => 'сўм', 'VES' => 'Bs.S', 'VND' => '₫',  'VUV' => 'VT',
		'WST' => 'WS$', 'XAF' => 'FCFA', 'XCD' => 'EC$', 'XOF' => 'CFA',
		'YER' => '﷼',   'ZAR' => 'R',   'ZMW' => 'ZK',  'ZWL' => 'Z$',
	);
}

/**
 * Look up the display symbol for a single currency code.
 *
 * EXAMPLE:
 *   geoprice_get_currency_symbol( 'GBP' ) returns '£'
 *   geoprice_get_currency_symbol( 'CAD' ) returns 'CA$'
 *   geoprice_get_currency_symbol( 'XDR' ) returns 'XDR' (fallback — unknown code)
 *
 * @param string $currency_code ISO 4217 currency code (e.g., 'USD', 'EUR', 'JPY').
 * @return string The symbol (e.g., '$', '€', '¥') or the raw code if no symbol
 *                is mapped.
 */
function geoprice_get_currency_symbol( $currency_code ) {
	$symbols = geoprice_get_currency_symbols();
	return isset( $symbols[ $currency_code ] ) ? $symbols[ $currency_code ] : $currency_code;
}

/**
 * Look up the primary currency code for a given country.
 *
 * EXAMPLE:
 *   geoprice_get_country_currency( 'CA' ) returns 'CAD'
 *   geoprice_get_country_currency( 'DE' ) returns 'EUR'
 *   geoprice_get_country_currency( 'ZZ' ) returns 'USD' (fallback — unknown country)
 *
 * HOW THIS IS USED:
 *   frontend.php calls this after geolocation determines the visitor's country
 *   code. The returned currency code is then used to:
 *     1. Convert the USD price to the local currency (via geoprice_convert_usd_to_currency).
 *     2. Format the converted amount with the correct symbol (via geoprice_format_price).
 *
 * @param string $country_code ISO 3166-1 alpha-2 country code (e.g., 'CA', 'US', 'GB').
 * @return string ISO 4217 currency code (e.g., 'CAD', 'USD', 'GBP'). Returns 'USD'
 *                as a safe fallback for unknown country codes.
 */
function geoprice_get_country_currency( $country_code ) {
	$countries = geoprice_get_all_countries();
	return isset( $countries[ $country_code ] ) ? $countries[ $country_code ]['currency'] : 'USD';
}
