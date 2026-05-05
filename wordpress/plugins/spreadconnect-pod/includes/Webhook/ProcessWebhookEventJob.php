<?php
/**
 * Action-Scheduler dispatcher for `spreadconnect/process_webhook_event`
 * (slice-17).
 *
 * Closes the webhook receive pipeline (architecture.md Flow E, Z. 432-458):
 *
 *   1. Slice 16's {@see WebhookController::handle()} persists the inbound
 *      payload via {@see WebhookLogRepo::insertOrIgnore()} and on a fresh
 *      insert enqueues `spreadconnect/process_webhook_event` with `[$logId]`.
 *   2. Action Scheduler invokes {@see self::handle($logId)} on a separate
 *      request thread.
 *   3. This class loads the row, parses the JSON payload, dispatches per
 *      `eventType`-prefix to the appropriate domain handler stub
 *      ({@see OrderEventHandler} for `Order.*`/`Shipment.*`,
 *      {@see ArticleEventHandler} for `Article.*`), and writes the
 *      processing outcome back to `wp_spreadconnect_webhook_log`.
 *
 * Outcome matrix (per slice-17 ACs):
 *
 * | Condition                              | Status update                                | Re-throw? |
 * |----------------------------------------|----------------------------------------------|-----------|
 * | Happy path (handler returns cleanly)   | `success`, `processing_error=null`           | no        |
 * | `WebhookLogRepo::find()` returns null  | (no update — row is gone)                    | no        |
 * | Invalid JSON / missing `eventType`     | `error`, `processing_error='invalid_payload'`| no        |
 * | Unknown `eventType` prefix             | `error`, `processing_error='unknown_event_type'`| no    |
 * | `SpreadconnectClientError` (4xx)       | `error`, `processing_error=<msg>`            | no (record-stub) |
 * | Generic `\Throwable`                   | `error`, `processing_error=<msg>`            | no (record-stub) |
 * | `SpreadconnectTransientError` (5xx)    | `error`, `processing_error=<msg>`            | YES (AS retries 1m/5m/15m) |
 *
 * The {@see self::recordFailedOp()} method is a slice-17 logging-only stub
 * that emits a `wc_get_logger()->error(...)` line tagged with source
 * `spreadconnect-failure`. Slice 37 replaces the body with a real
 * `Failure\FailedOpsRepo::record()` insertion — same method name, same
 * arguments, same call sites.
 *
 * Idempotency note (AC-8): re-runs of an already-`success`/`error` row are
 * not short-circuited here. The Domain-handler-internal CAS (slice 25/30)
 * is the idempotency barrier; this class is the dumb dispatcher.
 *
 * @package SpreadconnectPod\Webhook
 */

declare(strict_types=1);

namespace SpreadconnectPod\Webhook;

use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;
use Throwable;

/**
 * Action-Scheduler dispatcher entry-point — `final class` with a single
 * `public static handle()` method per the Job-pattern (architecture.md
 * Z. 532).
 *
 * The class has no constructor and no instance state; collaborators
 * ({@see WebhookLogRepo}, {@see OrderEventHandler}, {@see ArticleEventHandler})
 * are referenced through their static APIs. Brain\Monkey + Mockery
 * `alias:`-mocks cover the unit-test surface.
 */
final class ProcessWebhookEventJob
{
	/**
	 * `wc_get_logger()` source string for dispatcher-internal log lines
	 * (architecture.md Z. 398).
	 */
	private const LOG_SOURCE = 'spreadconnect-webhook-receiver';

	/**
	 * `wc_get_logger()` source string used by the {@see self::recordFailedOp()}
	 * stub (architecture.md Z. 398). Slice 37 swaps the stub body for a
	 * real `Failure\FailedOpsRepo::record()` call but the source label
	 * stays.
	 */
	private const FAILURE_LOG_SOURCE = 'spreadconnect-failure';

	/**
	 * `op_type` value for failed webhook-handle attempts. Contract-bound
	 * by architecture.md Z. 723 (Failed-Ops `op_type` enum).
	 */
	private const OP_TYPE_HANDLE_WEBHOOK = 'handle_webhook';

