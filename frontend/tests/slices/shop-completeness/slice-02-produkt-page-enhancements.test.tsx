// tests/slices/shop-completeness/slice-02-produkt-page-enhancements.test.tsx
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import React from 'react'

// ─── Hilfsfunktionen ────────────────────────────────────────────────────────

function buildReviewEdge(overrides?: Partial<{ rating: number; name: string; content: string; id: string }>) {
  return {
    rating: overrides?.rating ?? 4,
    node: {
      id: overrides?.id ?? 'review-1',
      content: overrides?.content ?? 'Tolles Produkt!',
      date: '2026-01-12',
      author: { node: { name: overrides?.name ?? 'Max M.' } },
    },
  }
}

function buildProductReviewsResult(overrides?: {
  averageRating?: number
  reviewCount?: number
  reviewsAllowed?: boolean
  reviews?: ReturnType<typeof buildReviewEdge>[]
}) {
  return {
    averageRating: overrides?.averageRating ?? 4.2,
    reviewCount: overrides?.reviewCount ?? 3,
    reviewsAllowed: overrides?.reviewsAllowed ?? true,
    reviews: { edges: overrides?.reviews ?? [buildReviewEdge()] },
    productId: 42,
  }
}

// ─── Mocks ──────────────────────────────────────────────────────────────────

const mockMutate = vi.fn()

vi.mock('@/lib/apollo/client', () => ({
  apolloClient: {
    mutate: mockMutate,
  },
}))

vi.mock('@/lib/graphql/mutations', () => ({
  WRITE_REVIEW: 'WRITE_REVIEW_MUTATION',
}))

// ─── ProductReviewsBlock ─────────────────────────────────────────────────────

