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
// `wc_get_logger`. Mockery doubles for SpreadconnectClient, OrderStateMachine,
// WC_Order, WC_Logger, WC_Order_Item_Product and WC_Product. No real WC / WP
// runtime required.
//
// We rely on the same `WC_Order` / `WC_Logger` / `wpdb` stub classes that
// Slice27OrderStateMachineTest already declared; PHPUnit may execute either
// test file first, so we declare them defensively guarded by class_exists().
// Additional stubs added in this file: `WC_Product` and
// `WC_Order_Item_Product` for the DTO-build path.
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'WC_Order', false ) ) {
		class WC_Order
		{
			public function get_id(): int { return 0; }
			/** @return mixed */
			public function get_meta( string $key, bool $single = true, string $context = 'view' ) { return ''; }
			public function update_meta_data( string $key, $value ): void {}
			public function save(): int { return 0; }
			public function add_order_note( string $note, bool $is_customer_note = false, bool $added_by_user = false ): int { return 0; }
			/** @return array<int, mixed> */
			public function get_items( $types = 'line_item' ): array { return []; }
			public function get_billing_first_name(): string { return ''; }
			public function get_billing_last_name(): string { return ''; }
			public function get_billing_address_1(): string { return ''; }
			public function get_billing_address_2(): string { return ''; }
			public function get_billing_postcode(): string { return ''; }
			public function get_billing_city(): string { return ''; }
			public function get_billing_country(): string { return ''; }
			public function get_billing_state(): string { return ''; }
			public function get_billing_email(): string { return ''; }
			public function get_billing_phone(): string { return ''; }
			public function get_shipping_first_name(): string { return ''; }
			public function get_shipping_last_name(): string { return ''; }
			public function get_shipping_address_1(): string { return ''; }
			public function get_shipping_address_2(): string { return ''; }
			public function get_shipping_postcode(): string { return ''; }
			public function get_shipping_city(): string { return ''; }
			public function get_shipping_country(): string { return ''; }
			public function get_shipping_state(): string { return ''; }
		}
	}

	if ( ! class_exists( 'WC_Logger', false ) ) {
		class WC_Logger
		{
			public function log( string $level, string $message, array $context = [] ): void {}
		}
	}

	if ( ! class_exists( 'WC_Product', false ) ) {
		/**
		 * Minimal stub for `WC_Product`. Mockery doubles override get_sku().
		 */
		class WC_Product
		{
			public function get_sku( string $context = 'view' ): string { return ''; }
		}
	}

	if ( ! class_exists( 'WC_Order_Item_Product', false ) ) {
		/**
		 * Minimal stub for `WC_Order_Item_Product`. Mockery doubles override
		 * get_product() / get_quantity().
		 */
		class WC_Order_Item_Product
		{
			/** @return mixed */
			public function get_product() { return null; }
			public function get_quantity(): int { return 0; }
		}
	}

	if ( ! class_exists( 'wpdb', false ) ) {
		class wpdb
		{
			public string $prefix = 'wp_';
			public function prepare( string $query, ...$args ): string { return $query; }
			public function query( string $query ): int { return 0; }
			/** @return mixed */
			public function get_var( string $query ) { return null; }
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

	/**
	 * Slice 28 — Order-Submit-Job (`spreadconnect/create_order`).
	 *
	 * One-file Acceptance + Integration test suite for the outbound order
	 * submit pipeline, covering both production classes:
	 *
	 *   - {@see OrderHandler::on_processing} — WC `processing` hook listener
	 *     (AC-1, AC-2, AC-3).
	 *   - {@see OrderSubmitJob::handle}      — AS `create_order` job handler
	 *     (AC-4, AC-5, AC-6, AC-7, AC-8, AC-10).
	 *   - {@see \SpreadconnectPod\Bootstrap\Plugin::init} hook wiring (AC-9).
	 *
	 * Each test maps 1:1 to a spec acceptance criterion via the docblock
	 * GIVEN/WHEN/THEN. Mocking strategy: Mockery for the injected
	 * SpreadconnectClient + OrderStateMachine collaborators, Brain\Monkey for
	 * the global functions (`as_enqueue_async_action`, `as_has_scheduled_action`,
	 * `wc_get_order`, `wc_get_logger`). The real Slice-09 DTO classes are
	 * exercised end-to-end so AC-10 also covers the DTO-factory boundary.
	 */
	final class Slice28OrderSubmitJobTest extends TestCase
	{
		/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
		private array $loggerCalls = [];

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();
			$this->loggerCalls = [];

			// Default `wc_get_logger()` fallback — returns a spy logger that
			// records all log calls; the OrderHandler / OrderSubmitJob log()
			// helpers fall through to this when no logger is injected.
			$spy = new Slice28LoggerSpy();
			Functions\when( 'wc_get_logger' )->alias( static function () use ( $spy ) {
				return $spy;
			} );
		}

		protected function tearDown(): void
		{
			// Reset Plugin-internal state so AC-9 idempotency tests start clean.
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
		// Helpers
		// ===================================================================

		/**
		 * Build a Mockery {@see WC_Order} double with a pre-canned
		 * `_spreadconnect_order_id` meta value. All other accessors return
		 * sensible defaults; tests override what they need.
		 *
		 * @return WC_Order&\Mockery\MockInterface
		 */
		private function mockOrder( int $id = 7, string $orderIdMeta = '' ): WC_Order
		{
			/** @var WC_Order&\Mockery\MockInterface $order */
			$order = Mockery::mock( WC_Order::class );
			$order->shouldReceive( 'get_id' )->andReturn( $id )->byDefault();
			$order->shouldReceive( 'get_meta' )
				->with( '_spreadconnect_order_id' )
				->andReturn( $orderIdMeta )
				->byDefault();
			$order->shouldReceive( 'get_meta' )->andReturn( '' )->byDefault();
			$order->shouldReceive( 'add_order_note' )->andReturn( 1 )->byDefault();
			$order->shouldReceive( 'update_meta_data' )->andReturnNull()->byDefault();
			$order->shouldReceive( 'save' )->andReturn( $id )->byDefault();

			// Default empty items list.
			$order->shouldReceive( 'get_items' )->andReturn( [] )->byDefault();

			// Sensible default address fields so DTO-build doesn't blow up
			// in non-DTO tests. Tests asserting DTO content override these.
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
		 * Stub the eight billing- or shipping-* getters on a Mockery WC_Order
		 * double with the given values.
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
		 * Build a Mockery WC_Order_Item_Product double with a fixed SKU and
		 * quantity. Used for AC-10 DTO assertions and the AC-4 happy path.
		 *
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
		 * Build a Mockery double for {@see SpreadconnectClient}.
		 *
		 * @return SpreadconnectClient&\Mockery\MockInterface
		 */
		private function mockClient(): SpreadconnectClient
		{
			/** @var SpreadconnectClient&\Mockery\MockInterface $mock */
			$mock = Mockery::mock( SpreadconnectClient::class );
			return $mock;
		}

		/**
		 * Build a Mockery double for {@see OrderStateMachine}.
		 *
		 * @return OrderStateMachine&\Mockery\MockInterface
		 */
		private function mockStateMachine(): OrderStateMachine
		{
			/** @var OrderStateMachine&\Mockery\MockInterface $mock */
			$mock = Mockery::mock( OrderStateMachine::class );
			return $mock;
		}

		/**
		 * Inject a Mockery WC_Logger double that records each `log()` call
		 * into `$this->loggerCalls`.
		 *
		 * @return WC_Logger&\Mockery\MockInterface
		 */
		private function recordingLogger(): WC_Logger
		{
			/** @var WC_Logger&\Mockery\MockInterface $logger */
			$logger = Mockery::mock( WC_Logger::class );
			$logger->shouldReceive( 'log' )
				->andReturnUsing( function ( string $level, string $message, array $context = [] ) {
					$this->loggerCalls[] = [
						'level'   => $level,
						'message' => $message,
						'context' => $context,
					];
				} );
			return $logger;
		}

		// ===================================================================
		// AC-1: OrderHandler::on_processing enqueues exactly once.
		// ===================================================================

		/**
		 * AC-1: GIVEN WC-Order without `_spreadconnect_order_id` meta and no
		 *       scheduled `spreadconnect/create_order` action.
		 *       WHEN  the WC processing-hook fires for that order.
		 *       THEN  the handler calls `as_enqueue_async_action()` exactly
		 *             once with hook=`spreadconnect/create_order`,
		 *             args=`['order_id' => 7]`, group=`'spreadconnect'`.
		 */
		public function test_on_processing_enqueues_create_order_action_once(): void
		{
			$order = $this->mockOrder( 7, '' );

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
			$order = $this->mockOrder( 7, 'sc_42' );

			Functions\expect( 'as_enqueue_async_action' )->never();

			$handler = new OrderHandler( $this->recordingLogger() );
			$handler->on_processing( 7, $order );

			$messages = array_map( static fn( array $c ): string => $c['message'], $this->loggerCalls );
			$debugCalls = array_filter(
				$this->loggerCalls,
				static fn( array $c ): bool => 'debug' === $c['level']
					&& false !== stripos( $c['message'], 'idempotent skip' )
			);
			self::assertNotEmpty(
				$debugCalls,
				'AC-2: a debug-level log entry containing "idempotent skip" must be written. Captured: '
					. implode( ' | ', $messages )
			);

			$first = array_values( $debugCalls )[0];
			self::assertSame( 'spreadconnect-order-service', $first['context']['source'] ?? null );
		}

		// ===================================================================
		// AC-3: Skip when `as_has_scheduled_action` returns true.
		// ===================================================================

		/**
		 * AC-3: GIVEN order without sc-id meta but `as_has_scheduled_action`
		 *       returns true (race / duplicate hook).
		 *       WHEN  the processing-hook fires again.
		 *       THEN  `as_enqueue_async_action()` is NEVER called.
		 */
		public function test_on_processing_skips_when_action_already_scheduled(): void
		{
			$order = $this->mockOrder( 7, '' );

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

			self::assertNotEmpty( $hasCalls, 'AC-3: as_has_scheduled_action() must be called for the pre-check.' );
			self::assertSame( 'spreadconnect/create_order', $hasCalls[0]['hook'] );
			self::assertSame( [ 'order_id' => 7 ], $hasCalls[0]['args'], 'AC-3 + Constraints: args MUST be assoc-shape so equality matches the enqueue site.' );
			self::assertSame( 'spreadconnect', $hasCalls[0]['group'] );
		}

		// ===================================================================
		// AC-4: 2xx success path — meta + state transitions + order-note.
		// ===================================================================

		/**
		 * AC-4: GIVEN WC-Order in state `pending` (no state meta), valid DTO.
		 *       Mocked `createOrder()` returns `['id' => 'sc_42', 'state' => 'NEW']`.
		 *       WHEN  `OrderSubmitJob::handle(['order_id' => 7])`.
		 *       THEN  CAS `''->submitting` succeeds, then `createOrder()` is
		 *             called, then `_spreadconnect_order_id = 'sc_42'` is
		 *             persisted via `update_meta_data` + `save`, then CAS
		 *             `submitting->NEW` succeeds, then a private order-note
		 *             `Submitted to Spreadconnect (#SC-sc_42)` is added. The
		 *             job throws no exception.
		 */
		public function test_handle_success_persists_order_id_and_transitions_state_to_new(): void
		{
			$order = $this->mockOrder( 7, '' );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 2 ) ]
			);

			$capturedSequence = [];
			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )
				->once()
				->andReturnUsing( static function ( OrderCreate $dto ) use ( &$capturedSequence ): array {
					$capturedSequence[] = 'createOrder';
					return [ 'id' => 'sc_42', 'state' => 'NEW' ];
				} );

			$sm = $this->mockStateMachine();
			$sm->shouldReceive( 'compareAndSet' )
				->with( $order, '', OrderStateMachine::STATE_SUBMITTING )
				->once()
				->andReturnUsing( static function () use ( &$capturedSequence ): bool {
					$capturedSequence[] = 'cas-submitting';
					return true;
				} );
			$sm->shouldReceive( 'compareAndSet' )
				->with( $order, OrderStateMachine::STATE_SUBMITTING, OrderStateMachine::STATE_NEW )
				->once()
				->andReturnUsing( static function () use ( &$capturedSequence ): bool {
					$capturedSequence[] = 'cas-new';
					return true;
				} );

			$capturedMeta = [];
			$order->shouldReceive( 'update_meta_data' )
				->andReturnUsing( static function ( string $key, $value ) use ( &$capturedMeta, &$capturedSequence ): void {
					$capturedMeta[ $key ] = $value;
					$capturedSequence[] = 'update_meta_data';
				} );
			$order->shouldReceive( 'save' )
				->andReturnUsing( static function () use ( &$capturedSequence ): int {
					$capturedSequence[] = 'save';
					return 7;
				} );
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function () use ( &$capturedSequence ): int {
					$capturedSequence[] = 'add_order_note';
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $sm, $this->recordingLogger() );
			$job->handle( [ 'order_id' => 7 ] );

			// Assert the side-effect sequence (a) -> (b) -> (c) -> (d) -> (e).
			self::assertSame(
				[ 'cas-submitting', 'createOrder', 'update_meta_data', 'save', 'cas-new', 'add_order_note' ],
				$capturedSequence,
				'AC-4: side-effect order MUST be: CAS->submitting, createOrder, update_meta_data+save, CAS->NEW, order-note.'
			);

			self::assertSame( 'sc_42', $capturedMeta['_spreadconnect_order_id'] ?? null );
		}

		/**
		 * AC-4 (note): 2xx path writes the private order-note in the
		 * `Submitted to Spreadconnect (#SC-<id>)` shape, with
		 * `is_customer_note=false, added_by_user=false`.
		 */
		public function test_handle_success_writes_submitted_order_note(): void
		{
			$order = $this->mockOrder( 7, '' );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )->andReturn( [ 'id' => 'sc_42' ] );

			$sm = $this->mockStateMachine();
			$sm->shouldReceive( 'compareAndSet' )->andReturn( true, true );

			$capturedNote = null;
			$capturedIsCustomer = null;
			$capturedAddedByUser = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note, bool $isCustomer = false, bool $addedByUser = false ) use ( &$capturedNote, &$capturedIsCustomer, &$capturedAddedByUser ): int {
					$capturedNote = $note;
					$capturedIsCustomer = $isCustomer;
					$capturedAddedByUser = $addedByUser;
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $sm );
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
		 * AC-5 (a): GIVEN order in state submitting; createOrder() throws
		 *           SpreadconnectClientError(http_4xx).
		 *           THEN  state-machine receives compareAndSet(submitting,
		 *                 failed_to_submit) and NO update_meta_data for
		 *                 `_spreadconnect_order_id`.
		 */
		public function test_handle_permanent_4xx_sets_state_failed_to_submit(): void
		{
			$order = $this->mockOrder( 7, '' );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

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

			$sm = $this->mockStateMachine();
			$sm->shouldReceive( 'compareAndSet' )
				->with( $order, '', OrderStateMachine::STATE_SUBMITTING )
				->once()
				->andReturn( true );
			$sm->shouldReceive( 'compareAndSet' )
				->with( $order, OrderStateMachine::STATE_SUBMITTING, OrderStateMachine::STATE_FAILED_TO_SUBMIT )
				->once()
				->andReturn( true );

			// AC-5 (b): No `_spreadconnect_order_id` write on the 4xx path.
			$order->shouldNotReceive( 'update_meta_data' )
				->with( '_spreadconnect_order_id', Mockery::any() );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $sm, $this->recordingLogger() );

			// AC-5 (e): no rethrow.
			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-5 (e): the job MUST NOT rethrow on a 4xx permanent failure.' );
		}

		/**
		 * AC-5 (c): The 4xx-path emits an `error`-level log entry with
		 *           context tag `failed_op_pending_record`,
		 *           op_type=`create_order`, related_entity_type=`order`,
		 *           related_entity_id=$orderId, source=
		 *           `spreadconnect-order-service`.
		 */
		public function test_handle_permanent_4xx_logs_failed_op_pending_record(): void
		{
			$order = $this->mockOrder( 7, '' );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )
				->andThrow(
					new SpreadconnectClientError( 'http_4xx', 'invalid SKU mapping', 400, '/orders' )
				);

			$sm = $this->mockStateMachine();
			$sm->shouldReceive( 'compareAndSet' )->andReturn( true );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$logger = $this->recordingLogger();
			$job = new OrderSubmitJob( $client, $sm, $logger );
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
				'AC-5 (c): an error-level log entry with context tag "failed_op_pending_record" MUST be emitted.'
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
			$order = $this->mockOrder( 7, '' );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			$capturedNote = null;
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note ) use ( &$capturedNote ): int {
					$capturedNote = $note;
					return 1;
				} );

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )
				->andThrow( new SpreadconnectClientError( 'http_4xx', 'bad', 400, '/orders' ) );

			$sm = $this->mockStateMachine();
			$sm->shouldReceive( 'compareAndSet' )->andReturn( true );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $sm, $this->recordingLogger() );

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
		// AC-6: 5xx transient path — rethrow for AS retry, no state mutation
		//       beyond the initial submitting CAS.
		// ===================================================================

		/**
		 * AC-6 (c): GIVEN createOrder() throws SpreadconnectTransientError.
		 *           THEN  the job rethrows the exact exception instance
		 *                 unchanged for the AS retry cascade (1m/5m/15m).
		 */
		public function test_handle_transient_5xx_rethrows_unchanged(): void
		{
			$order = $this->mockOrder( 7, '' );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			$transient = new SpreadconnectTransientError(
				'http_5xx',
				'Spreadconnect /orders 503: gateway timeout',
				503,
				'/orders'
			);

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )->andThrow( $transient );

			$sm = $this->mockStateMachine();
			$sm->shouldReceive( 'compareAndSet' )
				->with( $order, '', OrderStateMachine::STATE_SUBMITTING )
				->once()
				->andReturn( true );
			// AC-6 (a): NO further compareAndSet call (neither to NEW nor to
			// failed_to_submit).
			$sm->shouldNotReceive( 'compareAndSet' )
				->with( Mockery::any(), OrderStateMachine::STATE_SUBMITTING, OrderStateMachine::STATE_NEW );
			$sm->shouldNotReceive( 'compareAndSet' )
				->with( Mockery::any(), OrderStateMachine::STATE_SUBMITTING, OrderStateMachine::STATE_FAILED_TO_SUBMIT );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $sm, $this->recordingLogger() );

			$caught = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( SpreadconnectTransientError $e ) {
				$caught = $e;
			}

			self::assertNotNull( $caught, 'AC-6: a SpreadconnectTransientError MUST be rethrown.' );
			self::assertSame( $transient, $caught, 'AC-6 (c): the rethrown instance MUST be identical (unchanged).' );

			// AC-6 (d): warning-level log with `transient error, AS retry`
			// MUST be written before the rethrow.
			$warningCalls = array_filter(
				$this->loggerCalls,
				static fn( array $c ): bool => 'warning' === $c['level']
					&& false !== stripos( $c['message'], 'transient error, AS retry' )
			);
			self::assertNotEmpty(
				$warningCalls,
				'AC-6 (d): warning-level log entry with substring "transient error, AS retry" MUST be written.'
			);
		}

		/**
		 * AC-6 (a) + (b): GIVEN transient error, THEN no
		 * `_spreadconnect_order_id` is persisted and no further state
		 * transition is requested beyond the initial `submitting` CAS.
		 */
		public function test_handle_transient_5xx_does_not_mutate_state(): void
		{
			$order = $this->mockOrder( 7, '' );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			// AC-6 (b): No update_meta_data for the SC-Order-ID.
			$order->shouldNotReceive( 'update_meta_data' )
				->with( '_spreadconnect_order_id', Mockery::any() );

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )
				->andThrow( new SpreadconnectTransientError( 'network_error', 'timeout' ) );

			$sm = $this->mockStateMachine();
			$sm->shouldReceive( 'compareAndSet' )
				->with( $order, '', OrderStateMachine::STATE_SUBMITTING )
				->andReturn( true );
			// No success / no failed_to_submit transition.
			$sm->shouldNotReceive( 'compareAndSet' )
				->with( Mockery::any(), OrderStateMachine::STATE_SUBMITTING, OrderStateMachine::STATE_NEW );
			$sm->shouldNotReceive( 'compareAndSet' )
				->with( Mockery::any(), OrderStateMachine::STATE_SUBMITTING, OrderStateMachine::STATE_FAILED_TO_SUBMIT );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $sm, $this->recordingLogger() );

			try {
				$job->handle( [ 'order_id' => 7 ] );
				self::fail( 'AC-6: the transient error MUST propagate.' );
			} catch ( SpreadconnectTransientError $e ) {
				self::assertInstanceOf( SpreadconnectTransientError::class, $e );
			}
		}

		// ===================================================================
		// AC-7: Internal idempotency — skip when meta already set at execution.
		// ===================================================================

		/**
		 * AC-7: GIVEN the WC-Order has acquired `_spreadconnect_order_id`
		 *       between enqueue and execution.
		 *       WHEN  `OrderSubmitJob::handle(['order_id' => 7])`.
		 *       THEN  `createOrder()` is NEVER called, no state mutation, no
		 *             order-note; an info-level log with substring
		 *             `job skipped, order already submitted` is written.
		 */
		public function test_handle_skips_when_order_already_has_spreadconnect_order_id(): void
		{
			$order = $this->mockOrder( 7, 'sc_42' );

			$client = $this->mockClient();
			$client->shouldNotReceive( 'createOrder' );

			$sm = $this->mockStateMachine();
			$sm->shouldNotReceive( 'compareAndSet' );

			$order->shouldNotReceive( 'add_order_note' );
			$order->shouldNotReceive( 'update_meta_data' );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $sm, $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-7: the job MUST return cleanly without throwing.' );

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
		 * AC-8: GIVEN CAS `submitting -> NEW` returns false (a parallel
		 *       webhook already advanced state to PROCESSED).
		 *       WHEN  the 2xx response is processed.
		 *       THEN  `_spreadconnect_order_id` is still persisted; a
		 *             race-aware order-note `Submitted to Spreadconnect
		 *             (#SC-sc_42); state already advanced (race)` is added;
		 *             no rethrow.
		 */
		public function test_handle_persists_order_id_even_when_cas_to_new_loses_race(): void
		{
			$order = $this->mockOrder( 7, '' );
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( 'SKU-A', 1 ) ]
			);

			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )->andReturn( [ 'id' => 'sc_42', 'state' => 'PROCESSED' ] );

			$sm = $this->mockStateMachine();
			$sm->shouldReceive( 'compareAndSet' )
				->with( $order, '', OrderStateMachine::STATE_SUBMITTING )
				->once()
				->andReturn( true );
			// CAS to NEW loses race — webhook advanced state already.
			$sm->shouldReceive( 'compareAndSet' )
				->with( $order, OrderStateMachine::STATE_SUBMITTING, OrderStateMachine::STATE_NEW )
				->once()
				->andReturn( false );

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

			$job = new OrderSubmitJob( $client, $sm, $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}
			self::assertNull( $thrown, 'AC-8: the job MUST NOT throw when the submitting->NEW CAS loses the race.' );

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
		 * AC-10: GIVEN WC-Order with two items (each with a SKU + quantity),
		 *        billing+shipping address, customer email + phone.
		 *        WHEN  the job builds the OrderCreate DTO and calls
		 *              `createOrder()`.
		 *        THEN  the DTO carries:
		 *              - externalOrderReference = (string) $order->get_id()
		 *              - one OrderItem per WC line-item with SKU + quantity
		 *                from `get_product()->get_sku()` / `get_quantity()`
		 *              - billingAddress/shippingAddress as Address DTOs
		 *              - shippingType remains null in this slice (Slice 31).
		 */
		public function test_handle_builds_order_create_dto_from_wc_order(): void
		{
			$order = $this->mockOrder( 7, '' );
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

			$captured = null;
			$client = $this->mockClient();
			$client->shouldReceive( 'createOrder' )
				->once()
				->andReturnUsing( static function ( OrderCreate $dto ) use ( &$captured ): array {
					$captured = $dto;
					return [ 'id' => 'sc_42' ];
				} );

			$sm = $this->mockStateMachine();
			$sm->shouldReceive( 'compareAndSet' )->andReturn( true );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $sm );
			$job->handle( [ 'order_id' => 7 ] );

			self::assertInstanceOf( OrderCreate::class, $captured );
			self::assertSame( '7', $captured->externalOrderReference, 'externalOrderReference MUST be the WC-Order-ID as string.' );
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

			// Slice-28 keeps shippingType null — pre-submit wiring is Slice 31.
			self::assertNull( $captured->shippingType, 'AC-10: shippingType MUST remain null in this slice.' );
		}

		/**
		 * AC-10: GIVEN WC-Order with an item that has no SKU (DTO factory
		 *        rejects empty SKUs via InvalidArgumentException).
		 *        THEN  the job treats the validation failure as a permanent
		 *              fail (analogous to AC-5): `compareAndSet(submitting,
		 *              failed_to_submit)` is called, NO `createOrder()` call,
		 *              NO `_spreadconnect_order_id` persisted, and the job
		 *              returns without rethrowing.
		 */
		public function test_dto_validation_failure_is_treated_as_permanent_failure(): void
		{
			$order = $this->mockOrder( 7, '' );

			// Item without SKU triggers InvalidArgumentException from
			// OrderItem::fromArray().
			$order->shouldReceive( 'get_items' )->andReturn(
				[ $this->mockItem( '', 1 ) ]
			);

			$client = $this->mockClient();
			$client->shouldNotReceive( 'createOrder' );

			$sm = $this->mockStateMachine();
			$sm->shouldReceive( 'compareAndSet' )
				->with( $order, '', OrderStateMachine::STATE_SUBMITTING )
				->once()
				->andReturn( true );
			$sm->shouldReceive( 'compareAndSet' )
				->with( $order, OrderStateMachine::STATE_SUBMITTING, OrderStateMachine::STATE_FAILED_TO_SUBMIT )
				->once()
				->andReturn( true );

			$order->shouldNotReceive( 'update_meta_data' )
				->with( '_spreadconnect_order_id', Mockery::any() );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$job = new OrderSubmitJob( $client, $sm, $this->recordingLogger() );

			$thrown = null;
			try {
				$job->handle( [ 'order_id' => 7 ] );
			} catch ( \Throwable $t ) {
				$thrown = $t;
			}

			self::assertNull(
				$thrown,
				'AC-10: DTO-validation-failure path MUST NOT rethrow (treated as permanent fail like 4xx).'
			);

			// AC-5 (c) extension: failed_op_pending_record must be logged
			// with `error_code = 'dto_validation'`.
			$matches = array_filter(
				$this->loggerCalls,
				static fn( array $c ): bool => 'error' === $c['level']
					&& 'failed_op_pending_record' === ( $c['context']['tag'] ?? null )
					&& 'dto_validation' === ( $c['context']['error_code'] ?? null )
			);
			self::assertNotEmpty(
				$matches,
				'AC-10: DTO-validation failure must emit failed_op_pending_record with error_code="dto_validation".'
			);
		}

		// ===================================================================
		// AC-9: Bootstrap registers WC + AS hooks idempotently.
		// ===================================================================

		/**
		 * AC-9: GIVEN Plugin::init().
		 *       THEN  (a) `woocommerce_order_status_processing` has a
		 *             registered listener (priority 10, accepting 2 args).
		 *             (b) `spreadconnect/create_order` has a registered
		 *             listener (priority 10, accepting 1 arg).
		 */
		public function test_plugin_init_registers_processing_and_create_order_hooks(): void
		{
			$pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
			$pluginFile = self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';

			// Pre-condition: nothing registered yet.
			self::assertFalse(
				Actions\has( 'woocommerce_order_status_processing' ),
				'AC-9 (precondition): no listeners before init().'
			);

			$pluginFqcn::init( $pluginFile );

			self::assertNotFalse(
				Actions\has( 'woocommerce_order_status_processing' ),
				'AC-9 (a): a listener for "woocommerce_order_status_processing" MUST be registered.'
			);
			self::assertNotFalse(
				Actions\has( 'spreadconnect/create_order' ),
				'AC-9 (b): a listener for "spreadconnect/create_order" MUST be registered.'
			);
		}

		/**
		 * AC-9: Idempotency — calling Plugin::init() twice must NOT register
		 * the hooks twice. Brain\Monkey returns the priority of a single
		 * matching listener via Actions\has() — the count of matching
		 * registrations stays identical between the first and second init().
		 */
		public function test_plugin_init_is_idempotent_on_double_call(): void
		{
			$pluginFqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
			$pluginFile = self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';

			$pluginFqcn::init( $pluginFile );

			$priorityProcessingFirst = Actions\has( 'woocommerce_order_status_processing' );
			$priorityCreateOrderFirst = Actions\has( 'spreadconnect/create_order' );

			// Second init() must be a no-op.
			$pluginFqcn::init( $pluginFile );

			$priorityProcessingSecond = Actions\has( 'woocommerce_order_status_processing' );
			$priorityCreateOrderSecond = Actions\has( 'spreadconnect/create_order' );

			self::assertSame(
				$priorityProcessingFirst,
				$priorityProcessingSecond,
				'AC-9: doubled Plugin::init() MUST NOT change has_action() output for "woocommerce_order_status_processing".'
			);
			self::assertSame(
				$priorityCreateOrderFirst,
				$priorityCreateOrderSecond,
				'AC-9: doubled Plugin::init() MUST NOT change has_action() output for "spreadconnect/create_order".'
			);
		}

		// ===================================================================
		// Helpers (instance + static)
		// ===================================================================

		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}
	}

	/**
	 * Minimal logger spy for the `wc_get_logger()` fallback path. The
	 * production code resolves `wc_get_logger()` only when no WC_Logger was
	 * injected — Slice28 tests inject a recording Mockery double almost
	 * everywhere, but the fallback must not blow up either.
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
