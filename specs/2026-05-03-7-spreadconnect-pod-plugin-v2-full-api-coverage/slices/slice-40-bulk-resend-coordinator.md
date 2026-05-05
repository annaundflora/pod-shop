# Slice 40: Bulk-Resend-Coordinator (Outcome-Panel) — Verbindet Coordinator mit FailedOpsRepo

> **Slice 40 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-40-bulk-resend-coordinator` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-38-failed-ops-ui", "slice-33-order-list-columns-bulk"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WC 10.5 HPOS + Action Scheduler + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: `/wp-admin/admin.php?page=spreadconnect&section=failed` Bulk-UI; `/wp-admin/admin.php?page=wc-orders` Bulk-Resend) |
| **Health Endpoint** | `n/a` (Coordinator + Bulk-AJAX-Handler, kein eigener REST-Endpoint) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `check_ajax_referer`, `current_user_can`, `wp_send_json_*`, `as_enqueue_async_action`, `wc_get_order`, `wp_generate_uuid4`, `add_query_arg`; `Failure\FailedOpsRepo` per Mockery-Mock injiziert; `Inline\OrderListColumns` Static-Bridges via `Functions\when` ueber Closure-Invocation) |

---

## Ziel

Verbindet die Slice-33-Stub-Methode `BulkResendCoordinator::run()` (deferred markers) mit dem in Slice 37 gelieferten `FailedOpsRepo` und liefert die echte Per-Row-Outcome-Logik fuer beide Bulk-Surfaces: (a) Order-List `?action=spreadconnect_bulk_resend` (Slice 33) und (b) Failed-Ops-UI Bulk-Resend / Bulk-Dismiss (Slice 38). Bulk-Dismiss-Aufrufe mit `create_order`-Eintraegen in der Selection werden Server- **und** UI-seitig refused (per-Op-Type-Regel aus Discovery Z. 640).

---

## Bulk-Lifecycle (Single Source of Truth fuer diese Slice)

| Phase | Endpoint / Hook | Verantwortlich | Verhalten in Slice 40 |
|---|---|---|---|
| Pre-Flight (Order-List) | `spreadconnect_bulk_resend_preflight` (existiert seit Slice 33) | `BulkResendCoordinator::preflight(int[]): array` | **Erweitert um `failed_op_lookup`**: liest fuer jede ID auch `FailedOpsRepo::findByEntity('order', (string)$id, 'unresolved')` und liefert `failed_op_id` in `eligible_ids_with_op_id` (siehe AC-1). Slice 33 Vertrag (`will_resend`/`will_skip`/`eligible_ids`/`skipped`) bleibt rueckwaertskompatibel. |
| Run (Order-List Bulk-Apply) | WC-Filter `handle_bulk_actions-(...)` -> `OrderListColumns::handleBulkAction()` | `BulkResendCoordinator::run(int[]): array` | **Body ersetzt:** Per eligible Order-ID: `as_enqueue_async_action('spreadconnect/create_order', ['order_id'=>$id], 'spreadconnect')`; `per_row[$id]='requeued'` fuer eligible, `per_row[$id]=$skipReason` fuer ineligible. `queued`-Counter zaehlt nur Eligible-Schedules. Liefert `run_id` (UUID). KEIN `markResolved` — die `failed_ops`-Row wird erst durch das erfolgreiche `OrderSubmitJob`-Re-Run aufgeloest (Slice 28 schreibt bei 2xx den State `NEW`). |
| Bulk-Resend (Failed-Ops-UI) | AJAX `spreadconnect_bulk_resend_failed_op` (NEU) | `BulkResendCoordinator::resendFailedOps(int[] $failed_op_ids): array` | Pro Row: `findById($id)`, Op-Type-zu-Hook-Mapping (Slice 38 Konstante), `as_enqueue_async_action(...)`, `markResolved($id)`. Liefert `array{queued:int, skipped:int, run_id:string, per_row:array<int,string>}`. |
| Bulk-Dismiss (Failed-Ops-UI) | AJAX `spreadconnect_bulk_dismiss_failed_op` (NEU) | `BulkResendCoordinator::dismissFailedOps(int[] $failed_op_ids): array` | **Per-Op-Type-Regel:** wenn `findById($id)['op_type']==='create_order'` fuer **mindestens eine** ID -> Abort, Response `{ok:false, code:'create_order_in_selection', blocked_ids:int[]}`. Sonst: pro Row `markDismissed`. Liefert `array{dismissed:int, blocked_ids:int[]}`. |
| Outcome-Banner (Order-List) | `admin_notices` -> `OrderListColumns::renderOutcomePanel()` (Slice 33 AC-14) | unveraendert | Slice 40 nutzt das in Slice 33 bereits existierende Markup. KEIN neuer Outcome-Hook. |
| Outcome-Banner (Failed-Ops-UI) | inline JSON-Antwort (kein Redirect) | `FailedOps::render()` (edit) | Edit fuegt Bulk-Dropdown + Apply-Button + Outcome-Container `<div data-panel="bulk_outcome">` in die Failed-Ops-Tabelle ein. JS rendert `"3 of 5 re-queued, 2 skipped"`-Banner aus AJAX-Response. |

> **Resend-AJAX (existing) vs. Bulk-Resend-AJAX (new):** Slice 38 lieferte `spreadconnect_resend_failed_op` (Per-Row, single ID). Slice 40 ergaenzt `spreadconnect_bulk_resend_failed_op` (Array von IDs). Single-Row-Path bleibt fuer Slice 38-Kompatibilitaet erhalten (kein Edit an `FailedOpsActions`).

---

## Acceptance Criteria

1) **AC-Preflight-Erweitert — `failed_op_id`-Lookup pro eligible Order**
   **GIVEN** `BulkResendCoordinator::preflight([1, 2, 3])` mit Order-Meta `_spreadconnect_state='failed_to_submit'` fuer Order 1+2 und `'NEW'` fuer Order 3. `FailedOpsRepo::findByEntity('order', '1', 'unresolved')` liefert eine Row mit `id=99`; `findByEntity('order', '2', 'unresolved')` liefert `[]` (kein Repo-Eintrag — z. B. weil 4xx vor Slice 37 angelegt wurde).
   **WHEN** die Methode ausgefuehrt wird
   **THEN** liefert sie `array{will_resend:2, will_skip:1, eligible_ids:[1,2], skipped:[3=>'not_failed'], eligible_ids_with_op_id:[1=>99, 2=>null]}`. Slice-33-Vertrag (`will_resend`/`will_skip`/`eligible_ids`/`skipped`) bleibt **unveraendert**; nur der **neue** Schluessel `eligible_ids_with_op_id` ist additiv. Bei `findByEntity`-Throw -> `eligible_ids_with_op_id[$id]=null` (Defensive-Default; **kein** Re-Throw).

2) **AC-Run-Schedules-CreateOrder-PerEligible — echte AS-Schedules statt Stub**
   **GIVEN** `BulkResendCoordinator::run([1, 2, 3])` mit `preflight()`-Result aus AC-1
   **WHEN** die Methode ausgefuehrt wird
   **THEN** wird **genau zweimal** `as_enqueue_async_action('spreadconnect/create_order', ['order_id'=>1], 'spreadconnect')` und `as_enqueue_async_action('spreadconnect/create_order', ['order_id'=>2], 'spreadconnect')` aufgerufen — **nicht** fuer Order 3. Returntyp: `array{queued:int, skipped:int, run_id:string, per_row:array<int,string>}` mit `queued=2`, `skipped=1`, `per_row=[1=>'requeued', 2=>'requeued', 3=>'not_failed']`, `run_id` = `wp_generate_uuid4()`-Result. **Kein** `FailedOpsRepo::markResolved()`-Call (resolved-Mark passiert beim erfolgreichen Re-Run im `OrderSubmitJob`).

3) **AC-Run-RefusalOnZeroEligible — Banner bei `queued=0`**
   **GIVEN** `BulkResendCoordinator::run([3])` (Order 3 hat `_spreadconnect_state='NEW'`, also nicht eligible)
   **WHEN** die Methode ausgefuehrt wird
   **THEN** liefert sie `array{queued:0, skipped:1, run_id:'...', per_row:[3=>'not_failed']}`. **Kein** `as_enqueue_async_action`-Call. Slice-33-`handleBulkAction()` (unveraendert) liest `queued===0` und schreibt `bulk_resend_summary=no_eligible` in den Redirect — Outcome-Banner aus Slice 33 AC-14 (`notice-warning`) wird gerendert.

4) **AC-Run-Logging — Coordinator schreibt Strukturlog**
   **GIVEN** ein erfolgreicher `run([1, 2, 3])`-Lauf aus AC-2
   **WHEN** die Methode ausgefuehrt wird
   **THEN** wird `wc_get_logger()->info()` mit Source `'spreadconnect-failure'`, Tag `'bulk_resend_run'`, Context `['run_id'=>...,  'queued'=>2, 'skipped'=>1, 'per_row_summary'=>[ 'requeued'=>2, 'not_failed'=>1 ]]` aufgerufen. Pro skipped-Row **kein** Warning-Log (sonst Log-Spam bei grossen Selektionen).

5) **AC-ResendFailedOps-Happy-Path — Bulk-Resend aus Failed-Ops-UI**
   **GIVEN** drei `failed_ops`-Rows: `id=10, op_type='create_order', payload=['order_id'=>1]`; `id=11, op_type='sync_article', payload=['article_id'=>500]`; `id=12, op_type='confirm_order', payload=['order_id'=>2]`. Aufruf `BulkResendCoordinator::resendFailedOps([10, 11, 12])`.
   **WHEN** die Methode ausgefuehrt wird
   **THEN** wird (a) `as_enqueue_async_action('spreadconnect/create_order', ['order_id'=>1], 'spreadconnect')`, (b) `as_enqueue_async_action('spreadconnect/sync_article', ['article_id'=>500], 'spreadconnect')`, (c) `as_enqueue_async_action('spreadconnect/confirm_order', ['order_id'=>2], 'spreadconnect')` aufgerufen, anschliessend pro ID `FailedOpsRepo::markResolved($id)`. Returntyp: `array{queued:3, skipped:0, run_id:'...', per_row:[10=>'requeued', 11=>'requeued', 12=>'requeued']}`. Op-Type-zu-Hook-Mapping = identisch zur Slice-38 `OP_TYPE_TO_HOOK`-Konstante (siehe Constraints).

6) **AC-ResendFailedOps-MissingRow — `findById` returns null -> skip**
   **GIVEN** `failed_op_ids=[10, 99]`, wobei `findById(99)` `null` liefert (Row geloescht zwischen Selection und Apply).
   **WHEN** `resendFailedOps([10, 99])` ausgefuehrt wird
   **THEN** wird Row 10 normal verarbeitet (`as_enqueue` + `markResolved`). Row 99 wird uebersprungen: `per_row[99]='row_missing'`, `skipped++`, **kein** `as_enqueue`, **kein** `markResolved`. Methode wirft NICHT.

7) **AC-DismissFailedOps-AbortOnCreateOrder — Per-Op-Type-Regel blockt Bulk**
   **GIVEN** `failed_op_ids=[10, 11, 12]` mit `op_type` aus AC-5 (`create_order`, `sync_article`, `confirm_order`). Aufruf `BulkResendCoordinator::dismissFailedOps([10, 11, 12])`.
   **WHEN** die Methode ausgefuehrt wird
   **THEN** wird **kein** `markDismissed`-Call abgesetzt (auch nicht fuer Rows 11/12). Returntyp: `array{ok:false, code:'create_order_in_selection', dismissed:0, blocked_ids:[10], message:string}`. Die `message` enthaelt den i18n-String `__('1 create_order entry requires explicit resolution — open it individually.', 'spreadconnect-pod')` (Discovery Z. 640; Wireframe `bulk_dismiss_blocked` Z. 472). Plural-Form via `_n()` bei `count(blocked_ids) > 1`.

8) **AC-DismissFailedOps-PlainOnly — alle Rows ohne `create_order` werden dismissed**
   **GIVEN** `failed_op_ids=[11, 12]` (nur `sync_article` und `confirm_order`).
   **WHEN** `dismissFailedOps([11, 12])` ausgefuehrt wird
   **THEN** wird pro Row `FailedOpsRepo::markDismissed($id)` aufgerufen. Returntyp: `array{ok:true, dismissed:2, blocked_ids:[]}`. Slice-38-Rule "Plain-Dismiss erlaubt fuer non-`create_order`-Op-Types" wird respektiert.

9) **AC-Ajax-BulkResend-FailedOps-Wired — `wp_ajax_spreadconnect_bulk_resend_failed_op`**
   **GIVEN** POST `{action:'spreadconnect_bulk_resend_failed_op', failed_op_ids:[10,11,12], _ajax_nonce:<valid>}` mit Cap `manage_woocommerce`.
   **WHEN** der Handler `BulkResendCoordinator::handleBulkResendAjax()` (oder Adapter-Methode in `OrderListColumns`/`FailedOpsActions` — Implementer-Wahl, siehe Deliverables) feuert
   **THEN** verifiziert er **in Reihenfolge** (a) `check_ajax_referer('spreadconnect_failed_ops', '_ajax_nonce', false)` -> false -> `wp_send_json_error([...], 403)`; (b) `current_user_can('manage_woocommerce')` -> false -> 403; (c) `failed_op_ids` non-empty `int[]` (via `array_map('intval', ...)` + `array_filter(fn($id)=>$id>0)`) -> false -> 400. Bei Pass: delegiert an `resendFailedOps(...)` und antwortet `wp_send_json_success(array_merge($result, ['banner' => sprintf(_n('%1$d of %2$d re-queued, %3$d skipped', '%1$d of %2$d re-queued, %3$d skipped', $total, ...), $queued, $total, $skipped)]))`. Nonce-Action `'spreadconnect_failed_ops'` wird aus Slice 38 wiederverwendet (kein neuer Nonce).

10) **AC-Ajax-BulkDismiss-FailedOps-Wired — `wp_ajax_spreadconnect_bulk_dismiss_failed_op`**
    **GIVEN** POST `{action:'spreadconnect_bulk_dismiss_failed_op', failed_op_ids:[10,11,12], _ajax_nonce:<valid>}` mit `failed_op_id=10` op_type=`create_order`.
    **WHEN** der Handler feuert
    **THEN** Cap+Nonce-Reihenfolge identisch AC-9; bei Pass delegiert an `dismissFailedOps(...)`. Wenn das Result `ok:false` enthaelt: Response `wp_send_json_error(['code'=>'create_order_in_selection', 'blocked_ids'=>[10], 'message'=>...], 422)`. Wenn `ok:true`: `wp_send_json_success(['dismissed'=>N, 'blocked_ids'=>[]])`. **Kein** Resolution-Modal-Trigger Server-seitig (das ist UI-Verantwortung — siehe AC-13).

11) **AC-FailedOpsView-BulkUI-Markup — Bulk-Dropdown + Apply + Checkbox-Spalte**
    **GIVEN** `Hub\View\FailedOps::render()` rendert eine Tabelle mit drei Rows (zwei `unresolved`, eine `unresolved` `create_order`).
    **WHEN** der Render-Output produziert wird
    **THEN** enthaelt das Markup (a) `<select name="bulk_action">` mit zwei Options: `spreadconnect_bulk_resend_failed_op` (Label `__('Resend selected', 'spreadconnect-pod')`) und `spreadconnect_bulk_dismiss_failed_op` (Label `__('Dismiss selected', 'spreadconnect-pod')`); (b) `<button data-action="bulk-apply">` (i18n `__('Apply', 'spreadconnect-pod')`); (c) eine Spalte `<input type="checkbox" data-bulk-row-id="{id}">` pro Tabellen-Row sowie `<input type="checkbox" data-bulk-select-all>` im Header; (d) ein `<div data-panel="bulk_outcome" hidden>`-Container fuer das JS-Banner. **Kein** Inline-`onclick` (alle Handler in `assets/js/failed-ops-bulk.js`). Sidebar (Slice 13) bleibt unveraendert.

12) **AC-FailedOpsView-Localize-BulkData — Localized-Keys erweitert**
    **GIVEN** `Hub\View\FailedOps::render()` enqueued `assets/js/failed-ops-bulk.js`
    **WHEN** das Asset registriert wird
    **THEN** `wp_localize_script('spreadconnect-failed-ops-bulk', 'spreadconnectFailedOpsBulk', [...])` enthaelt mindestens: `ajaxUrl`, `nonce` (= `wp_create_nonce('spreadconnect_failed_ops')` — selbe Nonce-Action wie Slice 38), `actions.bulkResend='spreadconnect_bulk_resend_failed_op'`, `actions.bulkDismiss='spreadconnect_bulk_dismiss_failed_op'`, `i18n.bannerSuccessTpl='%1$d of %2$d re-queued, %3$d skipped'`, `i18n.bannerBlockedTpl='%d create_order entries require explicit resolution — open them individually.'`, `i18n.noSelection='Please select at least one row.'`, `i18n.confirmDismiss='Dismiss selected entries?'`. Asset-Handle `'spreadconnect-failed-ops-bulk'` (eindeutig zu Slice 38 `'spreadconnect-failed-ops'`); Dependency `['jquery']`; Dependency-Reihenfolge ist irrelevant.

13) **AC-JS-BulkBlocked-RendersBanner — Client-Side reagiert auf `create_order_in_selection`**
    **GIVEN** Asset `failed-ops-bulk.js` ist geladen, Tabelle hat 3 ausgewaehlte Rows (1x `create_order`, 2x `sync_article`).
    **WHEN** der Admin "Dismiss selected" + [Apply] klickt; AJAX-Response = `{ok:false, code:'create_order_in_selection', blocked_ids:[10], message:'1 create_order entry requires explicit resolution — open it individually.'}` (HTTP 422)
    **THEN** rendert das Asset (a) das Banner-Markup `<div class="notice notice-error spreadconnect-bulk-blocked">…message…</div>` im `[data-panel="bulk_outcome"]`-Container; (b) entfernt **keine** Row aus dem DOM; (c) hebt die `blocked_ids`-Rows visuell hervor (CSS-Klasse `spreadconnect-row-blocked`). Selection-State (Checkboxen) bleibt erhalten (Wireframe `bulk_dismiss_blocked` Z. 472 — "Selection retained").

14) **AC-JS-BulkResend-RendersOutcome — Client-Side rendert `"X of Y re-queued, Z skipped"`**
    **GIVEN** Asset `failed-ops-bulk.js` ist geladen, Tabelle hat 5 ausgewaehlte Rows (3 eligible, 2 row_missing).
    **WHEN** der Admin "Resend selected" + [Apply] klickt; AJAX-Response = `{ok:true, queued:3, skipped:2, per_row:{10:'requeued', 11:'requeued', 12:'requeued', 99:'row_missing', 100:'row_missing'}, banner:'3 of 5 re-queued, 2 skipped'}`
    **THEN** rendert das Asset (a) das Banner-Markup `<div class="notice notice-success spreadconnect-bulk-outcome">3 of 5 re-queued, 2 skipped</div>` im `[data-panel="bulk_outcome"]`-Container; (b) entfernt die drei `requeued`-Rows aus dem DOM; (c) belaesst `row_missing`-Rows (sind ohnehin schon weg vom Server, Markup wird best-effort entfernt). `[data-panel]` wird `hidden`-Attribut entfernt.

15) **AC-Hook-Wiring — Bootstrap registriert die zwei neuen AJAX-Hooks idempotent**
    **GIVEN** Plugin-Boot
    **WHEN** `Bootstrap\Plugin::init()` durchlaeuft
    **THEN** sind via `add_action('wp_ajax_spreadconnect_bulk_resend_failed_op', [...])` und `add_action('wp_ajax_spreadconnect_bulk_dismiss_failed_op', [...])` zwei **neue** Handler registriert (kein `nopriv`). Doppelter `init()`-Aufruf fuegt nicht doppelt hinzu (`has_action()`-Idempotenz analog Slice 38). `BulkResendCoordinator` wird mit `FailedOpsRepo` (aus Slice 37) konstruiert; Slice-33-Konstruktor-Vertrag (DI-frei) wird **erweitert** auf `__construct(?FailedOpsRepo $repo = null, ?\WC_Logger $logger = null)` — der `?`-Default haelt Slice-33-Tests rueckwaertskompatibel (Implementer pflegt diese Konvention).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Eine Test-Datei `tests/slices/pod-shop-mvp/slice-09-bulk-resend.php` mit drei Test-Klassen (Coordinator-Logic, AJAX-Handler, View-Markup) — alternativ drei Files. Brain\Monkey-Setup mit `Functions\expect('as_enqueue_async_action')`, `Functions\when('wp_generate_uuid4')`. `FailedOpsRepo` per Mockery-Mock. Slice-33-`preflight()`-Tests bleiben gruen (Vertrag rueckwaertskompatibel).

### Test-Datei: `tests/slices/pod-shop-mvp/slice-09-bulk-resend.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests\Failure;

