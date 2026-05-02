# Codebase Scan

**Feature:** Kleinstadtpflanze — Layout & Components (Phase 2, ehrlich-klein)
**Scan Date:** 2026-04-28
**Discovery:** `specs/2026-04-26-kleinstadtpflanze-layout-flair/discovery.md`

---

## Identified Patterns

| # | Pattern | Locations | Count | Type |
|---|---------|-----------|-------|------|
| 1 | Block component with `BlockComponentProps<T>` + named `*Block` export + optional `*BlockSkeleton` export | `frontend/components/blocks/*-block.tsx` (26 files) | 26 | REUSE |
| 2 | YAML page config (3-tier lookup theme/slug/default, deep `$route.*` placeholder resolution) | `frontend/lib/blocks/page-config.ts:53-119`, `frontend/themes/default/pages/{home,product,category}.yaml` | 8 yamls | REUSE |
| 3 | Block registry (`type → ComponentType`) + `resolveBlock()` in `SectionRenderer` | `frontend/lib/blocks/registry.ts:38-79`, `frontend/lib/blocks/section-renderer.tsx:14-20` | 26 entries | EXTEND |
| 4 | Suspense + Skeleton via `SKELETON_MAP` per page | `frontend/app/page.tsx:13-22`, `frontend/app/produkt/[slug]/page.tsx:42-46`, `frontend/app/kategorie/[slug]/page.tsx:81-95` | 3 pages | EXTEND |
| 5 | `'use client'` block with `localStorage` + mount guard | `frontend/components/blocks/announcement-bar-block.tsx:1-69` | 1 | REUSE (reference) |
| 6 | Tile-Grid with image+name+link (Card variant=interactive, asChild Link) | `frontend/components/blocks/category-showcase-block.tsx:14-62` | 1 | REUSE (pattern) |
| 7 | Card-grid with figure/blockquote on `bg-surface rounded-card shadow-card` | `frontend/components/blocks/testimonials-block.tsx:16-60` | 1 | REUSE (pattern) |
| 8 | Cross-sell rendering using `ProductCard` grid + heading guard | `frontend/components/blocks/product-recommendations-block.tsx:1-34` | 1 | REUSE / EXTEND |
| 9 | Icon-mapped item list with `ICON_MAP` (lucide-react) | `frontend/components/blocks/trust-badges-block.tsx:5-13`, `frontend/components/blocks/usp-bar-block.tsx:6-11` | 2 | REUSE |
| 10 | Prose styling for HTML (`prose prose-sm text-text-secondary max-w-none` + `dangerouslySetInnerHTML`) | `frontend/components/blocks/product-description-block.tsx:13-17` | 1 | REUSE |
| 11 | PDP gallery — `ProductImageGallery` with active-index, touch-swipe (mobile dots), desktop horizontal thumbs | `frontend/components/product/product-image-gallery.tsx:15-132`, `frontend/components/blocks/product-gallery-block.tsx:1-33` | 1 | EXTEND |
| 12 | woocommerce data loader dispatch on `params.query` | `frontend/lib/blocks/data-loaders.ts:131-358` | 12 query types | EXTEND |
| 13 | Inline `content_source: inline` with `props:` payload | `frontend/themes/default/pages/{home,product,category}.yaml` (testimonials, newsletter, trust-badges, usp-bar, sort-bar, empty-state) | 6 occurrences | REUSE |
| 14 | Section span/columns helper (`grid-cols-{1..4}`, gap, span/row_span on inner div) | `frontend/lib/blocks/section-layout.tsx:1-25`, `frontend/lib/blocks/section-renderer.tsx:39-51` | 1 | REUSE |
| 15 | Theme tokens (Tailwind utilities) wired via `app/globals.css` `@theme` block (`bg-surface-alt`, `text-text-primary`, `border-border`, `rounded-card`, `shadow-card`, `font-heading`, `aspect-product`) | `frontend/app/globals.css:5-55`, `frontend/themes/kleinstadtpflanze/theme.yaml:9-53` | All blocks | REUSE |
| 16 | Theme-conditional font selection (`isKleinstadtpflanze` → Source Serif 4 + Work Sans) | `frontend/lib/theme/fonts.ts:3-32` | 1 | REUSE |

