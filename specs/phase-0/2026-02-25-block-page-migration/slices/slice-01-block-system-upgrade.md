# Slice 1: Block-System Upgrade

> **Slice 1 von 4** fuer `Block-basierte Seiten-Migration`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | — |
> | **Naechster:** | `slice-02-kategorie-seite.md` |

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-01-block-system-upgrade` |
| **Test** | `pnpm test tests/slices/block-page-migration/slice-01-block-system-upgrade.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `[]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` enthaelt `next ^16.1.6` + `vitest ^3.0.0`.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `cd frontend && pnpm test tests/slices/block-page-migration/slice-01-block-system-upgrade.test.ts` |
| **Integration Command** | `cd frontend && pnpm test tests/slices/block-page-migration/` |
| **Acceptance Command** | `cd frontend && pnpm build` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/` |
| **Mocking Strategy** | `mock_external` |

---

## Slice-Uebersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Block-System Upgrade | Ready | `slice-01-block-system-upgrade.md` |
| 2 | Kategorie-Seite als Block-Page | Pending | `slice-02-kategorie-seite.md` |
| 3 | Produkt-Seite als Block-Page | Pending | `slice-03-produkt-seite.md` |
| 4 | Rechtsseiten als Block-Pages | Pending | `slice-04-rechtsseiten.md` |

---

## Kontext & Ziel

Das bestehende Block-System unterstuetzt nur eine flache `blocks[]`-Liste pro Seite. Fuer die Migration aller Seiten auf YAML-basierte Konfiguration braucht das System:

1. Eine **Sections-Ebene** als Kompositions-Container ueber Blocks (Shopify-Pattern)
2. Ein **2D-Grid-Layout** pro Section (columns + span + row-span) fuer flexible Layouts
3. Einen **Param-Resolver** der `$route.slug` Platzhalter in YAML-Params mit URL-Werten ersetzt
4. Einen **3-tier Template-Override** (slug-spezifisch → theme → default)
5. Eine **Homepage-Migration** auf das neue Sections-Format

**Aktuelle Probleme:**
1. `lib/blocks/types.ts`: `PageConfig` hat `blocks: BlockConfig[]` — kein Sections-Konzept
2. `lib/blocks/page-config.ts`: 2-tier Lookup (theme → default), keine `$route.slug`-Auflosung
3. `app/page.tsx`: iteriert direkt ueber `pageConfig.blocks` — muss auf SectionRenderer umgestellt werden
4. `themes/default/pages/home.yaml`: flaches `blocks:` Format — muss auf `sections:` migriert werden

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Architecture Layers + Business Logic Flow

```
Browser Request
  → Next.js Route (page.tsx)
    → loadPageConfig(pageType, theme) [Config Layer]
      → 3-tier YAML lookup [fs]
      → resolveParams($route.slug → actual slug) [Config Layer]
    → SectionRenderer [Section Layer]
      → SectionLayout(columns, gap) [Layout Layer]
        → BlockRenderer [Block Layer]
          → loadBlockData(source, params) [Data Layer]
            → GraphQL (WordPress/WooCommerce) oder inline
          → BlockComponent({ data }) [Block Layer]
  → HTML Response (streamed via React Suspense)
```

### 1. Architektur-Impact

| Layer | Aenderungen |
|-------|-------------|
| `lib/blocks/types.ts` | `SectionConfig` interface NEU, `PageConfig.blocks[]` → `PageConfig.sections[]`, `BlockConfig` bekommt optionale `span?` + `row_span?`, `WooCommerceLoaderParams.query` Union erweitert, `WPPageContent` + `CategoryWithProducts` interfaces NEU |
| `lib/blocks/page-config.ts` | `resolveParams()` Funktion NEU, `loadPageConfig()` Signatur erweitert (pageType + optionale routeParams), 3-tier Lookup statt 2-tier |
| `lib/blocks/section-renderer.tsx` | NEU — Server Component, iteriert sections[], rendert SectionLayout + BlockRenderer |
| `lib/blocks/section-layout.tsx` | NEU — CSS Grid mit columns prop, mobile stack (grid-cols-1 md:grid-cols-{N}), span + row-span |
| `themes/default/pages/home.yaml` | Flat `blocks:` → `sections:` Array |
| `app/page.tsx` | Flat block-Iteration → SectionRenderer, SKELETON_MAP aktualisiert |

### 2. Datenfluss

```
YAML (sections[].blocks[].params mit $route.slug)
  ↓
loadPageConfig(pageType, theme, { slug })
  ↓ resolveParams(): $route.slug → "t-shirts"
PageConfig { sections: SectionConfig[] }
  ↓
SectionRenderer (Server Component)
  ↓ pro Section:
SectionLayout (columns=2, gap="gap-8")
  ↓ pro Block:
loadBlockData(content_source, resolvedParams)
  ↓
BlockComponent({ data })
```

### 3. Neue Typen-Definitionen

```typescript
// lib/blocks/types.ts — neue + geaenderte Interfaces

