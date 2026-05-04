<?php
/**
 * Repository for the `wp_spreadconnect_failed_ops` custom table (slice-37).
 *
 * Owns full CRUD on the dead-letter queue (DLQ) introduced by Slice 04
 * ({@see \SpreadconnectPod\Bootstrap\Schema}). Slice 37 is the producer side
 * of the DLQ pipeline:
 *
 *   - {@see self::record()} writes a fresh row with all 11 columns populated,
 *     UTC timestamps, JSON-serialised payload and `state='unresolved'`.
 *   - {@see self::findById()}, {@see self::findAll()}, {@see self::findByEntity()}
 *     and {@see self::count()} provide the read surface consumed by Slice 38
 *     (UI), Slice 39 (notifier), Slice 40 (bulk-resend) and Slice 43
 *     (retention purge).
 *   - {@see self::markResolved()} / {@see self::markDismissed()} mutate
 *     `state` for the manual-resolution flow (Slice 38).
 *
 * Architecture refs:
 *   - architecture.md "Schema Details" Z. 191-210 (column list + indexes).
 *   - architecture.md "Service Map" Z. 388 (responsibility row).
 *   - architecture.md "Quality Attributes — Reliability" Z. 680
 *     (3-fail-then-record DLQ contract).
 *
 * Logging: every diagnostic is routed through {@see WcLoggerAdapter} with
 * source {@see Sources::FAILURE}. Slice 42's lint contract forbids raw
 * `error_log()` calls in v2 sources (architecture.md Z. 687).
 *
 * @package SpreadconnectPod\Failure
 */

declare(strict_types=1);

namespace SpreadconnectPod\Failure;

use SpreadconnectPod\Logging\Sources;
use SpreadconnectPod\Logging\WcLoggerAdapter;

/**
 * CRUD repository for `wp_spreadconnect_failed_ops`.
 *
 * Constructor-injected `\wpdb` keeps unit tests easy — Brain\Monkey/Mockery
 * doubles can replace the global without touching `$GLOBALS['wpdb']`. The
 * optional `?\WC_Logger` is preserved per the slice-37 Provides-To contract
 * (used by future slices that want to override the logger sink), but the
 * default logging path goes through {@see WcLoggerAdapter} so the canonical
 * source-whitelist (slice-42) is honoured automatically.
 *
 * `final` per slice-37 Constraints — no subclassing.
 */
final class FailedOpsRepo
{
	/**
	 * Custom-table suffix appended to `$wpdb->prefix`.
	 */
	private const TABLE_SUFFIX = 'spreadconnect_failed_ops';

	/**
	 * `state` enum values (architecture.md Z. 205).
	 */
	public const STATE_UNRESOLVED = 'unresolved';
	public const STATE_RESOLVED   = 'resolved';
	public const STATE_DISMISSED  = 'dismissed';

	/**
	 * `op_type` enum values (architecture.md Z. 542-556 + Z. 457). Mirrored
	 * here so consumers (Slice 38 UI filter, Slice 40 op-type → AS-hook map,
	 * Slice 39 notifier routing) reach a single source-of-truth.
	 *
	 * @var list<string>
	 */
	public const OP_TYPES = array(
		'create_order',
		'confirm_order',
		'cancel_order_mirror',
		'fetch_tracking',
		'sync_article',
		'sync_catalog',
		'handle_article_removed',
		'handle_webhook',
		'scheduled_stock_sync',
	);

	/**
	 * Required keys for {@see self::record()}.
	 *
	 * @var list<string>
	 */
	private const REQUIRED_RECORD_FIELDS = array(
		'op_type',
		'related_entity_type',
		'related_entity_id',
		'payload',
	);

	/**
	 * Live `\wpdb` instance (or test double).
	 */
	private \wpdb $wpdb;

	/**
	 * Optional WC-Logger override. The default path goes through
	 * {@see WcLoggerAdapter} which resolves `wc_get_logger()` on each call,
	 * so this property is reserved for the slice-39 / future-slice overrides
	 * that want to capture log lines via a custom sink.
	 */
	private ?\WC_Logger $logger;

