# Integration Map: Frontend-Architektur für Theming (P0.2)

**Generated:** 2026-02-23
**Slices:** 5
**Connections:** 18

---

## Dependency Graph (Visual)

```
┌─────────────────────────────────┐
│  Slice 01: Theme Token System   │
│  (Fundament — keine Deps)       │
└────────────────┬────────────────┘
                 │
                 │ cn(), CSS Tokens, generate-theme.mjs, theme.yaml
                 ▼
┌─────────────────────────────────┐
│  Slice 02: shadcn/ui Primitives │
│  (Button, Input, Card, etc.)    │
└────────────────┬────────────────┘
                 │
                 │ Button, Input, Card, Badge, Skeleton, Dialog, Sheet,
                 │ headingFont, bodyFont
                 ▼
┌─────────────────────────────────┐
│  Slice 03: Component Migration  │
│  (Big Bang — 18 Components)     │
└────────────────┬────────────────┘
                 │
                 │ ProductCard, ProductCardSkeleton, layout.tsx
                 │ (bereinigt), NEXT_PUBLIC_SHOP_NAME
                 ▼
┌─────────────────────────────────┐
│  Slice 04: Block Registry +     │
│  Page Renderer                  │
└────────────────┬────────────────┘
                 │
                 │ loadPageConfig, resolveBlock, loadBlockData,
                 │ Block Types, themes/default/pages/home.yaml,
                 │ WP Custom Fields Plugin
                 ▼
┌─────────────────────────────────┐
│  Slice 05: Theme Config +       │
│  Multi-Shop (Deep Merge)        │
└─────────────────────────────────┘
```

---

## Nodes

### Slice 01: Theme Token System

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | None |
| Outputs | CSS Token System, Build Script, cn() Utility, Default Theme Config |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| — | — | No dependencies |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `app/generated-theme.css` (Build Artifact) | CSS File (auto-generated) | `globals.css` (Slice 01 own deliverable), Slice 02 |
| `--color-error`, `--color-success`, `--color-warning`, `--color-overlay` | CSS Custom Properties in `@theme` | Slice 02, Slice 03, Slice 04 |
| `--radius-card`, `--radius-button` | CSS Custom Properties in `@theme` | Slice 02, Slice 04 |
| `--shadow-card`, `--shadow-card-hover` | CSS Custom Properties in `@theme` | Slice 02, Slice 03, Slice 04 |
| `--min-touch-target`, `--color-primary`, `--color-surface-elevated`, `--color-border`, `--color-text-primary`, `--color-text-secondary` | CSS Custom Properties in `@theme` | Slice 02, Slice 03, Slice 04 |
| `cn()` Function in `lib/utils.ts` | TypeScript Function | Slice 02, Slice 03, Slice 04 |
| `themes/default/theme.yaml` | YAML Config File | Slice 05 (Deep Merge Base) |
| `scripts/generate-theme.mjs` (Slice 1 version — single-theme) | Build Script | Slice 05 (replaced by full Deep Merge version) |
| `frontend/package.json` (yaml, clsx, tailwind-merge deps + prebuild script) | Package Config | Slice 02, Slice 04, Slice 05 |

---

### Slice 02: shadcn/ui Primitives

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | Slice 01 |
| Outputs | 7 UI Primitives, Font Loading, tw-animate-css |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `cn()` in `lib/utils.ts` | Slice 01 | Imported in all 7 Primitives |
| `--color-primary`, `--color-error`, `--color-success`, `--color-warning`, `--color-overlay` | Slice 01 | Used via Tailwind token classes |
| `--radius-card`, `--radius-button` | Slice 01 | Used via `var()` in CVA base styles |
| `--shadow-card`, `--shadow-card-hover` | Slice 01 | Used via `var()` in CVA variants |
| `--min-touch-target`, `--color-surface-elevated`, `--color-border`, `--color-text-primary`, `--color-text-secondary` | Slice 01 | Used via Tailwind token classes |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `Button` + `buttonVariants` | React Component (CVA) | Slice 03 (cart, forms), Slice 04 (Hero CTA) |
| `Input` + `inputVariants` | React Component (CVA) | Slice 03 (cart, forms), Slice 04 (Blocks) |
| `Card` + Compound Components | React Component (CVA) | Slice 03 (product-card), Slice 04 (ProductGridBlock) |
| `Badge` + `badgeVariants` | React Component (CVA) | Slice 03 (product/category), Slice 04 (Blocks) |
| `Skeleton` | React Component | Slice 03 (product-card-skeleton), Slice 04 (Block Loading States) |
| `Dialog` + Sub-Components | React Component (Radix) | Slice 03 (overlays), Slice 04 |
| `Sheet` + Sub-Components | React Component (Radix) | Slice 03 (mobile-menu.tsx → Sheet side="left") |
| `headingFont`, `bodyFont` | next/font instances | `app/layout.tsx` (Slice 02 own deliverable), Slice 03 |
| `lib/theme/fonts.ts` | TypeScript Module | `app/layout.tsx` (Slice 02 own modified deliverable) |

