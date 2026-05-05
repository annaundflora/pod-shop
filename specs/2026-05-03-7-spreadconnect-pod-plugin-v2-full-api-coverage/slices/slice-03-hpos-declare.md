# Slice 03: HPOS-Declare + WC-Compat-Hook

> **Slice 3 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-03-hpos-declare` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-02-plugin-bootstrap"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA-Check: `WooCommerce → Status → Plugins` zeigt HPOS-Compat = green) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `add_action`; Mock-Klasse `Automattic\WooCommerce\Utilities\FeaturesUtil` als Test-Double mit Spy auf `declare_compatibility`) |

---

## Ziel

Registriert den `before_woocommerce_init`-Hook in `Bootstrap\Plugin::init()`, sodass das Plugin per `FeaturesUtil::declare_compatibility('custom_order_tables', $plugin_file, true)` HPOS-Kompatibilitaet bei WooCommerce anmeldet. Pflicht-Voraussetzung fuer alle Order-Slices (28-33), da HPOS seit WC 8.2 Default ist und ohne Declare ein Admin-Notice "Plugin not compatible" erscheint.

---

## Acceptance Criteria

1) **GIVEN** `Bootstrap\Plugin::init( $plugin_file )` wird mit gueltigem Plugin-Pfad aufgerufen
   **WHEN** die Methode ihre Hook-Registrierung durchlaeuft
   **THEN** ist genau ein Listener fuer den WordPress-Action-Hook `before_woocommerce_init` mit Priority `10` registriert; der Listener ist eine oeffentliche statische Methode der Klasse `SpreadconnectPod\Bootstrap\Plugin` (z. B. `declareHposCompatibility`).

2) **GIVEN** der `before_woocommerce_init`-Hook feuert in einer Umgebung, in der die Klasse `Automattic\WooCommerce\Utilities\FeaturesUtil` existiert
   **WHEN** der registrierte Listener ausgefuehrt wird
   **THEN** ruft er `FeaturesUtil::declare_compatibility()` mit den drei Argumenten `'custom_order_tables'` (string), `$plugin_file` (= dem in `init()` gespeicherten Plugin-Hauptdatei-Pfad aus Slice 02), `true` (bool) auf — exakt einmal pro Hook-Fire.

3) **GIVEN** der `before_woocommerce_init`-Hook feuert in einer Umgebung **ohne** WooCommerce (Klasse `FeaturesUtil` ist nicht geladen)
   **WHEN** der Listener ausgefuehrt wird
   **THEN** wirft er **keinen** `Error`/`Exception` (kein Fatal `Class not found`); er prueft `class_exists( FeaturesUtil::class )` (oder strukturell aequivalent) und kehrt no-op zurueck.

4) **GIVEN** der gespeicherte `$plugin_file` aus `Plugin::init()` (Slice 02 AC-4)
   **WHEN** AC-2 verifiziert wird
   **THEN** ist das zweite Argument an `declare_compatibility` **identisch** mit dem `$plugin_file`-Wert, den der Test ueber `Plugin::pluginFile()`-Getter aus Slice 02 abruft (kein hardcoded Pfad, keine `__FILE__`-Konstante aus der Bootstrap-Klassen-Datei).

5) **GIVEN** `Plugin::init()` wird zweimal mit demselben `$plugin_file` aufgerufen (Idempotenz aus Slice 02 AC-5)
   **WHEN** das Hook-System den `before_woocommerce_init`-Hook feuert
   **THEN** wird der Listener trotzdem genau **einmal** registriert (kein Duplikat), und `FeaturesUtil::declare_compatibility` wird folglich nur einmal pro Hook-Fire aufgerufen.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey + Mockery fuer `add_action`-Spy. Eine Anonymous-/Stub-Klasse mit Namespace `Automattic\WooCommerce\Utilities\FeaturesUtil` und statischer `declare_compatibility`-Spy-Methode (siehe Brain\Monkey Patchwork-Pattern aus Slice 02-Bootstrap). Test-Writer implementiert die Assertions selbststaendig und nutzt `Plugin::pluginFile()` (Slice 02 Provides) zur Verifikation von AC-4.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-01-foundation-hpos.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class HposDeclareTest extends TestCase
{
    // AC-1: Plugin::init() registriert before_woocommerce_init-Hook (Priority 10)
    public function test_init_registers_before_woocommerce_init_hook(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Listener ist public static method auf Bootstrap\Plugin
    public function test_hook_callback_is_public_static_method_on_plugin(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Listener ruft FeaturesUtil::declare_compatibility mit 3 Argumenten
    public function test_listener_calls_features_util_declare_compatibility(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: declare_compatibility wird mit 'custom_order_tables' als ersten Arg aufgerufen
    public function test_first_argument_is_custom_order_tables_feature_id(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: drittes Argument ist true (Compat-Flag)
    public function test_third_argument_is_true(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Ohne FeaturesUtil-Klasse kein Fatal Error (no-op return)
    public function test_listener_is_noop_when_features_util_class_missing(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Zweites Argument ist der via Plugin::init() uebergebene plugin-file Pfad
    public function test_second_argument_is_plugin_file_from_bootstrap(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Doppelter init()-Aufruf registriert Hook nicht doppelt
    public function test_double_init_does_not_duplicate_hook_registration(): void
    {
        $this->markTestIncomplete('AC-5');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-02-plugin-bootstrap` | `SpreadconnectPod\Bootstrap\Plugin::init( string $plugin_file ): void` | static method | Klasse autoladbar; Methode existiert und akzeptiert `$plugin_file`. |
