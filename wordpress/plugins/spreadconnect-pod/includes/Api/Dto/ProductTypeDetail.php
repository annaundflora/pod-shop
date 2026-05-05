<?php
/**
 * ProductTypeDetail response DTO.
 *
 * Catalog metadata returned by `GET /productTypes/{id}` — defines the
 * size and color label-tables the {@see \SpreadconnectPod\Catalog\ProductMapper}
 * uses to translate `Variant.sizeId`/`Variant.colorId` into human-readable
 * `pa_groesse` / `pa_farbe` term labels on a `WC_Product_Variation`.
 *
 * Architecture: `architecture.md` -> "Outbound Endpoints" Z. 113
 *   `ProductTypeDetail{sizes[], colors[], printAreas[], …}`.
 *
 * Slice 22 only consumes `sizes[]` and `colors[]`; `printAreas` and any
 * other fields (e.g. `name`, `category`) are ignored intentionally — Slice
 * 36 (`/designs` integration) may revisit this DTO and add fields without
 * breaking the Slice 22 mapper contract.
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable ProductTypeDetail value object.
 *
 * Validation:
 *   - `id` non-empty string.
 *   - `sizes` array of {@see ProductTypeSize}.
 *   - `colors` array of {@see ProductTypeColor}.
 *
 * Both `sizes` and `colors` may be empty — a product type without size or
 * color axes is theoretically possible (single-SKU article). In that case
 * the `ProductMapper` will emit a warning-level log and create a single
 * variation per Variant without the missing attribute. Empty arrays are
 * not a validation error.
 */
final readonly class ProductTypeDetail
{
	/**
	 * @param ProductTypeSize[]  $sizes
	 * @param ProductTypeColor[] $colors
	 */
	public function __construct(
		public string $id,
		public array $sizes,
		public array $colors,
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

		$id = $data['id'] ?? null;
		if ( ! is_string( $id ) || '' === $id ) {
			throw new InvalidArgumentException(
				'ProductTypeDetail: field "id" must be a non-empty string.'
			);
		}

		$sizes_raw = $data['sizes'] ?? array();
		if ( ! is_array( $sizes_raw ) ) {
			throw new InvalidArgumentException( 'ProductTypeDetail: field "sizes" must be an array.' );
		}
		$sizes = array();
		foreach ( $sizes_raw as $index => $item ) {
			if ( ! is_array( $item ) ) {
				throw new InvalidArgumentException(
					sprintf( 'ProductTypeDetail: field "sizes[%s]" must be an array.', (string) $index )
				);
			}
			$sizes[] = ProductTypeSize::fromArray( $item );
		}

		$colors_raw = $data['colors'] ?? array();
		if ( ! is_array( $colors_raw ) ) {
			throw new InvalidArgumentException( 'ProductTypeDetail: field "colors" must be an array.' );
		}
		$colors = array();
		foreach ( $colors_raw as $index => $item ) {
			if ( ! is_array( $item ) ) {
				throw new InvalidArgumentException(
					sprintf( 'ProductTypeDetail: field "colors[%s]" must be an array.', (string) $index )
				);
			}
			$colors[] = ProductTypeColor::fromArray( $item );
		}

		return new self(
			id: $id,
			sizes: $sizes,
			colors: $colors,
		);
	}
}
