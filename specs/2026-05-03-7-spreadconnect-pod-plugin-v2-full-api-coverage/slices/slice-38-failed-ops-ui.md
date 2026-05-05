# Slice 38: Failed-Ops-UI + Dismiss-Resolution-Modal

> **Slice 38 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-38-failed-ops-ui` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-37-failed-ops-repo"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WC 10.5 HPOS + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: `/wp-admin/admin.php?page=spreadconnect&section=failed`) |
| **Health Endpoint** | `n/a` (Hub-Section + admin-ajax-Handler) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `check_ajax_referer`, `current_user_can`, `wp_send_json_success/error`, `as_enqueue_async_action`, `wc_get_order`; Mockery fuer `FailedOpsRepo`, `\WC_Order`). |

---

## Ziel

Liefert die User-Surface fuer Discovery-Slice 9 "Failure-Recovery": `Hub\View\FailedOps` rendert die DLQ-Tabelle aus `wp_spreadconnect_failed_ops` (state=`unresolved`) mit Per-Row [Resend]/[Dismiss]/[View Detail] und macht den von Slice 37 geschriebenen Repo via drei AJAX-Actions bedienbar. Plain-Dismiss eines `create_order`-Eintrags wird Server- **und** Client-seitig refused; das `failed-ops-modal.js`-Asset oeffnet stattdessen den 3-Choice-Resolution-Modal (Resend / Cancel WC / Submitted externally + ext-SC-OrderID).

---

## Acceptance Criteria

1) **AC-View-Render — `Hub\View\FailedOps::render()` listet alle `unresolved`-Rows**
   **GIVEN** `FailedOpsRepo::findAll(['state' => 'unresolved'], 50, 0)` liefert N Rows mit gemischten `op_type` (mind. eine `create_order` + eine `sync_article`)
   **WHEN** `Hub\View\FailedOps::render()` ausgefuehrt wird
   **THEN** wird die Sidebar (Slice 13) gerendert + ein `<table>` mit N `<tr data-row-id="..." data-op-type="...">`-Eintraegen, Spalten: Failed-At, Op-Type, Entity, Error (truncated 80 chars), Retries, Action-Buttons. Bei `findAll`-Result `[]` wird der Empty-State-Panel "No failed operations — all systems running smoothly." gerendert (siehe wireframes.md Screen 5 -> State `empty`).

2) **AC-View-Action-Buttons — Per-Row-Buttons sind op-type-aware gerendert**
   **GIVEN** eine Row mit `op_type='create_order'` und eine Row mit `op_type='sync_article'`
   **WHEN** `Hub\View\FailedOps::render()` ausgefuehrt wird
   **THEN** traegt die `create_order`-Row ein `<button data-action="dismiss" data-needs-resolution="1">`-Attribut, die `sync_article`-Row `data-needs-resolution="0"`. Beide Rows haben `<button data-action="resend">` und `<button data-action="detail">`. Der Render-Output enthaelt **kein** Inline-`onclick` (alle Handler ueber JS-Asset, AC-Modal-JS).

3) **AC-View-Nonce-Localized — JS-Asset bekommt Nonce + AJAX-URL**
   **GIVEN** `Hub\View\FailedOps::render()` wird aufgerufen
   **WHEN** das Asset `failed-ops-modal.js` enqueued wird
   **THEN** wird via `wp_localize_script('spreadconnect-failed-ops', 'spreadconnectFailedOps', [...])` ein Object mit Keys `ajaxUrl` (= `admin_url('admin-ajax.php')`), `nonce` (= `wp_create_nonce('spreadconnect_failed_ops')`), `i18n` (Strings `confirmDismiss`, `confirmResolution`, `manualOrderIdRequired`) bereitgestellt. Asset-Handle eindeutig, Dependency `['jquery']`.

4) **AC-Ajax-Resend-Happy-Path — `spreadconnect_resend_failed_op` re-enqueues + resolved-Mark**
   **GIVEN** eingeloggter Admin (`manage_woocommerce`), gueltige Nonce `spreadconnect_failed_ops`, POST-Body `{action: 'spreadconnect_resend_failed_op', failed_op_id: 42}`. `FailedOpsRepo::findById(42)` liefert Row mit `op_type='create_order'`, `payload=['order_id' => 7]`, `state='unresolved'`.
   **WHEN** der Handler `Hub\Ajax\FailedOpsActions::resend()` laeuft
   **THEN** wird `as_enqueue_async_action('spreadconnect/create_order', ['order_id' => 7], 'spreadconnect')` aufgerufen, anschliessend `FailedOpsRepo::markResolved(42)`, anschliessend `wp_send_json_success(['message' => __('Operation resent successfully', 'spreadconnect-pod'), 'failed_op_id' => 42])`. Op-Type-zu-Hook-Mapping (alle 9 Hooks) entspricht der Tabelle in Slice 37 AC-9 (invertiert).

5) **AC-Ajax-Resend-Capability-Nonce — beide Gates blocken**
   **GIVEN** ein Request ohne Nonce-Header / mit ungueltiger Nonce ODER eingeloggter User ohne `manage_woocommerce`
   **WHEN** der Handler `resend()` laeuft
   **THEN** ruft er `check_ajax_referer('spreadconnect_failed_ops', 'nonce', false)` bzw. `current_user_can('manage_woocommerce')`; bei false fuer eines der beiden -> `wp_send_json_error(['message' => ...], 403)`. Kein `findById`-Call, kein `as_enqueue_async_action`-Call.

6) **AC-Ajax-Dismiss-Plain-Allowed — Nicht-`create_order`-Op wird ohne Resolution gedismissed**
   **GIVEN** Row `id=88`, `op_type='sync_article'`, `state='unresolved'`. POST `{action: 'spreadconnect_dismiss_failed_op', failed_op_id: 88}` mit gueltiger Nonce + Cap.
   **WHEN** der Handler `dismiss()` laeuft
   **THEN** ruft er `FailedOpsRepo::markDismissed(88)` und antwortet `wp_send_json_success(['failed_op_id' => 88])`. Kein Resolution-Modal-Branch.

7) **AC-Ajax-Dismiss-CreateOrder-Refused — Plain-Dismiss fuer `create_order` wird Server-seitig abgelehnt**
   **GIVEN** Row `id=42`, `op_type='create_order'`. POST `{action: 'spreadconnect_dismiss_failed_op', failed_op_id: 42}` (ohne `resolution`-Key).
   **WHEN** der Handler `dismiss()` laeuft
   **THEN** wird **kein** `markDismissed`-Call abgesetzt; Response = `wp_send_json_error(['code' => 'resolution_required', 'message' => __('create_order entries require explicit resolution', 'spreadconnect-pod')], 422)`. Auch wenn ein client-side-Bug die Modal-Erzwingung umgaeht, der Server schuetzt invariant.

8) **AC-Ajax-Resolve-Resend-Branch — `resend`-Choice re-enqueues `create_order`**
   **GIVEN** Row `id=42`, `op_type='create_order'`, `payload=['order_id' => 7]`. POST `{action: 'spreadconnect_resolve_create_order', failed_op_id: 42, resolution: 'resend'}`.
   **WHEN** der Handler `resolve()` laeuft
   **THEN** ruft er `as_enqueue_async_action('spreadconnect/create_order', ['order_id' => 7], 'spreadconnect')` + `FailedOpsRepo::markResolved(42)` + `wp_send_json_success(...)`. Identisches Verhalten wie AC-4-Resend.

9) **AC-Ajax-Resolve-CancelWC-Branch — `cancel_wc`-Choice setzt WC-Order auf `cancelled`**
   **GIVEN** Row `id=42`, `op_type='create_order'`, `payload=['order_id' => 7]`. POST `{action: 'spreadconnect_resolve_create_order', failed_op_id: 42, resolution: 'cancel_wc'}`. `wc_get_order(7)` liefert WC_Order-Mock.
   **WHEN** der Handler `resolve()` laeuft
   **THEN** wird `$wcOrder->update_status('cancelled', __('Resolved via Failed-Ops modal — admin chose Cancel WC order', 'spreadconnect-pod'))` aufgerufen, anschliessend `FailedOpsRepo::markResolved(42)`. Plugin macht **keinen** Refund-API-Call (nur Status-Mutation + Note). Bei `wc_get_order(7) === false` -> `wp_send_json_error(['code' => 'wc_order_missing'], 422)`.

10) **AC-Ajax-Resolve-SubmittedExternally-Branch — `submitted_externally` schreibt Meta + Note**
    **GIVEN** Row `id=42`, `op_type='create_order'`, `payload=['order_id' => 7]`. POST `{action: 'spreadconnect_resolve_create_order', failed_op_id: 42, resolution: 'submitted_externally', external_sc_order_id: 'sc_abc_999'}`. `wc_get_order(7)` liefert WC_Order-Mock.
    **WHEN** der Handler `resolve()` laeuft
    **THEN** wird (a) `$wcOrder->update_meta_data('_spreadconnect_order_id', 'sc_abc_999')`, (b) `$wcOrder->update_meta_data('_spreadconnect_state', 'NEW')`, (c) `$wcOrder->save()`, (d) `$wcOrder->add_order_note(__('Submitted manually outside plugin (SC-OrderID: sc_abc_999)', 'spreadconnect-pod'))`, (e) `FailedOpsRepo::markResolved(42)` aufgerufen. Persistenz via `update_meta_data` + `save()` (HPOS-konform; **kein** `update_post_meta`).

11) **AC-Ajax-Resolve-SubmittedExternally-Validation — leerer External-ID-String wird abgelehnt**
    **GIVEN** POST `{..., resolution: 'submitted_externally', external_sc_order_id: ''}` (oder Key fehlt komplett).
    **WHEN** `resolve()` laeuft
    **THEN** Response = `wp_send_json_error(['code' => 'external_id_required', 'message' => __('External SC-OrderID is required for this resolution.', 'spreadconnect-pod')], 422)`. Kein Meta-Write, kein `markResolved`.

12) **AC-Ajax-Resolve-Unknown-Resolution-Refused — Whitelist auf 3 Werte**
    **GIVEN** POST `{..., resolution: 'foo'}` (nicht in `['resend', 'cancel_wc', 'submitted_externally']`).
    **WHEN** `resolve()` laeuft
    **THEN** Response = `wp_send_json_error(['code' => 'invalid_resolution'], 422)`. Strikte Whitelist; keine Default-to-Resend-Falle.

13) **AC-Ajax-Resolve-Wrong-OpType-Refused — `resolve_create_order` nur fuer `op_type='create_order'`**
    **GIVEN** Row `id=88`, `op_type='sync_article'`. POST `{action: 'spreadconnect_resolve_create_order', failed_op_id: 88, resolution: 'resend'}`.
    **WHEN** der Handler `resolve()` laeuft
    **THEN** Response = `wp_send_json_error(['code' => 'wrong_op_type', 'message' => ...], 422)`. Kein Meta-Write, kein Repo-Mutation.

14) **AC-JS-Modal-Trigger — Click auf `create_order`-Dismiss oeffnet Modal statt Plain-AJAX**
    **GIVEN** ein DOM mit zwei Rows: `<tr data-op-type="create_order">[Dismiss]</tr>` und `<tr data-op-type="sync_article">[Dismiss]</tr>`. Asset `failed-ops-modal.js` ist geladen.
    **WHEN** der Admin auf den Dismiss-Button der `create_order`-Row klickt
    **THEN** wird **kein** AJAX-Call zu `spreadconnect_dismiss_failed_op` abgesetzt; stattdessen wird der Resolution-Modal-Markup eingefuegt/sichtbar gemacht (Container-Selector `#spreadconnect-resolution-modal`, 3 Radio-Buttons mit values `resend`/`cancel_wc`/`submitted_externally`). Der `sync_article`-Dismiss-Click triggert direkt `spreadconnect_dismiss_failed_op` (Plain-Path).

