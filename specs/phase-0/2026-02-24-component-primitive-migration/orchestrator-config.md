# Orchestrator Configuration: Component Primitive Migration + Storybook (P0.3)

**Integration Map:** `integration-map.md`
**E2E Checklist:** `e2e-checklist.md`
**Generated:** 2026-02-24

---

## Pre-Implementation Gates

```yaml
pre_checks:
  - name: "Gate 1: Architecture Compliance"
    file: "specs/phase-0/2026-02-24-component-primitive-migration/architecture.md"
    required: "Reviewed and consistent with slice specs"
    status: "PASSED"

  - name: "Gate 2: All Slices Approved"
    files:
      - "specs/phase-0/2026-02-24-component-primitive-migration/slices/compliance-slice-01.md"
      - "specs/phase-0/2026-02-24-component-primitive-migration/slices/compliance-slice-02.md"
    required: "ALL Verdict == APPROVED"
    status: "PASSED (compliance-slice-01: APPROVED, compliance-slice-02: APPROVED)"

  - name: "Gate 3: Integration Map Valid"
    file: "specs/phase-0/2026-02-24-component-primitive-migration/integration-map.md"
    required: "Missing Inputs == 0, Orphaned Outputs == 0, Deliverable-Consumer Gaps == 0"
    status: "PASSED"
```

---

## Implementation Order

Based on dependency analysis — Slice 01 must complete before Slice 02 begins. No parallel execution possible.

| Order | Slice | Name | Depends On | Parallel? |
|-------|-------|------|------------|-----------|
| 1 | slice-01 | Component Primitive Composition | None (shadcn/ui Primitives pre-exist) | No (foundation) |
| 2 | slice-02 | Storybook Setup + Stories | slice-01 (migrated components + CartContext) | No (depends on slice-01) |

### Slice 01 Internal Implementation Order

Per slice-01 spec (Migrations-Reihenfolge):

1. Dependency Pinning — `frontend/package.json` (pin 3 "latest" packages)
2. Card `asChild` Extension — `frontend/components/ui/card.tsx` (3-line change: Slot import + asChild prop + Comp pattern)
3. Button Patterns (11) — migrate in this order:
   - `add-to-cart-button.tsx`
   - `cart-summary.tsx` (Button + Card)
   - `quantity-stepper.tsx`
   - `cart-item-row.tsx` (Button + Card)
   - `variant-selector-size.tsx`
   - `mobile-menu.tsx`
   - `cart-page-client.tsx`
   - `product-image-gallery.tsx`
4. Button-as-Link Patterns (4):
   - `hero-block.tsx`
   - `empty-cart.tsx`
   - `filter-chips.tsx`
5. Card Composition Patterns (remaining):
   - `product-card.tsx`
   - `category-showcase-block.tsx`
   - `produkt/[slug]/page.tsx`
6. Badge Pattern:
   - `cart-icon.tsx`
7. Adapt existing tests (data-slot selectors, role selectors)
8. Write new Vitest test file for slice-01

### Slice 02 Internal Implementation Order

1. Install Storybook devDependencies + add scripts to `frontend/package.json`
2. Create `frontend/.storybook/main.ts`
3. Create `frontend/.storybook/preview.ts`
4. Add `CartContext` named export to `frontend/contexts/cart-context.tsx`
5. Create 27 story files in this order:
   - `frontend/stories/ui/` (7 files: Button, Card, Input, Badge, Skeleton, Dialog, Sheet)
   - `frontend/stories/layout/` (5 files: Header, Footer, MobileMenu, CartIcon, CookieConsentBanner)
   - `frontend/stories/product/` (6 files: ProductCard, ProductCardSkeleton, AddToCartButton, ImageGallery, VariantSelectorSize, VariantSelectorColor)
   - `frontend/stories/cart/` (4 files: CartItemRow, CartSummary, QuantityStepper, EmptyCart)
   - `frontend/stories/category/` (1 file: FilterChips)
   - `frontend/stories/blocks/` (4 files: HeroBlock, ProductGridBlock, CategoryShowcaseBlock, UspBarBlock)
