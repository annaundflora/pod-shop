# Slice 34: Product-Edit-Meta-Box (Article-Picker + Margin + Stock)

> **Slice 34 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-34-product-meta-box-margin-stock` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-22-product-mapper", "slice-23-sync-article-job"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WordPress 6.9 + WC 10.5 + JS/jQuery + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: `wp-admin/post.php?post={id}&action=edit` rendert Meta-Box; Picker-Search und Margin-Recalc browser-getestet) |
| **Health Endpoint** | `n/a` (Inline-UI + AJAX-Handler) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `add_meta_box`, `add_action`, `wp_enqueue_script`, `check_ajax_referer`, `current_user_can`, `wp_send_json_*`, `get_post_meta`, `update_post_meta`, `delete_post_meta`, `get_transient`, `set_transient`, `as_enqueue_async_action`, `wp_get_post_terms`; `SpreadconnectClient` als Konstruktor-Injectable mit Mockery-Mock) |

---

## Ziel

Liefert die WC-Product-Edit-Sidebar-Meta-Box "Spreadconnect" mit drei States: (1) `unlinked` -> debounced Article-Picker via `getArticles(search)`, (2) `linked` -> Cost/Margin-Block + Live-Stock-Tabelle (5min Transient-Cache) + Re-Sync-Button, (3) `removed_in_sc` -> Banner + Re-Create-Action. Liefert ausserdem die drei AJAX-Handler `link_article`, `unlink_article`, `refresh_stock` und das clientseitige JS fuer 300ms-debounced Search und Live-Margin-Recalc bei Preis-Form-Change (rot/gelb/gruen).

---

## Acceptance Criteria

1) **GIVEN** ein WC-Variable-Product ohne Postmeta `_spreadconnect_article_id` und ein eingeloggter Admin mit `manage_woocommerce`
   **WHEN** die Product-Edit-Page (`post.php?post={id}&action=edit`) gerendert wird
   **THEN** registriert `Inline\ProductMetaBox::register()` per `add_meta_boxes`-Hook eine Meta-Box mit ID `spreadconnect_product_meta_box`, Titel `'Spreadconnect'`, Screen `'product'`, Position `'side'`, Priority `'default'`. Im Body wird der `unlinked`-Zustand gerendert (siehe wireframes.md -> Screen 9 -> "unlinked — Article Picker ASCII"): Such-Input, leere Result-Liste, "Open SC dashboard"-Link. Es werden **keine** Cost-/Margin-/Stock-Blocks gerendert.

2) **GIVEN** das Product-Edit-Screen aus AC-1 wird mit Picker-Such-Input bedient
   **WHEN** der User in das Search-Input tippt (z. B. `"shirt"`)
   **THEN** triggert `assets/js/product-meta-box.js` einen `300ms`-debounced `POST admin-ajax.php?action=spreadconnect_search_articles` mit Body `{nonce, search:'shirt'}`. Der serverseitige Handler `Hub\Ajax\ProductActions::searchArticles()` ruft `SpreadconnectClient::getArticles(0, 20, 'shirt')` (Slice 10 Wrapper #2). Bei Erfolg wird `wp_send_json_success(['items'=>[{id,title,thumbUrl,sizes,colors}, ...], 'total'=>N])` zurueckgegeben. Das JS rendert die Treffer als klickbare Cards (Thumbnail + Title + Article-ID + variant-summary) im Result-Container. Leerer Search-String triggert denselben Call mit `null`-Search (Top-20 most-recent).

3) **GIVEN** ein gerenderter Picker mit Result-Liste und ein User klickt `[Link this article]` auf Card mit Article-ID `'88421'`
   **WHEN** das JS `POST admin-ajax.php?action=spreadconnect_link_article` mit Body `{nonce, product_id:42, article_id:'88421'}` sendet
   **THEN** verifiziert `Hub\Ajax\ProductActions::linkArticle()` (a) `current_user_can('manage_woocommerce')` -> sonst HTTP 403 + `wp_send_json_error(['message'=>'forbidden'], 403)`, (b) `check_ajax_referer('spreadconnect_product_actions', 'nonce')` -> sonst HTTP 403, (c) `update_post_meta(42, '_spreadconnect_article_id', '88421')` wird aufgerufen, (d) `as_enqueue_async_action('spreadconnect/sync_article', [['article_id'=>'88421']], 'spreadconnect')` wird aufgerufen (kein `run_id` -> webhook-getriggerter Pfad aus Slice 23 AC-10), (e) Response `wp_send_json_success(['linked'=>true, 'article_id'=>'88421'])`. Das JS reloaded die Meta-Box per Page-Reload oder `location.reload()`.

4) **GIVEN** ein WC-Variable-Product mit Postmeta `_spreadconnect_article_id='88421'`, `_spreadconnect_cost='12.34'`, `_spreadconnect_cost_currency='EUR'`, `_spreadconnect_last_sync=1714723200`, `_spreadconnect_sync_state='synced'` und drei Variations mit SKUs `['TS-S-RED','TS-M-RED','TS-L-RED']`
   **WHEN** die Meta-Box gerendert wird
   **THEN** rendert `Inline\ProductMetaBox` (siehe wireframes.md Screen 9 Block ②③④⑤⑥): (a) "Article-ID: 88421" + `[Unlink]`-Button, (b) Cost-Display "SC Cost: 12.34 EUR", (c) WC-Price aus erster Variation (Server-side via `WC_Product::get_price()` Initial-Render), (d) Margin als JS-data-attribute (`data-sc-cost="12.34"`) am Container — Initial-Berechnung im JS, NICHT serverseitig, (e) Stock-Tabelle mit den 3 Variation-SKUs (Stock-Werte siehe AC-6), (f) Sync-Status-Block mit Last-Sync-Time + State-Badge + `[Re-Sync now]`-Button. Cost/Margin/Stock/Sync-Bloecke werden **nur** gerendert wenn `_spreadconnect_article_id` non-empty ist.

5) **GIVEN** das Product-Edit-Screen aus AC-4 ist offen und der Admin aendert das Form-Feld `#_regular_price` (oder Variation-Preise) von `29.90` auf `19.90`
   **WHEN** das `change` / `input`-Event auf dem Preis-Input feuert
   **THEN** liest `assets/js/product-meta-box.js` den neuen WC-Price-Wert, kombiniert ihn mit dem `data-sc-cost`-Attribut (`12.34`) aus AC-4, berechnet `margin_eur = wcPrice - cost` und `margin_pct = (margin_eur / wcPrice) * 100`, schreibt das Ergebnis in den Margin-Display-DOM-Knoten (`#sc-margin-value`) als `'7.56 EUR (38.0%)'` und setzt eine CSS-Klasse `sc-margin-low` (rot, `<20%`), `sc-margin-mid` (gelb, `20-40%`) oder `sc-margin-high` (gruen, `>40%`) auf den Container. Bei `wcPrice <= 0` oder `cost == null` wird der Display als `'—'` und Klasse `sc-margin-unknown` gesetzt (kein NaN, keine `Infinity`).

