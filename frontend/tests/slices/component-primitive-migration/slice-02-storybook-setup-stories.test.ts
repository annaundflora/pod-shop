/**
 * Acceptance Tests for Slice 02: Storybook Setup + Stories.
 * Derived from GIVEN/WHEN/THEN Acceptance Criteria in the Slice-Spec.
 *
 * These tests validate file existence, file content, and structural correctness
 * of Storybook configuration and story files — no browser or DOM rendering.
 */
import { describe, it, expect, beforeAll } from 'vitest'
import * as fs from 'fs'
import * as path from 'path'
import { execSync } from 'child_process'

const FRONTEND_ROOT = path.resolve(__dirname, '../../../')
const STORYBOOK_DIR = path.join(FRONTEND_ROOT, '.storybook')
const STORIES_DIR = path.join(FRONTEND_ROOT, 'stories')

// ---------------------------------------------------------------------------
// Helper: read file content as UTF-8
// ---------------------------------------------------------------------------
function readFile(relativePath: string): string {
  return fs.readFileSync(path.join(FRONTEND_ROOT, relativePath), 'utf-8')
}

function readStory(relativePath: string): string {
  return fs.readFileSync(path.join(STORIES_DIR, relativePath), 'utf-8')
}

function storyExists(relativePath: string): boolean {
  return fs.existsSync(path.join(STORIES_DIR, relativePath))
}

// ===========================================================================
// AC-1: Storybook startet auf Port 6006
// GIVEN Storybook nicht installiert ist
// WHEN `pnpm storybook` im `frontend/`-Verzeichnis ausgefuehrt wird
// THEN startet Storybook 10.2.x auf Port 6006 ohne Fehler innerhalb von 30 Sekunden
// ===========================================================================
describe('AC-1: Storybook installation and startup configuration', () => {
  describe('Storybook configuration files exist', () => {
    it('should have .storybook/main.ts config file', () => {
      const mainPath = path.join(STORYBOOK_DIR, 'main.ts')
      expect(fs.existsSync(mainPath), `Missing: ${mainPath}`).toBe(true)
    })

    it('should have .storybook/preview.ts config file', () => {
      const previewPath = path.join(STORYBOOK_DIR, 'preview.ts')
      expect(fs.existsSync(previewPath), `Missing: ${previewPath}`).toBe(true)
    })
  })

  describe('main.ts configures @storybook/nextjs-vite framework', () => {
    it('should reference @storybook/nextjs-vite as framework', () => {
      const content = readFile('.storybook/main.ts')
      expect(content).toContain('@storybook/nextjs-vite')
    })

    it('should configure stories glob for stories/ directory', () => {
      const content = readFile('.storybook/main.ts')
      expect(content).toContain('stories')
      expect(content).toContain('.stories.')
    })

    it('should have an addons array in main.ts', () => {
      const content = readFile('.storybook/main.ts')
      expect(content).toContain('addons')
    })
  })

  describe('package.json has Storybook scripts', () => {
    it('should have "storybook" script that runs on port 6006', () => {
      const pkg = JSON.parse(readFile('package.json'))
      expect(pkg.scripts?.storybook).toBeDefined()
      expect(pkg.scripts.storybook).toContain('storybook dev')
      expect(pkg.scripts.storybook).toContain('6006')
    })

    it('should have "build-storybook" script', () => {
      const pkg = JSON.parse(readFile('package.json'))
      expect(pkg.scripts?.['build-storybook']).toBeDefined()
      expect(pkg.scripts['build-storybook']).toContain('storybook build')
    })
  })

  describe('package.json has Storybook devDependencies', () => {
    it('should have "storybook" devDependency', () => {
      const pkg = JSON.parse(readFile('package.json'))
      expect(pkg.devDependencies?.storybook).toBeDefined()
    })

    it('should have "@storybook/nextjs-vite" devDependency', () => {
      const pkg = JSON.parse(readFile('package.json'))
      expect(pkg.devDependencies?.['@storybook/nextjs-vite']).toBeDefined()
    })

    it('should have "@storybook/react" devDependency', () => {
      const pkg = JSON.parse(readFile('package.json'))
      expect(pkg.devDependencies?.['@storybook/react']).toBeDefined()
    })

    it('should have "@storybook/addon-essentials" devDependency', () => {
      const pkg = JSON.parse(readFile('package.json'))
      expect(pkg.devDependencies?.['@storybook/addon-essentials']).toBeDefined()
    })
  })
})

