# Slice 2: Storybook Setup + Stories

> **Slice 2 von 2** für `Component Primitive Migration + Storybook (P0.3)`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-01-component-primitive-composition.md` |
> | **Nächster:** | — |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-02-storybook-setup-stories` |
| **Test** | `pnpm test tests/slices/component-primitive-migration/slice-02-storybook-setup-stories.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-component-primitive-composition"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier für Commits und Evidence
- **Test**: Vitest-Test prüft, ob Storybook-Konfigurationsdateien korrekt existieren und valide sind
- **E2E**: false — kein Playwright-Test, Vitest prüft Konfigurationsdateien und Story-Exporte
- **Dependencies**: Slice 1 muss approved sein — Stories referenzieren die migrierten Components mit shadcn/ui Primitives

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf `frontend/package.json` → `next: "^16.1.6"`, `vitest: "^3.0.0"`, `@testing-library/react: "^16.3.2"`.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/component-primitive-migration/slice-02-storybook-setup-stories.test.ts` |
| **Integration Command** | `pnpm test tests/slices/component-primitive-migration/` |
| **Acceptance Command** | `pnpm test` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `no_mocks` |

**Erklaerung:**
- **Stack**: Next.js 16 + Vitest 3 + React Testing Library 16 (aus `frontend/package.json` detektiert)
- **Test Command**: Slice-spezifischer Test prüft Konfigurationsdateien und Story-Default-Exporte auf Vollständigkeit
- **Integration Command**: Alle Tests im feature-spezifischen Ordner
- **Acceptance Command**: Alle 151+ bestehenden Tests + neue Tests müssen grün sein; manuell: `pnpm storybook` muss auf Port 6006 starten
- **Mocking Strategy**: `no_mocks` — Story-Dateien exportieren statische Objekte; Konfigurationsdateien sind reine TypeScript-Module ohne externe Calls

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Component Primitive Composition | **Approved** | `slice-01-component-primitive-composition.md` |
| 2 | Storybook Setup + Stories | **Ready** | `slice-02-storybook-setup-stories.md` |

---

## Kontext & Ziel

**Problem:** Kein Storybook — Components können nicht isoliert entwickelt, getestet oder visuell verglichen werden. Kein Component-Katalog für Entwickler oder Stakeholder. Nach der Migration in Slice 1 fehlt ein visuelles Dokumentationswerkzeug, das alle Variants und States der migrierten shadcn/ui Primitives und Domain-Components darstellt.

**Zielbild:** Storybook 10.2.x läuft auf Port 6006. Alle 27 Components haben Stories mit allen relevanten Variants und States. Tailwind Theme Tokens (aus `generated-theme.css`) werden korrekt angewendet. Cart-Components erhalten einen `CartContext.Provider` Decorator mit Mock-Werten. `pnpm build-storybook` erzeugt einen statischen Build ohne Fehler.

**Aktuelle Probleme:**
1. Kein `.storybook/` Ordner im `frontend/`-Verzeichnis
2. Keine Storybook-Dependencies in `frontend/package.json`
3. Kein `storybook`- oder `build-storybook`-Script in `frontend/package.json`
4. Kein `frontend/stories/` Ordner

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → "Architecture Layers" + "Technology Decisions" + "Constraints"

```
Layer Responsibilities:
  .storybook/          → Storybook Config (@storybook/nextjs-vite Framework + Tailwind v4 via globals.css)
  stories/             → Story Files (dedizierter Ordner, nicht co-located — hält Components clean)
  components/ui/       → shadcn/ui Primitives (Button, Card, Badge, etc.) — UNVERÄNDERT
  components/{domain}/ → Composed Components — referenziert von Stories via Props

Technology Decisions:
  Framework:    @storybook/nextjs-vite (Vite-basiert, schneller als Webpack, Next.js 16 Support)
  Version:      10.2.x (NICHT 8.x — 8.x hat kein Next.js 16 Support)
  Story Format: CSF 3, TypeScript-first
  Location:     frontend/stories/ (dedizierter Ordner)
  Mock-Strategy: Static Props + CartContext Decorator (kein MSW, kein GraphQL-Mocking)

Constraint: Storybook 10 ist ESM-Only → Node.js 20.16+ required → Projekt nutzt Node.js 22.x (kompatibel)
Constraint: Storybook + Tailwind v4 → globals.css + generated-theme.css in preview.ts importieren → PostCSS automatisch
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `frontend/.storybook/` | NEU: `main.ts` (Framework-Config) + `preview.ts` (Global Imports + Decorators) |
| `frontend/stories/` | NEU: 27 Story-Dateien in 6 Sub-Ordnern (ui/, layout/, product/, cart/, category/, blocks/) |
| `frontend/package.json` | devDependencies + Scripts erweitert |

### 2. Datenfluss (Story-Rendering)

```
Story Args (statische Mock-Daten)
  ↓
Storybook Controls (interaktiv editierbar)
  ↓
CartContext.Provider Decorator (für Cart-Components)
  ↓
Component render (mit shadcn/ui Primitives aus Slice 1)
  ↓
