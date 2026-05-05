<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice-29 — Order-Confirm + Order-Cancel-Jobs
// ---------------------------------------------------------------------------
//
// Covers two production classes:
//   - SpreadconnectPod\Order\OrderConfirmJob (AC-1..AC-6, AC-11)
//   - SpreadconnectPod\Order\OrderCancelJob  (AC-7..AC-10, AC-11)
// + Bootstrap hook-wiring for both AS-actions (AC-12).
//
// Mocking strategy per slice spec: `mock_external` — Brain\Monkey for
// `wc_get_order`, `wc_get_logger`, `get_option`. Mockery doubles for
// SpreadconnectClient + WC_Order + WC_Logger. The production
// `OrderStateMachine` class is `final`, so Mockery cannot subclass it. We
// use a Mockery `overload:` mock-strategy is not available either (the SM
// already loaded); instead, we exploit the fact that the slice-29 jobs only
// call ONE method on the SM (`compareAndSet`) — we wrap a real `OrderStateMachine`
// instance backed by a programmable wpdb double whose `query()` outcome
// (1=true / 0=false) determines CAS. The captured SQL string carries both
// `expected` and `target` literals, so "was compareAndSet(NEW, CONFIRMED)
// invoked?" is provable from the SQL trace.
//
// Stub classes (WC_Order / WC_Logger / wpdb / WC_Product / WC_Order_Item_Product)
// live in `tests/stubs/wc-classes.php` and are loaded centrally by
// `tests/bootstrap.php` (slice-28 shared stubs).
// ---------------------------------------------------------------------------

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use Mockery;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Api\SpreadconnectClient;
	use SpreadconnectPod\Api\SpreadconnectClientError;
	use SpreadconnectPod\Api\SpreadconnectTransientError;
	use SpreadconnectPod\Order\OrderCancelJob;
	use SpreadconnectPod\Order\OrderConfirmJob;
	use SpreadconnectPod\Order\OrderStateMachine;
	use WC_Logger;
	use WC_Order;
	use wpdb;

	/**
	 * Slice 29 — Order-Confirm + Order-Cancel-Jobs.
	 *
	 * Each test maps 1:1 to a spec acceptance criterion via the docblock
	 * GIVEN/WHEN/THEN. AC-Coverage:
	 *
	 *   - OrderConfirmJob:
	 *       AC-1: pre-check fails without shipping-type
	 *       AC-2: 2xx success with per-order shipping-type meta
	 *       AC-3: 2xx success with default-shipping-type setting fallback
	 *       AC-4: skip when state !== NEW
	 *       AC-5: 4xx permanent error path
	 *       AC-6: 5xx transient error path (rethrow for AS retry)
	 *       AC-11: skip when `_spreadconnect_order_id` meta missing
	 *
	 *   - OrderCancelJob:
	 *       AC-7: 2xx success when state === NEW
	 *       AC-8: skip with state-aware note when state !== NEW
	 *       AC-9: 4xx permanent error path
	 *       AC-10: 5xx transient error path (rethrow for AS retry)
	 *       AC-11: skip when `_spreadconnect_order_id` meta missing
	 *
	 *   - Bootstrap:
	 *       AC-12: confirm + cancel hooks registered idempotently
	 */
	final class Slice29OrderConfirmCancelJobsTest extends TestCase
	{
		/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
		private array $loggerCalls = [];

		/** @var list<int> FIFO queue of `wpdb->query()` return values (CAS results). */
		private array $wpdbQueryQueue = [];

		/** @var list<mixed> FIFO queue of `wpdb->get_var()` return values. */
		private array $wpdbGetVarQueue = [];

		/** @var list<string> Captured SQL strings issued via `wpdb->query()`. */
		private array $capturedSqls = [];

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->loggerCalls    = [];
			$this->wpdbQueryQueue  = [];
			$this->wpdbGetVarQueue = [];
			$this->capturedSqls   = [];

			// Default `wc_get_logger()` fallback — production `*Job::log*`
			// helpers `function_exists()`-probe for `wc_get_logger`. Returning
			// a recording spy keeps logging defensive even when no logger is
			// injected.
			$spy = new Slice29LoggerSpy();
			Functions\when( 'wc_get_logger' )->alias( static function () use ( $spy ) {
				return $spy;
			} );

			// Default `get_option()` returns empty for the default-shipping-type
			// option unless a test overrides it. Other option calls land here
			// too — they all return the supplied default.
			Functions\when( 'get_option' )->alias(
				static function ( string $key, $default = false ) {
					return $default;
				}
			);
		}

		protected function tearDown(): void
		{
			// Reset Plugin-internal static state so AC-12 idempotency tests
			// stay clean across the suite.
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

			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// ===================================================================
		// Helpers — WC_Order / Logger / Client / wpdb mocks
		// ===================================================================

		/**
		 * Build a Mockery {@see WC_Order} double whose `get_meta()` returns
		 * the per-key value from `$metaValues` (default: empty string).
		 *
		 * @param array<string,string> $metaValues Per-key meta values.
		 *
		 * @return WC_Order&\Mockery\MockInterface
		 */
		private function mockOrder( int $id = 7, array $metaValues = [] ): WC_Order
		{
			/** @var WC_Order&\Mockery\MockInterface $order */
			$order = Mockery::mock( WC_Order::class );
			$order->shouldReceive( 'get_id' )->andReturn( $id )->byDefault();

			$order->shouldReceive( 'get_meta' )
				->andReturnUsing( static function ( string $key ) use ( $metaValues ): string {
					return (string) ( $metaValues[ $key ] ?? '' );
				} )
				->byDefault();

			$order->shouldReceive( 'add_order_note' )->andReturn( 1 )->byDefault();
			$order->shouldReceive( 'update_meta_data' )->andReturnNull()->byDefault();
			$order->shouldReceive( 'save' )->andReturn( $id )->byDefault();

			return $order;
		}

		/**
		 * @return SpreadconnectClient&\Mockery\MockInterface
		 */
		private function mockClient(): SpreadconnectClient
		{
			/** @var SpreadconnectClient&\Mockery\MockInterface $mock */
			$mock = Mockery::mock( SpreadconnectClient::class );
			return $mock;
		}

		/**
		 * Recording WC_Logger Mockery double; every `log()` call is appended
		 * to `$this->loggerCalls`.
		 *
		 * @return WC_Logger&\Mockery\MockInterface
		 */
		private function recordingLogger(): WC_Logger
		{
			/** @var WC_Logger&\Mockery\MockInterface $logger */
			$logger = Mockery::mock( WC_Logger::class );
			$logger->shouldReceive( 'log' )
				->andReturnUsing( function ( string $level, string $message, array $context = [] ): void {
					$this->loggerCalls[] = [
						'level'   => $level,
						'message' => $message,
						'context' => $context,
					];
				} );
			return $logger;
		}

		/**
		 * Build a real {@see OrderStateMachine} backed by a programmable
		 * Mockery `wpdb` double. SM is `final` so it cannot be mocked
		 * directly; tests drive every CAS outcome by pushing `query()` /
		 * `get_var()` return values onto FIFO queues. Captured SQL strings
		 * carry the (expected, target) literals, so callers can assert
		 * which CAS transition fired (or that none did).
		 */
		private function realStateMachine(): OrderStateMachine
		{
			/** @var wpdb&\Mockery\MockInterface $wpdbMock */
			$wpdbMock         = Mockery::mock( wpdb::class );
			$wpdbMock->prefix = 'wp_';

			$wpdbMock->shouldReceive( 'prepare' )
				->andReturnUsing( static function ( string $query, ...$args ): string {
					$result = $query;
					foreach ( $args as $arg ) {
						$replacement = is_int( $arg )
							? (string) $arg
							: "'" . str_replace( "'", "''", (string) $arg ) . "'";
						$result      = preg_replace( '/%[ds]/', $replacement, $result, 1 ) ?? $result;
					}
					return $result;
				} );

			$queryQueue =& $this->wpdbQueryQueue;
			$captured =& $this->capturedSqls;
			$wpdbMock->shouldReceive( 'query' )
				->andReturnUsing( static function ( string $sql ) use ( &$queryQueue, &$captured ): int {
					$captured[] = $sql;
					return array_shift( $queryQueue ) ?? 0;
				} );

			$getVarQueue =& $this->wpdbGetVarQueue;
			$wpdbMock->shouldReceive( 'get_var' )
				->andReturnUsing( static function ( string $sql ) use ( &$getVarQueue ) {
					if ( [] === $getVarQueue ) {
						return null;
					}
					return array_shift( $getVarQueue );
				} );

			return new OrderStateMachine( $wpdbMock, null );
		}

		/**
		 * Push a CAS-success (`UPDATE … WHERE state=expected` returns
		 * affected_rows=1).
		 */
		private function enqueueCasSuccess(): void
		{
			$this->wpdbQueryQueue[] = 1;
		}

		// ===================================================================
		// AC-1: Confirm pre-check fails without shipping-type.
		// ===================================================================

		/**
		 * AC-1: GIVEN WC-Order in state NEW with `_spreadconnect_order_id='sc_42'`,
		 *       no `_spreadconnect_shipping_type` meta, and empty
		 *       `spreadconnect_default_shipping_type` option.
		 *       WHEN  `OrderConfirmJob::handle(['order_id' => 7])`.
		 *       THEN  `confirmOrder()` is NEVER called; `compareAndSet()` is
		 *             NEVER asked to flip to CONFIRMED (no SQL); an order-note
		 *             with substring 'Cannot confirm: no shipping type set'
		 *             is added; a warning-log with tag
		 *             `confirm_pre_check_failed` and source
		 *             `spreadconnect-order-service` is emitted; the job
		 *             returns cleanly (no rethrow).
		 */
		public function test_confirm_pre_check_fails_when_no_shipping_type_anywhere(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id' => 'sc_42',
				'_spreadconnect_state'    => 'NEW',
			] );

			// Default shipping-type option = '' (set by setUp default).
			$client = $this->mockClient();
			$client->shouldNotReceive( 'confirmOrder' );

			$capturedNote = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note ) use ( &$capturedNote ): int {
					$capturedNote = $note;
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderConfirmJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-1 (e): pre-check failure MUST NOT rethrow.' );

			// AC-1 (b): no CAS write — no SQL was issued at all.
			self::assertCount( 0, $this->capturedSqls, 'AC-1 (b): no CAS SQL MUST be issued on pre-check failure.' );

			// AC-1 (c): order-note carries the contracted substring.
			self::assertNotNull( $capturedNote, 'AC-1 (c): an order-note MUST be added.' );
			self::assertStringContainsString(
				'Cannot confirm: no shipping type set',
				(string) $capturedNote
			);
		}

		/**
		 * AC-1 (d): the warning-level log carries source
		 * `spreadconnect-order-service` and tag `confirm_pre_check_failed`.
		 */
		public function test_confirm_pre_check_failure_logs_confirm_pre_check_failed_tag(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id' => 'sc_42',
				'_spreadconnect_state'    => 'NEW',
			] );

			$client = $this->mockClient();
			$client->shouldNotReceive( 'confirmOrder' );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderConfirmJob( $client, $this->realStateMachine(), $this->recordingLogger() );
			$job->handle( [ 'order_id' => 7 ] );

			$matches = array_filter(
				$this->loggerCalls,
				static function ( array $c ): bool {
					return 'warning' === $c['level']
						&& 'confirm_pre_check_failed' === ( $c['context']['tag'] ?? null );
				}
			);
			self::assertNotEmpty(
				$matches,
				'AC-1 (d): a warning log entry with tag "confirm_pre_check_failed" MUST be emitted.'
			);
			$entry = array_values( $matches )[0];
			self::assertSame( 'spreadconnect-order-service', $entry['context']['source'] ?? null );
		}

		// ===================================================================
		// AC-2: Confirm 2xx success — shipping-type from order-meta.
		// ===================================================================

		/**
		 * AC-2: GIVEN WC-Order in state NEW with `_spreadconnect_order_id='sc_42'`
		 *       and `_spreadconnect_shipping_type='STANDARD'`. confirmOrder
		 *       returns 2xx.
		 *       WHEN  `OrderConfirmJob::handle(['order_id' => 7])`.
		 *       THEN  (a) `confirmOrder('sc_42')` called exactly once;
		 *             (b) CAS `NEW -> CONFIRMED` invoked (visible as UPDATE
		 *                 SQL with both literals); (c) order-note carries the
		 *                 'Confirmed in Spreadconnect (#SC-sc_42)' substring;
		 *             (d) job does not throw.
		 */
		public function test_confirm_success_with_order_meta_shipping_type(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id'      => 'sc_42',
				'_spreadconnect_shipping_type' => 'STANDARD',
				'_spreadconnect_state'         => 'NEW',
			] );

			$capturedSequence = [];

			$client = $this->mockClient();
			$client->shouldReceive( 'confirmOrder' )
				->once()
				->with( 'sc_42' )
				->andReturnUsing( static function ( string $id ) use ( &$capturedSequence ): array {
					$capturedSequence[] = 'confirmOrder';
					return [ 'id' => $id, 'state' => 'CONFIRMED' ];
				} );

			$capturedNote = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note ) use ( &$capturedNote, &$capturedSequence ): int {
					if ( str_contains( $note, 'Confirmed in Spreadconnect' ) ) {
						$capturedNote       = $note;
						$capturedSequence[] = 'confirm_note';
					} else {
						// SM-internal transition note — recorded as a generic
						// marker so we can prove the CAS-write happened.
						$capturedSequence[] = 'sm_note';
					}
					return 1;
				} );

			// CAS NEW -> CONFIRMED returns success.
			$this->enqueueCasSuccess();

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderConfirmJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-2 (d): the job MUST NOT throw on 2xx.' );

			// AC-2 (b): CAS NEW -> CONFIRMED — SQL contains both literals.
			self::assertCount( 1, $this->capturedSqls, 'AC-2 (b): exactly ONE CAS UPDATE MUST fire.' );
			self::assertMatchesRegularExpression(
				"/UPDATE\s+wp_wc_orders_meta.+'CONFIRMED'.+'NEW'/is",
				$this->capturedSqls[0],
				'AC-2 (b): SM MUST be asked to CAS NEW -> CONFIRMED.'
			);

			// AC-2 (a) + (c): call ordering — confirmOrder before the
			// confirmed-note (sm_note may appear between them since SM
			// adds an internal transition note inside compareAndSet()).
			self::assertContains( 'confirmOrder', $capturedSequence );
			self::assertContains( 'confirm_note', $capturedSequence );
			$confirmIdx = array_search( 'confirmOrder', $capturedSequence, true );
			$noteIdx    = array_search( 'confirm_note', $capturedSequence, true );
			self::assertLessThan( $noteIdx, $confirmIdx, 'AC-2: confirmOrder MUST run BEFORE the confirmed-note.' );
		}

		/**
		 * AC-2 (c): the 2xx-path order-note carries the
		 * `Confirmed in Spreadconnect (#SC-<id>)` shape with
		 * is_customer_note=false and added_by_user=false.
		 */
		public function test_confirm_success_writes_confirmed_order_note(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id'      => 'sc_42',
				'_spreadconnect_shipping_type' => 'STANDARD',
				'_spreadconnect_state'         => 'NEW',
			] );

			$client = $this->mockClient();
			$client->shouldReceive( 'confirmOrder' )->andReturn( [ 'id' => 'sc_42' ] );

			$capturedNote        = null;
			$capturedIsCustomer  = null;
			$capturedAddedByUser = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note, bool $isCustomer = false, bool $addedByUser = false ) use ( &$capturedNote, &$capturedIsCustomer, &$capturedAddedByUser ): int {
					if ( str_contains( $note, 'Confirmed in Spreadconnect' ) ) {
						$capturedNote        = $note;
						$capturedIsCustomer  = $isCustomer;
						$capturedAddedByUser = $addedByUser;
					}
					return 1;
				} );

			$this->enqueueCasSuccess();

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderConfirmJob( $client, $this->realStateMachine() );
			$job->handle( [ 'order_id' => 7 ] );

			self::assertNotNull( $capturedNote, 'AC-2 (c): a confirmed-note MUST be added.' );
			self::assertStringContainsString( 'Confirmed in Spreadconnect (#SC-sc_42)', (string) $capturedNote );
			self::assertFalse( $capturedIsCustomer, 'is_customer_note MUST be false (private note).' );
			self::assertFalse( $capturedAddedByUser, 'added_by_user MUST be false.' );
		}

		// ===================================================================
		// AC-3: Confirm 2xx success — fallback to default shipping-type setting.
		// ===================================================================

		/**
		 * AC-3: GIVEN WC-Order in state NEW with `_spreadconnect_order_id='sc_42'`,
		 *       NO `_spreadconnect_shipping_type` meta, BUT a non-empty
		 *       `spreadconnect_default_shipping_type='STANDARD'` option.
		 *       WHEN  `OrderConfirmJob::handle(['order_id' => 7])`.
		 *       THEN  the default-setting is treated as the shipping-type for
		 *             the pre-check; `confirmOrder('sc_42')` is invoked; CAS
		 *             NEW -> CONFIRMED fires; order-note is written. No
		 *             additional `setShippingType()` call inside this job.
		 */
		public function test_confirm_uses_default_shipping_type_setting_as_fallback(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id' => 'sc_42',
				// no `_spreadconnect_shipping_type` meta
				'_spreadconnect_state'    => 'NEW',
			] );

			// Override the default get_option() stub: return a non-empty
			// default shipping-type for the option key the job probes.
			Functions\when( 'get_option' )->alias(
				static function ( string $key, $default = false ) {
					if ( 'spreadconnect_default_shipping_type' === $key ) {
						return 'STANDARD';
					}
					return $default;
				}
			);

			$client = $this->mockClient();
			$client->shouldReceive( 'confirmOrder' )
				->once()
				->with( 'sc_42' )
				->andReturn( [ 'id' => 'sc_42', 'state' => 'CONFIRMED' ] );
			// AC-3: no setShippingType call inside this job.
			$client->shouldNotReceive( 'setShippingType' );

			$this->enqueueCasSuccess();

			$capturedNote = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note ) use ( &$capturedNote ): int {
					if ( str_contains( $note, 'Confirmed in Spreadconnect' ) ) {
						$capturedNote = $note;
					}
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderConfirmJob( $client, $this->realStateMachine() );
			$job->handle( [ 'order_id' => 7 ] );

			// CAS UPDATE: NEW -> CONFIRMED.
			self::assertCount( 1, $this->capturedSqls );
			self::assertMatchesRegularExpression(
				"/UPDATE\s+wp_wc_orders_meta.+'CONFIRMED'.+'NEW'/is",
				$this->capturedSqls[0]
			);
			self::assertNotNull( $capturedNote );
			self::assertStringContainsString( '#SC-sc_42', (string) $capturedNote );
		}

		// ===================================================================
		// AC-4: Confirm — skip when state !== NEW.
		// ===================================================================

		/**
		 * AC-4: GIVEN WC-Order with `_spreadconnect_state='CONFIRMED'`,
		 *       valid shipping-type and SC-Order-ID.
		 *       WHEN  `OrderConfirmJob::handle(['order_id' => 7])`.
		 *       THEN  `confirmOrder()` is NEVER called; no CAS write to
		 *             CONFIRMED happens (no SQL or CAS returns false); an
		 *             info-log with tag `confirm_skipped_wrong_state` is
		 *             emitted; an order-note with substring
		 *             'Confirm skipped (state: CONFIRMED)' is written; the
		 *             job does NOT rethrow.
		 */
		public function test_confirm_skips_when_state_is_not_new(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id'      => 'sc_42',
				'_spreadconnect_shipping_type' => 'STANDARD',
				'_spreadconnect_state'         => 'CONFIRMED',
			] );

			$client = $this->mockClient();
			$client->shouldNotReceive( 'confirmOrder' );

			$capturedNote = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note ) use ( &$capturedNote ): int {
					$capturedNote = $note;
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderConfirmJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-4: the job MUST NOT rethrow on wrong-state skip.' );

			// No state mutation to CONFIRMED visible in SQL — either no SQL
			// (job pre-checked the state) or a CAS UPDATE that returned 0.
			// In both cases no `'CONFIRMED' WHERE … 'NEW'` UPDATE that
			// actually flipped state was applied.
			foreach ( $this->capturedSqls as $sql ) {
				self::assertDoesNotMatchRegularExpression(
					"/UPDATE\s+wp_wc_orders_meta.+'CONFIRMED'.+'NEW'/is",
					$sql,
					'AC-4: state MUST NOT be flipped to CONFIRMED on wrong-state skip.'
				);
			}

			// Order-note carries the state-aware skip text.
			self::assertNotNull( $capturedNote );
			self::assertStringContainsString( 'Confirm skipped', (string) $capturedNote );
			self::assertStringContainsString( 'CONFIRMED', (string) $capturedNote );

			// AC-4: info-log with tag `confirm_skipped_wrong_state`.
			$matches = array_filter(
				$this->loggerCalls,
				static function ( array $c ): bool {
					return 'info' === $c['level']
						&& 'confirm_skipped_wrong_state' === ( $c['context']['tag'] ?? null );
				}
			);
			self::assertNotEmpty(
				$matches,
				'AC-4: an info-log entry with tag "confirm_skipped_wrong_state" MUST be emitted.'
			);
		}

		// ===================================================================
		// AC-5: Confirm 4xx permanent path.
		// ===================================================================

		/**
		 * AC-5 (a) + (b): 4xx triggers no CAS to CONFIRMED and writes an
		 * order-note with substring 'Confirm failed (4xx)'.
		 */
		public function test_confirm_permanent_4xx_does_not_mutate_state(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id'      => 'sc_42',
				'_spreadconnect_shipping_type' => 'STANDARD',
				'_spreadconnect_state'         => 'NEW',
			] );

			$client = $this->mockClient();
			$client->shouldReceive( 'confirmOrder' )
				->once()
				->andThrow(
					new SpreadconnectClientError(
						'http_4xx',
						'Spreadconnect /orders/sc_42/confirm 400: order already confirmed',
						400,
						'/orders/sc_42/confirm'
					)
				);

			$capturedNote = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note ) use ( &$capturedNote ): int {
					$capturedNote = $note;
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderConfirmJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-5 (d): the job MUST NOT rethrow on 4xx.' );

			// AC-5 (a): NO CAS UPDATE to CONFIRMED happened — state stays NEW.
			foreach ( $this->capturedSqls as $sql ) {
				self::assertDoesNotMatchRegularExpression(
					"/'CONFIRMED'/i",
					$sql,
					'AC-5 (a): no CAS to CONFIRMED on 4xx.'
				);
			}

			// AC-5 (b): order-note carries the 'Confirm failed (4xx)' substring.
			self::assertNotNull( $capturedNote );
			self::assertStringContainsString( 'Confirm failed (4xx)', (string) $capturedNote );
		}

		/**
		 * AC-5 (c): 4xx emits an `error`-level log with context tag
		 * `failed_op_pending_record`, op_type=`confirm_order`,
		 * related_entity_type=`order`, related_entity_id=7,
		 * source=`spreadconnect-order-service`, and an `error_message`.
		 */
		public function test_confirm_permanent_4xx_logs_failed_op_pending_record_with_confirm_op_type(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id'      => 'sc_42',
				'_spreadconnect_shipping_type' => 'STANDARD',
				'_spreadconnect_state'         => 'NEW',
			] );

			$client = $this->mockClient();
			$client->shouldReceive( 'confirmOrder' )
				->andThrow(
					new SpreadconnectClientError( 'http_4xx', 'order already confirmed', 400, '/orders/sc_42/confirm' )
				);

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderConfirmJob( $client, $this->realStateMachine(), $this->recordingLogger() );
			$job->handle( [ 'order_id' => 7 ] );

			$matches = array_filter(
				$this->loggerCalls,
				static function ( array $call ): bool {
					return 'error' === $call['level']
						&& 'failed_op_pending_record' === ( $call['context']['tag'] ?? null );
				}
			);
			self::assertNotEmpty(
				$matches,
				'AC-5 (c): an error-level log entry with tag "failed_op_pending_record" MUST be emitted.'
			);

			$entry = array_values( $matches )[0];
			self::assertSame( 'spreadconnect-order-service', $entry['context']['source'] ?? null );
			self::assertSame( 'confirm_order', $entry['context']['op_type'] ?? null );
			self::assertSame( 'order', $entry['context']['related_entity_type'] ?? null );
			self::assertSame( 7, $entry['context']['related_entity_id'] ?? null );
			self::assertArrayHasKey( 'error_message', $entry['context'] );
			self::assertNotEmpty( (string) $entry['context']['error_message'] );
		}

		/**
		 * AC-5 (d): the 4xx-path does NOT rethrow — Action-Scheduler must
		 * not retry a permanent failure.
		 */
		public function test_confirm_permanent_4xx_does_not_rethrow(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id'      => 'sc_42',
				'_spreadconnect_shipping_type' => 'STANDARD',
				'_spreadconnect_state'         => 'NEW',
			] );

			$client = $this->mockClient();
			$client->shouldReceive( 'confirmOrder' )
				->andThrow( new SpreadconnectClientError( 'http_4xx', 'bad', 400, '/orders/sc_42/confirm' ) );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderConfirmJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-5 (d): no rethrow on 4xx.' );
		}

		// ===================================================================
		// AC-6: Confirm 5xx transient path — rethrow for AS retry.
		// ===================================================================

		/**
		 * AC-6: GIVEN confirmOrder() throws SpreadconnectTransientError.
		 *       THEN  state stays at NEW (no CAS to CONFIRMED); a
		 *             warning-log with substring 'transient error, AS retry'
		 *             is emitted; the same exception instance is rethrown
		 *             unchanged so AS retries.
		 */
		public function test_confirm_transient_5xx_rethrows_unchanged(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id'      => 'sc_42',
				'_spreadconnect_shipping_type' => 'STANDARD',
				'_spreadconnect_state'         => 'NEW',
			] );

			$transient = new SpreadconnectTransientError(
				'http_5xx',
				'Spreadconnect /orders/sc_42/confirm 503: gateway timeout',
				503,
				'/orders/sc_42/confirm'
			);

			$client = $this->mockClient();
			$client->shouldReceive( 'confirmOrder' )->andThrow( $transient );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderConfirmJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$caught = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( SpreadconnectTransientError $e ) {
				$caught = $e;
			}

			self::assertNotNull( $caught, 'AC-6: a SpreadconnectTransientError MUST be rethrown.' );
			self::assertSame( $transient, $caught, 'AC-6: rethrown instance MUST be identical.' );

			// AC-6 (a): no CAS to CONFIRMED.
			foreach ( $this->capturedSqls as $sql ) {
				self::assertDoesNotMatchRegularExpression(
					"/'CONFIRMED'/i",
					$sql,
					'AC-6 (a): state MUST stay NEW on transient.'
				);
			}

			// AC-6 (b): warning-log with substring 'transient error, AS retry'.
			$warningCalls = array_filter(
				$this->loggerCalls,
				static fn( array $c ): bool => 'warning' === $c['level']
					&& false !== stripos( $c['message'], 'transient error, AS retry' )
			);
			self::assertNotEmpty(
				$warningCalls,
				'AC-6 (b): a warning log entry with substring "transient error, AS retry" MUST be emitted.'
			);
		}

		// ===================================================================
		// AC-7: Cancel 2xx success — state === NEW.
		// ===================================================================

		/**
		 * AC-7: GIVEN WC-Order in state NEW with `_spreadconnect_order_id='sc_42'`.
		 *       cancelOrder returns 2xx.
		 *       WHEN  `OrderCancelJob::handle(['order_id' => 7])`.
		 *       THEN  (a) `cancelOrder('sc_42')` called exactly once;
		 *             (b) CAS NEW -> CANCELLED invoked (visible in SQL with
		 *                 both literals); (c) order-note carries the
		 *                 'Cancelled in Spreadconnect (#SC-sc_42)' substring;
		 *             (d) job does not throw.
		 */
		public function test_cancel_calls_cancel_and_cas_to_cancelled_when_state_new(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id' => 'sc_42',
				'_spreadconnect_state'    => 'NEW',
			] );

			$capturedSequence = [];

			$client = $this->mockClient();
			$client->shouldReceive( 'cancelOrder' )
				->once()
				->with( 'sc_42' )
				->andReturnUsing( static function ( string $id ) use ( &$capturedSequence ): array {
					$capturedSequence[] = 'cancelOrder';
					return [ 'id' => $id, 'state' => 'CANCELLED' ];
				} );

			$capturedNote = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note ) use ( &$capturedNote, &$capturedSequence ): int {
					if ( str_contains( $note, 'Cancelled in Spreadconnect' ) ) {
						$capturedNote       = $note;
						$capturedSequence[] = 'cancel_note';
					} else {
						$capturedSequence[] = 'sm_note';
					}
					return 1;
				} );

			$this->enqueueCasSuccess();

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderCancelJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-7 (d): the job MUST NOT throw on 2xx.' );

			// AC-7 (b): SQL evidence of CAS NEW -> CANCELLED.
			self::assertCount( 1, $this->capturedSqls, 'AC-7 (b): exactly ONE CAS UPDATE MUST fire.' );
			self::assertMatchesRegularExpression(
				"/UPDATE\s+wp_wc_orders_meta.+'CANCELLED'.+'NEW'/is",
				$this->capturedSqls[0],
				'AC-7 (b): SM MUST be asked to CAS NEW -> CANCELLED.'
			);

			// AC-7 (a) + (c): cancelOrder runs BEFORE the cancelled-note.
			self::assertContains( 'cancelOrder', $capturedSequence );
			self::assertContains( 'cancel_note', $capturedSequence );
			$cancelIdx = array_search( 'cancelOrder', $capturedSequence, true );
			$noteIdx   = array_search( 'cancel_note', $capturedSequence, true );
			self::assertLessThan( $noteIdx, $cancelIdx, 'AC-7: cancelOrder MUST run BEFORE the cancelled-note.' );
		}

		/**
		 * AC-7 (c): the 2xx-path order-note carries the
		 * `Cancelled in Spreadconnect (#SC-<id>)` shape with
		 * is_customer_note=false and added_by_user=false.
		 */
		public function test_cancel_success_writes_cancelled_order_note(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id' => 'sc_42',
				'_spreadconnect_state'    => 'NEW',
			] );

			$client = $this->mockClient();
			$client->shouldReceive( 'cancelOrder' )->andReturn( [ 'id' => 'sc_42', 'state' => 'CANCELLED' ] );

			$capturedNote        = null;
			$capturedIsCustomer  = null;
			$capturedAddedByUser = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note, bool $isCustomer = false, bool $addedByUser = false ) use ( &$capturedNote, &$capturedIsCustomer, &$capturedAddedByUser ): int {
					if ( str_contains( $note, 'Cancelled in Spreadconnect' ) ) {
						$capturedNote        = $note;
						$capturedIsCustomer  = $isCustomer;
						$capturedAddedByUser = $addedByUser;
					}
					return 1;
				} );

			$this->enqueueCasSuccess();

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderCancelJob( $client, $this->realStateMachine() );
			$job->handle( [ 'order_id' => 7 ] );

			self::assertNotNull( $capturedNote );
			self::assertStringContainsString( 'Cancelled in Spreadconnect (#SC-sc_42)', (string) $capturedNote );
			self::assertFalse( $capturedIsCustomer, 'is_customer_note MUST be false (private note).' );
			self::assertFalse( $capturedAddedByUser, 'added_by_user MUST be false.' );
		}

		// ===================================================================
		// AC-8: Cancel — skip with state-aware note when state !== NEW.
		// ===================================================================

		/**
		 * AC-8: GIVEN WC-Order with `_spreadconnect_state='CONFIRMED'`.
		 *       WHEN  `OrderCancelJob::handle(['order_id' => 7])`.
		 *       THEN  `cancelOrder()` is NEVER called; an order-note with
		 *             substring 'Cannot cancel in Spreadconnect (state:
		 *             CONFIRMED)' is written; an info-log with tag
		 *             `cancel_skipped_wrong_state` is emitted; the job does
		 *             NOT rethrow.
		 */
		public function test_cancel_skips_and_writes_state_aware_note_when_state_not_new(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id' => 'sc_42',
				'_spreadconnect_state'    => 'CONFIRMED',
			] );

			$client = $this->mockClient();
			$client->shouldNotReceive( 'cancelOrder' );

			$capturedNote = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note ) use ( &$capturedNote ): int {
					$capturedNote = $note;
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderCancelJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-8: the job MUST NOT rethrow on wrong-state skip.' );

			// AC-8: state-aware order-note.
			self::assertNotNull( $capturedNote );
			self::assertStringContainsString(
				'Cannot cancel in Spreadconnect',
				(string) $capturedNote,
				'AC-8: order-note MUST carry the "Cannot cancel in Spreadconnect" substring (architecture.md Z. 538/593).'
			);
			self::assertStringContainsString(
				'CONFIRMED',
				(string) $capturedNote,
				'AC-8: order-note MUST embed the actual state.'
			);

			// AC-8: info-log with tag `cancel_skipped_wrong_state`.
			$matches = array_filter(
				$this->loggerCalls,
				static function ( array $c ): bool {
					return 'info' === $c['level']
						&& 'cancel_skipped_wrong_state' === ( $c['context']['tag'] ?? null );
				}
			);
			self::assertNotEmpty(
				$matches,
				'AC-8: an info-log entry with tag "cancel_skipped_wrong_state" MUST be emitted.'
			);

			// And no CAS UPDATE to CANCELLED was attempted.
			foreach ( $this->capturedSqls as $sql ) {
				self::assertDoesNotMatchRegularExpression(
					"/UPDATE\s+wp_wc_orders_meta.+'CANCELLED'.+'NEW'/is",
					$sql,
					'AC-8: state MUST NOT be flipped to CANCELLED on wrong-state skip.'
				);
			}
		}

		// ===================================================================
		// AC-9: Cancel 4xx permanent path.
		// ===================================================================

		/**
		 * AC-9 (c): 4xx emits an `error`-level log with context tag
		 * `failed_op_pending_record`, op_type=`cancel_order`,
		 * related_entity_id=7, source=`spreadconnect-order-service`.
		 */
		public function test_cancel_permanent_4xx_logs_failed_op_pending_record_with_cancel_op_type(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id' => 'sc_42',
				'_spreadconnect_state'    => 'NEW',
			] );

			$client = $this->mockClient();
			$client->shouldReceive( 'cancelOrder' )
				->andThrow(
					new SpreadconnectClientError( 'http_4xx', 'cannot cancel', 400, '/orders/sc_42/cancel' )
				);

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderCancelJob( $client, $this->realStateMachine(), $this->recordingLogger() );
			$job->handle( [ 'order_id' => 7 ] );

			$matches = array_filter(
				$this->loggerCalls,
				static function ( array $call ): bool {
					return 'error' === $call['level']
						&& 'failed_op_pending_record' === ( $call['context']['tag'] ?? null );
				}
			);
			self::assertNotEmpty(
				$matches,
				'AC-9 (c): an error-level log entry with tag "failed_op_pending_record" MUST be emitted.'
			);

			$entry = array_values( $matches )[0];
			self::assertSame( 'spreadconnect-order-service', $entry['context']['source'] ?? null );
			self::assertSame( 'cancel_order', $entry['context']['op_type'] ?? null );
			self::assertSame( 'order', $entry['context']['related_entity_type'] ?? null );
			self::assertSame( 7, $entry['context']['related_entity_id'] ?? null );
			self::assertArrayHasKey( 'error_message', $entry['context'] );
		}

		/**
		 * AC-9 (a) + (b) + (d): 4xx triggers no CAS to CANCELLED, writes an
		 * order-note with substring 'Cancel failed (4xx)', and does NOT
		 * rethrow.
		 */
		public function test_cancel_permanent_4xx_does_not_mutate_state(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id' => 'sc_42',
				'_spreadconnect_state'    => 'NEW',
			] );

			$client = $this->mockClient();
			$client->shouldReceive( 'cancelOrder' )
				->andThrow( new SpreadconnectClientError( 'http_4xx', 'cannot cancel', 400, '/orders/sc_42/cancel' ) );

			$capturedNote = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note ) use ( &$capturedNote ): int {
					$capturedNote = $note;
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderCancelJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-9 (d): no rethrow on 4xx.' );

			// AC-9 (a): no CAS to CANCELLED.
			foreach ( $this->capturedSqls as $sql ) {
				self::assertDoesNotMatchRegularExpression(
					"/'CANCELLED'/i",
					$sql,
					'AC-9 (a): state MUST stay NEW on 4xx.'
				);
			}

			// AC-9 (b): order-note carries the 'Cancel failed (4xx)' substring.
			self::assertNotNull( $capturedNote );
			self::assertStringContainsString( 'Cancel failed (4xx)', (string) $capturedNote );
		}

		// ===================================================================
		// AC-10: Cancel 5xx transient path — rethrow for AS retry.
		// ===================================================================

		/**
		 * AC-10: GIVEN cancelOrder() throws SpreadconnectTransientError.
		 *        THEN state stays at NEW; warning-log; same exception
		 *        instance rethrown unchanged.
		 */
		public function test_cancel_transient_5xx_rethrows_unchanged(): void
		{
			$order = $this->mockOrder( 7, [
				'_spreadconnect_order_id' => 'sc_42',
				'_spreadconnect_state'    => 'NEW',
			] );

			$transient = new SpreadconnectTransientError(
				'http_5xx',
				'Spreadconnect /orders/sc_42/cancel 503: gateway timeout',
				503,
				'/orders/sc_42/cancel'
			);

			$client = $this->mockClient();
			$client->shouldReceive( 'cancelOrder' )->andThrow( $transient );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderCancelJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$caught = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( SpreadconnectTransientError $e ) {
				$caught = $e;
			}

			self::assertNotNull( $caught, 'AC-10: a SpreadconnectTransientError MUST be rethrown.' );
			self::assertSame( $transient, $caught, 'AC-10: rethrown instance MUST be identical.' );

			// State stays NEW: no CAS to CANCELLED issued.
			foreach ( $this->capturedSqls as $sql ) {
				self::assertDoesNotMatchRegularExpression(
					"/'CANCELLED'/i",
					$sql,
					'AC-10: state MUST stay NEW on transient.'
				);
			}

			// Warning-log entry from the transient catch-arm.
			$warningCalls = array_filter(
				$this->loggerCalls,
				static fn( array $c ): bool => 'warning' === $c['level']
			);
			self::assertNotEmpty(
				$warningCalls,
				'AC-10: a warning-level log entry MUST be emitted on transient errors.'
			);
		}

		// ===================================================================
		// AC-11: Skip when `_spreadconnect_order_id` meta missing.
		// ===================================================================

		/**
		 * AC-11 (Confirm): GIVEN WC-Order without `_spreadconnect_order_id`.
		 *                  THEN the job calls neither `confirmOrder()` nor
		 *                  `compareAndSet()`; emits an info-log with tag
		 *                  `job_skipped_no_sc_order_id`; writes no
		 *                  order-note; does NOT rethrow.
		 */
		public function test_confirm_skips_when_spreadconnect_order_id_meta_missing(): void
		{
			$order = $this->mockOrder( 7, [
				// no `_spreadconnect_order_id`
				'_spreadconnect_state' => 'NEW',
			] );

			$client = $this->mockClient();
			$client->shouldNotReceive( 'confirmOrder' );

			$order->shouldNotReceive( 'add_order_note' );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderConfirmJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-11: the job MUST NOT rethrow on idempotency-skip.' );

			// No CAS SQL — the job bails before touching the state machine.
			self::assertCount( 0, $this->capturedSqls, 'AC-11: no CAS SQL MUST be issued.' );

			$matches = array_filter(
				$this->loggerCalls,
				static function ( array $c ): bool {
					return 'info' === $c['level']
						&& 'job_skipped_no_sc_order_id' === ( $c['context']['tag'] ?? null );
				}
			);
			self::assertNotEmpty(
				$matches,
				'AC-11: an info-log entry with tag "job_skipped_no_sc_order_id" MUST be emitted (Confirm).'
			);
		}

		/**
		 * AC-11 (Cancel): GIVEN WC-Order without `_spreadconnect_order_id`.
		 *                 THEN the job calls neither `cancelOrder()` nor
		 *                 `compareAndSet()`; emits an info-log with tag
		 *                 `job_skipped_no_sc_order_id`; writes no
		 *                 order-note; does NOT rethrow.
		 */
		public function test_cancel_skips_when_spreadconnect_order_id_meta_missing(): void
		{
			$order = $this->mockOrder( 7, [
				// no `_spreadconnect_order_id`
				'_spreadconnect_state' => 'NEW',
			] );

			$client = $this->mockClient();
			$client->shouldNotReceive( 'cancelOrder' );

			$order->shouldNotReceive( 'add_order_note' );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderCancelJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-11: the job MUST NOT rethrow on idempotency-skip.' );

			self::assertCount( 0, $this->capturedSqls, 'AC-11: no CAS SQL MUST be issued.' );

			$matches = array_filter(
				$this->loggerCalls,
				static function ( array $c ): bool {
					return 'info' === $c['level']
						&& 'job_skipped_no_sc_order_id' === ( $c['context']['tag'] ?? null );
				}
			);
			self::assertNotEmpty(
				$matches,
				'AC-11: an info-log entry with tag "job_skipped_no_sc_order_id" MUST be emitted (Cancel).'
			);
		}

		// ===================================================================
		// AC-12: Bootstrap registers confirm + cancel hooks idempotently.
		// ===================================================================

		/**
		 * AC-12: GIVEN Plugin::init() runs.
		 *        THEN  (a) `spreadconnect/confirm_order` listener registered;
		 *              (b) `spreadconnect/cancel_order` listener registered.
		 *              Slice-28 hooks (`woocommerce_order_status_processing`
		 *              and `spreadconnect/create_order`) remain registered.
		 */
		public function test_plugin_init_registers_confirm_and_cancel_action_hooks(): void
		{
			$pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
			$pluginFile = self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';

			self::assertFalse(
				Actions\has( 'spreadconnect/confirm_order' ),
				'AC-12 (precondition): no confirm-listener before init().'
			);
			self::assertFalse(
				Actions\has( 'spreadconnect/cancel_order' ),
				'AC-12 (precondition): no cancel-listener before init().'
			);

			$pluginFqcn::init( $pluginFile );

			self::assertNotFalse(
				Actions\has( 'spreadconnect/confirm_order' ),
				'AC-12 (a): listener for "spreadconnect/confirm_order" MUST be registered.'
			);
			self::assertNotFalse(
				Actions\has( 'spreadconnect/cancel_order' ),
				'AC-12 (b): listener for "spreadconnect/cancel_order" MUST be registered.'
			);

			// Slice-28 hooks remain unaffected.
			self::assertNotFalse(
				Actions\has( 'woocommerce_order_status_processing' ),
				'AC-12: slice-28 processing hook MUST stay registered.'
			);
			self::assertNotFalse(
				Actions\has( 'spreadconnect/create_order' ),
				'AC-12: slice-28 create_order hook MUST stay registered.'
			);
		}

		/**
		 * AC-12: GIVEN Plugin::init() called twice.
		 *        THEN  has_action() returns the same priority for both
		 *              confirm + cancel hooks before and after the second
		 *              call (no double-registration).
		 */
		public function test_plugin_init_hook_registration_is_idempotent_on_double_call(): void
		{
			$pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
			$pluginFile = self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';

			$pluginFqcn::init( $pluginFile );

			$priorityConfirmFirst = Actions\has( 'spreadconnect/confirm_order' );
			$priorityCancelFirst  = Actions\has( 'spreadconnect/cancel_order' );

			$pluginFqcn::init( $pluginFile );

			$priorityConfirmSecond = Actions\has( 'spreadconnect/confirm_order' );
			$priorityCancelSecond  = Actions\has( 'spreadconnect/cancel_order' );

			self::assertSame(
				$priorityConfirmFirst,
				$priorityConfirmSecond,
				'AC-12: second init() MUST NOT register the confirm-listener again.'
			);
			self::assertSame(
				$priorityCancelFirst,
				$priorityCancelSecond,
				'AC-12: second init() MUST NOT register the cancel-listener again.'
			);
		}

		// ===================================================================
		// Static helpers
		// ===================================================================

		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}
	}

	/**
	 * Minimal logger spy used as the `wc_get_logger()` fallback when no
	 * WC_Logger is injected into the production class. Tests inject a
	 * Mockery double almost everywhere — this spy is just defensive.
	 */
	final class Slice29LoggerSpy
	{
		/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
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
