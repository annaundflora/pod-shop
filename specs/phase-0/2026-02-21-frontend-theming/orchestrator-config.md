# Orchestrator Configuration: Frontend-Architektur für Theming (P0.2)

**Integration Map:** `integration-map.md`
**E2E Checklist:** `e2e-checklist.md`
**Generated:** 2026-02-23

---

## Pre-Implementation Gates

```yaml
pre_checks:
  - name: "Gate 1: Architecture Compliance"
    file: "specs/phase-0/2026-02-21-frontend-theming/compliance-architecture.md"
    required: "Verdict == APPROVED"
    status: APPROVED

  - name: "Gate 2: All Slices Approved"
    files:
      - "specs/phase-0/2026-02-21-frontend-theming/slices/compliance-slice-01.md"
      - "specs/phase-0/2026-02-21-frontend-theming/slices/compliance-slice-02.md"
      - "specs/phase-0/2026-02-21-frontend-theming/slices/compliance-slice-03.md"
      - "specs/phase-0/2026-02-21-frontend-theming/slices/compliance-slice-04.md"
      - "specs/phase-0/2026-02-21-frontend-theming/slices/compliance-slice-05.md"
    required: "ALL Verdict == APPROVED"
    status: ALL APPROVED

  - name: "Gate 3: Integration Map Valid"
    file: "specs/phase-0/2026-02-21-frontend-theming/integration-map.md"
    required: "VERDICT == READY FOR ORCHESTRATION"
    status: READY FOR ORCHESTRATION
```

---

## Implementation Order

Based on the strict linear dependency chain (each slice depends on all previous slices):

| Order | Slice | Name | Depends On | Parallel? |
|-------|-------|------|------------|-----------|
| 1 | slice-01 | Theme Token System | — (foundation) | No — foundation for all |
| 2 | slice-02 | shadcn/ui Primitives | slice-01 | No — requires Slice 01 CSS tokens |
| 3 | slice-03 | Component Migration (Big Bang) | slice-01, slice-02 | No — requires primitives from Slice 02 |
| 4 | slice-04 | Block Registry + Page Renderer | slice-01, slice-02, slice-03 | No — requires migrated components from Slice 03 |
| 5 | slice-05 | Theme Config + Multi-Shop | all previous | No — completes the system |

**Note:** There are NO parallel opportunities in this feature. The dependency chain is strictly linear. Each slice must be fully implemented and its tests must pass before the next slice begins.

---

## Per-Slice Implementation Instructions

### Slice 01: Theme Token System

**Spec:** `specs/phase-0/2026-02-21-frontend-theming/slices/slice-01-theme-token-system.md`

**Deliverables to create/modify:**

```
NEW:   frontend/scripts/generate-theme.mjs
NEW:   frontend/themes/default/theme.yaml
NEW:   frontend/lib/utils.ts
NEW:   frontend/tests/slices/frontend-theming/slice-01-theme-token-system.test.ts
MODIFY: frontend/app/globals.css
MODIFY: frontend/package.json
MODIFY: .gitignore (root)
```

**Key constraints:**
- `generate-theme.mjs` MUST be ESM (`.mjs` extension) — yaml package has ESM-only exports
- `globals.css` MUST have `@import "./generated-theme.css"` BEFORE `@import "tailwindcss"`
- `generated-theme.css` uses `--theme-*` prefix (not `--color-*`) to avoid Tailwind conflicts
- `@theme` block maps `--theme-*` variables to Tailwind token names (`--color-error`, `--radius-card`, etc.)
- Remove ALL `[data-theme="modern"]`, `[data-theme="bold"]`, `[data-theme="soft"]` blocks from `globals.css`
- Do NOT remove `theme-switcher.tsx` or `theme-context.tsx` in this slice — that is Slice 03 scope

**Test command:**
```bash
cd frontend && pnpm test tests/slices/frontend-theming/slice-01-theme-token-system.test.ts
```

**Acceptance command:**
```bash
cd frontend && node scripts/generate-theme.mjs && cat app/generated-theme.css
```