	/**
	 * @param \wpdb           $wpdb   Live `\wpdb` (or test double).
	 * @param \WC_Logger|null $logger Optional custom logger override.
	 */
	public function __construct( \wpdb $wpdb, ?\WC_Logger $logger = null )
	{
		$this->wpdb   = $wpdb;
		$this->logger = $logger;
	}

	/**
	 * Insert a fresh failed-op row.
	 *
	 * Validates the four required keys (`op_type`, `related_entity_type`,
	 * `related_entity_id`, `payload`); a missing key produces an
	 * `\InvalidArgumentException` BEFORE any DB write (slice-37 AC-2).
	 *
	 * Audit fields are filled server-side — `created_at` and `last_attempt_at`
	 * use `current_time('mysql', true)` (UTC, slice-37 Constraints) and
	 * `state` defaults to `'unresolved'` unless the caller overrides it.
	 *
	 * `payload` is serialised via `wp_json_encode()` with
	 * `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. Encoding failure
	 * does NOT throw — the row is still written with `payload='[]'` and the
	 * `error_message` prefixed with `'payload_serialize_failed: '` so the
	 * row remains visible to the UI / notifier path (slice-37 Constraints).
	 *
	 * @param array<string, mixed> $args Row arguments. Required keys:
	 *                                   `op_type`, `related_entity_type`,
	 *                                   `related_entity_id`, `payload` (array).
	 *                                   Optional: `error_message`,
	 *                                   `error_code`, `retries_used`, `state`.
	 *
	 * @return int New `id` (always `> 0` on success).
	 *
	 * @throws \InvalidArgumentException When a required field is missing.
	 * @throws \RuntimeException         When `$wpdb->insert()` fails.
	 */
	public function record( array $args ): int
	{
		foreach ( self::REQUIRED_RECORD_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $args ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'FailedOpsRepo::record(): missing required field "%s".', $field )
				);
			}
		}

		$payloadInput   = $args['payload'];
		$errorMessage   = isset( $args['error_message'] ) && is_string( $args['error_message'] )
			? $args['error_message']
			: '';

		$encodedPayload = wp_json_encode(
			$payloadInput,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( false === $encodedPayload || ! is_string( $encodedPayload ) ) {
			// Per slice-37 Constraints: do NOT throw — record an empty payload
			// and prefix `error_message` so the UI surfaces the cause.
			$encodedPayload = '[]';
			$errorMessage   = 'payload_serialize_failed: ' . $errorMessage;
		}

		$nowUtc = (string) current_time( 'mysql', true );

		$row = array(
			'op_type'             => (string) $args['op_type'],
			'related_entity_type' => (string) $args['related_entity_type'],
			'related_entity_id'   => (string) $args['related_entity_id'],
			'payload'             => $encodedPayload,
			'error_message'       => '' !== $errorMessage ? $errorMessage : null,
			'error_code'          => isset( $args['error_code'] ) && is_string( $args['error_code'] )
				? $args['error_code']
				: null,
			'retries_used'        => isset( $args['retries_used'] ) ? (int) $args['retries_used'] : 0,
			'created_at'          => $nowUtc,
			'last_attempt_at'     => $nowUtc,
			'state'               => isset( $args['state'] ) && is_string( $args['state'] )
				? $args['state']
				: self::STATE_UNRESOLVED,
		);

		$format = array(
			'%s', // op_type
			'%s', // related_entity_type
			'%s', // related_entity_id
			'%s', // payload
			'%s', // error_message
			'%s', // error_code
			'%d', // retries_used
			'%s', // created_at
			'%s', // last_attempt_at
			'%s', // state
		);

		$inserted = $this->wpdb->insert( $this->table(), $row, $format );

		if ( false === $inserted || (int) $inserted < 1 ) {
			$lastError = $this->lastWpdbError();
			WcLoggerAdapter::error(
				Sources::FAILURE,
				sprintf( 'FailedOpsRepo::record() insert failed (%s)', $lastError ),
				array(
					'op_type'             => $row['op_type'],
					'related_entity_type' => $row['related_entity_type'],
					'related_entity_id'   => $row['related_entity_id'],
				)
			);
			throw new \RuntimeException(
				sprintf( 'FailedOpsRepo::record(): wpdb insert failed (%s).', $lastError )
			);
		}

		$id = isset( $this->wpdb->insert_id ) ? (int) $this->wpdb->insert_id : 0;

		WcLoggerAdapter::info(
			Sources::FAILURE,
			sprintf( 'FailedOpsRepo::record() inserted id=%d op_type=%s', $id, $row['op_type'] ),
			array(
				'failed_op_id'        => $id,
				'op_type'             => $row['op_type'],
				'related_entity_type' => $row['related_entity_type'],
				'related_entity_id'   => $row['related_entity_id'],
				'retries_used'        => $row['retries_used'],
				'state'               => $row['state'],
			)
		);

		return $id;
	}

	/**
	 * Look up one row by primary key.
	 *
	 * Returns `null` (no throw) when the id does not exist. The `payload`
	 * column is JSON-decoded into a PHP array for caller convenience
	 * (slice-37 AC-3). Decode failure on a malformed value yields `[]` —
	 * the row metadata is still surfaced.
	 *
	 * @return array<string, mixed>|null Assoc-array with all 11 columns, or
	 *                                   `null` when no row matches.
	 */
	public function findById( int $id ): ?array
	{
		if ( $id <= 0 ) {
			return null;
		}

		$sql = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1",
			$id
		);

		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Paginated list of rows, optionally filtered by state and/or op_type.
	 *
	 * Slice 38 / Slice 39 / Slice 40 consumers. The filter map accepts:
	 *   - `state`   string (`unresolved`/`resolved`/`dismissed`) — equality.
	 *   - `op_type` string from {@see self::OP_TYPES} — equality.
	 *
	 * Unknown filter keys/values are silently ignored (defense-in-depth —
	 * the UI validates first, this is the second gate). Result rows are
	 * returned newest-first (`created_at DESC`).
	 *
	 * @param array<string, mixed> $filter Optional `state` / `op_type`.
	 * @param int                  $limit  Page size (clamped to >= 1).
	 * @param int                  $offset OFFSET (clamped to >= 0).
	 *
	 * @return list<array<string, mixed>> Hydrated rows (payload JSON-decoded).
	 */
	public function findAll( array $filter = array(), int $limit = 50, int $offset = 0 ): array
	{
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		$predicates = array();
		$args       = array();

		if ( isset( $filter['state'] ) && is_string( $filter['state'] )
			&& in_array( $filter['state'], array( self::STATE_UNRESOLVED, self::STATE_RESOLVED, self::STATE_DISMISSED ), true ) ) {
			$predicates[] = 'state = %s';
			$args[]       = $filter['state'];
		}

		if ( isset( $filter['op_type'] ) && is_string( $filter['op_type'] )
			&& in_array( $filter['op_type'], self::OP_TYPES, true ) ) {
			$predicates[] = 'op_type = %s';
			$args[]       = $filter['op_type'];
		}

		$where = array() === $predicates
			? ''
			: 'WHERE ' . implode( ' AND ', $predicates );

		$args[] = $limit;
		$args[] = $offset;

		$sql = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$this->table()} {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
			$args
		);

		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->hydrate( $row );
			}
		}

		return $out;
	}

	/**
	 * Per-entity lookup using the composite `idx_related_entity` index
	 * (architecture.md Z. 209).
	 *
	 * WHERE-column ordering is `related_entity_type, related_entity_id, [state]`
	 * which exactly matches the index column-order so MySQL can satisfy the
	 * lookup index-only (slice-37 AC-5 + Constraints).
	 *
	 * Result rows are sorted newest-first.
	 *
	 * @param string      $type     `related_entity_type` value (e.g. `'order'`).
	 * @param string      $entityId `related_entity_id` value (already cast to string).
	 * @param string|null $state    Optional `state` filter; `null` returns all states.
	 *
	 * @return list<array<string, mixed>> Hydrated rows (payload JSON-decoded).
	 */
	public function findByEntity( string $type, string $entityId, ?string $state = null ): array
	{
		if ( '' === $type || '' === $entityId ) {
			return array();
		}

		if ( null === $state ) {
			$sql = $this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table()} "
					. "WHERE related_entity_type = %s AND related_entity_id = %s "
					. 'ORDER BY created_at DESC, id DESC',
				$type,
				$entityId
			);
		} else {
			$sql = $this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table()} "
					. "WHERE related_entity_type = %s AND related_entity_id = %s AND state = %s "
					. 'ORDER BY created_at DESC, id DESC',
				$type,
				$entityId,
				$state
			);
		}

		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->hydrate( $row );
			}
		}

		return $out;
	}

	/**
	 * Flip `state` to `'resolved'` for one row.
	 *
	 * Idempotent: a second call against an already-resolved row produces
	 * `affected_rows = 0` and the method returns `false` without throwing
	 * (slice-37 AC-4).
	 */
	public function markResolved( int $id ): bool
	{
		return $this->updateState( $id, self::STATE_RESOLVED );
	}

	/**
	 * Flip `state` to `'dismissed'` for one row. See {@see self::markResolved()}
	 * for the idempotency contract.
	 */
	public function markDismissed( int $id ): bool
	{
		return $this->updateState( $id, self::STATE_DISMISSED );
	}

	/**
	 * Count rows by `state` (default `unresolved`).
	 *
	 * Slice 46 (Hub-Dashboard) consumes this to render the "X failed ops"
	 * tile. Uses the `idx_state_op_type` index head (architecture.md Z. 208).
	 */
	public function count( string $state = self::STATE_UNRESOLVED ): int
	{
		$sql = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$this->table()} WHERE state = %s",
			$state
		);

		$value = $this->wpdb->get_var( $sql );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Common state-update routine for {@see self::markResolved()} /
	 * {@see self::markDismissed()}.
	 */
	private function updateState( int $id, string $state ): bool
	{
		if ( $id <= 0 ) {
			return false;
		}

		$nowUtc = (string) current_time( 'mysql', true );

		$result = $this->wpdb->update(
			$this->table(),
			array(
				'state'           => $state,
				'last_attempt_at' => $nowUtc,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			WcLoggerAdapter::warning(
				Sources::FAILURE,
				sprintf( 'FailedOpsRepo::updateState(%d, %s) wpdb update failed (%s)', $id, $state, $this->lastWpdbError() ),
				array( 'failed_op_id' => $id, 'state' => $state )
			);
			return false;
		}

		return (int) $result >= 1;
	}

	/**
	 * Decode the `payload` column into a PHP array and leave the rest of the
	 * row unchanged.
	 *
	 * @param array<string, mixed> $row Raw `$wpdb->get_row( ARRAY_A )` output.
	 *
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array
	{
		$payload = $row['payload'] ?? '[]';
		if ( is_string( $payload ) && '' !== $payload ) {
			$decoded = json_decode( $payload, true );
			$row['payload'] = is_array( $decoded ) ? $decoded : array();
		} else {
			$row['payload'] = array();
		}

		// Cast numeric columns for downstream type-safety. WC's $wpdb
		// returns everything as string by default (PDO_MYSQL drops type
		// metadata) — consumers expect ints for id / retries_used.
		if ( isset( $row['id'] ) ) {
			$row['id'] = (int) $row['id'];
		}
		if ( isset( $row['retries_used'] ) ) {
			$row['retries_used'] = (int) $row['retries_used'];
		}

		return $row;
	}

	/**
	 * Resolve the prefixed table name on every call so an externally-mutated
	 * `$wpdb->prefix` (e.g. multisite switch_to_blog) is honoured.
	 */
	private function table(): string
	{
		return $this->wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Defensive `$wpdb->last_error` accessor.
	 */
	private function lastWpdbError(): string
	{
		return property_exists( $this->wpdb, 'last_error' ) && is_string( $this->wpdb->last_error )
			? $this->wpdb->last_error
			: '';
	}
}
