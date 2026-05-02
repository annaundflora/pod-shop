# Post-Implement Review Report

**Feature:** kleinstadtpflanze-layout-flair
**Branch:** kleinstadtpflanze-layout-flair
**Default Branch:** master
**Date:** 2026-05-01

---

## Summary

**Verdict:** FAILED

### Part A: Code Quality

| Criterion | Findings |
|-----------|----------|
| A.1 Logic Errors | 0 |
| A.2 Error Handling | 0 |
| A.3 Security | 0 |
| A.4 Anti-Patterns | 0 |
| **Subtotal** | **0** |

### Part B: Codebase Alignment

| Criterion | Findings |
|-----------|----------|
| B.1 Duplicate Solution Paths | 0 |
| B.2 Abstraction Reuse | 0 |
| B.3 Schema Consistency | 2 |
| B.4 Dead Code / Unused Imports | 0 |
| B.5 Error Handling Divergence | 0 |
| B.6 Configuration Drift | 0 |
| B.7 Interface Inconsistency | 1 |
| B.8 Dependency Direction | 0 |
| B.9 Security Pattern Consistency | 0 |
| B.10 Performance Pattern Consistency | 0 |
| **Subtotal** | **3** |

| **Total** | **3** |

---

## Findings

### RV-1: Inconsistent property naming (snake_case) for inline data types vs. camelCase elsewhere

**Category:** B.3 Schema Consistency

**Problem:**
The new Slice-07 data interfaces (`EditorialMiniData`, `MotifGridItem`, `MotifCrossSellTile`) introduce `image_url` / `image_alt` (snake_case) for inline-YAML image fields. Every other data type in the codebase uses camelCase for image-related fields:

