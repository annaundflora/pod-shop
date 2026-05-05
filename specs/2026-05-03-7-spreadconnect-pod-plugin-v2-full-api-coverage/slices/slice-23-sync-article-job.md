# Slice 23: Sync-Article-Job (Per-Article)

> **Slice 23 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-23-sync-article-job` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-10-endpoint-methods", "slice-21-image-sideloader", "slice-22-product-mapper"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + Action-Scheduler + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `as_*`-Action-Scheduler-Funktionen, `$wpdb`-Stub fuer `SyncHistoryRepo`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: `WooCommerce -> Spreadconnect -> Catalog -> Sync Now` triggert Job-Sequenz) |
| **Health Endpoint** | `n/a` (Action-Scheduler-Job-Handler) |
| **Mocking Strategy** | `mock_external` (`SpreadconnectClient`, `ImageSideloader`, `ProductMapper` als Konstruktor-Injectables; `SyncHistoryRepo` mit `$wpdb`-Stub via Brain\Monkey) |

---

## Ziel

Verkettet die in Slice 10/21/22 etablierten Bausteine zur **Per-Article-Sync-Sequenz** als Action-Scheduler-Hook-Handler `spreadconnect/sync_article`. Liest pro Article-ID Article-Detail + ProductType (cached) + Previews aus SC, sideloaded Bilder, ruft `ProductMapper::upsert()` auf und schreibt einen `sync_history.details[]`-JSON-Eintrag mit Status `created|updated|skipped|error|partial`. Liefert ausserdem den schmalen `SyncHistoryRepo` (nur Details-JSON-Append; Counter-Increment kommt in Slice 24).

---

## Acceptance Criteria

1) **GIVEN** ein Action-Scheduler-Worker dispatched `spreadconnect/sync_article` mit Args `['article_id'=>'ART-1', 'run_id'=>42]`, und `SpreadconnectClient::getArticle('ART-1')` liefert ein gueltiges `ArticleDetail`-DTO mit `productTypeId='PT-7'`, `designId='D-1'`, `hotspotId='H-1'`, einer nicht-leeren `viewIds`-Liste und nicht-leerer `variants`-Liste, und es existiert noch **kein** WC-Produkt mit `_spreadconnect_article_id='ART-1'`
   **WHEN** `Catalog\SyncArticleJob::handle(['article_id'=>'ART-1', 'run_id'=>42])` aufgerufen wird
   **THEN** wird genau diese Sequenz in dieser Reihenfolge ausgefuehrt: (1) `getArticle('ART-1')`, (2) `getProductType('PT-7')` (cached, siehe AC-7), (3) `createPreviews('PT-7', 'D-1', 'H-1', $viewIds)`, (4) pro Preview-URL `ImageSideloader::sideload($url, 0)` (Pre-Attach: `0` als post_id, da Produkt noch nicht existiert — Re-Attach via `update_post_meta($attachId, '_thumbnail_id', ...)` ist Mapper-Verantwortung), (5) `ProductMapper::upsert($article, $productType, $attachmentIds)` (siehe Slice 22 Signatur). Anschliessend `SyncHistoryRepo::appendDetail(42, ['article_id'=>'ART-1', 'title'=>$article->title, 'status'=>'created', 'notes'=>null])`.

2) **GIVEN** dieselbe Sequenz wie in AC-1, aber es existiert bereits ein WC-Produkt mit `_spreadconnect_article_id='ART-1'` (Reverse-Lookup-Hit aus Slice 22)
   **WHEN** `handle(...)` aufgerufen wird
   **THEN** wird der Detail-Eintrag mit `status='updated'` (statt `'created'`) geschrieben. Die Unterscheidung erfolgt **bevor** `ProductMapper::upsert()` aufgerufen wird (Pre-Lookup auf `_spreadconnect_article_id` durch den Job; siehe Constraint "Status-Klassifikation").

3) **GIVEN** `SpreadconnectClient::createPreviews(...)` liefert eine Preview-Liste, aber **mindestens ein** `ImageSideloader::sideload($url, 0)`-Aufruf returned `WP_Error` (siehe Slice 21 AC-5)
   **WHEN** `handle(...)` aufgerufen wird
   **THEN** wird (a) der Mapper trotzdem mit den **erfolgreich** sideloaded Attachment-IDs aufgerufen (leere Liste zulaessig — Mapper verzichtet dann auf `set_image_id`/`set_gallery_image_ids`, siehe Slice 22 AC-5), (b) der Detail-Eintrag mit `status='partial'` und `notes` = String-Repraesentation des `WP_Error->get_error_message()` geschrieben (siehe architecture.md "Failure Mode Map" -> "Image-sideload failure"), (c) zusaetzlich Postmeta `_spreadconnect_sync_state='partial'` auf dem WC-Produkt gesetzt (siehe architecture.md "WC-Product Meta" -> `_spreadconnect_sync_state` enum). Der Job wirft **keine** Exception — Sync laeuft fuer andere Articles weiter.

4) **GIVEN** `SpreadconnectClient::getArticle('ART-1')` wirft `SpreadconnectClientError` (4xx — z. B. 404 weil Article in SC geloescht wurde, oder 403)
   **WHEN** `handle(...)` aufgerufen wird
   **THEN** wird (a) der Detail-Eintrag mit `status='error'` und `notes` = `$e->getMessage()` geschrieben, (b) die Exception **re-thrown** (oder als `\RuntimeException` re-wrapped), sodass Action-Scheduler den Job als `failed` markiert. Action-Scheduler retried `SpreadconnectClientError` **nicht** (siehe architecture.md "AS Hook Inventory" -> "Fail-fast on 4xx"; in Slice 37 wird daraus ein `FailedOpsRepo`-Eintrag — in Slice 23 reicht das Re-Throw).

5) **GIVEN** `SpreadconnectClient::getArticle(...)` oder `createPreviews(...)` wirft `SpreadconnectTransientError` (5xx/Network/Timeout)
   **WHEN** `handle(...)` aufgerufen wird
   **THEN** wird (a) **kein** Detail-Eintrag geschrieben (der Job wird ggf. retried — Detail wuerde sonst doppelt erscheinen), (b) die Exception unveraendert re-thrown, sodass Action-Scheduler den 1m/5m/15m-Retry-Cycle startet (siehe architecture.md "AS Retry Policy" -> `spreadconnect/sync_article`).

6) **GIVEN** `ProductMapper::upsert(...)` wirft `ProductMapperException` (z. B. leere Variants-Liste — Slice 22 AC-7)
   **WHEN** `handle(...)` aufgerufen wird
   **THEN** wird der Detail-Eintrag mit `status='error'` und `notes` = `$e->getMessage()` geschrieben; die Exception wird re-thrown (analog AC-4 — `ProductMapperException extends \RuntimeException`, kein AS-Retry).

7) **GIVEN** zwei aufeinanderfolgende `handle(...)`-Aufrufe innerhalb desselben Worker-Prozesses (z. B. Action-Scheduler-Batch-Run mit zwei Articles aus dem gleichen `productTypeId`)
   **WHEN** der zweite Aufruf `getProductType('PT-7')` benoetigt
   **THEN** liest der Job aus dem in architecture.md ("Outbound Endpoints" Z. 113) spezifizierten Transient `sc_pt_PT-7` (24 h TTL); ein zweiter `SpreadconnectClient::getProductType(...)`-Call wird **nicht** ausgeloest. Der Cache-Key-Aufbau `sc_pt_{id}` ist Vertragsbestandteil (Slice 36 Stock-Cache liest denselben Schluessel).

8) **GIVEN** der Plugin-Bootstrap in Slice 02 ist initialisiert
   **WHEN** der Bootstrap-Hook `init_actions()` (oder Aequivalent) laeuft
   **THEN** ist `add_action('spreadconnect/sync_article', [SyncArticleJob::class, 'handleStatic'], 10, 1)` registriert (Action-Scheduler ruft den Hook mit dem Args-Array als erstem Parameter auf — die statische Bridge-Methode resolved den Service via Container/Factory und ruft `handle($args)`). Default-Action-Group ist `spreadconnect` (architecture.md "AS Hook Inventory" Anmerkung).

9) **GIVEN** `SyncHistoryRepo::appendDetail(int $runId, array $detail)` wird aufgerufen mit einem `run_id`, zu dem bereits ein History-Row mit `details=[{...}, {...}]` existiert
   **WHEN** ein neuer Detail-Eintrag angefuegt wird
   **THEN** wird die `details`-JSON-Spalte atomar gelesen, der neue Eintrag angefuegt, und der erweiterte Array zurueckgeschrieben (`$wpdb->update($table, ['details'=>json_encode($newArr)], ['id'=>$runId])`). Schema des Eintrags MUSS architecture.md "wp_spreadconnect_sync_history" -> "details JSON shape" entsprechen (Keys: `article_id`, `title`, `status`, `notes`).

10) **GIVEN** `run_id=null` wird im Args-Array uebergeben (Webhook-getriggerter Per-Article-Sync ohne Catalog-Run, siehe slim-slices.md Slice-25 `Article.added/updated`)
    **WHEN** `handle(['article_id'=>'ART-1'])` (kein `run_id`) aufgerufen wird
    **THEN** wird die komplette Sync-Sequenz unveraendert ausgefuehrt, aber `SyncHistoryRepo::appendDetail()` wird **nicht** aufgerufen (kein Run, keine History). Postmeta `_spreadconnect_sync_state` und `_spreadconnect_last_sync` werden trotzdem geschrieben (Mapper-Verantwortung; Slice 22 AC-1).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey mocked `as_*`-Funktionen + `$wpdb` (insert/update/get_var). `SpreadconnectClient`, `ImageSideloader`, `ProductMapper` werden als Konstruktor-Argumente in `SyncArticleJob` injiziert (DI-Pattern wie Slice 22) und im Test mit Mockery-Mocks ersetzt. Der Test-Writer ergaenzt minimale `WP_Error`-Stubs in `tests/bootstrap/` falls nicht vorhanden.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-04-sync-article-job.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests\Catalog;

use PHPUnit\Framework\TestCase;

final class SyncArticleJobTest extends TestCase
{
    // AC-1: Sequenz getArticle -> getProductType -> createPreviews -> sideload -> upsert; Detail status=created
    public function test_handle_executes_full_sequence_and_writes_created_detail(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Reihenfolge der API-Calls verifiziert (Mockery::ordered)
    public function test_handle_calls_endpoints_in_documented_order(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Bestehendes Produkt mit _spreadconnect_article_id -> status=updated
    public function test_handle_writes_updated_detail_when_product_exists(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Image-Sideload-Failure -> status=partial, _spreadconnect_sync_state=partial
    public function test_handle_writes_partial_detail_on_image_sideload_failure(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: Mapper wird trotz Image-Failure mit erfolgreichen Attachment-IDs aufgerufen
    public function test_handle_calls_mapper_with_partial_attachment_list(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: Job wirft keine Exception bei Image-Failure
    public function test_handle_does_not_throw_on_image_sideload_failure(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: SpreadconnectClientError (4xx) -> status=error, Detail geschrieben, dann re-thrown
    public function test_handle_writes_error_detail_and_rethrows_client_error(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: SpreadconnectTransientError (5xx) -> KEIN Detail-Insert, re-thrown
    public function test_handle_rethrows_transient_error_without_detail(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: ProductMapperException -> status=error, re-thrown
    public function test_handle_writes_error_detail_on_product_mapper_exception(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: getProductType wird beim zweiten Article mit selbem PT nur 1x aufgerufen (Transient sc_pt_{id})
    public function test_handle_caches_product_type_via_transient(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Bootstrap registriert add_action('spreadconnect/sync_article', ...)
    public function test_bootstrap_registers_sync_article_action_hook(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: SyncHistoryRepo::appendDetail merged neuen Eintrag in bestehende details-JSON
    public function test_sync_history_repo_appends_detail_to_existing_json_array(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-9: appendDetail-Schema entspricht architecture.md (Keys article_id, title, status, notes)
    public function test_sync_history_repo_detail_schema_matches_architecture(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: run_id=null -> kein appendDetail-Call, Sequenz laeuft trotzdem
    public function test_handle_skips_history_when_run_id_is_null(): void
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
| `slice-10-endpoint-methods` | `SpreadconnectClient::getArticle(string $id): ArticleDetail`, `getProductType(string $id): array`, `createPreviews(string $ptId, string $designId, string $hotspotId, array $viewIds): array` | Methoden auf `Api\SpreadconnectClient` | Slice 10 garantiert HTTP-Verb + Path + DTO-Mapping; Slice 23 ruft die typed Wrapper auf, keine rohen `request()`-Calls. |
| `slice-21-image-sideloader` | `Catalog\ImageSideloader::sideload(string $url, int $product_id): int\|\WP_Error` | Methode auf `Catalog\ImageSideloader` | Slice 21 AC-4/5/6 garantiert Cron-Context-Sicherheit + WP_Error-Pass-Through. |
| `slice-22-product-mapper` | `Catalog\ProductMapper::upsert(ArticleDetail $a, ProductTypeDetail $p, array $attachmentIds = []): int`, `ProductMapperException extends \RuntimeException` | Methode + Exception | Slice 22 AC-1/2/5/7 garantiert Idempotenz, leere Attachment-IDs zulaessig, Empty-Variants-Throw. |
| `slice-09-dto-value-objects` | `Api\Dto\ArticleDetail`, `Api\Dto\ProductTypeDetail`, `Api\Dto\Preview` (oder `Preview::fromArray`) | `final readonly` Value Objects | Slice 23 instantiiert `ProductTypeDetail` aus dem in Slice 10 dokumentierten raw assoc array — DTO-Mapping (`ProductTypeDetail::fromResponse($body)`) liegt im Job. |
| `slice-04-schema-dbdelta` | Custom Table `wp_spreadconnect_sync_history` mit Spalte `details LONGTEXT` | DB-Resource | Slice 04 erstellt das Schema; `SyncHistoryRepo` schreibt nur `UPDATE`-Statements auf eine bereits durch Slice 24 angelegte Row. |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::init()`-Hook-Lifecycle | Bootstrap-Lifecycle | Slice 23 erweitert `init_actions()` (oder Aequivalent) um `add_action('spreadconnect/sync_article', ...)`. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `Catalog\SyncArticleJob::handle` | Method (Action-Scheduler-Handler) | `slice-24-sync-catalog-job` (paginiert `GET /articles`, schedult per Article-ID `as_enqueue_async_action('spreadconnect/sync_article', ['article_id'=>$id, 'run_id'=>$runId])`); `slice-25-article-removed-job` (Webhook `Article.added/updated` schedult ohne `run_id`); `slice-34-product-meta-box-margin-stock` (Re-Sync-Button) | `public function handle(array $args): void` mit `$args = ['article_id'=>string, 'run_id'=>?int]` |
| `Catalog\SyncHistoryRepo::appendDetail` | Method | `slice-24-sync-catalog-job` (verwendet denselben Repo, erweitert ihn um Counter-Increment + Row-Insert) | `public function appendDetail(int $runId, array $detail): void` (keine Rueckgabe; wirft `\RuntimeException` bei Row-not-found) |
| Action-Hook `spreadconnect/sync_article` | Action-Hook (registered) | Action-Scheduler-Worker (`as_enqueue_async_action`-Caller in Slice 24/25/34) | Hook-Signatur: `array $args` (Action-Scheduler-Konvention: erster Parameter ist Args-Array). |

