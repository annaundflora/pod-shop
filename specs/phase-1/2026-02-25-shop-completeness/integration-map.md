# Integration Map: Shop Completeness Phase 1

**Generated:** 2026-02-26
**Feature:** Shop Completeness — Blocks & Pages
**Slices:** 6
**Connections:** 32

---

## Dependency Graph (Visual)

```
┌──────────────────────────────┐
│  Slice 01                    │
│  Cross-Page Infrastruktur    │
│  (Keine Dependencies)        │
└──────────┬───────────────────┘
           │ Provides: 6 Block-Components, loadGlobalConfig,
           │6 Data-Interfaces, registry.ts erweitert
           │
     ┌─────┴──────────────────────────────────────────┐
     │              │                │                 │
     ▼              ▼                ▼                 ▼
┌─────────┐   ┌─────────┐      ┌─────────┐       ┌─────────┐
│ Slice 02│   │ Slice 03│      │(wartet) │       │(wartet) │
│ Produkt │   │ Kategorie│     │         │       │         │
│ Page    │   │ Page    │      │         │       │         │
└────┬────┘   └────┬────┘      └─────────┘       └─────────┘
     │             │ Provides: GET_PRODUCTS_PAGINATED,
     │             │ GET_CATEGORY_META, PaginatedProductsResult,
     │             │ PaginationMeta, buildOrderby()
     │             │
     │      ┌──────┴──────────────────────────┐
     │      │              │                  │
     │      ▼              ▼                  ▼
     │ ┌─────────┐   ┌─────────┐        ┌─────────┐
     │ │ Slice 04│   │ Slice 05│        │ Slice 06│
     │ │ Homepage│   │ Suche   │        │ Neue    │
     │ │ Enhanc. │   │         │        │ Pages   │
     │ └─────────┘   └─────────┘        └─────────┘
     │
     └─→ (Keine eigenen Downstream-Consumers in diesem Feature)
```

**Wave-Zusammenfassung:**

```
Wave 1 (sequenziell): Slice 01
Wave 2 (parallel):    Slice 02 || Slice 03
Wave 3 (parallel):    Slice 04 || Slice 05 || Slice 06
```

---

## Nodes

### Slice 01: Cross-Page Infrastruktur

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | Keine (Foundation Slice) |
| Test-Befehl | `pnpm test tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| Keine | — | Kein vorheriger Slice erforderlich |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `AnnouncementBarBlock` | React Component (Client) | `app/layout.tsx` via global.yaml |
| `BreadcrumbBlock` | React Component (Server) | Slice 03 (category.yaml) |
| `TrustBadgesBlock` | React Component (Server) | Slice 02 (product.yaml) |
| `PaginationBlock` | React Component (Server) | Slice 03 (category.yaml), Slice 05 (search.yaml), Slice 06 (collection.yaml) |
| `SortBarBlock` | React Component (Client) | Slice 03 (category.yaml), Slice 05 (search.yaml) |
| `EmptyStateBlock` | React Component (Server) | Slice 03 (category.yaml), Slice 05 (search.yaml) |
| `AnnouncementBarData` | TypeScript Interface | `lib/blocks/types.ts` |
| `BreadcrumbData` | TypeScript Interface | `lib/blocks/types.ts` |
| `TrustBadgeData` | TypeScript Interface | `lib/blocks/types.ts` |
| `PaginationData` | TypeScript Interface | `lib/blocks/types.ts` |
| `SortBarData` | TypeScript Interface | `lib/blocks/types.ts` (erweitert von Slice 05) |
| `EmptyStateData` | TypeScript Interface | `lib/blocks/types.ts` |
| `SortOption` | TypeScript Union Type | Slice 03, Slice 05 |
| `loadGlobalConfig(theme)` | Function | `app/layout.tsx` (via Slice 04) |
| `registry.ts` (6 neue Eintraege) | Registry Map | `lib/blocks/section-renderer.tsx` |
| `themes/default/pages/global.yaml` | YAML Config | `app/layout.tsx` (via Slice 04) |
| `WooCommerceLoaderParams` (erweitert) | TypeScript Interface | Slice 03, Slice 05 |

---

### Slice 02: Produkt-Page Enhancements

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | `slice-01-cross-page-infrastruktur` |
| Test-Befehl | `pnpm test tests/slices/shop-completeness/slice-02-produkt-page-enhancements.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `TrustBadgesBlock` | Slice 01 | Registriert in `registry.ts` unter Key `'trust-badges'` |
| `TrustBadgeData` Interface | Slice 01 | In `lib/blocks/types.ts` definiert |
| `registry.ts` Pattern | Slice 01 | Pattern stabil fuer weitere Block-Registrierungen |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `ProductReviewsBlock` | React Component (Client) | `app/produkt/[slug]/page.tsx` via product.yaml |
| `ProductRecommendationsBlock` | React Component (Server) | `app/produkt/[slug]/page.tsx` via product.yaml |
| `ProductReviewsResult` | TypeScript Interface | `lib/blocks/data-loaders.ts` (product_reviews branch) |
| `ReviewEdge` | TypeScript Interface | `ProductReviewsBlock` |
| `WriteReviewInput` | TypeScript Interface | `review-form.tsx` |
| `validateReviewInput()` | Function | Tests + `review-form.tsx` |
| `WRITE_REVIEW` | GraphQL Mutation | `lib/graphql/mutations.ts` |
| `GET_PRODUCT_REVIEWS` | GraphQL Query | `lib/graphql/queries.ts` |
| `GET_RELATED_PRODUCTS` | GraphQL Query | `lib/graphql/queries.ts` |
| `GET_BESTSELLER_PRODUCTS` | GraphQL Query | `lib/graphql/queries.ts` |
| `GET_PRODUCTS_BY_IDS` | GraphQL Query | `lib/graphql/queries.ts` |
| `GET_PRODUCT_CATEGORY` | GraphQL Query | `lib/graphql/queries.ts` (fuer Recommendations-Fallback) |
| `product.yaml` (erweitert) | YAML Config | `app/produkt/[slug]/page.tsx` via loadPageConfig |
| `lib/graphql/mutations.ts` | Neue Datei | `review-form.tsx` (Client-Mutation) |

