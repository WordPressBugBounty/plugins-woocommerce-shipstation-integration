<?php
/**
 * Prepaid duties fee on the cart.
 *
 * @package WC_ShipStation
 * @since 5.4.1
 */

namespace WooCommerce\Shipping\ShipStation\GPDT;

use WC_Cart;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Shipping_Rate;
use WooCommerce\Shipping\ShipStation\Checkout\Checkout_Rates_Options;
use WooCommerce\Shipping\ShipStation\Logger;
use WooCommerce\Shipping\ShipStation\Order_Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the duties of the chosen GPDT rates to the cart as one non-taxable fee.
 *
 * The GPDT rate is charged as freight only, so this fee brings the shopper's total
 * back to the amount ShipStation quoted while store tax applies to the freight
 * alone. It reads the rate meta, never the feature flag, so a rate cached in the
 * shopper's session keeps its fee across a flag flip.
 *
 * @since 5.4.1
 */
final class Prepaid_Duties_Fee {

	/**
	 * Cart fee id, passed as $fee_key to woocommerce_checkout_create_order_fee_item.
	 * Order code finds the fee by it, never by its label.
	 *
	 * @since 5.4.1
	 */
	const FEE_ID = 'shipstation_prepaid_duties';

	/**
	 * Property on the cart fee object that holds the chosen rates' GPDT rate ids.
	 *
	 * @since 5.4.1
	 */
	const RATE_IDS = 'gpdt_rate_ids';

	/**
	 * Property on the cart fee object that holds the cart currency when the fee was added.
	 *
	 * @since 5.4.1
	 */
	const CURRENCY = 'gpdt_currency';

	/**
	 * Transient prefix that throttles each warning to once an hour.
	 *
	 * @since 5.4.1
	 */
	const WARNING_TRANSIENT = 'wc_shipstation_prepaid_duties_fee_warned_';

	/**
	 * Subscriptions carts built from a saved order, which copy that order's fees.
	 *
	 * Each is the cart item key in woocommerce_adjust_order_fees_for_setup_cart_for_{$key}:
	 * paying a renewal, paying a pending initial order, and resubscribing.
	 *
	 * @since 5.4.1
	 */
	const ORDER_CART_TYPES = array( 'subscription_renewal', 'subscription_initial_payment', 'subscription_resubscribe' );

	/**
	 * Hook the fee into the cart totals and its order line into checkout.
	 *
	 * @since 5.4.1
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( self::class, 'add_fee' ) );
		add_action( 'woocommerce_checkout_create_order_fee_item', array( self::class, 'tag_order_fee' ), 10, 4 );
		foreach ( self::ORDER_CART_TYPES as $cart_type ) {
			add_action( 'woocommerce_adjust_order_fees_for_setup_cart_for_' . $cart_type, array( self::class, 'skip_order_fee' ) );
		}
		add_filter( 'woocommerce_subscriptions_is_recurring_fee', array( self::class, 'keep_in_order_cart' ), 10, 3 );
	}

	/**
	 * Remove the hooks added by register().
	 *
	 * @since 5.4.1
	 *
	 * @return void
	 */
	public static function unregister(): void {
		remove_action( 'woocommerce_cart_calculate_fees', array( self::class, 'add_fee' ) );
		remove_action( 'woocommerce_checkout_create_order_fee_item', array( self::class, 'tag_order_fee' ), 10 );
		foreach ( self::ORDER_CART_TYPES as $cart_type ) {
			remove_action( 'woocommerce_adjust_order_fees_for_setup_cart_for_' . $cart_type, array( self::class, 'skip_order_fee' ) );
		}
		remove_filter( 'woocommerce_subscriptions_is_recurring_fee', array( self::class, 'keep_in_order_cart' ), 10 );
	}