use PHPUnit\Framework\TestCase;

final class BulkResendCoordinatorTest extends TestCase
{
    // AC-1: preflight liefert eligible_ids_with_op_id additiv
    public function test_preflight_adds_failed_op_id_lookup_for_eligible_orders(): void { $this->markTestIncomplete('AC-1'); }
    public function test_preflight_returns_null_when_no_repo_row_for_eligible(): void { $this->markTestIncomplete('AC-1'); }
    public function test_preflight_keeps_slice_33_contract_keys_unchanged(): void { $this->markTestIncomplete('AC-1'); }

    // AC-2: run schedules create_order per eligible
    public function test_run_enqueues_create_order_action_per_eligible_order(): void { $this->markTestIncomplete('AC-2'); }
    public function test_run_does_not_enqueue_for_skipped_orders(): void { $this->markTestIncomplete('AC-2'); }
    public function test_run_returns_run_id_uuid(): void { $this->markTestIncomplete('AC-2'); }
    public function test_run_does_not_call_mark_resolved(): void { $this->markTestIncomplete('AC-2'); }

    // AC-3: run with zero eligible
    public function test_run_returns_zero_queued_when_no_eligible(): void { $this->markTestIncomplete('AC-3'); }
    public function test_run_does_not_enqueue_anything_when_no_eligible(): void { $this->markTestIncomplete('AC-3'); }

