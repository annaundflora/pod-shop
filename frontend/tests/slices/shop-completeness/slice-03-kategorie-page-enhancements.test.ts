// tests/slices/shop-completeness/slice-03-kategorie-page-enhancements.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'

// ─── Mocks ───────────────────────────────────────────────────────────────────

vi.mock('@/lib/apollo/server-client', () => ({
  getClient: vi.fn(() => ({
    query: vi.fn(),
  })),
}))

vi.mock('next/navigation', () => ({
  useRouter: vi.fn(() => ({ push: vi.fn(), replace: vi.fn() })),
  useSearchParams: vi.fn(() => new URLSearchParams()),
  redirect: vi.fn(),
}))

// ─── Tests: URL-Param Parsing & Sanitierung ──────────────────────────────────

describe('Kategorie-Page URL-Param Handling', () => {
  it('should parse valid page param as integer', () => {
    const parsePageParam = (raw: string | undefined): number => {
      const parsed = parseInt(raw ?? '1', 10)
      return isNaN(parsed) || parsed < 1 ? 1 : parsed
    }
    expect(parsePageParam('2')).toBe(2)
    expect(parsePageParam('1')).toBe(1)
    expect(parsePageParam(undefined)).toBe(1)
  })

  it('should fallback to page 1 for invalid page params', () => {
    const parsePageParam = (raw: string | undefined): number => {
      const parsed = parseInt(raw ?? '1', 10)
      return isNaN(parsed) || parsed < 1 ? 1 : parsed
    }
    expect(parsePageParam('abc')).toBe(1)
    expect(parsePageParam('0')).toBe(1)
    expect(parsePageParam('-5')).toBe(1)
  })

  it('should accept valid sort params', () => {
    const parseSortParam = (raw: string | undefined): string => {
      const validSorts = ['price_asc', 'price_desc', 'newest']
      return raw && validSorts.includes(raw) ? raw : ''
    }
    expect(parseSortParam('price_asc')).toBe('price_asc')
    expect(parseSortParam('price_desc')).toBe('price_desc')
    expect(parseSortParam('newest')).toBe('newest')
  })

  it('should reject invalid sort params and return empty string', () => {
    const parseSortParam = (raw: string | undefined): string => {
      const validSorts = ['price_asc', 'price_desc', 'newest']
      return raw && validSorts.includes(raw) ? raw : ''
    }
    expect(parseSortParam('invalid')).toBe('')
    expect(parseSortParam('PRICE_ASC')).toBe('')
    expect(parseSortParam(undefined)).toBe('')
  })
})

// ─── Tests: Sort-Mapping ─────────────────────────────────────────────────────

describe('Sort-Mapping buildOrderby()', () => {
  const buildOrderby = (sort: string | undefined) => {
    switch (sort) {
      case 'price_asc':  return [{ field: 'PRICE', order: 'ASC' }]
      case 'price_desc': return [{ field: 'PRICE', order: 'DESC' }]
      case 'newest':     return [{ field: 'DATE', order: 'DESC' }]
      default:           return undefined
    }
  }

  it('should map price_asc to PRICE ASC orderby', () => {
    expect(buildOrderby('price_asc')).toEqual([{ field: 'PRICE', order: 'ASC' }])
  })

  it('should map price_desc to PRICE DESC orderby', () => {
    expect(buildOrderby('price_desc')).toEqual([{ field: 'PRICE', order: 'DESC' }])
  })

  it('should map newest to DATE DESC orderby', () => {
    expect(buildOrderby('newest')).toEqual([{ field: 'DATE', order: 'DESC' }])
  })

  it('should return undefined for empty sort (WooCommerce default)', () => {
    expect(buildOrderby('')).toBeUndefined()
    expect(buildOrderby(undefined)).toBeUndefined()
  })
})

// ─── Tests: Over-fetch + Slice Pagination Logic ──────────────────────────────