// ===========================================================================
// AC-2: Button story with 6 variants and 4 sizes
// GIVEN Storybook laeuft auf Port 6006
// WHEN ein Entwickler die `UI Primitives/Button` Story oeffnet
// THEN sind alle 6 Variants (default, destructive, outline, ghost, link, secondary)
//      als separate Stories sichtbar und alle 4 Sizes (default, sm, lg, icon)
//      ueber Controls waehlbar
// ===========================================================================
describe('AC-2: Button story with 6 variants and 4 sizes via Controls', () => {
  it('should have Button story file', () => {
    expect(storyExists('ui/Button.stories.tsx')).toBe(true)
  })

  it('should have title "UI Primitives/Button"', () => {
    const content = readStory('ui/Button.stories.tsx')
    expect(content).toContain('UI Primitives/Button')
  })

  describe('6 variant stories as named exports', () => {
    const variants = ['Default', 'Destructive', 'Outline', 'Ghost', 'Link', 'Secondary']

    variants.forEach((variant) => {
      it(`should export "${variant}" story`, () => {
        const content = readStory('ui/Button.stories.tsx')
        expect(content).toMatch(new RegExp(`export\\s+const\\s+${variant}\\s*[:=]`))
      })
    })
  })

  describe('4 sizes controllable via argTypes', () => {
    const sizes = ['default', 'sm', 'lg', 'icon']

    sizes.forEach((size) => {
      it(`should have size option "${size}" in argTypes`, () => {
        const content = readStory('ui/Button.stories.tsx')
        expect(content).toContain(`'${size}'`)
      })
    })
  })

  it('should have variant argType with select control', () => {
    const content = readStory('ui/Button.stories.tsx')
    expect(content).toContain("control: 'select'")
    // All 6 variant options present
    ;['default', 'destructive', 'outline', 'ghost', 'link', 'secondary'].forEach((v) => {
      expect(content).toContain(`'${v}'`)
    })
  })
})

// ===========================================================================
// AC-3: Theme Tokens via generated-theme.css in preview.ts
// GIVEN Storybook laeuft auf Port 6006
// WHEN ein Entwickler eine Story mit Tailwind-Klassen oeffnet (z.B. bg-primary)
// THEN werden die Theme Tokens aus generated-theme.css korrekt angewendet
// ===========================================================================
describe('AC-3: Theme Tokens applied via CSS imports in preview.ts', () => {
  beforeAll(() => {
    // Ensure generated-theme.css exists regardless of test execution order.
    // Other test suites (frontend-theming/slice-01) delete this file in their
    // afterEach hooks and only restore it in afterAll. When Vitest runs files
    // in parallel the file may be absent when this suite executes.
    const themeCssPath = path.join(FRONTEND_ROOT, 'app', 'generated-theme.css')
    if (!fs.existsSync(themeCssPath)) {
      const scriptPath = path.join(FRONTEND_ROOT, 'scripts', 'generate-theme.mjs')
      execSync(`node ${scriptPath}`, { cwd: FRONTEND_ROOT })
    }
  })

  it('should import globals.css in preview.ts', () => {
    const content = readFile('.storybook/preview.ts')
    expect(content).toContain('globals.css')
  })

  it('should import generated-theme.css in preview.ts', () => {
    const content = readFile('.storybook/preview.ts')
    expect(content).toContain('generated-theme.css')
  })

  it('should have generated-theme.css file in app/ directory', () => {
    const themeCssPath = path.join(FRONTEND_ROOT, 'app', 'generated-theme.css')
    expect(fs.existsSync(themeCssPath), `Missing: ${themeCssPath}`).toBe(true)
  })

  it('should have globals.css file in app/ directory', () => {
    const globalsCssPath = path.join(FRONTEND_ROOT, 'app', 'globals.css')
    expect(fs.existsSync(globalsCssPath), `Missing: ${globalsCssPath}`).toBe(true)
  })

  it('should configure backgrounds parameter in preview.ts', () => {
    const content = readFile('.storybook/preview.ts')
    expect(content).toContain('backgrounds')
    expect(content).toContain('#ffffff')
  })
})