	/**
	 * The fee label the shopper sees.
	 *
	 * @since 5.4.1
	 *
	 * @return string
	 */
	public static function get_label(): string {
		$default = __( 'Duties and Taxes', 'woocommerce-shipstation-integration' );

		/**
		 * Filters the label of the prepaid duties fee.
		 *
		 * @since 5.4.1
		 *
		 * @param string $label Fee label. Default "Duties and Taxes".
		 */
		$label = apply_filters( 'woocommerce_shipstation_prepaid_duties_fee_label', $default );

		return is_string( $label ) && '' !== $label ? $label : $default;
	}

	/**
	 * Add one fee for the duties of the cart's chosen GPDT rates.
	 *
	 * WC_Cart_Totals calculates shipping before fees, so the passed cart holds the
	 * chosen rate of each package.
	 *
	 * @since 5.4.1
	 *
	 * @param mixed $cart The cart WooCommerce is calculating.
	 *
	 * @return void
	 */
	public static function add_fee( $cart ): void {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		// A totals run without a cart reset, as Woo Tax makes, still holds the last
		// pass's fee; remove it so the fee follows this pass's rates. WC_Cart_Fees has
		// no single-fee removal, so set the other fees back. The Store API's early
		// calculate_fees() pass needs no removal: calculate_totals() clears it.
		$fees = $cart->fees_api()->get_fees();
		if ( isset( $fees[ self::FEE_ID ] ) ) {
			unset( $fees[ self::FEE_ID ] );
			$cart->fees_api()->set_fees( $fees );
		}

		$rates = self::get_gpdt_rates( $cart );
		if ( array() === $rates ) {
			return;
		}

		// Summed at the price decimals, because the stored strings do not add exactly as floats.
		$cents = 0;
		$ids   = array();
		foreach ( $rates as $rate ) {
			$meta   = $rate->get_meta_data();
			$cents += wc_add_number_precision( (float) $meta[ Checkout_Rates_Options::DUTIES_TAXES_META_KEY ] );
			$ids[]  = (string) $meta[ Checkout_Rates_Options::GPDT_RATE_ID_META_KEY ];
		}
		$duties = wc_remove_number_precision( $cents );

		self::warn_when_unconverted();

		/**
		 * Filters the prepaid duties fee amount before it is added to the cart.
		 *
		 * The duties are in the store base currency, and currency switchers convert
		 * shipping rates but not cart fees, so a switcher integration converts here.
		 * It can run more than once in a request (the Store API calculates the fees
		 * once before the totals), so keep callbacks cheap and free of side effects.
		 *
		 * @since 5.4.1
		 *
		 * @param float              $amount Summed duties of the chosen GPDT rates, in the store base currency. Return 0 to charge no fee.
		 * @param WC_Shipping_Rate[] $rates  The chosen GPDT rates, keyed by package position.
		 * @param WC_Cart            $cart   The cart being calculated.
		 */
		$filtered = apply_filters( 'woocommerce_shipstation_prepaid_duties_fee_amount', $duties, $rates, $cart );

		if ( ! is_numeric( $filtered ) || ! is_finite( (float) $filtered ) || (float) $filtered < 0 ) {
			self::warn_once(
				'amount',
				sprintf(
					'Prepaid duties fee: the filtered amount "%1$s" is not a number at or above zero, so the duties %2$s were not charged.',
					Order_Util::sanitize_for_log( self::describe_amount( $filtered ) ),
					wc_format_decimal( $duties, wc_get_price_decimals() )
				)
			);
			return;
		}

		// A converted amount can carry more decimals than the store charges.
		$amount = round( (float) $filtered, wc_get_price_decimals() );

		// Zero, or an amount that rounds to zero, is the store absorbing the duties.
		if ( $amount <= 0 ) {
			self::debug_safely(
				sprintf(
					'Prepaid duties fee: the filtered amount "%1$s" rounds to zero, so no fee was added for the duties %2$s.',
					Order_Util::sanitize_for_log( (string) $filtered ),
					wc_format_decimal( $duties, wc_get_price_decimals() )
				)
			);
			return;
		}

		$cart->fees_api()->add_fee(
			array(
				'id'           => self::FEE_ID,
				'name'         => self::get_label(),
				'amount'       => $amount,
				'taxable'      => false,
				self::RATE_IDS => $ids,
				// In the fee data, so a currency switch changes the Store API fee hash and rebuilds a draft's line.
				self::CURRENCY => get_woocommerce_currency(),
			)
		);
	}

