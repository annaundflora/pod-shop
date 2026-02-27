# Slice 2: Seed-Script Erweiterung

> **Slice 2 von 5** für `Seed Data — 100+ POD-Produkte mit KI-generierten Bildern`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-01-produktkatalog-definition.md` |
> | **Nächster:** | `slice-03-motiv-definition.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-02-seed-script-erweiterung` |
| **Test** | `pnpm test tests/slices/seed-data/slice-02-seed-script-erweiterung.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-produktkatalog-definition"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Vitest-Tests prüfen die Datei-Struktur, PHP-Syntax und Shell-Script-Logik
- **E2E**: `false` — Vitest Unit Tests, kein Playwright
- **Dependencies**: `slice-01` muss fertig sein, damit `scripts/product-catalog.json` existiert

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` enthält `next` + `vitest` Dependencies. PHP-Dateien vorhanden in `scripts/` und `wordpress/plugins/`.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/seed-data/slice-02-seed-script-erweiterung.test.ts` |
| **Integration Command** | `docker compose exec wpcli wp eval-file /scripts/seed-products.php --allow-root --path=/var/www/html` |
| **Acceptance Command** | `pnpm test tests/slices/seed-data/slice-02-seed-script-erweiterung.test.ts` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `no_mocks` |

**Erklaerung:**
- **Stack**: typescript-nextjs (Vitest für PHP-Datei-Struktur-Tests)
- **Test Command**: Liest `scripts/seed-products.php` und `scripts/mock-data.sh` per `readFileSync`, prüft Code-Patterns und -Struktur
- **Integration Command**: Führt das tatsächliche Seed-Script im Docker-Container aus
- **Mocking Strategy**: `no_mocks` — Tests prüfen Datei-Inhalte statisch, kein Mocking nötig

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Produktkatalog-Definition | Ready | `slice-01-produktkatalog-definition.md` |
| 2 | Seed-Script Erweiterung | Ready | `slice-02-seed-script-erweiterung.md` |
| 3 | Motiv-Definition pro Produkt | Pending | `slice-03-motiv-definition.md` |
| 4 | Bild-Generierung Script | Pending | `slice-04-bild-generierung.md` |
| 5 | Bild-Import im Seed | Pending | `slice-05-bild-import.md` |

---

## Kontext & Ziel

Das bestehende `scripts/seed-products.php` erstellt 3 hardcoded Produkte in 2 flachen Kategorien ohne Kategorie-Hierarchie, ohne Featured-Markierung und ohne Reviews. Dieses Script muss vollständig refaktoriert werden, um den Produktkatalog aus `scripts/product-catalog.json` (Slice 1) zu laden und 110 Produkte in 12 Unter-Kategorien unter 3 Parent-Kategorien zu erstellen.

**Aktuelle Probleme:**
1. `seed-products.php` hat 3 hardcoded Produkte — keine JSON-Datenbasis
2. Keine Kategorie-Hierarchie (parent/child Beziehungen fehlen)
3. Kein `pod_create_simple_product()` für Simple Products (Tassen, Poster, Handyhüllen)
4. Kein `pod_create_color_only_variable_product()` für Farb-nur Variationen (Taschen, Mützen, Kissen)
5. Keine Featured-Markierung (`set_featured(true)`)
6. Keine Mock-Reviews (`wp_insert_comment()` + `rating` meta)
7. `docker-compose.yml` wpcli-Service hat kein uploads-Volume-Mount (nötig für Slice 5 Bild-Import)
8. `mock-data.sh` hat kein `--force` Flag für Re-Seeding

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Server Logic / Business Logic Flow

```
docker compose up -d → setup.sh → mock-data.sh → seed-products.php
  Check idempotency (WP option: pod_shop_mock_data_seeded)
    Read product-catalog.json: json_decode(file_get_contents('/scripts/product-catalog.json'))
      Create parent categories (3): Kleidung, Accessoires, Wohnen & Geschenke
        Create child categories (12) with parent ID lookup
          Create products (110) — dispatch nach type:
            variable-size-color → pod_create_variable_product() (5×4 = 20 Variationen)
            variable-color      → pod_create_color_only_variable_product() (4 Farb-Variationen)
            simple              → pod_create_simple_product()
          Set featured flag: $product->set_featured(true)
          Create reviews: wp_insert_comment() + update_comment_meta(rating)
          Create legal pages (unverändert)
            Set idempotency flag
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `scripts/seed-products.php` | Vollständiges Refactoring: JSON-Loading, Kategorie-Hierarchie, Simple/Color-only Products, Featured, Reviews |
| `scripts/mock-data.sh` | `--force` Flag hinzufügen (löscht Idempotenz-Flag vor Aufruf) |
| `docker-compose.yml` | `wpcli` service: uploads volume mount hinzufügen |

### 2. Datenfluss

```
scripts/product-catalog.json (Slice 1 Output)
  ↓ json_decode(file_get_contents('/scripts/product-catalog.json'))
PHP-Objekt $catalog mit $catalog->categories[] und $catalog->products[]
  ↓
Kategorie-Erstellung: $parent_ids[] map von slug → term_id
  ↓
Produkt-Erstellung: dispatch nach $product->type
  "variable-size-color" → pod_create_variable_product() — 20 Variationen
  "variable-color"      → pod_create_color_only_variable_product() — 4 Variationen
  "simple"              → pod_create_simple_product() — keine Variationen
  ↓
Featured-Markierung: $wp_product->set_featured(true) für products mit "featured": true
  ↓