6. Write Vitest test file for slice-02

---

## Post-Slice Validation

### After Slice 01 Completes

```yaml
validation_steps_slice_01:
  - step: "Deliverables Check"
    action: "Verify all files in DELIVERABLES_START section of slice-01 exist"
    files:
      - "frontend/package.json (3 packages pinned)"
      - "frontend/components/ui/card.tsx (asChild added)"
      - "frontend/components/product/add-to-cart-button.tsx"
      - "frontend/components/cart/cart-summary.tsx"
      - "frontend/components/cart/quantity-stepper.tsx"
      - "frontend/components/cart/cart-item-row.tsx"
      - "frontend/components/product/variant-selector-size.tsx"
      - "frontend/components/layout/mobile-menu.tsx"
      - "frontend/app/warenkorb/cart-page-client.tsx"
      - "frontend/components/product/product-image-gallery.tsx"
      - "frontend/components/blocks/hero-block.tsx"
      - "frontend/components/cart/empty-cart.tsx"
      - "frontend/components/category/filter-chips.tsx"
      - "frontend/components/product/product-card.tsx"
      - "frontend/components/blocks/category-showcase-block.tsx"
      - "frontend/app/produkt/[slug]/page.tsx"
      - "frontend/components/layout/cart-icon.tsx"
      - "frontend/tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts"

  - step: "Slice Unit Tests"
    command: "cd frontend && pnpm test tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts"
    required: "All tests pass"

  - step: "Full Test Suite"
    command: "cd frontend && pnpm test"
    required: "All 151+ existing tests still pass"

  - step: "Production Build"
    command: "cd frontend && pnpm build"
    required: "Build exits 0, no TypeScript errors"

  - step: "Migration Verification"
    action: "grep -rn '<button' frontend/components frontend/app"
    required: "Only Gallery Dots (product-image-gallery.tsx dots) and Color Swatches (variant-selector-color.tsx) remain as native <button>"

  - step: "Integration Points"
    action: "Verify slice-01 outputs accessible by slice-02"
    check: "All component imports in slice-02 stories resolve correctly"
    reference: "integration-map.md → Connections #1-5"
```

### After Slice 02 Completes

```yaml
validation_steps_slice_02:
  - step: "Deliverables Check"
    action: "Verify all files in DELIVERABLES_START section of slice-02 exist"
    files:
      - "frontend/.storybook/main.ts"
      - "frontend/.storybook/preview.ts"
      - "frontend/package.json (Storybook devDeps + scripts added)"
      - "frontend/stories/ui/Button.stories.tsx"
      - "frontend/stories/ui/Card.stories.tsx"
      - "frontend/stories/ui/Input.stories.tsx"
      - "frontend/stories/ui/Badge.stories.tsx"
      - "frontend/stories/ui/Skeleton.stories.tsx"
      - "frontend/stories/ui/Dialog.stories.tsx"
      - "frontend/stories/ui/Sheet.stories.tsx"
      - "frontend/stories/layout/Header.stories.tsx"
      - "frontend/stories/layout/Footer.stories.tsx"
      - "frontend/stories/layout/MobileMenu.stories.tsx"
      - "frontend/stories/layout/CartIcon.stories.tsx"
      - "frontend/stories/layout/CookieConsentBanner.stories.tsx"
      - "frontend/stories/product/ProductCard.stories.tsx"
      - "frontend/stories/product/ProductCardSkeleton.stories.tsx"
      - "frontend/stories/product/AddToCartButton.stories.tsx"
      - "frontend/stories/product/ImageGallery.stories.tsx"
      - "frontend/stories/product/VariantSelectorSize.stories.tsx"
      - "frontend/stories/product/VariantSelectorColor.stories.tsx"
      - "frontend/stories/cart/CartItemRow.stories.tsx"
      - "frontend/stories/cart/CartSummary.stories.tsx"
      - "frontend/stories/cart/QuantityStepper.stories.tsx"
      - "frontend/stories/cart/EmptyCart.stories.tsx"
      - "frontend/stories/category/FilterChips.stories.tsx"
      - "frontend/stories/blocks/HeroBlock.stories.tsx"
      - "frontend/stories/blocks/ProductGridBlock.stories.tsx"
      - "frontend/stories/blocks/CategoryShowcaseBlock.stories.tsx"
      - "frontend/stories/blocks/UspBarBlock.stories.tsx"
      - "frontend/contexts/cart-context.tsx (CartContext named export added)"
      - "frontend/tests/slices/component-primitive-migration/slice-02-storybook-setup-stories.test.ts"

  - step: "Slice Unit Tests"
    command: "cd frontend && pnpm test tests/slices/component-primitive-migration/slice-02-storybook-setup-stories.test.ts"
    required: "All tests pass (config files + 27 story existence checks + CartContext content checks)"

  - step: "Full Test Suite"
    command: "cd frontend && pnpm test"
    required: "All tests still pass after Storybook dependencies installed"

  - step: "Storybook Dev (Manual)"
    command: "cd frontend && pnpm storybook"
    required: "Starts on port 6006 within 30 seconds, no errors in terminal"

  - step: "Storybook Build (Manual)"
    command: "cd frontend && pnpm build-storybook"
    required: "Generates storybook-static/ without TypeScript errors"

  - step: "CartContext Decorator Verification (Manual)"
    action: "Open Cart/Cart Summary story in Storybook browser"
    required: "No 'useCart must be used within CartProvider' error"
```