**Pass criteria:** All tests green, `generated-theme.css` contains all 12 color tokens + 2 font + 2 radius + 2 shadow tokens in `:root {}` block.

---

### Slice 02: shadcn/ui Primitives

**Spec:** `specs/phase-0/2026-02-21-frontend-theming/slices/slice-02-shadcn-ui-primitives.md`

**Deliverables to create/modify:**

```
NEW:   frontend/components/ui/button.tsx
NEW:   frontend/components/ui/input.tsx
NEW:   frontend/components/ui/card.tsx
NEW:   frontend/components/ui/badge.tsx
NEW:   frontend/components/ui/skeleton.tsx
NEW:   frontend/components/ui/dialog.tsx
NEW:   frontend/components/ui/sheet.tsx
NEW:   frontend/lib/theme/fonts.ts
NEW:   frontend/tests/slices/frontend-theming/slice-02-shadcn-ui-primitives.test.ts
MODIFY: frontend/app/layout.tsx
MODIFY: frontend/app/globals.css
MODIFY: frontend/package.json
```

**Key constraints:**
- `dialog.tsx` and `sheet.tsx` MUST have `'use client'` directive (SSR hydration)
- ALL primitives use `data-slot="..."` attributes (shadcn/ui convention, used by tests)
- NO hardcoded Tailwind colors (`bg-red-*`, `bg-green-*`, `bg-gray-*`) — only token classes (`bg-error`, `bg-success`, etc.)
- `globals.css` import order: `generated-theme.css` → `tailwindcss` → `tw-animate-css`
- If `tailwindcss-animate` is in `package.json`, REMOVE it to avoid conflicts with `tw-animate-css`
- `lib/theme/fonts.ts`: `next/font/google` requires static imports — Inter is hardcoded for Default theme; Slice 05 will generate dynamic fonts.ts for other themes

**Test command:**
```bash
cd frontend && pnpm test tests/slices/frontend-theming/slice-02-shadcn-ui-primitives.test.ts
```

**Acceptance command:**
```bash
cd frontend && pnpm build
```

**Pass criteria:** All tests green, `pnpm build` exits with code 0 (no TypeScript errors, no import errors).

---

### Slice 03: Component Migration (Big Bang)

**Spec:** `specs/phase-0/2026-02-21-frontend-theming/slices/slice-03-component-migration.md`

**Deliverables to create/modify:**

```
MODIFY: frontend/app/layout.tsx
MODIFY: frontend/app/page.tsx
MODIFY: frontend/app/warenkorb/page.tsx
MODIFY: frontend/app/warenkorb/cart-page-client.tsx
MODIFY: frontend/app/kategorie/[slug]/page.tsx
MODIFY: frontend/app/produkt/[slug]/page.tsx
MODIFY: frontend/components/layout/header.tsx
MODIFY: frontend/components/layout/footer.tsx
MODIFY: frontend/components/layout/mobile-menu.tsx
MODIFY: frontend/components/layout/cookie-consent-banner.tsx
MODIFY: frontend/components/product/product-card.tsx
MODIFY: frontend/components/product/product-card-skeleton.tsx
MODIFY: frontend/components/product/add-to-cart-button.tsx
MODIFY: frontend/components/cart/cart-item-row.tsx
MODIFY: frontend/components/cart/cart-summary.tsx
MODIFY: frontend/components/cart/empty-cart.tsx
MODIFY: frontend/components/cart/quantity-stepper.tsx
MODIFY: frontend/.env.local.example
DELETE: frontend/components/layout/theme-switcher.tsx
DELETE: frontend/contexts/theme-context.tsx
DELETE: frontend/lib/theme/themes.ts
NEW:   frontend/tests/slices/frontend-theming/slice-03-component-migration.test.ts
```

