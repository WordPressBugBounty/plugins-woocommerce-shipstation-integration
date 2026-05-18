<?php
/**
 * Centralized storage and redaction for Checkout Rates options.
 *
 * Option keys, accessors, and a log redaction helper used by the live API
 * client, REST controllers, settings UI, and logger. URL values are treated
 * as opaque per project requirements — sanitization belongs at the REST
 * /configure boundary, not in this storage class.
 *
 * @package WC_ShipStation
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized accessor for Checkout Rates options.
 */
final class Checkout_Rates_Options {

	/**
	 * Option key for the ShipStation-issued rates URL.
	 */
	const OPTION_RATES_URL = 'wc_shipstation_checkout_rates_url';

	/**
	 * Option key for the merchant-side enable flag.
	 */
	const OPTION_ENABLED = 'wc_shipstation_checkout_rates_enabled';

	/**
	 * Sentinel returned when the URL is redacted from log output.
	 */
	const REDACTED_TOKEN = '<redacted-rates-url>';

	/**
	 * Get the configured rates URL.
	 *
	 * @return string Empty string when unset.
	 */
	public static function get_rates_url(): string {
		$value = get_option( self::OPTION_RATES_URL, '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Store the rates URL.
	 *
	 * @param string $url Opaque ShipStation rates URL.
	 *
	 * @return bool Whether the option write succeeded.
	 */
	public static function set_rates_url( string $url ): bool {
		if ( self::get_rates_url() === $url ) {
			return true;
		}
		return update_option( self::OPTION_RATES_URL, $url, false );
	}

	/**
	 * Delete the stored rates URL.
	 *
	 * @return bool True when an existing value was removed; false when the option was already absent or the delete failed.
	 */
	public static function clear_rates_url(): bool {
		return delete_option( self::OPTION_RATES_URL );
	}

	/**
	 * Whether a rates URL is currently stored.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::get_rates_url();
	}

	/**
	 * Whether the merchant-side enable flag is on.
	 *
	 * @return bool
	 */
	public static function get_enabled(): bool {
		$value = get_option( self::OPTION_ENABLED, false );
		return 'yes' === $value;
	}

	/**
	 * Set the merchant-side enable flag.
	 *
	 * @param bool $enabled Desired state.
	 *
	 * @return bool Whether the option write succeeded.
	 */
	public static function set_enabled( bool $enabled ): bool {
		$value = $enabled ? 'yes' : 'no';
		return update_option( self::OPTION_ENABLED, $value, false );
	}

	/**
	 * Redact the configured rates URL (and any GUID-tail HTTPS URL) from a log message.
	 *
	 * @param string $message Log message that may contain the URL.
	 *
	 * @return string Message with the URL replaced by REDACTED_TOKEN.
	 */
	public static function redact( string $message ): string {
		$url = self::get_rates_url();
		if ( '' !== $url ) {
			$message = str_replace( $url, self::REDACTED_TOKEN, $message );
		}
		$result = preg_replace(
			'#https://[^\s"\']+?/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\b#i',
			self::REDACTED_TOKEN,
			$message
		);
		return is_string( $result ) ? $result : $message;
	}
}
