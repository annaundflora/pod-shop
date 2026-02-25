# Feature: Shop Completeness — Blocks & Pages

**Epic:** Phase 1 — Shop verkaufsbereit machen
**Status:** Ready
**Discovery:** `discovery.md` (same folder)
**Derived from:** Discovery constraints, NFRs, and risks

---

## Problem & Solution

**Problem:**
- 11 Block-Typen + 5 Page-Routes existieren — aber kritische Elemente fehlen fuer einen vollstaendigen, verkaufsreifen Shop
- Keine Pagination, Sortierung, Suche, Reviews, 404, Collections, Cross-sell, Announcement Bar

**Solution:**
- 15 neue Block-Typen implementieren (Homepage, Produkt, Kategorie, Cross-Page)
- 4 neue Page-Routes ergaenzen (Suche, Collections, Danke, 404)
- Alle Bloecke YAML-konfigurierbar nach bestehendem Block-System-Pattern

**Business Value:**
- Launch-Readiness: Shop erfuellt Mindestanforderungen fuer echte Kunden
- Conversion +15-30% durch Social Proof (Reviews, Trust Badges)
- AOV +20-35% durch Cross-sell / Product Recommendations
- Bounce Rate -20% durch Suche und Collections-Navigation
- SEO: Klassische Pagination indexierbar

---

## Scope & Boundaries

| In Scope |
|----------|
| 15 neue Block-Typen (YAML-konfigurierbar, Block-System-konform) |
| 4 neue Page-Routes: `/suche`, `/kollektion/[slug]`, `/danke`, `not-found` |
| Erweiterung bestehender Pages per YAML (keine Code-Aenderungen an bestehenden Blocks) |
| Suche via WooCommerce/WPGraphQL (kein Meilisearch) |
| Produktbewertungen via WooCommerce (Sterne-Summary + Liste + Formular) |
| Product Recommendations Block (konfigurierbare Quelle + Ueberschrift) |
| Klassische Seitenpagination (SEO-kompatibel) |
| Collections via WooCommerce Product Categories |
| Order-Bestaetigungsseite (`/danke`) mit URL-Param-Bestellnummer |

| Out of Scope |
|--------------|
| Meilisearch / externe Suchinfrastruktur |
| Blog / Content-Pages |
| Wishlist / Merkliste |
| Recently Viewed Products (localStorage-basiert) |
| Countdown-Timer / Flash Sales |
| Instagram / Social Feed Block |
| User Accounts (Slice 7, separates Feature) |
| Bewertungs-Import von externen Plattformen (Trustpilot etc.) |
| Facetten-Filter auf Kategorieseite (Farbe, Groesse, Preis) |
| Newsletter Backend-Integration (Mailchimp/WC) — nur UI |

---

## API Design

### Overview

| Aspect | Specification |
|--------|---------------|
| Style | GraphQL (WPGraphQL + WooGraphQL) — same as existing |
| Authentication | Unauthenticated reads (RSC Server Client). Authenticated mutations via WooCommerce Session Token (Client Apollo) |
| Rate Limiting | None (WordPress-level, no custom) |

### New GraphQL Queries

| Query | Variables | Response Fields | Used By |
|-------|-----------|-----------------|---------|
| `GET_PRODUCTS_PAGINATED` | `first: Int!, categorySlug: String, search: String, orderby: [ProductsOrderbyInput]` | `products { nodes { ...ProductCardFields } pageInfo { hasNextPage endCursor } }` | search-results, product-grid (paginated), pagination |
| `GET_PRODUCT_REVIEWS` | `productSlug: ID!` | `product { averageRating reviewCount reviewsAllowed reviews { edges { rating node { id content date author { node { name } } } } } }` | product-reviews |
| `GET_RELATED_PRODUCTS` | `productId: ID!, first: Int` | `product { related(first: $first) { nodes { ...ProductCardFields } } }` | product-recommendations (source: related) |
| `GET_BESTSELLER_PRODUCTS` | `first: Int` | `products(where: { orderby: [{ field: TOTAL_SALES, order: DESC }] }) { nodes { ...ProductCardFields } }` | product-recommendations (source: bestsellers) |
| `GET_PRODUCTS_BY_IDS` | `include: [Int!]!, first: Int` | `products(where: { include: $include }) { nodes { ...ProductCardFields } }` | product-recommendations (source: custom) |
| `GET_CATEGORY_META` | `slug: ID!` | `productCategory { name description slug count image { sourceUrl altText } }` | collection-header, featured-collection, breadcrumb, pagination (totalCount) |

### New GraphQL Mutations

| Mutation | Variables | Response Fields | Used By |
|----------|-----------|-----------------|---------|
| `WRITE_REVIEW` | `productId: Int!, content: String!, author: String!, authorEmail: String!, rating: Int!` | `writeReview { rating review { id content date } }` | product-reviews (submit form) |

### Data Transfer Objects (DTOs)

| DTO | Fields | Validation | Notes |
|-----|--------|------------|-------|
| `PaginatedProductsResult` | `products: ProductCardData[], currentPage: number, totalPages: number, hasNextPage: boolean, totalCount: number` | page >= 1, perPage 1-100 | Composite DTO built in data-loader from GraphQL response |
| `ProductReviewsResult` | `averageRating: number, reviewCount: number, reviewsAllowed: boolean, reviews: ReviewEdge[]` | — | Maps to WooGraphQL product.reviews response |
| `ReviewEdge` | `rating: number, node: { id: string, content: string, date: string, authorName: string }` | — | Note: `rating` is on edge, not node (WooGraphQL quirk) |
| `WriteReviewInput` | `productId: number, content: string, author: string, authorEmail: string, rating: number` | author min 2 chars, email valid format, rating 1-5 (Int), content min 10 chars | Frontend validates before mutation |
| `SearchParams` | `q: string, page: number, sort: SortOption, perPage: number` | q min 2 chars for query execution, page >= 1, sort enum | Parsed from URL searchParams |
| `SortOption` | `'default' \| 'price_asc' \| 'price_desc' \| 'newest'` | Must be one of the 4 values | Maps to GraphQL orderby enums |

---

## Database Schema

