<?php
/**
 * Daily recurring purge of expired log rows (slice-43).
 *
 * Closes Discovery Slice 10 "Auto-Purge-Cron" and mitigates the
 * architecture-Risk row "Action-Scheduler jobs accumulate without purge ->
 * DB bloat" (architecture.md Z. 738). Two custom tables carry retention-
 * controlled rows that grow without bound until purged:
 *
 *   - `wp_spreadconnect_webhook_log` (slice-04, indexed `idx_received_at`)
 *   - `wp_spreadconnect_failed_ops`  (slice-04, indexed `idx_created_at`)
 *
 * Each table has its OWN retention setting (architecture.md Z. 339-340):
 *
 *   - `spreadconnect_webhook_log_retention_days` (default 90, range 7..365)
 *   - `spreadconnect_failed_ops_retention_days`  (default 90, range 7..365)
 *
 * `handle()` builds an independent UTC cutoff per table (`now - retention
 * days`, formatted via `gmdate('Y-m-d H:i:s', ...)`) and runs ONE
 * `$wpdb->prepare()`-bound DELETE per table. Rows whose timestamp is
 * STRICTLY older than the cutoff are removed; rows exactly at the cutoff
 * survive (slice-43 AC-3 / AC-4 — `<` semantics).
 *
 * Failure mode (slice-43 AC-10): a `false` return from
 * `$wpdb->query()` is logged via {@see WcLoggerAdapter::error()} (source
 * `spreadconnect-failure`) WITHOUT short-circuiting the second DELETE —
 * best-effort across both tables. The job is registered as `no retry`
 * (architecture.md Z. 556); a one-day blip is acceptable because the
 * recurring schedule will retry the next day.
 *
 * Architecture references:
 *   - "Action Scheduler — Hook Inventory" -> `spreadconnect/purge_old_logs`
 *     (recurring daily, Concurrency 1, no retry; Z. 556).
 *   - "Risks" -> DB-bloat mitigation row (Z. 738).
 *   - "Settings" -> retention options + range (Z. 339-340).
 *   - "Tables" -> `idx_received_at` / `idx_created_at` "for retention purge"
 *     (Z. 210, Z. 229).
 *
 * @package SpreadconnectPod\Logging
 */

declare(strict_types=1);

namespace SpreadconnectPod\Logging;

/**
 * Action-Scheduler handler for the `spreadconnect/purge_old_logs` hook.
 *
 * Stateless: no constructor, no instance state. Action-Scheduler invokes
 * the registered callable; the recurring schedule itself is laid down by
 * `Bootstrap\Plugin::scheduleRecurringPurgeOldLogs()` on plugin activation
 * (idempotent pre-check via `as_next_scheduled_action()`).
 *
 * `final` per slice-43 Constraints.
 */
final class PurgeOldLogsJob
{
	/**
	 * Hook name for the daily purge action (architecture.md Z. 556).
	 */
	public const HOOK = 'spreadconnect/purge_old_logs';

	/**
	 * Action-Scheduler group (architecture.md Z. 558 — all plugin hooks
	 * share the same group for filterable visibility under
	 * `Tools -> Scheduled Actions`).
	 */
	public const AS_GROUP = 'spreadconnect';

	/**
	 * Custom-table suffix appended to `$wpdb->prefix` for the inbound
	 * webhook-log table (slice-04).
	 */
	private const TABLE_WEBHOOK_LOG = 'spreadconnect_webhook_log';

	/**
	 * Custom-table suffix appended to `$wpdb->prefix` for the failed-ops
	 * DLQ table (slice-04).
	 */
	private const TABLE_FAILED_OPS = 'spreadconnect_failed_ops';

	/**
	 * Option key for the webhook-log retention window (architecture.md
	 * Z. 340).
	 */
	private const OPTION_WEBHOOK_LOG_RETENTION = 'spreadconnect_webhook_log_retention_days';

	/**
	 * Option key for the failed-ops retention window (architecture.md
	 * Z. 339).
	 */
	private const OPTION_FAILED_OPS_RETENTION = 'spreadconnect_failed_ops_retention_days';

	/**
	 * Architecture default when an option is missing or unparseable
	 * (architecture.md Z. 339-340).
	 */
	private const RETENTION_DEFAULT_DAYS = 90;

	/**
	 * Lower clamp bound for retention values (architecture.md Z. 339-340
	 * Validation column: `7..365`).
	 */
	private const RETENTION_MIN_DAYS = 7;

	/**
	 * Upper clamp bound for retention values (architecture.md Z. 339-340).
	 */
	private const RETENTION_MAX_DAYS = 365;

	/**
	 * Run one purge sweep across both custom tables.
	 *
	 * Best-effort semantics (slice-43 AC-10): a DB error on the first
	 * DELETE is logged but does NOT prevent the second DELETE from
	 * running. No exception is permitted to escape the hook boundary —
	 * Action-Scheduler is configured `no retry` (architecture.md Z. 556)
	 * and a thrown exception would still leave the failed table in a
	 * partial state until the next daily tick.
	 *
	 * @return void
	 */
	public static function handle(): void
	{
		global $wpdb;

		// Defensive bail-out: in stripped test contexts (e.g. Brain\Monkey
		// without a wpdb stub) the global may be unset. The recurring
		// schedule will retry on the next tick when the environment is
		// properly set up.
		if ( ! is_object( $wpdb ) ) {
			return;
		}

		$webhookLogRetention = self::resolveRetentionDays( self::OPTION_WEBHOOK_LOG_RETENTION );
		$failedOpsRetention  = self::resolveRetentionDays( self::OPTION_FAILED_OPS_RETENTION );

		$webhookLogCutoff = self::cutoffFromRetention( $webhookLogRetention );
		$failedOpsCutoff  = self::cutoffFromRetention( $failedOpsRetention );

		$webhookLogResult = self::deleteOlderThan(
			$wpdb,
			self::TABLE_WEBHOOK_LOG,
			'received_at',
			$webhookLogCutoff
		);

		$failedOpsResult = self::deleteOlderThan(
			$wpdb,
			self::TABLE_FAILED_OPS,
			'created_at',
			$failedOpsCutoff
		);

		// Log the run summary. Even if a DELETE failed, the success-row
		// counts for the surviving DELETE remain useful operational data.
		WcLoggerAdapter::info(
			Sources::FAILURE,
			'Purged expired log rows.',
			array(
				'webhook_log_deleted' => $webhookLogResult['deleted'],
				'failed_ops_deleted'  => $failedOpsResult['deleted'],
				'webhook_log_cutoff'  => $webhookLogCutoff,
				'failed_ops_cutoff'   => $failedOpsCutoff,
			)
		);
	}

