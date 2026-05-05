<?php
/**
 * Domain handler for `Order.*` and `Shipment.*` webhook events (slice-30).
 *
 * Replaces the slice-17 logging-only stub with the real implementation per
 * the slice-17 Integration Contract ("`OrderEventHandler::handle` …
 * vollstaendig ueberschrieben in Slice 30"). The class signature stays
 * intact so the {@see ProcessWebhookEventJob} dispatcher continues to
 * invoke `OrderEventHandler::handle($payload)` without a dispatcher edit.
 *
 * Mapping (architecture.md Z. 381 + Z. 845-848 + Discovery Flow C 8-11):
 *   - `Order.processed`   → State `PROCESSED` (direct write — Webhook
 *                            has Last-Write-Wins priority, NO CAS).
 *                            WC-Status NOT mutated (still `processing`).
 *   - `Order.cancelled`   → State `CANCELLED` (direct write) +
 *                            WC-Status `cancelled` (idempotency-guarded
 *                            against the slice-31 WC-Cancel-Mirror loop).
 *   - `Order.needs-action`→ Orthogonal flag `_spreadconnect_needs_action`
 *                            + persistent-notice logging stub (slice-39
 *                            replaces with real `Failure\AdminNoticeStore`).
 *                            State NOT mutated.
 *   - `Shipment.sent`     → Schedule `spreadconnect/fetch_tracking` AS-job
 *                            (consumer is {@see \SpreadconnectPod\Order\FetchTrackingJob}).
 *                            State NOT mutated (still PROCESSED).
 *
 * Reverse-Lookup:
 *   - `wc_get_orders(['meta_key'=>'_spreadconnect_order_id', ...])` against
 *     the meta written by {@see OrderSubmitJob} on 2xx-path. HPOS-aware
 *     (architecture.md Z. 850 — no raw `$wpdb` queries on the HPOS-meta
 *     tables).
 *   - Empty result ⇒ warning-log + clean return (the dispatcher in
 *     {@see ProcessWebhookEventJob} writes `processing_status='success'`
 *     so AS does NOT retry — no amount of retry will reverse-look-up a
 *     non-existent order).
 *
 * Validation:
 *   - `data.entity.orderReference` (preferred per architecture Z. 850) OR
 *     `data.entity.id` (fallback) MUST be a non-empty string. Anything
 *     else is treated as a permanent validation failure: a warning is
 *     logged and the handler returns cleanly so AS retry would not help.
 *
 * Out of scope (later slices):
 *   - Real `Failure\AdminNoticeStore` insertion — slice-39 (the persistent-
 *     notice logging stub `admin_notice_pending_record` is the bridge).
 *   - WC → SC cancel-mirror (`woocommerce_order_status_cancelled`) —
 *     slice-31 (this slice handles INBOUND SC → WC; OUTBOUND is slice-31).
 *   - `setShippingType()` / Auto-Confirm-Timer-Cancel on `Order.processed`
 *     — slice-31.
 *
 * @package SpreadconnectPod\Webhook
 */

declare(strict_types=1);

namespace SpreadconnectPod\Webhook;

use SpreadconnectPod\Order\OrderStateMachine;
use WC_Order;

/**
 * Inbound webhook handler for Order/Shipment domain events.
 *
 * Static-only `final class` — Job-pattern (architecture.md Z. 532, single-
 * method handler). The public signature
 * (`public static function handle(array $payload): void`) is contract-bound
 * by the slice-17 dispatcher; slice-30 only swaps the body.
 */
final class OrderEventHandler
{
	/**
	 * `wc_get_logger()` source string shared by the entire webhook
	 * receive pipeline (architecture.md Z. 398).
	 */
	private const LOG_SOURCE = 'spreadconnect-webhook-receiver';

	/**
	 * Action-Scheduler group used for every enqueued action. Matches the
	 * convention introduced by slice-23 / slice-24 / slice-25 / slice-28.
	 */
	private const AS_GROUP = 'spreadconnect';

	/**
	 * HPOS Order-Meta key for the SC-Order-ID anchor (reverse-lookup
	 * target). Mirrors the constant kept by {@see \SpreadconnectPod\Order\OrderSubmitJob}.
	 */
	private const META_ORDER_ID = '_spreadconnect_order_id';

	/**
	 * HPOS Order-Meta key for the orthogonal needs-action flag
	 * (architecture.md Z. 311).
	 */
	private const META_NEEDS_ACTION = '_spreadconnect_needs_action';

	/**
	 * HPOS Order-Meta key for the latest-event audit field
	 * (architecture.md Z. 315). Format `<unix>:<event-type>`.
	 */
	private const META_LAST_EVENT = '_spreadconnect_last_event';

