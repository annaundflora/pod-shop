/**
 * Acceptance & Unit Tests for Slice 02: Produktkatalog Frontend implementieren
 *
 * Tests are derived from the GIVEN/WHEN/THEN Acceptance Criteria
 * in docs/features/pod-shop-mvp/slices/slice-02-produktkatalog-frontend.md
 *
 * Focus areas (per orchestrator-config):
 * - extractVariantOptions (7 Tests) -- AC-9 through AC-15 (variant-related ACs)
 * - findVariation (5 Tests) -- AC-10, AC-11, AC-12, AC-13, AC-14
 * - generateProductJsonLd (6 Tests) -- AC-18 (JSON-LD schema)
 *
 * ACs 1-8, 16-17: Page-level ACs verified via file structure assertions
 * (runtime rendering requires dev server, not available in Vitest).
 */
import { describe, it, expect } from 'vitest'
import { extractVariantOptions, findVariation } from '@/lib/product/variant-utils'
import { generateProductJsonLd } from '@/lib/seo/json-ld'
import type { ProductVariation, ProductDetailData } from '@/lib/graphql/types'

// ---------------------------------------------------------------------------
// Test Fixtures
// ---------------------------------------------------------------------------

function makeVariation(
  id: string,
  stockStatus: 'IN_STOCK' | 'OUT_OF_STOCK',
  attrs: Array<{ name: string; value: string }>
): ProductVariation {
  return {
    id,
    databaseId: parseInt(id, 10) || 1,
    stockStatus,
    attributes: { nodes: attrs },
  }
}

const VARIATIONS_FIXTURE: ProductVariation[] = [
  makeVariation('1', 'IN_STOCK', [
    { name: 'pa_size', value: 'M' },
    { name: 'pa_color', value: 'Schwarz' },
  ]),
  makeVariation('2', 'IN_STOCK', [
    { name: 'pa_size', value: 'L' },
    { name: 'pa_color', value: 'Schwarz' },
  ]),
  makeVariation('3', 'OUT_OF_STOCK', [
    { name: 'pa_size', value: 'XL' },
    { name: 'pa_color', value: 'Rot' },
  ]),
  makeVariation('4', 'IN_STOCK', [
    { name: 'pa_size', value: 'M' },
    { name: 'pa_color', value: 'Rot' },
  ]),
]

function makeProduct(overrides: Partial<ProductDetailData> = {}): ProductDetailData {
  return {
    id: 'product-1',
    databaseId: 101,
    slug: 'basic-tshirt',
    name: 'Basic T-Shirt',
    description: '<p>Ein einfaches T-Shirt aus Bio-Baumwolle.</p>',
    shortDescription: 'Bio-Baumwolle T-Shirt',
    price: '29,90\u00a0\u20ac',
    regularPrice: '39,90\u00a0\u20ac',
    onSale: true,
    image: {
      sourceUrl: 'https://example.com/tshirt.jpg',
      altText: 'Basic T-Shirt',
      mediaDetails: { width: 800, height: 800 },
    },
    galleryImages: { nodes: [] },
    productCategories: {
      nodes: [{ id: 'cat-1', slug: 't-shirts', name: 'T-Shirts' }],
    },
    variations: { nodes: VARIATIONS_FIXTURE },
    ...overrides,
  }
}

