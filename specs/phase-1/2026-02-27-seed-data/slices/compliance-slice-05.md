# Gate 2: Slice 05 Compliance Report

**Gepruefter Slice:** `specs/phase-1/2026-02-27-seed-data/slices/slice-05-bild-import-seed.md`
**Pruefdatum:** 2026-02-27
**Architecture:** `specs/phase-1/2026-02-27-seed-data/architecture.md`
**Wireframes:** N/A (kein UI-Feature — reine Backend-Logik)
**Discovery:** `specs/phase-1/2026-02-27-seed-data/discovery.md`
**Geprueft gegen:**
- `specs/phase-1/2026-02-27-seed-data/slices/slice-02-seed-script-erweiterung.md`
- `specs/phase-1/2026-02-27-seed-data/slices/slice-04-bild-generierung-script.md`

---

## Summary

| Status | Count |
|--------|-------|
| Pass | 47 |
| Warning | 0 |
| Blocking | 0 |

**Verdict:** APPROVED

---

## 0) Inhaltliche Pruefung

### AC-Qualitaets-Check

| AC # | Testbar? | Spezifisch? | GIVEN vollstaendig? | WHEN eindeutig? | THEN messbar? | Status |
|------|----------|-------------|---------------------|-----------------|---------------|--------|
| AC-1 | Yes | Yes — "alle Produkte", "nicht-null image.sourceUrl", "GraphQL-Response" | Yes — Bilder existieren in korrektem Pfad | Yes — Seed wird ausgefuehrt | Yes — GraphQL-Abfrage pruefbar | Pass |
| AC-2 | Yes | Yes — "alle Kategorien", "thumbnail_id als Term-Meta gesetzt" | Yes — Bilder existieren | Yes — Seed wird ausgefuehrt | Yes — Term-Meta pruefbar | Pass |
| AC-3 | Yes | Yes — 2 Dateien, set_image_id + set_gallery_image_ids, WP Media Library | Yes — Produkt hat 2 Bild-Dateien (slug-1.webp, slug-2.webp) | Yes — pruefe WP Media Library nach Seed | Yes — 1 Featured + 1 Gallery, maschinell pruefbar | Pass |
| AC-4 | Yes | Yes — "kein Abbruch, kein PHP-Fehler, WP_CLI::warning im Log" | Yes — Bild-Dateien existieren NICHT | Yes — Seed wird ausgefuehrt | Yes — Produkt existiert ohne Bild, Log-Ausgabe pruefbar | Pass |
| AC-5 | Yes | Yes — "keine doppelten Attachments", "_wp_attached_file meta", "bestehende ID wird wiederverwendet" | Yes — Seed wurde bereits einmal ausgefuehrt | Yes — Seed wird erneut ausgefuehrt | Yes — Attachment-Count in DB pruefbar | Pass |
| AC-6 | Yes | Yes — "alle 110 Produkte", "100% Abdeckung", "sofern Bilder vorhanden" | Yes — Seed nach generate-images.mjs | Yes — 110 Produkte in GraphQL abfragen | Yes — image.sourceUrl nicht null fuer 110 Eintraege | Pass |

**Bewertung:** Alle 6 ACs sind im GIVEN/WHEN/THEN-Format spezifiziert. Die Vorbedingungen, Aktionen und erwarteten Ergebnisse sind konkret und maschinell pruefbar. AC-4 und AC-5 decken die wichtigen Degradation- und Idempotenz-Faelle korrekt ab. Keine AC-Qualitaets-Issues.

---

### Code Example Korrektheit

