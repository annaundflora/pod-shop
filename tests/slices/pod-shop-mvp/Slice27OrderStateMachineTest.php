<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope, runs once at first include)
// ---------------------------------------------------------------------------
//
// `OrderStateMachine` references three runtime classes from WordPress /
// WooCommerce: `wpdb`, `WC_Order`, `WC_Logger`. We do NOT load WP/WC in
// unit tests (mock_external strategy per slice-27 spec); minimal stub
// classes are provided here so type-hints in the production code can be
// satisfied via Mockery doubles.
//
// `wc_get_logger()` is stubbed via Brain\Monkey when needed.
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'wpdb', false ) ) {
		/**
		 * Minimal wpdb stub. Mockery generates a partial-mock subclass per test
		 * which overrides `prepare()`, `query()` and `get_var()`. The `prefix`
		 * property is set directly by tests.
		 */
		class wpdb
		{
			public string $prefix = 'wp_';

			public function prepare( string $query, ...$args ): string
			{
				return $query;
			}

			public function query( string $query ): int
			{
				return 0;
			}

			/**
			 * @return mixed
			 */
			public function get_var( string $query )
			{
				return null;
			}
		}
	}

	if ( ! class_exists( 'WC_Order', false ) ) {
		/**
		 * Minimal WC_Order stub. Mockery doubles override the public methods
		 * SimpleStateMachine consumes (`get_id`, `get_meta`, `add_order_note`).
		 */
		class WC_Order
		{
			public function get_id(): int
			{
				return 0;
			}

			/**
			 * @return mixed
			 */
			public function get_meta( string $key, bool $single = true, string $context = 'view' )
			{
				return '';
			}

			/**
			 * @param string $note
			 * @param bool   $is_customer_note
			 * @param bool   $added_by_user
			 *
			 * @return int
			 */
			public function add_order_note( string $note, bool $is_customer_note = false, bool $added_by_user = false ): int
			{
				return 0;
			}
		}
	}

	if ( ! class_exists( 'WC_Logger', false ) ) {
		/**
		 * Minimal WC_Logger stub. Mockery doubles override `log()`.
		 */
		class WC_Logger
		{
			public function log( string $level, string $message, array $context = [] ): void
			{
				// no-op
			}
		}
	}
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use InvalidArgumentException;
	use Mockery;
	use PHPUnit\Framework\Attributes\Group;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Order\OrderStateMachine;
	use WC_Logger;
	use WC_Order;
	use wpdb;

	/**
	 * Slice 27 — Order State Machine (Compare-and-Set).
	 *
	 * Acceptance Tests gegen die Slice-Spec
	 * `slice-27-order-state-machine.md`. Mocking-Strategy `mock_external`:
	 *   - Mockery fuer wpdb-Subclass mit prepare/query/get_var-Spies.
	 *   - Mockery fuer WC_Order- und WC_Logger-Doubles.
	 *   - Brain\Monkey fuer global `wc_get_logger()`-Fallback (AC-8).
	 *
	 * Jeder Test ist 1:1 aus einem GIVEN/WHEN/THEN abgeleitet. Die Klasse
	 * wird via Constructor-Injection getestet — keine globalen Side-Effects.
	 */
	final class Slice27OrderStateMachineTest extends TestCase
	{
		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			// Default fallback for wc_get_logger() — returns a no-op spy so
			// the AC-8 fallback path inside OrderStateMachine::log() does
			// not blow up in tests that pass a null logger but still hit a
			// rejection (logRejection -> log -> wc_get_logger()). Tests that
			// inject a real Mockery logger never reach this branch.
			$fallback = new OrderStateMachineLoggerSpy();
			Functions\when( 'wc_get_logger' )->alias( static function () use ( $fallback ) {
				return $fallback;
			} );
		}

		protected function tearDown(): void
		{
			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// ===================================================================
		// Test helpers
		// ===================================================================

		/**
		 * Build a Mockery wpdb-double with prefix + injected behaviour for
		 * prepare/query/get_var. Each method call is tracked via Mockery
		 * expectations so tests can assert SQL shape and call-count.
		 *
		 * @return wpdb&\Mockery\MockInterface
		 */
		private function mockWpdb(): wpdb
		{
			/** @var wpdb&\Mockery\MockInterface $mock */
			$mock         = Mockery::mock( wpdb::class );
			$mock->prefix = 'wp_';

			// Default `prepare`: return the SQL with placeholders interpolated
			// into a deterministic string that callers can pattern-match.
			// Most tests override the default with a stricter expectation.
			$mock->shouldReceive( 'prepare' )
				->andReturnUsing( static function ( string $query, ...$args ): string {
					// Naive substitution: %d->int, %s->'string'
					$result = $query;
					foreach ( $args as $arg ) {
						$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
						$result      = preg_replace( '/%[ds]/', $replacement, $result, 1 ) ?? $result;
					}
					return $result;
				} )
				->byDefault();

			$mock->shouldReceive( 'query' )->andReturn( 0 )->byDefault();
			$mock->shouldReceive( 'get_var' )->andReturn( null )->byDefault();

			return $mock;
		}

		/**
		 * Build a Mockery WC_Order-double with default get_id() = 42.
		 *
		 * @param int    $orderId
		 * @param string $currentMeta Value returned by get_meta('_spreadconnect_state').
		 *
		 * @return WC_Order&\Mockery\MockInterface
		 */
		private function mockOrder( int $orderId = 42, string $currentMeta = '' ): WC_Order
		{
			/** @var WC_Order&\Mockery\MockInterface $mock */
			$mock = Mockery::mock( WC_Order::class );
			$mock->shouldReceive( 'get_id' )->andReturn( $orderId )->byDefault();
			$mock->shouldReceive( 'get_meta' )
				->with( '_spreadconnect_state' )
				->andReturn( $currentMeta )
				->byDefault();
			$mock->shouldReceive( 'add_order_note' )->andReturn( 1 )->byDefault();
			return $mock;
		}

		// ===================================================================
		// AC-1: CAS submitting->NEW updates row, returns true
		// ===================================================================

		/**
		 * AC-1: GIVEN order with _spreadconnect_state='submitting'
		 *       WHEN compareAndSet($order, 'submitting', 'NEW')
		 *       THEN returns true, exactly one $wpdb->query() with UPDATE
		 *            statement matching WHERE order_id, meta_key, meta_value=expected.
		 */
		public function test_cas_submitting_to_new_succeeds_on_match(): void
		{
			$wpdb  = $this->mockWpdb();
			$order = $this->mockOrder( 42, 'submitting' );

			$capturedSql = null;
			$wpdb->shouldReceive( 'query' )
				->once()
				->andReturnUsing( static function ( string $sql ) use ( &$capturedSql ): int {
					$capturedSql = $sql;
					return 1;
				} );

			$sm     = new OrderStateMachine( $wpdb, null );
			$result = $sm->compareAndSet( $order, 'submitting', 'NEW' );

			self::assertTrue( $result );
			self::assertNotNull( $capturedSql );
			self::assertMatchesRegularExpression(
				'/UPDATE\s+wp_wc_orders_meta/i',
				$capturedSql,
				'CAS UPDATE must target HPOS table wp_wc_orders_meta.'
			);
			self::assertStringContainsString( '42', $capturedSql, 'order_id must be present.' );
			self::assertStringContainsString( "'submitting'", $capturedSql, "expected meta_value='submitting' must be in WHERE." );
			self::assertStringContainsString( "'NEW'", $capturedSql, 'target value NEW must be in SET.' );
		}

		/**
		 * AC-1: SQL shape: UPDATE wp_wc_orders_meta SET meta_value=...
		 * WHERE order_id=... AND meta_key='_spreadconnect_state'
		 * AND meta_value=expected.
		 */
		public function test_cas_uses_hpos_table_with_where_meta_value_expected(): void
		{
			$wpdb  = $this->mockWpdb();
			$order = $this->mockOrder( 100, 'submitting' );

			$capturedSql = null;
			$wpdb->shouldReceive( 'query' )
				->once()
				->andReturnUsing( static function ( string $sql ) use ( &$capturedSql ): int {
					$capturedSql = $sql;
					return 1;
				} );

			$sm = new OrderStateMachine( $wpdb, null );
			$sm->compareAndSet( $order, 'submitting', 'NEW' );

			self::assertNotNull( $capturedSql );
			self::assertMatchesRegularExpression( '/UPDATE\s+wp_wc_orders_meta\s+SET\s+meta_value/i', $capturedSql );
			self::assertMatchesRegularExpression( '/WHERE\s+order_id\s*=\s*100/i', $capturedSql );
			self::assertMatchesRegularExpression( "/meta_key\s*=\s*'_spreadconnect_state'/i", $capturedSql );
			self::assertMatchesRegularExpression( "/meta_value\s*=\s*'submitting'/i", $capturedSql );
		}

		// ===================================================================
		// AC-2: CAS rejected when current state already advanced
		// ===================================================================

		/**
		 * AC-2: GIVEN order with _spreadconnect_state='PROCESSED' (webhook win)
		 *       WHEN compareAndSet($order, 'submitting', 'NEW')
		 *       THEN returns false, UPDATE returns 0 affected rows,
		 *            no add_order_note call.
		 */
		public function test_cas_rejected_when_current_state_advanced_to_processed(): void
		{
			$wpdb  = $this->mockWpdb();
			$order = $this->mockOrder( 7, 'PROCESSED' );

			$wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

			// Note must NOT be added on rejection.
			$order->shouldNotReceive( 'add_order_note' );

			$sm     = new OrderStateMachine( $wpdb, null );
			$result = $sm->compareAndSet( $order, 'submitting', 'NEW' );

			self::assertFalse( $result );
		}

		/**
		 * AC-2: No update_meta_data / save call on WC_Order when CAS rejects.
		 * (We assert via Mockery `shouldNotReceive`. The mock would FAIL
		 * `Mockery::close()` if called.)
		 */
		public function test_cas_failure_does_not_call_update_meta_data(): void
		{
			$wpdb = $this->mockWpdb();

			/** @var WC_Order&\Mockery\MockInterface $order */
			$order = Mockery::mock( WC_Order::class );
			$order->shouldReceive( 'get_id' )->andReturn( 7 );
			$order->shouldReceive( 'get_meta' )->andReturn( 'PROCESSED' );
			// add_order_note is allowed but expected NOT to be called.
			$order->shouldNotReceive( 'add_order_note' );

			$wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

			$sm     = new OrderStateMachine( $wpdb, null );
			$result = $sm->compareAndSet( $order, 'submitting', 'NEW' );

			self::assertFalse( $result );
		}

		// ===================================================================
		// AC-3: Initial-Insert path (expected='', no meta row exists)
		// ===================================================================

		/**
		 * AC-3: GIVEN order without _spreadconnect_state meta
		 *       WHEN compareAndSet($order, '', 'submitting')
		 *       THEN INSERT path triggers, returns true.
		 */
		public function test_cas_inserts_meta_when_expected_empty_and_no_row_exists(): void
		{
			$wpdb  = $this->mockWpdb();
			$order = $this->mockOrder( 99, '' );

			// SELECT returns null (no row exists).
			$wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

			// INSERT returns 1 (success).
			$capturedSql = null;
			$wpdb->shouldReceive( 'query' )
				->once()
				->andReturnUsing( static function ( string $sql ) use ( &$capturedSql ): int {
					$capturedSql = $sql;
					return 1;
				} );

			$sm     = new OrderStateMachine( $wpdb, null );
			$result = $sm->compareAndSet( $order, '', 'submitting' );

			self::assertTrue( $result );
			self::assertNotNull( $capturedSql );
			self::assertMatchesRegularExpression( '/INSERT\s+INTO\s+wp_wc_orders_meta/i', $capturedSql );
			self::assertStringContainsString( "'_spreadconnect_state'", $capturedSql );
			self::assertStringContainsString( "'submitting'", $capturedSql );
		}

		/**
		 * AC-3: GIVEN order WITH existing _spreadconnect_state meta
		 *       WHEN compareAndSet($order, '', 'submitting')
		 *       THEN returns false (caller's "no meta yet" assumption violated),
		 *            no INSERT executed.
		 */
		public function test_cas_with_empty_expected_returns_false_if_meta_exists(): void
		{
			$wpdb  = $this->mockWpdb();
			$order = $this->mockOrder( 99, 'submitting' );

			// SELECT returns 'submitting' — row already exists.
			$wpdb->shouldReceive( 'get_var' )->once()->andReturn( 'submitting' );

			// query() must NOT be called for INSERT.
			$wpdb->shouldNotReceive( 'query' );

			$sm     = new OrderStateMachine( $wpdb, null );
			$result = $sm->compareAndSet( $order, '', 'submitting' );

			self::assertFalse( $result );
		}

		// ===================================================================
		// AC-4: Class constants (6 persistent states) + invalid-arg guard
		// ===================================================================

		/**
		 * AC-4 / AC-Constants: All six persistent states exposed as
		 * public class constants on OrderStateMachine.
		 */
		public function test_six_persistent_states_exposed_as_class_constants(): void
		{
			$ref = new ReflectionClass( OrderStateMachine::class );

			$expected = [
				'STATE_SUBMITTING'        => 'submitting',
				'STATE_NEW'               => 'NEW',
				'STATE_CONFIRMED'         => 'CONFIRMED',
				'STATE_PROCESSED'         => 'PROCESSED',
				'STATE_CANCELLED'         => 'CANCELLED',
				'STATE_FAILED_TO_SUBMIT'  => 'failed_to_submit',
			];

			foreach ( $expected as $constName => $constValue ) {
				self::assertTrue(
					$ref->hasConstant( $constName ),
					"Constant $constName must be exposed."
				);
				self::assertSame( $constValue, $ref->getConstant( $constName ) );
			}
		}

		/**
		 * AC-4: GIVEN invalid $target (e.g. 'foo')
		 *       WHEN compareAndSet is called
		 *       THEN InvalidArgumentException with prefix
		 *            "OrderStateMachine: invalid state '...'", and NO SQL.
		 */
		public function test_invalid_target_state_throws_and_does_not_query(): void
		{
			$wpdb  = $this->mockWpdb();
			$order = $this->mockOrder();

			$wpdb->shouldNotReceive( 'query' );
			$wpdb->shouldNotReceive( 'get_var' );

			$sm = new OrderStateMachine( $wpdb, null );

			$caught = null;
			try {
				$sm->compareAndSet( $order, 'submitting', 'foo' );
			} catch ( InvalidArgumentException $e ) {
				$caught = $e;
			}

			self::assertInstanceOf( InvalidArgumentException::class, $caught );
			self::assertStringContainsString( "invalid state 'foo'", $caught->getMessage() );
			self::assertStringStartsWith( 'OrderStateMachine:', $caught->getMessage() );
		}

		/**
		 * AC-4: GIVEN invalid $expected (not in the 6 states + not '')
		 *       THEN InvalidArgumentException, no SQL.
		 */
		public function test_invalid_expected_state_throws(): void
		{
			$wpdb  = $this->mockWpdb();
			$order = $this->mockOrder();

			$wpdb->shouldNotReceive( 'query' );
			$wpdb->shouldNotReceive( 'get_var' );

			$sm = new OrderStateMachine( $wpdb, null );

			$caught = null;
			try {
				$sm->compareAndSet( $order, 'invalid', 'NEW' );
			} catch ( InvalidArgumentException $e ) {
				$caught = $e;
			}

			self::assertInstanceOf( InvalidArgumentException::class, $caught );
			self::assertStringContainsString( "invalid state 'invalid'", $caught->getMessage() );
		}

		/**
		 * AC-4: 'needs_action' (orthogonal flag, not part of state-enum) is
		 * rejected as both expected and target.
		 */
		public function test_needs_action_string_is_rejected_as_state(): void
		{
			$wpdb  = $this->mockWpdb();
			$order = $this->mockOrder();
			$wpdb->shouldNotReceive( 'query' );

			$sm = new OrderStateMachine( $wpdb, null );

			$this->expectException( InvalidArgumentException::class );
			$sm->compareAndSet( $order, 'submitting', 'needs_action' );
		}

		// ===================================================================
		// AC-5: Successful CAS adds private order-note "state X -> Y"
		// ===================================================================

		/**
		 * AC-5: WHEN compareAndSet succeeds
		 *       THEN add_order_note('Spreadconnect: state X -> Y', false, false).
		 */
		public function test_successful_cas_adds_private_order_note(): void
		{
			$wpdb = $this->mockWpdb();

			/** @var WC_Order&\Mockery\MockInterface $order */
			$order = Mockery::mock( WC_Order::class );
			$order->shouldReceive( 'get_id' )->andReturn( 42 );
			$order->shouldReceive( 'get_meta' )->andReturn( 'submitting' );

			// Expect note with the exact transition format.
			$capturedNote = null;
			$capturedIsCustomer = null;
			$capturedAddedByUser = null;
			$order->shouldReceive( 'add_order_note' )
				->once()
				->andReturnUsing( static function ( string $note, bool $isCustomer, bool $addedByUser ) use ( &$capturedNote, &$capturedIsCustomer, &$capturedAddedByUser ): int {
					$capturedNote        = $note;
					$capturedIsCustomer  = $isCustomer;
					$capturedAddedByUser = $addedByUser;
					return 1;
				} );

			$wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

			$sm     = new OrderStateMachine( $wpdb, null );
			$result = $sm->compareAndSet( $order, 'submitting', 'NEW' );

			self::assertTrue( $result );
			self::assertNotNull( $capturedNote );
			self::assertStringContainsString( 'Spreadconnect:', $capturedNote );
			self::assertStringContainsString( 'submitting', $capturedNote );
			self::assertStringContainsString( '->', $capturedNote );
			self::assertStringContainsString( 'NEW', $capturedNote );
			self::assertFalse( $capturedIsCustomer, 'is_customer_note must be false (private note).' );
			self::assertFalse( $capturedAddedByUser, 'added_by_user must be false.' );
		}

		// ===================================================================
		// AC-6: Failed CAS logs info-level entry
		// ===================================================================

		/**
		 * AC-6: GIVEN failed CAS (current=PROCESSED, expected=submitting)
		 *       WHEN compareAndSet returns false
		 *       THEN wc_get_logger()->log('info', '...current=PROCESSED...',
		 *            ['source' => 'spreadconnect-order-service']) is called.
		 */
		public function test_failed_cas_logs_info_with_current_state(): void
		{
			$wpdb  = $this->mockWpdb();
			$order = $this->mockOrder( 42, 'PROCESSED' );

			$wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

			/** @var WC_Logger&\Mockery\MockInterface $logger */
			$logger = Mockery::mock( WC_Logger::class );

			$capturedLevel  = null;
			$capturedMsg    = null;
			$capturedCtx    = null;
			$logger->shouldReceive( 'log' )
				->once()
				->andReturnUsing( static function ( string $level, string $message, array $context ) use ( &$capturedLevel, &$capturedMsg, &$capturedCtx ): void {
					$capturedLevel = $level;
					$capturedMsg   = $message;
					$capturedCtx   = $context;
				} );

			$sm     = new OrderStateMachine( $wpdb, $logger );
			$result = $sm->compareAndSet( $order, 'submitting', 'NEW' );

			self::assertFalse( $result );
			self::assertSame( 'info', $capturedLevel );
			self::assertSame( 'spreadconnect-order-service', $capturedCtx['source'] ?? null );
			self::assertStringContainsString( 'CAS rejected', $capturedMsg );
			self::assertStringContainsString( 'order_id=42', $capturedMsg );
			self::assertStringContainsString( 'expected=submitting', $capturedMsg );
			self::assertStringContainsString( 'target=NEW', $capturedMsg );
			self::assertStringContainsString( 'current=PROCESSED', $capturedMsg );
		}

		/**
		 * AC-6: Failed CAS does NOT log warning/error level.
		 */
		public function test_failed_cas_does_not_log_warning_or_error(): void
		{
			$wpdb  = $this->mockWpdb();
			$order = $this->mockOrder( 42, 'PROCESSED' );
			$wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

			/** @var WC_Logger&\Mockery\MockInterface $logger */
			$logger = Mockery::mock( WC_Logger::class );

			$capturedLevels = [];
			$logger->shouldReceive( 'log' )
				->andReturnUsing( static function ( string $level ) use ( &$capturedLevels ): void {
					$capturedLevels[] = $level;
				} );

			$sm = new OrderStateMachine( $wpdb, $logger );
			$sm->compareAndSet( $order, 'submitting', 'NEW' );

			self::assertNotContains( 'warning', $capturedLevels );
			self::assertNotContains( 'error', $capturedLevels );
			self::assertNotContains( 'critical', $capturedLevels );
		}

		// ===================================================================
		// AC-7: SQL uses wpdb->prefix interpolation + prepare placeholders
		// ===================================================================

		/**
		 * AC-7: SQL must interpolate $wpdb->prefix (no hardcoded 'wp_').
		 * Switch prefix to 'foo_' and assert table reference reflects that.
		 */
		public function test_sql_uses_wpdb_prefix_interpolation(): void
		{
			$wpdb         = $this->mockWpdb();
			$wpdb->prefix = 'foo_';
			$order        = $this->mockOrder( 11, 'submitting' );

			$capturedQuerySql   = null;
			$capturedPrepareSql = null;

			// Override default prepare to capture full SQL string passed.
			$wpdb->shouldReceive( 'prepare' )
				->andReturnUsing( static function ( string $query, ...$args ) use ( &$capturedPrepareSql ): string {
					$capturedPrepareSql = $query;
					$result             = $query;
					foreach ( $args as $arg ) {
						$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
						$result      = preg_replace( '/%[ds]/', $replacement, $result, 1 ) ?? $result;
					}
					return $result;
				} );
			$wpdb->shouldReceive( 'query' )
				->once()
				->andReturnUsing( static function ( string $sql ) use ( &$capturedQuerySql ): int {
					$capturedQuerySql = $sql;
					return 1;
				} );

			$sm = new OrderStateMachine( $wpdb, null );
			$sm->compareAndSet( $order, 'submitting', 'NEW' );

			self::assertNotNull( $capturedQuerySql );
			self::assertStringContainsString(
				'foo_wc_orders_meta',
				$capturedQuerySql,
				'Custom prefix must be interpolated into SQL — no hardcoded wp_.'
			);
			// Direct hardcoded literal `wp_wc_orders_meta` would mean the
			// implementation didnt use $wpdb->prefix.
			self::assertStringNotContainsString( ' wp_wc_orders_meta', $capturedQuerySql );
		}

		/**
		 * AC-7: All three params bound via wpdb->prepare with %d/%s placeholders.
		 * UPDATE path receives 4 params (target=%s, order_id=%d, meta_key=%s,
		 * expected=%s) — the count distinguishes prepare-bound from naked SQL.
		 */
		public function test_prepare_binds_order_id_expected_target_with_placeholders(): void
		{
			$wpdb  = $this->mockWpdb();
			$order = $this->mockOrder( 42, 'submitting' );

			$capturedQuery = null;
			$capturedArgs  = null;

			$wpdb->shouldReceive( 'prepare' )
				->andReturnUsing( static function ( string $query, ...$args ) use ( &$capturedQuery, &$capturedArgs ): string {
					$capturedQuery = $query;
					$capturedArgs  = $args;
					$result        = $query;
					foreach ( $args as $arg ) {
						$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
						$result      = preg_replace( '/%[ds]/', $replacement, $result, 1 ) ?? $result;
					}
					return $result;
				} );

			$wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

			$sm = new OrderStateMachine( $wpdb, null );
			$sm->compareAndSet( $order, 'submitting', 'NEW' );

			self::assertNotNull( $capturedQuery );

			// Verify placeholder coverage: %d for order_id, %s for meta_key/value.
			self::assertStringContainsString( '%d', $capturedQuery, '%d placeholder must be present for order_id.' );
			self::assertStringContainsString( '%s', $capturedQuery, '%s placeholder must be present for strings.' );

			// Verify args contain the expected bound values.
			self::assertContains( 42, $capturedArgs );
			self::assertContains( 'NEW', $capturedArgs );
			self::assertContains( 'submitting', $capturedArgs );
			self::assertContains( '_spreadconnect_state', $capturedArgs );
		}

		// ===================================================================
		// AC-8: Class is final, constructor accepts wpdb + nullable logger
		// ===================================================================

		/**
		 * AC-8: OrderStateMachine is `final class` and constructor accepts
		 * `(wpdb $wpdb, ?WC_Logger $logger = null)`.
		 */
		public function test_class_is_final_and_constructor_accepts_optional_logger(): void
		{
			$ref = new ReflectionClass( OrderStateMachine::class );
			self::assertTrue( $ref->isFinal(), 'OrderStateMachine must be final.' );

			$ctor = $ref->getConstructor();
			self::assertNotNull( $ctor );

			$params = $ctor->getParameters();
			self::assertCount( 2, $params );

			// First param: wpdb (no default).
			self::assertSame( 'wpdb', $params[0]->getName() );
			$wpdbType = $params[0]->getType();
			self::assertNotNull( $wpdbType );
			self::assertSame( 'wpdb', (string) $wpdbType );
			self::assertFalse( $params[0]->isOptional() );

			// Second param: ?WC_Logger with default null.
			self::assertSame( 'logger', $params[1]->getName() );
			self::assertTrue( $params[1]->isOptional() );
			self::assertTrue( $params[1]->allowsNull() );
			$loggerType = $params[1]->getType();
			self::assertNotNull( $loggerType );
			// Type can be `?WC_Logger` (nullable).
			self::assertStringContainsString( 'WC_Logger', (string) $loggerType );
		}

		/**
		 * AC-8: Constructor with null logger does NOT throw and CAS still works.
		 * The `wc_get_logger()`-fallback is only used on the rejection path
		 * (info-level log). Use Brain\Monkey to stub a fallback logger.
		 */
		public function test_cas_works_without_logger_injection(): void
		{
			$wpdb  = $this->mockWpdb();
			$order = $this->mockOrder( 42, 'submitting' );

			$wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

			// Stub global wc_get_logger() so any fallback call doesn't fatal.
			$fallbackSpy = new LoggerSpy();
			Functions\when( 'wc_get_logger' )->alias( static function () use ( $fallbackSpy ) {
				return $fallbackSpy;
			} );

			// Constructor accepts null logger, no throw.
			$sm = new OrderStateMachine( $wpdb, null );

			$result = $sm->compareAndSet( $order, 'submitting', 'NEW' );
			self::assertTrue( $result, 'CAS must succeed without injected logger.' );
		}
	}

	/**
	 * Minimal logger spy for the AC-8 wc_get_logger() fallback path.
	 */
	final class OrderStateMachineLoggerSpy
	{
		/**
		 * @var list<array{level:string,message:string,context:array<string,mixed>}>
		 */
		public array $calls = [];

		public function log( string $level, string $message, array $context = [] ): void
		{
			$this->calls[] = [
				'level'   => $level,
				'message' => $message,
				'context' => $context,
			];
		}
	}

}
