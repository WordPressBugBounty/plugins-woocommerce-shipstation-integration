<?php
/**
 * Class WC_ShipStation_Integration file.
 *
 * @package WC_ShipStation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WooCommerce\Shipping\ShipStation\Order_Util;
use Automattic\WooCommerce\Enums\OrderInternalStatus;
use WooCommerce\Shipping\ShipStation\Logger;
use WooCommerce\Shipping\ShipStation\Auth_Controller;

/**
 * WC_ShipStation_Integration Class
 */
class WC_ShipStation_Integration extends WC_Integration {

	/**
	 * Authorization key for ShipStation API.
	 *
	 * @var string
	 */
	public static $auth_key = null;

	/**
	 * Export statuses.
	 *
	 * @var array
	 */
	public static $export_statuses = array();

	/**
	 * Flag for logging feature.
	 * `true` means log feature is on.
	 *
	 * @var boolean
	 */
	public static $logging_enabled = true;

	/**
	 * Shipment status.
	 *
	 * @var string
	 */
	public static $shipped_status = null;

	/**
	 * Gift enable flag.
	 *
	 * @var boolean
	 */
	public static $gift_enabled = false;

	/**
	 * Status mapping.
	 *
	 * @var array
	 */
	public static $status_mapping = array();

	/**
	 * Status mapping mode: 'api' (ShipStation-driven) or 'plugin' (merchant-managed).
	 *
	 * @since 5.0.8
	 * @var string
	 */
	public static $status_mode = 'api';

	/**
	 * ShipStation status for awaiting payment.
	 *
	 * @var string
	 */
	public const AWAITING_PAYMENT_STATUS = 'AwaitingPayment';

	/**
	 * ShipStation status for awaiting shipment.
	 *
	 * @var string
	 */
	public const AWAITING_SHIPMENT_STATUS = 'AwaitingShipment';

	/**
	 * ShipStation status for on-hold.
	 *
	 * @var string
	 */
	public const ON_HOLD_STATUS = 'OnHold';

	/**
	 * ShipStation status for completed.
	 *
	 * @var string
	 */
	public const COMPLETED_STATUS = 'Completed';

	/**
	 * ShipStation status for Cancelled.
	 *
	 * @var string
	 */
	public const CANCELLED_STATUS = 'Cancelled';

	/**
	 * ShipStation status for Payment Cancelled.
	 *
	 * @var string
	 */
	public const PAYMENT_CANCELLED_STATUS = 'PaymentCancelled';

	/**
	 * ShipStation status for Payment Failed.
	 *
	 * @var string
	 */
	public const PAYMENT_FAILED_STATUS = 'PaymentFailed';

	/**
	 * ShipStation status for Paid.
	 *
	 * @var string
	 */
	public const PAID_STATUS = 'Paid';

	/**
	 * Status mapping mode: ShipStation owns the mappings and overwrites plugin-side values on every poll.
	 *
	 * @since 5.0.8
	 * @var string
	 */
	public const STATUS_MODE_API = 'api';

	/**
	 * Status mapping mode: plugin-side mappings are authoritative and ShipStation polls do not overwrite them.
	 *
	 * @since 5.0.8
	 * @var string
	 */
	public const STATUS_MODE_PLUGIN = 'plugin';

	/**
	 * Order meta keys.
	 *
	 * @var array
	 */
	public static array $order_meta_keys = array(
		'is_gift'      => 'shipstation_is_gift',
		'gift_message' => 'shipstation_gift_message',
	);

	/**
	 * WooCommerce status prefix.
	 *
	 * @var string
	 */
	public static $wc_status_prefix = 'wc-';

	/**
	 * WooCommerce core order statuses (prefixed with `wc-`).
	 *
	 * Used by maybe_save_status_mapping() to distinguish merchant-added custom
	 * statuses (which ShipStation's account-side mapping UI does not know about)
	 * from WC core statuses (which it does). Custom statuses are preserved across
	 * API-mode overwrites so a merchant's custom mapping is not silently dropped
	 * on the next ShipStation poll.
	 *
	 * @var string[]
	 */
	private const WC_CORE_ORDER_STATUSES = array(
		OrderInternalStatus::PENDING,
		OrderInternalStatus::PROCESSING,
		OrderInternalStatus::ON_HOLD,
		OrderInternalStatus::COMPLETED,
		OrderInternalStatus::CANCELLED,
		OrderInternalStatus::REFUNDED,
		OrderInternalStatus::FAILED,
	);

