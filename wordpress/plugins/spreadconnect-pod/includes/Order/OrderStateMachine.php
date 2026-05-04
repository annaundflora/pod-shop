<?php
/**
 * Compare-and-set primitive for the `_spreadconnect_state` HPOS Order-Meta.
 *
 * The {@see self::compareAndSet()} method is the **only** authorised mutation
 * path for the `_spreadconnect_state` Order-Meta in the persistent state-set
 * (`submitting`, `NEW`, `CONFIRMED`, `PROCESSED`, `CANCELLED`,
 * `failed_to_submit`). It guarantees that a state transition only succeeds
 * when the current persisted value still matches the caller's expectation —
 * preventing the race in which a late `Order.processed` Webhook has already
 * advanced the state and a subsequent `OrderSubmitJob` success path would
 * otherwise revert it (architecture.md "Risks & Mitigation" -> "HPOS meta
 * write race between webhook handler and outbound submit").
 *
 * Implementation pattern (architecture.md "Constraints & Integrations"
 * Z. 642):
 *
 *   - For `$expected !== ''` (state advancement / lateral move):
 *       a single atomic-ish `UPDATE {$wpdb->prefix}wc_orders_meta SET
 *       meta_value = :target WHERE order_id = :id AND meta_key =
 *       '_spreadconnect_state' AND meta_value = :expected`. Success ⇔
 *       `$wpdb->query()` returns `1` (one row affected).
 *
 *   - For `$expected === ''` (initial transition `pending -> submitting`,
 *       i.e. the meta row is **absent**): a vorgelagertes `SELECT meta_value
 *       ... LIMIT 1` checks whether a row exists. If yes, the CAS is
 *       rejected (`false`). If no, an `INSERT INTO {$wpdb->prefix}
 *       wc_orders_meta (order_id, meta_key, meta_value) VALUES (:id,
 *       '_spreadconnect_state', :target)` is performed. Atomicity for this
 *       read-then-write path relies on Action Scheduler's single-claim
 *       per `order_id` guarantee (architecture.md "Action Scheduler
 *       claim model"), since the HPOS table has no UNIQUE index over
 *       `(order_id, meta_key)`.
 *
 * Side effects on the **success** path:
 *   - A private (non-customer-facing) Order-Note is appended via
 *     `WC_Order::add_order_note()` after the SQL commits.
 *
 * Side effects on the **rejection** path (CAS returned `false`):
 *   - One `info`-level log entry is written via `wc_get_logger()` /
 *     the injected `WC_Logger` with source `spreadconnect-order-service`,
 *     including the actual current state read back from the order. No
 *     `warning`/`error` — a CAS rejection is normal Race-Sieger semantics,
 *     not a fault.
 *
 * Out of scope (deferred to later slices):
 *   - Hook registration / DI wiring (Slice 28 wires the service into
 *     `Bootstrap\Container` and `OrderSubmitJob`).
 *   - Webhook direct-write path for `PROCESSED` / `CANCELLED` (Slice 30 —
 *     either uses CAS with a wildcard expected, or writes directly via
 *     `WC_Order::update_meta_data()`).
 *   - `_spreadconnect_needs_action` flag (orthogonal, Slice 30).
 *   - `WcLoggerAdapter` wrapper (Slice 42 — until then the raw `WC_Logger`
 *     interface is consumed directly).
 *
 * @package SpreadconnectPod\Order
 */

declare(strict_types=1);

namespace SpreadconnectPod\Order;

use InvalidArgumentException;
use WC_Logger;
use WC_Order;
use wpdb;

/**
 * Compare-and-Set service for the `_spreadconnect_state` HPOS Order-Meta.
 *
 * Marked `final` per architecture decision (Service Map Z. 370 — Domain
 * Layer; not extended). Constructor-injectable with the global `$wpdb`
 * instance and an optional `WC_Logger`. Stateless apart from the injected
 * dependencies — every {@see self::compareAndSet()} call is independent and
 * contains its own `wc_get_logger()` fallback when no logger was supplied.
 */
final class OrderStateMachine
{
	/**
	 * HPOS Order-Meta key for the persisted state value.
	 *
	 * Source of truth: architecture.md "WC-Order Meta (HPOS)" Z. 310.
	 */
	public const META_KEY = '_spreadconnect_state';

	/**
	 * State written by `OrderHandler::on_processing` when a `POST /orders`
	 * job is enqueued and the request is in flight (Slice 28).
	 */
	public const STATE_SUBMITTING = 'submitting';

	/**
	 * State written by the `OrderSubmitJob` 2xx-Pfad once Spreadconnect has
	 * accepted the order and returned a Spreadconnect-Order-ID (Slice 28).
	 */
	public const STATE_NEW = 'NEW';

	/**
	 * State written by the `OrderConfirmJob` after `POST /orders/{id}/confirm`
	 * succeeds (Slice 29).
	 */
	public const STATE_CONFIRMED = 'CONFIRMED';

	/**
	 * State written by the `Order.processed` Webhook handler when
	 * Spreadconnect has started production (Slice 30).
	 */
	public const STATE_PROCESSED = 'PROCESSED';

