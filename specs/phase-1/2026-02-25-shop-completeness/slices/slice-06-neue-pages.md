# Slice 6: Neue Pages implementieren

> **Slice 6 von 6** für `Shop Completeness — Blocks & Pages`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-05-suchseite.md` |
> | **Nächster:** | — (letzter Slice) |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-06-neue-pages` |
| **Test** | `pnpm test tests/slices/shop-completeness/slice-06-neue-pages.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-cross-page-infrastruktur"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` — Vitest Unit/Component Tests (kein Playwright)
- **Dependencies**: Slice 1 (`PaginationBlock`, `loadGlobalConfig`, Block-Registry-Pattern, `GET_CATEGORY_META` Query, `category_meta` data-loader Branch, `CollectionHeaderData` Interface) muss fertig sein. Slice 3 (`products_by_category` data-loader Branch, `PaginatedProductsResult`, `PaginationMeta`, `buildOrderby`) muss fertig sein.

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` (next ^16.1.6, vitest ^3.0.0, typescript ^5.7.0, @tailwindcss/postcss ^4.0.0).

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/shop-completeness/slice-06-neue-pages.test.ts` |
| **Integration Command** | `pnpm test tests/slices/shop-completeness/` |
| **Acceptance Command** | `pnpm test tests/slices/shop-completeness/slice-06-neue-pages.test.ts --reporter=verbose` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `mock_external` |

**Erklaerung:**
- **Stack**: typescript-nextjs (Next.js 16 App Router, Vitest, Tailwind CSS v4)
- **Test Command**: Einzelner Slice-Testlauf via Vitest (Standard-Reporter)
- **Acceptance Command**: Identische Testdatei mit `--reporter=verbose` fuer vollstaendige AC-Ausgabe (lesbar als Acceptance-Protokoll durch den Orchestrator)
- **Mocking Strategy**: `mock_external` — Apollo Server Client (`lib/apollo/server-client.ts`) wird per `vi.mock()` gemockt. `next/navigation` (`useRouter`, `redirect`, `notFound`) wird gemockt. `lib/blocks/page-config.ts` (`loadPageConfig`) wird gemockt um YAML-Laden zu umgehen. `localStorage` ist via jsdom (bereits konfiguriert) verfuegbar. `window.location` fuer `OrderConfirmationBlock` URL-Param-Lesen gemockt.

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Cross-Page Infrastruktur | Done | `slice-01-cross-page-infrastruktur.md` |
| 2 | Produkt-Page Enhancements | Done | `slice-02-produkt-page-enhancements.md` |
| 3 | Kategorie-Page Enhancements | Done | `slice-03-kategorie-page-enhancements.md` |
| 4 | Homepage Enhancements | Done | `slice-04-homepage-enhancements.md` |
| 5 | Suchseite | Done | `slice-05-suchseite.md` |
| 6 | Neue Pages | Ready | `slice-06-neue-pages.md` |

---

## Kontext & Ziel

Dieser Slice implementiert drei neue eigenständige Page-Routes, die für einen launch-fähigen Shop zwingend erforderlich sind:

1. **`/kollektion/[slug]`** — Collections-Page: Thematisch kuratierte Produkt-Gruppen auf Basis von WooCommerce-Kategorien. Erschliesst neue Navigationseinstiegspunkte (Weihnachten, Sale, Neuheiten) und senkt Bounce Rate.
2. **`/danke`** — Order-Confirmation-Page: Nach Checkout-Abschluss zeigt WooCommerce auf diese Page weiter. Gibt Bestellnummer aus URL-Param aus. Launch-kritisch: ohne diese Page landen Kunden nach dem Kauf auf einem 404.
3. **`not-found.tsx`** — Globale 404-Page: Next.js rendert diese Datei automatisch für alle unbekannten Routes. Verhindert unprofessionellen Standard-404. Launch-blocker.

**Aktuelle Probleme:**
1. Route `/kollektion/[slug]` existiert nicht — jeder Collection-Link ergibt 404
2. Route `/danke` existiert nicht — WooCommerce-Checkout-Redirect landet auf 404 (Bestellungen gehen verloren im Sinne von Post-Purchase-UX)
3. Kein `not-found.tsx` — Next.js Standard-404 ist ungebrandete Fehlerseite
4. Kein `collection-header` Block — fehlt in registry.ts und hat keine Implementierung
5. Kein `order-confirmation` Block — fehlt in registry.ts und hat keine Implementierung
6. Kein `themes/default/pages/collection.yaml` und `themes/default/pages/thanks.yaml`

**Zielbild:** Drei vollständige, YAML-konfigurierte, gebrandete Pages. `collection-header` und `order-confirmation` im Block-System registriert. `/kollektion/sale` zeigt Produkte aus WC-Kategorie "sale". `/danke?order_id=12345` zeigt Bestellnummer. Jede ungültige URL zeigt gebrandeten 404.

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → "New Page Routes", "New Block Components", "New YAML Page Configs", "URL Parameter Handling Pattern", "Block Data Type Definitions"

```
Collections Page Pattern:
  /kollektion/[slug] (RSC, revalidate=60)
    → params.slug + searchParams.page/sort
    → loadPageConfig('collection', theme, { slug, page, sort })
    → collection.yaml → SectionRenderer
    → collection-header: woocommerceLoader('category_meta', { slug })
      → GET_CATEGORY_META → CollectionHeaderData
    → product-count: woocommerceLoader('products_by_category', { slug, page, perPage, sort })
      → GET_PRODUCTS_PAGINATED → PaginatedProductsResult
    → product-grid: (Apollo RSC dedup — gleiche Variablen wie product-count)
    → pagination: (Apollo RSC dedup — gleiche Variablen)

Danke-Page Pattern:
  /danke?order_id=12345&key=abc (Static, keine ISR)
    → searchParams.order_id (optional)
    → loadPageConfig('thanks', theme, { order_id })
    → thanks.yaml → SectionRenderer
    → order-confirmation: inline data (Texte aus YAML) + order_id aus URL (client-side)

404 Pattern (Next.js native):
  app/not-found.tsx — Next.js renders this for all notFound() calls
  Kein Block-System (hardcoded JSX) — kein YAML, kein loadPageConfig
  noindex via generateMetadata
```

### 1. Architektur-Impact

