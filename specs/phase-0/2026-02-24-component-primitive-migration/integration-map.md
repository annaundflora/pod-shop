# Integration Map: Component Primitive Migration + Storybook (P0.3)

**Generated:** 2026-02-24
**Slices:** 2
**Connections:** 6

---

## Dependency Graph (Visual)

```
┌──────────────────────────────────────────────┐
│  Slice 01: Component Primitive Composition   │
│                                              │
│  Inputs: shadcn/ui Primitives (pre-existing) │
│  Outputs: Migrated Components + Card asChild │
└──────────────────────────────────────────────┘
                        │
                        │  (6 Resources)
                        │
                        ▼
┌──────────────────────────────────────────────┐
│  Slice 02: Storybook Setup + Stories         │
│                                              │
│  Inputs: All Slice-01 Outputs               │
│  Outputs: Storybook Config + 27 Stories     │
└──────────────────────────────────────────────┘
```

---

## Nodes

### Slice 01: Component Primitive Composition

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | None (shadcn/ui Primitives already exist in components/ui/) |
| Outputs | Migrated Domain Components, Card asChild Extension |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `Button` Component (6 Variants, 4 Sizes, isLoading, asChild) | Pre-existing: `frontend/components/ui/button.tsx` | Theming Slice 2 (approved) |
| `Card` Component (default, interactive, Compound Sub-components) | Pre-existing: `frontend/components/ui/card.tsx` | Theming Slice 2 (approved) |
| `Badge` Component (4 Variants) | Pre-existing: `frontend/components/ui/badge.tsx` | Theming Slice 2 (approved) |
| `cn()` Utility | Pre-existing: `frontend/lib/utils.ts` | Theming Slice 2 (approved) |

**Outputs:**

| Output | Type | File | Consumers |
|--------|------|------|-----------|
| `card.tsx` with asChild extension | React Component (modified) | `frontend/components/ui/card.tsx` | slice-02 (Stories) |
| `add-to-cart-button.tsx` migrated | React Component | `frontend/components/product/add-to-cart-button.tsx` | slice-02 (Stories) |
| `cart-summary.tsx` migrated | React Component | `frontend/components/cart/cart-summary.tsx` | slice-02 (Stories) |
| `quantity-stepper.tsx` migrated | React Component | `frontend/components/cart/quantity-stepper.tsx` | slice-02 (Stories) |
| `cart-item-row.tsx` migrated | React Component | `frontend/components/cart/cart-item-row.tsx` | slice-02 (Stories) |
| `variant-selector-size.tsx` migrated | React Component | `frontend/components/product/variant-selector-size.tsx` | slice-02 (Stories) |
| `mobile-menu.tsx` migrated | React Component | `frontend/components/layout/mobile-menu.tsx` | slice-02 (Stories) |
| `cart-page-client.tsx` migrated | React Component | `frontend/app/warenkorb/cart-page-client.tsx` | None (final page component) |
| `product-image-gallery.tsx` migrated | React Component | `frontend/components/product/product-image-gallery.tsx` | slice-02 (Stories) |
| `hero-block.tsx` migrated | React Component | `frontend/components/blocks/hero-block.tsx` | slice-02 (Stories) |
| `empty-cart.tsx` migrated | React Component | `frontend/components/cart/empty-cart.tsx` | slice-02 (Stories) |
| `filter-chips.tsx` migrated | React Component | `frontend/components/category/filter-chips.tsx` | slice-02 (Stories) |
| `product-card.tsx` migrated | React Component | `frontend/components/product/product-card.tsx` | slice-02 (Stories) |
| `category-showcase-block.tsx` migrated | React Component | `frontend/components/blocks/category-showcase-block.tsx` | slice-02 (Stories) |
| `produkt/[slug]/page.tsx` migrated | Next.js Page | `frontend/app/produkt/[slug]/page.tsx` | None (final page) |
| `cart-icon.tsx` migrated | React Component | `frontend/components/layout/cart-icon.tsx` | slice-02 (Stories) |
| Test file slice-01 | Vitest Test | `frontend/tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts` | None |
| `package.json` with pinned dependencies | Package Config | `frontend/package.json` | slice-02 (devDependencies) |

---

### Slice 02: Storybook Setup + Stories

| Field | Value |
|-------|-------|
| Status | APPROVED |
| Dependencies | slice-01-component-primitive-composition |
| Outputs | Storybook Config, 27 Story Files |