describe('ProductReviewsBlock', () => {
  // Lazy import nach Mock-Setup
  let ProductReviewsBlock: React.ComponentType<{ data: ReturnType<typeof buildProductReviewsResult> | null }>

  beforeEach(async () => {
    vi.clearAllMocks()
    const mod = await import('@/components/blocks/product-reviews-block')
    ProductReviewsBlock = mod.ProductReviewsBlock
  })

  it('should render star summary with average rating and review count', () => {
    const data = buildProductReviewsResult({ averageRating: 4.2, reviewCount: 47 })
    render(<ProductReviewsBlock data={data} />)

    expect(screen.getByText(/4[,.]2/)).toBeTruthy()
    expect(screen.getByText(/47/)).toBeTruthy()
  })

  it('should render review list with author names and content', () => {
    const data = buildProductReviewsResult({
      reviews: [
        buildReviewEdge({ name: 'Max M.', content: 'Super Qualität!' }),
        buildReviewEdge({ id: 'r2', name: 'Anna K.', content: 'Schönes Design.' }),
      ],
    })
    render(<ProductReviewsBlock data={data} />)

    expect(screen.getByText('Max M.')).toBeTruthy()
    expect(screen.getByText('Super Qualität!')).toBeTruthy()
    expect(screen.getByText('Anna K.')).toBeTruthy()
  })

  it('should show review form when "Bewertung schreiben" is clicked', async () => {
    const data = buildProductReviewsResult()
    render(<ProductReviewsBlock data={data} />)

    const toggleButton = screen.getByRole('button', { name: /Bewertung schreiben/i })
    fireEvent.click(toggleButton)

    await waitFor(() => {
      expect(screen.getByLabelText(/Name/i)).toBeTruthy()
      expect(screen.getByLabelText(/E-Mail/i)).toBeTruthy()
    })
  })

  it('should show inline validation errors when submitting empty form', async () => {
    const data = buildProductReviewsResult()
    render(<ProductReviewsBlock data={data} />)

    fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))

    await waitFor(() => {
      expect(screen.getByLabelText(/Name/i)).toBeTruthy()
    })

    fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

    await waitFor(() => {
      expect(screen.getByText(/Bitte gib deinen Namen ein/i)).toBeTruthy()
    })
  })

  it('should validate minimum name length of 2 characters', async () => {
    const data = buildProductReviewsResult()
    render(<ProductReviewsBlock data={data} />)

    fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))

    await waitFor(() => {
      expect(screen.getByLabelText(/Name/i)).toBeTruthy()
    })

    fireEvent.change(screen.getByLabelText(/Name/i), { target: { value: 'A' } })
    fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

    await waitFor(() => {
      expect(screen.getByText(/min. 2 Zeichen/i)).toBeTruthy()
    })
  })

  it('should validate minimum content length of 10 characters', async () => {
    const data = buildProductReviewsResult()
    render(<ProductReviewsBlock data={data} />)

    fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))
    await waitFor(() => { expect(screen.getByLabelText(/Name/i)).toBeTruthy() })

    fireEvent.change(screen.getByLabelText(/Name/i), { target: { value: 'Max Muster' } })
    fireEvent.change(screen.getByLabelText(/E-Mail/i), { target: { value: 'max@example.com' } })
    // Rating wird nicht gesetzt, Content zu kurz
    const textarea = screen.getByRole('textbox', { name: /Bewertungstext|Deine Bewertung/i })
    fireEvent.change(textarea, { target: { value: 'Kurz' } })
    fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

    await waitFor(() => {
      expect(screen.getByText(/mindestens 10 Zeichen/i)).toBeTruthy()
    })
  })

  it('should call WRITE_REVIEW mutation with correct variables on valid submit', async () => {
    mockMutate.mockResolvedValueOnce({
      data: { writeReview: { rating: 5, review: { id: 'r-new', content: 'Super!', date: '2026-02-25' } } },
    })

    const data = buildProductReviewsResult()
    // ProductReviewsBlock braucht productId — via data oder prop
    render(<ProductReviewsBlock data={{ ...data, productId: 42 } as any} />)

    fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))
    await waitFor(() => { expect(screen.getByLabelText(/Name/i)).toBeTruthy() })

    fireEvent.change(screen.getByLabelText(/Name/i), { target: { value: 'Max Muster' } })
    fireEvent.change(screen.getByLabelText(/E-Mail/i), { target: { value: 'max@example.com' } })
    // Stern-Rating: Klick auf 5-Sterne-Button
    const starButtons = screen.getAllByRole('button', { name: /Stern/i })
    fireEvent.click(starButtons[4]) // 5. Stern
    const textarea = screen.getByRole('textbox', { name: /Bewertungstext|Deine Bewertung/i })
    fireEvent.change(textarea, { target: { value: 'Tolles Produkt, sehr empfehlenswert!' } })

    fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalledWith(
        expect.objectContaining({
          variables: expect.objectContaining({
            author: 'Max Muster',
            authorEmail: 'max@example.com',
            rating: 5,
            content: 'Tolles Produkt, sehr empfehlenswert!',
          }),
        })
      )
    })
  })

  it('should show success message and hide form after successful mutation', async () => {
    mockMutate.mockResolvedValueOnce({
      data: { writeReview: { rating: 4, review: { id: 'r-new', content: 'Toll!', date: '2026-02-25' } } },
    })

    const data = buildProductReviewsResult()
    render(<ProductReviewsBlock data={{ ...data, productId: 42 } as any} />)

    fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))
    await waitFor(() => { expect(screen.getByLabelText(/Name/i)).toBeTruthy() })

    fireEvent.change(screen.getByLabelText(/Name/i), { target: { value: 'Max Muster' } })
    fireEvent.change(screen.getByLabelText(/E-Mail/i), { target: { value: 'max@example.com' } })
    const starButtons = screen.getAllByRole('button', { name: /Stern/i })
    fireEvent.click(starButtons[3])
    const textarea = screen.getByRole('textbox', { name: /Bewertungstext|Deine Bewertung/i })
    fireEvent.change(textarea, { target: { value: 'Sehr gutes Produkt!' } })
    fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

    await waitFor(() => {
      expect(screen.getByText(/wird.*geprüft|Danke.*Bewertung/i)).toBeTruthy()
    })

    // Form-Felder sollten nicht mehr sichtbar sein
    expect(screen.queryByLabelText(/Name/i)).toBeNull()
  })

  it('should show error toast and keep form open when WRITE_REVIEW mutation fails', async () => {
    mockMutate.mockRejectedValueOnce(new Error('Network error'))

    const data = buildProductReviewsResult()
    render(<ProductReviewsBlock data={{ ...data, productId: 42 } as any} />)

    fireEvent.click(screen.getByRole('button', { name: /Bewertung schreiben/i }))
    await waitFor(() => { expect(screen.getByLabelText(/Name/i)).toBeTruthy() })

    fireEvent.change(screen.getByLabelText(/Name/i), { target: { value: 'Max Muster' } })
    fireEvent.change(screen.getByLabelText(/E-Mail/i), { target: { value: 'max@example.com' } })
    const starButtons = screen.getAllByRole('button', { name: /Stern/i })
    fireEvent.click(starButtons[4]) // 5. Stern
    const textarea = screen.getByRole('textbox', { name: /Bewertungstext|Deine Bewertung/i })
    fireEvent.change(textarea, { target: { value: 'Tolles Produkt, sehr empfehlenswert!' } })
    fireEvent.click(screen.getByRole('button', { name: /Absenden/i }))

    await waitFor(() => {
      expect(screen.getByText(/Bewertung konnte nicht gespeichert werden/i)).toBeTruthy()
      // Formular bleibt offen (Name-Feld noch sichtbar)
      expect(screen.getByLabelText(/Name/i)).toBeTruthy()
    })
  })

  it('should return null when reviewsAllowed is false', () => {
    const data = buildProductReviewsResult({ reviewsAllowed: false })
    const { container } = render(<ProductReviewsBlock data={data} />)
    expect(container.firstChild).toBeNull()
  })

  it('should return null when data is null', () => {
    const { container } = render(<ProductReviewsBlock data={null} />)
    expect(container.firstChild).toBeNull()
  })
})