    // AC-4: structured logging
    public function test_run_writes_structured_info_log_with_run_id(): void { $this->markTestIncomplete('AC-4'); }
    public function test_run_does_not_log_per_row_warnings_for_skipped(): void { $this->markTestIncomplete('AC-4'); }

    // AC-5: resendFailedOps happy path
    public function test_resend_failed_ops_enqueues_correct_hook_per_op_type(): void { $this->markTestIncomplete('AC-5'); }
    public function test_resend_failed_ops_marks_each_row_resolved(): void { $this->markTestIncomplete('AC-5'); }
    public function test_resend_failed_ops_returns_per_row_requeued_marker(): void { $this->markTestIncomplete('AC-5'); }

    // AC-6: missing row tolerance
    public function test_resend_failed_ops_skips_when_find_by_id_returns_null(): void { $this->markTestIncomplete('AC-6'); }
    public function test_resend_failed_ops_does_not_throw_on_missing_row(): void { $this->markTestIncomplete('AC-6'); }

    // AC-7: dismiss aborts on create_order
    public function test_dismiss_failed_ops_aborts_with_blocked_ids_when_create_order_in_selection(): void { $this->markTestIncomplete('AC-7'); }
    public function test_dismiss_failed_ops_does_not_call_mark_dismissed_when_aborted(): void { $this->markTestIncomplete('AC-7'); }

