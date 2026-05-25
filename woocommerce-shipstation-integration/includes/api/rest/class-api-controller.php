<?php
/**
 * ShipStation REST API Base Controller file.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation\API\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use WooCommerce\Shipping\ShipStation\Features;
use WooCommerce\Shipping\ShipStation\Logger;
use WP_Error;
use WP_REST_Request;

/**
 * API_Controller class.
 */
class API_Controller {

	/**
	 * Stable error code returned when ShipStation Basic Auth fails. Surfaced so
	 * the WPCOM proxy and direct callers see one consistent failure shape.
	 */
	const REST_INVALID_CREDENTIALS = 'rest_shipstation_invalid_credentials';

	/**
	 * Per-request memoisation of looked-up woocommerce_api_keys rows, keyed by
	 * the `wc_api_hash()` digest of the inbound consumer_key. Repeated
	 * permission_callback invocations during a single dispatch reuse the row
	 * fetched on the first call.
	 *
	 * @var array<string, \stdClass|null>
	 */
	private static array $api_key_row_cache = array();

	/**
	 * Log something.
	 *
	 * @param string $message Log message.
	 */
	public function log( $message ) {
		Logger::debug( $message );
	}

	/**
	 * Permission gate shared by every /wc-shipstation/v1/* route.
	 *
	 * Behaviour depends on the WPCOM transport flag:
	 *
	 *  - Flag ON: the request must carry the ShipStation-issued Basic Auth
	 *    credential. On match, the current user is set to that key's owner so
	 *    the downstream wc_rest_check_manager_permissions check sees an
	 *    authenticated identity even on `?rest_route=` URLs (which bypass
	 *    WC_REST_Authentication's `/wp-json/wc-*` prefix test). The
	 *    `wc_shipstation_user_can_manage_wc` filter is then applied as the
	 *    second layer.
	 *  - Flag OFF: the strict step is skipped. The public
	 *    `wc_shipstation_user_can_manage_wc` filter is the sole authority,
	 *    preserving trunk semantics for sites that have not opted in.
	 *
	 * @since 5.0.5
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @param string          $context wc_rest_check_manager_permissions context, e.g. 'attributes'.
	 * @param string          $action  wc_rest_check_manager_permissions action, 'read' or 'create'.
	 * @return bool|WP_Error `true` when authorized; `false` when the capability
	 *                       layer (`wc_rest_check_manager_permissions` and the
	 *                       `wc_shipstation_user_can_manage_wc` filter) rejects.
	 *                       `WP_Error(401, rest_shipstation_invalid_credentials)`
	 *                       only on Basic Auth failure (credential missing or
	 *                       wrong, or the key owner cannot be resolved).
	 */
	protected function check_namespace_permission( WP_REST_Request $request, string $context, string $action ) {
		if ( Features::is_wpcom_transport_enabled() ) {
			$row = $this->resolve_authenticated_api_key_row( $request );
			if ( null === $row ) {
				return $this->invalid_credentials_error();
			}
			// Mirror what WC_REST_Authentication does for /wp-json/wc-* URLs so
			// the capability check below works on `?rest_route=` URLs (the
			// proxy's outbound form), which bypass WC's user-resolution. Fail
			// closed when the owner cannot be resolved so a deleted owner
			// cannot leak the request to a pre-existing user identity.
			$user_id = (int) $row->user_id;
			if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
				Logger::debug( 'ShipStation Basic Auth rejected: api_key_owner_missing' );
				return $this->invalid_credentials_error();
			}
			if ( get_current_user_id() !== $user_id ) {
				wp_set_current_user( $user_id );
			}

			// Audit trail for support: which row authenticated this call. The
			// truncated_key is the same suffix WC shows in its admin listing,
			// so a support engineer can cross-reference without needing the
			// plaintext key. Gated on the existing Logger flag — no cost when
			// logging is disabled.
			Logger::debug(
				sprintf(
					'ShipStation Basic Auth accepted: key_id=%d truncated_key=%s',
					(int) $row->key_id,
					(string) $row->truncated_key
				)
			);
		}

