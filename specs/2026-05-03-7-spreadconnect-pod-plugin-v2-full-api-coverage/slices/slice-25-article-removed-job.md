# Slice 25: Article-Removed-Job + Article-Webhook-Handler

> **Slice 25 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-25-article-removed-job` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-17-process-webhook-event-job", "slice-24-sync-catalog-job"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WooCommerce 10.5 + Action-Scheduler + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `as_enqueue_async_action`, `wp_update_post`, `update_post_meta`, `get_posts`-mit-`meta_query`, `wc_get_logger`, `__()`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: SC-Webhook `Article.removed` ueber Dev-Tools/Simulate aus Slice 44 -> WC-Produkt-Status flippt sichtbar von `publish` auf `draft` unter `WooCommerce -> Produkte`) |
| **Health Endpoint** | `n/a` |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `add_action`, `as_enqueue_async_action`, `get_posts`, `wp_update_post`, `update_post_meta`, `wc_get_logger`; keine echte DB-/HTTP-I/O; Slice-23-`sync_article`-Hook nicht real ausgefuehrt — nur Enqueue-Spy) |

---

## Ziel

Schliesst die Webhook-zu-Catalog-Pipeline ab: ersetzt den Stub-`ArticleEventHandler` aus Slice 17 durch eine echte Implementation, die `Article.added`/`Article.updated` zum bestehenden Schedule `spreadconnect/sync_article` (Slice 23) und `Article.removed` zum neuen Schedule `spreadconnect/handle_article_removed` dispatcht. Der `ArticleRemovedJob` setzt das WC-Produkt per Reverse-Lookup auf `post_status='draft'` (NIEMALS `wp_delete_post`), um Order-Historie zu wahren (architecture.md Z. 281, 736).

---

## Acceptance Criteria

1) **GIVEN** ein Webhook-Payload mit `eventType='Article.added'` und `data.entity` enthaelt einen non-leeren `id`-String (z.B. `'ART-99'`), und der Dispatcher aus Slice 17 ruft `Webhook\ArticleEventHandler::handle($payload)`
   **WHEN** der Handler laeuft
   **THEN** ruft er **genau einmal** `as_enqueue_async_action('spreadconnect/sync_article', ['article_id'=>'ART-99', 'run_id'=>null], 'spreadconnect')` auf (Args-Shape laut architecture.md Service Map Z. 372 + Slice-23 Konsumenten-Vertrag) und kehrt ohne Exception zurueck. Group `'spreadconnect'` analog Slice 24 AC-2.

2) **GIVEN** ein Webhook-Payload mit `eventType='Article.updated'` und `data.entity.id='ART-12'`
   **WHEN** `ArticleEventHandler::handle($payload)` laeuft
   **THEN** ruft er **identisch zu AC-1** `as_enqueue_async_action('spreadconnect/sync_article', ['article_id'=>'ART-12','run_id'=>null], 'spreadconnect')`. Slice 23 toleriert mehrfach-Schedule per `_spreadconnect_article_id`-Reverse-Lookup-Idempotenz (Slice 22 AC-2). Begruendung: webhook-getriggerter Per-Article-Sync laeuft ohne Catalog-Run -> `run_id=null` (Slice 23 AC-10 + Slice 24 AC-8 ueberspringen Counter/History).

3) **GIVEN** ein Webhook-Payload mit `eventType='Article.removed'` und `data.entity.id='ART-77'`
   **WHEN** `ArticleEventHandler::handle($payload)` laeuft
   **THEN** ruft er **genau einmal** `as_enqueue_async_action('spreadconnect/handle_article_removed', ['article_id'=>'ART-77'], 'spreadconnect')` auf (Hook-Name + Args-Shape laut architecture.md AS-Inventory Z. 548 + Service-Map Z. 373). Kein direkter `wp_update_post`-Call im Handler — Status-Flip ist Job-Verantwortung (AC-5/6).

4) **GIVEN** ein Webhook-Payload, dessen `data.entity.id` fehlt (Key abwesend), leerer String oder nicht vom Typ `string`
   **WHEN** `ArticleEventHandler::handle($payload)` laeuft
   **THEN** wirft er **keine** Exception (Permanent-Validation-Failure in Webhook-Pipeline, kein AS-Retry sinnvoll), loggt eine Warning mit Source `spreadconnect-webhook-receiver` und Format `'ArticleEventHandler: missing or invalid entity.id event_type={...}'`, und ruft **kein** `as_enqueue_async_action`. Kein Re-Throw — Slice 17 ProcessWebhookEventJob markiert Row als `success` (Validation-Verantwortung liegt im Handler).

5) **GIVEN** `Catalog\ArticleRemovedJob::handle(['article_id'=>'ART-77'])` wird vom Action-Scheduler aufgerufen, und es existiert genau ein WC-Produkt mit Postmeta `_spreadconnect_article_id='ART-77'` (Reverse-Lookup wie Slice 22 AC-2)
   **WHEN** der Job laeuft
   **THEN** ruft er `wp_update_post(['ID'=>$productId, 'post_status'=>'draft'], true)` auf, schreibt Postmeta `_spreadconnect_sync_state='removed_in_sc'` (architecture.md Z. 292 Enum) sowie `_spreadconnect_last_sync` auf Unix-Timestamp, und kehrt ohne Exception zurueck. Reverse-Lookup nutzt `get_posts(['post_type'=>'product','meta_key'=>'_spreadconnect_article_id','meta_value'=>$articleId,'post_status'=>['publish','draft','private'],'numberposts'=>1])` exakt wie Slice 22 Constraint dokumentiert. Original `_spreadconnect_article_id` bleibt als Audit-Anker erhalten (architecture.md Z. 281: "meta retained for audit").

6) **GIVEN** `ArticleRemovedJob::handle(['article_id'=>'ART-XX'])` wird aufgerufen, aber Reverse-Lookup liefert **kein** WC-Produkt (Article war nie linked oder bereits manuell entfernt)
   **WHEN** der Job laeuft
   **THEN** wirft er **keine** Exception (kein AS-Retry sinnvoll), loggt eine Info-Zeile mit Source `spreadconnect-sync-job` und Format `'ArticleRemovedJob: no WC product found article_id={...} — skipping'` und kehrt frueh zurueck. Keine `wp_update_post`-Call.

7) **GIVEN** `ArticleRemovedJob::handle(['article_id'=>'ART-77'])` und Reverse-Lookup findet ein WC-Produkt, dessen `post_status` bereits `draft` ist (Re-Run nach manueller Wiederholung oder doppelter Webhook)
   **WHEN** der Job laeuft
   **THEN** ist die Operation idempotent: `wp_update_post(...,'post_status'=>'draft')` darf erneut aufgerufen werden (WC akzeptiert No-Op), Postmeta `_spreadconnect_sync_state='removed_in_sc'` wird re-written. **Niemals** `wp_delete_post`, `wp_trash_post` oder Status-Wechsel zu `trash`/`auto-draft` (architecture.md Z. 736 Risk-Mitigation; AC ist hartes Verbot).

8) **GIVEN** `wp_update_post` liefert `WP_Error` (z.B. WC-Plugin throws auf invalid product type) ODER `0` (update failed)
   **WHEN** der Job laeuft
   **THEN** wirft er `\SpreadconnectPod\Catalog\ArticleRemovedException` (`extends \RuntimeException`) mit Message `'wp_update_post failed for article_id={...} product_id={...}: {error}'`. Action-Scheduler retried den Job (1m/5m/15m laut architecture.md Z. 548). Permanent-vs-Transient-Differenzierung wird hier NICHT modelliert — alle `wp_update_post`-Failures gelten als transient (DB-/Plugin-Hick-Ups), Slice 37 entscheidet via Retry-Counter ueber DLQ-Eintrag.

9) **GIVEN** Plugin-Bootstrap aus Slice 02 ist initialisiert
   **WHEN** der Bootstrap-Hook `init()` laeuft
   **THEN** ist `add_action('spreadconnect/handle_article_removed', [ArticleRemovedJob::class, 'handleStatic'], 10, 1)` mit Priority `10` und genau **einem** Argument-Slot registriert (analog Slice 23 AC-8 `sync_article` und Slice 24 AC-9 `sync_catalog`). Idempotent (Slice 02 AC-5 Pattern).

10) **GIVEN** der Stub-`ArticleEventHandler` aus Slice 17 (`final class` mit `public static function handle(array $payload): void` als reine Logging-Methode)
    **WHEN** Slice 25 die Datei `includes/Webhook/ArticleEventHandler.php` ueberschreibt
    **THEN** bleibt die Klassen-/Method-Signatur unveraendert (`final class ArticleEventHandler` mit `public static function handle(array $payload): void`), damit der `ProcessWebhookEventJob`-Dispatcher aus Slice 17 ohne Edit weiter funktioniert. Slice 17 Integration-Contract wird erfuellt (Slice 17 Integration Contract Z. 235-236: "vollstaendig ueberschrieben in Slice 25").

11) **GIVEN** der Slice-17-Test-Stub fuer `ArticleEventHandler` (verifiziert nur Logging)
    **WHEN** Slice 25 die Logik ersetzt
    **THEN** schreibt der `Webhook\ProcessWebhookEventJob`-Dispatcher (Slice 17, **kein Edit** noetig) bei `Article.*`-Webhook-Events weiterhin `processing_status='success'` auf die Webhook-Log-Row, sofern der neue Handler nicht wirft. Slice 17 ACs 4/6 bleiben unveraendert gruen.

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey mocked `as_enqueue_async_action`, `wp_update_post`, `update_post_meta`, `get_posts`, `__()`, `wc_get_logger`. `WC_Error`/`WP_Error` sind ueber Patchwork-Stubs verfuegbar (Bootstrap aus Slice 02 ff.). Slice-17-`ProcessWebhookEventJob`-Test darf NICHT erneut laufen — Slice 25 testet ausschliesslich Handler-Dispatch + ArticleRemovedJob-Status-Flip.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-04-article-removed-job.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests\Catalog;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class ArticleRemovedJobAndHandlerTest extends TestCase
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

    // AC-1: Article.added -> sync_article enqueued mit article_id + run_id=null
    public function test_article_added_enqueues_sync_article_with_null_run_id(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Article.updated -> sync_article enqueued (gleicher Pfad wie added)
    public function test_article_updated_enqueues_sync_article(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Article.removed -> handle_article_removed enqueued, KEIN sync_article
    public function test_article_removed_enqueues_handle_article_removed(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: Action-Scheduler-Group ist 'spreadconnect'
    public function test_handler_uses_spreadconnect_action_scheduler_group(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Fehlende entity.id -> Warning-Log + kein Enqueue + keine Exception
    public function test_handler_logs_warning_and_skips_when_entity_id_missing(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: entity.id leerer String -> kein Enqueue
    public function test_handler_skips_when_entity_id_is_empty_string(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Existierendes WC-Produkt -> wp_update_post(post_status=draft) + sync_state=removed_in_sc
    public function test_remove_job_sets_wc_product_to_draft_via_reverse_lookup(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: _spreadconnect_article_id bleibt als Audit-Anker erhalten
    public function test_remove_job_retains_article_id_meta_for_audit(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: _spreadconnect_sync_state wird auf 'removed_in_sc' gesetzt
    public function test_remove_job_writes_sync_state_removed_in_sc(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Reverse-Lookup leer -> Info-Log + frueher Return, keine update-Call
    public function test_remove_job_skips_when_no_wc_product_found(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Bereits draft -> idempotente No-Op (kein delete, kein trash)
    public function test_remove_job_is_idempotent_on_already_draft_product(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: NIEMALS wp_delete_post oder wp_trash_post
    public function test_remove_job_never_calls_wp_delete_post(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: wp_update_post liefert WP_Error -> ArticleRemovedException
    public function test_remove_job_throws_on_wp_update_post_error(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: wp_update_post liefert 0 -> ArticleRemovedException
    public function test_remove_job_throws_on_wp_update_post_zero(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Bootstrap registriert add_action('spreadconnect/handle_article_removed', ...)
    public function test_bootstrap_registers_handle_article_removed_action_hook(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: ArticleEventHandler-Klasse hat unveraenderte Signatur (Slice-17-Vertrag)
    public function test_article_event_handler_signature_matches_slice_17_contract(): void
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
| `slice-17-process-webhook-event-job` | Dispatcher ruft `Webhook\ArticleEventHandler::handle(array $payload): void` per `Article.*`-Prefix-Match | static method dispatch | Slice 17 AC-4 verifiziert Mapping; Slice 25 ueberschreibt die Stub-Klasse ohne Signatur-Aenderung. |
| `slice-17-process-webhook-event-job` | Datei `includes/Webhook/ArticleEventHandler.php` (Stub) | Existing file | Slice 25 ueberschreibt **nur** den Method-Body; Klassen-Header + Namespace + Method-Signature unveraendert. |
| `slice-23-sync-article-job` | Action-Hook `spreadconnect/sync_article` registriert + Args-Shape `['article_id'=>string, 'run_id'=>int?]` | Hook-Registration + Args-Vertrag | Slice 23 AC-8 + AC-10 (run_id=null toleriert). Slice 25 schedult diesen Hook bei `Article.added`/`updated`. |
| `slice-22-product-mapper` | WC-Postmeta-Schema (`_spreadconnect_article_id`, `_spreadconnect_sync_state` Enum mit `removed_in_sc`) + Reverse-Lookup-Pattern (`get_posts` mit `meta_query`) | DB-Resource + Pattern | Slice 22 Constraint dokumentiert Lookup-Pattern; Slice 25 wendet ihn 1:1 an. |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::init()`-Hook-Lifecycle | Bootstrap-Lifecycle | Slice 25 ergaenzt `add_action('spreadconnect/handle_article_removed', ...)` analog Slice 23/24. |
| `slice-24-sync-catalog-job` | Konvention `as_enqueue_async_action(..., 'spreadconnect')` Group + `handleStatic`-Bridge-Pattern | Pattern | Slice 24 AC-2 + AC-9. Slice 25 nutzt identische Group + Bridge. |
| `slice-07-http-client-base` | (kein direkter Konsum) | — | ArticleRemovedJob ruft KEINE SC-API; `Article.removed` ist eine reine WC-seitige Status-Mutation. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Webhook\ArticleEventHandler::handle` | static method (final implementation) | `Webhook\ProcessWebhookEventJob` (Slice 17 Dispatcher) | `public static function handle(array $payload): void` (unveraendert seit Slice 17) |
| `SpreadconnectPod\Catalog\ArticleRemovedJob::handle` | instance method (Action-Scheduler-Handler) | Action-Scheduler-Worker via `add_action`-Bridge | `public function handle(array $args): void` mit `$args=['article_id'=>string]` |
| `SpreadconnectPod\Catalog\ArticleRemovedJob::handleStatic` | static method (Bridge fuer `add_action`) | Bootstrap-Hook-Registration | `public static function handleStatic(array $args): void` |
| `SpreadconnectPod\Catalog\ArticleRemovedException` | Exception class | `Failure\RetryPolicyListener` (Slice 37 — AS-Failure-Hook nach 3 Retries -> DLQ) | `class ArticleRemovedException extends \RuntimeException` |
| Action-Hook `spreadconnect/handle_article_removed` | Action-Hook (registered) | Action-Scheduler-Worker; `Webhook\ArticleEventHandler` (Producer) | Hook-Args: `array $args=['article_id'=>string]` |
| WC-Produkt-Postmeta `_spreadconnect_sync_state='removed_in_sc'` | DB-Resource | `slice-34-product-meta-box` (Inline-UX-Badge); `slice-32-order-meta-box` (Order-Note bei offenen Orders, falls in Slice 30 implementiert); `slice-36-stock-cache` (skip-Stock-Sync fuer removed Articles) | Enum-Wert wie architecture.md Z. 292. |

> **Mount-Point-Check:** `ArticleRemovedJob` wird durch `add_action`-Edit in `Bootstrap\Plugin::init()` an Action-Scheduler gehaengt (Deliverable). `ArticleEventHandler` wird durch Slice-17-Dispatcher konsumiert — kein zusaetzlicher Mount-Edit noetig (Slice 17 ProcessWebhookEventJob ruft bereits per Klassen-Konstante).

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Catalog/ArticleRemovedJob.php` — Neue Klasse `final class SpreadconnectPod\Catalog\ArticleRemovedJob` mit `public function handle(array $args): void` (Reverse-Lookup + `wp_update_post(post_status=draft)` + Postmeta-Update; siehe ACs 5-8) und `public static function handleStatic(array $args): void` (Bridge fuer `add_action`). Inklusive `final class ArticleRemovedException extends \RuntimeException` (in derselben Datei oder Sibling — analog `ProductMapperException` aus Slice 22).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Webhook/ArticleEventHandler.php` — **Vollstaendiger Replace** der Slice-17-Stub-Klasse: `final class SpreadconnectPod\Webhook\ArticleEventHandler` mit `public static function handle(array $payload): void` — Dispatch nach `eventType` zu `as_enqueue_async_action('spreadconnect/sync_article', ...)` (added/updated) bzw. `as_enqueue_async_action('spreadconnect/handle_article_removed', ...)` (removed). Validation `entity.id` als non-leerer String (siehe ACs 1-4). Klassen-Signatur unveraendert (Slice-17-Dispatcher-Vertrag).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Webhook/ProcessWebhookEventJob.php` — **Dispatcher-Wiring-Edit** laut Slim-Slices Spec: stellt sicher, dass `Article.*`-Prefix-Match (Slice 17 AC-4) den **echten** `ArticleEventHandler::handle($payload)` aufruft (nicht mehr den Stub). Falls Slice 17 bereits per Klassen-Konstante / Class-FQN dispatched, ist dieser Edit ein No-Op-Verifier-Pass — Constraints unten dokumentieren die Default-Annahme. Edit MUSS Slice-17 AC-1 bis AC-9 unveraendert lassen.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Ergaenzt `add_action('spreadconnect/handle_article_removed', [ArticleRemovedJob::class, 'handleStatic'], 10, 1)` analog zur bestehenden `sync_article` (Slice 23) / `sync_catalog` (Slice 24) / `process_webhook_event` (Slice 17) Hook-Registration.
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-04-article-removed-job.php` wird vom Test-Writer-Agent erstellt. Keine Edits an `WebhookLogRepo` (Slice 16), `SpreadconnectClient` (Slice 07/10), `ProductMapper` (Slice 22), `SyncArticleJob` (Slice 23) oder `SyncCatalogJob` (Slice 24).

---

## Constraints

**Scope-Grenzen:**
- **NIEMALS `wp_delete_post`**, `wp_trash_post` oder `EMPTY_TRASH_DAYS=0`-Tricks. Status-Flip ausschliesslich `post_status='draft'` (architecture.md Z. 281, 736 — explizit als Anti-Pattern markiert; PHPUnit-AC asserted das Verbot).
- **Kein** SC-API-Call im `ArticleRemovedJob` — SC hat den Article bereits geloescht; WC-Produkt-Update ist die einzige Aktion.
- **Kein** Order-Note-Schreiben bei offenen Orders mit dem entfernten Article (Discovery Z. 178 `+ Order-Note bei zugehoerigen offenen Orders`) — diese UX-Erweiterung ist OUT-OF-SCOPE-MVP-Polish (Slice 32 Order-Edit-Meta-Box zeigt removed_in_sc-Badge stattdessen). Implementer darf einen TODO-Kommentar setzen.
- **Kein** `FailedOpsRepo`-Insert — Slice 37 (`Failure\FailedOpsRepo`). `ArticleRemovedException` wird durch AS-Retry abgefangen; nach 3 Retries uebernimmt Slice 37 RetryPolicyListener.
- **Kein** Stock-Cache-Invalidation im ArticleRemovedJob — Slice 36 (`Stock\StockCache`) entscheidet eigenstaendig anhand `_spreadconnect_sync_state='removed_in_sc'`.
- **Keine UI-Aenderungen** — `removed_in_sc`-Badge in Product-Edit ist Slice 34, Catalog-UI ist Slice 26, Webhook-Log-UI ist Slice 41.
- **Keine Schema-Aenderungen** — `_spreadconnect_sync_state`-Enum-Wert `removed_in_sc` ist bereits in architecture.md Z. 292 deklariert; kein Postmeta-Migration noetig.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in beiden neuen Dateien.
- `final class ArticleRemovedJob` und `final class ArticleEventHandler` (nicht erweiterbar).
- `ArticleEventHandler::handle` ist `public static` (Slice-17-Vertrag); `ArticleRemovedJob::handle` ist `public` (Instance-Method, Konstruktor-DI moeglich), `handleStatic` als statische Bridge fuer `add_action`.
- **Reverse-Lookup-Konvention** (AC-5): `get_posts(['post_type'=>'product','meta_key'=>'_spreadconnect_article_id','meta_value'=>$articleId,'post_status'=>['publish','draft','private'],'numberposts'=>1])` — exakt wie Slice 22 Constraint dokumentiert. **Kein** rohes `$wpdb`-Query auf `wp_postmeta`.
- **`wp_update_post`-Aufruf** (AC-5): `wp_update_post(['ID'=>$productId, 'post_status'=>'draft'], true)` — zweites Argument `true` aktiviert WP_Error-Return statt 0/false-Mix.
- **Postmeta-Update-Reihenfolge** (AC-5): Erst `wp_update_post`, dann `update_post_meta($productId, '_spreadconnect_sync_state', 'removed_in_sc')`, dann `update_post_meta($productId, '_spreadconnect_last_sync', time())`. Bei Failure von `wp_update_post` Throw vor den Meta-Writes.
- **Action-Scheduler-Group** (AC-1/2/3): `'spreadconnect'` als 3. Argument zu `as_enqueue_async_action` — identisch zu Slice 24 AC-2 und Slice 23 AC-2.
- **Args-Shape** fuer `sync_article`-Enqueue: `['article_id'=>$entityId, 'run_id'=>null]` — `run_id=null` triggert Slice 23 AC-10 (kein History-Counter, kein appendDetail; Slice 24 AC-8 ueberspringt Increment).
- **Args-Shape** fuer `handle_article_removed`-Enqueue: `['article_id'=>$entityId]` — keine `run_id` (no-Catalog-Run-Context).
- **`entity.id`-Validation** (AC-4): `is_string($payload['data']['entity']['id'] ?? null) && $payload['data']['entity']['id'] !== ''`. Bei Failure: Warning-Log + early return; **kein** Throw (waere kontraproduktiv — Webhook-Receiver markiert Row als success, kein Retry).
- **Logging-Sources** (architecture.md Z. 532): `spreadconnect-webhook-receiver` fuer Handler-Logs (Validation, Dispatch); `spreadconnect-sync-job` fuer ArticleRemovedJob-Logs (Lookup, Status-Flip).
- **`wc_get_logger()` (NICHT `error_log`)** — analog Slice 17 Constraint. WcLoggerAdapter (Slice 42) ersetzt direkten `wc_get_logger`-Aufruf spaeter; bis dahin direkter Aufruf erlaubt.
- **`add_action`-Hook in `Plugin::init()`** mit Priority `10`, accepted_args `1`. Argument-Shape muss exakt zur `as_enqueue_async_action(..., [$args])`-Sequenz passen.
- **Slice-17-Dispatcher-Edit ist minimal**: Falls `ProcessWebhookEventJob` bereits per `ArticleEventHandler::handle($payload)` (Class-FQN) dispatched (Slice 17 AC-4 + Integration-Contract Z. 235), bleibt der Code unveraendert — Slice 25 ueberschreibt nur den Klassen-Body. Falls Slice 17 stattdessen einen Closure-/Service-Map-basierten Dispatch nutzt, MUSS dieser Slice den Map-Eintrag fuer `Article.*` auf den **echten** Handler updaten. Default-Annahme: FQN-Dispatch (Slice 17 Constraint dokumentiert das Pattern).

**Reuse:**

Slice 25 baut ausschliesslich auf bestehenden Bausteinen auf; **keine** Neuimplementierung von Webhook-Pipeline, Reverse-Lookup-Helper oder Hook-Registration:

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Webhook/ArticleEventHandler.php` (Slice 17 Stub) | **Replace-Target** — Body wird vollstaendig ersetzt; Klassen-Header + Namespace + Method-Signatur bleiben unveraendert (Slice-17-Dispatcher-Vertrag). |
| `wordpress/plugins/spreadconnect-pod/includes/Webhook/ProcessWebhookEventJob.php` (Slice 17) | **Edit-Target (minimal/no-op)** — Verifiziert, dass Article-Prefix-Match den echten Handler aufruft. Default: kein Code-Edit noetig. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + Edits aus Slice 03/04/05/06/17/23/24) | **Edit-Target** — Ergaenzt `add_action('spreadconnect/handle_article_removed', ...)` analog vorhandener `sync_article`/`sync_catalog`/`process_webhook_event`-Hook-Registrations. |
| `wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncArticleJob.php` (Slice 23 + Slice 24 Counter-Edit) | **Reuse, no edit** — Slice 23 toleriert mehrfach-Schedule + `run_id=null`. Slice 25 enqueued den `sync_article`-Hook ohne weitere Job-Aenderungen. |
| `wordpress/plugins/spreadconnect-pod/includes/Catalog/ProductMapper.php` (Slice 22) | **Reuse, no edit** — Slice 22 dokumentiert Reverse-Lookup-Pattern (`get_posts`-mit-`meta_query`); Slice 25 wendet identisches Pattern an. |
| WC-Postmeta `_spreadconnect_article_id` + `_spreadconnect_sync_state` (Slice 22 schreibt bei `upsert`) | **Reuse** — Reverse-Lookup-Anker und Status-Enum-Wert bereits etabliert. |
| Action-Scheduler-Hook `spreadconnect/sync_article` (Slice 23 registriert) | **Reuse** — Slice 25 enqueued bei `Article.added/updated`. |
| Konstruktor-DI- und `handleStatic`-Bridge-Pattern aus Slice 23/24 | **Reuse** — Slice 25 ArticleRemovedJob spiegelt das Pattern (kein neues Bridge-Konzept). |
| Architecture Service-Map Z. 373/382 + AS-Inventory Z. 548 + Webhook-Mapping Z. 842-844 | **Single Source of Truth** fuer Handler-Klassennamen, Hook-Namen, Args-Shapes. |

