<?php
/**
 * Action-Scheduler handler for `spreadconnect/confirm_order`.
 *
 * Implements the order-confirm hop of the order-lifecycle (architecture.md
 * "State-Transition" Z. 535-538 — `NEW + Confirm -> CONFIRMED`; "Confirm-
 * Pre-Check" Z. 588 — Shipping-Type must be set). The job:
 *
 *   1. Validate `$args['order_id']` and resolve the WC-Order.
 *   2. Idempotency: skip when `_spreadconnect_order_id` meta is missing
 *      (a previous submit was never successful — there is nothing to
 *      confirm).
 *   3. Pre-check: the SC-Order must already have a shipping-type, either
 *      via the per-order `_spreadconnect_shipping_type` meta or via the
 *      shop-wide `spreadconnect_default_shipping_type` option fallback.
 *      Missing shipping-type ⇒ permanent skip with order-note + log-tag
 *      `confirm_pre_check_failed` (no API call, no state mutation, no
 *      AS-retry).
 *   4. State-check: the persisted state must still be `NEW`. Anything
 *      else (`CONFIRMED`, `PROCESSED`, `CANCELLED`, `failed_to_submit`,
 *      empty) is a permanent skip with status-aware order-note + log-tag
 *      `confirm_skipped_wrong_state`.
 *   5. Call {@see SpreadconnectClient::confirmOrder()}. Three branches:
 *      - 2xx: CAS `NEW -> CONFIRMED`; private order-note; return cleanly.
 *      - 4xx ({@see SpreadconnectClientError}): permanent failure. Emit
 *        the slice-37 logging stub (`failed_op_pending_record`) at
 *        `error` level with `op_type='confirm_order'`; private order-note
 *        with substring `'Confirm failed (4xx)'`; do NOT rethrow.
 *      - 5xx / 429-after-inner-retry / network ({@see
 *        SpreadconnectTransientError}): leave state at `NEW`; warning-log;
 *        rethrow unchanged so AS applies the 1m/5m/15m retry cascade
 *        (architecture.md Z. 550).
 *
 * Out of scope (later slices):
 *   - Auto-Confirm-Timer scheduling (`as_schedule_single_action`) —
 *     slice-31.
 *   - Auto-Confirm-Pre-Check-Failure persistent admin-notice
 *     (architecture.md Z. 591) — slice-31 / slice-39.
 *   - `setShippingType()` round-trip before confirm — slice-31/32.
 *   - Real `Failure\FailedOpsRepo` insertion — slice-37.
 *
 * @package SpreadconnectPod\Order
 */

declare(strict_types=1);

namespace SpreadconnectPod\Order;

use SpreadconnectPod\Api\SpreadconnectClient;
use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;
use WC_Logger;
use WC_Order;

/**
 * Action-Scheduler hook handler for the `spreadconnect/confirm_order` job.
 *
 * Marked `final` per architecture decision (Service Map Z. 367 —
 * Application Layer; not extended). Constructor-injectable with the
 * SC-API client, the state-machine and an optional `WC_Logger`.
 */
final class OrderConfirmJob
{
	/**
	 * HPOS Order-Meta key carrying the Spreadconnect-Order-ID written by the
	 * 2xx-path of {@see OrderSubmitJob}. Presence is a hard precondition for
	 * the confirm flow.
	 */
	private const META_ORDER_ID = '_spreadconnect_order_id';

	/**
	 * HPOS Order-Meta key carrying the shipping-type chosen for this order
	 * (architecture.md Z. 312). The pre-check (AC-1, AC-3) reads this first
	 * and falls back to the shop-wide default option.
	 */
	private const META_SHIPPING_TYPE = '_spreadconnect_shipping_type';

	/**
	 * Shop-wide default shipping-type option seeded by slice-05's
	 * {@see OptionsDefaults::install()}. Empty string by default — slice-11
	 * makes it admin-configurable.
	 */
	private const OPTION_DEFAULT_SHIPPING_TYPE = 'spreadconnect_default_shipping_type';

