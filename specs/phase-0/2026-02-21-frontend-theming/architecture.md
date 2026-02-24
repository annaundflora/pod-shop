# Architecture: Frontend-Architektur für Theming (P0.2)

**Epic:** –
**Status:** Ready
**Discovery:** `discovery.md` (same folder)
**Derived from:** Discovery constraints, NFRs, and risks

---

## Problem & Solution

**Problem:**
- Shop-Name, Farben, Fonts sind in 8+ Dateien hardcoded — Rebranding erfordert manuelle Suche
- 7 Components nutzen hardcoded Tailwind-Farben (`red-*`, `green-*`, `black/50`, `bg-white`) statt semantischer Tokens
- Keine Status-Tokens (error, success, warning, overlay) definiert
- Kein Font-System — nur System-Fonts via Tailwind Default
- Keine Möglichkeit, mehrere Shops mit unterschiedlichem Branding aus derselben Codebase zu betreiben
- Keine wiederverwendbaren UI-Primitives — jede Component implementiert eigene Button-/Card-/Input-Patterns
- `--radius-card`, `--radius-button`, `--shadow-card` nur in `[data-theme]` Blöcken, nicht in `@theme` registriert — nicht als Tailwind-Klassen verfügbar

**Solution:**
- Theme Token System mit YAML-Config pro Shop, Build-Time CSS-Generierung per Env-Variable
- shadcn/ui als Basis-Component-Library mit Theme-Token-Anbindung (CVA Variants, `cn()` Utility)
- Block Registry + Page Renderer für flexible, config-getriebene Seitenlayouts
- Hybrid-Content: Layout-Struktur aus Config (YAML), editierbare Texte aus WP Custom Fields, Produkt-Daten aus WooCommerce GraphQL

**Business Value:**
- Ermöglicht 2-3 White-Label Shops aus einer Codebase (kein Code-Fork pro Shop)
- Reduziert Aufwand für neuen Shop auf: Theme-Config + Assets + WP-Instanz
- Zukunftssicher: Block Registry kann später zu visuellem Editor erweitert werden

---

## Scope & Boundaries

| In Scope |
|----------|
| Theme Token System (YAML → CSS Variables → Tailwind @theme) |
| Build-Pipeline: Env-Variable → YAML lesen → Deep Merge → CSS generieren |
| shadcn/ui Primitives: Button, Input, Card, Badge, Skeleton, Dialog, Sheet |
| Big Bang Migration aller bestehenden Components auf shadcn/ui Primitives |
| Block Registry: type → React Component Mapping |
| Data Loaders: wordpress / woocommerce / inline Content-Quellen |
| Page Renderer: YAML Config → Block-Liste → gerenderte Seite |
| 4 Block-Typen: Hero, ProductGrid, CategoryShowcase, UspBar |
| WP Custom Fields Plugin (PHP): Hero-Felder + SEO Meta Description |
| Theme Config Ordnerstruktur: `themes/{shop}/` mit theme.yaml, pages/, assets/ |
| Deep Merge Vererbung: Shop-Config überschreibt Default-Config |
| Shop-spezifische Assets (Logo, Favicon) |
| Font-Loading via `next/font/google` und `next/font/local` |
| Registrierung von `--radius-card`, `--radius-button`, `--shadow-card`, `--shadow-card-hover` in `@theme` Block |

| Out of Scope |
|--------------|
| Dark Mode / Light-Dark Toggle |
| Visueller Page Builder / Drag & Drop Editor in WP-Admin |
| Gutenberg Block Rendering |
| ACF Pro oder andere kostenpflichtige Plugins |
| Runtime Theme-Switching (Domain-basiert) — nur Build-Time per Env |
| WooCommerce Multisite |
| Bestehende Runtime ThemeProvider / ThemeSwitcher (wird entfernt — Build-Time ersetzt Runtime) |

---

## API Design

### Overview

| Aspect | Specification |
|--------|---------------|
| Style | GraphQL (WPGraphQL + WooGraphQL — bestehend) |
| New Queries | 1 neue Query: `page(slug)` mit Custom Fields |
| Authentication | Nicht nötig — Custom Fields sind public read |
| Rate Limiting | Keins — ISR cached Seiten, max 1 Request/60s pro Route |

