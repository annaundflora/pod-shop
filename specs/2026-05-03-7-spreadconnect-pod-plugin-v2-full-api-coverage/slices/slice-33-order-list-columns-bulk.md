# Slice 33: Order-List-Columns + Filter + Bulk-Action (HPOS + Legacy)

> **Slice 33 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-33-order-list-columns-bulk` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-32-order-meta-box"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WC 10.5 HPOS + Action Scheduler + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: HPOS `wp-admin/admin.php?page=wc-orders` UND Legacy `edit.php?post_type=shop_order` zeigen Spalten "SC-State"/"SC-OrderID", Filter-Dropdown, Bulk-Eintrag "Re-send to Spreadconnect") |
| **Health Endpoint** | `n/a` (Inline-Admin-List-Adapter, kein HTTP-Endpoint) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `add_filter`, `add_action`, `current_user_can`, `selected`, `esc_*`, `__`, `wc_get_orders`, `wc_get_order`; HPOS-`OrdersTableQuery`-Filter via direkte Closure-Invocation der registrierten Hooks; `Failure\BulkResendCoordinator` per Mockery-Mock injiziert; Legacy-`pre_get_posts` analog) |

---

## Ziel

Erweitert die WC-Order-Liste auf BEIDEN Hook-Sets (Legacy `manage_edit-shop_order_columns` + HPOS `manage_woocommerce_page_wc-orders_columns`) um zwei Spalten ("SC-State" Badge, "SC-OrderID" Link), ein Filter-Dropdown `?sc_state=…`, einen Bulk-Action-Eintrag "Re-send to Spreadconnect" und das Pre-Flight-Outcome-Panel `bulk_resend_outcome_panel`. Slice 33 liefert die Adapter-Surface; die volle Bulk-Resend-Implementierung (per-row outcome, AS-Schedules, Failed-Ops-Wiring) folgt in Slice 40.

---

## Bulk-Action-Lifecycle (Single Source of Truth fuer diese Slice)

> Architecture-Quelle: `architecture.md` Z. 391, 397, 641, 821; Wireframes: `Screen 12` Z. 999-1051; Discovery: Z. 100-101, 455.

| Phase | Hook / Endpoint | Verantwortlich | Verhalten in Slice 33 |
|---|---|---|---|
| Bulk-Eintrag registrieren | `bulk_actions-edit-shop_order` (Legacy) + `bulk_actions-woocommerce_page_wc-orders` (HPOS) | `Inline\OrderListColumns::registerBulkAction()` | Ergaenzt Eintrag `'spreadconnect_bulk_resend' => __('Re-send to Spreadconnect', 'spreadconnect-pod')`. |
| Pre-Flight-Count (vor Apply) | AJAX `spreadconnect_bulk_resend_preflight` (POST `order_ids[]`, `_ajax_nonce`) | `Inline\OrderListColumns::handlePreflightAjax()` | Cap+Nonce-Gate, delegiert an `BulkResendCoordinator::preflight(int[] $order_ids): array` -> liefert `{will_resend, will_skip}`. Response `{ok, will_resend, will_skip, eligible_ids}`. |
| Bulk-Apply (Klick auf [Apply]) | `handle_bulk_actions-edit-shop_order` (Legacy) + `handle_bulk_actions-woocommerce_page_wc-orders` (HPOS) | `Inline\OrderListColumns::handleBulkAction()` | Slice 33 = **Stub**: delegiert an `BulkResendCoordinator::run(int[] $order_ids): array`; setzt Redirect-Query `?bulk_resend_summary=…`. Volle Per-Row-Outcome-Logik = Slice 40. |
| Outcome-Panel | `admin_notices` (auf Order-List-Screen) | `Inline\OrderListColumns::renderOutcomePanel()` | Liest Redirect-Query `bulk_resend_summary`; rendert Banner `"X of Y re-queued · Z skipped"` (siehe `bulk_resend_outcome_panel` in wireframes.md Z. 455). Refused-Banner bei `0 eligible`. |

> **Resend-Job-Hook** (`spreadconnect/create_order` per `as_enqueue_async_action` aus Slice 28) wird in Slice 33 **nicht** direkt aufgerufen — `BulkResendCoordinator::run()` ist Pre-Flight-Stub. Slice 40 liefert Schedule-Wiring + Per-Row-Outcome.

---

## Acceptance Criteria

1) **AC-Columns-DualHook-Registration — Spalten auf HPOS + Legacy**
   **GIVEN** WP-Admin laedt entweder HPOS-Order-List `wp-admin/admin.php?page=wc-orders` oder Legacy `edit.php?post_type=shop_order`
   **WHEN** der jeweilige Columns-Filter feuert (`manage_woocommerce_page_wc-orders_columns` HPOS bzw. `manage_edit-shop_order_columns` Legacy)
   **THEN** ergaenzt `Inline\OrderListColumns::registerColumns(array $columns): array` zwei neue Spalten in fester Reihenfolge nach `order_status` (Fallback: vor `order_date` falls `order_status` fehlt): `sc_state` (Header `__('SC-State', 'spreadconnect-pod')`) und `sc_order_id` (Header `__('SC-OrderID', 'spreadconnect-pod')`). Bestehende WC-Spalten bleiben unveraendert. Returnwert ist das gemergte Columns-Array. Beide Hook-Sets (HPOS und Legacy) greifen auf **dieselbe** Methode (Architecture Z. 641 — Single-Adapter-Pattern).

2) **AC-Render-StateColumn — Badge mit Klasse je State**
   **GIVEN** WC-Order mit Order-Meta `_spreadconnect_state='NEW'` (alternativ einer der 6 persistenten States laut Architecture Z. 310: `submitting|NEW|CONFIRMED|PROCESSED|CANCELLED|failed_to_submit`; abwesendes Meta -> Pseudo-State `pending`)
   **WHEN** `Inline\OrderListColumns::renderColumn(string $column, int $order_id): void` mit `column='sc_state'` feuert
   **THEN** echo'd genau ein `<span data-state="NEW" class="spreadconnect-state-badge spreadconnect-state-NEW">NEW</span>`. Klassen-Mapping deckt alle 7 Werte ab (`pending|submitting|NEW|CONFIRMED|PROCESSED|CANCELLED|failed_to_submit`); Label fuer `failed_to_submit` ist `__('FAILED', 'spreadconnect-pod')` (Wireframe Z. 1016). `_spreadconnect_needs_action='1'` wird zusaetzlich als `data-needs-action="true"` und CSS-Klasse `spreadconnect-state-needs-action` an dem **selben** Span exponiert (orthogonaler Flag laut Architecture Z. 311). Output via `esc_attr()`/`esc_html()` escaped.

3) **AC-Render-OrderIdColumn — External-Link oder Dash**
   **GIVEN** WC-Order mit `_spreadconnect_order_id='sc_77194'`
   **WHEN** `renderColumn` mit `column='sc_order_id'` feuert
   **THEN** echo'd ein `<a target="_blank" rel="noopener noreferrer" href="…">SC-sc_77194 ↗</a>`-Link. Bei abwesendem Meta echo'd der Handler `'—'` (Wireframe Z. 1016 erste Zeile). `↗`-Affordance laut Wireframe Z. 1025/1034. Konkrete Backend-URL ist nicht fest verdrahtet (`architecture.md` definiert keinen SC-Backend-URL-Pattern); Implementer liest `Option `spreadconnect_sc_backend_url_template'` mit Default `'#'` (Filter-Hookable via `apply_filters('spreadconnect/sc_backend_order_url', $url, $sc_order_id)`).

