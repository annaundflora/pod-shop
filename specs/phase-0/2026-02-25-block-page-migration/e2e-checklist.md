# E2E Checklist: Block-basierte Seiten-Migration

**Integration Map:** `integration-map.md`
**Generated:** 2026-02-25

---

## Pre-Conditions

- [ ] All slices APPROVED (Gate 2) — compliance-slice-01.md through compliance-slice-04.md all show VERDICT: APPROVED
- [ ] Architecture APPROVED (Gate 1) — compliance-architecture.md
- [ ] Integration Map has no MISSING INPUTS — Verified: 0 missing inputs
- [ ] `cd frontend && pnpm install` completed successfully
- [ ] `cd frontend && pnpm build` completes without TypeScript errors

---

## Happy Path Tests

### Flow 1: Homepage rendert mit neuem Sections-Format

1. [ ] **Slice 01:** `themes/default/pages/home.yaml` existiert und hat `sections:` Key (kein flaches `blocks:`)
2. [ ] **Slice 01:** `loadPageConfig('home', 'default')` gibt `PageConfig` mit `sections: SectionConfig[]` zurueck
3. [ ] **Slice 01:** `GET /` — Homepage laedt erfolgreich, `<main id="main-content">` im DOM
4. [ ] **Slice 01:** Homepage zeigt hero, product-grid, category-showcase und usp-bar Blocks (identisch zum Ist-Zustand)
5. [ ] **Slice 01:** Keine visuellen Regressionen auf der Homepage gegenueber dem Pre-Migration-Zustand

### Flow 2: Kategorie-Seite mit Block-basiertem Layout

1. [ ] **Slice 01:** `SectionRenderer` ist im DOM erreichbar via `app/kategorie/[slug]/page.tsx`
2. [ ] **Slice 02:** `GET /kategorie/t-shirts` — Seite laedt mit HTTP 200
3. [ ] **Slice 02:** `<h1>` zeigt Kategorie-Namen (`productCategory.name`) mit CSS-Klassen `text-3xl font-bold tracking-tight text-text-primary`
4. [ ] **Slice 02:** Filter-Chips sind sichtbar und die aktuelle Kategorie (`t-shirts`) ist hervorgehoben
5. [ ] **Slice 02:** Produktanzahl-Text erscheint als "N Produkte" (oder "1 Produkt" bei Singular, "Keine Produkte" bei 0)
6. [ ] **Slice 02:** Produkt-Grid zeigt Produktkarten an (2/2/3/4-Spalten-Layout)
7. [ ] **Slice 02:** Kein hardcoded h1, FilterChips oder Grid-JSX in `app/kategorie/[slug]/page.tsx` — alles via `SectionRenderer`
8. [ ] **Slice 02:** `resolveBlock('page-heading')` gibt `PageHeadingBlock` zurueck (nicht null)
9. [ ] **Slice 02:** `resolveBlock('filter-chips')` gibt `FilterChipsBlock` zurueck (nicht null)
10. [ ] **Slice 02:** `resolveBlock('product-count')` gibt `ProductCountBlock` zurueck (nicht null)

### Flow 3: Produkt-Seite mit Client-Island Blocks

1. [ ] **Slice 01+03:** `GET /produkt/[valid-slug]` — Seite laedt mit HTTP 200
2. [ ] **Slice 03:** Section 1 rendert als 2-Spalten-Grid auf Desktop (product-gallery links, product-purchase rechts)
3. [ ] **Slice 03:** Section 1 stapelt auf Mobile (Gallery oben, Purchase unten)
4. [ ] **Slice 03:** ProductGalleryBlock zeigt Hauptbild und Galerie-Bilder an
5. [ ] **Slice 03:** ProductPurchaseBlock zeigt `<h1>` Produktname, Preis und Varianten-Selektoren an
6. [ ] **Slice 03:** "In den Warenkorb" Button funktioniert nach Varianten-Auswahl
7. [ ] **Slice 03:** ProductDescriptionBlock zeigt Beschreibungs-Card wenn `product.description` nicht leer
8. [ ] **Slice 03:** ProductDescriptionBlock rendert nichts wenn `product.description` leer (kein leerer Card)
9. [ ] **Slice 03:** `generateMetadata` erzeugt korrekten `<title>`, `description` und `og:image`
10. [ ] **Slice 03:** `<head>` enthaelt `<script type="application/ld+json">` mit Product Schema
11. [ ] **Slice 03:** `resolveBlock('product-gallery')`, `resolveBlock('product-purchase')`, `resolveBlock('product-description')` geben jeweils nicht-null zurueck