### New GraphQL Fields

| Type | Field | Return Type | Resolver | Notes |
|------|-------|-------------|----------|-------|
| `Page` | `heroHeadline` | `String` | `get_post_meta($id, 'hero_headline', true)` | Registered via `register_post_meta()` |
| `Page` | `heroSubline` | `String` | `get_post_meta($id, 'hero_subline', true)` | |
| `Page` | `heroCtaText` | `String` | `get_post_meta($id, 'hero_cta_text', true)` | |
| `Page` | `heroCtaLink` | `String` | `get_post_meta($id, 'hero_cta_link', true)` | |
| `Page` | `heroBackgroundImage` | `String` | `get_post_meta($id, 'hero_background_image', true)` | URL to media |
| `Page` | `seoMetaDescription` | `String` | `get_post_meta($id, 'seo_meta_description', true)` | Also on `Post` type |

### GraphQL Query (Frontend)

```graphql
query GetPageCustomFields($slug: String!) {
  pageBy(uri: $slug) {
    heroHeadline
    heroSubline
    heroCtaText
    heroCtaLink
    heroBackgroundImage
    seoMetaDescription
  }
}
```

### Existing Queries (Reused)

| Query | Used By | Notes |
|-------|---------|-------|
| `GET_FEATURED_PRODUCTS` | ProductGridBlock | `first: N` from page config |
| `GET_PRODUCT_CATEGORIES` | CategoryShowcaseBlock | `first: N` from page config |

---

## Database Schema

### WP Custom Fields (register_post_meta)

| Meta Key | Post Type | Type | Sanitize | GraphQL Field | GraphQL Type |
|----------|-----------|------|----------|---------------|-------------|
| `hero_headline` | `page` | `string` | `sanitize_text_field` | `heroHeadline` | `String` |
| `hero_subline` | `page` | `string` | `sanitize_text_field` | `heroSubline` | `String` |
| `hero_cta_text` | `page` | `string` | `sanitize_text_field` | `heroCtaText` | `String` |
| `hero_cta_link` | `page` | `string` | `esc_url_raw` | `heroCtaLink` | `String` |
| `hero_background_image` | `page` | `string` | `esc_url_raw` | `heroBackgroundImage` | `String` |
| `seo_meta_description` | `page`, `post` | `string` | `sanitize_text_field` | `seoMetaDescription` | `String` |

### Registration Pattern

```php
register_post_meta('page', 'hero_headline', [
    'type'              => 'string',
    'single'            => true,
    'show_in_rest'      => true,
    'show_in_graphql'   => true,
    'sanitize_callback' => 'sanitize_text_field',
    'auth_callback'     => fn() => current_user_can('edit_posts'),
]);
```

### GraphQL Field Registration

```php
add_action('graphql_register_types', function() {
    register_graphql_field('Page', 'heroHeadline', [
        'type'        => 'String',
        'description' => 'Hero section headline',
        'resolve'     => fn($page) => get_post_meta($page->databaseId, 'hero_headline', true) ?: null,
    ]);
});
```

---

## Server Logic

### Build-Time Services

| Service | Responsibility | Input | Output | Side Effects |
|---------|----------------|-------|--------|--------------|
| `generate-theme` | Read YAML configs, deep merge, generate CSS | `NEXT_PUBLIC_THEME` env var | `app/generated-theme.css` | File write |
| `validate-config` | Validate YAML against schema | `theme.yaml`, `pages/*.yaml` | Validation errors or success | Console output |

### Build-Time Theme Pipeline

```
ENV (NEXT_PUBLIC_THEME) → Read themes/{theme}/theme.yaml
                        → Read themes/default/theme.yaml
                        → Deep Merge (shop overrides default)
                        → Validate merged config
                        → Generate CSS Custom Properties
                        → Write app/generated-theme.css
                        → Tailwind @theme consumes CSS vars
                        → Next.js build
```

### Runtime Services

