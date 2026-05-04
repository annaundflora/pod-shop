<?php
/**
 * Action-Scheduler handler for `spreadconnect/fetch_tracking`.
 *
 * Implements the tracking-fetch hop of the order-lifecycle (architecture.md
 * "Action Scheduler — Hook Inventory" Z. 552 — `Shipment.sent`-webhook
 * trigger, one-shot, Concurrency 1 per SC-order, Retry 1m/5m/15m;
 * "WC-Order Meta" Z. 313-314 for the persisted tracking fields). The job:
 *
 *   1. Validate `$args['order_id']` and resolve the WC-Order.
 *   2. Read `_spreadconnect_order_id` (set by {@see OrderSubmitJob} 2xx
 *      path). Missing = inconsistent job-args ⇒ permanent
 *      {@see FetchTrackingException} (Action-Scheduler will retry, then
 *      slice-37 records a DLQ row).
 *   3. Call {@see SpreadconnectClient::getShipments()}.
 *      - 4xx ({@see SpreadconnectClientError}) ⇒ permanent failure.
 *        Emit the slice-37 logging stub (`failed_op_pending_record`) at
 *        `error` level with `op_type='fetch_tracking'`; do NOT rethrow.
 *      - 5xx / 429 / network ({@see SpreadconnectTransientError}) ⇒ leave
 *        order untouched; rethrow unchanged so AS retries.
 *   4. Empty shipments-array ⇒ throw {@see FetchTrackingException}
 *      (transient: SC-API is not yet consistent with the webhook).
 *   5. Pick the FIRST shipment-element. Persist `_spreadconnect_tracking_*`
 *      meta. Optionally `_spreadconnect_carrier` if present.
 *   6. Idempotency: when `$order->get_status() !== 'completed'`, flip the
 *      WC-Status via `WC_Order::update_status('completed', $note, false)`.
 *      Already-`completed` orders skip the status flip (note via
 *      `update_status` would otherwise duplicate). Tracking-meta is still
 *      written (idempotent refresh).
 *
 * Out of scope (later slices):
 *   - Real `Failure\FailedOpsRepo` insertion — slice-37 (the retry-policy
 *     listener consumes the `failed_op_pending_record` log tag).
 *   - DLQ-promotion after 3 retries — slice-37 `RetryPolicyListener`.
 *   - Pinterest-CAPI fan-out on `woocommerce_order_status_completed` —
 *     handled by the separate `pinterest-capi` plugin.
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
 * Action-Scheduler hook handler for the `spreadconnect/fetch_tracking` job.
 *
 * Marked `final` per architecture decision (Service Map — Application
 * Layer; not extended). Constructor-injectable with the SC-API client and
 * an optional `WC_Logger`. The static {@see self::handleStatic()} bridge
 * is the entry-point for `add_action('spreadconnect/fetch_tracking', ...)`
 * — mirrors the slice-25 / slice-28 / slice-29 wiring convention.
 */
final class FetchTrackingJob
{
	/**
	 * HPOS Order-Meta key carrying the Spreadconnect-Order-ID written by the
	 * 2xx-path of {@see OrderSubmitJob}. Required precondition for the
	 * fetch-tracking flow — empty meta ⇒ permanent failure.
	 */
	private const META_ORDER_ID = '_spreadconnect_order_id';

	/**
	 * HPOS Order-Meta key for the persisted tracking number
	 * (architecture.md Z. 313).
	 */
	private const META_TRACKING_NUMBER = '_spreadconnect_tracking_number';

	/**
	 * HPOS Order-Meta key for the persisted tracking URL
	 * (architecture.md Z. 314).
	 */
	private const META_TRACKING_URL = '_spreadconnect_tracking_url';

	/**
	 * HPOS Order-Meta key for the optional carrier code (slice-30 ACs +
	 * Discovery — DHL / UPS / DPD …). Written only when the SC payload
	 * exposes the field.
	 */
	private const META_CARRIER = '_spreadconnect_carrier';

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
	 * `op_type` value recorded by the slice-37 `FailedOpsRepo` for failed
	 * `GET /orders/{id}/shipments` calls. The literal is contract-bound
	 * (architecture.md Z. 723 lists the `op_type` enum).
	 */
	private const OP_TYPE_FETCH_TRACKING = 'fetch_tracking';

	/**
	 * `related_entity_type` value paired with the WC-Order ID for the
	 * `wp_spreadconnect_failed_ops` row.
	 */
	private const RELATED_ENTITY_ORDER = 'order';