Review-Erstellung: wp_insert_comment() + update_comment_meta($id, 'rating', N)
  (3–5 Reviews pro Featured-Produkt, DE Namen, Rating 3–5 Sterne)
  ↓
WooCommerce DB: wp_posts, wp_postmeta, wp_term_relationships, wp_comments, wp_commentmeta
```

### 3. Kategorie-Hierarchie-Erstellungslogik

**Phase 1 — Parent-Kategorien erstellen:**

```
Für jede Kategorie in $catalog->categories wo parent === null:
  get_term_by('slug', $slug, 'product_cat')
  → existiert: nutze term_id
  → existiert nicht: wp_insert_term(name, 'product_cat', ['slug', 'description'])
  Speichere slug → term_id in $parent_ids[]
```

**Phase 2 — Child-Kategorien erstellen (parent muss existieren):**

```
Für jede Kategorie in $catalog->categories wo parent !== null:
  $parent_term_id = $parent_ids[$category->parent]
  get_term_by('slug', $slug, 'product_cat')
  → existiert: nutze term_id
  → existiert nicht: wp_insert_term(name, 'product_cat', [
      'slug', 'description', 'parent' => $parent_term_id
    ])
  Speichere slug → term_id in $category_ids[]
```

### 4. Produkt-Typ-Dispatch

```php
// Dispatch basierend auf type-Feld aus JSON
foreach ($catalog->products as $product) {
    // Idempotenz-Check
    if (get_page_by_path($product->slug, OBJECT, 'product')) {
        WP_CLI::log("  Skip (exists): {$product->name}");
        continue;
    }

    $category_id = $category_ids[$product->category];

    $product_id = match($product->type) {
        'variable-size-color' => pod_create_variable_product([...]),
        'variable-color'      => pod_create_color_only_variable_product([...]),
        'simple'              => pod_create_simple_product([...]),
        default               => throw new Exception("Unknown type: {$product->type}"),
    };

    // Featured-Markierung
    if ($product->featured) {
        $wc_product = wc_get_product($product_id);
        $wc_product->set_featured(true);
        $wc_product->save();
    }
}
```

### 5. Review-Erstellungslogik

Rating-Gewichtung: 60% 5★, 25% 4★, 15% 3★ via gewichteter Zufallsauswahl.

```php
// Review-Daten pro Produktkategorie (produktspezifisch)
// 3–5 Reviews pro Featured-Produkt
// Deutsche Vornamen + Nachname-Initial

$german_names = [
    'Maria K.', 'Thomas B.', 'Sarah M.', 'Jan H.', 'Laura S.',
    'Michael R.', 'Anna W.', 'Felix G.', 'Julia N.', 'Stefan P.',
    'Lena F.', 'Daniel K.', 'Emma T.', 'Markus L.', 'Sophie D.',
    'Christian V.', 'Hannah C.', 'Andreas Z.', 'Nina O.', 'Patrick E.',
];

// Gewichtete Rating-Auswahl: 60% 5★, 25% 4★, 15% 3★
function pod_weighted_rating(): int {
    $rand = mt_rand(1, 100);
    if ($rand <= 60) return 5;
    if ($rand <= 85) return 4;
    return 3;
}

// Review erstellen
wp_insert_comment([
    'comment_post_ID'      => $product_id,
    'comment_author'       => $name,
    'comment_author_email' => strtolower(str_replace([' ', '.'], ['', ''], $name)) . '@example.com',
    'comment_content'      => $review_text,
    'comment_type'         => 'review',
    'comment_parent'       => 0,
    'user_id'              => 0,
    'comment_approved'     => 1,
]);
update_comment_meta($comment_id, 'rating', $rating);
update_comment_meta($comment_id, 'verified', '0');
```

**Review-Texte nach Produktkategorie (Beispiele):**

| Kategorie | Beispiel-Review (5★) | Beispiel-Review (4★) | Beispiel-Review (3★) |
|-----------|---------------------|---------------------|---------------------|
| t-shirts, hoodies, sweatshirts, tanktops, langarmshirts | "Super Qualität und der Druck ist wirklich scharf! Werde definitiv wieder bestellen." | "Tolles Shirt, Passform ist wie beschrieben. Der Print könnte etwas kräftiger sein." | "Qualität ist okay, Lieferung hat etwas länger gedauert als erwartet." |
| taschen, muetzen-caps, kissen | "Hochwertige Verarbeitung, das Design sitzt perfekt. Sehr zufrieden!" | "Schöne Tasche, Farbe entspricht dem Bild. Nähte könnten sorgfältiger sein." | "Für den Preis okay, aber Qualität ist nur Mittelmaß." |
| tassen, poster-kunstdrucke, handyhuellen | "Wunderschönes Motiv und sehr gute Druckqualität. Tolles Geschenk!" | "Hübsches Design, genau wie auf dem Bild. Verpackung war leider etwas beschädigt." | "Design gefällt mir, aber die Farben wirken etwas blasser als auf dem Foto." |

---

## Acceptance Criteria

1) GIVEN `docker compose up -d` auf frischem Setup (keine vorherigen WP-Daten), WHEN Seed vollständig durchläuft, THEN enthält WordPress exakt 110 WooCommerce-Produkte mit korrekten Kategorien (3 Parent + 12 Child)

2) GIVEN ein Produkt mit `"type": "simple"` im Katalog (z.B. Tassen, Poster, Handyhüllen), WHEN Seed ausgeführt wird, THEN ist das WooCommerce-Produkt vom Typ `WC_Product_Simple` ohne Variationen

3) GIVEN ein Produkt mit `"type": "variable-color"` im Katalog (z.B. Taschen, Mützen, Kissen), WHEN Seed ausgeführt wird, THEN ist das WooCommerce-Produkt vom Typ `WC_Product_Variable` mit exakt 4 Farb-Variationen (Schwarz, Weiß, Grau, Navy) und OHNE `pa_groesse`-Attribut

4) GIVEN ein Produkt mit `"featured": true` im Katalog, WHEN via GraphQL mit `featured: true` Filter abgefragt, THEN erscheint das Produkt in der Featured-Produktliste

5) GIVEN ein Produkt mit `"featured": true` nach dem Seed, WHEN die WP-Kommentare für dieses Produkt geprüft werden, THEN existieren 3–5 Reviews mit Rating-Werten zwischen 3 und 5 (in wp_commentmeta als `rating` key)

6) GIVEN der Seed wurde bereits einmal ausgeführt (Idempotenz-Flag gesetzt), WHEN `docker compose up -d` erneut ausgeführt wird (ohne `--force`), THEN sind immer noch exakt 110 Produkte vorhanden (keine Duplikate)

7) GIVEN `scripts/mock-data.sh --force`, WHEN das Script ausgeführt wird, THEN wird der Idempotenz-Flag `pod_shop_mock_data_seeded` gelöscht und der Seed erneut ausgeführt

8) GIVEN Bild-Dateien sind in `wordpress/uploads/products/` NICHT vorhanden, WHEN Seed ausgeführt wird (Slice 2 — kein Bild-Import), THEN werden alle 110 Produkte ohne Fehler erstellt (graceful degradation: kein Bild-Import in diesem Slice)

---

## Testfälle

### Test-Datei

`tests/slices/seed-data/slice-02-seed-script-erweiterung.test.ts`

<test_spec>
```typescript
// tests/slices/seed-data/slice-02-seed-script-erweiterung.test.ts
import { describe, it, expect } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const phpPath = resolve(__dirname, '../../../../scripts/seed-products.php')
const phpContent = readFileSync(phpPath, 'utf-8')

