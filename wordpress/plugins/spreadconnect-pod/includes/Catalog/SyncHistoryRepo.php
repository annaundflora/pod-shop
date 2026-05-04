<?php
/**
 * Catalog-sync history repository — full lifecycle (slice-23 + slice-24).
 *
 * Owns read/write access to {@link wp_spreadconnect_sync_history} (schema:
 * see {@see \SpreadconnectPod\Bootstrap\Schema}). Slice 23 introduced the
 * append-only `appendDetail()` operation. Slice 24 extends the class with
 * the catalog-job lifecycle methods:
 *
 *   - `startRun()` — INSERT of a fresh `state='in_progress'` row, returning
 *     the auto-incremented `run_id`.
 *   - `setTotal()` — persist the per-run total article count via the
 *     `sc_sync_total_{run_id}` transient (24 h TTL — Constraints AC-3).
 *   - `incrementCreated|Updated|Skipped|Error()` — atomic single-statement
 *     `UPDATE` of the corresponding counter column (Constraints AC-7) plus a
 *     CAS-style state transition to `complete` once the counter sum reaches
 *     the persisted total.
 *   - `markComplete()` / `markFailed()` — terminal state transitions used
 *     for the empty-catalog short-circuit (AC-4) and the 4xx fail-fast path
 *     (AC-5) respectively.
 *
 * Implementation choices:
 *
 *   - Counters use `$wpdb->query( $wpdb->prepare( "UPDATE … SET col=col+1
 *     WHERE id=%d", … ) )` — single-statement increments avoid the
 *     read-modify-write race (Constraints AC-7).
 *
 *   - The completion check after each increment performs a CAS update
 *     (`UPDATE … SET state='complete', finished_at=… WHERE id=%d AND
 *     state='in_progress'`) so concurrent workers cannot double-flip the
 *     row.
 *
 *   - Total persistence uses transients rather than a dedicated column
 *     because the architecture-canonical schema does NOT carry a
 *     `total_count` field (architecture.md Z. 233-258). Transient TTL is
 *     24 h (`DAY_IN_SECONDS`) so an abandoned run cannot leak the value
 *     beyond a sensible window.
 *
 * Architecture: `architecture.md` -> "Database Schema" -> `wp_spreadconnect_sync_history`.
 *
 * @package SpreadconnectPod\Catalog
 */

declare(strict_types=1);

namespace SpreadconnectPod\Catalog;

use RuntimeException;
use SpreadconnectPod\Logging\Sources;
use SpreadconnectPod\Logging\WcLoggerAdapter;

/**
 * Repository for the catalog-sync history table.
 *
 * `final` per slice-23 / slice-24 constraints (no subclassing — the
 * collaborator chain in `SyncCatalogJob` and `SyncArticleJob` injects this
 * class directly via constructor DI).
 */
final class SyncHistoryRepo
{
	/**
	 * Table suffix appended to `$wpdb->prefix`.
	 */
	private const TABLE_SUFFIX = 'spreadconnect_sync_history';

	/**
	 * Transient-key prefix for the per-run total article count (slice-24
	 * AC-3). Full key shape: `sc_sync_total_{run_id}`.
	 */
	private const TRANSIENT_TOTAL_PREFIX = 'sc_sync_total_';

	/**
	 * Transient-key prefix for the per-run live log tail ring buffer
	 * (architecture.md Z. 352 — last 20 log lines, kept until run-end + 1 h).
	 * Full key shape: `sc_sync_log_tail_{run_id}`. Producer is
	 * `SyncArticleJob` (slice-23 / nachgereicht). Slice 26 is read-only on
	 * this transient (Constraints "KEIN Schreiben in den
	 * sc_sync_log_tail_{run_id}-Transient").
	 */
	private const TRANSIENT_LOG_TAIL_PREFIX = 'sc_sync_log_tail_';

	/**
	 * 24-hour TTL for the total transient. `DAY_IN_SECONDS` is a WP core
	 * constant (= 86400). Defined here as a fallback for non-WP test
	 * contexts where the constant may be missing.
	 */
	private const TRANSIENT_TOTAL_TTL_SECONDS = 86400;