Tailwind CSS (via globals.css + generated-theme.css → Theme Tokens aktiv)
  ↓
Storybook Canvas (visueller Output)
```

### 3. Storybook-Konfiguration

**`frontend/.storybook/main.ts`:**
- Framework: `@storybook/nextjs-vite`
- Stories-Glob: `../stories/**/*.stories.@(ts|tsx)`
- Addons: `['@storybook/addon-essentials']`

**`frontend/.storybook/preview.ts`:**
- CSS-Imports: `../app/globals.css`, `../app/generated-theme.css`
- Default Viewport: mobile (375px) als erstes, desktop als zweites
- Backgrounds: weiß als Default (passend zu Theme `--color-surface`)

### 4. Package.json Erweiterungen

**Neue devDependencies:**
```json
{
  "storybook": "^10.2.0",
  "@storybook/nextjs-vite": "^10.2.0",
  "@storybook/addon-essentials": "^10.2.0",
  "@storybook/react": "^10.2.0"
}
```

**Neue Scripts:**
```json
{
  "storybook": "storybook dev -p 6006",
  "build-storybook": "storybook build"
}
```

### 5. CartContext Mock-Interface (für Decorator)

Die Cart-Components (`CartItemRow`, `CartSummary`, `QuantityStepper`, `EmptyCart`) nutzen `useCart()` und benötigen einen `CartContext.Provider` Wrapper im Storybook-Decorator. Das Mock-Interface folgt `CartContextValue` aus `frontend/contexts/cart-context.types.ts`:

```typescript
const mockCartContext = {
  cartItems: [/* statische Mock-Items */],
  subtotal: '29,99 €',
  shippingTotal: 'Kostenlos',
  total: '29,99 €',
  itemCount: 1,
  isLoading: false,
  error: null,
  addToCart: async () => {},
  updateQuantity: async () => {},
  removeItem: async () => {},
  clearError: () => {},
}
```

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft. Unvollständige Contracts blockieren die Genehmigung.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-component-primitive-composition | `Button` Component | React Component | `import { Button } from '@/components/ui/button'` — alle 6 Variants + 4 Sizes + `isLoading` + `asChild` Props vorhanden |
| slice-01-component-primitive-composition | `Card`, `CardHeader`, `CardTitle`, `CardContent`, `CardFooter` Components | React Components | `import { Card, ... } from '@/components/ui/card'` — 2 Variants, alle Compound-Components vorhanden |
| slice-01-component-primitive-composition | `Badge` Component | React Component | `import { Badge } from '@/components/ui/badge'` — 4 Variants vorhanden |
| slice-01-component-primitive-composition | `Input`, `Skeleton`, `Dialog`, `Sheet` Components | React Components | Alle aus `@/components/ui/` importierbar |
| slice-01-component-primitive-composition | Migrierte Domain-Components | React Components | `AddToCartButton`, `CartItemRow`, `CartSummary`, `QuantityStepper`, `ProductCard`, `FilterChips` — alle nutzen shadcn/ui Primitives |
| slice-01-component-primitive-composition | `CartContext` | React Context | `import { CartContext } from '@/contexts/cart-context'` — für Storybook-Decorator |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `frontend/.storybook/main.ts` | Storybook Config | Storybook CLI (`pnpm storybook`) | Framework + Stories-Glob konfiguriert |
| `frontend/.storybook/preview.ts` | Storybook Preview Config | Storybook Canvas | CSS-Imports + Decorators |
| `frontend/stories/**/*.stories.tsx` | Story Files (27 Stück) | Storybook Canvas + `pnpm build-storybook` | CSF 3 Format — Default Export + Named Story Exports |

### Integration Validation Tasks

- [ ] `Button` aus Slice 1 hat alle 6 Variants — Stories referenzieren: default, destructive, outline, ghost, link, secondary
- [ ] `CartContext` ist aus `@/contexts/cart-context` importierbar für Storybook Decorator
- [ ] `globals.css` + `generated-theme.css` existieren in `frontend/app/` — werden in `preview.ts` importiert
- [ ] `pnpm storybook` startet ohne Fehler (manuelle Validierung nach Implementierung)

---

## UI Anforderungen

### Kein neuer Screen

Storybook ist ein Development-Tool, kein Produktions-Screen. Die Stories zeigen Components in isolierter Umgebung — keine Änderungen an der Produktions-UI.

### Story-Titel-Konvention

Alle Stories folgen dem Pattern `{Kategorie}/{Component-Name}`:

| Kategorie | Stories |
|-----------|---------|
| `UI Primitives` | Button, Card, Input, Badge, Skeleton, Dialog, Sheet |
| `Layout` | Header, Footer, Mobile Menu, Cart Icon, Cookie Consent Banner |
| `Product` | Product Card, Product Card Skeleton, Add to Cart Button, Image Gallery, Variant Selector Size, Variant Selector Color |
| `Cart` | Cart Item Row, Cart Summary, Quantity Stepper, Empty Cart |
| `Category` | Filter Chips |
| `Blocks` | Hero Block, Product Grid Block, Category Showcase Block, USP Bar Block |

### Variants und States pro Component-Typ

| Component-Typ | Pflicht-Stories |
|---------------|-----------------|
| shadcn/ui Primitives | Alle Variants + alle States (default, disabled, loading wo vorhanden) |
| Layout-Components | Default-Story; wo relevant: mit/ohne Daten (z.B. CartIcon mit 0 vs. 3 Items) |
| Product-Components | Default + alle States (loading, success, disabled, unavailable) |
| Cart-Components | Default + leer + mit Daten; CartContext-Decorator PFLICHT |
| Block-Components | Loaded + Loading (Skeleton) + Empty (wo vorhanden) |

---

## Acceptance Criteria

1) GIVEN Storybook nicht installiert ist
   WHEN `pnpm storybook` im `frontend/`-Verzeichnis ausgeführt wird
   THEN startet Storybook 10.2.x auf Port 6006 ohne Fehler innerhalb von 30 Sekunden

2) GIVEN Storybook läuft auf Port 6006
   WHEN ein Entwickler die `UI Primitives/Button` Story öffnet
   THEN sind alle 6 Variants (default, destructive, outline, ghost, link, secondary) als separate Stories sichtbar und alle 4 Sizes (default, sm, lg, icon) über Controls wählbar

3) GIVEN Storybook läuft auf Port 6006
   WHEN ein Entwickler eine Story mit Tailwind-Klassen öffnet (z.B. `bg-primary`, `text-text-primary`)
   THEN werden die Theme Tokens aus `generated-theme.css` korrekt angewendet — Buttons haben die korrekte Primärfarbe aus dem Theme

4) GIVEN Storybook läuft auf Port 6006
   WHEN ein Entwickler die `Cart/Cart Summary` Story öffnet
   THEN rendert die Component mit Mock-Daten (Subtotal, Gesamt, Zur-Kasse-Button) ohne Fehler — kein "useCart must be used within CartProvider"-Fehler

5) GIVEN Storybook läuft auf Port 6006
   WHEN ein Entwickler die `Product/Add to Cart Button` Story mit State "loading" öffnet
   THEN zeigt der Button den Lade-Spinner aus dem shadcn/ui Button-Primitive (`isLoading={true}`)

6) GIVEN Storybook läuft auf Port 6006
   WHEN ein Entwickler die `Cart/Cart Icon` Story öffnet
   THEN sind zwei Stories sichtbar: eine ohne Badge (itemCount=0) und eine mit Badge (itemCount=3) — Badge nutzt shadcn/ui `<Badge>`-Primitive aus Slice 1

7) GIVEN alle Story-Dateien geschrieben wurden
   WHEN `pnpm build-storybook` im `frontend/`-Verzeichnis ausgeführt wird
   THEN wird ein statischer Build ohne TypeScript-Fehler und ohne Build-Fehler in `storybook-static/` erzeugt

8) GIVEN Storybook läuft auf Port 6006
   WHEN ein Entwickler die `Blocks/Hero Block` Story öffnet
   THEN sind zwei Stories sichtbar: "Loaded" (mit Text + CTA-Button als `<Button asChild><Link>`) und "Loading" (mit Skeleton-Komponente)

---

## Testfälle

### Test-Datei

`frontend/tests/slices/component-primitive-migration/slice-02-storybook-setup-stories.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// frontend/tests/slices/component-primitive-migration/slice-02-storybook-setup-stories.test.ts
import { describe, it, expect } from 'vitest'
import * as fs from 'fs'
import * as path from 'path'