    // AC-8: dismiss plain path
    public function test_dismiss_failed_ops_marks_each_non_create_order_row_dismissed(): void { $this->markTestIncomplete('AC-8'); }
}

final class BulkResendCoordinatorAjaxTest extends TestCase
{
    // AC-9: bulk-resend ajax wiring
    public function test_bulk_resend_ajax_returns_403_on_invalid_nonce(): void { $this->markTestIncomplete('AC-9'); }
    public function test_bulk_resend_ajax_returns_403_when_cap_missing(): void { $this->markTestIncomplete('AC-9'); }
    public function test_bulk_resend_ajax_returns_400_on_empty_failed_op_ids(): void { $this->markTestIncomplete('AC-9'); }
    public function test_bulk_resend_ajax_delegates_to_coordinator_and_returns_banner_string(): void { $this->markTestIncomplete('AC-9'); }

    // AC-10: bulk-dismiss ajax wiring
    public function test_bulk_dismiss_ajax_returns_422_on_create_order_in_selection(): void { $this->markTestIncomplete('AC-10'); }
    public function test_bulk_dismiss_ajax_returns_success_when_no_create_order(): void { $this->markTestIncomplete('AC-10'); }
}

final class FailedOpsBulkUiMarkupTest extends TestCase
{
    // AC-11: view markup
    public function test_render_emits_bulk_action_select_with_two_options(): void { $this->markTestIncomplete('AC-11'); }
    public function test_render_emits_per_row_checkbox_and_select_all(): void { $this->markTestIncomplete('AC-11'); }
    public function test_render_emits_bulk_outcome_panel_container(): void { $this->markTestIncomplete('AC-11'); }
    public function test_render_uses_no_inline_onclick_for_bulk_controls(): void { $this->markTestIncomplete('AC-11'); }

