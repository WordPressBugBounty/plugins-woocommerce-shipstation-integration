<?php
/**
 * Checkout Rates Request Builder class file.
 *
 * @package WC_ShipStation
 * @since 4.9.8
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

use WC_Product;
use WooCommerce\Shipping\ShipStation\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the ShipStation checkout rates API request payload from a WC shipping package.
 *
 * @since 4.9.8
 */
final class Checkout_Rates_Request_Builder {

	/**
	 * Build the rates request payload from a WooCommerce shipping package.
	 *
	 * Returns an array under a `rate` key containing `destination` and `items`.
	 * The `connection_key` is not included — it is injected by the caller.
	 *
	 * @since 4.9.8
	 *
	 * @param array $package WooCommerce shipping package.
	 *
	 * @return array Rates request payload.
	 */
	public function build( array $package ) {
		$destination = isset( $package['destination'] ) ? $package['destination'] : array();

		$payload = array(
			'rate' => array(
				'destination' => $this->build_destination( $destination ),
				'items'       => $this->build_items( $package ),
			),
		);

		/**
		 * Filters the final checkout rates request payload before it is returned.
		 *
		 * @since 5.0.4
		 *
		 * @param array $payload The outbound checkout rates payload.
		 * @param array $package The WooCommerce shipping package.
		 */
		return apply_filters( 'woocommerce_shipstation_checkout_rates_request', $payload, $package );
	}

	/**
	 * Build the destination portion of the payload.
	 *
	 * @param array $destination WC package destination array.
	 *
	 * @return array
	 */
	private function build_destination( array $destination ) {
		/**
		 * Filters the checkout rates address type before it is set.
		 *
		 * @since 5.0.4
		 *
		 * @param string $address_type Default address type ('residential').
		 * @param array  $destination  The raw WC package destination array.
		 */
		$address_type = apply_filters(
			'woocommerce_shipstation_checkout_rates_address_type',
			'residential',
			$destination
		);

		$name  = $this->get_customer_name( $destination );
		$phone = $this->get_customer_phone( $destination );
		$email = $this->get_customer_email( $destination );

		$built_destination = array(
			'country'      => $destination['country'] ?? '',
			'postal_code'  => $destination['postcode'] ?? '',
			'province'     => $destination['state'] ?? '',
			'city'         => $destination['city'] ?? '',
			'address1'     => $destination['address'] ?? '',
			'address2'     => $destination['address_2'] ?? '',
			'address_type' => $address_type,
		);

		$address3 = isset( $destination['address_3'] ) && '' !== $destination['address_3']
			? $destination['address_3']
			: '';

		if ( '' !== $address3 ) {
			$built_destination['address3'] = $address3;
		}

		if ( '' !== $name ) {
			$built_destination['name'] = $name;
		}

		if ( '' !== $phone ) {
			$built_destination['phone'] = $phone;
		}

		if ( '' !== $email ) {
			$built_destination['email'] = $email;
		}

		/**
		 * Filters the checkout rates destination data before it is returned.
		 *
		 * @since 5.0.4
		 *
		 * @param array $built_destination The destination data.
		 * @param array $destination       The raw WC package destination array.
		 */
		return apply_filters( 'woocommerce_shipstation_checkout_rates_destination', $built_destination, $destination );
	}