**Inputs:**

| Input | Source | Validation |
|-------|--------|------------|
| `Button` Component (all 6 Variants, 4 Sizes, isLoading, asChild) | slice-01 output (migrated + pre-existing) | APPROVED - consumed as `import { Button } from '@/components/ui/button'` |
| `Card`, `CardHeader`, `CardTitle`, `CardContent`, `CardFooter` | slice-01 output (card.tsx with asChild) | APPROVED - all Compound Components available |
| `Badge` (4 Variants) | slice-01 output (pre-existing + validated) | APPROVED |
| `Input`, `Skeleton`, `Dialog`, `Sheet` | Pre-existing `@/components/ui/` | APPROVED |
| Migrated Domain Components (AddToCartButton, CartItemRow, CartSummary, QuantityStepper, ProductCard, FilterChips) | slice-01 outputs | APPROVED |
| `CartContext` (as named export) | slice-02 self-deliverable: `frontend/contexts/cart-context.tsx` | New export added in this slice |
| `app/globals.css` | Pre-existing | Exists in repository |
| `app/generated-theme.css` | Pre-existing (generated by generate-theme.mjs) | Confirmed present in repo (gitStatus) |

**Outputs:**

| Output | Type | File | Consumers |
|--------|------|------|-----------|
| Storybook main config | Storybook Config | `frontend/.storybook/main.ts` | Storybook CLI (`pnpm storybook`) |
| Storybook preview config | Storybook Config | `frontend/.storybook/preview.ts` | Storybook Canvas |
| 27 Story files (ui/, layout/, product/, cart/, category/, blocks/) | Story Files (CSF 3) | `frontend/stories/**/*.stories.tsx` | Storybook Canvas, `pnpm build-storybook` |
| `package.json` with Storybook devDependencies + Scripts | Package Config | `frontend/package.json` | Developers |
| `CartContext` named export added | React Context | `frontend/contexts/cart-context.tsx` | Cart Stories (Decorator) |
| Test file slice-02 | Vitest Test | `frontend/tests/slices/component-primitive-migration/slice-02-storybook-setup-stories.test.ts` | None |

---

## Connections

| # | From | To | Resource | Type | Status |
|---|------|-----|----------|------|--------|
| 1 | slice-01 | slice-02 | `Button` Component (6 Variants + isLoading + asChild) | React Component | VALID |
| 2 | slice-01 | slice-02 | `Card` + Compound Components with asChild extension | React Component | VALID |
| 3 | slice-01 | slice-02 | `Badge` (4 Variants) | React Component | VALID |
| 4 | slice-01 | slice-02 | Migrated Domain Components (AddToCartButton, CartItemRow, CartSummary, QuantityStepper, ProductCard, FilterChips, HeroBlock, CartIcon, etc.) | React Components | VALID |
| 5 | slice-01 | slice-02 | `Input`, `Skeleton`, `Dialog`, `Sheet` (validated as present) | React Components | VALID |
| 6 | slice-02 (self) | slice-02 | `CartContext` named export added to `cart-context.tsx` | React Context Export | VALID (self-deliverable within slice-02) |

---

## Validation Results

### Valid Connections: 6

All declared dependencies have matching outputs. Every input required by Slice 02 is either delivered by Slice 01 or is a pre-existing resource confirmed to exist in the codebase.

### Orphaned Outputs: 0

All outputs from Slice 01 are consumed:

- Components consumed by Slice 02 Stories (14 component files)
- `cart-page-client.tsx` and `produkt/[slug]/page.tsx` are final user-facing pages (no story required — out of Storybook scope per architecture)
- `package.json` changes consumed by Slice 02 devDependency additions
- Test files are self-contained deliverables (no downstream consumer required)

Note: `cart-page-client.tsx` and `produkt/[slug]/page.tsx` have no stories — confirmed out-of-scope per architecture.md and discovery.md. These are production pages, not components that need isolation in Storybook.

### Missing Inputs: 0

No inputs are missing. All resources declared in Integration Contracts are accounted for:
- `generated-theme.css` is present (confirmed in gitStatus: `?? frontend/app/generated-theme.css`)
- All `components/ui/` primitives pre-exist from Theming Slice 2

### Deliverable-Consumer Gaps: 0