> Kein neues DB-Schema. Alle Daten kommen aus WooCommerce (Produkte, Kategorien, Reviews) und WordPress (Pages). Keine eigenen Tabellen noetig.

| System | Table | Purpose | Used By |
|--------|-------|---------|---------|
| WooCommerce | `wp_comments` (type: `review`) | Product reviews (Sterne, Text, Author) | product-reviews Block |
| WooCommerce | `wp_commentmeta` (`rating` key) | Review-Bewertung (1-5 Int) | product-reviews Block |
| WooCommerce | `wp_terms` / `wp_term_taxonomy` | Product categories (Collections) | collection-header, featured-collection |
| WooCommerce | `wp_postmeta` | Product meta (averageRating, reviewCount) | product-reviews Summary |

---

## Server Logic

### Services & Processing

| Service | Responsibility | Input | Output | Side Effects |
|---------|----------------|-------|--------|--------------|
| `woocommerceLoader` (extended) | Dispatch paginated product queries with sort/search | `query`, `slug`, `first`, `page`, `perPage`, `search`, `sort`, `orderby` | `PaginatedProductsResult` | None (read-only) |
| `woocommerceLoader` (reviews) | Fetch product reviews + summary | `query: 'product_reviews'`, `slug` | `ProductReviewsResult` | None (read-only) |
| `woocommerceLoader` (recommendations) | Fetch recommendations by source type with fallback chain | `query: 'product_recommendations'`, `source`, `productSlug`, `first`, `customIds?` | `{ products: { nodes: ProductCardData[] } }` | None (read-only) |
| `woocommerceLoader` (category_meta) | Fetch category metadata (for collection-header, breadcrumb) | `query: 'category_meta'`, `slug` | `{ name, description, slug, count, image }` | None (read-only) |
| Review Mutation Handler | Submit review via writeReview mutation | `WriteReviewInput` | `{ success: boolean, review?: ReviewEdge }` | Creates WP comment (status: pending) |

### Business Logic Flow

```
Page Request → page.tsx reads searchParams (page, sort, q)
  → loadPageConfig(pageType, theme, { slug, page, sort, q })
    → resolveParams replaces $route.* with URL values
  → SectionRenderer iterates sections
    → For each block: loadBlockData(content_source, resolved_params)
      → woocommerceLoader dispatches based on query type
        → GraphQL query with pagination/sort/search args
      → Returns typed data to block component
    → Block component renders with data

Review Submit → Client Component form
  → apolloClient.mutate(WRITE_REVIEW, { variables })
  → On success: Show confirmation, hide form
  → On error: Show error toast, keep form open
```

### Product Recommendations Fallback Chain (in data-loader)

```
Input: source (from YAML), productSlug, first (default: 4), customIds?

Fallback Chain (Discovery requirement):
1. Try source from YAML param (e.g. "related"):
   - "related"     → GET_RELATED_PRODUCTS(productSlug, first)
   - "category"    → GET_PRODUCTS_PAGINATED(categorySlug from product, first) excluding current product
   - "bestsellers" → GET_BESTSELLER_PRODUCTS(first)
   - "custom"      → GET_PRODUCTS_BY_IDS(customIds, first)

2. If result is empty (0 products returned):
   - "related" empty → Fallback to "category" (same category, excluding current product)
   - "category" empty → Block renders nothing (returns null, no empty container)
   - "bestsellers" empty → Block renders nothing
   - "custom" empty → Block renders nothing

3. If fallback "category" also empty → Block renders nothing (hidden)

Decision tree:
  source=related → query related → has results? → render
                                  → no results? → query category → has results? → render
                                                                   → no results? → return null (block hidden)
  source=category/bestsellers/custom → query → has results? → render
                                              → no results? → return null (block hidden)
```

### Pagination Logic (in data-loader)

```
Input: page (1-indexed), perPage (default: 24), total (from category.count or response)

Strategy: Over-fetch + slice (pragmatic for MVP with <200 products/category)

1. fetchCount = page * perPage + 1       // +1 to detect hasNextPage
2. GraphQL: products(first: fetchCount, where: { ... })
3. allNodes = response.products.nodes
4. pageNodes = allNodes.slice((page - 1) * perPage, page * perPage)
5. hasNextPage = allNodes.length > page * perPage
6. totalPages = category.count available? Math.ceil(count / perPage) : inferred from allNodes.length

Return: { products: { nodes: pageNodes }, currentPage: page, totalPages, hasNextPage, totalCount }
```

### Sort Mapping (in data-loader)

| URL Param | GraphQL Orderby | Label (German) |
|-----------|----------------|----------------|
| (none) | — (WooCommerce default) | Empfohlen |
| `price_asc` | `[{ field: PRICE, order: ASC }]` | Preis: aufsteigend |
| `price_desc` | `[{ field: PRICE, order: DESC }]` | Preis: absteigend |
| `newest` | `[{ field: DATE, order: DESC }]` | Neueste zuerst |

### Search Logic (in data-loader)

```
Input: q (search term), page, perPage, sort

1. If q.length < 2: return empty result (no query executed)
2. GraphQL: products(first: page * perPage + 1, where: { search: q, orderby: sortMapping })
3. totalCount: Separate lightweight query: products(first: 500, where: { search: q }) → nodes.length
   (Acceptable for MVP: <200 total products, ISR-cached for 60s)
4. Slice + paginate same as category pagination
```

### Validation Rules

| Field | Rule | Error Message |
|-------|------|---------------|
| `reviewer_name` | Min 2 chars, required | "Bitte gib deinen Namen ein (min. 2 Zeichen)" |
| `reviewer_email` | Valid email format, required | "Bitte gib eine gueltige E-Mail-Adresse ein" |
| `rating` | Required, 1-5 integer | "Bitte waehle eine Bewertung (1-5 Sterne)" |
| `review_content` | Min 10 chars, required | "Bitte schreibe mindestens 10 Zeichen" |
| `search_query` | Min 2 chars for query execution | — (no error, just no results) |
| `page` | Integer >= 1 | Invalid → redirect to page 1 |
| `sort` | Must be valid SortOption or empty | Invalid → ignore, use default |

---

## Security

### Authentication & Authorization