// ─── ProductRecommendationsBlock ─────────────────────────────────────────────

describe('ProductRecommendationsBlock', () => {
  let ProductRecommendationsBlock: React.ComponentType<{ data: unknown }>

  beforeEach(async () => {
    const mod = await import('@/components/blocks/product-recommendations-block')
    ProductRecommendationsBlock = mod.ProductRecommendationsBlock as React.ComponentType<{ data: unknown }>
  })

  const makeProduct = (id: string, name: string) => ({
    id,
    slug: `produkt-${id}`,
    name,
    price: '19,99\u00a0€',
    regularPrice: null,
    onSale: false,
    stockStatus: 'IN_STOCK',
    image: { sourceUrl: `/img/${id}.jpg`, altText: name },
    productCategories: { nodes: [] },
  })

  it('should render heading from params and 4 product cards', () => {
    const data = {
      heading: 'Das könnte dir gefallen',
      products: {
        nodes: [
          makeProduct('1', 'Produkt 1'),
          makeProduct('2', 'Produkt 2'),
          makeProduct('3', 'Produkt 3'),
          makeProduct('4', 'Produkt 4'),
        ],
      },
    }
    render(<ProductRecommendationsBlock data={data} />)

    expect(screen.getByText('Das könnte dir gefallen')).toBeTruthy()
    expect(screen.getByText('Produkt 1')).toBeTruthy()
    expect(screen.getByText('Produkt 4')).toBeTruthy()
  })

  it('should return null when data is null (fallback chain exhausted)', () => {
    const { container } = render(<ProductRecommendationsBlock data={null} />)
    expect(container.firstChild).toBeNull()
  })

  it('should return null when products array is empty', () => {
    const data = { heading: 'Empfehlungen', products: { nodes: [] } }
    const { container } = render(<ProductRecommendationsBlock data={data} />)
    expect(container.firstChild).toBeNull()
  })

  it('should render heading with aria-labelledby on section', () => {
    const data = {
      heading: 'Ähnliche Produkte',
      products: { nodes: [makeProduct('1', 'Test')] },
    }
    render(<ProductRecommendationsBlock data={data} />)

    const heading = screen.getByRole('heading', { name: 'Ähnliche Produkte' })
    expect(heading).toBeTruthy()
    const section = heading.closest('section')
    expect(section?.getAttribute('aria-labelledby')).toBeTruthy()
  })
})

// ─── Review Form Validierung (isoliert) ──────────────────────────────────────

describe('Review Validation Logic', () => {
  it('should validate email format correctly', async () => {
    const { validateReviewInput } = await import('@/components/reviews/review-form')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'invalid-email', rating: 4, content: 'Tolles Produkt hier' }))
      .toHaveProperty('authorEmail')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'max@example.com', rating: 4, content: 'Tolles Produkt hier' }))
      .not.toHaveProperty('authorEmail')
  })

  it('should require rating between 1 and 5', async () => {
    const { validateReviewInput } = await import('@/components/reviews/review-form')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'max@example.com', rating: 0, content: 'Tolles Produkt hier' }))
      .toHaveProperty('rating')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'max@example.com', rating: 6, content: 'Tolles Produkt hier' }))
      .toHaveProperty('rating')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'max@example.com', rating: 3, content: 'Tolles Produkt hier' }))
      .not.toHaveProperty('rating')
  })

  it('should require content of minimum 10 characters', async () => {
    const { validateReviewInput } = await import('@/components/reviews/review-form')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'max@example.com', rating: 4, content: 'Kurz' }))
      .toHaveProperty('content')

    expect(validateReviewInput({ author: 'Max', authorEmail: 'max@example.com', rating: 4, content: 'Mindestens zehn!' }))
      .not.toHaveProperty('content')
  })
})