---

### Slice 03: Component Migration (Big Bang)

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | Slice 01, Slice 02 |
| Outputs | Migrated Components, Cleaned layout.tsx, Env Pattern |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `--color-error`, `--color-success`, `--color-overlay` | Slice 01 | Used in migration (border-error, text-error, bg-success, bg-overlay) |
| `--shadow-card`, `--shadow-card-hover`, `--radius-card`, `--radius-button` | Slice 01 | Used in product-card migration |
| `cn()` in `lib/utils.ts` | Slice 01 | Used in migrated components |
| `Sheet`, `SheetTrigger`, `SheetContent (side="left")` | Slice 02 | mobile-menu.tsx migration |
| `Button` (variant, size, asChild, isLoading) | Slice 02 | cart-summary, empty-cart, quantity-stepper migrations |
| `Skeleton` | Slice 02 | product-card-skeleton.tsx migration |
| `headingFont`, `bodyFont` (.variable) | Slice 02 | app/layout.tsx font activation |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| Migrated `ProductCard` (token-based, shadow-[var(--shadow-card-hover)]) | React Component | Slice 04 (ProductGridBlock) |
| Migrated `ProductCardSkeleton` (uses `<Skeleton>` primitive) | React Component | Slice 04 (ProductGridBlockSkeleton) |
| Cleaned `app/layout.tsx` (no ThemeProvider, fonts applied) | Next.js Layout | Slice 04 (Block rendering context) |
| `NEXT_PUBLIC_SHOP_NAME` env pattern (7 files) | Env Variable Convention | Slice 04, Slice 05 |
| DELETED: `theme-switcher.tsx`, `contexts/theme-context.tsx`, `lib/theme/themes.ts` | Removed Files | — (no consumers) |

---

### Slice 04: Block Registry + Page Renderer

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | Slice 01, Slice 02, Slice 03 |
| Outputs | Block System, Data Loaders, Page Renderer, WP Custom Fields Plugin |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `yaml` npm package | Slice 01 (package.json deliverable) | `import { parse } from 'yaml'` in page-config.ts |
| CSS Custom Properties (`--color-*`, `--radius-*`) | Slice 01 | Used in Block Components |
| `Skeleton` Component | Slice 02 | hero-block.tsx, category-showcase-block.tsx |
| `lucide-react` npm package | Slice 02 (installed as shadcn dep) | usp-bar-block.tsx icons |
| `ProductCard` (migrated, token-based) | Slice 03 | product-grid-block.tsx |
| `ProductCardSkeleton` (using Skeleton primitive) | Slice 03 | product-grid-block.tsx skeleton |
| `app/page.tsx` (after Slice 03 cleanup) | Slice 03 | Replaced entirely by PageRenderer |
| `getClient()` Apollo Server Client | Existing foundation | data-loaders.ts |
| `GET_FEATURED_PRODUCTS`, `GET_PRODUCT_CATEGORIES` GraphQL Queries | Existing foundation | data-loaders.ts |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `loadPageConfig(slug, theme)` | TypeScript Function | Slice 05, `app/page.tsx` |
| `resolveBlock(type)` | TypeScript Function | Slice 05, `app/page.tsx` |
| `loadBlockData(source, params)` | TypeScript Function | Slice 05, `app/page.tsx` |
| `BlockConfig`, `PageConfig`, `ContentSource` TypeScript Types | Type Definitions | Slice 05 |
| `themes/default/pages/home.yaml` | YAML Config File | Slice 05 (as default template for shop overrides) |
| WP Custom Fields Plugin (all 6 GraphQL fields) | PHP Plugin | WordPress Backend |
| `app/page.tsx` (PageRenderer) | Next.js Page | End User |

---

