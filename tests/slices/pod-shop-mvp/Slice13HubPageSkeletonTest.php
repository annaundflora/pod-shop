<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use SpreadconnectPod\Hub\Controller;
use SpreadconnectPod\Hub\View\Dashboard;
use SpreadconnectPod\Hub\View\Sidebar;

// ---------------------------------------------------------------------
// Stub-Klassen-Strategie (Slice-13 Spec Constraints):
//
// Die 6 Routing-Stub-View-Klassen (Catalog, Orders, Webhooks, FailedOps,
// Logs, Subscriptions) sind NICHT Deliverables dieser Slice — sie werden
// erst in Folge-Slices angelegt (26/—/41/38/42/19). Damit der Dispatcher
// in seinen `class_exists()`-Check die Klasse als vorhanden sieht UND in
// die `($fqcn)::render()`-Aufrufe verzweigen kann, definieren wir sie
// hier als leere `final class` mit `public static function render()`.
//
// Settings (Slice 11) und Dashboard (Slice 13) werden von Production-
// Code geliefert und sind via Autoloader verfuegbar.
// ---------------------------------------------------------------------

if (! class_exists(\SpreadconnectPod\Hub\View\Catalog::class)) {
    /** @phpstan-ignore-next-line */
    eval('namespace SpreadconnectPod\\Hub\\View; final class Catalog { public static int $renderCount = 0; public static function render(): void { self::$renderCount++; } }');
}
if (! class_exists(\SpreadconnectPod\Hub\View\Orders::class)) {
    /** @phpstan-ignore-next-line */
    eval('namespace SpreadconnectPod\\Hub\\View; final class Orders { public static int $renderCount = 0; public static function render(): void { self::$renderCount++; } }');
}
if (! class_exists(\SpreadconnectPod\Hub\View\Webhooks::class)) {
    /** @phpstan-ignore-next-line */
    eval('namespace SpreadconnectPod\\Hub\\View; final class Webhooks { public static int $renderCount = 0; public static function render(): void { self::$renderCount++; } }');
}
if (! class_exists(\SpreadconnectPod\Hub\View\FailedOps::class)) {
    /** @phpstan-ignore-next-line */
    eval('namespace SpreadconnectPod\\Hub\\View; final class FailedOps { public static int $renderCount = 0; public static function render(): void { self::$renderCount++; } }');
}
if (! class_exists(\SpreadconnectPod\Hub\View\Logs::class)) {
    /** @phpstan-ignore-next-line */
    eval('namespace SpreadconnectPod\\Hub\\View; final class Logs { public static int $renderCount = 0; public static function render(): void { self::$renderCount++; } }');
}
if (! class_exists(\SpreadconnectPod\Hub\View\Subscriptions::class)) {
    /** @phpstan-ignore-next-line */
    eval('namespace SpreadconnectPod\\Hub\\View; final class Subscriptions { public static int $renderCount = 0; public static function render(): void { self::$renderCount++; } }');
}

/**
 * Slice 13 — Hub-Page-Skeleton + Section-Routing
 *
 * Acceptance-Tests gegen die Slice-Spec `slice-13-hub-page-skeleton.md`.
 *
 * Mocking Strategy: `mock_external` (laut Slice-Spec):
 *   - Brain\Monkey fuer WP Admin-Menu API: `add_submenu_page`.
 *   - Brain\Monkey fuer Capability-API: `current_user_can`, `wp_die`.
 *   - Brain\Monkey fuer Sanitize-Helpers: `sanitize_key`, `wp_unslash`.
 *   - Brain\Monkey fuer URL-Helpers: `admin_url`, `add_query_arg`,
 *     `esc_url`, `esc_attr`, `esc_html`, `esc_html__`, `esc_attr__`.
 *   - Brain\Monkey fuer i18n: `__`, `_e`.
 *   - `$_GET`-Superglobal in jedem Test gezielt setzen + tearDown leeren.
 *
 * Strategie:
 *   - AC-1 (registerMenu): Spy auf `add_submenu_page` capturet alle Args
 *     und verifiziert Slug, Capability, Callback.
 *   - AC-2/3/4 (Routing): Jede Section setzt eine eindeutige Marker-
 *     Ausgabe in Output-Buffer; Dispatcher-Aufruf -> Output-String wird
 *     gegen erwartete View-Render-Marker geprueft. Da Settings real ist,
 *     nutzen wir `Settings::render()`-Spy via `current_user_can` Stub
 *     der die i18n/Settings-API alle no-op'ed.
 *   - AC-5 (Capability-Gate): `current_user_can` gibt false; `wp_die`-
 *     Stub wirft RuntimeException -> Test catched.
 *   - AC-6 (Sanitize): `$_GET['section']` mit XSS/SQLi -> sanitize_key
 *     -> Whitelist-Miss -> Dashboard-Fallback.
 *   - AC-7 (Sidebar): Output-Buffer-Capture, parse mit DOMDocument,
 *     verifiziere 8 `<li>`-Items in Reihenfolge + genau 1 `is-active`.
 *   - AC-8 (Dashboard): Output-Buffer-Capture, 5 Card-Slots in Wireframe-
 *     Reihenfolge; keine Daten-Queries (kein get_option/wpdb-Call).
 *   - AC-9 (Escaping): Source-Analyse + Brain\Monkey-Spies auf
 *     esc_url/esc_attr/esc_html/esc_html__.
 *   - AC-10 (Settings-Reuse): Spy auf Settings::render-Aufruf via
 *     `current_user_can`-Sicht; verifiziere Aufruf passiert beim Routing.
 */
final class Slice13HubPageSkeletonTest extends TestCase
{
    /**
     * Repo-Root: drei Verzeichnisse oberhalb von `tests/slices/pod-shop-mvp/`.
     */
    private static function repoRoot(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }

    /**
     * Absoluter Pfad zu den Hub-View-Dateien.
     */
    private static function controllerFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/includes/Hub/Controller.php';
    }

    private static function sidebarFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/includes/Hub/View/Sidebar.php';
    }

    private static function dashboardFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/includes/Hub/View/Dashboard.php';
    }

    /**
     * Section-Slug-Tabelle (Single Source of Truth aus Slice-13 Spec).
     *
     * @return array<string, class-string>
     */
    private static function expectedSections(): array
    {
        return [
            'dashboard'     => Dashboard::class,
            'catalog'       => \SpreadconnectPod\Hub\View\Catalog::class,
            'orders'        => \SpreadconnectPod\Hub\View\Orders::class,
            'webhooks'      => \SpreadconnectPod\Hub\View\Webhooks::class,
            'failed'        => \SpreadconnectPod\Hub\View\FailedOps::class,
            'logs'          => \SpreadconnectPod\Hub\View\Logs::class,
            'settings'      => \SpreadconnectPod\Hub\View\Settings::class,
            'subscriptions' => \SpreadconnectPod\Hub\View\Subscriptions::class,
        ];
    }

    /**
     * Stub i18n + Escape-Helpers — werden in dispatch/Sidebar/Dashboard
     * haeufig aufgerufen.
     */
    private static function stubI18nAndEscapeHelpers(): void
    {
        Monkey\Functions\when('__')->returnArg(1);
        Monkey\Functions\when('esc_html__')->returnArg(1);
        Monkey\Functions\when('esc_attr__')->returnArg(1);
        Monkey\Functions\when('_e')->returnArg(1);
        Monkey\Functions\when('esc_html')->returnArg(1);
        Monkey\Functions\when('esc_attr')->returnArg(1);
        Monkey\Functions\when('esc_url')->returnArg(1);
    }

    /**
     * Stub URL-Helpers fuer Sidebar.
     */
    private static function stubUrlHelpers(): void
    {
        Monkey\Functions\when('admin_url')->alias(function ($path = '') {
            return 'http://example.test/wp-admin/' . ltrim((string) $path, '/');
        });
        Monkey\Functions\when('add_query_arg')->alias(function ($key, $value = null, $url = '') {
            // 3-arg form: add_query_arg('section', 'foo', 'http://...?page=spreadconnect')
            if (is_string($key) && is_string($url)) {
                $separator = (str_contains($url, '?')) ? '&' : '?';
                return $url . $separator . $key . '=' . (string) $value;
            }
            return (string) $url;
        });
    }

    /**
     * Stub Sanitize-Helpers.
     */
    private static function stubSanitizeHelpers(): void
    {
        // sanitize_key: WP-konform — lowercase, alphanumeric + dash + underscore.
        Monkey\Functions\when('sanitize_key')->alias(function ($key) {
            if (! is_string($key)) {
                return '';
            }
            $key = strtolower($key);
            return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
        });
        // wp_unslash: passthrough fuer Strings (entfernt magic-quote-Slashes;
        // im Test-Kontext keine vorhandenen Slashes).
        Monkey\Functions\when('wp_unslash')->alias(function ($value) {
            if (is_string($value)) {
                return stripslashes($value);
            }
            return $value;
        });
    }

    /**
     * Reset Render-Counter aller stub View-Klassen, damit Tests
     * unabhaengig sind.
     */
    private static function resetStubRenderCounts(): void
    {
        \SpreadconnectPod\Hub\View\Catalog::$renderCount       = 0;
        \SpreadconnectPod\Hub\View\Orders::$renderCount        = 0;
        \SpreadconnectPod\Hub\View\Webhooks::$renderCount      = 0;
        \SpreadconnectPod\Hub\View\FailedOps::$renderCount     = 0;
        \SpreadconnectPod\Hub\View\Logs::$renderCount          = 0;
        \SpreadconnectPod\Hub\View\Subscriptions::$renderCount = 0;
    }

    /**
     * @return list<string> Render-Counts als Snapshot fuer Cross-View-Vergleich.
     */
    private static function stubRenderCounts(): array
    {
        return [
            'catalog'       => \SpreadconnectPod\Hub\View\Catalog::$renderCount,
            'orders'        => \SpreadconnectPod\Hub\View\Orders::$renderCount,
            'webhooks'      => \SpreadconnectPod\Hub\View\Webhooks::$renderCount,
            'failed'        => \SpreadconnectPod\Hub\View\FailedOps::$renderCount,
            'logs'          => \SpreadconnectPod\Hub\View\Logs::$renderCount,
            'subscriptions' => \SpreadconnectPod\Hub\View\Subscriptions::$renderCount,
        ];
    }

    /**
     * Source-Inhalt (cached pro Aufruf — file_get_contents ist guenstig).
     */
    private static function readSource(string $file): string
    {
        self::assertFileExists($file, sprintf('Datei muss existieren: %s', $file));
        $contents = (string) file_get_contents($file);
        self::assertNotSame('', $contents, sprintf('Datei darf nicht leer sein: %s', $file));
        return $contents;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        self::resetStubRenderCounts();
        // Pruefe sicheren Initialzustand.
        unset($_GET['section']);
        unset($_GET['page']);
    }

    protected function tearDown(): void
    {
        unset($_GET['section']);
        unset($_GET['page']);
        Monkey\tearDown();
        parent::tearDown();
    }

    // =====================================================================
    // AC-1: registerMenu ruft add_submenu_page mit korrekten Args
    // =====================================================================

    /**
     * AC-1: GIVEN WP-Admin-Menu wird aufgebaut (Hook `admin_menu`)
     *       WHEN `Hub\Controller::registerMenu()` ausgefuehrt wird
     *       THEN wird `add_submenu_page('woocommerce', $page_title,
     *            $menu_title, 'manage_woocommerce', 'spreadconnect',
     *            [Hub\Controller::class, 'dispatch'])` aufgerufen.
     */
    public function test_register_menu_calls_add_submenu_page_under_woocommerce(): void
    {
        self::stubI18nAndEscapeHelpers();

        $captured = [];
        Monkey\Functions\when('add_submenu_page')->alias(
            function ($parent, $page_title, $menu_title, $cap, $slug, $callback) use (&$captured) {
                $captured[] = [
                    'parent'      => $parent,
                    'page_title'  => $page_title,
                    'menu_title'  => $menu_title,
                    'capability'  => $cap,
                    'slug'        => $slug,
                    'callback'    => $callback,
                ];
                return 'spreadconnect-hook-suffix';
            }
        );

        Controller::registerMenu();

        $this->assertCount(
            1,
            $captured,
            'AC-1: registerMenu() MUSS add_submenu_page() genau einmal aufrufen.'
        );

        $call = $captured[0];

        $this->assertSame(
            'woocommerce',
            $call['parent'],
            'AC-1: Parent-Slug MUSS "woocommerce" sein (Submenu unter WooCommerce-Top-Level).'
        );

        $this->assertSame(
            'spreadconnect',
            $call['slug'],
            'AC-1: Menu-Slug MUSS exakt "spreadconnect" sein.'
        );

        $this->assertSame(
            [Controller::class, 'dispatch'],
            $call['callback'],
            'AC-1: Callback MUSS auf [Hub\\Controller::class, "dispatch"] zeigen.'
        );

        // Page-Title und Menu-Title kommen via __() durch (returnArg(1) liefert
        // den unmodifizierten ersten Arg zurueck).
        $this->assertNotSame(
            '',
            (string) $call['page_title'],
            'AC-1: page_title darf nicht leer sein (via __() lokalisiert).'
        );
        $this->assertNotSame(
            '',
            (string) $call['menu_title'],
            'AC-1: menu_title darf nicht leer sein (via __() lokalisiert).'
        );
    }

    /**
     * AC-1: Capability fuer Submenu-Registration ist 'manage_woocommerce'.
     */
    public function test_register_menu_uses_manage_woocommerce_capability(): void
    {
        self::stubI18nAndEscapeHelpers();

        $captured = [];
        Monkey\Functions\when('add_submenu_page')->alias(
            function ($parent, $page_title, $menu_title, $cap, $slug, $callback) use (&$captured) {
                $captured[] = $cap;
            }
        );

        Controller::registerMenu();

        $this->assertCount(
            1,
            $captured,
            'AC-1: add_submenu_page() MUSS genau einmal aufgerufen werden.'
        );
        $this->assertSame(
            'manage_woocommerce',
            $captured[0],
            'AC-1: Submenu-Capability MUSS "manage_woocommerce" sein (architecture.md Z. 646).'
        );
    }

    /**
     * AC-1: Page-Title und Menu-Title werden via __() mit Domain
     * 'spreadconnect-pod' lokalisiert. Wir spy'en __() und pruefen
     * dass der zweite Arg (Domain) immer "spreadconnect-pod" ist.
     */
    public function test_register_menu_localises_titles_with_correct_text_domain(): void
    {
        self::stubI18nAndEscapeHelpers();

        $domainsUsed = [];
        Monkey\Functions\when('__')->alias(function ($text, $domain = null) use (&$domainsUsed) {
            $domainsUsed[] = $domain;
            return $text;
        });

        Monkey\Functions\when('add_submenu_page')->justReturn('hook-suffix');

        Controller::registerMenu();

        $this->assertNotEmpty(
            $domainsUsed,
            'AC-1: registerMenu() MUSS __() fuer page_title/menu_title aufrufen.'
        );
        foreach ($domainsUsed as $idx => $domain) {
            $this->assertSame(
                'spreadconnect-pod',
                $domain,
                sprintf(
                    'AC-1: __()-Aufruf #%d in registerMenu() MUSS Domain "spreadconnect-pod" verwenden.',
                    $idx
                )
            );
        }
    }

    // =====================================================================
    // AC-2: Default-Section ohne ?section ist Dashboard
    // =====================================================================

    /**
     * AC-2: GIVEN Admin-User mit manage_woocommerce ruft
     *       ?page=spreadconnect ohne section auf
     *       THEN wird `Hub\View\Dashboard::render()` aufgerufen.
     */
    public function test_dispatch_without_section_param_renders_dashboard(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubUrlHelpers();
        self::stubSanitizeHelpers();

        Monkey\Functions\when('current_user_can')->justReturn(true);

        // KEINE $_GET['section'] gesetzt.
        unset($_GET['section']);

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Controller::dispatch();
        } finally {
            $output = ob_get_clean();
            // Sicherheits-Cleanup, falls Exception unsere Buffer hinterlassen hat.
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        // Output enthaelt Dashboard-typische Marker — Dashboard rendert
        // einen <h1> Title sowie 5 Card-Slots mit "spreadconnect-card"-Class.
        $this->assertStringContainsString(
            'spreadconnect-card',
            (string) $output,
            'AC-2: dispatch() ohne ?section MUSS Dashboard rendern (5-Card-Stub-Markup erwartet).'
        );

        // Keine andere View-Klasse wurde gerufen.
        foreach (self::stubRenderCounts() as $slug => $count) {
            $this->assertSame(
                0,
                $count,
                sprintf('AC-2: Stub-View "%s" darf NICHT gerendert werden, wenn Default = Dashboard.', $slug)
            );
        }
    }

    /**
     * AC-2: Leerer Section-String (?section=) faellt ebenfalls auf
     * Dashboard zurueck (Default-Pfad).
     */
    public function test_dispatch_with_empty_section_string_renders_dashboard(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubUrlHelpers();
        self::stubSanitizeHelpers();

        Monkey\Functions\when('current_user_can')->justReturn(true);

        $_GET['section'] = '';

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Controller::dispatch();
        } finally {
            $output = ob_get_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        $this->assertStringContainsString(
            'spreadconnect-card',
            (string) $output,
            'AC-2: dispatch() mit ?section="" MUSS Dashboard rendern (Default-Fallback).'
        );

        foreach (self::stubRenderCounts() as $count) {
            $this->assertSame(0, $count, 'AC-2: Stub-Views duerfen NICHT gerendert werden.');
        }
    }

    // =====================================================================
    // AC-3: Dispatch zu jeder der 8 View-Klassen
    // =====================================================================

    /**
     * AC-3: GIVEN ?section={slug} mit gueltigem Slug
     *       THEN genau die zugehoerige View::render() wird genau einmal
     *       aufgerufen, keine andere View wird beruehrt.
     *
     * Datengetrieben fuer alle 8 Slugs aus self::expectedSections().
     */
    public function test_dispatch_routes_each_known_section_to_correct_view_class(): void
    {
        // Stub-Views verfolgen ihre Render-Counts; fuer Dashboard und Settings
        // (echte Klassen) muessen wir den Output-Marker pruefen.
        $stubSlugs = ['catalog', 'orders', 'webhooks', 'failed', 'logs', 'subscriptions'];

        foreach ($stubSlugs as $slug) {
            // Reset alle Mocks pro Iteration.
            Monkey\tearDown();
            Monkey\setUp();
            self::resetStubRenderCounts();

            self::stubI18nAndEscapeHelpers();
            self::stubUrlHelpers();
            self::stubSanitizeHelpers();
            Monkey\Functions\when('current_user_can')->justReturn(true);

            $_GET['section'] = $slug;

            $initialObLevel = ob_get_level();
            ob_start();
            try {
                Controller::dispatch();
            } finally {
                ob_end_clean();
                while (ob_get_level() > $initialObLevel) {
                    ob_end_clean();
                }
            }

            // Genau die erwartete Stub-View hat einmal gerendert.
            $counts = self::stubRenderCounts();
            $this->assertSame(
                1,
                $counts[$slug],
                sprintf(
                    'AC-3: Section-Slug "%s" MUSS genau einmal die zugehoerige View::render() aufrufen.',
                    $slug
                )
            );

            // Andere Stub-Views NICHT gerendert.
            foreach ($counts as $otherSlug => $otherCount) {
                if ($otherSlug === $slug) {
                    continue;
                }
                $this->assertSame(
                    0,
                    $otherCount,
                    sprintf(
                        'AC-3: Beim Routing zu "%s" darf View "%s" NICHT gerendert werden (gefunden: %d).',
                        $slug,
                        $otherSlug,
                        $otherCount
                    )
                );
            }
        }
    }

    /**
     * AC-3 / AC-2: ?section=dashboard ruft Dashboard::render() (echte Klasse).
     */
    public function test_dispatch_routes_dashboard_section_to_dashboard_view(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubUrlHelpers();
        self::stubSanitizeHelpers();
        Monkey\Functions\when('current_user_can')->justReturn(true);

        $_GET['section'] = 'dashboard';

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Controller::dispatch();
        } finally {
            $output = ob_get_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        $this->assertStringContainsString(
            'spreadconnect-card',
            (string) $output,
            'AC-3: ?section=dashboard MUSS Dashboard::render() aufrufen.'
        );

        // Stub-Views NICHT gerendert.
        foreach (self::stubRenderCounts() as $count) {
            $this->assertSame(0, $count);
        }
    }

    // =====================================================================
    // AC-4: Unbekannter Section-Slug faellt auf Dashboard zurueck
    // =====================================================================

    /**
     * AC-4: GIVEN ?section=foo (unbekannter Slug)
     *       THEN Routing faellt auf `dashboard` zurueck und ruft
     *       Dashboard::render() auf — kein 404, keine Exception.
     */
    public function test_dispatch_falls_back_to_dashboard_for_unknown_section(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubUrlHelpers();
        self::stubSanitizeHelpers();
        Monkey\Functions\when('current_user_can')->justReturn(true);

        $_GET['section'] = 'foo-unknown-slug';

        $initialObLevel = ob_get_level();
        ob_start();
        $thrown = null;
        try {
            Controller::dispatch();
        } catch (\Throwable $e) {
            $thrown = $e;
        } finally {
            $output = ob_get_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        $this->assertNull(
            $thrown,
            sprintf(
                'AC-4: Unbekannter Section-Slug darf KEINE Exception werfen — gefangen: %s',
                $thrown ? ($thrown::class . ': ' . $thrown->getMessage()) : 'none'
            )
        );

        $this->assertStringContainsString(
            'spreadconnect-card',
            (string) $output,
            'AC-4: Unbekannter Slug MUSS Dashboard rendern (5-Card-Stub-Marker erwartet).'
        );

        // Keine Stub-View gerendert.
        foreach (self::stubRenderCounts() as $slug => $count) {
            $this->assertSame(
                0,
                $count,
                sprintf('AC-4: Stub-View "%s" darf NICHT gerendert werden bei unknown-Section.', $slug)
            );
        }
    }

    /**
     * AC-4 / Stub-Klassen-Strategie: Wenn die View-Klasse nicht
     * existiert (`class_exists()` -> false), faellt der Dispatcher
     * ebenfalls auf Dashboard zurueck (defensive Guard laut Spec
     * Constraints "Stub-Klassen-Strategie", AC-4 implizit).
     */
    public function test_dispatch_falls_back_to_dashboard_for_missing_class(): void
    {
        // Wir injizieren eine Section deren Slug existiert, aber dessen
        // Klasse nicht autoloadbar ist. Dazu nutzen wir Reflection auf die
        // SECTIONS-Konstante: das ist nicht moeglich (`const`), also testen
        // wir den Fallback aus Sicht des Production-Behaviors:
        //
        // Der Production-Code prueft `class_exists()` VOR dem Dispatch.
        // Da die Stub-Klassen in diesem Test-File definiert sind, sind sie
        // alle existent — wir koennen den Pfad daher nur indirekt pruefen.
        // Stattdessen verifizieren wir, dass der Source-Code den
        // class_exists-Guard enthaelt.
        $source = self::readSource(self::controllerFile());

        $this->assertMatchesRegularExpression(
            '/class_exists\s*\(\s*\$fqcn\s*\)/',
            $source,
            'AC-4 / Constraint "Stub-Klassen-Strategie": Dispatcher MUSS einen ' .
            '`class_exists($fqcn)`-Guard VOR dem View-Render-Dispatch enthalten, ' .
            'damit nicht-existierende Stub-View-Klassen (Catalog/Orders/...) auf ' .
            'Dashboard zurueckfallen anstatt einen Class-Not-Found-Fatal zu werfen.'
        );
    }

    // =====================================================================
    // AC-5: Capability-Gate (manage_woocommerce -> false)
    // =====================================================================

    /**
     * AC-5: GIVEN User OHNE manage_woocommerce-Capability
     *       WHEN dispatch() laeuft
     *       THEN current_user_can('manage_woocommerce') -> false
     *            -> wp_die() terminiert; KEINE View::render() ausgefuehrt.
     */
    public function test_dispatch_aborts_via_wp_die_without_manage_woocommerce(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubUrlHelpers();
        self::stubSanitizeHelpers();

        $checkedCaps = [];
        Monkey\Functions\when('current_user_can')->alias(function ($cap) use (&$checkedCaps) {
            $checkedCaps[] = $cap;
            return false; // User hat KEINE Capability.
        });

        $_GET['section'] = 'dashboard';

        $initialObLevel = ob_get_level();
        $thrown         = false;
        $caughtMessage  = '';

        ob_start();
        try {
            Controller::dispatch();
        } catch (RuntimeException $e) {
            // wp_die-Stub aus tests/stubs/wp-functions.php wirft RuntimeException.
            $thrown        = true;
            $caughtMessage = $e->getMessage();
        }
        while (ob_get_level() > $initialObLevel) {
            ob_end_clean();
        }

        $this->assertTrue(
            $thrown,
            'AC-5: dispatch() OHNE manage_woocommerce MUSS via wp_die() terminieren.'
        );
        $this->assertStringContainsString(
            'wp_die',
            $caughtMessage,
            'AC-5: Termination MUSS aus wp_die() stammen.'
        );

        // current_user_can('manage_woocommerce') wurde geprueft.
        $this->assertContains(
            'manage_woocommerce',
            $checkedCaps,
            'AC-5: dispatch() MUSS current_user_can("manage_woocommerce") evaluieren.'
        );

        // Keine Stub-View gerendert.
        foreach (self::stubRenderCounts() as $slug => $count) {
            $this->assertSame(
                0,
                $count,
                sprintf('AC-5: View "%s" darf NICHT rendern, wenn Capability fehlt.', $slug)
            );
        }
    }

    /**
     * AC-5: ensureCapability() ist als oeffentliche static Methode exponiert
     * (Provides-To "per-AJAX-Slices"). wp_die-Message ist via __()
     * lokalisiert.
     */
    public function test_ensure_capability_throws_when_user_lacks_cap(): void
    {
        self::stubI18nAndEscapeHelpers();

        Monkey\Functions\when('current_user_can')->justReturn(false);

        $thrown = false;
        try {
            Controller::ensureCapability();
        } catch (RuntimeException $e) {
            $thrown = true;
        }

        $this->assertTrue(
            $thrown,
            'AC-5: ensureCapability() MUSS via wp_die() terminieren bei fehlender Capability.'
        );
    }

    /**
     * AC-5: ensureCapability() laesst durch, wenn Capability vorhanden ist.
     */
    public function test_ensure_capability_passes_when_user_has_cap(): void
    {
        self::stubI18nAndEscapeHelpers();

        Monkey\Functions\when('current_user_can')->justReturn(true);

        $thrown = false;
        try {
            Controller::ensureCapability();
        } catch (\Throwable $e) {
            $thrown = true;
        }

        $this->assertFalse(
            $thrown,
            'AC-5: ensureCapability() MUSS still durchlaufen bei vorhandener Capability.'
        );
    }

    /**
     * AC-5: ensureCapability ist Provides-To "per-AJAX-Slices" — MUSS
     * public static Methode sein.
     */
    public function test_ensure_capability_is_public_static(): void
    {
        $reflection = new ReflectionClass(Controller::class);
        $this->assertTrue(
            $reflection->hasMethod('ensureCapability'),
            'AC-5: Controller MUSS eine public Methode ensureCapability() exponieren.'
        );
        $method = $reflection->getMethod('ensureCapability');
        $this->assertTrue(
            $method->isPublic(),
            'AC-5: ensureCapability() MUSS public sein (Provides-To per-AJAX-Slices).'
        );
        $this->assertTrue(
            $method->isStatic(),
            'AC-5: ensureCapability() MUSS static sein (stateless adapter).'
        );
    }

    // =====================================================================
    // AC-6: Section-Param wird sanitisiert + gegen Whitelist gematcht
    // =====================================================================

    /**
     * AC-6: GIVEN ?section=<script>alert(1)</script>
     *       WHEN dispatch() ihn liest
     *       THEN sanitize_key + Whitelist-Match -> Dashboard-Fallback;
     *            kein literaler $_GET-Wert erreicht View::render().
     */
    public function test_dispatch_sanitizes_xss_section_param_and_falls_back(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubUrlHelpers();
        self::stubSanitizeHelpers();
        Monkey\Functions\when('current_user_can')->justReturn(true);

        $_GET['section'] = '<script>alert(1)</script>';

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Controller::dispatch();
        } finally {
            $output = (string) ob_get_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        // Output darf NICHT das rohe <script>-Tag enthalten.
        $this->assertStringNotContainsString(
            '<script>alert(1)</script>',
            $output,
            'AC-6: Roher $_GET-XSS-Wert darf NICHT in den Output durchschlagen — ' .
            'der Wert MUSS via sanitize_key + Whitelist verworfen werden.'
        );

        // Dashboard wurde gerendert (Fallback).
        $this->assertStringContainsString(
            'spreadconnect-card',
            $output,
            'AC-6: Bei nicht-whitelisted Section MUSS Dashboard rendern (AC-4-Fallback).'
        );

        // Keine Stub-View gerendert.
        foreach (self::stubRenderCounts() as $count) {
            $this->assertSame(0, $count);
        }
    }

    /**
     * AC-6: GIVEN ?section=settings'OR'1'='1 (SQLi-aehnlicher Wert)
     *       THEN sanitize_key entfernt Sonderzeichen -> "settingsor11"
     *            -> nicht in Whitelist -> Dashboard-Fallback.
     */
    public function test_dispatch_sanitizes_sqli_section_param_and_falls_back(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubUrlHelpers();
        self::stubSanitizeHelpers();
        Monkey\Functions\when('current_user_can')->justReturn(true);

        $_GET['section'] = "settings'OR'1'='1";

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Controller::dispatch();
        } finally {
            $output = (string) ob_get_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        $this->assertStringContainsString(
            'spreadconnect-card',
            $output,
            'AC-6: SQLi-aehnlicher Section-Param wird zu "settingsor11" sanitised, ist nicht ' .
            'in Whitelist -> Dashboard-Fallback. Settings::render() darf NICHT aufgerufen werden.'
        );

        foreach (self::stubRenderCounts() as $count) {
            $this->assertSame(0, $count);
        }
    }

    /**
     * AC-6: sanitize_key wird auf $_GET['section'] aufgerufen — Spy verifiziert.
     */
    public function test_dispatch_calls_sanitize_key_on_section_param(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubUrlHelpers();
        Monkey\Functions\when('current_user_can')->justReturn(true);
        Monkey\Functions\when('wp_unslash')->returnArg(1);

        $sanitizeKeyCalls = [];
        Monkey\Functions\when('sanitize_key')->alias(function ($key) use (&$sanitizeKeyCalls) {
            $sanitizeKeyCalls[] = $key;
            // Im Test geben wir den Original-Wert lowercase zurueck — egal,
            // wir testen nur, dass sanitize_key gerufen wurde.
            return is_string($key) ? strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key) ?? '') : '';
        });

        $_GET['section'] = 'CATALOG';

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Controller::dispatch();
        } finally {
            ob_end_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        $this->assertNotEmpty(
            $sanitizeKeyCalls,
            'AC-6: dispatch() MUSS sanitize_key() auf $_GET["section"] aufrufen.'
        );
        $this->assertContains(
            'CATALOG',
            $sanitizeKeyCalls,
            'AC-6: sanitize_key() MUSS mit dem Original-$_GET-Wert aufgerufen werden.'
        );
    }

    // =====================================================================
    // AC-7: Sidebar rendert 8 Nav-Items in korrekter Reihenfolge
    // =====================================================================

    /**
     * AC-7: GIVEN Sidebar::render($active_slug) mit gueltigem Slug
     *       THEN Markup hat genau 8 Nav-Items in Reihenfolge laut
     *       Section-Slug-Tabelle, jeder mit href=admin.php?page=spreadconnect&section={slug}.
     */
    public function test_sidebar_render_emits_eight_nav_items_with_admin_urls(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubUrlHelpers();

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Sidebar::render('dashboard');
        } finally {
            $output = (string) ob_get_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        // 8 <li>-Items zaehlen.
        $liCount = preg_match_all('/<li[^>]*>/', $output);
        $this->assertSame(
            8,
            $liCount,
            sprintf(
                'AC-7: Sidebar MUSS exakt 8 <li>-Items rendern (Section-Slug-Tabelle). Gefunden: %d',
                $liCount
            )
        );

        // Jeder der 8 Slugs MUSS in einem href=...section={slug} vorkommen, in
        // der Reihenfolge Dashboard, Catalog, Orders, Webhooks, Failed, Logs,
        // Settings, Subscriptions.
        $expectedOrder = ['dashboard', 'catalog', 'orders', 'webhooks', 'failed', 'logs', 'settings', 'subscriptions'];

        $previousPos = -1;
        foreach ($expectedOrder as $idx => $slug) {
            $needle = 'section=' . $slug;
            $pos = strpos($output, $needle, $previousPos + 1);
            $this->assertNotFalse(
                $pos,
                sprintf(
                    'AC-7: Sidebar MUSS einen href mit "%s" enthalten (Position %d in Section-Slug-Tabelle).',
                    $needle,
                    $idx + 1
                )
            );
            $this->assertGreaterThan(
                $previousPos,
                $pos,
                sprintf(
                    'AC-7: Sidebar-Reihenfolge falsch — "%s" muss NACH dem vorigen Slug "%s" stehen.',
                    $slug,
                    $idx > 0 ? $expectedOrder[$idx - 1] : 'BEGIN'
                )
            );
            $previousPos = $pos;
        }
    }

    /**
     * AC-7: Genau ein Sidebar-Item traegt 'is-active' fuer den
     * uebergebenen Slug.
     */
    public function test_sidebar_render_marks_exactly_one_item_active(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubUrlHelpers();

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Sidebar::render('catalog');
        } finally {
            $output = (string) ob_get_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        $activeCount = preg_match_all('/is-active/', $output);
        $this->assertSame(
            1,
            $activeCount,
            sprintf(
                'AC-7: Genau EIN Nav-Item MUSS "is-active" sein. Gefunden: %d',
                $activeCount
            )
        );

        // Und das aktive Item gehoert zum "catalog"-Slug. Wir matchen
        // ein <li> das BEIDES enthaelt: is-active UND section=catalog.
        $this->assertMatchesRegularExpression(
            '/<li[^>]*is-active[^>]*>[\s\S]*?section=catalog/',
            $output,
            'AC-7: Das "is-active"-<li> MUSS den catalog-Link enthalten.'
        );
    }

    /**
     * AC-7: Bei jedem der 8 Slugs als active markiert ist genau ein Item active.
     */
    public function test_sidebar_marks_correct_active_item_for_each_slug(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubUrlHelpers();

        $allSlugs = ['dashboard', 'catalog', 'orders', 'webhooks', 'failed', 'logs', 'settings', 'subscriptions'];

        foreach ($allSlugs as $slug) {
            $initialObLevel = ob_get_level();
            ob_start();
            try {
                Sidebar::render($slug);
            } finally {
                $output = (string) ob_get_clean();
                while (ob_get_level() > $initialObLevel) {
                    ob_end_clean();
                }
            }

            $activeCount = preg_match_all('/is-active/', $output);
            $this->assertSame(
                1,
                $activeCount,
                sprintf(
                    'AC-7: Beim active_slug="%s" MUSS genau 1 is-active-Marker existieren.',
                    $slug
                )
            );

            $this->assertMatchesRegularExpression(
                '/<li[^>]*is-active[^>]*>[\s\S]*?section=' . preg_quote($slug, '/') . '/',
                $output,
                sprintf('AC-7: is-active-Marker MUSS auf den "%s"-Link gesetzt sein.', $slug)
            );
        }
    }

    // =====================================================================
    // AC-8: Dashboard rendert 5 Card-Slots in Wireframe-Reihenfolge
    // =====================================================================

    /**
     * AC-8: GIVEN Dashboard::render()
     *       THEN exakt 5 Card-Slot-Container in Reihenfolge
     *            Connection / Catalog / Orders / Webhooks / Failed Operations
     *            (Wireframe Screen 1, Cards 5-9).
     *
     * Slug-Naming laut Spec Constraints:
     *   `connection`, `catalog`, `orders`, `webhooks`, `failed-ops`.
     */
    public function test_dashboard_render_emits_five_card_slots_in_wireframe_order(): void
    {
        self::stubI18nAndEscapeHelpers();

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Dashboard::render();
        } finally {
            $output = (string) ob_get_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        // Genau 5 Cards.
        $cardCount = preg_match_all('/spreadconnect-card spreadconnect-card--/', $output);
        $this->assertSame(
            5,
            $cardCount,
            sprintf('AC-8: Dashboard MUSS GENAU 5 Card-Slots rendern. Gefunden: %d', $cardCount)
        );

        // Reihenfolge laut Wireframe Screen 1 Cards 5-9.
        $expectedOrder = ['connection', 'catalog', 'orders', 'webhooks', 'failed-ops'];

        $previousPos = -1;
        foreach ($expectedOrder as $idx => $cardSlug) {
            $needle = 'spreadconnect-card--' . $cardSlug;
            $pos = strpos($output, $needle, $previousPos + 1);
            $this->assertNotFalse(
                $pos,
                sprintf('AC-8: Dashboard MUSS Card "%s" enthalten.', $cardSlug)
            );
            $this->assertGreaterThan(
                $previousPos,
                $pos,
                sprintf(
                    'AC-8: Card-Reihenfolge falsch — "%s" muss NACH "%s" stehen ' .
                    '(Wireframe Screen 1 Cards 5-9).',
                    $cardSlug,
                    $idx > 0 ? $expectedOrder[$idx - 1] : 'BEGIN'
                )
            );
            $previousPos = $pos;
        }
    }

    /**
     * AC-8: Jeder Card-Slot enthaelt einen "Wird in Slice {N} befuellt"-
     * Platzhalter-String.
     */
    public function test_dashboard_render_contains_slice_placeholder_strings(): void
    {
        self::stubI18nAndEscapeHelpers();

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Dashboard::render();
        } finally {
            $output = (string) ob_get_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        // "Wird in Slice {N} befuellt"-Pattern (5x — eine pro Card).
        $placeholderCount = preg_match_all('/Wird in Slice\s+\d+\s+bef[uü]llt/u', $output);
        $this->assertSame(
            5,
            $placeholderCount,
            sprintf(
                'AC-8: Dashboard MUSS GENAU 5 "Wird in Slice {N} befuellt"-Platzhalter ' .
                'enthalten (einen pro Card-Slot). Gefunden: %d',
                $placeholderCount
            )
        );
    }

    /**
     * AC-8: Dashboard fuehrt KEINE Daten-Queries aus.
     *
     * Heuristik: Wenn `get_option`, `wpdb`, `WP_Query`, `as_get_scheduled_actions`
     * o.ae. nicht gestubt sind, wuerde ein realer Aufruf einen Fatal Error
     * erzeugen — wir laufen render() ohne diese Stubs durch und erwarten
     * KEINE Exception.
     */
    public function test_dashboard_render_does_not_perform_data_queries(): void
    {
        self::stubI18nAndEscapeHelpers();

        // Wir spy'en auf gaengige Daten-Query-Funktionen. Wenn render()
        // sie aufruft, wuerde der Spy zaehlen — wir erwarten NULL Aufrufe.
        $queryCalls = [];
        Monkey\Functions\when('get_option')->alias(function ($key) use (&$queryCalls) {
            $queryCalls[] = ['fn' => 'get_option', 'arg' => $key];
            return false;
        });
        Monkey\Functions\when('get_transient')->alias(function ($key) use (&$queryCalls) {
            $queryCalls[] = ['fn' => 'get_transient', 'arg' => $key];
            return false;
        });
        Monkey\Functions\when('wp_count_posts')->alias(function () use (&$queryCalls) {
            $queryCalls[] = ['fn' => 'wp_count_posts'];
            return (object) [];
        });
        Monkey\Functions\when('as_get_scheduled_actions')->alias(function () use (&$queryCalls) {
            $queryCalls[] = ['fn' => 'as_get_scheduled_actions'];
            return [];
        });

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Dashboard::render();
        } finally {
            ob_end_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        $this->assertSame(
            [],
            $queryCalls,
            sprintf(
                'AC-8: Dashboard::render() darf KEINE Daten-Queries ausfuehren. Gefunden: %s',
                json_encode(array_column($queryCalls, 'fn'))
            )
        );
    }

    /**
     * AC-8: Dashboard-Render hat <h1>-Title.
     */
    public function test_dashboard_render_emits_h1_title(): void
    {
        self::stubI18nAndEscapeHelpers();

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Dashboard::render();
        } finally {
            $output = (string) ob_get_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        $this->assertMatchesRegularExpression(
            '/<h1[^>]*>/',
            $output,
            'AC-8: Dashboard MUSS einen <h1>-Title rendern.'
        );
    }

    // =====================================================================
    // AC-9: User-Strings via __() / esc_html__() mit Domain 'spreadconnect-pod'
    //       URLs/Attributes/Content via esc_url/esc_attr/esc_html escaped.
    // =====================================================================

    /**
     * AC-9: Sidebar.php verwendet i18n-Wrapper mit Domain 'spreadconnect-pod'
     * (oder self::TEXT_DOMAIN-Konstante mit diesem Wert).
     */
    public function test_sidebar_source_uses_correct_text_domain(): void
    {
        $source = self::readSource(self::sidebarFile());

        // TEXT_DOMAIN-Konstante (falls vorhanden) hat Wert 'spreadconnect-pod'.
        if (preg_match("/private const TEXT_DOMAIN\\s*=\\s*'([^']+)'/", $source, $m)) {
            $this->assertSame(
                'spreadconnect-pod',
                $m[1],
                'AC-9: Sidebar::TEXT_DOMAIN-Konstante MUSS exakt "spreadconnect-pod" sein.'
            );
        }

        // Alle __()/_e()/esc_html__()-Aufrufe nutzen die Domain.
        // Heuristik: Suche `'spreadconnect-pod'` ODER `self::TEXT_DOMAIN` als
        // 2. Argument von i18n-Wrappern.
        $callPattern = '/(?:_e|__|esc_html__|esc_attr__)\s*\(\s*[^,()]+(?:\(\s*[^()]*\s*\))?\s*,\s*([^)]+)\)/';
        preg_match_all($callPattern, $source, $matches);

        $this->assertNotEmpty(
            $matches[0],
            'AC-9: Sidebar.php MUSS mindestens einen i18n-Wrapper-Aufruf enthalten.'
        );

        $allowed = ["'spreadconnect-pod'", '"spreadconnect-pod"', 'self::TEXT_DOMAIN'];
        foreach ($matches[1] as $idx => $domainArg) {
            $domainArg = trim($domainArg);
            $ok = false;
            foreach ($allowed as $token) {
                if (str_contains($domainArg, $token)) {
                    $ok = true;
                    break;
                }
            }
            $this->assertTrue(
                $ok,
                sprintf(
                    'AC-9: Sidebar.php i18n-Wrapper #%d nutzt unerlaubte Text-Domain "%s". Match: %s',
                    $idx,
                    $domainArg,
                    $matches[0][$idx]
                )
            );
        }
    }

    /**
     * AC-9: Dashboard.php verwendet i18n-Wrapper mit Domain 'spreadconnect-pod'.
     */
    public function test_dashboard_source_uses_correct_text_domain(): void
    {
        $source = self::readSource(self::dashboardFile());

        if (preg_match("/private const TEXT_DOMAIN\\s*=\\s*'([^']+)'/", $source, $m)) {
            $this->assertSame(
                'spreadconnect-pod',
                $m[1],
                'AC-9: Dashboard::TEXT_DOMAIN-Konstante MUSS "spreadconnect-pod" sein.'
            );
        }

        $callPattern = '/(?:_e|__|esc_html__|esc_attr__)\s*\(\s*[^,()]+(?:\(\s*[^()]*\s*\))?\s*,\s*([^)]+)\)/';
        preg_match_all($callPattern, $source, $matches);

        $this->assertNotEmpty(
            $matches[0],
            'AC-9: Dashboard.php MUSS mindestens einen i18n-Wrapper-Aufruf enthalten.'
        );

        $allowed = ["'spreadconnect-pod'", '"spreadconnect-pod"', 'self::TEXT_DOMAIN'];
        foreach ($matches[1] as $idx => $domainArg) {
            $domainArg = trim($domainArg);
            $ok = false;
            foreach ($allowed as $token) {
                if (str_contains($domainArg, $token)) {
                    $ok = true;
                    break;
                }
            }
            $this->assertTrue(
                $ok,
                sprintf(
                    'AC-9: Dashboard.php i18n-Wrapper #%d nutzt unerlaubte Text-Domain "%s".',
                    $idx,
                    $domainArg
                )
            );
        }
    }

    /**
     * AC-9: Sidebar nutzt esc_url() fuer href-Output und esc_attr()/esc_html()
     * fuer Attribute/Inhalt. Wir spy'en die Funktionen und pruefen Aufrufe.
     */
    public function test_sidebar_render_calls_escape_helpers_for_outputs(): void
    {
        Monkey\Functions\when('admin_url')->returnArg(1);
        Monkey\Functions\when('add_query_arg')->alias(
            fn($k, $v, $url) => $url . '&' . $k . '=' . $v
        );
        Monkey\Functions\when('__')->returnArg(1);
        Monkey\Functions\when('esc_attr__')->returnArg(1);

        $escUrlCalls  = [];
        $escAttrCalls = [];
        $escHtmlCalls = [];

        Monkey\Functions\when('esc_url')->alias(function ($url) use (&$escUrlCalls) {
            $escUrlCalls[] = $url;
            return $url;
        });
        Monkey\Functions\when('esc_attr')->alias(function ($val) use (&$escAttrCalls) {
            $escAttrCalls[] = $val;
            return $val;
        });
        Monkey\Functions\when('esc_html')->alias(function ($val) use (&$escHtmlCalls) {
            $escHtmlCalls[] = $val;
            return $val;
        });

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Sidebar::render('dashboard');
        } finally {
            ob_end_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        // 8 hrefs -> mindestens 8 esc_url-Aufrufe.
        $this->assertGreaterThanOrEqual(
            8,
            count($escUrlCalls),
            sprintf(
                'AC-9: Sidebar MUSS esc_url() mindestens 8x aufrufen (eine URL pro Nav-Item). Gefunden: %d',
                count($escUrlCalls)
            )
        );

        // esc_attr fuer LI-Class (oder andere Attribute) — mindestens 8 (eine pro LI).
        $this->assertGreaterThanOrEqual(
            8,
            count($escAttrCalls),
            'AC-9: Sidebar MUSS esc_attr() fuer Attribute (z. B. <li class>) verwenden.'
        );

        // esc_html fuer Labels — 8 Labels.
        $this->assertGreaterThanOrEqual(
            8,
            count($escHtmlCalls),
            'AC-9: Sidebar MUSS esc_html() fuer User-facing Labels verwenden.'
        );
    }

    /**
     * AC-9: Dashboard nutzt esc_html()/esc_attr() fuer Inhalt/Attribute.
     */
    public function test_dashboard_render_calls_escape_helpers_for_outputs(): void
    {
        Monkey\Functions\when('__')->returnArg(1);
        Monkey\Functions\when('esc_html__')->returnArg(1);

        $escAttrCalls = [];
        $escHtmlCalls = [];

        Monkey\Functions\when('esc_attr')->alias(function ($val) use (&$escAttrCalls) {
            $escAttrCalls[] = $val;
            return $val;
        });
        Monkey\Functions\when('esc_html')->alias(function ($val) use (&$escHtmlCalls) {
            $escHtmlCalls[] = $val;
            return $val;
        });

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Dashboard::render();
        } finally {
            ob_end_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        // 5 Card-Slugs als Attribute -> mindestens 5 esc_attr.
        $this->assertGreaterThanOrEqual(
            5,
            count($escAttrCalls),
            'AC-9: Dashboard MUSS esc_attr() fuer Card-Slug-Klassen-Attribute verwenden.'
        );

        // 5 Card-Titel + 5 Placeholders -> mindestens 5 esc_html-Aufrufe
        // (esc_html__ wird separat gezaehlt, daher Mindestschwelle 5).
        $this->assertGreaterThanOrEqual(
            5,
            count($escHtmlCalls),
            'AC-9: Dashboard MUSS esc_html() fuer dynamische Inhalte verwenden.'
        );
    }

    /**
     * AC-9: Controller.php (dispatch) escaped section-Slug bevor er ihn
     * in HTML-Klassen-Attribute schreibt.
     */
    public function test_controller_source_escapes_section_slug_in_html(): void
    {
        $source = self::readSource(self::controllerFile());

        // Erwartet: `esc_attr( $slug )` o.ae. fuer den Wrapper-Class.
        $this->assertMatchesRegularExpression(
            '/esc_attr\s*\(\s*\$slug\s*\)/',
            $source,
            'AC-9: Controller MUSS esc_attr() auf $slug aufrufen, bevor er ihn in HTML-Output schreibt.'
        );
    }

    // =====================================================================
    // AC-10: ?section=settings dispatched zu Slice-11 Settings::render
    // =====================================================================

    /**
     * AC-10: GIVEN Slice 11 `Hub\View\Settings::render()` ist verfuegbar
     *        WHEN ?section=settings geroutet wird
     *        THEN wird genau diese Methode aufgerufen — keine Duplizierung,
     *             kein Wrapper.
     *
     * Wir verifizieren das ueber eine output-marker-basierte Strategie:
     * Settings::render() ruft current_user_can() auf (Slice-11 AC-9).
     * Im Capability-fail-Pfad wirft Settings::render() wp_die() — daher
     * koennen wir den Aufruf nachweisen, indem wir current_user_can()
     * fuer 'manage_woocommerce' true zurueckgeben (dispatch passt durch),
     * dann false (Settings::render() ruft erneut current_user_can und
     * wirft wp_die). Wenn beim zweiten Call wp_die() fliegt -> Settings::render
     * wurde tatsaechlich aufgerufen.
     */
    public function test_dispatch_settings_section_calls_slice_11_settings_render(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubUrlHelpers();
        self::stubSanitizeHelpers();

        // Settings::render ruft Settings-API-Funktionen auf — alle stub'en.
        Monkey\Functions\when('settings_fields')->justReturn(null);
        Monkey\Functions\when('do_settings_sections')->justReturn(null);
        Monkey\Functions\when('submit_button')->justReturn(null);
        Monkey\Functions\when('do_action')->justReturn(null);

        // Pruefe ob Settings::render() aufgerufen wird durch Capability-Spy:
        // ensureCapability ruft current_user_can EINMAL; Settings::render
        // ruft current_user_can ein ZWEITES Mal (Slice-11 AC-9).
        $capChecks = 0;
        Monkey\Functions\when('current_user_can')->alias(function ($cap) use (&$capChecks) {
            $capChecks++;
            return $cap === 'manage_woocommerce';
        });

        $_GET['section'] = 'settings';

        $initialObLevel = ob_get_level();
        ob_start();
        try {
            Controller::dispatch();
        } finally {
            $output = (string) ob_get_clean();
            while (ob_get_level() > $initialObLevel) {
                ob_end_clean();
            }
        }

        // Settings::render hat seinen eigenen current_user_can-Check (Slice-11
        // AC-9), daher MUSS current_user_can mindestens 2x aufgerufen worden
        // sein: 1x im Dispatcher (ensureCapability), 1x in Settings::render.
        $this->assertGreaterThanOrEqual(
            2,
            $capChecks,
            sprintf(
                'AC-10: Bei ?section=settings MUSS Slice-11-Settings::render() ausgefuehrt ' .
                'werden, was einen eigenen current_user_can()-Check enthaelt. Erwartet >= 2 ' .
                'Capability-Checks (1x Dispatcher, 1x Settings). Gefunden: %d',
                $capChecks
            )
        );

        // Stub-Views NICHT gerendert.
        foreach (self::stubRenderCounts() as $slug => $count) {
            $this->assertSame(
                0,
                $count,
                sprintf('AC-10: Stub-View "%s" darf NICHT gerendert werden bei ?section=settings.', $slug)
            );
        }
    }

    /**
     * AC-10: Sidebar wird vor View::render() ausgegeben — nicht innerhalb
     * der Settings-Page (kein Re-Render der Sidebar).
     *
     * Heuristik via Source-Analyse: dispatch() ruft Sidebar::render()
     * VOR ($fqcn)::render(). Wir verifizieren via Source-Analyse die
     * Reihenfolge im Controller.
     */
    public function test_dispatch_renders_sidebar_before_view(): void
    {
        $source = self::readSource(self::controllerFile());

        // Position des Sidebar-Aufrufs.
        $sidebarPos = false;
        if (preg_match('/Sidebar::render\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            $sidebarPos = $m[0][1];
        }

        // Position des View-Dispatch ($fqcn)::render() oder call_user_func.
        $viewPos = false;
        if (preg_match('/\(\s*\$fqcn\s*\)\s*::\s*render/', $source, $m, PREG_OFFSET_CAPTURE)) {
            $viewPos = $m[0][1];
        } elseif (preg_match('/call_user_func\s*\(\s*\[\s*\$fqcn/', $source, $m, PREG_OFFSET_CAPTURE)) {
            $viewPos = $m[0][1];
        }

        $this->assertNotFalse(
            $sidebarPos,
            'AC-10: Controller.php MUSS Sidebar::render() aufrufen.'
        );
        $this->assertNotFalse(
            $viewPos,
            'AC-10: Controller.php MUSS einen View-Dispatch via ($fqcn)::render() oder call_user_func enthalten.'
        );

        $this->assertLessThan(
            $viewPos,
            $sidebarPos,
            'AC-10: Sidebar::render() MUSS VOR der View::render()-Dispatch stehen, ' .
            'damit die Settings-Page (Slice 11) die Sidebar NICHT selber rendern muss.'
        );
    }

    /**
     * AC-10: Slice-11 Settings-Klasse ist die Klasse, auf die das
     * Routing zeigt — Settings.php aus Slice 11 bleibt unveraendert
     * (kein Wrapper, kein neuer Sidebar-Block).
     */
    public function test_settings_view_is_referenced_in_sections_map(): void
    {
        $source = self::readSource(self::controllerFile());

        // SECTIONS-Map referenziert Hub\View\Settings.
        $this->assertMatchesRegularExpression(
            '/[\'\"]settings[\'\"]\s*=>\s*Settings::class/',
            $source,
            'AC-10: SECTIONS-Map MUSS "settings" => Settings::class enthalten.'
        );

        // Use-Statement importiert Settings.
        $this->assertMatchesRegularExpression(
            '/use\s+SpreadconnectPod\\\\Hub\\\\View\\\\Settings\s*;/',
            $source,
            'AC-10: Controller MUSS Hub\\View\\Settings importieren.'
        );
    }

    // =====================================================================
    // Final-Class + Static-only Constraints (Spec "Technische Constraints")
    // =====================================================================

    /**
     * Constraint: Controller, Sidebar, Dashboard sind final class
     * mit ausschliesslich static Methoden.
     */
    public function test_hub_classes_are_final_with_static_methods(): void
    {
        foreach ([Controller::class, Sidebar::class, Dashboard::class] as $fqcn) {
            $reflection = new ReflectionClass($fqcn);
            $this->assertTrue(
                $reflection->isFinal(),
                sprintf('Constraint: %s MUSS final sein.', $fqcn)
            );

            foreach ($reflection->getMethods() as $method) {
                // Skip inherited methods from parent (none here, but safe).
                if ($method->getDeclaringClass()->getName() !== $fqcn) {
                    continue;
                }
                $this->assertTrue(
                    $method->isStatic(),
                    sprintf(
                        'Constraint: Methode %s::%s() MUSS static sein (stateless adapter).',
                        $fqcn,
                        $method->getName()
                    )
                );
            }
        }
    }

    /**
     * Constraint: Controller.php enthaelt strict_types-Declaration.
     */
    public function test_all_hub_files_declare_strict_types(): void
    {
        $files = [
            self::controllerFile(),
            self::sidebarFile(),
            self::dashboardFile(),
        ];

        foreach ($files as $file) {
            $source = self::readSource($file);
            $this->assertMatchesRegularExpression(
                '/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/',
                $source,
                sprintf('Constraint: %s MUSS declare(strict_types=1); enthalten.', basename($file))
            );
        }
    }
}
