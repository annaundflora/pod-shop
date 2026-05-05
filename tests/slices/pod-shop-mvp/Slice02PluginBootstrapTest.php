<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Slice 02 — Plugin-Bootstrap + PSR-4-Autoloader
 *
 * Filesystem-, JSON- und Reflection-Asserts gegen die Acceptance Criteria
 * der Slice-Spec `slice-02-plugin-bootstrap.md`.
 *
 * Mocking Strategy: `no_mocks` — keine HTTP-/DB-Aufrufe. Wir parsen die
 * Plugin-Hauptdatei statisch (Header + Guards + Bootstrap-Aufruf), validieren
 * die lokale composer.json strukturell, und nutzen Reflection auf
 * `\SpreadconnectPod\Bootstrap\Plugin` ueber den Root-Autoloader.
 *
 * AC-7 (Root composer test exit code) wird vom Orchestrator/Compliance-Gate
 * gemessen und ist hier NICHT abgedeckt.
 */
final class Slice02PluginBootstrapTest extends TestCase
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
     * Absoluter Pfad zur lokalen Plugin-composer.json.
     */
    private static function pluginComposerFile(): string
    {
        return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/composer.json';
    }

    /**
     * Absoluter Pfad zu uninstall.php.
     */
    private static function uninstallFile(): string
    {
        return self::repoRoot() . '/wordpress/plugins/spreadconnect-pod/uninstall.php';
    }

    /**
     * WordPress-konformes Header-Parsing analog `get_file_data()`.
     *
     * Liest nur die ersten 8 KiB (WP-Standard) und matcht jedes Feld via
     * `^[ \t/*#@]*Field:(.*)$` Regex (case-insensitive).
     *
     * @param array<string, string> $fields  Map von logischem Key -> Header-Field-Name.
     * @return array<string, string>          Map mit denselben Keys -> getrimmte Werte.
     */
    private static function parsePluginHeader(string $file, array $fields): array
    {
        $handle = fopen($file, 'rb');
        self::assertNotFalse($handle, 'Plugin-Hauptdatei muss lesbar sein.');

        $contents = (string) fread($handle, 8192);
        fclose($handle);

        // Normalisiere Line-Endings (CRLF -> LF), entspricht WP-Verhalten.
        $contents = str_replace("\r", "\n", $contents);

        $result = [];
        foreach ($fields as $logical => $headerName) {
            $pattern = '/^[ \t\/*#@]*' . preg_quote($headerName, '/') . ':(.*)$/mi';
            if (preg_match($pattern, $contents, $matches) === 1) {
                $result[$logical] = trim($matches[1]);
            } else {
                $result[$logical] = '';
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------
    // AC-1: GIVEN ein leeres Plugin-Verzeichnis nach slice-01-cleanup-v1
    //       WHEN Slice 02 abgeschlossen
    //       THEN existiert spreadconnect-pod.php mit WP-Plugin-Header,
    //            der mindestens enthaelt:
    //              Plugin Name, Version: 2.0.0, Requires PHP: 8.2,
    //              Requires at least: 6.4, Text Domain: spreadconnect-pod
    // -------------------------------------------------------------------
    public function test_main_plugin_file_has_valid_wp_header(): void
    {
        $file = self::pluginMainFile();

        $this->assertFileExists(
            $file,
            'AC-1: Plugin-Hauptdatei "spreadconnect-pod.php" muss existieren.'
        );

        $headers = self::parsePluginHeader($file, [
            'name'           => 'Plugin Name',
            'version'        => 'Version',
            'requires_php'   => 'Requires PHP',
            'requires_at'    => 'Requires at least',
            'text_domain'    => 'Text Domain',
        ]);

        $this->assertNotSame(
            '',
            $headers['name'],
            'AC-1: Header-Feld "Plugin Name" muss vorhanden und nicht leer sein.'
        );

        $this->assertSame(
            '2.0.0',
            $headers['version'],
            'AC-1: Header-Feld "Version" muss exakt "2.0.0" sein (v2-Greenfield).'
        );

        $this->assertSame(
            '8.2',
            $headers['requires_php'],
            'AC-1: Header-Feld "Requires PHP" muss exakt "8.2" sein.'
        );

        $this->assertSame(
            '6.4',
            $headers['requires_at'],
            'AC-1: Header-Feld "Requires at least" muss exakt "6.4" sein.'
        );

        $this->assertSame(
            'spreadconnect-pod',
            $headers['text_domain'],
            'AC-1: Header-Feld "Text Domain" muss exakt "spreadconnect-pod" sein.'
        );
    }

    // -------------------------------------------------------------------
    // AC-2 (Teil 1): GIVEN spreadconnect-pod.php
    //                WHEN ausserhalb von WordPress geladen
    //                THEN bricht sie mit ABSPATH-Guard ab.
    // -------------------------------------------------------------------
    public function test_main_plugin_file_has_abspath_guard(): void
    {
        $file = self::pluginMainFile();
        $this->assertFileExists($file, 'AC-2: Plugin-Hauptdatei muss existieren.');

        $contents = (string) file_get_contents($file);
        $this->assertNotSame('', $contents, 'AC-2: Plugin-Hauptdatei darf nicht leer sein.');

        // Toleranter Regex: erlaubt verschiedene Whitespace-Varianten und
        // den WP-Standard-Idiom mit `! defined(...)`.
        $pattern = '/if\s*\(\s*!\s*defined\s*\(\s*([\'"])ABSPATH\1\s*\)\s*\)\s*\{?\s*exit/';

        $this->assertMatchesRegularExpression(
            $pattern,
            $contents,
            'AC-2: Plugin-Hauptdatei muss den WP-Standard-Guard '
            . '`if ( ! defined( \'ABSPATH\' ) ) { exit; }` enthalten, '
            . 'damit direkter HTTP-Aufruf den Datei-Inhalt nicht ausfuehrt.'
        );
    }

    // -------------------------------------------------------------------
    // AC-2 (Teil 2): Bootstrap::init( __FILE__ ) wird aufgerufen,
    //                sofern der Guard passiert wurde.
    // -------------------------------------------------------------------
    public function test_main_plugin_file_calls_bootstrap_init(): void
    {
        $file = self::pluginMainFile();
        $this->assertFileExists($file, 'AC-2: Plugin-Hauptdatei muss existieren.');

        $contents = (string) file_get_contents($file);

        // Akzeptiere sowohl FQN-Schreibweise (\SpreadconnectPod\...) als auch
        // den use-importierten Namen `Plugin::init( __FILE__ )` — beide sind
        // semantisch aequivalent (aktuell: FQN-Schreibweise im Plugin-File).
        $patternFqn = '/\\\\?SpreadconnectPod\\\\Bootstrap\\\\Plugin::init\s*\(\s*__FILE__\s*\)\s*;/';
        $patternShort = '/(^|[^\\w\\\\])Plugin::init\s*\(\s*__FILE__\s*\)\s*;/m';

        $matchedFqn   = preg_match($patternFqn, $contents) === 1;
        $matchedShort = preg_match($patternShort, $contents) === 1;

        $this->assertTrue(
            $matchedFqn || $matchedShort,
            'AC-2: Plugin-Hauptdatei muss `\\SpreadconnectPod\\Bootstrap\\Plugin::init( __FILE__ )` '
            . '(oder das via use importierte `Plugin::init( __FILE__ )`) genau einmal aufrufen.'
        );

        // Genau einmal: Summe der Treffer beider Patterns muss exakt 1 sein.
        $countFqn   = preg_match_all($patternFqn, $contents) ?: 0;
        $countShort = preg_match_all($patternShort, $contents) ?: 0;

        $this->assertSame(
            1,
            $countFqn + $countShort,
            'AC-2: `Plugin::init( __FILE__ )` muss genau einmal in der Plugin-Hauptdatei '
            . 'aufgerufen werden — Doppelaufrufe gehoeren in die Idempotenz-Tests von AC-5.'
        );
    }

    // -------------------------------------------------------------------
    // AC-3 (Teil 1): Lokale composer.json hat PSR-4-Mapping
    //                "SpreadconnectPod\\": "includes/", "name", "type": "wordpress-plugin".
    // -------------------------------------------------------------------
    public function test_plugin_composer_json_has_psr4_mapping(): void
    {
        $file = self::pluginComposerFile();

        $this->assertFileExists(
            $file,
            'AC-3: Lokale Plugin-composer.json muss existieren.'
        );

        $raw = file_get_contents($file);
        $this->assertNotFalse($raw, 'AC-3: composer.json muss lesbar sein.');

        /** @var array<string, mixed>|null $config */
        $config = json_decode($raw, true);
        $this->assertIsArray(
            $config,
            'AC-3: composer.json muss valides JSON sein.'
        );

        // name (z. B. "pod-shop/spreadconnect-pod")
        $this->assertArrayHasKey(
            'name',
            $config,
            'AC-3: composer.json muss einen "name"-Eintrag haben.'
        );
        $this->assertIsString($config['name']);
        $this->assertNotSame(
            '',
            $config['name'],
            'AC-3: composer.json "name" darf nicht leer sein.'
        );
        $this->assertMatchesRegularExpression(
            '#^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$#',
            $config['name'],
            'AC-3: composer.json "name" muss dem Composer-Format <vendor>/<package> folgen '
            . '(z. B. "pod-shop/spreadconnect-pod").'
        );

        // type: wordpress-plugin
        $this->assertArrayHasKey(
            'type',
            $config,
            'AC-3: composer.json muss "type" enthalten.'
        );
        $this->assertSame(
            'wordpress-plugin',
            $config['type'],
            'AC-3: composer.json "type" muss "wordpress-plugin" sein.'
        );

        // PSR-4: SpreadconnectPod\\ -> includes/
        $this->assertArrayHasKey(
            'autoload',
            $config,
            'AC-3: composer.json muss "autoload"-Section enthalten.'
        );
        $this->assertIsArray($config['autoload']);
        $this->assertArrayHasKey(
            'psr-4',
            $config['autoload'],
            'AC-3: composer.json autoload muss "psr-4"-Mapping enthalten.'
        );
        $this->assertIsArray($config['autoload']['psr-4']);

        $psr4 = $config['autoload']['psr-4'];

        $this->assertArrayHasKey(
            'SpreadconnectPod\\',
            $psr4,
            'AC-3: PSR-4-Key "SpreadconnectPod\\\\" muss im Mapping vorhanden sein.'
        );
        $this->assertSame(
            'includes/',
            $psr4['SpreadconnectPod\\'],
            'AC-3: PSR-4-Mapping muss "SpreadconnectPod\\\\" -> "includes/" sein '
            . '(plugin-relativ; Repo-Root nutzt eigenes absolutes Mapping).'
        );
    }

    // -------------------------------------------------------------------
    // AC-3 (Teil 2): Lokale composer.json darf KEINE Test-Dev-Dependencies
    //                enthalten — phpunit/phpunit, brain/monkey leben
    //                ausschliesslich in der Root-composer.json (REUSE).
    // -------------------------------------------------------------------
    public function test_plugin_composer_json_has_no_test_dev_deps(): void
    {
        $file = self::pluginComposerFile();
        $this->assertFileExists($file, 'AC-3: Lokale Plugin-composer.json muss existieren.');

        $raw = file_get_contents($file);
        $this->assertNotFalse($raw, 'AC-3: composer.json muss lesbar sein.');

        /** @var array<string, mixed>|null $config */
        $config = json_decode($raw, true);
        $this->assertIsArray($config, 'AC-3: composer.json muss valides JSON sein.');

        // Constraint laut Spec: keinerlei `require-dev`-Eintraege fuer
        // Test-Dependencies. Wir interpretieren das streng: entweder
        // "require-dev" fehlt komplett, oder es enthaelt jedenfalls KEINE
        // bekannten Test-Pakete.
        if (array_key_exists('require-dev', $config)) {
            $this->assertIsArray(
                $config['require-dev'],
                'AC-3: Falls "require-dev" gesetzt ist, muss es ein Object sein.'
            );

            $forbidden = ['phpunit/phpunit', 'brain/monkey'];
            foreach ($forbidden as $package) {
                $this->assertArrayNotHasKey(
                    $package,
                    $config['require-dev'],
                    sprintf(
                        'AC-3: Lokale composer.json darf "%s" NICHT als require-dev haben — '
                        . 'Tests laufen ueber Root-composer.json (REUSE).',
                        $package
                    )
                );
            }
        } else {
            // Bevorzugter Zustand laut Spec: kein `require-dev` ueberhaupt.
            $this->assertArrayNotHasKey(
                'require-dev',
                $config,
                'AC-3: Lokale composer.json sollte gar kein "require-dev" enthalten '
                . '(Tests via Root-composer.json).'
            );
        }
    }

    // -------------------------------------------------------------------
    // AC-4 (Teil 1): Bootstrap\Plugin::class ist via Root-Autoloader
    //                resolvable und liegt unter includes/Bootstrap/Plugin.php.
    // -------------------------------------------------------------------
    public function test_bootstrap_plugin_class_is_autoloadable(): void
    {
        // Datei am erwarteten PSR-4-Pfad existiert?
        $expectedFile = self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php';
        $this->assertFileExists(
            $expectedFile,
            'AC-4: Bootstrap-Klassen-Datei muss am PSR-4-erwarteten Pfad liegen: '
            . 'includes/Bootstrap/Plugin.php (Case-Sensitiv).'
        );

        // Klasse via Root-Autoloader resolvable?
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $this->assertTrue(
            class_exists($fqcn),
            'AC-4: Klasse "' . $fqcn . '" muss via Root-Autoloader (vendor/autoload.php) '
            . 'resolvable sein. Falls nicht, fehlt entweder die Datei oder das PSR-4-Mapping '
            . 'wurde nach Slice 01 nicht via "composer dump-autoload" neu generiert.'
        );

        // Reflection: Datei-Pfad der geladenen Klasse passt zur Erwartung.
        $reflection = new ReflectionClass($fqcn);
        $loadedFile = $reflection->getFileName();
        $this->assertNotFalse(
            $loadedFile,
            'AC-4: Reflection muss einen File-Pfad fuer die Klasse liefern.'
        );

        $this->assertSame(
            realpath($expectedFile),
            realpath($loadedFile),
            'AC-4: Autoloader muss exakt die Datei "includes/Bootstrap/Plugin.php" laden — '
            . 'kein verwaister v1-Rest, kein anderer Pfad.'
        );

        // final class-Constraint (Spec: "Bootstrap-Klasse als final class markieren").
        $this->assertTrue(
            $reflection->isFinal(),
            'AC-4 / Constraint: Bootstrap-Klasse muss als "final class" markiert sein '
            . '(Service-Wiring kommt in Folge-Slices via Container).'
        );
    }

    // -------------------------------------------------------------------
    // AC-4 (Teil 2): Plugin::init() Signatur — public static, string $plugin_file -> void.
    //                Plugin::pluginFile() Signatur — public static, () -> string.
    // -------------------------------------------------------------------
    public function test_bootstrap_plugin_init_method_signature(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $this->assertTrue(class_exists($fqcn), 'AC-4: Bootstrap-Klasse muss autoloadbar sein.');

        $reflection = new ReflectionClass($fqcn);

        // ---------- init() ----------
        $this->assertTrue(
            $reflection->hasMethod('init'),
            'AC-4: Bootstrap\\Plugin muss eine Methode "init" haben.'
        );

        $init = $reflection->getMethod('init');
        $this->assertTrue($init->isPublic(), 'AC-4: init() muss public sein.');
        $this->assertTrue($init->isStatic(), 'AC-4: init() muss static sein.');

        $params = $init->getParameters();
        $this->assertCount(
            1,
            $params,
            'AC-4: init() muss genau einen Parameter haben ($plugin_file).'
        );

        $param = $params[0];
        $this->assertSame(
            'plugin_file',
            $param->getName(),
            'AC-4: init()-Parameter muss "$plugin_file" heissen.'
        );
        $this->assertTrue(
            $param->hasType(),
            'AC-4: init()-Parameter muss einen Type-Hint haben.'
        );

        $paramType = $param->getType();
        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $paramType,
            'AC-4: init()-Parameter-Type muss ein ReflectionNamedType sein (kein Union).'
        );
        $this->assertSame(
            'string',
            $paramType->getName(),
            'AC-4: init()-Parameter $plugin_file muss als string typisiert sein.'
        );

        // Return type: void
        $this->assertTrue(
            $init->hasReturnType(),
            'AC-4: init() muss einen Return-Type haben.'
        );
        $returnType = $init->getReturnType();
        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $returnType,
            'AC-4: init()-Return-Type muss ein ReflectionNamedType sein.'
        );
        $this->assertSame(
            'void',
            $returnType->getName(),
            'AC-4: init() muss "void" zurueckgeben.'
        );

        // ---------- pluginFile() ----------
        $this->assertTrue(
            $reflection->hasMethod('pluginFile'),
            'AC-4: Bootstrap\\Plugin muss einen "pluginFile()"-Getter exponieren '
            . '(wird ab Slice 03 fuer FeaturesUtil::declare_compatibility und '
            . 'plugin_basename() benoetigt).'
        );

        $getter = $reflection->getMethod('pluginFile');
        $this->assertTrue($getter->isPublic(), 'AC-4: pluginFile() muss public sein.');
        $this->assertTrue($getter->isStatic(), 'AC-4: pluginFile() muss static sein.');
        $this->assertCount(
            0,
            $getter->getParameters(),
            'AC-4: pluginFile() darf keine Parameter haben.'
        );

        $this->assertTrue(
            $getter->hasReturnType(),
            'AC-4: pluginFile() muss einen Return-Type haben.'
        );
        $getterReturn = $getter->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $getterReturn);
        $this->assertSame(
            'string',
            $getterReturn->getName(),
            'AC-4: pluginFile() muss "string" zurueckgeben.'
        );
    }

    // -------------------------------------------------------------------
    // AC-5: GIVEN Plugin::init()
    //       WHEN zweimal mit demselben $plugin_file aufgerufen
    //       THEN idempotent: kein Throw, keine doppelte State-Mutation;
    //            pluginFile() returns gleichen Wert.
    //
    // WP-Mocking: dieser eine Test ruft Plugin::init() direkt auf, das ab
    // Slice 03 `add_action()` registriert. Da PHPUnit kein WordPress lädt,
    // stubben wir die WP-Funktion lokal via Brain\Monkey in setUp/tearDown
    // dieses einzelnen Tests — die anderen 9 Tests in dieser Datei rufen
    // init() NICHT auf und brauchen kein Mocking. Folge-Slices (04/05/06),
    // die ebenfalls init() direkt testen, replizieren dieses Muster.
    // -------------------------------------------------------------------
    public function test_bootstrap_plugin_init_is_idempotent(): void
    {
        \Brain\Monkey\setUp();

        try {
            // Stub `add_action` als No-Op. Slice 03 ruft es einmal pro
            // init() auf; der Idempotenz-Guard verhindert, dass es bei
            // Re-Entry erneut aufgerufen wird. Andere WP-Funktionen
            // werden in init() (Stand Slice 03) NICHT aufgerufen.
            \Brain\Monkey\Functions\stubs([
                'add_action' => null,
            ]);

            $fqcn = 'SpreadconnectPod\\Bootstrap\\Plugin';
            $this->assertTrue(class_exists($fqcn), 'AC-5: Bootstrap-Klasse muss autoloadbar sein.');

            // Reset internen State, falls ein vorausgehender Test in dieser
            // PHPUnit-Run-Session bereits init() aufgerufen hat. Tests duerfen
            // einander nicht beeinflussen — der idempotenz-Guard wird vom
            // `private static $initialized = false` gesteuert.
            $reflection = new ReflectionClass($fqcn);
            if ($reflection->hasProperty('initialized')) {
                $initProp = $reflection->getProperty('initialized');
                $initProp->setValue(null, false);
            }
            if ($reflection->hasProperty('pluginFile')) {
                $fileProp = $reflection->getProperty('pluginFile');
                $fileProp->setValue(null, '');
            }

            $pluginFile = self::pluginMainFile();

            // Erster Aufruf: setzt $initialized=true und merkt sich $pluginFile.
            try {
                $fqcn::init($pluginFile);
            } catch (\Throwable $e) {
                $this->fail(
                    'AC-5: Erster init()-Aufruf darf nicht werfen, warf jedoch: '
                    . $e::class . ' — ' . $e->getMessage()
                );
            }

            $this->assertSame(
                $pluginFile,
                $fqcn::pluginFile(),
                'AC-5: Nach dem ersten init() muss pluginFile() den uebergebenen Pfad zurueckgeben.'
            );

            // Zweiter Aufruf mit demselben Pfad — MUSS Re-Entry-Guard triggern,
            // darf weder werfen noch State doppelt mutieren.
            try {
                $fqcn::init($pluginFile);
            } catch (\Throwable $e) {
                $this->fail(
                    'AC-5: Zweiter init()-Aufruf (gleicher $plugin_file) muss No-Op sein, '
                    . 'warf jedoch: ' . $e::class . ' — ' . $e->getMessage()
                );
            }

            $this->assertSame(
                $pluginFile,
                $fqcn::pluginFile(),
                'AC-5: pluginFile() muss nach Doppelaufruf identisch bleiben (kein Drift).'
            );

            // Adversarial: dritter Aufruf mit ANDEREM Pfad — Idempotenz-Guard
            // muss den Wert NICHT ueberschreiben (sonst waere "init" nicht
            // wirklich idempotent, sondern wuerde State leise mutieren).
            try {
                $fqcn::init('/dev/null/another-plugin-path.php');
            } catch (\Throwable $e) {
                $this->fail(
                    'AC-5: Re-Entry mit anderem Pfad darf ebenfalls nicht werfen, '
                    . 'warf jedoch: ' . $e::class . ' — ' . $e->getMessage()
                );
            }

            $this->assertSame(
                $pluginFile,
                $fqcn::pluginFile(),
                'AC-5: Idempotenz-Guard MUSS State-Mutation bei Re-Entry verhindern — '
                . 'pluginFile() darf den ersten Pfad nicht ueberschreiben.'
            );
        } finally {
            \Brain\Monkey\tearDown();
        }
    }

    // -------------------------------------------------------------------
    // AC-6 (Teil 1): uninstall.php hat WP_UNINSTALL_PLUGIN-Guard.
    // -------------------------------------------------------------------
    public function test_uninstall_php_has_unsinstall_guard(): void
    {
        $file = self::uninstallFile();
        $this->assertFileExists(
            $file,
            'AC-6: uninstall.php muss als Stub-Datei vorhanden sein.'
        );

        // Datei muss valider PHP-Code sein (keine Parse-Errors).
        // `php -l` ist hier die zuverlaessige, prozessfreie Variante:
        // wir parsen den Inhalt mit `token_get_all()` und scheitern bei
        // Syntax-Fehlern.
        $contents = (string) file_get_contents($file);
        $this->assertNotSame('', $contents, 'AC-6: uninstall.php darf nicht leer sein.');

        try {
            $tokens = @token_get_all($contents, TOKEN_PARSE);
            $this->assertIsArray(
                $tokens,
                'AC-6: uninstall.php muss als gueltiger PHP-Code parsen.'
            );
        } catch (\ParseError $e) {
            $this->fail('AC-6: uninstall.php hat einen Parse-Error: ' . $e->getMessage());
        }

        // Der WP_UNINSTALL_PLUGIN-Guard (analog zu ABSPATH).
        $pattern = '/if\s*\(\s*!\s*defined\s*\(\s*([\'"])WP_UNINSTALL_PLUGIN\1\s*\)\s*\)\s*\{?\s*exit/';
        $this->assertMatchesRegularExpression(
            $pattern,
            $contents,
            'AC-6: uninstall.php muss den WP-Standard-Guard '
            . '`if ( ! defined( \'WP_UNINSTALL_PLUGIN\' ) ) { exit; }` enthalten.'
        );
    }

    // -------------------------------------------------------------------
    // AC-6 (Teil 2): uninstall.php enthaelt KEINE DB-Mutationen
    //                (Schema-Drop kommt in Slice 04).
    // -------------------------------------------------------------------
    public function test_uninstall_php_has_no_db_mutations(): void
    {
        $file = self::uninstallFile();
        $this->assertFileExists($file, 'AC-6: uninstall.php muss existieren.');

        $contents = (string) file_get_contents($file);

        // Strip block- und line-comments — Kommentare wie
        // "Schema cleanup added in Slice 04 (Bootstrap\Schema::uninstall())"
        // sind erlaubt und duerfen unsere Regex nicht triggern.
        $tokens   = token_get_all($contents);
        $stripped = '';
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $stripped .= $token[1];
            } else {
                $stripped .= $token;
            }
        }

        // Liste verbotener DB-Indikatoren. Schema-Aenderungen gehoeren
        // nach Slice 04 (Bootstrap\Schema::uninstall()).
        $forbiddenPatterns = [
            'global $wpdb'                  => '/global\s+\$wpdb\b/i',
            '$wpdb-> usage'                 => '/\$wpdb\s*->/i',
            'dbDelta()'                     => '/\bdbDelta\s*\(/i',
            'WP_Query'                      => '/\bnew\s+WP_Query\b|\bWP_Query\s*::/i',
            'wp_query() helper'             => '/\bwp_query\s*\(/i',
            'delete_option()'               => '/\bdelete_option\s*\(/i',
            'delete_metadata()'             => '/\bdelete_metadata\s*\(/i',
            'delete_post_meta()'            => '/\bdelete_post_meta\s*\(/i',
            'delete_user_meta()'            => '/\bdelete_user_meta\s*\(/i',
            'wp_delete_post()'              => '/\bwp_delete_post\s*\(/i',
            'wp_delete_term()'              => '/\bwp_delete_term\s*\(/i',
            'wp_delete_user()'              => '/\bwp_delete_user\s*\(/i',
            'DROP/TRUNCATE/DELETE SQL'      => '/\b(DROP\s+TABLE|TRUNCATE\s+TABLE|DELETE\s+FROM)\b/i',
        ];

        foreach ($forbiddenPatterns as $label => $pattern) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $stripped,
                sprintf(
                    'AC-6: uninstall.php darf KEINE DB-Mutation enthalten — '
                    . 'verbotenes Pattern "%s" in Slice 02 erkannt. '
                    . 'Schema-Drop kommt in Slice 04 (Bootstrap\\Schema::uninstall()).',
                    $label
                )
            );
        }
    }
}
