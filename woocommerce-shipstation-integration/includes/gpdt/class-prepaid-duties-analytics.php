<?php
/**
 * Prepaid duties column in the Analytics Orders report.
 *
 * @package WC_ShipStation
 * @since 5.4.0
 */

namespace WooCommerce\Shipping\ShipStation\GPDT;

use Automattic\WooCommerce\Admin\PageController;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Refund;
use WP_REST_Response;
use WooCommerce\Shipping\ShipStation\Features;
use WooCommerce\Shipping\ShipStation\Logger;
use WooCommerce\Shipping\ShipStation\Order_Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a Prepaid duties column to the Analytics Orders report and its export.
 *
 * Analytics counts the prepaid duties fee in net sales, so the column gives the
 * merchant a figure to subtract. The amount is added to each row of the
 * report's REST response, which both export paths read: the server-side export
 * through the export filters, and the browser export, built from the on-screen
 * table, through the script this class enqueues.
 *
 * Refund rows show the duties the refund returned as a negative amount, as
 * Analytics does for net sales, so the column totals the same way.
 *
 * Only active while the prepaid duties and taxes feature is enabled
 * (Features::is_gpdt_enabled(), which requires Checkout Rates).
 *
 * @since 5.4.0
 */
final class Prepaid_Duties_Analytics {

	/**
	 * Report row field and export column ID.
	 */
	public const COLUMN = 'prepaid_duties';

	/**
	 * Handle of the script that adds the column to the on-screen table.
	 */
	public const SCRIPT_HANDLE = 'shipstation-analytics-prepaid-duties';

	/**
	 * Hook the column into the Orders report.
	 *
	 * @since 5.4.0
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'woocommerce_rest_prepare_report_orders', array( self::class, 'add_report_field' ), 10, 2 );
		add_filter( 'woocommerce_report_orders_export_columns', array( self::class, 'add_export_column' ) );
		add_filter( 'woocommerce_report_orders_prepare_export_item', array( self::class, 'add_export_value' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_script' ) );
	}

	/**
	 * Remove the hooks added by register().
	 *
	 * @since 5.4.0
	 *
	 * @return void
	 */
	public static function unregister(): void {
		remove_filter( 'woocommerce_rest_prepare_report_orders', array( self::class, 'add_report_field' ), 10 );
		remove_filter( 'woocommerce_report_orders_export_columns', array( self::class, 'add_export_column' ) );
		remove_filter( 'woocommerce_report_orders_prepare_export_item', array( self::class, 'add_export_value' ), 10 );
		remove_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_script' ) );
	}

	/**
	 * Add the prepaid duties amount to an Orders report row.
	 *
	 * @since 5.4.0
	 *
	 * @param mixed $response Report row response.
	 * @param mixed $report   Report row from the data store.
	 * @return mixed
	 */
	public static function add_report_field( $response, $report ) {
		if ( ! $response instanceof WP_REST_Response || ! is_array( $report ) || ! Features::is_gpdt_enabled() ) {
			return $response;
		}

		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$data[ self::COLUMN ] = self::get_row_amount( $report['order_id'] ?? 0 );
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Add the Prepaid duties column to the export headers.
	 *
	 * @since 5.4.0
	 *
	 * @param mixed $columns Column ID => label.
	 * @return mixed
	 */
	public static function add_export_column( $columns ) {
		if ( ! is_array( $columns ) || ! Features::is_gpdt_enabled() ) {
			return $columns;
		}

		$columns[ self::COLUMN ] = self::get_label();

		return $columns;
	}

	/**
	 * Add the prepaid duties amount to an export row.
	 *
	 * @since 5.4.0
	 *
	 * @param mixed $export_item Column ID => value.
	 * @param mixed $item        Report row.
	 * @return mixed
	 */
	public static function add_export_value( $export_item, $item ) {
		if ( ! is_array( $export_item ) || ! is_array( $item ) || ! Features::is_gpdt_enabled() ) {
			return $export_item;
		}

		// The row carries the amount from add_report_field() unless another
		// callback rebuilt the response, so read the order only as a fallback.
		$amount                      = $item[ self::COLUMN ] ?? null;
		$export_item[ self::COLUMN ] = is_numeric( $amount )
			? (float) $amount
			: self::get_row_amount( $item['order_id'] ?? 0 );

		return $export_item;
	}

	/**
	 * Enqueue the script that adds the column to the on-screen table, which is
	 * also what the browser export is built from.
	 *
	 * @since 5.4.0
	 *
	 * @return void
	 */
	public static function enqueue_script(): void {
		if ( ! class_exists( PageController::class ) || ! PageController::is_admin_page() || ! Features::is_gpdt_enabled() ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			WC_SHIPSTATION_PLUGIN_URL . 'assets/js/analytics-prepaid-duties' . $suffix . '.js',
			array( 'wp-hooks', 'wc-currency', 'wc-navigation' ),
			WC_SHIPSTATION_VERSION,
			true
		);

		// The label is translated here rather than in the script, so both
		// exports and the on-screen table share one string from the plugin's
		// PHP translations.
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'wcShipStationPrepaidDuties',
			array( 'label' => self::get_label() )
		);
	}

	/**
	 * Column label, shared by the export headers and the on-screen table.
	 *
	 * @return string
	 */
	private static function get_label(): string {
		return __( 'Prepaid duties', 'woocommerce-shipstation-integration' );
	}