**Key constraints:**
- `bg-error/10` DOES NOT WORK (var() indirection in @theme) — use `[background-color:color-mix(in_oklch,var(--color-error)_10%,transparent)]` instead
- Delete files ONLY after removing all their imports first (layout.tsx, header.tsx)
- `mobile-menu.tsx` uses `<Sheet side="left">` from Slice 02 — no custom drawer implementation
- Shop name pattern: `process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'` in all 7 locations
- All shadow utilities replaced: `shadow-sm/md` → `shadow-[var(--shadow-card)]`, `shadow-lg/xl` → `shadow-[var(--shadow-card-hover)]`
- Existing Vitest tests in `tests/slices/pod-shop-mvp/` must NOT be broken — they test business logic, not UI classes

**Test command:**
```bash
cd frontend && pnpm test tests/slices/frontend-theming/slice-03-component-migration.test.ts
```

**Acceptance command (full regression check):**
```bash
cd frontend && pnpm test
```

**Pass criteria:** All 151 existing tests still green PLUS all new Slice 03 tests green.

---

### Slice 04: Block Registry + Page Renderer

**Spec:** `specs/phase-0/2026-02-21-frontend-theming/slices/slice-04-block-registry-page-renderer.md`

**Deliverables to create/modify:**

```
NEW:   frontend/lib/blocks/types.ts
NEW:   frontend/lib/blocks/registry.ts
NEW:   frontend/lib/blocks/data-loaders.ts
NEW:   frontend/lib/blocks/page-config.ts
NEW:   frontend/components/blocks/hero-block.tsx
NEW:   frontend/components/blocks/product-grid-block.tsx
NEW:   frontend/components/blocks/category-showcase-block.tsx
NEW:   frontend/components/blocks/usp-bar-block.tsx
NEW:   frontend/themes/default/pages/home.yaml
NEW:   wordpress/plugins/wp-custom-fields/wp-custom-fields.php
NEW:   wordpress/plugins/wp-custom-fields/includes/class-custom-fields.php
NEW:   frontend/tests/slices/frontend-theming/slice-04-block-registry-page-renderer.test.ts
MODIFY: frontend/app/page.tsx  (replaces hardcoded Homepage with PageRenderer)
MODIFY: frontend/package.json  (ensure yaml and lucide-react are listed as dependencies)
```

**Key constraints:**
- `GET_PAGE_CUSTOM_FIELDS` GraphQL query MUST be defined locally in `lib/blocks/data-loaders.ts` (NOT in `lib/graphql/queries.ts`)
- `loadPageConfig` uses `fs.readFileSync` — only works in Node.js runtime (NOT Edge runtime) — ensure pages use default Node.js runtime
- `resolveBlock('unknown-type')` MUST return `null` AND call `console.warn('Unknown block type: unknown-type')`
- `HeroBlock` MUST have fallback values for all 5 WP Custom Fields (WP may have no data set)
- `UspBarBlock` is inline/synchronous — no Suspense wrapper needed
- `app/page.tsx` MUST have `export const revalidate = 60`
- WordPress Plugin registration: `register_post_meta()` + `register_graphql_field()` for all 6 fields; `hero_cta_link` and `hero_background_image` use `esc_url_raw` (not `sanitize_text_field`)

**Test command:**
```bash
cd frontend && pnpm test tests/slices/frontend-theming/slice-04-block-registry-page-renderer.test.ts
```

**Acceptance command:**
```bash
cd frontend && pnpm build
```

**Pass criteria:** All tests green, `pnpm build` exits with code 0.

---

### Slice 05: Theme Config + Multi-Shop

**Spec:** `specs/phase-0/2026-02-21-frontend-theming/slices/slice-05-theme-config-multi-shop.md`

**Deliverables to create/modify:**

```
REPLACE: frontend/scripts/generate-theme.mjs  (adds deepMerge() — replaces Slice 01 version entirely)
NEW:   frontend/themes/zweiter-shop/theme.yaml
NEW:   frontend/themes/zweiter-shop/pages/home.yaml
NEW:   frontend/public/themes/default/assets/logo.svg
NEW:   frontend/public/themes/default/assets/favicon.ico
NEW:   frontend/public/themes/zweiter-shop/assets/logo.svg
NEW:   frontend/public/themes/zweiter-shop/assets/favicon.ico
NEW:   frontend/lib/theme/logo.ts
NEW:   frontend/tests/slices/frontend-theming/slice-05-theme-config-multi-shop.test.ts
MODIFY or CREATE: frontend/.env.local.example  (add NEXT_PUBLIC_THEME=default)
```

