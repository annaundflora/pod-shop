# Slice 1: Component Primitive Composition

> **Slice 1 von 2** für `Component Primitive Migration + Storybook (P0.3)`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | — |
> | **Nächster:** | `slice-02-storybook-setup-stories.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-01-component-primitive-composition` |
| **Test** | `pnpm test tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `[]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier für Commits und Evidence
- **Test**: Vitest-Test — wird nach Implementierung vom Orchestrator ausgeführt
- **E2E**: false — kein Playwright-Test, reine Vitest Unit/Component Tests
- **Dependencies**: Keine — shadcn/ui Primitives sind bereits in `components/ui/` vorhanden (Ergebnis von Theming-Slice 2)

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf `frontend/package.json` → `next: "^16.1.6"`, `vitest: "^3.0.0"`, `@testing-library/react: "^16.3.2"`.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts` |
| **Integration Command** | `pnpm test tests/slices/component-primitive-migration/` |
| **Acceptance Command** | `pnpm test` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `no_mocks` |

**Erklaerung:**
- **Stack**: Next.js 16 + Vitest 3 + React Testing Library 16 (aus `frontend/package.json` detektiert)
- **Test Command**: Slice-spezifischer Test-File für den Orchestrator-Gate
- **Integration Command**: Alle Tests im feature-spezifischen Ordner
- **Acceptance Command**: Alle 151+ bestehenden Tests + neue Tests müssen grün sein
- **Mocking Strategy**: `no_mocks` — reine React-Component-Tests mit jsdom, keine externen Services involviert. CartContext via TestProviders gemockt.

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Component Primitive Composition | **Ready** | `slice-01-component-primitive-composition.md` |
| 2 | Storybook 10.2.x Setup + Stories | Pending | `slice-02-storybook-setup-stories.md` |

> **Hinweis zu Slice 2:** Storybook Version ist **10.2.x** (nicht 8.x). Architecture hat 8.x korrigiert — 8.x hat kein Next.js 16 Support. Details in `architecture.md` → Technology Decisions.

---

## Kontext & Ziel

**Problem:** 15 UI-Patterns in 13 Dateien implementieren eigene Button/Card/Badge-Logik statt die vorhandenen shadcn/ui Primitives zu verwenden. Duplizierte Focus-Rings, Hover-States und Disabled-States in jedem Custom-Component.

**Zielbild:** Alle 15 identifizierten Patterns nutzen shadcn/ui Primitives (`<Button>`, `<Card>`, `<Badge>`) als Bausteine. Composition Pattern: Domain-Components sind aus Primitives zusammengebaut. Accessibility-Audit pro Component. Alle 151+ bestehenden Tests bleiben grün + neue Tests pro migriertem Pattern.

**Aktuelle Probleme:**
1. `add-to-cart-button.tsx`: Eigener `<button>` mit manuellen Focus-Ring, Hover, Touch-Action-Styles — Button-Primitive hat bereits `isLoading` mit identischem Spinner
2. `cart-summary.tsx`: `<button>` mit `bg-primary rounded-lg` — exakt was `variant="default"` liefert; keine Card-Wrapper-Struktur
3. `quantity-stepper.tsx`: 2× `<button>` mit `w-8 h-8 border` — kleinere Variante als `size="icon"` (44px), braucht className-Override
4. `cart-item-row.tsx`: Outer `<div>` mit `border border-border rounded-lg` — identisch mit Card `variant="default"`
5. `variant-selector-size.tsx`: Toggle-Pattern mit className-Array — mappt auf `variant="default"` (selected) / `variant="outline"` (unselected)
6. `mobile-menu.tsx`: Custom `<button>` als SheetTrigger — kann direkt `<Button variant="ghost" size="icon">` nutzen
7. `cart-page-client.tsx`: Error-Close `<button>` mit `text-error` — `<Button variant="ghost" size="icon">` + className
8. `product-image-gallery.tsx`: Thumbnail-`<button>` — `<Button variant="ghost" size="icon">` + className-Override für 72px
9. `hero-block.tsx`: `<Link>` mit Button-Styles — klassisches `<Button asChild><Link>` Pattern
10. `empty-cart.tsx`: `<Link>` mit `bg-primary rounded-lg` — `<Button asChild><Link>` Pattern
11. `filter-chips.tsx`: 2× `<Link>` mit conditional className-Array — `<Button asChild>` mit Variant-Switch + `rounded-full` Override
12. `product-card.tsx`: `<Link>` mit custom border/shadow/rounded — exakt `<Card variant="interactive" asChild><Link>`
13. `category-showcase-block.tsx`: `<Link>` mit `border bg-surface-elevated rounded-xl` — `<Card variant="interactive" asChild><Link>`
14. `produkt/[slug]/page.tsx`: Beschreibungs-`<section>` mit `bg-surface-elevated`, inline boxShadow — `<Card>` + `<CardHeader>` + `<CardContent>`
15. `cart-icon.tsx`: `<span>` mit `bg-primary rounded-full` — `<Badge variant="default">` + className-Override

**Card `asChild` Erweiterung nötig:** `components/ui/card.tsx` nutzt `<div>` ohne Radix Slot — muss um `asChild` Prop erweitert werden (analog zum Button-Pattern) damit `product-card.tsx` und `category-showcase-block.tsx` migriert werden können.

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → "Architecture Layers" + "Composition Architecture"

```
┌──────────────────────────────────────────────────────────┐
│  Domain Components (components/{domain}/)                  │
│                                                            │
│  ProductCard = Card(interactive) + CardContent + Image     │
│  CartItemRow = Card + CardContent + Button(ghost/icon)     │
│  CartSummary = Card + CardContent + CardFooter + Button    │
│  AddToCartButton = Button(default) + isLoading + className │
│  QuantityStepper = Button(outline/icon) ×2                 │
│  FilterChips = Button(default/secondary, asChild) + Link   │
│  CartIcon = Link + Badge                                   │
│  HeroBlock = Button(default, asChild) + Link               │
│  EmptyCart = Button(default, asChild) + Link               │
├──────────────────────────────────────────────────────────┤
│  UI Primitives (components/ui/)  — UNVERÄNDERT (+ asChild) │
│                                                            │
│  Button (6 Variants, 4 Sizes, isLoading, asChild)          │
│  Card (default, interactive) + Header/Title/Content/Footer │
│  Badge (4 Variants)                                        │
└──────────────────────────────────────────────────────────┘
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `frontend/package.json` | `@radix-ui/react-slot: "latest"` → `"^1.2.4"`, `@radix-ui/react-dialog: "latest"` → `"^1.1.15"`, `tw-animate-css: "latest"` → gepinnte Version (aktuell installiert via lockfile) |
| `frontend/components/ui/card.tsx` | `asChild` Prop hinzufügen: Slot Import + Comp-Pattern analog zu `button.tsx` |
| `frontend/components/product/add-to-cart-button.tsx` | `<button>` → `<Button isLoading variant="default">` mit Success-State via `cn()` |
| `frontend/components/cart/cart-summary.tsx` | `<button>` → `<Button variant="default">` + `<Card><CardContent><CardFooter>` Wrapper |
| `frontend/components/cart/quantity-stepper.tsx` | 2× `<button>` → `<Button variant="outline" size="icon" className="w-8 h-8">` |
| `frontend/components/cart/cart-item-row.tsx` | Outer `<div>` → `<Card>` + `<CardContent>`, Remove-button → `<Button variant="ghost" size="icon">` |
| `frontend/components/product/variant-selector-size.tsx` | `<button>` → `<Button variant={isSelected ? "default" : "outline"}>` + `aria-pressed` |
| `frontend/components/layout/mobile-menu.tsx` | SheetTrigger-`<button>` → `<Button variant="ghost" size="icon">` |
| `frontend/app/warenkorb/cart-page-client.tsx` | Error-Close-`<button>` → `<Button variant="ghost" size="icon" className="text-error hover:text-error">` |
| `frontend/components/product/product-image-gallery.tsx` | Thumbnail-`<button>` → `<Button variant="ghost" size="icon" className="w-18 h-18 ...">` |
| `frontend/components/blocks/hero-block.tsx` | CTA-`<Link>` → `<Button asChild><Link>` |
| `frontend/components/cart/empty-cart.tsx` | CTA-`<Link>` → `<Button asChild><Link>` |
| `frontend/components/category/filter-chips.tsx` | 2× `<Link>` → `<Button asChild className="rounded-full">` (aktiv: default, inaktiv: secondary) |
| `frontend/components/product/product-card.tsx` | `<Link>` → `<Card variant="interactive" asChild><Link>` + `<CardContent className="p-3">` |
| `frontend/components/blocks/category-showcase-block.tsx` | `<Link>` → `<Card variant="interactive" asChild><Link>` |
| `frontend/app/produkt/[slug]/page.tsx` | `<section>` → `<Card>` + `<CardHeader>` + `<CardTitle>` + `<CardContent>` |
| `frontend/components/layout/cart-icon.tsx` | `<span>` Badge → `<Badge variant="default" className="absolute -top-1 -right-1 ...">` |
| `frontend/tests/slices/` | Bestehende Tests anpassen: `data-slot="button"` statt className-Matcher, `role` statt Tag-Selektoren |
| `frontend/tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts` | Neue Tests für alle 15 migrierten Patterns |

