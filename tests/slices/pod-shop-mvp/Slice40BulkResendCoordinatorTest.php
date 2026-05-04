<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 40 — Bulk-Resend-Coordinator (Outcome-Panel)
//
// Spec: specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/
//       slices/slice-40-bulk-resend-coordinator.md
//
// Mocking strategy per slice-40 spec (`mock_external`):
//   - Brain\Monkey aliased fuer WP-AJAX/Cap/Nonce/JSON-Response/Logger Helpers
//     (`check_ajax_referer`, `current_user_can`, `wp_send_json_success`,
//     `wp_send_json_error`, `as_enqueue_async_action`, `wp_generate_uuid4`,
//     `wc_get_logger`, `wc_get_order`, `__`, `_n`, `sprintf` (no stub - native),
//     `wp_register_script`, `wp_enqueue_script`, `wp_localize_script`,
//     `admin_url`, `plugins_url`, `wp_create_nonce`, `wp_unslash`,
//     `esc_html__`, `esc_html`, `esc_attr`, `wp_json_encode`).
//   - `wp_send_json_*` werfen `Slice40JsonSentinel` (Payload + Status), damit
//     der PHPUnit-Prozess nicht via `die()` terminiert.
//   - `FailedOpsRepo` als REALE Instanz gegen einen `Slice40FakeWpdb`-Stub
//     (gleicher Pattern wie Slice 37/38, weil FailedOpsRepo `final` ist).
//   - `WC_Order` via Mockery::mock-Subclass mit configurable `get_meta`.
//
// Test-Konvention:
//   `tests/slices/pod-shop-mvp/Slice40BulkResendCoordinatorTest.php` mit
//   `final class Slice40BulkResendCoordinatorTest extends TestCase`.
// ---------------------------------------------------------------------------

namespace {

	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
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
	use SpreadconnectPod\Failure\BulkResendCoordinator;
	use SpreadconnectPod\Failure\FailedOpsRepo;
	use SpreadconnectPod\Hub\View\FailedOps;

	/**
	 * Sentinel exception used to interrupt control flow when
	 * `wp_send_json_*` is invoked. WP terminates the request via `die()`
	 * after writing the JSON response — in the test environment we cannot
	 * afford to terminate the PHPUnit process, so the stubs throw
	 * `Slice40JsonSentinel` carrying the payload + status; the test catches
	 * it and inspects the captured value.
	 */
	final class Slice40JsonSentinel extends \RuntimeException
	{
		/** @var array<string, mixed> */
		public array $payload;

		public bool $success;

		public ?int $status;

		/**
		 * @param array<string, mixed> $payload
		 */
		public function __construct( bool $success, array $payload, ?int $status )
		{
			parent::__construct( $success ? 'wp_send_json_success' : 'wp_send_json_error' );
			$this->success = $success;
			$this->payload = $payload;
			$this->status  = $status;
		}
	}

	/**
	 * Recording `wpdb` double for slice-40 — captures `get_row` /
	 * `get_results` / `update` calls through `prepare()` and lets each test
	 * pre-program the return values.
	 *
	 * Supports per-id-keyed `findById` lookups (`getRowResultById[$id]`) so
	 * a single repo instance can serve multiple distinct rows in a Bulk-Pfad.
	 */
	class Slice40FakeWpdb extends \wpdb
	{
		public string $prefix = 'wp_';

		public int $insert_id = 0;

		public string $last_error = '';

		/** @var list<array{table:string,data:array<string,mixed>,format:array|string|null}> */
		public array $insertCalls = array();

		/** @var list<array{table:string,data:array<string,mixed>,where:array<string,mixed>}> */
		public array $updateCalls = array();

		/** @var list<string> */
		public array $getResultsCalls = array();

		/** @var list<string> */
		public array $getRowCalls = array();

		/** @var array<int, array<string, mixed>|null> */
		public array $getRowResultById = array();

		/** @var array<string, list<array<string, mixed>>> */
		public array $getResultsByEntity = array();

		/** @var array<string, mixed>|null */
		public ?array $getRowResult = null;

		/** @var list<array<string, mixed>> */
		public array $getResultsResult = array();

		public mixed $getVarResult = null;

		public int $updateResult = 1;

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