	/**
	 * Permanent-error marker written to `processing_error` when the
	 * payload's `eventType` does not match any of the 7 known prefixes
	 * (architecture.md Z. 467).
	 */
	private const ERROR_UNKNOWN_EVENT_TYPE = 'unknown_event_type';

	/**
	 * Permanent-error marker written to `processing_error` when the
	 * payload bytes cannot be JSON-decoded or the decoded array lacks
	 * the `eventType` key (architecture.md Z. 466).
	 */
	private const ERROR_INVALID_PAYLOAD = 'invalid_payload';

	/**
	 * `processing_error` column upper bound — `TEXT` is 65 535 bytes
	 * (slice-04 AC-2). Exception messages are `mb_substr`-clipped to
	 * stay within the column on UTF-8 input.
	 */
	private const PROCESSING_ERROR_MAX_BYTES = 65535;

	/**
	 * Action-Scheduler entry point.
	 *
	 * Bound to `spreadconnect/process_webhook_event` from
	 * {@see \SpreadconnectPod\Bootstrap\Plugin::init()} with priority 10
	 * and exactly one accepted argument (slice-17 AC-1).
	 *
	 * @param int $logId Primary-key of the `wp_spreadconnect_webhook_log`
	 *                   row to dispatch — corresponds to the
	 *                   `as_enqueue_async_action(..., [$logId])` payload
	 *                   from slice-16.
	 *
	 * @return void
	 *
	 * @throws SpreadconnectTransientError Re-thrown unchanged on a
	 *                                     transient (5xx/network) handler
	 *                                     failure so AS retries (1m/5m/15m).
	 */
	public static function handle( int $logId ): void
	{
		$row = WebhookLogRepo::find( $logId );

		// AC-3: row absent (deleted between schedule + run, or invalid
		// log_id). Action-Scheduler retry would be counterproductive —
		// the row will not magically reappear.
		if ( null === $row ) {
			self::log(
				'warning',
				sprintf( 'process_webhook_event: log_id=%d not found', $logId )
			);
			return;
		}

		$payloadJson = isset( $row['payload'] ) && is_string( $row['payload'] ) ? $row['payload'] : '';
		$decoded     = json_decode( $payloadJson, true );

		// AC-9: malformed JSON OR decoded array without `eventType` collapses
		// to a single permanent-error path. We treat a `null` decode-result
		// as malformed, even though strict JSON `null` would also produce
		// `null` — webhook payloads are always JSON objects, so the
		// false-positive risk is zero.
		if ( ! is_array( $decoded ) || ! isset( $decoded['eventType'] ) || ! is_string( $decoded['eventType'] ) ) {
			$preview = mb_substr( $payloadJson, 0, 200 );
			self::log(
				'warning',
				sprintf(
					'invalid payload log_id=%d payload_preview=%s',
					$logId,
					$preview
				)
			);
			WebhookLogRepo::updateProcessingStatus(
				$logId,
				WebhookLogRepo::STATUS_ERROR,
				self::ERROR_INVALID_PAYLOAD
			);
			return;
		}

		$eventType = $decoded['eventType'];

		// AC-5: unknown-prefix is NOT an exception — it is a missing
		// match. Detect it before the try/catch chain so we can write
		// the canonical `unknown_event_type` marker to the row without
		// going through the generic-Throwable arm. The three known
		// prefixes are disjoint per architecture.md Z. 175 so a single
		// pre-check is sufficient.
		if (
			! str_starts_with( $eventType, 'Order.' )
			&& ! str_starts_with( $eventType, 'Article.' )
			&& ! str_starts_with( $eventType, 'Shipment.' )
		) {
			WebhookLogRepo::updateProcessingStatus(
				$logId,
				WebhookLogRepo::STATUS_ERROR,
				self::ERROR_UNKNOWN_EVENT_TYPE
			);
			self::log(
				'error',
				sprintf( 'unknown event_type=%s log_id=%d', $eventType, $logId )
			);
			return;
		}

		// AC-4: prefix-match dispatch. Order matters only for readability —
		// the three prefixes are disjoint, so any ordering would produce
		// the same result. `str_starts_with` is PHP-8 native.
		try {
			self::dispatch( $eventType, $decoded );
		} catch ( SpreadconnectTransientError $e ) {
			// AC-7: transient-path. Persist the error message for log-UI
			// visibility and re-throw so AS triggers its 1m/5m/15m retry
			// cascade. The slice-37 `RetryPolicyListener` is responsible
			// for the after-3rd-retry FailedOps insert; this slice does
			// NOT call the record-stub on the transient path.
			WebhookLogRepo::updateProcessingStatus(
				$logId,
				WebhookLogRepo::STATUS_ERROR,
				self::truncateError( $e->getMessage() )
			);
			self::log(
				'warning',
				sprintf(
					'process_webhook_event: transient error log_id=%d event_type=%s message=%s',
					$logId,
					$eventType,
					$e->getMessage()
				)
			);
			throw $e;
		} catch ( SpreadconnectClientError $e ) {
			// AC-6: permanent client-error path (4xx, validation, ...).
			// Catch BEFORE generic Throwable so the more-specific subclass
			// wins. Record-stub + status update; do NOT re-throw.
			self::handlePermanentFailure( $logId, $eventType, $decoded, $e );
			return;
		} catch ( Throwable $e ) {
			// AC-6: generic permanent failure (programmer error, unexpected
			// exception). Same record-stub + status update path as the
			// 4xx case — Action-Scheduler must not retry.
			self::handlePermanentFailure( $logId, $eventType, $decoded, $e );
			return;
		}

		// AC-2: happy-path. Handler returned cleanly ⇒ flip the row to
		// `success`, clear any prior `processing_error` (re-runs of an
		// already-`error` row may flip back to `success` per AC-8).
		WebhookLogRepo::updateProcessingStatus(
			$logId,
			WebhookLogRepo::STATUS_SUCCESS,
			null
		);
	}