	/**
	 * Stores logger class.
	 *
	 * @var WC_Logger
	 */
	private static $log = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->id                 = 'shipstation';
		$this->method_title       = __( 'ShipStation', 'woocommerce-shipstation-integration' );
		$this->method_description = __( 'ShipStation allows you to retrieve &amp; manage orders, then print labels &amp; packing slips with ease.', 'woocommerce-shipstation-integration' );

		if ( ! get_option( 'woocommerce_shipstation_auth_key', false ) ) {
			update_option( 'woocommerce_shipstation_auth_key', $this->generate_key() );
		}

		// Initialize auth display functionality.
		$this->init_auth_display();

		// Load admin form.
		$this->init_form_fields();

		// Load settings.
		$this->init_settings();

		self::$auth_key        = get_option( 'woocommerce_shipstation_auth_key', false );
		self::$export_statuses = $this->get_option( 'export_statuses', array( OrderInternalStatus::PROCESSING, OrderInternalStatus::ON_HOLD, OrderInternalStatus::COMPLETED, OrderInternalStatus::CANCELLED ) );
		self::$logging_enabled = 'yes' === $this->get_option( 'logging_enabled', 'yes' );
		self::$shipped_status  = $this->get_option( 'shipped_status', OrderInternalStatus::COMPLETED );
		self::$gift_enabled    = 'yes' === $this->get_option( 'gift_enabled', 'no' );
		self::$status_mapping  = array(
			self::AWAITING_PAYMENT_STATUS  => $this->get_option( self::AWAITING_PAYMENT_STATUS . '_status' ),
			self::AWAITING_SHIPMENT_STATUS => $this->get_option( self::AWAITING_SHIPMENT_STATUS . '_status' ),
			self::ON_HOLD_STATUS           => $this->get_option( self::ON_HOLD_STATUS . '_status' ),
			self::COMPLETED_STATUS         => $this->get_option( self::COMPLETED_STATUS . '_status' ),
			self::CANCELLED_STATUS         => $this->get_option( self::CANCELLED_STATUS . '_status' ),
		);
		self::$status_mode     = $this->get_option( 'status_mode', self::STATUS_MODE_API );

		// Force saved .
		$this->settings['auth_key'] = self::$auth_key;

		// Hooks.
		add_action( 'woocommerce_update_options_integration_shipstation', array( $this, 'update_shipstation_options' ) );
		add_filter( 'woocommerce_subscriptions_renewal_order_meta_query', array( $this, 'subscriptions_renewal_order_meta_query' ), 10, 4 );
		add_action( 'wp_loaded', array( $this, 'hide_notices' ) );
		add_filter( 'woocommerce_translations_updates_for_woocommerce_shipstation_integration', '__return_true' );
		add_action( 'woocommerce_shipstation_get_orders_before_process_request', array( $this, 'maybe_update_api_mode' ), 10, 1 );
		add_action( 'woocommerce_shipstation_get_orders_before_process_request', array( $this, 'maybe_save_status_mapping' ), 15, 1 );

		$hide_notice               = get_option( 'wc_shipstation_hide_activate_notice', '' );
		$settings_notice_dismissed = get_user_meta( get_current_user_id(), 'dismissed_shipstation-setup_notice', true );

		// phpcs:ignore WordPress.WP.Capabilities.Unknown --- It's native capability from WooCommerce
		if ( current_user_can( 'manage_woocommerce' ) && ( 'yes' !== $hide_notice && ! $settings_notice_dismissed ) ) {
			if ( ! isset( $_GET['wc-shipstation-hide-notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended --- No need to use nonce as no DB operation
				add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
				add_action( 'admin_notices', array( $this, 'settings_notice' ) );
			}
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_settings_scripts' ) );
		add_filter( 'woocommerce_order_query_args', array( $this, 'add_custom_query_vars_for_hpos' ), 10, 1 );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'add_custom_query_vars_for_cpt' ), 10, 2 );
		add_filter( 'woocommerce_shipstation_diagnostics_controller_get_details', array( $this, 'add_more_diagnostics_details' ), 10 );
	}

