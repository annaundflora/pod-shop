# Gate 2: Compliance Report — Slice 36

**Geprueffter Slice:** `specs/2026-05-03-7-spreadconnect-pod-plugin-v2-full-api-coverage/slices/slice-36-stock-cache-sync.md`
**Pruefdatum:** 2026-05-03

---

## Phase 2: Deterministic Checks

| Check | Status | Detail |
|-------|--------|--------|
| D-1: Metadata | PASS | "## Metadata" Section vorhanden; alle 4 Felder gesetzt (ID=`slice-36-stock-cache-sync`, Test=`composer test`, E2E=`false`, Dependencies=`["slice-23-sync-article-job","slice-25-article-removed-job"]`). |
| D-2: Test-Strategy | PASS | "## Test-Strategy" Section vorhanden; alle 7 Felder gesetzt (Stack, Test/Integration/Acceptance/Start Command, Health Endpoint=`n/a`, Mocking Strategy=`mock_external`). |
| D-3: AC Format | PASS | 11 ACs vorhanden, jedes mit GIVEN/WHEN/THEN als Wort-Marker (Z. 40-82). |
| D-4: Test Skeletons | PASS | `<test_spec>`-Block vorhanden (Z. 92-219); 18 Test-Cases (PHPUnit `public function test_...` + `markTestIncomplete`) >= 11 ACs. PHP/PHPUnit-Pattern erfuellt. |
| D-5: Integration Contract | PASS | "## Integration Contract" mit "### Requires From Other Slices" (8 Eintraege) und "### Provides To Other Slices" (4 Eintraege). |
| D-6: Deliverables Marker | PASS | `<!-- DELIVERABLES_START -->` (Z. 253) + `<!-- DELIVERABLES_END -->` (Z. 259); 5 Deliverables (3 NEU: StockCache.php, LiveStockRefresher.php, StockSyncJob.php; 2 EDIT: ArticleEventHandler.php, Bootstrap/Plugin.php). Akzeptiert per User-Note. Alle haben Dateipfade. |
| D-7: Constraints | PASS | "## Constraints" Section mit Scope-Grenzen (9), Technische Constraints (12) und Reuse-Block. |
| D-8: Groesse | PASS | 329 Zeilen (< 400 Warning-Schwelle, < 600 Blocking). Keine Code-Bloecke > 20 Zeilen ausser Test-Skeleton (PHP-Test-Wrapper, kein Implementations-Code). |
| D-9: Anti-Bloat | PASS | Keine "## Code Examples"-Section; keine ASCII-Wireframes; kein DB-Schema kopiert; keine Type-Definitionen mit > 5 Feldern. Test-Skeleton enthaelt nur Method-Stubs mit `markTestIncomplete`. |
| D-10: Codebase Reference | SKIP | Greenfield-Slice (architecture.md Z. 624: "v1 plugin DELETED before v2 starts"). Edit-Targets `ArticleEventHandler.php` (von Slice 25 angelegt) und `Bootstrap/Plugin.php` (von Slice 02 angelegt) existieren noch nicht im Repository — Slice 25 + Slice 02 sind Vorgaenger-Slices, beide nicht implementiert. Konsistenz wird ueber L-3 (Contract-Konsistenz) verifiziert. |

**Phase 2 Verdict:** PASS

---

## Phase 3: LLM Content Checks

