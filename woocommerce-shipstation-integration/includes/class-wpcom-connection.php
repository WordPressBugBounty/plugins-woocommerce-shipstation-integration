<?php
/**
 * WPCOM connection wrapper.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\Jetpack\Config as Jetpack_Config;
use Automattic\Jetpack\Connection\Manager as Jetpack_Connection_Manager;
use Automattic\Jetpack\Connection\Plugin_Storage as Jetpack_Plugin_Storage;
use Automattic\Jetpack\Connection\Rest_Authentication as Jetpack_Rest_Authentication;
use Jetpack_Options;

/**
 * Thin facade over Jetpack's Connection Manager.
 */
class WPCOM_Connection {

	/**
	 * Jetpack connection slug used to identify this plugin in the Connection Manager.
	 */
	const SLUG = 'woocommerce-shipstation';

	/**
	 * Human-readable plugin name registered with the Jetpack connection.
	 */
	const NAME = 'ShipStation for WooCommerce';

	/**
	 * Pending flow: merchant clicked "Connect to WordPress.com".
	 */
	const ACTION_CONNECT = 'connect';

	/**
	 * Pending flow: merchant clicked "Disconnect from WordPress.com".
	 */
	const ACTION_DISCONNECT = 'disconnect';

	/**
	 * Outcome flow: a disconnect was intentionally refused because other plugins
	 * still use the WordPress.com connection. A connection-respecting disconnect
	 * is a no-op in that case (see {@see disconnect()}), so this drives an
	 * informational notice — not the "could not disconnect" error — and steers
	 * the merchant to the transport toggle, the real lever for ShipStation.
	 */
	const ACTION_DISCONNECT_SHARED = 'disconnect_shared';

	/**
	 * Outcome flow: connect fresh was refused because another plugin still uses
	 * the WordPress.com connection. Resetting it would break that plugin, so the
	 * plain link only explains why; an administrator can use the separate reset
	 * action instead.
	 */
	const ACTION_CONNECT_FRESH_SHARED = 'connect_fresh_shared';

	/**
	 * Outcome flow: connect fresh or the forced reset tore the connection down,
	 * but registering the site again or building the authorize link failed. The
	 * site is left disconnected, so the notice gives the reason and says how to
	 * finish. The reason and, for the forced reset, the other plugin names are
	 * kept in {@see ACTION_DETAIL_TRANSIENT}.
	 */
	const ACTION_CONNECT_FRESH_FAILED = 'connect_fresh_failed';

	/**
	 * Jetpack connection-consumer slugs that do NOT count as "another plugin"
	 * when deciding whether a disconnect is safe.
	 *
	 * WooCommerce core registers slug 'woocommerce' on every store
	 * (class-woocommerce.php init_jetpack_connection_config(), unconditional), so
	 * counting it would make ShipStation never the sole consumer and suppress the
	 * disconnect control everywhere. It also re-registers harmlessly on the next
	 * load, so excluding it is safe. ShipStation's own slug is excluded for the
	 * same self-evident reason.
	 *
	 * @var string[]
	 */
	const BASELINE_CONNECTION_SLUGS = array( 'woocommerce' );

	/**
	 * Site option recording a pending disconnect *intent*. Disconnect is a
	 * two-step, guarded flow (SHIPSTN-142): clicking "Disconnect from
	 * WordPress.com" records this intent rather than tearing down the connection,
	 * because the proxy may be ShipStation's only working route. The real Jetpack
	 * disconnect runs only once a direct ShipStation connection is detected (the
	 * fallback signal) and the merchant confirms — or via the explicit
	 * "Dangerously disconnect" override. Presence of the option = a disconnect is
	 * pending; the value is the epoch the intent was recorded.
	 *
	 * @var string
	 */
	const DISCONNECT_INTENT_OPTION = 'woocommerce_shipstation_disconnect_intent';

	/**
	 * Site option recording the site URL that was registered with WordPress.com
	 * when the connection was established (SHIPSTN-142, slice 4 — stale-URL / IDC
	 * detection). It mirrors the `siteurl` that {@see Jetpack_Connection_Manager}
	 * `register()` sent to WordPress.com, which is the address WordPress.com
	 * forwards proxied ShipStation traffic to. If the site later moves (domain
	 * migration), `home_url()` changes but WordPress.com keeps delivering to this
	 * recorded URL — a Jetpack "identity crisis" that makes the proxy dead while
	 * `is_connected()` (local tokens only) still reports connected. Comparing this
	 * value's host to the current `home_url()` host is our detection signal.
	 * Stamped on connect/reconnect, cleared on disconnect.
	 *
	 * @var string
	 */
	const CONNECTED_URL_OPTION = 'woocommerce_shipstation_connected_url';

	/**
	 * URL status (connection_url_status()): the site is not WordPress.com-connected,
	 * so the URL check does not apply.
	 */
	const URL_STATUS_NOT_CONNECTED = 'not_connected';

	/**
	 * URL status (connection_url_status()): connected and the recorded URL host
	 * matches the current site host — the proxy route is sound.
	 */
	const URL_STATUS_OK = 'ok';

	/**
	 * URL status (connection_url_status()): connected but the recorded URL host
	 * differs from the current site host — a stale registration / identity crisis.
	 * The proxy is delivering to the old address and needs a repair (reconnect).
	 */
	const URL_STATUS_MISMATCH = 'mismatch';

	/**
	 * URL status (connection_url_status()): connected but no URL was ever recorded
	 * (a connection established before slice 4 shipped). Treated as OK; the next
	 * settings render backfills the stamp so future moves are detected.
	 */
	const URL_STATUS_UNKNOWN = 'unknown';

	/**
	 * Per-user transient carrying the pending action that kicked off the current flow.
	 * Cleared when the resulting notice is rendered or the TTL elapses.
	 */
	const ACTION_TRANSIENT = 'shipstation_wpcom_action';

	/**
	 * TTL for the pending-action transient. Short enough to bound the "stale notice
	 * on next admin visit" window, long enough to cover the WPCOM authorize round-trip.
	 */
	const ACTION_TRANSIENT_TTL = 90;