	/**
	 * Refresh status mapping after being updated.
	 */
	public function refresh_status_mapping() {
		self::$status_mapping = array(
			self::AWAITING_PAYMENT_STATUS  => $this->get_option( self::AWAITING_PAYMENT_STATUS . '_status' ),
			self::AWAITING_SHIPMENT_STATUS => $this->get_option( self::AWAITING_SHIPMENT_STATUS . '_status' ),
			self::ON_HOLD_STATUS           => $this->get_option( self::ON_HOLD_STATUS . '_status' ),
			self::COMPLETED_STATUS         => $this->get_option( self::COMPLETED_STATUS . '_status' ),
			self::CANCELLED_STATUS         => $this->get_option( self::CANCELLED_STATUS . '_status' ),
		);
	}

	/**
	 * Update the status_mode option and keep the static cache in sync.
	 *
	 * @since 5.0.8
	 *
	 * @param string $value New status mode value. Must be one of self::STATUS_MODE_API or self::STATUS_MODE_PLUGIN.
	 */
	public function update_status_mode( $value ) {
		if ( ! in_array( $value, array( self::STATUS_MODE_API, self::STATUS_MODE_PLUGIN ), true ) ) {
			Logger::debug(
				'update_status_mode ignored invalid value',
				array( 'value' => (string) $value )
			);
			return;
		}
		$this->update_option( 'status_mode', $value );
		self::$status_mode = $value;
	}

	/**
	 * Build the description string for the export_statuses settings field.
	 *
	 * Renders two spans that the settings-page JS keeps in sync as the merchant
	 * toggles checkboxes:
	 *
	 *   #shipstation-excluded-statuses — informational list of statuses that
	 *                                    will NOT be exported (always shown).
	 *   #shipstation-unmapped-warning  — error notice listing statuses that ARE
	 *                                    selected for export but are missing
	 *                                    from every ShipStation→WC mapping slot.
	 *                                    Hidden when the list is empty.
	 *
	 * @return string HTML description (safe to pass to WC_Settings_API field — rendered via wp_kses_post).
	 */
	private function get_export_statuses_description(): string {
		$export_statuses = (array) $this->get_option( 'export_statuses', array() );

		$prefix     = self::$wc_status_prefix;
		$prefix_len = strlen( $prefix );
		$strip      = function ( $s ) use ( $prefix, $prefix_len ) {
			return ( 0 === strpos( $s, $prefix ) ) ? substr( $s, $prefix_len ) : $s;
		};

		$export_slugs = array_map( $strip, $export_statuses );

		$excluded_names = array();
		foreach ( wc_get_order_statuses() as $prefixed_slug => $label ) {
			$label_slug = $strip( $prefixed_slug );
			if ( ! in_array( $label_slug, $export_slugs, true ) ) {
				$excluded_names[] = esc_html( $label_slug );
			}
		}

		$base = esc_html__( 'Choose which order statuses to export to ShipStation.', 'woocommerce-shipstation-integration' )
			. '<br>' . esc_html__( 'Each selected status must also be mapped to a ShipStation status.', 'woocommerce-shipstation-integration' )
			. '<br>' . esc_html__( 'Mappings are managed below or in your ShipStation account (depending on your Status Mapping Mode).', 'woocommerce-shipstation-integration' );

		if ( empty( $excluded_names ) ) {
			$span_content = esc_html__( 'All statuses are selected for export.', 'woocommerce-shipstation-integration' );
		} else {
			$span_content = esc_html__( 'Excluded from export:', 'woocommerce-shipstation-integration' )
				. ' <strong>' . implode( ', ', $excluded_names ) . '</strong>';
		}

		$description = $base . '<br><span id="shipstation-excluded-statuses">' . $span_content . '</span>';

		$unmapped         = $this->get_unmapped_export_statuses();
		$unmapped_visible = ! empty( $unmapped );
		$unmapped_html    = $unmapped_visible
			? sprintf(
				/* translators: %s: comma-separated list of WC order status names */
				esc_html__( 'Selected for export but not mapped to a ShipStation status: %s. Map them below so their orders are exported correctly.', 'woocommerce-shipstation-integration' ),
				'<strong>' . esc_html( implode( ', ', $unmapped ) ) . '</strong>'
			)
			: '';

		$description .= '<span id="shipstation-unmapped-warning" class="shipstation-unmapped-warning notice notice-error inline'
			. ( $unmapped_visible ? ' is-visible' : '' )
			. '">' . $unmapped_html . '</span>';

		return $description;
	}

