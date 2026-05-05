# Slice 36: Stock-Cache + Periodic Stock-Sync-Job

> **Slice 36 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-36-stock-cache-sync` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-23-sync-article-job", "slice-25-article-removed-job"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WooCommerce 10.5 + Action-Scheduler + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` (Brain\Monkey-Mocks fuer `get_transient`/`set_transient`/`delete_transient`, `as_*`, `get_option`, `wc_get_product`, `wc_update_product_stock_status`, `__()`, `wc_get_logger`) |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: SC `Article.updated`-Webhook auf gelinktes Produkt -> Stock-Refresh; Tools -> Scheduled Actions zeigt `spreadconnect/scheduled_stock_sync` recurring per Setting) |
| **Health Endpoint** | `n/a` (Cache-/Job-Handler ohne UI) |
| **Mocking Strategy** | `mock_external` (`SpreadconnectClient` als Konstruktor-Injectable; Brain\Monkey fuer Transient-API, AS-Schedules, Options, WC-Product-Helpers; keine echte DB-/HTTP-I/O) |

---

## Ziel

Hybrid-Stock-Sync laut Discovery Flow F: schmale `Stock\StockCache` ueber Transients `sc_stock_{sku}` (TTL aus Setting clamped 60-900s), `Stock\LiveStockRefresher` fuer Bulk-Refresh einer Variations-Liste eines Produkts, und recurring `Stock\StockSyncJob` (Setting-Intervall) der WC-Variation-Stock + `outofstock` **nur bei `quantity < threshold`** mutiert. `Webhook\ArticleEventHandler` triggert zusaetzlich Stock-Refresh bei `Article.updated`.

---

## Acceptance Criteria

1) **GIVEN** ein Transient `sc_stock_{sku}` existiert mit Wert `42` (Integer, gesetzt durch vorheriges `set()`-Aufruf), Setting `spreadconnect_live_cache_ttl_seconds=300`
   **WHEN** `Stock\StockCache::get('SKU-1')` aufgerufen wird
   **THEN** liefert die Methode `42` (int) zurueck und ruft **niemals** `SpreadconnectClient::getStock(...)` auf. Cache-Miss-Pfad (`get_transient` returns `false`) liefert `null` (nicht `false`, um Boolean-Confusion zu vermeiden — Architecture Z. 385 typisiert die Methode als `int?`).

2) **GIVEN** Setting `spreadconnect_live_cache_ttl_seconds=300` (Default), `set('SKU-1', 17)` wird aufgerufen
   **WHEN** `StockCache::set(string $sku, int $quantity): void` ausgefuehrt wird
   **THEN** wird `set_transient('sc_stock_SKU-1', 17, 300)` mit exakt diesem Cache-Key (`sc_stock_{sku}` laut architecture.md Z. 350 Transient-Keys) und TTL aus Setting aufgerufen. Bei Setting-Werten ausserhalb `60..900` clampt die Klasse auf `[60, 900]` (architecture.md Z. 471 "Stock TTL clamp"); bei nicht-existierender Option faellt sie auf `300` zurueck.

3) **GIVEN** ein WC-Produkt mit `_spreadconnect_article_id='ART-7'` und Variations mit SKUs `['SKU-A','SKU-B','SKU-C']`, alle drei SKUs **nicht** im Cache (Miss); `SpreadconnectClient::getStock(null, ['SKU-A','SKU-B','SKU-C'])` liefert `[StockEntry('SKU-A',5), StockEntry('SKU-B',0), StockEntry('SKU-C',999)]`
   **WHEN** `Stock\LiveStockRefresher::refresh(int $productId): array` aufgerufen wird
   **THEN** wird (a) **genau ein** Bulk-`getStock(null, $skus)`-Call mit der vollstaendigen SKU-Liste abgesetzt (Open-Q10 "Bulk-Endpoint primaerer Pfad"; **niemals** per-SKU `getStockBySku()`-Loop — architecture.md Z. 716/Z. 797), (b) pro Entry `StockCache::set($sku, $quantity)` aufgerufen, (c) die Methode liefert ein Map `[$sku => $quantity]` zurueck (3 Eintraege).

