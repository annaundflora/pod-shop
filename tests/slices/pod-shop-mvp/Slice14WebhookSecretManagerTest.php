<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SpreadconnectPod\Hub\Ajax\RegenerateSecret;
use SpreadconnectPod\Hub\View\Settings;
use SpreadconnectPod\Subscription\WebhookSecretManager;

/**
 * Sentinel exception for `wp_send_json_*` termination — see Slice12 test.
 */
if ( ! class_exists( JsonResponseSentinel14::class, false ) ) {
    final class JsonResponseSentinel14 extends \RuntimeException
    {
        /** @var array<string,mixed> */
        public array $payload;
        public bool $success;
        public ?int $status;

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
 * Random-Bytes-Seam Driver: Patchwork-redefiniert
 * `WebhookSecretManager::generateRandomBytes()` ueber Brain\Monkey um
 * eine FIFO-Queue mit determministischen Bytes einzuspielen. Damit kann
 * der Test zwei aufeinanderfolgende Aufrufe (regenerate -> regenerate)
 * mit unterschiedlichen Bytes versorgen (AC-10). Der Manager ist `final`,
 * deshalb ist Patchwork-Replace das einzige Mittel.
 */
final class FakeRandomBytesQueue
{
    /** @var list<string> */
    public static array $bytesQueue = [];

    /** @var list<int> */
    public static array $lengthRequested = [];

    public static function reset(): void
    {
        self::$bytesQueue      = [];
        self::$lengthRequested = [];
    }

    public static function pushBytes( string $bytes ): void
    {
        self::$bytesQueue[] = $bytes;
    }

    public static function pop( int $len ): string
    {
        self::$lengthRequested[] = $len;

        if ( empty( self::$bytesQueue ) ) {
            // Deterministischer Default fuer Tests, die nur einen Aufruf
            // brauchen aber keine spezifischen Bytes vorbereiten.
            return str_repeat( "\x00", $len );
        }

        return array_shift( self::$bytesQueue );
    }
}

/**
 * Slice 14 — Webhook-Secret-Manager + One-Time-Reveal-Panel.
 *
 * Acceptance Tests gegen die Slice-Spec
 * `slice-14-webhook-secret-manager.md`.
 *
 * Mocking Strategy: `mock_external` (laut Slice-Spec):
 *   - Brain\Monkey fuer WP Options/AJAX/Actions/i18n.
 *   - In-memory Options-Map fuer Persistenz-Verifikation.
 *   - {@see FakeWebhookSecretManager} ueberschreibt den
 *     `protected static generateRandomBytes()`-Seam (Subclass-Trick), so
 *     dass die `random_bytes`-Source deterministisch wird, OHNE Patchwork
 *     auf `random_bytes` global anzuwenden.
 *   - `wp_send_json_*` werfen `JsonResponseSentinel14` damit der PHPUnit-
 *     Prozess nicht via `die()` terminiert.
 */
final class Slice14WebhookSecretManagerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var list<array{0:string, 1:array<int,mixed>}> */
    private array $actionsFired = [];

    /** @var list<array<string,mixed>> */
    private array $errorLogs = [];

    /** Temp-Datei in die ini_set('error_log', ...) umleitet. */
    private string $errorLogFile = '';

    /** @var string|false */
    private mixed $prevErrorLog = '';

    /** @var string|false */
    private mixed $prevLogErrors = '';

    /**
     * Test-Wallclock — ungefaehr time() zum Zeitpunkt von setUp(). Wird als
     * untere Schranke fuer Timestamp-Toleranz-Asserts genutzt; die Manager-
     * Production-Pfad nutzt den echten `time()` da Patchwork keine Internal-
     * Replacements ohne `redefinable-internals`-Config kann.
     */
    private int $now = 0;

    private static function repoRoot(): string
    {
        return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
    }

    private static function managerFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/includes/Subscription/WebhookSecretManager.php';
    }

    private static function ajaxFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/RegenerateSecret.php';
    }

    private static function settingsViewFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->options      = [];
        $this->transients   = [];
        $this->actionsFired = [];
        $this->errorLogs    = [];
        $this->now          = time();
        FakeRandomBytesQueue::reset();
        $_POST = [];

        // Patchwork-Replace fuer den `protected static generateRandomBytes()`-
        // Seam. Die Klasse ist `final` (sub-classing nicht moeglich), die Spec
        // erlaubt explizit Patchwork-Replace als Alternative.
        \Patchwork\redefine(
            [ WebhookSecretManager::class, 'generateRandomBytes' ],
            static function ( int $len ): string {
                return FakeRandomBytesQueue::pop( $len );
            }
        );

        // ---- i18n/escape identity stubs ----------------------------------.
        Functions\when( '__' )->returnArg( 1 );
        Functions\when( 'esc_html__' )->returnArg( 1 );
        Functions\when( 'esc_attr__' )->returnArg( 1 );
        Functions\when( 'esc_html' )->returnArg( 1 );
        Functions\when( 'esc_attr' )->returnArg( 1 );

        // ---- WP options (in-memory map) ----------------------------------.
        $opts = &$this->options;
        Functions\when( 'get_option' )->alias(
            static function ( $name, $default = false ) use ( &$opts ) {
                return array_key_exists( $name, $opts ) ? $opts[ $name ] : $default;
            }
        );
        Functions\when( 'update_option' )->alias(
            static function ( $name, $value ) use ( &$opts ): bool {
                $changed       = ( ! array_key_exists( $name, $opts ) ) || $opts[ $name ] !== $value;
                $opts[ $name ] = $value;
                return $changed;
            }
        );
        Functions\when( 'add_option' )->alias(
            static function ( $name, $value ) use ( &$opts ): bool {
                if ( array_key_exists( $name, $opts ) ) {
                    return false;
                }
                $opts[ $name ] = $value;
                return true;
            }
        );
        Functions\when( 'delete_option' )->alias(
            static function ( $name ) use ( &$opts ): bool {
                if ( ! array_key_exists( $name, $opts ) ) {
                    return false;
                }
                unset( $opts[ $name ] );
                return true;
            }
        );

        // ---- WP transients (in-memory map) -------------------------------.
        $trans = &$this->transients;
        Functions\when( 'set_transient' )->alias(
            static function ( $name, $value, $ttl = 0 ) use ( &$trans ): bool {
                $trans[ $name ] = $value;
                return true;
            }
        );
        Functions\when( 'get_transient' )->alias(
            static function ( $name ) use ( &$trans ) {
                return array_key_exists( $name, $trans ) ? $trans[ $name ] : false;
            }
        );
        Functions\when( 'delete_transient' )->alias(
            static function ( $name ) use ( &$trans ): bool {
                if ( ! array_key_exists( $name, $trans ) ) {
                    return false;
                }
                unset( $trans[ $name ] );
                return true;
            }
        );

        // ---- time() — NICHT gemockt: Patchwork erlaubt keine Redefinition
        // von built-in `time()` ohne `redefinable-internals`-Config; der
        // Manager nutzt `(int) time()`, der Test vergleicht gegen einen
        // engen Timestamp-Bereich (Test-Wallclock plus/minus 5s).

        // ---- do_action — capture-spy -------------------------------------.
        $captured = &$this->actionsFired;
        Functions\when( 'do_action' )->alias(
            static function ( ...$args ) use ( &$captured ): void {
                $hook         = array_shift( $args );
                $captured[]   = [ (string) $hook, $args ];
            }
        );

        // ---- wp_send_json_* — sentinel-throw -----------------------------.
        Functions\when( 'wp_send_json_success' )->alias(
            static function ( $data = null, $status_code = null ) {
                throw new JsonResponseSentinel14( true, (array) $data, $status_code );
            }
        );
        Functions\when( 'wp_send_json_error' )->alias(
            static function ( $data = null, $status_code = null ) {
                throw new JsonResponseSentinel14( false, (array) $data, $status_code );
            }
        );

        // ---- error_log capture via ini_set ('error_log' is a PHP internal,
        //      Patchwork erlaubt keine Redefinition ohne 'redefinable-internals'-
        //      Config). Wir leiten error_log() in eine Temp-Datei um und lesen
        //      sie zurueck via {@see self::readErrorLogMessages()}.
        $this->errorLogFile  = (string) tempnam( sys_get_temp_dir(), 'slice14_errorlog_' );
        $this->prevErrorLog  = ini_get( 'error_log' );
        $this->prevLogErrors = ini_get( 'log_errors' );
        ini_set( 'error_log', $this->errorLogFile );
        ini_set( 'log_errors', '1' );

        // ---- Hub-Settings: nonce + add_action defaults --------------------.
        Functions\when( 'wp_create_nonce' )->justReturn( 'NONCE_SECRET_42' );
        Functions\when( 'wp_print_inline_script_tag' )->alias(
            static function ( string $js, array $attrs = [] ): void {
                echo '<script>' . $js . '</script>';
            }
        );
        // submit_button + settings_fields/do_settings_sections — Settings.php
        // uses these in its broader render() but our Slice-14-tests target
        // only `renderWebhookSecuritySection()`. Stubbing them here keeps
        // any incidental call cheap.
        Functions\when( 'submit_button' )->alias( static function (): void {} );
        Functions\when( 'settings_fields' )->alias( static function (): void {} );
        Functions\when( 'do_settings_sections' )->alias( static function (): void {} );

        // wp_date for Settings::formatTimestamp.
        Functions\when( 'wp_date' )->alias(
            static function ( string $format, int $timestamp ): string {
                return gmdate( $format, $timestamp );
            }
        );
    }

    protected function tearDown(): void
    {
        // Restore error_log ini settings.
        if ( $this->prevErrorLog !== '' && $this->prevErrorLog !== false ) {
            ini_set( 'error_log', (string) $this->prevErrorLog );
        }
        if ( $this->prevLogErrors !== '' && $this->prevLogErrors !== false ) {
            ini_set( 'log_errors', (string) $this->prevLogErrors );
        }
        if ( '' !== $this->errorLogFile && is_file( $this->errorLogFile ) ) {
            @unlink( $this->errorLogFile );
        }

        FakeRandomBytesQueue::reset();
        $_POST = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Read the temp error_log file into a list of message strings.
     *
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
        // PHP error_log() prefixes each line with a timestamp like
        // "[04-May-2026 12:34:56 UTC] " — split by newline and keep all
        // non-empty lines.
        $lines = preg_split( '/\r?\n/', trim( $contents ) ) ?: [];
        return array_values( array_filter( $lines, static fn ( $l ): bool => '' !== $l ) );
    }

    // =====================================================================
    // AC-1: GIVEN Option leer, WHEN generate(), THEN
    //       random_bytes(32) -> base64 -> update_option +
    //       Reveal-Payload [secret, generated_at, is_initial=true].
    // =====================================================================

    public function test_generate_writes_option_and_returns_initial_reveal(): void
    {
        // Deterministische 32 Bytes -> bekannte base64-Repraesentation.
        $bytes = random_bytes_replacement_32();
        FakeRandomBytesQueue::pushBytes( $bytes );

        $payload = WebhookSecretManager::generate();

        $this->assertIsArray( $payload );
        $this->assertArrayHasKey( 'secret', $payload, 'AC-1: Reveal-Payload MUSS secret-Key haben.' );
        $this->assertArrayHasKey( 'generated_at', $payload, 'AC-1: Reveal-Payload MUSS generated_at haben.' );
        $this->assertArrayHasKey( 'is_initial', $payload, 'AC-1: Reveal-Payload MUSS is_initial haben.' );
        $this->assertTrue( $payload['is_initial'], 'AC-1: is_initial MUSS true sein bei generate().' );

        $this->assertSame(
            base64_encode( $bytes ),
            $payload['secret'],
            'AC-1: Secret MUSS base64( random_bytes(32) ) sein.'
        );
        $this->assertGreaterThanOrEqual(
            $this->now,
            $payload['generated_at'],
            'AC-1: generated_at MUSS aktueller unix-timestamp sein (>= test setup time).'
        );
        $this->assertLessThanOrEqual(
            $this->now + 5,
            $payload['generated_at'],
            'AC-1: generated_at darf nicht in der Zukunft liegen.'
        );

        // Persistenz: Option enthaelt das base64-Secret.
        $this->assertSame(
            $payload['secret'],
            $this->options[ WebhookSecretManager::OPTION_SECRET ] ?? null,
            'AC-1: Option `spreadconnect_webhook_secret` MUSS via update_option persistiert sein.'
        );
        $this->assertSame(
            $payload['generated_at'],
            $this->options[ WebhookSecretManager::OPTION_GENERATED_AT ] ?? null,
            'AC-1: Companion-Option generated_at MUSS via update_option persistiert sein.'
        );

        // Persistenz-Form: ASCII-base64 (kein hex, kein binary).
        $this->assertMatchesRegularExpression(
            '#^[A-Za-z0-9+/=]+$#',
            $payload['secret'],
            'AC-1: Persistenz-Form MUSS ASCII-base64 sein (kein hex, kein binary).'
        );
        $this->assertSame(
            trim( $payload['secret'] ),
            $payload['secret'],
            'AC-1: Persistenz-Form MUSS ohne Trailing-Whitespace sein.'
        );

        // random_bytes-Seam wurde mit 32 Bytes aufgerufen.
        $this->assertSame(
            [ 32 ],
            FakeRandomBytesQueue::$lengthRequested,
            'AC-1: generateRandomBytes() MUSS mit Laenge 32 aufgerufen werden.'
        );
    }

    // AC-1: Secret ist base64-encoded mit Laenge ~44 (genau 44 fuer 32 raw bytes).
    public function test_generate_produces_base64_44_chars(): void
    {
        FakeRandomBytesQueue::pushBytes( str_repeat( "\xAB", 32 ) );

        $payload = WebhookSecretManager::generate();

        $this->assertSame(
            44,
            strlen( $payload['secret'] ),
            'AC-1: base64(32 bytes) hat exakt 44 Zeichen (inkl. ggf. "=").'
        );
        // base64 alphabet enforcement.
        $this->assertMatchesRegularExpression(
            '#^[A-Za-z0-9+/]{43}=$#',
            $payload['secret'],
            'AC-1: base64(32 bytes) endet konventionell mit "=" (kein urlsafe-Encoding).'
        );
    }

    // =====================================================================
    // AC-2: GIVEN Option enthaelt $old, WHEN regenerate(),
    //       THEN $new !== $old, update_option ueberschrieben,
    //            is_initial = false.
    // =====================================================================

    public function test_regenerate_replaces_previous_secret(): void
    {
        $oldBytes = str_repeat( "\x11", 32 );
        $newBytes = str_repeat( "\x22", 32 );

        // Erste Generation -> $old.
        FakeRandomBytesQueue::pushBytes( $oldBytes );
        $first = WebhookSecretManager::generate();
        $oldSecret = $first['secret'];

        // Zweiter Aufruf -> $new (neue, andere Bytes).
        FakeRandomBytesQueue::pushBytes( $newBytes );
        $payload = WebhookSecretManager::regenerate();

        $this->assertSame( base64_encode( $newBytes ), $payload['secret'] );
        $this->assertNotSame(
            $oldSecret,
            $payload['secret'],
            'AC-2: Neues Secret MUSS sich vom alten unterscheiden (neue random_bytes).'
        );
        $this->assertFalse(
            $payload['is_initial'],
            'AC-2: regenerate() MUSS is_initial=false liefern.'
        );

        // Option enthaelt nur noch den NEUEN Wert; der alte ist nicht mehr lesbar.
        $persisted = $this->options[ WebhookSecretManager::OPTION_SECRET ] ?? null;
        $this->assertSame(
            $payload['secret'],
            $persisted,
            'AC-2: update_option MUSS den alten Wert ueberschrieben haben.'
        );
        $this->assertNotSame(
            $oldSecret,
            $persisted,
            'AC-2: Alter Wert MUSS nicht mehr aus der Option lesbar sein.'
        );
    }

    // =====================================================================
    // AC-3: regenerate() feuert do_action('spreadconnect/webhook_secret_rotated', $newSecret, $context).
    // =====================================================================

    public function test_regenerate_fires_rotation_action_with_new_secret(): void
    {
        $bytes = str_repeat( "\x33", 32 );
        FakeRandomBytesQueue::pushBytes( $bytes );

        $payload = WebhookSecretManager::regenerate();

        $rotated = array_filter(
            $this->actionsFired,
            static fn ( array $a ): bool => $a[0] === WebhookSecretManager::ACTION_ROTATED
        );
        $this->assertCount(
            1,
            $rotated,
            'AC-3: do_action(spreadconnect/webhook_secret_rotated, ...) MUSS GENAU EINMAL gefeuert werden.'
        );

        $event = array_values( $rotated )[0];
        $this->assertSame(
            $payload['secret'],
            $event[1][0] ?? null,
            'AC-3: Action MUSS das frisch generierte Secret als 1. Argument liefern.'
        );
        // Context-Array (zweites Argument) traegt is_initial=false bei regenerate().
        $context = $event[1][1] ?? [];
        $this->assertIsArray( $context );
        $this->assertArrayHasKey( 'is_initial', $context );
        $this->assertFalse(
            $context['is_initial'],
            'AC-3: Bei regenerate() MUSS Context is_initial=false enthalten.'
        );
    }

    // AC-3: generate() feuert dieselbe Action mit context is_initial=true.
    public function test_initial_generate_fires_rotation_action_with_is_initial_flag(): void
    {
        $bytes = str_repeat( "\x44", 32 );
        FakeRandomBytesQueue::pushBytes( $bytes );

        $payload = WebhookSecretManager::generate();

        $rotated = array_filter(
            $this->actionsFired,
            static fn ( array $a ): bool => $a[0] === WebhookSecretManager::ACTION_ROTATED
        );
        $this->assertCount(
            1,
            $rotated,
            'AC-3: generate() MUSS dieselbe Action wie regenerate() feuern (Single-Source-of-Truth).'
        );

        $event   = array_values( $rotated )[0];
        $context = $event[1][1] ?? [];
        $this->assertTrue(
            $context['is_initial'] ?? null,
            'AC-3: Bei generate() MUSS Context is_initial=true sein.'
        );
        $this->assertSame(
            $payload['secret'],
            $event[1][0] ?? null,
            'AC-3: Initial generate() reicht das frische Secret als 1. Action-Argument durch.'
        );
    }

    // AC-3: Action-Hook-Name ist EXAKT 'spreadconnect/webhook_secret_rotated'.
    public function test_action_hook_constant_matches_spec(): void
    {
        $this->assertSame(
            'spreadconnect/webhook_secret_rotated',
            WebhookSecretManager::ACTION_ROTATED,
            'AC-3: ACTION_ROTATED MUSS exakt "spreadconnect/webhook_secret_rotated" sein — '
            . 'Single-Source-of-Truth fuer Slice-18-Listener.'
        );
    }

    // =====================================================================
    // AC-4: peek() liefert persistierten Wert; KEINE Side-Effects.
    // =====================================================================

    public function test_peek_returns_persisted_secret_without_mutation(): void
    {
        // Pre-populate Option mit einem bekannten Wert.
        $this->options[ WebhookSecretManager::OPTION_SECRET ] = 'PERSISTED_BASE64_VALUE_xyz';

        // Snapshot der Option-Map vor peek().
        $before = $this->options;

        $value = WebhookSecretManager::peek();

        $this->assertSame(
            'PERSISTED_BASE64_VALUE_xyz',
            $value,
            'AC-4: peek() MUSS den persistierten Plaintext aus get_option() liefern.'
        );
        $this->assertSame(
            $before,
            $this->options,
            'AC-4: peek() darf KEINE Option-Mutation triggern (read-only).'
        );

        // Auch keine do_action-Calls durch peek().
        $this->assertEmpty(
            array_filter(
                $this->actionsFired,
                static fn ( array $a ): bool => $a[0] === WebhookSecretManager::ACTION_ROTATED
            ),
            'AC-4: peek() darf KEINEN Rotation-Action feuern.'
        );
    }

    // AC-4: peek() liefert leeren String wenn Option nie gesetzt war.
    public function test_peek_returns_empty_when_option_missing(): void
    {
        // Options-Map leer.
        $this->assertSame(
            '',
            WebhookSecretManager::peek(),
            'AC-4: peek() MUSS leeren String liefern wenn Option nie generiert wurde.'
        );
    }

    // =====================================================================
    // AC-5: AJAX ohne Capability ODER ohne Nonce -> 403; KEIN regenerate-Call.
    // =====================================================================

    public function test_regenerate_secret_ajax_rejects_without_capability(): void
    {
        Functions\when( 'current_user_can' )->alias(
            static fn ( string $cap ): bool => false
        );
        Functions\when( 'check_ajax_referer' )->justReturn( true );

        // Pre-existing Option-Wert wird beobachtet — bei einem Bug, der den
        // Cap-Gate ueberspringt, wuerde regenerate() den Wert ueberschreiben.
        $this->options[ WebhookSecretManager::OPTION_SECRET ] = 'PRE_EXISTING_SECRET';

        $thrown = null;
        try {
            RegenerateSecret::handleRegenerate();
        } catch ( JsonResponseSentinel14 $e ) {
            $thrown = $e;
        }

        $this->assertNotNull( $thrown, 'AC-5: Handler MUSS via wp_send_json_error terminieren.' );
        $this->assertFalse( $thrown->success, 'AC-5: MUSS wp_send_json_error sein, nicht _success.' );
        $this->assertSame( 403, $thrown->status, 'AC-5: HTTP 403 bei fehlender Capability.' );

        // Kein regenerate-Call -> Option unveraendert.
        $this->assertSame(
            'PRE_EXISTING_SECRET',
            $this->options[ WebhookSecretManager::OPTION_SECRET ],
            'AC-5: Bei Cap-Miss DARF regenerate() NICHT laufen — Option bleibt unveraendert.'
        );
        // Auch kein rotation-Action.
        $this->assertEmpty(
            array_filter(
                $this->actionsFired,
                static fn ( array $a ): bool => $a[0] === WebhookSecretManager::ACTION_ROTATED
            ),
            'AC-5: Cap-Miss MUSS rotation-Action verhindern.'
        );
    }

    public function test_regenerate_secret_ajax_rejects_invalid_nonce(): void
    {
        Functions\when( 'current_user_can' )->alias(
            static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
        );
        Functions\when( 'check_ajax_referer' )->justReturn( false );

        $this->options[ WebhookSecretManager::OPTION_SECRET ] = 'PRE_EXISTING_SECRET';

        $thrown = null;
        try {
            RegenerateSecret::handleRegenerate();
        } catch ( JsonResponseSentinel14 $e ) {
            $thrown = $e;
        }

        $this->assertNotNull( $thrown, 'AC-5: Handler MUSS via wp_send_json_error terminieren.' );
        $this->assertFalse( $thrown->success );
        $this->assertSame( 403, $thrown->status, 'AC-5: HTTP 403 bei invalider Nonce.' );

        $this->assertSame(
            'PRE_EXISTING_SECRET',
            $this->options[ WebhookSecretManager::OPTION_SECRET ],
            'AC-5: Bei Nonce-Miss DARF regenerate() NICHT laufen.'
        );
    }

    // AC-5: check_ajax_referer wird mit `false` als 3. Argument aufgerufen
    //       (sonst wuerde er selbst wp_die-en).
    public function test_regenerate_secret_ajax_calls_check_ajax_referer_with_false_die_flag(): void
    {
        Functions\when( 'current_user_can' )->justReturn( true );

        $captured = [];
        Functions\when( 'check_ajax_referer' )->alias(
            static function ( $action, $field = '_wpnonce', $die = true ) use ( &$captured ) {
                $captured[] = [ 'action' => $action, 'field' => $field, 'die' => $die ];
                return false;
            }
        );

        try {
            RegenerateSecret::handleRegenerate();
        } catch ( JsonResponseSentinel14 $e ) {
            // expected
        }

        $this->assertNotEmpty( $captured, 'AC-5: check_ajax_referer MUSS aufgerufen werden.' );
        $this->assertSame(
            'spreadconnect_secret_action',
            $captured[0]['action'],
            'AC-5/Constraint: Nonce-Action MUSS exakt "spreadconnect_secret_action" sein.'
        );
        $this->assertFalse(
            $captured[0]['die'],
            'AC-5: 3. Argument MUSS false sein damit check_ajax_referer NICHT selbst wp_die-t.'
        );
    }

    // =====================================================================
    // AC-6: AJAX mit Cap+Nonce -> regenerate() aufgerufen + Plaintext nur in Response.
    // =====================================================================

    public function test_regenerate_secret_ajax_returns_plaintext_on_success(): void
    {
        Functions\when( 'current_user_can' )->alias(
            static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
        );
        Functions\when( 'check_ajax_referer' )->justReturn( true );

        $thrown = null;
        try {
            RegenerateSecret::handleRegenerate();
        } catch ( JsonResponseSentinel14 $e ) {
            $thrown = $e;
        }

        $this->assertNotNull( $thrown, 'AC-6: Handler MUSS via wp_send_json_success terminieren.' );
        $this->assertTrue( $thrown->success, 'AC-6: MUSS wp_send_json_success sein.' );
        $this->assertArrayHasKey( 'secret', $thrown->payload, 'AC-6: Response MUSS secret-Key haben.' );
        $this->assertArrayHasKey( 'generated_at', $thrown->payload );
        $this->assertArrayHasKey( 'is_initial', $thrown->payload );

        $persistedSecret = $this->options[ WebhookSecretManager::OPTION_SECRET ] ?? null;
        $this->assertNotNull(
            $persistedSecret,
            'AC-6: regenerate() MUSS aufgerufen worden sein — Option ist gesetzt.'
        );
        $this->assertSame(
            $persistedSecret,
            $thrown->payload['secret'],
            'AC-6: Plaintext in Response MUSS dem persistierten Secret entsprechen.'
        );
        $this->assertFalse(
            $thrown->payload['is_initial'],
            'AC-6: AJAX-Pfad ist Regenerate, also is_initial=false.'
        );

        // Plaintext erscheint NICHT in einer "zweiten Lesebewegung" (z. B. Logger).
        // Das pruefen wir AC-9, hier nur die Response-Form.
    }

    // AC-6: handleAcknowledge — locks the panel (writes revealed_at + deletes transient).
    public function test_acknowledge_initial_reveal_locks_panel_permanently(): void
    {
        Functions\when( 'current_user_can' )->alias(
            static fn ( string $cap ): bool => $cap === 'manage_woocommerce'
        );
        Functions\when( 'check_ajax_referer' )->justReturn( true );

        // Pre-state: transient set + revealed_at == 0.
        $this->transients['spreadconnect_initial_secret_reveal'] = 'PLAINTEXT_FROM_PRIOR_REGEN';
        $this->options[ WebhookSecretManager::OPTION_REVEALED_AT ] = 0;

        $thrown = null;
        try {
            RegenerateSecret::handleAcknowledge();
        } catch ( JsonResponseSentinel14 $e ) {
            $thrown = $e;
        }

        $this->assertNotNull( $thrown );
        $this->assertTrue( $thrown->success, 'AC-8: Acknowledge MUSS via wp_send_json_success enden.' );

        $this->assertSame(
            $this->now,
            $this->options[ WebhookSecretManager::OPTION_REVEALED_AT ] ?? null,
            'AC-8: revealed_at MUSS auf time() gesetzt sein.'
        );
        $this->assertArrayNotHasKey(
            'spreadconnect_initial_secret_reveal',
            $this->transients,
            'AC-8: Transient MUSS via delete_transient() entfernt sein.'
        );
    }

    // =====================================================================
    // AC-7: Settings-Markup enthaelt Regenerate-Button + Maskierung +
    //       Last-Regenerated-Timestamp; Plaintext NIE im Server-Markup.
    // =====================================================================

    public function test_settings_section_renders_regenerate_button_and_mask(): void
    {
        // Companion-Option mit timestamp -> Last-Regenerated-Datum wird gerendert.
        $this->options[ WebhookSecretManager::OPTION_GENERATED_AT ] = $this->now;
        $this->options[ WebhookSecretManager::OPTION_REVEALED_AT ]  = $this->now; // panel hidden

        ob_start();
        try {
            Settings::renderWebhookSecuritySection();
        } catch ( \Throwable $e ) {
            ob_end_clean();
            $this->fail( 'AC-7: renderWebhookSecuritySection darf nicht werfen: ' . $e->getMessage() );
        }
        $html = (string) ob_get_clean();

        // (a) Regenerate-Button
        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*\bid=["\']spreadconnect-regenerate-secret["\']/i',
            $html,
            'AC-7 (a): Section ③ MUSS einen <button id="spreadconnect-regenerate-secret"> rendern.'
        );

        // (b) Maskierung (statisch, mehrere Bullets U+2022). Wir suchen die
        //     CSS-Klasse "spreadconnect-secret-mask" UND verifizieren, dass
        //     mindestens 10 U+2022-Bullets im Markup vorhanden sind (rohe
        //     UTF-8-Sequenz E2 80 A2 wiederholt sich >= 10 Mal).
        $this->assertStringContainsString(
            'spreadconnect-secret-mask',
            $html,
            'AC-7 (b): Markup MUSS einen Bullet-Mask-Container mit Klasse "spreadconnect-secret-mask" haben.'
        );
        $bulletByteRun = str_repeat( "\xE2\x80\xA2", 10 );
        $this->assertStringContainsString(
            $bulletByteRun,
            $html,
            'AC-7 (b): Markup MUSS mindestens 10 zusammenhaengende U+2022-Bullets enthalten.'
        );

        // (c) Last-Regenerated-Timestamp aus Option als formatiertes Datum.
        $this->assertMatchesRegularExpression(
            '/spreadconnect-secret-generated-at/i',
            $html,
            'AC-7 (c): Markup MUSS einen Last-Regenerated-Container haben.'
        );

        // (d) Inline-Hint
        $this->assertMatchesRegularExpression(
            '/<p class=["\']description["\']/i',
            $html,
            'AC-7 (d): Markup MUSS einen description-Hint enthalten.'
        );

        // Nonce ist im data-nonce Attribut eingebettet.
        $this->assertStringContainsString(
            'NONCE_SECRET_42',
            $html,
            'AC-7: Nonce-Token MUSS im Markup eingebettet sein (data-nonce).'
        );
    }

    public function test_settings_markup_never_contains_plaintext_secret(): void
    {
        // Persistierter Plaintext in Option — DARF NICHT ins Markup leaken.
        $persistedPlaintext = 'PLAINTEXT_BASE64_LEAK_PROBE_ABCDEFGHIJKLMNOP';
        $this->options[ WebhookSecretManager::OPTION_SECRET ]      = $persistedPlaintext;
        $this->options[ WebhookSecretManager::OPTION_GENERATED_AT ] = $this->now;
        $this->options[ WebhookSecretManager::OPTION_REVEALED_AT ]  = $this->now; // panel hidden
        // Auch keine offenen Transients — sicheres Default fuer "kein initial reveal".

        ob_start();
        Settings::renderWebhookSecuritySection();
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString(
            $persistedPlaintext,
            $html,
            'AC-7: Plaintext aus Option DARF NIE ins Server-Markup geschrieben werden.'
        );
    }

    // =====================================================================
    // AC-8: initial_secret_reveal_panel sichtbar wenn revealed_at leer +
    //       Transient frischen Plaintext enthaelt.
    // =====================================================================

    public function test_initial_reveal_panel_visible_when_revealed_at_empty(): void
    {
        // GIVEN: revealed_at == 0 UND Transient enthaelt frisch generierten Plaintext.
        $freshPlaintext = 'FRESH_PLAINTEXT_SECRET_FOR_INITIAL_REVEAL_ABC123==';
        $this->options[ WebhookSecretManager::OPTION_REVEALED_AT ]  = 0;
        $this->options[ WebhookSecretManager::OPTION_GENERATED_AT ] = $this->now;
        $this->transients['spreadconnect_initial_secret_reveal']    = $freshPlaintext;

        ob_start();
        Settings::renderWebhookSecuritySection();
        $html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression(
            '/spreadconnect-reveal-panel/i',
            $html,
            'AC-8: Bei revealed_at==0 + Transient gesetzt MUSS das initial_secret_reveal_panel rendern.'
        );
        $this->assertStringContainsString(
            $freshPlaintext,
            $html,
            'AC-8: Das Panel MUSS den frisch generierten Plaintext aus dem Transient enthalten.'
        );

        // [Done]-Button (acknowledge) ist im Panel.
        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*\bid=["\']spreadconnect-acknowledge-reveal["\']/i',
            $html,
            'AC-8: Panel MUSS [Done]-Button mit id="spreadconnect-acknowledge-reveal" enthalten.'
        );
    }

    public function test_initial_reveal_panel_hidden_after_acknowledgement(): void
    {
        // Subsequent Page-Load: revealed_at gesetzt -> Panel darf NICHT rendern.
        $loadedPlaintext = 'ANY_PLAINTEXT_THAT_WOULD_LEAK_IF_PANEL_RENDERED';
        $this->options[ WebhookSecretManager::OPTION_REVEALED_AT ]  = $this->now;
        $this->options[ WebhookSecretManager::OPTION_GENERATED_AT ] = $this->now;
        // Selbst wenn der Transient noch existieren WUERDE: Panel ist UI-locked.
        $this->transients['spreadconnect_initial_secret_reveal'] = $loadedPlaintext;

        ob_start();
        Settings::renderWebhookSecuritySection();
        $html = (string) ob_get_clean();

        $this->assertDoesNotMatchRegularExpression(
            '/spreadconnect-reveal-panel--initial/i',
            $html,
            'AC-8: Bei revealed_at != 0 DARF das initial-Panel NICHT mehr rendern.'
        );
        $this->assertStringNotContainsString(
            $loadedPlaintext,
            $html,
            'AC-8: Plaintext DARF NIE leaken nachdem revealed_at gesetzt ist.'
        );
    }

    // =====================================================================
    // AC-9: Logger enthaelt KEINEN Plaintext-Secret. Erlaubte Inhalte:
    //       event-marker, length-hint, is_initial-flag.
    // =====================================================================

    public function test_logger_does_not_emit_plaintext_secret(): void
    {
        $bytes = str_repeat( "\x55", 32 );
        FakeRandomBytesQueue::pushBytes( $bytes );

        $payload   = WebhookSecretManager::generate();
        $plaintext = $payload['secret'];

        $messages = $this->readErrorLogMessages();
        $this->assertNotEmpty(
            $messages,
            'AC-9: Manager MUSS einen Logger-Aufruf machen (event marker).'
        );

        foreach ( $messages as $msg ) {
            $this->assertStringNotContainsString(
                $plaintext,
                $msg,
                'AC-9: Plaintext-Secret DARF NICHT im Logger-Message auftauchen. ' .
                'Erlaubt sind nur event-marker (secret_generated/secret_rotated), len, is_initial.'
            );
        }

        // Mindestens ein Eintrag enthaelt den event-marker.
        $hasMarker = false;
        $hasLen    = false;
        foreach ( $messages as $msg ) {
            if ( str_contains( $msg, 'secret_generated' ) || str_contains( $msg, 'secret_rotated' ) ) {
                $hasMarker = true;
            }
            if ( preg_match( '/\blen=\d+\b/', $msg ) ) {
                $hasLen = true;
            }
        }
        $this->assertTrue( $hasMarker, 'AC-9: Log-Eintrag MUSS event-marker enthalten.' );
        $this->assertTrue( $hasLen, 'AC-9: Log-Eintrag MUSS length-hint (len=N) enthalten.' );
    }

    // AC-9: Static-source assertion — der Source darf den base64-Wert nirgends
    //       direkt mit error_log konkatinieren. Wir pruefen, dass das Event
    //       und der Plaintext nie im selben Aufruf landen.
    public function test_manager_source_never_concatenates_secret_with_log(): void
    {
        $source = (string) file_get_contents( self::managerFile() );

        // error_log darf existieren (event-marker), aber NICHT mit `$secret` als Teil
        // einer einzigen Zeile — die Production-Stelle nutzt nur strlen($secret) +
        // event-marker, nie $secret selbst.
        $this->assertMatchesRegularExpression(
            '/error_log\s*\(/',
            $source,
            'AC-9: Manager MUSS error_log fuer event-marker nutzen.'
        );
        // Der Plaintext-String wird nur via `strlen()` referenziert in logRotationEvent.
        $logRotationFn = '';
        if ( preg_match( '/private static function logRotationEvent[\s\S]*?\}\s*$/m', $source, $m ) ) {
            $logRotationFn = $m[0];
        }
        $this->assertNotSame( '', $logRotationFn, 'AC-9: logRotationEvent-Funktion MUSS existieren.' );
        $this->assertStringNotContainsString(
            '$secret',
            $logRotationFn,
            'AC-9: logRotationEvent DARF den Plaintext-$secret nicht referenzieren — nur length/marker.'
        );
    }

    // =====================================================================
    // AC-10: Verschiedene random_bytes-Inputs ergeben verschiedene Outputs.
    //        Konsistenz-Check: gleiche Bytes -> gleicher base64-Output.
    // =====================================================================

    public function test_regenerate_is_not_cached_between_calls(): void
    {
        $bytesA = str_repeat( "\xAA", 32 );
        $bytesB = str_repeat( "\xBB", 32 );

        // Zwei verschiedene Mock-Inputs hintereinander.
        FakeRandomBytesQueue::pushBytes( $bytesA );
        $first = WebhookSecretManager::regenerate();

        FakeRandomBytesQueue::pushBytes( $bytesB );
        $second = WebhookSecretManager::regenerate();

        $this->assertSame( base64_encode( $bytesA ), $first['secret'] );
        $this->assertSame( base64_encode( $bytesB ), $second['secret'] );
        $this->assertNotSame(
            $first['secret'],
            $second['secret'],
            'AC-10: Verschiedene Mock-Bytes MUESSEN verschiedene Outputs erzeugen — kein Caching.'
        );

        // Konsistenz-Check: gleiche Bytes -> gleicher base64-Output (deterministisch).
        FakeRandomBytesQueue::pushBytes( $bytesA );
        $third = WebhookSecretManager::regenerate();
        $this->assertSame(
            base64_encode( $bytesA ),
            $third['secret'],
            'AC-10: base64-Encoding ist deterministisch — gleiche Bytes -> gleicher Output.'
        );
    }

    // =====================================================================
    // Zusatz-Sanity: Persistenz-Atomicity — Action feuert NACH update_option,
    //                und KEINE rotation-Action ohne vorherige update_option.
    // =====================================================================

    public function test_persistence_runs_before_action_dispatch(): void
    {
        // Wir capturen die Reihenfolge: update_option vs do_action.
        $sequence = [];

        $opts = &$this->options;
        Functions\when( 'update_option' )->alias(
            static function ( $name, $value ) use ( &$opts, &$sequence ): bool {
                $opts[ $name ] = $value;
                if ( $name === WebhookSecretManager::OPTION_SECRET ) {
                    $sequence[] = 'update_secret';
                } elseif ( $name === WebhookSecretManager::OPTION_GENERATED_AT ) {
                    $sequence[] = 'update_generated_at';
                }
                return true;
            }
        );
        Functions\when( 'do_action' )->alias(
            static function ( ...$args ) use ( &$sequence ): void {
                if ( ( $args[0] ?? null ) === WebhookSecretManager::ACTION_ROTATED ) {
                    $sequence[] = 'do_action_rotated';
                }
            }
        );

        FakeRandomBytesQueue::pushBytes( str_repeat( "\xCC", 32 ) );
        WebhookSecretManager::regenerate();

        $idxSecret = array_search( 'update_secret', $sequence, true );
        $idxAction = array_search( 'do_action_rotated', $sequence, true );
        $this->assertNotFalse( $idxSecret, 'Constraint: update_option(SECRET) MUSS aufgerufen werden.' );
        $this->assertNotFalse( $idxAction, 'Constraint: rotation-Action MUSS gefeuert werden.' );
        $this->assertLessThan(
            $idxAction,
            $idxSecret,
            'Constraint: update_option(SECRET) MUSS VOR do_action(rotated) laufen.'
        );
    }
}

/**
 * Helper: deterministische 32-Byte-Sequenz zum Testen.
 */
function random_bytes_replacement_32(): string
{
    $out = '';
    for ( $i = 0; $i < 32; $i++ ) {
        $out .= chr( $i );
    }
    return $out;
}