	/**
	 * State enum values (`wp_spreadconnect_sync_history.state`).
	 *
	 * The `pending` state is set by the `dbDelta` schema default and applies
	 * to a freshly-inserted row that has not yet acquired a worker. As soon
	 * as `SyncCatalogJob::handle()` runs the row is transitioned to
	 * `in_progress` via `startRun()`. Both states are considered "active"
	 * for the slice-26 active-run lookup ({@see self::getActiveRun()}).
	 */
	private const STATE_PENDING     = 'pending';
	private const STATE_IN_PROGRESS = 'in_progress';
	private const STATE_COMPLETE    = 'complete';
	private const STATE_FAILED      = 'failed';

	/**
	 * Insert a fresh sync-history row in `state='in_progress'` and return
	 * the auto-generated `run_id`.
	 *
	 * Per slice-24 AC-1: `started_at = current_time('mysql')`,
	 * `trigger=$trigger`, every counter = 0, `details = '[]'`.
	 *
	 * @param string $trigger One of `manual|webhook|scheduled|initial`.
	 *                        Producer-validated; this method does not
	 *                        re-validate (architecture.md Z. 240).
	 *
	 * @return int Newly-created `run_id` (= `wp_spreadconnect_sync_history.id`).
	 *
	 * @throws RuntimeException On `$wpdb->insert()` failure.
	 */
	public function startRun( string $trigger ): int
	{
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$inserted = $wpdb->insert(
			$table,
			array(
				'started_at'    => current_time( 'mysql' ),
				'state'         => self::STATE_IN_PROGRESS,
				'trigger'       => $trigger,
				'created_count' => 0,
				'updated_count' => 0,
				'skipped_count' => 0,
				'error_count'   => 0,
				'details'       => '[]',
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
		);

		if ( false === $inserted ) {
			$lastError = $this->lastWpdbError( $wpdb );
			throw new RuntimeException(
				sprintf(
					'SyncHistoryRepo::startRun(): wpdb insert failed (%s).',
					$lastError
				)
			);
		}

		$runId = isset( $wpdb->insert_id ) ? (int) $wpdb->insert_id : 0;

		if ( $runId <= 0 ) {
			throw new RuntimeException(
				'SyncHistoryRepo::startRun(): wpdb did not return a positive insert_id.'
			);
		}

		return $runId;
	}

	/**
	 * Persist the total article count for a run.
	 *
	 * Stored in the `sc_sync_total_{run_id}` transient with 24 h TTL
	 * (slice-24 Constraints AC-3). The completion check inside
	 * {@see self::checkAndMarkComplete()} reads this transient back.
	 *
	 * @param int $runId Sync-history row id.
	 * @param int $total Number of articles enqueued for this run.
	 */
	public function setTotal( int $runId, int $total ): void
	{
		set_transient(
			self::TRANSIENT_TOTAL_PREFIX . $runId,
			$total,
			self::TRANSIENT_TOTAL_TTL_SECONDS
		);
	}

	/**
	 * Atomically increment the `created_count` column for a run.
	 *
	 * Single-statement `UPDATE` (no read-modify-write). After the increment
	 * the per-row counter sum is compared against the persisted total; once
	 * the sum reaches the total the row is CAS-flipped to `state='complete'`
	 * (slice-24 AC-7).
	 */
	public function incrementCreated( int $runId ): void
	{
		$this->incrementCounter( $runId, 'created_count' );
	}

	/**
	 * Atomically increment the `updated_count` column. See
	 * {@see self::incrementCreated()} for the contract.
	 */
	public function incrementUpdated( int $runId ): void
	{
		$this->incrementCounter( $runId, 'updated_count' );
	}

	/**
	 * Atomically increment the `skipped_count` column.
	 */
	public function incrementSkipped( int $runId ): void
	{
		$this->incrementCounter( $runId, 'skipped_count' );
	}

	/**
	 * Atomically increment the `error_count` column. Slice-24 Constraints
	 * map BOTH `error` and `partial` detail-statuses to this counter so the
	 * running counter sum stays equal to the total enqueue-count.
	 */
	public function incrementError( int $runId ): void
	{
		$this->incrementCounter( $runId, 'error_count' );
	}

	/**
	 * Mark a run as `complete` immediately (e.g. for the empty-catalog
	 * short-circuit, slice-24 AC-4).
	 *
	 * CAS update: only flips when the row is still `in_progress`, so a
	 * later concurrent counter-driven completion cannot revert the state.
	 */
	public function markComplete( int $runId ): void
	{
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET state = %s, finished_at = %s WHERE id = %d AND state = %s",
			self::STATE_COMPLETE,
			current_time( 'mysql' ),
			$runId,
			self::STATE_IN_PROGRESS
		);

		$wpdb->query( $sql );
	}