### 2. Datenfluss

```
Bestehende Component Props (unveraendert)
  ↓
Domain Component (z.B. ProductCard, CartSummary, AddToCartButton)
  ↓
shadcn/ui Primitives (Button, Card, Badge) — liefern Styling + Accessibility
  ↓
HTML Output mit data-slot Attributen (data-slot="button", data-slot="card", data-slot="badge")
  ↓
Identisches visuelles Rendering (nur DOM-Struktur leicht geändert)
```

### 3. Dependency Pinning (Erster Schritt)

Vor jeder Component-Migration: `frontend/package.json` aktualisieren um Breaking Changes durch `"latest"` zu verhindern.

| Package | Aktuell | Gepinnt auf |
|---------|---------|-------------|
| `@radix-ui/react-slot` | `"latest"` | `"^1.2.4"` |
| `@radix-ui/react-dialog` | `"latest"` | `"^1.1.15"` |
| `tw-animate-css` | `"latest"` | Aktuell installierte Version aus `pnpm-lock.yaml` |

### 4. Card `asChild` Erweiterung

`components/ui/card.tsx` muss um `asChild` Prop erweitert werden. Minimale Änderung — 3 Zeilen analog zum Button-Pattern:

1. `import { Slot } from '@radix-ui/react-slot'` hinzufügen
2. `CardProps` Interface: `asChild?: boolean` ergänzen
3. In `Card` Component: `const Comp = asChild ? Slot : 'div'` und `Comp` statt `div` rendern

### 5. Accessibility-Audit pro Component

| Component | Audit | Aktion |
|-----------|-------|--------|
| `add-to-cart-button.tsx` | `aria-live="polite"` vorhanden | Beibehalten |
| `cart-summary.tsx` | `aria-label` fehlt für Checkout-Button | `aria-label="Zur Kasse"` hinzufügen |
| `quantity-stepper.tsx` | `aria-label` für beide Buttons vorhanden | Beibehalten |
| `cart-item-row.tsx` | `aria-label` für Remove-Button vorhanden | Beibehalten |
| `variant-selector-size.tsx` | `aria-pressed` bereits vorhanden | Beibehalten |
| `mobile-menu.tsx` | `aria-label="Menü öffnen"` vorhanden | Beibehalten |
| `cart-page-client.tsx` | `aria-label="Fehlermeldung schliessen"` vorhanden | Beibehalten |
| `product-image-gallery.tsx` | `role="tab"` + `aria-selected` vorhanden | Beibehalten |
| `hero-block.tsx` | Kein `aria-label` auf CTA-Link — Linktext ist vorhanden | Linktext reicht als accessible name |
| `empty-cart.tsx` | Kein `aria-label` — Linktext vorhanden | Linktext reicht |
| `filter-chips.tsx` | `aria-current="page"` vorhanden | Beibehalten |
| `product-card.tsx` | Produktname als Linktext vorhanden | Linktext reicht |
| `cart-icon.tsx` | `aria-label` auf Link vorhanden, Badge `aria-hidden="true"` | Beibehalten |

### 6. Migrations-Reihenfolge

1. **Dependency Pinning** — `package.json` zuerst (sicherheitskritisch)
2. **Card `asChild` Extension** — Voraussetzung für product-card + category-showcase
3. **Buttons (11 Patterns)** — add-to-cart, cart-summary, quantity-stepper, cart-item-row, variant-selector-size, mobile-menu, cart-page-client, product-image-gallery
4. **Button-als-Link (4 Patterns)** — hero-block, empty-cart, filter-chips (aktiv + inaktiv)
5. **Card Composition (5 Patterns)** — product-card, cart-item-row, cart-summary, category-showcase, produkt-beschreibung
6. **Badge (1 Pattern)** — cart-icon
7. **Bestehende Tests anpassen** — Selektoren auf `data-slot` und `role` umstellen
8. **Neue Tests schreiben** — mind. 1 Test pro migriertem Pattern

---

## UI Anforderungen

**Keine neuen Screens.** Dieses Feature ändert keine sichtbare UI. Alle bestehenden Seiten bleiben visuell identisch.

**Prüfung nach Migration:**
- Homepage, Kategorie-Seite, Produkt-Seite, Warenkorb-Seite: visuell identisch
- Browser DevTools: `data-slot` Attribute auf migrierten Elementen sichtbar
- Keyboard-Navigation: Tab + Enter/Space auf allen Buttons/Links funktioniert
- Screen Reader: `aria-label` auf Icon-Buttons korrekt vorgelesen

### Accessibility

- [x] Alle Icon-only Buttons haben `aria-label` (Hamburger, Remove, Error-Close, Gallery-Thumbnails)
- [x] Toggle-Buttons (Size Selector) haben `aria-pressed`
- [x] Aktive Filter-Chips haben `aria-current="page"`
- [x] Checkout-Button erhält `aria-label="Zur Kasse"` (fehlte bisher)
- [x] Badge-Zähler hat `aria-hidden="true"` (Gesamtzahl steht im Link-`aria-label`)
- [x] Alle interaktiven Elemente haben `focus-visible` Ring (via Radix UI Primitives)
- [x] `touch-action: manipulation` auf allen Touch-Elementen (Button-Primitive hat `[touch-action:manipulation]` in Base Styles)

