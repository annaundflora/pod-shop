# Slice 02: Plugin-Bootstrap + PSR-4-Autoloader

> **Slice 2 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-02-plugin-bootstrap` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-cleanup-v1"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Bootstrap-Loadability auf Root-Autoloader) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA-Check: Plugin in WP-Liste sichtbar) |
| **Health Endpoint** | `n/a` (Bootstrap-Skeleton, keine Routes) |
| **Mocking Strategy** | `no_mocks` (Filesystem + Class-Reflection-Asserts; keine HTTP/DB-Aufrufe) |

---

## Ziel

Errichtet das v2-Plugin-Skeleton: Hauptdatei `spreadconnect-pod.php` mit WordPress-Plugin-Header (Version `2.0.0`), lokale `composer.json` mit PSR-4-Mapping `SpreadconnectPod\\` -> `includes/` und leeres `Bootstrap\Plugin` mit `init()`-Methode. Damit sind alle Folge-Slices ab Slice 03 in der Lage, Hooks/Services in `Bootstrap\Plugin` zu registrieren und Klassen unter `SpreadconnectPod\` autoladen zu lassen.

---

## Acceptance Criteria

1) **GIVEN** ein leeres Plugin-Verzeichnis nach `slice-01-cleanup-v1`
   **WHEN** Slice 02 abgeschlossen ist
   **THEN** existiert `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php` mit einem WordPress-Plugin-Header, der mindestens die Felder `Plugin Name`, `Version: 2.0.0`, `Requires PHP: 8.2`, `Requires at least: 6.4` und `Text Domain: spreadconnect-pod` enthaelt.

2) **GIVEN** die neue `spreadconnect-pod.php`
   **WHEN** sie ausserhalb von WordPress (z. B. via direktem HTTP-Request) geladen wird
   **THEN** bricht sie mit dem WP-Standard-Guard `if ( ! defined( 'ABSPATH' ) ) { exit; }` ab (kein produktiver Code wird ausgefuehrt) und ruft die `SpreadconnectPod\Bootstrap\Plugin::init( __FILE__ )`-Methode genau einmal auf, sofern der Guard passiert wurde.

3) **GIVEN** `wordpress/plugins/spreadconnect-pod/composer.json` als neue v2-Datei
   **WHEN** `composer dump-autoload` im Plugin-Verzeichnis ausgefuehrt wird
   **THEN** enthaelt die Datei das PSR-4-Mapping `"SpreadconnectPod\\": "includes/"`, einen `name`-Eintrag (z. B. `pod-shop/spreadconnect-pod`), `"type": "wordpress-plugin"` und keine `require-dev`-Eintraege fuer Test-Dependencies (Tests laufen ueber Root-`composer.json`, siehe AC-7).

4) **GIVEN** die Klasse `SpreadconnectPod\Bootstrap\Plugin`
   **WHEN** sie via Root-Autoloader (`vendor/autoload.php` an Repo-Root) geladen wird
   **THEN** ist sie unter `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` aufloesbar, hat eine oeffentliche statische Methode `init( string $plugin_file ): void` und speichert den uebergebenen `$plugin_file`-Pfad als statisches Property fuer spaetere Slices (Slice 03 HPOS-Declare, Slice 06 i18n-Loader).

5) **GIVEN** die `Plugin::init()`-Methode
   **WHEN** sie zweimal mit demselben `$plugin_file` aufgerufen wird
   **THEN** registriert sie ihre internen Hook-Stubs (in dieser Slice noch leer / no-op) **idempotent**: keine `_doing_it_wrong`-Notice, keine doppelte Hook-Registrierung — der zweite Aufruf ist ein No-Op.

6) **GIVEN** `uninstall.php` als Plugin-Stub
   **WHEN** WordPress die Datei beim Plugin-Uninstall laedt
   **THEN** prueft sie `if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }` und enthaelt **keine** DB-Mutationen (Schema-Drop kommt in Slice 04). Die Datei ist ein gueltiger PHP-Stub, der ohne Fatal Error parst.

7) **GIVEN** Root-`composer test` wird nach Slice 02 ausgefuehrt
   **WHEN** der Lauf startet
   **THEN** terminiert er mit Exit-Code `0` (PHPUnit 11 meldet 0 oder mehr Tests, je nachdem ob der Test-Writer-Agent fuer Slice 02 Reflection-Tests erzeugt hat). Es treten **keine** Fatal Errors beim Klassen-Resolve auf — `SpreadconnectPod\Bootstrap\Plugin` ist autoloadbar.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Reflection- und Filesystem-Asserts in einer einzigen PHPUnit-Test-Datei. Keine Brain\Monkey-Hook-Mocks noetig (Slice registriert selbst noch keine WP-Hooks, sondern legt das Skeleton an). Test-Writer implementiert die Assertions selbststaendig und nutzt `__DIR__` fuer Pfad-Resolves auf die Plugin-Files.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-02-plugin-bootstrap.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class PluginBootstrapTest extends TestCase
{
    // AC-1: Plugin-Header mit Pflicht-Feldern (Version 2.0.0, Requires PHP 8.2, Text Domain)
    public function test_main_plugin_file_has_valid_wp_header(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: ABSPATH-Guard schuetzt direkte Datei-Aufrufe
    public function test_main_plugin_file_has_abspath_guard(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Bootstrap-Aufruf Plugin::init( __FILE__ ) im Plugin-File enthalten
    public function test_main_plugin_file_calls_bootstrap_init(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Lokale composer.json hat PSR-4-Mapping + Plugin-type
    public function test_plugin_composer_json_has_psr4_mapping(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: Lokale composer.json hat KEINE test-relevanten require-dev (Tests via Root)
    public function test_plugin_composer_json_has_no_test_dev_deps(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Bootstrap\Plugin::class ist via Root-Autoloader resolvable
    public function test_bootstrap_plugin_class_is_autoloadable(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: Bootstrap\Plugin::init() Signatur mit string $plugin_file -> void
    public function test_bootstrap_plugin_init_method_signature(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Idempotenter init()-Aufruf (zweimal mit gleichem $plugin_file)
    public function test_bootstrap_plugin_init_is_idempotent(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: uninstall.php hat WP_UNINSTALL_PLUGIN-Guard
    public function test_uninstall_php_has_unsinstall_guard(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-6: uninstall.php enthaelt keine DB-Mutationen (Schema kommt in Slice 04)
    public function test_uninstall_php_has_no_db_mutations(): void
    {
        $this->markTestIncomplete('AC-6');
    }
}
```
</test_spec>