**Key constraints:**
- `generate-theme.mjs` is FULLY REPLACED (not patched) — the new version includes `deepMerge()` as well as the unchanged `validateConfig()` and `generateCSS()` from Slice 01
- `deepMerge()` must handle null shop config gracefully (yaml.parse returns null for comment-only YAML)
- Assets are in `public/themes/` (NOT in `themes/`) — Next.js only serves from `public/`
- `getLogoPath()` and `getFaviconPath()` in `lib/theme/logo.ts` use `fs.existsSync` — NOT usable in `'use client'` components
- Integration of logo/favicon into `app/layout.tsx` is OUT OF SCOPE for this slice (explicitly deferred)
- Page Configs are NOT merged — `themes/zweiter-shop/pages/home.yaml` fully replaces default for that shop
- `themes/zweiter-shop/theme.yaml` contains ONLY overrides (3-4 colors + 1 font) — proves minimal config works

**Test command:**
```bash
cd frontend && pnpm test tests/slices/frontend-theming/slice-05-theme-config-multi-shop.test.ts
```

**Acceptance command:**
```bash
cd frontend && NEXT_PUBLIC_THEME=zweiter-shop node scripts/generate-theme.mjs && cat app/generated-theme.css
```

**Pass criteria:** All tests green. `generated-theme.css` contains shop-specific primary color AND default values for unoverridden tokens.

---

## Post-Slice Validation

For each completed slice, execute in order:

```yaml
validation_steps:
  - step: "Deliverables Check"
    action: "Verify all files listed in DELIVERABLES_START block of slice spec exist (or are deleted for DELETE entries)"

  - step: "Unit Tests"
    action: "Run the slice-specific test command from Test-Strategy section"
    pass_condition: "All tests green (exit code 0)"

  - step: "Integration Tests (after Slice 03+)"
    action: "cd frontend && pnpm test tests/slices/frontend-theming/"
    pass_condition: "All theming slice tests green"

  - step: "Acceptance Check"
    action: "Run the Acceptance Command from slice spec"
    pass_condition: "Defined in each slice spec"

  - step: "Regression Check (mandatory after Slice 03)"
    action: "cd frontend && pnpm test"
    pass_condition: "151 existing pod-shop-mvp tests + all new theming tests green"
```

---

## E2E Validation

AFTER all 5 slices completed:

```yaml
e2e_validation:
  - step: "Execute e2e-checklist.md — all items"
    file: "specs/phase-0/2026-02-21-frontend-theming/e2e-checklist.md"

  - step: "Run complete test suite"
    command: "cd frontend && pnpm test"
    pass_condition: "ALL tests green"

  - step: "Build Default Theme"
    command: "cd frontend && pnpm build"
    pass_condition: "Exit code 0, no TypeScript errors"

  - step: "Build Second Shop Theme"
    command: "cd frontend && NEXT_PUBLIC_THEME=zweiter-shop pnpm build"
    pass_condition: "Exit code 0, different CSS output"

  - step: "No Hardcoded Colors Verification"
    command: "grep -r 'bg-red-\\|bg-green-\\|bg-black/\\|bg-white\\|bg-purple-\\|bg-orange-\\|bg-emerald-\\|text-red-\\|border-red-\\|ring-red-\\|ring-green-\\|\\bshadow-sm\\b\\|\\bshadow-md\\b\\|\\bshadow-lg\\b\\|\\bshadow-xl\\b' frontend/components frontend/app"
    pass_condition: "Zero results"

  - step: "No Runtime Theme Remnants"
    command: "grep -r 'ThemeProvider\\|ThemeSwitcher\\|useTheme\\|theme-context\\|pod-theme\\|data-theme' frontend/app frontend/components"
    pass_condition: "Zero results"

  - step: "FOR each failing check"
    actions:
      - "Identify responsible slice from Integration Map (integration-map.md → Connections)"
      - "Create fix task with slice reference and specific AC that failed"
      - "Re-run affected slice tests after fix"

  - step: "Final Approval"
    condition: "ALL checks in e2e-checklist.md PASS + pnpm test green + pnpm build green"
    output: "Feature READY for merge"
```

