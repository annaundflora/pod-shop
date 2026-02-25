# Architecture: Block-basierte Seiten-Migration

**Epic:** P0.3
**Status:** Ready
**Discovery:** `discovery.md` (same folder)
**Derived from:** Discovery constraints, NFRs, and risks

---

## Problem & Solution

**Problem:**
- Nur Homepage ist Block-basiert (YAML-konfigurierbar)
- Kategorie-, Produkt- und Rechtsseiten sind hardcoded in page.tsx
- Zweiter Shop kann Seitenstruktur nicht per Theme anpassen
- Rechtsseiten existieren nicht in Next.js (Footer linkt zu WordPress)

**Solution:**
- Alle verbleibenden Seiten (ausser Warenkorb) als Block-basierte YAML-Pages aufbauen
- Block-System um Sections-Ebene, Layout-Renderer und Param-Resolver erweitern
- Neue Block-Components fuer Kategorie, Produkt und Rechtsseiten erstellen

**Business Value:**
- Multi-Shop-Faehigkeit: Jeder Shop kann Seitenstruktur per YAML anpassen
- Konsistenz: Einheitliches Kompositions-Pattern fuer alle Seiten
- Exit-Kriterium Phase 0 + Phase 1: "Alle Seiten als Block-basierte YAML-Pages"

---

## Scope & Boundaries

| In Scope |
|----------|
| Block-System Upgrade: Sections-Format, 2D-Grid-Layout (columns + span + row-span), Param-Resolver, Template-Override |
| Homepage auf Sections-Format migrieren |
| Kategorie-Seite (`/kategorie/[slug]`) als Block-Page |
| Produkt-Seite (`/produkt/[slug]`) als Block-Page mit Client-Islands |
| Rechtsseiten (`/impressum`, `/agb`, `/datenschutz`, `/widerruf`) als Block-Pages |
| Footer-Links von WordPress-URLs auf Next.js-Routen umstellen |
| Platzhalter-Rechtstexte (echte Texte kommen in P0.5) |

| Out of Scope |
|--------------|
| Warenkorb-Seite (bleibt hardcoded — fast 100% Client-seitig) |
| "Alle Produkte"-Seite (`/produkte`) |
| Echte Rechtstexte (kommt in P0.5) |
| Neue Content-Sources (z.B. Markdown-Dateien) |
| Visueller Page-Builder / Drag & Drop |
| Slug-spezifische Layouts erstellen (System wird gebaut, aber keine konkreten Overrides) |

---

## API Design

### Overview

| Aspect | Specification |
|--------|---------------|
| Style | GraphQL (WPGraphQL + WooGraphQL) — existing |
| Authentication | No auth required (public data) |
| Rate Limiting | None (server-side RSC fetches, no client exposure) |

### GraphQL Queries (new)

| Query | Variables | Response | Implementation | Used by |
|-------|-----------|----------|----------------|---------|
| `GET_CATEGORY_WITH_PRODUCTS` | `categorySlug: String!, first: Int` | `{ products: { nodes: ProductCardData[] }, productCategory(id: $categorySlug, idType: SLUG): { name, description, slug } }` | Single multi-root GraphQL request with two root fields: `products(where: { categoryIn: [$categorySlug] })` AND `productCategory(id: $categorySlug, idType: SLUG)`. More efficient than two separate queries. | data-loaders.ts `products_by_category` handler → category page blocks (page-heading, product-count, product-grid, filter-chips) |
| `GET_PAGE_CONTENT` | `slug: String!` | `{ pageBy: { title, content } }` | Single root query. Uses WPGraphQL `pageBy` with URI slug. | data-loaders.ts `page_content` handler → legal page blocks (page-heading, legal-content) |

**Note on query deduplication:** Multiple blocks on the same page may call the same data-loader query (e.g., `page-heading` and `product-grid` both use `products_by_category`). Apollo Server Client uses `React.cache()` deduplication for RSC — identical queries with identical variables within the same render pass make only ONE actual network request.

### GraphQL Queries (reused, unchanged)

