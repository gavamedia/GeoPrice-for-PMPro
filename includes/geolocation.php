<?php
/**
 * IP-based geolocation for GeoPrice for PMPro.
 *
 * @copyright 2024-2026 GAVAMEDIA Corporation (https://gavamedia.com)
 * @license   GPL-2.0-or-later
 *
 * This file handles detecting a website visitor's country from their IP address.
 * It is the bridge between "a visitor loaded a page" and "we know which country
 * they're in, so we can show them the right price."
 *
 * ==========================================================================
 * SECURITY MODEL
 * ==========================================================================
 *
 * KEY SECURITY PRINCIPLE:
 *   IP geolocation is used ONLY for DISPLAY purposes (showing approximate
 *   local currency prices). It is NOT trusted for checkout/billing pricing.
 *   At checkout time, GeoPrice prices from the billing country selected on
 *   the checkout form. See frontend.php's geoprice_checkout_level() for the
 *   enforcement logic.
 *
 * This separation means that even if an attacker spoofs their IP, forges
 * cookies, or manipulates headers, they can only change the DISPLAYED price
 * (which is approximate and informational). The actual charge is determined
 * by the billing address country submitted with their payment method.
 *
 * SECURITY DESIGN:
 *
 *   Display-only cookie:
 *     The cookie is ONLY used for display-side caching. It does not
 *     influence the checkout price. Even if spoofed, the worst case is the
 *     visitor sees a different currency preview — the actual charge uses
 *     the billing country from the payment form.
 *
 *   Strict IP source control:
 *     IP detection defaults to REMOTE_ADDR only. Proxy headers
 *     (X-Forwarded-For, X-Real-IP, CF-Connecting-IP) are only trusted
 *     when the admin explicitly configures a trusted header in settings.
 *     This prevents attackers from injecting fake IPs via HTTP headers
 *     on sites that aren't behind a reverse proxy.
 *
 *   HTTPS-first geolocation:
 *     Default geolocation provider is ipapi.co (HTTPS) rather than
 *     ip-api.com (HTTP-only free tier). ip-api.com is the fallback,
 *     not primary, to prevent man-in-the-middle interception.
 *
 *   Input validation:
 *     The ?geoprice_country= admin override validates against the known
 *     country list, consistent with cookie validation.
 *
 *   SameSite cookie attribute:
 *     Cookie uses PHP 7.3+ array syntax with SameSite=Strict to prevent
 *     cross-site request attacks from pre-setting the cookie.
 *
 *   SHA-256 cache keys:
 *     Transient cache keys use SHA-256 instead of MD5 for IP hashing.
 *
 * LOOKUP STRATEGY:
 *   1. Check for an admin override first (?geoprice_country=XX query param).
 *   2. Check for a cached result in a browser cookie (display-only optimization).
 *   3. Check for a cached result in a WordPress transient (server-side cache by IP).
 *   4. If no cache hit, call an external geolocation API to resolve the IP.
 *   5. Cache the result in both a transient (24h) and a cookie (24h).
 *   6. If all else fails, fall back to the default country from plugin settings.
 *
 * @package GeoPrice_For_PMPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the visitor's country code for DISPLAY purposes.
 *
 * IMPORTANT: This function is for display/preview only. It returns the best
 * guess of the visitor's country based on IP geolocation. The result is used
 * to show prices in the visitor's local currency on the levels page and
 * checkout page BEFORE the billing form is submitted.
 *
 * For the AUTHORITATIVE country used for actual billing, see
 * geoprice_get_checkout_country() in frontend.php, which uses the billing
 * address country from the payment form.
 *
 * @return string ISO 3166-1 alpha-2 country code (e.g., 'CA', 'US', 'GB').
 */
