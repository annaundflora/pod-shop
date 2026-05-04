<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope, runs once at first include)
// ---------------------------------------------------------------------------
//
// `SpreadconnectClient` references the `WP_Error` class for network-level
// failure detection. We do NOT load WordPress in unit tests (mock_external
// strategy per slice-07 spec); a minimal stub class is provided here that
// mirrors the public surface used by the client.
//
// `wp_remote_request`, `wp_remote_retrieve_*`, `is_wp_error` and
// `wc_get_logger` are mocked per test via Brain\Monkey's `Functions\when()`
// / `Functions\expect()`. `get_option` is mocked the same way.
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
		/**
		 * Minimal `wp_json_encode` stub — WP's wrapper just calls
		 * `json_encode` with safe defaults; we mirror that behaviour.
		 *
		 * @param mixed $data
		 * @param int   $options
		 * @param int   $depth
		 * @return string|false
		 */
		function wp_json_encode( $data, $options = 0, $depth = 512 ) {
			return json_encode( $data, $options, $depth );
		}
	}
}

namespace SpreadconnectPod\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use RuntimeException;
	use SpreadconnectPod\Api\SpreadconnectClient;
	use SpreadconnectPod\Api\SpreadconnectClientError;
	use SpreadconnectPod\Api\SpreadconnectTransientError;
	use WP_Error;

	/**
	 * Slice 07 — HTTP-Client Basis (Bearer-Auth + Base-URL-Toggle).
	 *
	 * Acceptance Tests gegen die Slice-Spec
	 * `slice-07-http-client-base.md`. Mocking-Strategy `mock_external`:
	 *   - Brain\Monkey aliases `wp_remote_request` / `wp_remote_retrieve_*` /
	 *     `get_option` / `is_wp_error` mit fixen Returns.
	 *   - `wc_get_logger()` wird per Test gestubbt um Log-Calls zu capturen
	 *     (Spy-Klasse {@see LoggerSpy}).
	 *
	 * Jeder Test ist 1:1 aus einem GIVEN/WHEN/THEN abgeleitet.
	 */
	final class Slice07HttpClientBaseTest extends TestCase
	{
		/**
		 * Per-test logger spy. Reset in setUp().
		 */
		private LoggerSpy $loggerSpy;

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

			$this->loggerSpy   = new LoggerSpy();
			$this->remoteCalls = [];

			// Default `is_wp_error` impl — recognises our stub class.
			Functions\when( 'is_wp_error' )->alias( static function ( $thing ): bool {
				return $thing instanceof WP_Error;
			} );

			// Default `wc_get_logger` impl — returns the per-test spy.
			$spy = $this->loggerSpy;
			Functions\when( 'wc_get_logger' )->alias( static function () use ( $spy ) {
				return $spy;
			} );

			// `wp_remote_retrieve_response_code` reads `status` key from response array.
			Functions\when( 'wp_remote_retrieve_response_code' )->alias( static function ( $resp ): int {
				if ( is_array( $resp ) && isset( $resp['status'] ) ) {
					return (int) $resp['status'];
				}
				return 0;
			} );

			// `wp_remote_retrieve_body` reads `body` key (always string).
			Functions\when( 'wp_remote_retrieve_body' )->alias( static function ( $resp ): string {
				if ( is_array( $resp ) && isset( $resp['body'] ) ) {
					return (string) $resp['body'];
				}
				return '';
			} );

			// `wp_remote_retrieve_headers` reads `headers` key (assoc array).
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
		 * Configure `get_option` Map for the API-Key + staging-toggle.
		 *
		 * @param string $apiKey      Value returned for `spreadconnect_api_key`.
		 * @param bool   $useStaging  Value returned for `spreadconnect_use_staging`.
		 */
		private function stubOptions( string $apiKey, bool $useStaging = false ): void
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
		 * Stub `wp_remote_request` to capture invocations into
		 * `$this->remoteCalls` and return the supplied response.
		 *
		 * @param mixed $response The response array OR a WP_Error instance.
		 */
		private function stubRemoteRequest( $response ): void
		{
			$captures = &$this->remoteCalls;
			Functions\when( 'wp_remote_request' )->alias( static function ( $url, $args ) use ( $response, &$captures ) {
				$captures[] = [ (string) $url, (array) $args ];
				return $response;
			} );
		}

		// ===================================================================
		// AC-1: Prod-Base-URL + Bearer-Header
		// ===================================================================

		/**
		 * AC-1: GIVEN spreadconnect_api_key='sk-test-123' and
		 *       spreadconnect_use_staging=false
		 *       WHEN request('GET','/authentication',null) is called
		 *       THEN wp_remote_request gets URL
		 *            https://rest.spreadconnect.com/authentication, method GET,
		 *            Authorization: Bearer sk-test-123, Accept: application/json
		 *            and no body.
		 */
		public function test_request_uses_prod_base_url_and_bearer_header(): void
		{
			$this->stubOptions( 'sk-test-123', false );
			$this->stubRemoteRequest( [
				'status'  => 200,
				'body'    => '{"ok":true}',
				'headers' => [],
			] );

			$client = new SpreadconnectClient();
			$result = $client->request( 'GET', '/authentication', null );

			self::assertCount( 1, $this->remoteCalls, 'Exactly one wp_remote_request call expected.' );
			[ $url, $args ] = $this->remoteCalls[0];

			self::assertSame( 'https://rest.spreadconnect.com/authentication', $url );
			self::assertSame( 'GET', $args['method'] );
			self::assertArrayHasKey( 'headers', $args );
			self::assertSame( 'Bearer sk-test-123', $args['headers']['Authorization'] );
			self::assertSame( 'application/json', $args['headers']['Accept'] );
			self::assertArrayNotHasKey( 'body', $args, 'GET request must NOT have body key.' );

			self::assertSame( 200, $result['status'] );
		}

		// ===================================================================
		// AC-2: Staging-Toggle switches Base-URL
		// ===================================================================

		/**
		 * AC-2: GIVEN spreadconnect_use_staging=true
		 *       WHEN request('GET','/authentication',null) is called
		 *       THEN URL becomes https://staging.spreadconnect.com/authentication.
		 */
		public function test_staging_toggle_switches_base_url(): void
		{
			$this->stubOptions( 'sk-test-123', true );
			$this->stubRemoteRequest( [
				'status'  => 200,
				'body'    => '{}',
				'headers' => [],
			] );

			$client = new SpreadconnectClient();
			$client->request( 'GET', '/authentication', null );

			self::assertSame(
				'https://staging.spreadconnect.com/authentication',
				$this->remoteCalls[0][0]
			);
		}

		// ===================================================================
		// AC-2: Path-Slash-Normalisation idempotent
		// ===================================================================

		/**
		 * AC-2: Path-Konkatenation strippt fuehrenden Slash idempotent.
		 * `/authentication` and `authentication` produce identical URL.
		 */
		public function test_path_slash_normalization_is_idempotent(): void
		{
			$this->stubOptions( 'sk-test-123', false );
			$this->stubRemoteRequest( [
				'status'  => 200,
				'body'    => '{}',
				'headers' => [],
			] );

			$client = new SpreadconnectClient();
			$client->request( 'GET', '/authentication', null );
			$urlWithSlash = $this->remoteCalls[0][0];

			$this->remoteCalls = [];
			$client->request( 'GET', 'authentication', null );
			$urlWithoutSlash = $this->remoteCalls[0][0];

			self::assertSame( $urlWithSlash, $urlWithoutSlash );
			self::assertSame( 'https://rest.spreadconnect.com/authentication', $urlWithSlash );
		}

		// ===================================================================
		// AC-3: Empty API-Key -> Pre-Flight Throw, no HTTP call
		// ===================================================================

		/**
		 * AC-3: GIVEN spreadconnect_api_key=''
		 *       WHEN request(...) is called
		 *       THEN SpreadconnectClientError 'auth_missing' thrown,
		 *            wp_remote_request NEVER called.
		 */
		public function test_empty_api_key_throws_client_error_without_http_call(): void
		{
			$this->stubOptions( '', false );
			// Configure wp_remote_request to fail loudly if called.
			$captures = &$this->remoteCalls;
			Functions\when( 'wp_remote_request' )->alias( static function ( $url, $args ) use ( &$captures ) {
				$captures[] = [ (string) $url, (array) $args ];
				return [ 'status' => 200, 'body' => '{}', 'headers' => [] ];
			} );

			$client = new SpreadconnectClient();

			$caught = null;
			try {
				$client->request( 'GET', '/authentication', null );
			} catch ( SpreadconnectClientError $e ) {
				$caught = $e;
			}

			self::assertInstanceOf( SpreadconnectClientError::class, $caught );
			self::assertSame( 'auth_missing', $caught->getAppCode() );
			self::assertCount( 0, $this->remoteCalls, 'wp_remote_request must NOT be called on auth_missing.' );
		}

		// ===================================================================
		// AC-4: POST body is JSON-encoded with Content-Type
		// ===================================================================

		/**
		 * AC-4: GIVEN POST request('POST','/orders', payload)
		 *       WHEN request is built
		 *       THEN method='POST', Content-Type='application/json',
		 *            body=json_encode(payload).
		 */
		public function test_post_body_is_json_encoded_with_content_type_header(): void
		{
			$this->stubOptions( 'sk-test-123', false );
			$this->stubRemoteRequest( [
				'status'  => 200,
				'body'    => '{"ok":true}',
				'headers' => [],
			] );

			$payload = [
				'externalOrderReference' => 'WC-42',
				'orderItems'             => [],
			];

			$client = new SpreadconnectClient();
			$client->request( 'POST', '/orders', $payload );

			[ , $args ] = $this->remoteCalls[0];

			self::assertSame( 'POST', $args['method'] );
			self::assertSame( 'application/json', $args['headers']['Content-Type'] );
			self::assertArrayHasKey( 'body', $args );
			self::assertSame( json_encode( $payload ), $args['body'] );
			// Re-assert AC-4 GET-side (no Content-Type / body) covered in dedicated test.
		}

		/**
		 * AC-4: GET request must not carry `body` key nor `Content-Type` header.
		 */
		public function test_get_request_has_no_body_key(): void
		{
			$this->stubOptions( 'sk-test-123', false );
			$this->stubRemoteRequest( [
				'status'  => 200,
				'body'    => '{}',
				'headers' => [],
			] );

			$client = new SpreadconnectClient();
			$client->request( 'GET', '/authentication', null );

			[ , $args ] = $this->remoteCalls[0];

			self::assertArrayNotHasKey( 'body', $args );
			self::assertArrayNotHasKey( 'Content-Type', $args['headers'] );
		}

		// ===================================================================
		// AC-5: 2xx response decoded to structured array tuple
		// ===================================================================

		/**
		 * AC-5: GIVEN 2xx response with body {"ok":true}
		 *       WHEN request returns
		 *       THEN return value is array{status:int, body:array, headers:map<string,string>}.
		 */
		public function test_2xx_response_is_decoded_to_structured_array(): void
		{
			$this->stubOptions( 'sk-test-123', false );
			$this->stubRemoteRequest( [
				'status'  => 200,
				'body'    => '{"ok":true}',
				'headers' => [
					'Content-Type'           => 'application/json',
					'X-RateLimit-Remaining'  => '99',
				],
			] );

			$client = new SpreadconnectClient();
			$result = $client->request( 'GET', '/authentication', null );

			self::assertIsArray( $result );
			self::assertArrayHasKey( 'status', $result );
			self::assertArrayHasKey( 'body', $result );
			self::assertArrayHasKey( 'headers', $result );
			self::assertSame( 200, $result['status'] );
			self::assertSame( [ 'ok' => true ], $result['body'] );
			// Header keys lower-cased per Constraints (Slice 08 lookup contract).
			self::assertArrayHasKey( 'content-type', $result['headers'] );
			self::assertArrayHasKey( 'x-ratelimit-remaining', $result['headers'] );
			self::assertSame( '99', $result['headers']['x-ratelimit-remaining'] );
		}

		// ===================================================================
		// AC-6: 4xx -> SpreadconnectClientError 'http_4xx' (permanent)
		// ===================================================================

		/**
		 * AC-6: GIVEN 404 response
		 *       WHEN request returns
		 *       THEN SpreadconnectClientError thrown with code http_4xx,
		 *            message contains status + path.
		 */
		public function test_4xx_status_throws_client_error_permanent(): void
		{
			$this->stubOptions( 'sk-test-123', false );
			$this->stubRemoteRequest( [
				'status'  => 404,
				'body'    => '{"error":"not found"}',
				'headers' => [],
			] );

			$client = new SpreadconnectClient();

			$caught = null;
			try {
				$client->request( 'GET', '/orders/9999', null );
			} catch ( SpreadconnectClientError $e ) {
				$caught = $e;
			}

			self::assertInstanceOf( SpreadconnectClientError::class, $caught );
			self::assertSame( 'http_4xx', $caught->getAppCode() );
			self::assertSame( 404, $caught->getStatusCode() );
			self::assertSame( '/orders/9999', $caught->getEndpointPath() );
			self::assertStringContainsString( '404', $caught->getMessage() );
			self::assertStringContainsString( '/orders/9999', $caught->getMessage() );
		}

		// ===================================================================
		// AC-7: 5xx -> SpreadconnectTransientError 'http_5xx'
		// ===================================================================

		/**
		 * AC-7: GIVEN 503 response
		 *       WHEN request returns
		 *       THEN SpreadconnectTransientError thrown with code http_5xx.
		 */
		public function test_5xx_status_throws_transient_error(): void
		{
			$this->stubOptions( 'sk-test-123', false );
			$this->stubRemoteRequest( [
				'status'  => 503,
				'body'    => '',
				'headers' => [],
			] );

			$client = new SpreadconnectClient();

			$caught = null;
			try {
				$client->request( 'GET', '/orders', null );
			} catch ( SpreadconnectTransientError $e ) {
				$caught = $e;
			}

			self::assertInstanceOf( SpreadconnectTransientError::class, $caught );
			self::assertSame( 'http_5xx', $caught->getAppCode() );
			self::assertSame( 503, $caught->getStatusCode() );
		}

		// ===================================================================
		// AC-8: WP_Error -> SpreadconnectTransientError 'network_error'
		// ===================================================================

		/**
		 * AC-8: GIVEN WP_Error from wp_remote_request
		 *       WHEN request returns
		 *       THEN SpreadconnectTransientError thrown with code network_error,
		 *            message contains WP_Error::get_error_message() but NEVER
		 *            the Bearer token.
		 */
		public function test_wp_error_is_mapped_to_transient_error(): void
		{
			$this->stubOptions( 'sk-secret-must-not-leak', false );
			$wpError = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			$this->stubRemoteRequest( $wpError );

			$client = new SpreadconnectClient();

			$caught = null;
			try {
				$client->request( 'GET', '/orders', null );
			} catch ( SpreadconnectTransientError $e ) {
				$caught = $e;
			}

			self::assertInstanceOf( SpreadconnectTransientError::class, $caught );
			self::assertSame( 'network_error', $caught->getAppCode() );
			self::assertStringContainsString( 'cURL error 28', $caught->getMessage() );
			self::assertStringNotContainsString(
				'sk-secret-must-not-leak',
				$caught->getMessage(),
				'Bearer token must NEVER appear in exception message.'
			);
		}

		// ===================================================================
		// AC-9: Logger writes with source + redacts authorization
		// ===================================================================

		/**
		 * AC-9: WHEN request runs a 2xx/4xx/5xx path
		 *       THEN exactly ONE Logger::log call is emitted with
		 *            context['source']='spreadconnect-api-client' and the
		 *            message contains method+path+status but NEVER the API-key.
		 */
		public function test_logger_writes_with_source_and_redacts_authorization(): void
		{
			$this->stubOptions( 'sk-secret-XYZ', false );
			$this->stubRemoteRequest( [
				'status'  => 200,
				'body'    => '{}',
				'headers' => [],
			] );

			$client = new SpreadconnectClient();
			$client->request( 'GET', '/authentication', null );

			self::assertCount( 1, $this->loggerSpy->calls, 'Exactly one log call expected.' );
			$call = $this->loggerSpy->calls[0];

			self::assertSame( 'spreadconnect-api-client', $call['context']['source'] );
			self::assertStringContainsString( 'GET', $call['message'] );
			self::assertStringContainsString( '/authentication', $call['message'] );
			self::assertStringContainsString( '200', $call['message'] );
			self::assertStringNotContainsString( 'sk-secret-XYZ', $call['message'] );
			self::assertStringNotContainsString( 'Bearer sk-secret-XYZ', $call['message'] );
		}

		/**
		 * AC-9: Log-Level passt zu Status-Klasse:
		 *       2xx -> info, 4xx -> error, 5xx -> warning.
		 */
		public function test_log_level_matches_response_class(): void
		{
			$this->stubOptions( 'sk-test-123', false );

			// 2xx -> info
			$this->stubRemoteRequest( [
				'status'  => 200,
				'body'    => '{}',
				'headers' => [],
			] );
			( new SpreadconnectClient() )->request( 'GET', '/ok', null );
			self::assertSame( 'info', $this->loggerSpy->calls[0]['level'] );

			// 4xx -> error
			$this->loggerSpy->calls = [];
			$this->stubRemoteRequest( [
				'status'  => 404,
				'body'    => '',
				'headers' => [],
			] );
			try {
				( new SpreadconnectClient() )->request( 'GET', '/missing', null );
				self::fail( 'Expected SpreadconnectClientError on 404.' );
			} catch ( SpreadconnectClientError $e ) {
				// expected
			}
			self::assertSame( 'error', $this->loggerSpy->calls[0]['level'] );

			// 5xx -> warning
			$this->loggerSpy->calls = [];
			$this->stubRemoteRequest( [
				'status'  => 502,
				'body'    => '',
				'headers' => [],
			] );
			try {
				( new SpreadconnectClient() )->request( 'GET', '/up', null );
				self::fail( 'Expected SpreadconnectTransientError on 502.' );
			} catch ( SpreadconnectTransientError $e ) {
				// expected
			}
			self::assertSame( 'warning', $this->loggerSpy->calls[0]['level'] );
		}

		// ===================================================================
		// AC-10: Exception API shape (getStatusCode, getEndpointPath, getAppCode)
		// ===================================================================

		/**
		 * AC-10: Both error classes expose getStatusCode(): ?int,
		 *        getEndpointPath(): ?string, getAppCode(): string.
		 */
		public function test_exception_classes_expose_status_and_path_accessors(): void
		{
			$client = new SpreadconnectClientError( 'http_4xx', 'msg', 404, '/x' );
			$transient = new SpreadconnectTransientError( 'http_5xx', 'msg2', 503, '/y' );

			// Accessors return correct typed values.
			self::assertSame( 'http_4xx', $client->getAppCode() );
			self::assertSame( 404, $client->getStatusCode() );
			self::assertSame( '/x', $client->getEndpointPath() );

			self::assertSame( 'http_5xx', $transient->getAppCode() );
			self::assertSame( 503, $transient->getStatusCode() );
			self::assertSame( '/y', $transient->getEndpointPath() );

			// Null-pre-flight values supported (e.g. auth_missing).
			$pre = new SpreadconnectClientError( 'auth_missing', 'noop', null, null );
			self::assertNull( $pre->getStatusCode() );
			self::assertNull( $pre->getEndpointPath() );
			self::assertSame( 'auth_missing', $pre->getAppCode() );

			// Reflection: methods exist on both classes with correct signature.
			foreach ( [ SpreadconnectClientError::class, SpreadconnectTransientError::class ] as $fqcn ) {
				$ref = new ReflectionClass( $fqcn );
				self::assertTrue( $ref->hasMethod( 'getStatusCode' ), "$fqcn must have getStatusCode()" );
				self::assertTrue( $ref->hasMethod( 'getEndpointPath' ), "$fqcn must have getEndpointPath()" );
				self::assertTrue( $ref->hasMethod( 'getAppCode' ), "$fqcn must have getAppCode()" );
			}
		}

		/**
		 * AC-10: Both exception classes extend \RuntimeException so global
		 * error handlers / Action Scheduler retry logic catch them by parent.
		 */
		public function test_exception_classes_extend_runtime_exception(): void
		{
			$client = new SpreadconnectClientError( 'http_4xx', 'msg' );
			$transient = new SpreadconnectTransientError( 'http_5xx', 'msg2' );

			self::assertInstanceOf( RuntimeException::class, $client );
			self::assertInstanceOf( RuntimeException::class, $transient );

			// String-coded error code is exposed via getAppCode (NOT via int getCode()).
			self::assertIsString( $client->getAppCode() );
			self::assertIsString( $transient->getAppCode() );
		}
	}

	/**
	 * Minimal logger spy capturing every log() call into an array.
	 *
	 * Mirrors the WC_Logger surface used by SpreadconnectClient::log()
	 * (`log( $level, $message, $context )`).
	 */
	final class LoggerSpy
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
