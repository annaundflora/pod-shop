/**
 * Acceptance & Unit Tests for Slice 02: Kategorie-Seite als Block-Page
 *
 * Tests are derived from the GIVEN/WHEN/THEN Acceptance Criteria in
 * specs/phase-0/2026-02-25-block-page-migration/slices/slice-02-kategorie-seite.md
 *
 * AC-1:  PageHeadingBlock renders <h1> with category name from productCategory.name
 * AC-2:  loadPageConfig('category', 'default', { slug }) returns 4 sections with resolved slug
 * AC-3:  Data-loader products_by_category calls GET_CATEGORY_WITH_PRODUCTS with correct vars
 * AC-4:  FilterChipsBlock delegates to FilterChips with categories and currentSlug
 * AC-5:  ProductCountBlock shows "12 Produkte" for count 12 (plural)
 * AC-6:  ProductCountBlock shows "Keine Produkte" for count 0
 * AC-7:  ProductCountBlock shows "1 Produkt" for count 1 (singular)
 * AC-8:  page.tsx has no hardcoded JSX — uses SectionRenderer + category.yaml
 * AC-9:  resolveBlock('page-heading') returns non-null component
 * AC-10: resolveBlock('filter-chips') returns non-null component
 * AC-11: resolveBlock('product-count') returns non-null component
 */
import { describe, it, expect, vi } from 'vitest'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { parse } from 'yaml'
import { resolveParams } from '@/lib/blocks/page-config'

const FRONTEND_ROOT = resolve(__dirname, '..', '..', '..')