No consumer gaps found:
- Slice 01 delivers components; Slice 02 Stories import them directly (not via page modification)
- Storybook is a development tool — stories live in `frontend/stories/`, not in production pages
- The `CartContext` named export is added as a self-deliverable within Slice 02 (`frontend/contexts/cart-context.tsx`) — correctly scoped

---

## Discovery Traceability

### UI Components Coverage

#### Button Patterns

| Discovery Element | File | Target | Covered In | Status |
|-------------------|------|--------|------------|--------|
| Add-to-Cart Button | `product/add-to-cart-button.tsx` | `<Button isLoading>` | slice-01 | COVERED |
| Checkout Button | `cart/cart-summary.tsx` | `<Button variant="default">` | slice-01 | COVERED |
| Quantity Minus | `cart/quantity-stepper.tsx` | `<Button variant="outline" size="icon">` | slice-01 | COVERED |
| Quantity Plus | `cart/quantity-stepper.tsx` | `<Button variant="outline" size="icon">` | slice-01 | COVERED |
| Remove Item | `cart/cart-item-row.tsx` | `<Button variant="ghost" size="icon">` | slice-01 | COVERED |
| Size Selector | `product/variant-selector-size.tsx` | `<Button variant="default/outline">` | slice-01 | COVERED |
| Color Selector | `product/variant-selector-color.tsx` | OUT OF SCOPE | N/A | COVERED (explicit out-of-scope) |
| Hamburger Menu | `layout/mobile-menu.tsx` | `<Button variant="ghost" size="icon">` | slice-01 | COVERED |
| Error Close | `warenkorb/cart-page-client.tsx` | `<Button variant="ghost" size="icon">` | slice-01 | COVERED |
| Gallery Thumbnails | `product/product-image-gallery.tsx` | `<Button variant="ghost" size="icon">` | slice-01 | COVERED |

#### Button-as-Link Patterns

| Discovery Element | File | Target | Covered In | Status |
|-------------------|------|--------|------------|--------|
| Hero CTA | `blocks/hero-block.tsx` | `<Button asChild><Link>` | slice-01 | COVERED |
| Empty Cart CTA | `cart/empty-cart.tsx` | `<Button asChild><Link>` | slice-01 | COVERED |
| Filter Chip (active) | `category/filter-chips.tsx` | `<Button asChild><Link>` | slice-01 | COVERED |
| Filter Chip (inactive) | `category/filter-chips.tsx` | `<Button variant="secondary" asChild><Link>` | slice-01 | COVERED |

#### Card Composition Patterns

| Discovery Element | File | Target | Covered In | Status |
|-------------------|------|--------|------------|--------|
| Product Card | `product/product-card.tsx` | `<Card variant="interactive" asChild>` | slice-01 | COVERED |
| Cart Item Row (Card) | `cart/cart-item-row.tsx` | `<Card>` + `<CardContent>` | slice-01 | COVERED |
| Cart Summary (Card) | `cart/cart-summary.tsx` | `<Card>` + `<CardContent>` + `<CardFooter>` | slice-01 | COVERED |
| Category Showcase Item | `blocks/category-showcase-block.tsx` | `<Card variant="interactive" asChild>` | slice-01 | COVERED |
| Produkt-Beschreibung | `produkt/[slug]/page.tsx` | `<Card>` + `<CardHeader>` + `<CardContent>` | slice-01 | COVERED |

#### Badge Pattern

| Discovery Element | File | Target | Covered In | Status |
|-------------------|------|--------|------------|--------|
| Cart Counter | `layout/cart-icon.tsx` | `<Badge variant="default">` | slice-01 | COVERED |

#### Storybook Coverage (27 Stories)

| Category | Stories | Covered In | Status |
|----------|---------|------------|--------|
| UI Primitives (7) | Button, Card, Input, Badge, Skeleton, Dialog, Sheet | slice-02 | COVERED |
| Layout (5) | Header, Footer, MobileMenu, CartIcon, CookieConsentBanner | slice-02 | COVERED |
| Product (6) | ProductCard, ProductCardSkeleton, AddToCartButton, ImageGallery, VariantSelectorSize, VariantSelectorColor | slice-02 | COVERED |
| Cart (4) | CartItemRow, CartSummary, QuantityStepper, EmptyCart | slice-02 | COVERED |
| Category (1) | FilterChips | slice-02 | COVERED |
| Blocks (4) | HeroBlock, ProductGridBlock, CategoryShowcaseBlock, UspBarBlock | slice-02 | COVERED |

### State Machine Coverage

