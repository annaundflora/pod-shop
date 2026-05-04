<?php
/**
 * ProductTypeSize value object.
 *
 * One entry of the size axis of a product type — used by the mapper to
 * translate a Variant's `sizeId` into a human-readable WC term label
 * (`pa_groesse`).
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable size-axis entry.
 *
 * Validation:
 *   - `id` non-empty string (matches `Variant.sizeId`).
 *   - `label` non-empty string (used as WC term name; sanitized to slug).
 */
final readonly class ProductTypeSize
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
				'ProductTypeSize: field "id" must be a non-empty string.'
			);
		}

		$label = $data['label'] ?? null;
		if ( ! is_string( $label ) || '' === $label ) {
			throw new InvalidArgumentException(
				'ProductTypeSize: field "label" must be a non-empty string.'
			);
		}

		return new self( id: $id, label: $label );
	}
}