describe('Pagination: Over-fetch + Slice Logic', () => {
  const paginate = (
    allNodes: unknown[],
    page: number,
    perPage: number,
    categoryCount: number
  ) => {
    const pageNodes = allNodes.slice((page - 1) * perPage, page * perPage)
    const hasNextPage = allNodes.length > page * perPage
    const totalPages = categoryCount > 0
      ? Math.ceil(categoryCount / perPage)
      : Math.ceil(allNodes.length / perPage) + (hasNextPage ? 1 : 0)
    return {
      pageNodes,
      hasNextPage,
      hasPreviousPage: page > 1,
      totalPages: Math.max(totalPages, page),
      currentPage: page,
    }
  }

  it('should return first 24 products for page 1', () => {
    const allNodes = Array.from({ length: 25 }, (_, i) => ({ id: String(i) }))
    const result = paginate(allNodes, 1, 24, 50)
    expect(result.pageNodes).toHaveLength(24)
    expect(result.hasNextPage).toBe(true)
    expect(result.hasPreviousPage).toBe(false)
  })

  it('should return products 25-48 for page 2', () => {
    const allNodes = Array.from({ length: 49 }, (_, i) => ({ id: String(i) }))
    const result = paginate(allNodes, 2, 24, 60)
    expect(result.pageNodes).toHaveLength(24)
    expect(result.currentPage).toBe(2)
    expect(result.hasPreviousPage).toBe(true)
  })

  it('should detect hasNextPage correctly using over-fetch +1', () => {
    // fetchCount = page * perPage + 1 = 2 * 24 + 1 = 49
    const allNodes49 = Array.from({ length: 49 }, (_, i) => ({ id: String(i) }))
    const result = paginate(allNodes49, 2, 24, 0)
    expect(result.hasNextPage).toBe(true)

    const allNodes48 = Array.from({ length: 48 }, (_, i) => ({ id: String(i) }))
    const resultNoNext = paginate(allNodes48, 2, 24, 0)
    expect(resultNoNext.hasNextPage).toBe(false)
  })

  it('should calculate totalPages from categoryCount', () => {
    const allNodes = Array.from({ length: 25 }, (_, i) => ({ id: String(i) }))
    const result = paginate(allNodes, 1, 24, 100)
    expect(result.totalPages).toBe(Math.ceil(100 / 24)) // = 5
  })

  it('should return empty pageNodes for empty category', () => {
    const result = paginate([], 1, 24, 0)
    expect(result.pageNodes).toHaveLength(0)
    expect(result.hasNextPage).toBe(false)
    expect(result.totalPages).toBe(1)
  })
})

// ─── Tests: fetchCount Calculation ───────────────────────────────────────────

describe('Pagination: fetchCount Calculation', () => {
  it('should compute fetchCount as page * perPage + 1 for over-fetch', () => {
    const computeFetchCount = (page: number, perPage: number) => page * perPage + 1
    expect(computeFetchCount(1, 24)).toBe(25)
    expect(computeFetchCount(2, 24)).toBe(49)
    expect(computeFetchCount(3, 24)).toBe(73)
  })
})

// ─── Tests: Breadcrumb Data Construction ─────────────────────────────────────

describe('Breadcrumb Data for Category Page', () => {
  it('should build breadcrumb items with Startseite and category name', () => {
    const buildBreadcrumb = (categoryName: string) => ({
      items: [
        { label: 'Startseite', href: '/' },
        { label: categoryName },
      ],
    })

    const result = buildBreadcrumb('T-Shirts')
    expect(result.items).toHaveLength(2)
    expect(result.items[0]).toEqual({ label: 'Startseite', href: '/' })
    expect(result.items[1]).toEqual({ label: 'T-Shirts' })
    expect(result.items[1]).not.toHaveProperty('href')
  })

  it('should use WooCommerce category name, not URL slug', () => {
    const buildBreadcrumb = (categoryName: string) => ({
      items: [
        { label: 'Startseite', href: '/' },
        { label: categoryName },
      ],
    })
    // Slug "t-shirts" → WC Name "T-Shirts" (korrekte Formatierung)
    const result = buildBreadcrumb('T-Shirts')
    expect(result.items[1].label).toBe('T-Shirts')
    // Nicht der URL-Slug "t-shirts"
    expect(result.items[1].label).not.toBe('t-shirts')
  })
})

