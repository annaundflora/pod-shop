# E2E Checklist: Seed Data — 100+ POD-Produkte mit KI-generierten Bildern

**Integration Map:** `integration-map.md`
**Generated:** 2026-02-27

---

## Pre-Conditions

- [ ] All slices APPROVED (Gate 2) — 5/5 APPROVED
- [ ] Architecture APPROVED (Gate 1)
- [ ] Integration Map: Missing Inputs == 0 (verified: 0 missing inputs)
- [ ] `scripts/product-catalog.json` existiert und parst ohne Fehler
- [ ] `scripts/generate-images.mjs` existiert und importiert ohne Fehler
- [ ] `scripts/seed-products.php` existiert und enthaelt alle Helper-Funktionen
- [ ] Docker-Umgebung laeuft (`docker compose up -d` erfolgreich)

---

## Phase 1: Produktkatalog-Definition (Slice 01)

### Unit-Tests

- [ ] `cd frontend && pnpm test tests/slices/seed-data/slice-01-produktkatalog-definition.test.ts` — alle Tests gruen
- [ ] JSON-Datei parst ohne Fehler: `node -e "require('./scripts/product-catalog.json')"`
- [ ] Exakt 110 Produkte vorhanden (zaehlen: `jq '.products | length' scripts/product-catalog.json`)
- [ ] Exakt 15 Kategorien vorhanden (3 Parent + 12 Child): `jq '.categories | length' scripts/product-catalog.json`
- [ ] 8-12 Featured-Produkte vorhanden
- [ ] Alle Slugs sind kebab-case und eindeutig
- [ ] Alle `spreadconnect_id` folgen Pattern `demo-{cat-prefix}-{nnn}` und sind eindeutig
- [ ] Jedes Produkt hat `motif`-Feld (auch als leerer String akzeptabel fuer Test-Phase)
- [ ] Alle Produkte haben korrekte Produkttypen (variable-size-color/variable-color/simple)
- [ ] Preise liegen in den kategoriespezifischen Bereichen

### Produktverteilung

- [ ] T-Shirts: exakt 20 Produkte
- [ ] Hoodies: exakt 12 Produkte
- [ ] Sweatshirts: exakt 10 Produkte
- [ ] Tanktops: exakt 8 Produkte
- [ ] Langarmshirts: exakt 8 Produkte
- [ ] Taschen: exakt 10 Produkte
- [ ] Muetzen & Caps: exakt 8 Produkte
- [ ] Buttons & Anstecker: exakt 0 Produkte (Kategorie vorhanden, reserviert)
- [ ] Tassen: exakt 10 Produkte
- [ ] Poster & Kunstdrucke: exakt 10 Produkte
- [ ] Kissen: exakt 6 Produkte
- [ ] Handyhuellen: exakt 8 Produkte

---

## Phase 2: Motiv-Definition (Slice 03)

### Unit-Tests

- [ ] `cd frontend && pnpm test tests/slices/seed-data/slice-03-motiv-definition.test.ts` — alle Tests gruen
- [ ] EXPECTED_COUNTS-Summe ergibt exakt 110
- [ ] Alle 9 Motiv-Typen aus Discovery sind in REQUIRED_MOTIF_TYPES vorhanden
- [ ] 8 Muetzen-Slugs vorhanden (entspricht Slice 01 Muetzen-Verteilung)
- [ ] 10 Poster-Slugs vorhanden (entspricht Slice 01 Poster-Verteilung)

### Motiv-Qualitaet (manuell, nach Integration in product-catalog.json)

- [ ] Alle 110 Produkte haben nicht-leere `motif`-Felder in `product-catalog.json`
- [ ] Alle 8 Muetzen-Motive enthalten "embroidery-ready"
- [ ] Alle 8 Muetzen-Motive enthalten "minimum 2mm line width" (oder staerker)
- [ ] Alle 8 Muetzen-Motive haben max 3 Elemente
- [ ] Alle 10 Poster-Motive sind als "standalone art print" markiert
- [ ] Alle 9 Motiv-Typen (Line-Art, Cute Characters, Retro-Schriftzug, Flat Vector, Botanisch, Typografie, Celestial, Food & Drinks, Cottage/Cozy) sind vertreten
- [ ] Kein `motif`-Feld ist laenger als 400 Zeichen (Prompt-Laengen-Budget)

