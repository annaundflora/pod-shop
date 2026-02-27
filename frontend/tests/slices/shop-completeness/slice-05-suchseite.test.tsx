// tests/slices/shop-completeness/slice-05-suchseite.test.tsx
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, act, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import React from 'react'

// Mocks — top-level mocks are hoisted by vitest
const mockPush = vi.fn()

vi.mock('next/navigation', () => ({
  useRouter: vi.fn(() => ({ push: mockPush })),
  useSearchParams: vi.fn(() => new URLSearchParams()),
  redirect: vi.fn(),
}))

const mockGetClientQuery = vi.fn().mockResolvedValue({ data: { products: { nodes: [] }, productCategories: { nodes: [] } } })

vi.mock('@/lib/apollo/server-client', () => ({
  getClient: vi.fn(() => ({ query: mockGetClientQuery })),
}))

vi.mock('@/lib/blocks/page-config', () => ({
  loadPageConfig: vi.fn(() => ({ sections: [] })),
}))

// Mock ProductCard to avoid complex deps
vi.mock('@/components/product/product-card', () => ({
  ProductCard: ({ product }: { product: { name: string } }) =>
    React.createElement('div', { 'data-testid': 'product-card' }, product.name),
}))

// Mock CartIcon to avoid CartProvider dependency in Header tests
vi.mock('@/components/layout/cart-icon', () => ({
  CartIcon: () => React.createElement('div', { 'data-testid': 'cart-icon' }),
}))

// Mock MobileMenu to avoid complex deps
vi.mock('@/components/layout/mobile-menu', () => ({
  MobileMenu: () => React.createElement('div', { 'data-testid': 'mobile-menu' }),
}))

beforeEach(() => {
  mockPush.mockClear()
  mockGetClientQuery.mockClear()
  mockGetClientQuery.mockResolvedValue({ data: { products: { nodes: [] }, productCategories: { nodes: [] } } })
})

// ---------------------------------------------------------------------------
// AC1: Suchseite erreichbar — SearchBar rendert role="search"
// ---------------------------------------------------------------------------
describe('SearchBarBlock', () => {
  it('rendert ein Element mit role="search" (AC1)', async () => {
    const { SearchBarBlock } = await import(
      '@/components/blocks/search-bar-block'
    )
    render(
      <SearchBarBlock
        data={{ placeholder: 'Was suchst du?', currentQuery: '' }}
      />
    )
    expect(screen.getByRole('search')).toBeDefined()
  })

  it('zeigt den currentQuery als Input-Wert (AC1 + AC8)', async () => {
    const { SearchBarBlock } = await import(
      '@/components/blocks/search-bar-block'
    )
    render(
      <SearchBarBlock
        data={{ placeholder: 'Was suchst du?', currentQuery: 'shirt' }}
      />
    )
    const input = screen.getByRole('searchbox')
    expect((input as HTMLInputElement).defaultValue).toBe('shirt')
  })

  it('Clear-Button ruft router.push(/suche) auf (AC8)', async () => {
    const { SearchBarBlock } = await import(
      '@/components/blocks/search-bar-block'
    )
    render(
      <SearchBarBlock
        data={{ placeholder: 'Was suchst du?', currentQuery: 'shirt' }}
      />
    )
    const clearButton = screen.getByRole('button', { name: /suche löschen/i })
    await userEvent.click(clearButton)
    expect(mockPush).toHaveBeenCalledWith('/suche')
  })

  it('Submit mit weniger als 2 Zeichen löst keinen router.push aus', async () => {
    const { SearchBarBlock } = await import(
      '@/components/blocks/search-bar-block'
    )
    render(
      <SearchBarBlock
        data={{ placeholder: 'Was suchst du?', currentQuery: '' }}
      />
    )
    const input = screen.getByRole('searchbox')
    await userEvent.type(input, 'a')
    await userEvent.keyboard('{Enter}')
    expect(mockPush).not.toHaveBeenCalled()
  })
})