// ─── Data Loader: product_recommendations Fallback ───────────────────────────

const mockQuery = vi.fn()

vi.mock('@/lib/apollo/server-client', () => ({
  getClient: () => ({
    query: mockQuery,
  }),
}))

describe('woocommerceLoader product_recommendations fallback', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('should return category products as fallback when related is empty', async () => {
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')

    const categoryProduct = {
      id: 'cat-prod-1',
      slug: 'kategorie-produkt-1',
      name: 'Kategorie Produkt',
      price: '19,99\u00a0€',
      regularPrice: null,
      onSale: false,
      stockStatus: 'IN_STOCK',
      image: { sourceUrl: '/img/cat1.jpg', altText: 'Kategorie Produkt' },
      productCategories: { nodes: [] },
    }

    // Aufruf 1: GET_PRODUCT_CATEGORY → gibt productId + categorySlug
    mockQuery.mockResolvedValueOnce({
      data: {
        product: {
          databaseId: 99,
          productCategories: { nodes: [{ slug: 't-shirts' }] },
        },
      },
    })
    // Aufruf 2: GET_RELATED_PRODUCTS → leer
    mockQuery.mockResolvedValueOnce({
      data: { product: { related: { nodes: [] } } },
    })
    // Aufruf 3: GET_FEATURED_PRODUCTS (category fallback) → 4 Produkte
    mockQuery.mockResolvedValueOnce({
      data: { products: { nodes: [categoryProduct, categoryProduct, categoryProduct, categoryProduct] } },
    })

    const result = await woocommerceLoader({
      query: 'product_recommendations',
      source: 'related',
      slug: 'aktuelles-produkt',
      first: 4,
      heading: 'Das koennte dir gefallen',
    } as any)

    expect(result.data).not.toBeNull()
    expect((result.data as any).products.nodes.length).toBeGreaterThan(0)
  })

  it('should return null when related and category both return empty', async () => {
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')

    // Aufruf 1: GET_PRODUCT_CATEGORY → gibt productId + categorySlug
    mockQuery.mockResolvedValueOnce({
      data: {
        product: {
          databaseId: 99,
          productCategories: { nodes: [{ slug: 't-shirts' }] },
        },
      },
    })
    // Aufruf 2: GET_RELATED_PRODUCTS → leer
    mockQuery.mockResolvedValueOnce({
      data: { product: { related: { nodes: [] } } },
    })
    // Aufruf 3: GET_FEATURED_PRODUCTS (category fallback) → leer
    mockQuery.mockResolvedValueOnce({
      data: { products: { nodes: [] } },
    })

    const result = await woocommerceLoader({
      query: 'product_recommendations',
      source: 'related',
      slug: 'aktuelles-produkt',
      first: 4,
      heading: 'Das koennte dir gefallen',
    } as any)

    expect(result.data).toBeNull()
  })

  it('should return null when source=bestsellers and query returns empty', async () => {
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')

    // Aufruf 1: GET_PRODUCT_CATEGORY
    mockQuery.mockResolvedValueOnce({
      data: {
        product: { databaseId: 99, productCategories: { nodes: [] } },
      },
    })
    // Aufruf 2: GET_BESTSELLER_PRODUCTS → leer
    mockQuery.mockResolvedValueOnce({
      data: { products: { nodes: [] } },
    })

    const result = await woocommerceLoader({
      query: 'product_recommendations',
      source: 'bestsellers',
      slug: 'aktuelles-produkt',
      first: 4,
      heading: 'Bestseller',
    } as any)

    expect(result.data).toBeNull()
  })
})

// ─── YAML product.yaml ────────────────────────────────────────────────────────

describe('product.yaml configuration', () => {
  it('should contain trust-badges, product-reviews, and product-recommendations sections', async () => {
    const fs = await import('fs')
    const path = await import('path')
    const yaml = await import('yaml')

    const yamlPath = path.resolve('themes/default/pages/product.yaml')
    const content = fs.readFileSync(yamlPath, 'utf-8')
    const config = yaml.parse(content) as { sections: Array<{ blocks: Array<{ type: string }> }> }

    const allBlockTypes = config.sections.flatMap(s => s.blocks.map(b => b.type))
    expect(allBlockTypes).toContain('trust-badges')
    expect(allBlockTypes).toContain('product-reviews')
    expect(allBlockTypes).toContain('product-recommendations')
  })
})
