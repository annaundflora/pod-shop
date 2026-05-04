<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 16 — Event-ID-Hasher + Webhook-Log-Insert + ACK 202
//
// Acceptance Tests gegen `slice-16-event-id-hasher.md` (AC-1..AC-11).
//
// Mocking Strategy: `mock_external` (per Slice-Spec):
//   - Brain\Monkey aliases for `as_enqueue_async_action`, `current_time`,
//     `wp_json_encode`, `__`, `is_wp_error`.
//   - In-memory `$wpdb` stub (anonymous class) with configurable
//     `insert()`-returnvalue (1 = fresh, false = duplicate),
//     `last_error` property, `insert_id`, `prepare()`, `get_var()`,
//     `get_row()`, `update()`. No real DB-I/O.
//   - `WP_REST_Request` / `WP_REST_Response` declared idempotently in
//     `namespace { ... }` (mirror of Slice 15) so the controller's
//     `WP_REST_Response` constructor returns a real assertion-friendly
//     object.
//   - `EventIdHasher::compute()` is a pure-domain function — invoked
//     directly without any monkey-patching.
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'WP_REST_Request', false ) ) {
		class WP_REST_Request
		{
			/** @var array<string,string> Lower-cased header map. */
			private array $headers = [];

			private string $body = '';

			private string $method = 'POST';

			private string $route = '';

			/** @var array<string,mixed> */
			private array $params = [];

			public function __construct( string $method = 'POST', string $route = '' ) {
				$this->method = $method;
				$this->route  = $route;
			}

			public function set_body( string $body ): void {
				$this->body = $body;
			}

			public function get_body(): string {
				return $this->body;
			}

			public function set_header( string $name, string $value ): void {
				$this->headers[ strtolower( $name ) ] = $value;
			}

			public function get_header( string $name ): ?string {
				$key = strtolower( $name );
				return $this->headers[ $key ] ?? null;
			}

			/**
			 * @return array<string,array<int,string>>
			 */
			public function get_headers(): array {
				$out = [];
				foreach ( $this->headers as $k => $v ) {
					$out[ $k ] = [ $v ];
				}
				return $out;
			}

			public function get_method(): string {
				return $this->method;
			}

			public function get_route(): string {
				return $this->route;
			}

			public function get_json_params(): array {
				return [];
			}

			public function get_params(): array {
				return $this->params;
			}

			public function get_param( string $key ) {
				return $this->params[ $key ] ?? null;
			}

			public function set_param( string $key, $value ): void {
				$this->params[ $key ] = $value;
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
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use InvalidArgumentException;
	use PHPUnit\Framework\TestCase;
	use SpreadconnectPod\Webhook\EventIdHasher;
	use SpreadconnectPod\Webhook\WebhookController;
	use SpreadconnectPod\Webhook\WebhookLogRepo;
	use WP_REST_Request;
	use WP_REST_Response;

	/**
	 * In-memory `$wpdb` spy used by Slice 16.
	 *
	 * Mirrors the surface that `WebhookLogRepo::insertOrIgnore()`,
	 * `WebhookLogRepo::find()` and `WebhookLogRepo::updateProcessingStatus()`
	 * touch:
	 *   - `prefix` (string)
	 *   - `insert_id` (int)
	 *   - `last_error` (string)
	 *   - `insert(string $table, array $data): int|false`
	 *   - `update(string $table, array $data, array $where, ...): int|false`
	 *   - `prepare(string $sql, ...$args): string`
	 *   - `get_var(string $sql): mixed`
	 *   - `get_row(string $sql, $output): array|null`
	 *
	 * Tests configure the spy per-instance (`$spy->insertReturn = 1` for
	 * fresh, `false` + `$spy->last_error = 'Duplicate entry ...'` for the
	 * UNIQUE-conflict path).
	 */
	final class Slice16WpdbSpy
	{
		public string $prefix = 'wp_';

		public int $insert_id = 0;

		public string $last_error = '';

		/** Configurable insert() return; null = pass-through to default 1. */
		public int|false|null $insertReturn = 1;

		/** Configurable get_var() return for reverse-lookup. */
		public mixed $getVarReturn = null;

		/** Configurable get_row() return for find(). */
		public mixed $getRowReturn = null;

		/** Configurable update() return. */
		public int|false $updateReturn = 1;

		/** @var list<array{table:string,data:array<string,mixed>}> */
		public array $insertCalls = [];

		/** @var list<array{table:string,data:array<string,mixed>,where:array<string,mixed>,format:?array,where_format:?array}> */
		public array $updateCalls = [];

		/** @var list<array{sql:string,args:array<int,mixed>}> */
		public array $prepareCalls = [];

		/** @var list<string> */
		public array $getVarCalls = [];

		/** @var list<array{sql:string,output:string}> */
		public array $getRowCalls = [];

		public function insert( string $table, array $data, array $format = null ): int|false
		{
			$this->insertCalls[] = [ 'table' => $table, 'data' => $data ];
			$ret = $this->insertReturn;
			if ( null === $ret ) {
				$ret = 1;
			}
			if ( false !== $ret && $ret >= 1 ) {
				$this->insert_id = (int) ( $this->insert_id > 0 ? $this->insert_id : 4242 );
			}
			return $ret;
		}

		public function update(
			string $table,
			array $data,
			array $where,
			array $format = null,
			array $where_format = null
		): int|false {
			$this->updateCalls[] = [
				'table'        => $table,
				'data'         => $data,
				'where'        => $where,
				'format'       => $format,
				'where_format' => $where_format,
			];
			return $this->updateReturn;
		}

		public function prepare( string $sql, ...$args ): string
		{
			$this->prepareCalls[] = [ 'sql' => $sql, 'args' => $args ];
			// Cheap %s/%d substitution sufficient for SELECT-id tests.
			$out = $sql;
			foreach ( $args as $a ) {
				$replacement = is_int( $a ) || is_float( $a )
					? (string) $a
					: "'" . addslashes( (string) $a ) . "'";
				$out = preg_replace( '/%[sd]/', $replacement, $out, 1 ) ?? $out;
			}
			return $out;
		}

		public function get_var( string $sql ): mixed
		{
			$this->getVarCalls[] = $sql;
			return $this->getVarReturn;
		}

		public function get_row( string $sql, $output = 'OBJECT' ): mixed
		{
			$this->getRowCalls[] = [ 'sql' => $sql, 'output' => (string) $output ];
			return $this->getRowReturn;
		}
	}

	/**
	 * Slice 16 — Event-ID-Hasher + Webhook-Log-Insert + ACK 202.
	 *
	 * Acceptance Tests gegen `slice-16-event-id-hasher.md` AC-1..AC-11.
	 */
	final class Slice16EventIdHasherTest extends TestCase
	{
		private Slice16WpdbSpy $wpdb;

		/** @var list<array{hook:string,args:array<int,mixed>,group:string}> */
		private array $asEnqueued = [];

		/**
		 * Frozen UTC timestamp returned by every `current_time('mysql', true)`
		 * call; gives the AC-4 row-shape assertion a stable expected value.
		 */
		private const FROZEN_UTC = '2026-05-04 12:34:56';

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->wpdb               = new Slice16WpdbSpy();
			$GLOBALS['wpdb']          = $this->wpdb;
			$this->asEnqueued         = [];

			// ---- i18n / sanitisation identity stubs --------------------
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'is_wp_error' )->alias(
				static fn ( $thing ): bool => $thing instanceof \WP_Error
			);

			// ---- current_time('mysql', true) frozen --------------------
			Functions\when( 'current_time' )->alias(
				static function ( string $type = 'mysql', bool $gmt = false ): string {
					return self::FROZEN_UTC;
				}
			);

			// ---- as_enqueue_async_action capture -----------------------
			$captured = &$this->asEnqueued;
			Functions\when( 'as_enqueue_async_action' )->alias(
				static function ( string $hook, array $args = [], string $group = '' ) use ( &$captured ): int {
					$captured[] = [ 'hook' => $hook, 'args' => $args, 'group' => $group ];
					return count( $captured );
				}
			);
		}

		protected function tearDown(): void
		{
			unset( $GLOBALS['wpdb'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		// ===================================================================
		// AC-1: EventIdHasher::compute liefert sha256(eventType + ':' +
		//        entityId + ':' + sha256(rawBody)) als 64-Hex.
		// ===================================================================

		/**
		 * GIVEN `EventIdHasher::compute($eventType, $entityId, $rawBody)`
		 * WHEN  die Methode laeuft
		 * THEN  liefert sie `hash('sha256', $eventType . ':' . $entityId . ':'
		 *       . hash('sha256', $rawBody))` als 64-Zeichen-Hex-String.
		 */
		public function test_compute_returns_deterministic_sha256_hex_with_inner_body_hash(): void
		{
			$eventType = 'Order.processed';
			$entityId  = 'sc-12345';
			$rawBody   = '{"eventType":"Order.processed","data":{"entity":{"id":"sc-12345"}}}';

			$expected = hash(
				'sha256',
				$eventType . ':' . $entityId . ':' . hash( 'sha256', $rawBody )
			);

			$actual = EventIdHasher::compute( $eventType, $entityId, $rawBody );

			$this->assertSame(
				$expected,
				$actual,
				'AC-1: compute() MUSS sha256(eventType + ":" + entityId + ":" + sha256(rawBody)) liefern.'
			);
			$this->assertSame(
				64,
				strlen( $actual ),
				'AC-1: Output MUSS 64-Hex (SHA-256-Hex, lower-case) sein.'
			);
			$this->assertMatchesRegularExpression(
				'/^[0-9a-f]{64}$/',
				$actual,
				'AC-1: Output MUSS lowercase-Hex sein (default `hash()`-Output, kein binary).'
			);
		}

		/**
		 * AC-1: Identische Inputs -> identischer Hash (Determinismus).
		 */
		public function test_compute_is_stable_for_identical_inputs(): void
		{
			$a = EventIdHasher::compute( 'Order.processed', 'sc-1', 'body' );
			$b = EventIdHasher::compute( 'Order.processed', 'sc-1', 'body' );

			$this->assertSame( $a, $b, 'AC-1: identische Inputs MUSS denselben Hash liefern (Determinismus).' );
		}

		/**
		 * AC-1: Ein-Byte-Aenderung im rawBody -> abweichender Hash (Avalanche).
		 */
		public function test_compute_changes_when_raw_body_changes(): void
		{
			$base    = EventIdHasher::compute( 'Order.processed', 'sc-1', 'body' );
			$flipped = EventIdHasher::compute( 'Order.processed', 'sc-1', 'BODY' );
			$append  = EventIdHasher::compute( 'Order.processed', 'sc-1', 'body ' );

			$this->assertNotSame( $base, $flipped, 'AC-1: Ein-Byte-Aenderung im rawBody MUSS Hash-Avalanche ausloesen.' );
			$this->assertNotSame( $base, $append, 'AC-1: Append-Byte im rawBody MUSS Hash veraendern.' );
		}

		/**
		 * AC-1 (Defense): Aenderung im eventType ODER entityId -> abweichender
		 * Hash (Trenner ":" verhindert Boundary-Kollision).
		 */
		public function test_compute_changes_when_event_type_or_entity_id_changes(): void
		{
			$base = EventIdHasher::compute( 'Order.processed', 'sc-1', 'body' );

			$this->assertNotSame(
				$base,
				EventIdHasher::compute( 'Order.cancelled', 'sc-1', 'body' ),
				'AC-1: Geaenderter eventType MUSS anderen Hash liefern.'
			);
			$this->assertNotSame(
				$base,
				EventIdHasher::compute( 'Order.processed', 'sc-2', 'body' ),
				'AC-1: Geaenderte entityId MUSS anderen Hash liefern.'
			);

			// Boundary-Test: ":" als Separator verhindert dass
			// ('Order.','processed','x') mit ('Order.processed','','x') kollidieren.
			$slidA = EventIdHasher::compute( 'Order.', 'processed', 'body' );
			$slidB = EventIdHasher::compute( 'Order.processed', '_', 'body' );
			$this->assertNotSame(
				$slidA,
				$slidB,
				'AC-1: ":"-Trenner MUSS Boundary-Kollisionen verhindern.'
			);
		}

		// ===================================================================
		// AC-2: Leere eventType ODER entityId -> InvalidArgumentException.
		//        Leerer rawBody ist akzeptiert.
		// ===================================================================

		/**
		 * GIVEN `EventIdHasher::compute` wird mit leerem `$entityId` (oder
		 *       leerem `$eventType`) aufgerufen
		 * WHEN  die Methode laeuft
		 * THEN  wirft sie `\InvalidArgumentException` mit Code
		 *       'spreadconnect_event_id_missing_entity'.
		 */
		public function test_compute_throws_on_missing_event_type(): void
		{
			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessage( 'spreadconnect_event_id_missing_entity' );

			EventIdHasher::compute( '', 'sc-1', 'body' );
		}

		public function test_compute_throws_on_missing_entity_id(): void
		{
			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessage( 'spreadconnect_event_id_missing_entity' );

			EventIdHasher::compute( 'Order.processed', '', 'body' );
		}

		public function test_compute_throws_on_both_event_type_and_entity_id_missing(): void
		{
			$this->expectException( InvalidArgumentException::class );
			$this->expectExceptionMessage( 'spreadconnect_event_id_missing_entity' );

			EventIdHasher::compute( '', '', 'body' );
		}

		/**
		 * AC-2: Leerer rawBody ist akzeptiert (Hash wird trotzdem berechnet).
		 */
		public function test_compute_accepts_empty_raw_body(): void
		{
			$result = EventIdHasher::compute( 'Order.processed', 'sc-1', '' );

			$this->assertSame(
				64,
				strlen( $result ),
				'AC-2: Leerer rawBody MUSS NICHT werfen — Hash wird normal berechnet.'
			);

			$expected = hash(
				'sha256',
				'Order.processed:sc-1:' . hash( 'sha256', '' )
			);
			$this->assertSame(
				$expected,
				$result,
				'AC-2: Empty-Body-Hash MUSS sha256("Order.processed:sc-1:" + sha256("")) entsprechen.'
			);
		}

		// ===================================================================
		// AC-3: handle() parsed JSON, extrahiert eventType + data.entity.id,
		//        ruft EventIdHasher + Repo.
		// ===================================================================

		/**
		 * GIVEN ein WP REST-Request mit valider HMAC (authorize() -> true)
		 *       und JSON-Body `{eventType:"Order.processed",
		 *       data:{entity:{id:"sc-12345"}}}`
		 * WHEN  `WebhookController::handle($request)` laeuft
		 * THEN  parsed der Handler den raw body via `json_decode()`,
		 *       extrahiert `eventType` (Top-Level) und `entityId`
		 *       (`$payload['data']['entity']['id']`), berechnet `event_id`
		 *       via `EventIdHasher::compute(...)` und ruft
		 *       `WebhookLogRepo::insertOrIgnore($row)` mit dem zusammengebauten
		 *       Row-Array.
		 */
		public function test_handle_extracts_event_type_and_entity_id_and_calls_repo(): void
		{
			$rawBody = '{"eventType":"Order.processed","data":{"entity":{"id":"sc-12345"}}}';
			$expectedEventId = hash(
				'sha256',
				'Order.processed:sc-12345:' . hash( 'sha256', $rawBody )
			);

			$this->wpdb->insertReturn = 1;
			$this->wpdb->insert_id    = 777;

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( $rawBody );

			$response = WebhookController::handle( $request );

			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertCount(
				1,
				$this->wpdb->insertCalls,
				'AC-3: handle() MUSS GENAU EINMAL $wpdb->insert() aufrufen (via WebhookLogRepo).'
			);

			$insertedData = $this->wpdb->insertCalls[0]['data'];

			$this->assertSame(
				'Order.processed',
				$insertedData['event_type'] ?? null,
				'AC-3: event_type MUSS aus dem JSON-Top-Level extrahiert werden.'
			);
			$this->assertSame(
				'sc-12345',
				$insertedData['related_entity_id'] ?? null,
				'AC-3: related_entity_id MUSS aus $payload[data][entity][id] extrahiert werden.'
			);
			$this->assertSame(
				$expectedEventId,
				$insertedData['event_id'] ?? null,
				'AC-3: event_id MUSS via EventIdHasher::compute() berechnet werden.'
			);
		}

		// ===================================================================
		// AC-4: insertOrIgnore baut Row mit allen Pflicht-Spalten.
		// ===================================================================

		/**
		 * GIVEN `WebhookLogRepo::insertOrIgnore(array $row)` wird mit
		 *       Row-Daten aufgerufen
		 * WHEN  die Methode laeuft
		 * THEN  baut sie das `$wpdb->insert(prefix.'spreadconnect_webhook_log',
		 *       $row)`-Statement mit Spalten `event_type`, `event_id`,
		 *       `related_entity_type`, `related_entity_id`, `payload`
		 *       (re-encoded JSON via `wp_json_encode`),
		 *       `hmac_status='valid'`, `processing_status='pending'`,
		 *       `received_at=current_time('mysql', true)` (UTC).
		 */
		public function test_insert_or_ignore_builds_row_with_all_required_columns(): void
		{
			$rawBody = '{"eventType":"Order.processed","data":{"entity":{"id":"sc-77"}}}';
			$this->wpdb->insertReturn = 1;
			$this->wpdb->insert_id    = 999;

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( $rawBody );

			WebhookController::handle( $request );

			$this->assertCount( 1, $this->wpdb->insertCalls, 'AC-4: GENAU EIN insert()-Call.' );
			$call = $this->wpdb->insertCalls[0];

			$this->assertSame(
				'wp_spreadconnect_webhook_log',
				$call['table'],
				'AC-4: Table MUSS prefix + "spreadconnect_webhook_log" sein.'
			);

			$row = $call['data'];

			$this->assertArrayHasKey( 'event_type', $row, 'AC-4: Spalte event_type MUSS gesetzt sein.' );
			$this->assertArrayHasKey( 'event_id', $row, 'AC-4: Spalte event_id MUSS gesetzt sein.' );
			$this->assertArrayHasKey( 'related_entity_type', $row, 'AC-4: Spalte related_entity_type MUSS gesetzt sein.' );
			$this->assertArrayHasKey( 'related_entity_id', $row, 'AC-4: Spalte related_entity_id MUSS gesetzt sein.' );
			$this->assertArrayHasKey( 'payload', $row, 'AC-4: Spalte payload MUSS gesetzt sein.' );
			$this->assertArrayHasKey( 'hmac_status', $row, 'AC-4: Spalte hmac_status MUSS gesetzt sein.' );
			$this->assertArrayHasKey( 'processing_status', $row, 'AC-4: Spalte processing_status MUSS gesetzt sein.' );
			$this->assertArrayHasKey( 'received_at', $row, 'AC-4: Spalte received_at MUSS gesetzt sein.' );

			$this->assertSame(
				'valid',
				$row['hmac_status'],
				'AC-4: hmac_status MUSS literal "valid" sein (HMAC-Pre-Gate aus Slice 15).'
			);
			$this->assertSame(
				'pending',
				$row['processing_status'],
				'AC-4: processing_status MUSS literal "pending" sein.'
			);
			$this->assertSame(
				self::FROZEN_UTC,
				$row['received_at'],
				'AC-4: received_at MUSS aus current_time("mysql", true) stammen (UTC).'
			);

			// AC-4: payload ist re-encoded JSON (NICHT raw body).
			$decoded = json_decode( $row['payload'], true );
			$this->assertIsArray(
				$decoded,
				'AC-4: payload MUSS valider JSON-String sein (re-encoded via wp_json_encode).'
			);
			$this->assertSame(
				'Order.processed',
				$decoded['eventType'] ?? null,
				'AC-4: payload-JSON MUSS dieselben Top-Level-Keys wie raw body enthalten.'
			);
		}

		// ===================================================================
		// AC-5: Fresh-Insert -> ['status'=>'inserted', 'log_id'=>insert_id]
		//        + as_enqueue_async_action + 202 [accepted].
		// ===================================================================

		/**
		 * GIVEN `WebhookLogRepo::insertOrIgnore` und `$wpdb->insert()` liefert
		 *       `1` (Fresh-Insert)
		 * WHEN  der Insert erfolgreich war
		 * THEN  liefert die Methode `['status'=>'inserted', 'log_id'=>(int)
		 *       $wpdb->insert_id]`.
		 */
		public function test_insert_or_ignore_returns_inserted_on_fresh_row(): void
		{
			$this->wpdb->insertReturn = 1;
			$this->wpdb->insert_id    = 4711;
			$this->wpdb->last_error   = '';

			$row = [
				'event_type'          => 'Order.processed',
				'event_id'            => str_repeat( 'a', 64 ),
				'related_entity_type' => 'order',
				'related_entity_id'   => 'sc-1',
				'payload'             => '{}',
				'hmac_status'         => 'valid',
				'processing_status'   => 'pending',
				'received_at'         => self::FROZEN_UTC,
			];

			$result = WebhookLogRepo::insertOrIgnore( $row );

			$this->assertSame(
				[ 'status' => 'inserted', 'log_id' => 4711 ],
				$result,
				'AC-5: Fresh-Insert MUSS ["status"=>"inserted", "log_id"=>$wpdb->insert_id] liefern.'
			);
		}

		/**
		 * AC-5: Fresh-Path: handle() schedult as_enqueue_async_action mit
		 *       log_id + Group 'spreadconnect'.
		 */
		public function test_handle_schedules_async_action_on_fresh_insert(): void
		{
			$this->wpdb->insertReturn = 1;
			$this->wpdb->insert_id    = 4711;

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( '{"eventType":"Order.processed","data":{"entity":{"id":"sc-9"}}}' );

			WebhookController::handle( $request );

			$this->assertCount(
				1,
				$this->asEnqueued,
				'AC-5: as_enqueue_async_action MUSS GENAU EINMAL aufgerufen werden bei Fresh-Insert.'
			);

			$call = $this->asEnqueued[0];
			$this->assertSame(
				'spreadconnect/process_webhook_event',
				$call['hook'],
				'AC-5: AS-Hook MUSS "spreadconnect/process_webhook_event" sein.'
			);
			$this->assertSame(
				'spreadconnect',
				$call['group'],
				'AC-5: AS-Group MUSS "spreadconnect" sein (architecture.md Z. 657).'
			);
		}

		/**
		 * AC-5: Fresh-Path: handle() liefert `WP_REST_Response('[accepted]',
		 *       202, ['Content-Type' => 'text/plain; charset=utf-8'])` als
		 *       literal-string.
		 */
		public function test_handle_returns_202_with_literal_accepted_body(): void
		{
			$this->wpdb->insertReturn = 1;
			$this->wpdb->insert_id    = 1;

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( '{"eventType":"Order.processed","data":{"entity":{"id":"sc-9"}}}' );

			$response = WebhookController::handle( $request );

			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame(
				202,
				$response->get_status(),
				'AC-5: Fresh-Insert MUSS HTTP 202 liefern.'
			);
			$this->assertSame(
				'[accepted]',
				$response->get_data(),
				'AC-5: Body MUSS literal-string "[accepted]" sein (KEIN JSON, keine Quotes).'
			);

			$headers = $response->get_headers();
			$this->assertArrayHasKey( 'Content-Type', $headers );
			$this->assertSame(
				'text/plain; charset=utf-8',
				$headers['Content-Type'],
				'AC-5: Content-Type MUSS text/plain; charset=utf-8 sein (KEIN application/json).'
			);
		}

		// ===================================================================
		// AC-6: Duplicate-Insert -> Reverse-Lookup + UPDATE
		//        processing_status='duplicate'.
		// ===================================================================

		/**
		 * GIVEN `$wpdb->insert()` liefert `false` mit `$wpdb->last_error`
		 *       enthaelt 'Duplicate entry' (UNIQUE-Konflikt auf event_id)
		 * WHEN  der Insert kollidiert
		 * THEN  ruft die Methode `$wpdb->get_var($wpdb->prepare("SELECT id
		 *       FROM ... WHERE event_id = %s LIMIT 1", $row[event_id]))` zur
		 *       Reverse-Lookup, ruft `$wpdb->update($table,
		 *       ['processing_status' => 'duplicate'], ['id' => $log_id])`
		 *       und liefert `['status'=>'duplicate', 'log_id'=>(int)$log_id]`.
		 */
		public function test_insert_or_ignore_handles_duplicate_via_reverse_lookup_and_update(): void
		{
			$this->wpdb->insertReturn = false;
			$this->wpdb->last_error   = "Duplicate entry 'abcd...' for key 'uniq_event_id'";
			$this->wpdb->getVarReturn = '999';

			$eventId = str_repeat( 'b', 64 );

			$row = [
				'event_type'          => 'Order.processed',
				'event_id'            => $eventId,
				'related_entity_type' => 'order',
				'related_entity_id'   => 'sc-1',
				'payload'             => '{}',
				'hmac_status'         => 'valid',
				'processing_status'   => 'pending',
				'received_at'         => self::FROZEN_UTC,
			];

			$result = WebhookLogRepo::insertOrIgnore( $row );

			$this->assertSame(
				[ 'status' => 'duplicate', 'log_id' => 999 ],
				$result,
				'AC-6: Duplicate-Insert MUSS ["status"=>"duplicate", "log_id"=>(int)$wpdb->get_var()] liefern.'
			);

			// AC-6: prepare() wurde mit der SELECT-id-Query + event_id als %s aufgerufen.
			$this->assertGreaterThanOrEqual(
				1,
				count( $this->wpdb->prepareCalls ),
				'AC-6: $wpdb->prepare() MUSS fuer die Reverse-Lookup-Query aufgerufen werden.'
			);
			$preparedSelect = null;
			foreach ( $this->wpdb->prepareCalls as $pc ) {
				if ( false !== stripos( $pc['sql'], 'SELECT' ) && false !== stripos( $pc['sql'], 'event_id' ) ) {
					$preparedSelect = $pc;
					break;
				}
			}
			$this->assertNotNull(
				$preparedSelect,
				'AC-6: Es MUSS eine SELECT-event_id-prepare()-Call geben.'
			);
			$this->assertContains(
				$eventId,
				$preparedSelect['args'],
				'AC-6: Der SELECT-prepare() MUSS event_id als %s-Argument bekommen.'
			);

			// AC-6: get_var() wurde aufgerufen.
			$this->assertGreaterThanOrEqual(
				1,
				count( $this->wpdb->getVarCalls ),
				'AC-6: $wpdb->get_var() MUSS aufgerufen werden um die existierende log_id zu holen.'
			);

			// AC-6: update() wurde mit processing_status='duplicate' und id=999 aufgerufen.
			$this->assertGreaterThanOrEqual(
				1,
				count( $this->wpdb->updateCalls ),
				'AC-6: $wpdb->update() MUSS aufgerufen werden um processing_status="duplicate" zu setzen.'
			);
			$updateCall = $this->wpdb->updateCalls[0];
			$this->assertSame(
				'duplicate',
				$updateCall['data']['processing_status'] ?? null,
				'AC-6: update() MUSS processing_status="duplicate" setzen.'
			);
			$this->assertSame(
				999,
				$updateCall['where']['id'] ?? null,
				'AC-6: update()-WHERE MUSS id=$log_id (aus get_var) sein.'
			);
		}

		// ===================================================================
		// AC-7: Duplicate-Path: handle() ruft as_enqueue_async_action NICHT
		//        auf und liefert WP_REST_Response(200, 'duplicate').
		// ===================================================================

		/**
		 * GIVEN `WebhookController::handle` empfaengt
		 *       `['status'=>'duplicate', 'log_id'=>N]` von `insertOrIgnore`
		 * WHEN  der Handler die Antwort baut
		 * THEN  ruft er `as_enqueue_async_action(...)` NICHT auf und liefert
		 *       `WP_REST_Response('duplicate', 200, ['Content-Type'=>
		 *       'text/plain; charset=utf-8'])`.
		 */
		public function test_handle_does_not_schedule_action_on_duplicate(): void
		{
			$this->wpdb->insertReturn = false;
			$this->wpdb->last_error   = 'Duplicate entry for key uniq_event_id';
			$this->wpdb->getVarReturn = '321';

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( '{"eventType":"Order.processed","data":{"entity":{"id":"sc-1"}}}' );

			WebhookController::handle( $request );

			$this->assertCount(
				0,
				$this->asEnqueued,
				'AC-7: as_enqueue_async_action DARF NICHT aufgerufen werden bei Duplicate.'
			);
		}

		public function test_handle_returns_200_with_literal_duplicate_body(): void
		{
			$this->wpdb->insertReturn = false;
			$this->wpdb->last_error   = 'Duplicate entry for key uniq_event_id';
			$this->wpdb->getVarReturn = '321';

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( '{"eventType":"Order.processed","data":{"entity":{"id":"sc-1"}}}' );

			$response = WebhookController::handle( $request );

			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame(
				200,
				$response->get_status(),
				'AC-7: Duplicate-Path MUSS HTTP 200 liefern (NICHT 202).'
			);
			$this->assertSame(
				'duplicate',
				$response->get_data(),
				'AC-7: Body MUSS literal-string "duplicate" sein.'
			);

			$headers = $response->get_headers();
			$this->assertArrayHasKey( 'Content-Type', $headers );
			$this->assertSame(
				'text/plain; charset=utf-8',
				$headers['Content-Type'],
				'AC-7: Content-Type MUSS text/plain; charset=utf-8 sein.'
			);
		}

		// ===================================================================
		// AC-8: Identischer raw body 2x -> 1x 202+[accepted]+1 schedule,
		//        1x 200+duplicate+0 schedules.
		// ===================================================================

		/**
		 * GIVEN identischer raw body wird zwei Mal an `WebhookController::
		 *       handle` geliefert (gleicher eventType, gleiche entity.id,
		 *       byte-identisches Body)
		 * WHEN  beide Requests durchlaufen
		 * THEN  liefert der erste Request 202 + [accepted] + 1x AS-Call.
		 *       Der zweite Request liefert 200 + duplicate + 0x AS-Call.
		 *       In der DB existiert genau eine Zeile mit gemeinsamem
		 *       event_id.
		 */
		public function test_two_identical_requests_yield_one_accepted_one_duplicate(): void
		{
			$rawBody = '{"eventType":"Order.processed","data":{"entity":{"id":"sc-77"}}}';

			// ---- Erster Request: Fresh-Insert (insertReturn=1).
			$this->wpdb->insertReturn = 1;
			$this->wpdb->insert_id    = 5500;
			$this->wpdb->last_error   = '';

			$req1 = new WP_REST_Request( 'POST', '/webhook' );
			$req1->set_body( $rawBody );
			$resp1 = WebhookController::handle( $req1 );

			$this->assertSame( 202, $resp1->get_status(), 'AC-8: Erster Request MUSS 202 liefern.' );
			$this->assertSame( '[accepted]', $resp1->get_data(), 'AC-8: Erster Request MUSS "[accepted]" liefern.' );
			$this->assertCount(
				1,
				$this->asEnqueued,
				'AC-8: Erster Request MUSS GENAU EINMAL as_enqueue_async_action triggern.'
			);

			$firstInsertedRow = $this->wpdb->insertCalls[0]['data'];
			$firstEventId     = $firstInsertedRow['event_id'];

			// ---- Zweiter Request: Duplicate (insertReturn=false +
			// last_error="Duplicate entry"). Reverse-Lookup gibt log_id=5500
			// zurueck (gleiche Row).
			$this->wpdb->insertReturn = false;
			$this->wpdb->last_error   = "Duplicate entry '..' for key 'uniq_event_id'";
			$this->wpdb->getVarReturn = '5500';

			$req2 = new WP_REST_Request( 'POST', '/webhook' );
			$req2->set_body( $rawBody );
			$resp2 = WebhookController::handle( $req2 );

			$this->assertSame( 200, $resp2->get_status(), 'AC-8: Zweiter Request MUSS 200 liefern.' );
			$this->assertSame( 'duplicate', $resp2->get_data(), 'AC-8: Zweiter Request MUSS "duplicate" liefern.' );
			$this->assertCount(
				1,
				$this->asEnqueued,
				'AC-8: Zweiter Request DARF NICHT erneut as_enqueue_async_action triggern (bleibt bei 1 vom ersten Request).'
			);

			// AC-8: Beide Inserts wurden mit demselben event_id versucht
			// (raw body byte-identisch).
			$secondInsertedRow = $this->wpdb->insertCalls[1]['data'];
			$this->assertSame(
				$firstEventId,
				$secondInsertedRow['event_id'],
				'AC-8: Beide Requests MUSS denselben event_id-Hash erzeugen (Determinismus).'
			);
		}

		// ===================================================================
		// AC-9: JSON-Decode-Failure / fehlende Pflichtfelder -> trotzdem
		//        202 + INSERT mit '_unknown' Marker.
		// ===================================================================

		/**
		 * GIVEN `json_decode($request->get_body(), true)` liefert `null`
		 *       (Body ist nicht-JSON oder eventType/data.entity.id fehlt)
		 * WHEN  der Handler die Pflichtfelder pruefen will
		 * THEN  liefert er `WP_REST_Response('[accepted]', 202, ...)` und
		 *       INSERTs trotzdem die Row mit `processing_status='pending'`,
		 *       `event_type='_unknown'`, `related_entity_type='unknown'`,
		 *       `related_entity_id='_'` und `event_id =
		 *       sha256('_unknown:_:'.sha256($rawBody))`.
		 */
		public function test_handle_inserts_unknown_marker_on_invalid_json_and_still_returns_202(): void
		{
			$rawBody = 'not-json-at-all';

			$this->wpdb->insertReturn = 1;
			$this->wpdb->insert_id    = 100;

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( $rawBody );

			$response = WebhookController::handle( $request );

			$this->assertSame(
				202,
				$response->get_status(),
				'AC-9: Bei JSON-Decode-Failure MUSS handle() trotzdem 202 liefern (8s-ACK-Constraint).'
			);
			$this->assertSame(
				'[accepted]',
				$response->get_data(),
				'AC-9: Body MUSS "[accepted]" sein.'
			);

			$this->assertCount(
				1,
				$this->wpdb->insertCalls,
				'AC-9: Auch bei invalid-JSON MUSS GENAU EIN insert() aufgerufen werden.'
			);

			$row = $this->wpdb->insertCalls[0]['data'];
			$this->assertSame(
				'_unknown',
				$row['event_type'],
				'AC-9: event_type MUSS "_unknown"-Marker sein bei invalid-JSON.'
			);
			$this->assertSame(
				'unknown',
				$row['related_entity_type'],
				'AC-9: related_entity_type MUSS "unknown" sein bei invalid-JSON.'
			);
			$this->assertSame(
				'_',
				$row['related_entity_id'],
				'AC-9: related_entity_id MUSS "_"-Marker sein bei invalid-JSON.'
			);

			$expectedEventId = hash(
				'sha256',
				'_unknown:_:' . hash( 'sha256', $rawBody )
			);
			$this->assertSame(
				$expectedEventId,
				$row['event_id'],
				'AC-9: event_id MUSS sha256("_unknown:_:" + sha256($rawBody)) sein.'
			);

			$this->assertSame(
				'pending',
				$row['processing_status'],
				'AC-9: processing_status MUSS "pending" bleiben (Job validiert spaeter, slice-17).'
			);
		}

		/**
		 * AC-9 (Adversarial / Edge): JSON valid aber `eventType` fehlt -> Marker.
		 */
		public function test_handle_inserts_unknown_marker_when_event_type_missing(): void
		{
			$rawBody = '{"data":{"entity":{"id":"sc-1"}}}';

			$this->wpdb->insertReturn = 1;
			$this->wpdb->insert_id    = 101;

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( $rawBody );

			$response = WebhookController::handle( $request );

			$this->assertSame( 202, $response->get_status() );
			$this->assertCount( 1, $this->wpdb->insertCalls );

			$row = $this->wpdb->insertCalls[0]['data'];
			$this->assertSame(
				'_unknown',
				$row['event_type'],
				'AC-9: Fehlender eventType MUSS auf "_unknown"-Marker fallen.'
			);
		}

		/**
		 * AC-9 (Adversarial / Edge): JSON valid aber `data.entity.id` fehlt
		 * -> Marker.
		 */
		public function test_handle_inserts_unknown_marker_when_entity_id_missing(): void
		{
			$rawBody = '{"eventType":"Order.processed","data":{"entity":{}}}';

			$this->wpdb->insertReturn = 1;
			$this->wpdb->insert_id    = 102;

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( $rawBody );

			$response = WebhookController::handle( $request );

			$this->assertSame( 202, $response->get_status() );
			$this->assertCount( 1, $this->wpdb->insertCalls );

			$row = $this->wpdb->insertCalls[0]['data'];
			$this->assertSame(
				'_',
				$row['related_entity_id'],
				'AC-9: Fehlender data.entity.id MUSS auf "_"-Marker fallen.'
			);
		}

		// ===================================================================
		// AC-10: Schedule-Argument-Shape: as_enqueue_async_action erhaelt
		//         [log_id] als positional-array.
		// ===================================================================

		/**
		 * GIVEN der Handler hat erfolgreich Insert+Schedule abgeschlossen
		 * WHEN  die schedule-Action `spreadconnect/process_webhook_event`
		 *       von Action-Scheduler dispatched wird (in Slice 17)
		 * THEN  wird der Hook mit dem Argument $log_id (single positional
		 *       int) aufgerufen — exakt das Argument-Shape, das Slice 17
		 *       `ProcessWebhookEventJob::handle($log_id)` erwartet. Group
		 *       ist 'spreadconnect'.
		 */
		public function test_async_action_scheduled_with_log_id_positional_argument(): void
		{
			$this->wpdb->insertReturn = 1;
			$this->wpdb->insert_id    = 1234;

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( '{"eventType":"Order.processed","data":{"entity":{"id":"sc-1"}}}' );

			WebhookController::handle( $request );

			$this->assertCount( 1, $this->asEnqueued, 'AC-10: GENAU EIN AS-enqueue.' );
			$call = $this->asEnqueued[0];

			$this->assertSame(
				'spreadconnect/process_webhook_event',
				$call['hook'],
				'AC-10: Hook MUSS "spreadconnect/process_webhook_event" sein.'
			);
			$this->assertSame(
				[ 1234 ],
				$call['args'],
				'AC-10: Args MUSS [log_id] als positional-array sein (single positional int).'
			);
			$this->assertCount(
				1,
				$call['args'],
				'AC-10: Genau 1 Argument (log_id), kein Splat / mehrere Args.'
			);
			$this->assertIsInt(
				$call['args'][0],
				'AC-10: log_id MUSS int sein (NICHT string).'
			);
			$this->assertSame(
				'spreadconnect',
				$call['group'],
				'AC-10: Group MUSS "spreadconnect" sein (architecture.md Z. 657).'
			);
		}

		// ===================================================================
		// AC-11: WebhookLogRepo::find / updateProcessingStatus.
		// ===================================================================

		/**
		 * GIVEN `WebhookLogRepo::find(int $log_id)` wird aufgerufen
		 * WHEN  Slice 17 das Row-Loading benoetigt
		 * THEN  liefert die Methode SELECT *-Row als assoc-array (oder null).
		 */
		public function test_repo_find_returns_row_array_or_null(): void
		{
			// Case A: Existing row.
			$this->wpdb->getRowReturn = [
				'id'                => 42,
				'event_type'        => 'Order.processed',
				'event_id'          => str_repeat( 'c', 64 ),
				'processing_status' => 'pending',
			];

			$row = WebhookLogRepo::find( 42 );

			$this->assertIsArray( $row, 'AC-11: find() MUSS array liefern bei vorhandener Row.' );
			$this->assertSame( 42, $row['id'] ?? null );
			$this->assertSame( 'Order.processed', $row['event_type'] ?? null );

			// AC-11: prepare() wurde mit %d und id=42 aufgerufen.
			$preparedSelect = null;
			foreach ( $this->wpdb->prepareCalls as $pc ) {
				if ( false !== stripos( $pc['sql'], 'SELECT' ) && false !== stripos( $pc['sql'], 'WHERE id' ) ) {
					$preparedSelect = $pc;
					break;
				}
			}
			$this->assertNotNull(
				$preparedSelect,
				'AC-11: find() MUSS prepare() mit SELECT ... WHERE id = %d aufrufen.'
			);
			$this->assertContains(
				42,
				$preparedSelect['args'],
				'AC-11: prepare() MUSS log_id als %d bekommen.'
			);

			// Case B: Missing row.
			$this->wpdb->getRowReturn = null;
			$missing = WebhookLogRepo::find( 9999 );

			$this->assertNull(
				$missing,
				'AC-11: find() MUSS null liefern wenn Row nicht existiert.'
			);
		}

		/**
		 * AC-11: WebhookLogRepo::updateProcessingStatus setzt
		 *       processing_status + optional processing_error.
		 */
		public function test_repo_update_processing_status_writes_status_and_error(): void
		{
			// Case A: ohne error.
			WebhookLogRepo::updateProcessingStatus( 42, 'success' );

			$this->assertCount(
				1,
				$this->wpdb->updateCalls,
				'AC-11: updateProcessingStatus(id, status) MUSS GENAU EIN $wpdb->update() triggern.'
			);
			$callA = $this->wpdb->updateCalls[0];
			$this->assertSame(
				'wp_spreadconnect_webhook_log',
				$callA['table'],
				'AC-11: update() MUSS gegen wp_spreadconnect_webhook_log gehen.'
			);
			$this->assertSame(
				'success',
				$callA['data']['processing_status'] ?? null,
				'AC-11: data MUSS processing_status setzen.'
			);
			$this->assertArrayNotHasKey(
				'processing_error',
				$callA['data'],
				'AC-11: ohne error-Argument DARF processing_error NICHT gesetzt werden.'
			);
			$this->assertSame(
				42,
				$callA['where']['id'] ?? null,
				'AC-11: WHERE MUSS id=$logId sein.'
			);

			// Case B: mit error-string.
			WebhookLogRepo::updateProcessingStatus( 43, 'error', 'unknown_event_type' );

			$this->assertCount(
				2,
				$this->wpdb->updateCalls,
				'AC-11: zweiter Aufruf MUSS einen weiteren update()-Call triggern.'
			);
			$callB = $this->wpdb->updateCalls[1];
			$this->assertSame(
				'error',
				$callB['data']['processing_status'] ?? null,
				'AC-11: data MUSS processing_status="error" setzen.'
			);
			$this->assertSame(
				'unknown_event_type',
				$callB['data']['processing_error'] ?? null,
				'AC-11: data MUSS processing_error="unknown_event_type" setzen.'
			);
			$this->assertSame(
				43,
				$callB['where']['id'] ?? null,
				'AC-11: WHERE MUSS id=$logId sein.'
			);
		}
	}
}
