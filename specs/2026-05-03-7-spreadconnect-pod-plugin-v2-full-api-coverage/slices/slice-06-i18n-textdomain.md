# Slice 06: i18n-Textdomain-Loader

> **Slice 6 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-06-i18n-textdomain` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-02-plugin-bootstrap"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Hook-Registration via Brain\Monkey-Mock) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA-Check optional) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `add_action`, `load_plugin_textdomain`, `plugin_basename`) |

---

## Ziel

Registriert `load_plugin_textdomain('spreadconnect-pod', false, dirname(plugin_basename($plugin_file)) . '/languages')` auf dem `plugins_loaded`-Hook und legt das `languages/`-Verzeichnis mit leerem `spreadconnect-pod-de_DE.po`-Stub an. Damit ist die i18n-Pipeline funktionsfaehig: alle Folge-Slices koennen `__()`/`_e()`-Strings mit Text-Domain `spreadconnect-pod` verwenden, und Slice 46 fuellt den `.po`-Stub mit Uebersetzungen.

---

## Acceptance Criteria

1) **GIVEN** die `Bootstrap\Plugin::init( __FILE__ )`-Methode (aus Slice 02)
   **WHEN** sie aufgerufen wird
   **THEN** wird genau ein `add_action`-Call mit Hook `plugins_loaded` und einem Callback registriert, der die i18n-Initialisierung vornimmt (Hook-Prioritaet: WP-Default `10`).

2) **GIVEN** der auf `plugins_loaded` registrierte Callback
   **WHEN** WordPress den Hook feuert
   **THEN** ruft der Callback genau einmal `load_plugin_textdomain` mit den Argumenten (`'spreadconnect-pod'`, `false`, `<plugin-dir>/languages`) auf, wobei `<plugin-dir>` aus `dirname( plugin_basename( $plugin_file ) )` abgeleitet wird (Source: `Bootstrap\Plugin::pluginFile()` aus Slice 02).

3) **GIVEN** der `plugins_loaded`-Callback
   **WHEN** er zweimal in derselben Request gefeuert wird (z. B. Test-Re-Entry)
   **THEN** ruft er `load_plugin_textdomain` **maximal einmal** auf — entweder durch eine eigene Idempotenz-Guard ODER durch Verlassen auf die Idempotenz von `Bootstrap\Plugin::init()` (siehe Slice 02 AC-5). Mehrfaches Laden derselben `.mo`-Datei ist nicht zulaessig.

4) **GIVEN** das Verzeichnis `wordpress/plugins/spreadconnect-pod/languages/`
   **WHEN** Slice 06 abgeschlossen ist
   **THEN** existiert die Datei `spreadconnect-pod-de_DE.po` als gueltiger gettext-PO-Stub mit minimalem Header-Block (`Project-Id-Version: spreadconnect-pod 2.0.0`, `Language: de_DE`, `Content-Type: text/plain; charset=UTF-8`, `MIME-Version: 1.0`) und **null** Translation-Eintraegen (Inhalt kommt in Slice 46).

5) **GIVEN** der `.po`-Stub
   **WHEN** ein gettext-Tooling (z. B. `msgfmt --check`) die Datei parst
   **THEN** terminiert das Tool ohne Syntax-Errors. Datei-Encoding ist UTF-8 ohne BOM, Zeilenumbrueche `\n` (LF).

6) **GIVEN** die Plugin-Hauptdatei `spreadconnect-pod.php` (aus Slice 02)
   **WHEN** Slice 06 abgeschlossen ist
   **THEN** enthaelt der Plugin-Header zusaetzlich das Feld `Domain Path: /languages` (per Slice 02 explizit zurueckgehalten, vgl. Slice 02 Constraints "Plugin-Header darf nicht … `Domain Path`"). Die Felder `Text Domain: spreadconnect-pod` (aus Slice 02 AC-1) bleiben unveraendert.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey-Funktion-Mocks (`add_action`, `load_plugin_textdomain`, `plugin_basename`) plus Filesystem-Asserts fuer `.po`-Stub. Test-Writer implementiert Assertions selbststaendig.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-06-i18n-textdomain.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class I18nTextdomainTest extends TestCase
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

    // AC-1: Plugin::init() registriert add_action('plugins_loaded', <callback>)
    public function test_init_registers_plugins_loaded_action(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Callback ruft load_plugin_textdomain mit korrekten 3 Args
    public function test_callback_calls_load_plugin_textdomain_with_expected_args(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Callback berechnet languages-Pfad ueber plugin_basename($plugin_file)
    public function test_callback_resolves_languages_path_via_plugin_basename(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Callback ist idempotent (zweiter Fire ruft load_plugin_textdomain nicht erneut)
    public function test_callback_is_idempotent_on_double_fire(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: languages/spreadconnect-pod-de_DE.po existiert und enthaelt Pflicht-Header
    public function test_de_de_po_stub_file_exists_with_required_headers(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: .po-Stub enthaelt KEINE Translation-Eintraege (msgid/msgstr-Paare ausser Header)
    public function test_de_de_po_stub_has_no_translation_entries(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: .po-Stub ist UTF-8 ohne BOM, LF-Line-Endings
    public function test_de_de_po_stub_encoding_and_line_endings(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Plugin-Header enthaelt 'Domain Path: /languages'
    public function test_main_plugin_file_has_domain_path_header(): void
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
| `slice-02-plugin-bootstrap` | `SpreadconnectPod\Bootstrap\Plugin::init( string $plugin_file ): void` | static method | Edit-Hook in Plugin.php fuegt `plugins_loaded`-Action hinzu. |
| `slice-02-plugin-bootstrap` | `SpreadconnectPod\Bootstrap\Plugin::pluginFile(): string` | static getter | Liefert `$plugin_file`-Pfad fuer `plugin_basename()`-Aufruf. |
| `slice-02-plugin-bootstrap` | `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php` | WP-Plugin-Header | Slice 06 erweitert Header um `Domain Path: /languages`. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| Geladene Text-Domain `spreadconnect-pod` | WP i18n-Pipeline | Alle Slices ab 11 (Settings, Hub, Inline UX, Logs) | `__('Source string', 'spreadconnect-pod')` -> uebersetzte Ausgabe via `load_plugin_textdomain`. |
| `wordpress/plugins/spreadconnect-pod/languages/spreadconnect-pod-de_DE.po` | gettext PO-Datei | `slice-46-i18n-de-po` (fuellt Stub mit Uebersetzungen) | Datei vorhanden mit gueltigem Header, leerem Body. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Edit: registriere `add_action('plugins_loaded', <callback>)` in `Plugin::init()`; Callback ruft `load_plugin_textdomain('spreadconnect-pod', false, dirname( plugin_basename( $plugin_file ) ) . '/languages')` (idempotent).
- [ ] `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php` — Edit: Plugin-Header um `Domain Path: /languages` erweitern (alle anderen Felder unveraendert).
- [ ] `wordpress/plugins/spreadconnect-pod/languages/spreadconnect-pod-de_DE.po` — Neuer leerer PO-Stub mit gueltigem gettext-Header (Project-Id-Version, Language, Content-Type, MIME-Version), null Translation-Eintraegen.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-06-i18n-textdomain.php` wird vom Test-Writer-Agent erstellt, **nicht** im Deliverable.