		/**
		 * Filters whether the current user has permissions to manage WooCommerce
		 * for ShipStation routes.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $can_manage_wc Whether the user can manage WooCommerce.
		 */
		return apply_filters( 'wc_shipstation_user_can_manage_wc', wc_rest_check_manager_permissions( $context, $action ) );
	}

	/**
	 * Build the standard 401 response returned by every ShipStation REST
	 * permission callback when the Basic Auth credential check fails.
	 *
	 * Centralised so the error code, message and status stay consistent
	 * across controllers.
	 *
	 * @since 5.0.5
	 *
	 * @return WP_Error
	 */
	protected function invalid_credentials_error(): WP_Error {
		return new WP_Error(
			self::REST_INVALID_CREDENTIALS,
			__( 'Invalid ShipStation credentials.', 'woocommerce-shipstation-integration' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Validate the ShipStation HTTP Basic Auth credential against any row in
	 * woocommerce_api_keys whose hashed consumer_key matches the inbound one.
	 *
	 * The Jetpack/WPCOM transport only proves a request originated from WPCOM,
	 * not that the original caller knows the merchant's credential. Every
	 * /wc-shipstation/v1/* permission_callback runs this check so the
	 * consumer_key/secret remains the single source of truth for caller
	 * identity, regardless of whether the request arrived directly or through
	 * the WPCOM proxy.
	 *
	 * The lookup hashes the inbound consumer_key and queries the row by that
	 * hash. We deliberately do not pin to `woocommerce_shipstation_api_key_id`:
	 * stores that issued ShipStation keys before the auth modal landed (plugin
	 * versions before 5.0.3) never wrote that option, and pinning would 401
	 * their existing valid credentials. The relaxation matches WC core's own
	 * `WC_REST_Authentication` model for `/wp-json/wc-*` URLs; the second-layer
	 * `wc_rest_check_manager_permissions` capability check still gates access.
	 *
	 * @since 5.0.5
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return bool True iff a Basic Authorization header was present and the
	 *              decoded credentials match a row in woocommerce_api_keys.
	 */
	public function check_basic_auth_credential( WP_REST_Request $request ): bool {
		return null !== $this->resolve_authenticated_api_key_row( $request );
	}

	/**
	 * Validate the inbound Basic Auth credential and return the matching
	 * woocommerce_api_keys row on success, or null on any failure. The gate
	 * uses the returned row's user_id to authenticate the request without a
	 * second DB lookup; check_basic_auth_credential() is the bool-returning
	 * public facade for callers that only need pass/fail.
	 *
	 * @since 5.0.5
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return \stdClass|null woocommerce_api_keys row (user_id, consumer_key,
	 *                       consumer_secret) on success; null on rejection.
	 */
	private function resolve_authenticated_api_key_row( WP_REST_Request $request ): ?\stdClass {
		list( $consumer_key, $consumer_secret ) = $this->extract_basic_auth_credentials( $request );
		if ( '' === $consumer_key || '' === $consumer_secret ) {
			Logger::debug( 'ShipStation Basic Auth rejected: malformed_authorization' );
			return null;
		}

		$hashed_key = wc_api_hash( $consumer_key );
		$row        = $this->fetch_api_key_row_by_hash( $hashed_key );
		if ( null === $row || empty( $row->consumer_secret ) ) {
			Logger::debug( 'ShipStation Basic Auth rejected: consumer_key_mismatch' );
			return null;
		}

		if ( ! hash_equals( (string) $row->consumer_secret, $consumer_secret ) ) {
			Logger::debug( 'ShipStation Basic Auth rejected: consumer_secret_mismatch' );
			return null;
		}

		return $row;
	}

	/**
	 * Fetch the woocommerce_api_keys row whose consumer_key column matches the
	 * given `wc_api_hash()` digest, memoised for the request lifetime so
	 * repeated permission_callback invocations during a single dispatch do
	 * not re-hit the database.
	 *
	 * @since 5.0.5
	 *
	 * @param string $hashed_consumer_key `wc_api_hash()` of the inbound key.
	 * @return \stdClass|null DB row, or null when no row matches.
	 */
	private function fetch_api_key_row_by_hash( string $hashed_consumer_key ): ?\stdClass {
		if ( array_key_exists( $hashed_consumer_key, self::$api_key_row_cache ) ) {
			return self::$api_key_row_cache[ $hashed_consumer_key ];
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- hash-equality lookup, no CRUD equivalent
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- result memoised in self::$api_key_row_cache for the request lifetime
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT key_id, user_id, consumer_key, consumer_secret, truncated_key FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
				$hashed_consumer_key
			)
		);

		self::$api_key_row_cache[ $hashed_consumer_key ] = $row instanceof \stdClass ? $row : null;
		return self::$api_key_row_cache[ $hashed_consumer_key ];
	}

	/**
	 * Resolve a Basic Auth credential pair from any source PHP exposes.
	 *
	 * Precedence:
	 *
	 *  1. `X-ShipStation-Authorization` (the WPCOM proxy relay header). Checked
	 *     first because Apache mod_php (and similar fronts) auto-populate
	 *     `PHP_AUTH_USER` / `PHP_AUTH_PW` from any inbound `Authorization: Basic …`
	 *     header. If an unrelated upstream (LB healthcheck, basic-auth-protected
	 *     staging, developer error) lands a Basic header on the request, those
	 *     `$_SERVER` vars get filled and would silently short-circuit the
	 *     proxy-relayed credential we actually need to evaluate.
	 *  2. `PHP_AUTH_USER` / `PHP_AUTH_PW` for direct calls on hosts where
	 *     mod_php absorbed the standard `Authorization` header.
	 *  3. The standard `Authorization` header chain (with CGI / FastCGI fallbacks
	 *     in `get_authorization_header()`).
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return array{0: string, 1: string} Consumer key and secret, both '' when absent.
	 */
	private function extract_basic_auth_credentials( WP_REST_Request $request ): array {
		$relay_header = (string) $request->get_header( 'x_shipstation_authorization' );
		if ( '' !== $relay_header ) {
			return $this->parse_basic_auth_payload( $relay_header );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- credentials are pre-validated; further sanitisation would corrupt valid secrets containing special characters.
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && ! empty( $_SERVER['PHP_AUTH_PW'] ) ) {
			return array(
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw credential, see comment above.
				(string) wp_unslash( $_SERVER['PHP_AUTH_USER'] ),
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw credential, see comment above.
				(string) wp_unslash( $_SERVER['PHP_AUTH_PW'] ),
			);
		}

		return $this->parse_basic_auth_payload( $this->get_authorization_header( $request ) );
	}

	/**
	 * Decode an `Authorization: Basic …` header value into a consumer key / secret pair.
	 *
	 * @param string $authorization Raw header value.
	 * @return array{0: string, 1: string} Key and secret, both '' on any failure.
	 */
	private function parse_basic_auth_payload( string $authorization ): array {
		if ( '' === $authorization || 0 !== stripos( $authorization, 'Basic ' ) ) {
			return array( '', '' );
		}

		$decoded = base64_decode( substr( $authorization, 6 ), true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return array( '', '' );
		}

		list( $key, $secret ) = explode( ':', $decoded, 2 );
		return array( (string) $key, (string) $secret );
	}

	/**
	 * Read the standard `Authorization` header from the request, with fallbacks
	 * for CGI/FastCGI environments that strip it before WordPress sees it.
	 *
	 * Hosts often relocate Authorization to REDIRECT_HTTP_AUTHORIZATION via an
	 * .htaccess RewriteRule; some only surface it through getallheaders(). We
	 * try each so a correctly-configured site does not see spurious 401s. The
	 * WPCOM proxy relay header (`X-ShipStation-Authorization`) is consumed
	 * higher up in `extract_basic_auth_credentials()`.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return string Header value, or empty string when absent.
	 */
	private function get_authorization_header( WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'authorization' );
		if ( '' !== $header ) {
			return $header;
		}

		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw header, see extract_basic_auth_credentials() comment.
			return (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		}
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw header, see extract_basic_auth_credentials() comment.
			return (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}

		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					// Skip non-string values: some hosts return arrays for repeated
					// headers, and a (string) cast on those silently coerces to 'Array'.
					if ( is_string( $value ) && 0 === strcasecmp( (string) $name, 'Authorization' ) ) {
						return $value;
					}
				}
			}
		}

		return '';
	}
}
