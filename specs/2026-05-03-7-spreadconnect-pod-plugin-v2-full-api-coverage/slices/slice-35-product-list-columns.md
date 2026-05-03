# Slice 35: Product-List-Spalten + Filter

> **Slice 35 von 46** fuer `Spreadconnect POD Plugin v2 — Full API Coverage`

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-35-product-list-columns` |
| **Test** | `composer test` |
| **E2E** | `false` |
| **Dependencies** | `["slice-34-product-meta-box-margin-stock"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

| Key | Value |
|-----|-------|
| **Stack** | `php-wordpress-plugin` (PHP 8.2 + WordPress 6.9 + WC 10.5 + PHPUnit 11 + Brain\Monkey 2.6 + Patchwork) |
| **Test Command** | `composer test` |
| **Integration Command** | `composer test` |
| **Acceptance Command** | `composer test` |
| **Start Command** | `docker compose up -d` (manuelle QA: `wp-admin/edit.php?post_type=product` zeigt 3 neue Spalten + Filter-Dropdown; Filter `?sc_filter=low_margin` reduziert die Liste) |
| **Health Endpoint** | `n/a` (Inline-Admin-List-Adapter, kein HTTP-Endpoint) |
| **Mocking Strategy** | `mock_external` (Brain\Monkey fuer `add_filter`, `add_action`, `get_post_meta`, `wc_get_product`, `current_user_can`, `selected`, `esc_*`; `WP_Query`-Hooks via direkte Closure-Invocation der registrierten Filter — kein DB-Round-Trip) |

---

## Ziel

Erweitert die native WC-Product-List (`edit.php?post_type=product`) um drei Spalten ("SC-Linked", "SC-Cost", "Margin") und ein Filter-Dropdown ("All / Linked / Unlinked / Margin <20%"). Margin-Zelle nutzt dieselbe Farbcodierung wie Slice 34 (`<20%` rot, `20-40%` gelb, `>40%` gruen). Filter-Logik joins `_spreadconnect_article_id` und `_spreadconnect_cost` per `pre_get_posts` auf `wp_postmeta` ohne raw `$wpdb`.

---

## Acceptance Criteria

1) **GIVEN** der Plugin-Bootstrap (Slice 02) ist initialisiert und ein Admin oeffnet `wp-admin/edit.php?post_type=product`
   **WHEN** der Hook `manage_edit-product_columns` feuert
   **THEN** registriert `Inline\ProductListColumns::registerColumns(array $columns): array` drei neue Spalten in dieser Reihenfolge **nach** der Spalte `price` (Fallback: vor `categories` falls `price` fehlt): `sc_linked` (Header `__('SC-Linked', 'spreadconnect-pod')`), `sc_cost` (Header `__('SC-Cost', 'spreadconnect-pod')`), `sc_margin` (Header `__('Margin', 'spreadconnect-pod')`). Bestehende WC-Spalten bleiben unveraendert in ihrer Reihenfolge. Returnwert ist das gemergte Columns-Array.

2) **GIVEN** ein WC-Variable-Product mit Postmeta `_spreadconnect_article_id='88421'`, `_spreadconnect_cost='12.34'`, `_spreadconnect_cost_currency='EUR'` und WC-Price `29.90`
   **WHEN** der Hook `manage_product_posts_custom_column` mit `column_name='sc_linked'` und `post_id=42` feuert
   **THEN** echo'd `Inline\ProductListColumns::renderColumn(string $column, int $postId): void` ein `'<span class="sc-linked-yes" aria-label="linked">✓</span>'`. Fuer ein Produkt **ohne** `_spreadconnect_article_id` echo'd der Handler `'<span class="sc-linked-no" aria-label="unlinked">—</span>'`. Beide Outputs sind durch `esc_html`/`wp_kses_post`-Wrapper abgesichert (siehe wireframes.md Screen 10 ③).