| Query | Location | Used by |
|-------|----------|---------|
| `GET_PRODUCT` | `lib/graphql/queries.ts` | data-loaders.ts `product_by_slug` handler → product page blocks |
| `GET_PRODUCTS` | `lib/graphql/queries.ts` | NOT replaced — still used by homepage. `GET_CATEGORY_WITH_PRODUCTS` is a NEW separate query for category pages. |
| `GET_PRODUCT_CATEGORIES` | `lib/graphql/queries.ts` | data-loaders.ts `product_categories` handler → filter-chips block on category page |
| `GET_ALL_CATEGORY_SLUGS` | `lib/graphql/queries.ts` | generateStaticParams (stays in page.tsx) |
| `GET_ALL_PRODUCT_SLUGS` | `lib/graphql/queries.ts` | generateStaticParams (stays in page.tsx) |

### Data Transfer Objects

| DTO | Fields | Source |
|-----|--------|--------|
| `ProductCardData` | id, slug, name, price, regularPrice, onSale, stockStatus, image, productCategories | Existing `lib/graphql/types.ts` |
| `ProductDetailData` | id, databaseId, slug, name, description, shortDescription, price, regularPrice, onSale, variations, image, galleryImages, productCategories | Existing `lib/graphql/types.ts` |
| `ProductCategory` | id, slug, name, count, image | Existing `lib/graphql/types.ts` |
| `CategoryWithProducts` | `{ products: { nodes: ProductCardData[] }, productCategory: { name, description, slug } }` | NEW — combined response from `GET_CATEGORY_WITH_PRODUCTS` |
| `WPPageContent` | title: string, content: string | NEW — WordPress page data |

### Block → Data Source Mapping

Each block specifies its own `content_source` and `params` in YAML. Multiple blocks on the same page may use the same query — Apollo `React.cache()` deduplicates identical requests.

| Block | Content Source | Query/Params | Data Used |
|-------|---------------|-------------|-----------|
| `page-heading` (category) | `woocommerce` | `products_by_category`, slug from `$route.slug` | `productCategory.name` → h1 |
| `filter-chips` (category) | `woocommerce` | `product_categories` | All categories for pills |
| `product-count` (category) | `woocommerce` | `products_by_category`, slug from `$route.slug` | `products.nodes.length` → "N Produkte" |
| `product-grid` (category) | `woocommerce` | `products_by_category`, slug from `$route.slug` | `products.nodes` → ProductCard list |
| `product-gallery` (product) | `woocommerce` | `product_by_slug`, slug from `$route.slug` | `product.image` + `product.galleryImages` |
| `product-purchase` (product) | `woocommerce` | `product_by_slug`, slug from `$route.slug` | Full `product` (variants, price, databaseId) |
| `product-description` (product) | `woocommerce` | `product_by_slug`, slug from `$route.slug` | `product.description` |
| `page-heading` (legal) | `wordpress` | `page_content`, slug from route | `page.title` → h1 |
| `legal-content` (legal) | `wordpress` | `page_content`, slug from route | `page.content` → HTML |
| `hero` (home) | `wordpress` | `page_slug: "/"` | Custom fields (existing) |
| `product-grid` (home) | `woocommerce` | `featured_products` | Featured products (existing) |
| `category-showcase` (home) | `woocommerce` | `product_categories` | Categories (existing) |
| `usp-bar` (home) | `inline` | YAML props | Inline data (existing) |

**No `dynamic` content source needed.** All blocks get data from `woocommerce`, `wordpress`, or `inline` sources. Route-derived data (category name, product name) comes from the same GraphQL queries that blocks already call. The `$route.slug` Param-Resolver handles injecting the URL slug into query params before data loading.

---

## Database Schema

N/A — No database changes. All data comes from existing WordPress/WooCommerce via GraphQL.

---

## Server Logic

### Services & Processing

