# E2E Checklist: Component Primitive Migration + Storybook (P0.3)

**Integration Map:** `integration-map.md`
**Generated:** 2026-02-24

---

## Pre-Conditions

- [ ] Slice 01 APPROVED (Gate 2) — compliance-slice-01.md Verdict: APPROVED
- [ ] Slice 02 APPROVED (Gate 2) — compliance-slice-02.md Verdict: APPROVED
- [ ] Architecture APPROVED (Gate 1) — architecture.md reviewed
- [ ] Integration Map has no MISSING INPUTS (confirmed: 0)
- [ ] `frontend/app/generated-theme.css` exists in repository (confirmed)

---

## Flow 1: Component Migration Verification

Verifies that all 15 patterns have been migrated to shadcn/ui Primitives and the output is visually identical.

### Button Migration (11 Patterns)

1. - [ ] **Slice 01:** `add-to-cart-button.tsx` — run `pnpm test tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts` — verify `data-slot="button"` present, `isLoading` spinner works
2. - [ ] **Slice 01:** `cart-summary.tsx` Checkout Button — verify `data-slot="button"`, `aria-label="Zur Kasse"` present
3. - [ ] **Slice 01:** `quantity-stepper.tsx` Minus Button — verify `data-slot="button"`, `variant="outline"`, `size="icon"`, `aria-label="Menge verringern"`
4. - [ ] **Slice 01:** `quantity-stepper.tsx` Plus Button — verify `data-slot="button"`, `variant="outline"`, `size="icon"`, `aria-label="Menge erhoehen"`
5. - [ ] **Slice 01:** `cart-item-row.tsx` Remove Button — verify `data-slot="button"`, `variant="ghost"`, `size="icon"`
6. - [ ] **Slice 01:** `variant-selector-size.tsx` — verify `variant="default"` (selected), `variant="outline"` (unselected), `aria-pressed` present
7. - [ ] **Slice 01:** `mobile-menu.tsx` Hamburger — verify `data-slot="button"`, `variant="ghost"`, `size="icon"`, `aria-label="Menue oeffnen"`
8. - [ ] **Slice 01:** `cart-page-client.tsx` Error-Close — verify `data-slot="button"`, `variant="ghost"`, `size="icon"`, `className` includes `text-error`
9. - [ ] **Slice 01:** `product-image-gallery.tsx` Thumbnails — verify `data-slot="button"`, `variant="ghost"`, `size="icon"`, custom className for 72px size
10. - [ ] **Slice 01:** Gallery Dots — verify Dot-Buttons remain native `<button>` (NOT migrated — documented out-of-scope exception)

### Button-as-Link Migration (4 Patterns)

11. - [ ] **Slice 01:** `hero-block.tsx` CTA — verify `<Button asChild>` renders as `<a>` tag (not `<button>`) via `asChild` pattern
12. - [ ] **Slice 01:** `empty-cart.tsx` CTA — verify `<Button asChild><Link>`, `data-slot="button"`, no inline `touchAction` style
13. - [ ] **Slice 01:** `filter-chips.tsx` active chip — verify `<Button asChild>`, `variant="default"`, `aria-current="page"`, `rounded-full` class
14. - [ ] **Slice 01:** `filter-chips.tsx` inactive chip — verify `<Button variant="secondary" asChild>`, `rounded-full` class

### Card Composition Migration (5 Patterns)

15. - [ ] **Slice 01:** `product-card.tsx` — verify `data-slot="card"`, `variant="interactive"`, renders as `<a>` via `asChild`, `data-slot="card-content"` present
16. - [ ] **Slice 01:** `cart-item-row.tsx` outer wrapper — verify `data-slot="card"` (not native `<div>` with `border border-border`)
17. - [ ] **Slice 01:** `cart-summary.tsx` wrapper — verify `data-slot="card"`, `data-slot="card-content"`, `data-slot="card-footer"` present
18. - [ ] **Slice 01:** `category-showcase-block.tsx` — verify `data-slot="card"`, `variant="interactive"`, renders as `<a>` via `asChild`
19. - [ ] **Slice 01:** `produkt/[slug]/page.tsx` description section — verify `data-slot="card"`, `data-slot="card-header"`, `data-slot="card-content"` present (no `<section>` with inline styles)

### Badge Migration (1 Pattern)

20. - [ ] **Slice 01:** `cart-icon.tsx` counter — verify `data-slot="badge"`, `variant="default"`, visible only when `itemCount > 0`

---

## Flow 2: Test Stability Verification

Verifies that all pre-existing tests remain green after migration.

1. - [ ] **Slice 01:** Run `pnpm test` (all tests) — verify all 151+ pre-existing tests pass
2. - [ ] **Slice 01:** Run `pnpm test tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts` — all new slice-specific tests pass
3. - [ ] **Slice 01:** Run `pnpm build` — TypeScript build succeeds without errors
4. - [ ] **Slice 01:** Grep verification: `grep -rn '<button' frontend/components frontend/app` — only Gallery Dots and Color Swatches remain as custom `<button>` elements

---

## Flow 3: Storybook Setup Verification

