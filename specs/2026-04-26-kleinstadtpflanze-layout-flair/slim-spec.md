# Slim Spec: Kleinstadtpflanze — Layout & Components (Phase 2)

**Discovery:** specs/2026-04-26-kleinstadtpflanze-layout-flair/discovery.md
**Architecture:** specs/2026-04-26-kleinstadtpflanze-layout-flair/architecture.md
**Wireframes:** -- (Design-E HTML at `/tmp/kp-design-e/` is visual reference; no ASCII wireframes)

---

## Test Strategy

| Key | Value |
|-----|-------|
| **Stack** | TypeScript / Next.js 16 (App Router) + Tailwind v4 + Vitest 3 |
| **Test Command** | `cd frontend && pnpm test` (single file: `cd frontend && npx vitest run tests/slices/kleinstadtpflanze-layout-flair/{file}`) |
| **E2E Command** | -- |
| **Start Command** | `cd frontend && NEXT_PUBLIC_THEME=kleinstadtpflanze pnpm dev` |
| **Health Endpoint** | http://localhost:3000 |
| **Mocking Strategy** | mock_external (Apollo GraphQL responses mocked; YAML loaded from real `themes/kleinstadtpflanze/pages/*.yaml`; no live WP backend) |

---

## Dependencies

```
Slice 1: Homepage-Stack -> (none)
Slice 2: PDP-Refactor + Flair -> Slice 1
Slice 3: Category-Bottom-Flair -> Slice 1
```

Slice 2 and Slice 3 depend on Slice 1 only (for `faq-accordion` and `brand-recap` blocks). Slice 2 and Slice 3 are independent of each other.

---

## Slice 1: Homepage-Stack with new blocks + hero two-col

**Goal:** Ship 4 new theme-agnostic blocks (`editorial-mini`, `faq-accordion`, `motif-grid`, `brand-recap`), extend `HeroBlock` with a `variant: "two-col"` branch, and assemble the kleinstadtpflanze homepage via YAML override. Default theme rendering must remain unchanged.
**Depends on:** --

### Acceptance Criteria

- **AC-1.1 (Hero default backward-compat):** GIVEN the default theme is active (`NEXT_PUBLIC_THEME` unset or `default`)
  WHEN `app/page.tsx` renders the hero block with `data.variant` undefined
  THEN the existing single-column render path runs unchanged (existing snapshot/DOM matches)
  AND none of the new fields (`tag`, `ctaSecondary`, `trustItems`, `visual`) are referenced.

- **AC-1.2 (Hero two-col render):** GIVEN `data.variant === 'two-col'` with `tag="Aus Schleswig-Holstein"`, `heroHeadline`, `heroSubline`, `heroCtaText`+`heroCtaLink`, `ctaSecondary={text,href}`, `trustItems=[3 items]`, `visual.imageUrl=""`
  WHEN `HeroBlock` renders
  THEN the outer container has classes `grid grid-cols-1 md:grid-cols-[1.1fr_1fr]`
  AND the tag, h1, subline, primary CTA, ghost (secondary) CTA, and a trust-row `<ul>` with exactly 3 `<li>` items render
  AND when `visual.imageUrl` is empty, an SVG placeholder with `bg-surface-alt` renders in the right column.

- **AC-1.3 (Hero trust-row cap):** GIVEN `trustItems` YAML array with 5 entries
  WHEN `HeroBlock` renders the two-col variant
  THEN exactly 3 trust-row items appear in the DOM (block enforces `slice(0,3)`).

- **AC-1.4 (FAQ default closed):** GIVEN `FaqAccordionBlock` receives `items=[{q,a}, {q,a}, {q,a}]` and no `defaultOpen`
  WHEN the block first renders
  THEN every panel has `aria-expanded="false"` on its header `<button>`
  AND every panel `<div role="region">` has computed `max-height: 0`.

- **AC-1.5 (FAQ click toggle):** GIVEN a closed FAQ row with header button
  WHEN the user clicks the header button
  THEN that header's `aria-expanded` becomes `"true"`
  AND the panel's `max-height` transitions from `0` to its `scrollHeight` over 250ms ease
  AND the icon flips from `+` (plus) to `−` (minus).

- **AC-1.6 (FAQ single-open):** GIVEN row A is open and rows B, C are closed
  WHEN the user clicks row B's header
  THEN row A's `aria-expanded` becomes `"false"` (closed)
  AND row B's `aria-expanded` becomes `"true"` (open)
  AND at most one row has `aria-expanded="true"` at any time.

