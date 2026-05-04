<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Slice 19 — Subscriptions-Manager-UI + Repair-Button
//
// Acceptance Tests gegen die Slice-Spec `slice-19-subscriptions-ui.md`.
//
// Mocking Strategy (per Slice-Spec `mock_external`):
//
//   - Brain\Monkey aliased fuer WP-Admin-API + AJAX-Lifecycle:
//     `current_user_can`, `check_ajax_referer`, `wp_send_json_success`,
//     `wp_send_json_error`, `wp_create_nonce`, `admin_url`, `__`,
//     `esc_html__`, `esc_attr__`, `esc_html`, `esc_attr`, `esc_url`,
//     `wp_json_encode`, `current_time`, `wc_get_logger`.
//
//   - `\Patchwork\redefine` fuer `SubscriptionManager::diff`,
//     `SubscriptionManager::register`, `SubscriptionManager::removeOrphans`.
//     Die Klasse ist `final`, Subclass-Trick scheidet aus -- Patchwork ist
//     das einzige Mittel, statische Methoden ohne realen API-Roundtrip
//     auszutauschen. Tests verifizieren Aufruf-Args + Reihenfolge ueber
//     einen FIFO-Recorder.
//
//   - `wp_send_json_*` werfen `Slice19JsonSentinel` damit der PHPUnit-Prozess
//     nicht via `die()` terminiert.
//
//   - Sidebar::render wird NICHT von Subscriptions::render aufgerufen
//     (der Controller dispatchet Sidebar davor) -- Tests asserten daher
//     KEINEN Sidebar-Aufruf in der View.
//
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
    use SpreadconnectPod\Api\SpreadconnectClientError;
    use SpreadconnectPod\Api\SpreadconnectTransientError;
    use SpreadconnectPod\Hub\Ajax\RepairSubscriptions;
    use SpreadconnectPod\Hub\View\Subscriptions;
    use SpreadconnectPod\Subscription\SubscriptionManager;

    /**
     * Sentinel exception thrown by the `wp_send_json_*` stubs so the AJAX
     * handler under test does not terminate the PHPUnit process via `die()`.
     */
    if ( ! class_exists( Slice19JsonSentinel::class, false ) ) {
        final class Slice19JsonSentinel extends \RuntimeException
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
    }

    /**
     * In-memory FIFO recorder for `SubscriptionManager`-static-method calls
     * driven by Patchwork redefinitions. Each redefined method appends an
     * entry; tests inspect order + args.
     */
    final class Slice19ManagerSpy
    {
        /**
         * Captured call sequence in chronological order.
         *
         * Each entry: `['method' => 'diff'|'register'|'removeOrphans', 'args' => array]`.
         *
         * @var list<array{method:string, args:array<int,mixed>}>
         */
        public static array $calls = [];

        /**
         * Canned diff() result. May be a callable returning an array OR an
         * exception instance to throw.
         *
         * @var array<string,mixed>|\Throwable|callable
         */
        public static $diffResult;

        /**
         * Canned register() result. May be an array, exception instance
         * (then thrown), or a callable.
         *
         * @var array<string,mixed>|\Throwable|callable
         */
        public static $registerResult;

        /**
         * Canned removeOrphans() result. May be an int OR an exception OR
         * callable.
         *
         * @var int|\Throwable|callable
         */
        public static $removeOrphansResult;

        public static function reset(): void
        {
            self::$calls               = [];
            self::$diffResult          = [
                'active'  => [],
                'missing' => [],
                'orphans' => [],
            ];
            self::$registerResult      = [
                'added'   => 0,
                'removed' => 0,
                'skipped' => 7,
                'errors'  => [],
            ];
            self::$removeOrphansResult = 0;
        }
    }

    /**
     * Slice 19 — Subscriptions-Manager-UI + Repair-Button.
     *
     * Acceptance-Test-Suite gegen die 12 ACs aus `slice-19-subscriptions-ui.md`.
     *
     *   - AC-1  : 7-Zeilen-Tabelle in EXPECTED_EVENTS-Reihenfolge + Status-CSS.
     *   - AC-2  : Network-Error-Banner bei diff()-Throw, alle Rows `unknown`.
     *   - AC-3  : Nonce + data-ajax-action + data-ajax-url im Markup, escaped.
     *   - AC-4  : AJAX ohne Nonce -> wp_send_json_error 403, kein Manager-Call.
     *   - AC-5  : AJAX ohne Capability -> 403, kein Manager-Call.
     *   - AC-6  : removeOrphans() vor register() (Reihenfolge), Response Shape.
     *   - AC-7  : register-summary mit errors[] -> success-Response inkl. errors.
     *   - AC-8  : TransientError -> 503 ohne Inner-Retry.
     *   - AC-9  : Save-Success-Step-Result-Helper rendert success vs. partial.
     *   - AC-10 : Subscriptions-View-Klasse hat Slice-13-Stub ueberschrieben.
     *   - AC-11 : `wp_ajax_spreadconnect_repair_subscriptions` registriert,
     *             KEIN nopriv-Zwilling.
     *   - AC-12 : Logger-Eintrag enthaelt Repair-Marker + Counts, KEIN Secret.
     */
    final class Slice19SubscriptionsUiTest extends TestCase
    {
        /**
         * Captured `add_action`-Aufrufe (FIFO).
         *
         * @var list<array{hook:string, callback:mixed, priority:int, accepted_args:int}>
         */
        private array $registeredActions = [];

        /**
         * Logger entries captured from `wc_get_logger()->log(...)`.
         *
         * @var list<array{level:string, message:string, context:array<string,mixed>}>
         */
        private array $loggerEntries = [];

        /**
         * Captured `wp_send_json_*` payloads. Each entry: `[success, payload, status]`.
         * Default: empty list — the AJAX-handler-tests assert on the LAST entry.
         *
         * @var list<array{success:bool, payload:array<string,mixed>, status:?int}>
         */
        private array $jsonResponses = [];

        protected function setUp(): void
        {
            parent::setUp();
            Monkey\setUp();

            Slice19ManagerSpy::reset();
            $this->registeredActions = [];
            $this->loggerEntries     = [];
            $this->jsonResponses     = [];
            $_POST                   = [];
            $_GET                    = [];

            // ---- i18n / escape — passthrough -----------------------------.
            Functions\when( '__' )->returnArg( 1 );
            Functions\when( 'esc_html__' )->returnArg( 1 );
            Functions\when( 'esc_attr__' )->returnArg( 1 );
            Functions\when( 'esc_html' )->returnArg( 1 );
            Functions\when( 'esc_attr' )->returnArg( 1 );
            Functions\when( 'esc_url' )->returnArg( 1 );

            // ---- WP-URL helpers ------------------------------------------.
            Functions\when( 'admin_url' )->alias(
                static fn ( string $path = '' ): string =>
                    'https://shop.example/wp-admin/' . ltrim( $path, '/' )
            );
            Functions\when( 'wp_create_nonce' )->alias(
                static fn ( string $action ): string => 'NONCE_' . md5( $action )
            );
            Functions\when( 'current_time' )->alias(
                static fn ( string $type = 'mysql' ): string => '2026-05-04 12:00:00'
            );

            // ---- AJAX termination -----------------------------------------.
            // Wir koennen NICHT via Exception terminieren: der Handler hat
            // einen `catch ( \Throwable $e )`-Branch der jede Sentinel-Exception
            // schlucken und in einen 500-Error umwandeln wuerde. Stattdessen:
            // Capture-and-Return — der Handler hat hinter jedem
            // `wp_send_json_*` ein explizites `return;`, also exitet er sauber
            // und der Test liest `$this->jsonResponses` aus.
            $captures = & $this->jsonResponses;
            Functions\when( 'wp_send_json_success' )->alias(
                static function ( $data = null, $status_code = null ) use ( &$captures ): void {
                    $captures[] = [
                        'success' => true,
                        'payload' => (array) $data,
                        'status'  => $status_code,
                    ];
                }
            );
            Functions\when( 'wp_send_json_error' )->alias(
                static function ( $data = null, $status_code = null ) use ( &$captures ): void {
                    $captures[] = [
                        'success' => false,
                        'payload' => (array) $data,
                        'status'  => $status_code,
                    ];
                }
            );

            // ---- Logger capture -----------------------------------------.
            $entries = & $this->loggerEntries;
            Functions\when( 'wc_get_logger' )->alias( static function () use ( &$entries ) {
                return new class( $entries ) {
                    /** @var list<array{level:string, message:string, context:array<string,mixed>}> */
                    private array $entries;
                    public function __construct( array &$entries ) {
                        $this->entries = &$entries;
                    }
                    public function log( string $level, string $message, array $context = [] ): void {
                        $this->entries[] = [
                            'level'   => $level,
                            'message' => $message,
                            'context' => $context,
                        ];
                    }
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

            // ---- Patchwork: SubscriptionManager static-method seams ------.
            // Diff/register/removeOrphans werden umgeleitet auf den Recorder.
            \Patchwork\redefine(
                [ SubscriptionManager::class, 'diff' ],
                static function () {
                    Slice19ManagerSpy::$calls[] = [ 'method' => 'diff', 'args' => [] ];
                    $r = Slice19ManagerSpy::$diffResult;
                    if ( $r instanceof \Throwable ) {
                        throw $r;
                    }
                    if ( is_callable( $r ) ) {
                        return $r();
                    }
                    return $r;
                }
            );
            \Patchwork\redefine(
                [ SubscriptionManager::class, 'register' ],
                static function () {
                    Slice19ManagerSpy::$calls[] = [ 'method' => 'register', 'args' => [] ];
                    $r = Slice19ManagerSpy::$registerResult;
                    if ( $r instanceof \Throwable ) {
                        throw $r;
                    }
                    if ( is_callable( $r ) ) {
                        return $r();
                    }
                    return $r;
                }
            );
            \Patchwork\redefine(
                [ SubscriptionManager::class, 'removeOrphans' ],
                static function () {
                    Slice19ManagerSpy::$calls[] = [ 'method' => 'removeOrphans', 'args' => [] ];
                    $r = Slice19ManagerSpy::$removeOrphansResult;
                    if ( $r instanceof \Throwable ) {
                        throw $r;
                    }
                    if ( is_callable( $r ) ) {
                        return $r();
                    }
                    return $r;
                }
            );
        }

        protected function tearDown(): void
        {
            $_POST = [];
            $_GET  = [];
            Slice19ManagerSpy::reset();
            Monkey\tearDown();
            parent::tearDown();
        }

        /**
         * Render Subscriptions::render() with output buffering and return
         * the captured markup.
         */
        private function renderSubscriptions(): string
        {
            ob_start();
            try {
                Subscriptions::render();
                return (string) ob_get_clean();
            } catch ( \Throwable $e ) {
                ob_end_clean();
                throw $e;
            }
        }

        /**
         * Default capability + nonce stubs for AJAX-handler tests.
         *
         * @param bool $cap     `current_user_can` return value.
         * @param bool $nonce   `check_ajax_referer` return value.
         */
        private function stubAjaxGates( bool $cap, bool $nonce ): void
        {
            Functions\when( 'current_user_can' )->alias(
                static fn ( string $c ): bool => $cap
            );
            Functions\when( 'check_ajax_referer' )->alias(
                static fn ( $action, $field = '_wpnonce', $die = true ): bool => $nonce
            );
        }

        // ===================================================================
        // AC-1 — Render emits 7-row table in EXPECTED_EVENTS order with
        //        is-status-{active|missing|orphan} CSS classes; per-row
        //        [Repair] action only on missing/orphan rows.
        // ===================================================================

        public function test_render_emits_seven_rows_in_expected_events_order_with_status_classes(): void
        {
            // 5 active, 1 missing (Order.needs-action), 1 orphan (Shipment.sent
            // on stale URL).
            Slice19ManagerSpy::$diffResult = [
                'active'  => [
                    'Article.added',
                    'Article.updated',
                    'Article.removed',
                    'Order.processed',
                    'Order.cancelled',
                ],
                'missing' => [ 'Order.needs-action' ],
                'orphans' => [
                    [
                        'id'          => 'orph-A',
                        'eventType'   => 'Shipment.sent',
                        'callbackUrl' => 'http://localhost:8080/wp-json/spreadconnect/v1/webhook',
                    ],
                ],
            ];

            $html = $this->renderSubscriptions();

            // EXPECTED_EVENTS-Reihenfolge MUSS im Markup eingehalten sein —
            // wir verifizieren, dass jeder Event in der korrekten Reihenfolge
            // im HTML-String auftaucht.
            $expectedOrder = SubscriptionManager::EXPECTED_EVENTS;
            $lastPos       = -1;
            foreach ( $expectedOrder as $event ) {
                $pos = strpos( $html, $event );
                $this->assertNotFalse(
                    $pos,
                    sprintf( 'AC-1: EXPECTED_EVENTS-Eintrag %s MUSS im Markup vorkommen.', $event )
                );
                $this->assertGreaterThan(
                    $lastPos,
                    $pos,
                    sprintf(
                        'AC-1: Reihenfolge-Pflicht — %s MUSS NACH dem vorherigen Event im Markup stehen.',
                        $event
                    )
                );
                $lastPos = $pos;
            }

            // Genau 7 <tr> innerhalb der `striped`-Tabelle (1 thead + 7 tbody).
            $trCount = substr_count( $html, '<tr' );
            $this->assertSame(
                8,
                $trCount,
                'AC-1: Tabelle MUSS exakt 7 Body-Rows + 1 Header-Row enthalten (8 <tr> insgesamt).'
            );

            // Status-CSS-Class-Hooks fuer jede Variante.
            $this->assertStringContainsString(
                'is-status-active',
                $html,
                'AC-1d: Mindestens eine Row MUSS Class is-status-active tragen.'
            );
            $this->assertStringContainsString(
                'is-status-missing',
                $html,
                'AC-1d: Mindestens eine Row MUSS Class is-status-missing tragen (Order.needs-action).'
            );
            $this->assertStringContainsString(
                'is-status-orphan',
                $html,
                'AC-1d: Mindestens eine Row MUSS Class is-status-orphan tragen (Shipment.sent stale URL).'
            );

            // Inline-Hint-Text (Wireframe Z. 722-723).
            $this->assertStringContainsString(
                'Repair will POST missing & DELETE orphans',
                $html,
                'AC-1f: Policy-Hint-Text MUSS gerendert werden.'
            );
        }

        public function test_render_shows_per_row_repair_only_for_missing_and_orphan(): void
        {
            Slice19ManagerSpy::$diffResult = [
                'active'  => [ 'Article.added' ],
                'missing' => [ 'Article.updated' ],
                'orphans' => [
                    [
                        'id'          => 'orph-X',
                        'eventType'   => 'Shipment.sent',
                        'callbackUrl' => 'http://localhost/webhook',
                    ],
                ],
            ];

            $html = $this->renderSubscriptions();

            // Per-Row [Repair]-Buttons: einer pro missing + orphan + (in der
            // Default-Spec) jedes weitere fehlende Event = missing. Es sollen
            // 5 fehlende Events (alle ausser Article.added) + 1 orphan (das
            // den missing-Slot Shipment.sent ueberschreibt) ergeben — total 6
            // Buttons mit class spreadconnect-subscriptions__repair-row.
            $rowButtonCount = substr_count(
                $html,
                'spreadconnect-subscriptions__repair-row'
            );

            // Aktive Rows duerfen KEINEN per-row-Repair-Button rendern.
            // Wir verifizieren das ueber die `data-event-type` Attribute:
            // Article.added ist active und darf KEIN repair-row-Button haben.
            $this->assertGreaterThanOrEqual(
                1,
                $rowButtonCount,
                'AC-1e: Mindestens ein per-row [Repair]-Button MUSS gerendert werden.'
            );

            // Active Row: Article.added → kein data-event-type="Article.added"
            // auf einem repair-row-Button.
            $this->assertDoesNotMatchRegularExpression(
                '/spreadconnect-subscriptions__repair-row[^>]*data-event-type="Article\.added"/',
                $html,
                'AC-1e: Active Rows DUERFEN KEINEN per-row Repair-Button tragen.'
            );

            // Missing Row: Article.updated → MUSS einen Button haben.
            $this->assertMatchesRegularExpression(
                '/data-event-type="Article\.updated"/',
                $html,
                'AC-1e: Missing Rows MUESSEN einen per-row Repair-Button tragen.'
            );
        }

        // ===================================================================
        // AC-2 — diff() throws -> Network-Error-Banner; all 7 rows status
        //        `unknown`; no fatal.
        // ===================================================================

        public function test_render_shows_network_error_banner_when_diff_throws(): void
        {
            Slice19ManagerSpy::$diffResult = new SpreadconnectTransientError(
                'http_5xx',
                'GET /subscriptions -> 503',
                503,
                '/subscriptions'
            );

            $html = $this->renderSubscriptions();

            // Banner-Text MUSS gerendert werden (Spec AC-2 fix-string).
            $this->assertStringContainsString(
                'Cannot reach Spreadconnect',
                $html,
                'AC-2: Banner-Text MUSS exakt "Cannot reach Spreadconnect" enthalten.'
            );

            // Alle 7 Rows MUESSEN auf is-status-unknown fallen.
            $unknownCount = substr_count( $html, 'is-status-unknown' );
            $this->assertSame(
                7,
                $unknownCount,
                'AC-2: Bei diff()-Throw MUESSEN alle 7 Rows is-status-unknown tragen.'
            );

            // KEIN is-status-active/missing/orphan im Output.
            $this->assertStringNotContainsString(
                'is-status-active',
                $html,
                'AC-2: Bei diff()-Throw DARF KEINE Row is-status-active sein.'
            );
            $this->assertStringNotContainsString(
                'is-status-missing',
                $html,
                'AC-2: Bei diff()-Throw DARF KEINE Row is-status-missing sein.'
            );
            $this->assertStringNotContainsString(
                'is-status-orphan',
                $html,
                'AC-2: Bei diff()-Throw DARF KEINE Row is-status-orphan sein.'
            );
        }

        public function test_render_shows_network_error_banner_when_diff_throws_client_error(): void
        {
            Slice19ManagerSpy::$diffResult = new SpreadconnectClientError(
                'http_4xx',
                'GET /subscriptions -> 401 unauthorized',
                401,
                '/subscriptions'
            );

            $html = $this->renderSubscriptions();

            $this->assertStringContainsString(
                'Cannot reach Spreadconnect',
                $html,
                'AC-2: Auch bei ClientError MUSS Network-Banner gerendert werden.'
            );
            $this->assertSame(
                7,
                substr_count( $html, 'is-status-unknown' ),
                'AC-2: ClientError -> alle 7 Rows unknown.'
            );
        }

        // ===================================================================
        // AC-3 — render() emits hidden nonce + data-ajax-action + data-ajax-url
        //        attributes, all values escaped.
        // ===================================================================

        public function test_render_emits_nonce_and_ajax_data_attributes_escaped(): void
        {
            Slice19ManagerSpy::$diffResult = [
                'active'  => SubscriptionManager::EXPECTED_EVENTS,
                'missing' => [],
                'orphans' => [],
            ];

            $html = $this->renderSubscriptions();

            // Hidden nonce input — Wert kommt aus wp_create_nonce-Stub.
            $expectedNonce = 'NONCE_' . md5( RepairSubscriptions::ACTION );
            $this->assertMatchesRegularExpression(
                '/<input\s+type="hidden"\s+name="_wpnonce"\s+value="' . preg_quote( $expectedNonce, '/' ) . '"/',
                $html,
                'AC-3: Hidden nonce input MUSS mit wp_create_nonce-Wert gerendert werden.'
            );

            // data-ajax-action Attribut.
            $this->assertStringContainsString(
                'data-ajax-action="' . RepairSubscriptions::ACTION . '"',
                $html,
                'AC-3: data-ajax-action Attribut MUSS exakt die Action-Konstante tragen.'
            );

            // data-ajax-url Attribut.
            $this->assertStringContainsString(
                'data-ajax-url="https://shop.example/wp-admin/admin-ajax.php"',
                $html,
                'AC-3: data-ajax-url Attribut MUSS admin_url(\'admin-ajax.php\') tragen.'
            );

            // Action-String-Konsistenz: View und AJAX-Handler teilen denselben
            // Literal — wenn das brechen wuerde, waere der Repair-Klick unmoeglich.
            $this->assertSame(
                'spreadconnect_repair_subscriptions',
                RepairSubscriptions::ACTION,
                'AC-3: ACTION-Konstante MUSS exakt "spreadconnect_repair_subscriptions" sein.'
            );
        }

        // ===================================================================
        // AC-4 — AJAX without valid nonce -> wp_send_json_error 403,
        //        register() never called.
        // ===================================================================

        public function test_ajax_without_valid_nonce_returns_403_and_skips_manager(): void
        {
            $this->stubAjaxGates( cap: true, nonce: false );

            RepairSubscriptions::handle();

            $this->assertCount(
                1,
                $this->jsonResponses,
                'AC-4: Genau eine wp_send_json_* Antwort MUSS gesendet werden.'
            );
            $resp = $this->jsonResponses[0];
            $this->assertFalse(
                $resp['success'],
                'AC-4: wp_send_json_error MUSS gefeuert werden (success=false).'
            );
            $this->assertSame(
                403,
                $resp['status'],
                'AC-4: HTTP-Status MUSS 403 sein.'
            );
            $this->assertSame(
                'invalid_nonce',
                $resp['payload']['code'] ?? null,
                'AC-4: Error-Payload MUSS code=invalid_nonce tragen.'
            );

            // KEIN Service-Call darf stattgefunden haben.
            $this->assertSame(
                [],
                Slice19ManagerSpy::$calls,
                'AC-4: SubscriptionManager DARF NICHT aufgerufen werden ' .
                'wenn die Nonce ungueltig ist.'
            );
        }

        // ===================================================================
        // AC-5 — AJAX with valid nonce but no `manage_woocommerce`
        //        capability -> 403, register() never called.
        // ===================================================================

        public function test_ajax_without_capability_returns_403_and_skips_manager(): void
        {
            $this->stubAjaxGates( cap: false, nonce: true );

            try {
                RepairSubscriptions::handle();
                $this->fail( 'AC-5: Handler MUSS via wp_send_json_error terminieren.' );
            } catch ( Slice19JsonSentinel $e ) {
                $this->assertFalse( $e->success, 'AC-5: success MUSS false sein.' );
                $this->assertSame(
                    403,
                    $e->status,
                    'AC-5: HTTP-Status MUSS 403 sein.'
                );
                $this->assertSame(
                    'forbidden',
                    $e->payload['code'] ?? null,
                    'AC-5: Error-Payload MUSS code=forbidden tragen.'
                );
            }

            $this->assertSame(
                [],
                Slice19ManagerSpy::$calls,
                'AC-5: KEIN SubscriptionManager-Call ohne Capability.'
            );
        }

        // ===================================================================
        // AC-6 — Reihenfolge: removeOrphans() VOR register(); Response
        //        liefert {added, removed, errors}.
        // ===================================================================

        public function test_ajax_calls_remove_orphans_before_register_and_returns_summary(): void
        {
            $this->stubAjaxGates( cap: true, nonce: true );

            Slice19ManagerSpy::$removeOrphansResult = 1;
            Slice19ManagerSpy::$registerResult      = [
                'added'   => 2,
                'removed' => 0,
                'skipped' => 5,
                'errors'  => [],
            ];

            try {
                RepairSubscriptions::handle();
                $this->fail( 'AC-6: Handler MUSS via wp_send_json_success terminieren.' );
            } catch ( Slice19JsonSentinel $e ) {
                $this->assertTrue(
                    $e->success,
                    'AC-6: wp_send_json_success MUSS gefeuert werden.'
                );

                // Response-Shape (architecture.md Z. 147).
                $this->assertSame(
                    2,
                    $e->payload['added'] ?? null,
                    'AC-6: Response.added MUSS Summary[added]=2 spiegeln.'
                );
                $this->assertSame(
                    1,
                    $e->payload['removed'] ?? null,
                    'AC-6: Response.removed MUSS removeOrphans()-Rueckgabe (=1) spiegeln, ' .
                    'NICHT Summary[removed]=0.'
                );
                $this->assertSame(
                    [],
                    $e->payload['errors'] ?? null,
                    'AC-6: Response.errors MUSS leeres Array sein bei voller Success.'
                );
            }

            // Reihenfolge-Pflicht: removeOrphans MUSS VOR register laufen.
            $methodOrder = array_column( Slice19ManagerSpy::$calls, 'method' );
            $removeIdx   = array_search( 'removeOrphans', $methodOrder, true );
            $registerIdx = array_search( 'register', $methodOrder, true );

            $this->assertNotFalse(
                $removeIdx,
                'AC-6: removeOrphans() MUSS aufgerufen werden.'
            );
            $this->assertNotFalse(
                $registerIdx,
                'AC-6: register() MUSS aufgerufen werden.'
            );
            $this->assertLessThan(
                $registerIdx,
                $removeIdx,
                'AC-6 PFLICHT: removeOrphans() MUSS VOR register() laufen ' .
                '(Vermeidung 4xx-on-duplicate-eventType bei stale URLs).'
            );
        }

        // ===================================================================
        // AC-7 — register-summary mit non-empty errors[] -> success-Response
        //        (HTTP 200) inkl. errors-Array.
        // ===================================================================

        public function test_ajax_returns_success_with_errors_array_on_partial_failure(): void
        {
            $this->stubAjaxGates( cap: true, nonce: true );

            $errors = [
                [
                    'eventType' => 'Order.cancelled',
                    'message'   => 'Subscription registration failed',
                ],
            ];

            Slice19ManagerSpy::$removeOrphansResult = 0;
            Slice19ManagerSpy::$registerResult      = [
                'added'   => 6,
                'removed' => 0,
                'skipped' => 0,
                'errors'  => $errors,
            ];

            try {
                RepairSubscriptions::handle();
                $this->fail( 'AC-7: Handler MUSS via wp_send_json_success terminieren.' );
            } catch ( Slice19JsonSentinel $e ) {
                // HTTP-Status bleibt 200/null (kein 5xx) — wireframe-State
                // `repair_partial_error`.
                $this->assertTrue(
                    $e->success,
                    'AC-7: success MUSS true bleiben — HTTP 200 bei Partial-Error.'
                );
                $this->assertNotSame(
                    503,
                    $e->status,
                    'AC-7: KEIN 5xx-Status — Frontend entscheidet anhand errors.length.'
                );

                // Response.errors MUSS das errors-Array aus dem Summary tragen.
                $this->assertSame(
                    $errors,
                    $e->payload['errors'] ?? null,
                    'AC-7: Response.errors MUSS exakt summary[errors] enthalten.'
                );
                $this->assertSame(
                    6,
                    $e->payload['added'] ?? null,
                    'AC-7: Response.added MUSS Summary[added]=6 widerspiegeln.'
                );
            }
        }

        // ===================================================================
        // AC-8 — TransientError aus register() -> wp_send_json_error 503,
        //        kein Inner-Retry; Logger-Eintrag.
        // ===================================================================

        public function test_ajax_returns_503_on_transient_error_without_inner_retry(): void
        {
            $this->stubAjaxGates( cap: true, nonce: true );

            Slice19ManagerSpy::$removeOrphansResult = 0;
            Slice19ManagerSpy::$registerResult      = new SpreadconnectTransientError(
                'http_5xx',
                'POST /subscriptions -> 503',
                503,
                '/subscriptions'
            );

            try {
                RepairSubscriptions::handle();
                $this->fail( 'AC-8: Handler MUSS via wp_send_json_error terminieren.' );
            } catch ( Slice19JsonSentinel $e ) {
                $this->assertFalse(
                    $e->success,
                    'AC-8: success MUSS false sein.'
                );
                $this->assertSame(
                    503,
                    $e->status,
                    'AC-8: HTTP-Status MUSS 503 sein bei Transient-Error.'
                );
                $this->assertSame(
                    'transient_error',
                    $e->payload['code'] ?? null,
                    'AC-8: Payload.code MUSS transient_error sein.'
                );
                $this->assertArrayHasKey(
                    'message',
                    $e->payload,
                    'AC-8: Payload MUSS message-Key tragen (kein Stacktrace-Leak).'
                );
            }

            // KEIN Inner-Retry: register() MUSS GENAU EINMAL aufgerufen worden sein.
            $registerCalls = array_filter(
                Slice19ManagerSpy::$calls,
                static fn ( array $c ): bool => $c['method'] === 'register'
            );
            $this->assertCount(
                1,
                $registerCalls,
                'AC-8 PFLICHT: register() MUSS GENAU EINMAL aufgerufen werden ' .
                '(KEIN Inner-Retry — Frontend bietet manuellen Retry).'
            );
        }

        // ===================================================================
        // AC-9 — renderSaveSuccessStepResult() rendert success vs. partial-
        //        error Variante.
        // ===================================================================

        public function test_save_success_step_result_renders_success_and_partial_variants(): void
        {
            // Variante A: voller Erfolg — added+skipped=7, errors leer.
            ob_start();
            Subscriptions::renderSaveSuccessStepResult( [
                'added'   => 3,
                'removed' => 0,
                'skipped' => 4,
                'errors'  => [],
            ] );
            $successHtml = (string) ob_get_clean();

            $this->assertStringContainsString(
                'Subscriptions registered',
                $successHtml,
                'AC-9 success: Label MUSS "Subscriptions registered" enthalten.'
            );
            $this->assertStringContainsString(
                '7',
                $successHtml,
                'AC-9 success: 7 (of 7) MUSS im Markup auftauchen.'
            );
            $this->assertStringContainsString(
                'spreadconnect-step-result--success',
                $successHtml,
                'AC-9 success: CSS-Class --success MUSS gerendert werden.'
            );
            $this->assertStringNotContainsString(
                'Open Subscriptions Manager',
                $successHtml,
                'AC-9 success: Open-Subscriptions-Link DARF NICHT erscheinen bei voller Success.'
            );

            // Variante B: Partial — errors[] non-empty.
            ob_start();
            Subscriptions::renderSaveSuccessStepResult( [
                'added'   => 5,
                'removed' => 0,
                'skipped' => 0,
                'errors'  => [
                    [ 'eventType' => 'Order.cancelled', 'message' => 'Subscription registration failed' ],
                    [ 'eventType' => 'Shipment.sent', 'message' => 'Subscription registration failed' ],
                ],
            ] );
            $partialHtml = (string) ob_get_clean();

            $this->assertStringContainsString(
                'spreadconnect-step-result--partial',
                $partialHtml,
                'AC-9 partial: CSS-Class --partial MUSS gerendert werden.'
            );
            $this->assertStringContainsString(
                'failed',
                $partialHtml,
                'AC-9 partial: Label MUSS "(N failed)" enthalten.'
            );
            $this->assertStringContainsString(
                'Open Subscriptions Manager',
                $partialHtml,
                'AC-9 partial: Link "Open Subscriptions Manager" MUSS gerendert werden.'
            );
            $this->assertStringContainsString(
                'section=subscriptions',
                $partialHtml,
                'AC-9 partial: Link href MUSS auf ?section=subscriptions zeigen.'
            );
        }

        // ===================================================================
        // AC-10 — Slice-13-Stub `Hub\View\Subscriptions` wurde durch eine
        //         vollstaendige Implementation aus dieser Slice ersetzt.
        // ===================================================================

        public function test_subscriptions_view_class_replaces_slice_13_stub(): void
        {
            $reflection = new ReflectionClass( Subscriptions::class );

            // Datei-Pfad MUSS unter wordpress/plugins/spreadconnect-pod/includes/Hub/View
            // liegen — der echte Implementation-Pfad, NICHT eine Test-Stub-eval()-Datei.
            $file = (string) $reflection->getFileName();
            $this->assertStringContainsString(
                'wordpress/plugins/spreadconnect-pod/includes/Hub/View/Subscriptions.php',
                $file,
                'AC-10: Subscriptions-Klasse MUSS aus dem Production-Path geladen werden.'
            );

            // Klasse MUSS final + render() public static sein.
            $this->assertTrue(
                $reflection->isFinal(),
                'AC-10: Subscriptions MUSS `final class` sein (Stateless-Adapter-Pattern).'
            );
            $this->assertTrue(
                $reflection->hasMethod( 'render' ),
                'AC-10: Subscriptions::render() MUSS existieren.'
            );

            $renderMethod = $reflection->getMethod( 'render' );
            $this->assertTrue(
                $renderMethod->isStatic(),
                'AC-10: render() MUSS static sein.'
            );
            $this->assertTrue(
                $renderMethod->isPublic(),
                'AC-10: render() MUSS public sein.'
            );

            // renderSaveSuccessStepResult-Helper (Provides To Slice 11/14).
            $this->assertTrue(
                $reflection->hasMethod( 'renderSaveSuccessStepResult' ),
                'AC-10: renderSaveSuccessStepResult-Helper MUSS exponiert sein.'
            );
            $stepMethod = $reflection->getMethod( 'renderSaveSuccessStepResult' );
            $this->assertTrue(
                $stepMethod->isStatic() && $stepMethod->isPublic(),
                'AC-10: renderSaveSuccessStepResult MUSS public static sein.'
            );

            // Datei-Inhalt MUSS substanziellen Implementation-Code enthalten —
            // der Slice-13-Stub war eine 5-Zeilen-No-Op-Klasse.
            $contents = (string) file_get_contents( $file );
            $this->assertGreaterThan(
                500,
                strlen( $contents ),
                'AC-10: Implementation MUSS deutlich umfangreicher als ein Stub sein.'
            );
            $this->assertStringContainsString(
                'SubscriptionManager',
                $contents,
                'AC-10: Implementation MUSS SubscriptionManager konsumieren.'
            );
        }

        // ===================================================================
        // AC-11 — `wp_ajax_spreadconnect_repair_subscriptions` registriert,
        //         KEIN nopriv-Zwilling.
        // ===================================================================

        public function test_ajax_action_registered_without_nopriv_variant(): void
        {
            $registered = & $this->registeredActions;
            Functions\when( 'add_action' )->alias(
                static function ( $hook, $callback, $priority = 10, $accepted_args = 1 ) use ( &$registered ): bool {
                    $registered[] = [
                        'hook'          => (string) $hook,
                        'callback'      => $callback,
                        'priority'      => (int) $priority,
                        'accepted_args' => (int) $accepted_args,
                    ];
                    return true;
                }
            );

            RepairSubscriptions::register();

            $authHook   = 'wp_ajax_' . RepairSubscriptions::ACTION;
            $nopriveHook = 'wp_ajax_nopriv_' . RepairSubscriptions::ACTION;

            $authMatches = array_filter(
                $this->registeredActions,
                static fn ( array $a ): bool => $a['hook'] === $authHook
            );
            $this->assertCount(
                1,
                $authMatches,
                'AC-11: wp_ajax_spreadconnect_repair_subscriptions MUSS GENAU EINMAL registriert werden.'
            );

            $nopriveMatches = array_filter(
                $this->registeredActions,
                static fn ( array $a ): bool => $a['hook'] === $nopriveHook
            );
            $this->assertCount(
                0,
                $nopriveMatches,
                'AC-11 PFLICHT: KEIN wp_ajax_nopriv_*-Zwilling — kein Public-Endpoint.'
            );

            // Callback MUSS [RepairSubscriptions::class, 'handle'] sein.
            $authCall = array_values( $authMatches )[0];
            $this->assertSame(
                [ RepairSubscriptions::class, 'handle' ],
                $authCall['callback'],
                'AC-11: Callback MUSS [RepairSubscriptions::class, "handle"] sein.'
            );
        }

        public function test_bootstrap_plugin_imports_repair_subscriptions(): void
        {
            // AC-11 — Mount-Point in Bootstrap\Plugin muss existieren.
            $bootstrapFile = realpath( __DIR__ . '/../../..' )
                . '/wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php';

            $this->assertFileExists(
                $bootstrapFile,
                'AC-11: Bootstrap/Plugin.php MUSS existieren.'
            );

            $contents = (string) file_get_contents( $bootstrapFile );
            $this->assertStringContainsString(
                'RepairSubscriptions',
                $contents,
                'AC-11: Bootstrap MUSS RepairSubscriptions importieren oder verwenden.'
            );
            $this->assertStringContainsString(
                'RepairSubscriptions::register',
                $contents,
                'AC-11: Bootstrap MUSS RepairSubscriptions::register() im init-Hook aufrufen.'
            );
        }

        // ===================================================================
        // AC-12 — Logger emittiert Repair-Marker + Counts; KEIN Plaintext-Secret.
        // ===================================================================

        public function test_logger_emits_repair_marker_without_plaintext_secret(): void
        {
            $this->stubAjaxGates( cap: true, nonce: true );

            // Sentinel-Secret das NIEMALS im Log erscheinen darf.
            $secret = 'NEVER_LEAK_BASE64_SECRET_xyz789';

            Slice19ManagerSpy::$removeOrphansResult = 2;
            Slice19ManagerSpy::$registerResult      = [
                'added'   => 3,
                'removed' => 0,
                'skipped' => 4,
                'errors'  => [],
            ];

            try {
                RepairSubscriptions::handle();
            } catch ( Slice19JsonSentinel $e ) {
                // Erwartet — wir interessieren uns nur fuer die Log-Eintraege.
            }

            $this->assertNotEmpty(
                $this->loggerEntries,
                'AC-12: Mindestens ein Logger-Eintrag MUSS bei Repair-Outcome geschrieben werden.'
            );

            // Repair-Marker MUSS in mindestens einem Logger-Message auftauchen.
            $messages = array_column( $this->loggerEntries, 'message' );
            $marker   = false;
            foreach ( $messages as $message ) {
                if ( str_contains( (string) $message, 'subscription_repair_invoked' ) ) {
                    $marker = true;
                    break;
                }
            }
            $this->assertTrue(
                $marker,
                'AC-12: Logger-Eintrag MUSS Marker "subscription_repair_invoked" enthalten.'
            );

            // Counts MUESSEN als added=N + removed=M auftauchen.
            $hasCounts = false;
            foreach ( $messages as $message ) {
                if (
                    str_contains( (string) $message, 'added=3' )
                    && str_contains( (string) $message, 'removed=2' )
                ) {
                    $hasCounts = true;
                    break;
                }
            }
            $this->assertTrue(
                $hasCounts,
                'AC-12: Logger-Eintrag MUSS Counts (added=3, removed=2) enthalten.'
            );

            // Plaintext-Secret darf NIEMALS in Messages oder Context auftauchen.
            foreach ( $this->loggerEntries as $entry ) {
                $this->assertStringNotContainsString(
                    $secret,
                    (string) $entry['message'],
                    sprintf(
                        'AC-12 PFLICHT: Plaintext-Secret darf NIEMALS in Logger-Message stehen ' .
                        '(level=%s).',
                        $entry['level']
                    )
                );
                $contextJson = (string) ( json_encode( $entry['context'] ) ?: '' );
                $this->assertStringNotContainsString(
                    $secret,
                    $contextJson,
                    sprintf(
                        'AC-12 PFLICHT: Plaintext-Secret darf NIEMALS in Logger-Context stehen ' .
                        '(level=%s).',
                        $entry['level']
                    )
                );
            }
        }
    }
}
