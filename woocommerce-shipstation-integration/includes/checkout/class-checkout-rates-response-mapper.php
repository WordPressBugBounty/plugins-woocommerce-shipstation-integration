<?php
/**
 * Checkout Rates Response Mapper class file.
 *
 * @package WC_ShipStation
 * @since 5.0.5
 */

namespace WooCommerce\Shipping\ShipStation\Checkout;

use WooCommerce\Shipping\ShipStation\Features;
use WooCommerce\Shipping\ShipStation\Logger;
use WooCommerce\Shipping\ShipStation\Order_Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps raw ShipStation checkout rates API responses into WooCommerce-compatible rate arrays.
 *
 * @since 5.0.5
 */
final class Checkout_Rates_Response_Mapper {

	/**
	 * Most skipped quote codes named in one currency warning.
	 *
	 * @since 5.4.1
	 */
	private const MAX_CODES_LOGGED = 20;

	/**
	 * Transient prefix that limits the currency warning to one an hour per currency pair.
	 *
	 * @since 5.4.1
	 */
	private const CURRENCY_WARNING_TRANSIENT = 'wc_shipstation_checkout_rates_currency_warned_';

	/**
	 * Transient prefix that limits the duties warning to one an hour per service.
	 *
	 * @since 5.4.1
	 */
	private const DUTIES_WARNING_TRANSIENT = 'wc_shipstation_checkout_rates_duties_warned_';

	/**
	 * Whether the prepaid duties and taxes flag is on for the response being mapped.
	 *
	 * @since 5.4.1
	 *
	 * @var bool
	 */
	private bool $gpdt_enabled = false;

	/**
	 * Map a ShipStation rates response to an array of WC rate arrays.
	 *
	 * @since 5.0.5
	 * @since 5.4.1 Added the $store_currency parameter.
	 *
	 * @param array  $response       Raw ShipStation API response.
	 * @param string $store_currency Store base currency, the one WooCommerce reads rate costs in. Quotes in another currency are skipped; empty skips the check.
	 *
	 * @return array List of WC-compatible rate arrays.
	 */
	public function map( array $response, string $store_currency = '' ): array {
		$quotes = isset( $response['quotes'] ) && is_array( $response['quotes'] )
			? $response['quotes']
			: array();

		if ( empty( $quotes ) ) {
			return array();
		}

		$this->gpdt_enabled = Features::is_gpdt_enabled();

		$quote_id = isset( $response['quote_id'] )
			? (string) $response['quote_id']
			: '';

		$store_currency = strtoupper( trim( $store_currency ) );

		$rates    = array();
		$transits = array();
		$skipped  = array();

		foreach ( $quotes as $quote ) {
			if ( ! is_array( $quote ) ) {
				continue;
			}

			$currency = $this->quote_currency( $quote );

			if ( '' !== $store_currency && '' !== $currency && $currency !== $store_currency ) {
				$skipped[] = array(
					'code'     => isset( $quote['code'] ) && is_scalar( $quote['code'] ) ? (string) $quote['code'] : '',
					'currency' => $currency,
				);
				continue;
			}

			$mapped = $this->map_quote( $quote, $quote_id );

			if ( null !== $mapped ) {
				$rates[]    = $mapped;
				$transits[] = isset( $quote['transit_time'] ) && is_array( $quote['transit_time'] ) ? $quote['transit_time'] : array();
			}
		}

		if ( ! empty( $skipped ) ) {
			$this->log_currency_mismatch( $skipped, $store_currency, $quote_id );
		}

		return $this->make_ids_unique( $rates, $transits );
	}

	/**
	 * Get a quote's cost currency in uppercase.
	 *
	 * @since 5.4.1
	 *
	 * @param array $quote Single quote entry.
	 *
	 * @return string The currency, or an empty string when it is missing or not a string.
	 */
	private function quote_currency( array $quote ): string {
		return isset( $quote['cost']['currency'] ) && is_string( $quote['cost']['currency'] )
			? strtoupper( trim( $quote['cost']['currency'] ) )
			: '';
	}