| Service | Responsibility | Input | Output | Side Effects |
|---------|----------------|-------|--------|--------------|
| `loadPageConfig` | Read page YAML at build/ISR time | Page slug, theme name | Block config array | None (reads from filesystem) |
| `BlockRegistry.resolve` | Map block type to React component | Block type string | React component or null | Console.warn for unknown types |
| `DataLoader.wordpress` | Fetch WP Custom Fields via GraphQL | Page slug | Custom field values | GraphQL network request |
| `DataLoader.woocommerce` | Fetch products/categories via GraphQL | Query params | Product/category data | GraphQL network request |
| `DataLoader.inline` | Return props directly from config | Props object | Same props object | None |

### Block Rendering Flow

```
Page Route → loadPageConfig(slug, theme)
           → For each block in config:
              → BlockRegistry.resolve(block.type)
              → DataLoader[block.content_source](block.params)
              → <BlockComponent data={loadedData} {...block.props} />
           → <Suspense> wrapper per block with Skeleton fallback
```

### Validation Rules

| Field | Rule | Error |
|-------|------|-------|
| `theme.yaml: colors.*` | OKLch format: `oklch(L C H)` or `oklch(L C H / A)` | `Invalid color format for {key}: expected oklch()` |
| `theme.yaml: fonts.*` | Non-empty string | `Font name required for {key}` |
| `pages/*.yaml: blocks` | Non-empty array | `Page config must have at least one block` |
| `pages/*.yaml: blocks[].type` | Must exist in Block Registry | `Unknown block type: {type}` |
| `pages/*.yaml: blocks[].content_source` | `wordpress` \| `woocommerce` \| `inline` | `Invalid content source: {source}` |
| `pages/*.yaml: blocks[].page_slug` | Required when `content_source === 'wordpress'` | `page_slug required for wordpress content source` |
| `pages/*.yaml: blocks[].query` | Required when `content_source === 'woocommerce'` | `query required for woocommerce content source` |
| `pages/*.yaml: blocks[].props` | Required when `content_source === 'inline'` | `props required for inline content source` |

---

## Security

### Authentication & Authorization

| Area | Mechanism | Notes |
|------|-----------|-------|
| Custom Fields Read | Public (no auth) | `show_in_graphql: true` — read-only for visitors |
| Custom Fields Write | `auth_callback` | Only `edit_posts` capability (WP admin) |
| Theme Config | Filesystem (build-time) | YAML files in repo — no runtime access |
| Page Config | Filesystem (build-time/ISR) | YAML files in repo — no runtime access |

### Input Validation & Sanitization

| Input | Validation | Sanitization |
|-------|------------|--------------|
| `hero_headline` (WP admin) | `string`, max 200 chars | `sanitize_text_field()` |
| `hero_subline` (WP admin) | `string`, max 500 chars | `sanitize_text_field()` |
| `hero_cta_link` (WP admin) | Valid URL | `esc_url_raw()` |
| `hero_background_image` (WP admin) | Valid URL | `esc_url_raw()` |
| `seo_meta_description` (WP admin) | `string`, max 160 chars | `sanitize_text_field()` |
| YAML config (build-time) | Schema validation | N/A — developer-controlled files |

### Data Protection

| Data Type | Protection | Notes |
|-----------|------------|-------|
| Theme config | No sensitive data | Only colors, fonts, assets paths |
| WP Custom Fields | Standard WP sanitization | `sanitize_text_field`, `esc_url_raw` |

---

## Architecture Layers

### Layer Responsibilities

| Layer | Responsibility | Pattern |
|-------|----------------|---------|
| Build Layer | YAML → CSS generation, config validation | Build script (`scripts/generate-theme.mjs`) |
| Theme Layer | CSS Custom Properties → Tailwind @theme | CSS-first config (`globals.css` imports `generated-theme.css`) |
| Primitive Layer | Reusable UI components (Button, Card, Input, etc.) | shadcn/ui + CVA variants |
| Block Layer | Page-level content blocks (Hero, ProductGrid, etc.) | Block Registry + Data Loaders |
| Page Layer | Route-level orchestration | Next.js App Router RSC + Suspense |
| Content Layer | WP Custom Fields registration + GraphQL exposure | WordPress Plugin (PHP) |