| Service | Responsibility | Input | Output | Side Effects |
|---------|----------------|-------|--------|--------------|
| `loadPageConfig()` | Load YAML config with 3-tier template override | `pageType: string, theme: string, slug?: string` | `PageConfig` (sections format) | fs.readFileSync |
| `resolveParams()` | Replace `$route.{key}` placeholders in YAML params with URL params | `params: object, routeContext: { slug: string }` | Resolved params object (same shape, placeholders replaced) | None |
| `loadBlockData()` | Dispatch to correct loader based on content_source | `contentSource: ContentSource, params: LoaderParams` | `{ data: unknown, error?: string }` | GraphQL network calls |
| `wordpressLoader()` | Extended: handle `page_content` query (returns title + HTML body) | `{ page_slug: string, query?: 'custom_fields' \| 'page_content' }` | `{ data: WPCustomFieldsData \| WPPageContent \| null }` | GraphQL query |
| `woocommerceLoader()` | Extended: handle `products_by_category` and `product_by_slug` queries | `{ query: string, slug?: string, first?: number }` | `{ data: CategoryWithProducts \| ProductDetailData \| ... \| null }` | GraphQL query |

### Business Logic Flow

```
page.tsx (generateStaticParams, generateMetadata, JSON-LD, Pinterest tracking)
  → loadPageConfig(slug, theme) → YAML with sections[]
    → resolveParams(section.blocks[].params, { slug })
      → for each section:
        → SectionLayout (CSS Grid: columns, gap)
          → for each block:
            → loadBlockData(content_source, resolvedParams)
            → BlockComponent({ data })
```

### Param Resolution Rules

| Placeholder | Resolved to | Example |
|-------------|-------------|---------|
| `$route.slug` | URL path parameter `[slug]` | `$route.slug` → `"t-shirts"` |
| `$route.{any}` | Corresponding dynamic segment | Future: `$route.id` → `"123"` |

### Template Override Resolution (3-tier)

| Priority | Path | Example |
|----------|------|---------|
| 1 (highest) | `themes/{theme}/pages/{pageType}/{slug}.yaml` | `themes/zweiter-shop/pages/category/t-shirts.yaml` |
| 2 | `themes/{theme}/pages/{pageType}.yaml` | `themes/zweiter-shop/pages/category.yaml` |
| 3 (fallback) | `themes/default/pages/{pageType}.yaml` | `themes/default/pages/category.yaml` |

---

## Security

### Authentication & Authorization

| Area | Mechanism | Notes |
|------|-----------|-------|
| GraphQL queries | No auth | All product/category/page data is public |
| WooCommerce session | Not needed | Block pages are read-only (no cart mutations) |

### Data Protection

| Data Type | Protection | Notes |
|-----------|------------|-------|
| WordPress page content (HTML) | `dangerouslySetInnerHTML` | Trusted source (own WordPress backend). Same pattern as existing product description. |

### Input Validation & Sanitization

| Input | Validation | Sanitization |
|-------|------------|--------------|
| YAML block config | TypeScript type check on load | Parsed via `yaml` library (no eval) |
| Route params (`slug`) | `generateStaticParams` pre-validates at build time | Passed as GraphQL variable (parameterized query) |
| `$route.slug` placeholder | Only `$route.{key}` pattern allowed | Regex match, no arbitrary interpolation |

### Rate Limiting & Abuse Prevention

N/A — All queries are server-side RSC fetches. No client-exposed endpoints.

---

## Architecture Layers

### Layer Responsibilities

| Layer | Responsibility | Pattern |
|-------|----------------|---------|
| Route Layer (`app/{route}/page.tsx`) | generateStaticParams, generateMetadata, JSON-LD, Pinterest tracking, load page config, render SectionRenderer | Next.js App Router page |
| Section Layer (`lib/blocks/section-renderer.tsx`) | Iterate sections[], apply SectionLayout per section, render blocks | Server Component composition |
| Layout Layer (`lib/blocks/section-layout.tsx`) | CSS Grid with configurable columns, gap. Apply span/row-span per block. Mobile: always stack. | CSS Grid wrapper |
| Block Layer (`components/blocks/*.tsx`) | Individual block rendering with data prop | BlockComponentProps pattern |
| Data Layer (`lib/blocks/data-loaders.ts`) | Fetch data per content_source + params | Loader dispatch pattern |
| Config Layer (`lib/blocks/page-config.ts`) | Load + merge YAML, resolve params, template override | fs.readFileSync + yaml parse |
| Registry (`lib/blocks/registry.ts`) | Map block type string → React component | Record lookup |

