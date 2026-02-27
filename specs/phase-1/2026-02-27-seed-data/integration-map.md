# Integration Map: Seed Data — 100+ POD-Produkte mit KI-generierten Bildern

**Generated:** 2026-02-27
**Slices:** 5
**Connections:** 7

---

## Dependency Graph (Visual)

```
┌────────────────────────────┐    ┌────────────────────────────┐
│  Slice 01                  │    │  Slice 03                  │
│  Produktkatalog-Definition │    │  Motiv-Definition          │
│  (product-catalog.json)    │    │  (motif fields)            │
└────────────┬───────────────┘    └────────────┬───────────────┘
             │                                 │
             │ product-catalog.json            │ motif strings
             │ (categories + products)         │ (integrated into Slice 1)
             │                                 │
             ├─────────────────┬───────────────┘
             │                 │
             ▼                 ▼
┌────────────────────┐  ┌────────────────────────────────────────┐
│  Slice 02          │  │  Slice 04                              │
│  Seed-Script       │  │  Bild-Generierung Script               │
│  Erweiterung       │  │  (generate-images.mjs)                 │
│  (seed-products.   │  │  [developer-triggered one-time script] │
│   php)             │  └──────────────────┬─────────────────────┘
└────────┬───────────┘                     │
         │                                 │
         │ WP Produkte +                   │ WebP-Dateien in
         │ Kategorien (DB)                 │ wordpress/uploads/products/
         │ + docker-compose.yml            │
         │ uploads-Volume-Mount            │
         └─────────────────┬───────────────┘
                           │
                           ▼
              ┌────────────────────────┐
              │  Slice 05              │
              │  Bild-Import im Seed   │
              │  (seed-products.php    │
              │   Helper-Funktionen)   │
              └────────────────────────┘
                           │
                           ▼
              ┌────────────────────────────────┐
              │  Next.js Frontend (existing)   │
              │  image { sourceUrl } not null  │
              │  thumbnail_id on categories    │
              └────────────────────────────────┘
```

---

## Nodes

### Slice 01: Produktkatalog-Definition

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | None |
| Outputs | `scripts/product-catalog.json` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| — | — | Kein Slice — erster Slice ohne Dependencies |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `scripts/product-catalog.json` | JSON-Datei (110 Produkte, 15 Kategorien, leere `motif` Felder) | Slice 02, Slice 03 (Motiv-Befuellung), Slice 04 |

---

### Slice 02: Seed-Script Erweiterung

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | Slice 01 |
| Outputs | `scripts/seed-products.php` (refactored), `scripts/mock-data.sh` (--force), `docker-compose.yml` (uploads-Volume-Mount) |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `scripts/product-catalog.json` | Slice 01 | APPROVED — Datei wird per `json_decode(file_get_contents())` geladen |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `scripts/seed-products.php` (110 Produkte, 15 Kategorien, Featured, Reviews) | PHP-Script | Slice 05 (erweitert diese Datei um Helper-Funktionen) |
| `docker-compose.yml` (wpcli uploads-Volume-Mount) | Docker-Config | Slice 05 (benoetigt Mount fuer `wp_insert_attachment()`) |
| WooCommerce-Produkte (wp_posts type=product, 110 Eintraege) | WordPress-DB | Slice 05 (Produkt-IDs fuer Bild-Zuweisung) |
| WooCommerce-Kategorien (wp_term_taxonomy, 15 Eintraege) | WordPress-DB | Slice 05 (Kategorie-IDs fuer thumbnail_id) |
| Mock-Reviews (wp_comments + commentmeta) | WordPress-DB | Frontend (bestehende Produkt-Detail-Queries) |

---

### Slice 03: Motiv-Definition pro Produkt

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | None (keine harten Dependencies — Motiv-Strings sind Spec-Dokument) |
| Outputs | 110 Motiv-Beschreibungen (im Spec-Dokument), integriert in `product-catalog.json` via Slice 01 |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| — | — | Keine harten Dependencies |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| 110 Motiv-Strings (Spec-Dokument `slice-03-motiv-definition.md`) | Spec-Dokument | Slice 01 traegt diese als `motif`-Felder in `product-catalog.json` ein |
| `product.motif: string` in `product-catalog.json` (via Slice 01) | JSON-Feld | Slice 04 (`generate-images.mjs` liest `catalog[i].motif` fuer Replicate-Prompts) |

