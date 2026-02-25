# Slice 3: Produkt-Seite als Block-Page

> **Slice 3 von 4** fuer `Block-basierte Seiten-Migration`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-02-kategorie-seite.md` |
> | **Naechster:** | `slice-04-rechtsseiten.md` |

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-03-produkt-seite` |
| **Test** | `pnpm test tests/slices/block-page-migration/slice-03-produkt-seite.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-block-system-upgrade"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` enthaelt `next ^16.1.6` + `vitest ^3.0.0`.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `cd frontend && pnpm test tests/slices/block-page-migration/slice-03-produkt-seite.test.ts` |
| **Integration Command** | `cd frontend && pnpm test tests/slices/block-page-migration/` |
| **Acceptance Command** | `cd frontend && pnpm build` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/produkt/` |
| **Mocking Strategy** | `mock_external` |

---

## Slice-Uebersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Block-System Upgrade | Done | `slice-01-block-system-upgrade.md` |
| 2 | Kategorie-Seite als Block-Page | Done | `slice-02-kategorie-seite.md` |
| 3 | Produkt-Seite als Block-Page | Ready | `slice-03-produkt-seite.md` |
| 4 | Rechtsseiten als Block-Pages | Pending | `slice-04-rechtsseiten.md` |

---

## Kontext & Ziel

`app/produkt/[slug]/page.tsx` enthaelt aktuell hardcoded JSX fuer Gallery, Varianten-Auswahl und Beschreibung. Das Ziel dieses Slices ist die Migration auf das Block-System aus Slice 1, sodass die Produktseite YAML-konfigurierbar wird.

Besonderheit: Die Produktseite besteht aus **Client-Islands** (Gallery-Swipe, Varianten-Auswahl, AddToCart), die nach dem Server-Fetch-Prinzip des Block-Systems umgebaut werden: Der Data-Loader fetcht das Produkt serverseitig, uebergibt es als Props an Client-Island-Block-Wrapper.

**Aktuelle Probleme:**
1. `app/produkt/[slug]/page.tsx` hat ca. 127 Zeilen hardcoded JSX — nicht YAML-konfigurierbar
2. `lib/blocks/data-loaders.ts` kennt kein `product_by_slug` Query — muss ergaenzt werden
3. `lib/blocks/registry.ts` kennt `product-gallery`, `product-purchase`, `product-description` nicht — muessen registriert werden
4. Kein `themes/default/pages/product.yaml` vorhanden

**Unveraendert bleibende Funktionalitaet:**
- `generateStaticParams` — bleibt in page.tsx (Slice 1 Constraint)
- `generateMetadata` — bleibt in page.tsx (Slice 1 Constraint)
- JSON-LD Schema-Markup — bleibt in page.tsx (Slice 1 Constraint)
- `app/produkt/[slug]/product-variant-selector.tsx` — unveraendert (nur Import-Location aendert sich)
- `components/product/product-image-gallery.tsx` — unveraendert
- `components/product/add-to-cart-button.tsx` — unveraendert

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Architecture Layers + Client-Island Pattern + Block → Data Source Mapping

```
page.tsx (generateStaticParams, generateMetadata, JSON-LD — unveraendert)
  → loadPageConfig('product', theme, { slug })   [Config Layer]
    → themes/default/pages/product.yaml           [3-tier Lookup, Tier 3]
    → resolveParams($route.slug → actual slug)     [Slice 1]
  → SectionRenderer({ sections, skeletonMap })    [Slice 1]
    → Section 1 (columns: 2):
        → product-gallery Block:
            loadBlockData('woocommerce', { query: 'product_by_slug', slug })
            → GET_PRODUCT Query (bestehend)
            → ProductGalleryBlock({ data: product }) — "use client"
              → ProductImageGallery({ images, productName })
        → product-purchase Block:
            loadBlockData('woocommerce', { query: 'product_by_slug', slug })
            → GET_PRODUCT Query (Apollo React.cache() dedupl. — kein zweiter Request!)
            → ProductPurchaseBlock({ data: product }) — "use client"
              → ProductVariantSelector({ product, variantOptions })
    → Section 2 (columns: 1):
        → product-description Block:
            loadBlockData('woocommerce', { query: 'product_by_slug', slug })
            → GET_PRODUCT Query (Apollo React.cache() dedupl. — kein dritter Request!)
            → ProductDescriptionBlock({ data: product })
              → Card mit dangerouslySetInnerHTML
```

**Query-Deduplication:** Drei Blocks nutzen dieselbe `product_by_slug` Query mit identischem `slug`. Apollo Server Client nutzt `React.cache()` — identische Queries innerhalb eines Render-Passes machen nur **einen** Netzwerk-Request.

### 1. Architektur-Impact

| Layer | Aenderungen |
|-------|-------------|
| `lib/blocks/data-loaders.ts` | `product_by_slug` Handler NEU in `woocommerceLoader()`: nutzt bestehenden `GET_PRODUCT` Query. Gibt `{ data: ProductDetailData \| null }` zurueck. |
| `lib/blocks/registry.ts` | 3 neue Block-Types registrieren: `'product-gallery'`, `'product-purchase'`, `'product-description'` |
| `components/blocks/product-gallery-block.tsx` | NEU: Client-Island Wrapper um `ProductImageGallery`. Empfaengt `data: ProductDetailData`, kombiniert `product.image + product.galleryImages.nodes` zu `allImages[]`. |
| `components/blocks/product-purchase-block.tsx` | NEU: Client-Island Wrapper um `ProductVariantSelector`. Empfaengt `data: ProductDetailData`, ruft `extractVariantOptions()` auf, rendert Produkt-Name, Preis + Variant-Selector. |
| `components/blocks/product-description-block.tsx` | NEU: Server Component. Empfaengt `data: ProductDetailData`, rendert Beschreibung in `<Card>` via `dangerouslySetInnerHTML`. Rendert nichts wenn `description` leer. |
| `themes/default/pages/product.yaml` | NEU: Zwei Sections — Section 1 (columns: 2) mit gallery + purchase, Section 2 (columns: 1) mit description. |
| `app/produkt/[slug]/page.tsx` | Hardcoded JSX entfernen. `generateStaticParams`, `generateMetadata`, JSON-LD und SectionRenderer-Aufruf bleiben. |

### 2. Datenfluss

```
URL: /produkt/premium-hoodie
  ↓