	/**
	 * Return display names of export_statuses entries missing from every mapping slot.
	 *
	 * Returns an empty array when:
	 *  - api_mode is XML (the mapping UI is hidden in legacy mode), or
	 *  - status_mode is 'api' (ShipStation owns the mappings — the merchant can't
	 *    resolve the mismatch from this screen, so surfacing it would be noise), or
	 *  - every selected export status appears in at least one mapping slot.
	 *
	 * Reads option values directly so it works both at field-render time (GET) and
	 * post-save time (after process_admin_options() has persisted new values).
	 *
	 * @return string[] Display-name labels (e.g. "Custom A"), or slugs as fallback.
	 */
	private function get_unmapped_export_statuses(): array {
		if ( 'REST' !== $this->get_option( 'api_mode', 'XML' ) ) {
			return array();
		}

		if ( self::STATUS_MODE_API === $this->get_option( 'status_mode', self::STATUS_MODE_API ) ) {
			return array();
		}

		$export_statuses = (array) $this->get_option( 'export_statuses', array() );
		if ( array() === $export_statuses ) {
			return array();
		}

		$prefix     = self::$wc_status_prefix;
		$prefix_len = strlen( $prefix );
		$strip      = function ( $s ) use ( $prefix, $prefix_len ) {
			return ( 0 === strpos( $s, $prefix ) ) ? substr( $s, $prefix_len ) : $s;
		};

		$mapped_slugs = array();
		foreach ( array(
			self::AWAITING_PAYMENT_STATUS,
			self::AWAITING_SHIPMENT_STATUS,
			self::ON_HOLD_STATUS,
			self::COMPLETED_STATUS,
			self::CANCELLED_STATUS,
		) as $ss_status ) {
			foreach ( (array) $this->get_option( $ss_status . '_status', array() ) as $s ) {
				$mapped_slugs[] = $strip( $s );
			}
		}

		$all_statuses   = wc_get_order_statuses();
		$unmapped_names = array();
		foreach ( $export_statuses as $raw ) {
			$slug = $strip( $raw );
			if ( in_array( $slug, $mapped_slugs, true ) ) {
				continue;
			}
			$prefixed         = $prefix . $slug;
			$unmapped_names[] = isset( $all_statuses[ $prefixed ] ) ? $all_statuses[ $prefixed ] : $slug;
		}

		return $unmapped_names;
	}

	/**
	 * Check that every status in export_statuses is covered by at least one mapping slot.
	 *
	 * Only runs in REST mode. Calls WC_Admin_Settings::add_error() when unmapped
	 * statuses are found so WooCommerce shows a red error box on the settings page.
	 * Because WC_Admin_Settings::show_messages() uses an `elseif` between errors and
	 * messages, adding an error here also suppresses the default "Your settings have
	 * been saved." success notice — no extra work needed.
	 *
	 * @return void
	 */
	private function validate_export_statuses_mapping(): void {
		$unmapped_names = $this->get_unmapped_export_statuses();
		if ( empty( $unmapped_names ) ) {
			return;
		}

		\WC_Admin_Settings::add_error(
			__( 'Settings saved, but some selected export statuses are not mapped to a ShipStation status. Please review the settings below.', 'woocommerce-shipstation-integration' )
		);
	}

	/**
	 * Get REST API setting fields.
	 * These fields will be available only if the REST API is being used instead of XML API.
	 *
	 * @return array
	 */
	public function get_rest_api_setting_fields() {
		return array(
			'api_mode',
			'status_mode',
			self::AWAITING_PAYMENT_STATUS . '_status',
			self::AWAITING_SHIPMENT_STATUS . '_status',
			self::ON_HOLD_STATUS . '_status',
			self::COMPLETED_STATUS . '_status',
			self::CANCELLED_STATUS . '_status',
		);
	}

