<?php
/**
 * Frontend display and Stripe-aware checkout pricing for GeoPrice for PMPro.
 *
 * @copyright 2024-2026 GAVAMEDIA Corporation (https://gavamedia.com)
 * @license   GPL-2.0-or-later
 * @package   GeoPrice_For_PMPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Check whether GeoPrice is enabled.
 *
 * @return bool
 */
function geoprice_is_enabled() {
	return get_option( 'geoprice_enabled', '1' ) === '1';
}

/**
 * Check whether the current request should use checkout-country behavior.
 *
 * @return bool
 */
function geoprice_is_checkout_request() {
	if ( ! empty( $_REQUEST['geoprice_checkout_preview'] ) ) {
		return true;
	}

	return function_exists( 'pmpro_is_checkout' ) && pmpro_is_checkout();
}

/**
 * Check whether PMPro is currently using Stripe.
 *
 * @return bool
 */
function geoprice_is_stripe_gateway_active() {
	$gateway = function_exists( 'pmpro_getGateway' ) ? pmpro_getGateway() : get_option( 'pmpro_gateway' );
	return 'stripe' === $gateway;
}

/**
 * Validate and normalize a country code.
 *
 * @param string $country Country code to validate.
 * @return string
 */
function geoprice_validate_country_code( $country ) {
	if ( empty( $country ) ) {
		return '';
	}

	$country   = strtoupper( sanitize_text_field( wp_unslash( $country ) ) );
	$countries = geoprice_get_all_countries();

	return isset( $countries[ $country ] ) ? $country : '';
}

/**
 * Get the billing country currently selected in the checkout form.
 *
 * @return string
 */
function geoprice_get_checkout_form_country() {
	global $bcountry, $pmpro_default_country;

	if ( ! empty( $_REQUEST['bcountry'] ) ) {
		$country = geoprice_validate_country_code( $_REQUEST['bcountry'] );
		if ( ! empty( $country ) ) {
			return $country;
		}
	}

	if ( ! empty( $bcountry ) ) {
		$country = geoprice_validate_country_code( $bcountry );
		if ( ! empty( $country ) ) {
			return $country;
		}
	}

	if ( empty( $pmpro_default_country ) && function_exists( 'pmpro_get_default_country' ) ) {
		$pmpro_default_country = pmpro_get_default_country();
	}

	if ( ! empty( $pmpro_default_country ) ) {
		return geoprice_validate_country_code( $pmpro_default_country );
	}

	return '';
}

/**
 * Load billing/issuer country details from a Stripe PaymentMethod.
 *
 * @param string|null $payment_method_id Stripe PaymentMethod ID.
 * @param bool        $refresh           Whether to bypass the static cache.
 * @return array
 */
function geoprice_get_stripe_payment_method_country_data( $payment_method_id = null, $refresh = false ) {
	static $cache = array();

	if ( empty( $payment_method_id ) && ! empty( $_REQUEST['payment_method_id'] ) ) {
		$payment_method_id = sanitize_text_field( wp_unslash( $_REQUEST['payment_method_id'] ) );
	}

	if ( empty( $payment_method_id ) || ! geoprice_is_stripe_gateway_active() || ! class_exists( 'PMProGateway_stripe' ) ) {
		return array();
	}

	if ( ! $refresh && isset( $cache[ $payment_method_id ] ) ) {
		return $cache[ $payment_method_id ];
	}

	$cache[ $payment_method_id ] = array(
		'billing_country' => '',
		'card_country'    => '',
	);

	try {
		new PMProGateway_stripe();

		if ( ! class_exists( '\Stripe\PaymentMethod' ) ) {
			return $cache[ $payment_method_id ];
		}

		$payment_method = \Stripe\PaymentMethod::retrieve( $payment_method_id );

		if ( ! empty( $payment_method->billing_details->address->country ) ) {
			$cache[ $payment_method_id ]['billing_country'] = geoprice_validate_country_code( $payment_method->billing_details->address->country );
		}

		if ( ! empty( $payment_method->card->country ) ) {
			$cache[ $payment_method_id ]['card_country'] = geoprice_validate_country_code( $payment_method->card->country );
		}
	} catch ( \Throwable $e ) {
		$cache[ $payment_method_id ] = array(
			'billing_country' => '',
			'card_country'    => '',
		);
	}

	return $cache[ $payment_method_id ];
}