export interface SectionConfig {
  columns?: 1 | 2 | 3 | 4     // Default: 1 (fullwidth)
  gap?: string                  // Default: "gap-8" (Tailwind class)
  blocks: BlockConfig[]
}

export interface BlockConfig {
  type: string
  content_source: ContentSource
  params: LoaderParams
  span?: number       // Spalten-Spanning (1-4), Default: 1
  row_span?: number   // Zeilen-Spanning, Default: 1
}

export interface PageConfig {
  sections: SectionConfig[]
}

// Erweiterte WooCommerce Query Union
export interface WooCommerceLoaderParams {
  query: 'featured_products' | 'product_categories' | 'products_by_category' | 'product_by_slug'
  first?: number
  slug?: string
}

// Erweiterte WordPress Query Union
export interface WordPressLoaderParams {
  page_slug: string
  query?: 'custom_fields' | 'page_content'  // Default: 'custom_fields'
}

// Neue DTOs
export interface WPPageContent {
  title: string
  content: string
}

export interface CategoryWithProducts {
  products: { nodes: ProductCardData[] }
  productCategory: { name: string; description: string; slug: string }
}
```

### 4. resolveParams() Implementierung

```typescript
// lib/blocks/page-config.ts — resolveParams()

export interface RouteContext {
  slug?: string
  [key: string]: string | undefined
}

/**
 * Ersetzt $route.{key} Platzhalter in YAML-Params mit URL-Werten.
 * Unbekannte Platzhalter werden zu leerem String aufgeloest.
 */
export function resolveParams(
  params: Record<string, unknown>,
  routeContext: RouteContext
): Record<string, unknown> {
  const resolved: Record<string, unknown> = {}

  for (const [key, value] of Object.entries(params)) {
    if (typeof value === 'string' && value.startsWith('$route.')) {
      const routeKey = value.slice('$route.'.length)  // "slug" aus "$route.slug"
      const resolved_value = routeContext[routeKey]
      if (resolved_value === undefined) {
        console.warn(`resolveParams: unknown route key "${routeKey}" in param "${key}"`)
        resolved[key] = ''
      } else {
        resolved[key] = resolved_value
      }
    } else if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
      // Rekursiv fuer verschachtelte Objekte
      resolved[key] = resolveParams(value as Record<string, unknown>, routeContext)
    } else {
      resolved[key] = value
    }
  }

  return resolved
}
```

### 5. loadPageConfig() 3-tier Lookup

```typescript
// lib/blocks/page-config.ts — loadPageConfig() mit 3-tier Lookup

/**
 * Laedt YAML-Seitenkonfiguration mit 3-tier Template-Override:
 * 1. themes/{theme}/pages/{pageType}/{slug}.yaml   (slug-spezifisch)
 * 2. themes/{theme}/pages/{pageType}.yaml          (theme-generisch)
 * 3. themes/default/pages/{pageType}.yaml          (Fallback)
 *
 * WICHTIG: Nur fuer RSC (Node.js) geeignet, nicht Edge-Runtime.
 */
export function loadPageConfig(
  pageType: string,
  theme: string = 'default',
  routeParams?: RouteContext
): PageConfig {
  const slug = routeParams?.slug

  // 3-tier Lookup (exakt gemaess architecture.md "Template Override Resolution")
  // Tier 1: themes/{theme}/pages/{pageType}/{slug}.yaml  (slug-spezifisch, theme)
  // Tier 2: themes/{theme}/pages/{pageType}.yaml         (theme-generisch)
  // Tier 3: themes/default/pages/{pageType}.yaml         (Fallback, immer letzter Kandidat)
  //
  // WICHTIG: Genau 3 Kandidaten moeglich (je nach theme und slug). Kein weiterer Tier.
  // Tier 3 ist immer vorhanden. Tier 1+2 nur wenn theme !== 'default'.
  const candidates: string[] = []

  // Tier 1: slug-spezifisch (themes/{theme}/pages/{pageType}/{slug}.yaml)
  if (slug) {
    candidates.push(resolve(FRONTEND_ROOT, 'themes', theme, 'pages', pageType, `${slug}.yaml`))
  }
  // Tier 2: theme-generisch (themes/{theme}/pages/{pageType}.yaml)
  // Nur hinzufuegen wenn theme nicht schon 'default' ist (vermeidet Duplikat mit Tier 3)
  if (theme !== 'default') {
    candidates.push(resolve(FRONTEND_ROOT, 'themes', theme, 'pages', `${pageType}.yaml`))
  }
  // Tier 3: default-Fallback (themes/default/pages/{pageType}.yaml) — immer letzter Kandidat
  candidates.push(resolve(FRONTEND_ROOT, 'themes', 'default', 'pages', `${pageType}.yaml`))

  let configPath: string | undefined
  for (const candidate of candidates) {
    if (existsSync(candidate)) {
      configPath = candidate
      break
    }
  }

  if (!configPath) {
    throw new Error(`Page config not found for pageType "${pageType}", theme "${theme}", slug "${slug}"`)
  }

  const content = readFileSync(configPath, 'utf-8')
  const raw = parse(content) as { sections?: SectionConfig[]; blocks?: BlockConfig[] }

  // Backwards-Compat: Altes flat blocks[] Format in sections[] konvertieren
  if (raw.blocks && !raw.sections) {
    console.warn(`Page config "${configPath}" uses deprecated flat blocks[] format. Migrate to sections[].`)
    return { sections: [{ columns: 1, blocks: raw.blocks }] }
  }

  const config = raw as PageConfig

  if (!config.sections || !Array.isArray(config.sections) || config.sections.length === 0) {
    throw new Error(`Page config must have at least one section (pageType: ${pageType})`)
  }

  // resolveParams fuer alle Block-Params anwenden
  if (routeParams) {
    for (const section of config.sections) {
      section.blocks = section.blocks.map(block => ({
        ...block,
        params: resolveParams(block.params as Record<string, unknown>, routeParams) as LoaderParams,
      }))
    }
  }

  return config
}
```

### 6. SectionRenderer Server Component

```typescript
// lib/blocks/section-renderer.tsx

