<?php
/**
 * Action-Scheduler handler for `spreadconnect/cancel_order_mirror`.
 *
 * Mirrors a WooCommerce-side cancel onto Spreadconnect (slice-31).
 * Distinct from {@see OrderCancelJob} (slice-29 — the generic
 * `spreadconnect/cancel_order` action triggered by admin UI / failed-ops
 * resend) only in three respects:
 *
 *   1. Hook-name (`spreadconnect/cancel_order_mirror`) — keeps the WC-driven
 *      mirror branch separately filterable in `Tools → Scheduled Actions`.
 *   2. Order-Note string — explicitly mentions that the cancel was mirrored
 *      from the WC cancel.
 *   3. `op_type` — `cancel_order_mirror` so slice-37 / slice-38 can dispatch
 *      retry / resend on the correct pipeline lane.
 *
 * The job:
 *   1. Validate `$args['order_id']` and resolve the WC-Order.
 *   2. Idempotency: skip when `_spreadconnect_order_id` meta is missing
 *      (a previous submit was never successful — there is nothing to
 *      cancel).
 *   3. **Re-Check** the persisted state — must still be exactly `NEW`.
 *      Between {@see OrderHandler::on_cancelled()} (which enqueues this
 *      job) and run-time a webhook can advance the state directly to
 *      `PROCESSED` / `CANCELLED` (architecture.md "Race-Protection"
 *      Z. 642 + slice-30 webhook direct-write). When that happens the
 *      mirror is a no-op: no API call, no CAS, only a private order-note
 *      and a persistent admin-notice (slice-39 stub).
 *   4. Call {@see SpreadconnectClient::cancelOrder()}. Three branches:
 *      - 2xx: CAS `NEW -> CANCELLED`; private order-note. Note: the WC-Order
 *        is already `cancelled` (we are on the trigger path of
 *        `woocommerce_order_status_cancelled`), so NO WC-Status mutation.
 *      - 4xx ({@see SpreadconnectClientError}): permanent failure. Emit
 *        the slice-37 logging stub (`failed_op_pending_record`) at
 *        `error` level with `op_type='cancel_order_mirror'`; private
 *        order-note with substring `'Cancel-mirror failed (4xx)'`; do
 *        NOT rethrow.
 *      - 5xx / 429-after-inner-retry / network ({@see
 *        SpreadconnectTransientError}): leave state at `NEW`; warning-log;
 *        rethrow unchanged so AS applies the 1m/5m/15m retry cascade
 *        (architecture.md Z. 551).
 *
 * Out of scope (later slices):
 *   - Real `Failure\AdminNoticeStore` insertion — slice-39 (the persistent-
 *     notice logging stub `admin_notice_pending_record` is the bridge).
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
 * Action-Scheduler hook handler for the `spreadconnect/cancel_order_mirror`
 * job (slice-31).
 *
 * Marked `final` per architecture decision (Service Map — Application Layer;
 * not extended). Constructor-injectable with the SC-API client, the state-
 * machine and an optional `WC_Logger`. The static {@see self::handleStatic()}
 * bridge is used for AS dispatch in production; tests construct the instance
 * directly with mocks.
 */
final class OrderCancelMirrorJob
{
	/**
	 * Action-Scheduler hook name. Final per architecture.md "Action Scheduler
	 * — Hook Inventory" Z. 549-552. Slice 32 (Order-Edit-Meta-Box optional
	 * resend), slice 37 (RetryPolicyListener) and slice 38 (Failed-Ops resend)
	 * reference this exact literal.
	 */
	public const HOOK_CANCEL_ORDER_MIRROR = 'spreadconnect/cancel_order_mirror';

	/**
	 * Action-Scheduler group for all `spreadconnect/*` jobs.
	 */
	public const AS_GROUP = 'spreadconnect';

	/**
	 * HPOS Order-Meta key carrying the Spreadconnect-Order-ID written by the
	 * 2xx-path of {@see OrderSubmitJob}. Presence is a hard precondition for
	 * the cancel-mirror flow.
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
	 * real `wp_spreadconnect_failed_ops` rows.
	 */
	private const LOG_TAG_FAILED_OP_PENDING = 'failed_op_pending_record';