const shPath = resolve(__dirname, '../../../../scripts/mock-data.sh')
const shContent = readFileSync(shPath, 'utf-8')

describe('Seed-Script Erweiterung (slice-02)', () => {

  describe('seed-products.php — Datei-Struktur', () => {
    it('should load product catalog from JSON via file_get_contents', () => {
      expect(phpContent).toContain("file_get_contents('/scripts/product-catalog.json')")
      expect(phpContent).toContain('json_decode')
    })

    it('should contain pod_create_simple_product function', () => {
      expect(phpContent).toContain('function pod_create_simple_product')
    })

    it('should contain pod_create_color_only_variable_product function', () => {
      expect(phpContent).toContain('function pod_create_color_only_variable_product')
    })

    it('should contain pod_create_variable_product function (existing, unchanged)', () => {
      expect(phpContent).toContain('function pod_create_variable_product')
    })

    it('should dispatch products by type field', () => {
      expect(phpContent).toContain("'variable-size-color'")
      expect(phpContent).toContain("'variable-color'")
      expect(phpContent).toContain("'simple'")
    })

    it('should set featured flag via set_featured(true)', () => {
      expect(phpContent).toContain('set_featured(true)')
    })

    it('should create reviews via wp_insert_comment', () => {
      expect(phpContent).toContain('wp_insert_comment')
    })

    it('should set review rating via update_comment_meta', () => {
      expect(phpContent).toContain("update_comment_meta")
      expect(phpContent).toContain("'rating'")
    })

    it('should have idempotency check via get_page_by_path', () => {
      expect(phpContent).toContain('get_page_by_path')
      expect(phpContent).toContain("'product'")
    })

    it('should create parent categories before child categories', () => {
      const parentIdx = phpContent.indexOf('parent_ids')
      const childIdx = phpContent.indexOf('category_ids')
      expect(parentIdx).toBeGreaterThan(-1)
      expect(childIdx).toBeGreaterThan(-1)
      expect(parentIdx).toBeLessThan(childIdx)
    })

    it('should set category description via wp_insert_term args', () => {
      expect(phpContent).toContain("'description'")
      expect(phpContent).toContain('product_cat')
    })

    it('should preserve legal pages section', () => {
      expect(phpContent).toContain('Impressum')
      expect(phpContent).toContain('Datenschutz')
      expect(phpContent).toContain('AGB')
      expect(phpContent).toContain('Widerruf')
    })

    it('should have pod_ensure_attribute function (existing, unchanged)', () => {
      expect(phpContent).toContain('function pod_ensure_attribute')
    })

    it('should have pod_ensure_term function (existing, unchanged)', () => {
      expect(phpContent).toContain('function pod_ensure_term')
    })

    it('should have pod_find_attribute_id function (existing, unchanged)', () => {
      expect(phpContent).toContain('function pod_find_attribute_id')
    })
  })

  describe('seed-products.php — Simple Product Helper', () => {
    it('should use WC_Product_Simple in pod_create_simple_product', () => {
      expect(phpContent).toContain('WC_Product_Simple')
    })

    it('should set regular price in simple product', () => {
      expect(phpContent).toContain('set_regular_price')
    })
  })

  describe('seed-products.php — Color-Only Variable Product Helper', () => {
    it('should use only pa_farbe (no pa_groesse) in color-only helper', () => {
      // The color-only function should reference pa_farbe
      expect(phpContent).toContain('pod_create_color_only_variable_product')
      // Should not set pa_groesse in color-only variation attributes
      const colorOnlyFnMatch = phpContent.match(/function pod_create_color_only_variable_product[\s\S]*?^}/m)
      if (colorOnlyFnMatch) {
        expect(colorOnlyFnMatch[0]).not.toContain('pa_groesse')
      }
    })

    it('should create exactly 4 color variations in color-only helper', () => {
      expect(phpContent).toContain("'Schwarz', 'Weiß', 'Grau', 'Navy'")
    })
  })

  describe('seed-products.php — Review Creation', () => {
    it('should have German reviewer names', () => {
      // Check for typical German name pattern (firstname + initial)
      const germanNamePattern = /['"](?:Maria|Thomas|Sarah|Jan|Laura|Michael|Anna|Felix|Julia|Stefan|Lena|Daniel|Emma|Markus|Sophie|Christian|Hannah|Andreas|Nina|Patrick)\s[A-Z]\./
      expect(phpContent).toMatch(germanNamePattern)
    })

    it('should have weighted rating distribution logic', () => {
      // Check for 60/25/15 weighting or equivalent logic
      expect(phpContent).toMatch(/60|85/)
    })

    it('should create 3-5 reviews per featured product', () => {
      // Check for random count between 3 and 5
      expect(phpContent).toMatch(/mt_rand\s*\(\s*3\s*,\s*5\s*\)/)
    })

    it('should set comment_type to review', () => {
      expect(phpContent).toContain("'comment_type'")
      expect(phpContent).toContain("'review'")
    })

    it('should set verified meta to 0 (mock reviews)', () => {
      expect(phpContent).toContain("'verified'")
      expect(phpContent).toContain("'0'")
    })
  })

  describe('mock-data.sh — Force Flag', () => {
    it('should support --force flag', () => {
      expect(shContent).toContain('--force')
    })

    it('should delete pod_shop_mock_data_seeded option when --force is used', () => {
      expect(shContent).toContain('pod_shop_mock_data_seeded')
      expect(shContent).toContain('option delete')
    })
  })

  describe('docker-compose.yml — uploads volume', () => {
    it('should have uploads volume mount in wpcli service', () => {
      const composePath = resolve(__dirname, '../../../../docker-compose.yml')
      const composeContent = readFileSync(composePath, 'utf-8')

      // Check that the wpcli section has uploads mount
      expect(composeContent).toContain('./wordpress/uploads:/var/www/html/wp-content/uploads')
    })
  })
})
```
</test_spec>

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft. Unvollständige Contracts blockieren die Genehmigung.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-produktkatalog-definition | `scripts/product-catalog.json` | JSON-Datei | EXISTS, `json_decode(file_get_contents('/scripts/product-catalog.json'))` gibt valides PHP-Objekt zurück |
| slice-01-produktkatalog-definition | `$catalog->categories[]` | PHP-Array | Enthält 15 Kategorien mit `name`, `slug`, `parent`, `description` Feldern |
| slice-01-produktkatalog-definition | `$catalog->products[]` | PHP-Array | Enthält 110 Produkte mit `name`, `slug`, `description`, `short_description`, `price`, `category`, `type`, `spreadconnect_id`, `featured`, `motif` Feldern |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| WooCommerce-Produkte (110) | DB-Einträge (wp_posts type=product) | slice-05-bild-import | Produkte müssen existieren (mit `$product->slug` als `post_name`) bevor Bild-Import ausgeführt wird |
| WooCommerce-Kategorien (15) | DB-Einträge (wp_term_taxonomy) | slice-05-bild-import | Kategorien müssen existieren bevor Kategorie-Bilder importiert werden |
| Featured-Produkte (8–12) | product_visibility Term-Beziehungen | Frontend GraphQL `featured: true` | `GET_FEATURED_PRODUCTS` Query liefert Produkte mit `featured: true` Flag |
| Mock-Reviews | wp_comments + wp_commentmeta (rating) | Frontend Produkt-Detail-Page | Reviews mit `rating` 3–5 sind in WooCommerce sichtbar |
| Uploads-Volume-Mount | docker-compose.yml Konfiguration | slice-05-bild-import | wpcli-Container kann `file_exists('/var/www/html/wp-content/uploads/products/...')` prüfen |

### Integration Validation Tasks

- [ ] `scripts/product-catalog.json` existiert (Slice 1 abgeschlossen)
- [ ] `wp eval-file /scripts/seed-products.php --allow-root` läuft ohne Fehler durch
- [ ] Nach Seed: `wp post list --post_type=product --posts_per_page=-1 | wc -l` ergibt 110
- [ ] Nach Seed: Featured-Produkte via `wp term list product_visibility | grep featured` verifizierbar
- [ ] Nach Seed: Reviews via `wp comment list --post_id={id}` für featured Produkte verifizierbar
- [ ] docker-compose.yml: wpcli-Service hat `./wordpress/uploads:/var/www/html/wp-content/uploads` volume mount

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind **PFLICHT-Deliverables**.
> Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `pod_create_simple_product()` | Code Example 1 | YES | Neue Funktion, muss exakt diese Signatur haben |
| `pod_create_color_only_variable_product()` | Code Example 2 | YES | Neue Funktion, nur pa_farbe, kein pa_groesse |
| JSON-Katalog-Loading + Kategorie-Hierarchie | Code Example 3 | YES | Vollständige Logik mit parent_ids + category_ids |
| Review-Erstellungslogik | Code Example 4 | YES | Gewichtetes Rating, deutsche Namen, wp_insert_comment |
| `mock-data.sh` --force Flag | Code Example 5 | YES | Shell-Script Erweiterung |
| docker-compose.yml wpcli uploads mount | Code Example 6 | YES | Volume-Mount Konfiguration |

### Code Example 1: `pod_create_simple_product()`

```php
/**
 * Helper: create a simple product (no variations)
 * Used for: Tassen, Poster & Kunstdrucke, Handyhüllen
 */