---

## Phase 3: Seed-Script Erweiterung (Slice 02)

### Unit-Tests

- [ ] `cd frontend && pnpm test tests/slices/seed-data/slice-02-seed-script-erweiterung.test.ts` — alle Tests gruen
- [ ] `scripts/seed-products.php` enthaelt `pod_create_simple_product()`
- [ ] `scripts/seed-products.php` enthaelt `pod_create_color_only_variable_product()` (nur `pa_farbe`, kein `pa_groesse`)
- [ ] `scripts/seed-products.php` enthaelt JSON-Catalog-Loading via `json_decode(file_get_contents())`
- [ ] `scripts/seed-products.php` enthaelt Review-Erstellungslogik
- [ ] `scripts/mock-data.sh` enthaelt `--force` Flag und `wp option delete pod_shop_mock_data_seeded`
- [ ] `docker-compose.yml` enthaelt Volume-Mount `./wordpress/uploads:/var/www/html/wp-content/uploads` fuer wpcli-Service

### Integration-Test (erfordert laufende Docker-Umgebung)

- [ ] `docker compose exec wpcli wp eval-file /scripts/seed-products.php --allow-root --path=/var/www/html` — kein Fehler, kein PHP-Error
- [ ] Exakt 110 Produkte in WooCommerce: GraphQL `{ products { nodes { id } } }` → `nodes.length === 110`
- [ ] 3 Parent-Kategorien existieren (Kleidung, Accessoires, Wohnen & Geschenke)
- [ ] 12 Unter-Kategorien existieren mit korrekten parent-Zuordnungen
- [ ] Featured-Produkte erscheinen in GraphQL mit `featured: true` Filter
- [ ] Mind. ein Featured-Produkt hat 3-5 Reviews (GraphQL: `reviews { nodes { ... } }`)

### Idempotenz-Test

- [ ] Seed erneut ausfuehren (ohne `--force`) → exakt 110 Produkte (keine Duplikate)
- [ ] `--force` Flag: `scripts/mock-data.sh --force` loescht Flag und fuehrt Seed neu aus
- [ ] Seed ohne Bild-Dateien: alle 110 Produkte werden erstellt, kein Abbruch, kein PHP-Fehler

---

## Phase 4: Bild-Generierung Script (Slice 04)

**Hinweis: Slice 04 ist ein developer-triggered one-time script — kein automatischer Build-Step.**

### Unit-Tests

- [ ] `cd frontend && pnpm test tests/slices/seed-data/slice-04-bild-generierung-script.test.ts` — alle Tests gruen
- [ ] `buildPrompt()` enthaelt `product.motif` als Teilstring im generierten Prompt
- [ ] `buildPrompt()` generiert Prompt <= 2000 Zeichen
- [ ] `withRetry()` retried bei HTTP 429 mit Delays 1s/2s/4s
- [ ] `withRetry()` retried NICHT bei nicht-429-Fehlern
- [ ] `CATEGORY_TEMPLATES` hat exakt 12 Eintraege (alle Kategorien inkl. buttons-anstecker)
- [ ] `buildCategoryPrompt()` generiert kategorie-spezifische Prompts
- [ ] `runWithPool()` begrenzt Concurrency korrekt

### Script-Validation (lokale Ausfuehrung, erfordert REPLICATE_API_TOKEN)

- [ ] `REPLICATE_API_TOKEN` nicht gesetzt → Script bricht mit Exit-Code 1 ab und gibt Fehlermeldung aus
- [ ] Script erstellt Verzeichnisstruktur `wordpress/uploads/products/{cat-slug}/` (mkdir -p, kein Fehler bei Existenz)
- [ ] Idempotenz: Script ueberspringt Bilder die bereits existieren ("skipped" Counter)
- [ ] Summary-Ausgabe am Ende: Format "Summary: X generated, Y skipped, Z failed"