---

### Slice 03: Kategorie-Page Enhancements

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | `slice-01-cross-page-infrastruktur` |
| Test-Befehl | `pnpm test tests/slices/shop-completeness/slice-03-kategorie-page-enhancements.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `BreadcrumbBlock` | Slice 01 | Export `components/blocks/breadcrumb-block.tsx` |
| `SortBarBlock` | Slice 01 | Export `components/blocks/sort-bar-block.tsx` |
| `PaginationBlock` | Slice 01 | Export `components/blocks/pagination-block.tsx` |
| `EmptyStateBlock` | Slice 01 | Export `components/blocks/empty-state-block.tsx` |
| `PaginationData` | Slice 01 | In `lib/blocks/types.ts` |
| `SortOption` | Slice 01 | In `lib/blocks/types.ts` |
| `BreadcrumbData` | Slice 01 | In `lib/blocks/types.ts` |
| `EmptyStateData` | Slice 01 | In `lib/blocks/types.ts` |
| `SortBarData` | Slice 01 | In `lib/blocks/types.ts` |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `GET_PRODUCTS_PAGINATED` | GraphQL Query | Slice 05 (search_products), Slice 04 (featured_collection), Slice 06 (products_by_category) |
| `GET_CATEGORY_META` | GraphQL Query | Slice 04 (featured_collection), Slice 06 (collection-header) |
| `PaginatedProductsResult` | TypeScript DTO | Slice 05, Slice 06 |
| `PaginationMeta` | TypeScript DTO | Slice 05, Slice 06 |
| `products_by_category` Branch (paginiert) | data-loaders.ts Branch | Slice 06 (collection.yaml) |
| `buildOrderby()` | Function (intern in data-loaders.ts) | Slice 05 (wiederverwendet) |
| `category_meta` Branch | data-loaders.ts Branch | Slice 06 (collection-header) |
| `WooCommerceLoaderParams` (erweitert) | TypeScript Interface | Slice 05, Slice 06 |
| `app/kategorie/[slug]/page.tsx` (erweitert) | EXISTING file - Modify | Liest `searchParams.page` und `searchParams.sort` |
| `themes/default/pages/category.yaml` (ersetzt) | YAML Config | `app/kategorie/[slug]/page.tsx` via loadPageConfig |

---

### Slice 04: Homepage Enhancements

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | `slice-01-cross-page-infrastruktur`, `slice-03-kategorie-page-enhancements` |
| Test-Befehl | `pnpm test tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `loadGlobalConfig(theme)` | Slice 01 | Exportiert aus `lib/blocks/page-config.ts` |
| `AnnouncementBarBlock` | Slice 01 | Registriert in `registry.ts` |
| `registry.ts` Pattern | Slice 01 | Block-Registration Pattern stabil |
| `GET_CATEGORY_META` | Slice 03 | Exportiert aus `lib/graphql/queries.ts` |
| `GET_PRODUCTS_PAGINATED` | Slice 03 | Exportiert aus `lib/graphql/queries.ts` |
| `PaginatedProductsResult` | Slice 03 | Exportiert aus `lib/blocks/types.ts` |
| `WooCommerceLoaderParams` (mit `source`/`slug`) | Slice 03 | Erweitertes Interface |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `TestimonialsBlock` | React Component (Server) | `app/page.tsx` via home.yaml |
| `NewsletterSignupBlock` | React Component (Client) | `app/page.tsx` via home.yaml |
| `FeaturedCollectionBlock` | React Component (Server) | `app/page.tsx` via home.yaml |
| `FeaturedCollectionBlockSkeleton` | React Component | `app/page.tsx` (skeletonMap) |
| `TestimonialsData` | TypeScript Interface | `lib/blocks/types.ts` |
| `NewsletterSignupData` | TypeScript Interface | `lib/blocks/types.ts` |
| `FeaturedCollectionData` | TypeScript Interface | `lib/blocks/types.ts` |
| `themes/default/pages/global.yaml` | YAML Config (neu) | `app/layout.tsx` via `loadGlobalConfig()` |
| `featured_collection` Branch | data-loaders.ts Branch | `featured-collection-block.tsx` |
| `app/layout.tsx` (erweitert) | EXISTING file - Modify | Rendert GlobalConfig via `loadGlobalConfig()` |
| `themes/default/pages/home.yaml` (erweitert) | EXISTING file - Modify | 3 neue Sections |
| `app/page.tsx` (erweitert) | EXISTING file - Modify | skeletonMap erweitert |

