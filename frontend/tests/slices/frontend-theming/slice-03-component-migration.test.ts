// tests/slices/frontend-theming/slice-03-component-migration.test.ts
import { describe, it, expect } from 'vitest'
import { existsSync, readFileSync, unlinkSync } from 'fs'
import { resolve } from 'path'
import { execSync } from 'child_process'

const FRONTEND_ROOT = resolve(__dirname, '..', '..', '..')

// ─── Helper ─────────────────────────────────────────────────────────────────

function readFile(relativePath: string): string {
  return readFileSync(resolve(FRONTEND_ROOT, relativePath), 'utf-8')
}

function fileExists(relativePath: string): boolean {
  return existsSync(resolve(FRONTEND_ROOT, relativePath))
}

// ─── AC-1: Keine hardcoded Farben in migrierten Dateien ─────────────────────

describe('AC-1: Keine hardcoded Tailwind-Farben in Components', () => {
  const HARDCODED_PATTERN = /\b(bg|text|border|ring|from|to|via)-(red|green|blue|black|white|purple|orange|emerald|pink|yellow|gray|slate|zinc)-\d{3}\b/

  it('cart-page-client.tsx: keine hardcoded Farben (bg-red-*, border-red-*, text-red-*)', () => {
    const content = readFile('app/warenkorb/cart-page-client.tsx')
    expect(HARDCODED_PATTERN.test(content)).toBe(false)
  })

  it('cart-page-client.tsx: keine bg-red-50, border-red-200, text-red-800', () => {
    const content = readFile('app/warenkorb/cart-page-client.tsx')
    expect(content).not.toContain('bg-red-')
    expect(content).not.toContain('border-red-')
    expect(content).not.toContain('text-red-')
  })

  it('cart-page-client.tsx: nutzt color-mix() Fallback statt bg-error/10', () => {
    const content = readFile('app/warenkorb/cart-page-client.tsx')
    expect(content).toContain('color-mix(in_oklch,var(--color-error)_10%,transparent)')
    expect(content).toContain('border-error')
    expect(content).toContain('text-error')
  })

  it('cart-item-row.tsx: keine hardcoded red-Farben', () => {
    const content = readFile('components/cart/cart-item-row.tsx')
    expect(content).not.toContain('red-600')
    expect(content).not.toContain('red-500')
  })

  it('cart-item-row.tsx: nutzt text-error und ring-error Tokens', () => {
    const content = readFile('components/cart/cart-item-row.tsx')
    expect(content).toContain('text-error')
    expect(content).toContain('ring-error')
  })

  it('add-to-cart-button.tsx: keine hardcoded bg-green-600', () => {
    const content = readFile('components/product/add-to-cart-button.tsx')
    expect(content).not.toContain('bg-green-')
    expect(content).not.toContain('ring-green-')
  })

  it('add-to-cart-button.tsx: nutzt bg-success Token im Erfolgs-State', () => {
    const content = readFile('components/product/add-to-cart-button.tsx')
    expect(content).toContain('bg-success')
  })

  it('product-card.tsx: keine hardcoded bg-black/50 oder bg-black/70', () => {
    const content = readFile('components/product/product-card.tsx')
    expect(content).not.toContain('bg-black/')
  })

  it('product-card.tsx: nutzt bg-overlay Token für Sold-Out Overlay', () => {
    const content = readFile('components/product/product-card.tsx')
    expect(content).toContain('bg-overlay')
  })

  it('cookie-consent-banner.tsx: keine hardcoded bg-white, nutzt bg-surface und bg-surface-elevated', () => {
    const content = readFile('components/layout/cookie-consent-banner.tsx')
    expect(content).not.toContain("'bg-white'")
    expect(content).not.toContain('"bg-white"')
    // Container nutzt bg-surface, Ablehnen-Button nutzt bg-surface-elevated
    expect(content).toContain('bg-surface')
    expect(content).toContain('bg-surface-elevated')
  })

  it('cart-page-client.tsx: nutzt color-mix() Fallback fuer Error-Hintergrund (kein bg-error/10)', () => {
    const content = readFile('app/warenkorb/cart-page-client.tsx')
    // bg-error/10 funktioniert nicht mit var()-Indirection in Tailwind v4 (@theme hat --color-error: var(--theme-color-error))
    // Daher wird der sichere color-mix() Fallback verwendet
    expect(content).toContain('color-mix(in_oklch,var(--color-error)_10%,transparent)')
  })
})

// ─── AC-4: --theme-color-error in generated-theme.css ist direkter oklch()-Wert ──

