<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 43 — Purge-Old-Logs-Recurring-Job
//
// Spec: specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/
//       slices/slice-43-purge-old-logs-job.md
//
// Mocking strategy per spec (`mock_external`):
//   - `$wpdb` via `Slice43FakeWpdb` (duck-typed — does NOT extend the
//     strict-typed `\wpdb` stub from tests/stubs/wc-classes.php, because
//     `\wpdb::query()` is declared `int` and AC-10 requires returning a
//     literal `false`). The production code accepts any object via
//     `is_object($wpdb)` (PurgeOldLogsJob.php Z. 131), so duck-typing is
//     contract-faithful.
//   - `as_schedule_recurring_action` / `as_next_scheduled_action` /
//     `get_option` aliased via Brain\Monkey.
//   - `wc_get_logger` aliased to a `Slice43LoggerSpy` so AC-9 / AC-10
//     can introspect the source / message / context that
//     {@see WcLoggerAdapter::info()} / `::error()` forward.
//   - `time()` is a built-in (non-aliasable via Brain\Monkey reliably);
//     the cutoff assertions thus use a tolerance window around the
//     production formula `gmdate('Y-m-d H:i:s', time() - $days*DAY_IN_SECONDS)`
//     rather than an exact-match on a frozen clock. This is robust against
//     jitter in CI and matches the existing project pattern (slice-37).
// ---------------------------------------------------------------------------