| Layer | Art | Änderung |
|-------|-----|----------|
| `frontend/app/kollektion/[slug]/page.tsx` | NEU | RSC Route, liest `params.slug` + `searchParams.page`/`sort`, Redirect-Guard, loadPageConfig('collection'), generateMetadata mit Kategorie-Name |
| `frontend/app/danke/page.tsx` | NEU | RSC Route (static), liest `searchParams.order_id`, loadPageConfig('thanks'), generateMetadata noindex |
| `frontend/app/not-found.tsx` | NEU | Next.js 404-Handler, hardcoded JSX (kein Block-System), generateMetadata noindex |
| `frontend/components/blocks/collection-header-block.tsx` | NEU | Server Component, nutzt `CollectionHeaderData`, zeigt Kategorie-Bild + Titel + Beschreibung |
| `frontend/components/blocks/order-confirmation-block.tsx` | NEU | Client Component (`'use client'`), liest `order_id` aus `window.location.search` client-seitig, zeigt Bestellnummer (falls vorhanden) |
| `frontend/lib/blocks/registry.ts` | MODIFY | `collection-header` und `order-confirmation` Block-Typen registrieren |
| `frontend/lib/blocks/types.ts` | MODIFY | `CollectionHeaderData` und `OrderConfirmationData` Interfaces hinzufügen (falls noch nicht durch Slice 1/Architecture definiert) |
| `frontend/themes/default/pages/collection.yaml` | NEU | Block-Konfiguration für Collections-Page (collection-header, product-count, product-grid, pagination) |
| `frontend/themes/default/pages/thanks.yaml` | NEU | Block-Konfiguration für Danke-Page (order-confirmation) |

### 2. Datenfluss

```
Browser: GET /kollektion/sale?page=1&sort=price_asc
  ↓
app/kollektion/[slug]/page.tsx (RSC, revalidate=60)
  → params: { slug: "sale" }
  → searchParams: { page: "1", sort: "price_asc" }
  → Validierung: page >= 1, sort in allowed-list
  → loadPageConfig('collection', theme, { slug: "sale", page: "1", sort: "price_asc" })
  ↓
collection.yaml via resolveParams
  → $route.slug  → "sale"
  → $route.page  → "1"
  → $route.sort  → "price_asc"
  ↓
SectionRenderer iteriert sections
  ↓
  Section 1: collection-header (woocommerce, query: category_meta)
    → woocommerceLoader({ query: 'category_meta', slug: 'sale' })
    → GET_CATEGORY_META → { name: "Sale", description: "...", image: { sourceUrl, altText } }
    → CollectionHeaderBlock: Fullwidth-Header mit Bild + Titel + Beschreibung
  ↓
  Section 2: product-count (woocommerce, query: products_by_category)
    → woocommerceLoader({ query: 'products_by_category', slug: 'sale', page: 1, perPage: 24, sort: 'price_asc' })
    → GET_PRODUCTS_PAGINATED → PaginatedProductsResult { totalCount: 12, ... }
    → ProductCountBlock: "12 Produkte"
  ↓
  Section 3: product-grid (woocommerce, query: products_by_category)
    → Apollo RSC React.cache() dedup — kein zweiter Netzwerk-Call
    → ProductGridBlock: Grid mit 12 Produkt-Cards
  ↓
  Section 4: pagination (woocommerce, query: products_by_category)
    → Apollo RSC React.cache() dedup
    → PaginationBlock: "‹ 1 ›" (nur 1 Seite bei 12 Produkten)
  ↓
generateMetadata: title = "Sale | POD Shop", canonical = /kollektion/sale

---

Browser: GET /danke?order_id=12345&key=abc
  ↓
app/danke/page.tsx (RSC, static)
  → searchParams: { order_id: "12345" }
  → loadPageConfig('thanks', theme, { order_id: "12345" })
  ↓
thanks.yaml via resolveParams
  → $route.order_id → "12345"
  ↓
  Section 1: order-confirmation (inline)
    → OrderConfirmationBlock (Client Component)
    → Zeigt Texte aus YAML + order_id aus window.location.search (client-seitig hydration-safe)
    → Rendert: Checkmark-Icon, Headline, Bestellnummer #12345, Text, CTA-Button

---

Browser: GET /unbekannte-seite
  ↓
Next.js: kein Match in app/ → app/not-found.tsx (static)
  → Hardcoded JSX: 404, Headline, Text, zwei CTA-Links
  → generateMetadata: noindex
```

### 3. Block-Spezifikationen

#### 3.1 `collection-header` Block

**Typ:** Server Component
**Content Source:** `woocommerce` (query: `category_meta`)
**Data Type:** `CollectionHeaderData`

```
CollectionHeaderData (architecture.md, Block Data Type Definitions):
  name: string          → Kategorie-Name aus WooCommerce
  description: string   → HTML-Beschreibung (wird als dangerouslySetInnerHTML gerendert, WP sanitized)
  image?: {
    sourceUrl: string
    altText: string
  }
```

**Render-Regeln:**
- Container: `relative w-full` (Fullwidth-Header)
- Wenn `image` vorhanden: `<Image>` mit `fill`-Prop, `object-cover`, 40vh Mindesthöhe — dunkles Overlay (`bg-black/40`) für Lesbarkeit
- Wenn kein `image`: einfacher Container mit `bg-secondary` Hintergrund (Theme-Token)
- Titel: `<h1>` mit `text-3xl font-bold` — immer vorhanden
- Beschreibung: via `dangerouslySetInnerHTML={{ __html: data.description }}` — nur wenn nicht leer
- SEO-konform: `<h1>` exakt einmal pro Page (collection.yaml enthält keinen weiteren h1-Block)
- Skeleton: `CollectionHeaderBlockSkeleton` — Pulsierender Rechteck-Platzhalter (height: 40vh)

#### 3.2 `order-confirmation` Block

**Typ:** Client Component (`'use client'`)
**Content Source:** `inline` (Texte aus YAML, order_id aus URL client-seitig)
**Data Type:** `OrderConfirmationData`

```
OrderConfirmationData (architecture.md, Block Data Type Definitions):
  headline: string   → z.B. "Vielen Dank fuer deine Bestellung!"
  text: string       → Bestätigungstext
  emailText: string  → Text zu E-Mail-Bestätigung
  ctaText: string    → Button-Label, z.B. "Weiter einkaufen"
  ctaLink: string    → Button-Ziel, z.B. "/"
```

**Order ID Handling (Security-Entscheidung aus architecture.md):**
> "Order confirmation: URL params only — `order_id` + `key` in URL. No API call to fetch order details in MVP — only displays order ID from URL"

- `order_id` wird NICHT über RSC/YAML an den Client übergeben (um Hydration-Mismatch bei SSR zu vermeiden)
- Client-Component liest `order_id` via `new URLSearchParams(window.location.search).get('order_id')` in `useEffect`
- `useState<string | null>(null)` + Hydration-sicher via `suppressHydrationWarning` auf der Bestellnummer-Zeile
- Wenn `order_id` vorhanden: `#${orderId}` anzeigen
- Wenn `order_id` nicht vorhanden: Bestellnummer-Zeile weglassen (generischer Danke-Text)