	/**
	 * Log one warning naming the quotes skipped for their currency.
	 *
	 * A lasting mismatch repeats on every shipping recalculation, also for cached
	 * responses, so the warning is written once an hour per currency pair and the
	 * repeats go to the debug log. Nothing here may throw: the quotes that did
	 * match are already mapped.
	 *
	 * @since 5.4.1
	 *
	 * @param array  $skipped        Skipped quotes, each with its code and currency.
	 * @param string $store_currency Store base currency.
	 * @param string $quote_id       Top-level quote ID from the response.
	 *
	 * @return void
	 */
	private function log_currency_mismatch( array $skipped, string $store_currency, string $quote_id ): void {
		// The values come from the remote response: collapse control characters and
		// invalid UTF-8 first, then strip markup and bound their length.
		$clean = static function ( string $value, int $length ): string {
			$value = sanitize_text_field( Order_Util::sanitize_for_log( $value ) );

			return '' === $value ? '(none)' : mb_substr( $value, 0, $length );
		};

		try {
			$currencies = array_unique(
				array_map(
					static function ( array $quote ) use ( $clean ): string {
						return $clean( $quote['currency'], 10 );
					},
					$skipped
				)
			);
			$codes      = array_map(
				static function ( array $quote ) use ( $clean ): string {
					return $clean( $quote['code'], 64 );
				},
				array_slice( $skipped, 0, self::MAX_CODES_LOGGED )
			);
			$codes      = implode( ', ', $codes );
			$unnamed    = count( $skipped ) - self::MAX_CODES_LOGGED;

			if ( $unnamed > 0 ) {
				$codes .= sprintf( ' and %d more', $unnamed );
			}

			$message = sprintf(
				'ShipStation checkout rate quotes skipped: quoted in %1$s, but the store currency is %2$s. Skipped codes: %3$s.',
				implode( ', ', $currencies ),
				$clean( $store_currency, 10 ),
				$codes
			);
			$context = array( 'quote_id' => $quote_id );

			$pair = $currencies;
			sort( $pair );
			$throttle_key = self::CURRENCY_WARNING_TRANSIENT . md5( $store_currency . '|' . implode( ',', $pair ) );

			if ( false !== get_transient( $throttle_key ) ) {
				Logger::debug( $message, $context );
				return;
			}

			Logger::warning( $message, $context );
			set_transient( $throttle_key, 1, HOUR_IN_SECONDS );
		} catch ( \Throwable $e ) {
			// A throwing sanitize_text_field filter, transient or log handler must not cost
			// the shopper the rates that matched.
			return;
		}
	}

	/**
	 * Get a transit time in days.
	 *
	 * @since 5.3.9
	 *
	 * @param array $transit_time The quote's transit_time entry.
	 *
	 * @return float|null Days, or null when there is no numeric duration.
	 */
	private function transit_days( array $transit_time ): ?float {
		if ( ! isset( $transit_time['duration'] ) || ! is_numeric( $transit_time['duration'] ) ) {
			return null;
		}

		$duration = (float) $transit_time['duration'];

		switch ( $this->transit_units( $transit_time ) ) {
			case 'hour':
			case 'hours':
				return $duration / 24;
			case 'week':
			case 'weeks':
				return $duration * 7;
			default:
				// Days, business days, or a missing or unknown unit.
				return $duration;
		}
	}

	/**
	 * Get a transit time's unit in lowercase, with spaces for underscores.
	 *
	 * @since 5.3.9
	 *
	 * @param array $transit_time The quote's transit_time entry.
	 *
	 * @return string The unit, or an empty string when there is none.
	 */
	private function transit_units( array $transit_time ): string {
		return isset( $transit_time['units'] ) && is_string( $transit_time['units'] )
			? strtolower( str_replace( '_', ' ', trim( $transit_time['units'] ) ) )
			: '';
	}