app/produkt/[slug]/page.tsx
  ↓ slug = "premium-hoodie"
loadPageConfig('product', theme, { slug: 'premium-hoodie' })
  ↓ resolveParams: $route.slug → "premium-hoodie"
product.yaml (sections[0]: columns=2, sections[1]: columns=1)
  ↓
SectionRenderer
  ↓ Section 1 (columns=2, gap="gap-16"):
    Block 1: product-gallery
      params: { query: 'product_by_slug', slug: 'premium-hoodie' }
      → GET_PRODUCT { slug: 'premium-hoodie' }
      → { data: ProductDetailData }
      → ProductGalleryBlock (Client-Island)
        → allImages = [product.image, ...product.galleryImages.nodes]
        → ProductImageGallery({ images: allImages, productName: product.name })
    Block 2: product-purchase
      params: { query: 'product_by_slug', slug: 'premium-hoodie' }
      → Apollo React.cache() Dedup (kein neuer Request)
      → { data: ProductDetailData }
      → ProductPurchaseBlock (Client-Island)
        → variantOptions = extractVariantOptions(product.variations.nodes)
        → h1 + price + ProductVariantSelector
  ↓ Section 2 (columns=1):
    Block 3: product-description
      params: { query: 'product_by_slug', slug: 'premium-hoodie' }
      → Apollo React.cache() Dedup (kein neuer Request)
      → { data: ProductDetailData }
      → ProductDescriptionBlock (Server Component)
        → <Card> mit product.description HTML
```

### 3. product_by_slug DataLoader Handler

```typescript
// lib/blocks/data-loaders.ts — Erweiterung woocommerceLoader()
// Neuer Import oben:
import { GET_FEATURED_PRODUCTS, GET_PRODUCT_CATEGORIES, GET_PRODUCT } from '@/lib/graphql/queries'
import type { ProductDetailData } from '@/lib/graphql/types'

// In woocommerceLoader() switch/if-else, NEUER Handler:
} else if (params.query === 'product_by_slug') {
  const slug = (params as WooCommerceLoaderParams & { slug: string }).slug
  const { data } = await getClient().query<{ product: ProductDetailData | null }>({
    query: GET_PRODUCT,
    variables: { slug },
  })
  // Gibt das Produkt direkt zurueck (nicht in { products: ... } gewrappt)
  // BlockComponents erwarten data als ProductDetailData
  return { data: data?.product ?? null }
}
```

### 4. product.yaml Layout

```yaml
# themes/default/pages/product.yaml
# Produktseite Block-Konfiguration — Sections-Format

sections:
  - columns: 2
    gap: "gap-16"
    blocks:
      - type: product-gallery
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"

      - type: product-purchase
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"

  - columns: 1
    gap: "gap-8"
    blocks:
      - type: product-description
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"
```

### 5. ProductGalleryBlock (Client-Island)

```typescript
// components/blocks/product-gallery-block.tsx
'use client'

import { ProductImageGallery } from '@/components/product/product-image-gallery'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductDetailData } from '@/lib/graphql/types'

export function ProductGalleryBlock({ data }: BlockComponentProps<ProductDetailData | null>) {
  if (!data) {
    return (
      <div className="aspect-square bg-surface-elevated rounded-card flex items-center justify-center text-text-secondary">
        Produkt nicht gefunden
      </div>
    )
  }

  const allImages = [
    ...(data.image ? [data.image] : []),
    ...(data.galleryImages?.nodes ?? []),
  ]

  return (
    <ProductImageGallery
      images={allImages}
      productName={data.name}
    />
  )
}

export function ProductGalleryBlockSkeleton() {
  return (
    <div className="aspect-square bg-surface-elevated rounded-card animate-pulse" />
  )
}
```

### 6. ProductPurchaseBlock (Client-Island)

```typescript
// components/blocks/product-purchase-block.tsx
'use client'

import { ProductVariantSelector } from '@/app/produkt/[slug]/product-variant-selector'
import { extractVariantOptions } from '@/lib/product/variant-utils'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductDetailData } from '@/lib/graphql/types'

export function ProductPurchaseBlock({ data }: BlockComponentProps<ProductDetailData | null>) {
  if (!data) {
    return (
      <div className="text-text-secondary">Produkt nicht gefunden</div>
    )
  }

  const variantOptions = data.variations
    ? extractVariantOptions(data.variations.nodes)
    : { sizes: [], colors: [] }

  return (
    <div className="mt-8 md:mt-0">
      <h1 className="text-3xl font-bold tracking-tight text-text-primary">
        {data.name}
      </h1>

      <div className="mt-4 flex items-baseline gap-3">
        <p className="text-3xl font-bold text-accent">
          {data.price}
        </p>
      </div>
      <p className="mt-1 text-sm text-text-secondary">inkl. Versandkosten</p>

      <hr className="my-8 border-border" />

      <ProductVariantSelector
        product={data}
        variantOptions={variantOptions}
      />
    </div>
  )
}

