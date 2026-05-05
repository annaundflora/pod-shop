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
 * Slice 31 extends this class with:
 *   - `on_cancelled` listener for `woocommerce_order_status_cancelled` →
 *     enqueues the {@see OrderCancelMirrorJob} when the persisted SC-state
 *     is exactly `NEW` and short-circuits with a persistent-notice stub
 *     otherwise. Also unschedules any pending Auto-Confirm timer
 *     (`spreadconnect/confirm_order`) to avoid the race documented in
 *     architecture.md Z. 642.
 *   - `maybeScheduleAutoConfirm()` listener for the
 *     `spreadconnect/order_submitted` action fired by {@see OrderSubmitJob}
 *     on the 2xx-path → schedules `spreadconnect/confirm_order` according
 *     to the `spreadconnect_auto_confirm` setting (`off` / `immediate` /
 *     `after_minutes`).
 *   - `recordAutoConfirmPreCheckFailure()` listener for the
 *     `spreadconnect/auto_confirm_pre_check_failed` action fired by
 *     {@see OrderConfirmJob} when its pre-check fails on a job that was
 *     scheduled by the Auto-Confirm-Timer → emits the persistent-notice
 *     stub (slice-39) explicitly suppressing the FailedOps DLQ-Aufnahme
 *     (architecture.md Z. 591).
 *
 * Out of scope (deferred to later slices):
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
	 * Action-Scheduler hook name for the per-order confirm job
	 * ({@see OrderConfirmJob}). Slice 31 schedules this hook from the
	 * Auto-Confirm-Timer; slice 32 unschedules it via the "Cancel
	 * Auto-Confirm" admin AJAX (architecture.md Z. 549-552).
	 */
	public const HOOK_CONFIRM_ORDER = 'spreadconnect/confirm_order';

	/**
	 * Action-Scheduler hook name for the WC-cancel-mirror job
	 * ({@see OrderCancelMirrorJob}). Distinct from the generic
	 * `spreadconnect/cancel_order` (slice-29) so the filter view in
	 * `Tools → Scheduled Actions` keeps the WC-driven mirror branch
	 * visible separately (architecture.md Z. 549-552).
	 */
	public const HOOK_CANCEL_ORDER_MIRROR = 'spreadconnect/cancel_order_mirror';

	/**
	 * Action-Scheduler group for all `spreadconnect/*` jobs (architecture
	 * Z. 558 — single shared group for filterable visibility under
	 * Tools → Scheduled Actions).
	 */
	public const AS_GROUP = 'spreadconnect';

	/**
	 * Setting key + default for the Auto-Confirm timer mode (slice-05).
	 * Accepted values: `'off'` (default) | `'immediate'` | `'after_minutes'`.
	 */
	private const OPTION_AUTO_CONFIRM         = 'spreadconnect_auto_confirm';
	private const OPTION_AUTO_CONFIRM_DEFAULT = 'off';

	/**
	 * Setting key + default for the Auto-Confirm-after-minutes delay
	 * (slice-05). Effective only when {@see self::OPTION_AUTO_CONFIRM} is
	 * `'after_minutes'`. When the value is `0` the schedule degrades to
	 * `time()` (immediate).
	 */
	private const OPTION_AUTO_CONFIRM_MINUTES         = 'spreadconnect_auto_confirm_minutes';
	private const OPTION_AUTO_CONFIRM_MINUTES_DEFAULT = 0;

	/**
	 * Setting key + default for the WC-cancel-mirror toggle (slice-05).
	 * `false` short-circuits the mirror entirely: WC-cancel still happens,
	 * but no SC-side cancel is enqueued (architecture.md Z. 329 — the
	 * operator must hand-cancel via Order-Edit / Failed-Ops UI).
	 */
	private const OPTION_AUTO_CANCEL_MIRROR         = 'spreadconnect_auto_cancel_mirror';
	private const OPTION_AUTO_CANCEL_MIRROR_DEFAULT = true;

	/**
	 * Setting key for the shop-wide default shipping-type. Used as
	 * Auto-Confirm gating in {@see self::maybeScheduleAutoConfirm()}: an
	 * empty default + missing per-order shipping-type meta means the
	 * confirm job's pre-check would fail at run-time, so we refuse to
	 * schedule in the first place (architecture.md Z. 326-329 + Z. 647).
	 */
	private const OPTION_DEFAULT_SHIPPING_TYPE = 'spreadconnect_default_shipping_type';

	/**
	 * Per-order HPOS Order-Meta key for the shipping-type (architecture.md
	 * Z. 312). Slice 32's "Save Shipping-Type" admin button writes this.
	 */
	private const META_SHIPPING_TYPE = '_spreadconnect_shipping_type';

	/**
	 * Persistent-notice logging-stub tag (reuse-convention from slice-30
	 * AC-5; slice-39 replaces with real `Failure\AdminNoticeStore::add()`).
	 */
	private const LOG_TAG_ADMIN_NOTICE_PENDING = 'admin_notice_pending_record';

	/**
	 * Logging tag emitted on the AC-4 silent-skip branch.
	 */
	private const LOG_TAG_CANCEL_SKIPPED_NO_SC_ID = 'cancel_mirror_skipped_no_sc_order_id';

	/**
	 * Logging tag emitted on the AC-5 disabled-by-setting branch.
	 */
	private const LOG_TAG_CANCEL_DISABLED_BY_SETTING = 'cancel_mirror_disabled_by_setting';

	/**
	 * `op_type` values emitted alongside the `admin_notice_pending_record`
	 * tag for slice-39 lookup.
	 */
	private const OP_TYPE_NOTICE_CANCEL_BLOCKED        = 'wc_cancel_mirror_blocked';
	private const OP_TYPE_NOTICE_AUTO_CONFIRM_PRECHECK = 'auto_confirm_pre_check_failed';

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
	 * Hook listener for `woocommerce_order_status_cancelled` (slice-31).
	 *
	 * AC-1..AC-5: implements the WC → SC cancel-mirror outbound branch.
	 *
	 * Pre-check sequence (slice-31 Constraints):
	 *   1. Order-Lookup (defensive — the WC hook is sometimes called with
	 *      `[$order_id]` only, fall back to `wc_get_order()` if needed).
	 *   2. Setting `auto_cancel_mirror=false` ⇒ AC-5 short-circuit.
	 *   3. `_spreadconnect_order_id` missing ⇒ AC-4 silent-skip (nothing
	 *      to mirror — submit either failed or never ran).
	 *   4. Always `as_unschedule_action('spreadconnect/confirm_order', …)`
	 *      → race-protection per architecture.md Z. 642. Idempotent — no
	 *      side-effect when no schedule is pending.
	 *   5. State-branch:
	 *      - State `'NEW'` → enqueue {@see OrderCancelMirrorJob} via
	 *        `as_enqueue_async_action('spreadconnect/cancel_order_mirror',…)`,
	 *        guarded by `as_has_scheduled_action()` for idempotency
	 *        (AC-2).
	 *      - State `'CONFIRMED'` / `'PROCESSED'` (or any other non-`NEW`)
	 *        → no API call, just an Order-Note + persistent-notice stub
	 *        (AC-3).
	 *
	 * @param int      $order_id WC-Order ID.
	 * @param WC_Order $order    WC-Order instance (provided by the WC hook).
	 */
	public function on_cancelled( int $order_id, WC_Order $order ): void
	{
		// Defensive guard — the WC hook may pass an `WC_Order_Refund` or
		// other subclass; we want the canonical WC_Order. Slice-31
		// Constraints also allow falling back to `wc_get_order($order_id)`
		// when `$order` is not the right shape.
		if ( ! $order instanceof WC_Order ) {
			if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
				$resolved = wc_get_order( $order_id );
				if ( $resolved instanceof WC_Order ) {
					$order = $resolved;
				} else {
					return;
				}
			} else {
				return;
			}
		}

		if ( $order_id <= 0 ) {
			return;
		}

		// AC-5: Setting `auto_cancel_mirror=false` short-circuits the
		// entire mirror — no unschedule, no enqueue, no notice. The
		// operator must hand-cancel via Order-Edit / Failed-Ops UI.
		if ( ! $this->isAutoCancelMirrorEnabled() ) {
			$this->logWithContext(
				'info',
				sprintf(
					'OrderHandler: cancel-mirror disabled by setting — order_id=%d',
					$order_id
				),
				array(
					'source' => self::LOG_SOURCE,
					'tag'    => self::LOG_TAG_CANCEL_DISABLED_BY_SETTING,
				)
			);
			return;
		}

		// AC-4: Skip when no SC-Order-ID is set — there is nothing to
		// mirror (submit either failed or never ran). No order-note (UI
		// noise avoidance per AC-4); only an info-level log entry.
		$scOrderId = (string) $order->get_meta( self::META_ORDER_ID );
		if ( '' === $scOrderId ) {
			$this->logWithContext(
				'info',
				sprintf(
					'OrderHandler: cancel-mirror skipped, no SC-Order-ID — order_id=%d',
					$order_id
				),
				array(
					'source' => self::LOG_SOURCE,
					'tag'    => self::LOG_TAG_CANCEL_SKIPPED_NO_SC_ID,
				)
			);
			return;
		}

		// AC-2 (a) + AC-3 (a): Always unschedule any pending Auto-Confirm
		// timer. Idempotent — `as_unschedule_action` is a no-op when no
		// matching action is pending. Architecture.md Z. 642 — race-
		// protection: a still-pending Auto-Confirm timer would otherwise
		// flip state to `CONFIRMED` while the cancel-mirror is in flight.
		$args = array( 'order_id' => $order_id );

		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::HOOK_CONFIRM_ORDER, $args, self::AS_GROUP );
		}

		// AC-2 / AC-3: State-branch.
		$currentState = (string) $order->get_meta( OrderStateMachine::META_KEY );

		if ( OrderStateMachine::STATE_NEW === $currentState ) {
			// AC-2: state is NEW — enqueue the cancel-mirror job.
			// Idempotency-guard: only enqueue when no matching schedule is
			// already pending (analogous to slice-28 AC-3).
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( self::HOOK_CANCEL_ORDER_MIRROR, $args, self::AS_GROUP )
			) {
				$this->log(
					'debug',
					sprintf(
						'OrderHandler: cancel-mirror idempotent skip — order_id=%d already has scheduled %s action',
						$order_id,
						self::HOOK_CANCEL_ORDER_MIRROR
					)
				);
				return;
			}

			if ( ! function_exists( 'as_enqueue_async_action' ) ) {
				$this->log(
					'error',
					sprintf(
						'OrderHandler: as_enqueue_async_action() unavailable — cannot schedule cancel_order_mirror for order_id=%d',
						$order_id
					)
				);
				return;
			}

			as_enqueue_async_action( self::HOOK_CANCEL_ORDER_MIRROR, $args, self::AS_GROUP );

			$this->log(
				'info',
				sprintf(
					'OrderHandler: enqueued %s for order_id=%d (sc_order_id=%s)',
					self::HOOK_CANCEL_ORDER_MIRROR,
					$order_id,
					$scOrderId
				)
			);
			return;
		}

		// AC-3: state is NOT `NEW` (CONFIRMED / PROCESSED / CANCELLED /
		// failed_to_submit / empty). No API call — just an order-note +
		// persistent-notice stub.
		$displayedState = '' === $currentState ? 'pending' : $currentState;

		$order->add_order_note(
			sprintf(
				'Spreadconnect: Cannot cancel in Spreadconnect (state: %s)',
				$displayedState
			),
			false,
			false
		);

		$this->recordPersistentNotice(
			$order,
			sprintf( 'Cannot mirror cancel: SC-state=%s', $displayedState ),
			'cancel_blocked_by_state',
			self::OP_TYPE_NOTICE_CANCEL_BLOCKED,
			array( 'sc_state' => $currentState )
		);
	}

	/**
	 * Auto-Confirm-Timer scheduler (slice-31 AC-7..AC-9).
	 *
	 * Hook listener for `spreadconnect/order_submitted` — fired by
	 * {@see OrderSubmitJob} on the 2xx-success path after the
	 * `submitting → NEW` CAS commits. Reads the
	 * `spreadconnect_auto_confirm` setting and schedules the
	 * `spreadconnect/confirm_order` action accordingly:
	 *
	 *   - `'off'` (default) → no schedule.
	 *   - `'immediate'` → `as_schedule_single_action(time(), …)`.
	 *   - `'after_minutes'` → `as_schedule_single_action(time() + N*60, …)`
	 *     where N is `spreadconnect_auto_confirm_minutes`. N=0 degrades
	 *     to immediate.
	 *
	 * Gating: when both the per-order `_spreadconnect_shipping_type` meta
	 * AND the shop-wide `spreadconnect_default_shipping_type` option are
	 * empty, the confirm-job's pre-check would fail at run-time anyway
	 * (architecture.md Z. 326-329 + Z. 647). We refuse to schedule in
	 * that case — saves an AS-row and a log-entry.
	 *
	 * Idempotency: `as_has_scheduled_action()` short-circuits double
	 * schedule on duplicate hook fires (AC-7).
	 *
	 * @param WC_Order $order The order whose submit just succeeded.
	 */
	public function maybeScheduleAutoConfirm( WC_Order $order ): void
	{
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$orderId = (int) $order->get_id();
		if ( $orderId <= 0 ) {
			return;
		}

		// AC-9: Setting `'off'` (or missing) ⇒ no schedule. Read defaults
		// from slice-05 OptionsDefaults.
		$mode = $this->getAutoConfirmMode();
		if ( 'immediate' !== $mode && 'after_minutes' !== $mode ) {
			return;
		}

		// Auto-Confirm gating: a confirm without shipping-type would fail
		// the pre-check (slice-29 AC-1). Do not schedule when neither the
		// per-order meta nor the shop-wide default is set.
		if ( '' === $this->resolveShippingType( $order ) ) {
			$this->log(
				'info',
				sprintf(
					'OrderHandler: auto-confirm gated — no shipping type set for order_id=%d',
					$orderId
				)
			);
			return;
		}

		$args = array( 'order_id' => $orderId );

		// AC-7 (b): Idempotency-guard — never double-schedule.
		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::HOOK_CONFIRM_ORDER, $args, self::AS_GROUP )
		) {
			$this->log(
				'debug',
				sprintf(
					'OrderHandler: auto-confirm idempotent skip — order_id=%d already has scheduled %s action',
					$orderId,
					self::HOOK_CONFIRM_ORDER
				)
			);
			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->log(
				'error',
				sprintf(
					'OrderHandler: as_schedule_single_action() unavailable — cannot schedule auto-confirm for order_id=%d',
					$orderId
				)
			);
			return;
		}

		// AC-7 / AC-8: compute the timestamp.
		$now = time();
		if ( 'immediate' === $mode ) {
			$timestamp = $now;
		} else {
			// `'after_minutes'` mode. `MINUTE_IN_SECONDS` is provided by
			// WP-core; fall back to literal `60` if undefined (defensive
			// for very stripped test contexts).
			$minutes      = $this->getAutoConfirmMinutes();
			$secsPerMinute = defined( 'MINUTE_IN_SECONDS' ) ? (int) MINUTE_IN_SECONDS : 60;
			$timestamp    = 0 === $minutes ? $now : $now + ( $minutes * $secsPerMinute );
		}

		as_schedule_single_action(
			$timestamp,
			self::HOOK_CONFIRM_ORDER,
			$args,
			self::AS_GROUP
		);

		$this->log(
			'info',
			sprintf(
				'OrderHandler: auto-confirm scheduled — order_id=%d, mode=%s, timestamp=%d',
				$orderId,
				$mode,
				$timestamp
			)
		);
	}

	/**
	 * Persistent-notice listener for `spreadconnect/auto_confirm_pre_check_failed`
	 * (slice-31 AC-10).
	 *
	 * Fired by {@see OrderConfirmJob} when its pre-check fails AND the
	 * job was scheduled by the Auto-Confirm-Timer. Architecture.md Z. 591
	 * explicitly excludes this branch from the FailedOps DLQ-Aufnahme:
	 * the operator should fix the missing shipping-type via the admin
	 * settings, not via the failed-ops resend pipeline. The dedicated
	 * persistent-notice surface (slice-39) replaces the FailedOps row.
	 *
	 * Slice 31 ships only the logging stub; slice 39 swaps the body for
	 * a real `Failure\AdminNoticeStore::add()` Option-write.
	 *
	 * @param WC_Order $order The order whose Auto-Confirm pre-check just
	 *                        failed.
	 */
	public function recordAutoConfirmPreCheckFailure( WC_Order $order ): void
	{
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$orderId = (int) $order->get_id();
		if ( $orderId <= 0 ) {
			return;
		}

		$this->recordPersistentNotice(
			$order,
			sprintf(
				'Auto-confirm could not run for order #%d — no shipping type set',
				$orderId
			),
			'auto_confirm_pre_check_failed',
			self::OP_TYPE_NOTICE_AUTO_CONFIRM_PRECHECK
		);
	}

	/**
	 * Read the `spreadconnect_auto_cancel_mirror` setting with the slice-05
	 * default (`true`). Returns `true` only when the option is exactly
	 * truthy — mirrors slice-11 SettingsValidator semantics.
	 */
	private function isAutoCancelMirrorEnabled(): bool
	{
		if ( ! function_exists( 'get_option' ) ) {
			return self::OPTION_AUTO_CANCEL_MIRROR_DEFAULT;
		}

		$value = get_option(
			self::OPTION_AUTO_CANCEL_MIRROR,
			self::OPTION_AUTO_CANCEL_MIRROR_DEFAULT
		);

		// Treat `'0'` / `0` / `''` / `false` / `'false'` as off.
		if ( false === $value || 0 === $value || '0' === $value || '' === $value ) {
			return false;
		}
		if ( is_string( $value ) && 'false' === strtolower( $value ) ) {
			return false;
		}
		return (bool) $value;
	}

	/**
	 * Read the `spreadconnect_auto_confirm` setting with the slice-05
	 * default (`'off'`). Returns the raw string for switch-dispatch.
	 */
	private function getAutoConfirmMode(): string
	{
		if ( ! function_exists( 'get_option' ) ) {
			return self::OPTION_AUTO_CONFIRM_DEFAULT;
		}

		$value = get_option( self::OPTION_AUTO_CONFIRM, self::OPTION_AUTO_CONFIRM_DEFAULT );
		return is_string( $value ) ? $value : self::OPTION_AUTO_CONFIRM_DEFAULT;
	}

	/**
	 * Read the `spreadconnect_auto_confirm_minutes` setting with the
	 * slice-05 default (`0`). Negative values are clamped to `0`.
	 */
	private function getAutoConfirmMinutes(): int
	{
		if ( ! function_exists( 'get_option' ) ) {
			return self::OPTION_AUTO_CONFIRM_MINUTES_DEFAULT;
		}

		$value   = get_option( self::OPTION_AUTO_CONFIRM_MINUTES, self::OPTION_AUTO_CONFIRM_MINUTES_DEFAULT );
		$minutes = is_numeric( $value ) ? (int) $value : 0;
		return $minutes < 0 ? 0 : $minutes;
	}

	/**
	 * Resolve the effective shipping-type for the Auto-Confirm gating
	 * decision. Order:
	 *   1. Per-order `_spreadconnect_shipping_type` meta — wins if non-empty.
	 *   2. Shop-wide `spreadconnect_default_shipping_type` option fallback.
	 *
	 * Returns `''` when neither source yields a non-empty value. The
	 * caller refuses to schedule the Auto-Confirm action in that case.
	 */
	private function resolveShippingType( WC_Order $order ): string
	{
		$perOrder = (string) $order->get_meta( self::META_SHIPPING_TYPE );
		if ( '' !== $perOrder ) {
			return $perOrder;
		}

		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}

		$default = get_option( self::OPTION_DEFAULT_SHIPPING_TYPE, '' );
		return is_string( $default ) ? $default : '';
	}

	/**
	 * Slice-39 placeholder for `Failure\AdminNoticeStore::add()`.
	 *
	 * Logging-only stub: emits a single `error`-level log entry tagged
	 * `admin_notice_pending_record`. Slice 39 replaces the body with a
	 * real `wp_options` write that surfaces a persistent admin-notice on
	 * `admin_notices`.
	 *
	 * @param array<string, mixed> $extraContext Optional extra keys merged
	 *                                           into the context payload.
	 */
	private function recordPersistentNotice(
		WC_Order $order,
		string $message,
		string $reason,
		string $opType,
		array $extraContext = array()
	): void {
		$orderId = (int) $order->get_id();

		$context = array_merge(
			array(
				'source'   => self::LOG_SOURCE,
				'tag'      => self::LOG_TAG_ADMIN_NOTICE_PENDING,
				'order_id' => $orderId,
				'reason'   => $reason,
				'op_type'  => $opType,
			),
			$extraContext
		);

		$this->logWithContext(
			'error',
			sprintf(
				'OrderHandler: %s — order_id=%d, reason=%s, message=%s',
				self::LOG_TAG_ADMIN_NOTICE_PENDING,
				$orderId,
				$reason,
				$message
			),
			$context
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
		$this->logWithContext( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}

	/**
	 * Resolve the logger and dispatch a single entry with a custom context.
	 *
	 * The `source` key MUST be present in `$context` for log-stream
	 * filtering. Slice-37 / slice-39 read the additional
	 * `tag`/`op_type`/`reason` keys.
	 *
	 * @param array<string, mixed> $context
	 */
	private function logWithContext( string $level, string $message, array $context ): void
	{
		$logger = $this->logger;
		if ( null === $logger && function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
		}
		if ( null === $logger || ! is_object( $logger ) || ! method_exists( $logger, 'log' ) ) {
			return;
		}

		if ( ! isset( $context['source'] ) ) {
			$context['source'] = self::LOG_SOURCE;
		}

		$logger->log( $level, $message, $context );
	}
}
