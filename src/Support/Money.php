<?php
/**
 * Decimal-safe money helpers. Shippo returns rate amounts as decimal
 * strings in dollars (e.g. "5.50"); this plugin stores and compares
 * everything as integer cents and never does float arithmetic on money.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Money
 */
final class Money {

	/**
	 * Convert a decimal dollar string (as Shippo returns, e.g. "5.5" or
	 * "12.00") to integer cents without floating point rounding error.
	 *
	 * @param string $decimal Decimal amount as a string.
	 * @return int
	 */
	public static function decimal_string_to_cents( $decimal ) {
		$decimal  = trim( (string) $decimal );
		$negative = false;

		if ( '' !== $decimal && '-' === $decimal[0] ) {
			$negative = true;
			$decimal  = substr( $decimal, 1 );
		}

		$parts        = explode( '.', $decimal, 2 );
		$whole_part   = isset( $parts[0] ) && '' !== $parts[0] ? $parts[0] : '0';
		$decimal_part = isset( $parts[1] ) ? $parts[1] : '';
		// Round to the nearest cent (half up) using only integer math —
		// three decimal digits is enough headroom for any real-world
		// currency amount to round correctly; a 4th+ digit only matters
		// for rounding the 3rd, which half-up rounding already accounts for.
		$decimal_part = str_pad( substr( $decimal_part, 0, 3 ), 3, '0' );

		$milli_cents = ( (int) $whole_part * 1000 ) + (int) $decimal_part;
		$cents       = intdiv( $milli_cents + 5, 10 );

		return $negative ? -$cents : $cents;
	}

	/**
	 * Format integer cents as a decimal dollar string for display or for
	 * sending back to an API that expects a decimal string.
	 *
	 * @param int $cents Amount in integer cents.
	 * @return string
	 */
	public static function cents_to_decimal_string( $cents ) {
		$cents     = (int) $cents;
		$negative  = $cents < 0;
		$cents     = abs( $cents );
		$formatted = sprintf( '%d.%02d', intdiv( $cents, 100 ), $cents % 100 );

		return $negative ? '-' . $formatted : $formatted;
	}
}