> **Hinweis Mount-Point-Check:** `SyncArticleJob` ist eine Application-Klasse, die durch den Action-Scheduler dispatched wird. Der Mount-Point ist die `add_action(...)`-Registrierung in `Bootstrap\Plugin` (siehe Deliverable "Edit `includes/Bootstrap/Plugin.php`"). Ohne diese Edit waere der Job tot (Hook-Registrierung fehlt -> AS findet den Handler nicht). `SyncHistoryRepo` wird in Slice 23 nur durch `SyncArticleJob` konsumiert; Slice 24 erweitert ihn und mountet ihn an `SyncCatalogJob`.

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncArticleJob.php` — `final class SyncArticleJob` mit Konstruktor-DI (`SpreadconnectClient`, `ImageSideloader`, `ProductMapper`, `SyncHistoryRepo`), `public function handle(array $args): void` (siehe ACs 1-7, 10) und `public static function handleStatic(array $args): void` (Bridge fuer `add_action`, instantiiert via Container/Factory aus `Bootstrap\Plugin`).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Catalog/SyncHistoryRepo.php` — `final class SyncHistoryRepo` mit `public function appendDetail(int $runId, array $detail): void` (AC-9). **Schmal**: nur Details-JSON-Append; Counter-Increment-Methoden (`incrementCreated`, `incrementUpdated`, `incrementSkipped`, `incrementError`, `markComplete`) werden in Slice 24 ergaenzt.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Ergaenzt `add_action('spreadconnect/sync_article', [SyncArticleJob::class, 'handleStatic'], 10, 1)` in der bestehenden Hook-Registration-Methode (Slice 02 etablierte den Skeleton; Slice 17 hat denselben Pattern fuer `process_webhook_event`).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-04-sync-article-job.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- **Kein** `as_enqueue_async_action(...)`-Aufruf — Slice 23 ist der **Konsument** des Hooks, nicht der Producer. Producer sind Slice 24 (Catalog-Job), Slice 25 (Webhook-Handler) und Slice 34 (Re-Sync-Button).
- **Kein** Counter-Increment auf `wp_spreadconnect_sync_history` (Spalten `created_count`/`updated_count`/...) — gehoert zu Slice 24 (`SyncCatalogJob` aggregiert nach Abschluss aller per-article-Jobs).
- **Kein** History-Row-Insert (`INSERT INTO wp_spreadconnect_sync_history`) — Row wird durch Slice 24 mit `state='in_progress'` angelegt; Slice 23 schreibt **nur** `UPDATE` auf die `details`-Spalte.
- **Kein** Hook-Registrierung fuer Webhook-Events (`Article.added/updated/removed`) — gehoert zu Slice 25 (`ArticleEventHandler`). Slice 23 liefert den Handler, der vom Webhook-Slice aufgerufen wird.
- **Kein** UI-Touch — Catalog-Sync-UI (Progress-Bar, "Sync Now"-Button) ist Slice 26.
- **Kein** `FailedOpsRepo`-Insert bei 4xx — `FailedOpsRepo` kommt erst in Slice 37; bis dahin reicht das Re-Throw, das Action-Scheduler als `failed` markiert (Slice 37 wired den `action_scheduler_failed_action`-Hook).
- **Keine** "Force re-pull images"-Logik — der `spreadconnect_pull_images`-Toggle (architecture.md "Settings" -> Default `true`) wird in Slice 23 als immer-true behandelt (MVP-Default). Re-Pull-Skip-Logik (siehe Discovery Z. 573) ist eine spaetere Optimierung; Slice 23 sideloaded immer alle Previews. **Optional:** Implementer darf `if ($product_exists && get_option('spreadconnect_pull_images') === false) { skip sideload; }` als Pre-Check ergaenzen — kein Blocker.
- **Keine** WC-Order-Touch — Job arbeitet ausschliesslich mit `WC_Product_Variable`/`WC_Product_Variation` (via Slice 22).
- **Keine** `error_log`/`var_dump`-Calls — Logging-Adapter (`WcLoggerAdapter`, Slice 42) ist noch nicht verfuegbar; bei Bedarf `error_log`-Stub mit `// FIXME(slice-42): replace with WcLoggerAdapter` erlaubt.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile nach `<?php`.
- `final class SyncArticleJob` und `final class SyncHistoryRepo` (nicht erweiterbar; Service-Wiring kommt via `Bootstrap\Plugin`).
- Method-Signaturen exakt wie im Integration Contract dokumentiert.
- **Status-Klassifikation** (AC-1/AC-2): Pre-Lookup vor `ProductMapper::upsert()`: `get_posts(['post_type'=>'product','meta_key'=>'_spreadconnect_article_id','meta_value'=>$articleId,'numberposts'=>1,'post_status'=>['publish','draft','private']])` -> wenn Match -> `status='updated'`; sonst -> `status='created'`. Nutzt denselben Reverse-Lookup-Pattern wie Slice 22 AC-2 (Single-Source-of-Truth).
- **ProductType-Cache** (AC-7): `get_transient('sc_pt_'.$productTypeId)` vor API-Call; bei Miss -> API-Call -> `set_transient('sc_pt_'.$productTypeId, $body, 24 * HOUR_IN_SECONDS)`. Cache-Key exakt `sc_pt_{id}` (architecture.md Z. 113); kein Praefix-Salting.
- **DTO-Mapping**: `getProductType()` liefert raw assoc array (Slice 10 Wrapper #18). `SyncArticleJob` instantiiert daraus `ProductTypeDetail::fromResponse($body)` aus Slice 09 — `ProductMapper::upsert()` erwartet das DTO (Slice 22 Method-Signatur).
- **Image-Sideload-Loop**: pro Preview-URL einen `sideload()`-Call. Erfolgreiche IDs in `int[]`-Array sammeln, `WP_Error`-Returns separat zaehlen (fuer `partial`-Status-Entscheidung). post_id-Argument ist `0` (Pre-Attach) — Mapper setzt `_thumbnail_id` und Gallery via `set_image_id`/`set_gallery_image_ids` (Slice 22 AC-5).
- **`SyncHistoryRepo::appendDetail`**: Implementation MUSS atomisch sein (kein klassisches Read-Modify-Write-Race) — Discovery Z. 573 deklariert "Last-Write-Wins" als akzeptabel, aber Implementer SOLL `$wpdb->prepare` + `JSON_ARRAY_APPEND(details, '$', JSON_OBJECT(...))` nutzen, falls MySQL ≥ 5.7 verfuegbar (architecture.md Stack: WP 6.9 -> MySQL ≥ 5.7 garantiert). Fallback (Read-JSON / decode / append / encode / update) ist akzeptabel; pro Slice 23 NICHT gefordert.
- **Bridge-Methode `handleStatic`**: Action-Scheduler ruft Hooks per `do_action` auf; `add_action(..., [Class, 'handleStatic'], 10, 1)` ist die idiomatische Bridge zu DI-Konstruktoren. `handleStatic` liest die DI-Container-Resolutions aus `Bootstrap\Plugin::getContainer()` (oder Aequivalent — Implementer-Entscheidung; konsistent mit Slice 17 ProcessWebhookEventJob-Pattern).
- **Idempotency-Skip nicht in Slice 23**: Discovery Z. 573 + Slice 22 garantiert, dass `ProductMapper::upsert()` selbst idempotent ist; der Job MUSS keine zusaetzlichen "wurde schon gesynct"-Pruefungen machen. Mehrfach-Schedules desselben `article_id` produzieren mehrere Detail-Eintraege (akzeptabel laut Discovery "Last-Write-Wins").
- **Postmeta `_spreadconnect_sync_state` auf `partial`** (AC-3): MUSS auf das WC-Produkt geschrieben werden, das durch `ProductMapper::upsert()` erstellt/aktualisiert wurde (`update_post_meta($productId, '_spreadconnect_sync_state', 'partial')`). Bei `status='created'`/`'updated'` setzt der Mapper bereits `_spreadconnect_sync_state='synced'` (Slice 22 AC-1) — Slice 23 ueberschreibt nur den `partial`-Fall.

**Reuse:**

Slice 23 verkettet bestehende Bausteine; **keine** Neuimplementierung von Client, Sideloader oder Mapper:

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08/10) | Konstruktor-Injection. Job ruft `getArticle`, `getProductType`, `createPreviews`. Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/ArticleDetail.php`, `ProductTypeDetail.php`, `Preview.php` (Slice 09) | DTO-Mapping fuer ProductType-Response (`ProductTypeDetail::fromResponse(...)`); Article-DTO kommt direkt aus `getArticle()`. Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Catalog/ImageSideloader.php` (Slice 21) | Konstruktor-Injection. Job ruft `sideload($url, 0)` pro Preview. Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Catalog/ProductMapper.php` (Slice 22) | Konstruktor-Injection. Job ruft `upsert($article, $productType, $attachmentIds)`. Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02/17) | Edit-Target: ergaenzt `add_action('spreadconnect/sync_article', ...)` in der bereits bestehenden Action-Hook-Registration-Methode (Slice 17 etablierte den Pattern fuer `process_webhook_event`). |
| `wordpress/plugins/spreadconnect-pod/composer.json` (Slice 02) | Bestehendes PSR-4-Mapping `SpreadconnectPod\\` -> `includes/`. Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/tests/bootstrap/bootstrap.php` (Slice 02-22) | Bestehender Brain\Monkey + Patchwork-Bootstrap. Test-Writer ergaenzt ggf. minimale `WP_Error`-Stubs — gehoert NICHT zu Slice 23 Deliverables. |