---

## Existing Abstractions

| Abstraction | Location | Used by | Recommendation | Rationale |
|-------------|----------|---------|----------------|-----------|
| `BlockComponentProps<T>` interface | `frontend/lib/blocks/types.ts:86-88` | All 26 blocks | REUSE | Single contract `{ data: T }` for every block — new blocks must follow it. |
| `loadPageConfig(pageType, theme, routeParams)` (3-tier YAML resolver) | `frontend/lib/blocks/page-config.ts:53-119` | `app/page.tsx`, `app/produkt/[slug]/page.tsx`, `app/kategorie/[slug]/page.tsx` | REUSE | Already supports `themes/kleinstadtpflanze/pages/{home,product,category}.yaml` — no change needed. |
| `registry` map + `resolveBlock(type)` | `frontend/lib/blocks/registry.ts:38-79` | `SectionRenderer` | EXTEND | New entries needed for 6 new block types. Pattern already groups by Slice (comment style). |
| `SectionRenderer` + `SectionLayout` | `frontend/lib/blocks/section-renderer.tsx:22-57`, `frontend/lib/blocks/section-layout.tsx` | All pages | REUSE | New blocks render automatically once registered + YAML-configured. |
| `loadBlockData()` dispatcher (`wordpress` / `woocommerce` / `inline`) | `frontend/lib/blocks/data-loaders.ts:364-379` | `BlockRenderer` in `SectionRenderer` | EXTEND | Inline source covers everything except `motif-cross-sell` (needs new woocommerce query branch). |
| `WooCommerceLoaderParams.query` union | `frontend/lib/blocks/types.ts:13-37` | `data-loaders.ts` dispatch | EXTEND | Add new branch (e.g. `'products_by_motif'`) only if Q2 lands on tag/taxonomy data source. |
| `ProductImageGallery` (active-index, touch-swipe, mobile dots, desktop horizontal thumbs) | `frontend/components/product/product-image-gallery.tsx:15-132` | `ProductGalleryBlock` | EXTEND | Add `layout: 'default' \| 'vertical-thumbs'` prop. Mobile branch unchanged; desktop branch swaps thumbs container layout (80px col left + main right). |
| `ProductGalleryBlock` (passes data into `ProductImageGallery`) | `frontend/components/blocks/product-gallery-block.tsx:1-33` | PDP YAML | EXTEND | Add `layout` to its data shape, forward to `ProductImageGallery`. |
| `ProductPurchaseBlock` | `frontend/components/blocks/product-purchase-block.tsx:8-40` | PDP YAML | EXTEND | Append optional sections after `<ProductVariantSelector>`: rendered description (HTML via `prose`) + new `service-box`. Or: keep purchase pure and add new combined block. |
| `ProductDescriptionBlock` (`prose prose-sm` + `dangerouslySetInnerHTML`) | `frontend/components/blocks/product-description-block.tsx:1-37` | `themes/default/pages/product.yaml` | REUSE (prose recipe) / AVOID (as standalone section in `kleinstadtpflanze`) | Discovery removes it as a standalone section; the prose container recipe (`prose prose-sm text-text-secondary max-w-none`) is reused inline inside `ProductPurchaseBlock`. |
| `ProductRecommendationsBlock` + `ProductGridBlockSkeleton` reuse | `frontend/components/blocks/product-recommendations-block.tsx:1-34` | PDP YAML | REUSE | Heading is YAML-driven; usable on Kategorie-Bottom (Slice 3) and as base for `motif-cross-sell` (Slice 2). |
| `ICON_MAP` (lucide-react, string→Component) | `frontend/components/blocks/trust-badges-block.tsx:5-13`, `frontend/components/blocks/usp-bar-block.tsx:6-11` | trust-badges, usp-bar | REUSE | Pattern for `service-box`, `faq-accordion` headers, `editorial-mini` etc. — copy locally per block (no shared abstraction file exists). |
| `Card` variants (`variant="interactive"`, `asChild`) | `frontend/components/ui/card.tsx` (used in `category-showcase-block.tsx:32`) | category-showcase | REUSE | Tile shell for `motif-grid` and `motif-cross-sell`. |
| `ProductCard` | `frontend/components/product/product-card.tsx` | product-grid, product-recommendations, featured-collection | REUSE | Use as-is in `motif-cross-sell` if motif-cross-sell renders full cards; else build a small motif-tile. |
| `extractVariantOptions` + `ProductVariantSelector` | `frontend/lib/product/variant-utils.ts`, `frontend/app/produkt/[slug]/product-variant-selector.tsx` | `ProductPurchaseBlock` | REUSE (no change) | Discovery confirms: out of scope. |
| Theme tokens (`bg-surface-alt`, `border-border`, `rounded-card`, `shadow-card`, `font-heading`, `aspect-product`) | `frontend/app/globals.css:5-55`, `frontend/themes/kleinstadtpflanze/theme.yaml` | All blocks | REUSE | All new blocks must use tokens (not hardcoded oklch/hex). |