4) **AC-Sortable-Columns — Beide Spalten sortable auf beiden Hook-Sets**
   **GIVEN** Hook `manage_edit-shop_order_sortable_columns` (Legacy) bzw. `manage_woocommerce_page_wc-orders_sortable_columns` (HPOS) feuert
   **WHEN** `Inline\OrderListColumns::registerSortableColumns(array $columns): array` greift
   **THEN** sind `sc_state` und `sc_order_id` als sortable markiert mit Sort-Keys `sc_state` und `sc_order_id`. Bestehende WC-Sortable-Columns bleiben unveraendert. Beide Hook-Sets greifen auf **dieselbe** Methode (Wireframe Z. 1035).

5) **AC-Sorting-Apply — `?orderby=sc_state` setzt Meta-Sort auf beiden Backends**
   **GIVEN** Order-List wird mit `?orderby=sc_state&order=ASC` aufgerufen
   **WHEN** auf Legacy: Hook `pre_get_posts` mit `$query->is_main_query() && is_admin() && $query->get('post_type')==='shop_order'`; auf HPOS: Hook `woocommerce_order_query_args` (oder `woocommerce_order_list_table_prepare_items_query_args` — Implementer-Wahl, sofern HPOS-Args mit `meta_key`/`orderby` parametriert werden)
   **THEN** setzt `Inline\OrderListColumns::applySorting()` `meta_key='_spreadconnect_state'` und `orderby='meta_value'`. Fuer `orderby=sc_order_id` analog `meta_key='_spreadconnect_order_id'`. Auf Non-Admin-Screens, anderen Post-Types oder Sub-Queries -> NO-OP.

6) **AC-FilterDropdown-Render — `?sc_state` Dropdown auf beiden Backends**
   **GIVEN** Hook `restrict_manage_posts` mit `$post_type='shop_order'` (Legacy) ODER HPOS-Aequivalent `woocommerce_order_list_table_restrict_manage_orders` (Implementer prueft tatsaechlichen WC-Hook-Namen — Architecture Z. 821 verweist auf `analogues` ohne festen Namen) feuert
   **WHEN** `Inline\OrderListColumns::renderFilterDropdown(string $post_type_or_screen): void` greift
   **THEN** echo'd ein `<select name="sc_state">`-Element mit acht Optionen: `''` (Label `__('SC-State: All', 'spreadconnect-pod')`), `'pending'`, `'NEW'`, `'CONFIRMED'`, `'PROCESSED'`, `'CANCELLED'`, `'failed_to_submit'` (Label `__('FAILED', 'spreadconnect-pod')`), `'needs_action'` (Label `__('NEEDS-ACTION', 'spreadconnect-pod')`). Aktueller Wert via `selected($_GET['sc_state'] ?? '', $optionValue)`. Andere Screens -> NO-OP (Early-Return).

7) **AC-FilterApply-Legacy — `?sc_state=NEW` filtert via meta_query**
   **GIVEN** Admin klickt `[Filter]` auf Legacy-Order-List mit `?sc_state=NEW`
   **WHEN** `Inline\OrderListColumns::applyFilter()` im `pre_get_posts`-Hook greift
   **THEN** setzt der Handler `$query->set('meta_query', [[ 'key'=>'_spreadconnect_state', 'value'=>'NEW', 'compare'=>'=' ]])` (existierende `meta_query`-Eintraege via `array_merge` mit `'relation'=>'AND'` erhalten). Fuer `sc_state=needs_action` wird `key='_spreadconnect_needs_action', value='1', compare='='`. Fuer `sc_state='pending'` wird `key='_spreadconnect_state', compare='NOT EXISTS'` (Order war nie submitted). Server filtert die Query — kein Client-side-Filter, kein raw `$wpdb`-Join.