function geoprice_get_visitor_country() {
	/*
	 * STEP 1: Admin testing override.
	 *
	 * The override value is validated against the known country list to
	 * ensure consistency with cookie validation and prevent unexpected values
	 * from passing through the system. Only 'manage_options' users can use this.
	 */
	if ( current_user_can( 'manage_options' ) && ! empty( $_GET['geoprice_country'] ) ) {
		$override  = sanitize_text_field( wp_unslash( $_GET['geoprice_country'] ) );
		$countries = geoprice_get_all_countries();
		if ( isset( $countries[ $override ] ) ) {
			return $override;
		}
		/* Invalid country code in override — fall through to normal detection. */
	}

	/*
	 * STEP 2: Check the browser cookie cache.
	 *
	 * This cookie is used for display purposes only — it provides a fast,
	 * no-DB-hit way to show the right currency on subsequent page loads.
	 * The cookie is NOT trusted for checkout pricing. See frontend.php
	 * geoprice_get_checkout_country() — at checkout time, the billing
	 * country from the payment form is used instead, completely bypassing
	 * this cookie. So even if a visitor spoofs this cookie, they only
	 * change which currency PREVIEW they see, not what they're charged.
	 */
	if ( ! empty( $_COOKIE['geoprice_country'] ) ) {
		$cached    = sanitize_text_field( wp_unslash( $_COOKIE['geoprice_country'] ) );
		$countries = geoprice_get_all_countries();
		if ( isset( $countries[ $cached ] ) ) {
			return $cached;
		}
	}

	/*
	 * STEP 3: Get the visitor's IP and handle local development.
	 */
	$ip = geoprice_get_visitor_ip();
	if ( empty( $ip ) || in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
		return get_option( 'geoprice_default_country', 'US' );
	}

	/*
	 * STEP 4: Check the WordPress transient cache.
	 *
	 * Uses SHA-256 for IP hashing. SHA-256 is the modern standard and
	 * eliminates any theoretical collision risk where two different IPs
	 * could map to the same cache key.
	 */
	$cache_key = 'geoprice_geo_' . hash( 'sha256', $ip );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	/*
	 * STEP 5: No cache hit — call the external geolocation API.
	 */
	$country = geoprice_lookup_ip( $ip );

	if ( ! empty( $country ) ) {
		/*
		 * STEP 6a: Cache the result.
		 *
		 * Cookie uses PHP 7.3+ array syntax with SameSite=Strict. This
		 * prevents cross-site request forgery attacks from setting or sending
		 * this cookie via links from external sites.
		 *
		 * SameSite=Strict is used instead of Lax because this cookie should
		 * never need to be sent on cross-site navigations — it's only
		 * relevant when the visitor is actively browsing the membership site.
		 */
		set_transient( $cache_key, $country, DAY_IN_SECONDS );

		if ( ! headers_sent() ) {
			setcookie( 'geoprice_country', $country, array(
				'expires'  => time() + DAY_IN_SECONDS,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly'  => true,
				'samesite' => 'Strict',
			) );
		}

		return $country;
	}

	/*
	 * STEP 6b: All lookups failed — use the default country.
	 */
	return get_option( 'geoprice_default_country', 'US' );
}

/**
 * Route an IP lookup to the configured geolocation provider.
 *
 * ipapi.co (HTTPS) is the default provider instead of ip-api.com (HTTP-only
 * on free tier). ip-api.com is used as a fallback when ipapi.co is the primary.
 *
 * HTTPS is preferred because plain HTTP geolocation responses are vulnerable
 * to man-in-the-middle interception. An attacker on the network path could
 * return a fake country code. While this does not affect billing (billing uses
 * the card's country), it could cause incorrect currency display if exploited.
 *
 * @param string $ip The visitor's public IPv4 or IPv6 address.
 * @return string ISO 3166-1 alpha-2 country code, or empty string if lookup failed.
 */
function geoprice_lookup_ip( $ip ) {
	$provider = get_option( 'geoprice_geo_provider', 'ipapi' );

	switch ( $provider ) {
		case 'ip-api':
			/* Admin chose ip-api.com — use it with ipapi.co as fallback. */
			$result = geoprice_lookup_ip_api( $ip );
			if ( empty( $result ) ) {
				$result = geoprice_lookup_ipapi_co( $ip );
			}
			return $result;

		case 'ipapi':
		default:
			/*
			 * Default: ipapi.co (HTTPS) as primary, ip-api.com as fallback.
			 * HTTPS prevents man-in-the-middle interception of responses.
			 */
			$result = geoprice_lookup_ipapi_co( $ip );
			if ( empty( $result ) ) {
				$result = geoprice_lookup_ip_api( $ip );
			}
			return $result;
	}
}

/**
 * Look up a country code using the ip-api.com service.
 *
 * WARNING: The free tier uses HTTP only (no HTTPS). Responses can be
 * intercepted and modified by a man-in-the-middle attacker. This is
 * acceptable for display-only purposes (the billing country from the
 * payment form is used for actual charges), but ip-api.com should not
 * be the primary provider for security-conscious deployments.
 *
 * @param string $ip The IP address to look up.
 * @return string 2-letter country code on success, empty string on failure.
 */
