# Slice 28: Order-Submit-Job (`create_order`)

> **Slice 28 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-28-order-submit-job` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-10-endpoint-methods", "slice-27-order-state-machine"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WC 10.5 HPOS + Action Scheduler + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA optional ab Slice 32 Order-Edit Meta-Box) |
| **Health Endpoint** | `n/a` (Job + WC-Hook, keine eigenen REST-Routes) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey/Mockery fuer `WC_Order`, `wc_get_order`, `as_enqueue_async_action`, `as_has_scheduled_action`; SpreadconnectClient als injizierter Mock; OrderStateMachine als injizierter Mock; FailedOpsRepo als Stub-Interface — reale Implementation in Slice 37) |

---

## Ziel

Schliesst den outbound Order-Submit-Pfad (Discovery Flow C): WC-Hook `woocommerce_order_status_processing` enqueued idempotent einen `spreadconnect/create_order`-Action-Scheduler-Job, der die WC-Order in eine `OrderCreate`-DTO baut, `SpreadconnectClient::createOrder()` ruft und Erfolg/Permanent-Fail/Transient-Fail in den HPOS-Order-State plus Order-Note + (Stub-)FailedOps spiegelt. Damit ist Slice 5 "Order-Lifecycle" fuer den ersten Hop (`pending`/`submitting` -> `NEW`/`failed_to_submit`) abgeschlossen; Confirm/Cancel/Webhooks folgen in Slice 29-31.

---

## Acceptance Criteria

1) **AC-Hook-Enqueue — `OrderHandler::on_processing` enqueued genau einmal**
   **GIVEN** eine WC-Order ohne `_spreadconnect_order_id`-Meta und ohne bereits gescheduled `spreadconnect/create_order`-Action fuer diese Order-ID
   **WHEN** WordPress den Hook `woocommerce_order_status_processing` mit dieser Order-ID feuert (`do_action('woocommerce_order_status_processing', $order_id, $order)`)
   **THEN** ruft der Handler `as_enqueue_async_action('spreadconnect/create_order', ['order_id' => $order_id], 'spreadconnect')` **genau einmal** auf; der Group-Slug ist `'spreadconnect'` (Architecture Z. 558 — gemeinsame AS-Gruppe).

2) **AC-Idempotency-OrderID — Skip wenn `_spreadconnect_order_id` bereits vorhanden**
   **GIVEN** eine WC-Order, die bereits `_spreadconnect_order_id = 'sc_42'` als Meta traegt (z. B. weil ein vorheriger Submit erfolgreich war oder per `submitted_externally`-Resolution gesetzt wurde)
   **WHEN** der Hook `woocommerce_order_status_processing` ein zweites Mal feuert
   **THEN** ruft der Handler `as_enqueue_async_action()` **nicht** auf (`Brain\Monkey\Functions\expect('as_enqueue_async_action')->never()`); ein Debug-Log-Eintrag mit Source `spreadconnect-order-service` und Message-Substring `'idempotent skip'` wird geschrieben.

3) **AC-Idempotency-Pending — Skip wenn bereits eine Action gescheduled ist**
   **GIVEN** eine WC-Order ohne `_spreadconnect_order_id`-Meta, aber `as_has_scheduled_action('spreadconnect/create_order', ['order_id' => $order_id], 'spreadconnect')` liefert `true` (z. B. Doppel-Hook in derselben Request, oder Race mit AS)
   **WHEN** der Hook ein zweites Mal feuert
   **THEN** ruft der Handler `as_enqueue_async_action()` **nicht** ein zweites Mal auf — der erste Schedule bleibt der einzige.

4) **AC-Job-Success-2xx — `OrderSubmitJob::handle` 2xx-Pfad**
   **GIVEN** eine WC-Order mit State `pending` (kein `_spreadconnect_state`-Meta), valider Adresse + ≥1 OrderItem; `SpreadconnectClient::createOrder($dto)` liefert `['id' => 'sc_42', 'state' => 'NEW', ...]`
   **WHEN** `OrderSubmitJob::handle(['order_id' => 7])` aufgerufen wird
   **THEN** ruft der Job in dieser Reihenfolge: (a) `OrderStateMachine::compareAndSet($order, '', 'submitting')` -> `true`; (b) `SpreadconnectClient::createOrder($dto)` mit DTO gemaess architecture.md Z. 165 (`OrderCreate`-Shape); (c) `$order->update_meta_data('_spreadconnect_order_id', 'sc_42')` + `$order->save()`; (d) `OrderStateMachine::compareAndSet($order, 'submitting', 'NEW')` -> `true`; (e) `$order->add_order_note(sprintf('Submitted to Spreadconnect (#SC-%s)', 'sc_42'), false, false)`. Der Job wirft **keine** Exception.

5) **AC-Job-Permanent-4xx — `OrderSubmitJob::handle` Client-Error-Pfad**
   **GIVEN** eine WC-Order in State `submitting`; `SpreadconnectClient::createOrder()` wirft `SpreadconnectClientError` mit Code `http_4xx` (z. B. 400 "invalid SKU mapping" — Architecture Z. 611)
   **WHEN** `OrderSubmitJob::handle(['order_id' => 7])` aufgerufen wird
   **THEN** (a) `OrderStateMachine::compareAndSet($order, 'submitting', 'failed_to_submit')` wird mit `true`-Result aufgerufen; (b) **kein** `_spreadconnect_order_id`-Meta wird geschrieben; (c) ein Logging-Stub fuer `FailedOpsRepo::record(['op_type' => 'create_order', 'related_entity_type' => 'order', 'related_entity_id' => 7, 'payload' => ..., 'error_message' => ..., 'state' => 'unresolved'])` wird ausgeloest (in Slice 28 als `wc_get_logger()->error()` mit Source `spreadconnect-order-service` und Tag `failed_op_pending_record` — die echte DB-Insertion folgt in Slice 37 ueber den `RetryPolicyListener`); (d) `$order->add_order_note('Spreadconnect: submit failed (4xx) — see Failed-Ops')` wird geschrieben; (e) der Job wirft die Exception **nicht** weiter (Action Scheduler darf diesen Pfad nicht erneut retryen — 4xx ist permanent).

6) **AC-Job-Transient-5xx — `OrderSubmitJob::handle` Re-Throw fuer AS-Retry**
   **GIVEN** eine WC-Order in State `submitting`; `SpreadconnectClient::createOrder()` wirft `SpreadconnectTransientError` (Code `http_5xx`/`network_error`/`http_429`-after-inner-retry)
   **WHEN** `OrderSubmitJob::handle(['order_id' => 7])` aufgerufen wird
   **THEN** (a) der State bleibt `submitting` (kein `compareAndSet`-Aufruf auf `NEW` oder `failed_to_submit`); (b) **kein** `_spreadconnect_order_id`-Meta wird geschrieben; (c) der Job wirft die `SpreadconnectTransientError`-Instance **unveraendert** weiter, sodass Action Scheduler die Retry-Policy `1m/5m/15m` (Architecture Z. 549) anwendet; (d) eine Warning-Log-Zeile mit Source `spreadconnect-order-service` und Substring `'transient error, AS retry'` wird vor dem Re-Throw geschrieben.

7) **AC-Job-Idempotency-Internal — Skip wenn `_spreadconnect_order_id` schon im Job-Body gefunden**
   **GIVEN** eine WC-Order, die zwischen Enqueue und Job-Execution ein `_spreadconnect_order_id`-Meta erhalten hat (z. B. Race mit `submitted_externally`-Resolution oder Doppel-Job durch AS-Race)
   **WHEN** `OrderSubmitJob::handle(['order_id' => 7])` aufgerufen wird
   **THEN** prueft der Job **als allerersten Schritt** `$order->get_meta('_spreadconnect_order_id')` — bei non-empty Wert: `SpreadconnectClient::createOrder()` wird **nicht** aufgerufen, kein State-Mutate, kein Order-Note; ein Info-Log mit Substring `'job skipped, order already submitted'` wird geschrieben; der Job wirft **nicht** und liefert ohne Fehler zurueck.

8) **AC-Job-CAS-Race — `submitting -> NEW`-Write wird verworfen wenn Webhook bereits `PROCESSED` setzte**
   **GIVEN** eine WC-Order in State `submitting`; waehrend `createOrder()` in flight ist, schreibt ein parallel verarbeiteter `Order.processed`-Webhook bereits State `PROCESSED`. Anschliessend liefert `createOrder()` 2xx mit `id = 'sc_42'`.
   **WHEN** der Job das 2xx-Result verarbeitet
   **THEN** ruft `OrderStateMachine::compareAndSet($order, 'submitting', 'NEW')` -> `false` (siehe Slice 27 AC-2). Der Job (a) **persistiert dennoch** `_spreadconnect_order_id = 'sc_42'` (es ist Side-Effect-frei, identifiziert lediglich die SC-Order); (b) schreibt einen Order-Note mit `'Submitted to Spreadconnect (#SC-sc_42); state already advanced (race)'`; (c) wirft **nicht**. Der finale State bleibt `PROCESSED`.

9) **AC-Hook-Wiring — Bootstrap registriert beide Hooks idempotent**
   **GIVEN** Plugin-Boot
   **WHEN** `Bootstrap\Plugin::init()` durchlaeuft
   **THEN** sind die folgenden WP-/AS-Hooks via `add_action()` registriert: (a) `woocommerce_order_status_processing` -> `OrderHandler::on_processing` (Priority `10`, Args `2`); (b) `spreadconnect/create_order` -> `OrderSubmitJob::handle` (Priority `10`, Args `1` — AS uebergibt den Args-Array als ersten Parameter). Die Registrierung ist idempotent (zweimaliger `init()`-Aufruf fuegt **nicht** doppelt hinzu — `has_action()` returned identisch).

10) **AC-DTO-Build — `OrderCreate`-DTO aus WC-Order**
    **GIVEN** eine WC-Order mit Items (jedes mit WC-Variation-SKU), Billing-/Shipping-Adresse (`first_name`, `last_name`, `address_1`, `postcode`, `city`, `country` non-empty), `customer_email`, optional `phone`
    **WHEN** der Job die `OrderCreate`-DTO baut (private Helper `buildOrderCreateDto(WC_Order $order): OrderCreate`)
    **THEN** entspricht die DTO der Slice-09-Definition (architecture.md Z. 165): `externalOrderReference = (string) $order->get_id()`, `orderItems` = je WC-Item ein `OrderItem` mit `sku` aus `$item->get_product()->get_sku()` und `quantity = $item->get_quantity()`, `billingAddress`/`shippingAddress` als `Address`-DTO. Das `shippingType`-Feld bleibt **leer/null** in dieser Slice (Pre-Submit-Auto-Confirm-Wiring kommt in Slice 31; Pre-Check in Slice 29). Validation-Failures (z. B. Item ohne SKU) werfen `\InvalidArgumentException` aus der DTO-Factory (Slice 09) und werden vom Job wie ein Permanent-Fail behandelt (Pfad analog AC-5, mit `error_message = $e->getMessage()`).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Zwei PHPUnit-Test-Files: `tests/slices/pod-shop-mvp/slice-05-order-submit-job.php` (Job-Logik AC-4..AC-8, AC-10) und `tests/slices/pod-shop-mvp/slice-05-order-handler.php` (Hook-Wiring + Idempotency AC-1..AC-3, AC-9). Brain\Monkey-Setup mit `Functions\expect('as_enqueue_async_action')`, `Functions\when('as_has_scheduled_action')`, `Functions\when('wc_get_order')`. SpreadconnectClient + OrderStateMachine als Mockery-Mocks via Konstruktor injiziert. **FailedOpsRepo ist in Slice 28 nur ein Logging-Stub** — Test-Writer prueft via `wc_get_logger()`-Mock nur den Log-Tag `failed_op_pending_record`, nicht eine echte DB-Row.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-05-order-handler.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class OrderHandlerTest extends TestCase
{
    // AC-1: Hook enqueues create_order action exactly once
    public function test_on_processing_enqueues_create_order_action_once(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Skip when _spreadconnect_order_id already set
    public function test_on_processing_skips_when_order_id_meta_present(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Skip when as_has_scheduled_action returns true
    public function test_on_processing_skips_when_action_already_scheduled(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-9: Bootstrap registers WC + AS hooks idempotently
    public function test_plugin_init_registers_processing_and_create_order_hooks(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    public function test_plugin_init_is_idempotent_on_double_call(): void
    {
        $this->markTestIncomplete('AC-9');
    }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-05-order-submit-job.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class OrderSubmitJobTest extends TestCase
{
    // AC-4: 2xx success path -> meta + state transitions + order-note
    public function test_handle_success_persists_order_id_and_transitions_state_to_new(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    public function test_handle_success_writes_submitted_order_note(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: 4xx permanent path -> failed_to_submit + failed-ops log + no rethrow
    public function test_handle_permanent_4xx_sets_state_failed_to_submit(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    public function test_handle_permanent_4xx_logs_failed_op_pending_record(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    public function test_handle_permanent_4xx_does_not_rethrow(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: 5xx transient path -> rethrow for AS retry, no state mutation
    public function test_handle_transient_5xx_rethrows_unchanged(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    public function test_handle_transient_5xx_does_not_mutate_state(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Internal idempotency -> skip when meta already set at job execution time
    public function test_handle_skips_when_order_already_has_spreadconnect_order_id(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: CAS race-loss -> still persists order_id, race-aware order-note, no throw
    public function test_handle_persists_order_id_even_when_cas_to_new_loses_race(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-10: DTO build from WC-Order
    public function test_handle_builds_order_create_dto_from_wc_order(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    public function test_dto_validation_failure_is_treated_as_permanent_failure(): void
    {
        $this->markTestIncomplete('AC-10');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-10-endpoint-methods` | `SpreadconnectClient::createOrder(OrderCreate $dto): array` | public method | Liefert `array` mit Key `id` (string SC-OrderID) bei 2xx; wirft `SpreadconnectClientError` bei 4xx; wirft `SpreadconnectTransientError` bei 5xx/429/network. Slice-10-AC-3 garantiert Body-Shape. |
