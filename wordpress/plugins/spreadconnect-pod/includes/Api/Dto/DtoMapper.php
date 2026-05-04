<?php
/**
 * Snake_case <-> camelCase mapper for DTO array payloads.
 *
 * Pure-PHP helper used by every DTO factory before reading fields. Operates
 * exclusively on array keys (never on PHP property names) and recurses into
 * nested arrays (e.g. `order_items`, `data.entity`).
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

/**
 * Recursive snake_case <-> camelCase array-key mapper.
 *
 * Conventions:
 *   - `foo_bar` <-> `fooBar`
 *   - `foo_bar_baz` <-> `fooBarBaz`
 *   - Numeric/list-array keys are preserved untouched (recursive descent only).
 *   - Non-array values are returned as-is.
 *
 * Spreadconnect's REST API exclusively uses snake_case JSON. The DTOs in this
 * namespace expose camelCase property names. Factories therefore call
 * {@see self::snakeToCamel()} on the raw response array before reading
 * fields, and {@see self::camelToSnake()} on the request array before
 * encoding the body.
 */
final class DtoMapper
{
	/**
	 * Convert all string keys in `$input` from snake_case to camelCase.
	 *
	 * Recurses into nested arrays. Non-string keys (numeric list indexes)
	 * pass through unchanged so that lists of items are preserved.
	 *
	 * @param array<int|string, mixed> $input
	 *
	 * @return array<int|string, mixed>
	 */
	public static function snakeToCamel( array $input ): array
	{
		$output = array();

		foreach ( $input as $key => $value ) {
			$new_key = is_string( $key ) ? self::snakeToCamelKey( $key ) : $key;

			if ( is_array( $value ) ) {
				$value = self::snakeToCamel( $value );
			}

			$output[ $new_key ] = $value;
		}

		return $output;
	}

	/**
	 * Convert all string keys in `$input` from camelCase to snake_case.
	 *
	 * Recurses into nested arrays. Non-string keys pass through unchanged.
	 *
	 * @param array<int|string, mixed> $input
	 *
	 * @return array<int|string, mixed>
	 */
	public static function camelToSnake( array $input ): array
	{
		$output = array();

		foreach ( $input as $key => $value ) {
			$new_key = is_string( $key ) ? self::camelToSnakeKey( $key ) : $key;

			if ( is_array( $value ) ) {
				$value = self::camelToSnake( $value );
			}

			$output[ $new_key ] = $value;
		}

		return $output;
	}

	/**
	 * Convert a single snake_case key to camelCase.
	 *
	 * Examples:
	 *   - `foo_bar`        -> `fooBar`
	 *   - `foo_bar_baz`    -> `fooBarBaz`
	 *   - `foo`            -> `foo`
	 *   - `external_order_reference` -> `externalOrderReference`
	 */
	private static function snakeToCamelKey( string $key ): string
	{
		if ( ! str_contains( $key, '_' ) ) {
			return $key;
		}

		$parts  = explode( '_', $key );
		$result = array_shift( $parts );

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				// Preserve consecutive underscores deterministically.
				continue;
			}
			$result .= ucfirst( $part );
		}

		return $result;
	}

	/**
	 * Convert a single camelCase key to snake_case.
	 *
	 * Examples:
	 *   - `fooBar`         -> `foo_bar`
	 *   - `fooBarBaz`      -> `foo_bar_baz`
	 *   - `foo`            -> `foo`
	 *   - `externalOrderReference` -> `external_order_reference`
	 */
	private static function camelToSnakeKey( string $key ): string
	{
		$result = preg_replace( '/([a-z\d])([A-Z])/', '$1_$2', $key );
		if ( ! is_string( $result ) ) {
			return $key;
		}

		return strtolower( $result );
	}
}
