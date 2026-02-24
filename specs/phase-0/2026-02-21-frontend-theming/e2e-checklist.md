# E2E Checklist: Frontend-Architektur für Theming (P0.2)

**Integration Map:** `integration-map.md`
**Generated:** 2026-02-23

---

## Pre-Conditions

- [ ] All slices APPROVED (Gate 2) — Slice 01, 02, 03, 04, 05: ALL APPROVED
- [ ] Architecture APPROVED (Gate 1) — `compliance-architecture.md`: APPROVED
- [ ] Integration Map has no MISSING INPUTS — confirmed: 0 missing inputs
- [ ] `pnpm install` completed in `frontend/`
- [ ] `node scripts/generate-theme.mjs` runs without errors (generates `app/generated-theme.css`)
- [ ] Docker services running: `docker compose up -d` (WordPress + WooCommerce + MySQL)
- [ ] WP Custom Fields Plugin activated in WordPress

---

## Happy Path Tests

### Flow 1: Build Pipeline — Default Theme

1. [ ] **Slice 01/05:** Given `NEXT_PUBLIC_THEME` is unset, run `node scripts/generate-theme.mjs`
2. [ ] **Slice 01:** Verify `frontend/app/generated-theme.css` is created
3. [ ] **Slice 01:** Verify `generated-theme.css` contains `:root {` block with `--theme-color-primary`
4. [ ] **Slice 01:** Verify all 12 color tokens present: `--theme-color-error`, `--theme-color-success`, `--theme-color-warning`, `--theme-color-overlay`, and 8 base colors
5. [ ] **Slice 01:** Verify 2 radius tokens: `--theme-radius-card`, `--theme-radius-button`
6. [ ] **Slice 01:** Verify 2 shadow tokens: `--theme-shadow-card`, `--theme-shadow-card-hover`
7. [ ] **Slice 01:** Verify 2 font tokens: `--theme-font-heading: Inter`, `--theme-font-body: Inter`
8. [ ] **Slice 01:** Run `pnpm build` — verify `prebuild` script executes `generate-theme.mjs` automatically

### Flow 2: Build Pipeline — Second Shop (Deep Merge)

1. [ ] **Slice 05:** Run `NEXT_PUBLIC_THEME=zweiter-shop node scripts/generate-theme.mjs`
2. [ ] **Slice 05:** Verify `generated-theme.css` contains `--theme-color-primary: oklch(0.55 0.18 30)` (shop override)
3. [ ] **Slice 05:** Verify `generated-theme.css` contains `--theme-color-surface: oklch(1 0 0)` (default fallback — not overridden)
4. [ ] **Slice 05:** Verify `generated-theme.css` contains `--theme-font-heading: Playfair Display` (shop override)
5. [ ] **Slice 05:** Verify `generated-theme.css` contains `--theme-font-body: Inter` (default fallback)
6. [ ] **Slice 05:** Run `NEXT_PUBLIC_THEME=nicht-existierender-shop node scripts/generate-theme.mjs` — verify fallback to default with stdout message

### Flow 3: YAML Validation — Build Fails on Invalid Config

1. [ ] **Slice 01/05:** Given a `theme.yaml` with `primary: "#FF0000"` (hex format)
2. [ ] **Slice 01/05:** Run `node scripts/generate-theme.mjs` — verify Exit Code 1
3. [ ] **Slice 01/05:** Verify stderr contains `Invalid color format for primary: expected oklch()`
4. [ ] **Slice 01/05:** Given a `theme.yaml` with `heading: ""` (empty font name)
5. [ ] **Slice 01/05:** Run script — verify Exit Code 1 with `Font name required for heading`

### Flow 4: shadcn/ui Primitives — Token Binding

1. [ ] **Slice 02:** Run `pnpm test tests/slices/frontend-theming/slice-02-shadcn-ui-primitives.test.ts`
2. [ ] **Slice 02:** Verify all 7 primitive files exist in `frontend/components/ui/`
3. [ ] **Slice 02:** Verify `Button variant="destructive"` renders with `bg-error` (not `bg-red-*`)
4. [ ] **Slice 02:** Verify `Button variant="default"` has `h-[var(--min-touch-target,2.75rem)]`
5. [ ] **Slice 02:** Verify `Input variant="error"` has `aria-invalid="true"` + `role="alert"` on error message
6. [ ] **Slice 02:** Verify `Card variant="interactive"` has `hover:shadow-[var(--shadow-card-hover)]`
7. [ ] **Slice 02:** Verify `Skeleton` renders with `animate-pulse bg-surface-elevated`
8. [ ] **Slice 02:** Verify `Dialog` open renders overlay with `bg-overlay`
9. [ ] **Slice 02:** Verify `Sheet side="right"` renders with `slide-in-from-right` class
10. [ ] **Slice 02:** Verify no `bg-red-*`, `bg-green-*`, `bg-gray-*` classes in any primitive

### Flow 5: Component Migration — No Hardcoded Colors Remain

