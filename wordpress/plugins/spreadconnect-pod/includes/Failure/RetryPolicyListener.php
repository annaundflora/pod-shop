<?php
/**
 * RetryPolicyListener — passive `action_scheduler_failed_action` hook
 * receiver (slice-37).
 *
 * Bridges the Action-Scheduler failure lifecycle to the Plugin's
 * dead-letter-queue ({@see FailedOpsRepo}). The listener is the SINGLE
 * point of integration between the AS retry mechanic and the DLQ — Job
 * handlers (slice-23/24/25/28/29/30/31/36) stay unchanged; they re-throw
 * `SpreadconnectTransientError` for retryable conditions and let
 * `SpreadconnectClientError` escape (permanent).
 *
 * Decision tree per slice-37:
 *
 *   1. Action-hook is one of the 9 plugin hooks (otherwise: early-return).
 *   2. Read the last log entry of `$action_id` to extract the thrown
 *      exception class + message + status code.
 *   3. Permanent (`SpreadconnectClientError`)  → write a DLQ row immediately
 *      (no retry). `error_code = 'http_4xx'`. `retries_used` reflects the
 *      AS-counter at the moment of the call.
 *   4. Transient (`SpreadconnectTransientError`) → only write a DLQ row
 *      once the AS retry-counter has reached 3 (3rd failure consumed).
 *      Below the threshold the listener stays silent and AS keeps retrying
 *      (1m / 5m / 15m, architecture.md Z. 549).
 *   5. Idempotency: re-firing of the same `action_id` (e.g. AS-internal
 *      bookkeeping) does NOT produce a second DLQ row when an `unresolved`
 *      row for the same `(op_type, entity_type, entity_id)` already exists
 *      within a 5-minute window (slice-37 AC-12).
 *
 * The entire `on_action_failed()` body runs inside a `try/catch \Throwable`
 * so a defective AS logger or `$wpdb` failure can never bubble back into
 * the AS lifecycle — the listener is best-effort by design (slice-37
 * Constraints).
 *
 * Architecture refs:
 *   - architecture.md Z. 542-558 (AS-Hook inventory + retry policy).
 *   - architecture.md Z. 401-430 (Outbound Order Submit Flow C —
 *     permanent vs. transient classification).
 *   - architecture.md Z. 457 (`process_webhook_event` → `op_type='handle_webhook'`).
 *
 * @package SpreadconnectPod\Failure
 */

declare(strict_types=1);

namespace SpreadconnectPod\Failure;

use SpreadconnectPod\Api\SpreadconnectClientError;
use SpreadconnectPod\Api\SpreadconnectTransientError;
use SpreadconnectPod\Logging\Sources;
use SpreadconnectPod\Logging\WcLoggerAdapter;

/**
 * Static-free, instance-based AS-failure listener.
 *
 * `final` per slice-37 Constraints. Uses constructor DI for the repo so
 * Brain\Monkey/Mockery tests can inject a `FailedOpsRepo` double; the
 * production wiring lives in `Bootstrap\Plugin::init()`.
 */
final class RetryPolicyListener
{
	/**
	 * Hook prefix shared by every plugin AS action.
	 */
	private const HOOK_PREFIX = 'spreadconnect/';

	/**
	 * Threshold-count of prior failed AS attempts for the same
	 * `(hook, args)` tuple at which a transient error is escalated to a
	 * DLQ row. Below this the AS retry chain (1m/5m/15m) keeps running
	 * uninterrupted.
	 *
	 * Architecture: Z. 425-429 + slim-slices.md Z. 559-566.
	 */
	private const TRANSIENT_RETRY_THRESHOLD = 3;

	/**
	 * Idempotency window (in seconds) used by the AC-12 double-fire guard.
	 * A second invocation for the same (op_type, entity_type, entity_id)
	 * within this window is treated as a duplicate and skipped.
	 */
	private const IDEMPOTENCY_WINDOW_SECONDS = 300; // 5 minutes

	/**
	 * Hook-suffix → `op_type` mapping (slice-37 AC-9).
	 *
	 * Eight of the nine entries are the trivial "strip the prefix" identity
	 * mapping; `process_webhook_event` is the only special-case (renamed to
	 * `handle_webhook` per architecture.md Z. 457).
	 *
	 * @var array<string, string>
	 */
	private const OP_TYPE_MAP = array(
		'sync_catalog'           => 'sync_catalog',
		'sync_article'           => 'sync_article',
		'handle_article_removed' => 'handle_article_removed',
		'create_order'           => 'create_order',
		'confirm_order'          => 'confirm_order',
		'cancel_order_mirror'    => 'cancel_order_mirror',
		'fetch_tracking'         => 'fetch_tracking',
		'process_webhook_event'  => 'handle_webhook',
		'scheduled_stock_sync'   => 'scheduled_stock_sync',
	);