- **AC-1.7 (FAQ keyboard a11y):** GIVEN focus is on a closed FAQ header `<button>`
  WHEN the user presses `Enter` or `Space`
  THEN the row toggles open (same DOM result as click)
  AND WHEN the user presses `Escape` while a row is open
  THEN that row closes (`aria-expanded="false"`).

- **AC-1.8 (FAQ reduced motion):** GIVEN `window.matchMedia('(prefers-reduced-motion: reduce)').matches === true`
  WHEN a FAQ row is toggled
  THEN the panel transition is `none` (instant snap)
  AND the final `aria-expanded` and `max-height` values match the non-reduced-motion case.

- **AC-1.9 (FAQ empty graceful):** GIVEN `FaqAccordionBlock` receives `items: []` or `data: null`
  WHEN it renders
  THEN it returns `null` (no heading, no container in the DOM).

- **AC-1.10 (Editorial-Mini render):** GIVEN `EditorialMiniBlock` receives `{ tag, headline, text, cta:{text,href}, image_url:"", image_alt:"" }`
  WHEN it renders
  THEN the section uses `bg-surface-alt rounded-card shadow-card` and `grid grid-cols-1 md:grid-cols-2`
  AND the headline renders as `<h2>` with `font-heading text-text-primary`
  AND the CTA renders as a `<a>` with `href` matching YAML
  AND when `image_url` is empty, a placeholder `<div>` with `bg-surface-elevated` renders in the left column.

- **AC-1.11 (Editorial-Mini optional CTA):** GIVEN `EditorialMiniBlock` receives data without a `cta` field
  WHEN it renders
  THEN no `<a>`/Button element for the CTA appears in the section.

- **AC-1.12 (Motif-Grid tile rendering):** GIVEN `MotifGridBlock` receives `items` with 6 entries each having `name`, `image_url`, `href` starting with `/`
  WHEN it renders
  THEN exactly 6 tile elements render
  AND each tile is wrapped in an `<a>` with `href` matching the item
  AND the grid uses classes `grid grid-cols-2 lg:grid-cols-3 gap-4`
  AND each tile uses `Card variant="interactive"` recipe (matches `category-showcase-block.tsx:29-58` class shape).

- **AC-1.13 (Motif-Grid href guard):** GIVEN one item has `href: "https://external.com"` (does not start with `/`)
  WHEN `MotifGridBlock` renders
  THEN that item is skipped (not present in DOM)
  AND remaining items render as expected.

- **AC-1.14 (Motif-Grid empty graceful):** GIVEN `MotifGridBlock` receives `items: []` or `data: null`
  WHEN it renders
  THEN it returns `null`.

- **AC-1.15 (Brand-Recap render):** GIVEN `BrandRecapBlock` receives `{ tagline: "Kleine Drucke aus Schleswig-Holstein.", href: "/" }` and `logoText` undefined while `process.env.NEXT_PUBLIC_SHOP_NAME = "Kleinstadtpflanze"`
  WHEN it renders
  THEN the section has `border-y border-border bg-surface-alt`
  AND a `<a href="/">` containing the text `"Kleinstadtpflanze"` (the env fallback) renders
  AND the tagline text `"Kleine Drucke aus Schleswig-Holstein."` renders next to the logo text.

- **AC-1.16 (Brand-Recap logoText override):** GIVEN `logoText: "MyShop"` is provided in YAML
  WHEN `BrandRecapBlock` renders
  THEN the link text is `"MyShop"` (the env fallback is NOT used).

- **AC-1.17 (Registry registration):** GIVEN `lib/blocks/registry.ts` is imported
  WHEN `resolveBlock(type)` is called for each of `'editorial-mini'`, `'faq-accordion'`, `'motif-grid'`, `'brand-recap'`
  THEN each returns a defined React component (no `undefined`, no error).

- **AC-1.18 (Skeleton map entries):** GIVEN `app/page.tsx` is loaded
  WHEN `SKELETON_MAP` is inspected
  THEN it contains entries for `'editorial-mini'`, `'faq-accordion'`, `'motif-grid'`, `'brand-recap'` (each may be `null` for sync inline blocks; entry must exist).