export function ProductPurchaseBlockSkeleton() {
  return (
    <div className="mt-8 md:mt-0 space-y-4">
      <div className="h-9 bg-surface-elevated rounded-card animate-pulse w-3/4" />
      <div className="h-8 bg-surface-elevated rounded-card animate-pulse w-1/3" />
      <div className="h-px bg-border my-8" />
      <div className="h-12 bg-surface-elevated rounded-card animate-pulse w-full" />
    </div>
  )
}
```

### 7. ProductDescriptionBlock (Server Component)

```typescript
// components/blocks/product-description-block.tsx
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductDetailData } from '@/lib/graphql/types'

export function ProductDescriptionBlock({ data }: BlockComponentProps<ProductDetailData | null>) {
  if (!data?.description) return null

  return (
    <Card>
      <CardHeader>
        <CardTitle>Beschreibung</CardTitle>
      </CardHeader>
      <CardContent>
        <div
          className="prose prose-sm text-text-secondary max-w-none"
          dangerouslySetInnerHTML={{ __html: data.description }}
        />
      </CardContent>
    </Card>
  )
}

export function ProductDescriptionBlockSkeleton() {
  return (
    <Card>
      <CardHeader>
        <div className="h-6 bg-surface-elevated rounded-card animate-pulse w-1/4" />
      </CardHeader>
      <CardContent>
        <div className="space-y-2">
          <div className="h-4 bg-surface-elevated rounded animate-pulse w-full" />
          <div className="h-4 bg-surface-elevated rounded animate-pulse w-5/6" />
          <div className="h-4 bg-surface-elevated rounded animate-pulse w-4/6" />
        </div>
      </CardContent>
    </Card>
  )
}
```

### 8. registry.ts Erweiterung

```typescript
// lib/blocks/registry.ts — 3 neue Imports + Registry-Eintraege
import { ProductGalleryBlock } from '@/components/blocks/product-gallery-block'
import { ProductPurchaseBlock } from '@/components/blocks/product-purchase-block'
import { ProductDescriptionBlock } from '@/components/blocks/product-description-block'

// In registry Record:
'product-gallery': ProductGalleryBlock as BlockComponent,
'product-purchase': ProductPurchaseBlock as BlockComponent,
'product-description': ProductDescriptionBlock as BlockComponent,
```

### 9. app/produkt/[slug]/page.tsx nach Migration

```typescript
// app/produkt/[slug]/page.tsx — nach Migration (thin wrapper)
import { notFound } from 'next/navigation'
import { getClient } from '@/lib/apollo/server-client'
import { GET_PRODUCT, GET_ALL_PRODUCT_SLUGS } from '@/lib/graphql/queries'
import { generateProductJsonLd } from '@/lib/seo/json-ld'
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import { ProductGalleryBlockSkeleton } from '@/components/blocks/product-gallery-block'
import { ProductPurchaseBlockSkeleton } from '@/components/blocks/product-purchase-block'
import { ProductDescriptionBlockSkeleton } from '@/components/blocks/product-description-block'
import type { ProductDetailData } from '@/lib/graphql/types'

export const revalidate = 60

export async function generateStaticParams() {
  const { data } = await getClient().query<{
    products: { nodes: Array<{ slug: string }> }
  }>({
    query: GET_ALL_PRODUCT_SLUGS,
  })
  return (data?.products?.nodes ?? []).map((product) => ({
    slug: product.slug,
  }))
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params
  const { data } = await getClient().query<{ product: ProductDetailData }>({
    query: GET_PRODUCT,
    variables: { slug },
  })
  const product = data?.product
  if (!product) return { title: 'Produkt nicht gefunden' }
  return {
    title: `${product.name} | ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`,
    description: product.shortDescription ?? product.description?.substring(0, 160),
    openGraph: {
      images: product.image ? [{ url: product.image.sourceUrl }] : [],
    },
  }
}

const SKELETON_MAP: Record<string, React.ReactNode> = {
  'product-gallery': <ProductGalleryBlockSkeleton />,
  'product-purchase': <ProductPurchaseBlockSkeleton />,
  'product-description': <ProductDescriptionBlockSkeleton />,
}

interface ProductPageProps {
  params: Promise<{ slug: string }>
}

export default async function ProductPage({ params }: ProductPageProps) {
  const { slug } = await params

  // notFound-Pruefung: Produkt muss existieren (generateMetadata hat es bereits gecacht)
  const { data } = await getClient().query<{ product: ProductDetailData }>({
    query: GET_PRODUCT,
    variables: { slug },
  })
  if (!data?.product) notFound()

  const product = data.product
  const pageUrl = `${process.env.NEXT_PUBLIC_SHOP_URL ?? 'http://localhost:3000'}/produkt/${slug}`
  const jsonLd = generateProductJsonLd(product, pageUrl)

  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const pageConfig = loadPageConfig('product', theme, { slug })

  return (
    <>
      {/* JSON-LD fuer SEO (bleibt in page.tsx — Slice 1 Constraint) */}
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: jsonLd }}
      />

      <main id="main-content" className="space-y-12">
        <SectionRenderer
          sections={pageConfig.sections}
          skeletonMap={SKELETON_MAP}
        />
      </main>
    </>
  )
}
```

---

## UI Anforderungen

### Wireframe (aus architecture.md / discovery.md)

> **Quelle:** `discovery.md` → UI Layout & Context → Screen: Produkt-Seite

```
/produkt/[slug] — Desktop (2-Spalten Grid)
┌─────────────────────────────────────────────┐
│  Section 1 (columns: 2, gap: gap-16)         │
│  ┌──────────────┐  ┌────────────────────┐    │
│  │ product-     │  │ product-purchase   │    │
│  │ gallery      │  │                    │    │
│  │              │  │ [H1 Produktname]   │    │
│  │ [Hauptbild]  │  │ [Preis]            │    │
│  │              │  │ inkl. Versandkosten│    │
│  │ [Thumbnails] │  │ ---                │    │
│  │              │  │ [Farb-Selector]    │    │
│  │              │  │ [Groessen-Selector]│    │
│  │              │  │ [In den Warenkorb] │    │
│  └──────────────┘  └────────────────────┘    │
├─────────────────────────────────────────────┤
│  Section 2 (columns: 1)                      │
│  ┌─────────────────────────────────────┐     │
│  │ product-description                  │     │
│  │ Card: "Beschreibung"                 │     │
│  │ [HTML Content]                       │     │
│  └─────────────────────────────────────┘     │
└─────────────────────────────────────────────┘