	/**
	 * DLQ repository (constructor-injected).
	 */
	private FailedOpsRepo $repo;

	/**
	 * Optional WC-Logger override. Default-path goes through
	 * {@see WcLoggerAdapter}.
	 */
	private ?\WC_Logger $logger;

	/**
	 * Optional notification dispatcher (slice-39). When `null` the
	 * notify-lane is silently skipped — the AS retry pipeline keeps
	 * working even if `Bootstrap\Plugin::init()` decided not to wire
	 * one. Slice-37's existing tests use `new RetryPolicyListener($repo)`
	 * (no notifier/store) and stay green.
	 */
	private ?FailureNotifier $notifier;

	/**
	 * Optional admin-notice store (slice-39). See {@see self::$notifier}
	 * for the optional-by-design rationale.
	 */
	private ?AdminNoticeStore $noticeStore;

	/**
	 * @param FailedOpsRepo        $repo        DLQ repository.
	 * @param \WC_Logger|null      $logger      Optional logger override.
	 * @param FailureNotifier|null $notifier    Optional notification dispatcher (slice-39).
	 * @param AdminNoticeStore|null $noticeStore Optional admin-notice store (slice-39).
	 */
	public function __construct(
		FailedOpsRepo $repo,
		?\WC_Logger $logger = null,
		?FailureNotifier $notifier = null,
		?AdminNoticeStore $noticeStore = null
	) {
		$this->repo        = $repo;
		$this->logger      = $logger;
		$this->notifier    = $notifier;
		$this->noticeStore = $noticeStore;
	}

	/**
	 * Action-Scheduler hook callback for `action_scheduler_failed_action`.
	 *
	 * Wraps the entire body in `try/catch \Throwable` because the listener
	 * runs INSIDE an AS lifecycle — a thrown exception here would derail
	 * the scheduler's own bookkeeping. Failures are logged and swallowed
	 * (slice-37 Constraints).
	 *
	 * @param int $action_id The Action-Scheduler action id.
	 */
	public function on_action_failed( int $action_id ): void
	{
		try {
			$this->handle( $action_id );
		} catch ( \Throwable $e ) {
			WcLoggerAdapter::error(
				Sources::FAILURE,
				sprintf(
					'RetryPolicyListener::on_action_failed(%d) threw: %s',
					$action_id,
					$e->getMessage()
				),
				array( 'action_id' => $action_id, 'exception' => get_class( $e ) )
			);
		}
	}

	/**
	 * Internal worker — separated from the public entry-point so the
	 * outer `try/catch` body stays minimal.
	 */
	private function handle( int $action_id ): void
	{
		if ( $action_id <= 0 ) {
			return;
		}

		// 1) Resolve the action: hook + args.
		$action = $this->fetchAction( $action_id );
		if ( null === $action ) {
			return; // Unknown action — nothing to record.
		}

		$hook = $this->safeStringOr( $action->get_hook(), '' );
		$args = $this->safeArrayOr( $action->get_args(), array() );

		// 2) Filter on plugin hooks only — fremde Hooks werden ignoriert
		//    (AC-9).
		if ( ! $this->isPluginHook( $hook ) ) {
			return;
		}

		$opType = $this->resolveOpType( $hook );
		if ( null === $opType ) {
			return;
		}

		// 3) Read exception metadata from AS-Logs.
		$exceptionMeta = $this->readExceptionMeta( $action_id );

		// 4) Resolve entity tuple.
		[ $entityType, $entityId ] = $this->resolveEntity( $hook, $args );

		// 5) Count prior failed AS attempts for the same (hook, args) tuple.
		$priorFailed = $this->countPriorFailedAttempts( $hook, $args );

		// 6) Classification.
		$isPermanent = $this->isPermanentExceptionClass( $exceptionMeta['class'] );

		if ( ! $isPermanent && $priorFailed < self::TRANSIENT_RETRY_THRESHOLD ) {
			// AC-7: Transient below threshold → AS retries on its own.
			return;
		}

		// 7) Idempotency check (AC-12).
		if ( $this->recentUnresolvedExists( $opType, $entityType, $entityId ) ) {
			WcLoggerAdapter::debug(
				Sources::FAILURE,
				'failed-op already recorded for this action — skipping duplicate insert',
				array(
					'action_id'           => $action_id,
					'op_type'             => $opType,
					'related_entity_type' => $entityType,
					'related_entity_id'   => $entityId,
				)
			);
			return;
		}

		// 8) Write the DLQ row.
		$errorCode = $this->resolveErrorCode( $exceptionMeta, $isPermanent );

		$insertId = $this->repo->record(
			array(
				'op_type'             => $opType,
				'related_entity_type' => $entityType,
				'related_entity_id'   => $entityId,
				'payload'             => $args,
				'error_message'       => $exceptionMeta['message'],
				'error_code'          => $errorCode,
				'retries_used'        => $priorFailed,
				'state'               => FailedOpsRepo::STATE_UNRESOLVED,
			)
		);

		// 9) Slice-39 notify-lane: dispatch email + persist admin-notice
		//    after a successful DLQ insert. Defensive `?->`-Aufrufe — both
		//    collaborators are optional and slice-39's own try/catch
		//    swallows internal failures so this block cannot bubble.
		if ( $insertId > 0 && ( null !== $this->notifier || null !== $this->noticeStore ) ) {
			$row = $this->repo->findById( $insertId );
			if ( null !== $row ) {
				$this->notifier?->dispatch( $row );
				$this->noticeStore?->add( $row );
			}
		}
	}