**Hinweis:** Slice 03 hat `Dependencies: []` in seinen Metadaten. Die Motiv-Strings werden in das Spec-Dokument geschrieben; Slice 01 integriert diese als `motif`-Felder in `product-catalog.json`. Dies ist ein konzeptueller Input ohne harte Datei-Dependency — beide Slices koennen parallel laufen, Slice 01 traegt die Motive aber ein.

---

### Slice 04: Bild-Generierung Script

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | Slice 01, Slice 03 |
| Outputs | `scripts/generate-images.mjs`, 220 Produktbilder (WebP), 12 Kategoriebilder (WebP) |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `scripts/product-catalog.json` | Slice 01 | APPROVED — `import catalog from '../scripts/product-catalog.json'` |
| `product.motif`-Felder (alle 110 befuellt) | Slice 03 (via Slice 01) | APPROVED — Motiv-Strings sind in product-catalog.json als nicht-leere `motif`-Felder vorhanden |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `scripts/generate-images.mjs` | Node.js ESM Script | Developer fuehrt einmalig aus (one-time, kein automatischer Build-Step) |
| `wordpress/uploads/products/{cat-slug}/{slug}-1.webp` (110 Dateien) | WebP-Bilddateien | Slice 05 (`pod_import_product_images()` liest Featured-Image) |
| `wordpress/uploads/products/{cat-slug}/{slug}-2.webp` (110 Dateien) | WebP-Bilddateien | Slice 05 (`pod_import_product_images()` liest Gallery-Image, optional) |
| `wordpress/uploads/products/{cat-slug}/category-{slug}.webp` (12 Dateien) | WebP-Bilddateien | Slice 05 (`pod_import_category_image()` liest Kategorie-Bild) |

---

### Slice 05: Bild-Import im Seed

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | Slice 02, Slice 04 |
| Outputs | `scripts/seed-products.php` (erweitert um 3 Helper-Funktionen), `image { sourceUrl }` nicht null in GraphQL |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `scripts/seed-products.php` (mit `pod_create_variable_product()`, `pod_create_simple_product()`, `pod_create_color_only_variable_product()`) | Slice 02 | APPROVED — Slice 05 erweitert diese Datei additiv |
| `docker-compose.yml` wpcli Volume-Mount `./wordpress/uploads:/var/www/html/wp-content/uploads` | Slice 02 | APPROVED — ohne diesen Mount kann `wp_insert_attachment()` nicht auf Bild-Dateien zugreifen |
| `wordpress/uploads/products/{cat-slug}/{slug}-{1|2}.webp` (WebP-Dateien) | Slice 04 | APPROVED — Graceful Degradation implementiert wenn Dateien fehlen |
| `wordpress/uploads/products/{cat-slug}/category-{slug}.webp` (12 Dateien) | Slice 04 | APPROVED — Graceful Degradation implementiert |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `image { sourceUrl }` nicht null in WPGraphQL-Response | WordPress-DB-Daten (via WPGraphQL) | Next.js Frontend — `GET_FEATURED_PRODUCTS`, `GET_PRODUCTS`, `GET_PRODUCT` Queries (bestehend, keine Codeaenderung) |
| `thumbnail_id` Term-Meta auf Kategorien | WordPress-DB (wp_term_meta) | Next.js Frontend via GraphQL — Kategorie-Bild-URLs in Navigation (bestehend, keine Codeaenderung) |

---

## Connections

| # | From | To | Resource | Type | Status |
|---|------|-----|----------|------|--------|
| 1 | Slice 01 | Slice 02 | `scripts/product-catalog.json` | JSON-Datei | APPROVED |
| 2 | Slice 01 | Slice 03 | `product-catalog.json` mit `motif: ""` Platzhalter | JSON-Feld | APPROVED |
| 3 | Slice 01 | Slice 04 | `scripts/product-catalog.json` | JSON-Datei | APPROVED |
| 4 | Slice 03 | Slice 04 | `product.motif`-Felder (110 Motiv-Strings) | JSON-Felder via Slice 01 | APPROVED |
| 5 | Slice 02 | Slice 05 | `scripts/seed-products.php` (Basis-Funktionen + DB-Produkte) | PHP-Script + WordPress-DB | APPROVED |
| 6 | Slice 02 | Slice 05 | `docker-compose.yml` uploads-Volume-Mount | Docker-Config | APPROVED |
| 7 | Slice 04 | Slice 05 | `wordpress/uploads/products/{cat-slug}/*.webp` (232 Dateien) | WebP-Bilddateien (Filesystem) | APPROVED |

---

## Validation Results

### Valid Connections: 7