---

## Acceptance Criteria

1) GIVEN die Codebase enthält `@radix-ui/react-slot: "latest"` in package.json
   WHEN die Migration beginnt
   THEN ist `@radix-ui/react-slot` auf `"^1.2.4"` gepinnt, `@radix-ui/react-dialog` auf `"^1.1.15"` gepinnt und `tw-animate-css` auf die aktuell installierte Version gepinnt

2) GIVEN `components/ui/card.tsx` hat kein `asChild` Support
   WHEN die Card-Erweiterung implementiert wird
   THEN akzeptiert `<Card asChild>` exakt 1 Kind-Element und rendert dieses als Card-Container (via Radix Slot), und bestehende `<Card>` Usages (ohne asChild) funktionieren unverändert

3) GIVEN `add-to-cart-button.tsx` nutzt einen custom `<button>`
   WHEN die Migration implementiert ist
   THEN rendert `<AddToCartButton>` ein Element mit `data-slot="button"`, `isLoading` zeigt denselben Spinner wie der Button-Primitive, und der Success-State wechselt die Hintergrundfarbe auf `bg-success` via `cn()`

4) GIVEN `cart-summary.tsx` hat keinen Card-Wrapper und keinen `aria-label` auf dem Checkout-Button
   WHEN die Migration implementiert ist
   THEN ist der Checkout-Button ein `<Button variant="default">` mit `aria-label="Zur Kasse"` und der gesamte Summary-Bereich ist in `<Card><CardContent>...</CardContent><CardFooter>` strukturiert

5) GIVEN `quantity-stepper.tsx` nutzt 2× custom `<button>` mit 32px Größe
   WHEN die Migration implementiert ist
   THEN sind beide Buttons `<Button variant="outline" size="icon" className="w-8 h-8">` mit `data-slot="button"` und behalten ihre `aria-label` Attribute

6) GIVEN `cart-item-row.tsx` hat einen custom outer `<div>` mit Border-Styling
   WHEN die Migration implementiert ist
   THEN ist der outer Container ein `<Card>` mit `data-slot="card"`, der Inhalt in `<CardContent>` und der Remove-Button ein `<Button variant="ghost" size="icon">` mit `data-slot="button"`

7) GIVEN `variant-selector-size.tsx` nutzt custom `<button>` mit konditionaler className
   WHEN die Migration implementiert ist
   THEN ist jeder Size-Button ein `<Button>` mit `variant="default"` (selected) oder `variant="outline"` (unselected), `aria-pressed` ist gesetzt und deaktivierte Sizes haben `disabled` Prop

8) GIVEN `hero-block.tsx` hat ein `<Link>` mit Button-Styles
   WHEN die Migration implementiert ist
   THEN ist der CTA ein `<Button asChild><Link href={ctaLink}>` der semantisch als `<a>` rendert und visuell identisch zum vorherigen State ist

9) GIVEN `filter-chips.tsx` hat `<Link>` Elemente mit konditionaler className
   WHEN die Migration implementiert ist
   THEN ist der aktive Filter-Chip `<Button asChild className="rounded-full flex-shrink-0"><Link aria-current="page">` und der inaktive Filter-Chip `<Button variant="secondary" asChild className="rounded-full flex-shrink-0"><Link>`

10) GIVEN `product-card.tsx` hat eine `<Link>` mit manuellen border/shadow/rounded-Styles
    WHEN die Migration implementiert ist
    THEN ist der Card-Container `<Card variant="interactive" asChild><Link>` mit `data-slot="card"`, der Hover-Lift-Effekt kommt vom Card-Primitive und `group-hover:scale-105` auf dem Bild bleibt erhalten

11) GIVEN `produkt/[slug]/page.tsx` hat eine `<section>` mit manuellen bg/shadow/rounded-Styles
    WHEN die Migration implementiert ist
    THEN ist die Beschreibungs-Section eine `<Card>` mit `<CardHeader><CardTitle>` für die Überschrift und `<CardContent>` für den Prose-Inhalt

12) GIVEN `cart-icon.tsx` hat einen `<span>` Badge mit manuellen bg/rounded-Styles
    WHEN die Migration implementiert ist
    THEN ist der Badge ein `<Badge variant="default" className="absolute -top-1 -right-1 ...">` mit `data-slot="badge"` und `aria-hidden="true"`

13) GIVEN alle 15 Patterns sind migriert
    WHEN `pnpm test` ausgeführt wird
    THEN sind alle 151+ bestehenden Tests und alle neuen Tests grün (0 Failures)

14) GIVEN alle 15 Patterns sind migriert
    WHEN `pnpm build` ausgeführt wird
    THEN ist der Build erfolgreich ohne TypeScript-Fehler

15) GIVEN `grep -r 'className=.*bg-primary.*rounded' frontend/components` nach der Migration
    WHEN nur die Out-of-Scope Dateien geprüft werden
    THEN enthalten nur `variant-selector-color.tsx` und Gallery-Dot-Buttons (10px) noch custom Button-Styles

---

## Testfälle

**WICHTIG:** Tests definieren das erwartete Verhalten. Der Orchestrator führt diese Tests automatisch nach der Slice-Implementierung aus.

### Test-Datei

`tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// frontend/tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts
import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

// -- Helpers --
function renderWithCartContext(ui: React.ReactElement, cartOverrides = {}) {
  const mockCart = {
    itemCount: 0,
    cartItems: [],
    subtotal: '0,00 €',
    shippingTotal: 'kostenlos',
    total: '0,00 €',
    isLoading: false,
    updateQuantity: vi.fn(),
    removeItem: vi.fn(),
    ...cartOverrides,
  }
  // CartContext.Provider Wrapper wird vom Test-Writer implementiert
  return render(ui)
}

// -----------------------------------------------------------------------
// AC 1: Dependency Pinning
// -----------------------------------------------------------------------
describe('Dependency Pinning', () => {
  it('should have pinned @radix-ui/react-slot version (not "latest")', async () => {
    const pkg = await import('../../../../package.json')
    expect(pkg.dependencies['@radix-ui/react-slot']).not.toBe('latest')
    expect(pkg.dependencies['@radix-ui/react-slot']).toMatch(/^\^?\d+\.\d+\.\d+/)
  })

  it('should have pinned @radix-ui/react-dialog version (not "latest")', async () => {
    const pkg = await import('../../../../package.json')
    expect(pkg.dependencies['@radix-ui/react-dialog']).not.toBe('latest')
    expect(pkg.dependencies['@radix-ui/react-dialog']).toMatch(/^\^?\d+\.\d+\.\d+/)
  })

  it('should have pinned tw-animate-css version (not "latest")', async () => {
    const pkg = await import('../../../../package.json')
    expect(pkg.dependencies['tw-animate-css']).not.toBe('latest')
  })
})

// -----------------------------------------------------------------------
// AC 2: Card asChild Extension
// -----------------------------------------------------------------------
describe('Card asChild Extension', () => {
  it('should render Card as a link when asChild is used with <a>', () => {
    const { Card } = require('@/components/ui/card')
    const { container } = render(
      <Card asChild>
        <a href="/test">Card Link</a>
      </Card>
    )
    const link = container.querySelector('a[href="/test"]')
    expect(link).not.toBeNull()
    expect(link?.getAttribute('data-slot')).toBe('card')
  })

  it('should render Card as div (default) when asChild is not used', () => {
    const { Card } = require('@/components/ui/card')
    const { container } = render(<Card>Inhalt</Card>)
    expect(container.querySelector('div[data-slot="card"]')).not.toBeNull()
    expect(container.querySelector('a')).toBeNull()
  })
})

// -----------------------------------------------------------------------
// AC 3: AddToCartButton
// -----------------------------------------------------------------------
describe('AddToCartButton — Button Primitive', () => {
  it('should render with data-slot="button"', () => {
    const { AddToCartButton } = require('@/components/product/add-to-cart-button')
    render(
      <AddToCartButton
        productId={1}
        variationId={null}
        canAdd={true}
        hasVariants={false}
      />
    )
    expect(document.querySelector('[data-slot="button"]')).not.toBeNull()
  })

  it('should show loading spinner when isLoading is true', () => {
    const { AddToCartButton } = require('@/components/product/add-to-cart-button')
    render(
      <AddToCartButton
        productId={1}
        variationId={null}
        canAdd={true}
        hasVariants={false}
        isLoading={true}
      />
    )
    const spinner = document.querySelector('[aria-hidden="true"].animate-spin')
    expect(spinner).not.toBeNull()
  })

  it('should be disabled when canAdd is false', () => {
    const { AddToCartButton } = require('@/components/product/add-to-cart-button')
    render(
      <AddToCartButton
        productId={1}
        variationId={null}
        canAdd={false}
        hasVariants={true}
      />
    )
    const button = document.querySelector('[data-slot="button"]') as HTMLButtonElement
    expect(button?.disabled).toBe(true)
  })
})

// -----------------------------------------------------------------------
// AC 4: CartSummary — Button + Card
// -----------------------------------------------------------------------
describe('CartSummary — Button + Card Primitive', () => {
  it.todo('should render checkout button with data-slot="button"')
  it.todo('should render checkout button with aria-label="Zur Kasse"')
  it.todo('should render Card wrapper with data-slot="card"')
  it.todo('should render CardFooter with data-slot="card-footer"')
  it.todo('should disable checkout button when cart is empty')
})

// -----------------------------------------------------------------------
// AC 5: QuantityStepper — Button Primitives
// -----------------------------------------------------------------------
describe('QuantityStepper — Button Primitive', () => {
  it.todo('should render minus button with data-slot="button"')
  it.todo('should render plus button with data-slot="button"')
  it.todo('should have aria-label="Menge verringern" on minus button')
  it.todo('should have aria-label="Menge erhoehen" on plus button')
  it.todo('should disable minus button when quantity is 1')
  it.todo('should disable plus button when quantity is 99')
})

// -----------------------------------------------------------------------
// AC 6: CartItemRow — Card + Button Primitives
// -----------------------------------------------------------------------
describe('CartItemRow — Card + Button Primitive', () => {
  it.todo('should render outer container with data-slot="card"')
  it.todo('should render content area with data-slot="card-content"')
  it.todo('should render remove button with data-slot="button"')
  it.todo('should render remove button with variant="ghost"')
})

// -----------------------------------------------------------------------
// AC 7: VariantSelectorSize — Button Toggle
// -----------------------------------------------------------------------
describe('VariantSelectorSize — Button Toggle', () => {
  it('should render selected size with variant="default" (data-slot button)', () => {
    const { VariantSelectorSize } = require('@/components/product/variant-selector-size')
    render(
      <VariantSelectorSize
        sizes={[{ value: 'M', available: true }]}
        selectedSize="M"
        onSizeSelect={vi.fn()}
      />
    )
    const button = document.querySelector('[data-slot="button"]') as HTMLButtonElement
    expect(button).not.toBeNull()
    expect(button?.getAttribute('aria-pressed')).toBe('true')
  })

  it('should render unselected size with aria-pressed="false"', () => {
    const { VariantSelectorSize } = require('@/components/product/variant-selector-size')
    render(
      <VariantSelectorSize
        sizes={[{ value: 'L', available: true }]}
        selectedSize={null}
        onSizeSelect={vi.fn()}
      />
    )
    const button = document.querySelector('[data-slot="button"]') as HTMLButtonElement
    expect(button?.getAttribute('aria-pressed')).toBe('false')
  })

  it('should disable unavailable sizes', () => {
    const { VariantSelectorSize } = require('@/components/product/variant-selector-size')
    render(
      <VariantSelectorSize
        sizes={[{ value: 'XL', available: false }]}
        selectedSize={null}
        onSizeSelect={vi.fn()}
      />
    )
    const button = document.querySelector('[data-slot="button"]') as HTMLButtonElement
    expect(button?.disabled).toBe(true)
  })
})

// -----------------------------------------------------------------------
// AC 8: HeroBlock — Button asChild
// -----------------------------------------------------------------------
describe('HeroBlock — Button asChild', () => {
  it('should render CTA as <a> element (not <button>)', () => {
    const { HeroBlock } = require('@/components/blocks/hero-block')
    const { container } = render(
      <HeroBlock
        data={{
          heroHeadline: 'Test Headline',
          heroSubline: 'Test Subline',
          heroCtaText: 'Jetzt shoppen',
          heroCtaLink: '/kategorie/t-shirts',
          heroBackgroundImage: null,
        }}
      />
    )
    const ctaLink = container.querySelector('[data-slot="button"]')
    expect(ctaLink?.tagName.toLowerCase()).toBe('a')
  })
})

// -----------------------------------------------------------------------
// AC 9: FilterChips — Button asChild
// -----------------------------------------------------------------------
describe('FilterChips — Button asChild', () => {
  it('should render active chip with aria-current="page"', () => {
    const { FilterChips } = require('@/components/category/filter-chips')
    render(
      <FilterChips
        categories={[{ id: '1', name: 'T-Shirts', slug: 't-shirts' }]}
        currentSlug="alle"
      />
    )
    const activeChip = document.querySelector('[aria-current="page"]')
    expect(activeChip).not.toBeNull()
  })

  it('should render chips as <a> elements via asChild', () => {
    const { FilterChips } = require('@/components/category/filter-chips')
    const { container } = render(
      <FilterChips
        categories={[{ id: '1', name: 'T-Shirts', slug: 't-shirts' }]}
        currentSlug="alle"
      />
    )
    const buttons = container.querySelectorAll('[data-slot="button"]')
    expect(buttons.length).toBeGreaterThan(0)
    buttons.forEach((btn) => {
      expect(btn.tagName.toLowerCase()).toBe('a')
    })
  })
})

// -----------------------------------------------------------------------
// AC 10: ProductCard — Card asChild
// -----------------------------------------------------------------------
describe('ProductCard — Card asChild', () => {
  it('should render as <a> link with data-slot="card"', () => {
    const { ProductCard } = require('@/components/product/product-card')
    const { container } = render(
      <ProductCard
        product={{
          slug: 'test-shirt',
          name: 'Test T-Shirt',
          price: '29,99 €',
          stockStatus: 'IN_STOCK',
          image: null,
        }}
      />
    )
    const card = container.querySelector('[data-slot="card"]')
    expect(card).not.toBeNull()
    expect(card?.tagName.toLowerCase()).toBe('a')
  })

  it('should render product info in CardContent (data-slot="card-content")', () => {
    const { ProductCard } = require('@/components/product/product-card')
    const { container } = render(
      <ProductCard
        product={{
          slug: 'test-shirt',
          name: 'Test T-Shirt',
          price: '29,99 €',
          stockStatus: 'IN_STOCK',
          image: null,
        }}
      />
    )
    const cardContent = container.querySelector('[data-slot="card-content"]')
    expect(cardContent).not.toBeNull()
    expect(cardContent?.textContent).toContain('Test T-Shirt')
  })
})

// -----------------------------------------------------------------------
// AC 11: Produkt Page — Card Composition
// -----------------------------------------------------------------------
describe('Produkt Page — Card Composition', () => {
  it.todo('should render description section as Card (data-slot="card")')
  it.todo('should render description heading in CardHeader (data-slot="card-header")')
  it.todo('should render CardTitle with data-slot="card-title"')
  it.todo('should render description content in CardContent (data-slot="card-content")')
})

// -----------------------------------------------------------------------
// AC 12: CartIcon — Badge Primitive
// -----------------------------------------------------------------------
describe('CartIcon — Badge Primitive', () => {
  it.todo('should render badge with data-slot="badge" when itemCount > 0')
  it.todo('should not render badge when itemCount is 0')
  it.todo('should render badge with aria-hidden="true"')
  it.todo('should render "99+" when itemCount exceeds 99')
})

// -----------------------------------------------------------------------
// AC 13-14: Test Suite + Build
// -----------------------------------------------------------------------
describe('Migration Completeness', () => {
  it.todo('pnpm build erfolgreich — wird manuell als Gate geprüft')
  it.todo('Alle 151+ bestehenden Tests grün — wird via pnpm test geprüft')
})
```
</test_spec>