	/**
	 * Mark a run as `failed` and record the error message in the `details`
	 * JSON column under a synthetic `__error__` entry.
	 *
	 * Used by slice-24 AC-5 (4xx) and AC-10 (pagination cap exceeded).
	 *
	 * @param int    $runId        Sync-history row id.
	 * @param string $errorMessage Human-readable failure reason.
	 */
	public function markFailed( int $runId, string $errorMessage ): void
	{
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$sql = $wpdb->prepare(
			"UPDATE {$table} SET state = %s, finished_at = %s WHERE id = %d",
			self::STATE_FAILED,
			current_time( 'mysql' ),
			$runId
		);

		$wpdb->query( $sql );

		// Surface the error reason inside the details JSON so the
		// slice-26 Catalog-UI (and ad-hoc DB inspection) can read it back.
		// We piggy-back on `appendDetail()` rather than introducing a
		// dedicated column — the schema does not carry an `error_message`
		// field (architecture.md Z. 233-258) and the slice-23 contract
		// keeps `details` as the canonical narrative log.
		try {
			$this->appendDetail(
				$runId,
				array(
					'article_id' => '__error__',
					'title'      => null,
					'status'     => 'error',
					'notes'      => $errorMessage,
				)
			);
		} catch ( \Throwable $e ) {
			// Defensive: a missing row at this point is already an
			// inconsistent state; do not mask the original failure.
			// Surface via slice-42 WcLoggerAdapter so the entry lands in
			// the canonical sync-job log stream and the AC-10
			// raw-`error_log` ban stays intact.
			WcLoggerAdapter::warning(
				Sources::SYNC_JOB,
				sprintf(
					'failed to append __error__ detail for run_id=%d: %s',
					$runId,
					$e->getMessage()
				),
				array( 'run_id' => $runId )
			);
		}
	}

	/**
	 * Read a single sync-history row by primary key (slice-26 AC-7 / AC-9).
	 *
	 * Returns the row as an associative array (column → value) or `null`
	 * when no row matches the supplied id. Uses `$wpdb->get_row(... ARRAY_A)`
	 * with prepared SQL so the run id never reaches the SQL string
	 * unsanitised.
	 *
	 * Slice-26 callers ({@see \SpreadconnectPod\Hub\Rest\SyncProgress::handle()})
	 * use the `null` return as the trigger for the 404 `sync_run_not_found`
	 * response when an explicit `run_id` was requested.
	 *
	 * @param int $runId Sync-history row id.
	 *
	 * @return array<string, mixed>|null Row as assoc-array, or `null` when
	 *                                   the row does not exist.
	 */
	public function getById( int $runId ): ?array
	{
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $runId );
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Read the most-recent active sync-history row (slice-26 AC-3 / AC-8).
	 *
	 * "Active" means the row's `state` column is one of `pending` or
	 * `in_progress` — either the row was just inserted by `startRun()` and
	 * a worker has not yet picked it up, or the worker is currently iterating
	 * articles. The result is the youngest such row by primary key (DESC by
	 * `id`, which is monotonic with `started_at` because `startRun()` always
	 * inserts with `current_time('mysql')`).
	 *
	 * Returns `null` when no active row exists, in which case the caller
	 * ({@see \SpreadconnectPod\Hub\Rest\SyncProgress::handle()}) falls back
	 * to the youngest finished row, or — if the table is empty altogether —
	 * to the synthetic `state='idle'` default body (slice-26 AC-8).
	 *
	 * @return array<string, mixed>|null Row as assoc-array, or `null`.
	 */
	public function getActiveRun(): ?array
	{
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE state IN ( %s, %s ) ORDER BY id DESC LIMIT 1",
			self::STATE_IN_PROGRESS,
			self::STATE_PENDING
		);
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Read the most-recent N sync-history rows (slice-26 AC-2).
	 *
	 * Ordered DESC by `started_at` for the wireframes.md Screen 2 ⑦ history
	 * table. The default limit of 20 matches the slice-26 Constraints "KEINE
	 * Pagination der History-Tabelle" — older rows are pruned by slice-43's
	 * retention sweep, not paginated through here.
	 *
	 * Empty table → empty array (never `null`); the caller renders the
	 * `no_history_yet` empty-state from the wireframes State-Variations.
	 *
	 * @param int $limit Maximum number of rows to return (default 20).
	 *
	 * @return list<array<string, mixed>> Rows DESC by `started_at`.
	 */
	public function getRecent( int $limit = 20 ): array
	{
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// `LIMIT` does not accept `%d` placeholders in `prepare()` cleanly
		// across all WP versions — coerce to int and inline. The value is
		// caller-controlled but always passed as `int` per the signature.
		$safeLimit = max( 1, $limit );

		$sql  = $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY started_at DESC, id DESC LIMIT %d",
			$safeLimit
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return $rows;
	}

