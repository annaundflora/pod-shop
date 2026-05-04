<?php
/**
 * WooCommerce order-status hook listener — outbound submit gate.
 *
 * `OrderHandler::on_processing` is the WC-side entry point for the outbound
 * order-submit pipeline (architecture.md "Business Logic Flow — Outbound
 * Order Submit (Flow C)"). When WooCommerce transitions an order into
 * `processing` status, the hook fires and this listener decides whether to
 * enqueue an Action-Scheduler `spreadconnect/create_order` job.
 *
 * Idempotency contract (slice-28 AC-2 + AC-3):
 *   - Skip when `_spreadconnect_order_id` meta is already set (a previous
 *     submit succeeded, or a `submitted_externally`-resolution wired the ID).
 *   - Skip when `as_has_scheduled_action()` already reports a pending action
 *     for the same `(hook, args, group)` tuple — prevents double-schedule on
 *     duplicate hook fires inside one request and races with AS.
 *
 * Out of scope (deferred to later slices):
 *   - `on_cancelled` listener for `woocommerce_order_status_cancelled` —
 *     slice-31 (cancel-mirror flow).
 *   - Auto-Confirm timer scheduling (`spreadconnect/confirm_order`) —
 *     slice-31.
 *   - DI container wiring — slice-37 introduces `Bootstrap\Container`; for
 *     now `Bootstrap\Plugin` constructs the handler inline.
 *
 * @package SpreadconnectPod\Order
 */

declare(strict_types=1);

namespace SpreadconnectPod\Order;

use WC_Logger;
use WC_Order;

/**
 * WC order-status hook listener.
 *
 * Marked `final` per architecture decision (Service Map Z. 369 — Application
 * Layer). Stateless apart from the optional logger; one instance per
 * `Bootstrap\Plugin::init()` boot is enough.
 */
final class OrderHandler
{
	/**
	 * HPOS Order-Meta key carrying the Spreadconnect-Order-ID (set by the
	 * 2xx-path of {@see OrderSubmitJob}). Presence ⇒ submit already happened.
	 *
	 * Source of truth: architecture.md "WC-Order Meta (HPOS)" Z. 309.
	 */
	private const META_ORDER_ID = '_spreadconnect_order_id';

	/**
	 * Action-Scheduler hook name for the outbound `POST /orders` job.
	 *
	 * Final per architecture.md "Action Scheduler — Hook Inventory" Z. 549.
	 * Slice 33 (Bulk-Resend), slice 37 (Retry-Policy listener) and slice 38
	 * (Failed-Ops resend) reference this exact literal.
	 */
	public const HOOK_CREATE_ORDER = 'spreadconnect/create_order';

	/**
	 * Action-Scheduler group for all `spreadconnect/*` jobs (architecture
	 * Z. 558 — single shared group for filterable visibility under
	 * Tools → Scheduled Actions).
	 */
	public const AS_GROUP = 'spreadconnect';

	/**
	 * Logger source string for `wc_get_logger()` — shared with
	 * {@see OrderStateMachine} and {@see OrderSubmitJob} so Failed-Ops
	 * dashboards can filter the entire order-service log stream.
	 */
	private const LOG_SOURCE = 'spreadconnect-order-service';

	/**
	 * Optional `WC_Logger`. When `null`, {@see self::log()} resolves
	 * `wc_get_logger()` per call.
	 */
	private ?WC_Logger $logger;

	/**
	 * @param WC_Logger|null $logger Optional logger override; `null` =>
	 *                               resolve via `wc_get_logger()` on demand.
	 */
	public function __construct( ?WC_Logger $logger = null )
	{
		$this->logger = $logger;
	}

	/**
	 * Hook listener for `woocommerce_order_status_processing`.
	 *
	 * AC-1: Enqueues exactly one `spreadconnect/create_order` AS job when
	 * the order has no SC-Order-ID meta and no pending action is scheduled.
	 * AC-2: Idempotent skip when `_spreadconnect_order_id` already present.
	 * AC-3: Idempotent skip when `as_has_scheduled_action()` returns true.
	 *
	 * Defensive guards (slice-28 Constraints):
	 *   - `$order` must be a `WC_Order` (the WC hook is sometimes invoked
	 *     with `[$order_id]` only; we accept the second arg for the common
	 *     two-arg form and fall back to `wc_get_order()` only if needed).
	 *   - `$order_id` must be > 0 — non-positive IDs short-circuit silently.
	 *
	 * @param int      $order_id WC-Order ID.
	 * @param WC_Order $order    WC-Order instance (provided by the WC hook).
	 */
	public function on_processing( int $order_id, WC_Order $order ): void
	{
		// Slice-28 Constraints — defensive guard despite the strict type
		// hint above: PHPUnit / Brain\Monkey test contexts and a few WC
		// extensions are known to pass `WC_Order_Refund` (a subclass) or
		// other non-canonical variants. We require the canonical
		// `WC_Order` to avoid accidentally enqueuing a refund/credit-note.
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( $order_id <= 0 ) {
			return;
		}

		// AC-2: Skip when SC-Order-ID is already persisted. The HPOS-aware
		// accessor `WC_Order::get_meta()` works in both legacy- and
		// custom-table mode (slice-03 declared HPOS compatibility).
		$existingScOrderId = (string) $order->get_meta( self::META_ORDER_ID );
		if ( '' !== $existingScOrderId ) {
			$this->log(
				'debug',
				sprintf(
					'OrderHandler: idempotent skip — order_id=%d already has _spreadconnect_order_id=%s',
					$order_id,
					$existingScOrderId
				)
			);
			return;
		}

		// AC-3: Skip when a pending action is already scheduled for this
		// `(hook, args, group)` tuple. The args-array MUST be the same shape
		// passed to `as_enqueue_async_action()` below — assoc form
		// `['order_id' => $order_id]` so AS's args-equality check matches.
		$args = array( 'order_id' => $order_id );

		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::HOOK_CREATE_ORDER, $args, self::AS_GROUP )
		) {
			$this->log(
				'debug',
				sprintf(
					'OrderHandler: idempotent skip — order_id=%d already has scheduled %s action',
					$order_id,
					self::HOOK_CREATE_ORDER
				)
			);
			return;
		}

		// AC-1: Enqueue the AS job. Group `'spreadconnect'` per
		// architecture.md Z. 558 — gemeinsame AS-Gruppe.
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			// Defensive fallback when AS is not loaded (e.g. extreme test
			// stripping). Log error and bail — refusing to dispatch is
			// safer than throwing a fatal in a status-transition hook.
			$this->log(
				'error',
				sprintf(
					'OrderHandler: as_enqueue_async_action() unavailable — cannot schedule create_order for order_id=%d',
					$order_id
				)
			);
			return;
		}

		as_enqueue_async_action( self::HOOK_CREATE_ORDER, $args, self::AS_GROUP );

		$this->log(
			'info',
			sprintf(
				'OrderHandler: enqueued %s for order_id=%d',
				self::HOOK_CREATE_ORDER,
				$order_id
			)
		);
	}

	/**
	 * Resolve the logger and dispatch a single entry.
	 *
	 * Mirrors {@see OrderStateMachine::log()} so the order-service log
	 * stream is consistent across both classes (same source string, same
	 * fallback semantics).
	 */
	private function log( string $level, string $message ): void
	{
		$logger = $this->logger;
		if ( null === $logger && function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
		}
		if ( null === $logger || ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			return;
		}
		$logger->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}
}