### Slice 05: Theme Config + Multi-Shop

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | Slice 01, Slice 02, Slice 03, Slice 04 |
| Outputs | Deep Merge Build Script, Second Shop Theme, Logo/Favicon Resolver |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `scripts/generate-theme.mjs` (Slice 1 version) | Slice 01 | Replaced entirely with Deep Merge version |
| `themes/default/theme.yaml` | Slice 01 | Base config for deep merge |
| `loadPageConfig(slug, theme)` | Slice 04 | Already implements fallback to default pages |
| `themes/default/pages/home.yaml` | Slice 04 | Default page config for shop comparison in tests |

**Outputs:**

| Output | Type | Consumers |
|--------|------|-----------|
| `deepMerge()` in `generate-theme.mjs` (replaces Slice 01 version) | Build Script Function | Future additional shops |
| `getLogoPath(theme)`, `getFaviconPath(theme)` in `lib/theme/logo.ts` | TypeScript Functions | `app/layout.tsx` (OUT OF SCOPE for this slice — documented deliberate deferral) |
| `themes/zweiter-shop/` directory (theme.yaml + pages/home.yaml) | Theme Directory | Proof-of-concept for multi-shop system |
| `public/themes/{shop}/assets/` (logo.svg, favicon.ico) | Static Assets | Next.js Static Serving |
| `frontend/.env.local.example` (NEXT_PUBLIC_THEME=default) | Env Config | Developer setup |

---

## Connections

| # | From | To | Resource | Type | Status |
|---|------|-----|----------|------|--------|
| 1 | Slice 01 | Slice 02 | `cn()` in `lib/utils.ts` | TypeScript Function | Valid |
| 2 | Slice 01 | Slice 02 | CSS Custom Properties (`--color-*`, `--radius-*`, `--shadow-*`, `--min-touch-target`) | CSS @theme | Valid |
| 3 | Slice 01 | Slice 03 | CSS Custom Properties (`--color-error`, `--color-success`, `--color-overlay`, `--shadow-*`, `--radius-*`) | CSS @theme | Valid |
| 4 | Slice 01 | Slice 03 | `cn()` in `lib/utils.ts` | TypeScript Function | Valid |
| 5 | Slice 01 | Slice 04 | `yaml` npm package (via package.json) | Dependency | Valid |
| 6 | Slice 01 | Slice 04 | CSS Custom Properties | CSS @theme | Valid |
| 7 | Slice 01 | Slice 05 | `themes/default/theme.yaml` | YAML Config | Valid |
| 8 | Slice 01 | Slice 05 | `scripts/generate-theme.mjs` (base version) | Build Script | Valid |
| 9 | Slice 02 | Slice 03 | `Sheet` + Sub-Components (`side="left"`) | React Component | Valid |
| 10 | Slice 02 | Slice 03 | `Button` Component | React Component | Valid |
| 11 | Slice 02 | Slice 03 | `Skeleton` Component | React Component | Valid |
| 12 | Slice 02 | Slice 03 | `headingFont`, `bodyFont` (.variable) | next/font instances | Valid |
| 13 | Slice 02 | Slice 04 | `Skeleton` Component | React Component | Valid |
| 14 | Slice 02 | Slice 04 | `lucide-react` package | npm Dependency | Valid |
| 15 | Slice 03 | Slice 04 | Migrated `ProductCard` | React Component | Valid |
| 16 | Slice 03 | Slice 04 | Migrated `ProductCardSkeleton` | React Component | Valid |
| 17 | Slice 04 | Slice 05 | `loadPageConfig(slug, theme)` | TypeScript Function | Valid |
| 18 | Slice 04 | Slice 05 | `themes/default/pages/home.yaml` | YAML Config | Valid |

---

## Validation Results

### Valid Connections: 18

All 18 declared dependencies have matching, approved outputs. No input is unmatched.

### Orphaned Outputs: 0

All outputs are either consumed by downstream slices or are final user-facing deliverables:

- `getLogoPath()` / `getFaviconPath()` from Slice 05 are explicitly documented as deferred to a post-feature task (not orphaned — deliberate scoping decision documented in Slice 05 Constraints). The functions are deliverable but their integration into `app/layout.tsx` is deferred. This is acceptable as the functions are self-contained and independently testable.
- `WP Custom Fields Plugin` from Slice 04 is a final deliverable consumed directly by WordPress/WPGraphQL.
- `app/page.tsx` (PageRenderer) from Slice 04 is a final user-facing deliverable.
- `themes/zweiter-shop/` directory from Slice 05 is a proof-of-concept deliverable consumed by the build script.

