// tests/slices/kleinstadtpflanze-layout-flair/pdp-gallery-vertical-thumbs.test.tsx
//
// Slice-02: ProductImageGallery `layout="vertical-thumbs"` + ProductGalleryBlock forwarding
// Spec: specs/2026-04-26-kleinstadtpflanze-layout-flair/slim-spec.md (Slice 2)
//
// Acceptance Criteria covered:
//   AC-2.1: Gallery default backward-compat (layout undefined / "default" -> no vertical-thumbs grid)
//   AC-2.2: Gallery vertical-thumbs desktop (lg:grid lg:grid-cols-[80px_1fr] lg:gap-4 outer container,
//           thumbs as first column with flex flex-col gap-3, main image as second column)
//   AC-2.3: Gallery mobile unchanged (mobile dots row visible at all layouts; vertical-thumbs grid
//           is gated by lg: breakpoint, mobile is unchanged)
//   AC-2.4: Gallery thumb click swap (clicking nth thumb updates main <img> src; active class swap)
//
// Strategy: real React render via @testing-library/react. next/image renders <img>
// in jsdom. Real @testing-library fireEvent for click. Class-based assertions for
// the responsive breakpoint behavior (jsdom does not run Tailwind media queries).

import React from 'react'
import { describe, it, expect } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { ProductImageGallery } from '@/components/product/product-image-gallery'
import { ProductGalleryBlock } from '@/components/blocks/product-gallery-block'
import type { ProductImage } from '@/lib/graphql/types'

const FIVE_IMAGES: ProductImage[] = [
  { sourceUrl: '/img/p1.jpg', altText: 'Bild 1', mediaDetails: { width: 800, height: 800 } },
  { sourceUrl: '/img/p2.jpg', altText: 'Bild 2', mediaDetails: { width: 800, height: 800 } },
  { sourceUrl: '/img/p3.jpg', altText: 'Bild 3', mediaDetails: { width: 800, height: 800 } },
  { sourceUrl: '/img/p4.jpg', altText: 'Bild 4', mediaDetails: { width: 800, height: 800 } },
  { sourceUrl: '/img/p5.jpg', altText: 'Bild 5', mediaDetails: { width: 800, height: 800 } },
]

const FOUR_IMAGES: ProductImage[] = FIVE_IMAGES.slice(0, 4)

