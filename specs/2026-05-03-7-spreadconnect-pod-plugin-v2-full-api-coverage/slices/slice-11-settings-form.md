# Slice 11: Settings-Form + Settings-Validator

> **Slice 11 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-11-settings-form` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-05-options-defaults", "slice-06-i18n-textdomain"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `register_setting`/`add_settings_section`/`add_settings_field`/`get_option`/`update_option`/`__()`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA-Check via WP-Admin: Settings-Page rendern, Save triggern, Options im phpMyAdmin verifizieren) |
| **Health Endpoint** | `n/a` (Settings-Page-Logik, keine Routes) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer WP Settings API + Options API; in-memory Options-Map zur Sanitize-Verifikation) |

---

## Ziel

Liefert die `Hub\View\Settings`-Page (WP Settings API mit allen Form-Feldern aus der WP-Options-Tabelle) und den `Settings\SettingsValidator::sanitize($input)`, der die Auto-Confirm-Gating-Regel server-seitig erzwingt: bei leerem `spreadconnect_default_shipping_type` wird `spreadconnect_auto_confirm` zwingend auf `off` gezwungen — unabhaengig vom UI-Input. Damit ist Slice 7 "Hub-Page Skeleton + Settings" persistierbar; Slice 12 (Test-Connection-AJAX), Slice 13 (Hub-Routing), Slice 14 (Webhook-Secret-Reveal) bauen darauf auf.

---

## Form-Felder (Single Source of Truth = `architecture.md` -> WP Options Z. 323-341)

> Diese Tabelle listet die in dieser Slice **gerenderten** Felder. Defaults stammen aus `slice-05-options-defaults`; Validation aus `architecture.md`. **Nicht in dieser Slice:** `spreadconnect_webhook_secret` (Slice 14), `spreadconnect_pt_index_etag` (kein UI-Feld, system-managed).

| # | Option Key | UI-Control | Section (Wireframe) | Validation (Sanitizer) |
|---|---|---|---|---|
| 1 | `spreadconnect_api_key` | masked password input + `[Show]/[Edit]` | ① API Connection | `sanitize_text_field` + non-empty trim |
| 2 | `spreadconnect_use_staging` | checkbox toggle | ① API Connection | cast to PHP `bool` |
| 3 | `spreadconnect_auto_confirm` | radio group `off`/`immediate`/`after_minutes` | ⑥ Order Behavior | enum-whitelist + Auto-Confirm-Gating (siehe AC-3) |
| 4 | `spreadconnect_auto_confirm_minutes` | int input (visible only when option 3 = `after_minutes`) | ⑥ Order Behavior | `absint`; >= 0 |
| 5 | `spreadconnect_default_shipping_type` | radio group / dropdown (None + SC `shippingType.id` list) | ⑥ Order Behavior | `sanitize_text_field`; empty allowed (= None) |
| 6 | `spreadconnect_auto_cancel_mirror` | checkbox toggle | ⑥ Order Behavior | cast to PHP `bool` |
| 7 | `spreadconnect_pull_images` | checkbox toggle | ⑦ Catalog Sync | cast to PHP `bool` |
| 8 | `spreadconnect_force_repull_images` | checkbox toggle | ⑦ Catalog Sync | cast to PHP `bool` |
| 9 | `spreadconnect_stock_sync_interval` | dropdown enum `1h`/`4h`/`6h`/`12h`/`24h` | ⑦ Catalog Sync | enum-whitelist; fallback = `6h` |
| 10 | `spreadconnect_low_stock_threshold` | int input | ⑦ Catalog Sync | `absint`; >= 0 |
| 11 | `spreadconnect_live_cache_ttl_seconds` | dropdown / int input | ⑦ Catalog Sync | `absint`; clamp 60..900 |
| 12 | `spreadconnect_notify_emails` | text input (comma-sep) | ⑧ Failure Notifications | per-token `sanitize_email`; drop invalid; rejoin with `, ` |
| 13 | `spreadconnect_notify_on_order_failure` | checkbox toggle | ⑧ Failure Notifications | cast to PHP `bool` |
| 14 | `spreadconnect_notify_on_sync_failure` | checkbox toggle | ⑧ Failure Notifications | cast to PHP `bool` |
| 15 | `spreadconnect_notify_on_webhook_failure` | checkbox toggle | ⑧ Failure Notifications | cast to PHP `bool` |
| 16 | `spreadconnect_failed_ops_retention_days` | int input | ⑧ Failure Notifications | `absint`; clamp 7..365 |
| 17 | `spreadconnect_webhook_log_retention_days` | int input | ⑧ Failure Notifications | `absint`; clamp 7..365 |

> Done-Signal aus `slim-slices.md` spricht von "alle 19 Felder werden persistiert". Die 17 oben + `spreadconnect_webhook_secret` (Slice 14) + `spreadconnect_pt_index_etag` (system-managed) ergeben die 19 in der Architecture-Tabelle. Slice 11 deckt **17 von 19** ab; die anderen 2 sind explizit Out-of-Scope (siehe Constraints).

---

## Acceptance Criteria

1) **GIVEN** WP-Admin laedt die Settings-Page (`?page=spreadconnect&section=settings`)
   **WHEN** `Hub\View\Settings::render()` ausgefuehrt wird
   **THEN** registriert die Page genau **eine** Settings-Group `spreadconnect_settings` via `register_setting('spreadconnect_settings', $option_name, ['sanitize_callback' => [SettingsValidator::class, 'sanitize']])` fuer jede der 17 Form-Felder aus der Tabelle oben — Sanitize-Callback zeigt auf `SettingsValidator::sanitize`. Sections (① API Connection, ⑥ Order Behavior, ⑦ Catalog Sync, ⑧ Failure Notifications) sind via `add_settings_section` registriert; `③ Webhook Security` (Slice 14) und `② Test Connection` (Slice 12) sind in dieser Slice **nicht** Teil der Settings-API-Registration.

2) **GIVEN** ein Form-Submit mit valider Default-Shipping-Type-Selection (`'spreadconnect_default_shipping_type' => 'PREMIUM'`)
   **WHEN** WP `SettingsValidator::sanitize($input)` aufruft mit `$input['spreadconnect_auto_confirm'] => 'immediate'`
   **THEN** liefert der Sanitizer im Output `'spreadconnect_auto_confirm' => 'immediate'` und `'spreadconnect_default_shipping_type' => 'PREMIUM'` — Auto-Confirm bleibt unveraendert, Gating ist erfuellt.

3) **GIVEN** ein Form-Submit mit leerem Default-Shipping-Type (`'spreadconnect_default_shipping_type' => ''`)
   **WHEN** `SettingsValidator::sanitize($input)` aufruft mit `$input['spreadconnect_auto_confirm'] => 'immediate'` (oder `'after_minutes'`)
   **THEN** zwingt der Sanitizer `'spreadconnect_auto_confirm' => 'off'` im Output — auch wenn das UI-Eingabe `'immediate'` lautet (Auto-Confirm-Gating-Regel aus `architecture.md` Z. 326 / `discovery.md` Z. 590). Der Output enthaelt dabei weiterhin alle anderen 16 Keys mit ihren sanitized Werten.

4) **GIVEN** ein Form-Submit mit ungueltigem Enum-Wert (z. B. `'spreadconnect_stock_sync_interval' => '99h'`)
   **WHEN** `SettingsValidator::sanitize($input)` aufgerufen wird
   **THEN** faellt der Wert auf den Default aus `OptionsDefaults::DEFAULTS` zurueck (`'6h'` per Architecture Z. 332). Gleiche Regel fuer `spreadconnect_auto_confirm` (`off`).

5) **GIVEN** Range-Validierung fuer Integer-Felder
   **WHEN** `SettingsValidator::sanitize` aufgerufen wird mit `'spreadconnect_live_cache_ttl_seconds' => 30` (unter Min) und `'spreadconnect_failed_ops_retention_days' => 999` (ueber Max)
   **THEN** wird `live_cache_ttl_seconds` auf `60` geclamped und `failed_ops_retention_days` auf `365`. Aequivalent fuer `webhook_log_retention_days` (clamp 7..365). `low_stock_threshold` wird via `absint` auf `>= 0` normalisiert (kein Upper-Clamp).

6) **GIVEN** ein Form-Submit mit komma-separierter Email-Liste `'spreadconnect_notify_emails' => 'admin@example.com, broken-no-at, ops@example.com '`
   **WHEN** `SettingsValidator::sanitize` aufgerufen wird
   **THEN** liefert der Output `'admin@example.com, ops@example.com'` (whitespace-trimmed, invalid token `broken-no-at` per `sanitize_email`-Failure entfernt, valide Tokens bleiben in Original-Reihenfolge, Separator `, `).

7) **GIVEN** Boolean-Toggles (`spreadconnect_use_staging`, `spreadconnect_auto_cancel_mirror`, `spreadconnect_pull_images`, `spreadconnect_force_repull_images`, `spreadconnect_notify_on_*`)
   **WHEN** `sanitize` mit truthy/falsy Inputs aufgerufen wird (`'1'`, `'0'`, `'on'`, `''`, `true`, `false`)
   **THEN** liefert der Output strikt PHP-`bool` (nicht String) — `'1'`, `'on'`, `true` -> `true`; `'0'`, `''`, `false`, missing key -> `false`.

8) **GIVEN** ein Form-Submit mit komplettem 17-Felder-Input
   **WHEN** WP die Settings-API `update_option`-Pipeline durchlaeuft (mit `sanitize`-Callback)
   **THEN** sind alle 17 Options in `wp_options` mit den sanitized Werten persistiert. `OptionsDefaults`-Defaults (Slice 05) bleiben fuer nicht-uebermittelte Keys erhalten (kein Loeschen).

9) **GIVEN** `Hub\View\Settings::render()`
   **WHEN** sie ohne `manage_woocommerce`-Capability aufgerufen wird
   **THEN** wird via `current_user_can('manage_woocommerce')` abgelehnt — Render-Methode terminiert mit `wp_die()` (oder Aequivalent). Settings-Group-Registration laeuft unabhaengig auf `admin_init` (oder vergleichbar) **mit** Capability-Gate.

10) **GIVEN** alle UI-Strings (Section-Titles, Field-Labels, Hints) in `Hub\View\Settings`
    **WHEN** sie gerendert werden
    **THEN** sind sie konsequent durch `__()` / `esc_html__()` / `_e()` mit Text-Domain `spreadconnect-pod` gewrapped (Slice-06-Anschluss); kein literaler User-facing-String ohne Wrapper. Source-Strings auf Englisch (Discovery-Konvention).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey-Mocks fuer `register_setting`, `add_settings_section`, `add_settings_field`, `get_option`, `update_option`, `current_user_can`, `__`, `esc_html__`, `sanitize_text_field`, `sanitize_email`, `absint`. Keine WP-Test-Suite — reines Unit-Testing der Sanitize-Logik plus Hook-Capture fuer Settings-API-Registration.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-11-settings-form.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class SettingsFormTest extends TestCase
{
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

    // AC-1: Settings::render() registriert Settings-Group + 17 Felder + 4 Sections
    public function test_render_registers_settings_group_with_all_fields_and_sections(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: sanitize_callback fuer jedes Field zeigt auf SettingsValidator::sanitize
    public function test_each_register_setting_call_uses_settings_validator_sanitize(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Auto-Confirm bleibt 'immediate' wenn Default-Shipping-Type gesetzt
    public function test_sanitize_keeps_auto_confirm_when_default_shipping_type_set(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Auto-Confirm-Gating zwingt 'off' bei leerem Default-Shipping-Type
    public function test_sanitize_forces_auto_confirm_off_when_default_shipping_type_empty(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: Gating gilt auch fuer 'after_minutes', nicht nur 'immediate'
    public function test_sanitize_forces_auto_confirm_off_for_after_minutes_when_shipping_empty(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Ungueltiger Enum-Wert faellt auf Default zurueck
    public function test_sanitize_falls_back_to_default_for_invalid_enum_value(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Range-Clamp fuer live_cache_ttl_seconds (60..900)
    public function test_sanitize_clamps_live_cache_ttl_to_60_900_range(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: Range-Clamp fuer retention-Felder (7..365)
    public function test_sanitize_clamps_retention_days_to_7_365_range(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: notify_emails normalisiert + filtert invalid Tokens
    public function test_sanitize_filters_invalid_email_tokens_and_rejoins(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Boolean-Toggles werden zu strikten PHP-bool
    public function test_sanitize_casts_boolean_fields_to_strict_php_bool(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: Missing-Key fuer Boolean-Field -> false (Checkbox-Unchecked-Semantik)
    public function test_sanitize_treats_missing_boolean_key_as_false(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: 17 Felder werden via update_option persistiert (Roundtrip-Test)
    public function test_full_form_submit_persists_all_seventeen_options(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: render() ohne manage_woocommerce -> wp_die
    public function test_render_aborts_without_manage_woocommerce_capability(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Alle UI-Strings sind durch __() / esc_html__() mit Domain 'spreadconnect-pod' gewrapped
    public function test_all_ui_strings_use_spreadconnect_pod_text_domain(): void
    {
        $this->markTestIncomplete('AC-10');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-05-options-defaults` | `SpreadconnectPod\Bootstrap\OptionsDefaults::DEFAULTS` (oder `defaults(): array`) | array constant / static method | Liefert die 17 Defaults als Fallback fuer Enum-Reset (AC-4) und Initial-Render. Slice 11 importiert die Konstante; keine eigene Default-Quelle. |