namespace {

	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		// Mirror of the WP constant (wp-includes/default-constants.php).
		define( 'DAY_IN_SECONDS', 24 * 60 * 60 );
	}

	// -------------------------------------------------------------------
	// Recording `wpdb` double for slice-43.
	//
	// Captures `prepare($sql, ...$args)` + `query($sql)` calls and returns
	// programmable per-call values from `$queryReturns`. Mirrors the
	// slice-37 FakeWpdb shape conceptually, but does NOT extend the
	// shared `\wpdb` stub: `\wpdb::query(): int` would forbid the literal
	// `false`-return that AC-10 asserts on.
	// -------------------------------------------------------------------
	if ( ! class_exists( 'Slice43FakeWpdb', false ) ) {
		final class Slice43FakeWpdb
		{
			public string $prefix = 'wp_';

			public string $last_error = '';

			public int $rows_affected = 0;

			public int $insert_id = 0;

			/**
			 * Pre-programmed return values for `query()`, in call-order.
			 * Each `query()` invocation pops the head off this list and
			 * returns it. Missing entries default to `0`.
			 *
			 * @var list<int|false>
			 */
			public array $queryReturns = array();

			/**
			 * Captured SQL strings passed to `query()`, in call-order.
			 *
			 * @var list<string>
			 */
			public array $queryCalls = array();

			/**
			 * Captured `prepare()` invocations: the literal template SQL +
			 * the args array (un-substituted, so AC-8 can verify the
			 * `%s`-placeholder shape).
			 *
			 * @var list<array{sql:string,args:array<int,mixed>}>
			 */
			public array $prepareCalls = array();

			public function prepare( string $sql, ...$args ): string
			{
				$this->prepareCalls[] = array(
					'sql'  => $sql,
					'args' => $args,
				);

				// Substitute `%s` / `%d` placeholders so query()-callers see
				// the realised SQL string. Single-shot loop mirrors slice-37.
				$out = $sql;
				foreach ( $args as $arg ) {
					$replacement = is_int( $arg )
						? (string) $arg
						: "'" . str_replace( "'", "''", (string) $arg ) . "'";
					$out         = preg_replace( '/%[ds]/', $replacement, $out, 1 ) ?? $out;
				}
				return $out;
			}

			/**
			 * @return int|false
			 */
			public function query( string $query )
			{
				$this->queryCalls[] = $query;

				if ( array_key_exists( 0, $this->queryReturns ) ) {
					$next = array_shift( $this->queryReturns );
					if ( false === $next ) {
						return false;
					}
					if ( is_int( $next ) ) {
						$this->rows_affected = $next;
						return $next;
					}
				}

				$this->rows_affected = 0;
				return 0;
			}
		}
	}
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use Mockery;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Bootstrap\Plugin;
	use SpreadconnectPod\Logging\PurgeOldLogsJob;
	use SpreadconnectPod\Logging\Sources;

	/**
	 * Slice 43 — Purge-Old-Logs-Recurring-Job acceptance tests.
	 *
	 * Each test maps 1:1 to a GIVEN/WHEN/THEN from
	 * `slice-43-purge-old-logs-job.md`. The Spec's Test-Skeletons name
	 * the public surface; the bodies below provide the live
	 * Brain\Monkey + `Slice43FakeWpdb` wiring per AC.
	 */
	final class Slice43PurgeOldLogsJobTest extends TestCase
	{
		/**
		 * Captured `as_next_scheduled_action()` invocations (slice-43 AC-1).
		 *
		 * @var list<array{hook:string,args:mixed,group:mixed}>
		 */
		private array $nextScheduledCalls = [];

		/**
		 * Captured `as_schedule_recurring_action()` invocations (slice-43 AC-1).
		 *
		 * @var list<array{timestamp:mixed,interval:mixed,hook:string,args:mixed,group:mixed}>
		 */
		private array $scheduleRecurringCalls = [];

		/**
		 * Programmable return for `as_next_scheduled_action()`. Anything
		 * truthy means "already scheduled" — the activation hook MUST then
		 * skip the recurring re-schedule (AC-1 idempotency).
		 *
		 * @var int|false|null
		 */
		private $nextScheduledReturn = false;

		/**
		 * Backing store for `get_option()` lookups inside `handle()`.
		 *
		 * @var array<string,mixed>
		 */
		private array $optionStore = [];

		/**
		 * Captured logger-spy entries (info/warning/error/debug/log).
		 *
		 * @var list<array{level:string,message:string,context:array<string,mixed>}>
		 */
		private array $loggerEntries = [];

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->nextScheduledCalls     = [];
			$this->scheduleRecurringCalls = [];
			$this->nextScheduledReturn    = false;
			$this->optionStore            = [];
			$this->loggerEntries          = [];

			// ---- i18n passthrough ------------------------------------------
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'esc_attr__' )->returnArg( 1 );

			// ---- Action-Scheduler aliases (AC-1) ---------------------------
			$nextCalls    = & $this->nextScheduledCalls;
			$nextReturn   = & $this->nextScheduledReturn;
			Functions\when( 'as_next_scheduled_action' )->alias(
				static function ( string $hook, $args = null, $group = null ) use ( &$nextCalls, &$nextReturn ) {
					$nextCalls[] = [
						'hook'  => $hook,
						'args'  => $args,
						'group' => $group,
					];
					return $nextReturn;
				}
			);

			$scheduleCalls = & $this->scheduleRecurringCalls;
			Functions\when( 'as_schedule_recurring_action' )->alias(
				static function ( $timestamp, $interval, string $hook, $args = [], $group = '' ) use ( &$scheduleCalls ): int {
					$scheduleCalls[] = [
						'timestamp' => $timestamp,
						'interval'  => $interval,
						'hook'      => $hook,
						'args'      => $args,
						'group'     => $group,
					];
					return 1;
				}
			);

			// ---- get_option (AC-3..AC-7) -----------------------------------
			$store = & $this->optionStore;
			Functions\when( 'get_option' )->alias(
				static function ( $name, $default = false ) use ( &$store ) {
					if ( array_key_exists( (string) $name, $store ) ) {
						return $store[ (string) $name ];
					}
					return $default;
				}
			);

			// ---- wc_get_logger -> spy (AC-9 / AC-10) -----------------------
			$entries = & $this->loggerEntries;
			Functions\when( 'wc_get_logger' )->alias(
				static function () use ( &$entries ) {
					return new Slice43LoggerSpy( $entries );
				}
			);

			// Reset Plugin-internal state so AC-2 idempotency stays clean
			// across tests that share this class instance.
			$this->resetPluginState();

			// Ensure no stale `$wpdb` global from a previous test sneaks in.
			unset( $GLOBALS['wpdb'] );
		}

		protected function tearDown(): void
		{
			$this->resetPluginState();
			unset( $GLOBALS['wpdb'] );
			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// ===================================================================
		// Helpers
		// ===================================================================

		private function resetPluginState(): void
		{
			$pluginFqcn = Plugin::class;
			if ( class_exists( $pluginFqcn ) ) {
				$ref = new ReflectionClass( $pluginFqcn );
				if ( $ref->hasProperty( 'initialized' ) ) {
					$ref->getProperty( 'initialized' )->setValue( null, false );
				}
				if ( $ref->hasProperty( 'pluginFile' ) ) {
					$ref->getProperty( 'pluginFile' )->setValue( null, '' );
				}
			}
		}

		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}

		private static function pluginMainFile(): string
		{
			return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';
		}

		private function installWpdb( ?\Slice43FakeWpdb $wpdb = null ): \Slice43FakeWpdb
		{
			$wpdb            = $wpdb ?? new \Slice43FakeWpdb();
			$GLOBALS['wpdb'] = $wpdb;
			return $wpdb;
		}

		/**
		 * Filter logger entries by source-string for cleaner per-AC asserts.
		 *
		 * @return list<array{level:string,message:string,context:array<string,mixed>}>
		 */
		private function entriesForSource( string $source ): array
		{
			$out = [];
			foreach ( $this->loggerEntries as $entry ) {
				if ( ( $entry['context']['source'] ?? null ) === $source ) {
					$out[] = $entry;
				}
			}
			return $out;
		}

		/**
		 * Assert that the captured cutoff string is within `$toleranceSeconds`
		 * of the expected `now - $days*DAY_IN_SECONDS` baseline (computed at
		 * the moment of assertion, NOT inside production).
		 */
		private function assertCutoffWithinTolerance(
			string $captured,
			int $days,
			int $toleranceSeconds = 30,
			string $message = ''
		): void {
			$capturedTs = strtotime( $captured . ' UTC' );
			$this->assertNotFalse(
				$capturedTs,
				$message . ' (captured cutoff "' . $captured . '" MUST be parseable as UTC datetime)'
			);

			$expected = time() - ( $days * DAY_IN_SECONDS );
			$delta    = abs( $capturedTs - $expected );
			$this->assertLessThanOrEqual(
				$toleranceSeconds,
				$delta,
				$message . sprintf(
					' (cutoff drift %ds exceeds tolerance %ds for retention=%dd)',
					$delta,
					$toleranceSeconds,
					$days
				)
			);
		}

		// ===================================================================
		// AC-1: Activate registriert Recurring-Action genau einmal.
		//
		// GIVEN Plugin-Aktivierung wird ausgeloest
		// WHEN  der Activate-Code prueft as_next_scheduled_action(...)
		// THEN  bei Rueckgabe `false` genau einmal as_schedule_recurring_action(
		//         time(), DAY_IN_SECONDS, 'spreadconnect/purge_old_logs', [],
		//         'spreadconnect') aufgerufen.
		// ===================================================================

		public function test_activate_schedules_recurring_action_when_not_yet_scheduled(): void
		{
			// Pre-condition: AS reports "not yet scheduled".
			$this->nextScheduledReturn = false;

			Plugin::scheduleRecurringPurgeOldLogs();

			$this->assertCount(
				1,
				$this->nextScheduledCalls,
				'AC-1: as_next_scheduled_action MUSS GENAU EINMAL aufgerufen werden ' .
				'(Idempotenz-Pre-Check vor as_schedule_recurring_action).'
			);
			$this->assertSame(
				PurgeOldLogsJob::HOOK,
				$this->nextScheduledCalls[0]['hook'],
				'AC-1: as_next_scheduled_action MUSS mit dem hook "spreadconnect/purge_old_logs" gepruegt werden.'
			);
			$this->assertSame(
				'spreadconnect/purge_old_logs',
				$this->nextScheduledCalls[0]['hook'],
				'AC-1: Hook-String MUSS exakt "spreadconnect/purge_old_logs" sein (architecture.md Z. 556).'
			);
			$this->assertSame(
				PurgeOldLogsJob::AS_GROUP,
				$this->nextScheduledCalls[0]['group'],
				'AC-1: Idempotenz-Check MUSS group="spreadconnect" passieren (architecture.md Z. 558).'
			);

			$this->assertCount(
				1,
				$this->scheduleRecurringCalls,
				'AC-1: as_schedule_recurring_action MUSS bei not-yet-scheduled GENAU EINMAL laufen.'
			);

			$call = $this->scheduleRecurringCalls[0];
			$this->assertIsInt(
				$call['timestamp'],
				'AC-1: timestamp-Argument MUSS ein int sein (Resultat von time()).'
			);
			$this->assertSame(
				DAY_IN_SECONDS,
				$call['interval'],
				'AC-1: interval MUSS exakt DAY_IN_SECONDS (86400) sein (architecture.md Z. 556 "Recurring (daily)").'
			);
			$this->assertSame(
				'spreadconnect/purge_old_logs',
				$call['hook'],
				'AC-1: hook-Name MUSS exakt "spreadconnect/purge_old_logs" sein.'
			);
			$this->assertSame(
				[],
				$call['args'],
				'AC-1: args MUSS leeres Array sein (Recurring-Hook hat keine Parameter).'
			);
			$this->assertSame(
				'spreadconnect',
				$call['group'],
				'AC-1: AS-Group-Slug MUSS "spreadconnect" sein (architecture.md Z. 558).'
			);
		}

		// AC-1 (Idempotenz): Re-Activate plant nicht doppelt.
		public function test_activate_is_idempotent_when_action_already_scheduled(): void
		{
			// Pre-condition: AS reports "already scheduled" (timestamp).
			$this->nextScheduledReturn = 99999;

			Plugin::scheduleRecurringPurgeOldLogs();

			$this->assertCount(
				1,
				$this->nextScheduledCalls,
				'AC-1: as_next_scheduled_action MUSS auch im Re-Activate-Pfad GENAU EINMAL gepruegt werden.'
			);
			$this->assertSame(
				[],
				$this->scheduleRecurringCalls,
				'AC-1 PFLICHT: Re-Activate DARF KEIN as_schedule_recurring_action ausloesen — Idempotenz.'
			);
		}

		// ===================================================================
		// AC-2: Action-Hook ist auf handle() registriert mit Group spreadconnect.
		//
		// GIVEN Plugin-Bootstrap (Plugin::init())
		// WHEN  der Action-Hook registriert wird
		// THEN  add_action('spreadconnect/purge_old_logs',
		//         [PurgeOldLogsJob::class, 'handle']) ist aktiv.
		// ===================================================================

		public function test_action_hook_is_registered_on_init(): void
		{
			$jobFqcn = PurgeOldLogsJob::class;

			// Pre-condition: no listener for the hook before init().
			$this->assertFalse(
				Actions\has( PurgeOldLogsJob::HOOK ),
				'AC-2 (precondition): KEIN Listener fuer "spreadconnect/purge_old_logs" vor Plugin::init().'
			);

			Plugin::init( self::pluginMainFile() );

			$priority = Actions\has(
				PurgeOldLogsJob::HOOK,
				[ $jobFqcn, 'handle' ]
			);

			$this->assertNotFalse(
				$priority,
				'AC-2: Plugin::init() MUSS add_action("spreadconnect/purge_old_logs", '
				. '[PurgeOldLogsJob::class, "handle"]) registrieren.'
			);

			// AC-2 also confirms the AS-Group is "spreadconnect" — that
			// happens at SCHEDULE-time (via scheduleRecurringPurgeOldLogs())
			// not at add_action()-time. We verify the schedule constant
			// indirectly by asserting the production class exposes the
			// right group constant.
			$this->assertSame(
				'spreadconnect',
				PurgeOldLogsJob::AS_GROUP,
				'AC-2: PurgeOldLogsJob::AS_GROUP MUSS exakt "spreadconnect" sein (architecture.md Z. 558).'
			);
		}

		// AC-2 (Idempotenz): Doppel-Init darf den Hook nicht doppelt registrieren.
		public function test_action_hook_registration_is_idempotent(): void
		{
			$jobFqcn = PurgeOldLogsJob::class;

			Plugin::init( self::pluginMainFile() );
			$priorityFirst = Actions\has( PurgeOldLogsJob::HOOK, [ $jobFqcn, 'handle' ] );

			Plugin::init( self::pluginMainFile() );
			$prioritySecond = Actions\has( PurgeOldLogsJob::HOOK, [ $jobFqcn, 'handle' ] );

			$this->assertSame(
				$priorityFirst,
				$prioritySecond,
				'AC-2: Doppelter Plugin::init()-Call DARF die Hook-Registrierung NICHT duplizieren ' .
				'(Idempotenz-Guard aus Slice 02 AC-5).'
			);
		}

		// ===================================================================
		// AC-3: webhook_log DELETE mit cutoff = now-90d, strict <.
		//
		// GIVEN Option spreadconnect_webhook_log_retention_days = 90
		// WHEN  PurgeOldLogsJob::handle() ausgefuehrt wird
		// THEN  genau ein DELETE auf wp_spreadconnect_webhook_log mit
		//       Where-Clause `received_at < {cutoff}` (cutoff = now - 90 days).
		// ===================================================================

		public function test_handle_deletes_webhook_log_rows_older_than_retention(): void
		{
			$this->optionStore['spreadconnect_webhook_log_retention_days'] = 90;
			$this->optionStore['spreadconnect_failed_ops_retention_days']  = 90;

			$wpdb               = $this->installWpdb();
			$wpdb->queryReturns = [ 1, 0 ]; // 1 row deleted from webhook_log, 0 from failed_ops

			PurgeOldLogsJob::handle();

			// Find the webhook_log DELETE call.
			$webhookCalls = array_values(
				array_filter(
					$wpdb->queryCalls,
					static fn ( string $sql ): bool => str_contains( $sql, 'spreadconnect_webhook_log' )
				)
			);

			$this->assertCount(
				1,
				$webhookCalls,
				'AC-3: GENAU EIN DELETE auf wp_spreadconnect_webhook_log MUSS ausgefuehrt werden.'
			);

			$sql = $webhookCalls[0];
			$this->assertStringContainsString(
				'DELETE FROM',
				$sql,
				'AC-3: Statement MUSS ein DELETE sein.'
			);
			$this->assertMatchesRegularExpression(
				'/wp_spreadconnect_webhook_log/i',
				$sql,
				'AC-3: Tabellenname MUSS wp_spreadconnect_webhook_log sein (prefix "wp_" + suffix).'
			);
			$this->assertMatchesRegularExpression(
				'/received_at\s*<\s*/',
				$sql,
				'AC-3: Where-Clause MUSS `received_at < {cutoff}` sein — STRIKT `<`, nicht `<=`.'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/received_at\s*<=/',
				$sql,
				'AC-3: Where-Clause DARF NICHT `<=` verwenden — boundary-row at exact cutoff bleibt erhalten.'
			);

			// Verify the cutoff value passed to prepare()/the SQL is roughly
			// `now - 90 days` (UTC). The production code interpolates the
			// cutoff into the `%s` placeholder via prepare(), and our fake
			// wpdb's prepare() echoes the substituted SQL back into queryCalls.
			$this->assertCount(
				2,
				$wpdb->prepareCalls,
				'AC-3: prepare() MUSS GENAU EINMAL pro Tabelle aufgerufen werden ' .
				'(insgesamt 2 = webhook_log + failed_ops).'
			);
			$webhookPrepare = array_values(
				array_filter(
					$wpdb->prepareCalls,
					static fn ( array $call ): bool => str_contains( $call['sql'], 'spreadconnect_webhook_log' )
				)
			);
			$this->assertCount(
				1,
				$webhookPrepare,
				'AC-3: GENAU EIN prepare()-Call darf die webhook_log-Tabelle adressieren.'
			);
			$cutoffArg = $webhookPrepare[0]['args'][0] ?? '';
			$this->assertIsString(
				$cutoffArg,
				'AC-3: cutoff-Wert MUSS ein String (MySQL DATETIME) sein.'
			);
			$this->assertCutoffWithinTolerance(
				(string) $cutoffArg,
				90,
				30,
				'AC-3: cutoff MUSS ungefaehr `now - 90 days` (UTC) sein.'
			);
		}

		// AC-3: Boundary-row — strikt `<` (NICHT `<=`).
		public function test_handle_keeps_webhook_log_rows_at_or_younger_than_cutoff(): void
		{
			$this->optionStore['spreadconnect_webhook_log_retention_days'] = 90;
			$this->optionStore['spreadconnect_failed_ops_retention_days']  = 90;

			$wpdb               = $this->installWpdb();
			$wpdb->queryReturns = [ 1, 0 ];

			PurgeOldLogsJob::handle();

			// All webhook_log SQL strings must use strict `<`, never `<=`.
			$webhookCalls = array_values(
				array_filter(
					$wpdb->queryCalls,
					static fn ( string $sql ): bool => str_contains( $sql, 'spreadconnect_webhook_log' )
				)
			);
			$this->assertCount(
				1,
				$webhookCalls,
				'AC-3: GENAU EIN DELETE-Statement fuer webhook_log.'
			);

			foreach ( $webhookCalls as $sql ) {
				$this->assertDoesNotMatchRegularExpression(
					'/received_at\s*<=/',
					$sql,
					'AC-3 PFLICHT: Where-Clause DARF NICHT `<=` sein — Rows AT cutoff (now-90d) ' .
					'MUESSEN erhalten bleiben.'
				);
				$this->assertMatchesRegularExpression(
					'/received_at\s*<\s*[\'"]?\d/',
					$sql,
					'AC-3 PFLICHT: Where-Clause MUSS `received_at < {cutoff}` mit strikt `<` sein.'
				);
			}
		}

		// ===================================================================
		// AC-4: failed_ops DELETE mit eigenem cutoff.
		//
		// GIVEN Option spreadconnect_failed_ops_retention_days = 90
		// WHEN  handle() ausgefuehrt wird
		// THEN  genau ein DELETE auf wp_spreadconnect_failed_ops mit
		//       Where-Clause `created_at < {cutoff}`.
		// ===================================================================

		public function test_handle_deletes_failed_ops_rows_older_than_retention(): void
		{
			$this->optionStore['spreadconnect_webhook_log_retention_days'] = 90;
			$this->optionStore['spreadconnect_failed_ops_retention_days']  = 90;

			$wpdb               = $this->installWpdb();
			$wpdb->queryReturns = [ 0, 1 ];

			PurgeOldLogsJob::handle();

			$failedCalls = array_values(
				array_filter(
					$wpdb->queryCalls,
					static fn ( string $sql ): bool => str_contains( $sql, 'spreadconnect_failed_ops' )
				)
			);

			$this->assertCount(
				1,
				$failedCalls,
				'AC-4: GENAU EIN DELETE auf wp_spreadconnect_failed_ops MUSS ausgefuehrt werden.'
			);

			$sql = $failedCalls[0];
			$this->assertStringContainsString(
				'DELETE FROM',
				$sql,
				'AC-4: Statement MUSS ein DELETE sein.'
			);
			$this->assertMatchesRegularExpression(
				'/wp_spreadconnect_failed_ops/i',
				$sql,
				'AC-4: Tabellenname MUSS wp_spreadconnect_failed_ops sein.'
			);
			$this->assertMatchesRegularExpression(
				'/created_at\s*<\s*/',
				$sql,
				'AC-4: Where-Clause MUSS `created_at < {cutoff}` sein — failed_ops nutzt `created_at`, ' .
				'NICHT `received_at` (architecture.md Z. 229 idx_created_at).'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/created_at\s*<=/',
				$sql,
				'AC-4: Where-Clause DARF NICHT `<=` sein.'
			);

			$failedPrepare = array_values(
				array_filter(
					$wpdb->prepareCalls,
					static fn ( array $call ): bool => str_contains( $call['sql'], 'spreadconnect_failed_ops' )
				)
			);
			$this->assertCount(
				1,
				$failedPrepare,
				'AC-4: GENAU EIN prepare()-Call darf die failed_ops-Tabelle adressieren.'
			);
			$cutoffArg = $failedPrepare[0]['args'][0] ?? '';
			$this->assertCutoffWithinTolerance(
				(string) $cutoffArg,
				90,
				30,
				'AC-4: cutoff MUSS ungefaehr `now - 90 days` (UTC) sein.'
			);
		}

		// ===================================================================
		// AC-5: Zwei verschiedene Retention-Werte -> zwei verschiedene Cutoffs.
		//
		// GIVEN webhook_log_retention_days=30, failed_ops_retention_days=180
		// WHEN  handle() ausgefuehrt wird
		// THEN  jedes der zwei DELETE-Statements verwendet seinen EIGENEN
		//       Cutoff (30d fuer webhook_log, 180d fuer failed_ops).
		// ===================================================================

		public function test_handle_uses_independent_cutoffs_per_table(): void
		{
			$this->optionStore['spreadconnect_webhook_log_retention_days'] = 30;
			$this->optionStore['spreadconnect_failed_ops_retention_days']  = 180;

			$wpdb               = $this->installWpdb();
			$wpdb->queryReturns = [ 0, 0 ];

			PurgeOldLogsJob::handle();

			// Resolve the two cutoffs from the prepare() calls.
			$webhookPrepare = array_values(
				array_filter(
					$wpdb->prepareCalls,
					static fn ( array $call ): bool => str_contains( $call['sql'], 'spreadconnect_webhook_log' )
				)
			);
			$failedPrepare = array_values(
				array_filter(
					$wpdb->prepareCalls,
					static fn ( array $call ): bool => str_contains( $call['sql'], 'spreadconnect_failed_ops' )
				)
			);

			$this->assertCount(
				1,
				$webhookPrepare,
				'AC-5: GENAU EIN prepare()-Call fuer webhook_log.'
			);
			$this->assertCount(
				1,
				$failedPrepare,
				'AC-5: GENAU EIN prepare()-Call fuer failed_ops.'
			);

			$webhookCutoff = (string) ( $webhookPrepare[0]['args'][0] ?? '' );
			$failedCutoff  = (string) ( $failedPrepare[0]['args'][0] ?? '' );

			$this->assertCutoffWithinTolerance(
				$webhookCutoff,
				30,
				30,
				'AC-5: webhook_log cutoff MUSS `now - 30 days` (UTC) sein.'
			);
			$this->assertCutoffWithinTolerance(
				$failedCutoff,
				180,
				30,
				'AC-5: failed_ops cutoff MUSS `now - 180 days` (UTC) sein.'
			);

			$this->assertNotSame(
				$webhookCutoff,
				$failedCutoff,
				'AC-5 PFLICHT: Cutoffs MUESSEN unterschiedlich sein — kein Cross-Use der Retention-Werte.'
			);

			// Verify temporal ordering: 180d cutoff lies further in the past
			// than the 30d cutoff (i.e. failedTs < webhookTs).
			$webhookTs = strtotime( $webhookCutoff . ' UTC' );
			$failedTs  = strtotime( $failedCutoff . ' UTC' );
			$this->assertLessThan(
				$webhookTs,
				$failedTs,
				'AC-5: failed_ops cutoff (180d) MUSS in der Vergangenheit weiter zurueckliegen als ' .
				'webhook_log cutoff (30d).'
			);
		}

		// ===================================================================
		// AC-6: Fehlende Option faellt auf Default 90 zurueck.
		//
		// GIVEN get_option liefert false/''/null
		// WHEN  handle() den Cutoff berechnet
		// THEN  Architecture-Default 90 wird verwendet.
		// ===================================================================

		public function test_handle_falls_back_to_default_retention_when_option_missing(): void
		{
			// Option-Store ist absichtlich leer -> get_option() returns
			// the architecture default `90` (passed in by production code).
			$this->optionStore = [];

			$wpdb               = $this->installWpdb();
			$wpdb->queryReturns = [ 0, 0 ];

			PurgeOldLogsJob::handle();

			$this->assertCount(
				2,
				$wpdb->prepareCalls,
				'AC-6: prepare() MUSS GENAU 2-mal aufgerufen werden (eines pro Tabelle) — ' .
				'der fehlende Option-Wert DARF NICHT in cutoff=0 oder PHP-warning enden.'
			);

			foreach ( $wpdb->prepareCalls as $call ) {
				$cutoffArg = (string) ( $call['args'][0] ?? '' );
				$this->assertCutoffWithinTolerance(
					$cutoffArg,
					90,
					30,
					'AC-6: cutoff bei missing Option MUSS auf den Default 90d zurueckfallen.'
				);
			}
		}

		// AC-6: explicit `false` from get_option (auch ein gueltiger Default-Trigger).
		public function test_handle_falls_back_to_default_when_get_option_returns_false(): void
		{
			$this->optionStore['spreadconnect_webhook_log_retention_days'] = false;
			$this->optionStore['spreadconnect_failed_ops_retention_days']  = false;

			$wpdb               = $this->installWpdb();
			$wpdb->queryReturns = [ 0, 0 ];

			PurgeOldLogsJob::handle();

			$this->assertCount(
				2,
				$wpdb->prepareCalls,
				'AC-6: false-Wert aus get_option MUSS auf den 90d-Default fallen, KEIN PHP-Warning.'
			);
			foreach ( $wpdb->prepareCalls as $call ) {
				$cutoffArg = (string) ( $call['args'][0] ?? '' );
				$this->assertCutoffWithinTolerance(
					$cutoffArg,
					90,
					30,
					'AC-6: false-Option-Wert -> cutoff MUSS dem 90d-Default entsprechen.'
				);
			}
		}

		// ===================================================================
		// AC-7: Out-of-range Retention wird auf [7..365] geclamped.
		//
		// GIVEN Retention-Wert <7 oder >365
		// WHEN  handle() aufgerufen wird
		// THEN  Wert wird auf [7..365] geclamped.
		// ===================================================================

		public function test_handle_clamps_retention_value_into_allowed_range(): void
		{
			// Below-min: 1 -> clamped to 7.
			$this->optionStore['spreadconnect_webhook_log_retention_days'] = 1;
			// Above-max: 9999 -> clamped to 365.
			$this->optionStore['spreadconnect_failed_ops_retention_days']  = 9999;

			$wpdb               = $this->installWpdb();
			$wpdb->queryReturns = [ 0, 0 ];

			PurgeOldLogsJob::handle();

			$webhookPrepare = array_values(
				array_filter(
					$wpdb->prepareCalls,
					static fn ( array $call ): bool => str_contains( $call['sql'], 'spreadconnect_webhook_log' )
				)
			);
			$failedPrepare = array_values(
				array_filter(
					$wpdb->prepareCalls,
					static fn ( array $call ): bool => str_contains( $call['sql'], 'spreadconnect_failed_ops' )
				)
			);

			$this->assertCount( 1, $webhookPrepare, 'AC-7: webhook_log prepare()-Call.' );
			$this->assertCount( 1, $failedPrepare, 'AC-7: failed_ops prepare()-Call.' );

			$webhookCutoff = (string) ( $webhookPrepare[0]['args'][0] ?? '' );
			$failedCutoff  = (string) ( $failedPrepare[0]['args'][0] ?? '' );

			$this->assertCutoffWithinTolerance(
				$webhookCutoff,
				7,
				30,
				'AC-7: Retention=1 MUSS auf 7d (lower bound) geclamped werden.'
			);
			$this->assertCutoffWithinTolerance(
				$failedCutoff,
				365,
				30,
				'AC-7: Retention=9999 MUSS auf 365d (upper bound) geclamped werden.'
			);
		}

		// AC-7: zero / negative — also clamped up to 7.
		public function test_handle_clamps_zero_retention_up_to_lower_bound(): void
		{
			$this->optionStore['spreadconnect_webhook_log_retention_days'] = 0;
			$this->optionStore['spreadconnect_failed_ops_retention_days']  = -42;

			$wpdb               = $this->installWpdb();
			$wpdb->queryReturns = [ 0, 0 ];

			PurgeOldLogsJob::handle();

			foreach ( $wpdb->prepareCalls as $call ) {
				$cutoffArg = (string) ( $call['args'][0] ?? '' );
				$this->assertCutoffWithinTolerance(
					$cutoffArg,
					7,
					30,
					'AC-7: Retention=0 oder negative MUSS auf 7d geclamped werden.'
				);
			}
		}

		// ===================================================================
		// AC-8: SQL nutzt $wpdb->prefix + prepare() (kein Hardcode, kein Concat).
		//
		// GIVEN alle Tabellen-Bezeichner werden in SQL gebildet
		// WHEN  das DELETE-Statement zusammengesetzt wird
		// THEN  Tabellenname via $wpdb->prefix + Suffix; cutoff via prepare(%s).
		// ===================================================================

		public function test_handle_uses_wpdb_prefix_and_prepare_for_sql_construction(): void
		{
			$this->optionStore['spreadconnect_webhook_log_retention_days'] = 90;
			$this->optionStore['spreadconnect_failed_ops_retention_days']  = 90;

			$wpdb               = $this->installWpdb();
			$wpdb->prefix       = 'wp_xyz_'; // custom prefix to prove no hard-code.
			$wpdb->queryReturns = [ 0, 0 ];

			PurgeOldLogsJob::handle();

			$this->assertCount(
				2,
				$wpdb->prepareCalls,
				'AC-8: prepare() MUSS GENAU 2-mal aufgerufen werden (kein String-Concat).'
			);

			foreach ( $wpdb->prepareCalls as $call ) {
				$sql = $call['sql'];

				// Must use the live prefix, NEVER hard-coded `wp_`.
				$this->assertStringContainsString(
					'wp_xyz_spreadconnect_',
					$sql,
					'AC-8: Tabellenname MUSS via $wpdb->prefix gebildet werden — der Test-Prefix ' .
					'"wp_xyz_" MUSS im Final-SQL erscheinen.'
				);

				// The pre-prepare SQL template must use a `%s` placeholder
				// for the cutoff value (NEVER concatenate the date string).
				$this->assertMatchesRegularExpression(
					'/<\s*%s/',
					$sql,
					'AC-8: cutoff-Wert MUSS via %s-Placeholder im prepare()-Template gebunden werden — ' .
					'KEINE String-Konkatenation des Datums-Werts.'
				);

				// The args array passed to prepare() MUST contain exactly one
				// scalar value (the cutoff).
				$this->assertCount(
					1,
					$call['args'],
					'AC-8: prepare() MUSS GENAU EIN Argument bekommen (den cutoff-String).'
				);
				$this->assertIsString(
					$call['args'][0],
					'AC-8: cutoff-Argument MUSS ein String sein (gmdate(Y-m-d H:i:s)-Output).'
				);
			}
		}

		// ===================================================================
		// AC-9: Erfolgreicher Run loggt info mit deleted-Counts + Cutoff-Timestamps.
		//
		// GIVEN handle() schliesst beide DELETEs erfolgreich ab
		// WHEN  das Resultat geloggt wird
		// THEN  ein WcLoggerAdapter::info-Call mit Source spreadconnect-failure
		//       und Context webhook_log_deleted/failed_ops_deleted/
		//       webhook_log_cutoff/failed_ops_cutoff.
		// ===================================================================

		public function test_handle_logs_info_with_deletion_counters_on_success(): void
		{
			$this->optionStore['spreadconnect_webhook_log_retention_days'] = 90;
			$this->optionStore['spreadconnect_failed_ops_retention_days']  = 90;

			$wpdb               = $this->installWpdb();
			$wpdb->queryReturns = [ 5, 3 ]; // 5 webhook_log, 3 failed_ops.

			PurgeOldLogsJob::handle();

			$failureEntries = $this->entriesForSource( Sources::FAILURE );
			$infoEntries    = array_values(
				array_filter(
					$failureEntries,
					static fn ( array $entry ): bool => $entry['level'] === 'info'
				)
			);
			$this->assertCount(
				1,
				$infoEntries,
				'AC-9: GENAU EIN info-Log mit source=spreadconnect-failure MUSS abgesetzt werden.'
			);

			$entry = $infoEntries[0];
			$this->assertSame(
				Sources::FAILURE,
				$entry['context']['source'] ?? null,
				'AC-9: source MUSS Sources::FAILURE ("spreadconnect-failure") sein.'
			);

			$ctx = $entry['context'];
			$this->assertArrayHasKey(
				'webhook_log_deleted',
				$ctx,
				'AC-9: Context MUSS webhook_log_deleted enthalten.'
			);
			$this->assertSame(
				5,
				$ctx['webhook_log_deleted'],
				'AC-9: webhook_log_deleted MUSS dem $wpdb->query()-Return (5) entsprechen.'
			);
			$this->assertArrayHasKey(
				'failed_ops_deleted',
				$ctx,
				'AC-9: Context MUSS failed_ops_deleted enthalten.'
			);
			$this->assertSame(
				3,
				$ctx['failed_ops_deleted'],
				'AC-9: failed_ops_deleted MUSS dem $wpdb->query()-Return (3) entsprechen.'
			);

			$this->assertArrayHasKey(
				'webhook_log_cutoff',
				$ctx,
				'AC-9: Context MUSS webhook_log_cutoff (ISO-Timestamp) enthalten.'
			);
			$this->assertIsString(
				$ctx['webhook_log_cutoff'],
				'AC-9: webhook_log_cutoff MUSS ein String sein.'
			);
			$this->assertCutoffWithinTolerance(
				$ctx['webhook_log_cutoff'],
				90,
				60,
				'AC-9: webhook_log_cutoff MUSS einer 90d-Cutoff-Berechnung entsprechen.'
			);

			$this->assertArrayHasKey(
				'failed_ops_cutoff',
				$ctx,
				'AC-9: Context MUSS failed_ops_cutoff (ISO-Timestamp) enthalten.'
			);
			$this->assertIsString(
				$ctx['failed_ops_cutoff'],
				'AC-9: failed_ops_cutoff MUSS ein String sein.'
			);
			$this->assertCutoffWithinTolerance(
				$ctx['failed_ops_cutoff'],
				90,
				60,
				'AC-9: failed_ops_cutoff MUSS einer 90d-Cutoff-Berechnung entsprechen.'
			);
		}

		// ===================================================================
		// AC-10: webhook_log-Fehler stoppt failed_ops-DELETE NICHT.
		//
		// GIVEN $wpdb->query() liefert false (DB-Fehler) fuer webhook_log
		// WHEN  handle() weiterlaeuft
		// THEN  Fehler via WcLoggerAdapter::error geloggt; das zweite DELETE
		//       (failed_ops) wird trotzdem versucht.
		// ===================================================================

		public function test_handle_continues_failed_ops_purge_when_webhook_log_query_fails(): void
		{
			$this->optionStore['spreadconnect_webhook_log_retention_days'] = 90;
			$this->optionStore['spreadconnect_failed_ops_retention_days']  = 90;

			$wpdb               = $this->installWpdb();
			$wpdb->last_error   = 'MySQL server has gone away';
			// First DELETE (webhook_log) returns FALSE (failure); second
			// DELETE (failed_ops) returns 7 (success).
			$wpdb->queryReturns = [ false, 7 ];

			PurgeOldLogsJob::handle();

			// Both query() calls MUST have happened despite the first
			// failure — best-effort semantics (architecture.md Z. 556 + AC-10).
			$this->assertCount(
				2,
				$wpdb->queryCalls,
				'AC-10 PFLICHT: BEIDE DELETEs MUESSEN ausgefuehrt worden sein (Best-Effort) — ' .
				'der erste Fehler DARF den zweiten DELETE NICHT short-circuiten.'
			);

			// Verify the SECOND query was the failed_ops DELETE (not a duplicate
			// retry of webhook_log).
			$secondSql = $wpdb->queryCalls[1];
			$this->assertStringContainsString(
				'spreadconnect_failed_ops',
				$secondSql,
				'AC-10: Der ZWEITE query()-Call MUSS auf wp_spreadconnect_failed_ops zielen.'
			);
		}

		// AC-10: Fehler-Pfad loggt error mit last_error.
		public function test_handle_logs_error_when_wpdb_query_returns_false(): void
		{
			$this->optionStore['spreadconnect_webhook_log_retention_days'] = 90;
			$this->optionStore['spreadconnect_failed_ops_retention_days']  = 90;

			$wpdb               = $this->installWpdb();
			$wpdb->last_error   = 'MySQL server has gone away';
			$wpdb->queryReturns = [ false, 0 ];

			PurgeOldLogsJob::handle();

			$failureEntries = $this->entriesForSource( Sources::FAILURE );

			// Filter to error-level entries only.
			$errorEntries = array_values(
				array_filter(
					$failureEntries,
					static fn ( array $entry ): bool => $entry['level'] === 'error'
				)
			);

			$this->assertGreaterThanOrEqual(
				1,
				count( $errorEntries ),
				'AC-10: MIN. EIN error-Log-Eintrag mit source=spreadconnect-failure MUSS abgesetzt werden ' .
				'wenn $wpdb->query() false liefert.'
			);

			$errEntry = $errorEntries[0];
			$this->assertSame(
				'error',
				$errEntry['level'],
				'AC-10: Log-Level MUSS exakt "error" sein.'
			);
			$this->assertSame(
				Sources::FAILURE,
				$errEntry['context']['source'] ?? null,
				'AC-10: source MUSS Sources::FAILURE sein.'
			);
			$this->assertArrayHasKey(
				'last_error',
				$errEntry['context'],
				'AC-10: Context MUSS last_error-Feld enthalten (Diagnostic-Trace fuer Operations).'
			);
			$this->assertSame(
				'MySQL server has gone away',
				$errEntry['context']['last_error'],
				'AC-10: last_error MUSS dem $wpdb->last_error-Wert entsprechen.'
			);
		}

		// AC-10 (no exception escapes): handle() darf KEINE Exception werfen.
		public function test_handle_does_not_throw_when_wpdb_query_fails(): void
		{
			$this->optionStore['spreadconnect_webhook_log_retention_days'] = 90;
			$this->optionStore['spreadconnect_failed_ops_retention_days']  = 90;

			$wpdb               = $this->installWpdb();
			$wpdb->last_error   = 'simulated db error';
			$wpdb->queryReturns = [ false, false ];

			// MUST NOT throw — Architecture Z. 556 says `no retry`, and an
			// uncaught exception over the AS hook-boundary would defeat
			// the recurring schedule.
			PurgeOldLogsJob::handle();

			$this->addToAssertionCount( 1 );
		}
	}

	/**
	 * Logger spy that the {@see WcLoggerAdapter} forwards to via
	 * `wc_get_logger()`. Mirrors the small log/info/warning/error/debug
	 * surface the adapter calls.
	 */
	final class Slice43LoggerSpy
	{
		/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
		private array $entries;

		/**
		 * @param list<array{level:string,message:string,context:array<string,mixed>}> $entries
		 *        passed by reference from the test, so that
		 *        {@see Slice43PurgeOldLogsJobTest::$loggerEntries} sees
		 *        every log call without round-tripping through a singleton.
		 */
		public function __construct( array &$entries )
		{
			$this->entries = & $entries;
		}

		public function log( string $level, string $message, array $context = [] ): void
		{
			$this->entries[] = [
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			];
		}

		public function info( string $message, array $context = [] ): void
		{
			$this->log( 'info', $message, $context );
		}

		public function warning( string $message, array $context = [] ): void
		{
			$this->log( 'warning', $message, $context );
		}

		public function error( string $message, array $context = [] ): void
		{
			$this->log( 'error', $message, $context );
		}

		public function debug( string $message, array $context = [] ): void
		{
			$this->log( 'debug', $message, $context );
		}
	}
}