const FRONTEND_ROOT = path.resolve(__dirname, '../../../')
const STORYBOOK_DIR = path.join(FRONTEND_ROOT, '.storybook')
const STORIES_DIR = path.join(FRONTEND_ROOT, 'stories')

describe('Slice 02: Storybook Setup + Stories', () => {

  describe('Storybook Konfigurationsdateien', () => {
    it('should have .storybook/main.ts config file', () => {
      const mainPath = path.join(STORYBOOK_DIR, 'main.ts')
      expect(fs.existsSync(mainPath), `Datei fehlt: ${mainPath}`).toBe(true)
    })

    it('should have .storybook/preview.ts config file', () => {
      const previewPath = path.join(STORYBOOK_DIR, 'preview.ts')
      expect(fs.existsSync(previewPath), `Datei fehlt: ${previewPath}`).toBe(true)
    })

    it('should configure @storybook/nextjs-vite framework in main.ts', () => {
      const mainContent = fs.readFileSync(path.join(STORYBOOK_DIR, 'main.ts'), 'utf-8')
      expect(mainContent).toContain('@storybook/nextjs-vite')
    })

    it('should configure stories glob for stories/ directory in main.ts', () => {
      const mainContent = fs.readFileSync(path.join(STORYBOOK_DIR, 'main.ts'), 'utf-8')
      expect(mainContent).toContain('stories')
      expect(mainContent).toContain('.stories.')
    })

    it('should import globals.css in preview.ts', () => {
      const previewContent = fs.readFileSync(path.join(STORYBOOK_DIR, 'preview.ts'), 'utf-8')
      expect(previewContent).toContain('globals.css')
    })

    it('should import generated-theme.css in preview.ts', () => {
      const previewContent = fs.readFileSync(path.join(STORYBOOK_DIR, 'preview.ts'), 'utf-8')
      expect(previewContent).toContain('generated-theme.css')
    })
  })

  describe('Package.json Scripts und Dependencies', () => {
    it('should have storybook script in package.json', () => {
      const pkgPath = path.join(FRONTEND_ROOT, 'package.json')
      const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf-8'))
      expect(pkg.scripts?.storybook).toBeDefined()
      expect(pkg.scripts.storybook).toContain('storybook dev')
    })

    it('should have build-storybook script in package.json', () => {
      const pkgPath = path.join(FRONTEND_ROOT, 'package.json')
      const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf-8'))
      expect(pkg.scripts?.['build-storybook']).toBeDefined()
      expect(pkg.scripts['build-storybook']).toContain('storybook build')
    })

    it('should have storybook devDependency in package.json', () => {
      const pkgPath = path.join(FRONTEND_ROOT, 'package.json')
      const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf-8'))
      expect(pkg.devDependencies?.storybook).toBeDefined()
    })

    it('should have @storybook/nextjs-vite devDependency in package.json', () => {
      const pkgPath = path.join(FRONTEND_ROOT, 'package.json')
      const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf-8'))
      expect(pkg.devDependencies?.['@storybook/nextjs-vite']).toBeDefined()
    })

    it('should have @storybook/addon-essentials devDependency in package.json', () => {
      const pkgPath = path.join(FRONTEND_ROOT, 'package.json')
      const pkg = JSON.parse(fs.readFileSync(pkgPath, 'utf-8'))
      expect(pkg.devDependencies?.['@storybook/addon-essentials']).toBeDefined()
    })
  })

  describe('Story-Dateien: UI Primitives', () => {
    const uiStories = [
      'ui/Button.stories.tsx',
      'ui/Card.stories.tsx',
      'ui/Input.stories.tsx',
      'ui/Badge.stories.tsx',
      'ui/Skeleton.stories.tsx',
      'ui/Dialog.stories.tsx',
      'ui/Sheet.stories.tsx',
    ]

    uiStories.forEach((storyFile) => {
      it(`should have story file: stories/${storyFile}`, () => {
        const storyPath = path.join(STORIES_DIR, storyFile)
        expect(fs.existsSync(storyPath), `Story fehlt: ${storyPath}`).toBe(true)
      })
    })

    it('should have Button stories with default export containing title UI Primitives/Button', () => {
      const content = fs.readFileSync(path.join(STORIES_DIR, 'ui/Button.stories.tsx'), 'utf-8')
      expect(content).toContain('UI Primitives/Button')
    })
  })

  describe('Story-Dateien: Layout', () => {
    const layoutStories = [
      'layout/Header.stories.tsx',
      'layout/Footer.stories.tsx',
      'layout/MobileMenu.stories.tsx',
      'layout/CartIcon.stories.tsx',
      'layout/CookieConsentBanner.stories.tsx',
    ]

    layoutStories.forEach((storyFile) => {
      it(`should have story file: stories/${storyFile}`, () => {
        const storyPath = path.join(STORIES_DIR, storyFile)
        expect(fs.existsSync(storyPath), `Story fehlt: ${storyPath}`).toBe(true)
      })
    })
  })

  describe('Story-Dateien: Product', () => {
    const productStories = [
      'product/ProductCard.stories.tsx',
      'product/ProductCardSkeleton.stories.tsx',
      'product/AddToCartButton.stories.tsx',
      'product/ImageGallery.stories.tsx',
      'product/VariantSelectorSize.stories.tsx',
      'product/VariantSelectorColor.stories.tsx',
    ]

    productStories.forEach((storyFile) => {
      it(`should have story file: stories/${storyFile}`, () => {
        const storyPath = path.join(STORIES_DIR, storyFile)
        expect(fs.existsSync(storyPath), `Story fehlt: ${storyPath}`).toBe(true)
      })
    })
  })

  describe('Story-Dateien: Cart', () => {
    const cartStories = [
      'cart/CartItemRow.stories.tsx',
      'cart/CartSummary.stories.tsx',
      'cart/QuantityStepper.stories.tsx',
      'cart/EmptyCart.stories.tsx',
    ]

    cartStories.forEach((storyFile) => {
      it(`should have story file: stories/${storyFile}`, () => {
        const storyPath = path.join(STORIES_DIR, storyFile)
        expect(fs.existsSync(storyPath), `Story fehlt: ${storyPath}`).toBe(true)
      })
    })

    it('should have CartContext decorator in CartSummary story', () => {
      const content = fs.readFileSync(path.join(STORIES_DIR, 'cart/CartSummary.stories.tsx'), 'utf-8')
      expect(content).toContain('CartContext')
    })

    it('should have CartContext decorator in CartItemRow story', () => {
      const content = fs.readFileSync(path.join(STORIES_DIR, 'cart/CartItemRow.stories.tsx'), 'utf-8')
      expect(content).toContain('CartContext')
    })
  })

  describe('Story-Dateien: Category', () => {
    it('should have story file: stories/category/FilterChips.stories.tsx', () => {
      const storyPath = path.join(STORIES_DIR, 'category/FilterChips.stories.tsx')
      expect(fs.existsSync(storyPath), `Story fehlt: ${storyPath}`).toBe(true)
    })
  })

  describe('Story-Dateien: Blocks', () => {
    const blockStories = [
      'blocks/HeroBlock.stories.tsx',
      'blocks/ProductGridBlock.stories.tsx',
      'blocks/CategoryShowcaseBlock.stories.tsx',
      'blocks/UspBarBlock.stories.tsx',
    ]

    blockStories.forEach((storyFile) => {
      it(`should have story file: stories/${storyFile}`, () => {
        const storyPath = path.join(STORIES_DIR, storyFile)
        expect(fs.existsSync(storyPath), `Story fehlt: ${storyPath}`).toBe(true)
      })
    })
  })

})
```
</test_spec>

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind **PFLICHT-Deliverables**.
> Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.
> Abweichung nur mit expliziter Begründung im Commit erlaubt.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `main.ts` Storybook Config | Code Examples — Storybook Config | YES | Framework, Stories-Glob, Addons exakt wie spezifiziert |
| `preview.ts` Storybook Preview | Code Examples — Storybook Config | YES | globals.css + generated-theme.css Import, Decorators |
| `Button.stories.tsx` | Code Examples — Story Beispiele | YES | CSF 3, Default Export, alle 6 Variants als Named Exports |
| `CartSummary.stories.tsx` mit CartContext Decorator | Code Examples — Story Beispiele | YES | CartContext.Provider Decorator Pattern für alle Cart-Stories |
| `HeroBlock.stories.tsx` | Code Examples — Story Beispiele | YES | Loaded + Loading Story |

---

### Storybook Config: `frontend/.storybook/main.ts`

```typescript
// frontend/.storybook/main.ts
import type { StorybookConfig } from '@storybook/nextjs-vite'

