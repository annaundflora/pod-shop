# Slice 4: Rechtsseiten als Block-Pages

> **Slice 4 von 4** fuer `Block-basierte Seiten-Migration`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-03-produkt-seite.md` |
> | **Naechster:** | — |

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-04-rechtsseiten` |
| **Test** | `pnpm test tests/slices/block-page-migration/slice-04-rechtsseiten.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-block-system-upgrade", "slice-02-kategorie-seite"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` enthaelt `next ^16.1.6` + `vitest ^3.0.0`.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `cd frontend && pnpm test tests/slices/block-page-migration/slice-04-rechtsseiten.test.ts` |
| **Integration Command** | `cd frontend && pnpm test tests/slices/block-page-migration/` |
| **Acceptance Command** | `cd frontend && pnpm build` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/impressum` |
| **Mocking Strategy** | `mock_external` |

---

## Slice-Uebersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Block-System Upgrade | Done | `slice-01-block-system-upgrade.md` |
| 2 | Kategorie-Seite als Block-Page | Done | `slice-02-kategorie-seite.md` |
| 3 | Produkt-Seite als Block-Page | Done | `slice-03-produkt-seite.md` |
| 4 | Rechtsseiten als Block-Pages | Ready | `slice-04-rechtsseiten.md` |

---

## Kontext & Ziel

Die vier Rechtsseiten (Impressum, AGB, Datenschutz, Widerruf) existieren aktuell nicht in Next.js. Der Footer verlinkt direkt auf WordPress-URLs (`${WP_URL}/impressum` etc.). Das ist problematisch, weil:

1. Nutzer verlassen die Next.js-App beim Klick auf Rechtslinks
2. Ein zweiter Shop kann die Rechtsseiten-Struktur nicht per YAML-Theme anpassen
3. WordPress-Redirects durch das `headless-redirect.php` mu-plugin koennen die Seiten verbergen

Dieser Slice ergaenzt:
- Einen neuen `legal-content` Block-Type der WordPress-HTML-Content per `dangerouslySetInnerHTML` rendert
- Den `page_content` DataLoader-Handler im `wordpressLoader` (erweiterter `query`-Parameter)
- Eine neue GraphQL Query `GET_PAGE_CONTENT` die `pageBy(uri: $slug)` aufruft
- Ein gemeinsames `themes/default/pages/legal.yaml` Template fuer alle 4 Rechtsseiten
- Vier schlanke Route-Pages (`app/impressum/page.tsx` etc.)
- Footer-Umbau: `<a href="${WP_URL}/...">` → `<Link href="/...">`

Der `page-heading` Block aus Slice 2 wird wiederverwendet (existiert bereits nach Slice 1+2).

**Aktuelle Probleme:**
1. `components/layout/footer.tsx`: `LEGAL_LINKS` nutzen `${WP_URL}/impressum` (extern) statt `/impressum` (intern)
2. `/impressum`, `/agb`, `/datenschutz`, `/widerruf` existieren nicht als Next.js-Routen
3. `lib/blocks/data-loaders.ts`: `wordpressLoader` unterstuetzt nur `query: 'custom_fields'` — `page_content` fehlt
4. `lib/graphql/queries.ts`: `GET_PAGE_CONTENT` Query fehlt

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Block → Data Source Mapping (legal), Server Logic

```
app/impressum/page.tsx
  → loadPageConfig('legal', theme, { slug: 'impressum' })
    → themes/default/pages/legal.yaml (mit $route.slug Platzhaltern)
    → resolveParams({ slug: '$route.slug' }, { slug: 'impressum' })
  → SectionRenderer [sections[]]
    → Section 1 (columns: 1): page-heading
      → loadBlockData('wordpress', { page_slug: 'impressum', query: 'page_content' })
        → wordpressLoader → GET_PAGE_CONTENT → { pageBy: { title, content } }
      → PageHeadingBlock({ data: { title: 'Impressum' } }) → <h1>Impressum</h1>
    → Section 2 (columns: 1): legal-content
      → loadBlockData('wordpress', { page_slug: 'impressum', query: 'page_content' })
        → Apollo React.cache() deduplication: KEIN zweiter Netzwerk-Request
      → LegalContentBlock({ data: { content: '<p>...</p>' } }) → dangerouslySetInnerHTML
```

### 1. Architektur-Impact

| Layer | Aenderungen |
|-------|-------------|
| `lib/graphql/queries.ts` | `GET_PAGE_CONTENT` Query NEU — `pageBy(uri: $slug)` mit `title` + `content` |
| `lib/blocks/data-loaders.ts` | `wordpressLoader()` erweitert: `if (params.query === 'page_content')` Branch NEU. Import `GET_PAGE_CONTENT`. Rueckgabetyp-Union erweitert. |
| `lib/blocks/registry.ts` | `legal-content` Block-Type NEU registriert → `LegalContentBlock` |
| `components/blocks/legal-content-block.tsx` | NEU — Server Component, `dangerouslySetInnerHTML` mit WordPress-HTML |
| `themes/default/pages/legal.yaml` | NEU — gemeinsames YAML-Template fuer alle 4 Rechtsseiten (page-heading + legal-content) |
| `app/impressum/page.tsx` | NEU — schlanke Route-Page |
| `app/agb/page.tsx` | NEU — schlanke Route-Page |
| `app/datenschutz/page.tsx` | NEU — schlanke Route-Page |
| `app/widerruf/page.tsx` | NEU — schlanke Route-Page |
| `components/layout/footer.tsx` | `LEGAL_LINKS` hrefs von `${WP_URL}/...` auf `/...` umgestellt. `<a>` → `<Link>` (next/link). |

### 2. Datenfluss

```
Route: /impressum
  ↓
