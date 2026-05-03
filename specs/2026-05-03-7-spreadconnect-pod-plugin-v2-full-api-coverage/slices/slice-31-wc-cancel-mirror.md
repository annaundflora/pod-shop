# Slice 31: WC-Cancel-Mirror + Auto-Confirm-Timer

> **Slice 31 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-31-wc-cancel-mirror` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-29-order-confirm-cancel-jobs", "slice-30-order-webhooks-handler"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WC 10.5 HPOS + Action Scheduler + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA via Slice 32 Order-Edit Meta-Box "Cancel"-Button + WC-Order-Status-Wechsel auf `cancelled`) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `wc_get_order`, `as_unschedule_action`, `as_enqueue_async_action`, `as_schedule_single_action`, `as_has_scheduled_action`, `get_option`, `wc_get_logger`; SpreadconnectClient + OrderStateMachine + AdminNoticeStore-Stub via Konstruktor injiziert) |

---

## Ziel

Schliesst Slice 5 outbound-seitig: WC-Cancel-Mirror (`woocommerce_order_status_cancelled` -> Race-Schutz via `as_unschedule_action('spreadconnect/confirm_order')` -> separater `OrderCancelMirrorJob` mit eigenem Hook `spreadconnect/cancel_order_mirror`) sowie der Auto-Confirm-Timer (Setting `spreadconnect_auto_confirm` ∈ `immediate`/`after_minutes` schedulet `spreadconnect/confirm_order` post-Submit-Success). Auto-Confirm-Pre-Check-Failure schreibt eine persistente Admin-Notice (kein FailedOps-Eintrag, Architecture Z. 591).

---

## Acceptance Criteria

1) **AC-WC-Cancel-Hook-Wiring — `woocommerce_order_status_cancelled`-Listener**
   **GIVEN** Plugin-Boot
   **WHEN** `Bootstrap\Plugin::init()` durchlaeuft
   **THEN** ist `add_action('woocommerce_order_status_cancelled', [OrderHandler::class, 'on_cancelled'], 10, 2)` registriert (Priority `10`, Args `2` analog `on_processing` aus Slice 28). Zusaetzlich `add_action('spreadconnect/cancel_order_mirror', [OrderCancelMirrorJob::class, 'handleStatic'], 10, 1)`. Idempotent bei doppeltem `init()`-Aufruf (analog Slice 28 AC-9).

2) **AC-Cancel-Mirror-StateNew — Race-Schutz + Enqueue**
   **GIVEN** WC-Order mit `_spreadconnect_state = 'NEW'`, `_spreadconnect_order_id = 'sc_42'`; Setting `spreadconnect_auto_cancel_mirror = true`; eine pending `spreadconnect/confirm_order`-Action ist noch gescheduled (Auto-Confirm-Timer aus AC-7)
   **WHEN** `OrderHandler::on_cancelled($order_id, $order)` feuert
   **THEN** in genau dieser Reihenfolge: (a) `as_unschedule_action('spreadconnect/confirm_order', ['order_id' => $order_id], 'spreadconnect')` wird **genau einmal** aufgerufen (Race-Schutz: verhindert dass Auto-Confirm den bereits cancelnden State ueberholt — Architecture Z. 642); (b) `as_has_scheduled_action('spreadconnect/cancel_order_mirror', ['order_id' => $order_id], 'spreadconnect')` wird konsultiert; bei `false` -> `as_enqueue_async_action('spreadconnect/cancel_order_mirror', ['order_id' => $order_id], 'spreadconnect')` (Idempotency-Guard analog Slice 28 AC-3). KEIN direkter API-Call im Hook-Handler.

3) **AC-Cancel-Mirror-StateNotNew — Order-Note + Persistente Admin-Notice (kein API-Call)**
   **GIVEN** WC-Order mit `_spreadconnect_state ∈ {'CONFIRMED', 'PROCESSED'}`, `_spreadconnect_order_id = 'sc_42'`
   **WHEN** `OrderHandler::on_cancelled($order_id, $order)` feuert
   **THEN** (a) ruft `as_unschedule_action('spreadconnect/confirm_order', ...)` **trotzdem** auf (idempotent; haengende Auto-Confirm-Schedules werden konsistent geraeumt); (b) ruft `as_enqueue_async_action('spreadconnect/cancel_order_mirror', ...)` **nicht** auf; (c) schreibt Order-Note mit Substring `'Cannot cancel in Spreadconnect (state: <actual>)'` (analog Discovery Z. 169 + Architecture Z. 593); (d) ruft `recordPersistentNotice($order, sprintf('Cannot mirror cancel: SC-state=%s', $state))` (in dieser Slice als `wc_get_logger()->error()`-Stub mit Source `spreadconnect-order-service` und Tag `admin_notice_pending_record`, Context `['order_id' => $order_id, 'reason' => 'cancel_blocked_by_state', 'op_type' => 'wc_cancel_mirror_blocked', 'sc_state' => $state]` — echte `Failure\AdminNoticeStore::add()` kommt in Slice 39); (e) wirft **nicht**.

4) **AC-Cancel-Mirror-NoOrderID — Skip ohne SC-OrderID**
   **GIVEN** WC-Order ohne `_spreadconnect_order_id`-Meta (Submit nie erfolgreich gelaufen) ODER mit `_spreadconnect_state = 'failed_to_submit'`
   **WHEN** `OrderHandler::on_cancelled` feuert
   **THEN** ruft weder `as_unschedule_action` noch `as_enqueue_async_action` auf (silent-skip — kein SC-Eintrag existiert); schreibt Info-Log mit Tag `cancel_mirror_skipped_no_sc_order_id`; wirft **nicht**. Order-Note wird **nicht** geschrieben (UI-Noise vermeiden).

5) **AC-Cancel-Mirror-Disabled — Setting `auto_cancel_mirror = false` deaktiviert Mirror komplett**
   **GIVEN** WC-Order mit State `NEW`, `_spreadconnect_order_id = 'sc_42'`; Setting `spreadconnect_auto_cancel_mirror = false`
   **WHEN** `OrderHandler::on_cancelled` feuert
   **THEN** ruft weder `as_unschedule_action` noch `as_enqueue_async_action` auf; schreibt Info-Log mit Tag `cancel_mirror_disabled_by_setting`; wirft **nicht**. Architecture Z. 329 dokumentiert die Toggle-Semantik (Default `true`).

6) **AC-CancelMirrorJob-Success — Job ruft `cancelOrder` und CASt `NEW->CANCELLED`**
   **GIVEN** `OrderCancelMirrorJob::handle(['order_id' => 7])` wird vom AS aufgerufen; WC-Order #7 hat `_spreadconnect_state = 'NEW'`, `_spreadconnect_order_id = 'sc_42'`; `SpreadconnectClient::cancelOrder('sc_42')` liefert 2xx mit `['id' => 'sc_42', 'state' => 'CANCELLED']`
   **WHEN** der Job laeuft
   **THEN** in Reihenfolge: (a) **Re-Check** `_spreadconnect_state === 'NEW'` (Race-Schutz: zwischen Enqueue und Run koennte ein Webhook bereits `PROCESSED` geschrieben haben — siehe AC-9); (b) `SpreadconnectClient::cancelOrder('sc_42')` wird **genau einmal** aufgerufen; (c) `OrderStateMachine::compareAndSet($order, 'NEW', 'CANCELLED')` -> `true`; (d) `$order->add_order_note('Cancelled in Spreadconnect (#SC-sc_42, mirrored from WC cancel)', false, false)`; (e) Job wirft **nicht**. KEINE WC-Status-Mutation (WC-Order ist bereits `cancelled` — Trigger-Pfad).

7) **AC-AutoConfirm-Schedule-Immediate — Setting `immediate` schedult sofort**
   **GIVEN** Slice-28-`OrderSubmitJob` hat einen 2xx-Submit erfolgreich abgeschlossen (`_spreadconnect_order_id = 'sc_42'`, State auf `'NEW'` via CAS); Setting `spreadconnect_auto_confirm = 'immediate'`; `spreadconnect_default_shipping_type = 'STANDARD'` (non-empty — Auto-Confirm-Gating Architecture Z. 326)
   **WHEN** der Submit-Success-Pfad in `OrderSubmitJob` (Slice 28 AC-4 Schritt e) den Auto-Confirm-Timer-Hook delegiert
   **THEN** ruft der Auto-Confirm-Scheduler `as_schedule_single_action(time(), 'spreadconnect/confirm_order', ['order_id' => 7], 'spreadconnect')` (sofort = `time()` als Timestamp; Args-Shape identisch zu Slice 29 AC-12). Bei `as_has_scheduled_action(...)`-true erfolgt **kein** zweiter Schedule (Idempotency-Guard).

8) **AC-AutoConfirm-Schedule-AfterMinutes — Setting `after_minutes` schedult mit Delay**
   **GIVEN** Submit-Success wie AC-7; Setting `spreadconnect_auto_confirm = 'after_minutes'`; `spreadconnect_auto_confirm_minutes = 30`; valider Default-Shipping-Type
   **WHEN** der Auto-Confirm-Scheduler laeuft
   **THEN** ruft `as_schedule_single_action(time() + 30 * MINUTE_IN_SECONDS, 'spreadconnect/confirm_order', ['order_id' => 7], 'spreadconnect')`. Bei `auto_confirm_minutes = 0` faellt der Pfad auf Verhalten von AC-7 zurueck (`time()` als Timestamp).

9) **AC-AutoConfirm-Schedule-Off — Setting `off` schedult NICHT**
   **GIVEN** Submit-Success wie AC-7; Setting `spreadconnect_auto_confirm = 'off'` (oder fehlend, default per Slice 05)
   **WHEN** der Auto-Confirm-Scheduler-Pfad ausgewertet wird
   **THEN** ruft `as_schedule_single_action()` **nicht** auf; kein Order-Note, kein Log-Tag fuer Auto-Confirm; Submit-Success-Pfad in Slice 28 endet wie zuvor (State `'NEW'`).

10) **AC-AutoConfirm-PreCheck-Failure — Persistente Admin-Notice statt FailedOps**
    **GIVEN** Auto-Confirm-Action `spreadconnect/confirm_order` feuert via Timer fuer `order_id = 7`; zwischen Schedule (AC-7/8) und Run wurde der `_spreadconnect_shipping_type`-Meta entfernt UND `spreadconnect_default_shipping_type` ist mittlerweile leer (Architecture Z. 591 — Edge-Case)
    **WHEN** `OrderConfirmJob::handle(['order_id' => 7])` aus Slice 29 laeuft und sein Pre-Check (Slice 29 AC-1) faellt
    **THEN** wird die Pre-Check-Failure-Notice in dieser Slice angeschlossen: `recordPersistentNotice($order, sprintf('Auto-confirm could not run for order #%d — no shipping type set', $order_id))` (analog AC-3, Source `spreadconnect-order-service`, Tag `admin_notice_pending_record`, Context `['order_id' => 7, 'reason' => 'auto_confirm_pre_check_failed', 'op_type' => 'auto_confirm_pre_check_failed']`). **KEIN** `FailedOpsRepo`-Tag (`failed_op_pending_record` wird **nicht** geschrieben — explizite Absage der DLQ-Aufnahme, Discovery Z. 591). Order-Note bleibt der Slice-29-Substring `'Cannot confirm: no shipping type set'`. Implementation: Slice 31 erweitert `OrderConfirmJob::handle` (oder den Pre-Check-Pfad ueber einen Hook/Marker — Implementer waehlt) so, dass der Auto-Confirm-Trigger-Kontext (z. B. via `$args['source'] = 'auto_confirm_timer'` oder per `as_get_action`-Inspection) erkannt wird; ein Pre-Check-Fail aus diesem Kontext schreibt **zusaetzlich** den Notice-Stub und unterdrueckt jeden FailedOps-Logging-Tag.

11) **AC-CancelMirrorJob-RaceWebhook — State wechselt zwischen Enqueue und Run**
    **GIVEN** `OrderCancelMirrorJob::handle(['order_id' => 7])` startet; zwischen Enqueue (AC-2) und Run hat ein `Order.processed`-Webhook (Slice 30 AC-2) den State direkt auf `'PROCESSED'` geschrieben; `_spreadconnect_order_id = 'sc_42'`
    **WHEN** der Job seinen Re-Check ausfuehrt
    **THEN** `_spreadconnect_state !== 'NEW'` -> ruft `SpreadconnectClient::cancelOrder()` **nicht** auf; `OrderStateMachine::compareAndSet()` wird **nicht** auf `CANCELLED` gerufen; Order-Note `'Cancel-mirror skipped: SC-state advanced to PROCESSED before mirror could run'`; Info-Log Tag `cancel_mirror_skipped_state_advanced`; persistente Admin-Notice via `recordPersistentNotice($order, ...)` analog AC-3 mit Reason `'cancel_mirror_state_advanced'`; Job wirft **nicht**.

12) **AC-CancelMirrorJob-Errors — Permanent vs Transient (Slice-29-Konvention)**
    **GIVEN** `OrderCancelMirrorJob::handle(['order_id' => 7])`; State `'NEW'`; `cancelOrder()` wirft entweder `SpreadconnectClientError` (4xx — permanent) ODER `SpreadconnectTransientError` (5xx — transient)
    **WHEN** der Job die Exception faengt
    **THEN** Branch-Logik **identisch zu Slice 29 AC-9/AC-10** (Convention-Reuse): (a) `SpreadconnectClientError` -> Order-Note `'Cancel-mirror failed (4xx)'`, Tag `failed_op_pending_record` mit Context `['op_type' => 'cancel_order_mirror', 'related_entity_type' => 'order', 'related_entity_id' => 7]`, kein State-Mutate, kein Re-Throw; (b) `SpreadconnectTransientError` -> Re-Throw fuer AS-Retry `1m/5m/15m` (Architecture Z. 551). Specific-First-Catch-Order analog Slice 28/29.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Drei PHPUnit-Test-Files: `tests/slices/pod-shop-mvp/slice-05-wc-cancel-mirror.php` (Hook-Wiring + on_cancelled-Branches AC-1..AC-5, AC-12), `tests/slices/pod-shop-mvp/slice-05-cancel-mirror-job.php` (Job-Logik AC-6, AC-11, AC-12), `tests/slices/pod-shop-mvp/slice-05-auto-confirm-timer.php` (Auto-Confirm-Schedule + Pre-Check-Failure AC-7..AC-10). Brain\Monkey-Setup mit `Functions\expect('as_unschedule_action')`, `Functions\expect('as_enqueue_async_action')`, `Functions\expect('as_schedule_single_action')`, `Functions\when('as_has_scheduled_action')`, `Functions\when('get_option')`, `Functions\when('time')->justReturn(1700000000)`. SpreadconnectClient + OrderStateMachine + Logger als Mockery-Mocks injiziert. AdminNoticeStore bleibt Stub (Slice 39) — Test-Writer prueft `wc_get_logger()`-Mock auf Tag `admin_notice_pending_record` mit Context-Reasons.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-05-wc-cancel-mirror.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class WcCancelMirrorTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    // AC-1: Bootstrap registriert woocommerce_order_status_cancelled + cancel_order_mirror Hook
    public function test_plugin_init_registers_cancelled_and_mirror_hooks(): void { $this->markTestIncomplete('AC-1'); }
    public function test_plugin_init_is_idempotent_on_double_call(): void { $this->markTestIncomplete('AC-1'); }

    // AC-2: State NEW -> as_unschedule confirm_order + enqueue cancel_order_mirror
    public function test_on_cancelled_unschedules_confirm_and_enqueues_mirror_when_state_new(): void { $this->markTestIncomplete('AC-2'); }
    public function test_on_cancelled_skips_enqueue_when_mirror_already_scheduled(): void { $this->markTestIncomplete('AC-2'); }

    // AC-3: State CONFIRMED/PROCESSED -> Order-Note + Admin-Notice-Stub, kein Enqueue
    public function test_on_cancelled_writes_order_note_and_notice_stub_when_state_advanced(): void { $this->markTestIncomplete('AC-3'); }
    public function test_on_cancelled_does_not_enqueue_mirror_when_state_not_new(): void { $this->markTestIncomplete('AC-3'); }
    public function test_on_cancelled_still_unschedules_pending_confirm_when_state_advanced(): void { $this->markTestIncomplete('AC-3'); }

    // AC-4: Kein _spreadconnect_order_id -> silent-skip
    public function test_on_cancelled_skips_silently_when_no_sc_order_id_meta(): void { $this->markTestIncomplete('AC-4'); }

    // AC-5: Setting auto_cancel_mirror=false -> kein Enqueue, kein Unschedule
    public function test_on_cancelled_skips_when_auto_cancel_mirror_setting_disabled(): void { $this->markTestIncomplete('AC-5'); }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-05-cancel-mirror-job.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class OrderCancelMirrorJobTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    // AC-6: State NEW -> cancelOrder() + CAS NEW->CANCELLED + Order-Note
    public function test_handle_calls_cancel_and_cas_to_cancelled_when_state_new(): void { $this->markTestIncomplete('AC-6'); }
    public function test_handle_writes_mirror_order_note(): void { $this->markTestIncomplete('AC-6'); }
    public function test_handle_does_not_mutate_wc_status(): void { $this->markTestIncomplete('AC-6'); }

    // AC-11: Race — State zwischen Enqueue und Run advanced -> skip + persistent notice
    public function test_handle_skips_when_state_advanced_to_processed_before_run(): void { $this->markTestIncomplete('AC-11'); }
    public function test_handle_writes_state_advanced_admin_notice_stub(): void { $this->markTestIncomplete('AC-11'); }

    // AC-12: 4xx permanent -> Logging-Tag + kein Re-Throw
    public function test_handle_permanent_4xx_logs_failed_op_with_cancel_order_mirror_op_type(): void { $this->markTestIncomplete('AC-12'); }
    public function test_handle_permanent_4xx_does_not_rethrow(): void { $this->markTestIncomplete('AC-12'); }

    // AC-12: 5xx transient -> Re-Throw fuer AS-Retry
    public function test_handle_transient_5xx_rethrows_unchanged(): void { $this->markTestIncomplete('AC-12'); }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-05-auto-confirm-timer.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class AutoConfirmTimerTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    // AC-7: Setting immediate -> as_schedule_single_action mit time()
    public function test_submit_success_schedules_confirm_immediately_when_setting_immediate(): void { $this->markTestIncomplete('AC-7'); }
    public function test_submit_success_skips_schedule_when_already_scheduled(): void { $this->markTestIncomplete('AC-7'); }

    // AC-8: Setting after_minutes=30 -> as_schedule_single_action mit time()+1800
    public function test_submit_success_schedules_confirm_with_minute_delay(): void { $this->markTestIncomplete('AC-8'); }
    public function test_submit_success_with_zero_minutes_falls_back_to_immediate(): void { $this->markTestIncomplete('AC-8'); }

    // AC-9: Setting off -> kein Schedule
    public function test_submit_success_does_not_schedule_when_setting_off(): void { $this->markTestIncomplete('AC-9'); }
    public function test_submit_success_does_not_schedule_when_setting_missing(): void { $this->markTestIncomplete('AC-9'); }

    // AC-10: Auto-Confirm-Pre-Check-Failure -> persistent notice, KEIN failed_op_pending_record-Tag
    public function test_auto_confirm_pre_check_failure_writes_admin_notice_stub(): void { $this->markTestIncomplete('AC-10'); }
    public function test_auto_confirm_pre_check_failure_does_not_record_failed_op(): void { $this->markTestIncomplete('AC-10'); }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-28-order-submit-job` | `Order\OrderHandler` Klasse + 2xx-Submit-Success-Pfad | existing class | Slice 31 fuegt `on_cancelled()`-Methode + Auto-Confirm-Schedule-Trigger hinzu (Edit, Klassen-Header unveraendert). |
