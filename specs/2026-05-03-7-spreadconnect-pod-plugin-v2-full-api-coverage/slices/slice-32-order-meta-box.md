# Slice 32: Order-Edit-Meta-Box (Inline-UX)

> **Slice 32 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-32-order-meta-box` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-29-order-confirm-cancel-jobs", "slice-13-hub-page-skeleton"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WC 10.5 HPOS + Action Scheduler + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: HPOS Order-Edit `wp-admin/admin.php?page=wc-orders&action=edit&id={id}` UND Legacy `post.php?post={id}&action=edit`) |
| **Health Endpoint** | `n/a` (Adapter-Slice ohne eigenen REST-Endpoint) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `add_meta_box`, `wp_create_nonce`, `wp_verify_nonce`, `current_user_can`, `wp_send_json_*`, `check_ajax_referer`, `wc_get_order`, `as_enqueue_async_action`, `as_unschedule_action`; SpreadconnectClient + WebhookLogRepo als injizierte Mockery-Mocks; JS-Datei wird NICHT unit-getestet — manuelle QA gemaess Done-Signal) |

---

## Ziel

Liefert die WC-Order-Edit-Sidebar-Meta-Box als einzige UI-Surface fuer den manuellen Order-Lifecycle (Confirm/Cancel/Refresh/Resend/Shipping-Type-Save). Ein einziger Adapter (`Inline\OrderMetaBox`) registriert sowohl auf dem HPOS-Screen `woocommerce_page_wc-orders` als auch dem Legacy-`shop_order`-Screen, rendert State-Badge + Shipping-Type-Dropdown + Action-Buttons + Shipments-List + Webhook-Activity-Last-5, und ein gemeinsamer `Hub\Ajax\OrderActions`-Front-Controller bedient alle 5 Order-AJAX-Actions mit Cap+Nonce-Gate. Damit wird Discovery-Slice 8 "Inline UX (Order-Edit)" funktional komplett. Slice 33 ergaenzt anschliessend die Order-List-Spalten + Bulk-Action.

---

## AJAX-Actions-Tabelle (Single Source of Truth fuer diese Slice)

> Architecture-Quelle: `architecture.md` Z. 152-156. Alle 5 Handler liegen gemeinsam in `Hub\Ajax\OrderActions` (Front-Controller). Nonce-Action-Name: `'spreadconnect_admin'` (Architecture Z. 84).

| Action (string-literal) | Verb (POST-Body) | Capability | Nonce-Check | Effekt |
|---|---|---|---|---|
| `spreadconnect_confirm_order` | `order_id`, `_ajax_nonce` | `manage_woocommerce` | `check_ajax_referer('spreadconnect_admin')` | `as_enqueue_async_action('spreadconnect/confirm_order', ['order_id'=>$id], 'spreadconnect')` -> Slice-29 `OrderConfirmJob`; Response `{ok:true, queued:true}`. |
| `spreadconnect_cancel_order` | `order_id`, `_ajax_nonce` | `manage_woocommerce` | `check_ajax_referer('spreadconnect_admin')` | `as_enqueue_async_action('spreadconnect/cancel_order', ['order_id'=>$id], 'spreadconnect')` -> Slice-29 `OrderCancelJob`; Response `{ok:true, queued:true}`. |
| `spreadconnect_refresh_order_state` | `order_id`, `_ajax_nonce` | `manage_woocommerce` | `check_ajax_referer('spreadconnect_admin')` | Synchron: `SpreadconnectClient::getOrder($sc_id)` -> mappt Response auf Order-Meta (`_spreadconnect_state`, `_spreadconnect_last_event`); Response `{ok:true, state:string, last_event:string}`. |
| `spreadconnect_save_shipping_type` | `order_id`, `shipping_type`, `_ajax_nonce` | `manage_woocommerce` | `check_ajax_referer('spreadconnect_admin')` | Synchron: `SpreadconnectClient::setShippingType($sc_id, $type)` -> 2xx -> `$order->update_meta_data('_spreadconnect_shipping_type', $type)` + `$order->save()`; Response `{ok:true, shipping_type:string}`; 4xx -> `{ok:false, error:string}`. |
| `spreadconnect_cancel_auto_confirm` | `order_id`, `_ajax_nonce` | `manage_woocommerce` | `check_ajax_referer('spreadconnect_admin')` | `as_unschedule_action('spreadconnect/confirm_order', ['order_id'=>$id], 'spreadconnect')`; Response `{ok:true, unscheduled:true}`. |

> **Resend-Button** im Wireframe `Screen 11` ⑥ ruft die bereits in Slice 38 vorgesehene AJAX-Action `spreadconnect_resend_failed_op` (Architecture Z. 144) — **nicht** Teil dieser 5-Action-Tabelle. Slice 32 rendert nur das Markup + JS-Click-Hook; der Server-Handler ist Slice 38.

---

## Acceptance Criteria

1) **AC-MetaBox-Registration-Dual-Screens — HPOS + Legacy**
   **GIVEN** WP-Admin laedt einen Order-Edit-Screen
   **WHEN** der `add_meta_boxes`-Hook feuert
   **THEN** ruft `Inline\OrderMetaBox::register($screen_id)` `add_meta_box()` mit ID `'spreadconnect_order_meta_box'`, Title `'Spreadconnect'` (i18n via `__()`), Callback `[OrderMetaBox::class, 'render']`, Context `'side'`, Priority `'default'` auf BEIDE Screen-IDs auf: (a) HPOS-Screen `wc_get_page_screen_id('shop-order')` (siehe Architecture Z. 641 — typischerweise `'woocommerce_page_wc-orders'`); (b) Legacy-Screen `'shop_order'`. Auf anderen Screens (z. B. `'product'`) wird **kein** `add_meta_box` aufgerufen.

2) **AC-Render-StateBlock — State-Badge + SC-OrderID + Last-Action**
   **GIVEN** WC-Order mit `_spreadconnect_order_id = 'sc_77194'`, `_spreadconnect_state = 'NEW'`, `_spreadconnect_last_event = '1714657560:Order.created'`
   **WHEN** `OrderMetaBox::render($order_or_post)` aufgerufen wird (Callback-Signatur akzeptiert sowohl `\WC_Order` HPOS als auch `\WP_Post` Legacy — Adapter normalisiert via `wc_get_order()`)
   **THEN** enthaelt der gerenderte HTML-String genau ein Element mit `data-block="state"` und in dieser Reihenfolge: (a) escaped SC-OrderID `SC-sc_77194` mit External-Link `target="_blank"`; (b) State-Badge-Element mit `data-state="NEW"` und CSS-Klasse `spreadconnect-state-NEW`; (c) Last-Action-Timestamp im Format `H:i:s` (lokalisiert via `wp_date()`); (d) `[Refresh State]`-Button mit `data-action="refresh_order_state"` und `data-nonce`-Attribut. Alle dynamischen Strings durch `esc_html()`/`esc_attr()`/`esc_url()` escaped (vgl. Slice-13-Konvention).

3) **AC-Render-ShippingBlock — Lazy-Load-Dropdown**
   **GIVEN** WC-Order in State `NEW` mit `_spreadconnect_shipping_type = 'STANDARD'`
   **WHEN** `OrderMetaBox::render()` aufgerufen wird
   **THEN** rendert das Markup ein `<select data-block="shipping-type" data-loaded="false">` mit nur einer Default-Option (aktueller Wert `'STANDARD'` als Placeholder); ein `[Save Shipping-Type]`-Button mit `data-action="save_shipping_type"`. **Keine** `getShippingTypes()`-API-Call im Render-Pfad — die Dropdown-Optionen werden erst bei JS-Focus-Event ueber AJAX nachgeladen (Done-Signal: PHP-Render verursacht keinen `SpreadconnectClient`-Call). Bei State `CONFIRMED`/`PROCESSED`/`CANCELLED` ist das Block-Wrapping mit `data-disabled="true"` markiert (Wireframes-Variation Z. 989-991).

4) **AC-Render-ActionsBlock — State-abhaengige Buttons**
   **GIVEN** WC-Order in einem der 6 persistenten States laut Architecture Z. 310
   **WHEN** `render()` aufgerufen wird
   **THEN** ist die State-zu-Button-Sichtbarkeit gemaess Wireframes Z. 983-995: (a) State `NEW` + Shipping-Type gesetzt -> `[Confirm]` enabled, `[Cancel]` enabled, `[Refresh]` enabled, `[Resend]` hidden; (b) State `NEW` ohne Shipping-Type -> `[Confirm]` disabled mit `aria-disabled="true"` und `title="Set shipping type first"`; (c) `CONFIRMED`/`PROCESSED` -> `[Confirm]` und `[Cancel]` hidden, nur `[Refresh]` sichtbar; (d) `CANCELLED` -> nur `[Refresh]` sichtbar; (e) `failed_to_submit` -> `[Resend]` prominent + `[Refresh]`. Jeder Button traegt `data-action`-Attribut mit dem AJAX-Action-Namen aus der Tabelle oben (ohne `spreadconnect_`-Praefix — JS-Hook setzt diesen).

5) **AC-Render-WebhookActivityBlock — Last-5 aus WebhookLogRepo**
   **GIVEN** in `wp_spreadconnect_webhook_log` existieren 7 Eintraege fuer `related_entity_type='order' AND related_entity_id=$sc_order_id`
   **WHEN** `render()` aufgerufen wird
   **THEN** ruft `OrderMetaBox` `WebhookLogRepo::findRecentForOrder(string $sc_order_id, int $limit = 5): array` auf und rendert maximal 5 `<li>`-Eintraege im `data-block="webhook-activity"` Container, jeweils mit `event_type`, `received_at` (formatiert via `wp_date()`) und `processing_status` (Badge). Existieren <5 Eintraege, werden Platzhalter-`<li>` mit `—` bis Slot 5 aufgefuellt (Wireframes-Layout Z. 959-962). Bei fehlendem `_spreadconnect_order_id`-Meta wird der Block mit "Not yet submitted" gerendert; Repo-Call entfaellt.

6) **AC-Render-ShipmentsBlock — Stub-Read aus Order-Meta**
   **GIVEN** WC-Order mit `_spreadconnect_tracking_number` und `_spreadconnect_tracking_url` gesetzt (durch Webhook-Handler aus Slice 30)
   **WHEN** `render()` aufgerufen wird
   **THEN** rendert der `data-block="shipments"`-Container (a) wenn beide Meta-Werte non-empty: einen `<a href={tracking_url} target="_blank">{tracking_number}</a>`-Eintrag; (b) sonst Placeholder-Text "no shipments recorded". Slice 32 ruft **nicht** `getShipments()` synchron — die Anzeige ist purely Meta-getrieben (real Shipment-Sync ist Slice 30-`Order.shipment.sent`-Webhook-Handler).

7) **AC-Ajax-Confirm-Order-Cap-Nonce — Cap+Nonce-Gate**
   **GIVEN** AJAX-Request `spreadconnect_confirm_order` mit (a) ungueltigem Nonce ODER (b) User ohne `manage_woocommerce`-Capability ODER (c) ohne `order_id`-POST-Field
   **WHEN** `Hub\Ajax\OrderActions::handleConfirm()` ausgefuehrt wird
   **THEN** in jeder der drei Fail-Varianten: (a) ruft `wp_send_json_error(...)` mit HTTP-Status 403 (Cap/Nonce) bzw. 400 (Missing Param); (b) ruft `as_enqueue_async_action()` **nicht** auf; (c) der Handler kehrt **nicht** mit Success zurueck. Reihenfolge: Nonce-Check zuerst (`check_ajax_referer('spreadconnect_admin')`), dann Cap-Check (`current_user_can('manage_woocommerce')`), dann Param-Validation.

8) **AC-Ajax-Confirm-Order-Success — Enqueue Slice-29-Hook**
   **GIVEN** valider Nonce, `manage_woocommerce`-User, POST `order_id=7`, WC-Order existiert
   **WHEN** `handleConfirm()` ausgefuehrt wird
   **THEN** wird genau einmal `as_enqueue_async_action('spreadconnect/confirm_order', ['order_id' => 7], 'spreadconnect')` aufgerufen (Hook-Args-Konvention identisch zu Slice 29 Provides-Tabelle); Response via `wp_send_json_success(['queued' => true])`.

9) **AC-Ajax-Cancel-Order-Success — Enqueue Slice-29-Cancel-Hook**
   **GIVEN** wie AC-8, aber Action `spreadconnect_cancel_order`
   **WHEN** `handleCancel()` ausgefuehrt wird
   **THEN** `as_enqueue_async_action('spreadconnect/cancel_order', ['order_id' => 7], 'spreadconnect')`; Response `{queued: true}`.

10) **AC-Ajax-RefreshState-Success — Synchron getOrder + Meta-Update**
    **GIVEN** valider Nonce/Cap, `order_id=7`, `_spreadconnect_order_id='sc_42'`; `SpreadconnectClient::getOrder('sc_42')` liefert `['id'=>'sc_42','state'=>'CONFIRMED', 'updatedAt'=>'2026-05-03T14:14:00Z']`
    **WHEN** `handleRefreshState()` ausgefuehrt wird
    **THEN** (a) `SpreadconnectClient::getOrder('sc_42')` wird genau einmal aufgerufen; (b) `$order->update_meta_data('_spreadconnect_state', 'CONFIRMED')` + `$order->save()` werden aufgerufen; (c) Response `{ok:true, state:'CONFIRMED', last_event:string}`. Bei `SpreadconnectClientError` -> `wp_send_json_error(['message'=>$e->getMessage()])` mit HTTP 502 (Upstream-Fehler).

11) **AC-Ajax-SaveShippingType-Success — POST + Meta-Persist**
    **GIVEN** valider Nonce/Cap, `order_id=7`, `shipping_type='EXPRESS'`, `_spreadconnect_order_id='sc_42'`; `setShippingType('sc_42', 'EXPRESS')` liefert 2xx
    **WHEN** `handleSaveShippingType()` ausgefuehrt wird
    **THEN** `setShippingType()` wird einmal aufgerufen; `_spreadconnect_shipping_type='EXPRESS'` persistiert; Response `{ok:true, shipping_type:'EXPRESS'}`. Bei `SpreadconnectClientError` -> kein Meta-Write, Response `{ok:false}` mit HTTP 502.

12) **AC-Ajax-CancelAutoConfirm-Success — Unschedule via AS**
    **GIVEN** valider Nonce/Cap, `order_id=7`, ein zukuenftig geplanter `spreadconnect/confirm_order`-Job existiert (gescheduled durch Slice 31 Auto-Confirm-Timer)
    **WHEN** `handleCancelAutoConfirm()` ausgefuehrt wird
    **THEN** `as_unschedule_action('spreadconnect/confirm_order', ['order_id'=>7], 'spreadconnect')` wird genau einmal aufgerufen; Response `{ok:true, unscheduled:true}`. Slice 32 ruft die Slice-29-Jobs **nicht** direkt auf — Auto-Confirm-Pfad bleibt rein im Action-Scheduler-Layer.

13) **AC-AssetEnqueue-Localize-Bound-To-Screen — JS nur auf Order-Edit-Screen**
    **GIVEN** `admin_enqueue_scripts`-Hook feuert
    **WHEN** der aktive Screen ist `'shop_order'` ODER `'woocommerce_page_wc-orders'`
    **THEN** wird `assets/js/order-meta-box.js` mit Handle `'spreadconnect-order-meta-box'` registriert (Dependencies `['jquery']`, `in_footer=true`); ein `wp_localize_script(..., 'SpreadconnectOrderMetaBox', [...])`-Call uebergibt mindestens `ajaxUrl` (`admin_url('admin-ajax.php')`), `nonce` (`wp_create_nonce('spreadconnect_admin')`), `actions` (Map AJAX-Action-Slug -> server-action-name aus AJAX-Tabelle oben), `i18n` (deutsche Confirm-Dialog-Strings via `__()`). Auf anderen Admin-Screens wird **kein** Enqueue ausgeloest.

14) **AC-Hook-Wiring — Bootstrap registriert MetaBox + AJAX + Asset-Enqueue idempotent**
    **GIVEN** Plugin-Boot
    **WHEN** `Bootstrap\Plugin::init()` durchlaeuft
    **THEN** sind die folgenden Hooks via `add_action()` registriert: (a) `add_meta_boxes` -> `OrderMetaBox::registerOnAddMetaBoxes` (akzeptiert `$post_type`-Param, intern Screen-Filter aus AC-1); (b) `admin_enqueue_scripts` -> `OrderMetaBox::enqueueAssets` (Priority 10, Args 1 fuer Hook-Suffix); (c) fuenf `wp_ajax_{action}`-Hooks fuer alle 5 Actions aus der Tabelle (ohne `wp_ajax_nopriv_`-Variante — Admin-only). Doppelter `init()`-Aufruf fuegt nicht doppelt hinzu (`has_action()`-Idempotenz).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Drei PHPUnit-Test-Files: `slice-08-order-meta-box-render.php` (AC-1..AC-6, AC-13..AC-14), `slice-08-order-actions-cap-nonce.php` (AC-7), `slice-08-order-actions-success.php` (AC-8..AC-12). Brain\Monkey-Setup fuer `add_meta_box`, `wp_create_nonce`, `check_ajax_referer`, `current_user_can`, `wp_send_json_*`, `as_enqueue_async_action`, `as_unschedule_action`, `wc_get_order`. SpreadconnectClient + WebhookLogRepo per Konstruktor injiziert. Markup-Asserts auf `data-*`-Attribute, **nicht** auf CSS-Klassen-Pixel. JS (`order-meta-box.js`) wird **nicht** unit-getestet — Done-Signal ist manuelle QA.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-08-order-meta-box-render.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class OrderMetaBoxRenderTest extends TestCase
{
    // AC-1: Dual-Screen-Registration (HPOS + Legacy)
    public function test_register_adds_meta_box_on_hpos_screen(): void { $this->markTestIncomplete('AC-1'); }
    public function test_register_adds_meta_box_on_legacy_shop_order_screen(): void { $this->markTestIncomplete('AC-1'); }
    public function test_register_skips_unrelated_screens(): void { $this->markTestIncomplete('AC-1'); }

    // AC-2: State-Block render
    public function test_render_emits_state_block_with_sc_order_id_badge_and_refresh_button(): void { $this->markTestIncomplete('AC-2'); }
    public function test_render_escapes_dynamic_strings(): void { $this->markTestIncomplete('AC-2'); }

    // AC-3: Shipping-Block render lazy
    public function test_render_emits_shipping_dropdown_with_data_loaded_false(): void { $this->markTestIncomplete('AC-3'); }
    public function test_render_does_not_call_get_shipping_types_synchronously(): void { $this->markTestIncomplete('AC-3'); }
    public function test_render_marks_shipping_block_disabled_for_confirmed_state(): void { $this->markTestIncomplete('AC-3'); }

    // AC-4: Actions-Block state-abhaengig
    public function test_render_actions_block_state_new_with_shipping(): void { $this->markTestIncomplete('AC-4'); }
    public function test_render_actions_block_state_new_without_shipping_disables_confirm(): void { $this->markTestIncomplete('AC-4'); }
    public function test_render_actions_block_state_confirmed_hides_confirm_and_cancel(): void { $this->markTestIncomplete('AC-4'); }
    public function test_render_actions_block_state_failed_to_submit_shows_resend(): void { $this->markTestIncomplete('AC-4'); }

    // AC-5: Webhook-Activity-Block
    public function test_render_webhook_activity_calls_repo_with_limit_5(): void { $this->markTestIncomplete('AC-5'); }
    public function test_render_webhook_activity_pads_to_5_slots_when_fewer_entries(): void { $this->markTestIncomplete('AC-5'); }
    public function test_render_webhook_activity_skipped_without_sc_order_id(): void { $this->markTestIncomplete('AC-5'); }

    // AC-6: Shipments-Block aus Meta
    public function test_render_shipments_block_shows_tracking_link_when_meta_set(): void { $this->markTestIncomplete('AC-6'); }
    public function test_render_shipments_block_shows_placeholder_when_no_tracking_meta(): void { $this->markTestIncomplete('AC-6'); }

    // AC-13: Asset-Enqueue
    public function test_enqueue_assets_registers_script_only_on_order_edit_screens(): void { $this->markTestIncomplete('AC-13'); }
    public function test_enqueue_assets_localizes_ajax_url_nonce_and_actions(): void { $this->markTestIncomplete('AC-13'); }
    public function test_enqueue_assets_skips_other_admin_screens(): void { $this->markTestIncomplete('AC-13'); }

    // AC-14: Bootstrap-Wiring
    public function test_plugin_init_registers_add_meta_boxes_and_admin_enqueue_hooks(): void { $this->markTestIncomplete('AC-14'); }
    public function test_plugin_init_registers_all_five_wp_ajax_hooks(): void { $this->markTestIncomplete('AC-14'); }
    public function test_plugin_init_hook_registration_is_idempotent(): void { $this->markTestIncomplete('AC-14'); }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-08-order-actions-cap-nonce.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class OrderActionsCapNonceTest extends TestCase
{
    // AC-7: Cap+Nonce-Gate fuer alle 5 Handler
    public function test_handle_confirm_returns_403_on_invalid_nonce(): void { $this->markTestIncomplete('AC-7'); }
    public function test_handle_confirm_returns_403_when_user_lacks_manage_woocommerce(): void { $this->markTestIncomplete('AC-7'); }
    public function test_handle_confirm_returns_400_on_missing_order_id(): void { $this->markTestIncomplete('AC-7'); }
    public function test_handle_cancel_enforces_same_cap_and_nonce_chain(): void { $this->markTestIncomplete('AC-7'); }
    public function test_handle_refresh_state_enforces_same_cap_and_nonce_chain(): void { $this->markTestIncomplete('AC-7'); }
    public function test_handle_save_shipping_type_enforces_same_cap_and_nonce_chain(): void { $this->markTestIncomplete('AC-7'); }
    public function test_handle_cancel_auto_confirm_enforces_same_cap_and_nonce_chain(): void { $this->markTestIncomplete('AC-7'); }
    public function test_failed_gate_does_not_enqueue_or_call_client(): void { $this->markTestIncomplete('AC-7'); }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-08-order-actions-success.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class OrderActionsSuccessTest extends TestCase
{
    // AC-8: Confirm enqueues Slice-29-Hook
    public function test_handle_confirm_enqueues_spreadconnect_confirm_order_with_order_id_arg(): void { $this->markTestIncomplete('AC-8'); }

    // AC-9: Cancel enqueues Slice-29-Cancel-Hook
    public function test_handle_cancel_enqueues_spreadconnect_cancel_order_with_order_id_arg(): void { $this->markTestIncomplete('AC-9'); }

    // AC-10: Refresh-State synchron
    public function test_handle_refresh_state_calls_get_order_and_persists_state_meta(): void { $this->markTestIncomplete('AC-10'); }
    public function test_handle_refresh_state_returns_502_on_client_error(): void { $this->markTestIncomplete('AC-10'); }

    // AC-11: Save-Shipping-Type
    public function test_handle_save_shipping_type_calls_set_shipping_type_and_persists_meta(): void { $this->markTestIncomplete('AC-11'); }
    public function test_handle_save_shipping_type_skips_meta_write_on_client_error(): void { $this->markTestIncomplete('AC-11'); }

    // AC-12: Cancel-Auto-Confirm via AS-Unschedule
    public function test_handle_cancel_auto_confirm_calls_as_unschedule_action_with_order_id_arg(): void { $this->markTestIncomplete('AC-12'); }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-29-order-confirm-cancel-jobs` | AS-Hook-Names `'spreadconnect/confirm_order'`, `'spreadconnect/cancel_order'` (Group `'spreadconnect'`) + Args-Konvention `['order_id' => int]` | string-literal | `as_enqueue_async_action()`-Call-Args identisch zu Slice 29 Provides-Tabelle. |
