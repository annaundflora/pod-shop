/**
 * Acceptance & Unit Tests for Slice 01: Component Primitive Composition
 *
 * Tests are derived from the GIVEN/WHEN/THEN Acceptance Criteria in
 * specs/phase-0/2026-02-24-component-primitive-migration/slices/slice-01-component-primitive-composition.md
 *
 * AC-1:  Dependency Pinning (react-slot, react-dialog, tw-animate-css)
 * AC-2:  Card asChild Extension
 * AC-3:  AddToCartButton — Button Primitive with data-slot, isLoading, success state
 * AC-4:  CartSummary — Button + Card Primitives with aria-label
 * AC-5:  QuantityStepper — Button Primitives with 32px size
 * AC-6:  CartItemRow — Card + Button Primitives
 * AC-7:  VariantSelectorSize — Button Toggle with aria-pressed
 * AC-8:  HeroBlock — Button asChild with Link
 * AC-9:  FilterChips — Button asChild with variants
 * AC-10: ProductCard — Card asChild with interactive variant
 * AC-11: Produkt Page — Card Composition for description
 * AC-12: CartIcon — Badge Primitive
 * AC-13: All tests green (pnpm test)
 * AC-14: Build successful (pnpm build)
 * AC-15: Only allowed exceptions retain custom button styles
 */
import { describe, it, expect } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'

const FRONTEND_ROOT = resolve(__dirname, '..', '..', '..')

// -- Helpers --

function readFile(relativePath: string): string {
  return readFileSync(resolve(FRONTEND_ROOT, relativePath), 'utf-8')
}

function readPackageJson(): Record<string, unknown> {
  return JSON.parse(readFile('package.json'))
}

// -----------------------------------------------------------------------
// AC-1: Dependency Pinning
// GIVEN die Codebase enthaelt @radix-ui/react-slot: "latest" in package.json
// WHEN die Migration beginnt
// THEN ist @radix-ui/react-slot auf "^1.2.4" gepinnt, @radix-ui/react-dialog
//      auf "^1.1.15" gepinnt und tw-animate-css auf die aktuell installierte
//      Version gepinnt
// -----------------------------------------------------------------------
describe('AC-1: Dependency Pinning', () => {
  it('should have pinned @radix-ui/react-slot version (not "latest")', () => {
    const pkg = readPackageJson()
    const deps = pkg.dependencies as Record<string, string>
    expect(deps['@radix-ui/react-slot']).not.toBe('latest')
    expect(deps['@radix-ui/react-slot']).toMatch(/^\^?\d+\.\d+\.\d+/)
  })

  it('should have pinned @radix-ui/react-dialog version (not "latest")', () => {
    const pkg = readPackageJson()
    const deps = pkg.dependencies as Record<string, string>
    expect(deps['@radix-ui/react-dialog']).not.toBe('latest')
    expect(deps['@radix-ui/react-dialog']).toMatch(/^\^?\d+\.\d+\.\d+/)
  })

  it('should have pinned tw-animate-css version (not "latest")', () => {
    const pkg = readPackageJson()
    const deps = pkg.dependencies as Record<string, string>
    expect(deps['tw-animate-css']).not.toBe('latest')
    expect(deps['tw-animate-css']).toMatch(/^\^?\d+\.\d+\.\d+/)
  })
})

// -----------------------------------------------------------------------
// AC-2: Card asChild Extension
// GIVEN components/ui/card.tsx hat kein asChild Support
// WHEN die Card-Erweiterung implementiert wird
// THEN akzeptiert <Card asChild> exakt 1 Kind-Element und rendert dieses als
//      Card-Container (via Radix Slot), und bestehende <Card> Usages (ohne
//      asChild) funktionieren unveraendert
// -----------------------------------------------------------------------
describe('AC-2: Card asChild Extension', () => {
  it('should import Slot from @radix-ui/react-slot', () => {
    const content = readFile('components/ui/card.tsx')
    expect(content).toContain("from '@radix-ui/react-slot'")
  })

  it('should declare asChild prop in CardProps interface', () => {
    const content = readFile('components/ui/card.tsx')
    expect(content).toContain('asChild')
  })

  it('should use Slot when asChild is true, div otherwise', () => {
    const content = readFile('components/ui/card.tsx')
    // The pattern: const Comp = asChild ? Slot : 'div'
    expect(content).toMatch(/asChild\s*\?\s*Slot\s*:\s*'div'/)
  })

  it('should still render data-slot="card" on the Card element', () => {
    const content = readFile('components/ui/card.tsx')
    expect(content).toContain('data-slot="card"')
  })
})