function pod_create_simple_product(array $args): int
{
    $product = new WC_Product_Simple();
    $product->set_name($args['name']);
    $product->set_slug($args['slug']);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_description($args['description']);
    $product->set_short_description($args['short_description']);
    $product->set_regular_price($args['price']);
    $product->set_category_ids([$args['category_id']]);
    $product->update_meta_data('_spreadconnect_product_id', $args['spreadconnect_id'] ?? '');
    $product_id = $product->save();

    WP_CLI::success(sprintf("  '%s' (ID: %d, simple)", $args['name'], $product_id));
    return $product_id;
}
```

### Code Example 2: `pod_create_color_only_variable_product()`

```php
/**
 * Helper: create a variable product with color variations ONLY (no size)
 * Used for: Taschen, Mützen & Caps, Kissen
 */
function pod_create_color_only_variable_product(array $args): int
{
    $colors = ['Schwarz', 'Weiß', 'Grau', 'Navy'];

    $product = new WC_Product_Variable();
    $product->set_name($args['name']);
    $product->set_slug($args['slug']);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_description($args['description']);
    $product->set_short_description($args['short_description']);
    $product->set_category_ids([$args['category_id']]);

    // Color attribute only (no size attribute)
    $color_attr_id  = pod_find_attribute_id('farbe');
    $color_term_ids = array_map(fn($c) => pod_ensure_term($c, 'pa_farbe'), $colors);

    $color_attr = new WC_Product_Attribute();
    $color_attr->set_id($color_attr_id);
    $color_attr->set_name('pa_farbe');
    $color_attr->set_options($color_term_ids);
    $color_attr->set_position(0);
    $color_attr->set_visible(true);
    $color_attr->set_variation(true);

    $product->set_attributes([$color_attr]);
    $product->update_meta_data('_spreadconnect_product_id', $args['spreadconnect_id'] ?? '');
    $product_id = $product->save();

    wp_set_object_terms($product_id, $color_term_ids, 'pa_farbe');

    // Create one variation per color
    $count = 0;
    foreach ($colors as $color) {
        $color_term = get_term_by('name', $color, 'pa_farbe');
        if (!$color_term) continue;

        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product_id);
        $variation->set_regular_price($args['price']);
        $variation->set_status('publish');
        $variation->set_attributes([
            'pa_farbe' => $color_term->slug,
        ]);
        $variation->save();
        $count++;
    }

    WC_Product_Variable::sync($product_id);
    WP_CLI::success(sprintf("  '%s' (ID: %d, %d color variations)", $args['name'], $product_id, $count));
    return $product_id;
}
```

### Code Example 3: JSON-Katalog-Loading + Kategorie-Hierarchie

```php
// ═════════════════════════════════════════════════════════════
// 3. Load product catalog
// ═════════════════════════════════════════════════════════════
WP_CLI::log('');
WP_CLI::log('Loading product catalog...');
$catalog_path = '/scripts/product-catalog.json';
if (!file_exists($catalog_path)) {
    WP_CLI::error("Product catalog not found at $catalog_path. Run Slice 1 first.");
}
$catalog = json_decode(file_get_contents($catalog_path));
if (!$catalog || !isset($catalog->categories) || !isset($catalog->products)) {
    WP_CLI::error("Invalid product catalog JSON at $catalog_path");
}
WP_CLI::success(sprintf("Catalog loaded: %d categories, %d products", count($catalog->categories), count($catalog->products)));