### Datei-Validierung (nach tatsaechlicher Ausfuehrung)

- [ ] Exakt 220 Produktbild-Dateien generiert (`{slug}-1.webp` + `{slug}-2.webp` pro Produkt)
- [ ] Exakt 12 Kategoriebild-Dateien generiert (`category-{slug}.webp` pro Kategorie)
- [ ] Alle Dateien sind valide WebP-Dateien (kein 0-Byte-Output)
- [ ] Dateipfade folgen Konvention: `wordpress/uploads/products/{cat-slug}/{product-slug}-{1|2}.webp`
- [ ] Kategorie-Bilder folgen Konvention: `wordpress/uploads/products/{cat-slug}/category-{cat-slug}.webp`

---

## Phase 5: Bild-Import im Seed (Slice 05)

### Unit-Tests

- [ ] `cd frontend && pnpm test tests/slices/seed-data/slice-05-bild-import-seed.test.ts` — alle Tests gruen
- [ ] `scripts/seed-products.php` enthaelt `function pod_create_attachment(`
- [ ] `scripts/seed-products.php` enthaelt `function pod_import_product_images(`
- [ ] `scripts/seed-products.php` enthaelt `function pod_import_category_image(`
- [ ] `scripts/seed-products.php` enthaelt `_wp_attached_file` (Idempotenz-Check)
- [ ] `scripts/seed-products.php` enthaelt `WP_Query` (Idempotenz-Check)
- [ ] `scripts/seed-products.php` enthaelt `set_image_id(`
- [ ] `scripts/seed-products.php` enthaelt `set_gallery_image_ids(`
- [ ] `scripts/seed-products.php` enthaelt `update_term_meta(` mit `'thumbnail_id'`
- [ ] `scripts/seed-products.php` enthaelt `wp_insert_attachment(`
- [ ] `scripts/seed-products.php` enthaelt `wp_generate_attachment_metadata(`
- [ ] `scripts/seed-products.php` enthaelt `file_exists($file_path)` (Graceful Degradation)
- [ ] `scripts/seed-products.php` enthaelt `return 0;` (Fehler-Pfad)
- [ ] `scripts/seed-products.php` enthaelt `WP_CLI::warning(` (Logging bei fehlenden Dateien)
- [ ] `scripts/seed-products.php` enthaelt `pod_import_product_images(` (Aufruf nach Produkterstellung)
- [ ] `scripts/seed-products.php` enthaelt `pod_import_category_image(` (Aufruf nach Kategorie-Erstellung)

### Integration-Test (erfordert Docker + generierte Bilder)

- [ ] Seed nach `generate-images.mjs` ausfuehren: Alle 110 Produkte haben `image { sourceUrl }` nicht null in GraphQL
- [ ] Alle 12 Kategorien haben `thumbnail_id` Term-Meta gesetzt
- [ ] Produkte mit 2 Bild-Dateien haben 1 Featured Image + 1 Gallery Image in WP Media Library

### Idempotenz-Test (Bild-Import)

- [ ] Seed zweimal ausfuehren: keine doppelten Attachments in WP Media Library (gleiche `_wp_attached_file` meta → bestehende ID wird wiederverwendet)

### Graceful Degradation Test

- [ ] Seed ohne Bild-Dateien ausfuehren: Produkte werden ohne Bilder erstellt, kein PHP-Fehler, WP_CLI::warning im Log

---

## Cross-Slice Integration Points

