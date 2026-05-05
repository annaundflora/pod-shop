<?php
/**
 * Failure\BulkResendCoordinator (slice-33 + slice-40).
 *
 * Service that backs the `Re-send to Spreadconnect` bulk-action on the
 * WC-Order-List screen (HPOS + legacy) and the Bulk-Resend / Bulk-Dismiss
 * AJAX-Handler on the Failed-Ops sub-page.
 *
 *   - {@see self::preflight()}: pure-read classification of the selected
 *     order-ids into eligible (`failed_to_submit`) vs. skipped buckets.
 *     Zero side-effects on the WC-Order side. Slice-40 ergaenzt einen
 *     additiven `eligible_ids_with_op_id`-Lookup ueber {@see FailedOpsRepo}
 *     (Defensive-Default `null`, kein Re-Throw bei Repo-Failures).
 *
 *   - {@see self::run()}: schedules `spreadconnect/create_order` per eligible
 *     Order-ID via `as_enqueue_async_action()`. KEIN `markResolved()` —
 *     der Resolve passiert beim erfolgreichen Re-Run im `OrderSubmitJob`.
 *
 *   - {@see self::resendFailedOps()}: schedules den Op-Type-spezifischen
 *     AS-Hook fuer jede `failed_op`-Row (Op-Type-zu-Hook-Mapping identisch
 *     zu Slice 38) und markiert die Row sofort `resolved` (Admin hat
 *     explizit den Bulk gestartet).
 *
 *   - {@see self::dismissFailedOps()}: refused mit `code=create_order_in_selection`
 *     wenn mindestens eine `create_order`-Row in der Selection ist; sonst
 *     `markDismissed()` pro Row.
 *
 *   - AJAX-Adapter {@see self::handleBulkResendAjax()} +
 *     {@see self::handleBulkDismissAjax()} fuer die zwei NEUEN
 *     `wp_ajax_spreadconnect_bulk_*_failed_op`-Hooks (Slice 40 AC-9 / AC-10).
 *
 * Architecture references:
 *   - architecture.md "Service Map" Z. 391 — `Failure\BulkResendCoordinator`.
 *   - architecture.md Z. 309-311 — Order-Meta keys consumed.
 *   - slice-33 AC-10 / AC-11 / AC-12 / AC-13.
 *   - slice-40 AC-1..AC-15.
 *
 * @package SpreadconnectPod\Failure
 */

declare(strict_types=1);

namespace SpreadconnectPod\Failure;

use SpreadconnectPod\Logging\Sources;
use Throwable;
use WC_Order;

