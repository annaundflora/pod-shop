<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope, runs once at first include)
// ---------------------------------------------------------------------------
//
// Slice-28 covers two production classes:
//   - SpreadconnectPod\Order\OrderHandler     (WC processing-hook listener)
//   - SpreadconnectPod\Order\OrderSubmitJob   (AS create_order job handler)
//
// Mocking strategy per slice spec: `mock_external` — Brain\Monkey for
// `as_enqueue_async_action`, `as_has_scheduled_action`, `wc_get_order`,
// `wc_get_logger`. Mockery doubles for SpreadconnectClient, WC_Order,
// WC_Logger, WC_Order_Item_Product, WC_Product. The production
// `OrderStateMachine` class is `final` so Mockery cannot generate a subclass
// — instead the tests instantiate a real `OrderStateMachine` driven by a
// fully programmable Mockery `wpdb` double whose `prepare()` / `query()` /
// `get_var()` outcomes determine every CAS true/false transition. This keeps
// the boundary at the same external-collaborator surface the slice spec
// targets (the SQL layer) without sacrificing the assertion that
// `compareAndSet()` is in fact invoked with the documented (expected,
// target) tuple — captured SQL strings carry both values.
//
// Stub classes for WC_Order / WC_Logger / WC_Product /
// WC_Order_Item_Product / wpdb live in `tests/stubs/wc-classes.php` and are
// loaded centrally from `tests/bootstrap.php`, so test-file load order does
// not matter — every slice test sees the same canonical parent stubs.
// ---------------------------------------------------------------------------

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use InvalidArgumentException;
	use Mockery;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Api\Dto\OrderCreate;
	use SpreadconnectPod\Api\SpreadconnectClient;
	use SpreadconnectPod\Api\SpreadconnectClientError;
	use SpreadconnectPod\Api\SpreadconnectTransientError;
	use SpreadconnectPod\Order\OrderHandler;
	use SpreadconnectPod\Order\OrderStateMachine;
	use SpreadconnectPod\Order\OrderSubmitJob;
	use WC_Logger;
	use WC_Order;
	use WC_Order_Item_Product;
	use WC_Product;
	use wpdb;

	/**
	 * Slice 28 — Order-Submit-Job (`spreadconnect/create_order`).
	 *
	 * Acceptance + Integration tests for both production classes:
	 *
	 *   - {@see OrderHandler::on_processing} — WC `processing` hook listener
	 *     (AC-1, AC-2, AC-3).
	 *   - {@see OrderSubmitJob::handle}      — AS `create_order` job handler
	 *     (AC-4, AC-5, AC-6, AC-7, AC-8, AC-10).
	 *   - {@see \SpreadconnectPod\Bootstrap\Plugin::init} hook wiring (AC-9).
	 *
	 * Each test maps 1:1 to a spec acceptance criterion via the docblock
	 * GIVEN/WHEN/THEN.
	 */
	final class Slice28OrderSubmitJobTest extends TestCase
	{
		/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
		private array $loggerCalls = [];

		/**
		 * FIFO queue of return values for `wpdb->query()`. Each CAS call by
		 * the real {@see OrderStateMachine} pops one value off this queue and
		 * uses it as the affected-row-count.
		 *
		 * @var list<int>
		 */
		private array $wpdbQueryQueue = [];

		/**
		 * FIFO queue of return values for `wpdb->get_var()`. Used by the
		 * initial-INSERT path (`expected = ''`) to decide whether a meta row
		 * already exists.
		 *
		 * @var list<mixed>
		 */
		private array $wpdbGetVarQueue = [];

		/**
		 * SQL strings observed by `wpdb->query()`, captured for assertions
		 * about which CAS transitions were actually attempted.
		 *
		 * @var list<string>
		 */
		private array $capturedSqls = [];

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();
			$this->loggerCalls    = [];
			$this->wpdbQueryQueue = [];
			$this->wpdbGetVarQueue = [];
			$this->capturedSqls   = [];

			// Default `wc_get_logger()` fallback — returns a spy logger so
			// the OrderHandler / OrderSubmitJob log() helpers do not blow up
			// when no logger is injected.
			$spy = new Slice28LoggerSpy();
			Functions\when( 'wc_get_logger' )->alias( static function () use ( $spy ) {
				return $spy;
			} );
		}

		protected function tearDown(): void
		{
			// Reset Plugin-internal state so AC-9 idempotency tests stay clean.
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
		// Helpers — WC_Order / Item / Logger / Client / wpdb mocks
		// ===================================================================

		/**
		 * Build a Mockery {@see WC_Order} double whose `get_meta()` returns
		 * the given values per key.
		 *
		 * Mockery applies overload by argument-matcher: a `with('_spreadconnect_order_id')`
		 * expectation must be defined BEFORE a fallback expectation that
		 * matches "any args", so we set the specific rule first, then the
		 * fallback. We use `andReturnUsing()` keyed on the actual argument so
		 * a single expectation handles every key the code probes.
		 *
		 * @param array<string,string> $metaValues  Per-key meta values.
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

			$order->shouldReceive( 'get_items' )->andReturn( [] )->byDefault();

			$this->stubAddress( $order, 'billing', [
				'first_name' => 'Anna',
				'last_name'  => 'Hamann',
				'address_1'  => 'Musterstr. 1',
				'address_2'  => '',
				'postcode'   => '10115',
				'city'       => 'Berlin',
				'country'    => 'DE',
				'state'      => '',
			] );
			$this->stubAddress( $order, 'shipping', [
				'first_name' => 'Anna',
				'last_name'  => 'Hamann',
				'address_1'  => 'Musterstr. 1',
				'address_2'  => '',
				'postcode'   => '10115',
				'city'       => 'Berlin',
				'country'    => 'DE',
				'state'      => '',
			] );
			$order->shouldReceive( 'get_billing_email' )->andReturn( 'anna@example.com' )->byDefault();
			$order->shouldReceive( 'get_billing_phone' )->andReturn( '' )->byDefault();

			return $order;
		}

		/**
		 * Stub the eight billing- or shipping-* getters on a WC_Order mock.
		 *
		 * @param array<string,string> $fields
		 */
		private function stubAddress( WC_Order $order, string $type, array $fields ): void
		{
			foreach ( $fields as $field => $value ) {
				/** @var \Mockery\Expectation $exp */
				$exp = $order->shouldReceive( 'get_' . $type . '_' . $field );
				$exp->andReturn( $value )->byDefault();
			}
		}

		/**
		 * @return WC_Order_Item_Product&\Mockery\MockInterface
		 */
		private function mockItem( string $sku, int $quantity ): WC_Order_Item_Product
		{
			/** @var WC_Product&\Mockery\MockInterface $product */
			$product = Mockery::mock( WC_Product::class );
			$product->shouldReceive( 'get_sku' )->andReturn( $sku );

			/** @var WC_Order_Item_Product&\Mockery\MockInterface $item */
			$item = Mockery::mock( WC_Order_Item_Product::class );
			$item->shouldReceive( 'get_product' )->andReturn( $product );
			$item->shouldReceive( 'get_quantity' )->andReturn( $quantity );
			return $item;
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
		 * Mockery `wpdb` double. The SM is a `final` class so it cannot be
		 * mocked directly; instead the tests drive every CAS outcome by
		 * pushing `query()` and `get_var()` return values onto FIFO queues.
		 *
		 * Capture side-effect: every SQL string passed to `query()` is also
		 * appended to `$this->capturedSqls`, so tests can assert on which
		 * CAS transition actually fired (the SQL contains both `expected` and
		 * `target` literal values).
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
		 * Push a CAS-success outcome onto the wpdb queues. The initial-INSERT
		 * path (expected='') needs a `get_var()` of null + a `query()` of 1.
		 * Standard UPDATE path needs a `query()` of 1.
		 */
		private function enqueueCasSuccess( bool $isInitialInsert = false ): void
		{
			if ( $isInitialInsert ) {
				$this->wpdbGetVarQueue[] = null;
			}
			$this->wpdbQueryQueue[] = 1;
		}

		/**
		 * Push a CAS-failure outcome onto the wpdb queues (UPDATE returns 0).
		 */
		private function enqueueCasFailure(): void
		{
			$this->wpdbQueryQueue[] = 0;
		}

		// ===================================================================
		// AC-1: OrderHandler::on_processing enqueues exactly once.
		// ===================================================================

		/**
		 * AC-1: GIVEN WC-Order without `_spreadconnect_order_id` meta and
		 *       no scheduled `spreadconnect/create_order` action.
		 *       WHEN  the WC processing-hook fires.
		 *       THEN  `as_enqueue_async_action()` is called exactly once with
		 *             hook=`spreadconnect/create_order`,
		 *             args=`['order_id' => 7]`, group=`'spreadconnect'`.
		 */
		public function test_on_processing_enqueues_create_order_action_once(): void
		{
			$order = $this->mockOrder( 7 );

			Functions\when( 'as_has_scheduled_action' )->justReturn( false );

			$enqueueCalls = [];
			Functions\when( 'as_enqueue_async_action' )->alias(
				static function ( string $hook, array $args, string $group ) use ( &$enqueueCalls ): int {
					$enqueueCalls[] = [ 'hook' => $hook, 'args' => $args, 'group' => $group ];
					return 1;
				}
			);

			$handler = new OrderHandler( $this->recordingLogger() );
			$handler->on_processing( 7, $order );

			self::assertCount( 1, $enqueueCalls, 'as_enqueue_async_action MUST be called exactly once.' );
			self::assertSame( 'spreadconnect/create_order', $enqueueCalls[0]['hook'] );
			self::assertSame( [ 'order_id' => 7 ], $enqueueCalls[0]['args'] );
			self::assertSame( 'spreadconnect', $enqueueCalls[0]['group'] );
		}

		// ===================================================================
		// AC-2: Skip when `_spreadconnect_order_id` already present.
		// ===================================================================

		/**
		 * AC-2: GIVEN order already has `_spreadconnect_order_id = 'sc_42'`.
		 *       WHEN  the processing-hook fires a second time.
		 *       THEN  `as_enqueue_async_action()` is NEVER called and a
		 *             debug-log entry with source `spreadconnect-order-service`
		 *             and substring `idempotent skip` is written.
		 */
		public function test_on_processing_skips_when_order_id_meta_present(): void
		{
			$order = $this->mockOrder( 7, [ '_spreadconnect_order_id' => 'sc_42' ] );

			Functions\expect( 'as_enqueue_async_action' )->never();
			Functions\when( 'as_has_scheduled_action' )->justReturn( false );

			$handler = new OrderHandler( $this->recordingLogger() );
			$handler->on_processing( 7, $order );

			$debugCalls = array_filter(
				$this->loggerCalls,
				static fn( array $c ): bool => 'debug' === $c['level']
					&& false !== stripos( $c['message'], 'idempotent skip' )
			);
			self::assertNotEmpty(
				$debugCalls,
				'AC-2: a debug-level log entry containing "idempotent skip" MUST be written.'
			);

			$first = array_values( $debugCalls )[0];
			self::assertSame( 'spreadconnect-order-service', $first['context']['source'] ?? null );
		}

		// ===================================================================
		// AC-3: Skip when `as_has_scheduled_action` returns true.
		// ===================================================================

		/**
		 * AC-3: GIVEN order without sc-id meta but `as_has_scheduled_action`
		 *       returns true.
		 *       WHEN  the processing-hook fires again.
		 *       THEN  `as_enqueue_async_action()` is NEVER called.
		 */
		public function test_on_processing_skips_when_action_already_scheduled(): void
		{
			$order = $this->mockOrder( 7 );

			$hasCalls = [];
			Functions\when( 'as_has_scheduled_action' )->alias(
				static function ( string $hook, array $args, string $group ) use ( &$hasCalls ): bool {
					$hasCalls[] = [ 'hook' => $hook, 'args' => $args, 'group' => $group ];
					return true;
				}
			);
			Functions\expect( 'as_enqueue_async_action' )->never();

			$handler = new OrderHandler( $this->recordingLogger() );
			$handler->on_processing( 7, $order );

			self::assertNotEmpty(
				$hasCalls,
				'AC-3: as_has_scheduled_action() must be called for the pre-check.'
			);
			self::assertSame( 'spreadconnect/create_order', $hasCalls[0]['hook'] );
			self::assertSame(
				[ 'order_id' => 7 ],
				$hasCalls[0]['args'],
				'AC-3 + Constraints: args MUST be assoc-shape so equality matches the enqueue site.'
			);
			self::assertSame( 'spreadconnect', $hasCalls[0]['group'] );
		}

		// ===================================================================
		// AC-4: 2xx success path — meta + state transitions + order-note.
		// ===================================================================

		/**
		 * AC-4: GIVEN order without sc-id meta; createOrder() returns
		 *       `['id' => 'sc_42', 'state' => 'NEW']`.
		 *       WHEN  `OrderSubmitJob::handle(['order_id' => 7])`.
		 *       THEN  the side-effect order is:
		 *             (a) CAS `''->submitting` true,
		 *             (b) `createOrder()` call,
		 *             (c) `update_meta_data('_spreadconnect_order_id','sc_42') + save()`,
		 *             (d) CAS `submitting->NEW` true,
		 *             (e) order-note `Submitted to Spreadconnect (#SC-sc_42)`.
		 *             The job throws no exception.
		 */
		public function test_handle_success_persists_order_id_and_transitions_state_to_new(): void
		{
			$order = $this->mockOrder( 7 );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 2 ) ]
			);

			// Sequence collector — captures the meaningful side effects in
			// order. SM-internal transition notes (added by
			// `OrderStateMachine::addTransitionNote()`) are NOT recorded
			// here — they are bundled under their own `'sm_note'` token so
			// the assertion below is robust to that internal detail.
			$capturedSequence = [];

			// (a) initial INSERT (CAS ''->submitting) succeeds.
			$this->enqueueCasSuccess( true );
			// (d) UPDATE (CAS submitting->NEW) succeeds.
			$this->enqueueCasSuccess( false );

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )
				->once()
				->andReturnUsing( static function ( OrderCreate $dto ) use ( &$capturedSequence ): array {
					$capturedSequence[] = 'createOrder';
					return [ 'id' => 'sc_42', 'state' => 'NEW' ];
				} );

			$capturedMeta = [];
			$order->shouldReceive( 'update_meta_data' )
				->andReturnUsing( static function ( string $key, $value ) use ( &$capturedMeta, &$capturedSequence ): void {
					if ( '_spreadconnect_order_id' === $key ) {
						$capturedSequence[] = 'update_meta_data';
					}
					$capturedMeta[ $key ] = $value;
				} );
			$order->shouldReceive( 'save' )
				->andReturnUsing( static function () use ( &$capturedSequence ): int {
					$capturedSequence[] = 'save';
					return 7;
				} );
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note ) use ( &$capturedSequence ): int {
					if ( str_starts_with( $note, 'Submitted to Spreadconnect' ) ) {
						$capturedSequence[] = 'submit_note';
					} else {
						// SM-internal transition note (e.g. "state '' ->
						// submitting") — recorded as a generic marker so we
						// can later verify both CAS-write transitions
						// fired without coupling to the SM's note format.
						$capturedSequence[] = 'sm_note';
					}
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $this->realStateMachine(), $this->recordingLogger() );
			$job->handle( [ 'order_id' => 7 ] );

			// SQL evidence: INSERT (submitting) + UPDATE (submitting->NEW).
			self::assertCount( 2, $this->capturedSqls, 'Exactly two SQL writes (INSERT + UPDATE) MUST occur.' );
			self::assertMatchesRegularExpression(
				"/INSERT\s+INTO\s+wp_wc_orders_meta.+'submitting'/is",
				$this->capturedSqls[0],
				'AC-4 (a): first SQL MUST be an INSERT setting state=submitting.'
			);
			self::assertMatchesRegularExpression(
				"/UPDATE\s+wp_wc_orders_meta.+'NEW'.+'submitting'/is",
				$this->capturedSqls[1],
				'AC-4 (d): second SQL MUST be an UPDATE setting NEW where state=submitting.'
			);

			// AC-4: side-effect order — the SM-internal transition notes
			// surround the explicit job-side effects. The crucial part is
			// the relative order of (b)/(c)/(d)/(e): createOrder ->
			// update_meta_data -> save -> sm_note (CAS->NEW) -> submit_note.
			self::assertSame(
				[ 'sm_note', 'createOrder', 'update_meta_data', 'save', 'sm_note', 'submit_note' ],
				$capturedSequence,
				'AC-4: side-effect order MUST be CAS->submitting (sm_note), createOrder, '
					. 'update_meta_data, save, CAS->NEW (sm_note), explicit submit-note.'
			);
			self::assertSame( 'sc_42', $capturedMeta['_spreadconnect_order_id'] ?? null );
		}

		/**
		 * AC-4 (note): 2xx path writes the private order-note in the
		 * `Submitted to Spreadconnect (#SC-<id>)` shape, with
		 * is_customer_note=false, added_by_user=false.
		 */
		public function test_handle_success_writes_submitted_order_note(): void
		{
			$order = $this->mockOrder( 7 );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			$this->enqueueCasSuccess( true );
			$this->enqueueCasSuccess( false );

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )->andReturn( [ 'id' => 'sc_42' ] );

			$capturedNote        = null;
			$capturedIsCustomer  = null;
			$capturedAddedByUser = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note, bool $isCustomer = false, bool $addedByUser = false ) use ( &$capturedNote, &$capturedIsCustomer, &$capturedAddedByUser ): int {
					$capturedNote        = $note;
					$capturedIsCustomer  = $isCustomer;
					$capturedAddedByUser = $addedByUser;
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $this->realStateMachine() );
			$job->handle( [ 'order_id' => 7 ] );

			self::assertNotNull( $capturedNote );
			self::assertSame( 'Submitted to Spreadconnect (#SC-sc_42)', $capturedNote );
			self::assertFalse( $capturedIsCustomer, 'is_customer_note MUST be false (private note).' );
			self::assertFalse( $capturedAddedByUser, 'added_by_user MUST be false.' );
		}

		// ===================================================================
		// AC-5: 4xx permanent path — failed_to_submit + failed-ops log + no rethrow.
		// ===================================================================

		/**
		 * AC-5 (a) + (b): GIVEN createOrder() throws SpreadconnectClientError
		 *                 (http_4xx). THEN the SM CAS submitting->failed_to_submit
		 *                 fires (visible as UPDATE SQL), and NO update_meta_data
		 *                 for `_spreadconnect_order_id` is called.
		 */
		public function test_handle_permanent_4xx_sets_state_failed_to_submit(): void
		{
			$order = $this->mockOrder( 7 );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			// First CAS (''->submitting) succeeds, second CAS
			// (submitting->failed_to_submit) succeeds.
			$this->enqueueCasSuccess( true );
			$this->enqueueCasSuccess( false );

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )
				->once()
				->andThrow(
					new SpreadconnectClientError(
						'http_4xx',
						'Spreadconnect /orders 4xx: invalid SKU mapping',
						400,
						'/orders'
					)
				);

			// AC-5 (b): No `_spreadconnect_order_id` write on the 4xx path.
			$order->shouldNotReceive( 'update_meta_data' )
				->with( '_spreadconnect_order_id', Mockery::any() );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-5 (e): the job MUST NOT rethrow on 4xx.' );

			// SQL evidence: INSERT (submitting) followed by UPDATE
			// (submitting -> failed_to_submit).
			self::assertCount( 2, $this->capturedSqls );
			self::assertMatchesRegularExpression(
				"/INSERT\s+INTO\s+wp_wc_orders_meta.+'submitting'/is",
				$this->capturedSqls[0]
			);
			self::assertMatchesRegularExpression(
				"/UPDATE\s+wp_wc_orders_meta.+'failed_to_submit'.+'submitting'/is",
				$this->capturedSqls[1],
				'AC-5 (a): SM MUST be asked to CAS submitting -> failed_to_submit.'
			);
		}

		/**
		 * AC-5 (c): The 4xx-path emits an `error`-level log entry with
		 *           context tag `failed_op_pending_record`,
		 *           op_type=`create_order`, related_entity_type=`order`,
		 *           related_entity_id=7, source=`spreadconnect-order-service`.
		 */
		public function test_handle_permanent_4xx_logs_failed_op_pending_record(): void
		{
			$order = $this->mockOrder( 7 );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			$this->enqueueCasSuccess( true );
			$this->enqueueCasSuccess( false );

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )
				->andThrow(
					new SpreadconnectClientError( 'http_4xx', 'invalid SKU mapping', 400, '/orders' )
				);

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $this->realStateMachine(), $this->recordingLogger() );
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
			self::assertSame( 'create_order', $entry['context']['op_type'] ?? null );
			self::assertSame( 'order', $entry['context']['related_entity_type'] ?? null );
			self::assertSame( 7, $entry['context']['related_entity_id'] ?? null );
			self::assertSame( 'unresolved', $entry['context']['state'] ?? null );
			self::assertArrayHasKey( 'payload', $entry['context'] );
			self::assertArrayHasKey( 'error_message', $entry['context'] );
		}

		/**
		 * AC-5 (d) + (e): A private order-note `Spreadconnect: submit failed
		 * (4xx) — see Failed-Ops` is added; the job does not rethrow.
		 */
		public function test_handle_permanent_4xx_does_not_rethrow(): void
		{
			$order = $this->mockOrder( 7 );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			$this->enqueueCasSuccess( true );
			$this->enqueueCasSuccess( false );

			$capturedNote = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note ) use ( &$capturedNote ): int {
					$capturedNote = $note;
					return 1;
				} );

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )
				->andThrow( new SpreadconnectClientError( 'http_4xx', 'bad', 400, '/orders' ) );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-5 (e): no rethrow.' );

			self::assertNotNull( $capturedNote, 'AC-5 (d): an order-note MUST be added.' );
			self::assertStringContainsString( 'Spreadconnect:', (string) $capturedNote );
			self::assertStringContainsString( 'submit failed (4xx)', (string) $capturedNote );
			self::assertStringContainsString( 'Failed-Ops', (string) $capturedNote );
		}

		// ===================================================================
		// AC-6: 5xx transient path — rethrow for AS retry.
		// ===================================================================

		/**
		 * AC-6 (c): GIVEN createOrder() throws SpreadconnectTransientError.
		 *           THEN  the job rethrows the exact same exception instance.
		 */
		public function test_handle_transient_5xx_rethrows_unchanged(): void
		{
			$order = $this->mockOrder( 7 );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			// Initial CAS ''->submitting succeeds. NO further CAS expected.
			$this->enqueueCasSuccess( true );

			$transient = new SpreadconnectTransientError(
				'http_5xx',
				'Spreadconnect /orders 503: gateway timeout',
				503,
				'/orders'
			);

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )->andThrow( $transient );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$caught = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( SpreadconnectTransientError $e ) {
				$caught = $e;
			}

			self::assertNotNull( $caught, 'AC-6: a SpreadconnectTransientError MUST be rethrown.' );
			self::assertSame( $transient, $caught, 'AC-6 (c): rethrown instance MUST be identical.' );

			// AC-6 (a): only the initial INSERT (submitting) ran — no follow-up
			// UPDATE was executed (transient errors leave state at submitting).
			self::assertCount(
				1,
				$this->capturedSqls,
				'AC-6 (a): only the initial submitting-INSERT MUST run; no follow-up CAS.'
			);

			// AC-6 (d): warning-level log with `transient error, AS retry`.
			$warningCalls = array_filter(
				$this->loggerCalls,
				static fn( array $c ): bool => 'warning' === $c['level']
					&& false !== stripos( $c['message'], 'transient error, AS retry' )
			);
			self::assertNotEmpty(
				$warningCalls,
				'AC-6 (d): a warning log entry with substring "transient error, AS retry" MUST exist.'
			);
		}

		/**
		 * AC-6 (a) + (b): no `_spreadconnect_order_id` is persisted, no
		 * advancing CAS beyond `submitting` is attempted.
		 */
		public function test_handle_transient_5xx_does_not_mutate_state(): void
		{
			$order = $this->mockOrder( 7 );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			$this->enqueueCasSuccess( true );

			// AC-6 (b): No update_meta_data for the SC-Order-ID.
			$order->shouldNotReceive( 'update_meta_data' )
				->with( '_spreadconnect_order_id', Mockery::any() );

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )
				->andThrow( new SpreadconnectTransientError( 'network_error', 'timeout' ) );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			try {
				$job->handle( [ 'order_id' => 7 ] );
				self::fail( 'AC-6: the transient error MUST propagate.' );
			} catch ( SpreadconnectTransientError $e ) {
				self::assertInstanceOf( SpreadconnectTransientError::class, $e );
			}

			// SQL trace: only the submitting-INSERT ran.
			self::assertCount( 1, $this->capturedSqls );
			self::assertStringNotContainsString( "'NEW'", $this->capturedSqls[0] );
			self::assertStringNotContainsString( "'failed_to_submit'", $this->capturedSqls[0] );
		}

		// ===================================================================
		// AC-7: Internal idempotency — skip when meta already set at job time.
		// ===================================================================

		/**
		 * AC-7: GIVEN the WC-Order has `_spreadconnect_order_id` set at job
		 *       execution time.
		 *       WHEN  `OrderSubmitJob::handle(['order_id' => 7])`.
		 *       THEN  `createOrder()` is NEVER called, no state mutation, no
		 *             order-note; an info-level log with substring
		 *             `job skipped, order already submitted` is written.
		 */
		public function test_handle_skips_when_order_already_has_spreadconnect_order_id(): void
		{
			$order = $this->mockOrder( 7, [ '_spreadconnect_order_id' => 'sc_42' ] );

			$client = $this->mockClient();
			$client->shouldNotReceive( 'createOrder' );

			$order->shouldNotReceive( 'add_order_note' );
			$order->shouldNotReceive( 'update_meta_data' );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-7: the job MUST return cleanly without throwing.' );

			// No SQL must have been issued — the job bails before touching
			// the state machine.
			self::assertCount( 0, $this->capturedSqls, 'AC-7: no CAS SQL MUST be issued.' );

			$infoCalls = array_filter(
				$this->loggerCalls,
				static fn( array $c ): bool => 'info' === $c['level']
					&& false !== stripos( $c['message'], 'job skipped, order already submitted' )
			);
			self::assertNotEmpty(
				$infoCalls,
				'AC-7: an info-level log entry with substring "job skipped, order already submitted" MUST be written.'
			);
		}

		// ===================================================================
		// AC-8: CAS race-loss — still persists order_id, race-aware note, no throw.
		// ===================================================================

		/**
		 * AC-8: GIVEN CAS `submitting -> NEW` returns false (parallel webhook
		 *       advanced state to PROCESSED).
		 *       WHEN  the 2xx response is processed.
		 *       THEN  `_spreadconnect_order_id` is still persisted; a
		 *             race-aware order-note is added; no rethrow.
		 */
		public function test_handle_persists_order_id_even_when_cas_to_new_loses_race(): void
		{
			$order = $this->mockOrder( 7 );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			// Initial INSERT (CAS ''->submitting) succeeds.
			$this->enqueueCasSuccess( true );
			// CAS submitting->NEW LOSES — query() returns 0.
			$this->enqueueCasFailure();

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )->andReturn( [ 'id' => 'sc_42', 'state' => 'PROCESSED' ] );

			$capturedMeta = [];
			$order->shouldReceive( 'update_meta_data' )
				->andReturnUsing( static function ( string $key, $value ) use ( &$capturedMeta ): void {
					$capturedMeta[ $key ] = $value;
				} );

			$capturedNote = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note ) use ( &$capturedNote ): int {
					$capturedNote = $note;
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-8: the job MUST NOT throw on CAS race-loss.' );

			// (a) `_spreadconnect_order_id` is still persisted.
			self::assertSame( 'sc_42', $capturedMeta['_spreadconnect_order_id'] ?? null );

			// (b) The order-note carries the race-aware suffix.
			self::assertNotNull( $capturedNote );
			self::assertStringContainsString( '#SC-sc_42', (string) $capturedNote );
			self::assertStringContainsString( 'state already advanced (race)', (string) $capturedNote );
		}

		// ===================================================================
		// AC-10: DTO-Build from WC-Order.
		// ===================================================================

		/**
		 * AC-10: GIVEN WC-Order with two items, billing+shipping address,
		 *        customer email + phone.
		 *        THEN  the OrderCreate DTO carries:
		 *              - externalOrderReference = (string) $order->get_id()
		 *              - one OrderItem per line-item with sku + quantity
		 *              - billingAddress + shippingAddress as Address DTOs
		 *              - shippingType = null (Slice 28 scope).
		 */
		public function test_handle_builds_order_create_dto_from_wc_order(): void
		{
			$order = $this->mockOrder( 7 );
			$this->stubAddress( $order, 'billing', [
				'first_name' => 'Anna',
				'last_name'  => 'Hamann',
				'address_1'  => 'Musterstr. 1',
				'address_2'  => 'c/o',
				'postcode'   => '10115',
				'city'       => 'Berlin',
				'country'    => 'DE',
				'state'      => '',
			] );
			$this->stubAddress( $order, 'shipping', [
				'first_name' => 'Bob',
				'last_name'  => 'Builder',
				'address_1'  => 'Bauplatz 5',
				'address_2'  => '',
				'postcode'   => '20095',
				'city'       => 'Hamburg',
				'country'    => 'DE',
				'state'      => '',
			] );
			$order->shouldReceive( 'get_billing_email' )->andReturn( 'anna@example.com' );
			$order->shouldReceive( 'get_billing_phone' )->andReturn( '+49 30 12345678' );

			$order->shouldReceive( 'get_items' )->andReturn(
				[
					$this->mockItem( 'SKU-AAA', 2 ),
					$this->mockItem( 'SKU-BBB', 5 ),
				]
			);

			$this->enqueueCasSuccess( true );
			$this->enqueueCasSuccess( false );

			$captured = null;
			$client   = $this->mockClient();
			$client->shouldReceive( 'createOrder' )
				->once()
				->andReturnUsing( static function ( OrderCreate $dto ) use ( &$captured ): array {
					$captured = $dto;
					return [ 'id' => 'sc_42' ];
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $this->realStateMachine() );
			$job->handle( [ 'order_id' => 7 ] );

			self::assertInstanceOf( OrderCreate::class, $captured );
			self::assertSame( '7', $captured->externalOrderReference );
			self::assertCount( 2, $captured->orderItems );
			self::assertSame( 'SKU-AAA', $captured->orderItems[0]->sku );
			self::assertSame( 2, $captured->orderItems[0]->quantity );
			self::assertSame( 'SKU-BBB', $captured->orderItems[1]->sku );
			self::assertSame( 5, $captured->orderItems[1]->quantity );

			self::assertSame( 'Anna', $captured->billingAddress->firstName );
			self::assertSame( 'Hamann', $captured->billingAddress->lastName );
			self::assertSame( 'Musterstr. 1', $captured->billingAddress->street );
			self::assertSame( '10115', $captured->billingAddress->zipCode );
			self::assertSame( 'Berlin', $captured->billingAddress->city );
			self::assertSame( 'DE', $captured->billingAddress->country );

			self::assertSame( 'Bob', $captured->shippingAddress->firstName );
			self::assertSame( 'Hamburg', $captured->shippingAddress->city );

			self::assertSame( 'anna@example.com', $captured->customerEmail );
			self::assertSame( '+49 30 12345678', $captured->phone );

			self::assertNull( $captured->shippingType, 'AC-10: shippingType MUST remain null in this slice.' );
		}

		/**
		 * AC-10: GIVEN WC-Order with an item that has no SKU.
		 *        THEN  the validation failure is treated as permanent (analog
		 *              to AC-5): no createOrder call, CAS to failed_to_submit,
		 *              no rethrow.
		 */
		public function test_dto_validation_failure_is_treated_as_permanent_failure(): void
		{
			$order = $this->mockOrder( 7 );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( '', 1 ) ]
			);

			// Initial CAS ''->submitting succeeds; second CAS
			// (submitting->failed_to_submit) succeeds.
			$this->enqueueCasSuccess( true );
			$this->enqueueCasSuccess( false );

			$client = $this->mockClient();
			$client->shouldNotReceive( 'createOrder' );

			$order->shouldNotReceive( 'update_meta_data' )
				->with( '_spreadconnect_order_id', Mockery::any() );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $this->realStateMachine(), $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}

			self::assertNull(
				$thrown,
				'AC-10: DTO-validation failure MUST NOT rethrow (treated as permanent fail).'
			);

			$matches = array_filter(
				$this->loggerCalls,
				static fn( array $c ): bool => 'error' === $c['level']
					&& 'failed_op_pending_record' === ( $c['context']['tag'] ?? null )
					&& 'dto_validation' === ( $c['context']['error_code'] ?? null )
			);
			self::assertNotEmpty(
				$matches,
				'AC-10: DTO-validation failure MUST emit failed_op_pending_record with error_code="dto_validation".'
			);

			// SQL trace: INSERT (submitting) + UPDATE (-> failed_to_submit).
			self::assertCount( 2, $this->capturedSqls );
			self::assertMatchesRegularExpression(
				"/UPDATE\s+wp_wc_orders_meta.+'failed_to_submit'.+'submitting'/is",
				$this->capturedSqls[1]
			);
		}

		// ===================================================================
		// AC-9: Bootstrap registers WC + AS hooks idempotently.
		// ===================================================================

		/**
		 * AC-9: GIVEN Plugin::init().
		 *       THEN  (a) `woocommerce_order_status_processing` listener
		 *             registered; (b) `spreadconnect/create_order` listener
		 *             registered.
		 */
		public function test_plugin_init_registers_processing_and_create_order_hooks(): void
		{
			$pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
			$pluginFile = self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';

			self::assertFalse(
				Actions\has( 'woocommerce_order_status_processing' ),
				'AC-9 (precondition): no listener before init().'
			);

			$pluginFqcn::init( $pluginFile );

			self::assertNotFalse(
				Actions\has( 'woocommerce_order_status_processing' ),
				'AC-9 (a): listener for "woocommerce_order_status_processing" MUST be registered.'
			);
			self::assertNotFalse(
				Actions\has( 'spreadconnect/create_order' ),
				'AC-9 (b): listener for "spreadconnect/create_order" MUST be registered.'
			);
		}

		/**
		 * AC-9: idempotency on double init() — has_action() returns the same
		 * priority before and after the second call.
		 */
		public function test_plugin_init_is_idempotent_on_double_call(): void
		{
			$pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
			$pluginFile = self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';

			$pluginFqcn::init( $pluginFile );

			$priorityProcessingFirst  = Actions\has( 'woocommerce_order_status_processing' );
			$priorityCreateOrderFirst = Actions\has( 'spreadconnect/create_order' );

			$pluginFqcn::init( $pluginFile );

			$priorityProcessingSecond  = Actions\has( 'woocommerce_order_status_processing' );
			$priorityCreateOrderSecond = Actions\has( 'spreadconnect/create_order' );

			self::assertSame(
				$priorityProcessingFirst,
				$priorityProcessingSecond,
				'AC-9: second init() MUST NOT register the processing listener again.'
			);
			self::assertSame(
				$priorityCreateOrderFirst,
				$priorityCreateOrderSecond,
				'AC-9: second init() MUST NOT register the create_order listener again.'
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
	final class Slice28LoggerSpy
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