	/**
	 * Same-name quotes get __2, __3: the GPDT quote keeps the base id, then cost and transit time.
	 *
	 * @since 5.3.9
	 * @since 5.4.1 The GPDT quote keeps the base id.
	 *
	 * @param array $rates    Mapped rates, with ids from their service names.
	 * @param array $transits Each rate's transit_time entry, by the same index.
	 *
	 * @return array The same rates with unique ids.
	 */
	private function make_ids_unique( array $rates, array $transits ): array {
		$groups = array();
		foreach ( $rates as $index => $rate ) {
			$groups[ $rate['id'] ][] = $index;
		}

		$taken = array_fill_keys( array_keys( $groups ), true );

		foreach ( $groups as $base_id => $indexes ) {
			if ( count( $indexes ) < 2 ) {
				continue;
			}

			// Sort on API values, not translated text, so every locale agrees.
			$keys = array();
			foreach ( $indexes as $index ) {
				$keys[ $index ] = array(
					// A GPDT quote keeps the base id, so a new quote cannot swap the shopper off it.
					isset( $rates[ $index ]['meta_data'][ Checkout_Rates_Options::GPDT_RATE_ID_META_KEY ] ) ? 0 : 1,
					$rates[ $index ]['cost'],
					// A missing duration sorts last.
					$this->transit_days( $transits[ $index ] ) ?? INF,
					$this->transit_units( $transits[ $index ] ),
					$rates[ $index ]['description'] ?? '',
					// usort() is not stable before PHP 8.
					$index,
				);
			}

			usort(
				$indexes,
				static function ( int $a, int $b ) use ( $keys ): int {
					return $keys[ $a ] <=> $keys[ $b ];
				}
			);

			$suffix = 1;
			foreach ( array_slice( $indexes, 1 ) as $index ) {
				do {
					++$suffix;
					$id = $base_id . '__' . $suffix;
				} while ( isset( $taken[ $id ] ) );

				$taken[ $id ]          = true;
				$rates[ $index ]['id'] = $id;
			}
		}

		return $rates;
	}

	/**
	 * Rate ids come from the service name, so they survive a new quote.
	 *
	 * @since 5.3.9
	 *
	 * @param string $label Quote display name.
	 *
	 * @return string
	 */
	private function rate_id( string $label ): string {
		// Fixed locale and no filters, so a service keeps one id everywhere.
		// Underscores become dashes, so no slug can contain the "__" suffix.
		$key = sanitize_title_with_dashes( remove_accents( $label, 'en_US' ), '', 'save' );
		$key = trim( (string) preg_replace( '/-+/', '-', (string) preg_replace( '/[^a-z0-9-]/', '-', $key ) ), '-' );

		if ( '' === $key ) {
			$key = substr( md5( $label ), 0, 12 );
		}

		return 'shipstation_' . $key;
	}

	/**
	 * Map a single quote entry to a WC rate array.
	 *
	 * Returns null if the entry should be skipped (missing or empty code, or missing cost).
	 *
	 * @since 5.0.5
	 *
	 * @param array  $quote    Single quote entry.
	 * @param string $quote_id Top-level quote ID from the response.
	 *
	 * @return array|null WC rate array or null when quote should be skipped.
	 */
	private function map_quote( array $quote, string $quote_id ): ?array {
		if ( ! isset( $quote['code'] ) || ! is_scalar( $quote['code'] ) || '' === (string) $quote['code'] ) {
			Logger::debug(
				'ShipStation checkout rate quote skipped: missing or invalid "code" field.',
				array( 'quote_id' => $quote_id )
			);
			return null;
		}

		if ( ! isset( $quote['cost']['amount'] ) || ! is_numeric( $quote['cost']['amount'] ) ) {
			Logger::debug(
				'ShipStation checkout rate quote skipped: missing or non-numeric "cost.amount" field.',
				array(
					'quote_id' => $quote_id,
					'code'     => (string) $quote['code'],
				)
			);
			return null;
		}

		$code  = (string) $quote['code'];
		$label = isset( $quote['display_name'] ) && is_string( $quote['display_name'] )
			? $quote['display_name']
			: '';

		if ( '' === $label ) {
			Logger::debug(
				'ShipStation checkout rate quote skipped: missing or empty "display_name" field.',
				array(
					'quote_id' => $quote_id,
					'code'     => $code,
				)
			);
			return null;
		}

		$rate = array(
			'id'        => $this->rate_id( $label ),
			'label'     => $label,
			'cost'      => (float) $quote['cost']['amount'],
			'meta_data' => array(
				Checkout_Rates_Options::RATE_CODE_META_KEY => $code,
				Checkout_Rates_Options::QUOTE_ID_META_KEY  => $quote_id,
			),
		);

		if ( $this->gpdt_enabled ) {
			$rate['meta_data'] += $this->gpdt_meta( $quote, $quote_id, $code );
		}

		// description and delivery_time map onto the native WC_Shipping_Rate fields
		// (WC 9.2+), which the block cart/checkout renders. The shipping method applies
		// them to the rate object after add_rate(). They are deliberately kept off the
		// label so the label (which WooCommerce persists as the order shipping method
		// title) stays the bare carrier name.
		if ( isset( $quote['description'] ) && is_string( $quote['description'] ) && '' !== $quote['description'] ) {
			$rate['description'] = $quote['description'];
		}

		$delivery_time = isset( $quote['transit_time'] ) && is_array( $quote['transit_time'] )
			? $this->format_delivery_time( $quote['transit_time'] )
			: '';

		if ( '' !== $delivery_time ) {
			$rate['delivery_time'] = $delivery_time;
		}

		return $rate;
	}