| `slice-29-order-confirm-cancel-jobs` | `Order\OrderConfirmJob::handle` + `OrderCancelJob` (Vorlage) + Hook-Args-Shape `['order_id' => int]` | existing class + convention | Slice 31 schedult `spreadconnect/confirm_order` mit identischem Args-Shape; Pre-Check-Failure-Pfad aus AC-1 wird erweitert. |
| `slice-29-order-confirm-cancel-jobs` | `Order\OrderCancelJob` als Konvention-Vorlage fuer `OrderCancelMirrorJob` | class (analog) | Mirror-Job spiegelt 4xx/5xx-Branch + State-Check-Reihenfolge 1:1; nur Hook-Name + Order-Note-String unterscheiden sich. |
| `slice-30-order-webhooks-handler` | `OrderStateMachine::compareAndSet` + State-Werte (`'NEW'`, `'PROCESSED'`) | instance method | AC-11 setzt voraus, dass Slice 30 webhook-getriebene State-Direct-Writes vor dem Job-Run vornehmen kann. |
| `slice-27-order-state-machine` | `compareAndSet(WC_Order, 'NEW', 'CANCELLED')` | instance method | Job nutzt CAS analog Slice 29 AC-7. |
| `slice-10-endpoint-methods` | `SpreadconnectClient::cancelOrder(string)` | public method | 2xx -> assoc-Array; 4xx/5xx -> typisierte Exceptions. |
| `slice-05-options-defaults` | Options `spreadconnect_auto_confirm`, `spreadconnect_auto_confirm_minutes`, `spreadconnect_auto_cancel_mirror`, `spreadconnect_default_shipping_type` | option keys | Slice 31 liest sie via `get_option(...)` mit Defaults aus Slice 05. |
| WordPress / WooCommerce | `woocommerce_order_status_cancelled`-Hook + `wc_get_order` + `WC_Order::add_order_note`/`get_meta`/`update_meta_data`/`save` | WC-API | HPOS-aktiv (Slice 03). |
| Action Scheduler | `as_unschedule_action`, `as_enqueue_async_action`, `as_schedule_single_action`, `as_has_scheduled_action` | AS-API | Group `'spreadconnect'`. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Order\OrderHandler::on_cancelled` | instance method | WC-Hook `woocommerce_order_status_cancelled` | `public function on_cancelled(int $order_id, \WC_Order $order): void` |
| `SpreadconnectPod\Order\OrderCancelMirrorJob::handle` | instance method | AS-Hook `spreadconnect/cancel_order_mirror`; Slice 37 (`RetryPolicyListener` faengt nach 3 Retries permanent) | `public function handle(array $args): void` mit `$args = ['order_id' => int]` |
| `SpreadconnectPod\Order\OrderCancelMirrorJob::handleStatic` | static method (Bridge) | Bootstrap-Hook-Registration | `public static function handleStatic(array $args): void` |
| Auto-Confirm-Schedule-Trigger (in `OrderHandler` oder `OrderSubmitJob`-Edit) | instance method (private/internal) | Slice 28 Submit-Success-Pfad ruft Trigger nach State `'NEW'`-CAS | Signatur Implementer-Wahl, z. B. `private function maybeScheduleAutoConfirm(\WC_Order $order): void` |
| Hook-Name `spreadconnect/cancel_order_mirror` | AS-action-name (string-literal) | Slice 32 (Order-Edit-Mirror-Resend optional), Slice 37 (Retry-Policy), Slice 38 (Failed-Ops-Resend) | `'spreadconnect/cancel_order_mirror'` Group `'spreadconnect'` |
| Logging-Stub-Tag `admin_notice_pending_record` | Logging-Convention (string-literal, Reuse aus Slice 30 AC-5) | Slice 39 `AdminNoticeStore` ersetzt Stub durch Option-Write | Source `spreadconnect-order-service`, Level `error`, Context `['order_id' => int, 'reason' => string, 'op_type' => string]`. Konkrete `op_type`-Werte aus Slice 31: `'wc_cancel_mirror_blocked'`, `'cancel_mirror_state_advanced'`, `'auto_confirm_pre_check_failed'` |
| Auto-Confirm-Pre-Check-Failure-Suppression-Convention | Logging-Convention (negative — kein `failed_op_pending_record`) | Slice 37 `RetryPolicyListener` MUSS Auto-Confirm-Pre-Check-Failures **nicht** als FailedOps aufnehmen | Per AC-10 explizit: bei `op_type='auto_confirm_pre_check_failed'`-Notice-Tag wird **kein** `failed_op_pending_record`-Tag in derselben Job-Run-Sequenz geschrieben. |

> **Mount-Point-Check:** `OrderCancelMirrorJob` wird durch `add_action`-Edit in `Bootstrap\Plugin::init()` an Action-Scheduler gehaengt (Deliverable). `OrderHandler::on_cancelled` wird durch `add_action('woocommerce_order_status_cancelled', ...)`-Edit gemountet (Deliverable). Auto-Confirm-Schedule wird in `OrderHandler` gehostet und vom Submit-Success-Pfad aus Slice 28 aufgerufen — Edit an `OrderSubmitJob.php` ODER an `OrderHandler.php` (Implementer-Wahl, dokumentiert per Inline-Kommentar).

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Order/OrderCancelMirrorJob.php` — Neue Klasse `final class OrderCancelMirrorJob`. Konstruktor injiziert `SpreadconnectClient`, `OrderStateMachine`, optional `?WC_Logger`, optional Notice-Stub-Sink (Logger reicht). Methoden `public function handle(array $args): void` und `public static function handleStatic(array $args): void`. Sequenz: Order-Lookup -> `_spreadconnect_order_id`-Idempotency -> **Re-Check** State === `'NEW'` (sonst persistent-notice + skip per AC-11) -> `cancelOrder()` -> CAS `NEW->CANCELLED` -> Order-Note. 4xx/5xx-Branch identisch zu Slice 29 AC-9/AC-10 (`op_type='cancel_order_mirror'`).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Order/OrderHandler.php` — **Erweitern, nicht ersetzen.** Ergaenzt: (a) `public function on_cancelled(int $order_id, \WC_Order $order): void` mit Sequenz aus AC-2..AC-5 (Race-Schutz `as_unschedule_action` + Setting-Check + State-Branch + persistent-notice-Stub); (b) Auto-Confirm-Schedule-Trigger (private Helper oder Hook-Reaktion auf einen Slice-28-Submit-Success-Marker; Implementer waehlt einen von zwei Wegen: (i) direkter Edit in `OrderSubmitJob`-Erfolgspfad ruft `OrderHandler::scheduleAutoConfirm($order)`, oder (ii) `do_action('spreadconnect/order_submitted', $order)` wird in Slice 28 Erfolgspfad gefeuert und Slice 31 haengt `OrderHandler` als Listener ein — Variante (ii) bevorzugt, da Slice 28 Code unveraendert bleibt). Auto-Confirm-Logik liest Setting `spreadconnect_auto_confirm` + `spreadconnect_auto_confirm_minutes` + `spreadconnect_default_shipping_type` und ruft `as_schedule_single_action(...)` per AC-7..AC-9. **Keine** Aenderung am Slice-28 Hook-Wiring fuer `on_processing`. Klassen-Konstruktor ggf. um optionalen `\SpreadconnectPod\Failure\AdminNoticeStore $noticeStore = null`-Param erweitern (Default-`null` -> Fallback auf Logging-Stub-Pfad analog Slice 30 AC-5).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Ergaenzt drei Action-Hook-Registrierungen analog Slice 28/29: (a) `add_action('woocommerce_order_status_cancelled', [$orderHandler, 'on_cancelled'], 10, 2)`; (b) `add_action('spreadconnect/cancel_order_mirror', [OrderCancelMirrorJob::class, 'handleStatic'], 10, 1)`; (c) bei Variante (ii) der Auto-Confirm-Wiring (siehe `OrderHandler.php`-Eintrag): `add_action('spreadconnect/order_submitted', [$orderHandler, 'maybeScheduleAutoConfirm'], 10, 1)`. Konstruktion der Instances inline (analog Slice 28/29). Idempotent (Slice 02 Pattern). Bestehende Slice-28/29-Registrationen unveraendert.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt die drei oben gelisteten Test-Files. Bei Variante (ii) der Auto-Confirm-Wiring wird Slice 28 (`OrderSubmitJob.php`) **nur** um eine `do_action('spreadconnect/order_submitted', $order)`-Zeile am Ende des Submit-Success-Pfads erweitert; alle Slice-28-ACs bleiben semantisch erhalten (Hook-Fire ist Side-Effect-frei fuer Slice-28-Tests). Variante (i) erfordert Slice-28-Edit mit direkter `OrderHandler`-Referenz und ist wegen DI-Kopplung weniger bevorzugt.

---

## Constraints

**Scope-Grenzen:**
- KEIN realer `Failure\AdminNoticeStore` — Slice 39. Der Notice-Pfad in dieser Slice schreibt **nur** Logging-Tags (`admin_notice_pending_record`); Slice 39 ersetzt den Stub durch echten Option-Write + `admin_notices`-Render-Hook.
- KEIN realer `Failure\FailedOpsRepo` — Slice 37. 4xx-Branch im Mirror-Job schreibt nur Logging-Tag `failed_op_pending_record` (analog Slice 28/29 Convention).
- KEIN AJAX-Handler `spreadconnect_cancel_auto_confirm` (Architecture Z. 156) — Slice 32 (Order-Edit-Meta-Box "Cancel Auto-Confirm"-Button registriert die AJAX-Action und ruft `as_unschedule_action('spreadconnect/confirm_order', ...)` direkt). Slice 31 stellt nur den Auto-Confirm-Schedule-Producer.
- KEIN Order-Edit-Meta-Box-UI fuer den Mirror — Slice 32 rendert State + Buttons.
- KEIN Order-List-Filter "Cancel Pending" — Slice 33.
- KEIN Pre-Submit-Auto-Confirm-Wiring (also Setting `auto_confirm_minutes` = 0 vor Submit) — Architecture Z. 549/550 dokumentiert Auto-Confirm als Post-Submit-Timer.
- KEINE WC-Status-Mutation durch `OrderCancelMirrorJob` — die WC-Order ist bereits `cancelled` (Trigger-Pfad); ausgehender `Order.cancelled`-Webhook (von SC) wird in Slice 30 AC-4 verarbeitet, mutiert WC-Status idempotent (skip falls bereits `cancelled`).
- KEIN Edit an `OrderConfirmJob.php` aus Slice 29, ausser dem Auto-Confirm-Trigger-Marker fuer AC-10 (z. B. via `$args['source'] = 'auto_confirm_timer'` oder Action-Hook-Inspection). Slice 29 ACs 1-12 bleiben unveraendert; Slice 31 darf nur den Notice-Pfad **anflanschen**, nicht das Pre-Check-Verhalten aendern.
- KEIN Edit an `Webhook\OrderEventHandler.php` (Slice 30). Slice 30 AC-4 schreibt CANCELLED bei `Order.cancelled`-Webhook; Slice 31 macht den Outbound-Pfad WC -> SC.
- KEIN Direct-Write auf `_spreadconnect_state` ausser via `OrderStateMachine` (Slice 27 single source of truth).
- KEIN `try`/`catch` auf andere Exceptions als `SpreadconnectClientError` und `SpreadconnectTransientError`.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in der neuen Datei und im OrderHandler-Edit (bestehender Wert; nicht entfernen).
- `final class` fuer `OrderCancelMirrorJob`.
- `OrderHandler::on_cancelled` defensiv: `if (! $order instanceof \WC_Order) { $order = wc_get_order($order_id); } if (! $order instanceof \WC_Order) { return; }` (Hook liefert je nach WC-Context andere Args).
- Pre-Check-Reihenfolge in `OrderHandler::on_cancelled`: (1) Order-Lookup; (2) Setting-Check `auto_cancel_mirror` (AC-5 short-circuit); (3) `_spreadconnect_order_id`-Check (AC-4 short-circuit); (4) `as_unschedule_action('spreadconnect/confirm_order', ...)` immer (idempotent, kein Side-Effect bei nicht-gescheduled); (5) State-Lookup; (6) Branch State === `'NEW'` (Enqueue Mirror) / sonst (persistent-notice + Order-Note).
- Pre-Check-Reihenfolge in `OrderCancelMirrorJob::handle`: (1) `$args`-Validation (`$order_id = (int) ($args['order_id'] ?? 0); if ($order_id <= 0) return;`); (2) Order-Lookup; (3) `_spreadconnect_order_id`-Check; (4) **Re-Check** State === `'NEW'` (AC-11 Race-Schutz); (5) `cancelOrder()`; (6) CAS; (7) Order-Note.
- `as_schedule_single_action`-Args **immer** `['order_id' => $id]` (assoc-array, identisch zu Slice 28/29 — sichert Idempotency-Vergleich via `as_has_scheduled_action`).
- Auto-Confirm-Setting-Lese-Konvention: `get_option('spreadconnect_auto_confirm', 'off')`, `get_option('spreadconnect_auto_confirm_minutes', 0)`, `get_option('spreadconnect_auto_cancel_mirror', true)`, `get_option('spreadconnect_default_shipping_type', '')`. Default-Werte exakt aus Slice 05 / Architecture Z. 326-329.
- Auto-Confirm-Gating-Konsistenz mit Slice 11 `SettingsValidator` (Architecture Z. 647): Setting-Validator erzwingt `auto_confirm = 'off'` bei leerem `default_shipping_type`. Slice 31 Auto-Confirm-Trigger MUSS dennoch defensiv beide Werte pruefen (AC-7 Vorbedingung); leerer `default_shipping_type` UND keine `_spreadconnect_shipping_type`-Order-Meta -> kein Schedule (Pre-Check ohne Schedule statt Pre-Check-Failure spaeter).
- HPOS-Konformitaet: `$order->update_meta_data()` + `$order->save()` (NICHT `update_post_meta()`).
- Logging via `wc_get_logger()` mit Source `'spreadconnect-order-service'` fuer alle Order-Lifecycle-Logs (analog Slice 28/29). KEIN `error_log()`.
- Order-Note-Strings in **Englisch** (Backend-only, analog Slice 28/29). Customer-facing-Status-Wechsel-Note via WC-Hook in Slice 30.
- Auto-Confirm-Pre-Check-Failure-Erkennung (AC-10): Implementer waehlt **einen** von drei Wegen: (a) Action-Args `['order_id' => $id, 'source' => 'auto_confirm_timer']` -> Slice 29 `OrderConfirmJob::handle` liest `$args['source']` und reagiert nur dann mit Notice-Stub; (b) Inspection via `\ActionScheduler::store()->find_action(...)` in Slice 29 Pre-Check-Failure-Pfad; (c) ein dedizierter Hook `do_action('spreadconnect/auto_confirm_pre_check_failed', $order)` in Slice 29, der nur in Slice 31 als Listener angemeldet wird und den Notice-Stub schreibt. Variante (c) wird empfohlen, weil sie Slice 29 nur um **eine** zusaetzliche Hook-Fire-Zeile erweitert und keine Args-Konvention aendert.
- AS-Hook-Args-Stabilitaet: Beide neuen Hooks (`spreadconnect/cancel_order_mirror`, ggf. `spreadconnect/order_submitted`) registrieren mit Priority `10`, accepted_args `1`. `woocommerce_order_status_cancelled` mit Args `2` (analog Slice 28 `on_processing`).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Order/OrderHandler.php` (Slice 28) | **Edit, nicht ersetzen.** Slice 28 liefert `__construct` + `on_processing`; Slice 31 ergaenzt `on_cancelled` + Auto-Confirm-Schedule-Trigger. Keine Aenderung an `on_processing`. |
| `wordpress/plugins/spreadconnect-pod/includes/Order/OrderCancelJob.php` (Slice 29) | **Konvention-Vorlage, unveraendert.** `OrderCancelMirrorJob` spiegelt Sequenz/Branch-Logik 1:1, Hook-Name + Order-Note + `op_type` divergieren. |
| `wordpress/plugins/spreadconnect-pod/includes/Order/OrderConfirmJob.php` (Slice 29) | **Edit minimal/optional.** Bei Variante (c) der Auto-Confirm-Pre-Check-Failure-Erkennung: eine `do_action('spreadconnect/auto_confirm_pre_check_failed', $order)`-Zeile am Ende des Pre-Check-Failure-Pfads. Slice 29 ACs 1-12 bleiben semantisch unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Order/OrderSubmitJob.php` (Slice 28) | **Edit minimal (Variante ii).** Eine `do_action('spreadconnect/order_submitted', $order)`-Zeile am Ende des 2xx-Submit-Success-Pfads (Slice 28 AC-4 Schritt e nach Order-Note). Side-Effect-frei fuer Slice-28-Tests. Bei Variante (i) entfaellt dieser Edit zugunsten direkter `OrderHandler`-Methodenkopplung. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + Edits aus Slice 03/04/05/06/17/23/24/25/28/29/30) | **Edit-Target.** Ergaenzt drei Action-Hook-Registrierungen analog vorhandener Patterns. |
| `wordpress/plugins/spreadconnect-pod/includes/Order/OrderStateMachine.php` (Slice 27) | **Reuse, no edit.** Mirror-Job nutzt `compareAndSet('NEW', 'CANCELLED')`. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08/10) | **Reuse, no edit.** `cancelOrder()` ist Slice-10-Wrapper. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` + `SpreadconnectTransientError.php` (Slice 07) | **Reuse, no edit.** Specific-First-Catch identisch zu Slice 28/29. |
| Logging-Stub-Tag `admin_notice_pending_record` (Slice 30 AC-5) | **Reuse-Convention** — Slice 31 schreibt drei Notice-Reasons (`wc_cancel_mirror_blocked`, `cancel_mirror_state_advanced`, `auto_confirm_pre_check_failed`). Slice 39 verarbeitet alle Reasons in einem `AdminNoticeStore`. |
| Action-Scheduler-Group `'spreadconnect'` (Slice 24/25/28/29/30) | **Reuse** — Slice 31 nutzt identische Group fuer Mirror-Hook + Auto-Confirm-Schedule. |