	/**
	 * Wrapper around `\ActionScheduler::store()->fetch_action()`.
	 *
	 * Returns `null` when the AS store is unavailable, the action does not
	 * exist or `fetch_action()` returns a null/error sentinel.
	 *
	 * Return type is `?object` (rather than `?\ActionScheduler_Action`) so
	 * test doubles (Mockery `mock(\ActionScheduler_Action::class)` /
	 * Mockery anonymous-mocks) pass the runtime type-check even when the AS
	 * class hierarchy is not loaded (Brain\Monkey unit-test bootstrap).
	 *
	 * @return object|null Object exposes `get_hook(): string` and
	 *                     `get_args(): array` per AS public surface.
	 */
	private function fetchAction( int $action_id ): ?object
	{
		if ( ! class_exists( '\\ActionScheduler' ) ) {
			return null;
		}

		// `\ActionScheduler::store()` is a static factory method. Resolve via
		// `call_user_func` so PHPStan/PHPCS do not complain about the
		// optional global symbol at parse time when AS is absent.
		$store = call_user_func( array( '\\ActionScheduler', 'store' ) );
		if ( null === $store || ! is_object( $store ) || ! method_exists( $store, 'fetch_action' ) ) {
			return null;
		}

		$action = $store->fetch_action( $action_id );

		if ( ! is_object( $action ) ) {
			return null;
		}

		// `fetch_action()` returns a `NullAction` sentinel when the id is
		// unknown — both shapes implement `\ActionScheduler_Action` but
		// `NullAction::get_hook()` returns `''`.
		if ( ! method_exists( $action, 'get_hook' ) ) {
			return null;
		}

		return $action;
	}

	/**
	 * Whether `$hook` starts with `spreadconnect/` AND the suffix is one of
	 * the nine known plugin hooks.
	 */
	private function isPluginHook( string $hook ): bool
	{
		if ( '' === $hook ) {
			return false;
		}

		$prefixLength = strlen( self::HOOK_PREFIX );
		if ( strncmp( $hook, self::HOOK_PREFIX, $prefixLength ) !== 0 ) {
			return false;
		}

		$suffix = substr( $hook, $prefixLength );

		return isset( self::OP_TYPE_MAP[ $suffix ] );
	}

	/**
	 * Extract the suffix after `spreadconnect/` and map to the canonical
	 * `op_type` literal (slice-37 AC-9).
	 */
	private function resolveOpType( string $hook ): ?string
	{
		$prefixLength = strlen( self::HOOK_PREFIX );
		$suffix       = substr( $hook, $prefixLength );

		return self::OP_TYPE_MAP[ $suffix ] ?? null;
	}