- **AC-1.19 (Homepage YAML section order):** GIVEN `themes/kleinstadtpflanze/pages/home.yaml` is parsed
  WHEN section order is extracted
  THEN block types appear in this exact order: `hero`, `category-showcase`, `product-grid`, `motif-grid`, `editorial-mini`, `faq-accordion`, `trust-badges`, `brand-recap`
  AND the `hero` block has `params.props.variant === "two-col"`
  AND every block type referenced is registered in `lib/blocks/registry.ts`.

- **AC-1.20 (Trust-badges honesty):** GIVEN `themes/kleinstadtpflanze/pages/home.yaml` `trust-badges` block items
  WHEN the items are inspected
  THEN no item text contains the strings `"Klimaneutral"`, `"Made in Germany"`, `"Familienbetrieb"`, `"4,9★"`, or any review-count pattern
  AND items match the honest set: `"Versand DE 3–5 Werktage"`, `"30 Tage Rückgabe"`, `"Sichere Zahlung"`.

- **AC-1.21 (Default theme isolation):** GIVEN `themes/default/pages/home.yaml` is read after Slice-1 changes
  WHEN diffed against its pre-Slice-1 state
  THEN no lines have changed (the default theme YAML is byte-identical).

- **AC-1.22 (Test setup theme env):** GIVEN `tests/setup.ts` is loaded by Vitest
  WHEN `process.env.NEXT_PUBLIC_THEME` is read at the start of any test in `tests/slices/kleinstadtpflanze-layout-flair/`
  THEN it equals `"kleinstadtpflanze"` (defaulted in setup, overridable per-test).

- **AC-1.23 (Existing test AC-5b retired):** GIVEN `tests/slices/kleinstadtpflanze-design-e/slice-03-theme.test.ts` is run after Slice 1
  WHEN the suite executes
  THEN the previous AC-5b assertion (`themes/kleinstadtpflanze/pages/` does NOT exist) is replaced with a positive assertion that `home.yaml`, `product.yaml`, `category.yaml` exist under `themes/kleinstadtpflanze/pages/` and parse as valid YAML
  AND the full file passes (`expect(...).toBe(false)` against the directory existence is removed).

### Deliverables

- [ ] `frontend/components/blocks/editorial-mini-block.tsx` — New server block: 2-col image+text section.
- [ ] `frontend/components/blocks/faq-accordion-block.tsx` — New `'use client'` block: single-open accordion with a11y + reduced-motion.
- [ ] `frontend/components/blocks/motif-grid-block.tsx` — New server block: tile grid with internal-href guard.
- [ ] `frontend/components/blocks/brand-recap-block.tsx` — New server block: thin brand bar above footer.
- [ ] `frontend/components/blocks/hero-block.tsx` — Extended: add `variant: 'two-col'` branch + new optional fields.
- [ ] `frontend/lib/blocks/types.ts` — Append Slice-07 section: `EditorialMiniData`, `FaqAccordionData`, `FaqAccordionItem`, `MotifGridData`, `MotifGridItem`, `BrandRecapData`; extend `HeroBlockData`.
- [ ] `frontend/lib/blocks/registry.ts` — Register 4 new block types.
- [ ] `frontend/app/page.tsx` — Add 4 new entries to `SKELETON_MAP` (each `null` for inline-sync).
- [ ] `frontend/themes/kleinstadtpflanze/pages/home.yaml` — New file with 8-section homepage layout.
- [ ] `frontend/tests/setup.ts` — Default `NEXT_PUBLIC_THEME` to `kleinstadtpflanze` for the suite.
- [ ] `frontend/tests/slices/kleinstadtpflanze-design-e/slice-03-theme.test.ts` — Replace AC-5b with positive directory/YAML assertion.

---

## Slice 2: PDP-Refactor + Flair (gallery vertical-thumbs, description-in-purchase, service-box, motif-cross-sell)

**Goal:** Refactor the kleinstadtpflanze PDP via YAML override + 2 new blocks (`service-box`, `motif-cross-sell`) + 3 in-place extensions (`ProductImageGallery` `layout: "vertical-thumbs"`, `ProductPurchaseBlock` `withDescription` + inline `serviceBox`, `data-loaders.ts` param merge). Default PDP must stay unchanged.
**Depends on:** Slice 1 (uses `faq-accordion` and `brand-recap`).

### Acceptance Criteria

- **AC-2.1 (Gallery default backward-compat):** GIVEN `ProductImageGallery` is rendered with `layout` undefined OR `layout="default"`
  WHEN the component mounts
  THEN the outer container does NOT have `lg:grid lg:grid-cols-[80px_1fr]`
  AND the existing horizontal-thumb-row (below main image) renders unchanged.