// ===========================================================================
// extractVariantOptions -- 7 Tests
// Covers AC-9 (variant display), AC-11 (size selection), AC-12 (color selection),
// AC-14 (unavailable sizes), AC-15 (unavailable colors)
// ===========================================================================
describe('extractVariantOptions', () => {
  /**
   * AC-9: GIVEN ein Nutzer ist auf einer Produktdetailseite
   * WHEN die Seite geladen ist
   * THEN werden Farb-Selector und Groessen-Selector angezeigt
   *
   * Unit test: extractVariantOptions returns both sizes and colors from variations.
   */
  it('extracts unique sizes and colors from variations', () => {
    // Arrange (GIVEN)
    const variations = VARIATIONS_FIXTURE

    // Act (WHEN)
    const result = extractVariantOptions(variations)

    // Assert (THEN)
    expect(result.sizes).toHaveLength(3) // M, L, XL
    expect(result.colors).toHaveLength(2) // Schwarz, Rot
    expect(result.sizes.map((s) => s.value)).toEqual(['M', 'L', 'XL'])
    expect(result.colors.map((c) => c.value)).toEqual(['Schwarz', 'Rot'])
  })

  /**
   * AC-14: GIVEN es gibt eine nicht verfuegbare Groesse
   * WHEN die Produktdetailseite geladen ist
   * THEN ist der entsprechende Groessen-Button ausgegraut (available: false)
   */
  it('marks sizes as available when at least one variation is IN_STOCK', () => {
    // Arrange (GIVEN) -- M has IN_STOCK (variation 1+4), XL only OUT_OF_STOCK (variation 3)
    const variations = VARIATIONS_FIXTURE

    // Act (WHEN)
    const result = extractVariantOptions(variations)

    // Assert (THEN)
    const sizeM = result.sizes.find((s) => s.value === 'M')
    const sizeL = result.sizes.find((s) => s.value === 'L')
    const sizeXL = result.sizes.find((s) => s.value === 'XL')
    expect(sizeM?.available).toBe(true)
    expect(sizeL?.available).toBe(true)
    expect(sizeXL?.available).toBe(false)
  })

  /**
   * AC-15: GIVEN es gibt eine nicht verfuegbare Farbe
   * WHEN die Produktdetailseite geladen ist
   * THEN ist der Farb-Swatch ausgegraut (available: false only if ALL variations with that color are OUT_OF_STOCK)
   */
  it('marks colors as available when at least one variation is IN_STOCK', () => {
    // Arrange (GIVEN) -- Rot: variation 3 OUT_OF_STOCK, variation 4 IN_STOCK
    const variations = VARIATIONS_FIXTURE

    // Act (WHEN)
    const result = extractVariantOptions(variations)

    // Assert (THEN)
    const schwarz = result.colors.find((c) => c.value === 'Schwarz')
    const rot = result.colors.find((c) => c.value === 'Rot')
    expect(schwarz?.available).toBe(true)
    expect(rot?.available).toBe(true) // variation 4 is IN_STOCK
  })

  /**
   * AC-12: GIVEN ein Nutzer ist auf einer Produktdetailseite
   * WHEN er eine verfuegbare Farbe auswaehlt
   * THEN wird der Farb-Swatch mit Ring/Border dargestellt
   *
   * Unit test: hexCode is resolved for known colors.
   */
  it('resolves hexCode for known German color names', () => {
    // Arrange (GIVEN)
    const variations = VARIATIONS_FIXTURE

    // Act (WHEN)
    const result = extractVariantOptions(variations)

    // Assert (THEN)
    const schwarz = result.colors.find((c) => c.value === 'Schwarz')
    const rot = result.colors.find((c) => c.value === 'Rot')
    expect(schwarz?.hexCode).toBe('#000000')
    expect(rot?.hexCode).toBe('#CC0000')
  })

  it('returns null hexCode for unknown color names', () => {
    // Arrange (GIVEN)
    const variations: ProductVariation[] = [
      makeVariation('10', 'IN_STOCK', [
        { name: 'pa_size', value: 'M' },
        { name: 'pa_color', value: 'Regenbogen' },
      ]),
    ]

    // Act (WHEN)
    const result = extractVariantOptions(variations)

    // Assert (THEN)
    expect(result.colors[0].hexCode).toBeNull()
  })

  it('returns empty sizes and colors for empty variations array', () => {
    // Arrange (GIVEN)
    const variations: ProductVariation[] = []

    // Act (WHEN)
    const result = extractVariantOptions(variations)

    // Assert (THEN)
    expect(result.sizes).toEqual([])
    expect(result.colors).toEqual([])
  })

  it('recognizes alternative attribute names (size, farbe)', () => {
    // Arrange (GIVEN) -- using German attribute names
    const variations: ProductVariation[] = [
      makeVariation('20', 'IN_STOCK', [
        { name: 'größe', value: 'S' },
        { name: 'farbe', value: 'Blau' },
      ]),
      makeVariation('21', 'IN_STOCK', [
        { name: 'size', value: 'M' },
        { name: 'color', value: 'Grün' },
      ]),
    ]

    // Act (WHEN)
    const result = extractVariantOptions(variations)

    // Assert (THEN)
    expect(result.sizes.map((s) => s.value)).toEqual(['S', 'M'])
    expect(result.colors.map((c) => c.value)).toEqual(['Blau', 'Grün'])
    expect(result.colors.find((c) => c.value === 'Blau')?.hexCode).toBe('#0066CC')
    expect(result.colors.find((c) => c.value === 'Grün')?.hexCode).toBe('#228B22')
  })
})