### Data Flow

```
Build-Time:
  themes/{shop}/theme.yaml + themes/default/theme.yaml
    → scripts/generate-theme.mjs (deep merge + validate)
    → app/generated-theme.css (CSS Custom Properties)
    → globals.css (@import "generated-theme.css")
    → Tailwind v4 @theme (consumes CSS vars → utility classes)

Runtime (RSC):
  Page Route (e.g. app/page.tsx)
    → loadPageConfig("home", theme) → reads themes/{theme}/pages/home.yaml
    → For each block:
      → BlockRegistry.resolve(type) → React Component
      → DataLoader[content_source](params) → Data (GraphQL or inline)
    → <Suspense fallback={<Skeleton />}><Block data={data} /></Suspense>
    → ISR revalidate = 60

WordPress:
  WP Admin → Edit Page → Hero Fields (native meta box)
    → Saved via register_post_meta()
    → Exposed via WPGraphQL (show_in_graphql: true + register_graphql_field)
    → Queried by DataLoader.wordpress
```

### Error Handling Strategy

| Error Type | Handling | User Response | Logging |
|------------|----------|---------------|---------|
| Unknown block type | Skip block, continue rendering | Block not shown | `console.warn('Unknown block type: {type}')` |
| GraphQL error (Data Loader) | Error boundary catches | Block not shown, rest of page renders | `console.error` |
| Missing theme config | Fallback to `themes/default/` | Default theme renders | `console.warn('Theme {name} not found, using default')` |
| Missing page config | Fallback to `themes/default/pages/{slug}.yaml` | Default page layout | `console.warn` |
| YAML validation error | Build fails | Build error message | Exit code 1 |
| Invalid color format in YAML | Build fails | Validation error message | Exit code 1 |

### File Structure

```
frontend/
├── app/
│   ├── globals.css                      # @import "generated-theme.css" + @import "tailwindcss" + @theme + base layer
│   ├── generated-theme.css              # BUILD OUTPUT: CSS Custom Properties from YAML (gitignored)
│   ├── layout.tsx                       # Root layout (font loading, providers)
│   └── page.tsx                         # Homepage (uses PageRenderer)
├── components/
│   ├── ui/                              # NEW: shadcn/ui primitives
│   │   ├── button.tsx
│   │   ├── input.tsx
│   │   ├── card.tsx
│   │   ├── badge.tsx
│   │   ├── skeleton.tsx
│   │   ├── dialog.tsx
│   │   └── sheet.tsx
│   ├── blocks/                          # NEW: Page blocks
│   │   ├── hero-block.tsx
│   │   ├── product-grid-block.tsx
│   │   ├── category-showcase-block.tsx
│   │   └── usp-bar-block.tsx
│   ├── layout/                          # MIGRATED: use ui/ primitives
│   ├── product/                         # MIGRATED: use ui/ primitives
│   ├── cart/                            # MIGRATED: use ui/ primitives
│   └── category/                        # MIGRATED: use ui/ primitives
├── lib/
│   ├── blocks/                          # NEW: Block system
│   │   ├── registry.ts                  # Block type → Component mapping
│   │   ├── data-loaders.ts              # wordpress / woocommerce / inline loaders
│   │   ├── page-config.ts               # loadPageConfig(slug, theme)
│   │   └── types.ts                     # BlockConfig, PageConfig, ContentSource types
│   ├── theme/
│   │   └── fonts.ts                     # NEW: next/font instances (heading + body)
│   └── utils.ts                         # NEW: cn() utility (clsx + tailwind-merge)
├── themes/                              # NEW: Theme configs
│   ├── default/
│   │   ├── theme.yaml
│   │   ├── pages/
│   │   │   └── home.yaml
│   │   └── assets/
│   │       └── (logo.svg, favicon.ico)
│   └── {shop-name}/
│       ├── theme.yaml                   # Only overrides (deep merged with default)
│       ├── pages/
│       │   └── home.yaml
│       └── assets/
│           └── (logo.svg, favicon.ico)
├── scripts/
│   └── generate-theme.mjs              # NEW: Build script (YAML → CSS)
└── package.json
```