	/**
	 * Args-key → entity tuple resolution per slice-37 AC-10.
	 *
	 * Defensive defaults: a missing args-key returns `'unknown'` / `'0'`
	 * rather than throwing — Failed-Ops insertion is best-effort and the
	 * row staying visible (with a placeholder entity) is more valuable than
	 * losing the failure entirely.
	 *
	 * @param string               $hook Full hook name (`spreadconnect/...`).
	 * @param array<string, mixed> $args AS-action args-array.
	 *
	 * @return array{0:string,1:string} Tuple `[entity_type, entity_id]`.
	 */
	private function resolveEntity( string $hook, array $args ): array
	{
		switch ( $hook ) {
			case 'spreadconnect/create_order':
			case 'spreadconnect/confirm_order':
			case 'spreadconnect/cancel_order_mirror':
			case 'spreadconnect/fetch_tracking':
				$id = $args['order_id'] ?? null;
				return array( 'order', $this->coerceEntityId( $id ) );

			case 'spreadconnect/sync_article':
			case 'spreadconnect/handle_article_removed':
				$id = $args['article_id'] ?? null;
				return array( 'article', $this->coerceEntityId( $id ) );

			case 'spreadconnect/sync_catalog':
				$id = $args['run_id'] ?? '0';
				return array( 'system', $this->coerceEntityId( $id ) );

			case 'spreadconnect/process_webhook_event':
				$id = $args['log_id'] ?? null;
				return array( 'webhook', $this->coerceEntityId( $id ) );

			case 'spreadconnect/scheduled_stock_sync':
				return array( 'system', '0' );

			default:
				return array( 'unknown', '0' );
		}
	}

	/**
	 * Cast an arbitrary args-value into a non-empty string entity id.
	 * `null`/`''` collapse to `'unknown'` so the entity row remains
	 * unambiguous for the UI.
	 *
	 * @param mixed $value
	 */
	private function coerceEntityId( $value ): string
	{
		if ( null === $value ) {
			return 'unknown';
		}

		if ( is_scalar( $value ) ) {
			$cast = (string) $value;
			return '' === $cast ? 'unknown' : $cast;
		}

		return 'unknown';
	}

	/**
	 * Read exception metadata (class + message) from the AS log entries
	 * for a given `$action_id`.
	 *
	 * Strategy: walk the log entries newest-first and stop on the first
	 * line whose message contains a fully-qualified class name we recognise
	 * (`SpreadconnectClientError` / `SpreadconnectTransientError`). The
	 * fallback `class = ''` triggers a "treat as transient default" path.
	 *
	 * Action-Scheduler exposes the failure log line as
	 * `"action failed via Action: <class>: <message>"` (or similar) — we
	 * search both the FQCN (with namespace) and the short class name to
	 * stay tolerant of AS string-format variations across versions.
	 *
	 * @return array{class:string, message:string} Best-effort metadata
	 *                                              (empty strings on miss).
	 */
	private function readExceptionMeta( int $action_id ): array
	{
		$default = array( 'class' => '', 'message' => '' );

		if ( ! class_exists( '\\ActionScheduler' ) ) {
			return $default;
		}

		$logger = call_user_func( array( '\\ActionScheduler', 'logger' ) );
		if ( null === $logger || ! is_object( $logger ) || ! method_exists( $logger, 'get_logs' ) ) {
			return $default;
		}

		$logs = $logger->get_logs( $action_id );
		if ( ! is_array( $logs ) && ! ( $logs instanceof \Traversable ) ) {
			return $default;
		}

		// Walk newest entries last (AS stores chronologically); reverse so
		// we hit the failure line first.
		$entries = is_array( $logs ) ? $logs : iterator_to_array( $logs, false );

		$lastMessage = '';
		$lastClass   = '';

		foreach ( array_reverse( $entries ) as $entry ) {
			$message = $this->extractLogMessage( $entry );
			if ( '' === $message ) {
				continue;
			}

			$class = $this->detectExceptionClassInMessage( $message );

			if ( '' !== $class ) {
				return array( 'class' => $class, 'message' => $message );
			}

			// Remember the most-recent message as a fallback; AS may have
			// stored the bare exception message without the FQCN prefix.
			if ( '' === $lastMessage ) {
				$lastMessage = $message;
				$lastClass   = '';
			}
		}

		return array( 'class' => $lastClass, 'message' => $lastMessage );
	}

	/**
	 * Pull a string message out of an AS log entry — the entry surface is
	 * `\ActionScheduler_LogEntry` in production but Mockery doubles often
	 * pass plain assoc-arrays / objects.
	 *
	 * @param mixed $entry
	 */
	private function extractLogMessage( $entry ): string
	{
		if ( is_string( $entry ) ) {
			return $entry;
		}

		if ( is_object( $entry ) && method_exists( $entry, 'get_message' ) ) {
			$value = $entry->get_message();
			return is_string( $value ) ? $value : '';
		}

		if ( is_array( $entry ) && isset( $entry['message'] ) && is_string( $entry['message'] ) ) {
			return $entry['message'];
		}

		return '';
	}

