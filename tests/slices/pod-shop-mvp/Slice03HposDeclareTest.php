<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Slice 03 — HPOS-Declare + WC-Compat-Hook
 *
 * Acceptance Tests gegen die Slice-Spec `slice-03-hpos-declare.md`.
 *
 * Mocking Strategy: `mock_external` (Slice-Spec):
 *   - Brain\Monkey fuer `add_action` (Spy auf Hook-Registration).
 *   - Klasse `Automattic\WooCommerce\Utilities\FeaturesUtil` als Test-Stub
 *     mit static-spy via `\Patchwork\redefine` waere ideal — wir verwenden
 *     hier den einfacheren Ansatz: Stub-Klasse mit eigener `declare_compatibility`-
 *     Methode, die ihre Argumente in einem statischen Spy-Array sammelt.
 *
 * AC-3 (no Fatal wenn FeaturesUtil fehlt) wird in einem separaten Process
 * ausgefuehrt (`@runInSeparateProcess`), damit die in AC-2/AC-4 deklarierte
 * Stub-Klasse die "FeaturesUtil existiert nicht"-Bedingung nicht beeinflusst.
 */
final class Slice03HposDeclareTest extends TestCase
{
    /**
     * Repo-Root: drei Verzeichnisse oberhalb von `tests/slices/pod-shop-mvp/`.
     */
    private static function repoRoot(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }

    /**
     * Absoluter Pfad zur Bootstrap\Plugin-Klassendatei.
     */
    private static function pluginClassFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php';
    }

    /**
     * Absoluter Pfad zur Plugin-Hauptdatei.
     */
    private static function pluginMainFile(): string
    {
        return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';
    }

    /**
     * Reset Plugin-internen State (Idempotenz-Guard) zwischen Tests.
     */
    private static function resetPluginState(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        if (! class_exists($fqcn)) {
            return;
        }

        $reflection = new ReflectionClass($fqcn);

        if ($reflection->hasProperty('initialized')) {
            $prop = $reflection->getProperty('initialized');
            $prop->setValue(null, false);
        }
        if ($reflection->hasProperty('pluginFile')) {
            $prop = $reflection->getProperty('pluginFile');
            $prop->setValue(null, '');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        self::resetPluginState();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        self::resetPluginState();
        parent::tearDown();
    }

    // ===================================================================
    // AC-1: GIVEN Plugin::init( $plugin_file ) wird aufgerufen
    //       WHEN die Methode ihre Hook-Registrierung durchlaeuft
    //       THEN ist genau ein Listener fuer `before_woocommerce_init`
    //            mit Priority 10 registriert; Listener ist public static
    //            method auf SpreadconnectPod\Bootstrap\Plugin.
    // ===================================================================

    /**
     * AC-1: Plugin::init() registriert before_woocommerce_init-Hook (Priority 10).
     */
    public function test_init_registers_before_woocommerce_init_hook(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $this->assertTrue(class_exists($fqcn), 'AC-1: Bootstrap\\Plugin muss autoloadbar sein.');

        // Vor dem init()-Aufruf: noch kein Listener registriert.
        $this->assertFalse(
            Actions\has('before_woocommerce_init', [ $fqcn, 'declareHposCompatibility' ]),
            'AC-1 (precondition): vor init() darf kein Listener registriert sein.'
        );

        // init() durchlaeuft Hook-Registration.
        $fqcn::init(self::pluginMainFile());

        // Listener mit Default-Priority 10 ist registriert.
        $this->assertNotFalse(
            Actions\has('before_woocommerce_init', [ $fqcn, 'declareHposCompatibility' ]),
            'AC-1: Plugin::init() muss einen Listener fuer "before_woocommerce_init" '
            . 'mit Callback [Plugin::class, "declareHposCompatibility"] registrieren.'
        );

        // Priority MUSS exakt 10 sein (WP-Default; Slice-Spec AC-1 fordert "Priority 10").
        $priority = Actions\has('before_woocommerce_init', [ $fqcn, 'declareHposCompatibility' ]);
        $this->assertSame(
            10,
            $priority,
            'AC-1: before_woocommerce_init-Listener muss mit Priority 10 registriert sein '
            . '(WP-Default; spec-vorgegeben).'
        );
    }

    /**
     * AC-1: Listener ist public static method auf Bootstrap\Plugin.
     */
    public function test_hook_callback_is_public_static_method_on_plugin(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $this->assertTrue(class_exists($fqcn), 'AC-1: Bootstrap\\Plugin muss autoloadbar sein.');

        $reflection = new ReflectionClass($fqcn);

        $this->assertTrue(
            $reflection->hasMethod('declareHposCompatibility'),
            'AC-1: Bootstrap\\Plugin muss eine Methode "declareHposCompatibility" haben — '
            . 'sie wird als WP-Hook-Callable benoetigt.'
        );

        $method = $reflection->getMethod('declareHposCompatibility');

        $this->assertTrue(
            $method->isPublic(),
            'AC-1: declareHposCompatibility() MUSS public sein — '
            . 'WP-Hook-Callbacks erfordern oeffentliche Sichtbarkeit '
            . '(private/protected wuerden Fatal Error werfen sobald der Hook feuert).'
        );

        $this->assertTrue(
            $method->isStatic(),
            'AC-1: declareHposCompatibility() MUSS static sein — '
            . 'der Callback wird als [Plugin::class, "declareHposCompatibility"] '
            . 'array-Callable registriert (kein Instance-State noetig).'
        );

        $this->assertCount(
            0,
            $method->getParameters(),
            'AC-1: declareHposCompatibility() darf keine Parameter haben — '
            . 'WP feuert before_woocommerce_init ohne Argumente.'
        );

        $this->assertTrue(
            $method->hasReturnType(),
            'AC-1: declareHposCompatibility() muss einen Return-Type haben (PHP 8.2 strict types).'
        );

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType, 'AC-1: Return-Type muss aufloesbar sein.');
        $this->assertSame(
            'void',
            (string) $returnType,
            'AC-1: declareHposCompatibility() muss "void" zurueckgeben (kein Wert relevant fuer WP).'
        );
    }

    // ===================================================================
    // AC-2: GIVEN before_woocommerce_init feuert, FeaturesUtil existiert
    //       WHEN der registrierte Listener ausgefuehrt wird
    //       THEN ruft er FeaturesUtil::declare_compatibility() mit
    //            ('custom_order_tables', $plugin_file, true) — exakt einmal.
    //
    // AC-4: Zweites Argument ist Plugin::pluginFile() — nicht hardcoded /
    //       __FILE__ aus der Bootstrap-Klassen-Datei.
    // ===================================================================

    /**
     * AC-2 + AC-4 kombiniert: Listener-Aufruf mit FeaturesUtil-Stub.
     *
     * Wir registrieren eine Stub-Klasse `Automattic\WooCommerce\Utilities\FeaturesUtil`
     * mit einer static-spy Methode `declare_compatibility`, die ihre Argumente
     * in einer statischen Property sammelt. Ueberpruefung:
     *   - AC-2: Anzahl Calls == 1, Args == ['custom_order_tables', <plugin_file>, true]
     *   - AC-4: Args[1] == Plugin::pluginFile() (kein hardcoded Pfad).
     */
    public function test_listener_calls_features_util_declare_compatibility_with_correct_args(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';

        // Lazy-define FeaturesUtil-Stub (im richtigen Namespace) sofern noch
        // nicht von einem vorherigen Test in dieser Run-Session deklariert.
        // Eval ist hier vertretbar: Es geht um eine Stub-Klassendefinition
        // im Namespace Automattic\WooCommerce\Utilities, die in der
        // Test-Umgebung nicht existiert (kein WC installiert).
        if (! class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            eval(
                'namespace Automattic\\WooCommerce\\Utilities;'
                . ' class FeaturesUtil {'
                . '   public static array $calls = [];'
                . '   public static function declare_compatibility('
                . '     string $feature_id, string $plugin_file, bool $positive_compat = true'
                . '   ): bool {'
                . '     self::$calls[] = [ $feature_id, $plugin_file, $positive_compat ];'
                . '     return true;'
                . '   }'
                . ' }'
            );
        }

        // Reset Spy-State (Test-Isolation).
        \Automattic\WooCommerce\Utilities\FeaturesUtil::$calls = [];

        // init() registriert Hook + setzt $pluginFile.
        $pluginFile = self::pluginMainFile();
        $fqcn::init($pluginFile);

        // Pre-Assertion: pluginFile()-Getter liefert exakt den uebergebenen Pfad.
        // AC-4 vergleicht das zweite declare_compatibility-Argument hiergegen.
        $this->assertSame(
            $pluginFile,
            $fqcn::pluginFile(),
            'AC-4 (precondition): Plugin::pluginFile() muss den in init() uebergebenen '
            . 'Pfad zurueckgeben (Slice 02 Provides).'
        );

        // Listener direkt aufrufen (Hook-Fire-Simulation; Brain\Monkey simuliert
        // do_action() nicht-callbackend, daher Direkt-Call).
        $fqcn::declareHposCompatibility();

        // AC-2: Genau ein Aufruf von declare_compatibility().
        $this->assertCount(
            1,
            \Automattic\WooCommerce\Utilities\FeaturesUtil::$calls,
            'AC-2: declare_compatibility() muss exakt einmal pro Hook-Fire aufgerufen werden.'
        );

        $args = \Automattic\WooCommerce\Utilities\FeaturesUtil::$calls[0];

        // AC-2: Erstes Argument ist 'custom_order_tables' (string).
        $this->assertSame(
            'custom_order_tables',
            $args[0],
            'AC-2: Erstes Argument an FeaturesUtil::declare_compatibility() muss '
            . 'genau "custom_order_tables" (string) sein — der HPOS-Feature-ID-String.'
        );

        // AC-4: Zweites Argument == Plugin::pluginFile() (nicht hardcoded).
        $this->assertSame(
            $fqcn::pluginFile(),
            $args[1],
            'AC-4: Zweites Argument an FeaturesUtil::declare_compatibility() MUSS '
            . 'identisch mit Plugin::pluginFile() sein — kein hardcoded Pfad, '
            . 'kein __FILE__ aus der Bootstrap-Klassen-Datei.'
        );
        $this->assertSame(
            $pluginFile,
            $args[1],
            'AC-4: Zweites Argument muss exakt der in init() uebergebene Plugin-Hauptdatei-Pfad sein.'
        );

        // AC-2: Drittes Argument ist true (bool, Compat-Flag).
        $this->assertTrue(
            $args[2],
            'AC-2: Drittes Argument an FeaturesUtil::declare_compatibility() muss bool(true) sein.'
        );
        $this->assertSame(
            true,
            $args[2],
            'AC-2: Drittes Argument muss strikt === true sein (kein truthy int/string).'
        );
    }

    /**
     * AC-2 (Adversarial): Doppelter Hook-Fire ruft Listener-Aufrufe je 1x ab.
     *
     * Wenn der Listener N-mal direkt aufgerufen wird (entspricht N Hook-Fires),
     * muss declare_compatibility N-mal aufgerufen werden — kein internes
     * "schon erledigt"-Flag.
     */
    public function test_each_listener_invocation_calls_declare_compatibility_once(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';

        if (! class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            eval(
                'namespace Automattic\\WooCommerce\\Utilities;'
                . ' class FeaturesUtil {'
                . '   public static array $calls = [];'
                . '   public static function declare_compatibility('
                . '     string $feature_id, string $plugin_file, bool $positive_compat = true'
                . '   ): bool {'
                . '     self::$calls[] = [ $feature_id, $plugin_file, $positive_compat ];'
                . '     return true;'
                . '   }'
                . ' }'
            );
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::$calls = [];

        $fqcn::init(self::pluginMainFile());

        // Simuliere zwei Hook-Fires (z. B. WP feuert Hook in Mehrfach-Boot-Szenario).
        $fqcn::declareHposCompatibility();
        $fqcn::declareHposCompatibility();

        $this->assertCount(
            2,
            \Automattic\WooCommerce\Utilities\FeaturesUtil::$calls,
            'AC-2: Pro Hook-Fire muss declare_compatibility genau einmal aufgerufen werden — '
            . 'kein internes Caching/Single-Call-Flag im Listener.'
        );
    }

    // ===================================================================
    // AC-3: GIVEN before_woocommerce_init feuert OHNE FeaturesUtil
    //       WHEN der Listener ausgefuehrt wird
    //       THEN kein Error/Exception (kein Fatal "Class not found"),
    //            class_exists()-Guard, no-op return.
    //
    // Wir testen das auf zwei Arten:
    //   (a) statisch: Plugin.php enthaelt einen class_exists-Guard auf
    //       den FQCN Automattic\WooCommerce\Utilities\FeaturesUtil
    //   (b) dynamisch: Aufruf in einer Run-Session ohne FeaturesUtil-Stub
    //       wirft nicht.
    // ===================================================================

    /**
     * AC-3 (statisch): Source enthaelt class_exists-Guard auf FeaturesUtil-FQCN.
     *
     * Constraint aus Spec: kein String-Literal-Check via class_exists('...');
     * der Guard MUSS den FQCN als Klassenkonstante (`FeaturesUtil::class`)
     * referenzieren — entweder via `\Automattic\WooCommerce\Utilities\FeaturesUtil::class`
     * (FQN) oder via `use`-Import + `FeaturesUtil::class`.
     */
    public function test_listener_source_has_class_exists_guard_on_features_util(): void
    {
        $file = self::pluginClassFile();
        $this->assertFileExists($file, 'AC-3: Plugin.php muss existieren.');

        $contents = (string) file_get_contents($file);
        $this->assertNotSame('', $contents, 'AC-3: Plugin.php darf nicht leer sein.');

        // Variante 1: `class_exists( FeaturesUtil::class )` — setzt use-Import voraus.
        $patternShortClass = '/class_exists\s*\(\s*FeaturesUtil::class\s*\)/';

        // Variante 2: FQCN inline.
        $patternFqnClass = '/class_exists\s*\(\s*\\\\?Automattic\\\\WooCommerce\\\\Utilities\\\\FeaturesUtil::class\s*\)/';

        $matchedShort = preg_match($patternShortClass, $contents) === 1;
        $matchedFqn   = preg_match($patternFqnClass, $contents) === 1;

        $this->assertTrue(
            $matchedShort || $matchedFqn,
            'AC-3 / Constraint: Plugin.php MUSS einen class_exists()-Guard enthalten, der '
            . 'den FQCN Automattic\\WooCommerce\\Utilities\\FeaturesUtil als '
            . '::class-Konstante referenziert (kein String-Literal-Check via class_exists("...")).'
        );

        // Wenn die Short-Variante verwendet wird, MUSS auch ein use-Import stehen.
        if ($matchedShort && ! $matchedFqn) {
            $this->assertMatchesRegularExpression(
                '/use\s+Automattic\\\\WooCommerce\\\\Utilities\\\\FeaturesUtil\s*;/',
                $contents,
                'AC-3 / Constraint: Wenn `class_exists( FeaturesUtil::class )` verwendet wird, '
                . 'muss "use Automattic\\\\WooCommerce\\\\Utilities\\\\FeaturesUtil;" im File stehen.'
            );
        }
    }

    /**
     * AC-3 (dynamisch): Listener-Aufruf ohne FeaturesUtil wirft nicht.
     *
     * `#[RunInSeparateProcess]` (zusammen mit `#[PreserveGlobalState(false)]`)
     * gibt uns einen frischen PHP-Process ohne die in den AC-2-Tests via
     * `eval()` deklarierte FeaturesUtil-Stub-Klasse. So koennen wir die
     * "FeaturesUtil existiert nicht"-Bedingung wirklich pruefen.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_listener_is_noop_when_features_util_class_missing(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $this->assertTrue(class_exists($fqcn), 'AC-3: Bootstrap\\Plugin muss autoloadbar sein.');

        // Sanity: FeaturesUtil ist im frischen Process NICHT geladen.
        $this->assertFalse(
            class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil', false),
            'AC-3 (precondition): FeaturesUtil darf in einem frischen Process ohne WC '
            . 'NICHT vorhanden sein. Falls dieser Test-Lauf falsch isoliert ist, '
            . 'liefert er ein false-positive.'
        );

        // Listener-Aufruf MUSS ohne Throwable abschliessen.
        try {
            $fqcn::declareHposCompatibility();
        } catch (\Throwable $e) {
            $this->fail(
                'AC-3: declareHposCompatibility() darf bei fehlender FeaturesUtil-Klasse '
                . 'KEIN Throwable werfen (kein Fatal "Class not found"). Geworfen: '
                . $e::class . ' — ' . $e->getMessage()
            );
        }

        // Erfolgreich erreicht: no-op-Pfad funktioniert.
        $this->assertFalse(
            class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil', false),
            'AC-3 (post): Listener darf FeaturesUtil nicht autoloadbar machen oder '
            . 'auf andere Weise eine Klasse erzeugen — bleibt no-op.'
        );
    }

    // ===================================================================
    // AC-5: GIVEN Plugin::init() wird zweimal mit demselben $plugin_file
    //             aufgerufen (Idempotenz aus Slice 02 AC-5)
    //       WHEN das Hook-System before_woocommerce_init feuert
    //       THEN wird der Listener trotzdem genau einmal registriert,
    //            FeaturesUtil::declare_compatibility nur einmal pro Fire.
    // ===================================================================

    /**
     * Inspiziert das Brain\Monkey HookStorage und liefert die Anzahl
     * registrierter Callbacks fuer einen bestimmten (hook, callable).
     *
     * Brain\Monkey speichert intern:
     *   $storage[ADDED][ACTIONS][hookName][] = [ CallbackStringForm, priority, acceptedArgs ]
     *
     * Wir vergleichen ueber `CallbackStringForm::__toString()` — das ist
     * der stabile, public-zugaengliche Vergleichsmechanismus der Library.
     */
    private static function countRegistrationsForActionHook(
        string $hookName,
        callable|array $targetCallback
    ): int {
        $hookStorage = \Brain\Monkey\Container::instance()->hookStorage();
        $refl = new ReflectionClass($hookStorage);

        if (! $refl->hasProperty('storage')) {
            // Brain\Monkey-Version-Drift: HookStorage hat keine `$storage`-Property mehr.
            return -1;
        }

        $prop = $refl->getProperty('storage');
        $all  = $prop->getValue($hookStorage);

        $added   = $all[\Brain\Monkey\Hook\HookStorage::ADDED] ?? null;
        $actions = $added[\Brain\Monkey\Hook\HookStorage::ACTIONS] ?? null;
        $forHook = $actions[$hookName] ?? null;

        if (! is_array($forHook)) {
            return 0;
        }

        $targetForm = (string) new \Brain\Monkey\Name\CallbackStringForm($targetCallback);

        $count = 0;
        foreach ($forHook as $registration) {
            // Registration shape: [ CallbackStringForm, priority, acceptedArgs ]
            if (! is_array($registration) || ! isset($registration[0])) {
                continue;
            }
            $cb = $registration[0];
            if ($cb instanceof \Brain\Monkey\Name\CallbackStringForm
                && (string) $cb === $targetForm
            ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * AC-5: Doppelter init()-Aufruf registriert Hook nicht doppelt.
     *
     * Wir inspizieren das interne Brain\Monkey HookStorage und zaehlen,
     * wie oft unser konkreter Callback fuer 'before_woocommerce_init'
     * registriert wurde. Eine korrekte Implementierung wickelt
     * `add_action(...)` innerhalb des Idempotenz-Guards aus Slice 02 ab;
     * der zweite init()-Aufruf darf also kein add_action() mehr feuern.
     *
     * WP de-dupes statische Method-Callables auf identischer Klasse NICHT
     * automatisch (Slice-Spec Constraint), daher waere ein zweites
     * add_action() ein echter Bug — der hier sichtbar wuerde.
     */
    public function test_double_init_does_not_duplicate_hook_registration(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $callback = [ $fqcn, 'declareHposCompatibility' ];

        // 1. init() — registriert Hook zum ersten Mal.
        $fqcn::init(self::pluginMainFile());

        $countAfterFirst = self::countRegistrationsForActionHook(
            'before_woocommerce_init',
            $callback
        );

        // Falls -1 zurueckkommt, hat Brain\Monkey seine Internals geaendert —
        // wir markieren den Test als incomplete (kein silent pass).
        if ($countAfterFirst === -1) {
            $this->markTestIncomplete(
                'AC-5: Brain\\Monkey HookStorage hat keine inspizierbare $storage-Property '
                . 'in dieser Version — Aktualisierung des Tests noetig.'
            );
        }

        $this->assertSame(
            1,
            $countAfterFirst,
            'AC-5 (precondition): Nach erstem init() muss genau 1 Registration vorhanden sein.'
        );

        // 2. init() mit demselben Pfad — Idempotenz-Guard MUSS Re-Register verhindern.
        $fqcn::init(self::pluginMainFile());

        $countAfterSecond = self::countRegistrationsForActionHook(
            'before_woocommerce_init',
            $callback
        );

        $this->assertSame(
            1,
            $countAfterSecond,
            'AC-5: Doppelter Plugin::init()-Aufruf darf den before_woocommerce_init-Listener '
            . 'genau EINMAL registrieren — der Idempotenz-Guard aus Slice 02 muss verhindern, '
            . 'dass add_action() beim zweiten Aufruf erneut feuert. '
            . 'WP de-dupes static method callables nicht automatisch.'
        );

        // Adversarial: dritter Aufruf mit ANDEREM Pfad — Guard muss auch hier
        // halten (kein Drift, kein zusaetzliches add_action).
        $fqcn::init('/dev/null/another-plugin-path.php');

        $countAfterThird = self::countRegistrationsForActionHook(
            'before_woocommerce_init',
            $callback
        );

        $this->assertSame(
            1,
            $countAfterThird,
            'AC-5: Auch bei drittem init() mit anderem $plugin_file darf der Listener '
            . 'nicht erneut registriert werden — Idempotenz-Guard ist absolut.'
        );
    }

    /**
     * AC-5 (Verhaltens-Garantie): doppelter init() + ein Hook-Fire ergibt
     * genau EINEN declare_compatibility-Call.
     *
     * Beweisfuehrung: wir laufen ueber jede registrierte Brain\Monkey-Action
     * und rufen den dort gespeicherten Callback einmal auf — das simuliert
     * EINEN Hook-Fire. Wenn der Plugin-Code zwei add_action()-Aufrufe gemacht
     * haette, lief der FeaturesUtil-Spy zweimal — was hier sofort sichtbar
     * waere.
     */
    public function test_double_init_yields_single_declare_compatibility_per_fire(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $callback = [ $fqcn, 'declareHposCompatibility' ];

        if (! class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            eval(
                'namespace Automattic\\WooCommerce\\Utilities;'
                . ' class FeaturesUtil {'
                . '   public static array $calls = [];'
                . '   public static function declare_compatibility('
                . '     string $feature_id, string $plugin_file, bool $positive_compat = true'
                . '   ): bool {'
                . '     self::$calls[] = [ $feature_id, $plugin_file, $positive_compat ];'
                . '     return true;'
                . '   }'
                . ' }'
            );
        }

        \Automattic\WooCommerce\Utilities\FeaturesUtil::$calls = [];

        // Doppelter init() — der Idempotenz-Guard greift ab dem 2. Aufruf.
        $fqcn::init(self::pluginMainFile());
        $fqcn::init(self::pluginMainFile());

        // Anzahl Registrationen muss 1 sein (siehe vorheriger Test).
        $count = self::countRegistrationsForActionHook('before_woocommerce_init', $callback);
        $this->assertSame(
            1,
            $count,
            'AC-5: Erwartete genau 1 Registration nach doppeltem init().'
        );

        // Simuliere EINEN Hook-Fire: invoke jede gespeicherte Registration einmal.
        // Mit n=1 Registration sollte declare_compatibility genau 1x aufgerufen werden.
        for ($i = 0; $i < $count; $i++) {
            \call_user_func($callback);
        }

        $this->assertCount(
            1,
            \Automattic\WooCommerce\Utilities\FeaturesUtil::$calls,
            'AC-5: Pro Hook-Fire darf FeaturesUtil::declare_compatibility nur EINMAL aufgerufen '
            . 'werden — eine doppelte Hook-Registration wuerde hier zu 2 Calls fuehren.'
        );
    }
}