---

## Rollback Strategy

```yaml
rollback:
  - condition: "Slice 01 fails"
    action: "Revert all Slice 01 changes (scripts/, themes/default/theme.yaml, globals.css modifications, lib/utils.ts, package.json changes)"
    note: "No downstream slices affected — foundation not yet built"
    git: "git revert or git reset --hard to pre-Slice-01 state"

  - condition: "Slice 02 fails"
    action: "Revert components/ui/, lib/theme/fonts.ts, layout.tsx font changes, globals.css tw-animate-css import"
    note: "Slice 01 changes are stable and remain"
    git: "Revert Slice 02 commits only"

  - condition: "Slice 03 fails (Big Bang)"
    action: "Revert all component modifications; restore theme-switcher.tsx, theme-context.tsx, themes.ts from git history"
    note: "Highest-risk slice — 21 files modified/deleted. Consider creating a pre-Slice-03 branch tag."
    git: "git revert Slice 03 commits; restore deleted files from git history"

  - condition: "Slice 04 fails"
    action: "Revert lib/blocks/, components/blocks/, themes/default/pages/, app/page.tsx modifications; remove WP plugin"
    note: "Slice 01-03 remain stable"
    git: "Revert Slice 04 commits"

  - condition: "Slice 05 fails"
    action: "Revert generate-theme.mjs to Slice 01 version; remove themes/zweiter-shop/, public/themes/, lib/theme/logo.ts"
    note: "Slice 01-04 remain stable; system continues to work with single-theme mode"
    git: "Revert Slice 05 commits"

  - condition: "Integration test fails after all slices"
    action: "Review integration-map.md Connections table to identify which slice owns the failing integration point"
    note: "Create targeted fix task for specific slice — do not revert all slices"
```

---

## Monitoring

During implementation:

| Metric | Alert Threshold | Action |
|--------|-----------------|--------|
| Unit test failures per slice | Any | Stop — fix before proceeding to next slice |
| TypeScript compilation errors (`pnpm build`) | Any | Stop — fix in current slice |
| Regression failures in `tests/slices/pod-shop-mvp/` | Any (especially after Slice 03) | Stop — Slice 03 migration broke a business test |
| Hardcoded color grep results after Slice 03 | Any | Stop — migration incomplete |
| `ThemeProvider`/`data-theme` grep results after Slice 03 | Any | Stop — runtime theme cleanup incomplete |
| `generate-theme.mjs` execution time | > 5 seconds | Investigate — should be < 1 second for YAML parse + CSS write |
| `pnpm build` total time | > 120 seconds (2x baseline) | Investigate potential regression |
| Bundle size increase | > 50KB gzipped | Review shadcn/ui imports — check for unused component imports |

---

## Critical Ordering Notes

1. **Slice 01 must be complete before ANY other work starts.** The CSS token system is the foundation for all 7 primitives, all 18 migrated components, and all 4 block components.

2. **Slice 03 is the highest-risk slice.** It modifies 21 files simultaneously. Recommend creating a git tag `pre-slice-03` before starting. The acceptance command (`pnpm test` — all 151+ tests) is the definitive gate.

3. **Slice 05 replaces `generate-theme.mjs` entirely.** The Slice 01 version is a single-theme reader. The Slice 05 version adds `deepMerge()` and replaces the earlier version completely. Do not patch — replace.

4. **WordPress Plugin (Slice 04) must be activated in WordPress.** After creating `wordpress/plugins/wp-custom-fields/`, activate it via WP Admin or WP-CLI:
   ```bash
   docker compose exec wordpress wp plugin activate wp-custom-fields --allow-root
   ```

5. **`getLogoPath()`/`getFaviconPath()` integration into `app/layout.tsx` is deliberately out of scope** for Slice 05. The functions exist and are tested, but wiring them into the Header component is a follow-up task after this feature lands.