---

## Constraints & Integrations

### Constraints

| Constraint | Technical Implication | Solution |
|------------|----------------------|----------|
| Tailwind v4 CSS-first (no config file) | Cannot use `tailwind.config.ts` — all config via `@theme` in CSS | Build script generates CSS Custom Properties; `@theme` block references them via `var()` |
| Existing 3 runtime themes (modern/bold/soft) | Runtime ThemeProvider + ThemeSwitcher exist with `[data-theme]` CSS | Remove runtime switching; replace with build-time YAML themes. Current theme variants become YAML presets in `themes/` |
| 151 passing Vitest tests | Migration must not break existing tests | Tests need adaptation for shadcn/ui class changes (wrapper elements, data-slot attributes) |
| WordPress 6.9 + WPGraphQL 2.9.0 | `register_post_meta` with `show_in_graphql: true` supported since WPGraphQL 1.x | Direct `register_post_meta()` + `register_graphql_field()` pattern |
| ISR (revalidate = 60) | Page configs read at build/ISR time, not runtime | `loadPageConfig` reads YAML via `fs.readFileSync` in RSC context |
| Mobile-first (Pinterest traffic) | Touch targets, performance critical | shadcn/ui primitives inherit `--min-touch-target` token |
| OKLch color space | All color tokens in OKLch format | YAML schema enforces `oklch()` format; build script validates |
| `@apollo/experimental-nextjs-app-support` v0.11.x | RSC data fetching via `getClient()` with `React.cache()` | Data Loaders use existing `getClient()` for server-side queries |

### Integrations

| Area | System / Capability | Interface | Version / Notes |
|------|----------------------|-----------|----------------|
| UI Primitives | shadcn/ui | CLI + copy-paste components | shadcn@3.8.5 (Tailwind v4 compatible, tw-animate-css) |
| Variant Styles | class-variance-authority (CVA) | `cva()` function | ^0.7.0 |
| Class Merging | clsx + tailwind-merge | `cn()` utility | clsx ^2.0.0, tailwind-merge ^3.5.0 |
| Accessibility | @radix-ui/react-* | Headless primitives | Per-component (Dialog, Slot, etc.) |
| Icons | lucide-react | React icon components | ^0.575.0 |
| Animation | tw-animate-css | CSS animation classes | Replaces tailwindcss-animate |
| YAML Parsing | yaml (npm) | `parse()` function | ^2.x (build script + runtime config loading) |
| Font Loading | next/font/google, next/font/local | `next/font` module | Built into Next.js 16 |
| GraphQL | WPGraphQL + WooGraphQL | `register_graphql_field()` | WPGraphQL 2.9.0, WooGraphQL 0.21.2 |
| CSS Generation | Node.js script | `fs.readFileSync` + `fs.writeFileSync` | `scripts/generate-theme.mjs` |

---

## Quality Attributes (NFRs)

### From Discovery → Technical Solution

| Attribute | Target | Technical Approach | Measure / Verify |
|-----------|--------|--------------------|------------------|
| Visual Consistency | All components use theme tokens | shadcn/ui primitives with CVA variants bound to CSS variables | Grep for hardcoded Tailwind colors → 0 results |
| Build Performance | Theme generation < 1s | Simple YAML parse + string concatenation (no CSS-in-JS) | Measure `generate-theme.mjs` execution time |
| Runtime Performance | No regression from current Lighthouse score | shadcn/ui is Tailwind-only (no runtime CSS), Block rendering uses existing Suspense/ISR patterns | Lighthouse before/after comparison |
| Font Performance | No layout shift from font loading | `next/font` with `display: swap` and `adjustFontFallback` | CLS metric in Lighthouse |
| Accessibility | WCAG 2.1 AA | Radix UI primitives provide ARIA roles, keyboard nav, focus management out-of-box | axe-core audit, keyboard testing |
| Test Coverage | All 151 existing tests pass after migration | Adapt test assertions for shadcn/ui class/structure changes | `pnpm test` green |
| Multi-Shop Setup | New shop in < 1 hour | Only requires: theme.yaml + pages/home.yaml + assets/ | Manual verification |
| Config Validation | Invalid YAML fails build with clear error | Schema validation in `generate-theme.mjs` | Unit tests for validation |
| Touch Targets | Min 44px (2.75rem) | `--min-touch-target` token applied to all interactive shadcn/ui primitives | Visual audit |

