<?php
/**
 * REST controller for `GET /wp-json/spreadconnect/v1/sync-progress` (slice-26).
 *
 * Read-only diagnostics endpoint consumed by the Catalog sub-page's 3 s
 * AJAX-poll (architecture.md Z. 132 + Z. 517). Returns a fixed 10-key JSON
 * shape describing one sync-run's live progress; the route is
 * capability-gated on `manage_woocommerce` via `permission_callback` and is
 * NOT nonce-gated (architecture.md Z. 484: "Read-only AJAX requires
 * capability only (no nonce)").
 *
 * Resolution rules (slice-26 AC-7/8/9):
 *
 *   - Explicit `?run_id=NNN`:
 *       * row exists → 200 with the live-progress body.
 *       * row missing → 404 `sync_run_not_found` (NOT a fallback to active).
 *   - No `run_id` parameter (default = active run, architecture.md Z. 132):
 *       * youngest row in `state IN ('in_progress','pending')` → 200.
 *       * else youngest finished row → 200.
 *       * empty table → 200 with synthetic `state='idle'` body so the
 *         frontend poller never sees a non-200 from the default case.
 *
 * Response body shape (10 keys, ordered as in architecture.md Z. 132):
 *   `{run_id, state, started_at, processed, total, created, updated,
 *     skipped, errors, last_log_lines}`
 *
 * `processed` is computed as `created + updated + skipped + errors` — never
 * served from a separate column (the schema does not carry one).
 *
 * @package SpreadconnectPod\Hub\Rest
 */

declare(strict_types=1);

namespace SpreadconnectPod\Hub\Rest;

use SpreadconnectPod\Catalog\SyncHistoryRepo;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Front-controller for the sync-progress REST route.
 *
 * Final per slice-26 Constraints. `register()` is `static` (it is the hook
 * registration entry-point invoked from `Bootstrap\Plugin::init()` on
 * `rest_api_init`); `permission()` and `handle()` are instance methods so
 * that the {@see SyncHistoryRepo} collaborator can be supplied through
 * constructor DI for unit tests. The static `register()` factory wires a
 * production-default repo and bridges to the instance methods.
 */
final class SyncProgress
{
	/**
	 * REST namespace for the route — shared with the slice-15 webhook
	 * controller (architecture.md Z. 132 + Z. 528).
	 */
	public const ROUTE_NAMESPACE = 'spreadconnect/v1';

	/**
	 * REST route path appended to {@see self::ROUTE_NAMESPACE}.
	 */
	public const ROUTE_PATH = '/sync-progress';

	/**
	 * Capability required for both browse and JSON read-back.
	 *
	 * Mirrors `Hub\Controller::REQUIRED_CAP` and architecture.md Z. 132
	 * ("`manage_woocommerce` capability check via `permission_callback`").
	 */
	private const REQUIRED_CAP = 'manage_woocommerce';

	/**
	 * Plugin text-domain for the 404 error message.
	 */
	private const TEXT_DOMAIN = 'spreadconnect-pod';

	/**
	 * Sync-history-row state enum — duplicated from {@see SyncHistoryRepo}
	 * because that class keeps its constants `private`. The value strings
	 * are the canonical `wp_spreadconnect_sync_history.state` values.
	 */
	private const STATE_IDLE = 'idle';

	/**
	 * History-row repository used for read-back.
	 *
	 * Constructor-injected so unit tests can supply a Mockery double.
	 * Production callers reach the class through {@see self::register()},
	 * which constructs a default-DI repo internally.
	 */
	private SyncHistoryRepo $historyRepo;

	/**
	 * @param SyncHistoryRepo $historyRepo History-row repository.
	 */
	public function __construct( SyncHistoryRepo $historyRepo )
	{
		$this->historyRepo = $historyRepo;
	}

	/**
	 * Register the REST route on `rest_api_init`.
	 *
	 * Called from {@see \SpreadconnectPod\Bootstrap\Plugin::init()} via
	 * `add_action( 'rest_api_init', [ self::class, 'register' ] )`. The
	 * route is registered with `methods=GET`, `callback=$instance->handle`,
	 * `permission_callback=$instance->permission`. Both callbacks are bound
	 * to the same singleton instance so the `SyncHistoryRepo` collaborator
	 * is shared across permission + handler invocations within a request.
	 *
	 * @return void
	 */
	public static function register(): void
	{
		$instance = new self( new SyncHistoryRepo() );

		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE_PATH,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $instance, 'handle' ),
				'permission_callback' => array( $instance, 'permission' ),
				'args'                => array(
					'run_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Capability check (slice-26 AC-10).
	 *
	 * Returns `true` when the current user has `manage_woocommerce`,
	 * `false` otherwise. WP turns a `false` return into a 401 response
	 * (`rest_forbidden` with status 401) without invoking {@see self::handle()}.
	 *
	 * No nonce verification — REST reads are capability-gated only
	 * (architecture.md Z. 484).
	 *
	 * @param WP_REST_Request $request Unused; signature mandated by WP REST.
	 *
	 * @return bool
	 */
	public function permission( WP_REST_Request $request ): bool // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	{
		return current_user_can( self::REQUIRED_CAP );
	}