### Manuelle Tests (nach Implementierung)

1. `pnpm build` → Build muss ohne TypeScript-Fehler abschliessen
2. `pnpm dev` → Alle Seiten visuell mit pre-migration Screenshots vergleichen
3. Keyboard-Navigation: Tab-Fokus durch alle Buttons/Links auf Homepage, Kategorie, Produkt, Warenkorb — kein Focus-Trap, kein Focus-Verlust
4. `grep -rn 'className=.*bg-primary' frontend/components` → Nur erlaubte Ausnahmen (`variant-selector-color.tsx`, Gallery-Dots)

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig und vollständig (GIVEN/WHEN/THEN Format)
- [x] Sicherheits-/Privacy-Aspekte bedacht (keine Änderungen — Radix UI Primitives haben mindestens gleichwertigen Accessibility-Standard)
- [x] UX/Copy final — keine sichtbaren UI-Änderungen
- [x] Rollout-Plan: Einmalige Migration, kein Feature-Flag nötig. Rollback via Git Revert.

---

## Skill Verification (UI-Implementation)

### React Best Practices Verification

**Critical Priority:**
- [x] `bundle-barrel-imports`: Imports direkt aus `@/components/ui/button`, nicht aus Barrel `@/components` — bereits durch shadcn/ui Pattern sichergestellt
- [x] `async-suspense-boundaries`: Keine neuen async Boundaries — RSC-Struktur unverändert

**Medium Priority:**
- [x] `rerender-memo`: Keine neuen Memoization-Änderungen — bestehende Patterns unverändert
- [x] `rendering-conditional-render`: Ternary statt `&&` für Badge-Rendering: `{itemCount > 0 ? <Badge> : null}` statt `{itemCount > 0 && <Badge>}`

### Web Design Guidelines Verification

**Accessibility:**
- [x] Icon-only Buttons haben `aria-label` (Hamburger, Remove, Error-Close, Gallery-Thumbnails, QuantityStepper)
- [x] Toggle-Buttons haben `aria-pressed` (VariantSelectorSize)
- [x] Aktive Navigation-Links haben `aria-current="page"` (FilterChips)
- [x] Checkout-Button erhält `aria-label="Zur Kasse"` (neu hinzugefügt)
- [x] `focus-visible` Ring via Button-Primitive Base-Styles: `focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-primary`

**Touch & Mobile:**
- [x] `touch-action: manipulation` via Button-Primitive Base-Styles: `[touch-action:manipulation]`
- [x] Touch Targets: `size="default"` = `h-[var(--min-touch-target,2.75rem)]` = 44px. QuantityStepper 32px — Ausnahme dokumentiert (physisch in Group, kombiniertes Target ausreichend)

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [x] Alle migrierten Components nutzen ausschliesslich semantische Tokens (`bg-primary`, `bg-surface-elevated`, `text-text-primary`, `rounded-card`, `shadow-card`) — kein hardcoded hex oder px
- [x] className Overrides (Filter-Chips `rounded-full`, QuantityStepper `w-8 h-8`) nutzen Tailwind-Klassen, kein inline style

---

## Constraints & Hinweise

**Betrifft:**
- 13 Component-Dateien + 1 Page-Datei + `package.json` + `components/ui/card.tsx`
- Alle bestehenden Tests in `tests/slices/` die migrierten Components testen

**Abgrenzung:**
- `variant-selector-color.tsx` — NICHT migrieren (runde Color-Swatches mit dynamischem `style={{backgroundColor}}` + SVG-Strikethrough — zu domain-spezifisch)
- `product-image-gallery.tsx` Dot-Buttons (10px, `role="tab"`) — NICHT migrieren (zu klein für Button-Primitive)
- `footer.tsx` Links — NICHT migrieren (semantisch Navigations-Links, keine Aktions-Buttons)
- Gallery-Thumbnails: `<Button variant="ghost" size="icon">` OHNE `asChild` — Image ist als Child des Buttons, nicht umgekehrt
- Keine neuen shadcn/ui Variants — nur bestehende Variants + `className` Override