**Render-Regeln:**
- Zentrierte Card: `max-w-lg mx-auto text-center py-16 px-8`
- Checkmark-Icon: `lucide-react <CheckCircle2>` (`w-16 h-16 text-green-500 mx-auto mb-6`, aria-hidden)
- Headline: `<h1>` mit `text-2xl font-bold text-text-primary`
- Bestellnummer: `<p>` mit `text-text-secondary` — nur wenn orderId gesetzt
- Text: `<p>` mit `text-text-secondary`
- E-Mail-Text: `<p>` mit `text-text-secondary text-sm`
- CTA-Button: `<Link href={data.ctaLink}>` mit `bg-primary text-white` Styling (touch-action: manipulation)
- Kein Skeleton (inline, instantan)

### 4. TypeScript Interfaces

> Alle Interfaces aus architecture.md Zeile 353-354 übernommen — keine Architecture Extension nötig.

```typescript
// frontend/lib/blocks/types.ts (Ergänzungen)

// CollectionHeaderData: architecture.md, Block Data Type Definitions
export interface CollectionHeaderData {
  name: string
  description: string
  image?: {
    sourceUrl: string
    altText: string
  }
}

// OrderConfirmationData: architecture.md, Block Data Type Definitions
export interface OrderConfirmationData {
  headline: string
  text: string
  emailText: string
  ctaText: string
  ctaLink: string
  // orderId wird NICHT in OrderConfirmationData aufgenommen:
  // wird client-seitig aus window.location.search gelesen (Hydration-Sicherheit)
}
```

> **Hinweis:** `WooCommerceLoaderParams.query` wird um `'category_meta'` erweitert, falls noch nicht durch Slice 1 (collection-header Dependency) geschehen. Dieser Slice dokumentiert es explizit als seinen Beitrag.

### 5. `collection_header` Branch in data-loaders.ts

> **Hinweis:** Der `category_meta` Branch wurde in Slice 1 als Dependency für `CollectionHeaderData` definiert (architecture.md). Dieser Slice *nutzt* ihn — er wird hier nicht neu implementiert, sondern referenziert.

Falls `category_meta` noch nicht in `woocommerceLoader` implementiert ist (Slice 1 hat ihn nur für `breadcrumb`/`featured-collection` implementiert), ergänzt dieser Slice den Branch:

```typescript
// lib/blocks/data-loaders.ts (Ergänzung falls nicht in Slice 1 enthalten)
} else if (params.query === 'category_meta') {
  const slug = params.slug as string
  const { data } = await getClient().query({
    query: GET_CATEGORY_META,
    variables: { slug },
  })
  const cat = data?.productCategory
  return {
    data: {
      name: cat?.name ?? '',
      description: cat?.description ?? '',
      image: cat?.image ? {
        sourceUrl: cat.image.sourceUrl,
        altText: cat.image.altText ?? cat.name ?? '',
      } : undefined,
    } satisfies CollectionHeaderData,
  }
}
```

### 6. `app/kollektion/[slug]/page.tsx` (RSC)

```typescript
// app/kollektion/[slug]/page.tsx
import { notFound, redirect } from 'next/navigation'
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import type { Metadata } from 'next'
import { getClient } from '@/lib/apollo/server-client'
import { GET_CATEGORY_META } from '@/lib/graphql/queries'
import { CollectionHeaderBlockSkeleton } from '@/components/blocks/collection-header-block'
import { ProductGridBlockSkeleton } from '@/components/blocks/product-grid-block'

export const revalidate = 60

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>
}): Promise<Metadata> {
  const { slug } = await params
  const { data } = await getClient().query({
    query: GET_CATEGORY_META,
    variables: { slug },
  })
  const category = data?.productCategory
  if (!category) {
    return { title: 'Kollektion nicht gefunden' }
  }
  const shopName = process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'
  return {
    title: `${category.name} | ${shopName}`,
    description: category.description
      ? category.description.replace(/<[^>]*>/g, '').slice(0, 160)
      : `${category.name} — Alle Produkte im ${shopName}`,
    alternates: {
      canonical: `/kollektion/${slug}`,
    },
  }
}

interface CollectionPageProps {
  params: Promise<{ slug: string }>
  searchParams: Promise<{ page?: string; sort?: string }>
}

export default async function CollectionPage({
  params,
  searchParams,
}: CollectionPageProps) {
  const { slug } = await params
  const { page: pageParam, sort: sortParam } = await searchParams
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'

  // Validierung
  const pageRaw = parseInt(pageParam ?? '1', 10)
  const page = isNaN(pageRaw) || pageRaw < 1 ? 1 : pageRaw
  const validSorts = ['price_asc', 'price_desc', 'newest']
  const sort = sortParam && validSorts.includes(sortParam) ? sortParam : ''

  // Redirect zu Seite 1 wenn page-Param ungültig
  if (pageParam !== undefined && page !== pageRaw) {
    redirect(`/kollektion/${slug}${sort ? `?sort=${sort}` : ''}`)
  }

  // Kategorie-Existenz prüfen (404 wenn nicht gefunden)
  const { data } = await getClient().query({
    query: GET_CATEGORY_META,
    variables: { slug },
  })
  if (!data?.productCategory) {
    notFound()
  }

  const pageConfig = loadPageConfig('collection', theme, {
    slug,
    page: String(page),
    sort,
  })

  return (
    <main id="main-content">
      <SectionRenderer
        sections={pageConfig.sections}
        skeletonMap={{
          'collection-header': <CollectionHeaderBlockSkeleton />,
          'product-count': null,
          'product-grid': <ProductGridBlockSkeleton />,
          'pagination': null,
        }}
      />
    </main>
  )
}
```

### 7. `app/danke/page.tsx` (RSC, static)

```typescript
// app/danke/page.tsx
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import type { Metadata } from 'next'

// Keine ISR — statische Seite, order_id wird client-seitig gelesen
export const dynamic = 'force-static'

export const metadata: Metadata = {
  title: 'Vielen Dank für deine Bestellung!',
  robots: { index: false, follow: false },
}

interface ThanksPageProps {
  searchParams: Promise<{ order_id?: string; key?: string }>
}

export default async function ThanksPage({ searchParams }: ThanksPageProps) {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  // order_id wird nur für loadPageConfig übergeben (wird in thanks.yaml nicht genutzt da client-seitig)
  // Dennoch übergeben damit resolveParams korrekt funktioniert, falls YAML es referenziert
  const { order_id } = await searchParams

  const pageConfig = loadPageConfig('thanks', theme, {
    order_id: order_id ?? '',
  })

  return (
    <main id="main-content" className="container mx-auto px-4 py-16">
      <SectionRenderer
        sections={pageConfig.sections}
        skeletonMap={{
          'order-confirmation': null,
        }}
      />
    </main>
  )
}
```

### 8. `app/not-found.tsx` (Next.js 404)