### Missing Inputs: 0

No inputs without matching producers detected.

### Deliverable-Consumer Gaps: 0

All consumer pages for provided resources are either:
- Deliverables in the SAME slice (e.g., `app/layout.tsx` is modified in Slice 02 to consume `headingFont`/`bodyFont`)
- Deliverables in the NEXT slice (e.g., `ProductCard` consumed by `product-grid-block.tsx` which is a Slice 04 deliverable)
- Final user-facing outputs (e.g., `app/page.tsx` replaces the existing file)

The only documented deferral is `getLogoPath()`/`getFaviconPath()` integration into `app/layout.tsx`, which is explicitly marked as OUT OF SCOPE in Slice 05 Constraints.

---

## Discovery Traceability

### UI Components Coverage

| Discovery Element | Type | Variants/States | Covered In | Status |
|-------------------|------|-----------------|------------|--------|
| `Button` | Interactive | 5 Variants, 5 States | Slice 02 | Covered |
| `Input` | Form | 2 Variants, 4 States | Slice 02 | Covered |
| `Card` | Container | 2 Variants, 2 States | Slice 02 | Covered |
| `Badge` | Display | 4 Variants, 1 State | Slice 02 | Covered |
| `Skeleton` | Loading | animating | Slice 02 | Covered |
| `Dialog` | Overlay | 2 States (open/closed) | Slice 02 | Covered |
| `Sheet` | Overlay/Drawer | 4 side Variants, 2 States | Slice 02 | Covered |
| `HeroBlock` | Page Block | wordpress/inline; loaded, loading, error | Slice 04 | Covered |
| `ProductGridBlock` | Page Block | woocommerce; loaded, loading, empty, error | Slice 04 | Covered |
| `CategoryShowcaseBlock` | Page Block | woocommerce; loaded, loading, empty, error | Slice 04 | Covered |
| `UspBarBlock` | Page Block | inline; loaded (always) | Slice 04 | Covered |

**Coverage: 11/11 (100%)**

### State Machine Coverage

**Build-Time Theme Pipeline:**

| State | Covered In | Status |
|-------|------------|--------|
| `env_read` | Slice 01 (`process.env.NEXT_PUBLIC_THEME`) + Slice 05 (Deep Merge) | Covered |
| `config_loaded` | Slice 01 (default YAML) + Slice 05 (default + shop YAML) | Covered |
| `config_merged` | Slice 05 (`deepMerge()`) | Covered |
| `css_generated` | Slice 01 + Slice 05 (`generateCSS()` + `writeFileSync`) | Covered |
| `build_complete` | Slice 01 + Slice 05 (script exit) | Covered |
| `error_no_config` | Slice 01 + Slice 05 (fallback to `themes/default/`) | Covered |

**Runtime Block Rendering:**

| State | Covered In | Status |
|-------|------------|--------|
| `page_loading` | Slice 04 (`loadPageConfig`) | Covered |
| `blocks_resolving` | Slice 04 (`resolveBlock` + `loadBlockData`) | Covered |
| `blocks_rendered` | Slice 04 (`app/page.tsx` PageRenderer with Suspense) | Covered |
| `block_error` | Slice 04 (null return + console.warn in resolveBlock; error boundary in loadBlockData) | Covered |

**Coverage: 10/10 (100%)**

### Transitions Coverage

| From | Trigger | To | Covered In | Status |
|------|---------|-----|------------|--------|
| `env_read` | Config files exist | `config_loaded` | Slice 01, Slice 05 | Covered |
| `env_read` | Config folder missing | `error_no_config` | Slice 01, Slice 05 | Covered |
| `error_no_config` | Automatic | `config_loaded` (default) | Slice 01, Slice 05 | Covered |
| `config_loaded` | Merge script | `config_merged` | Slice 05 | Covered |
| `config_merged` | CSS generator | `css_generated` | Slice 01, Slice 05 | Covered |
| `css_generated` | `next build` | `build_complete` | Slice 01 (prebuild hook) | Covered |
| Unknown block type | `resolveBlock(unknown)` | Skip + console.warn | Slice 04 | Covered |
| Missing theme config | `loadPageConfig` fallback | Load default page config | Slice 04 | Covered |