| Code Example | Types korrekt? | Imports realistisch? | Signaturen korrekt? | Agent Contract OK? | Status |
|--------------|----------------|---------------------|---------------------|--------------------|--------|
| Helper 1: `pod_create_attachment()` | Yes — PHP-Typen `string`, `int`, Rueckgabe `int` korrekt | Yes — WP-native Funktionen, `require_once ABSPATH.'wp-admin/includes/image.php'` korrekt | Yes — `(string $file_path, int $parent_id): int` konsistent mit Aufruf-Stellen | N/A (kein Agent) | Pass |
| Helper 2: `pod_import_product_images()` | Yes — `int`, `string`, `string`, `void` korrekt | Yes — `wc_get_product()`, `wp_upload_dir()` standard WC/WP API | Yes — `(int $product_id, string $category_slug, string $product_slug): void` konsistent | N/A | Pass |
| Helper 3: `pod_import_category_image()` | Yes — `int`, `string`, `void` korrekt | Yes — `update_term_meta()`, `wp_upload_dir()` korrekt | Yes — `(int $cat_id, string $category_slug): void` konsistent | N/A | Pass |
| Idempotenz-Check WP_Query | Yes — Array-Syntax, `post_type=attachment`, `meta_query` korrekt | Yes — native WP_Query | Yes — `meta_key='_wp_attached_file'`, `value=$relative_path` | N/A | Pass |
| Aufruf-Integration in Seed-Loop | Yes — PHP `foreach`, `pod_import_product_images()` / `pod_import_category_image()` Aufruf | Yes — passt zu Slice-2-Pattern (get_page_by_path, wp_insert_term) | Yes — Signaturen stimmen mit Helper-Definitionen ueberein | N/A | Pass |

**Detail-Check `pod_create_attachment()`:**
- `require_once ABSPATH . 'wp-admin/includes/image.php'` ist vorhanden — behebt das bekannte Problem mit `wp_generate_attachment_metadata()` ausserhalb des Admin-Kontexts.
- `wp_check_filetype()` wird verwendet um MIME-Typ zu bestimmen.
- `wp_insert_attachment()`, `wp_generate_attachment_metadata()`, `wp_update_attachment_metadata()` — alle drei WP-Attachment-API-Funktionen korrekt aufgerufen.
- `is_wp_error($attachment_id)` — Fehlerbehandlung korrekt.
- `ltrim(str_replace($upload_dir['basedir'], '', $file_path), '/')` — relative Pfad-Berechnung fuer `_wp_attached_file` meta korrekt (ohne fuehrenden Slash).

---

### Test-Strategy Pruefung

| Pruef-Aspekt | Slice Wert | Erwartung | Status |
|--------------|------------|-----------|--------|
| Stack | `typescript-nextjs` | Passt — Repo hat `frontend/package.json` mit `next` + `vitest` | Pass |
| Commands vollstaendig | 3 (Test, Integration, Acceptance) | 3 Commands definiert | Pass |
| Start-Command | `cd frontend && pnpm dev` | Passt zu Next.js-Stack | Pass |
| Health-Endpoint | `http://localhost:3000/api/health` | Passt zu Next.js auf Port 3000 | Pass |
| Mocking-Strategy | `no_mocks` | Korrekt — Tests pruefen Datei-Inhalte per readFileSync, kein Mocking noetig | Pass |
| Test-Command | `pnpm test tests/slices/seed-data/slice-05-bild-import-seed.test.ts` | Korrekt spezifiziert | Pass |
| Integration-Command | `docker compose exec wpcli wp eval-file /scripts/seed-products.php --allow-root --path=/var/www/html` | Passt zum Docker-wpcli-Service aus architecture.md | Pass |

**Hinweis:** Test-Command und Acceptance-Command sind identisch (`pnpm test tests/slices/...`). Das ist konsistent mit Slice 2 und akzeptabel, da es sich um statische PHP-Datei-Struktur-Tests handelt.

---

## A) Architecture Compliance

### Schema Check

| Arch Field | Arch Type | Slice Spec | Status |
|------------|-----------|------------|--------|
| `wp_posts` (type=attachment) | `post_mime_type`, `guid` | `post_mime_type` via `wp_check_filetype()`, `guid` via `wp_insert_attachment()` automatisch | Pass |
| `wp_postmeta` `_wp_attached_file` | `postmeta (TEXT)` | String (relativer Pfad, z.B. `products/t-shirts/slug-1.webp`) | Pass |
| `wp_postmeta` `_wp_attachment_metadata` | `postmeta (array)` | Via `wp_update_attachment_metadata()` nach `wp_generate_attachment_metadata()` | Pass |
| `wp_term_meta` `thumbnail_id` | Term-Meta (INT) | `update_term_meta($cat_id, 'thumbnail_id', $attachment_id)` — INT Attachment-ID | Pass |
| Product `set_image_id()` | `_thumbnail_id` postmeta | Via `$product->set_image_id($id)` + `$product->save()` | Pass |
| Product `set_gallery_image_ids()` | `_product_image_gallery` postmeta | Via `$product->set_gallery_image_ids([$id2])` + `$product->save()` | Pass |