	/**
	 * Hook name of the slice-30 fetch-tracking consumer (sibling
	 * {@see \SpreadconnectPod\Order\FetchTrackingJob}). Producer-side
	 * invocation for `Shipment.sent`.
	 */
	private const HOOK_FETCH_TRACKING = 'spreadconnect/fetch_tracking';

	/**
	 * Logging tag picked up by slice-39 `AdminNoticeStore` to replace this
	 * stub with a real Option-write. Until then the handler emits a single
	 * `error`-level log entry carrying this tag in the context payload.
	 */
	private const LOG_TAG_ADMIN_NOTICE_PENDING = 'admin_notice_pending_record';

	/**
	 * `op_type` value paired with the `admin_notice_pending_record` tag for
	 * downstream slice-39 lookup.
	 */
	private const OP_TYPE_ORDER_NEEDS_ACTION = 'order_needs_action';

	/**
	 * Webhook event-types (architecture.md Z. 175 enum).
	 */
	private const EVENT_PROCESSED     = 'Order.processed';
	private const EVENT_CANCELLED     = 'Order.cancelled';
	private const EVENT_NEEDS_ACTION  = 'Order.needs-action';
	private const EVENT_SHIPMENT_SENT = 'Shipment.sent';

	/**
	 * WC order-statuses that are terminal w.r.t. the `Order.cancelled`
	 * idempotency-guard. Re-flipping a `cancelled` order to `cancelled` (or
	 * a `refunded` order to `cancelled`) would loop with the slice-31
	 * WC-Cancel-Mirror and re-fire `woocommerce_order_status_*` hooks.
	 *
	 * @var list<string>
	 */
	private const WC_TERMINAL_CANCEL_STATES = array( 'cancelled', 'refunded' );

	/**
	 * Process one Order/Shipment webhook payload.
	 *
	 * @param array<string, mixed> $payload Decoded webhook payload (already
	 *                                      validated by the dispatcher to
	 *                                      contain a string `eventType`
	 *                                      with the `Order.` or `Shipment.`
	 *                                      prefix).
	 *
	 * @return void
	 */
	public static function handle( array $payload ): void
	{
		$eventType = isset( $payload['eventType'] ) && is_string( $payload['eventType'] )
			? $payload['eventType']
			: '';

		// AC-13: Validate the entity reference. Architecture Z. 850 lists
		// `orderReference` as the canonical field; we fall back to `id` for
		// payloads that ship the alternative shape (Discovery doc allows
		// either). Both empty / non-string ⇒ permanent validation failure.
		$scOrderId = self::extractScOrderId( $payload );
		if ( null === $scOrderId ) {
			self::log(
				'warning',
				sprintf(
					'OrderEventHandler: missing or invalid entity reference event_type=%s',
					$eventType
				)
			);
			return;
		}

		// AC-1: Reverse-Lookup the WC-Order via the `_spreadconnect_order_id`
		// meta written by `OrderSubmitJob` on 2xx-path. HPOS-aware via
		// `wc_get_orders` (architecture.md Z. 850 — no raw `$wpdb`).
		$order = self::findOrderByScId( $scOrderId );
		if ( ! $order instanceof WC_Order ) {
			self::log(
				'warning',
				sprintf(
					'OrderEventHandler: no matching WC-order sc_id=%s event_type=%s',
					$scOrderId,
					$eventType
				)
			);
			return;
		}

		// Per-event branch dispatch. The four event-types are disjoint per
		// architecture Z. 175 so a single switch is sufficient. Any other
		// `Order.*` / `Shipment.*` value falls through to the AC-12
		// "unknown sub-event" warning branch.
		switch ( $eventType ) {
			case self::EVENT_PROCESSED:
				self::handleOrderProcessed( $order, $eventType );
				return;

			case self::EVENT_CANCELLED:
				self::handleOrderCancelled( $order, $eventType );
				return;

			case self::EVENT_NEEDS_ACTION:
				self::handleOrderNeedsAction( $order, $eventType, $payload );
				return;

			case self::EVENT_SHIPMENT_SENT:
				self::handleShipmentSent( $order, $eventType );
				return;

			default:
				// AC-12: unknown sub-event within Order/Shipment domain
				// (e.g. `Order.foo`). Permanent-validation failure — AS
				// retry would not help. Warn + return cleanly.
				self::log(
					'warning',
					sprintf(
						'OrderEventHandler: unhandled event_type=%s — skipping',
						$eventType
					)
				);
				return;
		}
	}

