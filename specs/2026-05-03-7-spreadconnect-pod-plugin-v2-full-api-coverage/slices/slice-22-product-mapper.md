# Slice 22: Product-Mapper (Article + ProductType -> WC-Variable-Product)

> **Slice 22 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-22-product-mapper` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-20-attribute-provisioner"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: PHPUnit-Aufruf des Mappers gegen ein Test-Article-DTO -> WC-Variable-Product erscheint unter `WooCommerce -> Produkte`) |
| **Health Endpoint** | `n/a` (Domain-Service, keine Routes) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `wc_get_product`, `WC_Product_Variable`, `WC_Product_Variation`, `wp_set_object_terms`, `wp_insert_term`, `term_exists`, `get_posts` / `meta_query`, `update_post_meta`; keine echte WC-/DB-I/O im Test) |

---

## Ziel

Mapped ein `ArticleDetail`-DTO + `ProductTypeDetail`-DTO + `Preview[]`-Liste auf ein WooCommerce `WC_Product_Variable` mit Variations pro Size/Color-Kombination (SKU = SC-Variant-SKU). Idempotent: zweiter Aufruf mit selber `article_id` updated dasselbe Produkt, ohne Duplikate. Schreibt SC-Tracking-Meta auf Product- und Variation-Ebene. WC-Preis (`_regular_price`) wird **niemals** geschrieben — Admin behaelt volle Margen-Kontrolle (Discovery Slice 4 Trade-off).

---

## Acceptance Criteria

1) **GIVEN** ein `ArticleDetail`-DTO mit `id='ART-1'`, `title='T-Shirt Demo'`, `productTypeId='PT-42'`, `variants=[{sku:'SC-S-RED', sizeId:'sz-S', colorId:'co-RED', priceCalculation:Money(amount='12.50', currency='EUR')}, {sku:'SC-M-RED', sizeId:'sz-M', colorId:'co-RED', priceCalculation:Money('12.50','EUR')}]` und ein `ProductTypeDetail` mit Sizes `[{id:'sz-S', label:'S'},{id:'sz-M', label:'M'}]`, Colors `[{id:'co-RED', label:'Rot'}]`, sowie eine leere `Preview[]`-Liste, und es existiert noch **kein** WC-Produkt mit Meta `_spreadconnect_article_id='ART-1'`
   **WHEN** `Catalog\ProductMapper::upsert($articleDetail, $productType, $previews)` aufgerufen wird
   **THEN** wird ein neues `WC_Product_Variable` mit `name='T-Shirt Demo'` und `status='publish'` angelegt; zwei `WC_Product_Variation`-Kinder werden erzeugt mit `_sku='SC-S-RED'` bzw. `_sku='SC-M-RED'`; auf dem Parent stehen Meta-Werte `_spreadconnect_article_id='ART-1'`, `_spreadconnect_product_type_id='PT-42'`, `_spreadconnect_cost='12.50'`, `_spreadconnect_cost_currency='EUR'`, `_spreadconnect_last_sync` als Unix-Timestamp (≥ Test-Startzeit), `_spreadconnect_sync_state='synced'`. Rueckgabe ist die WC-Product-ID (`int > 0`).

2) **GIVEN** dasselbe Article-DTO aus AC-1 und ein bereits in einem vorherigen Aufruf erzeugtes WC-Variable-Product mit `_spreadconnect_article_id='ART-1'` (Reverse-Lookup ueber `meta_query`)
   **WHEN** `upsert()` ein zweites Mal aufgerufen wird
   **THEN** wird **kein** neues Produkt erzeugt — der bestehende `WC_Product_Variable`-Datensatz wird identifiziert (per `meta_query` auf `_spreadconnect_article_id`), seine Properties (Title, Description, Variations) werden aktualisiert, `_spreadconnect_last_sync` wird neu geschrieben. Die Anzahl WC-Produkte mit `_spreadconnect_article_id='ART-1'` bleibt **genau 1** (Idempotenz). Die zurueckgegebene Product-ID ist identisch zum ersten Aufruf.

3) **GIVEN** ein bereits existierendes WC-Variable-Product (aus AC-2) mit Admin-gesetztem `_regular_price='29.99'` auf jeder Variation
   **WHEN** `upsert()` mit demselben Article-DTO erneut aufgerufen wird (z. B. nach Re-Sync via Webhook `Article.updated`)
   **THEN** bleibt `_regular_price` auf jeder Variation **unveraendert** bei `'29.99'`; der Mapper ruft **niemals** `WC_Product_Variation::set_regular_price()` oder `set_price()` auf (per Brain\Monkey `Functions\expect(...)->never()` verifizierbar). `_spreadconnect_cost` wird hingegen mit dem aktuellen Variant-`priceCalculation.amount` ueberschrieben (Cost ist Sync-bezogen, Verkaufspreis nicht).

4) **GIVEN** ein Article-DTO mit drei Sizes (`S/M/L`) und zwei Colors (`schwarz/weiss`), insgesamt 6 Variants mit eindeutigen SKUs, und Slice 20 hat sichergestellt, dass die Taxonomies `pa_groesse` und `pa_farbe` existieren
   **WHEN** `upsert()` aufgerufen wird
   **THEN** existieren auf dem Parent-Produkt zwei `WC_Product_Attribute`-Objekte mit Taxonomy-Slugs `pa_groesse` und `pa_farbe`, beide flag `variation=true` und `visible=true`; pro Size-Label und Color-Label wird ein WC-Term per `wp_insert_term()` angelegt (oder via `term_exists()` wiederverwendet); es existieren **genau 6** `WC_Product_Variation`-Kinder, jedes mit der korrekten `attribute_pa_groesse`/`attribute_pa_farbe`-Kombination und `_sku` aus dem Variant-DTO; auf jeder Variation stehen `_spreadconnect_sku`, `_spreadconnect_size_id` und `_spreadconnect_color_id` (siehe `architecture.md` -> "WC-Variation Meta").

5) **GIVEN** eine `Preview[]`-Liste mit drei Eintraegen, deren `imageUrl`-Werte als bereits via `ImageSideloader::sideload()` (Slice 21) auf Attachment-IDs `[101, 102, 103]` resolved gelten — d. h. der **Caller** (Slice 23 SyncArticleJob) uebergibt der Method-Signatur `upsert(ArticleDetail, ProductTypeDetail, int[] $attachmentIds)` die fertigen IDs
   **WHEN** `upsert()` aufgerufen wird und das Produkt **neu** angelegt wird (kein vorhandener `_spreadconnect_article_id`-Match)
   **THEN** wird `WC_Product::set_image_id(101)` (Featured Image = erste Attachment-ID) und `WC_Product::set_gallery_image_ids([102, 103])` aufgerufen. **Bei einem Re-Sync** (AC-2-Pfad, vorhandenes Produkt) wird `set_image_id`/`set_gallery_image_ids` **nicht** erneut aufgerufen — Re-Pull-Logik liegt im Caller (Slice 23 entscheidet via `spreadconnect_force_repull_images`-Option, ob er ueberhaupt sideloaded und IDs uebergibt; bei leerer ID-Liste fasst der Mapper die Bilder nicht an).

6) **GIVEN** ein Article-DTO, dessen Variant-Liste eine Variant ohne `priceCalculation` (NULL) enthaelt
   **WHEN** `upsert()` aufgerufen wird
   **THEN** wird `_spreadconnect_cost` auf dem Parent **nicht** gesetzt (Meta-Key bleibt NULL/abwesend), aber alle anderen Felder werden korrekt geschrieben; die zugehoerige Variation wird trotzdem angelegt. Das Vorhandensein einer Variant ohne Cost ist kein Fehler-Fall — Catalog-Sync laeuft weiter (siehe DTO-Tabelle: `priceCalculation` ist optional in `Variant`).

7) **GIVEN** ein Article-DTO mit leerer Variants-Liste (`variants=[]`) — z. B. SC liefert eine "soft-removed" Article-Antwort
   **WHEN** `upsert()` aufgerufen wird
   **THEN** wirft die Method `SpreadconnectPod\Catalog\ProductMapperException` (extends `\RuntimeException`) mit Message `'ArticleDetail has no variants — refusing to create empty Variable-Product'`. Es wird **kein** WC-Produkt erzeugt oder geaendert (Fail-Fast — Caller-Slice 23 mapped diese Exception auf `sync_history.details[].status='error'` und `_spreadconnect_sync_state='error'`).

8) **GIVEN** ein erfolgreich gemapptes WC-Variable-Product mit zwei vorhandenen Variations `[SC-S-RED, SC-M-RED]` und ein neues Article-DTO, in dem `SC-M-RED` **fehlt** (z. B. Variante in SC abgekuendigt) und stattdessen `SC-L-RED` neu hinzugekommen ist
   **WHEN** `upsert()` aufgerufen wird
   **THEN** wird die alte Variation `SC-M-RED` **nicht** geloescht, sondern auf `status='private'` gesetzt (oder `_stock_status='outofstock'` wenn private-Status mit WC-Frontend-Anzeige kollidiert — Implementer entscheidet konsistent zur Discovery "WC-Produkt-Status nie `delete`, immer soft-archive"); die neue Variation `SC-L-RED` wird erzeugt; die Variation `SC-S-RED` bleibt unveraendert (kein Re-Insert, kein Meta-Reset). Die Anzahl Variations am Produkt ist nach dem Aufruf **3** (1 archived + 2 active).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey mocked die WC-Klassen via Patchwork (`WC_Product_Variable`, `WC_Product_Variation` als reale Klassen-Stubs in `tests/bootstrap/`). Der bestehende Bootstrap aus Slice 02-21 (`tests/bootstrap/bootstrap.php`) wird wiederverwendet — falls fuer die WC-Produkt-Klassen noch keine Stubs existieren, ergaenzt der Test-Writer minimale `class WC_Product_Variable { public function set_name($s){} ... }`-Skeletons in der Bootstrap-Datei. Reverse-Lookup-Tests (AC-2) stub'en `get_posts(['meta_key'=>'_spreadconnect_article_id', 'meta_value'=>'ART-1'])` ueber Brain\Monkey. Preis-Niemals-Geschrieben-Test (AC-3) nutzt `Functions\expect('WC_Product_Variation::set_regular_price')->never()` (oder Mockery-Mock auf Variation-Instanz mit `shouldNotReceive('set_regular_price')`).

### Test-Datei: `tests/slices/pod-shop-mvp/slice-04-product-mapper.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use PHPUnit\Framework\TestCase;