1. [ ] **Slice 03:** Run `grep -r "bg-red-\|bg-green-\|bg-black/\|bg-white\|bg-purple-\|bg-orange-\|bg-emerald-\|text-red-\|border-red-\|ring-red-\|ring-green-\|\bshadow-sm\b\|\bshadow-md\b\|\bshadow-lg\b\|\bshadow-xl\b" frontend/components frontend/app` — expect zero results
2. [ ] **Slice 03:** Run `grep -r "ThemeProvider\|ThemeSwitcher\|useTheme\|theme-context\|pod-theme\|data-theme" frontend/app frontend/components` — expect zero results
3. [ ] **Slice 03:** Verify `components/layout/theme-switcher.tsx` does NOT exist (deleted)
4. [ ] **Slice 03:** Verify `contexts/theme-context.tsx` does NOT exist (deleted)
5. [ ] **Slice 03:** Verify `lib/theme/themes.ts` does NOT exist (deleted)
6. [ ] **Slice 03:** Verify `components/product/add-to-cart-button.tsx` contains `bg-success` (not `bg-green-600`)
7. [ ] **Slice 03:** Verify `app/warenkorb/cart-page-client.tsx` contains `border-error text-error` (not `border-red-200 text-red-800`)
8. [ ] **Slice 03:** Verify `components/product/product-card.tsx` contains `bg-overlay` (not `bg-black/50`)
9. [ ] **Slice 03:** Verify `components/layout/mobile-menu.tsx` imports `Sheet` from `@/components/ui/sheet`
10. [ ] **Slice 03:** Verify `components/product/product-card-skeleton.tsx` imports `Skeleton` from `@/components/ui/skeleton`
11. [ ] **Slice 03:** Verify `app/layout.tsx` contains `headingFont.variable` and `bodyFont.variable` on `<html>`
12. [ ] **Slice 03:** Verify `app/layout.tsx` does NOT contain `ThemeProvider` or `dangerouslySetInnerHTML`
13. [ ] **Slice 03:** Verify `app/globals.css` does NOT contain `[data-theme=`

### Flow 6: All Existing Tests Still Pass (Big Bang Regression Check)

1. [ ] **Slice 03:** Run `cd frontend && pnpm test` — verify all 151 existing pod-shop-mvp tests pass
2. [ ] **Slice 03:** Verify zero test failures from `tests/slices/pod-shop-mvp/`

### Flow 7: Block Registry + Page Renderer

1. [ ] **Slice 04:** Verify `lib/blocks/types.ts` exports `ContentSource`, `BlockConfig`, `PageConfig`
2. [ ] **Slice 04:** Verify `lib/blocks/registry.ts` maps `hero`, `product-grid`, `category-showcase`, `usp-bar`
3. [ ] **Slice 04:** Verify `resolveBlock('unknown-type')` returns `null` and logs `console.warn('Unknown block type: unknown-type')`
4. [ ] **Slice 04:** Verify `themes/default/pages/home.yaml` has 4 blocks: hero (wordpress), product-grid (woocommerce), category-showcase (woocommerce), usp-bar (inline)
5. [ ] **Slice 04:** Verify `app/page.tsx` uses `loadPageConfig` + `resolveBlock` + `loadBlockData` pattern
6. [ ] **Slice 04:** Verify `app/page.tsx` has `export const revalidate = 60`
7. [ ] **Slice 04:** Verify `components/blocks/hero-block.tsx` has fallback values for all 5 WP Custom Fields
8. [ ] **Slice 04:** Run `pnpm test tests/slices/frontend-theming/slice-04-block-registry-page-renderer.test.ts` — all pass

### Flow 8: WP Custom Fields Plugin

1. [ ] **Slice 04:** Verify `wordpress/plugins/wp-custom-fields/wp-custom-fields.php` exists
2. [ ] **Slice 04:** Verify `wordpress/plugins/wp-custom-fields/includes/class-custom-fields.php` registers all 6 meta keys
3. [ ] **Slice 04:** Verify `hero_headline`, `hero_subline`, `hero_cta_text`, `hero_cta_link`, `hero_background_image`, `seo_meta_description` are registered with `show_in_graphql: true`
4. [ ] **Slice 04:** Verify `hero_cta_link` and `hero_background_image` use `esc_url_raw` sanitization
5. [ ] **Slice 04:** With WordPress running, verify GraphQL query `{ pageBy(uri: "/") { heroHeadline heroSubline } }` returns data (or null — no error)

### Flow 9: Multi-Shop System (End-to-End)

