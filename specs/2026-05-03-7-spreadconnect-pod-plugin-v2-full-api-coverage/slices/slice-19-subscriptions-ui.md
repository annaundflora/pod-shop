# Slice 19: Subscriptions-Manager-UI + Repair-Button

> **Slice 19 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-19-subscriptions-ui` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-18-subscription-manager"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `current_user_can`, `check_ajax_referer`, `wp_send_json_success`, `wp_send_json_error`, `wp_create_nonce`, `admin_url`, `__()`, `esc_html__`, `esc_html`, `esc_attr`, `esc_url`, `add_action`. `SubscriptionManager::diff/register/removeOrphans` via Patchwork-Replace fuer statische Methoden — Test verifiziert Aufruf-Args + Summary-Mapping ohne realen API-Roundtrip.) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: `/wp-admin/admin.php?page=spreadconnect&section=subscriptions` -> Tabelle mit 7 Zeilen; Klick auf [Repair All] -> Banner `{added: N, removed: M}`) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer WP-Admin-API + AJAX-Lifecycle; Patchwork-Replace fuer `SubscriptionManager`-Static-Methods; KEIN realer SC-API-Call — Slice 18 ist die Service-Schicht und wird hier ausschliesslich aufgerufen, nicht re-implementiert) |

---

## Ziel

Liefert die Admin-Adapter-Schicht fuer den Subscription-Lifecycle: `Hub\View\Subscriptions` rendert die 7-Zeilen-Tabelle (Event-Type x Status x SC-ID x Registered-URL x Action) auf Basis von `SubscriptionManager::diff()` und stellt einen Repair-Button bereit, dessen AJAX-Action `spreadconnect_repair_subscriptions` `SubscriptionManager::register()` + `removeOrphans()` orchestriert und das `{added, removed}`-Banner zurueckliefert. Diese Slice schliesst Discovery-Slice 3 "Webhook Receiver + Subscriptions" UI-seitig ab und macht die in Slice 18 etablierte Service-Logik fuer Admins erreichbar.

---

## Acceptance Criteria

> **Quelle der 7 Expected Events:** `SubscriptionManager::EXPECTED_EVENTS` (Slice 18 Provides). Diese Slice referenziert die Konstante, hardcodet die Liste **nicht** lokal.

1) **GIVEN** ein Admin-User mit `manage_woocommerce` ruft `?page=spreadconnect&section=subscriptions` auf UND `SubscriptionManager::diff()` liefert ein Result mit 5 active, 1 missing (`Order.needs-action`), 1 orphan (`Shipment.sent`, eigene veraltete URL)
   **WHEN** `Hub\View\Subscriptions::render()` ausgefuehrt wird
   **THEN** wird Markup erzeugt mit (a) Sidebar-Render fuer `active_slug='subscriptions'` (Slice 13 `Hub\View\Sidebar`), (b) Action-Bar mit `[Repair All]`-Button + `Last check: {timestamp}`, (c) `<table>` mit genau **7 Zeilen** in Reihenfolge der `EXPECTED_EVENTS`-Konstante, (d) jede Zeile traegt CSS-Class `is-status-{active|missing|orphan}`, (e) per-Row-`[Repair]`-Action **nur** in `missing`/`orphan`-Zeilen, **nicht** in `active`-Zeilen, (f) Inline-Hinweis-Text "Repair will POST missing & DELETE orphans — only orphans pointing to our domain are deleted; foreign URLs are preserved". Wireframe-Referenz: `wireframes.md` Z. 692-733 (Screen 8).

2) **GIVEN** `Hub\View\Subscriptions::render()` wird aufgerufen UND `SubscriptionManager::diff()` wirft `SpreadconnectTransientError` oder `SpreadconnectClientError` (z. B. SC-API down)
   **WHEN** Markup erzeugt wird
   **THEN** zeigt die View einen roten Banner mit `__('Cannot reach Spreadconnect', 'spreadconnect-pod')` + `[Retry]`-Link (Page-Reload), und alle 7 Zeilen rendern den Status `unknown` (grey `?`). Kein Fatal, kein leerer Screen. Wireframe-State: `network_error` (`wireframes.md` Z. 744).

3) **GIVEN** `Hub\View\Subscriptions::render()` laeuft erfolgreich
   **WHEN** Markup erzeugt wird
   **THEN** ist im selben Render ein hidden `<input type="hidden" name="_wpnonce">` mit `wp_create_nonce('spreadconnect_repair_subscriptions')` ausgegeben UND eine `data-ajax-action`-Attribute mit Wert `'spreadconnect_repair_subscriptions'` UND ein `data-ajax-url`-Attribute mit `admin_url('admin-ajax.php')`. Alle URL-Outputs durch `esc_url`, alle Attribute durch `esc_attr`, alle Inhalte durch `esc_html`/`esc_html__` escaped (i18n-Domain `spreadconnect-pod`).

4) **GIVEN** AJAX-Request `POST /wp-admin/admin-ajax.php?action=spreadconnect_repair_subscriptions` ohne gueltige Nonce
   **WHEN** `RepairSubscriptions::handle()` ausgefuehrt wird
   **THEN** terminiert der Handler via `wp_send_json_error(['code'=>'invalid_nonce'], 403)` (oder Aequivalent ueber `check_ajax_referer($action, '_wpnonce', false)` mit Failure-Response); `SubscriptionManager::register()` wird **niemals** aufgerufen.

5) **GIVEN** AJAX-Request mit gueltiger Nonce, aber User ohne `manage_woocommerce`-Capability
   **WHEN** `RepairSubscriptions::handle()` ausgefuehrt wird
   **THEN** terminiert der Handler via `wp_send_json_error(['code'=>'forbidden'], 403)` (Capability-Gate ueber `Hub\Controller::ensureCapability` aus Slice 13 oder direkter `current_user_can`-Check); `SubscriptionManager::register()` wird **niemals** aufgerufen.

6) **GIVEN** AJAX-Request mit gueltiger Nonce + `manage_woocommerce`-Capability UND `SubscriptionManager::register()` liefert Summary `['added'=>2, 'removed'=>0, 'skipped'=>5, 'errors'=>[]]` UND `removeOrphans()` liefert `1`
   **WHEN** `RepairSubscriptions::handle()` ausgefuehrt wird
   **THEN** wird die Reihenfolge **erst `removeOrphans()`, dann `register()`** eingehalten (Vermeidung Race: orphan-DELETE vor missing-POST), und der Response-Body `wp_send_json_success(['added'=>2, 'removed'=>1, 'errors'=>[]])` wird ausgegeben. Architecture-Referenz: `architecture.md` Z. 147 (`{added:int, removed:int}` Response-Shape).

7) **GIVEN** AJAX-Request, der `SubscriptionManager::register()` aufruft UND der Manager liefert ein Summary mit non-empty `errors[]` (z. B. ein 4xx fuer einen Event)
   **WHEN** `RepairSubscriptions::handle()` ausgefuehrt wird
   **THEN** wird `wp_send_json_success(['added'=>N, 'removed'=>M, 'errors'=>$summary['errors']])` mit dem `errors[]`-Array aus Slice 18 zurueckgegeben (Wireframe-State `repair_partial_error`); HTTP-Status bleibt 200, der Frontend-Handler entscheidet anhand `errors.length > 0`. Architecture-Referenz: `wireframes.md` Z. 743 (`repair_partial_error`-State).

8) **GIVEN** AJAX-Request, bei dem `SubscriptionManager::register()` einen `SpreadconnectTransientError` (5xx, Network) re-thrown
   **WHEN** `RepairSubscriptions::handle()` ausgefuehrt wird
   **THEN** faengt der Handler die Exception, ruft `wp_send_json_error(['code'=>'transient_error', 'message'=>$e->getMessage()], 503)` (oder 502) und schreibt einen Logger-Eintrag (Source `spreadconnect-subscription-service` oder `spreadconnect-api-client`); kein 500-Stacktrace-Leak; **niemals** `SubscriptionManager::register()` mehrmals re-aufrufen (kein Inner-Retry — Frontend bietet manuellen Retry-Button).

9) **GIVEN** das Save-Success-Panel auf der Settings-Page (Slice 11/14) wurde nach erfolgreichem Settings-Save mit valider Connection gerendert UND der in Slice 18 implementierte Settings-Save-Side-Effect hat `SubscriptionManager::register()` aufgerufen mit Summary `['added'=>N, 'removed'=>M, 'skipped'=>S, 'errors'=>[]]`
   **WHEN** das Panel die Subscription-Step-Result-Zeile rendert
   **THEN** zeigt es entweder `__('Subscriptions registered (%d of 7)', 'spreadconnect-pod')` mit gruenem Marker bei `errors=[]` und `S+N=7` ODER `__('Subscriptions: %d of 7 registered (%d failed)', 'spreadconnect-pod')` mit gelbem/orangem Marker bei `errors[] != []` plus Link `[Open Subscriptions Manager →]` zu `?section=subscriptions`. Wireframe-Referenz: `wireframes.md` Z. 645 + Z. 659-684. Diese Slice **rendert nur** die Step-Result-Markup-Helper-Funktion; die Save-Success-Panel-Mechanik selbst stammt aus Slice 11/14.

10) **GIVEN** die Stub-Klasse `Hub\View\Subscriptions` aus Slice 13 (Routing-Stub) existiert UND diese Slice ersetzt sie durch eine vollstaendige Implementation
    **WHEN** `Hub\Controller::dispatch()` mit `?section=subscriptions` geroutet wird
    **THEN** wird `Hub\View\Subscriptions::render(): void` aus dieser Slice aufgerufen — die Slice-13-Stub-Datei wird **ueberschrieben**, nicht parallel betrieben (kein FQCN-Konflikt). `Hub\Controller`-Code (Slice 13) bleibt unveraendert, da nur die View-Klasse selbst ausgetauscht wird.

11) **GIVEN** die Repair-AJAX-Action wird in WP registriert
    **WHEN** das Plugin bootet
    **THEN** ist die Action via `add_action('wp_ajax_spreadconnect_repair_subscriptions', [RepairSubscriptions::class, 'handle'])` einmal registriert; KEIN `wp_ajax_nopriv_*`-Eintrag (kein Public-Endpoint). Mount-Point liegt im selben Bootstrap-Pfad, der bereits in Slice 12/14 fuer andere AJAX-Handler genutzt wird (Edit `Bootstrap\Plugin::init()` oder `RepairSubscriptions::register()`-Helper aufgerufen aus `Bootstrap\Plugin`).

12) **GIVEN** der AJAX-Handler schreibt einen Logger-Eintrag fuer Repair-Outcome
    **WHEN** ein Repair-Aufruf endet (Success oder Error)
    **THEN** enthaelt der Logger-Eintrag (Source `spreadconnect-subscription-service`) Marker `'subscription_repair_invoked'` + Summary-Counts (`added`, `removed`); enthaelt **niemals** den Plaintext-Secret-Wert. Bearer-Token + Secret-Redaction wird durch Slice 07/14-Adapter sichergestellt; diese Slice darf den Secret **nicht** loggen.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey-Mocks fuer WP-AJAX-Lifecycle (`check_ajax_referer`, `current_user_can`, `wp_send_json_success`, `wp_send_json_error`, `wp_create_nonce`, `admin_url`, `__`, `esc_*`). Patchwork-Replace fuer `SubscriptionManager::diff`, `SubscriptionManager::register`, `SubscriptionManager::removeOrphans` (statische Methoden) — Tests verifizieren Aufruf-Args + Reihenfolge ohne realen Service. Sidebar-Render aus Slice 13 wird via Patchwork gestubbt (Aufruf-Counter). KEIN realer HTTP-Roundtrip noetig.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-19-subscriptions-ui.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class SubscriptionsUiTest extends TestCase
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

    // AC-1: render() emittiert 7-Row-Tabelle in EXPECTED_EVENTS-Reihenfolge mit korrekten Status-Classes
    public function test_render_emits_seven_rows_in_expected_events_order_with_status_classes(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: per-row [Repair] Action erscheint nur fuer missing/orphan, nicht fuer active
    public function test_render_shows_per_row_repair_only_for_missing_and_orphan(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: diff() wirft -> View zeigt network_error-Banner, alle Rows als unknown
    public function test_render_shows_network_error_banner_when_diff_throws(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: render() erzeugt nonce + data-ajax-action Markup, alles escaped
    public function test_render_emits_nonce_and_ajax_data_attributes_escaped(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: AJAX ohne valide Nonce -> wp_send_json_error 403, kein register() Aufruf
    public function test_ajax_without_valid_nonce_returns_403_and_skips_manager(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: AJAX mit valider Nonce aber ohne Capability -> 403, kein register() Aufruf
    public function test_ajax_without_capability_returns_403_and_skips_manager(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Reihenfolge removeOrphans() vor register(), Response liefert {added, removed, errors}
    public function test_ajax_calls_remove_orphans_before_register_and_returns_summary(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: register()-Summary mit errors[] liefert success-Response inkl. errors-Array
    public function test_ajax_returns_success_with_errors_array_on_partial_failure(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: TransientError aus register() -> wp_send_json_error 503, kein Inner-Retry
    public function test_ajax_returns_503_on_transient_error_without_inner_retry(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Save-Success-Panel-Step-Result-Helper rendert success vs. partial-error Variante
    public function test_save_success_step_result_renders_success_and_partial_variants(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Slice-13-Stub wird ueberschrieben, dispatch routes auf neue Implementation
    public function test_subscriptions_view_class_replaces_slice_13_stub(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: AJAX-Action ist registriert ohne nopriv-Variante
    public function test_ajax_action_registered_without_nopriv_variant(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    // AC-12: Logger emittiert Repair-Marker + Counts, niemals Plaintext-Secret
    public function test_logger_emits_repair_marker_without_plaintext_secret(): void
    {
        $this->markTestIncomplete('AC-12');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-18-subscription-manager` | `Subscription\SubscriptionManager::diff(): array{active, missing, orphans}` | static method | Render konsumiert das Diff-Result fuer Tabellen-Aufbau (AC-1, AC-2). |
