<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Test Bootstrap (file-scope, runs once at first include)
// ---------------------------------------------------------------------------
//
// `AttributeProvisioner::ensure()` calls `is_wp_error()` on the
// `wc_create_attribute()` return value to detect creation failures. We do
// NOT load WordPress in unit tests (Mocking Strategy `mock_external` per
// slice-20 spec) — the `WP_Error` class and `is_wp_error()` are stubbed
// here in the global namespace, mirroring the pattern from
// Slice07HttpClientBaseTest.
//
// `wc_create_attribute()`, `wc_get_attribute_taxonomies()` and
// `register_activation_hook()` are mocked per test via Brain\Monkey's
// `Functions\when()` / `Functions\expect()`. `function_exists()` cannot
// be patched at runtime (PHP-internal); the AC-6 test exercises it in a
// separate process where `wc_create_attribute` is genuinely undefined.
// ---------------------------------------------------------------------------

namespace {

    if ( ! class_exists( 'WP_Error', false ) ) {
        /**
         * Minimal WP_Error stub. Mirrors the public surface used by
         * AttributeProvisioner (`get_error_message()` + `instanceof` check).
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
}

namespace SpreadconnectPod\Tests {

    use Brain\Monkey;
    use Brain\Monkey\Functions;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionNamedType;
    use WP_Error;

    /**
     * Slice 20 — Attribute-Provisioner (`pa_groesse`/`pa_farbe`).
     *
     * Acceptance Tests gegen die Slice-Spec
     * `slice-20-attribute-provisioner.md`.
     *
     * Mocking Strategy: `mock_external` (laut Slice-Spec):
     *   - Brain\Monkey aliases `wc_create_attribute`,
     *     `wc_get_attribute_taxonomies`, `register_activation_hook`,
     *     `is_wp_error` mit fixen Returns / Spy-Closures.
     *   - Globaler `WP_Error`-Stub (siehe Bootstrap-Block oben).
     *   - AC-6 nutzt `#[RunInSeparateProcess]`, damit `function_exists(
     *     'wc_create_attribute' )` zuverlaessig `false` liefert (Brain\Monkey
     *     definiert sie nur, wenn ein `Functions\when()`-Alias den Aufruf
     *     redefiniert — im Separate-Process ohne Alias bleibt sie undefined).
     *
     * Strategie:
     *   - AC-1: ensure() bei leerer Taxonomien-Liste -> 2x wc_create_attribute
     *           mit exakten Args + Result-Array `['created'=>[..],'skipped'=>[]]`.
     *   - AC-2: ensure() bei beiden vorhandenen Taxonomien -> 0x Calls,
     *           Result-Array `['created'=>[],'skipped'=>['pa_groesse','pa_farbe']]`.
     *   - AC-3: Mixed (pa_farbe vorhanden, pa_groesse fehlt) -> 1x Call fuer
     *           pa_groesse + Result-Array.
     *   - AC-4: WP_Error -> AttributeProvisionerException mit get_error_message().
     *           Nach Throw wird kein weiterer Slug versucht (Fail-Fast).
     *   - AC-5: Plugin::init() registriert register_activation_hook fuer
     *           [AttributeProvisioner::class, 'ensure'] genau einmal pro
     *           init()-Lauf. Idempotenz aus Slice 02 AC-5 bleibt intakt.
     *   - AC-6: function_exists('wc_create_attribute') == false ->
     *           AttributeProvisionerException 'WooCommerce not loaded'.
     *   - AC-7: ReflectionClass: ensure() ist `public static (): array`,
     *           hat 0 Parameter. TAXONOMIES ist private const mit Slug-Liste.
     */
    final class Slice20AttributeProvisionerTest extends TestCase
    {
        /**
         * Repo-Root: drei Verzeichnisse oberhalb von `tests/slices/pod-shop-mvp/`.
         */
        private static function repoRoot(): string
        {
            return realpath( __DIR__ . '/../../..' ) ?: dirname( __DIR__, 3 );
        }

        /**
         * Absoluter Pfad zur Plugin-Hauptdatei.
         */
        private static function pluginMainFile(): string
        {
            return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';
        }

        /**
         * Absoluter Pfad zur AttributeProvisioner.php (zur Source-Validation
         * von AC-7 und Sanity-Check der Datei-Existenz).
         */
        private static function provisionerClassFile(): string
        {
            return self::repoRoot()
                . '/wordpress/plugins/spreadconnect-pod/includes/Catalog/AttributeProvisioner.php';
        }

        /**
         * Reset Plugin-internen State (Idempotenz-Guard) zwischen Tests, damit
         * Plugin::init() in jedem Test seinen ersten "echten" Run sehen kann.
         */
        private static function resetPluginState(): void
        {
            $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
            if ( ! class_exists( $fqcn ) ) {
                return;
            }

            $reflection = new ReflectionClass( $fqcn );
            if ( $reflection->hasProperty( 'initialized' ) ) {
                $prop = $reflection->getProperty( 'initialized' );
                $prop->setValue( null, false );
            }
            if ( $reflection->hasProperty( 'pluginFile' ) ) {
                $prop = $reflection->getProperty( 'pluginFile' );
                $prop->setValue( null, '' );
            }
        }

        /**
         * Erzeugt ein "WC-Taxonomy"-Objekt analog dem Return von
         * `wc_get_attribute_taxonomies()` — Slug ohne `pa_`-Prefix in
         * `attribute_name`, weitere Felder existieren in WC, werden aber
         * vom Provisioner nicht gelesen.
         */
        private static function makeTaxonomyObject( string $attributeName ): object
        {
            $obj                  = new \stdClass();
            $obj->attribute_id    = 1;
            $obj->attribute_name  = $attributeName;
            $obj->attribute_label = ucfirst( $attributeName );
            $obj->attribute_type  = 'select';
            $obj->attribute_orderby = 'menu_order';
            $obj->attribute_public  = 0;
            return $obj;
        }

        protected function setUp(): void
        {
            parent::setUp();
            Monkey\setUp();
            self::resetPluginState();

            // Default `is_wp_error` impl — recognises our stub class. Tests
            // that need a different impl can re-alias it.
            Functions\when( 'is_wp_error' )->alias( static function ( $thing ): bool {
                return $thing instanceof WP_Error;
            } );
        }

        protected function tearDown(): void
        {
            Monkey\tearDown();
            self::resetPluginState();
            parent::tearDown();
        }

        // ===================================================================
        // AC-1: GIVEN eine WooCommerce-Installation, in der weder pa_groesse
        //             noch pa_farbe als Attribut-Taxonomie registriert sind
        //       WHEN AttributeProvisioner::ensure() aufgerufen wird
        //       THEN ruft sie wc_create_attribute() genau zweimal mit den
        //            exakten Args; Rueckgabe ist
        //            ['created' => ['pa_groesse','pa_farbe'], 'skipped' => []].
        // ===================================================================

        /**
         * AC-1: ensure() ruft wc_create_attribute() 2x mit exakten Args
         * fuer pa_groesse und pa_farbe, wenn beide Taxonomien fehlen.
         */
        public function test_ensure_creates_both_taxonomies_when_missing(): void
        {
            $fqcn = 'SpreadconnectPod\\Catalog\\AttributeProvisioner';
            $this->assertTrue( class_exists( $fqcn ), 'AC-1: AttributeProvisioner muss autoloadbar sein.' );

            // Existing taxonomies: leer (beide fehlen).
            Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( [] );

            // Spy: alle wc_create_attribute-Aufrufe sammeln.
            $createCalls = [];
            Functions\when( 'wc_create_attribute' )->alias( static function ( $args ) use ( &$createCalls ) {
                $createCalls[] = $args;
                return 1; // valider Attribute-ID-Return (kein WP_Error).
            } );

            $result = $fqcn::ensure();

            // AC-1: GENAU zwei Aufrufe.
            $this->assertCount(
                2,
                $createCalls,
                'AC-1: wc_create_attribute MUSS genau zweimal aufgerufen werden — '
                . 'einmal pro fehlender Taxonomie. Aufrufe: ' . count( $createCalls )
            );

            // AC-1: Args fuer pa_groesse (1. Aufruf, weil Iteration-Order
            // signifikant ist — Constant-Reihenfolge `pa_groesse` zuerst).
            $this->assertSame(
                [
                    'name'         => 'Groesse',
                    'slug'         => 'groesse',
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ],
                $createCalls[0],
                'AC-1: 1. Aufruf MUSS exakt die pa_groesse-Args haben '
                . '(name=Groesse, slug=groesse, type=select, order_by=menu_order, has_archives=false).'
            );

            // AC-1: Args fuer pa_farbe (2. Aufruf).
            $this->assertSame(
                [
                    'name'         => 'Farbe',
                    'slug'         => 'farbe',
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ],
                $createCalls[1],
                'AC-1: 2. Aufruf MUSS exakt die pa_farbe-Args haben '
                . '(name=Farbe, slug=farbe, type=select, order_by=menu_order, has_archives=false).'
            );

            // AC-1: Result-Array.
            $this->assertSame(
                [
                    'created' => [ 'pa_groesse', 'pa_farbe' ],
                    'skipped' => [],
                ],
                $result,
                'AC-1: ensure() MUSS [\'created\'=>[\'pa_groesse\',\'pa_farbe\'],\'skipped\'=>[]] zurueckgeben.'
            );
        }

        /**
         * AC-1: created-Liste enthaelt beide Slugs in kanonischer Reihenfolge
         * [pa_groesse, pa_farbe] — die Reihenfolge der TAXONOMIES-Constant
         * darf NICHT alphabetisch sortiert werden.
         */
        public function test_ensure_returns_created_list_in_canonical_order(): void
        {
            $fqcn = 'SpreadconnectPod\\Catalog\\AttributeProvisioner';
            $this->assertTrue( class_exists( $fqcn ), 'AC-1: AttributeProvisioner muss autoloadbar sein.' );

            Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( [] );
            Functions\when( 'wc_create_attribute' )->justReturn( 1 );

            $result = $fqcn::ensure();

            $this->assertSame(
                [ 'pa_groesse', 'pa_farbe' ],
                $result['created'],
                'AC-1: created-Liste MUSS exakt [pa_groesse, pa_farbe] sein '
                . '(Iteration-Order der TAXONOMIES-Constant, NICHT alphabetisch).'
            );

            // skipped ist leer.
            $this->assertSame(
                [],
                $result['skipped'],
                'AC-1: skipped MUSS leer sein, wenn keine Taxonomy vorhanden war.'
            );
        }

        // ===================================================================
        // AC-2: GIVEN eine WooCommerce-Installation, in der beide Taxonomies
        //             bereits existieren (z. B. nach erstem Activate)
        //       WHEN AttributeProvisioner::ensure() ein zweites Mal aufgerufen wird
        //       THEN wird wc_create_attribute() NICHT aufgerufen.
        //            Die Methode liefert
        //            ['created' => [], 'skipped' => ['pa_groesse','pa_farbe']].
        // ===================================================================

        /**
         * AC-2: ensure() ist No-Op, wenn beide Taxonomien bereits existieren.
         */
        public function test_ensure_is_noop_when_both_taxonomies_exist(): void
        {
            $fqcn = 'SpreadconnectPod\\Catalog\\AttributeProvisioner';
            $this->assertTrue( class_exists( $fqcn ), 'AC-2: AttributeProvisioner muss autoloadbar sein.' );

            // Beide Taxonomien existieren (Slug ohne pa_-Prefix in attribute_name).
            Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( [
                self::makeTaxonomyObject( 'groesse' ),
                self::makeTaxonomyObject( 'farbe' ),
            ] );

            // Spy: wc_create_attribute darf NICHT aufgerufen werden.
            $createCalls = [];
            Functions\when( 'wc_create_attribute' )->alias( static function ( $args ) use ( &$createCalls ) {
                $createCalls[] = $args;
                return 1;
            } );

            $result = $fqcn::ensure();

            // AC-2: 0x wc_create_attribute.
            $this->assertCount(
                0,
                $createCalls,
                'AC-2: wc_create_attribute darf NICHT aufgerufen werden, wenn beide '
                . 'Taxonomien bereits existieren. Tatsaechliche Aufrufe: '
                . count( $createCalls )
            );

            // AC-2: Result-Array.
            $this->assertSame(
                [
                    'created' => [],
                    'skipped' => [ 'pa_groesse', 'pa_farbe' ],
                ],
                $result,
                'AC-2: ensure() MUSS [\'created\'=>[],\'skipped\'=>[\'pa_groesse\',\'pa_farbe\']] zurueckgeben.'
            );
        }

        /**
         * AC-2: skipped-Liste enthaelt beide Slugs in kanonischer Reihenfolge,
         * created-Liste ist leer.
         */
        public function test_ensure_returns_skipped_list_when_all_present(): void
        {
            $fqcn = 'SpreadconnectPod\\Catalog\\AttributeProvisioner';

            Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( [
                self::makeTaxonomyObject( 'groesse' ),
                self::makeTaxonomyObject( 'farbe' ),
            ] );
            Functions\when( 'wc_create_attribute' )->justReturn( 1 );

            $result = $fqcn::ensure();

            $this->assertSame(
                [],
                $result['created'],
                'AC-2: created MUSS leer sein, wenn beide Taxonomien existieren.'
            );

            $this->assertSame(
                [ 'pa_groesse', 'pa_farbe' ],
                $result['skipped'],
                'AC-2: skipped MUSS exakt [pa_groesse, pa_farbe] in kanonischer Reihenfolge sein.'
            );
        }

        // ===================================================================
        // AC-3: GIVEN eine Mischsituation: pa_farbe existiert, pa_groesse fehlt
        //       WHEN ensure() aufgerufen wird
        //       THEN wird wc_create_attribute() genau einmal aufgerufen — fuer
        //            pa_groesse — und das Ergebnis ist
        //            ['created'=>['pa_groesse'], 'skipped'=>['pa_farbe']].
        // ===================================================================

        /**
         * AC-3: ensure() legt nur die fehlende Taxonomy an (pa_groesse),
         * skip'd die existierende (pa_farbe).
         */
        public function test_ensure_creates_only_missing_taxonomy(): void
        {
            $fqcn = 'SpreadconnectPod\\Catalog\\AttributeProvisioner';
            $this->assertTrue( class_exists( $fqcn ), 'AC-3: AttributeProvisioner muss autoloadbar sein.' );

            // Nur pa_farbe existiert.
            Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( [
                self::makeTaxonomyObject( 'farbe' ),
            ] );

            // Spy: wc_create_attribute genau einmal — fuer pa_groesse.
            $createCalls = [];
            Functions\when( 'wc_create_attribute' )->alias( static function ( $args ) use ( &$createCalls ) {
                $createCalls[] = $args;
                return 1;
            } );

            $result = $fqcn::ensure();

            // AC-3: GENAU EIN Aufruf.
            $this->assertCount(
                1,
                $createCalls,
                'AC-3: wc_create_attribute MUSS genau einmal aufgerufen werden '
                . '(nur fuer die fehlende Taxonomy pa_groesse). Aufrufe: '
                . count( $createCalls )
            );

            // AC-3: Args fuer pa_groesse.
            $this->assertSame(
                [
                    'name'         => 'Groesse',
                    'slug'         => 'groesse',
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ],
                $createCalls[0],
                'AC-3: Der einzige wc_create_attribute-Aufruf MUSS die pa_groesse-Args haben.'
            );

            // AC-3: Result-Array.
            $this->assertSame(
                [
                    'created' => [ 'pa_groesse' ],
                    'skipped' => [ 'pa_farbe' ],
                ],
                $result,
                'AC-3: ensure() MUSS [\'created\'=>[\'pa_groesse\'],\'skipped\'=>[\'pa_farbe\']] zurueckgeben.'
            );
        }

        // ===================================================================
        // AC-4: GIVEN wc_create_attribute() liefert beim Anlegen von pa_groesse
        //             ein WP_Error (z. B. weil der Slug "groesse" reserviert ist)
        //       WHEN ensure() aufgerufen wird
        //       THEN wirft die Methode AttributeProvisionerException (extends
        //            \RuntimeException) mit der WP_Error-Message als
        //            Exception-Message. Die noch nicht versuchte Taxonomy
        //            (pa_farbe) wird NICHT mehr angelegt (Fail-Fast).
        // ===================================================================

        /**
         * AC-4: WP_Error von wc_create_attribute -> AttributeProvisionerException
         * mit der WP_Error-Message als Exception-Message.
         */
        public function test_ensure_throws_on_wp_error(): void
        {
            $fqcn          = 'SpreadconnectPod\\Catalog\\AttributeProvisioner';
            $exceptionFqcn = 'SpreadconnectPod\\Catalog\\AttributeProvisionerException';
            $this->assertTrue( class_exists( $fqcn ), 'AC-4: AttributeProvisioner muss autoloadbar sein.' );
            $this->assertTrue(
                class_exists( $exceptionFqcn ),
                'AC-4: AttributeProvisionerException muss als Class verfuegbar sein.'
            );

            Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( [] );

            // 1. Aufruf liefert WP_Error.
            $errorMsg = 'Slug "groesse" is reserved.';
            $error    = new WP_Error( 'reserved_slug', $errorMsg );
            Functions\when( 'wc_create_attribute' )->justReturn( $error );

            try {
                $fqcn::ensure();
                $this->fail( 'AC-4: ensure() MUSS bei WP_Error eine AttributeProvisionerException werfen.' );
            } catch ( \Throwable $e ) {
                $this->assertInstanceOf(
                    $exceptionFqcn,
                    $e,
                    'AC-4: Geworfener Exception-Typ MUSS AttributeProvisionerException sein, '
                    . 'war: ' . $e::class
                );
                $this->assertInstanceOf(
                    \RuntimeException::class,
                    $e,
                    'AC-4: AttributeProvisionerException MUSS \\RuntimeException erweitern '
                    . '(Action-Scheduler klassifiziert RuntimeException als permanent failure).'
                );
                $this->assertSame(
                    $errorMsg,
                    $e->getMessage(),
                    'AC-4: Exception-Message MUSS exakt die WP_Error-get_error_message() sein, '
                    . 'war: ' . $e->getMessage()
                );
            }
        }

        /**
         * AC-4: Fail-Fast — nach WP_Error fuer pa_groesse wird wc_create_attribute
         * NICHT mehr fuer pa_farbe aufgerufen.
         */
        public function test_ensure_aborts_remaining_creates_after_error(): void
        {
            $fqcn          = 'SpreadconnectPod\\Catalog\\AttributeProvisioner';
            $exceptionFqcn = 'SpreadconnectPod\\Catalog\\AttributeProvisionerException';

            Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( [] );

            // Spy + Error-Behaviour: 1. Aufruf liefert WP_Error,
            // 2. Aufruf darf gar nicht erst stattfinden.
            $createCalls = [];
            $error       = new WP_Error( 'reserved_slug', 'Slug "groesse" is reserved.' );
            Functions\when( 'wc_create_attribute' )->alias( static function ( $args ) use ( &$createCalls, $error ) {
                $createCalls[] = $args;
                return $error;
            } );

            $thrown = false;
            try {
                $fqcn::ensure();
            } catch ( \Throwable $e ) {
                $thrown = $e instanceof $exceptionFqcn;
            }

            $this->assertTrue(
                $thrown,
                'AC-4 (precondition): ensure() MUSS AttributeProvisionerException werfen.'
            );

            // AC-4: GENAU EIN Aufruf — pa_farbe wurde nach dem Throw nicht mehr versucht.
            $this->assertCount(
                1,
                $createCalls,
                'AC-4: Fail-Fast — nach WP_Error fuer pa_groesse darf wc_create_attribute '
                . 'NICHT mehr fuer pa_farbe aufgerufen werden. '
                . 'Tatsaechliche Aufrufe: ' . count( $createCalls )
            );

            // Sanity: der eine Aufruf ging fuer pa_groesse, nicht pa_farbe.
            $this->assertSame(
                'groesse',
                $createCalls[0]['slug'] ?? null,
                'AC-4: Der einzige Aufruf vor dem Throw MUSS fuer pa_groesse '
                . '(slug=groesse) gewesen sein — die TAXONOMIES-Iteration-Order '
                . 'beginnt mit pa_groesse.'
            );
        }

        // ===================================================================
        // AC-5: GIVEN die Klasse SpreadconnectPod\Bootstrap\Plugin::init()
        //             aus Slice 02/04
        //       WHEN Slice 20 abgeschlossen ist
        //       THEN registriert Plugin::init() einen zusaetzlichen
        //            register_activation_hook( $plugin_file,
        //              [AttributeProvisioner::class, 'ensure'] )-Aufruf
        //            — neben dem bestehenden Schema::install-Hook aus Slice 04.
        //            Der Static-Property-Guard verhindert doppelte Registrierung.
        // ===================================================================

        /**
         * AC-5: Plugin::init() registriert register_activation_hook
         * mit [AttributeProvisioner::class, 'ensure'].
         */
        public function test_plugin_init_registers_activation_hook_for_attribute_provisioner(): void
        {
            $pluginFqcn      = 'SpreadconnectPod\\Bootstrap\\Plugin';
            $provisionerFqcn = 'SpreadconnectPod\\Catalog\\AttributeProvisioner';
            $this->assertTrue( class_exists( $pluginFqcn ), 'AC-5: Plugin muss autoloadbar sein.' );
            $this->assertTrue( class_exists( $provisionerFqcn ), 'AC-5: AttributeProvisioner muss autoloadbar sein.' );

            $registrations = [];
            Functions\when( 'register_activation_hook' )->alias( static function ( $file, $callable ) use ( &$registrations ) {
                $registrations[] = [ $file, $callable ];
                return null;
            } );

            $pluginFile = self::pluginMainFile();
            $pluginFqcn::init( $pluginFile );

            $this->assertGreaterThanOrEqual(
                1,
                count( $registrations ),
                'AC-5: register_activation_hook MUSS mindestens einmal aufgerufen werden.'
            );

            // Suche die Registration mit [AttributeProvisioner::class, 'ensure'].
            $found = null;
            foreach ( $registrations as $reg ) {
                [ $file, $callable ] = $reg;
                if ( is_array( $callable )
                    && count( $callable ) === 2
                    && ltrim( (string) $callable[0], '\\' ) === $provisionerFqcn
                    && $callable[1] === 'ensure'
                ) {
                    $found = $reg;
                    break;
                }
            }

            $this->assertNotNull(
                $found,
                'AC-5: Plugin::init() MUSS register_activation_hook() mit Callable '
                . '[AttributeProvisioner::class, "ensure"] aufrufen. Registrierungen waren: '
                . print_r( $registrations, true )
            );

            // Erstes Argument MUSS der uebergebene $plugin_file sein
            // (nicht ein hardcoded __FILE__ aus AttributeProvisioner.php).
            $this->assertSame(
                $pluginFile,
                $found[0],
                'AC-5: register_activation_hook() MUSS als 1. Argument den $plugin_file '
                . 'erhalten, der an init() uebergeben wurde — KEIN hardcoded Pfad.'
            );
        }

        /**
         * AC-5: Doppelter Plugin::init() registriert Hook NICHT doppelt
         * (Idempotenz-Guard aus Slice 02 AC-5 MUSS greifen).
         */
        public function test_activation_hook_registered_only_once_per_init(): void
        {
            $pluginFqcn      = 'SpreadconnectPod\\Bootstrap\\Plugin';
            $provisionerFqcn = 'SpreadconnectPod\\Catalog\\AttributeProvisioner';

            $registrations = [];
            Functions\when( 'register_activation_hook' )->alias( static function ( $file, $callable ) use ( &$registrations ) {
                $registrations[] = [ $file, $callable ];
                return null;
            } );

            $pluginFile = self::pluginMainFile();

            // 2x init() — Idempotenz-Guard aus Slice 02 AC-5.
            $pluginFqcn::init( $pluginFile );
            $pluginFqcn::init( $pluginFile );

            // Zaehle nur die fuer AttributeProvisioner::ensure registrierten Hooks.
            $ensureCount = 0;
            foreach ( $registrations as $reg ) {
                [ , $callable ] = $reg;
                if ( is_array( $callable )
                    && count( $callable ) === 2
                    && ltrim( (string) $callable[0], '\\' ) === $provisionerFqcn
                    && $callable[1] === 'ensure'
                ) {
                    $ensureCount++;
                }
            }

            $this->assertSame(
                1,
                $ensureCount,
                'AC-5: Bei doppeltem Plugin::init() darf register_activation_hook fuer '
                . '[AttributeProvisioner::class, "ensure"] NUR EINMAL aufgerufen werden — '
                . 'der Idempotenz-Guard aus Slice 02 AC-5 MUSS greifen.'
            );
        }

        // ===================================================================
        // AC-6: GIVEN AttributeProvisioner::ensure()
        //       WHEN sie in einem Kontext aufgerufen wird, in dem
        //            wc_create_attribute() noch nicht geladen ist (z. B.
        //            CLI / sehr fruehe Plugin-Boot-Phase)
        //       THEN prueft die Methode function_exists('wc_create_attribute')
        //            und wirft bei false AttributeProvisionerException mit
        //            Message 'WooCommerce not loaded'. KEIN stillschweigender No-Op.
        // ===================================================================

        /**
         * AC-6: Ohne `wc_create_attribute`-Funktion -> AttributeProvisionerException
         * mit Message 'WooCommerce not loaded'.
         *
         * Wir laufen in einem separaten Process, damit weder Brain\Monkey-Aliase
         * aus vorherigen Tests noch ein versehentliches function_exists()-Caching
         * den `false`-Pfad ueberdecken.
         */
        #[RunInSeparateProcess]
        #[PreserveGlobalState( false )]
        public function test_ensure_throws_when_woocommerce_not_loaded(): void
        {
            // Im Separate-Process MUSS Brain\Monkey vom Test selbst eingerichtet
            // werden — der Parent-Process-setUp() greift hier nicht.
            Monkey\setUp();

            try {
                $fqcn          = 'SpreadconnectPod\\Catalog\\AttributeProvisioner';
                $exceptionFqcn = 'SpreadconnectPod\\Catalog\\AttributeProvisionerException';
                $this->assertTrue( class_exists( $fqcn ), 'AC-6: AttributeProvisioner muss autoloadbar sein.' );
                $this->assertTrue(
                    class_exists( $exceptionFqcn ),
                    'AC-6: AttributeProvisionerException muss verfuegbar sein.'
                );

                // Precondition: wc_create_attribute darf NICHT definiert sein.
                // Im Separate-Process startet die PHP-Funktionen-Tabelle frisch —
                // nur Brain\Monkey-eigene Funktionen + unsere Bootstrap-Stubs sind da.
                $this->assertFalse(
                    function_exists( 'wc_create_attribute' ),
                    'AC-6 (precondition): wc_create_attribute darf im Separate-Process nicht '
                    . 'definiert sein (nur dann ist der function_exists-Guard aktiv pruefbar).'
                );

                $thrown        = false;
                $thrownMessage = '';
                $thrownClass   = '';
                try {
                    $fqcn::ensure();
                } catch ( \Throwable $e ) {
                    $thrown        = true;
                    $thrownMessage = $e->getMessage();
                    $thrownClass   = $e::class;
                }

                $this->assertTrue(
                    $thrown,
                    'AC-6: ensure() MUSS eine Exception werfen, wenn wc_create_attribute '
                    . 'nicht geladen ist — KEIN stillschweigender No-Op.'
                );

                $this->assertSame(
                    $exceptionFqcn,
                    ltrim( $thrownClass, '\\' ),
                    'AC-6: Geworfener Exception-Typ MUSS AttributeProvisionerException sein, '
                    . 'war: ' . $thrownClass
                );

                $this->assertSame(
                    'WooCommerce not loaded',
                    $thrownMessage,
                    'AC-6: Exception-Message MUSS exakt "WooCommerce not loaded" sein, '
                    . 'war: ' . $thrownMessage
                );
            } finally {
                Monkey\tearDown();
            }
        }

        // ===================================================================
        // AC-7: GIVEN das Discovery-Trade-off "fixed slugs pa_groesse/pa_farbe"
        //       WHEN ensure() mit anderen Slug-Argumenten aufgerufen wird
        //             (Theorie: erweiternder Aufrufer)
        //       THEN akzeptiert die Methode KEINE Slug-Parameter — Signatur
        //            ist parameterlos `public static function ensure(): array`.
        //            Die Slug-Liste ist als private Class-Constant TAXONOMIES
        //            fest verdrahtet.
        // ===================================================================

        /**
         * AC-7: Reflection: ensure() ist public static, () -> array,
         * KEIN Parameter. AttributeProvisioner ist final class.
         */
        public function test_ensure_signature_has_no_parameters(): void
        {
            $fqcn = 'SpreadconnectPod\\Catalog\\AttributeProvisioner';
            $this->assertTrue( class_exists( $fqcn ), 'AC-7: AttributeProvisioner muss autoloadbar sein.' );

            $reflection = new ReflectionClass( $fqcn );

            // final class — keine Subclasses, kein DI/State.
            $this->assertTrue(
                $reflection->isFinal(),
                'AC-7 / Constraint: AttributeProvisioner MUSS final class sein '
                . '(keine Subclasses, nur statische Methoden, kein State).'
            );

            // ensure()-Methode existiert.
            $this->assertTrue(
                $reflection->hasMethod( 'ensure' ),
                'AC-7: AttributeProvisioner MUSS Methode "ensure" haben.'
            );

            $method = $reflection->getMethod( 'ensure' );

            // public static.
            $this->assertTrue( $method->isPublic(), 'AC-7: ensure() MUSS public sein.' );
            $this->assertTrue( $method->isStatic(), 'AC-7: ensure() MUSS static sein.' );

            // KEINE Parameter.
            $this->assertCount(
                0,
                $method->getParameters(),
                'AC-7: ensure() MUSS parameterlos sein — die Slug-Liste ist als '
                . 'private const TAXONOMIES fest verdrahtet, KEIN extern injizierbarer '
                . 'Slug-Parameter erlaubt.'
            );

            // Return-Type: array.
            $this->assertTrue(
                $method->hasReturnType(),
                'AC-7: ensure() MUSS einen Return-Type haben.'
            );
            $returnType = $method->getReturnType();
            $this->assertInstanceOf(
                ReflectionNamedType::class,
                $returnType,
                'AC-7: ensure()-Return-Type MUSS ein ReflectionNamedType sein (kein Union).'
            );
            $this->assertSame(
                'array',
                $returnType->getName(),
                'AC-7: ensure() MUSS "array" zurueckgeben.'
            );
        }

        /**
         * AC-7 (Constraint): TAXONOMIES ist als private const verdrahtet
         * und enthaelt exakt die Slugs pa_groesse und pa_farbe.
         */
        public function test_taxonomies_are_private_class_constant(): void
        {
            $fqcn = 'SpreadconnectPod\\Catalog\\AttributeProvisioner';
            $this->assertTrue( class_exists( $fqcn ), 'AC-7: AttributeProvisioner muss autoloadbar sein.' );

            $reflection = new ReflectionClass( $fqcn );

            $this->assertTrue(
                $reflection->hasConstant( 'TAXONOMIES' ),
                'AC-7: AttributeProvisioner MUSS eine TAXONOMIES-Konstante haben '
                . '(siehe architecture.md "Decisions" -> "pa_groesse / pa_farbe fixed").'
            );

            // private const — Reflection-API: hasConstant matched alle, also
            // gehen wir ueber getReflectionConstants() um Visibility zu pruefen.
            $constReflection = $reflection->getReflectionConstant( 'TAXONOMIES' );
            $this->assertNotFalse(
                $constReflection,
                'AC-7: TAXONOMIES-Konstanten-Reflection MUSS auflösbar sein.'
            );
            $this->assertTrue(
                $constReflection->isPrivate(),
                'AC-7: TAXONOMIES MUSS PRIVATE const sein — kein externer Aufrufer '
                . 'darf die Slug-Liste ueberschreiben oder lesen koennen.'
            );

            $taxonomies = $reflection->getConstant( 'TAXONOMIES' );
            $this->assertIsArray( $taxonomies, 'AC-7: TAXONOMIES MUSS ein array sein.' );

            // Genau zwei Eintraege.
            $this->assertCount(
                2,
                $taxonomies,
                'AC-7: TAXONOMIES MUSS genau 2 Eintraege haben (pa_groesse, pa_farbe).'
            );

            // Reihenfolge der Schluessel ist signifikant.
            $this->assertSame(
                [ 'pa_groesse', 'pa_farbe' ],
                array_keys( $taxonomies ),
                'AC-7: TAXONOMIES-Keys MUSSEN exakt [pa_groesse, pa_farbe] in dieser '
                . 'Reihenfolge sein (Iteration-Order ist signifikant fuer AC-1 Created-Order).'
            );

            // Args-Map fuer pa_groesse.
            $this->assertSame(
                [
                    'name'         => 'Groesse',
                    'slug'         => 'groesse',
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ],
                $taxonomies['pa_groesse'],
                'AC-7 / Constraint: TAXONOMIES[pa_groesse] MUSS exakt die WC-API-Args haben.'
            );

            // Args-Map fuer pa_farbe.
            $this->assertSame(
                [
                    'name'         => 'Farbe',
                    'slug'         => 'farbe',
                    'type'         => 'select',
                    'order_by'     => 'menu_order',
                    'has_archives' => false,
                ],
                $taxonomies['pa_farbe'],
                'AC-7 / Constraint: TAXONOMIES[pa_farbe] MUSS exakt die WC-API-Args haben.'
            );
        }
    }
}