- **AC-2.2 (Gallery vertical-thumbs desktop):** GIVEN `ProductImageGallery` is rendered with `layout="vertical-thumbs"` and 5 images
  WHEN the desktop branch is inspected (jsdom or class-based assertion)
  THEN the outer container has classes `lg:grid lg:grid-cols-[80px_1fr] lg:gap-4`
  AND the thumbs container is the FIRST grid column with classes `flex flex-col gap-3`
  AND the main image is the SECOND grid column.

- **AC-2.3 (Gallery mobile unchanged):** GIVEN `ProductImageGallery` is rendered with `layout="vertical-thumbs"` AND viewport is below `lg:` breakpoint
  WHEN the mobile branch is inspected
  THEN the dots row is present (selector with `flex lg:hidden`)
  AND no vertical-thumbs grid classes apply at the mobile breakpoint.

- **AC-2.4 (Gallery thumb click swap):** GIVEN vertical-thumbs layout with 4 images, `activeIndex` initially 0
  WHEN the user clicks the 3rd thumb `<button role="tab">`
  THEN the main `<img>` `src` updates to images[2]
  AND the previously active thumb loses its `border-primary` (or active) class
  AND the 3rd thumb gains the active class.

- **AC-2.5 (Purchase withDescription off):** GIVEN `ProductPurchaseBlock` receives `data` with `withDescription: false` (or absent) and `description: "<p>foo</p>"`
  WHEN it renders
  THEN no element with classes `prose prose-sm` is present in the block.

- **AC-2.6 (Purchase withDescription on):** GIVEN `ProductPurchaseBlock` receives `data` with `withDescription: true` AND `description: "<p>Hello</p>"`
  WHEN it renders
  THEN a description container with classes `mt-8 prose prose-sm text-text-secondary max-w-none` renders below the variant selector
  AND the inner HTML matches `<p>Hello</p>` (rendered via `dangerouslySetInnerHTML`).

- **AC-2.7 (Purchase serviceBox composition):** GIVEN `ProductPurchaseBlock` receives `data.serviceBox = { items: [3 items with icon/label/detail] }`
  WHEN it renders
  THEN a `ServiceBoxBlock` instance renders BELOW the description (or below the variant selector if `withDescription` is off)
  AND exactly 3 service-box items appear in the DOM
  AND when `serviceBox` is absent or has empty `items`, no service-box renders.

- **AC-2.8 (ServiceBox render):** GIVEN `ServiceBoxBlock` receives `items=[{icon:"coffee", label:"Spülmaschinenfest", detail:"Bis 60 °C"}, {icon:"ruler", label:"330 ml", detail:"Standard-Henkel"}]`
  WHEN it renders
  THEN the container uses classes `bg-surface-alt rounded-card shadow-card`
  AND each item renders an icon (Lucide `coffee`, `ruler` SVG), a `<strong class="text-text-primary">` label, and a `<p class="text-sm text-text-secondary">` detail
  AND the icon uses class `text-primary`.

- **AC-2.9 (ServiceBox unknown icon graceful):** GIVEN one item has `icon: "nonexistent"`
  WHEN `ServiceBoxBlock` renders
  THEN no SVG renders for that item (no crash, no console error)
  AND the label + detail still render.

- **AC-2.10 (ServiceBox empty graceful):** GIVEN `ServiceBoxBlock` receives `items: []` or `data: null`
  WHEN it renders
  THEN it returns `null`.

- **AC-2.11 (MotifCrossSell null tiles):** GIVEN `MotifCrossSellBlock` receives `tiles: null`
  WHEN it renders
  THEN it returns `null`
  AND no heading, no grid, no empty container appears in the DOM.

- **AC-2.12 (MotifCrossSell empty array):** GIVEN `MotifCrossSellBlock` receives `tiles: []`
  WHEN it renders
  THEN it returns `null` (same behavior as null).

- **AC-2.13 (MotifCrossSell tiles render):** GIVEN `MotifCrossSellBlock` receives `heading: "Dieses Motiv auch auf …"` and `tiles=[3 items with productName, productType, image_url, href]`
  WHEN it renders
  THEN the heading text renders as a section heading
  AND exactly 3 tiles render
  AND each tile is an `<a>` with `href` matching the item (each `href` matches `^/produkt/`)
  AND each tile shows both `productName` and `productType` text.