function geoprice_lookup_ip_api( $ip ) {
	$url      = 'http://ip-api.com/json/' . urlencode( $ip ) . '?fields=status,countryCode';
	$response = wp_remote_get( $url, array( 'timeout' => 5 ) );

	if ( is_wp_error( $response ) ) {
		return '';
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! empty( $body['status'] ) && 'success' === $body['status'] && ! empty( $body['countryCode'] ) ) {
		$code = sanitize_text_field( $body['countryCode'] );
		/* Validate: must be exactly 2 alpha characters. */
		if ( strlen( $code ) === 2 && ctype_alpha( $code ) ) {
			return strtoupper( $code );
		}
	}

	return '';
}

/**
 * Look up a country code using the ipapi.co service (HTTPS).
 *
 * This is the recommended default provider because it uses HTTPS,
 * preventing man-in-the-middle attacks on the geolocation response.
 *
 * @param string $ip The IP address to look up.
 * @return string 2-letter country code on success, empty string on failure.
 */
function geoprice_lookup_ipapi_co( $ip ) {
	$url      = 'https://ipapi.co/' . urlencode( $ip ) . '/country/';
	$response = wp_remote_get( $url, array( 'timeout' => 5 ) );

	if ( is_wp_error( $response ) ) {
		return '';
	}

	$body = trim( wp_remote_retrieve_body( $response ) );

	if ( strlen( $body ) === 2 && ctype_alpha( $body ) ) {
		return strtoupper( sanitize_text_field( $body ) );
	}

	return '';
}

/**
 * Extract the visitor's real public IP address from the request.
 *
 * By default, ONLY trusts REMOTE_ADDR (the TCP connection IP, which
 * cannot be spoofed at the application layer). Proxy headers such as
 * X-Forwarded-For, X-Real-IP, and CF-Connecting-IP are ONLY trusted
 * when the admin explicitly configures a trusted header via the
 * 'geoprice_trusted_ip_header' setting.
 *
 * This prevents clients from injecting fake IPs via HTTP headers on
 * sites that aren't behind a reverse proxy. Without this restriction,
 * a spoofed IP could poison the geolocation transient cache, causing
 * incorrect currency display for real visitors sharing that IP.
 *
 * Configure the trusted header when the site is behind a known
 * reverse proxy or CDN:
 *   - Cloudflare:      Set to 'HTTP_CF_CONNECTING_IP'
 *   - Nginx proxy:     Set to 'HTTP_X_REAL_IP'
 *   - Load balancer:   Set to 'HTTP_X_FORWARDED_FOR'
 *   - Direct (no proxy): Leave as 'REMOTE_ADDR' (default)
 *
 * When a trusted header is configured, we read from that header first
 * and fall back to REMOTE_ADDR if the header is empty.
 *
 * @return string The visitor's IP address, or empty string if undetermined.
 */
function geoprice_get_visitor_ip() {
	/*
	 * Read the admin's configured trusted IP header.
	 *
	 * Default: 'REMOTE_ADDR' — the safest option, as it's the actual TCP
	 * connection source IP and cannot be spoofed by the client.
	 *
	 * When behind a proxy/CDN, the admin sets this to the header that the
	 * proxy populates with the real client IP. The proxy must be configured
	 * to strip/replace this header from incoming requests to prevent client
	 * spoofing (Cloudflare does this automatically for CF-Connecting-IP).
	 */
	$trusted_header = get_option( 'geoprice_trusted_ip_header', 'REMOTE_ADDR' );

	/*
	 * Whitelist of allowed header values to prevent the admin setting
	 * from being exploited if the option value is somehow corrupted.
	 */
	$allowed_headers = array(
		'REMOTE_ADDR',
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_REAL_IP',
	);

	if ( ! in_array( $trusted_header, $allowed_headers, true ) ) {
		$trusted_header = 'REMOTE_ADDR';
	}

	/*
	 * Try the trusted header first.
	 */
	if ( ! empty( $_SERVER[ $trusted_header ] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER[ $trusted_header ] ) );

		/*
		 * X-Forwarded-For format: "client_ip, proxy1_ip, proxy2_ip"
		 * Take the first IP (the original client).
		 */
		if ( strpos( $ip, ',' ) !== false ) {
			$ip = trim( explode( ',', $ip )[0] );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	/*
	 * Fallback to REMOTE_ADDR if the trusted header didn't work.
	 * This handles cases where the proxy is misconfigured or the header
	 * is empty on some requests.
	 */
	if ( 'REMOTE_ADDR' !== $trusted_header && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	}

	return '';
}
