# Slice 37: Failed-Ops-Repo + AS-Retry-Policy + Permanent-Record

> **Slice 37 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-37-failed-ops-repo` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-28-order-submit-job", "slice-23-sync-article-job"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WC 10.5 HPOS + Action Scheduler + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA optional via Tools -> Scheduled Actions) |
| **Health Endpoint** | `n/a` (Repo + AS-Hook-Listener, keine eigenen REST-Routes) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey/Mockery fuer `$wpdb`, `\ActionScheduler_Action`, `\ActionScheduler::store()`, `as_get_scheduled_actions`, `current_time`, `wc_get_logger`; SpreadconnectClient nicht direkt verwendet — Listener wirkt nur auf bereits gefangene Action-Scheduler-Failures). |

---

## Ziel

Schliesst Discovery Slice 9 "Failure-Recovery" (Auto-Retry + DLQ-Eintrag): `Failure\FailedOpsRepo` kapselt die CRUD auf `wp_spreadconnect_failed_ops` (Schema aus Slice 04). `Failure\RetryPolicyListener` haengt sich an den Action-Scheduler-Hook `action_scheduler_failed_action` und entscheidet anhand der geworfenen Exception-Klasse + des AS-Retry-Counters, ob eine Failed-Ops-Row geschrieben wird (3x Transient -> Row; Permanent direkt -> Row; sonst durchwinken). Damit wird die in Slice 23/28 hinterlegte Logging-Stub-Konvention (`failed_op_pending_record`) durch echte DB-Insertions ersetzt — ohne Aenderung an den bereits geschriebenen Job-Handlern.

---

## Acceptance Criteria

1) **AC-Repo-Record-Insert — `FailedOpsRepo::record()` schreibt Row mit allen Pflichtfeldern**
   **GIVEN** ein installiertes Plugin (Schema aus Slice 04 vorhanden) und ein Argument-Array `['op_type' => 'create_order', 'related_entity_type' => 'order', 'related_entity_id' => '7', 'payload' => ['order_id' => 7], 'error_message' => 'HTTP 400 invalid SKU mapping', 'error_code' => 'http_4xx', 'retries_used' => 0]`
   **WHEN** `FailedOpsRepo::record($args)` aufgerufen wird
   **THEN** wird genau ein `INSERT INTO {$wpdb->prefix}spreadconnect_failed_ops`-Statement abgesetzt mit Feldern: `op_type='create_order'`, `related_entity_type='order'`, `related_entity_id='7'`, `payload=json_encode(['order_id' => 7])` (JSON-string), `error_message='HTTP 400 invalid SKU mapping'`, `error_code='http_4xx'`, `retries_used=0`, `created_at=current_time('mysql', true)` (UTC), `last_attempt_at=current_time('mysql', true)`, `state='unresolved'`. Methode liefert die per `$wpdb->insert_id` zurueckgegebene `int` (>0).

2) **AC-Repo-Record-Validation — Pflichtfelder rejectet**
   **GIVEN** ein Argument-Array, das eines der Pflichtfelder fehlt (`op_type`, `related_entity_type`, `related_entity_id`, `payload`)
   **WHEN** `FailedOpsRepo::record($args)` aufgerufen wird
   **THEN** wirft die Methode `\InvalidArgumentException` mit einer Message, die das fehlende Feld benennt; **kein** `$wpdb->insert()`-Call wird abgesetzt.

3) **AC-Repo-Find-By-Id — `findById(int $id): ?array`**
   **GIVEN** eine vorhandene Row mit `id=42`
   **WHEN** `FailedOpsRepo::findById(42)` aufgerufen wird
   **THEN** liefert die Methode ein assoc-Array mit allen 11 Spalten (architecture.md Z. 191-205). `payload` wird via `json_decode(..., true)` zu einem PHP-Array de-serialisiert. Bei nicht-existenter ID liefert die Methode `null` (kein Throw).

4) **AC-Repo-Update-State — `markResolved(int $id)` / `markDismissed(int $id)`**
   **GIVEN** eine Row mit `state='unresolved'`
   **WHEN** `FailedOpsRepo::markResolved(42)` bzw. `markDismissed(42)` aufgerufen wird
   **THEN** wird via `$wpdb->update()` `state='resolved'` bzw. `state='dismissed'` geschrieben (WHERE `id=42`); Methode liefert `true` bei `affected_rows >= 1`, `false` sonst. Beide Methoden sind idempotent (zweiter Aufruf ist no-op-affected_rows=0).

5) **AC-Repo-Find-By-Entity — Per-Order/Article-Lookup ueber Composite-Index**
   **GIVEN** zwei Rows fuer dieselbe Entity (`related_entity_type='order'`, `related_entity_id='7'`) — eine `unresolved`, eine `resolved`
   **WHEN** `FailedOpsRepo::findByEntity('order', '7', 'unresolved')` aufgerufen wird
   **THEN** liefert die Methode genau die `unresolved`-Row als single-Element-Array; ohne dritten Parameter werden beide Rows geliefert (sortiert `created_at DESC`). Die Query MUSS den Index `idx_related_entity` (architecture.md Z. 209) nutzen koennen — d. h. WHERE-Reihenfolge `related_entity_type, related_entity_id, [state]`.

6) **AC-Listener-Permanent-On-First-Fail — `SpreadconnectClientError` -> direkt Row, kein Retry**
   **GIVEN** Action Scheduler markiert eine Action `spreadconnect/create_order` mit `args=['order_id' => 7]` als `failed`. Die Action-Klasse hat `last_exception_class = 'SpreadconnectPod\\Api\\SpreadconnectClientError'` und `last_exception_message = 'HTTP 400 invalid SKU mapping'`. Action-Retry-Counter (laut AS-Logs) = 0 (erster und einziger Lauf).
   **WHEN** der Hook `action_scheduler_failed_action` mit `($action_id)` feuert
   **THEN** ruft `RetryPolicyListener::on_action_failed($action_id)`: (a) ermittelt via `\ActionScheduler::store()->fetch_action($action_id)` Hook-Name + Args; (b) ermittelt via Logs (`\ActionScheduler::logger()->get_logs($action_id)`) Exception-Klasse + Message + Code; (c) erkennt `SpreadconnectClientError` als **permanent** und ruft `FailedOpsRepo::record()` mit `op_type='create_order'`, `related_entity_type='order'`, `related_entity_id='7'`, `payload=['order_id' => 7]`, `error_message=$message`, `error_code='http_4xx'`, `retries_used=0`, `state='unresolved'` auf. **Kein** `as_enqueue_async_action()`-Re-Schedule.

7) **AC-Listener-Transient-Below-Threshold — Bei <3 Retries: kein FailedOps-Insert**
   **GIVEN** Action Scheduler markiert `spreadconnect/create_order` als `failed` mit `last_exception_class = 'SpreadconnectPod\\Api\\SpreadconnectTransientError'`, und der Listener ermittelt via `as_get_scheduled_actions` einen Retry-Counter von **2** (d. h. 2 vorherige `failed`-Logs fuer dieselbe `(hook, args)`-Kombination existieren bereits).
   **WHEN** der Hook `action_scheduler_failed_action` feuert
   **THEN** wird **kein** `FailedOpsRepo::record()` aufgerufen. Action Scheduler-eigener Retry-Mechanismus (1m/5m/15m, architecture.md Z. 549) wirkt unbeobachtet weiter.

8) **AC-Listener-Transient-At-Threshold — Bei 3 Retries: Row + state='unresolved'**
   **GIVEN** Action Scheduler markiert `spreadconnect/create_order` mit `args=['order_id' => 7]` als `failed`; Exception-Klasse `SpreadconnectTransientError`; Retry-Counter = **3** (3 vorherige `failed`-Versuche).
   **WHEN** der Hook `action_scheduler_failed_action` feuert
   **THEN** ruft der Listener `FailedOpsRepo::record()` mit `op_type='create_order'`, `related_entity_id='7'`, `payload=['order_id' => 7]`, `error_message=<aus Logs>`, `error_code='http_5xx'` (oder Default `transient_error` falls Code-Mapping nicht eindeutig), `retries_used=3`, `state='unresolved'` auf.

9) **AC-Listener-Hook-Mapping — Op-Type-Erkennung deckt alle 9 Plugin-Hooks ab**
   **GIVEN** der Listener wird mit Action-Hook-Name X aufgerufen, X in der Menge der 9 Plugin-AS-Hooks (architecture.md Z. 542-556): `spreadconnect/sync_catalog`, `spreadconnect/sync_article`, `spreadconnect/handle_article_removed`, `spreadconnect/create_order`, `spreadconnect/confirm_order`, `spreadconnect/cancel_order_mirror`, `spreadconnect/fetch_tracking`, `spreadconnect/process_webhook_event`, `spreadconnect/scheduled_stock_sync`
   **WHEN** der Listener `op_type` aus dem Hook-Namen ableitet (Regel: Suffix nach `spreadconnect/`)
   **THEN** entspricht `op_type` exakt: `sync_catalog`, `sync_article`, `handle_article_removed`, `create_order`, `confirm_order`, `cancel_order_mirror`, `fetch_tracking`, `handle_webhook` (Mapping fuer `process_webhook_event` per architecture.md Z. 457), `scheduled_stock_sync`. **Fremde Hooks** (z. B. `woocommerce/some_other_hook`) werden ignoriert — `early return`, kein Repo-Insert.

10) **AC-Listener-Entity-Extraction — `related_entity_type`/`related_entity_id` aus Args**
    **GIVEN** verschiedene Hook+Args-Kombinationen
    **WHEN** der Listener das Entity-Tupel ableitet
    **THEN** gilt das Mapping (Args-Key zu Entity-Tupel):
    | Hook | Args-Key | `related_entity_type` | `related_entity_id` |
    | `spreadconnect/create_order` | `order_id` | `'order'` | `(string) $args['order_id']` |
    | `spreadconnect/confirm_order` | `order_id` | `'order'` | `(string) $args['order_id']` |
    | `spreadconnect/cancel_order_mirror` | `order_id` | `'order'` | `(string) $args['order_id']` |
    | `spreadconnect/fetch_tracking` | `order_id` | `'order'` | `(string) $args['order_id']` |
    | `spreadconnect/sync_article` | `article_id` | `'article'` | `(string) $args['article_id']` |
    | `spreadconnect/handle_article_removed` | `article_id` | `'article'` | `(string) $args['article_id']` |
    | `spreadconnect/sync_catalog` | (kein Entity) | `'system'` | `(string) ($args['run_id'] ?? '0')` |
    | `spreadconnect/process_webhook_event` | `log_id` | `'webhook'` | `(string) $args['log_id']` |
    | `spreadconnect/scheduled_stock_sync` | (kein Entity) | `'system'` | `'0'` |
    Bei fehlendem Args-Key wird `'unknown'`/`'0'` als Defensive-Default verwendet (Listener wirft **nicht** — Failed-Ops-Insert ist Best-Effort).

11) **AC-Listener-Hook-Wiring — Bootstrap registriert `action_scheduler_failed_action`**
    **GIVEN** Plugin-Boot
    **WHEN** `Bootstrap\Plugin::init()` durchlaeuft
    **THEN** ist `add_action('action_scheduler_failed_action', [<RetryPolicyListener-Instance>, 'on_action_failed'], 10, 1)` registriert; ein zweiter `init()`-Aufruf fuegt **nicht** doppelt hinzu (`has_action()`-Identitaet).

12) **AC-Listener-Idempotency — Doppel-Hook fuer dieselbe Action schreibt nur eine Row**
    **GIVEN** der Hook `action_scheduler_failed_action` feuert zweimal fuer dieselbe `$action_id` (z. B. AS-internes Retry-Bookkeeping); die erste Invocation hat bereits eine Row in `wp_spreadconnect_failed_ops` mit `op_type='create_order'`, `related_entity_id='7'`, `state='unresolved'` geschrieben.
    **WHEN** der Listener das zweite Mal aufgerufen wird
    **THEN** prueft der Listener via `FailedOpsRepo::findByEntity($type, $id, 'unresolved')` ob fuer dieselbe `(op_type, related_entity_type, related_entity_id, state='unresolved')`-Kombination innerhalb der letzten 5 Minuten bereits eine Row existiert; wenn ja: **kein** zweiter Insert (early return + Debug-Log mit Substring `'failed-op already recorded for this action'`).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Zwei PHPUnit-Test-Files: `tests/slices/pod-shop-mvp/slice-09-failed-ops-repo.php` (AC-1 bis AC-5) und `tests/slices/pod-shop-mvp/slice-09-retry-policy-listener.php` (AC-6 bis AC-12). Brain\Monkey-Setup mit `Functions\expect('current_time')`, `Functions\when('as_get_scheduled_actions')`. `$wpdb` als Mockery-Mock im global scope. `FailedOpsRepo` wird per Konstruktor mit `\wpdb` injiziert; `RetryPolicyListener` per Konstruktor mit `FailedOpsRepo` + `\ActionScheduler_Store`-Mock + `\ActionScheduler_Logger`-Mock + optional `?WC_Logger`. Action-Scheduler-Mocks verwenden `Mockery::mock(\ActionScheduler_Action::class)` mit Stub-Methoden `get_hook()`, `get_args()`.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-09-failed-ops-repo.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class FailedOpsRepoTest extends TestCase
{
    // AC-1: record() inserts row with all required + audit fields
    public function test_record_inserts_row_with_all_fields(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    public function test_record_returns_insert_id(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    public function test_record_json_encodes_payload(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    public function test_record_uses_utc_for_created_at(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: validation rejects missing required fields
    public function test_record_throws_on_missing_op_type(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    public function test_record_throws_on_missing_related_entity_id(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    public function test_record_does_not_call_wpdb_insert_on_invalid_args(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: findById returns row or null
    public function test_find_by_id_returns_associative_array(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    public function test_find_by_id_decodes_payload_json(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    public function test_find_by_id_returns_null_for_unknown_id(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: state mutators
    public function test_mark_resolved_writes_state_resolved(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    public function test_mark_dismissed_writes_state_dismissed(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    public function test_mark_resolved_is_idempotent_returns_false_on_repeat(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: findByEntity uses composite index path
    public function test_find_by_entity_filters_by_type_id_state(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    public function test_find_by_entity_orders_by_created_at_desc(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    public function test_find_by_entity_without_state_returns_all(): void
    {
        $this->markTestIncomplete('AC-5');
    }
}
```
</test_spec>

