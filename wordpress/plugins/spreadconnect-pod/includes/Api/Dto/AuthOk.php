<?php
/**
 * AuthOk response DTO.
 *
 * Marker DTO returned by `GET /authentication`. Spreadconnect signals
 * "credentials valid" purely via HTTP-200; the response body may be empty
 * or carry caller-info fields. We accept any (or no) body and capture the
 * raw payload for diagnostic display in the Settings → Test Connection UI.
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

/**
 * Immutable AuthOk value object.
 *
 * Validation: none enforced — the success signal is the HTTP-status, not
 * the body. The factory accepts any (possibly empty) array.
 *
 * The `pointOfSaleId` and `accountId` properties are surfaced when present
 * so that the Test-Connection AJAX (Slice 12) can render them in the
 * confirmation toast.
 */
final readonly class AuthOk
{
	/**
	 * @param array<string, mixed> $raw Original (snake-case-converted) payload, retained for diagnostics.
	 */
	public function __construct(
		public array $raw,
		public ?string $pointOfSaleId = null,
		public ?string $accountId = null,
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

		$point_of_sale_id = $data['pointOfSaleId'] ?? null;
		if ( null !== $point_of_sale_id && ! is_string( $point_of_sale_id ) ) {
			$point_of_sale_id = null;
		}

		$account_id = $data['accountId'] ?? null;
		if ( null !== $account_id && ! is_string( $account_id ) ) {
			$account_id = null;
		}

		return new self(
			raw: $data,
			pointOfSaleId: $point_of_sale_id,
			accountId: $account_id,
		);
	}
}