	/**
	 * Read a retention option, coerce to int, fall back to the
	 * architecture-default on missing/empty/invalid values, then clamp
	 * into the allowed `[7..365]` range.
	 *
	 * Slice-43 AC-6 / AC-7 — defensive against direct option writes that
	 * bypass the slice-11 `SettingsValidator`.
	 *
	 * @param string $optionKey One of the two retention option keys.
	 * @return int Clamped retention value in days.
	 */
	private static function resolveRetentionDays( string $optionKey ): int
	{
		$raw = get_option( $optionKey, self::RETENTION_DEFAULT_DAYS );

		// `false`/`null`/`''` -> default. `is_numeric` is permissive
		// enough to accept "90" strings persisted via `update_option`.
		if ( false === $raw || null === $raw || '' === $raw ) {
			$value = self::RETENTION_DEFAULT_DAYS;
		} elseif ( is_int( $raw ) ) {
			$value = $raw;
		} elseif ( is_string( $raw ) && is_numeric( $raw ) ) {
			$value = (int) $raw;
		} elseif ( is_numeric( $raw ) ) {
			$value = (int) $raw;
		} else {
			$value = self::RETENTION_DEFAULT_DAYS;
		}

		// Defensive clamp (slice-43 AC-7) — even if a non-validated value
		// reached `wp_options` somehow.
		return max( self::RETENTION_MIN_DAYS, min( self::RETENTION_MAX_DAYS, $value ) );
	}

	/**
	 * Compute a UTC cutoff timestamp `now - $days days` formatted for
	 * comparison with `received_at` / `created_at` (both DATETIME columns
	 * written in UTC via `current_time('mysql', true)` on insert).
	 *
	 * @param int $days Clamped retention value (slice-43 AC-7).
	 * @return string MySQL DATETIME (`Y-m-d H:i:s`) in UTC.
	 */
	private static function cutoffFromRetention( int $days ): string
	{
		$seconds = $days * DAY_IN_SECONDS;

		return gmdate( 'Y-m-d H:i:s', time() - $seconds );
	}

	/**
	 * Run one prepared `DELETE FROM <prefix><suffix> WHERE <column> <
	 * <cutoff>` statement and report the row-count + error state.
	 *
	 * SQL construction rules (slice-43 AC-8):
	 *   - Table name is built from `$wpdb->prefix . $tableSuffix` — NEVER
	 *     hard-coded `wp_` (multisite/custom-prefix safety).
	 *   - The cutoff value is bound through `$wpdb->prepare(..., $cutoff)`
	 *     with the `%s` placeholder — NEVER concatenated.
	 *   - The column name is an internal whitelisted literal (`received_at`
	 *     or `created_at`), not user input — direct interpolation is
	 *     acceptable per architecture conventions.
	 *
	 * On `$wpdb->query()` returning `false`, an error is logged via
	 * {@see WcLoggerAdapter::error()} with the `last_error` field but the
	 * overall call returns normally so the second DELETE can still run
	 * (slice-43 AC-10 — best-effort semantics).
	 *
	 * @param object $wpdb         Live `$wpdb` instance.
	 * @param string $tableSuffix  One of the two `TABLE_*` constants.
	 * @param string $column       `received_at` or `created_at`.
	 * @param string $cutoff       MySQL DATETIME, UTC.
	 * @return array{deleted:int,success:bool}
	 */
	private static function deleteOlderThan( $wpdb, string $tableSuffix, string $column, string $cutoff ): array
	{
		$table = $wpdb->prefix . $tableSuffix;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"DELETE FROM {$table} WHERE {$column} < %s",
			$cutoff
		);

		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			$lastError = isset( $wpdb->last_error ) && is_string( $wpdb->last_error )
				? $wpdb->last_error
				: '';

			WcLoggerAdapter::error(
				Sources::FAILURE,
				'Purge DELETE failed; continuing best-effort with remaining tables.',
				array(
					'table'      => $table,
					'column'     => $column,
					'cutoff'     => $cutoff,
					'last_error' => $lastError,
				)
			);

			return array(
				'deleted' => 0,
				'success' => false,
			);
		}

		// `$wpdb->query()` returns the affected-row count for DELETE.
		// `rows_affected` is also populated as a back-up signal.
		if ( is_int( $result ) ) {
			$deleted = $result;
		} elseif ( is_numeric( $result ) ) {
			$deleted = (int) $result;
		} elseif ( isset( $wpdb->rows_affected ) && is_numeric( $wpdb->rows_affected ) ) {
			$deleted = (int) $wpdb->rows_affected;
		} else {
			$deleted = 0;
		}

		return array(
			'deleted' => $deleted,
			'success' => true,
		);
	}
}