**Bekannte Risks:**
- `tailwind-merge` muss `rounded-full` korrekt über CVA-Base `rounded-[var(--radius-button)]` überschreiben — dokumentiert in architecture.md als getestetes Verhalten
- Card `asChild` + `group` CSS Klasse auf ProductCard: `group` muss über `className` auf dem `<Card>` oder dem `<Link>` bleiben — nicht verloren gehen

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| Theming Slice 2 (approved) | `components/ui/button.tsx` | React Component | EXISTS — 6 Variants, 4 Sizes, `isLoading`, `asChild` via Radix Slot |
| Theming Slice 2 (approved) | `components/ui/card.tsx` | React Component | EXISTS — 2 Variants, 6 Compound Components. Wird in diesem Slice um `asChild` erweitert. |
| Theming Slice 2 (approved) | `components/ui/badge.tsx` | React Component | EXISTS — 4 Variants, `<span>` Element, `data-slot="badge"` |
| Theming Slice 2 (approved) | `lib/utils.ts` → `cn()` | Function | EXISTS — clsx + tailwind-merge für className Merging |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `components/ui/card.tsx` (erweitert) | React Component mit `asChild` | slice-02-storybook (Stories), zukünftige Components | `<Card asChild>` akzeptiert 1 Kind-Element, rendert als Child-Tag mit Card-Styles |
| Alle migrierten Components | React Components (unverändertes Props-Interface) | slice-02-storybook (Stories) | Props-Interface unverändert — nur interne Implementierung ändert sich |
| `data-slot` Attribute | DOM-Attribute | Tests, Storybook Controls | `data-slot="button"`, `data-slot="card"`, `data-slot="card-content"`, `data-slot="card-footer"`, `data-slot="badge"` |

### Integration Validation Tasks

- [x] `@radix-ui/react-slot` verfügbar: bereits in package.json (wird gepinnt)
- [x] `Button` Component `asChild` Pattern: bereits implementiert und funktionsfähig (mobile-menu.tsx nutzt Sheet, cookie-consent-banner.tsx nutzt Button)
- [x] `Card asChild` Extension: Minimale Änderung (Slot Import + 2 Zeilen) — wird in diesem Slice implementiert
- [x] Props-Interfaces aller migrierten Components bleiben identisch (keine Breaking Changes für Consumer)

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind Pflicht-Deliverables. Abweichung nur mit expliziter Begründung.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `card.tsx` asChild Extension | Section "Card asChild Erweiterung" | YES | Exakt dieses Pattern implementieren |
| `package.json` Dependency Pinning | Section "Dependency Pinning" | YES | Alle 3 `"latest"` pinnen |
| `add-to-cart-button.tsx` Migration | Section "Button Migration: AddToCartButton" | YES | `<Button>` mit `isLoading` + Success-State via `cn()` |
| `cart-summary.tsx` Migration | Section "Card + Button Migration: CartSummary" | YES | `<Card>` + `<CardFooter>` + `<Button aria-label="Zur Kasse">` |
| `quantity-stepper.tsx` Migration | Section "Button Migration: QuantityStepper" | YES | `className="w-8 h-8"` Override für 32px |
| `cart-item-row.tsx` Migration | Section "Card + Button Migration: CartItemRow" | YES | `<Card>` + `<CardContent>` + `<Button variant="ghost" size="icon">` |
| `variant-selector-size.tsx` Migration | Section "Button Migration: VariantSelectorSize" | YES | Variant-Switch + `aria-pressed` |
| `mobile-menu.tsx` Migration | Section "Button Migration: MobileMenu" | YES | `<SheetTrigger asChild><Button variant="ghost" size="icon">` — SheetTrigger-Child-Pattern |
| `hero-block.tsx` Migration | Section "Button-as-Link Migration: HeroBlock" | YES | `<Button asChild><Link>` Pattern |
| `empty-cart.tsx` Migration | Section "Button-as-Link Migration: EmptyCart" | YES | `<Button asChild><Link>` + Hinweis zu `touchAction` inline style entfernen |
| `filter-chips.tsx` Migration | Section "Button-as-Link Migration: FilterChips" | YES | `rounded-full` Override + `aria-current="page"` |
| `product-card.tsx` Migration | Section "Card Migration: ProductCard" | YES | `<Card variant="interactive" asChild><Link>` + `group` Klasse |
| `cart-icon.tsx` Migration | Section "Badge Migration: CartIcon" | YES | `<Badge className="absolute -top-1 -right-1 ...">` |

### Card asChild Erweiterung

```typescript
// frontend/components/ui/card.tsx — Änderungen (Auszug)
import * as React from 'react'
import { Slot } from '@radix-ui/react-slot'  // NEU
import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/lib/utils'

// ... cardVariants unveraendert ...

export interface CardProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof cardVariants> {
  asChild?: boolean  // NEU
}

const Card = React.forwardRef<HTMLDivElement, CardProps>(
  ({ className, variant, asChild = false, ...props }, ref) => {  // NEU: asChild
    const Comp = asChild ? Slot : 'div'  // NEU
    return (
      <Comp
        ref={ref}
        className={cn(cardVariants({ variant, className }))}
        data-slot="card"
        {...props}
      />
    )
  }
)
```

### Dependency Pinning

```json
// frontend/package.json — dependencies (relevanter Auszug)
{
  "dependencies": {
    "@radix-ui/react-dialog": "^1.1.15",
    "@radix-ui/react-slot": "^1.2.4",
    "tw-animate-css": "^0.1.5"
  }
}
```

> **Hinweis:** Die exakte Version für `tw-animate-css` muss aus `pnpm-lock.yaml` entnommen werden — der Implementierungs-Agent liest die tatsächlich installierte Version und pinnt diese.

### Button Migration: AddToCartButton

```typescript
// frontend/components/product/add-to-cart-button.tsx — Auszug (migriertes Element)
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

// ... (Props und State unveraendert) ...

return (
  <Button
    type="button"
    onClick={handleClick}
    disabled={isDisabled}
    aria-disabled={isDisabled}
    aria-live="polite"
    isLoading={isLoading}
    className={cn(
      'w-full text-base',
      isSuccess && 'bg-success hover:bg-success focus-visible:ring-success scale-[0.98]'
    )}
  >
    {isSuccess ? (
      <span className="flex items-center justify-center gap-2">
        <svg aria-hidden="true" width="18" height="18" viewBox="0 0 18 18"
          fill="none" stroke="currentColor" strokeWidth="2"
          strokeLinecap="round" strokeLinejoin="round">
          <polyline points="3 9 7 13 15 5" />
        </svg>
        {label}
      </span>
    ) : (
      label
    )}
  </Button>
)
```

### Card + Button Migration: CartSummary

```typescript
// frontend/components/cart/cart-summary.tsx — migrierte Struktur
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter } from '@/components/ui/card'
import { useCart } from '@/contexts/cart-context'
import { checkoutRedirect } from '@/lib/cart/checkout-redirect'

export function CartSummary() {
  const { subtotal, shippingTotal, total, cartItems, isLoading } = useCart()
  const isEmpty = cartItems.length === 0

  return (
    <Card className="mt-6">
      <CardContent className="pt-6 space-y-3">
        {/* Zwischensumme, Versandkosten, Trennlinie, Gesamtpreis — unveraendert */}
        <div className="flex justify-between text-sm">
          <span className="text-text-secondary">Zwischensumme</span>
          <span className="tabular-nums">{subtotal}</span>
        </div>
        <div className="flex justify-between text-sm">
          <span className="text-text-secondary">Versandkosten</span>
          <span className="tabular-nums">{shippingTotal}</span>
        </div>
        <hr className="border-border" />
        <div className="flex justify-between font-semibold text-lg">
          <span>Gesamt</span>
          <span className="tabular-nums">{total}</span>
        </div>
        <p className="text-xs text-text-secondary">
          Gemäß §19 UStG wird keine Umsatzsteuer berechnet.
        </p>
      </CardContent>
      <CardFooter className="flex-col gap-2 pt-0">
        <Button
          onClick={checkoutRedirect}
          disabled={isEmpty || isLoading}
          aria-label="Zur Kasse"
          className="w-full"
        >
          Zur Kasse
        </Button>
      </CardFooter>
    </Card>
  )
}
```