---

## Recommendations

### REUSE (reuse existing abstraction)

| # | What | Where | Why |
|---|------|-------|-----|
| 1 | `BlockComponentProps<T>` + named `*Block`/`*BlockSkeleton` exports | `frontend/lib/blocks/types.ts:86-88` | Required contract — 26 existing blocks follow it. |
| 2 | 3-tier YAML override (`themes/kleinstadtpflanze/pages/{home,product,category}.yaml`) | `frontend/lib/blocks/page-config.ts:53-119` | Discovery's chosen override mechanism is already implemented. Just create new YAML files; no loader change needed. |
| 3 | Inline data source (`content_source: inline`, `params.props: { ... }`) | `frontend/themes/default/pages/{home,product,category}.yaml` (6+ occurrences) | All 6 new blocks (editorial-mini, faq-accordion, motif-grid, service-box, brand-recap; possibly motif-cross-sell with fallback) are inline-static — no new loader branch needed for these. |
| 4 | `ICON_MAP` pattern (lucide-react string→Component) | `frontend/components/blocks/trust-badges-block.tsx:5-13` | Copy verbatim into `service-box` and `editorial-mini` (and faq-accordion if items use icons). |
| 5 | `prose prose-sm text-text-secondary max-w-none` + `dangerouslySetInnerHTML` | `frontend/components/blocks/product-description-block.tsx:13-17` | Exact CSS recipe to render WP HTML inside the new combined `ProductPurchaseBlock` (or new `ProductPurchaseDescriptionBlock`). |
| 6 | Tile-Grid pattern (`Card variant="interactive" asChild`, image+name+arrow) | `frontend/components/blocks/category-showcase-block.tsx:14-62` | Exact base for `motif-grid` tiles and `motif-cross-sell` tiles. |
| 7 | Card-with-figure recipe (`bg-surface rounded-card shadow-card p-6 h-full flex flex-col gap-3`) | `frontend/components/blocks/testimonials-block.tsx:16-60` | Visual recipe for `service-box` items (icon + label + body). |
| 8 | `ProductRecommendationsBlock` (heading + ProductCard grid + null guard) | `frontend/components/blocks/product-recommendations-block.tsx:1-34` | Reuse as-is on Kategorie-Bottom (Slice 3). Heading param already in YAML. |
| 9 | `ProductGridBlockSkeleton` re-export pattern | `frontend/components/blocks/product-recommendations-block.tsx:34` | Same trick for `motif-cross-sell` skeleton. |
| 10 | Suspense + Skeleton wiring in `app/page.tsx` SKELETON_MAP | `frontend/app/page.tsx:13-22` | New blocks are inline-synchronous → register `null` skeleton (consistent with `'usp-bar': null`, `'testimonials': null`, `'newsletter-signup': null`). |
| 11 | `'use client'` + `localStorage` + `mounted` guard | `frontend/components/blocks/announcement-bar-block.tsx:1-69` | Reference pattern for `faq-accordion` IF persistence is added. Discovery (Q3 + Business Rules) currently spec's local-only state, so persistence is NOT required — but pattern is at hand if needed. |
| 12 | `SectionLayout` (`columns: 2`, `gap: "gap-16"`) for 2-col PDP main row | `frontend/lib/blocks/section-layout.tsx:1-25`, `frontend/themes/default/pages/product.yaml:5-7` | The PDP main row's 2-col layout already exists; the `kleinstadtpflanze/pages/product.yaml` re-uses the same pattern, only swapping in `product-gallery` (with `layout: vertical-thumbs`) + `product-purchase` (with `withDescription: true`). |
| 13 | Theme tokens (`bg-surface-alt`, `text-text-primary`, `text-text-secondary`, `border-border`, `border-strong`, `rounded-card`, `rounded-pill`, `shadow-card`, `shadow-card-hover`, `font-heading`, `aspect-product`) | `frontend/app/globals.css:5-55`, `frontend/themes/kleinstadtpflanze/theme.yaml:9-53` | All new block CSS must use tokens — `kleinstadtpflanze` already provides Cream `surface-alt`, Forest `primary`, Terracotta `accent`, Source Serif `font-heading`. Hardcoded colors/radii will not theme. |

