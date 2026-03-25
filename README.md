# GeoPrice for PMPro

Variable geographic pricing for [Paid Memberships Pro](https://www.paidmembershipspro.com/). Set country-specific membership prices in USD and display converted local currency amounts to visitors with 160+ currencies supported.

**Built by [GAVAMEDIA Corporation](https://gavamedia.com)**

---

## How It Works

1. **Configure prices** — On each PMPro membership level edit page, a "Geographic Pricing" section appears below Billing Details. Add countries via the searchable country picker and enter custom USD prices.
2. **Visitors see local prices** — The plugin detects the visitor's country via IP geolocation and displays prices converted to their local currency (e.g., CA$38.50, EUR26.78, JPY4,346) with an "(approximately)" label.
3. **Checkout charges the right amount** — At checkout, the billing country from the checkout form determines the actual USD price charged — not the IP-based estimate.

## Features

- **Per-country pricing** — Custom initial payment and recurring amounts for any of ~195 countries, per membership level
- **Two-tier pricing model** — IP geolocation for display only; billing country determines the actual charge (prevents location spoofing)
- **Automatic geolocation** — Free APIs, no key required ([ipapi.co](https://ipapi.co/) default, [ip-api.com](https://ip-api.com/) fallback)
- **160+ currencies** — Including zero-decimal currencies (JPY, KRW, etc.)
- **Modern country picker** — Searchable popup with flag emojis, sort by population or name, collapsible continent groups
- **Daily exchange rates** — Automatic refresh via WP-Cron with manual refresh option
- **Admin testing** — Append `?geoprice_country=CA` to any URL to preview pricing from any country
- **Proxy/CDN support** — Configurable IP detection for Cloudflare, Nginx, load balancers

## Requirements

- WordPress 5.8+
- PHP 7.4+
- [Paid Memberships Pro](https://wordpress.org/plugins/paid-memberships-pro/)

## Installation

1. Upload the `geoprice-for-pmpro` folder to `/wp-content/plugins/`
2. Activate the plugin in **Plugins > Installed Plugins**
3. Ensure Paid Memberships Pro is installed and active
4. Configure global settings at **Memberships > GeoPrice**
5. Edit any membership level and use the **Geographic Pricing** section to set per-country prices

## External Services

This plugin connects to third-party services for geolocation and exchange rates. No personal data is transmitted beyond the visitor's IP address (for geolocation).

| Service | Purpose | Privacy |
|---------|---------|---------|
| [ipapi.co](https://ipapi.co/) | IP geolocation (default) | [Privacy Policy](https://ipapi.co/privacy/) |
| [ip-api.com](https://ip-api.com/) | IP geolocation (alternate) | [Legal](https://ip-api.com/docs/legal) |
| [ExchangeRate-API](https://www.exchangerate-api.com/) | Exchange rates (default) | [Terms](https://www.exchangerate-api.com/terms) |
| [Open Exchange Rates](https://openexchangerates.org/) | Exchange rates (optional) | [Terms](https://openexchangerates.org/terms) |

## FAQ

**Do I need an API key?**
No. The defaults (ipapi.co + ExchangeRate-API) are free with no key. Open Exchange Rates requires a [free API key](https://openexchangerates.org/signup/free) if you prefer it.

**What currency are prices stored in?**
All prices are stored and charged in USD. Local currency amounts are approximate display conversions only.

**Can visitors spoof their location to get a different price?**
Spoofing an IP or cookie only changes the display currency. Checkout pricing is determined by the billing country on the payment form — not by geolocation.

**My site is behind Cloudflare. How do I configure it?**
Go to **Memberships > GeoPrice** and set **IP Detection Method** to "Cloudflare (CF-Connecting-IP)". This is safe and recommended for Cloudflare Tunnel setups.

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

---

Copyright 2024-2026 [GAVAMEDIA Corporation](https://gavamedia.com)