Verifies that Storybook is correctly configured and starts without errors.

1. - [ ] **Slice 02:** Run `pnpm test tests/slices/component-primitive-migration/slice-02-storybook-setup-stories.test.ts` — all configuration + story existence checks pass
2. - [ ] **Slice 02:** Verify `frontend/.storybook/main.ts` exists and contains `@storybook/nextjs-vite`
3. - [ ] **Slice 02:** Verify `frontend/.storybook/preview.ts` exists and imports `globals.css` + `generated-theme.css`
4. - [ ] **Slice 02:** Verify `frontend/package.json` has `storybook ^10.2.0`, `@storybook/nextjs-vite ^10.2.0`, `@storybook/addon-essentials ^10.2.0`, `@storybook/react ^10.2.0` in devDependencies
5. - [ ] **Slice 02:** Verify `package.json` scripts contain `"storybook": "storybook dev -p 6006"` and `"build-storybook": "storybook build"`
6. - [ ] **Slice 02:** Run `pnpm storybook` (manual) — Storybook 10.2.x starts on Port 6006 within 30 seconds without errors
7. - [ ] **Slice 02:** Run `pnpm build-storybook` (manual) — static build generates in `storybook-static/` without TypeScript or build errors

---

## Flow 4: Storybook Story Content Verification

Verifies that all 27 story files exist and render correctly.

### UI Primitives Stories (7)

1. - [ ] **Slice 02:** `frontend/stories/ui/Button.stories.tsx` — 6 variant stories visible (Default, Destructive, Outline, Ghost, Link, Secondary), all 4 sizes selectable via Controls
2. - [ ] **Slice 02:** `frontend/stories/ui/Card.stories.tsx` — Default variant + Interactive variant visible, Compound Components (CardHeader, CardTitle, CardContent, CardFooter) shown
3. - [ ] **Slice 02:** `frontend/stories/ui/Input.stories.tsx` — Default + Error variant (aria-invalid) visible
4. - [ ] **Slice 02:** `frontend/stories/ui/Badge.stories.tsx` — all 4 variants (Default, Secondary, Destructive, Outline) visible
5. - [ ] **Slice 02:** `frontend/stories/ui/Skeleton.stories.tsx` — Text Skeleton + Card Skeleton stories visible
6. - [ ] **Slice 02:** `frontend/stories/ui/Dialog.stories.tsx` — Open state + Closed state stories visible
7. - [ ] **Slice 02:** `frontend/stories/ui/Sheet.stories.tsx` — 4 side stories (Top, Right, Bottom, Left) visible

### Layout Stories (5)

8. - [ ] **Slice 02:** `frontend/stories/layout/Header.stories.tsx` — Default story with Logo + Navigation + CartIcon
9. - [ ] **Slice 02:** `frontend/stories/layout/Footer.stories.tsx` — Default story with links + copyright
10. - [ ] **Slice 02:** `frontend/stories/layout/MobileMenu.stories.tsx` — Closed + Opened (Sheet integration) stories
11. - [ ] **Slice 02:** `frontend/stories/layout/CartIcon.stories.tsx` — WithoutBadge (itemCount=0) + WithBadge (itemCount=3) stories; Badge uses `<Badge>` primitive from Slice 01
12. - [ ] **Slice 02:** `frontend/stories/layout/CookieConsentBanner.stories.tsx` — Default story with `<Button>` primitives visible

### Product Stories (6)

13. - [ ] **Slice 02:** `frontend/stories/product/ProductCard.stories.tsx` — Default story with mock data; Card `variant="interactive"` visible
14. - [ ] **Slice 02:** `frontend/stories/product/ProductCardSkeleton.stories.tsx` — Default story with Skeleton primitive
15. - [ ] **Slice 02:** `frontend/stories/product/AddToCartButton.stories.tsx` — Default + Loading (isLoading) + Success (className) + Disabled + VariantWaehlen stories
16. - [ ] **Slice 02:** `frontend/stories/product/ImageGallery.stories.tsx` — Default story with mock images; Thumbnails use `<Button>` primitive
17. - [ ] **Slice 02:** `frontend/stories/product/VariantSelectorSize.stories.tsx` — Selected + Unselected + Unavailable stories; `variant="default"` vs `variant="outline"` visible
18. - [ ] **Slice 02:** `frontend/stories/product/VariantSelectorColor.stories.tsx` — Default story with mock colors; Selected (ring) + Unavailable (strikethrough) states

### Cart Stories (4 — all with CartContext Decorator)

19. - [ ] **Slice 02:** `frontend/stories/cart/CartItemRow.stories.tsx` — Default story with mock product; Card + `<Button variant="ghost" size="icon">` Remove-Button visible; CartContext Decorator present; no "useCart must be used within CartProvider" error
20. - [ ] **Slice 02:** `frontend/stories/cart/CartSummary.stories.tsx` — WithItems + Empty + Loading stories; CartContext Decorator; `<Card>` + `<Button>` from Slice 01 visible; no CartProvider error
21. - [ ] **Slice 02:** `frontend/stories/cart/QuantityStepper.stories.tsx` — Default (qty=1) + Disabled + MaxQuantity stories; `<Button variant="outline" size="icon">` visible; CartContext Decorator
22. - [ ] **Slice 02:** `frontend/stories/cart/EmptyCart.stories.tsx` — Default story with `<Button asChild><Link>` CTA; CartContext Decorator

