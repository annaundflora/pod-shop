# Slice 45: Export/Import-Settings (JSON)

> **Slice 45 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-45-export-import-settings` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-11-settings-form"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `check_admin_referer`/`current_user_can`/`get_option`/`update_option`/`wp_send_json_*`/`__`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA via Settings-Footer: Export-Button -> Browser-Download `.json`; Import-Button -> File-Picker -> Apply) |
| **Health Endpoint** | `n/a` (AJAX-Handler, keine Routes) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer WP Admin-AJAX + Options API; in-memory Options-Map zur Roundtrip-Verifikation) |

---

## Ziel

Liefert die zwei Admin-AJAX-Handler `spreadconnect_export_settings` (JSON-Download aller `spreadconnect_*`-Options ohne Secrets) und `spreadconnect_import_settings` (JSON-Deserialize + Re-Sanitize via `SettingsValidator::sanitize()`). Erweitert `Hub\View\Settings` um Footer-Buttons "Export Settings JSON" / "Import Settings JSON" inkl. JS-Wiring. Damit ist Slice 10 "Logs + Polish" um eine Backup-/Migrations-Faehigkeit erweitert; Operator kann Konfiguration von Staging zu Prod portieren ohne Secret-Exfiltration.

---

## Acceptance Criteria

1) **GIVEN** ein Admin mit `manage_woocommerce`-Capability triggered AJAX `spreadconnect_export_settings` mit gueltiger Nonce
   **WHEN** `ExportImportSettings::handleExport()` ausgefuehrt wird
   **THEN** liefert der Response-Body ein JSON-Objekt, das **alle** Keys aus `OptionsDefaults::DEFAULTS` (Slice 05) enthaelt **AUSSER** `spreadconnect_api_key` und `spreadconnect_webhook_secret`. Content-Type-Header ist `application/json`, Content-Disposition `attachment; filename="spreadconnect-settings-{YYYYmmdd-HHiiss}.json"`. JSON ist `JSON_PRETTY_PRINT`-formattiert.

2) **GIVEN** Export-Response
   **WHEN** der Body durch `json_decode($body, true)` geparsed wird
   **THEN** ist das Resultat eine assoziative Map mit Top-Level-Keys `version` (`= 1`), `exported_at` (ISO-8601 UTC), `options` (Map). `options` enthaelt die Slice-05-Defaults-Keys minus die zwei Secret-Keys; jedes Value entspricht dem aktuellen `get_option(...)`-Ergebnis. **Niemals** erscheinen `spreadconnect_api_key` oder `spreadconnect_webhook_secret` in `options` — auch nicht mit leerem Wert oder als Key.

3) **GIVEN** AJAX `spreadconnect_export_settings` ohne `manage_woocommerce`-Capability oder mit ungueltiger Nonce
   **WHEN** der Handler aufgerufen wird
   **THEN** terminiert er mit HTTP 403 (`wp_send_json_error` mit Status 403) und ohne JSON-Settings im Body. Capability-Check **vor** Nonce-Check; beide gated.

4) **GIVEN** ein Admin POSTet AJAX `spreadconnect_import_settings` mit gueltiger Nonce + `manage_woocommerce` und gueltigem JSON-Body (von Export-AJAX produziert)
   **WHEN** `ExportImportSettings::handleImport()` ausgefuehrt wird
   **THEN** wird `SettingsValidator::sanitize($importedOptionsArray)` aufgerufen (Reuse aus Slice 11) und der Sanitizer-Output via `update_option($key, $value)` pro Key persistiert. `spreadconnect_api_key` und `spreadconnect_webhook_secret` werden auch dann **nicht** ueberschrieben, wenn sie im Import-Body vorkommen (Defense-in-Depth gegen manipulierte JSON-Files).

5) **GIVEN** Import-JSON enthaelt einen ungueltigen Wert (z. B. `"spreadconnect_stock_sync_interval": "99h"`)
   **WHEN** `handleImport()` den Sanitizer durchlaeuft
   **THEN** wendet `SettingsValidator::sanitize()` die bestehenden Slice-11-Regeln an (Enum-Reset, Range-Clamp, Auto-Confirm-Gating). Ergebnis: `update_option` wird mit `'6h'` (Default) aufgerufen, **nicht** mit `'99h'`. Response: `wp_send_json_success` mit `{imported: int, skipped_secret_keys: ['spreadconnect_api_key', 'spreadconnect_webhook_secret']}` — `imported` zaehlt Keys die `update_option` erreicht haben.

6) **GIVEN** Import-Body ist syntaktisch ungueltiges JSON (z. B. `"{ broken"`)
   **WHEN** `handleImport()` aufgerufen wird
   **THEN** terminiert er mit HTTP 400 via `wp_send_json_error(['message' => 'Invalid JSON', 'code' => 'invalid_json'], 400)`. Keine `update_option`-Calls. Existierende Options bleiben unveraendert.

7) **GIVEN** Import-Body ist valides JSON aber fehlerhaftes Schema (kein `options`-Key, oder `options` ist kein Array, oder `version` fehlt/ist != 1)
   **WHEN** `handleImport()` validiert
   **THEN** terminiert er mit HTTP 400 via `wp_send_json_error(['message' => '...', 'code' => 'schema_mismatch'], 400)`. Keine `update_option`-Calls.

8) **GIVEN** Import-JSON enthaelt einen Key der **nicht** in `OptionsDefaults::DEFAULTS` existiert (z. B. `"spreadconnect_unknown_key": "x"`)
   **WHEN** `handleImport()` filtert
   **THEN** wird der unbekannte Key **vor** dem Sanitize ignoriert (Whitelist-Filter gegen `OptionsDefaults::DEFAULTS`-Keys). Kein `update_option`-Call fuer den unbekannten Key. Response `imported`-Counter zaehlt ihn nicht.

9) **GIVEN** `Hub\View\Settings::render()` rendert die Footer-Section (Section ⑨ in `wireframes.md`)
   **WHEN** der Admin die Settings-Page laedt
   **THEN** sind dort die Buttons `[Export Settings JSON]` und `[Import Settings JSON]` neben `[Save Changes]` sichtbar. Beide Buttons enthalten `data-nonce`-Attribute (separater Nonce per Action via `wp_create_nonce('spreadconnect_export_settings')` bzw. `'spreadconnect_import_settings'`). Import-Button oeffnet einen `<input type="file" accept="application/json">`-Trigger (DOM-Element kann hidden sein, Click delegated). Alle UI-Strings via `__()` mit Domain `spreadconnect-pod`.

10) **GIVEN** Export-Roundtrip-Szenario: Export -> JSON-File -> Import auf Ziel-System
    **WHEN** das exportierte JSON unveraendert importiert wird
    **THEN** sind nach Import auf dem Ziel-System die 17 (= 19 minus 2 Secret-Keys) Options in `wp_options` mit denselben sanitized Werten persistiert wie auf dem Quell-System. `spreadconnect_api_key` und `spreadconnect_webhook_secret` bleiben Ziel-System-spezifisch (z. B. leer/auto-generated) und sind **nicht** durch Import ueberschrieben.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey-Mocks fuer `check_admin_referer`/`current_user_can`/`get_option`/`update_option`/`wp_send_json_success`/`wp_send_json_error`/`wp_create_nonce`/`__`/`esc_attr__`/`current_time`. AJAX-Input via direktes Setzen von `$_POST['payload']` (Import) oder leerem POST (Export). PHPUnit captured `wp_send_json_*`-Aufrufe mit Brain\Monkey-Functions; in-memory Options-Map zur `update_option`-Verifikation.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-45-export-import-settings.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class ExportImportSettingsTest extends TestCase
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

    // AC-1: Export liefert JSON-Download mit Content-Disposition + Pretty-JSON
    public function test_export_returns_json_download_with_attachment_header(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Export-Body enthaelt NIE spreadconnect_api_key oder spreadconnect_webhook_secret
    public function test_export_body_excludes_api_key_and_webhook_secret(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Export-Body enthaelt version=1, exported_at (ISO-8601), options-Map
    public function test_export_body_has_version_exported_at_and_options_envelope(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Export ohne Capability -> 403
    public function test_export_aborts_403_without_manage_woocommerce(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: Export mit ungueltiger Nonce -> 403
    public function test_export_aborts_403_with_invalid_nonce(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Import ruft SettingsValidator::sanitize, persistiert via update_option
    public function test_import_runs_sanitize_and_persists_options(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: Import ueberschreibt NIE spreadconnect_api_key/webhook_secret
    public function test_import_never_overwrites_api_key_or_webhook_secret(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Import mit invalid Enum -> Sanitizer erzwingt Default
    public function test_import_invalid_enum_falls_back_to_default_via_sanitizer(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: Import-Response success-payload enthaelt skipped_secret_keys
    public function test_import_success_response_lists_skipped_secret_keys(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Invalid JSON -> 400 invalid_json, keine update_option-Calls
    public function test_import_invalid_json_returns_400_and_does_not_persist(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: JSON ohne version oder mit version != 1 -> 400 schema_mismatch
    public function test_import_schema_mismatch_returns_400(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Unbekannter Option-Key wird via Whitelist gefiltert (kein update_option)
    public function test_import_filters_unknown_option_keys_via_whitelist(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Settings::render() zeigt Export+Import-Buttons im Footer mit Nonces
    public function test_settings_footer_renders_export_and_import_buttons(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Roundtrip Export -> Import erhaelt alle 17 nicht-Secret-Options
    public function test_export_import_roundtrip_preserves_all_non_secret_options(): void
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
| `slice-11-settings-form` | `SpreadconnectPod\Settings\SettingsValidator::sanitize` | static method | Re-Sanitize aller importierten Werte. Slice 11 garantiert: idempotent (`sanitize(sanitize(x)) === sanitize(x)`), erzwingt Auto-Confirm-Gating, Enum-Reset, Range-Clamp. Slice 45 ruft die Methode unveraendert; keine Modifikation an Slice 11 noetig. |
| `slice-11-settings-form` | `SpreadconnectPod\Hub\View\Settings::render` | static method | Slice 45 EDITIERT die existierende Datei und fuegt im Footer-Markup die zwei Buttons + JS-Wiring hinzu. Section ⑨ ist in Slice 11 als Slot vorgesehen (siehe Slice 11 Constraints "Section ⑨ ... Slice 45"). |
| `slice-05-options-defaults` | `SpreadconnectPod\Bootstrap\OptionsDefaults::DEFAULTS` | array constant | Single Source of Truth fuer Whitelist (AC-8) UND fuer Export-Key-Liste (AC-1). Iterations-Basis: `array_keys(OptionsDefaults::DEFAULTS)` minus `['spreadconnect_api_key', 'spreadconnect_webhook_secret']`. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| AJAX-Action `spreadconnect_export_settings` | WP Admin-AJAX-Hook | Settings-Page Footer-Button | Trigger via `admin-ajax.php?action=spreadconnect_export_settings`; Response: JSON-Download. |
| AJAX-Action `spreadconnect_import_settings` | WP Admin-AJAX-Hook | Settings-Page Footer-Button | Trigger via `admin-ajax.php?action=spreadconnect_import_settings` mit `payload`-POST-Field (JSON-String); Response: `wp_send_json_success`/`wp_send_json_error`. |
| `SpreadconnectPod\Hub\Ajax\ExportImportSettings::SECRET_KEYS` | class const `array<string>` | Slice 46 (Doku) | Liste der zwei vom Export ausgeschlossenen Keys; auch fuer Re-Verification in zukuenftigen Tests dokumentiert. |
| `SpreadconnectPod\Hub\Ajax\ExportImportSettings::EXPORT_VERSION` | class const `int` | Zukuenftige Migration | `= 1`; bei Schema-Aenderung wird hier inkrementiert + in `handleImport()` Migration-Logik hinzugefuegt. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/ExportImportSettings.php` — Klasse `SpreadconnectPod\Hub\Ajax\ExportImportSettings` mit `public static function register(): void` (registriert beide `wp_ajax_*`-Hooks), `public static function handleExport(): void` (Cap+Nonce-Gate -> JSON-Download mit Content-Disposition), `public static function handleImport(): void` (Cap+Nonce-Gate -> JSON-Decode -> Schema-Validate -> Whitelist-Filter -> `SettingsValidator::sanitize` -> `update_option` pro Key). Class-Const `SECRET_KEYS = ['spreadconnect_api_key', 'spreadconnect_webhook_secret']`, `EXPORT_VERSION = 1`.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php` (EDIT) — Footer-Section ⑨ um zwei Buttons erweitern: `[Export Settings JSON]` mit `data-nonce` (Nonce-Action `spreadconnect_export_settings`) und `[Import Settings JSON]` mit hidden `<input type="file">` + `data-nonce` (Action `spreadconnect_import_settings`). Inline-Script wired Click-Handler an `admin-ajax.php`. KEINE Aenderung an `registerSettings`/`sanitize`/Section-Markup.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-45-export-import-settings.php` wird vom Test-Writer-Agent erstellt, **nicht** im Deliverable. Mount-Point: `ExportImportSettings::register()` wird in `Bootstrap\Plugin::init()` (analog zu anderen `Hub\Ajax\*`-Klassen, z. B. Slice 12 `TestConnection`, Slice 19 `RepairSubscriptions`) auf `admin_init` (oder direkt als `add_action('wp_ajax_spreadconnect_export_settings', ...)`) verdrahtet — diese Verdrahtung ist Teil dieses Slices und passiert in `register()` selbst (keine separate Plugin.php-Edit, da `register()` die Hooks direkt registriert; Aufruf erfolgt bereits durch Bootstrap-Mechanismus aus Slice 02).