// ---------------------------------------------------------------------------
// AC3: Mindestzeichenregel — data-loader
// ---------------------------------------------------------------------------
describe('woocommerceLoader search_products', () => {
  it('gibt leeres PaginatedProductsResult zurück wenn search < 2 Zeichen (AC3)', async () => {
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({
      query: 'search_products',
      search: '',
      page: '1',
      perPage: 24,
      sort: '',
    } as any)
    expect((result.data as any).products.nodes).toHaveLength(0)
    expect((result.data as any).pagination.totalCount).toBe(0)
  })

  it('gibt leeres Ergebnis bei 1-Zeichen-Query zurück (AC3)', async () => {
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({
      query: 'search_products',
      search: 'a',
      page: '1',
      perPage: 24,
      sort: '',
    } as any)
    expect((result.data as any).products.nodes).toHaveLength(0)
    expect((result.data as any).pagination.totalCount).toBe(0)
  })

  it('führt GET_PRODUCTS_PAGINATED aus wenn search >= 2 Zeichen (AC2)', async () => {
    mockGetClientQuery.mockResolvedValueOnce({
      data: {
        products: {
          nodes: [
            { id: '1', name: 'Shirt 1', slug: 'shirt-1', price: '24.99', image: null },
            { id: '2', name: 'Shirt 2', slug: 'shirt-2', price: '19.99', image: null },
          ],
        },
      },
    })

    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({
      query: 'search_products',
      search: 'shirt',
      page: '1',
      perPage: 24,
      sort: '',
    } as any)

    expect(mockGetClientQuery).toHaveBeenCalledWith(
      expect.objectContaining({
        variables: expect.objectContaining({ search: 'shirt' }),
      })
    )
    expect((result.data as any).products.nodes).toHaveLength(2)
  })

  it('paginiert korrekt: Seite 2 mit perPage=1 aus 3 Produkten (AC4)', async () => {
    const allNodes = [
      { id: '1', slug: 'p1' },
      { id: '2', slug: 'p2' },
      { id: '3', slug: 'p3' },
    ]
    mockGetClientQuery.mockResolvedValueOnce({
      data: { products: { nodes: allNodes } },
    })

    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({
      query: 'search_products',
      search: 'shirt',
      page: '2',
      perPage: 1,
      sort: '',
    } as any)

    // Seite 2 = slice([1, 2]) = nur Produkt id='2'
    expect((result.data as any).products.nodes).toHaveLength(1)
    expect((result.data as any).products.nodes[0].id).toBe('2')
    expect((result.data as any).pagination.currentPage).toBe(2)
    expect((result.data as any).pagination.hasPreviousPage).toBe(true)
  })
})

// ---------------------------------------------------------------------------
// AC4: PaginationBlock URL-Komposition — q und sort werden beibehalten
// ---------------------------------------------------------------------------
describe('PaginationBlock URL-Komposition (AC4)', () => {
  it('erzeugt korrekte URL für Seite 2 mit ?q und ?sort Params', async () => {
    const { PaginationBlock } = await import('@/components/blocks/pagination-block')
    render(
      <PaginationBlock
        data={{
          currentPage: 1,
          totalPages: 3,
          baseUrl: '/suche?q=shirt&sort=price_asc',
        }}
      />
    )
    // Seite-2-Link muss q und sort beibehalten
    const page2Link = screen.getByRole('link', { name: /2/ })
    expect(page2Link.getAttribute('href')).toBe('/suche?q=shirt&sort=price_asc&page=2')
  })
})

describe('woocommerceLoader search_products sort', () => {
  it('wendet Sort-Mapping an: price_asc → PRICE ASC orderby (AC5)', async () => {
    mockGetClientQuery.mockResolvedValueOnce({
      data: { products: { nodes: [] } },
    })

    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    await woocommerceLoader({
      query: 'search_products',
      search: 'shirt',
      page: '1',
      perPage: 24,
      sort: 'price_asc',
    } as any)

    expect(mockGetClientQuery).toHaveBeenCalledWith(
      expect.objectContaining({
        variables: expect.objectContaining({
          orderby: [{ field: 'PRICE', order: 'ASC' }],
        }),
      })
    )
  })
})

