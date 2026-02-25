# Gate 1: Architecture Compliance Report

**Gepruefte Architecture:** `specs/phase-0/2026-02-25-block-page-migration/architecture.md`
**Pruefdatum:** 2026-02-25
**Discovery:** `specs/phase-0/2026-02-25-block-page-migration/discovery.md`
**Wireframes:** N/A (bewusst weggelassen -- reine Architektur-Migration ohne UI-Aenderungen)

---

## Summary

| Status | Count |
|--------|-------|
| PASS | 22 |
| Warning | 1 |
| Blocking | 0 |

**Verdict:** APPROVED

---

## Previous Blocking Issues -- Resolution Check

### Previous Issue 1: GET_PRODUCTS_BY_CATEGORY Query-Struktur unklar

**Status:** RESOLVED

**Was das Problem war:** Die Query-Struktur fuer Kategorie+Produkte war unklar -- zwei separate Queries oder eine kombinierte?

**Wie es geloest wurde:** Architecture Section "GraphQL Queries (new)" (Zeile 67) definiert klar:
> `GET_CATEGORY_WITH_PRODUCTS` -- Single multi-root GraphQL request with two root fields: `products(where: { categoryIn: [$categorySlug] })` AND `productCategory(id: $categorySlug, idType: SLUG)`. More efficient than two separate queries.

Die Query-Struktur ist eindeutig spezifiziert: Ein einzelner GraphQL-Request mit zwei Root-Feldern. Variables, Response-Shape und Implementation sind dokumentiert.

**Hinweis:** Namensinkonsistenz in Zeile 271 -- siehe Warning W1 unten.

### Previous Issue 2: 'dynamic' Content Source nicht spezifiziert

**Status:** RESOLVED

**Was das Problem war:** Discovery definiert eine `dynamic` Content Source, aber die Architecture spezifizierte keinen Loader dafuer.

**Wie es geloest wurde:** Architecture Zeile 112 sagt explizit:
> **No `dynamic` content source needed.** All blocks get data from `woocommerce`, `wordpress`, or `inline` sources. Route-derived data (category name, product name) comes from the same GraphQL queries that blocks already call. The `$route.slug` Param-Resolver handles injecting the URL slug into query params before data loading.

Das Block-Data-Source-Mapping (Zeilen 96-111) zeigt konkret fuer jeden Block, welche Content Source und welche Query verwendet wird. Kein Block braucht eine `dynamic` Source -- alle bekommen ihre Daten ueber bestehende `woocommerce` oder `wordpress` Queries.

**Hinweis:** Discovery Zeile 205 listet noch `dynamic` als "Neue Content-Source". Dies ist eine bewusste Abweichung der Architecture von der Discovery, die in der Architecture klar begruendet ist. Keine Aktion noetig.

---

## A) Feature Mapping

| Discovery Feature | Architecture Section | API Endpoint | DB Schema | Status |
|-------------------|---------------------|--------------|-----------|--------|
| Block-System Upgrade: Sections-Format | Scope, Server Logic, Architecture Layers | N/A (internal refactor) | N/A | PASS |
| 2D-Grid-Layout (columns + span + row-span) | Scope, Constraints, Architecture Layers | N/A (CSS Grid) | N/A | PASS |
| Param-Resolver ($route.slug) | Server Logic: resolveParams(), Param Resolution Rules | N/A (internal) | N/A | PASS |
| Template-Override (3-tier) | Server Logic: Template Override Resolution | N/A (fs lookup) | N/A | PASS |
| Homepage Migration auf Sections-Format | Migration Map: `themes/default/pages/home.yaml` + `app/page.tsx` | N/A | N/A | PASS |
| Kategorie-Seite als Block-Page | Migration Map: `app/kategorie/[slug]/page.tsx`, New Files: `category.yaml` | GET_CATEGORY_WITH_PRODUCTS | N/A | PASS |
| Produkt-Seite als Block-Page mit Client-Islands | Migration Map: `app/produkt/[slug]/page.tsx`, New Files: `product.yaml` | GET_PRODUCT (reused) | N/A | PASS |
| Rechtsseiten als Block-Pages | New Files: 4x route pages + `legal.yaml`, New query: GET_PAGE_CONTENT | GET_PAGE_CONTENT | N/A | PASS |
| Footer-Links Umbau (WP -> Next.js) | Migration Map: `components/layout/footer.tsx` | N/A | N/A | PASS |
| Platzhalter-Rechtstexte | Risks & Assumptions: WP pages created by setup.sh | N/A | N/A (existing WP pages) | PASS |