### Data Flow

```
Browser Request
  → Next.js Route (page.tsx)
    → loadPageConfig(pageType, theme) [Config Layer]
      → 3-tier YAML lookup [fs]
      → resolveParams($route.slug → actual slug) [Config Layer]
    → SectionRenderer [Section Layer]
      → SectionLayout(columns, gap) [Layout Layer]
        → BlockRenderer [Block Layer]
          → loadBlockData(source, params) [Data Layer]
            → GraphQL (WordPress/WooCommerce) or inline
          → BlockComponent({ data }) [Block Layer]
  → HTML Response (streamed via React Suspense)
```

### Error Handling Strategy

| Error Type | Handling | User Response | Logging |
|------------|----------|---------------|---------|
| YAML not found | Throw Error | 500 (build-time for static, runtime for dynamic) | Build log / Runtime error |
| Unknown block type | Skip block | Block omitted from page | `console.warn` (existing behavior) |
| GraphQL error | Return `{ data: null }` | Block renders with null data (graceful) | `console.error` (existing behavior) |
| Invalid `$route` placeholder | Resolve to empty string | Data loader gets empty param | `console.warn` |

---

## Migration Map

| Existing File | Current Pattern | Target Pattern | Specific Changes |
|---|---|---|---|
| `lib/blocks/types.ts` | Flat `BlockConfig[]` in `PageConfig` | `SectionConfig[]` in `PageConfig`, each section has `columns`, `gap`, `blocks[]`. BlockConfig gets optional `span`, `row_span`. | Add `SectionConfig` interface, update `PageConfig`, extend `WooCommerceLoaderParams` query union with `'products_by_category' \| 'product_by_slug'`, add `WordPressLoaderParams` query union with `'page_content'`, add `WPPageContent` and `CategoryWithProducts` interfaces |
| `lib/blocks/page-config.ts` | 2-tier lookup (theme → default), flat blocks, no param resolution | 3-tier lookup (slug-specific → theme → default), sections format, `resolveParams()` function | Add `resolveParams()`, update `loadPageConfig()` signature to accept `pageType` + optional `routeParams`, implement 3-tier lookup |
| `lib/blocks/data-loaders.ts` | 3 sources (wordpress, woocommerce, inline), 2 woocommerce queries (featured_products, product_categories), 1 wordpress query (custom_fields) | 3 sources (unchanged), 4 woocommerce queries (+ products_by_category, product_by_slug), 2 wordpress queries (+ page_content) | Add `products_by_category` and `product_by_slug` woocommerce handlers, add `page_content` wordpress handler, import new GraphQL queries (`GET_CATEGORY_WITH_PRODUCTS`, `GET_PAGE_CONTENT`) |
| `lib/blocks/registry.ts` | 4 block types (hero, product-grid, category-showcase, usp-bar) | 11 block types (+ page-heading, filter-chips, product-count, product-gallery, product-purchase, product-description, legal-content) | Register 7 new block component imports |
| `themes/default/pages/home.yaml` | Flat `blocks:` array | `sections:` array wrapping blocks | Convert each block into a section with `columns: 1` |
| `app/page.tsx` | Flat block iteration, inline BlockRenderer, SKELETON_MAP | SectionRenderer component, updated skeleton handling | Replace flat iteration with SectionRenderer, update SKELETON_MAP with new block types |
| `app/kategorie/[slug]/page.tsx` | Hardcoded JSX with inline GraphQL queries, FilterChips, ProductCard | Thin wrapper: generateStaticParams + generateMetadata + Pinterest tracking + loadPageConfig + SectionRenderer | Remove hardcoded layout, keep generateStaticParams/generateMetadata/Pinterest tracking, delegate to SectionRenderer |
| `app/kategorie/[slug]/category-page-client.tsx` | Pinterest tracking client component | Unchanged | No changes — still imported by page.tsx for tracking |
| `app/produkt/[slug]/page.tsx` | Hardcoded JSX with inline GraphQL, Client-Island components | Thin wrapper: generateStaticParams + generateMetadata + JSON-LD + loadPageConfig + SectionRenderer | Remove hardcoded layout, keep generateStaticParams/generateMetadata/JSON-LD, delegate to SectionRenderer |
| `app/produkt/[slug]/product-variant-selector.tsx` | Client component imported directly by page.tsx | Client component imported by ProductPurchaseBlock | No changes to component itself — only import location changes |
| `components/layout/footer.tsx` | Links to `${WP_URL}/impressum` etc. (external WordPress) | Links to `/impressum` etc. (internal Next.js routes) | Change LEGAL_LINKS hrefs from `${WP_URL}/...` to `/...`, change `<a>` to Next.js `<Link>` |

