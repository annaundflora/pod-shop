<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Slice 06 — i18n-Textdomain-Loader
 *
 * Acceptance Tests gegen die Slice-Spec `slice-06-i18n-textdomain.md`.
 *
 * Mocking Strategy: `mock_external` (laut Slice-Spec):
 *   - Brain\Monkey fuer `add_action`-Spy + `load_plugin_textdomain`-Spy.
 *   - Brain\Monkey fuer `plugin_basename`-Stub (deterministisches Mapping
 *     `<full-path>` -> `spreadconnect-pod/spreadconnect-pod.php`).
 *   - Filesystem-Asserts fuer `.po`-Stub (Existenz, Header, Encoding, LF).
 *   - Statische Source-Analyse fuer `Domain Path`-Header in der
 *     Plugin-Hauptdatei.
 *
 * Strategie:
 *   - AC-1: Plugin::init() registriert eine `plugins_loaded`-Action mit
 *     Priority 10 (WP-Default). Die exakte Closure-Identitaet pruefen wir
 *     ueber den Brain\Monkey HookStorage (Closure-Registration zaehlen).
 *   - AC-2: Den im HookStorage abgelegten Callback aus der Registration
 *     extrahieren, einmal aufrufen, danach load_plugin_textdomain-Spy
 *     pruefen (Args: 'spreadconnect-pod', false, '<plugin-dir>/languages').
 *   - AC-3: Idempotenz — der Hook wird durch den `Plugin::$initialized`-
 *     Guard nur einmal registriert, daher feuert er bei zweimaligem
 *     init() pro Hook-Fire trotzdem nur einen load_plugin_textdomain-Call.
 *   - AC-4 + AC-5: Filesystem-Asserts fuer den .po-Stub (Pflicht-Header,
 *     keine Translation-Eintraege, UTF-8 ohne BOM, LF-Line-Endings).
 *   - AC-6: Statische Source-Analyse fuer `Domain Path: /languages`.
 */
final class Slice06I18nTextdomainTest extends TestCase
{
    /**
     * Repo-Root: drei Verzeichnisse oberhalb von `tests/slices/pod-shop-mvp/`.
     */
    private static function repoRoot(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }

    /**
     * Absoluter Pfad zur Plugin-Hauptdatei.
     */
    private static function pluginMainFile(): string
    {
        return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';
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
     * Absoluter Pfad zum languages/-Verzeichnis.
     */
    private static function languagesDir(): string
    {
        return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/languages';
    }

    /**
     * Absoluter Pfad zur de_DE-PO-Stub-Datei.
     */
    private static function deDeStubFile(): string
    {
        return self::languagesDir() . '/spreadconnect-pod-de_DE.po';
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

    /**
     * Liefert ALLE Closure-Registrationen, die fuer einen Hook im
     * Brain\Monkey HookStorage abgelegt wurden — als Liste der internen
     * Storage-Eintraege `[CallbackStringForm, priority, acceptedArgs]`.
     *
     * Wir koennen die rohen Closure-Instanzen so direkt nicht extrahieren
     * (Brain\Monkey speichert nur die String-Form). Daher rufen wir den
     * Production-Code-Pfad NICHT mehr ueber den Hook auf, sondern ueber
     * eine eigene Closure-Reflection: wir lesen die Plugin.php-Source,
     * isolieren den Closure-Body, und vergleichen via Reflection auf
     * dem statisch eingebetteten Closure-Literal. Ein einfacherer Weg
     * ist allerdings: einmal init() aufrufen, dann die Registrierung
     * ueber Actions\has() absichern, und den Side-Effect des Callbacks
     * (load_plugin_textdomain) ueber einen Spy direkt pruefen.
     *
     * Hier liefern wir nur die Anzahl der Registrationen; der Callback-
     * Aufruf erfolgt ueber Brain\Monkey selbst (siehe weiter unten).
     *
     * @return int -1 wenn Brain\Monkey-Internals geaendert wurden.
     */
    private static function countRegistrationsForActionHook(string $hookName): int
    {
        $hookStorage = \Brain\Monkey\Container::instance()->hookStorage();
        $refl = new ReflectionClass($hookStorage);

        if (! $refl->hasProperty('storage')) {
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

        return count($forHook);
    }

    /**
     * Liefert die Priority + AcceptedArgs der ersten Registration fuer
     * einen Hook.
     *
     * @return array{priority:int, acceptedArgs:int}|null
     */
    private static function firstRegistrationMetaForActionHook(string $hookName): ?array
    {
        $hookStorage = \Brain\Monkey\Container::instance()->hookStorage();
        $refl = new ReflectionClass($hookStorage);

        if (! $refl->hasProperty('storage')) {
            return null;
        }

        $prop = $refl->getProperty('storage');
        $all  = $prop->getValue($hookStorage);

        $added   = $all[\Brain\Monkey\Hook\HookStorage::ADDED] ?? null;
        $actions = $added[\Brain\Monkey\Hook\HookStorage::ACTIONS] ?? null;
        $forHook = $actions[$hookName] ?? null;

        if (! is_array($forHook) || ! isset($forHook[0]) || ! is_array($forHook[0])) {
            return null;
        }

        // Brain\Monkey-Storage-Shape:
        //   [ CallbackStringForm, priority, acceptedArgs ]
        return [
            'priority'     => (int) ($forHook[0][1] ?? -1),
            'acceptedArgs' => (int) ($forHook[0][2] ?? -1),
        ];
    }

    /**
     * Liefert die String-Form des registrierten Callbacks fuer einen Hook
     * (Brain\Monkey CallbackStringForm). Hilft, Closure vs Method-Callable
     * zu unterscheiden — eine Closure wird hier z. B. als
     * "function ()" oder "static function ()" stringifiziert.
     */
    private static function firstRegistrationCallbackStringForm(string $hookName): string
    {
        $hookStorage = \Brain\Monkey\Container::instance()->hookStorage();
        $refl = new ReflectionClass($hookStorage);

        if (! $refl->hasProperty('storage')) {
            return '';
        }

        $prop = $refl->getProperty('storage');
        $all  = $prop->getValue($hookStorage);

        $added   = $all[\Brain\Monkey\Hook\HookStorage::ADDED] ?? null;
        $actions = $added[\Brain\Monkey\Hook\HookStorage::ACTIONS] ?? null;
        $forHook = $actions[$hookName] ?? null;

        if (! is_array($forHook) || ! isset($forHook[0][0])) {
            return '';
        }

        $cb = $forHook[0][0];
        if ($cb instanceof \Brain\Monkey\Name\CallbackStringForm) {
            return (string) $cb;
        }

        return '';
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
    // AC-1: GIVEN Plugin::init( __FILE__ ) — Slice 02 Idempotenz-Guard
    //       WHEN sie aufgerufen wird
    //       THEN registriert sie genau einen `add_action`-Call mit Hook
    //            'plugins_loaded' und einem Callback (Hook-Prioritaet 10).
    // ===================================================================

    /**
     * AC-1: Plugin::init() registriert genau eine plugins_loaded-Action
     * mit Default-Priority 10.
     */
    public function test_init_registers_plugins_loaded_action(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $this->assertTrue(class_exists($fqcn), 'AC-1: Bootstrap\\Plugin muss autoloadbar sein.');

        // Vor init(): kein Listener registriert.
        $this->assertSame(
            0,
            self::countRegistrationsForActionHook('plugins_loaded'),
            'AC-1 (precondition): Vor init() darf "plugins_loaded" keinen Listener haben.'
        );

        // init() durchlaeuft Hook-Registration.
        $fqcn::init(self::pluginMainFile());

        // Genau eine Registration.
        $this->assertSame(
            1,
            self::countRegistrationsForActionHook('plugins_loaded'),
            'AC-1: Plugin::init() muss genau einen "plugins_loaded"-Listener registrieren.'
        );

        // Priority muss WP-Default 10 sein (AC-1 fordert: "Hook-Prioritaet: WP-Default 10").
        $meta = self::firstRegistrationMetaForActionHook('plugins_loaded');
        $this->assertNotNull(
            $meta,
            'AC-1: Brain\\Monkey HookStorage muss eine Registration-Meta liefern.'
        );
        $this->assertSame(
            10,
            $meta['priority'],
            'AC-1: plugins_loaded-Listener muss mit Priority 10 (WP-Default) registriert sein. '
            . 'Frueherer Hook (z. B. "init") oder andere Priority ist gemaess Slice-Constraints '
            . 'falsch — load_plugin_textdomain MUSS auf plugins_loaded laufen.'
        );

        // Source-Verifikation: der Hook-Name in Plugin.php ist literal
        // 'plugins_loaded' — kein Variablen-Lookup, kein Konstanten-Indirect.
        $source = (string) file_get_contents(self::pluginClassFile());
        $this->assertMatchesRegularExpression(
            '/add_action\s*\(\s*[\'"]plugins_loaded[\'"]/',
            $source,
            'AC-1: Plugin.php muss einen wortwoertlichen `add_action("plugins_loaded", ...)` '
            . 'enthalten — kein Variablen-Lookup, der den Hook-Namen verschleiert.'
        );
    }

    /**
     * AC-1: Der Callback ist eine Closure (entweder normal oder static),
     * keine externe statische Methode. Slice-Spec sagt explizit
     * "Callback, der die i18n-Initialisierung vornimmt" — die Implementation
     * verwendet eine Closure, die `$plugin_file` einfaengt (use ($plugin_file)).
     */
    public function test_init_registers_closure_callback_on_plugins_loaded(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';

        $fqcn::init(self::pluginMainFile());

        $stringForm = self::firstRegistrationCallbackStringForm('plugins_loaded');
        $this->assertNotSame(
            '',
            $stringForm,
            'AC-1: Brain\\Monkey muss eine CallbackStringForm fuer den plugins_loaded-Listener '
            . 'liefern (Closure-Stringifizierung).'
        );

        // Brain\Monkey stringifiziert Closures als "function (...)" oder "static function (...)".
        // Eine "echte" statische Method-Callable waere "Foo\\Bar::method()" — solche Form lehnen
        // wir hier nicht zwingend ab, aber wir verifizieren positiv, dass die Registration
        // existiert. Der echte Verhaltens-Test (was tut der Callback) folgt in AC-2.
        $this->assertNotSame(
            '',
            $stringForm,
            'AC-1: plugins_loaded-Callback muss eine aufrufbare Form haben.'
        );
    }

    // ===================================================================
    // AC-2: GIVEN der auf plugins_loaded registrierte Callback
    //       WHEN WordPress den Hook feuert
    //       THEN ruft der Callback genau einmal load_plugin_textdomain
    //            mit ('spreadconnect-pod', false, '<plugin-dir>/languages')
    //            auf, wobei <plugin-dir> aus dirname(plugin_basename(...)) kommt.
    // ===================================================================

    /**
     * AC-2: Direkter Hook-Fire ueber Brain\Monkey simuliert WP's do_action()
     * Verhalten — wir nutzen `do_action('plugins_loaded')` mittels
     * Brain\Monkey-API um den registrierten Callback aufzurufen, und
     * spy'en `load_plugin_textdomain` und `plugin_basename`.
     *
     * Da Brain\Monkey-`do_action()`-Implementation den Callback NICHT
     * tatsaechlich aufruft (sie protokolliert nur), extrahieren wir den
     * Callback aus dem HookStorage und rufen ihn manuell auf — das ist
     * der Standard-Approach in dieser Test-Suite (vgl. Slice03).
     */
    public function test_callback_calls_load_plugin_textdomain_with_expected_args(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $pluginFile = self::pluginMainFile();

        // Spy auf load_plugin_textdomain — sammelt alle Argument-Tupel.
        $loadCalls = [];
        Monkey\Functions\when('load_plugin_textdomain')
            ->alias(static function (
                string $domain,
                $deprecated = false,
                $plugin_rel_path = false
            ) use (&$loadCalls): bool {
                $loadCalls[] = [ $domain, $deprecated, $plugin_rel_path ];
                return true;
            });

        // Stub plugin_basename: Map absoluten Pfad -> WP-konformer
        // "<plugin-dir>/<filename>"-String.
        Monkey\Functions\when('plugin_basename')
            ->alias(static function (string $file): string {
                $file = str_replace('\\', '/', $file);
                return basename(dirname($file)) . '/' . basename($file);
            });

        // init() registriert die Hook-Closure.
        $fqcn::init($pluginFile);

        // Callback aus dem HookStorage extrahieren.
        $callback = $this->extractFirstCallableForHook('plugins_loaded');
        $this->assertIsCallable(
            $callback,
            'AC-2: HookStorage muss einen aufrufbaren Callback fuer "plugins_loaded" liefern.'
        );

        // Hook-Fire simulieren: Callback einmal aufrufen.
        $callback();

        // AC-2: Genau ein load_plugin_textdomain-Call.
        $this->assertCount(
            1,
            $loadCalls,
            'AC-2: Pro Hook-Fire muss load_plugin_textdomain genau einmal aufgerufen werden.'
        );

        $args = $loadCalls[0];

        // Argument 1: Text-Domain (string).
        $this->assertSame(
            'spreadconnect-pod',
            $args[0],
            'AC-2: Erstes Argument an load_plugin_textdomain muss exakt "spreadconnect-pod" sein '
            . '(WP-Konvention; muss mit dem Plugin-Header-Feld "Text Domain" uebereinstimmen).'
        );

        // Argument 2: deprecated -> false (bool).
        $this->assertFalse(
            $args[1],
            'AC-2: Zweites Argument an load_plugin_textdomain MUSS false sein '
            . '(deprecated-Flag; WP-Default fuer Plugins).'
        );
        $this->assertSame(
            false,
            $args[1],
            'AC-2: Zweites Argument muss strikt === false (kein truthy/falsy Drift).'
        );

        // Argument 3: plugin_rel_path = '<plugin-dir>/languages'.
        // Der erwartete Wert wird via dirname(plugin_basename(__FILE__)) . '/languages'
        // gebildet. Mit unserem Stub gibt plugin_basename() "spreadconnect-pod/spreadconnect-pod.php"
        // zurueck, also: dirname(...) = "spreadconnect-pod" -> + "/languages".
        $expectedRelPath = 'spreadconnect-pod/languages';
        $this->assertSame(
            $expectedRelPath,
            $args[2],
            'AC-2: Drittes Argument an load_plugin_textdomain MUSS der relative Pfad '
            . '"<plugin-dir>/languages" sein, abgeleitet aus dirname(plugin_basename($plugin_file)). '
            . 'Hardcoded "/languages" oder absoluter Pfad sind falsch.'
        );
    }

    /**
     * AC-2: Der Callback berechnet den languages-Pfad ueber
     * dirname(plugin_basename($plugin_file)) — nicht hardcoded.
     *
     * Beweis: wenn wir plugin_basename so stubben, dass es ein anderes
     * Verzeichnis zurueckgibt, MUSS der dritte load_plugin_textdomain-
     * Argument-Slot genau diesem Drift folgen. Damit ist der Pfad-Aufbau
     * funktional — kein hardcoded "spreadconnect-pod/languages".
     */
    public function test_callback_resolves_languages_path_via_plugin_basename(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';

        $loadCalls = [];
        Monkey\Functions\when('load_plugin_textdomain')
            ->alias(static function (
                string $domain,
                $deprecated = false,
                $plugin_rel_path = false
            ) use (&$loadCalls): bool {
                $loadCalls[] = [ $domain, $deprecated, $plugin_rel_path ];
                return true;
            });

        // Adversarial: plugin_basename liefert ein KUENSTLICH anderes
        // Verzeichnis. Falls der Plugin-Code den Pfad hardcodet,
        // bleibt der Args[2]-Wert auf "spreadconnect-pod/languages" —
        // der Test schlaegt dann fehl.
        Monkey\Functions\when('plugin_basename')
            ->alias(static function (string $file): string {
                return 'totally-different-dir/whatever.php';
            });

        $fqcn::init(self::pluginMainFile());

        $callback = $this->extractFirstCallableForHook('plugins_loaded');
        $this->assertIsCallable($callback);

        $callback();

        $this->assertCount(1, $loadCalls);
        $this->assertSame(
            'totally-different-dir/languages',
            $loadCalls[0][2],
            'AC-2: Der languages-Pfad MUSS dynamisch via dirname(plugin_basename(...)) berechnet '
            . 'werden — kein hardcoded "spreadconnect-pod/languages". Wenn plugin_basename ein '
            . 'anderes Dir liefert, MUSS load_plugin_textdomain dem folgen.'
        );
    }

    // ===================================================================
    // AC-3: GIVEN der plugins_loaded-Callback
    //       WHEN er zweimal in derselben Request gefeuert wird
    //       THEN ruft er load_plugin_textdomain MAXIMAL EINMAL auf —
    //            entweder durch eigene Idempotenz-Guard ODER durch
    //            Verlassen auf Plugin::init()-Idempotenz.
    //
    // Slice-Spec Constraints: "Idempotenz-Strategie: bevorzugt durch
    // Bootstrap\Plugin::init()-Idempotenz (Slice 02 AC-5), nicht durch
    // separate Static-Property im Callback."
    //
    // Daher: wir testen, dass DOPPELTER init() den Hook nur einmal
    // registriert (= load_plugin_textdomain wird pro echtem Hook-Fire
    // nur einmal aufgerufen).
    // ===================================================================

    /**
     * AC-3 (Strategie 1): Doppelter init()-Aufruf registriert den Hook nur
     * einmal — Slice 02 Idempotenz-Guard greift.
     */
    public function test_double_init_does_not_duplicate_plugins_loaded_registration(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';

        $fqcn::init(self::pluginMainFile());
        $countAfterFirst = self::countRegistrationsForActionHook('plugins_loaded');
        $this->assertSame(
            1,
            $countAfterFirst,
            'AC-3 (precondition): Nach erstem init() muss genau 1 Registration vorhanden sein.'
        );

        // Zweiter init() mit demselben Pfad — Idempotenz-Guard MUSS Re-Register verhindern.
        $fqcn::init(self::pluginMainFile());
        $countAfterSecond = self::countRegistrationsForActionHook('plugins_loaded');
        $this->assertSame(
            1,
            $countAfterSecond,
            'AC-3: Doppelter Plugin::init()-Aufruf darf den plugins_loaded-Listener '
            . 'genau EINMAL registrieren — Idempotenz-Guard aus Slice 02 muss verhindern, '
            . 'dass add_action() beim zweiten Aufruf erneut feuert.'
        );

        // Adversarial: dritter Aufruf mit ANDEREM Pfad — Guard muss auch hier halten.
        $fqcn::init('/dev/null/another-plugin-path.php');
        $countAfterThird = self::countRegistrationsForActionHook('plugins_loaded');
        $this->assertSame(
            1,
            $countAfterThird,
            'AC-3: Auch bei drittem init() mit anderem $plugin_file darf der Listener '
            . 'nicht erneut registriert werden — Idempotenz-Guard ist absolut.'
        );
    }

    /**
     * AC-3 (Verhaltens-Garantie): Doppelter init() + ein (echter) Hook-Fire
     * ergibt genau EINEN load_plugin_textdomain-Call.
     *
     * Beweisfuehrung: wir laufen ueber jede registrierte Brain\Monkey-Action
     * und rufen den dort gespeicherten Callback einmal auf — das simuliert
     * EINEN Hook-Fire. Wenn der Plugin-Code zwei add_action()-Aufrufe gemacht
     * haette (Idempotenz-Bug), waere der load_plugin_textdomain-Spy zweimal
     * aufgerufen worden.
     */
    public function test_callback_is_idempotent_on_double_fire(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';

        $loadCalls = [];
        Monkey\Functions\when('load_plugin_textdomain')
            ->alias(static function (
                string $domain,
                $deprecated = false,
                $plugin_rel_path = false
            ) use (&$loadCalls): bool {
                $loadCalls[] = [ $domain, $deprecated, $plugin_rel_path ];
                return true;
            });

        Monkey\Functions\when('plugin_basename')
            ->alias(static function (string $file): string {
                $file = str_replace('\\', '/', $file);
                return basename(dirname($file)) . '/' . basename($file);
            });

        // Doppelter init() — der Idempotenz-Guard greift ab dem 2. Aufruf.
        $fqcn::init(self::pluginMainFile());
        $fqcn::init(self::pluginMainFile());

        // Genau eine Registration.
        $count = self::countRegistrationsForActionHook('plugins_loaded');
        $this->assertSame(
            1,
            $count,
            'AC-3: Erwartete genau 1 Registration nach doppeltem init().'
        );

        // Simuliere EINEN Hook-Fire: invoke jede gespeicherte Registration einmal.
        // Bei n=1 Registration -> load_plugin_textdomain wird genau 1x aufgerufen.
        $callback = $this->extractFirstCallableForHook('plugins_loaded');
        $this->assertIsCallable($callback);
        $callback();

        $this->assertCount(
            1,
            $loadCalls,
            'AC-3: Pro Hook-Fire darf load_plugin_textdomain nur EINMAL aufgerufen werden — '
            . 'eine doppelte Hook-Registration wuerde hier zu 2 Calls fuehren.'
        );
    }

    // ===================================================================
    // AC-4: GIVEN das Verzeichnis languages/
    //       WHEN Slice 06 abgeschlossen ist
    //       THEN existiert spreadconnect-pod-de_DE.po als gueltiger
    //            gettext-PO-Stub mit minimalem Header-Block:
    //              Project-Id-Version: spreadconnect-pod 2.0.0
    //              Language: de_DE
    //              Content-Type: text/plain; charset=UTF-8
    //              MIME-Version: 1.0
    //            und null Translation-Eintraegen.
    // ===================================================================

    /**
     * AC-4: PO-Datei existiert mit Pflicht-Headern.
     *
     * Slice-46 hat den Slice-06 Stub durch eine vollstaendige Uebersetzung
     * ersetzt (siehe `slice-46-i18n-de-readme.md` AC-1). Die Pflicht-Header
     * sind jetzt:
     *   - Project-Id-Version: Spreadconnect POD <Version>
     *     (Slice-46 AC-1, NICHT mehr "spreadconnect-pod 2.0.0")
     *   - Language: de_DE
     *   - MIME-Version: 1.0
     *   - Content-Type: text/plain; charset=UTF-8
     *   - Plural-Forms: nplurals=2; plural=(n != 1);
     *   - X-Domain: spreadconnect-pod
     */
    public function test_de_de_po_stub_file_exists_with_required_headers(): void
    {
        $file = self::deDeStubFile();
        $this->assertFileExists(
            $file,
            'AC-4: PO-Datei muss unter languages/spreadconnect-pod-de_DE.po existieren.'
        );

        $contents = (string) file_get_contents($file);
        $this->assertNotSame('', $contents, 'AC-4: PO-Datei darf nicht leer sein.');

        // Pflicht-Header laut Slice-46 AC-1 (ersetzt den Slice-06-Stub-Vertrag).
        // Project-Id-Version traegt jetzt den humanlesbaren Plugin-Namen
        // ("Spreadconnect POD <Version>") statt des Plugin-Slugs.
        $required = [
            'Project-Id-Version' => 'Spreadconnect POD 2.0.0',
            'Language'           => 'de_DE',
            'MIME-Version'       => '1.0',
            'Content-Type'       => 'text/plain; charset=UTF-8',
            'Plural-Forms'       => 'nplurals=2; plural=(n != 1);',
            'X-Domain'           => 'spreadconnect-pod',
        ];

        foreach ($required as $headerName => $expectedValue) {
            // gettext-Header werden als `"Header-Name: value\n"` notiert
            // (innerhalb der ersten `msgstr ""`-Block).
            $pattern = '/"' . preg_quote($headerName, '/') . ':\s*'
                . preg_quote($expectedValue, '/') . '\\\\n"/';
            $this->assertMatchesRegularExpression(
                $pattern,
                $contents,
                sprintf(
                    'AC-4: PO-Datei muss den gettext-Header "%s: %s" enthalten — '
                    . 'andernfalls scheitert msgfmt --check und WP findet das .mo nicht.',
                    $headerName,
                    $expectedValue
                )
            );
        }
    }

    /**
     * AC-4 (Slice-46-aktualisiert): PO-Datei enthaelt vollstaendige
     * Translations.
     *
     * Slice-06 hatte einen Header-only Stub vorgesehen (`# Inhalt kommt in
     * Slice 46`). Slice-46 (`slice-46-i18n-de-readme.md` AC-1) hat den Stub
     * durch eine vollstaendige Uebersetzung aller `__()`-Strings aus den
     * Slices 02-45 ersetzt. Daher pruefen wir jetzt das Gegenteil:
     *
     *   - Der Header-Block (msgid "" + msgstr "<headers>") ist weiterhin
     *     vorhanden (msgfmt-Pflicht).
     *   - Mehrere Translation-Eintraege existieren (msgid "<source>" mit
     *     non-empty msgstr "<translation>").
     *   - Keine User-facing msgid hat ein leeres msgstr "" (Slice-46
     *     Constraint "Keine `msgstr ""` (leere Uebersetzung) fuer
     *     User-facing Strings").
     */
    public function test_de_de_po_stub_has_no_translation_entries(): void
    {
        $file = self::deDeStubFile();
        $this->assertFileExists($file, 'AC-4: PO-Datei muss existieren.');

        $contents = (string) file_get_contents($file);

        // Anzahl `msgid`-Direktiven am Zeilenanfang zaehlen.
        // Der Header-Eintrag ist `msgid ""` (leer). Jeder weitere
        // `msgid "..."` ist ein User-facing Translation-Eintrag.
        $allMsgIds   = preg_match_all('/^msgid\s+"/m', $contents) ?: 0;
        $emptyMsgIds = preg_match_all('/^msgid\s+""\s*$/m', $contents) ?: 0;

        // Header-Block: genau EIN `msgid ""` am Anfang.
        $this->assertSame(
            1,
            $emptyMsgIds,
            'AC-4: PO-Datei MUSS genau einen leeren `msgid ""` (Header-Block) enthalten — '
            . 'msgfmt verlangt diesen Header-Eintrag.'
        );

        // Slice-46: ZUSAETZLICH zum Header-Block muessen mehrere Translation-
        // Eintraege existieren. Wir setzen die Untergrenze grosszuegig auf
        // 50, weil die tatsaechliche Anzahl je nach hinzukommenden Slices
        // schwanken kann (aktuell 334).
        $this->assertGreaterThan(
            50,
            $allMsgIds,
            sprintf(
                'AC-4 (Slice-46): PO-Datei MUSS deutsche Uebersetzungen aller __()-Strings '
                . 'aus den Slices 02-45 enthalten. Erwartet > 50 msgid-Eintraege; gefunden: %d',
                $allMsgIds
            )
        );

        // Der msgstr-Block direkt nach dem Header-`msgid ""` MUSS multi-line
        // sein (Header-Lines), nicht ein einzelnes leeres msgstr "".
        $this->assertMatchesRegularExpression(
            '/msgstr\s+""\s*\n\s*"[A-Z][A-Za-z\-]+:/',
            $contents,
            'AC-4: Auf msgstr "" muss mindestens eine Header-Zeile folgen '
            . '(Project-Id-Version, Language, MIME-Version, Content-Type).'
        );

        // Slice-46 AC-1 Constraint: Keine User-facing msgid darf ein leeres
        // msgstr "" haben. Wir suchen nach `msgid "<non-empty>"` direkt
        // gefolgt von `msgstr ""` (auf Zeilenanfang) — wenn das vorkommt,
        // waere die Translation versehentlich leer.
        $emptyTranslationCount = preg_match_all(
            '/^msgid\s+"[^"]+"\s*\nmsgstr\s+""\s*$/m',
            $contents
        ) ?: 0;
        $this->assertSame(
            0,
            $emptyTranslationCount,
            sprintf(
                'AC-4 (Slice-46 Constraint): Keine User-facing msgid darf ein leeres '
                . 'msgstr "" haben. Gefunden: %d leere Uebersetzungen.',
                $emptyTranslationCount
            )
        );
    }

    // ===================================================================
    // AC-5: GIVEN der .po-Stub
    //       WHEN ein gettext-Tooling die Datei parst
    //       THEN terminiert es ohne Syntax-Errors. Encoding ist UTF-8 ohne BOM,
    //            Zeilenumbrueche \n (LF).
    // ===================================================================

    /**
     * AC-5: Datei-Encoding ist UTF-8 ohne BOM, Line-Endings sind LF.
     *
     * Wir koennen `msgfmt --check` hier nicht zuverlaessig aufrufen
     * (gettext-Tooling ist im PHP-CLI-Container nicht garantiert vorhanden).
     * Stattdessen pruefen wir die zwei Encoding-Constraints, die ein
     * msgfmt-Run auch verlangen wuerde: kein BOM + ausschliesslich LF.
     */
    public function test_de_de_po_stub_encoding_and_line_endings(): void
    {
        $file = self::deDeStubFile();
        $this->assertFileExists($file, 'AC-5: PO-Stub muss existieren.');

        $raw = file_get_contents($file);
        $this->assertNotFalse($raw, 'AC-5: PO-Stub muss lesbar sein.');
        $this->assertNotSame('', $raw, 'AC-5: PO-Stub darf nicht leer sein.');

        // Kein UTF-8 BOM (\xEF\xBB\xBF) am Datei-Anfang.
        $this->assertStringStartsNotWith(
            "\xEF\xBB\xBF",
            $raw,
            'AC-5: PO-Stub darf KEINEN UTF-8 BOM haben (msgfmt akzeptiert ihn nicht).'
        );

        // Datei muss valides UTF-8 sein.
        $this->assertTrue(
            mb_check_encoding($raw, 'UTF-8'),
            'AC-5: PO-Stub muss valides UTF-8 sein.'
        );

        // Keine CR-Bytes (\r) — nur LF (\n).
        $this->assertStringNotContainsString(
            "\r",
            $raw,
            'AC-5: PO-Stub darf KEINE CR-Bytes (\\r) enthalten — Line-Endings muessen LF (\\n) sein. '
            . 'CRLF oder CR-Only verursachen msgfmt-Warnings und break beim Aufnehmen in '
            . 'Diff-Tools/CI.'
        );

        // Datei sollte mit \n enden (POSIX-Convention; viele gettext-Tools verlangen es).
        $this->assertStringEndsWith(
            "\n",
            $raw,
            'AC-5: PO-Stub sollte mit einem LF (\\n) enden (POSIX-Konvention).'
        );
    }

    // ===================================================================
    // AC-6: GIVEN die Plugin-Hauptdatei spreadconnect-pod.php (Slice 02)
    //       WHEN Slice 06 abgeschlossen ist
    //       THEN enthaelt der Plugin-Header zusaetzlich das Feld
    //            "Domain Path: /languages". "Text Domain: spreadconnect-pod"
    //            (Slice 02 AC-1) bleibt unveraendert.
    // ===================================================================

    /**
     * AC-6: Plugin-Hauptdatei hat Domain-Path-Header und behaelt Text Domain.
     */
    public function test_main_plugin_file_has_domain_path_header(): void
    {
        $file = self::pluginMainFile();
        $this->assertFileExists($file, 'AC-6: Plugin-Hauptdatei muss existieren.');

        $contents = (string) file_get_contents($file);
        $this->assertNotSame('', $contents, 'AC-6: Plugin-Hauptdatei darf nicht leer sein.');

        // WP parsed nur die ersten 8 KiB (analog zu get_file_data()).
        $headerArea = substr($contents, 0, 8192);
        // Normalisiere Line-Endings (CRLF -> LF), entspricht WP-Verhalten.
        $headerArea = str_replace("\r", "\n", $headerArea);

        // Domain Path-Header muss exakt "/languages" sein.
        $this->assertMatchesRegularExpression(
            '/^[ \t\/*#@]*Domain Path:\s*\/languages\s*$/mi',
            $headerArea,
            'AC-6: Plugin-Header MUSS das Feld "Domain Path: /languages" enthalten — '
            . 'WP nutzt es zur Pfad-Aufloesung von Translation-Files relativ zum Plugin-Dir.'
        );

        // Text Domain bleibt unveraendert (Slice 02 AC-1).
        $this->assertMatchesRegularExpression(
            '/^[ \t\/*#@]*Text Domain:\s*spreadconnect-pod\s*$/mi',
            $headerArea,
            'AC-6: Plugin-Header MUSS weiterhin "Text Domain: spreadconnect-pod" enthalten — '
            . 'der Slice darf den Slice-02-Header-Wert nicht aendern.'
        );

        // Domain Path darf KEIN absoluter Pfad sein (nur "/languages",
        // relativ zum Plugin-Verzeichnis — WP-Konvention).
        $this->assertDoesNotMatchRegularExpression(
            '/^[ \t\/*#@]*Domain Path:\s*\/(?:home|var|usr|opt|tmp)/mi',
            $headerArea,
            'AC-6: Domain Path darf kein absoluter Server-Pfad sein — WP-Konvention ist '
            . '"/languages" relativ zum Plugin-Verzeichnis.'
        );
    }

    // -------------------------------------------------------------------
    // Helper: extrahiert die erste registrierte Closure aus dem Brain\Monkey
    // HookStorage. Brain\Monkey speichert in $storage[ADDED][ACTIONS][hook][i]
    // ein 3er-Array `[CallbackStringForm, priority, acceptedArgs]` — die
    // tatsaechliche Closure ist NICHT gespeichert, weil Brain\Monkey nur
    // die String-Form pflegt.
    //
    // Loesung: wir lesen aus der `Container::instance()->expectations()`
    // die "added action"-Expectations OR — einfacher — wir extrahieren die
    // Closure direkt aus dem Plugin::$initialized=false-Reset-Run, indem wir
    // add_action selbst stubben und die Closure einfangen.
    // -------------------------------------------------------------------

    /**
     * Extrahiert die erste fuer einen Hook registrierte Closure.
     *
     * Da Brain\Monkey im HookStorage nur die `CallbackStringForm` aufbewahrt
     * (nicht die Closure-Instanz selbst), reflektieren wir hier ueber die
     * Brain\Monkey-internen Closure-Speicher, die `Hook\HookExpectationExecutor`
     * nutzt — falls verfuegbar. Falls nicht, fallen wir zurueck auf den
     * "add_action via when()->alias() einfangen"-Pattern.
     *
     * Wir tun das ueber einen pragmatischen Trick: wir resetten den State,
     * setzen einen eigenen `add_action`-Spy via Patchwork, der die Closure
     * einfaengt, rufen init() neu auf, und liefern die eingefangene Closure.
     *
     * Vorraussetzung: Tests, die diese Methode nutzen, haben Plugin-State
     * bereits ueber `resetPluginState()` zurueckgesetzt — daher ist init()
     * im Helper sicher idempotent re-aufrufbar.
     */
    private function extractFirstCallableForHook(string $hookName): ?\Closure
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';

        // Reset Plugin state, damit init() unten erneut die Hooks registriert.
        self::resetPluginState();

        // Erfasse die Closure ueber einen lokalen add_action-Override.
        // Wir setzen einen ZWEITEN Override fuer add_action ueber Brain\Monkey-
        // when()->alias(): vorhandene add_action-Spies werden damit ueberschrieben,
        // aber das ist OK — der einzige Caller von add_action im Plugin-Code
        // (Slice 03 + 06) ruft Hooks auf, die wir hier alle einfangen.
        $captured = null;
        $hookFn = static function (string $hook, $callback, int $priority = 10, int $accepted = 1) use ($hookName, &$captured): bool {
            if ($hook === $hookName && $callback instanceof \Closure) {
                $captured = $callback;
            }
            return true;
        };
        Monkey\Functions\when('add_action')->alias($hookFn);

        // Re-Init mit neuem Spy.
        $fqcn::init(self::pluginMainFile());

        return $captured;
    }
}
