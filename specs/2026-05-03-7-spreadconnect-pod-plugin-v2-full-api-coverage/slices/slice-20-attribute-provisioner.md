# Slice 20: Attribute-Provisioner (`pa_groesse`/`pa_farbe`)

> **Slice 20 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-20-attribute-provisioner` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-04-schema-dbdelta"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: Plugin aktivieren -> Admin -> WooCommerce -> Attributes zeigt `pa_groesse` und `pa_farbe`) |
| **Health Endpoint** | `n/a` (Setup-Helper, keine Routes) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `wc_create_attribute`, `wc_get_attribute_taxonomies`, `taxonomy_exists`, `register_activation_hook`; keine echte WC-/DB-I/O im Test) |

---

## Ziel

Stellt sicher, dass die beiden vom `Catalog\ProductMapper` (Slice 22) erwarteten WooCommerce-Attribut-Taxonomies `pa_groesse` und `pa_farbe` existieren. `Catalog\AttributeProvisioner::ensure()` legt fehlende Taxonomies idempotent via `wc_create_attribute()` an, ohne bestehende zu veraendern. Wird beim Plugin-Activate und vor jedem Catalog-/Article-Sync aufgerufen, damit Variations-Upserts in Slice 22+23 niemals an fehlenden Slugs scheitern.

---

## Acceptance Criteria

1) **GIVEN** eine WooCommerce-Installation, in der **weder** `pa_groesse` **noch** `pa_farbe` als Attribut-Taxonomie registriert sind
   **WHEN** `SpreadconnectPod\Catalog\AttributeProvisioner::ensure()` aufgerufen wird
   **THEN** ruft die Methode `wc_create_attribute()` **genau zweimal** auf — einmal pro fehlender Taxonomie — mit den exakten Argumenten:
   - `pa_groesse`: `[ 'name' => 'Groesse', 'slug' => 'groesse', 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ]`
   - `pa_farbe`: `[ 'name' => 'Farbe', 'slug' => 'farbe', 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false ]`
   und gibt das Ergebnis-Array `[ 'created' => ['pa_groesse', 'pa_farbe'], 'skipped' => [] ]` zurueck.

2) **GIVEN** eine WooCommerce-Installation, in der **beide** Taxonomies bereits existieren (z. B. nach erstem Activate)
   **WHEN** `AttributeProvisioner::ensure()` ein zweites Mal aufgerufen wird
   **THEN** wird `wc_create_attribute()` **nicht** aufgerufen (Aufruf-Counter == 0). Die Methode liefert `[ 'created' => [], 'skipped' => ['pa_groesse', 'pa_farbe'] ]`. Bestehende Attribute, Term-Listen und Sortier-Reihenfolgen bleiben unveraendert.

3) **GIVEN** eine Mischsituation: `pa_farbe` existiert, `pa_groesse` fehlt
   **WHEN** `ensure()` aufgerufen wird
   **THEN** wird `wc_create_attribute()` **genau einmal** aufgerufen — fuer `pa_groesse` — und das Ergebnis ist `[ 'created' => ['pa_groesse'], 'skipped' => ['pa_farbe'] ]`.

4) **GIVEN** `wc_create_attribute()` liefert beim Anlegen von `pa_groesse` ein `WP_Error` (z. B. weil der Slug `groesse` reserviert ist)
   **WHEN** `ensure()` aufgerufen wird
   **THEN** wirft die Methode `SpreadconnectPod\Catalog\AttributeProvisionerException` (extends `\RuntimeException`) mit der `WP_Error`-Message als Exception-Message. Die noch nicht versuchte Taxonomie (`pa_farbe`) wird **nicht** mehr angelegt (Fail-Fast — Catalog-Sync MUSS abbrechen, wenn ein Pflicht-Slug fehlt).

5) **GIVEN** die Klasse `SpreadconnectPod\Bootstrap\Plugin::init()` aus Slice 02/04
   **WHEN** Slice 20 abgeschlossen ist
   **THEN** registriert `Plugin::init()` einen zusaetzlichen `register_activation_hook( $plugin_file, [ AttributeProvisioner::class, 'ensure' ] )`-Aufruf — neben dem bestehenden `Schema::install`-Hook aus Slice 04. Die Idempotenz aus Slice 02 AC-5 bleibt unangetastet (Static-Property-Guard verhindert doppelte Registrierung).

6) **GIVEN** `AttributeProvisioner::ensure()`
   **WHEN** sie in einem Kontext aufgerufen wird, in dem `wc_create_attribute()` noch nicht geladen ist (z. B. CLI / sehr fruehe Plugin-Boot-Phase)
   **THEN** prueft die Methode `function_exists( 'wc_create_attribute' )` und wirft bei `false` `AttributeProvisionerException` mit Message `'WooCommerce not loaded'`. Es findet **kein** stillschweigender No-Op statt — der Aufrufer (Activate-Hook oder Sync-Job) muss den Fehler sehen.

7) **GIVEN** das Discovery-Trade-off "fixed slugs `pa_groesse`/`pa_farbe`"
   **WHEN** `ensure()` mit anderen Slug-Argumenten aufgerufen wird (Theorie: erweiternder Aufrufer)
   **THEN** akzeptiert die Methode **keine** Slug-Parameter — Signatur ist parameterlos `public static function ensure(): array`. Die Slug-Liste ist als private Class-Constant `TAXONOMIES` fest verdrahtet (siehe `architecture.md` Section "Decisions" -> "`pa_groesse` / `pa_farbe` fixed").

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey mocked `wc_create_attribute`, `wc_get_attribute_taxonomies` (liefert `array<object{attribute_name:string,...}>` analog WC-Core-Signatur), `taxonomy_exists`, `function_exists`, sowie `register_activation_hook`. Test-Writer prueft `wc_create_attribute`-Aufrufe per `Functions\expect('wc_create_attribute')->once()->with($matcher)`. Fuer AC-2/3 stub'd der `wc_get_attribute_taxonomies`-Mock vorhandene Taxonomies. Patchwork ist nicht noetig (keine internen PHP-Funktionen redefiniert).

### Test-Datei: `tests/slices/pod-shop-mvp/slice-20-attribute-provisioner.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class AttributeProvisionerTest extends TestCase
{
    // AC-1: Beide Taxonomies fehlen -> 2x wc_create_attribute mit korrekten Args
    public function test_ensure_creates_both_taxonomies_when_missing(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: created-Liste enthaelt beide Slugs in Reihenfolge [pa_groesse, pa_farbe]
    public function test_ensure_returns_created_list_in_canonical_order(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Beide bereits vorhanden -> wc_create_attribute wird NICHT aufgerufen
    public function test_ensure_is_noop_when_both_taxonomies_exist(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: skipped-Liste enthaelt beide Slugs, created ist leer
    public function test_ensure_returns_skipped_list_when_all_present(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Mixed state (pa_farbe existiert, pa_groesse fehlt) -> 1x Create fuer pa_groesse
    public function test_ensure_creates_only_missing_taxonomy(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: wc_create_attribute liefert WP_Error -> AttributeProvisionerException
    public function test_ensure_throws_on_wp_error(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: Fail-Fast: nach Error wird keine weitere Taxonomie versucht
    public function test_ensure_aborts_remaining_creates_after_error(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Plugin::init() registriert register_activation_hook fuer AttributeProvisioner::ensure
    public function test_plugin_init_registers_activation_hook_for_attribute_provisioner(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: Idempotenter Plugin::init()-Aufruf -> Hook-Registry nur 1x
    public function test_activation_hook_registered_only_once_per_init(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Ohne wc_create_attribute() geladen -> AttributeProvisionerException
    public function test_ensure_throws_when_woocommerce_not_loaded(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: ensure() ist parameterlos; Slugs sind class-constant fest verdrahtet
    public function test_ensure_signature_has_no_parameters(): void
    {
        $this->markTestIncomplete('AC-7');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-02-plugin-bootstrap` | `SpreadconnectPod\Bootstrap\Plugin::init( string $plugin_file ): void` | static method | Erweiterbar um zusaetzliche Hook-Registrierung; Idempotenz-Guard aus Slice 02 AC-5 bleibt aktiv. |
| `slice-02-plugin-bootstrap` | `Plugin::pluginFile(): string` | static getter | Liefert den Plugin-Datei-Pfad fuer `register_activation_hook`. |
| `slice-04-schema-dbdelta` | `Plugin::init()` Activate-Hook-Registry (mit `Schema::install`) | erweiterte Hook-Liste | Aktivierungs-Phase ist bereits etabliert; AttributeProvisioner haengt sich als zusaetzlicher Callback ein. |
| WooCommerce-Core | `wc_create_attribute()`, `wc_get_attribute_taxonomies()` | WC-API (≥ 3.6) | Standard-WC-Funktionen; im Plugin-Activate-Kontext nach `WooCommerce`-Hook verfuegbar. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Catalog\AttributeProvisioner::ensure` | static method | `slice-22-product-mapper` (vor jedem Variations-Upsert), `slice-23-sync-article-job` (Pre-Sync-Guard), `slice-24-sync-catalog-job` (Pre-Run-Guard), `Plugin::init()` Activate-Hook | `public static function ensure(): array` (return shape: `['created' => string[], 'skipped' => string[]]`) |
| `SpreadconnectPod\Catalog\AttributeProvisionerException` | Exception-Class | `slice-23-sync-article-job` (mapped auf `failed_to_submit` / FailedOps-Row), `slice-24-sync-catalog-job` (Run-Abort) | `class AttributeProvisionerException extends \RuntimeException` |
| Taxonomie `pa_groesse` (WC-Attribute) | DB-Resource | `slice-22-product-mapper` (Variation-Attribute-Slug fuer "Groesse") | WC-Standard-Taxonomy mit Slug `groesse`, Type `select`. |
| Taxonomie `pa_farbe` (WC-Attribute) | DB-Resource | `slice-22-product-mapper` (Variation-Attribute-Slug fuer "Farbe") | WC-Standard-Taxonomy mit Slug `farbe`, Type `select`. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Catalog/AttributeProvisioner.php` — Neue Klasse `SpreadconnectPod\Catalog\AttributeProvisioner` mit `public static function ensure(): array`, private `TAXONOMIES`-Constant (Slug => Args-Map), `function_exists`-Pre-Check und Fail-Fast-Loop. Inklusive `final class AttributeProvisionerException extends \RuntimeException` (in derselben Datei oder als Sibling — Implementer-Entscheidung).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — `Plugin::init()` registriert zusaetzlich `register_activation_hook( $plugin_file, [ AttributeProvisioner::class, 'ensure' ] )` neben dem bestehenden `Schema::install`-Hook aus Slice 04. Static-Property-Idempotenz aus Slice 02 AC-5 bleibt unveraendert.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-20-attribute-provisioner.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- Keine Term-Inserts (z. B. fuer konkrete Groessen `S/M/L` oder Farben `schwarz/weiss`) — Terms werden vom `ProductMapper` (Slice 22) on-demand pro Article-Variant angelegt.
- Keine Konfigurierbarkeit der Slugs — `pa_groesse`/`pa_farbe` sind fest verdrahtet (vgl. `architecture.md` -> "Decisions" und "Out of Scope").
- Keine Aufrufe in Sync-Jobs in dieser Slice — Slice 23/24 haengen `ensure()` selbst als Pre-Run-Guard ein. Hier nur Activate-Hook.
- Keine Aenderung bestehender Taxonomies — wenn eine andere `pa_groesse`-Taxonomy mit anderen Args bereits existiert, bleibt sie unangetastet (Skip-Branch).
- Keine i18n der `name`-Werte (`Groesse`, `Farbe`) in dieser Slice — die Strings werden in Slice 46 ueber `de_DE.po` lokalisiert; in der `TAXONOMIES`-Constant stehen sie als Default-`__()`-Strings mit Textdomain `spreadconnect-pod`.
- Keine Caching-Logik fuer `wc_get_attribute_taxonomies()` — WC cached intern bereits.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile nach `<?php`.
- `AttributeProvisioner` als `final class`; nur statische Methoden (kein State, kein DI).
- `TAXONOMIES`-Constant als `private const TAXONOMIES = [ 'pa_groesse' => [...], 'pa_farbe' => [...] ];` — Reihenfolge der Schluessel ist signifikant fuer AC-1 (Created-Liste folgt Iteration-Order).
- Existenz-Pruefung **muss** ueber `wc_get_attribute_taxonomies()` (oder `taxonomy_exists()` als zusaetzlicher Safety-Check) erfolgen — **nicht** ueber rohe `$wpdb`-Queries auf `wp_woocommerce_attribute_taxonomies`. Die WC-API ist Source of Truth.
- `wc_create_attribute()`-Args folgen exakt der WC-API-Signatur (https://developer.woocommerce.com/docs/woocommerce-class-reference/) — `name`, `slug`, `type`, `order_by`, `has_archives`. Slug ist OHNE `pa_`-Prefix (WC ergaenzt ihn intern).
- `AttributeProvisionerException` MUSS `extends \RuntimeException` sein (kein eigener `\Exception`-Basistyp), damit Action-Scheduler-Retry-Logik in Slice 23/24/37 sie als "permanent failure" einstuft (vgl. `SpreadconnectClientError`-Pattern aus Slice 07).
- Hook-Registrierung in `Plugin::init()` darf die Idempotenz aus Slice 02 AC-5 **nicht** brechen: bei Re-Entry in `init()` wird `register_activation_hook` fuer AttributeProvisioner nicht erneut aufgerufen (Static-Property-Guard).
- Keine `error_log`/`var_dump`-Calls; Logging kommt erst mit Slice 42 (`WcLoggerAdapter`). In dieser Slice ist Provisioning bewusst stumm (Activate-Hook-Kontext, vor Logger-Init).

**Reuse:**

Slice 20 erweitert eine bereits in Slice 02 angelegte und in Slice 04 erweiterte Datei — diese ist Source of Truth und wird **nicht** neu gebaut:

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + Edit Slice 04) | Edit: zusaetzlicher `register_activation_hook(...)`-Aufruf fuer `AttributeProvisioner::ensure`. Idempotenz-Guard aus Slice 02 AC-5 und Schema-Hook aus Slice 04 AC-5 bleiben unveraendert. |
| `frontend/lib/product/variant-utils.ts` | **Nicht** veraendert. Frontend-Slug-Lookup liest weiterhin `pa_groesse`/`pa_farbe` (Codebase-Scan #15 REUSE). Konsistenz-Anker fuer die Backend-Slug-Wahl. |
| `composer.json` (Root) | PSR-4-Mapping `SpreadconnectPod\\` -> `wordpress/plugins/spreadconnect-pod/includes/` aus Slice 02 wird unveraendert weiterverwendet. Keine neue Composer-Konfiguration. |

**Referenzen:**
- Architecture: `architecture.md` -> "Service Map" Zeile `Catalog\AttributeProvisioner` (Layer Infrastructure, Verantwortlichkeit "Idempotent create of `pa_groesse`/`pa_farbe` taxonomies + terms").
- Architecture: `architecture.md` -> "Assumptions & Constraints" -> Zeile "`pa_groesse` / `pa_farbe` fixed" (Plugin auto-creates these taxonomies if missing; never reads other slugs).
- Architecture: `architecture.md` -> "Decisions" -> "`pa_groesse`/`pa_farbe` fixed (not configurable)" (Trade-off: Simpler implementation; matches frontend `variant-utils.ts` REUSE).
- Discovery: `discovery.md` -> Slice 4 "Catalog-Sync" (Attribute-Slugs werden vom Plugin angelegt).
- Slim-Slices: `slices/slim-slices.md` -> Slice-20-Eintrag (Done-Signal: `wc_create_attribute()` wird bei fehlendem Slug aufgerufen, bei vorhandenem nicht).
- Codebase-Scan: `codebase-scan.md` -> Zeile #15 "Bilingual attr slug fallback (`pa_groesse`)" REUSE — Frontend ist bereits auf den deutschen Slug ausgerichtet.
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 20 (Attribut-Provisioning ist UI-fern; verbraucher-UI ist `WooCommerce -> Attributes` Standard-Screen).