	/**
	 * WC order-status flipped on tracking-success (architecture.md
	 * "State-Transition" — `Shipment.sent` ⇒ WC-Status `completed`).
	 */
	private const WC_STATUS_COMPLETED = 'completed';

	private SpreadconnectClient $client;

	private ?WC_Logger $logger;

	/**
	 * @param SpreadconnectClient $client SC-API HTTP client (slice-10).
	 * @param WC_Logger|null      $logger Optional logger override.
	 */
	public function __construct( SpreadconnectClient $client, ?WC_Logger $logger = null )
	{
		$this->client = $client;
		$this->logger = $logger;
	}

	/**
	 * Static bridge for `add_action('spreadconnect/fetch_tracking', …)`.
	 *
	 * Action-Scheduler invokes the registered hook with the args-array as
	 * the first parameter. This bridge instantiates a fresh job instance
	 * (production-default collaborator chain) and delegates to
	 * {@see self::handle()} — mirrors the slice-25 / slice-28 / slice-29
	 * `handleStatic` convention. The full DI-Container wiring is
	 * introduced in slice-37.
	 *
	 * @param array<string, mixed> $args Action-Scheduler args; expected
	 *                                   shape `['order_id'=>int]`.
	 *
	 * @throws FetchTrackingException     On empty shipments / missing
	 *                                    `_spreadconnect_order_id`.
	 * @throws SpreadconnectTransientError Re-thrown unchanged on 5xx /
	 *                                    network so AS retries.
	 */
	public static function handleStatic( array $args ): void
	{
		( new self( new SpreadconnectClient() ) )->handle( $args );
	}