8) **AC-FilterApply-HPOS — `?sc_state=NEW` filtert auf HPOS-Backend**
   **GIVEN** Admin klickt `[Filter]` auf HPOS-Order-List mit `?sc_state=NEW`
   **WHEN** der HPOS-Filter-Hook greift (Implementer-Wahl: `woocommerce_order_query_args` oder `woocommerce_order_list_table_prepare_items_query_args` — siehe AC-5; muss in `wc_get_orders`-Args resultieren)
   **THEN** ergaenzt der Handler die Args um `'meta_query' => [[ 'key'=>'_spreadconnect_state', 'value'=>'NEW', 'compare'=>'=' ]]`. Done-Signal aus slim-slices.md verlangt: "Filter `?sc_state=NEW` filtert korrekt" — d. h. resultierende `OrdersTableQuery` enthaelt nur Orders mit Meta `_spreadconnect_state='NEW'`. Pseudo-States `pending`/`needs_action` analog zu AC-7.

9) **AC-BulkAction-Registration — Eintrag auf beiden Hook-Sets**
   **GIVEN** Hook `bulk_actions-edit-shop_order` (Legacy) bzw. `bulk_actions-woocommerce_page_wc-orders` (HPOS) feuert
   **WHEN** `Inline\OrderListColumns::registerBulkAction(array $actions): array` greift
   **THEN** wird der Eintrag `'spreadconnect_bulk_resend' => __('Re-send to Spreadconnect', 'spreadconnect-pod')` ans Ende des Arrays appended. Bestehende WC-Bulk-Actions bleiben unveraendert. Beide Hook-Sets greifen auf **dieselbe** Methode.

10) **AC-Preflight-Ajax — Coordinator liefert `{will_resend, will_skip}`**
    **GIVEN** AJAX-Request `spreadconnect_bulk_resend_preflight` mit POST `order_ids=[7, 8, 9]`, valider Nonce (`spreadconnect_admin`), Cap `manage_woocommerce`. Order 7 Meta `_spreadconnect_state='failed_to_submit'`, Order 8 Meta `_spreadconnect_state='NEW'`, Order 9 ohne SC-Meta.
    **WHEN** `Inline\OrderListColumns::handlePreflightAjax()` ausgefuehrt wird
    **THEN** (a) Cap+Nonce-Reihenfolge identisch zu Slice 32 AC-7 (Nonce zuerst -> 403, dann Cap -> 403, dann Param-Validation -> 400 bei fehlendem `order_ids`); (b) delegiert an `Failure\BulkResendCoordinator::preflight(array $order_ids): array` und gibt das Result via `wp_send_json_success([...])` zurueck; (c) Coordinator liefert `['will_resend' => 1, 'will_skip' => 2, 'eligible_ids' => [7], 'skipped' => [8 => 'not_failed', 9 => 'never_submitted']]` (`failed_to_submit` ist die einzige eligible-State-Kategorie laut Wireframe Z. 1037).

11) **AC-BulkResendCoordinator-Preflight-PureRead — Kein API-Call, kein AS-Schedule**
    **GIVEN** Eingabe `int[] $order_ids` an `Failure\BulkResendCoordinator::preflight()`
    **WHEN** die Methode ausgefuehrt wird
    **THEN** ruft sie ausschliesslich `wc_get_order($id)->get_meta('_spreadconnect_state')` pro ID (kein `SpreadconnectClient`-Call, kein `as_enqueue_async_action`, kein `FailedOpsRepo`-Write). Returntyp: `array{will_resend:int, will_skip:int, eligible_ids:int[], skipped:array<int,string>}` mit `skipped[$id]` ∈ `{'not_failed','never_submitted','order_missing'}`. Methode ist seiteneffektfrei (nur DB-Reads).

12) **AC-BulkResendCoordinator-Run-Stub — Liefert Stub-Summary, KEIN Schedule**
    **GIVEN** Eingabe `int[] $order_ids` an `Failure\BulkResendCoordinator::run()`
    **WHEN** die Methode ausgefuehrt wird
    **THEN** ruft sie intern `preflight()`, gibt `array{queued:int, skipped:int, run_id:string, per_row:array<int,string>}` zurueck mit `queued=0` und `per_row[$id]='deferred_to_slice_40'` fuer **alle** eligible IDs. **Kein** `as_enqueue_async_action('spreadconnect/create_order', ...)`-Call. Slice 33 implementiert nur die Coordinator-Surface; Slice 40 ersetzt diesen Body durch echten Per-Row-Schedule-Pfad.

13) **AC-BulkApply-Refused-OnZeroEligible — Banner bei Zero-Eligible**
    **GIVEN** Bulk-Klick `[Apply]` mit Selection `[8, 9]` (keine `failed_to_submit`-Order)
    **WHEN** `Inline\OrderListColumns::handleBulkAction(string $redirect_url, string $action, array $order_ids): string` ausgefuehrt wird (WC-Filter-Signatur)
    **THEN** delegiert an `BulkResendCoordinator::run([8, 9])` -> `queued=0`, schreibt `bulk_resend_summary=no_eligible` in den Redirect-URL via `add_query_arg()`, returned Redirect-URL. Bei `queued > 0` wird `bulk_resend_summary=ok&queued=N&skipped=M&run_id=…` geschrieben (Wireframe State `bulk_action_no_eligible` Z. 1049).

