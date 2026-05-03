# Slice 05: Default-Options-Setter

> **Slice 5 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-05-options-defaults` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-02-plugin-bootstrap"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Hook-Capture des Activate-Hooks) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA-Check via WP-Admin: Plugin reaktivieren -> Options im phpMyAdmin sichtbar) |
| **Health Endpoint** | `n/a` (Activate-Hook-Logik, keine Routes) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `add_option`/`get_option`/`register_activation_hook`-Stubs; in-memory Options-Map zur Idempotenz-Verifikation) |

---

## Ziel

Setzt beim Plugin-Activate alle 19 `spreadconnect_*` Options auf die in `architecture.md -> WP Options` definierten Discovery-Defaults. Verwendet `add_option()` (idempotent: schreibt nur, wenn Option noch nicht existiert) — wiederholtes Aktivieren ueberschreibt **keine** vom Admin manuell geaenderten Werte.

---

## Acceptance Criteria

1) **GIVEN** ein leeres `wp_options`-Set ohne `spreadconnect_*`-Eintraege
   **WHEN** der Plugin-Activate-Hook ausgeloest wird (`OptionsDefaults::install()` ueber `register_activation_hook` aus `Bootstrap\Plugin`)
   **THEN** existieren danach **genau die 19** in `architecture.md -> WP Options` (Zeilen 323-341) gelisteten Options mit ihren dort definierten Defaults — Anzahl, Keys und Default-Werte (Type-korrekt: `string`/`bool`/`int`) entsprechen 1:1 der Architecture-Tabelle.

2) **GIVEN** eine bereits gesetzte Option, z. B. `spreadconnect_low_stock_threshold = 25` (Admin hat sie geaendert)
   **WHEN** Plugin-Activate ein zweites Mal feuert (z. B. nach Re-Aktivierung)
   **THEN** bleibt der Wert `25` erhalten — `add_option()` ueberschreibt **nicht**, und es wird **kein** `update_option()`-Call fuer diese Option ausgeloest.

3) **GIVEN** die spezielle Option `spreadconnect_webhook_secret` (Architecture: Default `auto-generated`, base64 von `random_bytes(32)`)
   **WHEN** Activate auf leerem Options-Set feuert
   **THEN** wird `spreadconnect_webhook_secret` **nicht** in dieser Slice gesetzt — Generation gehoert zu `slice-14-webhook-secret-manager`. `OptionsDefaults` schreibt fuer diesen Key **keinen** Default (Architecture-Wert `auto-generated` ist kein literaler Default). Die anderen 18 Options werden gesetzt.

4) **GIVEN** `Bootstrap\Plugin::init( __FILE__ )` aus Slice 02
   **WHEN** Slice 05 erweitert die `init()`-Methode
   **THEN** wird `register_activation_hook( $plugin_file, [ OptionsDefaults::class, 'install' ] )` genau einmal pro `init()`-Aufruf registriert. Die Idempotenz-Garantie aus Slice 02 AC-5 bleibt erhalten (zweiter `init()`-Call registriert den Hook **nicht** doppelt).

5) **GIVEN** `OptionsDefaults::install()`
   **WHEN** sie aufgerufen wird
   **THEN** ruft sie pro Default-Key **genau einen** `add_option( $key, $default )`-Aufruf auf — die Default-Werte stammen aus einer **einzigen** Konstanten-/Methoden-Quelle innerhalb der Klasse (z. B. `OptionsDefaults::DEFAULTS` Array-Const), nicht aus verteilten `add_option`-Statements.

6) **GIVEN** der Activate-Hook und die `Settings\SettingsValidator`-Sanitize-Logik (kommt in Slice 11)
   **WHEN** Slice 05 die Defaults schreibt
   **THEN** ist jeder Default-Wert **selbst** valide gegen die in `architecture.md -> WP Options` Spalte "Validation" beschriebenen Regeln (z. B. `spreadconnect_auto_confirm = 'off'` ist im Enum, `spreadconnect_live_cache_ttl_seconds = 300` liegt im 60..900-Range). AC ist erfuellt durch Inspektion der Defaults-Tabelle, kein API-Call.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey ueberschreibt `add_option`/`get_option`/`register_activation_hook` mit in-memory Doubles. Idempotenz wird ueber zweifache Invocation derselben In-Memory-Map verifiziert. Test-Writer implementiert die Assertions selbststaendig.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-05-options-defaults.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class OptionsDefaultsTest extends TestCase
{
    // AC-1: Erst-Activate setzt alle 18 explicit-default Options (19 - webhook_secret)
    public function test_install_sets_all_discovery_defaults_on_empty_options(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Type-Korrektheit (bool als bool, int als int, string als string)
    public function test_default_values_have_correct_php_types(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Re-Activate ueberschreibt admin-customized Options nicht
    public function test_reactivate_preserves_admin_customized_option_value(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: spreadconnect_webhook_secret wird hier nicht geschrieben
    public function test_install_does_not_set_webhook_secret_default(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: register_activation_hook wird in Plugin::init() einmalig wired
    public function test_plugin_init_registers_activation_hook_for_options_defaults(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: Doppelter Plugin::init() registriert Hook NICHT doppelt (Idempotenz aus Slice 02 AC-5)
    public function test_double_plugin_init_does_not_double_register_hook(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Defaults stammen aus einer zentralen Quelle (keine verstreuten add_option-Calls)
    public function test_defaults_originate_from_single_source(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Jeder Default ist gegen die Architecture-Validation-Regel valide
    public function test_each_default_satisfies_architecture_validation_rule(): void
    {
        $this->markTestIncomplete('AC-6');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-02-plugin-bootstrap` | `SpreadconnectPod\Bootstrap\Plugin::init( string $plugin_file ): void` | static method | `init()` muss um `register_activation_hook( $plugin_file, [ OptionsDefaults::class, 'install' ] )` erweiterbar sein, ohne die Idempotenz-Garantie aus Slice 02 AC-5 zu brechen. |