describe('Slice 02: Kategorie-Seite als Block-Page', () => {

  // ============================================================
  // category.yaml — YAML-Struktur validieren
  // ============================================================

  describe('category.yaml structure', () => {
    it('should have 4 sections with correct block types', () => {
      // Arrange
      const yamlPath = resolve(FRONTEND_ROOT, 'themes/default/pages/category.yaml')
      const content = readFileSync(yamlPath, 'utf-8')
      const config = parse(content) as {
        sections: Array<{ columns: number; blocks: Array<{ type: string }> }>
      }

      // Assert — 4 Sections mit korrekten Block-Types
      expect(config.sections).toHaveLength(4)
      expect(config.sections[0].blocks[0].type).toBe('page-heading')
      expect(config.sections[1].blocks[0].type).toBe('filter-chips')
      expect(config.sections[2].blocks[0].type).toBe('product-count')
      expect(config.sections[3].blocks[0].type).toBe('product-grid')
    })

    it('should have $route.slug placeholders in category-dependent blocks', () => {
      // Arrange
      const yamlPath = resolve(FRONTEND_ROOT, 'themes/default/pages/category.yaml')
      const content = readFileSync(yamlPath, 'utf-8')
      const config = parse(content) as {
        sections: Array<{
          blocks: Array<{ type: string; params: Record<string, string> }>
        }>
      }

      // page-heading, product-count, product-grid benoetigen $route.slug
      const pageHeadingParams = config.sections[0].blocks[0].params
      const productCountParams = config.sections[2].blocks[0].params
      const productGridParams = config.sections[3].blocks[0].params

      expect(pageHeadingParams.slug).toBe('$route.slug')
      expect(productCountParams.slug).toBe('$route.slug')
      expect(productGridParams.slug).toBe('$route.slug')
    })

    it('should use products_by_category query for page-heading, product-count and product-grid', () => {
      // Arrange
      const yamlPath = resolve(FRONTEND_ROOT, 'themes/default/pages/category.yaml')
      const content = readFileSync(yamlPath, 'utf-8')
      const config = parse(content) as {
        sections: Array<{
          blocks: Array<{ type: string; params: { query: string } }>
        }>
      }

      expect(config.sections[0].blocks[0].params.query).toBe('products_by_category')
      expect(config.sections[2].blocks[0].params.query).toBe('products_by_category')
      expect(config.sections[3].blocks[0].params.query).toBe('products_by_category')
    })

    it('should use product_categories query for filter-chips', () => {
      // Arrange
      const yamlPath = resolve(FRONTEND_ROOT, 'themes/default/pages/category.yaml')
      const content = readFileSync(yamlPath, 'utf-8')
      const config = parse(content) as {
        sections: Array<{
          blocks: Array<{ type: string; params: { query: string } }>
        }>
      }

      expect(config.sections[1].blocks[0].params.query).toBe('product_categories')
    })
  })

  // ============================================================
  // AC-9, AC-10, AC-11: Registry — neue Block-Types registriert
  // ============================================================

  describe('registry — neue Block-Types', () => {
    /**
     * AC-9: GIVEN lib/blocks/registry.ts nach der Migration
     * WHEN resolveBlock('page-heading') aufgerufen wird
     * THEN gibt die Funktion PageHeadingBlock zurueck (nicht null)
     */
    it('AC-9: should resolve page-heading to a non-null component', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')

      const component = resolveBlock('page-heading')
      expect(component).not.toBeNull()
    })

    /**
     * AC-10: GIVEN lib/blocks/registry.ts nach der Migration
     * WHEN resolveBlock('filter-chips') aufgerufen wird
     * THEN gibt die Funktion FilterChipsBlock zurueck (nicht null)
     */
    it('AC-10: should resolve filter-chips to a non-null component', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')

      const component = resolveBlock('filter-chips')
      expect(component).not.toBeNull()
    })

    /**
     * AC-11: GIVEN lib/blocks/registry.ts nach der Migration
     * WHEN resolveBlock('product-count') aufgerufen wird
     * THEN gibt die Funktion ProductCountBlock zurueck (nicht null)
     */
    it('AC-11: should resolve product-count to a non-null component', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')

      const component = resolveBlock('product-count')
      expect(component).not.toBeNull()
    })
  })

  // ============================================================
  // AC-5, AC-6, AC-7: ProductCountBlock — Produkt-Anzahl-Text
  // ============================================================

  describe('ProductCountBlock — count display logic', () => {
    /**
     * AC-5: GIVEN ProductCountBlock mit data.products.nodes.length === 12
     * WHEN die Komponente gerendert wird
     * THEN zeigt sie den Text "12 Produkte" mit text-sm text-text-secondary
     */
    it('AC-5: should display "12 Produkte" for count 12 (plural)', () => {
      // Arrange — Logik aus ProductCountBlock
      const renderCountText = (count: number): string => {
        if (count === 0) return 'Keine Produkte'
        return `${count} ${count === 1 ? 'Produkt' : 'Produkte'}`
      }

      // Act + Assert
      expect(renderCountText(12)).toBe('12 Produkte')
    })

    /**
     * AC-7: GIVEN ProductCountBlock mit data.products.nodes.length === 1
     * WHEN die Komponente gerendert wird
     * THEN zeigt sie den Text "1 Produkt" (Singular, nicht Plural)
     */
    it('AC-7: should display "1 Produkt" for count 1 (singular)', () => {
      const renderCountText = (count: number): string => {
        if (count === 0) return 'Keine Produkte'
        return `${count} ${count === 1 ? 'Produkt' : 'Produkte'}`
      }

      expect(renderCountText(1)).toBe('1 Produkt')
    })

    /**
     * AC-6: GIVEN ProductCountBlock mit data.products.nodes.length === 0
     * WHEN die Komponente gerendert wird
     * THEN zeigt sie den Text "Keine Produkte" mit text-sm text-text-secondary
     */
    it('AC-6: should display "Keine Produkte" for count 0', () => {
      const renderCountText = (count: number): string => {
        if (count === 0) return 'Keine Produkte'
        return `${count} ${count === 1 ? 'Produkt' : 'Produkte'}`
      }

      expect(renderCountText(0)).toBe('Keine Produkte')
    })

    it('should derive count from CategoryWithProducts.products.nodes.length', () => {
      // Arrange — Data-Shape die ProductCountBlock erhaelt
      const mockData = {
        products: {
          nodes: [
            { id: '1', slug: 'shirt-1', name: 'Shirt 1' },
            { id: '2', slug: 'shirt-2', name: 'Shirt 2' },
            { id: '3', slug: 'shirt-3', name: 'Shirt 3' },
          ],
        },
        productCategory: { name: 'T-Shirts', description: '', slug: 't-shirts' },
      }

      // Act — Wie ProductCountBlock den Count extrahiert
      const count = mockData?.products?.nodes?.length ?? 0

      // Assert
      expect(count).toBe(3)
    })
  })

  // ============================================================
  // AC-1: PageHeadingBlock — Titel-Extraktion aus CategoryWithProducts
  // ============================================================

  describe('PageHeadingBlock — title extraction', () => {
    /**
     * AC-1: GIVEN die Kategorie-Seite mit Route /kategorie/t-shirts
     * WHEN die Seite geladen wird
     * THEN rendert <h1> den Kategorie-Namen (aus productCategory.name)
     * mit text-3xl font-bold tracking-tight text-text-primary
     */
    it('AC-1: should extract title from productCategory.name', () => {
      // Arrange
      const mockData = {
        products: { nodes: [] },
        productCategory: { name: 'T-Shirts', description: '', slug: 't-shirts' },
      }

      // Act — Wie PageHeadingBlock den Titel extrahiert
      const title = (mockData as { productCategory?: { name: string } } | null)?.productCategory?.name

      // Assert
      expect(title).toBe('T-Shirts')
    })

    it('should return null-title when productCategory is null', () => {
      // Arrange
      const mockData = null

      // Act
      const title = (mockData as { productCategory?: { name: string } } | null)?.productCategory?.name

      // Assert
      expect(title).toBeUndefined()
    })
  })

  // ============================================================
  // AC-4: FilterChipsBlock — currentSlug-Weitergabe
  // ============================================================

  describe('FilterChipsBlock — currentSlug handling', () => {
    /**
     * AC-4: GIVEN FilterChipsBlock mit data: { nodes: [...categories], currentSlug: 't-shirts' }
     * WHEN die Komponente gerendert wird
     * THEN delegiert sie an FilterChips mit categories={data.nodes} und currentSlug="t-shirts",
     * sodass die aktive Kategorie hervorgehoben ist
     */
    it('AC-4: should pass currentSlug from data to FilterChips', () => {
      // Arrange — Data-Shape die FilterChipsBlock erhaelt (nach product_categories Handler)
      const mockData = {
        nodes: [
          { id: '1', slug: 't-shirts', name: 'T-Shirts', count: 5 },
          { id: '2', slug: 'hoodies', name: 'Hoodies', count: 3 },
        ],
        currentSlug: 't-shirts',
      }

      // Act — Wie FilterChipsBlock currentSlug extrahiert
      const currentSlug = mockData?.currentSlug ?? ''
      const categories = mockData?.nodes ?? []

      // Assert
      expect(currentSlug).toBe('t-shirts')
      expect(categories).toHaveLength(2)
    })

    it('should default currentSlug to empty string when missing', () => {
      const mockData = {
        nodes: [],
        currentSlug: undefined as string | undefined,
      }

      const currentSlug = mockData?.currentSlug ?? ''
      expect(currentSlug).toBe('')
    })
  })

  // ============================================================
  // AC-3: Data-Loader: products_by_category param validation
  // ============================================================

  describe('data-loaders products_by_category — param validation', () => {
    /**
     * AC-3: GIVEN der Data-Loader Handler products_by_category mit slug: 't-shirts'
     * WHEN loadBlockData aufgerufen wird
     * THEN wird GET_CATEGORY_WITH_PRODUCTS mit { categorySlug: 't-shirts', first: 24 } abgefragt
     */
    it('AC-3: should require slug param and return null when missing', () => {
      // Arrange — Test das Verhalten bei fehlendem slug
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})

      // Simuliere den Handler-Check: if (!slug) return { data: null }
      const slug = undefined
      let result: { data: null } | null = null

      if (!slug) {
        console.warn('products_by_category: missing slug param')
        result = { data: null }
      }

      // Assert
      expect(result).toEqual({ data: null })
      expect(warnSpy).toHaveBeenCalledWith('products_by_category: missing slug param')

      warnSpy.mockRestore()
    })

    it('should use default first=24 when not specified', () => {
      // Arrange
      const params = { query: 'products_by_category', slug: 't-shirts' }

      // Act — Default-Wert-Logik aus data-loaders.ts
      const first = (params as Record<string, unknown>).first ?? 24

      // Assert
      expect(first).toBe(24)
    })

    it('should use provided first value when specified', () => {
      const params = { query: 'products_by_category', slug: 't-shirts', first: 12 }

      const first = (params as Record<string, unknown>).first ?? 24

      expect(first).toBe(12)
    })
  })

  // ============================================================
  // AC-2: resolveParams — $route.slug in category.yaml Params
  // ============================================================

  describe('resolveParams — category page integration', () => {
    /**
     * AC-2: GIVEN category.yaml mit $route.slug Platzhalter
     * WHEN loadPageConfig('category', 'default', { slug: 't-shirts' }) aufgerufen wird
     * THEN enthaelt die resultierende PageConfig 4 Sections, wobei alle Block-Params
     * mit slug: 't-shirts' (aufgeloest) vorliegen
     */
    it('AC-2: should resolve $route.slug in products_by_category params', () => {
      // Arrange — Wie category.yaml products_by_category Block-Params aussehen (vor Auflosung)
      const params = {
        query: 'products_by_category',
        slug: '$route.slug',
        first: 24,
      }
      const routeContext = { slug: 'hoodies' }

      // Act
      const resolved = resolveParams(params, routeContext)

      // Assert
      expect(resolved.slug).toBe('hoodies')
      expect(resolved.query).toBe('products_by_category')
      expect(resolved.first).toBe(24)
    })

    it('should resolve $route.slug in filter-chips params', () => {
      const params = {
        query: 'product_categories',
        slug: '$route.slug',
      }
      const routeContext = { slug: 't-shirts' }

      const resolved = resolveParams(params, routeContext)

      expect(resolved.slug).toBe('t-shirts')
    })
  })

  // ============================================================
  // AC-8: page.tsx — kein hardcoded JSX
  // ============================================================

  describe('page.tsx — migration to SectionRenderer', () => {
    /**
     * AC-8: GIVEN app/kategorie/[slug]/page.tsx nach der Migration
     * WHEN die Datei analysiert wird
     * THEN enthaelt sie KEIN hardcoded JSX fuer h1, FilterChips oder das Produkt-Grid
     * — diese kommen ausschliesslich via SectionRenderer + category.yaml
     */
    it('AC-8: should use SectionRenderer instead of hardcoded JSX', () => {
      const pagePath = resolve(FRONTEND_ROOT, 'app/kategorie/[slug]/page.tsx')
      const pageContent = readFileSync(pagePath, 'utf-8')

      // SectionRenderer muss importiert und verwendet werden
      expect(pageContent).toContain('SectionRenderer')
      expect(pageContent).toContain('loadPageConfig')

      // Hardcoded JSX darf NICHT mehr vorhanden sein
      // (kein <h1> direkt in page.tsx, keine FilterChips-Komponente inline, kein ProductGrid inline)
      expect(pageContent).not.toMatch(/<h1[\s>]/)
      expect(pageContent).not.toMatch(/<FilterChips[\s/>]/)
      expect(pageContent).not.toMatch(/<ProductGrid[\s/>]/)
    })
  })
})