const config: StorybookConfig = {
  stories: ['../stories/**/*.stories.@(ts|tsx)'],
  addons: ['@storybook/addon-essentials'],
  framework: {
    name: '@storybook/nextjs-vite',
    options: {},
  },
}

export default config
```

---

### Storybook Preview: `frontend/.storybook/preview.ts`

```typescript
// frontend/.storybook/preview.ts
import type { Preview } from '@storybook/react'
import '../app/globals.css'
import '../app/generated-theme.css'

const preview: Preview = {
  parameters: {
    backgrounds: {
      default: 'light',
      values: [
        { name: 'light', value: '#ffffff' },
        { name: 'surface-elevated', value: '#f5f5f5' },
      ],
    },
    viewport: {
      viewports: {
        mobile: {
          name: 'Mobile (375px)',
          styles: { width: '375px', height: '812px' },
        },
        tablet: {
          name: 'Tablet (768px)',
          styles: { width: '768px', height: '1024px' },
        },
        desktop: {
          name: 'Desktop (1280px)',
          styles: { width: '1280px', height: '900px' },
        },
      },
      defaultViewport: 'desktop',
    },
    controls: {
      matchers: {
        color: /(background|color)$/i,
        date: /Date$/i,
      },
    },
  },
}

export default preview
```

---

### Story Beispiel: `frontend/stories/ui/Button.stories.tsx`

```tsx
// frontend/stories/ui/Button.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { Button } from '@/components/ui/button'

