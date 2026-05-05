<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 38 — Failed-Ops-UI + Dismiss-Resolution-Modal
//
// Spec: specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/
//       slices/slice-38-failed-ops-ui.md
//
// Mocking strategy per slice-38 spec (`mock_external`):
//   - Brain\Monkey aliased fuer WP-AJAX/Cap/Nonce/JSON-Response Helpers
//     (`check_ajax_referer`, `current_user_can`, `wp_send_json_success`,
//     `wp_send_json_error`, `as_enqueue_async_action`, `wc_get_order`).
//   - Brain\Monkey aliased fuer WP-View-Helpers (`wp_register_script`,
//     `wp_enqueue_script`, `wp_localize_script`, `admin_url`, `plugins_url`,
//     `wp_create_nonce`, `wp_unslash`, `sanitize_text_field`, `absint`,
//     `__`, `esc_html__`, `esc_html`, `esc_attr`, `wp_json_encode`).
//   - `wp_send_json_*` werfen `Slice38JsonSentinel` (Payload + Status), damit
//     der PHPUnit-Prozess nicht via `die()` terminiert.
//   - `FailedOpsRepo` als REALE Instanz gegen einen `Slice38FakeWpdb`-Stub
//     (gleicher Pattern wie Slice37). Konstruktor-DI via FailedOpsActions.
//   - `wc_get_order(...)` via Functions\when() — gibt entweder Mockery-
//     `WC_Order`-Mock oder `false` zurueck.
//   - `WC_Order` via Mockery::mock-Subclass mit configurable
//     `update_status` / `update_meta_data` / `save` / `add_order_note`.
//
// Test-Konvention:
//   `tests/slices/pod-shop-mvp/Slice38FailedOpsUiTest.php` mit
//   `final class Slice38FailedOpsUiTest extends TestCase`.
//
// Each test maps 1:1 to a spec acceptance criterion via the docblock
// GIVEN/WHEN/THEN. AC-1..AC-3 cover the View; AC-4..AC-13 cover the AJAX
// front-controller; AC-14..AC-15 cover the JS-driven modal markup
// (Markup-Inspection per Test-Skeleton).
// ---------------------------------------------------------------------------

namespace {

	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Mockery;
	use PHPUnit\Framework\TestCase;
	use SpreadconnectPod\Failure\FailedOpsRepo;
	use SpreadconnectPod\Hub\Ajax\FailedOpsActions;
	use SpreadconnectPod\Hub\View\FailedOps;

	/**
	 * Sentinel exception used to interrupt control flow when
	 * `wp_send_json_*` is invoked. WP terminates the request via `die()`
	 * after writing the JSON response — in the test environment we cannot
	 * afford to terminate the PHPUnit process, so the stubs throw
	 * `Slice38JsonSentinel` carrying the payload + status; the test catches
	 * it and inspects the captured value.
	 */
	final class Slice38JsonSentinel extends \RuntimeException
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
	 * Recording `wpdb` double for slice-38 — captures `get_row` /
	 * `get_results` calls through `prepare()` and lets each test pre-program
	 * the return values. Mirrors the slice-37 `Slice37FakeWpdb` shape.
	 */
	final class Slice38FakeWpdb extends \wpdb
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
	 * Slice 38 acceptance + integration tests — Failed-Ops UI + AJAX flows.
	 *
	 * AC-1..AC-3 — View (`Hub\View\FailedOps::render()`) — output buffer
	 * inspection against the rendered HTML.
	 *
	 * AC-4..AC-13 — AJAX front-controller (`Hub\Ajax\FailedOpsActions`) —
	 * invokes the three handlers (`resend()`, `dismiss()`, `resolve()`)
	 * with a `Slice38FakeWpdb`-backed `FailedOpsRepo`; asserts on the
	 * captured `Slice38JsonSentinel` payload and on side-effects
	 * (`as_enqueue_async_action`, `WC_Order::update_status` / `update_meta_data`,
	 * `markResolved` / `markDismissed`).
	 *
	 * AC-14..AC-15 — Modal markup verified via the View output (modal
	 * container + 3 radios + hidden external-id input + dismiss button
	 * `data-needs-resolution` attributes). Asset existence-check confirms
	 * `failed-ops-modal.js` is shipped at the expected path.
	 */
	final class Slice38FailedOpsUiTest extends TestCase
	{
		/**
		 * Captured `as_enqueue_async_action` invocations as `[hook, args, group]`.
		 *
		 * @var list<array{0:string,1:array<string,mixed>,2:string}>
		 */
		public array $enqueuedActions = array();

		/**
		 * Captured `wp_localize_script` invocations as `[handle, object_name, payload]`.
		 *
		 * @var list<array{0:string,1:string,2:mixed}>
		 */
		public array $localizeCalls = array();

