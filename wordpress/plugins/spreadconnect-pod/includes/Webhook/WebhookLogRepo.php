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
	 * `insertOrIgnore` return-status enum — binary contract (slice-16 AC-5/AC-6).
	 */
	public const STATUS_INSERTED = 'inserted';

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
	 * @return array{status:'inserted'|'duplicate', log_id:int}
	 *
	 * @throws \RuntimeException When the INSERT fails for any reason other
	 *                           than a UNIQUE-constraint conflict on
	 *                           `event_id` (slice-16 binary-return contract).
	 */
	public static function insertOrIgnore( array $row ): array
	{
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		$inserted = $wpdb->insert( $table, $row );
		if ( false !== $inserted && (int) $inserted === 1 ) {
			return array(
				'status' => self::STATUS_INSERTED,
				'log_id' => (int) $wpdb->insert_id,
			);
		}

		// Duplicate detection: MySQL errno 1062 surfaces as a `Duplicate
		// entry` substring in `$wpdb->last_error` (slice-16 contract).
		$lastError = isset( $wpdb->last_error ) && is_string( $wpdb->last_error ) ? $wpdb->last_error : '';
		if ( '' !== $lastError && false !== stripos( $lastError, 'Duplicate entry' ) ) {
			$existingId = self::findIdByEventId( (string) ( $row['event_id'] ?? '' ) );

			// Slice-16 AC-6: mark the existing row as a duplicate so
			// downstream consumers (slice-17 job dispatcher, slice-41 UI)
			// can distinguish replayed events from first-time inserts.
			if ( $existingId > 0 ) {
				$wpdb->update(
					$table,
					array( 'processing_status' => self::STATUS_DUPLICATE ),
					array( 'id' => $existingId ),
					array( '%s' ),
					array( '%d' )
				);
			}

			return array(
				'status' => self::STATUS_DUPLICATE,
				'log_id' => $existingId,
			);
		}

		// Insert-failure without Duplicate-marker: the row was rejected for
		// a reason other than UNIQUE-conflict (schema mismatch, connection
		// loss, ...). Throwing keeps the binary `inserted|duplicate`-return
		// contract intact (slice-16 AC-5/AC-6 mandate exactly two states).
		throw new \RuntimeException( 'spreadconnect_webhook_log_insert_failed' );
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
	 * Spalten-Whitelist fuer {@see self::findFiltered()} und {@see self::countFiltered()}.
	 *
	 * Defense-in-depth: das UI (Slice 41) validiert Filter bereits gegen Whitelists,
	 * aber der Repo-Layer wiederholt die Validierung damit jeder Aufrufer (zukuenftige
	 * Slices, Tests, Tools) durch denselben Gate laeuft. Werte ausserhalb dieser
	 * Whitelist werden silently ignoriert (siehe Filter-Validierung unten).
	 *
	 * @var list<string>
	 */
	private const FILTER_COLUMNS = array(
		'event_type',
		'received_at',
		'hmac_status',
		'processing_status',
	);

	/**
	 * Slice 41 AC-3 / AC-4: gefilterte Listen-Query mit Pagination.
	 *
	 * Defense-in-depth: jeder Filter-Wert wird hier erneut gegen seine Whitelist
	 * geprueft, bevor er via `$wpdb->prepare()` in die SQL geht. Unbekannte
	 * Werte fallen silently auf `'all'` zurueck (= kein WHERE-Predikat fuer
	 * dieses Feld). Die Indexes `idx_received_at` und `idx_processing_status`
	 * (Slice 04 / architecture.md Z. 228-231) decken die WHERE-Klausel ab.
	 *
	 * @param array{
	 *     event?: string,
	 *     range?: string,
	 *     hmac?: string,
	 *     proc?: string
	 * }   $filters Whitelist-validierte Filter-Werte (Strings).
	 * @param int $limit  Maximale Zeilenanzahl (Page-Size, > 0).
	 * @param int $offset OFFSET (>= 0).
	 *
	 * @return array<int, array<string, mixed>> Rows DESC nach `received_at`.
	 */
	public static function findFiltered( array $filters, int $limit, int $offset ): array
	{
		if ( $limit <= 0 || $offset < 0 ) {
			return array();
		}

		global $wpdb;

		$args  = array();
		$where = self::buildFilterWhereClause( $filters, $args );
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// Append the LIMIT + OFFSET binds AFTER all WHERE-binds so the
		// `prepare()` argument-order matches the placeholder-order in the SQL.
		$args[] = $limit;
		$args[] = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} {$where} ORDER BY received_at DESC LIMIT %d OFFSET %d",
			$args
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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

	/**
	 * Slice 41 AC-5: Total-Count fuer den Pager. Nutzt dieselbe WHERE-Klausel
	 * wie {@see self::findFiltered()} so dass die beiden Queries denselben
	 * Index-Pfad teilen.
	 *
	 * @param array{
	 *     event?: string,
	 *     range?: string,
	 *     hmac?: string,
	 *     proc?: string
	 * } $filters Whitelist-validierte Filter-Werte.
	 *
	 * @return int Anzahl der Zeilen, die die WHERE-Klausel erfuellen.
	 */
	public static function countFiltered( array $filters ): int
	{
		global $wpdb;

		$args  = array();
		$where = self::buildFilterWhereClause( $filters, $args );
		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		if ( array() === $args ) {
			// `prepare()` requires at least one argument; use an unparameterised
			// COUNT(*) when the WHERE is empty (= no filters active).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$value = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} {$where}",
				$args
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$value = $wpdb->get_var( $sql );
		}

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Build the WHERE-clause used by {@see self::findFiltered()} and
	 * {@see self::countFiltered()}.
	 *
	 * Defense-in-depth: Filter-Werte werden gegen ihre eigene Whitelist
	 * geprueft, bevor sie in `$args` (=  `prepare()`-Binds) wandern. Das
	 * spiegelt die UI-Sanitisierung (Slice 41 AC-3) im Repo wider.
	 *
	 * Spalten-Namen sind Literale aus {@see self::FILTER_COLUMNS} (no
	 * variable-interpolation). Das `range`-Feld wird in einen UTC-DATETIME-
	 * String umgewandelt und gegen `received_at >= %s` gebunden.
	 *
	 * @param array<string, mixed> $filters Eingehende Filter-Map.
	 * @param array<int, mixed>    $args    Out-Parameter — wird mit `prepare()`-
	 *                                       Binds in WHERE-Reihenfolge befuellt.
	 *
	 * @return string `''` (kein Filter) oder `WHERE col = %s AND ...` ohne
	 *                trailing-Whitespace.
	 */
	private static function buildFilterWhereClause( array $filters, array &$args ): string
	{
		$predicates = array();

		// event_type ($filters['event'])
		$event = isset( $filters['event'] ) && is_string( $filters['event'] ) ? $filters['event'] : 'all';
		if ( in_array( $event, self::EVENT_TYPES_WHITELIST, true ) ) {
			$predicates[] = 'event_type = %s';
			$args[]       = $event;
		}

		// received_at ($filters['range']) — interval lookup against the
		// whitelist and resolved to a UTC-MySQL-DATETIME-string before the bind.
		$range = isset( $filters['range'] ) && is_string( $filters['range'] ) ? $filters['range'] : 'all';
		if ( in_array( $range, array( '24h', '7d', '30d' ), true ) ) {
			$cutoff = self::resolveRangeCutoff( $range );
			if ( '' !== $cutoff ) {
				$predicates[] = 'received_at >= %s';
				$args[]       = $cutoff;
			}
		}

		// hmac_status ($filters['hmac'])
		$hmac = isset( $filters['hmac'] ) && is_string( $filters['hmac'] ) ? $filters['hmac'] : 'all';
		if ( in_array( $hmac, array( 'valid', 'invalid' ), true ) ) {
			$predicates[] = 'hmac_status = %s';
			$args[]       = $hmac;
		}

		// processing_status ($filters['proc'])
		$proc = isset( $filters['proc'] ) && is_string( $filters['proc'] ) ? $filters['proc'] : 'all';
		if ( in_array( $proc, array( self::STATUS_SUCCESS, self::STATUS_ERROR, self::STATUS_PENDING, self::STATUS_DUPLICATE ), true ) ) {
			$predicates[] = 'processing_status = %s';
			$args[]       = $proc;
		}

		if ( array() === $predicates ) {
			return '';
		}

		return 'WHERE ' . implode( ' AND ', $predicates );
	}

	/**
	 * Resolve a range-enum value into a UTC MySQL DATETIME-string suitable for
	 * `received_at >= %s` binding.
	 *
	 * Uses `current_time('mysql', true)` (UTC) so the cutoff matches the
	 * `received_at` column's timezone semantics.
	 */
	private static function resolveRangeCutoff( string $range ): string
	{
		$intervalSeconds = match ( $range ) {
			'24h'   => 86400,
			'7d'    => 604800,
			'30d'   => 2592000,
			default => 0,
		};

		if ( 0 === $intervalSeconds ) {
			return '';
		}

		// `current_time('mysql', true)` returns the current GMT/UTC time as
		// `YYYY-MM-DD HH:MM:SS`. Subtract the interval via strtotime + gmdate
		// so the cutoff is timezone-agnostic.
		$nowMysql = function_exists( 'current_time' )
			? (string) current_time( 'mysql', true )
			: gmdate( 'Y-m-d H:i:s' );

		$nowTs = strtotime( $nowMysql );
		if ( false === $nowTs ) {
			$nowTs = time();
		}

		return gmdate( 'Y-m-d H:i:s', $nowTs - $intervalSeconds );
	}

	/**
	 * The 8 webhook event-type Whitelist values (architecture.md Z. 41 + Z. 175).
	 *
	 * Spelled identically to the values that arrive on the wire so a
	 * `===` comparison resolves without case-folding or trim helpers.
	 *
	 * @var list<string>
	 */
	public const EVENT_TYPES_WHITELIST = array(
		'all',
		'Article.added',
		'Article.updated',
		'Article.removed',
		'Order.processed',
		'Order.cancelled',
		'Order.needs-action',
		'Shipment.sent',
	);

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

	/**
	 * Slice 46 AC-11: return the most-recent webhook-log row across all event
	 * types, or `null` when the table is empty.
	 *
	 * Used by the Hub Dashboard "Webhooks" card to render
	 * `received_at + event_type` of the last received event. The composite index
	 * `idx_received_at` (Slice 04 schema) covers the `ORDER BY ... LIMIT 1`
	 * scan so this is a near-zero-cost lookup.
	 *
	 * @return array<string, mixed>|null Row as assoc-array, or `null`.
	 */
	public static function findLatest(): ?array
	{
		global $wpdb;

		// Defensive guard for non-WP test contexts where `$wpdb` may not be
		// bootstrapped. A missing `$wpdb` is treated as "no rows", never as
		// a fatal — mirrors the existing `function_exists()` defensiveness
		// elsewhere in the codebase.
		if ( ! isset( $wpdb ) || null === $wpdb || ! is_object( $wpdb ) ) {
			return null;
		}

		$table = $wpdb->prefix . self::TABLE_SUFFIX;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY received_at DESC LIMIT 1", ARRAY_A );

		return is_array( $row ) ? $row : null;
	}
}
