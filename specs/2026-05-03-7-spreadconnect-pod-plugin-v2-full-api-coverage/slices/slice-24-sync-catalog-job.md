# Slice 24: Sync-Catalog-Job + History-Row

> **Slice 24 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-24-sync-catalog-job` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-23-sync-article-job"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + Action-Scheduler + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `as_enqueue_async_action`/`as_schedule_*`; `$wpdb`-Stub fuer `SyncHistoryRepo`-Insert/Update) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: `WooCommerce -> Spreadconnect -> Catalog -> Sync Now` triggert Catalog-Job) |
| **Health Endpoint** | `n/a` (Action-Scheduler-Job-Handler) |
| **Mocking Strategy** | `mock_external` (`SpreadconnectClient::getArticles` als Konstruktor-Injectable; `SyncHistoryRepo` mit `$wpdb`-Stub via Brain\Monkey; `as_*`-Funktionen via Brain\Monkey-Function-Mocks; `time()`/`current_time` via Patchwork wo noetig) |

---

## Ziel

Implementiert den **Catalog-Producer** als Action-Scheduler-Hook-Handler `spreadconnect/sync_catalog`: paginiert `GET /articles` (Slice 10 Wrapper #2), legt fuer jeden Run **eine** Row in `wp_spreadconnect_sync_history` mit `state='in_progress'` an, und schedult pro Article-ID eine `spreadconnect/sync_article`-Action mit dem zugehoerigen `run_id` (Slice 23 Konsument). Erweitert ausserdem den in Slice 23 angelegten schmalen `SyncHistoryRepo` um Counter-Increment-Methoden und einen Tracker, der die History-Row beim Abschluss aller per-article-Jobs auf `state='complete'` umschaltet.

---

## Acceptance Criteria

1) **GIVEN** ein Action-Scheduler-Worker dispatched `spreadconnect/sync_catalog` mit Args `['trigger'=>'manual']`, und `SpreadconnectClient::getArticles($page, $size)` liefert ueber 2 Seiten insgesamt 50 `ArticleSummary`-Eintraege (Page 1: 25 Items + Page 2: 25 Items + Page 3: 0 Items als End-Sentinel)
   **WHEN** `Catalog\SyncCatalogJob::handle(['trigger'=>'manual'])` aufgerufen wird
   **THEN** wird **genau eine** Row in `wp_spreadconnect_sync_history` per `INSERT` angelegt mit `state='in_progress'`, `started_at=current_time('mysql')`, `trigger='manual'`, `created_count=0`, `updated_count=0`, `skipped_count=0`, `error_count=0`, `details='[]'` (leerer JSON-Array). Die durch `$wpdb->insert_id` gelieferte `run_id` ist ab diesem Moment der primaere Tracker-Key (siehe AC-2/AC-7).

2) **GIVEN** dieselbe Sequenz wie in AC-1
   **WHEN** alle Pages durchlaufen sind
   **THEN** wurde fuer jeden der 50 Articles **genau einmal** `as_enqueue_async_action('spreadconnect/sync_article', ['article_id'=>$id, 'run_id'=>$runId], 'spreadconnect')` aufgerufen (Group-Param `'spreadconnect'` per Default-Group-Konvention aus architecture.md "AS Hook Inventory"-Anmerkung). Reihenfolge entspricht der Server-Reihenfolge aus den `getArticles`-Responses (deterministisch fuer Pagination, siehe discovery.md Z. 794).

3) **GIVEN** dieselbe Sequenz wie in AC-1
   **WHEN** alle 50 Articles enqueued sind
   **THEN** wird der `total`-Tracker fuer den Run via `SyncHistoryRepo::setTotal($runId, 50)` persistiert. Speicherort ist Implementer-Wahl: ENTWEDER neue Spalte/Transient `sc_sync_total_{run_id}` (24h TTL) ODER Schreiben in das `details`-Array als Meta-Eintrag — solange AC-7 (Counter-Vergleich) erfuellbar ist. (Architecture-Schema-Tabelle `wp_spreadconnect_sync_history` enthaelt **kein** `total_count`-Feld; daher ist Transient/Run-Meta die nicht-invasive Wahl.)

4) **GIVEN** `SpreadconnectClient::getArticles($page, $size)` liefert auf Page 1 leeres `items`-Array (Total = 0 Articles in SC)
   **WHEN** `handle(['trigger'=>'manual'])` aufgerufen wird
   **THEN** wird die History-Row angelegt (`state='in_progress'`), **kein** `as_enqueue_async_action` aufgerufen, und die Row sofort durch `SyncHistoryRepo::markComplete($runId)` auf `state='complete'`, `finished_at=current_time('mysql')` gesetzt. Total-Counter = 0 (Edge-Case: leerer Catalog).

5) **GIVEN** `SpreadconnectClient::getArticles(...)` wirft `SpreadconnectClientError` (4xx — z.B. 401 weil API-Key invalid)
   **WHEN** `handle(...)` aufgerufen wird
   **THEN** wird (a) die History-Row mit `state='failed'` markiert (`SyncHistoryRepo::markFailed($runId, $errorMessage)`), `finished_at` gesetzt, (b) **kein** `as_enqueue_async_action` aufgerufen, (c) die Exception re-thrown — Action-Scheduler markiert den Catalog-Job als `failed` (kein Retry bei 4xx, siehe architecture.md "AS Hook Inventory" Z. 546).

6) **GIVEN** `SpreadconnectClient::getArticles(...)` wirft `SpreadconnectTransientError` (5xx/Network) auf Page 2 nach erfolgreicher Page 1 (25 Articles bereits enqueued)
   **WHEN** `handle(...)` aufgerufen wird
   **THEN** wird (a) die History-Row **NICHT** umgeschaltet (bleibt `state='in_progress'`), damit AS-Retry den Job idempotent fortsetzt; (b) die Exception re-thrown -> AS-Retry-Cycle 1m/5m/15m. **Konsequenz:** Implementer MUSS Pagination-Resume-Pattern dokumentieren — entweder Pagination-Position als zusaetzliches Args-Feld bei AS-Reschedule (`$args['resume_page']`) ODER Akzeptanz von Doppel-Enqueues (Slice 23 toleriert mehrfach-Schedule per `_spreadconnect_article_id`-Reverse-Lookup-Idempotenz, Discovery Z. 573 "Last-Write-Wins"). Default-Pfad: **Doppel-Enqueue tolerieren** (AS-Retry startet komplett neu); Resume-Optimierung ist Out-of-Scope fuer Slice 24.

7) **GIVEN** ein laufender Catalog-Run mit `run_id=42` und `total=50`. Im Verlauf rufen 50 verschiedene `Catalog\SyncArticleJob`-Worker (Slice 23) **nach** ihrem `appendDetail($runId, $detail)` jeweils EINE der vier Increment-Methoden auf (`incrementCreated`/`incrementUpdated`/`incrementSkipped`/`incrementError`)
   **WHEN** der letzte Worker seine Increment-Methode aufruft und der Counter-Sum (`created+updated+skipped+error`) den persistierten `total=50` erreicht
   **THEN** wird in derselben Transaktion `state='complete'` und `finished_at=current_time('mysql')` gesetzt. Die Increment-Methode MUSS atomar sein: `$wpdb->query("UPDATE {$table} SET created_count=created_count+1, ... WHERE id=%d", $runId)` (Single-Statement-Increment) — kein Read-Modify-Write-Race. Der Tracker-Check (counter sum == total) erfolgt **nach** dem Increment im selben Method-Call (Lese-`SELECT created_count, updated_count, skipped_count, error_count FROM ... WHERE id=%d`).

8) **GIVEN** `Catalog\SyncArticleJob` wird durch Slice 23 als Konsument von `spreadconnect/sync_article` ausgefuehrt
   **WHEN** Slice 23 nach erfolgreichem `appendDetail()` einen der Counter-Increment-Calls ausfuehrt
   **THEN** ist **dieser Call** der Mount-Point fuer den Counter-Tracker. Slice 24 erweitert die Slice-23-Implementation NICHT — der Slice-24-Implementer ergaenzt im selben Edit den Aufruf von `SyncHistoryRepo::increment{Created|Updated|Skipped|Error}($runId)` direkt **nach** dem `appendDetail()`-Call in `SyncArticleJob::handle()`. Bei `run_id=null` (Webhook-getriggerter Per-Article-Sync ohne Run, Slice 23 AC-10) wird **weder** appendDetail **noch** increment aufgerufen.

9) **GIVEN** Plugin-Bootstrap aus Slice 02 ist initialisiert
   **WHEN** der Bootstrap-Hook `init_actions()` (oder Aequivalent) laeuft
   **THEN** ist `add_action('spreadconnect/sync_catalog', [SyncCatalogJob::class, 'handleStatic'], 10, 1)` registriert (analog Slice 23 AC-8 `sync_article`-Pattern). Die statische Bridge resolved den Service via Container/Factory.

10) **GIVEN** Pagination-Loop ruft `getArticles($page, $size)` mit `$size=50` (Default-Pagesize)
    **WHEN** `getArticles` returnt `items`-Array mit weniger als `$size` Eintraegen ODER ein leeres `items`-Array
    **THEN** terminiert der Pagination-Loop deterministisch (less-than-size = letzte Page; empty = letzte Page). Page-Counter beginnt bei `1` (1-basiert, gemaess Slice 10 AC-2 Query-String `?page=1&size=50`). Hard-Cap: `MAX_PAGES=200` (= 10000 Articles bei Pagesize 50) als Safety-Limit gegen unbounded Loops; Erreichen von `MAX_PAGES` schreibt `state='failed'` mit `error_message='pagination_max_pages_exceeded'`.

11) **GIVEN** der Slice-23-Test mit 50 Articles ist gruen
    **WHEN** der Done-Signal-Test fuer Slice 24 laeuft (`composer test` mit `slice-04-sync-catalog-job.php`)
    **THEN** verifiziert ein PHPUnit-Test: nach `handle(['trigger'=>'manual'])` wurden **exakt 50** `as_enqueue_async_action`-Calls mit `'spreadconnect/sync_article'`-Hook und `'spreadconnect'`-Group enqueued, **und** `$wpdb->insert` wurde **exakt einmal** auf `wp_spreadconnect_sync_history` aufgerufen (siehe slim-slices.md Slice-24 Done-Signal).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey mocked `as_enqueue_async_action`, `current_time`, `wp_using_ext_object_cache`. `$wpdb` wird via Mockery-Mock injiziert (Constructor-Param). `SpreadconnectClient` wird Konstruktor-injiziert; Test-Subclass oder Mockery-Mock liefert Page-Responses. Slice-23-Tests duerfen NICHT aufgerufen werden — Slice-24-Tests testen ausschliesslich Producer + Repo-Counter-Logik.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-04-sync-catalog-job.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests\Catalog;

use PHPUnit\Framework\TestCase;

final class SyncCatalogJobTest extends TestCase
{
    // AC-1: Insert genau 1 Row in sync_history mit state=in_progress, trigger=manual, alle Counter=0
    public function test_handle_inserts_single_history_row_with_in_progress_state(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: 50 Articles ueber 2 Pages -> 50 sync_article-Actions mit run_id enqueued
    public function test_handle_enqueues_one_sync_article_action_per_article_with_run_id(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Action-Scheduler-Group ist "spreadconnect"
    public function test_handle_uses_spreadconnect_action_scheduler_group(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: total wird persistiert (Transient sc_sync_total_{run_id} oder Aequivalent)
    public function test_handle_persists_total_article_count_for_run(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: leerer Catalog -> History-Row direkt auf state=complete
    public function test_handle_marks_complete_immediately_when_catalog_is_empty(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: SpreadconnectClientError (4xx) -> state=failed, kein Enqueue, re-thrown
    public function test_handle_marks_failed_and_rethrows_on_client_error(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: SpreadconnectTransientError (5xx) -> Row bleibt in_progress, re-thrown
    public function test_handle_keeps_in_progress_state_and_rethrows_on_transient_error(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Counter-Increment ist atomar (Single-UPDATE-Statement)
    public function test_increment_created_uses_atomic_single_update_statement(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: Beim Erreichen counter-sum == total wird state=complete + finished_at gesetzt
    public function test_repo_marks_complete_when_counter_sum_equals_total(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: Vor Erreichen total bleibt state=in_progress
    public function test_repo_keeps_in_progress_until_counter_sum_equals_total(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Slice-23 SyncArticleJob ruft increment{Status} nach appendDetail
    public function test_sync_article_job_invokes_counter_increment_after_append_detail(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: bei run_id=null kein Increment-Aufruf
    public function test_sync_article_job_skips_increment_when_run_id_is_null(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Bootstrap registriert add_action('spreadconnect/sync_catalog', ...)
    public function test_bootstrap_registers_sync_catalog_action_hook(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: Pagination terminiert bei items.size < pagesize
    public function test_pagination_terminates_on_partial_last_page(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-10: MAX_PAGES Safety-Cap loest state=failed mit pagination_max_pages_exceeded
    public function test_pagination_aborts_with_failed_state_when_max_pages_exceeded(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: Done-Signal: 50 Articles -> 50 enqueues + 1 history-row
    public function test_done_signal_50_articles_yield_50_enqueues_and_one_history_row(): void
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
| `slice-23-sync-article-job` | `Catalog\SyncArticleJob` (Konsument von `spreadconnect/sync_article`), `Catalog\SyncHistoryRepo` (schmal: `appendDetail`) | Class + Method | Slice 23 ACs gruen; `appendDetail`-Signatur bleibt unveraendert; Slice 24 erweitert die Klasse um Counter-Methoden ohne Bestand zu brechen. |
| `slice-23-sync-article-job` | Action-Hook `spreadconnect/sync_article` registriert | Hook-Registration | Slice 23 AC-8 verifiziert Hook ist registriert; Slice 24 schedult diesen Hook per `as_enqueue_async_action`. |
| `slice-10-endpoint-methods` | `SpreadconnectClient::getArticles(int $page, int $size, ?string $search = null): array` | Method auf `Api\SpreadconnectClient` | Slice 10 AC-2 garantiert paginated wrap mit Keys `['items', 'page', 'size', 'total']`; Slice 24 liest `items` und (optional) `total` fuer Hard-Cap-Check. |
| `slice-10-endpoint-methods` | `SpreadconnectClientError`, `SpreadconnectTransientError` | Exception classes | Pass-Through analog Slice 23 AC-4/AC-5; kein Wrapping. |
| `slice-09-dto-value-objects` | `Api\Dto\ArticleSummary` (mit `id`-Property) | DTO | Slice 24 liest `$summary->id` (oder `->articleId`, exakte Property-Naming pro Slice 09) zum Args-Building. |
| `slice-04-schema-dbdelta` | Custom Table `wp_spreadconnect_sync_history` mit allen Spalten (`state`, `started_at`, `finished_at`, `trigger`, `created_count`, `updated_count`, `skipped_count`, `error_count`, `details`) | DB-Resource | Slice 04 erstellt das Schema laut architecture.md Z. 233-258; Slice 24 schreibt `INSERT` (Row-Anlage) und `UPDATE`s (Counter + State-Transition). |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::init()`-Hook-Lifecycle | Bootstrap-Lifecycle | Slice 24 ergaenzt `add_action('spreadconnect/sync_catalog', ...)` analog Slice 23. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `Catalog\SyncCatalogJob::handle` | Method (Action-Scheduler-Handler) | Action-Scheduler-Worker; `slice-26-catalog-sync-ui-progress` (AJAX `spreadconnect_sync_now` enqueued `spreadconnect/sync_catalog`) | `public function handle(array $args): void` mit `$args = ['trigger'=>string ('manual'/'webhook'/'scheduled'/'initial')]` |
| `Catalog\SyncHistoryRepo` (erweitert) | Class mit zusaetzlichen Methoden | `slice-26-catalog-sync-ui-progress` (REST `/sync-progress` liest Row), `slice-23-sync-article-job` (Counter-Increment-Aufrufe) | `public function incrementCreated(int $runId): void`, `incrementUpdated(int $runId): void`, `incrementSkipped(int $runId): void`, `incrementError(int $runId): void`, `setTotal(int $runId, int $total): void`, `markComplete(int $runId): void`, `markFailed(int $runId, string $errorMessage): void` |
| Action-Hook `spreadconnect/sync_catalog` | Action-Hook (registered) | Action-Scheduler-Worker; `slice-26` AJAX (Producer) | Hook-Signatur: `array $args` |
| Tracker-Storage `sc_sync_total_{run_id}` | Transient (24h TTL) | Internes Detail von `SyncHistoryRepo::setTotal/get` | Implementer-Wahl; nicht oeffentlich |