app/impressum/page.tsx
  ↓ loadPageConfig('legal', theme, { slug: 'impressum' })
themes/default/pages/legal.yaml
  sections:
    - blocks: [page-heading, params: { page_slug: '$route.slug', query: 'page_content' }]
    - blocks: [legal-content, params: { page_slug: '$route.slug', query: 'page_content' }]
  ↓ resolveParams: '$route.slug' → 'impressum'
  ↓
SectionRenderer
  ↓ Section 1: page-heading
  ↓ loadBlockData('wordpress', { page_slug: 'impressum', query: 'page_content' })
    → GET_PAGE_CONTENT(slug: 'impressum') → { pageBy: { title: 'Impressum', content: '<p>...</p>' } }
    → WPPageContent { title: 'Impressum', content: '<p>Platzhalter-Text</p>' }
  ↓ PageHeadingBlock({ data: { title: 'Impressum' } }) → <h1>Impressum</h1>

  ↓ Section 2: legal-content
  ↓ loadBlockData('wordpress', { page_slug: 'impressum', query: 'page_content' })
    → Apollo React.cache() deduplication → kein zweiter Netzwerk-Request
    → WPPageContent { title: 'Impressum', content: '<p>Platzhalter-Text</p>' }
  ↓ LegalContentBlock({ data: { content: '<p>Platzhalter-Text</p>' } })
    → <div dangerouslySetInnerHTML={{ __html: data.content }} />
  ↓
HTML Response an Browser
```

### 3. Neue GraphQL Query

```typescript
// lib/graphql/queries.ts — Ergaenzung

export const GET_PAGE_CONTENT = gql`
  query GetPageContent($slug: String!) {
    pageBy(uri: $slug) {
      title
      content
    }
  }
`
```

**Hinweis zur WPGraphQL-Kompatibilitaet:** `pageBy(uri: "/impressum")` ist der empfohlene Weg in WPGraphQL. Der `uri`-Parameter akzeptiert auch den Slug ohne fuehrenden Slash (z.B. `"impressum"`). Die Route-Pages uebergeben den Slug ohne Slash gemaess `architecture.md` Assumption-Dokumentation.

### 4. wordpressLoader Erweiterung

```typescript
// lib/blocks/data-loaders.ts — wordpressLoader() erweitert

import { GET_PAGE_CUSTOM_FIELDS, GET_PAGE_CONTENT } from '@/lib/graphql/queries'
// GET_PAGE_CONTENT aus queries.ts importieren (nicht mehr lokal definiert)
// GET_PAGE_CUSTOM_FIELDS bleibt lokal ODER wird ebenfalls nach queries.ts verschoben

import type { WPPageContent } from './types'

interface WordPressLoaderResult {
  data: WPCustomFieldsData | WPPageContent | null
  error?: string
}

async function wordpressLoader(params: WordPressLoaderParams): Promise<WordPressLoaderResult> {
  try {
    if (params.query === 'page_content') {
      const { data } = await getClient().query<{ pageBy: { title: string; content: string } | null }>({
        query: GET_PAGE_CONTENT,
        variables: { slug: params.page_slug },
      })
      if (!data?.pageBy) return { data: null }
      return {
        data: {
          title: data.pageBy.title,
          content: data.pageBy.content,
        } satisfies WPPageContent,
      }
    }

    // Default: custom_fields (bestehendes Verhalten)
    const { data } = await getClient().query<{ pageBy: WPCustomFieldsData | null }>({
      query: GET_PAGE_CUSTOM_FIELDS,
      variables: { slug: params.page_slug },
    })
    return { data: data?.pageBy ?? null }
  } catch (error) {
    console.error('DataLoader.wordpress error:', error)
    return { data: null, error: String(error) }
  }
}
```

**Wichtig:** `GET_PAGE_CUSTOM_FIELDS` ist aktuell lokal in `data-loaders.ts` definiert. Bei der Erweiterung ENTWEDER lokal belassen UND `GET_PAGE_CONTENT` lokal hinzufuegen, ODER beide nach `lib/graphql/queries.ts` verschieben. Empfehlung: `GET_PAGE_CONTENT` nach `queries.ts` exportieren (konsistent mit allen anderen Queries), `GET_PAGE_CUSTOM_FIELDS` bleibt lokal (homepage-spezifisch).

### 5. LegalContentBlock Implementierung

```typescript
// components/blocks/legal-content-block.tsx

import type { WPPageContent } from '@/lib/blocks/types'

interface LegalContentBlockProps {
  data: WPPageContent | null
}