6) **GIVEN** das Product-Edit-Screen aus AC-4 ist offen, die Stock-Tabelle wird gerendert, und Transient `sc_stock_TS-S-RED` enthaelt `42` (aus vorherigem Refresh, < 5 min alt)
   **WHEN** die Meta-Box rendert
   **THEN** liest `Inline\ProductMetaBox` pro Variation-SKU `get_transient('sc_stock_'.$sku)`. Bei Cache-Hit wird der Wert direkt im Markup gerendert (Badge: `>=10` -> `'● Available'`, `1-9` -> `'⚠ Low (N)'`, `0` -> `'✗ Out'`). Bei Cache-Miss (Transient liefert `false`) wird `'…'` (Loading-Placeholder) gerendert. Es wird **kein** Synchronus-API-Call beim Page-Render gemacht (deferred-async; Refresh erfolgt manuell per Button in AC-7). "Last refresh"-Timestamp kommt aus separatem Transient `sc_stock_refresh_{productId}` (Unix-Time).

7) **GIVEN** der User klickt `[⟳ Refresh]` auf der Stock-Tabelle aus AC-6
   **WHEN** das JS `POST admin-ajax.php?action=spreadconnect_refresh_stock` mit Body `{nonce, product_id:42}` sendet
   **THEN** verifiziert `Hub\Ajax\ProductActions::refreshStock()` Cap+Nonce (analog AC-3), sammelt die Variation-SKUs des Products via `WC_Product_Variable::get_children()` -> `WC_Product_Variation::get_sku()`, ruft `SpreadconnectClient::getStock(null, $skus)` (Slice 10 Wrapper #23, Bulk-Call), schreibt pro `StockEntry` den Wert in `set_transient('sc_stock_'.$sku, $entry->quantity, 300)` (5 min) sowie `set_transient('sc_stock_refresh_'.$productId, time(), 300)`, und gibt `wp_send_json_success(['stock'=>['TS-S-RED'=>42, 'TS-M-RED'=>3, 'TS-L-RED'=>0], 'refreshed_at'=>1714724000])` zurueck. Das JS rendert die Tabelle mit den neuen Werten und setzt die Badge-Klassen analog AC-6.

8) **GIVEN** das Product-Edit-Screen aus AC-4 ist offen und der User klickt `[Unlink]`
   **WHEN** das JS nach Confirm-Dialog `POST admin-ajax.php?action=spreadconnect_unlink_article` mit `{nonce, product_id:42}` sendet
   **THEN** verifiziert `Hub\Ajax\ProductActions::unlinkArticle()` Cap+Nonce, ruft `delete_post_meta(42, '_spreadconnect_article_id')` und `delete_post_meta(42, ...)` fuer die weiteren `_spreadconnect_*`-Product-Meta-Keys (`_spreadconnect_product_type_id`, `_spreadconnect_cost`, `_spreadconnect_cost_currency`, `_spreadconnect_last_sync`, `_spreadconnect_sync_state` — siehe architecture.md "WC-Product Meta"). Variation-SKUs und WC-Variations bleiben unangetastet (architecture.md "Pricing-Trade-off": Verkaufspreis bleibt manuell). Response `wp_send_json_success(['unlinked'=>true])`. **Kein** API-Call zu Spreadconnect (Unlink ist eine WP-lokale Operation; SC-Subscription bleibt bestehen).