	/**
	 * Prepaid duties for a report row: the charged amount on an order row, the
	 * returned amount as a negative on a refund row.
	 *
	 * Reading the amount runs foreign code: the fee total filters, and on a
	 * refund row the hydration of earlier refunds, which throws on a corrupted
	 * refund row (SHIPSTN-164). A failure shows 0 and is logged, so the report
	 * and its export still load.
	 *
	 * @param mixed $order_id Order or refund ID from the report row.
	 * @return float
	 */
	private static function get_row_amount( $order_id ): float {
		$order_id = absint( $order_id );

		try {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				return Prepaid_Duties::get_prepaid_duties( $order );
			}

			return $order instanceof WC_Order_Refund ? self::get_refund_amount( $order ) : 0.0;
		} catch ( \Throwable $e ) {
			$message = sprintf(
				'Prepaid duties for order or refund #%d in the Analytics Orders report could not be read; reporting none: %s: %s',
				$order_id,
				get_class( $e ),
				Order_Util::sanitize_for_log( (string) $e->getMessage() )
			);

			Order_Util::log_isolated(
				'the prepaid duties analytics log',
				static function () use ( $message ) {
					Logger::error( $message );
				}
			);

			return 0.0;
		}
	}

	/**
	 * Prepaid duties returned by a refund, as a negative amount.
	 *
	 * Splits the refund the way WooCommerce 11.1+ splits its Analytics row
	 * (Orders\Stats\DataStore::update()), so the duties line up with the row's
	 * net sales. A full refund takes the parent's duties less what earlier
	 * refunds returned; any other refund takes its own lines for a tagged fee.
	 * An amount-only partial refund has no such line and returns 0. WooCommerce
	 * 11.0 does not subtract earlier refunds from a full refund's net sales,
	 * so there the column still nets to 0 while net sales does not.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 * @return float
	 */
	private static function get_refund_amount( WC_Order_Refund $refund ): float {
		$parent_order = wc_get_order( $refund->get_parent_id() );
		if ( ! $parent_order instanceof WC_Order ) {
			return 0.0;
		}

		$fee_ids = array();
		foreach ( $parent_order->get_fees() as $fee ) {
			if ( $fee instanceof WC_Order_Item_Fee && 0 !== $fee->get_id() && Prepaid_Duties::is_prepaid_duties_fee( $fee ) ) {
				$fee_ids[] = $fee->get_id();
			}
		}

		if ( empty( $fee_ids ) ) {
			return 0.0;
		}

		if ( ! self::is_split_from_parent( $refund, $parent_order ) ) {
			return round( self::get_refunded_fee_total( $refund, $fee_ids ), wc_get_price_decimals() );
		}

		// Earlier refunds' lines are negative, so adding them reduces what
		// this refund returns.
		$amount = Prepaid_Duties::get_prepaid_duties( $parent_order );
		foreach ( $parent_order->get_refunds() as $prior_refund ) {
			if ( $prior_refund->get_id() !== $refund->get_id() ) {
				$amount += self::get_refunded_fee_total( $prior_refund, $fee_ids );
			}
		}

		return 0.0 - round( $amount, wc_get_price_decimals() );
	}

	/**
	 * Whether WooCommerce takes this refund's Analytics amounts from the parent
	 * order. Mirrors Orders\Stats\DataStore::update() and its
	 * should_split_full_refund_using_parent_order() on WooCommerce 11.1+. The
	 * amount is read when the report loads, while WooCommerce stores its row
	 * when the order syncs, so a row stored earlier (on 11.0, or before a
	 * refund was deleted) can differ until it is synced again.
	 *
	 * @param WC_Order_Refund $refund       Refund.
	 * @param WC_Order        $parent_order Parent order.
	 * @return bool
	 */
	private static function is_split_from_parent( WC_Order_Refund $refund, WC_Order $parent_order ): bool {
		if ( ! is_callable( array( OrderUtil::class, 'uses_new_full_refund_data' ) ) || ! OrderUtil::uses_new_full_refund_data() ) {
			return false;
		}

		if ( 'full' === $refund->get_meta( '_refund_type' ) ) {
			return true;
		}

		$items_sold = 0;
		foreach ( $refund->get_items() as $item ) {
			$items_sold += $item->get_quantity();
		}

		// The refund count is checked last: it hydrates every refund of the
		// order, so a corrupted sibling refund (SHIPSTN-164) only matters for
		// a refund that could be a lump sum for the whole order.
		return $items_sold <= 0
			&& wc_format_decimal( abs( (float) $refund->get_total() ) ) === wc_format_decimal( (float) $parent_order->get_total() )
			&& 1 === count( $parent_order->get_refunds() );
	}

	/**
	 * Total of a refund's lines for the given fees, excluding tax. Negative,
	 * as refund line totals are.
	 *
	 * @param WC_Order_Refund $refund  Refund.
	 * @param int[]           $fee_ids Parent fee item IDs.
	 * @return float
	 */
	private static function get_refunded_fee_total( WC_Order_Refund $refund, array $fee_ids ): float {
		$total = 0.0;

		foreach ( $refund->get_fees() as $item ) {
			if ( in_array( absint( $item->get_meta( '_refunded_item_id' ) ), $fee_ids, true ) ) {
				$total += (float) $item->get_total();
			}
		}

		return $total;
	}
}