const meta: Meta<typeof Button> = {
  title: 'UI Primitives/Button',
  component: Button,
  tags: ['autodocs'],
  argTypes: {
    variant: {
      control: 'select',
      options: ['default', 'destructive', 'outline', 'ghost', 'link', 'secondary'],
    },
    size: {
      control: 'select',
      options: ['default', 'sm', 'lg', 'icon'],
    },
    isLoading: { control: 'boolean' },
    disabled: { control: 'boolean' },
  },
}

export default meta
type Story = StoryObj<typeof Button>

export const Default: Story = {
  args: {
    children: 'In den Warenkorb',
    variant: 'default',
    size: 'default',
  },
}

export const Destructive: Story = {
  args: {
    children: 'Entfernen',
    variant: 'destructive',
    size: 'default',
  },
}

export const Outline: Story = {
  args: {
    children: 'Groesse L',
    variant: 'outline',
    size: 'default',
  },
}

export const Ghost: Story = {
  args: {
    children: 'Abbrechen',
    variant: 'ghost',
    size: 'default',
  },
}

export const Link: Story = {
  args: {
    children: 'Mehr anzeigen',
    variant: 'link',
    size: 'default',
  },
}

export const Secondary: Story = {
  args: {
    children: 'Alle Produkte',
    variant: 'secondary',
    size: 'default',
  },
}

export const Loading: Story = {
  args: {
    children: 'Wird geladen',
    variant: 'default',
    size: 'default',
    isLoading: true,
  },
}

