<?php
/**
 * Catalog-sync history repository — details-JSON append (slice-23 scope).
 *
 * Owns read/write access to the `details` LONGTEXT column on
 * `{$wpdb->prefix}spreadconnect_sync_history` (schema: see
 * {@see \SpreadconnectPod\Bootstrap\Schema}). Slice 23 only needs the
 * append operation — the per-run row is INSERTed by slice-24's catalog-job
 * with `state='in_progress'`, and slice-24 is also responsible for the
 * counter columns (`created_count`, `updated_count`, `skipped_count`,
 * `error_count`) plus `markComplete()`.
 *
 * Implementation choice (architecture.md "wp_spreadconnect_sync_history"
 * Z. 246 + slice-23 Constraints "atomar lesen / schreiben"):
 *   - Read the existing `details` JSON via `$wpdb->get_var( $wpdb->prepare( … ) )`.
 *   - Decode → append the new entry → re-encode.
 *   - `$wpdb->update( $table, [ 'details' => json_encode($newArr) ], [ 'id' => $runId ] )`.
 *
 * The "Last-Write-Wins" race policy from discovery.md Z. 573 makes this
 * read-modify-write acceptable; a future optimisation could switch to
 * `JSON_ARRAY_APPEND` (MySQL ≥ 5.7), but per slice-23 Constraints the
 * fallback shape is explicitly allowed.
 *
 * Architecture: `architecture.md` -> "Database Schema" -> `wp_spreadconnect_sync_history`.
 *
 * @package SpreadconnectPod\Catalog
 */

declare(strict_types=1);

namespace SpreadconnectPod\Catalog;

use RuntimeException;

/**
 * Slim history repo for slice-23 — only `appendDetail()` is in scope.
 *
 * `final` because the contract is fixed by slice-23's integration map and
 * slice-24 will EXTEND this class with the missing counter / state methods
 * (no test currently subclasses it).
 */
final class SyncHistoryRepo
{
	/**
	 * Table suffix appended to `$wpdb->prefix`.
	 */
	private const TABLE_SUFFIX = 'spreadconnect_sync_history';

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
			$lastError = property_exists( $wpdb, 'last_error' ) && is_string( $wpdb->last_error )
				? $wpdb->last_error
				: '';
			throw new RuntimeException(
				sprintf(
					'SyncHistoryRepo::appendDetail(): wpdb update failed for run_id=%d (%s).',
					$runId,
					$lastError
				)
			);
		}
	}
}
