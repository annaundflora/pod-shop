# Slice 41: Webhook-Log-UI

> **Slice 41 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-41-webhook-log-ui` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-16-event-id-hasher", "slice-13-hub-page-skeleton"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WC 10.5 HPOS + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `current_user_can`, `wp_create_nonce`, `wp_enqueue_script`, `wp_localize_script`, `admin_url`, `__()`, `esc_html__()`, `esc_attr`, `esc_html`, `esc_url`, `wp_kses_post`, `sanitize_key`, `absint`, `sanitize_text_field`, `wp_unslash`, `get_option`; `$wpdb`-Stub mit `prepare()`, `get_results()`, `get_var()`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: `/wp-admin/admin.php?page=spreadconnect&section=webhooks` -> Filter setzen, Row-Expand klicken, JSON-Payload sichtbar) |
| **Health Endpoint** | `n/a` (read-only Admin-Section, keine REST-Route, keine AJAX-Mutation) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer WP-Admin-/Escape-/Asset-API; in-memory `$wpdb`-Spy mit konfigurierbarem `get_results()`-Returnwert; `Sidebar::render` (Slice 13) und `WebhookLogRepo::find` (Slice 16) per Patchwork beobachtbar) |

---

## Ziel

Liefert Discovery-Slice 10 "Logs + Polish"-Surface fuer den Webhook-Audit-Trail: read-only Admin-Section unter `?page=spreadconnect&section=webhooks` rendert `wp_spreadconnect_webhook_log` als gefilterte, paginierte Tabelle mit per-Row-Expand fuer JSON-Payload + Processing-Log. Inline JS-Asset toggelt nur DOM-Sichtbarkeit — keine Mutations-Endpunkte, keine AJAX-Reloads.

---

## Acceptance Criteria

1) **AC-Routing — Section `webhooks` ruft `Hub\View\Webhooks::render()`**
   **GIVEN** ein Admin mit `manage_woocommerce` ruft `?page=spreadconnect&section=webhooks` auf
   **WHEN** `Hub\Controller::dispatch()` (Slice 13) das Section-Routing ausfuehrt
   **THEN** wird `Hub\View\Webhooks::render(): void` genau einmal aufgerufen; die Klasse muss als `final class SpreadconnectPod\Hub\View\Webhooks` existieren und Slice-13-Section-Slug `webhooks` aus der Whitelist (Slice 13 Section-Slug-Tabelle) bedienen. Die Routing-Stub-Strategie aus Slice 13 wird durch diese Slice erfuellt — nach diesem Slice ist die `Webhooks`-Section produktiv.

2) **AC-Capability-Gate — render() ist gegen Read-Cap geschuetzt**
   **GIVEN** ein User ohne `manage_woocommerce`-Capability
   **WHEN** `Hub\View\Webhooks::render()` aufgerufen wird
   **THEN** wird `Hub\Controller::ensureCapability()` (Slice 13) aufgerufen; der Aufruf terminiert via `wp_die()` und keine `$wpdb`-Query und kein Markup wird emittiert. Auch read-only-Page nutzt Cap-Gate — Architecture Z. 484.

3) **AC-Filter-Read-And-Sanitize — `?event=`, `?range=`, `?hmac=`, `?proc=`, `?paged=` werden aus `$_GET` sanitisiert**
   **GIVEN** ein Request mit `$_GET = ['event' => 'Order.processed', 'range' => '7d', 'hmac' => 'invalid', 'proc' => 'error', 'paged' => '3', 'section' => 'webhooks', 'page' => 'spreadconnect']`
   **WHEN** `render()` die Filter-Werte einliest
   **THEN** werden alle Werte ueber `sanitize_text_field(wp_unslash(...))` (Strings) bzw. `absint(...)` (`paged`) defensiv gefiltert und gegen Whitelists validiert: `event` ∈ {`'all'`, `'Article.added'`, `'Article.updated'`, `'Article.removed'`, `'Order.processed'`, `'Order.cancelled'`, `'Order.needs-action'`, `'Shipment.sent'`} (8 Werte; siehe architecture.md Z. 41); `range` ∈ {`'24h'`, `'7d'`, `'30d'`, `'all'`}; `hmac` ∈ {`'all'`, `'valid'`, `'invalid'`}; `proc` ∈ {`'all'`, `'success'`, `'error'`, `'pending'`, `'duplicate'`} (Schema architecture.md Z. 223). Unbekannte Werte fallen auf `'all'` zurueck. `paged` Default = `1`, Minimum = `1`. Kein literaler `$_GET`-Wert erreicht die SQL-Query.

4) **AC-Query-Index-Usage — Filter-Query nutzt die in Slice 04 angelegten Indexes**
   **GIVEN** sanitisierte Filter `event = 'Order.processed'`, `range = '7d'`, `hmac = 'all'`, `proc = 'error'`
   **WHEN** `Hub\View\Webhooks::buildQuery(array $filters)` die SQL via `$wpdb->prepare()` erstellt
   **THEN** enthaelt die `WHERE`-Klausel:
   - `event_type = %s` (Filter `event`),
   - `received_at >= %s` (Filter `range` — UTC-DATETIME-String aus `current_time('mysql', true)` minus Intervall),
   - `processing_status = %s` (Filter `proc`),
   - **kein** `hmac_status`-Predikat falls `hmac='all'`.
   `ORDER BY received_at DESC` und `LIMIT %d OFFSET %d` zur Pagination ueber `idx_received_at` (architecture.md Z. 229). Der Done-Signal-Vertrag aus slim-slices.md Z. 610 ("PHPUnit Filter-Query nutzt korrekte Indexes") wird durch Inspektion des `prepare()`-Strings auf erwartete Spalten/Reihenfolge erfuellt — **kein** Volltext-Search, **kein** `LIKE %payload%`.

5) **AC-Pagination — `paged` schreibt OFFSET, Page-Size = 25, Total-Count fuer Pager**
   **GIVEN** `paged = 3` und Filter wie AC-4
   **WHEN** `buildQuery()` und der Total-Count-Query laufen
   **THEN** wird `LIMIT 25 OFFSET 50` (Page-Size 25 als `private const PAGE_SIZE`) gesetzt; der Total-Count-Query ist ein separates `SELECT COUNT(*) FROM ... WHERE ...` mit identischer `WHERE`-Klausel (gleiche `prepare()`-Argument-Reihenfolge); Pager-Markup zeigt `Prev` (disabled bei `paged=1`) + `Next` (disabled wenn `OFFSET + PAGE_SIZE >= total`) + Page-Counter `Seite 3 von N`. Kein WP-Paginierungs-Helper wird missbraucht — eigenes minimales Pager-Markup.

6) **AC-Empty-State — Keine Rows -> Wireframe-State `empty`**
   **GIVEN** der Filter-Query liefert `[]`
   **WHEN** `render()` Markup erzeugt
   **THEN** wird statt `<tbody>` der Empty-State-Panel mit Text `__('Noch keine Webhook-Events empfangen — pruefe, dass Subscriptions in [Subscriptions] aktiv sind.', 'spreadconnect-pod')` ausgegeben (wireframes.md Screen 4 State `empty` Z. 389). Bei aktiven Filtern und 0 Treffern: stattdessen `__('Keine Events stimmen mit den Filtern ueberein. [Filter zuruecksetzen]', 'spreadconnect-pod')` mit Link auf die Section-URL ohne Query-Args (State `filter_no_results` Z. 393). Differenzierung anhand der Frage "alle Filter == default?".

7) **AC-Row-Markup — Tabellen-Row enthaelt Daten-Attribute fuer Expand-JS**
   **GIVEN** eine geladene Row aus `WebhookLogRepo` (id=4711)
   **WHEN** `render()` einen `<tr>` ausgibt
   **THEN** traegt das Row-Element `data-log-id="4711"` als Identifier; Spalten in Wireframe-Reihenfolge (wireframes.md Screen 4 Z. 339-348): Received (`received_at` als lokalisiertes Datum via `wp_date()`), Event-Type, Entity (Linktext zu SC-Backend ↗ wenn `event_type` mit `Order.` startet UND `related_entity_id` existiert; sonst Plain-Text — kein Outbound-Link bei `event_type='_unknown'`), HMAC-Status (`✓`/`✗`-Glyph + `aria-label`), Processing-Status (Badge `✓ ok`/`⚠ err`/`pending`/`duplicate`), Action-Cell (leer; "Retry"-Button explizit NICHT in dieser Slice — nur Visual-Detail des Wireframes). State-Variations `hmac_failed_row`, `processing_error_row`, `unknown_event_row` aus wireframes.md Z. 390-393 als CSS-Class-Hooks (`is-hmac-failed`, `is-proc-error`, `is-unknown-event`); KEINE Inline-Styles.

8) **AC-Row-Expand-Trigger-Markup — Chevron-Button mit ARIA**
   **GIVEN** eine Tabellen-Row
   **WHEN** der Expand-Trigger gerendert wird
   **THEN** ist es ein `<button type="button" class="spreadconnect-row-toggle" aria-expanded="false" aria-controls="spreadconnect-row-detail-{id}">` mit `▶`-Glyph (oder semantisches Icon); pro Row existiert ein verstecktes Detail-`<tr id="spreadconnect-row-detail-{id}" hidden>` mit `colspan` ueber alle Spalten. Detail-`<tr>` enthaelt: `<pre class="spreadconnect-payload-json">` mit dem `payload`-Feld (re-encoded JSON aus Slice 16 — pretty-printed via `wp_json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)`), Processing-Log-Block mit `processing_status` + `processing_error` + `received_at`, sowie Source-IP-Text (wenn in `payload._source_ip` enthalten — andernfalls weglassen).

9) **AC-HMAC-Invalid-Payload-Hidden — Security-Constraint**
   **GIVEN** Row mit `hmac_status='invalid'`
   **WHEN** der Detail-`<tr>` gerendert wird
   **THEN** wird der `payload`-`<pre>` durch ein Hint ersetzt: `__('Payload nicht gespeichert (HMAC ungueltig — nur Header/IP geloggt).', 'spreadconnect-pod')`. Begruendung: architecture.md Z. 493 "Invalid payloads: header-snippet only". Nur `received_at`, `event_type` (kann `_unknown` sein) und IP-Snippet sind sichtbar.

10) **AC-Output-Escaping — alles entsprechend WP-Standard escaped**
    **GIVEN** `render()` produziert Markup
    **WHEN** Werte aus der DB oder aus `$_GET` ausgegeben werden
    **THEN** sind alle Inhalte durch `esc_html()` (Text), `esc_attr()` (Attribute), `esc_url()` (Links), `wp_kses_post()` (kein User-HTML — nur Filter/Pager-Markup), und User-facing Strings durch `__()`/`esc_html__()` mit Domain `'spreadconnect-pod'` gewrapped. JSON-Payload im `<pre>` wird via `esc_html()` escaped (kein Raw-Render, auch wenn pretty-printed). Kein `echo $row['payload']` ohne Escape.

11) **AC-Retention-Hint — Text aus Setting**
    **GIVEN** Option `spreadconnect_webhook_log_retention_days` (Slice 05; architecture.md Z. 340) liefert int `90`
    **WHEN** `render()` den Retention-Hinweis aus wireframes.md Screen 4 Annotation ② emittiert
    **THEN** zeigt der Hint `__('Aufbewahrung: %d Tage (konfigurierbar in Einstellungen)', 'spreadconnect-pod')` mit interpoliertem Wert ueber `sprintf` (oder `wp_kses_post(sprintf(__(...)))`); Link "Einstellungen" zeigt auf `?page=spreadconnect&section=settings` mit `esc_url(admin_url(...))`. **Kein** Mutate-Knopf; die Slice ist read-only.

12) **AC-Asset-Enqueue — `webhook-log-expand.js` registriert mit korrekten Deps**
    **GIVEN** `Hub\View\Webhooks::enqueueAssets()` wird auf dem Hook-Suffix der eigenen Section ausgefuehrt
    **WHEN** das JS-Asset registriert wird
    **THEN** wird `wp_register_script('spreadconnect-webhook-log-expand', plugins_url('assets/js/webhook-log-expand.js', SPREADCONNECT_POD_FILE), [], SPREADCONNECT_POD_VERSION, true)` (no jQuery dep) gefolgt von `wp_enqueue_script('spreadconnect-webhook-log-expand')`. Nur dann, wenn die aktive Section `webhooks` ist (kein globaler Admin-Enqueue). Asset-Handle eindeutig, Version-Pin via Plugin-Konstante (Slice 02).

13) **AC-JS-Behaviour — Click toggelt Expand ohne Page-Reload, ohne AJAX**
    **GIVEN** das geladene `webhook-log-expand.js` und der DOM aus AC-7/AC-8
    **WHEN** der User auf einen `.spreadconnect-row-toggle`-Button klickt
    **THEN** toggelt das Skript das `hidden`-Attribut der zugehoerigen `<tr id="spreadconnect-row-detail-{id}">` und setzt `aria-expanded` auf `'true'`/`'false'`; KEIN `fetch()`/`XMLHttpRequest`/`jQuery.ajax`. Ein zweiter Klick auf einen anderen Toggle-Button laesst bereits offene Details geoeffnet (multiple-open allowed). Initialisierung erfolgt einmalig beim `DOMContentLoaded` via Event-Delegation auf `document` (oder Tabelle), damit zukuenftige re-renders nicht erforderlich sind.

14) **AC-No-Mutation — keine state-veraendernden Endpunkte**
    **GIVEN** dieser Slice 41
    **WHEN** alle Deliverables zusammen gepruefte werden
    **THEN** existiert kein `add_action('wp_ajax_*', ...)`, kein `register_rest_route(...)`, kein `$wpdb->update`/`->insert`/`->delete` und kein `wp_create_nonce()`. Read-only-Constraint: nur `SELECT` via `WebhookLogRepo`-Methoden bzw. lokal komponierte Queries. (Begruendung: AC-Modal-/Resend-Pfad fuer Webhook-Logs ist Slice-37/38-Failed-Ops-Verantwortlichkeit, NICHT diese Slice.)

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey-Mocks fuer alle WP-Admin-/Escape-/Asset-Funktionen. `$wpdb` als Mockery-Mock mit `prepare()` (gibt SQL-String zurueck), `get_results()` (konfigurierbarer Rows-Array), `get_var()` (Total-Count). `Hub\Controller::ensureCapability` und `Hub\View\Sidebar::render` per Patchwork-Replace beobachtbar (Aufruf-Counter). `WebhookLogRepo::find/findFiltered` aus Slice 16 wird gemockt; falls `findFiltered` in Slice 16 nicht existiert, fuegt diese Slice die Implementierung in `WebhookLogRepo` hinzu (siehe Constraints "Repo-Erweiterung").

### Test-Datei: `tests/slices/pod-shop-mvp/slice-41-webhook-log-ui.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class WebhookLogUiTest extends TestCase
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

    // AC-1: Section-Routing webhooks dispatched zu Hub\View\Webhooks::render
    public function test_section_webhooks_routes_to_webhooks_view(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Ohne manage_woocommerce -> ensureCapability terminiert via wp_die
    public function test_render_aborts_without_manage_woocommerce_capability(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Filter-Werte werden via sanitize_text_field + Whitelist-Match gefiltert
    public function test_filter_params_sanitized_and_whitelisted(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: Unbekannte Filter-Werte fallen auf 'all'/Default zurueck
    public function test_unknown_filter_values_fall_back_to_defaults(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Filter-Query nutzt event_type, received_at, processing_status mit prepare()
    public function test_build_query_uses_indexed_columns_in_where(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: hmac_status='all' wird NICHT zur WHERE-Klausel hinzugefuegt
    public function test_build_query_omits_hmac_predicate_when_all(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: ORDER BY received_at DESC + LIMIT/OFFSET nutzen idx_received_at
    public function test_build_query_orders_by_received_at_desc_with_limit_offset(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: paged=3 -> OFFSET 50 bei PAGE_SIZE 25
    public function test_pagination_offset_calculation(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: Total-Count-Query nutzt identische WHERE-Klausel
    public function test_total_count_query_uses_same_where_clause(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: Pager-Markup disabled Prev bei paged=1, Next bei letzter Seite
    public function test_pager_disables_prev_on_first_and_next_on_last_page(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Leeres Result + Default-Filter -> Empty-Panel "Noch keine Webhook-Events"
    public function test_empty_state_panel_when_no_rows_and_no_filters(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-6: Leeres Result mit aktiven Filtern -> "Keine Events stimmen mit Filtern ueberein"
    public function test_filter_no_results_panel_when_filters_active(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Row-Markup enthaelt data-log-id und Wireframe-Spalten in Reihenfolge
    public function test_row_markup_has_data_log_id_and_columns_in_wireframe_order(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: State-Variations als CSS-Class-Hooks
    public function test_row_state_classes_applied_for_invalid_hmac_proc_error_and_unknown(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Toggle-Button hat aria-expanded + aria-controls + Detail-Row-ID
    public function test_expand_trigger_uses_aria_expanded_and_controls(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: Detail-Row enthaelt pretty-printed JSON-Pre + Processing-Log
    public function test_detail_row_contains_pretty_json_and_processing_log(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: hmac_status=invalid -> Payload-Pre wird durch Hint ersetzt
    public function test_invalid_hmac_row_hides_payload(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Output-Escaping greift fuer DB-Werte und $_GET-Werte
    public function test_outputs_are_escaped_with_esc_html_attr_url(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-10: User-Strings sind via __()/esc_html__() mit spreadconnect-pod-Domain
    public function test_user_strings_use_text_domain(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: Retention-Hint zeigt Wert aus Option spreadconnect_webhook_log_retention_days
    public function test_retention_hint_reads_option_and_renders_value(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    // AC-12: Asset wird nur in Section webhooks enqueued + ohne jQuery-Dep
    public function test_enqueue_assets_only_on_webhooks_section_without_jquery(): void
    {
        $this->markTestIncomplete('AC-12');
    }

    // AC-13: JS-Asset toggelt hidden-Attribut + aria-expanded ohne fetch/AJAX
    public function test_js_asset_toggles_visibility_without_network(): void
    {
        $this->markTestIncomplete('AC-13');
    }

    // AC-14: Keine Mutations-Endpunkte / kein wp_ajax_/register_rest_route/wpdb->update
    public function test_slice_contains_no_mutation_endpoints(): void
    {
        $this->markTestIncomplete('AC-14');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-13-hub-page-skeleton` | `Hub\Controller::dispatch` Section-Routing fuer Slug `webhooks` | static method + Section-Whitelist | Slice 13 Section-Slug-Tabelle listet `webhooks` -> `Hub\View\Webhooks::render` als Routing-Stub-Target. Diese Slice fuellt den Stub. |
| `slice-13-hub-page-skeleton` | `Hub\Controller::ensureCapability(): void` | static method | Wird am Anfang von `Hub\View\Webhooks::render` aufgerufen — geteilter Capability-Gate. |
| `slice-13-hub-page-skeleton` | `Hub\View\Sidebar::render(string $active_slug): void` | static method | Wird als erstes innerhalb von `Hub\View\Webhooks::render` aufgerufen mit `$active_slug='webhooks'`. |
| `slice-16-event-id-hasher` | `Webhook\WebhookLogRepo` als Repository fuer `wp_spreadconnect_webhook_log` | class | `find(int): ?array` aus Slice 16 wird ggf. genutzt; fuer Listen-Queries wird `findFiltered(...)` in dieser Slice ergaenzt (siehe Constraints "Repo-Erweiterung"). |
| `slice-04-schema-dbdelta` | Tabelle + Indexes `idx_received_at`, `idx_processing_status` | DB-Schema | Source of Truth fuer Spalten + Index-Verfuegbarkeit (architecture.md Z. 212-231). |
| `slice-05-options-defaults` | Option `spreadconnect_webhook_log_retention_days` | WP-Option | Wird via `get_option('spreadconnect_webhook_log_retention_days', 90)` gelesen (AC-11). |
| `slice-02-plugin-bootstrap` | Plugin-Konstanten `SPREADCONNECT_POD_FILE`, `SPREADCONNECT_POD_VERSION` | const | Asset-URL + Version-Pin fuer `wp_register_script` (AC-12). |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Hub\View\Webhooks::render` | static method | Slice 13 Section-Routing | `public static function render(): void` |
| `SpreadconnectPod\Webhook\WebhookLogRepo::findFiltered` | static method | (intern) `Hub\View\Webhooks` | `public static function findFiltered(array $filters, int $limit, int $offset): array` — Rows als assoc-array; siehe Constraints "Repo-Erweiterung". |
| `SpreadconnectPod\Webhook\WebhookLogRepo::countFiltered` | static method | (intern) `Hub\View\Webhooks` | `public static function countFiltered(array $filters): int` — Total-Count fuer Pager. |
| Asset `assets/js/webhook-log-expand.js` (Handle `spreadconnect-webhook-log-expand`) | enqueued JS | (intern) `Hub\View\Webhooks` | DOM-only-Toggle, kein public-API. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Webhooks.php` — `final class SpreadconnectPod\Hub\View\Webhooks` mit `public static function render(): void` (Cap-Gate + Sidebar + Filter-Read + Query-Build + Markup), `enqueueAssets(): void` (auf eigenem Section-Hook), private Helpers `buildFilters(array $get): array`, `buildWhereSql(array $filters, array &$args): string`, `renderRow(array $row): string`, `renderDetailRow(array $row): string`, `renderPager(int $page, int $total): string`. Page-Size als `private const PAGE_SIZE = 25`. Read-only — keine AJAX-/REST-/Write-Pfade.
- [ ] `wordpress/plugins/spreadconnect-pod/assets/js/webhook-log-expand.js` — DOM-only-Toggle-Skript: Event-Delegation auf `document` fuer Click auf `.spreadconnect-row-toggle`, toggelt `hidden`-Attribut der zugehoerigen Detail-`<tr>` und `aria-expanded`. Kein `fetch`/`XMLHttpRequest`/`jQuery.ajax`. Vanilla-JS, ES2020, kein Build-Step.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookLogRepo.php` — Erweiterung um `public static function findFiltered(array $filters, int $limit, int $offset): array` und `public static function countFiltered(array $filters): int` (whitelist-validiert + `$wpdb->prepare`-basiert; nutzt dieselbe Spalten-Whitelist wie `Hub\View\Webhooks`-Helper). `find()`/`insertOrIgnore()`/`updateProcessingStatus()` aus Slice 16 bleiben byte-identisch.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-41-webhook-log-ui.php` wird vom Test-Writer-Agent erstellt. Keine Edits an `Bootstrap\Plugin` (Section-Routing existiert bereits via Slice 13, das Asset-Enqueue erfolgt aus der View selbst gegen den eigenen Hook-Suffix). Keine Edits an `Hub\Controller` (Slug `webhooks` ist bereits in der Slice-13-Whitelist).

---

## Constraints

**Scope-Grenzen:**
- **Kein** Retry-/Resend-Button fuer Webhook-Events — Wireframe ⑤ Cell ist visuell vorhanden, aber Re-Verarbeitung von Webhook-Logs ist Slice-37/38-`FailedOpsRepo`-Verantwortlichkeit. Dieser Slice rendert die Cell leer.
- **Keine** Bulk-Actions, **keine** Selection-Checkboxes — read-only Audit-Trail.
- **Kein** Auto-Refresh (Logs-Section in Slice 42 hat das, hier nicht).
- **Kein** CSV-Export (Slice 42).
- **Kein** REST-Endpoint, **kein** AJAX-Handler, **kein** `wp_create_nonce()` — read-only-Section ohne State-Mutation (architecture.md Z. 484-485 explizit: read-only-AJAX braucht Cap-only; hier kein AJAX noetig, da DOM-Toggle reicht).
- **Kein** Volltext-Search ueber `payload` — nicht in Wireframe, nicht im Index, nicht in Done-Signal.
- **Kein** Inline-Edit/Annotation auf Rows.
- **Keine** Subscription-/HMAC-Repair-Aktionen — Slice 14/19.
- **Keine** Aenderungen an `WebhookController` / `EventIdHasher` (Slice 15/16).

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in `Webhooks.php`.
- `Hub\View\Webhooks` als `final class` mit ausschliesslich `static` Methoden — Adapter-Pattern (Slice 13 Konvention).
- Section-Hook-Suffix-Lookup fuer `wp_enqueue_script`: das Asset-Enqueue MUSS nur dann feuern, wenn `?page=spreadconnect&section=webhooks` aktiv ist — Implementer entscheidet zwischen (a) Hook `admin_enqueue_scripts` mit `$hook_suffix` + `$_GET['section']`-Check, oder (b) direktem `wp_enqueue_script`-Call innerhalb `render()` (legitim ab WP 5.0). AC-12 testet das Resultat (kein global enqueue), nicht den Pfad.
- **`$wpdb->prepare()` PFLICHT** fuer ALLE Spaltenwerte — auch wenn Whitelist-validiert (Defense-in-Depth, Plugin-Konvention Slice 16).
- **Keine** dynamischen Spalten-/Tabellennamen via Variable-Interpolation — Tabellenname als `"{$wpdb->prefix}spreadconnect_webhook_log"` aus Plugin-Konstante; Spaltennamen als Literale im Builder-Helper.
- **Page-Size als Konstante** `private const PAGE_SIZE = 25` — kein Magic-Number; potentiell spaeter via Setting oder Filter-Hook anpassbar.
- **`wp_date()` (NICHT `date()`)** fuer Datums-Anzeige — respektiert WP-Timezone-Setting.
- **`wp_json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)`** fuer Payload-Pretty-Print (architecture.md Z. 282 Konvention).
- **Asset-Version-Pin** ueber Plugin-Konstante (Slice 02) — Cache-Bust automatisch bei Plugin-Update.
- **JS in Vanilla-ES2020** — keine Build-Pipeline, keine Polyfills, keine externen Deps. WordPress 6.0+ Browser-Matrix erlaubt das.

**Repo-Erweiterung (kritisch fuer Slice-Boundary-Compliance):**
- Slice 16 stellt `find(int): ?array` bereit, NICHT `findFiltered`/`countFiltered`. Diese Slice fuegt beide Methoden zu `WebhookLogRepo` hinzu (Edit-Deliverable). Begruendung: das Repository-Pattern (architecture.md Z. 537) gehoert dem Webhook-Modul; ein UI-Slice darf keinen direkten `$wpdb`-Zugriff auf Domain-Tabellen umgehen, sondern muss durchs Repo. Der Edit beruehrt nur additive `static` Methoden — keine bestehende API wird geaendert.
- `findFiltered`/`countFiltered` validieren ihre Filter-Argumente eigenstaendig erneut (Defense-in-Depth) und erlauben nur die in AC-3 genannten Whitelist-Werte; unbekannte Werte werden silently ignoriert. Spalten-Whitelist als `private const FILTER_COLUMNS = ['event_type', 'received_at', 'hmac_status', 'processing_status']`.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Hub/Controller.php` (Slice 13) | **Import** — `ensureCapability()` wird aufgerufen; `dispatch()` routet zu uns. Klasse selbst NICHT modifiziert. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Sidebar.php` (Slice 13) | **Import** — `Sidebar::render('webhooks')` wird als erstes innerhalb `Webhooks::render` aufgerufen. NICHT modifiziert. |
| `wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookLogRepo.php` (Slice 16) | **Edit (additiv)** — `findFiltered` + `countFiltered` werden hinzugefuegt. `find`/`insertOrIgnore`/`updateProcessingStatus` aus Slice 16 bleiben byte-identisch (siehe Constraints "Repo-Erweiterung"). |
| Tabelle `wp_spreadconnect_webhook_log` + Indexes `idx_received_at`, `idx_processing_status` (Slice 04) | **Reuse** — Source of Truth fuer Spaltenset und Index-Verfuegbarkeit; AC-4 referenziert architecture.md Z. 229-231. |
| Option `spreadconnect_webhook_log_retention_days` (Slice 05) | **Reuse** — read-only via `get_option(..., 90)` (Default-Fallback gegen fehlende Initialisierung). |
| Plugin-Konstanten `SPREADCONNECT_POD_FILE`, `SPREADCONNECT_POD_VERSION` (Slice 02) | **Reuse** — Asset-URL + Version-Pin in `wp_register_script`. |

**Referenzen:**
- Architecture: `architecture.md` -> Database Schema `wp_spreadconnect_webhook_log` (Z. 212-231); Indexes (Z. 228-231); HMAC-Invalid-Logging-Constraint (Z. 493); Retention-Setting (Z. 340); Repository-Pattern (Z. 537); Cap+Nonce-Konvention fuer Read-only (Z. 484-485); 7 Webhook-Events Liste (Z. 41); `WebhookEvent`-Schema (Z. 175).
- Wireframes: `wireframes.md` -> Screen 4 "Webhook Event Log" (Z. 322-394) — komplette Layout-Vorgabe inkl. ① Filter-Bar, ② Retention-Hint, ③ Event-Table, ④ HMAC-/Proc-Glyphs, ⑤ Expand-Chevron, ⑥ Detail-Row mit JSON + Processing-Log; State-Variations Z. 386-393.
- Discovery: `discovery.md` -> Slice 10 "Logs + Webhook-Log + Polish" (Z. 931): "Webhook-Log-UI mit Expand-Detail".
- Slim-Slices: `slices/slim-slices.md` -> Slice-41-Eintrag (Z. 605-612; Done-Signal: "Filter funktioniert, Expand zeigt JSON. PHPUnit Filter-Query nutzt korrekte Indexes").
- Vorgaenger: `slices/slice-13-hub-page-skeleton.md` -> Section-Slug-Tabelle (Webhooks-Slug + Routing-Stub-Strategie); `Hub\Controller::ensureCapability` + `Hub\View\Sidebar::render` als Provides.
- Vorgaenger: `slices/slice-16-event-id-hasher.md` -> `WebhookLogRepo::find`/`updateProcessingStatus` als Provides; Spalten-Layout der `payload`-Spalte (re-encoded JSON via `wp_json_encode`).
- Folge: `slices/slim-slices.md` Slice-43 (Purge-Old-Logs-Job konsumiert dieselbe Retention-Setting); Slice-42 (Logs-UI mit `WC_Logger`-Adapter — analoges Read-only-Pattern).