export const Disabled: Story = {
  args: {
    children: 'Nicht verfügbar',
    variant: 'default',
    size: 'default',
    disabled: true,
  },
}

export const SizeSmall: Story = {
  args: {
    children: 'Klein',
    variant: 'default',
    size: 'sm',
  },
}

export const SizeLarge: Story = {
  args: {
    children: 'Gross',
    variant: 'default',
    size: 'lg',
  },
}

export const SizeIcon: Story = {
  args: {
    children: '×',
    variant: 'ghost',
    size: 'icon',
    'aria-label': 'Schliessen',
  },
}
```

---

### Story Beispiel: `frontend/stories/cart/CartSummary.stories.tsx` (mit CartContext Decorator)

```tsx
// frontend/stories/cart/CartSummary.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import React from 'react'
import { CartSummary } from '@/components/cart/cart-summary'
import { CartContext } from '@/contexts/cart-context'
import type { CartContextValue } from '@/contexts/cart-context.types'

const mockCartContextBase: CartContextValue = {
  cartItems: [
    {
      key: 'item-1',
      productId: 123,
      variationId: null,
      productName: 'Muster T-Shirt',
      variationLabel: 'Groesse: M',
      thumbnail: null,
      quantity: 1,
      subtotal: '29,99 €',
      subtotalRaw: 29.99,
      price: '29,99 €',
    },
  ],
  subtotal: '29,99 €',
  shippingTotal: 'Kostenlos',
  total: '29,99 €',
  itemCount: 1,
  isLoading: false,
  error: null,
  addToCart: async () => {},
  updateQuantity: async () => {},
  removeItem: async () => {},
  clearError: () => {},
}

function withCartContext(contextValue: CartContextValue) {
  return function Decorator(Story: React.ComponentType) {
    return (
      <CartContext.Provider value={contextValue}>
        <Story />
      </CartContext.Provider>
    )
  }
}

const meta: Meta<typeof CartSummary> = {
  title: 'Cart/Cart Summary',
  component: CartSummary,
  tags: ['autodocs'],
  decorators: [withCartContext(mockCartContextBase)],
}

export default meta
type Story = StoryObj<typeof CartSummary>

export const WithItems: Story = {}

export const Empty: Story = {
  decorators: [
    withCartContext({
      ...mockCartContextBase,
      cartItems: [],
      subtotal: '0,00 €',
      total: '0,00 €',
      itemCount: 0,
    }),
  ],
}

export const Loading: Story = {
  decorators: [
    withCartContext({
      ...mockCartContextBase,
      isLoading: true,
    }),
  ],
}
```

---

### Story Beispiel: `frontend/stories/blocks/HeroBlock.stories.tsx`

```tsx
// frontend/stories/blocks/HeroBlock.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { HeroBlock, HeroBlockSkeleton } from '@/components/blocks/hero-block'