### Monitoring & Observability

| Metric | Type | Target | Alert |
|--------|------|--------|-------|
| Build time | Timer | < 30s total (theme gen < 1s) | N/A (local dev + CI) |
| Lighthouse Performance | Score | >= current baseline | Manual check per deploy |
| CLS (Cumulative Layout Shift) | Web Vital | < 0.1 | Lighthouse audit |
| Bundle size delta | Bytes | < +50KB gzipped from shadcn/ui additions | `next build` output comparison |

---

## Risks & Assumptions

### Assumptions

| Assumption | Technical Validation | Impact if Wrong |
|------------|---------------------|-----------------|
| shadcn/ui components work with Tailwind v4 CSS-first config | Confirmed via shadcn/ui docs (Tailwind v4 page) + CLI support | Would need manual component porting |
| `register_post_meta` with `show_in_graphql: true` exposes fields in WPGraphQL | Pattern confirmed in WPGraphQL docs; project already uses WPGraphQL 2.9.0 | Would need `register_graphql_field` fallback (already planned as belt-and-suspenders) |
| YAML files can be read at ISR time via `fs.readFileSync` in RSC | Standard Node.js fs access works in Next.js RSC server context | Would need to read configs at build-time only and inline as JSON |
| `next/font` supports dynamic font selection from YAML config | `next/font` requires static analysis at build-time — font must be determined at build | Generate `lib/theme/fonts.ts` from YAML during build step |
| Existing tests can be adapted to shadcn/ui structure changes | Tests mock at component level; shadcn/ui changes internal HTML/classes | Some tests may need significant rewrite of assertions |

### Risks & Mitigation

| Risk | Likelihood | Impact | Technical Mitigation | Fallback |
|------|------------|--------|---------------------|----------|
| Big Bang migration breaks too many tests at once | Medium | High | Run migration + test fixup as single slice; no partial migration state | Revert commit; switch to incremental migration |
| `next/font` static analysis conflicts with dynamic YAML font config | Medium | Medium | Build script generates `fonts.ts` with static `next/font` calls from YAML values | Use CSS `@font-face` directly in generated-theme.css |
| shadcn/ui Dialog/Sheet SSR hydration issues in App Router | Low | Medium | Use `'use client'` directive on Dialog/Sheet; test SSR explicitly | Lazy-load overlay components |
| YAML config gets complex/hard to maintain for multiple shops | Low | Low | Start with 2 themes only; keep schema minimal; validate strictly | Switch to JSON if YAML proves problematic |
| WP Custom Fields not visible in WP Admin without meta box UI | Low | Medium | Register meta boxes via `add_meta_box()` for admin editing | Use WP REST API + custom admin page |
| `fs.readFileSync` in ISR causes edge runtime errors | Low | High | Ensure page configs are loaded only in Node.js runtime (not Edge) | Pre-bundle configs at build-time into JSON |

---

## Technology Decisions

### Stack Choices

| Area | Technology | Rationale |
|------|------------|-----------|
| UI Primitives | shadcn/ui (Tailwind v4 mode) | Copy-paste components, full customization, Radix accessibility, no runtime CSS overhead |
| Variant System | CVA (class-variance-authority) | Type-safe variant definitions, works with Tailwind utility classes |
| Class Utility | `cn()` (clsx + tailwind-merge) | Industry standard for conditional + conflict-free class merging |
| Config Format | YAML | Human-readable, supports comments, deep merge natural; used by Shopify, Gatsby, Hugo |
| CSS Generation | Custom Node.js script | Simple, no build tool dependencies, full control over output format |
| Font Loading | `next/font` (Google + local) | Zero layout shift, self-hosted, built into Next.js |
| WP Custom Fields | Native `register_post_meta()` | Free, lightweight, GraphQL-compatible, no plugin dependency |
| Block Registry | Custom TypeScript module | Lightweight, type-safe, no framework overhead; pattern from Shopify Hydrogen |