3) **GIVEN** das Produkt aus AC-2 mit `_spreadconnect_cost='12.34'` und `_spreadconnect_cost_currency='EUR'`
   **WHEN** der Hook `manage_product_posts_custom_column` mit `column_name='sc_cost'` feuert
   **THEN** echo'd der Handler den Cost via `wc_price(12.34, ['currency' => 'EUR'])` (analog WC-Core-Pattern). Bei fehlendem `_spreadconnect_cost` (Cache-Miss / unlinked) echo'd der Handler `'—'`. Bei `_spreadconnect_cost='0'` wird `wc_price(0)` ausgegeben (Null ist gueltiger Cost-Wert, **nicht** Dash).

4) **GIVEN** das Produkt aus AC-2 (`cost=12.34`, WC-Price `29.90`)
   **WHEN** der Hook `manage_product_posts_custom_column` mit `column_name='sc_margin'` feuert
   **THEN** berechnet der Handler **server-seitig** `margin_eur = 29.90 - 12.34 = 17.56` und `margin_pct = (17.56 / 29.90) * 100 = 58.7` und echo'd ein `<span class="sc-margin-high">17.56 € (58.7%) ●</span>` (siehe wireframes.md Screen 10 ⑤). Klassen-Mapping identisch zu Slice 34 AC-5 / Slice 34 Constraints: `sc-margin-low` (`<20.0%`), `sc-margin-mid` (`20.0-40.0%`), `sc-margin-high` (`>40.0%`). Bei `cost==null`, `wcPrice<=0` oder fehlendem `_spreadconnect_article_id` echo'd der Handler `'—'` mit Klasse `sc-margin-unknown` (kein NaN, kein `Infinity`, kein Division-by-Zero).

5) **GIVEN** ein Variable-Product ohne eigenen Hauptpreis (`get_price()` liefert `''`), aber mit Variations-Preisen
   **WHEN** der `sc_margin`-Handler aus AC-4 feuert
   **THEN** liest der Handler `wc_get_product($postId)->get_price()` (Variable-Products liefern den Min-Preis) und nutzt diesen als WC-Price. Liefert `get_price()` weiterhin leeren String oder `null`, faellt der Handler auf den `'—'` / `sc-margin-unknown`-Pfad (analog AC-4 Edge-Case).

6) **GIVEN** der Hook `manage_edit-product_sortable_columns` feuert
   **WHEN** der Filter `Inline\ProductListColumns::registerSortableColumns(array $columns): array` greift
   **THEN** sind `sc_linked`, `sc_cost` und `sc_margin` als sortable markiert mit Sort-Keys `sc_linked`, `sc_cost`, `sc_margin`. (Margin-Sort = sortiert nach `_spreadconnect_cost`-Meta, da WC-Price-Sort nicht reichweitig genug ist; Discovery Z. 446 "low_margin sortierbar"). Bestehende WC-Sortable-Columns bleiben unveraendert.

7) **GIVEN** die Product-List wird mit Query-Var `?orderby=sc_cost&order=ASC` aufgerufen
   **WHEN** der Hook `pre_get_posts` feuert und `$query->is_main_query() && is_admin() && $query->get('post_type')==='product'`
   **THEN** setzt `Inline\ProductListColumns::applySorting(\WP_Query $query): void` `$query->set('meta_key', '_spreadconnect_cost')` und `$query->set('orderby', 'meta_value_num')`. Fuer `orderby=sc_linked` wird `meta_key='_spreadconnect_article_id'` mit `orderby='meta_value'` gesetzt. Fuer `orderby=sc_margin` analog `meta_key='_spreadconnect_cost'` (Server-side-Margin-Sort = Cost-DESC-Approximation; eine echte computed-Sort ist non-goal — Discovery deferred). Auf Non-Main-Queries oder Non-Admin-Screens NO-OP.