    // AC-12: localized data
    public function test_render_localizes_bulk_actions_and_i18n_keys(): void { $this->markTestIncomplete('AC-12'); }
    public function test_render_reuses_failed_ops_nonce_action(): void { $this->markTestIncomplete('AC-12'); }

    // AC-13/14: JS-Banner-Markup-Assertions (siehe Slice 38 Konvention — Markup-Inspection statt DOM-Smoke)
    public function test_bulk_outcome_container_supports_blocked_state_class(): void { $this->markTestIncomplete('AC-13'); }
    public function test_bulk_outcome_container_supports_success_state_class(): void { $this->markTestIncomplete('AC-14'); }
    public function test_bulk_asset_file_exists_at_expected_path(): void { $this->markTestIncomplete('AC-12'); }

    // AC-15: hook wiring
    public function test_plugin_init_registers_bulk_resend_and_dismiss_ajax_hooks(): void { $this->markTestIncomplete('AC-15'); }
    public function test_plugin_init_bulk_hooks_are_idempotent(): void { $this->markTestIncomplete('AC-15'); }
    public function test_coordinator_constructor_accepts_repo_and_logger_optionally(): void { $this->markTestIncomplete('AC-15'); }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-37-failed-ops-repo` | `Failure\FailedOpsRepo` mit `findByEntity`, `findById`, `markResolved`, `markDismissed` | infrastructure class | Konstruktor-DI in `BulkResendCoordinator`. Slice 37 Provides-Section ist Source of Truth. |
| `slice-38-failed-ops-ui` | Op-Type-zu-AS-Hook-Mapping + Nonce-Action `'spreadconnect_failed_ops'` | shared convention | Slice 40 nutzt **identisch dieselbe** `OP_TYPE_TO_HOOK`-Tabelle (alle 9 Op-Types -> 9 AS-Hooks); siehe Slice 38 Provides-Tabelle. Implementer hebt die `private const` aus `FailedOpsActions` in einen geteilten Helper (z. B. `Failure\OpTypeHookMap`) — ODER Coordinator inlinet die identische 9-Eintrag-Tabelle (Konvention). |
| `slice-38-failed-ops-ui` | `Hub\View\FailedOps::render()`-Stub | view class | Slice 40 EDITED diese Klasse — fuegt Bulk-UI ein, ohne den Per-Row-Render zu brechen (siehe Constraints). |
| `slice-33-order-list-columns-bulk` | `BulkResendCoordinator::preflight()`-Vertrag + `run()`-Stub-Body | shared class | Slice 40 EDITED **diese Klasse** und **erweitert** `preflight()` (additiv) und **ersetzt** `run()`-Body. Slice 33 Tests bleiben gruen — nur `run()`-Test (Slice-33 AC-12) muss aktualisiert werden (Implementer-Vermerk in Slice-33-Test-Datei). |
| `slice-04-schema-dbdelta` | Tabelle `wp_spreadconnect_failed_ops` | DB schema | Indirekt ueber Repo. |
| `slice-28-order-submit-job` | AS-Hook `'spreadconnect/create_order'` (Args `['order_id'=>int]`) | string-literal + Args-Schema | Slice 40 schedult diesen Hook in `run()`. |
| Action Scheduler / WordPress | `as_enqueue_async_action`, `wp_generate_uuid4`, `check_ajax_referer`, `current_user_can`, `wp_send_json_*`, `wp_localize_script`, `wp_enqueue_script`, `wc_get_logger`, `_n`, `sprintf`, `__` | WP/AS-API | Standard. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `Failure\BulkResendCoordinator` (vollstaendig implementiert) | Application class | UI-Surface ist Endpunkt; Slice 33 + Slice 38 nutzen das Public-Interface | Public-Methoden: `preflight(int[]): array` (erweiterter Slice-33-Vertrag), `run(int[]): array` (echter Body), `resendFailedOps(int[]): array`, `dismissFailedOps(int[]): array`. Konstruktor `__construct(?FailedOpsRepo $repo = null, ?\WC_Logger $logger = null)`. |
| AJAX-Hooks `spreadconnect_bulk_resend_failed_op` + `spreadconnect_bulk_dismiss_failed_op` | wp_ajax-action-names | (intern; nur durch JS aus `assets/js/failed-ops-bulk.js`) | POST `{failed_op_ids:int[], _ajax_nonce}` -> `{ok, queued, skipped, run_id, per_row, banner}` bzw. `{ok, dismissed, blocked_ids}`. Nonce-Action wiederverwendet aus Slice 38 (`'spreadconnect_failed_ops'`). |
| Asset-Handle `'spreadconnect-failed-ops-bulk'` (JS) | enqueued script | — | enqueued nur in `Hub\View\FailedOps::render()`. |
| Op-Type-zu-Hook-Konstante (geteilt mit Slice 38) | shared convention | (intern) | Identisch zur Slice-38 `OP_TYPE_TO_HOOK`-Tabelle. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Failure/BulkResendCoordinator.php` — **Edit, nicht ersetzen.** Slice-33-Stub-Klasse erweitern: (a) Konstruktor `__construct(?FailedOpsRepo $repo = null, ?\WC_Logger $logger = null)`; (b) `preflight()` erweitert um `eligible_ids_with_op_id`-Schluessel via `FailedOpsRepo::findByEntity()` (additiv, AC-1); (c) `run()`-Body ersetzt: Per eligible Order `as_enqueue_async_action('spreadconnect/create_order', ['order_id'=>$id], 'spreadconnect')`, `per_row`-Map befuellen, `run_id` via `wp_generate_uuid4()`, Strukturlog (AC-2..4); (d) NEU `resendFailedOps(int[] $failed_op_ids): array` (AC-5..6); (e) NEU `dismissFailedOps(int[] $failed_op_ids): array` (AC-7..8) mit Per-Op-Type-Abort. Op-Type-zu-AS-Hook-Mapping als `private const OP_TYPE_TO_HOOK = [...]` (identisch Slice 38). Logging via `wc_get_logger()` Source `'spreadconnect-failure'`.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/View/FailedOps.php` — **Edit, nicht ersetzen.** Slice-38-Klasse erweitern: (a) Bulk-Action-Bar oberhalb der Tabelle rendern (`<select name="bulk_action">` + `[Apply]`-Button + Select-All-Checkbox); (b) Per-Row-`<input type="checkbox" data-bulk-row-id="{id}">` als erste Tabellen-Spalte; (c) `<div data-panel="bulk_outcome" hidden>`-Container fuer JS-Banner; (d) zweites Asset `'spreadconnect-failed-ops-bulk'` enqueuen mit `wp_localize_script(..., 'spreadconnectFailedOpsBulk', [...])` (AC-12). Slice-38-Per-Row-Render und Modal-Markup bleiben **unveraendert**.
- [ ] `wordpress/plugins/spreadconnect-pod/assets/js/failed-ops-bulk.js` — Vanilla-JS / jQuery (Asset-Dep `['jquery']`). Verantwortet: (a) `change`-Listener auf `[data-bulk-select-all]` toggled alle Per-Row-Checkboxen; (b) Click auf `[data-action="bulk-apply"]`: liest `<select>`-Value (`spreadconnect_bulk_resend_failed_op` ODER `spreadconnect_bulk_dismiss_failed_op`), sammelt `data-bulk-row-id`-Werte aller checkbox'd Rows, sendet AJAX mit `_ajax_nonce` aus `spreadconnectFailedOpsBulk.nonce`; (c) Bei `ok:true` -> Banner mit `i18n.bannerSuccessTpl` rendern + entferne `requeued`-Rows aus DOM (AC-14); (d) Bei `ok:false, code:'create_order_in_selection'` -> Banner-Variante `notice-error` mit `message`, blocked Rows visuell highlighten (CSS-Klasse `spreadconnect-row-blocked`), Selection bleibt erhalten (AC-13); (e) Bei leerer Selection -> JS-Alert mit `i18n.noSelection` (kein AJAX-Call).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — **Edit, nicht ersetzen.** In `init()` ergaenzen: (a) `BulkResendCoordinator`-Konstruktion (re-uses `$failedOpsRepo` aus Slice 37); (b) `add_action('wp_ajax_spreadconnect_bulk_resend_failed_op', [<adapter>, 'handleBulkResendAjax'], 10, 0)`; (c) `add_action('wp_ajax_spreadconnect_bulk_dismiss_failed_op', [<adapter>, 'handleBulkDismissAjax'], 10, 0)`. Adapter-Methoden gehoeren in `BulkResendCoordinator` (oder, falls Implementer eine duenne Adapter-Klasse vorzieht: `Hub\Ajax\BulkResendActions.php` — dann **ein zusaetzliches** Deliverable noetig; der vorliegende Slice empfiehlt die Inline-Methode in `BulkResendCoordinator`, um die Drei-Datei-Grenze zu halten). Idempotenz-Check via `has_action()`. Bestehende Hook-Registrierungen aus Slice 02..38 unveraendert.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-09-bulk-resend.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- KEIN Schedule der `spreadconnect/...`-Hooks aus dem Failed-Ops-Bulk-Pfad mit zusaetzlichen Args ueber das Slice-37-Mapping hinaus — Coordinator nutzt **exakt** das `payload`-Array aus der `failed_ops`-Row als Args.
- KEINE Modifikation an `OrderListColumns::handleBulkAction()` aus Slice 33 — Slice 33 delegiert weiterhin an `BulkResendCoordinator::run()`; Slice 40 ersetzt nur den **Body** der Methode (Vertrag bleibt stabil).
- KEINE Modifikation an Slice-38 `FailedOpsActions::resend()/dismiss()/resolve()` — Single-Row-Pfad bleibt unveraendert. Slice 40 fuegt zwei **neue** AJAX-Hooks hinzu, ersetzt keine.
- KEINE persistente Admin-Notice / Email bei Bulk-Erfolg — kommt aus Slice 39 (`FailureNotifier`); Slice 40 schreibt nur `wc_get_logger()`-Eintraege und liefert das inline-Banner.
- KEIN automatisches `markResolved` der `failed_ops`-Row im **Order-List**-Bulk-Pfad (`run()`) — der Resolve passiert erst, wenn der re-enqueuede `OrderSubmitJob` erfolgreich durchlaeuft (Slice 28 schreibt State `NEW`). Im **Failed-Ops**-Bulk-Pfad (`resendFailedOps`) markiert Coordinator dagegen sofort `markResolved`, weil der Admin explizit den Bulk gestartet hat (siehe AC-5).
- KEIN Pagination / Filter im Bulk-Apply — Coordinator akzeptiert die uebergebenen IDs as-is (UI-Layer ist verantwortlich, nur sichtbare Rows zu submitten).
- KEIN Refund-Flow bei `create_order`-Resolution — Slice 38 liefert das Resolution-Modal (Slice 40 liefert nur Bulk-Pfade; Plain-Bulk-Dismiss fuer `create_order` ist explizit verboten, AC-7).
- KEIN E2E-Browser-Test — JS-Verhalten via Markup-Assertions + Server-Response-Mocks.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in allen PHP-Dateien.
- `final class` fuer `BulkResendCoordinator` (bereits aus Slice 33 — bleibt).
- AJAX-Nonce-Action: `'spreadconnect_failed_ops'` (wiederverwendet aus Slice 38 — Single-Source-Of-Truth fuer alle Failed-Ops-AJAX-Hooks). **Kein** neuer Nonce.
- Cap-Gate: `current_user_can('manage_woocommerce')` — identisch Slice 38.
- HTTP-Status-Codes: `403` (Cap/Nonce), `400` (Param-Validation), `422` (Resolution-Refused / `create_order_in_selection`), `500` (Try-Catch-Wrap fuer unerwartete Throws).
- `failed_op_ids`-Sanitization: `array_map('intval', ...)` + `array_filter(fn($id) => $id > 0)` — identisch zu Slice 33 AC-10 Konvention.
- `run_id`-Generierung via `wp_generate_uuid4()` — KEIN `uniqid()` (nicht UUID-konform).
- Logging via `wc_get_logger()->info()/warning()/error()` mit Source `'spreadconnect-failure'` — KEIN `error_log()`. Tags: `bulk_resend_run`, `bulk_resend_failed_ops`, `bulk_dismiss_blocked`.
- Coordinator-Methoden MUESSEN Try-Catch um den Body wrappen (`\Throwable`); Fehler -> Log + Defensive-Default-Return (`['queued'=>0, 'skipped'=>count($ids), 'run_id'=>'', 'per_row'=>[]]`). **Kein** Throw aus `run()`/`resendFailedOps()`/`dismissFailedOps()` (UI darf nicht crashen).
- AS-Schedule-Convention: `as_enqueue_async_action($hook, $args, 'spreadconnect')` — Group-Name `'spreadconnect'` ist Pflicht (Architecture Z. 542-558 + Slice 28 Konvention).
- Op-Type-zu-AS-Hook-Mapping `private const OP_TYPE_TO_HOOK` enthaelt **alle 9** Op-Types aus Slice 37 AC-9 (`create_order`, `confirm_order`, `cancel_order_mirror`, `fetch_tracking`, `sync_article`, `sync_catalog`, `handle_article_removed`, `handle_webhook`, `scheduled_stock_sync`). Mapping-Wert ist der `'spreadconnect/...'`-Hook-String. Bei unbekanntem `op_type` (z. B. zukuenftige Ops) -> `per_row[$id]='unknown_op_type'`, kein Schedule.
- WC-Order-Meta-Reads via `wc_get_order($id)->get_meta('_spreadconnect_state')` — KEIN `get_post_meta()`.
- Markup-Output via `printf('%s', esc_html(...))` / `esc_attr()` / `esc_url()`-Wrapper; **keine** rohen Variablen-Interpolations in HTML.
- Slice-33-Konstruktor-Vertrag (DI-frei): wird auf optionale `?FailedOpsRepo`-DI **erweitert**, sodass Slice-33-Tests ohne Aenderung gruen bleiben (AC-15-Last-Bullet).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Failure/BulkResendCoordinator.php` (Slice 33) | **Edit, nicht ersetzen.** `preflight()` wird additiv erweitert; `run()`-Body wird ersetzt; zwei neue Public-Methoden (`resendFailedOps`, `dismissFailedOps`) werden hinzugefuegt. |
| `wordpress/plugins/spreadconnect-pod/includes/Failure/FailedOpsRepo.php` (Slice 37) | **Wiederverwendet, unveraendert.** `findById`, `findByEntity`, `markResolved`, `markDismissed`. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/FailedOps.php` (Slice 38) | **Edit, nicht ersetzen.** Bulk-UI-Markup additiv hinzugefuegt; Per-Row-Render und Resolution-Modal aus Slice 38 unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/FailedOpsActions.php` (Slice 38) | **Wiederverwendet, unveraendert.** `OP_TYPE_TO_HOOK`-Konstante wird in `BulkResendCoordinator` referenziert (oder gespiegelt — siehe Constraints). Single-Row-Handler bleibt aktiv fuer Slice-38-Buttons. |
| `wordpress/plugins/spreadconnect-pod/includes/Inline/OrderListColumns.php` (Slice 33) | **Wiederverwendet, unveraendert.** Slice-33 `handleBulkAction()` ruft weiterhin `BulkResendCoordinator::run()`; Slice 40 ersetzt nur den `run()`-Body. |
| `wordpress/plugins/spreadconnect-pod/assets/js/failed-ops-modal.js` (Slice 38) | **Wiederverwendet, unveraendert.** Slice 40 enqueued ein **zweites** Asset `failed-ops-bulk.js` (eigener Handle, keine Konflikte). |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02..38) | **Edit, nicht ersetzen.** Bestehende `init()`-Logik bleibt unveraendert; Slice 40 ergaenzt zwei `add_action('wp_ajax_*')`-Calls. |