		/**
		 * Captured `wp_register_script` invocations as `[handle, src, deps, ver, in_footer]`.
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

		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->enqueuedActions    = array();
			$this->localizeCalls      = array();
			$this->registerScriptCalls = array();
			$this->enqueueScriptCalls = array();

			// ---- i18n + escape helpers (identity stubs) -------------------
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'esc_attr__' )->returnArg( 1 );
			Functions\when( 'esc_html' )->returnArg( 1 );
			Functions\when( 'esc_attr' )->returnArg( 1 );
			Functions\when( 'esc_url' )->returnArg( 1 );

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
					throw new Slice38JsonSentinel( true, (array) $data, $status_code );
				}
			);
			Functions\when( 'wp_send_json_error' )->alias(
				static function ( $data = null, $status_code = null ): void {
					throw new Slice38JsonSentinel( false, (array) $data, $status_code );
				}
			);

			// ---- as_enqueue_async_action capture --------------------------
			Functions\when( 'as_enqueue_async_action' )->alias(
				static function ( string $hook, array $args = array(), string $group = '' ) use ( $self ): int {
					$self->enqueuedActions[] = array( $hook, $args, $group );
					return count( $self->enqueuedActions );
				}
			);

			// ---- WC logger no-op -----------------------------------------
			Functions\when( 'wc_get_logger' )->alias(
				static fn() => new class {
					public function info( string $m, array $c = array() ): void {}
					public function warning( string $m, array $c = array() ): void {}
					public function error( string $m, array $c = array() ): void {}
					public function debug( string $m, array $c = array() ): void {}
					public function log( string $level, string $m, array $c = array() ): void {}
				}
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
		}

		protected function tearDown(): void
		{
			$_POST = array();
			$_GET  = array();
			unset( $GLOBALS['wpdb'] );
			Mockery::close();
			Monkey\tearDown();
			parent::tearDown();
		}

		// ===================================================================
		// Helpers
		// ===================================================================

		private function makeWpdb(): Slice38FakeWpdb
		{
			$wpdb         = new Slice38FakeWpdb();
			$wpdb->prefix = 'wp_';
			return $wpdb;
		}

		private function makeRepo( ?Slice38FakeWpdb $wpdb = null ): FailedOpsRepo
		{
			$wpdb = $wpdb ?? $this->makeWpdb();
			return new FailedOpsRepo( $wpdb );
		}

		/**
		 * Build a Mockery double for `\WC_Order` with all relevant accessors
		 * stubbed out. Tests can further overload via `->shouldReceive(...)`.
		 */
		private function makeWcOrderMock(): \Mockery\MockInterface
		{
			$mock = Mockery::mock( \WC_Order::class );
			$mock->shouldReceive( 'get_id' )->andReturn( 7 )->byDefault();
			return $mock;
		}

		/**
		 * Capture the FailedOps view output. Provides a $wpdb double via
		 * GLOBALS so the View-internal `new FailedOpsRepo($wpdb)` resolves.
		 *
		 * @param list<array<string, mixed>> $rows  pre-hydrated rows (the
		 *                                          view calls findAll() once;
		 *                                          we install a tiny FakeWpdb
		 *                                          + repo subclass-shim by
		 *                                          stuffing the rows into
		 *                                          getResultsResult).
		 */
		private function captureViewOutput( array $rows ): string
		{
			$wpdb = $this->makeWpdb();

			// FailedOpsRepo::findAll() builds a SELECT and calls get_results().
			// Encode payload arrays to JSON strings so the repo's hydrate()
			// receives the canonical wire-shape (slice-37 AC-3).
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
		 * Run an AJAX handler method and capture the JsonSentinel payload.
		 *
		 * @return Slice38JsonSentinel|null Captured sentinel or null if the
		 *                                  handler returned without sending
		 *                                  a JSON response.
		 */
		private function runHandler( FailedOpsActions $actions, string $method ): ?Slice38JsonSentinel
		{
			try {
				$actions->{$method}();
			} catch ( Slice38JsonSentinel $e ) {
				return $e;
			}
			return null;
		}

		// ===================================================================
		// AC-1: View renders one row per unresolved op + empty-state.
		// ===================================================================

		/**
		 * AC-1: GIVEN findAll([state=unresolved], 50, 0) returns N rows
		 *       WHEN  Hub\View\FailedOps::render() runs
		 *       THEN  one <tr data-row-id data-op-type> per row + columns
		 *             Failed-At / Op-Type / Entity / Error (truncated) /
		 *             Retries / Actions.
		 */
		public function test_render_emits_table_row_per_unresolved_failed_op(): void
		{
			$rows = array(
				array(
					'id'                  => '42',
					'op_type'             => 'create_order',
					'related_entity_type' => 'order',
					'related_entity_id'   => '7',
					'payload'             => array( 'order_id' => 7 ),
					'error_message'       => 'HTTP 400 invalid SKU',
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
					'error_message'       => 'Timeout while syncing',
					'error_code'          => 'http_5xx',
					'retries_used'        => '3',
					'created_at'          => '2026-05-04 11:30:00',
					'last_attempt_at'     => '2026-05-04 11:30:00',
					'state'               => 'unresolved',
				),
			);

			$out = $this->captureViewOutput( $rows );

			self::assertStringContainsString(
				'data-row-id="42"',
				$out,
				'AC-1: row 42 MUST emit data-row-id attribute.'
			);
			self::assertStringContainsString(
				'data-row-id="88"',
				$out,
				'AC-1: row 88 MUST emit data-row-id attribute.'
			);
			self::assertStringContainsString(
				'data-op-type="create_order"',
				$out,
				'AC-1: row 42 MUST carry data-op-type="create_order".'
			);
			self::assertStringContainsString(
				'data-op-type="sync_article"',
				$out,
				'AC-1: row 88 MUST carry data-op-type="sync_article".'
			);
			self::assertMatchesRegularExpression(
				'/<table[^>]*spreadconnect-failed-ops__table/',
				$out,
				'AC-1: a <table class="…spreadconnect-failed-ops__table…"> MUST be rendered.'
			);
		}

		/**
		 * AC-1: GIVEN findAll() returns []
		 *       WHEN  render() runs
		 *       THEN  empty-state panel "No failed operations …"
		 */
		public function test_render_shows_empty_state_when_repo_returns_no_rows(): void
		{
			$out = $this->captureViewOutput( array() );

			self::assertStringContainsString(
				'No failed operations',
				$out,
				'AC-1: Empty state MUST contain literal "No failed operations".'
			);
			self::assertStringNotContainsString(
				'data-row-id=',
				$out,
				'AC-1: Empty state MUST NOT emit any data-row-id attributes.'
			);
		}

		/**
		 * AC-1: error message column is truncated to 80 chars.
		 */
		public function test_render_truncates_error_message_to_80_chars(): void
		{
			$longError = str_repeat( 'x', 200 );
			$rows      = array(
				array(
					'id'                  => '7',
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '1',
					'payload'             => array(),
					'error_message'       => $longError,
					'error_code'          => 'http_5xx',
					'retries_used'        => '2',
					'created_at'          => '2026-05-04 11:00:00',
					'last_attempt_at'     => '2026-05-04 11:00:00',
					'state'               => 'unresolved',
				),
			);

			$out = $this->captureViewOutput( $rows );

			// The full 200-char error MUST NOT appear in the rendered output
			// (only inside title="…" attribute may carry the full string —
			// check the visible cell-content shorter run).
			self::assertDoesNotMatchRegularExpression(
				'/<td[^>]*spreadconnect-failed-ops__cell-error[^>]*>x{200}/',
				$out,
				'AC-1: error column body MUST NOT contain the full 200-char message — must be truncated.'
			);

			// And the truncated body MUST contain at most 80 x's followed by
			// the ellipsis. We assert a positive: the visible body has the
			// ellipsis marker.
			self::assertStringContainsString(
				'…',
				$out,
				'AC-1: truncated error message MUST end with an ellipsis marker.'
			);
		}

		// ===================================================================
		// AC-2: Action buttons are op-type-aware.
		// ===================================================================

		/**
		 * AC-2: create_order row -> dismiss-button has data-needs-resolution="1".
		 */
		public function test_render_marks_create_order_row_with_needs_resolution_attr(): void
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
				'/<button[^>]*data-action="dismiss"[^>]*data-needs-resolution="1"/',
				$out,
				'AC-2: create_order Dismiss button MUST carry data-needs-resolution="1".'
			);
			self::assertMatchesRegularExpression(
				'/<button[^>]*data-action="resend"/',
				$out,
				'AC-2: every row MUST carry a Resend button with data-action="resend".'
			);
			self::assertMatchesRegularExpression(
				'/data-action="detail"/',
				$out,
				'AC-2: every row MUST carry a View Detail control with data-action="detail".'
			);
		}

