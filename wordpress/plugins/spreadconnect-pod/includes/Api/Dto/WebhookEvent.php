<?php
/**
 * WebhookEvent inbound DTO.
 *
 * Wraps one Spreadconnect webhook delivery received by the public REST
 * controller (Slice 15). The `data.entity` payload is intentionally kept
 * untyped — per-handler structural validation lives in the
 * `ProcessWebhookEventJob` (Slice 17).
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable WebhookEvent value object.
 *
 * Validation:
 *   - `eventType` must be one of the 7 known events
 *     ({@see self::ALLOWED_EVENT_TYPES}).
 *   - `data.pointOfSaleId` must be a string.
 *   - `data.entity` must be an array (raw, unvalidated).
 *   - `data.errorReason` optional string.
 */
final readonly class WebhookEvent
{
	/**
	 * The seven event types Spreadconnect emits via webhook.
	 *
	 * Mirrors {@see Subscription::ALLOWED_EVENT_TYPES} to keep the contract
	 * symmetrical between subscription registration and event reception.
	 * Slice 15/17 may reference this constant for routing.
	 */
	public const ALLOWED_EVENT_TYPES = array(
		'Article.added',
		'Article.updated',
		'Article.removed',
		'Order.cancelled',
		'Order.processed',
		'Order.needs-action',
		'Shipment.sent',
	);

	/**
	 * @param array<string, mixed> $entity Raw entity payload, kept untyped per architecture.
	 */
	public function __construct(
		public string $eventType,
		public string $pointOfSaleId,
		public array $entity,
		public ?string $errorReason = null,
	) {
	}

	/**
	 * Construct from a webhook body (snake_case or camelCase).
	 *
	 * Top-level shape:
	 *   {
	 *     "eventType": "...",
	 *     "data": {
	 *       "pointOfSaleId": "...",
	 *       "entity": { ... },
	 *       "errorReason": "..."  // optional
	 *     }
	 *   }
	 *
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self
	{
		$data = DtoMapper::snakeToCamel( $data );

		$event_type = $data['eventType'] ?? null;
		if ( ! is_string( $event_type ) || ! in_array( $event_type, self::ALLOWED_EVENT_TYPES, true ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'WebhookEvent: field "eventType" must be one of [%s].',
					implode( ', ', self::ALLOWED_EVENT_TYPES )
				)
			);
		}

		$inner = $data['data'] ?? null;
		if ( ! is_array( $inner ) ) {
			throw new InvalidArgumentException( 'WebhookEvent: field "data" must be an array.' );
		}

		$point_of_sale_id = $inner['pointOfSaleId'] ?? null;
		if ( ! is_string( $point_of_sale_id ) ) {
			throw new InvalidArgumentException(
				'WebhookEvent: field "data.pointOfSaleId" must be a string.'
			);
		}

		$entity = $inner['entity'] ?? null;
		if ( ! is_array( $entity ) ) {
			throw new InvalidArgumentException(
				'WebhookEvent: field "data.entity" must be an array (kept untyped, per-handler validation).'
			);
		}

		$error_reason = $inner['errorReason'] ?? null;
		if ( null !== $error_reason && ! is_string( $error_reason ) ) {
			throw new InvalidArgumentException(
				'WebhookEvent: field "data.errorReason" must be a string or null.'
			);
		}

		return new self(
			eventType: $event_type,
			pointOfSaleId: $point_of_sale_id,
			entity: $entity,
			errorReason: $error_reason,
		);
	}
}