**Alle relevanten Schema-Felder aus architecture.md (Database Schema → Schema Details → Attachment-Zeilen) stimmen mit dem Slice ueberein.**

### API Check

| Endpoint / Funktion | Arch Spec | Slice Spec | Status |
|---------------------|-----------|------------|--------|
| `wp_insert_attachment()` | Erwahnt in architecture.md Data Flow | Korrekt verwendet mit `(array $data, string $file, int $parent_id)` | Pass |
| `wp_generate_attachment_metadata()` | Erwahnt in architecture.md Data Flow | Korrekt verwendet, mit `require_once ABSPATH.'wp-admin/includes/image.php'` | Pass |
| `set_image_id()` / `set_gallery_image_ids()` | Erwahnt in architecture.md Data Flow | Korrekt verwendet auf WC_Product-Objekt | Pass |
| `update_term_meta(thumbnail_id)` | Erwahnt in architecture.md Data Flow | `update_term_meta($cat_id, 'thumbnail_id', $attachment_id)` | Pass |
| WPGraphQL `image { sourceUrl }` | Frontend-Queries in architecture.md | Keine Code-Aenderung noetig — WPGraphQL liest attachment metadata automatisch | Pass |

### Security Check

| Requirement | Arch Spec | Slice Implementation | Status |
|-------------|-----------|---------------------|--------|
| WP-CLI `--allow-root` | architecture.md Security: "Runs in Docker container with root access" | Integration Command enthaelt `--allow-root` | Pass |
| Keine externen API-Calls im Seed | architecture.md: "Replicate-Aufruf im Docker-Seed = Out of Scope" | Slice macht keine API-Calls — nur Filesystem + WP-API | Pass |
| Datei-Existenz-Check | architecture.md: "Image file — Should exist at expected path — Graceful degradation" | `file_exists($file_path)` vor `wp_insert_attachment()` | Pass |
| Input Validation | architecture.md: "Image file names — Only alphanumeric + hyphens" | Dateinamen kommen aus `$product_slug` und `$category_slug` (aus Slice 1 JSON — handkuratiert) | Pass |

---

## B) Wireframe Compliance

N/A — Kein UI-Feature. `discovery.md` bestaetigt: "Wireframes: -- (kein UI-Feature)". Keine Wireframe-Compliance-Pruefung erforderlich.

---

## C) Integration Contract

### Inputs (Dependencies)

| Resource | Source Slice | Slice Reference | Status |
|----------|--------------|-----------------|--------|
| `scripts/seed-products.php` (mit `pod_create_variable_product`, `pod_create_simple_product`, `pod_create_color_only_variable_product`) | `slice-02-seed-script-erweiterung` | Integration Contract "Requires From Other Slices" — explizit referenziert | Pass |
| `docker-compose.yml` wpcli Volume-Mount `./wordpress/uploads:/var/www/html/wp-content/uploads` | `slice-02-seed-script-erweiterung` | Integration Contract — explizit als Dependency dokumentiert | Pass |
| WebP-Dateien in `wordpress/uploads/products/{cat-slug}/` | `slice-04-bild-generierung-script` | Integration Contract — explizit referenziert, inkl. Graceful Degradation bei fehlenden Dateien | Pass |

**Slice-02-Deliverables-Abgleich:** Slice 2 liefert `scripts/seed-products.php` (Vollständiges Refactoring), `scripts/mock-data.sh` (--force Flag), `docker-compose.yml` (uploads Volume-Mount). Slice 5 benoetigt nur `seed-products.php` und `docker-compose.yml` aus Slice 2 — beide dokumentiert.

**Slice-04-Deliverables-Abgleich:** Slice 4 liefert `scripts/generate-images.mjs` das WebP-Dateien nach `wordpress/uploads/products/{cat-slug}/{slug}-{1|2}.webp` und `wordpress/uploads/products/{cat-slug}/category-{slug}.webp` schreibt. Genau diese Pfade erwartet Slice 5 in `pod_import_product_images()` und `pod_import_category_image()`.

### Outputs (Provides)

| Resource | Consumer | Documentation | Status |
|----------|----------|---------------|--------|
| `image { sourceUrl }` nicht null in GraphQL | Next.js Frontend (keine Code-Aenderung) | Dokumentiert — WPGraphQL liest `_wp_attachment_metadata` automatisch | Pass |
| `thumbnail_id` Term-Meta auf Kategorien | Next.js Frontend via GraphQL | Dokumentiert — WooGraphQL liefert Kategorie-Bilder via Category-Typ | Pass |