import { Suspense } from 'react'
import { SectionLayout } from './section-layout'
import { resolveBlock } from './registry'
import { loadBlockData } from './data-loaders'
import type { SectionConfig, BlockConfig } from './types'

interface SectionRendererProps {
  sections: SectionConfig[]
  skeletonMap?: Record<string, React.ReactNode>
}

async function BlockRenderer({ block }: { block: BlockConfig }) {
  const BlockComponent = resolveBlock(block.type)
  if (!BlockComponent) return null

  const { data } = await loadBlockData(block.content_source, block.params)
  return <BlockComponent data={data} />
}

export function SectionRenderer({ sections, skeletonMap = {} }: SectionRendererProps) {
  return (
    <>
      {sections.map((section, sectionIndex) => (
        <SectionLayout
          key={sectionIndex}
          columns={section.columns ?? 1}
          gap={section.gap ?? 'gap-8'}
        >
          {section.blocks.map((block, blockIndex) => {
            const skeleton = skeletonMap[block.type] ?? null
            const key = `${block.type}-${sectionIndex}-${blockIndex}`

            // Design-Entscheidung: Einheitliches Suspense-Wrapping fuer alle Block-Types (auch inline).
            // Konsistentes Pattern vereinfacht SectionRenderer-Code und ermoeglicht kuenftige
            // loadBlockData()-Aenderungen ohne Suspense-Refactoring. Inline-Blocks loesen ohne
            // Netzwerk-Request auf (vernachlaessigbarer Overhead).
            return (
              <div
                key={key}
                style={{
                  gridColumn: block.span && block.span > 1 ? `span ${block.span}` : undefined,
                  gridRow: block.row_span && block.row_span > 1 ? `span ${block.row_span}` : undefined,
                }}
              >
                <Suspense fallback={skeleton}>
                  <BlockRenderer block={block} />
                </Suspense>
              </div>
            )
          })}
        </SectionLayout>
      ))}
    </>
  )
}
```

### 7. SectionLayout CSS Grid Component

```typescript
// lib/blocks/section-layout.tsx

interface SectionLayoutProps {
  columns: 1 | 2 | 3 | 4
  gap?: string
  children: React.ReactNode
}

const COLUMN_CLASSES: Record<number, string> = {
  1: 'grid-cols-1',
  2: 'grid-cols-1 md:grid-cols-2',
  3: 'grid-cols-1 md:grid-cols-3',
  4: 'grid-cols-1 md:grid-cols-4',
}

export function SectionLayout({ columns, gap = 'gap-8', children }: SectionLayoutProps) {
  const colClass = COLUMN_CLASSES[columns] ?? 'grid-cols-1'

  return (
    <section className={`grid ${colClass} ${gap}`}>
      {children}
    </section>
  )
}
```

### 8. home.yaml Migration auf Sections-Format

```yaml
# themes/default/pages/home.yaml
# Homepage Block-Konfiguration — Sections-Format

sections:
  - columns: 1
    blocks:
      - type: hero
        content_source: wordpress
        params:
          page_slug: "/"

  - columns: 1
    blocks:
      - type: product-grid
        content_source: woocommerce
        params:
          query: featured_products
          first: 4

  - columns: 1
    blocks:
      - type: category-showcase
        content_source: woocommerce
        params:
          query: product_categories
          first: 6

  - columns: 1
    blocks:
      - type: usp-bar
        content_source: inline
        params:
          props:
            items:
              - icon: "truck"
                text: "Kostenloser Versand ab 100€"
              - icon: "shield"
                text: "Sichere Zahlung"
              - icon: "refresh"
                text: "30 Tage Rückgaberecht"
              - icon: "star"
                text: "Premium Qualität"
```

### 9. app/page.tsx Migration

```typescript
// app/page.tsx — nach Migration auf SectionRenderer