// ═════════════════════════════════════════════════════════════
// 4. Create parent categories
// ═════════════════════════════════════════════════════════════
WP_CLI::log('');
WP_CLI::log('Creating parent categories...');
$parent_ids = []; // slug => term_id

foreach ($catalog->categories as $cat) {
    if ($cat->parent !== null) continue; // skip child categories in this phase

    $existing = get_term_by('slug', $cat->slug, 'product_cat');
    if ($existing) {
        $parent_ids[$cat->slug] = (int) $existing->term_id;
        WP_CLI::log("  Parent exists: {$cat->name} (ID: {$existing->term_id})");
    } else {
        $result = wp_insert_term($cat->name, 'product_cat', [
            'slug'        => $cat->slug,
            'description' => $cat->description,
        ]);
        if (is_wp_error($result)) {
            WP_CLI::error("Failed to create parent category '{$cat->name}': " . $result->get_error_message());
        }
        $parent_ids[$cat->slug] = (int) $result['term_id'];
        WP_CLI::log("  Created parent: {$cat->name} (ID: {$result['term_id']})");
    }
}

// ═════════════════════════════════════════════════════════════
// 5. Create child categories
// ═════════════════════════════════════════════════════════════
WP_CLI::log('');
WP_CLI::log('Creating child categories...');
$category_ids = []; // slug => term_id (includes both parent and child)

// Include parent IDs in category_ids map for completeness
foreach ($parent_ids as $slug => $id) {
    $category_ids[$slug] = $id;
}