### Test-Datei: `tests/slices/pod-shop-mvp/slice-09-retry-policy-listener.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class RetryPolicyListenerTest extends TestCase
{
    // AC-6: Permanent (4xx) on first fail -> direct repo-record, no retry
    public function test_client_error_records_failed_op_immediately(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    public function test_client_error_does_not_re_enqueue_action(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Transient below threshold -> no record
    public function test_transient_error_with_two_retries_does_not_record(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Transient at threshold (3) -> record with retries_used=3
    public function test_transient_error_with_three_retries_records_with_counter(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Hook -> op_type mapping for all 9 plugin hooks
    public function test_op_type_mapping_for_all_known_hooks(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    public function test_unknown_hook_is_ignored(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: entity extraction from args
    public function test_entity_extraction_for_create_order(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    public function test_entity_extraction_for_sync_article(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    public function test_entity_extraction_defaults_to_system_for_sync_catalog(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    public function test_missing_args_key_uses_unknown_default(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: Bootstrap registers AS hook idempotently
    public function test_plugin_init_registers_action_scheduler_failed_action_hook(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    public function test_plugin_init_is_idempotent_for_listener_hook(): void
    {
        $this->markTestIncomplete('AC-11');
    }

    // AC-12: Double-fire idempotency
    public function test_listener_skips_second_invocation_when_recent_unresolved_row_exists(): void
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
| `slice-04-schema-dbdelta` | Tabelle `wp_spreadconnect_failed_ops` mit Spalten + Indexes laut architecture.md Z. 191-210 | DB-Schema | Spaltennamen + Indexes vorhanden; ohne Slice 04 wirft `$wpdb->insert()` Errors. |
| `slice-07-http-client-base` | `SpreadconnectPod\Api\SpreadconnectClientError` / `SpreadconnectTransientError` (FQCN) | exception classes | Listener vergleicht `$action_log->exception_class` per `is_a($class, $fqcn, true)` — beide Klassen sind ohne Aenderung wiederverwendet. |
| `slice-23-sync-article-job` | Hook `spreadconnect/sync_article` schmeisst konsistent `SpreadconnectTransientError`/`SpreadconnectClientError` | Konvention | Slice 23 ACs (AC-4/AC-5) garantieren das Re-Throw. |
| `slice-28-order-submit-job` | Hook `spreadconnect/create_order` schmeisst konsistent `SpreadconnectTransientError`; 4xx wird im Job geschluckt + per `wc_get_logger` mit Tag `failed_op_pending_record` markiert (Slice 28 AC-5) | Konvention | Slice 37 ueberlappt das Logging-Stub via `action_scheduler_failed_action`-Listener — ohne dass Slice 28 angepasst werden muss. |
| Action Scheduler (WC-bundled) | Hook `action_scheduler_failed_action` (1 arg = `$action_id`); `\ActionScheduler::store()->fetch_action($id)`; `\ActionScheduler::logger()->get_logs($id)`; `as_get_scheduled_actions(array $args)` | AS-API | Verfuegbar mit WC 10.5; siehe AS Source `ActionScheduler_Action` + `ActionScheduler_Logger`. |
| WordPress | `$wpdb`, `current_time('mysql', true)`, `add_action()`, `has_action()`, `wc_get_logger()` | WP-API | Standard. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Failure\FailedOpsRepo` | infrastructure class | Slice 38 (Failed-Ops-UI: `findAll`, `findById`, `markResolved`, `markDismissed`); Slice 39 (`FailureNotifier`: liest neue Rows aus `record()`-Result-ID); Slice 40 (`BulkResendCoordinator`: bulk-find + bulk-mark); Slice 17 (`ProcessWebhookEventJob`-Stub-Insert wird durch echten Repo-Call ersetzt); Slice 33 (Order-List bulk-resend nutzt `findByEntity`) | Konstruktor `__construct(\wpdb $wpdb, ?\WC_Logger $logger = null)`; Public-Methoden: `record(array $args): int`, `findById(int $id): ?array`, `findAll(array $filter = [], int $limit = 50, int $offset = 0): array`, `findByEntity(string $type, string $entity_id, ?string $state = null): array`, `markResolved(int $id): bool`, `markDismissed(int $id): bool`, `count(string $state = 'unresolved'): int` |
| `SpreadconnectPod\Failure\RetryPolicyListener` | adapter class (AS-Hook-Receiver) | Bootstrap (`init()`) registriert die Instanz auf `action_scheduler_failed_action`; Slice 39 erweitert spaeter um `FailureNotifier`-Dispatch nach erfolgreichem Repo-Insert | Konstruktor `__construct(FailedOpsRepo $repo, ?\WC_Logger $logger = null)`; Public-Methode `on_action_failed(int $action_id): void` |
| Op-Type-Enum (string-literal-Konvention) | shared convention | Slice 38 (UI-Filter), Slice 39 (Notifier-Routing), Slice 40 (Bulk-Resend Mapping `op_type -> AS-hook`) | Enum (string): `'create_order'`, `'confirm_order'`, `'cancel_order_mirror'`, `'fetch_tracking'`, `'sync_article'`, `'sync_catalog'`, `'handle_article_removed'`, `'handle_webhook'`, `'scheduled_stock_sync'` |

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Failure/FailedOpsRepo.php` — Neue Klasse `final class FailedOpsRepo`. Konstruktor `(\wpdb $wpdb, ?\WC_Logger $logger = null)`. Public-Methoden: `record(array $args): int`, `findById(int $id): ?array`, `findAll(array $filter = [], int $limit = 50, int $offset = 0): array`, `findByEntity(string $type, string $entity_id, ?string $state = null): array`, `markResolved(int $id): bool`, `markDismissed(int $id): bool`, `count(string $state = 'unresolved'): int`. Komplette CRUD via `$wpdb->insert/update/get_row/get_results` (prepared statements PFLICHT).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Failure/RetryPolicyListener.php` — Neue Klasse `final class RetryPolicyListener`. Konstruktor `(FailedOpsRepo $repo, ?\WC_Logger $logger = null)`. Public-Methode `on_action_failed(int $action_id): void` mit Hook-Suffix-Mapping (Tabelle in AC-9), Args-Entity-Extraction (AC-10), Exception-Klassifizierung (Permanent vs. Transient), Retry-Counter-Lookup via `as_get_scheduled_actions` + AS-Logs, Idempotency-Check via `FailedOpsRepo::findByEntity()` (AC-12).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — **Edit, nicht ersetzen.** In `init()` ergaenzen: Konstruktion `$failedOpsRepo = new FailedOpsRepo($wpdb)` und `$retryListener = new RetryPolicyListener($failedOpsRepo)`; `add_action('action_scheduler_failed_action', [$retryListener, 'on_action_failed'], 10, 1)`. Idempotenz wie in Slice 28/29 ueblich.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-09-failed-ops-repo.php` und `tests/slices/pod-shop-mvp/slice-09-retry-policy-listener.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- KEINE Aenderung an Job-Handlern aus Slice 23/24/25/28/29/30/31 — die Slices wurden mit den korrekten Exception-Klassen geschrieben (`SpreadconnectTransientError` Re-Throw fuer 5xx; `SpreadconnectClientError` Catch fuer 4xx). Slice 37 ist **rein passiv** ueber den AS-Hook `action_scheduler_failed_action` integriert.
- KEINE `FailureNotifier`-Calls (`wp_mail` an Recipients) — kommt in Slice 39, der nach erfolgreichem `record()`-Insert dispatchen wird.
- KEINE persistenten Admin-Notices via `AdminNoticeStore` — Slice 39.
- KEINE Failed-Ops-UI / Resend / Dismiss / Modal — Slice 38.
- KEIN Bulk-Resend-Coordinator — Slice 40.
- KEINE Auto-Resolution (z. B. wenn der Webhook `Order.processed` nachtraeglich eintrifft und die `failed_to_submit`-Order doch geloest ist) — out-of-scope; Slice 38 implementiert Manual-Resolve.
- KEINE Aenderung am AS-Retry-Verhalten (Backoff-Intervalle 1m/5m/15m bleiben AS-default) — Slice 37 OBSERVIERT nur, modifiziert nicht den AS-Scheduler.
- KEIN `RuntimeException`-Re-Wrap der Original-Exception — Listener arbeitet auf bereits abgeschlossenen Action-Logs.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in beiden Dateien.
- `final class` fuer beide neuen Klassen.
- **PFLICHT**: Alle Queries in `FailedOpsRepo` via `$wpdb->prepare()` (Prepared Statements) — keine String-Concatenation. SQL-Injection-Risk-Surface = 0.
- `record()` MUSS `payload` per `wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` serialisieren (NICHT `json_encode()`). Bei Encode-Failure: `error_message` mit `'payload_serialize_failed'` praefixen, statt zu werfen.
- `created_at` und `last_attempt_at` MUESSEN UTC sein (`current_time('mysql', true)` mit `$gmt=true`) — DB-Spalte ist DATETIME ohne TZ-Info, daher Konvention "alles UTC" wie restliche Custom-Tables (architecture.md "Schema Details" — Spalten ohne TZ-Suffix).
- `findByEntity()` WHERE-Klausel-Reihenfolge: `related_entity_type, related_entity_id [, state]` — entspricht Index `idx_related_entity` (architecture.md Z. 209) fuer Index-Hits.
- Logging via `wc_get_logger()->info()/warning()/error()` mit Source `'spreadconnect-failure'` (architecture.md Z. 398). Kein `error_log()`.
- `RetryPolicyListener::on_action_failed()` MUSS Try-Catch um den gesamten Body wrappen (`\Throwable`) und im Fehlerfall nur einen Log-Eintrag schreiben — der Listener darf NIE selbst werfen, da er innerhalb eines Action-Scheduler-Lifecycles laeuft und ein Throw weitere AS-Probleme verursachen wuerde.
- Retry-Counter-Ermittlung: per `as_get_scheduled_actions(['hook' => $hook, 'args' => $args, 'status' => 'failed', 'per_page' => 10])` fuer dieselbe `(hook, args)`-Kombination. Der Counter ist `count($prior_failed_actions)`. Alternativ: AS-Action-Log-Eintraege via `\ActionScheduler::logger()->get_logs($action_id)` zaehlen die Eintraege mit Substring `'failed'`. Implementer waehlt; AC-7/AC-8 prueft das beobachtbare Verhalten an der Threshold (3).
- Idempotency-Window in AC-12 = "5 Minuten" — gemeint ist: gleiche `(op_type, related_entity_type, related_entity_id, state='unresolved')` mit `created_at >= NOW() - INTERVAL 5 MINUTE` UTC. Lookup via `findByEntity()` + Client-side-Filter auf `created_at`.
- HPOS: irrelevant in Slice 37 (Repo schreibt nur Custom-Table, kein WC-Order-Meta).
- KEIN dependency injection container — die Konstruktion erfolgt inline in `Bootstrap\Plugin::init()` (analog Slice 28).

**Reuse:**

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + 03 + 04 + 05 + 06 + 28 + ff.) | **Edit, nicht ersetzen.** Bisherige `init()`-Logik (HPOS-Declare, Schema-Activate, Options-Defaults, i18n-Loader, Order-Hooks aus Slice 28-31, Catalog-Hooks aus Slice 23-25) bleibt unveraendert; Slice 37 ergaenzt eine `add_action('action_scheduler_failed_action', ...)`-Registrierung. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Schema.php` (Slice 04) | **Wiederverwendet, unveraendert.** Slice 04 erstellt die Tabelle `wp_spreadconnect_failed_ops` per `dbDelta` mit allen Spalten + Indexes; Slice 37 schreibt nur in diese Tabelle. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php` (Slice 07) | **Wiederverwendet, unveraendert.** Listener prueft per FQCN-Match (`is_a()`) die exception_class aus AS-Logs. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectTransientError.php` (Slice 07) | **Wiederverwendet, unveraendert.** Wie oben. |
| Job-Handler aus Slice 23/24/25/28/29/30/31 (`SyncArticleJob`, `SyncCatalogJob`, `ArticleRemovedJob`, `OrderSubmitJob`, `OrderConfirmJob`, `OrderCancelJob`, `OrderCancelMirrorJob`, `FetchTrackingJob`, `ProcessWebhookEventJob`) | **Wiederverwendet, unveraendert.** Slice 37 erweitert deren Verhalten **passiv** ueber den AS-Hook — KEINE Aenderung am Job-Code. Die in Slice 28 AC-5 bzw. Slice 23 AC-4 dokumentierten Logging-Stubs (`failed_op_pending_record`) werden durch echte Repo-Inserts ueberlagert; das Logging-Stub bleibt der Lesbarkeit halber bestehen, ist aber nicht mehr Source of Truth fuer DLQ-Rows. |

**Referenzen:**
- Architecture: `architecture.md` -> "Schema Details — wp_spreadconnect_failed_ops" Z. 191-210 — autoritative Spaltenliste + Indexes.
- Architecture: `architecture.md` -> "Action Scheduler — Hook Inventory" Z. 542-558 — alle 9 Plugin-Hooks + Retry-Policy `1m/5m/15m on 5xx; permanent on 4xx`.
- Architecture: `architecture.md` -> "Business Logic Flow — Outbound Order Submit (Flow C)" Z. 425-429 — Permanent-vs-Transient-Klassifizierung + 3-Retry-Threshold.
- Architecture: `architecture.md` -> "Quality Attributes — Reliability — Failure recovery" Z. 680 — autoritatives Done-Signal `3-fail-then-record assertion`.
- Architecture: `architecture.md` -> Service-Map Z. 388 — `Failure\FailedOpsRepo` Verantwortlichkeit.
- Architecture: `architecture.md` -> "Business Logic Flow — Webhook Receive (Flow E)" Z. 457 — `ProcessWebhookEventJob`-Failure-Pfad nutzt `op_type='handle_webhook'` (Mapping fuer AC-9).
- Discovery: `discovery.md` -> Slice 9 "Failure-Recovery" Z. 930 — `Custom-Table failed_ops`, Auto-Retry-Hook in Action-Failure.
- Slim-Slices: `slices/slim-slices.md` -> Slice-37-Eintrag Z. 559-566 — Done-Signal: `3x 5xx -> Retry-Counter=3 -> Failed-Ops-Row`; `4xx -> direkt Row, kein Retry`.
- Slice 28: `slices/slice-28-order-submit-job.md` -> AC-5 + Constraints — Logging-Stub-Konvention `failed_op_pending_record` wird in Slice 37 durch echten Repo-Insert ersetzt; Slice 28 bleibt unveraendert.
- Slice 23: `slices/slice-23-sync-article-job.md` -> AC-4 + Constraints — Re-Throw fuer 4xx genuegt in Slice 23, weil Slice 37 den `action_scheduler_failed_action`-Hook wired.
- Wireframes: `wireframes.md` — **nicht relevant** fuer Slice 37 (Repo + Listener ohne UI; UI-Surface kommt in Slice 38).