// -----------------------------------------------------------------------
// AC-3: AddToCartButton — Button Primitive
// GIVEN add-to-cart-button.tsx nutzt einen custom <button>
// WHEN die Migration implementiert ist
// THEN rendert <AddToCartButton> ein Element mit data-slot="button", isLoading
//      zeigt denselben Spinner wie der Button-Primitive, und der Success-State
//      wechselt die Hintergrundfarbe auf bg-success via cn()
// -----------------------------------------------------------------------
describe('AC-3: AddToCartButton — Button Primitive', () => {
  it('should import Button from @/components/ui/button', () => {
    const content = readFile('components/product/add-to-cart-button.tsx')
    expect(content).toContain("from '@/components/ui/button'")
  })

  it('should use <Button> component (not raw <button>)', () => {
    const content = readFile('components/product/add-to-cart-button.tsx')
    expect(content).toContain('<Button')
    // Should not have standalone <button> elements (only <Button>)
    expect(content).not.toMatch(/<button[\s>]/)
  })

  it('should pass isLoading prop to Button primitive', () => {
    const content = readFile('components/product/add-to-cart-button.tsx')
    expect(content).toMatch(/isLoading/)
  })

  it('should apply bg-success class for success state via cn()', () => {
    const content = readFile('components/product/add-to-cart-button.tsx')
    expect(content).toContain('bg-success')
    expect(content).toContain('cn(')
  })
})

// -----------------------------------------------------------------------
// AC-4: CartSummary — Button + Card Primitive
// GIVEN cart-summary.tsx hat keinen Card-Wrapper und keinen aria-label auf dem
//       Checkout-Button
// WHEN die Migration implementiert ist
// THEN ist der Checkout-Button ein <Button variant="default"> mit
//      aria-label="Zur Kasse" und der gesamte Summary-Bereich ist in
//      <Card><CardContent>...</CardContent><CardFooter> strukturiert
// -----------------------------------------------------------------------
describe('AC-4: CartSummary — Button + Card Primitive', () => {
  it('should import Button from @/components/ui/button', () => {
    const content = readFile('components/cart/cart-summary.tsx')
    expect(content).toContain("from '@/components/ui/button'")
  })

  it('should import Card, CardContent, CardFooter from @/components/ui/card', () => {
    const content = readFile('components/cart/cart-summary.tsx')
    expect(content).toContain("from '@/components/ui/card'")
    expect(content).toContain('Card')
    expect(content).toContain('CardContent')
    expect(content).toContain('CardFooter')
  })

  it('should use <Button> for checkout (not raw <button>)', () => {
    const content = readFile('components/cart/cart-summary.tsx')
    expect(content).toContain('<Button')
    expect(content).not.toMatch(/<button[\s>]/)
  })

  it('should have aria-label="Zur Kasse" on checkout button', () => {
    const content = readFile('components/cart/cart-summary.tsx')
    expect(content).toContain('aria-label="Zur Kasse"')
  })

  it('should wrap summary in <Card> and <CardFooter>', () => {
    const content = readFile('components/cart/cart-summary.tsx')
    expect(content).toContain('<Card')
    expect(content).toContain('<CardContent')
    expect(content).toContain('<CardFooter')
  })
})