### Category Stories (1)

23. - [ ] **Slice 02:** `frontend/stories/category/FilterChips.stories.tsx` — Active (variant="default") + Inactive (variant="secondary") + Mixed stories

### Block Stories (4)

24. - [ ] **Slice 02:** `frontend/stories/blocks/HeroBlock.stories.tsx` — Loaded + WithBackgroundImage + Loading (HeroBlockSkeleton) stories
25. - [ ] **Slice 02:** `frontend/stories/blocks/ProductGridBlock.stories.tsx` — Loaded (mock products) + Loading + Empty stories
26. - [ ] **Slice 02:** `frontend/stories/blocks/CategoryShowcaseBlock.stories.tsx` — Loaded (mock categories, Card variant="interactive") + Loading stories
27. - [ ] **Slice 02:** `frontend/stories/blocks/UspBarBlock.stories.tsx` — Default story with mock USPs

---

## Flow 5: Theme Token Verification (Manual)

Verifies that Tailwind theme tokens from `generated-theme.css` are correctly applied in Storybook.

1. - [ ] **Slice 02:** Open `UI Primitives/Button` story in Storybook — `bg-primary` color matches theme primary color from `generated-theme.css`
2. - [ ] **Slice 02:** Open any story with `text-text-primary` — text color matches theme token
3. - [ ] **Slice 02:** Open `Product/Add to Cart Button` Loading story — spinner is visible (`isLoading={true}` from shadcn/ui Button primitive)
4. - [ ] **Slice 02:** Open `Cart/Cart Icon` WithBadge story — Badge renders with correct `bg-primary` color from theme

---

## Edge Cases

### Error Handling

- [ ] Cart component stories without CartContext Decorator — verify no "useCart must be used within CartProvider" error in any Cart story (AC-4 of Slice 02)
- [ ] `Button asChild` with nested `<Link>` — verify rendered HTML is `<a>` not `<button>` (semantic correctness)
- [ ] Storybook starts when `generated-theme.css` exists — verify no import error in `preview.ts`

### State Transitions

- [ ] `AddToCartButton`: default → loading (isLoading prop) → success (className change) — all 3 states have separate stories or Controls
- [ ] `CartIcon`: itemCount=0 (no Badge) → itemCount=3 (Badge visible) — conditional rendering verified
- [ ] `VariantSelectorSize`: unselected (variant="outline") → selected (variant="default") — variant switch verified

### Boundary Conditions

- [ ] `CartSummary` Empty story — itemCount=0, cartItems=[], subtotal="0,00 €" renders without crash
- [ ] `Button` with `isLoading={true}` and `disabled={true}` simultaneously — no double-render issues
- [ ] `Card asChild` with `<Link>` as single child — verify Radix Slot renders correctly (1 child constraint)

---

## Cross-Slice Integration Points

| # | Integration Point | Slices | How to Verify |
|---|-------------------|--------|---------------|
| 1 | Migrated Components consumed by Stories | slice-01 → slice-02 | Open each Story in Storybook — Component renders with Primitive internals (`data-slot` attributes visible in DOM inspector) |
| 2 | `Card asChild` used in ProductCard and CategoryShowcase Stories | slice-01 → slice-02 | ProductCard story renders as `<a>` in DOM (not `<div>`); CategoryShowcase story identical |
| 3 | `CartContext` named export used by Cart Story Decorators | slice-02 → slice-02 (self) | Cart Stories render without CartProvider error; `CartContext.Provider` wraps each Cart story |
| 4 | `generated-theme.css` imported in `preview.ts` — applies theme tokens | pre-existing → slice-02 | Theme-colored elements (bg-primary buttons) match the pod-shop default theme colors |
| 5 | `package.json` Storybook scripts | slice-02 | `pnpm storybook` and `pnpm build-storybook` commands execute successfully |

---

## Acceptance Test Summary

| Test | Command / Method | Pass Condition |
|------|-----------------|----------------|
| All Vitest tests green | `cd frontend && pnpm test` | 0 failures, 151+ tests pass |
| Slice-01 specific tests | `pnpm test tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts` | All tests pass |
| Slice-02 specific tests | `pnpm test tests/slices/component-primitive-migration/slice-02-storybook-setup-stories.test.ts` | All tests pass (config + 27 story existence checks) |
| Production build | `cd frontend && pnpm build` | Build exits 0, no TypeScript errors |
| Storybook dev | `cd frontend && pnpm storybook` (manual) | Starts on port 6006 within 30s |
| Storybook static | `cd frontend && pnpm build-storybook` (manual) | Generates `storybook-static/` without errors |

---

## Sign-Off

| Tester | Date | Result |
|--------|------|--------|
| [Implementer] | [Date] | PASS / FAIL |
| [Reviewer] | [Date] | PASS / FAIL |

**Notes:**
[Any observations or issues found during implementation]
