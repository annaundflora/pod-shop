<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 37 — Failed-Ops-Repo + RetryPolicyListener
//
// Spec: specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/
//       slices/slice-37-failed-ops-repo.md
//
// Mocking strategy per spec (`mock_external`):
//   - `$wpdb` via a `Slice37FakeWpdb` recording double that exposes
//     `insertCalls`, `updateCalls`, `getRowResult`, `getResultsResult`
//     and `getVarResult` so the AC assertions can verify the SQL surface.
//   - `current_time` aliased via Brain\Monkey to a deterministic UTC string.
//   - `wp_json_encode` aliased to native `json_encode` with the slice-37
//      flag-pair (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).
//   - `\ActionScheduler::store()` / `::logger()` stubbed as a static helper
//     class (`Slice37FakeActionScheduler`) so the listener can resolve
//     hook + args + log entries without touching production AS.
//   - `as_get_scheduled_actions` aliased so the listener's retry-counter
//     lookup is fully deterministic.
//   - `WcLoggerAdapter` is bypassed via `Functions\when('wc_get_logger')`
//     returning a no-op spy (the adapter is allowed to fall through to
//     `wc_get_logger()` which we stub).
//
// Test-Konvention (Repo-Naming):
//   `tests/slices/pod-shop-mvp/Slice37FailedOpsRepoTest.php` mit
//   `final class Slice37FailedOpsRepoTest extends TestCase`.
//
// Each test maps 1:1 to a spec acceptance criterion via the docblock
// GIVEN/WHEN/THEN. AC-1..AC-5 cover the Repo (FailedOpsRepo); AC-6..AC-12
// cover the RetryPolicyListener.
// ---------------------------------------------------------------------------

namespace {

