# Slice 39: Failure-Notifier + Persistent-Admin-Notice-Store

> **Slice 39 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-39-failure-notifier` |
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
| **Start Command** | `docker compose up -d` (manuelle QA via WP-Admin: Failed-Op insertieren, Admin-Notice auf jeder Admin-Page sichtbar) |
| **Health Endpoint** | `n/a` (Notifier + Notice-Option-Store, keine eigenen REST-Routes) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey/Mockery fuer `wp_mail`, `get_option`/`update_option`/`delete_option`, `current_time`, `is_admin`, `add_action`, `wc_get_logger`; `FailedOpsRepo` als Konstruktor-Mock) |

---

## Ziel

Schliesst Discovery Slice 9 "Failure-Recovery" Notification-Lane: `Failure\FailureNotifier::dispatch($failedOpRow)` versendet `wp_mail` an die Recipients aus `spreadconnect_notify_emails`, gated per `notify_on_*`-Setting (op-type → flag mapping). `Failure\AdminNoticeStore` persistiert Notices in einer Option-basierten Liste (`spreadconnect_admin_notices`) mit per-op-type-Dismiss-Policy; der `admin_notices`-Hook rendert sie auf jeder WP-Admin-Page. Slice 37 ruft am Ende von `FailedOpsRepo::record()` (bzw. `RetryPolicyListener::on_action_failed()`) den Notifier auf — die Wiring-Edit ist Teil dieses Slices.

---

## Acceptance Criteria

1) **AC-Notifier-Dispatch-Sends-Email — Happy-Path mit aktiviertem Flag**
   **GIVEN** Setting `spreadconnect_notify_emails='admin@shop.de, ops@shop.de'`, `spreadconnect_notify_on_order_failure=true`; ein FailedOp-Row `['id'=>42, 'op_type'=>'create_order', 'related_entity_type'=>'order', 'related_entity_id'=>'7', 'error_message'=>'HTTP 400 invalid SKU mapping', 'error_code'=>'http_4xx', 'created_at'=>'2026-05-03 10:00:00']`
   **WHEN** `FailureNotifier::dispatch($row)` aufgerufen wird
   **THEN** wird `wp_mail()` genau einmal aufgerufen mit:
   - `$to=['admin@shop.de', 'ops@shop.de']` (array, getrimmt, in Original-Reihenfolge)
   - `$subject` enthaelt Substring `'[Spreadconnect]'` + den Op-Type-Label `'Order failed'` + die `related_entity_id` `'#7'`
   - `$message` enthaelt `error_message`, `error_code`, `created_at`, sowie einen Hub-Deeplink-URL-Substring `'page=spreadconnect&section=failed'`
   - `$headers` enthaelt mindestens `'Content-Type: text/plain; charset=UTF-8'`
   Methode liefert `true`.

2) **AC-Notifier-Flag-Gating — Op-Type-Flag entscheidet ueber Send**
   **GIVEN** Setting `spreadconnect_notify_emails='admin@shop.de'` und das Mapping op-type → flag:
   | op_type | gating Setting | Default |
   |---|---|---|
   | `create_order`, `confirm_order`, `cancel_order_mirror`, `fetch_tracking` | `spreadconnect_notify_on_order_failure` | `true` |
   | `sync_catalog`, `sync_article`, `handle_article_removed`, `scheduled_stock_sync` | `spreadconnect_notify_on_sync_failure` | `true` |
   | `handle_webhook` | `spreadconnect_notify_on_webhook_failure` | `false` |
   **WHEN** `dispatch($row)` aufgerufen wird mit `$row['op_type']='handle_webhook'` und `spreadconnect_notify_on_webhook_failure=false`
   **THEN** wird `wp_mail()` **nicht** aufgerufen; Methode liefert `false`. Ist das Flag fuer dieselbe Op-Type-Klasse `true`, wird gesendet (siehe AC-1).

3) **AC-Notifier-Empty-Recipients — Skip mit Log-Warning**
   **GIVEN** Setting `spreadconnect_notify_emails=''` (leer) und `spreadconnect_notify_on_order_failure=true`; FailedOp-Row mit `op_type='create_order'`
   **WHEN** `dispatch($row)` aufgerufen wird
   **THEN** wird `wp_mail()` **nicht** aufgerufen; ein WC-Logger-Eintrag mit Level `warning`, Source `'spreadconnect-failure'`, Message-Substring `'no notification recipients configured'` wird geschrieben; Methode liefert `false`.

4) **AC-Notifier-Throwable-Swallow — Notifier wirft niemals**
   **GIVEN** `wp_mail()` wirft eine `\Throwable` (z. B. `phpmailer_init`-Hook-Exception) waehrend `dispatch()`
   **WHEN** `dispatch($row)` aufgerufen wird
   **THEN** wird die Exception innerhalb von `dispatch()` gefangen; ein WC-Logger-Eintrag Level `error`, Source `'spreadconnect-failure'`, Message-Substring `'wp_mail dispatch failed'` mit `exception_message` im Context wird geschrieben; Methode liefert `false`. **Kein** Re-Throw — der Notifier ist Best-Effort, AS-Pipeline darf nicht durch Mail-Issues gestoert werden.

5) **AC-NoticeStore-Add-Persists-To-Option — `add()` schreibt Option-Array**
   **GIVEN** Option `spreadconnect_admin_notices` ist leer (`[]` oder ungesetzt) und ein FailedOp-Row wie in AC-1
   **WHEN** `AdminNoticeStore::add($row)` aufgerufen wird
   **THEN** wird `update_option('spreadconnect_admin_notices', $list, false)` aufgerufen mit `$list` = Array mit genau einem Eintrag, der die Felder enthaelt: `notice_id` (deterministisch berechnet als `'failed_op_'+failedOp.id`), `op_type='create_order'`, `related_entity_type='order'`, `related_entity_id='7'`, `error_message`, `error_code`, `created_at`, `severity='error'` (op-type-mapped, siehe AC-7), `dismiss_policy='requires_resolution'` (per-op-type, siehe AC-8). `autoload=false` ist Pflicht (zweiter `update_option`-Parameter).

6) **AC-NoticeStore-Add-Idempotent — Doppel-Add fuer dieselbe FailedOp-ID schreibt nur einen Eintrag**
   **GIVEN** Option `spreadconnect_admin_notices` enthaelt bereits einen Notice mit `notice_id='failed_op_42'`
   **WHEN** `AdminNoticeStore::add($row)` aufgerufen wird mit demselben `id=42`
   **THEN** bleibt das Option-Array bei genau einem Eintrag; `update_option()` wird **nicht** erneut aufgerufen (Pre-Check via `notice_id`-Uniqueness). Methode liefert `false` (no-op-flag).

7) **AC-NoticeStore-Severity-Mapping — Op-Type → severity**
   **GIVEN** `AdminNoticeStore::add($row)` wird fuer verschiedene op_types aufgerufen
   **WHEN** der Notice persistiert wird
   **THEN** entspricht `severity` dem Mapping:
   | op_type | severity |
   |---|---|
   | `create_order`, `confirm_order`, `cancel_order_mirror`, `fetch_tracking` | `'error'` |
   | `sync_catalog`, `sync_article`, `handle_article_removed`, `scheduled_stock_sync` | `'warning'` |
   | `handle_webhook` | `'warning'` |
   Unbekannte op_types fallen auf `'warning'` zurueck (Defensive-Default).

8) **AC-NoticeStore-Dismiss-Policy — Per-Op-Type-Policy steuert UI-Dismiss-Verhalten**
   **GIVEN** `AdminNoticeStore::add($row)` mit unterschiedlichen op_types
   **WHEN** der Notice persistiert wird
   **THEN** entspricht `dismiss_policy` dem Mapping:
   | op_type | dismiss_policy |
   |---|---|
   | `create_order` | `'requires_resolution'` (3-Choice-Modal aus Slice 38) |
   | `confirm_order`, `cancel_order_mirror`, `fetch_tracking` | `'mark_resolved'` (one-click resolve) |
   | `sync_catalog`, `sync_article`, `handle_article_removed`, `scheduled_stock_sync` | `'dismissible'` (plain dismiss) |
   | `handle_webhook` | `'dismissible'` |

9) **AC-NoticeStore-Remove-By-FailedOpId — `removeByFailedOpId(int $id)`**
   **GIVEN** Option enthaelt zwei Notices `failed_op_42` und `failed_op_43`
   **WHEN** `AdminNoticeStore::removeByFailedOpId(42)` aufgerufen wird
   **THEN** wird `update_option('spreadconnect_admin_notices', $list, false)` aufgerufen mit `$list` = Array, das nur noch `failed_op_43` enthaelt. Wenn die Liste danach leer ist, wird stattdessen `delete_option('spreadconnect_admin_notices')` aufgerufen. Methode liefert `true` bei Removal, `false` wenn der Eintrag nicht existierte.

10) **AC-NoticeStore-FindAll-And-Count — Lese-Methoden fuer UI**
    **GIVEN** Option enthaelt drei Notices (eine mit `severity='error'`, zwei mit `severity='warning'`)
    **WHEN** `findAll()` und `count(?string $severity = null)` aufgerufen werden
    **THEN** liefert `findAll()` ein Array mit allen drei Eintraegen (sortiert `created_at DESC`); `count()` liefert `3`; `count('error')` liefert `1`. Bei nicht-existenter Option liefern beide Methoden ein leeres Array bzw. `0` (kein Throw).

11) **AC-AdminNotices-Hook-Renders — `admin_notices`-Hook rendert alle Notices**
    **GIVEN** Option `spreadconnect_admin_notices` enthaelt zwei Notices; aktueller Request ist `is_admin()=true`; Capability-Check: aktueller User hat `manage_woocommerce`
    **WHEN** WP den `admin_notices`-Hook feuert (Aufruf `AdminNoticeStore::renderAll()`)
    **THEN** wird fuer jeden Notice ein `<div class="notice notice-{severity} {is-dismissible|...}">`-Markup ausgegeben, das enthaelt: `[Spreadconnect]`-Praefix, `op_type`-Label-Text, `related_entity_id`-Substring, `error_message`, sowie die action-buttons gemaess `dismiss_policy` (siehe AC-12). Ohne `manage_woocommerce`-Capability wird **nichts** gerendert (early return + kein `echo`).

12) **AC-AdminNotices-Hook-Action-Buttons — Pro Policy unterschiedliche CTAs**
    **GIVEN** ein Notice wird gerendert (siehe AC-11)
    **WHEN** `renderAll()` ausgefuehrt wird
    **THEN** entsprechen die action-buttons im HTML-Output dem Mapping:
    | dismiss_policy | gerenderte CTAs (HTML-Anchors/Buttons) |
    |---|---|
    | `requires_resolution` | `[View in Failed-Ops]`-Link (href = Hub-Deeplink mit `?page=spreadconnect&section=failed&highlight={failed_op_id}`); **kein** Plain-Dismiss-Button |
    | `mark_resolved` | `[Mark Resolved]`-Button (data-failed-op-id = `{failed_op_id}`) + `[View Detail]`-Link |
    | `dismissible` | WP-native `is-dismissible`-Klasse + `[View Detail]`-Link |
    Die `[Mark Resolved]`- und Plain-Dismiss-Interaktionen werden in Slice 38 als AJAX implementiert; in Slice 39 reicht das HTML-Markup mit korrekten `data-*`-Attributen + `wp_create_nonce('spreadconnect_dismiss_notice')`-Output als hidden input.

13) **AC-Wiring-Bootstrap-Hook — `admin_notices` registriert; `RetryPolicyListener` ruft `dispatch`+`add`**
    **GIVEN** Plugin-Boot
    **WHEN** `Bootstrap\Plugin::init()` durchlaeuft
    **THEN** ist `add_action('admin_notices', [<AdminNoticeStore-Instanz>, 'renderAll'], 10, 0)` registriert. Zusaetzlich: in `Failure\RetryPolicyListener::on_action_failed()` (Edit aus Slice 37) wird **nach** erfolgreichem `FailedOpsRepo::record()`-Insert (Insert-ID > 0) die Sequenz `FailureNotifier::dispatch($row)` + `AdminNoticeStore::add($row)` aufgerufen, wobei `$row` aus dem soeben inserteten Datensatz stammt (Reload via `findById($insertId)`). Doppel-`init()` registriert nicht doppelt (`has_action()`-Identitaet).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Zwei PHPUnit-Test-Files: `tests/slices/pod-shop-mvp/slice-09-failure-notifier.php` (AC-1 bis AC-4) und `tests/slices/pod-shop-mvp/slice-09-admin-notice-store.php` (AC-5 bis AC-12). AC-13 wird als Integration-Smoketest in der Notice-Store-Datei abgedeckt (Hook-Registrierung) bzw. in der Notifier-Datei (Listener-Wiring-Mock). Brain\Monkey-Setup: `Functions\expect('wp_mail')`, `Functions\when('get_option')`/`when('update_option')`/`when('delete_option')`, `Functions\when('current_user_can')`, `Functions\when('is_admin')`, `Functions\when('admin_url')`, `Functions\when('wp_create_nonce')`. `FailureNotifier` per Konstruktor mit `?\WC_Logger`-Mock; `AdminNoticeStore` per Konstruktor parameterlos (liest Option direkt).

### Test-Datei: `tests/slices/pod-shop-mvp/slice-09-failure-notifier.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class FailureNotifierTest extends TestCase
{
    // AC-1: Happy path — wp_mail invoked with correct recipients/subject/body
    public function test_dispatch_sends_email_with_correct_recipients(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    public function test_dispatch_subject_contains_op_type_label_and_entity_id(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    public function test_dispatch_body_contains_error_and_hub_deeplink(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Op-type → flag gating
    public function test_dispatch_skips_when_order_flag_is_off(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    public function test_dispatch_skips_when_webhook_flag_is_off_default(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    public function test_dispatch_sends_when_sync_flag_is_on_for_sync_article(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: empty recipients → skip + warn-log
    public function test_dispatch_skips_when_recipients_empty(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    public function test_dispatch_logs_warning_when_recipients_empty(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: throwable swallow
    public function test_dispatch_swallows_throwable_from_wp_mail(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    public function test_dispatch_logs_error_when_wp_mail_throws(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-13 (notifier-side): wired from RetryPolicyListener after record()-insert
    public function test_retry_policy_listener_calls_dispatch_after_record(): void
    {
        $this->markTestIncomplete('AC-13');
    }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-09-admin-notice-store.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class AdminNoticeStoreTest extends TestCase
{
    // AC-5: add() persists to option (autoload=false)
    public function test_add_persists_notice_to_option(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    public function test_add_uses_autoload_false(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    public function test_add_notice_id_is_deterministic_failed_op_prefix(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: idempotent
    public function test_add_is_idempotent_for_same_failed_op_id(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: severity mapping
    public function test_severity_is_error_for_order_op_types(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    public function test_severity_is_warning_for_sync_op_types(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    public function test_severity_defaults_to_warning_for_unknown_op_type(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: dismiss_policy mapping
    public function test_dismiss_policy_is_requires_resolution_for_create_order(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    public function test_dismiss_policy_is_mark_resolved_for_confirm_order(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    public function test_dismiss_policy_is_dismissible_for_sync_article(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: removeByFailedOpId
    public function test_remove_by_failed_op_id_drops_only_target(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    public function test_remove_by_failed_op_id_deletes_option_when_list_empty(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    public function test_remove_by_failed_op_id_returns_false_when_missing(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: findAll + count
    public function test_find_all_returns_notices_sorted_desc(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    public function test_count_filters_by_severity(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    public function test_find_all_returns_empty_array_when_option_missing(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: admin_notices hook renders
    public function test_render_all_outputs_notice_markup_per_entry(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    public function test_render_all_returns_early_without_manage_woocommerce_cap(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    // AC-12: dismiss_policy → action buttons
    public function test_render_no_plain_dismiss_for_requires_resolution(): void
    {
        $this->markTestIncomplete('AC-12');
    }

    public function test_render_emits_mark_resolved_button_with_data_attr(): void
    {
        $this->markTestIncomplete('AC-12');
    }

    public function test_render_includes_dismiss_nonce(): void
    {
        $this->markTestIncomplete('AC-12');
    }

    // AC-13 (store-side): admin_notices hook registered idempotently
    public function test_plugin_init_registers_admin_notices_hook(): void
    {
        $this->markTestIncomplete('AC-13');
    }

    public function test_plugin_init_does_not_register_admin_notices_hook_twice(): void
    {
        $this->markTestIncomplete('AC-13');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-37-failed-ops-repo` | `Failure\FailedOpsRepo` (`record`, `findById`) und `Failure\RetryPolicyListener::on_action_failed()` | infrastructure + listener | Listener-Edit haengt Notifier+Store-Calls hinten an `record()`-Insert; FQCNs siehe Slice-37 Provides-Tabelle. |
| `slice-05-options-defaults` | Options `spreadconnect_notify_emails`, `spreadconnect_notify_on_order_failure`, `spreadconnect_notify_on_sync_failure`, `spreadconnect_notify_on_webhook_failure` mit Defaults `''`/`true`/`true`/`false` | WP Options | `get_option(..., $default)` mit Slice-05-Defaults als Fallback. |
| `slice-11-settings-form` | UI-Toggles fuer die 4 Notify-Settings (Section ⑧) + Sanitizer | Settings-Form | Slice 11 AC-7 garantiert Boolean-Cast; AC-6 garantiert Email-CSV-Validation. |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::init()` | bootstrap class | Edit-Eintrag fuer `add_action('admin_notices', ...)` und Listener-Wiring. |
| WordPress | `wp_mail()`, `get_option`/`update_option`/`delete_option`, `add_action`, `has_action`, `is_admin`, `current_user_can('manage_woocommerce')`, `admin_url`, `wp_create_nonce`, `esc_html`, `esc_attr`, `esc_url`, `__()`, `wc_get_logger()` | WP-API | Standard. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Failure\FailureNotifier` | application class | Slice 37 (`RetryPolicyListener`-Edit ruft `dispatch()` nach `record()`); Slice 31 (Auto-Confirm-Pre-Check-Failure ruft direkt — out-of-scope hier, nur Interface bereitgestellt) | Konstruktor `__construct(?\WC_Logger $logger = null)`; Public-Methode `dispatch(array $failedOpRow): bool` |
| `SpreadconnectPod\Failure\AdminNoticeStore` | infrastructure class | Slice 38 (Failed-Ops-UI ruft `removeByFailedOpId()` bei Resolve/Dismiss); Slice 30 (Order.needs-action triggert `add()`); Slice 31 (Auto-Confirm-Pre-Check triggert `add()` ohne FailedOps-Row); Slice 46 (Dashboard-Card zaehlt via `count()`) | Konstruktor parameterlos; Public-Methoden: `add(array $failedOpRow): bool`, `findAll(): array`, `count(?string $severity = null): int`, `removeByFailedOpId(int $id): bool`, `removeByNoticeId(string $noticeId): bool`, `renderAll(): void` (Hook-Callback) |
| Notice-Severity-Enum (string) | shared convention | Slice 38 (UI-Filter), Slice 46 (Dashboard-Aggregate) | Enum (string): `'error'`, `'warning'`, `'info'` |
| Notice-Dismiss-Policy-Enum (string) | shared convention | Slice 38 (UI rendert per-policy Action-Buttons), Slice 30 (Order.needs-action setzt `'mark_resolved'`) | Enum (string): `'requires_resolution'`, `'mark_resolved'`, `'dismissible'` |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Failure/FailureNotifier.php` — Neue Klasse `final class FailureNotifier`. Konstruktor `(?\WC_Logger $logger = null)`. Public-Methode `dispatch(array $failedOpRow): bool`. Implementiert: Op-Type → Flag-Mapping (AC-2), Recipients-CSV-Parsing aus `spreadconnect_notify_emails` (Slice 11 sanitized), `wp_mail()`-Aufruf mit Subject/Body/Headers (AC-1), Empty-Recipient-Skip (AC-3), `\Throwable`-Swallow (AC-4). Logging via `wc_get_logger()` Source `'spreadconnect-failure'`.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Failure/AdminNoticeStore.php` — Neue Klasse `final class AdminNoticeStore`. Konstruktor parameterlos. Public-Methoden: `add(array $failedOpRow): bool`, `findAll(): array`, `count(?string $severity = null): int`, `removeByFailedOpId(int $id): bool`, `removeByNoticeId(string $noticeId): bool`, `renderAll(): void`. Persistence via `get_option/update_option/delete_option` (Option-Key `spreadconnect_admin_notices`, `autoload=false`). Mapping-Tabellen Op-Type → severity (AC-7) + dismiss_policy (AC-8). `renderAll()` HTML-Output mit `esc_*`-Escaping (AC-11/AC-12).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — **Edit, nicht ersetzen.** In `init()` ergaenzen: Konstruktion `$noticeStore = new AdminNoticeStore()` + `$notifier = new FailureNotifier()`; `add_action('admin_notices', [$noticeStore, 'renderAll'], 10, 0)`. Notifier+Store werden via Konstruktor in den `RetryPolicyListener` aus Slice 37 injiziert (Slice 37-Listener-Konstruktor-Signatur wird in diesem Slice erweitert um zwei Optional-Parameter `?FailureNotifier $notifier=null, ?AdminNoticeStore $noticeStore=null` — bestehende Slice-37-Tests ungebrochen, da Default `null`).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Failure/RetryPolicyListener.php` — **Edit, nicht ersetzen** (aus Slice 37 weitergefuehrt). Konstruktor um die zwei Optional-Parameter erweitern; in `on_action_failed()` nach erfolgreichem `FailedOpsRepo::record()`-Insert (Return-Value > 0): `$row = $repo->findById($insertId); if ($row) { $notifier?->dispatch($row); $noticeStore?->add($row); }`. Try-Catch-Wrapper aus Slice 37 bleibt — Notifier+Store wirft selbst nicht (AC-4 / AC-5), dennoch defensive `?->`-Aufrufe.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-09-failure-notifier.php` und `tests/slices/pod-shop-mvp/slice-09-admin-notice-store.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- KEIN Notification-Batching / Digest (UX-Review F-13 ist Backlog) — Slice 39 sendet eine Mail pro `record()`-Insert. Aggregation/Dedup ist out-of-scope.
- KEINE AJAX-Actions fuer `[Mark Resolved]` / Plain-Dismiss / `[View in Failed-Ops]`-Click — Slice 38 implementiert diese AJAX-Handler. Slice 39 liefert nur das HTML-Markup mit `data-*`-Attributen + `wp_create_nonce`-hidden-input fuer den Hand-off.
- KEINE Order-Note (Order-spezifische Notes werden bereits in Slice 28/30 als `WC_Order_Note` geschrieben — keine Duplikation).
- KEINE Auto-Resolution durch nachtraeglich eintreffende `Order.processed`-Webhooks (Slice 30 erweitert spaeter — out-of-scope hier).
- KEINE Dashboard-Card-Counts (Slice 46 nutzt `AdminNoticeStore::count()` — Provides-Interface ist da, Consumer-Slice spaeter).
- KEINE eigenen Settings-Felder — alle 4 `notify_*`-Options + Recipients-CSV stammen aus Slice 11 + 05.
- KEINE Aenderung am `FailedOpsRepo` aus Slice 37 (nur Listener-Konstruktor-Erweiterung um Optional-Parameter).
- KEIN HTML-Output bei `is_admin()=false` (Frontend-Requests dispatcht WP `admin_notices`-Hook ohnehin nicht; defensive guard nicht noetig — early return via Capability-Check in AC-11 reicht).

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in beiden neuen Dateien.
- `final class` fuer beide neuen Klassen.
- Recipients-Parsing: Setting `spreadconnect_notify_emails` ist Slice-11-sanitized (CSV mit `, `-Separator); `dispatch()` nutzt `array_filter(array_map('trim', explode(',', $csv)))` + `array_values(array_filter(..., 'is_email'))` — Defensive-Filter falls Pre-Sanitize bypassed.
- `wp_mail()`-`$headers` MUSS mindestens `Content-Type: text/plain; charset=UTF-8` enthalten; Plain-Text-Body, kein HTML (PHPMailer-Hook-Risiko minimieren).
- Subject-Template: `sprintf('[Spreadconnect] %s — %s', $opTypeLabel, $entityRef)` mit `__()` fuer i18n (z. B. `__('Order failed', 'spreadconnect-pod')`); Op-Type-Label-Map als private const.
- Hub-Deeplink im Body: `admin_url('admin.php?page=spreadconnect&section=failed&highlight=' . $row['id'])`.
- Option-Key `spreadconnect_admin_notices` — Schema: `array<int, array{notice_id: string, failed_op_id: int, op_type: string, related_entity_type: string, related_entity_id: string, error_message: string, error_code: string, created_at: string, severity: 'error'|'warning'|'info', dismiss_policy: 'requires_resolution'|'mark_resolved'|'dismissible'}>`. `update_option()`-`autoload`-Parameter ist **PFLICHT `false`** — Notice-Liste darf NICHT auf jeder Page-Load aus DB geladen werden, wenn nicht im Admin-Context.
- `renderAll()` HTML-Output: pro Notice ein `<div class="notice notice-{severity} ..."><p>...</p><p class="actions">...</p></div>`-Block. Alle dynamischen Werte via `esc_html()`, URLs via `esc_url()`, `data-*` via `esc_attr()`. Capability-Check `current_user_can('manage_woocommerce')` als erste Anweisung.
- Logging via `wc_get_logger()->warning()/error()` mit Source `'spreadconnect-failure'` (architecture.md Z. 398). Kein `error_log()`.
- Idempotency: `notice_id` deterministisch via `'failed_op_' . (int) $failedOpRow['id']`. Pre-Add-Check: `array_key_exists($noticeId, indexBy(notice_id, $list))`.
- Listener-Konstruktor-Erweiterung in Slice 37-Datei: zwei Optional-Parameter mit `null`-Default — bestehende Slice-37-Tests instanziieren `new RetryPolicyListener($repo)` ohne Notifier/Store, was weiterhin funktioniert (AC-4 / AC-5 sind Notifier-/Store-Concern, nicht Listener-Concern).
- HPOS: irrelevant in Slice 39 (Option-Store + Mail, kein WC-Order-Meta).
- KEIN dependency injection container — Konstruktion inline in `Bootstrap\Plugin::init()` analog Slice 37.
- KEINE Translations in `de_DE.po` schreiben — Strings werden via `__()` markiert; Slice 46 sammelt sie ein.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + 03 + 04 + 05 + 06 + 11 + 28 + 37 + ff.) | **Edit, nicht ersetzen.** Bisherige `init()`-Logik (HPOS-Declare, Schema-Activate, Options-Defaults, i18n, Order-Hooks, Catalog-Hooks, AS-Failed-Action-Listener aus Slice 37) bleibt unveraendert; Slice 39 ergaenzt eine `add_action('admin_notices', ...)`-Registrierung + uebergibt Notifier+Store an `RetryPolicyListener`. |
| `wordpress/plugins/spreadconnect-pod/includes/Failure/RetryPolicyListener.php` (Slice 37) | **Edit, nicht ersetzen.** Konstruktor um zwei Optional-Parameter erweitern; in `on_action_failed()` nach erfolgreichem `record()`-Insert die Aufruf-Sequenz `$notifier?->dispatch($row); $noticeStore?->add($row);` einfuegen. Slice-37-Tests bleiben gruen (Default-`null`). |
| `wordpress/plugins/spreadconnect-pod/includes/Failure/FailedOpsRepo.php` (Slice 37) | **Wiederverwendet, unveraendert.** Listener nutzt `findById($insertId)` zum Reload der frisch geschriebenen Row. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/OptionsDefaults.php` (Slice 05) | **Wiederverwendet, unveraendert.** Die 4 `notify_*`-Defaults werden bei Plugin-Activate gesetzt; `get_option()` faellt im Notifier auf diese Defaults zurueck. |
| `wordpress/plugins/spreadconnect-pod/includes/Settings/SettingsValidator.php` (Slice 11) | **Wiederverwendet, unveraendert.** Recipients-CSV ist bereits per `sanitize_email`-Filter pre-validated; Notifier nutzt zusaetzliche Defensive-Filter. |
| `wordpress/plugins/spreadconnect-pod/includes/Logging/WcLoggerAdapter.php` (Slice 42 — geplant) | **Forward-Reference: noch nicht vorhanden.** Slice 39 nutzt `wc_get_logger()` direkt mit Source `'spreadconnect-failure'`; Slice 42 ersetzt spaeter durch Adapter — Notifier-Logging-Stellen sind die Migration-Targets. |

**Referenzen:**
- Architecture: `architecture.md` -> Service-Map Z. 388-390 — `Failure\FailureNotifier` + `Failure\AdminNoticeStore` Verantwortlichkeit + Side-Effects.
- Architecture: `architecture.md` -> WP Options Z. 335-338 — `spreadconnect_notify_emails` + 3 `notify_on_*`-Flags + Defaults.
- Architecture: `architecture.md` -> Flow C Z. 425-429 — `FailureNotifier::dispatch()` Aufrufpunkt nach `FailedOpsRepo::record()`.
- Architecture: `architecture.md` -> Quality Attrs Z. 680 — Done-Signal `email + Admin-Notice` als Teil der Failure-Recovery-Pipeline.
- Architecture: `architecture.md` -> Error Handling Z. 607-608 — `4xx -> Admin-Notice`, `5xx after 3rd attempt -> Failed-Ops + email + Admin-Notice`.
- Discovery: `discovery.md` -> Slice 9 "Failure-Recovery" Z. 930 — Email-Notify + Persistent Admin-Notice mit Order-Mark-Resolved.
- Discovery: `discovery.md` -> UI-Components Z. 445 — `failure_admin_notice` `dismissible`/`permanent` "Bleibt bis Admin Mark as resolved klickt".
- Discovery: `discovery.md` -> Hooks Z. 879 — `admin_notices` "Render Persistent Failure-Notices".
- Slim-Slices: `slices/slim-slices.md` -> Slice-39-Eintrag Z. 582-589 — Done-Signal `wp_mail mit Recipients (wenn Flag on); Notice landet in Option + wird auf admin_notices-Hook gerendert`.
- Slice 37: `slices/slice-37-failed-ops-repo.md` -> Provides-Tabelle + Constraints — `RetryPolicyListener::on_action_failed()` ist der Einhaengepunkt; Slice 39 erweitert Listener-Konstruktor.
- Slice 11: `slices/slice-11-settings-form.md` -> AC-6 + AC-7 — Recipients-CSV + Boolean-Toggle-Sanitization sind pre-Slice-39 garantiert.
- Wireframes: `wireframes.md` -> Z. 29 / 149 / 968 / 988 — `failure_admin_notice` Header-Banner auf Screen 1/3/5/11; `auto_confirm_failed_no_shipping` als Beispiel-Notice (Slice 31 nutzt dann den Store hier).
- UX-Review: `checks/ux-expert-review.md` -> F-13 / Z. 491-512 — Notification-Fatigue-Risk; Batching ist Backlog (siehe Scope-Grenzen); Per-Op-Type-Severity + Dismiss-Policy in Slice 39 sind Teil der Mitigation.