final class ProductMapperTest extends TestCase
{
    // AC-1: Neuer Article -> WC_Product_Variable + Variations + Meta angelegt
    public function test_upsert_creates_new_variable_product_with_variations(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Meta-Werte _spreadconnect_article_id, _product_type_id, _cost, _last_sync, _sync_state korrekt geschrieben
    public function test_upsert_writes_tracking_meta_on_parent(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: Reverse-Lookup via meta_query findet bestehendes Produkt; kein Duplikat
    public function test_upsert_is_idempotent_via_article_id_reverse_lookup(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: Zweiter Aufruf liefert dieselbe Product-ID wie erster
    public function test_upsert_returns_same_product_id_on_repeat_call(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: Re-Sync ruft NIEMALS set_regular_price oder set_price auf
    public function test_upsert_never_writes_wc_price_on_resync(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: _spreadconnect_cost wird bei Re-Sync mit aktuellem priceCalculation.amount ueberschrieben
    public function test_upsert_updates_spreadconnect_cost_on_resync(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: pa_groesse + pa_farbe Attribute am Parent mit variation=true gesetzt
    public function test_upsert_sets_variation_attributes_on_parent(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: 6 Variations bei 3 Sizes x 2 Colors; jede mit korrekter Attribut-Term-Kombination
    public function test_upsert_creates_one_variation_per_size_color_combination(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: Variation-Meta _spreadconnect_sku/_size_id/_color_id korrekt geschrieben
    public function test_upsert_writes_variation_meta_per_variation(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: Beim Neu-Anlegen werden set_image_id + set_gallery_image_ids mit uebergebenen Attachment-IDs aufgerufen
    public function test_upsert_sets_featured_and_gallery_images_on_create(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-5: Beim Re-Sync mit leerer Attachment-ID-Liste werden Bilder NICHT geaendert
    public function test_upsert_skips_images_when_attachment_ids_empty(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Variant ohne priceCalculation -> _spreadconnect_cost nicht gesetzt, Variation trotzdem angelegt
    public function test_upsert_handles_variant_without_price_calculation(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: Leere Variants-Liste -> ProductMapperException; kein WC-Produkt erzeugt
    public function test_upsert_throws_on_empty_variants_list(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Veraltete Variation wird auf private gesetzt, nicht geloescht
    public function test_upsert_archives_obsolete_variations_instead_of_deleting(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: Neue Variant erzeugt neue Variation; bestehende bleibt unveraendert
    public function test_upsert_adds_new_variation_without_touching_unchanged_ones(): void
    {
        $this->markTestIncomplete('AC-8');
    }
}
```
</test_spec>

---

## Integration Contract

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-20-attribute-provisioner` | Taxonomies `pa_groesse` und `pa_farbe` (existent in WC) | DB-Resource | Slice 20 garantiert via `ensure()` im Activate-Hook und Pre-Sync-Guard, dass beide Slugs registriert sind. Mapper ruft `wc_get_attribute_taxonomies()` **nicht** mehr selbst — Verlass auf Slice 20 ist Vertragsbestandteil. |
| `slice-09-dto-value-objects` | `Api\Dto\ArticleDetail`, `Api\Dto\ProductTypeDetail`, `Api\Dto\Variant`, `Api\Dto\Money` | `final readonly` Value Objects | Mapper akzeptiert ausschliesslich diese DTOs (keine rohen Arrays). Validation lebt in den DTO-Factories — Mapper darf sich auf nicht-leere `id`/`title`/`sku`-Strings verlassen. |
| `slice-04-schema-dbdelta` | WC-Postmeta-Tabelle (Standard-WP) | DB-Resource | Plugin laeuft auf WP ≥ 6.9 / WC ≥ 10.5 — Variable-Product- und Variation-Postmeta-Schreibwege sind Standard-WC-API. |
| WooCommerce-Core | `WC_Product_Variable`, `WC_Product_Variation`, `WC_Product_Attribute`, `wc_get_product()`, `wp_insert_term()`, `term_exists()`, `wp_set_object_terms()`, `get_posts()` mit `meta_query` | WC-API (≥ 3.6) | Standard-WC-Methoden — keine internen Privatfunktionen. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SpreadconnectPod\Catalog\ProductMapper::upsert` | method | `slice-23-sync-article-job` (Per-Article-Sequenz: nach `getArticle` + `getProductType` + `createPreviews` + `ImageSideloader::sideload`) | `public function upsert(ArticleDetail $article, ProductTypeDetail $productType, array $attachmentIds = []): int` (return = WC-Product-ID; `$attachmentIds` = Liste von WP-Attachment-IDs, leere Liste = keine Bilder anfassen) |
| `SpreadconnectPod\Catalog\ProductMapperException` | Exception-Class | `slice-23-sync-article-job` (mapped auf `sync_history.details[].status='error'`), `slice-25-article-removed-job` (kein direkter Aufruf, aber gleiches Failure-Pattern) | `class ProductMapperException extends \RuntimeException` |
| WC-Product mit `_spreadconnect_article_id`-Meta | DB-Resource | `slice-25-article-removed-job` (Reverse-Lookup, setzt Status auf `draft`); `slice-32-product-meta-box` (Inline-Meta-Box-Anzeige); `slice-37-stock-cache` (Variation-SKU-Liste) | Reverse-Lookup-Pattern: `get_posts(['post_type'=>'product','meta_key'=>'_spreadconnect_article_id','meta_value'=>$articleId,'numberposts'=>1])`. |

> **Hinweis Consumer-Wiring:** `Catalog\SyncArticleJob` (Slice 23) instantiiert `ProductMapper` und ruft `upsert()` als letzten Schritt der Per-Article-Sequenz auf. Slice 22 liefert ausschliesslich die Service-Klasse; Job-Hook-Registrierung ist NICHT Teil dieses Slices.

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Catalog/ProductMapper.php` — Neue Klasse `final class SpreadconnectPod\Catalog\ProductMapper` mit `public function upsert(ArticleDetail $article, ProductTypeDetail $productType, array $attachmentIds = []): int`. Inklusive `final class ProductMapperException extends \RuntimeException` (in derselben Datei oder als Sibling — Implementer-Entscheidung, konsistent mit Slice 20-Pattern).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-04-product-mapper.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- Kein Aufruf von `AttributeProvisioner::ensure()` im Mapper — Slice 20 garantiert Existenz der Taxonomies via Activate-Hook und Slice 23/24 als Pre-Sync-Guard. Mapper darf annehmen, dass `pa_groesse`/`pa_farbe` registriert sind.
- Kein Image-Sideload — Mapper bekommt fertige `int[] $attachmentIds`. Sideload-Logik ist Slice 21, Aufruf-Sequenz Slice 23.
- Kein Action-Scheduler-Hook — `ProductMapper` ist ein Domain-Service, wird **synchron** vom `SyncArticleJob` (Slice 23) aufgerufen.
- Kein `sync_history`-Schreiben — Status-Mapping (`created/updated/skipped/error/partial`) ist Job-Verantwortung (Slice 23).
- Kein WC-Stock-Management — Stock-Status (`instock/outofstock`) und `_stock` werden vom Stock-Sync (Slice 37) verwaltet; Mapper laesst Stock-Felder unangetastet (POD = unlimited per Default).
- **Kein Schreiben von WC-Verkaufspreis** — `_regular_price`, `_sale_price`, `_price` werden **niemals** beruehrt. Mapper ist Read-Only auf diesen Feldern. SC-Cost wandert in separates Meta `_spreadconnect_cost`.
- Kein Loeschen von WC-Produkten oder -Variations — entfallene Variations werden auf `private` archiviert (AC-8). Hard-Delete wuerde Order-Item-Historie verletzen.
- Kein Logging-Adapter-Wiring — `WcLoggerAdapter` (Slice 42) ist noch nicht verfuegbar; bei Bedarf `error_log`-Stub erlaubt mit FIXME-Kommentar.
- Keine Designs-Read-Logik (`GET /designs`) — der Mapper bekommt fertige `Preview`/`attachment_id`-Daten; Design-Discovery ist Slice 36.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile nach `<?php`.
- Klasse `final class ProductMapper` (nicht erweiterbar).
- Method-Signaturen exakt wie im Integration Contract dokumentiert (Return-Type `int` Pflicht, DTO-Argumente non-nullable).
- Reverse-Lookup MUSS via `get_posts()` mit `meta_query` (Key `_spreadconnect_article_id`) erfolgen — **nicht** ueber rohe `$wpdb`-Queries auf `wp_postmeta`. WC-API ist Source of Truth (siehe Constraint-Tabelle Slice 20 fuer dieselbe Konvention).
- `ProductMapperException` MUSS `extends \RuntimeException` sein (analog `AttributeProvisionerException` aus Slice 20), damit Action-Scheduler in Slice 23 sie als "permanent failure" einstuft und nicht retried.
- WC-Variation-Erzeugung: pro Variant ein `WC_Product_Variation`-Objekt mit `set_parent_id($parentId)`, `set_attributes(['pa_groesse'=>$sizeTermSlug, 'pa_farbe'=>$colorTermSlug])`, `set_sku($variant->sku)`, `save()`. Reihenfolge der `save()`-Aufrufe: Parent zuerst, danach Variations.
- WC-Attribut-Term-Erzeugung: pro Size-Label und Color-Label aus `ProductTypeDetail` ein WC-Term in `pa_groesse`/`pa_farbe` per `term_exists()`-Check + `wp_insert_term()` falls fehlend; Term-Slug = sanitized Lowercase des Labels (z. B. `'S'` -> `'s'`, `'Schwarz'` -> `'schwarz'`).
- HPOS-Compliance: Mapper schreibt **Product**-Meta (nicht Order-Meta) — `update_post_meta($productId, ...)` ist hier korrekt (HPOS betrifft nur Order-Tabellen, nicht Posts).
- Idempotenz-Guard fuer Reverse-Lookup: der `meta_query`-Lookup MUSS `'numberposts'=>1` und `'post_status'=>['publish','draft','private']` setzen — andernfalls wuerde ein per Article-Removed-Webhook auf `draft` gesetztes Produkt nicht wiedergefunden und ein Duplikat erzeugt.
- Keine `error_log`/`var_dump`-Calls; Logging kommt mit Slice 42.

**Reuse:**

Slice 22 nutzt bestehende Bausteine; **keine** Neuimplementierung von DTOs, Mocking-Harness oder Plugin-Skeleton:

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/ArticleDetail.php` (Slice 09) | Import als Method-Argument-Type. Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/ProductTypeDetail.php` (Slice 09) | Import als Method-Argument-Type. Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/Variant.php` + `Money.php` (Slice 09) | Import zur Iteration ueber Variants und zum Zugriff auf `priceCalculation->amount`. Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Catalog/AttributeProvisioner.php` (Slice 20) | **Nicht** aufgerufen, aber **vorausgesetzt**: dass `pa_groesse`/`pa_farbe` durch ihn bereits angelegt sind. Mapper verlaesst sich auf den Activate-Hook + Pre-Sync-Guards. |
| `composer.json` (Root) | PSR-4-Mapping `SpreadconnectPod\\` -> `wordpress/plugins/spreadconnect-pod/includes/` aus Slice 02 wird unveraendert weiterverwendet. |
| `wordpress/plugins/spreadconnect-pod/tests/bootstrap/bootstrap.php` | Bestehender Brain\Monkey + Patchwork-Bootstrap aus Slice 02-21. Test-Writer ergaenzt minimale Stubs fuer `WC_Product_Variable`/`WC_Product_Variation`, falls noch nicht vorhanden — gehoert NICHT in die Deliverables-Liste. |
| `frontend/lib/product/variant-utils.ts` | **Nicht** veraendert. Frontend-Slug-Lookup liest weiterhin `pa_groesse`/`pa_farbe` (Codebase-Scan REUSE) — Konsistenz-Anker fuer Backend-Slug-Wahl. |

**Referenzen:**
- Architecture: `architecture.md` -> "Service Map" Zeile `Catalog\ProductMapper` (Layer Domain, Verantwortlichkeit "Article+ProductType+Previews -> WC_Product_Variable / WC_Product_Variation upsert").
- Architecture: `architecture.md` -> "WC-Product Meta" + "WC-Variation Meta" Tabellen (Section "Database Schema") — vollstaendige Meta-Key-Liste mit Validation-Regeln; Mapper ist Source-Of-Truth-Konsument dieser Tabelle.
- Architecture: `architecture.md` -> "Data Transfer Objects (DTOs)" Zeilen `ArticleDetail`, `Variant`, `Money` (Method-Argument-Schema).
- Architecture: `architecture.md` -> "Assumptions & Constraints" -> "Pricing: WC-price stays manual; SC-cost stored as meta + margin display" (zentrales Trade-off, AC-3 Vertragsgrundlage).
- Discovery: `discovery.md` -> Slice 4 "Catalog-Sync" -> Bullets "WC-Variable-Product idempotent", "WC-Preis wird niemals vom Sync ueberschrieben", "WC-Variation-Stock-Mgmt bleibt off", "SKU-Format: SC-SKU 1:1 als WC-Variation-SKU", "Article-Removal setzt WC-Produkt-Status auf `draft`. Niemals `delete`".
- Discovery: `discovery.md` -> Flow B "Initial Catalog Sync" Punkt 4 ("Erstellt/Updated WC-Variable-Product mit Variations pro Size/Color (SKU = SC-SKU)").
- Slim-Slices: `slices/slim-slices.md` -> Slice-22-Eintrag (Done-Signal: erster Aufruf -> WC-Product erstellt; zweiter Aufruf mit selber `article_id` -> Update, kein Duplicate; WC-Preis bleibt unveraendert ueber Calls).
- Wireframes: `wireframes.md` — **nicht direkt relevant** (Mapper ist Domain-Service ohne UI-Touch); Konsumenten-UI ist Standard-WC `WooCommerce -> Produkte` Listen-/Edit-Screen, der bereits durch WC-Core gerendert wird.