	/**
	 * Get a GPDT quote's rate id and duties amount as rate meta.
	 *
	 * @since 5.4.1
	 *
	 * @param array  $quote    Single quote entry.
	 * @param string $quote_id Top-level quote ID from the response.
	 * @param string $code     The quote's code.
	 *
	 * @return array Meta entries, empty when the quote carries neither.
	 */
	private function gpdt_meta( array $quote, string $quote_id, string $code ): array {
		$meta = array();

		$rate_id = is_string( $quote['gpdt_rate_id'] ?? null ) ? trim( $quote['gpdt_rate_id'] ) : '';

		if ( '' !== $rate_id ) {
			$meta[ Checkout_Rates_Options::GPDT_RATE_ID_META_KEY ] = $rate_id;
		}

		$duties = $this->duties_taxes( $quote, $quote_id, $code );

		if ( null !== $duties ) {
			$meta[ Checkout_Rates_Options::DUTIES_TAXES_META_KEY ] = $duties;
		}

		return $meta;
	}

	/**
	 * Get a quote's duties and taxes amount at the store's price decimals.
	 *
	 * The amount must be above zero and no more than the quote's cost. null, an
	 * empty string, zero and an amount that rounds to zero at the store's price
	 * decimals mean no amount; any other invalid value is logged and ignored.
	 *
	 * @since 5.4.1
	 *
	 * @param array  $quote    Single quote entry, with a display_name and a numeric cost.amount.
	 * @param string $quote_id Top-level quote ID from the response.
	 * @param string $code     The quote's code.
	 *
	 * @return string|null The amount, or null when there is none.
	 */
	private function duties_taxes( array $quote, string $quote_id, string $code ): ?string {
		$value = $quote['cost']['duties_taxes'] ?? null;

		// PHP 7.4 rejects trailing whitespace in is_numeric() and PHP 8 accepts it, form feed included.
		if ( is_string( $value ) ) {
			$value = trim( $value, " \t\n\r\0\x0B\f" );
		}

		// ShipStation may send null, '' or 0 on any quote, so they are not logged.
		if ( null === $value || '' === $value || ( is_numeric( $value ) && 0.0 === (float) $value ) ) {
			return null;
		}

		$reason = '';

		if ( ! is_numeric( $value ) || is_nan( (float) $value ) ) {
			$reason = 'not a number';
		} elseif ( (float) $value <= 0 ) {
			$reason = 'not above zero';
		} elseif ( (float) $value > (float) $quote['cost']['amount'] ) {
			$reason = sprintf( 'above cost.amount %s', (string) $quote['cost']['amount'] );
		}

		if ( '' !== $reason ) {
			$this->log_invalid_duties( $quote['cost']['duties_taxes'], $reason, $quote_id, $code, (string) $quote['display_name'] );

			return null;
		}

		// Casting first keeps wc_format_decimal() from turning "1e2" into "12".
		$amount = wc_format_decimal( (float) $value, wc_get_price_decimals() );

		// Below the store's price decimals there is nothing to store and nothing to warn about.
		return (float) $amount > 0 ? $amount : null;
	}