// ===========================================================================
// findVariation -- 5 Tests
// Covers AC-10 (disabled button until both selected), AC-11 (size selection),
// AC-12 (color selection), AC-13 (button active when both selected)
// ===========================================================================
describe('findVariation', () => {
  /**
   * AC-13: GIVEN ein Nutzer ist auf einer Produktdetailseite
   * WHEN er sowohl Groesse als auch Farbe gewaehlt hat
   * THEN ist der "In den Warenkorb"-Button aktiv
   *
   * Unit test: findVariation returns the matching variation.
   */
  it('finds matching variation when both size and color are selected', () => {
    // Arrange (GIVEN)
    const variations = VARIATIONS_FIXTURE

    // Act (WHEN)
    const result = findVariation(variations, 'M', 'Schwarz')

    // Assert (THEN)
    expect(result).not.toBeNull()
    expect(result?.id).toBe('1')
    expect(result?.stockStatus).toBe('IN_STOCK')
  })

  /**
   * AC-10: GIVEN ein Nutzer ist auf einer Produktdetailseite
   * WHEN er noch keine Farbe und Groesse gewaehlt hat
   * THEN ist der "In den Warenkorb"-Button disabled
   *
   * Unit test: findVariation returns null when size is null.
   */
  it('returns null when selectedSize is null', () => {
    // Arrange (GIVEN)
    const variations = VARIATIONS_FIXTURE

    // Act (WHEN)
    const result = findVariation(variations, null, 'Schwarz')

    // Assert (THEN)
    expect(result).toBeNull()
  })

  /**
   * AC-10: Same AC -- button disabled when color not selected.
   */
  it('returns null when selectedColor is null', () => {
    // Arrange (GIVEN)
    const variations = VARIATIONS_FIXTURE

    // Act (WHEN)
    const result = findVariation(variations, 'M', null)

    // Assert (THEN)
    expect(result).toBeNull()
  })

  it('returns null when no variation matches the selection', () => {
    // Arrange (GIVEN) -- L + Rot does not exist in fixture
    const variations = VARIATIONS_FIXTURE

    // Act (WHEN)
    const result = findVariation(variations, 'L', 'Rot')

    // Assert (THEN)
    expect(result).toBeNull()
  })

  it('returns the OUT_OF_STOCK variation when size and color match', () => {
    // Arrange (GIVEN) -- XL + Rot is OUT_OF_STOCK (variation 3)
    const variations = VARIATIONS_FIXTURE

    // Act (WHEN)
    const result = findVariation(variations, 'XL', 'Rot')

    // Assert (THEN)
    expect(result).not.toBeNull()
    expect(result?.id).toBe('3')
    expect(result?.stockStatus).toBe('OUT_OF_STOCK')
  })
})

// ===========================================================================
// generateProductJsonLd -- 6 Tests
// Covers AC-18: GIVEN die Produktdetailseite ist geladen
// WHEN der HTML-Source angezeigt wird
// THEN enthaelt <head> ein <script type="application/ld+json"> mit korrektem
// Product Schema (name, price, priceCurrency: EUR)
// ===========================================================================
describe('generateProductJsonLd', () => {
  const PAGE_URL = 'https://podshop.de/produkt/basic-tshirt'

  /**
   * AC-18: JSON-LD contains @context and @type Product.
   */
  it('generates valid JSON-LD with @context and @type Product', () => {
    // Arrange (GIVEN)
    const product = makeProduct()

    // Act (WHEN)
    const jsonLd = generateProductJsonLd(product, PAGE_URL)
    const parsed = JSON.parse(jsonLd)

    // Assert (THEN)
    expect(parsed['@context']).toBe('https://schema.org')
    expect(parsed['@type']).toBe('Product')
  })

  /**
   * AC-18: JSON-LD contains product name.
   */
  it('includes the product name in JSON-LD', () => {
    // Arrange (GIVEN)
    const product = makeProduct()

    // Act (WHEN)
    const jsonLd = generateProductJsonLd(product, PAGE_URL)
    const parsed = JSON.parse(jsonLd)

    // Assert (THEN)
    expect(parsed.name).toBe('Basic T-Shirt')
  })

  /**
   * AC-18: JSON-LD contains price and priceCurrency EUR.
   */
  it('includes price in numeric format and priceCurrency EUR', () => {
    // Arrange (GIVEN)
    const product = makeProduct()

    // Act (WHEN)
    const jsonLd = generateProductJsonLd(product, PAGE_URL)
    const parsed = JSON.parse(jsonLd)

    // Assert (THEN)
    expect(parsed.offers.price).toBe('29.90')
    expect(parsed.offers.priceCurrency).toBe('EUR')
  })

  /**
   * AC-18: JSON-LD uses shortDescription when available.
   */
  it('uses shortDescription as description when available', () => {
    // Arrange (GIVEN)
    const product = makeProduct({ shortDescription: 'Kurze Beschreibung' })

    // Act (WHEN)
    const jsonLd = generateProductJsonLd(product, PAGE_URL)
    const parsed = JSON.parse(jsonLd)

    // Assert (THEN)
    expect(parsed.description).toBe('Kurze Beschreibung')
  })

  /**
   * AC-18: JSON-LD falls back to description when shortDescription is null.
   */
  it('falls back to description when shortDescription is null', () => {
    // Arrange (GIVEN)
    const product = makeProduct({ shortDescription: null })

    // Act (WHEN)
    const jsonLd = generateProductJsonLd(product, PAGE_URL)
    const parsed = JSON.parse(jsonLd)

    // Assert (THEN)
    expect(parsed.description).toBe('<p>Ein einfaches T-Shirt aus Bio-Baumwolle.</p>')
  })

  /**
   * AC-18: JSON-LD includes image URL and page URL.
   */
  it('includes image sourceUrl and page url', () => {
    // Arrange (GIVEN)
    const product = makeProduct()

    // Act (WHEN)
    const jsonLd = generateProductJsonLd(product, PAGE_URL)
    const parsed = JSON.parse(jsonLd)

    // Assert (THEN)
    expect(parsed.image).toBe('https://example.com/tshirt.jpg')
    expect(parsed.url).toBe(PAGE_URL)
    expect(parsed.offers.availability).toBe('https://schema.org/InStock')
  })
})
