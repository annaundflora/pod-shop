// tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import React from 'react'

// -------------------------------------------------------------------
// Hilfsfunktionen (aus den Block-Komponenten re-exportiert)
// -------------------------------------------------------------------

describe('Slice 01 — Cross-Page Infrastruktur', () => {

  // ---------------------------------------------------------------
  // 1. Block Registry
  // ---------------------------------------------------------------
  describe('registry.ts — neue Blocks registriert', () => {
    it('should resolve announcement-bar block', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('announcement-bar')
      expect(component).not.toBeNull()
    })

    it('should resolve breadcrumb block', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('breadcrumb')
      expect(component).not.toBeNull()
    })

    it('should resolve trust-badges block', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('trust-badges')
      expect(component).not.toBeNull()
    })

    it('should resolve pagination block', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('pagination')
      expect(component).not.toBeNull()
    })

    it('should resolve sort-bar block', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('sort-bar')
      expect(component).not.toBeNull()
    })

    it('should resolve empty-state block', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('empty-state')
      expect(component).not.toBeNull()
    })
  })

  // ---------------------------------------------------------------
  // 2. AnnouncementBarBlock
  // ---------------------------------------------------------------
  describe('AnnouncementBarBlock', () => {
    beforeEach(() => {
      localStorage.clear()
    })

    it('should render announcement bar when not dismissed', async () => {
      const { AnnouncementBarBlock } = await import('@/components/blocks/announcement-bar-block')
      render(
        <AnnouncementBarBlock data={{
          id: 'test-bar-1',
          text: 'Kostenloser Versand ab 100€',
          dismissible: true,
        }} />
      )
      expect(screen.getByText('Kostenloser Versand ab 100€')).toBeInTheDocument()
    })

    it('should not render when already dismissed in localStorage', async () => {
      localStorage.setItem('announcement-dismissed-test-bar-1', '1')
      const { AnnouncementBarBlock } = await import('@/components/blocks/announcement-bar-block')
      render(
        <AnnouncementBarBlock data={{
          id: 'test-bar-1',
          text: 'Kostenloser Versand ab 100€',
          dismissible: true,
        }} />
      )
      expect(screen.queryByText('Kostenloser Versand ab 100€')).toBeNull()
    })

    it('should dismiss bar and set localStorage on X-button click', async () => {
      const { AnnouncementBarBlock } = await import('@/components/blocks/announcement-bar-block')
      render(
        <AnnouncementBarBlock data={{
          id: 'test-bar-2',
          text: 'Test Bar Text',
          dismissible: true,
        }} />
      )
      const closeButton = screen.getByRole('button', { name: /ankündigung schliessen/i })
      fireEvent.click(closeButton)
      expect(localStorage.getItem('announcement-dismissed-test-bar-2')).not.toBeNull()
      expect(screen.queryByText('Test Bar Text')).toBeNull()
    })

    it('should not show close button when dismissible is false', async () => {
      const { AnnouncementBarBlock } = await import('@/components/blocks/announcement-bar-block')
      render(
        <AnnouncementBarBlock data={{
          id: 'test-bar-3',
          text: 'Pflicht-Hinweis',
          dismissible: false,
        }} />
      )
      expect(screen.queryByRole('button', { name: /ankündigung schliessen/i })).toBeNull()
      expect(screen.getByText('Pflicht-Hinweis')).toBeInTheDocument()
    })
  })

  // ---------------------------------------------------------------
  // 3. BreadcrumbBlock
  // ---------------------------------------------------------------
  describe('BreadcrumbBlock', () => {
    it('should render breadcrumb with correct links', async () => {
      const { BreadcrumbBlock } = await import('@/components/blocks/breadcrumb-block')
      render(
        <BreadcrumbBlock data={{
          items: [
            { label: 'Home', href: '/' },
            { label: 'T-Shirts' },
          ],
        }} />
      )
      const nav = screen.getByRole('navigation', { name: /breadcrumb/i })
      expect(nav).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Home' })).toHaveAttribute('href', '/')
      expect(screen.getByText('T-Shirts')).toBeInTheDocument()
    })

    it('should set aria-current="page" on last breadcrumb item', async () => {
      const { BreadcrumbBlock } = await import('@/components/blocks/breadcrumb-block')
      render(
        <BreadcrumbBlock data={{
          items: [
            { label: 'Home', href: '/' },
            { label: 'T-Shirts' },
          ],
        }} />
      )
      const currentItem = screen.getByText('T-Shirts').closest('[aria-current="page"]')
      expect(currentItem).not.toBeNull()
    })

    it('should return null for empty items', async () => {
      const { BreadcrumbBlock } = await import('@/components/blocks/breadcrumb-block')
      const { container } = render(<BreadcrumbBlock data={{ items: [] }} />)
      expect(container.firstChild).toBeNull()
    })
  })

  // ---------------------------------------------------------------
  // 4. TrustBadgesBlock
  // ---------------------------------------------------------------
  describe('TrustBadgesBlock', () => {
    it('should render all badge items', async () => {
      const { TrustBadgesBlock } = await import('@/components/blocks/trust-badges-block')
      render(
        <TrustBadgesBlock data={{
          items: [
            { icon: 'truck', text: 'Versand in 3-5 Tagen' },
            { icon: 'refresh', text: '30 Tage Rückgabe' },
            { icon: 'lock', text: 'Sichere Zahlung' },
          ],
        }} />
      )
      expect(screen.getByText('Versand in 3-5 Tagen')).toBeInTheDocument()
      expect(screen.getByText('30 Tage Rückgabe')).toBeInTheDocument()
      expect(screen.getByText('Sichere Zahlung')).toBeInTheDocument()
    })

    it('should return null for empty items', async () => {
      const { TrustBadgesBlock } = await import('@/components/blocks/trust-badges-block')
      const { container } = render(<TrustBadgesBlock data={{ items: [] }} />)
      expect(container.firstChild).toBeNull()
    })
  })

  // ---------------------------------------------------------------
  // 5. PaginationBlock
  // ---------------------------------------------------------------
  describe('PaginationBlock', () => {
    it('should render pagination with correct page links', async () => {
      const { PaginationBlock } = await import('@/components/blocks/pagination-block')
      render(
        <PaginationBlock data={{
          currentPage: 2,
          totalPages: 5,
          baseUrl: '/kategorie/t-shirts',
        }} />
      )
      const nav = screen.getByRole('navigation', { name: /seitennavigation/i })
      expect(nav).toBeInTheDocument()
      // Seite 2 ist aktiv
      const activePage = screen.getByText('2').closest('[aria-current="page"]')
      expect(activePage).not.toBeNull()
    })

    it('should return null when totalPages is 1', async () => {
      const { PaginationBlock } = await import('@/components/blocks/pagination-block')
      const { container } = render(
        <PaginationBlock data={{ currentPage: 1, totalPages: 1, baseUrl: '/kategorie/t-shirts' }} />
      )
      expect(container.firstChild).toBeNull()
    })

    it('should return null when totalPages is 0', async () => {
      const { PaginationBlock } = await import('@/components/blocks/pagination-block')
      const { container } = render(
        <PaginationBlock data={{ currentPage: 1, totalPages: 0, baseUrl: '/kategorie/t-shirts' }} />
      )
      expect(container.firstChild).toBeNull()
    })

    it('should disable prev button on first page', async () => {
      const { PaginationBlock } = await import('@/components/blocks/pagination-block')
      render(
        <PaginationBlock data={{ currentPage: 1, totalPages: 3, baseUrl: '/suche' }} />
      )
      const prevLink = screen.getByRole('link', { name: /vorige seite/i })
      expect(prevLink).toHaveAttribute('aria-disabled', 'true')
    })

    it('should disable next button on last page', async () => {
      const { PaginationBlock } = await import('@/components/blocks/pagination-block')
      render(
        <PaginationBlock data={{ currentPage: 3, totalPages: 3, baseUrl: '/suche' }} />
      )
      const nextLink = screen.getByRole('link', { name: /nächste seite/i })
      expect(nextLink).toHaveAttribute('aria-disabled', 'true')
    })

    it('should build correct page URLs including sort param', async () => {
      const { PaginationBlock } = await import('@/components/blocks/pagination-block')
      render(
        <PaginationBlock data={{
          currentPage: 1,
          totalPages: 3,
          baseUrl: '/kategorie/t-shirts',
          currentSort: 'price_asc',
        }} />
      )
      const page2Link = screen.getByRole('link', { name: '2' })
      expect(page2Link).toHaveAttribute('href', '/kategorie/t-shirts?page=2&sort=price_asc')
    })
  })

  // ---------------------------------------------------------------
  // 6. SortBarBlock
  // ---------------------------------------------------------------
  describe('SortBarBlock', () => {
    it('should render sort dropdown with all options', async () => {
      const { SortBarBlock } = await import('@/components/blocks/sort-bar-block')
      // Mock useRouter — next/navigation wird per vi.mock gemockt (mock_external Strategie)
      vi.mock('next/navigation', () => ({
        useRouter: () => ({ push: vi.fn() }),
        useSearchParams: () => new URLSearchParams(),
      }))
      render(
        <SortBarBlock data={{ currentSort: 'default', baseUrl: '/kategorie/t-shirts' }} />
      )
      const select = screen.getByRole('combobox', { name: /produkte sortieren/i })
      expect(select).toBeInTheDocument()
      expect(screen.getByText('Empfohlen')).toBeInTheDocument()
      expect(screen.getByText('Preis: aufsteigend')).toBeInTheDocument()
      expect(screen.getByText('Preis: absteigend')).toBeInTheDocument()
      expect(screen.getByText('Neueste zuerst')).toBeInTheDocument()
    })

    it('should show correct selected option for currentSort', async () => {
      const { SortBarBlock } = await import('@/components/blocks/sort-bar-block')
      vi.mock('next/navigation', () => ({
        useRouter: () => ({ push: vi.fn() }),
        useSearchParams: () => new URLSearchParams('sort=price_desc'),
      }))
      render(
        <SortBarBlock data={{ currentSort: 'price_desc', baseUrl: '/suche' }} />
      )
      const select = screen.getByRole('combobox') as HTMLSelectElement
      expect(select.value).toBe('price_desc')
    })

    it('should show "Empfohlen" as selected when currentSort is default', async () => {
      const { SortBarBlock } = await import('@/components/blocks/sort-bar-block')
      vi.mock('next/navigation', () => ({
        useRouter: () => ({ push: vi.fn() }),
        useSearchParams: () => new URLSearchParams(),
      }))
      render(
        <SortBarBlock data={{ currentSort: 'default', baseUrl: '/kategorie/t-shirts' }} />
      )
      const select = screen.getByRole('combobox') as HTMLSelectElement
      expect(select.value).toBe('default')
    })
  })

  // ---------------------------------------------------------------
  // 7. EmptyStateBlock
  // ---------------------------------------------------------------
  describe('EmptyStateBlock', () => {
    it('should render headline, text and suggestion links', async () => {
      const { EmptyStateBlock } = await import('@/components/blocks/empty-state-block')
      render(
        <EmptyStateBlock data={{
          headline: 'Keine Produkte gefunden',
          text: 'Versuche eine andere Kategorie',
          links: [
            { label: 'T-Shirts', href: '/kategorie/t-shirts' },
            { label: 'Hoodies', href: '/kategorie/hoodies' },
          ],
        }} />
      )
      expect(screen.getByRole('heading', { name: 'Keine Produkte gefunden' })).toBeInTheDocument()
      expect(screen.getByText('Versuche eine andere Kategorie')).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'T-Shirts' })).toHaveAttribute('href', '/kategorie/t-shirts')
      expect(screen.getByRole('link', { name: 'Hoodies' })).toHaveAttribute('href', '/kategorie/hoodies')
    })

    it('should render without links when links array is empty', async () => {
      const { EmptyStateBlock } = await import('@/components/blocks/empty-state-block')
      render(
        <EmptyStateBlock data={{
          headline: 'Leer',
          text: 'Nichts hier',
        }} />
      )
      expect(screen.getByRole('heading', { name: 'Leer' })).toBeInTheDocument()
      expect(screen.queryAllByRole('link')).toHaveLength(0)
    })
  })

  // ---------------------------------------------------------------
  // 8. loadGlobalConfig
  // ---------------------------------------------------------------
  describe('loadGlobalConfig', () => {
    it('should return a valid PageConfig from global.yaml', async () => {
      const { loadGlobalConfig } = await import('@/lib/blocks/page-config')
      const config = loadGlobalConfig('default')
      expect(config).toHaveProperty('sections')
      expect(Array.isArray(config.sections)).toBe(true)
    })

    it('should contain announcement-bar block in default global config', async () => {
      const { loadGlobalConfig } = await import('@/lib/blocks/page-config')
      const config = loadGlobalConfig('default')
      const allBlocks = config.sections.flatMap(s => s.blocks)
      const announcementBar = allBlocks.find(b => b.type === 'announcement-bar')
      expect(announcementBar).toBeDefined()
    })
  })

})