| `slice-05-options-defaults` | Persistierte `spreadconnect_*` Options | WP Option | Slice 11 liest sie via `get_option($key, OptionsDefaults::DEFAULTS[$key])` fuer das Initial-Form-Rendering. |
| `slice-06-i18n-textdomain` | Geladene Text-Domain `spreadconnect-pod` | WP i18n-Pipeline | `__('...', 'spreadconnect-pod')` muss bei Render auf `plugins_loaded` bereits aktiv sein (i18n laed auf `plugins_loaded` Slice 06 AC-1; Settings-Page rendert auf `admin_init`/`load-...`-Hooks danach). |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Hub\View\Settings::render` | static method | `slice-13-hub-page-skeleton` (Section-Routing) | `public static function render(): void` — gibt HTML aus, gated auf `manage_woocommerce`. |
| `SpreadconnectPod\Hub\View\Settings::registerSettings` | static method | WP `admin_init`-Hook (registriert in `Bootstrap\Plugin` Slice 13 oder direkt) | `public static function registerSettings(): void` — ruft `register_setting`/`add_settings_section`/`add_settings_field` fuer alle 17 Felder + 4 Sections. |
| `SpreadconnectPod\Settings\SettingsValidator::sanitize` | static method | WP Settings API Sanitize-Callback; `slice-18-subscription-manager` (Hook nach Save); `slice-45-export-import-settings` (Re-Sanitize nach Import) | `public static function sanitize(array $input): array` — liefert sanitized 17-Key Map; erzwingt Auto-Confirm-Gating. |
| Settings-Save-Action-Hook (z. B. `update_option_spreadconnect_api_key` oder synthetisches `spreadconnect_settings_saved`) | WP action | `slice-12-test-connection-ajax`, `slice-18-subscription-manager` | Wird durch WP Settings-API automatisch nach `update_option` gefeuert; Slice 11 liefert keinen eigenen Trigger, dokumentiert nur die Verfuegbarkeit. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php` — Klasse `SpreadconnectPod\Hub\View\Settings` mit `static render(): void` (Capability-Gate + Form-Output) und `static registerSettings(): void` (`register_setting`/`add_settings_section`/`add_settings_field` fuer alle 17 Felder aus der Form-Felder-Tabelle). Sections ② Test-Connection, ③ Webhook-Security, ⑨ Footer (Export/Import) sind als Section-Slots/Markup-Stubs vorgesehen aber NICHT mit echten Inhalten gefuellt (Slice 12/14/45 fuellen sie).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Settings/SettingsValidator.php` — Klasse `SpreadconnectPod\Settings\SettingsValidator` mit `public static function sanitize(array $input): array`, die alle 17 Keys validiert/clampt + Auto-Confirm-Gating-Regel anwendet. Single-Source-of-Truth fuer Validation-Rules (Enum-Whitelist als Class-Const).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-11-settings-form.php` wird vom Test-Writer-Agent erstellt, **nicht** im Deliverable. Mount-Point: `Settings::render`/`registerSettings` werden in **`slice-13-hub-page-skeleton`** in `Hub\Controller` (Section-Routing) und in `Bootstrap\Plugin` (`admin_init`-Hook fuer `registerSettings`) verdrahtet. Slice 11 liefert die Klasse; Slice 13 verdrahtet das Routing.