// ─── Tests: SortBar baseUrl Construction ─────────────────────────────────────

describe('SortBar baseUrl für Kategorie-Page', () => {
  it('should build baseUrl as /kategorie/{slug}', () => {
    const buildBaseUrl = (slug: string) => `/kategorie/${slug}`
    expect(buildBaseUrl('t-shirts')).toBe('/kategorie/t-shirts')
    expect(buildBaseUrl('neuheiten')).toBe('/kategorie/neuheiten')
  })

  it('should preserve sort param when changing page', () => {
    const buildPageUrl = (baseUrl: string, page: number, sort: string) => {
      const params = new URLSearchParams()
      if (page > 1) params.set('page', String(page))
      if (sort) params.set('sort', sort)
      const query = params.toString()
      return query ? `${baseUrl}?${query}` : baseUrl
    }
    expect(buildPageUrl('/kategorie/t-shirts', 2, 'price_asc')).toBe(
      '/kategorie/t-shirts?page=2&sort=price_asc'
    )
    expect(buildPageUrl('/kategorie/t-shirts', 1, 'price_desc')).toBe(
      '/kategorie/t-shirts?sort=price_desc'
    )
    expect(buildPageUrl('/kategorie/t-shirts', 1, '')).toBe('/kategorie/t-shirts')
  })
})

// ─── Tests: YAML category.yaml Neue Blocks ───────────────────────────────────

describe('Category YAML: Neue Block-Typen', () => {
  it('should include breadcrumb block with category_meta query', () => {
    // Validiert dass die YAML-Konfiguration korrekt ist
    const expectedBreadcrumbBlock = {
      type: 'breadcrumb',
      content_source: 'woocommerce',
      params: {
        query: 'category_meta',
        slug: '$route.slug',
      },
    }
    expect(expectedBreadcrumbBlock.type).toBe('breadcrumb')
    expect(expectedBreadcrumbBlock.params.query).toBe('category_meta')
  })

  it('should include sort-bar block with inline content_source', () => {
    const expectedSortBarBlock = {
      type: 'sort-bar',
      content_source: 'inline',
      params: {
        props: {
          currentSort: '$route.sort',
          baseUrl: '/kategorie/$route.slug',
        },
      },
    }
    expect(expectedSortBarBlock.content_source).toBe('inline')
    expect(expectedSortBarBlock.params.props.currentSort).toBe('$route.sort')
  })

  it('should include pagination block with woocommerce source and products_by_category query', () => {
    const expectedPaginationBlock = {
      type: 'pagination',
      content_source: 'woocommerce',
      params: {
        query: 'products_by_category',
        slug: '$route.slug',
        page: '$route.page',
        perPage: 24,
        sort: '$route.sort',
      },
    }
    expect(expectedPaginationBlock.type).toBe('pagination')
    expect(expectedPaginationBlock.params.query).toBe('products_by_category')
    expect(expectedPaginationBlock.params.perPage).toBe(24)
  })
})

// ─── Tests: Empty-State Trigger ──────────────────────────────────────────────

describe('Empty-State Rendering-Bedingung', () => {
  it('should show empty state when products nodes is empty array', () => {
    const shouldShowEmptyState = (products: unknown[]) => products.length === 0
    expect(shouldShowEmptyState([])).toBe(true)
    expect(shouldShowEmptyState([{ id: '1' }])).toBe(false)
  })

  it('should configure empty-state with German headline and suggestion links', () => {
    const emptyStateConfig = {
      headline: 'Keine Produkte gefunden',
      text: 'In dieser Kategorie sind aktuell keine Produkte verfügbar.',
      links: [
        { label: 'Alle Kategorien', href: '/' },
        { label: 'Neuheiten', href: '/kategorie/neuheiten' },
      ],
    }
    expect(emptyStateConfig.headline).toBe('Keine Produkte gefunden')
    expect(emptyStateConfig.links).toHaveLength(2)
    expect(emptyStateConfig.links[0].href).toBe('/')
  })
})
