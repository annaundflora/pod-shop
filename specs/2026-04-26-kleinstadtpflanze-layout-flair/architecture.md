# Architecture: Kleinstadtpflanze — Layout & Components (Phase 2)

**Issue:** -- _(no issue per Discovery; traceability limited to branch + spec)_
**Status:** Ready
**Discovery:** `specs/2026-04-26-kleinstadtpflanze-layout-flair/discovery.md`
**Codebase Scan:** `specs/2026-04-26-kleinstadtpflanze-layout-flair/codebase-scan.md`
**Wireframes:** N/A — Design-E HTML at `/tmp/kp-design-e/` is the visual reference.

---

## Problem & Solution (carried from Discovery)

**Problem:** Phase 1 (style tokens) is merged. Default homepage/PDP/category structure feels empty for a small POD launch inventory; naive 1:1 adoption of Design E adds dishonest marketing theater (DealBar, Editorial-Werkstatt, Reviews 4.9★, Trust-Theater) that does not match a small Spreadshirt-fulfilled shop.

**Solution:** Add 6 new theme-agnostic block components + 2 in-place block extensions (`hero` `variant: "two-col"`, `product-gallery` `layout: "vertical-thumbs"`), wired entirely via YAML overrides under `themes/kleinstadtpflanze/pages/`. Default theme stays untouched. PDP description folds into the Purchase column. Motif-based cross-sell ships with a graceful-null contract until WC motif identity is decided.

---

## Scope & Boundaries (carried from Discovery)

**In scope:** 6 new blocks (`editorial-mini`, `faq-accordion`, `motif-grid`, `service-box`, `motif-cross-sell`, `brand-recap`), 2 extensions (`HeroBlock`, `ProductImageGallery`), 1 inline integration into `ProductPurchaseBlock` (description + service-box), 3 theme YAML overrides (`home.yaml`, `product.yaml`, `category.yaml` under `themes/kleinstadtpflanze/pages/`), trust-badges YAML re-copy (no code change), 3 new `SKELETON_MAP` entries per page.

**Out of scope:** DealBar, Editorial-Werkstatt, Reviews-Summary, Trust-Theater, Newsletter on kleinstadtpflanze, Testimonials on kleinstadtpflanze, Featured-Collection on kleinstadtpflanze, UtilBar/MenuRow, HeroC, Sticky-ATC, PDP Tabs, Hero-Visual asset pipeline, Motiv-Taxonomy in WC, Spreadshirt color extensions.

---

## 1. Architecture Overview

### How the new blocks plug into the existing block pipeline

```
                 NEXT_PUBLIC_THEME=kleinstadtpflanze
                             │
                             ▼
   app/page.tsx ─────► loadPageConfig('home', theme)
   app/produkt/[slug]/page.tsx ─► loadPageConfig('product', theme, {slug})
   app/kategorie/[slug]/page.tsx ─► loadPageConfig('category', theme, {slug, page, sort})
                             │
                             ▼
              frontend/lib/blocks/page-config.ts
                  (3-tier YAML lookup)
                             │
       ┌─────────────────────┼─────────────────────────┐
       │                     │                         │
       ▼                     ▼                         ▼
  Tier 1: themes/{theme}/pages/{type}/{slug}.yaml   (skipped — not used here)
  Tier 2: themes/kleinstadtpflanze/pages/{type}.yaml ◄── NEW (3 files)
  Tier 3: themes/default/pages/{type}.yaml          (default fallback, unchanged)
                             │
                             ▼
                    PageConfig { sections: [...] }
                             │
                             ▼
            frontend/lib/blocks/section-renderer.tsx
                             │
                For each block in each section:
                             │
            ┌────────────────┴────────────────┐
            │                                 │
            ▼                                 ▼
    resolveBlock(type)               loadBlockData(content_source, params)
    ─ frontend/lib/blocks/           ─ frontend/lib/blocks/data-loaders.ts
      registry.ts                       (wordpress | woocommerce | inline)
            │                                 │
            ▼                                 ▼
   ┌────────────────┐               ┌────────────────────┐
   │  Block.Comp    │ ◄─── data ─── │ inline → params.props      │
   │   (React)      │               │ wordpress → WP GraphQL     │
   └────────────────┘               │ woocommerce → WC GraphQL   │
            │                       └────────────────────────────┘
            ▼
    <Suspense fallback={ SKELETON_MAP[block.type] }>
        <BlockRenderer block={block} />
    </Suspense>
            │
            ▼
        Rendered HTML

NEW for this feature (additive only — no contract changes):
  • Registry: 6 new entries appended (Slice 07 group).
  • SKELETON_MAP: 6 new entries per page (mostly `null` for inline-sync blocks).
  • data-loaders.ts: NO change for Slice 1 (everything inline).
                     Optional new branch in Slice 2 IF Q2 lands on WC taxonomy.
```

### Key invariants

| Invariant | Why |
|-----------|-----|
| Default theme YAMLs (`themes/default/pages/*.yaml`) are NEVER edited. | Other themes (`zweiter-shop`) keep their behavior; backward compatibility. |
| New blocks are theme-agnostic — they live in `frontend/components/blocks/` next to the existing 26. | Any future theme can pick them up via YAML. |
| All HeroBlock/ProductImageGallery extensions are additive prop branches with the existing default unchanged. | Discovery Business Rule: "Backward Compatible". |
| All new block CSS uses theme tokens (`bg-primary`, `bg-surface-alt`, `text-text-primary`, `text-text-secondary`, `border-border`, `border-strong`, `rounded-card`, `shadow-card`, `shadow-card-hover`, `font-heading`, `aspect-product`). | CLAUDE.md: "never hardcode colors/radii/shadows"; multi-shop theming requires it. |

---

## 2. Component Architecture (6 new blocks)

Common contract (all new blocks):
- Live under `frontend/components/blocks/{type}-block.tsx`.
- Export `Pascal{Type}Block` and (where applicable) `Pascal{Type}BlockSkeleton`.
- Props: `BlockComponentProps<T>` from `frontend/lib/blocks/types.ts:86-88`.
- Top-of-file path comment per repo convention.
- Section heading (when present) uses `id="{type}-heading"` + `aria-labelledby`.
- Empty/null guard returns `null` (no empty container leak).
- Theme tokens only.

### 2.1 `editorial-mini` — `EditorialMiniBlock`

| Field | Value |
|-------|-------|
| File path | `frontend/components/blocks/editorial-mini-block.tsx` |
| Data source | `inline` (YAML `params.props`) |
| Server / Client | **Server** (RSC). No interactivity. |
| Skeleton export | Optional; register `null` in SKELETON_MAP (inline-sync, like `'testimonials': null`). |
| Registry entry | `'editorial-mini': EditorialMiniBlock as BlockComponent` |
| Type | `EditorialMiniData` in `frontend/lib/blocks/types.ts` (new "Slice 07" section) |
| Slice | 1 |

```ts
// frontend/lib/blocks/types.ts (append at end, new section)
// ============================================================
// Slice 07 — Kleinstadtpflanze Layout-Flair: Types
// ============================================================

export interface EditorialMiniData {
  tag?: string                                    // Optional pill label
  headline: string                                // Required h2
  text: string                                    // Required body (1-3 sentences)
  cta?: { text: string; href: string }            // Optional CTA
  image_url?: string                              // Optional left image URL
  image_alt?: string                              // Optional alt text (default: '')
}
```

```tsx
// frontend/components/blocks/editorial-mini-block.tsx (sketch — not implementation)
// Section: bg-surface-alt rounded-card shadow-card
// Layout: grid-cols-1 md:grid-cols-2 gap-8 p-6 md:p-10
// Left: Image (aspect-[4/3]) — fallback: <div class="bg-surface-elevated"/>
// Right: optional tag pill (bg-surface text-accent), h2 (font-heading text-text-primary),
//        p (text-text-secondary), Button asChild (CTA)
export function EditorialMiniBlock({ data }: BlockComponentProps<EditorialMiniData | null>) { ... }
```

### 2.2 `faq-accordion` — `FaqAccordionBlock`

| Field | Value |
|-------|-------|
| File path | `frontend/components/blocks/faq-accordion-block.tsx` |
| Data source | `inline` |
| Server / Client | **Client** — `'use client'` (uses `useState` for open-index, single-open mode). |
| Skeleton export | Optional; register `null` in SKELETON_MAP. |
| Registry entry | `'faq-accordion': FaqAccordionBlock as BlockComponent` |
| Type | `FaqAccordionData` |
| Slice | 1 |