	/**
	 * State written by either the `OrderCancelMirrorJob` (Slice 31) or the
	 * `Order.cancelled` Webhook handler (Slice 30).
	 */
	public const STATE_CANCELLED = 'CANCELLED';

	/**
	 * State written by the `OrderSubmitJob` 4xx-Pfad on permanent submit
	 * failure (Slice 28).
	 */
	public const STATE_FAILED_TO_SUBMIT = 'failed_to_submit';

	/**
	 * Logger source string for `wc_get_logger()`.
	 *
	 * Final per architecture.md "Service Map" Z. 398 -- Failed-Ops
	 * dashboards filter on this exact source.
	 */
	private const LOG_SOURCE = 'spreadconnect-order-service';

	/**
	 * Sentinel meaning "no Meta row currently exists" — accepted only as
	 * `$expected`, never as `$target`.
	 */
	private const SENTINEL_NO_META = '';

	/**
	 * The complete persistent state-set (architecture.md "WC-Order Meta"
	 * Z. 310). Used for both `$expected` and `$target` validation; the
	 * `''` sentinel is **only** valid as `$expected` (separate guard below).
	 *
	 * @var list<string>
	 */
	private const PERSISTENT_STATES = array(
		self::STATE_SUBMITTING,
		self::STATE_NEW,
		self::STATE_CONFIRMED,
		self::STATE_PROCESSED,
		self::STATE_CANCELLED,
		self::STATE_FAILED_TO_SUBMIT,
	);

	/**
	 * Injected `$wpdb` instance — never re-resolved via `global $wpdb`
	 * inside method bodies, to keep the unit-test surface clean (Brain\Monkey
	 * + Mockery mock the property directly).
	 */
	private wpdb $wpdb;

	/**
	 * Optional `WC_Logger`. When `null`, {@see self::log()} falls back to
	 * `wc_get_logger()` per call. Slice 42 will swap this for the
	 * `WcLoggerAdapter` wrapper.
	 */
	private ?WC_Logger $logger;

	/**
	 * @param wpdb           $wpdb   The WP database object (HPOS-Tabelle
	 *                               `{$wpdb->prefix}wc_orders_meta` is the
	 *                               CAS target).
	 * @param WC_Logger|null $logger Optional logger override; `null` =>
	 *                               resolve via `wc_get_logger()` on demand.
	 */
	public function __construct( wpdb $wpdb, ?WC_Logger $logger = null )
	{
		$this->wpdb   = $wpdb;
		$this->logger = $logger;
	}