	/**
	 * Per-user transient carrying the details a pending action's notice needs
	 * (for {@see ACTION_CONNECT_FRESH_FAILED}: the reason and the other plugin
	 * names). Set and cleared together with {@see ACTION_TRANSIENT}.
	 */
	const ACTION_DETAIL_TRANSIENT = 'shipstation_wpcom_action_detail';

	/**
	 * Cached Jetpack Connection Manager instance.
	 *
	 * @var Jetpack_Connection_Manager|null
	 */
	private ?Jetpack_Connection_Manager $manager = null;

	/**
	 * Guards bootstrap() against double-registration of hooks.
	 *
	 * @var bool
	 */
	private bool $bootstrapped = false;

	/**
	 * Register the connection package with Jetpack and wire admin hooks.
	 * Idempotent: guarded by $bootstrapped so repeat calls don't double-register hooks.
	 */
	public function bootstrap(): void {
		if ( $this->bootstrapped || ! $this->is_jetpack_autoloader_ready() ) {
			return;
		}
		$this->bootstrapped = true;

		$config = new Jetpack_Config();
		$config->ensure(
			'connection',
			array(
				'slug' => self::SLUG,
				'name' => self::NAME,
			)
		);

		// Register the determine_current_user / rest_authentication_errors filters
		// that verify Jetpack-signed REST requests. No-op for requests without
		// `?_for=jetpack` and a token+signature, so it's safe to always init when
		// the WPCOM transport feature flag is on.
		Jetpack_Rest_Authentication::init();

		add_action( 'admin_post_shipstation_wpcom_connect', array( $this, 'handle_connect_action' ) );
		// Disconnect is a guarded, two-step intent flow (SHIPSTN-142): "disconnect"
		// records the intent, "disconnect_complete" performs the real Jetpack
		// disconnect (gated on a direct fallback signal unless forced), and
		// "disconnect_cancel" abandons a pending intent.
		add_action( 'admin_post_shipstation_wpcom_disconnect', array( $this, 'handle_disconnect_request' ) );
		add_action( 'admin_post_shipstation_wpcom_disconnect_complete', array( $this, 'handle_disconnect_complete' ) );
		add_action( 'admin_post_shipstation_wpcom_disconnect_cancel', array( $this, 'handle_disconnect_cancel' ) );
		// Slice 4: when the site address changes the connection is bound to a dead
		// URL. Keeping the same blog id across a domain change needs jetpack-sync
		// (declined for footprint), so the fix is to re-establish the connection as
		// a NEW site (new blog id / proxy URL). The local-only teardown leaves any
		// original site it was cloned from untouched.
		add_action( 'admin_post_shipstation_wpcom_connect_fresh', array( $this, 'handle_connect_fresh_action' ) );
		// Administrator-only reset for a moved site whose connection another plugin
		// also uses (SHIPSTN-262). Separate nonce action and manage_options.
		add_action( 'admin_post_shipstation_wpcom_connect_fresh_force', array( $this, 'handle_connect_fresh_force_action' ) );
		// Surgical: only intercept the Calypso post-authorize landing on
		// `?page=jetpack`. Other denied admin pages keep their normal
		// "Sorry, you are not allowed to access this page." wp_die.
		add_action( 'admin_page_access_denied', array( $this, 'maybe_redirect_post_authorize_landing' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_action( 'jetpack_client_authorize_error', array( $this, 'log_authorize_error' ) );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_wpcom_redirect_hosts' ) );
	}

	/**
	 * Bounce the merchant from `?page=jetpack` (the Calypso post-authorize
	 * landing) back to the ShipStation settings tab when our connect/disconnect
	 * action transient is set.
	 *
	 * Hooked on `admin_page_access_denied` so we run immediately before WP would
	 * otherwise wp_die with "Sorry, you are not allowed to access this page."
	 * (the Jetpack admin menu requires a cap that's only present when the
	 * Jetpack core plugin is installed).
	 *
	 * Tightened compared to maybe_redirect_to_settings(): only intercepts the
	 * specific `?page=jetpack` URL — any other denied admin page that happens
	 * to fire this hook during our 90s transient window is left to its normal
	 * wp_die so we don't yank merchants away from unrelated permission denials.
	 * (Network admin context is skipped inside maybe_redirect_to_settings(),
	 * so both this hook and the admin_init hook share that protection.)
	 */
	public function maybe_redirect_post_authorize_landing(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'jetpack' !== $page ) {
			return;
		}

		$this->maybe_redirect_to_settings();
	}

	/**
	 * Append the Jetpack/WPCOM hosts to allowed_redirect_hosts so wp_safe_redirect()
	 * accepts our outbound jump from handle_connect_action() to jetpack.wordpress.com.
	 *
	 * Mirrors the host list Jetpack itself adds inside Webhooks\Authorize_Redirect::handle().
	 *
	 * @param array $hosts Existing allowed redirect hosts.
	 * @return array Hosts with Jetpack/WPCOM/Calypso entries appended.
	 */
	public function allow_wpcom_redirect_hosts( $hosts ): array {
		$hosts   = is_array( $hosts ) ? $hosts : array();
		$hosts[] = 'jetpack.com';
		$hosts[] = 'jetpack.wordpress.com';
		$hosts[] = 'wordpress.com';
		$hosts[] = 'calypso.localhost';
		$hosts[] = 'wpcalypso.wordpress.com';
		$hosts[] = 'horizon.wordpress.com';

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Whether the site has a Jetpack connection to WPCOM.
	 *
	 * Requires both site-level (`is_connected`) and a connected owner
	 * (`has_connected_owner`) — matches WooCommerce Shipping's definition in
	 * classes/class-wc-connect-jetpack.php so we don't treat a half-completed
	 * registration (site token written, no user-token yet) as connected.
	 *
	 * @return bool True when Jetpack reports both a site connection and an owner.
	 */
	public function is_connected(): bool {
		$manager = $this->get_manager();
		return null !== $manager && $manager->is_connected() && $manager->has_connected_owner();
	}

	/**
	 * Return the site's WPCOM blog id, or null if the site isn't registered.
	 *
	 * @return int|null WPCOM blog id when known, otherwise null.
	 */
	public function get_blog_id(): ?int {
		if ( ! class_exists( Jetpack_Options::class ) ) {
			return null;
		}
		$id = Jetpack_Options::get_option( 'id' );
		return $id ? (int) $id : null;
	}

	/**
	 * Build the WordPress.com proxy URL ShipStation should use as the Store URL.
	 *
	 * @since 5.2.0
	 *
	 * @return string|null Proxy URL, or null when the site has no WPCOM blog id.
	 */
	public function get_proxy_url(): ?string {
		$blog_id = $this->get_blog_id();
		if ( null === $blog_id ) {
			return null;
		}

		return sprintf( 'https://public-api.wordpress.com/wpcom/v2/sites/%d/shipstation', $blog_id );
	}

	/**
	 * Build the Jetpack site-level authorize URL.
	 *
	 * Uses the default `auth_type=calypso` flow for the smoothest WPCOM-side UI
	 * and appends a `from=woocommerce-shipstation` query arg so WordPress.com
	 * recognizes the originating plugin and renders the standalone-plugin
	 * authorize UI rather than landing the merchant on the generic Jetpack admin
	 * page. Same pattern WooCommerce Shipping uses in
	 * classes/class-wc-connect-jetpack.php.
	 *
	 * @param string $return_url Where to redirect the merchant after the flow completes. Defaults to the ShipStation settings tab.
	 * @return string|null Null if the Connection package is unavailable.
	 */
	public function get_authorize_url( string $return_url = '' ): ?string {
		$manager = $this->get_manager();
		if ( null === $manager ) {
			return null;
		}
		if ( '' === $return_url ) {
			$return_url = self::settings_url();
		}
		return add_query_arg(
			array( 'from' => self::SLUG ),
			$manager->get_authorization_url( null, $return_url )
		);
	}

	/**
	 * Disconnect the site from WordPress.com.
	 *
	 * Uses the full Manager::disconnect_site() ($ignore_connected_plugins = true).
	 * The respectful variant ($ignore_connected_plugins = false) is unusable here:
	 * WooCommerce core always registers slug 'woocommerce', so Jetpack's own
	 * is_only() guard would refuse the disconnect on every store. We gate the
	 * tear-down at the application level instead — callers only reach here once
	 * {@see has_other_connection_consumers()} is false, so the only other
	 * registered consumer is WooCommerce core, which re-registers harmlessly on
	 * the next load. With that gate satisfied, a still-connected result is a
	 * genuine failure.
	 *
	 * @return bool True if the site ended up disconnected.
	 */
	public function disconnect(): bool {
		$manager = $this->get_manager();
		if ( null === $manager ) {
			return false;
		}
		$manager->disconnect_site( true, true );
		return ! $manager->is_connected();
	}

	/**
	 * Whether a genuine third-party plugin — beyond WooCommerce core and
	 * ShipStation itself — currently holds the WordPress.com connection (e.g.
	 * Jetpack, Jetpack Backup, WooCommerce Shipping & Tax). When true, a full
	 * disconnect would tear the connection down for that plugin too, so the UI
	 * steers the merchant to the transport toggle instead of disconnecting.
	 *
	 * WooCommerce core is excluded ({@see BASELINE_CONNECTION_SLUGS}) because it
	 * registers the connection unconditionally on every store; counting it would
	 * suppress the disconnect control everywhere. An unknown registry reads as
	 * false; see {@see get_other_connection_consumers()}.
	 *
	 * @return bool
	 */
	public function has_other_connection_consumers(): bool {
		return ! empty( $this->get_other_connection_consumers() );
	}

	/**
	 * Slugs of the plugins that hold the WordPress.com connection besides
	 * WooCommerce core and ShipStation.
	 *
	 * Reads Plugin_Storage, which is configured on plugins_loaded; callers run
	 * on admin_post or the settings render, both later. When the registry is
	 * unknown (connection package missing, or get_all() returns a WP_Error
	 * because it is not configured yet) this returns an empty list, so the
	 * disconnect control stays available rather than being silently blocked.
	 * A WP_Error is logged. Connect fresh does not rely on the empty list: it
	 * also checks {@see is_connection_registry_unknown()} and refuses.
	 *
	 * @since 5.4.1
	 *
	 * @return string[]
	 */
	public function get_other_connection_consumers(): array {
		return array_keys( $this->get_other_connection_consumer_entries() );
	}

	/**
	 * Display names of the plugins from {@see get_other_connection_consumers()},
	 * in the same order, for the repair card and the notices. A plugin that
	 * registered without a name is listed by its slug.
	 *
	 * @since 5.4.1
	 *
	 * @return string[]
	 */
	public function get_other_connection_consumer_names(): array {
		$names = array();
		foreach ( $this->get_other_connection_consumer_entries() as $slug => $args ) {
			$name    = is_array( $args ) && isset( $args['name'] ) && is_string( $args['name'] ) ? trim( $args['name'] ) : '';
			$names[] = '' !== $name ? $name : $slug;
		}
		return $names;
	}

	/**
	 * Whether Jetpack, or a standalone Jetpack plugin such as Jetpack Backup,
	 * Boost or Protect, is among the other consumers. Their slugs all start
	 * with "jetpack", and they show Jetpack's site-address notice on the
	 * dashboard, which is where the address is fixed without a reset.
	 *
	 * @since 5.4.1
	 *
	 * @return bool
	 */
	public function shares_connection_with_jetpack(): bool {
		foreach ( $this->get_other_connection_consumers() as $slug ) {
			if ( 0 === strpos( $slug, 'jetpack' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Registry entries (slug => registration arguments) of the plugins that
	 * hold the connection besides WooCommerce core and ShipStation. Empty when
	 * the registry is unknown; see {@see get_other_connection_consumers()}.
	 *
	 * @return array<string, mixed>
	 */
	private function get_other_connection_consumer_entries(): array {
		if ( ! class_exists( Jetpack_Plugin_Storage::class ) ) {
			return array();
		}
		$plugins = Jetpack_Plugin_Storage::get_all();
		if ( is_wp_error( $plugins ) ) {
			Logger::warning( 'WPCOM: plugin registry unavailable: ' . $plugins->get_error_message() );
			return array();
		}
		if ( ! is_array( $plugins ) ) {
			return array();
		}
		$ignored = array_merge( array( self::SLUG ), self::BASELINE_CONNECTION_SLUGS );
		$entries = array();
		foreach ( $plugins as $slug => $args ) {
			$slug = (string) $slug;
			if ( ! in_array( $slug, $ignored, true ) ) {
				$entries[ $slug ] = $args;
			}
		}
		return $entries;
	}

	/**
	 * Whether the Jetpack plugin registry cannot be read, so it is unknown which
	 * plugins use the connection: the connection package is missing or
	 * Plugin_Storage::get_all() returns a WP_Error (not yet configured).
	 *
	 * @return bool
	 */
	private function is_connection_registry_unknown(): bool {
		return ! class_exists( Jetpack_Plugin_Storage::class ) || is_wp_error( Jetpack_Plugin_Storage::get_all() );
	}

	/**
	 * Classify the WordPress.com connection's URL health (slice 4).
	 *
	 * Compares the host of the URL recorded at connect ({@see CONNECTED_URL_OPTION})
	 * against the current `home_url()` host. A difference means the site moved and
	 * WordPress.com is still forwarding proxied traffic to the old address — a
	 * Jetpack identity crisis — even though `is_connected()` (local tokens only)
	 * still reports connected. Pure: no side effects (the lazy stamp lives in
	 * {@see backfill_connected_url()}).
	 *
	 * @return string One of self::URL_STATUS_NOT_CONNECTED, URL_STATUS_UNKNOWN,
	 *                URL_STATUS_MISMATCH, or URL_STATUS_OK.
	 */
	public function connection_url_status(): string {
		if ( ! $this->is_connected() ) {
			return self::URL_STATUS_NOT_CONNECTED;
		}
		$recorded = $this->get_connected_url();
		if ( '' === $recorded ) {
			return self::URL_STATUS_UNKNOWN;
		}
		return $this->same_host( $recorded, home_url() ) ? self::URL_STATUS_OK : self::URL_STATUS_MISMATCH;
	}

	/**
	 * Whether the connection is up but registered to a different site URL than the
	 * site now uses — the actionable identity-crisis state the settings UI repairs.
	 *
	 * @return bool
	 */
	public function has_url_mismatch(): bool {
		return self::URL_STATUS_MISMATCH === $this->connection_url_status();
	}

	/**
	 * Record the current site URL as the one registered with WordPress.com for a
	 * connection that predates slice 4 (no stamp yet). Starts mismatch tracking
	 * from "now" so the next domain change is detected. No-op once a URL is
	 * recorded, or when the site isn't connected. Call on settings render.
	 *
	 * Note: a connection that was ALREADY on a stale URL when slice 4 shipped
	 * cannot be detected retroactively — WordPress.com won't reveal the old URL
	 * once the site is unreachable — so this assumes the current URL is sound.
	 *
	 * @return void
	 */
	public function backfill_connected_url(): void {
		if ( $this->is_connected() && '' === $this->get_connected_url() ) {
			$this->set_connected_url( home_url() );
		}
	}

	/**
	 * The host of the URL registered with WordPress.com (the "previous" domain
	 * when a mismatch exists), or '' when none is recorded.
	 *
	 * @return string
	 */
	public function get_connected_host(): string {
		return $this->url_host( $this->get_connected_url() );
	}

	/**
	 * The host of the site's current `home_url()` (the "current" domain).
	 *
	 * @return string
	 */
	public function get_current_host(): string {
		return $this->url_host( home_url() );
	}

	/**
	 * Read the recorded connect URL, or '' when none is stored.
	 *
	 * @return string
	 */
	public function get_connected_url(): string {
		return (string) get_option( self::CONNECTED_URL_OPTION, '' );
	}

	/**
	 * Record the URL registered with WordPress.com.
	 *
	 * @param string $url Site URL that was registered (typically home_url()).
	 * @return void
	 */
	private function set_connected_url( string $url ): void {
		update_option( self::CONNECTED_URL_OPTION, $url, false );
	}

	/**
	 * Listen for any plugin re-registering the site with WordPress.com.
	 *
	 * Called from Main::init() for every request, outside the bootstrap() gate:
	 * Jetpack's "Start fresh" runs over REST, where bootstrap() does not run
	 * while the transport toggle is off. Safe to call more than once.
	 *
	 * @since 5.4.1
	 *
	 * @return void
	 */
	public static function register_url_restamp(): void {
		if ( false === has_action( 'jetpack_site_registered', array( __CLASS__, 'restamp_connected_url' ) ) ) {
			add_action( 'jetpack_site_registered', array( __CLASS__, 'restamp_connected_url' ) );
		}
	}

	/**
	 * Record the current site URL after a re-registration, so a repair done in
	 * another plugin (for example Jetpack's "Start fresh") clears the repair
	 * card. Does nothing when no URL is recorded yet; the settings page
	 * backfills one later ({@see backfill_connected_url()}).
	 *
	 * @since 5.4.1
	 *
	 * @return void
	 */
	public static function restamp_connected_url(): void {
		if ( '' === (string) get_option( self::CONNECTED_URL_OPTION, '' ) ) {
			return;
		}
		update_option( self::CONNECTED_URL_OPTION, home_url(), false );
	}

	/**
	 * Clear the recorded connect URL (on disconnect).
	 *
	 * @return void
	 */
	private function clear_connected_url(): void {
		delete_option( self::CONNECTED_URL_OPTION );
	}

	/**
	 * Whether two URLs share the same (case-insensitive) host. Host-only by
	 * design: a domain move is what breaks the WordPress.com proxy route; a
	 * scheme tweak on the same host does not.
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool
	 */
	private function same_host( string $a, string $b ): bool {
		$host_a = $this->url_host( $a );
		$host_b = $this->url_host( $b );
		return '' !== $host_a && $host_a === $host_b;
	}

	/**
	 * Extract the lower-cased host from a URL, or '' when it has none.
	 *
	 * @param string $url URL to parse.
	 * @return string
	 */
	private function url_host( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Admin-post handler: register the site if needed and kick off the Jetpack
	 * authorize flow. Records the action so the return-trip lands on the settings tab.
	 */
	public function handle_connect_action(): void {
		$this->assert_admin_action_allowed( 'shipstation_wpcom_connect' );
		$this->maybe_persist_transport_toggle();
		// A fresh connect supersedes any stale pending-disconnect intent.
		$this->clear_disconnect_intent();
		$this->set_action( self::ACTION_CONNECT );

		$manager = $this->get_manager();
		if ( null === $manager ) {
			Logger::error( 'WPCOM connect: Jetpack Connection Manager unavailable (autoloader not ready).' );
			$this->redirect_to_settings();
			return;
		}

		if ( ! $manager->is_connected() ) {
			$result = $manager->try_registration();
			if ( is_wp_error( $result ) ) {
				Logger::error( 'WPCOM connect: site registration failed: ' . $result->get_error_message() );
				$this->redirect_to_settings();
				return;
			}
			// Record the URL we just registered with WordPress.com so a later
			// domain change can be detected (slice 4). register() sent home_url().
			$this->set_connected_url( home_url() );
		}

		$url = $this->get_authorize_url();
		if ( null === $url ) {
			Logger::error( 'WPCOM connect: could not build the authorize URL.' );
			$this->redirect_to_settings();
			return;
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Admin-post handler: re-establish the WordPress.com connection as a NEW site
	 * after the site address changed (slice 4).
	 *
	 * When the domain changes, the connection is bound to a dead URL. Keeping the
	 * same blog id across a domain change would require jetpack-sync's IDC migrate
	 * (declined for footprint — it means continuous full-site sync), so the
	 * supported fix is a fresh connection: WordPress.com mints a NEW blog id / proxy
	 * URL, and the merchant updates ShipStation's Store URL afterward.
	 *
	 * Runs only on a moved site, and only when no other plugin uses the
	 * connection; otherwise it explains why and changes nothing. The checks and
	 * the teardown are in {@see reset_connection_and_authorize()}.
	 */
	public function handle_connect_fresh_action(): void {
		$this->assert_admin_action_allowed( 'shipstation_wpcom_connect_fresh' );
		$this->reset_connection_and_authorize( 'connect-fresh', false );
	}

	/**
	 * Admin-post handler: the administrator-only reset for a moved site whose
	 * connection another plugin also uses. Runs the same teardown as connect
	 * fresh, so that plugin and every user's WordPress.com link need
	 * reconnecting afterward. Has its own nonce action and needs manage_options,
	 * so the plain connect-fresh link can never reach it.
	 *
	 * @since 5.4.1
	 */
	public function handle_connect_fresh_force_action(): void {
		$this->assert_admin_action_allowed( 'shipstation_wpcom_connect_fresh_force', 'manage_options' );
		$this->reset_connection_and_authorize( 'connect-fresh-force', true );
	}

	/**
	 * Drop this site's Jetpack tokens locally, register again as a new site and
	 * send the merchant to the authorize step. Shared by the connect-fresh and
	 * force handlers. It returns to the settings tab without a change when the
	 * site has not moved, and, unless forced, when another plugin uses the
	 * connection or the plugin registry is unknown.
	 *
	 * If registering again or building the authorize link fails, the teardown is
	 * not rolled back: the site is left disconnected, the failure is logged and
	 * {@see ACTION_CONNECT_FRESH_FAILED} queues a notice with the reason.
	 *
	 * The teardown is local only: `disconnect_site( false, ... )` does not tell
	 * WordPress.com to deregister, so the site this one was cloned from keeps its
	 * record. `$ignore_connected_plugins = true` lets the clear run although
	 * WooCommerce core also registered the connection.
	 *
	 * @param string $flow   Flow name for the log lines: 'connect-fresh' or 'connect-fresh-force'.
	 * @param bool   $forced True for the administrator-only reset, which goes ahead although
	 *                       other plugins use the connection.
	 * @return void
	 */
	private function reset_connection_and_authorize( string $flow, bool $forced ): void {
		// The link is only shown on a moved site, but its nonce lives for hours.
		// A replayed or stale link must not reset a connection that is healthy now.
		if ( ! $this->has_url_mismatch() ) {
			$this->redirect_to_settings();
			return;
		}

		$other_consumers = $this->get_other_connection_consumers();

		// Another plugin (Jetpack, WooPayments, ...) uses the same connection, and the
		// teardown below drops it for that plugin too. Explain instead; an
		// administrator can still reset through handle_connect_fresh_force_action().
		// An unknown registry may hide such a plugin, so it is refused the same way.
		if ( ! $forced && ( ! empty( $other_consumers ) || $this->is_connection_registry_unknown() ) ) {
			$this->set_action( self::ACTION_CONNECT_FRESH_SHARED );
			$this->redirect_to_settings();
			return;
		}

		// Names for the failure notice, read before the teardown below.
		$other_names = $this->get_other_connection_consumer_names();

		// An administrator choosing to reset a shared connection is not an error,
		// but it is worth an audit line: which plugins lose the connection with it.
		if ( $forced ) {
			Logger::warning(
				empty( $other_consumers )
					? 'WPCOM ' . $flow . ': resetting the connection; no other plugin uses it any more.'
					: 'WPCOM ' . $flow . ': resetting the connection for other plugins too: ' . implode( ', ', $other_consumers )
			);
		}

		$this->maybe_persist_transport_toggle();
		$this->clear_disconnect_intent();
		$this->set_action( self::ACTION_CONNECT );

		$manager = $this->get_manager();
		if ( null === $manager ) {
			Logger::error( 'WPCOM ' . $flow . ': Jetpack Connection Manager unavailable (autoloader not ready).' );
			$this->redirect_to_settings();
			return;
		}

		// Local-only teardown: drop the cloned tokens here without telling
		// WordPress.com to deregister, so the original/production site is untouched.
		$manager->disconnect_site( false, true );
		$this->clear_connected_url();
		$this->ensure_heartbeat_scheduled();

		$result = $manager->try_registration();
		if ( is_wp_error( $result ) ) {
			Logger::error( 'WPCOM ' . $flow . ': site registration failed: ' . $result->get_error_message() );
			$this->report_reset_failure( $result->get_error_message(), $other_names );
			$this->redirect_to_settings();
			return;
		}
		$this->set_connected_url( home_url() );

		$url = $this->get_authorize_url();
		if ( null === $url ) {
			Logger::error( 'WPCOM ' . $flow . ': could not build the authorize URL.' );
			$this->report_reset_failure( __( 'the WordPress.com authorization link could not be built', 'woocommerce-shipstation-integration' ), $other_names );
			$this->redirect_to_settings();
			return;
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Queue the failed-reset notice with its reason and the other plugin names.
	 *
	 * @param string   $reason          Why the site could not be registered again.
	 * @param string[] $other_consumers Names of the other plugins the reset disconnected.
	 * @return void
	 */
	private function report_reset_failure( string $reason, array $other_consumers ): void {
		$this->set_action( self::ACTION_CONNECT_FRESH_FAILED );
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		set_transient(
			self::ACTION_DETAIL_TRANSIENT . '_' . $user_id,
			array(
				'reason'          => $reason,
				'other_consumers' => array_values( array_map( 'strval', $other_consumers ) ),
			),
			self::ACTION_TRANSIENT_TTL
		);
	}

	/**
	 * Admin-post handler: record the *intent* to disconnect, without touching the
	 * Jetpack connection. Disconnecting tears down the proxy Store URL, so if
	 * ShipStation is reaching the store only through WordPress.com the disconnect
	 * is gated until a direct fallback connection is detected (or the merchant
	 * uses the "Dangerously disconnect" override). The settings section reflects
	 * the pending state and offers Confirm / Cancel / Dangerously disconnect.
	 */
	public function handle_disconnect_request(): void {
		$this->assert_admin_action_allowed( 'shipstation_wpcom_disconnect' );
		$this->maybe_persist_transport_toggle();
		$this->set_disconnect_intent();
		$this->redirect_to_settings();
	}

	/**
	 * Admin-post handler: perform the real Jetpack disconnect — the GUARDED
	 * execution. Refuses unless a direct ShipStation connection has been seen
	 * within the active window (the fallback signal) OR the request carries the
	 * explicit `force` flag ("Dangerously disconnect"). A refusal leaves the
	 * pending intent intact and returns to the settings tab.
	 */
	public function handle_disconnect_complete(): void {
		$this->assert_admin_action_allowed( 'shipstation_wpcom_disconnect_complete' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified in assert_admin_action_allowed() above.
		$force = isset( $_GET['force'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['force'] ) );

		// Guard the execution: without a direct fallback and without the explicit
		// override, do not disconnect — keep the intent pending and return.
		if ( ! $force && ! Connection_Log::is_direct_connection_safe( Auth_Controller::active_window_seconds(), Auth_Controller::direct_lag_tolerance_seconds() ) ) {
			Logger::error( 'WPCOM disconnect blocked: no direct ShipStation connection detected and not forced.' );
			$this->redirect_to_settings();
			return;
		}

		// Refuse the tear-down while a genuine third-party plugin (Jetpack, WC
		// Shipping & Tax, Jetpack Backup, …) still holds the connection: a full
		// disconnect would break it for them too. Resolve the intent and surface
		// that distinctly so the merchant is steered to the transport toggle
		// instead. The connected-state UI normally prevents reaching here, but
		// state can change between render and click. WooCommerce core does not
		// count here — see has_other_connection_consumers().
		if ( $this->has_other_connection_consumers() ) {
			$this->clear_disconnect_intent();
			$this->set_action( self::ACTION_DISCONNECT_SHARED );
			$this->redirect_to_settings();
			return;
		}

		$this->perform_disconnect();
		$this->clear_disconnect_intent();
		$this->set_action( self::ACTION_DISCONNECT );
		$this->redirect_to_settings();
	}

	/**
	 * Admin-post handler: abandon a pending disconnect intent, leaving the
	 * WordPress.com connection intact.
	 */
	public function handle_disconnect_cancel(): void {
		$this->assert_admin_action_allowed( 'shipstation_wpcom_disconnect_cancel' );
		$this->clear_disconnect_intent();
		$this->redirect_to_settings();
	}

	/**
	 * Tear down the Jetpack site connection and keep the heartbeat schedule sane.
	 * Shared by the confirm and forced ("Dangerously disconnect") paths.
	 */
	private function perform_disconnect(): void {
		// Reached only after the caller has confirmed no genuine third-party
		// plugin holds the connection (WooCommerce core aside), so a
		// still-connected result is a real failure — not a respectful refusal.
		if ( ! $this->disconnect() ) {
			Logger::error( 'WPCOM disconnect: site is still connected after the disconnect attempt.' );
			return;
		}

		// Drop the recorded connect URL so a future reconnect starts clean.
		$this->clear_connected_url();
		$this->ensure_heartbeat_scheduled();
	}

	/**
	 * Re-schedule the Jetpack heartbeat cron event after a teardown that
	 * unschedules it (disconnect, or the staging local-token clear).
	 *
	 * Manager::disconnect_site() unschedules `jetpack_v2_heartbeat`. On the *next*
	 * page load Jetpack's plugins_loaded callback re-instantiates Heartbeat and
	 * re-schedules the event — but plugins_loaded fires before after_setup_theme,
	 * so the wp_schedule_event() call triggers WC's cron_schedules filter callback
	 * (WC_Install::cron_schedules) which calls __('Monthly', 'woocommerce') before
	 * WP 6.7's textdomain timing check passes. That emits a "_doing_it_wrong"
	 * notice, the notice text echoes into the response body, and the settings
	 * page's admin-header.php then fails with "Cannot modify header information —
	 * headers already sent."
	 *
	 * Re-scheduling here (post-init, with after_setup_theme already fired) is safe
	 * and means Jetpack's next plugins_loaded run finds an existing schedule and
	 * skips the problematic wp_schedule_event() call.
	 *
	 * @return void
	 */
	private function ensure_heartbeat_scheduled(): void {
		if ( ! wp_next_scheduled( 'jetpack_v2_heartbeat' ) ) {
			wp_schedule_event( time(), 'daily', 'jetpack_v2_heartbeat' );
		}
	}

	/**
	 * Whether a disconnect intent is currently pending.
	 *
	 * @return bool
	 */
	public function has_disconnect_intent(): bool {
		return false !== get_option( self::DISCONNECT_INTENT_OPTION, false );
	}

	/**
	 * Record a pending disconnect intent (epoch of the request).
	 *
	 * @return void
	 */
	private function set_disconnect_intent(): void {
		update_option( self::DISCONNECT_INTENT_OPTION, time(), false );
	}

	/**
	 * Clear any pending disconnect intent.
	 *
	 * @return void
	 */
	public function clear_disconnect_intent(): void {
		delete_option( self::DISCONNECT_INTENT_OPTION );
	}

	/**
	 * Log the WP_Error surfaced by Jetpack's webhook when authorize() fails.
	 *
	 * Unconditionally logs, so errors that fire outside our originating flow (other
	 * Jetpack consumers, user-context-less webhook callbacks) are still captured.
	 *
	 * @param mixed $error WP_Error from Jetpack; loosely typed because the action is shared.
	 */
	public function log_authorize_error( $error ): void {
		$detail = is_wp_error( $error ) ? $error->get_error_message() : 'unknown error';
		$scope  = '' === $this->get_action() ? 'external flow' : 'ShipStation flow';
		Logger::error( sprintf( 'WPCOM authorize failed (%s): %s', $scope, $detail ) );
	}

	/**
	 * If the merchant landed on any admin page while a connect/disconnect flow
	 * is still pending, bounce them to the ShipStation settings tab so the
	 * notice renders in context.
	 */
	public function maybe_redirect_to_settings(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || is_network_admin() ) {
			return;
		}
		if ( '' === $this->get_action() ) {
			return;
		}
		if ( $this->is_shipstation_settings_screen() ) {
			return;
		}

		wp_safe_redirect( self::settings_url() );
		exit;
	}

	/**
	 * Render a success/error admin notice on the ShipStation settings tab based on
	 * the action transient and the resulting connection state, then clear the action.
	 */
	public function render_admin_notice(): void {
		if ( ! $this->is_shipstation_settings_screen() ) {
			return;
		}

		$action = $this->get_action();
		if ( '' === $action ) {
			return;
		}
		$detail = $this->get_action_detail();
		$this->clear_action();

		// Connect fresh or the forced reset dropped the connection but could not
		// register the site again, so the site is now disconnected.
		if ( self::ACTION_CONNECT_FRESH_FAILED === $action ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $this->reset_failure_message( $detail ) )
			);
			return;
		}

		// Connect fresh refused because another plugin still uses the connection.
		// Informational, not an error. The repair card below already shows the
		// steps for each role, so the notice only points at it.
		if ( self::ACTION_CONNECT_FRESH_SHARED === $action ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				/* translators: "WordPress.com connection needs repair" is the status label shown below; use the same translation. */
				esc_html__( 'The WordPress.com connection wasn\'t reset because another plugin also uses it. See the steps under "WordPress.com connection needs repair" below.', 'woocommerce-shipstation-integration' )
			);
			return;
		}

		// Disconnect intentionally refused because other plugins still use the
		// WordPress.com connection. Informational, not an error. Point the
		// merchant at the transport toggle, which is the real lever for
		// ShipStation (its label is defined in includes/data/data-settings.php).
		if ( self::ACTION_DISCONNECT_SHARED === $action ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html__( 'ShipStation stayed connected to WordPress.com because another plugin is still using that connection. To stop routing ShipStation through WordPress.com, turn off "Enable WordPress.com Transport".', 'woocommerce-shipstation-integration' )
			);
			return;
		}

		$connected = $this->is_connected();
		$success   = ( self::ACTION_CONNECT === $action && $connected ) || ( self::ACTION_DISCONNECT === $action && ! $connected );
		$message   = $this->notice_message_for( $action, $success );

		// A bare success/error toast (SHIPSTN-142). The persistent connection
		// section on the settings tab carries the "copy these values into
		// ShipStation" guidance and the "View ShipStation connection details"
		// entry point, so the transient notice no longer repeats them.
		printf(
			'<div class="%s is-dismissible"><p>%s</p></div>',
			esc_attr( $success ? 'notice notice-success' : 'notice notice-error' ),
			esc_html( $message )
		);
	}

	/**
	 * Build the failed-reset notice text.
	 *
	 * @param array $detail Stored detail: 'reason' (string) and 'other_consumers' (string[]).
	 * @return string Translated, user-facing message text.
	 */
	private function reset_failure_message( array $detail ): string {
		$reason = isset( $detail['reason'] ) && is_string( $detail['reason'] ) && '' !== $detail['reason']
			? rtrim( $detail['reason'], '. ' )
			: __( 'unknown error', 'woocommerce-shipstation-integration' );

		$message = sprintf(
			/* translators: %s: the reason WordPress.com gave, for example "Verification secrets are missing". */
			__( 'The WordPress.com connection was reset, but the site could not be registered again: %s. Use Connect to WordPress.com to finish. If it fails again, wait a minute and retry.', 'woocommerce-shipstation-integration' ),
			$reason
		);

		$others = isset( $detail['other_consumers'] ) && is_array( $detail['other_consumers'] ) ? array_filter( array_map( 'strval', $detail['other_consumers'] ) ) : array();
		if ( ! empty( $others ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s: the other plugins that used the connection, for example "Jetpack and WooPayments". */
				_n( '%s also lost the connection and needs to be reconnected.', '%s also lost the connection and need to be reconnected.', count( $others ), 'woocommerce-shipstation-integration' ),
				wp_sprintf( '%l', array_values( $others ) )
			);
		}

		return $message;
	}

	/**
	 * Return the translated notice message for the given action/outcome pair.
	 *
	 * @param string $action  One of self::ACTION_CONNECT or self::ACTION_DISCONNECT.
	 * @param bool   $success Whether the action achieved the desired end state.
	 * @return string Translated, user-facing message text.
	 */
	private function notice_message_for( string $action, bool $success ): string {
		if ( self::ACTION_CONNECT === $action ) {
			// Bare confirmation (SHIPSTN-142): the persistent connection section
			// on the settings tab carries the "copy your details into ShipStation"
			// follow-up, so the transient notice no longer repeats it.
			return $success
				? __( 'Connected to WordPress.com.', 'woocommerce-shipstation-integration' )
				: __( 'Could not connect to WordPress.com. Please try again.', 'woocommerce-shipstation-integration' );
		}

		return $success
			? __( 'Disconnected from WordPress.com.', 'woocommerce-shipstation-integration' )
			: __( 'Could not disconnect from WordPress.com. Please try again.', 'woocommerce-shipstation-integration' );
	}

	/**
	 * Whether the given string is one of the known pending-action identifiers.
	 *
	 * @param string $action Candidate action identifier.
	 * @return bool
	 */
	private function is_known_action( string $action ): bool {
		return in_array(
			$action,
			array( self::ACTION_CONNECT, self::ACTION_DISCONNECT, self::ACTION_DISCONNECT_SHARED, self::ACTION_CONNECT_FRESH_SHARED, self::ACTION_CONNECT_FRESH_FAILED ),
			true
		);
	}

	/**
	 * Store the current flow's action for the current user.
	 *
	 * Rejects unknown action identifiers so typos fail loudly rather than rendering
	 * the wrong notice branch.
	 *
	 * @param string $action One of self::ACTION_CONNECT, self::ACTION_DISCONNECT, self::ACTION_DISCONNECT_SHARED,
	 *                       self::ACTION_CONNECT_FRESH_SHARED, or self::ACTION_CONNECT_FRESH_FAILED.
	 */
	private function set_action( string $action ): void {
		if ( ! $this->is_known_action( $action ) ) {
			Logger::error( 'WPCOM: refusing to set unknown pending action: ' . $action );
			return;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		set_transient( self::ACTION_TRANSIENT . '_' . $user_id, $action, self::ACTION_TRANSIENT_TTL );
	}

	/**
	 * Read the current user's pending action, or '' if none is queued or the value
	 * is not one of the known action constants.
	 *
	 * @return string Stored action (one of the self::ACTION_* constants), or ''
	 *                when nothing valid is pending.
	 */
	private function get_action(): string {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return '';
		}
		$action = get_transient( self::ACTION_TRANSIENT . '_' . $user_id );
		if ( is_string( $action ) && $this->is_known_action( $action ) ) {
			return $action;
		}
		return '';
	}

	/**
	 * Read the current user's pending action detail, or an empty array.
	 *
	 * @return array
	 */
	private function get_action_detail(): array {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}
		$detail = get_transient( self::ACTION_DETAIL_TRANSIENT . '_' . $user_id );
		return is_array( $detail ) ? $detail : array();
	}

	/**
	 * Clear the current user's pending action and its detail.
	 */
	private function clear_action(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		delete_transient( self::ACTION_TRANSIENT . '_' . $user_id );
		delete_transient( self::ACTION_DETAIL_TRANSIENT . '_' . $user_id );
	}

	/**
	 * Redirect to the ShipStation settings tab and end the request.
	 */
	private function redirect_to_settings(): void {
		wp_safe_redirect( self::settings_url() );
		exit;
	}

	/**
	 * Absolute URL of the ShipStation settings tab.
	 *
	 * @return string Fully qualified admin URL for the ShipStation integration tab.
	 */
	private static function settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=integration&section=shipstation' );
	}

	/**
	 * Whether the current admin request is for the ShipStation settings tab.
	 *
	 * @return bool True when page/tab/section query args match the ShipStation integration tab.
	 */
	private function is_shipstation_settings_screen(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only page context.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable

		return 'wc-settings' === $page && 'integration' === $tab && 'shipstation' === $section;
	}

	/**
	 * Persist the WordPress.com transport toggle to match the (possibly unsaved)
	 * checkbox state carried on a connect/disconnect action link, before the
	 * action runs. The settings tab now renders the connect/disconnect controls
	 * regardless of the saved toggle (CSS-hidden until the box is ticked), so the
	 * merchant can act on the connection without saving the form first; the link's
	 * `wpcom_transport` param lets us keep the option and the action consistent.
	 * Nonce + capability are already verified by the calling handler.
	 *
	 * @return void
	 */
	private function maybe_persist_transport_toggle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by the calling handler's assert_admin_action_allowed().
		if ( ! isset( $_GET['wpcom_transport'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$enabled  = '1' === sanitize_text_field( wp_unslash( $_GET['wpcom_transport'] ) ) ? 'yes' : 'no';
		$settings = (array) get_option( 'woocommerce_shipstation_settings', array() );
		$current  = isset( $settings['wpcom_transport_enabled'] ) ? $settings['wpcom_transport_enabled'] : 'no';

		if ( $current !== $enabled ) {
			$settings['wpcom_transport_enabled'] = $enabled;
			update_option( 'woocommerce_shipstation_settings', $settings );
		}
	}

	/**
	 * Verify the admin-post nonce and capability for a connection action, dying
	 * with a clear message on failure.
	 *
	 * @param string $nonce_action Nonce action name to verify.
	 * @param string $capability   Capability the current user needs.
	 *
	 * @return void
	 */
	private function assert_admin_action_allowed( string $nonce_action, string $capability = 'manage_woocommerce' ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'woocommerce-shipstation-integration' ) );
		}
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- manage_woocommerce by default, manage_options for the forced reset.
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'woocommerce-shipstation-integration' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Lazy-instantiate and cache the Jetpack Connection Manager.
	 *
	 * @return Jetpack_Connection_Manager|null Manager instance, or null when the Connection package is unavailable.
	 */
	private function get_manager(): ?Jetpack_Connection_Manager {
		if ( null !== $this->manager ) {
			return $this->manager;
		}
		if ( ! $this->is_jetpack_autoloader_ready() ) {
			return null;
		}
		$this->manager = new Jetpack_Connection_Manager( self::SLUG );
		return $this->manager;
	}

	/**
	 * Whether Jetpack's Composer autoloader has loaded the Connection package.
	 *
	 * @return bool True when the Manager class is available to instantiate.
	 */
	private function is_jetpack_autoloader_ready(): bool {
		return class_exists( Jetpack_Connection_Manager::class );
	}
}
