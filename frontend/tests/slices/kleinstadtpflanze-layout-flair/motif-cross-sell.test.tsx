// tests/slices/kleinstadtpflanze-layout-flair/motif-cross-sell.test.tsx
//
// Slice-02: MotifCrossSellBlock — new server block with graceful-null contract
// Spec: specs/2026-04-26-kleinstadtpflanze-layout-flair/slim-spec.md (Slice 2)
//
// Acceptance Criteria covered:
//   AC-2.11: MotifCrossSell tiles=null -> returns null (no DOM)
//   AC-2.12: MotifCrossSell tiles=[] -> returns null (same as null)
//   AC-2.13: MotifCrossSell tiles render (heading + 3 tiles, each <a href> matches "^/produkt/",
//            each tile shows productName + productType)
//
// Plus: filter test for non-/produkt/ hrefs (block-level safe-href guard mentioned in deliverables).
//
// Strategy: real React render. next/link + next/image render real <a> + <img> in jsdom.

import React from 'react'
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MotifCrossSellBlock } from '@/components/blocks/motif-cross-sell-block'

const THREE_TILES = [
  {
    productName: 'Helgoland Print',
    productType: 'Tasse',
    image_url: '/motifs/helgoland-tasse.jpg',
    href: '/produkt/helgoland-tasse',
  },
  {
    productName: 'Helgoland Print',
    productType: 'Beutel',
    image_url: '/motifs/helgoland-beutel.jpg',
    href: '/produkt/helgoland-beutel',
  },
  {
    productName: 'Helgoland Print',
    productType: 'Shirt',
    image_url: '/motifs/helgoland-shirt.jpg',
    href: '/produkt/helgoland-shirt',
  },
]

