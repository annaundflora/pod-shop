<?php
/**
 * OrderItem value object.
 *
 * One line item within an OrderCreate request. The optional
 * `customerPrice` is the price the end-customer paid (used by Spreadconnect
 * for tax-relevant invoicing).
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable OrderItem value object.
 *
 * Validation:
 *   - `sku` non-empty string.
 *   - `quantity` integer >= 1.
 *   - `customerPrice` optional Money (when provided, fully validated by Money::fromArray()).
 */
final readonly class OrderItem
{
	public function __construct(
		public string $sku,
		public int $quantity,
		public ?Money $customerPrice = null,
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

		$sku = $data['sku'] ?? null;
		if ( ! is_string( $sku ) || '' === $sku ) {
			throw new InvalidArgumentException( 'OrderItem: field "sku" must be a non-empty string.' );
		}

		$quantity = $data['quantity'] ?? null;
		if ( ! is_int( $quantity ) || $quantity < 1 ) {
			throw new InvalidArgumentException( 'OrderItem: field "quantity" must be an integer >= 1.' );
		}

		$customer_price = null;
		if ( isset( $data['customerPrice'] ) ) {
			if ( ! is_array( $data['customerPrice'] ) ) {
				throw new InvalidArgumentException(
					'OrderItem: field "customerPrice" must be an array convertible to Money.'
				);
			}
			$customer_price = Money::fromArray( $data['customerPrice'] );
		}

		return new self(
			sku: $sku,
			quantity: $quantity,
			customerPrice: $customer_price,
		);
	}
}