### EXTEND (extend existing abstraction)

| # | What | Where | Extension needed |
|---|------|-------|------------------|
| 1 | `registry` in `frontend/lib/blocks/registry.ts` | `frontend/lib/blocks/registry.ts:38-79` | Add 6 entries: `'editorial-mini'`, `'faq-accordion'`, `'motif-grid'`, `'service-box'`, `'motif-cross-sell'`, `'brand-recap'`. Add comment header `// Slice 07 — Kleinstadtpflanze Layout-Flair:`. |
| 2 | `SKELETON_MAP` per page | `frontend/app/page.tsx:13-22`, `frontend/app/produkt/[slug]/page.tsx:42-46`, `frontend/app/kategorie/[slug]/page.tsx:81-95` | Add `null` entries for the new inline-synchronous blocks (analog to `'testimonials': null`). Required even for inline blocks (SectionRenderer always wraps in Suspense). |
| 3 | `HeroBlock` to support `variant: "two-col"` | `frontend/components/blocks/hero-block.tsx:18-61` | Discovery: variant prop on data + branched JSX (default = current; two-col = tag + brand-color span headline + subline + CTA + ghost CTA + Trust-Row + visual right). Backward-compatible. Loader is `wordpress` → may need to read `variant` from YAML params, not from WP CMS — see Architecture decision. |
| 4 | `ProductImageGallery` to support `layout="vertical-thumbs"` | `frontend/components/product/product-image-gallery.tsx:15-132` | Add `layout` prop. On Desktop (`lg:` breakpoint) when vertical-thumbs: switch outer container to `lg:grid lg:grid-cols-[80px_1fr] lg:gap-4` and render thumb list as vertical column on the left. Mobile branch unchanged. |
| 5 | `ProductGalleryBlock` to forward `layout` from YAML | `frontend/components/blocks/product-gallery-block.tsx:1-33` | Read `layout` from `data` (or from a new param on the block); pass to `<ProductImageGallery layout=... />`. |
| 6 | `ProductPurchaseBlock` to inline render description + service-box | `frontend/components/blocks/product-purchase-block.tsx:8-40` | Two viable shapes: (a) extend block with optional `withDescription` + `serviceBox` data, render after `<ProductVariantSelector>`; (b) introduce a new combined block (e.g. `product-purchase-rich`). Architecture decision needed; prose recipe lives in `product-description-block.tsx:13-17`. |
| 7 | `WooCommerceLoaderParams.query` union | `frontend/lib/blocks/types.ts:13-37` | If Q2 (motif identity) decides on tag/custom-taxonomy: add new query string (e.g. `'products_by_motif'`) + matching branch in `woocommerceLoader` and a new gql query in `lib/graphql/queries.ts`. If decision is "no taxonomy yet" → motif-cross-sell stays inline-YAML and renders `null` when no items. |
| 8 | `PRODUCT_DETAIL_FRAGMENT` / `PRODUCT_CARD_FRAGMENT` to expose tags or motif metadata | `frontend/lib/graphql/fragments.ts:4-91` | Only required if Q2 lands on a WC-side identity (productTags, custom taxonomy, meta field). Today neither tags nor meta are exposed. |
| 9 | Trust-Badges YAML payload for `kleinstadtpflanze` (text only — no code change) | `frontend/themes/default/pages/product.yaml:31-41` (default already has correct shape `{icon, text}`) | Block schema already supports YAML override; only need new YAML override files in `themes/kleinstadtpflanze/pages/{home,product,category}.yaml`. **No block code change.** |

