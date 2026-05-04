<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 26 — Catalog-Sync-UI + Live-Progress-AJAX
//
// Acceptance Tests gegen die Slice-Spec `slice-26-catalog-sync-ui-progress.md`.
//
// Mocking Strategy (per Slice-Spec `mock_external`):
//   - Brain\Monkey aliased fuer WP REST/AJAX/Action-Scheduler-Funktionen
//     (`register_rest_route`, `as_enqueue_async_action`, `check_ajax_referer`,
//      `current_user_can`, `wp_send_json_success`, `wp_send_json_error`,
//      `get_transient`, `__`, `esc_html`, `esc_attr`, `esc_url`,
//      `current_time`, `admin_url`, `wp_create_nonce`, `rest_url`,
//      `esc_url_raw`, `wp_json_encode`).
//   - `SyncHistoryRepo` ist `final` -> wir verwenden REALE Instanzen gegen
//     einen FakeWpdb-Stub. Damit testen wir die echte Repo-SQL und das
//     Konstruktor-DI Verhalten von `SyncProgress`.
//   - `wp_send_json_*` werfen `Slice26JsonSentinel`, damit der PHPUnit-Prozess
//     nicht via `die()` terminiert.
//   - `WP_REST_Request`, `WP_REST_Response`, `WP_REST_Server`, `WP_Error`
//     werden als minimale globale Stubs deklariert (keine WP-Loader-Abhaengigkeit).
//
// Strategie pro AC-Block:
//   - AC-1: `Hub\View\Catalog::render()` Output-Buffer-Capture; assert auf
//     Sync-Now-Button + During-Sync-Panel + History-Title.
//   - AC-2: 5 History-Rows -> Tabelle mit korrekter Spalten-Reihenfolge;
//     0 Rows -> Empty-State.
//   - AC-3: data-run-id Attribut wird mit `getActiveRun()`-Resultat befuellt.
//   - AC-4: AJAX-Handler ruft `as_enqueue_async_action` mit korrektem
//     Hook+Args+Group; Response = `wp_send_json_success([...])`.
//   - AC-5: Cap/Nonce fail -> `wp_send_json_error(403)` + KEIN Enqueue.
//   - AC-6: `register_rest_route` mit korrektem namespace+route+methods+
//     callback+permission_callback.
//   - AC-7: `handle()` JSON-Body mit allen 10 Architecture-Keys + Total +
//     Log-Tail aus Transients.
//   - AC-8: Default-Pfad (kein run_id-Param) -> juengste aktive Row;
//     fallback recent; fallback idle.
//   - AC-9: Spezifischer run_id existiert nicht -> 404 WP_Error
//     `sync_run_not_found`.
//   - AC-10: `permission()` returnt false ohne `manage_woocommerce`.
//   - AC-12: Bootstrap registriert REST-Init-Hook + AJAX-Action.
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'WP_Error', false ) ) {
		class WP_Error
		{
			public string $code;
			public string $message;
			public mixed $data;

			public function __construct( string $code = '', string $message = '', mixed $data = null ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data(): mixed {
				return $this->data;
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Server', false ) ) {
		final class WP_REST_Server
		{
			public const READABLE = 'GET';
			public const CREATABLE = 'POST';
			public const EDITABLE = 'POST, PUT, PATCH';
			public const DELETABLE = 'DELETE';
		}
	}

	if ( ! class_exists( 'WP_REST_Request', false ) ) {
		class WP_REST_Request
		{
			/** @var array<string,mixed> */
			private array $params = [];
			private string $method = 'GET';
			private string $route = '';

			public function __construct( string $method = 'GET', string $route = '' ) {
				$this->method = $method;
				$this->route  = $route;
			}

			public function get_param( string $key ) {
				return $this->params[ $key ] ?? null;
			}

			public function set_param( string $key, $value ): void {
				$this->params[ $key ] = $value;
			}

			public function get_method(): string {
				return $this->method;
			}

			public function get_route(): string {
				return $this->route;
			}
		}
	}

	if ( ! class_exists( 'WP_REST_Response', false ) ) {
		class WP_REST_Response
		{
			/** @var mixed */
			private $data;
			private int $status;
			/** @var array<string,string> */
			private array $headers;

			public function __construct( $data = null, int $status = 200, array $headers = [] ) {
				$this->data    = $data;
				$this->status  = $status;
				$this->headers = $headers;
			}

			public function get_data() {
				return $this->data;
			}

			public function get_status(): int {
				return $this->status;
			}

			public function get_headers(): array {
				return $this->headers;
			}
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $options = 0, $depth = 512 ) {
			return json_encode( $data, $options, $depth );
		}
	}

	if ( ! function_exists( 'absint' ) ) {
		function absint( $maybeint ): int {
			return abs( (int) $maybeint );
		}
	}
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Bootstrap\Plugin;
	use SpreadconnectPod\Catalog\SyncHistoryRepo;
	use SpreadconnectPod\Hub\Ajax\SyncNow;
	use SpreadconnectPod\Hub\Rest\SyncProgress;
	use SpreadconnectPod\Hub\View\Catalog;

	/**
	 * Sentinel exception fuer `wp_send_json_*`-Stubs.
	 */
	final class Slice26JsonSentinel extends \RuntimeException
	{
		/** @var array<string,mixed> */
		public array $payload;

		public bool $success;

		public ?int $status;

		/**
		 * @param array<string,mixed> $payload
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
	 * Minimal $wpdb stub fuer Slice-26 SyncHistoryRepo Read-Tests.
	 *
	 * Tabelle wird in-memory gehalten — Tests koennen Rows direkt setzen.
	 */
	final class Slice26FakeWpdb
	{
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public string $last_error = '';

		/** @var list<array<string,mixed>> */
		public array $rows = [];

		/** @var list<string> */
		public array $queryCalls = [];

		public function prepare( string $sql, ...$args ): string
		{
			$out = $sql;
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg )
					? (string) $arg
					: "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$out         = preg_replace( '/%[ds]/', $replacement, $out, 1 ) ?? $out;
			}
			return $out;
		}

		public function get_row( string $sql, $output = ARRAY_A ): ?array
		{
			// `getById`: SELECT * FROM ... WHERE id = N
			if ( preg_match( '/WHERE\s+id\s*=\s*(\d+)\s*$/i', $sql, $m ) ) {
				$id = (int) $m[1];
				foreach ( $this->rows as $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === $id ) {
						return $row;
					}
				}
				return null;
			}

			// `getActiveRun`: WHERE state IN ('in_progress','pending') ORDER BY id DESC LIMIT 1
			if ( preg_match( '/state\s+IN\s*\(.*?\)\s*ORDER\s+BY\s+id\s+DESC/i', $sql ) ) {
				$active = array_filter(
					$this->rows,
					static fn( array $r ) => in_array( (string) ( $r['state'] ?? '' ), [ 'in_progress', 'pending' ], true )
				);
				if ( empty( $active ) ) {
					return null;
				}
				usort( $active, static fn( array $a, array $b ) => ( (int) $b['id'] ) <=> ( (int) $a['id'] ) );
				return reset( $active ) ?: null;
			}

			return null;
		}

		public function get_results( string $sql, $output = ARRAY_A ): array
		{
			// `getRecent`: SELECT * FROM ... ORDER BY started_at DESC, id DESC LIMIT N
			if ( preg_match( '/ORDER\s+BY\s+started_at\s+DESC.*?LIMIT\s+(\d+)/is', $sql, $m ) ) {
				$limit = (int) $m[1];
				$rows  = $this->rows;
				usort(
					$rows,
					static fn( array $a, array $b ) => strcmp(
						(string) ( $b['started_at'] ?? '' ),
						(string) ( $a['started_at'] ?? '' )
					) ?: ( (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ) )
				);
				return array_slice( $rows, 0, $limit );
			}
			return [];
		}

		public function query( string $sql ): int
		{
			$this->queryCalls[] = $sql;
			return 1;
		}

		public function insert( string $table, array $data, $format = null ): int
		{
			return 1;
		}

		public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int
		{
			return 1;
		}

		public function get_var( string $sql ): ?string
		{
			return null;
		}
	}

	/**
	 * Slice 26 Acceptance Tests — Catalog Sync UI + REST Progress + AJAX.
	 */
	final class Slice26CatalogSyncUiTest extends TestCase
	{
		/**
		 * Captured `register_rest_route` invocations as `[namespace, route, args]`.
		 *
		 * @var list<array{0:string,1:string,2:array<string,mixed>}>
		 */
		private array $restRoutes = [];

		/**
		 * Captured `as_enqueue_async_action` invocations as `[hook, args, group]`.
		 *
		 * @var list<array{0:string,1:array<string,mixed>,2:string}>
		 */
		private array $enqueuedActions = [];

		/**
		 * Backing store for `get_transient` lookups.
		 *
		 * @var array<string, mixed>
		 */
		private array $transientStore = [];

		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->restRoutes      = [];
			$this->enqueuedActions = [];
			$this->transientStore  = [];

			// ---- Default i18n + Escape-Helpers (identity stubs) -----------------
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'esc_attr__' )->returnArg( 1 );
			Functions\when( 'esc_html' )->returnArg( 1 );
			Functions\when( 'esc_attr' )->returnArg( 1 );
			Functions\when( 'esc_url' )->returnArg( 1 );
			Functions\when( 'esc_url_raw' )->returnArg( 1 );
			Functions\when( 'sanitize_key' )->alias(
				static function ( $key ) {
					if ( ! is_string( $key ) ) {
						return '';
					}
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
				}
			);
			Functions\when( 'wp_unslash' )->alias(
				static fn( $v ) => is_string( $v ) ? stripslashes( $v ) : $v
			);
			Functions\when( 'sanitize_text_field' )->alias(
				static fn( $v ) => is_string( $v ) ? trim( strip_tags( $v ) ) : ''
			);

			// ---- URL helpers ---------------------------------------------------
			Functions\when( 'admin_url' )->alias(
				static fn( $path = '' ) => 'http://example.test/wp-admin/' . ltrim( (string) $path, '/' )
			);
			Functions\when( 'rest_url' )->alias(
				static fn( $path = '' ) => 'http://example.test/wp-json/' . ltrim( (string) $path, '/' )
			);
			Functions\when( 'wp_create_nonce' )->alias(
				static fn( $action = -1 ) => 'NONCE_' . (string) $action
			);

			// ---- Time helper ---------------------------------------------------
			Functions\when( 'current_time' )->alias(
				static fn( $type = 'mysql' ) => '2026-05-04 12:00:00'
			);

			// ---- Transient store ----------------------------------------------
			$store = & $this->transientStore;
			Functions\when( 'get_transient' )->alias(
				static fn( $key ) => array_key_exists( (string) $key, $store )
					? $store[ (string) $key ]
					: false
			);
			Functions\when( 'set_transient' )->alias(
				static function ( $key, $value, $ttl = 0 ) use ( &$store ): bool {
					$store[ (string) $key ] = $value;
					return true;
				}
			);

			// ---- wp_send_json_* sentinels --------------------------------------
			Functions\when( 'wp_send_json_success' )->alias(
				static function ( $data = null, $status_code = null ): void {
					throw new Slice26JsonSentinel( true, (array) $data, $status_code );
				}
			);
			Functions\when( 'wp_send_json_error' )->alias(
				static function ( $data = null, $status_code = null ): void {
					throw new Slice26JsonSentinel( false, (array) $data, $status_code );
				}
			);

			// ---- as_enqueue_async_action capture -------------------------------
			$enqueues = & $this->enqueuedActions;
			Functions\when( 'as_enqueue_async_action' )->alias(
				static function ( string $hook, array $args = [], string $group = '' ) use ( &$enqueues ): int {
					$enqueues[] = [ $hook, $args, $group ];
					return count( $enqueues );
				}
			);

			// ---- register_rest_route capture -----------------------------------
			$routes = & $this->restRoutes;
			Functions\when( 'register_rest_route' )->alias(
				static function ( string $namespace, string $route, array $args = [] ) use ( &$routes ): bool {
					$routes[] = [ $namespace, $route, $args ];
					return true;
				}
			);

			// ---- WC logger no-op -----------------------------------------------
			Functions\when( 'wc_get_logger' )->alias(
				static fn() => new class {
					public function info( string $m, array $c = [] ): void {}
					public function warning( string $m, array $c = [] ): void {}
					public function error( string $m, array $c = [] ): void {}
					public function debug( string $m, array $c = [] ): void {}
				}
			);

			// ---- get_option default --------------------------------------------
			Functions\when( 'get_option' )->alias(
				static fn( $name, $default = false ) => $default
			);

			// ---- ARRAY_A constant for $wpdb stubs ------------------------------
			if ( ! defined( 'ARRAY_A' ) ) {
				define( 'ARRAY_A', 'ARRAY_A' );
			}
		}

		protected function tearDown(): void
		{
			$_POST = [];
			$_GET  = [];
			unset( $GLOBALS['wpdb'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * Install a fresh fake-`$wpdb` and seed it with rows.
		 *
		 * @param list<array<string,mixed>> $rows
		 */
		private function installFakeWpdb( array $rows = [] ): Slice26FakeWpdb
		{
			$wpdb         = new Slice26FakeWpdb();
			$wpdb->prefix = 'wp_';
			$wpdb->rows   = $rows;
			$GLOBALS['wpdb'] = $wpdb;
			return $wpdb;
		}

		/**
		 * Build a history row with sensible defaults.
		 *
		 * @param array<string,mixed> $overrides
		 *
		 * @return array<string,mixed>
		 */
		private function makeHistoryRow( array $overrides = [] ): array
		{
			return array_merge(
				[
					'id'            => 1,
					'started_at'    => '2026-05-04 12:00:00',
					'finished_at'   => null,
					'state'         => 'in_progress',
					'trigger'       => 'manual',
					'created_count' => 0,
					'updated_count' => 0,
					'skipped_count' => 0,
					'error_count'   => 0,
					'details'       => '[]',
				],
				$overrides
			);
		}

		// ===================================================================
		// AC-1: Catalog::render emits Sync-Now button + Progress slot +
		//        History table + i18n + escaping.
		// ===================================================================

		public function test_catalog_render_emits_sync_button_progress_slot_and_history_table(): void
		{
			$this->installFakeWpdb( [] );

			ob_start();
			Catalog::render();
			$html = (string) ob_get_clean();

			// (a) Sync-Now Button (Annotation ①).
			$this->assertStringContainsString(
				'data-spreadconnect-action="sync-now"',
				$html,
				'AC-1: Catalog-Page MUSS einen Sync-Now-Button mit data-spreadconnect-action="sync-now" rendern.'
			);
			$this->assertStringContainsString(
				'Sync now',
				$html,
				'AC-1: Catalog-Page MUSS den lokalisierten Button-Label "Sync now" rendern.'
			);

			// (b) During-Sync-Panel-Container (Annotation ③) — initial hidden.
			$this->assertStringContainsString(
				'spreadconnect-catalog__during-sync',
				$html,
				'AC-1: Catalog-Page MUSS einen "During Sync"-Panel-Container rendern (Annotation ③).'
			);

			// (c) Progress-Bar-Slot (Annotation ④).
			$this->assertStringContainsString(
				'data-spreadconnect-slot="progress"',
				$html,
				'AC-1: Catalog-Page MUSS einen Progress-Bar-Slot rendern (Annotation ④).'
			);

			// (d) Counter-Slot (Annotation ⑤).
			$this->assertStringContainsString(
				'data-spreadconnect-slot="counters"',
				$html,
				'AC-1: Catalog-Page MUSS einen Counter-Slot rendern (Annotation ⑤).'
			);

			// (e) Live-Log-Tail-Slot (Annotation ⑥).
			$this->assertStringContainsString(
				'data-spreadconnect-slot="log-tail"',
				$html,
				'AC-1: Catalog-Page MUSS einen Live-Log-Tail-Slot rendern (Annotation ⑥).'
			);

			// (f) History-Title (Annotation ⑦).
			$this->assertStringContainsString(
				'Sync History',
				$html,
				'AC-1: Catalog-Page MUSS die Sync-History-Sektion rendern (Annotation ⑦).'
			);

			// (g) Form posts to admin-ajax.php with the spreadconnect_sync_now action.
			$this->assertStringContainsString(
				'admin-ajax.php',
				$html,
				'AC-1: Sync-Now-Form MUSS auf admin-ajax.php posten.'
			);
			$this->assertStringContainsString(
				'spreadconnect_sync_now',
				$html,
				'AC-1: Form MUSS den AJAX-Action-String "spreadconnect_sync_now" enthalten.'
			);

			// (h) Nonce field present.
			$this->assertStringContainsString(
				'_ajax_nonce',
				$html,
				'AC-1: Form MUSS ein _ajax_nonce-Feld rendern.'
			);
		}

		/**
		 * AC-1: Source-Analyse — Catalog.php nutzt `__()`/`esc_html__()` mit
		 * dem Domain-String 'spreadconnect-pod'.
		 */
		public function test_catalog_render_uses_correct_text_domain(): void
		{
			$source = (string) file_get_contents(
				self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/includes/Hub/View/Catalog.php'
			);
			$this->assertNotSame( '', $source );

			$reflection = new ReflectionClass( Catalog::class );
			$constants  = $reflection->getReflectionConstants();

			$textDomainValue = null;
			foreach ( $constants as $rc ) {
				if ( $rc->getName() === 'TEXT_DOMAIN' ) {
					$textDomainValue = $rc->getValue();
					break;
				}
			}

			$this->assertSame(
				'spreadconnect-pod',
				$textDomainValue,
				'AC-1: Catalog::TEXT_DOMAIN MUSS exakt "spreadconnect-pod" sein.'
			);
		}

		// ===================================================================
		// AC-2: History-Tabelle zeigt Rows DESC by started_at mit korrekten
		//        Spalten in Wireframe-Reihenfolge.
		// ===================================================================

		public function test_catalog_render_history_table_columns_match_wireframe_order(): void
		{
			$rows = [
				$this->makeHistoryRow( [ 'id' => 5, 'started_at' => '2026-05-04 14:00:00', 'state' => 'complete', 'trigger' => 'manual', 'created_count' => 10 ] ),
				$this->makeHistoryRow( [ 'id' => 4, 'started_at' => '2026-05-04 13:00:00', 'state' => 'failed', 'trigger' => 'webhook', 'error_count' => 1 ] ),
				$this->makeHistoryRow( [ 'id' => 3, 'started_at' => '2026-05-04 12:00:00', 'state' => 'complete', 'trigger' => 'scheduled' ] ),
				$this->makeHistoryRow( [ 'id' => 2, 'started_at' => '2026-05-04 11:00:00', 'state' => 'complete', 'trigger' => 'initial' ] ),
				$this->makeHistoryRow( [ 'id' => 1, 'started_at' => '2026-05-04 10:00:00', 'state' => 'complete', 'trigger' => 'manual' ] ),
			];

			$this->installFakeWpdb( $rows );

			ob_start();
			Catalog::render();
			$html = (string) ob_get_clean();

			// History-Tabelle MUSS existieren.
			$this->assertStringContainsString(
				'spreadconnect-catalog__history-table',
				$html,
				'AC-2: History-Tabelle MUSS gerendert werden bei vorhandenen Rows.'
			);

			// Spalten in EXAKTER Wireframe-Reihenfolge: Started, Duration, Trigger,
			// Created, Updated, Skipped, Errors, State.
			$expectedColumns = [ 'Started', 'Duration', 'Trigger', 'Created', 'Updated', 'Skipped', 'Errors', 'State' ];
			$lastPos         = -1;
			foreach ( $expectedColumns as $col ) {
				$pos = strpos( $html, '<th' );
				$this->assertGreaterThan(
					$lastPos,
					(int) strpos( $html, $col, max( 0, $lastPos ) ),
					sprintf(
						'AC-2: Spalten-Reihenfolge MUSS Wireframe ⑦ entsprechen — "%s" muss nach Position %d kommen.',
						$col,
						$lastPos
					)
				);
				$lastPos = (int) strpos( $html, $col, max( 0, $lastPos ) );
			}

			// Alle 5 Rows MUESSEN im HTML enthalten sein (Trigger-Strings als Marker).
			foreach ( [ 'manual', 'webhook', 'scheduled', 'initial' ] as $trigger ) {
				$this->assertStringContainsString(
					'<td>' . $trigger . '</td>',
					$html,
					sprintf( 'AC-2: Trigger-Wert "%s" MUSS in der Tabelle erscheinen.', $trigger )
				);
			}

			// DESC by started_at: Row id=5 (14:00) erscheint VOR Row id=1 (10:00).
			$pos5 = strpos( $html, '2026-05-04 14:00:00' );
			$pos1 = strpos( $html, '2026-05-04 10:00:00' );
			$this->assertNotFalse( $pos5, 'AC-2: Row id=5 MUSS gerendert werden.' );
			$this->assertNotFalse( $pos1, 'AC-2: Row id=1 MUSS gerendert werden.' );
			$this->assertLessThan(
				$pos1,
				$pos5,
				'AC-2: Sortierung MUSS DESC by started_at sein — neueste Row zuerst.'
			);
		}

		public function test_catalog_render_history_empty_state_when_no_rows(): void
		{
			$this->installFakeWpdb( [] );

			ob_start();
			Catalog::render();
			$html = (string) ob_get_clean();

			// Keine Tabelle bei 0 Rows.
			$this->assertStringNotContainsString(
				'spreadconnect-catalog__history-table',
				$html,
				'AC-2: Bei 0 History-Rows DARF KEINE Tabelle gerendert werden.'
			);

			// Stattdessen: lokalisierter Empty-State-Hinweis.
			$this->assertStringContainsString(
				'spreadconnect-catalog__history-empty',
				$html,
				'AC-2: Empty-State MUSS einen Hinweis-Container rendern.'
			);
			$this->assertStringContainsString(
				'No sync runs yet',
				$html,
				'AC-2: Empty-State-Text MUSS "No sync runs yet" enthalten (State-Variation `no_history_yet`).'
			);
		}

		// ===================================================================
		// AC-3: data-run-id Attribut wird mit aktiver run_id befuellt.
		// ===================================================================

		public function test_catalog_render_exposes_active_run_id_to_frontend(): void
		{
			// Active run mit id=42.
			$this->installFakeWpdb( [
				$this->makeHistoryRow( [ 'id' => 42, 'state' => 'in_progress' ] ),
			] );

			ob_start();
			Catalog::render();
			$html = (string) ob_get_clean();

			// data-run-id="42" MUSS am During-Sync-Panel anliegen.
			$this->assertMatchesRegularExpression(
				'/spreadconnect-catalog__during-sync[^>]*data-run-id="42"/',
				$html,
				'AC-3: Active run_id MUSS via data-run-id-Attribut am During-Sync-Panel exponiert werden.'
			);
		}

		public function test_catalog_render_exposes_empty_run_id_when_no_active_run(): void
		{
			$this->installFakeWpdb( [
				// Nur abgeschlossene Rows -> keine aktive run.
				$this->makeHistoryRow( [ 'id' => 1, 'state' => 'complete' ] ),
			] );

			ob_start();
			Catalog::render();
			$html = (string) ob_get_clean();

			// data-run-id="" wenn keine aktive Row existiert.
			$this->assertMatchesRegularExpression(
				'/spreadconnect-catalog__during-sync[^>]*data-run-id=""/',
				$html,
				'AC-3: Bei fehlender aktiver Row MUSS data-run-id="" sein.'
			);
		}

		// ===================================================================
		// AC-4: SyncNow::handle ruft as_enqueue_async_action mit korrektem
		//        Hook+Args+Group + Response-Format.
		// ===================================================================

		public function test_sync_now_handle_enqueues_sync_catalog_with_manual_trigger(): void
		{
			Functions\when( 'current_user_can' )->alias(
				static fn( string $cap ): bool => $cap === 'manage_woocommerce'
			);
			Functions\when( 'check_ajax_referer' )->justReturn( true );

			$_POST = [
				'action'      => 'spreadconnect_sync_now',
				'_ajax_nonce' => 'nonce_42',
			];

			$thrown = null;
			try {
				SyncNow::handle();
			} catch ( Slice26JsonSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown, 'AC-4: Handler MUSS via wp_send_json_success terminieren.' );

			$this->assertCount(
				1,
				$this->enqueuedActions,
				'AC-4: as_enqueue_async_action MUSS GENAU EINMAL aufgerufen werden.'
			);

			[ $hook, $args, $group ] = $this->enqueuedActions[0];

			$this->assertSame(
				'spreadconnect/sync_catalog',
				$hook,
				'AC-4: Hook-Name MUSS "spreadconnect/sync_catalog" sein.'
			);
			$this->assertSame(
				[ 'trigger' => 'manual' ],
				$args,
				'AC-4: Args MUSS exakt ["trigger"=>"manual"] sein.'
			);
			$this->assertSame(
				'spreadconnect',
				$group,
				'AC-4: AS-Group MUSS "spreadconnect" sein (konsistent mit Slice-24-AC-2).'
			);
		}

		public function test_sync_now_handle_response_shape_matches_contract(): void
		{
			Functions\when( 'current_user_can' )->alias(
				static fn( string $cap ): bool => $cap === 'manage_woocommerce'
			);
			Functions\when( 'check_ajax_referer' )->justReturn( true );

			$_POST = [
				'action'      => 'spreadconnect_sync_now',
				'_ajax_nonce' => 'nonce_42',
			];

			$thrown = null;
			try {
				SyncNow::handle();
			} catch ( Slice26JsonSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull( $thrown );
			$this->assertTrue(
				$thrown->success,
				'AC-4: Response MUSS wp_send_json_success sein (success=true).'
			);
			$this->assertArrayHasKey(
				'run_id',
				$thrown->payload,
				'AC-4: Response MUSS run_id-Key enthalten.'
			);
			$this->assertArrayHasKey(
				'state',
				$thrown->payload,
				'AC-4: Response MUSS state-Key enthalten.'
			);
			$this->assertNull(
				$thrown->payload['run_id'],
				'AC-4: run_id darf null sein in dieser Slice (Worker erstellt die Row erst bei Tick).'
			);
			$this->assertSame(
				'queued',
				$thrown->payload['state'],
				'AC-4: state MUSS "queued" sein.'
			);
		}

		// ===================================================================
		// AC-5: Cap/Nonce fail -> wp_send_json_error 403 + KEIN Enqueue.
		// ===================================================================

		public function test_sync_now_handle_aborts_without_capability(): void
		{
			// Cap fehlt -> ensureCapability() wirft via wp_die.
			Functions\when( 'current_user_can' )->alias(
				static fn( string $cap ): bool => false
			);
			// Nonce-Check darf nicht stattfinden.
			Functions\when( 'check_ajax_referer' )->justReturn( true );

			$_POST = [
				'action'      => 'spreadconnect_sync_now',
				'_ajax_nonce' => 'nonce_42',
			];

			$thrown = null;
			try {
				SyncNow::handle();
			} catch ( \RuntimeException $e ) {
				// `wp_die` wirft RuntimeException via tests/stubs/wp-functions.php.
				$thrown = $e;
			} catch ( Slice26JsonSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull(
				$thrown,
				'AC-5: Handler MUSS terminieren wenn Cap fehlt (entweder wp_die oder wp_send_json_error).'
			);

			$this->assertSame(
				[],
				$this->enqueuedActions,
				'AC-5: as_enqueue_async_action darf NICHT aufgerufen werden bei fehlender Cap.'
			);
		}

		public function test_sync_now_handle_aborts_with_invalid_nonce(): void
		{
			Functions\when( 'current_user_can' )->alias(
				static fn( string $cap ): bool => $cap === 'manage_woocommerce'
			);
			// Nonce-Check schlaegt fehl.
			Functions\when( 'check_ajax_referer' )->alias(
				static fn( string $action, string $field = '_wpnonce', bool $die = true ) => false
			);

			$_POST = [
				'action' => 'spreadconnect_sync_now',
				// Kein _ajax_nonce.
			];

			$thrown = null;
			try {
				SyncNow::handle();
			} catch ( Slice26JsonSentinel $e ) {
				$thrown = $e;
			}

			$this->assertNotNull(
				$thrown,
				'AC-5: Handler MUSS via wp_send_json_error terminieren bei invalider Nonce.'
			);
			$this->assertFalse(
				$thrown->success,
				'AC-5: Termination MUSS wp_send_json_error (success=false) sein.'
			);
			$this->assertSame(
				403,
				$thrown->status,
				'AC-5: HTTP-Status MUSS 403 sein bei invalider Nonce.'
			);
			$this->assertSame(
				[],
				$this->enqueuedActions,
				'AC-5: as_enqueue_async_action darf NICHT aufgerufen werden bei invalider Nonce.'
			);
		}

		public function test_sync_now_uses_exact_nonce_action_string(): void
		{
			Functions\when( 'current_user_can' )->alias(
				static fn( string $cap ): bool => $cap === 'manage_woocommerce'
			);

			$captured = [];
			Functions\when( 'check_ajax_referer' )->alias(
				static function ( $action, $field = '_wpnonce', $die = true ) use ( &$captured ) {
					$captured[] = [ 'action' => $action, 'field' => $field, 'die' => $die ];
					return false;
				}
			);

			$_POST = [ 'action' => 'spreadconnect_sync_now' ];

			try {
				SyncNow::handle();
			} catch ( Slice26JsonSentinel $e ) {
				// expected.
			}

			$this->assertCount( 1, $captured, 'AC-5: check_ajax_referer MUSS genau einmal aufgerufen werden.' );
			$this->assertSame(
				'spreadconnect_sync_now',
				$captured[0]['action'],
				'AC-5/Constraint: Nonce-Action-String MUSS exakt "spreadconnect_sync_now" sein.'
			);
			$this->assertFalse(
				$captured[0]['die'],
				'AC-5: 3. Argument MUSS false sein (Handler shaped Response selbst).'
			);
		}

		// ===================================================================
		// AC-6: register_rest_route mit korrektem namespace+route+methods+
		//        callback+permission_callback.
		// ===================================================================

		public function test_sync_progress_registers_rest_route_with_capability_callback(): void
		{
			SyncProgress::register();

			$this->assertCount(
				1,
				$this->restRoutes,
				'AC-6: register_rest_route MUSS GENAU EINMAL aufgerufen werden.'
			);

			[ $namespace, $route, $args ] = $this->restRoutes[0];

			$this->assertSame(
				'spreadconnect/v1',
				$namespace,
				'AC-6: REST-Namespace MUSS exakt "spreadconnect/v1" sein.'
			);
			$this->assertSame(
				'/sync-progress',
				$route,
				'AC-6: REST-Route MUSS exakt "/sync-progress" sein.'
			);

			$this->assertArrayHasKey( 'methods', $args, 'AC-6: register_rest_route MUSS methods-Key haben.' );
			$this->assertSame(
				'GET',
				$args['methods'],
				'AC-6: methods MUSS GET sein (READABLE).'
			);

			$this->assertArrayHasKey( 'callback', $args, 'AC-6: args MUSS callback enthalten.' );
			$this->assertIsArray( $args['callback'], 'AC-6: callback MUSS [instance, method] sein.' );
			$this->assertInstanceOf(
				SyncProgress::class,
				$args['callback'][0],
				'AC-6: callback[0] MUSS Instanz von SyncProgress sein.'
			);
			$this->assertSame(
				'handle',
				$args['callback'][1],
				'AC-6: callback[1] MUSS "handle" sein.'
			);

			$this->assertArrayHasKey(
				'permission_callback',
				$args,
				'AC-6: args MUSS permission_callback enthalten.'
			);
			$this->assertIsArray( $args['permission_callback'] );
			$this->assertInstanceOf(
				SyncProgress::class,
				$args['permission_callback'][0],
				'AC-6: permission_callback[0] MUSS Instanz von SyncProgress sein.'
			);
			$this->assertSame(
				'permission',
				$args['permission_callback'][1],
				'AC-6: permission_callback[1] MUSS "permission" sein.'
			);
		}

		// ===================================================================
		// AC-7: handle returnt JSON-Body mit allen 10 Architecture-Keys.
		// ===================================================================

		public function test_sync_progress_handle_returns_full_progress_body(): void
		{
			$wpdb = $this->installFakeWpdb( [
				$this->makeHistoryRow( [
					'id'            => 42,
					'state'         => 'in_progress',
					'started_at'    => '2026-05-03 14:30:00',
					'trigger'       => 'manual',
					'created_count' => 12,
					'updated_count' => 68,
					'skipped_count' => 3,
					'error_count'   => 1,
				] ),
			] );

			$this->transientStore['sc_sync_total_42']    = 100;
			$this->transientStore['sc_sync_log_tail_42'] = [ 'line A', 'line B' ];

			$repo    = new SyncHistoryRepo();
			$handler = new SyncProgress( $repo );

			$request = new \WP_REST_Request( 'GET', '/sync-progress' );
			$request->set_param( 'run_id', 42 );

			$response = $handler->handle( $request );

			$this->assertInstanceOf(
				\WP_REST_Response::class,
				$response,
				'AC-7: handle MUSS WP_REST_Response zurueckgeben (kein WP_Error).'
			);
			$this->assertSame( 200, $response->get_status(), 'AC-7: HTTP-Status MUSS 200 sein.' );

			$body = $response->get_data();
			$this->assertIsArray( $body, 'AC-7: Body MUSS Array sein.' );

			// Exakt die 10 Keys laut Architecture Z. 132 — kein Drift.
			$expectedKeys = [
				'run_id',
				'state',
				'started_at',
				'processed',
				'total',
				'created',
				'updated',
				'skipped',
				'errors',
				'last_log_lines',
			];
			foreach ( $expectedKeys as $key ) {
				$this->assertArrayHasKey(
					$key,
					$body,
					sprintf( 'AC-7: Body MUSS "%s" enthalten.', $key )
				);
			}

			$this->assertSame( 42, $body['run_id'], 'AC-7: run_id MUSS = 42 sein.' );
			$this->assertSame( 'in_progress', $body['state'] );
			$this->assertSame( '2026-05-03 14:30:00', $body['started_at'] );
			$this->assertSame( 12, $body['created'] );
			$this->assertSame( 68, $body['updated'] );
			$this->assertSame( 3, $body['skipped'] );
			$this->assertSame( 1, $body['errors'] );
			$this->assertSame( 100, $body['total'] );
			$this->assertSame( [ 'line A', 'line B' ], $body['last_log_lines'] );
		}

		public function test_sync_progress_handle_processed_equals_counter_sum(): void
		{
			$this->installFakeWpdb( [
				$this->makeHistoryRow( [
					'id'            => 42,
					'created_count' => 12,
					'updated_count' => 68,
					'skipped_count' => 3,
					'error_count'   => 1,
				] ),
			] );

			$handler  = new SyncProgress( new SyncHistoryRepo() );
			$request  = new \WP_REST_Request();
			$request->set_param( 'run_id', 42 );

			$body = $handler->handle( $request )->get_data();

			$this->assertSame(
				12 + 68 + 3 + 1,
				$body['processed'],
				'AC-7: processed MUSS = created+updated+skipped+errors sein.'
			);
		}

		public function test_sync_progress_handle_reads_total_from_transient(): void
		{
			$this->installFakeWpdb( [
				$this->makeHistoryRow( [ 'id' => 99 ] ),
			] );
			$this->transientStore['sc_sync_total_99'] = 250;

			$handler = new SyncProgress( new SyncHistoryRepo() );
			$request = new \WP_REST_Request();
			$request->set_param( 'run_id', 99 );

			$body = $handler->handle( $request )->get_data();

			$this->assertSame(
				250,
				$body['total'],
				'AC-7: total MUSS aus Transient sc_sync_total_{run_id} kommen.'
			);
		}

		public function test_sync_progress_handle_total_falls_back_to_zero_when_transient_missing(): void
		{
			$this->installFakeWpdb( [
				$this->makeHistoryRow( [ 'id' => 99 ] ),
			] );
			// KEIN sc_sync_total_99 im Store.

			$handler = new SyncProgress( new SyncHistoryRepo() );
			$request = new \WP_REST_Request();
			$request->set_param( 'run_id', 99 );

			$body = $handler->handle( $request )->get_data();

			$this->assertSame(
				0,
				$body['total'],
				'AC-7: total MUSS 0 sein wenn Transient fehlt (kein 404, kein null).'
			);
		}

		public function test_sync_progress_handle_reads_log_tail_from_transient(): void
		{
			$this->installFakeWpdb( [
				$this->makeHistoryRow( [ 'id' => 77 ] ),
			] );
			$this->transientStore['sc_sync_log_tail_77'] = [ 'log 1', 'log 2', 'log 3' ];

			$handler = new SyncProgress( new SyncHistoryRepo() );
			$request = new \WP_REST_Request();
			$request->set_param( 'run_id', 77 );

			$body = $handler->handle( $request )->get_data();

			$this->assertSame(
				[ 'log 1', 'log 2', 'log 3' ],
				$body['last_log_lines'],
				'AC-7: last_log_lines MUSS aus Transient sc_sync_log_tail_{run_id} kommen.'
			);
		}

		public function test_sync_progress_handle_log_tail_falls_back_to_empty_array(): void
		{
			$this->installFakeWpdb( [
				$this->makeHistoryRow( [ 'id' => 77 ] ),
			] );
			// KEIN log-tail Transient.

			$handler = new SyncProgress( new SyncHistoryRepo() );
			$request = new \WP_REST_Request();
			$request->set_param( 'run_id', 77 );

			$body = $handler->handle( $request )->get_data();

			$this->assertSame(
				[],
				$body['last_log_lines'],
				'AC-7: last_log_lines MUSS [] sein wenn Transient fehlt (kein Fail-Modus).'
			);
		}

		// ===================================================================
		// AC-8: Default-Pfad (kein run_id-Param) -> juengste aktive Row;
		//        fallback recent; fallback idle.
		// ===================================================================

		public function test_sync_progress_handle_resolves_default_to_active_run(): void
		{
			$this->installFakeWpdb( [
				$this->makeHistoryRow( [ 'id' => 1, 'state' => 'complete', 'started_at' => '2026-05-04 10:00:00' ] ),
				$this->makeHistoryRow( [ 'id' => 2, 'state' => 'in_progress', 'started_at' => '2026-05-04 11:00:00' ] ),
				$this->makeHistoryRow( [ 'id' => 3, 'state' => 'pending', 'started_at' => '2026-05-04 12:00:00' ] ),
			] );

			$handler = new SyncProgress( new SyncHistoryRepo() );
			$request = new \WP_REST_Request();
			// KEIN run_id-Param.

			$body = $handler->handle( $request )->get_data();

			// Juengste aktive (id=3, pending). DESC by id.
			$this->assertSame(
				3,
				$body['run_id'],
				'AC-8: Default MUSS juengste aktive Row sein (id=3, pending).'
			);
			$this->assertSame( 'pending', $body['state'] );
		}

		public function test_sync_progress_handle_falls_back_to_recent_finished_when_no_active(): void
		{
			$this->installFakeWpdb( [
				$this->makeHistoryRow( [ 'id' => 1, 'state' => 'complete', 'started_at' => '2026-05-04 10:00:00' ] ),
				$this->makeHistoryRow( [ 'id' => 2, 'state' => 'complete', 'started_at' => '2026-05-04 11:00:00' ] ),
				$this->makeHistoryRow( [ 'id' => 3, 'state' => 'failed', 'started_at' => '2026-05-04 12:00:00' ] ),
			] );

			$handler = new SyncProgress( new SyncHistoryRepo() );
			$body    = $handler->handle( new \WP_REST_Request() )->get_data();

			$this->assertSame(
				3,
				$body['run_id'],
				'AC-8: Ohne aktive Row MUSS juengste finished (id=3) zurueckgegeben werden.'
			);
		}

		public function test_sync_progress_handle_returns_idle_when_no_history(): void
		{
			$this->installFakeWpdb( [] );

			$handler  = new SyncProgress( new SyncHistoryRepo() );
			$response = $handler->handle( new \WP_REST_Request() );

			$this->assertInstanceOf( \WP_REST_Response::class, $response, 'AC-8: Empty table MUSS WP_REST_Response liefern (KEIN 404).' );
			$this->assertSame( 200, $response->get_status(), 'AC-8: HTTP-Status MUSS 200 sein bei leerer History.' );

			$body = $response->get_data();
			$this->assertNull( $body['run_id'], 'AC-8: run_id MUSS null sein im idle-Body.' );
			$this->assertSame( 'idle', $body['state'], 'AC-8: state MUSS "idle" sein im Default-Body.' );
			$this->assertSame( 0, $body['processed'] );
			$this->assertSame( 0, $body['total'] );
			$this->assertSame( 0, $body['created'] );
			$this->assertSame( 0, $body['updated'] );
			$this->assertSame( 0, $body['skipped'] );
			$this->assertSame( 0, $body['errors'] );
			$this->assertSame( [], $body['last_log_lines'] );
		}

		// ===================================================================
		// AC-9: Spezifischer run_id existiert nicht -> 404 sync_run_not_found.
		// ===================================================================

		public function test_sync_progress_handle_returns_404_for_missing_specific_run(): void
		{
			$this->installFakeWpdb( [
				$this->makeHistoryRow( [ 'id' => 1, 'state' => 'complete' ] ),
			] );

			$handler = new SyncProgress( new SyncHistoryRepo() );
			$request = new \WP_REST_Request();
			$request->set_param( 'run_id', 99999 );

			$response = $handler->handle( $request );

			$this->assertInstanceOf(
				\WP_Error::class,
				$response,
				'AC-9: Spezifischer fehlender run_id MUSS WP_Error liefern (KEIN Fallback auf default).'
			);
			$this->assertSame(
				'sync_run_not_found',
				$response->get_error_code(),
				'AC-9: WP_Error-Code MUSS exakt "sync_run_not_found" sein.'
			);

			$data = $response->get_error_data();
			$this->assertIsArray( $data );
			$this->assertSame(
				404,
				$data['status'] ?? null,
				'AC-9: WP_Error-Data MUSS status=404 enthalten.'
			);
		}

		// ===================================================================
		// AC-10: permission returnt false ohne manage_woocommerce.
		// ===================================================================

		public function test_sync_progress_permission_returns_false_without_capability(): void
		{
			Functions\when( 'current_user_can' )->alias(
				static fn( string $cap ): bool => false
			);

			$handler = new SyncProgress( new SyncHistoryRepo() );

			$result = $handler->permission( new \WP_REST_Request() );

			$this->assertFalse(
				$result,
				'AC-10: permission() MUSS false zurueckgeben wenn manage_woocommerce fehlt.'
			);
		}

		public function test_sync_progress_permission_returns_true_with_capability(): void
		{
			Functions\when( 'current_user_can' )->alias(
				static fn( string $cap ): bool => $cap === 'manage_woocommerce'
			);

			$handler = new SyncProgress( new SyncHistoryRepo() );

			$result = $handler->permission( new \WP_REST_Request() );

			$this->assertTrue(
				$result,
				'AC-10: permission() MUSS true zurueckgeben mit manage_woocommerce.'
			);
		}

		/**
		 * AC-10: Source-Analyse — `permission()` checks `current_user_can`,
		 * keinen Nonce. Architecture Z. 484: "Read-only AJAX requires capability
		 * only (no nonce)".
		 */
		public function test_sync_progress_permission_does_not_check_nonce(): void
		{
			$source = (string) file_get_contents(
				self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/includes/Hub/Rest/SyncProgress.php'
			);

			// permission()-Methode lokalisieren und sicherstellen, dass kein
			// nonce-Check stattfindet.
			$this->assertStringContainsString(
				'function permission(',
				$source,
				'AC-10: permission()-Methode MUSS existieren.'
			);

			// Reflektiere permission() um nur den Methoden-Body zu inspizieren.
			$reflection = new \ReflectionMethod( SyncProgress::class, 'permission' );
			$startLine  = $reflection->getStartLine();
			$endLine    = $reflection->getEndLine();
			$lines      = explode( "\n", $source );
			$body       = implode( "\n", array_slice( $lines, $startLine - 1, $endLine - $startLine + 1 ) );

			$this->assertStringContainsString(
				'current_user_can',
				$body,
				'AC-10: permission()-Body MUSS current_user_can pruefen.'
			);
			$this->assertStringNotContainsString(
				'check_ajax_referer',
				$body,
				'AC-10: permission() darf KEINEN nonce-Check enthalten (REST-Reads sind cap-only).'
			);
			$this->assertStringNotContainsString(
				'wp_verify_nonce',
				$body,
				'AC-10: permission() darf KEIN wp_verify_nonce enthalten.'
			);
		}

		// ===================================================================
		// AC-12: Bootstrap registriert REST-Init- und AJAX-Hooks.
		// ===================================================================

		public function test_bootstrap_registers_rest_and_ajax_hooks(): void
		{
			// Plugin static state zuruecksetzen.
			$reflection = new ReflectionClass( Plugin::class );
			$initProp   = $reflection->getProperty( 'initialized' );
			$initProp->setValue( null, false );
			$fileProp = $reflection->getProperty( 'pluginFile' );
			$fileProp->setValue( null, '' );

			Plugin::init( '/tmp/spreadconnect-pod-fake.php' );

			// (a) rest_api_init -> SyncProgress::register
			$restInitPriority = Actions\has(
				'rest_api_init',
				[ SyncProgress::class, 'register' ]
			);
			$this->assertNotFalse(
				$restInitPriority,
				'AC-12: add_action("rest_api_init", [SyncProgress::class, "register"]) MUSS registriert sein.'
			);

			// (b) wp_ajax_spreadconnect_sync_now -> SyncNow::handle
			$ajaxPriority = Actions\has(
				'wp_ajax_spreadconnect_sync_now',
				[ SyncNow::class, 'handle' ]
			);
			$this->assertNotFalse(
				$ajaxPriority,
				'AC-12: add_action("wp_ajax_spreadconnect_sync_now", [SyncNow::class, "handle"]) MUSS registriert sein.'
			);
		}

		public function test_bootstrap_routes_catalog_section_to_real_view_class(): void
		{
			// Hub\Controller::SECTIONS-Map MUSS 'catalog' => Hub\View\Catalog::class enthalten.
			$reflection = new ReflectionClass( \SpreadconnectPod\Hub\Controller::class );
			$sections   = $reflection->getReflectionConstants();

			$sectionsValue = null;
			foreach ( $sections as $rc ) {
				if ( $rc->getName() === 'SECTIONS' ) {
					$sectionsValue = $rc->getValue();
					break;
				}
			}

			$this->assertIsArray(
				$sectionsValue,
				'AC-12: Hub\Controller::SECTIONS MUSS als Konstante existieren.'
			);
			$this->assertArrayHasKey(
				'catalog',
				$sectionsValue,
				'AC-12: Section-Map MUSS "catalog"-Key enthalten.'
			);
			$this->assertSame(
				Catalog::class,
				$sectionsValue['catalog'],
				'AC-12: Section "catalog" MUSS auf reale Hub\View\Catalog-Klasse zeigen (kein Stub).'
			);
		}

		/**
		 * AC-12: SyncProgress::ROUTE_NAMESPACE und ROUTE_PATH sind oeffentlich
		 * verfuegbar und korrekt definiert.
		 */
		public function test_sync_progress_constants_exposed_for_consumers(): void
		{
			$this->assertSame(
				'spreadconnect/v1',
				SyncProgress::ROUTE_NAMESPACE,
				'AC-12/Constraints: ROUTE_NAMESPACE MUSS "spreadconnect/v1" sein.'
			);
			$this->assertSame(
				'/sync-progress',
				SyncProgress::ROUTE_PATH,
				'AC-12/Constraints: ROUTE_PATH MUSS "/sync-progress" sein.'
			);
		}

		/**
		 * AC-12: SyncNow::NONCE_ACTION ist oeffentlich verfuegbar.
		 */
		public function test_sync_now_nonce_action_constant_exposed(): void
		{
			$this->assertSame(
				'spreadconnect_sync_now',
				SyncNow::NONCE_ACTION,
				'AC-12/Constraints: NONCE_ACTION MUSS "spreadconnect_sync_now" sein.'
			);
		}

		/**
		 * AC-11 Inline-Script enthaelt POLL_INTERVAL_MS=3000 (Architecture Z. 517).
		 */
		public function test_catalog_render_inline_script_uses_3000ms_poll_interval(): void
		{
			$this->installFakeWpdb( [] );

			ob_start();
			Catalog::render();
			$html = (string) ob_get_clean();

			// Source-Hint: 3000 muss als String/Zahl im JSON-Bootstrap enthalten sein.
			$this->assertStringContainsString(
				'3000',
				$html,
				'AC-11/Constraints: Inline-Script MUSS POLL_INTERVAL_MS=3000 enthalten (Architecture Z. 517).'
			);
		}
	}
}
