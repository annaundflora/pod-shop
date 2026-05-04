<?php
/**
 * Action-Scheduler handler for `spreadconnect/cancel_order`.
 *
 * Implements the order-cancel hop of the order-lifecycle (architecture.md
 * "State-Transition" Z. 535-538 — `NEW + Cancel -> CANCELLED`; Z. 593 —
 * Cancel-Mirror runs ONLY when the persisted state is exactly `NEW`). The
 * job:
 *
 *   1. Validate `$args['order_id']` and resolve the WC-Order.
 *   2. Idempotency: skip when `_spreadconnect_order_id` meta is missing
 *      (a previous submit was never successful — there is nothing to
 *      cancel).
 *   3. State-check: the persisted state must still be exactly `NEW`.
 *      Anything else (`CONFIRMED`, `PROCESSED`, `CANCELLED`,
 *      `failed_to_submit`, empty) is a permanent skip with
 *      status-aware order-note (architecture.md Z. 538/593 —
 *      `'Cannot cancel in Spreadconnect (state: <actual>)'`) and log-tag
 *      `cancel_skipped_wrong_state`.
 *   4. Call {@see SpreadconnectClient::cancelOrder()}. Three branches:
 *      - 2xx: CAS `NEW -> CANCELLED`; private order-note; return cleanly.
 *        Note: WC-Order-Status is NOT mutated here — that is owned by the
 *        `Order.cancelled` webhook handler (slice-30) / the cancel-mirror
 *        listener (slice-31).
 *      - 4xx ({@see SpreadconnectClientError}): permanent failure. Emit
 *        the slice-37 logging stub (`failed_op_pending_record`) at
 *        `error` level with `op_type='cancel_order'`; private order-note
 *        with substring `'Cancel failed (4xx)'`; do NOT rethrow.
 *      - 5xx / 429-after-inner-retry / network ({@see
 *        SpreadconnectTransientError}): leave state at `NEW`; warning-log;
 *        rethrow unchanged so AS applies the retry cascade
 *        (architecture.md Z. 551).
 *
 * Out of scope (later slices):
 *   - The separate `OrderCancelMirrorJob` for the
 *     `spreadconnect/cancel_order_mirror` hook (slice-31). The mirror job
 *     uses the same body shape but a distinct hook-name and an additional
 *     WC-status-mirror step.
 *   - WC-Status-mutation on `cancelled` — slice-30 / slice-31.
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
 * Action-Scheduler hook handler for the `spreadconnect/cancel_order` job.
 *
 * Marked `final` per architecture decision (Service Map — Application
 * Layer; not extended). Constructor-injectable with the SC-API client,
 * the state-machine and an optional `WC_Logger`.
 */
final class OrderCancelJob
{
	/**
	 * HPOS Order-Meta key carrying the Spreadconnect-Order-ID written by the
	 * 2xx-path of {@see OrderSubmitJob}. Presence is a hard precondition for
	 * the cancel flow.
	 */
	private const META_ORDER_ID = '_spreadconnect_order_id';

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
	private const LOG_TAG_SKIPPED_WRONG_STATE   = 'cancel_skipped_wrong_state';
	private const LOG_TAG_SKIPPED_NO_SC_ORDER_ID = 'job_skipped_no_sc_order_id';