---

### Slice 05: Suchseite

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | `slice-01-cross-page-infrastruktur`, `slice-03-kategorie-page-enhancements` |
| Test-Befehl | `pnpm test tests/slices/shop-completeness/slice-05-suchseite.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `PaginationBlock` | Slice 01 | Export `components/blocks/pagination-block.tsx` |
| `SortBarBlock` | Slice 01 | Export `components/blocks/sort-bar-block.tsx` |
| `EmptyStateBlock` | Slice 01 | Export `components/blocks/empty-state-block.tsx` |
| `SortBarData` Interface | Slice 01 | Wird von Slice 05 um `currentQuery?` erweitert |
| `loadGlobalConfig` | Slice 01 | Exportiert aus `lib/blocks/page-config.ts` |
| `GET_PRODUCTS_PAGINATED` | Slice 03 | Exportiert, mit `$search`-Variable |
| `PaginatedProductsResult` | Slice 03 | Exportiert aus `lib/blocks/types.ts` |
| `PaginationMeta` | Slice 03 | Exportiert aus `lib/blocks/types.ts` |
| `buildOrderby()` | Slice 03 | Intern in `lib/blocks/data-loaders.ts` |
| `WooCommerceLoaderParams` | Slice 03 | Erweitert mit `search?` Feld |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `SearchBarBlock` | React Component (Client) | `app/suche/page.tsx` via search.yaml |
| `SearchResultsBlock` | React Component (Server) | `app/suche/page.tsx` via search.yaml |
| `SearchBarData` | TypeScript Interface | `lib/blocks/types.ts` |
| `SortBarData` (erweitert) | TypeScript Interface | `components/blocks/sort-bar-block.tsx` |
| `search_products` Branch | data-loaders.ts Branch | `search-results-block.tsx` |
| `app/suche/page.tsx` | Neue Route | Header-Integration, globale Navigation |
| `themes/default/pages/search.yaml` | YAML Config (neu) | `app/suche/page.tsx` via loadPageConfig |
| `components/layout/header.tsx` (erweitert) | EXISTING file - Modify | Suchicon hinzugefuegt |

---

### Slice 06: Neue Pages

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | `slice-01-cross-page-infrastruktur`, `slice-03-kategorie-page-enhancements` |
| Test-Befehl | `pnpm test tests/slices/shop-completeness/slice-06-neue-pages.test.ts` |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `PaginationBlock` | Slice 01 | Export `components/blocks/pagination-block.tsx` |
| `loadGlobalConfig` | Slice 01 | Exportiert aus `lib/blocks/page-config.ts` |
| `GET_CATEGORY_META` | Slice 03 | Exportiert aus `lib/graphql/queries.ts` |
| `category_meta` loader branch | Slice 03 | In `lib/blocks/data-loaders.ts` |
| `products_by_category` Branch (paginiert) | Slice 03 | In `lib/blocks/data-loaders.ts` |
| `PaginatedProductsResult` | Slice 03 | Exportiert aus `lib/blocks/types.ts` |
| `PaginationMeta` | Slice 03 | Exportiert aus `lib/blocks/types.ts` |
| `buildOrderby()` | Slice 03 | Intern in data-loaders.ts |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `CollectionHeaderBlock` | React Component (Server) | `app/kollektion/[slug]/page.tsx` via collection.yaml |
| `CollectionHeaderBlockSkeleton` | React Component | `app/kollektion/[slug]/page.tsx` (skeletonMap) |
| `OrderConfirmationBlock` | React Component (Client) | `app/danke/page.tsx` via thanks.yaml |
| `CollectionHeaderData` | TypeScript Interface | `lib/blocks/types.ts` |
| `OrderConfirmationData` | TypeScript Interface | `lib/blocks/types.ts` |
| `app/kollektion/[slug]/page.tsx` | Neue Route | Navigation, Homepage featured-collection CTA |
| `app/danke/page.tsx` | Neue Route | WooCommerce Checkout Redirect |
| `app/not-found.tsx` | Next.js 404 Handler | Alle fehlerhaften Links im gesamten Shop |
| `themes/default/pages/collection.yaml` | YAML Config (neu) | `app/kollektion/[slug]/page.tsx` via loadPageConfig |
| `themes/default/pages/thanks.yaml` | YAML Config (neu) | `app/danke/page.tsx` via loadPageConfig |

---

## Connections

| # | Von | Zu | Resource | Type | Status |
|---|-----|----|----------|------|--------|
| 1 | Slice 01 | Slice 02 | `TrustBadgesBlock` | React Component | VALID |
| 2 | Slice 01 | Slice 02 | `TrustBadgeData` | TypeScript Interface | VALID |
| 3 | Slice 01 | Slice 02 | `registry.ts` Pattern | Module | VALID |
| 4 | Slice 01 | Slice 03 | `BreadcrumbBlock` | React Component | VALID |
| 5 | Slice 01 | Slice 03 | `SortBarBlock` | React Component | VALID |
| 6 | Slice 01 | Slice 03 | `PaginationBlock` | React Component | VALID |
| 7 | Slice 01 | Slice 03 | `EmptyStateBlock` | React Component | VALID |
| 8 | Slice 01 | Slice 03 | `PaginationData`, `SortOption`, `BreadcrumbData`, `EmptyStateData`, `SortBarData` | TypeScript Interfaces | VALID |
| 9 | Slice 01 | Slice 04 | `loadGlobalConfig(theme)` | Function | VALID |
| 10 | Slice 01 | Slice 04 | `AnnouncementBarBlock` | React Component | VALID |
| 11 | Slice 01 | Slice 04 | `registry.ts` Pattern | Module | VALID |
| 12 | Slice 01 | Slice 05 | `PaginationBlock` | React Component | VALID |
| 13 | Slice 01 | Slice 05 | `SortBarBlock` | React Component | VALID |
| 14 | Slice 01 | Slice 05 | `EmptyStateBlock` | React Component | VALID |
| 15 | Slice 01 | Slice 05 | `SortBarData` Interface | TypeScript Interface (Slice 05 erweitert) | VALID |
| 16 | Slice 01 | Slice 06 | `PaginationBlock` | React Component | VALID |
| 17 | Slice 01 | Slice 06 | `loadGlobalConfig` | Function | VALID |
| 18 | Slice 03 | Slice 04 | `GET_CATEGORY_META` | GraphQL Query | VALID |
| 19 | Slice 03 | Slice 04 | `GET_PRODUCTS_PAGINATED` | GraphQL Query | VALID |
| 20 | Slice 03 | Slice 04 | `PaginatedProductsResult` | TypeScript DTO | VALID |
| 21 | Slice 03 | Slice 04 | `WooCommerceLoaderParams` (mit source/slug) | TypeScript Interface | VALID |
| 22 | Slice 03 | Slice 05 | `GET_PRODUCTS_PAGINATED` (mit $search) | GraphQL Query | VALID |
| 23 | Slice 03 | Slice 05 | `PaginatedProductsResult` | TypeScript DTO | VALID |
| 24 | Slice 03 | Slice 05 | `PaginationMeta` | TypeScript DTO | VALID |
| 25 | Slice 03 | Slice 05 | `buildOrderby()` | Function | VALID |
| 26 | Slice 03 | Slice 05 | `WooCommerceLoaderParams` (mit search?) | TypeScript Interface | VALID |
| 27 | Slice 03 | Slice 06 | `GET_CATEGORY_META` | GraphQL Query | VALID |
| 28 | Slice 03 | Slice 06 | `category_meta` loader branch | data-loaders Branch | VALID |
| 29 | Slice 03 | Slice 06 | `products_by_category` Branch (paginiert) | data-loaders Branch | VALID |
| 30 | Slice 03 | Slice 06 | `PaginatedProductsResult` | TypeScript DTO | VALID |
| 31 | Slice 03 | Slice 06 | `PaginationMeta` | TypeScript DTO | VALID |
| 32 | Slice 03 | Slice 06 | `buildOrderby()` | Function | VALID |

---

## Cross-Slice Interfaces (TypeScript-Typen und Funktionen)

| # | Interface / Funktion | Definiert In | Konsumiert Von | Signatur |
|---|---------------------|--------------|----------------|---------|
| 1 | `AnnouncementBarData` | Slice 01 → `lib/blocks/types.ts` | `app/layout.tsx`, global.yaml | `{ id: string, text: string, link?: string, dismissible?: boolean, bgColor?: string }` |
| 2 | `BreadcrumbData` | Slice 01 → `lib/blocks/types.ts` | Slice 03 (category.yaml), BreadcrumbBlock | `{ items: { label: string, href?: string }[] }` |
| 3 | `TrustBadgeData` | Slice 01 → `lib/blocks/types.ts` | Slice 02 (product.yaml), TrustBadgesBlock | `{ items: { icon: string, text: string }[] }` |
| 4 | `PaginationData` | Slice 01 → `lib/blocks/types.ts` | Slice 03, Slice 05, Slice 06 | `{ currentPage: number, totalPages: number, baseUrl: string, currentSort?: string, currentQuery?: string }` |
| 5 | `SortBarData` | Slice 01 → `lib/blocks/types.ts` (Slice 05 erweitert) | Slice 03, Slice 05 | `{ currentSort: SortOption, baseUrl: string, currentQuery?: string }` |
| 6 | `EmptyStateData` | Slice 01 → `lib/blocks/types.ts` | Slice 03, Slice 05 | `{ headline: string, text: string, links?: { label: string, href: string }[] }` |
| 7 | `SortOption` | Slice 01 → `lib/blocks/types.ts` | Slice 03, Slice 05 | `'default' \| 'price_asc' \| 'price_desc' \| 'newest'` |
| 8 | `loadGlobalConfig(theme)` | Slice 01 → `lib/blocks/page-config.ts` | Slice 04 → `app/layout.tsx` | `(theme: string) => PageConfig` |
| 9 | `WooCommerceLoaderParams` (erweitert) | Slice 01 Basis, Slice 03 erweitert, Slice 05 erweitert | Slice 03, Slice 04, Slice 05, Slice 06 | `{ query, slug?, page?, perPage?, sort?, search?, source?, ... }` |
| 10 | `GET_PRODUCTS_PAGINATED` | Slice 03 → `lib/graphql/queries.ts` | Slice 04, Slice 05, Slice 06 | `gql` — Variables: `{ categorySlug?: String, first: Int!, orderby?, search? }` |
| 11 | `GET_CATEGORY_META` | Slice 03 → `lib/graphql/queries.ts` | Slice 04, Slice 06 | `gql` — Variables: `{ slug: ID! }` |
| 12 | `PaginatedProductsResult` | Slice 03 → `lib/blocks/types.ts` | Slice 04, Slice 05, Slice 06 | `{ products: { nodes: ProductCardData[] }, pagination: PaginationMeta, productCategory? }` |
| 13 | `PaginationMeta` | Slice 03 → `lib/blocks/types.ts` | Slice 05, Slice 06 | `{ currentPage, totalPages, hasNextPage, hasPreviousPage, totalCount }` |
| 14 | `buildOrderby()` | Slice 03 → `lib/blocks/data-loaders.ts` (intern) | Slice 05 (wiederverwendet) | `(sort: string \| undefined) => ProductsOrderbyInput[] \| undefined` |
| 15 | `BlockComponentProps<T>` | Bestehend → `lib/blocks/types.ts` | Alle 6 Slices | `{ data: T }` |
| 16 | `ProductReviewsResult` | Slice 02 → `lib/blocks/types.ts` | product_reviews data-loader branch | `{ averageRating, reviewCount, reviewsAllowed, reviews: { edges: ReviewEdge[] }, productId }` |
| 17 | `WriteReviewInput` | Slice 02 → `lib/blocks/types.ts` | `review-form.tsx`, Tests | `{ productId: number, content: string, author: string, authorEmail: string, rating: number }` |
| 18 | `SearchBarData` | Slice 05 → `lib/blocks/types.ts` | `SearchBarBlock`, search.yaml | `{ placeholder: string, currentQuery?: string }` |
| 19 | `CollectionHeaderData` | Slice 06 → `lib/blocks/types.ts` | `CollectionHeaderBlock`, collection.yaml | `{ name: string, description: string, image?: { sourceUrl, altText } }` |
| 20 | `OrderConfirmationData` | Slice 06 → `lib/blocks/types.ts` | `OrderConfirmationBlock`, thanks.yaml | `{ headline, text, emailText, ctaText, ctaLink }` |
| 21 | `TestimonialsData` | Slice 04 → `lib/blocks/types.ts` | `TestimonialsBlock`, home.yaml | `{ items: { name, text, rating, location? }[] }` |
| 22 | `NewsletterSignupData` | Slice 04 → `lib/blocks/types.ts` | `NewsletterSignupBlock`, home.yaml | `{ headline, text, buttonText, placeholder }` |
| 23 | `FeaturedCollectionData` | Slice 04 → `lib/blocks/types.ts` | `FeaturedCollectionBlock`, featured_collection loader | `{ category: {...}, products: { nodes: ProductCardData[] } }` |

---

## Shared Deliverables (Mehrfach modifizierte Dateien)

> Diese Dateien werden von mehreren Slices modifiziert. Die Reihenfolge ist kritisch um Merge-Konflikte zu vermeiden.

| # | Datei | Modifiziert Von | Merge-Reihenfolge | Art der Aenderung |
|---|-------|-----------------|-------------------|-------------------|
| 1 | `frontend/lib/blocks/registry.ts` | Slice 01, Slice 02, Slice 03 (nein), Slice 04, Slice 05, Slice 06 | Wave 1 → Wave 2 → Wave 3 | Jeder Slice fuegt neue Block-Typen hinzu (additiv, kein Konflikt wenn sequenziell) |
| 2 | `frontend/lib/blocks/types.ts` | Slice 01, Slice 02, Slice 03, Slice 04, Slice 05, Slice 06 | Wave 1 → Wave 2 → Wave 3 | Interface-Erweiterungen; `WooCommerceLoaderParams.query` Union waechst; alle aenderungen sind additiv |
| 3 | `frontend/lib/blocks/data-loaders.ts` | Slice 02, Slice 03, Slice 04, Slice 05, Slice 06 | Wave 2 (Slice 03 zuerst wegen buildOrderby) → Wave 3 | Neue `else if (params.query === '...')` Branches; bestehende Branches unveraendert |
| 4 | `frontend/lib/graphql/queries.ts` | Slice 02, Slice 03 | Wave 2 parallel | Neue `gql` exports; keine Konflikte (additive Exporte) |
| 5 | `frontend/themes/default/pages/home.yaml` | Slice 04 | Wave 3 (Slice 04 allein) | 3 neue Sections hinzugefuegt (additive Erweiterung) |
| 6 | `frontend/app/layout.tsx` | Slice 04 | Wave 3 (Slice 04 allein) | `loadGlobalConfig()` Aufruf + global sections render (minimale Aenderung) |
| 7 | `frontend/app/page.tsx` | Slice 04 | Wave 3 (Slice 04 allein) | `skeletonMap` erweitert |
| 8 | `frontend/components/layout/header.tsx` | Slice 05 | Wave 3 (Slice 05 allein) | Suchicon als Link `/suche` hinzugefuegt |
| 9 | `frontend/themes/default/pages/category.yaml` | Slice 03 | Wave 2 (Slice 03 allein) | Vollstaendiger Ersatz mit Breadcrumb, Sort-Bar, Pagination, Empty-State |
| 10 | `frontend/app/kategorie/[slug]/page.tsx` | Slice 03 | Wave 2 (Slice 03 allein) | `searchParams` prop lesen, URL-Param Validierung |

**Kritische Merge-Anmerkung fuer `SortBarBlock` (Slice 01 → Slice 05 Modifikation):**

Slice 05 erweitert `SortBarData` um `currentQuery?: string` und modifiziert `sort-bar-block.tsx` um den `q`-Param bei URL-Konstruktion zu beruecksichtigen. Diese Aenderung ist rueckwaertskompatibel (optionales Feld), muss aber nach Slice 01's Implementierung erfolgen.

---

## Architecture-Extensions

Alle dokumentierten Abweichungen von `architecture.md` ueber alle 6 Slices:

| # | Slice | Abweichung | Begruendung |
|---|-------|------------|-------------|
| 1 | Slice 01 | `PaginationBlock` nutzt in Slice 1 `content_source: inline` statt `woocommerce` | Slice 1 implementiert nur UI-Logik; ab Slice 3 architekturkonform mit `woocommerce` source |
| 2 | Slice 04 | `TestimonialsData` hat optionales Feld `location?: string` (nicht in architecture.md Zeile 349) | Fuer Testimonials-UX ("— Maria K., Muenchen") notwendig; optionales Feld, non-breaking |
| 3 | Slice 04 | `global.yaml` und `layout.tsx`-Integration ist Deliverable von Slice 04, nicht Slice 01 | Slice 01 implementiert `loadGlobalConfig()`, aber die Aktivierung (global.yaml + layout.tsx) erfolgt in Slice 04 |
| 4 | Slice 06 | `thanks.yaml` enthaelt kein `orderId: "$route.order_id"` Prop (abweichend von architecture.md Zeile 567) | `order_id` wird client-seitig via `window.location.search` in `useEffect` gelesen; serverseitig wuerde SSR Hydration-Mismatch produzieren; `architecture.md` sollte korrigiert werden |
| 5 | Slice 06 | `category_meta` Branch wird sowohl von Slice 03 (fuer Breadcrumb) als auch Slice 06 (fuer CollectionHeader) benoetigt — Reihenfolge der Implementierung kritisch | Slice 03 implementiert den Branch; Slice 06 prueft ob er existiert und ergaenzt ihn ggf. |

---

## Route-Inventory

Alle neuen und modifizierten Routes mit YAML-Datei und Block-Typen:

| Route | Status | YAML-Datei | Block-Typen | Slice |
|-------|--------|-----------|-------------|-------|
| `/` (Homepage) | MODIFY | `themes/default/pages/home.yaml` | + `testimonials`, `featured-collection`, `newsletter-signup` (+ bestehende) | Slice 04 |
| `/produkt/[slug]` | MODIFY | `themes/default/pages/product.yaml` | + `trust-badges`, `product-reviews`, `product-recommendations` (+ bestehende) | Slice 02 |
| `/kategorie/[slug]` | MODIFY | `themes/default/pages/category.yaml` | + `breadcrumb`, `sort-bar`, `pagination`, `empty-state` (+ bestehende) | Slice 03 |
| `/suche` | NEU | `themes/default/pages/search.yaml` | `search-bar`, `product-count`, `sort-bar`, `search-results`, `pagination`, `empty-state` | Slice 05 |
| `/kollektion/[slug]` | NEU | `themes/default/pages/collection.yaml` | `collection-header`, `product-count`, `product-grid`, `pagination` | Slice 06 |
| `/danke` | NEU | `themes/default/pages/thanks.yaml` | `order-confirmation` | Slice 06 |
| `not-found` | NEU | Kein YAML (hardcoded JSX) | `<h1>`, 2 CTA-Links, noindex metadata | Slice 06 |
| `(global)` | NEU | `themes/default/pages/global.yaml` | `announcement-bar` | Slice 04 |

---

## Validation Results

### Valid Connections: 32

Alle 32 deklarierten Dependencies haben passende Outputs in vorherigen Slices. Alle Source-Slices sind APPROVED.

### Orphaned Outputs: 0

Alle Outputs werden von mindestens einem Consumer genutzt. Outputs fuer "zukuenftige Slices" (z.B. `SearchBarBlock` ggf. fuer Slice 06) sind als finale User-facing Outputs markiert und stehen bereit.

### Missing Inputs: 0

Kein Input ohne passenden Producer identifiziert.

### Deliverable-Consumer Gaps: 0

Alle Cross-Slice-Verbindungen wurden geprueft:

- `TrustBadgesBlock` (Slice 01) → `themes/default/pages/product.yaml` (Slice 02 Deliverable): VALID
- `BreadcrumbBlock` (Slice 01) → `themes/default/pages/category.yaml` (Slice 03 Deliverable): VALID
- `PaginationBlock` (Slice 01) → `search.yaml` (Slice 05 Deliverable), `collection.yaml` (Slice 06 Deliverable): VALID
- `AnnouncementBarBlock` (Slice 01) → `global.yaml` (Slice 04 Deliverable) → `app/layout.tsx` (Slice 04 Deliverable): VALID
- `GET_PRODUCTS_PAGINATED` (Slice 03) → `data-loaders.ts` (Slice 04/05/06 Deliverables): VALID
- `category_meta` Branch (Slice 03) → `collection.yaml` (Slice 06 Deliverable): VALID

---

## Discovery Traceability

### Block-Inventory Coverage

| Block-Typ | Discovery-Anforderung | Abgedeckt In | Status |
|-----------|----------------------|--------------|--------|
| `announcement-bar` | Announcement Bar global | Slice 01 (Block), Slice 04 (global.yaml + layout) | VALID |
| `breadcrumb` | Breadcrumb-Navigation auf Kategorie-Page | Slice 01 (Block), Slice 03 (category.yaml) | VALID |
| `trust-badges` | Trust-Signale auf Produktseite | Slice 01 (Block), Slice 02 (product.yaml) | VALID |
| `pagination` | Klassische Seitenpagination | Slice 01 (Block), Slice 03 (category), Slice 05 (suche), Slice 06 (kollektion) | VALID |
| `sort-bar` | Sortierung nach Preis/Neuheit | Slice 01 (Block), Slice 03 (category), Slice 05 (suche) | VALID |
| `empty-state` | Empty State bei 0 Produkten | Slice 01 (Block), Slice 03 (category), Slice 05 (suche) | VALID |
| `product-reviews` | Sterne-Summary + Liste + Formular | Slice 02 | VALID |
| `product-recommendations` | Cross-sell Grid | Slice 02 | VALID |
| `testimonials` | Social Proof auf Homepage | Slice 04 | VALID |
| `newsletter-signup` | E-Mail-Capture | Slice 04 | VALID |
| `featured-collection` | Kuratierte Produkt-Praesentation | Slice 04 | VALID |
| `search-bar` | Suchformular | Slice 05 | VALID |
| `search-results` | Suchergebnis-Grid | Slice 05 | VALID |
| `collection-header` | Collections-Page Header | Slice 06 | VALID |
| `order-confirmation` | Bestellbestaetigung | Slice 06 | VALID |

**Block Coverage: 15/15 (100%)**

### Route Coverage

| Route | Discovery-Anforderung | Abgedeckt In | Status |
|-------|----------------------|--------------|--------|
| `/suche` | Suchseite | Slice 05 | VALID |
| `/kollektion/[slug]` | Collections | Slice 06 | VALID |
| `/danke` | Order-Bestaetigung | Slice 06 | VALID |
| `not-found` | 404-Page | Slice 06 | VALID |

**Route Coverage: 4/4 (100%)**

### User Flow Coverage

| Flow | Abgedeckt Von | Status |
|------|---------------|--------|
| Suche: Eingabe → Ergebnisse → Produkt | Slice 05 (SearchBar, SearchResults, Pagination) | VALID |
| Suche: Leere Query → Empty State | Slice 05 (EmptyStateBlock) | VALID |
| Reviews: Anzeige | Slice 02 (ProductReviewsBlock, ReviewList) | VALID |
| Reviews: Formular schreiben → absenden | Slice 02 (ReviewForm, WRITE_REVIEW Mutation) | VALID |
| Reviews: Fehler → Toast | Slice 02 (Error Handling) | VALID |
| Recommendations: related/category/custom/bestsellers | Slice 02 (ProductRecommendationsBlock, Fallback-Chain) | VALID |
| Collections: Header + Grid + Pagination | Slice 06 (CollectionHeader, product-grid, pagination) | VALID |
| Order-Bestaetigung: Bestellnummer aus URL | Slice 06 (OrderConfirmationBlock, useEffect) | VALID |
| Pagination: Seite wechseln mit sort behalten | Slice 03 + Slice 01 (buildPageUrl) | VALID |
| Sortierung: Preis asc/desc, Neueste | Slice 03 (buildOrderby) + Slice 05 | VALID |
| Announcement Bar: Anzeigen + Dismiss | Slice 01 + Slice 04 | VALID |
| Homepage: Testimonials + Featured Collection + Newsletter | Slice 04 | VALID |

**Flow Coverage: 12/12 (100%)**

---

## Summary

| Metrik | Wert |
|--------|------|
| Gesamt Slices | 6 |
| Approved Slices | 6 |
| Gesamt Connections | 32 |
| Valid Connections | 32 |
| Orphaned Outputs | 0 |
| Missing Inputs | 0 |
| Deliverable-Consumer Gaps | 0 |
| Neue Block-Typen | 15 |
| Neue/modifizierte Routes | 8 |
| Neue GraphQL Queries | 6 |
| Neue GraphQL Mutations | 1 |
| Shared Deliverables | 10 |
| Architecture-Abweichungen | 5 (alle dokumentiert und begruendet) |
| Discovery Coverage | 100% |

**Verdict: READY FOR ORCHESTRATION**

Alle 6 Slices sind APPROVED. Keine Missing Inputs. Keine ungeklaerten Orphaned Outputs. Discovery Coverage 100%. Alle Architecture-Abweichungen sind dokumentiert und begruendet.