// ===========================================================================
// AC-4: Cart Summary story with CartContext decorator (no useCart error)
// GIVEN Storybook laeuft auf Port 6006
// WHEN ein Entwickler die `Cart/Cart Summary` Story oeffnet
// THEN rendert die Component mit Mock-Daten ohne Fehler
// ===========================================================================
describe('AC-4: Cart Summary story with CartContext decorator', () => {
  it('should have CartSummary story file', () => {
    expect(storyExists('cart/CartSummary.stories.tsx')).toBe(true)
  })

  it('should have title "Cart/Cart Summary"', () => {
    const content = readStory('cart/CartSummary.stories.tsx')
    expect(content).toContain('Cart/Cart Summary')
  })

  it('should import CartContext from contexts', () => {
    const content = readStory('cart/CartSummary.stories.tsx')
    expect(content).toContain("import { CartContext }")
  })

  it('should use CartContext.Provider in decorator', () => {
    const content = readStory('cart/CartSummary.stories.tsx')
    expect(content).toContain('CartContext.Provider')
  })

  it('should have mock cart data with subtotal', () => {
    const content = readStory('cart/CartSummary.stories.tsx')
    expect(content).toContain('subtotal')
  })

  it('should have mock cart data with total', () => {
    const content = readStory('cart/CartSummary.stories.tsx')
    expect(content).toContain('total')
  })

  it('should have a "WithItems" story', () => {
    const content = readStory('cart/CartSummary.stories.tsx')
    expect(content).toMatch(/export\s+const\s+WithItems\s*[:=]/)
  })

  it('should use decorators array in meta', () => {
    const content = readStory('cart/CartSummary.stories.tsx')
    expect(content).toContain('decorators')
  })

  describe('All Cart stories use CartContext decorator', () => {
    const cartStoryFiles = [
      'cart/CartItemRow.stories.tsx',
      'cart/CartSummary.stories.tsx',
      'cart/QuantityStepper.stories.tsx',
      'cart/EmptyCart.stories.tsx',
    ]

    cartStoryFiles.forEach((file) => {
      it(`${file} should import and use CartContext`, () => {
        const content = readStory(file)
        expect(content).toContain('CartContext')
        expect(content).toContain('CartContext.Provider')
      })
    })
  })
})

// ===========================================================================
// AC-5: AddToCartButton loading state with isLoading
// GIVEN Storybook laeuft auf Port 6006
// WHEN ein Entwickler die `Product/Add to Cart Button` Story mit State "loading" oeffnet
// THEN zeigt der Button den Lade-Spinner (isLoading={true})
// ===========================================================================
describe('AC-5: AddToCartButton loading story with isLoading', () => {
  it('should have AddToCartButton story file', () => {
    expect(storyExists('product/AddToCartButton.stories.tsx')).toBe(true)
  })

  it('should have title "Product/Add to Cart Button"', () => {
    const content = readStory('product/AddToCartButton.stories.tsx')
    expect(content).toContain('Product/Add to Cart Button')
  })

  it('should export a "Loading" story', () => {
    const content = readStory('product/AddToCartButton.stories.tsx')
    expect(content).toMatch(/export\s+const\s+Loading\s*[:=]/)
  })

  it('should set isLoading: true in Loading story args', () => {
    const content = readStory('product/AddToCartButton.stories.tsx')
    expect(content).toContain('isLoading: true')
  })

  it('should have isLoading in argTypes for interactive control', () => {
    const content = readStory('product/AddToCartButton.stories.tsx')
    expect(content).toContain('isLoading')
  })
})

