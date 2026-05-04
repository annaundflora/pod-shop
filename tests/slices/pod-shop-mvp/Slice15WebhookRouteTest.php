<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 15 — Webhook-Receiver-Route + HMAC-Verifier
//
// Acceptance Tests gegen `slice-15-webhook-route.md` (AC-1..AC-10).
//
// Mocking Strategy: `mock_external` (per Slice-Spec):
//   - Brain\Monkey aliases fuer WP-REST/Hook-Funktionen
//     (`register_rest_route`, `add_action`, `get_option`, `__`,
//      `is_wp_error`).
//   - In-memory `WP_REST_Request` / `WP_REST_Response` / `WP_REST_Server` /
//     `WP_Error` Stubs (Bootstrap aus Slice 02 stellt diese nicht in
//     einem zentralen File — wir deklarieren sie idempotent in
//     `namespace { ... }` und gaten ueber `class_exists($_, false)`).
//   - Patchwork-Replace fuer `hash_equals` und `hash_hmac` zur Verifikation
//     der constant-time-compare-Nutzung (AC-4 / AC-5 / AC-6 / AC-10).
//     Voraussetzung: `wordpress/plugins/spreadconnect-pod/patchwork.json`
//     listet `hash_equals` und `hash_hmac` als `redefinable-internals`.
//   - `error_log` wird via `ini_set('error_log', $tempfile)` umgeleitet
//     und der File-Inhalt zurueckgelesen (Slice-14-Pattern).
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
			public const READABLE  = 'GET';
			public const CREATABLE = 'POST';
			public const EDITABLE  = 'POST, PUT, PATCH';
			public const DELETABLE = 'DELETE';
		}
	}

	if ( ! class_exists( 'WP_REST_Request', false ) ) {
		class WP_REST_Request
		{
			/** @var array<string,string> Lower-cased header map. */
			private array $headers = [];

			private string $body = '';

			private string $method = 'POST';

			private string $route = '';

			/**
			 * Internal "trip-wire" toggles; `Slice15WebhookRouteTest`
			 * sets these to assert that the controller does NOT touch
			 * the parsed-JSON path (`get_json_params`/`get_params`).
			 */
			public bool $jsonParamsAccessed = false;
			public bool $paramsAccessed     = false;

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
					// WP returns header values as arrays; we mimic that.
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

			/**
			 * Trip-wire — the controller MUST NOT call this on the
			 * raw-body authorisation path (slice-15 AC-2).
			 */
			public function get_json_params(): array {
				$this->jsonParamsAccessed = true;
				return [];
			}

			/**
			 * Trip-wire — same rationale as `get_json_params()`.
			 */
			public function get_params(): array {
				$this->paramsAccessed = true;
				return [];
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
	use Brain\Monkey\Actions;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use SpreadconnectPod\Bootstrap\Plugin;
	use SpreadconnectPod\Webhook\WebhookController;
	use SpreadconnectPod\Webhook\WebhookSignatureVerifier;
	use WP_Error;
	use WP_REST_Request;
	use WP_REST_Response;

	/**
	 * Patchwork-Spy fuer `hash_equals` + `hash_hmac`.
	 *
	 * Beide Internals sind in `wordpress/plugins/spreadconnect-pod/patchwork.json`
	 * unter `redefinable-internals` gelistet. Pro Test wird `setUp()` auf
	 * eine Default-Pass-Through-Behaviour zurueckgesetzt; einzelne Tests
	 * koennen das Verhalten ueberschreiben (z. B. ein Spy, der den Compare
	 * zaehlt aber bei AC-5 echte HMAC-Identitaet erzwingt).
	 */
	final class Slice15HashSpy
	{
		/** @var list<array{0:string,1:string}> Calls captured: `[a, b]` byte strings. */
		public static array $hashEqualsCalls = [];

		/** @var list<array{algo:string,data:string,key:string,raw:bool}> */
		public static array $hashHmacCalls = [];

		/**
		 * If non-null, `hash_equals` returns this fixed value rather than
		 * the original PHP impl. Defaults to null = pass-through.
		 */
		public static ?bool $hashEqualsForce = null;

		/**
		 * If non-null, `hash_hmac` returns this fixed value rather than
		 * the original PHP impl. Defaults to null = pass-through.
		 */
		public static ?string $hashHmacForce = null;

		public static function reset(): void
		{
			self::$hashEqualsCalls = [];
			self::$hashHmacCalls   = [];
			self::$hashEqualsForce = null;
			self::$hashHmacForce   = null;
		}
	}

	/**
	 * Slice 15 — Webhook-Receiver-Route + HMAC-Verifier.
	 */
	final class Slice15WebhookRouteTest extends TestCase
	{
		/** @var array<string,mixed> In-memory option store. */
		private array $options = [];

		/** @var list<array{0:string,1:string,2:array<string,mixed>}> */
		private array $restRoutes = [];

		/** Temp-Datei fuer error_log Capture. */
		private string $errorLogFile = '';

		/** @var string|false */
		private mixed $prevErrorLog = '';

		/** @var string|false */
		private mixed $prevLogErrors = '';

		private static function repoRoot(): string
		{
			return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
		}

		private static function controllerFile(): string
		{
			return self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookController.php';
		}

		private static function verifierFile(): string
		{
			return self::repoRoot()
				. '/wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookSignatureVerifier.php';
		}

		private static function pluginMainFile(): string
		{
			return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';
		}

		private static function resetPluginState(): void
		{
			$reflection = new ReflectionClass( Plugin::class );
			if ( $reflection->hasProperty( 'initialized' ) ) {
				$prop = $reflection->getProperty( 'initialized' );
				$prop->setValue( null, false );
			}
			if ( $reflection->hasProperty( 'pluginFile' ) ) {
				$prop = $reflection->getProperty( 'pluginFile' );
				$prop->setValue( null, '' );
			}
		}

		protected function setUp(): void
		{
			parent::setUp();
			Monkey\setUp();

			$this->options    = [];
			$this->restRoutes = [];
			Slice15HashSpy::reset();
			self::resetPluginState();

			// ---- i18n / sanitisation identity stubs --------------------
			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'esc_attr__' )->returnArg( 1 );

			// ---- Option store (peek -> get_option) ---------------------
			$opts = &$this->options;
			Functions\when( 'get_option' )->alias(
				static function ( $name, $default = false ) use ( &$opts ) {
					return array_key_exists( $name, $opts ) ? $opts[ $name ] : $default;
				}
			);
			Functions\when( 'is_wp_error' )->alias(
				static fn ( $thing ): bool => $thing instanceof \WP_Error
			);

			// ---- register_rest_route capture ---------------------------
			$captured = &$this->restRoutes;
			Functions\when( 'register_rest_route' )->alias(
				static function ( string $namespace, string $route, array $args = [] ) use ( &$captured ): bool {
					$captured[] = [ $namespace, $route, $args ];
					return true;
				}
			);

			// ---- Patchwork-Replace: hash_equals + hash_hmac ------------
			// Beide Internals sind in plugin-root-`patchwork.json` als
			// `redefinable-internals` gelistet (Constraint AC-5 / AC-10 +
			// orchestrator-config Note 3). Default: Pass-Through; Test
			// kann pro Test-Case via `Slice15HashSpy::$hashEqualsForce`
			// einen festen Boolean erzwingen (z. B. fuer AC-5 ohne echtes
			// Secret-Material).
			\Patchwork\redefine(
				'hash_equals',
				static function ( string $known, string $user ): bool {
					Slice15HashSpy::$hashEqualsCalls[] = [ $known, $user ];
					if ( null !== Slice15HashSpy::$hashEqualsForce ) {
						return Slice15HashSpy::$hashEqualsForce;
					}
					// Pass-through: relay to the real implementation via
					// Patchwork's `relay()` so we get genuine constant-
					// time semantics on the real PHP runtime.
					return \Patchwork\relay( [ $known, $user ] );
				}
			);

			\Patchwork\redefine(
				'hash_hmac',
				static function ( string $algo, string $data, string $key, bool $binary = false ): string {
					Slice15HashSpy::$hashHmacCalls[] = [
						'algo' => $algo,
						'data' => $data,
						'key'  => $key,
						'raw'  => $binary,
					];
					if ( null !== Slice15HashSpy::$hashHmacForce ) {
						return Slice15HashSpy::$hashHmacForce;
					}
					return \Patchwork\relay( [ $algo, $data, $key, $binary ] );
				}
			);

			// ---- error_log capture via ini_set --------------------------
			$this->errorLogFile  = (string) tempnam( sys_get_temp_dir(), 'slice15_errlog_' );
			$this->prevErrorLog  = ini_get( 'error_log' );
			$this->prevLogErrors = ini_get( 'log_errors' );
			ini_set( 'error_log', $this->errorLogFile );
			ini_set( 'log_errors', '1' );

			// ---- $_SERVER reset for resolveClientIp ---------------------
			$_SERVER['REMOTE_ADDR'] = '198.51.100.42';
		}

		protected function tearDown(): void
		{
			if ( $this->prevErrorLog !== '' && $this->prevErrorLog !== false ) {
				ini_set( 'error_log', (string) $this->prevErrorLog );
			}
			if ( $this->prevLogErrors !== '' && $this->prevLogErrors !== false ) {
				ini_set( 'log_errors', (string) $this->prevLogErrors );
			}
			if ( '' !== $this->errorLogFile && is_file( $this->errorLogFile ) ) {
				@unlink( $this->errorLogFile );
			}
			unset( $_SERVER['REMOTE_ADDR'] );

			Slice15HashSpy::reset();
			self::resetPluginState();
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * @return list<string>
		 */
		private function readErrorLogMessages(): array
		{
			if ( '' === $this->errorLogFile || ! is_file( $this->errorLogFile ) ) {
				return [];
			}
			$contents = (string) file_get_contents( $this->errorLogFile );
			if ( '' === $contents ) {
				return [];
			}
			$lines = preg_split( '/\r?\n/', trim( $contents ) ) ?: [];
			return array_values( array_filter( $lines, static fn ( $l ): bool => '' !== $l ) );
		}

		// ===================================================================
		// AC-1: register() registriert die REST-Route mit POST + permission_callback.
		// ===================================================================

		public function test_register_route_wires_post_with_permission_callback(): void
		{
			WebhookController::register();

			$this->assertCount(
				1,
				$this->restRoutes,
				'AC-1: register_rest_route MUSS GENAU EINMAL aufgerufen werden.'
			);

			[ $namespace, $route, $args ] = $this->restRoutes[0];

			$this->assertSame(
				'spreadconnect/v1',
				$namespace,
				'AC-1: REST-Namespace MUSS "spreadconnect/v1" sein (architecture.md Z. 127-131).'
			);
			$this->assertSame(
				'/webhook',
				$route,
				'AC-1: REST-Route MUSS "/webhook" sein.'
			);

			$this->assertArrayHasKey( 'methods', $args );
			$this->assertSame(
				'POST',
				$args['methods'],
				'AC-1: methods MUSS POST (CREATABLE) sein.'
			);

			$this->assertArrayHasKey( 'callback', $args );
			$this->assertSame(
				[ WebhookController::class, 'handle' ],
				$args['callback'],
				'AC-1: callback MUSS [WebhookController::class, "handle"] sein.'
			);

			$this->assertArrayHasKey( 'permission_callback', $args );
			$this->assertSame(
				[ WebhookController::class, 'authorize' ],
				$args['permission_callback'],
				'AC-1: permission_callback MUSS [WebhookController::class, "authorize"] sein.'
			);

			// AC-1 (Constraint): Public route — kein `manage_woocommerce`-Gate.
			// Wir pruefen, dass keine `permission`-String-Gate-Variante verwendet
			// wird (z. B. callback returnt current_user_can()).
			$this->assertNotSame(
				'__return_false',
				$args['permission_callback'],
				'AC-1: Route MUSS public sein — permission_callback ist HMAC-Gate, nicht __return_false.'
			);
		}

		// ===================================================================
		// AC-2: authorize() liest raw body via $request->get_body() — NICHT
		//        get_json_params() / get_params(); secret kommt via
		//        WebhookSecretManager::peek() aus get_option(SECRET).
		// ===================================================================

		public function test_authorize_reads_raw_body_not_parsed_json(): void
		{
			$rawBody = '{"key":"value","nested":{"a":[1,2,3]}}';
			$secret  = 'my-shared-secret-bytes';
			$expected = base64_encode( hash_hmac( 'sha256', $rawBody, $secret, true ) );

			$this->options['spreadconnect_webhook_secret'] = $secret;

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( $rawBody );
			$request->set_header( 'X-SPRD-SIGNATURE', $expected );

			$result = WebhookController::authorize( $request );

			$this->assertTrue(
				$result,
				'AC-2: Bei korrekter HMAC-Signatur MUSS authorize() true liefern.'
			);

			// AC-2 Trip-wires: weder get_json_params noch get_params duerfen
			// auf der Auth-Pfad aufgerufen werden — der raw body ist die
			// einzige byte-stabile Quelle fuer den HMAC.
			$this->assertFalse(
				$request->jsonParamsAccessed,
				'AC-2: authorize() DARF get_json_params() NICHT aufrufen — '
				. 'JSON-Roundtrip wuerde HMAC-Bytes zerstoeren.'
			);
			$this->assertFalse(
				$request->paramsAccessed,
				'AC-2: authorize() DARF get_params() NICHT aufrufen — '
				. 'nur get_body() liefert den byte-stabilen raw body.'
			);

			// AC-2: hash_hmac wurde mit raw body + secret aufgerufen (nicht
			// mit der parsed Struktur).
			$this->assertGreaterThan(
				0,
				count( Slice15HashSpy::$hashHmacCalls ),
				'AC-2: hash_hmac MUSS aufgerufen worden sein.'
			);
			$call = Slice15HashSpy::$hashHmacCalls[0];
			$this->assertSame( 'sha256', $call['algo'], 'AC-2: HMAC-Algo MUSS sha256 sein.' );
			$this->assertSame( $rawBody, $call['data'], 'AC-2: hash_hmac MUSS auf raw body operieren.' );
			$this->assertSame( $secret, $call['key'], 'AC-2: hash_hmac MUSS persisted secret als key bekommen.' );
			$this->assertTrue( $call['raw'], 'AC-2: hash_hmac MUSS mit binary=true (raw bytes) aufgerufen werden.' );
		}

		// ===================================================================
		// AC-3: Fehlender X-SPRD-SIGNATURE-Header -> WP_Error 401.
		//        Verifier wird NICHT aufgerufen (Header-Pre-Check).
		// ===================================================================

		public function test_authorize_rejects_request_without_signature_header(): void
		{
			$this->options['spreadconnect_webhook_secret'] = 'secret-xyz';

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( '{"any":"payload"}' );
			// NO X-SPRD-SIGNATURE header.

			$result = WebhookController::authorize( $request );

			$this->assertInstanceOf(
				\WP_Error::class,
				$result,
				'AC-3: Fehlender Signature-Header MUSS WP_Error liefern, nicht true/false.'
			);
			$this->assertSame(
				'spreadconnect_webhook_unauthorized',
				$result->get_error_code(),
				'AC-3: WP_Error code MUSS "spreadconnect_webhook_unauthorized" sein.'
			);
			$data = $result->get_error_data();
			$this->assertIsArray( $data );
			$this->assertSame(
				401,
				$data['status'] ?? null,
				'AC-3: WP_Error data MUSS HTTP-Status 401 setzen.'
			);

			// AC-3: Pre-Check vermeidet hash_hmac-Roundtrip wenn Header fehlt.
			$this->assertCount(
				0,
				Slice15HashSpy::$hashHmacCalls,
				'AC-3 (defense-in-depth): Bei fehlendem Header DARF hash_hmac '
				. 'NICHT aufgerufen werden — Header-Pre-Check kommt vor Verifier.'
			);
			$this->assertCount(
				0,
				Slice15HashSpy::$hashEqualsCalls,
				'AC-3 (defense-in-depth): Bei fehlendem Header DARF hash_equals NICHT aufgerufen werden.'
			);
		}

		// AC-3: Leerer Header (== "") -> WP_Error 401, gleiche Behandlung.
		public function test_authorize_rejects_request_with_empty_signature_header(): void
		{
			$this->options['spreadconnect_webhook_secret'] = 'secret-xyz';

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( '{"any":"payload"}' );
			$request->set_header( 'X-SPRD-SIGNATURE', '' );

			$result = WebhookController::authorize( $request );

			$this->assertInstanceOf(
				\WP_Error::class,
				$result,
				'AC-3: Leerer Signature-Header MUSS dieselbe 401 wie fehlender Header liefern.'
			);
			$data = $result->get_error_data();
			$this->assertSame( 401, $data['status'] ?? null );

			$this->assertCount(
				0,
				Slice15HashSpy::$hashHmacCalls,
				'AC-3: Leerer Header DARF hash_hmac NICHT triggern.'
			);
		}

		// AC-3: Nicht-base64-decodierbarer Header -> WP_Error 401.
		//        Verifier wird aufgerufen (Header ist nicht leer), liefert aber
		//        false weil base64_decode strict-mode false zurueckgibt.
		public function test_authorize_rejects_invalid_base64_signature(): void
		{
			$secret = 'secret-bytes-for-hmac';
			$this->options['spreadconnect_webhook_secret'] = $secret;

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( '{"any":"payload"}' );
			// Garbage that base64_decode($_, true) rejects.
			$request->set_header( 'X-SPRD-SIGNATURE', '!!!not-valid-base64@@@' );

			$result = WebhookController::authorize( $request );

			$this->assertInstanceOf(
				\WP_Error::class,
				$result,
				'AC-3: Nicht-base64 Signature MUSS WP_Error 401 liefern.'
			);
			$this->assertSame(
				'spreadconnect_webhook_unauthorized',
				$result->get_error_code()
			);
			$data = $result->get_error_data();
			$this->assertSame( 401, $data['status'] ?? null );

			// AC-3: hash_equals DARF NICHT aufgerufen werden — base64_decode
			// (strict) liefert false, der Verifier returnt false vorher.
			$this->assertCount(
				0,
				Slice15HashSpy::$hashEqualsCalls,
				'AC-3: Bei base64-Decode-Failure DARF hash_equals NICHT aufgerufen werden.'
			);
		}

		// ===================================================================
		// AC-4: Verifier nutzt hash_equals (constant-time); KEIN ===-Compare.
		// ===================================================================

		public function test_verifier_uses_hash_equals_not_loose_compare(): void
		{
			$rawBody = 'webhook-payload-bytes';
			$secret  = 'shared-secret-32-bytes-or-anything';
			$validSig = base64_encode( hash_hmac( 'sha256', $rawBody, $secret, true ) );

			$result = WebhookSignatureVerifier::verify( $rawBody, $validSig, $secret );

			$this->assertTrue(
				$result,
				'AC-4 (precondition): valider HMAC MUSS true liefern (Pass-Through Spy).'
			);

			// AC-4: hash_equals wurde GENAU EINMAL aufgerufen waehrend des Compares.
			$this->assertCount(
				1,
				Slice15HashSpy::$hashEqualsCalls,
				'AC-4 / AC-5: hash_equals MUSS exakt einmal pro verify()-Call aufgerufen werden — '
				. 'kein eigener strlen/===-Pre-Filter, kein Doppel-Compare.'
			);

			// AC-4 (Constraint): Source enthaelt KEIN ===/== auf den Compare-
			// Operanden. Wir pruefen statisch, dass der Verifier-Source weder
			// `=== $providedRaw` noch `== $providedRaw` noch `strcmp(` enthaelt.
			$source = (string) file_get_contents( self::verifierFile() );
			$this->assertNotSame( '', $source );

			// AC-4: Kein direkter `$expectedRaw === $providedRaw` Compare —
			//        nur hash_equals() darf die zwei HMAC-Outputs vergleichen.
			//        Erlaubt: `false === $providedRaw` als base64_decode-Result-
			//        check (das ist KEIN Two-Operand-Compare zwischen den Bytes).
			$this->assertDoesNotMatchRegularExpression(
				'/\$expectedRaw\s*(===|==|!=|!==)\s*\$providedRaw/',
				$source,
				'AC-4: Verifier-Source DARF KEINEN ===/==-Compare zwischen $expectedRaw und $providedRaw enthalten.'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/\$providedRaw\s*(===|==|!=|!==)\s*\$expectedRaw/',
				$source,
				'AC-4: Verifier-Source DARF KEINEN ===/==-Compare zwischen $providedRaw und $expectedRaw enthalten.'
			);
			$this->assertDoesNotMatchRegularExpression(
				'/\bstrcmp\s*\(/',
				$source,
				'AC-4: Verifier-Source DARF KEIN strcmp() verwenden — nur hash_equals().'
			);
			// hash_equals ist im Source verwendet.
			$this->assertMatchesRegularExpression(
				'/\bhash_equals\s*\(/',
				$source,
				'AC-4: Verifier-Source MUSS hash_equals() referenzieren.'
			);
			// Kein strlen-prefilter ausserhalb hash_equals.
			$this->assertDoesNotMatchRegularExpression(
				'/\bstrlen\s*\(\s*\$providedRaw\s*\)\s*!==\s*\d+/',
				$source,
				'AC-4: Verifier-Source DARF KEINEN strlen-Pre-Filter haben — '
				. 'hash_equals ist constant-time auch bei Length-Mismatch.'
			);
		}

		// AC-4: HMAC-Mismatch -> false (echter Compare via Pass-Through).
		public function test_verifier_returns_false_on_hmac_mismatch(): void
		{
			$rawBody = 'webhook-payload';
			$secret  = 'real-secret';

			// Korrekt geformte base64-Signatur mit 32 Bytes — aber Bytes
			// stimmen nicht mit hash_hmac($rawBody, $secret) ueberein.
			$wrongRaw = str_repeat( "\x00", 32 );
			$wrongSig = base64_encode( $wrongRaw );

			$result = WebhookSignatureVerifier::verify( $rawBody, $wrongSig, $secret );

			$this->assertFalse(
				$result,
				'AC-4: HMAC-Mismatch MUSS false liefern.'
			);
			// Constant-time-compare lief: hash_equals wurde aufgerufen.
			$this->assertCount(
				1,
				Slice15HashSpy::$hashEqualsCalls,
				'AC-4: Auch bei Mismatch MUSS hash_equals aufgerufen werden — '
				. 'kein Length-Mismatch-Bypass.'
			);
		}

		// ===================================================================
		// AC-5: Gueltige Signatur -> verify() returns true; hash_equals genau 1x.
		// ===================================================================

		public function test_verifier_returns_true_on_valid_signature(): void
		{
			$rawBody = '{"order_id":42,"event":"order.shipped"}';
			$secret  = 'production-shared-secret-32-bytes-XX';
			$expectedRaw = hash_hmac( 'sha256', $rawBody, $secret, true );
			$validSig = base64_encode( $expectedRaw );

			$result = WebhookSignatureVerifier::verify( $rawBody, $validSig, $secret );

			$this->assertTrue(
				$result,
				'AC-5: verify() MUSS bei korrekter Signatur true liefern.'
			);

			// AC-5: hash_equals wurde GENAU EINMAL aufgerufen.
			$this->assertCount(
				1,
				Slice15HashSpy::$hashEqualsCalls,
				'AC-5: hash_equals MUSS pro verify() genau einmal aufgerufen werden.'
			);

			// AC-5: Operanden gleicher Laenge (32 Bytes — SHA-256 raw).
			$call = Slice15HashSpy::$hashEqualsCalls[0];
			$this->assertSame(
				32,
				strlen( $call[0] ),
				'AC-5: erste Operand (expected) MUSS 32 Bytes sein (SHA-256 raw).'
			);
			$this->assertSame(
				32,
				strlen( $call[1] ),
				'AC-5: zweite Operand (provided) MUSS 32 Bytes sein (base64-decoded SHA-256).'
			);
			$this->assertSame(
				$expectedRaw,
				$call[0],
				'AC-5: hash_equals MUSS gegen den hash_hmac-Output aufgerufen werden.'
			);
		}

		// ===================================================================
		// AC-6: Leeres Secret -> verify() returns false sofort,
		//        KEIN hash_hmac-Aufruf, KEIN hash_equals-Aufruf.
		// ===================================================================

		public function test_verifier_returns_false_on_empty_secret(): void
		{
			$rawBody = 'any-body';
			// Auch eine syntaktisch valide base64-Signatur darf das Defense-in-
			// depth-Verhalten nicht aushebeln: leeres Secret -> false.
			$signature = base64_encode( str_repeat( "\x00", 32 ) );

			$result = WebhookSignatureVerifier::verify( $rawBody, $signature, '' );

			$this->assertFalse(
				$result,
				'AC-6: Leeres Secret MUSS sofort false liefern (Defense-in-depth).'
			);
			$this->assertCount(
				0,
				Slice15HashSpy::$hashHmacCalls,
				'AC-6: Bei leerem Secret DARF hash_hmac NICHT aufgerufen werden.'
			);
			$this->assertCount(
				0,
				Slice15HashSpy::$hashEqualsCalls,
				'AC-6: Bei leerem Secret DARF hash_equals NICHT aufgerufen werden.'
			);
		}

		// AC-6 / Adversarial: Auch leere Signatur + leeres Secret darf NICHT
		//        true liefern — die "leeres-Secret"-Bailout greift VOR jedem
		//        Compare, sogar wenn beide Strings trivial gleich sind.
		public function test_verifier_returns_false_on_empty_secret_and_empty_signature(): void
		{
			$result = WebhookSignatureVerifier::verify( 'any-body', '', '' );

			$this->assertFalse(
				$result,
				'AC-6 (adversarial): Leeres Secret + leere Signatur MUSS false liefern, '
				. 'auch wenn der Compare technisch "gleich" waere.'
			);
			$this->assertCount( 0, Slice15HashSpy::$hashHmacCalls );
			$this->assertCount( 0, Slice15HashSpy::$hashEqualsCalls );
		}

		// ===================================================================
		// AC-7: HMAC-Mismatch loggt IP + Header-Keys (KEIN Payload, KEINE
		//        Signatur-Bytes, KEIN Secret).
		// ===================================================================

		public function test_rejected_request_logs_ip_and_header_keys_without_payload(): void
		{
			$rawBody = '{"sensitive":"payload-data-MUST-NOT-LEAK"}';
			$secret  = 'shared-secret-MUST-NOT-LEAK';
			$wrongSig = base64_encode( str_repeat( "\xFF", 32 ) );

			$this->options['spreadconnect_webhook_secret'] = $secret;

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( $rawBody );
			$request->set_header( 'X-SPRD-SIGNATURE', $wrongSig );
			$request->set_header( 'X-Forwarded-For', '203.0.113.7, 198.51.100.1' );
			$request->set_header( 'User-Agent', 'Spreadconnect-Webhook/1.0' );

			$result = WebhookController::authorize( $request );

			$this->assertInstanceOf(
				\WP_Error::class,
				$result,
				'AC-7 (precondition): Mismatch MUSS WP_Error liefern (sonst kein Log-Pfad).'
			);

			$messages = $this->readErrorLogMessages();
			$this->assertNotEmpty(
				$messages,
				'AC-7: Bei Mismatch MUSS ein Log-Eintrag geschrieben werden.'
			);

			$joined = implode( "\n", $messages );

			// (a) Source-Marker present.
			$this->assertStringContainsString(
				'spreadconnect-webhook-receiver',
				$joined,
				'AC-7: Log MUSS source-marker "spreadconnect-webhook-receiver" enthalten.'
			);

			// (b) Reason ist "invalid_hmac".
			$this->assertStringContainsString(
				'invalid_hmac',
				$joined,
				'AC-7: Log MUSS reason=invalid_hmac enthalten.'
			);

			// (c) IP-Field ist gesetzt (X-Forwarded-For first-hop).
			$this->assertStringContainsString(
				'203.0.113.7',
				$joined,
				'AC-7: Log MUSS X-Forwarded-For first-hop IP enthalten.'
			);

			// (d) Header-Names sind enthalten (Keys, nicht Values).
			$this->assertStringContainsString(
				'x-sprd-signature',
				$joined,
				'AC-7: Log MUSS Header-NAMES enthalten (z. B. x-sprd-signature).'
			);

			// (e) Redaction-Asserts: KEIN raw body, KEINE Signature-Bytes, KEIN Secret.
			$this->assertStringNotContainsString(
				$rawBody,
				$joined,
				'AC-7: Log DARF NIEMALS den raw body enthalten.'
			);
			$this->assertStringNotContainsString(
				'sensitive',
				$joined,
				'AC-7: Log DARF KEIN Body-Field-Name enthalten (Body ist nie Quelle).'
			);
			$this->assertStringNotContainsString(
				$secret,
				$joined,
				'AC-7: Log DARF NIEMALS das Secret enthalten.'
			);
			$this->assertStringNotContainsString(
				$wrongSig,
				$joined,
				'AC-7: Log DARF NIEMALS die Signatur-Bytes (header value) enthalten.'
			);
		}

		// AC-7: Fehlender Header -> reason=missing_header (anderer Marker).
		public function test_rejected_request_logs_missing_header_reason(): void
		{
			$this->options['spreadconnect_webhook_secret'] = 'sec';

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( 'body' );
			// No X-SPRD-SIGNATURE.
			$request->set_header( 'User-Agent', 'curl/8.0' );

			WebhookController::authorize( $request );

			$messages = $this->readErrorLogMessages();
			$joined   = implode( "\n", $messages );

			$this->assertStringContainsString(
				'missing_header',
				$joined,
				'AC-7: Bei fehlendem Header MUSS reason=missing_header geloggt werden.'
			);
			$this->assertStringNotContainsString(
				'invalid_hmac',
				$joined,
				'AC-7: Bei fehlendem Header DARF reason=invalid_hmac NICHT geloggt werden — '
				. 'eindeutiger Reason-Marker pro Failure-Branch.'
			);
		}

		// AC-7 (statisch): Source nutzt nur array_keys(...) auf headers, NICHT
		//                    Header-VALUES. Defense-in-depth gegen Future-Edits.
		public function test_log_rejected_uses_only_header_names(): void
		{
			$source = (string) file_get_contents( self::controllerFile() );
			$this->assertNotSame( '', $source );

			// Extract the logRejected method body via regex (final + private static).
			$matched = preg_match(
				'/private static function logRejected\s*\([^)]*\)\s*:\s*void\s*\{([\s\S]*?)\n\t\}/',
				$source,
				$m
			);
			$this->assertSame( 1, $matched, 'AC-7: logRejected-Methode MUSS im Source existieren.' );
			$body = $m[1];

			// MUST use array_keys() to project headers down to names.
			$this->assertMatchesRegularExpression(
				'/\barray_keys\s*\(/',
				$body,
				'AC-7: logRejected MUSS array_keys() auf headers anwenden — nur NAMES, keine VALUES.'
			);
			// MUST NOT reference the signature header value.
			$this->assertDoesNotMatchRegularExpression(
				'/\$signature\b/',
				$body,
				'AC-7: logRejected DARF die Signature-Variable NICHT referenzieren.'
			);
			// MUST NOT reference the raw body.
			$this->assertDoesNotMatchRegularExpression(
				'/\$rawBody\b|->get_body\s*\(/',
				$body,
				'AC-7: logRejected DARF den raw body NICHT lesen oder loggen.'
			);
		}

		// ===================================================================
		// AC-8: handle() bei valider HMAC liefert Stub-200-Response;
		//        kein DB-Insert, kein AS-Schedule.
		// ===================================================================

		public function test_handle_returns_stub_200_response_without_side_effects(): void
		{
			// Spy auf Action-Scheduler-Enqueue: muss EXAKT 0 mal aufgerufen werden.
			$enqueued = [];
			Functions\when( 'as_enqueue_async_action' )->alias(
				static function ( string $hook, array $args = [], string $group = '' ) use ( &$enqueued ): int {
					$enqueued[] = [ $hook, $args, $group ];
					return 0;
				}
			);

			$request = new WP_REST_Request( 'POST', '/webhook' );
			$request->set_body( '{"event":"order.shipped"}' );

			$response = WebhookController::handle( $request );

			$this->assertInstanceOf(
				WP_REST_Response::class,
				$response,
				'AC-8: handle() MUSS WP_REST_Response liefern.'
			);
			$this->assertSame(
				200,
				$response->get_status(),
				'AC-8: handle() MUSS HTTP 200 liefern (Stub) — Slice-16 wechselt auf 202.'
			);
			$this->assertNull(
				$response->get_data(),
				'AC-8: handle() MUSS Body=null liefern — kein literal "[accepted]" in Slice-15.'
			);

			$this->assertSame(
				[],
				$enqueued,
				'AC-8: handle() DARF KEINE AS-Action enqueuen — Slice-16 (process_webhook_event) ist out-of-scope.'
			);
		}

		// AC-8 (statisch): handle() ist trivial (Single-Return), so dass Slice-16
		//                  einen sauberen Diff produzieren kann.
		public function test_handle_source_is_trivial_single_return(): void
		{
			$source = (string) file_get_contents( self::controllerFile() );

			$matched = preg_match(
				'/public static function handle\s*\([^)]*\)\s*:\s*WP_REST_Response[^\{]*\{([\s\S]*?)\n\t\}/',
				$source,
				$m
			);
			$this->assertSame( 1, $matched, 'AC-8: handle()-Methode MUSS existieren.' );

			$body = trim( $m[1] );

			// MUST be a single `return new WP_REST_Response(null, 200);` line.
			$this->assertMatchesRegularExpression(
				'/return\s+new\s+WP_REST_Response\s*\(\s*null\s*,\s*200\s*\)\s*;/',
				$body,
				'AC-8: handle() MUSS GENAU `return new WP_REST_Response(null, 200);` enthalten.'
			);

			// No DB calls, no AS-enqueue, no event-id hashing.
			$this->assertDoesNotMatchRegularExpression(
				'/\$wpdb|as_enqueue_async_action|EventIdHasher|WebhookLogRepo/',
				$body,
				'AC-8: handle() DARF KEINEN DB-Insert, AS-Enqueue oder EventIdHasher enthalten — '
				. 'das sind Slice-16-Deliverables.'
			);
		}

		// ===================================================================
		// AC-9: Bootstrap registriert rest_api_init-Hook genau einmal.
		// ===================================================================

		public function test_bootstrap_registers_rest_api_init_hook(): void
		{
			Plugin::init( self::pluginMainFile() );

			$priority = Actions\has(
				'rest_api_init',
				[ WebhookController::class, 'register' ]
			);

			$this->assertNotFalse(
				$priority,
				'AC-9: Bootstrap MUSS add_action("rest_api_init", [WebhookController::class, "register"]) aufrufen.'
			);
			$this->assertSame(
				10,
				$priority,
				'AC-9: Hook-Priority MUSS 10 (WP-default) sein.'
			);
		}

		// AC-9: Doppelter init() registriert Hook GENAU EINMAL.
		public function test_bootstrap_double_init_does_not_duplicate_webhook_hook(): void
		{
			Plugin::init( self::pluginMainFile() );
			Plugin::init( self::pluginMainFile() );

			$count = self::countRegistrationsForActionHook(
				'rest_api_init',
				[ WebhookController::class, 'register' ]
			);

			if ( -1 === $count ) {
				$this->markTestIncomplete(
					'AC-9: Brain\\Monkey HookStorage Internals nicht inspizierbar.'
				);
			}

			$this->assertSame(
				1,
				$count,
				'AC-9: Doppelter Plugin::init() DARF den rest_api_init->WebhookController-Hook '
				. 'NICHT zweimal registrieren — Idempotenz-Guard aus Slice 02 muss greifen.'
			);
		}

		// ===================================================================
		// AC-10: Patchwork-Replace beweist Constant-Time-Compare-Eigenschaften
		//         (Operanden gleicher Laenge = 32 Bytes = SHA-256 raw).
		// ===================================================================

		public function test_hash_equals_invoked_with_equal_length_operands(): void
		{
			// Run BOTH the valid AND the invalid case in the same test, so we
			// witness the hash_equals invocation under both regimes.

			$rawBody = 'webhook-payload';
			$secret  = 'shared-secret';

			// Case A: valid signature.
			$validSig = base64_encode( hash_hmac( 'sha256', $rawBody, $secret, true ) );
			Slice15HashSpy::reset();
			\Patchwork\redefine(
				'hash_equals',
				static function ( string $known, string $user ): bool {
					Slice15HashSpy::$hashEqualsCalls[] = [ $known, $user ];
					return \Patchwork\relay( [ $known, $user ] );
				}
			);
			\Patchwork\redefine(
				'hash_hmac',
				static function ( string $algo, string $data, string $key, bool $binary = false ): string {
					Slice15HashSpy::$hashHmacCalls[] = [
						'algo' => $algo,
						'data' => $data,
						'key'  => $key,
						'raw'  => $binary,
					];
					return \Patchwork\relay( [ $algo, $data, $key, $binary ] );
				}
			);
			$this->assertTrue(
				WebhookSignatureVerifier::verify( $rawBody, $validSig, $secret ),
				'AC-10 (Case A): valider HMAC MUSS true liefern.'
			);
			$this->assertCount(
				1,
				Slice15HashSpy::$hashEqualsCalls,
				'AC-10 (Case A): hash_equals genau 1x.'
			);
			$callA = Slice15HashSpy::$hashEqualsCalls[0];
			$this->assertSame(
				strlen( $callA[0] ),
				strlen( $callA[1] ),
				'AC-10 (Case A): Operanden gleiche Laenge = constant-time compare.'
			);
			$this->assertSame(
				32,
				strlen( $callA[0] ),
				'AC-10: SHA-256 raw -> 32 Bytes Compare-Operanden.'
			);

			// Case B: invalid signature (different bytes, but base64 OK).
			Slice15HashSpy::reset();
			\Patchwork\redefine(
				'hash_equals',
				static function ( string $known, string $user ): bool {
					Slice15HashSpy::$hashEqualsCalls[] = [ $known, $user ];
					return \Patchwork\relay( [ $known, $user ] );
				}
			);
			\Patchwork\redefine(
				'hash_hmac',
				static function ( string $algo, string $data, string $key, bool $binary = false ): string {
					Slice15HashSpy::$hashHmacCalls[] = [
						'algo' => $algo,
						'data' => $data,
						'key'  => $key,
						'raw'  => $binary,
					];
					return \Patchwork\relay( [ $algo, $data, $key, $binary ] );
				}
			);
			$wrongSig = base64_encode( str_repeat( "\xAB", 32 ) );
			$this->assertFalse(
				WebhookSignatureVerifier::verify( $rawBody, $wrongSig, $secret ),
				'AC-10 (Case B): invalider HMAC MUSS false liefern.'
			);
			$this->assertCount(
				1,
				Slice15HashSpy::$hashEqualsCalls,
				'AC-10 (Case B): hash_equals auch bei Mismatch genau 1x — kein Length-Mismatch-Bypass.'
			);
			$callB = Slice15HashSpy::$hashEqualsCalls[0];
			$this->assertSame(
				strlen( $callB[0] ),
				strlen( $callB[1] ),
				'AC-10 (Case B): Operanden gleiche Laenge auch bei Mismatch.'
			);
			$this->assertSame(
				32,
				strlen( $callB[1] ),
				'AC-10 (Case B): provided signature wird via base64_decode auf 32 Bytes normalisiert.'
			);
		}

		// ===================================================================
		// Helper: Brain\Monkey HookStorage introspection (mirror of Slice03).
		// ===================================================================

		/**
		 * Count registrations of a given (hook, callable) pair in
		 * Brain\Monkey's internal HookStorage.
		 *
		 * Returns -1 when the storage shape isn't introspectable (library
		 * version drift); callers should `markTestIncomplete` in that case.
		 *
		 * @param array{0:string,1:string}|callable $targetCallback
		 */
		private static function countRegistrationsForActionHook(
			string $hookName,
			callable|array $targetCallback
		): int {
			$hookStorage = \Brain\Monkey\Container::instance()->hookStorage();
			$refl        = new ReflectionClass( $hookStorage );

			if ( ! $refl->hasProperty( 'storage' ) ) {
				return -1;
			}

			$prop = $refl->getProperty( 'storage' );
			$all  = $prop->getValue( $hookStorage );

			$added   = $all[ \Brain\Monkey\Hook\HookStorage::ADDED ] ?? null;
			$actions = $added[ \Brain\Monkey\Hook\HookStorage::ACTIONS ] ?? null;
			$forHook = $actions[ $hookName ] ?? null;

			if ( ! is_array( $forHook ) ) {
				return 0;
			}

			$targetForm = (string) new \Brain\Monkey\Name\CallbackStringForm( $targetCallback );

			$count = 0;
			foreach ( $forHook as $registration ) {
				if ( ! is_array( $registration ) || ! isset( $registration[0] ) ) {
					continue;
				}
				$cb = $registration[0];
				if ( $cb instanceof \Brain\Monkey\Name\CallbackStringForm
					&& (string) $cb === $targetForm
				) {
					$count++;
				}
			}

			return $count;
		}
	}
}