```typescript
// app/not-found.tsx
import Link from 'next/link'
import type { Metadata } from 'next'

export const metadata: Metadata = {
  title: 'Seite nicht gefunden | POD Shop',
  robots: { index: false, follow: false },
}

export default function NotFound() {
  return (
    <main
      id="main-content"
      className="container mx-auto px-4 py-24 text-center"
    >
      <p className="text-8xl font-bold text-primary mb-6" aria-hidden="true">
        404
      </p>
      <h1 className="text-3xl font-bold text-text-primary mb-4">
        Diese Seite wurde nicht gefunden
      </h1>
      <p className="text-text-secondary mb-8 max-w-md mx-auto">
        Der Link ist möglicherweise abgelaufen oder die Seite wurde verschoben.
      </p>
      <div className="flex flex-col sm:flex-row gap-4 justify-center">
        <Link
          href="/"
          className="px-6 py-3 bg-primary text-white rounded-card
                     hover:opacity-90 focus-visible:ring-2 focus-visible:ring-primary
                     focus-visible:ring-offset-2 touch-action-manipulation"
        >
          Zurück zur Startseite
        </Link>
        <Link
          href="/kategorie/alle"
          className="px-6 py-3 border border-border text-text-primary rounded-card
                     hover:bg-secondary focus-visible:ring-2 focus-visible:ring-primary
                     focus-visible:ring-offset-2 touch-action-manipulation"
        >
          Zum Shop
        </Link>
      </div>
    </main>
  )
}
```

---

## Wireframe-Beschreibung

### Desktop `/kollektion/sale` (1024px+)

```
┌─────────────────────────────────────────────────────────────┐
│ HEADER: Logo | Nav | [🔍 Suche] | Cart                      │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │  [Kategorie-Bild — Fullwidth, 40vh, dunkles Overlay]    │ │
│ │                                                         │ │
│ │         SALE                                            │ │
│ │         Unsere aktuellen Angebote                       │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│  12 Produkte                                                │
│                                                             │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐      │
│  │ [Bild]  │ │ [Bild]  │ │ [Bild]  │ │ [Bild]  │      │
│  │ Name    │ │ Name    │ │ Name    │ │ Name    │      │
│  │ 24,99 € │ │ 19,99 € │ │ 29,99 € │ │ 22,99 € │      │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘      │
│  [weitere 2 Zeilen Produkt-Cards]                           │
│                                                             │
│              ‹  1  ›                                        │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│ FOOTER                                                      │
└─────────────────────────────────────────────────────────────┘
```

### Mobile `/kollektion/sale` (< 768px)

```
┌───────────────────────────┐
│ Logo     [🔍]  [Cart]     │
├───────────────────────────┤
│                           │
│ ┌───────────────────────┐ │
│ │ [Kategorie-Bild]      │ │
│ │ SALE                  │ │
│ │ Unsere Angebote       │ │
│ └───────────────────────┘ │
│                           │
│ 12 Produkte               │
│                           │
│ ┌──────────┐ ┌──────────┐ │
│ │ [Bild]  │ │ [Bild]  │ │
│ │ Name    │ │ Name    │ │
│ │ 24,99 € │ │ 19,99 € │ │
│ └──────────┘ └──────────┘ │
│ ┌──────────┐ ┌──────────┐ │
│ │ [Bild]  │ │ [Bild]  │ │
│ └──────────┘ └──────────┘ │
│                           │
│         ‹  1  ›           │
│                           │
└───────────────────────────┘
```

### Desktop `/kollektion/[slug]` ohne Bild (kein Category Image)

```
┌─────────────────────────────────────────────────────────────┐
│ HEADER                                                      │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │  bg-secondary                                       │   │
│  │  NEUHEITEN                                          │   │
│  │  Die neuesten Produkte in unserem Shop              │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  8 Produkte                                                 │
│  [Produkt-Grid 4 Spalten]                                   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Desktop `/danke?order_id=12345`

```
┌─────────────────────────────────────────────────────────────┐
│ HEADER                                                      │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│                  [✓ Checkmark — grün, 64px]                 │
│                                                             │
│              Vielen Dank für deine Bestellung!              │
│                                                             │
│                    Bestellnummer: #12345                    │
│                                                             │
│         Deine Bestellbestätigung wurde an deine             │
│              E-Mail-Adresse gesendet.                       │
│                                                             │
│               [Weiter einkaufen → /]                        │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│ FOOTER                                                      │
└─────────────────────────────────────────────────────────────┘
```

### Desktop `/danke` (kein order_id)

```
│                  [✓ Checkmark — grün, 64px]                 │
│              Vielen Dank für deine Bestellung!              │
│                                                             │
│         Deine Bestellbestätigung wurde an deine             │
│              E-Mail-Adresse gesendet.                       │
│               [Weiter einkaufen → /]                        │
```
*(Bestellnummer-Zeile fehlt — kein order_id vorhanden)*

### Desktop `404`

```
┌─────────────────────────────────────────────────────────────┐
│ HEADER                                                      │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│                        404                                  │
│             (Grosse Zahl, text-primary, 96px)               │
│                                                             │
│           Diese Seite wurde nicht gefunden                  │
│                                                             │
│     Der Link ist möglicherweise abgelaufen oder             │
│          die Seite wurde verschoben.                        │
│                                                             │
│    [Zurück zur Startseite]    [Zum Shop]                    │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│ FOOTER                                                      │
└─────────────────────────────────────────────────────────────┘
```

---

## Acceptance Criteria

**AC1: Collections-Page rendert Kategorie-Header**
```
GIVEN GET /kollektion/sale wird aufgerufen
WHEN woocommerceLoader('category_meta', { slug: 'sale' }) eine Kategorie zurückgibt
THEN rendert CollectionHeaderBlock ein <h1> mit dem Kategorie-Namen
     UND das Kategorie-Bild wird als <img> oder <Image> gerendert (wenn vorhanden)
```

**AC2: Collections-Page ohne Bild rendert Fallback-Header**
```
GIVEN CollectionHeaderBlock wird mit CollectionHeaderData ohne image-Feld gerendert
WHEN die Komponente rendert
THEN ist ein <h1> mit dem Kategorie-Namen im DOM vorhanden
     UND kein <img>-Element ist vorhanden
```

**AC3: Collections-Page mit ungültigem Slug gibt 404**
```
GIVEN GET /kollektion/nicht-existierende-kategorie wird aufgerufen
WHEN GET_CATEGORY_META keine Kategorie zurückgibt (null)
THEN wird notFound() aufgerufen (CollectionPage wirft 404)
```

**AC4: Collections-Page ungültiger page-Param führt zu Redirect**
```
GIVEN GET /kollektion/sale?page=xyz wird aufgerufen
WHEN CollectionPage die searchParams liest und parseInt("xyz") NaN ergibt
THEN wird redirect("/kollektion/sale") aufgerufen
```

**AC5: Collections-Page generateMetadata nutzt Kategorie-Name**
```
GIVEN generateMetadata für /kollektion/sale wird aufgerufen
WHEN GET_CATEGORY_META die Kategorie "Sale" zurückgibt
THEN enthält metadata.title "Sale"
     UND metadata.alternates.canonical ist "/kollektion/sale"