/**
 * Get the country context used for checkout pricing.
 *
 * On Stripe on-site checkouts we prefer the PaymentMethod billing country if
 * it is available. Otherwise we fall back to the checkout form country, and
 * only use geolocation as a preview fallback before the form is ready.
 *
 * @param bool $refresh Whether to bypass the static cache.
 * @return array
 */
function geoprice_get_checkout_country_context( $refresh = false ) {
	static $cache = null;

	if ( ! $refresh && null !== $cache ) {
		return $cache;
	}

	$submitted_country = geoprice_get_checkout_form_country();
	$stripe_data       = geoprice_get_stripe_payment_method_country_data( null, $refresh );
	$country           = '';
	$source            = 'visitor_geolocation_preview';

	if ( ! empty( $stripe_data['billing_country'] ) ) {
		$country = $stripe_data['billing_country'];
		$source  = 'stripe_payment_method_billing_country';
	} elseif ( ! empty( $submitted_country ) ) {
		$country = $submitted_country;
		$source  = 'checkout_form_country';
	} else {
		$country = geoprice_get_visitor_country();
	}

	$cache = array(
		'country'                => $country,
		'source'                 => $source,
		'submitted_country'      => $submitted_country,
		'stripe_billing_country' => empty( $stripe_data['billing_country'] ) ? '' : $stripe_data['billing_country'],
		'stripe_card_country'    => empty( $stripe_data['card_country'] ) ? '' : $stripe_data['card_country'],
	);

	return $cache;
}

/**
 * Get the country used for checkout pricing.
 *
 * @return string
 */
function geoprice_get_checkout_country() {
	$context = geoprice_get_checkout_country_context();
	return empty( $context['country'] ) ? '' : $context['country'];
}

/**
 * Look up the country-specific prices for a given membership level and country.
 *
 * @param int    $level_id     The PMPro membership level ID.
 * @param string $country_code ISO 3166-1 alpha-2 country code.
 * @return array|false
 */
function geoprice_get_country_price( $level_id, $country_code ) {
	static $cache = array();

	$level_id     = (int) $level_id;
	$country_code = strtoupper( $country_code );

	if ( ! isset( $cache[ $level_id ] ) ) {
		$cache[ $level_id ] = array();

		$meta = get_pmpro_membership_level_meta( $level_id, 'geoprice_prices', true );
		if ( ! empty( $meta ) ) {
			$decoded = json_decode( $meta, true );
			if ( is_array( $decoded ) ) {
				$cache[ $level_id ] = $decoded;
			}
		}
	}

	if ( ! isset( $cache[ $level_id ][ $country_code ] ) || ! is_array( $cache[ $level_id ][ $country_code ] ) ) {
		return false;
	}

	return $cache[ $level_id ][ $country_code ];
}

/**
 * Get the stored PMPro base level for a checkout level.
 *
 * @param int $level_id Level ID.
 * @return object|false
 */
function geoprice_get_base_level( $level_id ) {
	static $cache = array();

	$level_id = (int) $level_id;

	if ( ! isset( $cache[ $level_id ] ) ) {
		$cache[ $level_id ] = pmpro_getLevel( $level_id );
	}

	return $cache[ $level_id ];
}

/**
 * Apply PMPro's current pricing adjustment to the country-specific baseline.
 *
 * We preserve PMPro's already-prepared level object and only transform the two
 * GeoPrice-controlled money fields. When a discount code or another adjustment
 * has altered the base level amount, we apply the same relative change to the
 * country-specific amount.
 *
 * @param float $base_amount    Stored PMPro base amount.
 * @param float $adjusted_amount PMPro's current checkout amount.
 * @param float $country_amount Country-specific baseline amount.
 * @return float
 */