> **AC-7:** Wird nicht ueber PHPUnit gemessen, sondern ueber das Done-Signal des Orchestrators (`composer test` Exit-Code im Compliance-Gate). Test-Writer fuegt **keinen** Test fuer AC-7 hinzu.

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-01-cleanup-v1` | Leere Plugin-Wurzel `wordpress/plugins/spreadconnect-pod/` | Filesystem-Vorbedingung | Verzeichnis existiert nicht oder ist leer; keine v1-Reste vorhanden. |
| `slice-01-cleanup-v1` | Root-`composer.json` mit PSR-4-Mapping `SpreadconnectPod\\` -> `wordpress/plugins/spreadconnect-pod/includes/` | Composer-Konfiguration | `composer dump-autoload` (Root) findet Klassen unter `includes/`. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php` | WordPress-Plugin-Hauptdatei | Alle Folge-Slices, die Plugin-Hooks registrieren | Header v2.0.0; ruft `Plugin::init( __FILE__ )` auf. |
| `SpreadconnectPod\Bootstrap\Plugin::init` | static method | `slice-03-hpos-declare`, `slice-04-schema-dbdelta`, `slice-05-options-defaults`, `slice-06-i18n-textdomain` | `public static function init( string $plugin_file ): void` |
| `SpreadconnectPod\Bootstrap\Plugin::pluginFile` | static getter (oder Property) | Slice 03 (`__FILE__` fuer `FeaturesUtil::declare_compatibility`), Slice 06 (`plugin_basename` fuer i18n) | `public static function pluginFile(): string` |
| `wordpress/plugins/spreadconnect-pod/uninstall.php` | WP-Lifecycle-Stub | `slice-04-schema-dbdelta` (extended um `Schema::uninstall()`) | Datei vorhanden mit `WP_UNINSTALL_PLUGIN`-Guard, leerem Body. |
| `wordpress/plugins/spreadconnect-pod/composer.json` | Composer-Manifest | Composer-Tooling, Plugin-Repository-Tools | PSR-4-Mapping; `type: wordpress-plugin`. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/spreadconnect-pod.php` — Plugin-Hauptdatei mit WP-Plugin-Header (v2.0.0), `ABSPATH`-Guard und Aufruf `\SpreadconnectPod\Bootstrap\Plugin::init( __FILE__ )`.
- [ ] `wordpress/plugins/spreadconnect-pod/composer.json` — Lokales Composer-Manifest mit PSR-4-Mapping `SpreadconnectPod\\` -> `includes/`, `type: wordpress-plugin`, keine Test-Dev-Deps.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Klasse `SpreadconnectPod\Bootstrap\Plugin` mit `public static function init( string $plugin_file ): void` (Idempotenz-Guard), `pluginFile()`-Getter, leerem Hook-Registrierungs-Block (wird in Slice 03+ erweitert).
- [ ] `wordpress/plugins/spreadconnect-pod/uninstall.php` — Stub mit `WP_UNINSTALL_PLUGIN`-Guard, leerem Body (Schema-Drop kommt in Slice 04).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-02-plugin-bootstrap.php` basierend auf den Test Skeletons oben.
> **Hinweis 2:** Die slim-slices.md zaehlt nur 3 Deliverables, listet `uninstall.php` aber im Scope-Text auf ("uninstall.php Stub"). Diese Spec macht den Stub explizit als 4. Deliverable, da er ein eigenes File ist und in AC-6 verifiziert wird. Vier Files sind hier vertretbar, da `uninstall.php` ein WP-Lifecycle-Stub ohne Logik ist (Boilerplate-Setup, vergleichbar mit `composer.json`).

