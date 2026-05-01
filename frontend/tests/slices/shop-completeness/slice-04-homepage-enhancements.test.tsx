// tests/slices/shop-completeness/slice-04-homepage-enhancements.test.tsx
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react'
import React from 'react'

// --- TestimonialsBlock ---

describe('TestimonialsBlock', () => {
  it('should render the correct number of testimonial cards', async () => {
    const { TestimonialsBlock } = await import('@/components/blocks/testimonials-block')
    const data = {
      items: [
        { name: 'Maria K.', text: 'Super Qualität!', rating: 5, location: 'München' },
        { name: 'Thomas B.', text: 'Schnelle Lieferung.', rating: 4 },
        { name: 'Sandra M.', text: 'Tolles Design.', rating: 5 },
      ],
    }
    render(React.createElement(TestimonialsBlock, { data }))
    expect(screen.getAllByRole('figure').length).toBe(3)
  })

  it('should render the correct number of filled stars for rating 4', async () => {
    const { TestimonialsBlock } = await import('@/components/blocks/testimonials-block')
    const data = {
      items: [{ name: 'Test User', text: 'Gutes Produkt.', rating: 4 }],
    }
    render(React.createElement(TestimonialsBlock, { data }))
    expect(screen.getByLabelText('4 von 5 Sternen')).toBeTruthy()
  })

  it('should render testimonial text and author name', async () => {
    const { TestimonialsBlock } = await import('@/components/blocks/testimonials-block')
    const data = {
      items: [{ name: 'Anna L.', text: 'Wirklich beeindruckend!', rating: 5 }],
    }
    render(React.createElement(TestimonialsBlock, { data }))
    expect(screen.getByText(/Wirklich beeindruckend!/)).toBeTruthy()
    expect(screen.getByText(/Anna L\./)).toBeTruthy()
  })

  it('should render null when items array is empty', async () => {
    const { TestimonialsBlock } = await import('@/components/blocks/testimonials-block')
    const data = { items: [] }
    const { container } = render(React.createElement(TestimonialsBlock, { data }))
    expect(container.firstChild).toBeNull()
  })

  it('should render location when provided', async () => {
    const { TestimonialsBlock } = await import('@/components/blocks/testimonials-block')
    const data = {
      items: [{ name: 'Maria K.', text: 'Super!', rating: 5, location: 'München' }],
    }
    render(React.createElement(TestimonialsBlock, { data }))
    expect(screen.getByText(/München/)).toBeTruthy()
  })
})

// --- NewsletterSignupBlock ---

describe('NewsletterSignupBlock', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(console, 'log').mockImplementation(() => {})
  })

  it('should render headline, input and submit button in idle state', async () => {
    const { NewsletterSignupBlock } = await import('@/components/blocks/newsletter-signup-block')
    const data = {
      headline: 'Bleib auf dem Laufenden',
      text: 'Exklusive Angebote.',
      buttonText: 'Anmelden',
      placeholder: 'deine@email.de…',
    }
    render(React.createElement(NewsletterSignupBlock, { data }))
    expect(screen.getByRole('heading', { name: 'Bleib auf dem Laufenden' })).toBeTruthy()
    expect(screen.getByRole('textbox')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Anmelden' })).toBeTruthy()
  })

  it('should show inline error for empty email on submit', async () => {
    const { NewsletterSignupBlock } = await import('@/components/blocks/newsletter-signup-block')
    const data = {
      headline: 'Newsletter',
      text: 'Anmelden.',
      buttonText: 'Anmelden',
      placeholder: 'email…',
    }
    render(React.createElement(NewsletterSignupBlock, { data }))
    fireEvent.click(screen.getByRole('button', { name: 'Anmelden' }))
    expect(screen.getByRole('alert')).toBeTruthy()
    expect(screen.getByText(/E-Mail-Adresse ein/)).toBeTruthy()
  })

  it('should show inline error for invalid email format on submit', async () => {
    const { NewsletterSignupBlock } = await import('@/components/blocks/newsletter-signup-block')
    const data = {
      headline: 'Newsletter',
      text: 'Anmelden.',
      buttonText: 'Anmelden',
      placeholder: 'email…',
    }
    render(React.createElement(NewsletterSignupBlock, { data }))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'kein-at-zeichen' } })
    fireEvent.click(screen.getByRole('button', { name: 'Anmelden' }))
    expect(screen.getByText(/gültige E-Mail-Adresse/)).toBeTruthy()
  })

  it('should call console.log with email on valid submit', async () => {
    vi.useFakeTimers()
    const { NewsletterSignupBlock } = await import('@/components/blocks/newsletter-signup-block')
    const data = {
      headline: 'Newsletter',
      text: 'Anmelden.',
      buttonText: 'Anmelden',
      placeholder: 'email…',
    }
    render(React.createElement(NewsletterSignupBlock, { data }))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'test@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Anmelden' }))
    expect(console.log).toHaveBeenCalledWith('[Newsletter] E-Mail eingereicht:', 'test@example.com')
    await act(async () => { vi.runAllTimers() })
    vi.useRealTimers()
  })

  it('should show success message after valid submit', async () => {
    vi.useFakeTimers()
    const { NewsletterSignupBlock } = await import('@/components/blocks/newsletter-signup-block')
    const data = {
      headline: 'Newsletter',
      text: 'Anmelden.',
      buttonText: 'Anmelden',
      placeholder: 'email…',
    }
    render(React.createElement(NewsletterSignupBlock, { data }))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'test@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Anmelden' }))
    await act(async () => { vi.runAllTimers() })
    expect(screen.getByRole('status')).toBeTruthy()
    vi.useRealTimers()
  })

  it('should disable submit button in loading state', async () => {
    vi.useFakeTimers()
    const { NewsletterSignupBlock } = await import('@/components/blocks/newsletter-signup-block')
    const data = {
      headline: 'Newsletter',
      text: 'Anmelden.',
      buttonText: 'Anmelden',
      placeholder: 'email…',
    }
    render(React.createElement(NewsletterSignupBlock, { data }))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'test@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Anmelden' }))
    expect(screen.getByRole('button').hasAttribute('disabled')).toBe(true)
    await act(async () => { vi.runAllTimers() })
    vi.useRealTimers()
  })
})