### NEW (new implementation needed)

| # | What | Why new |
|---|------|---------|
| 1 | `EditorialMiniBlock` + skeleton (`'editorial-mini'`) | No 2-col image+text section component exists. `featured-collection-block.tsx` is similar but renders products, not free text+CTA. Inline-synchronous, no async data. |
| 2 | `FaqAccordionBlock` + skeleton (`'faq-accordion'`) | No accordion / disclosure component exists in repo. New `'use client'` component (single-open mode, max-height 250ms transition, prefers-reduced-motion respected). Inline data via YAML. |
| 3 | `MotifGridBlock` + skeleton (`'motif-grid'`) | Tile pattern is similar to `category-showcase-block.tsx`, but motif tiles need different layout (3-col grid, image-tile w/ name overlay or below, link to motif filter). New component, REUSING the `Card variant="interactive" asChild` recipe. |
| 4 | `ServiceBoxBlock` + skeleton (`'service-box'`) | No "Material/Pflege/Größen" service-list component exists. New, REUSING `ICON_MAP` recipe + `bg-surface-alt`/`rounded-card`/`shadow-card`. Inline by default; `source: 'category'` or `'product'` deferred per Q4. |
| 5 | `MotifCrossSellBlock` + skeleton (`'motif-cross-sell'`) | Conceptually similar to `product-recommendations-block.tsx`, but data source is "same motif, different product types". Until Q2 is resolved, ship as inline-YAML (`{ tiles: [{name, image, href, productType}] }`) with graceful `null` rendering. Later: add `content_source: woocommerce` branch keyed on tag/taxonomy. |
| 6 | `BrandRecapBlock` + skeleton (`'brand-recap'`) | No brand-bar component exists. Static, inline-YAML, single line: small logo + brand name + tagline. Trivial. |

### AVOID (known debt, do not replicate)