**Evidence:** Alle 10 Discovery-Features sind in der Architecture adressiert. Jedes Feature hat mindestens einen Eintrag in Migration Map oder New Files, und wo noetig einen API Endpoint.

---

## B) Constraint Mapping

| Constraint | Source | Architecture | Status |
|------------|--------|--------------|--------|
| Page-level logic stays in page.tsx (generateStaticParams, generateMetadata, JSON-LD, Pinterest) | Discovery Business Rules | Constraints Table: "page.tsx remains a thin wrapper that handles these" | PASS |
| Mobile always stacks (columns: 1) | Discovery Business Rules | Constraints: "SectionLayout uses grid-cols-1 as base, md:grid-cols-{N}" | PASS |
| Theme tokens only (no hardcoded colors) | Discovery Business Rules | Constraints: "All new components use bg-primary, text-text-primary, rounded-card" | PASS |
| Warenkorb excluded | Discovery Scope (Out of Scope) | Architecture Scope: "Warenkorb-Seite (bleibt hardcoded)" | PASS |
| YAML page configs use full replacement (not deep merge) | Discovery Business Rules | Constraints: "Shop themes must provide complete page YAML if overriding" | PASS |
| Default columns: 1 (fullwidth) | Discovery Business Rules | Server Logic: SectionLayout default columns=1 | PASS |
| $route.slug resolved before data loading | Discovery Business Rules | Server Logic: Business Logic Flow shows resolveParams before loadBlockData | PASS |
| Client-Island blocks need server data | Discovery New Patterns | Constraints: "Data fetched by data-loader (server), passed as props to Client-Island block wrapper" | PASS |

---

## C) Realistic Data Check

### Codebase Evidence

```
# Existing patterns in codebase:
- ContentSource type: 'wordpress' | 'woocommerce' | 'inline' (lib/blocks/types.ts:3)
- WooCommerceLoaderParams.query: 'featured_products' | 'product_categories' (lib/blocks/types.ts:10)
- WordPressLoaderParams: { page_slug: string } (lib/blocks/types.ts:6)
- PageConfig: { blocks: BlockConfig[] } (lib/blocks/types.ts:27)
- YAML parser: yaml ^2.7.0 (package.json)
- GraphQL: Apollo Client ^4.1.4, gql tagged templates (queries.ts)
- URL fields: sourceUrl (string in TypeScript, from WPGraphQL -- no VARCHAR constraint)
- Slug fields: string (TypeScript), used as GraphQL variables
- HTML content: string (product.description via dangerouslySetInnerHTML)
```

### External API Analysis

No NEW external APIs are introduced. All queries go to the existing WordPress/WooCommerce GraphQL endpoint (localhost:8080/graphql). No external third-party APIs.

| API | Field | Measured Length | Sample | Arch Type | Recommendation |
|-----|-------|----------------|--------|-----------|----------------|
| WPGraphQL | pageBy.title | 5-25 chars | "Impressum", "Datenschutzerklaerung" | string (TS) | PASS -- TypeScript string, no DB constraint |
| WPGraphQL | pageBy.content | 50-10000+ chars | HTML body of legal pages | string (TS) | PASS -- TypeScript string, rendered via dangerouslySetInnerHTML |
| WPGraphQL | productCategory.name | 3-50 chars | "T-Shirts", "Hoodies" | string (TS) | PASS -- TypeScript string |
| WPGraphQL | productCategory.description | 0-1000+ chars | Category description HTML | string (TS) | PASS -- TypeScript string |

### Data Type Verdicts