4) **GIVEN** `LiveStockRefresher::refresh($productId)` wird aufgerufen, alle drei Variations-SKUs bereits im Cache (`get('SKU-A')=5`, `get('SKU-B')=0`, `get('SKU-C')=999`)
   **WHEN** `refresh(...)` laeuft
   **THEN** wird `SpreadconnectClient::getStock(...)` **nicht** aufgerufen (Full-Cache-Hit-Pfad), und die Methode liefert die Map aus Cache-Werten zurueck. Nur Cache-Misses triggern den Bulk-Call (Mixed-Hit darf entweder partial-bulk-call (nur Miss-SKUs) oder Full-Skip implementieren — Implementer-Wahl; AC-3 testet Full-Miss, dieser AC den Full-Hit-Pfad).

5) **GIVEN** `LiveStockRefresher::refresh($productId)` und das WC-Produkt hat Postmeta `_spreadconnect_sync_state='removed_in_sc'` (Slice 25 setzt diesen Wert)
   **WHEN** `refresh(...)` aufgerufen wird
   **THEN** wird **kein** API-Call ausgeloest, kein Cache-Write, und die Methode liefert ein leeres Map `[]` zurueck. Removed Articles werden in der Stock-Pipeline ignoriert (siehe Slice-25 "Provides To Other Slices" Z. 240, "skip-Stock-Sync fuer removed Articles").

6) **GIVEN** der Action-Scheduler dispatcht `spreadconnect/scheduled_stock_sync` (recurring), Setting `spreadconnect_low_stock_threshold=10`, und der Job iteriert ueber alle gelinkten WC-Produkte mit `_spreadconnect_article_id IS NOT NULL` und `_spreadconnect_sync_state != 'removed_in_sc'`. Ein Produkt hat Variations mit SKUs `['LOW-1','HIGH-1','ZERO-1']`; SC liefert `quantity=3` (LOW-1), `quantity=99` (HIGH-1), `quantity=0` (ZERO-1)
   **WHEN** `Stock\StockSyncJob::handle(array $args = []): void` laeuft
   **THEN** **fuer LOW-1** (`3 < 10`): WC-Variation-Stock-Mgmt wird aktiviert (`set_manage_stock(true)`), `set_stock_quantity(3)`, `set_stock_status('instock')` (quantity > 0); **fuer ZERO-1** (`0 < 10`): `set_manage_stock(true)`, `set_stock_quantity(0)`, `set_stock_status('outofstock')`; **fuer HIGH-1** (`99 >= 10`): **keine** WC-Stock-Mutation (`set_manage_stock`/`set_stock_quantity`/`set_stock_status` werden **nicht** aufgerufen) — POD-Default unlimited (Discovery Z. 191, architecture.md Z. 623). Cache `sc_stock_{sku}` wird fuer **alle drei** SKUs geschrieben (unabhaengig von Threshold; AC-2-Pfad).

7) **GIVEN** `StockSyncJob::handle()` laeuft und ein Produkt hat 60 Variations
   **WHEN** der Job die SKUs sammelt
   **THEN** wird **genau ein** Bulk-`getStock(null, $skus)`-Call pro Produkt abgesetzt (alle 60 SKUs in einem Request — architecture.md Z. 120 "Bulk endpoint per Open Q10"). **Niemals** parallele `getStockBySku()`-Loops; diese Variante ist nur als Fallback bei 4xx auf Bulk-Endpoint zulaessig (architecture.md Z. 121 "Optional fallback only"; in Slice 36 NICHT implementiert — Implementer setzt TODO-Kommentar `// FIXME(post-MVP): per-SKU fallback bei 4xx`).

8) **GIVEN** Plugin-Activate (Slice 02 `register_activation_hook`) oder `init`-Hook
   **WHEN** der Bootstrap laeuft
   **THEN** ist (a) `add_action('spreadconnect/scheduled_stock_sync', [StockSyncJob::class, 'handleStatic'], 10, 1)` registriert (analog Slice 23/25 Bridge-Pattern), (b) eine recurring AS-Schedule `as_schedule_recurring_action(time(), $intervalSeconds, 'spreadconnect/scheduled_stock_sync', [], 'spreadconnect')` mit `$intervalSeconds` aus Setting `spreadconnect_stock_sync_interval` (enum-Mapping `1h`->3600, `4h`->14400, `6h`->21600, `12h`->43200, `24h`->86400; Default `6h`) angelegt, **idempotent** (siehe `as_next_scheduled_action(...)`-Pre-Check, damit doppelte Activate-Hooks keine doppelten Schedules erzeugen).