| # | What | Decision Log Entry | Alternative |
|---|------|--------------------|-------------|
| 1 | Standalone `product-description` section under PDP main row in `kleinstadtpflanze` PDP | Feature replaces this (Discovery: "untere `product-description`-Section entfernt") | Inline the description via the prose recipe inside `ProductPurchaseBlock` (or a new combined block). Keep `ProductDescriptionBlock` in registry — other themes still use it. |
| 2 | `product-reviews` on `kleinstadtpflanze` PDP | Feature scopes it out (Discovery: "`product-reviews` aus kleinstadtpflanze-PDP entfernt"; "Reviews-Summary" in Out of Scope) | Don't include in `themes/kleinstadtpflanze/pages/product.yaml`. Block stays in registry for default theme. |
| 3 | `testimonials`, `newsletter-signup`, `featured-collection` on `kleinstadtpflanze` Homepage | Feature scopes them out (Discovery Out-of-Scope) | Don't include in `themes/kleinstadtpflanze/pages/home.yaml`. Blocks stay in registry. |
| 4 | Hardcoding colors / radii / shadows in new block JSX | CLAUDE.md "Component migration pattern": "Use only theme tokens — never hardcode colors or radii" | Use Tailwind utilities mapped via `app/globals.css:5-55` (`bg-surface-alt`, `text-text-primary`, `border-border`, `rounded-card`, `shadow-card`, `font-heading`, `aspect-product`). |
| 5 | Marketing claims in YAML payloads (4,9★, 2.180 Bewertungen, Klimaneutral, Made in Germany, Familienbetrieb) | Discovery Out-of-Scope ("Trust-Theater"); "Realitäts-Check" (User-Feedback) | Use neutral copy (Versand DE 3–5 Werktage, 30 Tage Rückgabe, Sichere Zahlung). |

---

## Conventions Detected

| Convention | Evidence | Count |
|------------|----------|-------|
| Block component file naming: `{type}-block.tsx` (kebab-case) | `frontend/components/blocks/*-block.tsx` | 26 files |
| Block component export naming: `Pascal{Type}Block` + optional `Pascal{Type}BlockSkeleton` | `hero-block.tsx`, `category-showcase-block.tsx`, `product-recommendations-block.tsx`, etc. | 26 / ~17 with skeletons |
| Skeleton wired in page via `SKELETON_MAP` (object literal in `page.tsx`) | `frontend/app/page.tsx:13-22`, `frontend/app/produkt/[slug]/page.tsx:42-46`, `frontend/app/kategorie/[slug]/page.tsx:81-95` | 3 pages |
| Inline-synchronous blocks use `null` as skeleton | `frontend/app/page.tsx:17-19` (`'usp-bar': null`, `'testimonials': null`, `'newsletter-signup': null`) | 3 |
| Path alias `@/...` for all internal imports | `frontend/components/blocks/*.tsx`, `frontend/lib/blocks/*.ts` | All |
| Top-of-file path comment (`// frontend/components/blocks/foo-block.tsx`) | `hero-block.tsx:1`, `trust-badges-block.tsx:1`, `usp-bar-block.tsx:1`, `category-showcase-block.tsx`, etc. | most files |
| Block registry grouped by Slice with `// Slice NN — ...` comments | `frontend/lib/blocks/registry.ts:15-34, 50-69` | 6 sections |
| Types live in `frontend/lib/blocks/types.ts` and are re-imported via `BlockComponentProps<...>` | `frontend/lib/blocks/types.ts:86-254` | 1 |
| YAML files use `sections: [{columns, gap, blocks: [{type, content_source, params}]}]` shape | `frontend/themes/default/pages/*.yaml` | 8 |
| `$route.{key}` placeholders in YAML params, resolved via `resolveParams` | `frontend/themes/default/pages/{product,category}.yaml`, `frontend/lib/blocks/page-config.ts:18-43` | many |
| Lucide-react icons via local `ICON_MAP: Record<string, ComponentType<LucideProps>>` | `trust-badges-block.tsx:5-13`, `usp-bar-block.tsx:6-11` | 2 |
| Card recipe `bg-surface rounded-card shadow-card p-6 h-full flex flex-col gap-3` | `testimonials-block.tsx:27` | 1 (template) |
| `aria-labelledby` for sections with stable id `<type>-heading` | `hero-block.tsx:29,46`, `category-showcase-block.tsx:20,22`, `product-recommendations-block.tsx:18,21` | many |
| `'use client'` only when state/event handlers are needed | `announcement-bar-block.tsx:1`, `newsletter-signup-block.tsx:1`, `product-purchase-block.tsx:1`, `product-gallery-block.tsx:1` | 4 of 26 |
| Mobile-first + touch-action: manipulation | `category-showcase-block.tsx:38`, `announcement-bar-block.tsx:51`, `globals.css:88-91` | many |
| `prose prose-sm text-text-secondary max-w-none` for HTML rendering | `product-description-block.tsx:15` | 1 |

