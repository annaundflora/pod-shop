<?php
/**
 * Repository for the `wp_spreadconnect_webhook_log` custom table.
 *
 * Slice 16 introduces this repository for the inbound webhook receiver
 * (`insertOrIgnore` for idempotent INSERT, `find` + `updateProcessingStatus`
 * for the asynchronous job dispatcher).
 *
 * Slice 32 (this file's most-recent edit) adds {@see self::findRecentForOrder()}
 * — a per-order "last 5 webhook events" read used by the
 * `Inline\OrderMetaBox` Webhook-Activity block (Screen 11 ⑩, Slice 32 AC-5).
 *
 * Storage: `{$wpdb->prefix}spreadconnect_webhook_log` (Slice 04 schema):
 *   id, event_type, event_id (UNIQUE sha256 hex), related_entity_type,
 *   related_entity_id, payload (JSON re-encoded), hmac_status,
 *   processing_status, processing_error, received_at.
 *
 * @package SpreadconnectPod\Webhook
 */

declare(strict_types=1);

namespace SpreadconnectPod\Webhook;

/**
 * Repository for `wp_spreadconnect_webhook_log` rows.
 *
 * Static-only `final class` — Repository pattern (architecture.md "Service
 * Map" Z. 537). Direct `$wpdb`-Access via `global $wpdb;` keeps the surface
 * minimal and consistent with `Bootstrap\Schema` (Slice 04). Brain\Monkey
 * tests stub `$wpdb` and assert on `prepare`/`insert`/`get_var`/`get_results`
 * call counts.
 */
final class WebhookLogRepo
{
	/**
	 * Custom-table name suffix appended to `$wpdb->prefix`.
	 */
	private const TABLE_SUFFIX = 'spreadconnect_webhook_log';

	/**
	 * `related_entity_type` value used by Order-related webhook events
	 * (`Order.created`, `Order.processed`, `Order.cancelled`,
	 * `Order.needs-action`, `Shipment.sent`).
	 */
	public const ENTITY_TYPE_ORDER = 'order';

	/**
	 * `processing_status` enum (architecture.md Z. 223).
	 */
	public const STATUS_PENDING   = 'pending';
	public const STATUS_SUCCESS   = 'success';
	public const STATUS_ERROR     = 'error';
	public const STATUS_DUPLICATE = 'duplicate';

	/**
	 * Idempotent INSERT: writes a webhook-log row, gracefully recognising
	 * UNIQUE-constraint conflicts on `event_id` and reporting them as
	 * `'duplicate'` instead of failing.
	 *
	 * Slice 16 contract — implementation deferred to slice 16 itself; here
	 * only the signature is reserved. Slice 32 does NOT call this method.
	 *
	 * @param array<string, mixed> $row Row payload (already-validated).
	 *
	 * @return array{status:string, log_id:int}
	 */
	public static function insertOrIgnore( array $row ): array
	{
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$inserted = $wpdb->insert( $table, $row );
		if ( false !== $inserted && (int) $inserted === 1 ) {
			return array(
				'status' => 'inserted',
				'log_id' => (int) $wpdb->insert_id,
			);
		}

		// Duplicate detection: MySQL errno 1062 surfaces as a `Duplicate
		// entry` substring in `$wpdb->last_error` (slice-16 contract).
		$lastError = isset( $wpdb->last_error ) && is_string( $wpdb->last_error ) ? $wpdb->last_error : '';
		if ( '' !== $lastError && false !== stripos( $lastError, 'Duplicate entry' ) ) {
			$existingId = self::findIdByEventId( (string) ( $row['event_id'] ?? '' ) );
			return array(
				'status' => 'duplicate',
				'log_id' => $existingId,
			);
		}

		return array(
			'status' => 'error',
			'log_id' => 0,
		);
	}

	/**
	 * Resolve a row's primary-key by its UNIQUE `event_id` (slice-16 helper).
	 */
	private static function findIdByEventId( string $eventId ): int
	{
		if ( '' === $eventId ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT id FROM {$table} WHERE event_id = %s LIMIT 1",
			$eventId
		);

		$value = $wpdb->get_var( $sql );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Find one log-row by primary-key.
	 *
	 * Slice 16 / Slice 17 consumer. Returns the raw SELECT-* assoc-array
	 * or `null` when not found.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function find( int $logId ): ?array
	{
		if ( $logId <= 0 ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
			$logId
		);

		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update `processing_status` (and optionally `processing_error`) for one
	 * log-row.
	 *
	 * Slice 16 / Slice 17 consumer. No-op when `$logId <= 0`.
	 */
	public static function updateProcessingStatus( int $logId, string $status, ?string $error = null ): void
	{
		if ( $logId <= 0 ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$data   = array( 'processing_status' => $status );
		$format = array( '%s' );

		if ( null !== $error ) {
			$data['processing_error'] = $error;
			$format[]                 = '%s';
		}

		$wpdb->update(
			$table,
			$data,
			array( 'id' => $logId ),
			$format,
			array( '%d' )
		);
	}

	/**
	 * Slice 32 AC-5: return the most-recent webhook-log rows for one
	 * Spreadconnect-Order-ID, newest first.
	 *
	 * Used by `Inline\OrderMetaBox` Webhook-Activity block (Screen 11 ⑩).
	 * The composite index `idx_related_entity` covers
	 * `(related_entity_type, related_entity_id, received_at)` so the LIMIT-5
	 * scan is index-only.
	 *
	 * @param string $scOrderId Spreadconnect-Order-ID (matches
	 *                          `related_entity_id` for order-events).
	 * @param int    $limit     Maximum rows to return (default 5).
	 *
	 * @return array<int, array<string, mixed>> Empty array on missing/empty
	 *                                          input or zero matches.
	 */
	public static function findRecentForOrder( string $scOrderId, int $limit = 5 ): array
	{
		if ( '' === $scOrderId || $limit <= 0 ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT event_type, received_at, processing_status FROM {$table} "
				. "WHERE related_entity_type = %s AND related_entity_id = %s "
				. "ORDER BY received_at DESC LIMIT %d",
			self::ENTITY_TYPE_ORDER,
			$scOrderId,
			$limit
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $row;
			}
		}

		return $out;
	}
}
