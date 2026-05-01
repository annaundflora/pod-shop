// tests/slices/kleinstadtpflanze-layout-flair/editorial-mini.test.tsx
//
// Slice-01: EditorialMiniBlock
// Spec: specs/2026-04-26-kleinstadtpflanze-layout-flair/slim-spec.md (Slice 1)
//
// Acceptance Criteria covered:
//   AC-1.10: Editorial-Mini render (bg-surface-alt, rounded-card, shadow-card,
//            grid-cols, h2 with font-heading, CTA href, placeholder div with
//            bg-surface-elevated when image_url empty)
//   AC-1.11: Editorial-Mini optional CTA (no <a> when cta absent)

import React from 'react'
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { EditorialMiniBlock } from '@/components/blocks/editorial-mini-block'

describe('EditorialMiniBlock — Slice 01', () => {
  describe('AC-1.10: Editorial-Mini render', () => {
    const fullData = {
      tag: 'Über uns',
      headline: 'Was ist Kleinstadtpflanze?',
      text: 'Kleine Auswahl Stadt-Motive aus Schleswig-Holstein.',
      cta: { text: 'Alle Kategorien', href: '/' },
      image_url: '',
      image_alt: '',
    }

    it(
      'AC-1.10a: GIVEN full data with empty image_url ' +
        'WHEN EditorialMiniBlock renders ' +
        'THEN the section uses classes "bg-surface-alt rounded-card shadow-card" ' +
        'AND grid layout "grid grid-cols-1 md:grid-cols-2"',
      () => {
        const { container } = render(<EditorialMiniBlock data={fullData} />)
        const section = container.querySelector('section')
        expect(section, 'section element should exist').not.toBeNull()
        const cls = section!.className
        expect(cls).toContain('bg-surface-alt')
        expect(cls).toContain('rounded-card')
        expect(cls).toContain('shadow-card')
        expect(cls).toContain('grid')
        expect(cls).toContain('grid-cols-1')
        expect(cls).toContain('md:grid-cols-2')
      },
    )

    it(
      'AC-1.10b: GIVEN headline "Was ist Kleinstadtpflanze?" ' +
        'WHEN it renders ' +
        'THEN the headline appears as <h2> with classes font-heading and text-text-primary',
      () => {
        render(<EditorialMiniBlock data={fullData} />)
        const h2 = screen.getByRole('heading', { level: 2 })
        expect(h2.textContent).toBe('Was ist Kleinstadtpflanze?')
        expect(h2.className).toContain('font-heading')
        expect(h2.className).toContain('text-text-primary')
      },
    )

    it(
      'AC-1.10c: GIVEN cta = { text: "Alle Kategorien", href: "/" } ' +
        'WHEN it renders ' +
        'THEN an <a> element with href="/" and label "Alle Kategorien" appears',
      () => {
        render(<EditorialMiniBlock data={fullData} />)
        const link = screen.getByRole('link', { name: 'Alle Kategorien' })
        expect(link.getAttribute('href')).toBe('/')
      },
    )

    it(
      'AC-1.10d: GIVEN image_url is empty string ' +
        'WHEN EditorialMiniBlock renders ' +
        'THEN a placeholder <div> with class bg-surface-elevated is present in the left column ' +
        'AND no real <img> is rendered',
      () => {
        const { container } = render(<EditorialMiniBlock data={fullData} />)
        const placeholder = Array.from(container.querySelectorAll('div')).find((d) =>
          d.className.includes('bg-surface-elevated'),
        )
        expect(placeholder, 'placeholder div with bg-surface-elevated must exist').toBeDefined()
        const img = container.querySelector('img')
        expect(img, 'no real <img> when image_url is empty').toBeNull()
      },
    )
  })

  describe('AC-1.11: Editorial-Mini optional CTA', () => {
    it(
      'AC-1.11: GIVEN data without a `cta` field ' +
        'WHEN EditorialMiniBlock renders ' +
        'THEN no <a> link or Button-as-link element appears in the section',
      () => {
        const dataNoCta = {
          headline: 'Headline ohne CTA',
          text: 'Body text ohne CTA.',
          image_url: '',
        }
        const { container } = render(<EditorialMiniBlock data={dataNoCta} />)
        // No <a> element anywhere in the rendered section
        const link = container.querySelector('a')
        expect(link, 'no <a> should be rendered when cta is absent').toBeNull()
        // Headline should still be there
        expect(screen.getByRole('heading', { level: 2 }).textContent).toBe('Headline ohne CTA')
      },
    )
  })
})