```ts
export interface FaqAccordionItem {
  q: string                                       // Question
  a: string                                       // Answer (PLAIN STRING — see §6 / Q decision)
}

export interface FaqAccordionData {
  headline?: string                               // Optional section headline
  items: FaqAccordionItem[]                       // Min 1
  defaultOpen?: number                            // Optional initial open index (default: -1 = all closed)
}
```

A11y contract:
- Each row: `<button type="button" aria-expanded={isOpen} aria-controls={panelId}>` for the question header.
- Panel: `<div id={panelId} role="region" aria-labelledby={headerId}>`, animated via `max-height` (`0 → scrollHeight`) over 250ms `ease`.
- Keyboard: Enter/Space toggles (native `<button>`); Escape closes the open item (custom `onKeyDown` on the section).
- `prefers-reduced-motion`: under the media query, transition becomes `none` (instant snap).
- Focus-visible: `focus-visible:ring-2 focus-visible:ring-primary` (theme token).
- Single-open mode: clicking a different row closes the previously open one (Discovery Q3 = A).

### 2.3 `motif-grid` — `MotifGridBlock`

| Field | Value |
|-------|-------|
| File path | `frontend/components/blocks/motif-grid-block.tsx` |
| Data source | `inline` (Slice 1); future migration to `woocommerce` once motif taxonomy lands (see §4) |
| Server / Client | **Server** |
| Skeleton export | Optional; register `null`. |
| Registry entry | `'motif-grid': MotifGridBlock as BlockComponent` |
| Type | `MotifGridData` |
| Slice | 1 |

```ts
export interface MotifGridItem {
  name: string                                    // Tile label
  image_url: string                               // Tile image
  image_alt?: string
  href: string                                    // Internal route, MUST start with "/"
}

export interface MotifGridData {
  headline?: string
  items: MotifGridItem[]                          // Min 4
}
```

Reuses the `Card variant="interactive" asChild` recipe from `category-showcase-block.tsx:29-58`. Grid: `grid-cols-2 lg:grid-cols-3 gap-4`. Each tile: `aspect-[4/3]` Image + name overlay or below + hover lift via `shadow-card → shadow-card-hover`.

### 2.4 `service-box` — `ServiceBoxBlock`

| Field | Value |
|-------|-------|
| File path | `frontend/components/blocks/service-box-block.tsx` |
| Data source | `inline` (Slice 2 default per Discovery Q4 = A); `category` / `product` deferred. |
| Server / Client | **Server** |
| Skeleton export | Optional; register `null`. |
| Registry entry | `'service-box': ServiceBoxBlock as BlockComponent` |
| Type | `ServiceBoxData` |
| Slice | 2 |

```ts
export interface ServiceBoxItem {
  icon: string                                    // ICON_MAP key (lucide-react)
  label: string                                   // Bold label
  detail: string                                  // 1-line detail
}

export interface ServiceBoxData {
  source?: 'inline' | 'category' | 'product'      // Default 'inline'; non-inline deferred
  items: ServiceBoxItem[]                         // Min 2
}
```

