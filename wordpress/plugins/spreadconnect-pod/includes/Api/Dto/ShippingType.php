<?php
/**
 * ShippingType response DTO.
 *
 * One available shipping option returned by `GET /shippingTypes`. Used in
 * Order-Edit dropdown and Settings default-shipping-type picker.
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable ShippingType value object.
 *
 * Validation:
 *   - `id`, `company`, `name` must be strings (no further empty-check per architecture).
 *   - `price` is a Money instance (delegates currency/amount validation).
 *   - `description` optional.
 */
final readonly class ShippingType
{
	public function __construct(
		public string $id,
		public string $company,
		public string $name,
		public Money $price,
		public ?string $description = null,
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
		if ( ! is_string( $id ) ) {
			throw new InvalidArgumentException( 'ShippingType: field "id" must be a string.' );
		}

		$company = $data['company'] ?? null;
		if ( ! is_string( $company ) ) {
			throw new InvalidArgumentException( 'ShippingType: field "company" must be a string.' );
		}

		$name = $data['name'] ?? null;
		if ( ! is_string( $name ) ) {
			throw new InvalidArgumentException( 'ShippingType: field "name" must be a string.' );
		}

		$price_raw = $data['price'] ?? null;
		if ( ! is_array( $price_raw ) ) {
			throw new InvalidArgumentException(
				'ShippingType: field "price" must be an array convertible to Money.'
			);
		}
		$price = Money::fromArray( $price_raw );

		$description = $data['description'] ?? null;
		if ( null !== $description && ! is_string( $description ) ) {
			throw new InvalidArgumentException( 'ShippingType: field "description" must be a string or null.' );
		}

		return new self(
			id: $id,
			company: $company,
			name: $name,
			price: $price,
			description: $description,
		);
	}
}