Alle deklarierten Dependencies haben passende Outputs in vorherigen (oder parallel laufenden) Slices.

| Connection | Validation |
|------------|------------|
| Slice 02 benoetigt `product-catalog.json` → Slice 01 liefert es | APPROVED |
| Slice 04 benoetigt `product-catalog.json` → Slice 01 liefert es | APPROVED |
| Slice 04 benoetigt `product.motif`-Felder → Slice 03 definiert diese (via Slice 01 integriert) | APPROVED |
| Slice 05 benoetigt `seed-products.php` Basis → Slice 02 liefert es | APPROVED |
| Slice 05 benoetigt docker-compose.yml Volume-Mount → Slice 02 liefert es | APPROVED |
| Slice 05 benoetigt WebP-Dateien → Slice 04 generiert sie | APPROVED (Graceful Degradation bei fehlenden Dateien) |

### Orphaned Outputs: 0

Alle Outputs werden konsumiert:

| Output | Defined In | Consumers | Status |
|--------|------------|-----------|--------|
| `scripts/product-catalog.json` | Slice 01 | Slice 02, Slice 03, Slice 04 | Vollstaendig konsumiert |
| `scripts/seed-products.php` (Basis) | Slice 02 | Slice 05 (additiv erweitert) | Vollstaendig konsumiert |
| `docker-compose.yml` Volume-Mount | Slice 02 | Slice 05 | Vollstaendig konsumiert |
| WooCommerce-Produkte (DB) | Slice 02 | Slice 05 (Bild-Zuweisung) | Vollstaendig konsumiert |
| WooCommerce-Kategorien (DB) | Slice 02 | Slice 05 (thumbnail_id) | Vollstaendig konsumiert |
| Mock-Reviews (DB) | Slice 02 | Frontend (bestehende Queries) | User-facing Output |
| `scripts/generate-images.mjs` | Slice 04 | Developer (one-time Ausfuehrung) | Developer-Tool — kein weiterer Slice-Consumer noetig |
| WebP-Dateien (232 Stueck) | Slice 04 | Slice 05 | Vollstaendig konsumiert |
| `image { sourceUrl }` (GraphQL) | Slice 05 | Next.js Frontend | User-facing Output |
| `thumbnail_id` Term-Meta | Slice 05 | Next.js Frontend via GraphQL | User-facing Output |

### Missing Inputs: 0

Alle Inputs haben passende Producers. Keine fehlenden Verbindungen.

### Deliverable-Consumer Gaps: 0

| Component | Defined In | Consumer Page | Page In Deliverables? | Status |
|-----------|------------|---------------|-----------------------|--------|
| `product-catalog.json` | Slice 01 | `scripts/seed-products.php` | Slice 02 Deliverable | KEIN GAP |
| `product-catalog.json` | Slice 01 | `scripts/generate-images.mjs` | Slice 04 Deliverable | KEIN GAP |
| `scripts/seed-products.php` | Slice 02 | Slice 05 (additiv) | Slice 05 Deliverable | KEIN GAP |
| WebP-Dateien | Slice 04 | `pod_import_product_images()` in `seed-products.php` | Slice 05 Deliverable | KEIN GAP |
| `image { sourceUrl }` | Slice 05 | Next.js Frontend (bestehende Queries) | Pre-existing (keine Aenderung noetig) | KEIN GAP |

---

## Discovery Traceability

### Kategorie-Struktur Coverage

| Discovery Element | Covered In | Status |
|-------------------|------------|--------|
| Parent: Kleidung (`kleidung`, `parent: null`) | Slice 01 | ABGEDECKT |
| Parent: Accessoires (`accessoires`, `parent: null`) | Slice 01 | ABGEDECKT |
| Parent: Wohnen & Geschenke (`wohnen-geschenke`, `parent: null`) | Slice 01 | ABGEDECKT |
| Kind: T-Shirts (`t-shirts`, Variable, Groesse+Farbe) | Slice 01 + Slice 02 | ABGEDECKT |
| Kind: Hoodies (`hoodies`, Variable, Groesse+Farbe) | Slice 01 + Slice 02 | ABGEDECKT |
| Kind: Sweatshirts (`sweatshirts`, Variable, Groesse+Farbe) | Slice 01 + Slice 02 | ABGEDECKT |
| Kind: Tanktops (`tanktops`, Variable, Groesse+Farbe) | Slice 01 + Slice 02 | ABGEDECKT |
| Kind: Langarmshirts (`langarmshirts`, Variable, Groesse+Farbe) | Slice 01 + Slice 02 | ABGEDECKT |
| Kind: Taschen (`taschen`, Variable, Farbe) | Slice 01 + Slice 02 | ABGEDECKT |
| Kind: Muetzen & Caps (`muetzen-caps`, Variable, Farbe) | Slice 01 + Slice 02 | ABGEDECKT |
| Kind: Buttons & Anstecker (`buttons-anstecker`, Simple, 0 Produkte) | Slice 01 (Kategorie vorhanden, keine Produkte) | ABGEDECKT |
| Kind: Tassen (`tassen`, Simple) | Slice 01 + Slice 02 | ABGEDECKT |
| Kind: Poster & Kunstdrucke (`poster-kunstdrucke`, Simple) | Slice 01 + Slice 02 | ABGEDECKT |
| Kind: Kissen (`kissen`, Variable, Farbe) | Slice 01 + Slice 02 | ABGEDECKT |
| Kind: Handyhuellen (`handyhuellen`, Simple) | Slice 01 + Slice 02 | ABGEDECKT |