	/**
	 * Logger source string for `wc_get_logger()` — shared across all
	 * order-service classes so Failed-Ops dashboards can filter the entire
	 * stream.
	 */
	private const LOG_SOURCE = 'spreadconnect-order-service';

	/**
	 * Logging tag the slice-37 `RetryPolicyListener` will pivot on to insert
	 * real `wp_spreadconnect_failed_ops` rows. Until then the 4xx-path emits
	 * an `error`-level log entry carrying this tag in the context payload.
	 */
	private const LOG_TAG_FAILED_OP_PENDING = 'failed_op_pending_record';

	/**
	 * Skip-path log-tags (per slice-29 Constraints — distinct tags allow the
	 * Failed-Ops UI to differentiate user-actionable skip reasons from
	 * transient/permanent failures).
	 */
	private const LOG_TAG_PRE_CHECK_FAILED      = 'confirm_pre_check_failed';
	private const LOG_TAG_SKIPPED_WRONG_STATE   = 'confirm_skipped_wrong_state';
	private const LOG_TAG_SKIPPED_NO_SC_ORDER_ID = 'job_skipped_no_sc_order_id';

	/**
	 * `op_type` value recorded by the slice-37 `FailedOpsRepo` for failed
	 * `POST /orders/{id}/confirm` calls. The literal is contract-bound
	 * (architecture.md Z. 723 lists the `op_type` enum).
	 */
	private const OP_TYPE_CONFIRM_ORDER = 'confirm_order';

	/**
	 * `related_entity_type` value paired with the WC-Order ID for the
	 * `wp_spreadconnect_failed_ops` row.
	 */
	private const RELATED_ENTITY_ORDER = 'order';

	private SpreadconnectClient $client;

	private OrderStateMachine $stateMachine;

	private ?WC_Logger $logger;

	/**
	 * @param SpreadconnectClient $client       SC-API HTTP client (slice-10).
	 * @param OrderStateMachine   $stateMachine CAS service for the
	 *                                          `_spreadconnect_state` meta
	 *                                          (slice-27).
	 * @param WC_Logger|null      $logger       Optional logger override.
	 */
	public function __construct(
		SpreadconnectClient $client,
		OrderStateMachine $stateMachine,
		?WC_Logger $logger = null
	) {
		$this->client       = $client;
		$this->stateMachine = $stateMachine;
		$this->logger       = $logger;
	}

	/**
	 * Action-Scheduler entry point. AS dispatches the registered hook with
	 * the args-array as the first parameter; this method validates the
	 * payload, loads the WC-Order and dispatches into {@see self::run()}.
	 *
	 * Invalid payloads (missing / non-positive `order_id`, or
	 * `wc_get_order()` returning falsy) produce a still-and-silent return
	 * to prevent an AS retry-loop on a permanently broken job.
	 *
	 * @param array<string, mixed> $args Action-Scheduler args; expected
	 *                                   shape `['order_id' => int]`.
	 *
	 * @throws SpreadconnectTransientError Re-thrown unchanged on 5xx /
	 *                                     network so AS retries.
	 */
	public function handle( array $args ): void
	{
		$orderId = (int) ( $args['order_id'] ?? 0 );
		if ( $orderId <= 0 ) {
			$this->log(
				'warning',
				'OrderConfirmJob: invalid args — missing or non-positive order_id; bailing.'
			);
			return;
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			// Defensive: WC not loaded. Bail silently — the AS-runner
			// will retry on the next request once WC is back.
			return;
		}

		$order = wc_get_order( $orderId );
		if ( ! $order instanceof WC_Order ) {
			$this->log(
				'warning',
				sprintf(
					'OrderConfirmJob: wc_get_order(%d) returned no WC_Order; bailing.',
					$orderId
				)
			);
			return;
		}

		$this->run( $order );
	}