	/**
	 * Prefix-match the `eventType` against the three known domain
	 * surfaces and invoke the matching handler (slice-17 AC-4).
	 *
	 * Mapping (architecture.md Z. 175 + Z. 381-382):
	 *   - `Order.*`    → {@see OrderEventHandler::handle()}
	 *   - `Article.*`  → {@see ArticleEventHandler::handle()}
	 *   - `Shipment.*` → {@see OrderEventHandler::handle()} (architecture
	 *                    Z. 381 — Order-domain handler also owns
	 *                    `Shipment.sent`).
	 *
	 * Anything else triggers the `unknown_event_type` permanent-error
	 * path (AC-5).
	 *
	 * @param string               $eventType Already-validated string
	 *                                        `eventType` value.
	 * @param array<string, mixed> $payload   Decoded payload passed to
	 *                                        the matched handler verbatim.
	 *
	 * @return void
	 *
	 * @throws SpreadconnectTransientError Propagated from the domain
	 *                                     handler unchanged.
	 * @throws SpreadconnectClientError    Propagated from the domain
	 *                                     handler unchanged.
	 * @throws \Throwable                  Propagated from the domain
	 *                                     handler unchanged.
	 */
	private static function dispatch( string $eventType, array $payload ): void
	{
		if ( str_starts_with( $eventType, 'Order.' ) ) {
			OrderEventHandler::handle( $payload );
			return;
		}

		if ( str_starts_with( $eventType, 'Article.' ) ) {
			ArticleEventHandler::handle( $payload );
			return;
		}

		if ( str_starts_with( $eventType, 'Shipment.' ) ) {
			// Architecture.md Z. 381: Shipment.* shares the Order-domain
			// handler — there is no separate ShipmentEventHandler class.
			OrderEventHandler::handle( $payload );
			return;
		}

		// Defence-in-depth: the caller in {@see self::handle()} already
		// rejects unknown prefixes via the AC-5 pre-check, so this
		// branch is unreachable on the production path. We still emit a
		// no-op return to keep the method total.
	}