---

## Constraints

**Scope-Grenzen:**
- Keine `.mo`-Kompilierung — `.mo` wird in Slice 46 generiert oder per CI/Build-Step erzeugt.
- Keine Uebersetzungs-Strings im `.po`-Stub — Strings werden in Slice 46 (`slice-46-i18n-de-po`) hinzugefuegt.
- Keine `__()`-Wrapper fuer existierende UI-Strings — UI-Slices (ab 11) nutzen ab dann konsequent `__()` mit Domain `spreadconnect-pod`.
- Keine zusaetzlichen Locale-Dateien (`en_US.po`, `en_GB.po`) — Source-Strings sind Englisch (Discovery), keine Source-Locale-Datei noetig.
- Keine `register_activation_hook`-Erweiterung — i18n-Loader laeuft auf jedem Request via `plugins_loaded`, nicht nur bei Activate.
- Kein DI-Container-Wiring — `Plugin::init()` registriert die Action direkt; `Bootstrap\Container` (aus Architecture Service Map) wird erst ab Slice 17+ relevant.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in `Plugin.php` (bereits durch Slice 02 vorhanden).
- Hook-Prioritaet `plugins_loaded` = WP-Default `10`. Frueherer Hook (z. B. `init`) ist falsch — `load_plugin_textdomain` MUSS auf `plugins_loaded` (oder spaeter) laufen, weil WP vorher die Locale nicht final aufgeloest hat (siehe WP Plugin API Doc).
- `.po`-Stub-Header MUSS UTF-8 deklarieren: `Content-Type: text/plain; charset=UTF-8`. Encoding-Mismatch wuerde `msgfmt`-Errors verursachen.
- `.po`-Datei-Naming: `<text-domain>-<locale>.po` = `spreadconnect-pod-de_DE.po` (WP-Konvention; abweichendes Naming wird von `load_plugin_textdomain` nicht gefunden).
- Idempotenz-Strategie: bevorzugt durch `Bootstrap\Plugin::init()`-Idempotenz (Slice 02 AC-5), nicht durch separate Static-Property im Callback. Vermeidet doppelten State.
- Kein direkter Filesystem-Read im Test fuer `Plugin.php`-Inhalt — Brain\Monkey-Mock fuer `add_action` reicht aus (Reflection/Mock statt Source-Inspection).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (aus Slice 02) | Edit, NICHT neu erstellen — fuegt `plugins_loaded`-Action-Registration zu `init()` hinzu. |
| `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php` (aus Slice 02) | Edit, NICHT neu erstellen — fuegt `Domain Path: /languages` zum Plugin-Header hinzu. |
| `Bootstrap\Plugin::pluginFile()` (aus Slice 02 Integration Contract) | Liefert `$plugin_file` fuer `plugin_basename()`; KEINE eigene Path-Resolution noetig. |
| Brain\Monkey 2.6 + PHPUnit 11 (Root `composer.json`) | Test-Harness fuer Funktion-Mocks; KEINE neuen Test-Dependencies. |

**Referenzen:**
- Architecture: `architecture.md` -> Service Map "Infrastructure - i18n" (Zeile 540), Stack & Conventions "i18n" (Zeile 762), Quality Attributes "i18n" (Zeile 688), Constraints "i18n" (Zeile 54).
- Discovery: `discovery.md` -> Section "i18n" (Zeile 643-645) — Text-Domain `spreadconnect-pod`, Source Englisch, Translation `de_DE.po`.
- Slim-Slices: `slices/slim-slices.md` -> Slice-06-Eintrag (Done-Signal: `load_plugin_textdomain` mit korrekten Args + `.po`-Datei existiert).
- Vorgaenger-Slice: `slices/slice-02-plugin-bootstrap.md` -> AC-4 (`pluginFile()`-Getter), AC-5 (Idempotenz), Constraints "Plugin-Header darf nicht … `Domain Path`" (Zeile 199).
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 06 (UI-Strings kommen ab Slice 11; Wireframes-Note in Zeile 6 erwaehnt nur Source-English-Konvention).