| Area | Mechanism | Notes |
|------|-----------|-------|
| Product queries (read) | Unauthenticated | Public data, RSC Server Client (no session token) |
| Review read | Unauthenticated | Public reviews visible to all |
| Review write | Unauthenticated (guest review) | WooCommerce allows guest reviews by default. Author name + email required. Review created with status `pending` (moderation required) |
| Search | Unauthenticated | Public search, no auth needed |
| Order confirmation | URL params only | `order_id` + `key` in URL. No API call to fetch order details in MVP — only displays order ID from URL |

### Data Protection

| Data Type | Protection | Notes |
|-----------|------------|-------|
| Review author email | Not displayed in UI | Fetched by WooCommerce but not exposed in review list query response. Only used for moderation notification. |
| Order key (URL param) | Short-lived, non-sensitive | WooCommerce order key is a hash, not the actual order data. MVP displays only order ID. |

### Input Validation & Sanitization

| Input | Validation | Sanitization |
|-------|------------|--------------|
| Search query `q` | Min 2 chars, max 100 chars | WPGraphQL uses `sanitize_text_field()` on search input. No XSS risk in GraphQL variable. |
| Review content | Min 10 chars, max 5000 chars | WooCommerce sanitizes comment content via `wp_kses_post()`. HTML stripped on write. |
| Review author | Min 2 chars, max 100 chars | `sanitize_text_field()` |
| Review email | Valid email format | `sanitize_email()` |
| Review rating | Integer 1-5 | GraphQL schema enforces `Int!`, WooCommerce validates range |
| URL params (page, sort) | Type check + allowed values | Invalid values → defaults. No injection possible (not used in queries directly). |

### Rate Limiting & Abuse Prevention

| Resource | Limit | Window | Penalty |
|----------|-------|--------|---------|
| GraphQL endpoint | WordPress default (no custom limit) | — | Standard WP rate limiting |
| Review submission | WordPress duplicate check | Per content hash | "Duplicate comment" error from WP |
| Search queries | No custom limit | — | ISR cache (60s) reduces server load |

---

## Architecture Layers

### Layer Responsibilities

| Layer | Responsibility | Pattern |
|-------|----------------|---------|
| Page Routes (`app/*/page.tsx`) | Read URL params, load page config, provide skeleton maps, render SectionRenderer | Server Component (RSC) with ISR |
| Block Components (`components/blocks/`) | Render typed data as UI, export skeleton for Suspense | Server or Client Component (per block) |
| Section Renderer (`lib/blocks/section-renderer.tsx`) | Iterate sections, resolve blocks, wrap in Suspense, coordinate layout | Server Component |
| Data Loaders (`lib/blocks/data-loaders.ts`) | Dispatch GraphQL queries based on content_source + params, transform response | Async functions called by BlockRenderer |
| GraphQL Queries (`lib/graphql/queries.ts`) | Define GraphQL query strings | Constants |
| GraphQL Mutations (`lib/graphql/mutations.ts`) | Define GraphQL mutation strings for reviews | Constants |
| Block Registry (`lib/blocks/registry.ts`) | Map block type string → React component | Registry pattern |
| Page Config (`lib/blocks/page-config.ts`) | Load YAML, resolve params, return PageConfig | 3-tier template override |
| Global Config Loader (NEW) | Load `global.yaml`, inject global blocks (announcement-bar) into layout | Called from `layout.tsx` |

### Data Flow

```
Browser Request → Next.js ISR → page.tsx (RSC)
  → reads searchParams: { page, sort, q }
  → loadPageConfig(pageType, theme, { slug, page, sort, q })
  → <SectionRenderer sections={config.sections}>
      → resolveBlock(block.type) → Component from registry
      → loadBlockData(content_source, params)
          → woocommerceLoader / wordpressLoader / inlineLoader
              → getClient().query(GRAPHQL_QUERY, variables)
              → transform response (pagination slice, sort mapping)
          → return typed data
      → <Suspense fallback={skeleton}>
          <BlockComponent data={data} />
        </Suspense>

Review Submit (Client-Side):
  Browser → Client Component → apolloClient.mutate(WRITE_REVIEW)
    → WooCommerce creates wp_comment (status: pending)
    → Response → UI update (success/error state)
```

### Error Handling Strategy

| Error Type | Handling | User Response | Logging |
|------------|----------|---------------|---------|
| GraphQL network error | Data loader returns `{ data: null, error: string }` | Block renders null (invisible) or empty-state | `console.error` in data-loader |
| Product not found | `notFound()` in page.tsx | Next.js 404 page | — |
| Invalid page param | Redirect to page 1 | Automatic redirect | — |
| Invalid sort param | Ignore, use default | Default sort applied silently | — |
| Empty search (`q.length < 2`) | No query executed | Show placeholder/bestsellers | — |
| Review mutation error | GraphQL error response | Toast: "Bewertung konnte nicht gespeichert werden" | `console.error` |
| Duplicate review | WP duplicate check error | Toast: "Diese Bewertung wurde bereits eingereicht" | — |
| Category empty (0 products) | Data loader returns empty array | `empty-state` block shown instead of grid | — |
| Search no results | Data loader returns empty array | `empty-state` block shown | — |

---

## New Block Components

### Block Inventory (15 new blocks)

| # | Block Type | Server/Client | Content Source | Data Type | Skeleton |
|---|-----------|---------------|----------------|-----------|----------|
| 1 | `announcement-bar` | Client | `inline` | `AnnouncementBarData` | `null` (inline) |
| 2 | `breadcrumb` | Server | `woocommerce` / `inline` | `BreadcrumbData` | `null` (lightweight) |
| 3 | `trust-badges` | Server | `inline` | `TrustBadgeData` | `null` (inline) |
| 4 | `pagination` | Server | `woocommerce` | `PaginatedProductsResult` | `null` (lightweight) |
| 5 | `sort-bar` | Client | `inline` | `SortBarData` | `null` (inline) |
| 6 | `empty-state` | Server | `inline` | `EmptyStateData` | `null` (inline) |
| 7 | `product-reviews` | Client | `woocommerce` | `ProductReviewsResult` | `ProductReviewsBlockSkeleton` |
| 8 | `product-recommendations` | Server | `woocommerce` | `{ products: { nodes: ProductCardData[] } }` | `ProductGridBlockSkeleton` (reuse) |
| 9 | `testimonials` | Server | `inline` | `TestimonialsData` | `null` (inline) |
| 10 | `newsletter-signup` | Client | `inline` | `NewsletterSignupData` | `null` (inline) |
| 11 | `featured-collection` | Server | `woocommerce` | `FeaturedCollectionData` | `FeaturedCollectionBlockSkeleton` |
| 12 | `search-bar` | Client | `inline` | `SearchBarData` | `null` (inline) |
| 13 | `search-results` | Server | `woocommerce` | `PaginatedProductsResult` | `ProductGridBlockSkeleton` (reuse) |
| 14 | `collection-header` | Server | `woocommerce` | `CollectionHeaderData` | `CollectionHeaderBlockSkeleton` |
| 15 | `order-confirmation` | Client | `inline` | `OrderConfirmationData` | `null` (inline) |

