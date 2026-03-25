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
 *   2. TOP 20 COUNTRIES (geoprice_get_top_countries):
 *      The 20 most-populated countries on Earth. These are shown by default on the
 *      admin level pricing table so that the admin doesn't have to scroll through
 *      ~195 countries to configure the most commonly relevant ones. The rest are
 *      hidden behind a "Show All Countries" button.
 *
 *   3. CURRENCY SYMBOLS (geoprice_get_currency_symbols):
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
 * Get the top 20 countries by population.
 *
 * WHY THESE 20:
 *   These are the world's most populated countries (approximate 2024 rankings).
 *   They're shown first in the admin pricing table because they represent the
 *   largest potential customer bases. The admin can configure pricing for these
 *   without expanding the full country list.
 *
 * HOW THIS IS USED:
 *   - admin-level-pricing.php renders these countries first in the pricing table,
 *     always visible. All other countries are rendered below but hidden by default.
 *   - admin.js uses the CSS class distinction (geoprice-top-country vs
 *     geoprice-extra-country) to toggle visibility with the Show/Hide button.
 *
 * @return array Flat array of ISO 3166-1 alpha-2 country codes, ordered by
 *               approximate population rank (India first, Thailand 20th).
 */
function geoprice_get_top_countries() {
	return array(
		'IN', // 1.  India .............. INR (Indian Rupee)
		'CN', // 2.  China .............. CNY (Chinese Yuan)
		'US', // 3.  United States ...... USD (US Dollar)
		'ID', // 4.  Indonesia .......... IDR (Indonesian Rupiah)
		'PK', // 5.  Pakistan ........... PKR (Pakistani Rupee)
		'NG', // 6.  Nigeria ............ NGN (Nigerian Naira)
		'BR', // 7.  Brazil ............. BRL (Brazilian Real)
		'BD', // 8.  Bangladesh ......... BDT (Bangladeshi Taka)
		'RU', // 9.  Russia ............. RUB (Russian Ruble)
		'ET', // 10. Ethiopia ........... ETB (Ethiopian Birr)
		'MX', // 11. Mexico ............. MXN (Mexican Peso)
		'JP', // 12. Japan .............. JPY (Japanese Yen)
		'PH', // 13. Philippines ........ PHP (Philippine Peso)
		'EG', // 14. Egypt .............. EGP (Egyptian Pound)
		'CD', // 15. DR Congo ........... CDF (Congolese Franc)
		'VN', // 16. Vietnam ............ VND (Vietnamese Dong)
		'IR', // 17. Iran ............... IRR (Iranian Rial)
		'TR', // 18. Turkey ............. TRY (Turkish Lira)
		'DE', // 19. Germany ............ EUR (Euro)
		'TH', // 20. Thailand ........... THB (Thai Baht)
	);
}

/**
 * Get all countries with their names and primary currency codes.
 *
 * STRUCTURE:
 *   Returns an associative array keyed by ISO 3166-1 alpha-2 country code.
 *   Each entry is an array with two keys:
 *     - 'name'     => Human-readable country name (English).
 *     - 'currency' => ISO 4217 currency code for the country's primary currency.
 *
 * EXAMPLE ENTRY:
 *   'CA' => array( 'name' => 'Canada', 'currency' => 'CAD' )
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
 *               Each value is array{ name: string, currency: string }.
 */
