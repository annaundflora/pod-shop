<?php
/**
 * Deterministic `event_id` computation for inbound Spreadconnect webhooks
 * (slice-16).
 *
 * Spreadconnect does NOT supply a stable `eventId` per delivery
 * (architecture.md Z. 643). To make the receiver idempotent we derive a
 * 64-character lowercase-hex SHA-256 digest from a triple of stable inputs:
 *
 *   event_id = sha256( eventType + ':' + entityId + ':' + sha256(rawBody) )
 *
 * Properties:
 *   - **Deterministic**: identical inputs ⇒ identical digest. The result is
 *     used as the value for the UNIQUE column `wp_spreadconnect_webhook_log
 *     .event_id` (architecture.md Z. 218 / Z. 264 — `CHAR(64)`).
 *   - **Avalanche**: a single-byte change anywhere in `$rawBody` (or in
 *     `$eventType` / `$entityId`) flips on average half of the digest bits.
 *   - **No WP / DB dependency**: pure-domain function. Only PHP-builtin
 *     `hash()` is invoked twice (inner over the raw body, outer over the
 *     concatenated triple).
 *
 * The inner pre-hash of `$rawBody` is intentional: SC bodies can grow into
 * the low-MB range (architecture.md Z. 503 lists a 1 MB ceiling for the
 * receiver). Hashing the body once up-front collapses it to a fixed 64-byte
 * tag and keeps the outer concatenation cheap and predictable in length —
 * regardless of body size.
 *
 * @package SpreadconnectPod\Webhook
 */

declare(strict_types=1);

namespace SpreadconnectPod\Webhook;

use InvalidArgumentException;

/**
 * Stateless, single-method `event_id` hasher.
 *
 * Final + only one `static` method. No constructor, no instance state, no
 * collaborators. The class is the canonical Domain-layer answer to "what is
 * the deterministic id for this webhook delivery?" and is consumed by
 * {@see WebhookController::handle()} (slice-16) as well as verifiable in
 * isolation without any WP bootstrap.
 */
final class EventIdHasher
{
	/**
	 * Hash algorithm identifier — single canonical choice for both the
	 * inner body-hash and the outer triple-hash.
	 *
	 * Centralised as a `private const` so a coordinated rotation is a
	 * one-line change. SHA-256 hex output is exactly 64 characters, matching
	 * the schema column type `event_id CHAR(64)` (architecture.md Z. 218 /
	 * Z. 264).
	 */
	private const HASH_ALGO = 'sha256';

	/**
	 * Triple-separator literal. Chosen as `':'` (single colon) because it is
	 * neither part of `eventType` (e.g. `Order.processed`) nor of an SC
	 * entity-id (numeric / UUID-ish strings). Architecture-fixed
	 * (architecture.md Z. 643).
	 */
	private const SEPARATOR = ':';

	/**
	 * Exception code emitted when the caller supplies an empty
	 * `$eventType` (top-level webhook field) or an empty `$entityId`
	 * (`data.entity.id` lookup).
	 *
	 * Both empty inputs collapse to the same code so callers can branch on a
	 * single string. {@see WebhookController::handle()} catches the
	 * exception and falls through to the `_unknown` marker insert path
	 * (slice-16 AC-9) — the receiver never rejects a valid-HMAC delivery.
	 */
	public const EXCEPTION_CODE_MISSING_ENTITY = 'spreadconnect_event_id_missing_entity';

	/**
	 * Compute the deterministic `event_id` for a webhook delivery.
	 *
	 * Returns a 64-character lowercase-hex SHA-256 digest. Both `$eventType`
	 * and `$entityId` MUST be non-empty; an empty `$rawBody` is acceptable
	 * (an SC delivery with a valid HMAC over an empty body still yields a
	 * deterministic id — the inner `sha256('')` is a well-defined constant).
	 *
	 * @param string $eventType Top-level `eventType` from the JSON body
	 *                          (e.g. `Order.processed`,
	 *                          `Article.updated`). Must not be `''`.
	 * @param string $entityId  `data.entity.id` from the JSON body. Must not
	 *                          be `''`.
	 * @param string $rawBody   Exact raw request body bytes — never the
	 *                          re-encoded JSON. May be `''`.
	 *
	 * @return string Lowercase-hex SHA-256 digest (always 64 chars).
	 *
	 * @throws InvalidArgumentException When `$eventType` OR `$entityId`
	 *                                  is empty (code:
	 *                                  {@see self::EXCEPTION_CODE_MISSING_ENTITY}).
	 */
	public static function compute(
		string $eventType,
		string $entityId,
		string $rawBody
	): string {
		// AC-2: both type AND id are required. Empty in either slot would
		// collapse the digest space (e.g. all `eventType=''` deliveries
		// would hash into the same UNIQUE-bucket per body) and is therefore
		// rejected at the source.
		if ( '' === $eventType || '' === $entityId ) {
			throw new InvalidArgumentException( self::EXCEPTION_CODE_MISSING_ENTITY );
		}

		// AC-1 inner step: collapse the raw body to a fixed 64-char tag.
		// `hash()` defaults to lowercase-hex output; we explicitly do NOT
		// pass `true` (raw binary) — the schema column is CHAR(64) hex.
		$bodyHash = hash( self::HASH_ALGO, $rawBody );

		// AC-1 outer step: hash the concatenation of the three stable
		// inputs. The separator keeps `('Order','.processed','abc')` and
		// `('Order.','processed','abc')` from colliding.
		return hash(
			self::HASH_ALGO,
			$eventType . self::SEPARATOR . $entityId . self::SEPARATOR . $bodyHash
		);
	}
}