### Trade-offs

| Decision | Pro | Con | Mitigation |
|----------|-----|-----|------------|
| Build-time theme (not runtime) | Simpler, faster, no hydration issues | Requires rebuild per theme change | Separate deployments per shop; CI/CD pipeline |
| YAML over JSON for config | Comments, readability, multi-line strings | Extra dependency (`yaml` npm package), less tooling | Strict schema validation at build |
| Big Bang migration (not incremental) | Clean cut, no dual-pattern period, easier to reason about | Higher risk per commit, more test changes at once | Single slice with focused test fixup |
| shadcn/ui over custom primitives | Battle-tested accessibility, CVA patterns, community | Adds ~6 dependencies, opinionated structure | Pin versions, audit bundle impact |
| Custom Block Registry over CMS blocks | Lightweight, no WP/Gutenberg coupling, type-safe | Manual maintenance, no visual editor | Schema validation, good defaults |
| Remove runtime ThemeSwitcher | Simplifies architecture, one theme per build | Loses demo/preview of multiple themes in dev | Keep themes as YAML presets; dev can rebuild with different env |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| -- | Keine offenen Fragen | -- | -- | -- |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-02-23 | Codebase | 8 files with hardcoded "POD Shop": layout.tsx, page.tsx, warenkorb/page.tsx, kategorie/[slug]/page.tsx, produkt/[slug]/page.tsx, header.tsx, footer.tsx |
| 2026-02-23 | Codebase | 7 components with hardcoded colors: cart-page-client.tsx (red-*), cart-item-row.tsx (red-*), add-to-cart-button.tsx (green-600), product-card.tsx (black/50, black/70), mobile-menu.tsx (black/40), cookie-consent-banner.tsx (bg-white), theme-switcher.tsx (purple-600, orange-500, emerald-500) |
| 2026-02-23 | Codebase | --radius-card, --radius-button, --shadow-card, --shadow-card-hover defined in [data-theme] blocks but NOT in @theme — not available as Tailwind utility classes |
| 2026-02-23 | Codebase | 10 files use hardcoded shadow-* Tailwind utilities (shadow-lg, shadow-xl, shadow-md, shadow-sm) instead of --shadow-card tokens |
| 2026-02-23 | Codebase | No font-family declarations anywhere — relies on Tailwind default sans-serif stack |
| 2026-02-23 | Codebase | 3 runtime themes (modern/bold/soft) via ThemeProvider + ThemeSwitcher + data-theme attribute |
| 2026-02-23 | Codebase | No tailwind.config.ts — Tailwind v4 CSS-first via @theme in globals.css |
| 2026-02-23 | Codebase | Package versions: Next.js ^16.1.6, React ^19.0.0, @apollo/client ^4.1.4, Tailwind ^4.0.0 |
| 2026-02-23 | Codebase | WPGraphQL 2.9.0, WooGraphQL 0.21.2 — no existing register_post_meta with show_in_graphql |
| 2026-02-23 | Codebase | No register_graphql_field calls in custom plugins — spreadconnect + pinterest plugins use get_post_meta directly |
| 2026-02-23 | Web | shadcn/ui fully supports Tailwind v4 CSS-first config, OKLCH colors, React 19. CLI can init Tailwind v4 projects. |
| 2026-02-23 | Web | shadcn/ui deps: CVA ^0.7.0, clsx ^2.0.0, tailwind-merge, @radix-ui/react-*, lucide-react, tw-animate-css (replaced tailwindcss-animate March 2025) |
| 2026-02-23 | Web | next/font: Self-hosts Google fonts at build-time, zero CLS, works in App Router layout.tsx. Requires static imports (not dynamic). |

---

## Q&A Log

| # | Question | Answer |
|---|----------|--------|
| 1 | wireframes.md fehlt. Zuerst /wireframe ausführen? | Ohne Wireframes fortfahren — Discovery markiert dies als Architektur-Feature ohne neue UI |