9) **GIVEN** ein Webhook-Payload `eventType='Article.updated'`, `data.entity.id='ART-7'` durchlaeuft den Slice-25-`ArticleEventHandler::handle()`-Pfad (der bereits `sync_article` enqueued)
   **WHEN** Slice 36 den Handler **erweitert**, sodass zusaetzlich ein Stock-Refresh ausgeloest wird
   **THEN** wird **zusaetzlich** zum bereits in Slice 25 enqueued `spreadconnect/sync_article` ein direkter Aufruf `LiveStockRefresher::refresh($productId)` per Reverse-Lookup auf `_spreadconnect_article_id` durchgefuehrt — **nur** wenn das WC-Produkt existiert. Existiert kein gelinktes Produkt (Article.updated fuer ein noch nicht gesyncted Article), wird kein Refresh getriggert (`sync_article`-Job legt das Produkt erst an; Slice 23-Pfad uebernimmt). Slice 25 ACs 1/2 bleiben gruen (zusaetzliche Aktion, keine Veraenderung der bisherigen Logik).

10) **GIVEN** `SpreadconnectClient::getStock(...)` wirft `SpreadconnectTransientError` (5xx/Network) im periodischen Job-Pfad
    **WHEN** `StockSyncJob::handle()` laeuft
    **THEN** wird die Exception **unveraendert re-thrown**, damit Action-Scheduler den 1m/5m/15m-Retry-Cycle (architecture.md Z. 554) startet. **Kein** Cache-Write fuer das fehlgeschlagene Produkt; bereits erfolgreich verarbeitete Produkte im selben Job-Run bleiben jedoch persistiert (Per-Produkt-Try/Catch-Boundary erlaubt; siehe Constraint "Per-Produkt-Isolation"). 4xx (`SpreadconnectClientError`) wird ebenfalls re-thrown — Slice 37 RetryPolicyListener entscheidet ueber DLQ.

11) **GIVEN** `StockCache::invalidate(string $sku): void` wird aufgerufen
    **WHEN** der Cache invalidiert wird
    **THEN** ruft die Methode `delete_transient('sc_stock_'.$sku)` auf. Wird genutzt vom AJAX `spreadconnect_refresh_stock` (Slice 34 Product-Meta-Box) und intern, falls Implementer Cache-Bust-Pfade benoetigt. **Kein** Mass-Invalidate noetig (Bulk-Refresh ueberschreibt ohnehin).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey mocked Transient-API + AS-Funktionen + WC-Product-Helpers (`wc_get_product`, `wc_get_products`, `WC_Product_Variation::set_manage_stock/set_stock_quantity/set_stock_status/save`). `SpreadconnectClient` als Konstruktor-Injectable (Mockery-Mock im Test). `LiveStockRefresher` und `StockSyncJob` erhalten `StockCache` + `SpreadconnectClient` per DI; `ArticleEventHandler`-Edit testet Slice-25-Vertrag bleibt erhalten + neuer Refresh-Aufruf verifiziert.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-06-stock-cache-sync.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests\Stock;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