### Flow 4: Rechtsseiten als neue Next.js-Routen

1. [ ] **Slice 04:** `GET /impressum` — Seite laedt mit HTTP 200 (kein Redirect zu WordPress)
2. [ ] **Slice 04:** `GET /agb` — Seite laedt mit HTTP 200
3. [ ] **Slice 04:** `GET /datenschutz` — Seite laedt mit HTTP 200
4. [ ] **Slice 04:** `GET /widerruf` — Seite laedt mit HTTP 200
5. [ ] **Slice 04:** `/impressum` zeigt `<h1>Impressum</h1>` und WordPress HTML-Content darunter
6. [ ] **Slice 04:** `$route.slug` wird korrekt mit dem jeweiligen Seitenslug aufgeloest
7. [ ] **Slice 04:** Footer-Links navigieren intern (kein Seitenwechsel zu `localhost:8080/...`)
8. [ ] **Slice 04:** Footer `<nav aria-label="Rechtliche Seiten">` enthaelt 4 interne `<Link>` Elemente
9. [ ] **Slice 04:** `LegalContentBlock` zeigt "Inhalt konnte nicht geladen werden." wenn WordPress `pageBy: null` zurueckgibt
10. [ ] **Slice 04:** Apollo `React.cache()` stellt sicher, dass auf jeder Rechtsseite nur ein einziger GraphQL-Request fuer `GET_PAGE_CONTENT` gesendet wird (page-heading und legal-content teilen sich die Antwort)

---

## Edge Cases

### Error Handling

- [ ] **Slice 01:** `loadPageConfig()` mit unbekanntem `pageType` ohne YAML-Fallback wirft einen Error (kein stiller Fail)
- [ ] **Slice 01:** `resolveParams()` mit unbekanntem `$route.unbekannt` Platzhalter resolvt zu leerem String und loggt `console.warn`
- [ ] **Slice 02:** `products_by_category` mit fehlendem `slug` gibt `{ data: null }` zurueck und loggt `console.warn`
- [ ] **Slice 03:** `product_by_slug` mit nicht-existentem Slug ruft `notFound()` in `page.tsx` auf — HTTP 404
- [ ] **Slice 04:** WordPress `pageBy: null` (Seite nicht gefunden) — `LegalContentBlock` zeigt "Inhalt konnte nicht geladen werden." ohne Crash
- [ ] **Slice 04:** GraphQL-Netzwerkfehler in `wordpressLoader` — gibt `{ data: null, error: string }` zurueck, Block zeigt Fehlermeldung

### State Transitions (Block-States)

- [ ] **Slice 02:** `PageHeadingBlock` mit `data === null` rendert `null` (kein leeres `<div>`)
- [ ] **Slice 02:** `FilterChipsBlock` mit `data.nodes === []` rendert `null`
- [ ] **Slice 02:** `ProductCountBlock` mit `count === 0` zeigt "Keine Produkte"
- [ ] **Slice 02:** `ProductCountBlock` mit `count === 1` zeigt "1 Produkt" (Singular)
- [ ] **Slice 02:** `ProductCountBlock` mit `count === 12` zeigt "12 Produkte" (Plural)
- [ ] **Slice 03:** `ProductGalleryBlock` mit `data === null` zeigt Fallback-Element "Produkt nicht gefunden"
- [ ] **Slice 03:** `ProductDescriptionBlock` mit `data === null` oder `data.description === ''` rendert `null`

### Backwards Compatibility

- [ ] **Slice 01:** Alte YAML-Datei mit flachem `blocks:` Format wird automatisch in `sections: [{ columns: 1, blocks: [...] }]` konvertiert (mit `console.warn`)
- [ ] **Slice 04:** `wordpressLoader` mit `query: 'custom_fields'` (kein `page_content`) — bestehendes Verhalten unveraendert

### Boundary Conditions

- [ ] **Slice 01:** `SectionLayout` mit `columns={2}` rendert `grid grid-cols-1 md:grid-cols-2` (Mobile Stack)
- [ ] **Slice 01:** `SectionLayout` mit `columns={4}` rendert `grid grid-cols-1 md:grid-cols-4`
- [ ] **Slice 01:** Block mit `span: 2` in einer `columns: 3` Section erhaelt `style="grid-column: span 2"` am Wrapper-Div
- [ ] **Slice 01:** Block mit `span: 1` erhaelt kein `grid-column` Inline-Style (undefined → kein Attribut)
- [ ] **Slice 01:** 3-tier Lookup — slug-spezifische YAML-Datei (`themes/default/pages/category/t-shirts.yaml`) wird vor generischer (`themes/default/pages/category.yaml`) bevorzugt
- [ ] **Slice 01:** 3-tier Lookup — bei Theme `zweiter-shop` wird `themes/zweiter-shop/pages/category.yaml` vor `themes/default/pages/category.yaml` bevorzugt