	/**
	 * Read the persisted total article count for a run (slice-26 AC-7).
	 *
	 * Wrapper around `get_transient('sc_sync_total_{run_id}')` (set by
	 * {@see self::setTotal()}). Returns `0` when the transient is missing
	 * (e.g. expired, or the run is still in pre-total enqueue phase) so the
	 * progress JSON body always carries an integer per the architecture.md
	 * Z. 132 contract.
	 *
	 * @param int $runId Sync-history row id.
	 *
	 * @return int Total article count, `0` when unknown.
	 */
	public function getTotal( int $runId ): int
	{
		$value = get_transient( self::TRANSIENT_TOTAL_PREFIX . $runId );

		if ( ! is_int( $value ) ) {
			return 0;
		}

		return $value;
	}

	/**
	 * Read the live log tail for a run (slice-26 AC-7, last 20 log lines).
	 *
	 * Wrapper around `get_transient('sc_sync_log_tail_{run_id}')` — the ring
	 * buffer written by `SyncArticleJob` (slice-23 / nachgereicht). The
	 * producer side is OUT-OF-SCOPE for slice-26: when the transient does
	 * not yet exist the method returns an empty list (per
	 * `Integration Contract` "Slice 26 ist Read-Only fuer diesen Transient
	 * — kein Fail-Modus").
	 *
	 * Defensive value-shape coercion: any non-array value (including the
	 * `false` returned by `get_transient()` on miss) collapses to `[]`.
	 * Each entry is cast to a string so the REST response shape always
	 * matches `last_log_lines:string[]` per architecture.md Z. 132.
	 *
	 * @param int $runId Sync-history row id.
	 *
	 * @return list<string> Last log lines (most-recent first or last per the
	 *                      producer's convention — slice 26 does not reorder).
	 */
	public function getLogTail( int $runId ): array
	{
		$value = get_transient( self::TRANSIENT_LOG_TAIL_PREFIX . $runId );

		if ( ! is_array( $value ) ) {
			return array();
		}

		$lines = array();
		foreach ( $value as $line ) {
			if ( is_scalar( $line ) ) {
				$lines[] = (string) $line;
			}
		}

		return $lines;
	}