// --- FeaturedCollectionBlock ---

describe('FeaturedCollectionBlock', () => {
  it('should render category name and products', async () => {
    const { FeaturedCollectionBlock } = await import('@/components/blocks/featured-collection-block')
    const data = {
      category: {
        name: 'T-Shirts',
        description: 'Premium T-Shirts',
        slug: 't-shirts',
        image: null,
      },
      products: {
        nodes: [
          { id: '1', slug: 'shirt-1', name: 'Shirt 1', price: '19.99', regularPrice: '19.99', onSale: false, stockStatus: 'IN_STOCK' as const, image: { sourceUrl: '/1.jpg', altText: 'Shirt 1', mediaDetails: { width: 800, height: 800 } }, productCategories: { nodes: [] } },
          { id: '2', slug: 'shirt-2', name: 'Shirt 2', price: '24.99', regularPrice: '24.99', onSale: false, stockStatus: 'IN_STOCK' as const, image: { sourceUrl: '/2.jpg', altText: 'Shirt 2', mediaDetails: { width: 800, height: 800 } }, productCategories: { nodes: [] } },
          { id: '3', slug: 'shirt-3', name: 'Shirt 3', price: '19.99', regularPrice: '19.99', onSale: false, stockStatus: 'IN_STOCK' as const, image: { sourceUrl: '/3.jpg', altText: 'Shirt 3', mediaDetails: { width: 800, height: 800 } }, productCategories: { nodes: [] } },
          { id: '4', slug: 'shirt-4', name: 'Shirt 4', price: '29.99', regularPrice: '29.99', onSale: false, stockStatus: 'IN_STOCK' as const, image: { sourceUrl: '/4.jpg', altText: 'Shirt 4', mediaDetails: { width: 800, height: 800 } }, productCategories: { nodes: [] } },
        ],
      },
    }
    render(React.createElement(FeaturedCollectionBlock, { data }))
    expect(screen.getByRole('heading', { name: 'T-Shirts' })).toBeTruthy()
    expect(screen.getAllByRole('link').length).toBeGreaterThanOrEqual(4)
  })

  it('should render "Alle ansehen" link pointing to /kategorie/t-shirts', async () => {
    const { FeaturedCollectionBlock } = await import('@/components/blocks/featured-collection-block')
    const data = {
      category: { name: 'T-Shirts', description: '', slug: 't-shirts', image: null },
      products: {
        nodes: [{ id: '1', slug: 'shirt-1', name: 'Shirt 1', price: '19.99', regularPrice: '19.99', onSale: false, stockStatus: 'IN_STOCK' as const, image: null, productCategories: { nodes: [] } }],
      },
    }
    render(React.createElement(FeaturedCollectionBlock, { data }))
    const alleAnsehenLink = screen.getByRole('link', { name: /Alle.*T-Shirts.*ansehen/i })
    expect(alleAnsehenLink.getAttribute('href')).toBe('/kategorie/t-shirts')
  })

  it('should render null when data is null', async () => {
    const { FeaturedCollectionBlock } = await import('@/components/blocks/featured-collection-block')
    const { container } = render(React.createElement(FeaturedCollectionBlock, { data: null }))
    expect(container.firstChild).toBeNull()
  })

  it('should render FeaturedCollectionBlockSkeleton as animate-pulse placeholder', async () => {
    const { FeaturedCollectionBlockSkeleton } = await import('@/components/blocks/featured-collection-block')
    const { container } = render(React.createElement(FeaturedCollectionBlockSkeleton))
    expect(container.querySelector('.animate-pulse')).toBeTruthy()
  })
})