8) **GIVEN** der Hook `restrict_manage_posts` feuert auf `post_type='product'`
   **WHEN** `Inline\ProductListColumns::renderFilterDropdown(string $postType): void` greift
   **THEN** echo'd ein `<select name="sc_filter">`-Element mit vier Optionen: `''` (Label `__('Spreadconnect: All', 'spreadconnect-pod')`), `'linked'` (Label `__('Linked', 'spreadconnect-pod')`), `'unlinked'` (Label `__('Unlinked', 'spreadconnect-pod')`), `'low_margin'` (Label `__('Margin <20%', 'spreadconnect-pod')`). Aktueller Wert wird via `selected($_GET['sc_filter'] ?? '', $optionValue)` markiert. Andere `post_type`-Screens (z. B. `shop_order`) -> NO-OP (Early-Return).

9) **GIVEN** Admin klickt `[Filter]` mit Auswahl `?sc_filter=linked`
   **WHEN** `Inline\ProductListColumns::applyFilter(\WP_Query $query): void` im `pre_get_posts`-Hook greift
   **THEN** setzt der Handler `$query->set('meta_query', [[ 'key'=>'_spreadconnect_article_id', 'compare'=>'EXISTS' ]])` (existierende meta_query-Eintraege werden via `array_merge` mit `'relation'=>'AND'` erhalten). Fuer `sc_filter=unlinked` wird `compare=>'NOT EXISTS'` gesetzt. Server filtert die Query — kein Client-side-Filter, kein raw `$wpdb`-Join.

10) **GIVEN** Admin waehlt `?sc_filter=low_margin`
    **WHEN** `applyFilter` greift
    **THEN** setzt der Handler eine `meta_query` mit zwei Klauseln: `[ 'key'=>'_spreadconnect_cost', 'compare'=>'EXISTS' ]` AND `[ 'key'=>'_spreadconnect_article_id', 'compare'=>'EXISTS' ]` (relation `AND`). Zusaetzlich registriert der Handler temporaer einen `posts_clauses`-Filter, der nur fuer **diese** Query greift und einen `HAVING ((_price - _spreadconnect_cost) / _price) * 100 < 20` aequivalenten WP-Meta-Approach via `meta_query` mit `'type'=>'NUMERIC'`-Compare einsetzt. **Implementer-Wahl:** entweder (a) zwei-Pass via `posts_clauses`-Closure, die `wp_postmeta`-Aliase joint und die Margin-Rate in `HAVING` filtert; oder (b) ein Post-Query-Filter via `the_posts`, der die ID-Liste reduziert (langsamer, aber raw-SQL-frei). Done-Signal aus slim-slices.md verlangt: "Margin <20% filtert nur Produkte mit Cost+WC-Preis" — d. h. Produkte ohne `_spreadconnect_cost` ODER ohne `_price` werden ausgeblendet (kein Division-by-Zero, kein NULL-Margin in Selection).

11) **GIVEN** der Plugin-Bootstrap (Slice 02) wird initialisiert
    **WHEN** der Bootstrap-Hook `init_actions()` (oder Aequivalent) laeuft
    **THEN** sind registriert: (a) `add_filter('manage_edit-product_columns', [ProductListColumns::class, 'registerColumnsStatic'])`, (b) `add_action('manage_product_posts_custom_column', [ProductListColumns::class, 'renderColumnStatic'], 10, 2)`, (c) `add_filter('manage_edit-product_sortable_columns', [ProductListColumns::class, 'registerSortableColumnsStatic'])`, (d) `add_action('pre_get_posts', [ProductListColumns::class, 'preGetPostsStatic'])` (haendelt sowohl AC-7 Sorting als auch AC-9/10 Filtering in einer Closure), (e) `add_action('restrict_manage_posts', [ProductListColumns::class, 'renderFilterDropdownStatic'])`. Alle Static-Bridges resolven Container/Factory analog Slice 17/23/34. Capability-Check `current_user_can('manage_woocommerce')` schuetzt Sort-/Filter-Mutations (siehe Constraints).

