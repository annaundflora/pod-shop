// tests/slices/kleinstadtpflanze-layout-flair/service-box.test.tsx
//
// Slice-02: ServiceBoxBlock — new server block with local ICON_MAP
// Spec: specs/2026-04-26-kleinstadtpflanze-layout-flair/slim-spec.md (Slice 2)
//
// Acceptance Criteria covered:
//   AC-2.8:  ServiceBox render (bg-surface-alt rounded-card shadow-card container; per item:
//            icon SVG + <strong> label + <p class="text-sm text-text-secondary"> detail; icon class text-primary)
//   AC-2.9:  ServiceBox unknown icon graceful (icon="nonexistent" -> no SVG, no crash, label/detail still render)
//   AC-2.10: ServiceBox empty graceful (items=[] OR data=null -> returns null)
//
// Strategy: real React render via @testing-library/react. lucide-react icons render
// real <svg class="lucide-coffee"> etc. — assertable via .lucide-{name} classes.

import React from 'react'
import { describe, it, expect, vi, afterEach } from 'vitest'
import { render } from '@testing-library/react'
import { ServiceBoxBlock } from '@/components/blocks/service-box-block'

const TWO_ITEMS = [
  { icon: 'coffee', label: 'Spülmaschinenfest', detail: 'Bis 60 °C' },
  { icon: 'ruler', label: '330 ml', detail: 'Standard-Henkel' },
]

describe('Slice 02 — ServiceBoxBlock', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  describe('AC-2.8: ServiceBox render', () => {
    it(
      'AC-2.8a: GIVEN items=[{icon:"coffee", label:"Spülmaschinenfest", detail:"Bis 60 °C"}, ' +
        '{icon:"ruler", label:"330 ml", detail:"Standard-Henkel"}] ' +
        'WHEN ServiceBoxBlock renders ' +
        'THEN the container uses classes "bg-surface-alt rounded-card shadow-card"',
      () => {
        const { container } = render(
          <ServiceBoxBlock data={{ items: TWO_ITEMS }} />,
        )
        const section = container.querySelector('section') as HTMLElement
        expect(section, 'section element must exist').toBeTruthy()
        const cls = section.className
        expect(cls).toContain('bg-surface-alt')
        expect(cls).toContain('rounded-card')
        expect(cls).toContain('shadow-card')
      },
    )

    it(
      'AC-2.8b: GIVEN 2 items with valid icons ' +
        'WHEN ServiceBoxBlock renders ' +
        'THEN each item has a Lucide SVG with class "text-primary" ' +
        '(icon coffee -> svg.lucide-coffee; icon ruler -> svg.lucide-ruler)',
      () => {
        const { container } = render(
          <ServiceBoxBlock data={{ items: TWO_ITEMS }} />,
        )
        const coffeeSvg = container.querySelector('svg.lucide-coffee')
        expect(coffeeSvg, 'Lucide coffee icon must render').toBeTruthy()
        expect((coffeeSvg as SVGElement).getAttribute('class')).toContain('text-primary')

        const rulerSvg = container.querySelector('svg.lucide-ruler')
        expect(rulerSvg, 'Lucide ruler icon must render').toBeTruthy()
        expect((rulerSvg as SVGElement).getAttribute('class')).toContain('text-primary')
      },
    )

    it(
      'AC-2.8c: GIVEN 2 items ' +
        'WHEN ServiceBoxBlock renders ' +
        'THEN each item renders <strong class="text-text-primary"> with the label text',
      () => {
        const { container } = render(
          <ServiceBoxBlock data={{ items: TWO_ITEMS }} />,
        )
        const strongs = container.querySelectorAll('strong')
        expect(strongs.length).toBe(2)
        for (const s of strongs) {
          expect(s.className).toContain('text-text-primary')
        }
        const texts = Array.from(strongs).map((s) => s.textContent)
        expect(texts).toContain('Spülmaschinenfest')
        expect(texts).toContain('330 ml')
      },
    )

    it(
      'AC-2.8d: GIVEN 2 items ' +
        'WHEN ServiceBoxBlock renders ' +
        'THEN each item renders <p class="text-sm text-text-secondary"> with the detail text',
      () => {
        const { container } = render(
          <ServiceBoxBlock data={{ items: TWO_ITEMS }} />,
        )
        const ps = container.querySelectorAll('p')
        expect(ps.length).toBe(2)
        for (const p of ps) {
          expect(p.className).toContain('text-sm')
          expect(p.className).toContain('text-text-secondary')
        }
        const texts = Array.from(ps).map((p) => p.textContent)
        expect(texts).toContain('Bis 60 °C')
        expect(texts).toContain('Standard-Henkel')
      },
    )

    it(
      'AC-2.8e: GIVEN 2 items ' +
        'WHEN ServiceBoxBlock renders ' +
        'THEN exactly 2 <li> rows render in the <ul>',
      () => {
        const { container } = render(
          <ServiceBoxBlock data={{ items: TWO_ITEMS }} />,
        )
        const ul = container.querySelector('ul') as HTMLElement
        expect(ul).toBeTruthy()
        const lis = ul.querySelectorAll('li')
        expect(lis.length).toBe(2)
      },
    )
  })

  describe('AC-2.9: ServiceBox unknown icon graceful', () => {
    it(
      'AC-2.9: GIVEN one item has icon: "nonexistent" ' +
        'WHEN ServiceBoxBlock renders ' +
        'THEN no SVG renders for that item AND no console error AND label + detail still render',
      () => {
        const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
        const items = [
          { icon: 'nonexistent', label: 'Mystery Feature', detail: 'Some detail' },
          { icon: 'coffee', label: 'Real Feature', detail: 'Real detail' },
        ]
        const { container } = render(<ServiceBoxBlock data={{ items }} />)

        // Two <li> rows (item with unknown icon still renders).
        const lis = container.querySelectorAll('li')
        expect(lis.length).toBe(2)

        // First item (unknown icon): no SVG within its <li>.
        const firstLi = lis[0]
        const firstSvg = firstLi.querySelector('svg')
        expect(firstSvg, 'unknown icon -> no SVG').toBeNull()

        // First item: label + detail still render.
        expect(firstLi.querySelector('strong')?.textContent).toBe('Mystery Feature')
        expect(firstLi.querySelector('p')?.textContent).toBe('Some detail')

        // Second item: SVG present.
        const secondLi = lis[1]
        expect(secondLi.querySelector('svg.lucide-coffee')).toBeTruthy()

        // No console.error from React or the component.
        expect(errorSpy).not.toHaveBeenCalled()
      },
    )
  })

  describe('AC-2.10: ServiceBox empty graceful', () => {
    it(
      'AC-2.10a: GIVEN items: [] ' +
        'WHEN ServiceBoxBlock renders ' +
        'THEN it returns null (no DOM nodes)',
      () => {
        const { container } = render(<ServiceBoxBlock data={{ items: [] }} />)
        expect(container.firstChild).toBeNull()
      },
    )

    it(
      'AC-2.10b: GIVEN data: null ' +
        'WHEN ServiceBoxBlock renders ' +
        'THEN it returns null',
      () => {
        const { container } = render(<ServiceBoxBlock data={null} />)
        expect(container.firstChild).toBeNull()
      },
    )
  })
})
