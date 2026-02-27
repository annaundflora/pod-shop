# Slice 5: Bild-Import im Seed

> **Slice 5 von 5** für `Seed Data — 100+ POD-Produkte mit KI-generierten Bildern`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-04-bild-generierung-script.md` |
> | **Nächster:** | — |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-05-bild-import-seed` |
| **Test** | `pnpm test tests/slices/seed-data/slice-05-bild-import-seed.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-02-seed-script-erweiterung", "slice-04-bild-generierung-script"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Vitest-Tests prüfen die PHP-Code-Patterns (Datei-Struktur statisch via `readFileSync`)
- **E2E**: `false` — Vitest Unit Tests, kein Playwright
- **Dependencies**: Slice 2 liefert das erweiterte `seed-products.php` mit Produkterstellung; Slice 4 liefert WebP-Dateien in `wordpress/uploads/products/`

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` enthält `next` + `vitest` Dependencies. PHP-Datei vorhanden in `scripts/`.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/seed-data/slice-05-bild-import-seed.test.ts` |
| **Integration Command** | `docker compose exec wpcli wp eval-file /scripts/seed-products.php --allow-root --path=/var/www/html` |
| **Acceptance Command** | `pnpm test tests/slices/seed-data/slice-05-bild-import-seed.test.ts` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `no_mocks` |

**Erklaerung:**
- **Stack**: typescript-nextjs (Vitest für PHP-Datei-Struktur-Tests)
- **Test Command**: Liest `scripts/seed-products.php` per `readFileSync` und prüft, dass alle drei Helper-Funktionen und der Idempotenz-Check vorhanden sind
- **Integration Command**: Führt das tatsächliche Seed-Script im Docker-Container aus (braucht laufende Docker-Umgebung)
- **Mocking Strategy**: `no_mocks` — Tests prüfen Datei-Inhalte statisch, kein Mocking nötig

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Produktkatalog-Definition | Done | `slice-01-produktkatalog-definition.md` |
| 2 | Seed-Script Erweiterung | Done | `slice-02-seed-script-erweiterung.md` |
| 3 | Motiv-Definition pro Produkt | Done | `slice-03-motiv-definition.md` |
| 4 | Bild-Generierung Script | Done | `slice-04-bild-generierung-script.md` |
| 5 | Bild-Import im Seed | Ready | `slice-05-bild-import-seed.md` |

---

## Kontext & Ziel

Das erweiterte `scripts/seed-products.php` (Slice 2) erstellt 110 Produkte und 12 Kategorien, aber noch ohne Bilder — `image.sourceUrl` ist in der GraphQL-Response `null`. Slice 4 hat WebP-Dateien nach `wordpress/uploads/products/{category-slug}/` generiert und committed.

Dieser Slice erweitert `seed-products.php` um drei Helper-Funktionen, die WebP-Dateien als WordPress-Media-Library-Einträge importieren und den Produkten sowie Kategorien zuweisen. Nach diesem Slice zeigt der GraphQL-Query `image { sourceUrl }` für alle 110 Produkte einen gültigen URL, und die Homepage-Featured-Section sowie Kategorie-Seiten zeigen echte Produktbilder.

**Aktuelle Probleme:**
1. `seed-products.php` ruft nach Produkterstellung keine Bild-Import-Logik auf
2. `image.sourceUrl` ist `null` für alle 110 Produkte in GraphQL
3. Kategorie-`thumbnail_id` ist nicht gesetzt → Kategorie-Bilder fehlen in der Navigation
4. Keine Idempotenz beim Attachment-Import → doppeltes Seed würde Duplikate erzeugen

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Server Logic / Business Logic Flow

```
Phase 2: Docker Seed (automatic on docker compose up)

