<?php
/**
 * Address value object.
 *
 * Postal address used for both billing and shipping in OrderCreate. Country
 * is enforced as ISO 3166-1 alpha-2 at the DTO boundary; Spreadconnect's
 * server performs the additional country-list validation downstream.
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable Address value object.
 *
 * Validation:
 *   - `firstName`, `lastName`, `street`, `zipCode`, `city` non-empty.
 *   - `country` matches `^[A-Z]{2}$` (ISO 3166-1 alpha-2 — exactly two uppercase ASCII letters).
 *   - `streetAnnex` and `state` are optional and may be null.
 */
final readonly class Address
{
	/**
	 * Pattern for ISO 3166-1 alpha-2 country codes (KISS: format-only check).
	 */
	private const COUNTRY_PATTERN = '/^[A-Z]{2}$/';

	public function __construct(
		public string $firstName,
		public string $lastName,
		public string $street,
		public string $zipCode,
		public string $city,
		public string $country,
		public ?string $streetAnnex = null,
		public ?string $state = null,
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

		$first_name = $data['firstName'] ?? null;
		$last_name  = $data['lastName'] ?? null;
		$street     = $data['street'] ?? null;
		$zip_code   = $data['zipCode'] ?? null;
		$city       = $data['city'] ?? null;
		$country    = $data['country'] ?? null;

		if ( ! is_string( $first_name ) || '' === $first_name ) {
			throw new InvalidArgumentException( 'Address: field "firstName" must be a non-empty string.' );
		}
		if ( ! is_string( $last_name ) || '' === $last_name ) {
			throw new InvalidArgumentException( 'Address: field "lastName" must be a non-empty string.' );
		}
		if ( ! is_string( $street ) || '' === $street ) {
			throw new InvalidArgumentException( 'Address: field "street" must be a non-empty string.' );
		}
		if ( ! is_string( $zip_code ) || '' === $zip_code ) {
			throw new InvalidArgumentException( 'Address: field "zipCode" must be a non-empty string.' );
		}
		if ( ! is_string( $city ) || '' === $city ) {
			throw new InvalidArgumentException( 'Address: field "city" must be a non-empty string.' );
		}
		if ( ! is_string( $country ) || 1 !== preg_match( self::COUNTRY_PATTERN, $country ) ) {
			throw new InvalidArgumentException(
				'Address: field "country" must be an ISO 3166-1 alpha-2 code (exactly two uppercase ASCII letters).'
			);
		}

		$street_annex = $data['streetAnnex'] ?? null;
		if ( null !== $street_annex && ! is_string( $street_annex ) ) {
			throw new InvalidArgumentException( 'Address: field "streetAnnex" must be a string or null.' );
		}

		$state = $data['state'] ?? null;
		if ( null !== $state && ! is_string( $state ) ) {
			throw new InvalidArgumentException( 'Address: field "state" must be a string or null.' );
		}

		return new self(
			firstName: $first_name,
			lastName: $last_name,
			street: $street,
			zipCode: $zip_code,
			city: $city,
			country: $country,
			streetAnnex: $street_annex,
			state: $state,
		);
	}
}
