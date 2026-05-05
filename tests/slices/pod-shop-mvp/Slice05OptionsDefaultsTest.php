<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Slice 05 — Default-Options-Setter
 *
 * Acceptance Tests gegen die Slice-Spec `slice-05-options-defaults.md`.
 *
 * Mocking Strategy: `mock_external` (laut Slice-Spec):
 *   - Brain\Monkey fuer `add_option`, `update_option`, `register_activation_hook`
 *     mit in-memory Options-Map zur Idempotenz-Verifikation.
 *   - Statische Source-Analyse fuer DEFAULTS-Konstante (AC-5).
 *
 * Strategie:
 *   - AC-1: Dynamic — install() aufrufen + add_option-Spy pruefen (18 Keys mit
 *           passenden Type/Werten gemaess architecture.md Z. 323-341).
 *   - AC-2: Dynamic — Options-Map vor 2. install()-Aufruf vorbefuellen,
 *           assert dass kein update_option getriggert + Wert unveraendert.
 *   - AC-3: Dynamic — webhook_secret-Key wird im add_option-Spy NICHT gesehen.
 *   - AC-4: Dynamic — Plugin::init() registriert register_activation_hook fuer
 *           [OptionsDefaults::class, 'install'] genau einmal pro init()-Lauf.
 *   - AC-5: Statische Source-Analyse — DEFAULTS-Konstante existiert,
 *           install()-Body enthaelt EINEN add_option-Aufruf in einer Schleife.
 *   - AC-6: Statische Pruefung der DEFAULTS-Konstante gegen Validation-Rules
 *           (Enum-Membership, Range-Checks).
 */
final class Slice05OptionsDefaultsTest extends TestCase
{
    /**
     * Repo-Root: drei Verzeichnisse oberhalb von `tests/slices/pod-shop-mvp/`.
     */
    private static function repoRoot(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }

    /**
     * Absoluter Pfad zur OptionsDefaults.php.
     */
    private static function optionsDefaultsClassFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/includes/Bootstrap/OptionsDefaults.php';
    }

    /**
     * Absoluter Pfad zur Plugin.php.
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
     * Source-Inhalt der OptionsDefaults.php (cached pro Test).
     */
    private static function optionsDefaultsSource(): string
    {
        $file = self::optionsDefaultsClassFile();
        self::assertFileExists($file, 'OptionsDefaults.php muss existieren.');
        $contents = (string) file_get_contents($file);
        self::assertNotSame('', $contents, 'OptionsDefaults.php darf nicht leer sein.');
        return $contents;
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
     * Erwartete Defaults-Tabelle gemaess architecture.md Z. 323-341
     * (ohne `spreadconnect_webhook_secret`).
     *
     * @return array<string, string|int|bool>
     */
    private static function expectedDefaults(): array
    {
        return [
            'spreadconnect_api_key'                    => '',
            'spreadconnect_use_staging'                => false,
            'spreadconnect_auto_confirm'               => 'off',
            'spreadconnect_auto_confirm_minutes'       => 0,
            'spreadconnect_default_shipping_type'      => '',
            'spreadconnect_auto_cancel_mirror'         => true,
            'spreadconnect_pull_images'                => true,
            'spreadconnect_force_repull_images'        => false,
            'spreadconnect_stock_sync_interval'        => '6h',
            'spreadconnect_low_stock_threshold'        => 10,
            'spreadconnect_live_cache_ttl_seconds'     => 300,
            'spreadconnect_notify_emails'              => '',
            'spreadconnect_notify_on_order_failure'    => true,
            'spreadconnect_notify_on_sync_failure'     => true,
            'spreadconnect_notify_on_webhook_failure'  => false,
            'spreadconnect_failed_ops_retention_days'  => 90,
            'spreadconnect_webhook_log_retention_days' => 90,
            'spreadconnect_pt_index_etag'              => '',
        ];
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
    // AC-1: GIVEN ein leeres `wp_options`-Set ohne `spreadconnect_*`-Eintraege
    //       WHEN der Plugin-Activate-Hook ausgeloest wird
    //       THEN existieren danach genau die 18 Default-Options mit
    //            ihren architecture.md-Defaults (Type-korrekt:
    //            string/bool/int).
    // ===================================================================

    /**
     * AC-1: install() ruft add_option() fuer GENAU 18 Keys auf —
     * jeder Key/Value entspricht der architecture.md-Tabelle.
     */
    public function test_install_sets_all_discovery_defaults_on_empty_options(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\OptionsDefaults';
        $this->assertTrue(class_exists($fqcn), 'AC-1: OptionsDefaults muss autoloadbar sein.');

        // In-memory Options-Map.
        $options = [];

        // add_option-Spy: schreibt nur, wenn Key noch nicht existiert (WP-Semantik).
        Monkey\Functions\when('add_option')->alias(function ($key, $value = '', $deprecated = '', $autoload = 'yes') use (&$options) {
            if (array_key_exists($key, $options)) {
                return false;
            }
            $options[$key] = $value;
            return true;
        });

        // update_option soll in dieser Slice NIE gerufen werden.
        $updateCalls = [];
        Monkey\Functions\when('update_option')->alias(function ($key, $value, $autoload = null) use (&$updateCalls) {
            $updateCalls[] = [$key, $value];
            return true;
        });

        $fqcn::install();

        $this->assertSame(
            [],
            $updateCalls,
            'AC-1 / Constraint: install() darf KEIN update_option() aufrufen — '
            . 'Re-Activate wuerde sonst Admin-Werte ueberschreiben (siehe AC-2).'
        );

        $this->assertCount(
            18,
            $options,
            sprintf(
                'AC-1: install() MUSS GENAU 18 Options setzen (architecture.md Z. 323-341 '
                . 'minus spreadconnect_webhook_secret). Tatsaechlich gesetzt: %d. Keys: %s',
                count($options),
                implode(', ', array_keys($options))
            )
        );

        $expected = self::expectedDefaults();

        // Jeder erwartete Key existiert mit dem dokumentierten Default-Wert.
        foreach ($expected as $key => $expectedValue) {
            $this->assertArrayHasKey(
                $key,
                $options,
                sprintf('AC-1: Default-Option "%s" fehlt — architecture.md verlangt diesen Key.', $key)
            );

            $this->assertSame(
                $expectedValue,
                $options[$key],
                sprintf(
                    'AC-1: Default fuer "%s" muss exakt %s (Type %s) sein, war %s (Type %s).',
                    $key,
                    var_export($expectedValue, true),
                    gettype($expectedValue),
                    var_export($options[$key], true),
                    gettype($options[$key])
                )
            );
        }

        // Keine zusaetzlichen Keys.
        $extras = array_diff(array_keys($options), array_keys($expected));
        $this->assertEmpty(
            $extras,
            'AC-1: install() darf KEINE zusaetzlichen Keys setzen. Unerwartet: '
            . implode(', ', $extras)
        );
    }

    /**
     * AC-1: Type-Korrektheit pro Default — bool als bool, int als int,
     * string als string.
     */
    public function test_default_values_have_correct_php_types(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\OptionsDefaults';
        $this->assertTrue(class_exists($fqcn), 'AC-1: OptionsDefaults muss autoloadbar sein.');

        $observedTypes = [];
        Monkey\Functions\when('add_option')->alias(function ($key, $value = '', $deprecated = '', $autoload = 'yes') use (&$observedTypes) {
            $observedTypes[$key] = $value;
            return true;
        });

        $fqcn::install();

        // Bool-Defaults — MUSS PHP-bool sein, nicht "false"/"true"-String.
        $boolKeys = [
            'spreadconnect_use_staging'                => false,
            'spreadconnect_auto_cancel_mirror'         => true,
            'spreadconnect_pull_images'                => true,
            'spreadconnect_force_repull_images'        => false,
            'spreadconnect_notify_on_order_failure'    => true,
            'spreadconnect_notify_on_sync_failure'     => true,
            'spreadconnect_notify_on_webhook_failure'  => false,
        ];
        foreach ($boolKeys as $key => $expected) {
            $this->assertArrayHasKey($key, $observedTypes, sprintf('AC-1: Bool-Default "%s" fehlt.', $key));
            $this->assertIsBool(
                $observedTypes[$key],
                sprintf(
                    'AC-1: "%s" MUSS PHP-bool sein (kein "false"/"true"-String). Gefunden: %s',
                    $key,
                    gettype($observedTypes[$key])
                )
            );
            $this->assertSame($expected, $observedTypes[$key]);
        }

        // Int-Defaults — MUSS PHP-int sein.
        $intKeys = [
            'spreadconnect_auto_confirm_minutes'       => 0,
            'spreadconnect_low_stock_threshold'        => 10,
            'spreadconnect_live_cache_ttl_seconds'     => 300,
            'spreadconnect_failed_ops_retention_days'  => 90,
            'spreadconnect_webhook_log_retention_days' => 90,
        ];
        foreach ($intKeys as $key => $expected) {
            $this->assertArrayHasKey($key, $observedTypes, sprintf('AC-1: Int-Default "%s" fehlt.', $key));
            $this->assertIsInt(
                $observedTypes[$key],
                sprintf('AC-1: "%s" MUSS PHP-int sein. Gefunden: %s', $key, gettype($observedTypes[$key]))
            );
            $this->assertSame($expected, $observedTypes[$key]);
        }

        // String-Defaults — MUSS PHP-string sein.
        $stringKeys = [
            'spreadconnect_api_key'                => '',
            'spreadconnect_auto_confirm'           => 'off',
            'spreadconnect_default_shipping_type'  => '',
            'spreadconnect_stock_sync_interval'    => '6h',
            'spreadconnect_notify_emails'          => '',
            'spreadconnect_pt_index_etag'          => '',
        ];
        foreach ($stringKeys as $key => $expected) {
            $this->assertArrayHasKey($key, $observedTypes, sprintf('AC-1: String-Default "%s" fehlt.', $key));
            $this->assertIsString(
                $observedTypes[$key],
                sprintf('AC-1: "%s" MUSS PHP-string sein. Gefunden: %s', $key, gettype($observedTypes[$key]))
            );
            $this->assertSame($expected, $observedTypes[$key]);
        }
    }

    /**
     * AC-1 (Signatur): install() ist public static, () -> void; OptionsDefaults
     * ist final class.
     */
    public function test_options_defaults_class_signature(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\OptionsDefaults';
        $this->assertTrue(class_exists($fqcn), 'AC-1: OptionsDefaults muss autoloadbar sein.');

        $reflection = new ReflectionClass($fqcn);
        $this->assertTrue(
            $reflection->isFinal(),
            'AC-1 / Constraint: OptionsDefaults MUSS final class sein.'
        );

        $this->assertTrue($reflection->hasMethod('install'), 'AC-1: install()-Methode muss existieren.');

        $method = $reflection->getMethod('install');
        $this->assertTrue($method->isPublic(), 'AC-1: install() muss public sein.');
        $this->assertTrue($method->isStatic(), 'AC-1: install() muss static sein.');
        $this->assertCount(0, $method->getParameters(), 'AC-1: install() darf keine Parameter haben.');

        $returnType = $method->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame('void', $returnType->getName(), 'AC-1: install() return type ist void.');
    }

    // ===================================================================
    // AC-2: GIVEN eine bereits gesetzte Option (Admin-customized)
    //       WHEN Plugin-Activate ein zweites Mal feuert
    //       THEN bleibt der Admin-Wert erhalten — kein update_option()-Call
    //            fuer diese Option.
    // ===================================================================

    /**
     * AC-2: Re-Activate ueberschreibt admin-customized Options nicht —
     * add_option() ist no-op, kein update_option() wird gerufen.
     */
    public function test_reactivate_preserves_admin_customized_option_value(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\OptionsDefaults';
        $this->assertTrue(class_exists($fqcn), 'AC-2: OptionsDefaults muss autoloadbar sein.');

        // In-memory Options-Map mit pre-existing Admin-Wert.
        $options = [
            'spreadconnect_low_stock_threshold' => 25,  // Admin hat geaendert.
        ];

        Monkey\Functions\when('add_option')->alias(function ($key, $value = '', $deprecated = '', $autoload = 'yes') use (&$options) {
            if (array_key_exists($key, $options)) {
                return false;
            }
            $options[$key] = $value;
            return true;
        });

        $updateCalls = [];
        Monkey\Functions\when('update_option')->alias(function ($key, $value, $autoload = null) use (&$updateCalls) {
            $updateCalls[] = [$key, $value];
            return true;
        });

        // Erster Activate (mit pre-existing Wert).
        $fqcn::install();

        $this->assertSame(
            25,
            $options['spreadconnect_low_stock_threshold'],
            'AC-2: 1. install() darf den Admin-Wert (25) NICHT ueberschreiben.'
        );

        // Zweiter Activate (Re-Aktivierung).
        $fqcn::install();

        $this->assertSame(
            25,
            $options['spreadconnect_low_stock_threshold'],
            'AC-2: 2. install() (Re-Aktivierung) darf den Admin-Wert (25) NICHT ueberschreiben.'
        );

        // Andere Defaults sind nach 1./2. Run vorhanden.
        $this->assertArrayHasKey(
            'spreadconnect_use_staging',
            $options,
            'AC-2: Andere Defaults werden trotz pre-existing Admin-Wert weiterhin gesetzt.'
        );

        // KEIN update_option-Call.
        $this->assertSame(
            [],
            $updateCalls,
            'AC-2: install() darf NIEMALS update_option() aufrufen — '
            . 'sonst wuerden Admin-Werte stillschweigend ueberschrieben. '
            . 'Beobachtete update_option-Calls: ' . print_r($updateCalls, true)
        );
    }

    /**
     * AC-2 / Constraint: install()-Source enthaelt KEINEN update_option-Aufruf
     * (nach Stripping von Doc/Line-Comments — Dokumentation wie
     * "update_option() is deliberately NOT used here" muss erlaubt sein).
     */
    public function test_install_source_contains_no_update_option_call(): void
    {
        $source = self::optionsDefaultsSource();

        // Strip block- und line-comments — Kommentare/Docstrings duerfen das
        // Wort "update_option" als Erlaeuterung enthalten.
        $tokens   = token_get_all($source);
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

        $this->assertDoesNotMatchRegularExpression(
            '/\bupdate_option\s*\(/',
            $stripped,
            'AC-2 / Constraint: OptionsDefaults.php darf KEINEN update_option()-Aufruf enthalten — '
            . 'das wuerde Admin-Werte ueberschreiben. Erlaubt ist nur add_option() (idempotent).'
        );
    }

    // ===================================================================
    // AC-3: GIVEN die spezielle Option `spreadconnect_webhook_secret`
    //       WHEN Activate auf leerem Options-Set feuert
    //       THEN wird sie NICHT in dieser Slice gesetzt — Generation
    //            gehoert zu slice-14-webhook-secret-manager.
    // ===================================================================

    /**
     * AC-3: install() ruft add_option NICHT fuer spreadconnect_webhook_secret.
     */
    public function test_install_does_not_set_webhook_secret_default(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\OptionsDefaults';
        $this->assertTrue(class_exists($fqcn), 'AC-3: OptionsDefaults muss autoloadbar sein.');

        $observedKeys = [];
        Monkey\Functions\when('add_option')->alias(function ($key, $value = '', $deprecated = '', $autoload = 'yes') use (&$observedKeys) {
            $observedKeys[] = (string) $key;
            return true;
        });

        $fqcn::install();

        $this->assertNotContains(
            'spreadconnect_webhook_secret',
            $observedKeys,
            'AC-3: spreadconnect_webhook_secret darf NICHT in slice-05 gesetzt werden — '
            . 'Generation gehoert zu slice-14-webhook-secret-manager (random_bytes(32) + base64). '
            . 'Beobachtete Keys: ' . implode(', ', $observedKeys)
        );

        // Aber 18 Keys MUESSEN gesetzt sein (alle anderen).
        $this->assertCount(
            18,
            $observedKeys,
            'AC-3: install() MUSS GENAU 18 Keys setzen (alle ausser webhook_secret).'
        );
    }

    /**
     * AC-3 / Constraint: DEFAULTS-Konstante enthaelt webhook_secret NICHT.
     */
    public function test_defaults_constant_does_not_contain_webhook_secret(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\OptionsDefaults';
        $this->assertTrue(class_exists($fqcn), 'AC-3: OptionsDefaults muss autoloadbar sein.');

        $reflection = new ReflectionClass($fqcn);
        $this->assertTrue(
            $reflection->hasConstant('DEFAULTS') || $reflection->hasMethod('defaults'),
            'AC-5: OptionsDefaults muss DEFAULTS-Konstante oder defaults()-Methode haben '
            . '(Single Source of Truth).'
        );

        if ($reflection->hasConstant('DEFAULTS')) {
            $defaults = $reflection->getConstant('DEFAULTS');
            $this->assertIsArray($defaults, 'AC-3: DEFAULTS muss array sein.');
            $this->assertArrayNotHasKey(
                'spreadconnect_webhook_secret',
                $defaults,
                'AC-3: DEFAULTS-Konstante darf "spreadconnect_webhook_secret" NICHT enthalten — '
                . 'Generation gehoert zu slice-14.'
            );
        }
    }

    // ===================================================================
    // AC-4: GIVEN Bootstrap\Plugin::init( __FILE__ ) aus Slice 02
    //       WHEN Slice 05 erweitert init()
    //       THEN wird register_activation_hook(
    //              $plugin_file, [OptionsDefaults::class, 'install']
    //            ) genau einmal pro init()-Aufruf registriert.
    //            Doppelter init() registriert den Hook NICHT doppelt.
    // ===================================================================

    /**
     * AC-4: Plugin::init() registriert register_activation_hook
     * mit [OptionsDefaults::class, 'install'].
     */
    public function test_plugin_init_registers_activation_hook_for_options_defaults(): void
    {
        $pluginFqcn  = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $optionsFqcn = 'SpreadconnectPod\\Bootstrap\\OptionsDefaults';
        $this->assertTrue(class_exists($pluginFqcn), 'AC-4: Plugin muss autoloadbar sein.');
        $this->assertTrue(class_exists($optionsFqcn), 'AC-4: OptionsDefaults muss autoloadbar sein.');

        $registrations = [];
        Monkey\Functions\when('register_activation_hook')->alias(function ($file, $callable) use (&$registrations) {
            $registrations[] = [$file, $callable];
            return null;
        });

        $pluginFile = self::pluginMainFile();
        $pluginFqcn::init($pluginFile);

        $this->assertGreaterThanOrEqual(
            1,
            count($registrations),
            'AC-4: register_activation_hook MUSS mindestens einmal aufgerufen werden.'
        );

        // Suche die Registration mit [OptionsDefaults::class, 'install']-Callable.
        $found = null;
        foreach ($registrations as $reg) {
            [$file, $callable] = $reg;
            if (is_array($callable)
                && count($callable) === 2
                && ltrim((string) $callable[0], '\\') === $optionsFqcn
                && $callable[1] === 'install'
            ) {
                $found = $reg;
                break;
            }
        }

        $this->assertNotNull(
            $found,
            'AC-4: Plugin::init() MUSS register_activation_hook() mit Callable '
            . '[OptionsDefaults::class, "install"] aufrufen. Registrierungen waren: '
            . print_r($registrations, true)
        );

        // Erstes Argument MUSS der uebergebene $plugin_file sein.
        $this->assertSame(
            $pluginFile,
            $found[0],
            'AC-4: register_activation_hook() MUSS als 1. Argument den $plugin_file '
            . 'erhalten, der an init() uebergeben wurde — kein hardcoded __FILE__ aus '
            . 'OptionsDefaults.php.'
        );
    }

    /**
     * AC-4: Doppelter Plugin::init() registriert Hook NICHT doppelt
     * (Idempotenz-Guard aus Slice 02 AC-5 muss greifen).
     */
    public function test_double_plugin_init_does_not_double_register_hook(): void
    {
        $pluginFqcn  = 'SpreadconnectPod\\Bootstrap\\Plugin';
        $optionsFqcn = 'SpreadconnectPod\\Bootstrap\\OptionsDefaults';

        $registrations = [];
        Monkey\Functions\when('register_activation_hook')->alias(function ($file, $callable) use (&$registrations) {
            $registrations[] = [$file, $callable];
            return null;
        });

        $pluginFile = self::pluginMainFile();

        // 2x init() — Idempotenz-Guard aus Slice 02.
        $pluginFqcn::init($pluginFile);
        $pluginFqcn::init($pluginFile);

        $optionsInstallCount = 0;
        foreach ($registrations as $reg) {
            [, $callable] = $reg;
            if (is_array($callable)
                && count($callable) === 2
                && ltrim((string) $callable[0], '\\') === $optionsFqcn
                && $callable[1] === 'install'
            ) {
                $optionsInstallCount++;
            }
        }

        $this->assertSame(
            1,
            $optionsInstallCount,
            'AC-4: Bei doppeltem Plugin::init() darf register_activation_hook fuer '
            . '[OptionsDefaults::class, "install"] NUR EINMAL aufgerufen werden — '
            . 'der Idempotenz-Guard aus Slice 02 AC-5 MUSS greifen.'
        );
    }

    // ===================================================================
    // AC-5: GIVEN OptionsDefaults::install()
    //       WHEN sie aufgerufen wird
    //       THEN ruft sie pro Default-Key GENAU EINEN add_option-Aufruf —
    //            die Default-Werte stammen aus EINER zentralen Quelle
    //            (z. B. DEFAULTS-Array-Konstante), nicht aus verteilten
    //            add_option-Statements.
    // ===================================================================

    /**
     * AC-5: DEFAULTS ist Array-Konstante (oder defaults()-Methode) mit 18 Eintraegen.
     */
    public function test_defaults_originate_from_single_source(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\OptionsDefaults';
        $this->assertTrue(class_exists($fqcn), 'AC-5: OptionsDefaults muss autoloadbar sein.');

        $reflection = new ReflectionClass($fqcn);

        // Variante A: public const DEFAULTS = [ ... ].
        // Variante B: private static function defaults(): array.
        $hasConst   = $reflection->hasConstant('DEFAULTS');
        $hasMethod  = $reflection->hasMethod('defaults');

        $this->assertTrue(
            $hasConst || $hasMethod,
            'AC-5: OptionsDefaults MUSS eine zentrale Quelle fuer die Defaults bereitstellen — '
            . 'entweder Array-Konstante "DEFAULTS" oder "defaults(): array"-Methode.'
        );

        if ($hasConst) {
            $defaults = $reflection->getConstant('DEFAULTS');
            $this->assertIsArray($defaults, 'AC-5: DEFAULTS-Konstante MUSS array sein.');
            $this->assertCount(
                18,
                $defaults,
                sprintf(
                    'AC-5: DEFAULTS-Konstante MUSS GENAU 18 Eintraege haben (architecture.md '
                    . 'Z. 323-341 minus webhook_secret). Tatsaechlich: %d.',
                    count($defaults)
                )
            );

            // Alle Keys folgen dem Naming-Pattern `spreadconnect_*`.
            foreach (array_keys($defaults) as $key) {
                $this->assertIsString($key, 'AC-5: DEFAULTS-Keys MUSSEN strings sein.');
                $this->assertStringStartsWith(
                    'spreadconnect_',
                    (string) $key,
                    sprintf('AC-5: Default-Key "%s" MUSS mit "spreadconnect_" prefixed sein.', $key)
                );
            }
        }
    }

    /**
     * AC-5: install()-Body enthaelt nur EINEN add_option-Aufruf
     * (innerhalb einer Iteration ueber die zentrale Quelle).
     */
    public function test_install_body_has_single_add_option_call_in_loop(): void
    {
        $source = self::optionsDefaultsSource();

        $installBody = self::extractMethodBody($source, 'install');
        $this->assertNotNull($installBody, 'AC-5: install()-Methoden-Body muss extrahierbar sein.');

        // Genau ein add_option(...)-Aufruf — nicht 18 verstreute.
        $addOptionCount = preg_match_all('/\badd_option\s*\(/', $installBody);
        $this->assertSame(
            1,
            $addOptionCount,
            'AC-5: install()-Body MUSS GENAU EINEN add_option(...)-Aufruf haben '
            . '(in einer Schleife ueber DEFAULTS). Tatsaechlich gefunden: ' . $addOptionCount
            . '. Verteilte add_option-Statements sind verboten — sie verletzen die '
            . 'Single-Source-Garantie.'
        );

        // Eine Schleife (foreach) MUSS vorhanden sein.
        $this->assertMatchesRegularExpression(
            '/\bforeach\s*\(/',
            $installBody,
            'AC-5: install()-Body MUSS eine foreach-Schleife ueber die DEFAULTS-Quelle '
            . 'haben (statt 18 verstreute add_option-Statements).'
        );
    }

    /**
     * Extrahiert den Methoden-Body als Source-String.
     *
     * Greift mit Tokenizer, um Klammer-Balance korrekt aufzuloesen.
     */
    private static function extractMethodBody(string $source, string $methodName): ?string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (! is_array($tok) || $tok[0] !== T_FUNCTION) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];
                if (is_array($next) && $next[0] === T_STRING) {
                    if ($next[1] === $methodName) {
                        for ($k = $j + 1; $k < $count; $k++) {
                            if ($tokens[$k] === '{') {
                                $depth = 1;
                                $body = '';
                                for ($l = $k + 1; $l < $count; $l++) {
                                    $t = $tokens[$l];
                                    if ($t === '{') {
                                        $depth++;
                                        $body .= '{';
                                    } elseif ($t === '}') {
                                        $depth--;
                                        if ($depth === 0) {
                                            return $body;
                                        }
                                        $body .= '}';
                                    } else {
                                        $body .= is_array($t) ? $t[1] : $t;
                                    }
                                }
                                return null;
                            }
                        }
                    }
                    break;
                }
            }
        }

        return null;
    }

    // ===================================================================
    // AC-6: GIVEN der Activate-Hook und die SettingsValidator-Sanitize-Logik
    //             (kommt in Slice 11)
    //       WHEN Slice 05 die Defaults schreibt
    //       THEN ist jeder Default-Wert SELBST valide gegen die in
    //            architecture.md beschriebenen Validation-Rules
    //            (z. B. spreadconnect_auto_confirm = 'off' ist im Enum,
    //            spreadconnect_live_cache_ttl_seconds = 300 liegt im
    //            60..900-Range).
    // ===================================================================

    /**
     * AC-6: Jeder Default-Wert ist gegen die architecture.md-Validation-Regel valide.
     *
     * Wir lesen die DEFAULTS-Konstante via Reflection und pruefen jeden Default
     * gegen die Validation-Regel aus architecture.md (Z. 323-341).
     */
    public function test_each_default_satisfies_architecture_validation_rule(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\OptionsDefaults';
        $this->assertTrue(class_exists($fqcn), 'AC-6: OptionsDefaults muss autoloadbar sein.');

        $reflection = new ReflectionClass($fqcn);
        $this->assertTrue(
            $reflection->hasConstant('DEFAULTS'),
            'AC-6: AC-Pruefung erfordert DEFAULTS-Konstante (siehe AC-5).'
        );

        $defaults = $reflection->getConstant('DEFAULTS');
        $this->assertIsArray($defaults, 'AC-6: DEFAULTS muss array sein.');

        // ---- Validation-Rules je Key (architecture.md Z. 323-341) ----

        // spreadconnect_auto_confirm: enum off/immediate/after_minutes
        $this->assertContains(
            $defaults['spreadconnect_auto_confirm'] ?? null,
            ['off', 'immediate', 'after_minutes'],
            "AC-6: spreadconnect_auto_confirm Default MUSS im Enum {off, immediate, after_minutes} liegen."
        );

        // spreadconnect_auto_confirm_minutes: int >= 0
        $this->assertIsInt($defaults['spreadconnect_auto_confirm_minutes'] ?? null);
        $this->assertGreaterThanOrEqual(
            0,
            $defaults['spreadconnect_auto_confirm_minutes'],
            'AC-6: spreadconnect_auto_confirm_minutes MUSS >= 0 sein.'
        );

        // spreadconnect_stock_sync_interval: enum 1h/4h/6h/12h/24h
        $this->assertContains(
            $defaults['spreadconnect_stock_sync_interval'] ?? null,
            ['1h', '4h', '6h', '12h', '24h'],
            "AC-6: spreadconnect_stock_sync_interval MUSS im Enum {1h,4h,6h,12h,24h} liegen."
        );

        // spreadconnect_low_stock_threshold: int >= 0
        $this->assertIsInt($defaults['spreadconnect_low_stock_threshold'] ?? null);
        $this->assertGreaterThanOrEqual(
            0,
            $defaults['spreadconnect_low_stock_threshold'],
            'AC-6: spreadconnect_low_stock_threshold MUSS >= 0 sein.'
        );

        // spreadconnect_live_cache_ttl_seconds: int 60..900
        $ttl = $defaults['spreadconnect_live_cache_ttl_seconds'] ?? null;
        $this->assertIsInt($ttl);
        $this->assertGreaterThanOrEqual(60, $ttl, 'AC-6: live_cache_ttl >= 60.');
        $this->assertLessThanOrEqual(900, $ttl, 'AC-6: live_cache_ttl <= 900.');

        // spreadconnect_failed_ops_retention_days: int 7..365
        $fr = $defaults['spreadconnect_failed_ops_retention_days'] ?? null;
        $this->assertIsInt($fr);
        $this->assertGreaterThanOrEqual(7, $fr, 'AC-6: failed_ops_retention_days >= 7.');
        $this->assertLessThanOrEqual(365, $fr, 'AC-6: failed_ops_retention_days <= 365.');

        // spreadconnect_webhook_log_retention_days: int 7..365
        $wr = $defaults['spreadconnect_webhook_log_retention_days'] ?? null;
        $this->assertIsInt($wr);
        $this->assertGreaterThanOrEqual(7, $wr, 'AC-6: webhook_log_retention_days >= 7.');
        $this->assertLessThanOrEqual(365, $wr, 'AC-6: webhook_log_retention_days <= 365.');

        // Bool-Felder MUSSEN bool sein (sonst kann SettingsValidator nicht
        // sauber sanitizen — implizite cast-Risiken).
        $boolKeys = [
            'spreadconnect_use_staging',
            'spreadconnect_auto_cancel_mirror',
            'spreadconnect_pull_images',
            'spreadconnect_force_repull_images',
            'spreadconnect_notify_on_order_failure',
            'spreadconnect_notify_on_sync_failure',
            'spreadconnect_notify_on_webhook_failure',
        ];
        foreach ($boolKeys as $key) {
            $this->assertArrayHasKey($key, $defaults, sprintf('AC-6: bool-Default "%s" fehlt.', $key));
            $this->assertIsBool(
                $defaults[$key],
                sprintf('AC-6: "%s" MUSS bool sein (gegen Validation-Rule).', $key)
            );
        }

        // String-Felder mit "leer ok"-Default — verifiziere gegen Validation:
        // - spreadconnect_api_key:  "non-empty when set" — leer als Default ist erlaubt
        //                           (User noch nicht konfiguriert), Validation triggert
        //                           erst nach explizitem Save.
        // - spreadconnect_default_shipping_type: dito.
        // - spreadconnect_notify_emails: comma-sep emails — leer ok.
        // - spreadconnect_pt_index_etag: kein Constraint — leer ok.
        $stringEmptyOk = [
            'spreadconnect_api_key',
            'spreadconnect_default_shipping_type',
            'spreadconnect_notify_emails',
            'spreadconnect_pt_index_etag',
        ];
        foreach ($stringEmptyOk as $key) {
            $this->assertArrayHasKey($key, $defaults, sprintf('AC-6: string-Default "%s" fehlt.', $key));
            $this->assertIsString(
                $defaults[$key],
                sprintf('AC-6: "%s" MUSS string sein.', $key)
            );
            $this->assertSame(
                '',
                $defaults[$key],
                sprintf(
                    'AC-6: "%s" Default MUSS leerer String sein (architecture.md Z. 323-341).',
                    $key
                )
            );
        }
    }

    /**
     * AC-6 (Constraint): notify_emails-Default ist leerer String —
     * comma-sep emails-Validation muss auf leer als gueltigen "kein Empfaenger"-State reagieren.
     */
    public function test_notify_emails_default_is_empty_string(): void
    {
        $fqcn = 'SpreadconnectPod\\Bootstrap\\OptionsDefaults';
        $reflection = new ReflectionClass($fqcn);
        $this->assertTrue($reflection->hasConstant('DEFAULTS'));

        $defaults = $reflection->getConstant('DEFAULTS');
        $this->assertSame(
            '',
            $defaults['spreadconnect_notify_emails'] ?? null,
            'AC-6: spreadconnect_notify_emails-Default MUSS "" sein — '
            . 'comma-sep emails-Validation gegen leeren Default. '
            . 'Architecture.md Z. 335: Default `""`.'
        );
    }
}