| Field | Arch Type | Evidence | Verdict | Issue |
|-------|-----------|----------|---------|-------|
| ContentSource union | `'wordpress' \| 'woocommerce' \| 'inline'` | Existing types.ts:3 uses same union. Architecture does NOT add 'dynamic'. | PASS | -- |
| WooCommerceLoaderParams.query | Extended string union | Existing: `'featured_products' \| 'product_categories'`. Architecture adds `'products_by_category' \| 'product_by_slug'`. Consistent pattern. | PASS | -- |
| WordPressLoaderParams | Extended with query field | Existing: `{ page_slug: string }`. Architecture adds optional `query` discriminator. Backward compatible. | PASS | -- |
| PageConfig | Sections format | Existing: `{ blocks: BlockConfig[] }`. Architecture changes to `{ sections: SectionConfig[] }`. Breaking change but Migration Map covers home.yaml conversion. | PASS | -- |
| SectionConfig (NEW) | `{ columns: number, gap?: string, blocks: BlockConfig[] }` | No existing equivalent. New interface. Columns as number is correct for CSS Grid repeat(). | PASS | -- |
| BlockConfig.span (NEW) | Optional number | Default 1. Used in grid-column span. Correct for CSS Grid. | PASS | -- |
| BlockConfig.row_span (NEW) | Optional number | Default 1. Used in grid-row span. Correct for CSS Grid. | PASS | -- |
| CategoryWithProducts (NEW) | Combined response DTO | Combines products.nodes[] + productCategory. Matches GraphQL multi-root query response shape. | PASS | -- |
| WPPageContent (NEW) | `{ title: string, content: string }` | Matches WPGraphQL `pageBy` response fields. Verified: `pageBy` returns `title` and `content` in WPGraphQL schema. | PASS | -- |
| Route slug param | string | Existing pattern: `params: Promise<{ slug: string }>` (Next.js 16 pattern). Architecture uses same. | PASS | -- |

---

## D) External Dependencies

### D1) Dependency Version Check

**Project type:** Existing (package.json exists with pinned versions)

| Dependency | Arch Version | Pinning File | Pinned? | "Latest"? | Status |
|------------|-------------|--------------|---------|-----------|--------|
| Next.js | 16.1.x (package.json: `^16.1.6`) | package.json: `^16.1.6` | PASS (caret pin) | No | PASS |
| React | 19.x (package.json: `^19.0.0`) | package.json: `^19.0.0` | PASS | No | PASS |
| Tailwind CSS | 4.x (package.json: `^4.0.0`) | package.json: `^4.0.0` | PASS | No | PASS |
| Apollo Client | 4.1.x (package.json: `^4.1.4`) | package.json: `^4.1.4` | PASS | No | PASS |
| WPGraphQL | 1.x (WordPress plugin) | Docker setup | PASS | No | PASS |
| WooGraphQL | 0.x (WordPress plugin) | Docker setup | PASS | No | PASS |
| yaml | 2.7.x (package.json: `^2.7.0`) | package.json: `^2.7.0` | PASS | No | PASS |
| WordPress | 6.9.x (Docker) | Docker setup | PASS | No | PASS |

**All dependencies are existing and pinned.** No new dependencies introduced. Architecture correctly references pinned versions from package.json.

### D2) External APIs & Services

| Dependency | Rate Limits | Auth | Errors | Timeout | Status |
|------------|-------------|------|--------|---------|--------|
| WPGraphQL (existing) | N/A (server-side RSC, same-network Docker) | No auth (public data) | Return null data, console.error (existing pattern) | Default Apollo timeout | PASS |
| WooGraphQL (existing) | N/A (server-side RSC) | No auth (public product data) | Return null data (existing pattern) | Default Apollo timeout | PASS |

---

## E) Migration Completeness

> Scope enthaelt Migration (Homepage, Kategorie, Produkt, Rechtsseiten werden von hardcoded auf Block-basiert migriert).

### Quantitaets-Check

| Discovery Claim | Architecture Coverage | Status |
|---|---|---|
| Block-System Upgrade (Sections, Layout, Param-Resolver, Template-Override) | Migration Map: 6 existing files + Server Logic specs | PASS |
| Homepage auf Sections-Format migrieren | Migration Map: `themes/default/pages/home.yaml` + `app/page.tsx` | PASS |
| Kategorie-Seite als Block-Page | Migration Map: `app/kategorie/[slug]/page.tsx` + New: `category.yaml` + 3 new blocks | PASS |
| Produkt-Seite als Block-Page | Migration Map: `app/produkt/[slug]/page.tsx` + New: `product.yaml` + 3 new blocks | PASS |
| Rechtsseiten (4x) als Block-Pages | New: 4 route pages + `legal.yaml` + 1 new block + GET_PAGE_CONTENT query | PASS |
| Footer-Links Umbau | Migration Map: `components/layout/footer.tsx` | PASS |