---

## Constraints

**Scope-Grenzen:**
- Keine Verschluesselung des Export-JSONs — Plain-Text-JSON; Operator ist verantwortlich fuer sichere Uebertragung. Doku-Hinweis im Button-Tooltip ("Contains operational settings; transfer over secure channel").
- Keine Diff-/Preview-UI vor Import (`import_dialog_open` State aus `wireframes.md` Z. 648 ist Out-of-Scope) — Import wendet sofort an. Operator faehrt einen Smoke-Test nach Import.
- Keine Versions-Migration im Importer — Slice 45 supportet exakt `version: 1`. Bei `version: 2` (zukuenftig) wird `code: schema_mismatch` zurueckgegeben; Migration-Logik kommt in spaeterem Slice.
- Kein Multi-File-Import oder ZIP — single `.json`-File pro Import.
- Kein Bulk-Re-Subscribe nach Import — wenn `spreadconnect_default_shipping_type` o. ae. veraendert wurde, ist Operator verantwortlich, manuell die Subscriptions-UI (Slice 19) zu pruefen. Hinweis im Success-Banner.
- Keine Aenderung an `SettingsValidator::sanitize` — Reuse unveraendert. Wenn Sanitizer-Bug festgestellt wird, ist es ein Slice-11-Fix, kein Slice-45-Concern.
- Kein REST-Endpoint — bewusst Admin-AJAX (analog zu allen anderen Hub-Actions in der Architecture-Tabelle Z. 144-157).
- Kein Streaming/Chunked-Download — Settings-JSON ist <10 KB; Single-Response.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in `ExportImportSettings.php`.
- `final class ExportImportSettings` mit ausschliesslich `static` Methoden (stateless AJAX-Handler).
- Capability-Check (`current_user_can('manage_woocommerce')`) **vor** Nonce-Check (`check_admin_referer` mit `false, false` -> `wp_die(403)` bei Mismatch). Beide Gates Pflicht; bei Slice-12-Konsistenz: gleiche Reihenfolge.
- Export-Filename via `current_time('Y-m-d_His')` -> `spreadconnect-settings-{ts}.json`.
- Export-JSON-Encoding: `json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`. Pretty-Print erleichtert Operator-Inspektion vor Import.
- Import-Body via `$_POST['payload']` (raw JSON-String) — nicht via `$_FILES`-Upload, da `<input type="file">` clientseitig per `FileReader` ausgelesen und als POST-Field gesendet wird (vermeidet `upload_max_filesize`-Konflikte fuer kleine JSONs). JS-Wiring ist Teil des Settings.php-Edits.
- Whitelist-Filter VOR `sanitize`-Call: `array_intersect_key($importOptions, $defaults)` — verhindert dass unbekannte Keys den Sanitizer durchlaufen.
- `SECRET_KEYS`-Filter ist DOPPELT: einmal bei Export (Keys werden gar nicht serialisiert) und einmal bei Import (Keys werden vor `update_option`-Loop entfernt, auch wenn jemand manipuliertes JSON einschleust). Defense-in-Depth.
- Response-Schema fuer Import-Success: `{imported: int, skipped_secret_keys: array<string>, skipped_unknown_keys: array<string>}` — `skipped_unknown_keys` zeigt Operator was im JSON ignoriert wurde (Debug-Hilfe).
- Keine `wp_die`-direct-Calls — nur `wp_send_json_error`/`wp_send_json_success` (terminieren WP-AJAX-konform).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Settings/SettingsValidator.php` (Slice 11) | **Import**, NICHT neu — `SettingsValidator::sanitize($importedOptions)` ist die einzige Validations-Quelle beim Import. Garantiert konsistente Regeln zwischen UI-Save und JSON-Import. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/OptionsDefaults.php` (Slice 05) | **Import**, NICHT neu — `OptionsDefaults::DEFAULTS` liefert Whitelist (AC-8) + iterierte Key-Liste fuer Export (AC-1) + Fallback-Defaults bei missing Keys post-Sanitize. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php` (Slice 11) | **EDIT** — Footer-Section ⑨ um Export/Import-Buttons + JS-Wiring erweitern. Slice 11 Constraints sehen diesen Slot ausdruecklich vor (siehe Slice 11 Z. 266 "Keine Export/Import-Buttons (Section ⑨) — Slice 45"). |
| Architecture `architecture.md` Section "Outbound + Webhook + AJAX-Actions" Z. 157 | Single Source of Truth fuer Action-Naming (`spreadconnect_export_settings`/`spreadconnect_import_settings`) und Secret-Exclusion-Regel. |

**Referenzen:**
- Architecture: `architecture.md` -> Z. 157 (AJAX-Action-Tabelle: Export/Import-Definition); Z. 496 (Security-Constraint "Settings-Export JSON excludes `spreadconnect_api_key` and `spreadconnect_webhook_secret` — Prevents secret exfil via export"); Z. 317-341 (WP Options Single Source of Truth).
- Wireframes: `wireframes.md` -> Z. 614 (Footer-Buttons-Layout `[Save Changes] [Export Settings JSON] [Import Settings JSON]`); Z. 630 (Annotation ⑨); Z. 648 (`import_dialog_open`-State — explizit Out-of-Scope, siehe Constraints).
- Discovery: `discovery.md` -> Z. 344 (Footer-Buttons); Z. 931 (Slice 10 Scope inkl. Export/Import-Settings).
- Slim-Slices: `slices/slim-slices.md` -> Slice-45-Eintrag (Done-Signal: Export-Output enthaelt nicht `spreadconnect_api_key`+`spreadconnect_webhook_secret`; Import valid JSON -> Options gesetzt; invalid -> Error).
- Vorgaenger: `slices/slice-11-settings-form.md` -> AC-3/AC-4/AC-5 (Sanitize-Regeln, die hier wiederverwendet werden); Integration Contract `SettingsValidator::sanitize`; Constraints "Sanitize muss idempotent sein" (Z. 282 — Roundtrip-Voraussetzung fuer Slice 45 AC-10).
- Vorgaenger: `slices/slice-05-options-defaults.md` -> Defaults-Map als Whitelist-Quelle.