### New Files

| New File | Purpose |
|----------|---------|
| `lib/blocks/section-renderer.tsx` | Server Component: iterates sections[], renders SectionLayout + BlockRenderer per section |
| `lib/blocks/section-layout.tsx` | Server Component: CSS Grid wrapper with `columns` prop, mobile stack |
| `components/blocks/page-heading-block.tsx` | Block: h1 + optional subtitle |
| `components/blocks/filter-chips-block.tsx` | Block: wrapper around existing FilterChips component |
| `components/blocks/product-count-block.tsx` | Block: "N Produkte" text |
| `components/blocks/product-gallery-block.tsx` | Client-Island Block: wrapper around existing ProductImageGallery |
| `components/blocks/product-purchase-block.tsx` | Client-Island Block: wrapper around existing ProductVariantSelector + AddToCartButton |
| `components/blocks/product-description-block.tsx` | Block: product description in Card |
| `components/blocks/legal-content-block.tsx` | Block: WordPress HTML content |
| `themes/default/pages/category.yaml` | YAML page config for category pages |
| `themes/default/pages/product.yaml` | YAML page config for product pages |
| `themes/default/pages/legal.yaml` | YAML page config for legal pages (shared template) |
| `app/impressum/page.tsx` | Route: Impressum legal page |
| `app/agb/page.tsx` | Route: AGB legal page |
| `app/datenschutz/page.tsx` | Route: Datenschutz legal page |
| `app/widerruf/page.tsx` | Route: Widerruf legal page |
| `lib/graphql/queries.ts` | Extended: GET_CATEGORY_WITH_PRODUCTS, GET_PAGE_CONTENT queries |

---

## Constraints & Integrations

### Constraints

| Constraint | Technical Implication | Solution |
|------------|----------------------|----------|
| Page-level logic stays in page.tsx | generateStaticParams, generateMetadata, JSON-LD, Pinterest tracking cannot move into block system | page.tsx remains a thin wrapper that handles these, then delegates layout to SectionRenderer |
| Client-Island blocks need server data | ProductGallery and ProductPurchase need server-fetched product data but render as Client Components | Data fetched by data-loader (server), passed as props to Client-Island block wrapper. Wrapper uses `"use client"` directive. |
| Mobile always stacks | All layouts must collapse to single column on mobile | SectionLayout uses `grid-cols-1` as base, `md:grid-cols-{N}` for desktop. Tailwind responsive. |
| Theme tokens only | No hardcoded colors, radii, shadows | All new components use `bg-primary`, `text-text-primary`, `rounded-card`, etc. |
| `generated-theme.css` never manual edit | Auto-generated file | No changes needed — existing tokens sufficient |
| YAML page configs use full replacement (not deep merge) | Shop themes must provide complete page YAML if overriding | 3-tier fallback: slug-specific → theme-generic → default |

### Integrations