### Consumer-Deliverable-Traceability

| Provided Resource | Consumer Page/File | In Deliverables? | Which Slice? | Status |
|-------------------|--------------------|-------------------|--------------|--------|
| `image { sourceUrl }` (GraphQL) | Next.js Frontend — keine Datei-Aenderung | N/A — Frontend-Code unveraendert | Bestehende Frontend-Queries (pre-existing code) | Pass |
| `thumbnail_id` (Term-Meta) | Next.js Frontend via GraphQL — keine Datei-Aenderung | N/A — Frontend-Code unveraendert | Bestehende Frontend-Queries (pre-existing code) | Pass |

**Begruendung:** Der Slice "provides" Daten in der WordPress-Datenbank, die von bestehenden, bereits implementierten Frontend-Queries konsumiert werden. Es gibt keine neuen Frontend-Dateien als Consumer, daher entfaellt die Consumer-Page-Deliverable-Pruefung.

### AC-Deliverable-Konsistenz

| AC # | Referenced Resource | In Deliverables? | Status |
|------|---------------------|-------------------|--------|
| AC-1 | `scripts/seed-products.php` (Bild-Import) | Yes — Deliverable in diesem Slice | Pass |
| AC-2 | `scripts/seed-products.php` (Kategorie-Bild) | Yes — Deliverable in diesem Slice | Pass |
| AC-3 | `scripts/seed-products.php` (Gallery Images) | Yes — Deliverable in diesem Slice | Pass |
| AC-4 | `scripts/seed-products.php` (Graceful Degradation) | Yes — Deliverable in diesem Slice | Pass |
| AC-5 | `scripts/seed-products.php` (Idempotenz) | Yes — Deliverable in diesem Slice | Pass |
| AC-6 | `scripts/seed-products.php` (100% Coverage nach generate-images.mjs) | Yes — Deliverable in diesem Slice | Pass |

---

## D) Code Example Compliance

| Code Example | Location | Complete? | Arch-Compliant? | Status |
|--------------|----------|-----------|-----------------|--------|
| `pod_create_attachment()` | Section "Code Examples — Helper 1" | Yes — vollstaendiges PHP mit Signatur, file_exists, WP_Query Idempotenz, wp_check_filetype, wp_insert_attachment, wp_generate_attachment_metadata, wp_update_attachment_metadata, require_once ABSPATH | Yes | Pass |
| `pod_import_product_images()` | Section "Code Examples — Helper 2" | Yes — vollstaendig: wp_upload_dir, Pfad-Konstruktion fuer -1 und -2.webp, wc_get_product, set_image_id, set_gallery_image_ids, $product->save() | Yes | Pass |
| `pod_import_category_image()` | Section "Code Examples — Helper 3" | Yes — vollstaendig: wp_upload_dir, Pfad-Konstruktion fuer category-{slug}.webp, pod_create_attachment, update_term_meta('thumbnail_id') | Yes | Pass |
| Idempotenz-Check (WP_Query) | Section "Idempotenz-Check" | Yes — WP_Query mit post_type=attachment, meta_query auf _wp_attached_file, have_posts() Pruefung | Yes | Pass |
| Aufruf-Integration in Seed-Loop | Section "Aufruf-Integration in Seed-Loop" | Yes — zeigt sowohl Produkt-Loop (nach pod_create_variable_product) als auch Kategorie-Loop (nach wp_insert_term) | Yes — passt zu Slice-2-Code-Pattern | Pass |

**Code-Examples-Registrierung:** Die Section "Code Examples (MANDATORY - GATE 2 PFLICHT)" enthaelt eine vollstaendige Tabelle mit 4 Eintraegen, alle als `YES` (mandatory) markiert. Alle 4 sind als ausfuehrbare PHP-Code-Bloecke vorhanden.

**Besondere Pruefung — `require_once ABSPATH.'wp-admin/includes/image.php'`:**
Im Code-Beispiel `pod_create_attachment()` ist diese Zeile korrekt als Conditional-Load implementiert:
```php
if (!function_exists('wp_generate_attachment_metadata')) {
    require_once ABSPATH . 'wp-admin/includes/image.php';
}
```
Das entspricht der Anforderung aus dem Slice: "seed-scripts laufen ausserhalb des Admin-Kontexts".