	/**
	 * Build the items array from the package contents.
	 *
	 * @param array $package WooCommerce shipping package.
	 *
	 * @return array
	 */
	private function build_items( array $package ) {
		$contents = isset( $package['contents'] ) ? $package['contents'] : array();

		if ( empty( $contents ) ) {
			return array();
		}

		// Hoist store-wide values out of the loop — they don't vary per line item.
		$weight_unit    = get_option( 'woocommerce_weight_unit' );
		$currency       = get_woocommerce_currency();
		$price_decimals = wc_get_price_decimals();

		$items = array();
		foreach ( $contents as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			if ( ! $product->needs_shipping() ) {
				continue;
			}

			$weight = $product->get_weight();

			if ( '' === $weight || ! is_numeric( $weight ) ) {
				$weight = '0';
			} else {
				$weight = (string) $weight;
			}

			// PRD wire shape requires integer quantity; fractional WC quantities
			// (e.g. 0.5 yards of fabric) are floored to a minimum of 1. Unit price
			// is compensated by dividing line_total by the *raw* fractional
			// quantity, so per-unit price stays accurate. Weight is intentionally
			// NOT compensated — it is sent as the per-unit value and ShipStation
			// multiplies it by the integer quantity. This means a 0.5-yard cart
			// line ships as quantity=1 with full per-unit weight, slightly
			// overstating shipping weight for fractional items. Acceptable per
			// PRD, since fractional quantities are uncommon and the alternative
			// (inflating weight to match the truncated quantity ratio) violates
			// the per-unit weight contract carriers expect.
			$raw_quantity = isset( $item['quantity'] ) && is_numeric( $item['quantity'] )
				? (float) $item['quantity']
				: 0.0;
			$quantity     = max( 1, (int) $raw_quantity );

			$line_total = isset( $item['line_total'] ) && is_numeric( $item['line_total'] )
				? (float) $item['line_total']
				: 0.0;

			$unit_price = 0.0 < $raw_quantity
				? wc_format_decimal( $line_total / $raw_quantity, $price_decimals )
				: wc_format_decimal( 0, $price_decimals );

			$line_item = array(
				'name'     => $product->get_name(),
				'sku'      => $product->get_sku(),
				'quantity' => $quantity,
				'weight'   => array(
					'value' => $weight,
					'unit'  => $weight_unit,
				),
				'price'    => array(
					'amount'   => $unit_price,
					'currency' => $currency,
				),
			);

			$dimensions = $this->build_item_dimensions( $product );
			if ( ! empty( $dimensions ) ) {
				$line_item['dimensions'] = $dimensions;
			}

			/**
			 * Filters an individual checkout rates line item before it is added to the items array.
			 *
			 * @since 5.0.4
			 *
			 * @param array      $line_item The line item data.
			 * @param array      $package   The WooCommerce shipping package.
			 * @param WC_Product $product   The product object.
			 * @param array      $item      The raw cart item.
			 */
			$items[] = apply_filters( 'woocommerce_shipstation_checkout_rates_item', $line_item, $package, $product, $item );
		}

		/**
		 * Filters the checkout rates items array before it is returned.
		 *
		 * @since 5.0.4
		 *
		 * @param array $items   The items array.
		 * @param array $package The WooCommerce shipping package.
		 */
		return apply_filters( 'woocommerce_shipstation_checkout_rates_items', $items, $package );
	}

	/**
	 * Build the dimensions array for a product.
	 *
	 * Returns an empty array if any dimension is empty, non-numeric, zero, or negative.
	 *
	 * @param WC_Product $product WooCommerce product.
	 *
	 * @return array
	 */
	private function build_item_dimensions( WC_Product $product ) {
		$length = $product->get_length();
		$width  = $product->get_width();
		$height = $product->get_height();

		if ( '' === $length || '' === $width || '' === $height ) {
			return array();
		}

		if ( ! is_numeric( $length ) || ! is_numeric( $width ) || ! is_numeric( $height ) ) {
			return array();
		}

		if ( 0.0 >= (float) $length || 0.0 >= (float) $width || 0.0 >= (float) $height ) {
			return array();
		}

		return array(
			'length' => wc_format_decimal( $length, 2 ),
			'width'  => wc_format_decimal( $width, 2 ),
			'height' => wc_format_decimal( $height, 2 ),
			'unit'   => get_option( 'woocommerce_dimension_unit' ),
		);
	}