```

**AC6: Danke-Page rendert Bestellnummer aus URL-Param**
```
GIVEN OrderConfirmationBlock rendert im Browser
WHEN window.location.search den Param "?order_id=12345" enthält
THEN ist der Text "#12345" nach useEffect-Hydration im DOM sichtbar
```

**AC7: Danke-Page ohne order_id zeigt generischen Text**
```
GIVEN OrderConfirmationBlock rendert mit leerem window.location.search
WHEN die Komponente vollständig gerendert ist
THEN ist kein "#" gefolgt von einer Zahl im DOM vorhanden
     UND data.headline ist im DOM vorhanden
```

**AC8: Danke-Page ist noindex**
```
GIVEN generateMetadata (oder metadata export) für /danke wird ausgewertet
WHEN Metadata abgerufen wird
THEN ist robots.index false
     UND robots.follow false
```

**AC9: 404-Page rendert mit korrekten CTA-Links**
```
GIVEN ein User navigiert zu einer nicht existierenden Route
WHEN not-found.tsx gerendert wird
THEN ist ein <h1> mit dem Text "Diese Seite wurde nicht gefunden" im DOM
     UND ein Link mit href="/" ist vorhanden
     UND ein Link mit href="/kategorie/alle" ist vorhanden
```

**AC10: 404-Page ist noindex**
```
GIVEN metadata export in app/not-found.tsx wird ausgewertet
WHEN robots-Metadaten abgerufen werden
THEN ist robots.index false
```

**AC11: CollectionHeaderBlock und OrderConfirmationBlock in registry.ts registriert**
```
GIVEN lib/blocks/registry.ts wird importiert
WHEN resolveBlock('collection-header') und resolveBlock('order-confirmation') aufgerufen werden
THEN geben beide eine React-Komponente zurück (nicht undefined)
```

---

## Integration Contract (GATE 2 PFLICHT)

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-cross-page-infrastruktur | `PaginationBlock` | React Component | IMPORTED — `components/blocks/pagination-block.tsx` existiert |
| slice-01-cross-page-infrastruktur | `loadGlobalConfig` | Function | IMPORTED — `lib/blocks/page-config.ts` exportiert die Funktion |
| slice-01-cross-page-infrastruktur | `GET_CATEGORY_META` | GraphQL Query | IMPORTED — `lib/graphql/queries.ts` exportiert die Query |
| slice-01-cross-page-infrastruktur | `category_meta` loader branch | Function | USED — `woocommerceLoader` in `lib/blocks/data-loaders.ts` hat diesen Branch (Slice 1 Deliverable) |
| slice-03-kategorie-page-enhancements | `products_by_category` loader branch | Function | USED — `woocommerceLoader` dispatch für paginierten Produkt-Fetch |
| slice-03-kategorie-page-enhancements | `PaginatedProductsResult` | TypeScript Interface | IMPORTED — `lib/blocks/types.ts` |
| slice-03-kategorie-page-enhancements | `PaginationMeta` | TypeScript Interface | IMPORTED — `lib/blocks/types.ts` |
| slice-03-kategorie-page-enhancements | `buildOrderby` | Function | USED (intern in data-loaders.ts) |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `/kollektion/[slug]` Route | Next.js Page | Navigation, global.yaml announcement-bar link, Homepage featured-collection CTA | GET `/kollektion/<slug>?page=<n>&sort=<sort>` |
| `/danke` Route | Next.js Page | WooCommerce Checkout Redirect (konfiguriert in WP-Admin) | GET `/danke?order_id=<id>&key=<key>` |
| `app/not-found.tsx` | Next.js 404 | Alle fehlerhaften Links im gesamten Shop | Automatisch via Next.js Routing |
| `CollectionHeaderBlock` | React Component | Zukünftige Slices / Shop-Themes | `BlockComponentProps<CollectionHeaderData>` |
| `OrderConfirmationBlock` | React Component | Zukünftige Post-Purchase Flows | `BlockComponentProps<OrderConfirmationData>` |
| `CollectionHeaderData` | TypeScript Interface | Zukünftige Slices / Shop-Themes | `{ name, description, image? }` |
| `OrderConfirmationData` | TypeScript Interface | Zukünftige Slices | `{ headline, text, emailText, ctaText, ctaLink }` |

### Breaking Changes

| Change | Affected | Migration |
|--------|----------|-----------|
| `app/not-found.tsx` NEU (ersetzt Standard-404) | Alle bestehenden unbekannten URLs | Kein Breaking Change — bisherige Behavior war ungebranded Next.js 404 |
| `registry.ts` um 2 neue Block-Typen erweitert | Keine bestehenden Blocks | Kein Breaking Change — additive Änderung |

---

## Testfälle

### Test-Datei
`tests/slices/shop-completeness/slice-06-neue-pages.test.ts`

<test_spec>
```typescript
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, act } from '@testing-library/react'