const meta: Meta<typeof HeroBlock> = {
  title: 'Blocks/Hero Block',
  component: HeroBlock,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof HeroBlock>

export const Loaded: Story = {
  args: {
    data: {
      heroHeadline: 'Einzigartige Designs auf Premium-Produkten',
      heroSubline: 'Print-on-Demand – jedes Stück wird eigens für dich produziert.',
      heroCtaText: 'Jetzt shoppen',
      heroCtaLink: '/kategorie/t-shirts',
      heroBackgroundImage: null,
    },
  },
}

export const WithBackgroundImage: Story = {
  args: {
    data: {
      heroHeadline: 'Sommerkollketion 2026',
      heroSubline: 'Neue Designs jetzt entdecken.',
      heroCtaText: 'Kollektion ansehen',
      heroCtaLink: '/kategorie/sommer',
      heroBackgroundImage: 'https://placehold.co/1280x400/e2e8f0/64748b?text=Hero+Bild',
    },
  },
}

export const Loading: Story = {
  render: () => <HeroBlockSkeleton />,
}
```

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Storybook Konfiguration
- [ ] `frontend/.storybook/main.ts` — Storybook 10.2.x Config: Framework `@storybook/nextjs-vite`, Stories-Glob `../stories/**/*.stories.@(ts|tsx)`, Addons `@storybook/addon-essentials`
- [ ] `frontend/.storybook/preview.ts` — Global CSS-Imports (`globals.css` + `generated-theme.css`), Default Viewport, Backgrounds

### Package.json Änderungen
- [ ] `frontend/package.json` — devDependencies: `storybook ^10.2.0`, `@storybook/nextjs-vite ^10.2.0`, `@storybook/addon-essentials ^10.2.0`, `@storybook/react ^10.2.0`
- [ ] `frontend/package.json` — Scripts: `"storybook": "storybook dev -p 6006"`, `"build-storybook": "storybook build"`

### Stories: UI Primitives
- [ ] `frontend/stories/ui/Button.stories.tsx` — 6 Variant-Stories (Default, Destructive, Outline, Ghost, Link, Secondary) + Loading + Disabled + 3 Size-Stories (Small, Large, Icon)
- [ ] `frontend/stories/ui/Card.stories.tsx` — Default-Variant-Story + Interactive-Variant-Story; Compound Components (CardHeader, CardTitle, CardContent, CardFooter) sichtbar
- [ ] `frontend/stories/ui/Input.stories.tsx` — Default-Story + Error-Variante (mit `aria-invalid`)
- [ ] `frontend/stories/ui/Badge.stories.tsx` — 4 Variant-Stories (Default, Secondary, Destructive, Outline)
- [ ] `frontend/stories/ui/Skeleton.stories.tsx` — Text-Skeleton + Card-Skeleton (verschiedene Sizes)
- [ ] `frontend/stories/ui/Dialog.stories.tsx` — Open-State-Story + Closed-State-Story
- [ ] `frontend/stories/ui/Sheet.stories.tsx` — 4 Side-Stories (Top, Right, Bottom, Left)

### Stories: Layout
- [ ] `frontend/stories/layout/Header.stories.tsx` — Default-Story mit Logo + Navigation + Cart-Icon
- [ ] `frontend/stories/layout/Footer.stories.tsx` — Default-Story mit Links + Copyright
- [ ] `frontend/stories/layout/MobileMenu.stories.tsx` — Sheet-Integration: Geschlossen + Geöffnet (via `open` prop wenn möglich)
- [ ] `frontend/stories/layout/CartIcon.stories.tsx` — WithoutBadge-Story (itemCount=0) + WithBadge-Story (itemCount=3) — Badge nutzt `<Badge>`-Primitive
- [ ] `frontend/stories/layout/CookieConsentBanner.stories.tsx` — Default-Story (Banner sichtbar) mit `<Button>`-Primitives

### Stories: Product
- [ ] `frontend/stories/product/ProductCard.stories.tsx` — Default-Story mit Produkt-Mock-Daten (Titel, Preis, Bild-Placeholder); Card `variant="interactive"` sichtbar
- [ ] `frontend/stories/product/ProductCardSkeleton.stories.tsx` — Default-Story mit Skeleton-Primitive
- [ ] `frontend/stories/product/AddToCartButton.stories.tsx` — Default + Loading (isLoading) + Success (isSuccess simuliert via args) + Disabled + VariantWaehlen-Stories
- [ ] `frontend/stories/product/ImageGallery.stories.tsx` — Default-Story mit Mock-Bildern, Dots sichtbar, Thumbnails als `<Button>`-Primitives sichtbar
- [ ] `frontend/stories/product/VariantSelectorSize.stories.tsx` — Selected-Story + Unselected-Story + Unavailable-Story; Button `variant="default"` vs `variant="outline"` sichtbar
- [ ] `frontend/stories/product/VariantSelectorColor.stories.tsx` — Default-Story mit Mock-Farben (Rot, Blau, Grün); Selected-State (Ring) + Unavailable-State (Strikethrough)

### Stories: Cart (ALLE mit CartContext Decorator)
- [ ] `frontend/stories/cart/CartItemRow.stories.tsx` — Default-Story mit Mock-Produkt (Thumbnail, Name, Variante, Menge, Preis); Card + `<Button variant="ghost" size="icon">` Remove-Button sichtbar; CartContext Decorator
- [ ] `frontend/stories/cart/CartSummary.stories.tsx` — WithItems + Empty + Loading Stories; CartContext Decorator; `<Card>` + `<Button>` aus Slice 1 sichtbar
- [ ] `frontend/stories/cart/QuantityStepper.stories.tsx` — Default (Menge=1) + Disabled + MaxMenge-Stories; `<Button variant="outline" size="icon">` sichtbar; CartContext Decorator
- [ ] `frontend/stories/cart/EmptyCart.stories.tsx` — Default-Story mit `<Button asChild><Link>` CTA; CartContext Decorator

### Stories: Category
- [ ] `frontend/stories/category/FilterChips.stories.tsx` — Active-Story (aktiver Chip `variant="default"`) + Inactive-Story (inaktiver Chip `variant="secondary"`) + Mixed-Story (mehrere Chips, einer aktiv)

### Stories: Blocks
- [ ] `frontend/stories/blocks/HeroBlock.stories.tsx` — Loaded + WithBackgroundImage + Loading (HeroBlockSkeleton) Stories
- [ ] `frontend/stories/blocks/ProductGridBlock.stories.tsx` — Loaded (mit Mock-Produkten) + Loading + Empty-Stories
- [ ] `frontend/stories/blocks/CategoryShowcaseBlock.stories.tsx` — Loaded (mit Mock-Kategorien, Card `variant="interactive"`) + Loading-Stories
- [ ] `frontend/stories/blocks/UspBarBlock.stories.tsx` — Default-Story mit Mock-USPs

### Context: Voraussetzungen
- [ ] `frontend/contexts/cart-context.tsx` — `CartContext` als named export hinzufügen: `export const CartContext = createContext<CartContextValue | null>(null)` (Voraussetzung für Cart-Story-Decorators)

### Tests
- [ ] `frontend/tests/slices/component-primitive-migration/slice-02-storybook-setup-stories.test.ts` — Vitest-Tests für Konfigurationsdateien + alle 27 Story-Dateien (Existenz-Checks + Inhalt-Checks)
<!-- DELIVERABLES_END -->

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig und vollständig
- [ ] `pnpm storybook` startet auf Port 6006 ohne Fehler (manuelle Validierung)
- [ ] `pnpm build-storybook` erzeugt statischen Build ohne Fehler (manuelle Validierung)
- [ ] Alle 27 Story-Dateien existieren und exportieren mindestens eine Default + Named Story
- [ ] Alle Cart-Stories haben CartContext Decorator — kein "useCart must be used within CartProvider"-Fehler
- [ ] Tailwind Theme Tokens (bg-primary, text-text-primary, etc.) werden korrekt angewendet (manueller visueller Check)
- [ ] Vitest-Test-Suite grün: `pnpm test tests/slices/component-primitive-migration/slice-02-storybook-setup-stories.test.ts`
- [ ] Alle 151+ bestehenden Tests bleiben grün nach Storybook-Installation: `pnpm test`

---

## Skill Verification (Storybook-Implementation)

### React Best Practices Verification

**Medium Priority:**
- [ ] `rerender-memo`: Kein Problem — Stories sind statische Definitionen, keine Performance-kritischen Components
- [ ] `rendering-conditional-render`: Stories nutzen ternary statt `&&` für conditional Content

### Web Design Guidelines Verification

**Accessibility:**
- [ ] Icon-only Buttons in Stories haben `aria-label` als Arg (z.B. Sheet-Close-Button)
- [ ] Stories demonstrieren disabled-State korrekt (nicht nur visuell, sondern auch `aria-disabled`)
- [ ] Stories nutzen semantisches HTML (keine `<div onClick>` in Story-Mock-Daten)

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [ ] Stories nutzen keine hardcoded Farben in Inline-Styles — nur Theme-Token-Klassen
- [ ] `generated-theme.css` wird in `preview.ts` importiert — alle `--theme-*` CSS-Variablen verfügbar

---

## Constraints & Hinweise

**Betrifft:**
- `frontend/package.json` — devDependencies und Scripts werden erweitert
- `frontend/.storybook/` — Neuer Ordner wird erstellt
- `frontend/stories/` — Neuer Ordner mit 27 Story-Dateien wird erstellt

**Wichtige Constraints:**
- **Node.js Version:** Storybook 10 ist ESM-Only — Node.js 20.16+ required. Projekt nutzt Node.js 22.x — kompatibel.
- **Storybook Version:** 10.2.x (NICHT 8.x oder 9.x) — nur 10.x hat Day-One Next.js 16 Support.
- **Framework:** `@storybook/nextjs-vite` (Vite-basiert) — NICHT `@storybook/nextjs` (Webpack-basiert). Vite ist schneller.
- **CSS:** `globals.css` + `generated-theme.css` BEIDE importieren in `preview.ts` — `generated-theme.css` enthält die generierten `--theme-*` Variablen, `globals.css` enthält den `@theme` Block für Tailwind.
- **CartContext:** `CartContext` MUSS in `cart-context.tsx` als named export hinzugefügt werden: `export const CartContext = createContext<CartContextValue | null>(null)` (aktuell ist nur `CartProvider` und `useCart` exportiert — `CartContext` selbst ist nicht exportiert). Der Decorator importiert `CartContext` direkt (nicht `CartProvider`) und wrapp mit `<CartContext.Provider value={mockValue}>`. Ohne diesen Export schlägt der TypeScript-Build aller Cart-Stories fehl. Siehe Deliverable `frontend/contexts/cart-context.tsx`.
- **Storybook + GraphQL:** Cart-Components nutzen `useCart()` → Apollo Client calls werden NICHT gemockt — stattdessen wird der CartContext mit statischen Mock-Werten gewrapped, sodass `useCart()` die Mock-Werte zurückgibt ohne GraphQL aufzurufen.
- **`generated-theme.css` muss existieren:** Die Datei wird von `scripts/generate-theme.mjs` erzeugt. Falls sie beim Storybook-Start fehlt, schlägt der Import in `preview.ts` fehl. Hinweis: `pnpm storybook` sollte im Storybook-Script `generate-theme` als Pre-Step ausführen oder die Datei muss bereits existieren.

**Abgrenzung:**
- Storybook Interaction Tests (play functions) — Out of Scope
- Chromatic oder Visual Regression Testing — Out of Scope
- Accessibility Addon (`@storybook/addon-a11y`) — Out of Scope (nur `addon-essentials`)
- MSW (Mock Service Worker) für GraphQL-Mocking — Out of Scope (CartContext Decorator reicht)

---

## Links

- Design/Spec: `specs/phase-0/2026-02-24-component-primitive-migration/`
- Vorheriger Slice: `slice-01-component-primitive-composition.md`
- Architecture: `architecture.md` → "Technology Decisions" (Storybook 10.2.x, @storybook/nextjs-vite)
- shadcn/ui Button Primitive: `frontend/components/ui/button.tsx`
- CartContext: `frontend/contexts/cart-context.tsx`
- Tailwind CSS: `frontend/app/globals.css` + `frontend/app/generated-theme.css`
