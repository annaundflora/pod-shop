<?php
/**
 * Preview value object.
 *
 * Presigned image URL returned by `POST /previews`. The URL is transient
 * and **never persisted** — it is consumed immediately by the
 * image-sideloader (Slice 21) which downloads the bytes and writes them
 * into the WP media library.
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable Preview value object.
 *
 * Validation:
 *   - `viewId` must be a string.
 *   - `imageUrl` must be a string starting with `https://` (HTTPS-only).
 *   - `expiresAt` optional.
 */
final readonly class Preview
{
	public function __construct(
		public string $viewId,
		public string $imageUrl,
		public ?string $expiresAt = null,
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

		$view_id = $data['viewId'] ?? null;
		if ( ! is_string( $view_id ) ) {
			throw new InvalidArgumentException( 'Preview: field "viewId" must be a string.' );
		}

		$image_url = $data['imageUrl'] ?? null;
		if ( ! is_string( $image_url ) || ! str_starts_with( $image_url, 'https://' ) ) {
			throw new InvalidArgumentException(
				'Preview: field "imageUrl" must be a string beginning with "https://".'
			);
		}

		$expires_at = $data['expiresAt'] ?? null;
		if ( null !== $expires_at && ! is_string( $expires_at ) ) {
			throw new InvalidArgumentException( 'Preview: field "expiresAt" must be a string or null.' );
		}

		return new self(
			viewId: $view_id,
			imageUrl: $image_url,
			expiresAt: $expires_at,
		);
	}
}