// ===========================================================================
// AC-6: CartIcon story with WithoutBadge (itemCount=0) and WithBadge (itemCount=3)
// GIVEN Storybook laeuft auf Port 6006
// WHEN ein Entwickler die `Cart/Cart Icon` Story oeffnet
// THEN sind zwei Stories sichtbar: eine ohne Badge (itemCount=0) und eine mit Badge (itemCount=3)
// ===========================================================================
describe('AC-6: CartIcon story with WithoutBadge and WithBadge', () => {
  it('should have CartIcon story file', () => {
    expect(storyExists('layout/CartIcon.stories.tsx')).toBe(true)
  })

  it('should have title containing "Cart Icon"', () => {
    const content = readStory('layout/CartIcon.stories.tsx')
    expect(content).toContain('Cart Icon')
  })

  it('should export "WithoutBadge" story', () => {
    const content = readStory('layout/CartIcon.stories.tsx')
    expect(content).toMatch(/export\s+const\s+WithoutBadge\s*[:=]/)
  })

  it('should export "WithBadge" story', () => {
    const content = readStory('layout/CartIcon.stories.tsx')
    expect(content).toMatch(/export\s+const\s+WithBadge\s*[:=]/)
  })

  it('should use CartContext with itemCount=0 for WithoutBadge', () => {
    const content = readStory('layout/CartIcon.stories.tsx')
    // The decorator passes itemCount 0
    expect(content).toContain('itemCount')
    expect(content).toMatch(/withCartContext\(0\)/)
  })

  it('should use CartContext with itemCount=3 for WithBadge', () => {
    const content = readStory('layout/CartIcon.stories.tsx')
    expect(content).toMatch(/withCartContext\(3\)/)
  })
})

// ===========================================================================
// AC-7: build-storybook produces static build without errors
// GIVEN alle Story-Dateien geschrieben wurden
// WHEN `pnpm build-storybook` im `frontend/`-Verzeichnis ausgefuehrt wird
// THEN wird ein statischer Build ohne TypeScript-Fehler in storybook-static/ erzeugt
//
// Note: Actual build is a manual validation step. Tests verify all prerequisites:
// all 27 story files exist, all have valid CSF 3 structure (default export + named exports).
// ===========================================================================
describe('AC-7: All 27 story files exist with valid CSF 3 structure', () => {
  const allStoryFiles = [
    // UI Primitives (7)
    'ui/Button.stories.tsx',
    'ui/Card.stories.tsx',
    'ui/Input.stories.tsx',
    'ui/Badge.stories.tsx',
    'ui/Skeleton.stories.tsx',
    'ui/Dialog.stories.tsx',
    'ui/Sheet.stories.tsx',
    // Layout (5)
    'layout/Header.stories.tsx',
    'layout/Footer.stories.tsx',
    'layout/MobileMenu.stories.tsx',
    'layout/CartIcon.stories.tsx',
    'layout/CookieConsentBanner.stories.tsx',
    // Product (6)
    'product/ProductCard.stories.tsx',
    'product/ProductCardSkeleton.stories.tsx',
    'product/AddToCartButton.stories.tsx',
    'product/ImageGallery.stories.tsx',
    'product/VariantSelectorSize.stories.tsx',
    'product/VariantSelectorColor.stories.tsx',
    // Cart (4)
    'cart/CartItemRow.stories.tsx',
    'cart/CartSummary.stories.tsx',
    'cart/QuantityStepper.stories.tsx',
    'cart/EmptyCart.stories.tsx',
    // Category (1)
    'category/FilterChips.stories.tsx',
    // Blocks (4)
    'blocks/HeroBlock.stories.tsx',
    'blocks/ProductGridBlock.stories.tsx',
    'blocks/CategoryShowcaseBlock.stories.tsx',
    'blocks/UspBarBlock.stories.tsx',
  ]

  it('should have exactly 27 story files', () => {
    expect(allStoryFiles).toHaveLength(27)
  })

  allStoryFiles.forEach((storyFile) => {
    describe(`stories/${storyFile}`, () => {
      it('should exist', () => {
        expect(storyExists(storyFile), `Missing story: ${storyFile}`).toBe(true)
      })

      it('should have a default export (CSF 3 meta)', () => {
        const content = readStory(storyFile)
        expect(content).toMatch(/export\s+default\s+/)
      })

      it('should have at least one named story export', () => {
        const content = readStory(storyFile)
        // Match named exports that are not the default or type alias
        const namedExports = content.match(/export\s+const\s+\w+\s*[:=]/g)
        expect(namedExports, `No named story exports in ${storyFile}`).not.toBeNull()
        expect(namedExports!.length).toBeGreaterThanOrEqual(1)
      })

      it('should use CSF 3 format with Meta type', () => {
        const content = readStory(storyFile)
        expect(content).toContain('Meta')
      })
    })
  })

  describe('Story title conventions follow category pattern', () => {
    const titleExpectations: Record<string, string> = {
      'ui/Button.stories.tsx': 'UI Primitives/Button',
      'ui/Card.stories.tsx': 'UI Primitives/Card',
      'ui/Badge.stories.tsx': 'UI Primitives/Badge',
      'layout/Header.stories.tsx': 'Layout/Header',
      'layout/Footer.stories.tsx': 'Layout/Footer',
      'layout/CartIcon.stories.tsx': 'Layout/Cart Icon',
      'product/ProductCard.stories.tsx': 'Product/Product Card',
      'product/AddToCartButton.stories.tsx': 'Product/Add to Cart Button',
      'cart/CartSummary.stories.tsx': 'Cart/Cart Summary',
      'cart/CartItemRow.stories.tsx': 'Cart/Cart Item Row',
      'cart/QuantityStepper.stories.tsx': 'Cart/Quantity Stepper',
      'cart/EmptyCart.stories.tsx': 'Cart/Empty Cart',
      'category/FilterChips.stories.tsx': 'Category/Filter Chips',
      'blocks/HeroBlock.stories.tsx': 'Blocks/Hero Block',
      'blocks/ProductGridBlock.stories.tsx': 'Blocks/Product Grid Block',
      'blocks/CategoryShowcaseBlock.stories.tsx': 'Blocks/Category Showcase Block',
      'blocks/UspBarBlock.stories.tsx': 'Blocks/USP Bar Block',
    }

    Object.entries(titleExpectations).forEach(([file, expectedTitle]) => {
      it(`${file} should have title "${expectedTitle}"`, () => {
        const content = readStory(file)
        expect(content).toContain(expectedTitle)
      })
    })
  })
})

