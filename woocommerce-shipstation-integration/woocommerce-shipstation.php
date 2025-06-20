<?php
/**
 * Plugin Name: ShipStation for WooCommerce
 * Plugin URI: https://woocommerce.com/products/shipstation-integration/
 * Version: 4.7.1
 * Description: Ship your WooCommerce orders with confidence, save on top carriers, and automate your processes with ShipStation.
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Text Domain: woocommerce-shipstation-integration
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.7
 * Tested up to: 6.8
 * WC requires at least: 9.7
 * WC tested up to: 9.9
 *
 * @package WC_ShipStation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WooCommerce\Shipping\ShipStation\REST_API_Loader;

define( 'WC_SHIPSTATION_FILE', __FILE__ );
define( 'WC_SHIPSTATION_ABSPATH', trailingslashit( __DIR__ ) );

if ( ! defined( 'WC_SHIPSTATION_PLUGIN_DIR' ) ) {
	define( 'WC_SHIPSTATION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WC_SHIPSTATION_PLUGIN_URL' ) ) {
	define( 'WC_SHIPSTATION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * WooCommerce fallback notice.
 *
 * @since 4.1.26
 *
 * @return void
 */
function woocommerce_shipstation_missing_wc_notice() {
	/* translators: %s WC download URL link. */
	echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'Shipstation requires WooCommerce to be installed and active. You can download %s here.', 'woocommerce-shipstation-integration' ), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>' ) . '</strong></p></div>';
}

/**
 * Include shipstation class.
 *
 * @since 1.0.0
 */
function woocommerce_shipstation_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'woocommerce_shipstation_missing_wc_notice' );

		return;
	}

	define( 'WC_SHIPSTATION_VERSION', '4.7.1' ); // WRCS: DEFINED_VERSION.

	if ( ! defined( 'WC_SHIPSTATION_EXPORT_LIMIT' ) ) {
		define( 'WC_SHIPSTATION_EXPORT_LIMIT', 100 );
	}

	woocommerce_shipstation_includes();

	add_action( 'before_woocommerce_init', 'woocommerce_shipstation_before_woocommerce_init' );
	add_action( 'after_setup_theme', 'woocommerce_shipstation_load_textdomain' );
	add_action( 'woocommerce_init', 'woocommerce_shipstation_load_rest_api' );
}

add_action( 'plugins_loaded', 'woocommerce_shipstation_init' );

/**
 * Run instances on time.
 *
 * @since 4.4.6
 */
function woocommerce_shipstation_before_woocommerce_init() {
	new WC_ShipStation_Privacy();
}

/**
 * Include needed files.
 *
 * @since 4.4.5
 */
function woocommerce_shipstation_includes() {
	// Include order util trait class file.
	require_once WC_SHIPSTATION_ABSPATH . 'includes/trait-woocommerce-order-util.php';
	include_once WC_SHIPSTATION_ABSPATH . 'includes/class-wc-shipstation-integration.php';
	include_once WC_SHIPSTATION_ABSPATH . 'includes/class-checkout.php';
	include_once WC_SHIPSTATION_ABSPATH . 'includes/class-wc-shipstation-privacy.php';
}

/**
 * Initialize REST API.
 *
 * @since 4.5.2
 */
function woocommerce_shipstation_load_rest_api() {
	// Load REST API loader class file.
	require_once WC_SHIPSTATION_ABSPATH . 'includes/class-rest-api-loader.php';

	// Initialize REST API.
	$rest_loader = new REST_API_Loader();
	$rest_loader->init();
}

/**
 * Localisation.
 *
 * @since 4.4.5
 */
function woocommerce_shipstation_load_textdomain() {
	load_plugin_textdomain( 'woocommerce-shipstation-integration', false, plugin_basename( WC_SHIPSTATION_ABSPATH ) . '/languages/' );
}

/**
 * Define integration.
 *
 * @since 1.0.0
 *
 * @param array $integrations Integrations.
 *
 * @return array Integrations.
 */
function woocommerce_shipstation_load_integration( $integrations ) {
	$integrations[] = 'WC_ShipStation_Integration';

	return $integrations;
}

add_filter( 'woocommerce_integrations', 'woocommerce_shipstation_load_integration' );

/**
 * Listen for API requests.
 *
 * @since 1.0.0
 */
function woocommerce_shipstation_api() {
	include_once WC_SHIPSTATION_ABSPATH . 'includes/class-wc-shipstation-api.php';
}

add_action( 'woocommerce_api_wc_shipstation', 'woocommerce_shipstation_api' );

/**
 * Added ShipStation custom plugin action links.
 *
 * @since 4.1.17
 * @version 4.1.17
 *
 * @param array $links Links.
 *
 * @return array Links.
 */
function woocommerce_shipstation_api_plugin_action_links( $links ) {
	$setting_link = admin_url( 'admin.php?page=wc-settings&tab=integration&section=shipstation' );
	$plugin_links = array(
		'<a href="' . $setting_link . '">' . __( 'Settings', 'woocommerce-shipstation-integration' ) . '</a>',
		'<a href="https://woocommerce.com/my-account/tickets">' . __( 'Support', 'woocommerce-shipstation-integration' ) . '</a>',
		'<a href="https://docs.woocommerce.com/document/shipstation-for-woocommerce/">' . __( 'Docs', 'woocommerce-shipstation-integration' ) . '</a>',
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( WC_SHIPSTATION_FILE ), 'woocommerce_shipstation_api_plugin_action_links' );

/**
 * Declaring HPOS compatibility.
 */
function woocommerce_shipstation_declare_hpos_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', 'woocommerce-shipstation/woocommerce-shipstation.php', true );
	}
}
add_action( 'before_woocommerce_init', 'woocommerce_shipstation_declare_hpos_compatibility' );