	/**
	 * AC-2: `Order.processed` ⇒ State `PROCESSED` via direct write
	 * ({@see OrderStateMachine::writeUnchecked()}). Webhook has Last-Write-
	 * Wins priority so the `submitting`→`PROCESSED` race against a still-
	 * running `OrderSubmitJob` is decided in favour of the webhook
	 * (architecture.md Z. 619). WC-Status stays untouched (Discovery
	 * Z. 539 — only `Shipment.sent` flips the status to `completed`).
	 */
	private static function handleOrderProcessed( WC_Order $order, string $eventType ): void
	{
		self::stateMachine( $order )
			->writeUnchecked( $order, OrderStateMachine::STATE_PROCESSED );

		self::writeLastEvent( $order, $eventType );

		$order->add_order_note(
			'Spreadconnect: Order processed (state=PROCESSED)',
			false,
			false
		);
	}

	/**
	 * AC-4: `Order.cancelled` ⇒ State `CANCELLED` (direct write) + WC-Status
	 * `cancelled` (idempotency-guarded). The guard prevents an infinite
	 * loop with the slice-31 `woocommerce_order_status_cancelled` listener
	 * which schedules the SC-cancel-mirror — without the guard we would
	 * round-trip `cancelled` → SC API → SC webhook → `cancelled` ad
	 * infinitum.
	 */
	private static function handleOrderCancelled( WC_Order $order, string $eventType ): void
	{
		self::stateMachine( $order )
			->writeUnchecked( $order, OrderStateMachine::STATE_CANCELLED );

		$currentStatus = (string) $order->get_status();
		if ( ! in_array( $currentStatus, self::WC_TERMINAL_CANCEL_STATES, true ) ) {
			$order->update_status(
				'cancelled',
				'Spreadconnect: Order cancelled (webhook)',
				false
			);
		}

		self::writeLastEvent( $order, $eventType );

		$order->add_order_note(
			'Spreadconnect: Order cancelled (state=CANCELLED)',
			false,
			false
		);
	}

	/**
	 * AC-5: `Order.needs-action` ⇒ orthogonal flag (no state mutate) +
	 * persistent-notice logging stub + Order-Note. The `errorReason` field
	 * is optional in the payload (Discovery Z. 603 — present for SKU-not-
	 * found, missing for ambiguous failures). Falls back to a flag-only
	 * note when absent.
	 */
	private static function handleOrderNeedsAction(
		WC_Order $order,
		string $eventType,
		array $payload
	): void {
		$errorReason = self::extractErrorReason( $payload );

		// AC-5 (a): orthogonal flag (architecture.md Z. 311 — needs-action
		// is independent of `_spreadconnect_state`, which stays unchanged).
		$order->update_meta_data( self::META_NEEDS_ACTION, true );
		$order->save();

		// AC-5 (c): persistent-notice logging stub. Slice-39 replaces the
		// body with a real `Failure\AdminNoticeStore::add()` Option-write.
		self::recordPersistentNotice( $order, $errorReason );

		// AC-5 (d): Order-Note with optional reason suffix.
		$noteMessage = '' === $errorReason
			? 'Spreadconnect: Order needs action'
			: sprintf( 'Spreadconnect: Order needs action — %s', $errorReason );

		$order->add_order_note( $noteMessage, false, false );

		// AC-5 (e): last-event meta (audit only).
		self::writeLastEvent( $order, $eventType );
	}

	/**
	 * AC-6: `Shipment.sent` ⇒ enqueue the `spreadconnect/fetch_tracking`
	 * AS-job that performs the actual `getShipments()` call + tracking-meta
	 * persist + WC-Status `completed` flip. NO direct HTTP-I/O in the
	 * webhook receive path (architecture.md Z. 552). Idempotency-guard
	 * via `as_has_scheduled_action` analogous to slice-28.
	 */
	private static function handleShipmentSent( WC_Order $order, string $eventType ): void
	{
		$args = array( 'order_id' => (int) $order->get_id() );

		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			// Defensive: AS not loaded — bail silently. The handler must
			// not throw, otherwise the dispatcher writes the row to
			// `processing_status='error'` and AS retries the dispatcher
			// itself.
			self::writeLastEvent( $order, $eventType );
			return;
		}

		$alreadyScheduled = as_has_scheduled_action(
			self::HOOK_FETCH_TRACKING,
			$args,
			self::AS_GROUP
		);