> **Mount-Point-Check:** `SyncCatalogJob` ist Application-Klasse, dispatched durch Action-Scheduler. Mount-Point ist `add_action(...)` in `Bootstrap\Plugin` (siehe Deliverable). `SyncHistoryRepo`-Counter-Methoden werden direkt durch `SyncArticleJob` (Slice 23 Edit-Target hier) konsumiert — der Mount erfolgt durch das `Plugin.php`-Edit (analog Slice 23). UI-Konsument `slice-26` mountet im Folge-Slice.

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncCatalogJob.php` — `final class SyncCatalogJob` mit Konstruktor-DI (`SpreadconnectClient`, `SyncHistoryRepo`), `public function handle(array $args): void` (siehe ACs 1-6, 10-11), `public static function handleStatic(array $args): void` (Bridge fuer `add_action`, instantiiert via Container/Factory). Konstanten `PAGE_SIZE = 50` und `MAX_PAGES = 200`.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncHistoryRepo.php` — Erweitert die schmal in Slice 23 angelegte Klasse um: `public function startRun(string $trigger): int` (gibt `run_id` zurueck), `setTotal(int, int): void`, `incrementCreated(int): void`, `incrementUpdated(int): void`, `incrementSkipped(int): void`, `incrementError(int): void`, `markComplete(int): void`, `markFailed(int, string): void`. Slice-23-`appendDetail` bleibt unveraendert.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Ergaenzt `add_action('spreadconnect/sync_catalog', [SyncCatalogJob::class, 'handleStatic'], 10, 1)` in der bestehenden Hook-Registration-Methode (analog Slice 23 fuer `sync_article`).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncArticleJob.php` — Ergaenzt direkt nach `SyncHistoryRepo::appendDetail($runId, $detail)` (Slice 23) den korrespondierenden Counter-Increment-Aufruf basierend auf `$detail['status']`: `created` -> `incrementCreated`, `updated` -> `incrementUpdated`, `skipped` -> `incrementSkipped`, `error`/`partial` -> `incrementError`. Bei `run_id=null` kein Aufruf (Slice 23 AC-10 unveraendert).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-04-sync-catalog-job.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- **Kein** UI-Touch — Catalog-Sync-Page mit "Sync Now"-Button + Progress-Bar ist Slice 26.
- **Kein** REST-Route `/sync-progress` — Slice 26.
- **Kein** Cancel-Sync-Handling (`spreadconnect_cancel_sync`-AJAX, `as_unschedule_action`) — Slice 26 / Out-of-Scope-MVP-Optimierung.
- **Kein** Anlegen der `wp_spreadconnect_sync_history`-Tabelle — Schema kommt aus Slice 04.
- **Kein** Webhook-Trigger (`Article.added/updated/removed` -> `sync_article`-Schedule) — Slice 25.
- **Kein** initialer Auto-Sync nach Plugin-Activate — Slice 26 (Activate-Hook `as_enqueue_async_action` mit `trigger='initial'`) ODER spaeter.
- **Kein** Hard-Refresh des `sc_pt_{id}`-ProductType-Cache — Slice 23 / Slice 36.
- **Kein** Failed-Ops-Repo-Insert bei 4xx — `FailedOpsRepo` kommt erst in Slice 37; State `state='failed'` auf der History-Row reicht (User sieht Fehler im Catalog-UI in Slice 26).
- **Kein** Rate-Limit-Throttling im Pagination-Loop — `SpreadconnectClient` (Slice 08) handhabt 429 + `X-RateLimit-Remaining` transparent.
- **Kein** `error_log`/`var_dump` — siehe Slice 23 Constraint (`WcLoggerAdapter` kommt in Slice 42; bis dahin Stub erlaubt).

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);`.
- `final class SyncCatalogJob` und `final class SyncHistoryRepo` (nicht erweiterbar).
- Method-Signaturen exakt wie im Integration Contract dokumentiert.
- **Pagination-Loop** (AC-10): 1-basiert (`$page = 1; while (true) { ... $page++; if ($page > MAX_PAGES) throw ... }`). Termination-Condition: `count($items) === 0 || count($items) < self::PAGE_SIZE`. Pagesize fix `50` (Konstante).
- **History-Row-Insert** (AC-1): `$wpdb->insert($wpdb->prefix.'spreadconnect_sync_history', ['started_at'=>current_time('mysql'), 'state'=>'in_progress', 'trigger'=>$args['trigger'] ?? 'manual', 'created_count'=>0, 'updated_count'=>0, 'skipped_count'=>0, 'error_count'=>0, 'details'=>'[]'], ['%s','%s','%s','%d','%d','%d','%d','%s'])`. `$wpdb->insert_id` -> `run_id`.
- **Counter-Increment-Atomicity** (AC-7): Single-Statement-`UPDATE` via `$wpdb->query($wpdb->prepare("UPDATE {$table} SET created_count=created_count+1 WHERE id=%d", $runId))`. KEIN Read-Modify-Write. Nach Increment: `SELECT created_count + updated_count + skipped_count + error_count AS sum FROM ... WHERE id=%d` -> wenn `$sum >= $total` (read total via `get_transient('sc_sync_total_'.$runId)`): `UPDATE ... SET state='complete', finished_at=current_time('mysql') WHERE id=%d AND state='in_progress'` (CAS-WHERE-Klausel verhindert Doppel-Markierung).
- **Total-Persistence** (AC-3): `set_transient('sc_sync_total_'.$runId, $total, 24 * HOUR_IN_SECONDS)`. Reads via `get_transient` in `incrementCreated/Updated/Skipped/Error` zur Vergleichs-Logik.
- **Status-Mapping fuer Counter** (AC-8): `appendDetail`-Detail-Status `created` -> `incrementCreated`; `updated` -> `incrementUpdated`; `skipped` -> `incrementSkipped`; `error` -> `incrementError`; `partial` -> `incrementError` (Discovery Z. 612: partial ist eine Fehler-Variante; alternativ Implementer-Wahl `incrementUpdated` falls semantisch passender — beides AC-konform, solange Counter-Sum == Total deterministisch ist).
- **Action-Scheduler-Group** (AC-2/AC-9): `'spreadconnect'` als 3. Argument zu `as_enqueue_async_action`. Hook-Registration analog Slice 23 (`handleStatic`-Bridge).
- **Bridge-Methode `handleStatic`**: identischer Pattern wie Slice 23 AC-8 / Slice 17 ProcessWebhookEventJob.
- **Args-Default**: `$args['trigger'] ?? 'manual'`. Akzeptierte Werte gemaess Schema: `manual`/`webhook`/`scheduled`/`initial`. KEINE Validierung im Job (Producer-Verantwortung).
- **No-Resume-Optimization** (AC-6): Bei AS-Retry nach Transient-Error startet der Catalog-Job neu von Page 1. Slice 23 toleriert Doppel-Schedules (Reverse-Lookup-Idempotenz). KEIN Pagination-Cursor-Persistenz in Slice 24.
- **Konstruktor-DI**: `SyncCatalogJob` und `SyncHistoryRepo` erhalten Dependencies via Konstruktor (Mockability fuer Tests).