	/**
	 * Look for a known exception-class needle in a free-form AS log line.
	 *
	 * Matches both the namespaced FQCN and the bare classname so the test
	 * harness can pass either form.
	 */
	private function detectExceptionClassInMessage( string $message ): string
	{
		$candidates = array(
			SpreadconnectClientError::class    => SpreadconnectClientError::class,
			SpreadconnectTransientError::class => SpreadconnectTransientError::class,
		);

		foreach ( $candidates as $fqcn ) {
			if ( false !== strpos( $message, $fqcn ) ) {
				return $fqcn;
			}

			$short = $this->classBasename( $fqcn );
			if ( '' !== $short && false !== strpos( $message, $short ) ) {
				return $fqcn;
			}
		}

		return '';
	}

	/**
	 * Final segment of a backslash-namespaced FQCN.
	 */
	private function classBasename( string $fqcn ): string
	{
		$pos = strrpos( $fqcn, '\\' );
		return false === $pos ? $fqcn : substr( $fqcn, $pos + 1 );
	}

	/**
	 * Whether the detected class string represents a permanent client error.
	 */
	private function isPermanentExceptionClass( string $class ): bool
	{
		if ( '' === $class ) {
			return false;
		}

		// `is_a(string, string, allow_string=true)` matches both the exact
		// class and any subclass — defensive for future error-hierarchy
		// extensions.
		return is_a( $class, SpreadconnectClientError::class, true );
	}

	/**
	 * Map (exceptionMeta, isPermanent) to the canonical `error_code`
	 * literal stored in the DLQ row.
	 *
	 * Permanent → `'http_4xx'` (slice-37 AC-6).
	 * Transient → `'http_5xx'` (slice-37 AC-8) with `'transient_error'`
	 * fallback when the message did not surface a recognisable class.
	 *
	 * @param array{class:string, message:string} $exceptionMeta
	 */
	private function resolveErrorCode( array $exceptionMeta, bool $isPermanent ): string
	{
		if ( $isPermanent ) {
			return 'http_4xx';
		}

		if ( '' !== $exceptionMeta['class']
			&& is_a( $exceptionMeta['class'], SpreadconnectTransientError::class, true ) ) {
			return 'http_5xx';
		}

		return 'transient_error';
	}

	/**
	 * Count prior failed AS attempts for the same (hook, args) tuple.
	 *
	 * Uses `as_get_scheduled_actions()` (AS public API). Falls back to `0`
	 * when the function is unavailable (early-bootstrap or test context
	 * without AS) — the listener's transient-threshold gate then collapses
	 * to a permanent-only path, which is the safest default.
	 *
	 * @param string               $hook
	 * @param array<string, mixed> $args
	 */
	private function countPriorFailedAttempts( string $hook, array $args ): int
	{
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'status'   => 'failed',
				'per_page' => 10,
			)
		);

		if ( ! is_array( $actions ) ) {
			return 0;
		}

		return count( $actions );
	}

	/**
	 * AC-12: Has an `unresolved` row for the same
	 * `(op_type, entity_type, entity_id)` been written within the last
	 * {@see self::IDEMPOTENCY_WINDOW_SECONDS} seconds?
	 *
	 * Walks `findByEntity(..., 'unresolved')` and filters client-side on
	 * `created_at` so a single composite-index lookup answers the question
	 * (architecture.md Z. 209).
	 */
	private function recentUnresolvedExists( string $opType, string $entityType, string $entityId ): bool
	{
		$rows = $this->repo->findByEntity( $entityType, $entityId, FailedOpsRepo::STATE_UNRESOLVED );
		if ( array() === $rows ) {
			return false;
		}

		$nowMysql = (string) current_time( 'mysql', true );
		$nowTs    = strtotime( $nowMysql );
		if ( false === $nowTs ) {
			$nowTs = time();
		}

		$cutoffTs = $nowTs - self::IDEMPOTENCY_WINDOW_SECONDS;

		foreach ( $rows as $row ) {
			$rowOpType = isset( $row['op_type'] ) ? (string) $row['op_type'] : '';
			if ( $rowOpType !== $opType ) {
				continue;
			}

			$createdAt = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
			if ( '' === $createdAt ) {
				// Without a timestamp we cannot prove the row is recent;
				// be conservative and treat it as a duplicate to avoid
				// double-records on broken rows.
				return true;
			}

			$createdTs = strtotime( $createdAt );
			if ( false === $createdTs ) {
				continue;
			}

			if ( $createdTs >= $cutoffTs ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Defensive scalar-string coercion.
	 *
	 * @param mixed $value
	 */
	private function safeStringOr( $value, string $default ): string
	{
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return $default;
	}

	/**
	 * Defensive array coercion.
	 *
	 * @param mixed                $value
	 * @param array<string, mixed> $default
	 *
	 * @return array<string, mixed>
	 */
	private function safeArrayOr( $value, array $default ): array
	{
		return is_array( $value ) ? $value : $default;
	}
}