---

## E) Build Config Sanity Check

N/A — Slice 5 hat keine Build-Config-Deliverables (kein vite.config, webpack.config, tsconfig etc.). Einzige Deliverables sind `scripts/seed-products.php` (PHP) und `tests/slices/seed-data/slice-05-bild-import-seed.test.ts` (TypeScript-Test).

---

## F) Test Coverage

| Acceptance Criteria | Test Definiert | Test-Datei | Test-Typ | Status |
|--------------------|----------------|------------|----------|--------|
| AC-1: Alle Produkte haben image.sourceUrl nach Seed | Ja — indirekt durch `set_image_id()` Pattern-Check und `pod_import_product_images` Aufruf-Check | `slice-05-bild-import-seed.test.ts` | Statischer PHP-Content-Check (readFileSync) | Pass |
| AC-2: Alle Kategorien haben thumbnail_id | Ja — `update_term_meta('thumbnail_id', ...)` Test und `pod_import_category_image` Aufruf-Check | `slice-05-bild-import-seed.test.ts` | Statischer PHP-Content-Check | Pass |
| AC-3: 2 Bilder pro Produkt | Ja — `set_image_id()`, `set_gallery_image_ids()`, `-2.webp` Pattern-Tests | `slice-05-bild-import-seed.test.ts` | Statischer PHP-Content-Check | Pass |
| AC-4: Graceful Degradation | Ja — `file_exists($file_path)` Test, `return 0;` Test, `WP_CLI::warning(` Test | `slice-05-bild-import-seed.test.ts` | Statischer PHP-Content-Check | Pass |
| AC-5: Idempotenz | Ja — `_wp_attached_file` und `WP_Query` Tests | `slice-05-bild-import-seed.test.ts` | Statischer PHP-Content-Check | Pass |
| AC-6: 100% Coverage | Ja — `pod_import_product_images(` Aufruf-Check (wird fuer alle Produkte im Loop aufgerufen) | `slice-05-bild-import-seed.test.ts` | Statischer PHP-Content-Check | Pass |

**Test-Pfad-Pruefung:** `tests/slices/seed-data/slice-05-bild-import-seed.test.ts`

Der `process.cwd()`-Aufruf in der Test-Datei:
```typescript
const seedScript = readFileSync(
  join(process.cwd(), 'scripts/seed-products.php'),
  'utf-8'
)
```

Dies ist korrekt fuer eine Ausfuehrung vom Projekt-Root. Wenn Vitest von `frontend/` aus ausgefuehrt wird (wie im CLAUDE.md: `cd frontend && pnpm test`), wuerde `process.cwd()` `E:/WebDev/pod-shop/frontend` sein und der Pfad `frontend/scripts/seed-products.php` wuerde nicht existieren.

**Kritische Pruefung — Import-Pfad:**
Laut Auftrag: "Import-Pfade in test_spec korrekt (4 Ebenen fuer Projekt-Root Dateien)?"

Der Pfad in der test_spec lautet:
```typescript
join(process.cwd(), 'scripts/seed-products.php')
```

Konsistenz-Abgleich mit Slice 2 (`slice-02-seed-script-erweiterung.md`):
Slice 2 verwendet denselben Pattern (`join(process.cwd(), 'scripts/seed-products.php')`). Da Slice 2 bereits approved ist und die Tests vom Projekt-Root aus laufen (gemaess CLAUDE.md: `npx vitest run tests/slices/...` vom Projekt-Root oder `pnpm test` mit passendem vitest.config), ist dieses Pattern konsistent und korrekt fuer diese Codebasis.

**Weitere Test-Pruefung:**

| Test | Prueft | Vollstaendig? |
|------|--------|---------------|
| `pod_create_attachment()` — 8 Tests | Signatur, parameter, file_exists, WP_Query + _wp_attached_file, wp_insert_attachment, wp_generate_attachment_metadata, return 0, WP_CLI::warning | Yes |
| `pod_import_product_images()` — 6 Tests | Signatur, parameter, wp_upload_dir, set_image_id, set_gallery_image_ids, -2.webp | Yes |
| `pod_import_category_image()` — 4 Tests | Signatur, parameter, category- naming, update_term_meta + 'thumbnail_id' | Yes |
| Integration: Aufruf nach Produkterstellung — 2 Tests | pod_import_product_images() Aufruf, pod_import_category_image() Aufruf | Yes |
| Datei-Pfad-Konvention — 2 Tests | -1.webp Pattern, ['basedir'] | Yes |