14) **AC-OutcomePanel-Render — Banner aus Redirect-Query**
    **GIVEN** Order-List wird mit `?bulk_resend_summary=ok&queued=2&skipped=8` geladen
    **WHEN** `Inline\OrderListColumns::renderOutcomePanel(): void` im `admin_notices`-Hook greift (mit Screen-Filter laut AC-15)
    **THEN** echo'd genau ein `<div class="notice notice-info spreadconnect-bulk-outcome" data-panel="bulk_resend_outcome_panel">` mit Text `__('2 of 10 re-queued · 8 skipped (not in FAILED state)', 'spreadconnect-pod')` (Wireframe `bulk_action_complete` Z. 1048; `__()` mit `_n()` plural fuer Counts). Bei `bulk_resend_summary=no_eligible` -> Banner-Variante `notice-warning` mit `__('Re-send needs at least one FAILED order in the selection.', 'spreadconnect-pod')` (Wireframe `bulk_action_no_eligible` Z. 1049). Output via `esc_html()` escaped; `[Show details ▾]`-Toggle ist Slice 40 (statisches Markup hier akzeptabel, JS deferred).

15) **AC-AssetEnqueue-Localize-Bound-To-Screen — JS nur auf Order-List-Screen**
    **GIVEN** `admin_enqueue_scripts`-Hook feuert
    **WHEN** der aktive Screen `wc_get_page_screen_id('shop-order')` (HPOS) ODER `'edit-shop_order'` (Legacy)
    **THEN** registriert `Inline\OrderListColumns::enqueueAssets(string $hook_suffix): void` Handle `'spreadconnect-order-list'` (Dependencies `['jquery']`, `in_footer=true`) mit der Datei `assets/js/order-list.js` und `wp_localize_script(..., 'SpreadconnectOrderList', [...])` mit mindestens: `ajaxUrl`, `nonce` (`wp_create_nonce('spreadconnect_admin')`), `actions.preflight='spreadconnect_bulk_resend_preflight'`, `bulkActionSlug='spreadconnect_bulk_resend'`, `i18n` (Pre-Flight-Text-Templates `__('Will re-send: %d · Will skip: %d', ...)`). Auf anderen Screens -> NO-OP.

16) **AC-Hook-Wiring — Bootstrap registriert alle Hooks idempotent**
    **GIVEN** Plugin-Boot
    **WHEN** `Bootstrap\Plugin::init()` durchlaeuft
    **THEN** sind die folgenden Hooks via `add_filter`/`add_action` registriert: (a) `manage_edit-shop_order_columns` + `manage_woocommerce_page_wc-orders_columns` -> `registerColumnsStatic`; (b) `manage_shop_order_posts_custom_column` + HPOS-Render-Hook (Implementer-Wahl: `manage_woocommerce_page_wc-orders_custom_column` — Architecture-Phase) -> `renderColumnStatic`; (c) `manage_edit-shop_order_sortable_columns` + `manage_woocommerce_page_wc-orders_sortable_columns` -> `registerSortableColumnsStatic`; (d) `pre_get_posts` + HPOS-Order-Query-Args-Filter -> `applySortingAndFilterStatic` (eine Closure haendelt AC-5/AC-7/AC-8); (e) `restrict_manage_posts` + HPOS-Aequivalent -> `renderFilterDropdownStatic`; (f) `bulk_actions-edit-shop_order` + `bulk_actions-woocommerce_page_wc-orders` -> `registerBulkActionStatic`; (g) `handle_bulk_actions-edit-shop_order` + `handle_bulk_actions-woocommerce_page_wc-orders` -> `handleBulkActionStatic`; (h) `admin_notices` -> `renderOutcomePanelStatic`; (i) `admin_enqueue_scripts` -> `enqueueAssetsStatic`; (j) `wp_ajax_spreadconnect_bulk_resend_preflight` -> `handlePreflightAjaxStatic` (kein `nopriv`). Doppelter `init()`-Aufruf fuegt nicht doppelt hinzu (`has_action()`-Idempotenz analog Slice 32 AC-14).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Drei PHPUnit-Test-Files: `slice-08-order-list-columns.php` (AC-1..AC-9, AC-13..AC-16), `slice-08-order-list-bulk-preflight.php` (AC-10..AC-12, AC-14), `slice-08-order-list-cap-nonce.php` (AC-10 Cap+Nonce-Pfad). Brain\Monkey-Setup analog Slice 32; HPOS-Filter-Closure-Invocation auf `WC_Order_Query`-Mock; `Failure\BulkResendCoordinator` per Konstruktor-Mockery in `OrderListColumns` injiziert. Markup-Asserts auf `data-state`/`data-panel`-Attribute, **nicht** auf CSS-Pixel. JS (`order-list.js`) wird **nicht** unit-getestet — Done-Signal manuelle QA.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-08-order-list-columns.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests\Inline;

use PHPUnit\Framework\TestCase;

final class OrderListColumnsTest extends TestCase
{
    // AC-1: Dual-Hook-Spalten-Registration
    public function test_register_columns_inserts_sc_state_and_sc_order_id_after_order_status_on_hpos(): void { $this->markTestIncomplete('AC-1'); }
    public function test_register_columns_inserts_two_columns_on_legacy_hook_set(): void { $this->markTestIncomplete('AC-1'); }
    public function test_register_columns_falls_back_to_before_order_date_when_order_status_missing(): void { $this->markTestIncomplete('AC-1'); }