	/**
	 * Update options for ShipStation settings.
	 *
	 * `WC_Integration::process_admin_options()` cannot be hooked directly because it
	 * returns a value and PHPStan rejects that for action callbacks.
	 *
	 * When Status Mapping Mode is "API" (`status_mode === 'api'`), the five status
	 * mapping <select> elements are HTML-disabled in the browser and therefore do
	 * not arrive in `$_POST`. WC's validate_*_field() helpers turn an absent key
	 * into `''`, so without intervention `process_admin_options()` would clear the
	 * stored mappings on every save. Pre-fill the missing keys from `get_option()`
	 * so the values round-trip unchanged, matching the merchant's expectation that
	 * saving while ShipStation owns the mappings is a no-op for those fields.
	 */
	public function update_shipstation_options() {
		$post_data = $this->get_post_data();
		$mode_key  = $this->get_field_key( 'status_mode' );
		$new_mode  = isset( $post_data[ $mode_key ] )
			? sanitize_key( wp_unslash( $post_data[ $mode_key ] ) )
			: $this->get_option( 'status_mode' );

		if ( self::STATUS_MODE_API === $new_mode ) {
			$status_field_keys = array(
				self::AWAITING_PAYMENT_STATUS . '_status',
				self::AWAITING_SHIPMENT_STATUS . '_status',
				self::ON_HOLD_STATUS . '_status',
				self::COMPLETED_STATUS . '_status',
				self::CANCELLED_STATUS . '_status',
			);

			foreach ( $status_field_keys as $key ) {
				$field_key = $this->get_field_key( $key );
				if ( isset( $post_data[ $field_key ] ) ) {
					continue;
				}
				$stored = $this->get_option( $key );
				if ( ! empty( $stored ) ) {
					$post_data[ $field_key ] = $stored;
				}
			}

			$this->set_post_data( $post_data );
		}

		$this->process_admin_options();

		// Refresh the export_statuses description so the same-request render after save
		// reflects the newly-saved option values. Avoids re-running the full
		// init_form_fields()/data-settings.php include just to refresh one string.
		$this->form_fields['export_statuses']['description'] = $this->get_export_statuses_description();

		$this->validate_export_statuses_mapping();
	}

	/**
	 * Initialize the authentication display functionality.
	 */
	private function init_auth_display() {
		if ( is_admin() && class_exists( 'WooCommerce\Shipping\ShipStation\Auth_Controller' ) ) {
			new Auth_Controller();
		}
	}

	/**
	 * Update the status mode and the status mapping fields if the plugin is a fresh installed.
	 *
	 * @param array $request_params Request parameters.
	 */
	public function maybe_update_status_mode( $request_params ) {
		// Need to separate the condition for optimization as `check_shipstation_has_exported_orders()` has more complex database calling.
		if ( ! empty( $this->get_option( 'status_mode', '' ) ) ) {
			return;
		}

		if ( $this->check_shipstation_has_exported_orders() && ! empty( $request_params['status_mapping'] ) ) {
			return;
		}

		$log_info = array();
		$this->update_status_mode( self::STATUS_MODE_PLUGIN );
		$log_info['status_mode'] = self::STATUS_MODE_PLUGIN;

		$shipstation_statuses = array_keys( self::$status_mapping );

		foreach ( $shipstation_statuses as $status ) {
			$this->update_option( $status . '_status', $this->form_fields[ $status . '_status' ]['default'] );
			$log_info[ $status . '_status' ] = $this->form_fields[ $status . '_status' ]['default'];
		}

		$this->refresh_status_mapping();

		Logger::debug( 'Mapping the status for fresh install', $log_info );
	}

	/**
	 * Check if the plugin has been used to export the orders.
	 *
	 * @return bool
	 */
	public function check_shipstation_has_exported_orders() {
		$orders = wc_get_orders(
			array(
				'shipstation_exported' => 1,
				'limit'                => 1,
				'orderby'              => 'modified',
				'order'                => 'DESC',
				'return'               => 'ids',
			)
		);

		return ( ! empty( $orders ) && is_array( $orders ) );
	}

	/**
	 * Update API Mode.
	 *
	 * @param array $request_params Request parameters.
	 */
	public function maybe_update_api_mode( $request_params ) {
		$api_mode = $this->get_option( 'api_mode', '' );

		if ( 'REST' === $api_mode ) {
			return;
		}

		$this->update_option( 'api_mode', 'REST' );
		$this->init_form_fields();
		$this->maybe_update_status_mode( $request_params );
	}