function geoprice_transform_adjusted_amount( $base_amount, $adjusted_amount, $country_amount ) {
	$base_amount     = (float) $base_amount;
	$adjusted_amount = (float) $adjusted_amount;
	$country_amount  = (float) $country_amount;

	if ( abs( $adjusted_amount - $base_amount ) < 0.00001 ) {
		return pmpro_round_price( $country_amount );
	}

	if ( $base_amount > 0 ) {
		return pmpro_round_price( max( 0, $country_amount * ( $adjusted_amount / $base_amount ) ) );
	}

	return pmpro_round_price( max( 0, $country_amount + ( $adjusted_amount - $base_amount ) ) );
}

/**
 * Apply GeoPrice's country pricing to a level object.
 *
 * @param object $level   PMPro level object.
 * @param string $country Country code.
 * @return object
 */
function geoprice_apply_country_pricing_to_level( $level, $country ) {
	if ( empty( $level->id ) || empty( $country ) ) {
		return $level;
	}

	$level->geoprice_country_adjusted = false;

	$country_price = geoprice_get_country_price( $level->id, $country );
	if ( false === $country_price ) {
		return $level;
	}

	$base_level = geoprice_get_base_level( $level->id );
	if ( empty( $base_level ) ) {
		return $level;
	}

	if ( isset( $country_price['initial_payment'] ) && '' !== (string) $country_price['initial_payment'] ) {
		$level->initial_payment            = geoprice_transform_adjusted_amount( $base_level->initial_payment, $level->initial_payment, $country_price['initial_payment'] );
		$level->geoprice_country_adjusted = true;
	}

	if ( isset( $country_price['billing_amount'] ) && '' !== (string) $country_price['billing_amount'] ) {
		$level->billing_amount             = geoprice_transform_adjusted_amount( $base_level->billing_amount, $level->billing_amount, $country_price['billing_amount'] );
		$level->geoprice_country_adjusted = true;
	}

	return $level;
}

/**
 * Override the level's country-priced amounts at checkout time.
 *
 * @param object $level The PMPro membership level object.
 * @return object
 */
function geoprice_checkout_level( $level ) {
	if ( ! geoprice_is_enabled() || empty( $level->id ) ) {
		return $level;
	}

	$context = geoprice_get_checkout_country_context();
	if ( empty( $context['country'] ) ) {
		return $level;
	}

	$level                         = geoprice_apply_country_pricing_to_level( $level, $context['country'] );
	$level->geoprice_country       = $context['country'];
	$level->geoprice_country_source = $context['source'];

	return $level;
}
add_filter( 'pmpro_checkout_level', 'geoprice_checkout_level', 999 );

/**
 * Get the country context used for frontend display.
 *
 * @return array
 */
function geoprice_get_display_country_context() {
	if ( geoprice_is_checkout_request() ) {
		return geoprice_get_checkout_country_context();
	}

	return array(
		'country' => geoprice_get_visitor_country(),
		'source'  => 'visitor_geolocation_preview',
	);
}

/**
 * Get the level object that should be displayed for a country.
 *
 * @param object $level   PMPro level object.
 * @param string $country Country code.
 * @return object
 */
function geoprice_get_display_level_for_country( $level, $country ) {
	$display_level = clone $level;

	if ( empty( $country ) || empty( $display_level->id ) ) {
		return $display_level;
	}

	if ( ! empty( $display_level->geoprice_country ) && $display_level->geoprice_country === $country ) {
		return $display_level;
	}

	return geoprice_apply_country_pricing_to_level( $display_level, $country );
}

/**
 * Convert a USD amount for display.
 *
 * @param float  $amount        USD amount.
 * @param string $currency_code Currency code.
 * @return float|false
 */
function geoprice_convert_amount_for_display( $amount, $currency_code ) {
	$amount = (float) $amount;

	if ( 'USD' === $currency_code ) {
		return $amount;
	}

	if ( 0.0 === $amount ) {
		return 0.0;
	}

	return geoprice_convert_usd_to_currency( $amount, $currency_code );
}

/**
 * DISPLAY: Replace PMPro's cost text with the visitor/checkout country's local
 * currency equivalent.
 *
 * @param string $text  The original cost text generated by PMPro.
 * @param object $level The membership level object.
 * @param bool   $tags  Whether to include HTML tags in the output.
 * @param bool   $short Whether to use short format.
 * @return string
 */