// --- featured_collection data-loader ---

describe('featured_collection data-loader', () => {
  it('should return FeaturedCollectionData combining category and products', async () => {
    vi.resetModules()
    vi.doMock('@/lib/apollo/server-client', () => ({
      getClient: () => ({
        query: vi.fn().mockImplementation(({ variables }: { variables?: { slug?: string; categorySlug?: string } }) => {
          // GET_CATEGORY_META has slug variable
          if (variables && 'slug' in variables && !('categorySlug' in variables)) {
            return Promise.resolve({
              data: {
                productCategory: {
                  name: 'T-Shirts',
                  description: 'Premium Shirts',
                  slug: 't-shirts',
                  count: 12,
                  image: { sourceUrl: '/cat.jpg', altText: 'T-Shirts' },
                },
              },
            })
          }
          // GET_PRODUCTS_PAGINATED has categorySlug variable
          return Promise.resolve({
            data: {
              products: {
                nodes: [
                  { id: '1', slug: 'shirt-1', name: 'Shirt 1', price: '19.99' },
                  { id: '2', slug: 'shirt-2', name: 'Shirt 2', price: '24.99' },
                  { id: '3', slug: 'shirt-3', name: 'Shirt 3', price: '19.99' },
                  { id: '4', slug: 'shirt-4', name: 'Shirt 4', price: '29.99' },
                ],
              },
              productCategory: { name: 'T-Shirts', count: 12 },
            },
          })
        }),
      }),
    }))
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({ query: 'featured_collection', slug: 't-shirts', first: 4 })
    expect(result.data).not.toBeNull()
    expect((result.data as any)?.category?.name).toBe('T-Shirts')
    expect((result.data as any)?.products?.nodes?.length).toBe(4)
    vi.doUnmock('@/lib/apollo/server-client')
  })

  it('should return null data when no products are found', async () => {
    vi.resetModules()
    vi.doMock('@/lib/apollo/server-client', () => ({
      getClient: () => ({
        query: vi.fn().mockResolvedValue({
          data: {
            productCategory: { name: 'Leer', description: '', slug: 'leer', count: 0, image: null },
            products: { nodes: [] },
          },
        }),
      }),
    }))
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({ query: 'featured_collection', slug: 'leer', first: 4 })
    expect(result.data).toBeNull()
    vi.doUnmock('@/lib/apollo/server-client')
  })

  it('should return null data when slug is missing', async () => {
    vi.resetModules()
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({ query: 'featured_collection' } as any)
    expect(result.data).toBeNull()
  })
})

// --- global.yaml / loadGlobalConfig ---

describe('loadGlobalConfig', () => {
  it('should load global.yaml and return sections with announcement-bar', async () => {
    const { loadGlobalConfig } = await import('@/lib/blocks/page-config')
    const config = loadGlobalConfig('default')
    expect(config.sections).toBeDefined()
    expect(config.sections.length).toBeGreaterThanOrEqual(1)
    const announcementBlock = config.sections[0]?.blocks?.find(
      (b: { type: string }) => b.type === 'announcement-bar'
    )
    expect(announcementBlock).toBeDefined()
  })

  it('should return empty sections when global.yaml does not exist', async () => {
    const { loadGlobalConfig } = await import('@/lib/blocks/page-config')
    const config = loadGlobalConfig('nonexistent-theme-xyz')
    expect(config.sections).toEqual([])
  })
})

// --- AC-3: AnnouncementBar localStorage-dismissed ---

describe('AnnouncementBarBlock localStorage dismissed (AC-3)', () => {
  it('should not render when localStorage dismissed key is set', async () => {
    const { AnnouncementBarBlock } = await import('@/components/blocks/announcement-bar-block')
    window.localStorage.setItem('announcement-dismissed-launch-2026', '1')
    const data = { id: 'launch-2026', text: 'Kostenloser Versand ab 50€', dismissible: true }
    render(<AnnouncementBarBlock data={data} />)
    expect(screen.queryByText('Kostenloser Versand ab 50€')).toBeNull()
    window.localStorage.clear()
  })

  it('should render when localStorage dismissed key is NOT set', async () => {
    const { AnnouncementBarBlock } = await import('@/components/blocks/announcement-bar-block')
    window.localStorage.clear()
    const data = { id: 'launch-2026', text: 'Kostenloser Versand ab 50€', dismissible: true }
    render(<AnnouncementBarBlock data={data} />)
    expect(screen.getByText('Kostenloser Versand ab 50€')).toBeTruthy()
  })
})