| Area | System / Capability | Interface | Version | Notes |
|------|----------------------|-----------|---------|-------|
| Frontend Framework | Next.js | App Router, RSC, Suspense | 16.1.x (package.json: `^16.1.6`) | Existing |
| UI Framework | React | Server + Client Components | 19.x (package.json: `^19.0.0`) | Existing |
| CSS | Tailwind CSS | Utility classes, `@theme` block | 4.x (package.json: `^4.0.0`) | Existing |
| GraphQL Client | Apollo Client | `getClient()` RSC pattern | 4.1.x (package.json: `^4.1.4`) | Existing |
| GraphQL Server | WPGraphQL | GraphQL endpoint | 1.x (WordPress plugin) | Existing |
| WooCommerce GraphQL | WooGraphQL | Product/Category queries | 0.x (WordPress plugin) | Existing |
| YAML Parser | yaml | `parse()` | 2.7.x (package.json: `^2.7.0`) | Existing |
| CMS | WordPress | Page content via GraphQL | 6.9.x (Docker) | Existing |

---

## Quality Attributes (NFRs)

### From Discovery → Technical Solution

| Attribute | Target | Technical Approach | Measure / Verify |
|-----------|--------|--------------------|------------------|
| Visual parity | Pages look identical before and after migration | Block components reuse existing UI components, same Tailwind classes | Visual comparison, screenshot tests |
| Build-time validation | Invalid YAML caught at build time (static pages) | `loadPageConfig()` throws on missing YAML, `generateStaticParams` pre-validates | `pnpm build` fails on broken config |
| ISR consistency | Same `revalidate = 60` behavior | page.tsx retains `export const revalidate = 60` | Same behavior as before |
| Performance | No degradation from block system overhead | SectionRenderer is thin server component, no extra network calls | Lighthouse before/after |
| Mobile responsiveness | All layouts collapse to single column | `grid-cols-1 md:grid-cols-{N}` pattern | Responsive testing |
| SEO | generateMetadata and JSON-LD unchanged | Stays in page.tsx, not moved to block system | Check meta tags, JSON-LD in HTML |
| Accessibility | Same a11y as current pages | Reuse existing components, maintain ARIA attributes | axe-core audit |

### Monitoring & Observability

N/A — No new monitoring needed. Existing Next.js build logs and console.warn/error for unknown blocks.

---

## Risks & Assumptions

### Assumptions

| Assumption | Technical Validation | Impact if Wrong |
|------------|---------------------|-----------------|
| WPGraphQL supports `pageBy(uri: "/impressum")` query | Test via GraphQL playground against running WP | Need alternative query (e.g., `pageBy(slug: "impressum")`) |
| WordPress has pages for /impressum, /agb, /datenschutz, /widerruf | Created by `scripts/setup.sh` (existing WP-CLI setup) | legal-content block shows "not found" state |
| Existing GraphQL queries return sufficient data for blocks | Verified by reading `lib/graphql/queries.ts` + `fragments.ts` | May need to extend fragments |
| `fs.readFileSync` in YAML loader works in RSC context | Already proven by existing `loadPageConfig()` | Would need async alternative |

### Risks & Mitigation

| Risk | Likelihood | Impact | Technical Mitigation | Fallback |
|------|------------|--------|---------------------|----------|
| Client-Island blocks lose interactivity after migration | Low | High | Thorough testing of Gallery swipe, Variant selection, AddToCart. Blocks pass all needed data as props. | Revert to hardcoded page for affected page type |
| Template-Override complexity makes debugging hard | Low | Medium | 3-tier lookup logs which file was loaded. Clear naming convention. | Simplify to 2-tier (theme → default) |
| YAML config mistakes break pages silently | Medium | Medium | TypeScript validation in `loadPageConfig()`. Build-time validation for static pages. | console.error + graceful null rendering |
| Section/Grid layout breaks on edge cases (very long content, missing blocks) | Low | Low | CSS Grid handles overflow gracefully. Empty sections skipped. | Fallback to `columns: 1` |

---

## Technology Decisions

### Stack Choices