	/**
	 * Apply the AC-6 permanent-failure side-effects (status update,
	 * dispatcher log entry, record-stub call).
	 *
	 * Centralised so both the 4xx-catch arm and the generic-Throwable
	 * arm produce identical observable outcomes. The only path that
	 * deviates is the transient-error arm (which re-throws after the
	 * status update — see {@see self::handle()}).
	 *
	 * @param int                  $logId     Primary-key of the row to mark
	 *                                        as failed.
	 * @param string               $eventType `eventType` from the payload
	 *                                        (used for log-context).
	 * @param array<string, mixed> $payload   Decoded payload (forwarded to
	 *                                        the record-stub).
	 * @param \Throwable           $e         Caught exception.
	 */
	private static function handlePermanentFailure(
		int $logId,
		string $eventType,
		array $payload,
		Throwable $e
	): void {
		WebhookLogRepo::updateProcessingStatus(
			$logId,
			WebhookLogRepo::STATUS_ERROR,
			self::truncateError( $e->getMessage() )
		);

		self::logWithContext(
			'error',
			sprintf(
				'process_webhook_event: handler error log_id=%d event_type=%s message=%s',
				$logId,
				$eventType,
				$e->getMessage()
			),
			array(
				'source'     => self::LOG_SOURCE,
				'log_id'     => $logId,
				'event_type' => $eventType,
				'exception'  => $e::class,
			)
		);

		self::recordFailedOp( self::OP_TYPE_HANDLE_WEBHOOK, $payload, $e );
	}

	/**
	 * Slice-37 placeholder for `Failure\FailedOpsRepo::record()`.
	 *
	 * Logging-only stub: emits a single `error`-level log entry tagged
	 * with source `spreadconnect-failure` and the `op_type` /
	 * exception-class context. Slice 37 replaces the body with a real
	 * DB insert into `wp_spreadconnect_failed_ops` plus the
	 * {@see \SpreadconnectPod\Failure\FailureNotifier::dispatch()} fan-out.
	 *
	 * Method signature is contract-bound (slice-17 Integration Contract);
	 * slice-37 must keep `$opType` / `$payload` / `$error` intact so the
	 * call sites in this class do not need to change.
	 *
	 * @param string               $opType  Operation marker — slice-17
	 *                                      always passes
	 *                                      {@see self::OP_TYPE_HANDLE_WEBHOOK}.
	 * @param array<string, mixed> $payload Decoded webhook payload (kept
	 *                                      for slice-37's row insert).
	 * @param \Throwable           $error   Caught exception (kept for
	 *                                      slice-37's `error_message`
	 *                                      column).
	 *
	 * @return void
	 */
	private static function recordFailedOp( string $opType, array $payload, Throwable $error ): void
	{
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		if ( ! is_object( $logger ) || ! method_exists( $logger, 'error' ) ) {
			return;
		}

		$eventType = isset( $payload['eventType'] ) && is_string( $payload['eventType'] )
			? $payload['eventType']
			: '';

		$logger->error(
			sprintf(
				'recordFailedOp stub: op_type=%s event_type=%s exception=%s message=%s',
				$opType,
				$eventType,
				$error::class,
				$error->getMessage()
			),
			array(
				'source'     => self::FAILURE_LOG_SOURCE,
				'op_type'    => $opType,
				'event_type' => $eventType,
				'exception'  => $error::class,
			)
		);
	}

	/**
	 * UTF-8-safe truncation to the `processing_error TEXT`-column ceiling
	 * (65 535 bytes per slice-04 AC-2). `mb_substr` operates on
	 * characters, so the result may still exceed 65 535 *bytes* when the
	 * input is multi-byte-heavy, but the practical risk is negligible
	 * for exception messages.
	 */
	private static function truncateError( string $message ): string
	{
		if ( strlen( $message ) <= self::PROCESSING_ERROR_MAX_BYTES ) {
			return $message;
		}

		return mb_substr( $message, 0, self::PROCESSING_ERROR_MAX_BYTES );
	}

	/**
	 * Resolve `wc_get_logger()` and emit a single entry with `source`
	 * only.
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