final class StockCacheAndSyncTest extends TestCase
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

    // AC-1: Cache-Hit liefert int ohne API-Call; Miss liefert null
    public function test_cache_get_returns_int_on_hit_and_null_on_miss(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: set() schreibt sc_stock_{sku}-Transient mit Setting-TTL
    public function test_cache_set_uses_configured_ttl_and_correct_key(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: TTL-Clamp 60..900, Default 300 bei fehlender Option
    public function test_cache_clamps_ttl_to_60_900_with_default_300(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: refresh() ruft genau einen Bulk-getStock-Call mit allen Variations-SKUs
    public function test_refresher_calls_single_bulk_get_stock_for_all_variation_skus(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: refresh() schreibt Cache pro StockEntry und liefert Map
    public function test_refresher_writes_cache_and_returns_sku_quantity_map(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Full-Cache-Hit -> kein API-Call
    public function test_refresher_skips_api_call_when_all_skus_cached(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: removed_in_sc -> kein API-Call, leeres Map
    public function test_refresher_skips_removed_articles(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Periodic Job mutiert WC-Stock nur unter Threshold
    public function test_periodic_job_mutates_wc_stock_only_below_threshold(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-6: quantity=0 setzt outofstock + manage_stock=true
    public function test_periodic_job_sets_outofstock_when_quantity_zero(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-6: quantity >= threshold -> keine WC-Mutation, aber Cache-Write
    public function test_periodic_job_writes_cache_but_skips_wc_mutation_when_above_threshold(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Bulk-Call pro Produkt, niemals per-SKU-Loop
    public function test_periodic_job_uses_single_bulk_call_per_product_never_per_sku(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Bootstrap registriert add_action + recurring AS schedule, idempotent
    public function test_bootstrap_registers_action_and_recurring_schedule_idempotent(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: interval-Setting-Mapping (6h -> 21600 etc.)
    public function test_bootstrap_maps_interval_setting_to_seconds(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Article.updated -> zusaetzlicher LiveStockRefresher::refresh-Call (Reverse-Lookup)
    public function test_article_updated_webhook_triggers_stock_refresh_when_product_linked(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-9: Article.updated ohne gelinktes Produkt -> kein Refresh
    public function test_article_updated_webhook_skips_refresh_when_no_linked_product(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: TransientError -> re-throw fuer AS-Retry
    public function test_periodic_job_rethrows_transient_error_for_action_scheduler_retry(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: invalidate() ruft delete_transient mit korrektem Key
    public function test_cache_invalidate_deletes_transient(): void
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
| `slice-10-endpoint-methods` | `SpreadconnectClient::getStock(?string $productTypeId, ?array $skus): array` (returns `StockEntry[]`) | Method | Slice 10 AC-9 garantiert Bulk-Pfad mit `?skus=`-Komma-Liste; `null+null` wirft `\InvalidArgumentException`. |
| `slice-09-dto-value-objects` | `Api\Dto\StockEntry{sku:string, quantity:int>=0}` | DTO | Slice 09 garantiert `quantity` non-negative + `sku` non-empty. |
| `slice-22-product-mapper` | WC-Variation-Schema mit `_spreadconnect_sku`-Postmeta + `_spreadconnect_article_id` auf parent product (Reverse-Lookup) | DB-Resource | Slice 22 etabliert Reverse-Lookup-Idiom (`get_posts` mit `meta_query`); Slice 36 wendet ihn an. |
| `slice-25-article-removed-job` | `final class Webhook\ArticleEventHandler` mit `public static function handle(array $payload): void`; Postmeta `_spreadconnect_sync_state='removed_in_sc'` | Class + DB-Resource | Slice 25 AC-10 garantiert Klassen-Signatur; Slice 36 erweitert `handle()` um Stock-Refresh-Call bei `Article.updated` und liest `_spreadconnect_sync_state` zum Skip-Pfad (AC-5). |
| `slice-23-sync-article-job` | Hook `spreadconnect/sync_article` registriert; `_spreadconnect_article_id`-Reverse-Lookup-Pattern | Action-Hook + Pattern | Slice 23 etabliert Pattern; Slice 36 ruft `sync_article` **nicht** selbst, nutzt aber dieselbe Lookup-Logik fuer Stock-Sync-Iteration. |
| `slice-11-settings-form` | Options `spreadconnect_low_stock_threshold` (int, default 10), `spreadconnect_stock_sync_interval` (enum, default `6h`), `spreadconnect_live_cache_ttl_seconds` (int, default 300, clamp 60..900) | Persisted options | Slice 11 schreibt + sanitized; Slice 36 liest via `get_option`. **Nicht-Blocker**: bei fehlender Option faellt Slice 36 auf den dokumentierten Default zurueck — Slice 11 muss nicht zwingend vor Slice 36 mergen (Defaults aus Slice 05). |
| `slice-05-options-defaults` | Activate-Hook setzt die drei o.g. Options mit Discovery-Defaults | Activate-Hook | Slice 05 garantiert, dass die Options nach Activate vorhanden sind (idempotent via `add_option`). |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::init()`-Hook-Lifecycle + Activate-Hook | Bootstrap-Lifecycle | Slice 36 ergaenzt `add_action('spreadconnect/scheduled_stock_sync', ...)` und Activate-Hook fuer recurring schedule. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `Stock\StockCache` | Domain-Klasse | `slice-34-product-meta-box-margin-stock` (Live-Stock-Indicator-Tabelle), AJAX `spreadconnect_refresh_stock` | `public function get(string $sku): ?int`, `public function set(string $sku, int $quantity): void`, `public function invalidate(string $sku): void` |
| `Stock\LiveStockRefresher` | Application-Klasse | `slice-34-product-meta-box-margin-stock` (`refresh_stock`-AJAX); `Webhook\ArticleEventHandler` (Slice 25 + dieser Slice) | `public function refresh(int $productId): array` (returns `array<string,int>` Map sku=>quantity, leere Map bei removed/no-link) |
| `Stock\StockSyncJob` | Application-Klasse (AS-Handler) | Action-Scheduler-Worker via `add_action`-Bridge; recurring AS-Schedule | `public function handle(array $args = []): void`, `public static function handleStatic(array $args = []): void` |
| Action-Hook `spreadconnect/scheduled_stock_sync` | Action-Hook (registered + recurring scheduled) | Action-Scheduler-Worker | Hook-Args: leeres Array (job iteriert intern alle gelinkten Produkte) |

> **Mount-Point-Check:** `StockSyncJob` wird durch `add_action`-Edit + recurring `as_schedule_recurring_action`-Edit in `Bootstrap\Plugin` an Action-Scheduler gehaengt (Deliverable Edit). `StockCache` und `LiveStockRefresher` werden in Slice 34 (Product-Meta-Box) direkt instanziiert/per Container-Resolve aufgerufen — Slice 36 liefert sie als Service-Klassen, der Mount erfolgt im Konsumenten. `ArticleEventHandler::handle` wird in Slice 17 ProcessWebhookEventJob bereits gemountet — Slice 36 erweitert nur den Method-Body um den Refresh-Call (kein neuer Mount-Point).

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Stock/StockCache.php` — `final class SpreadconnectPod\Stock\StockCache` mit `get(string): ?int`, `set(string, int): void`, `invalidate(string): void`. Liest TTL aus Setting `spreadconnect_live_cache_ttl_seconds` (Clamp 60..900, Default 300). Cache-Key-Format: `sc_stock_{sku}` (architecture.md Z. 350).
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Stock/LiveStockRefresher.php` — `final class SpreadconnectPod\Stock\LiveStockRefresher` mit Konstruktor-DI (`SpreadconnectClient`, `StockCache`). `public function refresh(int $productId): array` — Reverse-Lookup `_spreadconnect_article_id`, Skip bei `_spreadconnect_sync_state='removed_in_sc'`, Bulk-`getStock(null, $skus)`, Cache-Write, Map-Return.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Stock/StockSyncJob.php` — `final class SpreadconnectPod\Stock\StockSyncJob` mit Konstruktor-DI (`SpreadconnectClient`, `StockCache`). `public function handle(array $args = []): void`, `public static function handleStatic(array $args = []): void` (Bridge fuer `add_action`). Iteriert gelinkte Produkte, Bulk-`getStock` pro Produkt, Threshold-Logic (siehe AC-6/7).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Webhook/ArticleEventHandler.php` — Erweitert den **bestehenden** Slice-25-`handle()`-Body um einen zusaetzlichen `LiveStockRefresher::refresh($productId)`-Call bei `eventType='Article.updated'`, **nach** dem bestehenden `as_enqueue_async_action('spreadconnect/sync_article', ...)`-Call. Reverse-Lookup auf `_spreadconnect_article_id`; Skip wenn kein WC-Produkt existiert. Slice-25 ACs 1/2/3/4 bleiben unveraendert gruen.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — (a) `add_action('spreadconnect/scheduled_stock_sync', [StockSyncJob::class, 'handleStatic'], 10, 1)` registrieren; (b) im Activate-Hook: `as_schedule_recurring_action(...)` mit Setting-Intervall idempotent anlegen (Pre-Check `as_next_scheduled_action(...)`); (c) bei Setting-Aenderung des Intervalls: `as_unschedule_action` + Re-Schedule (Hook auf `update_option_spreadconnect_stock_sync_interval`).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-06-stock-cache-sync.php` wird vom Test-Writer-Agent erstellt. Keine Edits an `SpreadconnectClient` (Slice 07/10), `ProductMapper` (Slice 22), `SyncArticleJob` (Slice 23), `ArticleRemovedJob` (Slice 25).

---

## Constraints

**Scope-Grenzen:**
- **Kein** UI-Code — Live-Stock-Indicator-Tabelle und `[Refresh Stock]`-Button im Product-Meta-Box sind Slice 34. Slice 36 liefert nur die Service-Klassen, die Slice 34 konsumiert.
- **Kein** AJAX-Handler `spreadconnect_refresh_stock` — kommt in Slice 34 (Hub\Ajax\ProductActions).
- **Kein** Stock-Webhook-Event-Type — SC hat keinen separaten Stock-Event (Discovery Z. 1036). `Article.updated` ist der Trigger; Slice 36 erweitert den bestehenden Handler-Pfad, **kein** neuer Webhook-Mapping.
- **Kein** Per-SKU-Fallback (`getStockBySku()`-Loop) — architecture.md Z. 716/Z. 797 verbietet per-SKU-Loops in Hot Paths. TODO-Kommentar erlaubt fuer post-MVP-Fallback bei 4xx.
- **Kein** WC-Product-Stock-Mutation auf Parent-Level — Stock-Mgmt liegt auf der Variation (`WC_Product_Variation::set_stock_*`); Parent-Product bleibt unberuehrt.
- **Kein** `set_manage_stock(true)` auf Variations mit `quantity >= threshold` — POD-Default ist unlimited; nur unter Threshold aktivieren (Discovery Z. 191, architecture.md Z. 623).
- **Kein** `FailedOpsRepo`-Insert bei 4xx/5xx — Slice 37 wired den `action_scheduler_failed_action`-Hook nach 3 Retries. Slice 36 re-throwt nur.
- **Kein** Logs-UI-Touch — Logging via `wc_get_logger()` Source `spreadconnect-sync-job` (architecture.md Z. 532).
- **Kein** Mass-Cache-Invalidate — `StockCache::invalidate` ist per-SKU; Bulk-Refresh ueberschreibt Cache implizit per `set()`.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile in allen neuen Dateien.
- `final class` fuer alle drei Klassen (nicht erweiterbar).
- Method-Signaturen exakt wie im Integration Contract dokumentiert.
- **Cache-Key-Format** (AC-1/2/11): exakt `sc_stock_{sku}` — kein Praefix-Salting, kein Hash; `architecture.md` Z. 350 ist Single-Source-of-Truth. SKUs duerfen WordPress-Transient-Key-Limits ueberschreiten (172 chars) — bei langen SKUs implementer-seitig truncate-and-hash erlaubt, aber unueblich (POD-SKUs sind kurz). PHPUnit-Test verifiziert Default-Format.
- **TTL-Clamp** (AC-2): `min(900, max(60, (int) get_option('spreadconnect_live_cache_ttl_seconds', 300)))` — clamp-Logik in `StockCache` zentralisiert; Slice 11 sanitized zwar bereits, aber Defense-in-Depth.
- **Reverse-Lookup-Pattern** (AC-3/5/6/9): identisch zu Slice 22/25 (`get_posts(['post_type'=>'product','meta_key'=>'_spreadconnect_article_id','meta_value'=>$articleId,'numberposts'=>1,'post_status'=>['publish','draft','private']])`). Fuer den Job-Pfad (Iteration aller gelinkten Produkte) `wc_get_products(['meta_query'=>[...]])` mit Pagination — Implementer waehlt Batch-Size (Vorschlag: 50). **Niemals** rohes `$wpdb`-Query.
- **Threshold-Vergleich** (AC-6): `$quantity < $threshold` — strict less-than; bei `quantity == threshold` keine Mutation. `$threshold = (int) get_option('spreadconnect_low_stock_threshold', 10)`.
- **WC-Stock-Mutation-Sequenz** (AC-6): Reihenfolge: `set_manage_stock(true)` -> `set_stock_quantity($qty)` -> `set_stock_status($qty > 0 ? 'instock' : 'outofstock')` -> `save()`. Pro Variation einzeln; **kein** `wc_update_product_stock` (deprecated/Top-Level-Helper unzuverlaessig fuer Variations).
- **Per-Produkt-Isolation** (AC-10): Job iteriert Produkte; pro Produkt try/catch um den `getStock`-Call. Bei `SpreadconnectClientError` (4xx fuer EIN Produkt — z.B. Article in SC nicht mehr existent) -> Log Warning + Skip; bei `SpreadconnectTransientError` (5xx) -> re-throw aus dem **gesamten** Job (Action-Scheduler retried den ganzen Run; akzeptabel, da idempotent). Implementer-Wahl: per-Produkt-Skip auf Transient ist NICHT erlaubt (sonst dauert ein 5xx-Stagger zu lange).
- **AS-Hook-Group** (AC-8): `'spreadconnect'` als 5. Argument zu `as_schedule_recurring_action(time(), $intervalSeconds, 'spreadconnect/scheduled_stock_sync', [], 'spreadconnect')` — analog Slice 23/24/25.
- **Bridge-Methode `handleStatic`** (AC-8): identisches Pattern wie Slice 23/25 — resolved Service via Container/Factory aus `Bootstrap\Plugin`. Konsistent mit Slice 17 ProcessWebhookEventJob-Pattern.
- **Webhook-Edit-Idempotenz** (AC-9): Der zusaetzliche `LiveStockRefresher::refresh()`-Call darf KEINE Exception werfen, die den Slice-25-Handler-Pfad bricht. Implementer wraps den Call in try/catch + Log Warning bei `SpreadconnectTransientError` (Stock-Refresh ist best-effort fuer Webhook; periodischer Job ist authoritative — architecture.md Z. 719). `SpreadconnectClientError` (4xx) ebenso silently swallow + Log.
- **Settings-Change-Re-Schedule**: Beim Update von `spreadconnect_stock_sync_interval` (Hook `update_option_spreadconnect_stock_sync_interval`) muss der bestehende recurring schedule unscheduled und neu angelegt werden, sonst laeuft der alte Intervall weiter. Idempotent via `as_unschedule_action('spreadconnect/scheduled_stock_sync', [], 'spreadconnect')` -> `as_schedule_recurring_action(...)`.
- **Logging-Source** (architecture.md Z. 532): `spreadconnect-sync-job` fuer alle drei Klassen. `wc_get_logger()` (NICHT `error_log`); WcLoggerAdapter (Slice 42) ersetzt direkten Aufruf spaeter.

**Reuse:**

Slice 36 baut ausschliesslich auf bestehenden Bausteinen auf; **keine** Neuimplementierung von Client, DTOs, Reverse-Lookup-Helper oder Hook-Registration:

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08/10) | Konstruktor-Injection in `LiveStockRefresher` + `StockSyncJob`. Aufruf `getStock(null, $skus)`. **Unveraendert** — keine neuen Endpoint-Methoden. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/StockEntry.php` (Slice 09) | Map-Lesen `$entry->sku` + `$entry->quantity` in `LiveStockRefresher` und `StockSyncJob`. **Unveraendert**. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClientError.php`, `SpreadconnectTransientError.php` (Slice 07) | Exception-Pass-Through (re-throw aus Job; try/catch im Webhook-Handler-Edit). **Unveraendert**. |
| `wordpress/plugins/spreadconnect-pod/includes/Webhook/ArticleEventHandler.php` (Slice 25) | **Edit-Target** — Body-Erweiterung um `LiveStockRefresher::refresh()`-Call bei `Article.updated`. Slice-25-ACs 1-4 + 10 bleiben unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02 + Edits aus 03/04/05/06/17/23/24/25) | **Edit-Target** — `add_action('spreadconnect/scheduled_stock_sync', ...)` + Activate-Hook-Recurring-Schedule + `update_option_*`-Hook fuer Re-Schedule. Pattern wie Slice 23/24/25 etabliert. |
| WC-Postmeta `_spreadconnect_article_id`, `_spreadconnect_sync_state` (Slice 22 schreibt; Slice 25 setzt `removed_in_sc`) | **Reuse** — Reverse-Lookup-Anker + Skip-Filter. |
| WC-Variation-Postmeta `_spreadconnect_sku` (Slice 22 schreibt) | **Reuse** — SKU-Quelle fuer Bulk-`getStock`-Filter. |
| WP Options `spreadconnect_live_cache_ttl_seconds`, `spreadconnect_low_stock_threshold`, `spreadconnect_stock_sync_interval` (Slice 05 Defaults) | **Reuse** — read via `get_option`, kein Write. |
| Action-Scheduler `as_schedule_recurring_action` / `as_next_scheduled_action` / `as_unschedule_action` (WC bundled) | **Reuse** — Standard-AS-API; kein Wrapper. |

**Referenzen:**
- Architecture: `architecture.md` -> Service-Map Zeilen `Stock\StockCache` (Z. 385), `Stock\StockSyncJob` (Z. 386), `Stock\LiveStockRefresher` (Z. 387).
- Architecture: `architecture.md` -> "Outbound Endpoints" Z. 120 (`GET /stock` Bulk + Cache `sc_stock_{sku}` 5 min konfigurierbar 60-900s), Z. 121 (`/stock/{sku}` Optional fallback only), Z. 122 (`/stock/productType/{id}` Optional).
- Architecture: `architecture.md` -> "AS Hook Inventory" Z. 554 (`spreadconnect/scheduled_stock_sync` recurring per Setting, Concurrency 1, Retry 1m/5m/15m).
- Architecture: `architecture.md` -> "Transient Keys" Z. 350 (`sc_stock_{sku}` configurable 60-900s default 300).
- Architecture: `architecture.md` -> "Settings"/"WP Options" Z. 332-334 (`spreadconnect_stock_sync_interval` enum, `spreadconnect_low_stock_threshold` int default 10, `spreadconnect_live_cache_ttl_seconds` int 60..900 default 300).
- Architecture: `architecture.md` -> "Open Q10" (Z. 797) — Bulk-Endpoint primaerer Pfad, per-SKU forbidden in Hot Paths.
- Architecture: `architecture.md` -> "Open Q1" (Z. 788) — Periodic + Webhook beide ausgeliefert; Webhook ist Bonus, nicht authoritative.
- Architecture: `architecture.md` -> "Performance — Live stock cache" (Z. 685) — 5min TTL, Bulk-Endpoint, deferred async.
- Architecture: `architecture.md` -> "Stock Sync" (Z. 621-623) — Hybrid; Periodic schreibt WC-Stock + outofstock nur bei `stock < threshold`.
- Architecture: `architecture.md` -> "WC-Variation Meta" (Z. 297-303) — `_spreadconnect_sku`, `_spreadconnect_stock_cached`, `_spreadconnect_stock_cached_at`.
- Discovery: `discovery.md` -> Flow F "Stock-Sync (Hybrid)" (Z. 180-192) — Live-Cache + Periodic + Webhook-Trigger; **niemals** parallele per-SKU-Calls.
- Discovery: `discovery.md` -> Z. 622-623 — Threshold-Logic (`< threshold` schreibt; `>=` keine Mutation, WC-Stock-Mgmt off).
- Discovery: `discovery.md` -> Z. 1034 (Stock-Sync-Strategie Hybrid), Z. 1036 (`Article.updated` ist Stock-Trigger, kein separater Stock-Event).
- Slim-Slices: `slices/slim-slices.md` -> Slice-36-Eintrag (Z. 547-555; Done-Signal "Cache-Hit liefert ohne API-Call; Periodic-Job updated outofstock bei quantity=0; bei quantity >= threshold keine WC-Stock-Mutation. Webhook re-triggert Refresh.").
- Vorgaenger Slice 23: `slices/slice-23-sync-article-job.md` -> Reverse-Lookup-Pattern + `handleStatic`-Bridge + AS-Group `'spreadconnect'`.
- Vorgaenger Slice 25: `slices/slice-25-article-removed-job.md` -> `ArticleEventHandler`-Klasse-Signatur + `_spreadconnect_sync_state='removed_in_sc'`-Skip-Filter; "Provides To" Z. 240 deklariert Slice-36 als Konsument.
- Slice 10: `slices/slice-10-endpoint-methods.md` -> AC-Stock Z. 131-134 (`getStock(null, $skus)` Bulk-Pfad; `null+null` wirft `\InvalidArgumentException`).
- Wireframes: `wireframes.md` — **nicht direkt relevant** (Slice 36 ohne UI-Touch; Live-Stock-Indicator-Tabelle ist Slice 34 Product-Meta-Box).