- `frontend/lib/blocks/types.ts:222` — `image?: { sourceUrl: string; altText: string }` (FeaturedCollectionData)
- `frontend/lib/blocks/types.ts:246-247` — `sourceUrl: string; altText: string` (CollectionHeaderData)
- `frontend/components/blocks/hero-block.tsx:27-30` — `interface HeroVisual { imageUrl?: string; alt?: string }` (introduced by THIS branch — proves the chosen convention is camelCase, since hero's own YAML key is `imageUrl`)
- `frontend/lib/blocks/types.ts:73` — `heroBackgroundImage` (camelCase)

The same branch even mixes both conventions in the same YAML file:

- `frontend/themes/kleinstadtpflanze/pages/home.yaml:31` — `imageUrl: ""` (hero visual)
- `frontend/themes/kleinstadtpflanze/pages/home.yaml:59,62,…` — `image_url: "/motifs/…"` (motif grid items)
- `frontend/themes/kleinstadtpflanze/pages/home.yaml:89-90` — `image_url: ""` / `image_alt: ""` (editorial mini)

Consumers (loader code, future block authors, future shop themes) cannot rely on a single convention without ad-hoc lookups.

**New code:**
- `frontend/lib/blocks/types.ts:269-270` (`EditorialMiniData.image_url`, `image_alt`)
- `frontend/lib/blocks/types.ts:286-287` (`MotifGridItem.image_url`, `image_alt`)
- `frontend/lib/blocks/types.ts:318-319` (`MotifCrossSellTile.image_url`, `image_alt`)
- `frontend/components/blocks/editorial-mini-block.tsx:14-15`
- `frontend/components/blocks/motif-grid-block.tsx:40-41`
- `frontend/components/blocks/motif-cross-sell-block.tsx:43-44`
- `frontend/themes/kleinstadtpflanze/pages/home.yaml:59-75, 89-90`

**Existing pattern:** `frontend/lib/blocks/types.ts:222, 246-247` (`sourceUrl`, `altText`); same branch establishes `imageUrl` for `HeroVisual` at `frontend/components/blocks/hero-block.tsx:27-30`.

**Recommendation:**
Rename the new fields to `imageUrl` and `imageAlt` (or `altText`) in:
1. The three interfaces in `frontend/lib/blocks/types.ts` (Slice-07 section).
2. The three block components that read them.
3. The kleinstadtpflanze YAML files that supply them.
This unifies the YAML / TypeScript naming with the existing `imageUrl`/`altText`/`sourceUrl` convention used throughout the project (and aligns with the `HeroVisual.imageUrl` shape this very branch introduces).

---

### RV-2: `defaultOpen` semantics inconsistent with `FaqAccordionData` schema (number vs. magic sentinel)

**Category:** B.3 Schema Consistency

**Problem:**
`FaqAccordionData.defaultOpen` is typed as `number` (`frontend/lib/blocks/types.ts:281` — "Optional initial open index (default: -1 = all closed)"), but the schema doc-comment encodes a magic sentinel `-1` to mean "all closed". The block reads it via:

```ts
const initialOpen = typeof data?.defaultOpen === 'number' ? data.defaultOpen : -1
```

so `defaultOpen: -1`, `defaultOpen: undefined`, and `defaultOpen` omitted all collapse to the same state — but a YAML author cannot tell this from the type. Other Slice-04 / Slice-06 data types (e.g. `EmptyStateData`, `OrderConfirmationData`, `NewsletterSignupData` in `frontend/lib/blocks/types.ts`) consistently use `optional + omitted = default` rather than a magic sentinel value. Likewise, `AnnouncementBarData.dismissible?: boolean` (`announcement-bar-block.tsx:12`) uses a plain optional flag.

**New code:** `frontend/lib/blocks/types.ts:281`, `frontend/components/blocks/faq-accordion-block.tsx:11`

**Existing pattern:**
- `frontend/lib/blocks/types.ts:99-104` — `AnnouncementBarData` uses optional fields without sentinel values.
- `frontend/lib/blocks/types.ts:124-128` — `SortBarData.currentQuery?: string` (omitted → "no query"; no sentinel).

**Recommendation:**
Either (a) remove `defaultOpen` from the public schema (the AC-1.4 acceptance criterion already specifies "no `defaultOpen` → all panels closed"), or (b) accept only non-negative integers (`defaultOpen?: number  // ≥ 0`) and let `undefined` mean "all closed". Update the comment accordingly so the schema does not document a magic value.

---

### RV-3: `WooCommerceLoaderParams.serviceBox` shape duplicates `ServiceBoxData` instead of referencing it

**Category:** B.7 Interface Inconsistency

**Problem:**
The Slice-2 passthrough merge in `data-loaders.ts:product_by_slug` (`frontend/lib/blocks/data-loaders.ts:198-200`) takes a `params.serviceBox` object and forwards it onto the product as `merged.serviceBox`. The block (`product-purchase-block.tsx:11`) then reads it as `serviceBox?: ServiceBoxData`. But the loader-params type re-defines the shape inline:

```ts
// frontend/lib/blocks/types.ts:40
serviceBox?: { items: { icon: string; label: string; detail: string }[]; source?: 'inline' | 'category' | 'product' }
```

while the canonical type definition lives at:

```ts
// frontend/lib/blocks/types.ts:304-313
export interface ServiceBoxItem { … }
export interface ServiceBoxData { source?: …; items: ServiceBoxItem[] }
```

The two shapes are equivalent today, but they will drift the moment one side adds a field. The codebase pattern elsewhere is to share types: `WordPressLoaderParams` and `WPCustomFieldsData` reference each other via shared keys (no inline duplication); `PaginatedProductsResult` and `PaginationMeta` are defined once and reused (`frontend/lib/blocks/types.ts:181-193`); `ProductRecommendationsData` is defined once and consumed by both the loader return type (`data-loaders.ts:262`) and the block (`product-recommendations-block.tsx:7-10`).

**New code:**
- `frontend/lib/blocks/types.ts:40` (inline `serviceBox` shape on `WooCommerceLoaderParams`)
- `frontend/lib/blocks/types.ts:304-313` (`ServiceBoxItem`, `ServiceBoxData` — declared but not used by the loader-params union)
- `frontend/lib/blocks/data-loaders.ts:198-200` (passthrough merge)

**Existing pattern:**
- `frontend/lib/blocks/types.ts:172-175` (`ProductRecommendationsData` — defined once, reused) ↔ `frontend/components/blocks/product-recommendations-block.tsx:7-10`.
- `frontend/lib/blocks/types.ts:181-193` (`PaginationMeta` + `PaginatedProductsResult` — reused across loaders).

**Recommendation:**
Reference the canonical type from the loader-params union instead of inlining its shape:

```ts
// frontend/lib/blocks/types.ts (replace inline shape on line 40)
serviceBox?: ServiceBoxData
```

(`ServiceBoxData` is already declared in the same file and exported.) This removes drift risk and matches how `ProductRecommendationsData` / `PaginationMeta` are wired.

---

## Changed Files

| File | Lines Changed | Findings |
|------|---------------|----------|
| frontend/app/kategorie/[slug]/page.tsx | +4 -0 | 0 |
| frontend/app/page.tsx | +6 -0 | 0 |
| frontend/app/produkt/[slug]/page.tsx | +7 -0 | 0 |
| frontend/components/blocks/brand-recap-block.tsx | +31 -0 | 0 |
| frontend/components/blocks/editorial-mini-block.tsx | +65 -0 | RV-1 |
| frontend/components/blocks/faq-accordion-block.tsx | +111 -0 | RV-2 |
| frontend/components/blocks/hero-block.tsx | +149 -0 | 0 |
| frontend/components/blocks/motif-cross-sell-block.tsx | +66 -0 | RV-1 |
| frontend/components/blocks/motif-grid-block.tsx | +56 -0 | RV-1 |
| frontend/components/blocks/product-gallery-block.tsx | +8 -1 | 0 |
| frontend/components/blocks/product-purchase-block.tsx | +26 -2 | 0 |
| frontend/components/blocks/service-box-block.tsx | +61 -0 | 0 |
| frontend/components/product/product-image-gallery.tsx | +99 -35 | 0 |
| frontend/lib/blocks/data-loaders.ts | +16 -1 | RV-3 |
| frontend/lib/blocks/registry.ts | +14 -0 | 0 |
| frontend/lib/blocks/types.ts | +72 -0 | RV-1, RV-2, RV-3 |
| frontend/tests/slices/kleinstadtpflanze-design-e/slice-03-theme.test.ts | +31 -6 | 0 |
| frontend/tests/slices/kleinstadtpflanze-layout-flair/brand-recap.test.tsx | +129 -0 | 0 |
| frontend/tests/slices/kleinstadtpflanze-layout-flair/editorial-mini.test.tsx | +108 -0 | 0 |
| frontend/tests/slices/kleinstadtpflanze-layout-flair/faq-accordion.test.tsx | +238 -0 | 0 |
| frontend/tests/slices/kleinstadtpflanze-layout-flair/hero-two-col.test.tsx | +196 -0 | 0 |
| frontend/tests/slices/kleinstadtpflanze-layout-flair/motif-cross-sell.test.tsx | +230 -0 | 0 |
| frontend/tests/slices/kleinstadtpflanze-layout-flair/motif-grid.test.tsx | +177 -0 | 0 |
| frontend/tests/slices/kleinstadtpflanze-layout-flair/pdp-gallery-vertical-thumbs.test.tsx | +322 -0 | 0 |
| frontend/tests/slices/kleinstadtpflanze-layout-flair/pdp-purchase-with-description.test.tsx | +243 -0 | 0 |
| frontend/tests/slices/kleinstadtpflanze-layout-flair/service-box.test.tsx | +180 -0 | 0 |
| frontend/tests/slices/kleinstadtpflanze-layout-flair/theme-overrides-category.test.ts | +674 -0 | 0 |
| frontend/tests/slices/kleinstadtpflanze-layout-flair/theme-overrides-home.test.ts | +296 -0 | 0 |
| frontend/tests/slices/kleinstadtpflanze-layout-flair/theme-overrides-product.test.ts | +462 -0 | 0 |
| frontend/tests/slices/shop-completeness/slice-02-produkt-page-enhancements.test.tsx | +1 -1 | 0 |
| frontend/tests/slices/shop-completeness/slice-04-homepage-enhancements.test.tsx | +5 -5 | 0 |
| frontend/tests/slices/shop-completeness/slice-05-suchseite.test.tsx | +2 -2 | 0 |
| frontend/themes/kleinstadtpflanze/pages/category.yaml | +128 -0 | 0 |
| frontend/themes/kleinstadtpflanze/pages/home.yaml | +132 -0 | RV-1 |
| frontend/themes/kleinstadtpflanze/pages/product.yaml | +99 -0 | 0 |
| specs/2026-04-26-kleinstadtpflanze-layout-flair/.implement-state.json | +30 -0 | 0 |
| specs/2026-04-26-kleinstadtpflanze-layout-flair/architecture.md | +1149 -0 | 0 |
| specs/2026-04-26-kleinstadtpflanze-layout-flair/codebase-scan.md | +173 -0 | 0 |
| specs/2026-04-26-kleinstadtpflanze-layout-flair/discovery.md | +405 -0 | 0 |
| specs/2026-04-26-kleinstadtpflanze-layout-flair/evidence/slice-01.json | +27 -0 | 0 |
| specs/2026-04-26-kleinstadtpflanze-layout-flair/evidence/slice-02.json | +24 -0 | 0 |
| specs/2026-04-26-kleinstadtpflanze-layout-flair/evidence/slice-03.json | +24 -0 | 0 |
| specs/2026-04-26-kleinstadtpflanze-layout-flair/gates/compliance-architecture.md | +304 -0 | 0 |
| specs/2026-04-26-kleinstadtpflanze-layout-flair/slim-spec.md | +354 -0 | 0 |

---

## Verified Codebase Alignment (no findings)

The following pattern checks passed (listed for traceability — not findings):

- **Block component contract**: All 6 new blocks export `Pascal{Type}Block` matching `BlockComponentProps<T>` (`frontend/lib/blocks/types.ts:90-92`). All include the path-comment header convention. (B.2)
- **Block registry**: 6 new entries appended under "Slice 07" comment block (`frontend/lib/blocks/registry.ts:35-83`); pattern (grouped by slice with comment header) matches the existing groups. (B.2)
- **SKELETON_MAP wiring**: Each new inline-sync block registered as `null` per the existing convention (`'usp-bar': null`, `'testimonials': null` at `frontend/app/page.tsx:17-18`). (B.2)
- **Theme tokens**: All 6 new blocks use `bg-surface-alt`, `text-text-primary`, `text-text-secondary`, `border-border`, `rounded-card`, `shadow-card`, `font-heading`, `text-primary` — no hardcoded colors / radii / shadows. (B.6)
- **Default-theme isolation**: `git diff master...kleinstadtpflanze-layout-flair -- frontend/themes/default/` is empty — default YAML files are byte-identical (AC-1.21, AC-2.17, AC-3.6 all verified). (B.6)
- **Data loaders**: `inlineLoader` reused for all 6 new blocks; `woocommerceLoader.product_by_slug` extended with passthrough merge (additive, behind `typeof === ...` guards), preserving backward compatibility. (B.2)
- **ICON_MAP convention**: Both `service-box-block.tsx:18-28` and `hero-block.tsx:12-20` use the local-`ICON_MAP` recipe (matching `trust-badges-block.tsx:5-13`, `usp-bar-block.tsx:6-11`). (B.2)
- **Card recipe**: `motif-grid-block.tsx:28-51`, `motif-cross-sell-block.tsx:30-60` reuse `Card variant="interactive" asChild` matching `category-showcase-block.tsx:29-58`. (B.2)
- **Prose recipe**: `product-purchase-block.tsx:50-55` reuses `prose prose-sm text-text-secondary max-w-none` + `dangerouslySetInnerHTML` matching `product-description-block.tsx:13-17`. (B.2)
- **Skeleton re-export trick**: `motif-cross-sell-block.tsx:66` re-exports `ProductGridBlockSkeleton as MotifCrossSellBlockSkeleton`, mirroring `product-recommendations-block.tsx:34`. (B.2)
- **`'use client'` boundary**: Only blocks needing state (`faq-accordion`) carry `'use client'`; pure render blocks (editorial-mini, motif-grid, motif-cross-sell, brand-recap, service-box) stay server. Pre-existing client blocks (`product-gallery-block`, `product-purchase-block`) keep their boundary. (B.8)
- **Apollo client**: `data-loaders.ts:product_by_slug` continues to use `getClient()` (server-client.ts via React.cache); no incorrect use of the client-side Apollo. (B.8)
- **Mobile-first / touch-action**: `motif-grid-block.tsx:36`, `motif-cross-sell-block.tsx:39` set `touch-action: manipulation` matching `category-showcase-block.tsx:37`. (B.10)
- **Suspense pattern**: All new blocks render under `SectionRenderer`'s Suspense boundary; inline-sync blocks correctly map to `null` skeletons. No N+1 risk; no synchronous block introduces blocking I/O. (B.10)
- **Pre-existing typecheck fixes** (`tests/slices/shop-completeness/*.tsx`) align test mock data shape with `ProductCardData` strict types (`stockStatus: 'IN_STOCK' as const`, `regularPrice`, `mediaDetails`, `productCategories`); changes are minimal and scoped to test files. (B.7)