- **AC-2.14 (Loader param merge):** GIVEN YAML `params` for the `product-purchase` block contain `withDescription: true` and `serviceBox: { items: [...] }`
  WHEN `woocommerceLoader` runs the `product_by_slug` branch
  THEN the returned `data` object contains the WC product fields PLUS `withDescription: true` and `serviceBox: { items: [...] }` (passthrough merge)
  AND similarly for `product-gallery` block: `data.layout === "vertical-thumbs"` is set on the returned product.

- **AC-2.15 (PDP YAML section order):** GIVEN `themes/kleinstadtpflanze/pages/product.yaml` is parsed
  WHEN section order is extracted
  THEN block types appear in this exact order: `product-gallery` + `product-purchase` (in a 2-col section), `trust-badges`, `motif-cross-sell`, `product-recommendations`, `faq-accordion`, `brand-recap`
  AND `product-description` block type does NOT appear anywhere in the file
  AND `product-reviews` block type does NOT appear anywhere in the file
  AND the `product-gallery` block has `params.layout === "vertical-thumbs"`
  AND the `product-purchase` block has `params.withDescription === true` and a non-empty `params.serviceBox.items` array.

- **AC-2.16 (PDP YAML graceful-null wiring):** GIVEN `themes/kleinstadtpflanze/pages/product.yaml`'s `motif-cross-sell` block
  WHEN inspected
  THEN `params.props.tiles === null`
  AND `content_source === "inline"`.

- **AC-2.17 (Default PDP isolation):** GIVEN `themes/default/pages/product.yaml` is read after Slice-2 changes
  WHEN diffed against its pre-Slice-2 state
  THEN no lines have changed.

- **AC-2.18 (Variant selector unchanged):** GIVEN the kleinstadtpflanze PDP is rendered with a product that has size + color attributes
  WHEN the variant selector is interacted with
  THEN size and color selection works exactly as before (no regressions in `ProductVariantSelector` behavior; existing tests in `tests/slices/pod-shop-mvp/` still pass).

- **AC-2.19 (Skeleton map entries):** GIVEN `app/produkt/[slug]/page.tsx` is loaded after Slice 2
  WHEN `SKELETON_MAP` is inspected
  THEN it contains entries for `'service-box'`, `'motif-cross-sell'` (in addition to Slice-1 entries `'faq-accordion'`, `'brand-recap'`).

- **AC-2.20 (Marketing-honesty in PDP YAML):** GIVEN `themes/kleinstadtpflanze/pages/product.yaml` is parsed
  WHEN every string value in `service-box.items[].detail`, `service-box.items[].label`, `trust-badges.items[].text`, `faq-accordion.items[].q`, `faq-accordion.items[].a`, `brand-recap.tagline` is concatenated
  THEN the concatenated string contains none of: `"Klimaneutral"`, `"Made in Germany"`, `"Familienbetrieb"`, `"4,9★"`, `"2.180 Bewertungen"`.

### Deliverables

- [ ] `frontend/components/blocks/service-box-block.tsx` — New server block with local ICON_MAP (coffee, ruler, leaf, droplet, package, truck, refresh, lock, shopping-bag).
- [ ] `frontend/components/blocks/motif-cross-sell-block.tsx` — New server block with graceful-null contract.
- [ ] `frontend/components/product/product-image-gallery.tsx` — Add `layout?: 'default' | 'vertical-thumbs'` prop + desktop branch.
- [ ] `frontend/components/blocks/product-gallery-block.tsx` — Forward `layout` from `data` to `ProductImageGallery`.
- [ ] `frontend/components/blocks/product-purchase-block.tsx` — Add `withDescription` rendering + inline `ServiceBoxBlock` composition.
- [ ] `frontend/lib/blocks/types.ts` — Append `ServiceBoxData`, `ServiceBoxItem`, `MotifCrossSellData`, `MotifCrossSellTile`; extend `WooCommerceLoaderParams` with optional `layout`, `withDescription`, `serviceBox`.
- [ ] `frontend/lib/blocks/data-loaders.ts` — In `product_by_slug` branch: merge YAML `layout`, `withDescription`, `serviceBox` into returned product.
- [ ] `frontend/lib/blocks/registry.ts` — Register `'service-box'` and `'motif-cross-sell'`.
- [ ] `frontend/app/produkt/[slug]/page.tsx` — Add SKELETON_MAP entries for the 4 newly-used types on PDP.
- [ ] `frontend/themes/kleinstadtpflanze/pages/product.yaml` — New file with 6-section PDP layout (no `product-description`, no `product-reviews`).