import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import { HeroBlockSkeleton } from '@/components/blocks/hero-block'
import { ProductGridBlockSkeleton } from '@/components/blocks/product-grid-block'
import { CategoryShowcaseBlockSkeleton } from '@/components/blocks/category-showcase-block'

export const revalidate = 60

const SKELETON_MAP: Record<string, React.ReactNode> = {
  'hero': <HeroBlockSkeleton />,
  'product-grid': <ProductGridBlockSkeleton />,
  'category-showcase': <CategoryShowcaseBlockSkeleton />,
  'usp-bar': null,
}

export default function HomePage() {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const pageConfig = loadPageConfig('home', theme)

  return (
    <main id="main-content">
      <div className="space-y-12">
        <SectionRenderer
          sections={pageConfig.sections}
          skeletonMap={SKELETON_MAP}
        />
      </div>
    </main>
  )
}
```

---

## Acceptance Criteria

1) GIVEN eine YAML-Datei mit `sections:` Format
   WHEN `loadPageConfig('home', 'default')` aufgerufen wird
   THEN gibt die Funktion ein `PageConfig` mit `sections: SectionConfig[]` zurueck

2) GIVEN eine alte YAML-Datei mit `blocks:` Format (Backwards-Compat)
   WHEN `loadPageConfig()` aufgerufen wird
   THEN wird die Config in `sections: [{ columns: 1, blocks: [...] }]` konvertiert und eine Warnung geloggt

3) GIVEN ein YAML-Block-Param mit `$route.slug` Platzhalter
   WHEN `resolveParams(params, { slug: 't-shirts' })` aufgerufen wird
   THEN wird `$route.slug` durch `"t-shirts"` ersetzt

4) GIVEN ein YAML-Block-Param mit unbekanntem `$route.unbekannt` Platzhalter
   WHEN `resolveParams()` aufgerufen wird
   THEN wird der Wert zu leerem String aufgeloest und eine Warnung geloggt

5) GIVEN eine YAML-Datei fuer theme "zweiter-shop" und pageType "category"
   WHEN `loadPageConfig('category', 'zweiter-shop')` aufgerufen wird
   THEN wird zuerst `themes/zweiter-shop/pages/category.yaml` geprueft, dann `themes/default/pages/category.yaml`

6) GIVEN eine slug-spezifische YAML-Datei `themes/default/pages/category/t-shirts.yaml`
   WHEN `loadPageConfig('category', 'default', { slug: 't-shirts' })` aufgerufen wird
   THEN wird Tier-1 (`themes/default/pages/category/t-shirts.yaml`) bevorzugt gegenueber Tier-3 (`themes/default/pages/category.yaml`)

7) GIVEN `SectionLayout` mit `columns={2}` und zwei Blocks
   WHEN die Komponente gerendert wird
   THEN enthaelt das DOM `grid grid-cols-1 md:grid-cols-2` (mobile Stack, Desktop 2-Spalten)

8) GIVEN `themes/default/pages/home.yaml` mit 4 Sections (hero, product-grid, category-showcase, usp-bar)
   WHEN `loadPageConfig('home', 'default')` aufgerufen wird
   THEN enthaelt das Ergebnis `sections` mit genau 4 Eintraegen, wobei `sections[0].blocks[0].type === 'hero'`, `sections[1].blocks[0].type === 'product-grid'`, `sections[2].blocks[0].type === 'category-showcase'` und `sections[3].blocks[0].type === 'usp-bar'`

9) GIVEN ein Block mit `span: 2` in einer `columns: 3` Section
   WHEN `SectionRenderer` rendert
   THEN erhaelt das Block-Wrapper-div `style="grid-column: span 2"`

---

## Testfaelle

### Test-Datei

`tests/slices/block-page-migration/slice-01-block-system-upgrade.test.ts`

<test_spec>
```typescript
// tests/slices/block-page-migration/slice-01-block-system-upgrade.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { resolveParams } from '../../../frontend/lib/blocks/page-config'

