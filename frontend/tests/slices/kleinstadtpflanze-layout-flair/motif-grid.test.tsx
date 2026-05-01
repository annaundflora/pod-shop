// tests/slices/kleinstadtpflanze-layout-flair/motif-grid.test.tsx
//
// Slice-01: MotifGridBlock
// Spec: specs/2026-04-26-kleinstadtpflanze-layout-flair/slim-spec.md (Slice 1)
//
// Acceptance Criteria covered:
//   AC-1.12: Motif-Grid tile rendering (6 tiles, each <a href>, grid classes,
//            Card variant="interactive" recipe)
//   AC-1.13: Motif-Grid href guard (skip items where href doesn't start with "/")
//   AC-1.14: Motif-Grid empty graceful (returns null for empty/null data)

import React from 'react'
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MotifGridBlock } from '@/components/blocks/motif-grid-block'

const SIX_INTERNAL_ITEMS = [
  { name: 'Helgoland', image_url: '/motifs/helgoland.jpg', href: '/suche?q=helgoland' },
  { name: 'Husum', image_url: '/motifs/husum.jpg', href: '/suche?q=husum' },
  { name: 'Glückstadt', image_url: '/motifs/glueckstadt.jpg', href: '/suche?q=glueckstadt' },
  { name: 'Eckernförde', image_url: '/motifs/eckernfoerde.jpg', href: '/suche?q=eckernfoerde' },
  { name: 'Friedrichstadt', image_url: '/motifs/friedrichstadt.jpg', href: '/suche?q=friedrichstadt' },
  { name: 'Tönning', image_url: '/motifs/toenning.jpg', href: '/suche?q=toenning' },
]

describe('MotifGridBlock — Slice 01', () => {
  describe('AC-1.12: Motif-Grid tile rendering', () => {
    it(
      'AC-1.12a: GIVEN items with 6 internal-href entries ' +
        'WHEN MotifGridBlock renders ' +
        'THEN exactly 6 <a> tiles render AND each <a> has href matching the item',
      () => {
        const { container } = render(
          <MotifGridBlock data={{ items: SIX_INTERNAL_ITEMS }} />,
        )
        // Each tile is wrapped in an <a> via <Link> with the item href.
        const links = container.querySelectorAll('a')
        expect(links.length).toBe(6)

        const expectedHrefs = SIX_INTERNAL_ITEMS.map((i) => i.href)
        const actualHrefs = Array.from(links).map((a) => a.getAttribute('href'))
        for (const href of expectedHrefs) {
          expect(actualHrefs).toContain(href)
        }
      },
    )

    it(
      'AC-1.12b: GIVEN items with 6 entries ' +
        'WHEN it renders ' +
        'THEN the grid container has classes "grid grid-cols-2 lg:grid-cols-3 gap-4"',
      () => {
        const { container } = render(
          <MotifGridBlock data={{ items: SIX_INTERNAL_ITEMS }} />,
        )
        // Find the inner grid container — it has grid-cols-2.
        const grid = Array.from(container.querySelectorAll('div')).find((d) =>
          d.className.includes('grid-cols-2'),
        )
        expect(grid, 'grid container must exist').toBeDefined()
        const cls = grid!.className
        expect(cls).toContain('grid')
        expect(cls).toContain('grid-cols-2')
        expect(cls).toContain('lg:grid-cols-3')
        expect(cls).toContain('gap-4')
      },
    )

    it(
      'AC-1.12c: GIVEN items with 6 entries ' +
        'WHEN it renders ' +
        'THEN tile <a>s use the Card variant="interactive" recipe — ' +
        '"cursor-pointer", "hover:-translate-y-0.5", and "rounded-[var(--radius-card,0.75rem)]" ' +
        'are applied (matches category-showcase shape).',
      () => {
        const { container } = render(
          <MotifGridBlock data={{ items: SIX_INTERNAL_ITEMS }} />,
        )
        const links = Array.from(container.querySelectorAll('a'))
        expect(links.length).toBe(6)

        // The Card asChild merges its variant classes onto the Link.
        for (const a of links) {
          const cls = a.className
          expect(cls, `link ${a.getAttribute('href')}: missing cursor-pointer`).toContain(
            'cursor-pointer',
          )
          expect(cls, `link: missing hover:-translate-y-0.5`).toContain('hover:-translate-y-0.5')
          // The Card recipe applies the radius var class.
          expect(cls).toContain('rounded-[var(--radius-card,0.75rem)]')
        }
      },
    )

    it(
      'AC-1.12d: GIVEN headline is provided ' +
        'WHEN it renders ' +
        'THEN <h2> text matches the headline',
      () => {
        render(
          <MotifGridBlock
            data={{ headline: 'Motive entdecken', items: SIX_INTERNAL_ITEMS }}
          />,
        )
        const h2 = screen.getByRole('heading', { level: 2 })
        expect(h2.textContent).toBe('Motive entdecken')
      },
    )
  })

  describe('AC-1.13: Motif-Grid href guard (internal-only)', () => {
    it(
      'AC-1.13: GIVEN one item has href "https://external.com" (does not start with "/") ' +
        'WHEN MotifGridBlock renders ' +
        'THEN that item is skipped (its name and href are NOT in the DOM) ' +
        'AND remaining internal-href items render as expected',
      () => {
        const mixed = [
          { name: 'Internal A', image_url: '/img/a.jpg', href: '/categories/a' },
          { name: 'External-Bad', image_url: '/img/x.jpg', href: 'https://external.com/x' },
          { name: 'Internal B', image_url: '/img/b.jpg', href: '/categories/b' },
        ]
        const { container } = render(<MotifGridBlock data={{ items: mixed }} />)

        const links = container.querySelectorAll('a')
        expect(links.length).toBe(2)

        const hrefs = Array.from(links).map((a) => a.getAttribute('href'))
        expect(hrefs).toContain('/categories/a')
        expect(hrefs).toContain('/categories/b')
        expect(hrefs).not.toContain('https://external.com/x')

        // External item's name is not in the DOM
        expect(screen.queryByText('External-Bad')).toBeNull()
        expect(screen.getByText('Internal A')).toBeTruthy()
        expect(screen.getByText('Internal B')).toBeTruthy()
      },
    )

    it(
      'AC-1.13b: GIVEN items where every href is non-internal (protocol-relative, anchor, query-only) ' +
        'WHEN MotifGridBlock renders ' +
        'THEN it returns null (no tiles rendered)',
      () => {
        const allBad = [
          { name: 'A', image_url: '/a.jpg', href: 'https://x.com' },
          { name: 'B', image_url: '/b.jpg', href: '#anchor' },
          { name: 'C', image_url: '/c.jpg', href: '?query=1' },
        ]
        const { container } = render(<MotifGridBlock data={{ items: allBad }} />)
        expect(container.firstChild).toBeNull()
      },
    )
  })

  describe('AC-1.14: Motif-Grid empty graceful', () => {
    it(
      'AC-1.14a: GIVEN items: [] ' +
        'WHEN MotifGridBlock renders ' +
        'THEN it returns null',
      () => {
        const { container } = render(<MotifGridBlock data={{ items: [] }} />)
        expect(container.firstChild).toBeNull()
      },
    )

    it(
      'AC-1.14b: GIVEN data: null ' +
        'WHEN MotifGridBlock renders ' +
        'THEN it returns null',
      () => {
        const { container } = render(<MotifGridBlock data={null} />)
        expect(container.firstChild).toBeNull()
      },
    )
  })
})