foreach ($catalog->categories as $cat) {
    if ($cat->parent === null) continue; // skip parent categories in this phase

    $parent_term_id = $parent_ids[$cat->parent] ?? null;
    if (!$parent_term_id) {
        WP_CLI::error("Parent category '{$cat->parent}' not found for child '{$cat->slug}'");
    }

    $existing = get_term_by('slug', $cat->slug, 'product_cat');
    if ($existing) {
        $category_ids[$cat->slug] = (int) $existing->term_id;
        WP_CLI::log("  Child exists: {$cat->name} (ID: {$existing->term_id})");
    } else {
        $result = wp_insert_term($cat->name, 'product_cat', [
            'slug'        => $cat->slug,
            'description' => $cat->description,
            'parent'      => $parent_term_id,
        ]);
        if (is_wp_error($result)) {
            WP_CLI::error("Failed to create child category '{$cat->name}': " . $result->get_error_message());
        }
        $category_ids[$cat->slug] = (int) $result['term_id'];
        WP_CLI::log("  Created child: {$cat->name} under {$cat->parent} (ID: {$result['term_id']})");
    }
}

WP_CLI::success(sprintf("Categories ready: %d total", count($category_ids)));

// ═════════════════════════════════════════════════════════════
// 6. Create products from catalog
// ═════════════════════════════════════════════════════════════
WP_CLI::log('');
WP_CLI::log('Creating products...');
$featured_product_ids = [];
$created = 0;
$skipped = 0;

foreach ($catalog->products as $product) {
    // Idempotenz-Check
    if (get_page_by_path($product->slug, OBJECT, 'product')) {
        WP_CLI::log("  Skip (exists): {$product->name}");
        $skipped++;

        // Collect existing featured product IDs for reviews
        if ($product->featured) {
            $existing = get_page_by_path($product->slug, OBJECT, 'product');
            $featured_product_ids[] = (int) $existing->ID;
        }
        continue;
    }

    $category_id = $category_ids[$product->category] ?? null;
    if (!$category_id) {
        WP_CLI::warning("  Unknown category '{$product->category}' for product '{$product->name}' — skipping");
        continue;
    }

    $args = [
        'name'              => $product->name,
        'slug'              => $product->slug,
        'description'       => $product->description,
        'short_description' => $product->short_description,
        'price'             => $product->price,
        'category_id'       => $category_id,
        'spreadconnect_id'  => $product->spreadconnect_id,
    ];

    $product_id = match($product->type) {
        'variable-size-color' => pod_create_variable_product($args),
        'variable-color'      => pod_create_color_only_variable_product($args),
        'simple'              => pod_create_simple_product($args),
        default               => (function() use ($product) {
            WP_CLI::warning("  Unknown product type '{$product->type}' for '{$product->name}' — skipping");
            return 0;
        })(),
    };

    if (!$product_id) continue;
    $created++;

    // Featured-Markierung
    if ($product->featured) {
        $wc_product = wc_get_product($product_id);
        $wc_product->set_featured(true);
        $wc_product->save();
        $featured_product_ids[] = $product_id;
        WP_CLI::log("    -> Marked as featured");
    }
}

WP_CLI::success(sprintf("Products: %d created, %d skipped (already exist)", $created, $skipped));
```

### Code Example 4: Review-Erstellungslogik

```php
// ═════════════════════════════════════════════════════════════
// 7. Create mock reviews for featured products
// ═════════════════════════════════════════════════════════════
WP_CLI::log('');
WP_CLI::log('Creating mock reviews for featured products...');

$german_names = [
    'Maria K.', 'Thomas B.', 'Sarah M.', 'Jan H.', 'Laura S.',
    'Michael R.', 'Anna W.', 'Felix G.', 'Julia N.', 'Stefan P.',
    'Lena F.', 'Daniel K.', 'Emma T.', 'Markus L.', 'Sophie D.',
    'Christian V.', 'Hannah C.', 'Andreas Z.', 'Nina O.', 'Patrick E.',
];

// Kategorie-spezifische Review-Texte
$review_texts = [
    'clothing' => [
        5 => [
            "Super Qualität und der Druck ist wirklich scharf! Werde definitiv wieder bestellen.",
            "Tolle Verarbeitung und die Farben sind genau wie auf dem Foto. Bin sehr zufrieden!",
            "Passt perfekt und sitzt super angenehm. Das Design ist noch schöner als erwartet.",
        ],
        4 => [
            "Tolles Shirt, Passform ist wie beschrieben. Der Print könnte etwas kräftiger sein.",
            "Gute Qualität, Lieferung war schnell. Einen Stern Abzug wegen der Verpackung.",
            "Schönes Design, Material fühlt sich hochwertig an. Bin insgesamt zufrieden.",
        ],
        3 => [
            "Qualität ist okay, Lieferung hat etwas länger gedauert als erwartet.",
            "Für den Preis okay, aber ich hatte mehr erwartet. Das Design gefällt mir.",
            "Passt, aber der Stoff wirkt etwas dünner als im Produktfoto.",
        ],
    ],
    'accessories' => [
        5 => [
            "Hochwertige Verarbeitung, das Design sitzt perfekt. Sehr zufrieden!",
            "Tolles Produkt, genau wie beschrieben. Schnelle Lieferung, kommt wieder!",
            "Wunderschönes Design und sehr gute Qualität. Tolles Geschenk!",
        ],
        4 => [
            "Schöne Qualität, Farbe entspricht dem Bild. Nähte könnten sorgfältiger sein.",
            "Gefällt mir gut, Maße passen. Einen Stern Abzug für die Lieferzeit.",
            "Gutes Produkt für den Preis. Das Design ist hübsch, wie auf dem Foto.",
        ],
        3 => [
            "Für den Preis okay, aber Qualität ist nur Mittelmaß.",
            "Optisch schön, aber die Verarbeitung ist nicht ganz so hochwertig wie erwartet.",
            "Passt, aber ich hätte mehr Sorgfalt bei den Details erwartet.",
        ],
    ],
    'home' => [
        5 => [
            "Wunderschönes Motiv und sehr gute Druckqualität. Tolles Geschenk für Freunde!",
            "Genau wie auf dem Bild, super Qualität. Mache definitiv weitere Bestellungen.",
            "Tolle Verarbeitung und das Motiv ist noch schöner in echt. Sehr empfehlenswert!",
        ],
        4 => [
            "Hübsches Design, genau wie auf dem Bild. Verpackung war leider etwas beschädigt.",
            "Gute Qualität, bin zufrieden. Die Farben sind minimal blasser als im Foto.",
            "Schönes Produkt, gute Verarbeitung. Einen Stern Abzug für die Lieferzeit.",
        ],
        3 => [
            "Design gefällt mir, aber die Farben wirken etwas blasser als auf dem Foto.",
            "Okay für den Preis, aber ich hatte qualitativ mehr erwartet.",
            "Sieht gut aus, aber der Druck ist nicht ganz so scharf wie gehofft.",
        ],
    ],
];