**Referenzen:**
- Architecture: `architecture.md` -> Service-Map Zeile `Catalog\ArticleRemovedJob` (Z. 373: "Sets WC-product `draft`, leaves order history intact"); Service-Map Zeile `Webhook\ArticleEventHandler` (Z. 382: "Article.added/updated/removed -> schedule sync_article / handle_article_removed"); AS-Inventory Zeile `spreadconnect/handle_article_removed` (Z. 548: one-shot, Concurrency 1 per article-id, Retry 1m/5m/15m); WC-Postmeta-Schema (Z. 281, 287, 292: `_spreadconnect_article_id` Reverse-Lookup-Idiom + `_spreadconnect_sync_state` Enum mit `removed_in_sc`); Risks-Tabelle Z. 736 (`Article.removed` triggers WC product deletion -> "**forbidden**, never `wp_delete_post`"); Webhook-DTO `WebhookEvent` Z. 175 (`eventType` Enum); Webhook-Mapping-Tabelle Z. 842-844 (`Article.added/updated -> sync_article`, `Article.removed -> handle_article_removed`).
- Discovery: `discovery.md` -> Slice 4 "Catalog-Sync" Bullet (Z. 178: "Article.removed -> handle_article_removed -> WC-Status draft, NICHT loeschen"); Constraint Z. 581 ("`Article.removed` setzt WC-Produkt-Status auf `draft`. **Niemals** `delete`."); Sync-State-Tabelle Z. 543 (`synced -> Webhook Article.removed -> WC-Status draft + Order-Note ... -> removed_in_sc`); Failed-Ops Z. 723 (op_type-Enum enthaelt `handle_article_removed`).
- Slim-Slices: `slices/slim-slices.md` -> Slice-25-Eintrag (Z. 418-426; Done-Signal "Article.removed-Webhook -> WC-Product-Status draft (kein Delete); Article.added/updated -> sync_article enqueued").
- Vorgaenger Slice 17: `slices/slice-17-process-webhook-event-job.md` -> Integration Contract Z. 235 ("`ArticleEventHandler::handle` ... vollstaendig ueberschrieben in Slice 25"); AC-4 (Prefix-Match `Article.` -> `ArticleEventHandler`); AC-10 (Stub-Signatur).
- Vorgaenger Slice 22: `slices/slice-22-product-mapper.md` -> AC-2 + Constraint (Reverse-Lookup-Pattern via `get_posts` + `meta_query`).
- Vorgaenger Slice 23: `slices/slice-23-sync-article-job.md` -> Hook-Registration-Pattern (`handleStatic`); Args-Shape `['article_id'=>string,'run_id'=>int?]`; `run_id=null`-Toleranz.
- Vorgaenger Slice 24: `slices/slice-24-sync-catalog-job.md` -> Action-Scheduler-Group `'spreadconnect'`; `add_action`-Edit-Pattern in `Bootstrap\Plugin.php`.
- Folge Slice 30: `slices/slim-slices.md` Z. 477-485 (`OrderEventHandler` wird in Slice 30 analog ersetzt — Slice 25 etabliert das Replace-Pattern).
- Folge Slice 37: `slim-slices.md` Slice-37 (`FailedOpsRepo` faengt `ArticleRemovedException` nach 3 AS-Retries als DLQ-Eintrag).
- Wireframes: `wireframes.md` — **nicht direkt relevant** (Job + Handler ohne UI-Touch; `removed_in_sc`-Badge ist Slice 34 Product-Meta-Box).