describe('Slice 01: Block-System Upgrade', () => {

  // ============================================================
  // resolveParams()
  // ============================================================

  describe('resolveParams()', () => {
    it('should replace $route.slug placeholder with actual slug', () => {
      // Arrange
      const params = { query: 'products_by_category', slug: '$route.slug' }
      const routeContext = { slug: 't-shirts' }

      // Act
      const result = resolveParams(params, routeContext)

      // Assert
      expect(result.slug).toBe('t-shirts')
    })

    it('should leave non-placeholder values unchanged', () => {
      // Arrange
      const params = { query: 'featured_products', first: 4 }
      const routeContext = { slug: 't-shirts' }

      // Act
      const result = resolveParams(params, routeContext)

      // Assert
      expect(result.query).toBe('featured_products')
      expect(result.first).toBe(4)
    })

    it('should resolve unknown $route key to empty string and warn', () => {
      // Arrange
      const params = { id: '$route.unknown' }
      const routeContext = { slug: 't-shirts' }
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})

      // Act
      const result = resolveParams(params, routeContext)

      // Assert
      expect(result.id).toBe('')
      expect(warnSpy).toHaveBeenCalledWith(
        expect.stringContaining('unknown route key "unknown"')
      )

      warnSpy.mockRestore()
    })

    it('should recursively resolve nested objects', () => {
      // Arrange
      const params = { nested: { slug: '$route.slug', other: 'static' } }
      const routeContext = { slug: 'hoodies' }

      // Act
      const result = resolveParams(params, routeContext)

      // Assert
      expect((result.nested as Record<string, unknown>).slug).toBe('hoodies')
      expect((result.nested as Record<string, unknown>).other).toBe('static')
    })

    it('should handle params without any placeholders', () => {
      // Arrange
      const params = { page_slug: '/', query: 'custom_fields' }
      const routeContext = {}

      // Act
      const result = resolveParams(params, routeContext)

      // Assert
      expect(result).toEqual({ page_slug: '/', query: 'custom_fields' })
    })

    it('should handle array values without modification', () => {
      // Arrange
      const params = { items: ['a', 'b', 'c'] }
      const routeContext = { slug: 'test' }

      // Act
      const result = resolveParams(params, routeContext)

      // Assert
      expect(result.items).toEqual(['a', 'b', 'c'])
    })
  })

  // ============================================================
  // SectionLayout column classes
  // ============================================================

  describe('SectionLayout column mapping', () => {
    it('should map columns=1 to grid-cols-1', () => {
      // Arrange — COLUMN_CLASSES konstante aus section-layout.tsx
      const COLUMN_CLASSES: Record<number, string> = {
        1: 'grid-cols-1',
        2: 'grid-cols-1 md:grid-cols-2',
        3: 'grid-cols-1 md:grid-cols-3',
        4: 'grid-cols-1 md:grid-cols-4',
      }

      // Assert
      expect(COLUMN_CLASSES[1]).toBe('grid-cols-1')
    })

    it('should map columns=2 to responsive 2-column class', () => {
      const COLUMN_CLASSES: Record<number, string> = {
        1: 'grid-cols-1',
        2: 'grid-cols-1 md:grid-cols-2',
        3: 'grid-cols-1 md:grid-cols-3',
        4: 'grid-cols-1 md:grid-cols-4',
      }

      expect(COLUMN_CLASSES[2]).toBe('grid-cols-1 md:grid-cols-2')
    })

    it('should apply mobile-first stacking for all column values', () => {
      const COLUMN_CLASSES: Record<number, string> = {
        1: 'grid-cols-1',
        2: 'grid-cols-1 md:grid-cols-2',
        3: 'grid-cols-1 md:grid-cols-3',
        4: 'grid-cols-1 md:grid-cols-4',
      }

      // Alle Breakpoints beginnen mit grid-cols-1 (Mobile Stack)
      for (const cls of Object.values(COLUMN_CLASSES)) {
        expect(cls).toMatch(/^grid-cols-1/)
      }
    })
  })

  // ============================================================
  // loadPageConfig() 3-tier Lookup (mit fs-Mock)
  // ============================================================

  describe('loadPageConfig() 3-tier template override', () => {
    it('should prefer slug-specific yaml over generic pageType yaml', async () => {
      // Arrange — Mock fuer fs.existsSync
      const { existsSync, readFileSync } = await import('fs')
      vi.mock('fs')

      const mockExistsSync = vi.mocked(existsSync)
      const mockReadFileSync = vi.mocked(readFileSync)

      const slugSpecificYaml = `
sections:
  - columns: 1
    blocks:
      - type: hero
        content_source: inline
        params:
          props: {}
`
      // slug-spezifische Datei existiert
      mockExistsSync.mockImplementation((p: unknown) => {
        return typeof p === 'string' && p.includes('t-shirts.yaml')
      })
      mockReadFileSync.mockReturnValue(slugSpecificYaml)

      const { loadPageConfig } = await import('../../../frontend/lib/blocks/page-config')

      // Act
      const config = loadPageConfig('category', 'default', { slug: 't-shirts' })

      // Assert
      expect(config.sections).toBeDefined()
      expect(config.sections.length).toBeGreaterThan(0)

      vi.restoreAllMocks()
    })

    it('should fall back to default pageType yaml when theme-specific does not exist', async () => {
      // Arrange — Mock fuer fs: theme-spezifische Datei fehlt, nur default existiert
      const { existsSync, readFileSync } = await import('fs')
      vi.mock('fs')

      const mockExistsSync = vi.mocked(existsSync)
      const mockReadFileSync = vi.mocked(readFileSync)

      const defaultYaml = `
sections:
  - columns: 1
    blocks:
      - type: product-grid
        content_source: woocommerce
        params:
          query: featured_products
          first: 4
`
      // Nur default-Datei existiert, theme-spezifische nicht
      mockExistsSync.mockImplementation((p: unknown) => {
        return typeof p === 'string' && p.includes('themes/default/pages/category.yaml')
      })
      mockReadFileSync.mockReturnValue(defaultYaml)

      const { loadPageConfig } = await import('../../../frontend/lib/blocks/page-config')

      // Act
      const config = loadPageConfig('category', 'zweiter-shop')

      // Assert — Fallback auf default verwendet
      expect(config.sections).toBeDefined()
      expect(config.sections[0].blocks[0].type).toBe('product-grid')

      vi.restoreAllMocks()
    })

    it('should convert deprecated flat blocks[] format to sections[]', async () => {
      const { existsSync, readFileSync } = await import('fs')
      vi.mock('fs')

      const mockExistsSync = vi.mocked(existsSync)
      const mockReadFileSync = vi.mocked(readFileSync)
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})

      const legacyYaml = `
blocks:
  - type: hero
    content_source: inline
    params:
      props: {}
`
      mockExistsSync.mockReturnValue(true)
      mockReadFileSync.mockReturnValue(legacyYaml)

      const { loadPageConfig } = await import('../../../frontend/lib/blocks/page-config')

      // Act
      const config = loadPageConfig('home')

      // Assert — Konvertierung auf sections[]
      expect(config.sections).toBeDefined()
      expect(config.sections[0].columns).toBe(1)
      expect(config.sections[0].blocks[0].type).toBe('hero')
      expect(warnSpy).toHaveBeenCalledWith(expect.stringContaining('deprecated flat blocks[]'))

      vi.restoreAllMocks()
      warnSpy.mockRestore()
    })
  })

  // ============================================================
  // loadPageConfig() — homepage sections structure (AC-8)
  // ============================================================

  describe('loadPageConfig() homepage sections structure', () => {
    it('should load home.yaml with 4 sections in correct block type order', async () => {
      // Arrange
      const { existsSync, readFileSync } = await import('fs')
      vi.mock('fs')

      const mockExistsSync = vi.mocked(existsSync)
      const mockReadFileSync = vi.mocked(readFileSync)

      const homeYaml = `
sections:
  - columns: 1
    blocks:
      - type: hero
        content_source: wordpress
        params:
          page_slug: "/"
  - columns: 1
    blocks:
      - type: product-grid
        content_source: woocommerce
        params:
          query: featured_products
          first: 4
  - columns: 1
    blocks:
      - type: category-showcase
        content_source: woocommerce
        params:
          query: product_categories
          first: 6
  - columns: 1
    blocks:
      - type: usp-bar
        content_source: inline
        params:
          props: {}
`
      mockExistsSync.mockReturnValue(true)
      mockReadFileSync.mockReturnValue(homeYaml)

      const { loadPageConfig } = await import('../../../frontend/lib/blocks/page-config')

      // Act
      const config = loadPageConfig('home', 'default')

      // Assert — 4 Sections mit korrekten Block-Types
      expect(config.sections).toHaveLength(4)
      expect(config.sections[0].blocks[0].type).toBe('hero')
      expect(config.sections[1].blocks[0].type).toBe('product-grid')
      expect(config.sections[2].blocks[0].type).toBe('category-showcase')
      expect(config.sections[3].blocks[0].type).toBe('usp-bar')

      vi.restoreAllMocks()
    })
  })

  // ============================================================
  // PageConfig type contracts
  // ============================================================

  describe('PageConfig type structure', () => {
    it('should accept SectionConfig with default column=1', () => {
      // Type-level test — verifiziert dass Inferenz funktioniert
      const section = {
        blocks: [{
          type: 'hero',
          content_source: 'inline' as const,
          params: { props: {} },
        }],
      }
      // columns ist optional — kein Fehler ohne columns
      expect(section.blocks.length).toBe(1)
    })

    it('should support span and row_span on BlockConfig', () => {
      const block = {
        type: 'product-grid',
        content_source: 'woocommerce' as const,
        params: { query: 'featured_products' as const, first: 4 },
        span: 2,
        row_span: 1,
      }
      expect(block.span).toBe(2)
      expect(block.row_span).toBe(1)
    })

    it('should compute gridColumn style from span (AC-9)', () => {
      // Tests the ternary: block.span && block.span > 1 ? `span ${block.span}` : undefined
      const computeGridColumn = (span?: number) =>
        span && span > 1 ? `span ${span}` : undefined

      expect(computeGridColumn(2)).toBe('span 2')
      expect(computeGridColumn(3)).toBe('span 3')
      expect(computeGridColumn(1)).toBeUndefined()  // span=1 → no inline style
      expect(computeGridColumn(undefined)).toBeUndefined()
    })

    it('should compute gridRow style from row_span', () => {
      const computeGridRow = (rowSpan?: number) =>
        rowSpan && rowSpan > 1 ? `span ${rowSpan}` : undefined

      expect(computeGridRow(2)).toBe('span 2')
      expect(computeGridRow(1)).toBeUndefined()
      expect(computeGridRow(undefined)).toBeUndefined()
    })
  })
})
```
</test_spec>

---

## Integration Contract (GATE 2 PFLICHT)

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| — | — | — | Keine Dependencies |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SectionConfig` | TypeScript Interface | Slice 2, 3, 4 | `{ columns?: 1\|2\|3\|4; gap?: string; blocks: BlockConfig[] }` |
| `BlockConfig.span` | optional number | Slice 2, 3, 4 | `span?: number` auf `BlockConfig` |
| `BlockConfig.row_span` | optional number | Slice 2, 3, 4 | `row_span?: number` auf `BlockConfig` |
| `PageConfig.sections` | TypeScript Interface | Slice 2, 3, 4 | `{ sections: SectionConfig[] }` |
| `WooCommerceLoaderParams.query` | Union Type | Slice 2, 3 | `'featured_products' \| 'product_categories' \| 'products_by_category' \| 'product_by_slug'` |
| `WordPressLoaderParams.query` | Union Type | Slice 4 | `'custom_fields' \| 'page_content'` |
| `WPPageContent` | TypeScript Interface | Slice 4 | `{ title: string; content: string }` |
| `CategoryWithProducts` | TypeScript Interface | Slice 2 | `{ products: { nodes: ProductCardData[] }; productCategory: { name, description, slug } }` |
| `resolveParams()` | Function | Slice 2, 3, 4 (via loadPageConfig) | `(params: Record<string, unknown>, routeContext: RouteContext) => Record<string, unknown>` |
| `loadPageConfig()` | Function | Slice 2, 3, 4 | `(pageType: string, theme?: string, routeParams?: RouteContext) => PageConfig` |
| `SectionRenderer` | React Server Component | Slice 2, 3, 4 | Props: `{ sections: SectionConfig[], skeletonMap?: Record<string, ReactNode> }` |
| `SectionLayout` | React Server Component | `SectionRenderer` | Props: `{ columns: 1\|2\|3\|4, gap?: string, children: ReactNode }` |

