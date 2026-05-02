// tests/slices/kleinstadtpflanze-layout-flair/pdp-purchase-with-description.test.tsx
//
// Slice-02: ProductPurchaseBlock `withDescription` toggle + inline `serviceBox` composition
// Spec: specs/2026-04-26-kleinstadtpflanze-layout-flair/slim-spec.md (Slice 2)
//
// Acceptance Criteria covered:
//   AC-2.5: Purchase withDescription off (no .prose container when toggle is false/absent)
//   AC-2.6: Purchase withDescription on (renders mt-8 prose prose-sm container with description HTML)
//   AC-2.7: Purchase serviceBox composition (renders ServiceBoxBlock with N items below description;
//           absent / empty serviceBox -> no service-box rendered)
//
// Strategy: ProductPurchaseBlock pulls in ProductVariantSelector, which depends on the cart-context
// (Apollo). Per Test-Strategy `mock_external`: we mock ProductVariantSelector at the import level
// so we can assert the description + service-box composition logic in isolation. ServiceBoxBlock
// is imported real (no external deps).

import React from 'react'
import { describe, it, expect, vi } from 'vitest'

// Mock ProductVariantSelector — it depends on Apollo + cart-context, which are
// not under test here (those are exercised by tests/slices/pod-shop-mvp/...).
vi.mock('@/app/produkt/[slug]/product-variant-selector', () => ({
  ProductVariantSelector: ({ product }: { product: { name: string } }) => (
    <div data-testid="mock-variant-selector">VariantSelector({product.name})</div>
  ),
}))

import { render } from '@testing-library/react'
import { ProductPurchaseBlock } from '@/components/blocks/product-purchase-block'
import type { ProductDetailData } from '@/lib/graphql/types'
import type { ServiceBoxData } from '@/lib/blocks/types'

function makeProduct(overrides: Partial<ProductDetailData> = {}): ProductDetailData {
  return {
    id: 'prod-1',
    databaseId: 123,
    slug: 'test-product',
    name: 'Test Product',
    description: '<p>Hello</p>',
    shortDescription: 'Short',
    price: '19,99 €',
    regularPrice: '19,99 €',
    onSale: false,
    variations: { nodes: [] },
    image: { sourceUrl: '/p.jpg', altText: 'Bild', mediaDetails: { width: 800, height: 800 } },
    galleryImages: { nodes: [] },
    productCategories: { nodes: [] },
    ...overrides,
  } as unknown as ProductDetailData
}

const SERVICE_BOX_3: ServiceBoxData = {
  items: [
    { icon: 'package', label: 'Versand DE 3–5 Werktage', detail: 'Auf Bestellung produziert' },
    { icon: 'refresh', label: '30 Tage Rückgabe', detail: 'Unbenutzt in Originalverpackung' },
    { icon: 'ruler', label: 'Größen / Maße', detail: 'Siehe Größentabelle' },
  ],
}

