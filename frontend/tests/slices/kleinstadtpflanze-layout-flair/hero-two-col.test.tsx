// tests/slices/kleinstadtpflanze-layout-flair/hero-two-col.test.tsx
//
// Slice-01: Homepage-Stack — HeroBlock two-col variant + default backward-compat
// Spec: specs/2026-04-26-kleinstadtpflanze-layout-flair/slim-spec.md (Slice 1)
//
// Acceptance Criteria covered:
//   AC-1.1: Hero default backward-compat (variant undefined → single-column path)
//   AC-1.2: Hero two-col render (grid classes, tag, h1, subline, primary+ghost CTA, trust-row, SVG placeholder)
//   AC-1.3: Hero trust-row cap (slice(0,3) when 5 items provided)
//
// Strategy: real React render via @testing-library/react. No mocks for component logic.
// next/image and next/link are real (jsdom-safe).

import React from 'react'
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { HeroBlock } from '@/components/blocks/hero-block'

describe('HeroBlock — Slice 01 Layout-Flair', () => {
  describe('AC-1.1: Hero default backward-compat', () => {
    it(
      'AC-1.1: GIVEN data.variant is undefined ' +
        'WHEN HeroBlock renders ' +
        'THEN the existing single-column hero (heading id="hero-heading", ' +
        'rounded-2xl container) renders unchanged AND no two-col grid classes appear',
      () => {
        const data = {
          heroHeadline: 'Default Hero Headline',
          heroSubline: 'Default subline copy.',
          heroCtaText: 'Jetzt shoppen',
          heroCtaLink: '/kategorie/t-shirts',
          // variant intentionally undefined
        }
        const { container } = render(<HeroBlock data={data} />)

        const section = container.querySelector('section')
        expect(section, 'section element should exist').not.toBeNull()
        // Existing single-column path uses rounded-2xl container.
        expect(section?.className).toContain('rounded-2xl')
        // Two-col grid template MUST NOT be present in default path.
        expect(section?.className).not.toContain('md:grid-cols-[1.1fr_1fr]')

        // Default path renders the existing heading content.
        const heading = screen.getByRole('heading', { level: 1 })
        expect(heading.textContent).toBe('Default Hero Headline')

        // None of the new fields (tag, ctaSecondary, trustItems) should affect output.
        expect(container.querySelector('ul')).toBeNull()
      },
    )

    it(
      'AC-1.1b: GIVEN data is null (no WP fields available) ' +
        'WHEN HeroBlock renders ' +
        'THEN the default fallback hero renders without crashing',
      () => {
        const { container } = render(<HeroBlock data={null} />)
        const section = container.querySelector('section')
        expect(section).not.toBeNull()
        expect(section?.className).not.toContain('md:grid-cols-[1.1fr_1fr]')
      },
    )
  })

  describe('AC-1.2: Hero two-col render', () => {
    const fullTwoColData = {
      variant: 'two-col' as const,
      tag: 'Aus Schleswig-Holstein',
      heroHeadline: 'Stadt-Motive für jeden Tag',
      heroSubline: 'Kleine Drucke, persönlich gewählt.',
      heroCtaText: 'Kollektion ansehen',
      heroCtaLink: '/kategorie/tassen',
      ctaSecondary: { text: 'Was ist Kleinstadtpflanze?', href: '#editorial-mini-heading' },
      trustItems: [
        { icon: 'truck', text: 'Versand DE 3–5 Werktage' },
        { icon: 'refresh', text: '30 Tage Rückgabe' },
        { icon: 'lock', text: 'Sichere Zahlung' },
      ],
      visual: { imageUrl: '', alt: '' },
    }

    it(
      'AC-1.2a: GIVEN variant="two-col" with all fields populated ' +
        'WHEN HeroBlock renders ' +
        'THEN the outer container has classes "grid grid-cols-1 md:grid-cols-[1.1fr_1fr]"',
      () => {
        const { container } = render(<HeroBlock data={fullTwoColData} />)
        const section = container.querySelector('section')
        expect(section).not.toBeNull()
        const cls = section!.className
        expect(cls).toContain('grid')
        expect(cls).toContain('grid-cols-1')
        expect(cls).toContain('md:grid-cols-[1.1fr_1fr]')
      },
    )

    it(
      'AC-1.2b: GIVEN variant="two-col" with tag, headline, subline, primary CTA, ghost CTA ' +
        'WHEN HeroBlock renders ' +
        'THEN tag text, h1 text, subline text, primary CTA link, and ghost CTA link all appear',
      () => {
        render(<HeroBlock data={fullTwoColData} />)
        // Tag (pill)
        expect(screen.getByText('Aus Schleswig-Holstein')).toBeTruthy()
        // h1 headline
        const h1 = screen.getByRole('heading', { level: 1 })
        expect(h1.textContent).toBe('Stadt-Motive für jeden Tag')
        // Subline
        expect(screen.getByText('Kleine Drucke, persönlich gewählt.')).toBeTruthy()

        // Primary CTA
        const primaryLink = screen.getByRole('link', { name: 'Kollektion ansehen' })
        expect(primaryLink.getAttribute('href')).toBe('/kategorie/tassen')

        // Ghost / secondary CTA
        const ghostLink = screen.getByRole('link', { name: 'Was ist Kleinstadtpflanze?' })
        expect(ghostLink.getAttribute('href')).toBe('#editorial-mini-heading')
      },
    )

    it(
      'AC-1.2c: GIVEN variant="two-col" with trustItems = 3 ' +
        'WHEN HeroBlock renders ' +
        'THEN a <ul> with exactly 3 <li> items renders',
      () => {
        const { container } = render(<HeroBlock data={fullTwoColData} />)
        const ul = container.querySelector('ul')
        expect(ul, 'trust-row <ul> must exist').not.toBeNull()
        const items = ul!.querySelectorAll('li')
        expect(items.length).toBe(3)
        // Check trust labels are present
        expect(screen.getByText('Versand DE 3–5 Werktage')).toBeTruthy()
        expect(screen.getByText('30 Tage Rückgabe')).toBeTruthy()
        expect(screen.getByText('Sichere Zahlung')).toBeTruthy()
      },
    )

    it(
      'AC-1.2d: GIVEN variant="two-col" with visual.imageUrl="" (empty) ' +
        'WHEN HeroBlock renders ' +
        'THEN an SVG placeholder renders in the right column ' +
        'AND the visual container has bg-surface-alt class',
      () => {
        const { container } = render(<HeroBlock data={fullTwoColData} />)
        // No real <img> rendered for placeholder
        const realImage = container.querySelector('img')
        // Note: next/image may produce <img> only when src is set; with empty url we expect SVG path.
        const svg = container.querySelector('svg')
        expect(svg, 'SVG placeholder must render when imageUrl is empty').not.toBeNull()
        expect(realImage).toBeNull()

        // Right column container with bg-surface-alt
        const surfaceAltDiv = Array.from(container.querySelectorAll('div')).find(
          (d) => d.className.includes('bg-surface-alt'),
        )
        expect(surfaceAltDiv, 'div with bg-surface-alt should host placeholder').toBeDefined()
      },
    )
  })

  describe('AC-1.3: Hero trust-row cap (slice(0,3))', () => {
    it(
      'AC-1.3: GIVEN trustItems array with 5 entries ' +
        'WHEN HeroBlock renders the two-col variant ' +
        'THEN exactly 3 trust-row <li> items appear in the DOM',
      () => {
        const data = {
          variant: 'two-col' as const,
          heroHeadline: 'Five trust items provided',
          heroCtaText: 'CTA',
          heroCtaLink: '/',
          trustItems: [
            { icon: 'truck', text: 'Item 1' },
            { icon: 'refresh', text: 'Item 2' },
            { icon: 'lock', text: 'Item 3' },
            { icon: 'star', text: 'Item 4' },
            { icon: 'shield', text: 'Item 5' },
          ],
          visual: { imageUrl: '', alt: '' },
        }
        const { container } = render(<HeroBlock data={data} />)
        const items = container.querySelectorAll('ul > li')
        expect(items.length).toBe(3)

        // First three items render (slice(0,3) keeps the leading three).
        expect(screen.getByText('Item 1')).toBeTruthy()
        expect(screen.getByText('Item 2')).toBeTruthy()
        expect(screen.getByText('Item 3')).toBeTruthy()

        // Items 4 and 5 must NOT be in the DOM.
        expect(screen.queryByText('Item 4')).toBeNull()
        expect(screen.queryByText('Item 5')).toBeNull()
      },
    )
  })
})