9) **GIVEN** das Product-Edit-Screen aus AC-4 ist offen und der User klickt `[Re-Sync now]`
   **WHEN** das JS `POST admin-ajax.php?action=spreadconnect_link_article` mit `{nonce, product_id:42, article_id:'88421', force:true}` sendet (Re-Sync nutzt denselben Handler wie Link, da identische Semantik: meta schreiben + sync_article enqueuen)
   **THEN** wird `as_enqueue_async_action('spreadconnect/sync_article', [['article_id'=>'88421']], 'spreadconnect')` aufgerufen (analog AC-3 Schritt d), `update_post_meta` ist no-op wenn meta bereits vorhanden ist (idempotent). Response `wp_send_json_success(['linked'=>true, 'article_id'=>'88421', 'enqueued'=>true])`. **Alternative Implementation:** Implementer darf einen separaten Handler `spreadconnect_resync_article` einfuehren, der nur das `as_enqueue_async_action` aufruft (ohne meta-write); Tests pruefen den enqueue-Aufruf, nicht den Handler-Namen.

10) **GIVEN** ein WC-Product mit `_spreadconnect_sync_state='removed_in_sc'` (gesetzt durch Slice 25 ArticleRemovedJob)
    **WHEN** die Meta-Box rendert
    **THEN** wird ein roter Banner am Top der Box gerendert: `'Article removed in Spreadconnect — WC product set to draft'` (siehe wireframes.md Screen 9 State `removed_in_sc`). Cost/Margin/Stock/Sync-Bloecke werden mit CSS-Klasse `sc-greyed` versehen (visuell deaktiviert) UND es wird ein `[Re-Sync (re-create)]`-Button gerendert, der wie AC-9 `as_enqueue_async_action('spreadconnect/sync_article', ...)` triggert. Der Unlink-Button bleibt verfuegbar (User kann manuell entkoppeln, falls Article in SC permanent geloescht wurde).