// ---------------------------------------------------------------------------
// AC6: SearchResultsBlock rendert null bei 0 Ergebnissen
// ---------------------------------------------------------------------------
describe('SearchResultsBlock', () => {
  it('rendert null wenn products.nodes leer ist (AC6)', async () => {
    const { SearchResultsBlock } = await import(
      '@/components/blocks/search-results-block'
    )
    const { container } = render(
      <SearchResultsBlock
        data={{
          products: { nodes: [] },
          pagination: {
            currentPage: 1,
            totalPages: 0,
            hasNextPage: false,
            hasPreviousPage: false,
            totalCount: 0,
          },
        }}
      />
    )
    expect(container.firstChild).toBeNull()
  })

  it('EmptyStateBlock zeigt "Keine Ergebnisse gefunden" wenn SearchResultsBlock null rendert (AC6-Teil2)', async () => {
    const { EmptyStateBlock } = await import('@/components/blocks/empty-state-block')
    render(
      <EmptyStateBlock
        data={{
          headline: 'Keine Ergebnisse gefunden',
          text: 'Versuche es mit einem anderen Suchbegriff oder stöbere in unseren Kategorien.',
          links: [{ label: 'Alle Produkte', href: '/kategorie/alle' }],
        }}
      />
    )
    expect(screen.getByRole('heading', { name: 'Keine Ergebnisse gefunden' })).toBeTruthy()
  })

  it('rendert Produkt-Grid wenn nodes vorhanden (AC2)', async () => {
    const { SearchResultsBlock } = await import(
      '@/components/blocks/search-results-block'
    )
    const mockNodes = [
      {
        id: '1',
        name: 'Shirt Blau',
        slug: 'shirt-blau',
        price: '24.99',
        regularPrice: '24.99',
        onSale: false,
        stockStatus: 'IN_STOCK',
        image: { sourceUrl: '/img.jpg', altText: 'Shirt' },
        productCategories: { nodes: [] },
      },
    ]
    render(
      <SearchResultsBlock
        data={{
          products: { nodes: mockNodes },
          pagination: {
            currentPage: 1,
            totalPages: 1,
            hasNextPage: false,
            hasPreviousPage: false,
            totalCount: 1,
          },
        }}
      />
    )
    expect(screen.getByText('Shirt Blau')).toBeDefined()
  })
})

// ---------------------------------------------------------------------------
// AC7: Redirect bei ungültigem page-Param
// ---------------------------------------------------------------------------
describe('SearchPage (page.tsx)', () => {
  it('ruft redirect() auf wenn page-Param kein valider Integer ist (AC7)', async () => {
    const { redirect } = await import('next/navigation')
    vi.mocked(redirect).mockClear()

    const { default: SearchPage } = await import('@/app/suche/page')
    await SearchPage({
      searchParams: Promise.resolve({ q: 'shirt', page: 'abc', sort: '' }),
    })

    expect(vi.mocked(redirect)).toHaveBeenCalledWith('/suche?q=shirt')
  })
})

// ---------------------------------------------------------------------------
// AC9: Header enthält Suchlink
// ---------------------------------------------------------------------------
describe('Header', () => {
  it('rendert einen Link zu /suche mit aria-label (AC9)', async () => {
    const { Header } = await import('@/components/layout/header')
    // Header is async — render the resolved value
    const element = await Header()
    render(element as React.ReactElement)
    const searchLink = screen.getByRole('link', { name: /suche öffnen/i })
    expect(searchLink).toBeDefined()
    expect(searchLink.getAttribute('href')).toBe('/suche')
  })
})

// ---------------------------------------------------------------------------
// AC10: generateMetadata setzt noindex und Suchbegriff in title
// ---------------------------------------------------------------------------
describe('SearchPage generateMetadata', () => {
  it('setzt robots noindex und enthält Suchbegriff im title (AC10)', async () => {
    const { generateMetadata } = await import('@/app/suche/page')
    const metadata = await generateMetadata({
      searchParams: Promise.resolve({ q: 'shirt' }),
    })
    expect(metadata.robots).toEqual({ index: false, follow: true })
    expect(String(metadata.title)).toContain('shirt')
  })

  it('setzt generischen title ohne Suchbegriff (AC10)', async () => {
    const { generateMetadata } = await import('@/app/suche/page')
    const metadata = await generateMetadata({
      searchParams: Promise.resolve({ q: '' }),
    })
    expect(String(metadata.title)).toContain('Suche')
    expect(String(metadata.title)).not.toContain('""')
  })
})