function geoprice_filter_level_cost_text( $text, $level, $tags, $short ) {
	if ( is_admin() || ! geoprice_is_enabled() || empty( $level->id ) ) {
		return $text;
	}

	$country_context = geoprice_get_display_country_context();
	if ( empty( $country_context['country'] ) ) {
		return $text;
	}

	$display_level  = geoprice_get_display_level_for_country( $level, $country_context['country'] );
	$currency_code  = geoprice_get_country_currency( $country_context['country'] );
	$initial_local  = geoprice_convert_amount_for_display( $display_level->initial_payment, $currency_code );
	$billing_local  = geoprice_convert_amount_for_display( $display_level->billing_amount, $currency_code );
	$trial_local    = geoprice_convert_amount_for_display( $display_level->trial_amount, $currency_code );

	if ( false === $initial_local || false === $billing_local || false === $trial_local ) {
		return $text;
	}

	$local_text = geoprice_build_local_cost_text( $display_level, $initial_local, $billing_local, $trial_local, $currency_code, $tags, $short );
	if ( empty( $local_text ) ) {
		return $text;
	}

	if ( 'USD' === $currency_code ) {
		return $local_text;
	}

	$show_approx = get_option( 'geoprice_show_approx', '1' ) === '1';
	if ( ! $show_approx ) {
		return $local_text;
	}

	if ( $tags ) {
		return '<span class="geoprice-cost">' . $local_text . ' <span class="geoprice-approx">(' . esc_html__( 'approximately', 'geoprice-for-pmpro' ) . ')</span></span>';
	}

	return $local_text . ' (' . __( 'approximately', 'geoprice-for-pmpro' ) . ')';
}
add_filter( 'pmpro_level_cost_text', 'geoprice_filter_level_cost_text', 20, 4 );

/**
 * Check whether a level is free using the display amounts being rendered.
 *
 * @param float $initial_amount Initial amount.
 * @param float $billing_amount Recurring amount.
 * @return bool
 */
function geoprice_is_level_free_for_display( $initial_amount, $billing_amount ) {
	return (float) $initial_amount <= 0 && (float) $billing_amount <= 0;
}

/**
 * Build a formatted cost text string in any given currency.
 *
 * Mirrors PMPro's pmpro_getLevelCost() output structure while formatting the
 * amounts in the visitor's display currency.
 *
 * @param object $level         The PMPro level object.
 * @param float  $initial_local Initial payment in display currency.
 * @param float  $billing_local Recurring amount in display currency.
 * @param float  $trial_local   Trial amount in display currency.
 * @param string $currency_code ISO 4217 currency code.
 * @param bool   $tags          Whether to wrap amounts in HTML tags.
 * @param bool   $short         Whether to use short format.
 * @return string
 */