| `slice-10-endpoint-methods` | `SpreadconnectClient::getOrder(string)`, `setShippingType(string,string)`, `getShippingTypes(string)` | public methods | Wrapper-Vertrag aus Slice 10 (Z. 48, 52-53). 4xx -> `SpreadconnectClientError`; 5xx -> `SpreadconnectTransientError`. |
| `slice-16-event-id-hasher` | `Webhook\WebhookLogRepo::find(int)` als Convention; Slice 32 fuegt `findRecentForOrder(string $sc_order_id, int $limit = 5): array` hinzu | repo method | Slice 16 hat `WebhookLogRepo` voll implementiert; Slice 32 erweitert um eine zusaetzliche Read-Methode (siehe Reuse-Tabelle). |
| `slice-13-hub-page-skeleton` | `Hub\View\Sidebar`-Convention (Capability-Gate `manage_woocommerce`, Nonce-Action `'spreadconnect_admin'`) | shared convention | AJAX-Handler-Cap-Gate identisch zur Hub-Convention. |
| `slice-04-schema-dbdelta` | Tabelle `wp_spreadconnect_webhook_log` mit Spalten `event_type`, `received_at`, `processing_status`, `related_entity_type`, `related_entity_id` | DDL | SELECT-Query in `findRecentForOrder` nutzt `idx_received_at` Index. |
| WordPress / WooCommerce | `add_meta_box`, `wc_get_page_screen_id`, `wc_get_order`, `WC_Order::update_meta_data`/`save`/`get_meta`, `admin_enqueue_scripts`, `wp_register_script`/`wp_enqueue_script`/`wp_localize_script`, `check_ajax_referer`, `wp_send_json_success`/`wp_send_json_error`, `as_enqueue_async_action`/`as_unschedule_action` | WP/WC/AS-API | HPOS-Default seit WC 8.2 (Architecture Z. 637). |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| AJAX-Action `spreadconnect_confirm_order` (string-literal) | wp_ajax-action-name | Slice 33 (Order-List Bulk-Resend kann **nicht** denselben Handler nutzen — Bulk hat anderen Outcome-Panel-Pfad) | POST `{order_id, _ajax_nonce}` -> `{ok, queued}` |
| AJAX-Action `spreadconnect_cancel_order` | wp_ajax-action-name | (intern; nicht von Slice 33 konsumiert) | POST `{order_id, _ajax_nonce}` -> `{ok, queued}` |
| AJAX-Action `spreadconnect_refresh_order_state` | wp_ajax-action-name | Slice 38 (Failed-Ops-Detail-Panel kann denselben Handler aufrufen) | POST `{order_id, _ajax_nonce}` -> `{ok, state, last_event}` |
| AJAX-Action `spreadconnect_save_shipping_type` | wp_ajax-action-name | (intern) | POST `{order_id, shipping_type, _ajax_nonce}` -> `{ok, shipping_type}` |
| AJAX-Action `spreadconnect_cancel_auto_confirm` | wp_ajax-action-name | (intern; Slice 31 Auto-Confirm-Timer ist Producer der Schedule, Slice 32 ist Consumer des Cancel) | POST `{order_id, _ajax_nonce}` -> `{ok, unscheduled}` |
| `Inline\OrderMetaBox` Class | Adapter (Admin-Page) | (UI-Surface ist Endpunkt — keine downstream-Slices) | Konstruktor: DI-frei (statische `register`/`render`/`enqueueAssets`-Methoden, analog Slice 13 `Hub\View\Sidebar`); private Helper-Methoden konsumieren `WebhookLogRepo` und `SpreadconnectClient` via Service-Locator-Lookup gemaess Slice-13/Slice-29-Konvention. |
| `Hub\Ajax\OrderActions` Class | Adapter (Admin-AJAX) | Slice 33 (Bulk-Resend nutzt **andere** Action `spreadconnect_resend_failed_op` aus Slice 38) | Public methods `handleConfirm()`, `handleCancel()`, `handleRefreshState()`, `handleSaveShippingType()`, `handleCancelAutoConfirm()` — alle ohne Args (lesen `$_POST` direkt). |
| `WebhookLogRepo::findRecentForOrder(string $sc_order_id, int $limit = 5): array` | repo method | Slice 41 (Webhook-Log-UI Filter pro Order-ID) | Returns `array<array{event_type:string, received_at:string, processing_status:string}>`. |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Inline/OrderMetaBox.php` — Neue Klasse `final class SpreadconnectPod\Inline\OrderMetaBox`. Public statische Methoden: `registerOnAddMetaBoxes(string $post_type_or_screen): void` (dual-Screen-Filter laut AC-1), `render(\WC_Order|\WP_Post $context): void` (HTML-Output mit 5 `data-block`-Containern: state, shipping-type, actions, shipments, webhook-activity), `enqueueAssets(string $hook_suffix): void` (Screen-Filter + Script-Register + `wp_localize_script` laut AC-13). Private Helper fuer Block-Rendering. Kein Code-Path zu `SpreadconnectClient` oder API innerhalb des Render-Pfades (Lazy via JS).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/OrderActions.php` — Neue Klasse `final class SpreadconnectPod\Hub\Ajax\OrderActions`. Konstruktor injiziert `SpreadconnectClient`. Public methods `handleConfirm()`, `handleCancel()`, `handleRefreshState()`, `handleSaveShippingType()`, `handleCancelAutoConfirm()` — alle implementieren die identische Cap+Nonce+Param-Reihenfolge aus AC-7 und delegieren an die jeweilige Action laut AJAX-Tabelle. Statisches `register(): void` ergaenzt 5 `add_action('wp_ajax_{name}', ...)`-Calls (kein `nopriv`).
- [ ] `wordpress/plugins/spreadconnect-pod/assets/js/order-meta-box.js` — Vanilla-JS (jQuery dependency erlaubt — WP-Admin-Convention). Verantwortet: (a) Lazy-Load der Shipping-Types beim Dropdown-Focus via fetch zu `SpreadconnectOrderMetaBox.ajaxUrl`; (b) Click-Hooks fuer alle 5 Action-Buttons -> POST mit `nonce` + Reload-on-Success; (c) Confirm-Dialog vor Cancel/Cancel-Auto-Confirm; (d) State-Badge-Update nach Refresh-Response ohne Page-Reload. Keine eigene Build-Pipeline (Plain-JS reicht — Slice 32 ist erste JS-Datei im Plugin).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — **Edit, nicht ersetzen.** In `init()`: (a) `add_action('add_meta_boxes', [Inline\OrderMetaBox::class, 'registerOnAddMetaBoxes'])`; (b) `add_action('admin_enqueue_scripts', [Inline\OrderMetaBox::class, 'enqueueAssets'])`; (c) `Hub\Ajax\OrderActions::register()` aufrufen (registriert 5 `wp_ajax_*`-Hooks). Ohne diesen Edit ist die Meta-Box auf keinem Order-Edit-Screen sichtbar und kein AJAX-Handler erreichbar (Mount-Point-Pflicht).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookLogRepo.php` — **Edit, nicht ersetzen.** Methode `findRecentForOrder(string $sc_order_id, int $limit = 5): array` ergaenzen. SELECT mit `WHERE related_entity_type='order' AND related_entity_id=%s ORDER BY received_at DESC LIMIT %d`. Bestehende `find()`/`insertOrIgnore()`/`updateProcessingStatus()` aus Slice 16 unveraendert.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt die drei `slice-08-*.php`-Files basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- KEINE Order-List-Spalten / Filter / Bulk-Action — Slice 33.
- KEIN Server-Handler fuer `spreadconnect_resend_failed_op` (das Resend-Button-Markup wird gerendert, der AJAX-Handler kommt aus Slice 38).
- KEIN Auto-Confirm-Timer-Schedule (`as_schedule_single_action(...)`) — Slice 31. Slice 32 implementiert nur den `cancel_auto_confirm`-Pfad (Unschedule).
- KEIN Banner/Admin-Notice-Rendering oberhalb der Meta-Box (Wireframes ① "needs-action banner") — `Failure\AdminNoticeStore` aus Slice 39 rendert diese auf `admin_notices`-Hook globalweit.
- KEIN echter `Failure\FailedOpsRepo`-Read fuer `failed_to_submit`-Detail-Link — Slice 32 rendert nur das `[Open Failed-Ops Detail ↗]`-Markup mit Static-URL zu Hub-Page-Failed-Section; Repo kommt aus Slice 37/38.
- KEINE `getShipments()`-API-Calls — Shipments-Block ist Meta-getrieben (AC-6). Live-Shipment-Sync ist Slice 30 Webhook-Handler.
- KEIN Build/Bundling fuer JS — Plain-JS-Datei direkt aus `assets/`. Tailwind/Vite/etc. NICHT eingefuehrt.
- KEINE i18n-Strings in `.po`-Datei einpflegen — die `__()`-Calls werden im JS via `wp_localize_script` gefuettert; PO-File-Update kommt in Slice 46.
- KEIN Bulk-Resend-Outcome-Panel — Slice 33+40.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in beiden PHP-Files.
- `final class` fuer beide neuen Klassen.
- `OrderMetaBox::render()`-Callback-Signatur akzeptiert sowohl HPOS-`\WC_Order`-Instanz als auch Legacy-`\WP_Post`-Instanz; Adapter normalisiert via `wc_get_order($context_or_id)` (HPOS-Convention Architecture Z. 637).
- Cap+Nonce-Reihenfolge in **allen** AJAX-Handlern: (1) `check_ajax_referer('spreadconnect_admin', '_ajax_nonce', false)` — bei `false` -> `wp_send_json_error([...], 403)`; (2) `current_user_can('manage_woocommerce')` — bei `false` -> 403; (3) Param-Validation (`order_id` muss int>0; `shipping_type` muss non-empty string fuer Save-Action) — bei `false` -> 400.
- Hook-Args-Konvention `['order_id' => $order_id]` (assoc-Array, identisch zu Slice 28/29) — sichert idempoten `as_has_scheduled_action()`-Vergleich.
- Keine `update_post_meta()`-Aufrufe — alle Order-Meta-Writes via `$order->update_meta_data() + $order->save()` (HPOS).
- Logging via `wc_get_logger()` mit Source `'spreadconnect-order-service'` — kein `error_log()`. Tags: `meta_box_render_failed` (Render-Exception), `ajax_action_failed` (jeweiliger Handler-Fail).
- Markup-Output via `printf()` mit `esc_html()`/`esc_attr()`/`esc_url()`-Wrappern; **keine** rohen Variablen-Interpolations in HTML.
- JS-Datei nutzt jQuery (WP-Admin-Convention), kein ES-Modules. AJAX-Calls via `jQuery.post(SpreadconnectOrderMetaBox.ajaxUrl, {...})` — Response-Handling als Promises akzeptabel.
- Render-Pfad MUSS leistungsfrei laufen (kein API-Roundtrip): nur DB-Reads (Order-Meta + WebhookLogRepo). `getShippingTypes()` lazy via JS-AJAX (AC-3).
- `WebhookLogRepo::findRecentForOrder` nutzt `$wpdb->prepare()` mit `%s`+`%d`-Platzhaltern; Index-Hint nicht noetig (Architecture Z. 213-216 listet `idx_received_at`).
- Asset-Versionierung: Plugin-Version-Konstante als `wp_register_script(..., $version)`-Cache-Buster.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02..29) | **Edit, nicht ersetzen.** Erweitert `init()` um 3 neue `add_action`-Bloecke (MetaBox-Hook, Asset-Enqueue-Hook, AJAX-Hooks-via-Helper-Call). Bestehende Hook-Registrierungen (HPOS-Declare, Schema-Activate, Order-Hooks aus Slice 28/29) bleiben unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookLogRepo.php` (Slice 16) | **Edit, additive Erweiterung.** Zwei neue Methoden hinzufuegen: `findRecentForOrder()` (AC-5). Bestehende `find()`/`insertOrIgnore()`/`updateProcessingStatus()` unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08/10) | **Wiederverwendet, unveraendert.** `getOrder()`, `setShippingType()`, `getShippingTypes()` sind Slice-10-Wrapper. Per Konstruktor in `Hub\Ajax\OrderActions` injiziert. |
| `wordpress/plugins/spreadconnect-pod/includes/Order/OrderConfirmJob.php` / `OrderCancelJob.php` (Slice 29) | **Wiederverwendet, unveraendert.** Slice 32 ruft sie indirekt via `as_enqueue_async_action()` auf den in Slice 29 registrierten Hooks (`spreadconnect/confirm_order`, `spreadconnect/cancel_order`). |
| `wordpress/plugins/spreadconnect-pod/includes/Hub/View/Sidebar.php` (Slice 13) | **Convention-Vorlage, unveraendert.** Slice 32 spiegelt die statische `render()`-Methodik + `esc_*`-Konvention + Capability-Gate-Pfad. |