setup.sh → mock-data.sh → seed-products.php
  ├── Check idempotency (WP option: pod_shop_mock_data_seeded)
  ├── Create parent categories (3)
  ├── Create child categories (12)
  ├── Create products (110)
  ├── Set featured (10 products)
  ├── Import images as WP attachments     ← DIESER SLICE
  │   ├── wp_insert_attachment()
  │   ├── wp_generate_attachment_metadata()
  │   └── set_image_id() / set_gallery_image_ids()
  ├── Assign category images: update_term_meta(thumbnail_id)
  ├── Create reviews
  └── Set idempotency flag
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `scripts/seed-products.php` | Drei neue Helper-Funktionen hinzufügen: `pod_create_attachment()`, `pod_import_product_images()`, `pod_import_category_image()`. Aufruf dieser Helpers nach Produkterstellung und Kategorieerstellung einfügen. |
| `docker-compose.yml` | Kein Änderungsbedarf — Volume-Mount `./wordpress/uploads:/var/www/html/wp-content/uploads` wird von Slice 2 hinzugefügt (bereits in den Dependencies). |

### 2. Datenfluss

```
wordpress/uploads/products/{cat-slug}/{slug}-1.webp  (vom Slice 4 generiert + committed)
  ↓
wp_upload_dir()['basedir']  → Absoluter Basis-Pfad im Container (/var/www/html/wp-content/uploads)
  ↓
pod_create_attachment(abs_path, parent_product_id)
  ↓  ├── Idempotenz-Check: WP_Query für _wp_attached_file meta
  ↓  ├── wp_insert_attachment(attachment_data, abs_path, parent_id)
  ↓  ├── wp_generate_attachment_metadata(attachment_id, abs_path)
  ↓  └── Returns attachment_id (int), 0 on failure
  ↓
pod_import_product_images(product_id, cat_slug, product_slug)
  ↓  ├── Bild 1: pod_create_attachment() → $product->set_image_id($id1)
  ↓  ├── Bild 2 (optional): pod_create_attachment() → $product->set_gallery_image_ids([$id2])
  ↓  └── $product->save()
  ↓
pod_import_category_image(cat_id, cat_slug)
  ↓  ├── pod_create_attachment(category-{slug}.webp, 0)
  ↓  └── update_term_meta($cat_id, 'thumbnail_id', $attachment_id)
  ↓
GraphQL: image { sourceUrl }  → nicht null
```

### 3. Dateipfad-Konvention

| Pfad-Art | Wert | Verwendung |
|----------|------|------------|
| Uploads-Basis (Container) | `wp_upload_dir()['basedir']` | Absoluter Pfad-Prefix |
| Produkt-Bild 1 (absolut) | `{basedir}/products/{cat-slug}/{slug}-1.webp` | `wp_insert_attachment()` arg |
| Produkt-Bild 2 (absolut) | `{basedir}/products/{cat-slug}/{slug}-2.webp` | Galerie-Bild (optional) |
| Kategorie-Bild (absolut) | `{basedir}/products/{cat-slug}/category-{slug}.webp` | `update_term_meta` thumbnail |
| `_wp_attached_file` meta (relativ) | `products/{cat-slug}/{slug}-1.webp` | Idempotenz-Check + WP-Meta |

### 4. WordPress-Attachment-API

| WP-Funktion | Signatur | Rückgabe | Zweck |
|-------------|----------|---------|-------|
| `wp_check_filetype()` | `(string $file)` | `['type' => 'image/webp', ...]` | MIME-Typ ermitteln |
| `wp_insert_attachment()` | `(array $args, string $file, int $parent_id)` | `int $attachment_id` oder `WP_Error` | Media-Library-Eintrag erstellen |
| `wp_generate_attachment_metadata()` | `(int $attach_id, string $file)` | `array $metadata` | Thumbnails generieren |
| `wp_update_attachment_metadata()` | `(int $attach_id, array $data)` | `bool` | Metadata speichern |
| `update_term_meta()` | `(int $term_id, string $key, mixed $value)` | `int\|bool` | Kategorie-Bild setzen |

### 5. Idempotenz-Mechanismus

Vor jedem `wp_insert_attachment()` prüft `pod_create_attachment()` via WP_Query, ob bereits ein Attachment mit demselben `_wp_attached_file`-Meta-Wert existiert. Wenn ja, wird die vorhandene ID zurückgegeben statt ein Duplikat zu erstellen.

