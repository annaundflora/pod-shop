<?php
/**
 * Failure\BulkResendCoordinator (slice-33).
 *
 * Service that backs the `Re-send to Spreadconnect` bulk-action on the
 * WC-Order-List screen (HPOS + legacy). Slice 33 ships ONLY the surface:
 *
 *   - {@see self::preflight()}: pure-read classification of the selected
 *     order-ids into eligible (`failed_to_submit`) vs. skipped buckets.
 *     Zero side-effects — no API calls, no AS schedules, no DB writes.
 *
 *   - {@see self::run()}: a Slice-40 deferred stub. Runs `preflight()`
 *     internally, returns `queued=0` plus a `per_row` map marking every
 *     eligible id as `'deferred_to_slice_40'`. NO `as_enqueue_async_action()`
 *     is invoked. Slice 40 will replace the body with the real per-row
 *     schedule path + `Failure\FailedOpsRepo` correlation.
 *
 * Architecture references:
 *   - architecture.md "Service Map" Z. 391 — `Failure\BulkResendCoordinator`.
 *   - architecture.md Z. 309-311 — Order-Meta keys consumed.
 *   - slice-33 AC-10 / AC-11 / AC-12 / AC-13.
 *
 * @package SpreadconnectPod\Failure
 */

declare(strict_types=1);

namespace SpreadconnectPod\Failure;

use WC_Order;

/**
 * Stateless coordinator for the bulk-resend pre-flight + run surface.
 *
 * `final` per slice-33 Constraints. The class is constructor-DI-free in
 * slice-33; slice-40 will introduce a `FailedOpsRepo` dependency, but the
 * `preflight()` / `run()` signatures remain stable.
 */
final class BulkResendCoordinator
{
	/**
	 * Order-Meta key holding the persistent SC-state (architecture.md
	 * Z. 309-311).
	 */
	private const META_STATE = '_spreadconnect_state';

	/**
	 * The single eligible state for a re-send (slice-33 AC-11 + Wireframe
	 * Screen 12 Z. 1037).
	 */
	private const STATE_FAILED_TO_SUBMIT = 'failed_to_submit';

	/**
	 * Skip reasons emitted by {@see self::preflight()}.
	 *
	 * The strings are stable identifiers consumed by the JS pre-flight
	 * banner (slice-33 AC-10) and (later) slice-40 outcome panel.
	 */
	public const SKIP_NOT_FAILED      = 'not_failed';
	public const SKIP_NEVER_SUBMITTED = 'never_submitted';
	public const SKIP_ORDER_MISSING   = 'order_missing';

	/**
	 * Slice-40 deferral marker emitted by {@see self::run()} for every
	 * eligible id. Slice 40 replaces this with the real per-row outcome
	 * literal (`'queued'` / `'failed'` / etc.).
	 */
	public const PER_ROW_DEFERRED = 'deferred_to_slice_40';

	/**
	 * Logger source (slice-33 Constraints — `'spreadconnect-failure'`).
	 */
	private const LOG_SOURCE = 'spreadconnect-failure';

	// ------------------------------------------------------------------
	// AC-11: pre-flight pure-read classification
	// ------------------------------------------------------------------