	/**
	 * Tag the order line of the duties fee and store it as not taxable.
	 *
	 * Runs on woocommerce_checkout_create_order_fee_item, which classic checkout,
	 * the Store API and Subscriptions all fire, so subscriptions and their renewals
	 * carry the tag too. WooCommerce 11.0 and 11.1 store a non-taxable fee as
	 * taxable with tax class 0, so the status and class are set the way 11.2 sets
	 * them. The reference is the first GPDT rate id on the fee, the first package
	 * whose duties it charges.
	 *
	 * @since 5.4.1
	 *
	 * @param mixed $item    The order fee item being created.
	 * @param mixed $fee_key The cart fee id.
	 * @param mixed $fee     The cart fee object.
	 * @param mixed $order   The order or subscription the item is for.
	 *
	 * @return void
	 */
	public static function tag_order_fee( $item, $fee_key, $fee, $order ): void {
		if ( ! $item instanceof WC_Order_Item_Fee || ! $order instanceof WC_Order || self::FEE_ID !== $fee_key ) {
			return;
		}

		$ids = is_object( $fee ) && isset( $fee->{self::RATE_IDS} ) && is_array( $fee->{self::RATE_IDS} )
			? array_values( $fee->{self::RATE_IDS} )
			: array();

		// The cart's currency first: on a re-used Store API draft the order still holds the earlier request's.
		$currency = is_object( $fee ) && isset( $fee->{self::CURRENCY} ) && is_string( $fee->{self::CURRENCY} ) && '' !== $fee->{self::CURRENCY}
			? $fee->{self::CURRENCY}
			: $order->get_currency();
		$currency = '' !== $currency ? $currency : get_woocommerce_currency();

		$item->set_tax_status( 'none' );
		$item->set_tax_class( '' );
		$item->update_meta_data(
			Prepaid_Duties::META_KEY,
			array(
				'kind'      => 'combined',
				'amount'    => wc_format_decimal( $item->get_total(), wc_get_price_decimals() ),
				'currency'  => $currency,
				'provider'  => 'shipstation',
				'reference' => isset( $ids[0] ) && is_scalar( $ids[0] ) ? (string) $ids[0] : '',
			)
		);

		// Defensive: the Subscriptions switcher saves the item before this hook and writes it again with the subscription.
		if ( $item->get_id() > 0 ) {
			$item->save();
		}
	}

	/**
	 * Leave the duties fee out when Subscriptions copies a saved order's fees into the cart.
	 *
	 * Subscriptions copies the fees by name and amount, so the duties would sit
	 * untagged beside the fee add_fee() charges for the cart's own rate. The cart
	 * quotes again, so its fee is the one that matches the freight and rate id the
	 * order is rebuilt with. Subscriptions passes the order so callbacks can change
	 * its fees for the cart only and does not save it; the item stays on the
	 * stored order unless another callback saves this object.
	 *
	 * @since 5.4.1
	 *
	 * @param mixed $order The order or subscription whose fees are copied.
	 *
	 * @return void
	 */
	public static function skip_order_fee( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		foreach ( $order->get_fees() as $item_id => $item ) {
			if ( Prepaid_Duties::is_prepaid_duties_fee( $item ) ) {
				$order->remove_item( $item_id );
			}
		}
	}