### Produktverteilung Coverage

| Kategorie | Discovery Anzahl | Covered In | Status |
|-----------|-----------------|------------|--------|
| T-Shirts | 20 | Slice 01 (20 Eintraege) | ABGEDECKT |
| Hoodies | 12 | Slice 01 (12 Eintraege) | ABGEDECKT |
| Sweatshirts | 10 | Slice 01 (10 Eintraege) | ABGEDECKT |
| Tanktops | 8 | Slice 01 (8 Eintraege) | ABGEDECKT |
| Langarmshirts | 8 | Slice 01 (8 Eintraege) | ABGEDECKT |
| Taschen | 10 | Slice 01 (10 Eintraege) | ABGEDECKT |
| Muetzen & Caps | 8 | Slice 01 (8 Eintraege) | ABGEDECKT |
| Tassen | 10 | Slice 01 (10 Eintraege) | ABGEDECKT |
| Poster & Kunstdrucke | 10 | Slice 01 (10 Eintraege) | ABGEDECKT |
| Kissen | 6 | Slice 01 (6 Eintraege) | ABGEDECKT |
| Handyhuellen | 8 | Slice 01 (8 Eintraege) | ABGEDECKT |
| Gesamt | 110 | Slice 01 | ABGEDECKT |

### Business Rules Coverage

| Business Rule | Covered In | Status |
|---------------|------------|--------|
| Kleidung = Variable Products (Groesse + Farbe) | Slice 01 (`type: variable-size-color`) + Slice 02 (`pod_create_variable_product()`) | ABGEDECKT |
| Accessoires Taschen/Muetzen/Kissen = Variable (nur Farbe) | Slice 01 (`type: variable-color`) + Slice 02 (`pod_create_color_only_variable_product()`) | ABGEDECKT |
| Wohnen/Buttons = Simple Products | Slice 01 (`type: simple`) + Slice 02 (`pod_create_simple_product()`) | ABGEDECKT |
| ~10% Featured (~10 Produkte) | Slice 01 (`featured: true` auf 8 Produkten) + Slice 02 (`set_featured(true)`) | ABGEDECKT |
| 3-5 deutsche Mock-Reviews pro Featured-Produkt | Slice 02 (Review-Logik mit mt_rand(3,5), Rating 3-5, deutsche Namen) | ABGEDECKT |
| Seed bleibt idempotent | Slice 02 (WP option flag + per-product `get_page_by_path` check) | ABGEDECKT |
| Alle Produkte bekommen `_spreadconnect_product_id` (Demo-ID-Format) | Slice 01 (Format definiert) + Slice 02 (`update_meta_data('_spreadconnect_product_id')`) | ABGEDECKT |
| Preise in realistischen Bereichen pro Kategorie | Slice 01 (Preisbereiche spezifiziert und validiert) | ABGEDECKT |
| Bild-Dateien nicht vorhanden beim Seed: Graceful Degradation | Slice 05 (`file_exists()` check + `WP_CLI::warning` + kein Abbruch) | ABGEDECKT |
| Kategorie-Bilder (1 pro Kategorie aus Replicate) | Slice 04 (`buildCategoryPrompt()` + 12 Kategorie-Tasks) + Slice 05 (`pod_import_category_image()`) | ABGEDECKT |

### Data Fields Coverage

