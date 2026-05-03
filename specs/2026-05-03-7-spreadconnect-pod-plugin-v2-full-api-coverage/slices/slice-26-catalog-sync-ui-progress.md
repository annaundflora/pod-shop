# Slice 26: Catalog-Sync-UI + Live-Progress-AJAX

> **Slice 26 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-26-catalog-sync-ui-progress` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-13-hub-page-skeleton", "slice-24-sync-catalog-job"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `register_rest_route`, `as_enqueue_async_action`, `check_ajax_referer`, `current_user_can`, `wp_send_json_*`, `get_transient`; `$wpdb`-Stub fuer `SyncHistoryRepo`-Reads) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: `WooCommerce -> Spreadconnect -> Catalog -> Sync now` triggert Sync; Progress-Bar pollt alle 3s) |
| **Health Endpoint** | `GET /wp-json/spreadconnect/v1/sync-progress?run_id=<id>` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer WP REST-API + AJAX-API + Action-Scheduler-Funktionen; `SyncHistoryRepo` Konstruktor-injiziert via Mockery) |

---

## Ziel

Liefert die Catalog-Sub-Page der Hub-Verwaltung als Operator-Surface fuer Discovery-Slice 4: ein "Jetzt synchronisieren"-Button (AJAX `spreadconnect_sync_now` enqueued `spreadconnect/sync_catalog` aus Slice 24), eine Live-Progress-Bar die alle 3s die neue REST-Route `/wp-json/spreadconnect/v1/sync-progress` pollt und die Sync-History-Tabelle aus `wp_spreadconnect_sync_history`. Damit ist die in Slice 13 als Routing-Stub angelegte `Hub\View\Catalog`-Klasse fachlich befuellt und die in Slice 24 angelegten Counter werden fuer das Operator-Auge sichtbar.

---

## Acceptance Criteria

1) **GIVEN** `?page=spreadconnect&section=catalog` wird mit `manage_woocommerce`-Capability aufgerufen
   **WHEN** `Hub\View\Catalog::render()` ausgefuehrt wird
   **THEN** wird Markup gemaess wireframes.md `Screen 2` erzeugt: "Sync now"-Button (Annotation ①), "During Sync"-Panel-Container (③, initial leer/hidden), Progress-Bar-Slot (④), Counter-Slot (⑤), Live-Log-Tail-Slot (⑥) und Sync-History-Tabelle (⑦). Alle User-Strings via `__()` mit Domain `'spreadconnect-pod'`; alle Outputs via `esc_html`/`esc_attr`/`esc_url` escaped.

2) **GIVEN** `Hub\View\Catalog::render()` rendert die Sync-History-Tabelle
   **WHEN** `wp_spreadconnect_sync_history` enthaelt 5 Rows
   **THEN** zeigt die Tabelle die Rows DESC by `started_at` mit Spalten `Started`, `Duration` (`finished_at - started_at`), `Trigger`, `Created`, `Updated`, `Skipped`, `Errors`, `State` (genau Reihenfolge laut Wireframe `Screen 2` ⑦). Empty-State (0 Rows) zeigt lokalisierten Hinweis aus State-Variation `no_history_yet` (siehe wireframes.md `Screen 2 -> State Variations`). KEIN per-Row-Expand in dieser Slice (⑧ ist Out-of-Scope).

3) **GIVEN** `Hub\View\Catalog::render()` schreibt den Progress-Bar-Container ins Markup
   **WHEN** das Inline-Script die `run_id` der aktuell laufenden History-Row braucht
   **THEN** wird die `run_id` der juengsten Row mit `state IN ('in_progress', 'pending')` als `data-run-id`-Attribut (oder via `wp_localize_script`) verfuegbar gemacht. Existiert **keine** aktive Row, ist `data-run-id=""` und das Polling startet erst nach erfolgreichem `spreadconnect_sync_now`-Response (siehe AC-7).

4) **GIVEN** ein Admin-User mit `manage_woocommerce`-Capability + gueltiger Nonce postet `action=spreadconnect_sync_now` an `admin-ajax.php`
   **WHEN** `Hub\Ajax\SyncNow::handle()` ausgefuehrt wird
   **THEN** wird **genau einmal** `as_enqueue_async_action('spreadconnect/sync_catalog', ['trigger'=>'manual'], 'spreadconnect')` aufgerufen, und die Response ist `wp_send_json_success(['run_id' => int|null, 'state' => 'queued'])`. Der `run_id` darf bei dieser Slice `null` sein (Job hat die History-Row noch nicht angelegt — die ist Slice-24-AC-1 erst beim Worker-Tick). Der Frontend-Poller (AC-8) faellt dann auf `?run_id=` (default = active run) zurueck.

5) **GIVEN** `Hub\Ajax\SyncNow::handle()` wird ohne gueltige Nonce oder ohne `manage_woocommerce` aufgerufen
   **WHEN** der Handler die Pre-Checks evaluiert
   **THEN** terminiert die Request via `wp_send_json_error([...], 403)` (oder `wp_die`) **bevor** `as_enqueue_async_action` aufgerufen wird. Nonce-Action: exakt `spreadconnect_sync_now` (analog Slice-12-Naming). Capability-Check via `Hub\Controller::ensureCapability()` (Slice 13 Provides-To).

6) **GIVEN** Plugin-Bootstrap initialisiert die REST-Routen
   **WHEN** der Hook `rest_api_init` feuert
   **THEN** wird `register_rest_route('spreadconnect/v1', '/sync-progress', [...])` mit `methods='GET'`, `callback=[Hub\Rest\SyncProgress::class, 'handle']` und `permission_callback=[Hub\Rest\SyncProgress::class, 'permission']` aufgerufen. Die `permission`-Methode prueft `current_user_can('manage_woocommerce')` (Architecture Z. 132). Query-Param: optional `run_id` (int).

7) **GIVEN** `GET /wp-json/spreadconnect/v1/sync-progress?run_id=42` mit gueltiger Capability, und in `wp_spreadconnect_sync_history` existiert Row `id=42` mit `state='in_progress'`, `created_count=12`, `updated_count=68`, `skipped_count=3`, `error_count=1`, `started_at='2026-05-03 14:30:00'`, `trigger='manual'`
   **WHEN** `Hub\Rest\SyncProgress::handle($request)` ausgefuehrt wird
   **THEN** wird `WP_REST_Response` mit JSON-Body laut Architecture Z. 132 zurueckgegeben: exakt die Keys `{run_id:int, state:string, started_at:string, processed:int, total:int, created:int, updated:int, skipped:int, errors:int, last_log_lines:string[]}`. `processed = created+updated+skipped+errors`. `total` aus Transient `sc_sync_total_{run_id}` (Slice-24-AC-3); fallback `0` wenn Transient fehlt. `last_log_lines` aus Transient `sc_sync_log_tail_{run_id}` (Architecture Z. 352); fallback `[]`. HTTP-Status `200`.

8) **GIVEN** `GET /wp-json/spreadconnect/v1/sync-progress` **ohne** `run_id`-Query-Param
   **WHEN** der Handler `?run_id=` (default = active run, Architecture Z. 132) aufloest
   **THEN** liest `SyncHistoryRepo` die juengste Row mit `state IN ('in_progress', 'pending')` (`ORDER BY id DESC LIMIT 1`). Existiert keine aktive Row, returnt der Endpunkt die juengste abgeschlossene Row. Existiert ueberhaupt keine Row, returnt der Endpunkt `WP_REST_Response` mit `{run_id: null, state: 'idle', processed: 0, total: 0, ...}` und HTTP-Status `200` (kein 404, damit der Poller nicht haengt).

9) **GIVEN** `GET /wp-json/spreadconnect/v1/sync-progress?run_id=99999` und Row `id=99999` existiert nicht
   **WHEN** `handle()` keine Row findet
   **THEN** returnt der Endpunkt HTTP-Status `404` mit JSON-Body `{code:'sync_run_not_found', message:string, data:{status:404}}` (`WP_Error`-Convention) — **NICHT** Fallback auf Default-Run, da der Client einen spezifischen Run angefragt hat.

10) **GIVEN** `Hub\Rest\SyncProgress::permission($request)` wird ohne `manage_woocommerce`-Capability aufgerufen
    **WHEN** WP die `permission_callback` evaluiert
    **THEN** returnt die Methode `false` (oder `WP_Error` mit `status=401`); der `handle()`-Callback wird nicht ausgefuehrt. **Kein** Nonce-Check (REST-Reads sind capability-gated, nicht nonce-gated; siehe Architecture Z. 484 "Read-only AJAX (`sync-progress`, `health`) requires capability only (no nonce)").

11) **GIVEN** AC-1 Markup ist gerendert und das Inline-Script ist aktiv
    **WHEN** der Browser den Polling-Loop startet
    **THEN** ist die Polling-Frequenz exakt `3000` ms (Architecture Z. 517: "3 s client-poll"). Nach REST-Response mit `state IN ('complete','failed','canceled')` stoppt das Polling und das "During Sync"-Panel wechselt in den State gemaess wireframes.md `Screen 2 -> State Variations`. **JS-Verhalten ist nicht PHPUnit-getestet** — Verifikation erfolgt manuell als QA-Schritt im Done-Signal.

12) **GIVEN** Plugin-Bootstrap aus Slice 02/13/24 ist initialisiert
    **WHEN** der Bootstrap-Hook-Pipeline laeuft
    **THEN** sind drei Mount-Points aktiv: (a) `add_action('rest_api_init', [SyncProgress::class, 'register'])` (b) `add_action('wp_ajax_spreadconnect_sync_now', [SyncNow::class, 'handle'])` (c) `Hub\Controller::SECTIONS`-Whitelist routet `?section=catalog` zur **realen** `Hub\View\Catalog`-Klasse (ersetzt den Slice-13-Stub). Ohne Mount-Points sind weder REST-Route noch AJAX-Action erreichbar.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey-Mocks fuer `register_rest_route`, `as_enqueue_async_action`, `check_ajax_referer`, `current_user_can`, `wp_send_json_success`, `wp_send_json_error`, `get_transient`, `__`, `esc_html`, `esc_attr`, `esc_url`, `current_time`, `admin_url`. `SyncHistoryRepo` als Konstruktor-injiziertes Mockery-Mock. Patchwork-Replace fuer `Hub\Controller::ensureCapability` (statisch). JS-AC-11 ist nicht unit-getestet.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-04-catalog-sync-ui.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests\Hub;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class CatalogSyncUiTest extends TestCase
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

    // AC-1: Catalog::render erzeugt Sync-Now-Button + Progress-Slot + History-Slot mit lokalisierten Strings
    public function test_catalog_render_emits_sync_button_progress_slot_and_history_table(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: History-Tabelle zeigt Rows DESC by started_at mit korrekten Spalten
    public function test_catalog_render_history_table_columns_match_wireframe_order(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Empty-State bei 0 History-Rows
    public function test_catalog_render_history_empty_state_when_no_rows(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Aktive run_id wird ins Markup als data-run-id geschrieben
    public function test_catalog_render_exposes_active_run_id_to_frontend(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: SyncNow::handle ruft as_enqueue_async_action mit korrektem Hook+Args+Group
    public function test_sync_now_handle_enqueues_sync_catalog_with_manual_trigger(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: Response-Format ist wp_send_json_success({run_id, state})
    public function test_sync_now_handle_response_shape_matches_contract(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Ohne Cap/Nonce -> wp_send_json_error 403, kein Enqueue
    public function test_sync_now_handle_aborts_without_capability_or_nonce(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: register_rest_route wird mit korrektem namespace+route+methods+callbacks aufgerufen
    public function test_sync_progress_registers_rest_route_with_capability_callback(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: handle returnt JSON-Body mit allen 10 Architecture-Keys
    public function test_sync_progress_handle_returns_full_progress_body(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: processed = created+updated+skipped+errors
    public function test_sync_progress_handle_processed_equals_counter_sum(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: total wird aus sc_sync_total_{run_id} Transient gelesen
    public function test_sync_progress_handle_reads_total_from_transient(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: last_log_lines aus sc_sync_log_tail_{run_id} Transient
    public function test_sync_progress_handle_reads_log_tail_from_transient(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Ohne run_id-Param -> juengste aktive Row wird zurueckgegeben
    public function test_sync_progress_handle_resolves_default_to_active_run(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: Keine aktive UND keine abgeschlossene Row -> idle-Default-Body 200
    public function test_sync_progress_handle_returns_idle_when_no_history(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Spezifischer run_id existiert nicht -> 404 sync_run_not_found
    public function test_sync_progress_handle_returns_404_for_missing_specific_run(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: permission_callback returnt false ohne manage_woocommerce
    public function test_sync_progress_permission_returns_false_without_capability(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-12: Bootstrap registriert REST-Init- und AJAX-Hooks
    public function test_bootstrap_registers_rest_and_ajax_hooks(): void
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
| `slice-13-hub-page-skeleton` | `Hub\Controller::SECTIONS`-Whitelist mit `'catalog' => Hub\View\Catalog::class` | const map | Slice-13-Routing dispatched `?section=catalog` zu dieser View; Slice 26 ersetzt den Slice-13-Stub durch die reale Klasse (siehe Constraints "Stub-Replacement"). |
| `slice-13-hub-page-skeleton` | `Hub\Controller::ensureCapability(): void` | static method | AJAX-Handler nutzt den Helper fuer Cap-Gate (AC-5). |
| `slice-13-hub-page-skeleton` | `Hub\View\Sidebar::render(string $active_slug): void` | static method | `Catalog::render` ruft den Sidebar-Helper am Anfang auf (Layout-Wrapper analog zu Slice 11/19 etc.). |
| `slice-24-sync-catalog-job` | `Catalog\SyncHistoryRepo` mit `getById(int): ?array`, `getActiveRun(): ?array`, `getRecent(int $limit): array`, `setTotal/getTotal` (Transient-Wrapper) | Class + Methods | Slice 24 hat Counter-Methoden + Total-Persistence; Slice 26 ergaenzt Read-Methoden im selben Repo (siehe Deliverables "Edit"). |
| `slice-24-sync-catalog-job` | Action-Hook `spreadconnect/sync_catalog` registriert | Hook-Registration | Slice-24-AC-9 garantiert die Hook-Registration; Slice 26 enqueued via `as_enqueue_async_action`. |
| `slice-24-sync-catalog-job` | Transient-Konvention `sc_sync_total_{run_id}` | Transient-Key | Slice-24-AC-3 schreibt diesen Key; Slice 26 liest ihn (AC-7). |
| `slice-23-sync-article-job` | Transient-Konvention `sc_sync_log_tail_{run_id}` (laut Architecture Z. 352, in-Memory-Ring der letzten 20 Log-Lines) | Transient-Key | Slice 23 (oder spaetere Edit-Slice) schreibt; Slice 26 liest. **Hinweis fuer Implementer:** Falls der Transient zur Slice-26-Implementation noch nicht geschrieben wird (Slice 23 hat ihn als optional dokumentiert), liefert AC-7 einfach `[]` zurueck — **kein** Fail-Modus. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Hub\View\Catalog::render` | static method | Hub-Routing (`Hub\Controller::dispatch` -> `?section=catalog`) | `public static function render(): void` |
| `SpreadconnectPod\Hub\Ajax\SyncNow::handle` | static method | WP `wp_ajax_spreadconnect_sync_now`-Hook; Hub-Dashboard Sync-Now-Button (Architecture Z. 142) | `public static function handle(): void` (terminiert via `wp_send_json_*`) |
| `SpreadconnectPod\Hub\Ajax\SyncNow::register` | static method | `Bootstrap\Plugin::init()` | `public static function register(): void` |
| `SpreadconnectPod\Hub\Rest\SyncProgress::handle` | method | WP REST-Stack ueber `register_rest_route`-Callback; Frontend-Poller (3s) | `public function handle(\WP_REST_Request $request): \WP_REST_Response\|\WP_Error` |
| `SpreadconnectPod\Hub\Rest\SyncProgress::permission` | method | WP REST-Stack ueber `permission_callback` | `public function permission(\WP_REST_Request $request): bool` |
| `SpreadconnectPod\Hub\Rest\SyncProgress::register` | static method | `Bootstrap\Plugin::init()` (auf `rest_api_init`) | `public static function register(): void` |
| REST-Route `/wp-json/spreadconnect/v1/sync-progress` | WP-REST-Endpoint | Slice-13-Hub-Header (zukuenftige Health-Card-Refresh — Architecture Z. 133); Slice-46-Dashboard-Aggregat | JSON-Response-Shape gemaess Architecture Z. 132 (10 Keys) |
| AJAX-Action `spreadconnect_sync_now` | WP admin-ajax.php action | Hub-Dashboard Cards (Slice 13 Card "Catalog" optional Inline-Trigger spaeter); Catalog-Page-Button | `POST admin-ajax.php` mit `action=spreadconnect_sync_now&_ajax_nonce=<token>` -> JSON `{success:bool, data:{run_id:int\|null, state:string}}` |