	/**
	 * `op_type` value recorded by the slice-37 `FailedOpsRepo` for failed
	 * `POST /orders/{id}/cancel` calls. The literal is contract-bound
	 * (architecture.md Z. 723 lists the `op_type` enum).
	 */
	private const OP_TYPE_CANCEL_ORDER = 'cancel_order';

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
				'OrderCancelJob: invalid args — missing or non-positive order_id; bailing.'
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
					'OrderCancelJob: wc_get_order(%d) returned no WC_Order; bailing.',
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
	 *   2. State-check `NEW` (AC-7, AC-8).
	 *   3. API call.
	 *
	 * @throws SpreadconnectTransientError Re-thrown unchanged on 5xx /
	 *                                     network so AS retries.
	 */
	private function run( WC_Order $order ): void
	{
		$orderId = (int) $order->get_id();

		// AC-11: Skip when no SC-Order-ID is set — there is nothing to
		// cancel yet (a previous submit either failed or never ran).
		$scOrderId = (string) $order->get_meta( self::META_ORDER_ID );
		if ( '' === $scOrderId ) {
			$this->logWithContext(
				'info',
				sprintf(
					'OrderCancelJob: job skipped, no SC-Order-ID — order_id=%d',
					$orderId
				),
				array(
					'source' => self::LOG_SOURCE,
					'tag'    => self::LOG_TAG_SKIPPED_NO_SC_ORDER_ID,
				)
			);
			return;
		}

		// AC-7 + AC-8: State-check. Cancel runs only when the state is
		// exactly `NEW`. Any other value (CONFIRMED / PROCESSED /
		// CANCELLED / failed_to_submit / empty) is a permanent skip with
		// the status-aware note from architecture.md Z. 538/593.
		$currentState = (string) $order->get_meta( OrderStateMachine::META_KEY );
		if ( OrderStateMachine::STATE_NEW !== $currentState ) {
			$this->logWithContext(
				'info',
				sprintf(
					'OrderCancelJob: skip — wrong state (order_id=%d, state=%s)',
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
					'Spreadconnect: Cannot cancel in Spreadconnect (state: %s)',
					'' === $currentState ? 'pending' : $currentState
				),
				false,
				false
			);
			return;
		}

		// API call. Three branches per the lifecycle contract.
		try {
			$this->client->cancelOrder( $scOrderId );
		} catch ( SpreadconnectClientError $e ) {
			// AC-9: Permanent failure (4xx). Catch BEFORE
			// SpreadconnectTransientError per PHP catch-order rules.
			$this->handlePermanentFailure( $order, $scOrderId, $e->getMessage() );
			return;
		} catch ( SpreadconnectTransientError $e ) {
			// AC-10: Transient failure. Leave state at `NEW`; rethrow
			// unchanged so AS retries.
			$this->log(
				'warning',
				sprintf(
					'OrderCancelJob: transient error, AS retry — order_id=%d, sc_order_id=%s, message=%s',
					$orderId,
					$scOrderId,
					$e->getMessage()
				)
			);
			throw $e;
		}

		// AC-7: 2xx success path. CAS `NEW -> CANCELLED`.
		$casOk = $this->stateMachine->compareAndSet(
			$order,
			OrderStateMachine::STATE_NEW,
			OrderStateMachine::STATE_CANCELLED
		);

		$order->add_order_note(
			sprintf( 'Cancelled in Spreadconnect (#SC-%s)', $scOrderId ),
			false,
			false
		);

		$this->log(
			'info',
			sprintf(
				'OrderCancelJob: cancel success — order_id=%d, sc_order_id=%s, cas_ok=%s',
				$orderId,
				$scOrderId,
				$casOk ? 'true' : 'false'
			)
		);
	}

	/**
	 * Permanent-failure side-effect bundle (AC-9):
	 *   - Emit the slice-37 logging stub (`failed_op_pending_record`) at
	 *     `error` level with `op_type='cancel_order'` and the contract-bound
	 *     context shape.
	 *   - Append a private order-note marking the cancel as permanently
	 *     failed.
	 *   - State stays at `NEW` (no CAS-call to `CANCELLED`).
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
			'op_type'             => self::OP_TYPE_CANCEL_ORDER,
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
				'OrderCancelJob: %s — order_id=%d, sc_order_id=%s, message=%s',
				self::LOG_TAG_FAILED_OP_PENDING,
				$orderId,
				$scOrderId,
				$errorMessage
			),
			$context
		);

		$order->add_order_note(
			'Spreadconnect: Cancel failed (4xx) — see Failed-Ops',
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
