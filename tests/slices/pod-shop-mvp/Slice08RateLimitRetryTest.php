<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope, runs once at first include)
// ---------------------------------------------------------------------------
//
// Mirrors Slice 07 bootstrap pattern (mock_external strategy per
// slice-08-rate-limit-retry.md Test-Strategy).
//
//   - `WP_Error` stub class — gated via `class_exists('WP_Error', false)` so
//     it co-exists with the same stub provided by Slice07's test file when
//     both files are loaded in one PHPUnit run.
//   - `wp_json_encode` stub — same gated pattern.
//   - `wp_remote_request`, `wp_remote_retrieve_*`, `is_wp_error` and
//     `wc_get_logger` are mocked per test via Brain\Monkey.
//   - Sleep is intercepted via a TEST SUBCLASS that overrides the protected
//     `sleepSeconds(int)` seam — NEVER a real sleep() in the suite.
// ---------------------------------------------------------------------------

namespace {

	if ( ! class_exists( 'WP_Error', false ) ) {
		/**
		 * Minimal WP_Error stub. Mirrors the public surface used by
		 * SpreadconnectClient (`get_error_message()` + `instanceof` check).
		 */
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

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $options = 0, $depth = 512 ) {
			return json_encode( $data, $options, $depth );
		}
	}
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use SpreadconnectPod\Api\SpreadconnectClient;
	use SpreadconnectPod\Api\SpreadconnectClientError;
	use SpreadconnectPod\Api\SpreadconnectTransientError;
	use WP_Error;

	/**
	 * Slice 08 — HTTP-Client Rate-Limit + Single-429-Retry.
	 *
	 * Acceptance Tests gegen die Slice-Spec
	 * `slice-08-rate-limit-retry.md` (AC-1..AC-11).
	 *
	 * Mocking-Strategy `mock_external` (siehe Spec):
	 *   - Brain\Monkey aliases `wp_remote_request` mit sequenzierten Response-
	 *     Tupeln (initial -> retry).
	 *   - Sleep wird ueber eine Test-Subclass abgegriffen
	 *     (`SleepSpyClient::sleepSeconds()`) — niemals real ausgefuehrt.
	 *   - `wc_get_logger()` Spy zaehlt + capturet Log-Calls.
	 *
	 * Jeder Test ist 1:1 aus einem GIVEN/WHEN/THEN abgeleitet.
	 */
	final class Slice08RateLimitRetryTest extends TestCase
	{
		/**
		 * Per-test logger spy. Reset in setUp().
		 */
		private LoggerSpy08 $loggerSpy;

		/**
		 * Captured `wp_remote_request` invocations: list of `[$url, $args]`.
		 *
		 * @var list<array{0:string,1:array<string,mixed>}>
		 */
		private array $remoteCalls = [];

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->loggerSpy   = new LoggerSpy08();
			$this->remoteCalls = [];

			// `is_wp_error` recognises our stub class.
			Functions\when( 'is_wp_error' )->alias( static function ( $thing ): bool {
				return $thing instanceof WP_Error;
			} );

			// `wc_get_logger` returns the per-test spy.
			$spy = $this->loggerSpy;
			Functions\when( 'wc_get_logger' )->alias( static function () use ( $spy ) {
				return $spy;
			} );

			// `wp_remote_retrieve_response_code` reads `status` from response array.
			Functions\when( 'wp_remote_retrieve_response_code' )->alias( static function ( $resp ): int {
				if ( is_array( $resp ) && isset( $resp['status'] ) ) {
					return (int) $resp['status'];
				}
				return 0;
			} );

			// `wp_remote_retrieve_body` reads `body` key.
			Functions\when( 'wp_remote_retrieve_body' )->alias( static function ( $resp ): string {
				if ( is_array( $resp ) && isset( $resp['body'] ) ) {
					return (string) $resp['body'];
				}
				return '';
			} );

			// `wp_remote_retrieve_headers` reads `headers` key.
			Functions\when( 'wp_remote_retrieve_headers' )->alias( static function ( $resp ) {
				if ( is_array( $resp ) && isset( $resp['headers'] ) ) {
					return $resp['headers'];
				}
				return [];
			} );
		}

		protected function tearDown(): void
		{
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * Configure `get_option` for API-Key + staging-toggle.
		 */
		private function stubOptions( string $apiKey = 'sk-test-123', bool $useStaging = false ): void
		{
			Functions\when( 'get_option' )->alias( static function ( string $name, $default = false ) use ( $apiKey, $useStaging ) {
				if ( 'spreadconnect_api_key' === $name ) {
					return $apiKey;
				}
				if ( 'spreadconnect_use_staging' === $name ) {
					return $useStaging;
				}
				return $default;
			} );
		}

		/**
		 * Stub `wp_remote_request` with a SEQUENCED queue of responses.
		 * Captures every invocation into `$this->remoteCalls`.
		 *
		 * @param list<mixed> $sequence Responses to return on call 1, 2, 3, …
		 *                              When the queue is exhausted, the LAST
		 *                              entry is repeated (defensive — but the
		 *                              tests assert exact call counts so this
		 *                              should never trigger).
		 */
		private function stubRemoteSequence( array $sequence ): void
		{
			$captures = &$this->remoteCalls;
			$queue    = $sequence;

			Functions\when( 'wp_remote_request' )->alias( static function ( $url, $args ) use ( &$captures, &$queue ) {
				$captures[] = [ (string) $url, (array) $args ];

				if ( count( $queue ) > 1 ) {
					return array_shift( $queue );
				}
				// Last (or only) response — return without consuming.
				return $queue[0] ?? [ 'status' => 0, 'body' => '', 'headers' => [] ];
			} );
		}

		/**
		 * Build a SleepSpyClient that records `sleepSeconds()` invocations.
		 */
		private function buildClient(): SleepSpyClient
		{
			return new SleepSpyClient();
		}

		// ===================================================================
		// AC-1: 429 + Retry-After:2 -> sleep(2) + 1 Retry, der bei 200 success returned
		// ===================================================================

		/**
		 * AC-1: GIVEN wp_remote_request liefert HTTP 429 mit Header
		 *       X-RateLimit-Retry-After-Seconds: 2 und Body {}
		 *       WHEN SpreadconnectClient::request('GET','/articles', null) aufgerufen wird
		 *       THEN wp_remote_request wird genau zweimal aufgerufen, dazwischen
		 *            sleep(2); zweiter Call liefert 200; request() returned
		 *            das 2xx-Result-Array (status/body/headers).
		 */
		public function test_429_with_retry_after_triggers_one_retry_and_returns_2xx(): void
		{
			$this->stubOptions();
			$this->stubRemoteSequence( [
				// Call 1: 429 with Retry-After: 2
				[
					'status'  => 429,
					'body'    => '{}',
					'headers' => [
						'X-RateLimit-Retry-After-Seconds' => '2',
					],
				],
				// Call 2: success
				[
					'status'  => 200,
					'body'    => '{"items":[]}',
					'headers' => [
						'Content-Type' => 'application/json',
					],
				],
			] );

			$client = $this->buildClient();
			$result = $client->request( 'GET', '/articles', null );

			// Exactly two HTTP attempts (initial + 1 retry, no 3rd attempt).
			self::assertCount( 2, $this->remoteCalls, 'Exactly 2 wp_remote_request calls expected (1 initial + 1 retry).' );

			// Sleep called between the two attempts with 2 seconds.
			self::assertSame( [ 2 ], $client->sleepCalls, 'Single sleep with 2 seconds between attempts expected.' );

			// 2xx-tuple returned per Slice 07 shape.
			self::assertIsArray( $result );
			self::assertSame( 200, $result['status'] );
			self::assertSame( [ 'items' => [] ], $result['body'] );
			self::assertIsArray( $result['headers'] );
			// Header keys lower-cased per Slice 07 contract.
			self::assertArrayHasKey( 'content-type', $result['headers'] );
		}

		// ===================================================================
		// AC-2: doppeltes 429 wirft SpreadconnectTransientError 'http_429'
		// ===================================================================

		/**
		 * AC-2: GIVEN beide Calls liefern HTTP 429
		 *       WHEN request(...) durchlaeuft den Retry-Pfad
		 *       THEN SpreadconnectTransientError 'http_429';
		 *            wp_remote_request genau zweimal (kein 3. Versuch);
		 *            getStatusCode()=429, getEndpointPath()='/articles'.
		 */
		public function test_double_429_throws_transient_after_exactly_two_attempts(): void
		{
			$this->stubOptions();
			$this->stubRemoteSequence( [
				[
					'status'  => 429,
					'body'    => '{}',
					'headers' => [ 'X-RateLimit-Retry-After-Seconds' => '1' ],
				],
				[
					'status'  => 429,
					'body'    => '{}',
					'headers' => [ 'X-RateLimit-Retry-After-Seconds' => '1' ],
				],
			] );

			$client = $this->buildClient();

			$caught = null;
			try {
				$client->request( 'GET', '/articles', null );
			} catch ( SpreadconnectTransientError $e ) {
				$caught = $e;
			}

			self::assertInstanceOf(
				SpreadconnectTransientError::class,
				$caught,
				'Second consecutive 429 must throw SpreadconnectTransientError.'
			);
			self::assertSame( 'http_429', $caught->getAppCode() );
			self::assertSame( 429, $caught->getStatusCode() );
			self::assertSame( '/articles', $caught->getEndpointPath() );

			// Exactly TWO attempts — no third try.
			self::assertCount( 2, $this->remoteCalls, 'No third HTTP attempt allowed after second 429.' );
		}

		// ===================================================================
		// AC-3: 429 ohne Retry-After-Header / 0 / non-numeric -> Default 1s
		// ===================================================================

		/**
		 * AC-3: GIVEN HTTP 429 ohne X-RateLimit-Retry-After-Seconds-Header
		 *             (oder Wert "0"/non-numerisch)
		 *       WHEN der Retry vorbereitet wird
		 *       THEN Default-Sleep 1 Sekunde; bei zweitem 429 -> Transient.
		 *
		 * Test deckt drei Varianten ab: (a) Header fehlt, (b) Wert "0",
		 * (c) Wert "abc". In allen drei Faellen muss sleep(1) angewendet
		 * werden, dann Transient nach 2. 429.
		 */
		public function test_429_without_retry_after_uses_default_one_second_sleep(): void
		{
			// (a) Header completely absent.
			$this->stubOptions();
			$this->stubRemoteSequence( [
				[ 'status' => 429, 'body' => '{}', 'headers' => [] ],
				[ 'status' => 429, 'body' => '{}', 'headers' => [] ],
			] );

			$client = $this->buildClient();
			try {
				$client->request( 'GET', '/articles', null );
				self::fail( 'Expected SpreadconnectTransientError after double 429.' );
			} catch ( SpreadconnectTransientError $e ) {
				self::assertSame( 'http_429', $e->getAppCode() );
			}

			self::assertSame( [ 1 ], $client->sleepCalls, '(a) Missing header -> default 1s sleep.' );
			self::assertCount( 2, $this->remoteCalls );

			// (b) Header value "0".
			$this->remoteCalls = [];
			$this->stubRemoteSequence( [
				[ 'status' => 429, 'body' => '{}', 'headers' => [ 'X-RateLimit-Retry-After-Seconds' => '0' ] ],
				[ 'status' => 429, 'body' => '{}', 'headers' => [ 'X-RateLimit-Retry-After-Seconds' => '0' ] ],
			] );
			$client = $this->buildClient();
			try {
				$client->request( 'GET', '/articles', null );
				self::fail( 'Expected SpreadconnectTransientError after double 429.' );
			} catch ( SpreadconnectTransientError $e ) {
				// expected
			}
			self::assertSame( [ 1 ], $client->sleepCalls, '(b) Header "0" -> default 1s sleep.' );

			// (c) Non-numeric header value.
			$this->remoteCalls = [];
			$this->stubRemoteSequence( [
				[ 'status' => 429, 'body' => '{}', 'headers' => [ 'X-RateLimit-Retry-After-Seconds' => 'abc' ] ],
				[ 'status' => 429, 'body' => '{}', 'headers' => [ 'X-RateLimit-Retry-After-Seconds' => 'abc' ] ],
			] );
			$client = $this->buildClient();
			try {
				$client->request( 'GET', '/articles', null );
				self::fail( 'Expected SpreadconnectTransientError after double 429.' );
			} catch ( SpreadconnectTransientError $e ) {
				// expected
			}
			self::assertSame( [ 1 ], $client->sleepCalls, '(c) Non-numeric header -> default 1s sleep.' );
		}

		// ===================================================================
		// AC-4: Retry-After 600 -> Cap auf 30s + WARN-Log mit Cap-Notice
		// ===================================================================

		/**
		 * AC-4: GIVEN X-RateLimit-Retry-After-Seconds: 600
		 *       WHEN Retry vorbereitet wird
		 *       THEN Sleep auf 30s gecapped; wp_remote_request genau zweimal;
		 *            WARN-Log enthaelt Hinweis auf gecappten Wert.
		 */
		public function test_retry_after_above_cap_is_clamped_to_max(): void
		{
			$this->stubOptions();
			$this->stubRemoteSequence( [
				[
					'status'  => 429,
					'body'    => '{}',
					'headers' => [ 'X-RateLimit-Retry-After-Seconds' => '600' ],
				],
				[
					'status'  => 200,
					'body'    => '{}',
					'headers' => [],
				],
			] );

			$client = $this->buildClient();
			$result = $client->request( 'GET', '/articles', null );

			// Sleep capped at 30s — never the raw 600.
			self::assertSame( [ 30 ], $client->sleepCalls, 'Retry-After: 600 must be capped to 30s.' );

			// Exactly two HTTP attempts.
			self::assertCount( 2, $this->remoteCalls );

			// 2xx success returned.
			self::assertSame( 200, $result['status'] );

			// WARN log emitted with cap notice. Filter to just WARN-level entries
			// to be robust against the trailing INFO log on the final 200.
			$warnLogs = array_values( array_filter(
				$this->loggerSpy->calls,
				static fn( array $c ): bool => 'warning' === $c['level']
			) );
			self::assertCount( 1, $warnLogs, 'Exactly one WARN log expected on the retry path.' );
			$msg = $warnLogs[0]['message'];
			self::assertStringContainsString( '429', $msg );
			self::assertStringContainsString( '/articles', $msg );
			// Cap-Notice marker — Implementation uses "capped from" or "max".
			self::assertTrue(
				str_contains( $msg, 'capped' ) || str_contains( $msg, '600' ) || str_contains( $msg, '30' ),
				'WARN message must mention the cap (either "capped from", original value 600, or cap 30): ' . $msg
			);
		}

		// ===================================================================
		// AC-5: X-RateLimit-Remaining=3 -> proactive 1s sleep VOR naechstem Call
		// ===================================================================

		/**
		 * AC-5: GIVEN erfolgreicher Call (200) liefert X-RateLimit-Remaining: 3
		 *       WHEN derselbe Client einen 2. request() macht
		 *       THEN vor dem 2. wp_remote_request -> sleep(1).
		 *            Vor dem 1. Call -> KEIN Pre-Sleep.
		 */
		public function test_low_rate_limit_remaining_triggers_proactive_sleep_on_next_call(): void
		{
			$this->stubOptions();
			$this->stubRemoteSequence( [
				// Call 1: 200 with X-RateLimit-Remaining: 3
				[
					'status'  => 200,
					'body'    => '{}',
					'headers' => [ 'X-RateLimit-Remaining' => '3' ],
				],
				// Call 2: 200 (no rate-limit info)
				[
					'status'  => 200,
					'body'    => '{}',
					'headers' => [],
				],
			] );

			$client = $this->buildClient();

			// First call — must NOT trigger any sleep (no prior knowledge).
			$client->request( 'GET', '/articles', null );
			self::assertCount( 1, $this->remoteCalls );
			self::assertSame( [], $client->sleepCalls, 'First call must NOT pre-sleep (no prior knowledge).' );

			// Second call — must trigger proactive 1s sleep BEFORE wp_remote_request.
			$client->request( 'GET', '/articles', null );
			self::assertCount( 2, $this->remoteCalls );
			self::assertSame( [ 1 ], $client->sleepCalls, 'Second call must pre-sleep 1s after Remaining=3.' );
		}

		// ===================================================================
		// AC-6: X-RateLimit-Remaining=6 -> KEIN proactive sleep (Schwelle <=5, nicht <5)
		// ===================================================================

		/**
		 * AC-6: GIVEN vorheriger Call lieferte X-RateLimit-Remaining: 6
		 *       WHEN Folgecall stattfindet
		 *       THEN KEIN proaktiver Sleep (Schwelle ist <=5, nicht <5).
		 */
		public function test_rate_limit_remaining_above_threshold_does_not_sleep(): void
		{
			$this->stubOptions();
			$this->stubRemoteSequence( [
				[
					'status'  => 200,
					'body'    => '{}',
					'headers' => [ 'X-RateLimit-Remaining' => '6' ],
				],
				[
					'status'  => 200,
					'body'    => '{}',
					'headers' => [],
				],
			] );

			$client = $this->buildClient();
			$client->request( 'GET', '/articles', null );
			$client->request( 'GET', '/articles', null );

			self::assertCount( 2, $this->remoteCalls );
			self::assertSame(
				[],
				$client->sleepCalls,
				'Remaining=6 is above threshold (<=5) — no proactive sleep.'
			);
		}

		// ===================================================================
		// AC-7: Header fehlt -> KEIN proactive sleep, KEIN Reset
		// ===================================================================

		/**
		 * AC-7: GIVEN vorheriges Response enthielt keinen X-RateLimit-Remaining
		 *       WHEN Folgecall stattfindet
		 *       THEN KEIN proaktiver Sleep (defensives Default — Header-Fehlen
		 *            != 0).
		 */
		public function test_missing_rate_limit_header_skips_proactive_sleep(): void
		{
			$this->stubOptions();
			$this->stubRemoteSequence( [
				// Call 1: 200, NO rate-limit header.
				[
					'status'  => 200,
					'body'    => '{}',
					'headers' => [],
				],
				// Call 2: 200.
				[
					'status'  => 200,
					'body'    => '{}',
					'headers' => [],
				],
			] );

			$client = $this->buildClient();
			$client->request( 'GET', '/articles', null );
			$client->request( 'GET', '/articles', null );

			self::assertCount( 2, $this->remoteCalls );
			self::assertSame(
				[],
				$client->sleepCalls,
				'Missing X-RateLimit-Remaining must be treated as "no info" (no sleep).'
			);
		}

		// ===================================================================
		// AC-8: 404 -> SpreadconnectClientError, KEIN Retry, exakt 1 Call
		// ===================================================================

		/**
		 * AC-8: GIVEN wp_remote_request liefert HTTP 404
		 *       WHEN request(...) ausgefuehrt wird
		 *       THEN KEIN Retry (genau 1 Call); SpreadconnectClientError
		 *            'http_4xx'.
		 */
		public function test_4xx_status_throws_client_error_without_retry(): void
		{
			$this->stubOptions();
			$this->stubRemoteSequence( [
				[
					'status'  => 404,
					'body'    => '{"error":"not found"}',
					'headers' => [],
				],
			] );

			$client = $this->buildClient();

			$caught = null;
			try {
				$client->request( 'GET', '/articles/9999', null );
			} catch ( SpreadconnectClientError $e ) {
				$caught = $e;
			}

			self::assertInstanceOf( SpreadconnectClientError::class, $caught );
			self::assertSame( 'http_4xx', $caught->getAppCode() );
			self::assertSame( 404, $caught->getStatusCode() );

			// Exactly ONE attempt — no inner retry on 4xx.
			self::assertCount( 1, $this->remoteCalls );
			self::assertSame( [], $client->sleepCalls, 'No sleep on 4xx — inner retry only for 429.' );
		}

		// ===================================================================
		// AC-9: 503 -> SpreadconnectTransientError 'http_5xx', KEIN Retry
		// ===================================================================

		/**
		 * AC-9: GIVEN wp_remote_request liefert HTTP 503
		 *       WHEN request(...)
		 *       THEN KEIN Inner-Retry (genau 1 Call); SpreadconnectTransientError
		 *            'http_5xx' (5xx-Retries delegiert an Action Scheduler).
		 */
		public function test_5xx_status_throws_transient_without_inner_retry(): void
		{
			$this->stubOptions();
			$this->stubRemoteSequence( [
				[
					'status'  => 503,
					'body'    => '',
					'headers' => [],
				],
			] );

			$client = $this->buildClient();

			$caught = null;
			try {
				$client->request( 'GET', '/articles', null );
			} catch ( SpreadconnectTransientError $e ) {
				$caught = $e;
			}

			self::assertInstanceOf( SpreadconnectTransientError::class, $caught );
			self::assertSame( 'http_5xx', $caught->getAppCode() );
			self::assertSame( 503, $caught->getStatusCode() );

			// Exactly ONE attempt — 5xx delegated to AS, no inner retry.
			self::assertCount( 1, $this->remoteCalls );
			self::assertSame( [], $client->sleepCalls, 'No sleep on 5xx — inner retry only for 429.' );
		}

		// ===================================================================
		// AC-10: WP_Error -> SpreadconnectTransientError 'network_error', KEIN Retry
		// ===================================================================

		/**
		 * AC-10: GIVEN wp_remote_request liefert WP_Error (Network/Timeout)
		 *        WHEN request(...) ausgefuehrt wird
		 *        THEN KEIN Inner-Retry (genau 1 Call); SpreadconnectTransientError
		 *             'network_error'. Inner-Retry ist ausschliesslich fuer 429.
		 */
		public function test_wp_error_throws_transient_without_inner_retry(): void
		{
			$this->stubOptions();
			$wpError = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			$this->stubRemoteSequence( [ $wpError ] );

			$client = $this->buildClient();

			$caught = null;
			try {
				$client->request( 'GET', '/articles', null );
			} catch ( SpreadconnectTransientError $e ) {
				$caught = $e;
			}

			self::assertInstanceOf( SpreadconnectTransientError::class, $caught );
			self::assertSame( 'network_error', $caught->getAppCode() );

			// Exactly ONE attempt — WP_Error never triggers an inner retry.
			self::assertCount( 1, $this->remoteCalls );
			self::assertSame( [], $client->sleepCalls, 'No sleep on WP_Error — inner retry only for 429.' );
		}

		// ===================================================================
		// AC-11: 429-Retry-Pfad -> 1 WARN-Log mit Source/Method/Path/Retry-After,
		//        KEIN Bearer; bei 200-Retry zusaetzlich 1 INFO-Log
		// ===================================================================

		/**
		 * AC-11: GIVEN 429-Retry-Pfad wird durchlaufen (egal ob erfolgreich
		 *              oder erneut 429)
		 *        WHEN Logging stattfindet
		 *        THEN genau 1 WARN-Log mit Source 'spreadconnect-api-client',
		 *             Message enthaelt Method+Path+Retry-After-Sekunden,
		 *             NIEMALS Bearer-Token. Bei erfolgreichem Retry (200)
		 *             zusaetzlich 1 INFO-Log fuer den finalen 2xx
		 *             (Total: 1 WARN + 1 INFO).
		 */
		public function test_429_retry_emits_single_warn_log_without_bearer_token(): void
		{
			$secret = 'sk-secret-must-not-leak-XYZ';
			$this->stubOptions( $secret );
			$this->stubRemoteSequence( [
				[
					'status'  => 429,
					'body'    => '{}',
					'headers' => [ 'X-RateLimit-Retry-After-Seconds' => '2' ],
				],
				[
					'status'  => 200,
					'body'    => '{"items":[]}',
					'headers' => [],
				],
			] );

			$client = $this->buildClient();
			$client->request( 'GET', '/articles', null );

			// Partition logs by level.
			$warnLogs = array_values( array_filter(
				$this->loggerSpy->calls,
				static fn( array $c ): bool => 'warning' === $c['level']
			) );
			$infoLogs = array_values( array_filter(
				$this->loggerSpy->calls,
				static fn( array $c ): bool => 'info' === $c['level']
			) );

			// Exactly ONE WARN log on the retry path.
			self::assertCount( 1, $warnLogs, 'Exactly one WARN log expected for the 429 retry trigger.' );
			$warn = $warnLogs[0];

			// Source is the canonical 'spreadconnect-api-client'.
			self::assertSame(
				'spreadconnect-api-client',
				$warn['context']['source'] ?? null,
				'WARN log must carry source spreadconnect-api-client.'
			);

			// Message contains method, path, and the retry-after seconds.
			self::assertStringContainsString( 'GET', $warn['message'] );
			self::assertStringContainsString( '/articles', $warn['message'] );
			self::assertStringContainsString( '2', $warn['message'], 'Retry-After value (2 s) must appear in WARN message.' );

			// Bearer token must NEVER leak into any log message or context.
			foreach ( $this->loggerSpy->calls as $call ) {
				self::assertStringNotContainsString( $secret, $call['message'], 'API-Key must NOT leak into log message.' );
				self::assertStringNotContainsString( 'Bearer ' . $secret, $call['message'], 'Bearer header must NOT leak into log message.' );
				$contextDump = json_encode( $call['context'] );
				self::assertStringNotContainsString( $secret, (string) $contextDump, 'API-Key must NOT leak into log context.' );
			}

			// Exactly ONE INFO log for the successful retry (final 200).
			self::assertCount( 1, $infoLogs, 'Exactly one INFO log expected for the successful 200 retry.' );
			self::assertSame( 'spreadconnect-api-client', $infoLogs[0]['context']['source'] ?? null );
			self::assertStringContainsString( '200', $infoLogs[0]['message'] );
			self::assertStringContainsString( '/articles', $infoLogs[0]['message'] );
		}
	}

	/**
	 * Subclass of SpreadconnectClient that records every `sleepSeconds()`
	 * invocation into a public list and NEVER sleeps in real time.
	 *
	 * The protected `sleepSeconds(int)` seam is the explicit hook designated
	 * by Slice 08 spec (Constraints + Test Skeletons) for intercepting
	 * sleeps in tests.
	 */
	final class SleepSpyClient extends SpreadconnectClient
	{
		/**
		 * Recorded sleep durations in call-order.
		 *
		 * @var list<int>
		 */
		public array $sleepCalls = [];

		protected function sleepSeconds( int $seconds ): void
		{
			$this->sleepCalls[] = $seconds;
			// Intentionally do NOT call sleep() — tests must run in <1s.
		}
	}

	/**
	 * Minimal logger spy capturing every `log()` call into an array.
	 *
	 * Renamed `LoggerSpy08` (instead of `LoggerSpy`) to avoid a class-name
	 * collision with the same-named class declared in Slice07's test file
	 * when both files are loaded into one PHPUnit run.
	 */
	final class LoggerSpy08
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