15) **AC-JS-Modal-ManualInput-Gate — External-ID-Input erscheint conditional**
    **GIVEN** der Modal ist offen
    **WHEN** der Admin den Radio `submitted_externally` waehlt
    **THEN** wird das `<input name="external_sc_order_id">`-Feld angezeigt (vorher hidden); der `[Confirm Resolution]`-Button bleibt `disabled`, bis das Input einen non-empty getrimmten String enthaelt. Bei Wechsel zurueck zu `resend`/`cancel_wc` wird das Input wieder hidden + Button enabled.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Drei Test-Files: `tests/slices/pod-shop-mvp/slice-09-failed-ops-view.php` (AC-1 bis AC-3), `tests/slices/pod-shop-mvp/slice-09-failed-ops-ajax.php` (AC-4 bis AC-13), `tests/slices/pod-shop-mvp/slice-09-failed-ops-modal-js.php` (AC-14 bis AC-15 — entweder Patchwork-Stub fuer DOM-Smoke ODER reine Markup-Assertions im View-Output, je nach Mocking-Strategy). Brain\Monkey-Setup mit `Functions\expect('check_ajax_referer')`, `Functions\expect('current_user_can')`, `Functions\when('as_enqueue_async_action')`, `Functions\when('wc_get_order')`. `FailedOpsRepo` als Mockery-Mock injiziert (Konstruktor-DI; vgl. Slice 37 Provides-Section).