		if ( ! $alreadyScheduled && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				self::HOOK_FETCH_TRACKING,
				$args,
				self::AS_GROUP
			);
		}

		self::writeLastEvent( $order, $eventType );
	}

	/**
	 * Resolve the `OrderStateMachine` instance with the canonical
	 * production-default collaborator chain. The state-machine itself is
	 * stateless apart from the injected `$wpdb`, so a fresh instance per
	 * call is cheap and avoids storing globals at the class level.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$order` reserved for
	 *                  future per-order DI.
	 */
	private static function stateMachine( WC_Order $order ): OrderStateMachine
	{
		global $wpdb;

		return new OrderStateMachine( $wpdb );
	}

	/**
	 * Persist the `_spreadconnect_last_event` audit field
	 * (architecture.md Z. 315 — format `<unix>:<event-type>`).
	 */
	private static function writeLastEvent( WC_Order $order, string $eventType ): void
	{
		$order->update_meta_data(
			self::META_LAST_EVENT,
			sprintf( '%d:%s', time(), $eventType )
		);
		$order->save();
	}

	/**
	 * Slice-39 placeholder for `Failure\AdminNoticeStore::add()`.
	 *
	 * Logging-only stub: emits a single `error`-level log entry tagged with
	 * source `spreadconnect-webhook-receiver` and the
	 * `admin_notice_pending_record` tag. Slice 39 replaces the body with a
	 * real `wp_options` write that surfaces a persistent admin-notice on
	 * `admin_notices`.
	 */
	private static function recordPersistentNotice( WC_Order $order, string $errorReason ): void
	{
		$context = array(
			'source'   => self::LOG_SOURCE,
			'tag'      => self::LOG_TAG_ADMIN_NOTICE_PENDING,
			'op_type'  => self::OP_TYPE_ORDER_NEEDS_ACTION,
			'order_id' => (int) $order->get_id(),
			'reason'   => $errorReason,
		);

		self::logWithContext(
			'error',
			sprintf(
				'OrderEventHandler: %s — order_id=%d, reason=%s',
				self::LOG_TAG_ADMIN_NOTICE_PENDING,
				(int) $order->get_id(),
				$errorReason
			),
			$context
		);
	}

	/**
	 * Reverse-look-up the WC-Order associated with an SC-Order-ID via the
	 * `_spreadconnect_order_id` meta. HPOS-aware via `wc_get_orders` —
	 * architecture.md Z. 850 explicitly forbids raw `$wpdb` access on the
	 * HPOS-meta tables.
	 *
	 * @return WC_Order|null `null` when no order matches (warning-log path).
	 */
	private static function findOrderByScId( string $scOrderId ): ?WC_Order
	{
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$results = wc_get_orders(
			array(
				'meta_key'   => self::META_ORDER_ID,
				'meta_value' => $scOrderId,
				'limit'      => 1,
				'status'     => 'any',
				'type'       => 'shop_order',
				'return'     => 'objects',
			)
		);

		if ( ! is_array( $results ) || empty( $results ) ) {
			return null;
		}

		$first = $results[0];

		return $first instanceof WC_Order ? $first : null;
	}

	/**
	 * Extract the SC-Order-ID from `data.entity.orderReference` (preferred
	 * per architecture Z. 850) or `data.entity.id` (fallback for the
	 * alternative payload-shape).
	 *
	 * Returns `null` when neither path yields a non-empty string.
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function extractScOrderId( array $payload ): ?string
	{
		$entity = $payload['data']['entity'] ?? null;
		if ( ! is_array( $entity ) ) {
			return null;
		}

		$ref = $entity['orderReference'] ?? null;
		if ( is_string( $ref ) && '' !== $ref ) {
			return $ref;
		}

		$id = $entity['id'] ?? null;
		if ( is_string( $id ) && '' !== $id ) {
			return $id;
		}

		return null;
	}

	/**
	 * Extract `data.errorReason` (architecture.md Z. 175 — optional field
	 * present on `Order.needs-action`). Returns `''` when absent / non-
	 * string.
	 *
	 * @param array<string, mixed> $payload
	 */
	private static function extractErrorReason( array $payload ): string
	{
		$reason = $payload['data']['errorReason'] ?? null;

		return is_string( $reason ) ? $reason : '';
	}

	/**
	 * Resolve `wc_get_logger()` and emit a single entry with `source` only.
	 */
	private static function log( string $level, string $message ): void
	{
		self::logWithContext( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}

	/**
	 * Resolve `wc_get_logger()` and emit a single entry with a custom
	 * context. The `source` key is enforced so log-stream filtering in
	 * WC → Status → Logs works regardless of the caller's context shape.
	 *
	 * @param array<string, mixed> $context
	 */
	private static function logWithContext( string $level, string $message, array $context ): void
	{
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		if ( ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			return;
		}

		if ( ! isset( $context['source'] ) ) {
			$context['source'] = self::LOG_SOURCE;
		}

		$logger->log( $level, $message, $context );
	}
}