// ===========================================================================
// AC-8: HeroBlock story with "Loaded" and "Loading" stories
// GIVEN Storybook laeuft auf Port 6006
// WHEN ein Entwickler die `Blocks/Hero Block` Story oeffnet
// THEN sind zwei Stories sichtbar: "Loaded" (mit Text + CTA-Button) und "Loading" (Skeleton)
// ===========================================================================
describe('AC-8: HeroBlock story with Loaded and Loading stories', () => {
  it('should have HeroBlock story file', () => {
    expect(storyExists('blocks/HeroBlock.stories.tsx')).toBe(true)
  })

  it('should have title "Blocks/Hero Block"', () => {
    const content = readStory('blocks/HeroBlock.stories.tsx')
    expect(content).toContain('Blocks/Hero Block')
  })

  it('should export a "Loaded" story', () => {
    const content = readStory('blocks/HeroBlock.stories.tsx')
    expect(content).toMatch(/export\s+const\s+Loaded\s*[:=]/)
  })

  it('should export a "Loading" story', () => {
    const content = readStory('blocks/HeroBlock.stories.tsx')
    expect(content).toMatch(/export\s+const\s+Loading\s*[:=]/)
  })

  it('should have hero data args with headline in Loaded story', () => {
    const content = readStory('blocks/HeroBlock.stories.tsx')
    expect(content).toContain('heroHeadline')
  })

  it('should have hero data args with CTA text', () => {
    const content = readStory('blocks/HeroBlock.stories.tsx')
    expect(content).toContain('heroCtaText')
  })

  it('should have hero data args with CTA link', () => {
    const content = readStory('blocks/HeroBlock.stories.tsx')
    expect(content).toContain('heroCtaLink')
  })

  it('should import HeroBlockSkeleton for Loading story', () => {
    const content = readStory('blocks/HeroBlock.stories.tsx')
    expect(content).toContain('HeroBlockSkeleton')
  })

  it('should render HeroBlockSkeleton in Loading story', () => {
    const content = readStory('blocks/HeroBlock.stories.tsx')
    expect(content).toContain('<HeroBlockSkeleton')
  })
})