| State | Required UI | Available Actions | Covered In | Status |
|-------|-------------|-------------------|------------|--------|
| `pre_migration` | All custom patterns identified | Start Migration | slice-01 (Kontext section) | COVERED |
| `migrating` | 15 patterns migrated one by one | Migrate next pattern | slice-01 (Migration Map) | COVERED |
| `tests_adapting` | Existing tests updated (data-slot selectors) | Fix next test | slice-01 (Deliverables: test adaptation) | COVERED |
| `tests_writing` | New Vitest tests written (1 per pattern) | Write next test | slice-01 (Test spec + Deliverables) | COVERED |
| `accessibility_audit` | ARIA Labels verified per component | Fix next issue | slice-01 (Accessibility-Audit table) | COVERED |
| `validation` | All tests green, visual check | Approve | slice-01 (AC-13, AC-14, AC-15) | COVERED |
| `complete` | Migration finished | -- | slice-01 + slice-02 | COVERED |

### Transitions Coverage

| From | Trigger | To | Covered In | Status |
|------|---------|-----|------------|--------|
| `pre_migration` | Developer starts | `migrating` | slice-01 (Kontext) | COVERED |
| `migrating` | All 15 patterns migrated | `tests_adapting` | slice-01 (Migration-Reihenfolge) | COVERED |
| `tests_adapting` | All existing tests pass | `tests_writing` | slice-01 (Deliverables) | COVERED |
| `tests_writing` | New tests written | `accessibility_audit` | slice-01 (Test-Datei) | COVERED |
| `accessibility_audit` | All ARIA issues fixed | `validation` | slice-01 (Accessibility-Audit) | COVERED |
| `validation` | Visual identity confirmed + tests green | `complete` | slice-01 (AC-13/14/15) + slice-02 (DoD) | COVERED |

### Business Rules Coverage

| Rule | Covered In | Status |
|------|------------|--------|
| Visuell identisch nach Migration | slice-01 (AC-14, "Prüfung nach Migration" + visual check) | COVERED |
| Button Touch Targets >= 44px | slice-01 (Accessibility-Audit, QuantityStepper 32px exception documented) | COVERED |
| asChild Pattern for Links | slice-01 (4 Button-as-Link patterns, all use asChild) | COVERED |
| Keine neuen Variants | slice-01 (Constraint: "Keine neuen Variants") | COVERED |
| Gallery-Dots Ausnahme | slice-01 (Out-of-scope, explicitly documented) | COVERED |
| Footer-Links Ausnahme | slice-01 (Out-of-scope, explicitly documented) | COVERED |
| Test-Stabilitat: 151+ Tests grueen | slice-01 (AC-13) + slice-02 (DoD) | COVERED |
| Accessibility-Mindeststandard | slice-01 (Accessibility-Audit table per component) | COVERED |
| Storybook Stories (min. 1 Default) | slice-02 (27 Story files, all with Default + Named exports) | COVERED |
| Storybook Theme Tokens (no hardcoded colors) | slice-02 (Skill Verification + preview.ts CSS imports) | COVERED |
| Storybook Mock Data (no GraphQL) | slice-02 (CartContext Decorator pattern) | COVERED |
| Storybook Story Titles: {Category}/{Component} | slice-02 (Story-Titel-Konvention table) | COVERED |

### Data Fields Coverage

| Field | Description | Covered In | Status |
|-------|-------------|------------|--------|
| No new data model | Pure component refactoring | slice-01 (confirmed: "Kein neues Datenmodell") | COVERED |
| `data-slot="button"` attribute | Radix UI output attribute used in tests | slice-01 (Integration Contract, test adaption) | COVERED |
| `data-slot="card"` / `"card-content"` etc. | Radix UI output attributes | slice-01 (Integration Contract) | COVERED |
| `CartContextValue` fields (cartItems, subtotal, etc.) | Mock data in Storybook stories | slice-02 (CartSummary.stories.tsx code example) | COVERED |

**Discovery Coverage: 100% (all in-scope items covered)**

---

## Summary

| Metric | Value |
|--------|-------|
| Total Slices | 2 |
| Total Connections | 6 |
| Valid Connections | 6 |
| Orphaned Outputs | 0 |
| Missing Inputs | 0 |
| Deliverable-Consumer Gaps | 0 |
| Discovery Elements Covered | 100% |

---

VERDICT: READY FOR ORCHESTRATION
