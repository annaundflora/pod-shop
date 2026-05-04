<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use SpreadconnectPod\Bootstrap\OptionsDefaults;
use SpreadconnectPod\Hub\View\Settings;
use SpreadconnectPod\Settings\SettingsValidator;

/**
 * Slice 11 — Settings-Form + Settings-Validator
 *
 * Acceptance Tests gegen die Slice-Spec `slice-11-settings-form.md`.
 *
 * Mocking Strategy: `mock_external` (laut Slice-Spec):
 *   - Brain\Monkey fuer WP Settings API: `register_setting`,
 *     `add_settings_section`, `add_settings_field`, `settings_fields`,
 *     `do_settings_sections`, `submit_button`, `do_action`, `add_filter`.
 *   - Brain\Monkey fuer Options API: `get_option`, `update_option`.
 *   - Brain\Monkey fuer Capability + i18n + Sanitizer-Helpers:
 *     `current_user_can`, `__`, `esc_html__`, `esc_attr`, `esc_html`,
 *     `sanitize_text_field`, `sanitize_email`, `absint`, `current_filter`.
 *   - In-memory Options-Map zur Sanitize-Verifikation (AC-8 Roundtrip).
 *
 * Strategie:
 *   - AC-1 (Hook-Capture): Brain\Monkey-Spies fuer `register_setting` +
 *     `add_settings_section` + `add_filter` capturen alle Calls,
 *     anschliessend wird Reihenfolge / Group-Slug / Sanitize-Callable verifiziert.
 *   - AC-2..AC-7 (Sanitize-Logik): direkte `SettingsValidator::sanitize($input)`
 *     Calls mit gestubten WP-Helpers (sanitize_text_field, sanitize_email,
 *     absint).
 *   - AC-3 (Cross-Field-Gating): Tests fuer ARRAY-MODE (`sanitize`) UND
 *     fuer den `gateAutoConfirmOnPreUpdate`-Filter (per-option Mode).
 *   - AC-8: Roundtrip-Test mit in-memory Options-Map ueber WP Settings-API.
 *   - AC-9: `current_user_can('manage_woocommerce')` -> false -> wp_die() Throw.
 *   - AC-10: Statische Source-Analyse fuer `__()` / `esc_html__()` mit
 *     Text-Domain `spreadconnect-pod`.
 */
final class Slice11SettingsFormTest extends TestCase
{
    /**
     * Repo-Root: drei Verzeichnisse oberhalb von `tests/slices/pod-shop-mvp/`.
     */
    private static function repoRoot(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }

    /**
     * Absoluter Pfad zur Settings-View-Klassendatei.
     */
    private static function settingsViewFile(): string
    {
        return self::repoRoot()
            . '/wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php';
    }

    /**
     * Source-Inhalt der Settings.php (cached pro Test).
     */
    private static function settingsViewSource(): string
    {
        $file = self::settingsViewFile();
        self::assertFileExists($file, 'Hub/View/Settings.php muss existieren.');
        $contents = (string) file_get_contents($file);
        self::assertNotSame('', $contents, 'Settings.php darf nicht leer sein.');
        return $contents;
    }

    /**
     * Die 17 user-editierbaren Form-Felder gemaess Slice-11-Spec
     * (Form-Felder-Tabelle Z. 42-60). Slice-14 (`webhook_secret`) und
     * `pt_index_etag` sind explizit NICHT in dieser Slice.
     *
     * @return list<string>
     */
    private static function expectedFormFields(): array
    {
        return [
            // ① API Connection.
            'spreadconnect_api_key',
            'spreadconnect_use_staging',
            // ⑥ Order Behavior.
            'spreadconnect_auto_confirm',
            'spreadconnect_auto_confirm_minutes',
            'spreadconnect_default_shipping_type',
            'spreadconnect_auto_cancel_mirror',
            // ⑦ Catalog Sync.
            'spreadconnect_pull_images',
            'spreadconnect_force_repull_images',
            'spreadconnect_stock_sync_interval',
            'spreadconnect_low_stock_threshold',
            'spreadconnect_live_cache_ttl_seconds',
            // ⑧ Failure Notifications.
            'spreadconnect_notify_emails',
            'spreadconnect_notify_on_order_failure',
            'spreadconnect_notify_on_sync_failure',
            'spreadconnect_notify_on_webhook_failure',
            'spreadconnect_failed_ops_retention_days',
            'spreadconnect_webhook_log_retention_days',
        ];
    }

    /**
     * Stub die i18n + Escape-Helpers — sie werden in render() und in den
     * Section-Intro-Callbacks haeufig aufgerufen.
     */
    private static function stubI18nAndEscapeHelpers(): void
    {
        Monkey\Functions\when('__')->returnArg(1);
        Monkey\Functions\when('esc_html__')->returnArg(1);
        Monkey\Functions\when('esc_attr__')->returnArg(1);
        Monkey\Functions\when('_e')->returnArg(1);
        Monkey\Functions\when('esc_html')->returnArg(1);
        Monkey\Functions\when('esc_attr')->returnArg(1);
    }

    /**
     * Stub die Sanitize-Helpers, die `SettingsValidator` aufruft.
     */
    private static function stubSanitizeHelpers(): void
    {
        // sanitize_text_field: trim + strip tags (vereinfacht).
        Monkey\Functions\when('sanitize_text_field')->alias(function ($input) {
            if (! is_string($input)) {
                return '';
            }
            $clean = strip_tags($input);
            $clean = preg_replace('/[\r\n\t]+/', ' ', $clean) ?? '';
            return trim($clean);
        });

        // sanitize_email: liefert Email zurueck wenn valide via filter_var,
        // ansonsten leeren String (wie WP es tut).
        Monkey\Functions\when('sanitize_email')->alias(function ($email) {
            if (! is_string($email)) {
                return '';
            }
            $email = trim($email);
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return '';
            }
            return $email;
        });