11) **GIVEN** der Plugin-Bootstrap (Slice 02) ist initialisiert
    **WHEN** der Bootstrap-Hook `init_actions()` (oder Aequivalent) laeuft
    **THEN** sind registriert: (a) `add_action('add_meta_boxes', [ProductMetaBox::class, 'register'])`, (b) `add_action('admin_enqueue_scripts', [ProductMetaBox::class, 'enqueueAssets'])` mit Screen-Check (`get_current_screen()->post_type === 'product'`) und Localize-Script-Aufruf (`wp_localize_script('spreadconnect-product-meta-box', 'spreadconnectProductMetaBox', ['ajaxUrl'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('spreadconnect_product_actions'), 'productId'=>$postId])`), (c) `add_action('wp_ajax_spreadconnect_link_article', [ProductActions::class, 'linkArticleStatic'])`, (d) `add_action('wp_ajax_spreadconnect_unlink_article', [ProductActions::class, 'unlinkArticleStatic'])`, (e) `add_action('wp_ajax_spreadconnect_refresh_stock', [ProductActions::class, 'refreshStockStatic'])`, (f) `add_action('wp_ajax_spreadconnect_search_articles', [ProductActions::class, 'searchArticlesStatic'])`. Alle Handler nutzen das Bridge-Pattern aus Slice 17/23 (`*Static`-Methoden resolven DI-Container). `nopriv`-Hooks werden NICHT registriert (Admin-only).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey mocked `add_meta_box`, `add_action`, `wp_enqueue_script`, `wp_localize_script`, `current_user_can`, `check_ajax_referer`, `wp_send_json_success`, `wp_send_json_error`, `get_post_meta`, `update_post_meta`, `delete_post_meta`, `get_transient`, `set_transient`, `as_enqueue_async_action`. `SpreadconnectClient` wird als Konstruktor-Argument injiziert und mit Mockery gemockt. JS-Verhalten (Debounce, Margin-Recalc, Klassen-Toggle) wird **nicht** im PHPUnit getestet — Manual-QA / Browser deckt das ab (siehe Done-Signal in slim-slices.md).

### Test-Datei: `tests/slices/pod-shop-mvp/slice-08-product-meta-box.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests\Inline;

use PHPUnit\Framework\TestCase;

