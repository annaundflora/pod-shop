<?php
/**
 * Subscription response DTO.
 *
 * One webhook subscription registered with Spreadconnect via
 * `GET/POST/DELETE /subscriptions`. The 7-event enum is shared with
 * {@see WebhookEvent}.
 *
 * @package SpreadconnectPod\Api\Dto
 */

declare(strict_types=1);

namespace SpreadconnectPod\Api\Dto;

use InvalidArgumentException;

/**
 * Immutable Subscription value object.
 *
 * Validation:
 *   - `id`, `callbackUrl` must be strings.
 *   - `eventType` must match one of the 7 known events
 *     ({@see self::ALLOWED_EVENT_TYPES}).
 *   - `state` optional.
 */
final readonly class Subscription
{
	/**
	 * The seven event types Spreadconnect emits / accepts on subscriptions.
	 *
	 * Slice 15/18 may reference this constant for cross-validation.
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

	public function __construct(
		public string $id,
		public string $eventType,
		public string $callbackUrl,
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
		if ( ! is_string( $id ) ) {
			throw new InvalidArgumentException( 'Subscription: field "id" must be a string.' );
		}

		$event_type = $data['eventType'] ?? null;
		if ( ! is_string( $event_type ) || ! in_array( $event_type, self::ALLOWED_EVENT_TYPES, true ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Subscription: field "eventType" must be one of [%s].',
					implode( ', ', self::ALLOWED_EVENT_TYPES )
				)
			);
		}

		$callback_url = $data['callbackUrl'] ?? null;
		if ( ! is_string( $callback_url ) ) {
			throw new InvalidArgumentException( 'Subscription: field "callbackUrl" must be a string.' );
		}

		$state = $data['state'] ?? null;
		if ( null !== $state && ! is_string( $state ) ) {
			throw new InvalidArgumentException( 'Subscription: field "state" must be a string or null.' );
		}

		return new self(
			id: $id,
			eventType: $event_type,
			callbackUrl: $callback_url,
			state: $state,
		);
	}
}
