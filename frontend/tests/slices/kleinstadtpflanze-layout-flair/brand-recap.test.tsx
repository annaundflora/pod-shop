// tests/slices/kleinstadtpflanze-layout-flair/brand-recap.test.tsx
//
// Slice-01: BrandRecapBlock
// Spec: specs/2026-04-26-kleinstadtpflanze-layout-flair/slim-spec.md (Slice 1)
//
// Acceptance Criteria covered:
//   AC-1.15: Brand-Recap render (border-y, surface-alt bg, env fallback for logoText, tagline)
//   AC-1.16: Brand-Recap logoText override (YAML logoText wins over env fallback)
//
// Strategy: Set process.env.NEXT_PUBLIC_SHOP_NAME directly. The component reads
// the env at module import time, so we re-import the module after setting the env.
// Using vi.resetModules() + dynamic import to ensure a fresh ENV_SHOP_NAME closure.

import React from 'react'
import { describe, it, expect, beforeEach, afterAll, vi } from 'vitest'
import { render, screen } from '@testing-library/react'

const ORIGINAL_SHOP_NAME = process.env.NEXT_PUBLIC_SHOP_NAME

describe('BrandRecapBlock — Slice 01', () => {
  beforeEach(() => {
    vi.resetModules()
  })

  afterAll(() => {
    if (ORIGINAL_SHOP_NAME === undefined) {
      delete process.env.NEXT_PUBLIC_SHOP_NAME
    } else {
      process.env.NEXT_PUBLIC_SHOP_NAME = ORIGINAL_SHOP_NAME
    }
  })

  describe('AC-1.15: Brand-Recap render with env fallback', () => {
    it(
      'AC-1.15a: GIVEN data { tagline, href:"/" } and logoText undefined ' +
        'AND process.env.NEXT_PUBLIC_SHOP_NAME = "Kleinstadtpflanze" ' +
        'WHEN BrandRecapBlock renders ' +
        'THEN <a href="/"> contains text "Kleinstadtpflanze" (env fallback)',
      async () => {
        process.env.NEXT_PUBLIC_SHOP_NAME = 'Kleinstadtpflanze'
        // Re-import module so the module-scope `ENV_SHOP_NAME` constant captures the new value.
        const mod = await import('@/components/blocks/brand-recap-block')
        const { BrandRecapBlock } = mod
        render(
          <BrandRecapBlock
            data={{
              tagline: 'Kleine Drucke aus Schleswig-Holstein.',
              href: '/',
            }}
          />,
        )
        const link = screen.getByRole('link', { name: 'Kleinstadtpflanze' })
        expect(link.getAttribute('href')).toBe('/')
      },
    )

    it(
      'AC-1.15b: GIVEN the same data ' +
        'WHEN BrandRecapBlock renders ' +
        'THEN the section has classes "border-y border-border bg-surface-alt"',
      async () => {
        process.env.NEXT_PUBLIC_SHOP_NAME = 'Kleinstadtpflanze'
        const mod = await import('@/components/blocks/brand-recap-block')
        const { BrandRecapBlock } = mod
        const { container } = render(
          <BrandRecapBlock
            data={{
              tagline: 'Kleine Drucke aus Schleswig-Holstein.',
              href: '/',
            }}
          />,
        )
        const section = container.querySelector('section')
        expect(section).not.toBeNull()
        const cls = section!.className
        expect(cls).toContain('border-y')
        expect(cls).toContain('border-border')
        expect(cls).toContain('bg-surface-alt')
      },
    )

    it(
      'AC-1.15c: GIVEN the same data ' +
        'WHEN BrandRecapBlock renders ' +
        'THEN the tagline text "Kleine Drucke aus Schleswig-Holstein." appears next to the logo',
      async () => {
        process.env.NEXT_PUBLIC_SHOP_NAME = 'Kleinstadtpflanze'
        const mod = await import('@/components/blocks/brand-recap-block')
        const { BrandRecapBlock } = mod
        render(
          <BrandRecapBlock
            data={{
              tagline: 'Kleine Drucke aus Schleswig-Holstein.',
              href: '/',
            }}
          />,
        )
        expect(screen.getByText('Kleine Drucke aus Schleswig-Holstein.')).toBeTruthy()
      },
    )
  })

  describe('AC-1.16: Brand-Recap logoText override', () => {
    it(
      'AC-1.16: GIVEN logoText: "MyShop" is provided in YAML data ' +
        'AND process.env.NEXT_PUBLIC_SHOP_NAME = "Kleinstadtpflanze" (different) ' +
        'WHEN BrandRecapBlock renders ' +
        'THEN the link text is "MyShop" (NOT "Kleinstadtpflanze")',
      async () => {
        process.env.NEXT_PUBLIC_SHOP_NAME = 'Kleinstadtpflanze'
        const mod = await import('@/components/blocks/brand-recap-block')
        const { BrandRecapBlock } = mod
        render(
          <BrandRecapBlock
            data={{
              logoText: 'MyShop',
              tagline: 'Mein Tagline.',
              href: '/',
            }}
          />,
        )
        // The override wins:
        expect(screen.getByRole('link', { name: 'MyShop' })).toBeTruthy()
        // Env-fallback must NOT appear as link text.
        expect(screen.queryByRole('link', { name: 'Kleinstadtpflanze' })).toBeNull()
      },
    )
  })
})