1. [ ] **Slice 05:** Verify `themes/zweiter-shop/theme.yaml` exists with minimal overrides (3-4 colors + 1 font)
2. [ ] **Slice 05:** Verify `themes/zweiter-shop/pages/home.yaml` exists with shop-specific USP texts
3. [ ] **Slice 05:** Verify `public/themes/default/assets/logo.svg` exists
4. [ ] **Slice 05:** Verify `public/themes/default/assets/favicon.ico` exists
5. [ ] **Slice 05:** Verify `public/themes/zweiter-shop/assets/logo.svg` exists
6. [ ] **Slice 05:** Verify `public/themes/zweiter-shop/assets/favicon.ico` exists
7. [ ] **Slice 05:** Verify `lib/theme/logo.ts` exports `getLogoPath` and `getFaviconPath`
8. [ ] **Slice 05:** Verify `getLogoPath('zweiter-shop')` returns `/themes/zweiter-shop/assets/logo.svg`
9. [ ] **Slice 05:** Verify `getLogoPath('non-existent-shop')` returns `/themes/default/assets/logo.svg` (fallback)
10. [ ] **Slice 05:** Verify `frontend/.env.local.example` contains `NEXT_PUBLIC_THEME=default`
11. [ ] **Slice 05:** Run `NEXT_PUBLIC_THEME=zweiter-shop pnpm build` — verify build succeeds with shop theme

---

## Edge Cases

### Error Handling

- [ ] Unknown block type in `home.yaml` — block is skipped, rest of page renders, `console.warn` logged
- [ ] Missing `themes/{shop}/pages/home.yaml` — `loadPageConfig` falls back to `themes/default/pages/home.yaml`
- [ ] Missing `themes/{shop}/` directory entirely — build script falls back to default theme with stdout message
- [ ] Empty `fonts.heading` in `theme.yaml` — build fails with `Font name required for heading` (Exit 1)
- [ ] Non-oklch color in `theme.yaml` — build fails with `Invalid color format for {key}: expected oklch()` (Exit 1)
- [ ] GraphQL network error during `DataLoader.wordpress` call — block renders with fallback values (headline/subline from `??` defaults in HeroBlock)
- [ ] GraphQL network error during `DataLoader.woocommerce` call — `ProductGridBlock`/`CategoryShowcaseBlock` returns `null` (block not shown)
- [ ] `UspBarBlock` with empty `items` array — returns `null` (block not shown)

### State Transitions

- [ ] `env_read` → `error_no_config` → `config_loaded` (default): non-existent `NEXT_PUBLIC_THEME` triggers fallback
- [ ] `config_loaded` → `config_merged` → `css_generated`: Deep Merge runs, CSS written, Exit 0
- [ ] `page_loading` → `blocks_resolving` → `blocks_rendered`: Homepage renders all 4 blocks
- [ ] `block_error`: Unknown block type triggers null return, no crash, console.warn

### Boundary Conditions

- [ ] `pnpm build` with missing `app/generated-theme.css` — `prebuild` hook generates it automatically before Next.js build
- [ ] `deepMerge({}, {})` — returns empty object without error
- [ ] `deepMerge(defaultConfig, null)` — returns `defaultConfig` unchanged (null-guard in generate-theme.mjs)
- [ ] `loadPageConfig` with valid `slug` but empty blocks array — throws `Page config must have at least one block`
- [ ] `getLogoPath('default')` — returns `/themes/default/assets/logo.svg` directly (no theme check)
- [ ] `getLogoPath()` (no argument) — uses default `'default'`, returns default logo path

---

## Cross-Slice Integration Points

| # | Integration Point | Slices | How to Verify |
|---|-------------------|--------|---------------|
| 1 | CSS Token → shadcn/ui | Slice 01 → Slice 02 | `Button` renders with `bg-primary` class (not `bg-blue-*`) |
| 2 | Skeleton Primitive → ProductCardSkeleton | Slice 02 → Slice 03 | `product-card-skeleton.tsx` imports from `@/components/ui/skeleton` |
| 3 | Sheet Primitive → MobileMenu | Slice 02 → Slice 03 | `mobile-menu.tsx` imports `Sheet` from `@/components/ui/sheet` |
| 4 | ProductCard (migrated) → ProductGridBlock | Slice 03 → Slice 04 | `product-grid-block.tsx` imports `ProductCard` from `@/components/product/product-card` |
| 5 | cn() Utility → All Primitives | Slice 01 → Slice 02 | `button.tsx`, `card.tsx` etc. import `cn` from `@/lib/utils` |
| 6 | loadPageConfig → app/page.tsx | Slice 04 → Slice 04 | `app/page.tsx` calls `loadPageConfig('home', theme)` |
| 7 | Default theme.yaml → Deep Merge | Slice 01 → Slice 05 | `generate-theme.mjs` reads `themes/default/theme.yaml` as base |
| 8 | Default home.yaml → Shop Page Config | Slice 04 → Slice 05 | `loadPageConfig('home', 'zweiter-shop')` loads shop-specific YAML |

---

## Full Test Suite

Run all theming slice tests together:

```bash
cd frontend && pnpm test tests/slices/frontend-theming/
```

Expected: All 5 test files pass.

Run entire test suite (regression check):

```bash
cd frontend && pnpm test
```

Expected: All 151 existing pod-shop-mvp tests + all new theming tests pass.

---

## Sign-Off

| Tester | Date | Result |
|--------|------|--------|
| — | — | — |

**Notes:**
Pre-implementation checklist — to be completed after Orchestrator implements all slices.