			// Per-id lookup: if the SQL contains "WHERE id = N" extract N
			// and look up the per-id map.
			if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) === 1 ) {
				$id = (int) $m[1];
				if ( array_key_exists( $id, $this->getRowResultById ) ) {
					return $this->getRowResultById[ $id ];
				}
			}

			return $this->getRowResult;
		}

		public function get_results( string $sql, $output = 'OBJECT' ): array
		{
			$this->getResultsCalls[] = $sql;

			// Per-entity lookup: SQL contains
			// "WHERE related_entity_type = 'X' AND related_entity_id = 'Y'"
			if ( preg_match(
				"/related_entity_type = '([^']+)' AND related_entity_id = '([^']+)'/",
				$sql,
				$m
			) === 1 ) {
				$key = $m[1] . ':' . $m[2];
				if ( array_key_exists( $key, $this->getResultsByEntity ) ) {
					return $this->getResultsByEntity[ $key ];
				}
			}

			return $this->getResultsResult;
		}
	}

	/**
	 * Logger spy — collects level/message/context tuples from
	 * `wc_get_logger()->info()/warning()/error()` calls so AC-4 / AC-7
	 * can assert on the structured-log shape.
	 */
	final class Slice40LoggerSpy
	{
		/** @var list<array{level:string,message:string,context:array<string,mixed>}> */
		public array $entries = array();

		public function info( string $message, array $context = array() ): void
		{
			$this->entries[] = array( 'level' => 'info', 'message' => $message, 'context' => $context );
		}

		public function warning( string $message, array $context = array() ): void
		{
			$this->entries[] = array( 'level' => 'warning', 'message' => $message, 'context' => $context );
		}

		public function error( string $message, array $context = array() ): void
		{
			$this->entries[] = array( 'level' => 'error', 'message' => $message, 'context' => $context );
		}

		public function debug( string $message, array $context = array() ): void
		{
			$this->entries[] = array( 'level' => 'debug', 'message' => $message, 'context' => $context );
		}

		public function log( string $level, string $message, array $context = array() ): void
		{
			$this->entries[] = array( 'level' => $level, 'message' => $message, 'context' => $context );
		}

		public function entriesByTag( string $tag ): array
		{
			$out = array();
			foreach ( $this->entries as $entry ) {
				if ( ( $entry['context']['tag'] ?? null ) === $tag ) {
					$out[] = $entry;
				}
			}
			return $out;
		}
	}

	/**
	 * Slice 40 acceptance + integration tests — Bulk-Resend-Coordinator.
	 *
	 * Mapping:
	 *   - AC-1 .. AC-4 -> {@see BulkResendCoordinator::preflight() / run()}.
	 *   - AC-5 .. AC-6 -> {@see BulkResendCoordinator::resendFailedOps()}.
	 *   - AC-7 .. AC-8 -> {@see BulkResendCoordinator::dismissFailedOps()}.
	 *   - AC-9 .. AC-10 -> AJAX adapters
	 *     (`handleBulkResendAjax()` / `handleBulkDismissAjax()`).
	 *   - AC-11 .. AC-12 -> View Markup +
	 *     {@see Hub\View\FailedOps::render()} bulk-UI assertions.
	 *   - AC-13 .. AC-14 -> JS-driven banner markup-class-existence assertions
	 *     against the source asset (Markup-Inspection per Slice-Skeleton).
	 *   - AC-15 -> {@see Plugin::init()} hook-wiring + idempotency +
	 *     constructor backward-compat.
	 */
	final class Slice40BulkResendCoordinatorTest extends TestCase
	{
		/**
		 * Captured `as_enqueue_async_action` invocations as
		 * `[hook, args, group]`.
		 *
		 * @var list<array{0:string,1:array<string,mixed>,2:string}>
		 */
		public array $enqueuedActions = array();

		/**
		 * Captured `wp_localize_script` invocations as
		 * `[handle, object_name, payload]`.
		 *
		 * @var list<array{0:string,1:string,2:mixed}>
		 */
		public array $localizeCalls = array();

		/**
		 * Captured `wp_register_script` invocations as
		 * `[handle, src, deps, ver, in_footer]`.
		 *
		 * @var list<array{0:string,1:string,2:array,3:mixed,4:bool}>
		 */
		public array $registerScriptCalls = array();

		/**
		 * Captured `wp_enqueue_script` invocations as `[handle]`.
		 *
		 * @var list<string>
		 */
		public array $enqueueScriptCalls = array();

		public Slice40LoggerSpy $loggerSpy;

		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}

		private static function pluginMainFile(): string
		{
			return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';
		}

		private static function bulkAssetPath(): string
		{
			return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/assets/js/failed-ops-bulk.js';
		}

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->enqueuedActions     = array();
			$this->localizeCalls       = array();
			$this->registerScriptCalls = array();
			$this->enqueueScriptCalls  = array();
			$this->loggerSpy           = new Slice40LoggerSpy();

			// ---- i18n + escape helpers (identity stubs) -------------------
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'esc_attr__' )->returnArg( 1 );
			Functions\when( 'esc_html' )->returnArg( 1 );
			Functions\when( 'esc_attr' )->returnArg( 1 );
			Functions\when( 'esc_url' )->returnArg( 1 );

			// _n() — return singular for n=1, plural for n>=2.
			Functions\when( '_n' )->alias(
				static function ( $singular, $plural, $count, $domain = null ) {
					return ( (int) $count === 1 ) ? (string) $singular : (string) $plural;
				}
			);

			// ---- sanitize/unslash helpers ---------------------------------
			Functions\when( 'wp_unslash' )->alias(
				static fn ( $v ) => is_string( $v ) ? stripslashes( $v ) : $v
			);
			Functions\when( 'sanitize_text_field' )->alias(
				static function ( $v ): string {
					if ( ! is_string( $v ) ) {
						return '';
					}
					$clean = strip_tags( $v );
					$clean = preg_replace( '/[\r\n\t]+/', ' ', $clean ) ?? '';
					return trim( $clean );
				}
			);
			Functions\when( 'absint' )->alias(
				static fn ( $v ): int => abs( (int) $v )
			);
			Functions\when( 'sanitize_key' )->alias(
				static function ( $key ) {
					if ( ! is_string( $key ) ) {
						return '';
					}
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
				}
			);

			// ---- URL / asset helpers --------------------------------------
			Functions\when( 'admin_url' )->alias(
				static fn ( $path = '' ) => 'http://example.test/wp-admin/' . ltrim( (string) $path, '/' )
			);
			Functions\when( 'plugins_url' )->alias(
				static function ( $path = '', $plugin = '' ) {
					return 'http://example.test/plugins/' . ltrim( (string) $path, '/' );
				}
			);
			Functions\when( 'wp_create_nonce' )->alias(
				static fn ( $action = -1 ) => 'NONCE_' . (string) $action
			);
			Functions\when( 'wp_json_encode' )->alias(
				static function ( $data, $options = 0, $depth = 512 ) {
					return json_encode( $data, $options, $depth );
				}
			);

			// ---- script enqueue capture -----------------------------------
			$self = $this;
			Functions\when( 'wp_register_script' )->alias(
				static function ( string $handle, string $src, $deps = array(), $ver = false, $in_footer = false ) use ( $self ): bool {
					$self->registerScriptCalls[] = array( $handle, $src, (array) $deps, $ver, (bool) $in_footer );
					return true;
				}
			);
			Functions\when( 'wp_enqueue_script' )->alias(
				static function ( string $handle, string $src = '', $deps = array(), $ver = false, $in_footer = false ) use ( $self ): void {
					$self->enqueueScriptCalls[] = $handle;
				}
			);
			Functions\when( 'wp_localize_script' )->alias(
				static function ( string $handle, string $object_name, $l10n ) use ( $self ): bool {
					$self->localizeCalls[] = array( $handle, $object_name, $l10n );
					return true;
				}
			);

			// ---- wp_send_json_* sentinels ---------------------------------
			Functions\when( 'wp_send_json_success' )->alias(
				static function ( $data = null, $status_code = null ): void {
					throw new Slice40JsonSentinel( true, (array) $data, $status_code );
				}
			);
			Functions\when( 'wp_send_json_error' )->alias(
				static function ( $data = null, $status_code = null ): void {
					throw new Slice40JsonSentinel( false, (array) $data, $status_code );
				}
			);

			// ---- as_enqueue_async_action capture --------------------------
			Functions\when( 'as_enqueue_async_action' )->alias(
				static function ( string $hook, array $args = array(), string $group = '' ) use ( $self ): int {
					$self->enqueuedActions[] = array( $hook, $args, $group );
					return count( $self->enqueuedActions );
				}
			);

			// ---- wp_generate_uuid4 — deterministic for assertions ----------
			Functions\when( 'wp_generate_uuid4' )->alias(
				static fn () => '11111111-2222-4333-8444-555555555555'
			);

			// ---- WC logger spy --------------------------------------------
			$logger = $this->loggerSpy;
			Functions\when( 'wc_get_logger' )->alias(
				static fn () => $logger
			);

			// ---- current_time + UTC helper --------------------------------
			Functions\when( 'current_time' )->alias(
				static fn ( $type = 'mysql', $gmt = 0 ) => '2026-05-04 12:00:00'
			);

			// ---- default wc_get_order — false (overridden per-test) -------
			Functions\when( 'wc_get_order' )->justReturn( false );

			// ---- default current_user_can — true (overridden per-test) ----
			Functions\when( 'current_user_can' )->justReturn( true );

			// ---- default check_ajax_referer — true (overridden per-test) -
			Functions\when( 'check_ajax_referer' )->justReturn( true );

			// ---- HubController::ensureCapability — no-op for view tests ---
			// This keeps `FailedOps::render()` from short-circuiting on the
			// cap-gate. The static method exists but cap is gated by
			// current_user_can which we already stubbed to true.
		}

		protected function tearDown(): void
		{
			$_POST = array();
			$_GET  = array();
			unset( $GLOBALS['wpdb'] );
			Mockery::close();
			Monkey\tearDown();

			// Reset the Plugin::init() guard so the AC-15 idempotency tests
			// can re-run init() in a clean state across test cases.
			$pluginFqcn = Plugin::class;
			if ( class_exists( $pluginFqcn, false ) ) {
				$ref = new ReflectionClass( $pluginFqcn );
				if ( $ref->hasProperty( 'initialized' ) ) {
					$ref->getProperty( 'initialized' )->setValue( null, false );
				}
				if ( $ref->hasProperty( 'pluginFile' ) ) {
					$ref->getProperty( 'pluginFile' )->setValue( null, '' );
				}
			}

			parent::tearDown();
		}

		// ===================================================================
		// Helpers
		// ===================================================================

		private function makeWpdb(): Slice40FakeWpdb
		{
			$wpdb         = new Slice40FakeWpdb();
			$wpdb->prefix = 'wp_';
			return $wpdb;
		}

		private function makeRepo( ?Slice40FakeWpdb $wpdb = null ): FailedOpsRepo
		{
			$wpdb = $wpdb ?? $this->makeWpdb();
			return new FailedOpsRepo( $wpdb );
		}

		/**
		 * Build a Mockery double for `\WC_Order` with `get_meta()` returning
		 * a deterministic state from a per-id map. The default state is
		 * `'failed_to_submit'` for every id; tests override on demand.
		 */
		private function makeWcOrderMock( int $id, string $state = 'failed_to_submit' ): \Mockery\MockInterface
		{
			$mock = Mockery::mock( \WC_Order::class );
			$mock->shouldReceive( 'get_id' )->andReturn( $id )->byDefault();
			$mock->shouldReceive( 'get_meta' )
				->with( '_spreadconnect_state', Mockery::any(), Mockery::any() )
				->andReturn( $state )
				->byDefault();
			$mock->shouldReceive( 'get_meta' )
				->with( '_spreadconnect_state' )
				->andReturn( $state )
				->byDefault();
			return $mock;
		}

		/**
		 * Wire `wc_get_order($id)` so each id resolves to a Mockery `WC_Order`
		 * with the supplied state-meta. ids missing from the map -> false.
		 *
		 * @param array<int,string> $ordersById  Map id -> state.
		 */
		private function wireOrders( array $ordersById ): void
		{
			$mocks = array();
			foreach ( $ordersById as $id => $state ) {
				$mocks[ (int) $id ] = $this->makeWcOrderMock( (int) $id, (string) $state );
			}
			Functions\when( 'wc_get_order' )->alias(
				static function ( $id ) use ( $mocks ) {
					$key = (int) $id;
					return $mocks[ $key ] ?? false;
				}
			);
		}

		/**
		 * Run a coordinator AJAX handler and capture the JsonSentinel payload.
		 *
		 * @return Slice40JsonSentinel|null Captured sentinel or null when
		 *                                   no JSON response was sent.
		 */
		private function runHandler( BulkResendCoordinator $coord, string $method ): ?Slice40JsonSentinel
		{
			try {
				$coord->{$method}();
			} catch ( Slice40JsonSentinel $e ) {
				return $e;
			}
			return null;
		}

		/**
		 * Capture the FailedOps view output. Provides a $wpdb double via
		 * GLOBALS so the View-internal `new FailedOpsRepo($wpdb)` resolves.
		 *
		 * @param list<array<string, mixed>> $rows  pre-hydrated rows.
		 */
		private function captureViewOutput( array $rows ): string
		{
			$wpdb = $this->makeWpdb();

			// FailedOpsRepo::findAll() builds a SELECT and calls get_results().
			$encoded = array();
			foreach ( $rows as $row ) {
				if ( isset( $row['payload'] ) && is_array( $row['payload'] ) ) {
					$row['payload'] = json_encode( $row['payload'] );
				}
				$encoded[] = $row;
			}
			$wpdb->getResultsResult = $encoded;

			$GLOBALS['wpdb'] = $wpdb;

			ob_start();
			try {
				FailedOps::render();
			} finally {
				$out = (string) ob_get_clean();
			}
			return $out;
		}

		/**
		 * Encode payload as JSON-string so FailedOpsRepo::hydrate() decodes
		 * back into the canonical wire-shape.
		 *
		 * @param array<string, mixed> $row
		 * @return array<string, mixed>
		 */
		private function withJsonPayload( array $row ): array
		{
			if ( isset( $row['payload'] ) && is_array( $row['payload'] ) ) {
				$row['payload'] = json_encode( $row['payload'] );
			}
			return $row;
		}

		// ===================================================================
		// AC-1: preflight liefert eligible_ids_with_op_id additiv
		// ===================================================================

		/**
		 * AC-1: GIVEN preflight([1,2,3]) — Order 1+2 failed, Order 3 NEW;
		 *             findByEntity('order','1','unresolved') -> [{id=99,...}];
		 *             findByEntity('order','2','unresolved') -> [].
		 *       WHEN  preflight() runs
		 *       THEN  eligible_ids_with_op_id = [1=>99, 2=>null].
		 */
		public function test_preflight_adds_failed_op_id_lookup_for_eligible_orders(): void
		{
			$this->wireOrders( array(
				1 => 'failed_to_submit',
				2 => 'failed_to_submit',
				3 => 'NEW',
			) );

			$wpdb = $this->makeWpdb();
			$wpdb->getResultsByEntity['order:1'] = array(
				$this->withJsonPayload( array(
					'id'                  => '99',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '1',
					'payload'             => array( 'order_id' => 1 ),
					'state'               => 'unresolved',
				) ),
			);
			$wpdb->getResultsByEntity['order:2'] = array(); // empty
			$repo = $this->makeRepo( $wpdb );

			$coord = new BulkResendCoordinator( $repo );
			$result = $coord->preflight( array( 1, 2, 3 ) );

			self::assertArrayHasKey( 'eligible_ids_with_op_id', $result );
			self::assertSame(
				array( 1 => 99, 2 => null ),
				$result['eligible_ids_with_op_id'],
				'AC-1: eligible_ids_with_op_id MUST map order_id -> failed_op_id (or null).'
			);
		}

		/**
		 * AC-1: GIVEN findByEntity returns [] for an eligible order
		 *       WHEN  preflight() runs
		 *       THEN  eligible_ids_with_op_id[$id] = null (not absent, not 0).
		 */
		public function test_preflight_returns_null_when_no_repo_row_for_eligible(): void
		{
			$this->wireOrders( array( 7 => 'failed_to_submit' ) );

			$wpdb = $this->makeWpdb();
			$wpdb->getResultsByEntity['order:7'] = array();
			$repo = $this->makeRepo( $wpdb );

			$coord = new BulkResendCoordinator( $repo );
			$result = $coord->preflight( array( 7 ) );

			self::assertArrayHasKey( 7, $result['eligible_ids_with_op_id'] );
			self::assertNull(
				$result['eligible_ids_with_op_id'][7],
				'AC-1: when findByEntity returns [] the value MUST be null.'
			);
		}

		/**
		 * AC-1: Slice-33 contract keys (will_resend / will_skip / eligible_ids
		 * / skipped) MUST remain unchanged.
		 */
		public function test_preflight_keeps_slice_33_contract_keys_unchanged(): void
		{
			$this->wireOrders( array(
				1 => 'failed_to_submit',
				2 => 'failed_to_submit',
				3 => 'NEW',
			) );

			$wpdb = $this->makeWpdb();
			$wpdb->getResultsByEntity['order:1'] = array();
			$wpdb->getResultsByEntity['order:2'] = array();
			$repo = $this->makeRepo( $wpdb );

			$coord = new BulkResendCoordinator( $repo );
			$result = $coord->preflight( array( 1, 2, 3 ) );

			self::assertSame( 2, $result['will_resend'], 'AC-1: will_resend stable.' );
			self::assertSame( 1, $result['will_skip'], 'AC-1: will_skip stable.' );
			self::assertSame( array( 1, 2 ), $result['eligible_ids'], 'AC-1: eligible_ids stable.' );
			self::assertSame(
				array( 3 => 'not_failed' ),
				$result['skipped'],
				'AC-1: skipped map stable.'
			);
		}

		/**
		 * AC-1 last clause: Defensive default — when findByEntity throws,
		 * value is `null`; method does NOT re-throw.
		 *
		 * `FailedOpsRepo` is `final` so we cannot Mockery-mock it. Instead
		 * we install a `wpdb` double that throws on `get_results()`, which
		 * is exactly the path `findByEntity()` traverses.
		 */
		public function test_preflight_defaults_to_null_when_repo_throws(): void
		{
			$this->wireOrders( array( 5 => 'failed_to_submit' ) );

			$wpdb = new class extends Slice40FakeWpdb {
				public function get_results( string $sql, $output = 'OBJECT' ): array
				{
					throw new \RuntimeException( 'db down' );
				}
			};

			$repo = new FailedOpsRepo( $wpdb );

			$coord = new BulkResendCoordinator( $repo );
			$result = $coord->preflight( array( 5 ) );

			self::assertArrayHasKey(
				5,
				$result['eligible_ids_with_op_id'],
				'AC-1: eligible order MUST appear in eligible_ids_with_op_id even when repo throws.'
			);
			self::assertNull(
				$result['eligible_ids_with_op_id'][5],
				'AC-1: throw -> defensive default null.'
			);
		}

		// ===================================================================
		// AC-2: run() schedules `spreadconnect/create_order` per eligible
		// ===================================================================

		/**
		 * AC-2: GIVEN preflight result [1,2 eligible; 3 not_failed]
		 *       WHEN  run() runs
		 *       THEN  as_enqueue_async_action fires exactly 2x — once per
		 *             eligible order — with the documented hook + group.
		 */
		public function test_run_enqueues_create_order_action_per_eligible_order(): void
		{
			$this->wireOrders( array(
				1 => 'failed_to_submit',
				2 => 'failed_to_submit',
				3 => 'NEW',
			) );

			$wpdb = $this->makeWpdb();
			$repo = $this->makeRepo( $wpdb );

			$coord = new BulkResendCoordinator( $repo );
			$coord->run( array( 1, 2, 3 ) );

			self::assertCount(
				2,
				$this->enqueuedActions,
				'AC-2: as_enqueue_async_action MUST fire exactly twice for two eligible orders.'
			);
			self::assertSame(
				array( 'spreadconnect/create_order', array( 'order_id' => 1 ), 'spreadconnect' ),
				$this->enqueuedActions[0],
				'AC-2: first call MUST schedule create_order for order_id=1.'
			);
			self::assertSame(
				array( 'spreadconnect/create_order', array( 'order_id' => 2 ), 'spreadconnect' ),
				$this->enqueuedActions[1],
				'AC-2: second call MUST schedule create_order for order_id=2.'
			);
		}

		/**
		 * AC-2: skipped orders MUST NOT be scheduled.
		 */
		public function test_run_does_not_enqueue_for_skipped_orders(): void
		{
			$this->wireOrders( array( 3 => 'NEW' ) );

			$repo = $this->makeRepo();
			$coord = new BulkResendCoordinator( $repo );
			$result = $coord->run( array( 3 ) );

			self::assertSame( array(), $this->enqueuedActions, 'AC-2: no enqueue for skipped.' );
			self::assertSame( 'not_failed', $result['per_row'][3] ?? null );
		}

		/**
		 * AC-2: run() MUST return a UUID-shaped run_id.
		 */
		public function test_run_returns_run_id_uuid(): void
		{
			$this->wireOrders( array( 1 => 'failed_to_submit' ) );

			$repo = $this->makeRepo();
			$coord = new BulkResendCoordinator( $repo );
			$result = $coord->run( array( 1 ) );

			self::assertArrayHasKey( 'run_id', $result );
			self::assertMatchesRegularExpression(
				'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
				(string) $result['run_id'],
				'AC-2: run_id MUST be a UUIDv4 from wp_generate_uuid4().'
			);
		}

		/**
		 * AC-2: run() MUST NOT call markResolved (resolved-mark happens in
		 * OrderSubmitJob on successful re-run).
		 */
		public function test_run_does_not_call_mark_resolved(): void
		{
			$this->wireOrders( array( 1 => 'failed_to_submit' ) );

			$wpdb = $this->makeWpdb();
			$repo = $this->makeRepo( $wpdb );

			$coord = new BulkResendCoordinator( $repo );
			$coord->run( array( 1 ) );

			self::assertSame(
				array(),
				$wpdb->updateCalls,
				'AC-2: run() MUST NOT call markResolved (no UPDATE statement).'
			);
		}

		/**
		 * AC-2: per_row map carries correct markers.
		 */
		public function test_run_per_row_map_carries_requeued_and_skip_reasons(): void
		{
			$this->wireOrders( array(
				1 => 'failed_to_submit',
				2 => 'failed_to_submit',
				3 => 'NEW',
			) );

			$repo = $this->makeRepo();
			$coord = new BulkResendCoordinator( $repo );
			$result = $coord->run( array( 1, 2, 3 ) );

			self::assertSame( 2, $result['queued'] );
			self::assertSame( 1, $result['skipped'] );
			self::assertSame( 'requeued', $result['per_row'][1] );
			self::assertSame( 'requeued', $result['per_row'][2] );
			self::assertSame( 'not_failed', $result['per_row'][3] );
		}

		// ===================================================================
		// AC-3: run() with zero eligible
		// ===================================================================

		/**
		 * AC-3: GIVEN run([3]) — order 3 has state=NEW (not eligible)
		 *       WHEN  run() runs
		 *       THEN  queued=0, skipped=1, per_row=[3=>'not_failed'].
		 */
		public function test_run_returns_zero_queued_when_no_eligible(): void
		{
			$this->wireOrders( array( 3 => 'NEW' ) );

			$repo = $this->makeRepo();
			$coord = new BulkResendCoordinator( $repo );
			$result = $coord->run( array( 3 ) );

			self::assertSame( 0, $result['queued'], 'AC-3: queued MUST be 0.' );
			self::assertSame( 1, $result['skipped'], 'AC-3: skipped MUST be 1.' );
			self::assertSame(
				array( 3 => 'not_failed' ),
				$result['per_row'],
				'AC-3: per_row contains only the skip reason.'
			);
		}

		/**
		 * AC-3: zero-eligible MUST not call as_enqueue_async_action at all.
		 */
		public function test_run_does_not_enqueue_anything_when_no_eligible(): void
		{
			$this->wireOrders( array( 3 => 'NEW' ) );

			$repo = $this->makeRepo();
			$coord = new BulkResendCoordinator( $repo );
			$coord->run( array( 3 ) );

			self::assertSame(
				array(),
				$this->enqueuedActions,
				'AC-3: as_enqueue_async_action MUST NOT be called when no eligible orders.'
			);
		}

		// ===================================================================
		// AC-4: structured logging (single info per run, no per-row warnings)
		// ===================================================================

		/**
		 * AC-4: GIVEN successful run([1,2,3])
		 *       WHEN  run() runs
		 *       THEN  wc_get_logger()->info() is called once with tag
		 *             'bulk_resend_run' carrying run_id + queued + skipped +
		 *             per_row_summary.
		 */
		public function test_run_writes_structured_info_log_with_run_id(): void
		{
			$this->wireOrders( array(
				1 => 'failed_to_submit',
				2 => 'failed_to_submit',
				3 => 'NEW',
			) );

			$repo = $this->makeRepo();
			$coord = new BulkResendCoordinator( $repo );
			$coord->run( array( 1, 2, 3 ) );

			$infoEntries = $this->loggerSpy->entriesByTag( 'bulk_resend_run' );
			self::assertNotEmpty(
				$infoEntries,
				'AC-4: at least one info entry tagged "bulk_resend_run" MUST be logged.'
			);

			$entry = $infoEntries[0];
			self::assertSame( 'info', $entry['level'], 'AC-4: log level MUST be info.' );
			self::assertArrayHasKey( 'run_id', $entry['context'], 'AC-4: context.run_id present.' );
			self::assertSame( 2, $entry['context']['queued'] ?? null, 'AC-4: queued=2.' );
			self::assertSame( 1, $entry['context']['skipped'] ?? null, 'AC-4: skipped=1.' );
			self::assertArrayHasKey( 'per_row_summary', $entry['context'], 'AC-4: per_row_summary present.' );
			self::assertSame(
				2,
				$entry['context']['per_row_summary']['requeued'] ?? null,
				'AC-4: per_row_summary[requeued]=2.'
			);
			self::assertSame(
				1,
				$entry['context']['per_row_summary']['not_failed'] ?? null,
				'AC-4: per_row_summary[not_failed]=1.'
			);
		}

		/**
		 * AC-4: NO per-row warning logs (would spam at large selections).
		 */
		public function test_run_does_not_log_per_row_warnings_for_skipped(): void
		{
			$this->wireOrders( array(
				1 => 'failed_to_submit',
				2 => 'NEW',
				3 => 'NEW',
			) );

			$repo = $this->makeRepo();
			$coord = new BulkResendCoordinator( $repo );
			$coord->run( array( 1, 2, 3 ) );

			$warnings = array_filter(
				$this->loggerSpy->entries,
				static fn ( $e ) => 'warning' === $e['level']
			);
			self::assertSame(
				array(),
				array_values( $warnings ),
				'AC-4: no per-row warnings — single info summary only.'
			);
		}

		// ===================================================================
		// AC-5: resendFailedOps happy path
		// ===================================================================

		/**
		 * AC-5: GIVEN three failed_op rows with op_types create_order /
		 *             sync_article / confirm_order
		 *       WHEN  resendFailedOps([10,11,12]) runs
		 *       THEN  three distinct AS-hooks scheduled per the
		 *             OP_TYPE_TO_HOOK mapping.
		 */
		public function test_resend_failed_ops_enqueues_correct_hook_per_op_type(): void
		{
			$wpdb = $this->makeWpdb();
			$wpdb->getRowResultById = array(
				10 => $this->withJsonPayload( array(
					'id'                  => '10',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '1',
					'payload'             => array( 'order_id' => 1 ),
					'state'               => 'unresolved',
				) ),
				11 => $this->withJsonPayload( array(
					'id'                  => '11',
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '500',
					'payload'             => array( 'article_id' => 500 ),
					'state'               => 'unresolved',
				) ),
				12 => $this->withJsonPayload( array(
					'id'                  => '12',
					'op_type'             => 'confirm_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '2',
					'payload'             => array( 'order_id' => 2 ),
					'state'               => 'unresolved',
				) ),
			);
			$wpdb->updateResult = 1;

			$repo  = $this->makeRepo( $wpdb );
			$coord = new BulkResendCoordinator( $repo );
			$coord->resendFailedOps( array( 10, 11, 12 ) );

			self::assertCount( 3, $this->enqueuedActions, 'AC-5: 3 schedules expected.' );
			self::assertSame(
				array( 'spreadconnect/create_order', array( 'order_id' => 1 ), 'spreadconnect' ),
				$this->enqueuedActions[0]
			);
			self::assertSame(
				array( 'spreadconnect/sync_article', array( 'article_id' => 500 ), 'spreadconnect' ),
				$this->enqueuedActions[1]
			);
			self::assertSame(
				array( 'spreadconnect/confirm_order', array( 'order_id' => 2 ), 'spreadconnect' ),
				$this->enqueuedActions[2]
			);
		}

		/**
		 * AC-5: resendFailedOps MUST mark each successful row resolved.
		 */
		public function test_resend_failed_ops_marks_each_row_resolved(): void
		{
			$wpdb = $this->makeWpdb();
			$wpdb->getRowResultById = array(
				10 => $this->withJsonPayload( array(
					'id'                  => '10',
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '5',
					'payload'             => array( 'article_id' => 5 ),
					'state'               => 'unresolved',
				) ),
				11 => $this->withJsonPayload( array(
					'id'                  => '11',
					'op_type'             => 'confirm_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '7',
					'payload'             => array( 'order_id' => 7 ),
					'state'               => 'unresolved',
				) ),
			);
			$wpdb->updateResult = 1;

			$repo  = $this->makeRepo( $wpdb );
			$coord = new BulkResendCoordinator( $repo );
			$coord->resendFailedOps( array( 10, 11 ) );

			self::assertCount(
				2,
				$wpdb->updateCalls,
				'AC-5: each resent row MUST receive an UPDATE (markResolved).'
			);
			foreach ( $wpdb->updateCalls as $call ) {
				self::assertSame(
					'resolved',
					$call['data']['state'] ?? null,
					'AC-5: state MUST be set to "resolved".'
				);
			}
		}

		/**
		 * AC-5: per_row map carries the literal "requeued" for every row.
		 */
		public function test_resend_failed_ops_returns_per_row_requeued_marker(): void
		{
			$wpdb = $this->makeWpdb();
			$wpdb->getRowResultById = array(
				10 => $this->withJsonPayload( array(
					'id'                  => '10',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '1',
					'payload'             => array( 'order_id' => 1 ),
					'state'               => 'unresolved',
				) ),
			);
			$wpdb->updateResult = 1;

			$repo   = $this->makeRepo( $wpdb );
			$coord  = new BulkResendCoordinator( $repo );
			$result = $coord->resendFailedOps( array( 10 ) );

			self::assertSame( 1, $result['queued'] );
			self::assertSame( 0, $result['skipped'] );
			self::assertSame( 'requeued', $result['per_row'][10] ?? null );
		}

		// ===================================================================
		// AC-6: missing row tolerance
		// ===================================================================

		/**
		 * AC-6: GIVEN failed_op_ids=[10,99]; findById(99) -> null
		 *       WHEN  resendFailedOps() runs
		 *       THEN  row 10 normal; row 99 -> per_row='row_missing', skipped++;
		 *             no throw.
		 */
		public function test_resend_failed_ops_skips_when_find_by_id_returns_null(): void
		{
			$wpdb = $this->makeWpdb();
			$wpdb->getRowResultById = array(
				10 => $this->withJsonPayload( array(
					'id'                  => '10',
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '5',
					'payload'             => array( 'article_id' => 5 ),
					'state'               => 'unresolved',
				) ),
				99 => null,
			);
			$wpdb->updateResult = 1;

			$repo   = $this->makeRepo( $wpdb );
			$coord  = new BulkResendCoordinator( $repo );
			$result = $coord->resendFailedOps( array( 10, 99 ) );

			self::assertSame( 1, $result['queued'], 'AC-6: only row 10 is queued.' );
			self::assertSame( 1, $result['skipped'], 'AC-6: row 99 counts toward skipped.' );
			self::assertSame( 'requeued', $result['per_row'][10] ?? null );
			self::assertSame( 'row_missing', $result['per_row'][99] ?? null );
			self::assertCount(
				1,
				$this->enqueuedActions,
				'AC-6: missing row MUST NOT trigger as_enqueue_async_action.'
			);
		}

		/**
		 * AC-6: method MUST NOT throw when findById returns null.
		 */
		public function test_resend_failed_ops_does_not_throw_on_missing_row(): void
		{
			$wpdb = $this->makeWpdb();
			$wpdb->getRowResultById = array( 99 => null );

			$repo  = $this->makeRepo( $wpdb );
			$coord = new BulkResendCoordinator( $repo );

			$result = $coord->resendFailedOps( array( 99 ) );
			self::assertIsArray( $result, 'AC-6: returns array, no throw.' );
			self::assertSame( 'row_missing', $result['per_row'][99] ?? null );
		}

		// ===================================================================
		// AC-7: dismissFailedOps aborts on `create_order` in selection
		// ===================================================================

		/**
		 * AC-7: GIVEN failed_op_ids=[10 (create_order), 11, 12]
		 *       WHEN  dismissFailedOps() runs
		 *       THEN  no markDismissed call; result {ok:false,
		 *             code:'create_order_in_selection', blocked_ids:[10]}.
		 */
		public function test_dismiss_failed_ops_aborts_with_blocked_ids_when_create_order_in_selection(): void
		{
			$wpdb = $this->makeWpdb();
			$wpdb->getRowResultById = array(
				10 => $this->withJsonPayload( array(
					'id'                  => '10',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '1',
					'payload'             => array( 'order_id' => 1 ),
					'state'               => 'unresolved',
				) ),
				11 => $this->withJsonPayload( array(
					'id'                  => '11',
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '500',
					'payload'             => array( 'article_id' => 500 ),
					'state'               => 'unresolved',
				) ),
				12 => $this->withJsonPayload( array(
					'id'                  => '12',
					'op_type'             => 'confirm_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '2',
					'payload'             => array( 'order_id' => 2 ),
					'state'               => 'unresolved',
				) ),
			);

			$repo   = $this->makeRepo( $wpdb );
			$coord  = new BulkResendCoordinator( $repo );
			$result = $coord->dismissFailedOps( array( 10, 11, 12 ) );

			self::assertFalse( $result['ok'], 'AC-7: ok MUST be false.' );
			self::assertSame(
				'create_order_in_selection',
				$result['code'] ?? null,
				'AC-7: code MUST be "create_order_in_selection".'
			);
			self::assertSame( 0, $result['dismissed'], 'AC-7: dismissed MUST be 0.' );
			self::assertSame(
				array( 10 ),
				$result['blocked_ids'] ?? null,
				'AC-7: blocked_ids MUST list the create_order rows.'
			);
			self::assertArrayHasKey( 'message', $result );
			self::assertNotSame( '', (string) $result['message'] );
		}

		/**
		 * AC-7: NO markDismissed UPDATE is issued when aborted.
		 */
		public function test_dismiss_failed_ops_does_not_call_mark_dismissed_when_aborted(): void
		{
			$wpdb = $this->makeWpdb();
			$wpdb->getRowResultById = array(
				10 => $this->withJsonPayload( array(
					'id'                  => '10',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '1',
					'payload'             => array( 'order_id' => 1 ),
					'state'               => 'unresolved',
				) ),
				11 => $this->withJsonPayload( array(
					'id'                  => '11',
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '500',
					'payload'             => array( 'article_id' => 500 ),
					'state'               => 'unresolved',
				) ),
			);

			$repo  = $this->makeRepo( $wpdb );
			$coord = new BulkResendCoordinator( $repo );
			$coord->dismissFailedOps( array( 10, 11 ) );

			self::assertSame(
				array(),
				$wpdb->updateCalls,
				'AC-7: NO UPDATE statement MUST run when bulk-dismiss is aborted.'
			);
		}

		/**
		 * AC-7: Plural-Form via _n() — at count>=2 the plural variant fires.
		 */
		public function test_dismiss_failed_ops_uses_plural_message_when_multiple_blocked(): void
		{
			$wpdb = $this->makeWpdb();
			$wpdb->getRowResultById = array(
				10 => $this->withJsonPayload( array(
					'id'                  => '10',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '1',
					'payload'             => array( 'order_id' => 1 ),
					'state'               => 'unresolved',
				) ),
				20 => $this->withJsonPayload( array(
					'id'                  => '20',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '2',
					'payload'             => array( 'order_id' => 2 ),
					'state'               => 'unresolved',
				) ),
			);

			$repo   = $this->makeRepo( $wpdb );
			$coord  = new BulkResendCoordinator( $repo );
			$result = $coord->dismissFailedOps( array( 10, 20 ) );

			self::assertCount( 2, $result['blocked_ids'] );
			self::assertStringContainsString(
				'entries',
				(string) ( $result['message'] ?? '' ),
				'AC-7: plural message variant MUST be used when count > 1.'
			);
		}

		// ===================================================================
		// AC-8: dismissFailedOps plain-only path
		// ===================================================================

		/**
		 * AC-8: GIVEN failed_op_ids=[11,12] — only sync_article and
		 *             confirm_order types
		 *       WHEN  dismissFailedOps() runs
		 *       THEN  markDismissed per row; result {ok:true, dismissed:2,
		 *             blocked_ids:[]}.
		 */
		public function test_dismiss_failed_ops_marks_each_non_create_order_row_dismissed(): void
		{
			$wpdb = $this->makeWpdb();
			$wpdb->getRowResultById = array(
				11 => $this->withJsonPayload( array(
					'id'                  => '11',
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '500',
					'payload'             => array( 'article_id' => 500 ),
					'state'               => 'unresolved',
				) ),
				12 => $this->withJsonPayload( array(
					'id'                  => '12',
					'op_type'             => 'confirm_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '2',
					'payload'             => array( 'order_id' => 2 ),
					'state'               => 'unresolved',
				) ),
			);
			$wpdb->updateResult = 1;

			$repo   = $this->makeRepo( $wpdb );
			$coord  = new BulkResendCoordinator( $repo );
			$result = $coord->dismissFailedOps( array( 11, 12 ) );

			self::assertTrue( $result['ok'], 'AC-8: ok MUST be true.' );
			self::assertSame( 2, $result['dismissed'], 'AC-8: dismissed=2.' );
			self::assertSame( array(), $result['blocked_ids'], 'AC-8: blocked_ids empty.' );

			self::assertCount( 2, $wpdb->updateCalls, 'AC-8: 2 UPDATE statements (one per row).' );
			foreach ( $wpdb->updateCalls as $call ) {
				self::assertSame( 'dismissed', $call['data']['state'] ?? null );
			}
		}

		// ===================================================================
		// AC-9: bulk-resend AJAX wiring
		// ===================================================================

		/**
		 * AC-9: invalid nonce -> 403; no AS-enqueue.
		 */
		public function test_bulk_resend_ajax_returns_403_on_invalid_nonce(): void
		{
			Functions\when( 'check_ajax_referer' )->justReturn( false );

			$repo  = $this->makeRepo();
			$coord = new BulkResendCoordinator( $repo );

			$_POST = array(
				'failed_op_ids' => array( 10, 11, 12 ),
				'_ajax_nonce'   => 'whatever',
			);

			$sentinel = $this->runHandler( $coord, 'handleBulkResendAjax' );

			self::assertNotNull( $sentinel );
			self::assertFalse( $sentinel->success, 'AC-9: invalid nonce -> wp_send_json_error.' );
			self::assertSame( 403, $sentinel->status, 'AC-9: invalid nonce -> 403.' );
			self::assertSame( array(), $this->enqueuedActions );
		}

		/**
		 * AC-9: missing capability -> 403; no AS-enqueue.
		 */
		public function test_bulk_resend_ajax_returns_403_when_cap_missing(): void
		{
			Functions\when( 'current_user_can' )->justReturn( false );

			$repo  = $this->makeRepo();
			$coord = new BulkResendCoordinator( $repo );

			$_POST = array(
				'failed_op_ids' => array( 10 ),
				'_ajax_nonce'   => 'whatever',
			);

			$sentinel = $this->runHandler( $coord, 'handleBulkResendAjax' );

			self::assertNotNull( $sentinel );
			self::assertFalse( $sentinel->success );
			self::assertSame( 403, $sentinel->status );
			self::assertSame( array(), $this->enqueuedActions );
		}

		/**
		 * AC-9: empty/missing failed_op_ids -> 400.
		 */
		public function test_bulk_resend_ajax_returns_400_on_empty_failed_op_ids(): void
		{
			$repo  = $this->makeRepo();
			$coord = new BulkResendCoordinator( $repo );

			$_POST = array(
				'failed_op_ids' => array(),
				'_ajax_nonce'   => 'whatever',
			);

			$sentinel = $this->runHandler( $coord, 'handleBulkResendAjax' );

			self::assertNotNull( $sentinel );
			self::assertFalse( $sentinel->success, 'AC-9: empty ids -> error.' );
			self::assertSame( 400, $sentinel->status, 'AC-9: empty ids -> 400.' );
		}

		/**
		 * AC-9: happy path -> delegates to resendFailedOps + returns banner.
		 */
		public function test_bulk_resend_ajax_delegates_to_coordinator_and_returns_banner_string(): void
		{
			$wpdb = $this->makeWpdb();
			$wpdb->getRowResultById = array(
				10 => $this->withJsonPayload( array(
					'id'                  => '10',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '1',
					'payload'             => array( 'order_id' => 1 ),
					'state'               => 'unresolved',
				) ),
			);
			$wpdb->updateResult = 1;

			$repo  = $this->makeRepo( $wpdb );
			$coord = new BulkResendCoordinator( $repo );

			$_POST = array(
				'failed_op_ids' => array( 10 ),
				'_ajax_nonce'   => 'whatever',
			);

			$sentinel = $this->runHandler( $coord, 'handleBulkResendAjax' );

			self::assertNotNull( $sentinel );
			self::assertTrue( $sentinel->success, 'AC-9: success path -> wp_send_json_success.' );
			self::assertArrayHasKey( 'banner', $sentinel->payload, 'AC-9: response carries banner string.' );
			self::assertStringContainsString(
				're-queued',
				(string) $sentinel->payload['banner'],
				'AC-9: banner MUST contain "re-queued".'
			);
			self::assertCount( 1, $this->enqueuedActions, 'AC-9: AS schedule fires.' );
		}

		// ===================================================================
		// AC-10: bulk-dismiss AJAX wiring
		// ===================================================================

		/**
		 * AC-10: dismissFailedOps with create_order in selection -> 422 with
		 * code/blocked_ids/message in JSON-error payload.
		 */
		public function test_bulk_dismiss_ajax_returns_422_on_create_order_in_selection(): void
		{
			$wpdb = $this->makeWpdb();
			$wpdb->getRowResultById = array(
				10 => $this->withJsonPayload( array(
					'id'                  => '10',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '1',
					'payload'             => array( 'order_id' => 1 ),
					'state'               => 'unresolved',
				) ),
				11 => $this->withJsonPayload( array(
					'id'                  => '11',
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '500',
					'payload'             => array( 'article_id' => 500 ),
					'state'               => 'unresolved',
				) ),
			);

			$repo  = $this->makeRepo( $wpdb );
			$coord = new BulkResendCoordinator( $repo );

			$_POST = array(
				'failed_op_ids' => array( 10, 11 ),
				'_ajax_nonce'   => 'whatever',
			);

			$sentinel = $this->runHandler( $coord, 'handleBulkDismissAjax' );

			self::assertNotNull( $sentinel );
			self::assertFalse( $sentinel->success, 'AC-10: blocked path -> wp_send_json_error.' );
			self::assertSame( 422, $sentinel->status, 'AC-10: status MUST be 422.' );
			self::assertSame(
				'create_order_in_selection',
				$sentinel->payload['code'] ?? null,
				'AC-10: code propagated.'
			);
			self::assertSame(
				array( 10 ),
				$sentinel->payload['blocked_ids'] ?? null,
				'AC-10: blocked_ids propagated.'
			);
			self::assertArrayHasKey( 'message', $sentinel->payload );
		}

		/**
		 * AC-10: success path (no create_order) -> wp_send_json_success
		 * with dismissed count + empty blocked_ids.
		 */
		public function test_bulk_dismiss_ajax_returns_success_when_no_create_order(): void
		{
			$wpdb = $this->makeWpdb();
			$wpdb->getRowResultById = array(
				11 => $this->withJsonPayload( array(
					'id'                  => '11',
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '500',
					'payload'             => array( 'article_id' => 500 ),
					'state'               => 'unresolved',
				) ),
				12 => $this->withJsonPayload( array(
					'id'                  => '12',
					'op_type'             => 'confirm_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '2',
					'payload'             => array( 'order_id' => 2 ),
					'state'               => 'unresolved',
				) ),
			);
			$wpdb->updateResult = 1;

			$repo  = $this->makeRepo( $wpdb );
			$coord = new BulkResendCoordinator( $repo );

			$_POST = array(
				'failed_op_ids' => array( 11, 12 ),
				'_ajax_nonce'   => 'whatever',
			);

			$sentinel = $this->runHandler( $coord, 'handleBulkDismissAjax' );

			self::assertNotNull( $sentinel );
			self::assertTrue( $sentinel->success, 'AC-10: success path -> wp_send_json_success.' );
			self::assertSame( 2, $sentinel->payload['dismissed'] ?? null );
			self::assertSame( array(), $sentinel->payload['blocked_ids'] ?? null );
		}

		// ===================================================================
		// AC-11: View bulk-UI markup
		// ===================================================================

		/**
		 * AC-11: render() emits a <select name="bulk_action"> with the two
		 * documented options.
		 */
		public function test_render_emits_bulk_action_select_with_two_options(): void
		{
			$rows = array(
				array(
					'id'                  => '10',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '1',
					'payload'             => array( 'order_id' => 1 ),
					'error_message'       => 'boom',
					'error_code'          => 'http_4xx',
					'retries_used'        => '0',
					'created_at'          => '2026-05-04 11:00:00',
					'last_attempt_at'     => '2026-05-04 11:00:00',
					'state'               => 'unresolved',
				),
			);

			$out = $this->captureViewOutput( $rows );

			self::assertMatchesRegularExpression(
				'/<select[^>]*name="bulk_action"/',
				$out,
				'AC-11: <select name="bulk_action"> MUST be present.'
			);
			self::assertMatchesRegularExpression(
				'/<option[^>]*value="spreadconnect_bulk_resend_failed_op"/',
				$out,
				'AC-11: option for bulk-resend MUST exist.'
			);
			self::assertMatchesRegularExpression(
				'/<option[^>]*value="spreadconnect_bulk_dismiss_failed_op"/',
				$out,
				'AC-11: option for bulk-dismiss MUST exist.'
			);
			self::assertMatchesRegularExpression(
				'/<button[^>]*data-action="bulk-apply"/',
				$out,
				'AC-11: Apply button with data-action="bulk-apply" MUST exist.'
			);
		}

		/**
		 * AC-11: render() emits checkbox per row + select-all checkbox.
		 */
		public function test_render_emits_per_row_checkbox_and_select_all(): void
		{
			$rows = array(
				array(
					'id'                  => '42',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '7',
					'payload'             => array( 'order_id' => 7 ),
					'error_message'       => 'boom',
					'error_code'          => 'http_4xx',
					'retries_used'        => '0',
					'created_at'          => '2026-05-04 11:00:00',
					'last_attempt_at'     => '2026-05-04 11:00:00',
					'state'               => 'unresolved',
				),
				array(
					'id'                  => '88',
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '13',
					'payload'             => array( 'article_id' => 13 ),
					'error_message'       => 'boom',
					'error_code'          => 'http_5xx',
					'retries_used'        => '3',
					'created_at'          => '2026-05-04 11:30:00',
					'last_attempt_at'     => '2026-05-04 11:30:00',
					'state'               => 'unresolved',
				),
			);

			$out = $this->captureViewOutput( $rows );

			self::assertMatchesRegularExpression(
				'/<input[^>]*data-bulk-row-id="42"/',
				$out,
				'AC-11: per-row checkbox for id=42 MUST exist.'
			);
			self::assertMatchesRegularExpression(
				'/<input[^>]*data-bulk-row-id="88"/',
				$out,
				'AC-11: per-row checkbox for id=88 MUST exist.'
			);
			self::assertMatchesRegularExpression(
				'/<input[^>]*data-bulk-select-all/',
				$out,
				'AC-11: select-all checkbox MUST exist in header.'
			);
		}

		/**
		 * AC-11: render() emits the bulk_outcome panel container hidden
		 * by default.
		 */
		public function test_render_emits_bulk_outcome_panel_container(): void
		{
			$rows = array(
				array(
					'id'                  => '42',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '7',
					'payload'             => array( 'order_id' => 7 ),
					'error_message'       => 'boom',
					'error_code'          => 'http_4xx',
					'retries_used'        => '0',
					'created_at'          => '2026-05-04 11:00:00',
					'last_attempt_at'     => '2026-05-04 11:00:00',
					'state'               => 'unresolved',
				),
			);

			$out = $this->captureViewOutput( $rows );

			self::assertMatchesRegularExpression(
				'/<div[^>]*data-panel="bulk_outcome"[^>]*hidden/',
				$out,
				'AC-11: <div data-panel="bulk_outcome" hidden> container MUST exist.'
			);
		}

		/**
		 * AC-11: render() MUST NOT emit any inline `onclick` handler.
		 */
		public function test_render_uses_no_inline_onclick_for_bulk_controls(): void
		{
			$rows = array(
				array(
					'id'                  => '10',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '1',
					'payload'             => array( 'order_id' => 1 ),
					'error_message'       => 'boom',
					'error_code'          => 'http_4xx',
					'retries_used'        => '0',
					'created_at'          => '2026-05-04 11:00:00',
					'last_attempt_at'     => '2026-05-04 11:00:00',
					'state'               => 'unresolved',
				),
			);

			$out = $this->captureViewOutput( $rows );

			self::assertDoesNotMatchRegularExpression(
				'/onclick\s*=/',
				$out,
				'AC-11: NO inline onclick handlers permitted.'
			);
		}

		// ===================================================================
		// AC-12: localized data + dedicated asset handle
		// ===================================================================

		/**
		 * AC-12: render() localizes the bulk asset with the documented keys.
		 */
		public function test_render_localizes_bulk_actions_and_i18n_keys(): void
		{
			$this->captureViewOutput( array() );

			$found = null;
			foreach ( $this->localizeCalls as $call ) {
				if ( 'spreadconnectFailedOpsBulk' === $call[1] ) {
					$found = $call;
					break;
				}
			}

			self::assertNotNull(
				$found,
				'AC-12: wp_localize_script MUST be called with object name "spreadconnectFailedOpsBulk".'
			);

			$payload = $found[2];
			self::assertIsArray( $payload );
			self::assertArrayHasKey( 'ajaxUrl', $payload );
			self::assertArrayHasKey( 'nonce', $payload );
			self::assertArrayHasKey( 'actions', $payload );
			self::assertArrayHasKey( 'i18n', $payload );

			self::assertSame(
				'spreadconnect_bulk_resend_failed_op',
				$payload['actions']['bulkResend'] ?? null,
				'AC-12: actions.bulkResend MUST be the bulk-resend action literal.'
			);
			self::assertSame(
				'spreadconnect_bulk_dismiss_failed_op',
				$payload['actions']['bulkDismiss'] ?? null,
				'AC-12: actions.bulkDismiss MUST be the bulk-dismiss action literal.'
			);

			$i18n = $payload['i18n'];
			self::assertArrayHasKey( 'bannerSuccessTpl', $i18n );
			self::assertArrayHasKey( 'bannerBlockedTpl', $i18n );
			self::assertArrayHasKey( 'noSelection', $i18n );
			self::assertArrayHasKey( 'confirmDismiss', $i18n );

			self::assertStringContainsString(
				're-queued',
				(string) $i18n['bannerSuccessTpl'],
				'AC-12: bannerSuccessTpl MUST contain "re-queued".'
			);
		}

		/**
		 * AC-12: nonce MUST reuse the slice-38 nonce action
		 * `spreadconnect_failed_ops`.
		 */
		public function test_render_reuses_failed_ops_nonce_action(): void
		{
			$this->captureViewOutput( array() );

			$found = null;
			foreach ( $this->localizeCalls as $call ) {
				if ( 'spreadconnectFailedOpsBulk' === $call[1] ) {
					$found = $call;
					break;
				}
			}
			self::assertNotNull( $found );
			$payload = $found[2];

			self::assertSame(
				'NONCE_spreadconnect_failed_ops',
				(string) $payload['nonce'],
				'AC-12: nonce action MUST be reused from slice-38.'
			);
		}

		/**
		 * AC-12: bulk asset handle MUST be `spreadconnect-failed-ops-bulk`
		 * (distinct from slice-38 `spreadconnect-failed-ops`) and registered
		 * with jquery dependency.
		 */
		public function test_render_registers_bulk_asset_with_unique_handle_and_jquery_dep(): void
		{
			$this->captureViewOutput( array() );

			$registered = null;
			foreach ( $this->registerScriptCalls as $call ) {
				if ( 'spreadconnect-failed-ops-bulk' === $call[0] ) {
					$registered = $call;
					break;
				}
			}
			self::assertNotNull(
				$registered,
				'AC-12: bulk asset handle "spreadconnect-failed-ops-bulk" MUST be registered.'
			);
			self::assertContains(
				'jquery',
				$registered[2],
				'AC-12: bulk asset MUST depend on jquery.'
			);
			self::assertContains(
				'spreadconnect-failed-ops-bulk',
				$this->enqueueScriptCalls,
				'AC-12: bulk asset MUST be enqueued.'
			);
		}

		// ===================================================================
		// AC-13 / AC-14: JS asset markup-class assertions (Markup-Inspection)
		// ===================================================================

		/**
		 * AC-13: bulk-asset JS contains the blocked-state CSS class so the
		 * client renders a `notice notice-error spreadconnect-bulk-blocked`
		 * banner on `create_order_in_selection`.
		 */
		public function test_bulk_outcome_container_supports_blocked_state_class(): void
		{
			$path = self::bulkAssetPath();
			self::assertFileExists( $path, 'AC-13: bulk JS asset MUST be shipped.' );

			$src = (string) file_get_contents( $path );

			self::assertStringContainsString(
				'spreadconnect-bulk-blocked',
				$src,
				'AC-13: JS MUST reference the blocked-banner CSS class.'
			);
			self::assertStringContainsString(
				'notice-error',
				$src,
				'AC-13: JS MUST emit a notice-error banner for the blocked path.'
			);
			self::assertStringContainsString(
				'spreadconnect-row-blocked',
				$src,
				'AC-13: JS MUST highlight blocked rows via spreadconnect-row-blocked.'
			);
		}

		/**
		 * AC-14: bulk-asset JS contains the success-state CSS class
		 * `spreadconnect-bulk-outcome` so the client renders the
		 * "X of Y re-queued, Z skipped" banner.
		 */
		public function test_bulk_outcome_container_supports_success_state_class(): void
		{
			$path = self::bulkAssetPath();
			self::assertFileExists( $path );

			$src = (string) file_get_contents( $path );

			self::assertStringContainsString(
				'spreadconnect-bulk-outcome',
				$src,
				'AC-14: JS MUST reference the success-banner CSS class.'
			);
			self::assertStringContainsString(
				'notice-success',
				$src,
				'AC-14: JS MUST emit a notice-success banner for the resend path.'
			);
			self::assertMatchesRegularExpression(
				'/requeued/',
				$src,
				'AC-14: JS MUST handle the "requeued" per_row marker for DOM-removal.'
			);
		}

		/**
		 * AC-12 / AC-13 / AC-14: physical asset existence at the documented
		 * path.
		 */
		public function test_bulk_asset_file_exists_at_expected_path(): void
		{
			self::assertFileExists(
				self::bulkAssetPath(),
				'AC-12: failed-ops-bulk.js MUST be shipped at assets/js/.'
			);
		}

		// ===================================================================
		// AC-15: Plugin::init() hook wiring + idempotency + ctor compat
		// ===================================================================

		/**
		 * AC-15: GIVEN Plugin-Boot
		 *        WHEN  Plugin::init() runs
		 *        THEN  add_action() registers BOTH new bulk-ajax hooks.
		 */
		public function test_plugin_init_registers_bulk_resend_and_dismiss_ajax_hooks(): void
		{
			self::assertFalse(
				Actions\has( 'wp_ajax_spreadconnect_bulk_resend_failed_op' ),
				'AC-15 (precondition): no bulk-resend listener before init().'
			);
			self::assertFalse(
				Actions\has( 'wp_ajax_spreadconnect_bulk_dismiss_failed_op' ),
				'AC-15 (precondition): no bulk-dismiss listener before init().'
			);

			Plugin::init( self::pluginMainFile() );

			self::assertNotFalse(
				Actions\has( 'wp_ajax_spreadconnect_bulk_resend_failed_op' ),
				'AC-15: init() MUST register the bulk-resend ajax hook.'
			);
			self::assertNotFalse(
				Actions\has( 'wp_ajax_spreadconnect_bulk_dismiss_failed_op' ),
				'AC-15: init() MUST register the bulk-dismiss ajax hook.'
			);
		}

		/**
		 * AC-15: idempotent — double init() does not register hooks twice.
		 */
		public function test_plugin_init_bulk_hooks_are_idempotent(): void
		{
			Plugin::init( self::pluginMainFile() );
			$priorityFirstResend  = Actions\has( 'wp_ajax_spreadconnect_bulk_resend_failed_op' );
			$priorityFirstDismiss = Actions\has( 'wp_ajax_spreadconnect_bulk_dismiss_failed_op' );

			Plugin::init( self::pluginMainFile() );
			$prioritySecondResend  = Actions\has( 'wp_ajax_spreadconnect_bulk_resend_failed_op' );
			$prioritySecondDismiss = Actions\has( 'wp_ajax_spreadconnect_bulk_dismiss_failed_op' );

			self::assertSame(
				$priorityFirstResend,
				$prioritySecondResend,
				'AC-15: double init() MUST NOT change bulk-resend hook priority.'
			);
			self::assertSame(
				$priorityFirstDismiss,
				$prioritySecondDismiss,
				'AC-15: double init() MUST NOT change bulk-dismiss hook priority.'
			);
		}

		/**
		 * AC-15: BulkResendCoordinator constructor MUST accept the optional
		 * `?FailedOpsRepo` + `?\WC_Logger` (slice-33 backward-compat).
		 */
		public function test_coordinator_constructor_accepts_repo_and_logger_optionally(): void
		{
			// Slice-33 backward-compat: zero-arg ctor.
			$coord1 = new BulkResendCoordinator();
			self::assertInstanceOf(
				BulkResendCoordinator::class,
				$coord1,
				'AC-15: zero-arg ctor MUST stay valid (slice-33 compat).'
			);

			// Slice-40 production wiring: repo-only.
			$coord2 = new BulkResendCoordinator( $this->makeRepo() );
			self::assertInstanceOf( BulkResendCoordinator::class, $coord2 );

			// Slice-40 production wiring: repo + logger.
			$logger = Mockery::mock( \WC_Logger::class );
			$coord3 = new BulkResendCoordinator( $this->makeRepo(), $logger );
			self::assertInstanceOf( BulkResendCoordinator::class, $coord3 );
		}

		/**
		 * AC-15: signature reflection — constructor parameters are nullable
		 * with default null values.
		 */
		public function test_coordinator_constructor_signature_has_nullable_defaults(): void
		{
			$ref    = new \ReflectionClass( BulkResendCoordinator::class );
			$ctor   = $ref->getConstructor();
			self::assertNotNull( $ctor );
			$params = $ctor->getParameters();

			self::assertCount( 2, $params, 'AC-15: ctor MUST have two parameters.' );

			foreach ( $params as $p ) {
				self::assertTrue(
					$p->allowsNull(),
					"AC-15: ctor parameter \${$p->getName()} MUST allow null."
				);
				self::assertTrue(
					$p->isOptional(),
					"AC-15: ctor parameter \${$p->getName()} MUST be optional."
				);
				self::assertTrue(
					$p->isDefaultValueAvailable(),
					"AC-15: ctor parameter \${$p->getName()} MUST have a default value."
				);
				self::assertNull(
					$p->getDefaultValue(),
					"AC-15: ctor parameter \${$p->getName()} default MUST be null."
				);
			}
		}
	}
}
