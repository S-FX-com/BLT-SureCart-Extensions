<?php
/**
 * Safe property access for API response objects.
 *
 * SureCart's PHP models expose their attributes through a magic `__get()`.
 * PHP routes `isset()` and `empty()` on such a property to `__isset()`, and a
 * class that defines `__get()` without `__isset()` therefore reports every
 * attribute as "not set" — while `$model->attribute` returns the value
 * perfectly well. So this reads FALSE:
 *
 *     isset( $order->checkout )   // false, even though $order->checkout exists
 *
 * Guarding model access with `isset()`/`empty()` consequently makes a fully
 * populated response look completely empty, with no error anywhere — which is
 * exactly how the Reports module shipped a "successful" report containing zero
 * orders. Every read of a SureCart response goes through here instead.
 *
 * Handles plain arrays, stdClass, ArrayAccess collections, and magic-accessor
 * models uniformly, so callers don't care which one they were handed.
 *
 * @package BLT\SCE
 */

namespace BLT\SCE\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Obj
 */
final class Obj {

	/**
	 * Read a member, whatever the container is.
	 *
	 * @param mixed  $bag     Array, object, or anything else.
	 * @param string $key     Member name.
	 * @param mixed  $default Returned when the member is absent or null.
	 * @return mixed
	 */
	public static function get( $bag, $key, $default = null ) {
		if ( is_array( $bag ) ) {
			return array_key_exists( $key, $bag ) && null !== $bag[ $key ] ? $bag[ $key ] : $default;
		}

		if ( ! is_object( $bag ) ) {
			return $default;
		}

		if ( $bag instanceof \ArrayAccess && $bag->offsetExists( $key ) ) {
			$value = $bag[ $key ];

			return null === $value ? $default : $value;
		}

		// Public, declared or dynamically assigned properties.
		$vars = get_object_vars( $bag );

		if ( array_key_exists( $key, $vars ) ) {
			return null === $vars[ $key ] ? $default : $vars[ $key ];
		}

		// Magic accessor. Only attempted when the class actually defines one,
		// so a plain object never triggers an undefined-property warning.
		if ( method_exists( $bag, '__get' ) ) {
			try {
				$value = $bag->$key;
			} catch ( \Throwable $e ) {
				return $default;
			}

			return null === $value ? $default : $value;
		}

		return $default;
	}

	/**
	 * Read a member as a trimmed string.
	 *
	 * @param mixed  $bag     Container.
	 * @param string $key     Member name.
	 * @param string $default Fallback.
	 * @return string
	 */
	public static function str( $bag, $key, $default = '' ) {
		$value = self::get( $bag, $key );

		if ( is_scalar( $value ) ) {
			$value = trim( (string) $value );

			// A blank string counts as absent, so a caller can pass a
			// meaningful fallback (an ID standing in for a missing name)
			// without repeating the empty check at every call site.
			if ( '' !== $value ) {
				return $value;
			}
		}

		return $default;
	}

	/**
	 * Read a member as an integer.
	 *
	 * @param mixed  $bag     Container.
	 * @param string $key     Member name.
	 * @param int    $default Fallback used when absent or non-numeric.
	 * @return int
	 */
	public static function int( $bag, $key, $default = 0 ) {
		$value = self::get( $bag, $key );

		return is_numeric( $value ) ? (int) $value : $default;
	}

	/**
	 * Read a member that is expected to be a nested object (an expanded
	 * relation). Returns null rather than a scalar — an unexpanded relation
	 * comes back as a bare ID string, which callers must not mistake for the
	 * object itself.
	 *
	 * @param mixed  $bag Container.
	 * @param string $key Member name.
	 * @return object|null
	 */
	public static function obj( $bag, $key ) {
		$value = self::get( $bag, $key );

		return is_object( $value ) ? $value : null;
	}

	/**
	 * Read a member that is expected to be a list, unwrapping SureCart's
	 * `{ data: [] }` collection envelope and any Traversable.
	 *
	 * @param mixed  $bag Container.
	 * @param string $key Member name.
	 * @return array
	 */
	public static function items( $bag, $key ) {
		$value = self::get( $bag, $key );

		if ( is_object( $value ) || is_array( $value ) ) {
			$nested = self::get( $value, 'data' );

			if ( null !== $nested ) {
				$value = $nested;
			}
		}

		if ( $value instanceof \Traversable ) {
			$value = iterator_to_array( $value );
		}

		return is_array( $value ) ? array_values( $value ) : array();
	}
}
