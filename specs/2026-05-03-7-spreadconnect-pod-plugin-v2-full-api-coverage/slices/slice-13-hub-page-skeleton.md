# Slice 13: Hub-Page-Skeleton + Section-Routing

> **Slice 13 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-13-hub-page-skeleton` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-11-settings-form"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `add_submenu_page`, `current_user_can`, `wp_die`, `__()`, `esc_html__()`, `admin_url`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: `/wp-admin/admin.php?page=spreadconnect&section=...` fuer alle 8 Section-Slugs aufrufen) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer WP Admin-Menu-API + Capability-API; Patchwork fuer statische `Hub\View\*::render`-Calls) |

---

## Ziel

Liefert das Admin-Menu-Skeleton: `Hub\Controller` registriert das WC-Submenu `WooCommerce -> Spreadconnect` und routet `?section=...` zu einer der acht View-Klassen. Capability-Gate `manage_woocommerce`, gemeinsamer Sidebar-Nav-Helper, Dashboard-Stub mit 5 Card-Slots (Daten kommen in Folge-Slices). Damit ist Discovery-Slice 7 "Hub-Page Skeleton + Settings" funktional komplett: Slice 11 lieferte das Settings-Form, Slice 13 verdrahtet es ueber das Routing erreichbar.

---

## Section-Slugs (Single Source of Truth)

> Diese Tabelle ist die **autoritative Routing-Map** fuer den `?section=`-Parameter. Wireframes `Screen 1..Screen 8` referenzieren dieselben Slugs.

| # | `?section=` Slug | View-Klasse | Sidebar-Label | Status in dieser Slice |
|---|---|---|---|---|
| 1 | `dashboard` (default) | `Hub\View\Dashboard` | "Dashboard" | NEU — Stub mit 5 Card-Slots |
| 2 | `catalog` | `Hub\View\Catalog` | "Catalog" | Routing-Stub (Klasse stub-erzeugt; Inhalt in Slice 26) |
| 3 | `orders` | `Hub\View\Orders` | "Orders" | Routing-Stub (Inhalt in spaeterer Slice — Discovery Slice 8) |
| 4 | `webhooks` | `Hub\View\Webhooks` | "Webhooks" | Routing-Stub (Inhalt in Slice 41) |
| 5 | `failed` | `Hub\View\FailedOps` | "Failed Operations" | Routing-Stub (Inhalt in Slice 38) |
| 6 | `logs` | `Hub\View\Logs` | "Logs" | Routing-Stub (Inhalt in Slice 42) |
| 7 | `settings` | `Hub\View\Settings` | "Settings" | **Reuse** aus Slice 11 (keine Modifikation) |
| 8 | `subscriptions` | `Hub\View\Subscriptions` | "Subscriptions" | Routing-Stub (Inhalt in Slice 19) |

> "Routing-Stub" = leere `final class Hub\View\{Name}` mit `public static function render(): void` als Platzhalter — **NICHT** in Deliverables dieser Slice (siehe Constraints "Stub-Klassen-Strategie").

---

## Acceptance Criteria

1) **GIVEN** WP-Admin-Menu wird aufgebaut (Hook `admin_menu`)
   **WHEN** `Hub\Controller::registerMenu()` ausgefuehrt wird
   **THEN** wird `add_submenu_page('woocommerce', $page_title, $menu_title, 'manage_woocommerce', 'spreadconnect', [Hub\Controller::class, 'dispatch'])` mit Menu-Slug `'spreadconnect'`, Capability `'manage_woocommerce'` und Callback `Hub\Controller::dispatch` aufgerufen. `$page_title` und `$menu_title` werden via `__()` mit Domain `'spreadconnect-pod'` lokalisiert.

2) **GIVEN** ein Admin-User mit `manage_woocommerce` ruft `?page=spreadconnect` ohne `section`-Parameter auf
   **WHEN** `Hub\Controller::dispatch()` laeuft
   **THEN** wird `Hub\View\Dashboard::render()` aufgerufen (Default = `dashboard` per Section-Slug-Tabelle).

3) **GIVEN** ein Admin-User mit `manage_woocommerce` ruft `?page=spreadconnect&section={slug}` auf, wobei `{slug}` einer der 8 gueltigen Section-Slugs ist (siehe Tabelle)
   **WHEN** `Hub\Controller::dispatch()` laeuft
   **THEN** wird genau die zugehoerige `Hub\View\{Klasse}::render()`-Methode genau einmal aufgerufen; keine andere View-Klasse wird beruehrt.

4) **GIVEN** ein Aufruf mit unbekanntem Section-Slug (z. B. `?section=foo`)
   **WHEN** `Hub\Controller::dispatch()` laeuft
   **THEN** faellt das Routing auf `dashboard` zurueck und ruft `Hub\View\Dashboard::render()` auf (kein 404, keine Exception).

5) **GIVEN** ein Benutzer ohne `manage_woocommerce`-Capability ruft die Hub-Page auf (egal mit welchem Section-Slug)
   **WHEN** `Hub\Controller::dispatch()` laeuft
   **THEN** wird `current_user_can('manage_woocommerce')` evaluiert, und bei `false` terminiert der Dispatcher via `wp_die()` (oder Aequivalent) mit lokalisierter Permission-Denied-Message; **keine** `Hub\View\*::render()` wird ausgefuehrt.

6) **GIVEN** der `?section=`-Parameter aus `$_GET` enthaelt unsanitized Input (z. B. `section=<script>` oder `section=settings'OR'1'='1`)
   **WHEN** `Hub\Controller::dispatch()` ihn liest
   **THEN** wird der Wert via `sanitize_key()` (oder Whitelist-Compare) gegen die 8-Slug-Liste validiert; kein literaler `$_GET`-Wert erreicht eine `Hub\View\*::render()`. Falls der sanitized Wert nicht in der Whitelist ist, greift AC-4-Fallback.

7) **GIVEN** `Hub\View\Sidebar::render(string $active_slug): void` wird aufgerufen
   **WHEN** der `$active_slug` einem der 8 gueltigen Slugs entspricht
   **THEN** generiert der Helper Markup mit genau 8 Nav-Eintraegen (Reihenfolge laut Section-Slug-Tabelle), jeder mit `href = admin_url('admin.php?page=spreadconnect&section={slug}')`, korrekter `__()`-lokalisierter Label und einem `is-active`-CSS-Hook auf dem Item, dessen Slug `=== $active_slug`. Genau **ein** Eintrag ist `is-active`.

8) **GIVEN** `Hub\View\Dashboard::render()` wird ausgefuehrt
   **WHEN** Markup erzeugt wird
   **THEN** sind genau **5 Card-Slots** als Container-Elemente ausgegeben (Reihenfolge + Identifier laut Wireframe `Screen 1` Cards Nr. 5-9: Connection, Catalog, Orders, Webhooks, Failed Operations — siehe wireframes.md `Screen 1`). Jeder Slot enthaelt einen Platzhalter-String "Wird in Slice {N} befuellt" (Hardcode-Mapping zu den Folge-Slices); **keine** Daten-Queries werden ausgefuehrt (Aggregat-Queries sind Slice 46).

9) **GIVEN** `Hub\View\Dashboard::render()` und `Hub\View\Sidebar::render()`
   **WHEN** sie Markup erzeugen
   **THEN** sind alle User-facing Strings durch `__()` / `esc_html__()` / `_e()` mit Text-Domain `'spreadconnect-pod'` gewrapped (Slice-06-Anschluss); alle URL-Outputs durch `esc_url()`, alle Attribute durch `esc_attr()`, alle Inhalte durch `esc_html()` escaped.

10) **GIVEN** Slice 11 `Hub\View\Settings::render()` ist verfuegbar
    **WHEN** `?section=settings` geroutet wird
    **THEN** wird **genau diese** Methode aufgerufen — keine Duplizierung, kein Wrapper, kein Re-Render der Sidebar innerhalb der Settings-Page (Sidebar wird durch den Dispatcher **vor** dem `View::render()`-Call ausgegeben). Slice 11-Klasse bleibt unveraendert.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey-Mocks fuer `add_submenu_page`, `current_user_can`, `wp_die`, `sanitize_key`, `admin_url`, `__`, `esc_html__`, `esc_html`, `esc_attr`, `esc_url`. Patchwork-Replace fuer `Hub\View\*::render()` (statische Methoden), um Aufruf-Zaehler zu erfassen ohne den Inhalt auszufuehren. Fuer Stub-View-Klassen (siehe Constraints) liefert das Test-Bootstrap leere `final class`-Definitionen.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-13-hub-page-skeleton.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class HubPageSkeletonTest extends TestCase
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

    // AC-1: registerMenu ruft add_submenu_page mit korrekten Args
    public function test_register_menu_calls_add_submenu_page_under_woocommerce(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Capability fuer Submenu-Registration ist 'manage_woocommerce'
    public function test_register_menu_uses_manage_woocommerce_capability(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Default-Section ohne ?section ist Dashboard
    public function test_dispatch_without_section_param_renders_dashboard(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Dispatch zu jeder der 8 View-Klassen (data-driven)
    public function test_dispatch_routes_each_known_section_to_correct_view_class(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Unbekannter Section-Slug faellt auf Dashboard zurueck
    public function test_dispatch_falls_back_to_dashboard_for_unknown_section(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Ohne manage_woocommerce -> wp_die, keine View::render()
    public function test_dispatch_aborts_via_wp_die_without_manage_woocommerce(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Section-Param wird sanitisiert + gegen Whitelist gematcht
    public function test_dispatch_sanitizes_and_whitelist_matches_section_param(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Sidebar rendert 8 Nav-Items in korrekter Reihenfolge mit href
    public function test_sidebar_render_emits_eight_nav_items_with_admin_urls(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: Genau ein Sidebar-Item traegt 'is-active' fuer den uebergebenen Slug
    public function test_sidebar_render_marks_exactly_one_item_active(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Dashboard rendert 5 Card-Slots in Wireframe-Reihenfolge
    public function test_dashboard_render_emits_five_card_slots_in_wireframe_order(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: Dashboard fuehrt keine Daten-Queries aus
    public function test_dashboard_render_does_not_perform_data_queries(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Alle User-Strings sind via __() mit Domain 'spreadconnect-pod' gewrapped
    public function test_all_ui_strings_use_spreadconnect_pod_text_domain(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-9: URLs/Attributes/Content sind durch esc_url/esc_attr/esc_html escaped
    public function test_outputs_are_properly_escaped(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: ?section=settings dispatched zu Slice-11 Settings::render
    public function test_dispatch_settings_section_calls_slice_11_settings_render(): void
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
| `slice-02-plugin-bootstrap` | `SpreadconnectPod\Bootstrap\Plugin` mit `init()`/Hook-Pipeline | class | Hub\Controller::registerMenu wird auf `admin_menu`-Hook in `Bootstrap\Plugin` registriert (Edit aussen — siehe Deliverables-Hinweis). |
| `slice-06-i18n-textdomain` | Geladene Text-Domain `spreadconnect-pod` auf `plugins_loaded` | WP i18n | `__()`/`esc_html__()` in Sidebar/Dashboard/Controller koennen die Domain aufloesen, da `admin_menu` nach `plugins_loaded` feuert. |
| `slice-11-settings-form` | `SpreadconnectPod\Hub\View\Settings::render(): void` | static method | Section-Routing `?section=settings` ruft genau diese Methode (AC-10). Slice 11-Klasse wird **nicht** modifiziert. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Hub\Controller::registerMenu` | static method | `Bootstrap\Plugin` (Hook-Wiring auf `admin_menu`) | `public static function registerMenu(): void` |
| `SpreadconnectPod\Hub\Controller::dispatch` | static method | WP-Admin-Menu-Callback | `public static function dispatch(): void` (liest `$_GET['section']`, gated auf Capability, ruft View::render) |
| `SpreadconnectPod\Hub\Controller::ensureCapability` | static method | per-AJAX-Slices (z. B. Slice 12 `TestConnection`, Slice 14 `RegenerateSecret`, Slice 19 `RepairSubscriptions`) | `public static function ensureCapability(): void` — gemeinsame Capability-Gate-Helper, wirft `wp_die` bei Fehlen. |
| `SpreadconnectPod\Hub\View\Sidebar::render` | static method | Alle `Hub\View\*::render`-Implementierungen (Slice 11/19/26/38/41/42 — kuenftig) | `public static function render(string $active_slug): void` — gibt `<nav>`-Markup aus. Konsumenten rufen den Helper am Anfang ihrer eigenen `render()` auf. |
| `SpreadconnectPod\Hub\View\Dashboard::render` | static method | Section-Routing-Default; spaeter Slice 46 (echte Daten in Cards) | `public static function render(): void` — gibt 5-Card-Stub-Markup aus. |
| Section-Slug-Whitelist (8 Slugs als `private const` in `Hub\Controller`) | const array | Alle kuenftigen `Hub\View\*`-Klassen, die ihre eigene URL bauen (z. B. fuer Cross-Links Dashboard -> Catalog) | dokumentiert ueber `Sidebar::render` als sichtbares Zugriffsmuster — kein direkter externer Konsum noetig. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/Controller.php` — Klasse `SpreadconnectPod\Hub\Controller` mit `registerMenu()` (`add_submenu_page` unter `woocommerce`), `dispatch()` (Section-Routing mit Capability-Gate + Whitelist-Sanitize + Sidebar-Render-Wrapper), `ensureCapability()` (geteilter Helper). Section-Slug-Whitelist als `private const SECTIONS = [...]` (Map slug -> View-FQCN).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Sidebar.php` — Klasse `SpreadconnectPod\Hub\View\Sidebar` mit `render(string $active_slug): void`. Generiert `<nav>`-Markup mit 8 Nav-Items in Slug-Tabelle-Reihenfolge, `is-active`-Marker, lokalisierten Labels, escaped URLs.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Dashboard.php` — Klasse `SpreadconnectPod\Hub\View\Dashboard` mit `render(): void`. 5 Card-Slot-Container (Connection/Catalog/Orders/Webhooks/FailedOps in Wireframe `Screen 1`-Reihenfolge); jeder Slot enthaelt Platzhalter-Markup mit "Wird in Slice {N} befuellt"-Hint. Keine Daten-Queries.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Mount-Point: `add_action('admin_menu', [Hub\Controller::class, 'registerMenu'])` registrieren. Ohne diesen Edit ist die gesamte Hub-Page nicht erreichbar.
<!-- DELIVERABLES_END -->

> **Hinweis:** Die 7 Routing-Stub-View-Klassen (`Catalog`, `Orders`, `Webhooks`, `FailedOps`, `Logs`, `Subscriptions`) sind **NICHT** Deliverables dieser Slice — sie werden vom jeweiligen Folge-Slice (26/—/41/38/42/19) angelegt. Damit der Dispatcher in dieser Slice nicht crasht, siehe Constraints "Stub-Klassen-Strategie". Test-Datei `tests/slices/pod-shop-mvp/slice-13-hub-page-skeleton.php` wird vom Test-Writer-Agent erstellt.

---

## Constraints

**Scope-Grenzen:**
- Keine echten Daten-Queries in Dashboard-Cards — Aggregat-Counts kommen in Slice 46. Dashboard rendert statische Platzhalter.
- Kein `connection_status_badge` oder `subscription_status_badge` im Hub-Header — beide kommen im jeweiligen Sub-Slice (Slice 12 fuer Connection-Cache via `sc_health`-Transient; Slice 19 fuer Subscriptions-Aggregate). Diese Slice liefert nur das Markup-Geruest.
- Keine AJAX-Handler in dieser Slice — Slice 12/14/19/26/38 liefern jeweils ihre Action-Endpunkte; sie konsumieren `Hub\Controller::ensureCapability` als geteilten Capability-Helper.
- Keine Section-spezifischen URL-Parameter (`?run_id=...`, `?event_id=...` etc.) — pro-Section ist Sache der jeweiligen View-Klasse.
- Kein Content fuer Settings/Catalog/Orders/Webhooks/FailedOps/Logs/Subscriptions — Routing ist die einzige Interaktion mit diesen Sections.
- Kein REST-Route-Registrieren — Slice 26 (`/sync-progress`) und Slice 15 (`/webhook`) haben eigene REST-Controller.
- Keine CSS- oder JS-Asset-Enqueues — pro-Section-Assets sind Sache der jeweiligen View-Slice (z. B. Slice 41 `webhook-log-expand.js`). Sidebar-CSS-Hooks (`is-active`) bleiben markup-only; Styles existieren erst, wenn Folge-Slices sie liefern.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in allen 3 neuen Dateien.
- `Hub\Controller`, `Hub\View\Sidebar`, `Hub\View\Dashboard` als `final class` mit ausschliesslich `static` Methoden (keine Instanziierung — Adapter sind stateless laut architecture.md Section "Adapter — Admin Page").
- Section-Slug-Whitelist als `private const SECTIONS` in `Hub\Controller` (Map `slug => View-FQCN`). Die Tabelle in dieser Spec ist die Single Source of Truth — JEDE neue Section MUSS hier eingetragen werden.
- Default-Section-Konstante separat: `private const DEFAULT_SECTION = 'dashboard'` (kein Magic-String).
- Capability-String als Konstante: `private const REQUIRED_CAP = 'manage_woocommerce'`.
- Section-Resolution-Reihenfolge: (1) `$_GET['section']` lesen -> `sanitize_key()`; (2) gegen `SECTIONS`-Keys whitelist-matchen; (3) fallback auf `DEFAULT_SECTION`. Niemals direkter String-Concat in einen Klassenname (no class-name-injection).
- View-Dispatch via `call_user_func([$fqcn, 'render'])` ODER `($fqcn)::render()` — der Dispatcher darf KEINE Reflection oder dynamic-method-Magic verwenden, der Set ist statisch und endlich.
- Sidebar-Markup: `<nav class="spreadconnect-hub-nav">` Wrapper + `<ul>` Liste + `<li>` Items mit `<a href>`. CSS-Class `is-active` auf dem aktiven `<li>`. Keine inline-Styles, keine JS-Event-Handler.
- Dashboard-Card-Slot: jeweils `<div class="spreadconnect-card spreadconnect-card--{slug}">` mit `<h2>` Titel + Platzhalter-`<p>`. Slug-Naming: `connection`, `catalog`, `orders`, `webhooks`, `failed-ops`.
- `wp_die`-Message-String muss durch `__()` mit Domain `'spreadconnect-pod'` lokalisiert sein.

**Stub-Klassen-Strategie (kritisch fuer Test-Run):**
- Da 7 View-Klassen (alle ausser `Settings` aus Slice 11 und `Dashboard` aus dieser Slice) erst in Folge-Slices erstellt werden, MUSS der Implementer im **Test-Bootstrap** (`tests/bootstrap.php` oder Slice-Test-File-Setup) Stub-Definitionen anlegen: leere `final class Hub\View\Catalog { public static function render(): void {} }` etc. — dies ist KEIN Plugin-Code-Deliverable, sondern Test-Infrastruktur. In Production ist die Hub-Page erst voll funktional, sobald die Folge-Slices die echten Klassen liefern; bis dahin wuerde ein Aufruf von `?section=catalog` einen `Class not found` Fatal werfen — akzeptiert, da Slice-13-Done-Signal nur die Section `dashboard` und (via Slice 11) `settings` als manuell QA-baren Pfad nennt.
- Alternative (**bevorzugt**): Implementer legt die 6 Stub-View-Klassen (Catalog, Orders, Webhooks, FailedOps, Logs, Subscriptions) als minimale `final class` mit leerem `render()` an — aber NICHT in dieser Slice's Deliverables, sondern bewusst aufgeschoben auf den jeweiligen Folge-Slice. Der Dispatcher muss daher bei "Klasse existiert nicht" einen sauberen Fallback ausfuehren: `class_exists($fqcn)`-Check VOR dem Dispatch; bei Miss -> `Dashboard::render()` (analog zu AC-4 unbekannter Slug). Dieses Verhalten ist in **AC-4 implizit gedeckt** (unbekannter Slug = unbekannte Klasse) und sollte in den Test-Cases zu AC-4 explizit getestet werden.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php` (Slice 11) | **Import**, NICHT modifizieren — `Hub\View\Settings::render` wird durch das neue Routing als `?section=settings`-Target aufgerufen (AC-10). Slice 11 hat dies bereits als Provides-To `slice-13-hub-page-skeleton` annotiert. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02) | **Edit** — Mount-Point fuer `admin_menu`-Hook. Patcht **nur** den Hook-Eintrag; bestehende Hook-Registrierungen aus Slice 02-06 bleiben unveraendert. |
| Architecture-Tabelle `architecture.md` -> Service Map "`Hub\Controller` / `Hub\View\*`" (Z. 392-393) + "Admin UI" Tech-Stack (Z. 761) + Capability-Standard (Z. 646 `Hub\Controller::ensureCapability`) | **Single Source of Truth** fuer Klassen-FQCN, Routing-Pattern, Capability-Helper-Signatur. |
| Wireframes `wireframes.md` -> Screen 1 Sidebar (Z. 113-122) + Card-Reihenfolge ⑤-⑩ (Z. 116-143) | **Layout-Vorlage** fuer Sidebar-Reihenfolge und 5-Card-Stub-Anordnung im Dashboard. Diese Slice rendert das Geruest, nicht die Inhalte. |