describe('Slice 02 — MotifCrossSellBlock', () => {
  describe('AC-2.11: MotifCrossSell tiles=null -> null render', () => {
    it(
      'AC-2.11: GIVEN MotifCrossSellBlock receives tiles: null ' +
        'WHEN it renders ' +
        'THEN it returns null (no heading, no grid, no empty container appears)',
      () => {
        const { container } = render(
          <MotifCrossSellBlock data={{ heading: 'Some heading', tiles: null }} />,
        )
        expect(container.firstChild).toBeNull()
      },
    )

    it(
      'AC-2.11b: GIVEN MotifCrossSellBlock receives data: null ' +
        'WHEN it renders ' +
        'THEN it returns null',
      () => {
        const { container } = render(<MotifCrossSellBlock data={null} />)
        expect(container.firstChild).toBeNull()
      },
    )
  })

  describe('AC-2.12: MotifCrossSell tiles=[] -> null render', () => {
    it(
      'AC-2.12: GIVEN MotifCrossSellBlock receives tiles: [] ' +
        'WHEN it renders ' +
        'THEN it returns null (same behavior as null)',
      () => {
        const { container } = render(
          <MotifCrossSellBlock data={{ heading: 'Some heading', tiles: [] }} />,
        )
        expect(container.firstChild).toBeNull()
      },
    )
  })

  describe('AC-2.13: MotifCrossSell tiles render', () => {
    it(
      'AC-2.13a: GIVEN heading="Dieses Motiv auch auf …" AND 3 tiles ' +
        'WHEN MotifCrossSellBlock renders ' +
        'THEN the heading text renders as a section heading <h2>',
      () => {
        render(
          <MotifCrossSellBlock
            data={{ heading: 'Dieses Motiv auch auf …', tiles: THREE_TILES }}
          />,
        )
        const heading = screen.getByRole('heading', { level: 2 })
        expect(heading.textContent).toBe('Dieses Motiv auch auf …')
      },
    )

    it(
      'AC-2.13b: GIVEN 3 tiles ' +
        'WHEN MotifCrossSellBlock renders ' +
        'THEN exactly 3 tile <a> elements render',
      () => {
        const { container } = render(
          <MotifCrossSellBlock
            data={{ heading: 'Test', tiles: THREE_TILES }}
          />,
        )
        const links = container.querySelectorAll('a')
        expect(links.length).toBe(3)
      },
    )

    it(
      'AC-2.13c: GIVEN 3 tiles all with href starting with "/produkt/" ' +
        'WHEN MotifCrossSellBlock renders ' +
        'THEN each <a> href matches the item AND each href matches /^\\/produkt\\// pattern',
      () => {
        const { container } = render(
          <MotifCrossSellBlock
            data={{ heading: 'Test', tiles: THREE_TILES }}
          />,
        )
        const links = Array.from(container.querySelectorAll('a'))
        const hrefs = links.map((a) => a.getAttribute('href'))

        for (const expectedTile of THREE_TILES) {
          expect(hrefs).toContain(expectedTile.href)
        }
        for (const href of hrefs) {
          expect(href).toMatch(/^\/produkt\//)
        }
      },
    )

    it(
      'AC-2.13d: GIVEN 3 tiles ' +
        'WHEN MotifCrossSellBlock renders ' +
        'THEN each tile shows both productName and productType text',
      () => {
        render(
          <MotifCrossSellBlock
            data={{ heading: 'Test', tiles: THREE_TILES }}
          />,
        )
        // productName "Helgoland Print" appears 3x; assert at least 3 occurrences via getAllByText.
        const nameOccurrences = screen.getAllByText('Helgoland Print')
        expect(nameOccurrences.length).toBe(3)

        // Each productType appears once.
        expect(screen.getByText('Tasse')).toBeTruthy()
        expect(screen.getByText('Beutel')).toBeTruthy()
        expect(screen.getByText('Shirt')).toBeTruthy()
      },
    )
  })

  describe('Block-level safe-href guard (deliverable: filters non-/produkt/ hrefs)', () => {
    it(
      'GIVEN a tile with href NOT starting with "/produkt/" ' +
        'WHEN MotifCrossSellBlock renders ' +
        'THEN that tile is filtered out (its href is not in the DOM) ' +
        'AND the remaining /produkt/ tiles render normally',
      () => {
        const mixed = [
          ...THREE_TILES,
          {
            productName: 'External Bad',
            productType: 'Externe',
            image_url: '/x.jpg',
            href: 'https://external.com/x',
          },
          {
            productName: 'Anchor Bad',
            productType: 'Anker',
            image_url: '/y.jpg',
            href: '#section',
          },
          {
            productName: 'Kategorie Bad',
            productType: 'Cat',
            image_url: '/z.jpg',
            href: '/kategorie/foo',
          },
        ]
        const { container } = render(
          <MotifCrossSellBlock data={{ heading: 'Test', tiles: mixed }} />,
        )
        const links = Array.from(container.querySelectorAll('a'))
        // Only 3 valid /produkt/ tiles render.
        expect(links.length).toBe(3)
        const hrefs = links.map((a) => a.getAttribute('href'))
        expect(hrefs).not.toContain('https://external.com/x')
        expect(hrefs).not.toContain('#section')
        expect(hrefs).not.toContain('/kategorie/foo')
        // The "External Bad" / "Anchor Bad" / "Kategorie Bad" tile names are absent.
        expect(screen.queryByText('External Bad')).toBeNull()
        expect(screen.queryByText('Anchor Bad')).toBeNull()
        expect(screen.queryByText('Kategorie Bad')).toBeNull()
      },
    )

    it(
      'GIVEN every tile is non-/produkt/ ' +
        'WHEN MotifCrossSellBlock renders ' +
        'THEN it returns null (no tiles render)',
      () => {
        const allBad = [
          { productName: 'A', productType: 'X', image_url: '/a.jpg', href: 'https://x.com' },
          { productName: 'B', productType: 'Y', image_url: '/b.jpg', href: '/kategorie/y' },
        ]
        const { container } = render(
          <MotifCrossSellBlock data={{ heading: 'Test', tiles: allBad }} />,
        )
        expect(container.firstChild).toBeNull()
      },
    )

    it(
      'GIVEN heading is omitted ' +
        'WHEN MotifCrossSellBlock renders ' +
        'THEN the default heading "Dieses Motiv auch auf …" is used',
      () => {
        render(
          <MotifCrossSellBlock data={{ tiles: THREE_TILES }} />,
        )
        const heading = screen.getByRole('heading', { level: 2 })
        expect(heading.textContent).toBe('Dieses Motiv auch auf …')
      },
    )
  })
})