| # | Integration Point | Slices | How to Verify |
|---|-------------------|--------|---------------|
| 1 | `product-catalog.json` korrekt von `seed-products.php` gelesen | Slice 01 → Slice 02 | `docker compose exec wpcli wp eval-file` + GraphQL-Produktzaehlung == 110 |
| 2 | `motif`-Felder in JSON befuellt (nicht leer) vor `generate-images.mjs` Ausfuehrung | Slice 03 → Slice 01 → Slice 04 | `jq '.products[] | select(.motif == "") | .slug' scripts/product-catalog.json` → leere Ausgabe erwartet |
| 3 | `generate-images.mjs` liest `product.motif` korrekt in Prompts ein | Slice 01 + Slice 03 → Slice 04 | Unit-Test `buildPrompt()` enthaelt `product.motif` als Teilstring |
| 4 | WebP-Dateien von Slice 04 an korrekten Pfaden von Slice 05 gelesen | Slice 04 → Slice 05 | Dateinamen-Pattern stimmt mit `pod_import_product_images()` ueberein |
| 5 | Volume-Mount aus Slice 02 ermoeglicht `wp_insert_attachment()` in Slice 05 | Slice 02 → Slice 05 | `docker-compose.yml` wpcli-Service hat `./wordpress/uploads:/var/www/html/wp-content/uploads` |
| 6 | Frontend-Queries zeigen Bilder ohne Codeaenderungen | Slice 05 → Next.js | GraphQL `GET_FEATURED_PRODUCTS` → `image { sourceUrl }` nicht null |
| 7 | Kategorie-Navigation zeigt Bilder | Slice 05 → Next.js | GraphQL `GET_PRODUCT_CATEGORIES` → Kategorie hat `image { sourceUrl }` nicht null |

---

## End-to-End Acceptance Flow

### Flow: Vollstaendiges Developer-Setup

1. [ ] **Slice 01+03:** `scripts/product-catalog.json` vorhanden mit 110 Produkten und befuellten `motif`-Feldern
2. [ ] **Slice 04 (manuell):** `node scripts/generate-images.mjs` — generiert 232 WebP-Dateien in `wordpress/uploads/products/`
3. [ ] Developer committet generierte Bilder: `git add wordpress/uploads/products/ && git commit -m "feat: add generated product images"`
4. [ ] **Slice 02+05:** `docker compose up -d` startet — `setup.sh → mock-data.sh → seed-products.php` laeuft automatisch
5. [ ] WooCommerce enthaelt 110 Produkte, 15 Kategorien, ~8-12 Featured, ~40-50 Reviews
6. [ ] Alle 110 Produkte haben `image { sourceUrl }` nicht null in GraphQL
7. [ ] Alle 12 Kategorien haben `image { sourceUrl }` nicht null in GraphQL
8. [ ] Next.js Homepage zeigt Featured-Products-Section mit Produktbildern
9. [ ] Next.js Kategorie-Pages zeigen Produkte mit Bildern
10. [ ] Next.js Produkt-Detail-Page zeigt Bilder und Reviews

### Flow: Reset und Neu-Seeden

1. [ ] `scripts/mock-data.sh --force` — loescht Idempotenz-Flag, Seed laeuft neu
2. [ ] Kein PHP-Fehler, kein Docker-Crash
3. [ ] Produkte werden nicht dupliziert (per-product `get_page_by_path` check)
4. [ ] Attachments werden nicht dupliziert (`_wp_attached_file` Idempotenz-Check)

---

## Error Handling Verification

- [ ] `REPLICATE_API_TOKEN` nicht gesetzt → `generate-images.mjs` bricht mit Exit-Code 1 ab und gibt verstaendliche Fehlermeldung aus
- [ ] Replicate API gibt HTTP 429 zurueck → Script wartet 1s/2s/4s und retried (max 3 Versuche)
- [ ] Replicate Prediction schlaegt fehl (non-429) → Bild wird uebersprungen, "failed" Counter erhoehen, naechstes Bild fortsetzen
- [ ] Bild-Datei beim Seed nicht vorhanden → `WP_CLI::warning` ausgegeben, Produkt wird ohne Bild erstellt, kein PHP-Error
- [ ] Produkt existiert bereits beim Seed → `get_page_by_path` liefert bestehende ID, kein Duplikat
- [ ] Attachment existiert bereits beim Seed → `WP_Query` auf `_wp_attached_file` liefert bestehende ID, kein Duplikat

---

## Sign-Off

| Tester | Date | Result |
|--------|------|--------|
| [Name] | [Date] | PASS / FAIL |

**Notes:**
[Any observations or issues found during testing]