	/**
	 * Log a duties and taxes value that was not stored.
	 *
	 * A bad value repeats on every shipping recalculation, also for cached responses, so the
	 * warning is written once an hour per service and the repeats go to the debug log. The
	 * throttle is keyed on the service name, because ShipStation sends a new quote code on
	 * every rates call. When the throttle cannot be read, the warning is written.
	 *
	 * @since 5.4.1
	 *
	 * @param mixed  $value    The value from the response.
	 * @param string $reason   Why the value was not stored.
	 * @param string $quote_id Top-level quote ID from the response.
	 * @param string $code     The quote's code.
	 * @param string $service  The quote's display name.
	 *
	 * @return void
	 */
	private function log_invalid_duties( $value, string $reason, string $quote_id, string $code, string $service ): void {
		// The values come from the remote response.
		$clean = static function ( string $text ): string {
			$text = sanitize_text_field( Order_Util::sanitize_for_log( $text ) );

			return '' === $text ? '(none)' : mb_substr( $text, 0, 64 );
		};

		$throttle_key = self::DUTIES_WARNING_TRANSIENT . md5( $service );
		$warned       = false;

		try {
			$warned = false !== get_transient( $throttle_key );
		} catch ( \Throwable $e ) {
			// A failing read must not hide the warning, so it counts as not warned yet.
			$warned = false;
		}

		try {
			$message = sprintf(
				'ShipStation checkout rate quote %1$s: invalid "cost.duties_taxes" value "%2$s" ignored (%3$s).',
				$clean( $code ),
				$clean( is_scalar( $value ) && ! is_bool( $value ) ? (string) $value : gettype( $value ) ),
				$clean( $reason )
			);
			$context = array( 'quote_id' => $quote_id );

			if ( $warned ) {
				Logger::debug( $message, $context );
				return;
			}

			Logger::warning( $message, $context );
			set_transient( $throttle_key, 1, HOUR_IN_SECONDS );
		} catch ( \Throwable $e ) {
			// A failing transient or log handler must not cost the shopper the rate.
			return;
		}
	}

	/**
	 * Format a ShipStation transit_time block into a human-readable delivery estimate.
	 *
	 * Returns an empty string when the duration is missing, non-numeric, or not positive.
	 * The duration is rounded to the nearest whole unit. Known units (day, business day,
	 * hour, week) are localized with correct singular/plural forms; a missing unit defaults
	 * to days. An unrecognized unit is passed through sanitized of any markup.
	 *
	 * @since 5.2.1
	 *
	 * @param array $transit_time ShipStation transit_time block (duration, units).
	 *
	 * @return string Delivery estimate such as "2 days" or "1 business day", or '' when unavailable.
	 */
	private function format_delivery_time( array $transit_time ): string {
		if ( ! isset( $transit_time['duration'] ) || ! is_numeric( $transit_time['duration'] ) ) {
			return '';
		}

		$duration = (int) round( (float) $transit_time['duration'] );

		if ( $duration < 1 ) {
			return '';
		}

		$units = $this->transit_units( $transit_time );

		switch ( $units ) {
			case '':
			case 'day':
			case 'days':
				/* translators: %d: number of days. */
				return sprintf( _n( '%d day', '%d days', $duration, 'woocommerce-shipstation-integration' ), $duration );
			case 'business day':
			case 'business days':
				/* translators: %d: number of business days. */
				return sprintf( _n( '%d business day', '%d business days', $duration, 'woocommerce-shipstation-integration' ), $duration );
			case 'hour':
			case 'hours':
				/* translators: %d: number of hours. */
				return sprintf( _n( '%d hour', '%d hours', $duration, 'woocommerce-shipstation-integration' ), $duration );
			case 'week':
			case 'weeks':
				/* translators: %d: number of weeks. */
				return sprintf( _n( '%d week', '%d weeks', $duration, 'woocommerce-shipstation-integration' ), $duration );
			default:
				// Unrecognized unit from the API: render defensively, stripped of any markup.
				return $duration . ' ' . sanitize_text_field( $units );
		}
	}
}