| `slice-02-plugin-bootstrap` | `Plugin::pluginFile()` getter (oder gespeichertes static Property) | accessor | Liefert den Pfad fuer `register_activation_hook` als 1. Argument. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Bootstrap\OptionsDefaults::install` | static method | WP-Lifecycle (`register_activation_hook`); indirekt `slice-07-http-client-base` (liest `spreadconnect_use_staging`), `slice-11-settings-form` (rendert Initial-Form), `slice-36-stock-cache` (liest `spreadconnect_low_stock_threshold` etc.) | `public static function install(): void` |
| `SpreadconnectPod\Bootstrap\OptionsDefaults::DEFAULTS` (oder `defaults(): array`) | array constant / static method | `slice-11-settings-form` (Form-Defaults wenn Option leer), `slice-45-export-import-settings` (Round-Trip-Vergleich), Test-Asserts | `array<string, string|int|bool>` mit 18 Eintraegen (ohne `spreadconnect_webhook_secret`). |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/OptionsDefaults.php` — Klasse `SpreadconnectPod\Bootstrap\OptionsDefaults` mit zentraler Defaults-Tabelle (Array-Konstante oder `defaults(): array`-Methode) und `public static function install(): void`, die ueber alle Default-Keys iteriert und je einen `add_option()`-Aufruf ausloest.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — **Edit:** `init()` um `register_activation_hook( $plugin_file, [ OptionsDefaults::class, 'install' ] )` erweitern; Idempotenz-Guard aus Slice 02 (`$initialized`) muss diesen Hook umfassen.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-05-options-defaults.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- Keine Generation von `spreadconnect_webhook_secret` — gehoert zu `slice-14-webhook-secret-manager`. Diese Slice schreibt **keinen** Default fuer diesen Key.
- Keine `register_uninstall_hook`-Behandlung der Options — Cleanup ist Aufgabe von `slice-04-schema-dbdelta` bzw. einer dedizierten Uninstall-Erweiterung. `OptionsDefaults` setzt **keine** `delete_option`-Logik.
- Keine `update_option`-Aufrufe — Re-Activate darf nichts ueberschreiben (vgl. AC-2).
- Keine Settings-Validation — `Settings\SettingsValidator` kommt in `slice-11-settings-form`. Slice 05 prueft Defaults nicht zur Laufzeit.
- Keine UI/Form/AJAX — `Hub\View\Settings` ist Slice 11.
- Keine Schema-/dbDelta-Calls — `Bootstrap\Schema` ist Slice 04 (parallel, nicht in dieser Slice).
- Keine HPOS-Declare — Slice 03.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile.
- `OptionsDefaults` als `final class` (nicht erweiterbar).
- Defaults als **eine** zentrale Quelle (PHP-Const-Array oder private static Methode) — vgl. AC-5; vermeidet Duplikat-Pflege beim Tabellen-Update.
- `add_option( $key, $default )` mit zwei Argumenten verwenden (autoload-Default = `'yes'`); explizites `autoload`-Toggle nicht noetig (Defaults sind klein, < 1 KB total).
- Activation-Hook auf `Bootstrap\Plugin::pluginFile()` registrieren — **nicht** auf `__FILE__` von `OptionsDefaults.php` (sonst feuert WP nicht; `register_activation_hook` braucht den Pfad der Plugin-Hauptdatei aus Slice 02).
- Type-Treue: bool-Defaults (`spreadconnect_use_staging` etc.) als PHP-`bool` an `add_option` uebergeben — **nicht** als String `"false"` (WP serialisiert bool korrekt).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (aus Slice 02) | **Edit, nicht neu**: nur `init()`-Body um `register_activation_hook(...)`-Zeile erweitern. Idempotenz-Guard (`$initialized`-Property aus Slice 02 AC-5) bleibt unveraendert; Hook-Registrierung haengt am gleichen Guard. |
| Architecture-Tabelle `architecture.md -> WP Options` (Z. 323-341) | **Single Source of Truth** fuer alle 19 Default-Werte. `OptionsDefaults::DEFAULTS` ist die programmatische Spiegelung dieser Tabelle. Bei Aenderung der Tabelle muss die Konstante in **einem** Schritt nachgezogen werden (Slice 11 wird das beim Settings-Render zusaetzlich respektieren). |

**Referenzen:**
- Architecture: `architecture.md` -> Section "WP Options (`wp_options`)" (Z. 317-341) — verbindliche Liste aller 19 Keys + Defaults + Validation; "Service Map" -> `Bootstrap\Plugin` (Aktivierung/Deaktivierung).
- Discovery: `discovery.md` -> Slice 1 "Plugin Foundation" (Default-Options setzen).
- Slim-Slices: `slices/slim-slices.md` -> Slice-05-Eintrag (Done-Signal: alle 19 Options vorhanden, idempotent).
- Slice 02: `slices/slice-02-plugin-bootstrap.md` -> AC-4/AC-5 (Plugin::init Signatur + Idempotenz-Garantie), AC-7 (Bootstrap-Loadability).
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 05 (Settings-UI ist Slice 11).