    // AC-2: State-Badge-Render mit Klasse + needs_action-Flag
    public function test_render_sc_state_emits_badge_with_data_state_and_class(): void { $this->markTestIncomplete('AC-2'); }
    public function test_render_sc_state_maps_failed_to_submit_to_failed_label(): void { $this->markTestIncomplete('AC-2'); }
    public function test_render_sc_state_appends_needs_action_flag_class(): void { $this->markTestIncomplete('AC-2'); }
    public function test_render_sc_state_pending_when_meta_absent(): void { $this->markTestIncomplete('AC-2'); }

    // AC-3: SC-OrderID-Link-Render
    public function test_render_sc_order_id_emits_external_link_when_meta_present(): void { $this->markTestIncomplete('AC-3'); }
    public function test_render_sc_order_id_emits_dash_when_meta_missing(): void { $this->markTestIncomplete('AC-3'); }
    public function test_render_sc_order_id_url_filter_hook_is_applied(): void { $this->markTestIncomplete('AC-3'); }

    // AC-4: Sortable-Columns
    public function test_register_sortable_columns_marks_sc_state_and_sc_order_id_sortable_on_both_hooks(): void { $this->markTestIncomplete('AC-4'); }

    // AC-5: Sorting-Apply (Legacy + HPOS)
    public function test_apply_sorting_sets_meta_key_for_sc_state_orderby_on_legacy(): void { $this->markTestIncomplete('AC-5'); }
    public function test_apply_sorting_sets_meta_key_for_sc_order_id_on_hpos(): void { $this->markTestIncomplete('AC-5'); }
    public function test_apply_sorting_noop_for_non_admin_or_non_main_query(): void { $this->markTestIncomplete('AC-5'); }

    // AC-6: Filter-Dropdown-Render
    public function test_render_filter_dropdown_emits_select_with_eight_options(): void { $this->markTestIncomplete('AC-6'); }
    public function test_render_filter_dropdown_marks_current_value_selected(): void { $this->markTestIncomplete('AC-6'); }
    public function test_render_filter_dropdown_skips_unrelated_post_types(): void { $this->markTestIncomplete('AC-6'); }

    // AC-7: Filter-Apply Legacy
    public function test_apply_filter_legacy_sets_meta_query_for_sc_state_new(): void { $this->markTestIncomplete('AC-7'); }
    public function test_apply_filter_legacy_sets_compare_not_exists_for_pending(): void { $this->markTestIncomplete('AC-7'); }
    public function test_apply_filter_legacy_sets_meta_query_for_needs_action(): void { $this->markTestIncomplete('AC-7'); }
    public function test_apply_filter_legacy_preserves_existing_meta_query_with_and_relation(): void { $this->markTestIncomplete('AC-7'); }

    // AC-8: Filter-Apply HPOS
    public function test_apply_filter_hpos_returns_args_with_sc_state_meta_query(): void { $this->markTestIncomplete('AC-8'); }
    public function test_apply_filter_hpos_respects_pending_pseudo_state(): void { $this->markTestIncomplete('AC-8'); }

    // AC-9: Bulk-Action-Registration
    public function test_register_bulk_action_appends_resend_entry_on_hpos_hook(): void { $this->markTestIncomplete('AC-9'); }
    public function test_register_bulk_action_appends_resend_entry_on_legacy_hook(): void { $this->markTestIncomplete('AC-9'); }

    // AC-13: Bulk-Apply-Refused
    public function test_handle_bulk_action_redirects_with_no_eligible_summary_when_zero_failed(): void { $this->markTestIncomplete('AC-13'); }
    public function test_handle_bulk_action_redirects_with_ok_summary_and_run_id_on_eligible_selection(): void { $this->markTestIncomplete('AC-13'); }

    // AC-14: Outcome-Panel-Render
    public function test_render_outcome_panel_emits_info_notice_with_counts(): void { $this->markTestIncomplete('AC-14'); }
    public function test_render_outcome_panel_emits_warning_notice_for_no_eligible(): void { $this->markTestIncomplete('AC-14'); }
    public function test_render_outcome_panel_skips_when_summary_query_absent(): void { $this->markTestIncomplete('AC-14'); }

    // AC-15: Asset-Enqueue
    public function test_enqueue_assets_registers_script_only_on_order_list_screens(): void { $this->markTestIncomplete('AC-15'); }
    public function test_enqueue_assets_localizes_ajax_url_nonce_and_actions(): void { $this->markTestIncomplete('AC-15'); }

    // AC-16: Bootstrap-Wiring
    public function test_plugin_init_registers_all_dual_hook_pairs(): void { $this->markTestIncomplete('AC-16'); }
    public function test_plugin_init_registers_preflight_ajax_hook(): void { $this->markTestIncomplete('AC-16'); }
    public function test_plugin_init_hook_registration_is_idempotent(): void { $this->markTestIncomplete('AC-16'); }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-08-order-list-bulk-preflight.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests\Failure;

use PHPUnit\Framework\TestCase;

final class BulkResendCoordinatorPreflightTest extends TestCase
{
    // AC-10: Preflight-AJAX delegiert + sendet JSON
    public function test_handle_preflight_ajax_returns_will_resend_and_will_skip_counts(): void { $this->markTestIncomplete('AC-10'); }
    public function test_handle_preflight_ajax_returns_eligible_ids_array(): void { $this->markTestIncomplete('AC-10'); }
    public function test_handle_preflight_ajax_returns_skipped_reason_map(): void { $this->markTestIncomplete('AC-10'); }