**Referenzen:**
- Architecture: `architecture.md` -> AJAX-Action-Tabelle Z. 152-156 (alle 5 Handler-Definitionen).
- Architecture: `architecture.md` -> Service-Map Z. 395 — `Inline\OrderMetaBox` als Adapter (Admin-Page).
- Architecture: `architecture.md` -> Z. 84 — Nonce-Action-Name `spreadconnect_admin`.
- Architecture: `architecture.md` -> Z. 637 — HPOS-Meta-Convention `$order->update_meta_data()`.
- Architecture: `architecture.md` -> Z. 641 — WC dual hook-sets fuer Order-Screens (HPOS + Legacy).
- Architecture: `architecture.md` -> Z. 305-315 — Order-Meta-Schema (`_spreadconnect_state`/`_spreadconnect_shipping_type`/`_spreadconnect_tracking_*`).
- Wireframes: `wireframes.md` -> `Screen 11` Z. 920-995 (Layout, Annotations ③..⑩, State-Variations-Tabelle).
- Discovery: `discovery.md` -> Slice 8 "Inline UX (Product + Order)" Z. 929 (Order-Edit-Meta-Box ist Teil dieses Discovery-Slices).
- Slim-Slices: `slices/slim-slices.md` -> Slice-32-Eintrag Z. 501-509 — Done-Signal: Meta-Box-Render mit allen Bloecken; Confirm-Button triggert Slice-29-Hook.
- Slice 10: `slices/slice-10-endpoint-methods.md` -> `getOrder()`/`setShippingType()`/`getShippingTypes()`-Wrapper-Vertrag.
- Slice 13: `slices/slice-13-hub-page-skeleton.md` -> Capability-Gate-Konvention + statische View-Klassen-Methodik.
- Slice 16: `slices/slice-16-event-id-hasher.md` -> `WebhookLogRepo`-Existenz + Methodensignaturen-Konvention.
- Slice 29: `slices/slice-29-order-confirm-cancel-jobs.md` -> Provides-Tabelle (AS-Hook-Names + Args-Konvention `['order_id' => int]`).
- Slice 31: (folgt) — registriert die Auto-Confirm-Timer-Schedule, die Slice 32 via `cancel_auto_confirm` unscheduled.
- Slice 33: (folgt) — Order-List-Spalten/Filter/Bulk konsumieren denselben Inline-Layer-Stil, aber andere Hooks.
- Slice 38: (folgt) — `spreadconnect_resend_failed_op`-Handler fuer den `[Resend]`-Button im `failed_to_submit`-State.