### Button Migration: QuantityStepper

```typescript
// frontend/components/cart/quantity-stepper.tsx — migrierte Buttons (Auszug)
import { Button } from '@/components/ui/button'

// ... (Props und useCart unveraendert) ...

return (
  <div className="flex items-center gap-1" role="group" aria-label="Menge aendern">
    <Button
      variant="outline"
      size="icon"
      onClick={() => updateQuantity(itemKey, quantity - 1)}
      disabled={disabled || quantity <= 1}
      aria-label="Menge verringern"
      className="w-8 h-8"
    >
      <span aria-hidden="true">&#8722;</span>
    </Button>

    <span
      className="w-8 text-center tabular-nums text-sm font-medium"
      aria-live="polite"
      aria-label={`Menge: ${quantity}`}
    >
      {quantity}
    </span>

    <Button
      variant="outline"
      size="icon"
      onClick={() => updateQuantity(itemKey, quantity + 1)}
      disabled={disabled || quantity >= 99}
      aria-label="Menge erhoehen"
      className="w-8 h-8"
    >
      <span aria-hidden="true">+</span>
    </Button>
  </div>
)
```

### Card + Button Migration: CartItemRow

```typescript
// frontend/components/cart/cart-item-row.tsx — migrierte Struktur (Auszug)
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'

// ... (Props und useCart unveraendert) ...

return (
  <Card>
    <CardContent className="flex gap-4 p-4">
      {/* Thumbnail — unveraendert */}
      <div className="flex-shrink-0 w-20 h-20 relative rounded overflow-hidden bg-surface-elevated">
        {/* ... Image-Rendering unveraendert ... */}
      </div>

      {/* Produktinfo — unveraendert bis auf Remove-Button */}
      <div className="flex-1 min-w-0">
        <p className="font-medium text-text-primary truncate">{item.productName}</p>
        {item.variationLabel && (
          <p className="text-sm text-text-secondary mt-0.5">{item.variationLabel}</p>
        )}
        <div className="flex items-center justify-between mt-3">
          <QuantityStepper
            itemKey={item.key}
            quantity={item.quantity}
            disabled={isLoading}
          />
          <Button
            variant="ghost"
            size="icon"
            onClick={() => removeItem(item.key)}
            disabled={isLoading}
            aria-label={`${item.productName} aus dem Warenkorb entfernen`}
            className="text-text-secondary hover:text-error"
          >
            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M12 4L4 12M4 4l8 8" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
            </svg>
          </Button>
        </div>
      </div>

      {/* Zeilenpreis — unveraendert */}
      <div className="flex-shrink-0 text-right">
        <p className="font-medium tabular-nums">{item.subtotal}</p>
        {item.quantity > 1 && (
          <p className="text-xs text-text-secondary tabular-nums mt-1">{item.price} / Stk.</p>
        )}
      </div>
    </CardContent>
  </Card>
)
```

### Button Migration: VariantSelectorSize

```typescript
// frontend/components/product/variant-selector-size.tsx — migrierter Button (Auszug)
import { Button } from '@/components/ui/button'

// ... (Props unveraendert) ...

{sizes.map(({ value, available }) => {
  const isSelected = selectedSize === value
  return (
    <Button
      key={value}
      type="button"
      variant={isSelected ? 'default' : 'outline'}
      onClick={() => available && onSizeSelect(value)}
      aria-pressed={isSelected}
      aria-disabled={!available}
      disabled={!available}
      className={!available ? 'opacity-50 cursor-not-allowed' : undefined}
    >
      {value}
    </Button>
  )
})}
```

### Button-as-Link Migration: HeroBlock

```typescript
// frontend/components/blocks/hero-block.tsx — migrierter CTA (Auszug)
import Link from 'next/link'
import { Button } from '@/components/ui/button'

// ... (Props und section unveraendert) ...

<Button asChild>
  <Link href={ctaLink}>
    {ctaText}
  </Link>
</Button>
```

### Button-as-Link Migration: FilterChips

```typescript
// frontend/components/category/filter-chips.tsx — migrierte Chips (Auszug)
import Link from 'next/link'
import { Button } from '@/components/ui/button'

// Aktiver Chip (currentSlug === 'alle'):
<Button asChild className="rounded-full flex-shrink-0">
  <Link
    href="/produkte"
    aria-current="page"
  >
    Alle
  </Link>
</Button>

// Inaktiver Chip:
<Button variant="secondary" asChild className="rounded-full flex-shrink-0">
  <Link href="/produkte">
    Alle
  </Link>
</Button>

// Dynamische Variante (aus map):
<Button
  variant={category.slug === currentSlug ? 'default' : 'secondary'}
  asChild
  className="rounded-full flex-shrink-0"
>
  <Link
    key={category.id}
    href={`/kategorie/${category.slug}`}
    aria-current={category.slug === currentSlug ? 'page' : undefined}
  >
    {category.name}
  </Link>
</Button>
```

### Button Migration: MobileMenu

```typescript
// frontend/components/layout/mobile-menu.tsx — migrierter Hamburger (Auszug)
import { Button } from '@/components/ui/button'
// SheetTrigger wraps Button direkt als Child — Button ist NICHT der asChild-Consumer hier,
// SheetTrigger nutzt asChild um das Kind-Element als Trigger zu registrieren.
<SheetTrigger asChild>
  <Button
    variant="ghost"
    size="icon"
    aria-label="Menü öffnen"
  >
    {/* SVG-Icon unveraendert */}
    <svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none"
      stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <line x1="3" y1="6" x2="21" y2="6" />
      <line x1="3" y1="12" x2="21" y2="12" />
      <line x1="3" y1="18" x2="21" y2="18" />
    </svg>
  </Button>
</SheetTrigger>
// Entfernt: manuelle min-h, min-w, focus-ring und hover-Styles — Button-Primitive liefert diese
```

> **Pattern-Hinweis:** `<SheetTrigger asChild>` konsumiert seinen Child als Trigger. `<Button>` ist das Child — daher `asChild` auf `SheetTrigger`, NICHT auf `Button`. `<Button>` wird normal gerendert. Die bestehende `aria-label="Menü öffnen"` bleibt erhalten.

### Button-as-Link Migration: EmptyCart

```typescript
// frontend/components/cart/empty-cart.tsx — migrierter CTA (Auszug)
import Link from 'next/link'
import { Button } from '@/components/ui/button'

// ... (Wrapper-Div und Icon unveraendert) ...

<Button asChild>
  <Link href="/kategorie/alle">
    Weiter shoppen
  </Link>
</Button>
// Entfernt: inline style={{ touchAction: 'manipulation' }} — Button-Primitive hat
// [touch-action:manipulation] bereits in den Base-Styles via Tailwind-Klasse
```

### Card Migration: ProductCard