// Kategorie-Mapping für Review-Texte
$clothing_cats = ['t-shirts', 'hoodies', 'sweatshirts', 'tanktops', 'langarmshirts'];
$accessory_cats = ['taschen', 'muetzen-caps', 'kissen'];
// home_cats: alle anderen (tassen, poster-kunstdrucke, handyhuellen)

// Gewichtete Rating-Auswahl: 60% 5★, 25% 4★, 15% 3★
function pod_weighted_rating(): int {
    $rand = mt_rand(1, 100);
    if ($rand <= 60) return 5;
    if ($rand <= 85) return 4;
    return 3;
}

$total_reviews = 0;

foreach ($featured_product_ids as $product_id) {
    // Bereits Reviews vorhanden? Skip (idempotent)
    $existing_reviews = get_comments(['post_id' => $product_id, 'type' => 'review']);
    if (count($existing_reviews) > 0) {
        WP_CLI::log("  Reviews exist for product ID $product_id — skipping");
        continue;
    }

    // Bestimme Kategorie-Typ für Review-Texte
    $product_terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);
    $review_type = 'home'; // default
    foreach ($product_terms as $term_slug) {
        if (in_array($term_slug, $clothing_cats)) { $review_type = 'clothing'; break; }
        if (in_array($term_slug, $accessory_cats)) { $review_type = 'accessories'; break; }
    }

    // 3–5 Reviews pro Produkt
    $review_count = mt_rand(3, 5);
    $used_names = [];

    for ($i = 0; $i < $review_count; $i++) {
        // Zufälligen, noch nicht verwendeten Namen wählen
        do {
            $name = $german_names[array_rand($german_names)];
        } while (in_array($name, $used_names) && count($used_names) < count($german_names));
        $used_names[] = $name;

        $rating = pod_weighted_rating();
        $texts = $review_texts[$review_type][$rating];
        $text = $texts[array_rand($texts)];

        // Autor-E-Mail aus Namen ableiten
        $email_base = strtolower(str_replace([' ', '.'], ['', ''], $name));
        $email = $email_base . '@example.com';

        $comment_id = wp_insert_comment([
            'comment_post_ID'      => $product_id,
            'comment_author'       => $name,
            'comment_author_email' => $email,
            'comment_content'      => $text,
            'comment_type'         => 'review',
            'comment_parent'       => 0,
            'user_id'              => 0,
            'comment_approved'     => 1,
        ]);

        if ($comment_id) {
            update_comment_meta($comment_id, 'rating', $rating);
            update_comment_meta($comment_id, 'verified', '0');
            $total_reviews++;
        }
    }

    WP_CLI::log("  Created $review_count reviews for product ID $product_id");

    // WooCommerce Review-Count-Cache invalidieren
    $wc_product = wc_get_product($product_id);
    if ($wc_product) {
        $wc_product->set_rating_counts([]);
        $wc_product->set_average_rating('');
        $wc_product->save();
        WC_Comments::get_rating_counts_for_product($wc_product);
    }
}

WP_CLI::success(sprintf("Reviews created: %d total for %d featured products", $total_reviews, count($featured_product_ids)));
```

### Code Example 5: `mock-data.sh` --force Flag

```sh
#!/bin/sh
# =============================================================
# POD Shop – Mock Data Seeder
# Creates: categories, product attributes, variable products,
#          simple products, reviews, legal pages
# Idempotent: safe to run multiple times
# Usage: sh /scripts/mock-data.sh [--force]
# =============================================================
set -e

WP_PATH=/var/www/html

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  POD Shop Mock Data Seeder"
echo "═══════════════════════════════════════════════════════════"
echo ""

# --force flag: reset idempotency flag to allow re-seeding
if [ "$1" = "--force" ]; then
  echo "Force flag detected — resetting idempotency flag..."
  wp option delete pod_shop_mock_data_seeded --allow-root --path="$WP_PATH" 2>/dev/null || true
  echo "Idempotency flag reset. Re-seeding..."
fi

# Idempotency check
if wp option get pod_shop_mock_data_seeded --allow-root --path="$WP_PATH" 2>/dev/null | grep -q "1"; then
  echo "Mock data already seeded – skipping (use --force to re-seed)"
  exit 0
fi

# Run PHP seed script via WP-CLI eval-file
wp eval-file /scripts/seed-products.php --allow-root --path="$WP_PATH"

# Mark as seeded
wp option update pod_shop_mock_data_seeded "1" --allow-root --path="$WP_PATH"

