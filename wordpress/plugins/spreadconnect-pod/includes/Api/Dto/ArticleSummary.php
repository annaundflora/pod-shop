<?php
/**
 * ArticleSummary response DTO.
 *
 * Lightweight projection returned by `GET /articles` (list endpoint).
 * Carries only the fields needed for catalog browsing; full attribute
 * payload lives in {@see ArticleDetail}.
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable ArticleSummary value object.
 *
 * Validation:
 *   - `id` non-empty string (TEXT-stored on WP side; format not contract-fixed).
 *   - `title` non-empty string.
 *   - `productTypeId` non-empty string.
 *   - `variants` array of Variant.
 *   - `description`, `designId`, `state` optional.
 */
final readonly class ArticleSummary
{
	/**
	 * @param Variant[] $variants
	 */
	public function __construct(
		public string $id,
		public string $title,
		public string $productTypeId,
		public array $variants,
		public ?string $description = null,
		public ?string $designId = null,
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

		$id = $data['id'] ?? null;
		if ( ! is_string( $id ) || '' === $id ) {
			throw new InvalidArgumentException( 'ArticleSummary: field "id" must be a non-empty string.' );
		}

		$title = $data['title'] ?? null;
		if ( ! is_string( $title ) || '' === $title ) {
			throw new InvalidArgumentException( 'ArticleSummary: field "title" must be a non-empty string.' );
		}

		$product_type_id = $data['productTypeId'] ?? null;
		if ( ! is_string( $product_type_id ) || '' === $product_type_id ) {
			throw new InvalidArgumentException(
				'ArticleSummary: field "productTypeId" must be a non-empty string.'
			);
		}

		$variants_raw = $data['variants'] ?? array();
		if ( ! is_array( $variants_raw ) ) {
			throw new InvalidArgumentException( 'ArticleSummary: field "variants" must be an array.' );
		}
		$variants = array();
		foreach ( $variants_raw as $index => $item ) {
			if ( ! is_array( $item ) ) {
				throw new InvalidArgumentException(
					sprintf( 'ArticleSummary: field "variants[%s]" must be an array.', (string) $index )
				);
			}
			$variants[] = Variant::fromArray( $item );
		}

		$description = $data['description'] ?? null;
		if ( null !== $description && ! is_string( $description ) ) {
			throw new InvalidArgumentException( 'ArticleSummary: field "description" must be a string or null.' );
		}

		$design_id = $data['designId'] ?? null;
		if ( null !== $design_id && ! is_string( $design_id ) ) {
			throw new InvalidArgumentException( 'ArticleSummary: field "designId" must be a string or null.' );
		}

		$state = $data['state'] ?? null;
		if ( null !== $state && ! is_string( $state ) ) {
			throw new InvalidArgumentException( 'ArticleSummary: field "state" must be a string or null.' );
		}

		return new self(
			id: $id,
			title: $title,
			productTypeId: $product_type_id,
			variants: $variants,
			description: $description,
			designId: $design_id,
			state: $state,
		);
	}
}