	/**
	 * Logging tag the slice-39 `AdminNoticeStore` will pivot on to insert
	 * persistent admin-notices. Reuse-Convention from slice-30 AC-5.
	 */
	private const LOG_TAG_ADMIN_NOTICE_PENDING = 'admin_notice_pending_record';

	/**
	 * Skip-path log-tags. Distinct tags allow the Failed-Ops UI to
	 * differentiate user-actionable skip reasons from transient/permanent
	 * failures.
	 */
	private const LOG_TAG_SKIPPED_NO_SC_ORDER_ID  = 'cancel_mirror_skipped_no_sc_order_id';
	private const LOG_TAG_SKIPPED_STATE_ADVANCED  = 'cancel_mirror_skipped_state_advanced';

	/**
	 * `op_type` value recorded by the slice-37 `FailedOpsRepo` for failed
	 * `POST /orders/{id}/cancel` calls originating from the WC-cancel mirror
	 * path. Distinct from `cancel_order` (slice-29 OrderCancelJob) so the
	 * UI / resend logic can tell the two pipelines apart.
	 */
	private const OP_TYPE_CANCEL_ORDER_MIRROR = 'cancel_order_mirror';

	/**
	 * `op_type` value emitted alongside the persistent-notice stub when the
	 * mirror skips because the SC-state advanced past `NEW` between enqueue
	 * and run (AC-11).
	 */
	private const OP_TYPE_NOTICE_STATE_ADVANCED = 'cancel_mirror_state_advanced';

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
	 * Static AS-bridge. Resolves the production-default collaborator chain
	 * (lazy `$wpdb`) and dispatches into {@see self::handle()}. Used by the
	 * `add_action('spreadconnect/cancel_order_mirror', …)` registration in
	 * `Bootstrap\Plugin::init()`.
	 *
	 * @param array<string, mixed> $args AS args; expected `['order_id' => int]`.
	 *
	 * @throws SpreadconnectTransientError Re-thrown unchanged on 5xx /
	 *                                     network so AS retries.
	 */
	public static function handleStatic( array $args ): void
	{
		global $wpdb;

		$job = new self(
			new SpreadconnectClient(),
			new OrderStateMachine( $wpdb )
		);
		$job->handle( $args );
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
				'OrderCancelMirrorJob: invalid args — missing or non-positive order_id; bailing.'
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
					'OrderCancelMirrorJob: wc_get_order(%d) returned no WC_Order; bailing.',
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
	 * Pre-check order is fixed (slice-31 Constraints):
	 *   1. `_spreadconnect_order_id` presence.
	 *   2. **Re-Check** state === `'NEW'` (AC-11 race-protection).
	 *   3. API call.
	 *   4. CAS `NEW -> CANCELLED`.
	 *   5. Order-Note.
	 *
	 * @throws SpreadconnectTransientError Re-thrown unchanged on 5xx /
	 *                                     network so AS retries.
	 */
	private function run( WC_Order $order ): void
	{
		$orderId = (int) $order->get_id();

		// Skip when no SC-Order-ID is set — there is nothing to cancel
		// (a previous submit either failed or never ran). This branch is
		// unreachable under normal flow because OrderHandler::on_cancelled
		// also short-circuits on missing SC-Order-ID, but we guard
		// defensively against direct AS dispatch.
		$scOrderId = (string) $order->get_meta( self::META_ORDER_ID );
		if ( '' === $scOrderId ) {
			$this->logWithContext(
				'info',
				sprintf(
					'OrderCancelMirrorJob: job skipped, no SC-Order-ID — order_id=%d',
					$orderId
				),
				array(
					'source' => self::LOG_SOURCE,
					'tag'    => self::LOG_TAG_SKIPPED_NO_SC_ORDER_ID,
				)
			);
			return;
		}

		// AC-11: Re-Check the state. Between OrderHandler::on_cancelled
		// (enqueue) and this job-run an Order.processed webhook can advance
		// the state directly to PROCESSED. When that happens the mirror is
		// a no-op: no API call, no CAS — only a private order-note and a
		// persistent admin-notice for the operator.
		$currentState = (string) $order->get_meta( OrderStateMachine::META_KEY );
		if ( OrderStateMachine::STATE_NEW !== $currentState ) {
			$this->logWithContext(
				'info',
				sprintf(
					'OrderCancelMirrorJob: skip — state advanced before mirror could run (order_id=%d, state=%s)',
					$orderId,
					$currentState
				),
				array(
					'source' => self::LOG_SOURCE,
					'tag'    => self::LOG_TAG_SKIPPED_STATE_ADVANCED,
				)
			);
			$order->add_order_note(
				sprintf(
					'Cancel-mirror skipped: SC-state advanced to %s before mirror could run',
					'' === $currentState ? 'pending' : $currentState
				),
				false,
				false
			);
			$this->recordPersistentNotice(
				$order,
				sprintf(
					'Cancel-mirror skipped for order #%d — SC-state advanced to %s',
					$orderId,
					'' === $currentState ? 'pending' : $currentState
				),
				'cancel_mirror_state_advanced',
				self::OP_TYPE_NOTICE_STATE_ADVANCED,
				array( 'sc_state' => $currentState )
			);
			return;
		}

		// API call. Three branches per the lifecycle contract.
		try {
			$this->client->cancelOrder( $scOrderId );
		} catch ( SpreadconnectClientError $e ) {
			// Permanent failure (4xx). Catch BEFORE
			// SpreadconnectTransientError per PHP catch-order rules.
			$this->handlePermanentFailure( $order, $scOrderId, $e->getMessage() );
			return;
		} catch ( SpreadconnectTransientError $e ) {
			// Transient failure. Leave state at `NEW`; rethrow unchanged
			// so AS retries (1m/5m/15m).
			$this->log(
				'warning',
				sprintf(
					'OrderCancelMirrorJob: transient error, AS retry — order_id=%d, sc_order_id=%s, message=%s',
					$orderId,
					$scOrderId,
					$e->getMessage()
				)
			);
			throw $e;
		}

		// AC-6: 2xx success path. CAS `NEW -> CANCELLED`. WC-Order is
		// already `cancelled` (trigger path) — NO WC-Status mutation.
		$casOk = $this->stateMachine->compareAndSet(
			$order,
			OrderStateMachine::STATE_NEW,
			OrderStateMachine::STATE_CANCELLED
		);

		$order->add_order_note(
			sprintf(
				'Cancelled in Spreadconnect (#SC-%s, mirrored from WC cancel)',
				$scOrderId
			),
			false,
			false
		);

		$this->log(
			'info',
			sprintf(
				'OrderCancelMirrorJob: cancel-mirror success — order_id=%d, sc_order_id=%s, cas_ok=%s',
				$orderId,
				$scOrderId,
				$casOk ? 'true' : 'false'
			)
		);
	}

	/**
	 * Permanent-failure side-effect bundle (AC-12 a):
	 *   - Emit the slice-37 logging stub (`failed_op_pending_record`) at
	 *     `error` level with `op_type='cancel_order_mirror'` and the
	 *     contract-bound context shape.
	 *   - Append a private order-note marking the cancel-mirror as
	 *     permanently failed.
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
			'op_type'             => self::OP_TYPE_CANCEL_ORDER_MIRROR,
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
				'OrderCancelMirrorJob: %s — order_id=%d, sc_order_id=%s, message=%s',
				self::LOG_TAG_FAILED_OP_PENDING,
				$orderId,
				$scOrderId,
				$errorMessage
			),
			$context
		);

		$order->add_order_note(
			'Spreadconnect: Cancel-mirror failed (4xx) — see Failed-Ops',
			false,
			false
		);
	}

	/**
	 * Slice-39 placeholder for `Failure\AdminNoticeStore::add()`.
	 *
	 * Logging-only stub: emits a single `error`-level log entry tagged
	 * `admin_notice_pending_record`. Slice 39 replaces the body with a real
	 * `wp_options` write that surfaces a persistent admin-notice on
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
				'OrderCancelMirrorJob: %s — order_id=%d, reason=%s, message=%s',
				self::LOG_TAG_ADMIN_NOTICE_PENDING,
				$orderId,
				$reason,
				$message
			),
			$context
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