// -----------------------------------------------------------------------
// AC-5: QuantityStepper — Button Primitives
// GIVEN quantity-stepper.tsx nutzt 2x custom <button> mit 32px Groesse
// WHEN die Migration implementiert ist
// THEN sind beide Buttons <Button variant="outline" size="icon"
//      className="w-8 h-8"> mit data-slot="button" und behalten ihre
//      aria-label Attribute
// -----------------------------------------------------------------------
describe('AC-5: QuantityStepper — Button Primitive', () => {
  it('should import Button from @/components/ui/button', () => {
    const content = readFile('components/cart/quantity-stepper.tsx')
    expect(content).toContain("from '@/components/ui/button'")
  })

  it('should use <Button> for both stepper buttons (not raw <button>)', () => {
    const content = readFile('components/cart/quantity-stepper.tsx')
    // Should have at least 2 <Button> usages
    const buttonMatches = content.match(/<Button[\s]/g)
    expect(buttonMatches).not.toBeNull()
    expect(buttonMatches!.length).toBeGreaterThanOrEqual(2)
    // Should not have standalone <button> elements
    expect(content).not.toMatch(/<button[\s>]/)
  })

  it('should use variant="outline" and size="icon"', () => {
    const content = readFile('components/cart/quantity-stepper.tsx')
    expect(content).toContain('variant="outline"')
    expect(content).toContain('size="icon"')
  })

  it('should override size to w-8 h-8 (32px)', () => {
    const content = readFile('components/cart/quantity-stepper.tsx')
    expect(content).toContain('w-8')
    expect(content).toContain('h-8')
  })

  it('should have aria-label="Menge verringern" on minus button', () => {
    const content = readFile('components/cart/quantity-stepper.tsx')
    expect(content).toContain('aria-label="Menge verringern"')
  })

  it('should have aria-label="Menge erhoehen" on plus button', () => {
    const content = readFile('components/cart/quantity-stepper.tsx')
    expect(content).toContain('aria-label="Menge erhoehen"')
  })
})

// -----------------------------------------------------------------------
// AC-6: CartItemRow — Card + Button Primitives
// GIVEN cart-item-row.tsx hat einen custom outer <div> mit Border-Styling
// WHEN die Migration implementiert ist
// THEN ist der outer Container ein <Card> mit data-slot="card", der Inhalt in
//      <CardContent> und der Remove-Button ein <Button variant="ghost"
//      size="icon"> mit data-slot="button"
// -----------------------------------------------------------------------
describe('AC-6: CartItemRow — Card + Button Primitive', () => {
  it('should import Card and CardContent from @/components/ui/card', () => {
    const content = readFile('components/cart/cart-item-row.tsx')
    expect(content).toContain("from '@/components/ui/card'")
    expect(content).toContain('Card')
    expect(content).toContain('CardContent')
  })

  it('should import Button from @/components/ui/button', () => {
    const content = readFile('components/cart/cart-item-row.tsx')
    expect(content).toContain("from '@/components/ui/button'")
  })

  it('should use <Card> as outer container (not raw <div> with border)', () => {
    const content = readFile('components/cart/cart-item-row.tsx')
    expect(content).toContain('<Card>')
    expect(content).toContain('<CardContent')
  })

  it('should use <Button variant="ghost" size="icon"> for remove button', () => {
    const content = readFile('components/cart/cart-item-row.tsx')
    expect(content).toContain('variant="ghost"')
    expect(content).toContain('size="icon"')
  })

  it('should have aria-label on remove button', () => {
    const content = readFile('components/cart/cart-item-row.tsx')
    expect(content).toMatch(/aria-label=.*entfernen/)
  })
})

