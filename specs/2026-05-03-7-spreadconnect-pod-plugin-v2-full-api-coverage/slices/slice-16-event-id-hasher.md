# Slice 16: Event-ID-Hasher + Webhook-Log-Insert + ACK 202

> **Slice 16 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-16-event-id-hasher` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-15-webhook-route"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `as_enqueue_async_action`, `current_time`, `wp_json_encode`, `__()`, `is_wp_error`; `$wpdb`-Stub mit `insert()`, `last_error`, `update()`, `get_var()`, `prepare()`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manueller QA: zwei identische `curl -X POST .../webhook` mit gueltigem `X-SPRD-SIGNATURE` -> erste 202+`[accepted]`, zweite 200+`duplicate`) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer AS-/WP-Funktionen; in-memory `$wpdb`-Spy mit konfigurierbarem `insert()`-Returnwert fuer Fresh- vs Duplicate-Path; keine echte DB-I/O) |

---

## Ziel

Vervollstaendigt den Webhook-Receive-Pfad: deterministischer `event_id`-Hash + idempotenter Insert in `wp_spreadconnect_webhook_log` (UNIQUE `event_id` als Idempotency-Barrier) + Async-Schedule des Domain-Dispatchers + 8-Sekunden-konformer ACK (`202` literal `[accepted]` bei Fresh, `200` literal `duplicate` bei Wiederholung). Keine Domain-Mutation im Request-Thread (Architecture-Constraint Z. 638).

---

## Acceptance Criteria

1) **GIVEN** `Webhook\EventIdHasher::compute($eventType, $entityId, $rawBody)` wird mit konkreten Inputs aufgerufen
   **WHEN** die Methode laeuft
   **THEN** liefert sie `hash('sha256', $eventType . ':' . $entityId . ':' . hash('sha256', $rawBody))` als 64-Zeichen-Hex-String. Inner-Hash und Outer-Hash beide `sha256`, beide Hex (default), Trenner ist literal `':'`. Bei identischem Input ist Output stabil; bei einem geaenderten Byte im `$rawBody` aendert sich der Output (Architecture-Referenz: `architecture.md` Z. 643).

2) **GIVEN** `EventIdHasher::compute` wird mit leerem `$entityId` (Webhook-Payload ohne `data.entity.id`) aufgerufen
   **WHEN** die Methode laeuft
   **THEN** wirft sie `\InvalidArgumentException` mit Code `'spreadconnect_event_id_missing_entity'`. `$eventType` darf ebenfalls nicht leer sein und triggert dieselbe Exception. `$rawBody` darf empty-string sein (Edge-Case: leere Payload mit gueltigem HMAC) -> Hash wird normal berechnet.

3) **GIVEN** ein WP REST-Request mit gueltiger HMAC (Slice 15 hat `authorize()` -> `true` geliefert) und JSON-Body `{eventType:"Order.processed", data:{entity:{id:"sc-12345"}}}`
   **WHEN** `WebhookController::handle($request)` aus Slice 15 ueberschrieben durch Slice 16 laeuft
   **THEN** parsed der Handler den raw body via `json_decode($request->get_body(), true)`, extrahiert `eventType` (Top-Level) und `entityId` (`$payload['data']['entity']['id']`), berechnet `event_id` via `EventIdHasher::compute(...)` und ruft `WebhookLogRepo::insertOrIgnore($row)` mit dem zusammengebauten Row-Array (siehe AC-4). Architecture-Referenz: `architecture.md` Flow E (Z. 444) + WebhookEvent-DTO-Shape (Z. 175).

4) **GIVEN** `WebhookLogRepo::insertOrIgnore(array $row): array{status:'inserted'|'duplicate', log_id:int}` wird mit Row-Daten aufgerufen
   **WHEN** die Methode laeuft
   **THEN** baut sie das `$wpdb->insert($wpdb->prefix.'spreadconnect_webhook_log', $row)`-Statement mit Spalten `event_type`, `event_id`, `related_entity_type`, `related_entity_id`, `payload` (re-encoded JSON via `wp_json_encode`), `hmac_status='valid'`, `processing_status='pending'`, `received_at=current_time('mysql', true)` (UTC). Schema-Referenz: `architecture.md` -> Database Schema -> `wp_spreadconnect_webhook_log` (Z. 212-231; Slice 04 Source of Truth).

5) **GIVEN** `WebhookLogRepo::insertOrIgnore` und `$wpdb->insert()` liefert `1` (genau eine Zeile inserted, kein UNIQUE-Konflikt)
   **WHEN** der Insert erfolgreich war
   **THEN** liefert die Methode `['status' => 'inserted', 'log_id' => (int)$wpdb->insert_id]`. `$wpdb->last_error` ist leer. `WebhookController::handle` schedult dann `as_enqueue_async_action('spreadconnect/process_webhook_event', [$log_id], 'spreadconnect')` (Group: `'spreadconnect'`, Architecture-Referenz Z. 553) und liefert `new WP_REST_Response('[accepted]', 202, ['Content-Type' => 'text/plain; charset=utf-8'])` zurueck. Body ist literal-string `[accepted]` (KEIN JSON, keine Quotes).

6) **GIVEN** `WebhookLogRepo::insertOrIgnore` und `$wpdb->insert()` liefert `false` mit `$wpdb->last_error` enthaelt `Duplicate entry` (UNIQUE-Constraint `uniq_event_id` verletzt)
   **WHEN** der Insert kollidiert
   **THEN** ruft die Methode `$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}spreadconnect_webhook_log WHERE event_id = %s LIMIT 1", $row['event_id']))` zur Reverse-Lookup-Aufloesung der existierenden `log_id`, ruft anschliessend `$wpdb->update($table, ['processing_status' => 'duplicate'], ['id' => $log_id])` und liefert `['status' => 'duplicate', 'log_id' => (int)$log_id]` (Architecture-Referenz Flow E Z. 446-448).