/**
 * Stateless coordinator for the bulk-resend pre-flight + run surface.
 *
 * `final` per slice-33 Constraints. Konstruktor-DI ist optional
 * (`?FailedOpsRepo`, `?\WC_Logger`) — Slice-33-Tests bleiben rueckwaertskompatibel.
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
	 * banner (slice-33 AC-10) and the slice-40 outcome panel.
	 */
	public const SKIP_NOT_FAILED      = 'not_failed';
	public const SKIP_NEVER_SUBMITTED = 'never_submitted';
	public const SKIP_ORDER_MISSING   = 'order_missing';

	/**
	 * Per-row outcome literals emitted by {@see self::run()} +
	 * {@see self::resendFailedOps()} (slice-40 AC-2 / AC-5).
	 */
	public const PER_ROW_REQUEUED        = 'requeued';
	public const PER_ROW_ROW_MISSING     = 'row_missing';
	public const PER_ROW_UNKNOWN_OP_TYPE = 'unknown_op_type';

	/**
	 * Dismiss-Refusal-Code (slice-40 AC-7 / Discovery Z. 640).
	 */
	public const DISMISS_REFUSED_CODE = 'create_order_in_selection';

	/**
	 * Slice-33-Backwards-Compat: deferred marker. Aktive Slice-33-Tests
	 * referenzieren den Wert nicht (Slice 40 ersetzt den Stub-Body), aber
	 * die Konstante bleibt fuer Drittkonsumenten in der Public API.
	 */
	public const PER_ROW_DEFERRED = 'deferred_to_slice_40';

	/**
	 * AJAX-Nonce-Action (wiederverwendet aus Slice 38 — Single Source of
	 * Truth fuer alle Failed-Ops-AJAX-Hooks).
	 */
	public const NONCE_ACTION = 'spreadconnect_failed_ops';

	/**
	 * AJAX-Nonce-POST-Field (Slice 38 Convention — JS-localized als `nonce`,
	 * aber `_ajax_nonce` ist der Default fuer `check_ajax_referer()`).
	 * Wir akzeptieren BEIDE Feldnamen, damit Bulk-AJAX kompatibel bleibt
	 * mit der Slice-38-Localization (`spreadconnectFailedOpsBulk.nonce`).
	 */
	private const NONCE_FIELD_PRIMARY  = '_ajax_nonce';
	private const NONCE_FIELD_FALLBACK = 'nonce';

	/**
	 * Capability gate (identisch Slice 38).
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Plugin text-domain.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Action-Scheduler group (architecture.md Z. 542).
	 */
	private const AS_GROUP = 'spreadconnect';

	/**
	 * AS-Hook-String fuer den Order-List-Bulk-Pfad (Slice 28 AC-9 mirror).
	 */
	private const HOOK_CREATE_ORDER = 'spreadconnect/create_order';

	/**
	 * Op-Type → Action-Scheduler hook mapping (slice-37 AC-9 inverted, all
	 * 9 entries — identisch zu {@see \SpreadconnectPod\Hub\Ajax\FailedOpsActions::OP_TYPE_TO_HOOK}).
	 *
	 * @var array<string, string>
	 */
	private const OP_TYPE_TO_HOOK = array(
		'create_order'           => 'spreadconnect/create_order',
		'confirm_order'          => 'spreadconnect/confirm_order',
		'cancel_order_mirror'    => 'spreadconnect/cancel_order_mirror',
		'fetch_tracking'         => 'spreadconnect/fetch_tracking',
		'sync_article'           => 'spreadconnect/sync_article',
		'sync_catalog'           => 'spreadconnect/sync_catalog',
		'handle_article_removed' => 'spreadconnect/handle_article_removed',
		'handle_webhook'         => 'spreadconnect/process_webhook_event',
		'scheduled_stock_sync'   => 'spreadconnect/scheduled_stock_sync',
	);

	/**
	 * Optional FailedOpsRepo-DI (slice-37 producer; slice-40 consumer).
	 * `null` haelt Slice-33-Tests (die ohne `$wpdb` arbeiten) gruen.
	 */
	private ?FailedOpsRepo $repo;

	/**
	 * Optional WC-Logger-Override (Slice 39 / Slice 42 verwenden den
	 * Adapter; dieser Konstruktor-Slot bleibt fuer zukuenftige Sinks).
	 */
	private ?\WC_Logger $logger;

	/**
	 * @param FailedOpsRepo|null $repo   Optional repo (slice-40 prod-wiring injects this lazily).
	 * @param \WC_Logger|null    $logger Optional WC-Logger override.
	 */
	public function __construct( ?FailedOpsRepo $repo = null, ?\WC_Logger $logger = null )
	{
		$this->repo   = $repo;
		$this->logger = $logger;
	}

	// ------------------------------------------------------------------
	// AC-1: pre-flight pure-read classification + failed_op_id lookup
	// ------------------------------------------------------------------

	/**
	 * Classify a selection of WC-Order ids into eligible vs. skipped.
	 *
	 * Side-effect-free: nur `wc_get_order($id)->get_meta(...)` und
	 * (additiv, slice-40) `FailedOpsRepo::findByEntity('order', ...)`.
	 *
	 * Slice-33-Vertrag (`will_resend`/`will_skip`/`eligible_ids`/`skipped`)
	 * bleibt unveraendert; nur der NEUE Schluessel `eligible_ids_with_op_id`
	 * ist additiv. Bei `findByEntity`-Throw -> Defensive-Default `null`,
	 * kein Re-Throw.
	 *
	 * @param int[] $order_ids  WC-Order ids selected in the bulk-checkbox UI.
	 *
	 * @return array{
	 *     will_resend:int,
	 *     will_skip:int,
	 *     eligible_ids:int[],
	 *     skipped:array<int,string>,
	 *     eligible_ids_with_op_id:array<int,?int>
	 * }
	 */
	public function preflight( array $order_ids ): array
	{
		$eligible           = array();
		$skipped            = array();
		$eligibleWithOpId   = array();

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

			$eligible[]               = $id;
			$eligibleWithOpId[ $id ] = $this->lookupFailedOpIdForOrder( $id );
		}

		return array(
			'will_resend'             => count( $eligible ),
			'will_skip'               => count( $skipped ),
			'eligible_ids'            => array_values( $eligible ),
			'skipped'                 => $skipped,
			'eligible_ids_with_op_id' => $eligibleWithOpId,
		);
	}

	/**
	 * Defensive lookup of the latest unresolved `failed_op` row for an
	 * order — returns `null` when (a) no repo was injected, (b) `findByEntity`
	 * yields `[]`, or (c) `findByEntity` throws (slice-40 AC-1 last clause).
	 */
	private function lookupFailedOpIdForOrder( int $orderId ): ?int
	{
		if ( null === $this->repo ) {
			return null;
		}

		try {
			$rows = $this->repo->findByEntity(
				'order',
				(string) $orderId,
				FailedOpsRepo::STATE_UNRESOLVED
			);
		} catch ( Throwable $e ) {
			return null;
		}

		if ( ! is_array( $rows ) || array() === $rows ) {
			return null;
		}

		$first = $rows[0];
		if ( ! is_array( $first ) || ! isset( $first['id'] ) ) {
			return null;
		}

		$id = (int) $first['id'];
		return $id > 0 ? $id : null;
	}

	// ------------------------------------------------------------------
	// AC-2..AC-4: Order-List Bulk-Resend run() — echte AS-Schedules
	// ------------------------------------------------------------------

	/**
	 * Schedule `spreadconnect/create_order` per eligible Order-ID. KEIN
	 * `markResolved()` — der Resolve passiert erst beim erfolgreichen Re-Run
	 * im `OrderSubmitJob` (Slice 28 schreibt bei 2xx den State `NEW`).
	 *
	 * Returntyp: `{queued, skipped, run_id, per_row}` —
	 * `per_row[$id]='requeued'` fuer eligible, sonst der Skip-Reason aus
	 * `preflight()`. `run_id` = `wp_generate_uuid4()`-Result.
	 *
	 * Try-Catch um den Body — bei `\Throwable`-Fehler wird der
	 * Defensive-Default zurueckgegeben (slice-40 Constraints —
	 * "UI darf nicht crashen").
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
		$runId = self::generateRunId();

		try {
			$preflight = $this->preflight( $order_ids );

			$perRow  = array();
			$queued  = 0;
			$skipped = (int) ( $preflight['will_skip'] ?? 0 );

			foreach ( $preflight['eligible_ids'] as $id ) {
				$intId = (int) $id;
				if ( $intId <= 0 ) {
					continue;
				}

				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action(
						self::HOOK_CREATE_ORDER,
						array( 'order_id' => $intId ),
						self::AS_GROUP
					);
				}

				$perRow[ $intId ] = self::PER_ROW_REQUEUED;
				$queued++;
			}

			foreach ( $preflight['skipped'] as $skipId => $reason ) {
				$perRow[ (int) $skipId ] = is_string( $reason ) ? $reason : self::SKIP_NOT_FAILED;
			}

			$this->logRunSummary( $runId, $queued, $skipped, $perRow );

			return array(
				'queued'  => $queued,
				'skipped' => $skipped,
				'run_id'  => $runId,
				'per_row' => $perRow,
			);
		} catch ( Throwable $e ) {
			$this->logException( 'bulk_resend_run_failed', $runId, $e );

			return array(
				'queued'  => 0,
				'skipped' => count( $order_ids ),
				'run_id'  => $runId,
				'per_row' => array(),
			);
		}
	}

	// ------------------------------------------------------------------
	// AC-5..AC-6: Failed-Ops-UI Bulk-Resend
	// ------------------------------------------------------------------

	/**
	 * Bulk-Resend ueber `failed_op_ids` (NICHT Order-IDs). Pro Row:
	 *   1. `findById($id)` — `null` -> `per_row='row_missing'`, skip.
	 *   2. Op-Type-zu-Hook-Lookup — unknown -> `per_row='unknown_op_type'`, skip.
	 *   3. `as_enqueue_async_action($hook, $payload, 'spreadconnect')`.
	 *   4. `markResolved($id)` — Admin hat explizit den Bulk gestartet.
	 *
	 * Try-Catch um den Body — Defensive-Default-Return bei `\Throwable`.
	 *
	 * @param int[] $failed_op_ids
	 *
	 * @return array{
	 *     queued:int,
	 *     skipped:int,
	 *     run_id:string,
	 *     per_row:array<int,string>
	 * }
	 */
	public function resendFailedOps( array $failed_op_ids ): array
	{
		$runId = self::generateRunId();

		try {
			$perRow  = array();
			$queued  = 0;
			$skipped = 0;

			foreach ( $failed_op_ids as $rawId ) {
				$id = (int) $rawId;
				if ( $id <= 0 ) {
					continue;
				}

				$row = null !== $this->repo ? $this->repo->findById( $id ) : null;
				if ( null === $row || ! is_array( $row ) ) {
					$perRow[ $id ] = self::PER_ROW_ROW_MISSING;
					$skipped++;
					continue;
				}

				$opType  = isset( $row['op_type'] ) && is_string( $row['op_type'] ) ? $row['op_type'] : '';
				$payload = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();

				if ( ! isset( self::OP_TYPE_TO_HOOK[ $opType ] ) ) {
					$perRow[ $id ] = self::PER_ROW_UNKNOWN_OP_TYPE;
					$skipped++;
					continue;
				}

				$hook = self::OP_TYPE_TO_HOOK[ $opType ];

				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action( $hook, $payload, self::AS_GROUP );
				}

				if ( null !== $this->repo ) {
					$this->repo->markResolved( $id );
				}

				$perRow[ $id ] = self::PER_ROW_REQUEUED;
				$queued++;
			}

			$this->logBulkResendFailedOps( $runId, $queued, $skipped, $perRow );

			return array(
				'queued'  => $queued,
				'skipped' => $skipped,
				'run_id'  => $runId,
				'per_row' => $perRow,
			);
		} catch ( Throwable $e ) {
			$this->logException( 'bulk_resend_failed_ops_failed', $runId, $e );

			return array(
				'queued'  => 0,
				'skipped' => count( $failed_op_ids ),
				'run_id'  => $runId,
				'per_row' => array(),
			);
		}
	}

	// ------------------------------------------------------------------
	// AC-7..AC-8: Failed-Ops-UI Bulk-Dismiss (mit Per-Op-Type-Regel)
	// ------------------------------------------------------------------

	/**
	 * Bulk-Dismiss mit Per-Op-Type-Regel: wenn mind. eine Row in der
	 * Selection `op_type='create_order'` hat -> `ok=false` mit
	 * `code=create_order_in_selection` + `blocked_ids[]`. Sonst `markDismissed`
	 * pro Row (Slice 38-Rule "Plain-Dismiss erlaubt fuer non-`create_order`").
	 *
	 * @param int[] $failed_op_ids
	 *
	 * @return array{
	 *     ok:bool,
	 *     code?:string,
	 *     dismissed:int,
	 *     blocked_ids:int[],
	 *     message?:string
	 * }
	 */
	public function dismissFailedOps( array $failed_op_ids ): array
	{
		try {
			$rowsByid    = array();
			$blockedIds  = array();

			foreach ( $failed_op_ids as $rawId ) {
				$id = (int) $rawId;
				if ( $id <= 0 ) {
					continue;
				}

				$row = null !== $this->repo ? $this->repo->findById( $id ) : null;
				if ( null === $row || ! is_array( $row ) ) {
					continue;
				}

				$opType = isset( $row['op_type'] ) && is_string( $row['op_type'] ) ? $row['op_type'] : '';
				if ( 'create_order' === $opType ) {
					$blockedIds[] = $id;
				}

				$rowsByid[ $id ] = $row;
			}

			if ( array() !== $blockedIds ) {
				$message = $this->buildBulkDismissBlockedMessage( $blockedIds );

				$this->logBulkDismissBlocked( $blockedIds );

				return array(
					'ok'          => false,
					'code'        => self::DISMISS_REFUSED_CODE,
					'dismissed'   => 0,
					'blocked_ids' => $blockedIds,
					'message'     => $message,
				);
			}

			$dismissed = 0;
			foreach ( $rowsByid as $id => $row ) {
				if ( null !== $this->repo && $this->repo->markDismissed( (int) $id ) ) {
					$dismissed++;
				}
			}

			return array(
				'ok'          => true,
				'dismissed'   => $dismissed,
				'blocked_ids' => array(),
			);
		} catch ( Throwable $e ) {
			$this->logException( 'bulk_dismiss_failed_ops_failed', '', $e );

			return array(
				'ok'          => false,
				'code'        => 'internal_error',
				'dismissed'   => 0,
				'blocked_ids' => array(),
				'message'     => '',
			);
		}
	}

	// ------------------------------------------------------------------
	// AC-9 / AC-10: AJAX-Adapter
	// ------------------------------------------------------------------

	/**
	 * AJAX-Handler `wp_ajax_spreadconnect_bulk_resend_failed_op` (slice-40 AC-9).
	 *
	 * Cap+Nonce+Param-Reihenfolge:
	 *   1. `check_ajax_referer('spreadconnect_failed_ops', $field, false)` -> 403.
	 *   2. `current_user_can('manage_woocommerce')`                         -> 403.
	 *   3. `failed_op_ids` non-empty `int[]`                                -> 400.
	 * Bei Pass: delegiert an {@see self::resendFailedOps()} und antwortet
	 * `wp_send_json_success(array_merge($result, ['banner' => ...]))`.
	 */
	public function handleBulkResendAjax(): void
	{
		if ( ! $this->checkNonceAndCap() ) {
			return;
		}

		$ids = $this->resolveFailedOpIds();
		if ( array() === $ids ) {
			$this->sendInvalidIds();
			return;
		}

		$total  = count( $ids );
		$result = $this->resendFailedOps( $ids );

		$queued  = (int) ( $result['queued'] ?? 0 );
		$skipped = (int) ( $result['skipped'] ?? 0 );

		$banner = sprintf(
			/* translators: 1: queued, 2: total, 3: skipped */
			_n(
				'%1$d of %2$d re-queued, %3$d skipped',
				'%1$d of %2$d re-queued, %3$d skipped',
				$total,
				self::TEXT_DOMAIN
			),
			$queued,
			$total,
			$skipped
		);

		wp_send_json_success( array_merge( $result, array( 'banner' => $banner ) ) );
	}

	/**
	 * AJAX-Handler `wp_ajax_spreadconnect_bulk_dismiss_failed_op` (slice-40 AC-10).
	 *
	 * Cap+Nonce+Param-Reihenfolge identisch {@see self::handleBulkResendAjax()}.
	 * Bei `ok=false` -> 422 mit `{code, blocked_ids, message}`. Bei `ok=true`
	 * -> `{dismissed, blocked_ids:[]}`.
	 */
	public function handleBulkDismissAjax(): void
	{
		if ( ! $this->checkNonceAndCap() ) {
			return;
		}

		$ids = $this->resolveFailedOpIds();
		if ( array() === $ids ) {
			$this->sendInvalidIds();
			return;
		}

		$result = $this->dismissFailedOps( $ids );

		if ( ! ( isset( $result['ok'] ) && true === $result['ok'] ) ) {
			$payload = array(
				'code'        => isset( $result['code'] ) && is_string( $result['code'] ) ? $result['code'] : self::DISMISS_REFUSED_CODE,
				'blocked_ids' => isset( $result['blocked_ids'] ) && is_array( $result['blocked_ids'] ) ? $result['blocked_ids'] : array(),
				'message'     => isset( $result['message'] ) && is_string( $result['message'] ) ? $result['message'] : '',
			);
			wp_send_json_error( $payload, 422 );
			return;
		}

		wp_send_json_success(
			array(
				'dismissed'   => isset( $result['dismissed'] ) ? (int) $result['dismissed'] : 0,
				'blocked_ids' => array(),
			)
		);
	}

	// ------------------------------------------------------------------
	// Internal helpers
	// ------------------------------------------------------------------

	/**
	 * Cap+Nonce-gate for the two bulk-AJAX handlers.
	 */
	private function checkNonceAndCap(): bool
	{
		$field = isset( $_POST[ self::NONCE_FIELD_PRIMARY ] )
			? self::NONCE_FIELD_PRIMARY
			: self::NONCE_FIELD_FALLBACK;

		if ( ! check_ajax_referer( self::NONCE_ACTION, $field, false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'invalid_nonce',
					'message' => __(
						'Security check failed. Please reload the page and try again.',
						self::TEXT_DOMAIN
					),
				),
				403
			);
			return false;
		}

		if ( ! current_user_can( self::REQUIRED_CAP ) ) {
			wp_send_json_error(
				array(
					'code'    => 'forbidden',
					'message' => __(
						'You do not have permission to perform this action.',
						self::TEXT_DOMAIN
					),
				),
				403
			);
			return false;
		}

		return true;
	}

	/**
	 * Sanitize `failed_op_ids` from POST -> `int[]` (>0). Identisch zu
	 * Slice 33 AC-10 Convention.
	 *
	 * @return int[]
	 */
	private function resolveFailedOpIds(): array
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Missing — nonce verified by checkNonceAndCap().
		if ( ! isset( $_POST['failed_op_ids'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = wp_unslash( $_POST['failed_op_ids'] );

		if ( is_string( $raw ) ) {
			// JSON-encoded array fallback (some jQuery serializers post nested arrays as JSON).
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} else {
				$raw = explode( ',', $raw );
			}
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array_map( 'intval', $raw );
		$ids = array_values(
			array_filter(
				$ids,
				static function ( $id ): bool {
					return is_int( $id ) && $id > 0;
				}
			)
		);

		return $ids;
	}

	/**
	 * Uniform 400 for empty / missing `failed_op_ids`.
	 */
	private function sendInvalidIds(): void
	{
		wp_send_json_error(
			array(
				'code'    => 'invalid_ids',
				'message' => __( 'No failed operation ids provided.', self::TEXT_DOMAIN ),
			),
			400
		);
	}

	/**
	 * Build the blocked-message for {@see self::dismissFailedOps()} —
	 * `_n()`-driven Plural-Form.
	 *
	 * @param int[] $blockedIds
	 */
	private function buildBulkDismissBlockedMessage( array $blockedIds ): string
	{
		$count = count( $blockedIds );

		return sprintf(
			/* translators: %d: number of create_order entries that require explicit resolution */
			_n(
				'%d create_order entry requires explicit resolution — open it individually.',
				'%d create_order entries require explicit resolution — open them individually.',
				$count,
				self::TEXT_DOMAIN
			),
			$count
		);
	}

	/**
	 * Generate a UUIDv4 correlation id via `wp_generate_uuid4()` (slice-40
	 * Constraints — KEIN `uniqid()`). Falls die WP-Funktion fehlt
	 * (Bootstrap-only-Tests), faellt der Generator auf `random_bytes` zurueck.
	 */
	private static function generateRunId(): string
	{
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			$value = wp_generate_uuid4();
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		try {
			$bytes  = random_bytes( 16 );
			$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
			$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
			$hex    = bin2hex( $bytes );
			return sprintf(
				'%s-%s-%s-%s-%s',
				substr( $hex, 0, 8 ),
				substr( $hex, 8, 4 ),
				substr( $hex, 12, 4 ),
				substr( $hex, 16, 4 ),
				substr( $hex, 20, 12 )
			);
		} catch ( Throwable $e ) {
			return substr( hash( 'sha256', (string) microtime( true ) ), 0, 36 );
		}
	}

	/**
	 * Strukturlog fuer `run()` — slice-40 AC-4. Pro skipped-Row KEIN
	 * Warning-Log (sonst Log-Spam bei grossen Selektionen).
	 *
	 * @param array<int,string> $perRow
	 */
	private function logRunSummary( string $runId, int $queued, int $skipped, array $perRow ): void
	{
		$summary = array();
		foreach ( $perRow as $marker ) {
			$summary[ $marker ] = ( $summary[ $marker ] ?? 0 ) + 1;
		}

		$context = array(
			'run_id'          => $runId,
			'queued'          => $queued,
			'skipped'         => $skipped,
			'per_row_summary' => $summary,
		);

		$this->safeLog( 'info', 'bulk_resend_run', $context );
	}

	/**
	 * Strukturlog fuer `resendFailedOps()`.
	 *
	 * @param array<int,string> $perRow
	 */
	private function logBulkResendFailedOps( string $runId, int $queued, int $skipped, array $perRow ): void
	{
		$summary = array();
		foreach ( $perRow as $marker ) {
			$summary[ $marker ] = ( $summary[ $marker ] ?? 0 ) + 1;
		}

		$context = array(
			'run_id'          => $runId,
			'queued'          => $queued,
			'skipped'         => $skipped,
			'per_row_summary' => $summary,
		);

		$this->safeLog( 'info', 'bulk_resend_failed_ops', $context );
	}

	/**
	 * Strukturlog fuer `dismissFailedOps()` Refusal — slice-40 AC-7.
	 *
	 * @param int[] $blockedIds
	 */
	private function logBulkDismissBlocked( array $blockedIds ): void
	{
		$this->safeLog(
			'warning',
			'bulk_dismiss_blocked',
			array(
				'blocked_ids' => $blockedIds,
				'count'       => count( $blockedIds ),
			)
		);
	}

	/**
	 * Strukturlog fuer Try-Catch-Branches.
	 */
	private function logException( string $tag, string $runId, Throwable $e ): void
	{
		$this->safeLog(
			'error',
			$tag,
			array(
				'run_id'  => $runId,
				'message' => $e->getMessage(),
			)
		);
	}

	/**
	 * Forward log entries to {@see \wc_get_logger()} with source
	 * {@see Sources::FAILURE}. Slice-40 AC-4 requires `wc_get_logger()->info()`
	 * (or `warning()`/`error()`) — call the WC logger directly so per-method
	 * intercepts in unit tests resolve naturally.
	 *
	 * Defensive: bei `wc_get_logger()`-Absence (Bootstrap-Test) wird der
	 * Eintrag verworfen.
	 *
	 * @param array<string, mixed> $context
	 */
	private function safeLog( string $level, string $tag, array $context ): void
	{
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		try {
			$logger = wc_get_logger();
		} catch ( Throwable $e ) {
			return;
		}

		if ( null === $logger || ! is_object( $logger ) ) {
			return;
		}

		$fullContext = array_merge( $context, array( 'source' => Sources::FAILURE, 'tag' => $tag ) );
		$message     = sprintf( '[%s]', $tag );

		try {
			switch ( $level ) {
				case 'warning':
					if ( method_exists( $logger, 'warning' ) ) {
						$logger->warning( $message, $fullContext );
					}
					return;
				case 'error':
					if ( method_exists( $logger, 'error' ) ) {
						$logger->error( $message, $fullContext );
					}
					return;
				case 'info':
				default:
					if ( method_exists( $logger, 'info' ) ) {
						$logger->info( $message, $fullContext );
					}
					return;
			}
		} catch ( Throwable $e ) {
			// Logging must never crash the coordinator.
		}
	}
}