function geoprice_build_local_cost_text( $level, $initial_local, $billing_local, $trial_local, $currency_code, $tags, $short ) {
	$initial_formatted = geoprice_format_price( $initial_local, $currency_code );
	$billing_formatted = geoprice_format_price( $billing_local, $currency_code );
	$trial_formatted   = geoprice_format_price( $trial_local, $currency_code );

	if ( ! $short ) {
		$r = sprintf(
			__( 'The price for membership is <strong>%s</strong> now', 'geoprice-for-pmpro' ),
			$initial_formatted
		);
	} else {
		if ( geoprice_is_level_free_for_display( $initial_local, $billing_local ) ) {
			$r = '<strong>' . __( 'Free', 'geoprice-for-pmpro' ) . '</strong>';
		} else {
			$r = sprintf(
				__( '<strong>%s</strong> now', 'geoprice-for-pmpro' ),
				$initial_formatted
			);
		}
	}

	if ( (float) $billing_local > 0 ) {
		if ( (int) $level->billing_limit > 1 ) {
			if ( '1' === (string) $level->cycle_number ) {
				$r .= sprintf(
					__( ' and then <strong>%1$s per %2$s for %3$d more %4$s</strong>.', 'geoprice-for-pmpro' ),
					$billing_formatted,
					pmpro_translate_billing_period( $level->cycle_period ),
					(int) $level->billing_limit,
					pmpro_translate_billing_period( $level->cycle_period, $level->billing_limit )
				);
			} else {
				$r .= sprintf(
					__( ' and then <strong>%1$s every %2$d %3$s for %4$d more payments</strong>.', 'geoprice-for-pmpro' ),
					$billing_formatted,
					(int) $level->cycle_number,
					pmpro_translate_billing_period( $level->cycle_period, $level->cycle_number ),
					(int) $level->billing_limit
				);
			}
		} elseif ( 1 === (int) $level->billing_limit ) {
			$r .= sprintf(
				__( ' and then <strong>%1$s after %2$d %3$s</strong>.', 'geoprice-for-pmpro' ),
				$billing_formatted,
				(int) $level->cycle_number,
				pmpro_translate_billing_period( $level->cycle_period, $level->cycle_number )
			);
		} else {
			if ( abs( (float) $billing_local - (float) $initial_local ) < 0.00001 ) {
				if ( '1' === (string) $level->cycle_number ) {
					if ( ! $short ) {
						$r = sprintf(
							__( 'The price for membership is <strong>%1$s per %2$s</strong>.', 'geoprice-for-pmpro' ),
							$initial_formatted,
							pmpro_translate_billing_period( $level->cycle_period )
						);
					} else {
						$r = sprintf(
							__( '<strong>%1$s per %2$s</strong>.', 'geoprice-for-pmpro' ),
							$initial_formatted,
							pmpro_translate_billing_period( $level->cycle_period )
						);
					}
				} else {
					if ( ! $short ) {
						$r = sprintf(
							__( 'The price for membership is <strong>%1$s every %2$d %3$s</strong>.', 'geoprice-for-pmpro' ),
							$initial_formatted,
							(int) $level->cycle_number,
							pmpro_translate_billing_period( $level->cycle_period, $level->cycle_number )
						);
					} else {
						$r = sprintf(
							__( '<strong>%1$s every %2$d %3$s</strong>.', 'geoprice-for-pmpro' ),
							$initial_formatted,
							(int) $level->cycle_number,
							pmpro_translate_billing_period( $level->cycle_period, $level->cycle_number )
						);
					}
				}
			} else {
				if ( '1' === (string) $level->cycle_number ) {
					$r .= sprintf(
						__( ' and then <strong>%1$s per %2$s</strong>.', 'geoprice-for-pmpro' ),
						$billing_formatted,
						pmpro_translate_billing_period( $level->cycle_period )
					);
				} else {
					$r .= sprintf(
						__( ' and then <strong>%1$s every %2$d %3$s</strong>.', 'geoprice-for-pmpro' ),
						$billing_formatted,
						(int) $level->cycle_number,
						pmpro_translate_billing_period( $level->cycle_period, $level->cycle_number )
					);
				}
			}
		}
	} else {
		$r .= '.';
	}

	$r .= ' ';

	if ( ! empty( $level->trial_limit ) ) {
		if ( 0.0 === (float) $trial_local ) {
			if ( '1' === (string) $level->trial_limit ) {
				$r .= ' ' . __( 'After your initial payment, your first payment is Free.', 'geoprice-for-pmpro' );
			} else {
				$r .= ' ' . sprintf(
					__( 'After your initial payment, your first %d payments are Free.', 'geoprice-for-pmpro' ),
					(int) $level->trial_limit
				);
			}
		} else {
			if ( '1' === (string) $level->trial_limit ) {
				$r .= ' ' . sprintf(
					__( 'After your initial payment, your first payment will cost %s.', 'geoprice-for-pmpro' ),
					$trial_formatted
				);
			} else {
				$r .= ' ' . sprintf(
					__( 'After your initial payment, your first %1$d payments will cost %2$s.', 'geoprice-for-pmpro' ),
					(int) $level->trial_limit,
					$trial_formatted
				);
			}
		}
	}

	$tax_state = get_option( 'pmpro_tax_state' );
	$tax_rate  = get_option( 'pmpro_tax_rate' );

	if ( $tax_state && $tax_rate && ! geoprice_is_level_free_for_display( $initial_local, $billing_local ) ) {
		$r .= sprintf(
			__( 'Customers in %1$s will be charged %2$s%% tax.', 'geoprice-for-pmpro' ),
			$tax_state,
			round( $tax_rate * 100, 2 )
		);
	}

	if ( ! $tags ) {
		$r = strip_tags( $r );
	}

	return $r;
}