	/**
	 * Handle a single `GET /sync-progress` request.
	 *
	 * Resolution order:
	 *   1. Read `?run_id=` and `absint()` it. Value `> 0` → look up the
	 *      specific row via `SyncHistoryRepo::getById()`. Missing row →
	 *      404 `sync_run_not_found` (slice-26 AC-9).
	 *   2. Otherwise resolve the default: active run via
	 *      `SyncHistoryRepo::getActiveRun()`; if absent fall back to the
	 *      youngest finished row via `SyncHistoryRepo::getRecent(1)`.
	 *   3. Empty table altogether → synthetic `state='idle'` body with
	 *      HTTP 200 (slice-26 AC-8: poller must not hang on 404).
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return WP_REST_Response|WP_Error 200 JSON body, or 404 `WP_Error`
	 *                                   for the explicit-run-not-found case.
	 */
	public function handle( WP_REST_Request $request )
	{
		$runIdParam = $request->get_param( 'run_id' );
		$requested  = is_numeric( $runIdParam ) ? (int) $runIdParam : 0;

		if ( $requested > 0 ) {
			$row = $this->historyRepo->getById( $requested );

			if ( null === $row ) {
				return new WP_Error(
					'sync_run_not_found',
					sprintf(
						/* translators: %d is the requested sync-history run id. */
						__( 'No sync run found for run_id %d.', self::TEXT_DOMAIN ),
						$requested
					),
					array( 'status' => 404 )
				);
			}

			return new WP_REST_Response( $this->buildBody( $row ), 200 );
		}

		// AC-8: default path — youngest active run, else youngest finished
		// row, else synthetic idle body.
		$row = $this->historyRepo->getActiveRun();

		if ( null === $row ) {
			$recent = $this->historyRepo->getRecent( 1 );
			$row    = isset( $recent[0] ) && is_array( $recent[0] ) ? $recent[0] : null;
		}

		if ( null === $row ) {
			return new WP_REST_Response( $this->buildIdleBody(), 200 );
		}

		return new WP_REST_Response( $this->buildBody( $row ), 200 );
	}

	/**
	 * Compose the 10-key progress body from a sync-history row.
	 *
	 * Architecture.md Z. 132 mandates the exact key set + names. Counter
	 * columns may arrive as numeric strings from `$wpdb` — coerce all
	 * fields explicitly so the JSON envelope matches the contract.
	 *
	 * @param array<string, mixed> $row Sync-history row.
	 *
	 * @return array<string, mixed> JSON body.
	 */
	private function buildBody( array $row ): array
	{
		$runId   = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$state   = isset( $row['state'] ) && is_string( $row['state'] ) ? $row['state'] : '';
		$started = isset( $row['started_at'] ) && is_string( $row['started_at'] ) ? $row['started_at'] : '';

		$created = isset( $row['created_count'] ) ? (int) $row['created_count'] : 0;
		$updated = isset( $row['updated_count'] ) ? (int) $row['updated_count'] : 0;
		$skipped = isset( $row['skipped_count'] ) ? (int) $row['skipped_count'] : 0;
		$errors  = isset( $row['error_count'] ) ? (int) $row['error_count'] : 0;

		$processed = $created + $updated + $skipped + $errors;
		$total     = $runId > 0 ? $this->historyRepo->getTotal( $runId ) : 0;
		$logTail   = $runId > 0 ? $this->historyRepo->getLogTail( $runId ) : array();

		return array(
			'run_id'         => $runId,
			'state'          => $state,
			'started_at'     => $started,
			'processed'      => $processed,
			'total'          => $total,
			'created'        => $created,
			'updated'        => $updated,
			'skipped'        => $skipped,
			'errors'         => $errors,
			'last_log_lines' => $logTail,
		);
	}

	/**
	 * Synthetic body returned when the history table is empty (AC-8).
	 *
	 * Same 10-key shape as {@see self::buildBody()} so the frontend poller
	 * does not need a separate code-path for the empty case.
	 *
	 * @return array<string, mixed>
	 */
	private function buildIdleBody(): array
	{
		return array(
			'run_id'         => null,
			'state'          => self::STATE_IDLE,
			'started_at'     => '',
			'processed'      => 0,
			'total'          => 0,
			'created'        => 0,
			'updated'        => 0,
			'skipped'        => 0,
			'errors'         => 0,
			'last_log_lines' => array(),
		);
	}
}