---

## Key Integration Points

| Integration | Current Location | Impact of Feature |
|-------------|------------------|-------------------|
| Page config loader (3-tier YAML) | `frontend/lib/blocks/page-config.ts:53-119` | No code change. Picks up `themes/kleinstadtpflanze/pages/{home,product,category}.yaml` automatically when `NEXT_PUBLIC_THEME=kleinstadtpflanze`. |
| Block registry | `frontend/lib/blocks/registry.ts:38-69` | 6 new entries appended (theme-agnostic — usable by any theme later). |
| Section renderer (Suspense per block) | `frontend/lib/blocks/section-renderer.tsx:22-57` | No change. New blocks need a `null` (or real) entry in each page's `SKELETON_MAP`. |
| Homepage page | `frontend/app/page.tsx:13-22` | `SKELETON_MAP` extended with new block types. |
| PDP page | `frontend/app/produkt/[slug]/page.tsx:42-46` | `SKELETON_MAP` extended. `routeParams: { slug }` already passed → YAML `$route.slug` works for new blocks. |
| Kategorie page | `frontend/app/kategorie/[slug]/page.tsx:81-95` | `SKELETON_MAP` extended. `routeParams: { slug, page, sort }` already passed. |
| Hero default (`'use client'` not needed; SSR + prop branching) | `frontend/components/blocks/hero-block.tsx:18-61` | Variant branching logic added — still SSR. Backward compatible. |
| Product gallery (only Desktop layout changes) | `frontend/components/product/product-image-gallery.tsx:96-128` | Vertical-thumbs branch added under `hidden lg:` breakpoint; mobile branch (lines 73-94) unchanged. |
| Product purchase block | `frontend/components/blocks/product-purchase-block.tsx:19-40` | Either: append rendered description + service-box; or split into composed block. Already `'use client'` (variant selector) — `prose` HTML rendering inside a client component is acceptable per existing pattern. |
| WooCommerce data fetching for motif-cross-sell | `frontend/lib/blocks/data-loaders.ts:131-358`, `frontend/lib/graphql/queries.ts:1-282`, `frontend/lib/graphql/fragments.ts:1-110` | **Risk:** No existing query for "products with same tag / motif identity". Today only categories, related products, search are wired. New gql query + loader branch + fragment extension needed IF Q2 (motif identity) lands on tag/custom-taxonomy. Otherwise inline YAML + graceful null is sufficient for Slice 2. |
| Theme tokens & fonts | `frontend/app/globals.css:5-55`, `frontend/lib/theme/fonts.ts:3-32`, `frontend/themes/kleinstadtpflanze/theme.yaml:9-53` | No change. New blocks reference tokens (`bg-surface-alt`, `font-heading`, `text-text-primary`, etc.) and inherit Cream/Forest/Terracotta/Source-Serif automatically. |
| Trust-badges YAML override | `frontend/themes/default/pages/product.yaml:31-41` (current shape `{icon, text}`) | Schema already adequate (`{icon, text}`). YAML-only override in `themes/kleinstadtpflanze/pages/{home,product}.yaml` — no block code change. |

---

## Decision Log Context

> No `.decisions.md` found in the working directory.

| # | Decision | Relevant for this Feature | How |
|---|----------|---------------------------|-----|
| — | n/a | — | — |

---

## Scan Summary

| Metric | Value |
|--------|-------|
| Patterns found | 16 |
| REUSE recommendations | 13 |
| EXTEND recommendations | 9 |
| NEW recommendations | 6 |
| AVOID recommendations | 5 |
| Decision Log entries | 0 |