> **Mount-Point-Check:** Drei neue Komponenten (`Hub\View\Catalog`, `Hub\Rest\SyncProgress`, `Hub\Ajax\SyncNow`) — alle benoetigen Mount-Edits in `Bootstrap\Plugin.php`. Ohne diese Edits ist die UI tot. Siehe Deliverables.

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Catalog.php` — `final class SpreadconnectPod\Hub\View\Catalog` mit `public static function render(): void`. Erzeugt Markup gemaess wireframes.md `Screen 2` (Sync-Now-Button, During-Sync-Panel-Slot, Progress-Bar-Slot, Counter-Slot, Live-Log-Tail-Slot, History-Tabelle). Liest aktuelle/recent History-Rows via `SyncHistoryRepo::getActiveRun()` + `getRecent(int $limit)`. Druckt Inline-Script (oder enqueued via `wp_enqueue_script` + `wp_localize_script`), das den 3s-Poll auf `/wp-json/spreadconnect/v1/sync-progress` und den Klick-Handler fuer den Sync-Now-Button verdrahtet.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/Rest/SyncProgress.php` — `final class SpreadconnectPod\Hub\Rest\SyncProgress` mit `public static function register(): void` (haengt sich an `rest_api_init`), `public function permission(\WP_REST_Request $request): bool` (Cap-Check `manage_woocommerce`), `public function handle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error` (siehe AC-7/8/9). Konstruktor-DI: `SyncHistoryRepo`. Konstante `ROUTE_NAMESPACE = 'spreadconnect/v1'`, `ROUTE_PATH = '/sync-progress'`.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/SyncNow.php` — `final class SpreadconnectPod\Hub\Ajax\SyncNow` mit `public static function register(): void` (`add_action('wp_ajax_spreadconnect_sync_now', [self::class, 'handle'])`), `public static function handle(): void` (Cap+Nonce-Check, `as_enqueue_async_action('spreadconnect/sync_catalog', ['trigger'=>'manual'], 'spreadconnect')`, `wp_send_json_success(['run_id' => ?int, 'state' => 'queued'])`). Nonce-Action-String: exakt `spreadconnect_sync_now`.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncHistoryRepo.php` — Ergaenzt **Read**-Methoden zur in Slice 24 erweiterten Klasse: `public function getById(int $runId): ?array`, `public function getActiveRun(): ?array` (juengste Row mit `state IN ('in_progress','pending')`), `public function getRecent(int $limit = 20): array` (DESC by `started_at`), `public function getTotal(int $runId): int` (Wrapper um `get_transient('sc_sync_total_'.$runId)`, fallback `0`), `public function getLogTail(int $runId): array` (Wrapper um `get_transient('sc_sync_log_tail_'.$runId)`, fallback `[]`). Bestehende Slice-23/24-Methoden bleiben unveraendert.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Drei Mount-Edits: (a) `add_action('rest_api_init', [Hub\Rest\SyncProgress::class, 'register'])`, (b) `Hub\Ajax\SyncNow::register()` aufrufen (analog Slice-12-Pattern), (c) `Hub\Controller::SECTIONS`-Whitelist verifiziert `'catalog' => Hub\View\Catalog::class` (von Slice 13 bereits vorgemerkt; falls nicht, hier patchen). Bestehende Hook-Registrierungen aus Slice 02/06/13/14/15/17/23/24 bleiben unveraendert.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-04-catalog-sync-ui.php` wird vom Test-Writer-Agent erstellt. Falls das Inline-Script in eine eigene `assets/js/catalog-sync-progress.js` ausgelagert wird, ist das eine Implementer-Wahl und KEIN zusaetzliches Deliverable.

---

## Constraints

**Scope-Grenzen:**
- KEIN `spreadconnect_cancel_sync`-AJAX (Architecture Z. 143) — Cancel-Button ist Out-of-Scope; das Markup darf den Button-Slot ⑨ als sichtbaren Stub-Container vorbereiten, aber **kein** AJAX-Handler in dieser Slice.
- KEIN Per-Article-Expand der History-Rows (wireframes.md `Screen 2 ⑧`) — Out-of-Scope-MVP-Optimierung; History-Tabelle zeigt nur die Aggregat-Counter.
- KEINE Aenderung an Slice-24-`SyncCatalogJob` oder Slice-23-`SyncArticleJob`. Slice 26 ist read-only gegenueber den Job-Klassen.
- KEINE Aenderung an Slice-24-Counter-Atomic-Logic (`incrementCreated/Updated/Skipped/Error`) — Slice 26 nutzt sie ausschliesslich als bestehende Methoden.
- KEIN `sc_health`-Transient-Update — Slice 13 / Slice 12 sind dafuer zustaendig.
- KEIN Initial-Sync-Schedule beim Plugin-Activate — Slice 24 oder spaeter.
- KEIN Schreiben in den `sc_sync_log_tail_{run_id}`-Transient — der Producer ist `SyncArticleJob` (Slice 23 / nachgereicht). Slice 26 ist Read-Only fuer diesen Transient.
- KEINE i18n-Po-Aktualisierung — Slice 46.
- KEINE CSS-Datei — Markup nutzt WP-Admin-Default-Klassen + Slice-13-Sidebar-CSS-Hooks.
- KEINE Pagination der History-Tabelle — `getRecent(20)` reicht fuer MVP (Architecture-Retention via Slice 43 entfernt alte Rows).

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` in allen 3 neuen Dateien.
- `Hub\View\Catalog` als `final class` mit ausschliesslich `static`-Methoden (analog Slice-13-Pattern fuer alle View-Klassen).
- `Hub\Ajax\SyncNow` als `final class` mit ausschliesslich `static`-Methoden (analog Slice-12-`TestConnection`-Pattern).
- `Hub\Rest\SyncProgress` als `final class` — `register()` ist `static` (Hook-Registration), `permission()` und `handle()` sind **Instanz-Methoden** mit Konstruktor-DI fuer `SyncHistoryRepo` (Mockability). `register()` instantiiert intern und uebergibt die Methode-Referenzen an `register_rest_route`.
- Nonce-Action-Name: **exakt** `spreadconnect_sync_now` (`wp_create_nonce` im Catalog-Markup und `check_ajax_referer` im Handler muessen denselben String nutzen).
- Capability-String: `'manage_woocommerce'` (konsistent mit Slice 11/12/13/24); im AJAX-Handler via `Hub\Controller::ensureCapability()`, im REST-Handler inline `current_user_can('manage_woocommerce')`.
- REST-Namespace: **exakt** `'spreadconnect/v1'` (Architecture Z. 132 + Slice-15-Webhook-Route teilen denselben Namespace).
- REST-Route: **exakt** `'/sync-progress'`.
- REST-Method: **GET only** (kein `methods => 'GET, POST'`).
- Response-Body-Shape (AC-7): exakt 10 Keys laut Architecture Z. 132. KEINE zusaetzlichen Keys (kein API-Drift).
- HTTP-Status-Codes: 200 fuer alle Success-Cases inkl. `idle`-Default (AC-8); 401 nur via `permission_callback` (AC-10); 404 fuer "spezifischer run_id existiert nicht" (AC-9).
- `wp_send_json_*` — niemals nackte `echo`/`die`. WP setzt Content-Type-Header und beendet die Response korrekt.
- AS-Group-Param: `'spreadconnect'` (3. Argument zu `as_enqueue_async_action`, konsistent mit Slice-24-AC-2).
- `data-run-id`-Attribut bevorzugt vor `wp_localize_script` (weniger globale JS-Vars, leichter zu testen). Implementer-Wahl, beides AC-3-konform.
- Polling-Interval **3000ms** als Konstante im Inline-Script (`POLL_INTERVAL_MS=3000`); **niemals** dynamisch oder als Server-Option.
- KEIN `try/finally`-State-Reset in Handlern — alle drei Klassen sind stateless.