	/**
	 * Save status mapping.
	 *
	 * @param array $request_params Request parameter.
	 */
	public function maybe_save_status_mapping( $request_params ) {
		if ( empty( $request_params['status_mapping'] ) ) {
			return;
		}

		$mapping_mode = $this->get_option( 'status_mode', '' );

		if ( self::STATUS_MODE_PLUGIN === $mapping_mode ) {
			return;
		}

		$log_info       = array();
		$status_mapping = is_array( $request_params['status_mapping'] ) ? $request_params['status_mapping'] : array( $request_params['status_mapping'] );

		foreach ( $status_mapping as $status_parameter ) {
			$statuses = explode( ':', $status_parameter );

			if ( 2 !== count( $statuses ) ) {
				continue;
			}

			$wc_statuses = explode( ',', strtolower( $statuses[0] ) );
			$ss_status   = $statuses[1];

			if ( ! isset( $this->form_fields[ $ss_status . '_status' ] ) ) {
				continue;
			}

			$wc_statuses = array_map(
				function ( $status ) {
					return self::$wc_status_prefix . $status;
				},
				$wc_statuses
			);

			// Preserve merchant-configured custom (non-core) statuses that ShipStation's
			// payload omits. ShipStation's account-side mapping UI only knows about WC
			// core statuses, so any non-core status in the existing plugin-side option
			// was added by the merchant via this UI and must not be silently dropped on
			// every poll (SHIPSTN-122).
			$existing_wc_statuses = (array) $this->get_option( $ss_status . '_status', array() );
			$preserved_custom     = array_values(
				array_diff( $existing_wc_statuses, self::WC_CORE_ORDER_STATUSES, $wc_statuses )
			);
			$wc_statuses          = array_values( array_unique( array_merge( $wc_statuses, $preserved_custom ) ) );

			$this->update_option( $ss_status . '_status', $wc_statuses );
			$log_info[ $ss_status . '_status' ] = $wc_statuses;
			if ( ! empty( $preserved_custom ) ) {
				$log_info[ $ss_status . '_status_preserved_custom' ] = $preserved_custom;
			}
		}

		// Update the status mode only if the status_mode still empty.
		if ( empty( $mapping_mode ) ) {
			$this->update_status_mode( self::STATUS_MODE_PLUGIN );
			$log_info['status_mode'] = self::STATUS_MODE_PLUGIN;
		}

		$this->refresh_status_mapping();

		Logger::debug( 'Status has been mapped', $log_info );
	}

	/**
	 * Handle a custom variable query var to get orders with the 'order_number' meta for HPOS.
	 *
	 * @param array $query_vars - Query vars from WC_Order_Query.
	 *
	 * @return array modified $query_vars
	 */
	public function add_custom_query_vars_for_hpos( $query_vars ) {
		if ( ! Order_Util::custom_orders_table_usage_is_enabled() ) {
			return $query_vars;
		}

		if ( ! empty( $query_vars['wt_order_number'] ) ) {
			$query_vars['meta_query'][] = array(
				'key'   => '_order_number',
				'value' => esc_attr( $query_vars['wt_order_number'] ),
			);
		}

		if ( ! empty( $query_vars['shipstation_exported'] ) ) {
			$query_vars['meta_query'][] = array(
				'key'     => '_shipstation_exported',
				'compare' => 'EXISTS',
			);
		}

		return $query_vars;
	}

	/**
	 * Handle a custom variable query var to get orders with the 'order_number' meta for order post type.
	 *
	 * @param array $query      Main query of WC_Order_Query.
	 * @param array $query_vars Query vars from WC_Order_Query.
	 *
	 * @return array modified $query.
	 */
	public function add_custom_query_vars_for_cpt( $query, $query_vars ) {
		if ( ! empty( $query_vars['wt_order_number'] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_order_number',
				'value' => esc_attr( $query_vars['wt_order_number'] ),
			);
		}

		if ( ! empty( $query_vars['shipstation_exported'] ) ) {
			$query['meta_query'][] = array(
				'key'     => '_shipstation_exported',
				'compare' => 'EXISTS',
			);
		}

		return $query;
	}

	/**
	 * Add more information into diagnostic API results.
	 *
	 * @param array $site_info Site informations.
	 *
	 * @return array
	 */
	public function add_more_diagnostics_details( $site_info ) {
		$site_info['source_details']['status_mapping']      = self::$status_mapping;
		$site_info['source_details']['status_mapping_mode'] = $this->get_option( 'status_mode', self::STATUS_MODE_API );

		return $site_info;
	}

