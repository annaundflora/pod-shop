// tests/slices/shop-completeness/slice-06-neue-pages.test.tsx
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, act } from '@testing-library/react'
import React from 'react'

// ---------------------------------------------------------------------------
// Globale Mocks
// ---------------------------------------------------------------------------
vi.mock('next/navigation', () => ({
  notFound: vi.fn(),
  redirect: vi.fn(),
  useRouter: vi.fn(() => ({ push: vi.fn() })),
  useSearchParams: vi.fn(() => new URLSearchParams()),
}))

const mockGetClientQuery = vi.fn().mockResolvedValue({ data: { productCategory: null } })

vi.mock('@/lib/apollo/server-client', () => ({
  getClient: vi.fn(() => ({ query: mockGetClientQuery })),
}))

vi.mock('@/lib/blocks/page-config', () => ({
  loadPageConfig: vi.fn(() => ({ sections: [] })),
  loadGlobalConfig: vi.fn(() => ({ sections: [] })),
}))

beforeEach(() => {
  mockGetClientQuery.mockClear()
  mockGetClientQuery.mockResolvedValue({ data: { productCategory: null } })
})

// ---------------------------------------------------------------------------
// AC1: CollectionHeaderBlock rendert h1 mit Kategorie-Name + Bild
// ---------------------------------------------------------------------------
describe('CollectionHeaderBlock', () => {
  it('rendert <h1> mit Kategorie-Name wenn Daten vorhanden (AC1)', async () => {
    const { CollectionHeaderBlock } = await import(
      '@/components/blocks/collection-header-block'
    )
    render(
      <CollectionHeaderBlock
        data={{
          name: 'Sale',
          description: '<p>Unsere aktuellen Angebote</p>',
          image: {
            sourceUrl: 'https://example.com/sale-banner.jpg',
            altText: 'Sale Banner',
          },
        }}
      />
    )
    expect(screen.getByRole('heading', { level: 1 })).toBeDefined()
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Sale')
  })

  it('rendert ein img-Element wenn image vorhanden (AC1)', async () => {
    const { CollectionHeaderBlock } = await import(
      '@/components/blocks/collection-header-block'
    )
    render(
      <CollectionHeaderBlock
        data={{
          name: 'Sale',
          description: '',
          image: {
            sourceUrl: 'https://example.com/sale-banner.jpg',
            altText: 'Sale Banner',
          },
        }}
      />
    )
    // next/image rendert als <img> mit alt
    const img = screen.getByAltText('Sale Banner')
    expect(img).toBeDefined()
  })

  // AC2: CollectionHeaderBlock ohne Bild — kein img
  it('rendert kein img wenn image nicht vorhanden (AC2)', async () => {
    const { CollectionHeaderBlock } = await import(
      '@/components/blocks/collection-header-block'
    )
    render(
      <CollectionHeaderBlock
        data={{
          name: 'Neuheiten',
          description: '',
        }}
      />
    )
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Neuheiten')
    expect(screen.queryByRole('img')).toBeNull()
  })
})

// ---------------------------------------------------------------------------
// AC3: CollectionPage ruft notFound() auf wenn Kategorie nicht existiert
// ---------------------------------------------------------------------------
describe('CollectionPage (page.tsx)', () => {
  it('ruft notFound() auf wenn Kategorie nicht gefunden (AC3)', async () => {
    mockGetClientQuery.mockResolvedValue({ data: { productCategory: null } })

    const { notFound } = await import('next/navigation')
    vi.mocked(notFound).mockClear()

    const { default: CollectionPage } = await import(
      '@/app/kollektion/[slug]/page'
    )
    await CollectionPage({
      params: Promise.resolve({ slug: 'nicht-existent' }),
      searchParams: Promise.resolve({}),
    })

    expect(notFound).toHaveBeenCalled()
  })

  // AC4: Ungültiger page-Param → redirect
  it('ruft redirect() auf wenn page-Param kein valider Integer ist (AC4)', async () => {
    mockGetClientQuery.mockResolvedValue({
      data: {
        productCategory: {
          name: 'Sale',
          description: '',
          slug: 'sale',
          count: 12,
          image: null,
        },
      },
    })

    const { redirect } = await import('next/navigation')
    vi.mocked(redirect).mockClear()

    const { default: CollectionPage } = await import(
      '@/app/kollektion/[slug]/page'
    )
    await CollectionPage({
      params: Promise.resolve({ slug: 'sale' }),
      searchParams: Promise.resolve({ page: 'abc' }),
    })

    expect(redirect).toHaveBeenCalledWith('/kollektion/sale')
  })

  // AC5: generateMetadata nutzt Kategorie-Name und Canonical
  it('generateMetadata enthält Kategorie-Name im title und Canonical (AC5)', async () => {
    mockGetClientQuery.mockResolvedValue({
      data: {
        productCategory: {
          name: 'Sale',
          description: '<p>Angebote</p>',
          slug: 'sale',
          image: null,
        },
      },
    })

    const { generateMetadata } = await import('@/app/kollektion/[slug]/page')
    const metadata = await generateMetadata({
      params: Promise.resolve({ slug: 'sale' }),
    })

    expect(String(metadata.title)).toContain('Sale')
    expect(metadata.alternates?.canonical).toBe('/kollektion/sale')
  })
})