7) **GIVEN** `WebhookController::handle` empfaengt `['status' => 'duplicate', 'log_id' => N]` von `insertOrIgnore`
   **WHEN** der Handler die Antwort baut
   **THEN** ruft er `as_enqueue_async_action(...)` **NICHT** auf (keine Re-Verarbeitung) und liefert `new WP_REST_Response('duplicate', 200, ['Content-Type' => 'text/plain; charset=utf-8'])`. Body ist literal-string `duplicate`. Status ist 200 (NICHT 202 — SC retry-Verhalten dokumentiert in Architecture Z. 448).

8) **GIVEN** identischer raw body wird zwei Mal an `WebhookController::handle` geliefert (gleicher `eventType`, gleiche `entity.id`, byte-identisches Body)
   **WHEN** beide Requests durchlaufen
   **THEN** liefert der **erste** Request `202` + `[accepted]` + 1x `as_enqueue_async_action`-Call. Der **zweite** Request liefert `200` + `duplicate` + 0x `as_enqueue_async_action`-Calls. In der DB existiert genau **eine** Zeile mit dem gemeinsamen `event_id`. Slim-Slice-Done-Signal (`slim-slices.md` Z. 322).

9) **GIVEN** `json_decode($request->get_body(), true)` liefert `null` (Body ist nicht-JSON oder `eventType`/`data.entity.id` fehlt)
   **WHEN** der Handler die Pflichtfelder pruefen will
   **THEN** liefert er `new WP_REST_Response('[accepted]', 202, ...)` und INSERTs trotzdem die Row mit `processing_status='pending'`, `event_type='_unknown'`, `related_entity_type='unknown'`, `related_entity_id='_'` und `event_id = sha256('_unknown:_:'.sha256($rawBody))`. Begruendung: ACK-≤-8s-Constraint (Z. 638) verbietet Reject im Receiver; Schema-Validation ist Job-Verantwortung (Slice 17, `processing_status='error'` mit `processing_error='unknown_event_type'`, Architecture Z. 467).