**Reuse:**

Slice 24 baut ausschliesslich auf bestehenden Bausteinen auf; **keine** Neuimplementierung von Client, Repo-Foundation oder Hook-Registration:

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08/10) | Konstruktor-Injection. Job ruft `getArticles($page, $size)`. Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/ArticleSummary.php` (Slice 09) | Lesen von `id` zum Args-Building fuer `as_enqueue_async_action`. Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncHistoryRepo.php` (Slice 23) | **Edit-Target**: Erweitert um `startRun`, `setTotal`, `increment{Created\|Updated\|Skipped\|Error}`, `markComplete`, `markFailed`. Slice-23-`appendDetail` bleibt unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncArticleJob.php` (Slice 23) | **Edit-Target**: Ergaenzt Counter-Increment-Call nach `appendDetail`. Slice-23-Sequenz bleibt unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02/17/23) | **Edit-Target**: Ergaenzt `add_action('spreadconnect/sync_catalog', ...)` analog zur bestehenden `sync_article`-Hook-Registration. |
| `wordpress/plugins/spreadconnect-pod/composer.json` (Slice 02) | PSR-4-Mapping `SpreadconnectPod\\` -> `includes/`. Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/tests/bootstrap/bootstrap.php` (Slice 02-23) | Brain\Monkey + Patchwork-Bootstrap. Unveraendert; Test-Writer ergaenzt ggf. fehlende `as_*`-Funktion-Mocks. |