```
WP_Query:
  post_type    = attachment
  meta_key     = _wp_attached_file
  meta_value   = products/{cat-slug}/{slug}-1.webp
  posts_per_page = 1
```

### 6. Graceful Degradation

| Fehlerfall | Verhalten | Logging |
|------------|-----------|---------|
| Bild-Datei nicht gefunden (`!file_exists()`) | Überspringen, nächstes Produkt | `WP_CLI::warning("Image not found: $file_path, skipping")` |
| `wp_insert_attachment()` gibt `WP_Error` | Überspringen, nächstes Produkt | `WP_CLI::warning("Attachment failed for $file_path: ...")` |
| `wp_generate_attachment_metadata()` schlägt fehl (kein GD/Imagick) | Thumbnails fehlen, Hauptbild ist gesetzt | `WP_CLI::warning("Metadata generation failed for ID $attach_id")` |
| Kategorie-Bild fehlt | Kategorie ohne Thumbnail-Bild | `WP_CLI::warning("Category image not found: $cat_path, skipping")` |

### 7. Integration in bestehendes Seed-Script (Aufruf-Punkte)

Die Bild-Import-Helfer werden in den bestehenden Produkt- und Kategorie-Erstellungs-Loop aus Slice 2 integriert:

```
// Nach pod_create_variable_product() / pod_create_simple_product():
pod_import_product_images($product_id, $cat_slug, $product_slug);

// Nach wp_insert_term() / Kategorie-Erstellung:
pod_import_category_image($cat_id, $cat_slug);
```

---

## Acceptance Criteria

1) GIVEN Bilder existieren in `wordpress/uploads/products/`, WHEN Seed ausgeführt wird, THEN haben alle Produkte einen nicht-null `image.sourceUrl` in der GraphQL-Response.

2) GIVEN Bilder existieren in `wordpress/uploads/products/`, WHEN Seed ausgeführt wird, THEN haben alle Kategorien `thumbnail_id` als Term-Meta gesetzt (Kategorie-Bilder sichtbar).

3) GIVEN ein Produkt hat 2 Bild-Dateien (`{slug}-1.webp` und `{slug}-2.webp`), WHEN man die WP-Media-Library prüft, THEN hat das Produkt 1 Featured Image (via `set_image_id`) + 1 Gallery Image (via `set_gallery_image_ids`).

4) GIVEN Bild-Dateien existieren NICHT, WHEN Seed ausgeführt wird, THEN werden Produkte ohne Bilder erstellt (kein Abbruch, kein PHP-Fehler, `WP_CLI::warning` im Log).

5) GIVEN Seed wurde bereits einmal ausgeführt, WHEN Seed erneut ausgeführt wird (Idempotenz), THEN werden keine doppelten Attachments in der Media-Library erstellt (gleiche `_wp_attached_file` meta → bestehende ID wird wiederverwendet).

6) GIVEN Seed nach `generate-images.mjs` ausgeführt wird, WHEN alle 110 Produkte in GraphQL abgefragt werden, THEN haben alle Produkte `image { sourceUrl }` nicht null (100% Abdeckung, sofern Bilder vorhanden).

---

## Testfälle

### Test-Datei

`tests/slices/seed-data/slice-05-bild-import-seed.test.ts`