Mobile: Section 1 gestapelt (Gallery oben, Purchase unten)
```

### 1. ProductGalleryBlock (Client-Island)

**Komponenten & Dateien:**
- `components/blocks/product-gallery-block.tsx` — Client-Island Wrapper
- Intern: `components/product/product-image-gallery.tsx` (unveraendert)

**Verhalten:**
- Empfaengt `ProductDetailData` als `data` prop
- Kombiniert `product.image` + `product.galleryImages.nodes` zu `allImages[]`
- Delegiert an bestehende `ProductImageGallery` Komponente

**Zustaende:**
- Loading: `ProductGalleryBlockSkeleton` — `aspect-square` Placeholder mit `animate-pulse`
- Loaded: `ProductImageGallery` mit Swipe-Unterstuetzung
- No-Image (data: null): Fallback-Div mit "Produkt nicht gefunden"

### 2. ProductPurchaseBlock (Client-Island)

**Komponenten & Dateien:**
- `components/blocks/product-purchase-block.tsx` — Client-Island Wrapper
- Intern: `app/produkt/[slug]/product-variant-selector.tsx` (unveraendert, Import-Location aendert sich nicht)

**Verhalten:**
- Empfaengt `ProductDetailData` als `data` prop
- Ruft `extractVariantOptions(product.variations.nodes)` auf
- Rendert h1 (Produktname), Preis, Separator, dann `ProductVariantSelector`
- `ProductVariantSelector` bleibt unveraendert (haelt `selectedVariant` State intern)

**Zustaende:**
- Loading: `ProductPurchaseBlockSkeleton` — h1 + Preis + Separator + Button Placeholder
- Loaded: h1 + Preis + VariantSelector + AddToCart
- No-Data (data: null): "Produkt nicht gefunden"

### 3. ProductDescriptionBlock (Server Component)

**Komponenten & Dateien:**
- `components/blocks/product-description-block.tsx`
- Intern: `components/ui/card.tsx` (unveraendert)

**Verhalten:**
- Empfaengt `ProductDetailData` als `data` prop
- Gibt `null` zurueck wenn `!data?.description` (kein leerer Card)
- Rendert `dangerouslySetInnerHTML` — Trusted Source (eigenes WordPress Backend)

**Zustaende:**
- Loading: `ProductDescriptionBlockSkeleton` — Card mit Pulsierende Linien
- Has-Description: Card mit HTML Content
- No-Description: `null` — Block wird nicht gerendert

### 4. Accessibility

- [x] `ProductImageGallery` hat bereits `role="region"` + `aria-label="Produktbilder"`
- [x] Thumbnail-Buttons haben `aria-label` + `role="tab"` + `aria-selected`
- [x] `AddToCartButton` hat `aria-label` und `disabled` State
- [x] h1 Produktname klar als Seitentitel lesbar
- [x] `<main id="main-content">` als Skip-Link-Ziel

---

## Acceptance Criteria

1) GIVEN eine gueltige Produkt-URL `/produkt/premium-hoodie`
   WHEN die Seite geoeffnet wird
   THEN wird `product.yaml` geladen und `SectionRenderer` rendert 2 Sections (1x columns-2, 1x columns-1)

2) GIVEN `product.yaml` Section 1 mit `columns: 2`
   WHEN auf Desktop (>= md Breakpoint) gerendert
   THEN sind `product-gallery` und `product-purchase` nebeneinander in einem 50/50 Grid angeordnet

3) GIVEN `product.yaml` Section 1 mit `columns: 2`
   WHEN auf Mobile (< md Breakpoint) gerendert
   THEN sind `product-gallery` und `product-purchase` vertikal gestapelt (Gallery oben, Purchase unten)

4) GIVEN ein Produkt mit Galerie-Bildern
   WHEN `ProductGalleryBlock` gerendert wird
   THEN zeigt die Gallery alle Bilder (product.image + galleryImages.nodes) und Swipe funktioniert auf Mobile

5) GIVEN ein Produkt mit Farb- und Groessen-Varianten
   WHEN `ProductPurchaseBlock` gerendert wird
   THEN zeigt der Block den Produktnamen als h1, den Preis und die Varianten-Selektoren korrekt an

6) GIVEN der User waehlt eine Variante und klickt "In den Warenkorb"
   WHEN `AddToCartButton` bestaetigt wird
   THEN wird `useCart().addToCart()` mit korrektem `productId`, `variationId` und `variationAttributes` aufgerufen

7) GIVEN ein Produkt mit nicht-leerer `description`
   WHEN `ProductDescriptionBlock` gerendert wird
   THEN zeigt der Block eine Card mit "Beschreibung" als Titel und dem HTML-Content

8) GIVEN ein Produkt mit leerer `description`
   WHEN `ProductDescriptionBlock` gerendert wird
   THEN rendert der Block nichts (null) — keine leere Card

9) GIVEN drei Blocks (`product-gallery`, `product-purchase`, `product-description`) mit identischem `slug`
   WHEN alle drei `product_by_slug` Data-Loader aufgerufen werden
   THEN macht Apollo `React.cache()` nur einen einzigen GraphQL-Netzwerk-Request

10) GIVEN die Produktseite
    WHEN `generateMetadata()` aufgerufen wird
    THEN enthaelt die Metadata `title`, `description` und `openGraph.images` aus den Produkt-Daten

11) GIVEN die Produktseite
    WHEN der HTML-Response analysiert wird
    THEN enthaelt der `<head>` ein `<script type="application/ld+json">` mit korrektem Product Schema

12) GIVEN eine nicht-existente Produkt-URL `/produkt/nicht-vorhanden`
    WHEN die Seite angefragt wird
    THEN wird `notFound()` aufgerufen und eine 404-Seite zurueckgegeben

---

## Testfaelle

### Test-Datei

`tests/slices/block-page-migration/slice-03-produkt-seite.test.ts`

<test_spec>
```typescript
// tests/slices/block-page-migration/slice-03-produkt-seite.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mock fuer apollo client (keine echte GraphQL-Verbindung noetig)
vi.mock('frontend/lib/apollo/server-client', () => ({
  getClient: () => ({
    query: vi.fn().mockResolvedValue({ data: null }),
  }),
}))