	// `\ActionScheduler` is referenced by the listener via
	// `class_exists('\ActionScheduler')` + `call_user_func([...,'store'])`
	// + `call_user_func([...,'logger'])`. Declare a global stub that
	// resolves to programmable per-test state via static properties so
	// every Slice37 test can swap the store/logger doubles without
	// re-loading classes.
	if ( ! class_exists( 'ActionScheduler', false ) ) {
		final class ActionScheduler // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		{
			/** @var object|null */
			public static $storeImpl = null;

			/** @var object|null */
			public static $loggerImpl = null;

			public static function store(): ?object
			{
				return self::$storeImpl;
			}

			public static function logger(): ?object
			{
				return self::$loggerImpl;
			}
		}
	}

	// Recording AS-store stub. The listener probes via
	// `method_exists($store, 'fetch_action')` (Mockery anonymous mocks fail
	// that check), so we declare a real class with the documented surface.
	if ( ! class_exists( 'Slice37AsStoreStub', false ) ) {
		final class Slice37AsStoreStub
		{
			/** @var object|null */
			public ?object $action = null;

			public function fetch_action( int $action_id ): ?object
			{
				return $this->action;
			}
		}
	}

	// Recording AS-logger stub. Same `method_exists()` argument as above.
	if ( ! class_exists( 'Slice37AsLoggerStub', false ) ) {
		final class Slice37AsLoggerStub
		{
			/** @var list<mixed> */
			public array $logs = array();

			public function get_logs( int $action_id ): array
			{
				return $this->logs;
			}
		}
	}

	// Recording action stub. The listener calls `get_hook()` + `get_args()`.
	if ( ! class_exists( 'Slice37AsActionStub', false ) ) {
		final class Slice37AsActionStub
		{
			public string $hook = '';

			/** @var array<string, mixed> */
			public array $args = array();

			public function get_hook(): string
			{
				return $this->hook;
			}

			/**
			 * @return array<string, mixed>
			 */
			public function get_args(): array
			{
				return $this->args;
			}
		}
	}
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use InvalidArgumentException;
	use Mockery;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Api\SpreadconnectClientError;
	use SpreadconnectPod\Api\SpreadconnectTransientError;
	use SpreadconnectPod\Failure\FailedOpsRepo;
	use SpreadconnectPod\Failure\RetryPolicyListener;

	/**
	 * Slice 37 acceptance + integration tests.
	 *
	 * Covers AC-1..AC-12 across the two production classes:
	 *   - {@see FailedOpsRepo}        (AC-1 to AC-5).
	 *   - {@see RetryPolicyListener}  (AC-6 to AC-12).
	 *   - {@see \SpreadconnectPod\Bootstrap\Plugin::init} hook wiring (AC-11).
	 */
	final class Slice37FailedOpsRepoTest extends TestCase
	{
		private const TABLE = 'wp_spreadconnect_failed_ops';

		private const UTC_NOW = '2026-05-04 12:00:00';

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			// AS-store/logger default to "absent" — individual tests opt-in.
			\ActionScheduler::$storeImpl  = null;
			\ActionScheduler::$loggerImpl = null;

			// Deterministic UTC clock for the entire suite.
			Functions\when( 'current_time' )->alias(
				static function ( string $type, $gmt = 0 ): string {
					return self::UTC_NOW;
				}
			);

			// Native JSON encode — preserves the unicode + slashes flags
			// the production code relies on for round-trip equality.
			Functions\when( 'wp_json_encode' )->alias(
				static function ( $data, int $options = 0, int $depth = 512 ) {
					return json_encode( $data, $options, $depth );
				}
			);

			// Logger fallback — the WcLoggerAdapter validates the source
			// whitelist and forwards to `wc_get_logger()`. The spy here
			// only keeps the adapter from blowing up.
			Functions\when( 'wc_get_logger' )->alias(
				static function (): object {
					return new Slice37LoggerSpy();
				}
			);
		}

		protected function tearDown(): void
		{
			// Reset Plugin-internal state so AC-11 idempotency tests stay clean.
			$pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
			if ( class_exists( $pluginFqcn ) ) {
				$ref = new ReflectionClass( $pluginFqcn );
				if ( $ref->hasProperty( 'initialized' ) ) {
					$ref->getProperty( 'initialized' )->setValue( null, false );
				}
				if ( $ref->hasProperty( 'pluginFile' ) ) {
					$ref->getProperty( 'pluginFile' )->setValue( null, '' );
				}
			}

			\ActionScheduler::$storeImpl  = null;
			\ActionScheduler::$loggerImpl = null;

			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// ===================================================================
		// Helpers
		// ===================================================================

		private function makeWpdb(): Slice37FakeWpdb
		{
			$wpdb         = new Slice37FakeWpdb();
			$wpdb->prefix = 'wp_';
			return $wpdb;
		}

		private function makeRepo( ?Slice37FakeWpdb $wpdb = null ): FailedOpsRepo
		{
			$wpdb = $wpdb ?? $this->makeWpdb();
			return new FailedOpsRepo( $wpdb );
		}

		/**
		 * Build an `\ActionScheduler_Action`-like double that returns the
		 * given hook + args. We use a real (non-final) stub class because
		 * the listener probes `method_exists($action, 'get_hook')` which
		 * Mockery anonymous mocks fail.
		 *
		 * @param array<string, mixed> $args
		 */
		private function makeAction( string $hook, array $args ): object
		{
			$action       = new \Slice37AsActionStub();
			$action->hook = $hook;
			$action->args = $args;
			return $action;
		}

		/**
		 * Wire the global `\ActionScheduler::store()` so `fetch_action($id)`
		 * resolves to `$action`. Uses {@see \Slice37AsStoreStub} so
		 * `method_exists($store, 'fetch_action')` is honoured.
		 */
		private function installStore( object $action, int $forActionId = 1 ): void
		{
			$store         = new \Slice37AsStoreStub();
			$store->action = $action;
			\ActionScheduler::$storeImpl = $store;
		}

		/**
		 * Wire the global `\ActionScheduler::logger()` so `get_logs($id)`
		 * resolves to a list of message strings/objects. Uses
		 * {@see \Slice37AsLoggerStub} so `method_exists()` passes.
		 *
		 * @param list<mixed> $logs Either strings or objects with
		 *                          `get_message()`.
		 */
		private function installLogger( array $logs ): void
		{
			$logger      = new \Slice37AsLoggerStub();
			$logger->logs = $logs;
			\ActionScheduler::$loggerImpl = $logger;
		}

		/**
		 * Stub the `as_get_scheduled_actions()` AS-API surface for the
		 * retry-counter lookup. Returns an array of the requested length
		 * regardless of the filter args.
		 */
		private function stubPriorFailedActions( int $count ): void
		{
			$prior = array_fill( 0, $count, Mockery::mock() );
			Functions\when( 'as_get_scheduled_actions' )->alias(
				static function ( array $args ) use ( $prior ): array {
					return $prior;
				}
			);
		}

		// ===================================================================
		// AC-1 — record() inserts row with all required + audit fields.
		// ===================================================================

		/**
		 * AC-1: GIVEN a complete args-array.
		 *       WHEN  `FailedOpsRepo::record($args)` runs.
		 *       THEN  exactly one INSERT into `{prefix}spreadconnect_failed_ops`
		 *             with all 10 columns populated, payload as JSON-string,
		 *             timestamps from `current_time('mysql', true)` (UTC),
		 *             state = 'unresolved'.
		 */
		public function test_record_inserts_row_with_all_fields(): void
		{
			$wpdb            = $this->makeWpdb();
			$wpdb->insert_id = 99;
			$repo            = $this->makeRepo( $wpdb );

			$args = array(
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => array( 'order_id' => 7 ),
				'error_message'       => 'HTTP 400 invalid SKU mapping',
				'error_code'          => 'http_4xx',
				'retries_used'        => 0,
			);

			$repo->record( $args );

			self::assertCount( 1, $wpdb->insertCalls, 'AC-1: exactly one INSERT MUST be issued.' );
			$call = $wpdb->insertCalls[0];
			self::assertSame( self::TABLE, $call['table'] );

			$row = $call['data'];
			self::assertSame( 'create_order', $row['op_type'] );
			self::assertSame( 'order', $row['related_entity_type'] );
			self::assertSame( '7', $row['related_entity_id'] );
			self::assertSame( '{"order_id":7}', $row['payload'], 'AC-1: payload MUST be JSON-encoded.' );
			self::assertSame( 'HTTP 400 invalid SKU mapping', $row['error_message'] );
			self::assertSame( 'http_4xx', $row['error_code'] );
			self::assertSame( 0, $row['retries_used'] );
			self::assertSame( self::UTC_NOW, $row['created_at'], 'AC-1: created_at MUST be UTC.' );
			self::assertSame( self::UTC_NOW, $row['last_attempt_at'], 'AC-1: last_attempt_at MUST be UTC.' );
			self::assertSame( 'unresolved', $row['state'] );
		}

		/**
		 * AC-1 (return-id): record() returns the int from `$wpdb->insert_id`.
		 */
		public function test_record_returns_insert_id(): void
		{
			$wpdb            = $this->makeWpdb();
			$wpdb->insert_id = 4242;
			$repo            = $this->makeRepo( $wpdb );

			$id = $repo->record(
				array(
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '7',
					'payload'             => array( 'order_id' => 7 ),
				)
			);

			self::assertSame( 4242, $id );
		}

		/**
		 * AC-1 (payload): payload is `wp_json_encode`'d before insert.
		 */
		public function test_record_json_encodes_payload(): void
		{
			$wpdb = $this->makeWpdb();
			$repo = $this->makeRepo( $wpdb );

			$repo->record(
				array(
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '13',
					'payload'             => array( 'article_id' => 13, 'name' => 'Bär' ),
				)
			);

			$row = $wpdb->insertCalls[0]['data'];
			self::assertIsString( $row['payload'] );
			$decoded = json_decode( $row['payload'], true );
			self::assertSame(
				array( 'article_id' => 13, 'name' => 'Bär' ),
				$decoded,
				'AC-1: payload MUST roundtrip through JSON unchanged.'
			);
		}

		/**
		 * AC-1 (UTC): created_at + last_attempt_at use `current_time('mysql', true)`.
		 */
		public function test_record_uses_utc_for_created_at(): void
		{
			$gmtFlags = array();
			Functions\when( 'current_time' )->alias(
				static function ( string $type, $gmt = 0 ) use ( &$gmtFlags ): string {
					$gmtFlags[] = (bool) $gmt;
					return Slice37FailedOpsRepoTest::utcNow();
				}
			);

			$wpdb = $this->makeWpdb();
			$repo = $this->makeRepo( $wpdb );

			$repo->record(
				array(
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '7',
					'payload'             => array(),
				)
			);

			self::assertNotEmpty( $gmtFlags );
			foreach ( $gmtFlags as $flag ) {
				self::assertTrue( $flag, 'AC-1: every current_time() call MUST pass $gmt=true.' );
			}
		}

		public static function utcNow(): string
		{
			return self::UTC_NOW;
		}

		// ===================================================================
		// AC-2 — Validation rejects missing required fields.
		// ===================================================================

		/**
		 * AC-2: GIVEN an args-array missing `op_type`.
		 *       WHEN  record() runs.
		 *       THEN  InvalidArgumentException is thrown referencing the field.
		 */
		public function test_record_throws_on_missing_op_type(): void
		{
			$repo = $this->makeRepo();

			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessageMatches( '/op_type/' );

			$repo->record(
				array(
					'related_entity_type' => 'order',
					'related_entity_id'   => '7',
					'payload'             => array(),
				)
			);
		}

		/**
		 * AC-2: GIVEN an args-array missing `related_entity_id`.
		 *       WHEN  record() runs.
		 *       THEN  InvalidArgumentException is thrown referencing the field.
		 */
		public function test_record_throws_on_missing_related_entity_id(): void
		{
			$repo = $this->makeRepo();

			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessageMatches( '/related_entity_id/' );

			$repo->record(
				array(
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'payload'             => array(),
				)
			);
		}

		/**
		 * AC-2: validation runs BEFORE any `$wpdb->insert()` call.
		 */
		public function test_record_does_not_call_wpdb_insert_on_invalid_args(): void
		{
			$wpdb = $this->makeWpdb();
			$repo = $this->makeRepo( $wpdb );

			try {
				$repo->record( array() ); // empty -> missing op_type
				self::fail( 'AC-2: empty args MUST throw.' );
			} catch ( InvalidArgumentException $e ) {
				// expected
			}

			self::assertSame(
				array(),
				$wpdb->insertCalls,
				'AC-2: $wpdb->insert MUST NOT be called on invalid args.'
			);
		}

		// ===================================================================
		// AC-3 — findById returns row or null.
		// ===================================================================

		/**
		 * AC-3: GIVEN row with id=42.
		 *       WHEN  findById(42).
		 *       THEN  assoc-array with all 11 columns; payload is JSON-decoded
		 *             into a PHP array.
		 */
		public function test_find_by_id_returns_associative_array(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => '{"order_id":7}',
				'error_message'       => 'HTTP 400 invalid SKU mapping',
				'error_code'          => 'http_4xx',
				'retries_used'        => '0',
				'created_at'          => self::UTC_NOW,
				'last_attempt_at'     => self::UTC_NOW,
				'state'               => 'unresolved',
			);
			$repo               = $this->makeRepo( $wpdb );

			$row = $repo->findById( 42 );

			self::assertIsArray( $row );
			self::assertSame( 42, $row['id'], 'AC-3: id MUST be cast to int.' );
			self::assertSame( 'create_order', $row['op_type'] );
			self::assertSame( 'order', $row['related_entity_type'] );
			self::assertSame( '7', $row['related_entity_id'] );
			self::assertSame( 'http_4xx', $row['error_code'] );
			self::assertSame( 0, $row['retries_used'] );
			self::assertSame( 'unresolved', $row['state'] );
		}

		/**
		 * AC-3: payload column is JSON-decoded into a PHP array.
		 */
		public function test_find_by_id_decodes_payload_json(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'      => '7',
				'payload' => '{"order_id":7,"sku":"AAA"}',
				'state'   => 'unresolved',
			);
			$repo               = $this->makeRepo( $wpdb );

			$row = $repo->findById( 7 );

			self::assertIsArray( $row );
			self::assertSame(
				array( 'order_id' => 7, 'sku' => 'AAA' ),
				$row['payload'],
				'AC-3: payload MUST be json_decode\'d.'
			);
		}

		/**
		 * AC-3: unknown id -> null (no throw).
		 */
		public function test_find_by_id_returns_null_for_unknown_id(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = null;
			$repo               = $this->makeRepo( $wpdb );

			self::assertNull( $repo->findById( 9999 ) );
		}

		// ===================================================================
		// AC-4 — markResolved / markDismissed.
		// ===================================================================

		/**
		 * AC-4: markResolved writes state='resolved' and returns true on success.
		 */
		public function test_mark_resolved_writes_state_resolved(): void
		{
			$wpdb              = $this->makeWpdb();
			$wpdb->updateResult = 1;
			$repo              = $this->makeRepo( $wpdb );

			$result = $repo->markResolved( 42 );

			self::assertTrue( $result );
			self::assertCount( 1, $wpdb->updateCalls );
			self::assertSame( 'resolved', $wpdb->updateCalls[0]['data']['state'] );
			self::assertSame( array( 'id' => 42 ), $wpdb->updateCalls[0]['where'] );
		}

		/**
		 * AC-4: markDismissed writes state='dismissed' and returns true.
		 */
		public function test_mark_dismissed_writes_state_dismissed(): void
		{
			$wpdb              = $this->makeWpdb();
			$wpdb->updateResult = 1;
			$repo              = $this->makeRepo( $wpdb );

			$result = $repo->markDismissed( 42 );

			self::assertTrue( $result );
			self::assertCount( 1, $wpdb->updateCalls );
			self::assertSame( 'dismissed', $wpdb->updateCalls[0]['data']['state'] );
			self::assertSame( array( 'id' => 42 ), $wpdb->updateCalls[0]['where'] );
		}

		/**
		 * AC-4: idempotent — second call (affected_rows=0) returns false.
		 */
		public function test_mark_resolved_is_idempotent_returns_false_on_repeat(): void
		{
			$wpdb              = $this->makeWpdb();
			$wpdb->updateResult = 0;
			$repo              = $this->makeRepo( $wpdb );

			self::assertFalse( $repo->markResolved( 42 ) );
		}

		// ===================================================================
		// AC-5 — findByEntity uses composite index path.
		// ===================================================================

		/**
		 * AC-5: WHERE clause filters by `(type, id, state)` in the
		 *       composite-index column-order.
		 */
		public function test_find_by_entity_filters_by_type_id_state(): void
		{
			$wpdb                   = $this->makeWpdb();
			$wpdb->getResultsResult = array(
				array(
					'id'                  => '17',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '7',
					'payload'             => '{}',
					'state'               => 'unresolved',
					'created_at'          => self::UTC_NOW,
				),
			);
			$repo                   = $this->makeRepo( $wpdb );

			$rows = $repo->findByEntity( 'order', '7', 'unresolved' );

			self::assertCount( 1, $rows );
			self::assertSame( 'unresolved', $rows[0]['state'] );

			// Index-friendly WHERE-order: type, id, state.
			$sql = $wpdb->getResultsCalls[0];
			self::assertMatchesRegularExpression(
				'/related_entity_type\s*=\s*(\'?)order\1[\s\S]*related_entity_id\s*=\s*(\'?)7\2[\s\S]*state\s*=\s*(\'?)unresolved\3/i',
				$sql,
				'AC-5: WHERE-order MUST be (type, id, state) for the idx_related_entity hit.'
			);
		}

		/**
		 * AC-5: ORDER BY created_at DESC.
		 */
		public function test_find_by_entity_orders_by_created_at_desc(): void
		{
			$wpdb                   = $this->makeWpdb();
			$wpdb->getResultsResult = array();
			$repo                   = $this->makeRepo( $wpdb );

			$repo->findByEntity( 'order', '7', 'unresolved' );

			$sql = $wpdb->getResultsCalls[0];
			self::assertMatchesRegularExpression(
				'/ORDER\s+BY\s+created_at\s+DESC/i',
				$sql,
				'AC-5: query MUST sort created_at DESC.'
			);
		}

		/**
		 * AC-5: omitting state -> all rows for the entity.
		 */
		public function test_find_by_entity_without_state_returns_all(): void
		{
			$wpdb                   = $this->makeWpdb();
			$wpdb->getResultsResult = array(
				array(
					'id'                  => '17',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '7',
					'payload'             => '{}',
					'state'               => 'unresolved',
					'created_at'          => '2026-05-04 11:00:00',
				),
				array(
					'id'                  => '15',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '7',
					'payload'             => '{}',
					'state'               => 'resolved',
					'created_at'          => '2026-05-04 09:00:00',
				),
			);
			$repo                   = $this->makeRepo( $wpdb );

			$rows = $repo->findByEntity( 'order', '7' );

			self::assertCount( 2, $rows );
			$states = array_column( $rows, 'state' );
			self::assertContains( 'unresolved', $states );
			self::assertContains( 'resolved', $states );

			$sql = $wpdb->getResultsCalls[0];
			self::assertDoesNotMatchRegularExpression(
				'/state\s*=/i',
				$sql,
				'AC-5: omitted-state query MUST NOT include a state predicate.'
			);
		}

		// ===================================================================
		// AC-6 — Permanent (4xx) on first fail -> direct repo-record, no retry.
		// ===================================================================

		/**
		 * AC-6: GIVEN a `spreadconnect/create_order` action whose log carries
		 *       `SpreadconnectClientError`; AS retry-counter = 0.
		 *       WHEN  the listener fires.
		 *       THEN  FailedOpsRepo::record() runs once with op_type='create_order',
		 *             related_entity_type='order', related_entity_id='7',
		 *             error_code='http_4xx', retries_used=0, state='unresolved'.
		 */
		public function test_client_error_records_failed_op_immediately(): void
		{
			$wpdb            = $this->makeWpdb();
			$wpdb->insert_id = 1;
			$repo            = $this->makeRepo( $wpdb );

			$action = $this->makeAction( 'spreadconnect/create_order', array( 'order_id' => 7 ) );
			$this->installStore( $action, 1 );
			$this->installLogger(
				array(
					'action created',
					'action started',
					'action failed via Action: ' . SpreadconnectClientError::class
						. ': HTTP 400 invalid SKU mapping',
				)
			);
			$this->stubPriorFailedActions( 0 );

			$listener = new RetryPolicyListener( $repo );
			$listener->on_action_failed( 1 );

			self::assertCount( 1, $wpdb->insertCalls, 'AC-6: a single DLQ row MUST be written.' );
			$row = $wpdb->insertCalls[0]['data'];
			self::assertSame( 'create_order', $row['op_type'] );
			self::assertSame( 'order', $row['related_entity_type'] );
			self::assertSame( '7', $row['related_entity_id'] );
			self::assertSame( 'http_4xx', $row['error_code'], 'AC-6: error_code MUST be "http_4xx".' );
			self::assertSame( 0, $row['retries_used'] );
			self::assertSame( 'unresolved', $row['state'] );
			self::assertStringContainsString( 'invalid SKU mapping', (string) $row['error_message'] );
		}

		/**
		 * AC-6: NO `as_enqueue_async_action` re-schedule.
		 */
		public function test_client_error_does_not_re_enqueue_action(): void
		{
			$repo   = $this->makeRepo();
			$action = $this->makeAction( 'spreadconnect/create_order', array( 'order_id' => 7 ) );
			$this->installStore( $action, 1 );
			$this->installLogger(
				array( 'action failed: ' . SpreadconnectClientError::class . ': bad' )
			);
			$this->stubPriorFailedActions( 0 );

			Functions\expect( 'as_enqueue_async_action' )->never();

			$listener = new RetryPolicyListener( $repo );
			$listener->on_action_failed( 1 );

			// Implicit: the never() expectation passes if the listener does
			// not re-schedule; explicit assertion keeps the test loud.
			self::assertTrue( true, 'AC-6: listener MUST NOT re-enqueue actions.' );
		}

		// ===================================================================
		// AC-7 — Transient below threshold -> no record.
		// ===================================================================

		/**
		 * AC-7: GIVEN SpreadconnectTransientError with retry-counter = 2.
		 *       WHEN  the listener fires.
		 *       THEN  no DLQ row is written; AS keeps retrying.
		 */
		public function test_transient_error_with_two_retries_does_not_record(): void
		{
			$wpdb = $this->makeWpdb();
			$repo = $this->makeRepo( $wpdb );

			$action = $this->makeAction( 'spreadconnect/create_order', array( 'order_id' => 7 ) );
			$this->installStore( $action, 1 );
			$this->installLogger(
				array(
					'action failed: ' . SpreadconnectTransientError::class . ': 503 gateway timeout',
				)
			);
			$this->stubPriorFailedActions( 2 );

			$listener = new RetryPolicyListener( $repo );
			$listener->on_action_failed( 1 );

			self::assertSame(
				array(),
				$wpdb->insertCalls,
				'AC-7: NO DLQ row MUST be written below the 3-retry threshold.'
			);
		}

		// ===================================================================
		// AC-8 — Transient at threshold (3) -> record with retries_used=3.
		// ===================================================================

		/**
		 * AC-8: GIVEN SpreadconnectTransientError with retry-counter = 3.
		 *       WHEN  the listener fires.
		 *       THEN  DLQ row with retries_used=3, error_code='http_5xx' or
		 *             'transient_error' fallback, state='unresolved'.
		 */
		public function test_transient_error_with_three_retries_records_with_counter(): void
		{
			$wpdb            = $this->makeWpdb();
			$wpdb->insert_id = 7;
			$repo            = $this->makeRepo( $wpdb );

			$action = $this->makeAction( 'spreadconnect/create_order', array( 'order_id' => 7 ) );
			$this->installStore( $action, 1 );
			$this->installLogger(
				array(
					'action failed: ' . SpreadconnectTransientError::class . ': 503 gateway timeout',
				)
			);
			$this->stubPriorFailedActions( 3 );

			$listener = new RetryPolicyListener( $repo );
			$listener->on_action_failed( 1 );

			self::assertCount( 1, $wpdb->insertCalls );
			$row = $wpdb->insertCalls[0]['data'];
			self::assertSame( 'create_order', $row['op_type'] );
			self::assertSame( 'order', $row['related_entity_type'] );
			self::assertSame( '7', $row['related_entity_id'] );
			self::assertSame( 3, $row['retries_used'] );
			self::assertContains(
				$row['error_code'],
				array( 'http_5xx', 'transient_error' ),
				'AC-8: error_code MUST be "http_5xx" or fallback "transient_error".'
			);
			self::assertSame( 'unresolved', $row['state'] );
		}

		// ===================================================================
		// AC-9 — Op-Type mapping for all 9 plugin hooks + foreign-hook ignore.
		// ===================================================================

		/**
		 * AC-9: each of the 9 plugin hooks maps to its canonical op_type and
		 *       produces a DLQ row when permanent-classified.
		 *
		 * @return array<int, array{0:string,1:string,2:array<string,mixed>,3:string,4:string}>
		 */
		public static function hookOpTypeMappingProvider(): array
		{
			return array(
				array( 'spreadconnect/create_order',           'create_order',           array( 'order_id' => 7 ),    'order',   '7' ),
				array( 'spreadconnect/confirm_order',          'confirm_order',          array( 'order_id' => 7 ),    'order',   '7' ),
				array( 'spreadconnect/cancel_order_mirror',    'cancel_order_mirror',    array( 'order_id' => 7 ),    'order',   '7' ),
				array( 'spreadconnect/fetch_tracking',         'fetch_tracking',         array( 'order_id' => 7 ),    'order',   '7' ),
				array( 'spreadconnect/sync_article',           'sync_article',           array( 'article_id' => 13 ), 'article', '13' ),
				array( 'spreadconnect/handle_article_removed', 'handle_article_removed', array( 'article_id' => 13 ), 'article', '13' ),
				array( 'spreadconnect/sync_catalog',           'sync_catalog',           array( 'run_id' => '0' ),    'system',  '0' ),
				array( 'spreadconnect/process_webhook_event',  'handle_webhook',         array( 'log_id' => 99 ),     'webhook', '99' ),
				array( 'spreadconnect/scheduled_stock_sync',   'scheduled_stock_sync',   array(),                     'system',  '0' ),
			);
		}

		/**
		 * AC-9 (mapping): each hook -> op_type as documented.
		 */
		public function test_op_type_mapping_for_all_known_hooks(): void
		{
			foreach ( self::hookOpTypeMappingProvider() as $row ) {
				[ $hook, $opType, $args, $entityType, $entityId ] = $row;

				$wpdb            = $this->makeWpdb();
				$wpdb->insert_id = 1;
				$repo            = new FailedOpsRepo( $wpdb );

				$action = $this->makeAction( $hook, $args );
				$this->installStore( $action, 1 );
				$this->installLogger(
					array( 'action failed: ' . SpreadconnectClientError::class . ': boom' )
				);
				$this->stubPriorFailedActions( 0 );

				$listener = new RetryPolicyListener( $repo );
				$listener->on_action_failed( 1 );

				self::assertCount(
					1,
					$wpdb->insertCalls,
					"AC-9 ({$hook}): a DLQ row MUST be written."
				);
				$inserted = $wpdb->insertCalls[0]['data'];
				self::assertSame(
					$opType,
					$inserted['op_type'],
					"AC-9 ({$hook}): op_type mismatch."
				);
				self::assertSame(
					$entityType,
					$inserted['related_entity_type'],
					"AC-9 ({$hook}): related_entity_type mismatch."
				);
				self::assertSame(
					$entityId,
					$inserted['related_entity_id'],
					"AC-9 ({$hook}): related_entity_id mismatch."
				);

				// Reset AS state for the next iteration.
				\ActionScheduler::$storeImpl  = null;
				\ActionScheduler::$loggerImpl = null;
			}
		}

		/**
		 * AC-9: foreign hooks are ignored — no DLQ row.
		 */
		public function test_unknown_hook_is_ignored(): void
		{
			$wpdb = $this->makeWpdb();
			$repo = $this->makeRepo( $wpdb );

			$action = $this->makeAction( 'woocommerce/some_other_hook', array( 'foo' => 'bar' ) );
			$this->installStore( $action, 1 );
			$this->installLogger(
				array( 'action failed: ' . SpreadconnectClientError::class . ': not us' )
			);
			$this->stubPriorFailedActions( 0 );

			$listener = new RetryPolicyListener( $repo );
			$listener->on_action_failed( 1 );

			self::assertSame(
				array(),
				$wpdb->insertCalls,
				'AC-9: foreign hooks MUST be ignored — no DLQ row.'
			);
		}

		// ===================================================================
		// AC-10 — Entity extraction from args.
		// ===================================================================

		/**
		 * AC-10: spreadconnect/create_order -> ('order', (string) order_id).
		 */
		public function test_entity_extraction_for_create_order(): void
		{
			$wpdb            = $this->makeWpdb();
			$wpdb->insert_id = 1;
			$repo            = $this->makeRepo( $wpdb );

			$action = $this->makeAction( 'spreadconnect/create_order', array( 'order_id' => 7 ) );
			$this->installStore( $action, 1 );
			$this->installLogger(
				array( 'action failed: ' . SpreadconnectClientError::class . ': bad' )
			);
			$this->stubPriorFailedActions( 0 );

			$listener = new RetryPolicyListener( $repo );
			$listener->on_action_failed( 1 );

			$inserted = $wpdb->insertCalls[0]['data'];
			self::assertSame( 'order', $inserted['related_entity_type'] );
			self::assertSame( '7', $inserted['related_entity_id'], 'AC-10: order_id MUST be cast to string.' );
		}

		/**
		 * AC-10: spreadconnect/sync_article -> ('article', (string) article_id).
		 */
		public function test_entity_extraction_for_sync_article(): void
		{
			$wpdb            = $this->makeWpdb();
			$wpdb->insert_id = 1;
			$repo            = $this->makeRepo( $wpdb );

			$action = $this->makeAction( 'spreadconnect/sync_article', array( 'article_id' => 13 ) );
			$this->installStore( $action, 1 );
			$this->installLogger(
				array( 'action failed: ' . SpreadconnectClientError::class . ': bad' )
			);
			$this->stubPriorFailedActions( 0 );

			$listener = new RetryPolicyListener( $repo );
			$listener->on_action_failed( 1 );

			$inserted = $wpdb->insertCalls[0]['data'];
			self::assertSame( 'article', $inserted['related_entity_type'] );
			self::assertSame( '13', $inserted['related_entity_id'], 'AC-10: article_id MUST be cast to string.' );
		}

		/**
		 * AC-10: spreadconnect/sync_catalog (no entity) ->
		 *        ('system', (string) ($args['run_id'] ?? '0')).
		 */
		public function test_entity_extraction_defaults_to_system_for_sync_catalog(): void
		{
			$wpdb            = $this->makeWpdb();
			$wpdb->insert_id = 1;
			$repo            = $this->makeRepo( $wpdb );

			$action = $this->makeAction( 'spreadconnect/sync_catalog', array() );
			$this->installStore( $action, 1 );
			$this->installLogger(
				array( 'action failed: ' . SpreadconnectClientError::class . ': bad' )
			);
			$this->stubPriorFailedActions( 0 );

			$listener = new RetryPolicyListener( $repo );
			$listener->on_action_failed( 1 );

			$inserted = $wpdb->insertCalls[0]['data'];
			self::assertSame( 'system', $inserted['related_entity_type'] );
			self::assertSame( '0', $inserted['related_entity_id'] );
		}

		/**
		 * AC-10: missing args-key falls back to a defensive default and the
		 *        listener does NOT throw.
		 */
		public function test_missing_args_key_uses_unknown_default(): void
		{
			$wpdb            = $this->makeWpdb();
			$wpdb->insert_id = 1;
			$repo            = $this->makeRepo( $wpdb );

			// create_order WITHOUT order_id arg.
			$action = $this->makeAction( 'spreadconnect/create_order', array() );
			$this->installStore( $action, 1 );
			$this->installLogger(
				array( 'action failed: ' . SpreadconnectClientError::class . ': bad' )
			);
			$this->stubPriorFailedActions( 0 );

			$listener = new RetryPolicyListener( $repo );

			$thrown = null;
			try {
				$listener->on_action_failed( 1 );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-10: listener MUST NOT throw on missing args-key.' );

			// A row was still written (best-effort) with a defensive default.
			self::assertCount( 1, $wpdb->insertCalls );
			$inserted = $wpdb->insertCalls[0]['data'];
			self::assertSame( 'order', $inserted['related_entity_type'] );
			self::assertNotSame(
				'',
				(string) $inserted['related_entity_id'],
				'AC-10: defensive default MUST yield a non-empty entity_id.'
			);
		}

		// ===================================================================
		// AC-11 — Bootstrap registers AS hook idempotently.
		// ===================================================================

		/**
		 * AC-11: GIVEN Plugin::init() runs.
		 *       THEN  `action_scheduler_failed_action` listener is registered
		 *             with priority 10 and accepts 1 arg.
		 */
		public function test_plugin_init_registers_action_scheduler_failed_action_hook(): void
		{
			$pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
			$pluginFile = self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';

			$GLOBALS['wpdb'] = $this->makeWpdb();

			self::assertFalse(
				Actions\has( 'action_scheduler_failed_action' ),
				'AC-11 (precondition): no listener before init().'
			);

			$pluginFqcn::init( $pluginFile );

			self::assertNotFalse(
				Actions\has( 'action_scheduler_failed_action' ),
				'AC-11: listener for "action_scheduler_failed_action" MUST be registered.'
			);
		}

		/**
		 * AC-11: a second init() call MUST NOT register the listener twice.
		 */
		public function test_plugin_init_is_idempotent_for_listener_hook(): void
		{
			$pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
			$pluginFile = self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';

			$GLOBALS['wpdb'] = $this->makeWpdb();

			$pluginFqcn::init( $pluginFile );
			$priorityFirst = Actions\has( 'action_scheduler_failed_action' );

			$pluginFqcn::init( $pluginFile );
			$prioritySecond = Actions\has( 'action_scheduler_failed_action' );

			self::assertSame(
				$priorityFirst,
				$prioritySecond,
				'AC-11: second init() MUST NOT register the listener again.'
			);
		}

		// ===================================================================
		// AC-12 — Double-fire idempotency.
		// ===================================================================

		/**
		 * AC-12: GIVEN a recent unresolved row for the same
		 *        (op_type, entity_type, entity_id) inside the 5-min window.
		 *        WHEN  the listener fires a second time.
		 *        THEN  NO second insert; the listener early-returns.
		 */
		public function test_listener_skips_second_invocation_when_recent_unresolved_row_exists(): void
		{
			$wpdb = $this->makeWpdb();

			// findByEntity()->get_results() returns one recent unresolved row.
			$wpdb->getResultsResult = array(
				array(
					'id'                  => '1',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '7',
					'payload'             => '{"order_id":7}',
					'state'               => 'unresolved',
					'created_at'          => self::UTC_NOW, // exactly "now" — within window.
				),
			);

			$repo   = $this->makeRepo( $wpdb );
			$action = $this->makeAction( 'spreadconnect/create_order', array( 'order_id' => 7 ) );
			$this->installStore( $action, 1 );
			$this->installLogger(
				array( 'action failed: ' . SpreadconnectClientError::class . ': bad' )
			);
			$this->stubPriorFailedActions( 0 );

			$listener = new RetryPolicyListener( $repo );
			$listener->on_action_failed( 1 );

			self::assertSame(
				array(),
				$wpdb->insertCalls,
				'AC-12: second listener invocation MUST NOT write a duplicate row.'
			);
		}

		// ===================================================================
		// Helpers
		// ===================================================================

		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}
	}

	/**
	 * Recording `wpdb` double for slice-37 — captures `insert`, `update`,
	 * `get_row`, `get_results`, `get_var` calls and lets each test pre-program
	 * the return values.
	 *
	 * Mirrors the slice-24 `SyncCatalogJobFakeWpdb` shape so the suite stays
	 * stylistically consistent.
	 */
	final class Slice37FakeWpdb extends \wpdb
	{
		public string $prefix    = 'wp_';

		public int $insert_id    = 0;

		public string $last_error = '';

		/** @var list<array{table:string,data:array<string,mixed>,format:array|string|null}> */
		public array $insertCalls = array();

		/** @var list<array{table:string,data:array<string,mixed>,where:array<string,mixed>}> */
		public array $updateCalls = array();

		/** @var list<string> */
		public array $getResultsCalls = array();

		/** @var list<string> */
		public array $getRowCalls = array();

		/** @var array<string, mixed>|null */
		public ?array $getRowResult = null;

		/** @var list<array<string, mixed>> */
		public array $getResultsResult = array();

		public mixed $getVarResult = null;

		public int $updateResult = 0;

		public function __construct() // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
		{
			// Skip parent ctor so we don't try to talk to MySQL.
		}

		public function insert( string $table, array $data, $format = null ): int
		{
			$this->insertCalls[] = array(
				'table'  => $table,
				'data'   => $data,
				'format' => $format,
			);
			// Tests pre-set `insert_id`; keep whatever value is on the prop.
			return 1;
		}

		public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int
		{
			$this->updateCalls[] = array(
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			);
			return $this->updateResult;
		}

		public function prepare( string $sql, ...$args ): string
		{
			$out = $sql;
			foreach ( $args as $arg ) {
				if ( is_array( $arg ) ) {
					foreach ( $arg as $inner ) {
						$replacement = is_int( $inner )
							? (string) $inner
							: "'" . str_replace( "'", "''", (string) $inner ) . "'";
						$out         = preg_replace( '/%[ds]/', $replacement, $out, 1 ) ?? $out;
					}
					continue;
				}
				$replacement = is_int( $arg )
					? (string) $arg
					: "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$out         = preg_replace( '/%[ds]/', $replacement, $out, 1 ) ?? $out;
			}
			return $out;
		}

		public function get_var( string $sql )
		{
			return $this->getVarResult;
		}

		public function get_row( string $sql, $output = 'OBJECT' )
		{
			$this->getRowCalls[] = $sql;
			return $this->getRowResult;
		}

		public function get_results( string $sql, $output = 'OBJECT' ): array
		{
			$this->getResultsCalls[] = $sql;
			return $this->getResultsResult;
		}
	}

	/**
	 * Minimal `wc_get_logger()` spy. The {@see WcLoggerAdapter} forwards
	 * to whatever this returns — we only need to provide a `log()` method
	 * so the adapter does not blow up.
	 */
	final class Slice37LoggerSpy
	{
		/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
		public array $calls = array();

		public function log( string $level, string $message, array $context = array() ): void
		{
			$this->calls[] = array(
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			);
		}
	}
}