```typescript
// frontend/components/product/product-card.tsx — migrierte Struktur
import Image from 'next/image'
import Link from 'next/link'
import { Card, CardContent } from '@/components/ui/card'
import type { ProductCardData } from '@/lib/graphql/types'

export function ProductCard({ product }: ProductCardProps) {
  const isSoldOut = product.stockStatus === 'OUT_OF_STOCK'

  return (
    <Card variant="interactive" asChild>
      <Link href={`/produkt/${product.slug}`} className="group block">
        {/* Produktbild — unveraendert */}
        <div className="relative aspect-square bg-surface-elevated overflow-hidden">
          {product.image ? (
            <Image
              src={product.image.sourceUrl}
              alt={product.image.altText || product.name}
              fill
              sizes="(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 25vw"
              className="object-cover group-hover:scale-105 transition-transform duration-300"
            />
          ) : (
            <div className="w-full h-full flex items-center justify-center text-text-secondary text-sm">
              Kein Bild
            </div>
          )}
          {isSoldOut && (
            <div className="absolute inset-0 bg-overlay flex items-center justify-center">
              <span className="text-white font-medium text-sm px-3 py-1 bg-overlay rounded">
                Ausverkauft
              </span>
            </div>
          )}
        </div>

        {/* Produktinfos in CardContent */}
        <CardContent className="p-3">
          <h3 className="text-sm font-medium text-text-primary line-clamp-2 min-w-0">
            {product.name}
          </h3>
          <p className="mt-1 text-sm font-semibold text-text-primary tabular-nums">
            {product.price ?? '—'}
          </p>
        </CardContent>
      </Link>
    </Card>
  )
}
```

### Badge Migration: CartIcon

```typescript
// frontend/components/layout/cart-icon.tsx — migrierter Badge (Auszug)
import { Badge } from '@/components/ui/badge'

// ... (Link und SVG unveraendert) ...

{itemCount > 0 && (
  <Badge
    aria-hidden="true"
    className="absolute -top-1 -right-1 min-w-[1.125rem] h-[1.125rem] px-1 text-xs font-bold tabular-nums leading-none"
  >
    {itemCount > 99 ? '99+' : itemCount}
  </Badge>
)}
```

---

## Links

- Architecture: `specs/phase-0/2026-02-24-component-primitive-migration/architecture.md`
- Discovery: `specs/phase-0/2026-02-24-component-primitive-migration/discovery.md`
- shadcn/ui Button Primitive: `frontend/components/ui/button.tsx`
- shadcn/ui Card Primitive: `frontend/components/ui/card.tsx`
- shadcn/ui Badge Primitive: `frontend/components/ui/badge.tsx`

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert.**

<!-- DELIVERABLES_START -->
### Dependency Pinning
- [ ] `frontend/package.json` — `@radix-ui/react-slot` von `"latest"` auf `"^1.2.4"` gepinnt
- [ ] `frontend/package.json` — `@radix-ui/react-dialog` von `"latest"` auf `"^1.1.15"` gepinnt
- [ ] `frontend/package.json` — `tw-animate-css` von `"latest"` auf installierte Version gepinnt (aus `pnpm-lock.yaml`)

### UI Primitives (Erweiterung)
- [ ] `frontend/components/ui/card.tsx` — `asChild` Prop hinzugefügt (Slot Import + Comp-Pattern, 3 Zeilen)

### Button Migration (11 Patterns)
- [ ] `frontend/components/product/add-to-cart-button.tsx` — `<button>` → `<Button isLoading>` + Success-State via `cn()`
- [ ] `frontend/components/cart/cart-summary.tsx` — `<button>` → `<Button aria-label="Zur Kasse">` + `<Card><CardContent><CardFooter>` Wrapper
- [ ] `frontend/components/cart/quantity-stepper.tsx` — 2× `<button>` → `<Button variant="outline" size="icon" className="w-8 h-8">`
- [ ] `frontend/components/cart/cart-item-row.tsx` — Remove-`<button>` → `<Button variant="ghost" size="icon">` + outer `<div>` → `<Card><CardContent>`
- [ ] `frontend/components/product/variant-selector-size.tsx` — `<button>` → `<Button variant={isSelected ? "default" : "outline"}>` + `aria-pressed`
- [ ] `frontend/components/layout/mobile-menu.tsx` — SheetTrigger-`<button>` → `<Button variant="ghost" size="icon">`
- [ ] `frontend/app/warenkorb/cart-page-client.tsx` — Error-Close-`<button>` → `<Button variant="ghost" size="icon" className="text-error hover:text-error">`
- [ ] `frontend/components/product/product-image-gallery.tsx` — Thumbnail-`<button>` → `<Button variant="ghost" size="icon" className="w-18 h-18 ...">`

### Button-als-Link Migration (4 Patterns)
- [ ] `frontend/components/blocks/hero-block.tsx` — CTA-`<Link>` → `<Button asChild><Link>`
- [ ] `frontend/components/cart/empty-cart.tsx` — CTA-`<Link>` → `<Button asChild><Link>`
- [ ] `frontend/components/category/filter-chips.tsx` — 2× `<Link>` → `<Button asChild className="rounded-full">` (default/secondary Variant)

### Card Composition (5 Patterns)
- [ ] `frontend/components/product/product-card.tsx` — `<Link>` → `<Card variant="interactive" asChild><Link>` + `<CardContent className="p-3">`
- [ ] `frontend/components/blocks/category-showcase-block.tsx` — `<Link>` → `<Card variant="interactive" asChild><Link>`
- [ ] `frontend/app/produkt/[slug]/page.tsx` — Beschreibungs-`<section>` → `<Card>` + `<CardHeader>` + `<CardTitle>` + `<CardContent>`
- [ ] `frontend/components/cart/cart-item-row.tsx` — **Card-Aspekt:** outer `<div>` → `<Card><CardContent>` (zusätzlich zu Button-Migration des Remove-Buttons — Code Example in Section "Card + Button Migration: CartItemRow")
- [ ] `frontend/components/cart/cart-summary.tsx` — **Card-Aspekt:** kein vorheriger Card-Wrapper → `<Card><CardContent><CardFooter>` (zusätzlich zu Button-Migration des Checkout-Buttons — Code Example in Section "Card + Button Migration: CartSummary")

### Badge Composition (1 Pattern)
- [ ] `frontend/components/layout/cart-icon.tsx` — `<span>` → `<Badge variant="default" className="absolute -top-1 -right-1 ...">`

### Tests
- [ ] `frontend/tests/slices/component-primitive-migration/slice-01-component-primitive-composition.test.ts` — Neuer Test-File mit Tests für alle 15 migrierten Patterns (vollständige Implementierung der `it.todo` Testfälle)
- [ ] Alle bestehenden Tests in `frontend/tests/slices/` angepasst (DOM-Selektoren auf `data-slot` + `role` umgestellt wo nötig)
- [ ] `pnpm test` — alle Tests grün (0 Failures)
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind **Pflicht**
- Der Stop-Hook prüft automatisch ob alle Dateien existieren
- Reihenfolge: 1. Dependency Pinning → 2. Card asChild → 3. Button Migrations → 4. Card Compositions → 5. Badge → 6. Tests anpassen → 7. Neue Tests schreiben