**Stub-Replacement (Slice-13-Anschluss):**
- Slice 13 hat `Hub\View\Catalog` entweder als minimale leere Stub-Klasse oder gar nicht angelegt (siehe Slice-13-Constraint "Stub-Klassen-Strategie"). Slice 26 erstellt die **reale** Klasse mit identischer FQCN `SpreadconnectPod\Hub\View\Catalog`. Die Slice-13-Stub-Datei (falls vorhanden) wird durch das Slice-26-Deliverable ersetzt — der Implementer **ueberschreibt** die Stub-Datei; KEIN paralleles File.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Hub/Controller.php` (Slice 13) | **Import**, NICHT modifizieren — `Hub\Controller::ensureCapability()` wird vom AJAX-Handler aufgerufen. Section-Whitelist routet `?section=catalog` bereits zu `Hub\View\Catalog::class` (Slice-13-Provides-To). |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Sidebar.php` (Slice 13) | **Import**, NICHT modifizieren — `Catalog::render` ruft `Sidebar::render('catalog')` als Layout-Wrapper. |
| `wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncHistoryRepo.php` (Slice 23/24) | **Edit-Target**: ergaenzt Read-Methoden (`getById`, `getActiveRun`, `getRecent`, `getTotal`, `getLogTail`). Slice-23/24-Methoden bleiben unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02/13/14/15/17/23/24) | **Edit-Target**: drei Mount-Edits (REST-Init-Hook, AJAX-Register-Call, optional Catalog-Whitelist-Verifikation). Bestehende Hook-Registrierungen unveraendert. |
| `wordpress/plugins/spreadconnect-pod/composer.json` (Slice 02) | PSR-4-Mapping `SpreadconnectPod\\` -> `includes/`. Unveraendert. |