| Field | Required | Covered In | Status |
|-------|----------|------------|--------|
| `product.name` (DE/EN kreativ gemischt) | Yes | Slice 01 | ABGEDECKT |
| `product.slug` (kebab-case, eindeutig) | Yes | Slice 01 | ABGEDECKT |
| `product.description` (HTML, DE) | Yes | Slice 01 | ABGEDECKT |
| `product.short_description` (DE) | Yes | Slice 01 | ABGEDECKT |
| `product.price` (ohne Waehrungssymbol) | Yes | Slice 01 | ABGEDECKT |
| `product.category` (Unter-Kategorie-Slug) | Yes | Slice 01 | ABGEDECKT |
| `product.type` (variable-size-color/variable-color/simple) | Yes | Slice 01 | ABGEDECKT |
| `product.spreadconnect_id` (demo-{cat-prefix}-{nnn}) | Yes | Slice 01 | ABGEDECKT |
| `product.featured` (boolean) | Yes | Slice 01 | ABGEDECKT |
| `product.motif` (Motiv-Beschreibung fuer Replicate-Prompt) | Yes | Slice 03 (definiert) + Slice 01 (integriert) | ABGEDECKT |
| `pa_groesse` Werte (S/M/L/XL/XXL) | Yes | Slice 02 (bestehend, unveraendert uebernommen) | ABGEDECKT |
| `pa_farbe` Werte (Schwarz/Weiss/Grau/Navy) | Yes | Slice 02 (bestehend + explizit in color-only Funktion) | ABGEDECKT |
| `_wp_attached_file` meta (relativer Pfad) | Yes | Slice 05 (`pod_create_attachment()`) | ABGEDECKT |
| `_wp_attachment_metadata` | Yes | Slice 05 (`wp_generate_attachment_metadata()`) | ABGEDECKT |
| `thumbnail_id` Term-Meta auf Kategorien | Yes | Slice 05 (`update_term_meta($cat_id, 'thumbnail_id', ...)`) | ABGEDECKT |
| `set_image_id()` auf Produkt (Featured Image) | Yes | Slice 05 (`pod_import_product_images()`) | ABGEDECKT |
| `set_gallery_image_ids()` auf Produkt | Yes | Slice 05 (`pod_import_product_images()`) | ABGEDECKT |
| Review Rating (commentmeta, 3-5) | Yes | Slice 02 | ABGEDECKT |
| Review Verified = '0' | Yes | Slice 02 | ABGEDECKT |

### Bild-Generierung Coverage

| Discovery Element | Covered In | Status |
|-------------------|------------|--------|
| Replicate Flux 2 Pro (`black-forest-labs/flux-2-pro`) | Slice 04 | ABGEDECKT |
| 2 Bilder pro Produkt (verschiedene Winkel) | Slice 04 (Loop `i = 1; i <= 2`) | ABGEDECKT |
| 1 Kategoriebild pro Kategorie (12 Stck.) | Slice 04 (`buildCategoryPrompt()`) | ABGEDECKT |
| Speicherort `{cat-slug}/{slug}-{1|2}.webp` | Slice 04 (Pfad-Konstruktion in `generateImage()`) | ABGEDECKT |
| `category-{slug}.webp` Kategoriebilder | Slice 04 | ABGEDECKT |
| Max 5 concurrent Replicate-Calls | Slice 04 (`MAX_CONCURRENCY = 5`, `runWithPool()`) | ABGEDECKT |
| Exponential Backoff 1s/2s/4s bei 429 | Slice 04 (`withRetry()`) | ABGEDECKT |
| Idempotenz (skip wenn Bild vorhanden) | Slice 04 (`fs.existsSync()` Guard) | ABGEDECKT |
| Prompt-Strategie pro Kategorie (Lifestyle, Crop, Kamera-Parameter) | Slice 04 (12 CATEGORY_TEMPLATES) | ABGEDECKT |
| Motiv/Design pro Produkt (Slice 03) fliesst in Prompt ein | Slice 04 (`buildPrompt(product, template, imageIndex)`) | ABGEDECKT |
| `REPLICATE_API_KEY` / `REPLICATE_API_TOKEN` in `.env` | Slice 04 (ENV-Validation + `.env.example`) | ABGEDECKT |

**Discovery Coverage: 100% (alle Discovery-Elemente abgedeckt)**

---

## Summary

| Metric | Value |
|--------|-------|
| Total Slices | 5 |
| All Slices APPROVED | Yes |
| Total Connections | 7 |
| Valid Connections | 7 |
| Orphaned Outputs | 0 |
| Missing Inputs | 0 |
| Deliverable-Consumer Gaps | 0 |
| Discovery Coverage | 100% |

**VERDICT: READY FOR ORCHESTRATION**
