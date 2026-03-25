=== GeoPrice for PMPro ===
Contributors: gavamedia
Tags: pmpro, paid memberships pro, geographic pricing, geolocation, currency conversion
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Variable geographic pricing for Paid Memberships Pro. Set country-specific membership prices and display converted local currency amounts to visitors.

== Description ==

**GeoPrice for PMPro** by [GAVAMEDIA](https://gavamedia.com) is an add-on for [Paid Memberships Pro](https://www.paidmembershipspro.com/) that lets you set different membership prices for visitors from different countries.

All prices are configured in USD, and visitors see an approximate conversion to their local currency based on daily exchange rates. At checkout, GeoPrice uses the billing country selected during checkout to determine the country-specific USD amount to charge. The strongest checkout protections in this version are implemented for Stripe.

= Key Features =

* **Per-country pricing** — Set custom initial payment and recurring billing amounts for any of ~195 countries, on each membership level.
* **Automatic geolocation** — Detects the visitor's country from their IP address using free geolocation APIs (no API key required).
* **Local currency display** — Converts USD prices to the visitor's local currency with proper symbols (e.g., CA$38.50, €26.78, ¥4,346).
* **160+ currencies supported** — Including zero-decimal currencies like JPY and KRW.
* **Two-tier pricing model** — IP geolocation is used for display only. At checkout, the billing country selected on the checkout form determines the actual price charged.
* **Stripe-aware checkout flow** — When Stripe is the active gateway, GeoPrice requires billing-address collection, disables payment request buttons, and keeps checkout pricing synced to the selected billing country.
* **Daily exchange rate refresh** — Rates are fetched automatically via WordPress cron and cached for performance.
* **Admin testing tools** — Append `?geoprice_country=CA` to any URL to preview pricing as a visitor from that country.
* **Top 20 countries first** — The admin pricing table shows the 20 most-populated countries by default, with a button to reveal all countries.
* **Search and filter** — Quickly find any country in the pricing table by name.
* **Proxy/CDN support** — Configurable IP detection for sites behind Cloudflare, Nginx, or load balancers.

= How It Works =

1. **Configure prices** — On each PMPro membership level edit page, a "Geographic Pricing" section appears. Enter custom USD prices for specific countries. Leave countries blank to use the level's default price.
2. **Visitors see local prices** — When a visitor loads your membership pages, the plugin detects their country via IP geolocation and displays prices converted to their local currency with an "(approximately)" label.
3. **Checkout charges the right amount** — When the visitor checks out, the plugin reads the billing country selected on the checkout form and charges the country-specific USD amount you configured.

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

Spoofing an IP or cookie only changes the preview currency display. GeoPrice prices checkout from the billing country selected on the checkout form instead. On Stripe, GeoPrice also requires billing-address collection and logs issuer-country mismatches for manual review. This is still a trust-based workflow, not a hard proof of residence.

= What happens if geolocation fails? =

The plugin falls back to a configurable default country (US by default). You can change this in **Memberships > GeoPrice**.

= My site is behind Cloudflare / a load balancer. How do I configure IP detection? =

Go to **Memberships > GeoPrice** and change the **IP Detection Method** to match your infrastructure (Cloudflare, X-Forwarded-For, or X-Real-IP). The default (REMOTE_ADDR) works for sites with a direct connection.

= How often are exchange rates updated? =

Rates are refreshed once daily via WordPress cron. You can also click **Refresh Rates Now** on the settings page for an immediate update.

= Does this plugin work with all PMPro payment gateways? =

GeoPrice's pricing layer runs through PMPro's checkout-level filters, but the strongest checkout protections in this release are focused on Stripe. If you use another gateway, test country collection and checkout pricing carefully before relying on country-based pricing in production.

= What data is cleaned up when I delete the plugin? =

All plugin settings, cached exchange rates, geolocation transients, per-level pricing data, and scheduled cron events are removed from the database when you delete the plugin through the WordPress admin.

== Screenshots ==

1. Global settings page under Memberships > GeoPrice.
2. Per-country pricing table on the membership level edit page.
3. Frontend display showing converted local currency prices.

== Changelog ==

= 1.0.0 =
* Initial release.
* Per-country pricing for PMPro membership levels.
* IP geolocation via ipapi.co (HTTPS) with ip-api.com fallback.
* Exchange rate fetching from ExchangeRate-API or Open Exchange Rates.
* Two-tier pricing: display uses IP geolocation, checkout uses billing country.
* Stripe-aware checkout sync for billing-country pricing, live checkout price refreshes, and manual-review mismatch logging.
* Admin testing override via URL parameter.
* Configurable IP detection for proxy/CDN environments.
* Full data cleanup on plugin deletion.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