function geoprice_get_all_countries() {
	return array(
		'AF' => array( 'name' => 'Afghanistan', 'currency' => 'AFN' ),
		'AL' => array( 'name' => 'Albania', 'currency' => 'ALL' ),
		'DZ' => array( 'name' => 'Algeria', 'currency' => 'DZD' ),
		'AD' => array( 'name' => 'Andorra', 'currency' => 'EUR' ),
		'AO' => array( 'name' => 'Angola', 'currency' => 'AOA' ),
		'AG' => array( 'name' => 'Antigua and Barbuda', 'currency' => 'XCD' ),
		'AR' => array( 'name' => 'Argentina', 'currency' => 'ARS' ),
		'AM' => array( 'name' => 'Armenia', 'currency' => 'AMD' ),
		'AU' => array( 'name' => 'Australia', 'currency' => 'AUD' ),
		'AT' => array( 'name' => 'Austria', 'currency' => 'EUR' ),
		'AZ' => array( 'name' => 'Azerbaijan', 'currency' => 'AZN' ),
		'BS' => array( 'name' => 'Bahamas', 'currency' => 'BSD' ),
		'BH' => array( 'name' => 'Bahrain', 'currency' => 'BHD' ),
		'BD' => array( 'name' => 'Bangladesh', 'currency' => 'BDT' ),
		'BB' => array( 'name' => 'Barbados', 'currency' => 'BBD' ),
		'BY' => array( 'name' => 'Belarus', 'currency' => 'BYN' ),
		'BE' => array( 'name' => 'Belgium', 'currency' => 'EUR' ),
		'BZ' => array( 'name' => 'Belize', 'currency' => 'BZD' ),
		'BJ' => array( 'name' => 'Benin', 'currency' => 'XOF' ),
		'BT' => array( 'name' => 'Bhutan', 'currency' => 'BTN' ),
		'BO' => array( 'name' => 'Bolivia', 'currency' => 'BOB' ),
		'BA' => array( 'name' => 'Bosnia and Herzegovina', 'currency' => 'BAM' ),
		'BW' => array( 'name' => 'Botswana', 'currency' => 'BWP' ),
		'BR' => array( 'name' => 'Brazil', 'currency' => 'BRL' ),
		'BN' => array( 'name' => 'Brunei', 'currency' => 'BND' ),
		'BG' => array( 'name' => 'Bulgaria', 'currency' => 'BGN' ),
		'BF' => array( 'name' => 'Burkina Faso', 'currency' => 'XOF' ),
		'BI' => array( 'name' => 'Burundi', 'currency' => 'BIF' ),
		'CV' => array( 'name' => 'Cabo Verde', 'currency' => 'CVE' ),
		'KH' => array( 'name' => 'Cambodia', 'currency' => 'KHR' ),
		'CM' => array( 'name' => 'Cameroon', 'currency' => 'XAF' ),
		'CA' => array( 'name' => 'Canada', 'currency' => 'CAD' ),
		'CF' => array( 'name' => 'Central African Republic', 'currency' => 'XAF' ),
		'TD' => array( 'name' => 'Chad', 'currency' => 'XAF' ),
		'CL' => array( 'name' => 'Chile', 'currency' => 'CLP' ),
		'CN' => array( 'name' => 'China', 'currency' => 'CNY' ),
		'CO' => array( 'name' => 'Colombia', 'currency' => 'COP' ),
		'KM' => array( 'name' => 'Comoros', 'currency' => 'KMF' ),
		'CG' => array( 'name' => 'Congo', 'currency' => 'XAF' ),
		'CD' => array( 'name' => 'Congo (DRC)', 'currency' => 'CDF' ),
		'CR' => array( 'name' => 'Costa Rica', 'currency' => 'CRC' ),
		'CI' => array( 'name' => "Cote d'Ivoire", 'currency' => 'XOF' ),
		'HR' => array( 'name' => 'Croatia', 'currency' => 'EUR' ),
		'CU' => array( 'name' => 'Cuba', 'currency' => 'CUP' ),
		'CY' => array( 'name' => 'Cyprus', 'currency' => 'EUR' ),
		'CZ' => array( 'name' => 'Czech Republic', 'currency' => 'CZK' ),
		'DK' => array( 'name' => 'Denmark', 'currency' => 'DKK' ),
		'DJ' => array( 'name' => 'Djibouti', 'currency' => 'DJF' ),
		'DM' => array( 'name' => 'Dominica', 'currency' => 'XCD' ),
		'DO' => array( 'name' => 'Dominican Republic', 'currency' => 'DOP' ),
		'EC' => array( 'name' => 'Ecuador', 'currency' => 'USD' ),
		'EG' => array( 'name' => 'Egypt', 'currency' => 'EGP' ),
		'SV' => array( 'name' => 'El Salvador', 'currency' => 'USD' ),
		'GQ' => array( 'name' => 'Equatorial Guinea', 'currency' => 'XAF' ),
		'ER' => array( 'name' => 'Eritrea', 'currency' => 'ERN' ),
		'EE' => array( 'name' => 'Estonia', 'currency' => 'EUR' ),
		'SZ' => array( 'name' => 'Eswatini', 'currency' => 'SZL' ),
		'ET' => array( 'name' => 'Ethiopia', 'currency' => 'ETB' ),
		'FJ' => array( 'name' => 'Fiji', 'currency' => 'FJD' ),
		'FI' => array( 'name' => 'Finland', 'currency' => 'EUR' ),
		'FR' => array( 'name' => 'France', 'currency' => 'EUR' ),
		'GA' => array( 'name' => 'Gabon', 'currency' => 'XAF' ),
		'GM' => array( 'name' => 'Gambia', 'currency' => 'GMD' ),
		'GE' => array( 'name' => 'Georgia', 'currency' => 'GEL' ),
		'DE' => array( 'name' => 'Germany', 'currency' => 'EUR' ),
		'GH' => array( 'name' => 'Ghana', 'currency' => 'GHS' ),
		'GR' => array( 'name' => 'Greece', 'currency' => 'EUR' ),
		'GD' => array( 'name' => 'Grenada', 'currency' => 'XCD' ),
		'GT' => array( 'name' => 'Guatemala', 'currency' => 'GTQ' ),
		'GN' => array( 'name' => 'Guinea', 'currency' => 'GNF' ),
		'GW' => array( 'name' => 'Guinea-Bissau', 'currency' => 'XOF' ),
		'GY' => array( 'name' => 'Guyana', 'currency' => 'GYD' ),
		'HT' => array( 'name' => 'Haiti', 'currency' => 'HTG' ),
		'HN' => array( 'name' => 'Honduras', 'currency' => 'HNL' ),
		'HU' => array( 'name' => 'Hungary', 'currency' => 'HUF' ),
		'IS' => array( 'name' => 'Iceland', 'currency' => 'ISK' ),
		'IN' => array( 'name' => 'India', 'currency' => 'INR' ),
		'ID' => array( 'name' => 'Indonesia', 'currency' => 'IDR' ),
		'IR' => array( 'name' => 'Iran', 'currency' => 'IRR' ),
		'IQ' => array( 'name' => 'Iraq', 'currency' => 'IQD' ),
		'IE' => array( 'name' => 'Ireland', 'currency' => 'EUR' ),
		'IL' => array( 'name' => 'Israel', 'currency' => 'ILS' ),
		'IT' => array( 'name' => 'Italy', 'currency' => 'EUR' ),
		'JM' => array( 'name' => 'Jamaica', 'currency' => 'JMD' ),
		'JP' => array( 'name' => 'Japan', 'currency' => 'JPY' ),
		'JO' => array( 'name' => 'Jordan', 'currency' => 'JOD' ),
		'KZ' => array( 'name' => 'Kazakhstan', 'currency' => 'KZT' ),
		'KE' => array( 'name' => 'Kenya', 'currency' => 'KES' ),
		'KI' => array( 'name' => 'Kiribati', 'currency' => 'AUD' ),
		'KW' => array( 'name' => 'Kuwait', 'currency' => 'KWD' ),
		'KG' => array( 'name' => 'Kyrgyzstan', 'currency' => 'KGS' ),
		'LA' => array( 'name' => 'Laos', 'currency' => 'LAK' ),
		'LV' => array( 'name' => 'Latvia', 'currency' => 'EUR' ),
		'LB' => array( 'name' => 'Lebanon', 'currency' => 'LBP' ),
		'LS' => array( 'name' => 'Lesotho', 'currency' => 'LSL' ),
		'LR' => array( 'name' => 'Liberia', 'currency' => 'LRD' ),
		'LY' => array( 'name' => 'Libya', 'currency' => 'LYD' ),
		'LI' => array( 'name' => 'Liechtenstein', 'currency' => 'CHF' ),
		'LT' => array( 'name' => 'Lithuania', 'currency' => 'EUR' ),
		'LU' => array( 'name' => 'Luxembourg', 'currency' => 'EUR' ),
		'MG' => array( 'name' => 'Madagascar', 'currency' => 'MGA' ),
		'MW' => array( 'name' => 'Malawi', 'currency' => 'MWK' ),
		'MY' => array( 'name' => 'Malaysia', 'currency' => 'MYR' ),
		'MV' => array( 'name' => 'Maldives', 'currency' => 'MVR' ),
		'ML' => array( 'name' => 'Mali', 'currency' => 'XOF' ),
		'MT' => array( 'name' => 'Malta', 'currency' => 'EUR' ),
		'MH' => array( 'name' => 'Marshall Islands', 'currency' => 'USD' ),
		'MR' => array( 'name' => 'Mauritania', 'currency' => 'MRU' ),
		'MU' => array( 'name' => 'Mauritius', 'currency' => 'MUR' ),
		'MX' => array( 'name' => 'Mexico', 'currency' => 'MXN' ),
		'FM' => array( 'name' => 'Micronesia', 'currency' => 'USD' ),
		'MD' => array( 'name' => 'Moldova', 'currency' => 'MDL' ),
		'MC' => array( 'name' => 'Monaco', 'currency' => 'EUR' ),
		'MN' => array( 'name' => 'Mongolia', 'currency' => 'MNT' ),
		'ME' => array( 'name' => 'Montenegro', 'currency' => 'EUR' ),
		'MA' => array( 'name' => 'Morocco', 'currency' => 'MAD' ),
		'MZ' => array( 'name' => 'Mozambique', 'currency' => 'MZN' ),
		'MM' => array( 'name' => 'Myanmar', 'currency' => 'MMK' ),
		'NA' => array( 'name' => 'Namibia', 'currency' => 'NAD' ),
		'NR' => array( 'name' => 'Nauru', 'currency' => 'AUD' ),
		'NP' => array( 'name' => 'Nepal', 'currency' => 'NPR' ),
		'NL' => array( 'name' => 'Netherlands', 'currency' => 'EUR' ),
		'NZ' => array( 'name' => 'New Zealand', 'currency' => 'NZD' ),
		'NI' => array( 'name' => 'Nicaragua', 'currency' => 'NIO' ),
		'NE' => array( 'name' => 'Niger', 'currency' => 'XOF' ),
		'NG' => array( 'name' => 'Nigeria', 'currency' => 'NGN' ),
		'KP' => array( 'name' => 'North Korea', 'currency' => 'KPW' ),
		'MK' => array( 'name' => 'North Macedonia', 'currency' => 'MKD' ),
		'NO' => array( 'name' => 'Norway', 'currency' => 'NOK' ),
		'OM' => array( 'name' => 'Oman', 'currency' => 'OMR' ),
		'PK' => array( 'name' => 'Pakistan', 'currency' => 'PKR' ),
		'PW' => array( 'name' => 'Palau', 'currency' => 'USD' ),
		'PS' => array( 'name' => 'Palestine', 'currency' => 'ILS' ),
		'PA' => array( 'name' => 'Panama', 'currency' => 'PAB' ),
		'PG' => array( 'name' => 'Papua New Guinea', 'currency' => 'PGK' ),
		'PY' => array( 'name' => 'Paraguay', 'currency' => 'PYG' ),
		'PE' => array( 'name' => 'Peru', 'currency' => 'PEN' ),
		'PH' => array( 'name' => 'Philippines', 'currency' => 'PHP' ),
		'PL' => array( 'name' => 'Poland', 'currency' => 'PLN' ),
		'PT' => array( 'name' => 'Portugal', 'currency' => 'EUR' ),
		'QA' => array( 'name' => 'Qatar', 'currency' => 'QAR' ),
		'RO' => array( 'name' => 'Romania', 'currency' => 'RON' ),
		'RU' => array( 'name' => 'Russia', 'currency' => 'RUB' ),
		'RW' => array( 'name' => 'Rwanda', 'currency' => 'RWF' ),
		'KN' => array( 'name' => 'Saint Kitts and Nevis', 'currency' => 'XCD' ),
		'LC' => array( 'name' => 'Saint Lucia', 'currency' => 'XCD' ),
		'VC' => array( 'name' => 'Saint Vincent and the Grenadines', 'currency' => 'XCD' ),
		'WS' => array( 'name' => 'Samoa', 'currency' => 'WST' ),
		'SM' => array( 'name' => 'San Marino', 'currency' => 'EUR' ),
		'ST' => array( 'name' => 'Sao Tome and Principe', 'currency' => 'STN' ),
		'SA' => array( 'name' => 'Saudi Arabia', 'currency' => 'SAR' ),
		'SN' => array( 'name' => 'Senegal', 'currency' => 'XOF' ),
		'RS' => array( 'name' => 'Serbia', 'currency' => 'RSD' ),
		'SC' => array( 'name' => 'Seychelles', 'currency' => 'SCR' ),
		'SL' => array( 'name' => 'Sierra Leone', 'currency' => 'SLL' ),
		'SG' => array( 'name' => 'Singapore', 'currency' => 'SGD' ),
		'SK' => array( 'name' => 'Slovakia', 'currency' => 'EUR' ),
		'SI' => array( 'name' => 'Slovenia', 'currency' => 'EUR' ),
		'SB' => array( 'name' => 'Solomon Islands', 'currency' => 'SBD' ),
		'SO' => array( 'name' => 'Somalia', 'currency' => 'SOS' ),
		'ZA' => array( 'name' => 'South Africa', 'currency' => 'ZAR' ),
		'KR' => array( 'name' => 'South Korea', 'currency' => 'KRW' ),
		'SS' => array( 'name' => 'South Sudan', 'currency' => 'SSP' ),
		'ES' => array( 'name' => 'Spain', 'currency' => 'EUR' ),
		'LK' => array( 'name' => 'Sri Lanka', 'currency' => 'LKR' ),
		'SD' => array( 'name' => 'Sudan', 'currency' => 'SDG' ),
		'SR' => array( 'name' => 'Suriname', 'currency' => 'SRD' ),
		'SE' => array( 'name' => 'Sweden', 'currency' => 'SEK' ),
		'CH' => array( 'name' => 'Switzerland', 'currency' => 'CHF' ),
		'SY' => array( 'name' => 'Syria', 'currency' => 'SYP' ),
		'TW' => array( 'name' => 'Taiwan', 'currency' => 'TWD' ),
		'TJ' => array( 'name' => 'Tajikistan', 'currency' => 'TJS' ),
		'TZ' => array( 'name' => 'Tanzania', 'currency' => 'TZS' ),
		'TH' => array( 'name' => 'Thailand', 'currency' => 'THB' ),
		'TL' => array( 'name' => 'Timor-Leste', 'currency' => 'USD' ),
		'TG' => array( 'name' => 'Togo', 'currency' => 'XOF' ),
		'TO' => array( 'name' => 'Tonga', 'currency' => 'TOP' ),
		'TT' => array( 'name' => 'Trinidad and Tobago', 'currency' => 'TTD' ),
		'TN' => array( 'name' => 'Tunisia', 'currency' => 'TND' ),
		'TR' => array( 'name' => 'Turkey', 'currency' => 'TRY' ),
		'TM' => array( 'name' => 'Turkmenistan', 'currency' => 'TMT' ),
		'TV' => array( 'name' => 'Tuvalu', 'currency' => 'AUD' ),
		'UG' => array( 'name' => 'Uganda', 'currency' => 'UGX' ),
		'UA' => array( 'name' => 'Ukraine', 'currency' => 'UAH' ),
		'AE' => array( 'name' => 'United Arab Emirates', 'currency' => 'AED' ),
		'GB' => array( 'name' => 'United Kingdom', 'currency' => 'GBP' ),
		'US' => array( 'name' => 'United States', 'currency' => 'USD' ),
		'UY' => array( 'name' => 'Uruguay', 'currency' => 'UYU' ),
		'UZ' => array( 'name' => 'Uzbekistan', 'currency' => 'UZS' ),
		'VU' => array( 'name' => 'Vanuatu', 'currency' => 'VUV' ),
		'VA' => array( 'name' => 'Vatican City', 'currency' => 'EUR' ),
		'VE' => array( 'name' => 'Venezuela', 'currency' => 'VES' ),
		'VN' => array( 'name' => 'Vietnam', 'currency' => 'VND' ),
		'YE' => array( 'name' => 'Yemen', 'currency' => 'YER' ),
		'ZM' => array( 'name' => 'Zambia', 'currency' => 'ZMW' ),
		'ZW' => array( 'name' => 'Zimbabwe', 'currency' => 'ZWL' ),
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