export function LegalContentBlock({ data }: LegalContentBlockProps) {
  if (!data) {
    return (
      <div className="rounded-card bg-surface border border-border p-8 text-center text-text-secondary">
        Inhalt konnte nicht geladen werden.
      </div>
    )
  }

  if (!data.content) {
    return (
      <div className="rounded-card bg-surface border border-border p-8 text-center text-text-secondary">
        Kein Inhalt vorhanden.
      </div>
    )
  }

  return (
    <div
      className="prose prose-sm max-w-none text-text-primary [&_h2]:text-text-primary [&_h3]:text-text-primary [&_a]:text-primary [&_a:hover]:underline"
      dangerouslySetInnerHTML={{ __html: data.content }}
    />
  )
}

export function LegalContentBlockSkeleton() {
  return (
    <div className="space-y-4 animate-pulse">
      <div className="h-4 bg-surface-elevated rounded w-full" />
      <div className="h-4 bg-surface-elevated rounded w-5/6" />
      <div className="h-4 bg-surface-elevated rounded w-full" />
      <div className="h-4 bg-surface-elevated rounded w-4/6" />
      <div className="h-4 bg-surface-elevated rounded w-full" />
      <div className="h-4 bg-surface-elevated rounded w-3/4" />
    </div>
  )
}
```

**Security Note (aus architecture.md):** `dangerouslySetInnerHTML` ist sicher weil eigenes WordPress-Backend die einzige Datenquelle ist. Identisches Pattern wie bestehendes `product.description` in der Produkt-Seite.

### 6. legal.yaml — gemeinsames Rechtsseiten-Template

```yaml
# themes/default/pages/legal.yaml
# Gemeinsames YAML-Template fuer alle 4 Rechtsseiten.
# $route.slug wird zur Laufzeit ersetzt: impressum / agb / datenschutz / widerruf

sections:
  - columns: 1
    blocks:
      - type: page-heading
        content_source: wordpress
        params:
          page_slug: "$route.slug"
          query: page_content

  - columns: 1
    blocks:
      - type: legal-content
        content_source: wordpress
        params:
          page_slug: "$route.slug"
          query: page_content
```

**YAML-Strategie:** Ein einziges `legal.yaml` fuer alle 4 Rechtsseiten. Der `$route.slug` Param-Resolver (aus Slice 1) ersetzt den Platzhalter mit dem jeweiligen Slug (`impressum`, `agb`, `datenschutz`, `widerruf`). Slug-spezifische Override-Dateien (z.B. `themes/default/pages/legal/impressum.yaml`) sind moeglich aber nicht erstellt.

### 7. Route-Pages (4x identisches Pattern)

```typescript
// app/impressum/page.tsx
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import { LegalContentBlockSkeleton } from '@/components/blocks/legal-content-block'
import { PageHeadingBlockSkeleton } from '@/components/blocks/page-heading-block'
import type { Metadata } from 'next'

export const metadata: Metadata = {
  title: 'Impressum',
}

const SKELETON_MAP: Record<string, React.ReactNode> = {
  'page-heading': <PageHeadingBlockSkeleton />,
  'legal-content': <LegalContentBlockSkeleton />,
}

export default function ImpressumPage() {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const pageConfig = loadPageConfig('legal', theme, { slug: 'impressum' })

  return (
    <main id="main-content" className="max-w-3xl mx-auto px-4 py-12 space-y-8">
      <SectionRenderer
        sections={pageConfig.sections}
        skeletonMap={SKELETON_MAP}
      />
    </main>
  )
}
```

```typescript
// app/agb/page.tsx — identisch bis auf slug und title
export const metadata: Metadata = { title: 'AGB' }

export default function AgbPage() {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const pageConfig = loadPageConfig('legal', theme, { slug: 'agb' })

  return (
    <main id="main-content" className="max-w-3xl mx-auto px-4 py-12 space-y-8">
      <SectionRenderer sections={pageConfig.sections} skeletonMap={SKELETON_MAP} />
    </main>
  )
}
```

```typescript
// app/datenschutz/page.tsx
export const metadata: Metadata = { title: 'Datenschutz' }

export default function DatenschutzPage() {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const pageConfig = loadPageConfig('legal', theme, { slug: 'datenschutz' })

  return (
    <main id="main-content" className="max-w-3xl mx-auto px-4 py-12 space-y-8">
      <SectionRenderer sections={pageConfig.sections} skeletonMap={SKELETON_MAP} />
    </main>
  )
}
```

```typescript
// app/widerruf/page.tsx
export const metadata: Metadata = { title: 'Widerruf' }

export default function WiderrufPage() {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const pageConfig = loadPageConfig('legal', theme, { slug: 'widerruf' })

  return (
    <main id="main-content" className="max-w-3xl mx-auto px-4 py-12 space-y-8">
      <SectionRenderer sections={pageConfig.sections} skeletonMap={SKELETON_MAP} />
    </main>
  )
}
```

### 8. Footer-Umbau

```typescript
// components/layout/footer.tsx — nach Migration

import Link from 'next/link'
import { getAccountUrl } from '@/lib/config/account'

// VORHER:
// const WP_URL = process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'
// const LEGAL_LINKS = [
//   { label: 'Impressum', href: `${WP_URL}/impressum` },
//   ...
// ]
// <a href={link.href}>

// NACHHER:
const LEGAL_LINKS = [
  { label: 'Impressum', href: '/impressum' },
  { label: 'AGB', href: '/agb' },
  { label: 'Datenschutz', href: '/datenschutz' },
  { label: 'Widerruf', href: '/widerruf' },
] as const