	/**
	 * Action-Scheduler entry point.
	 *
	 * Invalid payloads (missing / non-positive `order_id`, or
	 * `wc_get_order()` returning falsy) produce a still-and-silent return
	 * to prevent an AS retry-loop on a permanently broken job.
	 *
	 * @param array<string, mixed> $args Action-Scheduler args; expected
	 *                                   shape `['order_id' => int]`.
	 *
	 * @throws FetchTrackingException     On empty shipments / missing SC-id.
	 * @throws SpreadconnectTransientError Re-thrown unchanged on 5xx /
	 *                                    network so AS retries.
	 */
	public function handle( array $args ): void
	{
		$orderId = (int) ( $args['order_id'] ?? 0 );
		if ( $orderId <= 0 ) {
			$this->log(
				'warning',
				'FetchTrackingJob: invalid args — missing or non-positive order_id; bailing.'
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
					'FetchTrackingJob: wc_get_order(%d) returned no WC_Order; bailing.',
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
	 * @throws FetchTrackingException     On empty shipments / missing SC-id.
	 * @throws SpreadconnectTransientError Re-thrown unchanged on 5xx /
	 *                                    network so AS retries.
	 */
	private function run( WC_Order $order ): void
	{
		$orderId   = (int) $order->get_id();
		$scOrderId = (string) $order->get_meta( self::META_ORDER_ID );

		if ( '' === $scOrderId ) {
			// Permanent: the consumer was scheduled without the producer-
			// side OrderSubmitJob having ever persisted the SC-Order-ID.
			// Throw FetchTrackingException so AS surfaces it as a job-
			// failure; slice-37 RetryPolicyListener records DLQ after the
			// 3rd retry.
			throw new FetchTrackingException(
				sprintf(
					'FetchTrackingJob: missing _spreadconnect_order_id meta order_id=%d',
					$orderId
				)
			);
		}

		// API call. Three branches:
		//   - 4xx ⇒ permanent failure (logging stub, no rethrow).
		//   - 5xx / network ⇒ rethrow for AS retry.
		//   - 2xx ⇒ array of shipments (possibly empty — see below).
		try {
			$shipments = $this->client->getShipments( $scOrderId );
		} catch ( SpreadconnectClientError $e ) {
			// Catch BEFORE SpreadconnectTransientError per PHP catch-order
			// rules (specific subclass first).
			$this->handlePermanentFailure( $orderId, $scOrderId, $e->getMessage() );
			return;
		} catch ( SpreadconnectTransientError $e ) {
			$this->log(
				'warning',
				sprintf(
					'FetchTrackingJob: transient error, AS retry — order_id=%d, sc_order_id=%s, message=%s',
					$orderId,
					$scOrderId,
					$e->getMessage()
				)
			);
			throw $e;
		}

		if ( ! is_array( $shipments ) || empty( $shipments ) ) {
			// Race: webhook arrived before the SC-API list-endpoint became
			// consistent. Throw so AS retries (1m/5m/15m).
			throw new FetchTrackingException(
				sprintf(
					'FetchTrackingJob: empty shipments response order_id=%d sc_id=%s',
					$orderId,
					$scOrderId
				)
			);
		}

		// Multi-shipment response: pick the first element + log info.
		// Real-world SC fulfillment is single-package per order in practice;
		// the multi-element guard is defence-in-depth.
		if ( count( $shipments ) > 1 ) {
			$this->log(
				'info',
				sprintf(
					'FetchTrackingJob: multiple shipments order_id=%d, using first',
					$orderId
				)
			);
		}

		$first = $shipments[0] ?? null;
		if ( ! is_array( $first ) ) {
			// Defence-in-depth: SC contract guarantees a Shipment[] but a
			// malformed payload should not crash the worker. Treat as
			// permanent failure.
			$this->handlePermanentFailure(
				$orderId,
				$scOrderId,
				'shipments[0] is not an array'
			);
			return;
		}

		$trackingNumber = isset( $first['trackingNumber'] ) && is_string( $first['trackingNumber'] )
			? $first['trackingNumber']
			: '';
		$trackingUrl    = isset( $first['trackingUrl'] ) && is_string( $first['trackingUrl'] )
			? $first['trackingUrl']
			: '';
		$carrier        = isset( $first['carrier'] ) && is_string( $first['carrier'] )
			? $first['carrier']
			: '';

		// Idempotent meta-write — Last-Write-Wins. Re-runs on an already-
		// `completed` order overwrite identical values harmlessly (AC-10).
		$order->update_meta_data( self::META_TRACKING_NUMBER, $trackingNumber );
		$order->update_meta_data( self::META_TRACKING_URL, $trackingUrl );
		if ( '' !== $carrier ) {
			$order->update_meta_data( self::META_CARRIER, $carrier );
		}
		$order->save();

		// AC-10: Idempotency-Guard against double-status-flip. `update_status`
		// itself fires `woocommerce_order_status_*`-hooks (Pinterest-CAPI
		// downstream consumers depend on these), so we MUST NOT call it
		// when the status is already `completed`.
		if ( self::WC_STATUS_COMPLETED !== $order->get_status() ) {
			$order->update_status(
				self::WC_STATUS_COMPLETED,
				sprintf(
					'Spreadconnect: Shipped — tracking %s (%s)',
					$trackingNumber,
					'' === $carrier ? '-' : $carrier
				),
				false
			);
		}

		$this->log(
			'info',
			sprintf(
				'FetchTrackingJob: tracking persisted — order_id=%d, sc_order_id=%s, tracking=%s, carrier=%s',
				$orderId,
				$scOrderId,
				$trackingNumber,
				$carrier
			)
		);
	}

	/**
	 * Permanent-failure side-effect bundle (4xx-path):
	 *   - Emit the slice-37 logging stub (`failed_op_pending_record`) at
	 *     `error` level with `op_type='fetch_tracking'` and the contract-
	 *     bound context shape (mirrors slice-28 / slice-29).
	 *   - Do NOT rethrow — Action Scheduler must not retry this job.
	 *   - Tracking-meta + WC-Status stay untouched.
	 */
	private function handlePermanentFailure(
		int $orderId,
		string $scOrderId,
		string $errorMessage
	): void {
		$context = array(
			'source'              => self::LOG_SOURCE,
			'tag'                 => self::LOG_TAG_FAILED_OP_PENDING,
			'op_type'             => self::OP_TYPE_FETCH_TRACKING,
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
				'FetchTrackingJob: %s — order_id=%d, sc_order_id=%s, message=%s',
				self::LOG_TAG_FAILED_OP_PENDING,
				$orderId,
				$scOrderId,
				$errorMessage
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

/**
 * Raised when {@see FetchTrackingJob} cannot persist tracking-meta —
 * either because the SC-API returned an empty shipments-array (race with
 * the `Shipment.sent` webhook arriving before list-consistency) or the
 * `_spreadconnect_order_id` job-arg is missing.
 *
 * Extends `\RuntimeException` (not a new base) so Action-Scheduler retry
 * logic classifies it as a transient failure — same pattern as
 * {@see \SpreadconnectPod\Catalog\ArticleRemovedException} (slice-25).
 * After 3 retries slice-37 `RetryPolicyListener` records a
 * `wp_spreadconnect_failed_ops` row with `op_type='fetch_tracking'`.
 */
final class FetchTrackingException extends \RuntimeException
{
}