| `slice-10-endpoint-methods` | `SpreadconnectClientError` / `SpreadconnectTransientError` | exception classes | Slice 28 catched **gezielt**: `SpreadconnectClientError` -> Permanent-Pfad; `SpreadconnectTransientError` -> Re-Throw. |
| `slice-09-dto-value-objects` | `OrderCreate`, `OrderItem`, `Address` (transitiv via Slice 10) | DTOs | DTOs werden im Job gebaut; Validation-Throw `\InvalidArgumentException` -> Permanent-Pfad (AC-10). |
| `slice-27-order-state-machine` | `OrderStateMachine::compareAndSet(WC_Order, string, string): bool` + Class-Konstanten `STATE_SUBMITTING`/`STATE_NEW`/`STATE_FAILED_TO_SUBMIT` | instance method | Konstruktor `(\wpdb, ?\WC_Logger=null)`. Slice 28 injiziert die Instanz in `OrderSubmitJob` via Konstruktor — kein neuer DI-Container hier. |
| WordPress / WooCommerce | `wc_get_order()`, `WC_Order::get_id`/`get_meta`/`update_meta_data`/`save`/`add_order_note`/`get_items`/`get_address` | WC-API | HPOS-aktiv (Slice 03); Standard im Plugin-Runtime. |
| Action Scheduler (WC-Bundled) | `as_enqueue_async_action(string $hook, array $args, string $group)`, `as_has_scheduled_action(string $hook, array $args, string $group)` | AS-API | Verfuegbar mit WC 10.5; Group `'spreadconnect'` (Architecture Z. 558). |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Order\OrderHandler::on_processing` | instance method | WC-Hook `woocommerce_order_status_processing` (intern); ab Slice 31 erweitert mit `on_cancelled` + Auto-Confirm-Schedule | `public function on_processing(int $order_id, \WC_Order $order): void` |
| `SpreadconnectPod\Order\OrderSubmitJob::handle` | instance method | AS-Hook `spreadconnect/create_order`; Resend-Pfade in Slice 38 (Failed-Ops-UI), Slice 33 (Bulk-Resend), Slice 32 (Order-Edit Resend) | `public function handle(array $args): void` (key `order_id` int) |
| `SpreadconnectPod\Order\OrderHandler` Class | Adapter (WC-Hook-Receiver) | DI-Container ab Slice 31 (`on_cancelled` + Auto-Confirm-Timer); Slice 38 ruft `on_processing` indirekt via Resend-AS-Re-Enqueue. | Konstruktor: `public function __construct( \SpreadconnectPod\Order\OrderHandler\Logger $logger = null )` (Logger-Param via WC_Logger oder Stub; ab Slice 42 ueber Adapter) |
| `SpreadconnectPod\Order\OrderSubmitJob` Class | Application (Job-Handler) | DI-Container ab Slice 37 (`RetryPolicyListener` haengt sich an `action_scheduler_failed_action`); Slice 32 Order-Edit "Resend"-AJAX nutzt `OrderHandler::on_processing` als Re-Entry | Konstruktor: `public function __construct( \SpreadconnectPod\Api\SpreadconnectClient $client, \SpreadconnectPod\Order\OrderStateMachine $sm, ?\WC_Logger $logger = null )` |
| Hook-Name `spreadconnect/create_order` | AS-action-name (string-literal) | Slice 33 (Bulk-Resend re-enqueues), Slice 37 (Retry-Policy listens), Slice 38 (Failed-Ops resend) | `'spreadconnect/create_order'` Group `'spreadconnect'` |
| Failed-Ops-Logging-Tag `failed_op_pending_record` | Logging-Convention (string-literal) | Slice 37 `RetryPolicyListener` ersetzt das Logging-Stub durch echte `FailedOpsRepo::record()`-Calls | Source `spreadconnect-order-service`, Level `error`, Context `['op_type' => 'create_order', 'related_entity_type' => 'order', 'related_entity_id' => int, 'payload' => array, 'error_message' => string]` |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Order/OrderSubmitJob.php` — Neue Klasse `final class OrderSubmitJob`. Konstruktor injiziert `SpreadconnectClient`, `OrderStateMachine`, optional `?WC_Logger`. Methode `public function handle(array $args): void` enthaelt Job-Logik (DTO-Build, API-Call, State-Mutation, Error-Branches). Private Helper `buildOrderCreateDto(WC_Order $order): OrderCreate`.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Order/OrderHandler.php` — Neue Klasse `final class OrderHandler`. Methode `public function on_processing(int $order_id, \WC_Order $order): void` mit Idempotency-Pre-Checks (`_spreadconnect_order_id`-Meta + `as_has_scheduled_action`) und Enqueue auf `spreadconnect/create_order`. **Nur** der Processing-Hook in dieser Slice; `on_cancelled` + Auto-Confirm-Schedule kommen in Slice 31.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — **Edit, nicht ersetzen.** In `init()` zwei `add_action()`-Registrierungen ergaenzen: `woocommerce_order_status_processing` -> `OrderHandler::on_processing` (Priority 10, Args 2) und `spreadconnect/create_order` -> `OrderSubmitJob::handle` (Priority 10, Args 1). Konstruktion der Instances erfolgt inline (`new OrderHandler(...)`, `new OrderSubmitJob(new SpreadconnectClient(...), new OrderStateMachine($wpdb), ...)`); echtes DI-Container-Wiring kommt in Slice 31/37.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-05-order-handler.php` und `tests/slices/pod-shop-mvp/slice-05-order-submit-job.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- KEIN `Order\OrderConfirmJob` / `OrderCancelJob` / `OrderCancelMirrorJob` (Slice 29/31).
- KEINE Auto-Confirm-Timer-Logik (`as_schedule_single_action('spreadconnect/confirm_order', ...)`) — kommt in Slice 31; in Slice 28 wird `_spreadconnect_state` auf `'NEW'` gesetzt und der Pfad endet dort.
- KEIN realer `Failure\FailedOpsRepo` — die Klasse existiert in Slice 28 noch nicht. Das 4xx-AC schreibt **nur** einen Logging-Tag (`failed_op_pending_record`), den der `RetryPolicyListener` in Slice 37 in eine echte `FailedOpsRepo::record()`-Insertion ueberfuehrt.
- KEINE `FailureNotifier`-Calls (`wp_mail` an Recipients) — Slice 39.
- KEINE persistenten Admin-Notices via `AdminNoticeStore` — Slice 39.
- KEINE Order-List-Spalten / Meta-Box-UI — Slice 32/33.
- KEIN `woocommerce_order_status_cancelled`-Hook — Slice 31.
- KEIN `try`/`catch` auf andere Exceptions als `SpreadconnectClientError` und `SpreadconnectTransientError` und `\InvalidArgumentException` (DTO-Validation -> Permanent-Pfad). Andere Exceptions (PHP-Fatals, Logic-Errors) duerfen durchschlagen, sodass AS sie als generischen Fail erfasst.
- KEINE Domain-Validierung im Job ausser DTO-Validation (z. B. **kein** Pre-Check fuer Adressen jenseits der DTO-Factory; Shipping-Type-Pre-Check ist Slice 29).
- KEIN Direct-Write auf `_spreadconnect_state` ausser via `OrderStateMachine::compareAndSet()` (Slice 27 ist single source of truth fuer State-Mutationen in dieser Slice).

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile.
- `final class` fuer beide neuen Klassen (`OrderHandler`, `OrderSubmitJob`).
- `OrderHandler::on_processing` MUSS defensiv prueft ob `$order` ein `WC_Order` ist (`if (! $order instanceof \WC_Order) { return; }`) — der Hook liefert je nach WC-Context unterschiedliche Args; per Architecture Z. 408 ist `[$order_id]` der Job-Payload.
- `as_enqueue_async_action()`-Args **immer** als assoc-Array `['order_id' => $order_id]` (NICHT als list-array `[$order_id]`) — dies stellt sicher, dass `as_has_scheduled_action()` mit identischem Args-Vergleich greift und der AS-Group-Filter `'spreadconnect'` konsistent matcht.
- Der Job liest `$args` als ersten Parameter und validiert: `$order_id = (int) ($args['order_id'] ?? 0); if ($order_id <= 0) { return; }` — bei invalidem Payload still-and-silent return (kein Throw, sonst AS retry-loop).
- `SpreadconnectClientError`-Catch muss **vor** `SpreadconnectTransientError`-Catch stehen (PHP-Catch-Order), da beide PHP-Exceptions sind und die Specific-First-Regel gilt — auch wenn die Klassen disjoint sind, ist die deklarierte Reihenfolge bindend.
- Logging via `wc_get_logger()->info()/warning()/error()` mit Source `'spreadconnect-order-service'` (Architecture Z. 398, 611). Kein `error_log()`.
- Order-Note-Strings in **Englisch** (Backend-only) — UI-Strings werden in Slice 46 i18n-ed; Order-Notes sind nicht customer-facing.
- Order-Note `add_order_note( $note, $is_customer_note=false, $added_by_user=false )` — NICHT customer-facing, kein E-Mail-Trigger.
- DTO-Build via Slice-09-Factories (`OrderCreate::fromArray()` oder Konstruktor-Aufruf — Implementer-Wahl, beide MUESSEN in Slice 09 verfuegbar sein); KEIN Direct-Property-Set.
- HPOS-Konformitaet: `$order->update_meta_data()` + `$order->save()` (NICHT `update_post_meta()` — letzteres bricht bei aktiviertem HPOS).
- Konstruktor-Parameter-Order: in `OrderSubmitJob` `(SpreadconnectClient, OrderStateMachine, ?WC_Logger=null)`; in `OrderHandler` `(?WC_Logger=null)` plus optional spaeter Auto-Confirm-Setting-Provider (Slice 31). KEINE `static`-Properties; Per-Instanz-State.
- Hook-Registrierung in `Bootstrap\Plugin::init()` MUSS hinter einem `did_action('plugins_loaded')`-aequivalenten Idempotency-Guard liegen — ODER direkt im `init()`-Body, wenn `init()` selbst nur einmal pro Request laeuft (Slice 02 Garantie). Implementer waehlt; AC-9 prueft `has_action()`-Identitaet bei doppeltem Aufruf.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + 03 + 04 + 05 + 06) | **Edit, nicht ersetzen.** Bisherige `init()`-Logik (HPOS-Declare, dbDelta-Activate, Options-Defaults, i18n-Loader) bleibt unveraendert; Slice 28 ergaenzt zwei `add_action()`-Calls und ggf. eine private Factory-Methode `getOrderHandler()`/`getOrderSubmitJob()`. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07 + 08 + 10) | **Wiederverwendet, unveraendert.** `OrderSubmitJob` ruft `createOrder()` (Slice-10-Wrapper). |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` / `SpreadconnectTransientError.php` (Slice 07/08) | **Wiederverwendet, unveraendert.** Job-`try/catch` unterscheidet die beiden. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/OrderCreate.php` etc. (Slice 09) | **Wiederverwendet, unveraendert.** DTO-Factories werden im Job-Helper aufgerufen. |
| `wordpress/plugins/spreadconnect-pod/includes/Order/OrderStateMachine.php` (Slice 27) | **Wiederverwendet, unveraendert.** Im Job injiziert; CAS-Calls fuer `''->submitting`, `submitting->NEW`, `submitting->failed_to_submit`. |

Hinweis: `Failure\FailedOpsRepo` existiert in Slice 28 noch **nicht** — daher ausschliesslich Logging-Stub. Slice 37 ersetzt das Stub durch echten Repo-Call ohne Schnittstellenaenderung am Job (der Repo-Call passiert dort via separater `RetryPolicyListener`, der auf `action_scheduler_failed_action`-Hook + auf das Logging-Tag reagiert).

**Referenzen:**
- Architecture: `architecture.md` -> "Business Logic Flow — Outbound Order Submit (Flow C)" Z. 401-430 — autoritative Sequenz inkl. Branch-Logik 2xx/4xx/5xx.
- Architecture: `architecture.md` -> "Action Scheduler — Hook Inventory" Z. 549 — `spreadconnect/create_order`-Trigger + Retry-Policy `1m/5m/15m on 5xx; permanent on 4xx`.
- Architecture: `architecture.md` -> "WC-Order Meta (HPOS)" Z. 305-313 — `_spreadconnect_state`-Enum-Werte (Slice 27 Source of Truth) + `_spreadconnect_order_id`.
- Architecture: `architecture.md` -> Service-Map Z. 366 — `Order\OrderSubmitJob`-Verantwortlichkeit.
- Architecture: `architecture.md` -> "Idempotency — Order Submit" Z. 682 — Doppelte `processing`-Hooks no-op via `_spreadconnect_order_id`-Check + `OrderStateMachine::compareAndSet`.
- Architecture: `architecture.md` -> "Open Questions Resolved" Q11 Z. 798 — `shippingType` inline akzeptiert; Fallback-Pfad in Slice 31.
- Architecture: `architecture.md` -> "Risks & Mitigation" Z. 731 — HPOS race; CAS verhindert PROCESSED-Overwrite (AC-8).
- Discovery: `discovery.md` -> Slice 5 "Order-Lifecycle" -> Action `create_order`.
- Slim-Slices: `slices/slim-slices.md` -> Slice-28-Eintrag Z. 453-461 — Done-Signal: processing-Hook 1x, doppelt = idempotent skip; 2xx -> meta `_spreadconnect_state='NEW'`+`_spreadconnect_order_id`; 4xx -> `failed_to_submit`; 5xx -> Transient.
- Slice 10: `slices/slice-10-endpoint-methods.md` -> `createOrder()`-Wrapper-Verhalten + Exception-Pass-Through.
- Slice 27: `slices/slice-27-order-state-machine.md` -> `compareAndSet()`-Semantik fuer alle State-Transitions.
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 28 (Job + Hook ohne UI; UI-Surface fuer Submit-State kommt in Slice 32/33).