	/**
	 * Enqueue admin scripts/styles
	 */
	public function enqueue_scripts() {
		wp_enqueue_style( 'shipstation-admin', plugins_url( 'assets/css/admin.css', WC_SHIPSTATION_FILE ), array(), WC_SHIPSTATION_VERSION );
	}

	/**
	 * Enqueue scripts and styles for the ShipStation integration settings page.
	 *
	 * @since 5.0.8
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_admin_settings_scripts( $hook_suffix ) {
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab/section detection, no action taken.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab/section detection, no action taken.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		if ( 'integration' !== $tab || 'shipstation' !== $section ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script(
			'shipstation-admin-settings',
			WC_SHIPSTATION_PLUGIN_URL . 'assets/js/admin-settings' . $suffix . '.js',
			array( 'jquery' ),
			WC_SHIPSTATION_VERSION,
			true
		);

		wp_enqueue_style(
			'shipstation-admin',
			WC_SHIPSTATION_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WC_SHIPSTATION_VERSION
		);

		wp_localize_script(
			'shipstation-admin-settings',
			'wcShipStationSettings',
			array(
				'statusModeFieldId'     => 'woocommerce_shipstation_status_mode',
				'apiMode'               => self::STATUS_MODE_API,
				'apiModeFieldId'        => 'woocommerce_shipstation_api_mode',
				'restApiModeValue'      => 'REST',
				'exportStatusesFieldId' => 'woocommerce_shipstation_export_statuses',
				'unmappedWarningId'     => 'shipstation-unmapped-warning',
				'statusFieldIds'        => array(
					'woocommerce_shipstation_' . self::AWAITING_PAYMENT_STATUS . '_status',
					'woocommerce_shipstation_' . self::AWAITING_SHIPMENT_STATUS . '_status',
					'woocommerce_shipstation_' . self::ON_HOLD_STATUS . '_status',
					'woocommerce_shipstation_' . self::COMPLETED_STATUS . '_status',
					'woocommerce_shipstation_' . self::CANCELLED_STATUS . '_status',
				),
				'excludedStatusesLabel' => __( 'Excluded from export:', 'woocommerce-shipstation-integration' ),
				'allStatusesExported'   => __( 'All statuses are selected for export.', 'woocommerce-shipstation-integration' ),
				'unmappedWarningPrefix' => __( 'Selected for export but not mapped to a ShipStation status:', 'woocommerce-shipstation-integration' ),
				'unmappedWarningSuffix' => __( 'Map them below so their orders are exported correctly.', 'woocommerce-shipstation-integration' ),
			)
		);
	}

	/**
	 * Generate a key.
	 *
	 * @return string
	 */
	public function generate_key() {
		$to_hash = get_current_user_id() . wp_date( 'U' ) . wp_rand();
		return 'WCSS-' . hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
	}

	/**
	 * Init integration form fields
	 */
	public function init_form_fields() {
		$this->form_fields = include WC_SHIPSTATION_ABSPATH . 'includes/data/data-settings.php';
		$api_mode          = $this->get_option( 'api_mode', 'XML' );

		if ( 'REST' !== $api_mode ) {
			$rest_api_fields = $this->get_rest_api_setting_fields();

			foreach ( $rest_api_fields as $field ) {
				if ( isset( $this->form_fields[ $field ] ) ) {
					unset( $this->form_fields[ $field ] );
				}
			}
		}

		// If Checkout class does not exist, disable the gift option.
		if ( ! class_exists( 'WooCommerce\Shipping\ShipStation\Checkout' ) ) {
			$this->form_fields['gift_enabled']['custom_attributes'] = array( 'disabled' => 'disabled' );
			$this->form_fields['gift_enabled']['description']       = __( 'This feature requires WooCommerce 9.7.0 or higher.', 'woocommerce-shipstation-integration' );
		}

		$this->form_fields['export_statuses']['desc_tip']    = false;
		$this->form_fields['export_statuses']['description'] = $this->get_export_statuses_description();
	}