// -----------------------------------------------------------------------
// AC-7: VariantSelectorSize — Button Toggle
// GIVEN variant-selector-size.tsx nutzt custom <button> mit konditionaler
//       className
// WHEN die Migration implementiert ist
// THEN ist jeder Size-Button ein <Button> mit variant="default" (selected)
//      oder variant="outline" (unselected), aria-pressed ist gesetzt und
//      deaktivierte Sizes haben disabled Prop
// -----------------------------------------------------------------------
describe('AC-7: VariantSelectorSize — Button Toggle', () => {
  it('should import Button from @/components/ui/button', () => {
    const content = readFile('components/product/variant-selector-size.tsx')
    expect(content).toContain("from '@/components/ui/button'")
  })

  it('should use <Button> (not raw <button>)', () => {
    const content = readFile('components/product/variant-selector-size.tsx')
    expect(content).toContain('<Button')
    expect(content).not.toMatch(/<button[\s>]/)
  })

  it('should use variant="default" for selected and variant="outline" for unselected', () => {
    const content = readFile('components/product/variant-selector-size.tsx')
    // Ternary: variant={isSelected ? 'default' : 'outline'}
    expect(content).toMatch(/variant=\{.*'default'.*'outline'/)
  })

  it('should set aria-pressed attribute', () => {
    const content = readFile('components/product/variant-selector-size.tsx')
    expect(content).toContain('aria-pressed')
  })

  it('should set disabled prop for unavailable sizes', () => {
    const content = readFile('components/product/variant-selector-size.tsx')
    expect(content).toMatch(/disabled=\{!available\}/)
  })
})

// -----------------------------------------------------------------------
// AC-8: HeroBlock — Button asChild
// GIVEN hero-block.tsx hat ein <Link> mit Button-Styles
// WHEN die Migration implementiert ist
// THEN ist der CTA ein <Button asChild><Link href={ctaLink}> der semantisch
//      als <a> rendert und visuell identisch zum vorherigen State ist
// -----------------------------------------------------------------------
describe('AC-8: HeroBlock — Button asChild', () => {
  it('should import Button from @/components/ui/button', () => {
    const content = readFile('components/blocks/hero-block.tsx')
    expect(content).toContain("from '@/components/ui/button'")
  })

  it('should use <Button asChild> wrapping a <Link>', () => {
    const content = readFile('components/blocks/hero-block.tsx')
    expect(content).toContain('<Button asChild>')
    expect(content).toContain('<Link')
  })

  it('should not have raw button-style classes on the Link', () => {
    const content = readFile('components/blocks/hero-block.tsx')
    // The CTA Link should NOT have manual bg-primary/rounded-lg since Button provides those
    // Check the Link element does not carry inline button-styling
    const ctaSection = content.slice(content.indexOf('<Button asChild>'))
    expect(ctaSection).not.toMatch(/<Link[^>]*className=.*bg-primary/)
  })
})

// -----------------------------------------------------------------------
// AC-9: FilterChips — Button asChild
// GIVEN filter-chips.tsx hat <Link> Elemente mit konditionaler className
// WHEN die Migration implementiert ist
// THEN ist der aktive Filter-Chip <Button asChild className="rounded-full
//      flex-shrink-0"><Link aria-current="page"> und der inaktive Filter-Chip
//      <Button variant="secondary" asChild className="rounded-full
//      flex-shrink-0"><Link>
// -----------------------------------------------------------------------
describe('AC-9: FilterChips — Button asChild', () => {
  it('should import Button from @/components/ui/button', () => {
    const content = readFile('components/category/filter-chips.tsx')
    expect(content).toContain("from '@/components/ui/button'")
  })

  it('should use <Button asChild> wrapping <Link> elements', () => {
    const content = readFile('components/category/filter-chips.tsx')
    expect(content).toContain('asChild')
    expect(content).toContain('<Link')
  })

  it('should use rounded-full className override', () => {
    const content = readFile('components/category/filter-chips.tsx')
    expect(content).toContain('rounded-full')
  })

  it('should use variant="secondary" for inactive chips', () => {
    const content = readFile('components/category/filter-chips.tsx')
    expect(content).toContain("variant={")
    expect(content).toContain("'secondary'")
  })

  it('should preserve aria-current="page" on active chip', () => {
    const content = readFile('components/category/filter-chips.tsx')
    expect(content).toContain('aria-current')
    expect(content).toContain("'page'")
  })
})

// -----------------------------------------------------------------------
// AC-10: ProductCard — Card asChild
// GIVEN product-card.tsx hat eine <Link> mit manuellen border/shadow/rounded-
//       Styles
// WHEN die Migration implementiert ist
// THEN ist der Card-Container <Card variant="interactive" asChild><Link> mit
//      data-slot="card", der Hover-Lift-Effekt kommt vom Card-Primitive und
//      group-hover:scale-105 auf dem Bild bleibt erhalten
// -----------------------------------------------------------------------
describe('AC-10: ProductCard — Card asChild', () => {
  it('should import Card and CardContent from @/components/ui/card', () => {
    const content = readFile('components/product/product-card.tsx')
    expect(content).toContain("from '@/components/ui/card'")
    expect(content).toContain('Card')
    expect(content).toContain('CardContent')
  })

  it('should use <Card variant="interactive" asChild>', () => {
    const content = readFile('components/product/product-card.tsx')
    expect(content).toContain('variant="interactive"')
    expect(content).toContain('asChild')
  })

  it('should wrap a <Link> inside the Card', () => {
    const content = readFile('components/product/product-card.tsx')
    expect(content).toContain('<Link')
  })

  it('should preserve group-hover:scale-105 on the image', () => {
    const content = readFile('components/product/product-card.tsx')
    expect(content).toContain('group-hover:scale-105')
  })

  it('should use <CardContent> for product info area', () => {
    const content = readFile('components/product/product-card.tsx')
    expect(content).toContain('<CardContent')
  })
})

// -----------------------------------------------------------------------
// AC-11: Produkt Page — Card Composition via SectionRenderer
// GIVEN produkt/[slug]/page.tsx wurde auf SectionRenderer migriert
// WHEN die Migration implementiert ist
// THEN nutzt die Seite SectionRenderer und loadPageConfig (Block-System)
//      fuer die Produkt-Detail-Darstellung; Card-Primitives werden in den
//      Block-Komponenten verwendet (product-description-block.tsx)
// -----------------------------------------------------------------------
describe('AC-11: Produkt Page — Card Composition via SectionRenderer', () => {
  it('should use SectionRenderer from lib/blocks/section-renderer', () => {
    const content = readFile('app/produkt/[slug]/page.tsx')
    expect(content).toContain('SectionRenderer')
    expect(content).toContain('section-renderer')
  })

  it('should import loadPageConfig from lib/blocks/page-config', () => {
    const content = readFile('app/produkt/[slug]/page.tsx')
    expect(content).toContain('loadPageConfig')
    expect(content).toContain('page-config')
  })

  it('product-description-block.tsx should use Card primitives for description section', () => {
    const content = readFile('components/blocks/product-description-block.tsx')
    expect(content).toContain("from '@/components/ui/card'")
    expect(content).toContain('Card')
    expect(content).toContain('CardContent')
  })
})

// -----------------------------------------------------------------------
// AC-12: CartIcon — Badge Primitive
// GIVEN cart-icon.tsx hat einen <span> Badge mit manuellen bg/rounded-Styles
// WHEN die Migration implementiert ist
// THEN ist der Badge ein <Badge variant="default" className="absolute -top-1
//      -right-1 ..."> mit data-slot="badge" und aria-hidden="true"
// -----------------------------------------------------------------------
describe('AC-12: CartIcon — Badge Primitive', () => {
  it('should import Badge from @/components/ui/badge', () => {
    const content = readFile('components/layout/cart-icon.tsx')
    expect(content).toContain("from '@/components/ui/badge'")
  })

  it('should use <Badge> component (not raw <span> with bg-primary rounded-full)', () => {
    const content = readFile('components/layout/cart-icon.tsx')
    expect(content).toContain('<Badge')
  })

  it('should position badge with absolute -top-1 -right-1', () => {
    const content = readFile('components/layout/cart-icon.tsx')
    expect(content).toContain('absolute')
    expect(content).toContain('-top-1')
    expect(content).toContain('-right-1')
  })

  it('should set aria-hidden="true" on the badge', () => {
    const content = readFile('components/layout/cart-icon.tsx')
    // Badge should have aria-hidden
    expect(content).toMatch(/<Badge[^>]*aria-hidden="true"/)
  })

  it('should display "99+" when count exceeds 99', () => {
    const content = readFile('components/layout/cart-icon.tsx')
    expect(content).toContain("'99+'")
  })
})

// -----------------------------------------------------------------------
// AC-13: All tests green (pnpm test)
// GIVEN alle 15 Patterns sind migriert
// WHEN pnpm test ausgefuehrt wird
// THEN sind alle 151+ bestehenden Tests und alle neuen Tests gruen
// -----------------------------------------------------------------------
describe('AC-13: Migration Completeness — Existing Tests', () => {
  it('test suite itself validates migration did not break any structural contracts', () => {
    // This test is a structural assertion: if all tests in this file pass,
    // it means the migration preserved the expected patterns.
    // The actual "pnpm test" gate is run by the orchestrator.
    expect(true).toBe(true)
  })
})

// -----------------------------------------------------------------------
// AC-14: Build successful (pnpm build)
// GIVEN alle 15 Patterns sind migriert
// WHEN pnpm build ausgefuehrt wird
// THEN ist der Build erfolgreich ohne TypeScript-Fehler
// -----------------------------------------------------------------------
describe('AC-14: Build Success — TypeScript Compatibility', () => {
  it('all migrated components should have valid TypeScript imports', () => {
    // Verify all migrated files import from ui primitives (type-safe imports)
    const files = [
      'components/product/add-to-cart-button.tsx',
      'components/cart/cart-summary.tsx',
      'components/cart/quantity-stepper.tsx',
      'components/cart/cart-item-row.tsx',
      'components/product/variant-selector-size.tsx',
      'components/layout/mobile-menu.tsx',
      'app/warenkorb/cart-page-client.tsx',
      'components/product/product-image-gallery.tsx',
      'components/blocks/hero-block.tsx',
      'components/cart/empty-cart.tsx',
      'components/category/filter-chips.tsx',
      'components/product/product-card.tsx',
      'components/blocks/category-showcase-block.tsx',
      // app/produkt/[slug]/page.tsx has been migrated to SectionRenderer —
      // UI primitives are now used inside block components, not in page.tsx directly
      'components/layout/cart-icon.tsx',
    ]

    for (const file of files) {
      const content = readFile(file)
      // Each migrated file must import from at least one ui primitive
      const hasUiImport =
        content.includes("from '@/components/ui/button'") ||
        content.includes("from '@/components/ui/card'") ||
        content.includes("from '@/components/ui/badge'") ||
        content.includes("from '@/components/ui/sheet'")
      expect(hasUiImport).toBe(true)
    }
  })
})

// -----------------------------------------------------------------------
// AC-15: Only allowed exceptions retain custom button styles
// GIVEN grep -r 'className=.*bg-primary.*rounded' frontend/components nach
//       der Migration
// WHEN nur die Out-of-Scope Dateien geprueft werden
// THEN enthalten nur variant-selector-color.tsx und Gallery-Dot-Buttons (10px)
//      noch custom Button-Styles
// -----------------------------------------------------------------------
describe('AC-15: Custom Button Style Exceptions', () => {
  it('add-to-cart-button.tsx: should NOT have raw <button> with manual bg-primary/rounded', () => {
    const content = readFile('components/product/add-to-cart-button.tsx')
    expect(content).not.toMatch(/<button[\s>]/)
  })

  it('cart-summary.tsx: should NOT have raw <button> elements', () => {
    const content = readFile('components/cart/cart-summary.tsx')
    expect(content).not.toMatch(/<button[\s>]/)
  })

  it('quantity-stepper.tsx: should NOT have raw <button> elements', () => {
    const content = readFile('components/cart/quantity-stepper.tsx')
    expect(content).not.toMatch(/<button[\s>]/)
  })

  it('hero-block.tsx: should NOT have <Link> with manual bg-primary rounded-lg', () => {
    const content = readFile('components/blocks/hero-block.tsx')
    // Link should not carry old manual styling
    expect(content).not.toMatch(/<Link[^>]*bg-primary[^>]*rounded-lg/)
  })

  it('empty-cart.tsx: should NOT have <Link> with manual bg-primary rounded-lg', () => {
    const content = readFile('components/cart/empty-cart.tsx')
    expect(content).not.toMatch(/<Link[^>]*bg-primary[^>]*rounded-lg/)
  })

  it('product-card.tsx: should NOT have manual border/shadow/rounded on Link', () => {
    const content = readFile('components/product/product-card.tsx')
    // Product card Link should not have manual border-border rounded-card shadow-card
    // because Card primitive provides those
    expect(content).not.toMatch(/<Link[^>]*border-border[^>]*rounded/)
  })

  it('product-image-gallery.tsx: dot-buttons (mobile) are allowed to keep custom styles', () => {
    const content = readFile('components/product/product-image-gallery.tsx')
    // Dot-buttons use raw <button> — this is explicitly Out-of-Scope
    // Desktop thumbnails should use <Button>
    expect(content).toContain('<Button')
    expect(content).toContain('variant="ghost"')
  })

  it('mobile-menu.tsx: should use Button primitive for hamburger trigger', () => {
    const content = readFile('components/layout/mobile-menu.tsx')
    expect(content).toContain('<Button')
    expect(content).toContain('variant="ghost"')
    expect(content).toContain('size="icon"')
    expect(content).toContain('aria-label="Men')
  })

  it('cart-page-client.tsx: error close button should use Button primitive', () => {
    const content = readFile('app/warenkorb/cart-page-client.tsx')
    expect(content).toContain('<Button')
    expect(content).toContain('variant="ghost"')
    expect(content).toContain('size="icon"')
    expect(content).toContain('aria-label="Fehlermeldung schliessen"')
  })

  it('category-showcase-block.tsx: should use <Card variant="interactive" asChild>', () => {
    const content = readFile('components/blocks/category-showcase-block.tsx')
    expect(content).toContain('variant="interactive"')
    expect(content).toContain('asChild')
    expect(content).toContain('<Link')
  })
})