/**
 * Enforce Stripe-specific checkout settings that GeoPrice depends on.
 *
 * @param mixed $value Existing option value.
 * @return mixed
 */
function geoprice_force_stripe_billing_address_option( $value ) {
	if ( geoprice_is_enabled() && geoprice_is_stripe_gateway_active() ) {
		return '1';
	}

	return $value;
}
add_filter( 'option_pmpro_stripe_billingaddress', 'geoprice_force_stripe_billing_address_option', 999 );
add_filter( 'default_option_pmpro_stripe_billingaddress', 'geoprice_force_stripe_billing_address_option', 999 );

/**
 * Disable Stripe payment request buttons while GeoPrice is active.
 *
 * @param mixed $value Existing option value.
 * @return mixed
 */
function geoprice_force_stripe_payment_request_button_option( $value ) {
	if ( geoprice_is_enabled() && geoprice_is_stripe_gateway_active() ) {
		return '0';
	}

	return $value;
}
add_filter( 'option_pmpro_stripe_payment_request_button', 'geoprice_force_stripe_payment_request_button_option', 999 );
add_filter( 'default_option_pmpro_stripe_payment_request_button', 'geoprice_force_stripe_payment_request_button_option', 999 );

/**
 * Require billing address collection in Stripe Checkout while GeoPrice is active.
 *
 * @param mixed $value Existing option value.
 * @return mixed
 */
function geoprice_force_stripe_checkout_billing_address_option( $value ) {
	if ( geoprice_is_enabled() && geoprice_is_stripe_gateway_active() ) {
		return 'required';
	}

	return $value;
}
add_filter( 'option_pmpro_stripe_checkout_billing_address', 'geoprice_force_stripe_checkout_billing_address_option', 999 );
add_filter( 'default_option_pmpro_stripe_checkout_billing_address', 'geoprice_force_stripe_checkout_billing_address_option', 999 );

/**
 * Ask PMPro/Stripe to verify the collected billing address.
 *
 * @param bool $verify Existing setting.
 * @return bool
 */
function geoprice_force_stripe_address_verification( $verify ) {
	if ( geoprice_is_enabled() && geoprice_is_stripe_gateway_active() ) {
		return true;
	}

	return $verify;
}
add_filter( 'pmpro_stripe_verify_address', 'geoprice_force_stripe_address_verification', 999 );

/**
 * Always include billing address fields for Stripe checkouts.
 *
 * @param bool $include Whether PMPro should include billing fields.
 * @return bool
 */
function geoprice_include_billing_address_fields( $include ) {
	if ( geoprice_is_enabled() && geoprice_is_stripe_gateway_active() ) {
		return true;
	}

	return $include;
}
add_filter( 'pmpro_include_billing_address_fields', 'geoprice_include_billing_address_fields', 999 );

/**
 * Re-add required billing fields after PMPro Stripe removes them.
 *
 * @param array $fields Required billing fields.
 * @return array
 */
function geoprice_require_stripe_billing_fields( $fields ) {
	if ( ! geoprice_is_enabled() || ! geoprice_is_stripe_gateway_active() ) {
		return $fields;
	}

	$required_fields = array(
		'bfirstname',
		'blastname',
		'baddress1',
		'bcity',
		'bstate',
		'bzipcode',
		'bcountry',
	);

	foreach ( $required_fields as $field ) {
		if ( ! isset( $fields[ $field ] ) ) {
			$fields[ $field ] = '';
		}
	}

	return $fields;
}
add_filter( 'pmpro_required_billing_fields', 'geoprice_require_stripe_billing_fields', 999 );

/**
 * Mark the checkout country as a PMPro price-altering field.
 *
 * This lets PMPro's own Stripe JS refresh payment request totals whenever the
 * billing country changes.
 *
 * @param array  $classes Existing element classes.
 * @param string $element PMPro element key.
 * @return array
 */