	/**
	 * Prevents WooCommerce Subscriptions from copying across certain meta keys to renewal orders.
	 *
	 * @param string $order_meta_query Order meta query.
	 * @param int    $original_order_id Original order ID.
	 * @param int    $renewal_order_id Order ID after being renewed.
	 * @param string $new_order_role New order role.
	 *
	 * @return array
	 */
	public function subscriptions_renewal_order_meta_query( $order_meta_query, $original_order_id, $renewal_order_id, $new_order_role ) {
		if ( 'parent' === $new_order_role ) {
			$order_meta_query .= ' AND `meta_key` NOT IN ('
							. "'_tracking_provider', "
							. "'_tracking_number', "
							. "'_date_shipped', "
							. "'_order_custtrackurl', "
							. "'_order_custcompname', "
							. "'_order_trackno', "
							. "'_order_trackurl' )";
		}
		return $order_meta_query;
	}

	/**
	 * Hides any admin notices.
	 *
	 * @since 4.1.37
	 * @return void
	 */
	public function hide_notices() {
		if ( isset( $_GET['wc-shipstation-hide-notice'] ) && isset( $_GET['_wc_shipstation_notice_nonce'] ) ) {
			if ( ! wp_verify_nonce( sanitize_key( $_GET['_wc_shipstation_notice_nonce'] ), 'wc_shipstation_hide_notices_nonce' ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, nonce is unslashed and verified.
				wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'woocommerce-shipstation-integration' ) );
			}

			// phpcs:ignore WordPress.WP.Capabilities.Unknown --- It's native capability from WooCommerce
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Cheatin&#8217; huh?', 'woocommerce-shipstation-integration' ) );
			}

			update_option( 'wc_shipstation_hide_activate_notice', 'yes' );
		}
	}

	/**
	 * Settings prompt
	 */
	public function settings_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended --- No need to use nonce as no DB operation
		if ( ! empty( $_GET['tab'] ) && 'integration' === $_GET['tab'] ) {
			return;
		}

		$current_screen = get_current_screen();

		if ( $current_screen instanceof WP_Screen && 'users' === $current_screen->id ) {
			return;
		}

		$logo_title = __( 'ShipStation logo', 'woocommerce-shipstation-integration' );
		?>
		<div class="notice notice-warning">
			<img class="shipstation-logo" alt="<?php echo esc_attr( $logo_title ); ?>" title="<?php echo esc_attr( $logo_title ); ?>" src="<?php echo esc_url( plugins_url( 'assets/images/shipstation-logo.svg', __DIR__ ) ); ?>" />
			<a class="woocommerce-message-close notice-dismiss woocommerce-shipstation-activation-notice-dismiss" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wc-shipstation-hide-notice', '' ), 'wc_shipstation_hide_notices_nonce', '_wc_shipstation_notice_nonce' ) ); ?>"></a>
			<p>
				<?php
				printf(
					wp_kses(
						/* translators: %s: ShipStation URL */
						__( 'To begin printing shipping labels with ShipStation head over to <a class="shipstation-external-link" href="%s" target="_blank">ShipStation.com</a> and log in or create a new account.', 'woocommerce-shipstation-integration' ),
						array(
							'a' => array(
								'class'  => array(),
								'href'   => array(),
								'target' => array(),
							),
						)
					),
					'https://www.shipstation.com/partners/woocommerce/?ref=partner-woocommerce'
				);
				?>
			</p>
			<p>
				<?php
				esc_html_e( 'After logging in, add WooCommerce as a selling channel in ShipStation. Use your store\'s Auth Key and REST API credentials to connect. Once connected you\'re good to go!', 'woocommerce-shipstation-integration' );
				?>
			</p>
			<p>
				<?php
				if ( class_exists( 'WooCommerce\Shipping\ShipStation\Auth_Controller' ) ) :
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo Auth_Controller::get_auth_button_html();
				endif;
				?>
			</p>
			<hr />
			<p>
				<?php
				printf(
					wp_kses(
						/* translators: %1$s: ShipStation plugin settings URL, %2$s: ShipStation documentation URL */
						__( 'You can find other settings for this extension <a href="%1$s">here</a> and view the documentation <a href="%2$s">here</a>.', 'woocommerce-shipstation-integration' ),
						array(
							'a' => array(
								'href' => array(),
							),
						)
					),
					esc_url( admin_url( 'admin.php?page=wc-settings&tab=integration&section=shipstation' ) ),
					'https://docs.woocommerce.com/document/shipstation-for-woocommerce/'
				);
				?>
			</p>
		</div>
		<?php
	}
}