describe('Slice 02 — ProductPurchaseBlock with withDescription + serviceBox', () => {
  describe('AC-2.5: withDescription off (or absent) -> no description container', () => {
    it(
      'AC-2.5a: GIVEN data with withDescription=false AND description="<p>foo</p>" ' +
        'WHEN ProductPurchaseBlock renders ' +
        'THEN no element with classes "prose prose-sm" is present',
      () => {
        const data = {
          ...makeProduct({ description: '<p>foo</p>' }),
          withDescription: false,
        } as unknown as Parameters<typeof ProductPurchaseBlock>[0]['data']
        const { container } = render(<ProductPurchaseBlock data={data} />)
        const proseEl = container.querySelector('.prose.prose-sm')
        expect(proseEl, '.prose.prose-sm container must NOT render when withDescription=false').toBeNull()
      },
    )

    it(
      'AC-2.5b: GIVEN data with withDescription absent (undefined) AND description="<p>foo</p>" ' +
        'WHEN ProductPurchaseBlock renders ' +
        'THEN no element with classes "prose prose-sm" is present (backward-compat)',
      () => {
        const data = makeProduct({ description: '<p>foo</p>' })
        const { container } = render(<ProductPurchaseBlock data={data} />)
        expect(container.querySelector('.prose.prose-sm')).toBeNull()
      },
    )
  })

  describe('AC-2.6: withDescription on -> description renders below variant selector', () => {
    it(
      'AC-2.6a: GIVEN data with withDescription=true AND description="<p>Hello</p>" ' +
        'WHEN ProductPurchaseBlock renders ' +
        'THEN a div with classes "mt-8 prose prose-sm text-text-secondary max-w-none" exists ' +
        'AND its inner HTML contains <p>Hello</p>',
      () => {
        const data = {
          ...makeProduct({ description: '<p>Hello</p>' }),
          withDescription: true,
        } as unknown as Parameters<typeof ProductPurchaseBlock>[0]['data']
        const { container } = render(<ProductPurchaseBlock data={data} />)

        // Find the prose container.
        const prose = container.querySelector('.prose.prose-sm') as HTMLElement
        expect(prose, 'prose container must render').toBeTruthy()
        expect(prose.className).toContain('mt-8')
        expect(prose.className).toContain('prose')
        expect(prose.className).toContain('prose-sm')
        expect(prose.className).toContain('text-text-secondary')
        expect(prose.className).toContain('max-w-none')

        // Description HTML rendered via dangerouslySetInnerHTML.
        expect(prose.innerHTML).toBe('<p>Hello</p>')
      },
    )

    it(
      'AC-2.6b: GIVEN withDescription=true AND description is empty string ' +
        'WHEN ProductPurchaseBlock renders ' +
        'THEN no .prose container renders (block guards on Boolean(description))',
      () => {
        const data = {
          ...makeProduct({ description: '' }),
          withDescription: true,
        } as unknown as Parameters<typeof ProductPurchaseBlock>[0]['data']
        const { container } = render(<ProductPurchaseBlock data={data} />)
        expect(container.querySelector('.prose.prose-sm')).toBeNull()
      },
    )

    it(
      'AC-2.6c: GIVEN withDescription=true ' +
        'WHEN inspecting DOM order ' +
        'THEN the .prose container appears AFTER the variant selector ' +
        '(below the variant selector, per spec)',
      () => {
        const data = {
          ...makeProduct({ description: '<p>Hello</p>' }),
          withDescription: true,
        } as unknown as Parameters<typeof ProductPurchaseBlock>[0]['data']
        const { container } = render(<ProductPurchaseBlock data={data} />)
        const variant = container.querySelector('[data-testid="mock-variant-selector"]') as HTMLElement
        const prose = container.querySelector('.prose.prose-sm') as HTMLElement
        expect(variant).toBeTruthy()
        expect(prose).toBeTruthy()
        // Document order: variant must appear before prose.
        const compare = variant.compareDocumentPosition(prose)
        // DOCUMENT_POSITION_FOLLOWING = 4 -> prose follows variant
        expect(compare & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
      },
    )
  })

  describe('AC-2.7: serviceBox composition', () => {
    it(
      'AC-2.7a: GIVEN data.serviceBox = { items: [3 items] } ' +
        'WHEN ProductPurchaseBlock renders ' +
        'THEN exactly 3 service-box <li> items appear in the DOM ' +
        'AND the service-box section has aria-label "Produkt-Service-Hinweise"',
      () => {
        const data = {
          ...makeProduct(),
          withDescription: false,
          serviceBox: SERVICE_BOX_3,
        } as unknown as Parameters<typeof ProductPurchaseBlock>[0]['data']
        const { container } = render(<ProductPurchaseBlock data={data} />)

        const sb = container.querySelector('[aria-label="Produkt-Service-Hinweise"]') as HTMLElement
        expect(sb, 'ServiceBoxBlock must render').toBeTruthy()
        const items = sb.querySelectorAll('li')
        expect(items.length).toBe(3)
      },
    )

    it(
      'AC-2.7b: GIVEN data.serviceBox is absent ' +
        'WHEN ProductPurchaseBlock renders ' +
        'THEN no service-box section appears',
      () => {
        const data = makeProduct()
        const { container } = render(<ProductPurchaseBlock data={data} />)
        expect(container.querySelector('[aria-label="Produkt-Service-Hinweise"]')).toBeNull()
      },
    )

    it(
      'AC-2.7c: GIVEN data.serviceBox = { items: [] } ' +
        'WHEN ProductPurchaseBlock renders ' +
        'THEN no service-box section appears (graceful empty)',
      () => {
        const data = {
          ...makeProduct(),
          serviceBox: { items: [] },
        } as unknown as Parameters<typeof ProductPurchaseBlock>[0]['data']
        const { container } = render(<ProductPurchaseBlock data={data} />)
        expect(container.querySelector('[aria-label="Produkt-Service-Hinweise"]')).toBeNull()
      },
    )

    it(
      'AC-2.7d: GIVEN withDescription=true AND serviceBox with 3 items ' +
        'WHEN ProductPurchaseBlock renders ' +
        'THEN the ServiceBox renders BELOW the description in DOM order',
      () => {
        const data = {
          ...makeProduct({ description: '<p>Hello</p>' }),
          withDescription: true,
          serviceBox: SERVICE_BOX_3,
        } as unknown as Parameters<typeof ProductPurchaseBlock>[0]['data']
        const { container } = render(<ProductPurchaseBlock data={data} />)
        const prose = container.querySelector('.prose.prose-sm') as HTMLElement
        const sb = container.querySelector('[aria-label="Produkt-Service-Hinweise"]') as HTMLElement
        expect(prose).toBeTruthy()
        expect(sb).toBeTruthy()
        // Description must come before service-box.
        const compare = prose.compareDocumentPosition(sb)
        expect(compare & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
      },
    )

    it(
      'AC-2.7e: GIVEN withDescription=false AND serviceBox with 3 items ' +
        'WHEN ProductPurchaseBlock renders ' +
        'THEN the ServiceBox renders BELOW the variant selector ' +
        '(no description in between)',
      () => {
        const data = {
          ...makeProduct(),
          withDescription: false,
          serviceBox: SERVICE_BOX_3,
        } as unknown as Parameters<typeof ProductPurchaseBlock>[0]['data']
        const { container } = render(<ProductPurchaseBlock data={data} />)
        const variant = container.querySelector('[data-testid="mock-variant-selector"]') as HTMLElement
        const sb = container.querySelector('[aria-label="Produkt-Service-Hinweise"]') as HTMLElement
        expect(variant).toBeTruthy()
        expect(sb).toBeTruthy()
        const compare = variant.compareDocumentPosition(sb)
        expect(compare & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
        // No prose container in between.
        expect(container.querySelector('.prose.prose-sm')).toBeNull()
      },
    )
  })
})