<test_spec>
```typescript
// tests/slices/seed-data/slice-05-bild-import-seed.test.ts
import { describe, it, expect } from 'vitest'
import { readFileSync } from 'fs'
import { join } from 'path'

const seedScript = readFileSync(
  join(process.cwd(), 'scripts/seed-products.php'),
  'utf-8'
)

describe('Slice 05: Bild-Import im Seed (seed-products.php)', () => {
  describe('pod_create_attachment()', () => {
    it('should define the pod_create_attachment helper function', () => {
      expect(seedScript).toContain('function pod_create_attachment(')
    })

    it('should accept $file_path and $parent_id parameters', () => {
      expect(seedScript).toContain('pod_create_attachment(string $file_path, int $parent_id)')
    })

    it('should check file existence before attempting import', () => {
      expect(seedScript).toContain('file_exists($file_path)')
    })

    it('should perform idempotency check via WP_Query on _wp_attached_file meta', () => {
      expect(seedScript).toContain('_wp_attached_file')
      expect(seedScript).toContain('WP_Query')
    })

    it('should call wp_insert_attachment()', () => {
      expect(seedScript).toContain('wp_insert_attachment(')
    })

    it('should call wp_generate_attachment_metadata()', () => {
      expect(seedScript).toContain('wp_generate_attachment_metadata(')
    })

    it('should return 0 on missing file (graceful degradation)', () => {
      // Function returns int — 0 on failure path
      expect(seedScript).toContain('return 0;')
    })

    it('should emit WP_CLI::warning when file not found', () => {
      expect(seedScript).toContain("WP_CLI::warning(")
    })
  })

  describe('pod_import_product_images()', () => {
    it('should define the pod_import_product_images helper function', () => {
      expect(seedScript).toContain('function pod_import_product_images(')
    })

    it('should accept product_id, category_slug, and product_slug parameters', () => {
      expect(seedScript).toContain('pod_import_product_images(int $product_id, string $category_slug, string $product_slug)')
    })

    it('should build image path using wp_upload_dir()', () => {
      expect(seedScript).toContain('wp_upload_dir()')
    })

    it('should call set_image_id() for the featured image', () => {
      expect(seedScript).toContain('set_image_id(')
    })

    it('should call set_gallery_image_ids() for the second image', () => {
      expect(seedScript).toContain('set_gallery_image_ids(')
    })

    it('should check for second image before adding gallery', () => {
      // Second image path uses -2.webp
      expect(seedScript).toContain('-2.webp')
    })
  })

  describe('pod_import_category_image()', () => {
    it('should define the pod_import_category_image helper function', () => {
      expect(seedScript).toContain('function pod_import_category_image(')
    })

    it('should accept cat_id and category_slug parameters', () => {
      expect(seedScript).toContain('pod_import_category_image(int $cat_id, string $category_slug)')
    })

    it('should use category-{slug}.webp naming convention', () => {
      expect(seedScript).toContain('category-')
    })

    it('should call update_term_meta() with thumbnail_id', () => {
      expect(seedScript).toContain("update_term_meta(")
      expect(seedScript).toContain("'thumbnail_id'")
    })
  })

  describe('Integration: Aufruf nach Produkterstellung', () => {
    it('should call pod_import_product_images after product creation', () => {
      expect(seedScript).toContain('pod_import_product_images(')
    })

    it('should call pod_import_category_image after category creation', () => {
      expect(seedScript).toContain('pod_import_category_image(')
    })
  })

  describe('Datei-Pfad-Konvention', () => {
    it('should use products/{cat-slug}/{slug}-1.webp path pattern', () => {
      expect(seedScript).toContain('-1.webp')
    })

    it('should use basedir from wp_upload_dir() for absolute path', () => {
      expect(seedScript).toContain("['basedir']")
    })
  })
})
```
</test_spec>

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig und vollständig
- [x] Sicherheits-/Privacy-Aspekte bedacht (Bilder sind öffentliche Produktbilder, kein sensitiver Inhalt)
- [x] Graceful Degradation für fehlende Bild-Dateien definiert
- [x] Idempotenz-Mechanismus spezifiziert

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft. Unvollständige Contracts blockieren die Genehmigung.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-02-seed-script-erweiterung | `scripts/seed-products.php` | PHP-Datei | Existiert, enthält `pod_create_variable_product()`, `pod_create_simple_product()`, `pod_create_color_only_variable_product()`. Produkte und Kategorien werden VOR dem Bild-Import erstellt → `$product_id` und `$cat_id` verfügbar. |
| slice-02-seed-script-erweiterung | `docker-compose.yml` wpcli-Service | Docker-Config | Volume-Mount `./wordpress/uploads:/var/www/html/wp-content/uploads` muss im wpcli-Service vorhanden sein — ohne diesen Mount kann `wp_insert_attachment()` nicht auf die Bild-Dateien zugreifen. |
| slice-04-bild-generierung-script | WebP-Dateien in `wordpress/uploads/products/{cat-slug}/` | Filesystem | Dateien `{slug}-1.webp`, `{slug}-2.webp`, `category-{slug}.webp` wurden generiert und committed. Graceful Degradation bei fehlenden Dateien ist implementiert. |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `image { sourceUrl }` nicht null in GraphQL | GraphQL-Daten | Next.js Frontend (keine Codeänderung) | WPGraphQL liest `_wp_attachment_metadata` und generiert CDN-URL; Frontend-Queries `GET_FEATURED_PRODUCTS`, `GET_PRODUCTS`, `GET_PRODUCT` zeigen Bilder ohne Änderung |
| `thumbnail_id` Term-Meta auf Kategorien | WordPress-Meta | Next.js Frontend via GraphQL | WooGraphQL/WPGraphQL liefert Kategorie-Bilder via `image { sourceUrl }` auf Category-Typ |

