<?php
/**
 * Money value object.
 *
 * Represents a decimal amount in a fixed currency. Amount stored as string
 * (decimal-as-string convention) to avoid float-precision loss when
 * round-tripping through Spreadconnect's REST payloads.
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable Money value object: amount + currency, optional tax decoration.
 *
 * Validation:
 *   - `amount`    must match `^\d+\.\d{2}$` (always two decimal places, no sign).
 *   - `currency`  must match `^[A-Z]{3}$` (ISO-4217 alphabetic code).
 *   - `taxRate`   when provided, must match `^\d+\.\d{2}$`.
 *   - `taxAmount` when provided, must match `^\d+\.\d{2}$`.
 */
final readonly class Money
{
	/**
	 * Pattern for decimal-as-string fields (`123.45`, `0.00`, `1234567.89`).
	 */
	private const DECIMAL_PATTERN = '/^\d+\.\d{2}$/';

	/**
	 * Pattern for ISO-4217 currency codes.
	 */
	private const CURRENCY_PATTERN = '/^[A-Z]{3}$/';

	public function __construct(
		public string $amount,
		public string $currency,
		public ?string $taxRate = null,
		public ?string $taxAmount = null,
	) {
	}

	/**
	 * Construct from a snake_case or camelCase array.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self
	{
		$data = DtoMapper::snakeToCamel( $data );

		$amount   = $data['amount'] ?? null;
		$currency = $data['currency'] ?? null;

		if ( ! is_string( $amount ) || 1 !== preg_match( self::DECIMAL_PATTERN, $amount ) ) {
			throw new InvalidArgumentException(
				'Money: field "amount" must be a decimal-as-string matching ^\\d+\\.\\d{2}$.'
			);
		}

		if ( ! is_string( $currency ) || 1 !== preg_match( self::CURRENCY_PATTERN, $currency ) ) {
			throw new InvalidArgumentException(
				'Money: field "currency" must be a 3-letter ISO-4217 uppercase code.'
			);
		}

		$tax_rate = $data['taxRate'] ?? null;
		if ( null !== $tax_rate ) {
			if ( ! is_string( $tax_rate ) || 1 !== preg_match( self::DECIMAL_PATTERN, $tax_rate ) ) {
				throw new InvalidArgumentException(
					'Money: field "taxRate" must be a decimal-as-string matching ^\\d+\\.\\d{2}$.'
				);
			}
		}

		$tax_amount = $data['taxAmount'] ?? null;
		if ( null !== $tax_amount ) {
			if ( ! is_string( $tax_amount ) || 1 !== preg_match( self::DECIMAL_PATTERN, $tax_amount ) ) {
				throw new InvalidArgumentException(
					'Money: field "taxAmount" must be a decimal-as-string matching ^\\d+\\.\\d{2}$.'
				);
			}
		}

		return new self(
			amount: $amount,
			currency: $currency,
			taxRate: $tax_rate,
			taxAmount: $tax_amount,
		);
	}
}