final class ProductMetaBoxTest extends TestCase
{
    // AC-1: Meta-Box wird auf product-Screen registriert (unlinked-State rendert Picker)
    public function test_register_adds_meta_box_to_product_screen(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-1: Unlinked-State rendert KEINE Cost/Margin/Stock-Blocks
    public function test_render_unlinked_omits_cost_margin_stock_blocks(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: searchArticles ruft getArticles(0,20,$search) und liefert items+total
    public function test_search_articles_calls_client_and_returns_items(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: linkArticle schreibt _spreadconnect_article_id und enqueued sync_article
    public function test_link_article_writes_meta_and_enqueues_sync_job(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: linkArticle ohne manage_woocommerce -> 403
    public function test_link_article_rejects_user_without_capability(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: linkArticle mit invalid Nonce -> 403
    public function test_link_article_rejects_invalid_nonce(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: Linked-State rendert Cost/Margin-Container mit data-sc-cost-Attribut
    public function test_render_linked_emits_cost_data_attribute(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: Linked-State rendert Stock-Tabelle mit Variation-SKUs
    public function test_render_linked_renders_stock_table_per_variation_sku(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-6: Stock-Render liest Transient sc_stock_{sku}; kein API-Call beim Render
    public function test_render_reads_stock_from_transient_without_api_call(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: refreshStock ruft getStock(null,$skus) bulk und schreibt Transients
    public function test_refresh_stock_bulk_call_writes_transients(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: refreshStock liefert {stock:{sku:qty}, refreshed_at} JSON
    public function test_refresh_stock_returns_stock_map_and_timestamp(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: unlinkArticle delete'd alle _spreadconnect_*-Meta-Keys
    public function test_unlink_article_deletes_all_spreadconnect_meta_keys(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: unlinkArticle macht KEINEN API-Call zu Spreadconnect
    public function test_unlink_article_does_not_call_spreadconnect_api(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: Re-Sync triggert as_enqueue_async_action('spreadconnect/sync_article')
    public function test_resync_enqueues_sync_article_action(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: removed_in_sc-State rendert Banner + Re-Create-Button
    public function test_render_removed_in_sc_renders_banner_and_recreate_button(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: Bootstrap registriert add_meta_boxes + admin_enqueue_scripts + 4 wp_ajax_*-Hooks
    public function test_bootstrap_registers_all_required_hooks(): void
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
| `slice-10-endpoint-methods` | `SpreadconnectClient::getArticles(int $page, int $size, ?string $search): array`, `getStock(?string $productTypeId, ?array $skus): array` | Methoden | Slice 10 garantiert Pagination + Server-side-Search (Wrapper #2) und Bulk-SKU-Stock-Query (Wrapper #23). |
| `slice-22-product-mapper` | Postmeta-Keys `_spreadconnect_article_id`, `_spreadconnect_cost`, `_spreadconnect_cost_currency`, `_spreadconnect_last_sync`, `_spreadconnect_sync_state` | DB-Resource | Slice 22 schreibt diese Keys; Slice 34 liest sie. Reverse-Lookup via `meta_query` ist NICHT noetig (Meta-Box arbeitet auf bekannter `$post_id`). |
| `slice-23-sync-article-job` | Action-Hook `spreadconnect/sync_article` | Action-Scheduler-Hook | Slice 23 registriert den Handler; Slice 34 ist Producer (`as_enqueue_async_action(...)`). Args-Schema `['article_id'=>string]` (kein `run_id` -> Slice 23 AC-10 Webhook-Pfad). |
| `slice-09-dto-value-objects` | `Api\Dto\ArticleSummary`, `Api\Dto\StockEntry` | `final readonly` Value Objects | `searchArticles`-Handler iteriert ueber `ArticleSummary[]` aus `getArticles()`; `refreshStock` ueber `StockEntry[]` aus `getStock()`. |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::init()` Hook-Lifecycle | Bootstrap-Lifecycle | Slice 34 erweitert die bestehende Hook-Registration (siehe AC-11). |
| WooCommerce-Core | `WC_Product_Variable::get_children()`, `WC_Product_Variation::get_sku()`, `WC_Product::get_price()`, `wc_get_product()` | WC-API ≥ 3.6 | Standard-WC. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `Inline\ProductMetaBox::register` / `::render` | static methods (WP-Hook-Handler) | WP `add_meta_boxes`-Hook (Mount in `Bootstrap\Plugin`) | `public static function register(): void`, `public static function render(\WP_Post $post): void` |
| `Hub\Ajax\ProductActions::linkArticle` / `::unlinkArticle` / `::refreshStock` / `::searchArticles` | Methods + statische Bridges (`*Static`) | WP `wp_ajax_spreadconnect_*`-Hooks (Mount in `Bootstrap\Plugin`) | `public function linkArticle(): void` (sendet JSON, exit); analog die anderen drei. Bridges: `public static function linkArticleStatic(): void` (DI-Resolve + Delegate). |
| Transient-Schluessel `sc_stock_{sku}` | Cache-Resource (5 min TTL) | `slice-36-stock-cache` (`Stock\StockCache` wraps denselben Schluessel — Source-of-Truth-Konsistenz); Stock-Sync-Job liest/ueberschreibt | Transient-Wert: `int` (quantity) oder `false` (cache-miss). |
| Transient-Schluessel `sc_stock_refresh_{productId}` | Cache-Resource (5 min TTL) | `slice-35-product-list-columns` (Last-Refresh-Anzeige in Spalte) | Transient-Wert: `int` (Unix-Timestamp). |
| `assets/js/product-meta-box.js` | JS-Asset (enqueued) | Browser (Product-Edit-Screen) | Localized Object `spreadconnectProductMetaBox = {ajaxUrl, nonce, productId}`. |

> **Hinweis Mount-Point-Check:** `ProductMetaBox` und `ProductActions` sind Adapter-Klassen, die durch WP-Hooks dispatched werden. Mount-Punkt ist die Hook-Registrierung in `Bootstrap\Plugin` — siehe AC-11 + Deliverable "Edit `includes/Bootstrap/Plugin.php`". Ohne diese Edit waere die Meta-Box tot (kein `add_meta_box`-Call) und die AJAX-Handler unaufrufbar.

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Inline/ProductMetaBox.php` — `final class Inline\ProductMetaBox` mit `public static function register(): void` (registriert via `add_meta_box`), `public static function render(\WP_Post $post): void` (dispatched zwischen `unlinked`/`linked`/`removed_in_sc`-Templates), `public static function enqueueAssets(string $hookSuffix): void` (Screen-Guard + `wp_enqueue_script` + `wp_localize_script`). Liest Postmeta `_spreadconnect_*` und Transients `sc_stock_*`.
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Hub/Ajax/ProductActions.php` — `final class Hub\Ajax\ProductActions` mit Konstruktor-DI (`SpreadconnectClient`) und vier Handler-Methoden `linkArticle`, `unlinkArticle`, `refreshStock`, `searchArticles` plus Static-Bridges (`linkArticleStatic`, ...). Jeder Handler: Cap+Nonce -> Delegation -> `wp_send_json_success/error`.
- [ ] `wordpress/plugins/spreadconnect-pod/assets/js/product-meta-box.js` — Vanilla-JS oder jQuery (Implementer-Wahl, konsistent mit Slice 32 `order-meta-box.js`). Liefert: 300ms-Debounced-Search auf Picker-Input, Margin-Live-Recalc auf Preis-Form-Change-Events (`#_regular_price`, `#_sale_price`, Variation-Preis-Inputs), Click-Handler fuer `[Link]`/`[Unlink]`/`[Refresh-Stock]`/`[Re-Sync]`-Buttons, DOM-Update der Stock-Tabelle nach `refresh_stock`-Response. Nutzt `spreadconnectProductMetaBox.{ajaxUrl, nonce, productId}` aus Localize.
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Ergaenzt die sechs Hook-Registrierungen aus AC-11 (`add_meta_boxes`, `admin_enqueue_scripts`, `wp_ajax_spreadconnect_link_article`, `_unlink_article`, `_refresh_stock`, `_search_articles`).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Dateien gehoeren NICHT in Deliverables. Der Test-Writer-Agent erstellt `tests/slices/pod-shop-mvp/slice-08-product-meta-box.php` basierend auf den Test Skeletons oben.

---

## Constraints

**Scope-Grenzen:**
- **Kein** `Stock\StockCache`-Wrapper — der Transient-Schluessel `sc_stock_{sku}` wird in Slice 34 direkt via `get_transient`/`set_transient` benutzt. Slice 36 wraps denselben Schluessel spaeter als Service-Klasse; bis dahin nutzt Slice 34 die WP-Transient-API direkt (Konsistenz-Anker: gleicher Key-Name).
- **Kein** Server-Side-Margin-Berechnung — Margin wird **ausschliesslich** clientseitig in JS aus `data-sc-cost`-Attribut + Live-Form-Wert berechnet. Server liefert nur Cost als Roh-Decimal.
- **Kein** Schreiben von `_regular_price` / `_sale_price` / `_price` — Verkaufspreis bleibt komplett admin-kontrolliert (Discovery Slice 4 Trade-off; bestaetigt durch Slice 22 AC-3).
- **Kein** Product-List-Spalten — die Spalten "SC-Linked / SC-Cost / Margin" gehoeren zu Slice 35.
- **Kein** Order-Meta-Box-Code — Order-Meta-Box ist Slice 32; AJAX-Handler sind separat (`OrderActions`).
- **Kein** automatischer Stock-Refresh on-Render — der Initial-Render zeigt nur Cache-Werte (siehe AC-6); Refresh ist manueller User-Klick (AC-7) ODER kommt aus Slice 36 Stock-Sync-Job. Async-Auto-Refresh-on-Page-Render (architecture.md "Live stock cache deferred async") ist explizit Slice 36 Scope.
- **Kein** Edit von `WC_Product_Variation`-Properties — Meta-Box liest nur (SKUs, Cost, Stock).
- **Kein** Subscription-Handling beim Unlink — `_spreadconnect_article_id` zu loeschen entfernt nur das WC-seitige Linking; SC behaelt den Article. Reverse-Sync auf SC ist explizit non-goal (Discovery: einseitiger Pull).
- **Kein** FailedOps-Wiring — bei AJAX-Fehler reicht JSON-Error-Response; Slice 37 wired das spaeter fuer Async-Job-Failures.
- **Keine** REST-API-Routes — alle Endpoints laufen ueber `admin-ajax.php` (konsistent mit Slice 12/14/26/32).

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile.
- `final class` fuer alle drei PHP-Klassen.
- Nonce-Action-String fuer alle vier AJAX-Handler einheitlich: `'spreadconnect_product_actions'` (Localize-Script schreibt diesen Nonce, alle Handler verifizieren ihn).
- Capability-Check einheitlich: `current_user_can('manage_woocommerce')` (konsistent mit Slice 11/12/13/32).
- AJAX-Hooks NUR `wp_ajax_*` (logged-in admin-only), KEIN `wp_ajax_nopriv_*`.
- Transient-Keys exakt `sc_stock_{sku}` und `sc_stock_refresh_{productId}` — kein Praefix-Salting (Konsistenz mit architecture.md "Cache-Keys" Tabelle und Slice 36).
- Transient-TTL `300` Sekunden (5 min) — Discovery Default, in spaeterem Slice 36 setting-konfigurierbar; in Slice 34 hardcoded 300.
- JS-Debounce `300ms` (Discovery + wireframes Screen 9 Picker-Behavior).
- JS-Margin-Klassen: `sc-margin-low` (red, `<20%`), `sc-margin-mid` (yellow, `20-40%`), `sc-margin-high` (green, `>40%`), `sc-margin-unknown` (grey, `cost==null` oder `wcPrice<=0`). Schwelle exakt `20.0` und `40.0` (architecture.md "Pricing-Trade-off"; wireframes Screen 9 Annotation ④).
- Variation-SKU-Sammlung im `refreshStock`-Handler MUSS via `WC_Product_Variable::get_children()` -> `wc_get_product()` -> `get_sku()` erfolgen (filter leerer SKUs). Rohe `$wpdb`-Queries auf `_sku`-Postmeta sind NICHT erlaubt (Konsistenz mit Slice 22).
- Localize-Script-Object-Name: `spreadconnectProductMetaBox` (camelCase analog WC-Core-Localizes wie `wc_product_meta_boxes_variations`).
- Bridge-Methoden (`*Static`): konsistent mit Slice 17 `ProcessWebhookEventJob` und Slice 23 `SyncArticleJob` Patterns (Container/Factory-Resolve in der statischen Methode, dann Delegate an Instanz-Method).
- `as_enqueue_async_action`-Args: `as_enqueue_async_action('spreadconnect/sync_article', [['article_id'=>$id]], 'spreadconnect')` — Action-Group `'spreadconnect'` analog Slice 23 AC-8.
- KEINE `error_log`/`var_dump`-Calls — Slice 42 wired Logger.

**Reuse:**

Slice 34 verbindet bestehende Bausteine; **keine** Neuimplementierung von Client, DTOs, Bootstrap oder bestehender JS-Helfer:

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Api/SpreadconnectClient.php` (Slice 07/08/10) | Konstruktor-Injection in `ProductActions`. Ruft `getArticles` (Picker) und `getStock` (Refresh-Stock). Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Api/Dto/ArticleSummary.php`, `StockEntry.php` (Slice 09) | Iteration ueber `ArticleSummary[]` (Picker-Result-Mapping) und `StockEntry[]` (Refresh-Stock-Mapping). Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02/17/23) | Edit-Target: ergaenzt sechs Hook-Registrierungen (siehe AC-11). Bestehender Pattern aus Slice 23 (Action-Hooks) wird wiederverwendet. |
| `wordpress/plugins/spreadconnect-pod/composer.json` (Slice 02) | Bestehendes PSR-4-Mapping `SpreadconnectPod\\` -> `includes/`. Unveraendert. |
| `wordpress/plugins/spreadconnect-pod/assets/js/order-meta-box.js` (Slice 32) | **Stilreferenz**, nicht Import. Slice 34 nutzt denselben Vanilla-JS-/jQuery-Stil und denselben Localize-Pattern. KEIN Code-Sharing — separate Datei. |
| WC-Core `WC_Product_Variable`/`WC_Product_Variation` | Standard-WC ≥ 3.6 API. Unveraendert. |
| WP-Core `add_meta_box`, `admin-ajax.php`, Transient-API | Standard-WP. Unveraendert. |

**Referenzen:**
- Architecture: `architecture.md` -> "Service Map" Zeile `Inline\ProductMetaBox` (Layer Adapter, Verantwortlichkeit "Product-Edit Spreadconnect box: cost+margin+stock+sync").
- Architecture: `architecture.md` -> "AJAX Action Inventory" Zeilen `spreadconnect_link_article`, `spreadconnect_unlink_article`, `spreadconnect_refresh_stock` (Z. 149-151) — Vertragsgrundlage fuer AC-3/7/8.
- Architecture: `architecture.md` -> "Outbound Endpoints" Z. 94 (`GET /articles` mit `search?`-Param fuer Picker) + Z. 120 (`GET /stock` Bulk + Transient `sc_stock_{sku}` 5 min).
- Architecture: `architecture.md` -> "WC-Product Meta" Tabelle (Z. 287-292) — Liste der `_spreadconnect_*`-Keys, die Slice 34 liest (linked-State) und beim Unlink loescht.
- Architecture: `architecture.md` -> "Cache-Keys" Tabelle Z. 350 (`sc_stock_{sku}` TTL 60-900s) — Konsistenz-Anker zu Slice 36.
- Wireframes: `wireframes.md` -> Screen 9 "Product-Edit Meta-Box (Inline-Erweiterung)" Z. 748-859 (komplett: Layout, State-Variations `unlinked`/`linked`/`removed_in_sc`, Picker-Verhalten, Margin-Farbcodierung, Stock-Badges).
- Discovery: `discovery.md` -> Slice 8 "Inline UX (Product + Order)" Z. 929 (Done-Signal: HPOS+Legacy + Margin-Live-Calc).
- Discovery: `discovery.md` -> Z. 115 (Margen-Berechnung Live, JS Recalc), Z. 182 (Live-Cache 5min), Z. 358-369 (Meta-Box Block "Cost & Margin" Spec), Z. 441-448 (Component-Spec `margin_display`, `live_stock_indicator`, `article_picker`).
- Slim-Slices: `slices/slim-slices.md` -> Slice-34-Eintrag (Done-Signal: Picker zeigt Search-Results; Margin updated live bei Preis-Change; PHPUnit `link_article` schreibt Meta + enqueued sync_article).