	/**
	 * Atomically transition the `_spreadconnect_state` of `$order` from
	 * `$expected` to `$target` if and only if the current persisted value
	 * still matches `$expected`.
	 *
	 * Behaviour matrix:
	 *
	 *   | $expected   | DB row exists? | DB value matches? | Outcome |
	 *   |-------------|----------------|-------------------|---------|
	 *   | `''`        | no             | (n/a)             | INSERT, return `true`, add note |
	 *   | `''`        | yes            | (n/a)             | reject, return `false`, info-log |
	 *   | non-empty   | yes            | yes               | UPDATE, return `true`, add note |
	 *   | non-empty   | yes            | no                | reject, return `false`, info-log |
	 *   | non-empty   | no             | (n/a)             | UPDATE matches 0 rows, return `false`, info-log |
	 *
	 * @param WC_Order $order    The order to mutate. The `WC_Order` instance
	 *                           is **not** re-read from the DB after the
	 *                           write — callers that need the fresh state
	 *                           should `wc_get_order( $order->get_id() )`.
	 * @param string   $expected Either one of the six persistent states or
	 *                           the empty string `''` (= "no meta row yet",
	 *                           initial transition).
	 * @param string   $target   One of the six persistent states.
	 *
	 * @return bool `true` iff exactly one row was written; `false` otherwise.
	 *
	 * @throws InvalidArgumentException When `$expected` or `$target` is not
	 *                                  one of the accepted state values.
	 */
	public function compareAndSet( WC_Order $order, string $expected, string $target ): bool
	{
		// AC-4: Validate BEFORE any SQL — invalid state values must NEVER
		// reach the DB layer. `$target` may not be the empty sentinel.
		$this->assertValidExpected( $expected );
		$this->assertValidTarget( $target );

		$orderId  = (int) $order->get_id();
		$metaKey  = self::META_KEY;
		$metaName = $this->wpdb->prefix . 'wc_orders_meta';

		if ( self::SENTINEL_NO_META === $expected ) {
			// AC-3: Initial-Insert path. The HPOS table has NO UNIQUE index
			// over (order_id, meta_key) by default, so we MUST NOT use
			// `INSERT IGNORE` semantics — instead we pre-check via SELECT.
			// Atomicity is provided one layer up by AS single-claim per
			// order_id (architecture.md "AS single-claim per order_id").
			$selectSql = $this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT meta_value FROM {$metaName} WHERE order_id = %d AND meta_key = %s LIMIT 1",
				$orderId,
				$metaKey
			);
			$existing = $this->wpdb->get_var( $selectSql );

			if ( null !== $existing ) {
				// A row exists -> the caller's assumption ("no state yet")
				// is violated. Reject without writing.
				$this->logRejection( $orderId, $expected, $target, (string) $existing );
				return false;
			}

			$insertSql = $this->wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$metaName} (order_id, meta_key, meta_value) VALUES (%d, %s, %s)",
				$orderId,
				$metaKey,
				$target
			);
			$affected = $this->wpdb->query( $insertSql );

			if ( 1 === (int) $affected ) {
				$this->addTransitionNote( $order, $expected, $target );
				return true;
			}

			// Defensive: INSERT failed for an unknown DB reason (e.g. lost
			// connection mid-call). Treat as a rejection so the caller does
			// not assume the write happened.
			$this->logRejection( $orderId, $expected, $target, '' );
			return false;
		}

		// AC-1 / AC-2 / AC-7: Standard CAS path. Atomic-ish single-statement
		// UPDATE, mit allen drei Werten als prepared parameters. Die Kombi
		// aus `WHERE order_id` und `WHERE meta_value = :expected` macht das
		// Statement zum CAS — bei Race-Sieger eines anderen Pfades liefert
		// es 0 affected rows.
		$updateSql = $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$metaName} SET meta_value = %s WHERE order_id = %d AND meta_key = %s AND meta_value = %s",
			$target,
			$orderId,
			$metaKey,
			$expected
		);
		$affected = $this->wpdb->query( $updateSql );

		if ( 1 === (int) $affected ) {
			// AC-5: Order-Note is added AFTER the SQL commits. Not part of
			// CAS atomicity — a failure to add the note does not undo the
			// state transition, and any DB error from the note is swallowed
			// by `WC_Order::add_order_note()` itself.
			$this->addTransitionNote( $order, $expected, $target );
			return true;
		}

		// AC-2 / AC-6: CAS rejected. Read the current state back so the
		// log entry is informative (helps diagnose race-loser scenarios).
		// The read is via `WC_Order::get_meta()` — the canonical HPOS-aware
		// accessor — so HPOS-Mode and legacy-table-Mode are both covered
		// without an extra SQL round-trip.
		$current = (string) $order->get_meta( $metaKey );
		$this->logRejection( $orderId, $expected, $target, $current );
		return false;
	}

	/**
	 * Validate that `$expected` is either one of the persistent states or
	 * the empty-sentinel.
	 *
	 * @throws InvalidArgumentException When `$expected` is not accepted.
	 */
	private function assertValidExpected( string $expected ): void
	{
		if ( self::SENTINEL_NO_META === $expected ) {
			return;
		}
		if ( in_array( $expected, self::PERSISTENT_STATES, true ) ) {
			return;
		}
		throw new InvalidArgumentException(
			sprintf( "OrderStateMachine: invalid state '%s'", $expected )
		);
	}

	/**
	 * Validate that `$target` is one of the persistent states. The empty
	 * sentinel is **not** accepted as a target — there is no "remove the
	 * meta row" transition in this domain.
	 *
	 * @throws InvalidArgumentException When `$target` is not accepted.
	 */
	private function assertValidTarget( string $target ): void
	{
		if ( in_array( $target, self::PERSISTENT_STATES, true ) ) {
			return;
		}
		throw new InvalidArgumentException(
			sprintf( "OrderStateMachine: invalid state '%s'", $target )
		);
	}

	/**
	 * Append a private Order-Note describing the transition.
	 *
	 * Parameter-Order von `WC_Order::add_order_note( $note, $is_customer_note=false,
	 * $added_by_user=false )` wird exakt eingehalten — private note, kein
	 * Customer-E-Mail-Trigger.
	 */
	private function addTransitionNote( WC_Order $order, string $expected, string $target ): void
	{
		$displayedExpected = self::SENTINEL_NO_META === $expected ? 'pending' : $expected;
		$order->add_order_note(
			sprintf( 'Spreadconnect: state %s -> %s', $displayedExpected, $target ),
			false,
			false
		);
	}

	/**
	 * Emit the AC-6 info-level log entry for a rejected CAS.
	 *
	 * Source `spreadconnect-order-service`, level `info` — a CAS rejection
	 * is the **expected** outcome of any race-loser branch and is therefore
	 * NOT logged at warning/error level.
	 */
	private function logRejection( int $orderId, string $expected, string $target, string $current ): void
	{
		$message = sprintf(
			'OrderStateMachine: CAS rejected (order_id=%d, expected=%s, target=%s, current=%s)',
			$orderId,
			$expected,
			$target,
			$current
		);
		$this->log( 'info', $message );
	}

	/**
	 * Resolve the logger and dispatch a single entry.
	 *
	 * Per AC-8: when the constructor was called with `$logger = null` we
	 * fall back to `wc_get_logger()` per call. The fallback is wrapped in
	 * a `function_exists()` guard so unit tests that do not boot the WC
	 * stub library do not trigger an undefined-function fatal.
	 */
	private function log( string $level, string $message ): void
	{
		$logger = $this->logger;
		if ( null === $logger && function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
		}
		if ( null === $logger ) {
			return;
		}
		$logger->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}
}