		/**
		 * AC-2: sync_article row -> dismiss-button has data-needs-resolution="0".
		 */
		public function test_render_marks_sync_article_row_without_needs_resolution(): void
		{
			$rows = array(
				array(
					'id'                  => '88',
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '13',
					'payload'             => array( 'article_id' => 13 ),
					'error_message'       => 'boom',
					'error_code'          => 'http_5xx',
					'retries_used'        => '3',
					'created_at'          => '2026-05-04 11:00:00',
					'last_attempt_at'     => '2026-05-04 11:00:00',
					'state'               => 'unresolved',
				),
			);

			$out = $this->captureViewOutput( $rows );

			self::assertMatchesRegularExpression(
				'/<button[^>]*data-action="dismiss"[^>]*data-needs-resolution="0"/',
				$out,
				'AC-2: sync_article Dismiss button MUST carry data-needs-resolution="0".'
			);
		}

		/**
		 * AC-2: render output MUST NOT contain inline `onclick` handlers.
		 */
		public function test_render_uses_no_inline_onclick_handlers(): void
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

			self::assertDoesNotMatchRegularExpression(
				'/onclick\s*=/',
				$out,
				'AC-2: NO inline onclick handlers — JS asset binds via event-delegation.'
			);
		}

		// ===================================================================
		// AC-3: JS asset is enqueued with localised payload (ajaxUrl + nonce).
		// ===================================================================

		/**
		 * AC-3: GIVEN render() runs
		 *       WHEN  the asset failed-ops-modal.js is enqueued
		 *       THEN  wp_localize_script(handle, 'spreadconnectFailedOps', [...])
		 *             with ajaxUrl + nonce + i18n keys.
		 */
		public function test_render_localizes_script_with_ajax_url_and_nonce(): void
		{
			$this->captureViewOutput( array() );

			self::assertNotEmpty(
				$this->localizeCalls,
				'AC-3: wp_localize_script MUST be invoked at least once during render().'
			);

			// Find the call for our object name.
			$found = null;
			foreach ( $this->localizeCalls as $call ) {
				if ( 'spreadconnectFailedOps' === $call[1] ) {
					$found = $call;
					break;
				}
			}

			self::assertNotNull(
				$found,
				'AC-3: wp_localize_script MUST be called with object name "spreadconnectFailedOps".'
			);

			$payload = $found[2];
			self::assertIsArray( $payload, 'AC-3: localized payload MUST be an array.' );
			self::assertArrayHasKey( 'ajaxUrl', $payload, 'AC-3: payload MUST carry ajaxUrl.' );
			self::assertArrayHasKey( 'nonce', $payload, 'AC-3: payload MUST carry nonce.' );
			self::assertArrayHasKey( 'i18n', $payload, 'AC-3: payload MUST carry i18n strings.' );

			self::assertStringContainsString(
				'admin-ajax.php',
				(string) $payload['ajaxUrl'],
				'AC-3: ajaxUrl MUST point at admin-ajax.php.'
			);
			self::assertSame(
				'NONCE_spreadconnect_failed_ops',
				(string) $payload['nonce'],
				'AC-3: nonce action MUST be "spreadconnect_failed_ops".'
			);

			$i18n = $payload['i18n'];
			self::assertIsArray( $i18n );
			self::assertArrayHasKey( 'confirmDismiss', $i18n );
			self::assertArrayHasKey( 'confirmResolution', $i18n );
			self::assertArrayHasKey( 'manualOrderIdRequired', $i18n );

			// Asset must be registered with jquery dependency.
			$registered = null;
			foreach ( $this->registerScriptCalls as $call ) {
				if ( 'spreadconnect-failed-ops' === $call[0] ) {
					$registered = $call;
					break;
				}
			}
			self::assertNotNull(
				$registered,
				'AC-3: wp_register_script MUST be invoked with handle "spreadconnect-failed-ops".'
			);
			self::assertContains(
				'jquery',
				$registered[2],
				'AC-3: dependency list MUST contain "jquery".'
			);

			self::assertContains(
				'spreadconnect-failed-ops',
				$this->enqueueScriptCalls,
				'AC-3: wp_enqueue_script MUST be called for the localized handle.'
			);
		}

		// ===================================================================
		// AC-4: resend() — happy path.
		// ===================================================================

		/**
		 * AC-4: GIVEN failed_op_id=42, op_type=create_order, payload={order_id:7}
		 *       WHEN  resend() runs
		 *       THEN  as_enqueue_async_action('spreadconnect/create_order',
		 *             ['order_id'=>7], 'spreadconnect') is called and the
		 *             row is marked resolved + JSON success.
		 */
		public function test_resend_enqueues_correct_action_for_op_type(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$wpdb->updateResult = 1;
			$repo               = $this->makeRepo( $wpdb );

			$_POST = array(
				'failed_op_id' => '42',
				'nonce'        => 'whatever',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'resend' );

			self::assertNotNull( $sentinel, 'AC-4: resend() MUST terminate via wp_send_json_*.' );
			self::assertTrue( $sentinel->success, 'AC-4: response MUST be wp_send_json_success.' );

			self::assertCount( 1, $this->enqueuedActions );
			$call = $this->enqueuedActions[0];
			self::assertSame(
				'spreadconnect/create_order',
				$call[0],
				'AC-4: hook MUST be the AS-mapping for create_order.'
			);
			self::assertSame(
				array( 'order_id' => 7 ),
				$call[1],
				'AC-4: payload MUST be forwarded as args.'
			);
			self::assertSame(
				'spreadconnect',
				$call[2],
				'AC-4: AS group MUST be "spreadconnect".'
			);
		}

		/**
		 * AC-4: after enqueue, the row is marked resolved.
		 */
		public function test_resend_marks_failed_op_resolved_after_enqueue(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'sync_article',
				'related_entity_type' => 'article',
				'related_entity_id'   => '13',
				'payload'             => json_encode( array( 'article_id' => 13 ) ),
				'state'               => 'unresolved',
			);
			$wpdb->updateResult = 1;
			$repo               = $this->makeRepo( $wpdb );

			$_POST = array(
				'failed_op_id' => '42',
				'nonce'        => 'whatever',
			);

			$actions = new FailedOpsActions( $repo );
			$this->runHandler( $actions, 'resend' );

			self::assertCount( 1, $wpdb->updateCalls, 'AC-4: exactly one UPDATE MUST run for markResolved.' );
			self::assertSame(
				'resolved',
				$wpdb->updateCalls[0]['data']['state'],
				'AC-4: markResolved MUST write state="resolved".'
			);
		}

		/**
		 * AC-4: response MUST carry failed_op_id=42 + i18n message.
		 */
		public function test_resend_returns_json_success_with_failed_op_id(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$wpdb->updateResult = 1;
			$repo               = $this->makeRepo( $wpdb );

			$_POST = array(
				'failed_op_id' => '42',
				'nonce'        => 'whatever',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'resend' );

			self::assertNotNull( $sentinel );
			self::assertTrue( $sentinel->success );
			self::assertArrayHasKey( 'failed_op_id', $sentinel->payload );
			self::assertSame( 42, $sentinel->payload['failed_op_id'] );
			self::assertArrayHasKey( 'message', $sentinel->payload );
		}

		// ===================================================================
		// AC-5: capability + nonce gates.
		// ===================================================================

		/**
		 * AC-5: invalid nonce -> 403 + no AS-enqueue, no findById.
		 */
		public function test_resend_returns_403_on_invalid_nonce(): void
		{
			Functions\when( 'check_ajax_referer' )->justReturn( false );

			$wpdb = $this->makeWpdb();
			$repo = $this->makeRepo( $wpdb );

			$_POST = array(
				'failed_op_id' => '42',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'resend' );

			self::assertNotNull( $sentinel );
			self::assertFalse( $sentinel->success, 'AC-5: invalid nonce -> wp_send_json_error.' );
			self::assertSame( 403, $sentinel->status, 'AC-5: invalid nonce -> 403.' );
			self::assertSame(
				array(),
				$this->enqueuedActions,
				'AC-5: no AS enqueue when nonce gate fails.'
			);
			self::assertSame(
				array(),
				$wpdb->getRowCalls,
				'AC-5: findById MUST NOT be called when nonce is invalid.'
			);
		}

		/**
		 * AC-5: missing capability -> 403 + no AS-enqueue.
		 */
		public function test_resend_returns_403_when_capability_missing(): void
		{
			Functions\when( 'current_user_can' )->justReturn( false );

			$wpdb = $this->makeWpdb();
			$repo = $this->makeRepo( $wpdb );

			$_POST = array(
				'failed_op_id' => '42',
				'nonce'        => 'whatever',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'resend' );

			self::assertNotNull( $sentinel );
			self::assertFalse( $sentinel->success );
			self::assertSame( 403, $sentinel->status );
			self::assertSame( array(), $this->enqueuedActions );
		}

		// ===================================================================
		// AC-6: dismiss() — plain dismiss for non-create_order.
		// ===================================================================

		/**
		 * AC-6: GIVEN op_type=sync_article + valid nonce/cap
		 *       WHEN  dismiss() runs
		 *       THEN  markDismissed(88) + JSON success.
		 */
		public function test_dismiss_marks_sync_article_row_dismissed(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '88',
				'op_type'             => 'sync_article',
				'related_entity_type' => 'article',
				'related_entity_id'   => '13',
				'payload'             => json_encode( array( 'article_id' => 13 ) ),
				'state'               => 'unresolved',
			);
			$wpdb->updateResult = 1;
			$repo               = $this->makeRepo( $wpdb );

			$_POST = array(
				'failed_op_id' => '88',
				'nonce'        => 'whatever',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'dismiss' );

			self::assertNotNull( $sentinel );
			self::assertTrue( $sentinel->success, 'AC-6: dismiss MUST send wp_send_json_success.' );
			self::assertCount( 1, $wpdb->updateCalls );
			self::assertSame(
				'dismissed',
				$wpdb->updateCalls[0]['data']['state'],
				'AC-6: state MUST be set to "dismissed".'
			);
			self::assertSame( 88, $sentinel->payload['failed_op_id'] );
		}

		// ===================================================================
		// AC-7: dismiss() refuses plain-dismiss for create_order rows.
		// ===================================================================

		/**
		 * AC-7: GIVEN op_type=create_order + no `resolution` key
		 *       WHEN  dismiss() runs
		 *       THEN  wp_send_json_error(['code'=>'resolution_required'], 422)
		 *             AND markDismissed is NOT called.
		 */
		public function test_dismiss_refuses_plain_dismiss_for_create_order(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$repo               = $this->makeRepo( $wpdb );

			$_POST = array(
				'failed_op_id' => '42',
				'nonce'        => 'whatever',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'dismiss' );

			self::assertNotNull( $sentinel );
			self::assertFalse( $sentinel->success, 'AC-7: dismiss for create_order MUST be rejected.' );
			self::assertSame( 422, $sentinel->status, 'AC-7: HTTP status MUST be 422.' );
			self::assertSame(
				'resolution_required',
				$sentinel->payload['code'] ?? null,
				'AC-7: response code MUST be "resolution_required".'
			);
		}

		/**
		 * AC-7: when refused, NO markDismissed UPDATE is issued.
		 */
		public function test_dismiss_does_not_call_mark_dismissed_when_refused(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$repo               = $this->makeRepo( $wpdb );

			$_POST = array(
				'failed_op_id' => '42',
				'nonce'        => 'whatever',
			);

			$actions = new FailedOpsActions( $repo );
			$this->runHandler( $actions, 'dismiss' );

			self::assertSame(
				array(),
				$wpdb->updateCalls,
				'AC-7: NO UPDATE (markDismissed) MUST be issued when dismiss is refused.'
			);
		}

		// ===================================================================
		// AC-8: resolve() — `resend` branch.
		// ===================================================================

		/**
		 * AC-8: GIVEN op_type=create_order, resolution=resend
		 *       WHEN  resolve() runs
		 *       THEN  AS-enqueue spreadconnect/create_order + markResolved + JSON success.
		 */
		public function test_resolve_with_resend_enqueues_create_order_action(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$wpdb->updateResult = 1;
			$repo               = $this->makeRepo( $wpdb );

			$_POST = array(
				'failed_op_id' => '42',
				'resolution'   => 'resend',
				'nonce'        => 'whatever',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'resolve' );

			self::assertNotNull( $sentinel );
			self::assertTrue( $sentinel->success, 'AC-8: resolve(resend) MUST emit wp_send_json_success.' );
			self::assertCount( 1, $this->enqueuedActions );
			self::assertSame( 'spreadconnect/create_order', $this->enqueuedActions[0][0] );
			self::assertSame( array( 'order_id' => 7 ), $this->enqueuedActions[0][1] );
			self::assertCount( 1, $wpdb->updateCalls );
			self::assertSame( 'resolved', $wpdb->updateCalls[0]['data']['state'] );
		}

		// ===================================================================
		// AC-9: resolve() — `cancel_wc` branch.
		// ===================================================================

		/**
		 * AC-9: GIVEN op_type=create_order, resolution=cancel_wc, payload.order_id=7
		 *       WHEN  resolve() runs
		 *       THEN  WC_Order::update_status('cancelled', $note) + markResolved.
		 */
		public function test_resolve_with_cancel_wc_sets_wc_order_status_cancelled(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$wpdb->updateResult = 1;
			$repo               = $this->makeRepo( $wpdb );

			// Capture update_status invocations on the WC_Order mock.
			$captured = array();
			$order    = $this->makeWcOrderMock();
			$order->shouldReceive( 'update_status' )
				->once()
				->andReturnUsing( static function ( string $status, string $note = '' ) use ( &$captured ): bool {
					$captured[] = array( 'status' => $status, 'note' => $note );
					return true;
				} );

			Functions\when( 'wc_get_order' )->alias(
				static function ( $id ) use ( $order ) {
					return ( 7 === (int) $id ) ? $order : false;
				}
			);

			$_POST = array(
				'failed_op_id' => '42',
				'resolution'   => 'cancel_wc',
				'nonce'        => 'whatever',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'resolve' );

			self::assertNotNull( $sentinel );
			self::assertTrue( $sentinel->success, 'AC-9: cancel_wc MUST emit wp_send_json_success.' );
			self::assertCount( 1, $captured, 'AC-9: update_status MUST be called exactly once.' );
			self::assertSame( 'cancelled', $captured[0]['status'], 'AC-9: status MUST be "cancelled".' );
			self::assertNotEmpty( $captured[0]['note'], 'AC-9: update_status MUST carry a note string.' );

			self::assertCount( 1, $wpdb->updateCalls );
			self::assertSame( 'resolved', $wpdb->updateCalls[0]['data']['state'] );
		}

		/**
		 * AC-9: cancel_wc MUST NOT trigger refund-API calls.
		 */
		public function test_resolve_cancel_wc_does_not_call_refund_api(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$wpdb->updateResult = 1;
			$repo               = $this->makeRepo( $wpdb );

			$order = $this->makeWcOrderMock();
			$order->shouldReceive( 'update_status' )->once()->andReturnTrue();

			// The WC refund flow is `wc_create_refund(...)`. The handler MUST
			// NEVER call it — Plugin only does status mutation + note.
			Functions\expect( 'wc_create_refund' )->never();
			Functions\when( 'wc_get_order' )->justReturn( $order );

			$_POST = array(
				'failed_op_id' => '42',
				'resolution'   => 'cancel_wc',
				'nonce'        => 'whatever',
			);

			$actions = new FailedOpsActions( $repo );
			$this->runHandler( $actions, 'resolve' );

			// Assertions covered by Mockery + Brain\Monkey.
			self::assertTrue( true, 'AC-9: wc_create_refund MUST never be called.' );
		}

		/**
		 * AC-9: wc_get_order returns false -> 422 wc_order_missing.
		 */
		public function test_resolve_returns_error_when_wc_order_missing(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$repo               = $this->makeRepo( $wpdb );

			Functions\when( 'wc_get_order' )->justReturn( false );

			$_POST = array(
				'failed_op_id' => '42',
				'resolution'   => 'cancel_wc',
				'nonce'        => 'whatever',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'resolve' );

			self::assertNotNull( $sentinel );
			self::assertFalse( $sentinel->success );
			self::assertSame( 422, $sentinel->status );
			self::assertSame( 'wc_order_missing', $sentinel->payload['code'] ?? null );
		}

		// ===================================================================
		// AC-10: resolve() — `submitted_externally` branch.
		// ===================================================================

		/**
		 * AC-10: submitted_externally MUST write
		 *        `_spreadconnect_order_id` = $external_sc_order_id via update_meta_data.
		 */
		public function test_resolve_submitted_externally_writes_order_id_meta(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$wpdb->updateResult = 1;
			$repo               = $this->makeRepo( $wpdb );

			$metaWrites = array();
			$saveCount  = 0;
			$noteCalls  = array();

			$order = $this->makeWcOrderMock();
			$order->shouldReceive( 'update_meta_data' )
				->andReturnUsing( static function ( string $key, $value ) use ( &$metaWrites ): void {
					$metaWrites[] = array( 'key' => $key, 'value' => $value );
				} );
			$order->shouldReceive( 'save' )
				->andReturnUsing( static function () use ( &$saveCount ): int {
					$saveCount++;
					return 1;
				} );
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note, $is_customer = false, $added_by_user = false ) use ( &$noteCalls ): int {
					$noteCalls[] = $note;
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$_POST = array(
				'failed_op_id'         => '42',
				'resolution'           => 'submitted_externally',
				'external_sc_order_id' => 'sc_abc_999',
				'nonce'                => 'whatever',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'resolve' );

			self::assertNotNull( $sentinel );
			self::assertTrue( $sentinel->success, 'AC-10: submitted_externally MUST succeed when valid.' );

			$keysWritten = array_column( $metaWrites, 'key' );
			self::assertContains(
				'_spreadconnect_order_id',
				$keysWritten,
				'AC-10: _spreadconnect_order_id meta MUST be written.'
			);

			$valueByKey = array();
			foreach ( $metaWrites as $row ) {
				$valueByKey[ $row['key'] ] = $row['value'];
			}
			self::assertSame(
				'sc_abc_999',
				$valueByKey['_spreadconnect_order_id'] ?? null,
				'AC-10: _spreadconnect_order_id MUST equal external_sc_order_id from POST.'
			);
		}

		/**
		 * AC-10: submitted_externally MUST write `_spreadconnect_state='NEW'`.
		 */
		public function test_resolve_submitted_externally_writes_state_new(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$wpdb->updateResult = 1;
			$repo               = $this->makeRepo( $wpdb );

			$metaWrites = array();

			$order = $this->makeWcOrderMock();
			$order->shouldReceive( 'update_meta_data' )
				->andReturnUsing( static function ( string $key, $value ) use ( &$metaWrites ): void {
					$metaWrites[] = array( 'key' => $key, 'value' => $value );
				} );
			$order->shouldReceive( 'save' )->andReturn( 1 );
			$order->shouldReceive( 'add_order_note' )->andReturn( 1 );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$_POST = array(
				'failed_op_id'         => '42',
				'resolution'           => 'submitted_externally',
				'external_sc_order_id' => 'sc_abc_999',
				'nonce'                => 'whatever',
			);

			$actions = new FailedOpsActions( $repo );
			$this->runHandler( $actions, 'resolve' );

			$valueByKey = array();
			foreach ( $metaWrites as $row ) {
				$valueByKey[ $row['key'] ] = $row['value'];
			}
			self::assertSame(
				'NEW',
				$valueByKey['_spreadconnect_state'] ?? null,
				'AC-10: _spreadconnect_state MUST equal "NEW".'
			);
		}

		/**
		 * AC-10: submitted_externally MUST add an order note mentioning the
		 *        external SC-OrderID.
		 */
		public function test_resolve_submitted_externally_adds_order_note(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$wpdb->updateResult = 1;
			$repo               = $this->makeRepo( $wpdb );

			$noteCalls = array();

			$order = $this->makeWcOrderMock();
			$order->shouldReceive( 'update_meta_data' )->andReturnTrue();
			$order->shouldReceive( 'save' )->andReturn( 1 );
			$order->shouldReceive( 'add_order_note' )
				->andReturnUsing( static function ( string $note, $is_customer = false, $added_by_user = false ) use ( &$noteCalls ): int {
					$noteCalls[] = $note;
					return 1;
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$_POST = array(
				'failed_op_id'         => '42',
				'resolution'           => 'submitted_externally',
				'external_sc_order_id' => 'sc_abc_999',
				'nonce'                => 'whatever',
			);

			$actions = new FailedOpsActions( $repo );
			$this->runHandler( $actions, 'resolve' );

			self::assertNotEmpty( $noteCalls, 'AC-10: add_order_note MUST be called at least once.' );
			$joined = implode( ' | ', $noteCalls );
			self::assertStringContainsString(
				'sc_abc_999',
				$joined,
				'AC-10: order note MUST mention the external SC-OrderID.'
			);
		}

		/**
		 * AC-10: HPOS-konform — meta writes go through update_meta_data + save,
		 *        NEVER through `update_post_meta`. Brain\Monkey expectation
		 *        ensures the lower-level WP function is never invoked.
		 */
		public function test_resolve_submitted_externally_uses_hpos_safe_meta_writes(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$wpdb->updateResult = 1;
			$repo               = $this->makeRepo( $wpdb );

			Functions\expect( 'update_post_meta' )->never();

			$order = $this->makeWcOrderMock();
			$order->shouldReceive( 'update_meta_data' )->andReturnTrue();
			$order->shouldReceive( 'save' )->once()->andReturn( 1 );
			$order->shouldReceive( 'add_order_note' )->andReturn( 1 );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$_POST = array(
				'failed_op_id'         => '42',
				'resolution'           => 'submitted_externally',
				'external_sc_order_id' => 'sc_abc_999',
				'nonce'                => 'whatever',
			);

			$actions = new FailedOpsActions( $repo );
			$this->runHandler( $actions, 'resolve' );

			self::assertTrue(
				true,
				'AC-10: HPOS path uses update_meta_data + save — verified via Brain\Monkey expectations.'
			);
		}

		// ===================================================================
		// AC-11: submitted_externally — empty external_sc_order_id rejected.
		// ===================================================================

		/**
		 * AC-11: empty external_sc_order_id -> 422 external_id_required + no meta-write.
		 */
		public function test_resolve_submitted_externally_rejects_empty_external_id(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$repo               = $this->makeRepo( $wpdb );

			$metaWrites = array();
			$order      = $this->makeWcOrderMock();
			$order->shouldReceive( 'update_meta_data' )
				->andReturnUsing( static function ( string $key, $value ) use ( &$metaWrites ): void {
					$metaWrites[] = array( 'key' => $key, 'value' => $value );
				} );

			Functions\when( 'wc_get_order' )->justReturn( $order );

			$_POST = array(
				'failed_op_id'         => '42',
				'resolution'           => 'submitted_externally',
				'external_sc_order_id' => '   ',
				'nonce'                => 'whatever',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'resolve' );

			self::assertNotNull( $sentinel );
			self::assertFalse( $sentinel->success );
			self::assertSame( 422, $sentinel->status );
			self::assertSame(
				'external_id_required',
				$sentinel->payload['code'] ?? null,
				'AC-11: empty external_sc_order_id MUST yield code="external_id_required".'
			);
			self::assertSame(
				array(),
				$metaWrites,
				'AC-11: NO update_meta_data MUST run when external-id is empty.'
			);
			self::assertSame(
				array(),
				$wpdb->updateCalls,
				'AC-11: NO markResolved UPDATE MUST run when external-id is empty.'
			);
		}

		// ===================================================================
		// AC-12: resolve() — unknown resolution rejected.
		// ===================================================================

		/**
		 * AC-12: resolution='foo' -> 422 invalid_resolution.
		 */
		public function test_resolve_rejects_unknown_resolution_value(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '42',
				'op_type'             => 'create_order',
				'related_entity_type' => 'order',
				'related_entity_id'   => '7',
				'payload'             => json_encode( array( 'order_id' => 7 ) ),
				'state'               => 'unresolved',
			);
			$repo               = $this->makeRepo( $wpdb );

			$_POST = array(
				'failed_op_id' => '42',
				'resolution'   => 'foo',
				'nonce'        => 'whatever',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'resolve' );

			self::assertNotNull( $sentinel );
			self::assertFalse( $sentinel->success );
			self::assertSame( 422, $sentinel->status );
			self::assertSame(
				'invalid_resolution',
				$sentinel->payload['code'] ?? null,
				'AC-12: unknown resolution MUST yield code="invalid_resolution".'
			);
			self::assertSame( array(), $this->enqueuedActions, 'AC-12: NO AS-enqueue on unknown resolution.' );
			self::assertSame( array(), $wpdb->updateCalls, 'AC-12: NO repo mutation on unknown resolution.' );
		}

		// ===================================================================
		// AC-13: resolve() — wrong op_type rejected.
		// ===================================================================

		/**
		 * AC-13: op_type=sync_article -> 422 wrong_op_type, no meta/repo write.
		 */
		public function test_resolve_create_order_refuses_other_op_types(): void
		{
			$wpdb               = $this->makeWpdb();
			$wpdb->getRowResult = array(
				'id'                  => '88',
				'op_type'             => 'sync_article',
				'related_entity_type' => 'article',
				'related_entity_id'   => '13',
				'payload'             => json_encode( array( 'article_id' => 13 ) ),
				'state'               => 'unresolved',
			);
			$repo               = $this->makeRepo( $wpdb );

			$_POST = array(
				'failed_op_id' => '88',
				'resolution'   => 'resend',
				'nonce'        => 'whatever',
			);

			$actions  = new FailedOpsActions( $repo );
			$sentinel = $this->runHandler( $actions, 'resolve' );

			self::assertNotNull( $sentinel );
			self::assertFalse( $sentinel->success );
			self::assertSame( 422, $sentinel->status );
			self::assertSame(
				'wrong_op_type',
				$sentinel->payload['code'] ?? null,
				'AC-13: non-create_order op_type MUST yield code="wrong_op_type".'
			);
			self::assertSame( array(), $this->enqueuedActions, 'AC-13: NO AS-enqueue.' );
			self::assertSame( array(), $wpdb->updateCalls, 'AC-13: NO repo mutation.' );
		}

		// ===================================================================
		// AC-14: Modal markup — 3 radios, dismiss button data-attrs.
		// ===================================================================

		/**
		 * AC-14: Modal container is rendered with 3 radio options.
		 */
		public function test_modal_container_is_rendered_with_three_radio_options(): void
		{
			$out = $this->captureViewOutput( array() );

			self::assertStringContainsString(
				'id="spreadconnect-resolution-modal"',
				$out,
				'AC-14: Modal container with id "spreadconnect-resolution-modal" MUST be rendered.'
			);

			self::assertMatchesRegularExpression(
				'/<input[^>]*type="radio"[^>]*name="resolution"[^>]*value="resend"/',
				$out,
				'AC-14: Modal MUST carry a "resend" radio.'
			);
			self::assertMatchesRegularExpression(
				'/<input[^>]*type="radio"[^>]*name="resolution"[^>]*value="cancel_wc"/',
				$out,
				'AC-14: Modal MUST carry a "cancel_wc" radio.'
			);
			self::assertMatchesRegularExpression(
				'/<input[^>]*type="radio"[^>]*name="resolution"[^>]*value="submitted_externally"/',
				$out,
				'AC-14: Modal MUST carry a "submitted_externally" radio.'
			);
		}

		/**
		 * AC-14: create_order Dismiss button MUST have data-needs-resolution="1".
		 * (Same as AC-2, kept here for AC-14 traceability — JS handler reads
		 * exactly this attribute to decide modal-vs-plain.)
		 */
		public function test_create_order_dismiss_button_has_data_needs_resolution_one(): void
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
				'/<button[^>]*data-action="dismiss"[^>]*data-needs-resolution="1"/',
				$out,
				'AC-14: create_order Dismiss button MUST carry data-needs-resolution="1".'
			);
		}

		/**
		 * AC-14: sync_article Dismiss button MUST have data-needs-resolution="0".
		 */
		public function test_sync_article_dismiss_button_has_data_needs_resolution_zero(): void
		{
			$rows = array(
				array(
					'id'                  => '88',
					'op_type'             => 'sync_article',
					'related_entity_type' => 'article',
					'related_entity_id'   => '13',
					'payload'             => array( 'article_id' => 13 ),
					'error_message'       => 'boom',
					'error_code'          => 'http_5xx',
					'retries_used'        => '3',
					'created_at'          => '2026-05-04 11:00:00',
					'last_attempt_at'     => '2026-05-04 11:00:00',
					'state'               => 'unresolved',
				),
			);

			$out = $this->captureViewOutput( $rows );

			self::assertMatchesRegularExpression(
				'/<button[^>]*data-action="dismiss"[^>]*data-needs-resolution="0"/',
				$out,
				'AC-14: sync_article Dismiss button MUST carry data-needs-resolution="0".'
			);
		}

		// ===================================================================
		// AC-15: External-ID input + asset existence check.
		// ===================================================================

		/**
		 * AC-15: external_sc_order_id input is rendered inside the modal,
		 *        wrapped in a container with the `hidden` attribute (JS toggles
		 *        on radio change).
		 */
		public function test_external_id_input_is_in_markup_with_initial_hidden_attr(): void
		{
			$out = $this->captureViewOutput( array() );

			self::assertMatchesRegularExpression(
				'/<input[^>]*name="external_sc_order_id"/',
				$out,
				'AC-15: external_sc_order_id input MUST be present in the modal markup.'
			);

			// The container around the input MUST be hidden on initial render.
			self::assertMatchesRegularExpression(
				'/<div[^>]*spreadconnect-failed-ops__modal-external-id[^>]*hidden/',
				$out,
				'AC-15: external-id wrapper MUST carry the hidden attribute initially.'
			);
		}

		/**
		 * AC-15: asset file exists on disk at the expected path.
		 */
		public function test_modal_asset_file_exists_at_expected_path(): void
		{
			$assetPath = self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/assets/js/failed-ops-modal.js';

			self::assertFileExists(
				$assetPath,
				'AC-15: failed-ops-modal.js MUST ship at assets/js/failed-ops-modal.js.'
			);

			$content = (string) file_get_contents( $assetPath );

			// Spot-check three behaviour markers from AC-14 / AC-15:
			//   - the modal container selector,
			//   - the data-action="dismiss" delegated handler,
			//   - the submitted_externally branch (External-ID gate).
			self::assertStringContainsString(
				'#spreadconnect-resolution-modal',
				$content,
				'AC-15: JS MUST reference the modal container selector.'
			);
			self::assertStringContainsString(
				'data-action="dismiss"',
				$content,
				'AC-14: JS MUST bind a delegated handler for data-action="dismiss".'
			);
			self::assertStringContainsString(
				'submitted_externally',
				$content,
				'AC-15: JS MUST handle the submitted_externally branch.'
			);
			self::assertStringContainsString(
				'spreadconnect_resolve_create_order',
				$content,
				'AC-14: JS MUST POST to spreadconnect_resolve_create_order on Confirm.'
			);
			self::assertStringContainsString(
				'spreadconnect_dismiss_failed_op',
				$content,
				'AC-14: JS MUST POST to spreadconnect_dismiss_failed_op on plain Dismiss.'
			);
		}
	}
}
