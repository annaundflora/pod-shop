# Slice 30: Order-Webhooks-Handler (Order.* + Shipment.sent)

> **Slice 30 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-30-order-webhooks-handler` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-28-order-submit-job", "slice-25-article-removed-job"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WC 10.5 HPOS + Action Scheduler + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `wc_get_orders`, `wc_get_order`, `as_enqueue_async_action`, `as_has_scheduled_action`, `wc_get_logger`, `__()`; `SpreadconnectClient::getShipments()` als Mockery-Mock; `OrderStateMachine::compareAndSet` als injizierter Mock; `Failure\AdminNoticeStore` als Logging-Stub — reale Implementation in Slice 39) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA via Slice 44 Dev-Tools `simulate/order-processed`/`simulate/shipment-sent` -> WC-Order-Status sichtbar mutiert in WC-Order-Liste) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer alle WP/WC/AS-Funktionen; SpreadconnectClient + OrderStateMachine via Konstruktor injiziert; keine echte DB-/HTTP-I/O. AdminNoticeStore-Stub via `wc_get_logger`-Spy) |

---

## Ziel

Schliesst Slice 5 "Order-Lifecycle" inbound-seitig: ersetzt den Slice-17-Stub `Webhook\OrderEventHandler` durch die echte Implementation der vier Order-/Shipment-Webhook-Reaktionen (Discovery Flow C Schritte 8-11, architecture.md Webhook-Mapping Z. 845-848). `Shipment.sent` enqueued den separaten `Order\FetchTrackingJob`, der ueber `getShipments()` Tracking-Meta schreibt und WC-Status auf `completed` setzt. Webhook-getriebene States (`PROCESSED`, `CANCELLED`) ueberleben spaeter eintreffende Submit-Jobs durch Last-Write-Wins (Discovery Z. 613).

---

## Acceptance Criteria

1) **AC-Reverse-Lookup — WC-Order via `_spreadconnect_order_id`**
   **GIVEN** ein Webhook-Payload mit `eventType='Order.processed'` und `data.entity.orderReference='sc_42'` (bzw. `data.entity.id='sc_42'` als Fallback — Implementer waehlt anhand Payload-Shape; Discovery Z. 850 nennt `orderReference`)
   **WHEN** `OrderEventHandler::handle($payload)` laeuft und der Reverse-Lookup `wc_get_orders(['meta_key'=>'_spreadconnect_order_id','meta_value'=>'sc_42','limit'=>1,'status'=>'any','type'=>'shop_order'])` genau ein WC-Order-Result liefert
   **THEN** wird die WC-Order-Instance an die internen Per-Event-Branches uebergeben; bei **leerem** Lookup-Result schreibt der Handler `wc_get_logger()->warning()` mit Source `spreadconnect-webhook-receiver` und Format `'OrderEventHandler: no matching WC-order sc_id={...} event_type={...}'` und kehrt ohne Exception zurueck (Architecture Z. 850: `processing_error='no matching WC-order'` wird durch Slice 17 als `processing_status='success'` markiert — Validation-Verantwortung liegt im Handler).

2) **AC-Order-processed — direct State-Write `PROCESSED` (kein CAS)**
   **GIVEN** WC-Order mit aktuellem `_spreadconnect_state='submitting'` (oder `NEW`/`CONFIRMED`); Webhook `Order.processed` empfangen
   **WHEN** `OrderEventHandler::handle($payload)` den Order-Branch ausfuehrt
   **THEN** ruft er `OrderStateMachine::writeUnchecked($order, 'PROCESSED')` (direct-write per Architecture Z. 619 + Slice-27-Tabelle: Webhook hat Vorrang via Last-Write-Wins, **kein CAS**), schreibt `_spreadconnect_last_event = sprintf('%d:%s', time(), 'Order.processed')` (architecture.md Z. 315), schreibt `$order->add_order_note('Spreadconnect: Order processed (state=PROCESSED)', false, false)`, und kehrt ohne Exception zurueck. Der WC-Status wird **nicht** mutiert (Discovery Z. 539: "(still)"; nur `Shipment.sent` wechselt zu `completed`).

3) **AC-LWW-Race — `submitting->PROCESSED` ueberlebt spaeteren `submitting->NEW`-CAS**
   **GIVEN** Webhook `Order.processed` setzt `_spreadconnect_state='PROCESSED'` direkt (AC-2). Anschliessend laeuft der Slice-28-`OrderSubmitJob` zu Ende und ruft `OrderStateMachine::compareAndSet($order, 'submitting', 'NEW')` auf (Slice-28 AC-8 Race-Pfad).
   **WHEN** der CAS auswertet
   **THEN** liefert er `false` (Slice 27 AC-2 + Slice 28 AC-8); der finale `_spreadconnect_state` bleibt `'PROCESSED'`. Slice 30 verifiziert via Test, dass nach Sequenz `[Webhook.Order.processed, OrderSubmitJob.complete]` der State `'PROCESSED'` ist (Done-Signal aus slim-slices.md Z. 483: "State-Override submitting->PROCESSED ueberlebt spaeter eintreffende submitting->NEW (LWW)").

4) **AC-Order-cancelled — State `CANCELLED` + WC-Status `cancelled`**
   **GIVEN** WC-Order mit aktuellem WC-Status `processing` (oder `on-hold`); Webhook `Order.cancelled`
   **WHEN** `OrderEventHandler::handle($payload)` den Order-Branch ausfuehrt
   **THEN** ruft er (a) `OrderStateMachine::writeUnchecked($order, 'CANCELLED')`; (b) `$order->update_status('cancelled', 'Spreadconnect: Order cancelled (webhook)', false)` **nur** wenn `$order->get_status()` nicht bereits `cancelled` oder `refunded` ist (Idempotency-Guard gegen Loop mit Slice-31 WC-Cancel-Mirror); (c) schreibt `_spreadconnect_last_event = "<unix>:Order.cancelled"`; (d) Order-Note `'Spreadconnect: Order cancelled (state=CANCELLED)'`. Bei bereits `cancelled`-WC-Status: nur State + Last-Event + Order-Note, kein `update_status`-Call.

5) **AC-Order-needs-action — Flag + Admin-Notice + Order-Note**
   **GIVEN** WC-Order; Webhook `Order.needs-action` mit `data.errorReason='SKU not found'` (architecture.md Z. 175 + Discovery Z. 603)
   **WHEN** `OrderEventHandler::handle($payload)` den Order-Branch ausfuehrt
   **THEN** ruft er (a) `$order->update_meta_data('_spreadconnect_needs_action', true)` + `$order->save()` (architecture.md Z. 311 — orthogonaler Flag, **kein** State-Mutate); (b) **kein** `OrderStateMachine`-Call (State bleibt unveraendert; needs-action ist orthogonal); (c) ruft `recordPersistentNotice($order, $errorReason)` (in dieser Slice als `wc_get_logger()->error()`-Stub mit Source `spreadconnect-webhook-receiver` und Tag `admin_notice_pending_record`; echte `Failure\AdminNoticeStore::add()` kommt in Slice 39); (d) Order-Note `sprintf('Spreadconnect: Order needs action — %s', $errorReason)`; (e) schreibt `_spreadconnect_last_event = "<unix>:Order.needs-action"`. Bei fehlendem `errorReason` -> Note-String ohne Suffix (`'Spreadconnect: Order needs action'`).

6) **AC-Shipment-sent — Schedule `fetch_tracking`-Job**
   **GIVEN** WC-Order mit `_spreadconnect_order_id='sc_42'`; Webhook `Shipment.sent` (architecture.md Z. 552)
   **WHEN** `OrderEventHandler::handle($payload)` den Shipment-Branch ausfuehrt
   **THEN** ruft er **genau einmal** `as_enqueue_async_action('spreadconnect/fetch_tracking', ['order_id'=>$order->get_id()], 'spreadconnect')` (Group-Konvention analog Slice 28 AC-1) **nur** wenn `as_has_scheduled_action('spreadconnect/fetch_tracking', ['order_id'=>$order->get_id()], 'spreadconnect')` `false` liefert (Idempotency-Guard analog Slice 28 AC-3); schreibt `_spreadconnect_last_event = "<unix>:Shipment.sent"`. **Kein** direkter `getShipments()`-Call im Handler — die HTTP-I/O laeuft im separaten `FetchTrackingJob` (architecture.md Z. 552). **Kein** State-Mutate (Discovery Z. 540: "(still PROCESSED)").

7) **AC-FetchTrackingJob-Success — Tracking-Meta + WC-Status `completed`**
   **GIVEN** `Order\FetchTrackingJob::handle(['order_id'=>7])` wird vom Action-Scheduler aufgerufen; WC-Order #7 hat `_spreadconnect_order_id='sc_42'`; `SpreadconnectClient::getShipments('sc_42')` liefert ein non-leeres Array mit mindestens einem Shipment-Element (z. B. `[['trackingNumber'=>'TRK123', 'trackingUrl'=>'https://dhl/TRK123', 'carrier'=>'DHL', 'state'=>'sent']]`)
   **WHEN** der Job laeuft
   **THEN** persistiert er aus dem **ersten** Shipment-Element (architecture.md Z. 313-314): `_spreadconnect_tracking_number = 'TRK123'`, `_spreadconnect_tracking_url = 'https://dhl/TRK123'`, optional `_spreadconnect_carrier = 'DHL'` (falls Schluessel vorhanden); ruft `$order->update_status('completed', sprintf('Spreadconnect: Shipped — tracking %s (%s)', 'TRK123', 'DHL'), false)` **nur** wenn `$order->get_status() !== 'completed'` (Idempotency-Guard); Order-Note via `update_status`-Note-Param ist ausreichend (kein zusaetzlicher `add_order_note`-Call). Bei mehreren Shipment-Elementen wird das **erste** ausgewertet und ein Info-Log mit Source `spreadconnect-order-service` + Format `'FetchTrackingJob: multiple shipments order_id={N}, using first'` geschrieben.

8) **AC-FetchTrackingJob-NoShipments — leeres Array, kein WC-Status-Wechsel**
   **GIVEN** `FetchTrackingJob::handle(['order_id'=>7])`; `getShipments('sc_42')` liefert `[]` (Race: Webhook frueher als SC-API-Konsistenz)
   **WHEN** der Job laeuft
   **THEN** wirft er `\SpreadconnectPod\Order\FetchTrackingException` (`extends \RuntimeException`) mit Message `'FetchTrackingJob: empty shipments response order_id=7 sc_id=sc_42'`. Action-Scheduler retried 1m/5m/15m (architecture.md Z. 552). **Kein** Tracking-Meta-Write, **kein** WC-Status-Wechsel. Nach 3 Retries uebernimmt Slice 37 `RetryPolicyListener` die DLQ-Aufnahme.

9) **AC-FetchTrackingJob-Errors — Permanent vs Transient**
   **GIVEN** `FetchTrackingJob::handle(['order_id'=>7])`; `getShipments('sc_42')` wirft entweder `SpreadconnectClientError` (4xx, z. B. 404 — SC-Order nicht gefunden) ODER `SpreadconnectTransientError` (5xx/network)
   **WHEN** der Job die Exception faengt
   **THEN** (a) bei `SpreadconnectClientError` (permanent): logt mit Source `spreadconnect-order-service`, Tag `failed_op_pending_record` + Context `['op_type'=>'fetch_tracking', 'related_entity_type'=>'order', 'related_entity_id'=>7]` (analog Slice 28 AC-5; echte `FailedOpsRepo::record()` kommt in Slice 37), wirft **nicht** weiter; (b) bei `SpreadconnectTransientError`: re-throw fuer AS-Retry (analog Slice 28 AC-6), kein FailedOps-Stub-Aufruf. Reihenfolge im `try/catch`: `SpreadconnectClientError` zuerst (specific), dann `SpreadconnectTransientError`.

10) **AC-FetchTrackingJob-Idempotency — bereits `completed` -> No-Op**
    **GIVEN** `FetchTrackingJob::handle(['order_id'=>7])`; WC-Order #7 hat bereits `_spreadconnect_tracking_number='TRK123'` und WC-Status `'completed'` (Re-Run nach Retry oder doppelter Webhook)
    **WHEN** der Job laeuft
    **THEN** ruft er `getShipments()` **trotzdem** auf (Refresh ist guenstig; idempotenter Read), schreibt `_spreadconnect_tracking_*`-Meta erneut (Last-Write-Wins, identische Werte), aber ruft `$order->update_status('completed', ...)` **nicht** (Idempotency-Guard aus AC-7). Job wirft nicht.

11) **AC-Bootstrap-Hook-Wiring — `add_action('spreadconnect/fetch_tracking', ...)`**
    **GIVEN** Plugin-Boot
    **WHEN** `Bootstrap\Plugin::init()` durchlaeuft
    **THEN** ist `add_action('spreadconnect/fetch_tracking', [FetchTrackingJob::class, 'handleStatic'], 10, 1)` mit Priority `10` und genau **einem** Argument-Slot registriert (analog Slice 28 AC-9 fuer `create_order` und Slice 25 AC-9 fuer `handle_article_removed`). Idempotent (Slice 02 AC-5 Pattern; doppelter `init()`-Call fuegt nicht doppelt hinzu).

12) **AC-Stub-Replace — Slice-17-Vertrag bleibt erfuellt**
    **GIVEN** der Stub-`OrderEventHandler` aus Slice 17 (`final class` mit `public static function handle(array $payload): void` als reine Logging-Methode)
    **WHEN** Slice 30 die Datei `includes/Webhook/OrderEventHandler.php` ueberschreibt
    **THEN** bleibt die Klassen-/Method-Signatur unveraendert (`final class OrderEventHandler` mit `public static function handle(array $payload): void`), damit der `ProcessWebhookEventJob`-Dispatcher aus Slice 17 ohne Edit weiter funktioniert (Slice 17 Integration Contract Z. 234). Bei unbekanntem `eventType`-Wert innerhalb der Order-/Shipment-Domain (z. B. `Order.foo` — Prefix-Match traf, aber kein bekannter Sub-Event) loggt der Handler eine Warning und kehrt ohne Throw zurueck (Permanent-Validation-Failure, kein AS-Retry sinnvoll).

13) **AC-Invalid-Payload — fehlende `entity.id`/`orderReference`**
    **GIVEN** ein Webhook-Payload mit gueltigem `eventType` aus der Order-Domain, aber ohne `data.entity.orderReference` UND ohne `data.entity.id` (oder beide leerer String / nicht-string)
    **WHEN** `OrderEventHandler::handle($payload)` laeuft
    **THEN** wirft er **keine** Exception (Permanent-Validation-Failure, kein AS-Retry sinnvoll), loggt eine Warning mit Source `spreadconnect-webhook-receiver` und Format `'OrderEventHandler: missing or invalid entity reference event_type={...}'` und kehrt frueh zurueck. Kein Reverse-Lookup, kein State-Mutate, kein Enqueue.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Zwei PHPUnit-Test-Files: `tests/slices/pod-shop-mvp/slice-05-order-event-handler.php` (Handler-Logik AC-1..AC-6, AC-12, AC-13) und `tests/slices/pod-shop-mvp/slice-05-fetch-tracking-job.php` (Job-Logik AC-7..AC-11). Brain\Monkey-Setup mit `Functions\expect('wc_get_orders')`, `Functions\expect('as_enqueue_async_action')`, `Functions\when('as_has_scheduled_action')`, `Functions\when('time')->justReturn(1700000000)`. SpreadconnectClient + OrderStateMachine als Mockery-Mocks via Konstruktor injiziert. AC-3 (LWW-Race) wird als Sequenz-Test geschrieben: erst Handler invoken, dann simulieren von `OrderStateMachine::compareAndSet('submitting','NEW')` mit Slice-27-CAS-Logik (Mockery-Spy verifiziert dass CAS `false` liefert weil State bereits `PROCESSED`).

### Test-Datei: `tests/slices/pod-shop-mvp/slice-05-order-event-handler.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class OrderEventHandlerTest extends TestCase
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

    // AC-1: Reverse-Lookup auf _spreadconnect_order_id liefert WC-Order
    public function test_handler_resolves_wc_order_via_meta_reverse_lookup(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Leerer Lookup -> Warning-Log + frueher Return, keine Exception
    public function test_handler_logs_and_returns_when_no_wc_order_matches(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Order.processed -> direkt-Write PROCESSED (writeUnchecked, kein CAS)
    public function test_order_processed_writes_state_processed_directly(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Order.processed schreibt _spreadconnect_last_event mit Unix-Timestamp
    public function test_order_processed_writes_last_event_meta(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Order.processed mutiert WC-Status NICHT
    public function test_order_processed_does_not_change_wc_status(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: LWW-Race — submitting->PROCESSED via Webhook ueberlebt spaeteren submitting->NEW-CAS
    public function test_processed_webhook_survives_late_submit_job_cas_to_new(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Order.cancelled -> State CANCELLED + WC-Status cancelled
    public function test_order_cancelled_sets_state_and_wc_status(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: Bereits cancelled-WC-Status -> kein update_status-Call (Idempotency-Guard)
    public function test_order_cancelled_skips_update_status_when_already_cancelled(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Order.needs-action -> _spreadconnect_needs_action=true + Order-Note + Notice-Stub
    public function test_order_needs_action_sets_flag_and_notice_stub(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: Order.needs-action mutiert _spreadconnect_state NICHT
    public function test_order_needs_action_does_not_mutate_state(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: Fehlender errorReason -> Order-Note ohne Suffix
    public function test_order_needs_action_handles_missing_error_reason(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Shipment.sent -> as_enqueue_async_action('spreadconnect/fetch_tracking', ...)
    public function test_shipment_sent_enqueues_fetch_tracking_job(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-6: Shipment.sent — Idempotency-Guard via as_has_scheduled_action
    public function test_shipment_sent_is_idempotent_when_action_already_scheduled(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-6: Shipment.sent ruft KEINE getShipments() im Handler
    public function test_shipment_sent_does_not_call_get_shipments_in_handler(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-12: Klassen-/Method-Signatur unveraendert seit Slice 17
    public function test_order_event_handler_signature_matches_slice_17_contract(): void
    {
        $this->markTestIncomplete('AC-12');
    }

    // AC-12: Unknown sub-event innerhalb Order-Domain -> Warning, kein Throw
    public function test_unknown_order_sub_event_logs_warning_and_returns(): void
    {
        $this->markTestIncomplete('AC-12');
    }

    // AC-13: Fehlende entity.id/orderReference -> Warning + frueher Return
    public function test_handler_skips_when_entity_reference_missing(): void
    {
        $this->markTestIncomplete('AC-13');
    }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-05-fetch-tracking-job.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class FetchTrackingJobTest extends TestCase
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

    // AC-7: getShipments-Success -> Tracking-Meta + WC-Status completed
    public function test_handle_persists_tracking_meta_and_completes_order(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: Mehrere Shipments -> erstes wird gewaehlt + Info-Log
    public function test_handle_uses_first_shipment_when_multiple_present(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Leeres Shipments-Array -> FetchTrackingException (AS-Retry)
    public function test_handle_throws_on_empty_shipments(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: SpreadconnectClientError (4xx) -> Logging-Stub, kein Re-Throw
    public function test_handle_treats_client_error_as_permanent(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-9: SpreadconnectTransientError (5xx) -> Re-Throw fuer AS-Retry
    public function test_handle_rethrows_transient_error_for_as_retry(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Bereits completed -> Tracking-Meta-Refresh, aber kein update_status
    public function test_handle_skips_status_update_when_already_completed(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: Bootstrap registriert add_action('spreadconnect/fetch_tracking', ...) idempotent
    public function test_plugin_init_registers_fetch_tracking_hook(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    public function test_plugin_init_is_idempotent_for_fetch_tracking_hook(): void
    {
        $this->markTestIncomplete('AC-11');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-17-process-webhook-event-job` | Dispatcher ruft `Webhook\OrderEventHandler::handle(array $payload): void` per `Order.*`/`Shipment.*`-Prefix-Match | static method dispatch | Slice 17 AC-4 verifiziert Mapping; Slice 30 ueberschreibt Stub-Klasse ohne Signatur-Aenderung. |
| `slice-17-process-webhook-event-job` | Datei `includes/Webhook/OrderEventHandler.php` (Stub) | Existing file | Slice 30 ueberschreibt **nur** den Method-Body; Klassen-Header + Namespace + Method-Signature unveraendert. |
| `slice-25-article-removed-job` | Slim-Slices-Dependency-Order (Stub-Replace-Pattern etabliert) | Convention | Slice 25 etabliert Stub-Replace-Pattern + `handleStatic`-Bridge — Slice 30 spiegelt es 1:1. |
| `slice-27-order-state-machine` | `OrderStateMachine::writeUnchecked(WC_Order, string): void` (direct-write fuer Webhook-Path; siehe Slice 27 Constants `STATE_PROCESSED`/`STATE_CANCELLED`) | instance method | Slice 27 AC-Tabelle Z. 46-47: Webhook-Path nutzt direct-write, **nicht** CAS. Bei fehlender Methode in Slice 27 ist dies ein expliziter Slice-30-Edit-Bedarf (siehe Constraints). |
| `slice-27-order-state-machine` | `OrderStateMachine::compareAndSet(...)` | instance method | AC-3 LWW-Race-Verifikation per Slice-27-CAS-Semantik. |
| `slice-28-order-submit-job` | `_spreadconnect_order_id`-Meta gesetzt nach 2xx-Submit | DB-Resource | Reverse-Lookup-Anker fuer Slice 30 Webhooks. |
| `slice-10-endpoint-methods` | `SpreadconnectClient::getShipments(string $scOrderId): array` | public method | Liefert Array von Shipment-DTOs (architecture.md Z. 103: `Shipment[]` mit `trackingNumber`/`trackingUrl`/`carrier`); wirft `SpreadconnectClientError`/`SpreadconnectTransientError` analog Slice 28. |
| `slice-07-http-client-base` | `SpreadconnectClientError` / `SpreadconnectTransientError` | exception classes | Job-`try/catch` unterscheidet permanent vs. transient. |
| WordPress / WooCommerce | `wc_get_orders`, `WC_Order::update_status`/`update_meta_data`/`save`/`add_order_note`/`get_status` | WC-API | HPOS-aktiv (Slice 03). |
| Action Scheduler | `as_enqueue_async_action`, `as_has_scheduled_action` | AS-API | Group `'spreadconnect'`. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Webhook\OrderEventHandler::handle` | static method (final implementation) | `Webhook\ProcessWebhookEventJob` (Slice 17 Dispatcher) | `public static function handle(array $payload): void` (unveraendert seit Slice 17) |
| `SpreadconnectPod\Order\FetchTrackingJob::handle` | instance method | Action-Scheduler-Worker via `add_action`-Bridge | `public function handle(array $args): void` mit `$args=['order_id'=>int]` |
| `SpreadconnectPod\Order\FetchTrackingJob::handleStatic` | static method (Bridge) | Bootstrap-Hook-Registration | `public static function handleStatic(array $args): void` |
| `SpreadconnectPod\Order\FetchTrackingException` | Exception class | `Failure\RetryPolicyListener` (Slice 37) | `final class FetchTrackingException extends \RuntimeException` |
| Action-Hook `spreadconnect/fetch_tracking` | Action-Hook (registered) | Action-Scheduler; `Webhook\OrderEventHandler` (Producer fuer `Shipment.sent`) | Hook-Args: `array $args=['order_id'=>int]` |
| WC-Order-Meta `_spreadconnect_needs_action`, `_spreadconnect_tracking_number`, `_spreadconnect_tracking_url`, `_spreadconnect_carrier`, `_spreadconnect_last_event` | DB-Resource | Slice 32 (Order-Edit-Meta-Box rendert Tracking + Needs-Action-Badge); Slice 33 (Order-List-Filter-Dropdown "Needs Action"); Slice 39 (echter `AdminNoticeStore` ersetzt Stub) | Schema laut architecture.md Z. 311-315. |
| Logging-Stub-Tag `admin_notice_pending_record` | Logging-Convention (string-literal) | Slice 39 `AdminNoticeStore` ersetzt Stub durch echten Option-Write | Source `spreadconnect-webhook-receiver`, Level `error`, Context `['order_id'=>int, 'reason'=>string, 'op_type'=>'order_needs_action']` |
| Logging-Stub-Tag `failed_op_pending_record` | Logging-Convention (string-literal) | Slice 37 `RetryPolicyListener` ersetzt durch echten `FailedOpsRepo::record()` | Identisch zu Slice 28 AC-5 Convention. |

> **Mount-Point-Check:** `FetchTrackingJob` wird durch `add_action`-Edit in `Bootstrap\Plugin::init()` an Action-Scheduler gehaengt (Deliverable). `OrderEventHandler` wird durch Slice-17-Dispatcher konsumiert via Class-FQN — kein zusaetzlicher Mount-Edit noetig (analog Slice 25 Pattern).

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Webhook/OrderEventHandler.php` — **Vollstaendiger Replace** der Slice-17-Stub-Klasse: `final class SpreadconnectPod\Webhook\OrderEventHandler` mit `public static function handle(array $payload): void`. Reverse-Lookup via `wc_get_orders(['meta_key'=>'_spreadconnect_order_id', ...])`; Per-Event-Branches `Order.processed`/`Order.cancelled`/`Order.needs-action`/`Shipment.sent` (siehe ACs 1-6, 12, 13). Klassen-Signatur unveraendert (Slice-17-Dispatcher-Vertrag).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Order/FetchTrackingJob.php` — Neue Klasse `final class SpreadconnectPod\Order\FetchTrackingJob`. Konstruktor injiziert `SpreadconnectClient`, optional `?WC_Logger`. Methode `public function handle(array $args): void` (Tracking-Meta-Write + WC-Status-`completed`-Wechsel; siehe ACs 7-10) und `public static function handleStatic(array $args): void` (Bridge fuer `add_action`). Inklusive `final class FetchTrackingException extends \RuntimeException` (in derselben Datei oder Sibling — analog `ArticleRemovedException` aus Slice 25).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Webhook/ProcessWebhookEventJob.php` — **Dispatcher-Wiring-Verifikation** laut slim-slices.md Slice-30-Spec. Default: kein Code-Edit noetig (Slice 17 dispatched per Class-FQN; OrderEventHandler-Replace in dieser Slice greift automatisch). Falls Slice 17 stattdessen Map-/Closure-Dispatch nutzt, MUSS dieser Slice den Map-Eintrag fuer `Order.*`/`Shipment.*` auf den echten Handler updaten. Edit MUSS Slice-17 ACs 1-9 unveraendert lassen.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Ergaenzt `add_action('spreadconnect/fetch_tracking', [FetchTrackingJob::class, 'handleStatic'], 10, 1)` analog zur bestehenden `create_order` (Slice 28) / `handle_article_removed` (Slice 25) Hook-Registration. Konstruktion der Instance erfolgt inline (`new FetchTrackingJob(new SpreadconnectClient(...), ...)`); echtes DI-Container-Wiring kommt in Slice 37.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-05-order-event-handler.php` und `tests/slices/pod-shop-mvp/slice-05-fetch-tracking-job.php`. Keine Edits an `WebhookLogRepo` (Slice 16), `OrderHandler`/`OrderSubmitJob` (Slice 28), `OrderStateMachine` (Slice 27 — siehe Constraints fuer ggf. noetigen `writeUnchecked`-Edit).

---

## Constraints

**Scope-Grenzen:**
- KEIN realer `Failure\AdminNoticeStore` — Slice 39. Das `Order.needs-action`-AC schreibt **nur** einen Logging-Tag (`admin_notice_pending_record`). Slice 39 ersetzt den Stub durch echten Option-Write.
- KEIN realer `Failure\FailedOpsRepo` — Slice 37 (analog Slice 28 Convention). Permanent-Errors im `FetchTrackingJob` schreiben Logging-Tag `failed_op_pending_record`.
- KEINE persistente Admin-Notice-Render-Logik (Hook `admin_notices`) — Slice 39.
- KEINE `FailureNotifier`-`wp_mail`-Calls — Slice 39.
- KEIN `woocommerce_order_status_cancelled`-Hook-Listener (WC-Cancel-Mirror) — Slice 31. Diese Slice macht **inbound** SC -> WC; Outbound WC -> SC ist Slice 31.
- KEINE Auto-Confirm-Timer-Cancel-Logic bei `Order.processed` — der Timer-Cancel passiert in Slice 31, falls relevant; in Slice 30 wird nur State persistiert.
- KEINE Order-List-/Order-Edit-UI — Slice 32 (Order-Edit-Meta-Box) rendert das Tracking + Needs-Action-Badge; Slice 33 die Order-List-Filter "Needs Action".
- KEIN Direkt-Write auf `_spreadconnect_state` ausser via `OrderStateMachine` (Slice 27 ist Single Source of Truth fuer State-Mutationen).
- KEINE Re-Subscribe-Logic bei Webhook-Drift — Slice 18/19.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in beiden neuen Files.
- `final class` fuer `OrderEventHandler` und `FetchTrackingJob`.
- `OrderEventHandler::handle` ist `public static` (Slice-17-Vertrag); `FetchTrackingJob::handle` ist `public` (Instance-Method, Konstruktor-DI), `handleStatic` als statische Bridge fuer `add_action`.
- **Reverse-Lookup-Konvention** (AC-1): `wc_get_orders(['meta_key'=>'_spreadconnect_order_id','meta_value'=>$scOrderId,'limit'=>1,'status'=>'any','type'=>'shop_order','return'=>'objects'])`. **Kein** rohes `$wpdb`-Query auf `wp_postmeta`/`wp_wc_orders_meta` — `wc_get_orders` ist HPOS-konform (architecture.md Z. 537, 637, 850).
- **State-Direct-Write** (AC-2/4): Slice 30 ruft `OrderStateMachine::writeUnchecked($order, $target)` (direct-write, **kein** CAS) — Webhook-Vorrang per Architecture Z. 619 + Slice 27 Tabelle Z. 46. **Edit-Bedarf in Slice 27**: Falls `OrderStateMachine` (Slice 27) keine `writeUnchecked()`-Methode exposed, MUSS Slice 30 sie ergaenzen (private->public Promotion oder neue Methode); Implementer prueft Slice-27-Datei und faellt zurueck auf `update_meta_data($order, '_spreadconnect_state', $target)` + `$order->save()` direkt im Handler, dokumentiert per Inline-Kommentar (Out-of-band-Path zu CAS). Begruendung: Discovery Z. 619 dokumentiert "Sonstige Felder ... unproblematisch unter Last-Write-Wins"; CAS ist Slice 27 ausschliesslich fuer `submitting->NEW`/`submitting->failed_to_submit`.
- **HPOS-Konformitaet** (AC-2/4/5/7): `$order->update_meta_data()` + `$order->save()` (NICHT `update_post_meta()`).
- **WC-Status-Wechsel** (AC-4/7): `$order->update_status($newStatus, $note, false)` mit `$manual_update=false` — Hooks fuer `woocommerce_order_status_*` werden gefeuert (wichtig fuer Pinterest-CAPI-Plugin-Integration). Idempotency-Guard (`if ($order->get_status() !== $target)`) verhindert Loop mit Slice-31 WC-Cancel-Mirror.
- **Last-Event-Format** (AC-2/4/5/6): `_spreadconnect_last_event = sprintf('%d:%s', time(), $eventType)` exakt wie architecture.md Z. 315 dokumentiert.
- **Action-Scheduler-Group** (AC-6/11): `'spreadconnect'` als 3. Argument zu `as_enqueue_async_action` und `as_has_scheduled_action` — identisch zu Slice 25/28.
- **Args-Shape** fuer `fetch_tracking`-Enqueue: `['order_id' => $order->get_id()]` (assoc-array, NICHT list — analog Slice 28 Constraint).
- **`getShipments()`-Argument** (AC-7): SC-Order-ID aus `_spreadconnect_order_id`-Meta lesen (`$order->get_meta('_spreadconnect_order_id')`). Bei leerem Meta -> Throw `FetchTrackingException` (Permanent — Job-Args inkonsistent).
- **Try/Catch-Reihenfolge** im FetchTrackingJob (AC-9): `SpreadconnectClientError` ZUERST (permanent), `SpreadconnectTransientError` ZWEITENS (transient/re-throw). Andere Exceptions (PHP-Fatals) duerfen durchschlagen.
- **Logging via `wc_get_logger()`** (NICHT `error_log`). Sources: `spreadconnect-webhook-receiver` fuer Handler-Logs (Validation, Reverse-Lookup-Misses, Notice-Stub); `spreadconnect-order-service` fuer FetchTrackingJob-Logs (HTTP-Response, FailedOps-Stub).
- **Order-Note-Strings in Englisch** (Backend-only, analog Slice 28 Constraint). Customer-facing-Status-Wechsel-Noten via `update_status($status, $note)` werden ebenfalls auf Englisch geschrieben (Slice 46 i18n-isiert spaeter).
- **`add_action`-Hook in `Plugin::init()`** mit Priority `10`, accepted_args `1`. Argument-Shape muss exakt zur `as_enqueue_async_action(..., $args)`-Sequenz passen (analog Slice 25 AC-9).
- **Slice-17-Dispatcher-Edit ist minimal**: Falls `ProcessWebhookEventJob` per Class-FQN dispatched (Slice 17 Constraint), bleibt der Code unveraendert — Slice 30 ueberschreibt nur den Klassen-Body. Default-Annahme: FQN-Dispatch (analog Slice 25 Constraint).

**Reuse:**

Slice 30 baut ausschliesslich auf bestehenden Bausteinen auf; **keine** Neuimplementierung von Webhook-Pipeline, Reverse-Lookup-Helper oder Hook-Registration:

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Webhook/OrderEventHandler.php` (Slice 17 Stub) | **Replace-Target** — Body wird vollstaendig ersetzt; Klassen-Header + Namespace + Method-Signatur bleiben unveraendert (Slice-17-Dispatcher-Vertrag). |
| `wordpress/plugins/spreadconnect-pod/includes/Webhook/ProcessWebhookEventJob.php` (Slice 17) | **Edit-Target (minimal/no-op)** — Verifiziert dass Order-/Shipment-Prefix-Match den echten Handler aufruft. Default: kein Code-Edit. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + Edits aus Slice 03/04/05/06/17/23/24/25/28) | **Edit-Target** — Ergaenzt `add_action('spreadconnect/fetch_tracking', ...)` analog vorhandener Action-Hook-Registrationen. |
| `wordpress/plugins/spreadconnect-pod/includes/Order/OrderStateMachine.php` (Slice 27) | **Reuse + ggf. minimaler Methoden-Add** — `writeUnchecked()`-Methode ggf. ergaenzen, falls Slice 27 nur `compareAndSet()` exposed (siehe Technical Constraint oben). Keine Aenderung an `compareAndSet()`-Signatur. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08/10) | **Reuse, no edit** — `FetchTrackingJob` ruft `getShipments()` (Slice-10-Wrapper). |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` + `SpreadconnectTransientError.php` (Slice 07) | **Reuse, no edit** — Job-`try/catch` unterscheidet die beiden. |
| WC-Order-Meta `_spreadconnect_order_id`/`_spreadconnect_state`/`_spreadconnect_needs_action`/`_spreadconnect_tracking_*`/`_spreadconnect_last_event` (Slice 28 schreibt `order_id` + `state`) | **Reuse** — Schema-Source-of-Truth ist architecture.md Z. 307-315. |
| Konstruktor-DI- und `handleStatic`-Bridge-Pattern aus Slice 25/28 | **Reuse** — `FetchTrackingJob` spiegelt das Pattern (kein neues Bridge-Konzept). |
| Action-Scheduler-Group `'spreadconnect'` (Slice 24/25/28 etabliert) | **Reuse** — Slice 30 nutzt identische Group fuer `fetch_tracking`. |
| Architecture Service-Map Z. 381 + AS-Inventory Z. 552 + Webhook-Mapping Z. 845-848 + WC-Order-Meta Z. 311-315 + Race-Tabelle Z. 731 + Last-Event-Format Z. 315 + Reverse-Lookup-Konvention Z. 850 | **Single Source of Truth** fuer Handler-Klassennamen, Hook-Namen, Args-Shapes, Meta-Keys, State-Werte. |

**Referenzen:**
- Architecture: `architecture.md` -> Service-Map Zeile `Webhook\OrderEventHandler` (Z. 381: "Order.processed/cancelled/needs-action/Shipment.sent -> state mutations + WC-status updates"); AS-Inventory `spreadconnect/fetch_tracking` (Z. 552: "Shipment.sent-webhook, one-shot, Concurrency 1 per SC-order, Retry 1m/5m/15m"); Webhook-Mapping-Tabelle Z. 845-848 (vier Order-/Shipment-Reaktionen 1:1); WC-Order-Meta-Schema Z. 307-315 (`_spreadconnect_state` Enum + `_spreadconnect_needs_action` orthogonaler Flag + `_spreadconnect_tracking_number`/`_url`/`_carrier` + `_spreadconnect_last_event` Format); Reverse-Lookup-Konvention Z. 850 (`wc_get_orders(['meta_query'=>...])`); Race-Tabelle Z. 731 (HPOS race + LWW); Discovery-Race-Doku Z. 619 (LWW fuer Webhook-Path); GET /orders/{id}/shipments Z. 103 (Trigger durch `Shipment.sent` + Tracking-Felder).
- Discovery: `discovery.md` -> Flow C "Order-Lifecycle" Schritte 8-11 (Z. 158-162; vier Webhook-Reaktionen autoritativ); State-Machine-Tabelle Z. 539-541 (Order.processed -> still WC-Status, Shipment.sent -> WC-Status completed, Order.needs-action -> Notice persistent); Race-Tabelle Z. 613 (Order.processed-Webhook waehrend submitting -> LWW); Webhook-Payload-Shape Z. 603 (`data.entity` + bei needs-action `errorReason`).
- Slim-Slices: `slices/slim-slices.md` -> Slice-30-Eintrag (Z. 477-485; Done-Signal: "jedes der 4 Events mutiert State + WC-Status korrekt; State-Override submitting->PROCESSED ueberlebt spaeter eintreffende submitting->NEW (LWW)").
- Vorgaenger Slice 17: `slices/slice-17-process-webhook-event-job.md` -> Integration Contract Z. 234 (`OrderEventHandler::handle ... vollstaendig ueberschrieben in Slice 30`); AC-4 (Prefix-Match `Order.`/`Shipment.` -> `OrderEventHandler`); AC-10 (Stub-Signatur).
- Vorgaenger Slice 25: `slices/slice-25-article-removed-job.md` -> Replace-Pattern fuer Stub + `handleStatic`-Bridge + Bootstrap-Edit-Konvention (analoges Vorbild fuer Slice 30).
- Vorgaenger Slice 27: `slices/slice-27-order-state-machine.md` -> CAS-Semantik (AC-2 LWW-Verifikation in Slice 30 AC-3); ggf. `writeUnchecked()`-Methoden-Add fuer direct-write (siehe Technical Constraint).
- Vorgaenger Slice 28: `slices/slice-28-order-submit-job.md` -> Hook-Registration-Pattern (`handleStatic`); Konstruktor-DI; Logging-Stub-Tag-Konvention `failed_op_pending_record`; Idempotency-Guard via `as_has_scheduled_action`.
- Folge Slice 31: `slim-slices.md` Z. 489-497 (WC-Cancel-Mirror nutzt `_spreadconnect_state='NEW'`-Read; Slice 30 setzt das State-Schema bereit).
- Folge Slice 32: `slim-slices.md` Z. 501-509 (Order-Edit-Meta-Box rendert Tracking-Meta + Needs-Action-Flag, die in Slice 30 geschrieben werden).
- Folge Slice 37: `slim-slices.md` Z. 559-566 (`FailedOpsRepo` faengt `FetchTrackingException` nach 3 AS-Retries als DLQ-Eintrag).
- Folge Slice 39: `slim-slices.md` Z. 583-589 (`AdminNoticeStore` ersetzt den `admin_notice_pending_record`-Logging-Stub aus AC-5).
- Wireframes: `wireframes.md` — **nicht direkt relevant** (Handler + Job ohne UI-Touch; `Shipments`-Block in Order-Edit-Meta-Box ist Slice 32; persistent-Notice-Render ist Slice 39).