Migration Map has 10 existing files + 16 new files = 26 total file entries. Covers all Discovery scope items.

### Qualitaets-Check

| File in Migration Map | Current Pattern | Target Pattern | Specific enough for test? | Status |
|---|---|---|---|---|
| `lib/blocks/types.ts` | Flat PageConfig.blocks[] | SectionConfig[] with columns, gap, blocks[]. Extended query unions. | Yes -- test: SectionConfig interface exists, query unions include new values | PASS |
| `lib/blocks/page-config.ts` | 2-tier lookup, flat blocks | 3-tier lookup, sections format, resolveParams() | Yes -- test: 3-tier resolution, $route.slug replacement | PASS |
| `lib/blocks/data-loaders.ts` | 2 woocommerce queries, 1 wordpress query | 4 woocommerce queries, 2 wordpress queries | Yes -- test: products_by_category handler, product_by_slug handler, page_content handler | PASS |
| `lib/blocks/registry.ts` | 4 block types | 11 block types (+ 7 new) | Yes -- test: resolveBlock('page-heading') returns component | PASS |
| `themes/default/pages/home.yaml` | Flat blocks: array | sections: array wrapping blocks | Yes -- test: YAML has sections key, each section has blocks array | PASS |
| `app/page.tsx` | Flat block iteration, inline BlockRenderer | SectionRenderer component | Yes -- test: imports SectionRenderer, no inline BlockRenderer | PASS |
| `app/kategorie/[slug]/page.tsx` | Hardcoded JSX | Thin wrapper + SectionRenderer | Yes -- test: no ProductCard import, uses loadPageConfig + SectionRenderer | PASS |
| `app/produkt/[slug]/page.tsx` | Hardcoded JSX | Thin wrapper + SectionRenderer | Yes -- test: no ProductImageGallery import, uses loadPageConfig + SectionRenderer | PASS |
| `components/layout/footer.tsx` | Links to `${WP_URL}/impressum` | Links to `/impressum` with Next.js Link | Yes -- test: href="/impressum", uses Link from next/link | PASS |

---

## W) Warnings

### Warning W1: Query Name Inconsistency in Migration Map

**Category:** Naming
**Severity:** Warning (not blocking -- intent is clear)

**Architecture API Design (line 67) says:**
> `GET_CATEGORY_WITH_PRODUCTS`

**Architecture Migration Map New Files (line 271) says:**
> `GET_PRODUCTS_BY_CATEGORY`

**Problem:** Two different names for the same query in the same document. The API Design section, Data Loaders migration entry (line 241), and reused queries note (line 77) all consistently use `GET_CATEGORY_WITH_PRODUCTS`. Only line 271 in the New Files table uses `GET_PRODUCTS_BY_CATEGORY`.

**Resolution:** Rename line 271 to `GET_CATEGORY_WITH_PRODUCTS` for consistency. Non-blocking because the API Design section (which is authoritative for query naming) is clear and consistent.

---

## Blocking Issues

None.

---

## Recommendations

1. **[Warning]** Fix query name in Migration Map New Files (line 271): Change `GET_PRODUCTS_BY_CATEGORY` to `GET_CATEGORY_WITH_PRODUCTS` for consistency with the rest of the document.
2. **[Info]** Discovery still lists `dynamic` content source in Data section (line 205). Consider adding a strikethrough or note in Discovery to prevent confusion, since Architecture explicitly removed it.

---

## Verdict

**Status:** APPROVED

**Blocking Issues:** 0
**Warnings:** 1

**Next Steps:**
- [ ] (Optional) Fix query naming inconsistency in architecture.md line 271
- [ ] (Optional) Update discovery.md to note that `dynamic` content source was removed during architecture phase
- [ ] Proceed to slice writing