	/**
	 * Body of the job once the `WC_Order` has been resolved.
	 *
	 * Pre-check order is fixed (slice-29 Constraints):
	 *   1. `_spreadconnect_order_id` presence (AC-11).
	 *   2. Shipping-type pre-check (AC-1, AC-3).
	 *   3. State-check `NEW` (AC-4).
	 *   4. API call.
	 *
	 * @throws SpreadconnectTransientError Re-thrown unchanged on 5xx /
	 *                                     network so AS retries.
	 */
	private function run( WC_Order $order ): void
	{
		$orderId = (int) $order->get_id();

		// AC-11: Skip when no SC-Order-ID is set — there is nothing to
		// confirm yet (a previous submit either failed or never ran).
		$scOrderId = (string) $order->get_meta( self::META_ORDER_ID );
		if ( '' === $scOrderId ) {
			$this->logWithContext(
				'info',
				sprintf(
					'OrderConfirmJob: job skipped, no SC-Order-ID — order_id=%d',
					$orderId
				),
				array(
					'source' => self::LOG_SOURCE,
					'tag'    => self::LOG_TAG_SKIPPED_NO_SC_ORDER_ID,
				)
			);
			return;
		}

		// AC-1 + AC-3: Shipping-type pre-check. The order may carry the
		// shipping-type either as per-order meta (set by slice-31/32 admin
		// action) or via the shop-wide default option (slice-05 default,
		// slice-11 admin-configurable). Either presence is enough to pass.
		$shippingType = $this->resolveShippingType( $order );
		if ( '' === $shippingType ) {
			$this->logWithContext(
				'warning',
				sprintf(
					'OrderConfirmJob: pre-check failed — no shipping type set for order_id=%d (sc_order_id=%s)',
					$orderId,
					$scOrderId
				),
				array(
					'source' => self::LOG_SOURCE,
					'tag'    => self::LOG_TAG_PRE_CHECK_FAILED,
				)
			);
			$order->add_order_note(
				'Spreadconnect: Cannot confirm: no shipping type set',
				false,
				false
			);

			// Slice-31 AC-10: dedicated persistent-notice surface for the
			// Auto-Confirm-Timer pre-check failure path. Architecture.md
			// Z. 591 explicitly excludes this branch from the FailedOps
			// DLQ-Aufnahme — fire a Notification-only action so the
			// {@see \SpreadconnectPod\Order\OrderHandler::recordAutoConfirmPreCheckFailure}
			// listener (registered in slice-31 only) can write the
			// persistent-notice stub. Slice-29 ACs 1-12 stay semantically
			// identical: the order-note + log-tag above are unchanged;
			// the new `do_action` is a pure notification with no
			// side-effect on the Slice-29 surface itself.
			if ( function_exists( 'do_action' ) ) {
				do_action( 'spreadconnect/auto_confirm_pre_check_failed', $order );
			}
			return;
		}

		// AC-4: State-check. Confirm runs only when the state is exactly
		// `NEW`. Any other value (CONFIRMED / PROCESSED / CANCELLED /
		// failed_to_submit / empty) is a permanent skip.
		$currentState = (string) $order->get_meta( OrderStateMachine::META_KEY );
		if ( OrderStateMachine::STATE_NEW !== $currentState ) {
			$this->logWithContext(
				'info',
				sprintf(
					'OrderConfirmJob: skip — wrong state (order_id=%d, state=%s)',
					$orderId,
					$currentState
				),
				array(
					'source' => self::LOG_SOURCE,
					'tag'    => self::LOG_TAG_SKIPPED_WRONG_STATE,
				)
			);
			$order->add_order_note(
				sprintf(
					'Spreadconnect: Confirm skipped (state: %s)',
					'' === $currentState ? 'pending' : $currentState
				),
				false,
				false
			);
			return;
		}

		// API call. Three branches per the lifecycle contract.
		try {
			$this->client->confirmOrder( $scOrderId );
		} catch ( SpreadconnectClientError $e ) {
			// AC-5: Permanent failure (4xx). Catch BEFORE
			// SpreadconnectTransientError per PHP catch-order rules.
			$this->handlePermanentFailure( $order, $scOrderId, $e->getMessage() );
			return;
		} catch ( SpreadconnectTransientError $e ) {
			// AC-6: Transient failure. Leave state at `NEW`; rethrow
			// unchanged so AS retries (1m/5m/15m).
			$this->log(
				'warning',
				sprintf(
					'OrderConfirmJob: transient error, AS retry — order_id=%d, sc_order_id=%s, message=%s',
					$orderId,
					$scOrderId,
					$e->getMessage()
				)
			);
			throw $e;
		}

		// AC-2: 2xx success path. CAS `NEW -> CONFIRMED`.
		$casOk = $this->stateMachine->compareAndSet(
			$order,
			OrderStateMachine::STATE_NEW,
			OrderStateMachine::STATE_CONFIRMED
		);

		$order->add_order_note(
			sprintf( 'Confirmed in Spreadconnect (#SC-%s)', $scOrderId ),
			false,
			false
		);

		$this->log(
			'info',
			sprintf(
				'OrderConfirmJob: confirm success — order_id=%d, sc_order_id=%s, cas_ok=%s',
				$orderId,
				$scOrderId,
				$casOk ? 'true' : 'false'
			)
		);
	}