### Block Data Type Definitions

| Type | Fields | Source |
|------|--------|--------|
| `AnnouncementBarData` | `id: string, text: string, link?: string, dismissible?: boolean, bgColor?: string` | YAML inline |
| `BreadcrumbData` | `items: { label: string, href?: string }[]` | Derived from route context or inline |
| `TrustBadgeData` | `items: { icon: string, text: string }[]` | YAML inline (same pattern as usp-bar) |
| `SortBarData` | `currentSort: SortOption, baseUrl: string` | YAML inline + $route.sort |
| `EmptyStateData` | `headline: string, text: string, links?: { label: string, href: string }[]` | YAML inline |
| `TestimonialsData` | `items: { name: string, text: string, rating: number }[]` | YAML inline |
| `NewsletterSignupData` | `headline: string, text: string, buttonText: string, placeholder: string` | YAML inline |
| `SearchBarData` | `placeholder: string, currentQuery?: string` | YAML inline + $route.q |
| `FeaturedCollectionData` | `category: { name, description, slug, image }, products: { nodes: ProductCardData[] }` | GraphQL: category_meta + products_by_category |
| `CollectionHeaderData` | `name: string, description: string, image?: { sourceUrl, altText }` | GraphQL: category_meta |
| `OrderConfirmationData` | `headline: string, text: string, emailText: string, ctaText: string, ctaLink: string` | YAML inline (order_id from URL param client-side) |

---

## New Data Loader Query Types

### Extended `woocommerceLoader` Dispatch

| Query Type | Variables | GraphQL Query | Transform | Used By Blocks |
|-----------|-----------|---------------|-----------|----------------|
| `products_by_category` (existing, extended) | `slug, first, page?, perPage?, sort?` | `GET_PRODUCTS_PAGINATED` with `categorySlug` filter | Over-fetch + slice for pagination | product-grid, pagination, product-count |
| `search_products` (new) | `search, first, page?, perPage?, sort?` | `GET_PRODUCTS_PAGINATED` with `search` filter | Over-fetch + slice + totalCount | search-results, pagination |
| `product_reviews` (new) | `slug` | `GET_PRODUCT_REVIEWS` | Map edges to flat review list with rating | product-reviews |
| `product_recommendations` (new) | `source, productSlug, first, customIds?` | Dispatches to `GET_RELATED_PRODUCTS` / `GET_PRODUCTS_PAGINATED` (category) / `GET_BESTSELLER_PRODUCTS` / `GET_PRODUCTS_BY_IDS` | Returns ProductCardData array | product-recommendations |
| `category_meta` (new) | `slug` | `GET_CATEGORY_META` | Returns category metadata | collection-header, featured-collection, breadcrumb |
| `featured_collection` (new) | `slug, first` | `GET_CATEGORY_META` + `GET_PRODUCTS_PAGINATED` (categorySlug) | Combines category meta + products | featured-collection |

### Pagination Data Contract

All paginated queries return enriched response:

```
{
  products: { nodes: ProductCardData[] },    // Current page only (sliced)
  pagination: {
    currentPage: number,                      // From URL param
    totalPages: number,                       // Calculated
    hasNextPage: boolean,                     // From over-fetch detection
    hasPreviousPage: boolean,                 // currentPage > 1
    totalCount: number                        // From category.count or response length
  }
}
```

---

## New Page Routes

### Route Configuration

| Route | Page File | YAML Config | ISR | searchParams | routeParams |
|-------|-----------|-------------|-----|-------------|-------------|
| `/suche` | `app/suche/page.tsx` | `search.yaml` | `revalidate = 60` | `q`, `page`, `sort` | `{ q, page, sort }` |
| `/kollektion/[slug]` | `app/kollektion/[slug]/page.tsx` | `collection.yaml` | `revalidate = 60` | `page`, `sort` | `{ slug, page, sort }` |
| `/danke` | `app/danke/page.tsx` | `thanks.yaml` | static | `order_id`, `key` | `{ order_id }` |
| 404 | `app/not-found.tsx` | — (hardcoded) | static | — | — |

### URL Parameter Handling Pattern

```
page.tsx reads searchParams → passes as routeParams to loadPageConfig
  → resolveParams replaces $route.* in YAML
  → Data loaders receive resolved params
  → Blocks render with resolved data
```

New `$route.*` placeholders needed:

| Placeholder | Source | Used In |
|-------------|--------|---------|
| `$route.page` | `searchParams.page` (default: "1") | search.yaml, collection.yaml, category.yaml |
| `$route.sort` | `searchParams.sort` (default: "") | search.yaml, collection.yaml, category.yaml |
| `$route.q` | `searchParams.q` (default: "") | search.yaml |
| `$route.order_id` | `searchParams.order_id` (default: "") | thanks.yaml |

---

## Global Blocks (Announcement Bar)

### Architecture Decision

**Decision:** New `global.yaml` config file loaded in `layout.tsx`. Global blocks render above main content.

### Implementation

| Component | Responsibility |
|-----------|----------------|
| `themes/default/pages/global.yaml` | Defines global blocks (announcement-bar) |
| `lib/blocks/page-config.ts` | `loadGlobalConfig(theme)` — loads global.yaml, no routeParams needed |
| `app/layout.tsx` | Calls `loadGlobalConfig()`, renders global blocks above `{children}` |
| `announcement-bar` block (Client) | Reads `id` from inline data, checks `localStorage['announcement-dismissed-{id}']`, renders or hides |

