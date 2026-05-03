# Slice 17: Process-Webhook-Event-Job (Dispatcher)

> **Slice 17 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-17-process-webhook-event-job` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-16-event-id-hasher"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `add_action`, `wc_get_logger`, `__()`; `WebhookLogRepo::find` und `::updateProcessingStatus` als statische Mockery-Spies; Stub-Handler-Closures als injizierbare Dispatcher-Map) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: `wp action-scheduler run --hooks=spreadconnect/process_webhook_event` nach Test-Webhook -> `wp_spreadconnect_webhook_log.processing_status` flippt von `pending` auf `success`) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `add_action`, Logging-Stub, `__()`; `WebhookLogRepo`-Methoden via Mockery-Alias-Mock; keine echte DB-I/O; FailedOpsRepo wird in dieser Slice **nicht** existieren — Logging-only Stub via `wc_get_logger()`) |

---

## Ziel

Schliesst die Webhook-Receive-Pipeline ab: der Async-Job `Webhook\ProcessWebhookEventJob::handle($logId)` laedt die in Slice 16 gespeicherte Log-Row, parsed das `payload`-JSON, dispatched per `eventType`-Prefix (`Order.*`/`Article.*`/`Shipment.*`) an Domain-Handler-Stubs und schreibt das Verarbeitungsergebnis (`success` | `error` + `processing_error`) zurueck. Domain-Handler werden in Slice 25 (`ArticleEventHandler`) und Slice 30 (`OrderEventHandler`) implementiert; in dieser Slice sind sie nur als Interface-Contract dokumentiert und durch Logging-Stubs vertreten.

---

## Acceptance Criteria

1) **GIVEN** `Bootstrap\Plugin::init()` wird beim Plugin-Load aufgerufen
   **WHEN** die Hook-Registrierung laeuft
   **THEN** registriert `Plugin::init()` `add_action('spreadconnect/process_webhook_event', [ProcessWebhookEventJob::class, 'handle'], 10, 1)` mit Priority `10` und genau **einem** Argument (positional `int $logId`, korrespondiert zu `as_enqueue_async_action(...,[$logId])` aus Slice 16 AC-10). Hook-Registrierung ist idempotent (mehrfacher `init()`-Call registriert den Listener nicht doppelt; Architecture-Referenz `architecture.md` Z. 553 + Slice-16-Vertrag).

2) **GIVEN** `ProcessWebhookEventJob::handle(int $logId)` wird mit einer existierenden Log-Row aufgerufen, deren `processing_status='pending'` ist und deren `payload` ein gueltiges JSON mit `eventType` und `data.entity` enthaelt
   **WHEN** der Job laeuft
   **THEN** ruft er `WebhookLogRepo::find($logId)` (Slice 16 AC-11), parsed `$row['payload']` via `json_decode(..., true)`, ermittelt den passenden Domain-Handler ueber das `eventType`-Prefix (Mapping siehe AC-4), invoziert ihn mit `$payload`-Array und ruft bei erfolgreicher Rueckkehr `WebhookLogRepo::updateProcessingStatus($logId, 'success', null)` auf (Architecture-Referenz Flow E Z. 453-456).

3) **GIVEN** `ProcessWebhookEventJob::handle($logId)` wird aufgerufen, aber `WebhookLogRepo::find($logId)` liefert `null` (Row geloescht oder log_id ungueltig)
   **WHEN** der Job laeuft
   **THEN** wirft er **keine** Exception (Action-Scheduler-Retry waere kontraproduktiv — Row existiert nicht mehr), loggt eine Warnung mit Source `spreadconnect-webhook-receiver` und Format `'process_webhook_event: log_id={N} not found'` und kehrt frueh zurueck (`return;`). Keine `updateProcessingStatus`-Call (es gibt keine Row).

4) **GIVEN** der Job hat eine Row geladen mit `eventType` aus den **7 bekannten Strings** (Architecture Z. 175: `Article.added`/`Article.updated`/`Article.removed`/`Order.cancelled`/`Order.processed`/`Order.needs-action`/`Shipment.sent`)
   **WHEN** der Dispatcher den Handler waehlt
   **THEN** mapped er per Prefix-Match exakt:
   - `Order.*` -> `OrderEventHandler::handle($payload)` (Stub in dieser Slice)
   - `Article.*` -> `ArticleEventHandler::handle($payload)` (Stub in dieser Slice)
   - `Shipment.*` -> `OrderEventHandler::handle($payload)` (Architecture Z. 381: `Shipment.sent` ist Order-Domain — gleiche Handler-Klasse)
   Prefix-Vergleich nutzt `str_starts_with($eventType, 'Order.')` (PHP 8 native). Match-Reihenfolge: `Order.` -> `Article.` -> `Shipment.` (deterministisch, da disjunkt).

5) **GIVEN** der Job hat eine Row geladen mit `eventType`, das **keinem** der 7 bekannten Strings entspricht (z. B. `Foo.bar` oder leerer String)
   **WHEN** der Dispatcher kein Prefix-Match findet
   **THEN** ruft er `WebhookLogRepo::updateProcessingStatus($logId, 'error', 'unknown_event_type')` (Architecture Validation Z. 467) und loggt eine Error-Zeile mit Source `spreadconnect-webhook-receiver` (Format `'unknown event_type={...} log_id={N}'`). Kein Re-Throw -> Action-Scheduler retried **nicht** (permanent error).

6) **GIVEN** ein Domain-Handler (Stub oder real) wirft eine `\SpreadconnectPod\Api\SpreadconnectClientError` oder eine generische `\Throwable` aus seinem `handle()`-Aufruf
   **WHEN** der Job die Exception faengt
   **THEN** ruft er `WebhookLogRepo::updateProcessingStatus($logId, 'error', $exception->getMessage())` (truncated auf max. 65535 Bytes — `processing_error` ist `TEXT`, Slice 04 AC-2), loggt das Exception-Stack mit Source `spreadconnect-webhook-receiver`, Level `error`, Context `['log_id' => $logId, 'event_type' => $eventType, 'exception' => $exception::class]`, und ruft danach den **FailedOps-Stub** `recordFailedOp($opType='handle_webhook', $payload, $exception)` auf — implementiert in dieser Slice als Logging-only-Stub mit `wc_get_logger()->error()` und Source `spreadconnect-failure` (Architecture Flow E Z. 457; finaler Repo kommt in Slice 37).

7) **GIVEN** ein Domain-Handler wirft eine `\SpreadconnectPod\Api\SpreadconnectTransientError` (5xx/Network)
   **WHEN** der Job die Exception faengt
   **THEN** ruft er `WebhookLogRepo::updateProcessingStatus($logId, 'error', $exception->getMessage())` UND **re-throwt** anschliessend dieselbe Exception, damit Action-Scheduler den Job nach 1m/5m/15m retried (Architecture Z. 553 + Slice-16-Constraint). Bei wiederholtem Erfolg im Retry ueberschreibt `success` den `error`-Status (Slice 16 AC-11 erlaubt mehrfaches Update). Kein FailedOps-Stub-Aufruf in der transient-Path (3-Retries-Regel ist Slice-37-Verantwortung).

8) **GIVEN** der Job hat eine Row geladen, deren `processing_status` bereits `success` oder `error` ist (Re-Run nach manueller Wiederholung)
   **WHEN** der Job startet
   **THEN** verarbeitet er die Row trotzdem normal — kein vorzeitiger Return, keine Idempotenz-Pruefung auf bereits-verarbeiteten Status. Begruendung: in dieser Slice ist Re-Verarbeitung ein gueltiger Pfad (z. B. Bulk-Resend in Slice 40); die Idempotency-Barriere liegt im **Domain-Handler** (Slice 25/30 — z. B. State-Machine-CAS).

9) **GIVEN** `$row['payload']` ist nicht-JSON-decodierbar (`json_decode` liefert `null`) ODER das decodierte Array enthaelt kein `eventType`-Key
   **WHEN** der Dispatcher die Pflichtfelder pruefen will
   **THEN** ruft er `WebhookLogRepo::updateProcessingStatus($logId, 'error', 'invalid_payload')` und loggt eine Warning mit Format `'invalid payload log_id={N} payload_preview={first 200 chars}'`. Kein Handler-Dispatch, kein Re-Throw (permanent error). Architecture-Referenz Validation Z. 466.

10) **GIVEN** die internen Domain-Handler-Stubs `OrderEventHandler::handle(array $payload): void` und `ArticleEventHandler::handle(array $payload): void` werden in dieser Slice angelegt
    **WHEN** der Dispatcher sie aufruft
    **THEN** loggen sie nur einen Info-Eintrag (Source `spreadconnect-webhook-receiver`, Format `'<handler>: stub invoked event_type={...}'`) und kehren ohne Mutation zurueck. Beide Klassen sind als `final class` mit `static handle(array): void` deklariert, leben in `includes/Webhook/` und werden in Slice 25 / Slice 30 vollstaendig ueberschrieben (siehe Integration Contract).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey fuer `add_action`, `__()`, `wc_get_logger` (returns Mockery-Mock mit `info`/`error`/`warning`-Methoden). `WebhookLogRepo::find` und `::updateProcessingStatus` werden als statische Aliase via Mockery `alias:SpreadconnectPod\Webhook\WebhookLogRepo` gemockt (PHPUnit 11 + Mockery `mockery_setUp()` Pattern). `OrderEventHandler`/`ArticleEventHandler` werden ebenfalls aliased — Tests verifizieren Aufruf-Argumente und koennen via `andThrow()` Exceptions injizieren. Keine echte DB-I/O.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-17-process-webhook-event-job.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class ProcessWebhookEventJobTest extends TestCase
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

    // AC-1: Plugin::init registriert add_action('spreadconnect/process_webhook_event', [Job, 'handle'], 10, 1)
    public function test_plugin_init_registers_process_webhook_event_action(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Mehrfacher init()-Call registriert den Listener nicht doppelt
    public function test_plugin_init_is_idempotent_for_action_registration(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Happy-Path: find -> dispatch -> updateProcessingStatus('success', null)
    public function test_handle_dispatches_and_marks_success(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: find() liefert null -> Warning-Log + frueher Return, keine update-Call, keine Exception
    public function test_handle_returns_early_on_missing_row(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Order.processed -> OrderEventHandler::handle
    public function test_dispatch_routes_order_prefix_to_order_event_handler(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: Article.added -> ArticleEventHandler::handle
    public function test_dispatch_routes_article_prefix_to_article_event_handler(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: Shipment.sent -> OrderEventHandler::handle (gleiche Klasse wie Order.*)
    public function test_dispatch_routes_shipment_prefix_to_order_event_handler(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Unknown eventType -> updateProcessingStatus('error', 'unknown_event_type'), kein Re-Throw
    public function test_unknown_event_type_writes_error_status(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Handler wirft generische Throwable -> updateProcessingStatus('error', exception-message) + FailedOps-Stub-Log
    public function test_handler_throwable_writes_error_and_invokes_failed_ops_stub(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-6: SpreadconnectClientError (4xx, permanent) -> error + FailedOps-Stub, kein Re-Throw
    public function test_client_error_is_permanent_and_invokes_failed_ops_stub(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: SpreadconnectTransientError -> updateProcessingStatus('error', ...) UND Re-Throw fuer AS-Retry
    public function test_transient_error_updates_status_and_rethrows(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: Transient-Path ruft FailedOps-Stub NICHT auf (Slice-37-Verantwortlichkeit)
    public function test_transient_error_does_not_invoke_failed_ops_stub(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Re-Run einer bereits 'success'-Row verarbeitet normal weiter (keine Early-Return)
    public function test_handle_does_not_short_circuit_on_already_processed_row(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Invalid JSON in payload -> updateProcessingStatus('error', 'invalid_payload')
    public function test_invalid_json_payload_writes_invalid_payload_error(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-9: Decoded payload ohne eventType-Key -> 'invalid_payload'-Error
    public function test_payload_without_event_type_writes_invalid_payload_error(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Stub-Handler loggen Info + return ohne Mutation, signature ist (array): void
    public function test_stub_handlers_log_info_and_return_void(): void
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
| `slice-16-event-id-hasher` | `Webhook\WebhookLogRepo::find(int $logId): ?array` | static method | Liefert assoc-array mit `id`/`event_type`/`event_id`/`payload`/`hmac_status`/`processing_status`/`processing_error`/`received_at` (Schema Slice 04). |
| `slice-16-event-id-hasher` | `Webhook\WebhookLogRepo::updateProcessingStatus(int, string, ?string): void` | static method | Setzt `processing_status` + optional `processing_error`. |
| `slice-16-event-id-hasher` | Action-Scheduler-Hook `spreadconnect/process_webhook_event` mit Argument `int $logId` | scheduled action | Slice-16 enqueued via `as_enqueue_async_action(..., [$logId], 'spreadconnect')`. Diese Slice registriert den passenden `add_action`-Listener. |
| `slice-07-http-client-base` | `\SpreadconnectPod\Api\SpreadconnectClientError`, `\SpreadconnectPod\Api\SpreadconnectTransientError` | Exception-Klassen | Werden in `try/catch` separat behandelt (permanent vs. transient). |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::init()` | Bootstrap-Method | Wird um den `add_action`-Hook fuer `process_webhook_event` erweitert. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Webhook\ProcessWebhookEventJob::handle` | static method | Action-Scheduler (via `add_action`-Listener) | `public static function handle(int $logId): void` |
| `SpreadconnectPod\Webhook\OrderEventHandler::handle` | static method (Stub) | This slice (dispatcher); **vollstaendig ueberschrieben in Slice 30** | `public static function handle(array $payload): void` — Slice 30 erweitert um State-Mutation, WC-Status-Updates, Tracking-Meta. |
| `SpreadconnectPod\Webhook\ArticleEventHandler::handle` | static method (Stub) | This slice (dispatcher); **vollstaendig ueberschrieben in Slice 25** | `public static function handle(array $payload): void` — Slice 25 erweitert um `sync_article`-Schedule und `handle_article_removed`-Dispatch. |
| Hook-Registrierung `spreadconnect/process_webhook_event` | Action-Hook | Slice 25 / Slice 30 (Domain-Handler werden via dieser Dispatch-Klasse erreicht) | `add_action('spreadconnect/process_webhook_event', [ProcessWebhookEventJob::class, 'handle'], 10, 1)` |
| Logging-only `recordFailedOp`-Stub (in `ProcessWebhookEventJob::recordFailedOp`) | private static method | Wird **vollstaendig ersetzt** in Slice 37 durch `Failure\FailedOpsRepo::record()` | `private static function recordFailedOp(string $opType, array $payload, \Throwable $error): void` |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Webhook/ProcessWebhookEventJob.php` — `final class SpreadconnectPod\Webhook\ProcessWebhookEventJob` mit `public static function handle(int $logId): void`. Internes `dispatch(string $eventType, array $payload): void` (Prefix-Match), `recordFailedOp(string, array, \Throwable): void` (Logging-only Stub). Catch-Blocks unterscheiden `SpreadconnectTransientError` (re-throw) von `SpreadconnectClientError` und generischer `\Throwable` (record-stub + return).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Webhook/OrderEventHandler.php` — `final class SpreadconnectPod\Webhook\OrderEventHandler` mit `public static function handle(array $payload): void`. **Stub**: nur Logging-Eintrag, kein Side-Effect. Klasse wird in Slice 30 vollstaendig ueberschrieben.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Webhook/ArticleEventHandler.php` — `final class SpreadconnectPod\Webhook\ArticleEventHandler` mit `public static function handle(array $payload): void`. **Stub**: nur Logging-Eintrag, kein Side-Effect. Klasse wird in Slice 25 vollstaendig ueberschrieben.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — `Plugin::init()` registriert `add_action('spreadconnect/process_webhook_event', [ProcessWebhookEventJob::class, 'handle'], 10, 1)`. Idempotent (Slice 02 AC-5 Pattern).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-17-process-webhook-event-job.php` wird vom Test-Writer-Agent erstellt. Keine Edits an `WebhookController` (Slice 15/16) oder `WebhookLogRepo` (Slice 16). Drei Stub-Klassen wegen Slice-Architektur (Dispatcher + zwei Domain-Handler-Stubs) — Stubs sind triviale Logging-Klassen ohne Domain-Logik (Slice-Regel max-3-produktive-Files-Ausnahme zugelassen, da Stubs Boilerplate-Setup fuer Slice 25/30 sind).

---

## Constraints

**Scope-Grenzen:**
- **Keine** echte Domain-Logik in `OrderEventHandler` / `ArticleEventHandler` — Stubs loggen + return. State-Machine-Mutation, WC-Status-Updates, `sync_article`-Schedule etc. sind Slice 25 (`Article.*`) und Slice 30 (`Order.*`/`Shipment.*`).
- **Kein** `Failure\FailedOpsRepo` — Slice 37. Diese Slice nutzt einen private-static `recordFailedOp(...)`-Stub, der ausschliesslich `wc_get_logger()->error('spreadconnect-failure', ...)` ruft. Stub-Methode wird in Slice 37 durch echten Repo-Call ersetzt (Edit derselben Datei).
- **Kein** Retry-Counter / Action-Scheduler-Failure-Listener — Slice 37 (`Failure\RetryPolicyListener`).
- **Keine** Schema-Validation auf Payload-Pflichtfeldern (`data.entity.id` etc.) jenseits des `eventType`-Existence-Checks — strukturelle Validation ist Slice-25/30-Verantwortung in den Domain-Handlern.
- **Kein** Body-Size-413-Reject vor JSON-Decode — Architecture Z. 503 listet diese Validation, fuer MVP wird sie hier nicht implementiert (Receiver-Slice-16 hat sie ebenfalls deferred; payload ist bereits in DB persistiert).
- **Kein** UI / Webhook-Log-Ansicht — Slice 41 liest `WebhookLogRepo` direkt.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in allen drei neuen Dateien.
- Alle drei Klassen sind `final class` mit ausschliesslich `public static`-Methoden — Job-Pattern (Architecture Z. 532, "single-method handler"). Keine Instanz-State, keine DI.
- **Prefix-Match per `str_starts_with`** (PHP 8 native) — keine regex, keine substr-Vergleiche. Reihenfolge: `Order.` -> `Article.` -> `Shipment.` (disjunkt).
- **`Shipment.*` mapped auf `OrderEventHandler`** — Architecture Z. 381 deklariert `OrderEventHandler` zustaendig fuer `Shipment.sent`. Kein separater `ShipmentEventHandler`.
- **Try/Catch-Reihenfolge in `handle()`**: `SpreadconnectTransientError` ZUERST (re-throw), `SpreadconnectClientError` ZWEITENS (permanent + record-stub), `\Throwable` als Fallback (permanent + record-stub). Spezifischere Exception-Klassen vor generischen.
- **`wc_get_logger()` (NICHT `error_log`)** — Architecture Z. 532 + Slice 42-Vorgabe. Sources: `spreadconnect-webhook-receiver` fuer Dispatcher-Logs, `spreadconnect-failure` fuer den FailedOps-Stub-Pfad (Architecture Z. 398).
- **`processing_error`-Truncation** auf 65535 Bytes (`TEXT`-Limit Slice 04 AC-2): `mb_substr($message, 0, 65535)` falls laenger; `mb_*` fuer UTF-8-Sicherheit.
- **Keine Idempotency-Pruefung auf `processing_status='success'`** im Job — Re-Run ist gueltig (siehe AC-8); Domain-Handler-CAS uebernimmt Race-Schutz.
- **`add_action`-Hook in `Plugin::init()` mit Priority 10, accepted_args 1**: Argument-Shape muss exakt zum `as_enqueue_async_action(..., [$logId])`-Call aus Slice 16 passen. Zweites accepted_args waere ein Bug (Action Scheduler reicht nur das array-element durch).
- **Stub-Klassen-Naming** matched 1:1 die Service-Map-Eintraege (Architecture Z. 381-382): `OrderEventHandler` und `ArticleEventHandler` — Slice 25/30 erweitern dieselbe Datei + Klasse, kein Re-Naming.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookLogRepo.php` (Slice 16) | **Reuse, no edit** — `find()` und `updateProcessingStatus()` werden konsumiert. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + Edits aus Slice 03/04/05/06) | **Edit** — `init()` wird um den `add_action`-Aufruf fuer `process_webhook_event` erweitert. Idempotenz-Pattern aus Slice 02 AC-5 wiederverwendet. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` + `SpreadconnectTransientError.php` (Slice 07) | **Reuse, no edit** — Catch-Blocks unterscheiden permanent vs. transient. |
| Tabelle `wp_spreadconnect_webhook_log` (Slice 04) | **Reuse** — Spalte `processing_status` enum + `processing_error TEXT` ist Voraussetzung. |
| Action-Scheduler-Hook `spreadconnect/process_webhook_event` (Slice 16 enqueue) | **Reuse** — diese Slice registriert den passenden Listener. |
| Architecture Flow E (Z. 432-458) + Service Map (Z. 380-382) + Validation Rules (Z. 466-467) + Action-Scheduler-Group-Convention (Z. 553) | **Single Source of Truth** fuer Dispatch-Logik, Handler-Mapping, Error-Codes. |

**Referenzen:**
- Architecture: `architecture.md` -> Flow E Webhook Receive (Z. 432-458, **insbesondere Z. 453-457** fuer Dispatcher + Status-Update + FailedOps-Eintrag); Service Map `Webhook\ProcessWebhookEventJob` (Z. 380); Service Map `Webhook\OrderEventHandler` + `Webhook\ArticleEventHandler` (Z. 381-382); Validation Rules `unknown_event_type` (Z. 467); WebhookEvent-DTO `eventType`-enum (Z. 175); Action-Definitions `spreadconnect/process_webhook_event` (Z. 553); Failed-Ops `op_type='handle_webhook'` (Z. 723 — Discovery-Quelle).
- Discovery: `discovery.md` -> Slice 3 "Webhook Receiver + Subscriptions" (Async Schedule `process_webhook_event`).
- Slim-Slices: `slices/slim-slices.md` -> Slice-17-Eintrag (Z. 328-336; Done-Signal "Job mit Stub-Handlers schreibt `success`; bei thrown Exception schreibt `error`+`processing_error`").
- Vorgaenger: `slices/slice-16-event-id-hasher.md` -> `WebhookLogRepo::find` + `::updateProcessingStatus` + Hook-Schedule mit `[$logId]`-Argument-Shape.
- Folge: `slim-slices.md` Slice-25 (`ArticleEventHandler` vollstaendig + `Catalog\ArticleRemovedJob`); Slice-30 (`OrderEventHandler` vollstaendig + `Order\FetchTrackingJob`); Slice-37 (`Failure\FailedOpsRepo` ersetzt den `recordFailedOp`-Stub).