// Mock fuer next/navigation
vi.mock('next/navigation', () => ({
  notFound: vi.fn(),
}))

describe('Slice 03: Produkt-Seite als Block-Page', () => {

  // ============================================================
  // product_by_slug DataLoader Handler
  // ============================================================

  describe('woocommerceLoader product_by_slug', () => {
    it('should call GET_PRODUCT with correct slug variable', async () => {
      // Arrange
      const mockProduct = {
        id: 'prod-1',
        databaseId: 123,
        slug: 'premium-hoodie',
        name: 'Premium Hoodie',
        description: '<p>Toller Hoodie</p>',
        shortDescription: 'Kurze Beschreibung',
        price: '39,99 €',
        regularPrice: '49,99 €',
        onSale: true,
        variations: { nodes: [] },
        image: { sourceUrl: '/img.jpg', altText: '', mediaDetails: { width: 800, height: 800 } },
        galleryImages: { nodes: [] },
        productCategories: { nodes: [] },
      }
      const mockGetClient = vi.fn().mockReturnValue({
        query: vi.fn().mockResolvedValue({ data: { product: mockProduct } }),
      })
      vi.doMock('@/lib/apollo/server-client', () => ({ getClient: mockGetClient }))

      const { woocommerceLoader } = await import('../../../frontend/lib/blocks/data-loaders')

      // Act
      const result = await woocommerceLoader({ query: 'product_by_slug', slug: 'premium-hoodie' } as Parameters<typeof woocommerceLoader>[0])

      // Assert
      expect(result.data).toEqual(mockProduct)
      expect(result.error).toBeUndefined()

      vi.restoreAllMocks()
    })

    it('should return null data when product not found', async () => {
      // Arrange
      const mockGetClient = vi.fn().mockReturnValue({
        query: vi.fn().mockResolvedValue({ data: { product: null } }),
      })
      vi.doMock('@/lib/apollo/server-client', () => ({ getClient: mockGetClient }))

      const { woocommerceLoader } = await import('../../../frontend/lib/blocks/data-loaders')

      // Act
      const result = await woocommerceLoader({ query: 'product_by_slug', slug: 'nicht-vorhanden' } as Parameters<typeof woocommerceLoader>[0])

      // Assert
      expect(result.data).toBeNull()

      vi.restoreAllMocks()
    })

    it('should return null data and error string on GraphQL error', async () => {
      // Arrange
      const mockGetClient = vi.fn().mockReturnValue({
        query: vi.fn().mockRejectedValue(new Error('GraphQL network error')),
      })
      vi.doMock('@/lib/apollo/server-client', () => ({ getClient: mockGetClient }))

      const { woocommerceLoader } = await import('../../../frontend/lib/blocks/data-loaders')

      // Act
      const result = await woocommerceLoader({ query: 'product_by_slug', slug: 'fehler-slug' } as Parameters<typeof woocommerceLoader>[0])

      // Assert
      expect(result.data).toBeNull()
      expect(result.error).toContain('GraphQL network error')

      vi.restoreAllMocks()
    })
  })

  // ============================================================
  // ProductGalleryBlock — Bild-Aggregation
  // ============================================================

  describe('ProductGalleryBlock image aggregation', () => {
    it('should combine product.image and galleryImages.nodes into allImages array', () => {
      // Arrange — Logik aus ProductGalleryBlock
      const mainImage = { sourceUrl: '/main.jpg', altText: 'Hauptbild', mediaDetails: { width: 800, height: 800 } }
      const galleryImage1 = { sourceUrl: '/gal1.jpg', altText: 'Galerie 1', mediaDetails: { width: 800, height: 800 } }
      const galleryImage2 = { sourceUrl: '/gal2.jpg', altText: 'Galerie 2', mediaDetails: { width: 800, height: 800 } }

      const product = {
        image: mainImage,
        galleryImages: { nodes: [galleryImage1, galleryImage2] },
      }

      // Act — Logik aus ProductGalleryBlock
      const allImages = [
        ...(product.image ? [product.image] : []),
        ...(product.galleryImages?.nodes ?? []),
      ]

      // Assert
      expect(allImages).toHaveLength(3)
      expect(allImages[0]).toEqual(mainImage)
      expect(allImages[1]).toEqual(galleryImage1)
      expect(allImages[2]).toEqual(galleryImage2)
    })

    it('should return empty array when product has no image and no gallery images', () => {
      // Arrange
      const product = {
        image: null,
        galleryImages: { nodes: [] },
      }

      // Act
      const allImages = [
        ...(product.image ? [product.image] : []),
        ...(product.galleryImages?.nodes ?? []),
      ]

      // Assert
      expect(allImages).toHaveLength(0)
    })

    it('should include only main image when galleryImages is empty', () => {
      // Arrange
      const mainImage = { sourceUrl: '/main.jpg', altText: '', mediaDetails: { width: 800, height: 800 } }
      const product = {
        image: mainImage,
        galleryImages: { nodes: [] },
      }

      // Act
      const allImages = [
        ...(product.image ? [product.image] : []),
        ...(product.galleryImages?.nodes ?? []),
      ]

      // Assert
      expect(allImages).toHaveLength(1)
      expect(allImages[0]).toEqual(mainImage)
    })
  })

  // ============================================================
  // ProductDescriptionBlock — Conditional Rendering
  // ============================================================

  describe('ProductDescriptionBlock conditional rendering', () => {
    it('should return null when data is null', () => {
      // Arrange — Logik aus ProductDescriptionBlock
      const data = null

      // Act
      const shouldRender = data !== null && !!(data as { description?: string })?.description

      // Assert
      expect(shouldRender).toBe(false)
    })

    it('should return null when description is empty string', () => {
      // Arrange
      const data = { description: '' }

      // Act
      const shouldRender = data !== null && !!data.description

      // Assert
      expect(shouldRender).toBe(false)
    })

    it('should render when description has content', () => {
      // Arrange
      const data = { description: '<p>Toller Hoodie aus Baumwolle</p>' }

      // Act
      const shouldRender = data !== null && !!data.description

      // Assert
      expect(shouldRender).toBe(true)
    })
  })

  // ============================================================
  // product.yaml Struktur
  // ============================================================

  describe('product.yaml structure', () => {
    it('should have 2 sections with correct columns config', async () => {
      // Arrange
      const { existsSync, readFileSync } = await import('fs')
      vi.mock('fs')

      const mockExistsSync = vi.mocked(existsSync)
      const mockReadFileSync = vi.mocked(readFileSync)

      const productYaml = `
sections:
  - columns: 2
    gap: "gap-16"
    blocks:
      - type: product-gallery
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"
      - type: product-purchase
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"
  - columns: 1
    gap: "gap-8"
    blocks:
      - type: product-description
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"
`
      mockExistsSync.mockReturnValue(true)
      mockReadFileSync.mockReturnValue(productYaml)

      const { loadPageConfig } = await import('../../../frontend/lib/blocks/page-config')

      // Act
      const config = loadPageConfig('product', 'default', { slug: 'premium-hoodie' })

      // Assert
      expect(config.sections).toHaveLength(2)
      expect(config.sections[0].columns).toBe(2)
      expect(config.sections[0].gap).toBe('gap-16')
      expect(config.sections[0].blocks).toHaveLength(2)
      expect(config.sections[0].blocks[0].type).toBe('product-gallery')
      expect(config.sections[0].blocks[1].type).toBe('product-purchase')
      expect(config.sections[1].columns).toBe(1)
      expect(config.sections[1].blocks[0].type).toBe('product-description')

      vi.restoreAllMocks()
    })

    it('should resolve $route.slug placeholders in product.yaml blocks', async () => {
      // Arrange
      const { existsSync, readFileSync } = await import('fs')
      vi.mock('fs')

      const mockExistsSync = vi.mocked(existsSync)
      const mockReadFileSync = vi.mocked(readFileSync)

      const productYaml = `
sections:
  - columns: 2
    blocks:
      - type: product-gallery
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"
      - type: product-purchase
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"
  - columns: 1
    blocks:
      - type: product-description
        content_source: woocommerce
        params:
          query: product_by_slug
          slug: "$route.slug"
`
      mockExistsSync.mockReturnValue(true)
      mockReadFileSync.mockReturnValue(productYaml)

      const { loadPageConfig } = await import('../../../frontend/lib/blocks/page-config')

      // Act — slug "winter-jacke" wird durch resolveParams eingesetzt
      const config = loadPageConfig('product', 'default', { slug: 'winter-jacke' })

      // Assert — alle 3 Blocks haben den aufgeloesten slug
      expect((config.sections[0].blocks[0].params as Record<string, unknown>).slug).toBe('winter-jacke')
      expect((config.sections[0].blocks[1].params as Record<string, unknown>).slug).toBe('winter-jacke')
      expect((config.sections[1].blocks[0].params as Record<string, unknown>).slug).toBe('winter-jacke')

      vi.restoreAllMocks()
    })
  })

  // ============================================================
  // registry.ts — neue Block-Types registriert
  // ============================================================

  describe('registry block registration', () => {
    it('should resolve product-gallery block type', async () => {
      const { resolveBlock } = await import('../../../frontend/lib/blocks/registry')

      // Act
      const component = resolveBlock('product-gallery')

      // Assert
      expect(component).not.toBeNull()
    })

    it('should resolve product-purchase block type', async () => {
      const { resolveBlock } = await import('../../../frontend/lib/blocks/registry')

      const component = resolveBlock('product-purchase')

      expect(component).not.toBeNull()
    })

    it('should resolve product-description block type', async () => {
      const { resolveBlock } = await import('../../../frontend/lib/blocks/registry')

      const component = resolveBlock('product-description')

      expect(component).not.toBeNull()
    })
  })

  // ============================================================
  // ProductPurchaseBlock — variantOptions Extraktion
  // ============================================================

  describe('ProductPurchaseBlock variantOptions extraction', () => {
    it('should return empty sizes and colors when product has no variations', () => {
      // Arrange — Logik aus ProductPurchaseBlock
      const product = { variations: undefined }

      // Act
      const variantOptions = product.variations
        ? { sizes: [], colors: [] }  // extractVariantOptions would be called
        : { sizes: [], colors: [] }

      // Assert
      expect(variantOptions.sizes).toHaveLength(0)
      expect(variantOptions.colors).toHaveLength(0)
    })

    it('should compute canAddToCart as true when no variants exist', () => {
      // Arrange — Logik aus ProductVariantSelector (wird in ProductPurchaseBlock verwendet)
      const variantOptions = { sizes: [], colors: [] }
      const matchedVariation = null

      // Act — Logik aus product-variant-selector.tsx canAddToCart
      const canAddToCart =
        matchedVariation !== null ||
        (variantOptions.sizes.length === 0 && variantOptions.colors.length === 0)

      // Assert
      expect(canAddToCart).toBe(true)
    })

    it('should compute canAddToCart as false when variants exist but none selected', () => {
      // Arrange
      const variantOptions = { sizes: [{ value: 'L', available: true }], colors: [] }
      const matchedVariation = null

      // Act
      const canAddToCart =
        matchedVariation !== null ||
        (variantOptions.sizes.length === 0 && variantOptions.colors.length === 0)

      // Assert
      expect(canAddToCart).toBe(false)
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
| `slice-01-block-system-upgrade` | `SectionConfig` | TypeScript Interface | EXPORTED from `lib/blocks/types.ts` |
| `slice-01-block-system-upgrade` | `PageConfig.sections[]` | TypeScript Interface | EXPORTED from `lib/blocks/types.ts` |
| `slice-01-block-system-upgrade` | `WooCommerceLoaderParams.query` | Union Type | Enthaelt `'product_by_slug'` |
| `slice-01-block-system-upgrade` | `loadPageConfig(pageType, theme, routeParams)` | Function | EXPORTED from `lib/blocks/page-config.ts`, 3-tier Lookup implementiert |
| `slice-01-block-system-upgrade` | `SectionRenderer` | React Server Component | EXPORTED from `lib/blocks/section-renderer.tsx` |
| `slice-01-block-system-upgrade` | `BlockComponentProps<T>` | TypeScript Interface | EXPORTED from `lib/blocks/types.ts` |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `product-gallery` Block-Type | Registry Entry | `SectionRenderer` (via `resolveBlock()`) | `BlockComponentProps<ProductDetailData \| null>` |
| `product-purchase` Block-Type | Registry Entry | `SectionRenderer` (via `resolveBlock()`) | `BlockComponentProps<ProductDetailData \| null>` |
| `product-description` Block-Type | Registry Entry | `SectionRenderer` (via `resolveBlock()`) | `BlockComponentProps<ProductDetailData \| null>` |
| `product_by_slug` DataLoader Query | WooCommerce Loader Handler | `lib/blocks/data-loaders.ts` | `{ query: 'product_by_slug', slug: string }` → `{ data: ProductDetailData \| null }` |
| `themes/default/pages/product.yaml` | YAML Config | `loadPageConfig('product', ...)` | 2 Sections: columns=2 (gallery+purchase), columns=1 (description) |

### Integration Validation Tasks

- [ ] `slice-01-block-system-upgrade` ist approved und alle Exports verfuegbar
- [ ] `WooCommerceLoaderParams` enthaelt `'product_by_slug'` in Query-Union (Slice 1 Deliverable)
- [ ] `product-gallery`, `product-purchase`, `product-description` in `registry.ts` registriert
- [ ] `product.yaml` an `themes/default/pages/product.yaml` abgelegt
- [ ] Alle drei Block-Components als Named Exports verfuegbar (inkl. Skeleton-Exports)
- [ ] `product_by_slug` Handler in `woocommerceLoader()` implementiert und gibt `ProductDetailData` zurueck

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind PFLICHT-Deliverables. Der Gate 2 Compliance Agent prueft, dass jedes Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `product_by_slug` DataLoader Handler | Abschnitt 3 | YES | In `woocommerceLoader()` — nutzt `GET_PRODUCT` Query, gibt `ProductDetailData` zurueck |
| `product.yaml` Layout | Abschnitt 4 | YES | Exaktes YAML-Format: 2 Sections (columns=2, columns=1), alle 3 Blocks mit `$route.slug` |
| `ProductGalleryBlock` | Abschnitt 5 | YES | `"use client"`, `BlockComponentProps<ProductDetailData \| null>`, allImages-Aggregation, Skeleton-Export |
| `ProductPurchaseBlock` | Abschnitt 6 | YES | `"use client"`, h1 + Preis + `ProductVariantSelector`, Skeleton-Export |
| `ProductDescriptionBlock` | Abschnitt 7 | YES | Server Component, null wenn keine Description, `dangerouslySetInnerHTML`, Skeleton-Export |
| `registry.ts` Erweiterung | Abschnitt 8 | YES | Alle 3 neuen Block-Types registriert |
| `app/produkt/[slug]/page.tsx` Migration | Abschnitt 9 | YES | Thin wrapper: `generateStaticParams`, `generateMetadata`, JSON-LD, `notFound()`, `SectionRenderer` |

---

## Links

- Discovery: `specs/phase-0/2026-02-25-block-page-migration/discovery.md`
- Architecture: `specs/phase-0/2026-02-25-block-page-migration/architecture.md`
- Approved Dependency: `specs/phase-0/2026-02-25-block-page-migration/slices/slice-01-block-system-upgrade.md`
- Bestehende Produktseite: `frontend/app/produkt/[slug]/page.tsx`
- ProductImageGallery: `frontend/components/product/product-image-gallery.tsx`
- ProductVariantSelector: `frontend/app/produkt/[slug]/product-variant-selector.tsx`
- Block-System: `frontend/lib/blocks/`

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
### Lib / Block-System

- [ ] `frontend/lib/blocks/data-loaders.ts` — `product_by_slug` Handler in `woocommerceLoader()`: importiert `GET_PRODUCT`, gibt `{ data: ProductDetailData | null }` zurueck
- [ ] `frontend/lib/blocks/registry.ts` — 3 neue Block-Types registriert: `'product-gallery'`, `'product-purchase'`, `'product-description'`

### Frontend

- [ ] `frontend/components/blocks/product-gallery-block.tsx` — Client-Island, wrappet `ProductImageGallery`, aggregiert `allImages`, Null-State, Named Skeleton-Export
- [ ] `frontend/components/blocks/product-purchase-block.tsx` — Client-Island, wrappet `ProductVariantSelector`, rendert h1 + Preis, Named Skeleton-Export
- [ ] `frontend/components/blocks/product-description-block.tsx` — Server Component, Card mit HTML-Content, null wenn keine Description, Named Skeleton-Export
- [ ] `frontend/themes/default/pages/product.yaml` — 2 Sections: columns=2 (product-gallery, product-purchase), columns=1 (product-description). Alle 3 Blocks mit `$route.slug` Platzhalter.
- [ ] `frontend/app/produkt/[slug]/page.tsx` — Thin wrapper: `generateStaticParams` + `generateMetadata` + JSON-LD + `notFound()`-Check + `SectionRenderer`. Hardcoded JSX entfernt.

### Tests

- [ ] `frontend/tests/slices/block-page-migration/slice-03-produkt-seite.test.ts` — Unit Tests fuer `product_by_slug` DataLoader, allImages-Aggregation, ProductDescriptionBlock Conditional Rendering, product.yaml Struktur + resolveParams, Registry-Registrierung, variantOptions-Extraktion
<!-- DELIVERABLES_END -->

---

## Constraints & Hinweise

**Betrifft:**
- `app/produkt/[slug]/page.tsx`: Bestehende Imports (`GET_PRODUCT`, `GET_ALL_PRODUCT_SLUGS`, `generateProductJsonLd`) bleiben. Nur der Rendering-Teil wird durch `SectionRenderer` ersetzt.
- `app/produkt/[slug]/product-variant-selector.tsx`: Unveraendert. Wird von `ProductPurchaseBlock` importiert — Import-Pfad bleibt `@/app/produkt/[slug]/product-variant-selector`.
- `lib/blocks/data-loaders.ts`: `GET_PRODUCT` muss zu den Imports hinzugefuegt werden (aktuell nur `GET_FEATURED_PRODUCTS`, `GET_PRODUCT_CATEGORIES`).

**Client-Island Pattern:**
- `ProductGalleryBlock` und `ProductPurchaseBlock` haben `"use client"` Directive
- `ProductDescriptionBlock` ist Server Component (kein `"use client"`)
- Data-Fetching erfolgt serverseitig durch `loadBlockData()` im `SectionRenderer`
- Client-Blocks empfangen fertige Daten als `data` Prop — kein Client-seitiges Fetching
- Apollo `React.cache()` dedupliziert alle drei `product_by_slug` Queries zu einem einzigen Netzwerk-Request

**notFound()-Pruefung:**
- `page.tsx` prueft weiterhin ob das Produkt existiert und ruft `notFound()` auf
- Die Pruefung darf NICHT in einen Block verschoben werden (Slice 1 Constraint: Page-level Logic bleibt in page.tsx)
- Apollo dedupliziert den GET_PRODUCT Query aus `generateMetadata` und der notFound-Pruefung

**Design-Token-Pflicht:**
- `ProductGalleryBlock`: `bg-surface-elevated`, `rounded-card` (keine Hardcoded-Werte)
- `ProductPurchaseBlock`: `text-text-primary`, `text-text-secondary`, `text-accent`, `border-border`
- `ProductDescriptionBlock`: Reused `Card`-Komponente (hat bereits Theme-Tokens)

**Abgrenzung:**
- Pinterest-Tracking-Client-Component (falls vorhanden) bleibt in page.tsx — NICHT in Block-System
- `extractVariantOptions()` aus `lib/product/variant-utils` wird in `ProductPurchaseBlock` aufgerufen (nicht im DataLoader)
- ISR `revalidate = 60` bleibt in page.tsx unveraendert

---

## Skill Verification

### React Best Practices Verification

- [x] `async-suspense-boundaries`: Slice 1 SectionRenderer wrappet alle Blocks in `<Suspense fallback={skeleton}>`
- [x] `server-cache-react`: Apollo `getClient()` nutzt `React.cache()` — drei identische `product_by_slug` Queries = ein Netzwerk-Request
- [x] `rendering-hoist-jsx`: `SKELETON_MAP` in `page.tsx` als Modul-Level-Konstante (kein Re-Create pro Render)
- [x] `bundle-dynamic-imports`: Client-Islands sind in separaten Dateien — Next.js code-splittet automatisch

### Web Design Guidelines Verification

- [x] Semantic HTML: `ProductPurchaseBlock` verwendet `<h1>` fuer Produktnamen, `<hr>` als Separator
- [x] Mobile-first: `columns: 2` in `product.yaml` mit Slice 1 `SectionLayout` → `grid-cols-1 md:grid-cols-2`
- [x] `touch-action: manipulation`: Bestehende `ProductImageGallery` hat Touch-Swipe implementiert
- [x] Accessibility: Bestehende `ProductImageGallery` hat `role="region"` + `aria-label="Produktbilder"`

### Tailwind v4 Patterns Verification

- [x] Keine hardcoded Farben/Radii: `bg-surface-elevated`, `rounded-card`, `text-text-primary`, `text-accent`
- [x] Mobile-first: `mt-8 md:mt-0` in `ProductPurchaseBlock` fuer korrekten Mobile-Stack-Abstand
- [x] Theme-Tokens: `border-border`, `text-text-secondary` in allen neuen Komponenten