### global.yaml Structure

```yaml
sections:
  - columns: 1
    blocks:
      - type: announcement-bar
        content_source: inline
        params:
          props:
            id: "launch-2026"
            text: "Kostenloser Versand ab 100 Euro | Code: SOMMER10"
            link: "/kollektion/sale"
            dismissible: true
            bgColor: "primary"
```

---

## New YAML Page Configs

### `themes/default/pages/search.yaml`

```yaml
sections:
  - columns: 1
    blocks:
      - type: search-bar
        content_source: inline
        params:
          props:
            placeholder: "Was suchst du?"
            currentQuery: "$route.q"
  - columns: 1
    blocks:
      - type: product-count
        content_source: woocommerce
        params:
          query: search_products
          search: "$route.q"
          page: "$route.page"
          perPage: 24
          sort: "$route.sort"
  - columns: 1
    blocks:
      - type: sort-bar
        content_source: inline
        params:
          props:
            currentSort: "$route.sort"
            baseUrl: "/suche"
  - columns: 1
    blocks:
      - type: search-results
        content_source: woocommerce
        params:
          query: search_products
          search: "$route.q"
          page: "$route.page"
          perPage: 24
          sort: "$route.sort"
  - columns: 1
    blocks:
      - type: pagination
        content_source: woocommerce
        params:
          query: search_products
          search: "$route.q"
          page: "$route.page"
          perPage: 24
          sort: "$route.sort"
```

### `themes/default/pages/collection.yaml`

```yaml
sections:
  - columns: 1
    blocks:
      - type: collection-header
        content_source: woocommerce
        params:
          query: category_meta
          slug: "$route.slug"
  - columns: 1
    blocks:
      - type: product-count
        content_source: woocommerce
        params:
          query: products_by_category
          slug: "$route.slug"
          page: "$route.page"
          perPage: 24
          sort: "$route.sort"
  - columns: 1
    blocks:
      - type: product-grid
        content_source: woocommerce
        params:
          query: products_by_category
          slug: "$route.slug"
          page: "$route.page"
          perPage: 24
          sort: "$route.sort"
  - columns: 1
    blocks:
      - type: pagination
        content_source: woocommerce
        params:
          query: products_by_category
          slug: "$route.slug"
          page: "$route.page"
          perPage: 24
          sort: "$route.sort"
```

### `themes/default/pages/thanks.yaml`

```yaml
sections:
  - columns: 1
    blocks:
      - type: order-confirmation
        content_source: inline
        params:
          props:
            headline: "Vielen Dank fuer deine Bestellung!"
            text: "Deine Bestellbestaetigung wurde an deine E-Mail-Adresse gesendet."
            emailText: "Du erhaeltst in Kuerze eine Bestaetigung per E-Mail."
            ctaText: "Weiter einkaufen"
            ctaLink: "/"
            orderId: "$route.order_id"
```

### Extended `themes/default/pages/category.yaml` (additions)

New blocks added to existing category.yaml:

| Position | New Block | Content Source |
|----------|-----------|----------------|
| Before page-heading | `breadcrumb` | `woocommerce: products_by_category` (Apollo cache dedup) |
| After product-count | `sort-bar` | `inline` |
| After product-grid | `pagination` | `woocommerce: products_by_category` (Apollo cache dedup) |

### Extended `themes/default/pages/product.yaml` (additions)

New sections appended:

| Position | New Block | Content Source |
|----------|-----------|----------------|
| After product-description | `trust-badges` | `inline` |
| After trust-badges | `product-reviews` | `woocommerce: product_reviews` |
| After product-reviews | `product-recommendations` | `woocommerce: product_recommendations` |

### Extended `themes/default/pages/home.yaml` (additions)

New optional sections:

| Position | New Block | Content Source |
|----------|-----------|----------------|
| After category-showcase | `testimonials` | `inline` |
| After testimonials | `featured-collection` | `woocommerce: featured_collection` |
| Before usp-bar | `newsletter-signup` | `inline` |

---

## Migration Map

> Existing files that need modification.

| Existing File | Current Pattern | Target Pattern | Specific Changes |
|---|---|---|---|
| `frontend/lib/blocks/registry.ts` | 11 registered blocks | 26 registered blocks | Add 15 new block imports + registrations |
| `frontend/lib/blocks/data-loaders.ts` | 4 WooCommerce query types, 2 WordPress query types | 9 WooCommerce query types, 2 WordPress query types | Add `search_products`, `product_reviews`, `product_recommendations`, `category_meta`, `featured_collection` dispatches. Extend `products_by_category` with page/perPage/sort params. Add pagination slicing logic. |
| `frontend/lib/blocks/types.ts` | `WooCommerceLoaderParams` with `query: 4 types` | Extended with 5 new query types + pagination params | Add `page?: string, perPage?: number, sort?: string, search?: string, source?: string, productSlug?: string, customIds?: string` to WooCommerceLoaderParams. Add `PaginatedProductsResult`, `ProductReviewsResult`, `ReviewEdge` types. |
| `frontend/lib/blocks/page-config.ts` | `loadPageConfig()` | + `loadGlobalConfig()` | Add new function for global.yaml loading (no routeParams) |
| `frontend/lib/graphql/queries.ts` | 8 queries | 14 queries | Add `GET_PRODUCTS_PAGINATED`, `GET_PRODUCT_REVIEWS`, `GET_RELATED_PRODUCTS`, `GET_BESTSELLER_PRODUCTS`, `GET_PRODUCTS_BY_IDS`, `GET_CATEGORY_META` |
| `frontend/lib/graphql/fragments.ts` | 4 fragments | 4 fragments (no change) | Reuse existing `ProductCardFields` fragment for all new product queries |
| `frontend/app/layout.tsx` | No global blocks | Renders global blocks (announcement-bar) | Import `loadGlobalConfig`, render global sections above Header or between Header and children |
| `frontend/app/kategorie/[slug]/page.tsx` | No searchParams reading | Reads `page`, `sort` searchParams | Pass `{ slug, page, sort }` as routeParams to loadPageConfig. Update SKELETON_MAP with new block types. |
| `frontend/app/produkt/[slug]/page.tsx` | No review/recommendation blocks | SKELETON_MAP includes new blocks | Add skeleton entries for trust-badges, product-reviews, product-recommendations |
| `frontend/app/page.tsx` | 4 block types in SKELETON_MAP | 7+ block types in SKELETON_MAP | Add skeleton entries for testimonials, featured-collection, newsletter-signup |
| `frontend/themes/default/pages/category.yaml` | 4 sections (heading, chips, count, grid) | 6 sections (+ breadcrumb, sort-bar, pagination) | Add breadcrumb before heading, sort-bar after count, pagination after grid. Add page/sort params to product queries. |
| `frontend/themes/default/pages/product.yaml` | 2 sections (gallery+purchase, description) | 5 sections (+ trust-badges, reviews, recommendations) | Append 3 new sections |
| `frontend/themes/default/pages/home.yaml` | 4 sections (hero, products, categories, usp) | 7 sections (+ testimonials, featured-collection, newsletter) | Add 3 new sections between existing ones |

