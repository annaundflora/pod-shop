<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope)
// ---------------------------------------------------------------------------
//
// `SpreadconnectClient` (which the AJAX handler instantiates with the
// POST-body key as `apiKeyOverride`) references the global `WP_Error` class
// at parse time. We pre-load the same minimal stub Slice 07 / Slice 10 use
// so the file-load order remains compatible.
//
// `wp_json_encode()` is also referenced by the underlying HTTP client when
// it encodes JSON request bodies; the AJAX handler never sends a body, but
// loading the stub keeps the symbol available regardless of branch order.
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
    use ReflectionClass;
    use RuntimeException;
    use SpreadconnectPod\Hub\Ajax\TestConnection;
    use SpreadconnectPod\Hub\View\Settings;

    /**
     * Sentinel exception used to interrupt control flow when `wp_send_json_*`
     * is invoked. WP terminates the request via `die()` after writing the JSON
     * response — in the test environment we cannot afford to terminate the
     * PHPUnit process, so the stubs throw `JsonResponseSentinel` carrying the
     * payload + status; the test catches it and inspects the captured value.
     *
     * The handler under test contains an explicit `return` after every
     * `wp_send_json_*` call (defensive, slice-12 Constraint), so even without
     * a thrown sentinel the handler would not continue executing. The throw
     * serves two purposes: (1) prevent any downstream code from running in
     * non-defensive branches, and (2) provide a uniform single point at which
     * the test grabs the captured payload.
     */
    final class JsonResponseSentinel extends \RuntimeException
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
     * Slice 12 — Admin-AJAX `spreadconnect_test_connection` Handler.
     *
     * Acceptance Tests gegen die Slice-Spec
     * `slice-12-test-connection-ajax.md`.
     *
     * Mocking Strategy: `mock_external` (laut Slice-Spec):
     *   - Brain\Monkey fuer Cap+Nonce: `current_user_can`, `check_ajax_referer`.
     *   - Brain\Monkey fuer Sanitize-Helpers: `wp_unslash`, `sanitize_text_field`.
     *   - Brain\Monkey fuer Termination-Helpers: `wp_send_json_success`,
     *     `wp_send_json_error` (werfen `JsonResponseSentinel` damit der
     *     PHPUnit-Prozess nicht via `die()` terminiert).
     *   - Brain\Monkey fuer i18n: `__`, `esc_html__`, `esc_attr`.
     *   - Brain\Monkey fuer Hook-Capture: `add_action`, `wp_create_nonce`.
     *   - `wp_remote_request` wird gestubbt um den realen Slice-07
     *     `SpreadconnectClient` zu treiben (er wirft die echten
     *     `SpreadconnectClientError` / `SpreadconnectTransientError`-Typen)
     *     — kein Mockery-Subclass-Trick noetig, weil der Handler den Client
     *     selbst per `new` instanziiert.
     *
     * Strategie pro AC-Block:
     *   - AC-1 / AC-2: Cap+Nonce-Hard-Gates — `authenticate()`-Pfad muss
     *     unerreicht bleiben (kein `wp_remote_request`-Call).
     *   - AC-3: POST-Body-Key wird durch wp_unslash + sanitize_text_field
     *     gefuehrt; persistierte Option wird ignoriert.
     *   - AC-4: 200-Pfad -> `wp_send_json_success` mit Valid-Message.
     *   - AC-5: 4xx-Pfad -> `wp_send_json_error` mit Invalid-Key-Message,
     *     KEIN Leak der Client-Exception-Message.
     *   - AC-6: 5xx-Pfad -> `wp_send_json_error` mit Connection-Failed-Message.
     *   - AC-7: Empty Key -> sofort error, kein authenticate-Call.
     *   - AC-8: Settings-View rendert Section ② Markup mit Button + Status-
     *     Container + Nonce; kein API-Key-Wert.
     *   - AC-10: register() haengt sich an wp_ajax_spreadconnect_test_connection
     *     (kein nopriv).
     *   - AC-11: API-Key-Wert taucht weder in Response noch in error_log auf.
     */
    final class Slice12TestConnectionAjaxTest extends TestCase
    {
        /**
         * Captured `wp_remote_request` invocations: list of `[$url, $args]`.
         *
         * @var list<array{0:string,1:array<string,mixed>}>
         */
        private array $remoteCalls = [];

        /**
         * Repo-Root: drei Verzeichnisse oberhalb von `tests/slices/pod-shop-mvp/`.
         */
        private static function repoRoot(): string
        {
            return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
        }

        /**
         * Absoluter Pfad zur TestConnection-Handler-Klassendatei.
         */
        private static function handlerFile(): string
        {
            return self::repoRoot()
                . '/wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/TestConnection.php';
        }

        /**
         * Absoluter Pfad zur Settings-View-Klassendatei.
         */
        private static function settingsViewFile(): string
        {
            return self::repoRoot()
                . '/wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php';
        }

        protected function setUp(): void
        {
            parent::setUp();
            Monkey\setUp();

            $this->remoteCalls = [];

            // ---- Default i18n + Escape-Helpers ------------------------------.
            // Identity stubs — `__()` / `esc_html__()` / `esc_attr()` werden
            // sowohl im Handler (Messages) als auch im Settings-View-Markup
            // (Button-Label, Section-Title) verwendet. Default reicht
            // `returnArg` weil wir die Strings 1:1 vergleichen.
            Functions\when( '__' )->returnArg( 1 );
            Functions\when( 'esc_html__' )->returnArg( 1 );
            Functions\when( 'esc_attr__' )->returnArg( 1 );
            Functions\when( 'esc_html' )->returnArg( 1 );
            Functions\when( 'esc_attr' )->returnArg( 1 );

            // ---- Default Sanitize-Helpers ------------------------------------.
            Functions\when( 'wp_unslash' )->alias( static function ( $value ) {
                if ( is_string( $value ) ) {
                    return stripslashes( $value );
                }
                return $value;
            } );
            Functions\when( 'sanitize_text_field' )->alias( static function ( $value ) {
                if ( ! is_string( $value ) ) {
                    return '';
                }
                $clean = strip_tags( $value );
                $clean = preg_replace( '/[\r\n\t]+/', ' ', $clean ) ?? '';
                return trim( $clean );
            } );

            // ---- `wp_send_json_*` Termination-Stubs --------------------------.
            // Werfen `JsonResponseSentinel` mit Payload + Status, damit Tests
            // das Resultat inspizieren koennen und der Handler nicht weiter
            // ausgefuehrt wird. WP terminiert via `die()` in Production —
            // ein Throw ist die saubere Test-Aequivalenz.
            Functions\when( 'wp_send_json_success' )->alias(
                static function ( $data = null, $status_code = null ) {
                    throw new JsonResponseSentinel( true, (array) $data, $status_code );
                }
            );
            Functions\when( 'wp_send_json_error' )->alias(
                static function ( $data = null, $status_code = null ) {
                    throw new JsonResponseSentinel( false, (array) $data, $status_code );
                }
            );

            // ---- WP-Remote-Helpers — der Slice-07 Client liest Status / Body /
            //      Headers ueber diese Wrapper. Die einzelnen Tests setzen ihren
            //      eigenen `wp_remote_request`-Stub.
            Functions\when( 'is_wp_error' )->alias( static function ( $thing ): bool {
                return $thing instanceof \WP_Error;
            } );
            Functions\when( 'wp_remote_retrieve_response_code' )->alias( static function ( $resp ): int {
                if ( is_array( $resp ) && isset( $resp['status'] ) ) {
                    return (int) $resp['status'];
                }
                return 0;
            } );
            Functions\when( 'wp_remote_retrieve_body' )->alias( static function ( $resp ): string {
                if ( is_array( $resp ) && isset( $resp['body'] ) ) {
                    return (string) $resp['body'];
                }
                return '';
            } );
            Functions\when( 'wp_remote_retrieve_headers' )->alias( static function ( $resp ) {
                if ( is_array( $resp ) && isset( $resp['headers'] ) ) {
                    return $resp['headers'];
                }
                return [];
            } );

            // wc_get_logger — Minimal-Spy. Der Slice-07 Client verlangt einen
            // Logger; ein No-Op reicht fuer Slice-12-Tests (das Logging ist
            // nicht Slice-12-Verantwortung — nur "kein API-Key im Log" wird
            // gegen einen Capture-Spy geprueft.).
            Functions\when( 'wc_get_logger' )->alias( static function () {
                return new class {
                    /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
                    public array $entries = [];

                    public function info( string $message, array $context = [] ): void {
                        $this->entries[] = [ 'level' => 'info', 'message' => $message, 'context' => $context ];
                    }
                    public function warning( string $message, array $context = [] ): void {
                        $this->entries[] = [ 'level' => 'warning', 'message' => $message, 'context' => $context ];
                    }
                    public function error( string $message, array $context = [] ): void {
                        $this->entries[] = [ 'level' => 'error', 'message' => $message, 'context' => $context ];
                    }
                    public function debug( string $message, array $context = [] ): void {
                        $this->entries[] = [ 'level' => 'debug', 'message' => $message, 'context' => $context ];
                    }
                };
            } );

            // get_option — Default leer. Slice-12 darf den persistierten Key
            // gar nicht erst lesen (AC-3); wir konfigurieren ihn pro Test
            // explizit, um die "POST-Body-Key wird verwendet, NICHT die
            // Option" Assertion sauber zu fahren.
            Functions\when( 'get_option' )->alias(
                static function ( string $name, $default = false ) {
                    if ( 'spreadconnect_use_staging' === $name ) {
                        return false;
                    }
                    return $default;
                }
            );
        }

        protected function tearDown(): void
        {
            // Reset $_POST damit Tests sich nicht beeinflussen.
            $_POST = [];
            Monkey\tearDown();
            parent::tearDown();
        }

        /**
         * Stub `wp_remote_request` to capture invocations into
         * `$this->remoteCalls` and dispense a canned response.
         *
         * @param mixed $response Response array OR `WP_Error`.
         */
        private function stubRemoteRequest( mixed $response ): void
        {
            $captures = &$this->remoteCalls;
            Functions\when( 'wp_remote_request' )->alias(
                static function ( $url, $args ) use ( $response, &$captures ) {
                    $captures[] = [ (string) $url, (array) $args ];
                    return $response;
                }
            );
        }

        /**
         * Stub `wp_remote_request` so it ALWAYS fails the test if it is
         * invoked. Used by AC-1 / AC-2 / AC-7 to prove `authenticate()` was
         * never reached.
         */
        private function stubRemoteRequestForbidden(): void
        {
            Functions\when( 'wp_remote_request' )->alias(
                static function ( $url, $args ) {
                    throw new \LogicException(
                        'wp_remote_request MUST NOT be reached when cap/nonce/empty-key gates fire. ' .
                        'URL=' . (string) $url
                    );
                }
            );
        }

        // ===================================================================
        // AC-1: Capability-Check — manage_woocommerce fehlt -> 403, kein
        //       authenticate-Call.
        // ===================================================================

        public function test_handle_rejects_without_manage_woocommerce_capability(): void
        {
            // User hat KEINE manage_woocommerce-Capability.
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => false
            );
            // Nonce-Check darf gar nicht erst stattfinden — wir lassen den
            // Default in Brain\Monkey, der bei nicht-stub TRUE ergeben wuerde.
            Functions\when( 'check_ajax_referer' )->justReturn( true );
            $this->stubRemoteRequestForbidden();

            $_POST = [
                'api_key'     => 'sk_test_should_not_be_used',
                '_ajax_nonce' => 'doesnt_matter',
            ];

            $thrown = null;
            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                $thrown = $e;
            }

            $this->assertNotNull(
                $thrown,
                'AC-1: Handler MUSS via wp_send_json_* terminieren wenn manage_woocommerce fehlt.'
            );
            $this->assertFalse(
                $thrown->success,
                'AC-1: Termination MUSS via wp_send_json_error (NICHT _success) erfolgen.'
            );
            $this->assertSame(
                403,
                $thrown->status,
                'AC-1: HTTP-Status MUSS 403 sein wenn die Capability fehlt.'
            );
            $this->assertArrayHasKey( 'ok', $thrown->payload );
            $this->assertFalse( $thrown->payload['ok'], 'AC-1: payload[ok] MUSS false sein.' );

            $this->assertSame(
                [],
                $this->remoteCalls,
                'AC-1: wp_remote_request darf NIE aufgerufen werden — authenticate() ' .
                'wird durch den Cap-Hard-Gate abgefangen.'
            );
        }

        // ===================================================================
        // AC-2: Nonce-Check — invalid Nonce -> 403, kein authenticate-Call.
        // ===================================================================

        public function test_handle_rejects_with_invalid_nonce(): void
        {
            // User HAT Capability ...
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );
            // ... aber Nonce-Check schlaegt fehl.
            Functions\when( 'check_ajax_referer' )->alias(
                static function ( string $action, string $field = '_wpnonce', bool $die = true ) {
                    // Slice-12 muss `false` als 3. Arg uebergeben damit der
                    // Helper nicht selbst `wp_die`-t. Wir geben `false`
                    // (== invalid nonce) zurueck, der Handler MUSS daraus
                    // einen 403 + JSON-Error machen.
                    return false;
                }
            );
            $this->stubRemoteRequestForbidden();

            $_POST = [
                'api_key' => 'sk_test_42',
                // _ajax_nonce fehlt -> simuliert die "missing nonce"-Variante.
            ];

            $thrown = null;
            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                $thrown = $e;
            }

            $this->assertNotNull(
                $thrown,
                'AC-2: Handler MUSS via wp_send_json_* terminieren wenn die Nonce invalid ist.'
            );
            $this->assertFalse(
                $thrown->success,
                'AC-2: Termination MUSS via wp_send_json_error erfolgen.'
            );
            $this->assertSame(
                403,
                $thrown->status,
                'AC-2: HTTP-Status MUSS 403 sein wenn die Nonce invalid ist.'
            );
            $this->assertSame(
                [],
                $this->remoteCalls,
                'AC-2: wp_remote_request darf NIE aufgerufen werden — authenticate() ' .
                'wird durch den Nonce-Hard-Gate abgefangen.'
            );
        }

        /**
         * AC-2: Cap+Nonce als hard-gates — `check_ajax_referer` MUSS mit dem
         * exakten Action-String "spreadconnect_test_connection" UND mit
         * `false` als 3. Argument aufgerufen werden (sonst wuerde der WP-
         * Helper selbst `wp_die`-en und das normalisierte JSON-Shape brechen).
         */
        public function test_handle_calls_check_ajax_referer_with_exact_action_and_false_die_flag(): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );

            $captured = [];
            Functions\when( 'check_ajax_referer' )->alias(
                static function ( $action, $field = '_wpnonce', $die = true ) use ( &$captured ) {
                    $captured[] = [ 'action' => $action, 'field' => $field, 'die' => $die ];
                    return false; // Force-fail damit der Handler in JSON-Error terminiert.
                }
            );
            $this->stubRemoteRequestForbidden();

            $_POST = [ 'api_key' => 'whatever' ];

            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                // Erwartet — Handler terminiert via wp_send_json_error.
            }

            $this->assertCount(
                1,
                $captured,
                'AC-2: check_ajax_referer MUSS genau einmal aufgerufen werden.'
            );
            $call = $captured[0];
            $this->assertSame(
                'spreadconnect_test_connection',
                $call['action'],
                'AC-2 / Constraint: Nonce-Action-String MUSS exakt "spreadconnect_test_connection" sein ' .
                '(muss mit wp_create_nonce-String im Settings-Markup uebereinstimmen).'
            );
            $this->assertFalse(
                $call['die'],
                'AC-2: 3. Argument MUSS `false` sein damit check_ajax_referer NICHT selbst wp_die-t — ' .
                'der Handler shaped die Response selbst.'
            );
        }

        // ===================================================================
        // AC-2: Cap+Nonce als hard-gates — beide muessen vor der Business-
        //       Logik laufen. Auch mit valider Nonce ohne Cap MUSS authenticate
        //       unerreichbar bleiben.
        // ===================================================================

        public function test_handle_runs_both_cap_and_nonce_checks_before_business_logic(): void
        {
            // Cap fehlt …
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => false
            );
            // … aber Nonce wird (faelschlich) als gueltig erkannt.
            Functions\when( 'check_ajax_referer' )->justReturn( true );

            $remoteCalled = false;
            Functions\when( 'wp_remote_request' )->alias(
                static function ( $url, $args ) use ( &$remoteCalled ) {
                    $remoteCalled = true;
                    return [ 'status' => 200, 'body' => '{}', 'headers' => [] ];
                }
            );

            $_POST = [
                'api_key'     => 'sk_test_with_valid_nonce',
                '_ajax_nonce' => 'valid_token',
            ];

            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                // Erwartet — wp_send_json_error mit 403.
                $this->assertFalse( $e->success );
                $this->assertSame( 403, $e->status );
            }

            $this->assertFalse(
                $remoteCalled,
                'AC-2: Auch bei valider Nonce MUSS Cap-Hard-Gate greifen — kein authenticate()-Call.'
            );
        }

        // ===================================================================
        // AC-3: Handler nutzt POST-Body-Key, NICHT die persistierte Option.
        // ===================================================================

        public function test_handle_uses_post_body_api_key_not_persisted_option(): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );
            Functions\when( 'check_ajax_referer' )->justReturn( true );

            // Persistierte Option hat einen ANDEREN Wert als der POST-Body-Key.
            // Wenn der Handler die Option lesen wuerde, taucht "OLD_PERSISTED_KEY"
            // im Authorization-Header auf — der Test asserted, dass stattdessen
            // der POST-Body-Key "sk_test_unsaved_42" verwendet wird.
            Functions\when( 'get_option' )->alias(
                static function ( string $name, $default = false ) {
                    if ( 'spreadconnect_api_key' === $name ) {
                        return 'OLD_PERSISTED_KEY';
                    }
                    if ( 'spreadconnect_use_staging' === $name ) {
                        return false;
                    }
                    return $default;
                }
            );

            $this->stubRemoteRequest( [
                'status'  => 200,
                'body'    => '{"pointOfSaleId":"pos_1","accountId":"acc_1"}',
                'headers' => [],
            ] );

            $_POST = [
                'api_key'     => 'sk_test_unsaved_42',
                '_ajax_nonce' => 'valid_token',
            ];

            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                $this->assertTrue( $e->success, 'AC-3: 200-Pfad terminiert via wp_send_json_success.' );
            }

            $this->assertCount(
                1,
                $this->remoteCalls,
                'AC-3: Genau ein wp_remote_request-Call (authenticate() macht einen GET).'
            );

            [ $url, $args ] = $this->remoteCalls[0];

            $this->assertArrayHasKey( 'headers', $args );
            $this->assertSame(
                'Bearer sk_test_unsaved_42',
                $args['headers']['Authorization'],
                'AC-3: Bearer-Header MUSS den POST-Body-Key "sk_test_unsaved_42" tragen — ' .
                'NICHT den persistierten "OLD_PERSISTED_KEY". Der Handler ignoriert die Option ' .
                'und verwendet den unsaved Key vom Form-Input.'
            );
            $this->assertStringNotContainsString(
                'OLD_PERSISTED_KEY',
                $args['headers']['Authorization'],
                'AC-3: Persistierter Option-Wert darf NICHT in den Outbound-Request leaken.'
            );

            $this->assertStringContainsString(
                '/authentication',
                $url,
                'AC-3: authenticate() ruft GET /authentication.'
            );
        }

        /**
         * AC-3: Sanitize-Pfad — `wp_unslash` UND `sanitize_text_field` werden
         * BEIDE auf den POST-Body-Key angewandt. Wir verifizieren das ueber
         * Slash-Escaping (das wp_unslash entfernt) UND Whitespace/Tags (das
         * sanitize_text_field strippt).
         */
        public function test_handle_sanitizes_post_body_api_key(): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );
            Functions\when( 'check_ajax_referer' )->justReturn( true );

            // Capture-Spies: erfassen Reihenfolge UND Wert.
            $unslashCalls   = [];
            $sanitizeCalls  = [];
            Functions\when( 'wp_unslash' )->alias(
                static function ( $value ) use ( &$unslashCalls ) {
                    $unslashCalls[] = $value;
                    return is_string( $value ) ? stripslashes( $value ) : $value;
                }
            );
            Functions\when( 'sanitize_text_field' )->alias(
                static function ( $value ) use ( &$sanitizeCalls ) {
                    $sanitizeCalls[] = $value;
                    if ( ! is_string( $value ) ) {
                        return '';
                    }
                    $clean = strip_tags( $value );
                    $clean = preg_replace( '/[\r\n\t]+/', ' ', $clean ) ?? '';
                    return trim( $clean );
                }
            );

            $this->stubRemoteRequest( [
                'status'  => 200,
                'body'    => '{}',
                'headers' => [],
            ] );

            // Magic-quotes-style escapierter Slash + leading/trailing whitespace
            // + Tags (die der Sanitize-Helper strippt).
            $_POST = [
                'api_key'     => "  <b>sk_test\\\"escaped</b>\n  ",
                '_ajax_nonce' => 'valid_token',
            ];

            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                // Egal welcher Pfad — wir wollen die Sanitize-Calls inspizieren.
            }

            $this->assertNotEmpty(
                $unslashCalls,
                'AC-3: wp_unslash MUSS auf den api_key-POST-Body angewandt werden.'
            );
            $this->assertNotEmpty(
                $sanitizeCalls,
                'AC-3: sanitize_text_field MUSS auf den api_key-POST-Body angewandt werden.'
            );

            // Nach beiden Sanitizern sollte der Bearer-Header KEINE Tags und
            // KEINE Backslash-Escapes mehr enthalten.
            if ( ! empty( $this->remoteCalls ) ) {
                [ $url, $args ] = $this->remoteCalls[0];
                $auth = $args['headers']['Authorization'] ?? '';

                $this->assertStringNotContainsString(
                    '<b>',
                    $auth,
                    'AC-3: sanitize_text_field MUSS HTML-Tags strippen — Bearer-Header darf <b> nicht enthalten.'
                );
                $this->assertStringNotContainsString(
                    "\\\"",
                    $auth,
                    'AC-3: wp_unslash MUSS Backslash-Escapes entfernen.'
                );
                $this->assertStringStartsWith(
                    'Bearer ',
                    $auth,
                    'AC-3: Authorization-Header MUSS mit "Bearer " beginnen.'
                );
            }
        }

        // ===================================================================
        // AC-4: Erfolgs-Pfad — AuthOk -> wp_send_json_success mit ok=true +
        //       Valid-Message.
        // ===================================================================

        public function test_handle_returns_success_with_valid_message_on_authenticate_ok(): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );
            Functions\when( 'check_ajax_referer' )->justReturn( true );

            // 200-Response -> SpreadconnectClient::authenticate() returned AuthOk.
            $this->stubRemoteRequest( [
                'status'  => 200,
                'body'    => '{"pointOfSaleId":"pos_42","accountId":"acc_7"}',
                'headers' => [],
            ] );

            $_POST = [
                'api_key'     => 'sk_test_valid',
                '_ajax_nonce' => 'valid_token',
            ];

            $thrown = null;
            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                $thrown = $e;
            }

            $this->assertNotNull(
                $thrown,
                'AC-4: Handler MUSS via wp_send_json_success terminieren bei valid AuthOk.'
            );
            $this->assertTrue(
                $thrown->success,
                'AC-4: Termination MUSS wp_send_json_success (success=true) sein, NICHT _error.'
            );
            $this->assertArrayHasKey( 'ok', $thrown->payload, 'AC-4: Payload MUSS ok-Key haben.' );
            $this->assertTrue( $thrown->payload['ok'], 'AC-4: payload[ok] MUSS true sein.' );
            $this->assertArrayHasKey( 'message', $thrown->payload, 'AC-4: Payload MUSS message-Key haben.' );

            // U+2014 em-dash (—) ist Pflicht in der UX-String — genau wie
            // im Slice-12-Spec / wireframes.md Z. 622.
            $this->assertSame(
                'Valid — click Save to apply',
                $thrown->payload['message'],
                'AC-4: message MUSS exakt "Valid — click Save to apply" lauten ' .
                '(U+2014 em-dash, kein normales "-"). Quelle: wireframes.md Z. 622.'
            );
            $this->assertStringContainsString(
                "\u{2014}",
                $thrown->payload['message'],
                'AC-4: message MUSS U+2014 em-dash enthalten — kein ASCII-"-".'
            );
        }

        // ===================================================================
        // AC-5: 4xx-Pfad — SpreadconnectClientError -> wp_send_json_error mit
        //       ok=false + Invalid-Key-Message.
        // ===================================================================

        public function test_handle_returns_error_with_invalid_key_message_on_client_error(): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );
            Functions\when( 'check_ajax_referer' )->justReturn( true );

            // 401-Response -> SpreadconnectClient::authenticate() throws
            // SpreadconnectClientError('http_4xx', ...).
            $this->stubRemoteRequest( [
                'status'  => 401,
                'body'    => '{"error":"invalid_token"}',
                'headers' => [],
            ] );

            $_POST = [
                'api_key'     => 'sk_test_INVALID_KEY_LEAK_PROBE_xyz',
                '_ajax_nonce' => 'valid_token',
            ];

            $thrown = null;
            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                $thrown = $e;
            }

            $this->assertNotNull(
                $thrown,
                'AC-5: Handler MUSS via wp_send_json_error terminieren bei 401.'
            );
            $this->assertFalse(
                $thrown->success,
                'AC-5: Termination MUSS wp_send_json_error (success=false) sein.'
            );
            $this->assertArrayHasKey( 'ok', $thrown->payload );
            $this->assertFalse(
                $thrown->payload['ok'],
                'AC-5: payload[ok] MUSS false sein.'
            );
            $this->assertSame(
                'Invalid Key — check value or environment',
                $thrown->payload['message'],
                'AC-5: message MUSS exakt "Invalid Key — check value or environment" lauten ' .
                '(U+2014 em-dash). Quelle: wireframes.md Z. 622 + State-Variation `test_key_invalid` Z. 637.'
            );
            $this->assertStringContainsString(
                "\u{2014}",
                $thrown->payload['message'],
                'AC-5: message MUSS U+2014 em-dash enthalten — kein ASCII-"-".'
            );
        }

        /**
         * AC-5: Die Exception-Message des Clients (z. B.
         * "GET /authentication -> 401" mit Pfad und Status) leakt NICHT in
         * die JSON-Response. Der User sieht NUR die UX-String, keine API-
         * Diagnostics.
         */
        public function test_handle_does_not_leak_client_error_message_into_response(): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );
            Functions\when( 'check_ajax_referer' )->justReturn( true );
            $this->stubRemoteRequest( [
                'status'  => 403,
                'body'    => '{"error":"forbidden_account"}',
                'headers' => [],
            ] );

            $_POST = [
                'api_key'     => 'sk_test_revoked',
                '_ajax_nonce' => 'valid_token',
            ];

            $thrown = null;
            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                $thrown = $e;
            }

            $this->assertNotNull( $thrown );

            $message = $thrown->payload['message'] ?? '';
            // Diagnostic-Tokens des Slice-07 Log-Format-Strings duerfen NICHT
            // in der User-Response auftauchen.
            $this->assertStringNotContainsString(
                '/authentication',
                $message,
                'AC-5: Endpoint-Pfad darf NICHT in der User-Response auftauchen — kein API-Detail-Leak.'
            );
            $this->assertStringNotContainsString(
                '403',
                $message,
                'AC-5: HTTP-Status-Code darf NICHT in der User-Response auftauchen.'
            );
            $this->assertStringNotContainsString(
                'forbidden_account',
                $message,
                'AC-5: Upstream-Error-Body darf NICHT in der User-Response auftauchen.'
            );
            $this->assertStringNotContainsString(
                'http_4xx',
                $message,
                'AC-5: Internal Error-Code (http_4xx) darf NICHT in der User-Response auftauchen.'
            );
        }

        // ===================================================================
        // AC-6: 5xx/429/Network — SpreadconnectTransientError -> generische
        //       Connection-Failed-Message.
        // ===================================================================

        public function test_handle_returns_generic_failure_on_transient_error(): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );
            Functions\when( 'check_ajax_referer' )->justReturn( true );

            // 503-Response -> SpreadconnectClient::authenticate() throws
            // SpreadconnectTransientError('http_5xx', ...).
            $this->stubRemoteRequest( [
                'status'  => 503,
                'body'    => '{"error":"service_unavailable"}',
                'headers' => [],
            ] );

            $_POST = [
                'api_key'     => 'sk_test_at_503',
                '_ajax_nonce' => 'valid_token',
            ];

            $thrown = null;
            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                $thrown = $e;
            }

            $this->assertNotNull(
                $thrown,
                'AC-6: Handler MUSS via wp_send_json_error terminieren bei 5xx.'
            );
            $this->assertFalse(
                $thrown->success,
                'AC-6: Termination MUSS wp_send_json_error sein.'
            );
            $this->assertFalse(
                $thrown->payload['ok'] ?? null,
                'AC-6: payload[ok] MUSS false sein.'
            );
            $this->assertSame(
                'Connection failed — please try again',
                $thrown->payload['message'],
                'AC-6: message MUSS exakt "Connection failed — please try again" lauten ' .
                '(U+2014 em-dash). Generisch — kein Inner-Retry, der User soll selbst nochmal klicken.'
            );
            $this->assertStringContainsString(
                "\u{2014}",
                $thrown->payload['message'],
                'AC-6: message MUSS U+2014 em-dash enthalten.'
            );
        }

        /**
         * AC-6: Auch fuer Network-Failures (WP_Error aus wp_remote_request)
         * MUSS die generische Connection-Failed-Message gesendet werden —
         * SpreadconnectTransientError wird mit code=`network_error` geworfen.
         */
        public function test_handle_returns_generic_failure_on_network_error(): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );
            Functions\when( 'check_ajax_referer' )->justReturn( true );

            // WP_Error -> SpreadconnectClient throws SpreadconnectTransientError('network_error').
            $this->stubRemoteRequest(
                new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' )
            );

            $_POST = [
                'api_key'     => 'sk_test_network',
                '_ajax_nonce' => 'valid_token',
            ];

            $thrown = null;
            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                $thrown = $e;
            }

            $this->assertNotNull( $thrown );
            $this->assertFalse( $thrown->success, 'AC-6 (network): MUSS wp_send_json_error sein.' );
            $this->assertSame(
                'Connection failed — please try again',
                $thrown->payload['message'],
                'AC-6 (network): Generische Message gilt auch fuer WP_Error/Network-Failures.'
            );
        }

        // ===================================================================
        // AC-7: Empty/missing api_key -> sofort error, kein authenticate-Call.
        // ===================================================================

        public function test_handle_rejects_empty_api_key_without_calling_authenticate(): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );
            Functions\when( 'check_ajax_referer' )->justReturn( true );
            $this->stubRemoteRequestForbidden();

            $_POST = [
                'api_key'     => '',
                '_ajax_nonce' => 'valid_token',
            ];

            $thrown = null;
            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                $thrown = $e;
            }

            $this->assertNotNull(
                $thrown,
                'AC-7: Handler MUSS via wp_send_json_error terminieren bei leerem api_key.'
            );
            $this->assertFalse( $thrown->success, 'AC-7: MUSS wp_send_json_error sein.' );
            $this->assertFalse( $thrown->payload['ok'] ?? null, 'AC-7: payload[ok] MUSS false sein.' );
            $this->assertSame(
                'API Key required',
                $thrown->payload['message'],
                'AC-7: message MUSS exakt "API Key required" lauten — dedizierte Empty-Key-UX.'
            );
            $this->assertSame(
                [],
                $this->remoteCalls,
                'AC-7: wp_remote_request darf NICHT aufgerufen werden bei leerem Key — ' .
                'kein leerer-Key-Roundtrip zur SC-API.'
            );
        }

        public function test_handle_rejects_missing_api_key_without_calling_authenticate(): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );
            Functions\when( 'check_ajax_referer' )->justReturn( true );
            $this->stubRemoteRequestForbidden();

            $_POST = [
                // KEIN api_key-Key uebermittelt.
                '_ajax_nonce' => 'valid_token',
            ];

            $thrown = null;
            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                $thrown = $e;
            }

            $this->assertNotNull(
                $thrown,
                'AC-7: Missing api_key MUSS genauso behandelt werden wie leerer api_key.'
            );
            $this->assertFalse( $thrown->success );
            $this->assertSame( 'API Key required', $thrown->payload['message'] );
            $this->assertSame( [], $this->remoteCalls );
        }

        public function test_handle_rejects_whitespace_only_api_key(): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );
            Functions\when( 'check_ajax_referer' )->justReturn( true );
            $this->stubRemoteRequestForbidden();

            // Whitespace-only Key — sanitize_text_field trimmt zu '' -> empty path.
            $_POST = [
                'api_key'     => "   \t\n   ",
                '_ajax_nonce' => 'valid_token',
            ];

            $thrown = null;
            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                $thrown = $e;
            }

            $this->assertNotNull(
                $thrown,
                'AC-7: Whitespace-only Key MUSS nach sanitize_text_field auf empty kollabieren ' .
                '-> "API Key required".'
            );
            $this->assertSame( 'API Key required', $thrown->payload['message'] );
            $this->assertSame( [], $this->remoteCalls );
        }

        // ===================================================================
        // AC-8: Settings-View rendert Section ② Markup mit Button + Status-
        //       Container + Nonce. Kein API-Key-Wert im Markup.
        // ===================================================================

        public function test_settings_view_renders_test_connection_button_with_nonce(): void
        {
            // wp_create_nonce -> deterministischer Token, damit der Test
            // ueberprueft, dass der EXAKTE Action-String an wp_create_nonce
            // uebergeben wird.
            $captured = [];
            Functions\when( 'wp_create_nonce' )->alias(
                static function ( $action ) use ( &$captured ): string {
                    $captured[] = $action;
                    return 'NONCE_TOKEN_TEST_42';
                }
            );

            // Slice-12 nutzt evtl. wp_print_inline_script_tag — wir stubben
            // es als Echo-Passthrough.
            Functions\when( 'wp_print_inline_script_tag' )->alias(
                static function ( string $js, array $attrs = [] ): void {
                    echo '<script>' . $js . '</script>';
                }
            );

            ob_start();
            try {
                Settings::renderTestConnectionSection();
            } catch ( \Throwable $e ) {
                ob_end_clean();
                $this->fail( 'AC-8: renderTestConnectionSection darf nicht werfen: ' . $e->getMessage() );
            }
            $html = (string) ob_get_clean();

            // (a) Button mit eindeutigem Selector existiert.
            $this->assertMatchesRegularExpression(
                '/<button\b[^>]*\bid=["\']spreadconnect-test-connection["\']/i',
                $html,
                'AC-8 (a): Section ② MUSS einen <button> mit id="spreadconnect-test-connection" rendern.'
            );

            // KEIN <input type="submit"> — der wuerde das Settings-Form submitten.
            $this->assertDoesNotMatchRegularExpression(
                '/<input[^>]*type=["\']submit["\']/i',
                $html,
                'AC-8 / Constraint: Test-Connection-Button MUSS <button type="button"> sein, ' .
                'NIEMALS <input type="submit"> (sonst submitted das Form).'
            );

            // (b) Status-Container existiert.
            $this->assertMatchesRegularExpression(
                '/<(?:span|div|p)\b[^>]*\bid=["\']spreadconnect-test-status["\']/i',
                $html,
                'AC-8 (b): Section ② MUSS einen Status-Container mit id="spreadconnect-test-status" rendern.'
            );

            // (c) Nonce-Token im Markup eingebettet — als data-* Attribut ODER
            //     im Inline-Script (wp_localize_script). Wir pruefen, dass
            //     wp_create_nonce mit dem korrekten Action-String aufgerufen
            //     wurde UND der Token irgendwo im Markup auftaucht.
            $this->assertContains(
                'spreadconnect_test_connection',
                $captured,
                'AC-8 (c): wp_create_nonce MUSS mit Action-String "spreadconnect_test_connection" aufgerufen werden ' .
                '(muss mit check_ajax_referer-String im Handler uebereinstimmen).'
            );
            $this->assertStringContainsString(
                'NONCE_TOKEN_TEST_42',
                $html,
                'AC-8 (c): Generierter Nonce-Token MUSS im Markup enthalten sein ' .
                '(als data-nonce Attribut, im Inline-JS, oder via wp_localize_script).'
            );
        }

        public function test_settings_view_never_renders_api_key_value(): void
        {
            $loadedKeyValue = 'sk_LIVE_PERSISTED_SECRET_42';

            Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE_X' );
            Functions\when( 'wp_print_inline_script_tag' )->alias(
                static function ( string $js, array $attrs = [] ): void {
                    echo '<script>' . $js . '</script>';
                }
            );
            // Selbst wenn renderTestConnectionSection den persistierten Wert
            // versehentlich liest — er DARF NICHT im Output landen.
            Functions\when( 'get_option' )->alias(
                static function ( $name, $default = false ) use ( $loadedKeyValue ) {
                    if ( 'spreadconnect_api_key' === $name ) {
                        return $loadedKeyValue;
                    }
                    return $default;
                }
            );

            ob_start();
            try {
                Settings::renderTestConnectionSection();
            } catch ( \Throwable $e ) {
                ob_end_clean();
                $this->fail( 'renderTestConnectionSection threw: ' . $e->getMessage() );
            }
            $html = (string) ob_get_clean();

            $this->assertStringNotContainsString(
                $loadedKeyValue,
                $html,
                'AC-8: Section ② Markup darf NIEMALS den API-Key-Wert leaken — auch nicht den ' .
                'persistierten. JS liest den Wert erst zur Klick-Zeit aus dem Form-Input.'
            );
        }

        // ===================================================================
        // AC-10: register() haengt sich an wp_ajax_spreadconnect_test_connection
        //        — kein wp_ajax_nopriv_*.
        // ===================================================================

        public function test_register_hooks_only_authenticated_ajax_action(): void
        {
            $captured = [];
            Functions\when( 'add_action' )->alias(
                static function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$captured ) {
                    $captured[] = [
                        'hook'     => $hook,
                        'callback' => $callback,
                        'priority' => $priority,
                    ];
                }
            );

            TestConnection::register();

            // Genau ein add_action-Call.
            $this->assertCount(
                1,
                $captured,
                'AC-10: register() MUSS GENAU EINEN add_action-Call erzeugen — kein wp_ajax_nopriv_*.'
            );

            $hooks = array_column( $captured, 'hook' );
            $this->assertSame(
                [ 'wp_ajax_spreadconnect_test_connection' ],
                $hooks,
                'AC-10: register() MUSS auf "wp_ajax_spreadconnect_test_connection" hooken — ' .
                'NICHT auf wp_ajax_nopriv_* (admin-only, kein Public-Access).'
            );

            // Callback MUSS auf TestConnection::handle zeigen.
            $callback = $captured[0]['callback'];
            $this->assertIsArray(
                $callback,
                'AC-10: add_action-Callback MUSS ein [class, method]-Array sein.'
            );
            $this->assertCount( 2, $callback );
            $this->assertSame(
                TestConnection::class,
                ltrim( (string) $callback[0], '\\' ),
                'AC-10: add_action-Callback MUSS auf TestConnection-Klasse zeigen.'
            );
            $this->assertSame(
                'handle',
                $callback[1],
                'AC-10: add_action-Callback MUSS auf handle()-Methode zeigen.'
            );
        }

        /**
         * AC-10: Statische Source-Analyse — register() darf KEINE
         * wp_ajax_nopriv_*-Action registrieren (Defense-in-Depth gegen
         * accidental Public-Exposure).
         */
        public function test_register_source_does_not_reference_nopriv_action(): void
        {
            $source = (string) file_get_contents( self::handlerFile() );
            $this->assertNotSame( '', $source, 'AC-10: TestConnection.php MUSS lesbar sein.' );

            // Strip PHP doc-block / line-comments — Erwaehnungen in Kommentaren
            // (z. B. "kein `wp_ajax_nopriv_*` registriert") sind erlaubt; nur
            // ein aktiver String-Literal in Code-Pfaden waere ein Defekt.
            $codeOnly = preg_replace( '#/\*[\s\S]*?\*/#', '', $source ) ?? '';
            $codeOnly = preg_replace( '#//.*$#m', '', $codeOnly ) ?? '';

            $this->assertDoesNotMatchRegularExpression(
                "/['\"]wp_ajax_nopriv_/",
                $codeOnly,
                'AC-10: TestConnection.php darf "wp_ajax_nopriv_" NICHT als String-Literal in Code ' .
                'verwenden (Kommentar-Erwaehnungen sind erlaubt) — der Handler ist admin-only.'
            );
        }

        // ===================================================================
        // AC-11: API-Key-Wert taucht weder in Response noch (defensiv) in
        //        error_log auf.
        // ===================================================================

        public function test_handle_never_echoes_or_logs_api_key_value(): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
            );
            Functions\when( 'check_ajax_referer' )->justReturn( true );

            // Sentinel-Token im POST-Body — wir suchen ihn nachher in JEDER
            // Response-Message UND in JEDEM Logger-Spy-Eintrag.
            $secretKey = 'sk_test_SENSITIVE_TOKEN_DO_NOT_LEAK_xyz789';

            $loggerEntries = [];
            Functions\when( 'wc_get_logger' )->alias( static function () use ( &$loggerEntries ) {
                return new class( $loggerEntries ) {
                    /** @var array<int,array<string,mixed>> */
                    private array $entries;
                    public function __construct( array &$entries ) {
                        $this->entries = &$entries;
                    }
                    private function record( string $level, string $message, array $context ): void {
                        $this->entries[] = [
                            'level' => $level,
                            'message' => $message,
                            'context' => $context,
                        ];
                    }
                    public function info( string $message, array $context = [] ): void {
                        $this->record( 'info', $message, $context );
                    }
                    public function warning( string $message, array $context = [] ): void {
                        $this->record( 'warning', $message, $context );
                    }
                    public function error( string $message, array $context = [] ): void {
                        $this->record( 'error', $message, $context );
                    }
                    public function debug( string $message, array $context = [] ): void {
                        $this->record( 'debug', $message, $context );
                    }
                };
            } );

            // 4xx response so we exercise the error-path that logs.
            $this->stubRemoteRequest( [
                'status'  => 401,
                'body'    => '{"error":"unauthorized"}',
                'headers' => [],
            ] );

            $_POST = [
                'api_key'     => $secretKey,
                '_ajax_nonce' => 'valid_token',
            ];

            $thrown = null;
            try {
                TestConnection::handle();
            } catch ( JsonResponseSentinel $e ) {
                $thrown = $e;
            }

            $this->assertNotNull( $thrown );

            // (a) Response-Payload enthaelt den Key NICHT — weder als Echo
            //     noch als Substring der Message.
            foreach ( $thrown->payload as $key => $value ) {
                if ( ! is_scalar( $value ) ) {
                    continue;
                }
                $this->assertStringNotContainsString(
                    $secretKey,
                    (string) $value,
                    sprintf(
                        'AC-11: API-Key-Wert darf NICHT in payload[%s] auftauchen.',
                        is_string( $key ) ? $key : (string) $key
                    )
                );
            }

            // (b) Logger-Spy enthaelt den Key NICHT — weder als Substring der
            //     Message, noch als Wert im Context-Array. Slice-07 redactet
            //     bereits zu "Bearer ***", aber Slice-12 darf keinen
            //     EIGENEN Logger-Call hinzufuegen, der den Key leakt.
            foreach ( $loggerEntries as $entry ) {
                $this->assertStringNotContainsString(
                    $secretKey,
                    (string) $entry['message'],
                    sprintf(
                        'AC-11: API-Key darf NICHT im Logger-Message auftauchen (level=%s, message=%s).',
                        $entry['level'],
                        $entry['message']
                    )
                );
                $contextJson = json_encode( $entry['context'] ) ?: '';
                $this->assertStringNotContainsString(
                    $secretKey,
                    $contextJson,
                    sprintf(
                        'AC-11: API-Key darf NICHT im Logger-Context auftauchen (level=%s).',
                        $entry['level']
                    )
                );
            }
        }

        /**
         * AC-11: Statische Source-Analyse — TestConnection.php enthaelt KEIN
         * `error_log`-Call, das den API-Key durchschlagen koennte.
         */
        public function test_handler_source_does_not_call_error_log(): void
        {
            $source = (string) file_get_contents( self::handlerFile() );

            $this->assertDoesNotMatchRegularExpression(
                '/\\berror_log\\s*\\(/',
                $source,
                'AC-11: TestConnection.php darf KEINEN error_log()-Call enthalten — ' .
                'das einzige erlaubte Logging laeuft via WcLoggerAdapter im Slice-07 Client (mit Bearer-Redaction).'
            );
        }

        // ===================================================================
        // Zusatz: i18n-Strings nutzen die richtige Domain "spreadconnect-pod"
        //         und enthalten alle den vorgeschriebenen U+2014 em-dash.
        // ===================================================================

        /**
         * Static-source assertion: der Handler MUSS die UX-Strings exakt mit
         * U+2014 em-dash speichern. Verhindert Drift gegen wireframes.md
         * Z. 622 / Z. 637.
         */
        public function test_handler_source_contains_em_dash_uxstrings(): void
        {
            $source = (string) file_get_contents( self::handlerFile() );

            $emDash = "\u{2014}"; // U+2014 em-dash.

            $this->assertStringContainsString(
                'Valid ' . $emDash . ' click Save to apply',
                $source,
                'AC-4: Source MUSS exakt "Valid — click Save to apply" enthalten (U+2014 em-dash).'
            );
            $this->assertStringContainsString(
                'Invalid Key ' . $emDash . ' check value or environment',
                $source,
                'AC-5: Source MUSS exakt "Invalid Key — check value or environment" enthalten (U+2014 em-dash).'
            );
            $this->assertStringContainsString(
                'Connection failed ' . $emDash . ' please try again',
                $source,
                'AC-6: Source MUSS exakt "Connection failed — please try again" enthalten (U+2014 em-dash).'
            );
        }

        /**
         * Static-source assertion: alle __()-Aufrufe in TestConnection.php
         * nutzen die Text-Domain "spreadconnect-pod" (oder die zentrale
         * Konstante TEXT_DOMAIN, die diesen Wert haelt).
         */
        public function test_handler_source_uses_correct_text_domain(): void
        {
            $source = (string) file_get_contents( self::handlerFile() );

            // TEXT_DOMAIN-Konstante existiert UND haelt 'spreadconnect-pod'.
            $reflection = new ReflectionClass( TestConnection::class );
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
                'AC-4/5/6: TestConnection::TEXT_DOMAIN MUSS exakt "spreadconnect-pod" sein.'
            );

            // Alle __()-Aufrufe nutzen entweder das literale 'spreadconnect-pod'
            // oder self::TEXT_DOMAIN.
            $callPattern = '/(?:_e|__|esc_html__|esc_attr__)\s*\(\s*[^,()]+(?:\(\s*[^()]*\s*\))?\s*,\s*([^)]+)\)/';
            $matches = [];
            preg_match_all( $callPattern, $source, $matches );

            $this->assertNotEmpty(
                $matches[0],
                'AC-4/5/6: TestConnection.php MUSS __()-Aufrufe enthalten (UX-Messages).'
            );

            $allowed = [
                "'spreadconnect-pod'",
                '"spreadconnect-pod"',
                'self::TEXT_DOMAIN',
            ];
            foreach ( $matches[1] as $idx => $domainArg ) {
                $domainArg = trim( $domainArg );
                $ok        = false;
                foreach ( $allowed as $token ) {
                    if ( str_contains( $domainArg, $token ) ) {
                        $ok = true;
                        break;
                    }
                }
                $this->assertTrue(
                    $ok,
                    sprintf(
                        'AC-4/5/6: i18n-Aufruf #%d nutzt unerlaubte Domain "%s". Erwartet: ' .
                        '"spreadconnect-pod" oder self::TEXT_DOMAIN. Match: %s',
                        $idx,
                        $domainArg,
                        $matches[0][$idx]
                    )
                );
            }
        }
    }
}