| Check | Status | Detail |
|-------|--------|--------|
| L-1: AC-Qualitaet | PASS | Alle 11 ACs sind testbar mit konkreten Werten: AC-1 (`get('SKU-1')` -> `42` int / `null` bei Miss), AC-2 (`set_transient('sc_stock_SKU-1', 17, 300)`, Clamp 60..900), AC-3 (genau 1 Bulk-Call mit ['SKU-A','SKU-B','SKU-C'], Map mit 3 Eintraegen), AC-4 (Full-Cache-Hit -> kein API-Call), AC-5 (`removed_in_sc` -> leere Map), AC-6 (Threshold 10; LOW=3 -> mutate, HIGH=99 -> skip, ZERO=0 -> outofstock), AC-7 (60 Variations -> 1 Bulk-Call), AC-8 (`add_action(..., 'handleStatic', 10, 1)` + `as_schedule_recurring_action` + Enum-Mapping `6h->21600`), AC-9 (Webhook + Reverse-Lookup), AC-10 (re-throw fuer AS-Retry), AC-11 (`delete_transient('sc_stock_'.$sku)`). Alle THEN-Klauseln sind maschinell pruefbar. |
| L-2: Architecture Alignment | PASS | Cache-Key `sc_stock_{sku}` korrekt (architecture.md Z. 350); TTL-Range 60-900s mit Default 300 korrekt (Z. 334, 471, 685); Bulk-Endpoint `getStock(null, $skus)` mit `?skus=`-Komma-Liste konsistent mit Slice 10 AC-9 + architecture.md Z. 120; Hook `spreadconnect/scheduled_stock_sync` recurring + Group `spreadconnect` korrekt (Z. 554); Threshold-Logic `quantity < threshold` korrekt (Z. 622-623); Reverse-Lookup-Pattern via `get_posts` mit `meta_query` korrekt (Z. 287); Service-Map-Klassen `Stock\StockCache` (Z. 385), `Stock\LiveStockRefresher` (Z. 387), `Stock\StockSyncJob` (Z. 386) mit korrekter Layer-Klassifikation. Logging-Source `spreadconnect-sync-job` korrekt (Z. 532). |
| L-3: Contract Konsistenz | PASS | (a) Slice 10 AC-9 (Z. 131-134 in slice-10) garantiert `getStock(?string, ?array): array`-Signatur und `null+null` wirft `\InvalidArgumentException` — Slice 36 nutzt `getStock(null, $skus)`, kompatibel. (b) Slice 25 AC-10 (Z. 76-78 in slice-25) garantiert `final class ArticleEventHandler` mit `public static function handle(array $payload): void` — Slice 36 erweitert nur Method-Body, Klassen-/Method-Signatur unveraendert (ACs 9 + Deliverable Edit dokumentiert dies; Slice 25 ACs 1/2 bleiben gruen, Constraint "Webhook-Edit-Idempotenz" wraps in try/catch). (c) Slice 25 setzt `_spreadconnect_sync_state='removed_in_sc'` (AC-5 in slice-25) — Slice 36 AC-5 liest diesen Wert, "Provides To" Z. 240 in slice-25 deklariert Slice 36 explizit als Konsument. (d) Slice 23 etabliert `handleStatic`-Bridge + AS-Group `'spreadconnect'` — Slice 36 spiegelt dies in AC-8 + Constraint "Bridge-Methode `handleStatic`". (e) Provides-To-Eintraege (StockCache, LiveStockRefresher, StockSyncJob, Hook) sind typisierte Interfaces fuer Slice 34 (Product-Meta-Box) konsumierbar. |
| L-4: Deliverable-Coverage | PASS | StockCache.php deckt AC-1, AC-2, AC-11 (get/set/invalidate); LiveStockRefresher.php deckt AC-3, AC-4, AC-5 (refresh + Cache-Hit-Skip + removed-Skip); StockSyncJob.php deckt AC-6, AC-7, AC-10 (Threshold-Mutation, Bulk-Call, re-throw); ArticleEventHandler-Edit deckt AC-9 (Webhook-Refresh-Trigger); Bootstrap/Plugin-Edit deckt AC-8 (add_action + recurring schedule + Re-Schedule on setting change). Keine verwaisten Deliverables. Test-Datei in Hinweis dokumentiert (Z. 261). |
| L-5: Discovery Compliance | PASS | Discovery Flow F "Stock-Sync (Hybrid)" (Z. 180-192) ist vollstaendig abgedeckt: (a) Live-Cache mit konfigurierbarer TTL -> AC-1/2; (b) Periodic-Job mit Threshold-Logic -> AC-6 (LOW/ZERO/HIGH-Cases); (c) Webhook-Re-Trigger via `Article.updated` -> AC-9; (d) "niemals parallele per-SKU-Calls" -> AC-7 + Constraint "Kein Per-SKU-Fallback"; (e) Discovery Z. 191/Z. 622-623 Threshold-Logic (`< threshold` mutate; `>=` skip + manage_stock off) -> AC-6 (HIGH-1 keine Mutation). Discovery Z. 1036 ("Article.updated ist Stock-Trigger, kein separater Stock-Event") -> Constraint "Kein Stock-Webhook-Event-Type". |
| L-6: Consumer Coverage | PASS | Edit-Target `ArticleEventHandler::handle()` (Slice 25-Vertrag): Aufrufer ist `Webhook\ProcessWebhookEventJob::handle()` (Slice 17 Dispatcher) — Call-Pattern ist dispatch via Class-FQN `ArticleEventHandler::handle($payload)` ohne Return-Wert-Verwendung (`: void`). Slice 36 AC-9 + Constraint "Webhook-Edit-Idempotenz" garantieren: (1) Klassen-/Method-Signatur unveraendert (Slice 25 AC-10), (2) Slice 25 ACs 1/2/3/4 bleiben gruen (additive Aktion), (3) try/catch um Refresh-Call verhindert Exception-Bruch des Slice-17-Dispatcher-Pfads. Edit-Target `Bootstrap\Plugin.php` ist additiv (neuer `add_action` + neuer Activate-Hook-Schritt + neuer `update_option_*`-Hook); keine bestehenden Aufrufer-Patterns werden veraendert. |

---

## Verdict

**VERDICT: APPROVED**

**Blocking Issues:** 0

**Notes (non-blocking):**
- Slice ist gross (329 Zeilen, 11 ACs, 18 Test-Stubs, 12 technische Constraints) aber bleibt unter der 400-Zeilen-Warnschwelle. Komplexitaet ist gerechtfertigt durch 3 neue Klassen + 2 Edits + Hybrid-Architektur (Cache + Refresher + Job + Webhook-Trigger).
- 5 Deliverables explizit per User-Note akzeptiert (3 NEU + 2 EDIT) — entspricht der dokumentierten Architektur (Service-Map Z. 385-387 + Edits an Slice-25-Webhook-Handler + Slice-02-Bootstrap).
- Constraint "Per-Produkt-Isolation" (AC-10) verbietet per-Produkt-Skip auf Transient-Errors — dies kontrastiert mit der gaengigen Erwartung, ist aber konsistent mit der dokumentierten Begruendung ("sonst dauert ein 5xx-Stagger zu lange") und der AS-Retry-Policy 1m/5m/15m. Implementer-Entscheidung dokumentiert.
- Constraint "Settings-Change-Re-Schedule" auf `update_option_spreadconnect_stock_sync_interval` ist ueber AC-8 indirekt abgedeckt aber nicht als eigenes AC gelistet — vertretbar, da operationelles Detail; PHPUnit-Test koennte hier als zusaetzlicher Edge-Case ergaenzt werden (kein Blocker).