**Referenzen:**
- Architecture: `architecture.md` -> "Service Map" Zeile `Catalog\SyncArticleJob` (Layer Application, Verantwortlichkeit "One article: `GET /articles/{id}` + `GET /productTypes/{id}` + previews + media-sideload + WC-product upsert").
- Architecture: `architecture.md` -> "Action Scheduler — Hook Inventory" Zeile `spreadconnect/sync_article` (one-shot, Concurrency 5, Retry 1m/5m/15m on 5xx, Fail-fast on 4xx).
- Architecture: `architecture.md` -> "Outbound Endpoints" Z. 113 (`GET /productTypes/{id}` Caching: transient `sc_pt_{id}` 24 h).
- Architecture: `architecture.md` -> "wp_spreadconnect_sync_history" Schema + "details JSON-Schema" (Keys `article_id`, `title`, `status`, `notes` mit Status-Enum).
- Architecture: `architecture.md` -> "Failure Mode Map" Zeile "Image-sideload failure" (`status='partial'`, sync continues, row in `sync_history.details`).
- Architecture: `architecture.md` -> "WC-Product Meta" Zeile `_spreadconnect_sync_state` Enum (`synced`/`partial`/`error`/`removed_in_sc`).
- Discovery: `discovery.md` -> Slice 4 "Catalog-Sync" -> Flow B Punkt 4 (Per-Article-Sequenz: getArticle -> getProductType -> createPreviews -> media_sideload -> upsert).
- Discovery: `discovery.md` -> Error Paths Tabelle Zeilen B.4 (`createPreviews` 4xx -> error / `media_sideload_image()` Failure -> partial).
- Slim-Slices: `slices/slim-slices.md` -> Slice-23-Eintrag (Done-Signal: WC-Product + sync_history.details[i].status='created'/'updated'; Image-Failure -> partial; 4xx -> Job-Failure).
- Wireframes: `wireframes.md` — **nicht relevant** (Job-Handler ohne UI-Touch; Catalog-Sync-UI mit Progress-Bar ist Slice 26).