**Referenzen:**
- Architecture: `architecture.md` -> AS-Hook-Inventory Z. 549-552 — `spreadconnect/confirm_order` Trigger "Order-Edit + Auto-Confirm timer"; `spreadconnect/cancel_order_mirror` Trigger "`woocommerce_order_status_cancelled` (only if `_spreadconnect_state='NEW'`)".
- Architecture: `architecture.md` -> Service-Map Z. 368-369 — `Order\OrderCancelMirrorJob` + `OrderHandler` Verantwortlichkeiten.
- Architecture: `architecture.md` -> Auto-Confirm-Settings Z. 326-329 — `auto_confirm`, `auto_confirm_minutes`, `auto_cancel_mirror`, `default_shipping_type`.
- Architecture: `architecture.md` -> Auto-Confirm-Gating Z. 465 + Z. 647 — `default_shipping_type` non-empty Pflicht; Settings-Validator erzwingt `auto_confirm='off'` bei leerem Default.
- Architecture: `architecture.md` -> AJAX-Action `spreadconnect_cancel_auto_confirm` Z. 156 — Slice 32 nutzt `as_unschedule_action('spreadconnect/confirm_order')`; Slice 31 produziert die zu canceln-de Schedule.
- Architecture: `architecture.md` -> State-Transitions Z. 593 — "WC-Cancel-Mirror: WC-Order auf `cancelled` triggert SC-Cancel automatisch nur wenn SC-State = `NEW`. Sonst Notice + Order-Note."
- Architecture: `architecture.md` -> Z. 591 — "Auto-Confirm-Pre-Check-Failure-Handling: ... wird die Action **nicht** in Failed-Ops generisch eingereiht. Stattdessen: dedizierte persistente Admin-Notice".
- Architecture: `architecture.md` -> Race-Protection Z. 642 — "`as_unschedule_action()` on WC-Cancel before scheduling cancel-mirror".
- Discovery: `discovery.md` -> Flow C Schritte 7 + 10 (Auto-Confirm-Trigger nach Submit; `Order.cancelled`-Webhook).
- Discovery: `discovery.md` -> Flow D Z. 164-169 — "WC-Cancel-Mirror" autoritative Sequenz inkl. Notice-Pfad.
- Slim-Slices: `slices/slim-slices.md` -> Slice-31-Eintrag Z. 489-497 — Done-Signal: "WC-Cancel mit State NEW -> as_unschedule + Cancel-Mirror enqueued; Cancel mit State CONFIRMED -> Order-Note + Admin-Notice (kein API-Call). Auto-Confirm-Schedule bei Setting immediate enqueues sofort."
- Vorgaenger Slice 28: `slices/slice-28-order-submit-job.md` -> `OrderHandler` + Hook-Registration-Pattern; Logging-Tag-Konvention.
- Vorgaenger Slice 29: `slices/slice-29-order-confirm-cancel-jobs.md` -> `OrderCancelJob` als Vorlage; Branch-Logik 4xx/5xx; `op_type`-Convention.
- Vorgaenger Slice 30: `slices/slice-30-order-webhooks-handler.md` -> `recordPersistentNotice` Logging-Stub Convention (`admin_notice_pending_record`-Tag); `OrderStateMachine::writeUnchecked`-Convention.
- Folge Slice 32: `slim-slices.md` Z. 501-509 — Order-Edit-Meta-Box konsumiert `cancel_auto_confirm`-AJAX (ruft `as_unschedule_action` auf der hier produzierten Schedule).
- Folge Slice 37: `slim-slices.md` Z. 559-566 — `RetryPolicyListener` muss Notice-Tag `auto_confirm_pre_check_failed` ausschliessen (kein DLQ-Eintrag).
- Folge Slice 39: `slim-slices.md` Z. 583-589 — `AdminNoticeStore` ersetzt alle drei `admin_notice_pending_record`-Reasons aus Slice 31.
- Wireframes: `wireframes.md` — **nicht direkt relevant** (Hook + Job ohne UI; persistent-Notice-Render ist Slice 39; Order-Edit "Cancel Auto-Confirm"-Button ist Slice 32).
