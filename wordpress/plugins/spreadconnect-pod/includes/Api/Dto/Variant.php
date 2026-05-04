<?php
/**
 * Variant value object.
 *
 * One purchasable variant of a Spreadconnect article: identified by SKU,
 * scoped by size and color, optionally carrying a price calculation breakdown.
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable Variant value object.
 *
 * Validation:
 *   - `sku`, `sizeId`, `colorId` must be strings (no further empty-check per
 *     architecture: "no hard validations").
 *   - `priceCalculation` optional Money (when provided, fully validated).
 */
final readonly class Variant
{
	public function __construct(
		public string $sku,
		public string $sizeId,
		public string $colorId,
		public ?Money $priceCalculation = null,
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
		if ( ! is_string( $sku ) ) {
			throw new InvalidArgumentException( 'Variant: field "sku" must be a string.' );
		}

		$size_id = $data['sizeId'] ?? null;
		if ( ! is_string( $size_id ) ) {
			throw new InvalidArgumentException( 'Variant: field "sizeId" must be a string.' );
		}

		$color_id = $data['colorId'] ?? null;
		if ( ! is_string( $color_id ) ) {
			throw new InvalidArgumentException( 'Variant: field "colorId" must be a string.' );
		}

		$price_calculation = null;
		if ( isset( $data['priceCalculation'] ) ) {
			if ( ! is_array( $data['priceCalculation'] ) ) {
				throw new InvalidArgumentException(
					'Variant: field "priceCalculation" must be an array convertible to Money.'
				);
			}
			$price_calculation = Money::fromArray( $data['priceCalculation'] );
		}

		return new self(
			sku: $sku,
			sizeId: $size_id,
			colorId: $color_id,
			priceCalculation: $price_calculation,
		);
	}
}