### New Files

| New File | Purpose |
|----------|---------|
| `frontend/lib/graphql/mutations.ts` | GraphQL mutation strings (WRITE_REVIEW) |
| `frontend/components/blocks/announcement-bar-block.tsx` | Announcement bar with dismiss (Client Component) |
| `frontend/components/blocks/breadcrumb-block.tsx` | Breadcrumb navigation |
| `frontend/components/blocks/trust-badges-block.tsx` | Trust badges (similar to usp-bar) |
| `frontend/components/blocks/pagination-block.tsx` | Page number pagination |
| `frontend/components/blocks/sort-bar-block.tsx` | Sort dropdown (Client Component) |
| `frontend/components/blocks/empty-state-block.tsx` | Empty state with suggestions |
| `frontend/components/blocks/product-reviews-block.tsx` | Review summary + list + form (Client Component) |
| `frontend/components/blocks/product-recommendations-block.tsx` | Configurable product recommendations |
| `frontend/components/blocks/testimonials-block.tsx` | Customer testimonials |
| `frontend/components/blocks/newsletter-signup-block.tsx` | Newsletter form UI only (Client Component) |
| `frontend/components/blocks/featured-collection-block.tsx` | Category hero + product grid |
| `frontend/components/blocks/search-bar-block.tsx` | Search input (Client Component) |
| `frontend/components/blocks/search-results-block.tsx` | Search result product grid |
| `frontend/components/blocks/collection-header-block.tsx` | Collection hero with image + description |
| `frontend/components/blocks/order-confirmation-block.tsx` | Order confirmation (Client Component) |
| `frontend/app/suche/page.tsx` | Search page route |
| `frontend/app/kollektion/[slug]/page.tsx` | Collection page route |
| `frontend/app/danke/page.tsx` | Thank you page route |
| `frontend/app/not-found.tsx` | Custom 404 page |
| `frontend/themes/default/pages/search.yaml` | Search page block config |
| `frontend/themes/default/pages/collection.yaml` | Collection page block config |
| `frontend/themes/default/pages/thanks.yaml` | Thank you page block config |
| `frontend/themes/default/pages/global.yaml` | Global blocks config (announcement-bar) |

---

## Constraints & Integrations

### Constraints

| Constraint | Technical Implication | Solution |
|------------|----------------------|----------|
| WooGraphQL cursor-based pagination only (no offset) | Cannot directly jump to page N | Over-fetch + slice strategy: `first: page * perPage + 1`, then slice for current page. Acceptable for MVP (<200 products/category) |
| WooGraphQL review `rating` on edges, not nodes | Cannot use simple `reviews.nodes[].rating` | Access via `reviews.edges[].rating` + `reviews.edges[].node` pattern |
| WooGraphQL `writeReview` author bug (logged user override) | Guest reviews may not set author correctly | Test with v0.21.2 (may be fixed). Fallback: Use WPGraphQL core `createComment` mutation + custom rating meta |
| WooGraphQL search: SQL LIKE only | No typo tolerance, no fuzzy matching | Acceptable for MVP. Document migration path to Meilisearch |
| WooGraphQL search + cursor pagination bug | Cursor pagination may produce incorrect results with search | Mitigated: Over-fetch strategy doesn't rely on cursors for page navigation |
| Apollo RSC cache: per-request deduplication | Multiple blocks with same query + variables execute once | Leverage for pagination/sort/search blocks that share query params |
| ISR revalidate = 60 | Pages cached for 60 seconds | Acceptable for product catalog. Reviews show with delay after approval. |
| All UI text must be German | Block labels, error messages, placeholders | Hardcode German strings in components (no i18n framework) |
| All styles must use theme tokens | No hardcoded colors/radii/shadows | Use `bg-primary`, `text-text-primary`, `rounded-card` etc. exclusively |
| Naming convention: `first` (GraphQL) vs `perPage` (YAML/URL) | Existing YAML uses `first: 24` (GraphQL pass-through). New paginated blocks use `perPage: 24` (semantic page size). | Data-loader maps `perPage` to GraphQL `first` internally: `first = page * perPage + 1`. Non-paginated blocks keep using `first` directly. Both are valid in YAML params. |
| `@apollo/experimental-nextjs-app-support` deprecated | Package renamed to `@apollo/client-integration-nextjs` (latest: 0.14.0) | Codebase uses old name (^0.11.11), still functional. Migration to new package name is out of scope (technical debt). No impact on this feature. |
| Apollo Client default timeouts | No custom timeout configuration for GraphQL queries | Apollo Client uses browser/Node.js default timeouts (no explicit timeout set). Over-fetch pagination (up to 192 products) completes within default timeout for WooCommerce (<5s typical). If performance degrades, add `fetchOptions: { signal: AbortSignal.timeout(10000) }` to HttpLink. |

### Integrations