**Referenzen:**
- Architecture: `architecture.md` -> Z. 391 (`Failure\BulkResendCoordinator` Service-Map-Eintrag — "pre-flight count + per-row outcome panel").
- Architecture: `architecture.md` -> Z. 144 (`spreadconnect_resend_failed_op` AJAX-Action — "Order-List bulk").
- Architecture: `architecture.md` -> Z. 145 (`spreadconnect_dismiss_failed_op` Refusal-Regel fuer `create_order`).
- Wireframes: `wireframes.md` -> Screen 5 Z. 408-411 + State `bulk_dismiss_blocked` Z. 472 ("X create_order entries require explicit resolution — open them individually."); State `bulk_action_in_progress` Z. 474.
- Wireframes: `wireframes.md` -> Screen 12 State `bulk_action_complete` Z. 1048 ("X of Y re-queued · Z skipped"); State `bulk_action_no_eligible` Z. 1049.
- Discovery: `discovery.md` -> Z. 640 — autoritative Bulk-Dismiss-Per-Op-Type-Regel; Z. 631-639 Dismiss-Semantik je Op-Type.
- Slim-Slices: `slices/slim-slices.md` -> Slice-40-Eintrag Z. 593-601 — Done-Signal: PHPUnit Bulk mit 5 Rows (3 eligible, 2 not) -> Banner "3 of 5 re-queued, 2 skipped"; Bulk-Dismiss mit `create_order` in Selection -> abort + Hint.
- Slice 33: `slices/slice-33-order-list-columns-bulk.md` -> AC-11/AC-12 — `BulkResendCoordinator::preflight()`/`run()`-Vertrag; Slice 40 erweitert nur additiv und ersetzt den `run()`-Body.
- Slice 37: `slices/slice-37-failed-ops-repo.md` -> Provides-Section (`findById`, `findByEntity`, `markResolved`, `markDismissed`) + AC-9 Op-Type-Tabelle (Source of Truth fuer das 9-Eintrag-Mapping).
- Slice 38: `slices/slice-38-failed-ops-ui.md` -> `OP_TYPE_TO_HOOK`-Konstante in `FailedOpsActions`, Nonce-Action `'spreadconnect_failed_ops'` — Slice 40 reuse-only.