	/**
	 * Get the current WooCommerce customer object, if available.
	 *
	 * @return \WC_Customer|null
	 */
	private function get_customer() {
		if ( function_exists( 'WC' ) && WC() && WC()->customer ) {
			return WC()->customer;
		}

		return null;
	}

	/**
	 * Get customer name from destination or customer object.
	 *
	 * Accepts a partial destination name — either first_name or last_name (or both)
	 * is sufficient; trim collapses missing halves. Falls back to the customer
	 * object's shipping address, then billing address, when the destination yields
	 * nothing. Name is an optional API field, so all log entries are debug-level —
	 * absence and customer-object fallback are expected behavior, not actionable
	 * conditions. Debug entries provide a diagnostic trail for merchants who
	 * actively investigate why a name field is missing in their ShipStation requests.
	 *
	 * @param array $destination WC package destination array.
	 *
	 * @return string
	 */
	private function get_customer_name( array $destination ) {
		$first = '';
		$last  = '';

		if ( isset( $destination['first_name'] ) && is_string( $destination['first_name'] ) ) {
			$first = trim( $destination['first_name'] );
		}

		if ( '' === $first ) {
			Logger::debug( 'Checkout rates: destination first_name is empty.' );
		}

		if ( isset( $destination['last_name'] ) && is_string( $destination['last_name'] ) ) {
			$last = trim( $destination['last_name'] );
		}

		if ( '' === $last ) {
			Logger::debug( 'Checkout rates: destination last_name is empty.' );
		}

		$name = trim( $first . ' ' . $last );
		if ( '' !== $name ) {
			return $name;
		}

		$customer = $this->get_customer();

		if ( $customer ) {
			$name = trim( $customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name() );
			if ( '' !== $name ) {
				Logger::debug(
					'Checkout rates: destination first/last name was empty; falling back to the customer shipping address.'
				);
				return $name;
			}

			$name = trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() );
			if ( '' !== $name ) {
				Logger::debug(
					'Checkout rates: destination first/last name was empty; falling back to the customer billing address.'
				);
				return $name;
			}
		}

		Logger::debug(
			'Checkout rates: destination first/last name was empty and no customer name is available; the rates request will omit the name field.'
		);

		return '';
	}

	/**
	 * Get customer phone from destination or customer object.
	 *
	 * @param array $destination WC package destination array.
	 *
	 * @return string
	 */
	private function get_customer_phone( array $destination ) {
		$phone = '';

		if ( isset( $destination['phone'] ) && is_string( $destination['phone'] ) ) {
			$phone = trim( $destination['phone'] );
		}

		$customer = $this->get_customer();

		if ( '' === $phone && $customer ) {
			$phone = trim( $customer->get_billing_phone() );
		}

		return $phone;
	}

	/**
	 * Get customer email from destination or customer object.
	 *
	 * If the destination email is set but invalid, a warning is logged and the
	 * customer object's email is used as a fallback. If neither yields a valid
	 * email, an empty string is returned so the field is omitted from the payload.
	 *
	 * @param array $destination WC package destination array.
	 *
	 * @return string
	 */
	private function get_customer_email( array $destination ) {
		$destination_email_invalid = false;

		if ( isset( $destination['email'] ) && is_string( $destination['email'] ) && '' !== trim( $destination['email'] ) ) {
			$email = sanitize_email( $destination['email'] );
			if ( is_email( $email ) ) {
				return $email;
			}
			$destination_email_invalid = true;
		}

		$customer = $this->get_customer();
		if ( $customer ) {
			$email = $customer->get_email();
			if ( is_email( $email ) ) {
				if ( $destination_email_invalid ) {
					Logger::warning(
						'Checkout rates: destination email was invalid; falling back to the customer account email.'
					);
				}
				return $email;
			}
		}

		if ( $destination_email_invalid ) {
			Logger::warning(
				'Checkout rates: destination email was invalid and no customer account email is available; the rates request will omit the email field.'
			);
		}

		return '';
	}
}