---

## Test Skeletons

> **Fuer den Test-Writer-Agent:** Brain\Monkey mocked `add_filter`, `add_action`, `get_post_meta`, `wc_get_product`, `wc_price`, `current_user_can`, `selected`, `esc_html`, `__`. `\WP_Query`-Hooks werden durch direkte Aufrufe der registrierten Closures auf einem Mockery-`WP_Query`-Mock getestet (kein WP-Bootstrap, kein DB-Roundtrip). `_GET['sc_filter']` wird im Test-Setup pro Case gesetzt.

### Test-Datei: `tests/slices/pod-shop-mvp/slice-08-product-list.php`

<test_spec>
```php
<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests\Inline;

use PHPUnit\Framework\TestCase;

final class ProductListColumnsTest extends TestCase
{
    // AC-1: Drei Spalten in korrekter Reihenfolge nach `price` registriert
    public function test_register_columns_inserts_three_columns_after_price(): void
    {
        $this->markTestIncomplete('AC-1');
    }

    // AC-2: sc_linked rendert Check-Mark fuer linked, Dash fuer unlinked
    public function test_render_sc_linked_renders_checkmark_when_article_id_present(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-2: sc_linked Dash + sc-linked-no Klasse fuer unlinked Produkt
    public function test_render_sc_linked_renders_dash_when_unlinked(): void
    {
        $this->markTestIncomplete('AC-2');
    }

    // AC-3: sc_cost nutzt wc_price() mit Currency aus Postmeta
    public function test_render_sc_cost_formats_with_wc_price_and_currency(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-3: sc_cost rendert Dash bei fehlendem Postmeta
    public function test_render_sc_cost_renders_dash_when_missing(): void
    {
        $this->markTestIncomplete('AC-3');
    }

    // AC-4: sc_margin berechnet euro+pct + Klasse sc-margin-high (>40%)
    public function test_render_sc_margin_emits_high_class_above_40_percent(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: sc_margin emits sc-margin-mid bei 20-40%
    public function test_render_sc_margin_emits_mid_class_between_20_and_40(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: sc_margin emits sc-margin-low bei <20%
    public function test_render_sc_margin_emits_low_class_below_20(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-4: sc_margin emits sc-margin-unknown bei wcPrice=0 (kein Division-by-Zero)
    public function test_render_sc_margin_emits_unknown_class_on_zero_price(): void
    {
        $this->markTestIncomplete('AC-4');
    }

    // AC-5: sc_margin liest Variable-Product-Min-Price via WC_Product::get_price()
    public function test_render_sc_margin_reads_variable_product_min_price(): void
    {
        $this->markTestIncomplete('AC-5');
    }

    // AC-6: Sortable-Columns registriert sc_linked + sc_cost + sc_margin
    public function test_register_sortable_columns_marks_three_columns_sortable(): void
    {
        $this->markTestIncomplete('AC-6');
    }

    // AC-7: pre_get_posts mit orderby=sc_cost setzt meta_key+orderby
    public function test_pre_get_posts_applies_sort_for_sc_cost(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-7: Non-Main-Query bleibt unveraendert (NO-OP)
    public function test_pre_get_posts_skips_non_main_queries(): void
    {
        $this->markTestIncomplete('AC-7');
    }

    // AC-8: Filter-Dropdown rendert vier Optionen mit selected-State
    public function test_render_filter_dropdown_emits_four_options(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-8: Filter-Dropdown auf shop_order-Screen -> NO-OP
    public function test_render_filter_dropdown_skips_non_product_post_type(): void
    {
        $this->markTestIncomplete('AC-8');
    }

    // AC-9: sc_filter=linked setzt meta_query EXISTS
    public function test_apply_filter_linked_sets_meta_query_exists(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-9: sc_filter=unlinked setzt meta_query NOT EXISTS
    public function test_apply_filter_unlinked_sets_meta_query_not_exists(): void
    {
        $this->markTestIncomplete('AC-9');
    }

    // AC-10: sc_filter=low_margin filtert nur Produkte mit Cost+WC-Preis
    public function test_apply_filter_low_margin_excludes_products_without_cost_or_price(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-10: sc_filter=low_margin Margin-Berechnung filtert <20%
    public function test_apply_filter_low_margin_only_returns_products_below_20_percent(): void
    {
        $this->markTestIncomplete('AC-10');
    }

    // AC-11: Bootstrap registriert alle 5 Hooks
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
| `slice-22-product-mapper` | Postmeta-Keys `_spreadconnect_article_id`, `_spreadconnect_cost`, `_spreadconnect_cost_currency` | DB-Resource (postmeta) | Slice 22 schreibt diese Keys auf Catalog-Sync; Slice 35 liest sie pro Row im List-View. |
| `slice-34-product-meta-box-margin-stock` | CSS-Klassen-Konvention `sc-margin-low/mid/high/unknown` (Schwellen `20.0` / `40.0`) | CSS-Class-Vertrag | Slice 34 hat dieselbe Margin-Farbcodierung im Meta-Box etabliert; Slice 35 wiederverwendet die Klassen 1:1 (kein neues CSS). |
| `slice-02-plugin-bootstrap` | `Bootstrap\Plugin::init()` Hook-Lifecycle | Bootstrap | Edit-Target: ergaenzt fuenf Hook-Registrierungen aus AC-11 (siehe Deliverables). |
| WooCommerce-Core | `WC_Product::get_price()`, `wc_get_product()`, `wc_price()` | WC-API ≥ 3.6 | Standard-WC. |
| WP-Core | Hooks `manage_edit-product_columns`, `manage_product_posts_custom_column`, `manage_edit-product_sortable_columns`, `pre_get_posts`, `restrict_manage_posts` | WP-Core ≥ 4.3 | Standard-WP-Admin-List-Hooks. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `Inline\ProductListColumns` | `final class` mit Static-Bridges | WP `manage_edit-product_columns` etc. (Mount in `Bootstrap\Plugin`) | `public static function registerColumns(array $columns): array`, `public static function renderColumn(string $column, int $postId): void`, `public static function registerSortableColumns(array $columns): array`, `public static function preGetPosts(\WP_Query $query): void`, `public static function renderFilterDropdown(string $postType): void` (plus `*Static`-Bridges analog Slice 34 Pattern). |
| Sort-Query-Vars `orderby=sc_linked\|sc_cost\|sc_margin` | URL-Query-Var-Vertrag | Admins (kein nachfolgender Slice) | Mapped auf Postmeta-Sort. |
| Filter-Query-Var `?sc_filter=linked\|unlinked\|low_margin` | URL-Query-Var-Vertrag | Admins (kein nachfolgender Slice) | Mapped auf `meta_query`. |

> **Hinweis Mount-Point-Check:** `ProductListColumns` ist eine reine Adapter-Klasse, dispatched durch WP-Hooks. Mount-Punkt = Hook-Registrierung in `Bootstrap\Plugin` (siehe AC-11 + Deliverables). Ohne den `Plugin.php`-Edit waeren die Spalten/Filter tot.

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
- [ ] `wordpress/plugins/spreadconnect-pod/includes/Inline/ProductListColumns.php` — `final class Inline\ProductListColumns` mit den fuenf Public-Methoden aus AC-1/2-3/6/7/8 plus Static-Bridges analog Slice 34. Liest Postmeta `_spreadconnect_*` und WC-Price. Setzt `meta_query` / `orderby` / `meta_key` per `pre_get_posts`. Echo'd HTML-Outputs fuer Spalten und Filter-Dropdown. Keine eigene CSS-Datei (Klassen aus Slice 34 wiederverwendet).
- [ ] Edit `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` — Ergaenzt die fuenf Hook-Registrierungen aus AC-11 (`manage_edit-product_columns`, `manage_product_posts_custom_column`, `manage_edit-product_sortable_columns`, `pre_get_posts`, `restrict_manage_posts`).
<!-- DELIVERABLES_END -->

> **Hinweis:** Test-Datei `tests/slices/pod-shop-mvp/slice-08-product-list.php` gehoert NICHT in Deliverables — der Test-Writer-Agent erstellt sie aus den Skeletons oben. (slim-slices.md listet die Test-Datei als Deliverable; das ist nicht regelkonform und wird hier korrigiert.)

---

## Constraints

**Scope-Grenzen:**
- **Kein** Bulk-Action — Bulk-Resend gehoert zu Slice 33 (Order-List) und Slice 40 (BulkResendCoordinator) bzw. ist fuer Product-List nicht im Scope dieses Features (Discovery Slice 8 nennt Bulk nur fuer Orders).
- **Kein** Margin-Live-Recalc-JS — Margin wird **server-seitig** im List-View berechnet (Static-Render); JS-Live-Recalc gehoert ausschliesslich zu Slice 34 Meta-Box.
- **Kein** AJAX-Handler — Slice 35 ist rein Hook-basiert (kein `wp_ajax_*`).
- **Kein** Schreiben von Postmeta — Slice 35 liest nur (`get_post_meta`); jede Mutation gehoert zu Slice 22 / 23 / 34.
- **Kein** raw `$wpdb`-Query — alle DB-Zugriffe ueber `WP_Query`/`meta_query` oder `posts_clauses`-Filter (siehe AC-10 Implementer-Wahl).
- **Kein** neues CSS-Stylesheet — Slice 35 setzt CSS-Klassen, die Slice 34 (`assets/js/product-meta-box.js` + zugehoeriges CSS oder `wp_add_inline_style`) bereits enthaelt. Falls Slice 34 ein dediziertes CSS-Asset einfuehrt, MUSS dies fuer Product-List-Screens ebenfalls enqueued sein — Bootstrap-Edit deckt das implizit ab. Reine Style-Lieferung ist hier non-goal.
- **Kein** Front-End-Output — Slice 35 ist 100% Admin (`is_admin()`-Guards in allen `pre_get_posts`-Closures).
- **Kein** Logger-Wiring — Filter/Sort-Operations sind reine Read-Paths; Slice 42 wired Logger global.

**Technische Constraints:**
- PHP 8.2 strict types: `declare(strict_types=1);` als zweite Zeile.
- `final class Inline\ProductListColumns`.
- Capability-Guard fuer `pre_get_posts`-Mutations: `if (!current_user_can('manage_woocommerce')) { return; }` (analog Slice 11/12/32/33/34).
- Admin-Guard fuer `pre_get_posts`: `if (!is_admin() || !$query->is_main_query()) { return; }`.
- `$_GET['sc_filter']`-Sanitization via `sanitize_key()` mit Whitelist-Check (`['', 'linked', 'unlinked', 'low_margin']`) — andere Werte -> NO-OP (defensives Filtering).
- Alle echo'd Strings durch `esc_html` / `esc_attr` / `wc_kses_post` (HTML-Snippets) gefiltert. `wc_price()`-Output ist bereits sicher (Trust WC-Core).
- Bridge-Methoden (`*Static`) konsistent mit Slice 17/23/34.
- Margin-Schwellen exakt `20.0` und `40.0` (siehe Slice 34 Constraints; architecture.md "Pricing-Trade-off"; wireframes.md Screen 10 Annotations).
- CSS-Klassen-Namen exakt `sc-margin-low` / `sc-margin-mid` / `sc-margin-high` / `sc-margin-unknown` (Konsistenz mit Slice 34 AC-5).
- `meta_query`-Merging: bestehende `$query->get('meta_query')`-Eintraege werden via `array_merge` mit `'relation'=>'AND'` erhalten — NICHT ueberschrieben (verhindert Konflikt mit anderen Plugins / WC-Filtern).
- Sort-Direction-Default: `ASC` fuer `sc_cost` und `sc_margin` (low-margin-Up-Surfacing per Discovery Z. 446 + wireframes.md Screen 10 State `sort_by_margin_asc`).
- KEINE `error_log`/`var_dump`-Calls — Slice 42 wired Logger.

**Reuse:**

Slice 35 ist ein reiner Read-Adapter; **keine** Neuimplementierung von Mapper, DTOs, Bootstrap-Pattern oder CSS:

| Existing File | Usage in this Slice |
|---|---|
| `wordpress/plugins/spreadconnect-pod/includes/Bootstrap/Plugin.php` (Slice 02/17/23/34) | Edit-Target: ergaenzt fuenf Hook-Registrierungen (siehe AC-11). Bestehender Hook-Pattern aus Slice 23/34 wird wiederverwendet. |
| `wordpress/plugins/spreadconnect-pod/includes/Inline/ProductMetaBox.php` (Slice 34) | **Stilreferenz** fuer CSS-Klassen `sc-margin-*` und Margin-Schwellen. Kein Code-Sharing, keine Vererbung — Slice 35 echo'd HTML mit denselben Klassen. |
| Postmeta-Keys `_spreadconnect_article_id`, `_spreadconnect_cost`, `_spreadconnect_cost_currency` (Slice 22) | Read-only via `get_post_meta`. Schema unveraendert. |
| `wordpress/plugins/spreadconnect-pod/composer.json` (Slice 02) | Bestehendes PSR-4-Mapping. Unveraendert. |
| WC-Core `WC_Product::get_price()`, `wc_get_product()`, `wc_price()` | Standard-WC-API. Unveraendert. |
| WP-Core `WP_Query`/`meta_query`, `pre_get_posts`, `manage_edit-*`, `restrict_manage_posts` | Standard-WP-Admin-List-Hooks. Unveraendert. |

**Referenzen:**
- Architecture: `architecture.md` -> "Service Map" Z. 396 (`Inline\ProductListColumns` — Layer Adapter, Hook `manage_edit-product_columns` + render + filter dropdown).
- Architecture: `architecture.md` -> "WC-Product Meta" Z. 287-291 (Liste der Postmeta-Keys, die Slice 35 liest).
- Architecture: `architecture.md` -> "Inline-Erweiterungen" Z. 46 (Cost+Margin-Spalten als First-Class Surface).
- Wireframes: `wireframes.md` -> Screen 10 "Product-List Spalten" Z. 863-916 (komplett: Layout, drei Spalten, Filter-Dropdown-Optionen, Sortable-Header, State-Variations `mixed_linked_unlinked`/`filter_unlinked`/`filter_low_margin`/`sort_by_margin_asc`).
- Wireframes: `wireframes.md` -> Z. 25 (`margin_display` als Component auch fuer Product-List), Z. 30 (`low_margin_notice_in_list`), Z. 446 ("Statisch, sortierbar").
- Discovery: `discovery.md` -> Slice 8 Z. 929 (Done-Signal: Product-List Spalten + Filter), Z. 384-387 (Spalten-Spec), Z. 441 (Margin-Display-Component), Z. 446 (`low_margin_notice_in_list` sortierbar).
- Slim-Slices: `slices/slim-slices.md` -> Slice-35-Eintrag (Done-Signal: Spalten registriert; Filter-Query joins auf postmeta korrekt; Margin <20% filtert nur Produkte mit Cost+WC-Preis).
- Slice 34: `slices/slice-34-product-meta-box-margin-stock.md` -> Constraints "JS-Margin-Klassen" (CSS-Klassen-Vertrag) + AC-5 (Schwellen `20.0` / `40.0`).