**Coverage: 8/8 (100%)**

### Business Rules Coverage

| Rule | Covered In | Status |
|------|------------|--------|
| Theme-Vererbung per Deep Merge (Shop überschreibt Default selektiv) | Slice 05 | Covered |
| Env-Variable Pflicht / Fallback auf Default-Theme | Slice 01, Slice 05 | Covered |
| Block Registry Fallback: Unbekannte Typen überspringen (kein Crash) | Slice 04 | Covered |
| Content Source Validierung: nur wordpress/woocommerce/inline | Slice 04 (TypeScript Union Type) | Covered |
| WP Custom Fields via `register_post_meta()` + `show_in_graphql: true` | Slice 04 | Covered |
| Font Loading via `next/font` mit display:swap | Slice 02, Slice 03 | Covered |
| Logo-Format: SVG bevorzugt | Slice 05 (SVG placeholder assets) | Covered |
| YAML-Schema-Validierung beim Build (Exit 1 bei Fehler) | Slice 01 (single), Slice 05 (merged) | Covered |

**Coverage: 8/8 (100%)**

### Data Fields Coverage

**theme.yaml Schema:**

| Field | Required | Covered In | Status |
|-------|----------|------------|--------|
| `colors.primary` | No | Slice 01 (default), Slice 05 (override example) | Covered |
| `colors.primary-hover` | No | Slice 01 | Covered |
| `colors.error` | No | Slice 01 | Covered |
| `colors.success` | No | Slice 01 | Covered |
| `colors.warning` | No | Slice 01 | Covered |
| `colors.surface` | No | Slice 01 | Covered |
| `colors.surface-elevated` | No | Slice 01 | Covered |
| `colors.text-primary` | No | Slice 01 | Covered |
| `colors.text-secondary` | No | Slice 01 | Covered |
| `colors.border` | No | Slice 01 | Covered |
| `colors.overlay` | No | Slice 01 | Covered |
| `fonts.heading` | No | Slice 01 (default), Slice 05 (override example: Playfair Display) | Covered |
| `fonts.body` | No | Slice 01 | Covered |
| `assets.logo` | No | Slice 05 (via `getLogoPath()` + public/ assets — architecture-approved alternative to YAML field) | Covered |
| `assets.favicon` | No | Slice 05 (via `getFaviconPath()` + public/ assets) | Covered |

**Page Config Schema:**

| Field | Required | Covered In | Status |
|-------|----------|------------|--------|
| `blocks` | Yes (min 1) | Slice 04 | Covered |
| `blocks[].type` | Yes | Slice 04 (Block Registry, 4 types) | Covered |
| `blocks[].content_source` | Yes | Slice 04 (ContentSource TypeScript type) | Covered |
| `blocks[].page_slug` | Conditional (wordpress) | Slice 04 | Covered |
| `blocks[].query` | Conditional (woocommerce) | Slice 04 | Covered |
| `blocks[].props` | Conditional (inline) | Slice 04 | Covered |

**WP Custom Fields:**

| Field | Post Type | GraphQL Name | Covered In | Status |
|-------|-----------|--------------|------------|--------|
| `hero_headline` | page | `heroHeadline` | Slice 04 | Covered |
| `hero_subline` | page | `heroSubline` | Slice 04 | Covered |
| `hero_cta_text` | page | `heroCtaText` | Slice 04 | Covered |
| `hero_cta_link` | page | `heroCtaLink` | Slice 04 | Covered |
| `hero_background_image` | page | `heroBackgroundImage` | Slice 04 | Covered |
| `seo_meta_description` | page, post | `seoMetaDescription` | Slice 04 | Covered |

**Total Data Fields Coverage: 27/27 (100%)**

---

## Summary

| Metric | Value |
|--------|-------|
| Total Slices | 5 |
| Slices APPROVED | 5 |
| Total Connections | 18 |
| Valid Connections | 18 |
| Orphaned Outputs (unexplained) | 0 |
| Missing Inputs | 0 |
| Deliverable-Consumer Gaps | 0 |
| Discovery UI Components | 11/11 (100%) |
| Discovery State Machine States | 10/10 (100%) |
| Discovery Transitions | 8/8 (100%) |
| Discovery Business Rules | 8/8 (100%) |
| Discovery Data Fields | 27/27 (100%) |

---

VERDICT: READY FOR ORCHESTRATION