---

## Slice 3: Category-Bottom-Flair (recommendations + mini-FAQ + brand-recap below pagination)

**Goal:** Append 3 sections below the existing 8-section category page on the kleinstadtpflanze theme: cross-category product recommendations, mini-FAQ, brand-recap. No new components — pure YAML override + skeleton-map registration.
**Depends on:** Slice 1 (uses `faq-accordion` and `brand-recap`).

### Acceptance Criteria

- **AC-3.1 (Category YAML section order):** GIVEN `themes/kleinstadtpflanze/pages/category.yaml` is parsed
  WHEN section order is extracted
  THEN the first 8 block types match `themes/default/pages/category.yaml` verbatim (`breadcrumb`, `page-heading`, `filter-chips`, `product-count`, `sort-bar`, `product-grid`, `pagination`, `empty-state`)
  AND sections 9, 10, 11 are `product-recommendations`, `faq-accordion`, `brand-recap` (in that order)
  AND every block type referenced is registered in `lib/blocks/registry.ts`.

- **AC-3.2 (Recommendations cross-category source):** GIVEN the category-page recommendations block in YAML
  WHEN params are inspected
  THEN `params.query === "product_recommendations"`
  AND `params.source === "bestsellers"` (existing cross-category mode in `data-loaders.ts`)
  AND `params.first === 4`
  AND `params.heading === "Vielleicht auch was für dich"`.

- **AC-3.3 (Recommendations renders even when category empty):** GIVEN a category that has zero products (empty-state path)
  WHEN the page renders
  THEN the empty-state block renders
  AND the `product-recommendations` block STILL renders below it (because its data source is `bestsellers`, not the current category)
  AND the mini-FAQ and brand-recap also render.

- **AC-3.4 (Mini-FAQ subset):** GIVEN the category-page `faq-accordion` block
  WHEN `params.props.items` is inspected
  THEN it contains exactly 3 items
  AND each item has non-empty `q` and `a` strings (plain strings, no HTML).

- **AC-3.5 (Brand-Recap on category):** GIVEN the category-page `brand-recap` block
  WHEN rendered
  THEN it produces the same DOM shape as on the homepage (border-y, surface-alt bg, logoText + tagline link)
  AND the tagline string equals the homepage tagline (consistency).

- **AC-3.6 (Default category isolation):** GIVEN `themes/default/pages/category.yaml` is read after Slice-3 changes
  WHEN diffed against its pre-Slice-3 state
  THEN no lines have changed.

- **AC-3.7 (Skeleton map entries on category page):** GIVEN `app/kategorie/[slug]/page.tsx` is loaded after Slice 3
  WHEN `SKELETON_MAP` is inspected
  THEN it contains entries for `'product-recommendations'`, `'faq-accordion'`, `'brand-recap'`.

- **AC-3.8 (Marketing-honesty in category YAML):** GIVEN `themes/kleinstadtpflanze/pages/category.yaml` is parsed
  WHEN every string in `faq-accordion.items[].q`, `faq-accordion.items[].a`, `brand-recap.tagline`, `product-recommendations.heading` is concatenated
  THEN the concatenated string contains none of: `"Klimaneutral"`, `"Made in Germany"`, `"Familienbetrieb"`, any `"X,X★"` rating pattern, any `"N Bewertungen"` review-count pattern.

- **AC-3.9 (No new components introduced):** GIVEN a `git diff` of Slice 3 changes
  WHEN file paths are inspected
  THEN no files under `frontend/components/blocks/` are added or modified
  AND no files under `frontend/lib/blocks/` are modified except possibly `registry.ts` (and only IF a new entry is needed — none expected)
  AND the only changes are: 1 new YAML file, 1 modified `app/kategorie/[slug]/page.tsx` (SKELETON_MAP), 1+ test files.

### Deliverables

- [ ] `frontend/themes/kleinstadtpflanze/pages/category.yaml` — New file: 8 default sections + 3 new flair sections (recommendations, faq, brand-recap).
- [ ] `frontend/app/kategorie/[slug]/page.tsx` — Add SKELETON_MAP entries for `product-recommendations`, `faq-accordion`, `brand-recap`.
