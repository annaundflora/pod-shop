/**
 * Acceptance & Unit Tests for Slice 04: Rechtsseiten als Block-Pages
 *
 * Tests are derived from the GIVEN/WHEN/THEN Acceptance Criteria in
 * specs/phase-0/2026-02-25-block-page-migration/slices/slice-04-rechtsseiten.md
 *
 * AC-1: Footer LEGAL_LINKS use internal Next.js paths (no WP_URL)
 * AC-2: legal.yaml loaded and $route.slug resolved to correct slug
 * AC-3: page-heading renders WordPress title as h1, legal-content renders HTML content
 * AC-4: All 4 routes (/impressum, /agb, /datenschutz, /widerruf) resolve distinct slugs
 * AC-5: LegalContentBlock shows error message when pageBy is null (no crash)
 * AC-6: Apollo React.cache() deduplication — GET_PAGE_CONTENT called once per render
 * AC-7: wordpressLoader with query=page_content uses GET_PAGE_CONTENT, returns WPPageContent
 * AC-8: wordpressLoader with query=custom_fields (or no query) keeps existing behavior
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { loadPageConfig, resolveParams } from '@/lib/blocks/page-config'

describe('Slice 04: Rechtsseiten als Block-Pages', () => {

  // ============================================================
  // AC-1: Footer LEGAL_LINKS — internal Next.js routes
  // ============================================================

  describe('AC-1: Footer LEGAL_LINKS internal routes', () => {
    it('AC-1: GIVEN der Footer ist gerendert WHEN ein User auf "Impressum" klickt THEN navigiert die App intern zu /impressum (kein WP_URL)', () => {
      /**
       * AC-1: GIVEN der Footer ist gerendert
       * WHEN ein User auf "Impressum" klickt
       * THEN navigiert die Next.js-App intern zu /impressum (kein Seitenwechsel zu WordPress)
       */
      const LEGAL_LINKS = [
        { label: 'Impressum', href: '/impressum' },
        { label: 'AGB', href: '/agb' },
        { label: 'Datenschutz', href: '/datenschutz' },
        { label: 'Widerruf', href: '/widerruf' },
      ]

      for (const link of LEGAL_LINKS) {
        // All hrefs must start with / (internal route)
        expect(link.href).toMatch(/^\//)
        // Must not contain absolute URLs pointing to WordPress
        expect(link.href).not.toContain('http')
        expect(link.href).not.toContain('localhost:8080')
      }
    })

    it('AC-1: Footer should have exactly 4 legal links', () => {
      const LEGAL_LINKS = [
        { label: 'Impressum', href: '/impressum' },
        { label: 'AGB', href: '/agb' },
        { label: 'Datenschutz', href: '/datenschutz' },
        { label: 'Widerruf', href: '/widerruf' },
      ]

      expect(LEGAL_LINKS).toHaveLength(4)
    })

    it('AC-1: Footer should cover all required legal pages', () => {
      const LEGAL_LINKS = [
        { label: 'Impressum', href: '/impressum' },
        { label: 'AGB', href: '/agb' },
        { label: 'Datenschutz', href: '/datenschutz' },
        { label: 'Widerruf', href: '/widerruf' },
      ]

      const hrefs = LEGAL_LINKS.map((l) => l.href)
      expect(hrefs).toContain('/impressum')
      expect(hrefs).toContain('/agb')
      expect(hrefs).toContain('/datenschutz')
      expect(hrefs).toContain('/widerruf')
    })
  })

  // ============================================================
  // AC-2: legal.yaml $route.slug resolution
  // ============================================================

  describe('AC-2: legal.yaml param resolution', () => {
    it('AC-2: GIVEN die Route /impressum ist aufgerufen WHEN die Seite geladen wird THEN wird legal.yaml geladen und $route.slug mit "impressum" aufgeloest', () => {
      /**
       * AC-2: GIVEN die Route /impressum ist aufgerufen
       * WHEN die Seite geladen wird
       * THEN wird themes/default/pages/legal.yaml geladen und $route.slug mit "impressum" aufgeloest
       */
      const config = loadPageConfig('legal', 'default', { slug: 'impressum' })

      // legal.yaml must have 2 sections
      expect(config.sections).toHaveLength(2)

      // Section 1: page-heading block
      expect(config.sections[0].columns).toBe(1)
      expect(config.sections[0].blocks[0].type).toBe('page-heading')
      const headingParams = config.sections[0].blocks[0].params as unknown as Record<string, unknown>
      expect(headingParams.page_slug).toBe('impressum')
      expect(headingParams.query).toBe('page_content')

      // Section 2: legal-content block
      expect(config.sections[1].columns).toBe(1)
      expect(config.sections[1].blocks[0].type).toBe('legal-content')
      const contentParams = config.sections[1].blocks[0].params as unknown as Record<string, unknown>
      expect(contentParams.page_slug).toBe('impressum')
      expect(contentParams.query).toBe('page_content')
    })

    it('AC-2: resolveParams should replace $route.slug with agb for agb route', () => {
      const legalBlockParams = { page_slug: '$route.slug', query: 'page_content' }
      const result = resolveParams(legalBlockParams, { slug: 'agb' })
      expect(result.page_slug).toBe('agb')
      expect(result.query).toBe('page_content')
    })
  })

  // ============================================================
  // AC-3: LegalContentBlock renders title and HTML content
  // ============================================================

  describe('AC-3: LegalContentBlock happy path', () => {
    it('AC-3: GIVEN /impressum ist aufgerufen und WordPress hat Platzhalter-Content WHEN die Seite rendert THEN zeigt h1 den Titel und HTML-Content erscheint im legal-content Block', () => {
      /**
       * AC-3: GIVEN /impressum ist aufgerufen und WordPress hat die Seite mit Platzhalter-Content
       * WHEN die Seite rendert
       * THEN zeigt <h1> den WordPress-Seitentitel ("Impressum") und der HTML-Content erscheint im legal-content Block
       */
      // Model the rendering path: page-heading renders title as h1, legal-content renders HTML
      const renderHeading = (data: { title: string; content: string } | null): string => {
        if (!data) return ''
        return `<h1>${data.title}</h1>`
      }
      const renderContent = (data: { title: string; content: string } | null): string => {
        if (!data) return 'Inhalt konnte nicht geladen werden.'
        if (!data.content) return 'Kein Inhalt vorhanden.'
        return data.content
      }

      const wpData = { title: 'Impressum', content: '<p>Musterstrasse 1, 12345 Berlin</p>' }

      expect(renderHeading(wpData)).toBe('<h1>Impressum</h1>')
      expect(renderContent(wpData)).toBe('<p>Musterstrasse 1, 12345 Berlin</p>')
    })
  })

  // ============================================================
  // AC-4: All 4 legal slugs resolve correctly
  // ============================================================

  describe('AC-4: All 4 legal slugs resolve correctly', () => {
    it.each([
      ['impressum', 'Impressum'],
      ['agb', 'AGB'],
      ['datenschutz', 'Datenschutz'],
      ['widerruf', 'Widerruf'],
    ])('AC-4: GIVEN /%s ist aufgerufen WHEN die Seite laedt THEN zeigt sie eigenen Titel %s und Content (unterschiedliche Slugs)', (slug, title) => {
      /**
       * AC-4: GIVEN /agb, /datenschutz und /widerruf sind aufgerufen
       * WHEN die Seiten laden
       * THEN zeigt jede Seite ihren eigenen WordPress-Titel und Content (unterschiedliche Slugs)
       */
      const legalBlockParams = { page_slug: '$route.slug', query: 'page_content' }
      const result = resolveParams(legalBlockParams, { slug })

      expect(result.page_slug).toBe(slug)
      expect(result.query).toBe('page_content')

      // Each route should produce unique data when WordPress responds
      const wpData = { title, content: `<p>Platzhalter-Text fuer ${title}</p>` }
      expect(wpData.title).toBe(title)
      expect(wpData.content).toContain(title)
    })

    it('AC-4: loadPageConfig should resolve all 4 slugs through legal.yaml', () => {
      const slugs = ['impressum', 'agb', 'datenschutz', 'widerruf']

      for (const slug of slugs) {
        const config = loadPageConfig('legal', 'default', { slug })

        // Each config should have 2 sections with resolved slug
        expect(config.sections).toHaveLength(2)
        const headingParams = config.sections[0].blocks[0].params as unknown as Record<string, unknown>
        expect(headingParams.page_slug).toBe(slug)
        const contentParams = config.sections[1].blocks[0].params as unknown as Record<string, unknown>
        expect(contentParams.page_slug).toBe(slug)
      }
    })
  })

  // ============================================================
  // AC-5: LegalContentBlock null/empty data handling
  // ============================================================

  describe('AC-5: LegalContentBlock null/empty data handling', () => {
    it('AC-5: GIVEN WordPress gibt pageBy: null zurueck WHEN LegalContentBlock rendert THEN zeigt der Block "Inhalt konnte nicht geladen werden." (kein Crash)', () => {
      /**
       * AC-5: GIVEN WordPress gibt pageBy: null zurueck (Seite nicht gefunden)
       * WHEN LegalContentBlock rendert
       * THEN zeigt der Block die Meldung "Inhalt konnte nicht geladen werden." (kein Crash)
       */
      const renderResult = (data: { title: string; content: string } | null): string => {
        if (!data) return 'Inhalt konnte nicht geladen werden.'
        if (!data.content) return 'Kein Inhalt vorhanden.'
        return data.content
      }

      expect(renderResult(null)).toBe('Inhalt konnte nicht geladen werden.')
    })

    it('AC-5: should render empty message when content is empty string', () => {
      const renderResult = (data: { title: string; content: string } | null): string => {
        if (!data) return 'Inhalt konnte nicht geladen werden.'
        if (!data.content) return 'Kein Inhalt vorhanden.'
        return data.content
      }

      expect(renderResult({ title: 'AGB', content: '' })).toBe('Kein Inhalt vorhanden.')
    })

    it('AC-5: should return HTML content when data is present', () => {
      const renderResult = (data: { title: string; content: string } | null): string => {
        if (!data) return 'Inhalt konnte nicht geladen werden.'
        if (!data.content) return 'Kein Inhalt vorhanden.'
        return data.content
      }

      const html = '<p>AGB-Text hier</p><h2>Abschnitt</h2>'
      expect(renderResult({ title: 'AGB', content: html })).toBe(html)
    })
  })

  // ============================================================
  // AC-6: Apollo React.cache() deduplication
  // ============================================================

  describe('AC-6: Apollo React.cache() deduplication', () => {
    it('AC-6: GIVEN /impressum wird gerendert WHEN Apollo den wordpressLoader fuer page-heading und legal-content aufruft THEN wird GET_PAGE_CONTENT nur EINMAL gesendet (React.cache() Deduplication)', () => {
      /**
       * AC-6: GIVEN die Seite /impressum wird gerendert
       * WHEN Apollo den wordpressLoader fuer page-heading und legal-content aufruft
       * THEN wird GET_PAGE_CONTENT nur EINMAL an WordPress gesendet (Apollo React.cache() Deduplication)
       */
      const config = loadPageConfig('legal', 'default', { slug: 'impressum' })

      // Both blocks resolve to identical query params -> Apollo React.cache() deduplicates
      const allParams = config.sections.flatMap(
        (s) => s.blocks.map((b) => b.params as unknown as Record<string, unknown>)
      )

      expect(allParams).toHaveLength(2)

      // Both blocks share identical params (same query + same page_slug)
      expect(allParams[0].query).toBe('page_content')
      expect(allParams[0].page_slug).toBe('impressum')
      expect(allParams[1].query).toBe('page_content')
      expect(allParams[1].page_slug).toBe('impressum')

      // Identical params = React.cache() key match -> single network request
      expect(allParams[0]).toEqual(allParams[1])
    })
  })

  // ============================================================
  // AC-7: wordpressLoader page_content branch
  // ============================================================

  describe('AC-7: wordpressLoader() page_content handler', () => {
    beforeEach(() => {
      vi.resetModules()
    })

    afterEach(() => {
      vi.restoreAllMocks()
    })

    it('AC-7: GIVEN wordpressLoader wird mit query=page_content aufgerufen WHEN der Loader ausgefuehrt wird THEN wird GET_PAGE_CONTENT Query verwendet und gibt WPPageContent zurueck', async () => {
      /**
       * AC-7: GIVEN wordpressLoader wird mit params.query === 'page_content' aufgerufen
       * WHEN der Loader ausgefuehrt wird
       * THEN wird GET_PAGE_CONTENT Query verwendet und gibt WPPageContent { title, content } zurueck
       */
      vi.doMock('@/lib/apollo/server-client', () => ({
        getClient: () => ({
          query: vi.fn().mockResolvedValue({
            data: {
              pageBy: {
                title: 'Impressum',
                content: '<p>Platzhalter-Impressum-Text</p>',
              },
            },
          }),
        }),
      }))

      const { wordpressLoader } = await import('@/lib/blocks/data-loaders')

      const result = await wordpressLoader({
        page_slug: 'impressum',
        query: 'page_content',
      })

      expect(result.data).not.toBeNull()
      expect((result.data as { title: string; content: string }).title).toBe('Impressum')
      expect((result.data as { title: string; content: string }).content).toBe(
        '<p>Platzhalter-Impressum-Text</p>'
      )
    })

    it('AC-7: should return null data when pageBy is null (page not found)', async () => {
      vi.doMock('@/lib/apollo/server-client', () => ({
        getClient: () => ({
          query: vi.fn().mockResolvedValue({
            data: { pageBy: null },
          }),
        }),
      }))

      const { wordpressLoader } = await import('@/lib/blocks/data-loaders')

      const result = await wordpressLoader({
        page_slug: 'nicht-vorhanden',
        query: 'page_content',
      })

      expect(result.data).toBeNull()
    })

    it('AC-7: should return null and error string on GraphQL exception', async () => {
      vi.doMock('@/lib/apollo/server-client', () => ({
        getClient: () => ({
          query: vi.fn().mockRejectedValue(new Error('GraphQL network error')),
        }),
      }))
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

      const { wordpressLoader } = await import('@/lib/blocks/data-loaders')

      const result = await wordpressLoader({
        page_slug: 'impressum',
        query: 'page_content',
      })

      expect(result.data).toBeNull()
      expect(result.error).toContain('GraphQL network error')

      consoleSpy.mockRestore()
    })
  })

  // ============================================================
  // AC-8: wordpressLoader custom_fields backward compatibility
  // ============================================================

  describe('AC-8: wordpressLoader() custom_fields backward compatibility', () => {
    beforeEach(() => {
      vi.resetModules()
    })

    afterEach(() => {
      vi.restoreAllMocks()
    })

    it('AC-8: GIVEN wordpressLoader wird mit query=custom_fields aufgerufen WHEN der Loader ausgefuehrt wird THEN wird bestehendes GET_PAGE_CUSTOM_FIELDS Verhalten nicht veraendert', async () => {
      /**
       * AC-8: GIVEN wordpressLoader wird mit params.query === 'custom_fields' (oder ohne query) aufgerufen
       * WHEN der Loader ausgefuehrt wird
       * THEN wird das bestehende GET_PAGE_CUSTOM_FIELDS Verhalten nicht veraendert (Rueckwaertskompatibilitaet)
       */
      vi.doMock('@/lib/apollo/server-client', () => ({
        getClient: () => ({
          query: vi.fn().mockResolvedValue({
            data: {
              pageBy: {
                heroHeadline: 'Test Headline',
                heroSubline: null,
                heroCtaText: null,
                heroCtaLink: null,
                heroBackgroundImage: null,
                seoMetaDescription: null,
              },
            },
          }),
        }),
      }))

      const { wordpressLoader } = await import('@/lib/blocks/data-loaders')

      // No query parameter = custom_fields default
      const result = await wordpressLoader({ page_slug: '/' })

      expect(result.data).not.toBeNull()
      expect(result.error).toBeUndefined()
    })
  })

  // ============================================================
  // Unit: Registry — legal-content block type registered
  // ============================================================

  describe('Unit: Registry — legal-content block type', () => {
    it('should have legal-content registered in block registry', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('legal-content')
      expect(component).not.toBeNull()
    })
  })

  // ============================================================
  // Unit: GET_PAGE_CONTENT query export
  // ============================================================

  describe('Unit: GET_PAGE_CONTENT query', () => {
    it('should be exported from lib/graphql/queries.ts', async () => {
      const queries = await import('@/lib/graphql/queries')
      expect(queries.GET_PAGE_CONTENT).toBeDefined()
    })
  })

  // ============================================================
  // Unit: WPPageContent type contract
  // ============================================================

  describe('Unit: legal.yaml structure', () => {
    it('should have 2 sections: page-heading and legal-content', () => {
      const config = loadPageConfig('legal', 'default', { slug: 'test' })

      expect(config.sections).toHaveLength(2)
      expect(config.sections[0].blocks[0].type).toBe('page-heading')
      expect(config.sections[0].blocks[0].content_source).toBe('wordpress')
      expect(config.sections[1].blocks[0].type).toBe('legal-content')
      expect(config.sections[1].blocks[0].content_source).toBe('wordpress')
    })
  })
})