function geoprice_add_price_altering_class_to_country_field( $classes, $element ) {
	if ( ! geoprice_is_enabled() || 'bcountry' !== $element ) {
		return $classes;
	}

	if ( ! in_array( 'pmpro_alter_price', $classes, true ) ) {
		$classes[] = 'pmpro_alter_price';
	}

	return $classes;
}
add_filter( 'pmpro_element_class', 'geoprice_add_price_altering_class_to_country_field', 20, 2 );

/**
 * Register the checkout preview REST route.
 *
 * @return void
 */
function geoprice_register_rest_routes() {
	register_rest_route(
		'geoprice/v1',
		'/checkout-preview',
		array(
			'methods'             => 'GET',
			'callback'            => 'geoprice_rest_checkout_preview',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'geoprice_register_rest_routes' );

/**
 * Render checkout pricing HTML for the live-preview endpoint.
 *
 * @param object      $checkout_level PMPro checkout level.
 * @param string|null $discount_code  Discount code string.
 * @return string
 */
function geoprice_render_checkout_level_cost_html( $checkout_level, $discount_code = null ) {
	$html = array();

	if ( ! empty( $discount_code ) && pmpro_checkDiscountCode( $discount_code ) ) {
		$html[] = '<p class="' . esc_attr( pmpro_get_element_class( 'pmpro_level_discount_applied' ) ) . '">';
		$html[] = sprintf(
			esc_html__( 'The %s code has been applied to your order.', 'paid-memberships-pro' ),
			'<span class="' . esc_attr( pmpro_get_element_class( 'pmpro_tag pmpro_tag-discount-code', 'pmpro_tag-discount-code' ) ) . '">' . esc_html( $discount_code ) . '</span>'
		);
		$html[] = '</p>';
	}

	$level_cost_text = pmpro_getLevelCost( $checkout_level );
	if ( ! empty( $level_cost_text ) ) {
		$html[] = '<div class="' . esc_attr( pmpro_get_element_class( 'pmpro_level_cost_text' ) ) . '">' . wpautop( $level_cost_text ) . '</div>';
	}

	$level_expiration_text = pmpro_getLevelExpiration( $checkout_level );
	if ( ! empty( $level_expiration_text ) ) {
		$html[] = '<div class="' . esc_attr( pmpro_get_element_class( 'pmpro_level_expiration_text' ) ) . '">' . wpautop( $level_expiration_text ) . '</div>';
	}

	return wp_kses_post( implode( "\n\n", array_filter( $html ) ) );
}

/**
 * Return updated checkout pricing HTML for the selected billing country.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function geoprice_rest_checkout_preview( WP_REST_Request $request ) {
	$params = $request->get_params();

	if ( isset( $params['pmpro_level'] ) ) {
		$level_id = (int) $params['pmpro_level'];
	} elseif ( isset( $params['level_id'] ) ) {
		$level_id = (int) $params['level_id'];
	} elseif ( isset( $params['level'] ) ) {
		$level_id = (int) $params['level'];
	} else {
		$level_id = 0;
	}

	if ( empty( $level_id ) ) {
		return new WP_REST_Response( array( 'message' => 'No level found.' ), 400 );
	}

	if ( isset( $params['pmpro_discount_code'] ) ) {
		$discount_code = sanitize_text_field( $params['pmpro_discount_code'] );
	} elseif ( isset( $params['discount_code'] ) ) {
		$discount_code = sanitize_text_field( $params['discount_code'] );
	} else {
		$discount_code = null;
	}

	$original_request = $_REQUEST;
	$_REQUEST         = array_merge( $_REQUEST, $params, array( 'geoprice_checkout_preview' => '1' ) );

	$country_context = geoprice_get_checkout_country_context( true );
	$checkout_level  = pmpro_getLevelAtCheckout( $level_id, $discount_code );
	$html            = empty( $checkout_level ) ? '' : geoprice_render_checkout_level_cost_html( $checkout_level, $discount_code );

	$_REQUEST = $original_request;

	return new WP_REST_Response(
		array(
			'country'        => empty( $country_context['country'] ) ? '' : $country_context['country'],
			'country_source' => empty( $country_context['source'] ) ? '' : $country_context['source'],
			'html'           => $html,
		)
	);
}

/**
 * Enqueue checkout-only frontend behavior.
 *
 * @return void
 */
function geoprice_enqueue_checkout_assets() {
	if ( is_admin() || ! geoprice_is_enabled() || ! geoprice_is_checkout_request() ) {
		return;
	}

	wp_enqueue_script(
		'geoprice-checkout',
		GEOPRICE_PMPRO_URL . 'assets/js/frontend.js',
		array( 'jquery' ),
		GEOPRICE_PMPRO_VERSION,
		true
	);

	wp_localize_script(
		'geoprice-checkout',
		'geopriceCheckout',
		array(
			'previewUrl' => rest_url( 'geoprice/v1/checkout-preview' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'geoprice_enqueue_checkout_assets', 20 );

/**
 * Add a short pricing note to the checkout page.
 *
 * @return void
 */
function geoprice_checkout_country_notice() {
	if ( ! geoprice_is_enabled() ) {
		return;
	}
	?>
	<p class="geoprice-checkout-note">
		<?php esc_html_e( 'Final checkout pricing uses the billing country selected below. Stripe issuer-country mismatches are logged for manual review.', 'geoprice-for-pmpro' ); ?>
	</p>
	<?php
}
add_action( 'pmpro_checkout_after_level_cost', 'geoprice_checkout_country_notice', 20 );

/**
 * Store a lightweight checkout-country audit trail on successful orders.
 *
 * @param int         $user_id User ID.
 * @param MemberOrder $morder  Order object.
 * @return void
 */
function geoprice_after_checkout_audit( $user_id, $morder ) {
	if ( ! geoprice_is_enabled() || empty( $morder->id ) ) {
		return;
	}

	$context = geoprice_get_checkout_country_context( true );
	if ( empty( $context['country'] ) ) {
		return;
	}

	if ( function_exists( 'update_pmpro_membership_order_meta' ) ) {
		update_pmpro_membership_order_meta(
			$morder->id,
			'geoprice_country_context',
			array(
				'pricing_country'        => $context['country'],
				'pricing_country_source' => $context['source'],
				'submitted_country'      => $context['submitted_country'],
				'stripe_billing_country' => $context['stripe_billing_country'],
				'stripe_card_country'    => $context['stripe_card_country'],
			)
		);
	}

	if ( ! method_exists( $morder, 'add_order_note' ) ) {
		return;
	}

	if ( ! empty( $context['stripe_billing_country'] ) && ! empty( $context['submitted_country'] ) && $context['stripe_billing_country'] !== $context['submitted_country'] ) {
		$morder->add_order_note(
			sprintf(
				'GeoPrice used Stripe billing country %1$s for pricing; the submitted checkout country was %2$s.',
				$context['stripe_billing_country'],
				$context['submitted_country']
			)
		);
	}

	if ( ! empty( $context['stripe_card_country'] ) && $context['country'] !== $context['stripe_card_country'] ) {
		$morder->add_order_note(
			sprintf(
				'GeoPrice pricing country was %1$s; Stripe reported card issuer country %2$s. Review manually if needed.',
				$context['country'],
				$context['stripe_card_country']
			)
		);
	}
}
add_action( 'pmpro_after_checkout', 'geoprice_after_checkout_audit', 20, 2 );

/**
 * Output minimal inline CSS for frontend GeoPrice elements.
 *
 * @return void
 */
function geoprice_frontend_styles() {
	if ( ! geoprice_is_enabled() ) {
		return;
	}
	?>
	<style>
		.geoprice-cost { display: inline; }
		.geoprice-approx {
			font-size: 0.85em;
			opacity: 0.7;
			font-style: italic;
		}
		.geoprice-checkout-note {
			margin: 0.75rem 0 0;
			font-size: 0.95em;
			opacity: 0.8;
		}
	</style>
	<?php
}
add_action( 'wp_head', 'geoprice_frontend_styles' );