### Integration Validation Tasks

- [ ] Volume-Mount im wpcli-Service vorhanden (Slice 2 Deliverable)
- [ ] WebP-Dateien in korrektem Pfad vorhanden (Slice 4 Deliverable)
- [ ] `pod_import_product_images()` wird nach jedem `pod_create_*_product()` Aufruf aufgerufen
- [ ] `pod_import_category_image()` wird nach jeder Kategorie-Erstellung aufgerufen
- [ ] GraphQL-Query `image { sourceUrl }` auf mindestens einem Produkt nicht null (Smoke Test nach Seed)

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind PFLICHT-Deliverables.
> Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `pod_create_attachment()` | Code Examples — Helper 1 | YES | Signatur, Idempotenz-Check und WP-API-Aufrufe exakt wie spezifiziert |
| `pod_import_product_images()` | Code Examples — Helper 2 | YES | Signatur und set_image_id / set_gallery_image_ids exakt wie spezifiziert |
| `pod_import_category_image()` | Code Examples — Helper 3 | YES | Signatur und update_term_meta mit 'thumbnail_id' exakt wie spezifiziert |
| Idempotenz-Check WP_Query | Code Examples — Idempotenz | YES | WP_Query auf `_wp_attached_file` meta muss vorhanden sein |

### Helper 1: `pod_create_attachment()`

```php
/**
 * Erstellt einen WordPress-Media-Library-Eintrag fuer eine Bild-Datei.
 * Idempotent: gibt vorhandene Attachment-ID zurueck wenn Datei bereits importiert.
 *
 * @param string $file_path Absoluter Pfad zur Bild-Datei im Container-Filesystem
 * @param int    $parent_id Post-ID des Eltern-Posts (Produkt-ID), 0 fuer Kategoriebilder
 * @return int Attachment-ID oder 0 bei Fehler
 */
function pod_create_attachment(string $file_path, int $parent_id): int
{
    // Graceful degradation: Datei nicht vorhanden
    if (!file_exists($file_path)) {
        WP_CLI::warning("Image not found: $file_path, skipping");
        return 0;
    }

    // Idempotenz-Check: Relativen Pfad relativ zu uploads-Verzeichnis berechnen
    $upload_dir   = wp_upload_dir();
    $relative_path = ltrim(str_replace($upload_dir['basedir'], '', $file_path), '/');

    $existing = new WP_Query([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'meta_query'     => [[
            'key'   => '_wp_attached_file',
            'value' => $relative_path,
        ]],
    ]);

    if ($existing->have_posts()) {
        $attachment_id = (int) $existing->posts[0]->ID;
        WP_CLI::log("  Attachment already exists (ID: $attachment_id): $relative_path");
        return $attachment_id;
    }

    // MIME-Typ ermitteln
    $filetype = wp_check_filetype($file_path);
    if (empty($filetype['type'])) {
        WP_CLI::warning("Could not determine MIME type for: $file_path, skipping");
        return 0;
    }

    // Attachment-Daten zusammenstellen
    $attachment = [
        'post_mime_type' => $filetype['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($file_path)),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    // Attachment in WP Media Library einfuegen
    $attachment_id = wp_insert_attachment($attachment, $file_path, $parent_id);

    if (is_wp_error($attachment_id)) {
        WP_CLI::warning("Attachment failed for $file_path: " . $attachment_id->get_error_message());
        return 0;
    }

    // Sicherstellen dass wp_generate_attachment_metadata verfuegbar ist
    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    // Thumbnails und Metadaten generieren
    $metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
    if (empty($metadata)) {
        WP_CLI::warning("Metadata generation failed for attachment ID $attachment_id (GD/Imagick may be missing)");
    } else {
        wp_update_attachment_metadata($attachment_id, $metadata);
    }

    return $attachment_id;
}
```