---

## Constraints

**Scope-Grenzen:**
- Keine `spreadconnect_webhook_secret`-Generation, -Anzeige oder -Reveal-Panel — komplette Section ③ (Webhook Security) ist Slice 14.
- Keine Test-Connection-AJAX-Handler oder JS-Wiring — Section ② mit `[Test This Key]` ist Slice 12.
- Keine Export/Import-Buttons (Section ⑨) — Slice 45.
- Keine Dev-Tools-Section (Simulate-Buttons bei `spreadconnect_use_staging=true`) — Slice 44.
- Kein Section-Routing oder `add_submenu_page` — Slice 13. `Settings::render` wird **nur** durch `Hub\Controller`-Dispatch aufgerufen (Slice 13).
- Keine Subscription-Auto-Register nach Save — Slice 18 hookt sich an die durch Slice 11 verfuegbar gewordene WP-Settings-Save-Action.
- Keine Validation der Default-Shipping-Type-Werte gegen die SC-API-Liste — die SC `shippingType.id`-Liste wird in Slice 12/29 aus `GET /shippingTypes` gepullt; Slice 11 akzeptiert jeden non-empty String oder `''` (= None).
- Keine `wp_nonce_field`-Generation im Form — WP Settings API erledigt dies automatisch via `settings_fields('spreadconnect_settings')` — Slice 11 muss `settings_fields` korrekt aufrufen, aber generiert keinen eigenen Nonce-Mechanismus.
- Kein `spreadconnect_pt_index_etag`-UI-Feld — system-managed (Slice 23 schreibt es).
- Keine Live-Validation via JS — Server-Side-Sanitize ist autoritativ; client-seitige UX-Hints (z. B. Auto-Confirm-Disable-Visual) sind kosmetisch und in Slice 13 (UI-Polish) optional.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in beiden neuen Dateien.
- `Hub\View\Settings` als `final class` mit ausschliesslich `static` Methoden (keine Instanziierung — Page-Renderer ist stateless).
- `Settings\SettingsValidator` als `final class` mit ausschliesslich `static` Methoden; Enum-Whitelists und Range-Bounds als `private const` (z. B. `AUTO_CONFIRM_VALUES = ['off', 'immediate', 'after_minutes']`, `STOCK_INTERVAL_VALUES = ['1h','4h','6h','12h','24h']`).
- Auto-Confirm-Gating-Regel ist die **einzige** Cross-Field-Regel — alle anderen Fields sind unabhaengig validierbar.
- Sanitize-Output muss **immer alle 17 Keys** enthalten (auch wenn Input einzelne Keys nicht enthaelt) — fehlende Keys werden auf `OptionsDefaults::DEFAULTS[$key]` zurueckgesetzt. Verhindert dass ein partial-Form-Submit Options loescht.
- WP Settings API Pattern: pro Option **ein** `register_setting`-Call mit dediziertem `sanitize_callback`. Alternative: **eine** `register_setting('spreadconnect_settings', 'spreadconnect_settings_blob', ...)` mit Array-Option und globalem Sanitize. **Empfehlung: pro-Option-Calls** (passt zur 17-Key-Architecture; einfaches Export-Slice 45). Implementer entscheidet, aber muss in beiden Faellen denselben Sanitizer verwenden.
- Sanitize muss **idempotent** sein: `sanitize(sanitize($input)) === sanitize($input)` (Slice 45 Re-Validation nach Import-Roundtrip).
- Kein direkter Zugriff auf `$_POST` oder `$_GET` — WP Settings API liefert `$input` als Argument an den Sanitize-Callback.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/OptionsDefaults.php` (Slice 05) | **Import**, NICHT neu — `OptionsDefaults::DEFAULTS` ist Single Source of Truth fuer Default-Werte (AC-4 Enum-Reset, AC-5 Clamp-Defaults bei missing Key, AC-8 Persistierungs-Roundtrip). |
| `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php` (Slice 02) | Plugin-Hauptdatei — keine Edit in Slice 11; Wiring zu `Hub\Controller` (Slice 13) erfolgt dort. |
| Architecture-Tabelle `architecture.md` -> Section "WP Options" (Z. 317-341) | **Single Source of Truth** fuer Form-Felder, Validation-Rules, Defaults. Bei Aenderung MUSS sowohl `OptionsDefaults::DEFAULTS` (Slice 05) als auch `SettingsValidator` (Slice 11) im selben Schritt nachgezogen werden. |
| Wireframes `wireframes.md` -> Section "Screen 7: Settings (Hub Sub-Page)" (Z. 554-660) | **Layout-Vorlage** fuer Section-Reihenfolge ① ⑥ ⑦ ⑧ und Field-Gruppierung. Section ② ③ ⑨ ⑩ sind Out-of-Scope dieser Slice (siehe Scope-Grenzen). |

**Referenzen:**
- Architecture: `architecture.md` -> Section "WP Options (`wp_options`)" (Z. 317-341); Service Map "`Hub\View\*`" (Z. 393); "Settings API" (Discovery Z. 104).
- Wireframes: `wireframes.md` -> Section "Screen 7: Settings (Hub Sub-Page)" (Z. 554-650); State-Variation `auto_confirm_locked` (Z. 639), `unconfigured` (Z. 636) — UI-State-Hints fuer Renderer.
- Discovery: `discovery.md` -> Slice 7 "Hub-Page Skeleton + Settings" (Z. 245); Auto-Confirm-Gating-Regel (Z. 590); `default_shipping_type_radio` (Z. 456); `auto_confirm_radio` (Z. 457).
- Slim-Slices: `slices/slim-slices.md` -> Slice-11-Eintrag (Done-Signal: alle 19 Felder werden persistiert; Auto-Confirm-Gating server-forced).
- Vorgaenger: `slices/slice-05-options-defaults.md` -> AC-1 (Defaults-Tabelle), AC-5 (Single-Source-of-Truth), Integration Contract `OptionsDefaults::DEFAULTS`.
- Vorgaenger: `slices/slice-06-i18n-textdomain.md` -> AC-2 (Text-Domain `spreadconnect-pod` ist auf `plugins_loaded` aktiv).