	/**
	 * Append a single detail entry to a sync-history row's `details` JSON
	 * column.
	 *
	 * The JSON shape per architecture.md ("details JSON shape", Z. 252-258):
	 * ```
	 * [
	 *   { "article_id": "string", "title": "string",
	 *     "status": "created|updated|skipped|error|partial", "notes": "string|null" }
	 * ]
	 * ```
	 *
	 * Sequence:
	 *   1. Read the existing `details` value via prepared SELECT.
	 *   2. JSON-decode (tolerating `null`, `''`, malformed JSON ⇒ `[]`).
	 *   3. Append `$detail` (preserving the existing `details[]` shape).
	 *   4. JSON-encode and `$wpdb->update()` the row.
	 *
	 * Errors:
	 *   - Row not found → `\RuntimeException` (the slice-24 INSERT must
	 *     happen first; absence of the row is a programmer error).
	 *   - `$wpdb->update()` failure → `\RuntimeException` with the wpdb
	 *     last_error included in the message.
	 *
	 * @param int                  $runId  Primary key of the sync-history row
	 *                                     (= `wp_spreadconnect_sync_history.id`).
	 * @param array<string, mixed> $detail Detail entry; expected keys:
	 *                                     `article_id`, `title`, `status`, `notes`.
	 *
	 * @throws RuntimeException When the row does not exist or the update fails.
	 */
	public function appendDetail( int $runId, array $detail ): void
	{
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// Load the current `details` value. `prepare()` is mandatory because
		// `$runId` is primitive integer input; the table-name is interpolated
		// from `$wpdb->prefix` (no user input) — identifiers cannot be
		// parameterised via `%s` anyway.
		$sql     = $wpdb->prepare( "SELECT details FROM {$table} WHERE id = %d", $runId );
		$current = $wpdb->get_var( $sql );

		if ( null === $current ) {
			throw new RuntimeException(
				sprintf( 'SyncHistoryRepo::appendDetail(): no sync_history row found for id=%d.', $runId )
			);
		}

		// Decode the existing array. An empty / whitespace / non-JSON value
		// is treated as "no entries yet" rather than a hard failure — the
		// schema declares `details LONGTEXT NOT NULL`, but slice-24 may
		// insert an empty `''` or `'[]'` placeholder before the first
		// append.
		$decoded = is_string( $current ) && '' !== trim( $current )
			? json_decode( $current, true )
			: array();

		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		$decoded[] = $detail;

		$encoded = wp_json_encode( $decoded );
		if ( false === $encoded ) {
			// `wp_json_encode()` returns `false` only for un-encodable input
			// (e.g. binary strings, NaN). The job composes the array from
			// safe scalar values, so this branch is defensive.
			throw new RuntimeException(
				sprintf( 'SyncHistoryRepo::appendDetail(): failed to JSON-encode details for run_id=%d.', $runId )
			);
		}

		$updated = $wpdb->update(
			$table,
			array( 'details' => $encoded ),
			array( 'id' => $runId ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			$lastError = $this->lastWpdbError( $wpdb );
			throw new RuntimeException(
				sprintf(
					'SyncHistoryRepo::appendDetail(): wpdb update failed for run_id=%d (%s).',
					$runId,
					$lastError
				)
			);
		}
	}

	/**
	 * Common atomic-increment routine for the four `*_count` columns.
	 *
	 * Sequence (slice-24 AC-7):
	 *   1. `UPDATE … SET {col} = {col} + 1 WHERE id = %d` — single-statement
	 *      increment, no read-modify-write.
	 *   2. SELECT the running counter sum.
	 *   3. If the sum has reached the persisted total, CAS-flip the row to
	 *      `state='complete'` (only when still `in_progress`).
	 *
	 * Whitelisted column names guarantee the interpolated identifier is safe
	 * (the public increment methods are the only callers).
	 */
	private function incrementCounter( int $runId, string $column ): void
	{
		$allowed = array(
			'created_count' => true,
			'updated_count' => true,
			'skipped_count' => true,
			'error_count'   => true,
		);

		if ( ! isset( $allowed[ $column ] ) ) {
			throw new RuntimeException(
				sprintf( 'SyncHistoryRepo::incrementCounter(): unknown column "%s".', $column )
			);
		}

		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// Atomic single-statement increment. The column name is whitelisted
		// above so it is safe to interpolate; only `$runId` needs prepare().
		$sql = $wpdb->prepare(
			"UPDATE {$table} SET {$column} = {$column} + 1 WHERE id = %d",
			$runId
		);
		$wpdb->query( $sql );

		$this->checkAndMarkComplete( $runId );
	}

	/**
	 * Read the running counter sum and CAS-flip the row to `complete` once
	 * it matches the persisted total.
	 *
	 * No-op when the total transient is missing (e.g. expired) — without a
	 * total there is no completion criterion. The CAS WHERE-clause prevents
	 * double flips when multiple workers race past the same threshold.
	 */
	private function checkAndMarkComplete( int $runId ): void
	{
		$total = get_transient( self::TRANSIENT_TOTAL_PREFIX . $runId );

		if ( ! is_int( $total ) || $total <= 0 ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$sumSql = $wpdb->prepare(
			"SELECT created_count + updated_count + skipped_count + error_count AS sum FROM {$table} WHERE id = %d",
			$runId
		);
		$sum    = $wpdb->get_var( $sumSql );

		if ( null === $sum ) {
			return;
		}

		$sumInt = (int) $sum;

		if ( $sumInt < $total ) {
			return;
		}

		$completeSql = $wpdb->prepare(
			"UPDATE {$table} SET state = %s, finished_at = %s WHERE id = %d AND state = %s",
			self::STATE_COMPLETE,
			current_time( 'mysql' ),
			$runId,
			self::STATE_IN_PROGRESS
		);
		$wpdb->query( $completeSql );
	}

	/**
	 * Read the `last_error` string off `$wpdb` defensively.
	 */
	private function lastWpdbError( $wpdb ): string
	{
		return property_exists( $wpdb, 'last_error' ) && is_string( $wpdb->last_error )
			? $wpdb->last_error
			: '';
	}
}