| Area | System / Capability | Interface | Version | Notes |
|------|----------------------|-----------|---------|-------|
| Frontend Framework | Next.js | App Router, RSC, ISR | 16.1.6 (package.json: ^16.1.6) | Stable LTS, Feb 2026 |
| React | React | Server/Client Components | 19.0.0 (package.json: ^19.0.0) | React.cache() for RSC dedup |
| GraphQL Client | @apollo/client | useQuery, useMutation, getClient | 4.1.4 (package.json: ^4.1.4) | Server + Client instances |
| WordPress | WordPress | Headless CMS | 6.9-php8.2 (docker-compose.yml) | Reviews stored as wp_comments |
| WooCommerce | WooCommerce | Products, Categories, Reviews, Cart | >=10.0 required (plugin header), latest stable: 10.5.2 | Review moderation via WC admin |
| WPGraphQL | WPGraphQL | GraphQL API for WordPress | 2.9.0 (WordPress.org, Feb 2026) | Comments/reviews exposed via schema |
| WooGraphQL | WPGraphQL for WooCommerce | Product queries, writeReview mutation | 0.21.2 (setup.sh GitHub release URL) | Review rating on edges, not nodes |
| CSS Framework | Tailwind CSS | Utility-first CSS, CSS-first config | 4.0.0 (package.json: ^4.0.0) | Theme tokens via @theme block in globals.css |
| Icons | lucide-react | Icon components | 0.575.0 (package.json) | Used in trust-badges, breadcrumb, empty-state |
| YAML Parser | yaml (npm) | YAML config parsing | 2.7.0 (package.json) | Page configs + theme configs |
| Testing | Vitest | Unit + component tests | 3.0.0 (package.json: ^3.0.0) | Frontend test runner |
| PHP | PHP | Server runtime | >=8.2 (composer.json) | WordPress + plugins |
| PHPUnit | PHPUnit | PHP test runner | 11.0 (composer.json) | Root-level tests |
| Database | MySQL | Data storage | 8.0 (docker-compose.yml) | WooCommerce data store |

---

## Quality Attributes (NFRs)

### From Discovery → Technical Solution

| Attribute | Target | Technical Approach | Measure / Verify |
|-----------|--------|--------------------|------------------|
| Page Load Performance | LCP < 2.5s on mobile (Pinterest traffic) | ISR (revalidate=60), RSC for all read-only blocks, Suspense streaming, skeleton fallbacks | Lighthouse CI, Core Web Vitals |
| SEO Pagination | Each page independently indexable | `?page=N` URL params, no JavaScript-dependent pagination, `<link rel="next/prev">` tags | Google Search Console, manual crawl check |
| Mobile Usability | Min 44px touch targets, no horizontal scroll | `--min-touch-target: 2.75rem` (existing), `touch-action: manipulation`, responsive grid | Lighthouse accessibility audit |
| Search Performance | < 500ms for search results | WooCommerce SQL LIKE query (adequate for <200 products), ISR cache | Server-side timing logs |
| Review Submission | Feedback within 2s | Apollo mutation with optimistic UI feedback (loading spinner → success/error) | Manual testing |
| Cache Efficiency | Minimize redundant GraphQL calls | Apollo InMemoryCache deduplication for identical queries in same request | Network tab verification |
| Accessibility | WCAG 2.1 AA | Semantic HTML (nav, main, section), aria-labels on interactive elements, focus management on pagination/sort/search | axe-core audit, keyboard navigation test |

### Monitoring & Observability

| Metric | Type | Target | Alert |
|--------|------|--------|-------|
| ISR cache hit rate | Counter | > 95% | — |
| GraphQL query errors | Counter | < 1% of requests | Error spike |
| Review submission success rate | Counter | > 95% | Drop below 80% |
| Search empty result rate | Gauge | Monitor trend | — (informational) |

---

## Risks & Assumptions

### Assumptions

| Assumption | Technical Validation | Impact if Wrong |
|------------|---------------------|-----------------|
| WooGraphQL `writeReview` works for guest users in v0.21.2 | Test mutation with anonymous author in dev environment | Fallback: Use WPGraphQL `createComment` + custom rating meta |
| WooGraphQL `product.related` field exists and returns products | Test query in GraphiQL IDE | Fallback: Use `products_by_category` with same category slug |
| Over-fetch pagination strategy is fast enough for <200 products | Benchmark page 8 load time (192 products fetched) | Fallback: Implement cursor caching per page in ISR or switch to WP REST API for pagination |
| Apollo RSC cache deduplicates identical queries in same request | Verify with network logging that pagination/grid/count blocks don't triple-fetch | If no dedup: refactor to shared data loading at page level |
| WooCommerce product categories have images uploaded | Check WC admin data | Fallback: Collection-header and featured-collection render without image (graceful degradation) |
| `category.count` accurately reflects published product count | Compare count vs actual products query result | Fallback: Use response length for totalPages calculation |

### Risks & Mitigation

| Risk | Likelihood | Impact | Technical Mitigation | Fallback |
|------|------------|--------|---------------------|----------|
| WooGraphQL `writeReview` author bug not fixed in v0.21.2 | Medium | Medium | Test early in Slice 2. If broken: switch to `createComment` mutation | `createComment` + custom meta for rating |
| Pagination over-fetch too slow for large catalogs (>500 products) | Low (MVP <200) | High | Monitor page load times. Document migration path to offset plugin | Reduce perPage, implement infinite scroll, or WP REST API fallback |
| WooGraphQL search + sort combination produces wrong order | Medium | Medium | Test all sort options with search query | Remove sort from search (use WC default relevance) |
| Global blocks (announcement-bar) in layout.tsx cause hydration mismatch | Low | Medium | Use `suppressHydrationWarning` for localStorage-dependent dismiss state. Client Component with useEffect for initial state. | Render announcement-bar only client-side |
| Pinterest CAPI checkout event on /danke fires without actual order | Low | Low | Only fire if `order_id` URL param is present AND non-empty | Accept minor tracking inaccuracy for MVP |
| Review spam | Medium | Low | WooCommerce review moderation (status: pending). WordPress duplicate comment check. | Add CAPTCHA in future iteration |

---

## Technology Decisions

### Stack Choices