describe('AC-4: --theme-color-error in generated-theme.css ist direkter oklch()-Wert (kein var())', () => {
  const GENERATED_CSS_PATH = resolve(FRONTEND_ROOT, 'app/generated-theme.css')

  it('generated-theme.css: --theme-color-error ist direkter oklch()-Wert (kein var()-Verweis)', () => {
    // globals.css hat --color-error: var(--theme-color-error) (Indirektion gemaess Slice 1)
    // Der direkte oklch()-Wert liegt in generated-theme.css unter --theme-color-error
    // Dieser Test stellt sicher dass generated-theme.css den Token korrekt enthaelt
    // Datei wird inline generiert und sofort nach dem Read geloescht, um Race Conditions
    // mit slice-01's afterEach unlinkSync im parallelen Vitest-Lauf zu vermeiden.
    execSync('node scripts/generate-theme.mjs', { cwd: FRONTEND_ROOT })
    let content: string
    try {
      content = readFile('app/generated-theme.css')
    } finally {
      if (existsSync(GENERATED_CSS_PATH)) {
        unlinkSync(GENERATED_CSS_PATH)
      }
    }
    const errorTokenMatch = content.match(/--theme-color-error\s*:\s*([^;]+);/)
    expect(errorTokenMatch).not.toBeNull()
    if (errorTokenMatch) {
      const tokenValue = errorTokenMatch[1].trim()
      // Der Wert darf kein var() sein — generated-theme.css enthaelt nur direkte oklch()-Werte
      expect(tokenValue).not.toMatch(/^var\(/)
      // Der Wert muss oklch() sein
      expect(tokenValue).toMatch(/^oklch\(/)
    }
  })
})

// ─── AC-2: Runtime-Theme vollständig entfernt ────────────────────────────────

describe('AC-2: Runtime-Theme vollständig entfernt', () => {
  it('theme-switcher.tsx ist gelöscht', () => {
    expect(fileExists('components/layout/theme-switcher.tsx')).toBe(false)
  })

  it('contexts/theme-context.tsx ist gelöscht', () => {
    expect(fileExists('contexts/theme-context.tsx')).toBe(false)
  })

  it('lib/theme/themes.ts ist gelöscht', () => {
    expect(fileExists('lib/theme/themes.ts')).toBe(false)
  })

  it('layout.tsx: kein ThemeProvider Import', () => {
    const content = readFile('app/layout.tsx')
    expect(content).not.toContain('ThemeProvider')
    expect(content).not.toContain('theme-context')
  })

  it('layout.tsx: kein dangerouslySetInnerHTML Theme-Script', () => {
    const content = readFile('app/layout.tsx')
    expect(content).not.toContain('dangerouslySetInnerHTML')
    expect(content).not.toContain('pod-theme')
    expect(content).not.toContain('dataset.theme')
  })

  it('header.tsx: kein ThemeSwitcher Import', () => {
    const content = readFile('components/layout/header.tsx')
    expect(content).not.toContain('ThemeSwitcher')
    expect(content).not.toContain('theme-switcher')
  })
})

// ─── AC-8: Font-Variables in layout.tsx aktiviert ───────────────────────────

describe('AC-8: Font-Loading via next/font in layout.tsx', () => {
  it('layout.tsx: importiert headingFont und bodyFont aus lib/theme/fonts', () => {
    const content = readFile('app/layout.tsx')
    expect(content).toContain('headingFont')
    expect(content).toContain('bodyFont')
    expect(content).toContain('lib/theme/fonts')
  })

  it('layout.tsx: Font-Variable-Klassen sind auf <html> gesetzt', () => {
    const content = readFile('app/layout.tsx')
    expect(content).toContain('headingFont.variable')
    expect(content).toContain('bodyFont.variable')
  })
})

// ─── AC-9: NEXT_PUBLIC_SHOP_NAME Env-Variable ───────────────────────────────

describe('AC-9: NEXT_PUBLIC_SHOP_NAME als Shop-Name Quelle', () => {
  it('layout.tsx: nutzt NEXT_PUBLIC_SHOP_NAME für Metadata-Titel', () => {
    const content = readFile('app/layout.tsx')
    expect(content).toContain('NEXT_PUBLIC_SHOP_NAME')
  })

  it('header.tsx: nutzt NEXT_PUBLIC_SHOP_NAME für Logo-Text', () => {
    const content = readFile('components/layout/header.tsx')
    expect(content).toContain('NEXT_PUBLIC_SHOP_NAME')
  })

  it('footer.tsx: nutzt NEXT_PUBLIC_SHOP_NAME für Copyright', () => {
    const content = readFile('components/layout/footer.tsx')
    expect(content).toContain('NEXT_PUBLIC_SHOP_NAME')
  })

  it('.env.local.example: enthält NEXT_PUBLIC_SHOP_NAME', () => {
    const content = readFile('.env.local.example')
    expect(content).toContain('NEXT_PUBLIC_SHOP_NAME')
  })
})

// ─── AC-7: product-card-skeleton.tsx auf Skeleton migriert ──────────────────

describe('AC-7: ProductCardSkeleton auf shadcn/ui Skeleton migriert', () => {
  it('product-card-skeleton.tsx: importiert Skeleton aus components/ui/skeleton', () => {
    const content = readFile('components/product/product-card-skeleton.tsx')
    expect(content).toContain("from '@/components/ui/skeleton'")
  })

  it('product-card-skeleton.tsx: nutzt <Skeleton> Component (nicht inline animate-pulse Divs)', () => {
    const content = readFile('components/product/product-card-skeleton.tsx')
    expect(content).toContain('<Skeleton')
  })
})

// ─── AC-6: mobile-menu.tsx auf Sheet migriert ───────────────────────────────

describe('AC-6: MobileMenu auf shadcn/ui Sheet migriert', () => {
  it('mobile-menu.tsx: importiert Sheet aus components/ui/sheet', () => {
    const content = readFile('components/layout/mobile-menu.tsx')
    expect(content).toContain("from '@/components/ui/sheet'")
  })

  it('mobile-menu.tsx: nutzt SheetTrigger und SheetContent', () => {
    const content = readFile('components/layout/mobile-menu.tsx')
    expect(content).toContain('SheetTrigger')
    expect(content).toContain('SheetContent')
  })

  it('mobile-menu.tsx: kein manuelles isOpen useState für Drawer mehr', () => {
    const content = readFile('components/layout/mobile-menu.tsx')
    // State-Management übernimmt Radix UI Sheet
    expect(content).not.toContain("useState(false)")
  })

  it('mobile-menu.tsx: keine hardcoded bg-black/* mehr', () => {
    const content = readFile('components/layout/mobile-menu.tsx')
    expect(content).not.toContain('bg-black/')
  })

  it('mobile-menu.tsx: side="left" SheetContent', () => {
    const content = readFile('components/layout/mobile-menu.tsx')
    expect(content).toContain('side="left"')
  })
})

// ─── Shadow-Token Migration ──────────────────────────────────────────────────

describe('Shadow-Token Migration: keine hardcoded shadow-md/shadow-xl mehr', () => {
  it('product-card.tsx: kein hardcoded hover:shadow-md', () => {
    const content = readFile('components/product/product-card.tsx')
    // hover:shadow-md wurde ersetzt durch hover:shadow-[var(--shadow-card-hover)]
    expect(content).not.toContain('hover:shadow-md')
  })

  it('product-card.tsx: nutzt shadow-card-hover Token', () => {
    const content = readFile('components/product/product-card.tsx')
    expect(content).toContain('shadow-card-hover')
  })
})

// ─── Bestehende 151 Tests bleiben grün (Smoke-Check auf Datei-Existenz) ─────

describe('Bestehende Pod-Shop-MVP Tests — Datei-Existenz (Smoke)', () => {
  const existingTestFiles = [
    'tests/slices/pod-shop-mvp/slice-01-infrastruktur.test.ts',
    'tests/slices/pod-shop-mvp/slice-02-produktkatalog-frontend.test.ts',
    'tests/slices/pod-shop-mvp/slice-03-warenkorb-checkout-redirect.test.ts',
    'tests/slices/pod-shop-mvp/slice-04-rechtliches-rechnungen.test.ts',
    'tests/slices/pod-shop-mvp/slice-06-pinterest-tracking.test.ts',
    'tests/slices/pod-shop-mvp/slice-07-user-accounts.test.ts',
  ]

  for (const testFile of existingTestFiles) {
    it(`should have ${testFile}`, () => {
      expect(fileExists(testFile)).toBe(true)
    })
  }
})

// ─── Kern-Utility-Dateien nicht gelöscht ────────────────────────────────────

describe('Kern-Dateien existieren nach Migration', () => {
  const requiredFiles = [
    'app/layout.tsx',
    'app/page.tsx',
    'app/warenkorb/cart-page-client.tsx',
    'components/cart/cart-item-row.tsx',
    'components/cart/cart-summary.tsx',
    'components/cart/empty-cart.tsx',
    'components/cart/quantity-stepper.tsx',
    'components/product/product-card.tsx',
    'components/product/product-card-skeleton.tsx',
    'components/product/add-to-cart-button.tsx',
    'components/layout/header.tsx',
    'components/layout/footer.tsx',
    'components/layout/mobile-menu.tsx',
    'components/layout/cookie-consent-banner.tsx',
    'contexts/cart-context.tsx',
    'lib/theme/fonts.ts',
  ]

  for (const file of requiredFiles) {
    it(`should still have ${file}`, () => {
      expect(fileExists(file)).toBe(true)
    })
  }
})

// ─── Keine unerwünschten hardcoded shadow-* Utilities in Kern-Components ────

describe('Shadow-Utilities: keine shadow-sm/shadow-lg/shadow-xl in migrierten Components', () => {
  const componentsToCheck = [
    'components/product/product-card.tsx',
    'components/cart/cart-item-row.tsx',
    'components/cart/cart-summary.tsx',
    'components/cart/empty-cart.tsx',
    'components/cart/quantity-stepper.tsx',
    'components/layout/cookie-consent-banner.tsx',
    'components/layout/header.tsx',
    'components/layout/footer.tsx',
    'components/layout/mobile-menu.tsx',
    'app/warenkorb/cart-page-client.tsx',
  ]

  for (const componentPath of componentsToCheck) {
    it(`${componentPath}: keine hardcoded shadow-sm/shadow-md/shadow-lg/shadow-xl`, () => {
      const content = readFile(componentPath)
      // Erlaubt: shadow-[var(--shadow-*)], nicht erlaubt: shadow-sm, shadow-md, shadow-lg, shadow-xl als standalone Klasse
      expect(content).not.toMatch(/\bshadow-(sm|md|lg|xl)\b/)
    })
  }
})