| Area | Technology | Rationale |
|------|------------|-----------|
| Page composition | YAML → Sections → Blocks | Extends existing proven pattern (homepage). Shopify-inspired industry standard. |
| Layout system | CSS Grid via Tailwind | Native browser layout. No JS runtime cost. Responsive via Tailwind breakpoints. |
| Param resolution | String replacement (`$route.slug`) | Minimal complexity. No template engine needed. Covers all current use cases. |
| Template override | 3-tier file system lookup | Same fs-based approach as existing theme system. No config database needed. |
| Client-Islands | `"use client"` wrapper blocks | Existing React 19 pattern. Server-fetched data passed as props to client boundary. |

### Trade-offs

| Decision | Pro | Con | Mitigation |
|----------|-----|-----|------------|
| Sections as composition layer (not flat blocks) | Enables multi-column layouts, closer to industry standard | More complex YAML structure | Default `columns: 1` keeps simple pages simple |
| 2D-Grid (columns + span + row-span) from start | No future migration needed, handles complex layouts | Slightly more complex SectionLayout component | row-span rarely used initially, but available |
| Page-level concerns stay in page.tsx | SEO, tracking, static params work exactly as before | Some duplication across page files | Each page.tsx is thin (<30 lines of non-block code) |
| Shared `legal.yaml` for all legal pages | DRY — one template for 4 pages | Cannot customize per legal page | Slug-specific override available if needed later |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| -- | Alle Fragen geklaert | -- | -- | -- |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-02-25 | Codebase | `lib/blocks/types.ts`: Flat `PageConfig.blocks[]`, `ContentSource` = wordpress/woocommerce/inline |
| 2026-02-25 | Codebase | `lib/blocks/page-config.ts`: 2-tier lookup (theme → default), fs.readFileSync, `yaml` parse |
| 2026-02-25 | Codebase | `lib/blocks/data-loaders.ts`: 3 loaders (wordpress, woocommerce, inline). Woocommerce: featured_products + product_categories only |
| 2026-02-25 | Codebase | `lib/blocks/registry.ts`: 4 blocks registered (hero, product-grid, category-showcase, usp-bar) |
| 2026-02-25 | Codebase | `app/page.tsx`: Flat block iteration, inline BlockRenderer async component, SKELETON_MAP, ISR revalidate=60 |
| 2026-02-25 | Codebase | `app/kategorie/[slug]/page.tsx`: Hardcoded JSX, parallel Promise.all for products+categories, CategoryPageClient for Pinterest tracking |
| 2026-02-25 | Codebase | `app/produkt/[slug]/page.tsx`: Hardcoded JSX, GET_PRODUCT query, extractVariantOptions, JSON-LD, Client-Island ProductVariantSelector |
| 2026-02-25 | Codebase | `app/produkt/[slug]/product-variant-selector.tsx`: `"use client"`, useState, useCart, VariantSelectorSize/Color, AddToCartButton |
| 2026-02-25 | Codebase | `components/layout/footer.tsx`: LEGAL_LINKS use `${WP_URL}/impressum` etc. — external WordPress links |
| 2026-02-25 | Codebase | `components/category/filter-chips.tsx`: Server component, receives categories + currentSlug, renders Button+Link pills |
| 2026-02-25 | Codebase | `lib/graphql/queries.ts`: GET_PRODUCTS (categorySlug filter), GET_PRODUCT (by slug), GET_PRODUCT_CATEGORIES, slug queries for SSG |
| 2026-02-25 | Codebase | `lib/graphql/types.ts`: ProductCardData, ProductDetailData, ProductCategory, VariantOptions — all reusable |
| 2026-02-25 | Codebase | `package.json`: Next.js 16.1.x, React 19, Apollo 4.1.x, Tailwind 4, yaml 2.7.x |

---

## Q&A Log

| # | Question | Answer |
|---|----------|--------|
| 1 | wireframes.md fehlt — ohne Wireframes fortfahren? | Ja, Architektur-Migration ohne UI-Aenderungen braucht keine Wireframes |