| Area | Technology | Rationale |
|------|------------|-----------|
| Pagination | Over-fetch + slice (no plugin) | Simple, no additional WordPress plugin dependency. Adequate for MVP product count (<200). Avoids cursor pagination bugs with search/sort. |
| Reviews | WooGraphQL `writeReview` mutation | Native WooCommerce integration. Rating field built-in. Review moderation via existing WC admin. |
| Search | WooCommerce SQL LIKE via WPGraphQL | No external service (Meilisearch) needed for MVP. Adequate for <200 products. Easy migration path via data-loader swap. |
| Collections | WooCommerce Product Categories | Already queried, have image + description + count. No new data model. Reuses existing `products_by_category` query. |
| Global Blocks | New `global.yaml` + layout.tsx | Single config point. Follows Shopify global sections pattern. Avoids duplicating announcement-bar in every page YAML. |
| 404 Page | Next.js `not-found.tsx` (hardcoded) | Convention-based. No YAML overhead for a static error page. |
| Newsletter | UI only (no backend) | Out of scope per Discovery. Frontend form + validation. Backend integration (Mailchimp/WC) is separate feature. |
| Sort State | URL params (`?sort=price_asc`) | SEO-friendly. Bookmarkable. No client state management needed. Server-rendered via ISR. |
| Search State | URL params (`?q=shirt&page=2`) | SEO-friendly. Shareable URLs. Triggers server-side data fetching via ISR. |

### Trade-offs

| Decision | Pro | Con | Mitigation |
|----------|-----|-----|------------|
| Over-fetch pagination | Simple, no plugins, no cursor bugs | Wastes bandwidth for later pages (page 8 = 192 products fetched) | Acceptable for MVP (<200 products). ISR caches response for 60s. Monitor and migrate to offset plugin if catalog grows. |
| `writeReview` vs `createComment` | Native WC integration, includes rating | Known bugs in older versions, rating on edges not nodes | Test in dev. Fallback to `createComment` ready. Rating accessed via edges pattern. |
| Global YAML vs per-page YAML for announcement | Single config, DRY | New loading mechanism in layout.tsx | Minimal complexity: one new function (`loadGlobalConfig`), one new YAML file. |
| Search: separate count query for totalPages | Accurate total count for pagination numbers | Extra GraphQL call for search result count | Only for search (categories use `category.count`). Lightweight query (IDs only). ISR cached. |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| 1 | Collections: WooCommerce Kategorien oder Product Tags? | A) Kategorien B) Tags | A) Kategorien | **Entschieden: Kategorien** — haben Bild, Beschreibung, Count. Bereits querybar. |
| 2 | featured-collection Bild: WC-Kategorie oder WP Custom Field? | A) WC-Kategorie B) WP Custom Field | A) WC-Kategorie | **Entschieden: WC-Kategorie** — `ProductCategory.image` bereits verfuegbar. |
| 3 | Announcement Bar: global.yaml oder jede Page-YAML? | A) global.yaml B) Jede Page-YAML | A) global.yaml | **Entschieden: global.yaml** — DRY, Shopify-Pattern, einmal konfigurieren. |
| 4 | Review mutation: writeReview oder createComment? | A) writeReview (WooGraphQL) B) createComment (WPGraphQL) | A) writeReview | **Entschieden: writeReview** — Native WC-Integration. Fallback zu createComment dokumentiert. |
| 5 | Pagination: Cursor, Offset-Plugin, oder Over-fetch? | A) Cursor B) Offset-Plugin C) Over-fetch+slice | C) Over-fetch+slice | **Entschieden: Over-fetch+slice** — Einfach, keine Plugins, umgeht Cursor-Bugs. Ausreichend fuer <200 Produkte. |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-02-25 | Codebase | 11 Blocks registriert, 4 WooCommerce query types, 2 WordPress query types in data-loaders |
| 2026-02-25 | Codebase | Sections-basiertes Rendering mit Grid-Layout (columns 1-4, span, row-span). 3-tier YAML lookup. |
| 2026-02-25 | Codebase | Apollo Server Client mit React.cache() Deduplication. Apollo Client mit WooCommerce Session Token chain. |
| 2026-02-25 | Codebase | Keine Search/Review/Pagination Queries vorhanden. Keine Mutations ausser Cart (add/update/remove). |
| 2026-02-25 | Codebase | searchParams werden aktuell in keiner Page verwendet. $route.slug ist einziger Placeholder. |
| 2026-02-25 | Codebase | Kein not-found.tsx vorhanden. Default Next.js 404 Page. |
| 2026-02-25 | Codebase | app/layout.tsx: ApolloWrapper > CartProvider > Header > children > Footer > CookieConsent > PinterestTagInit |
| 2026-02-25 | Web | WooGraphQL v0.21.2: `writeReview` mutation mit rating:Int Input. Rating auf edges, nicht nodes. |
| 2026-02-25 | Web | WooGraphQL: `product.averageRating`, `product.reviewCount`, `product.reviewsAllowed` verfuegbar. |
| 2026-02-25 | Web | WooGraphQL: Cursor-based pagination (first/after/last/before + pageInfo). Known bug: cursor + search ordering. |
| 2026-02-25 | Web | WPGraphQL Offset Pagination Plugin (valu-digital): Kein WooCommerce-Support. |
| 2026-02-25 | Web | WooGraphQL: orderby enum values PRICE, DATE, TOTAL_SALES. Known enum conversion bug bei Client-seitigen Variablen. |
| 2026-02-25 | Web | Aktuelle Versionen: Next.js 16.1.6, WPGraphQL 2.9.0, WooGraphQL 0.21.2, WooCommerce 10.5.2, WordPress 6.9 |

---

## Q&A Log

| # | Question | Answer |
|---|----------|--------|
| 1 | Collections: WooCommerce Kategorien oder Product Tags? | Kategorien — haben Bild, Beschreibung, Count. Bereits im Schema. Tags waeren flacher (kein Bild). |
| 2 | featured-collection Bild: Woher? | WC-Kategorie-Bild — `ProductCategory.image` bereits verfuegbar, kein neues Custom Field noetig. |
| 3 | Announcement Bar: Global oder per-Page? | Neues global.yaml — DRY-Prinzip, Shopify-Pattern. layout.tsx laedt und rendert oberhalb Content. |
| 4 | Review mutation: Welche? | writeReview (WooGraphQL) — Native WC-Integration mit Rating-Input. Fallback zu createComment wenn Bugs auftreten. |
| 5 | Pagination-Strategie? | Over-fetch + slice — Einfach, keine Plugin-Abhaengigkeit, umgeht bekannte Cursor+Search Bugs. Fuer <200 Produkte ausreichend. |