describe('Slice 02 — PDP Gallery vertical-thumbs', () => {
  describe('AC-2.1: Gallery default backward-compat', () => {
    it(
      'AC-2.1a: GIVEN ProductImageGallery is rendered with `layout` undefined ' +
        'WHEN the component mounts ' +
        'THEN the outer container does NOT have lg:grid lg:grid-cols-[80px_1fr] ' +
        'AND no vertical-thumbs flex-col container is present',
      () => {
        const { container } = render(
          <ProductImageGallery images={FIVE_IMAGES} productName="Test Product" />,
        )
        // Walk all root-level descendants and assert none have the vertical-thumbs grid template.
        const allDivs = container.querySelectorAll('div')
        for (const d of allDivs) {
          expect(d.className).not.toContain('lg:grid-cols-[80px_1fr]')
        }
      },
    )

    it(
      'AC-2.1b: GIVEN ProductImageGallery is rendered with `layout="default"` ' +
        'WHEN the component mounts ' +
        'THEN the existing horizontal-thumb-row (hidden lg:flex with mt-4) renders ' +
        'AND no vertical-thumbs flex-col container is present',
      () => {
        const { container } = render(
          <ProductImageGallery
            images={FIVE_IMAGES}
            productName="Test Product"
            layout="default"
          />,
        )
        // Default path: thumbnails appear in a horizontal flex row gated by lg:.
        const horizThumbRow = Array.from(container.querySelectorAll('div')).find((d) =>
          /hidden\s+lg:flex/.test(d.className) && d.className.includes('mt-4'),
        )
        expect(horizThumbRow, 'horizontal thumb row should render in default layout').toBeTruthy()

        // Vertical-thumbs grid template MUST NOT be present.
        const verticalGrid = Array.from(container.querySelectorAll('div')).find((d) =>
          d.className.includes('lg:grid-cols-[80px_1fr]'),
        )
        expect(verticalGrid, 'vertical-thumbs grid must NOT render in default layout').toBeFalsy()
      },
    )
  })

  describe('AC-2.2: Gallery vertical-thumbs desktop layout', () => {
    it(
      'AC-2.2a: GIVEN ProductImageGallery is rendered with `layout="vertical-thumbs"` and 5 images ' +
        'WHEN inspected ' +
        'THEN the OUTER container has classes lg:grid lg:grid-cols-[80px_1fr] lg:gap-4',
      () => {
        const { container } = render(
          <ProductImageGallery
            images={FIVE_IMAGES}
            productName="Test Product"
            layout="vertical-thumbs"
          />,
        )
        // The outermost div (first descendant of the container) is the grid wrapper.
        const outer = container.firstElementChild as HTMLElement
        expect(outer, 'outer wrapper must exist').toBeTruthy()
        const cls = outer.className
        expect(cls).toContain('lg:grid')
        expect(cls).toContain('lg:grid-cols-[80px_1fr]')
        expect(cls).toContain('lg:gap-4')
      },
    )

    it(
      'AC-2.2b: GIVEN vertical-thumbs layout with 5 images ' +
        'WHEN the thumbs column is inspected ' +
        'THEN it is the FIRST grid child with classes "flex flex-col gap-3" ' +
        '(hidden on mobile via lg:flex)',
      () => {
        const { container } = render(
          <ProductImageGallery
            images={FIVE_IMAGES}
            productName="Test Product"
            layout="vertical-thumbs"
          />,
        )
        const outer = container.firstElementChild as HTMLElement
        const firstChild = outer.firstElementChild as HTMLElement
        expect(firstChild).toBeTruthy()
        const cls = firstChild.className
        // First grid column = vertical thumbs container
        expect(cls).toContain('flex-col')
        expect(cls).toContain('gap-3')
        // It is gated on lg breakpoint (hidden on mobile; lg:flex on desktop)
        expect(cls).toContain('hidden')
        expect(cls).toContain('lg:flex')
      },
    )

    it(
      'AC-2.2c: GIVEN vertical-thumbs layout with 5 images ' +
        'WHEN the second grid column is inspected ' +
        'THEN it contains the main image (region role + aria-label "Produktbilder")',
      () => {
        const { container } = render(
          <ProductImageGallery
            images={FIVE_IMAGES}
            productName="Test Product"
            layout="vertical-thumbs"
          />,
        )
        const outer = container.firstElementChild as HTMLElement
        // The second child is the wrapper for main image + mobile dots.
        const secondChild = outer.children[1] as HTMLElement
        expect(secondChild).toBeTruthy()
        // Inside, the main image region carries role="region" + aria-label="Produktbilder"
        const mainRegion = secondChild.querySelector('[role="region"][aria-label="Produktbilder"]')
        expect(mainRegion, 'main image region must be in second column').toBeTruthy()
      },
    )

    it(
      'AC-2.2d: GIVEN vertical-thumbs layout with 5 images ' +
        'WHEN the thumb buttons are inspected ' +
        'THEN exactly 5 thumb <button role="tab"> elements render in the first column',
      () => {
        const { container } = render(
          <ProductImageGallery
            images={FIVE_IMAGES}
            productName="Test Product"
            layout="vertical-thumbs"
          />,
        )
        // Vertical-thumbs first column has the role="tablist" container.
        const tablists = container.querySelectorAll('[role="tablist"]')
        // There should be at least one (vertical thumbs); mobile dots also use tablist when >1 image.
        expect(tablists.length).toBeGreaterThanOrEqual(1)

        // Find the tablist that has flex-col (vertical thumbs distinguishing class).
        const vertList = Array.from(tablists).find((t) =>
          (t as HTMLElement).className.includes('flex-col'),
        )
        expect(vertList).toBeTruthy()
        const thumbButtons = vertList!.querySelectorAll('button[role="tab"]')
        expect(thumbButtons.length).toBe(5)
      },
    )
  })

  describe('AC-2.3: Gallery mobile unchanged (dots row present)', () => {
    it(
      'AC-2.3a: GIVEN ProductImageGallery is rendered with `layout="vertical-thumbs"` ' +
        'WHEN the mobile dots row is inspected ' +
        'THEN a tablist container with classes "flex lg:hidden" exists ' +
        '(dots row visible on mobile, hidden on lg+)',
      () => {
        const { container } = render(
          <ProductImageGallery
            images={FIVE_IMAGES}
            productName="Test Product"
            layout="vertical-thumbs"
          />,
        )
        // Find the dots row: tablist containing classes "flex lg:hidden"
        const tablists = container.querySelectorAll('[role="tablist"]')
        const mobileDots = Array.from(tablists).find((t) => {
          const cls = (t as HTMLElement).className
          return cls.includes('lg:hidden') && cls.includes('flex')
        })
        expect(mobileDots, 'mobile dots row (flex lg:hidden) must render').toBeTruthy()

        // Dot count == image count.
        const dotButtons = mobileDots!.querySelectorAll('button[role="tab"]')
        expect(dotButtons.length).toBe(FIVE_IMAGES.length)
      },
    )

    it(
      'AC-2.3b: GIVEN vertical-thumbs layout ' +
        'WHEN inspecting the dots row ' +
        'THEN it does NOT use the vertical-thumbs grid classes ' +
        '(no lg:grid-cols-[80px_1fr] on the dots tablist)',
      () => {
        const { container } = render(
          <ProductImageGallery
            images={FIVE_IMAGES}
            productName="Test Product"
            layout="vertical-thumbs"
          />,
        )
        const tablists = container.querySelectorAll('[role="tablist"]')
        const mobileDots = Array.from(tablists).find((t) =>
          (t as HTMLElement).className.includes('lg:hidden'),
        ) as HTMLElement
        expect(mobileDots).toBeTruthy()
        expect(mobileDots.className).not.toContain('lg:grid-cols-[80px_1fr]')
      },
    )
  })

  describe('AC-2.4: Gallery thumb click swap', () => {
    it(
      'AC-2.4: GIVEN vertical-thumbs layout with 4 images, activeIndex initially 0 ' +
        'WHEN the user clicks the 3rd thumb (index 2) ' +
        'THEN the main <img> src updates to images[2] ' +
        'AND the previously active thumb loses its border-primary class ' +
        'AND the 3rd thumb gains border-primary',
      () => {
        const { container } = render(
          <ProductImageGallery
            images={FOUR_IMAGES}
            productName="Test Product"
            layout="vertical-thumbs"
          />,
        )
        // Find the vertical-thumbs tablist (flex-col).
        const tablists = container.querySelectorAll('[role="tablist"]')
        const vertList = Array.from(tablists).find((t) =>
          (t as HTMLElement).className.includes('flex-col'),
        ) as HTMLElement
        expect(vertList).toBeTruthy()

        const thumbButtons = vertList.querySelectorAll('button[role="tab"]') as NodeListOf<HTMLButtonElement>
        expect(thumbButtons.length).toBe(4)

        // Initial: thumb 0 active (border-primary), thumb 2 not active.
        expect(thumbButtons[0].className).toContain('border-primary')
        expect(thumbButtons[2].className).not.toContain('border-primary')

        // Click 3rd thumb (index 2)
        fireEvent.click(thumbButtons[2])

        // Previously active (idx 0) must lose border-primary; idx 2 gains it.
        expect(thumbButtons[0].className).not.toContain('border-primary')
        expect(thumbButtons[2].className).toContain('border-primary')

        // Main image src updates to images[2].sourceUrl. The main image is in
        // a [role="region"][aria-label="Produktbilder"] element.
        const mainRegion = container.querySelector(
          '[role="region"][aria-label="Produktbilder"]',
        ) as HTMLElement
        expect(mainRegion).toBeTruthy()
        const mainImg = mainRegion.querySelector('img') as HTMLImageElement
        expect(mainImg).toBeTruthy()
        // next/image rewrites src into /_next/image?url=<urlencoded>; assert the
        // url-encoded asset path appears in the rewritten src.
        const expectedUrlEncoded = encodeURIComponent('/img/p3.jpg')
        expect(mainImg.src).toContain(expectedUrlEncoded)
      },
    )
  })

  describe('ProductGalleryBlock — forwards `layout` from data to ProductImageGallery', () => {
    it(
      'GIVEN ProductGalleryBlock receives data with `layout: "vertical-thumbs"` ' +
        'WHEN it renders ' +
        'THEN the outer wrapper carries lg:grid-cols-[80px_1fr] (forwarded to gallery)',
      () => {
        const data = {
          name: 'Test',
          image: FIVE_IMAGES[0],
          galleryImages: { nodes: FIVE_IMAGES.slice(1) },
          layout: 'vertical-thumbs' as const,
        } as unknown as Parameters<typeof ProductGalleryBlock>[0]['data']

        const { container } = render(<ProductGalleryBlock data={data} />)
        const outer = container.firstElementChild as HTMLElement
        expect(outer.className).toContain('lg:grid-cols-[80px_1fr]')
      },
    )

    it(
      'GIVEN ProductGalleryBlock receives data WITHOUT `layout` ' +
        'WHEN it renders ' +
        'THEN no vertical-thumbs grid classes are applied (default backward-compat)',
      () => {
        const data = {
          name: 'Test',
          image: FIVE_IMAGES[0],
          galleryImages: { nodes: FIVE_IMAGES.slice(1) },
        } as unknown as Parameters<typeof ProductGalleryBlock>[0]['data']

        const { container } = render(<ProductGalleryBlock data={data} />)
        const allDivs = container.querySelectorAll('div')
        for (const d of allDivs) {
          expect(d.className).not.toContain('lg:grid-cols-[80px_1fr]')
        }
      },
    )
  })
})
