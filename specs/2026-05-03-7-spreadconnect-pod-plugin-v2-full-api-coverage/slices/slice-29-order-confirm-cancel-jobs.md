# Slice 29: Order-Confirm + Order-Cancel-Jobs

> **Slice 29 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-29-order-confirm-cancel-jobs` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-28-order-submit-job"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WC 10.5 HPOS + Action Scheduler + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA optional ab Slice 32 Order-Edit Meta-Box) |
| **Health Endpoint** | `n/a` (Job-Handler ohne eigenen REST-Endpoint) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey/Mockery fuer `wc_get_order`, `WC_Order::get_meta`/`update_meta_data`/`save`/`add_order_note`; SpreadconnectClient + OrderStateMachine als injizierte Mocks; `wc_get_logger()` als Mock fuer Log-Tag-Asserts) |

---

## Ziel

Schliesst den Confirm-/Cancel-Hop im Order-Lifecycle (Discovery Slice 5): Action-Scheduler-Hooks `spreadconnect/confirm_order` und `spreadconnect/cancel_order` fuehren `POST /orders/{id}/confirm` bzw. `POST /orders/{id}/cancel` aus, mutieren `_spreadconnect_state` via OrderStateMachine (`NEW -> CONFIRMED` bzw. `NEW -> CANCELLED`) und respektieren Pre-Checks (Confirm verlangt gesetzten Shipping-Type; Cancel laeuft nur wenn State exakt `NEW`). Auto-Confirm-Schedule und WC-Cancel-Mirror-Wiring kommen in Slice 31; diese Slice liefert ausschliesslich die Job-Handler plus Action-Wiring.

---

## Acceptance Criteria

1) **AC-Confirm-PreCheck-NoShippingType — Pre-Check bricht ohne Shipping-Type ab**
   **GIVEN** eine WC-Order in State `NEW` mit `_spreadconnect_order_id = 'sc_42'`, ohne `_spreadconnect_shipping_type`-Meta UND mit leerem `spreadconnect_default_shipping_type`-Setting
   **WHEN** `OrderConfirmJob::handle(['order_id' => 7])` aufgerufen wird
   **THEN** ruft der Job (a) `SpreadconnectClient::confirmOrder()` **nicht** auf (`Functions\expect(... )->never()`); (b) ruft `OrderStateMachine::compareAndSet()` **nicht** auf — State bleibt `NEW`; (c) schreibt eine Order-Note mit Substring `'Cannot confirm: no shipping type set'`; (d) loggt mit Source `spreadconnect-order-service`, Level `warning`, Tag `confirm_pre_check_failed`; (e) wirft **nicht** (Pre-Check-Failure ist permanent — kein AS-Retry); (f) das Auto-Confirm-Pre-Check-Failure-Notice-Pfad (Architecture Z. 591) wird in Slice 31 angeschlossen — in Slice 29 reicht der Log-Tag.

2) **AC-Confirm-Success-FromOrderMeta — Shipping-Type aus Order-Meta**
   **GIVEN** WC-Order State `NEW`, `_spreadconnect_order_id = 'sc_42'`, `_spreadconnect_shipping_type = 'STANDARD'`; `SpreadconnectClient::confirmOrder('sc_42')` liefert 2xx mit `['id' => 'sc_42', 'state' => 'CONFIRMED', ...]`
   **WHEN** `OrderConfirmJob::handle(['order_id' => 7])`
   **THEN** in Reihenfolge: (a) `SpreadconnectClient::confirmOrder('sc_42')` wird **genau einmal** mit der SC-Order-ID aufgerufen; (b) `OrderStateMachine::compareAndSet($order, 'NEW', 'CONFIRMED')` wird mit `true`-Result aufgerufen; (c) `$order->add_order_note(...)` mit Substring `'Confirmed in Spreadconnect (#SC-sc_42)'` wird geschrieben; (d) der Job wirft **nicht**.

3) **AC-Confirm-Success-FallbackDefaultShippingType — Default-Setting als Fallback**
   **GIVEN** WC-Order in State `NEW`, `_spreadconnect_order_id = 'sc_42'`, **kein** `_spreadconnect_shipping_type`-Meta, ABER `spreadconnect_default_shipping_type = 'STANDARD'`-Setting
   **WHEN** `OrderConfirmJob::handle(['order_id' => 7])`
   **THEN** behandelt der Job das Default-Setting als gesetzten Shipping-Type fuer den Pre-Check; ruft `SpreadconnectClient::confirmOrder('sc_42')`; CAS `NEW -> CONFIRMED`; Order-Note geschrieben. Kein zusaetzlicher `setShippingType()`-Call innerhalb dieses Jobs (Setting des Shipping-Types vor Confirm passiert in Slice 31/32 via separater Action — diese Slice baut ausschliesslich auf einem bereits gesetzten Shipping-Type auf).

4) **AC-Confirm-WrongState — Skip wenn State nicht `NEW`**
   **GIVEN** WC-Order in State `CONFIRMED` (oder `PROCESSED`/`CANCELLED`/`failed_to_submit`/leer), `_spreadconnect_shipping_type = 'STANDARD'`, `_spreadconnect_order_id = 'sc_42'`
   **WHEN** `OrderConfirmJob::handle(['order_id' => 7])`
   **THEN** ruft `SpreadconnectClient::confirmOrder()` **nicht** auf; ruft `compareAndSet()` mit `expected='NEW'` -> Result `false` (kein State-Write); schreibt einen Info-Log mit Tag `confirm_skipped_wrong_state` und Order-Note mit Substring `'Confirm skipped (state: <actual>)'`; wirft **nicht**.

5) **AC-Confirm-Permanent-4xx — Client-Error-Pfad**
   **GIVEN** WC-Order State `NEW`, valider Shipping-Type; `SpreadconnectClient::confirmOrder()` wirft `SpreadconnectClientError` (z. B. 400 "order already confirmed")
   **WHEN** `OrderConfirmJob::handle(['order_id' => 7])`
   **THEN** (a) State bleibt `NEW` (kein CAS-Aufruf auf `CONFIRMED`); (b) Order-Note mit Substring `'Confirm failed (4xx)'`; (c) Logging mit Source `spreadconnect-order-service`, Level `error`, Tag `failed_op_pending_record`, Context inkl. `op_type='confirm_order'`, `related_entity_type='order'`, `related_entity_id=7`, `error_message=$e->getMessage()` (analog Slice 28 AC-5; reale `FailedOpsRepo`-Insertion folgt in Slice 37); (d) der Job wirft **nicht** weiter (4xx ist permanent — kein AS-Retry).

6) **AC-Confirm-Transient-5xx — Re-Throw fuer AS-Retry**
   **GIVEN** WC-Order State `NEW`, valider Shipping-Type; `SpreadconnectClient::confirmOrder()` wirft `SpreadconnectTransientError`
   **WHEN** `OrderConfirmJob::handle(['order_id' => 7])`
   **THEN** (a) State bleibt `NEW`; (b) Warning-Log mit Substring `'transient error, AS retry'`; (c) Job wirft die `SpreadconnectTransientError`-Instance **unveraendert** weiter (AS Retry-Policy `1m/5m/15m`, Architecture Z. 550).

7) **AC-Cancel-Success-StateNew — Cancel laeuft nur bei State `NEW`**
   **GIVEN** WC-Order State `NEW`, `_spreadconnect_order_id = 'sc_42'`; `SpreadconnectClient::cancelOrder('sc_42')` liefert 2xx mit `['id' => 'sc_42', 'state' => 'CANCELLED']`
   **WHEN** `OrderCancelJob::handle(['order_id' => 7])`
   **THEN** (a) `SpreadconnectClient::cancelOrder('sc_42')` wird genau einmal aufgerufen; (b) `OrderStateMachine::compareAndSet($order, 'NEW', 'CANCELLED')` -> `true`; (c) Order-Note mit Substring `'Cancelled in Spreadconnect (#SC-sc_42)'`; (d) Job wirft **nicht**. WC-Order-Status wird **nicht** in dieser Slice mutiert (das uebernimmt der `Order.cancelled`-Webhook-Handler in Slice 30 bzw. der Cancel-Mirror-Pfad in Slice 31).

8) **AC-Cancel-WrongState — Skip wenn State nicht `NEW`**
   **GIVEN** WC-Order in State `CONFIRMED` (oder `PROCESSED`/`CANCELLED`/`failed_to_submit`/leer), `_spreadconnect_order_id = 'sc_42'`
   **WHEN** `OrderCancelJob::handle(['order_id' => 7])`
   **THEN** ruft `SpreadconnectClient::cancelOrder()` **nicht** auf; Order-Note mit Substring `'Cannot cancel in Spreadconnect (state: <actual>)'` (Architecture Z. 538/593 — Status-aware Note); Info-Log Tag `cancel_skipped_wrong_state`; wirft **nicht**. (Persistent Admin-Notice fuer den `CONFIRMED`/`PROCESSED`-Pfad ist Slice 31/39.)

9) **AC-Cancel-Permanent-4xx — Client-Error-Pfad**
   **GIVEN** WC-Order State `NEW`; `SpreadconnectClient::cancelOrder()` wirft `SpreadconnectClientError`
   **WHEN** `OrderCancelJob::handle(['order_id' => 7])`
   **THEN** (a) State bleibt `NEW` (kein CAS-Aufruf auf `CANCELLED`); (b) Order-Note mit Substring `'Cancel failed (4xx)'`; (c) Logging-Tag `failed_op_pending_record` mit Context `op_type='cancel_order'`, `related_entity_id=7`; (d) Job wirft **nicht**.

10) **AC-Cancel-Transient-5xx — Re-Throw fuer AS-Retry**
    **GIVEN** WC-Order State `NEW`; `SpreadconnectClient::cancelOrder()` wirft `SpreadconnectTransientError`
    **WHEN** `OrderCancelJob::handle(['order_id' => 7])`
    **THEN** State bleibt `NEW`; Warning-Log; Job wirft `SpreadconnectTransientError` unveraendert weiter (AS Retry-Policy Architecture Z. 551 entspricht; `cancel_order_mirror` als separate Hook-Variante folgt in Slice 31).

11) **AC-Job-Idempotency-NoOrderId — Skip wenn `_spreadconnect_order_id` fehlt**
    **GIVEN** WC-Order ohne `_spreadconnect_order_id`-Meta (z. B. Submit ist nie erfolgreich gelaufen)
    **WHEN** `OrderConfirmJob::handle(['order_id' => 7])` ODER `OrderCancelJob::handle(['order_id' => 7])`
    **THEN** prueft jeder Job **als ersten Schritt nach Order-Lookup** `$order->get_meta('_spreadconnect_order_id')` — bei leer/empty: kein API-Call, kein State-Mutate, kein Order-Note; Info-Log mit Tag `job_skipped_no_sc_order_id`; Job wirft **nicht**.

12) **AC-Hook-Wiring — Bootstrap registriert beide AS-Action-Hooks idempotent**
    **GIVEN** Plugin-Boot
    **WHEN** `Bootstrap\Plugin::init()` durchlaeuft
    **THEN** sind die folgenden AS-Hooks via `add_action()` registriert: (a) `spreadconnect/confirm_order` -> `OrderConfirmJob::handle` (Priority `10`, Args `1`); (b) `spreadconnect/cancel_order` -> `OrderCancelJob::handle` (Priority `10`, Args `1`). Die Registrierung ist idempotent (zweimaliger `init()`-Aufruf fuegt **nicht** doppelt hinzu — `has_action()` returned identische Priority). Slice-28-Hooks (`woocommerce_order_status_processing` und `spreadconnect/create_order`) bleiben unveraendert registriert.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Zwei PHPUnit-Test-Files: `tests/slices/pod-shop-mvp/slice-05-order-confirm-job.php` (AC-1 bis AC-6, AC-11) und `tests/slices/pod-shop-mvp/slice-05-order-cancel-job.php` (AC-7 bis AC-11). AC-12 wird in der bestehenden `slice-05-order-handler.php` aus Slice 28 erweitert (oder als neuer Test-Case dort ergaenzt — der Test-Writer entscheidet). Brain\Monkey-Setup fuer `wc_get_order`, `wc_get_logger`, `get_option('spreadconnect_default_shipping_type')`. SpreadconnectClient + OrderStateMachine als Mockery-Mocks per Konstruktor injiziert. **`FailedOpsRepo` ist in Slice 29 weiterhin nur Logging-Stub** — Test-Writer prueft via `wc_get_logger()`-Mock auf den Log-Tag `failed_op_pending_record` mit Context-Field `op_type='confirm_order'` bzw. `'cancel_order'`.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-05-order-confirm-job.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class OrderConfirmJobTest extends TestCase
{
    // AC-1: Pre-Check-Failure ohne Shipping-Type -> kein API-Call
    public function test_handle_pre_check_fails_when_no_shipping_type_anywhere(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    public function test_handle_pre_check_failure_logs_confirm_pre_check_failed_tag(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Success-Pfad mit Shipping-Type aus Order-Meta
    public function test_handle_success_with_order_meta_shipping_type_calls_confirm_and_cas_to_confirmed(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    public function test_handle_success_writes_confirmed_order_note(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Success-Pfad mit Shipping-Type aus Default-Setting
    public function test_handle_success_uses_default_shipping_type_setting_as_fallback(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: State !== NEW -> Skip ohne API-Call
    public function test_handle_skips_when_state_is_not_new(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: 4xx Permanent -> failed-op log, kein State-Mutate, kein Re-Throw
    public function test_handle_permanent_4xx_does_not_mutate_state(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    public function test_handle_permanent_4xx_logs_failed_op_pending_record_with_confirm_op_type(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    public function test_handle_permanent_4xx_does_not_rethrow(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: 5xx Transient -> Re-Throw fuer AS-Retry
    public function test_handle_transient_5xx_rethrows_unchanged(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-11: Skip wenn _spreadconnect_order_id fehlt
    public function test_handle_skips_when_spreadconnect_order_id_meta_missing(): void
    {
        $this->markTestIncomplete('AC-11');
    }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-05-order-cancel-job.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class OrderCancelJobTest extends TestCase
{
    // AC-7: Cancel laeuft bei State NEW
    public function test_handle_calls_cancel_and_cas_to_cancelled_when_state_new(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    public function test_handle_writes_cancelled_order_note(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: State !== NEW -> Skip ohne API-Call (mit zustandsabhaengiger Note)
    public function test_handle_skips_and_writes_state_aware_note_when_state_not_new(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: 4xx Permanent -> failed-op log, kein State-Mutate, kein Re-Throw
    public function test_handle_permanent_4xx_logs_failed_op_pending_record_with_cancel_op_type(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    public function test_handle_permanent_4xx_does_not_mutate_state(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: 5xx Transient -> Re-Throw fuer AS-Retry
    public function test_handle_transient_5xx_rethrows_unchanged(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: Skip wenn _spreadconnect_order_id fehlt
    public function test_handle_skips_when_spreadconnect_order_id_meta_missing(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    // AC-12: Bootstrap-Hook-Wiring (kann auch in slice-05-order-handler.php landen)
    public function test_plugin_init_registers_confirm_and_cancel_action_hooks(): void
    {
        $this->markTestIncomplete('AC-12');
    }

    public function test_plugin_init_hook_registration_is_idempotent_on_double_call(): void
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
| `slice-10-endpoint-methods` | `SpreadconnectClient::confirmOrder(string $sc_order_id): array` | public method | 2xx -> assoc-Array mit `state='CONFIRMED'`; 4xx -> `SpreadconnectClientError`; 5xx/429-after-inner-retry/Network -> `SpreadconnectTransientError`. |
| `slice-10-endpoint-methods` | `SpreadconnectClient::cancelOrder(string $sc_order_id): array` | public method | 2xx -> `state='CANCELLED'`; Exception-Mapping wie Confirm. |
| `slice-07-http-client-base` / `slice-08-rate-limit-retry` | `SpreadconnectClientError`, `SpreadconnectTransientError` | exception classes | Job-`try/catch` unterscheidet die beiden (Specific-First-Order). |
| `slice-27-order-state-machine` | `OrderStateMachine::compareAndSet(WC_Order, string $expected, string $target): bool` + Class-Konstanten `STATE_NEW`/`STATE_CONFIRMED`/`STATE_CANCELLED` | instance method | Konstruktor `(\wpdb, ?\WC_Logger=null)`; injiziert in beide neuen Jobs. |
| `slice-28-order-submit-job` | `_spreadconnect_order_id`-Meta-Convention + `_spreadconnect_state`-Werte (`NEW` als Vorbedingung) | meta key (string) | Slice 28 setzt diese Meta beim 2xx-Submit; Slice 29 liest sie nur. |
| `slice-05-options-defaults` | Option `spreadconnect_default_shipping_type` (string, default `''`) | option key | Slice 11 macht es konfigurierbar; Slice 29 nutzt `get_option('spreadconnect_default_shipping_type', '')` als Fallback im Pre-Check (AC-3). |
| WordPress / WooCommerce | `wc_get_order()`, `WC_Order::get_meta`/`update_meta_data`/`save`/`add_order_note` | WC-API | HPOS-aktiv (Slice 03). |
| Action Scheduler (WC-Bundled) | `add_action('spreadconnect/confirm_order', ...)`, `add_action('spreadconnect/cancel_order', ...)` | AS-API | Hook-Args sind assoc-Array `['order_id' => int]` (Konvention aus Slice 28). |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Order\OrderConfirmJob::handle` | instance method | AS-Hook `spreadconnect/confirm_order`; Slice 31 (Auto-Confirm-Timer enqueued via `as_schedule_single_action`); Slice 32 (Order-Edit-Meta-Box "Confirm"-Button per AJAX `spreadconnect_confirm_order` -> `as_enqueue_async_action`); Slice 38 (Failed-Ops-UI Resend) | `public function handle(array $args): void` (key `order_id` int) |
| `SpreadconnectPod\Order\OrderCancelJob::handle` | instance method | AS-Hook `spreadconnect/cancel_order`; Slice 32 (Order-Edit-Meta-Box "Cancel"-Button); Slice 38 (Failed-Ops-Resend). **Nicht** der `cancel_order_mirror`-Hook — den fuegt Slice 31 separat hinzu (eigener Job mit anderem Hook-Namen). | `public function handle(array $args): void` (key `order_id` int) |
| `SpreadconnectPod\Order\OrderConfirmJob` Class | Application (Job-Handler) | DI-Wiring in Slice 31 (Auto-Confirm-Timer ruft `as_schedule_single_action('spreadconnect/confirm_order', ['order_id' => $id], 'spreadconnect')`); Slice 32 ruft denselben Hook synchron via `as_enqueue_async_action`. | Konstruktor: `public function __construct(\SpreadconnectPod\Api\SpreadconnectClient $client, \SpreadconnectPod\Order\OrderStateMachine $sm, ?\WC_Logger $logger = null)` |
| `SpreadconnectPod\Order\OrderCancelJob` Class | Application (Job-Handler) | wie oben + Slice 31 nutzt Cancel-Logik als Vorlage fuer den separaten `OrderCancelMirrorJob` (eigene Klasse, gleiche Sequenz, anderer Hook-Name). | Konstruktor: identisch zu `OrderConfirmJob`. |
| Hook-Name `spreadconnect/confirm_order` | AS-action-name (string-literal) | Slice 31 (Auto-Confirm-Timer + `as_unschedule_action` Race-Schutz), Slice 32 (Order-Edit-Confirm-Button), Slice 38 (Failed-Ops-Resend) | `'spreadconnect/confirm_order'` Group `'spreadconnect'` |
| Hook-Name `spreadconnect/cancel_order` | AS-action-name (string-literal) | Slice 32 (Order-Edit-Cancel-Button), Slice 38 | `'spreadconnect/cancel_order'` Group `'spreadconnect'` |
| Failed-Ops-Logging-Tag `failed_op_pending_record` mit `op_type ∈ {'confirm_order', 'cancel_order'}` | Logging-Convention (string-literal) | Slice 37 `RetryPolicyListener` ersetzt Stub durch echten Repo-Call (op-type-getrieben — Architecture Z. 723 listet `op_type` enum). | Source `spreadconnect-order-service`, Level `error`, Context `['op_type' => 'confirm_order'\|'cancel_order', 'related_entity_type' => 'order', 'related_entity_id' => int, 'error_message' => string]` |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Order/OrderConfirmJob.php` — Neue Klasse `final class OrderConfirmJob`. Konstruktor injiziert `SpreadconnectClient`, `OrderStateMachine`, optional `?WC_Logger`. Methode `public function handle(array $args): void`: Order-Lookup -> `_spreadconnect_order_id`-Idempotency -> Pre-Check (`_spreadconnect_shipping_type`-Meta ODER `spreadconnect_default_shipping_type`-Option non-empty) -> State-Check (`NEW`) -> `confirmOrder()` -> `compareAndSet('NEW' -> 'CONFIRMED')` -> Order-Note. Branch-Logik fuer 4xx/5xx wie in Slice 28.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Order/OrderCancelJob.php` — Neue Klasse `final class OrderCancelJob`. Konstruktor identisch zu `OrderConfirmJob`. Methode `public function handle(array $args): void`: Order-Lookup -> `_spreadconnect_order_id`-Idempotency -> State-Check (`NEW`; sonst zustandsabhaengige Note + Skip) -> `cancelOrder()` -> `compareAndSet('NEW' -> 'CANCELLED')` -> Order-Note. Branch-Logik fuer 4xx/5xx wie in Slice 28.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — **Edit, nicht ersetzen.** In `init()` zwei `add_action()`-Registrierungen ergaenzen: `spreadconnect/confirm_order` -> `OrderConfirmJob::handle` (Priority 10, Args 1) und `spreadconnect/cancel_order` -> `OrderCancelJob::handle` (Priority 10, Args 1). Konstruktion der Job-Instances inline (analog Slice 28); echtes DI-Container-Wiring kommt in Slice 37.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-05-order-confirm-job.php` und `tests/slices/pod-shop-mvp/slice-05-order-cancel-job.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- KEIN `Order\OrderCancelMirrorJob` (Slice 31 fuegt einen separaten Job mit eigenem Hook-Namen `spreadconnect/cancel_order_mirror` hinzu — die Sequenz ist aehnlich, der Hook und das Wiring jedoch separat).
- KEIN `woocommerce_order_status_cancelled`-WC-Hook-Listener — Slice 31.
- KEINE Auto-Confirm-Timer-Logik (`as_schedule_single_action('spreadconnect/confirm_order', ...)`) — Slice 31. Slice 29 stellt nur den Hook-Empfaenger bereit, der von Slice 31's Schedule-Code konsumiert wird.
- KEIN `as_unschedule_action('spreadconnect/confirm_order')`-Race-Schutz in dieser Slice — Slice 31.
- KEIN `setShippingType()`-Aufruf innerhalb von `OrderConfirmJob` (auch nicht als Vorab-Roundtrip) — der Shipping-Type MUSS bereits gesetzt sein. Inline-`shippingType`-Variante (Slice 28 Open Q11) wird ausschliesslich von `OrderSubmitJob` behandelt; ein Fallback-Pfad mit separatem `POST /shippingType` vor Confirm folgt in Slice 31/32 als eigene Action.
- KEIN realer `Failure\FailedOpsRepo` — Logging-Stub-Konvention identisch zu Slice 28 (Tag `failed_op_pending_record`).
- KEINE persistenten Admin-Notices (Slice 39); insbesondere KEINE Auto-Confirm-Pre-Check-Failure-Notice (Architecture Z. 591) — der dort beschriebene Notice-Pfad wird in Slice 31 angeschlossen, sobald der Auto-Confirm-Timer existiert. Slice 29 dokumentiert das Pre-Check-Failure ausschliesslich via Order-Note + Log-Tag.
- KEINE WC-Status-Mutation auf `cancelled` durch `OrderCancelJob` — der WC-Status wird vom `Order.cancelled`-Webhook-Handler (Slice 30) bzw. vom Cancel-Mirror (Slice 31) gesteuert.
- KEINE Order-List-Spalten / Meta-Box-UI — Slice 32/33.
- KEIN AJAX-Handler `spreadconnect_confirm_order` / `spreadconnect_cancel_order` — die AJAX-Adapter (Slice 32) rufen `as_enqueue_async_action(...)` auf den hier registrierten Hooks; Slice 29 selbst exponiert keine AJAX-Endpunkte.
- KEIN `try`/`catch` auf andere Exceptions als `SpreadconnectClientError` und `SpreadconnectTransientError`. PHP-Fatals/Logic-Errors duerfen durchschlagen.
- KEIN Direct-Write auf `_spreadconnect_state` ausser via `OrderStateMachine::compareAndSet()` (Slice 27 Source of Truth).

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile.
- `final class` fuer beide neuen Klassen (`OrderConfirmJob`, `OrderCancelJob`).
- `handle($args)` erwartet `$args = ['order_id' => int]`. Validierung als allerersten Schritt: `$order_id = (int) ($args['order_id'] ?? 0); if ($order_id <= 0) { return; }` (still-and-silent, kein Throw).
- Order-Lookup defensiv: `$order = wc_get_order($order_id); if (! $order instanceof \WC_Order) { return; }`.
- Pre-Check-Reihenfolge in `OrderConfirmJob`: (1) Order-Lookup; (2) `_spreadconnect_order_id`-Check (AC-11); (3) Shipping-Type-Pre-Check (AC-1, AC-3); (4) State-Check `NEW` (AC-4); (5) API-Call.
- Pre-Check-Reihenfolge in `OrderCancelJob`: (1) Order-Lookup; (2) `_spreadconnect_order_id`-Check (AC-11); (3) State-Check `NEW` (AC-7, AC-8); (4) API-Call.
- `SpreadconnectClientError`-Catch muss vor `SpreadconnectTransientError`-Catch stehen (Specific-First-Regel; Anwendung der Slice-28-Konvention).
- Logging via `wc_get_logger()->info()/warning()/error()` mit Source `'spreadconnect-order-service'`. Kein `error_log()`.
- Log-Tags konsistent mit Slice 28: `failed_op_pending_record` fuer 4xx-Pfad; eigene Tags fuer Skip-Pfade (`confirm_pre_check_failed`, `confirm_skipped_wrong_state`, `cancel_skipped_wrong_state`, `job_skipped_no_sc_order_id`).
- Order-Note-Strings in **Englisch** (Backend-only, nicht customer-facing); `add_order_note( $note, false, false )`-Signatur (kein Customer-Note, kein User).
- HPOS-Konformitaet: `$order->update_meta_data()` + `$order->save()` (NICHT `update_post_meta()`).
- Hook-Args-Konvention `['order_id' => $order_id]` (assoc-Array, identisch zu Slice 28) — sichert idempoten `as_has_scheduled_action()`-Vergleich fuer spaetere Konsumenten in Slice 31/32.
- `OrderConfirmJob::handle` MUSS bei AC-1 und AC-4 keine `compareAndSet`-Aufrufe taetigen (also kein `compareAndSet('NEW', 'CONFIRMED')` mit erwartet-fail) — Pre-Check-Skip ist explizit ein No-Op. AC-4 darf optional `compareAndSet('NEW', 'CONFIRMED')` aufrufen und auf `false`-Result reagieren; alternativ vorab `$order->get_meta('_spreadconnect_state')` lesen und gegen `'NEW'` vergleichen. Implementer waehlt — Test-Writer prueft nur die Effekt-Ebene (kein API-Call, korrekte Note, kein State-Wechsel).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + 03 + 04 + 05 + 06 + 28) | **Edit, nicht ersetzen.** Slice 28 hat bereits zwei `add_action()`-Calls fuer `woocommerce_order_status_processing` + `spreadconnect/create_order` ergaenzt; Slice 29 fuegt zwei weitere Action-Hook-Registrierungen hinzu. Bestehende Logik unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08/10) | **Wiederverwendet, unveraendert.** `confirmOrder()` + `cancelOrder()` sind Slice-10-Wrapper. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` / `SpreadconnectTransientError.php` (Slice 07/08) | **Wiederverwendet, unveraendert.** Job-`try/catch` unterscheidet die beiden identisch zu Slice 28. |
| `wordpress/plugins/spreadconnect-pod/includes/Order/OrderStateMachine.php` (Slice 27) | **Wiederverwendet, unveraendert.** Im Job injiziert; CAS-Calls fuer `NEW -> CONFIRMED` (Confirm) und `NEW -> CANCELLED` (Cancel). |
| `wordpress/plugins/spreadconnect-pod/includes/Order/OrderSubmitJob.php` (Slice 28) | **Konvention-Vorlage, unveraendert.** Slice 29 spiegelt die Branch-Logik 4xx/5xx (Logging-Tag, Order-Note-Format, kein Re-Throw bei Permanent) 1:1, aendert nur die op_type-Werte und API-Calls. |

Hinweis: `Failure\FailedOpsRepo` existiert weiterhin nicht (Slice 37). Logging-Stub-Pfad bleibt identisch zur Slice-28-Konvention; der `RetryPolicyListener` (Slice 37) reagiert in einem Aufwasch auf die Tags `failed_op_pending_record` aller Order-Jobs (`create_order`, `confirm_order`, `cancel_order`).

**Referenzen:**
- Architecture: `architecture.md` -> Endpoint-Tabelle Z. 101-102 — `POST /orders/{id}/confirm` (NEW->CONFIRMED) und `POST /orders/{id}/cancel` (allowed only when state=NEW).
- Architecture: `architecture.md` -> AJAX-Action-Tabelle Z. 153-154 — Pre-Check Shipping-Type fuer Confirm; Cancel updated state.
- Architecture: `architecture.md` -> Service-Map Z. 367 — `Order\OrderConfirmJob`-Verantwortlichkeit (auto-confirm timer or manual).
- Architecture: `architecture.md` -> AS-Hook-Inventory Z. 550-551 — `spreadconnect/confirm_order` Retry `1m/5m/15m`; `spreadconnect/cancel_order_mirror` separat (in Slice 31).
- Architecture: `architecture.md` -> Order-Meta Z. 312 — `_spreadconnect_shipping_type` als Quelle fuer Pre-Check.
- Architecture: `architecture.md` -> Settings Z. 328 — `spreadconnect_default_shipping_type` als Fallback im Pre-Check.
- Architecture: `architecture.md` -> State-Transition-Tabelle Z. 535-538 — `NEW + Confirm -> CONFIRMED (Pre-Check Shipping-Type)`; `NEW + Cancel -> CANCELLED`; CONFIRMED/PROCESSED-Cancel -> Note + Notice.
- Architecture: `architecture.md` -> Z. 588 "Confirm-Pre-Check: Shipping-Type muss gewaehlt sein"; Z. 591 Auto-Confirm-Pre-Check-Failure-Handling (Persistent-Notice-Pfad spaeter in Slice 31).
- Architecture: `architecture.md` -> Z. 593 — Cancel-Mirror laeuft NUR bei State `NEW`.
- Discovery: `discovery.md` -> Slice 5 "Order-Lifecycle" Z. 158/161 — Confirm-Klick -> Confirm-API; Cancel-Webhook (Order.cancelled) ist Slice 30.
- Slim-Slices: `slices/slim-slices.md` -> Slice-29-Eintrag Z. 465-473 — Done-Signal: Confirm ohne Shipping-Type -> Pre-Check-Failure (kein API-Call); mit Shipping-Type -> POST /confirm + State CONFIRMED. Cancel nur bei State NEW.
- Slice 10: `slices/slice-10-endpoint-methods.md` -> `confirmOrder()`/`cancelOrder()`-Wrapper-Verhalten + Exception-Pass-Through.
- Slice 27: `slices/slice-27-order-state-machine.md` -> `compareAndSet()`-Semantik fuer State-Transitions.
- Slice 28: `slices/slice-28-order-submit-job.md` -> Branch-Logik 4xx/5xx, Logging-Tag-Konvention `failed_op_pending_record`, Hook-Args-Konvention `['order_id' => int]`.
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 29 (Job-Handler ohne UI; UI-Surface fuer Confirm/Cancel-Buttons kommt in Slice 32 Order-Edit-Meta-Box).
