<?php
/**
 * ProductTypeColor value object.
 *
 * One entry of the color axis of a product type — used by the mapper to
 * translate a Variant's `colorId` into a human-readable WC term label
 * (`pa_farbe`).
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable color-axis entry.
 *
 * Validation:
 *   - `id` non-empty string (matches `Variant.colorId`).
 *   - `label` non-empty string (used as WC term name; sanitized to slug).
 */
final readonly class ProductTypeColor
{
	public function __construct(
		public string $id,
		public string $label,
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

		$id = $data['id'] ?? null;
		if ( ! is_string( $id ) || '' === $id ) {
			throw new InvalidArgumentException(
				'ProductTypeColor: field "id" must be a non-empty string.'
			);
		}

		$label = $data['label'] ?? null;
		if ( ! is_string( $label ) || '' === $label ) {
			throw new InvalidArgumentException(
				'ProductTypeColor: field "label" must be a non-empty string.'
			);
		}

		return new self( id: $id, label: $label );
	}
}
