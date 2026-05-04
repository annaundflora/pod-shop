<?php
/**
 * Domain handler stub for `Order.*` and `Shipment.*` webhook events
 * (slice-17).
 *
 * This is a **logging-only stub** introduced by slice-17 so the
 * {@see ProcessWebhookEventJob} dispatcher has a concrete target to
 * invoke for the `Order.cancelled` / `Order.processed` / `Order.needs-action`
 * / `Shipment.sent` event-types (architecture.md Z. 381 — the
 * Order-domain handler is also responsible for `Shipment.sent`, no
 * separate `ShipmentEventHandler` exists).
 *
 * Slice 30 fully overwrites this class with the real implementation:
 *   - `Order.cancelled` → `cancelOrder()` mirror + WC status update.
 *   - `Order.processed` → state CAS to `PROCESSED`.
 *   - `Order.needs-action` → admin notice + persistent flag.
 *   - `Shipment.sent` → schedule `spreadconnect/fetch_tracking`.
 *
 * Until then the stub merely emits a single info-level log entry so the
 * dispatch surface is observable in QA / unit tests without producing
 * any side-effects.
 *
 * @package SpreadconnectPod\Webhook
 */

declare(strict_types=1);

namespace SpreadconnectPod\Webhook;

/**
 * Logging-only stub for Order/Shipment webhook events.
 *
 * Static-only `final class` — Job-pattern (architecture.md Z. 532, single-
 * method handler). Slice 25/30 will extend the body but keep the public
 * signature intact so the dispatcher in {@see ProcessWebhookEventJob}
 * does not need to be updated.
 */
final class OrderEventHandler
{
	/**
	 * `wc_get_logger()` source string shared by the entire webhook
	 * receive pipeline (architecture.md Z. 398).
	 */
	private const LOG_SOURCE = 'spreadconnect-webhook-receiver';

	/**
	 * Process one Order/Shipment webhook payload.
	 *
	 * Stub-implementation (slice-17): logs an info-line and returns. The
	 * dispatcher treats a clean return as "success" and writes
	 * `processing_status='success'` to the log-row.
	 *
	 * @param array<string, mixed> $payload Decoded webhook payload (already
	 *                                      validated by the dispatcher to
	 *                                      contain a string `eventType`).
	 *
	 * @return void
	 */
	public static function handle( array $payload ): void
	{
		$eventType = isset( $payload['eventType'] ) && is_string( $payload['eventType'] )
			? $payload['eventType']
			: '';

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		if ( ! is_object( $logger ) || ! method_exists( $logger, 'info' ) ) {
			return;
		}

		$logger->info(
			sprintf( 'OrderEventHandler: stub invoked event_type=%s', $eventType ),
			array( 'source' => self::LOG_SOURCE )
		);
	}
}