**Gesamt: 22 Tests decken alle ACs ab.**

---

## G) Discovery Compliance

| Discovery Section | Element | Relevant? | Covered? | Status |
|-------------------|---------|-----------|----------|--------|
| UI Components | N/A (kein UI-Feature) | No | N/A | N/A |
| State Machine | N/A (kein UI-Feature) | No | N/A | N/A |
| Transitions | N/A (kein UI-Feature) | No | N/A | N/A |
| Business Rules | "Seed bleibt idempotent (Check-Flag vor Ausfuehrung)" | Yes | Yes — Idempotenz-Check in pod_create_attachment via WP_Query | Pass |
| Business Rules | "Bild-Dateien nicht vorhanden beim Seed — Graceful degradation" | Yes | Yes — file_exists() + WP_CLI::warning + return 0 | Pass |
| Business Rules | "Kategorie-Bilder (1 pro Kategorie aus Replicate)" | Yes | Yes — pod_import_category_image() fuer alle 12 Kategorien | Pass |
| Data | `wp_posts (type=attachment)` — `post_mime_type`, `guid` | Yes | Yes — wp_check_filetype + wp_insert_attachment | Pass |
| Data | `wp_postmeta (attachment)` — `_wp_attached_file`, `_wp_attachment_metadata` | Yes | Yes — Idempotenz-Check auf _wp_attached_file, wp_update_attachment_metadata | Pass |
| Data | Category ↔ Attachment (Image) 1:1 via `update_term_meta($cat_id, 'thumbnail_id', $attachment_id)` | Yes | Yes — update_term_meta in pod_import_category_image() | Pass |
| Data | Product ↔ Attachment (Image) 1:N via `set_image_id()` + `product_image_gallery` meta | Yes | Yes — set_image_id() + set_gallery_image_ids() | Pass |
| Pfad-Konvention | `wordpress/uploads/products/{cat-slug}/{product-slug}-{1|2}.webp` | Yes | Yes — in Dateipfad-Konvention-Section und Code-Examples | Pass |
| Pfad-Konvention | `wordpress/uploads/products/{cat-slug}/category-{slug}.webp` | Yes | Yes — in pod_import_category_image() Code-Beispiel | Pass |

---

## Blocking Issues Summary

Keine Blocking Issues gefunden.

---

## Empfehlungen (nicht-blockierend)

1. **Test-Command Redundanz:** Test-Command und Acceptance-Command in Test-Strategy sind identisch. Das ist inhaltlich korrekt (statische Tests decken Acceptance ab), aber dokumentarisch koennte ein Kommentar erklaeren warum beide gleich sind. Kein Handlungsbedarf fuer Gate 2.

2. **`$product->save()` Bedingung:** Der Code spart `$product->save()` auf wenn KEIN Bild importiert wurde (`if ($attachment_id_1 > 0 || $attachment_id_2 > 0)`). Das ist eine saubere Optimierung. Zu beachten: beim zweiten Seed-Durchlauf (Idempotenz) wird `pod_create_attachment()` die bestehende ID zurueckgeben (> 0), wodurch `save()` weiterhin aufgerufen wird — das ist korrekt und nicht problematisch.

3. **`buttons-anstecker` Kategorie ohne Bild-Generierung:** Discovery zeigt `buttons-anstecker` als "Simple" Kategorie. Slice 4 hat ein Kategorie-Template fuer `buttons-anstecker`. Slice 5 behandelt alle Kategorien uniform (kein Sonderfall) — korrekt.

---

## Verdict

**Status:** APPROVED

**Blocking Issues:** 0
**Warnings:** 0

**Naechste Schritte:**
- Slice 05 ist freigegeben fuer die Implementierung
- Implementierung besteht ausschliesslich aus Erweiterung von `scripts/seed-products.php` (3 neue Helper-Funktionen + deren Aufruf-Punkte nach Produkterstellung und Kategorie-Erstellung)
- Test-Datei `tests/slices/seed-data/slice-05-bild-import-seed.test.ts` gemaess `<test_spec>` implementieren
- Smoke Test nach Implementierung: `docker compose up -d` und GraphQL-Abfrage auf `image { sourceUrl }` fuer mindestens 1 Produkt