| `slice-18-subscription-manager` | `Subscription\SubscriptionManager::register(): array{added, removed, skipped, errors}` | static method | Repair-AJAX ruft `register()` (AC-6, AC-7). |
| `slice-18-subscription-manager` | `Subscription\SubscriptionManager::removeOrphans(): int` | static method | Repair-AJAX ruft `removeOrphans()` vor `register()` (AC-6). |
| `slice-18-subscription-manager` | Constant `SubscriptionManager::EXPECTED_EVENTS` | `final public const` | View iteriert ueber die Konstante fuer 7-Zeilen-Reihenfolge (AC-1). KEIN lokales Hardcode der Event-Liste. |
| `slice-13-hub-page-skeleton` | `Hub\View\Sidebar::render(string $active_slug): void` | static method | View-Wrapper ruft Sidebar mit `'subscriptions'` (AC-1). |
| `slice-13-hub-page-skeleton` | `Hub\Controller::ensureCapability(): void` | static method | AJAX-Handler nutzt den Capability-Helper (AC-5). |
| `slice-13-hub-page-skeleton` | Routing `?section=subscriptions` -> `Hub\View\Subscriptions::render` | dispatch wiring | AC-10: Slice-13-Stub wird durch diese Slice's vollstaendige Implementation ersetzt; Routing-Code unveraendert. |
| `slice-11-settings-form` / `slice-14-webhook-secret-manager` | Save-Success-Panel-Render-Slot | view markup | AC-9: Step-Result-Helper-Markup wird in den Save-Success-Panel-Render aufgerufen — Slot-Mechanik existiert in Slice 11/14, diese Slice liefert nur den Subscription-spezifischen Markup-Helper. |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::init()` Hook-Pipeline | method | Mount-Point fuer `add_action('wp_ajax_spreadconnect_repair_subscriptions', ...)` (AC-11). |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Hub\View\Subscriptions::render` | static method | `Hub\Controller::dispatch` (Slice 13) | `public static function render(): void` |
| `SpreadconnectPod\Hub\View\Subscriptions::renderSaveSuccessStepResult` | static method | Settings-Save-Success-Panel (Slice 11/14) | `public static function renderSaveSuccessStepResult(array $registerSummary): void` (rendert die Subscription-Step-Result-Zeile) |
| AJAX-Action `spreadconnect_repair_subscriptions` | WP AJAX hook | Frontend (Subscriptions-Page JS-Click-Handler — Inline-Script innerhalb dieser Slice's `render()`) | Request: `_wpnonce`; Response: `{added:int, removed:int, errors:array}` (HTTP 200 / 403 / 503) |
| `SpreadconnectPod\Hub\Ajax\RepairSubscriptions::handle` | static method | WP-AJAX-Callback | `public static function handle(): void` (terminiert via `wp_send_json_*`) |
| Subscriptions-UI als komplette Section | Sidebar-Nav-Link funktional | Slice 13 Sidebar | Klick auf `?section=subscriptions` fuehrt jetzt zu funktionaler Page (statt Stub). |
| Subscription-Step-Result-Markup-Helper | Markup-Snippet | Slice 14 `WebhookSecretManager`-Save-Success-Panel | Wird im Save-Success-Panel nach `register()`-Step gerendert (AC-9). |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Subscriptions.php` — Klasse `SpreadconnectPod\Hub\View\Subscriptions` als `final class` mit ausschliesslich `static` Methoden: `render(): void` (Sidebar + Action-Bar + 7-Zeilen-Tabelle + Inline-Hint + Nonce + AJAX-Markup), `renderSaveSuccessStepResult(array $registerSummary): void` (Markup-Helper fuer Settings-Save-Success-Panel-Step-Result, AC-9), `private static buildRows(array $diff): array` (verheiratet `EXPECTED_EVENTS` mit `diff()`-Buckets zu 7 Row-Records mit Status `active`/`missing`/`orphan`/`unknown`). Inline-JS-Click-Handler fuer `[Repair All]`-Button (XHR an `admin-ajax.php`, Banner-Update bei Response). Ueberschreibt Slice-13-Stub.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/RepairSubscriptions.php` — Klasse `SpreadconnectPod\Hub\Ajax\RepairSubscriptions` als `final class` mit `handle(): void` (Capability-Gate via `Hub\Controller::ensureCapability` + Nonce-Verify via `check_ajax_referer('spreadconnect_repair_subscriptions')`; Reihenfolge `removeOrphans()` -> `register()`; Mapping Summary -> `wp_send_json_success(['added'=>N, 'removed'=>M, 'errors'=>[]])`; Exception-Branch `SpreadconnectTransientError` -> 503; `SpreadconnectClientError` und unerwartete `\Throwable` -> 500/`wp_send_json_error`) und `register(): void` (registriert `add_action('wp_ajax_spreadconnect_repair_subscriptions', ...)`).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02) — Mount-Point: Aufruf `RepairSubscriptions::register()` im `init`-Hook (analog zu den AJAX-Handlern aus Slice 12 `TestConnection` und Slice 14 `RegenerateSecret`). Bestehende Bootstrap-Logik bleibt unveraendert.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-19-subscriptions-ui.php` wird vom Test-Writer-Agent erstellt. Keine separaten JS-Asset-Dateien — Inline-Script innerhalb von `Subscriptions::render()` ist scope-akzeptabel fuer einen einzelnen Click-Handler ohne Build-Step-Pflicht (analog zu `Settings`-Page-Inline-Handler in Slice 11/12). KEINE neue View-Stub-Klasse fuer andere Sections — Slice-Scope endet an `Subscriptions`/`RepairSubscriptions`.

---

## Constraints

**Scope-Grenzen:**
- KEINE Re-Implementierung der Diff-Logik — `SubscriptionManager::diff()` aus Slice 18 ist Single Source of Truth (AC-1, AC-2).
- KEINE Modifikation von `SubscriptionManager` — diese Slice ist reiner Adapter; sie konsumiert die Service-Methoden aus Slice 18.
- KEINE `EXPECTED_EVENTS`-Liste lokal hardcoden — immer Konstante aus Slice 18 referenzieren (Konsistenzgarantie zwischen UI und Service).
- KEIN eigener `wp_options`-Write fuer Last-Check-Timestamp — wenn benoetigt, lesen aus optionalem Read-Through-Cache (Slice 18 Constraint erlaubt das, aber Slice 19 liefert keinen Cache; Last-Check rendert simpel `current_time('mysql')` zum Render-Zeitpunkt oder leeren String).
- KEIN Refresh-from-SC-Button als separater AJAX-Endpoint — Page-Reload ist der "Refresh"; der Button im Wireframe `① Refresh from SC` ist ein simpler Reload-Link (`<a href="?page=spreadconnect&section=subscriptions">`), keine eigene AJAX-Action.
- KEIN per-row-Repair als separater AJAX-Endpoint — der Wireframe `④ [Repair]` (per Row) triggert dieselbe `spreadconnect_repair_subscriptions`-Action; Backend macht ohnehin Diff+Bulk. Per-row-Button ist UI-Affordance, kein separater Code-Path.
- KEINE Hub-Dashboard-Card-Integration — `subscription_status_badge` (X/7 active) auf Dashboard ist Aufgabe von Slice 26/46.
- KEINE FailedOpsRepo-Integration fuer Repair-Errors — `errors[]` aus Summary wird im Banner angezeigt; persistente Notice-Logik ist Slice 39.
- KEINE WP-CLI-Variante — Slice 46 (Polish) optional.
- KEINE neue View-Stub-Klassen fuer andere Sections (`Catalog`, `Orders`, `Webhooks`, `FailedOps`, `Logs`) — diese Slice ueberschreibt nur die `Subscriptions`-Stub-Klasse (AC-10).

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in beiden neuen Dateien.
- `Hub\View\Subscriptions` und `Hub\Ajax\RepairSubscriptions` als `final class` mit ausschliesslich `static` Methoden — Stateless-Adapter-Pattern (konsistent mit Slice 12/13/14).
- AJAX-Reihenfolge **Pflicht** (AC-6): `removeOrphans()` zuerst, dann `register()`. Begruendung: Falls dieselbe URL als orphan + missing gleichzeitig gilt (architecture-bekanntes Edge-Case Slice-18 AC-2), muss DELETE vor POST erfolgen, sonst 4xx-Konflikt auf duplicate eventType.
- Nonce-Action-String **exakt** `'spreadconnect_repair_subscriptions'` — identisch zwischen `wp_create_nonce()` (View) und `check_ajax_referer()` (AJAX-Handler).
- HTTP-Status-Codes: Nonce-Fail/Cap-Fail -> 403; TransientError -> 503; ClientError -> 500; Success (auch mit `errors[]`) -> 200. Begruendung Slice-Discipline: 200+errors-Array ist Wireframe-`repair_partial_error`-State (AC-7); echte 5xx nur bei API-/Service-Failure.
- Status-CSS-Class-Naming: `is-status-active` (gruen), `is-status-missing` (rot), `is-status-orphan` (gelb), `is-status-unknown` (grau). Konsistent mit `subscription_status_badge`-Komponente aus `wireframes.md` Z. 17.
- I18n: alle User-facing Strings via `__()`/`esc_html__()`/`_e()` mit Domain `'spreadconnect-pod'`. Pluralisierung fuer "X of 7 registered" via `_n()` ist Bonus, nicht Pflicht (sprintf mit `__()` ist akzeptabel, da N=7 fix).
- Escaping: alle URL-Outputs `esc_url`, alle Attribute `esc_attr`, alle Inhalte `esc_html`/`esc_html__`. KEINE `echo $foo` ohne Escape.
- Reihenfolge im Markup (oben nach unten): Sidebar, Hub-Header, Action-Bar (`[Repair All]` + Hint-Text + Last-Check), Tabelle (Header + 7 Rows), Banner-Container (initial empty, JS-populated nach Repair-Response), Inline-`<script>` mit JS-Click-Handler.
- AJAX-JS-Click-Handler ist **inline** in `render()`-Output (kein separater `assets/js/`-File). Begruendung: einzelner Handler, keine Wiederverwendung, < 50 Zeilen JS. Falls > 50 Zeilen oder Wiederverwendung: Implementer darf optional `assets/js/subscriptions-ui.js` extrahieren und via `wp_enqueue_script` in `render()` enqueuen — dann ist die JS-Datei **kein** separates Deliverable, sondern Implementation-Detail-Aufstockung.
- `RepairSubscriptions::handle()`: Catch-Reihenfolge `SpreadconnectTransientError` -> 503, `SpreadconnectClientError` -> 500 mit `errors[]`-Wrapping, `\Throwable` -> 500 generic. KEIN Outer-Retry (siehe AC-8 Begruendung).
- Per-row-`[Repair]`-Button traegt `data-event-type="{eventType}"` Attribut (Read-Only-Affordance — Backend ignoriert es, da Bulk-Logik). Diese Markup-Decoration ist optional fuer kuenftige Per-Row-AJAX (nicht in dieser Slice).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Subscription/SubscriptionManager.php` (Slice 18) | **Import** — `diff()`, `register()`, `removeOrphans()`, `EXPECTED_EVENTS` werden konsumiert. NICHT modifizieren. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Sidebar.php` (Slice 13) | **Import** — `Sidebar::render('subscriptions')` als erstes im Page-Render. NICHT modifizieren. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/Controller.php` (Slice 13) | **Import** — `Controller::ensureCapability()` als geteilter Capability-Gate-Helper im AJAX-Handler. NICHT modifizieren. Routing-Code (Slice 13) bleibt unveraendert; nur die Stub-View-Klasse wird ueberschrieben. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` (Slice 07) | **Import** — Catch-Klausel im AJAX-Handler (AC-8). |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectTransientError.php` (Slice 07/08) | **Import** — Catch-Klausel im AJAX-Handler (AC-8). |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02) | **Edit** — Mount-Point: `RepairSubscriptions::register()` im `init`-Hook aufrufen. Bestehende Bootstrap-Logik unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Settings.php` (Slice 11) bzw. Save-Success-Panel-Renderer aus Slice 14 | **Read-Only Reuse** — der Save-Success-Panel-Renderer ruft den neuen Helper `Subscriptions::renderSaveSuccessStepResult(...)` auf (AC-9). Diese Slice modifiziert die Settings-Datei **nicht** im Deliverable-Edit (zumindest nicht zwingend) — falls der Save-Success-Panel-Renderer den neuen Helper aufrufen muss, kann der Implementer entweder (a) Slice 14 hat diesen Hook bereits exposed, dann Subscribe via Hook, ODER (b) ein Edit von Slice-11/14-Save-Success-Panel-File ist erforderlich, dann gehoert dieser Edit zur _natuerlichen_ Anpassung und ist NICHT als Deliverable gelistet, weil keine eigenstaendige Funktion (Slice-13-Pattern: Stub-Ueberschreibung). Implementer-Wahl basierend auf der konkreten Slice-14-Architektur. |

**Referenzen:**
- Architecture: `architecture.md` -> Endpoint-Tabelle Z. 106-108 (GET/POST/DELETE /subscriptions); AJAX-Inventory Z. 147 (`spreadconnect_repair_subscriptions`-Response-Shape `{added:int, removed:int}`); Service-Map `Hub\View\*` Z. 393; Risk-Mitigation Z. 739 (Repair ist idempotent); UI-Component `repair_subscriptions_button` Z. 18.
- Wireframes: `wireframes.md` -> Screen 8 Subscriptions Manager Z. 692-744 (Layout, Annotations ①-⑤, State-Variations); Save-Success-Panel Step-Result Z. 645 + Z. 659-684; Component `subscription_status_badge` Z. 17.
- Discovery: `discovery.md` -> Flow H Subscription-Repair Z. 204-211; Subscription-State-Tabelle Z. 473-479; Component-Inventory `repair_subscriptions_button` Z. 433-434; Constraint Z. 605 ("Repair loescht nur orphan deren URL = unsere"); Discovery-Slice 3 "Webhook Receiver + Subscriptions" Z. 924.
- Slim-Slices: `slices/slim-slices.md` -> Slice-19-Eintrag Z. 350-357 (Done-Signal: Repair-Klick zeigt `{added: N, removed: M}`-Banner; Diff-Logic markiert orphan korrekt mit URL-Match-only fuer DELETE).
- Vorgaenger Slice 13: `slices/slice-13-hub-page-skeleton.md` -> Stub-Klasse `Hub\View\Subscriptions` (Routing-Stub, AC-10 hier ersetzt sie); `Hub\Controller::ensureCapability` als Capability-Helper.
- Vorgaenger Slice 18: `slices/slice-18-subscription-manager.md` -> Provides `SubscriptionManager::diff/register/removeOrphans/EXPECTED_EVENTS`; AC-7 dort beschreibt Settings-Save-Hook, AC-9 hier rendert das daraus resultierende Step-Result.
- Folge-Slice 26 (Hub-Dashboard): konsumiert `SubscriptionManager::diff()->active`-Count fuer Card 4 — diese Slice und Slice 26 teilen sich denselben Service ohne UI-Duplikation.