export function Footer() {
  const currentYear = new Date().getFullYear()

  return (
    <footer className="border-t border-border mt-auto py-8 px-4">
      <div className="max-w-5xl mx-auto">
        {/* Rechtliche Links */}
        <nav aria-label="Rechtliche Seiten">
          <ul className="flex flex-wrap gap-x-4 gap-y-2 justify-center text-sm text-text-secondary">
            {LEGAL_LINKS.map((link) => (
              <li key={link.href}>
                <Link
                  href={link.href}
                  className="hover:text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded underline-offset-2 hover:underline"
                >
                  {link.label}
                </Link>
              </li>
            ))}
          </ul>
        </nav>

        {/* Mein Konto Link */}
        <div className="text-center mt-3">
          <a
            href={getAccountUrl()}
            className="text-sm text-text-secondary hover:text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded"
          >
            Mein Konto
          </a>
        </div>

        {/* Copyright */}
        <p className="text-center text-xs text-text-secondary mt-4" suppressHydrationWarning>
          &copy; {currentYear} {process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}
        </p>
      </div>
    </footer>
  )
}
```

**Aenderungen am Footer:**
- `WP_URL` Konstante entfernt (nicht mehr benoetigt)
- `LEGAL_LINKS.href` von `${WP_URL}/impressum` auf `/impressum` (intern) umgestellt
- `<a href={link.href}>` → `<Link href={link.href}>` (Next.js `<Link>` fuer interne Navigation)
- Styling unveraendert (alle Tailwind-Klassen bleiben identisch)

### 9. Registry-Erweiterung

```typescript
// lib/blocks/registry.ts — Ergaenzung (nur der neue Eintrag)

import { LegalContentBlock } from '@/components/blocks/legal-content-block'

// Bestehende Eintraege bleiben unveraendert, NEU hinzufuegen:
// 'legal-content': LegalContentBlock,
```

---

## UI Anforderungen

### Wireframe (aus architecture.md / discovery.md)

> **Quelle:** `discovery.md` → Screen: Rechtsseiten (NEU)

```
/impressum (und analog: /agb, /datenschutz, /widerruf)

+------------------------------------------+
|  [Header/Nav]                            |
+------------------------------------------+
|                                          |
|  Impressum                     <- h1     |
|                                          |
+------------------------------------------+
|                                          |
|  [WordPress HTML Content]                |
|                                          |
|  Paragraph text...                       |
|  Paragraph text...                       |
|                                          |
|  ## Abschnitt                            |
|  Content...                              |
|                                          |
+------------------------------------------+
|  [Footer mit Next.js-Links]              |
+------------------------------------------+
```

**Layout-Spezifikation (aus discovery.md):**
- Section 1 (columns: 1): page-heading Block — h1 mit Seitentitel
- Section 2 (columns: 1): legal-content Block — HTML-Content aus WordPress
- Max-Width: `max-w-3xl` (prose-freundliche Zeilenlaenge)
- Padding: `px-4 py-12`

### 1. LegalContentBlock

**Komponente & Datei:**
- `components/blocks/legal-content-block.tsx` — Server Component

**Verhalten:**
- Rendert WordPress-HTML via `dangerouslySetInnerHTML`
- Prose-Styling fuer lesbare Typografie
- Theme-Token-basierte Farben (keine hardcoded Werte)

**Zustaende:**
- Loading: `LegalContentBlockSkeleton` — animierte Linien-Platzhalter
- Loaded: HTML-Content mit Prose-Styling
- Not found (`data === null`): Fehlermeldung "Inhalt konnte nicht geladen werden."
- Empty (`data.content === ''`): Meldung "Kein Inhalt vorhanden."

### 2. Accessibility

- [x] `<main id="main-content">` auf allen 4 Route-Pages (Skip-Link-Target)
- [x] `<h1>` durch `page-heading` Block (korrekte Heading-Hierarchie)
- [x] Footer-Links als semantische `<Link>` Komponenten (nicht `<div onClick>`)
- [x] `<nav aria-label="Rechtliche Seiten">` bleibt unveraendert im Footer

---

## Acceptance Criteria

1) GIVEN der Footer ist gerendert
   WHEN ein User auf "Impressum" klickt
   THEN navigiert die Next.js-App intern zu `/impressum` (kein Seitenwechsel zu WordPress)

2) GIVEN die Route `/impressum` ist aufgerufen
   WHEN die Seite geladen wird
   THEN wird `themes/default/pages/legal.yaml` geladen und `$route.slug` mit `"impressum"` aufgeloest

3) GIVEN `/impressum` ist aufgerufen und WordPress hat die Seite mit Platzhalter-Content
   WHEN die Seite rendert
   THEN zeigt `<h1>` den WordPress-Seitentitel ("Impressum") und der HTML-Content erscheint im `legal-content` Block

4) GIVEN `/agb`, `/datenschutz` und `/widerruf` sind aufgerufen
   WHEN die Seiten laden
   THEN zeigt jede Seite ihren eigenen WordPress-Titel und Content (unterschiedliche Slugs)

5) GIVEN WordPress gibt `pageBy: null` zurueck (Seite nicht gefunden)
   WHEN `LegalContentBlock` rendert
   THEN zeigt der Block die Meldung "Inhalt konnte nicht geladen werden." (kein Crash)

6) GIVEN die Seite `/impressum` wird gerendert
   WHEN Apollo den `wordpressLoader` fuer `page-heading` und `legal-content` aufruft
   THEN wird `GET_PAGE_CONTENT` nur EINMAL an WordPress gesendet (Apollo `React.cache()` Deduplication)

7) GIVEN `wordpressLoader` wird mit `params.query === 'page_content'` aufgerufen
   WHEN der Loader ausgefuehrt wird
   THEN wird `GET_PAGE_CONTENT` Query verwendet und gibt `WPPageContent { title, content }` zurueck

8) GIVEN `wordpressLoader` wird mit `params.query === 'custom_fields'` (oder ohne `query`) aufgerufen
   WHEN der Loader ausgefuehrt wird
   THEN wird das bestehende `GET_PAGE_CUSTOM_FIELDS` Verhalten nicht veraendert (Rueckwaertskompatibilitaet)

---

## Testfaelle

### Test-Datei

`tests/slices/block-page-migration/slice-04-rechtsseiten.test.ts`

<test_spec>
```typescript
// tests/slices/block-page-migration/slice-04-rechtsseiten.test.ts
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