// ---------------------------------------------------------------------------
// Globale Mocks
// ---------------------------------------------------------------------------
vi.mock('next/navigation', () => ({
  notFound: vi.fn(),
  redirect: vi.fn(),
  useRouter: () => ({ push: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}))

vi.mock('@/lib/apollo/server-client', () => ({
  getClient: () => ({
    query: vi.fn().mockResolvedValue({ data: { productCategory: null } }),
  }),
}))

vi.mock('@/lib/blocks/page-config', () => ({
  loadPageConfig: vi.fn(() => ({ sections: [] })),
  loadGlobalConfig: vi.fn(() => ({ sections: [] })),
}))

vi.mock('@/lib/blocks/registry', () => ({
  resolveBlock: vi.fn((type: string) => {
    // Einfacher Mock: gibt eine Dummy-Komponente zurück wenn registriert
    const registered = ['collection-header', 'order-confirmation', 'product-grid', 'pagination', 'product-count']
    if (registered.includes(type)) {
      return () => null
    }
    return undefined
  }),
}))

// ---------------------------------------------------------------------------
// AC1: CollectionHeaderBlock rendert h1 mit Kategorie-Name + Bild
// ---------------------------------------------------------------------------
describe('CollectionHeaderBlock', () => {
  it('rendert <h1> mit Kategorie-Name wenn Daten vorhanden (AC1)', async () => {
    const { CollectionHeaderBlock } = await import(
      '@/components/blocks/collection-header-block'
    )
    render(
      <CollectionHeaderBlock
        data={{
          name: 'Sale',
          description: '<p>Unsere aktuellen Angebote</p>',
          image: {
            sourceUrl: 'https://example.com/sale-banner.jpg',
            altText: 'Sale Banner',
          },
        }}
      />
    )
    expect(screen.getByRole('heading', { level: 1 })).toBeDefined()
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Sale')
  })

  it('rendert ein img-Element wenn image vorhanden (AC1)', async () => {
    const { CollectionHeaderBlock } = await import(
      '@/components/blocks/collection-header-block'
    )
    render(
      <CollectionHeaderBlock
        data={{
          name: 'Sale',
          description: '',
          image: {
            sourceUrl: 'https://example.com/sale-banner.jpg',
            altText: 'Sale Banner',
          },
        }}
      />
    )
    // next/image rendert als <img> mit alt
    const img = screen.getByAltText('Sale Banner')
    expect(img).toBeDefined()
  })

  // AC2: CollectionHeaderBlock ohne Bild — kein img
  it('rendert kein img wenn image nicht vorhanden (AC2)', async () => {
    const { CollectionHeaderBlock } = await import(
      '@/components/blocks/collection-header-block'
    )
    render(
      <CollectionHeaderBlock
        data={{
          name: 'Neuheiten',
          description: '',
        }}
      />
    )
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Neuheiten')
    expect(screen.queryByRole('img')).toBeNull()
  })
})

// ---------------------------------------------------------------------------
// AC3: CollectionPage ruft notFound() auf wenn Kategorie nicht existiert
// ---------------------------------------------------------------------------
describe('CollectionPage (page.tsx)', () => {
  it('ruft notFound() auf wenn Kategorie nicht gefunden (AC3)', async () => {
    const mockGetClient = vi.fn().mockReturnValue({
      query: vi.fn().mockResolvedValue({ data: { productCategory: null } }),
    })
    const { getClient } = await import('@/lib/apollo/server-client')
    vi.mocked(getClient).mockImplementation(mockGetClient)

    const { notFound } = await import('next/navigation')

    const { default: CollectionPage } = await import(
      '@/app/kollektion/[slug]/page'
    )
    await CollectionPage({
      params: Promise.resolve({ slug: 'nicht-existent' }),
      searchParams: Promise.resolve({}),
    })

    expect(notFound).toHaveBeenCalled()
  })

  // AC4: Ungültiger page-Param → redirect
  it('ruft redirect() auf wenn page-Param kein valider Integer ist (AC4)', async () => {
    const mockGetClient = vi.fn().mockReturnValue({
      query: vi.fn().mockResolvedValue({
        data: {
          productCategory: {
            name: 'Sale',
            description: '',
            slug: 'sale',
            count: 12,
            image: null,
          },
        },
      }),
    })
    const { getClient } = await import('@/lib/apollo/server-client')
    vi.mocked(getClient).mockImplementation(mockGetClient)

    const { redirect } = await import('next/navigation')

    const { default: CollectionPage } = await import(
      '@/app/kollektion/[slug]/page'
    )
    await CollectionPage({
      params: Promise.resolve({ slug: 'sale' }),
      searchParams: Promise.resolve({ page: 'abc' }),
    })

    expect(redirect).toHaveBeenCalledWith('/kollektion/sale')
  })

  // AC5: generateMetadata nutzt Kategorie-Name und Canonical
  it('generateMetadata enthält Kategorie-Name im title und Canonical (AC5)', async () => {
    const mockGetClient = vi.fn().mockReturnValue({
      query: vi.fn().mockResolvedValue({
        data: {
          productCategory: {
            name: 'Sale',
            description: '<p>Angebote</p>',
            slug: 'sale',
            image: null,
          },
        },
      }),
    })
    const { getClient } = await import('@/lib/apollo/server-client')
    vi.mocked(getClient).mockImplementation(mockGetClient)

    const { generateMetadata } = await import('@/app/kollektion/[slug]/page')
    const metadata = await generateMetadata({
      params: Promise.resolve({ slug: 'sale' }),
    })

    expect(String(metadata.title)).toContain('Sale')
    expect(metadata.alternates?.canonical).toBe('/kollektion/sale')
  })
})

// ---------------------------------------------------------------------------
// AC6 & AC7: OrderConfirmationBlock — Bestellnummer aus window.location
// ---------------------------------------------------------------------------
describe('OrderConfirmationBlock', () => {
  const originalLocation = window.location

  beforeEach(() => {
    // window.location ist in jsdom nicht einfach überschreibbar — wir mock URLSearchParams
    vi.stubGlobal('URLSearchParams', class MockURLSearchParams {
      private params: Record<string, string>
      constructor(search: string) {
        this.params = {}
        if (search) {
          search.replace(/^\?/, '').split('&').forEach((pair) => {
            const [k, v] = pair.split('=')
            if (k) this.params[k] = decodeURIComponent(v ?? '')
          })
        }
      }
      get(key: string) { return this.params[key] ?? null }
    })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('zeigt Bestellnummer nach Hydration wenn order_id in URL vorhanden (AC6)', async () => {
    // window.location.search auf ?order_id=12345 setzen
    Object.defineProperty(window, 'location', {
      value: { ...originalLocation, search: '?order_id=12345' },
      writable: true,
    })

    const { OrderConfirmationBlock } = await import(
      '@/components/blocks/order-confirmation-block'
    )
    render(
      <OrderConfirmationBlock
        data={{
          headline: 'Vielen Dank!',
          text: 'Deine Bestellung ist eingegangen.',
          emailText: 'Du erhältst eine E-Mail.',
          ctaText: 'Weiter einkaufen',
          ctaLink: '/',
        }}
      />
    )

    // Nach act() ist useEffect ausgeführt
    await act(async () => {})
    expect(screen.getByText(/\#12345/)).toBeDefined()
  })

  it('zeigt keine Bestellnummer wenn order_id fehlt (AC7)', async () => {
    Object.defineProperty(window, 'location', {
      value: { ...originalLocation, search: '' },
      writable: true,
    })

    const { OrderConfirmationBlock } = await import(
      '@/components/blocks/order-confirmation-block'
    )
    render(
      <OrderConfirmationBlock
        data={{
          headline: 'Vielen Dank!',
          text: 'Deine Bestellung ist eingegangen.',
          emailText: 'Du erhältst eine E-Mail.',
          ctaText: 'Weiter einkaufen',
          ctaLink: '/',
        }}
      />
    )

    await act(async () => {})
    expect(screen.queryByText(/\#\d+/)).toBeNull()
    expect(screen.getByText('Vielen Dank!')).toBeDefined()
  })

  // AC8: Danke-Page ist noindex
  it('Danke-Page metadata enthält robots noindex (AC8)', async () => {
    const { metadata } = await import('@/app/danke/page')
    // metadata kann auch ein Objekt-Export sein
    expect(metadata).toBeDefined()
    const robots = (metadata as { robots?: { index?: boolean } }).robots
    expect(robots?.index).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// AC9: not-found.tsx rendert h1 und CTA-Links
// ---------------------------------------------------------------------------
describe('NotFound (not-found.tsx)', () => {
  it('rendert h1 "Diese Seite wurde nicht gefunden" und zwei CTA-Links (AC9)', async () => {
    const { default: NotFound } = await import('@/app/not-found')
    render(<NotFound />)

    expect(
      screen.getByRole('heading', { level: 1 })
    ).toBeDefined()
    expect(
      screen.getByRole('heading', { level: 1 }).textContent
    ).toContain('Diese Seite wurde nicht gefunden')

    const links = screen.getAllByRole('link')
    const hrefs = links.map((l) => l.getAttribute('href'))
    expect(hrefs).toContain('/')
    expect(hrefs).toContain('/kategorie/alle')
  })

  // AC10: 404-Page ist noindex
  it('not-found.tsx metadata enthält robots noindex (AC10)', async () => {
    const notFoundModule = await import('@/app/not-found')
    const meta = notFoundModule.metadata as { robots?: { index?: boolean } }
    expect(meta.robots?.index).toBe(false)
  })
})

// ---------------------------------------------------------------------------
// AC11: registry.ts enthält collection-header und order-confirmation
// ---------------------------------------------------------------------------
describe('Block Registry', () => {
  it('resolveBlock gibt Komponente für collection-header zurück (AC11)', async () => {
    // Echter Import (nicht gemockt) für diesen Test
    vi.resetModules()
    const { resolveBlock } = await import('@/lib/blocks/registry')
    const component = resolveBlock('collection-header')
    expect(component).toBeDefined()
    expect(typeof component).toBe('function')
  })

  it('resolveBlock gibt Komponente für order-confirmation zurück (AC11)', async () => {
    vi.resetModules()
    const { resolveBlock } = await import('@/lib/blocks/registry')
    const component = resolveBlock('order-confirmation')
    expect(component).toBeDefined()
    expect(typeof component).toBe('function')
  })
})
```
</test_spec>

---

## Code Examples (MANDATORY — GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind PFLICHT-Deliverables.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `CollectionHeaderBlock` | UI — Server Component | YES | h1, Image mit Fallback, dangerouslySetInnerHTML für description |
| `OrderConfirmationBlock` | UI — Client Component | YES | useEffect für order_id, suppressHydrationWarning, CheckCircle2-Icon |
| `collection.yaml` | YAML Config | YES | Exakt wie in architecture.md spezifiziert |
| `thanks.yaml` | YAML Config | YES | Exakt wie in architecture.md spezifiziert |
| `app/kollektion/[slug]/page.tsx` | Route | YES | generateMetadata mit Kategorie-Name, notFound(), Redirect-Guard |
| `app/danke/page.tsx` | Route | YES | static export, noindex metadata |
| `app/not-found.tsx` | 404 | YES | h1, zwei CTA-Links, noindex metadata |
| `CollectionHeaderData` + `OrderConfirmationData` | Types | YES | Exakt wie architecture.md |

### Code Example 1: `CollectionHeaderBlock`

```typescript
// frontend/components/blocks/collection-header-block.tsx
import Image from 'next/image'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { CollectionHeaderData } from '@/lib/blocks/types'

export function CollectionHeaderBlock({
  data,
}: BlockComponentProps<CollectionHeaderData>) {
  return (
    <div className="relative w-full min-h-[40vh] flex items-end">
      {data.image ? (
        <>
          <Image
            src={data.image.sourceUrl}
            alt={data.image.altText}
            fill
            className="object-cover"
            priority
          />
          {/* Dunkles Overlay für Lesbarkeit */}
          <div className="absolute inset-0 bg-black/40" aria-hidden="true" />
        </>
      ) : (
        <div className="absolute inset-0 bg-secondary" aria-hidden="true" />
      )}
      <div className="relative z-10 container mx-auto px-4 py-10">
        <h1
          className={`text-3xl md:text-5xl font-bold ${
            data.image ? 'text-white' : 'text-text-primary'
          }`}
        >
          {data.name}
        </h1>
        {data.description && (
          <div
            className={`mt-3 text-lg max-w-2xl ${
              data.image ? 'text-white/90' : 'text-text-secondary'
            }`}
            dangerouslySetInnerHTML={{ __html: data.description }}
          />
        )}
      </div>
    </div>
  )
}

export function CollectionHeaderBlockSkeleton() {
  return (
    <div className="relative w-full min-h-[40vh] bg-gray-100 animate-pulse" />
  )
}
```

### Code Example 2: `OrderConfirmationBlock`

```typescript
// frontend/components/blocks/order-confirmation-block.tsx
'use client'

import { useState, useEffect } from 'react'
import Link from 'next/link'
import { CheckCircle2 } from 'lucide-react'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { OrderConfirmationData } from '@/lib/blocks/types'

export function OrderConfirmationBlock({
  data,
}: BlockComponentProps<OrderConfirmationData>) {
  const [orderId, setOrderId] = useState<string | null>(null)

  useEffect(() => {
    // Client-seitig: order_id aus URL lesen (Hydration-sicher)
    const params = new URLSearchParams(window.location.search)
    const id = params.get('order_id')
    if (id) setOrderId(id)
  }, [])

  return (
    <div className="max-w-lg mx-auto text-center py-16 px-8">
      <CheckCircle2
        className="w-16 h-16 text-green-500 mx-auto mb-6"
        aria-hidden="true"
      />
      <h1 className="text-2xl font-bold text-text-primary mb-4">
        {data.headline}
      </h1>
      {orderId && (
        <p className="text-text-secondary mb-2" suppressHydrationWarning>
          Bestellnummer: #{orderId}
        </p>
      )}
      <p className="text-text-secondary mb-2">{data.text}</p>
      <p className="text-text-secondary text-sm mb-8">{data.emailText}</p>
      <Link
        href={data.ctaLink}
        className="inline-block px-6 py-3 bg-primary text-white rounded-card
                   hover:opacity-90 focus-visible:ring-2 focus-visible:ring-primary
                   focus-visible:ring-offset-2 touch-action-manipulation"
      >
        {data.ctaText}
      </Link>
    </div>
  )
}
```

### Code Example 3: `themes/default/pages/collection.yaml`

```yaml
# themes/default/pages/collection.yaml
sections:
  - columns: 1
    blocks:
      - type: collection-header
        content_source: woocommerce
        params:
          query: category_meta
          slug: "$route.slug"
  - columns: 1
    blocks:
      - type: product-count
        content_source: woocommerce
        params:
          query: products_by_category
          slug: "$route.slug"
          page: "$route.page"
          perPage: 24
          sort: "$route.sort"
  - columns: 1
    blocks:
      - type: product-grid
        content_source: woocommerce
        params:
          query: products_by_category
          slug: "$route.slug"
          page: "$route.page"
          perPage: 24
          sort: "$route.sort"
  - columns: 1
    blocks:
      - type: pagination
        content_source: woocommerce
        params:
          query: products_by_category
          slug: "$route.slug"
          page: "$route.page"
          perPage: 24
          sort: "$route.sort"
```

### Code Example 4: `themes/default/pages/thanks.yaml`

```yaml
# themes/default/pages/thanks.yaml
sections:
  - columns: 1
    blocks:
      - type: order-confirmation
        content_source: inline
        params:
          props:
            headline: "Vielen Dank fuer deine Bestellung!"
            text: "Deine Bestellbestaetigung wurde an deine E-Mail-Adresse gesendet."
            emailText: "Du erhaeltst in Kuerze eine Bestaetigung per E-Mail."
            ctaText: "Weiter einkaufen"
            ctaLink: "/"
```

### Code Example 5: TypeScript Interfaces (lib/blocks/types.ts Ergänzungen)

```typescript
// frontend/lib/blocks/types.ts (Ergänzungen)

export interface CollectionHeaderData {
  name: string
  description: string
  image?: {
    sourceUrl: string
    altText: string
  }
}

export interface OrderConfirmationData {
  headline: string
  text: string
  emailText: string
  ctaText: string
  ctaLink: string
  // orderId wird NICHT hier definiert — wird client-seitig aus window.location.search gelesen
}
```

---

<!-- DELIVERABLES_START -->
## Deliverables (SCOPE SAFEGUARD)

### Neue Dateien (Frontend)

- [ ] `frontend/app/kollektion/[slug]/page.tsx` — RSC Route `/kollektion/[slug]`, liest `params.slug` + `searchParams.page`/`sort`, `notFound()` bei ungültigem Slug, Redirect-Guard für ungültige page, `loadPageConfig('collection')`, `generateMetadata` mit Kategorie-Name und Canonical-URL
- [ ] `frontend/app/danke/page.tsx` — RSC Route `/danke` (static), `metadata` Export mit noindex, liest `searchParams.order_id`, `loadPageConfig('thanks')`
- [ ] `frontend/app/not-found.tsx` — Next.js 404-Handler, hardcoded JSX (kein Block-System), `<h1>`, zwei CTA-Links (`/` und `/kategorie/alle`), `metadata` Export mit noindex
- [ ] `frontend/components/blocks/collection-header-block.tsx` — Server Component, `CollectionHeaderData`, `<h1>` mit Kategorie-Name, `<Image>` mit dunklem Overlay (wenn image vorhanden), `<div bg-secondary>` Fallback (kein Bild), `dangerouslySetInnerHTML` für description, exportiert `CollectionHeaderBlockSkeleton`
- [ ] `frontend/components/blocks/order-confirmation-block.tsx` — Client Component (`'use client'`), `OrderConfirmationData`, `useEffect` liest `window.location.search` für `order_id`, `useState<string | null>`, `<CheckCircle2>` Icon, `<h1>`, Bestellnummer-Zeile (nur wenn orderId), CTA-Link
- [ ] `frontend/themes/default/pages/collection.yaml` — YAML-Konfiguration exakt wie in Code Example 3 (4 Sections: collection-header, product-count, product-grid, pagination)
- [ ] `frontend/themes/default/pages/thanks.yaml` — YAML-Konfiguration exakt wie in Code Example 4 (1 Section: order-confirmation)
- [ ] `tests/slices/shop-completeness/slice-06-neue-pages.test.ts` — Vitest-Testdatei mit allen 11 ACs

### Modifizierte Dateien (Frontend)

- [ ] `frontend/lib/blocks/registry.ts` — `collection-header` und `order-confirmation` Block-Typen registrieren (Import + Eintrag in Registry-Map)
- [ ] `frontend/lib/blocks/types.ts` — `CollectionHeaderData` Interface hinzufügen; `OrderConfirmationData` Interface hinzufügen; `WooCommerceLoaderParams.query` Union um `'category_meta'` ergänzen (falls noch nicht in Slice 1 enthalten)
- [ ] `frontend/lib/blocks/data-loaders.ts` — `category_meta` Branch prüfen und ggf. ergänzen (falls Slice 1 ihn nicht implementiert hat): `GET_CATEGORY_META` → `CollectionHeaderData` transform

### YAML-Konfiguration

- [ ] `frontend/themes/default/pages/collection.yaml` — NEU, Inhalt exakt wie in Code Example 3
- [ ] `frontend/themes/default/pages/thanks.yaml` — NEU, Inhalt exakt wie in Code Example 4

### Tests

- [ ] `tests/slices/shop-completeness/slice-06-neue-pages.test.ts` — NEU, alle ACs abgedeckt (AC1–AC11)

<!-- DELIVERABLES_END -->

---

## Definition of Done

| Kriterium | Prüfung |
|-----------|---------|
| Alle 11 ACs haben einen korrespondierenden Testfall | 1:1 Mapping AC → `it(...)` Block |
| `pnpm test tests/slices/shop-completeness/slice-06-neue-pages.test.ts` läuft durch ohne Fehler | CI-grün |
| `/kollektion/sale` Route antwortet mit HTTP 200 (mit valider WC-Kategorie) | `curl http://localhost:3000/kollektion/sale` |
| `/kollektion/nicht-existent` gibt 404 zurück | `curl -I http://localhost:3000/kollektion/nicht-existent` → 404 |
| `/danke?order_id=12345` zeigt Bestellnummer #12345 | Manuelle Verifikation im Browser (nach Hydration) |
| `/danke` (ohne order_id) zeigt generischen Danke-Text ohne Bestellnummer | Manuelle Verifikation |
| Ungültige URL (z.B. `/xyz-keine-route`) zeigt gebrandete 404-Page | Manuelle Verifikation |
| `collection-header` und `order-confirmation` in `registry.ts` registriert | `grep -n "collection-header\|order-confirmation" frontend/lib/blocks/registry.ts` |
| `collection.yaml` und `thanks.yaml` existieren im Pfad `themes/default/pages/` | `ls frontend/themes/default/pages/` |
| `pnpm build` ohne TypeScript-Fehler | CI-Build grün |
| Alle Tailwind-Klassen sind Theme-Tokens (kein hardcoded Farben, kein `text-gray-900`, kein `bg-white`) | Code Review |
| `CollectionHeaderBlock` rendert `<h1>` exakt einmal pro Collections-Page | HTML-Inspektion |
| Danke-Page und 404-Page sind noindex | HTML-Inspektion via `curl` oder Browser-DevTools |
| `collection.yaml` nutzt `$route.slug`, `$route.page`, `$route.sort` korrekt | YAML-Review |
| Footer-Links zu `/kollektion/*` navigieren korrekt | Manuelle Verifikation (sofern Footer-Links in anderen Slices/Konfigurationen vorhanden) |