---

## Constraints

**Scope-Grenzen:**
- Keine HPOS-Declare in dieser Slice — gehoert zu `slice-03-hpos-declare`.
- Keine `dbDelta`/Schema-Erstellung — `slice-04-schema-dbdelta`.
- Keine Default-Options-Setter — `slice-05-options-defaults`.
- Keine `load_plugin_textdomain`-Registrierung — `slice-06-i18n-textdomain`.
- Keine Service-Klassen ausserhalb `Bootstrap\Plugin` — Folge-Slices fuegen `Api\`, `Order\`, `Catalog\`, `Webhook\`, `Hub\` etc. hinzu.
- Keine Action-Scheduler-Hook-Registrierung — diese werden ab Slice 17 (`spreadconnect/process_webhook_event`) und Slice 23 (`spreadconnect/sync_article`) registriert.
- Keine `register_activation_hook`/`register_deactivation_hook` — kommen in Slice 04/05 (Schema + Options-Defaults).
- Plugin-Header darf **nicht** Felder enthalten, deren Inhalte in Folge-Slices Bestandteil sind (z. B. `Domain Path: /languages` ist Slice 06).

**Technische Constraints:**
- PHP 8.2 strict types in allen produktiven Files: `declare(strict_types=1);` als zweite Zeile nach `<?php`.
- Bootstrap-Klasse als `final class` markieren (nicht erweiterbar; Service-Wiring kommt in Folge-Slices via `Bootstrap\Container` aus Architecture).
- `Plugin::init()` muss idempotent sein (vgl. AC-5): static-Property `$initialized = false`, fruehzeitiger Return bei Re-Entry.
- Plugin-Header-Konventionen folgen WP Plugin API (https://developer.wordpress.org/plugins/plugin-basics/header-requirements/) — Pflicht-Feld `Plugin Name`, alle weiteren Felder gemaess AC-1.
- Lokale `composer.json` darf **kein** `autoload.psr-4`-Mapping als Duplikat zur Root-`composer.json` setzen, das `vendor/`-Erstellung im Plugin-Verzeichnis erzwingen wuerde — Composer auf Root ist alleinige Source of Truth fuer Autoload (siehe Reuse-Tabelle).
- Lokale `composer.json` darf **keine** `require-dev`-Eintraege fuer `phpunit/phpunit` oder `brain/monkey` haben — diese sind in Root-`composer.json` deklariert (REUSE).
- `uninstall.php` ist **subtraktiv** in dieser Slice: nur Guard + Kommentar `// Schema cleanup added in Slice 04 (Bootstrap\Schema::uninstall())`. Keine DB-Calls.
- Namespace-Pfade exakt: `SpreadconnectPod\Bootstrap\` -> `includes/Bootstrap/` (Case-Sensitiv, da PSR-4 strict).

**Reuse:**

Slice 02 nutzt eine bereits in der Repo-Wurzel existierende Komponente:

| Existing File | Usage in this Slice |
|---|---|
| `composer.json` (Root) | Bestehendes PSR-4-Mapping `SpreadconnectPod\\` -> `wordpress/plugins/spreadconnect-pod/includes/` (Zeilen 12-15) wird **unveraendert** weitergenutzt; Slice 02 fuegt **keine** weitere PSR-4-Konfiguration hinzu. Architecture: "Autoload | Composer PSR-4, `SpreadconnectPod\\` -> `includes/` | Codebase-Scan EXTEND #5". |

Hinweis: Die lokale Plugin-`composer.json` deklariert ihr eigenes PSR-4-Mapping nur fuer den Fall, dass das Plugin ausserhalb dieses Repos via Composer installiert wird (z. B. Wordpress.org-Distribution). Im hiesigen Dev-Setup wird ausschliesslich der Root-Autoloader verwendet.

**Referenzen:**
- Architecture: `architecture.md` -> "Service Map" Zeile `Bootstrap\Plugin` (Layer/Responsibility), "Stack & Conventions" -> "Autoload" + "i18n" + "Test framework" (Konventionen), "Greenfield — delete v1 before v2" (Trade-off-Begruendung).
- Discovery: `discovery.md` -> Slice 1 "Plugin Foundation".
- Slim-Slices: `slices/slim-slices.md` -> Slice-02-Eintrag (Done-Signal: `composer dump-autoload` resolved + WP zeigt Plugin in Liste + PHPUnit-Bootstrap laedt ohne Fatal).
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 02 (UI kommt erst ab Slice 11/13).