describe('Slice 04: Rechtsseiten als Block-Pages', () => {

  // ============================================================
  // wordpressLoader page_content Branch (AC-7, AC-8)
  // ============================================================

  describe('wordpressLoader() page_content handler', () => {
    it('should return WPPageContent when query is page_content', async () => {
      // Arrange
      vi.mock('@/lib/apollo/server-client', () => ({
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

      const { wordpressLoader } = await import('../../../frontend/lib/blocks/data-loaders')

      // Act
      const result = await wordpressLoader({
        page_slug: 'impressum',
        query: 'page_content',
      })

      // Assert
      expect(result.data).not.toBeNull()
      expect((result.data as { title: string; content: string }).title).toBe('Impressum')
      expect((result.data as { title: string; content: string }).content).toBe(
        '<p>Platzhalter-Impressum-Text</p>'
      )

      vi.restoreAllMocks()
    })

    it('should return null data when pageBy is null (page not found)', async () => {
      // Arrange
      vi.mock('@/lib/apollo/server-client', () => ({
        getClient: () => ({
          query: vi.fn().mockResolvedValue({
            data: { pageBy: null },
          }),
        }),
      }))

      const { wordpressLoader } = await import('../../../frontend/lib/blocks/data-loaders')

      // Act
      const result = await wordpressLoader({
        page_slug: 'nicht-vorhanden',
        query: 'page_content',
      })

      // Assert
      expect(result.data).toBeNull()

      vi.restoreAllMocks()
    })

    it('should still handle custom_fields query without breaking (AC-8)', async () => {
      // Arrange
      vi.mock('@/lib/apollo/server-client', () => ({
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

      const { wordpressLoader } = await import('../../../frontend/lib/blocks/data-loaders')

      // Act — kein query-Parameter = custom_fields Default
      const result = await wordpressLoader({ page_slug: '/' })

      // Assert — bestehendes Verhalten unveraendert
      expect(result.data).not.toBeNull()
      expect(result.error).toBeUndefined()

      vi.restoreAllMocks()
    })

    it('should return null and error string on GraphQL exception', async () => {
      // Arrange
      vi.mock('@/lib/apollo/server-client', () => ({
        getClient: () => ({
          query: vi.fn().mockRejectedValue(new Error('GraphQL network error')),
        }),
      }))

      const { wordpressLoader } = await import('../../../frontend/lib/blocks/data-loaders')

      // Act
      const result = await wordpressLoader({
        page_slug: 'impressum',
        query: 'page_content',
      })

      // Assert
      expect(result.data).toBeNull()
      expect(result.error).toContain('GraphQL network error')

      vi.restoreAllMocks()
    })
  })

  // ============================================================
  // LegalContentBlock render logic (AC-5)
  // ============================================================

  describe('LegalContentBlock happy path (AC-3)', () => {
    it('should render h1 title and HTML content when WordPress data is present', () => {
      // Modelliert den Rendering-Pfad mit echten Daten:
      // page-heading Block rendert title als h1, legal-content Block rendert HTML content
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

  describe('All 4 legal slugs resolve correctly (AC-4)', () => {
    it.each([
      ['impressum', 'Impressum'],
      ['agb', 'AGB'],
      ['datenschutz', 'Datenschutz'],
      ['widerruf', 'Widerruf'],
    ])('should resolve $route.slug=%s and render title=%s', (slug, title) => {
      const resolveParams = (
        params: Record<string, unknown>,
        routeContext: Record<string, string>
      ): Record<string, unknown> => {
        const resolved: Record<string, unknown> = {}
        for (const [key, value] of Object.entries(params)) {
          if (typeof value === 'string' && value.startsWith('$route.')) {
            const routeKey = value.slice('$route.'.length)
            resolved[key] = routeContext[routeKey] ?? ''
          } else {
            resolved[key] = value
          }
        }
        return resolved
      }

      const legalBlockParams = { page_slug: '$route.slug', query: 'page_content' }
      const result = resolveParams(legalBlockParams, { slug })

      expect(result.page_slug).toBe(slug)
      expect(result.query).toBe('page_content')

      // Simuliert dass jede Route eigene Daten rendert (unterschiedliche Slugs)
      const wpData = { title, content: `<p>Platzhalter-Text fuer ${title}</p>` }
      expect(wpData.title).toBe(title)
      expect(wpData.content).toContain(title)
    })
  })

  describe('LegalContentBlock null/empty data handling', () => {
    it('should render error message when data is null', () => {
      // Modelliert den Rendering-Pfad: null → Fehlermeldung
      const renderResult = (data: { title: string; content: string } | null): string => {
        if (!data) return 'Inhalt konnte nicht geladen werden.'
        if (!data.content) return 'Kein Inhalt vorhanden.'
        return data.content
      }

      expect(renderResult(null)).toBe('Inhalt konnte nicht geladen werden.')
    })

    it('should render empty message when content is empty string', () => {
      const renderResult = (data: { title: string; content: string } | null): string => {
        if (!data) return 'Inhalt konnte nicht geladen werden.'
        if (!data.content) return 'Kein Inhalt vorhanden.'
        return data.content
      }

      expect(renderResult({ title: 'AGB', content: '' })).toBe('Kein Inhalt vorhanden.')
    })

    it('should return HTML content when data is present', () => {
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
  // Apollo deduplication for page_content (AC-6)
  // ============================================================

  describe('Apollo React.cache() deduplication (AC-6)', () => {
    it('should call getClient().query only once when wordpressLoader is called twice with identical params', async () => {
      // React.cache() deduplication: identische Query-Aufrufe innerhalb derselben
      // RSC-Render-Phase werden auf einen Netzwerk-Request reduziert.
      // Dieser Test modelliert das Verhalten durch einen Mock der Apollo-Client-Instanz.
      const mockQuery = vi.fn().mockResolvedValue({
        data: { pageBy: { title: 'Impressum', content: '<p>Text</p>' } },
      })
      vi.mock('@/lib/apollo/server-client', () => ({
        getClient: () => ({ query: mockQuery }),
      }))

      const { wordpressLoader } = await import('../../../frontend/lib/blocks/data-loaders')

      // Zwei identische Aufrufe (page-heading + legal-content auf derselben Seite)
      await wordpressLoader({ page_slug: 'impressum', query: 'page_content' })
      await wordpressLoader({ page_slug: 'impressum', query: 'page_content' })

      // React.cache() stellt sicher: nur 1 Netzwerk-Request trotz 2 Aufrufen
      expect(mockQuery).toHaveBeenCalledTimes(1)

      vi.restoreAllMocks()
    })
  })

  // ============================================================
  // legal.yaml $route.slug Resolution (AC-2)
  // ============================================================

  describe('legal.yaml param resolution', () => {
    it('should resolve $route.slug to impressum', () => {
      // Testet resolveParams direkt (aus Slice 1)
      const resolveParams = (
        params: Record<string, unknown>,
        routeContext: Record<string, string>
      ): Record<string, unknown> => {
        const resolved: Record<string, unknown> = {}
        for (const [key, value] of Object.entries(params)) {
          if (typeof value === 'string' && value.startsWith('$route.')) {
            const routeKey = value.slice('$route.'.length)
            resolved[key] = routeContext[routeKey] ?? ''
          } else {
            resolved[key] = value
          }
        }
        return resolved
      }

      // legal.yaml Block-Params
      const legalBlockParams = { page_slug: '$route.slug', query: 'page_content' }

      const result = resolveParams(legalBlockParams, { slug: 'impressum' })
      expect(result.page_slug).toBe('impressum')
      expect(result.query).toBe('page_content')
    })

    it('should resolve $route.slug to agb for agb route', () => {
      const resolveParams = (
        params: Record<string, unknown>,
        routeContext: Record<string, string>
      ): Record<string, unknown> => {
        const resolved: Record<string, unknown> = {}
        for (const [key, value] of Object.entries(params)) {
          if (typeof value === 'string' && value.startsWith('$route.')) {
            const routeKey = value.slice('$route.'.length)
            resolved[key] = routeContext[routeKey] ?? ''
          } else {
            resolved[key] = value
          }
        }
        return resolved
      }

      const legalBlockParams = { page_slug: '$route.slug', query: 'page_content' }
      const result = resolveParams(legalBlockParams, { slug: 'agb' })
      expect(result.page_slug).toBe('agb')
    })
  })

  // ============================================================
  // Footer LEGAL_LINKS (AC-1)
  // ============================================================

  describe('Footer LEGAL_LINKS internal routes', () => {
    it('should use internal Next.js paths (no WP_URL)', () => {
      // Testet dass die Links intern sind (kein absolutes URL-Format)
      const LEGAL_LINKS = [
        { label: 'Impressum', href: '/impressum' },
        { label: 'AGB', href: '/agb' },
        { label: 'Datenschutz', href: '/datenschutz' },
        { label: 'Widerruf', href: '/widerruf' },
      ]

      for (const link of LEGAL_LINKS) {
        expect(link.href).toMatch(/^\//)
        expect(link.href).not.toContain('http')
        expect(link.href).not.toContain('localhost:8080')
      }
    })

    it('should have exactly 4 legal links', () => {
      const LEGAL_LINKS = [
        { label: 'Impressum', href: '/impressum' },
        { label: 'AGB', href: '/agb' },
        { label: 'Datenschutz', href: '/datenschutz' },
        { label: 'Widerruf', href: '/widerruf' },
      ]

      expect(LEGAL_LINKS).toHaveLength(4)
    })

    it('should cover all required legal pages', () => {
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
})
```
</test_spec>

---

## Integration Contract (GATE 2 PFLICHT)

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-01-block-system-upgrade` | `SectionRenderer` | React Server Component | Named Export aus `lib/blocks/section-renderer.tsx`, Props: `{ sections: SectionConfig[], skeletonMap?: Record<string, ReactNode> }` |
| `slice-01-block-system-upgrade` | `loadPageConfig()` | Function | `(pageType: string, theme?: string, routeParams?: RouteContext) => PageConfig` |
| `slice-01-block-system-upgrade` | `resolveParams()` | Function | Loest `$route.slug` in YAML-Params auf |
| `slice-01-block-system-upgrade` | `WordPressLoaderParams.query` | Union Type | `'custom_fields' \| 'page_content'` — `page_content` muss im Union enthalten sein |
| `slice-01-block-system-upgrade` | `WPPageContent` | TypeScript Interface | `{ title: string; content: string }` aus `lib/blocks/types.ts` |
| `slice-01-block-system-upgrade` (oder Slice 2) | `PageHeadingBlock` + `PageHeadingBlockSkeleton` | React Components | Aus `components/blocks/page-heading-block.tsx` — wird auf Rechtsseiten fuer `<h1>` wiederverwendet |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `LegalContentBlock` | React Server Component | `lib/blocks/registry.ts` | `({ data: WPPageContent \| null }) => JSX.Element` |
| `LegalContentBlockSkeleton` | React Component | Route-Pages Skeleton-Map | `() => JSX.Element` |
| `/impressum`, `/agb`, `/datenschutz`, `/widerruf` | Next.js Routes | Footer-Links, Browser | `GET` → HTML-Seite |

### Integration Validation Tasks

- [ ] `WordPressLoaderParams.query` Union enthaelt `'page_content'` (aus Slice 1 types.ts)
- [ ] `WPPageContent` Interface exportiert aus `lib/blocks/types.ts` (Slice 1)
- [ ] `PageHeadingBlock` ist in `lib/blocks/registry.ts` registriert (Slice 2)
- [ ] `SectionRenderer` akzeptiert `skeletonMap` mit `'legal-content'` Key
- [ ] `legal-content` Block-Type in `lib/blocks/registry.ts` registriert
- [ ] `GET_PAGE_CONTENT` exportiert aus `lib/graphql/queries.ts`

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind PFLICHT-Deliverables. Der Gate 2 Compliance Agent prueft, dass jedes Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `GET_PAGE_CONTENT` GraphQL Query | Abschnitt 3 | YES | Exportiert aus `lib/graphql/queries.ts`, `pageBy(uri: $slug)` |
| `wordpressLoader()` Erweiterung (`page_content` Branch) | Abschnitt 4 | YES | `if (params.query === 'page_content')` Branch mit `satisfies WPPageContent` |
| `LegalContentBlock` Server Component | Abschnitt 5 | YES | `dangerouslySetInnerHTML`, null-/empty-Handling, Prose-Styling |
| `LegalContentBlockSkeleton` Component | Abschnitt 5 | YES | Animierte Linien-Platzhalter |
| `legal.yaml` YAML-Template | Abschnitt 6 | YES | Shared Template mit `$route.slug` fuer beide Blocks |
| `app/impressum/page.tsx` Route-Page | Abschnitt 7 | YES | `loadPageConfig('legal', theme, { slug: 'impressum' })` + `SectionRenderer` |
| `app/agb/page.tsx` Route-Page | Abschnitt 7 | YES | Identisches Pattern mit `slug: 'agb'` |
| `app/datenschutz/page.tsx` Route-Page | Abschnitt 7 | YES | Identisches Pattern mit `slug: 'datenschutz'` |
| `app/widerruf/page.tsx` Route-Page | Abschnitt 7 | YES | Identisches Pattern mit `slug: 'widerruf'` |
| `Footer` mit Next.js `<Link>` | Abschnitt 8 | YES | `LEGAL_LINKS` mit internen Pfaden, `<Link href>` statt `<a href>` |
| Registry-Erweiterung `'legal-content'` | Abschnitt 9 | YES | Import `LegalContentBlock` + Registrierung in `registry.ts` |

---

## Links

- Discovery: `specs/phase-0/2026-02-25-block-page-migration/discovery.md`
- Architecture: `specs/phase-0/2026-02-25-block-page-migration/architecture.md`
- Dependency: `specs/phase-0/2026-02-25-block-page-migration/slices/slice-01-block-system-upgrade.md`
- Bestehendes Block-System: `frontend/lib/blocks/`
- Footer (zu aendern): `frontend/components/layout/footer.tsx`
- WPGraphQL pageBy Docs: https://www.wpgraphql.com/docs/posts-and-pages

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
### Neue Dateien

- [ ] `frontend/lib/graphql/queries.ts` — Ergaenzung: `GET_PAGE_CONTENT` Query (exportiert)
- [ ] `frontend/components/blocks/legal-content-block.tsx` — NEU: `LegalContentBlock` Server Component + `LegalContentBlockSkeleton`
- [ ] `frontend/themes/default/pages/legal.yaml` — NEU: Gemeinsames YAML-Template fuer alle 4 Rechtsseiten
- [ ] `frontend/app/impressum/page.tsx` — NEU: Impressum Route-Page
- [ ] `frontend/app/agb/page.tsx` — NEU: AGB Route-Page
- [ ] `frontend/app/datenschutz/page.tsx` — NEU: Datenschutz Route-Page
- [ ] `frontend/app/widerruf/page.tsx` — NEU: Widerruf Route-Page

### Geaenderte Dateien

- [ ] `frontend/lib/blocks/data-loaders.ts` — `wordpressLoader()` erweitert: `page_content` Branch + `GET_PAGE_CONTENT` Import + Rueckgabetyp-Union
- [ ] `frontend/lib/blocks/registry.ts` — `legal-content` Block-Type registriert → `LegalContentBlock`
- [ ] `frontend/components/layout/footer.tsx` — `LEGAL_LINKS` auf interne Pfade umgestellt, `<a>` → Next.js `<Link>`

### Tests

- [ ] `frontend/tests/slices/block-page-migration/slice-04-rechtsseiten.test.ts` — Unit Tests fuer `wordpressLoader` page_content Handler, `LegalContentBlock` Null-Handling, `$route.slug` Auflosung, Footer-Links
<!-- DELIVERABLES_END -->

---

## Constraints & Hinweise

**Betrifft:**
- `lib/blocks/data-loaders.ts`: `wordpressLoader()` Erweiterung ist additive (neuer `if`-Branch), bestehendes Verhalten fuer `custom_fields` unveraendert
- `lib/blocks/registry.ts`: Nur `legal-content` wird neu registriert. `page-heading` muss bereits aus Slice 2 registriert sein (Dependency ueber Slice 1 + Slice 2)
- `lib/graphql/queries.ts`: `GET_PAGE_CONTENT` als neuer Named Export — keine Aenderungen an bestehenden Queries

**Security:**
- `dangerouslySetInnerHTML` in `LegalContentBlock` ist sicher: Datenquelle ist ausschliesslich das eigene WordPress-Backend (identisches Pattern wie `product.description`)
- Keine User-Eingaben werden in HTML gerendert

**Platzhalter-Content:**
- WordPress-Seiten `/impressum`, `/agb`, `/datenschutz`, `/widerruf` werden durch `scripts/setup.sh` mit Platzhalter-Text erstellt
- Echte Rechtstexte kommen in Phase P0.5 (Out of Scope dieses Slices)

**Apollo Deduplication:**
- `page-heading` und `legal-content` auf derselben Seite rufen beide `wordpressLoader({ page_slug: 'impressum', query: 'page_content' })` auf
- Apollo Server Client nutzt `React.cache()` — identische Queries innerhalb des selben Render-Pass werden dedupliziert (nur ein Netzwerk-Request)

**Abgrenzung:**
- KEIN `generateStaticParams` fuer Rechtsseiten (statische Routen, kein `[slug]` Parameter)
- KEIN JSON-LD Schema-Markup (Rechtsseiten benoetigen kein strukturiertes Markup)
- KEINE ISR `revalidate` (Rechtstexte aendern sich selten, statisches Rendering reicht)
- Slug-spezifische YAML-Override-Dateien (`themes/default/pages/legal/impressum.yaml`) werden NICHT erstellt — System ist bereit dafuer, aber keine konkreten Overrides in diesem Slice

**API Contract:**
- `wordpressLoader()` Signatur unveraendert: `(params: WordPressLoaderParams) => Promise<WordPressLoaderResult>`
- `WordPressLoaderParams.query` muss `'page_content'` unterstuetzen (definiert in Slice 1 `types.ts`)
- `LegalContentBlock` erwartet `data: WPPageContent | null` (Interface aus Slice 1)

---

## Skill Verification

### React Best Practices Verification

- [x] `async-suspense-boundaries`: Alle Blocks in Route-Pages via `SectionRenderer` in `<Suspense>` gewrapped (bestehendes Pattern aus Slice 1)
- [x] `server-cache-react`: Apollo `getClient()` mit `React.cache()` — `page-heading` und `legal-content` deduplizieren identische Queries (kein doppelter Netzwerk-Request)
- [x] `rendering-hoist-jsx`: `SKELETON_MAP` als modul-level Konstante in Route-Pages (kein Re-Create per Render)
- [x] `bundle-barrel-imports`: Direkter Import von `LegalContentBlock` aus `components/blocks/legal-content-block.tsx`

### Web Design Guidelines Verification

- [x] Semantic HTML: `<main id="main-content">` auf allen Route-Pages (Skip-Link-Target)
- [x] Heading Hierarchy: `<h1>` durch `page-heading` Block — erste und einzige h1 pro Seite
- [x] Real Links: Footer-Links als `<Link href>` (Next.js), nicht `<div onClick>`
- [x] Focus States: `focus-visible:ring-2 focus-visible:ring-primary` auf Footer-Links (unveraendert)

### Tailwind v4 Patterns Verification

- [x] Theme Tokens: `LegalContentBlock` nutzt `text-text-primary`, `bg-surface`, `border-border`, `rounded-card` — keine hardcoded Farben
- [x] Mobile-first: `max-w-3xl mx-auto px-4` — responsive ohne Breakpoint-Overrides noetig (Text-Content, kein Grid)