---

## E2E Validation

AFTER all slices completed:

```yaml
e2e_validation:
  - step: "Execute e2e-checklist.md"
    file: "specs/phase-0/2026-02-24-component-primitive-migration/e2e-checklist.md"
    action: "Work through all 5 Flows systematically"

  - step: "Flow 1: Component Migration Verification"
    items: 20
    method: "Automated Vitest tests + manual DOM inspection"

  - step: "Flow 2: Test Stability Verification"
    items: 4
    method: "CLI commands (pnpm test, pnpm build, grep)"

  - step: "Flow 3: Storybook Setup Verification"
    items: 7
    method: "File existence checks + manual Storybook start"

  - step: "Flow 4: Story Content Verification"
    items: 27
    method: "Manual Storybook browser inspection per story"

  - step: "Flow 5: Theme Token Verification"
    items: 4
    method: "Manual visual inspection in Storybook browser"

  - step: "FOR each failing check"
    actions:
      - "Identify responsible slice from integration-map.md"
      - "Create fix task referencing the specific slice deliverable"
      - "Re-run affected slice tests after fix"
      - "Re-verify specific e2e-checklist item"

  - step: "Final Approval"
    condition: "ALL checks in e2e-checklist.md PASS"
    output: "Feature READY — Component Primitive Migration + Storybook complete"
```

---

## Critical Constraints for Implementer

The following constraints MUST be respected during implementation. Violations block the post-slice validation.

### Slice 01 Constraints