// ---------------------------------------------------------------------------
// AC6 & AC7: OrderConfirmationBlock — Bestellnummer aus window.location
// ---------------------------------------------------------------------------
describe('OrderConfirmationBlock', () => {
  const originalSearch = window.location.search

  afterEach(() => {
    vi.unstubAllGlobals()
    // Reset window.location.search
    Object.defineProperty(window, 'location', {
      value: { ...window.location, search: originalSearch },
      writable: true,
    })
  })

  it('zeigt Bestellnummer nach Hydration wenn order_id in URL vorhanden (AC6)', async () => {
    Object.defineProperty(window, 'location', {
      value: { ...window.location, search: '?order_id=12345' },
      writable: true,
    })

    const { OrderConfirmationBlock } = await import(
      '@/components/blocks/order-confirmation-block'
    )
    render(
      <OrderConfirmationBlock
        data={{
          headline: 'Vielen Dank!',
          text: 'Deine Bestellung ist eingegangen.',
          emailText: 'Du erhältst eine E-Mail.',
          ctaText: 'Weiter einkaufen',
          ctaLink: '/',
        }}
      />
    )

    // Nach act() ist useEffect ausgeführt
    await act(async () => {})
    expect(screen.getByText(/#12345/)).toBeDefined()
  })

  it('zeigt keine Bestellnummer wenn order_id fehlt (AC7)', async () => {
    Object.defineProperty(window, 'location', {
      value: { ...window.location, search: '' },
      writable: true,
    })

    const { OrderConfirmationBlock } = await import(
      '@/components/blocks/order-confirmation-block'
    )
    render(
      <OrderConfirmationBlock
        data={{
          headline: 'Vielen Dank!',
          text: 'Deine Bestellung ist eingegangen.',
          emailText: 'Du erhältst eine E-Mail.',
          ctaText: 'Weiter einkaufen',
          ctaLink: '/',
        }}
      />
    )

    await act(async () => {})
    expect(screen.queryByText(/\#\d+/)).toBeNull()
    expect(screen.getByText('Vielen Dank!')).toBeDefined()
  })

  // AC8: Danke-Page ist noindex
  it('Danke-Page metadata enthält robots noindex (AC8)', async () => {
    const { metadata } = await import('@/app/danke/page')
    expect(metadata).toBeDefined()
    const robots = (metadata as { robots?: { index?: boolean } }).robots
    expect(robots?.index).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// AC9: not-found.tsx rendert h1 und CTA-Links
// ---------------------------------------------------------------------------
describe('NotFound (not-found.tsx)', () => {
  it('rendert h1 "Diese Seite wurde nicht gefunden" und zwei CTA-Links (AC9)', async () => {
    const { default: NotFound } = await import('@/app/not-found')
    render(<NotFound />)

    expect(
      screen.getByRole('heading', { level: 1 })
    ).toBeDefined()
    expect(
      screen.getByRole('heading', { level: 1 }).textContent
    ).toContain('Diese Seite wurde nicht gefunden')

    const links = screen.getAllByRole('link')
    const hrefs = links.map((l) => l.getAttribute('href'))
    expect(hrefs).toContain('/')
    expect(hrefs).toContain('/kategorie/alle')
  })

  // AC10: 404-Page ist noindex
  it('not-found.tsx metadata enthält robots noindex (AC10)', async () => {
    const notFoundModule = await import('@/app/not-found')
    const meta = notFoundModule.metadata as { robots?: { index?: boolean } }
    expect(meta.robots?.index).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// AC11: registry.ts enthält collection-header und order-confirmation
// ---------------------------------------------------------------------------
describe('Block Registry', () => {
  it('resolveBlock gibt Komponente für collection-header zurück (AC11)', async () => {
    vi.resetModules()
    const { resolveBlock } = await import('@/lib/blocks/registry')
    const component = resolveBlock('collection-header')
    expect(component).toBeDefined()
    expect(typeof component).toBe('function')
  })

  it('resolveBlock gibt Komponente für order-confirmation zurück (AC11)', async () => {
    vi.resetModules()
    const { resolveBlock } = await import('@/lib/blocks/registry')
    const component = resolveBlock('order-confirmation')
    expect(component).toBeDefined()
    expect(typeof component).toBe('function')
  })
})