**Referenzen:**
- Architecture: `architecture.md` -> Service-Map Zeile `Catalog\SyncCatalogJob` (Z. 371): "Pages `GET /articles`, schedules per-article jobs; updates `sync_history`."
- Architecture: `architecture.md` -> "Action Scheduler — Hook Inventory" Zeile `spreadconnect/sync_catalog` (Z. 546): one-shot, Concurrency 1 (single-claim), Fail-fast 4xx, Retry 1m/5m/15m on 5xx.
- Architecture: `architecture.md` -> Schema `wp_spreadconnect_sync_history` (Z. 233-258) — autoritativ fuer alle Spalten + Enum `state`-Werte.
- Architecture: `architecture.md` -> "Outbound Endpoints" Zeile `GET /articles` (Z. 94): paginated wrap mit `Page<ArticleSummary>`-Shape.
- Architecture: `architecture.md` -> "Triggers / AJAX" Zeile `spreadconnect_sync_now` (Z. 142): Hub-Producer enqueued `spreadconnect/sync_catalog` mit `trigger='manual'`.
- Architecture: `architecture.md` -> "Failure Mode Map" Zeile "Image-sideload failure" (Z. 612): `status='partial'` -> sync continues -> Counter-Mapping zu `incrementError` rechtfertigt.
- Discovery: `discovery.md` -> Flow B "Initial Catalog Sync" Punkt 2-4 (Z. 137-148).
- Discovery: `discovery.md` -> Schema `wp_spreadconnect_sync_history` + JSON-Schema `details` (Z. 759-794).
- Slice-23: `slices/slice-23-sync-article-job.md` -> AC-9/AC-10 (`appendDetail`-Schema, run_id=null-Behandlung).
- Slice-10: `slices/slice-10-endpoint-methods.md` -> AC-2 (`getArticles`-Pagination-Wrapper-Vertrag).
- Slim-Slices: `slices/slim-slices.md` -> Slice-24-Eintrag (Done-Signal: 50 Articles -> 50 sync_article-Actions enqueued + 1 history-Row).
- Wireframes: `wireframes.md` — **nicht relevant** (Job-Handler ohne UI-Touch; Catalog-Sync-UI ist Slice 26).