### Test-Datei: `tests/slices/pod-shop-mvp/slice-09-failed-ops-view.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class FailedOpsViewTest extends TestCase
{
    // AC-1
    public function test_render_emits_table_row_per_unresolved_failed_op(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    public function test_render_shows_empty_state_when_repo_returns_no_rows(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    public function test_render_truncates_error_message_to_80_chars(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2
    public function test_render_marks_create_order_row_with_needs_resolution_attr(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    public function test_render_marks_sync_article_row_without_needs_resolution(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    public function test_render_uses_no_inline_onclick_handlers(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3
    public function test_render_localizes_script_with_ajax_url_and_nonce(): void
    {
        $this->markTestIncomplete('AC-3');
    }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-09-failed-ops-ajax.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class FailedOpsAjaxTest extends TestCase
{
    // AC-4: resend happy path
    public function test_resend_enqueues_correct_action_for_op_type(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    public function test_resend_marks_failed_op_resolved_after_enqueue(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    public function test_resend_returns_json_success_with_failed_op_id(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: capability + nonce gates
    public function test_resend_returns_403_on_invalid_nonce(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    public function test_resend_returns_403_when_capability_missing(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: plain dismiss for non-create_order
    public function test_dismiss_marks_sync_article_row_dismissed(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: server refuses plain dismiss for create_order
    public function test_dismiss_refuses_plain_dismiss_for_create_order(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    public function test_dismiss_does_not_call_mark_dismissed_when_refused(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: resolve resend branch
    public function test_resolve_with_resend_enqueues_create_order_action(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: resolve cancel_wc branch
    public function test_resolve_with_cancel_wc_sets_wc_order_status_cancelled(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    public function test_resolve_cancel_wc_does_not_call_refund_api(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    public function test_resolve_returns_error_when_wc_order_missing(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: submitted_externally branch
    public function test_resolve_submitted_externally_writes_order_id_meta(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    public function test_resolve_submitted_externally_writes_state_new(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    public function test_resolve_submitted_externally_adds_order_note(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    public function test_resolve_submitted_externally_uses_hpos_safe_meta_writes(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: empty external id
    public function test_resolve_submitted_externally_rejects_empty_external_id(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    // AC-12: unknown resolution
    public function test_resolve_rejects_unknown_resolution_value(): void
    {
        $this->markTestIncomplete('AC-12');
    }

    // AC-13: wrong op_type
    public function test_resolve_create_order_refuses_other_op_types(): void
    {
        $this->markTestIncomplete('AC-13');
    }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-09-failed-ops-modal-js.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

// JS-Behavior wird ueber Markup-Inspection des Render-Outputs + Asset-Existenz-Check verifiziert.
// Ein optionaler DOM-Smoke-Test (Patchwork) kann die handler-Bindings asserten; alternativ
// reicht eine Markup-Assertion des Modal-Containers + Radio-Values.
final class FailedOpsModalJsTest extends TestCase
{
    // AC-14
    public function test_modal_container_is_rendered_with_three_radio_options(): void
    {
        $this->markTestIncomplete('AC-14');
    }

    public function test_create_order_dismiss_button_has_data_needs_resolution_one(): void
    {
        $this->markTestIncomplete('AC-14');
    }

    public function test_sync_article_dismiss_button_has_data_needs_resolution_zero(): void
    {
        $this->markTestIncomplete('AC-14');
    }

    // AC-15
    public function test_external_id_input_is_in_markup_with_initial_hidden_attr(): void
    {
        $this->markTestIncomplete('AC-15');
    }

    public function test_modal_asset_file_exists_at_expected_path(): void
    {
        $this->markTestIncomplete('AC-15');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-37-failed-ops-repo` | `SpreadconnectPod\Failure\FailedOpsRepo` mit `findAll`, `findById`, `markResolved`, `markDismissed` | infrastructure class | Konstruktor-DI; vgl. Slice 37 Provides-Section. |
| `slice-37-failed-ops-repo` | Op-Type-Enum (`create_order`, `confirm_order`, `cancel_order_mirror`, `fetch_tracking`, `sync_article`, `sync_catalog`, `handle_article_removed`, `handle_webhook`, `scheduled_stock_sync`) | shared convention | Slice 38 invertiert die Tabelle (op_type -> AS-Hook) fuer Resend-Re-Enqueue. |
| `slice-13-hub-page-skeleton` | Section-Routing `?section=failed` -> `Hub\View\FailedOps::render()` | dispatcher contract | Slice 13 AC-3 routet auf Klassennamen `Hub\View\FailedOps`; diese Slice fuellt den Stub. |
| `slice-04-schema-dbdelta` | Tabelle `wp_spreadconnect_failed_ops` | DB schema | Indirekt ueber Repo. |
| Action Scheduler | `as_enqueue_async_action($hook, $args, $group)` | AS-API | WC 10.5. |
| WordPress | `check_ajax_referer`, `current_user_can`, `wp_send_json_success/error`, `wp_localize_script`, `wp_enqueue_script`, `wc_get_order`, `add_action('wp_ajax_*')` | WP-API | Standard. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Hub\View\FailedOps` | View-Klasse | `Hub\Controller` (Slice 13 Routing); `Hub\View\Dashboard` (Slice 46 verlinkt auf diese Section) | `public static function render(): void` |
| `SpreadconnectPod\Hub\Ajax\FailedOpsActions` | adapter class (3 wp_ajax-Hooks) | Slice 40 (BulkResendCoordinator nutzt dieselben Op-Type-zu-Hook-Mapping-Konstanten); Slice 32 OrderMetaBox-Resend (Wiederverwendung der `resend()`-Logik via shared helper) | `__construct(FailedOpsRepo $repo)`; Public `register(): void` registriert 3 wp_ajax-Hooks; intern `resend(): void`, `dismiss(): void`, `resolve(): void` |
| Op-Type-zu-AS-Hook-Mapping (`create_order` -> `spreadconnect/create_order` ...) | shared convention | Slice 40 BulkResendCoordinator; Slice 32 Order-Meta-Box-Resend | Implementiert als `private const OP_TYPE_TO_HOOK = [...]` in `FailedOpsActions`. |
| AJAX-Nonce-Action `spreadconnect_failed_ops` | shared nonce | Slice 40 (Bulk-Resend nutzt dieselbe Nonce-Action) | String-Konstante. |
| Asset-Handle `spreadconnect-failed-ops` (JS) | enqueued script | — | enqueued nur in `Hub\View\FailedOps::render()`. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/View/FailedOps.php` — Neue Klasse `final class FailedOps`. `public static function render(): void` ruft `FailedOpsRepo::findAll(['state' => 'unresolved'], 50, 0)`, rendert Tabelle (Spalten: Failed-At, Op-Type, Entity, Error truncated, Retries, Actions), Empty-State-Panel bei 0 Rows, Modal-Container-Markup (hidden), enqueued `failed-ops-modal.js` mit `wp_localize_script`-Payload (`ajaxUrl`, `nonce`, `i18n`). Per-Row Action-Buttons mit `data-action` + `data-needs-resolution`-Attributen. Sidebar via `Hub\View\Sidebar::render('failed')` (Slice 13).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/FailedOpsActions.php` — Neue Klasse `final class FailedOpsActions`. Konstruktor `(FailedOpsRepo $repo)`. `public function register(): void` haengt `add_action('wp_ajax_spreadconnect_resend_failed_op', [$this, 'resend'])` + `add_action('wp_ajax_spreadconnect_dismiss_failed_op', [$this, 'dismiss'])` + `add_action('wp_ajax_spreadconnect_resolve_create_order', [$this, 'resolve'])`. Drei Public-Handler verifizieren Nonce + Capability, validieren Input, dispatchen Repo + AS + WC-Order-Mutationen, antworten via `wp_send_json_*`. Privates `OP_TYPE_TO_HOOK`-Mapping (alle 9 Op-Types -> `spreadconnect/...`-Hooks). Alle WC-Order-Meta-Writes via `update_meta_data() + save()` (HPOS).
- [ ] `wordpress/plugins/spreadconnect-pod/assets/js/failed-ops-modal.js` — Vanilla-JS oder jQuery (Asset-Dep `['jquery']`). Bindet Click-Handler an `[data-action="resend"]`/`[data-action="dismiss"]`/`[data-action="detail"]` (Event-Delegation auf Tabelle). `data-needs-resolution="1"` -> oeffnet Modal statt AJAX; sonst Plain-AJAX zu `spreadconnect_dismiss_failed_op`. Modal-Logik: Radio-Wechsel toggled External-ID-Input-Sichtbarkeit + `[Confirm]`-Button-Disabled-State; bei `[Confirm]`-Click POST zu `spreadconnect_resolve_create_order` mit `nonce`, `failed_op_id`, `resolution`, `external_sc_order_id?`. Auf Erfolg: Row aus DOM entfernen + Toast.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — **Edit, nicht ersetzen.** In `init()` ergaenzen: `$failedOpsActions = new FailedOpsActions($failedOpsRepo); $failedOpsActions->register();` (Repo-Konstruktion stammt aus Slice 37). Idempotenz wie in Slice 28/29/37 ueblich.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt die drei Test-Files basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- KEIN Bulk-Resend / Bulk-Dismiss — kommt in Slice 40 (`BulkResendCoordinator`). Slice 38 rendert nur die Per-Row-Buttons + die drei Single-Row-AJAX-Handler.
- KEINE persistente Admin-Notice / Email-Versand bei Resolve-Success — kommt in Slice 39 (`FailureNotifier` + `AdminNoticeStore`). Slice 38 antwortet nur `wp_send_json_success` und ueberlaesst Slice 39 das Notice-Cleanup via separaten Hook (out-of-scope hier).
- KEIN automatischer Refund bei `cancel_wc`-Resolution — Plugin setzt nur `update_status('cancelled', ...)` + Order-Note; Refund-Flow bleibt WC-nativ (siehe wireframes.md `dismiss_modal_create_order_open`-ASCII Z. 489).
- KEINE Pagination / Filter-UI — Slice 38 zeigt nur die ersten 50 `unresolved`-Rows. Pagination + Filter (op_type, date-range) sind ein moegliches Slice-46-Polish-Item; nicht in Scope.
- KEIN expandable Detail-Panel mit JSON-Payload + Per-Retry-Log (siehe wireframes.md ⑥) — `[View Detail]` rendert in dieser Slice nur einen einfachen Inline-Toggle mit `payload`-JSON-Pretty-Print (kein AS-Log-Lookup). Voller Per-Retry-Log kommt mit Slice 42 (Logs-UI).
- KEINE Modify am `Hub\View\Sidebar` (Slice 13) — Sidebar zeigt `failed`-Item bereits.
- KEIN E2E-Browser-Test — JS-Verhalten via Markup-Assertions; siehe Test-Skeletons.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in beiden PHP-Dateien.
- `final class` fuer beide neuen Klassen.
- Alle JSON-Responses via `wp_send_json_success` / `wp_send_json_error` mit explizitem HTTP-Status (`403` Capability, `422` Validation/Resolution-Refused, `404` `findById === null`).
- AJAX-Nonce-Action: `'spreadconnect_failed_ops'` (single, fuer alle 3 Handler — Slice 40 wird sie wiederverwenden).
- WC-Order-Meta MUSS via `$order->update_meta_data() + $order->save()` geschrieben werden (HPOS-konform; **nicht** `update_post_meta`). Order-Status-Mutation MUSS via `$order->update_status('cancelled', $note_str)` (nicht `wp_update_post`).
- `external_sc_order_id` wird via `sanitize_text_field()` getrimmt; non-empty getrimmter String ist Pflicht.
- `failed_op_id` wird via `absint($_POST['failed_op_id'])` validiert; bei `=== 0` -> `wp_send_json_error(['code' => 'invalid_id'], 400)`.
- Asset-Pfad-Konvention: `assets/js/failed-ops-modal.js` — Verzeichnis kann in dieser Slice neu angelegt werden (Plugin hatte bisher kein `assets/js/`). Slice 32 (Order-Meta-Box) und Slice 34 (Product-Meta-Box) werden denselben Pfad-Stamm nutzen.
- Logging via `wc_get_logger()->info()/warning()` mit Source `'spreadconnect-failure'` bei Resolve-Branches; KEIN `error_log()`.
- `FailedOpsActions::resolve()` MUSS Try-Catch um WC-Order-Mutation wrappen (`\Throwable`); Fehler -> `wp_send_json_error(['code' => 'wc_mutation_failed'], 500)` statt PHP-Fatal.
- Op-Type-zu-AS-Hook-Mapping ist eine `private const` (kein DI-Container) — die 9 Eintraege aus Slice 37 AC-9 invertiert.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Failure/FailedOpsRepo.php` (Slice 37) | **Wiederverwendet, unveraendert.** View nutzt `findAll`, AJAX-Handler nutzen `findById`, `markResolved`, `markDismissed`. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Sidebar.php` (Slice 13) | **Wiederverwendet, unveraendert.** `Hub\View\FailedOps::render()` ruft `Sidebar::render('failed')` auf. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/Controller.php` (Slice 13) | **Wiederverwendet, unveraendert.** Routing `?section=failed` -> `Hub\View\FailedOps::render()` ist bereits in der Slice-13-AC-3-Tabelle definiert. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + ff.) | **Edit, nicht ersetzen.** Bestehende `init()`-Logik bleibt unveraendert; Slice 38 ergaenzt `$failedOpsActions->register()` nach der Slice-37-Repo-Instanziierung. |

**Referenzen:**
- Architecture: `architecture.md` -> "Internal Admin AJAX" Z. 144-146 — autoritative Beschreibung der drei AJAX-Actions inkl. Refusal-Regel fuer `create_order`.
- Architecture: `architecture.md` -> "Action Scheduler — Hook Inventory" Z. 542-556 — Op-Type-zu-AS-Hook-Mapping (Slice 38 invertiert es fuer Resend-Re-Enqueue).
- Architecture: `architecture.md` -> "WC-Order Meta (HPOS)" Z. 305-315 — Pflicht zu `update_meta_data() + save()` fuer `_spreadconnect_order_id` + `_spreadconnect_state`.
- Wireframes: `wireframes.md` -> Screen 5 "Failed Operations" Z. 397-498 — vollstaendiges Layout, State-Variations (`empty`, `dismiss_modal_create_order_open`, `dismiss_resolve_manual_input`), Modal-ASCII Z. 478-498.
- Discovery: `discovery.md` -> "Failure Handling — Dismiss-Semantik (per Op-Type)" Z. 631-639 — autoritative Spezifikation der drei Resolution-Choices + Refund-Behavior.
- Slim-Slices: `slices/slim-slices.md` -> Slice-38-Eintrag Z. 570-578 — Done-Signal: Plain-Dismiss `create_order` zeigt 3-Choice-Modal; Server refusiert plain-Dismiss; `submitted_externally` schreibt `_spreadconnect_order_id` + State `NEW`.
- Slice 37: `slices/slice-37-failed-ops-repo.md` -> Provides-Section + AC-9 Op-Type-Tabelle — Source of Truth fuer die 9 Op-Types und das Repo-Interface.
- Slice 13: `slices/slice-13-hub-page-skeleton.md` -> AC-3 Section-Routing-Tabelle — `?section=failed` -> `Hub\View\FailedOps::render()`.
