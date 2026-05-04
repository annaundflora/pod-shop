<?php
/**
 * ArticleDetail response DTO.
 *
 * Full article payload returned by `GET /articles/{id}`. Same shape as
 * {@see ArticleSummary} per architecture table — kept as a separate class
 * to allow the type system to distinguish list-projection from detail-fetch
 * downstream (sync-job vs. product-mapper consumption).
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable ArticleDetail value object.
 *
 * Validation: identical to ArticleSummary —
 *   - `id`, `title`, `productTypeId` non-empty.
 *   - `variants` is an array of Variant.
 *   - `description`, `designId`, `state` optional.
 */
final readonly class ArticleDetail
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
			throw new InvalidArgumentException( 'ArticleDetail: field "id" must be a non-empty string.' );
		}

		$title = $data['title'] ?? null;
		if ( ! is_string( $title ) || '' === $title ) {
			throw new InvalidArgumentException( 'ArticleDetail: field "title" must be a non-empty string.' );
		}

		$product_type_id = $data['productTypeId'] ?? null;
		if ( ! is_string( $product_type_id ) || '' === $product_type_id ) {
			throw new InvalidArgumentException(
				'ArticleDetail: field "productTypeId" must be a non-empty string.'
			);
		}

		$variants_raw = $data['variants'] ?? array();
		if ( ! is_array( $variants_raw ) ) {
			throw new InvalidArgumentException( 'ArticleDetail: field "variants" must be an array.' );
		}
		$variants = array();
		foreach ( $variants_raw as $index => $item ) {
			if ( ! is_array( $item ) ) {
				throw new InvalidArgumentException(
					sprintf( 'ArticleDetail: field "variants[%s]" must be an array.', (string) $index )
				);
			}
			$variants[] = Variant::fromArray( $item );
		}

		$description = $data['description'] ?? null;
		if ( null !== $description && ! is_string( $description ) ) {
			throw new InvalidArgumentException( 'ArticleDetail: field "description" must be a string or null.' );
		}

		$design_id = $data['designId'] ?? null;
		if ( null !== $design_id && ! is_string( $design_id ) ) {
			throw new InvalidArgumentException( 'ArticleDetail: field "designId" must be a string or null.' );
		}

		$state = $data['state'] ?? null;
		if ( null !== $state && ! is_string( $state ) ) {
			throw new InvalidArgumentException( 'ArticleDetail: field "state" must be a string or null.' );
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