        // absint: WP-Konvention — non-negative int.
        Monkey\Functions\when('absint')->alias(function ($value) {
            return abs((int) $value);
        });
    }

    /**
     * Stub die WP Settings-API-Callbacks (no-op fuer Render-Tests, ueberschrieben
     * mit Spies in AC-1-Tests).
     */
    private static function stubSettingsApiCallbacks(): void
    {
        Monkey\Functions\when('settings_fields')->justReturn(null);
        Monkey\Functions\when('do_settings_sections')->justReturn(null);
        Monkey\Functions\when('submit_button')->justReturn(null);
        Monkey\Functions\when('do_action')->justReturn(null);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ===================================================================
    // AC-1: Settings-API-Registration — `Hub\View\Settings::registerSettings()`
    //       registriert genau eine Settings-Group `spreadconnect_settings`,
    //       4 Sections (① ⑥ ⑦ ⑧), 17 Felder mit Sanitize-Callback
    //       `[SettingsValidator::class, 'sanitizeOne']`. Reihenfolge:
    //       `default_shipping_type` VOR `auto_confirm` (cross-field-gating).
    // ===================================================================

    /**
     * AC-1: registerSettings() registriert genau 17 Felder fuer die Settings-Group
     * `spreadconnect_settings` und 4 Sections (① ⑥ ⑦ ⑧).
     */
    public function test_render_registers_settings_group_with_all_fields_and_sections(): void
    {
        self::stubI18nAndEscapeHelpers();

        $registerSettingCalls = [];
        $sectionCalls         = [];
        $fieldCalls           = [];
        $filterRegistrations  = [];

        Monkey\Functions\when('register_setting')->alias(
            function (string $group, string $option, $args = []) use (&$registerSettingCalls) {
                $registerSettingCalls[] = ['group' => $group, 'option' => $option, 'args' => $args];
            }
        );
        Monkey\Functions\when('add_settings_section')->alias(
            function (string $id, string $title, $callback, string $page) use (&$sectionCalls) {
                $sectionCalls[] = ['id' => $id, 'title' => $title, 'callback' => $callback, 'page' => $page];
            }
        );
        Monkey\Functions\when('add_settings_field')->alias(
            function (string $id, string $title, $callback, string $page, ?string $section = null, $args = []) use (&$fieldCalls) {
                $fieldCalls[] = ['id' => $id, 'page' => $page, 'section' => $section];
            }
        );
        Monkey\Functions\when('add_filter')->alias(
            function (string $hook, $callback, int $priority = 10, int $args = 1) use (&$filterRegistrations) {
                $filterRegistrations[] = ['hook' => $hook, 'callback' => $callback];
            }
        );

        Settings::registerSettings();

        // ---- 17 register_setting calls ----
        $this->assertCount(
            17,
            $registerSettingCalls,
            sprintf(
                'AC-1: registerSettings() MUSS GENAU 17 register_setting()-Calls produzieren ' .
                '(Form-Felder-Tabelle Z. 42-60). Tatsaechlich: %d.',
                count($registerSettingCalls)
            )
        );

        // Alle Calls gehen an dieselbe Group `spreadconnect_settings`.
        foreach ($registerSettingCalls as $call) {
            $this->assertSame(
                'spreadconnect_settings',
                $call['group'],
                sprintf(
                    'AC-1: register_setting() MUSS Group "spreadconnect_settings" verwenden — ' .
                    'gefunden: "%s" fuer Option "%s".',
                    $call['group'],
                    $call['option']
                )
            );
        }

        // Alle 17 erwarteten Option-Keys sind registriert.
        $registeredOptions = array_column($registerSettingCalls, 'option');
        sort($registeredOptions);
        $expected = self::expectedFormFields();
        sort($expected);
        $this->assertSame(
            $expected,
            $registeredOptions,
            'AC-1: Die 17 registrierten Options muessen exakt der Form-Felder-Tabelle ' .
            'entsprechen (Slice-11 Z. 42-60). webhook_secret (Slice 14) und pt_index_etag ' .
            '(system-managed) sind explizit NICHT enthalten.'
        );

        // Webhook_secret und pt_index_etag NICHT registriert.
        $this->assertNotContains(
            'spreadconnect_webhook_secret',
            $registeredOptions,
            'AC-1 / Constraint: webhook_secret ist Slice 14 — darf hier NICHT registriert sein.'
        );
        $this->assertNotContains(
            'spreadconnect_pt_index_etag',
            $registeredOptions,
            'AC-1 / Constraint: pt_index_etag ist system-managed (Slice 23) — darf hier NICHT registriert sein.'
        );

        // ---- 4 Sections (① ⑥ ⑦ ⑧) — section ② (Slice 12), ③ (Slice 14), ⑨ (Slice 45) NICHT registriert.
        $this->assertCount(
            4,
            $sectionCalls,
            sprintf(
                'AC-1: registerSettings() MUSS GENAU 4 add_settings_section()-Calls produzieren ' .
                '(① API Connection, ⑥ Order Behavior, ⑦ Catalog Sync, ⑧ Failure Notifications). ' .
                'Tatsaechlich: %d.',
                count($sectionCalls)
            )
        );

        // Alle Sections gehen an dieselbe Page `spreadconnect-settings`.
        foreach ($sectionCalls as $section) {
            $this->assertSame(
                'spreadconnect-settings',
                $section['page'],
                sprintf(
                    'AC-1: Section "%s" muss auf Page "spreadconnect-settings" registriert sein.',
                    $section['id']
                )
            );
        }

        // ---- 17 Settings-Fields (eines pro registriertem Option) ----
        $this->assertCount(
            17,
            $fieldCalls,
            sprintf(
                'AC-1: registerSettings() MUSS GENAU 17 add_settings_field()-Calls produzieren. ' .
                'Tatsaechlich: %d.',
                count($fieldCalls)
            )
        );
    }

    /**
     * AC-1: Sanitize-Callback fuer jedes Field zeigt auf
     * [SettingsValidator::class, 'sanitizeOne'] (per-option dispatcher).
     */
    public function test_each_register_setting_call_uses_settings_validator_sanitize(): void
    {
        self::stubI18nAndEscapeHelpers();
        Monkey\Functions\when('add_settings_section')->justReturn(null);
        Monkey\Functions\when('add_settings_field')->justReturn(null);
        Monkey\Functions\when('add_filter')->justReturn(null);

        $registerSettingCalls = [];
        Monkey\Functions\when('register_setting')->alias(
            function (string $group, string $option, $args = []) use (&$registerSettingCalls) {
                $registerSettingCalls[] = ['group' => $group, 'option' => $option, 'args' => $args];
            }
        );

        Settings::registerSettings();

        $this->assertNotEmpty(
            $registerSettingCalls,
            'AC-1: registerSettings() MUSS register_setting() mindestens einmal aufrufen.'
        );

        foreach ($registerSettingCalls as $call) {
            $args = $call['args'];
            $this->assertIsArray(
                $args,
                sprintf('AC-1: register_setting()-Args fuer "%s" muessen ein Array sein.', $call['option'])
            );
            $this->assertArrayHasKey(
                'sanitize_callback',
                $args,
                sprintf(
                    'AC-1: register_setting() fuer "%s" MUSS einen sanitize_callback definieren.',
                    $call['option']
                )
            );

            $callback = $args['sanitize_callback'];
            $this->assertIsArray(
                $callback,
                sprintf(
                    'AC-1: sanitize_callback fuer "%s" MUSS ein [class, method]-Callable-Array sein. ' .
                    'Gefunden: %s',
                    $call['option'],
                    is_object($callback) ? get_class($callback) : gettype($callback)
                )
            );
            $this->assertCount(
                2,
                $callback,
                sprintf('AC-1: sanitize_callback fuer "%s" MUSS [class, method]-Form haben.', $call['option'])
            );
            $this->assertSame(
                SettingsValidator::class,
                ltrim((string) $callback[0], '\\'),
                sprintf(
                    'AC-1: sanitize_callback fuer "%s" MUSS auf SettingsValidator zeigen — ' .
                    'gefunden: %s.',
                    $call['option'],
                    (string) $callback[0]
                )
            );
            $this->assertSame(
                'sanitizeOne',
                $callback[1],
                sprintf(
                    'AC-1: sanitize_callback fuer "%s" MUSS sanitizeOne aufrufen ' .
                    '(per-option Dispatcher; sanitize() ist Array-Mode fuer Re-Validation/Tests).',
                    $call['option']
                )
            );
        }
    }

    /**
     * AC-1: `pre_update_option_spreadconnect_auto_confirm`-Filter ist
     * registriert mit [SettingsValidator::class, 'gateAutoConfirmOnPreUpdate'].
     * Cross-Field-Gating-Hook (vgl. Production-Code-Comment Z. 168-184).
     */
    public function test_register_settings_adds_pre_update_filter_for_auto_confirm_gating(): void
    {
        self::stubI18nAndEscapeHelpers();
        Monkey\Functions\when('register_setting')->justReturn(null);
        Monkey\Functions\when('add_settings_section')->justReturn(null);
        Monkey\Functions\when('add_settings_field')->justReturn(null);

        $filterRegistrations = [];
        Monkey\Functions\when('add_filter')->alias(
            function (string $hook, $callback, int $priority = 10, int $args = 1) use (&$filterRegistrations) {
                $filterRegistrations[] = ['hook' => $hook, 'callback' => $callback];
            }
        );

        Settings::registerSettings();

        $found = null;
        foreach ($filterRegistrations as $reg) {
            if ($reg['hook'] === 'pre_update_option_spreadconnect_auto_confirm') {
                $found = $reg;
                break;
            }
        }

        $this->assertNotNull(
            $found,
            'AC-1 / Cross-Field-Gating: registerSettings() MUSS einen ' .
            'add_filter("pre_update_option_spreadconnect_auto_confirm", ...) registrieren ' .
            'um die Auto-Confirm-Gating-Regel auch im per-option Settings-API-Pfad zu erzwingen.'
        );

        $callback = $found['callback'];
        $this->assertIsArray(
            $callback,
            'AC-1: pre_update_option-Filter Callback MUSS ein [class, method]-Array sein.'
        );
        $this->assertSame(
            SettingsValidator::class,
            ltrim((string) $callback[0], '\\'),
            'AC-1: pre_update_option-Filter Callback MUSS auf SettingsValidator zeigen.'
        );
        $this->assertSame(
            'gateAutoConfirmOnPreUpdate',
            $callback[1],
            'AC-1: pre_update_option-Filter Callback MUSS auf gateAutoConfirmOnPreUpdate() zeigen.'
        );
    }

    /**
     * AC-1 (Cross-Field-Constraint): `spreadconnect_default_shipping_type`
     * MUSS VOR `spreadconnect_auto_confirm` registriert werden — sonst
     * greift das Cross-Field-Gating in `pre_update_option_*` nicht
     * (wp-admin/options.php iteriert in Registrierungs-Reihenfolge).
     */
    public function test_default_shipping_type_is_registered_before_auto_confirm(): void
    {
        self::stubI18nAndEscapeHelpers();
        Monkey\Functions\when('add_settings_section')->justReturn(null);
        Monkey\Functions\when('add_settings_field')->justReturn(null);
        Monkey\Functions\when('add_filter')->justReturn(null);

        $registrationOrder = [];
        Monkey\Functions\when('register_setting')->alias(
            function (string $group, string $option, $args = []) use (&$registrationOrder) {
                $registrationOrder[] = $option;
            }
        );

        Settings::registerSettings();

        $shippingIdx = array_search('spreadconnect_default_shipping_type', $registrationOrder, true);
        $autoConfirmIdx = array_search('spreadconnect_auto_confirm', $registrationOrder, true);

        $this->assertNotFalse(
            $shippingIdx,
            'AC-1: spreadconnect_default_shipping_type MUSS registriert sein.'
        );
        $this->assertNotFalse(
            $autoConfirmIdx,
            'AC-1: spreadconnect_auto_confirm MUSS registriert sein.'
        );
        $this->assertLessThan(
            $autoConfirmIdx,
            $shippingIdx,
            'AC-1 / Cross-Field-Gating: spreadconnect_default_shipping_type MUSS VOR ' .
            'spreadconnect_auto_confirm registriert sein, weil wp-admin/options.php in ' .
            'Registrierungs-Reihenfolge iteriert und der pre_update_option-Filter den ' .
            'just-persistierten Shipping-Type via get_option() lesen muss.'
        );
    }

    // ===================================================================
    // AC-2: Auto-Confirm bleibt 'immediate' wenn Default-Shipping-Type gesetzt
    // ===================================================================

    /**
     * AC-2: GIVEN default_shipping_type='PREMIUM', auto_confirm='immediate'
     *       THEN sanitize() liefert auto_confirm='immediate' UND
     *            default_shipping_type='PREMIUM' unveraendert.
     */
    public function test_sanitize_keeps_auto_confirm_when_default_shipping_type_set(): void
    {
        self::stubSanitizeHelpers();

        $input = [
            'spreadconnect_default_shipping_type' => 'PREMIUM',
            'spreadconnect_auto_confirm'          => 'immediate',
        ];

        $output = SettingsValidator::sanitize($input);

        $this->assertSame(
            'immediate',
            $output['spreadconnect_auto_confirm'],
            'AC-2: auto_confirm MUSS unveraendert bleiben, wenn default_shipping_type ' .
            'gesetzt ist (Gating ist erfuellt).'
        );
        $this->assertSame(
            'PREMIUM',
            $output['spreadconnect_default_shipping_type'],
            'AC-2: default_shipping_type MUSS unveraendert "PREMIUM" sein.'
        );
    }

    // ===================================================================
    // AC-3: Auto-Confirm-Gating zwingt 'off' bei leerem Default-Shipping-Type
    // ===================================================================

    /**
     * AC-3 (ARRAY-MODE): GIVEN default_shipping_type='', auto_confirm='immediate'
     *       THEN sanitize() forciert auto_confirm='off'.
     */
    public function test_sanitize_forces_auto_confirm_off_when_default_shipping_type_empty(): void
    {
        self::stubSanitizeHelpers();

        $input = [
            'spreadconnect_default_shipping_type' => '',
            'spreadconnect_auto_confirm'          => 'immediate',
        ];

        $output = SettingsValidator::sanitize($input);

        $this->assertSame(
            'off',
            $output['spreadconnect_auto_confirm'],
            'AC-3: Auto-Confirm-Gating MUSS auto_confirm auf "off" zwingen, wenn ' .
            'default_shipping_type leer ist (architecture.md Z. 326).'
        );
        $this->assertSame(
            '',
            $output['spreadconnect_default_shipping_type'],
            'AC-3: default_shipping_type bleibt leer.'
        );

        // Output enthaelt weiterhin alle 17 Keys (siehe Constraint).
        foreach (self::expectedFormFields() as $key) {
            $this->assertArrayHasKey(
                $key,
                $output,
                sprintf(
                    'AC-3 / Constraint: sanitize-Output MUSS immer alle 17 Keys enthalten — "%s" fehlt.',
                    $key
                )
            );
        }
    }

    /**
     * AC-3: Gating gilt auch fuer 'after_minutes', nicht nur 'immediate'.
     */
    public function test_sanitize_forces_auto_confirm_off_for_after_minutes_when_shipping_empty(): void
    {
        self::stubSanitizeHelpers();

        $input = [
            'spreadconnect_default_shipping_type' => '',
            'spreadconnect_auto_confirm'          => 'after_minutes',
        ];

        $output = SettingsValidator::sanitize($input);

        $this->assertSame(
            'off',
            $output['spreadconnect_auto_confirm'],
            'AC-3: Gating zwingt "off" auch fuer after_minutes — nicht nur fuer "immediate".'
        );
    }

    // ===================================================================
    // AC-3 (per-option): gateAutoConfirmOnPreUpdate liest get_option()
    // ===================================================================

    /**
     * AC-3 (PER-OPTION): gateAutoConfirmOnPreUpdate() forciert 'off' wenn
     * default_shipping_type leer ist (Brain\Monkey-stub fuer get_option).
     */
    public function test_gate_auto_confirm_on_pre_update_forces_off_when_shipping_empty(): void
    {
        Monkey\Functions\when('get_option')->alias(
            function (string $key, $default = false) {
                if ($key === 'spreadconnect_default_shipping_type') {
                    return '';
                }
                return $default;
            }
        );

        $result = SettingsValidator::gateAutoConfirmOnPreUpdate('immediate');

        $this->assertSame(
            'off',
            $result,
            'AC-3 (per-option): gateAutoConfirmOnPreUpdate MUSS "off" zurueckgeben, wenn ' .
            'default_shipping_type leer ist — auch wenn der UI-Wert "immediate" lautete.'
        );
    }

    /**
     * AC-3 (PER-OPTION): gateAutoConfirmOnPreUpdate() laesst Wert unveraendert,
     * wenn default_shipping_type gesetzt ist.
     */
    public function test_gate_auto_confirm_on_pre_update_passes_through_when_shipping_set(): void
    {
        Monkey\Functions\when('get_option')->alias(
            function (string $key, $default = false) {
                if ($key === 'spreadconnect_default_shipping_type') {
                    return 'PREMIUM';
                }
                return $default;
            }
        );

        $result = SettingsValidator::gateAutoConfirmOnPreUpdate('immediate');

        $this->assertSame(
            'immediate',
            $result,
            'AC-3 (per-option): gateAutoConfirmOnPreUpdate MUSS den Wert unveraendert ' .
            'durchreichen, wenn default_shipping_type gesetzt ist.'
        );

        $resultAfterMinutes = SettingsValidator::gateAutoConfirmOnPreUpdate('after_minutes');
        $this->assertSame(
            'after_minutes',
            $resultAfterMinutes,
            'AC-3 (per-option): "after_minutes" passt ebenfalls durch wenn shipping_type gesetzt.'
        );

        $resultOff = SettingsValidator::gateAutoConfirmOnPreUpdate('off');
        $this->assertSame(
            'off',
            $resultOff,
            'AC-3 (per-option): "off" bleibt "off" (idempotent).'
        );
    }

    // ===================================================================
    // AC-1 / sanitizeOne dispatcher tests (per-option mode).
    // ===================================================================

    /**
     * AC-1 (sanitizeOne): scalar in -> scalar out fuer api_key.
     */
    public function test_sanitize_one_returns_scalar_for_api_key(): void
    {
        self::stubSanitizeHelpers();
        Monkey\Functions\when('current_filter')->justReturn('sanitize_option_spreadconnect_api_key');

        $result = SettingsValidator::sanitizeOne('  my-secret-key  ', 'spreadconnect_api_key');

        $this->assertIsString($result);
        $this->assertSame(
            'my-secret-key',
            $result,
            'AC-1: sanitizeOne fuer api_key trimmt + sanitize_text_field.'
        );
    }

    /**
     * AC-1 (sanitizeOne): bool fuer use_staging.
     */
    public function test_sanitize_one_returns_bool_for_use_staging(): void
    {
        self::stubSanitizeHelpers();
        Monkey\Functions\when('current_filter')->justReturn('sanitize_option_spreadconnect_use_staging');

        $true = SettingsValidator::sanitizeOne('1', 'spreadconnect_use_staging');
        $this->assertSame(true, $true, 'AC-1: sanitizeOne("1") fuer use_staging -> true.');

        $false = SettingsValidator::sanitizeOne('', 'spreadconnect_use_staging');
        $this->assertSame(false, $false, 'AC-1: sanitizeOne("") fuer use_staging -> false.');
    }

    /**
     * AC-1 (sanitizeOne): enum fallback fuer auto_confirm bei ungueltigem Wert.
     */
    public function test_sanitize_one_falls_back_for_invalid_auto_confirm(): void
    {
        self::stubSanitizeHelpers();
        Monkey\Functions\when('current_filter')->justReturn('sanitize_option_spreadconnect_auto_confirm');

        $result = SettingsValidator::sanitizeOne('lolwat', 'spreadconnect_auto_confirm');

        $this->assertSame(
            OptionsDefaults::DEFAULTS['spreadconnect_auto_confirm'],
            $result,
            'AC-1: sanitizeOne fuer ungueltigen auto_confirm -> Default ("off").'
        );
    }

    /**
     * AC-1 (sanitizeOne): unknown option name -> value unveraendert.
     */
    public function test_sanitize_one_returns_value_unchanged_for_unknown_option(): void
    {
        self::stubSanitizeHelpers();
        Monkey\Functions\when('current_filter')->justReturn('');

        $result = SettingsValidator::sanitizeOne('weirdvalue', 'spreadconnect_unknown_key');

        $this->assertSame(
            'weirdvalue',
            $result,
            'AC-1: sanitizeOne fuer unbekannten Option-Namen MUSS den Value unveraendert ' .
            'durchreichen — der Dispatcher darf Werte fremder Options nicht schlucken.'
        );
    }

    /**
     * AC-1 (sanitizeOne): 2-arg-Aufruf — Option-Name kommt als 2. Argument
     * (WP 4.7+ leitet Option-Namen via sanitize_option_$name an Filter weiter).
     */
    public function test_sanitize_one_uses_explicit_option_name_argument(): void
    {
        self::stubSanitizeHelpers();
        // current_filter() liefert irrefuehrenden Filter — der explizite
        // 2. Arg muss Vorrang haben.
        Monkey\Functions\when('current_filter')->justReturn('some_other_filter');

        $result = SettingsValidator::sanitizeOne('  trim-me  ', 'spreadconnect_api_key');

        $this->assertSame(
            'trim-me',
            $result,
            'AC-1: sanitizeOne MUSS den expliziten 2. Argument-Option-Namen bevorzugen vor current_filter().'
        );
    }

    /**
     * AC-1 (sanitizeOne): Single-Arg-Aufruf -> resolution via current_filter().
     */
    public function test_sanitize_one_resolves_option_via_current_filter_fallback(): void
    {
        self::stubSanitizeHelpers();
        Monkey\Functions\when('current_filter')->justReturn('sanitize_option_spreadconnect_api_key');

        // Aufruf mit nur einem Argument (option name = null -> fallback).
        $result = SettingsValidator::sanitizeOne('  fallback-test  ');

        $this->assertSame(
            'fallback-test',
            $result,
            'AC-1: sanitizeOne MUSS auf current_filter() zurueckfallen, wenn der ' .
            'Option-Name nicht als 2. Argument uebergeben wird.'
        );
    }

    // ===================================================================
    // AC-4: Ungueltiger Enum-Wert faellt auf Default zurueck
    // ===================================================================

    /**
     * AC-4: stock_sync_interval='99h' -> Default '6h'.
     */
    public function test_sanitize_falls_back_to_default_for_invalid_enum_value(): void
    {
        self::stubSanitizeHelpers();

        $input = [
            'spreadconnect_stock_sync_interval' => '99h',
            // Mit shipping_type gesetzt, damit AC-3 nicht greift.
            'spreadconnect_default_shipping_type' => 'PREMIUM',
            'spreadconnect_auto_confirm'          => 'pwnd',
        ];

        $output = SettingsValidator::sanitize($input);

        $this->assertSame(
            '6h',
            $output['spreadconnect_stock_sync_interval'],
            'AC-4: stock_sync_interval MUSS bei ungueltigem Enum auf "6h" (Default aus ' .
            'OptionsDefaults::DEFAULTS / architecture.md Z. 332) zurueckfallen.'
        );
        $this->assertSame(
            'off',
            $output['spreadconnect_auto_confirm'],
            'AC-4: auto_confirm MUSS bei ungueltigem Enum auf "off" (Default) zurueckfallen.'
        );
    }

    // ===================================================================
    // AC-5: Range-Clamp fuer Integer-Felder
    // ===================================================================

    /**
     * AC-5: live_cache_ttl_seconds = 30 -> 60 (clamp lower bound).
     */
    public function test_sanitize_clamps_live_cache_ttl_to_60_900_range(): void
    {
        self::stubSanitizeHelpers();

        // Unter Min.
        $output1 = SettingsValidator::sanitize([
            'spreadconnect_default_shipping_type'   => 'PREMIUM',
            'spreadconnect_live_cache_ttl_seconds'  => 30,
        ]);
        $this->assertSame(
            60,
            $output1['spreadconnect_live_cache_ttl_seconds'],
            'AC-5: live_cache_ttl_seconds=30 (unter Min) MUSS auf 60 geclamped werden.'
        );

        // Ueber Max.
        $output2 = SettingsValidator::sanitize([
            'spreadconnect_default_shipping_type'   => 'PREMIUM',
            'spreadconnect_live_cache_ttl_seconds'  => 9999,
        ]);
        $this->assertSame(
            900,
            $output2['spreadconnect_live_cache_ttl_seconds'],
            'AC-5: live_cache_ttl_seconds=9999 (ueber Max) MUSS auf 900 geclamped werden.'
        );

        // In Range bleibt.
        $output3 = SettingsValidator::sanitize([
            'spreadconnect_default_shipping_type'   => 'PREMIUM',
            'spreadconnect_live_cache_ttl_seconds'  => 300,
        ]);
        $this->assertSame(
            300,
            $output3['spreadconnect_live_cache_ttl_seconds'],
            'AC-5: live_cache_ttl_seconds=300 (in Range) MUSS unveraendert bleiben.'
        );
    }

    /**
     * AC-5: failed_ops_retention_days=999 -> 365; webhook_log_retention_days=3 -> 7.
     */
    public function test_sanitize_clamps_retention_days_to_7_365_range(): void
    {
        self::stubSanitizeHelpers();

        $output = SettingsValidator::sanitize([
            'spreadconnect_default_shipping_type'         => 'PREMIUM',
            'spreadconnect_failed_ops_retention_days'     => 999,
            'spreadconnect_webhook_log_retention_days'    => 3,
        ]);

        $this->assertSame(
            365,
            $output['spreadconnect_failed_ops_retention_days'],
            'AC-5: failed_ops_retention_days=999 (ueber Max) MUSS auf 365 geclamped werden.'
        );
        $this->assertSame(
            7,
            $output['spreadconnect_webhook_log_retention_days'],
            'AC-5: webhook_log_retention_days=3 (unter Min) MUSS auf 7 geclamped werden.'
        );
    }

    /**
     * AC-5: low_stock_threshold via absint normalisiert (>= 0, kein Upper-Clamp).
     */
    public function test_sanitize_normalises_low_stock_threshold_via_absint(): void
    {
        self::stubSanitizeHelpers();

        $output = SettingsValidator::sanitize([
            'spreadconnect_default_shipping_type'  => 'PREMIUM',
            'spreadconnect_low_stock_threshold'    => '-5',
        ]);

        $this->assertIsInt($output['spreadconnect_low_stock_threshold']);
        $this->assertGreaterThanOrEqual(
            0,
            $output['spreadconnect_low_stock_threshold'],
            'AC-5: low_stock_threshold MUSS >= 0 sein (absint).'
        );
        $this->assertSame(
            5,
            $output['spreadconnect_low_stock_threshold'],
            'AC-5: low_stock_threshold="-5" -> 5 via absint().'
        );

        // Grosser Wert -> kein Upper-Clamp.
        $output2 = SettingsValidator::sanitize([
            'spreadconnect_default_shipping_type'  => 'PREMIUM',
            'spreadconnect_low_stock_threshold'    => 99999,
        ]);
        $this->assertSame(
            99999,
            $output2['spreadconnect_low_stock_threshold'],
            'AC-5: low_stock_threshold hat keinen Upper-Clamp.'
        );
    }

    // ===================================================================
    // AC-6: notify_emails normalisiert + filtert invalid Tokens
    // ===================================================================

    /**
     * AC-6: 'admin@example.com, broken-no-at, ops@example.com '
     *       -> 'admin@example.com, ops@example.com'.
     */
    public function test_sanitize_filters_invalid_email_tokens_and_rejoins(): void
    {
        self::stubSanitizeHelpers();

        $output = SettingsValidator::sanitize([
            'spreadconnect_default_shipping_type' => 'PREMIUM',
            'spreadconnect_notify_emails'         => 'admin@example.com, broken-no-at, ops@example.com ',
        ]);

        $this->assertSame(
            'admin@example.com, ops@example.com',
            $output['spreadconnect_notify_emails'],
            'AC-6: Invalid Tokens (per sanitize_email-Failure) werden entfernt, ' .
            'Whitespace getrimmt, valide Tokens bleiben in Original-Reihenfolge mit ", "-Separator.'
        );
    }

    /**
     * AC-6: notify_emails - leere Eingabe ergibt leeren String.
     */
    public function test_sanitize_returns_empty_string_for_empty_email_list(): void
    {
        self::stubSanitizeHelpers();

        $output = SettingsValidator::sanitize([
            'spreadconnect_default_shipping_type' => 'PREMIUM',
            'spreadconnect_notify_emails'         => '',
        ]);

        $this->assertSame(
            '',
            $output['spreadconnect_notify_emails'],
            'AC-6: Leere Eingabe fuer notify_emails MUSS zu leerem String werden.'
        );
    }

    /**
     * AC-6: notify_emails - alle invalid -> leerer String.
     */
    public function test_sanitize_returns_empty_string_when_all_emails_invalid(): void
    {
        self::stubSanitizeHelpers();

        $output = SettingsValidator::sanitize([
            'spreadconnect_default_shipping_type' => 'PREMIUM',
            'spreadconnect_notify_emails'         => 'no-at-symbol, also-bad, !!!',
        ]);

        $this->assertSame(
            '',
            $output['spreadconnect_notify_emails'],
            'AC-6: Wenn alle Email-Tokens invalid sind, MUSS der Output leer sein.'
        );
    }

    // ===================================================================
    // AC-7: Boolean-Toggles -> strict PHP-bool
    // ===================================================================

    /**
     * AC-7: '1', 'on', true -> true; '0', '', false -> false.
     */
    public function test_sanitize_casts_boolean_fields_to_strict_php_bool(): void
    {
        self::stubSanitizeHelpers();

        $truthyInputs = [
            'spreadconnect_default_shipping_type'        => 'PREMIUM',
            'spreadconnect_use_staging'                  => '1',
            'spreadconnect_auto_cancel_mirror'           => 'on',
            'spreadconnect_pull_images'                  => true,
            'spreadconnect_force_repull_images'          => '1',
            'spreadconnect_notify_on_order_failure'      => 'on',
            'spreadconnect_notify_on_sync_failure'       => true,
            'spreadconnect_notify_on_webhook_failure'    => '1',
        ];
        $output = SettingsValidator::sanitize($truthyInputs);

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
            $this->assertIsBool(
                $output[$key],
                sprintf('AC-7: "%s" MUSS strikt PHP-bool sein (kein String).', $key)
            );
            $this->assertTrue(
                $output[$key],
                sprintf('AC-7: Truthy Input fuer "%s" MUSS true ergeben.', $key)
            );
        }

        // Falsy.
        $falsyInputs = [
            'spreadconnect_default_shipping_type'        => 'PREMIUM',
            'spreadconnect_use_staging'                  => '0',
            'spreadconnect_auto_cancel_mirror'           => '',
            'spreadconnect_pull_images'                  => false,
            'spreadconnect_force_repull_images'          => '0',
            'spreadconnect_notify_on_order_failure'      => '',
            'spreadconnect_notify_on_sync_failure'       => false,
            'spreadconnect_notify_on_webhook_failure'    => '0',
        ];
        $output2 = SettingsValidator::sanitize($falsyInputs);

        foreach ($boolKeys as $key) {
            $this->assertIsBool(
                $output2[$key],
                sprintf('AC-7: Falsy "%s" MUSS strikt PHP-bool sein.', $key)
            );
            $this->assertFalse(
                $output2[$key],
                sprintf('AC-7: Falsy Input fuer "%s" MUSS false ergeben.', $key)
            );
        }
    }

    /**
     * AC-7: Missing-Key fuer Boolean-Field -> false (Checkbox-Unchecked-Semantik).
     */
    public function test_sanitize_treats_missing_boolean_key_as_false(): void
    {
        self::stubSanitizeHelpers();

        // Input ohne use_staging-Key (Checkbox unchecked).
        $output = SettingsValidator::sanitize([
            'spreadconnect_default_shipping_type' => 'PREMIUM',
            // KEINE Boolean-Toggles uebermittelt.
        ]);

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
            $this->assertArrayHasKey(
                $key,
                $output,
                sprintf('AC-7: Missing "%s" MUSS trotzdem im Output stehen (17-Key-Garantie).', $key)
            );
            $this->assertIsBool(
                $output[$key],
                sprintf('AC-7: Missing "%s" MUSS strikt PHP-bool sein.', $key)
            );
            $this->assertFalse(
                $output[$key],
                sprintf(
                    'AC-7: Missing-Key fuer Boolean-Field "%s" MUSS false ergeben ' .
                    '(Checkbox-Unchecked-Semantik).',
                    $key
                )
            );
        }
    }

    // ===================================================================
    // AC-8: Roundtrip — 17 Felder werden via update_option persistiert
    // ===================================================================

    /**
     * AC-8: Komplettes 17-Felder-Input -> alle 17 Options sind nach
     * sanitize+update_option-Pipeline persistiert.
     */
    public function test_full_form_submit_persists_all_seventeen_options(): void
    {
        self::stubSanitizeHelpers();

        // In-memory Options-Map.
        $optionsMap = [];
        Monkey\Functions\when('update_option')->alias(
            function (string $key, $value, $autoload = null) use (&$optionsMap) {
                $optionsMap[$key] = $value;
                return true;
            }
        );
        Monkey\Functions\when('get_option')->alias(
            function (string $key, $default = false) use (&$optionsMap) {
                return $optionsMap[$key] ?? $default;
            }
        );

        // Komplettes Form-Submit-Input.
        $input = [
            'spreadconnect_api_key'                     => 'sk-test-1234',
            'spreadconnect_use_staging'                 => '1',
            'spreadconnect_auto_confirm'                => 'immediate',
            'spreadconnect_auto_confirm_minutes'        => '15',
            'spreadconnect_default_shipping_type'       => 'PREMIUM',
            'spreadconnect_auto_cancel_mirror'          => '1',
            'spreadconnect_pull_images'                 => '1',
            'spreadconnect_force_repull_images'         => '0',
            'spreadconnect_stock_sync_interval'         => '12h',
            'spreadconnect_low_stock_threshold'         => '5',
            'spreadconnect_live_cache_ttl_seconds'      => '600',
            'spreadconnect_notify_emails'               => 'a@b.com, c@d.com',
            'spreadconnect_notify_on_order_failure'     => '1',
            'spreadconnect_notify_on_sync_failure'      => '1',
            'spreadconnect_notify_on_webhook_failure'   => '0',
            'spreadconnect_failed_ops_retention_days'   => '180',
            'spreadconnect_webhook_log_retention_days'  => '120',
        ];

        // Sanitize-Pipeline simulieren.
        $sanitized = SettingsValidator::sanitize($input);

        // Persist via update_option-Spy.
        foreach ($sanitized as $key => $value) {
            update_option($key, $value);
        }

        // Verifizieren: alle 17 Keys persistiert.
        foreach (self::expectedFormFields() as $key) {
            $this->assertArrayHasKey(
                $key,
                $optionsMap,
                sprintf('AC-8: Option "%s" MUSS nach Save persistiert sein.', $key)
            );
        }

        // Stichproben: Werte korrekt sanitized + persistiert.
        $this->assertSame('sk-test-1234', $optionsMap['spreadconnect_api_key']);
        $this->assertTrue($optionsMap['spreadconnect_use_staging']);
        $this->assertSame('immediate', $optionsMap['spreadconnect_auto_confirm']);
        $this->assertSame(15, $optionsMap['spreadconnect_auto_confirm_minutes']);
        $this->assertSame('PREMIUM', $optionsMap['spreadconnect_default_shipping_type']);
        $this->assertSame('12h', $optionsMap['spreadconnect_stock_sync_interval']);
        $this->assertSame(600, $optionsMap['spreadconnect_live_cache_ttl_seconds']);
        $this->assertSame('a@b.com, c@d.com', $optionsMap['spreadconnect_notify_emails']);
        $this->assertSame(180, $optionsMap['spreadconnect_failed_ops_retention_days']);
        $this->assertSame(120, $optionsMap['spreadconnect_webhook_log_retention_days']);

        // Genau 17 persistiert (nicht mehr, nicht weniger).
        $this->assertCount(
            17,
            array_intersect_key($optionsMap, array_flip(self::expectedFormFields())),
            'AC-8: Exakt 17 Slice-11-Options MUSSEN persistiert sein.'
        );
    }

    /**
     * AC-8 / Constraint: sanitize() liefert immer alle 17 Keys, auch wenn
     * Input partial ist. Defaults aus OptionsDefaults::DEFAULTS verhindern,
     * dass missing Keys Options loeschen.
     */
    public function test_sanitize_output_contains_all_seventeen_keys_for_partial_input(): void
    {
        self::stubSanitizeHelpers();

        // Nur 1 Key uebermittelt.
        $output = SettingsValidator::sanitize([
            'spreadconnect_api_key' => 'partial-test',
        ]);

        $this->assertCount(
            17,
            array_intersect_key($output, array_flip(self::expectedFormFields())),
            'AC-8 / Constraint: sanitize-Output MUSS immer alle 17 Slice-11-Keys enthalten — ' .
            'verhindert Option-Loeschung bei partial Form-Submit.'
        );

        // Missing Keys haben Default-Werte.
        $this->assertSame(
            OptionsDefaults::DEFAULTS['spreadconnect_stock_sync_interval'],
            $output['spreadconnect_stock_sync_interval'],
            'AC-8: Missing Key MUSS auf Default aus OptionsDefaults zurueckfallen.'
        );
    }

    /**
     * AC-8 / Constraint: sanitize() ist idempotent (sanitize(sanitize($x)) === sanitize($x)).
     * Wichtig fuer Slice 45 (Re-Validation nach Import).
     */
    public function test_sanitize_is_idempotent(): void
    {
        self::stubSanitizeHelpers();

        $input = [
            'spreadconnect_api_key'                     => '  trimmed-key  ',
            'spreadconnect_use_staging'                 => '1',
            'spreadconnect_auto_confirm'                => 'immediate',
            'spreadconnect_auto_confirm_minutes'        => '5',
            'spreadconnect_default_shipping_type'       => 'PREMIUM',
            'spreadconnect_auto_cancel_mirror'          => 'on',
            'spreadconnect_pull_images'                 => '1',
            'spreadconnect_force_repull_images'         => '',
            'spreadconnect_stock_sync_interval'         => '6h',
            'spreadconnect_low_stock_threshold'         => '10',
            'spreadconnect_live_cache_ttl_seconds'      => '300',
            'spreadconnect_notify_emails'               => 'a@b.com',
            'spreadconnect_notify_on_order_failure'     => '1',
            'spreadconnect_notify_on_sync_failure'      => '1',
            'spreadconnect_notify_on_webhook_failure'   => '',
            'spreadconnect_failed_ops_retention_days'   => '90',
            'spreadconnect_webhook_log_retention_days'  => '90',
        ];

        $first  = SettingsValidator::sanitize($input);
        $second = SettingsValidator::sanitize($first);

        $this->assertSame(
            $first,
            $second,
            'AC-8 / Constraint: sanitize MUSS idempotent sein — ' .
            'sanitize(sanitize($x)) === sanitize($x). Wichtig fuer Slice 45 Re-Validation.'
        );
    }

    // ===================================================================
    // AC-9: render() ohne manage_woocommerce -> wp_die
    // ===================================================================

    /**
     * AC-9: render() ohne manage_woocommerce -> wp_die() (RuntimeException via Stub).
     */
    public function test_render_aborts_without_manage_woocommerce_capability(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubSettingsApiCallbacks();

        Monkey\Functions\when('current_user_can')->alias(function ($cap) {
            // User hat KEINE manage_woocommerce-Capability.
            return false;
        });

        // Initial-OB-Level festhalten und nach dem Test sauber stellen, ohne
        // PHPUnit's eigene Output-Buffer zu schliessen (Risky-Test-Vermeidung).
        $initialObLevel = ob_get_level();
        $thrown         = false;
        $caughtMessage  = '';

        ob_start();
        try {
            Settings::render();
        } catch (RuntimeException $e) {
            // wp_die-Stub wirft RuntimeException (siehe tests/stubs/wp-functions.php).
            $thrown        = true;
            $caughtMessage = $e->getMessage();
        }

        // Nur unsere eigenen Output-Buffer schliessen — PHPUnit-OB nicht beruehren.
        while (ob_get_level() > $initialObLevel) {
            ob_end_clean();
        }

        $this->assertTrue(
            $thrown,
            'AC-9: render() OHNE manage_woocommerce MUSS via wp_die() abbrechen — ' .
            'kein Form-Output, keine Settings-API-Iteration.'
        );
        $this->assertStringContainsString(
            'wp_die',
            $caughtMessage,
            'AC-9: Die Termination-Exception MUSS aus wp_die() stammen.'
        );
    }

    /**
     * AC-9: render() MIT manage_woocommerce laeuft durch (kein wp_die).
     */
    public function test_render_proceeds_with_manage_woocommerce_capability(): void
    {
        self::stubI18nAndEscapeHelpers();
        self::stubSettingsApiCallbacks();

        Monkey\Functions\when('current_user_can')->alias(function ($cap) {
            return $cap === 'manage_woocommerce';
        });

        // get_option fuer eventuelle Field-Render-Helpers.
        Monkey\Functions\when('get_option')->alias(function ($key, $default = false) {
            return OptionsDefaults::DEFAULTS[$key] ?? $default;
        });

        $initialObLevel = ob_get_level();
        $thrown         = false;

        ob_start();
        try {
            Settings::render();
        } catch (RuntimeException $e) {
            $thrown = true;
        }

        while (ob_get_level() > $initialObLevel) {
            ob_end_clean();
        }

        $this->assertFalse(
            $thrown,
            'AC-9: render() MIT manage_woocommerce darf NICHT via wp_die() abbrechen.'
        );
    }

    // ===================================================================
    // AC-10: Alle UI-Strings werden via __() / esc_html__() mit Domain
    // 'spreadconnect-pod' gewrapped (Statische Source-Analyse).
    // ===================================================================

    /**
     * AC-10: __() / esc_html__() / _e()-Aufrufe in Settings.php verwenden
     * konsistent die Text-Domain 'spreadconnect-pod' (oder die zentrale
     * `self::TEXT_DOMAIN`-Konstante, die diesen Wert haelt).
     */
    public function test_all_ui_strings_use_spreadconnect_pod_text_domain(): void
    {
        $source = self::settingsViewSource();

        // 1. TEXT_DOMAIN-Konstante existiert in der Klasse.
        $reflection = new ReflectionClass(Settings::class);
        $hasConst = $reflection->getReflectionConstants();

        $textDomainValue = null;
        foreach ($hasConst as $rc) {
            if ($rc->getName() === 'TEXT_DOMAIN') {
                $textDomainValue = $rc->getValue();
                break;
            }
        }

        $this->assertSame(
            'spreadconnect-pod',
            $textDomainValue,
            'AC-10: Settings::TEXT_DOMAIN-Konstante MUSS exakt "spreadconnect-pod" sein.'
        );

        // 2. Alle __()/esc_html__()/_e()-Aufrufe nutzen entweder die literal
        //    'spreadconnect-pod'-Domain ODER die self::TEXT_DOMAIN-Konstante.
        //    Pattern: __( '...', self::TEXT_DOMAIN ) oder esc_html__('...', 'spreadconnect-pod').
        $callPattern = '/(?:_e|__|esc_html__|esc_attr__)\s*\(\s*[^,()]+(?:\(\s*[^()]*\s*\))?\s*,\s*([^)]+)\)/';
        $matches = [];
        preg_match_all($callPattern, $source, $matches);

        $this->assertNotEmpty(
            $matches[0],
            'AC-10: Settings.php MUSS mindestens einen __() / esc_html__() / _e()-Aufruf enthalten.'
        );

        $allowedDomainTokens = [
            "'spreadconnect-pod'",
            '"spreadconnect-pod"',
            'self::TEXT_DOMAIN',
        ];

        foreach ($matches[1] as $idx => $domainArg) {
            $domainArg = trim($domainArg);
            $matchesAllowed = false;
            foreach ($allowedDomainTokens as $allowed) {
                if (str_contains($domainArg, $allowed)) {
                    $matchesAllowed = true;
                    break;
                }
            }
            $this->assertTrue(
                $matchesAllowed,
                sprintf(
                    'AC-10: i18n-Wrapper-Aufruf #%d nutzt unerlaubte Text-Domain "%s" — ' .
                    'erwartet: "spreadconnect-pod" oder self::TEXT_DOMAIN. Full match: %s',
                    $idx,
                    $domainArg,
                    $matches[0][$idx]
                )
            );
        }
    }

    /**
     * AC-10: Keine literalen User-facing-Strings in echo/print ohne Wrapper.
     * Heuristik: HTML-Tags + visible-Text-Patterns sollten via __()/esc_html__() laufen.
     * Wir pruefen, dass <h1>-Title via esc_html__() laeuft.
     */
    public function test_h1_title_uses_esc_html_with_text_domain(): void
    {
        $source = self::settingsViewSource();

        // Heading muss via esc_html__() oder __() laufen — Pattern erlaubt
        // sowohl Concat-Form 'esc_html__()' als auch echo/printf-Aufrufe
        // mit i18n-Wrapper innerhalb des H1-Blocks.
        $this->assertMatchesRegularExpression(
            '/<h1>(?:[^\']|\'\s*\.\s*)*(?:esc_html__|esc_html_e|__|_e)\s*\(/s',
            $source,
            'AC-10: <h1>-Title MUSS via esc_html__() / esc_html_e() / __() / _e() ' .
            'gewrapped sein — kein literaler User-facing-String.'
        );

        // Und die Domain muss "spreadconnect-pod" sein (oder via TEXT_DOMAIN-Konstante).
        // Wir verifizieren das, indem wir alle <h1>-Bloecke extrahieren und ihren
        // i18n-Aufruf gegen die TEXT_DOMAIN-Whitelist matchen.
        $this->assertMatchesRegularExpression(
            "/<h1>[\\s\\S]*?(?:'spreadconnect-pod'|self::TEXT_DOMAIN)/",
            $source,
            'AC-10: <h1>-Title-i18n-Aufruf MUSS Text-Domain "spreadconnect-pod" oder self::TEXT_DOMAIN nutzen.'
        );
    }

    /**
     * AC-10: Klasse importiert keine Hardcoded-Domain anders als
     * 'spreadconnect-pod' (z. B. nicht "default", "wp-core", etc.).
     */
    public function test_no_other_text_domains_used_in_settings_view(): void
    {
        $source = self::settingsViewSource();

        // Verbotene/abweichende Text-Domains.
        $forbiddenDomains = [
            "'default'",
            "'wp-core'",
            "'spreadconnect_pod'",
            "'spreadconnect'",
        ];

        foreach ($forbiddenDomains as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                sprintf(
                    'AC-10: Settings.php darf NICHT die Text-Domain %s verwenden — ' .
                    'erlaubt ist nur "spreadconnect-pod".',
                    $forbidden
                )
            );
        }
    }
}