| Constraint | Detail | Verification |
|------------|--------|-------------|
| Visuell identisch | No visible UI changes after migration | Manual visual check of all pages (/, /kategorie/*, /produkt/*, /warenkorb) |
| Gallery Dots = Out of Scope | `product-image-gallery.tsx` dot buttons (10px, role="tab") remain native `<button>` | grep check |
| Color Selector = Out of Scope | `variant-selector-color.tsx` remains custom (dynamic hex background) | grep check |
| Footer Links = Out of Scope | `footer.tsx` links remain as `<a>`/`<Link>` | grep check |
| Card asChild: 1 child only | Radix Slot accepts exactly 1 child element | TypeScript + runtime check |
| No new Variants | Only use: default, destructive, outline, ghost, link, secondary | code review |
| QuantityStepper 32px exception | `className="w-8 h-8"` overrides default 44px — documented and accepted | AC-5 test |
| 151+ tests must stay green | Adapt test selectors to `data-slot` and `role` | `pnpm test` output |

### Slice 02 Constraints

| Constraint | Detail | Verification |
|------------|--------|-------------|
| Storybook Version: 10.2.x | NOT 8.x or 9.x — only 10.x has Day-One Next.js 16 support | `package.json` devDependency version |
| Framework: `@storybook/nextjs-vite` | NOT `@storybook/nextjs` (Webpack) — Vite is faster and recommended | `main.ts` framework field |
| Node.js 22.x | Storybook 10 is ESM-Only, requires Node.js 20.16+ — project uses 22.x (compatible) | verify with `node --version` |
| CartContext as named export | `export const CartContext = createContext<CartContextValue \| null>(null)` MUST be added to `frontend/contexts/cart-context.tsx` | TypeScript build + story render |
| No GraphQL mocking | Cart Stories use `CartContext.Provider` with static mock values — NOT `CartProvider` (which calls Apollo) | story code review |
| No hardcoded colors | Stories use only theme token classes (`bg-primary`, `text-text-primary`) — no inline hex values | code review |
| `generated-theme.css` imported | Both `globals.css` AND `generated-theme.css` imported in `preview.ts` | `preview.ts` file check |
| Story titles: `{Category}/{Name}` | e.g. `UI Primitives/Button`, `Cart/Cart Summary` — consistent with Storybook autodocs | Default export `title` field |
| Storybook in devDependencies only | All 4 Storybook packages in `devDependencies`, NOT `dependencies` | `package.json` check |

---

## Rollback Strategy

IF implementation fails:

```yaml
rollback:
  - condition: "Slice 01 fails (test failures or build errors)"
    action: "Revert all component changes in Slice 01 — pre-existing shadcn/ui primitives remain unaffected"
    scope:
      - "frontend/components/product/"
      - "frontend/components/cart/"
      - "frontend/components/layout/"
      - "frontend/components/blocks/"
      - "frontend/components/category/"
      - "frontend/components/ui/card.tsx (asChild addition)"
      - "frontend/app/warenkorb/cart-page-client.tsx"
      - "frontend/app/produkt/[slug]/page.tsx"
    note: "Production app continues to work with original custom patterns"

  - condition: "Slice 01 passes but Slice 02 fails (Storybook errors)"
    action: "Revert only Slice 02 changes"
    scope:
      - "frontend/.storybook/ (delete)"
      - "frontend/stories/ (delete)"
      - "frontend/package.json (remove Storybook devDeps + scripts)"
      - "frontend/contexts/cart-context.tsx (revert CartContext export)"
    note: "Component migration from Slice 01 remains — production app unaffected"

  - condition: "Integration test fails (story cannot import migrated component)"
    action: "Review integration-map.md → Connections for the specific component"
    note: "Check that slice-01 deliverable for the component exists and exports correct interface"
```

---

## Monitoring

During implementation:

| Metric | Alert Threshold | Action |
|--------|-----------------|--------|
| Test failures after each migration step | Any new failure | Fix before migrating next component |
| TypeScript errors | Any during `pnpm build` | Fix immediately — TypeScript is the source of truth |
| Missing deliverable file | Any file from DELIVERABLES_START missing | Do not proceed to next component |
| Storybook start time | > 30 seconds | Check Vite config or PostCSS issues |
| Story render errors | Any story fails to render | Fix CartContext Decorator or mock data |

---

## Test Commands Reference

```bash
# Working directory: frontend/

# Slice 01: run after implementing each component
pnpm test tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts

# Slice 02: run after all 27 stories created
pnpm test tests/slices/component-primitive-migration/slice-02-storybook-setup-stories.test.ts

# All slices integration
pnpm test tests/slices/component-primitive-migration/

# Full acceptance (must stay green throughout)
pnpm test

# Production build gate
pnpm build

# Storybook dev (manual — port 6006)
pnpm storybook

# Storybook static build
pnpm build-storybook

# Migration grep check
grep -rn '<button' frontend/components frontend/app --include="*.tsx"
```