ICON_MAP (local constant inside the block file, per Convention #11 in scan): extends the trust-badges set (`truck, shield, lock, refresh, star, check, package`) with at least: `droplet` (Pflege/wash), `ruler` (Größentabelle), `leaf` (Material), `coffee` (Tassen — spülmaschinenfest), `shopping-bag` (Beutel-Tragkraft).

Recipe: `bg-surface-alt rounded-card shadow-card p-6 flex flex-col gap-3` (testimonials-card recipe). Each item: row of `Icon (h-5 w-5 text-primary)` + `<div><strong class="text-text-primary">{label}</strong> <p class="text-sm text-text-secondary">{detail}</p></div>`.

### 2.5 `motif-cross-sell` — `MotifCrossSellBlock`

| Field | Value |
|-------|-------|
| File path | `frontend/components/blocks/motif-cross-sell-block.tsx` |
| Data source | `inline` (Slice 2 default — graceful-null contract); `woocommerce` branch deferred (see §4). |
| Server / Client | **Server** |
| Skeleton export | `export { ProductGridBlockSkeleton as MotifCrossSellBlockSkeleton }` (mirror of `product-recommendations-block.tsx:34`). Page registers `null` if inline. |
| Registry entry | `'motif-cross-sell': MotifCrossSellBlock as BlockComponent` |
| Type | `MotifCrossSellData` |
| Slice | 2 |

```ts
export interface MotifCrossSellTile {
  productName: string                             // e.g. "Helgoland Print"
  productType: string                             // e.g. "Tasse" | "Beutel" | "Shirt"
  image_url: string
  image_alt?: string
  href: string                                    // "/produkt/{slug}"
}

export interface MotifCrossSellData {
  heading?: string                                // Default: "Dieses Motiv auch auf …"
  tiles: MotifCrossSellTile[] | null              // null OR [] → block renders null
}
```

**Graceful-null contract:** Block returns `null` when `tiles == null || tiles.length === 0`. No layout break, no heading, no empty container. Section above and below collapse cleanly because `SectionRenderer` wraps each block in its own grid cell.

### 2.6 `brand-recap` — `BrandRecapBlock`

| Field | Value |
|-------|-------|
| File path | `frontend/components/blocks/brand-recap-block.tsx` |
| Data source | `inline` |
| Server / Client | **Server** |
| Skeleton export | Optional; register `null`. |
| Registry entry | `'brand-recap': BrandRecapBlock as BlockComponent` |
| Type | `BrandRecapData` |
| Slice | 1 |

```ts
export interface BrandRecapData {
  logoText?: string                               // Default: process.env.NEXT_PUBLIC_SHOP_NAME
  tagline: string                                 // Required, 1 sentence
  href?: string                                   // Default: "/"
}
```

Recipe: `border-y border-border bg-surface-alt py-6 text-center`. Inside: `<Link href={href} class="font-heading text-lg text-text-primary">{logoText}</Link> <span class="text-text-secondary">·</span> <span class="text-sm text-text-secondary">{tagline}</span>`.

### 2.7 Registry registration (concrete patch)

```ts
// frontend/lib/blocks/registry.ts (append imports + entries)
// Slice 07 — Kleinstadtpflanze Layout-Flair:
import { EditorialMiniBlock } from '@/components/blocks/editorial-mini-block'
import { FaqAccordionBlock } from '@/components/blocks/faq-accordion-block'
import { MotifGridBlock } from '@/components/blocks/motif-grid-block'
import { ServiceBoxBlock } from '@/components/blocks/service-box-block'
import { MotifCrossSellBlock } from '@/components/blocks/motif-cross-sell-block'
import { BrandRecapBlock } from '@/components/blocks/brand-recap-block'

// in const registry:
  // Slice 07 — Kleinstadtpflanze Layout-Flair:
  'editorial-mini': EditorialMiniBlock as BlockComponent,
  'faq-accordion': FaqAccordionBlock as BlockComponent,
  'motif-grid': MotifGridBlock as BlockComponent,
  'service-box': ServiceBoxBlock as BlockComponent,
  'motif-cross-sell': MotifCrossSellBlock as BlockComponent,
  'brand-recap': BrandRecapBlock as BlockComponent,
```

---

## 3. Extended Components (in-place extensions)

### 3.1 `HeroBlock` — add `variant: "two-col"`

**File:** `frontend/components/blocks/hero-block.tsx:18-61`

**Current loader:** `wordpress` (reads WP custom fields). To stay backward compatible we add new YAML-driven props that flow alongside the existing WP fields.

**Decision:** The `variant` switch and all new fields (`tag`, `ctaSecondary`, `trustItems`, `visual.imageUrl`) are **YAML params** — not WP custom fields. The block reads them off `data` regardless of source. This is achieved by changing the YAML for kleinstadtpflanze hero from `content_source: wordpress` to `content_source: inline` (the kleinstadtpflanze hero is content-curated by the theme, not by WP editors). The default-theme hero keeps `content_source: wordpress` and continues to ignore the new fields (they will simply be `undefined`, the existing render path runs).

**Backward compatibility:** When `data.variant !== 'two-col'`, the existing render path (lines 25-60) runs unchanged. New fields default to `undefined` and are not referenced. No risk to default theme.

```ts
// frontend/components/blocks/hero-block.tsx — extended interface
interface HeroBlockData {
  // Existing (default variant, WP source)
  heroHeadline?: string | null
  heroSubline?: string | null
  heroCtaText?: string | null
  heroCtaLink?: string | null
  heroBackgroundImage?: string | null
  // NEW (two-col variant, inline YAML source)
  variant?: 'default' | 'two-col'                 // Default: 'default'
  tag?: string                                    // Small label above h1
  ctaSecondary?: { text: string; href: string }   // Ghost CTA next to primary
  trustItems?: { icon: string; text: string }[]   // Slim trust row (max 3)
  visual?: { imageUrl?: string; alt?: string }    // Right-side visual; fallback = SVG placeholder
}

export function HeroBlock({ data }: BlockComponentProps<HeroBlockData | null>) {
  if (data?.variant === 'two-col') {
    return <HeroTwoCol data={data} />
  }
  // ... existing render unchanged
}
```

`HeroTwoCol` (private subcomponent, same file): `grid grid-cols-1 md:grid-cols-[1.1fr_1fr] gap-8 lg:gap-12 items-center` (Design-E `.e-hero-a` ratio). Mobile stacks. Right column either renders `<Image>` or an SVG placeholder using `bg-surface-alt`. Headline supports a `text-primary` accent span; for Slice 1 we render the full headline as one string and apply the accent to the whole `h1` (advanced inline-span via `dangerouslySetInnerHTML` is **out of scope** here — the brand-color accent is achieved by `text-primary` on the entire `h1`, with `font-heading` from theme tokens).

Trust-Row: `<ul class="flex flex-wrap gap-x-6 gap-y-2 mt-6 pt-6 border-t border-border">` with the same ICON_MAP recipe used in `trust-badges-block.tsx:5-13` (copied locally per repo convention).

### 3.2 `ProductImageGallery` — add `layout: "vertical-thumbs"`

**File:** `frontend/components/product/product-image-gallery.tsx:15-132`

**Routing of the layout prop:** YAML carries `layout` on the `product-gallery` block params. `ProductGalleryBlock` (`frontend/components/blocks/product-gallery-block.tsx:1-33`) reads it and forwards.

```ts
// frontend/components/product/product-image-gallery.tsx — extended interface
interface ProductImageGalleryProps {
  images: ProductImage[]
  productName: string
  layout?: 'default' | 'vertical-thumbs'         // Default: 'default'
}
```

**Mobile fallback unchanged:** The mobile branch (lines 73-94, dots-only) is identical for both layouts — we never apply vertical-thumbs below `lg:`. Discovery: "Vertical thumbs only on `lg:` and up".

**Desktop branch (vertical-thumbs):** Outer container becomes `lg:grid lg:grid-cols-[80px_1fr] lg:gap-4`. Thumbs render in the **first** column as a vertical stack (`flex flex-col gap-3`); main image stays in the **second** column. Each thumb stays `w-18 h-18` per the existing recipe (lines 102-126). All other styling (active border, hover shadow) reuses the existing class list verbatim.

**Default layout:** When `layout !== 'vertical-thumbs'`, the existing render (horizontal thumb row below the main image, lines 96-127) runs unchanged.

`ProductGalleryBlock` patch:

```ts
// frontend/components/blocks/product-gallery-block.tsx — extended
export function ProductGalleryBlock({ data }: BlockComponentProps<(ProductDetailData & { layout?: 'default' | 'vertical-thumbs' }) | null>) {
  // ... existing data resolution ...
  return (
    <ProductImageGallery
      images={allImages}
      productName={data.name}
      layout={(data as any).layout ?? 'default'}
    />
  )
}
```

The `layout` arrives via YAML `params.layout` and reaches `data` because `data-loaders.ts:woocommerceLoader` returns the WC product, while the YAML param is sidecar. **Implementation detail:** we add a tiny merge in the loader (or in `BlockRenderer`) that splices `params.layout` into the returned `data`. Cleanest: extend `WooCommerceLoaderParams` with optional `layout?: string` and merge `{ ...product, layout: params.layout }` inside the `product_by_slug` branch of `woocommerceLoader`. This is the smallest extension and keeps Tier 1/2/3 YAML the source of truth.

### 3.3 `ProductPurchaseBlock` — description + service-box integration

**File:** `frontend/components/blocks/product-purchase-block.tsx:8-40`

**Decision: Extend `ProductPurchaseBlock` in place, do NOT introduce a new combined block.**

| Option | Pros | Cons |
|--------|------|------|
| **A. Extend `ProductPurchaseBlock` (chosen)** | Single block on PDP-right column → matches Discovery's "in Purchase-Spalte integriert"; reuses existing `'use client'` boundary; YAML stays simple (one block, one slot in the 2-col `SectionLayout`); description and service-box live where the user reads price + variant. | Block becomes ~3 concerns (purchase, description, service-box); requires a new optional data shape; couples description to purchase. |
| B. New combined `product-detail-card` block | Cleaner separation; reusable elsewhere. | Duplicates purchase logic OR composes purchase, doubling the registry surface; YAML needs a new block type just for PDP-right; the existing 2-col `SectionLayout` already gives us composition. |

Rationale for A: the purchase block is already `'use client'` (variant selector). Discovery is explicit about removing `product-description` as a standalone section AND putting description "below ATC, above service-box". Composing within Section Layout (option B) would re-render the purchase block AND a new description block AND a new service-box block in the same right column — three blocks where one is sufficient. The block already owns the right column; extending it preserves the single-source-of-truth.

```ts
// frontend/components/blocks/product-purchase-block.tsx — extended
'use client'
// Existing imports +
type ProductPurchaseData = ProductDetailData & {
  withDescription?: boolean                       // YAML toggle; default false
  serviceBox?: ServiceBoxData                     // Optional inline service-box payload
}

export function ProductPurchaseBlock({ data }: BlockComponentProps<ProductPurchaseData | null>) {
  // ... existing render: <h1>, price, <hr>, <ProductVariantSelector> ...

  // NEW: optional description (after variant selector)
  if (data.withDescription && data.description) {
    // Reuse the exact recipe from product-description-block.tsx:13-17
    // (DON'T import that block — copy the recipe locally to keep the extension self-contained)
    {/* <div className="mt-8 prose prose-sm text-text-secondary max-w-none"
         dangerouslySetInnerHTML={{ __html: data.description }} /> */}
  }

  // NEW: optional service-box (after description)
  if (data.serviceBox && data.serviceBox.items?.length) {
    return <ServiceBoxBlock data={data.serviceBox} />  // composed inline
  }
}
```

Loader merge (mirrors §3.2): the `product_by_slug` branch in `woocommerceLoader` merges YAML params (`withDescription`, `serviceBox`) into the returned product so `data.withDescription` and `data.serviceBox` are populated. `WooCommerceLoaderParams` gets two new optional fields; since they are forwarded as-is, no schema change in graphql is needed.

The standalone `ProductDescriptionBlock` (`frontend/components/blocks/product-description-block.tsx`) **stays in the registry and the default theme** — only the kleinstadtpflanze `product.yaml` omits it.

---

## 4. Data & GraphQL

### 4.1 Service-box source (Slice 2 default = INLINE YAML)

Per Discovery Q4 = A: service-box items are **inline YAML per category-slug**, not WC meta. Slice 2 ships with one canonical YAML payload **per category** that the kleinstadtpflanze `product.yaml` references. Because Tier-1 YAML lookup (`themes/kleinstadtpflanze/pages/product/{slug}.yaml`) already exists in `loadPageConfig` (page-config.ts:69-71), **we get per-product YAML overrides for free** if we ever need them.

Recommended pragma for Slice 2: one `themes/kleinstadtpflanze/pages/product.yaml` that hard-codes a "neutral" service-box (e.g. Material/Pflege/Größen) for all products initially, with the option to add `themes/kleinstadtpflanze/pages/product/{categoryRepresentativeSlug}.yaml` if a single product needs a specialized box. Per-category YAML is **deferred** until we know there's pain.

Concrete YAML shape per category (examples):

```yaml
# Tasse
service-box:
  items:
    - icon: coffee
      label: "Spülmaschinenfest"
      detail: "Bis 60 °C, formstabile Glasur"
    - icon: ruler
      label: "330 ml Fassung"
      detail: "Standard-Henkelmaß, rechtshändig"
    - icon: package
      label: "Versand DE 3–5 Werktage"
      detail: "Spreadshirt-fulfilled, neutrales Paket"

# Beutel
service-box:
  items:
    - icon: shopping-bag
      label: "Tragkraft 8 kg"
      detail: "Baumwoll-Canvas, gepolsterte Henkel"
    - icon: leaf
      label: "Faires Material"
      detail: "Standard-Baumwolle, Spreadshirt-Auswahl"
    - icon: package
      label: "Versand DE 3–5 Werktage"
      detail: "Spreadshirt-fulfilled, neutrales Paket"

# Shirt
service-box:
  items:
    - icon: ruler
      label: "Größen S–XXL"
      detail: "Schnitt: regulär; Größentabelle siehe FAQ"
    - icon: droplet
      label: "Pflege bei 30 °C"
      detail: "Auf links waschen, nicht in den Trockner"
    - icon: package
      label: "Versand DE 3–5 Werktage"
      detail: "Spreadshirt-fulfilled, neutrales Paket"
```

> Marketing-honesty review (Discovery Out of Scope): No "Familienbetrieb", no "Klimaneutral", no "Made in Germany"; "Spreadshirt-fulfilled" is named once in the shipping detail to avoid implying our own production.

### 4.2 Motif identity for `motif-cross-sell` — graceful-null contract + extension path

**Current state (per codebase scan #7 in EXTEND, integration row "WooCommerce data fetching for motif-cross-sell"):** No GraphQL query exposes "products with same tag/motif". `productTags` is not in `PRODUCT_DETAIL_FRAGMENT` / `PRODUCT_CARD_FRAGMENT`. Custom motif taxonomy does not exist in WC.

**Slice-2 contract (graceful null):**

| Step | Behavior |
|------|----------|
| YAML on PDP carries `motif-cross-sell` block with `content_source: inline`, `params.props.tiles: null` (or `[]`). | |
| `MotifCrossSellBlock` receives `data.tiles == null` → returns `null`. | No heading, no empty grid, no layout break. |
| The PDP renders without the section (the surrounding `space-y-12` collapses naturally — verified by the existing `ProductRecommendationsBlock` null-guard pattern at `product-recommendations-block.tsx:16`). | |

**Extension path (when motif identity lands — separate feature):**

| Decision | Implementation step |
|----------|---------------------|
| (Q2 = A) WC `product_tag` per motif | 1. Add `productTags { nodes { name slug } }` fragment to `PRODUCT_DETAIL_FRAGMENT` (`frontend/lib/graphql/fragments.ts`). 2. Add `'products_by_motif'` to `WooCommerceLoaderParams.query` (`types.ts:13-37`). 3. Add a new `gql` query in `lib/graphql/queries.ts` that filters `products` by `productTags.nodes.slug IN [tag]` and excludes the current product/category. 4. New branch in `woocommerceLoader` returns `MotifCrossSellData`. 5. Switch the YAML for `motif-cross-sell` to `content_source: woocommerce, params.query: products_by_motif, params.slug: $route.slug`. |
| (Q2 = B) Custom taxonomy `motif` | Same as above but use the custom taxonomy resolver. |
| (Q2 = C) Product meta field | Custom resolver in WP plugin to expose meta via WPGraphQL; query as above. |
| (Q2 = D) No motif modeling — defer | Block stays inline-null-tiles. No code change. |

The block component itself does not change between modes — only the data source.

### 4.3 `motif-grid` — Slice-1 default = pure inline YAML, future migration path

Slice 1 ships with a hand-curated `motif-grid` payload in `themes/kleinstadtpflanze/pages/home.yaml` (`content_source: inline, params.props.items: [...]`). Each item carries `name`, `image_url`, `href`. Hrefs are validated as starting with `/` (internal route).

**Future migration path** (when motif identity lands):
- New `'motif_list'` query type in `WooCommerceLoaderParams.query` returning all motif tags/taxonomies + a representative image (picked from the first product carrying the motif).
- `MotifGridBlock` component does not change — same `MotifGridItem` shape.
- YAML toggles `content_source: inline → woocommerce, params.query: motif_list`.

---

## 5. YAML Schema Additions

### 5.1 `themes/kleinstadtpflanze/pages/home.yaml` (new file, full content)

```yaml
# themes/kleinstadtpflanze/pages/home.yaml
# Kleinstadtpflanze Homepage — Layout & Components Phase 2 (ehrlich-klein).
# Section order per discovery.md §"Screen: Homepage":
#   1. Hero (variant two-col)  2. Categories  3. Products  4. Motif-Grid
#   5. Editorial-Mini  6. FAQ  7. Trust-Badges  8. Brand-Recap

sections:
  - columns: 1
    blocks:
      - type: hero
        content_source: inline
        params:
          props:
            variant: "two-col"
            tag: "Aus Schleswig-Holstein"
            heroHeadline: "Stadt-Motive für jeden Tag"
            heroSubline: "Kleine Drucke, persönlich gewählt — Tassen, Beutel, Shirts."
            heroCtaText: "Kollektion ansehen"
            heroCtaLink: "/kategorie/tassen"
            ctaSecondary:
              text: "Was ist Kleinstadtpflanze?"
              href: "#editorial-mini-heading"
            trustItems:
              - icon: truck
                text: "Versand DE 3–5 Werktage"
              - icon: refresh
                text: "30 Tage Rückgabe"
              - icon: lock
                text: "Sichere Zahlung"
            visual:
              imageUrl: ""        # empty → renders SVG placeholder
              alt: ""

  - columns: 1
    blocks:
      - type: category-showcase
        content_source: woocommerce
        params:
          query: product_categories
          first: 6

  - columns: 1
    blocks:
      - type: product-grid
        content_source: woocommerce
        params:
          query: featured_products
          first: 4

  - columns: 1
    blocks:
      - type: motif-grid
        content_source: inline
        params:
          props:
            headline: "Motive entdecken"
            items:
              - name: "Helgoland"
                image_url: "/motifs/helgoland.jpg"
                href: "/suche?q=helgoland"
              - name: "Husum"
                image_url: "/motifs/husum.jpg"
                href: "/suche?q=husum"
              - name: "Glückstadt"
                image_url: "/motifs/glueckstadt.jpg"
                href: "/suche?q=glueckstadt"
              - name: "Eckernförde"
                image_url: "/motifs/eckernfoerde.jpg"
                href: "/suche?q=eckernfoerde"
              - name: "Friedrichstadt"
                image_url: "/motifs/friedrichstadt.jpg"
                href: "/suche?q=friedrichstadt"
              - name: "Tönning"
                image_url: "/motifs/toenning.jpg"
                href: "/suche?q=toenning"

  - columns: 1
    blocks:
      - type: editorial-mini
        content_source: inline
        params:
          props:
            tag: "Über Kleinstadtpflanze"
            headline: "Was ist Kleinstadtpflanze?"
            text: "Eine kleine Auswahl Stadt-Motive aus Schleswig-Holstein, gedruckt auf Alltagsdingen. Auf Bestellung produziert — kein Lager, keine Massenware."
            cta:
              text: "Alle Kategorien"
              href: "/"
            image_url: ""        # empty → uses surface-alt placeholder
            image_alt: ""

  - columns: 1
    blocks:
      - type: faq-accordion
        content_source: inline
        params:
          props:
            headline: "Häufige Fragen"
            items:
              - q: "Wie lange dauert der Versand?"
                a: "Innerhalb Deutschlands 3–5 Werktage nach Druckfreigabe. Wir produzieren auf Bestellung."
              - q: "Wer druckt die Produkte?"
                a: "Unsere Drucke werden bei einem etablierten Print-on-Demand-Partner in Europa hergestellt."
              - q: "Wie pflege ich mein Produkt?"
                a: "Tassen sind spülmaschinenfest. Shirts und Beutel bei 30 °C auf links waschen, nicht in den Trockner."
              - q: "Kann ich umtauschen?"
                a: "Ja — 30 Tage Rückgaberecht. Bitte unbenutzt und in der Originalverpackung zurücksenden."
              - q: "Welche Größen gibt es?"
                a: "Shirts gibt es in S–XXL. Die Größentabelle findet sich auf jeder Produktseite."

  - columns: 1
    blocks:
      - type: trust-badges
        content_source: inline
        params:
          props:
            items:
              - icon: truck
                text: "Versand DE 3–5 Werktage"
              - icon: refresh
                text: "30 Tage Rückgabe"
              - icon: lock
                text: "Sichere Zahlung"

  - columns: 1
    blocks:
      - type: brand-recap
        content_source: inline
        params:
          props:
            tagline: "Kleine Drucke aus Schleswig-Holstein."
            href: "/"
```

> **NOTE on placeholder copy:** All German copy above is a starting proposal that satisfies the honesty constraints (no Familienbetrieb, no review claims, no Klimaneutral, no Made-in-Germany lie, neutral POD wording). Final wording — especially the brand-recap tagline — is a Slice-1 user decision (Open Question Q1). The architecture commits only to the *shape* of the YAML.

### 5.2 `themes/kleinstadtpflanze/pages/product.yaml` (new file, full content)

```yaml
# themes/kleinstadtpflanze/pages/product.yaml
# Kleinstadtpflanze PDP — Layout & Components Phase 2.
# Section order per discovery.md §"Screen: PDP":
#   1. Hauptbereich (2-col gallery vertical-thumbs | purchase + description + service-box)
#   2. Trust-Badges  3. Motif-Cross-Sell  4. Product-Recommendations
#   5. Mini-FAQ  6. Brand-Recap

sections:
  - columns: 2
    gap: "gap-16"
    blocks:
      - type: product-gallery
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"
          layout: "vertical-thumbs"

      - type: product-purchase
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"
          withDescription: true
          serviceBox:
            items:
              - icon: package
                label: "Versand DE 3–5 Werktage"
                detail: "Spreadshirt-fulfilled, neutrales Paket"
              - icon: refresh
                label: "30 Tage Rückgabe"
                detail: "Unbenutzt in Originalverpackung"
              - icon: ruler
                label: "Größen / Maße"
                detail: "Siehe Größentabelle in den FAQ"

  - columns: 1
    blocks:
      - type: trust-badges
        content_source: inline
        params:
          props:
            items:
              - icon: truck
                text: "Versand DE 3–5 Werktage"
              - icon: refresh
                text: "30 Tage Rückgabe"
              - icon: lock
                text: "Sichere Zahlung"

  - columns: 1
    blocks:
      - type: motif-cross-sell
        content_source: inline
        params:
          props:
            heading: "Dieses Motiv auch auf …"
            tiles: null    # null → block renders null (graceful)
                           # When motif data lands: switch to content_source: woocommerce,
                           # query: products_by_motif, slug: $route.slug

  - columns: 1
    blocks:
      - type: product-recommendations
        content_source: woocommerce
        params:
          query: product_recommendations
          source: related
          slug: "$route.slug"
          first: 4
          heading: "Vielleicht auch was für dich"

  - columns: 1
    blocks:
      - type: faq-accordion
        content_source: inline
        params:
          props:
            headline: "Häufige Fragen"
            items:
              - q: "Wie lange dauert der Versand?"
                a: "Innerhalb Deutschlands 3–5 Werktage nach Druckfreigabe."
              - q: "Wie pflege ich mein Produkt?"
                a: "Tassen sind spülmaschinenfest. Textilien bei 30 °C auf links waschen."
              - q: "Kann ich umtauschen?"
                a: "Ja — 30 Tage Rückgaberecht. Bitte unbenutzt und in Originalverpackung zurücksenden."

  - columns: 1
    blocks:
      - type: brand-recap
        content_source: inline
        params:
          props:
            tagline: "Kleine Drucke aus Schleswig-Holstein."
            href: "/"
```

> **Notes:**
> - `product-description` (standalone section) is **omitted** — replaced by `withDescription: true` on `product-purchase`.
> - `product-reviews` is **omitted** — Discovery scopes it out for kleinstadtpflanze.
> - `motif-cross-sell` ships with `tiles: null` → graceful null until §4.2 extension lands.

### 5.3 `themes/kleinstadtpflanze/pages/category.yaml` (new file, full content)

```yaml
# themes/kleinstadtpflanze/pages/category.yaml
# Kleinstadtpflanze Category — Layout & Components Phase 2.
# Sections 1-8 mirror themes/default/pages/category.yaml.
# Sections 9-11 are NEW (under Pagination/Empty-State).

sections:
  # Sections 1-8 = same as themes/default/pages/category.yaml — copy verbatim.
  - columns: 1
    blocks:
      - type: breadcrumb
        content_source: woocommerce
        params: { query: category_meta, slug: $route.slug }

  - columns: 1
    blocks:
      - type: page-heading
        content_source: woocommerce
        params: { query: products_by_category, slug: $route.slug, page: $route.page, perPage: 24, sort: $route.sort }

  - columns: 1
    blocks:
      - type: filter-chips
        content_source: woocommerce
        params: { query: product_categories, slug: $route.slug }

  - columns: 1
    blocks:
      - type: product-count
        content_source: woocommerce
        params: { query: products_by_category, slug: $route.slug, page: $route.page, perPage: 24, sort: $route.sort }

  - columns: 1
    blocks:
      - type: sort-bar
        content_source: inline
        params:
          props:
            currentSort: $route.sort
            baseUrl: /kategorie/$route.slug

  - columns: 1
    blocks:
      - type: product-grid
        content_source: woocommerce
        params: { query: products_by_category, slug: $route.slug, page: $route.page, perPage: 24, sort: $route.sort }

  - columns: 1
    blocks:
      - type: pagination
        content_source: woocommerce
        params: { query: products_by_category, slug: $route.slug, page: $route.page, perPage: 24, sort: $route.sort }

  - columns: 1
    blocks:
      - type: empty-state
        content_source: inline
        params:
          props:
            headline: "Keine Produkte gefunden"
            text: "In dieser Kategorie sind aktuell keine Produkte verfügbar."
            links:
              - { label: "Alle Kategorien", href: "/" }

  # === NEW for kleinstadtpflanze (Slice 3) ===

  - columns: 1
    blocks:
      - type: product-recommendations
        content_source: woocommerce
        params:
          query: product_recommendations
          source: bestsellers          # cross-category (existing source mode)
          first: 4
          heading: "Vielleicht auch was für dich"

  - columns: 1
    blocks:
      - type: faq-accordion
        content_source: inline
        params:
          props:
            headline: "Häufige Fragen"
            items:
              - q: "Wie lange dauert der Versand?"
                a: "Innerhalb Deutschlands 3–5 Werktage nach Druckfreigabe."
              - q: "Kann ich umtauschen?"
                a: "Ja — 30 Tage Rückgaberecht."
              - q: "Wie pflege ich mein Produkt?"
                a: "Pflegehinweise stehen auf jeder Produktseite."

  - columns: 1
    blocks:
      - type: brand-recap
        content_source: inline
        params:
          props:
            tagline: "Kleine Drucke aus Schleswig-Holstein."
            href: "/"
```

> Note on `product-recommendations.source`: `'bestsellers'` is the existing cross-category source in `data-loaders.ts` (`GET_BESTSELLER_PRODUCTS`). No new query/source mode needed.

---

## 6. Data Types & Validation (Realistic Data Check)

| Field | Storage | Validation | Reflow / Length Budget |
|-------|---------|------------|------------------------|
| `editorial-mini.image_url` | YAML → `string` (URL or empty) | URL string; empty allowed (placeholder fallback) | No DB column. No VARCHAR limit. |
| `editorial-mini.headline` | YAML → `string` | Required, non-empty | h2 → `text-balance` from `globals.css:84`. 1-line target on lg, wraps gracefully on sm. |
| `editorial-mini.text` | YAML → `string` | Required, 1-3 sentences (~ 300 chars target) | Renders inside `text-text-secondary` paragraph. Long text reflows; if a content editor pastes 800+ chars, the block grows vertically — no overflow risk. |
| `editorial-mini.cta` | YAML → `{ text, href }` | Both required when `cta` present | href is internal `/`-prefixed or anchor `#…`. |
| `motif-grid.items[].image_url` | YAML → `string` (URL) | Required, points to `/motifs/*.jpg` (Next/Image static) | Used inside `<Image>` with `aspect-[4/3]`. No DB column. |
| `motif-grid.items[].href` | YAML → `string` | **Validated as starting with `/`** (internal route). Block-level guard: skip item if `!href.startsWith('/')`. | – |
| `motif-grid.items[].name` | YAML → `string` | Required, non-empty | Tile label, max ~20 chars practical; CSS `truncate` if needed. |
| `faq-accordion.items[].q` | YAML → `string` | Required | Renders as `<button>` text; long questions wrap. No HTML. |
| `faq-accordion.items[].a` | YAML → `string` (PLAIN) | Required | **Decision: PLAIN STRING (no Markdown render) for Slice 1.** Rationale: 5-item FAQ for a small POD shop does not need links/lists; plain string keeps the block trivial; XSS surface = zero (no `dangerouslySetInnerHTML`); future Markdown opt-in is a 1-line change to add `react-markdown` if a content editor needs it. |
| `service-box.items[].icon` | YAML → `string` (ICON_MAP key) | Validated against local ICON_MAP; unknown → render no icon (graceful). | – |
| `service-box.items[].label` | YAML → `string` | Required | Bold; 1 line. |
| `service-box.items[].detail` | YAML → `string` | Required | 1-2 lines, `text-sm text-text-secondary`. |
| `service-box.source` | YAML → `'inline' \| 'category' \| 'product'` | Default `'inline'`; Slice 2 ignores non-inline (no-op). | – |
| `motif-cross-sell.tiles` | YAML → `MotifCrossSellTile[] \| null` | `null` or `[]` → block renders `null` (graceful). | – |
| `motif-cross-sell.tiles[].href` | YAML → `string` | Validated as `/produkt/{slug}`. | – |
| `brand-recap.tagline` | YAML → `string` | Required, 1 sentence (~ 60 chars target) | Reflows on narrow viewports to two lines via `text-balance`; section height grows. |
| `brand-recap.logoText` | YAML → `string` (optional) | Default = `process.env.NEXT_PUBLIC_SHOP_NAME`. | – |
| `hero.variant` | YAML → `'default' \| 'two-col'` | Enum; default render path runs when missing. | – |
| `hero.tag` | YAML → `string` | Optional. | – |
| `hero.ctaSecondary` | YAML → `{ text, href }` | Optional. | – |
| `hero.trustItems` | YAML → `{ icon, text }[]` | Max 3 items (block enforces `slice(0,3)`). | – |
| `hero.visual.imageUrl` | YAML → `string` (URL or empty) | Empty → SVG placeholder. | – |
| `product-gallery.layout` | YAML → `'default' \| 'vertical-thumbs'` | Enum; default layout when missing. | – |
| `product-purchase.withDescription` | YAML → `boolean` | Default `false`. | When `true`, description renders inside the right column; `prose prose-sm max-w-none` + `text-text-secondary` → wraps to column width naturally. Discovery Q6 = A (full render, no truncation in Slice 2). |
| `product-purchase.serviceBox` | YAML → `ServiceBoxData` | Optional. | – |

**Gate-1 explicit statement:** **No new DB columns.** All new block fields are YAML-only (read at build/RSC time). VARCHAR/length limits do not apply. The only "stored" fields used by the new blocks are the existing WC product fields (`description`, `image`, `galleryImages`) consumed by `ProductPurchaseBlock` and `ProductGalleryBlock`.

---

## 7. State Management

| Concern | Mechanism | Scope |
|---------|-----------|-------|
| FAQ-Accordion open-index | `useState<number>(-1)` inside `FaqAccordionBlock` (single-open mode per Q3 = A). | Block-local; resets on remount. |
| FAQ-Accordion `prefers-reduced-motion` | CSS-only via `@media (prefers-reduced-motion: reduce)` on the `max-height` transition (no JS). | – |
| Image-Gallery `activeIndex` | Existing `useState<number>(0)` in `ProductImageGallery` (`product-image-gallery.tsx:16`). **Unchanged.** Vertical-thumbs only swaps the **layout class**. | Component-local. |
| Image-Gallery `touchStart` | Existing `useState<number \| null>` (`product-image-gallery.tsx:17`). **Unchanged.** Mobile branch is identical. | Component-local. |
| Hero `variant` | YAML param → component prop. **No state.** | – |
| `motif-cross-sell` | YAML data → component prop. **No state.** Renders `null` if no tiles. | – |
| `brand-recap`, `editorial-mini`, `motif-grid`, `service-box` | Pure server components. **No state.** | – |

**No global state, no React Context, no localStorage.** The `announcement-bar-block.tsx` localStorage pattern is referenced in the codebase scan only — none of the new blocks need persistence (Discovery Business Rule: "Tracking via lokalem State (kein URL-State, kein localStorage)").

---

## 8. Accessibility & Responsive

### 8.1 FAQ-Accordion

| Aspect | Implementation |
|--------|----------------|
| Header | `<button type="button" aria-expanded={isOpen} aria-controls={panelId} id={headerId} class="w-full text-left flex items-center justify-between py-4 focus-visible:ring-2 focus-visible:ring-primary">` |
| Panel | `<div id={panelId} role="region" aria-labelledby={headerId} class="overflow-hidden transition-[max-height] duration-[250ms] ease-out" style={{ maxHeight: isOpen ? scrollHeight : 0 }}>` |
| Keyboard | `<button>` handles Enter/Space natively; section-level `onKeyDown` handles `Escape` → close current. |
| Reduced motion | `@media (prefers-reduced-motion: reduce) { .faq-panel { transition: none } }` (one rule in `globals.css` is acceptable, OR Tailwind `motion-reduce:transition-none`). |
| Focus order | DOM order = visual order; tab through headers, Enter to expand, tab continues to next header. |
| Icon (`+`/`−`) | `aria-hidden="true"` (decorative). |
| Single-open | When opening item `i`, set state to `i` (closes previous). Visual state-driven; no extra ARIA. |

### 8.2 Hero two-col

| Breakpoint | Layout |
|------------|--------|
| `< md:` (mobile, default Tailwind 0-767px) | Stacks: text first, visual second. Trust-row wraps. |
| `>= md:` (≥ 768px) | `grid-cols-[1.1fr_1fr]` (Design-E `.e-hero-a` ratio). Trust-row sits below CTA stack. |
| `>= lg:` | Increased padding/gap. |

Headline uses `text-balance` (already in `globals.css:84` base rule for h1-h6). Brand-color accent: `<h1 class="text-primary font-heading">…</h1>` (entire headline tinted; no inline span — keeps Slice-1 simple).

### 8.3 Vertical-thumbs (PDP)

| Breakpoint | Behavior |
|------------|----------|
| `< lg:` | Existing mobile branch (touch-swipe + dots) unchanged. |
| `>= lg:` AND `layout === 'vertical-thumbs'` | `lg:grid lg:grid-cols-[80px_1fr] lg:gap-4`; thumbs in left column as `flex flex-col gap-3`. |
| `>= lg:` AND `layout === 'default'` | Existing horizontal thumb row below main image (unchanged). |

Each thumb: `<button role="tab" aria-selected aria-label="Bild N">` (existing markup; no a11y change).

### 8.4 Theme tokens — block-by-block compliance

| Block | Tokens used | Forbidden constructs (must NOT appear) |
|-------|-------------|----------------------------------------|
| `editorial-mini` | `bg-surface-alt`, `text-text-primary`, `text-text-secondary`, `text-accent`, `rounded-card`, `shadow-card`, `font-heading` | hex (`#…`), `rgb(…)`, `oklch(…)` literals, hardcoded radii (`rounded-lg` for the card outer is acceptable IF the card recipe uses `rounded-card`; prefer `rounded-card`) |
| `faq-accordion` | `border-border`, `text-text-primary`, `text-text-secondary`, `text-primary` (focus ring + icon), `font-heading` (headline) | – |
| `motif-grid` | `bg-surface-alt` (tile bg), `shadow-card`, `shadow-card-hover` (hover lift), `text-text-primary`, `rounded-card`, `font-heading` (section heading) | – |
| `service-box` | `bg-surface-alt`, `rounded-card`, `shadow-card`, `text-text-primary`, `text-text-secondary`, `text-primary` (icon), `font-heading` | – |
| `motif-cross-sell` | Reuses tile recipe from `category-showcase-block.tsx`: `Card variant="interactive"` + theme tokens. | – |
| `brand-recap` | `bg-surface-alt`, `border-border` (top + bottom), `text-text-primary`, `text-text-secondary`, `font-heading` | – |
| `hero` (two-col branch) | `bg-surface-alt` (visual placeholder bg), `text-primary` (headline), `text-text-secondary` (subline), `border-border` (trust-row top border), `rounded-hero` (card outer), `font-heading` | The existing default-variant `bg-surface-elevated rounded-2xl` stays (do not refactor in this feature). |
| `product-gallery` (vertical-thumbs branch) | Existing token recipe unchanged. | – |
| `product-purchase` (extended) | Existing tokens + `prose prose-sm text-text-secondary max-w-none` for the description. | – |

---

## 9. Testing Strategy

**Test root for this feature:** `frontend/tests/slices/kleinstadtpflanze-layout-flair/`

**Test runner:** Vitest (existing — see `frontend/vitest.config.ts`).

**Test setup:** Existing `frontend/tests/setup.ts` (jsdom + `@testing-library/jest-dom` + localStorage mock).

### 9.1 Per-block unit tests (new)

| Block | File | Cases |
|-------|------|-------|
| `editorial-mini` | `editorial-mini-block.test.tsx` | (a) Renders headline+text. (b) Renders CTA when `cta` provided; omits when absent. (c) Renders image when `image_url`; falls back to placeholder when empty. (d) Returns `null` when `data` is `null`. |
| `faq-accordion` | `faq-accordion-block.test.tsx` | (a) Renders all items closed by default. (b) Click on row toggles `aria-expanded`. (c) **Keyboard: Enter on header opens; Escape closes.** (d) **Single-open: opening row B closes row A.** (e) **Reduced motion: when `matchMedia('(prefers-reduced-motion: reduce)').matches === true`, panel max-height transition is `none`.** (f) Empty items array → returns `null`. |
| `motif-grid` | `motif-grid-block.test.tsx` | (a) Renders N tiles. (b) Each tile links to `href`. (c) Skips tile if `href` does not start with `/`. (d) Renders headline when present. (e) Empty items → returns `null`. |
| `service-box` | `service-box-block.test.tsx` | (a) Renders all items with icon+label+detail. (b) Unknown icon key → no icon, no crash. (c) Min-2-items contract (renders even with exactly 2). (d) Empty items → returns `null`. |
| `motif-cross-sell` | `motif-cross-sell-block.test.tsx` | (a) `tiles: null` → returns `null` (no heading, no container). (b) `tiles: []` → returns `null`. (c) `tiles: [...]` → renders heading + N tiles, each linking to `href`. |
| `brand-recap` | `brand-recap-block.test.tsx` | (a) Renders logoText (env fallback) + tagline + link to `href`. (b) `logoText` override wins over env. |

### 9.2 Hero variant tests (new)

`hero-block.test.tsx` (or extend if exists):
- (a) **Snapshot: default variant** (no `data.variant`) — matches existing render.
- (b) **Snapshot: two-col variant** (`data.variant === 'two-col'`) — renders tag + headline + subline + 2 CTAs + trust-row + visual placeholder.
- (c) Trust-row caps at 3 items even if YAML provides 5.
- (d) Mobile (jsdom default width) — 1-col stack assertable via class on the outer grid.

### 9.3 Gallery layout tests (new)

`product-image-gallery.test.tsx` (or extend if exists):
- (a) **Layout switch:** `layout="vertical-thumbs"` adds the `lg:grid lg:grid-cols-[80px_1fr]` class on the outer container.
- (b) **Layout default:** `layout="default"` keeps the existing structure (no grid on outer).
- (c) **Mobile branch unchanged** under both layouts: dots row is present (`flex lg:hidden`).

### 9.4 ProductPurchase extension test (new)

`product-purchase-block.test.tsx`:
- (a) `withDescription: false` (or absent) → no description rendered.
- (b) `withDescription: true` AND `data.description` present → description renders inside the right column with `prose prose-sm` classes.
- (c) `serviceBox` provided → ServiceBoxBlock renders below description.
- (d) `serviceBox` empty/absent → no service-box rendered.

### 9.5 YAML wiring tests (Slice-level smoke)

`slice-01-yaml-home.test.ts`, `slice-02-yaml-product.test.ts`, `slice-03-yaml-category.test.ts`:
- Parse the YAML file, assert section order matches Discovery, assert all block types referenced are registered in `lib/blocks/registry.ts`, assert no removed blocks (`product-description`, `product-reviews`) appear in kleinstadtpflanze YAMLs.

### 9.6 Theme env in vitest (Discovery Q8)

**Recommended:** extend `tests/setup.ts` to set the env var globally:

```ts
// frontend/tests/setup.ts (extended)
import { vi } from 'vitest'
import '@testing-library/jest-dom'

// NEW: ensure theme is set for tests under tests/slices/kleinstadtpflanze-* —
// these tests load YAML from themes/kleinstadtpflanze and assert theme-specific behavior.
// If unset, loadPageConfig falls back to themes/default and tests fail noisily.
process.env.NEXT_PUBLIC_THEME = process.env.NEXT_PUBLIC_THEME ?? 'kleinstadtpflanze'

// ... existing localStorage mock unchanged
```

**Rationale for global default:** the existing `tests/slices/kleinstadtpflanze-design-e/slice-03-theme.test.ts` already manipulates `NEXT_PUBLIC_THEME` per-test via `execSync({ env })`. A global default of `kleinstadtpflanze` is safe because: (a) those tests override per-call; (b) other slices (pod-shop-mvp, etc.) read theme via `process.env.NEXT_PUBLIC_THEME ?? 'default'` in product code, but their tests do not assert theme-specific output. **Trade-off acknowledged:** if any existing test asserts default-theme behavior implicitly (e.g. expects `themes/default/pages/home.yaml` to load when env is unset), it could break. **Mitigation:** alternative is per-test `process.env.NEXT_PUBLIC_THEME = 'kleinstadtpflanze'` inside `beforeAll` of the new test files. Implementer should run the full test suite once with the global change and verify no regressions; if any, fall back to per-test scoping.

### 9.7 Existing test that needs adjustment

**`frontend/tests/slices/kleinstadtpflanze-design-e/slice-03-theme.test.ts`** AC-5b (lines 418-429) **currently asserts**:

> `expect(existsSync(KLEINSTADTPFLANZE_PAGES_DIR), 'themes/kleinstadtpflanze/pages/ should NOT exist to force 3-tier fallback').toBe(false)`

**This test will fail in Slice 1** because we are creating that directory. The test must be updated as part of Slice 1: change the AC to assert that `themes/kleinstadtpflanze/pages/{home,product,category}.yaml` exist, OR retire AC-5b (the 3-tier fallback is exercised by the slug-tier path, which kleinstadtpflanze does not populate). **Recommendation:** retire AC-5b and replace with a positive assertion (the three pages exist and are valid YAML).

---

## 10. Slice Mapping

### Slice 1 — Homepage-Stack

| Category | Files |
|----------|-------|
| **NEW components** | `frontend/components/blocks/editorial-mini-block.tsx`, `faq-accordion-block.tsx`, `motif-grid-block.tsx`, `brand-recap-block.tsx` |
| **MODIFIED components** | `frontend/components/blocks/hero-block.tsx` (variant branch) |
| **MODIFIED registry** | `frontend/lib/blocks/registry.ts` (4 new entries: editorial-mini, faq-accordion, motif-grid, brand-recap) |
| **MODIFIED types** | `frontend/lib/blocks/types.ts` (Slice-07 section: `EditorialMiniData`, `FaqAccordionData`, `FaqAccordionItem`, `MotifGridData`, `MotifGridItem`, `BrandRecapData`; HeroBlockData extended fields) |
| **MODIFIED page** | `frontend/app/page.tsx` (SKELETON_MAP: 4 new `null` entries) |
| **NEW YAML** | `frontend/themes/kleinstadtpflanze/pages/home.yaml` |
| **MODIFIED test** | `frontend/tests/slices/kleinstadtpflanze-design-e/slice-03-theme.test.ts` (retire AC-5b) |
| **MODIFIED setup** | `frontend/tests/setup.ts` (env default — see §9.6) |
| **NEW tests** | `frontend/tests/slices/kleinstadtpflanze-layout-flair/editorial-mini-block.test.tsx`, `faq-accordion-block.test.tsx`, `motif-grid-block.test.tsx`, `brand-recap-block.test.tsx`, `hero-block.test.tsx` (variant), `slice-01-yaml-home.test.ts` |

### Slice 2 — PDP-Refactor + Flair (depends on Slice 1)

| Category | Files |
|----------|-------|
| **NEW components** | `frontend/components/blocks/service-box-block.tsx`, `motif-cross-sell-block.tsx` |
| **MODIFIED components** | `frontend/components/product/product-image-gallery.tsx` (layout prop), `frontend/components/blocks/product-gallery-block.tsx` (forward layout), `frontend/components/blocks/product-purchase-block.tsx` (withDescription + serviceBox composition) |
| **MODIFIED registry** | `frontend/lib/blocks/registry.ts` (2 new entries: service-box, motif-cross-sell) |
| **MODIFIED types** | `frontend/lib/blocks/types.ts` (Slice-07 section: `ServiceBoxData`, `ServiceBoxItem`, `MotifCrossSellData`, `MotifCrossSellTile`); `WooCommerceLoaderParams` (optional `layout`, `withDescription`, `serviceBox`) |
| **MODIFIED loader** | `frontend/lib/blocks/data-loaders.ts` — `product_by_slug` branch merges YAML params (`layout`, `withDescription`, `serviceBox`) into the returned product. |
| **MODIFIED page** | `frontend/app/produkt/[slug]/page.tsx` (SKELETON_MAP: 2 new `null` entries — service-box, motif-cross-sell, faq-accordion, brand-recap if not already added in Slice 1) |
| **NEW YAML** | `frontend/themes/kleinstadtpflanze/pages/product.yaml` |
| **NEW tests** | `frontend/tests/slices/kleinstadtpflanze-layout-flair/service-box-block.test.tsx`, `motif-cross-sell-block.test.tsx`, `product-image-gallery.test.tsx`, `product-purchase-block.test.tsx`, `slice-02-yaml-product.test.ts` |

### Slice 3 — Kategorie-Bottom-Flair (depends on Slice 1)

| Category | Files |
|----------|-------|
| **NEW components** | none |
| **MODIFIED components** | none |
| **MODIFIED registry** | none |
| **MODIFIED types** | none |
| **MODIFIED page** | `frontend/app/kategorie/[slug]/page.tsx` (SKELETON_MAP: 3 new `null` entries — product-recommendations, faq-accordion, brand-recap) |
| **NEW YAML** | `frontend/themes/kleinstadtpflanze/pages/category.yaml` |
| **NEW tests** | `frontend/tests/slices/kleinstadtpflanze-layout-flair/slice-03-yaml-category.test.ts` |

---

## 11. Risks & Mitigations

Architecture-level risks (in addition to Discovery's):

| Risk | Mitigation |
|------|------------|
| **Existing test `slice-03-theme.test.ts` AC-5b will break in Slice 1** (asserts `themes/kleinstadtpflanze/pages/` does NOT exist). | **Update the test in the same Slice-1 commit** that creates the directory. Replace AC-5b with a positive assertion (the 3 YAML files exist and parse). See §9.7. |
| **Default-theme contamination via shared YAML schema.** Adding optional fields to `HeroBlockData` could accidentally activate the new branch on the default theme if the WP CMS ever returns a `variant` field. | The `HeroBlock` branch only fires on `data?.variant === 'two-col'`. Default theme YAML uses `content_source: wordpress` and never sets `variant`. WP custom-fields query `GET_PAGE_CUSTOM_FIELDS` (`data-loaders.ts:71-82`) does not request `variant` — it physically cannot leak into the default render. |
| **Registry insert order matters for skeleton hydration.** | Registry is a flat `Record<string, ComponentType>` — order does not matter for resolution. Order matters only for source-code readability (group by Slice with comments per existing convention). |
| **Theme regeneration step missed after token change.** Phase 1 added Cream `surface-alt`, Forest `primary`, etc. If anyone touches `themes/kleinstadtpflanze/theme.yaml` during Phase 2 they must run `cd frontend && node scripts/generate-theme.mjs`. | Phase 2 does NOT touch `theme.yaml`. If a future block needs a new token, the procedure is: (1) add to `themes/default/theme.yaml`, (2) emit in `generate-theme.mjs`, (3) map in `globals.css` `@theme`, (4) regenerate. Add to PR review checklist for Slice 1. |
| **`product-purchase` becomes a "god block" with 3 concerns.** | Acknowledged trade-off (see §3.3 decision). Mitigation: keep description and service-box render paths behind `if (data.withDescription)` and `if (data.serviceBox)` guards so the block stays a single-purpose purchase block when not opted in. Future split is a refactor, not a blocker. |
| **YAML loader merge for `layout`/`withDescription`/`serviceBox` couples loader to component shape.** | The loader merge is a single line per branch (`{ ...product, layout: params.layout, ... }`). It's the smallest invasive change. Alternative: a generic "params passthrough" layer in SectionRenderer — over-engineered for 3 fields. |
| **Mobile gallery accidentally hits vertical-thumbs branch.** | Vertical-thumbs branch is gated by `lg:` Tailwind breakpoint utilities only. Mobile branch (`lg:hidden` block) renders unconditionally on `< lg:`. JSX should produce both branches (mobile `lg:hidden` + desktop `hidden lg:grid …`); CSS chooses. |
| **FAQ-Accordion `max-height` transition janks for very long answers.** | Calculate `scrollHeight` on the panel ref at expand time (or use a generous fixed `max-h-96` ceiling). For Slice 1 short-text FAQs, ceiling is fine. |
| **Hero two-col without an image asset looks broken.** | Visual placeholder = `bg-surface-alt rounded-card aspect-[4/3]` with a small SVG (e.g. brand-color leaf glyph) or empty box. Block always renders. (Discovery Risk: "Hero-Visual-Asset: User hat noch kein Bild" → mitigation = placeholder pattern.) |
| **Brand-recap tagline placeholder ships unchanged.** | Slice 1 implementation must prompt user for the tagline before merge (Discovery Risk + Q1). YAML default is a minimal honest sentence. |

---

## 12. Open Questions Carry-Over (8 from Discovery)

| # | Question | Architectural answer |
|---|----------|----------------------|
| 1 | Brand-Recap tagline | **Defer** to Slice-1 implementation (user input). Architecture commits to YAML field shape only. Default placeholder: `"Kleine Drucke aus Schleswig-Holstein."` — will be replaced before merge. |
| 2 | Motiv-Identität in WC (tag / taxonomy / meta / none) | **Architecturally answered as "graceful null in Slice 2".** §4.2 spells out the extension path for each of A/B/C/D. Block ships with `tiles: null` in YAML; no GraphQL change in Slice 2. WC modeling is a separate feature and must be answered by inspecting the WP install directly (out of architecture scope). |
| 3 | FAQ Single-Open vs Multi-Open | **A — Single-Open** (per Discovery recommendation). Locked into `FaqAccordionBlock` state shape (`useState<number>`). Multi-open would need `useState<Set<number>>` — a future config option, not a Slice-1 decision. |
| 4 | Service-Box source (per-category YAML / WC meta / hybrid) | **A — INLINE YAML for Slice 2** (per Discovery recommendation). `service-box.source` field exists with default `'inline'`; `'category'` and `'product'` are reserved enum values for a future iteration. The block accepts `items` directly — no source switch needed for Slice 2. |
| 5 | Motif-Grid tile click target route | **Defer (depends on Q2).** Slice-1 YAML uses `/suche?q={motivname}` (existing search route) as the **C-fallback** option from Discovery. When Q2 lands on a tag/taxonomy, `motif-grid` href YAML can be re-pointed in one PR with no component change. |
| 6 | PDP description length | **A — Full render** (per Discovery recommendation). `withDescription: true` renders the full HTML via `prose prose-sm max-w-none`. No truncation, no "Mehr lesen" toggle — deferred to a later iteration if a single product description proves too long. |
| 7 | Mini-FAQ subset on PDP/Category | **C — YAML-configurable with default** (per Discovery recommendation). The PDP and Category YAMLs each carry their own 3-item subset of the homepage FAQ. Today: hand-curated per page. Future: a shared `themes/kleinstadtpflanze/data/faq.yaml` could be referenced by anchor (out of architecture scope). |
| 8 | `NEXT_PUBLIC_THEME=kleinstadtpflanze` activation | **Confirm before Slice 1 starts.** Implementation prerequisite (not architectural). For tests: see §9.6 — extend `tests/setup.ts` to default the env var to `kleinstadtpflanze`. |

---

## Q&A Log (architecture session)

| # | Question | Answer |
|---|----------|--------|
| 1 | `ProductPurchaseBlock` extension vs new combined block? | **Extend in place** (§3.3). Single block on PDP-right column matches Discovery wording, avoids triplicating the 2-col SectionLayout, and keeps the `'use client'` boundary singular. |
| 2 | Service-box source for Slice 2 — inline / category / product? | **Inline** (Q4 = A). Per-category and per-product overrides reserved as enum values; not implemented in Slice 2. Per-product YAML override available "for free" via Tier-1 (`themes/.../pages/product/{slug}.yaml`) if needed. |
| 3 | FAQ answer rendering — Markdown or plain string? | **Plain string** (§6). Smallest XSS surface, simplest block; Markdown is a future opt-in if a content editor needs links/lists. |
| 4 | `motif-cross-sell` data source for Slice 2? | **Inline-null contract** (§4.2). Block returns `null` until WC motif identity is decided. Extension path documented per Q2 outcome. |
| 5 | Hero `variant` field — WP CMS or YAML? | **YAML** (§3.1). The default-theme hero stays `content_source: wordpress`; the kleinstadtpflanze hero switches to `content_source: inline` because the two-col content is theme-curated, not editor-curated. |
| 6 | Routing of `layout` / `withDescription` / `serviceBox` from YAML to component? | Loader-side merge in `woocommerceLoader` `product_by_slug` branch (§3.2 / §3.3). Smallest invasive change; alternative ("generic params passthrough") is over-engineering for 3 fields. |
| 7 | Vitest theme env — global default or per-test? | **Global default** in `tests/setup.ts` (§9.6) with per-test override capability preserved. Implementer must verify no regressions in unrelated slices. |
| 8 | Existing `slice-03-theme.test.ts` AC-5b — keep or retire? | **Retire AC-5b** in the same Slice-1 commit that creates `themes/kleinstadtpflanze/pages/`. Replace with positive assertion. (§9.7 + Risk table.) |

---

## Sign-Off

All areas checked: API (N/A — no API surface), Schema (no DB columns added — all YAML), Components (6 new, 2 extended, 1 in-place integration), Security (XSS surface limited to existing WC `description` HTML inside the existing `dangerouslySetInnerHTML` recipe — no new sinks; FAQ uses plain string), Layers (registry, page-config, data-loaders, section-renderer extensions documented), Constraints/Integrations (theme tokens enforced; honest copy enforced), NFRs (responsive breakpoints, reduced-motion, a11y for accordion+gallery+hero), Risks/Mitigations (architecture-level + Discovery-level documented), Q&A log present, Open Questions deferred-or-answered per item.

**Status: Ready for implementation (Slice 1 first, then Slice 2, then Slice 3).**