### Helper 2: `pod_import_product_images()`

```php
/**
 * Importiert Produktbilder als WP-Attachments und weist sie dem Produkt zu.
 * Erwartet: {basedir}/products/{category_slug}/{product_slug}-1.webp
 *           {basedir}/products/{category_slug}/{product_slug}-2.webp (optional)
 *
 * @param int    $product_id    WooCommerce-Produkt-ID
 * @param string $category_slug Kategorie-Slug (z.B. 't-shirts')
 * @param string $product_slug  Produkt-Slug (z.B. 'cozy-bear-hoodie')
 * @return void
 */
function pod_import_product_images(int $product_id, string $category_slug, string $product_slug): void
{
    $upload_dir = wp_upload_dir();
    $base       = $upload_dir['basedir'];

    $image1_path = "{$base}/products/{$category_slug}/{$product_slug}-1.webp";
    $image2_path = "{$base}/products/{$category_slug}/{$product_slug}-2.webp";

    $product = wc_get_product($product_id);
    if (!$product) {
        WP_CLI::warning("Product not found for image import (ID: $product_id)");
        return;
    }

    // Hauptbild (Featured Image)
    $attachment_id_1 = pod_create_attachment($image1_path, $product_id);
    if ($attachment_id_1 > 0) {
        $product->set_image_id($attachment_id_1);
        WP_CLI::log("  Featured image set (Attachment ID: $attachment_id_1) for product $product_slug");
    }

    // Galerie-Bild (zweites Bild, optional)
    $attachment_id_2 = pod_create_attachment($image2_path, $product_id);
    if ($attachment_id_2 > 0) {
        $product->set_gallery_image_ids([$attachment_id_2]);
        WP_CLI::log("  Gallery image set (Attachment ID: $attachment_id_2) for product $product_slug");
    }

    // Produkt nur speichern wenn mindestens ein Bild importiert wurde
    if ($attachment_id_1 > 0 || $attachment_id_2 > 0) {
        $product->save();
    }
}
```

### Helper 3: `pod_import_category_image()`

```php
/**
 * Importiert ein Kategorie-Bild und setzt es als thumbnail_id Term-Meta.
 * Erwartet: {basedir}/products/{category_slug}/category-{category_slug}.webp
 *
 * @param int    $cat_id        WordPress Term-ID der Kategorie
 * @param string $category_slug Kategorie-Slug (z.B. 't-shirts')
 * @return void
 */
function pod_import_category_image(int $cat_id, string $category_slug): void
{
    $upload_dir = wp_upload_dir();
    $base       = $upload_dir['basedir'];
    $cat_path   = "{$base}/products/{$category_slug}/category-{$category_slug}.webp";

    // Kategorie-Bild als Attachment importieren (parent_id = 0, kein Eltern-Post)
    $attachment_id = pod_create_attachment($cat_path, 0);

    if ($attachment_id > 0) {
        update_term_meta($cat_id, 'thumbnail_id', $attachment_id);
        WP_CLI::log("  Category image set (Attachment ID: $attachment_id) for category $category_slug");
    }
}
```

### Idempotenz-Check (Inline in `pod_create_attachment()`)