**Referenzen:**
- Architecture: `architecture.md` -> "Inbound: WP REST Routes" Z. 132 (`/sync-progress`-Vertrag mit Response-Shape); "Internal Admin AJAX" Z. 142 (`spreadconnect_sync_now`); "Read-only AJAX requires capability only (no nonce)" Z. 484; Cache-Tabelle Z. 352 (`sc_sync_log_tail_{run_id}`); Polling-Frequenz Z. 517 (3s); Adapter-REST Z. 528.
- Wireframes: `wireframes.md` -> `Screen 2: Catalog Sync (Hub Sub-Page)` Z. 174-261 (Layout, Annotationen ①-⑨, State-Variations) — referenzieren, **nicht** kopieren.
- Discovery: `discovery.md` -> Slice 4 Z. 925 (Hub-Page Catalog-Sync + Live-Progress AJAX); UI-Element `sync_now_button` Z. 431; Live-Progress-Pattern Z. 114.
- Slim-Slices: `slices/slim-slices.md` -> Slice-26-Eintrag Z. 430-438 (Done-Signal: Klick triggered Sync; Progress-Bar updated alle 3s; PHPUnit REST-Route liefert `{run_id, processed, total, ...}`).
- Vorgaenger: `slices/slice-13-hub-page-skeleton.md` -> Section-Whitelist + `ensureCapability` + `Sidebar::render`.
- Vorgaenger: `slices/slice-24-sync-catalog-job.md` -> AC-1 (History-Row-Insert), AC-3 (Total-Transient), AC-7 (Counter-Atomicity), Provides-To `SyncHistoryRepo` + Action-Hook `spreadconnect/sync_catalog`.
