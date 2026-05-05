<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SpreadconnectPod\Hub\Assets;

/**
 * Slice css-hub-layout — CSS Hub Layout + Enqueue Adapter
 *
 * Acceptance Tests gegen die Slice-Spec
 * `slice-css-hub-layout` (slim-spec.md, Section "Slice css-hub-layout").
 *
 * Mocking Strategy: `mock_external` (laut Slice-Spec):
 *   - Brain\Monkey fuer `add_action` (via `\Brain\Monkey\Actions\has()` Spy).
 *   - Brain\Monkey fuer `wp_enqueue_style` — Spy via Functions\when()->alias()
 *     der jeden Aufruf in einer statischen Capture-Liste sammelt.
 *   - Brain\Monkey fuer `plugins_url` und `plugin_dir_path` — Aliases mit
 *     deterministischer URL/Path-Konstruktion.
 *
 * Strategie:
 *   - AC-1: Filesystem-Read der CSS-Datei + Mindestgroesse + Brace-Balance-Check.
 *   - AC-2: `Plugin::init( $plugin_file )` ausfuehren -> `Actions\has()` checken.
 *   - AC-3: `Hub\Assets::enqueue('woocommerce_page_spreadconnect')` ausfuehren,
 *     verifizieren dass `wp_enqueue_style` GENAU einmal mit Handle
 *     `'spreadconnect-hub'` und korrekter Source-URL aufgerufen wird.
 *   - AC-4: `Hub\Assets::enqueue($x)` fuer 5 verschiedene andere Hook-Suffixe
 *     ausfuehren, verifizieren dass `wp_enqueue_style` NIE aufgerufen wird.
 *   - AC-5: `wp_enqueue_style`-Capture extrahiert das `$ver`-Argument; pruefen
 *     auf nicht-leeren String aus Ziffern (filemtime → cast).
 *   - AC-6: CSS-Inhalt einlesen, fuer jeden BEM-Hook ein Selector-Regex pruefen.
 *   - AC-7: CSS-Inhalt einlesen, mit Multiline-Regex pruefen dass
 *     verbotene WP-Core-Selektoren NICHT als top-level (un-skoped) Regel
 *     vorkommen.
 *   - AC-8: CSS-Inhalt einlesen, `@media (max-width: 782px)`-Block extrahieren
 *     und auf Vorhandensein von `.spreadconnect-hub` + `.spreadconnect-hub-nav`
 *     Regeln im Block-Body pruefen.
 */
final class SliceCssHubLayoutTest extends TestCase
{
    /**
     * Repo-Root: drei Verzeichnisse oberhalb von `tests/slices/pod-shop-mvp/`.
     */
    private static function repoRoot(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }

    /**
     * Absoluter Pfad zur CSS-Datei.
     */
    private static function cssFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/assets/css/spreadconnect-hub.css';
    }

    /**
     * Absoluter Pfad zur Plugin-Hauptdatei (fuer plugins_url() / plugin_dir_path()).
     */
    private static function pluginMainFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php';
    }

    /**
     * Cached file-read so wiederholtes Einlesen in mehreren ACs guenstig bleibt.
     */
    private static ?string $cssContents = null;

    private static function readCss(): string
    {
        if (self::$cssContents === null) {
            $file = self::cssFile();
            self::assertFileExists($file, 'CSS-Datei muss existieren: ' . $file);
            $contents = file_get_contents($file);
            self::assertNotFalse($contents, 'file_get_contents darf nicht false liefern: ' . $file);
            self::$cssContents = (string) $contents;
        }
        return self::$cssContents;
    }

    /**
     * Spy-Liste fuer wp_enqueue_style-Aufrufe — pro Test ueber resetCaptures()
     * geleert. Brain\Monkey-Aliases referenzieren diese statische Property.
     *
     * @var list<array{handle: string, src: string, deps: array<int,string>, ver: mixed, media: mixed}>
     */
    private static array $enqueueCaptures = [];

    private static function resetCaptures(): void
    {
        self::$enqueueCaptures = [];
    }

    /**
     * Reset Plugin-internen State (Idempotenz-Guard) zwischen Tests, damit
     * jeder init()-Aufruf den vollstaendigen Hook-Block durchlaeuft.
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

        // Hub\Assets is now stateless — the plugin-file path is resolved
        // internally via dirname(__DIR__, 2). The hasProperty() guard below
        // gracefully no-ops if a future refactor reintroduces a static
        // property of the same name.
        $assetsFqcn = Assets::class;
        if (class_exists($assetsFqcn)) {
            $assetsRefl = new ReflectionClass($assetsFqcn);
            if ($assetsRefl->hasProperty('pluginFile')) {
                $prop = $assetsRefl->getProperty('pluginFile');
                $prop->setValue(null, '');
            }
        }
    }

    /**
     * Brain\Monkey-Stub fuer `wp_enqueue_style` — sammelt jeden Aufruf in
     * `self::$enqueueCaptures`.
     */
    private static function stubEnqueueSpy(): void
    {
        Monkey\Functions\when('wp_enqueue_style')->alias(
            function ($handle, $src = '', $deps = [], $ver = false, $media = 'all') {
                self::$enqueueCaptures[] = [
                    'handle' => (string) $handle,
                    'src'    => (string) $src,
                    'deps'   => is_array($deps) ? $deps : [],
                    'ver'    => $ver,
                    'media'  => $media,
                ];
            }
        );
    }

    /**
     * Brain\Monkey-Stub fuer `plugins_url` — liefert eine deterministische
     * URL, die mit `assets/css/spreadconnect-hub.css` endet.
     */
    private static function stubPluginsUrl(): void
    {
        Monkey\Functions\when('plugins_url')->alias(
            function ($path = '', $plugin = '') {
                return 'http://example.test/wp-content/plugins/spreadconnect-pod/' . ltrim((string) $path, '/');
            }
        );
    }

    /**
     * Brain\Monkey-Stub fuer `plugin_dir_path` — liefert das Verzeichnis
     * der uebergebenen Datei (mit trailing slash). Adapter nutzt das fuer
     * den absoluten CSS-Pfad zum filemtime()-Aufruf.
     */
    private static function stubPluginDirPath(): void
    {
        Monkey\Functions\when('plugin_dir_path')->alias(
            function ($file) {
                return dirname((string) $file) . '/';
            }
        );
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        self::resetCaptures();
        self::resetPluginState();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        self::resetPluginState();
        parent::tearDown();
    }

    // =====================================================================
    // AC-1: CSS-Datei existiert, ist > 500 Bytes, parsed ohne Brace-Imbalance
    // =====================================================================

    /**
     * AC-1: GIVEN the plugin is installed at `wordpress/plugins/spreadconnect-pod/`
     *       WHEN a developer or test inspects the assets directory
     *       THEN the file `wordpress/plugins/spreadconnect-pod/assets/css/spreadconnect-hub.css`
     *            exists, is non-empty (> 500 bytes), and parses without syntax
     *            errors when validated via brace-balance check.
     */
    public function test_css_file_exists_and_is_valid(): void
    {
        $file = self::cssFile();

        // Existenz.
        $this->assertFileExists(
            $file,
            'AC-1: Stylesheet MUSS existieren unter ' . $file
        );

        // Minimalgroesse.
        $size = filesize($file);
        $this->assertNotFalse($size, 'AC-1: filesize() darf nicht false liefern.');
        $this->assertGreaterThan(
            500,
            $size,
            'AC-1: Stylesheet MUSS > 500 Bytes gross sein (gemessen: '
            . (int) $size . ' Bytes).'
        );

        // Parser-Sanity: Brace-Balance. CSS hat keine Stringliterale die
        // Klammern enthalten koennten (mit ein paar Ausnahmen wie content:
        // "{"; — die fuer den Hub nicht relevant sind), also reicht ein
        // simpler Klammer-Zaehler. Wir entfernen Kommentare vorab, damit
        // ein '/* { */' nicht den Counter verfaelscht.
        $contents     = self::readCss();
        $stripped     = (string) preg_replace('!/\*.*?\*/!s', '', $contents);
        $openCount    = substr_count($stripped, '{');
        $closeCount   = substr_count($stripped, '}');

        $this->assertSame(
            $openCount,
            $closeCount,
            sprintf(
                'AC-1: CSS muss balancierte Klammern haben (parse-bar). '
                . 'Gefunden: { x %d, } x %d.',
                $openCount,
                $closeCount
            )
        );

        $this->assertGreaterThan(
            0,
            $openCount,
            'AC-1: CSS MUSS mindestens einen Regel-Block enthalten (Brace-Count > 0).'
        );

        // file_get_contents lieferte einen non-false String mit Laenge > 500.
        $this->assertGreaterThan(
            500,
            strlen($contents),
            'AC-1: file_get_contents() MUSS einen String der Laenge > 500 liefern.'
        );
    }

    // =====================================================================
    // AC-2: add_action('admin_enqueue_scripts', [Assets::class, 'enqueue']) @ prio 10
    // =====================================================================

    /**
     * AC-2: GIVEN `Bootstrap\Plugin::init( $plugin_file )` has executed
     *       WHEN the test calls
     *            `has_action( 'admin_enqueue_scripts', [Hub\Assets::class, 'enqueue'] )`
     *       THEN the return value is the integer `10` (default WP priority),
     *            proving the adapter is hooked exactly once from
     *            `Bootstrap\Plugin::init()`.
     */
    public function test_assets_enqueue_hook_registered_in_plugin_init(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $this->assertTrue(
            class_exists($fqcn),
            'AC-2: Bootstrap\\Plugin MUSS autoloadbar sein.'
        );

        // Vor dem init() darf der Listener noch nicht registriert sein.
        $this->assertFalse(
            Actions\has('admin_enqueue_scripts', [Assets::class, 'enqueue']),
            'AC-2 (precondition): Vor Plugin::init() darf der admin_enqueue_scripts-'
            . 'Listener fuer Hub\\Assets::enqueue NICHT registriert sein.'
        );

        // init() durchlaeuft den vollstaendigen Hook-Registration-Block.
        // Slice-13 / slice-css-hub-layout fuegen mehrere admin_enqueue_scripts-
        // Listener hinzu (InlineProductMetaBox, InlineOrderMetaBox,
        // InlineOrderListColumns, HubAssets) — wir interessieren uns nur
        // fuer den HubAssets-Eintrag.
        $fqcn::init(self::pluginMainFile());

        $priority = Actions\has('admin_enqueue_scripts', [Assets::class, 'enqueue']);

        $this->assertNotFalse(
            $priority,
            'AC-2: Plugin::init() MUSS einen admin_enqueue_scripts-Listener fuer '
            . '[Hub\\Assets::class, "enqueue"] registrieren.'
        );

        $this->assertSame(
            10,
            $priority,
            'AC-2: Listener MUSS mit Default-WP-Priority 10 registriert sein '
            . '(gefunden: ' . var_export($priority, true) . ').'
        );
    }

    // =====================================================================
    // AC-3: Screen-gating positive — wp_enqueue_style wird auf Hub-Page aufgerufen
    // =====================================================================

    /**
     * AC-3: GIVEN the current admin screen has
     *            `id === 'woocommerce_page_spreadconnect'` (i.e. the
     *            `$hook_suffix` argument equals `'woocommerce_page_spreadconnect'`)
     *       WHEN `\SpreadconnectPod\Hub\Assets::enqueue( 'woocommerce_page_spreadconnect' )` runs
     *       THEN exactly one `wp_enqueue_style()` call is recorded with handle
     *            `'spreadconnect-hub'` and a source URL ending in
     *            `assets/css/spreadconnect-hub.css`.
     */
    public function test_enqueue_on_hub_page_calls_wp_enqueue_style(): void
    {
        self::stubEnqueueSpy();
        self::stubPluginsUrl();
        self::stubPluginDirPath();

        // Adapter resolves its own plugin-file path via
        // dirname(__DIR__, 2) — no injector required.
        Assets::enqueue('woocommerce_page_spreadconnect');

        $this->assertCount(
            1,
            self::$enqueueCaptures,
            'AC-3: wp_enqueue_style MUSS GENAU einmal aufgerufen werden, '
            . 'aber wurde ' . count(self::$enqueueCaptures) . ' Mal aufgerufen.'
        );

        $call = self::$enqueueCaptures[0];

        $this->assertSame(
            'spreadconnect-hub',
            $call['handle'],
            'AC-3: wp_enqueue_style-Handle MUSS exakt "spreadconnect-hub" sein.'
        );

        $this->assertStringEndsWith(
            'assets/css/spreadconnect-hub.css',
            $call['src'],
            'AC-3: Source-URL MUSS auf "assets/css/spreadconnect-hub.css" enden '
            . '(gefunden: ' . $call['src'] . ').'
        );
    }

    // =====================================================================
    // AC-4: Screen-gating negative — wp_enqueue_style NICHT auf anderen Pages
    // =====================================================================

    /**
     * AC-4: GIVEN the current admin screen is anything other than the hub
     *            page (e.g. `'index.php'`, `'edit.php'`,
     *            `'woocommerce_page_wc-orders'`, `'plugins.php'`,
     *            `'post.php'`)
     *       WHEN `\SpreadconnectPod\Hub\Assets::enqueue( $hook_suffix )` runs
     *            for each of those values
     *       THEN zero `wp_enqueue_style()` calls are recorded — the adapter
     *            must early-return on any hook suffix that is not exactly
     *            `'woocommerce_page_spreadconnect'`.
     */
    public function test_enqueue_no_op_on_other_pages(): void
    {
        self::stubEnqueueSpy();
        self::stubPluginsUrl();
        self::stubPluginDirPath();

        // Adapter resolves its own plugin-file path internally — if it
        // failed to screen-gate it would call wp_enqueue_style for every
        // foreign hook suffix below.

        $foreignHookSuffixes = [
            'index.php',
            'edit.php',
            'plugins.php',
            'post.php',
            'woocommerce_page_wc-orders',
            // Adversarial: aehnlich klingende, aber NICHT-exakte Matches —
            // Adapter darf NICHT auf String-Substrings matchen.
            'woocommerce_page_spreadconnect_extra',
            'spreadconnect',
            'WOOCOMMERCE_PAGE_SPREADCONNECT', // Case-Mismatch
        ];

        foreach ($foreignHookSuffixes as $hookSuffix) {
            Assets::enqueue($hookSuffix);
        }

        $this->assertCount(
            0,
            self::$enqueueCaptures,
            sprintf(
                'AC-4: wp_enqueue_style DARF auf fremden Hook-Suffixes NICHT '
                . 'aufgerufen werden. Aufrufe gefunden (%d): %s',
                count(self::$enqueueCaptures),
                json_encode(self::$enqueueCaptures, JSON_UNESCAPED_SLASHES)
            )
        );
    }

    // =====================================================================
    // AC-5: Cache-Buster ($ver-Arg ist non-empty filemtime-string)
    // =====================================================================

    /**
     * AC-5: GIVEN the CSS file exists on disk with a known mtime
     *       WHEN `wp_enqueue_style()` is invoked by `Hub\Assets::enqueue` on
     *            the hub page
     *       THEN the 4th argument (`$ver`) is a non-empty string equal to either
     *            `(string) filemtime( $css_path )` OR a plugin-version constant;
     *            it is NEVER `false`, `null`, the empty string, or the WP default.
     */
    public function test_enqueue_uses_filemtime_as_version(): void
    {
        self::stubEnqueueSpy();
        self::stubPluginsUrl();
        self::stubPluginDirPath();

        Assets::enqueue('woocommerce_page_spreadconnect');

        $this->assertCount(
            1,
            self::$enqueueCaptures,
            'AC-5 (precondition): wp_enqueue_style muss genau einmal aufgerufen worden sein.'
        );

        $ver = self::$enqueueCaptures[0]['ver'];

        // Verboten: false, null, leer.
        $this->assertNotFalse(
            $ver,
            'AC-5: $ver MUSS gesetzt sein, NIE false (false suppress-WP-default-version).'
        );
        $this->assertNotNull(
            $ver,
            'AC-5: $ver MUSS gesetzt sein, NIE null.'
        );
        $this->assertIsString(
            $ver,
            'AC-5: $ver MUSS ein String sein (filemtime() casted oder Version-Konstante).'
        );
        $this->assertNotSame(
            '',
            $ver,
            'AC-5: $ver DARF NICHT der leere String sein.'
        );

        // Erwartetes Format: rein numerischer String (filemtime → cast).
        // Akzeptiert wird ALTERNATIV ein semver-aehnlicher String (Plugin-
        // Version-Konstante, z.B. "2.0.0"). Beides erfuellt AC-5.
        $isFilemtime = (bool) preg_match('/^\d+$/', (string) $ver);
        $isVersion   = (bool) preg_match('/^\d+\.\d+(?:\.\d+)?(?:-[\w.]+)?$/', (string) $ver);

        $this->assertTrue(
            $isFilemtime || $isVersion,
            sprintf(
                'AC-5: $ver MUSS entweder ein filemtime-String (rein numerisch) '
                . 'ODER eine Plugin-Version (semver-aehnlich) sein. '
                . 'Gefunden: "%s".',
                (string) $ver
            )
        );

        // Wenn $ver ein filemtime ist, MUSS er positiv sein (nicht "0",
        // sonst waere der Cache-Buster nutzlos — eine "0"-Version wuerde
        // bei jedem Plugin-Update reset werden).
        if ($isFilemtime) {
            $this->assertGreaterThan(
                0,
                (int) $ver,
                'AC-5: filemtime-Cache-Buster MUSS > 0 sein (nicht "0" — '
                . 'sonst wuerde die CSS-Datei fehlen oder unlesbar sein).'
            );
        }
    }

    // =====================================================================
    // AC-6: BEM-Class-Coverage im Stylesheet
    // =====================================================================

    /**
     * AC-6: GIVEN the stylesheet at `assets/css/spreadconnect-hub.css`
     *       WHEN the test reads the file with `file_get_contents()` and
     *            searches for selector substrings
     *       THEN the file contains rule blocks for ALL of the BEM hooks
     *            already emitted by the Hub views (see slim-spec AC-6).
     */
    public function test_css_contains_required_bem_hooks(): void
    {
        $css = self::readCss();

        // Alle BEM-Klassen aus AC-6. Pro Klasse pruefen wir, dass sie als
        // Selektor in einem Regelblock vorkommt — d.h. ein Match auf die
        // Klasse, gefolgt (irgendwo) von einer oeffnenden `{`-Klammer im
        // selben Selektor-Statement. Wir nehmen einen vereinfachten
        // Vorkommens-Check: die Klasse MUSS irgendwo im File vorhanden sein
        // (substring), UND ein Regelblock MUSS sie als Selektor referenzieren.
        $required = [
            '.spreadconnect-hub',
            '.spreadconnect-hub__content',
            '.spreadconnect-hub__title',
            '.spreadconnect-hub-nav',
            '.spreadconnect-hub-nav__list',
            '.spreadconnect-hub-nav__item',
            '.spreadconnect-hub-nav__item.is-active',
            '.spreadconnect-hub-nav__link',
            '.spreadconnect-card',
            '.spreadconnect-card__title',
            '.spreadconnect-card__status',
            '.spreadconnect-dashboard__cards',
            '.spreadconnect-catalog__progress-bar',
            '.spreadconnect-webhook-log__hmac.is-valid',
            '.spreadconnect-webhook-log__hmac.is-invalid',
            '.spreadconnect-failed-ops__modal',
            '.spreadconnect-failed-ops__modal-backdrop',
            '.spreadconnect-logs__filters',
            '.spreadconnect-subscriptions__error-banner',
            '.spreadconnect-step-result--success',
            '.spreadconnect-step-result--partial',
            '.spreadconnect-reveal-panel',
            '.spreadconnect-reveal-panel__secret',
        ];

        foreach ($required as $selector) {
            // Substring-Check: Die Klasse muss buchstaeblich im File auftauchen.
            $this->assertStringContainsString(
                $selector,
                $css,
                sprintf(
                    'AC-6: BEM-Hook "%s" MUSS im Stylesheet vorkommen (Substring-Match).',
                    $selector
                )
            );

            // Regel-Block-Check: Die Klasse muss in einem Selektor stehen, der
            // einen Regelblock einleitet. Wir pruefen via Regex, dass nach
            // dem Selektor (optional gefolgt von weiteren Zeichen wie
            // Pseudo-Selektoren, anderen Klassen, Whitespace, Komma-
            // Listenfortsetzung) eine `{`-Klammer im selben oder folgenden
            // Statement steht.
            //
            // Vereinfachung: Suche nach dem Selektor + (beliebige Nicht-`{`
            // / Nicht-`;`-Zeichen) + `{`. Das toleriert Pseudo-Selektoren
            // (`:hover`), Folge-Klassen (`.is-active`), Komma-Listen und
            // Newlines, schliesst aber `;` aus (Property-Werte-Bereich).
            $escaped  = preg_quote($selector, '/');
            $blockRegex = '/' . $escaped . '[^;{]*\{/s';

            $this->assertMatchesRegularExpression(
                $blockRegex,
                $css,
                sprintf(
                    'AC-6: BEM-Hook "%s" MUSS einen Regelblock einleiten '
                    . '(Selector { ... }-Pattern).',
                    $selector
                )
            );
        }
    }

    // =====================================================================
    // AC-7: Keine Override von WP-Core-Selektoren auf Top-Level
    // =====================================================================

    /**
     * AC-7: GIVEN WP-Admin's native `.notice`, `.notice-error`, `.notice-warning`,
     *            `.notice-success`, `.notice-info`, `.button`, `.button-primary`,
     *            `.button-secondary`, `.form-table`, and `.wrap` rules must
     *            keep their core appearance
     *       WHEN the test scans the stylesheet for top-level rule blocks
     *            targeting any of those selectors as a standalone (non-descendant) rule
     *       THEN ZERO matches are found — these selectors may only appear
     *            when scoped inside a `.spreadconnect-hub …` ancestor.
     */
    public function test_css_does_not_redefine_wp_core_selectors_at_top_level(): void
    {
        $css = self::readCss();

        // Kommentare entfernen, damit `/* .notice {} */`-Hinweise keinen
        // Match erzeugen. Auch @media-Bloecke koennen interne top-level-
        // Wirkung haben, aber der Test laeuft auf String-Ebene; @media-
        // Inhalte sind nach dem Brace-Eintritt eine Ebene tiefer und der
        // Multiline-`^`-Anchor matcht NUR Zeilenanfang. Innerhalb eines
        // @media-Blocks ist eine Zeile, die mit `\t.notice {` beginnt,
        // dennoch ein top-level-Override fuer das definierte Viewport-
        // Bereich. Wir haben keinen einfachen Weg, @media-Inhalte zu
        // entfernen ohne CSS-Parser — daher ist der Test bewusst strikt:
        // jede Zeile, die mit Whitespace + `.notice {` (oder einem der
        // anderen verbotenen Selektoren) beginnt, gilt als Verstoss.
        $stripped = (string) preg_replace('!/\*.*?\*/!s', '', $css);

        $forbidden = [
            'notice',
            'notice-error',
            'notice-warning',
            'notice-success',
            'notice-info',
            'button',
            'button-primary',
            'button-secondary',
            'form-table',
            'wrap',
        ];

        // Multiline-Regex: `^\s*\.SELECTOR\s*\{` — Zeilenanfang, optional
        // Whitespace (Indentation), dann `.{selector}`, optional
        // Whitespace, dann `{`. Das matcht NUR top-level / standalone-
        // Regeln, NICHT Descendant-Selektoren wie
        // `.spreadconnect-hub .notice { ... }`.
        foreach ($forbidden as $selector) {
            $regex = '/^\s*\.' . preg_quote($selector, '/') . '\s*\{/m';
            $matched = preg_match_all($regex, $stripped, $matches);

            $this->assertSame(
                0,
                $matched,
                sprintf(
                    'AC-7: WP-Core-Selektor ".%s" DARF NICHT als top-level / '
                    . 'standalone Rule definiert sein (gefunden: %d Match(es): %s). '
                    . 'Stattdessen MUSS er als Descendant in einem '
                    . '.spreadconnect-hub-Scope vorkommen.',
                    $selector,
                    (int) $matched,
                    isset($matches[0]) ? json_encode($matches[0], JSON_UNESCAPED_SLASHES) : 'n/a'
                )
            );
        }
    }

    // =====================================================================
    // AC-8: Responsive Breakpoint @media (max-width: 782px)
    // =====================================================================

    /**
     * AC-8: GIVEN WP-Admin collapses its own chrome at viewport width 782 px
     *       WHEN the test searches the stylesheet for media queries
     *       THEN the file contains at least one `@media (max-width: 782px)`
     *            (or `(max-width: 783px)`) block that re-flows the sidebar+
     *            content layout — verified by asserting the media-query body
     *            contains a rule for both `.spreadconnect-hub` and
     *            `.spreadconnect-hub-nav`.
     */
    public function test_css_has_mobile_breakpoint_at_782px(): void
    {
        $css = self::readCss();

        // Schritt 1: Ein @media-Block mit max-width: 782px (oder 783px) muss
        // existieren.
        $headerRegex = '/@media\s*\(\s*max-width\s*:\s*78[23]px\s*\)\s*\{/i';

        $this->assertMatchesRegularExpression(
            $headerRegex,
            $css,
            'AC-8: CSS MUSS mindestens einen @media (max-width: 782px)- oder '
            . '@media (max-width: 783px)-Block enthalten (WP-Admin-Mobile-Cutoff).'
        );

        // Schritt 2: Den @media-Block-Body extrahieren und auf Praesenz
        // BEIDER Selektoren pruefen. Wir nutzen einen Brace-aware Scan:
        // start nach der Header-`{`, dann char-by-char bis Brace-Tiefe 0
        // wieder erreicht ist.
        if (! preg_match($headerRegex, $css, $headerMatch, PREG_OFFSET_CAPTURE)) {
            $this->fail('AC-8: @media-Header-Match konnte nicht extrahiert werden.');
        }

        $start  = (int) $headerMatch[0][1] + strlen((string) $headerMatch[0][0]);
        $depth  = 1;
        $length = strlen($css);
        $end    = null;

        for ($i = $start; $i < $length; $i++) {
            $ch = $css[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        $this->assertNotNull(
            $end,
            'AC-8: @media-Block-Body konnte nicht balanciert geparst werden — '
            . 'CSS-Datei evtl. korrupt.'
        );

        $mediaBody = substr($css, $start, $end - $start);

        // Beide Selektoren MUESSEN im Block-Body vorkommen.
        $this->assertStringContainsString(
            '.spreadconnect-hub',
            $mediaBody,
            'AC-8: @media (max-width: 782px)-Block MUSS eine Regel fuer '
            . '.spreadconnect-hub enthalten (Layout-Reflow auf Mobile).'
        );

        $this->assertStringContainsString(
            '.spreadconnect-hub-nav',
            $mediaBody,
            'AC-8: @media (max-width: 782px)-Block MUSS eine Regel fuer '
            . '.spreadconnect-hub-nav enthalten (Sidebar collapsed zu Single-Column).'
        );
    }
}