### Integration Validation Tasks

- [ ] `SectionConfig` Interface exportiert und in Slice 2, 3, 4 verwendbar
- [ ] `loadPageConfig()` neue Signatur ist rueckwaertskompatibel (altes Format → Warnung + Konvertierung)
- [ ] `SectionRenderer` als Named Export aus `lib/blocks/section-renderer.tsx`
- [ ] `resolveParams()` als Named Export aus `lib/blocks/page-config.ts`
- [ ] Homepage rendert nach Migration identisch (kein visueller Unterschied)

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind PFLICHT-Deliverables. Der Gate 2 Compliance Agent prueft, dass jedes Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `SectionConfig` Interface | Abschnitt 3 | YES | Exakt so in `lib/blocks/types.ts` |
| `BlockConfig` mit `span?` + `row_span?` | Abschnitt 3 | YES | `span` und `row_span` optional |
| `PageConfig.sections[]` | Abschnitt 3 | YES | `blocks[]` wird ersetzt durch `sections[]` |
| `WooCommerceLoaderParams` erweiterte Query-Union | Abschnitt 3 | YES | Alle 4 Queries im Union |
| `WordPressLoaderParams` mit `query?` | Abschnitt 3 | YES | `query` optional, Default `'custom_fields'` |
| `WPPageContent` Interface | Abschnitt 3 | YES | `{ title: string; content: string }` |
| `CategoryWithProducts` Interface | Abschnitt 3 | YES | Kombiniertes Response-DTO |
| `resolveParams()` Funktion | Abschnitt 4 | YES | `$route.{key}` Regex-freie String-Replacement-Logik |
| `loadPageConfig()` 3-tier Lookup | Abschnitt 5 | YES | Candidates-Array in Reihenfolge, existsSync-Loop |
| Backwards-Compat `blocks[]` → `sections[]` Konvertierung | Abschnitt 5 | YES | console.warn + Konvertierung |
| `SectionRenderer` Server Component | Abschnitt 6 | YES | Named Export, Suspense-Wrapping, span/row_span als inline style |
| `SectionLayout` CSS Grid Component | Abschnitt 7 | YES | `COLUMN_CLASSES` Map, `grid grid-cols-1 md:grid-cols-{N}` |
| `home.yaml` Sections-Format | Abschnitt 8 | YES | Exaktes YAML-Format mit `sections:` Key |
| `app/page.tsx` mit `SectionRenderer` | Abschnitt 9 | YES | `SectionRenderer` statt flat block-Iteration |