| `slice-02-plugin-bootstrap` | `SpreadconnectPod\Bootstrap\Plugin::pluginFile(): string` | static getter | Liefert den in `init()` gespeicherten Pfad — wird in AC-4 gegen das zweite Argument von `declare_compatibility` verglichen. |
| `slice-02-plugin-bootstrap` | Idempotenz-Guard in `init()` | Implementation-Vertrag | Vorbedingung fuer AC-5 (Hook wird nur einmal registriert). |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `before_woocommerce_init`-Hook-Listener auf `Bootstrap\Plugin` | static method | WC Core (kein Plugin-internal Consumer) | `public static function declareHposCompatibility(): void` (Name finalisierbar; muss mit dem in `init()` registrierten Callback uebereinstimmen). |
| HPOS-Compat-Declare als Vorbedingung | Runtime-Garantie | `slice-28-order-submit-job`, `slice-30-order-webhooks-handler`, `slice-31-wc-cancel-mirror`, `slice-32-order-meta-box`, `slice-33-order-list-columns-bulk` | Ohne diese Slice: WC zeigt "Plugin not compatible with HPOS"-Notice + Order-Meta-Schreibvorgaenge ueber `$order->update_meta_data()` koennen in Mixed-Mode-Setups inkonsistent landen. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — **Edit:** Hook-Registrierung `add_action( 'before_woocommerce_init', [ self::class, 'declareHposCompatibility' ] )` in `init()` einfuegen; neue static method `declareHposCompatibility(): void` mit `class_exists`-Guard und `FeaturesUtil::declare_compatibility( 'custom_order_tables', self::pluginFile(), true )`-Aufruf.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-01-foundation-hpos.php` basierend auf den Test Skeletons oben.
> **Hinweis 2:** Slim-slices.md listet die Test-Datei als Deliverable; gemaess Slice-Writer-Konvention (Test-Writer-Agent uebernimmt Tests) ist sie hier nur in Test Skeletons referenziert.

---

## Constraints

**Scope-Grenzen:**
- Kein Schema/dbDelta — `slice-04-schema-dbdelta`.
- Keine HPOS-konforme Order-Meta-Logik — gehoert zu `slice-27-order-state-machine` und `slice-28-order-submit-job`.
- Keine duale Order-List-Hook-Registrierung (legacy + HPOS) — `slice-33-order-list-columns-bulk`.
- Kein Plugin-Activate-Hook — `slice-04`/`slice-05`.
- Keine Detection von WC-Versionen / Mindest-WC-Version-Check — Plugin-Header `Requires Plugins: woocommerce` (oder Status-Notice in spaeterer Slice) ist nicht Teil dieser Spec.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in der editierten `Plugin.php` muss erhalten bleiben.
- Hook-Registrierung erfolgt aus `Plugin::init()` heraus — **nicht** auf Modul-Top-Level der `Plugin.php`.
- `class_exists`-Check (AC-3) **muss** den vollstaendigen FQCN `Automattic\WooCommerce\Utilities\FeaturesUtil` mit Backslash-Prefix oder `use`-Import nutzen; kein String-Literal-Check via `class_exists( '...' )` ohne Namespace.
- Listener ist **public static** (nicht `private`/`protected`), da WP-Hook-Callable einen oeffentlich aufrufbaren Callable verlangt.
- Hook-Registrierung muss innerhalb des Idempotenz-Guards aus Slice 02 AC-5 ablaufen (vor dem `$initialized = true`-Setzen, oder als Teil des Hook-Registration-Blocks, der nur beim ersten `init()` durchlaufen wird).
- Kein zusaetzlicher `add_action`-Aufruf fuer dieselbe Hook+Methode-Kombination — `add_action` deduppt nicht automatisch bei statischen Methoden auf identischer Klasse.
- Kein Logging in dieser Slice (WC_Logger-Adapter kommt erst in Slice 42).

**Reuse:**

Slice 03 nutzt eine bestehende Datei aus dem Repo, die in Slice 02 angelegt wurde:

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` | **Edit** — Hook-Registrierung in `init()` einfuegen + neue static method `declareHposCompatibility()`. Datei selbst (Klassengeruest, `init()`-Signatur, `pluginFile()`-Getter, `$initialized`-Guard) ist Slice 02 Output und bleibt strukturell unveraendert. |
| `composer.json` (Root, PSR-4-Mapping) | **Unveraendert** — Autoloader laedt die editierte `Bootstrap\Plugin`-Klasse weiterhin via Slice 02 PSR-4-Konfiguration. |
| `wordpress/plugins/spreadconnect-pod/composer.json` (Plugin-lokal) | **Unveraendert** — keine zusaetzliche Dependency auf WooCommerce-Package noetig (`FeaturesUtil`-Aufruf ist runtime-conditional via `class_exists`-Guard). |

**Referenzen:**
- Architecture: `architecture.md` -> "Service Map" Zeile `Bootstrap\Plugin` (Verantwortung: HPOS-Declare); "WP / WooCommerce Core Hooks" Tabelle Zeile `before_woocommerce_init`; "Stack & Conventions" -> "WC HPOS (`Custom Order Tables`)".
- Discovery: `discovery.md` -> Slice 1 "Plugin Foundation" (Done-Signal: "HPOS-Declare via `FeaturesUtil`-Mock"); Section "HPOS-Compliance" (Pflicht-Declare-Begruendung).
- Slim-Slices: `slices/slim-slices.md` -> Slice-03-Eintrag (Scope, Deliverables, Done-Signal); "Flow-Traceability" -> Zeile "HPOS-Declare via FeaturesUtil-Mock".
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 03 (kein UI-Anteil).