**Referenzen:**
- Architecture: `architecture.md` -> "`Hub\Controller`" + "`Hub\View\*`" Service-Map (Z. 392-393); "Admin UI" Tech-Stack (Z. 761); Capability-Standard (Z. 646); "Adapter — Admin Page" Module-Layer (Z. 529).
- Wireframes: `wireframes.md` -> Screen 1 Sidebar (Z. 113-122, Annotation ④ "Hub Sidebar Navigation (8 sections)" Z. 152); Hub-Header (Screen 1 ②③) — fuer dieses Slice nur als Markup-Reservierung relevant (Inhalt = Folge-Slices).
- Discovery: `discovery.md` -> Slice 7 "Hub-Page Skeleton + Settings" (Z. 245); REUSE-Pattern "WC Submenu Page" (Z. 97); REUSE-Pattern "Hub-Page mit Sub-Sections" (Z. 113).
- Slim-Slices: `slices/slim-slices.md` -> Slice-13-Eintrag Z. 281-289 (Done-Signal: alle 8 Sections aufrufbar; Capability-Check redirected; PHPUnit Routing-Dispatch).
- Vorgaenger: `slices/slice-11-settings-form.md` -> AC-1 (Settings-Group registriert); Provides-To "Hub\View\Settings::render" + "Settings::registerSettings".
- Vorgaenger: `slices/slice-02-plugin-bootstrap.md` -> Plugin-Hauptdatei + Bootstrap-Hook-Pipeline (Mount-Point fuer `admin_menu`).