echo ""
echo "═══════════════════════════════════════════════════════════"
echo "  Mock data seeded successfully!"
echo "═══════════════════════════════════════════════════════════"
echo ""
```

### Code Example 6: docker-compose.yml wpcli uploads volume mount

Der `wpcli` Service in `docker-compose.yml` muss um den uploads-Volume-Mount erweitert werden. Das `wordpress` Service hat diesen Mount bereits — der `wpcli` Service braucht ihn zusätzlich für den Bild-Import in Slice 5.

```yaml
# In der wpcli service definition, volumes section:
# Vorher (nur /scripts mount):
  volumes:
    - ./scripts:/scripts

# Nachher (+ uploads mount):
  volumes:
    - ./scripts:/scripts
    - ./wordpress/uploads:/var/www/html/wp-content/uploads
```

---

## Constraints & Hinweise

**Betrifft:**
- `scripts/seed-products.php` — Vollständiges Refactoring (Bestehende Datei wird ersetzt)
- `scripts/mock-data.sh` — Erweiterung um `--force` Flag
- `docker-compose.yml` — wpcli volumes Erweiterung

**Bestehende Patterns beibehalten:**
- `pod_ensure_attribute()` — unverändert übernehmen
- `pod_ensure_term()` — unverändert übernehmen
- `pod_find_attribute_id()` — unverändert übernehmen
- `pod_create_variable_product()` — unverändert übernehmen (Kleidung: 5×4 Variationen)
- Legal Pages Section — unverändert übernehmen
- `WP_CLI::log` / `WP_CLI::success` / `WP_CLI::error` — Pattern beibehalten

**Datei-Reihenfolge in seed-products.php:**
1. WooCommerce-Check + Helper-Funktionen (bestehend + neu)
2. Attribute sicherstellen (pa_groesse, pa_farbe) — unverändert
3. Katalog laden (`json_decode(file_get_contents('/scripts/product-catalog.json'))`)
4. Parent-Kategorien erstellen (`$parent_ids[]` Map aufbauen)
5. Child-Kategorien erstellen (mit parent ID lookup aus `$parent_ids[]`)
6. Produkte erstellen (loop über `$catalog->products`, dispatch nach `type`)
7. Featured-Markierung (innerhalb des Produkt-Loops)
8. Mock-Reviews für Featured-Produkte (nach dem Produkt-Loop mit `$featured_product_ids[]`)
9. Legal Pages (unverändert)

**Abgrenzung:**
- Bild-Import (Slice 5) ist NICHT Teil dieses Slices — Produkte werden ohne Bilder erstellt
- Das `motif`-Feld aus dem JSON wird in diesem Slice ignoriert — es ist für Slice 4 (Bild-Generierung)
- `$featured_product_ids[]` wird während des Produkt-Loops gesammelt (neue + skipped existing featured)

**Docker-Volume:**
- Der `wpcli`-Container benötigt das uploads-Volume JETZT (Vorbereitung für Slice 5)
- Das Verzeichnis `wordpress/uploads/products/` muss existieren (wird von `generate-images.mjs` in Slice 4 befüllt)

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig und vollständig
- [x] Sicherheits-/Privacy-Aspekte bedacht (Mock-Daten, keine echten Nutzerdaten, Reviews mit Fake-E-Mails)
- [x] Kein UI — keine UX/Copy notwendig

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Scripts
- [ ] `scripts/seed-products.php` — Vollständiges Refactoring: liest `product-catalog.json`, erstellt 3 Parent-Kategorien + 12 Child-Kategorien mit parent-Beziehung, dispatcht Produkte nach type (`variable-size-color` / `variable-color` / `simple`), setzt Featured-Markierung, erstellt 3–5 Mock-Reviews pro Featured-Produkt mit deutschen Namen und gewichtetem Rating. Bestehende Helpers (`pod_ensure_attribute`, `pod_ensure_term`, `pod_find_attribute_id`, `pod_create_variable_product`) und Legal Pages Section bleiben unverändert. Neue Helpers: `pod_create_simple_product()` und `pod_create_color_only_variable_product()`.
- [ ] `scripts/mock-data.sh` — Erweiterung um optionales `--force` Flag: löscht `pod_shop_mock_data_seeded` WP-Option vor dem Seed-Aufruf wenn `--force` übergeben wird.
- [ ] `docker-compose.yml` — wpcli service: `- ./wordpress/uploads:/var/www/html/wp-content/uploads` Volume-Mount hinzufügen.

### Tests
- [ ] `tests/slices/seed-data/slice-02-seed-script-erweiterung.test.ts` — Vitest-Tests die PHP-Datei-Inhalt und Shell-Script-Inhalt per `readFileSync` prüfen: neue Funktionen vorhanden, JSON-Loading, Kategorie-Hierarchie, Typ-Dispatch, Featured-Flag, Review-Logik, --force Flag, docker-compose.yml uploads-mount.
<!-- DELIVERABLES_END -->

---

## Links

- Architecture: `specs/phase-1/2026-02-27-seed-data/architecture.md`
- Discovery: `specs/phase-1/2026-02-27-seed-data/discovery.md`
- Approved Dependency: `specs/phase-1/2026-02-27-seed-data/slices/slice-01-produktkatalog-definition.md`
- Bestehendes Seed-Script (wird ersetzt): `scripts/seed-products.php`
- Bestehendes Mock-Data-Wrapper (wird erweitert): `scripts/mock-data.sh`
- Docker-Compose (wird erweitert): `docker-compose.yml`