---

## Links

- Discovery: `specs/phase-0/2026-02-25-block-page-migration/discovery.md`
- Architecture: `specs/phase-0/2026-02-25-block-page-migration/architecture.md`
- Bestehendes Block-System: `frontend/lib/blocks/`
- Shopify Sections-Pattern: https://shopify.dev/docs/storefronts/themes/architecture/sections

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
### Lib / Block-System

- [ ] `frontend/lib/blocks/types.ts` — `SectionConfig` + erweitertes `BlockConfig` (span, row_span) + erweitertes `PageConfig` (sections[]) + `WooCommerceLoaderParams` erweitert + `WordPressLoaderParams` erweitert + `WPPageContent` + `CategoryWithProducts` interfaces
- [ ] `frontend/lib/blocks/page-config.ts` — `resolveParams()` Funktion NEU + `loadPageConfig()` Signatur erweitert (pageType, theme, routeParams?) + 3-tier Lookup + Backwards-Compat fuer altes `blocks[]` Format
- [ ] `frontend/lib/blocks/section-renderer.tsx` — NEU: Server Component, iteriert sections[], rendert SectionLayout + BlockRenderer mit Suspense
- [ ] `frontend/lib/blocks/section-layout.tsx` — NEU: CSS Grid Server Component mit columns (1-4), gap, span/row_span Unterstuetzung