```php
// Idempotenz: Pruefen ob Attachment mit diesem Datei-Pfad bereits existiert
$existing = new WP_Query([
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'posts_per_page' => 1,
    'meta_query'     => [[
        'key'   => '_wp_attached_file',
        'value' => $relative_path,  // z.B. "products/t-shirts/cozy-bear-hoodie-1.webp"
    ]],
]);

if ($existing->have_posts()) {
    return (int) $existing->posts[0]->ID;  // Vorhandene ID wiederverwenden, kein Duplikat
}
```

### Aufruf-Integration in Seed-Loop (aus Slice 2)

```php
// Beispiel: Nach pod_create_variable_product() im Produkt-Loop
foreach ($catalog['products'] as $product_data) {
    $existing = get_page_by_path($product_data['slug'], OBJECT, 'product');
    if (!$existing) {
        $product_id = pod_create_variable_product([/* ... */]);
    } else {
        $product_id = (int) $existing->ID;
        WP_CLI::log("  Product '{$product_data['name']}' already exists (ID: $product_id)");
    }

    // Bild-Import wird IMMER aufgerufen (idempotent durch pod_create_attachment)
    pod_import_product_images($product_id, $product_data['category_slug'], $product_data['slug']);
}

// Beispiel: Nach Kategorie-Erstellung im Kategorie-Loop
foreach ($catalog['categories'] as $cat_data) {
    $term = get_term_by('slug', $cat_data['slug'], 'product_cat');
    $cat_id = $term
        ? (int) $term->term_id
        : (int) wp_insert_term($cat_data['name'], 'product_cat', [
            'slug'        => $cat_data['slug'],
            'parent'      => $parent_id,
            'description' => $cat_data['description'] ?? '',
          ])['term_id'];

    // Kategorie-Bild importieren (idempotent)
    pod_import_category_image($cat_id, $cat_data['slug']);
}
```

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Scripts

- [ ] `scripts/seed-products.php` — Erweiterung um drei Helper-Funktionen: `pod_create_attachment()`, `pod_import_product_images()`, `pod_import_category_image()`. Aufruf nach Produkterstellung (`pod_import_product_images`) und nach Kategorie-Erstellung (`pod_import_category_image`). Alle bestehenden Funktionen bleiben unverändert.

### Tests

- [ ] `tests/slices/seed-data/slice-05-bild-import-seed.test.ts` — Vitest-Tests die `scripts/seed-products.php` per `readFileSync` einlesen und prüfen, dass alle drei Helper-Funktionen, der Idempotenz-Check via WP_Query, `set_image_id()`, `set_gallery_image_ids()` und `update_term_meta('thumbnail_id', ...)` vorhanden sind.
<!-- DELIVERABLES_END -->

---

## Constraints & Hinweise

**Betrifft:**
- Nur `scripts/seed-products.php` — keine Frontend-Änderungen, keine Docker-Compose-Änderungen (Slice 2 zuständig), keine GraphQL-Schema-Änderungen

**WordPress-API-Besonderheiten:**
- `wp_generate_attachment_metadata()` benötigt `require_once ABSPATH . 'wp-admin/includes/image.php'` — muss explizit geladen werden, da seed-scripts außerhalb des Admin-Kontexts laufen
- `wp_insert_attachment()` dritter Parameter `$parent_id`: für Produktbilder = `$product_id`; für Kategorie-Bilder = `0` (kein Eltern-Post)
- `_wp_attached_file` Meta-Wert ist RELATIV zum uploads-Verzeichnis (ohne `/var/www/html/wp-content/uploads/`-Prefix)
- WooCommerce `$product->save()` muss nach `set_image_id()` und `set_gallery_image_ids()` aufgerufen werden

**Abgrenzung:**
- Bild-Generierung (Replicate API) ist NICHT Teil dieses Slices — das ist Slice 4
- Produkt- und Kategorie-Erstellung ist NICHT Teil dieses Slices — das ist Slice 2
- Docker-Compose Volume-Mount-Konfiguration ist NICHT Teil dieses Slices — das ist Slice 2
- Dieser Slice ist rein additiv: nur neue Helper-Funktionen + deren Aufruf-Punkte