	/**
	 * Classify a selection of WC-Order ids into eligible vs. skipped.
	 *
	 * Side-effect-free: only `wc_get_order($id)->get_meta(...)` is called.
	 * NO `SpreadconnectClient` traffic, NO `as_enqueue_async_action()`, NO
	 * `FailedOpsRepo` writes (slice-33 AC-11).
	 *
	 * Skip reasons (slice-33 AC-10 + AC-11):
	 *   - `'order_missing'`    — `wc_get_order($id)` returned a non-`WC_Order`.
	 *   - `'never_submitted'`  — Order has no `_spreadconnect_state` meta
	 *                            (i.e. pseudo-state `pending`).
	 *   - `'not_failed'`       — Order has a state, but it is anything other
	 *                            than `failed_to_submit`.
	 *
	 * @param int[] $order_ids  WC-Order ids selected in the bulk-checkbox UI.
	 *
	 * @return array{
	 *     will_resend:int,
	 *     will_skip:int,
	 *     eligible_ids:int[],
	 *     skipped:array<int,string>
	 * }
	 */
	public function preflight( array $order_ids ): array
	{
		$eligible = array();
		$skipped  = array();

		foreach ( $order_ids as $rawId ) {
			$id = (int) $rawId;
			if ( $id <= 0 ) {
				continue;
			}

			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
			if ( ! ( $order instanceof WC_Order ) ) {
				$skipped[ $id ] = self::SKIP_ORDER_MISSING;
				continue;
			}

			$state = $order->get_meta( self::META_STATE );
			$state = is_string( $state ) ? $state : '';

			if ( '' === $state ) {
				$skipped[ $id ] = self::SKIP_NEVER_SUBMITTED;
				continue;
			}

			if ( self::STATE_FAILED_TO_SUBMIT !== $state ) {
				$skipped[ $id ] = self::SKIP_NOT_FAILED;
				continue;
			}

			$eligible[] = $id;
		}

		$result = array(
			'will_resend'  => count( $eligible ),
			'will_skip'    => count( $skipped ),
			'eligible_ids' => array_values( $eligible ),
			'skipped'      => $skipped,
		);

		$this->log(
			'bulk_resend_preflight',
			sprintf(
				'preflight: selected=%d eligible=%d skipped=%d',
				count( $order_ids ),
				$result['will_resend'],
				$result['will_skip']
			)
		);

		return $result;
	}

	// ------------------------------------------------------------------
	// AC-12: run-stub — slice-40 replaces this body with real schedules
	// ------------------------------------------------------------------

	/**
	 * Slice-33 stub: returns `queued=0` plus a `per_row` map marking every
	 * eligible id as `'deferred_to_slice_40'`. NO `as_enqueue_async_action()`
	 * is invoked. Slice 40 replaces this body with the real per-row
	 * schedule path + `Failure\FailedOpsRepo` correlation; the signature is
	 * stable.
	 *
	 * @param int[] $order_ids
	 *
	 * @return array{
	 *     queued:int,
	 *     skipped:int,
	 *     run_id:string,
	 *     per_row:array<int,string>
	 * }
	 */
	public function run( array $order_ids ): array
	{
		$preflight = $this->preflight( $order_ids );

		$perRow = array();
		foreach ( $preflight['eligible_ids'] as $id ) {
			$perRow[ (int) $id ] = self::PER_ROW_DEFERRED;
		}

		$runId = self::generateRunId();

		$this->log(
			'bulk_resend_run_stub',
			sprintf(
				'run-stub: run_id=%s eligible=%d (deferred_to_slice_40)',
				$runId,
				count( $preflight['eligible_ids'] )
			)
		);

		return array(
			'queued'  => 0,
			'skipped' => $preflight['will_skip'],
			'run_id'  => $runId,
			'per_row' => $perRow,
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Generate a 12-char hex correlation id for the outcome-panel banner.
	 *
	 * The id is opaque — slice-40 will reuse the same shape and persist it
	 * alongside the `wp_spreadconnect_failed_ops` rows so the banner
	 * "Show details" toggle can correlate per-row outcomes.
	 */
	private static function generateRunId(): string
	{
		try {
			return bin2hex( random_bytes( 6 ) );
		} catch ( \Throwable $e ) {
			// Fallback when CSPRNG is unavailable (e.g. broken openssl). The
			// run-id is non-cryptographic, so a microtime-based hash is fine.
			return substr( hash( 'sha256', (string) microtime( true ) . (string) wp_rand() ), 0, 12 );
		}
	}

	/**
	 * Forward log entries to `wc_get_logger()` with the
	 * `spreadconnect-failure` source. No-ops when the helper is not
	 * available (very old WC versions).
	 */
	private function log( string $tag, string $message ): void
	{
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		if ( null === $logger || ! is_object( $logger ) || ! method_exists( $logger, 'info' ) ) {
			return;
		}

		$logger->info(
			sprintf( '[%s] %s', $tag, $message ),
			array( 'source' => self::LOG_SOURCE )
		);
	}
}