10) **GIVEN** der Handler hat erfolgreich Insert+Schedule abgeschlossen
    **WHEN** die schedule-Action `spreadconnect/process_webhook_event` von Action-Scheduler dispatched wird (in Slice 17)
    **THEN** wird der Hook mit dem Argument `$log_id` (single positional int) aufgerufen — exakt das Argument-Shape, das Slice 17 `ProcessWebhookEventJob::handle($log_id)` erwartet. Group ist `'spreadconnect'` fuer alle AS-Actions (Architecture-Referenz Z. 657 — default group naming convention).

11) **GIVEN** `WebhookLogRepo` wird in spaeteren Slices (17, 41) konsumiert
    **WHEN** Slice 17 das Row-Loading benoetigt
    **THEN** stellt `WebhookLogRepo::find(int $log_id): ?array` bereit (SELECT * WHERE id = %d). `WebhookLogRepo::updateProcessingStatus(int $log_id, string $status, ?string $error = null): void` UPDATEt `processing_status` und (optional) `processing_error` (Architecture-Referenz Z. 456-457). Beide Methoden sind in dieser Slice **vollstaendig** implementiert (kein Stub) — werden hier aber **nicht** aufgerufen.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey fuer `as_enqueue_async_action`, `current_time`, `wp_json_encode`, `__()`, `is_wp_error`. `$wpdb` wird als Mockery-Mock konfiguriert mit `insert()`-Returnvalue (1 = Fresh, false = Duplicate), `last_error`-Property (`'' | 'Duplicate entry ... for key uniq_event_id'`), `insert_id`, `get_var()`, `update()`, `prepare()`. `WebhookSecretManager::peek()` aus Slice 14 wird re-genutzt (HMAC-Pfad: gueltige Signatur fuer Slice-15-`authorize` -> true). `WP_REST_Request`/`WP_REST_Response`-Stubs aus `tests/bootstrap/bootstrap.php`.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-16-event-id-hasher.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class EventIdHasherWebhookLogTest extends TestCase
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

    // AC-1: EventIdHasher::compute liefert sha256(eventType + ':' + entityId + ':' + sha256(rawBody)) als 64-Hex
    public function test_compute_returns_deterministic_sha256_hex_with_inner_body_hash(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Identische Inputs -> identischer Hash (Determinismus)
    public function test_compute_is_stable_for_identical_inputs(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Ein-Byte-Aenderung im rawBody -> abweichender Hash (Avalanche)
    public function test_compute_changes_when_raw_body_changes(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Leere eventType ODER entityId -> InvalidArgumentException
    public function test_compute_throws_on_missing_event_type_or_entity_id(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Leerer rawBody ist akzeptiert (Hash wird trotzdem berechnet)
    public function test_compute_accepts_empty_raw_body(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: handle() parsed JSON, extrahiert eventType + data.entity.id, ruft EventIdHasher + Repo
    public function test_handle_extracts_event_type_and_entity_id_and_calls_repo(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: insertOrIgnore baut Row mit allen Pflicht-Spalten (event_type, event_id, hmac_status='valid', processing_status='pending', received_at UTC)
    public function test_insert_or_ignore_builds_row_with_all_required_columns(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Fresh-Insert (wpdb->insert returns 1) -> ['status'=>'inserted', 'log_id'=>insert_id]
    public function test_insert_or_ignore_returns_inserted_on_fresh_row(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: Fresh-Path: handle() schedult as_enqueue_async_action mit log_id + Group 'spreadconnect'
    public function test_handle_schedules_async_action_on_fresh_insert(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: Fresh-Path: handle() liefert WP_REST_Response(202, '[accepted]') als literal-string
    public function test_handle_returns_202_with_literal_accepted_body(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Duplicate-Insert (wpdb->insert returns false + last_error 'Duplicate entry') -> Reverse-Lookup + UPDATE processing_status='duplicate'
    public function test_insert_or_ignore_handles_duplicate_via_reverse_lookup_and_update(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Duplicate-Path: handle() ruft as_enqueue_async_action NICHT auf
    public function test_handle_does_not_schedule_action_on_duplicate(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: Duplicate-Path: handle() liefert WP_REST_Response(200, 'duplicate') als literal-string
    public function test_handle_returns_200_with_literal_duplicate_body(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Identischer raw body 2x -> 1x 202+[accepted]+1 schedule, 1x 200+duplicate+0 schedules
    public function test_two_identical_requests_yield_one_accepted_one_duplicate(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: JSON-Decode-Failure / fehlende Pflichtfelder -> trotzdem 202 + INSERT mit '_unknown' Marker (Job validiert spaeter)
    public function test_handle_inserts_unknown_marker_on_invalid_json_and_still_returns_202(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Schedule-Argument-Shape: as_enqueue_async_action erhaelt [log_id] als positional-array
    public function test_async_action_scheduled_with_log_id_positional_argument(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: WebhookLogRepo::find(log_id) liefert SELECT *-Row als assoc-array (oder null)
    public function test_repo_find_returns_row_array_or_null(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    // AC-11: WebhookLogRepo::updateProcessingStatus setzt processing_status + optional processing_error
    public function test_repo_update_processing_status_writes_status_and_error(): void
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
| `slice-15-webhook-route` | `Webhook\WebhookController::handle(WP_REST_Request): WP_REST_Response` | static method | Stub-Body wird von dieser Slice **vollstaendig ersetzt** durch Insert+Schedule+ACK-Logik. `register()`/`authorize()` aus Slice 15 bleiben unveraendert. |
| `slice-15-webhook-route` | HMAC-Verifier-Pre-Gate via `permission_callback` | guarantee | Bei Eintreten in `handle()` ist HMAC bereits valid (Slice 15 AC-2) — diese Slice setzt `hmac_status='valid'` direkt. |
| `slice-04-schema-dbdelta` | Tabelle `{$wpdb->prefix}spreadconnect_webhook_log` | DB-Tabelle | Mit UNIQUE `uniq_event_id (event_id)` als Idempotency-Barrier (Slice 04 AC-2). Spalten-Layout aus Slice 04 ist Source of Truth. |
| WordPress + WC | `as_enqueue_async_action`, `$wpdb`, `wp_json_encode`, `current_time` | WP/WC-API | Action Scheduler ist mit WC ≥ 3.0 gebundelt (Architecture Z. 657). |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Webhook\EventIdHasher::compute` | static method | `Webhook\WebhookController::handle` (this slice); testbar isoliert | `public static function compute(string $eventType, string $entityId, string $rawBody): string` |
| `SpreadconnectPod\Webhook\WebhookLogRepo::insertOrIgnore` | static method | `Webhook\WebhookController::handle` (this slice) | `public static function insertOrIgnore(array $row): array{status:'inserted'\|'duplicate', log_id:int}` |
| `SpreadconnectPod\Webhook\WebhookLogRepo::find` | static method | `slice-17-process-webhook-event-job` (Job laedt Row), `slice-41-webhook-log-ui` | `public static function find(int $logId): ?array` |
| `SpreadconnectPod\Webhook\WebhookLogRepo::updateProcessingStatus` | static method | `slice-17-process-webhook-event-job` (success/error-Update) | `public static function updateProcessingStatus(int $logId, string $status, ?string $error = null): void` |
| Action-Scheduler-Hook `spreadconnect/process_webhook_event` | scheduled action | `slice-17-process-webhook-event-job` (registriert `add_action`-Listener) | Argument: `int $logId` (positional) |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Webhook/EventIdHasher.php` — `final class SpreadconnectPod\Webhook\EventIdHasher` mit `public static function compute(string, string, string): string`. Pure Domain-Funktion (nur `hash()`-Calls + Argument-Validation), keine WP-/DB-Abhaengigkeit.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookLogRepo.php` — `final class SpreadconnectPod\Webhook\WebhookLogRepo` mit `insertOrIgnore(array): array`, `find(int): ?array`, `updateProcessingStatus(int, string, ?string): void`. Statisch, raw `$wpdb` (Architecture-Pattern Z. 537: Repository fuer Custom-Tables).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookController.php` — `handle()` aus Slice 15 vollstaendig ueberschrieben mit: JSON-Parse, `EventIdHasher::compute(...)`, `WebhookLogRepo::insertOrIgnore(...)`, branch auf `$result['status']` -> Schedule + 202 ODER 200-`duplicate`. `register()`/`authorize()`/`logRejected()` aus Slice 15 bleiben unveraendert.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-16-event-id-hasher.php` wird vom Test-Writer-Agent erstellt. Keine Edits an `Webhook\WebhookSignatureVerifier` (Slice 15) — Signatur-Verifikation bleibt im `permission_callback`. Keine Edits an `Bootstrap\Plugin` — Hook-Registrierung fuer `process_webhook_event` ist Slice-17-Verantwortlichkeit.

---

## Constraints

**Scope-Grenzen:**
- **Kein** `add_action('spreadconnect/process_webhook_event', ...)` — Slice 17 (`ProcessWebhookEventJob`).
- **Kein** Domain-Dispatch (`OrderEventHandler` / `ArticleEventHandler`) — Slices 25/30 via Slice 17.
- **Kein** Update auf `processing_status='success'`/`'error'` im Receiver-Pfad — das macht Slice 17.
- **Kein** Schema-Validation (`eventType` enum-check) im Receiver — Slice 17 schreibt `processing_error='unknown_event_type'` (Architecture Z. 467).
- **Kein** 1-MB-Body-Size-Reject (`413`) — Architecture Z. 503 listet diese Validation, fuer MVP wird sie in Slice 17 (vor JSON-Decode des Jobs) durchgefuehrt; Receiver bleibt minimal.
- **Kein** UI fuer `webhook_log` — Slice 41.
- **Kein** Failed-Ops-Insert (`FailedOpsRepo::record`) — Slice 37.
- **Kein** Retry-Counter / Job-Failure-Handling — Action-Scheduler-Default 1m/5m/15m (Architecture Z. 553) wird ohne Override genutzt.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in beiden neuen Dateien.
- `EventIdHasher` als `final class` mit ausschliesslich `static` Methoden — Pure Function. Keine WP-Abhaengigkeit.
- `WebhookLogRepo` als `final class` mit `static` Methoden — Repository-Pattern (Architecture Z. 537). Direkter `$wpdb`-Access via `global $wpdb;` (kein DI; konsistent mit Slice 04).
- **`hash('sha256', ...)` Hex-Output** — default `hash()` liefert lowercase-Hex; explizit kein vierter Argument `true` (raw binary), denn `event_id`-Spalte ist `CHAR(64)` Hex-String (Slice 04 + Architecture Z. 264).
- **`current_time('mysql', true)`** — UTC-Modus (`$gmt = true`), konsistent mit Spalten-Doku in Architecture (DATETIME-Spalten in WP-Plugins sind UTC by convention; `idx_received_at` macht Sortierung deterministisch).
- **`wp_json_encode` (NICHT `json_encode`)** — WP-eigenes Wrapper, korrekte Unicode-Behandlung.
- **`payload`-Spalte enthaelt re-encoded JSON**, nicht der raw body. Begruendung: Architecture Z. 221 Anmerkung "JSON; raw body re-encoded for storage". Vorteil: leichtere Lesbarkeit in UI; Trade-off: HMAC ist NICHT mehr re-verifizierbar nach Storage (gewollt — verify ist Receiver-Job).
- **Group `'spreadconnect'`** in `as_enqueue_async_action` — vereinheitlicht Action-Listing in WP-Admin -> Tools -> Scheduled Actions (Architecture Z. 657).
- **Argument-Shape `[$log_id]`** als positional-array (zweites Param von `as_enqueue_async_action($hook, $args)`). Slice-17-Listener registriert `add_action('spreadconnect/process_webhook_event', $cb, 10, 1)` mit einem Param.
- **Body-Type `text/plain`** (KEIN `application/json`) — `[accepted]` und `duplicate` sind literal-strings, kein JSON. SC-Doku bestaetigt diesen literal-string-Body (Architecture Z. 85, Z. 450).
- **Reverse-Lookup nach Duplicate** via `prepare(... %s ...)` — Defense-in-Depth gegen SQL-Injection trotz dass `event_id` aus eigener `compute()` stammt (Konsistenz mit `$wpdb->prepare`-Pflicht im Plugin).
- **`$wpdb->last_error`-Match auf `'Duplicate entry'`** — MySQL-Errno 1062. Alternativ liesse sich auch `$wpdb->insert()` mit eigenem `INSERT IGNORE` auf raw-`query()` umstellen, aber `insert()`-API ist konsistenter mit dem Rest des Plugins (Slice-04-Pattern).
- **Idempotenz im Slice-15-Bootstrap-Hook**: `add_action('rest_api_init', ...)` wurde in Slice 15 AC-9 bereits idempotent registriert — diese Slice fasst diesen Hook nicht an.

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookController.php` (Slice 15) | **Edit** — `handle()`-Methode wird vollstaendig ueberschrieben. `register()`, `authorize()`, `logRejected()` bleiben byte-identisch. |
| `wordpress/plugins/spreadconnect-pod/includes/Webhook/WebhookSignatureVerifier.php` (Slice 15) | **No-touch** — Verify-Logik bleibt im `permission_callback`. |
| Tabelle `wp_spreadconnect_webhook_log` (Slice 04) | **Reuse** — Spalten + UNIQUE-Index `uniq_event_id` sind Voraussetzung; Slice 04 ist Source of Truth fuer Layout. |
| `Subscription\WebhookSecretManager::peek` (Slice 14) | **Indirect-reuse** — wird ueber Slice-15-`authorize` adressiert, nicht direkt von dieser Slice. |
| `wordpress/plugins/spreadconnect-pod/tests/bootstrap/bootstrap.php` (Slice 02) | **Reuse** — `WP_REST_Request`/`WP_REST_Response`-Stubs + `$wpdb`-Mock-Pattern werden vom Test-Writer-Agent erweitert (idempotent), kein Bootstrap-Edit in dieser Slice. |
| Architecture Flow E (Z. 432-450) + Service Map `EventIdHasher` (Z. 379) + Schema `wp_spreadconnect_webhook_log` (Z. 212-231) + ACK Contract (Z. 85, 638) | **Single Source of Truth** fuer Hash-Formel, Insert-Pfad, ACK-Body-Strings. |

**Referenzen:**
- Architecture: `architecture.md` -> Flow E Webhook Receive (Z. 432-458, **insbesondere Z. 444-450** fuer Hash + Insert + Schedule + ACK); Service Map `Webhook\EventIdHasher` (Z. 379); Database Schema `wp_spreadconnect_webhook_log` (Z. 212-231); ACK Contract `[accepted]` literal (Z. 85, 638, 678); Pseudo-Event-ID-Recipe (Z. 643); Action `spreadconnect/process_webhook_event` (Z. 553); Idempotency-Done-Signal (Z. 681); Validation Rules (Z. 466-467); Group-Convention (Z. 657).
- Discovery: `discovery.md` -> Slice 3 "Webhook Receiver + Subscriptions" (Webhook-Endpoint-Spezifikation, Idempotency, Async-Schedule).
- Slim-Slices: `slices/slim-slices.md` -> Slice-16-Eintrag (Z. 316-324; Done-Signal "identischer raw body 2x -> 1x 202+`[accepted]`, 1x 200+`duplicate`; Action `spreadconnect/process_webhook_event` wird mit `log_id` enqueued").
- Vorgaenger: `slices/slice-15-webhook-route.md` -> `WebhookController::handle` Stub, der hier ueberschrieben wird; `WebhookSignatureVerifier` aus Slice 15 garantiert HMAC-Pre-Gate.
- Folge: `slices/slim-slices.md` Slice-17 (`ProcessWebhookEventJob::handle($log_id)` registriert AS-Hook + dispatched Domain-Handler); Slice-25 (`ArticleEventHandler`); Slice-30 (`OrderEventHandler`); Slice-41 (Webhook-Log-UI liest `WebhookLogRepo`).