	/**
	 * Keep the duties fee when Subscriptions clears the fees of a renewal cart.
	 *
	 * Subscriptions keeps a fee in that cart only if its name and amount match a fee
	 * on the renewal order, so a new quote would lose the duties. A recurring cart
	 * adds its own fee, so the fee is not copied into one.
	 *
	 * @since 5.4.1
	 *
	 * @param mixed $is_recurring Whether Subscriptions keeps the fee.
	 * @param mixed $fee          The cart fee.
	 * @param mixed $cart         The cart the fee is kept in.
	 *
	 * @return mixed
	 */
	public static function keep_in_order_cart( $is_recurring, $fee, $cart ) {
		if ( ! is_object( $fee ) || ! isset( $fee->id ) || self::FEE_ID !== $fee->id ) {
			return $is_recurring;
		}

		// Subscriptions also asks while copying the cart's fees into a recurring cart.
		if ( ! $cart instanceof WC_Cart || ! empty( $cart->recurring_cart_key ) ) {
			return $is_recurring;
		}

		return true;
	}

	/**
	 * The chosen ShipStation rates that carry a GPDT rate id and a duties amount.
	 *
	 * @param WC_Cart $cart The cart being calculated.
	 *
	 * @return WC_Shipping_Rate[] Keyed by package position, as WC_Cart::get_shipping_methods() keys them.
	 */
	private static function get_gpdt_rates( WC_Cart $cart ): array {
		// Null until the cart has calculated shipping once.
		$chosen = $cart->get_shipping_methods();
		if ( ! is_array( $chosen ) ) {
			return array();
		}

		$rates = array();
		foreach ( $chosen as $package_key => $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate || Checkout_Rates_Options::SHIPPING_METHOD_ID !== $rate->get_method_id() ) {
				continue;
			}

			if ( ! Checkout_Rates_Options::is_gpdt_rate_meta( $rate->get_meta_data() ) ) {
				continue;
			}

			$rates[ $package_key ] = $rate;
		}

		return $rates;
	}

	/**
	 * Warn when the store sells in another currency and nothing converts the fee.
	 *
	 * @return void
	 */
	private static function warn_when_unconverted(): void {
		$base    = get_option( 'woocommerce_currency' );
		$current = get_woocommerce_currency();

		// A callback on the amount filter counts as the conversion, whatever it does.
		if ( ! is_string( $base ) || ! is_string( $current ) || $current === $base || has_filter( 'woocommerce_shipstation_prepaid_duties_fee_amount' ) ) {
			return;
		}

		self::warn_once(
			'currency',
			sprintf(
				'Prepaid duties fee: the store sells in %1$s but the fee is charged in %2$s. Convert it on the woocommerce_shipstation_prepaid_duties_fee_amount filter.',
				Order_Util::sanitize_for_log( $current ),
				Order_Util::sanitize_for_log( $base )
			)
		);
	}

	/**
	 * Write a debug line without letting a failing log handler reach the cart.
	 *
	 * @param string $message Log message.
	 *
	 * @return void
	 */
	private static function debug_safely( string $message ): void {
		try {
			Logger::debug( $message );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * A filtered amount as text for the log.
	 *
	 * PHP 8.5 warns when NAN is converted to a string, so it is named instead.
	 *
	 * @param mixed $value The filtered amount.
	 *
	 * @return string
	 */
	private static function describe_amount( $value ): string {
		if ( is_float( $value ) && is_nan( $value ) ) {
			return 'NAN';
		}

		return is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : gettype( $value );
	}

	/**
	 * Log a warning once an hour per key; the repeats go to the debug level.
	 *
	 * A failing transient store or log handler must not reach the shopper's cart.
	 *
	 * @param string $key     Throttle key.
	 * @param string $message Log message.
	 *
	 * @return void
	 */
	private static function warn_once( string $key, string $message ): void {
		$transient = self::WARNING_TRANSIENT . $key;

		try {
			$warned = false !== get_transient( $transient );
		} catch ( \Throwable $e ) {
			$warned = false;
		}

		try {
			if ( $warned ) {
				Logger::debug( $message );
				return;
			}

			// Log before arming the throttle, so a failing transient store cannot lose the warning.
			Logger::warning( $message );
			set_transient( $transient, 1, HOUR_IN_SECONDS );
		} catch ( \Throwable $e ) {
			return;
		}
	}
}