### Frontend

- [ ] `frontend/themes/default/pages/home.yaml` — Migration von `blocks:` auf `sections:` Format (4 Sections, je columns: 1)
- [ ] `frontend/app/page.tsx` — Flat block-Iteration ersetzt durch `<SectionRenderer sections={pageConfig.sections} skeletonMap={SKELETON_MAP} />`

### Tests

- [ ] `frontend/tests/slices/block-page-migration/slice-01-block-system-upgrade.test.ts` — Unit Tests fuer `resolveParams()`, `SectionLayout` column-Classes, `loadPageConfig()` Backwards-Compat, Typ-Struktur
<!-- DELIVERABLES_END -->

---

## Constraints & Hinweise

**Betrifft:**
- `lib/blocks/types.ts`: Bestehende `PageConfig.blocks[]` Referenz in `app/page.tsx` und `lib/blocks/page-config.ts` muss migriert werden
- `lib/blocks/page-config.ts`: Bestehende Aufruf-Signatur `loadPageConfig(slug, theme)` aendert sich auf `loadPageConfig(pageType, theme, routeParams?)` — alle Aufrufer (derzeit nur `app/page.tsx`) muessen aktualisiert werden
- `lib/blocks/data-loaders.ts`: Typ-Erweiterungen in `WooCommerceLoaderParams` und `WordPressLoaderParams` sind rueckwaertskompatibel (Union-Erweiterung, kein Breaking Change)

**Design-Entscheidung: Suspense fuer inline-Blocks**
- `SectionRenderer` wrappet alle Blocks (inkl. `content_source: inline`) einheitlich in `<Suspense>`. Dies ist eine bewusste Abkehr vom alten `app/page.tsx` (das inline-Blocks ohne Suspense renderte). Begruendung: Konsistentes Pattern fuer alle Block-Types vereinfacht den `SectionRenderer`-Code und ermoeglicht kuenftige Aenderungen an `loadBlockData()` ohne Suspense-Refactoring. Inline-Blocks sind trivial schnell, der Overhead ist vernachlaessigbar.

**Abgrenzung:**
- Neue Data-Loader-Handler (`products_by_category`, `product_by_slug`, `page_content`) sind NICHT in diesem Slice — diese kommen in Slice 2, 3, 4
- Neue Block-Components (`page-heading-block`, `filter-chips-block`, etc.) sind NICHT in diesem Slice
- Neue Route-Pages (`app/kategorie/`, `app/produkt/`, `app/impressum/` etc.) sind NICHT in diesem Slice
- Neue YAML-Dateien (`category.yaml`, `product.yaml`, `legal.yaml`) sind NICHT in diesem Slice

**API Contract:**
- `loadPageConfig()` neue Signatur: `(pageType: string, theme?: string, routeParams?: RouteContext) => PageConfig`
- `resolveParams()` exportiert als Named Export aus `lib/blocks/page-config.ts`
- `SectionRenderer` exportiert als Named Export aus `lib/blocks/section-renderer.tsx`
- `SectionLayout` exportiert als Named Export aus `lib/blocks/section-layout.tsx`
- Keine aenderungen an `resolveBlock()`, `loadBlockData()` oder bestehenden Block-Components

---

## Skill Verification

### React Best Practices Verification

- [x] `async-suspense-boundaries`: `SectionRenderer` wrappet jeden async Block in `<Suspense fallback={skeleton}>`
- [x] `server-cache-react`: Bestehender Apollo `getClient()` nutzt `React.cache()` Deduplication (unveraendert)
- [x] `rendering-hoist-jsx`: `SKELETON_MAP` ist modul-level Konstante (kein Re-Create per Render)

### Web Design Guidelines Verification

- [x] Semantic HTML: `SectionLayout` verwendet `<section>` Tag
- [x] Mobile-first: `grid-cols-1` als Base, `md:grid-cols-{N}` fuer Desktop

### Tailwind v4 Patterns Verification

- [x] Responsive: `grid-cols-1 md:grid-cols-{N}` — Mobile-first Breakpoint
- [x] Keine hardcoded Werte: `gap` als konfigurierbare Tailwind-Klasse