	/**
	 * Resolve the effective shipping-type for the confirm pre-check.
	 *
	 * Order:
	 *   1. Per-order `_spreadconnect_shipping_type` meta — wins if non-empty.
	 *   2. Shop-wide `spreadconnect_default_shipping_type` option fallback.
	 *
	 * Returns `''` when neither source yields a non-empty value — caller
	 * treats this as a pre-check failure (no API call).
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
	 * Permanent-failure side-effect bundle (AC-5):
	 *   - Emit the slice-37 logging stub (`failed_op_pending_record`) at
	 *     `error` level with `op_type='confirm_order'` and the contract-bound
	 *     context shape.
	 *   - Append a private order-note marking the confirm as permanently
	 *     failed.
	 *   - State stays at `NEW` (no CAS-call to `CONFIRMED`).
	 *   - Do NOT rethrow — Action Scheduler must not retry this job.
	 */
	private function handlePermanentFailure(
		WC_Order $order,
		string $scOrderId,
		string $errorMessage
	): void {
		$orderId = (int) $order->get_id();

		$context = array(
			'source'              => self::LOG_SOURCE,
			'tag'                 => self::LOG_TAG_FAILED_OP_PENDING,
			'op_type'             => self::OP_TYPE_CONFIRM_ORDER,
			'related_entity_type' => self::RELATED_ENTITY_ORDER,
			'related_entity_id'   => $orderId,
			'payload'             => array(
				'order_id'    => $orderId,
				'sc_order_id' => $scOrderId,
			),
			'error_message'       => $errorMessage,
			'state'               => 'unresolved',
		);

		$this->logWithContext(
			'error',
			sprintf(
				'OrderConfirmJob: %s — order_id=%d, sc_order_id=%s, message=%s',
				self::LOG_TAG_FAILED_OP_PENDING,
				$orderId,
				$scOrderId,
				$errorMessage
			),
			$context
		);

		$order->add_order_note(
			'Spreadconnect: Confirm failed (4xx) — see Failed-Ops',
			false,
			false
		);
	}

	/**
	 * Resolve the logger and dispatch a single entry with `source` only.
	 */
	private function log( string $level, string $message ): void
	{
		$this->logWithContext( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}

	/**
	 * Resolve the logger and dispatch a single entry with a custom context.
	 *
	 * The `source` key MUST be present in `$context` for log-stream
	 * filtering. Slice-37's RetryPolicyListener reads the additional
	 * `tag`/`op_type`/`related_entity_*`/`payload` keys.
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
