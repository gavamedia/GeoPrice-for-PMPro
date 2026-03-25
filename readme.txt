=== GeoPrice for PMPro ===
Contributors: gavamedia
Tags: pmpro, paid memberships pro, geographic pricing, geolocation, currency conversion
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Variable geographic pricing for Paid Memberships Pro. Set country-specific membership prices and display converted local currency amounts to visitors.

== Description ==

**GeoPrice for PMPro** by [GAVAMEDIA](https://gavamedia.com) is an add-on for [Paid Memberships Pro](https://www.paidmembershipspro.com/) that lets you set different membership prices for visitors from different countries.

All prices are configured in USD, and visitors see an approximate conversion to their local currency based on daily exchange rates. At checkout, GeoPrice uses the billing country from the checkout form to determine the country-specific USD amount to charge.

= Key Features =

* **Per-country pricing** — Set custom initial payment and recurring amounts for any of ~195 countries, on each membership level.
* **Automatic geolocation** — Detects the visitor's country from their IP address using free geolocation APIs (no API key required).
* **Local currency display** — Converts USD prices to the visitor's local currency with proper symbols (e.g., CA$38.50, EUR26.78, JPY4,346).
* **160+ currencies supported** — Including zero-decimal currencies like JPY and KRW.
* **Two-tier pricing model** — IP geolocation is used for display only. At checkout, the billing country from the checkout form determines the actual price charged.
* **Daily exchange rate refresh** — Rates are fetched automatically via WordPress cron and cached for performance.
* **Modern country picker** — Add countries to the pricing table via a searchable popup with flag emojis, sort by population or name, and group by continent.
* **Admin testing tools** — Append `?geoprice_country=CA` to any URL to preview pricing as a visitor from that country.
* **Proxy/CDN support** — Configurable IP detection for sites behind Cloudflare, Nginx, or load balancers.

= How It Works =

1. **Configure prices** — On each PMPro membership level edit page, a "Geographic Pricing" section appears below Billing Details. Add countries and enter custom USD prices. Leave prices blank to use the level's default.
2. **Visitors see local prices** — When a visitor loads your membership pages, the plugin detects their country via IP geolocation and displays prices converted to their local currency with an "(approximately)" label.
3. **Checkout charges the right amount** — When the visitor checks out, the plugin reads the billing country from the checkout form and charges the country-specific USD amount you configured.

= Requirements =

* WordPress 5.8 or later
* PHP 7.4 or later
* [Paid Memberships Pro](https://wordpress.org/plugins/paid-memberships-pro/) (free version or paid)

= External Services =

This plugin connects to external third-party services for IP geolocation and currency exchange rates. **No personal data is transmitted** — only the visitor's IP address (for geolocation) and a currency request (for exchange rates).

**IP Geolocation (to detect visitor country):**

* [ipapi.co](https://ipapi.co/) (default) — Privacy policy: [https://ipapi.co/privacy/](https://ipapi.co/privacy/)
* [ip-api.com](https://ip-api.com/) (fallback) — Privacy policy: [https://ip-api.com/docs/legal](https://ip-api.com/docs/legal)

The visitor's IP address is sent to one of these services to determine their country. The IP is not stored by the plugin beyond a 24-hour server-side cache (WordPress transient) keyed by a SHA-256 hash of the IP. A browser cookie (`geoprice_country`) stores the 2-letter country code for 24 hours to avoid repeat API calls.

**Exchange Rates (to convert USD to local currencies):**

* [ExchangeRate-API](https://www.exchangerate-api.com/) (default) — Terms: [https://www.exchangerate-api.com/terms](https://www.exchangerate-api.com/terms)
* [Open Exchange Rates](https://openexchangerates.org/) (optional, requires free API key) — Terms: [https://openexchangerates.org/terms](https://openexchangerates.org/terms)

Exchange rate requests do not include any user data. Rates are fetched once daily via WordPress cron and cached in the database.

== Installation ==

1. Upload the `geoprice-for-pmpro` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Ensure **Paid Memberships Pro** is installed and active.
4. Go to **Memberships > GeoPrice** to configure global settings.
5. Edit any membership level and scroll down to the **Geographic Pricing** section to set per-country prices.

== Frequently Asked Questions ==

= Do I need an API key? =

No. The default geolocation provider (ipapi.co) and exchange rate provider (ExchangeRate-API) are both free and require no API key. If you prefer Open Exchange Rates, a free API key is available at [openexchangerates.org](https://openexchangerates.org/signup/free).

= What currency are prices stored in? =

All prices are stored and charged in **USD**. The local currency amounts shown to visitors are approximate conversions for informational purposes only. The payment gateway always processes the USD amount.

= Can visitors manipulate the price by spoofing their location? =

Spoofing an IP address or cookie only changes the preview currency display. At checkout, GeoPrice determines pricing from the billing country selected on the checkout form — not from IP geolocation. This two-tier model ensures that location spoofing does not affect the amount charged.

= What happens if geolocation fails? =

The plugin falls back to a configurable default country (US by default). You can change this in **Memberships > GeoPrice**.

= My site is behind Cloudflare / a load balancer. How do I configure IP detection? =

Go to **Memberships > GeoPrice** and change the **IP Detection Method** to match your infrastructure (Cloudflare, X-Forwarded-For, or X-Real-IP). The default (REMOTE_ADDR) works for sites with a direct connection.

= How often are exchange rates updated? =

Rates are refreshed once daily via WordPress cron. You can also click **Refresh Rates Now** on the settings page for an immediate update.

= Does this plugin work with all PMPro payment gateways? =

GeoPrice's pricing layer works through PMPro's `pmpro_checkout_level` filter, which is gateway-agnostic. However, test your specific gateway's checkout flow before relying on country-based pricing in production.

= What data is cleaned up when I delete the plugin? =

All plugin settings, cached exchange rates, geolocation transients, per-level pricing data, active country lists, and scheduled cron events are removed from the database when you delete the plugin through the WordPress admin.

== Screenshots ==

1. Global settings page under Memberships > GeoPrice.
2. Per-country pricing table on the membership level edit page.
3. Country picker modal with search, sort, and group-by-continent.
4. Frontend display showing converted local currency prices.

== Changelog ==

= 1.1.3 =
* New country picker modal with flag emojis, search, population sort, and continent grouping.
* Countries persist in the pricing table even without prices set.
* Unsaved changes reminder banner.
* Geographic Pricing section now appears directly below Billing Details.
* Column renamed from "Billing Amount" to "Renewal Amount" for clarity.

= 1.0.0 =
* Initial release.
* Per-country pricing for PMPro membership levels.
* IP geolocation via ipapi.co (HTTPS) with ip-api.com fallback.
* Exchange rate fetching from ExchangeRate-API or Open Exchange Rates.
* Two-tier pricing: display uses IP geolocation, checkout uses billing country.
* Admin testing override via URL parameter.
* Configurable IP detection for proxy/CDN environments.
* Full data cleanup on plugin deletion.

== Upgrade Notice ==

= 1.1.3 =
New country picker modal with search and continent grouping. Geographic Pricing section moved below Billing Details. Added countries now persist even without prices.

= 1.0.0 =
Initial release.
