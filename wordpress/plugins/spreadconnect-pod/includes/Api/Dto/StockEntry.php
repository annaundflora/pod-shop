<?php
/**
 * StockEntry response DTO.
 *
 * One per-SKU stock data point returned by `GET /stock` and
 * `GET /stock/{sku}`. Backs the per-variation stock cache (Slice 36).
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable StockEntry value object.
 *
 * Validation:
 *   - `sku` must be a string.
 *   - `quantity` must be an integer >= 0.
 *   - `state` optional.
 */
final readonly class StockEntry
{
	public function __construct(
		public string $sku,
		public int $quantity,
		public ?string $state = null,
	) {
	}

	/**
	 * Construct from a Spreadconnect API response array (snake_case or camelCase).
	 *
	 * @param array<string, mixed> $data
	 */
	public static function fromResponse( array $data ): self
	{
		$data = DtoMapper::snakeToCamel( $data );

		$sku = $data['sku'] ?? null;
		if ( ! is_string( $sku ) ) {
			throw new InvalidArgumentException( 'StockEntry: field "sku" must be a string.' );
		}

		$quantity = $data['quantity'] ?? null;
		if ( ! is_int( $quantity ) || $quantity < 0 ) {
			throw new InvalidArgumentException( 'StockEntry: field "quantity" must be an integer >= 0.' );
		}

		$state = $data['state'] ?? null;
		if ( null !== $state && ! is_string( $state ) ) {
			throw new InvalidArgumentException( 'StockEntry: field "state" must be a string or null.' );
		}

		return new self(
			sku: $sku,
			quantity: $quantity,
			state: $state,
		);
	}
}