---

## Cross-Slice Integration Points

| # | Integration Point | Slices | How to Verify |
|---|-------------------|--------|---------------|
| 1 | `SectionRenderer` + `loadPageConfig()` in category page | Slice 01 → Slice 02 | `GET /kategorie/t-shirts` rendert 4 Sections via YAML, keine hardcoded JSX in page.tsx |
| 2 | `SectionRenderer` + `loadPageConfig()` in product page | Slice 01 → Slice 03 | `GET /produkt/[slug]` rendert 2 Sections (columns=2, columns=1) via YAML |
| 3 | `SectionRenderer` + `loadPageConfig()` in legal pages | Slice 01 → Slice 04 | `GET /impressum` rendert 2 Sections via `legal.yaml` |
| 4 | `PageHeadingBlock` reuse from Kategorie in Rechtsseiten | Slice 02 → Slice 04 | `/impressum` zeigt `<h1>Impressum</h1>` via `page-heading` Block aus Slice 02 |
| 5 | `resolveParams()` in category YAML | Slice 01 → Slice 02 | category.yaml `$route.slug` wird mit URL-Slug aufgeloest vor Data-Loading |
| 6 | `resolveParams()` in product YAML | Slice 01 → Slice 03 | product.yaml `$route.slug` wird mit URL-Slug aufgeloest vor Data-Loading |
| 7 | `resolveParams()` in legal YAML | Slice 01 → Slice 04 | legal.yaml `$route.slug` wird mit `impressum`/`agb`/`datenschutz`/`widerruf` aufgeloest |
| 8 | `products_by_category` DataLoader feeds category blocks | Slice 02 → SectionRenderer | page-heading, product-count und product-grid erhalten alle `CategoryWithProducts` via Apollo `React.cache()` (1 Netzwerk-Request) |
| 9 | `product_by_slug` DataLoader feeds product blocks | Slice 03 → SectionRenderer | gallery, purchase und description erhalten alle `ProductDetailData` via Apollo `React.cache()` (1 Netzwerk-Request) |
| 10 | `wordpressLoader` page_content feeds legal blocks | Slice 04 → SectionRenderer | page-heading und legal-content erhalten `WPPageContent` via Apollo `React.cache()` (1 Netzwerk-Request) |
| 11 | Footer internal links reach legal routes | Slice 04 (footer) → Slice 04 (routes) | Footer `<Link href="/impressum">` navigiert zu `app/impressum/page.tsx` |
| 12 | Registry completeness — all YAML block types resolvable | Slice 01+02+03+04 → `lib/blocks/registry.ts` | Alle in YAML-Dateien referenzierten Block-Types (`page-heading`, `filter-chips`, `product-count`, `product-grid`, `product-gallery`, `product-purchase`, `product-description`, `legal-content`, `hero`, `category-showcase`, `usp-bar`) sind in `registry.ts` registriert |

---

## Integration Test Commands

```bash
# Run all slice tests together
cd frontend && pnpm test tests/slices/block-page-migration/

# Run individual slice tests
cd frontend && pnpm test tests/slices/block-page-migration/slice-01-block-system-upgrade.test.ts
cd frontend && pnpm test tests/slices/block-page-migration/slice-02-kategorie-seite.test.ts
cd frontend && pnpm test tests/slices/block-page-migration/slice-03-produkt-seite.test.ts
cd frontend && pnpm test tests/slices/block-page-migration/slice-04-rechtsseiten.test.ts

# Acceptance: TypeScript build
cd frontend && pnpm build
```

---

## Sign-Off

| Tester | Date | Result |
|--------|------|--------|
| Orchestrator (automated) | [Date] | PASS / FAIL |
| Manual Reviewer | [Date] | PASS / FAIL |

**Notes:**
- Visual parity for category and product pages is a key requirement — these are migrations, not redesigns
- Legal pages are new routes; their WordPress content will show placeholder text (real content in P0.5)
- Client-Island blocks (ProductGalleryBlock, ProductPurchaseBlock) require `"use client"` directive — verify no SSR errors
- Apollo `React.cache()` deduplication can only be verified at integration level (not unit level with `mock_external` strategy)