    // AC-11: Coordinator::preflight ist seiteneffektfrei
    public function test_preflight_calls_only_wc_get_order_get_meta(): void { $this->markTestIncomplete('AC-11'); }
    public function test_preflight_does_not_invoke_spreadconnect_client(): void { $this->markTestIncomplete('AC-11'); }
    public function test_preflight_does_not_call_as_enqueue_async_action(): void { $this->markTestIncomplete('AC-11'); }
    public function test_preflight_classifies_failed_to_submit_as_eligible(): void { $this->markTestIncomplete('AC-11'); }
    public function test_preflight_classifies_new_state_as_not_failed(): void { $this->markTestIncomplete('AC-11'); }
    public function test_preflight_classifies_missing_meta_as_never_submitted(): void { $this->markTestIncomplete('AC-11'); }
    public function test_preflight_classifies_missing_order_as_order_missing(): void { $this->markTestIncomplete('AC-11'); }

    // AC-12: Coordinator::run ist Stub fuer Slice 40
    public function test_run_returns_zero_queued_with_deferred_marker(): void { $this->markTestIncomplete('AC-12'); }
    public function test_run_does_not_schedule_create_order_actions(): void { $this->markTestIncomplete('AC-12'); }
    public function test_run_returns_run_id_for_outcome_correlation(): void { $this->markTestIncomplete('AC-12'); }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-08-order-list-cap-nonce.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests\Inline;

use PHPUnit\Framework\TestCase;

final class OrderListPreflightCapNonceTest extends TestCase
{
    // AC-10: Cap+Nonce-Gate fuer Preflight-AJAX
    public function test_handle_preflight_returns_403_on_invalid_nonce(): void { $this->markTestIncomplete('AC-10'); }
    public function test_handle_preflight_returns_403_when_user_lacks_manage_woocommerce(): void { $this->markTestIncomplete('AC-10'); }
    public function test_handle_preflight_returns_400_on_missing_or_empty_order_ids(): void { $this->markTestIncomplete('AC-10'); }
    public function test_handle_preflight_does_not_call_coordinator_on_failed_gate(): void { $this->markTestIncomplete('AC-10'); }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-32-order-meta-box` | Nonce-Action `'spreadconnect_admin'`, Cap-Convention `manage_woocommerce`, AJAX-Front-Controller-Pattern | shared convention | Slice 33 spiegelt die Cap+Nonce-Reihenfolge aus Slice 32 AC-7. |
| `slice-27-order-state-machine` | Order-Meta `_spreadconnect_state` enum + Convention `_spreadconnect_needs_action`/`_spreadconnect_order_id` | postmeta keys | Architecture Z. 309-311. |
| `slice-28-order-submit-job` | AS-Hook `'spreadconnect/create_order'` (Group `'spreadconnect'`, Args `['order_id'=>int]`) | string-literal | Slice 33 referenziert den Hook nur in `BulkResendCoordinator::run()` als Schedule-Ziel — Body ist Stub (AC-12). Voller Schedule kommt in Slice 40. |
| `slice-04-schema-dbdelta` | WC-Order-Tables `wc_orders` + `wc_orders_meta` (HPOS) bzw. `wp_posts` + `wp_postmeta` (Legacy) | DDL | Standard-WC-HPOS, kein Custom-Table. |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::init()` Hook-Lifecycle | Bootstrap | Edit-Target: ergaenzt Hook-Registrierungen aus AC-16. |
| WordPress / WooCommerce | `add_filter`/`add_action`, `wc_get_order`, `wc_get_orders`, `wc_get_page_screen_id`, `selected`, `__`/`_n`, `wp_register_script`/`wp_localize_script`, `check_ajax_referer`, `current_user_can`, `wp_send_json_*`, `add_query_arg`, `admin_notices`, HPOS-Hooks `manage_woocommerce_page_wc-orders_columns`/`_sortable_columns`/`_custom_column`, `bulk_actions-woocommerce_page_wc-orders`, `handle_bulk_actions-woocommerce_page_wc-orders`, HPOS-Order-Query-Filter (Implementer prueft konkreten Hook-Namen) | WP/WC-API | HPOS-Default seit WC 8.2 (Architecture Z. 818, 821). |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `Failure\BulkResendCoordinator::preflight(int[] $order_ids): array` | Public method | `slice-40-bulk-resend-coordinator` (ersetzt `run()`-Body, `preflight()`-Vertrag bleibt stabil) | Returns `array{will_resend:int, will_skip:int, eligible_ids:int[], skipped:array<int,string>}`. |
| `Failure\BulkResendCoordinator::run(int[] $order_ids): array` | Public method (Stub in Slice 33) | `slice-40-bulk-resend-coordinator` | Returns `array{queued:int, skipped:int, run_id:string, per_row:array<int,string>}`. Slice 33 = `queued=0, per_row[$id]='deferred_to_slice_40'`. |
| AJAX-Action `spreadconnect_bulk_resend_preflight` (string-literal) | wp_ajax-action-name | (intern; nur durch JS aus `assets/js/order-list.js` aufgerufen) | POST `{order_ids[], _ajax_nonce}` -> `{ok, will_resend, will_skip, eligible_ids, skipped}`. |
| Bulk-Action-Slug `'spreadconnect_bulk_resend'` | string-literal | `slice-40-bulk-resend-coordinator` (erweitert die `handleBulkAction`-Closure mit echtem Per-Row-Schedule) | Konvention via WC-Bulk-Actions-API. |
| `Inline\OrderListColumns` Class | Adapter (Admin-List) | (UI-Surface ist Endpunkt — keine Downstream-Slices) | Statische Bridges + Konstruktor-DI fuer `BulkResendCoordinator` analog Slice 32 / Slice 35. |
| Filter-Hook `'spreadconnect/sc_backend_order_url'` | apply_filters-Hook | (intern; Slice 41 Webhook-Log-UI kann denselben Filter konsumieren) | `apply_filters('spreadconnect/sc_backend_order_url', string $url, string $sc_order_id): string`. Default `'#'`. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Inline/OrderListColumns.php` — Neue `final class SpreadconnectPod\Inline\OrderListColumns`. Konstruktor injiziert `Failure\BulkResendCoordinator`. Public methods: `registerColumns(array): array`, `renderColumn(string, int): void`, `registerSortableColumns(array): array`, `applySorting(...)` + `applyFilter(...)` (Implementer entscheidet ob Closure oder zwei Methoden — eine `pre_get_posts`-Bruecke laut AC-16d), HPOS-Args-Filter-Methode (`applyOrderQueryArgs(array): array`), `renderFilterDropdown(string|int): void`, `registerBulkAction(array): array`, `handleBulkAction(string, string, array): string`, `renderOutcomePanel(): void`, `enqueueAssets(string): void`, `handlePreflightAjax(): void`. Static-Bridges (`registerColumnsStatic`, etc.) analog Slice 35 fuer Hook-Registrierung. Cap+Nonce-Reihenfolge in `handlePreflightAjax` identisch zu Slice 32 AC-7.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Failure/BulkResendCoordinator.php` — Neue `final class SpreadconnectPod\Failure\BulkResendCoordinator`. Public methods: `preflight(array $order_ids): array` (vollstaendig laut AC-11), `run(array $order_ids): array` (Stub laut AC-12 — `queued=0`, `per_row[$id]='deferred_to_slice_40'`). Konstruktor-DI-frei (oder leer); Slice 40 erweitert um `FailedOpsRepo`-Konstruktor + AS-Schedule-Pfad. Logging via `wc_get_logger()` Source `'spreadconnect-failure'`.
- [ ] `wordpress/plugins/spreadconnect-pod/assets/js/order-list.js` — Vanilla-JS / jQuery (WP-Admin-Convention). Verantwortet: (a) bei Bulk-Dropdown-Auswahl `'spreadconnect_bulk_resend'` Pre-Flight-AJAX an `SpreadconnectOrderList.actions.preflight` mit Selection-IDs; (b) rendert Pre-Flight-Banner ueber `[Apply]` mit `__('Will re-send: %d · Will skip: %d', ...)` (Wireframe `bulk_action_selected_with_ineligible` Z. 1047); (c) markiert ineligible Rows visuell `greyed`. Keine eigene Build-Pipeline.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — **Edit, nicht ersetzen.** In `init()` ergaenzt: alle Hook-Registrierungen aus AC-16 (Items a-j). Bestehende Hook-Registrierungen aus Slices 02..32 unveraendert. Ohne diesen Edit sind weder Spalten noch Bulk-Action sichtbar (Mount-Point-Pflicht).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt die drei `slice-08-order-list-*.php`-Files basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- KEIN echter `as_enqueue_async_action('spreadconnect/create_order', ...)`-Call in `BulkResendCoordinator::run()` — Slice 33 Body ist Stub mit `per_row[$id]='deferred_to_slice_40'` (AC-12). Slice 40 ersetzt den Body.
- KEIN `FailedOpsRepo`-Read fuer eligible-Detection in `preflight()` — Slice 33 nutzt ausschliesslich Order-Meta `_spreadconnect_state='failed_to_submit'`. `FailedOpsRepo` existiert erst ab Slice 37; Slice 40 kann wechseln.
- KEIN Per-Row-Outcome-Detail-Panel `[Show details ▾]`-JS-Toggle — Slice 33 rendert nur Banner-Counts (AC-14). JS-Expand kommt in Slice 40.
- KEIN `spreadconnect_resend_failed_op`-AJAX-Handler — Slice 38 (Order-List-Bulk hat eigenen Pfad).
- KEIN Bulk-Dismiss — nur Bulk-Resend (Wireframe Z. 1027). Bulk-Dismiss ist im Failed-Ops-UI Kontext (Slice 38/40).
- KEINE Spalten/Filter auf der nicht-Order-List (z. B. Hub `Hub\View\Orders` aus Slice 13) — die Hub-Sub-Page ist eine eigene UI-Surface (Discovery Z. 267).
- KEIN Build/Bundling fuer JS — Plain-JS direkt aus `assets/`.
- KEINE i18n-Strings in `.po`-Datei einpflegen — alle `__()`/`_n()`-Calls werden in Slice 46 extrahiert.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in beiden PHP-Files.
- `final class` fuer beide neuen Klassen.
- Single-Adapter-Pattern: **dieselbe** Methode wird auf BEIDEN Hook-Sets (HPOS + Legacy) registriert — kein duplicate code (Architecture Z. 641: "registers on both hook slugs in one bootstrap call").
- HPOS-Hook-Namen: Implementer prueft im WC-Source (`woocommerce_page_wc-orders_*` + `_legacy`-Variante; Architecture Z. 821 verweist auf "analogues"); falls HPOS-Filter-Hook fuer Order-Query-Args anders heisst (z. B. `woocommerce_order_list_table_prepare_items_query_args` statt `woocommerce_order_query_args`), Implementer waehlt den, der `wc_get_orders`-Args mutiert. AC-5/AC-8 sind tolerant gegenueber dem konkreten Hook-Namen.
- Cap+Nonce-Reihenfolge in `handlePreflightAjax`: (1) `check_ajax_referer('spreadconnect_admin', '_ajax_nonce', false)` -> false -> `wp_send_json_error([...], 403)`; (2) `current_user_can('manage_woocommerce')` -> false -> 403; (3) Param-Validation (`order_ids` muss non-empty `int[]`) -> false -> 400.
- Sanitization: `$_GET['sc_state']` via `sanitize_key()`; `order_ids` per `array_map('intval', ...)` + `array_filter(fn($id) => $id > 0)`.
- Order-Meta-Reads: ausschliesslich `wc_get_order($id)->get_meta('_spreadconnect_*')` (HPOS-konform, Architecture Z. 637) — KEIN `get_post_meta()` fuer Orders.
- Logging via `wc_get_logger()` Source `'spreadconnect-order-service'` fuer `Inline\OrderListColumns` und `'spreadconnect-failure'` fuer `Failure\BulkResendCoordinator` — kein `error_log()`. Tags: `bulk_resend_preflight`, `bulk_resend_run_stub`.
- Markup-Output via `printf()` mit `esc_html()`/`esc_attr()`/`esc_url()`-Wrappern; **keine** rohen Variablen-Interpolations in HTML.
- `apply_filters('spreadconnect/sc_backend_order_url', $url, $sc_order_id)` als Extension-Point fuer SC-Backend-URL; Default-URL-Quelle ist Option `spreadconnect_sc_backend_url_template` (Standard `'#'`).
- `bulk_resend_summary`-Query-Param wird im Outcome-Panel via `wp_unslash()` + `sanitize_key()` gelesen; Counts via `(int)`-Cast.
- Asset-Versionierung: Plugin-Version-Konstante als `wp_register_script(..., $version)`-Cache-Buster.
- Pseudo-State `pending` (kein Meta) in Filter-Apply: `meta_query` mit `compare='NOT EXISTS'` auf Key `_spreadconnect_state` (siehe AC-7).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02..32) | **Edit, nicht ersetzen.** Erweitert `init()` um die Hook-Registrierungen aus AC-16. Bestehende Hooks (HPOS-Declare, Schema-Activate, Order-Hooks aus Slice 28/29/32) bleiben unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/OrderActions.php` (Slice 32) | **Convention-Vorlage, unveraendert.** Slice 33 spiegelt die Cap+Nonce-Reihenfolge sowie Nonce-Action-Name `'spreadconnect_admin'` (AC-10). |
| `wordpress/plugins/spreadconnect-pod/includes/Inline/ProductListColumns.php` (Slice 35 — folgt) | **Konvention.** Slice 33 nutzt dasselbe Static-Bridges-Pattern + Konstruktor-DI fuer das Coordinator-Mock-Setup. |
| `wordpress/plugins/spreadconnect-pod/includes/Inline/OrderMetaBox.php` (Slice 32) | **Wiederverwendet, unveraendert.** Slice 33 fuegt Spalten + Bulk auf der Order-List hinzu — Meta-Box bleibt der Detail-Edit-Surface. |
| `wordpress/plugins/spreadconnect-pod/includes/Order/OrderHandler.php` (Slice 28/31) | **Wiederverwendet, unveraendert.** Bulk-Resend-Schedule (Slice 40) wird denselben `'spreadconnect/create_order'`-Hook re-enqueuen, den Slice 28 registriert hat. |

**Referenzen:**
- Architecture: `architecture.md` -> Z. 397 (`Inline\OrderListColumns` Service-Map-Eintrag).
- Architecture: `architecture.md` -> Z. 391 (`Failure\BulkResendCoordinator` Service-Map-Eintrag).
- Architecture: `architecture.md` -> Z. 309-311 (Order-Meta-Schema `_spreadconnect_state`/`_spreadconnect_order_id`/`_spreadconnect_needs_action`).
- Architecture: `architecture.md` -> Z. 637, 641, 821 (HPOS-Konformitaet + Dual-Hook-Sets-Pflicht).
- Architecture: `architecture.md` -> Z. 84 (Nonce-Action-Name `spreadconnect_admin`).
- Wireframes: `wireframes.md` -> `Screen 12` Z. 999-1051 (Layout, Annotations ①-⑦, State-Variations).
- Wireframes: `wireframes.md` -> Z. 455 (`bulk_resend_outcome_panel`-Component-Definition).
- Discovery: `discovery.md` -> Slice 8 "Inline UX (Product + Order)" Z. 100-101, 413-419 (Order-List-Spalten + Bulk-Action).
- Slim-Slices: `slices/slim-slices.md` -> Slice-33-Eintrag Z. 513-520 (Done-Signal: beide Hook-Sets registrieren Spalten; Filter `?sc_state=NEW` filtert; Pre-Flight liefert `{will_resend, will_skip}`).
- Slice 32: `slices/slice-32-order-meta-box.md` -> Cap+Nonce-Konvention (AC-7), Nonce-Action-Name `'spreadconnect_admin'`.
- Slice 35: `slices/slice-35-product-list-columns.md` -> Spalten-Filter-Sortable-Pattern als Vorbild fuer Slice 33.
- Slice 40: (folgt) — ersetzt `BulkResendCoordinator::run()`-Stub durch echten AS-Schedule + Per-Row-Outcome-Panel.
- Slice 38: (folgt) — `spreadconnect_resend_failed_op`-Handler fuer Failed-Ops-UI (nicht der Bulk-Resend-Pfad).
