# Slice 2: Produktkatalog Frontend implementieren

> **Slice 2 von 7** für `POD Shop MVP`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-01-infrastruktur.md` |
> | **Nächster:** | `slice-03-warenkorb-checkout.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-02-produktkatalog-frontend` |
| **Test** | `pnpm test tests/slices/pod-shop-mvp/slice-02-produktkatalog-frontend.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-infrastruktur"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` – Vitest Unit/Component Tests (kein Playwright)
- **Dependencies**: Slice 1 muss fertig sein (Apollo Client, ApolloWrapper, TokenManager, WPGraphQL Endpoint)

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren. Slice 1 dokumentiert: `frontend/package.json` mit Next.js 16, Vitest 3.x, `@tailwindcss/postcss`. Stack: `typescript-nextjs`.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/pod-shop-mvp/slice-02-produktkatalog-frontend.test.ts` |
| **Integration Command** | `pnpm test tests/slices/pod-shop-mvp/slice-02-produktkatalog-frontend.test.ts --reporter=verbose` |
| **Acceptance Command** | `curl -f http://localhost:3000/ && curl -f http://localhost:3000/kategorie/t-shirts && echo "Pages OK"` |
| **Start Command** | `docker compose up -d && cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/` |
| **Mocking Strategy** | `mock_external` |

**Erklaerung:**
- **Stack**: Next.js 16 (App Router), React 19, Tailwind CSS v4, Apollo Client v4
- **Test Command**: Vitest Unit Tests für GraphQL Queries, Typen, Utility-Funktionen und Component-Logik
- **Integration Command**: Gleiche Tests mit ausführlicher Ausgabe
- **Acceptance Command**: Manuelle Smoke Tests gegen laufenden Dev-Server
- **Start Command**: Docker für WordPress Backend, dann Next.js Dev-Server
- **Health Endpoint**: Next.js Homepage (zeigt Featured Products aus WooCommerce)
- **Mocking Strategy**: WPGraphQL-Calls werden in Unit Tests gemockt (kein echter WooCommerce-Aufruf)

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Infrastruktur | Ready | `slice-01-infrastruktur.md` |
| 2 | Produktkatalog (Frontend) | Ready | `slice-02-produktkatalog-frontend.md` |
| 3 | Warenkorb + Checkout-Redirect | Pending | `slice-03-warenkorb-checkout.md` |
| 4 | Rechtliches + Rechnungen | Pending | `slice-04-rechtliches-rechnungen.md` |
| 5 | POD-Anbindung (Spreadconnect) | Pending | `slice-05-spreadconnect.md` |
| 6 | Pinterest Tracking | Pending | `slice-06-pinterest-tracking.md` |
| 7 | User-Accounts | Pending | `slice-07-user-accounts.md` |

---

## Kontext & Ziel

Dieser Slice implementiert den vollständigen Produktkatalog im Next.js Frontend: Startseite mit Hero-Bereich und Featured Products, Kategorieseiten mit Filter-Chips, und Produktdetailseiten mit Varianten-Auswahl (Größe + Farbe). Alle Seiten werden per SSG/ISR gebaut und sind Pinterest-traffic-optimiert (Mobile-first, JSON-LD Schema, Skeleton Loading).

Nach Abschluss können Nutzer Produkte aus WooCommerce browsen, filtern und die Produktdetailseite mit Variantenauswahl aufrufen. Der "In den Warenkorb"-Button ist als Vorbereitung für Slice 3 vorhanden (noch nicht funktional – zeigt Disabled-State bis Variante gewählt, dann Vorbereitung für addToCart).

**Scope-Abgrenzung:**
- `addToCart` Mutation: OUT OF SCOPE (Slice 3)
- Warenkorb-Icon Badge mit Anzahl: OUT OF SCOPE (Slice 3)
- Cookie-Banner: OUT OF SCOPE (Slice 4)
- Pinterest Tag Events: OUT OF SCOPE (Slice 6)
- Header/Footer: IN SCOPE (werden in diesem Slice implementiert, da alle Produktseiten sie brauchen)

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → GraphQL Queries, Architecture Layers, Quality Attributes

```
[Browser]
    │
    ├── Next.js (localhost:3000)
    │       ├── SSG/ISR: generateStaticParams() für alle Produkt- und Kategorieseiten
    │       ├── Apollo Client → POST http://localhost:8080/graphql (aus Slice 1)
    │       └── JSON-LD Product Schema (für SEO/Pinterest)
    │
    └── WordPress/WooCommerce (localhost:8080)
            └── WPGraphQL + WooGraphQL → products, product, productCategories Queries
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `Presentation` (Next.js) | `app/page.tsx` – Homepage mit Hero + Featured Products + Kategorien |
| `Presentation` (Next.js) | `app/kategorie/[slug]/page.tsx` – Kategorieseite mit Produktliste |
| `Presentation` (Next.js) | `app/produkt/[slug]/page.tsx` – Produktdetailseite mit Varianten |
| `Presentation` (Next.js) | `components/layout/header.tsx` – Persistenter Header (Mobile + Desktop) |
| `Presentation` (Next.js) | `components/layout/footer.tsx` – Persistenter Footer |
| `Presentation` (Next.js) | `components/product/product-card.tsx` – Produkt-Card (alle States) |
| `Presentation` (Next.js) | `components/product/product-card-skeleton.tsx` – Skeleton Loading |
| `Presentation` (Next.js) | `components/product/variant-selector-size.tsx` – Größen-Selector |
| `Presentation` (Next.js) | `components/product/variant-selector-color.tsx` – Farb-Selector |
| `Presentation` (Next.js) | `components/product/product-image-gallery.tsx` – Bild-Galerie (Swipe Mobile/Thumbnail Desktop) |
| `Presentation` (Next.js) | `components/product/add-to-cart-button.tsx` – "In den Warenkorb"-Button (Vorbereitung Slice 3) |
| `Presentation` (Next.js) | `components/category/filter-chips.tsx` – Horizontale Filter-Chips |
| `Presentation` (Next.js) | `lib/graphql/queries.ts` – GraphQL Query Definitionen |
| `Presentation` (Next.js) | `lib/graphql/fragments.ts` – Shared GraphQL Fragments |
| `Presentation` (Next.js) | `lib/graphql/types.ts` – TypeScript Typen für GraphQL Responses |
| `Presentation` (Next.js) | `lib/seo/json-ld.ts` – JSON-LD Product Schema Generator |

### 2. Datenfluss

```
Next.js Build (SSG/ISR)
  ↓
generateStaticParams() → products Query → WPGraphQL → Liste aller Produkt-Slugs
  ↓
generateStaticParams() → productCategories Query → WPGraphQL → Liste aller Kategorie-Slugs
  ↓
Per Seite: product/products Query → WPGraphQL → Produktdaten + Varianten
  ↓
React Server Component rendert HTML (kein Client-side Fetch für initiale Daten)
  ↓
Client Components: VariantSelectorSize, VariantSelectorColor (useState für Auswahl)
  ↓
AddToCartButton: disabled bis Größe + Farbe gewählt → onAddToCart Callback (Slice 3)
```

### 3. GraphQL Queries

**Datei:** `frontend/lib/graphql/queries.ts`

```typescript
import { gql } from '@apollo/client'
import { PRODUCT_CARD_FRAGMENT, PRODUCT_DETAIL_FRAGMENT, CATEGORY_FRAGMENT } from './fragments'

// Query: Alle Produkte für Kategorieseite
export const GET_PRODUCTS = gql`
  ${PRODUCT_CARD_FRAGMENT}
  query GetProducts($categorySlug: String, $first: Int = 24) {
    products(
      first: $first
      where: {
        categoryIn: [$categorySlug]
        orderby: { field: DATE, order: DESC }
        status: PUBLISH
      }
    ) {
      nodes {
        ...ProductCardFields
      }
    }
  }
`

// Query: Featured Products für Homepage (tag: "featured" oder custom Selektion)
export const GET_FEATURED_PRODUCTS = gql`
  ${PRODUCT_CARD_FRAGMENT}
  query GetFeaturedProducts($first: Int = 4) {
    products(
      first: $first
      where: {
        featured: true
        status: PUBLISH
        orderby: { field: DATE, order: DESC }
      }
    ) {
      nodes {
        ...ProductCardFields
      }
    }
  }
`

// Query: Einzelnes Produkt für Produktdetailseite
export const GET_PRODUCT = gql`
  ${PRODUCT_DETAIL_FRAGMENT}
  query GetProduct($slug: ID!) {
    product(id: $slug, idType: SLUG) {
      ...ProductDetailFields
    }
  }
`

// Query: Alle Produktkategorien für Navigation + Homepage
export const GET_PRODUCT_CATEGORIES = gql`
  ${CATEGORY_FRAGMENT}
  query GetProductCategories($first: Int = 10) {
    productCategories(first: $first, where: { parent: 0, hideEmpty: true }) {
      nodes {
        ...CategoryFields
      }
    }
  }
`

// Query: Alle Produkt-Slugs für generateStaticParams
export const GET_ALL_PRODUCT_SLUGS = gql`
  query GetAllProductSlugs {
    products(first: 100, where: { status: PUBLISH }) {
      nodes {
        slug
      }
    }
  }
`

// Query: Alle Kategorie-Slugs für generateStaticParams
export const GET_ALL_CATEGORY_SLUGS = gql`
  query GetAllCategorySlugs {
    productCategories(first: 50, where: { hideEmpty: true }) {
      nodes {
        slug
      }
    }
  }
`
```

### 4. GraphQL Fragments

**Datei:** `frontend/lib/graphql/fragments.ts`

```typescript
import { gql } from '@apollo/client'

// Fragment: Felder für Produkt-Card (Homepage + Kategorieseite)
export const PRODUCT_CARD_FRAGMENT = gql`
  fragment ProductCardFields on Product {
    id
    slug
    name
    ... on SimpleProduct {
      price(format: FORMATTED)
      regularPrice(format: FORMATTED)
      onSale
      stockStatus
    }
    ... on VariableProduct {
      price(format: FORMATTED)
      regularPrice(format: FORMATTED)
      onSale
    }
    image {
      sourceUrl
      altText
      mediaDetails {
        width
        height
      }
    }
    productCategories {
      nodes {
        id
        slug
        name
      }
    }
  }
`

// Fragment: Felder für Produktdetailseite (inkl. Varianten)
export const PRODUCT_DETAIL_FRAGMENT = gql`
  fragment ProductDetailFields on Product {
    id
    databaseId
    slug
    name
    description
    shortDescription
    ... on VariableProduct {
      price(format: FORMATTED)
      regularPrice(format: FORMATTED)
      onSale
      variations(first: 50) {
        nodes {
          id
          databaseId
          stockStatus
          attributes {
            nodes {
              name
              value
            }
          }
        }
      }
    }
    image {
      sourceUrl
      altText
      mediaDetails {
        width
        height
      }
    }
    galleryImages {
      nodes {
        sourceUrl
        altText
        mediaDetails {
          width
          height
        }
      }
    }
    productCategories {
      nodes {
        id
        slug
        name
      }
    }
  }
`

// Fragment: Felder für Kategorie
export const CATEGORY_FRAGMENT = gql`
  fragment CategoryFields on ProductCategory {
    id
    slug
    name
    count
    image {
      sourceUrl
      altText
      mediaDetails {
        width
        height
      }
    }
  }
`
```

### 5. TypeScript Typen

**Datei:** `frontend/lib/graphql/types.ts`

```typescript
// Produkt-Attribute (Größe, Farbe)
export interface ProductAttribute {
  name: string   // z.B. "pa_size" oder "pa_color"
  value: string  // z.B. "L" oder "Schwarz"
}

// Produktvariation
export interface ProductVariation {
  id: string
  databaseId: number
  stockStatus: 'IN_STOCK' | 'OUT_OF_STOCK' | 'ON_BACKORDER'
  attributes: {
    nodes: ProductAttribute[]
  }
}

// Produktbild
export interface ProductImage {
  sourceUrl: string
  altText: string
  mediaDetails: {
    width: number
    height: number
  }
}

// Produktkategorie
export interface ProductCategory {
  id: string
  slug: string
  name: string
  count?: number
  image?: ProductImage | null
}

// Produkt-Card (für Listen)
export interface ProductCardData {
  id: string
  slug: string
  name: string
  price: string | null
  regularPrice: string | null
  onSale: boolean
  stockStatus?: 'IN_STOCK' | 'OUT_OF_STOCK'
  image: ProductImage | null
  productCategories: {
    nodes: ProductCategory[]
  }
}

// Produkt-Detail (für Detailseite)
export interface ProductDetailData {
  id: string
  databaseId: number
  slug: string
  name: string
  description: string
  shortDescription: string | null
  price: string | null
  regularPrice: string | null
  onSale: boolean
  variations?: {
    nodes: ProductVariation[]
  }
  image: ProductImage | null
  galleryImages: {
    nodes: ProductImage[]
  }
  productCategories: {
    nodes: ProductCategory[]
  }
}

// Extrahierte Varianten-Optionen (aus ProductVariation[])
export interface VariantOptions {
  sizes: Array<{
    value: string
    available: boolean
  }>
  colors: Array<{
    value: string
    hexCode: string | null
    available: boolean
  }>
}

// Ausgewählte Variante
export interface SelectedVariant {
  size: string | null
  color: string | null
}
```

### 6. Utility: Varianten-Extraktion

**Datei:** `frontend/lib/product/variant-utils.ts`

```typescript
import type { ProductVariation, VariantOptions } from '../graphql/types'

// Bekannte Farben → Hex-Codes (erweiterbar)
const COLOR_HEX_MAP: Record<string, string> = {
  schwarz: '#000000',
  black: '#000000',
  weiß: '#FFFFFF',
  weiss: '#FFFFFF',
  white: '#FFFFFF',
  grau: '#808080',
  gray: '#808080',
  grey: '#808080',
  rot: '#CC0000',
  red: '#CC0000',
  blau: '#0066CC',
  blue: '#0066CC',
  navy: '#001F5B',
  grün: '#228B22',
  green: '#228B22',
}

/**
 * Extrahiert alle einzigartigen Größen und Farben aus den Produktvariationen.
 * Markiert verfügbare/nicht-verfügbare Optionen basierend auf stockStatus.
 */
export function extractVariantOptions(variations: ProductVariation[]): VariantOptions {
  const sizeMap = new Map<string, boolean>()
  const colorMap = new Map<string, boolean>()

  for (const variation of variations) {
    const isAvailable = variation.stockStatus === 'IN_STOCK'

    for (const attr of variation.attributes.nodes) {
      const attrName = attr.name.toLowerCase()
      const attrValue = attr.value

      if (attrName === 'pa_size' || attrName === 'size' || attrName === 'größe') {
        // Verfügbar wenn mindestens eine Variation mit dieser Größe IN_STOCK ist
        const existing = sizeMap.get(attrValue)
        sizeMap.set(attrValue, existing === true ? true : isAvailable)
      }

      if (attrName === 'pa_color' || attrName === 'color' || attrName === 'farbe') {
        const existing = colorMap.get(attrValue)
        colorMap.set(attrValue, existing === true ? true : isAvailable)
      }
    }
  }

  const sizes = Array.from(sizeMap.entries()).map(([value, available]) => ({
    value,
    available,
  }))

  const colors = Array.from(colorMap.entries()).map(([value, available]) => ({
    value,
    hexCode: COLOR_HEX_MAP[value.toLowerCase()] ?? null,
    available,
  }))

  return { sizes, colors }
}

/**
 * Findet die passende Variation basierend auf ausgewählter Größe und Farbe.
 * Gibt null zurück wenn keine passende Variation gefunden.
 */
export function findVariation(
  variations: ProductVariation[],
  selectedSize: string | null,
  selectedColor: string | null
): ProductVariation | null {
  if (!selectedSize || !selectedColor) return null

  return (
    variations.find((variation) => {
      const attrs = variation.attributes.nodes
      const hasSize = attrs.some(
        (a) =>
          (a.name.toLowerCase() === 'pa_size' ||
            a.name.toLowerCase() === 'size' ||
            a.name.toLowerCase() === 'größe') &&
          a.value === selectedSize
      )
      const hasColor = attrs.some(
        (a) =>
          (a.name.toLowerCase() === 'pa_color' ||
            a.name.toLowerCase() === 'color' ||
            a.name.toLowerCase() === 'farbe') &&
          a.value === selectedColor
      )
      return hasSize && hasColor
    }) ?? null
  )
}
```

### 7. JSON-LD Schema Generator

**Datei:** `frontend/lib/seo/json-ld.ts`

```typescript
import type { ProductDetailData } from '../graphql/types'

/**
 * Generiert JSON-LD Product Schema für SEO und Pinterest Rich Pins.
 * Referenz: https://schema.org/Product
 */
export function generateProductJsonLd(product: ProductDetailData, pageUrl: string): string {
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: product.name,
    description: product.shortDescription ?? product.description,
    image: product.image?.sourceUrl ?? '',
    url: pageUrl,
    offers: {
      '@type': 'Offer',
      price: product.price?.replace(/[^\d,]/g, '').replace(',', '.') ?? '0',
      priceCurrency: 'EUR',
      availability: 'https://schema.org/InStock',
      priceValidUntil: new Date(Date.now() + 365 * 24 * 60 * 60 * 1000)
        .toISOString()
        .split('T')[0],
    },
  }

  return JSON.stringify(schema)
}
```

### 8. Homepage

**Datei:** `frontend/app/page.tsx`

```typescript
import { Suspense } from 'react'
import Image from 'next/image'
import Link from 'next/link'
import { getClient } from '@/lib/apollo/server-client'
import { GET_FEATURED_PRODUCTS, GET_PRODUCT_CATEGORIES } from '@/lib/graphql/queries'
import { ProductCard } from '@/components/product/product-card'
import { ProductCardSkeleton } from '@/components/product/product-card-skeleton'
import type { ProductCardData, ProductCategory } from '@/lib/graphql/types'

// ISR: Seite wird alle 60 Minuten neu gebaut
export const revalidate = 60

async function FeaturedProducts() {
  const { data } = await getClient().query<{ products: { nodes: ProductCardData[] } }>({
    query: GET_FEATURED_PRODUCTS,
    variables: { first: 4 },
  })

  const products = data?.products?.nodes ?? []

  if (products.length === 0) return null

  return (
    <section aria-labelledby="featured-heading">
      <h2 id="featured-heading" className="text-2xl font-semibold tracking-tight mb-6">
        Ausgewählte Produkte
      </h2>
      <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {products.map((product) => (
          <ProductCard key={product.id} product={product} />
        ))}
      </div>
    </section>
  )
}

async function CategoryOverview() {
  const { data } = await getClient().query<{
    productCategories: { nodes: ProductCategory[] }
  }>({
    query: GET_PRODUCT_CATEGORIES,
    variables: { first: 6 },
  })

  const categories = data?.productCategories?.nodes ?? []

  return (
    <section aria-labelledby="categories-heading">
      <h2 id="categories-heading" className="text-2xl font-semibold tracking-tight mb-6">
        Kategorien
      </h2>
      <div className="flex flex-col gap-3">
        {categories.map((category) => (
          <Link
            key={category.id}
            href={`/kategorie/${category.slug}`}
            className="flex items-center gap-4 p-4 rounded-xl border border-border bg-surface-elevated hover:bg-surface-elevated/80 transition-colors"
          >
            {category.image && (
              <div className="relative w-12 h-12 flex-shrink-0 rounded-lg overflow-hidden">
                <Image
                  src={category.image.sourceUrl}
                  alt={category.image.altText || category.name}
                  fill
                  className="object-cover"
                  sizes="48px"
                />
              </div>
            )}
            <span className="font-medium text-text-primary flex-1">{category.name}</span>
            <span aria-hidden="true" className="text-text-secondary">
              →
            </span>
          </Link>
        ))}
      </div>
    </section>
  )
}

export default function HomePage() {
  return (
    <main id="main-content">
      {/* Hero Section */}
      <section className="relative bg-surface-elevated rounded-2xl overflow-hidden mb-12 p-8 sm:p-12">
        <div className="max-w-lg">
          <h1 className="text-3xl sm:text-4xl font-bold tracking-tight text-text-primary mb-4">
            Einzigartige Designs
            <br />
            auf Premium-Produkten
          </h1>
          <p className="text-text-secondary mb-6">
            Print-on-Demand – jedes Stück wird eigens für dich produziert.
          </p>
          <Link
            href="/kategorie/t-shirts"
            className="inline-flex items-center justify-center px-6 py-3 bg-primary text-white rounded-lg font-medium hover:bg-primary-hover transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          >
            Jetzt shoppen
          </Link>
        </div>
      </section>

      <div className="space-y-12">
        {/* Featured Products mit Suspense Skeleton */}
        <Suspense
          fallback={
            <section aria-labelledby="featured-heading-loading">
              <h2
                id="featured-heading-loading"
                className="text-2xl font-semibold tracking-tight mb-6"
              >
                Ausgewählte Produkte
              </h2>
              <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {Array.from({ length: 4 }).map((_, i) => (
                  <ProductCardSkeleton key={i} />
                ))}
              </div>
            </section>
          }
        >
          <FeaturedProducts />
        </Suspense>

        {/* Kategorie-Übersicht */}
        <Suspense
          fallback={
            <section>
              <div className="h-8 w-32 bg-surface-elevated rounded animate-pulse mb-6" />
              <div className="flex flex-col gap-3">
                {Array.from({ length: 3 }).map((_, i) => (
                  <div key={i} className="h-16 bg-surface-elevated rounded-xl animate-pulse" />
                ))}
              </div>
            </section>
          }
        >
          <CategoryOverview />
        </Suspense>
      </div>
    </main>
  )
}
```

### 9. Apollo Server Client (für RSC)

**Datei:** `frontend/lib/apollo/server-client.ts`

```typescript
import { ApolloClient, InMemoryCache, HttpLink } from '@apollo/client'
import { registerApolloClient } from '@apollo/experimental-nextjs-app-support/rsc'

// Server-seitiger Apollo Client für React Server Components (kein localStorage/Token nötig)
export const { getClient } = registerApolloClient(() => {
  return new ApolloClient({
    cache: new InMemoryCache(),
    link: new HttpLink({
      uri: process.env.NEXT_PUBLIC_GRAPHQL_URL ?? 'http://localhost:8080/graphql',
    }),
  })
})
```

**Hinweis:** `@apollo/experimental-nextjs-app-support` wird zu `package.json` hinzugefügt:

```json
"@apollo/experimental-nextjs-app-support": "^0.11.0"
```

### 10. Kategorieseite

**Datei:** `frontend/app/kategorie/[slug]/page.tsx`

```typescript
import { Suspense } from 'react'
import { notFound } from 'next/navigation'
import { getClient } from '@/lib/apollo/server-client'
import {
  GET_PRODUCTS,
  GET_PRODUCT_CATEGORIES,
  GET_ALL_CATEGORY_SLUGS,
} from '@/lib/graphql/queries'
import { ProductCard } from '@/components/product/product-card'
import { ProductCardSkeleton } from '@/components/product/product-card-skeleton'
import { FilterChips } from '@/components/category/filter-chips'
import type { ProductCardData, ProductCategory } from '@/lib/graphql/types'

export const revalidate = 60

export async function generateStaticParams() {
  const { data } = await getClient().query<{
    productCategories: { nodes: Array<{ slug: string }> }
  }>({
    query: GET_ALL_CATEGORY_SLUGS,
  })

  return (data?.productCategories?.nodes ?? []).map((cat) => ({
    slug: cat.slug,
  }))
}

export async function generateMetadata({ params }: { params: { slug: string } }) {
  const { data } = await getClient().query<{
    productCategories: { nodes: ProductCategory[] }
  }>({
    query: GET_PRODUCT_CATEGORIES,
  })

  const category = data?.productCategories?.nodes.find((c) => c.slug === params.slug)

  return {
    title: category ? `${category.name} | POD Shop` : 'Kategorie | POD Shop',
    description: category ? `Alle ${category.name} im POD Shop` : undefined,
  }
}

interface CategoryPageProps {
  params: { slug: string }
}

export default async function CategoryPage({ params }: CategoryPageProps) {
  // Produkte dieser Kategorie laden
  const [productsResult, categoriesResult] = await Promise.all([
    getClient().query<{ products: { nodes: ProductCardData[] } }>({
      query: GET_PRODUCTS,
      variables: { categorySlug: params.slug, first: 24 },
    }),
    getClient().query<{ productCategories: { nodes: ProductCategory[] } }>({
      query: GET_PRODUCT_CATEGORIES,
    }),
  ])

  const products = productsResult.data?.products?.nodes ?? []
  const categories = categoriesResult.data?.productCategories?.nodes ?? []
  const currentCategory = categories.find((c) => c.slug === params.slug)

  if (!currentCategory) {
    notFound()
  }

  return (
    <main id="main-content">
      <div className="mb-6">
        <h1 className="text-3xl font-bold tracking-tight text-text-primary">
          {currentCategory.name}
        </h1>
      </div>

      {/* Horizontale Filter-Chips */}
      <FilterChips
        categories={categories}
        currentSlug={params.slug}
      />

      {/* Produktanzahl */}
      <p className="text-sm text-text-secondary mb-6">
        {products.length === 0
          ? 'Keine Produkte'
          : `${products.length} ${products.length === 1 ? 'Produkt' : 'Produkte'}`}
      </p>

      {/* Produktgrid */}
      {products.length === 0 ? (
        <div className="text-center py-16">
          <p className="text-text-secondary">Keine Produkte in dieser Kategorie</p>
        </div>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
          {products.map((product) => (
            <ProductCard key={product.id} product={product} />
          ))}
        </div>
      )}
    </main>
  )
}
```

### 11. Produktdetailseite

**Datei:** `frontend/app/produkt/[slug]/page.tsx`

```typescript
import { notFound } from 'next/navigation'
import { getClient } from '@/lib/apollo/server-client'
import { GET_PRODUCT, GET_ALL_PRODUCT_SLUGS } from '@/lib/graphql/queries'
import { ProductImageGallery } from '@/components/product/product-image-gallery'
import { ProductVariantSelector } from './product-variant-selector'
import { VariantSelectorSize } from '@/components/product/variant-selector-size'
import { VariantSelectorColor } from '@/components/product/variant-selector-color'
import { AddToCartButton } from '@/components/product/add-to-cart-button'
import { generateProductJsonLd } from '@/lib/seo/json-ld'
import { extractVariantOptions } from '@/lib/product/variant-utils'
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

export async function generateMetadata({ params }: { params: { slug: string } }) {
  const { data } = await getClient().query<{ product: ProductDetailData }>({
    query: GET_PRODUCT,
    variables: { slug: params.slug },
  })

  const product = data?.product
  if (!product) return { title: 'Produkt nicht gefunden' }

  return {
    title: `${product.name} | POD Shop`,
    description: product.shortDescription ?? product.description?.substring(0, 160),
    openGraph: {
      images: product.image ? [{ url: product.image.sourceUrl }] : [],
    },
  }
}

interface ProductPageProps {
  params: { slug: string }
}

export default async function ProductPage({ params }: ProductPageProps) {
  const { data } = await getClient().query<{ product: ProductDetailData }>({
    query: GET_PRODUCT,
    variables: { slug: params.slug },
  })

  const product = data?.product
  if (!product) notFound()

  const allImages = [
    ...(product.image ? [product.image] : []),
    ...(product.galleryImages?.nodes ?? []),
  ]

  const variantOptions = product.variations
    ? extractVariantOptions(product.variations.nodes)
    : { sizes: [], colors: [] }

  const pageUrl = `${process.env.NEXT_PUBLIC_SHOP_URL ?? 'http://localhost:3000'}/produkt/${params.slug}`
  const jsonLd = generateProductJsonLd(product, pageUrl)

  return (
    <>
      {/* JSON-LD für SEO */}
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: jsonLd }}
      />

      <main id="main-content">
        {/* Mobile: Stack. Desktop: 2-Spalten Grid */}
        <div className="lg:grid lg:grid-cols-2 lg:gap-12">
          {/* Bild-Galerie */}
          <ProductImageGallery
            images={allImages}
            productName={product.name}
          />

          {/* Produkt-Infos */}
          <div className="mt-6 lg:mt-0">
            <h1 className="text-2xl font-bold tracking-tight text-text-primary">
              {product.name}
            </h1>

            <p className="mt-2 text-2xl font-semibold text-text-primary">
              {product.price}
            </p>
            <p className="mt-1 text-sm text-text-secondary">inkl. Versandkosten</p>

            <hr className="my-6 border-border" />

            {/* Variant-Selector als Client Component */}
            <ProductVariantSelector
              product={product}
              variantOptions={variantOptions}
            />
          </div>
        </div>

        {/* Beschreibung */}
        {product.description && (
          <div className="mt-10">
            <hr className="mb-6 border-border" />
            <h2 className="text-lg font-semibold mb-3">Beschreibung</h2>
            <div
              className="prose prose-sm text-text-secondary max-w-none"
              dangerouslySetInnerHTML={{ __html: product.description }}
            />
          </div>
        )}
      </main>
    </>
  )
}
```

**Datei:** `frontend/app/produkt/[slug]/product-variant-selector.tsx`

```typescript
'use client'

import { useState } from 'react'
import { VariantSelectorSize } from '@/components/product/variant-selector-size'
import { VariantSelectorColor } from '@/components/product/variant-selector-color'
import { AddToCartButton } from '@/components/product/add-to-cart-button'
import { findVariation } from '@/lib/product/variant-utils'
import type { ProductDetailData, VariantOptions, SelectedVariant } from '@/lib/graphql/types'

interface ProductVariantSelectorProps {
  product: ProductDetailData
  variantOptions: VariantOptions
}

export function ProductVariantSelector({ product, variantOptions }: ProductVariantSelectorProps) {
  const [selectedVariant, setSelectedVariant] = useState<SelectedVariant>({
    size: null,
    color: null,
  })

  const matchedVariation =
    product.variations && selectedVariant.size && selectedVariant.color
      ? findVariation(product.variations.nodes, selectedVariant.size, selectedVariant.color)
      : null

  const canAddToCart =
    selectedVariant.size !== null &&
    selectedVariant.color !== null &&
    (matchedVariation?.stockStatus === 'IN_STOCK' || matchedVariation === null)

  return (
    <div className="space-y-6">
      {/* Farb-Selector */}
      {variantOptions.colors.length > 0 && (
        <VariantSelectorColor
          colors={variantOptions.colors}
          selectedColor={selectedVariant.color}
          onColorSelect={(color) =>
            setSelectedVariant((prev) => ({ ...prev, color }))
          }
        />
      )}

      {/* Größen-Selector */}
      {variantOptions.sizes.length > 0 && (
        <VariantSelectorSize
          sizes={variantOptions.sizes}
          selectedSize={selectedVariant.size}
          onSizeSelect={(size) =>
            setSelectedVariant((prev) => ({ ...prev, size }))
          }
        />
      )}

      {/* "In den Warenkorb"-Button */}
      <AddToCartButton
        productId={product.databaseId}
        variationId={matchedVariation?.databaseId ?? null}
        canAdd={canAddToCart}
        hasVariants={variantOptions.sizes.length > 0 || variantOptions.colors.length > 0}
        // onAddToCart wird in Slice 3 implementiert
        onAddToCart={undefined}
      />
    </div>
  )
}
```

---

## UI Anforderungen

### Wireframe (aus wireframes.md)

> **Quelle:** `wireframes.md` → Screen: Homepage, Screen: Category Page, Screen: Product Detail Page, Shared: Header, Shared: Footer

**Referenz Skills für UI-Implementation:**
- `.claude/skills/react-best-practices/SKILL.md` – Suspense Boundaries, Memo, async-parallel
- `.claude/skills/web-design/SKILL.md` – Accessibility, Touch Targets, Images
- `.claude/skills/tailwind-v4/SKILL.md` – Design Tokens, Mobile-first, @theme

#### Homepage Wireframe (Mobile)
```
┌─────────────────────────────────────┐
│          [Header]                   │
├─────────────────────────────────────┤
│  ┌─────────────────────────────┐    │
│  │      ① HERO IMAGE           │    │
│  │    Tagline / Claim          │    │
│  │    [ Jetzt shoppen ]        │    │
│  └─────────────────────────────┘    │
│                                     │
│  ② Ausgewählte Produkte             │
│  ┌───────────┐  ┌───────────┐      │
│  │  ③[img]   │  │  ③[img]   │      │
│  │  Name     │  │  Name     │      │
│  │  29,99 €  │  │  24,99 €  │      │
│  └───────────┘  └───────────┘      │
│                                     │
│  ④ Kategorien                       │
│  ┌─────────────────────────────┐    │
│  │  [img]  T-Shirts     →     │    │
│  └─────────────────────────────┘    │
│          [Footer]                   │
└─────────────────────────────────────┘
```

#### Kategorieseite Wireframe (Mobile)
```
┌─────────────────────────────────────┐
│  ① T-Shirts                         │
│  ② Filter: [Alle] [T-Shirts] [...]  │
│  ③ 12 Produkte                      │
│  ┌───────────┐  ┌───────────┐      │
│  │  ④[img]   │  │  ④[img]   │      │
│  │  Name     │  │  Name     │      │
│  │  29,99 €  │  │  24,99 €  │      │
│  └───────────┘  └───────────┘      │
└─────────────────────────────────────┘
```

#### Produktdetailseite Wireframe (Mobile)
```
┌─────────────────────────────────────┐
│  ┌─────────────────────────────┐    │
│  │      ① PRODUCT IMAGE        │    │
│  └─────────────────────────────┘    │
│    ○  ●  ○  ○  (image dots)        │
│  ② Product Name                     │
│  ③ 29,99 €  / inkl. Versandkosten  │
│  ④ Farbe: Schwarz                   │
│  [⬛] [⬜] [🔵] [🔴]              │
│  ⑤ Größe                            │
│  [ S ] [ M ] [  L  ] [ XL ] [XXL]  │
│  ┌─────────────────────────────┐    │
│  │     ⑥ IN DEN WARENKORB      │    │
│  └─────────────────────────────┘    │
│  ⑦ Beschreibung                     │
└─────────────────────────────────────┘
```

### 1. Header Component

**Datei:** `frontend/components/layout/header.tsx`

**Komponenten & Dateien:**
- `components/layout/header.tsx` – Server Component (Navigation + Logo)
- `components/layout/mobile-menu.tsx` – Client Component (Hamburger-Menü State)

**Verhalten:**
- Mobile: Hamburger ☰ links, Logo zentriert, Warenkorb-Icon rechts (Badge in Slice 3)
- Desktop: Logo links, Kategorie-Links inline, Warenkorb-Icon rechts
- Aktive Kategorie wird mit `aria-current="page"` markiert

**Zustände:**
- Mobile Menu geschlossen: Hamburger sichtbar
- Mobile Menu offen: Overlay mit Kategorie-Links, Legal-Links, "Mein Konto"-Link
- Cart leer: Kein Badge (Badge-Counter wird in Slice 3 implementiert)

**Code-Beispiel:**

```typescript
// frontend/components/layout/header.tsx
import Link from 'next/link'
import { getClient } from '@/lib/apollo/server-client'
import { GET_PRODUCT_CATEGORIES } from '@/lib/graphql/queries'
import { MobileMenu } from './mobile-menu'
import type { ProductCategory } from '@/lib/graphql/types'

export async function Header() {
  const { data } = await getClient().query<{
    productCategories: { nodes: ProductCategory[] }
  }>({
    query: GET_PRODUCT_CATEGORIES,
  })

  const categories = data?.productCategories?.nodes ?? []

  return (
    <header className="sticky top-0 z-40 bg-surface border-b border-border">
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:px-4 focus:py-2 focus:bg-primary focus:text-white focus:rounded"
      >
        Zum Hauptinhalt springen
      </a>

      <div className="max-w-7xl mx-auto px-4 h-14 flex items-center justify-between">
        {/* Mobile: Hamburger */}
        <div className="flex lg:hidden">
          <MobileMenu categories={categories} />
        </div>

        {/* Logo */}
        <Link
          href="/"
          className="font-bold text-lg text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded"
        >
          POD Shop
        </Link>

        {/* Desktop: Kategorie-Navigation */}
        <nav aria-label="Hauptnavigation" className="hidden lg:flex items-center gap-6">
          {categories.map((category) => (
            <Link
              key={category.id}
              href={`/kategorie/${category.slug}`}
              className="text-sm text-text-secondary hover:text-text-primary transition-colors focus-visible:ring-2 focus-visible:ring-primary rounded"
            >
              {category.name}
            </Link>
          ))}
        </nav>

        {/* Warenkorb-Icon (Badge-Counter in Slice 3) */}
        <Link
          href="/warenkorb"
          aria-label="Warenkorb"
          className="relative p-2 min-h-[44px] min-w-[44px] flex items-center justify-center focus-visible:ring-2 focus-visible:ring-primary rounded"
        >
          <svg
            aria-hidden="true"
            className="w-6 h-6 text-text-primary"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"
            />
          </svg>
        </Link>
      </div>
    </header>
  )
}
```

### 2. Produkt-Card Component

**Datei:** `frontend/components/product/product-card.tsx`

**Zustände (aus wireframes.md + discovery.md):**
- Default: Produktbild, Name, Preis
- Hover (Desktop): Subtle scale/shadow (`hover:shadow-md hover:-translate-y-0.5 transition-all`)
- Loading: `ProductCardSkeleton` (separates Component)
- Sold-Out: Overlay "Ausverkauft" + abgedunkeltes Bild

**Code-Beispiel:**

```typescript
// frontend/components/product/product-card.tsx
import Image from 'next/image'
import Link from 'next/link'
import type { ProductCardData } from '@/lib/graphql/types'

interface ProductCardProps {
  product: ProductCardData
}

export function ProductCard({ product }: ProductCardProps) {
  const isSoldOut = product.stockStatus === 'OUT_OF_STOCK'

  return (
    <Link
      href={`/produkt/${product.slug}`}
      className="group block rounded-xl overflow-hidden border border-border bg-surface hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 focus-visible:ring-2 focus-visible:ring-primary"
    >
      {/* Produktbild */}
      <div className="relative aspect-square bg-surface-elevated overflow-hidden">
        {product.image ? (
          <Image
            src={product.image.sourceUrl}
            alt={product.image.altText || product.name}
            fill
            sizes="(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 25vw"
            className="object-cover group-hover:scale-105 transition-transform duration-300"
          />
        ) : (
          <div className="w-full h-full flex items-center justify-center text-text-secondary text-sm">
            Kein Bild
          </div>
        )}

        {/* Sold-Out Overlay */}
        {isSoldOut && (
          <div className="absolute inset-0 bg-black/50 flex items-center justify-center">
            <span className="text-white font-medium text-sm px-3 py-1 bg-black/70 rounded">
              Ausverkauft
            </span>
          </div>
        )}
      </div>

      {/* Produktinfos */}
      <div className="p-3">
        <h3 className="text-sm font-medium text-text-primary line-clamp-2 min-w-0">
          {product.name}
        </h3>
        <p className="mt-1 text-sm font-semibold text-text-primary tabular-nums">
          {product.price ?? '—'}
        </p>
      </div>
    </Link>
  )
}
```

**Datei:** `frontend/components/product/product-card-skeleton.tsx`

```typescript
// frontend/components/product/product-card-skeleton.tsx
export function ProductCardSkeleton() {
  return (
    <div
      className="rounded-xl overflow-hidden border border-border bg-surface animate-pulse"
      aria-label="loading"
    >
      <div className="aspect-square bg-surface-elevated" />
      <div className="p-3 space-y-2">
        <div className="h-4 bg-surface-elevated rounded w-3/4" />
        <div className="h-4 bg-surface-elevated rounded w-1/3" />
      </div>
    </div>
  )
}
```

**Datei:** `frontend/app/kategorie/[slug]/loading.tsx`

> **Zweck:** Next.js App Router rendert diese Datei automatisch als Suspense-Fallback waehrend `page.tsx` laedt. Zeigt Skeleton-Cards in identischem Grid-Layout wie die echte Produktliste.

```typescript
// frontend/app/kategorie/[slug]/loading.tsx
import { ProductCardSkeleton } from '@/components/product/product-card-skeleton'

export default function CategoryPageLoading() {
  return (
    <main id="main-content">
      {/* Titel-Skeleton */}
      <div className="mb-6">
        <div className="h-9 w-48 bg-surface-elevated rounded animate-pulse" />
      </div>

      {/* Filter-Chips-Skeleton */}
      <div className="mb-6 flex gap-2 overflow-x-auto pb-2">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="h-10 w-24 bg-surface-elevated rounded-full flex-shrink-0 animate-pulse" />
        ))}
      </div>

      {/* Produktanzahl-Skeleton */}
      <div className="h-4 w-24 bg-surface-elevated rounded mb-6 animate-pulse" />

      {/* Produktgrid-Skeleton */}
      <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {Array.from({ length: 8 }).map((_, i) => (
          <ProductCardSkeleton key={i} />
        ))}
      </div>
    </main>
  )
}
```

**Datei:** `frontend/app/produkt/[slug]/loading.tsx`

> **Zweck:** Next.js App Router rendert diese Datei automatisch als Suspense-Fallback waehrend `page.tsx` laedt. Zeigt Skeleton fuer Bild, Textbloecke und Button entsprechend dem Wireframe-Layout der Produktdetailseite.

```typescript
// frontend/app/produkt/[slug]/loading.tsx
export default function ProductPageLoading() {
  return (
    <main id="main-content">
      <div className="lg:grid lg:grid-cols-2 lg:gap-12">
        {/* Bild-Skeleton */}
        <div className="aspect-square bg-surface-elevated rounded-xl animate-pulse" />

        {/* Produkt-Info-Skeleton */}
        <div className="mt-6 lg:mt-0 space-y-4">
          {/* Titel */}
          <div className="h-8 w-3/4 bg-surface-elevated rounded animate-pulse" />
          {/* Preis */}
          <div className="h-8 w-1/3 bg-surface-elevated rounded animate-pulse" />
          {/* Versandkosten */}
          <div className="h-4 w-40 bg-surface-elevated rounded animate-pulse" />

          <hr className="border-border" />

          {/* Farb-Selector-Skeleton */}
          <div className="space-y-3">
            <div className="h-4 w-16 bg-surface-elevated rounded animate-pulse" />
            <div className="flex gap-3">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="w-9 h-9 rounded-full bg-surface-elevated animate-pulse" />
              ))}
            </div>
          </div>

          {/* Groessen-Selector-Skeleton */}
          <div className="space-y-3">
            <div className="h-4 w-12 bg-surface-elevated rounded animate-pulse" />
            <div className="flex gap-2 flex-wrap">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="h-11 w-14 bg-surface-elevated rounded-lg animate-pulse" />
              ))}
            </div>
          </div>

          {/* Button-Skeleton */}
          <div className="h-13 w-full bg-surface-elevated rounded-xl animate-pulse" />
        </div>
      </div>
    </main>
  )
}
```

### 3. Varianten-Selector Größe

**Datei:** `frontend/components/product/variant-selector-size.tsx`

**Zustände (aus wireframes.md + discovery.md):**
- Verfügbar: normaler Button
- Ausgewählt: gefüllter Hintergrund (`bg-text-primary text-surface`)
- Nicht verfügbar: ausgegraut, `aria-disabled="true"`, nicht klickbar

**Code-Beispiel:**

```typescript
// frontend/components/product/variant-selector-size.tsx
'use client'

interface SizeOption {
  value: string
  available: boolean
}

interface VariantSelectorSizeProps {
  sizes: SizeOption[]
  selectedSize: string | null
  onSizeSelect: (size: string) => void
}

export function VariantSelectorSize({
  sizes,
  selectedSize,
  onSizeSelect,
}: VariantSelectorSizeProps) {
  return (
    <fieldset>
      <legend className="text-sm font-medium text-text-primary mb-3">Größe</legend>
      <div className="flex flex-wrap gap-2" role="group" aria-label="Größe auswählen">
        {sizes.map(({ value, available }) => {
          const isSelected = selectedSize === value
          return (
            <button
              key={value}
              type="button"
              onClick={() => available && onSizeSelect(value)}
              aria-pressed={isSelected}
              aria-disabled={!available}
              disabled={!available}
              className={[
                'min-h-[44px] min-w-[44px] px-4 py-2 rounded-lg border text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1',
                isSelected
                  ? 'bg-text-primary text-surface border-text-primary'
                  : available
                    ? 'bg-surface text-text-primary border-border hover:border-text-primary'
                    : 'bg-surface-elevated text-text-secondary border-border opacity-50 cursor-not-allowed',
              ].join(' ')}
            >
              {value}
            </button>
          )
        })}
      </div>
    </fieldset>
  )
}
```

### 4. Varianten-Selector Farbe

**Datei:** `frontend/components/product/variant-selector-color.tsx`

**Zustände (aus wireframes.md + discovery.md):**
- Verfügbar: Farbswatch normal
- Ausgewählt: Ring/Border um Swatch (`ring-2 ring-text-primary ring-offset-2`)
- Nicht verfügbar: ausgegraut + durchgestrichen (CSS `line-through` via overlay)

**Code-Beispiel:**

```typescript
// frontend/components/product/variant-selector-color.tsx
'use client'

interface ColorOption {
  value: string
  hexCode: string | null
  available: boolean
}

interface VariantSelectorColorProps {
  colors: ColorOption[]
  selectedColor: string | null
  onColorSelect: (color: string) => void
}

export function VariantSelectorColor({
  colors,
  selectedColor,
  onColorSelect,
}: VariantSelectorColorProps) {
  return (
    <fieldset>
      <legend className="text-sm font-medium text-text-primary mb-3">
        Farbe: <span className="font-normal">{selectedColor ?? 'Bitte wählen'}</span>
      </legend>
      <div className="flex flex-wrap gap-3" role="group" aria-label="Farbe auswählen">
        {colors.map(({ value, hexCode, available }) => {
          const isSelected = selectedColor === value
          return (
            <button
              key={value}
              type="button"
              onClick={() => available && onColorSelect(value)}
              aria-label={`${value}${!available ? ' (nicht verfügbar)' : ''}`}
              aria-pressed={isSelected}
              aria-disabled={!available}
              disabled={!available}
              className={[
                'relative w-9 h-9 rounded-full border-2 transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
                isSelected ? 'ring-2 ring-text-primary ring-offset-2' : '',
                !available ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:scale-110',
                hexCode ? 'border-border' : 'border-border',
              ].join(' ')}
              style={hexCode ? { backgroundColor: hexCode } : undefined}
            >
              {!hexCode && (
                <span className="sr-only">{value}</span>
              )}
              {/* Strikethrough-Overlay für nicht verfügbare Farben */}
              {!available && (
                <span
                  aria-hidden="true"
                  className="absolute inset-0 flex items-center justify-center"
                >
                  <svg
                    className="w-full h-full text-text-secondary opacity-70"
                    viewBox="0 0 36 36"
                    fill="none"
                  >
                    <line x1="6" y1="6" x2="30" y2="30" stroke="currentColor" strokeWidth="2" />
                  </svg>
                </span>
              )}
            </button>
          )
        })}
      </div>
    </fieldset>
  )
}
```

### 5. Produkt-Bild-Galerie

**Datei:** `frontend/components/product/product-image-gallery.tsx`

**Verhalten (aus wireframes.md):**
- Mobile: Swipe-Navigation mit Dot-Indikatoren
- Desktop: Hauptbild + Thumbnail-Reihe darunter

**Code-Beispiel:**

```typescript
// frontend/components/product/product-image-gallery.tsx
'use client'

import { useState } from 'react'
import Image from 'next/image'
import type { ProductImage } from '@/lib/graphql/types'

interface ProductImageGalleryProps {
  images: ProductImage[]
  productName: string
}

export function ProductImageGallery({ images, productName }: ProductImageGalleryProps) {
  const [activeIndex, setActiveIndex] = useState(0)
  const [touchStart, setTouchStart] = useState<number | null>(null)

  if (images.length === 0) {
    return (
      <div className="aspect-square bg-surface-elevated rounded-xl flex items-center justify-center text-text-secondary">
        Kein Bild vorhanden
      </div>
    )
  }

  const activeImage = images[activeIndex]

  const handleTouchStart = (e: React.TouchEvent) => {
    setTouchStart(e.touches[0].clientX)
  }

  const handleTouchEnd = (e: React.TouchEvent) => {
    if (touchStart === null) return
    const delta = touchStart - e.changedTouches[0].clientX
    if (Math.abs(delta) > 50) {
      if (delta > 0) {
        setActiveIndex((prev) => Math.min(prev + 1, images.length - 1))
      } else {
        setActiveIndex((prev) => Math.max(prev - 1, 0))
      }
    }
    setTouchStart(null)
  }

  return (
    <div>
      {/* Hauptbild mit Touch-Swipe-Unterstuetzung (Mobile) */}
      <div
        className="relative aspect-square rounded-xl overflow-hidden bg-surface-elevated"
        role="region"
        aria-label="Produktbilder"
        onTouchStart={handleTouchStart}
        onTouchEnd={handleTouchEnd}
      >
        <Image
          src={activeImage.sourceUrl}
          alt={activeImage.altText || `${productName} – Bild ${activeIndex + 1}`}
          fill
          priority={activeIndex === 0}
          sizes="(max-width: 1024px) 100vw, 50vw"
          className="object-cover"
        />
      </div>

      {/* Dots (Mobile) / Thumbnails (Desktop) */}
      {images.length > 1 && (
        <>
          {/* Mobile: Dot-Navigation */}
          <div
            className="flex lg:hidden justify-center gap-2 mt-3"
            role="tablist"
            aria-label="Bilder-Navigation"
          >
            {images.map((_, index) => (
              <button
                key={index}
                role="tab"
                aria-selected={index === activeIndex}
                aria-label={`Bild ${index + 1}`}
                onClick={() => setActiveIndex(index)}
                className={[
                  'w-2 h-2 rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-primary',
                  index === activeIndex
                    ? 'bg-text-primary'
                    : 'bg-border hover:bg-text-secondary',
                ].join(' ')}
              />
            ))}
          </div>

          {/* Desktop: Thumbnail-Reihe */}
          <div
            className="hidden lg:flex gap-2 mt-3"
            role="tablist"
            aria-label="Bilder-Navigation"
          >
            {images.map((image, index) => (
              <button
                key={index}
                role="tab"
                aria-selected={index === activeIndex}
                aria-label={image.altText || `Bild ${index + 1}`}
                onClick={() => setActiveIndex(index)}
                className={[
                  'relative w-16 h-16 rounded-lg overflow-hidden border-2 transition-colors focus-visible:ring-2 focus-visible:ring-primary',
                  index === activeIndex ? 'border-text-primary' : 'border-border hover:border-text-secondary',
                ].join(' ')}
              >
                <Image
                  src={image.sourceUrl}
                  alt={image.altText || `${productName} – Thumbnail ${index + 1}`}
                  fill
                  sizes="64px"
                  className="object-cover"
                />
              </button>
            ))}
          </div>
        </>
      )}
    </div>
  )
}
```

### 6. AddToCart Button (Vorbereitung Slice 3)

**Datei:** `frontend/components/product/add-to-cart-button.tsx`

**Zustände (aus wireframes.md + discovery.md):**
- Disabled – keine Variante gewählt: Text "Bitte Größe und Farbe wählen", `disabled`
- Default – Variante gewählt: "In den Warenkorb", klickbar
- Loading: Spinner + disabled (in Slice 3 aktiviert)
- Erfolg: Kurze Bestätigung "Hinzugefügt!" (in Slice 3 aktiviert)

**Code-Beispiel:**

```typescript
// frontend/components/product/add-to-cart-button.tsx
'use client'

interface AddToCartButtonProps {
  productId: number
  variationId: number | null
  canAdd: boolean
  hasVariants: boolean
  onAddToCart?: (productId: number, variationId: number | null) => Promise<void>
}

export function AddToCartButton({
  productId,
  variationId,
  canAdd,
  hasVariants,
  onAddToCart,
}: AddToCartButtonProps) {
  const buttonText = !hasVariants
    ? 'In den Warenkorb'
    : canAdd
      ? 'In den Warenkorb'
      : 'Bitte Größe und Farbe wählen'

  const handleClick = () => {
    if (canAdd && onAddToCart) {
      onAddToCart(productId, variationId)
    }
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      disabled={!canAdd}
      aria-disabled={!canAdd}
      className={[
        'w-full min-h-[52px] px-6 py-3 rounded-xl font-semibold text-base transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
        canAdd
          ? 'bg-primary text-white hover:bg-primary-hover active:scale-95'
          : 'bg-surface-elevated text-text-secondary cursor-not-allowed',
      ].join(' ')}
    >
      {buttonText}
    </button>
  )
}
```

### 7. Filter-Chips

**Datei:** `frontend/components/category/filter-chips.tsx`

**Verhalten (aus wireframes.md):**
- Horizontale, scrollbare Reihe auf Mobile
- Aktiver Filter ist hervorgehoben

**Code-Beispiel:**

```typescript
// frontend/components/category/filter-chips.tsx
import Link from 'next/link'
import type { ProductCategory } from '@/lib/graphql/types'

interface FilterChipsProps {
  categories: ProductCategory[]
  currentSlug: string
}

export function FilterChips({ categories, currentSlug }: FilterChipsProps) {
  return (
    <nav
      aria-label="Kategorie-Filter"
      className="mb-6"
    >
      <div className="flex gap-2 overflow-x-auto pb-2 scrollbar-none -mx-4 px-4">
        <Link
          href="/produkte"
          aria-current={currentSlug === 'alle' ? 'page' : undefined}
          className={[
            'flex-shrink-0 px-4 py-2 rounded-full text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center',
            currentSlug === 'alle'
              ? 'bg-text-primary text-surface'
              : 'bg-surface-elevated text-text-primary hover:bg-border',
          ].join(' ')}
        >
          Alle
        </Link>
        {categories.map((category) => (
          <Link
            key={category.id}
            href={`/kategorie/${category.slug}`}
            aria-current={category.slug === currentSlug ? 'page' : undefined}
            className={[
              'flex-shrink-0 px-4 py-2 rounded-full text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center',
              category.slug === currentSlug
                ? 'bg-text-primary text-surface'
                : 'bg-surface-elevated text-text-primary hover:bg-border',
            ].join(' ')}
          >
            {category.name}
          </Link>
        ))}
      </div>
    </nav>
  )
}
```

### 8. Accessibility Checklist

- [x] Alle interaktiven Elemente haben `focus-visible` States (`focus-visible:ring-2`)
- [x] Icon-only Buttons haben `aria-label` (Warenkorb-Icon im Header)
- [x] Bilder haben `alt` Text (ProductCard, ProductImageGallery)
- [x] Bilder haben explizite `width`/`height` via `fill` + `sizes` (kein CLS)
- [x] Varianten-Buttons haben `aria-pressed` State
- [x] Nicht verfügbare Optionen haben `aria-disabled="true"`
- [x] Galerie-Navigation hat `role="tablist"` + `aria-selected`
- [x] Headings-Hierarchie: `h1` pro Seite, `h2` für Sektionen
- [x] Skip-Link "Zum Hauptinhalt springen" im Header
- [x] `touch-action: manipulation` auf allen Buttons (via globals.css in Slice 1)
- [x] Touch Targets min 44px (`min-h-[44px]`)

### 9. Skill Verification

**React Best Practices:**
- [x] `async-parallel`: `Promise.all()` in CategoryPage für parallele Queries
- [x] `async-suspense-boundaries`: Suspense in HomePage für FeaturedProducts + CategoryOverview
- [x] `bundle-dynamic-imports`: ProductVariantSelector als Client Component, RSC für alles andere
- [x] `server-parallel-fetching`: Alle Initial-Daten als RSC per SSG

**Tailwind v4:**
- [x] `@theme` Tokens aus Slice 1 genutzt (`primary`, `surface`, `text-primary`, `border`)
- [x] Keine hardcoded Hex-Farben in Klassen
- [x] Mobile-first Klassen (`grid-cols-2 lg:grid-cols-4`)
- [x] `tabular-nums` für Preisdarstellung

**Web Design:**
- [x] `prefers-reduced-motion`: `animate-pulse` für Skeletons (CSS respektiert `prefers-reduced-motion` automatisch in Tailwind v4)
- [x] `touch-action: manipulation` (gesetzt in globals.css Slice 1)
- [x] Keine `outline-none` ohne Ersatz

---

## Acceptance Criteria

1) GIVEN die Next.js App ist gestartet (`pnpm dev`)
   WHEN ein Nutzer `http://localhost:3000` aufruft
   THEN zeigt die Homepage den Hero-Bereich, Featured Products aus WooCommerce und eine Kategorien-Übersicht

2) GIVEN die Homepage lädt
   WHEN WooCommerce noch keine Featured Products hat
   THEN ist der Featured Products Bereich ausgeblendet (kein leerer Bereich sichtbar)

3) GIVEN die Next.js App rendert die Homepage via React Server Components Streaming
   WHEN die Suspense-Boundary fuer den FeaturedProducts-Block noch nicht aufgeloest ist
   THEN zeigt der Browser sofort 4 ProductCardSkeleton-Elemente (animate-pulse) anstelle der echten Produktkarten

4) GIVEN ein Nutzer ist auf der Homepage
   WHEN er auf eine Kategorie-Kachel klickt (z.B. "T-Shirts")
   THEN wird er zur Kategorieseite `/kategorie/t-shirts` navigiert

5) GIVEN ein Nutzer ist auf einer Kategorieseite
   WHEN die Seite geladen ist
   THEN werden alle Produkte dieser Kategorie im 2-Spalten Grid (Mobil) bzw. 3-4 Spalten (Desktop) angezeigt

6) GIVEN ein Nutzer ist auf einer Kategorieseite
   WHEN er auf einen anderen Kategorie-Filter-Chip klickt
   THEN wird er zur entsprechenden Kategorieseite navigiert

7) GIVEN eine Kategorieseite hat keine Produkte
   WHEN die Seite geladen ist
   THEN wird "Keine Produkte in dieser Kategorie" angezeigt (kein leerer Grid)

8) GIVEN ein Nutzer ist auf einer Kategorieseite
   WHEN er auf eine Produkt-Card klickt
   THEN wird er zur Produktdetailseite `/produkt/[slug]` navigiert

9) GIVEN ein Nutzer ist auf einer Produktdetailseite
   WHEN die Seite geladen ist
   THEN werden Produktbild-Galerie, Name, Preis mit "inkl. Versandkosten", Farb-Selector, Größen-Selector, "In den Warenkorb"-Button und Beschreibung angezeigt

10) GIVEN ein Nutzer ist auf einer Produktdetailseite
    WHEN er noch keine Farbe und Größe gewählt hat
    THEN ist der "In den Warenkorb"-Button disabled mit dem Text "Bitte Größe und Farbe wählen"

11) GIVEN ein Nutzer ist auf einer Produktdetailseite
    WHEN er eine verfügbare Größe auswählt
    THEN wird der Größen-Button mit gefülltem Hintergrund dargestellt (`aria-pressed="true"`)

12) GIVEN ein Nutzer ist auf einer Produktdetailseite
    WHEN er eine verfügbare Farbe auswählt
    THEN wird der Farb-Swatch mit Ring/Border dargestellt

13) GIVEN ein Nutzer ist auf einer Produktdetailseite
    WHEN er sowohl Größe als auch Farbe gewählt hat
    THEN ist der "In den Warenkorb"-Button aktiv und zeigt "In den Warenkorb"

14) GIVEN es gibt eine nicht verfügbare Größe
    WHEN die Produktdetailseite geladen ist
    THEN ist der entsprechende Größen-Button ausgegraut und nicht klickbar (`aria-disabled="true"`)

15) GIVEN es gibt eine nicht verfügbare Farbe
    WHEN die Produktdetailseite geladen ist
    THEN ist der Farb-Swatch ausgegraut mit durchgestrichenem Diagonal-Overlay

16) GIVEN ein Nutzer ist auf einer Produktdetailseite mit mehreren Bildern
    WHEN er auf ein Thumbnail (Desktop) klickt oder Dots (Mobile) antippt
    THEN wechselt das Hauptbild zum ausgewählten Bild

17) GIVEN Next.js baut die App (`pnpm build`)
    WHEN SSG läuft
    THEN werden alle Produkt-Slugs und Kategorie-Slugs per `generateStaticParams()` statisch vorgebaut

18) GIVEN die Produktdetailseite ist geladen
    WHEN der HTML-Source angezeigt wird
    THEN enthält `<head>` ein `<script type="application/ld+json">` mit korrektem Product Schema (name, price, priceCurrency: EUR)

---

## Testfälle

### Test-Datei

`tests/slices/pod-shop-mvp/slice-02-produktkatalog-frontend.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// tests/slices/pod-shop-mvp/slice-02-produktkatalog-frontend.test.ts
import { describe, it, expect } from 'vitest'
import { extractVariantOptions, findVariation } from '../../../frontend/lib/product/variant-utils'
import { generateProductJsonLd } from '../../../frontend/lib/seo/json-ld'
import type { ProductVariation, ProductDetailData } from '../../../frontend/lib/graphql/types'

// ──────────────────────────────────────────────
// AC-3: ProductCardSkeleton – Suspense Fallback
// ──────────────────────────────────────────────
describe('Slice 02: ProductCardSkeleton (AC-3)', () => {
  it('should render skeleton element with aria-label="loading"', async () => {
    // Arrange
    const { render } = await import('@testing-library/react')
    const { ProductCardSkeleton } = await import('../../../frontend/components/product/product-card-skeleton')
    // Act
    const { container } = render(ProductCardSkeleton({}))
    // Assert
    const skeletonEl = container.querySelector('[aria-label="loading"]')
    expect(skeletonEl).not.toBeNull()
  })

  it('should contain animate-pulse class for skeleton animation', async () => {
    // Arrange
    const { render } = await import('@testing-library/react')
    const { ProductCardSkeleton } = await import('../../../frontend/components/product/product-card-skeleton')
    // Act
    const { container } = render(ProductCardSkeleton({}))
    // Assert
    expect(container.firstChild).toHaveClass('animate-pulse')
  })
})

// ──────────────────────────────────────────────
// extractVariantOptions
// ──────────────────────────────────────────────
describe('Slice 02: extractVariantOptions', () => {
  const makeVariation = (
    id: string,
    size: string,
    color: string,
    stockStatus: 'IN_STOCK' | 'OUT_OF_STOCK' = 'IN_STOCK'
  ): ProductVariation => ({
    id,
    databaseId: parseInt(id),
    stockStatus,
    attributes: {
      nodes: [
        { name: 'pa_size', value: size },
        { name: 'pa_color', value: color },
      ],
    },
  })

  it('should extract unique sizes from variations', () => {
    // Arrange
    const variations = [
      makeVariation('1', 'S', 'Schwarz'),
      makeVariation('2', 'M', 'Schwarz'),
      makeVariation('3', 'L', 'Weiß'),
    ]
    // Act
    const result = extractVariantOptions(variations)
    // Assert
    expect(result.sizes.map((s) => s.value)).toEqual(['S', 'M', 'L'])
  })

  it('should extract unique colors from variations', () => {
    // Arrange
    const variations = [
      makeVariation('1', 'S', 'Schwarz'),
      makeVariation('2', 'M', 'Schwarz'),
      makeVariation('3', 'S', 'Weiß'),
    ]
    // Act
    const result = extractVariantOptions(variations)
    // Assert
    expect(result.colors.map((c) => c.value)).toEqual(['Schwarz', 'Weiß'])
  })

  it('should mark size as available if at least one variation with that size is IN_STOCK', () => {
    // Arrange
    const variations = [
      makeVariation('1', 'S', 'Schwarz', 'IN_STOCK'),
      makeVariation('2', 'S', 'Weiß', 'OUT_OF_STOCK'),
    ]
    // Act
    const result = extractVariantOptions(variations)
    // Assert
    const sizeS = result.sizes.find((s) => s.value === 'S')
    expect(sizeS?.available).toBe(true)
  })

  it('should mark size as unavailable if all variations with that size are OUT_OF_STOCK', () => {
    // Arrange
    const variations = [
      makeVariation('1', 'XL', 'Schwarz', 'OUT_OF_STOCK'),
      makeVariation('2', 'XL', 'Weiß', 'OUT_OF_STOCK'),
    ]
    // Act
    const result = extractVariantOptions(variations)
    // Assert
    const sizeXL = result.sizes.find((s) => s.value === 'XL')
    expect(sizeXL?.available).toBe(false)
  })

  it('should map known colors to hex codes', () => {
    // Arrange
    const variations = [
      makeVariation('1', 'S', 'schwarz'),
      makeVariation('2', 'M', 'weiß'),
    ]
    // Act
    const result = extractVariantOptions(variations)
    // Assert
    const black = result.colors.find((c) => c.value === 'schwarz')
    const white = result.colors.find((c) => c.value === 'weiß')
    expect(black?.hexCode).toBe('#000000')
    expect(white?.hexCode).toBe('#FFFFFF')
  })

  it('should return null hexCode for unknown colors', () => {
    // Arrange
    const variations = [makeVariation('1', 'S', 'Magenta')]
    // Act
    const result = extractVariantOptions(variations)
    // Assert
    const magenta = result.colors.find((c) => c.value === 'Magenta')
    expect(magenta?.hexCode).toBeNull()
  })

  it('should return empty sizes and colors for empty variations array', () => {
    // Arrange + Act
    const result = extractVariantOptions([])
    // Assert
    expect(result.sizes).toHaveLength(0)
    expect(result.colors).toHaveLength(0)
  })
})

// ──────────────────────────────────────────────
// findVariation
// ──────────────────────────────────────────────
describe('Slice 02: findVariation', () => {
  const makeVariation = (
    id: string,
    size: string,
    color: string,
    stockStatus: 'IN_STOCK' | 'OUT_OF_STOCK' = 'IN_STOCK'
  ): ProductVariation => ({
    id,
    databaseId: parseInt(id),
    stockStatus,
    attributes: {
      nodes: [
        { name: 'pa_size', value: size },
        { name: 'pa_color', value: color },
      ],
    },
  })

  it('should find matching variation for size and color', () => {
    // Arrange
    const variations = [
      makeVariation('1', 'S', 'Schwarz'),
      makeVariation('2', 'M', 'Weiß'),
      makeVariation('3', 'L', 'Schwarz'),
    ]
    // Act
    const result = findVariation(variations, 'L', 'Schwarz')
    // Assert
    expect(result?.id).toBe('3')
  })

  it('should return null when no matching variation exists', () => {
    // Arrange
    const variations = [
      makeVariation('1', 'S', 'Schwarz'),
      makeVariation('2', 'M', 'Weiß'),
    ]
    // Act
    const result = findVariation(variations, 'XL', 'Rot')
    // Assert
    expect(result).toBeNull()
  })

  it('should return null when selectedSize is null', () => {
    // Arrange
    const variations = [makeVariation('1', 'S', 'Schwarz')]
    // Act
    const result = findVariation(variations, null, 'Schwarz')
    // Assert
    expect(result).toBeNull()
  })

  it('should return null when selectedColor is null', () => {
    // Arrange
    const variations = [makeVariation('1', 'S', 'Schwarz')]
    // Act
    const result = findVariation(variations, 'S', null)
    // Assert
    expect(result).toBeNull()
  })

  it('should return null when both selectedSize and selectedColor are null', () => {
    // Arrange
    const variations = [makeVariation('1', 'S', 'Schwarz')]
    // Act
    const result = findVariation(variations, null, null)
    // Assert
    expect(result).toBeNull()
  })
})

// ──────────────────────────────────────────────
// generateProductJsonLd
// ──────────────────────────────────────────────
describe('Slice 02: generateProductJsonLd', () => {
  const makeProduct = (overrides: Partial<ProductDetailData> = {}): ProductDetailData => ({
    id: 'product-1',
    databaseId: 1,
    slug: 'test-shirt',
    name: 'Test T-Shirt',
    description: '<p>Ein tolles T-Shirt</p>',
    shortDescription: 'Ein tolles T-Shirt',
    price: '29,99&nbsp;€',
    regularPrice: '29,99&nbsp;€',
    onSale: false,
    image: {
      sourceUrl: 'http://localhost:8080/wp-content/uploads/shirt.jpg',
      altText: 'Test T-Shirt',
      mediaDetails: { width: 800, height: 800 },
    },
    galleryImages: { nodes: [] },
    productCategories: { nodes: [] },
    ...overrides,
  })

  it('should generate valid JSON-LD with @context and @type Product', () => {
    // Arrange
    const product = makeProduct()
    // Act
    const jsonLd = generateProductJsonLd(product, 'http://localhost:3000/produkt/test-shirt')
    const parsed = JSON.parse(jsonLd)
    // Assert
    expect(parsed['@context']).toBe('https://schema.org')
    expect(parsed['@type']).toBe('Product')
  })

  it('should include product name in JSON-LD', () => {
    // Arrange
    const product = makeProduct({ name: 'Premium Hoodie' })
    // Act
    const jsonLd = generateProductJsonLd(product, 'http://localhost:3000/produkt/premium-hoodie')
    const parsed = JSON.parse(jsonLd)
    // Assert
    expect(parsed.name).toBe('Premium Hoodie')
  })

  it('should include EUR as priceCurrency', () => {
    // Arrange
    const product = makeProduct()
    // Act
    const jsonLd = generateProductJsonLd(product, 'http://localhost:3000/produkt/test-shirt')
    const parsed = JSON.parse(jsonLd)
    // Assert
    expect(parsed.offers.priceCurrency).toBe('EUR')
  })

  it('should include product URL in JSON-LD', () => {
    // Arrange
    const product = makeProduct()
    const pageUrl = 'http://localhost:3000/produkt/test-shirt'
    // Act
    const jsonLd = generateProductJsonLd(product, pageUrl)
    const parsed = JSON.parse(jsonLd)
    // Assert
    expect(parsed.url).toBe(pageUrl)
  })

  it('should use shortDescription over description when available', () => {
    // Arrange
    const product = makeProduct({
      shortDescription: 'Kurze Beschreibung',
      description: '<p>Lange Beschreibung</p>',
    })
    // Act
    const jsonLd = generateProductJsonLd(product, 'http://localhost:3000/produkt/test')
    const parsed = JSON.parse(jsonLd)
    // Assert
    expect(parsed.description).toBe('Kurze Beschreibung')
  })

  it('should produce parseable JSON string', () => {
    // Arrange
    const product = makeProduct()
    // Act
    const jsonLd = generateProductJsonLd(product, 'http://localhost:3000/produkt/test-shirt')
    // Assert – kein Exception beim Parsen
    expect(() => JSON.parse(jsonLd)).not.toThrow()
  })
})
```
</test_spec>

### Manuelle Tests (Smoke Tests nach Implementierung)

1. `docker compose up -d && cd frontend && pnpm dev` → Beide starten ohne Fehler
2. `http://localhost:3000` → Homepage zeigt Hero, Featured Products Grid, Kategorien
3. Kategorien-Link klicken → Kategorieseite mit Produktgrid öffnet sich
4. Filter-Chip für andere Kategorie klicken → Produktliste wechselt
5. Produkt-Card klicken → Produktdetailseite mit Bildern, Preis, Varianten
6. Farb-Swatch wählen → Beschriftung aktualisiert, Ring um gewählte Farbe
7. Größen-Button wählen → Gefüllter Hintergrund
8. Beide gewählt → "In den Warenkorb"-Button wird aktiv
9. Nur Größe gewählt → Button zeigt "Bitte Größe und Farbe wählen"
10. Auf nicht verfügbare Größe klicken → Nichts passiert, Button bleibt ausgegraut
11. Desktop-Thumbnails klicken → Hauptbild wechselt
12. `pnpm build` → Build ohne Fehler, alle Static Paths generiert
13. HTML-Source der Produktdetailseite → `<script type="application/ld+json">` vorhanden
14. Mobile-Viewport (375px) → 2-Spalten-Grid, Touch-freundliche Buttons (min 44px)

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig & vollständig
- [ ] JSON-LD Product Schema implementiert (SEO)
- [ ] Skeleton Loading für alle Produktlisten implementiert
- [ ] Alle Produkt-Card States (Default/Hover/Loading/Sold-Out) implementiert
- [ ] Varianten-Selector korrekte States (Verfügbar/Ausgewählt/Nicht verfügbar)
- [ ] SSG/ISR: `generateStaticParams()` + `revalidate = 60` für alle Produktseiten
- [ ] Accessibility: Skip-Link, aria-labels, focus-visible, alt-Texte
- [ ] Mobile-first: Touch Targets 44px+, 2-Spalten Grid mobil

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-infrastruktur | `apolloClient` | Apollo Client Instance | Exportiert aus `frontend/lib/apollo/client.ts` |
| slice-01-infrastruktur | `ApolloWrapper` | React Component | Verfügbar in `frontend/components/apollo-wrapper.tsx` |
| slice-01-infrastruktur | `TokenManager` | Module | Verfügbar in `frontend/lib/apollo/token-manager.ts` (für Cart in Slice 3) |
| slice-01-infrastruktur | WPGraphQL Endpoint | HTTP API | `POST http://localhost:8080/graphql` erreichbar + antwortet |
| slice-01-infrastruktur | Tailwind CSS v4 Tokens | CSS Design Tokens | `--color-primary`, `--color-surface`, `--color-border`, `--color-text-primary` in `globals.css` |
| slice-01-infrastruktur | `next.config.ts` | Build Config | `remotePatterns` für `localhost:8080` konfiguriert (für `next/image`) |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `ProductDetailPage` | Next.js Page | slice-03-warenkorb | `/app/produkt/[slug]/page.tsx` mit `AddToCartButton` (onAddToCart Callback) |
| `AddToCartButton` | React Component | slice-03-warenkorb | `({ productId: number, variationId: number \| null, canAdd: boolean, hasVariants: boolean, onAddToCart?: (productId: number, variationId: number \| null) => Promise<void> }) => JSX` |
| `ProductCardData` | TypeScript Type | slice-03-warenkorb | Exportiert aus `frontend/lib/graphql/types.ts` |
| `ProductDetailData` | TypeScript Type | slice-03-warenkorb | Exportiert aus `frontend/lib/graphql/types.ts` |
| `ProductVariation` | TypeScript Type | slice-03-warenkorb | Exportiert aus `frontend/lib/graphql/types.ts` |
| `SelectedVariant` | TypeScript Type | slice-03-warenkorb | Exportiert aus `frontend/lib/graphql/types.ts` |
| `PRODUCT_CARD_FRAGMENT` | GraphQL Fragment | slice-03-warenkorb | Exportiert aus `frontend/lib/graphql/fragments.ts` |
| `PRODUCT_DETAIL_FRAGMENT` | GraphQL Fragment | slice-03-warenkorb | Exportiert aus `frontend/lib/graphql/fragments.ts` |
| `Header` Component | React Component | alle Seiten | Persistenter Header mit Navigation + Warenkorb-Icon (Badge in Slice 3) |
| `Footer` Component | React Component | alle Seiten | Persistenter Footer mit Legal-Links |
| `ProductVariantSelector` | React Client Component | slice-03-warenkorb | `/app/produkt/[slug]/product-variant-selector.tsx` – erweitern für onAddToCart |
| `extractVariantOptions` | Utility Function | slice-03-warenkorb | `(variations: ProductVariation[]) => VariantOptions` |
| `findVariation` | Utility Function | slice-03-warenkorb | `(variations: ProductVariation[], size: string \| null, color: string \| null) => ProductVariation \| null` |

### Integration Validation Tasks

- [ ] `ProductDetailData` Type kompatibel mit `addToCart` Mutation Input (Slice 3)
- [ ] `AddToCartButton` akzeptiert `onAddToCart` Callback (Slice 3 implementiert diesen)
- [ ] `ProductVariantSelector` in `app/produkt/[slug]/product-variant-selector.tsx` kann von Slice 3 um Cart-Logik erweitert werden
- [ ] `Header` Warenkorb-Icon hat Link zu `/warenkorb` (Slice 3 implementiert diese Page)
- [ ] `PRODUCT_CARD_FRAGMENT` enthält `id` und `slug` (für addToCart Identifikation)

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind PFLICHT-Deliverables.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `GET_PRODUCTS`, `GET_FEATURED_PRODUCTS`, `GET_PRODUCT`, `GET_PRODUCT_CATEGORIES`, `GET_ALL_PRODUCT_SLUGS`, `GET_ALL_CATEGORY_SLUGS` | Sektion 3 | YES | Exakt diese Query-Struktur (WooGraphQL v0.21.2) |
| `PRODUCT_CARD_FRAGMENT`, `PRODUCT_DETAIL_FRAGMENT`, `CATEGORY_FRAGMENT` | Sektion 4 | YES | Felder müssen mit WooGraphQL Schema übereinstimmen |
| `ProductCardData`, `ProductDetailData`, `ProductVariation`, `VariantOptions`, `SelectedVariant` | Sektion 5 | YES | TypeScript Interface – exakt diese Typen für Slice 3 Kompatibilität |
| `extractVariantOptions()` | Sektion 6 | YES | COLOR_HEX_MAP + Verfügbarkeitslogik |
| `findVariation()` | Sektion 6 | YES | Interface: `(variations, size, color) => ProductVariation \| null` |
| `generateProductJsonLd()` | Sektion 7 | YES | schema.org/Product mit EUR + InStock |
| `app/page.tsx` | Sektion 8 | YES | FeaturedProducts + CategoryOverview als separate async Server Components mit Suspense |
| `lib/apollo/server-client.ts` | Sektion 9 | YES | `registerApolloClient` für RSC |
| `app/kategorie/[slug]/page.tsx` | Sektion 10 | YES | `generateStaticParams` + `Promise.all` für parallele Queries |
| `app/produkt/[slug]/page.tsx` | Sektion 11 | YES | JSON-LD Script-Tag + SSG + `notFound()` + `import { ProductVariantSelector }` |
| `app/produkt/[slug]/product-variant-selector.tsx` | Sektion 11 | YES | Client Component mit useState für Varianten |
| `app/kategorie/[slug]/loading.tsx` | Sektion 10 | YES | Skeleton Grid aus ProductCardSkeleton (Loading State Kategorieseite) |
| `app/produkt/[slug]/loading.tsx` | Sektion 11 | YES | Skeleton fuer Bild, Textbloecke, Button (Loading State Produktdetailseite) |
| `components/layout/header.tsx` | Sektion 1 (UI) | YES | Skip-Link + sticky Header + aria-label Warenkorb |
| `components/product/product-card.tsx` | Sektion 2 (UI) | YES | Alle 4 States (Default/Hover/Loading/Sold-Out) |
| `components/product/product-card-skeleton.tsx` | Sektion 2 (UI) | YES | `animate-pulse` Skeleton |
| `components/product/variant-selector-size.tsx` | Sektion 3 (UI) | YES | `aria-pressed` + alle 3 States |
| `components/product/variant-selector-color.tsx` | Sektion 4 (UI) | YES | `aria-label` + Ring + Diagonal-Overlay |
| `components/product/product-image-gallery.tsx` | Sektion 5 (UI) | YES | Dots (Mobile) + Thumbnails (Desktop) mit `role="tablist"` + `onTouchStart`/`onTouchEnd` Swipe-Handler |
| `components/product/add-to-cart-button.tsx` | Sektion 6 (UI) | YES | Disabled-State "Bitte Größe und Farbe wählen" + onAddToCart Callback |
| `components/category/filter-chips.tsx` | Sektion 7 (UI) | YES | Horizontale Scroll-Navigation mit `aria-current` |

---

## Links

- WooGraphQL Product Queries: https://woographql.com/docs/products
- WooGraphQL Variations: https://woographql.com/docs/product-variations
- schema.org/Product: https://schema.org/Product
- Referenz-Implementierung: github.com/w3bdesign/nextjs-woocommerce
- architecture.md: `docs/features/pod-shop-mvp/architecture.md`
- wireframes.md: `docs/features/pod-shop-mvp/wireframes.md`
- discovery.md: `docs/features/pod-shop-mvp/discovery.md`
- Slice 1: `docs/features/pod-shop-mvp/slices/slice-01-infrastruktur.md`

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Frontend Pages (Next.js App Router)
- [ ] `frontend/app/page.tsx` – Homepage (Hero + Featured Products + Kategorien) mit Suspense
- [ ] `frontend/app/kategorie/[slug]/page.tsx` – Kategorieseite mit Filter-Chips + Produktgrid
- [ ] `frontend/app/kategorie/[slug]/loading.tsx` – Skeleton Loading State fuer Kategorieseite (Grid aus ProductCardSkeleton)
- [ ] `frontend/app/produkt/[slug]/page.tsx` – Produktdetailseite mit JSON-LD + SSG
- [ ] `frontend/app/produkt/[slug]/loading.tsx` – Skeleton Loading State fuer Produktdetailseite (Bild, Text, Button Skeletons)
- [ ] `frontend/app/produkt/[slug]/product-variant-selector.tsx` – Client Component für Varianten-Auswahl

### Frontend Layout Components
- [ ] `frontend/components/layout/header.tsx` – Persistenter Header (Mobile + Desktop) mit Skip-Link
- [ ] `frontend/components/layout/mobile-menu.tsx` – Client Component für Mobile-Hamburger-Menü
- [ ] `frontend/components/layout/footer.tsx` – Footer mit Legal-Links

### Frontend Product Components
- [ ] `frontend/components/product/product-card.tsx` – Produkt-Card (Default/Hover/Sold-Out States)
- [ ] `frontend/components/product/product-card-skeleton.tsx` – Skeleton Loading Card
- [ ] `frontend/components/product/product-image-gallery.tsx` – Galerie (Swipe+Dots Mobile / Thumbnails Desktop)
- [ ] `frontend/components/product/variant-selector-size.tsx` – Größen-Selector (Button-Reihe, 3 States)
- [ ] `frontend/components/product/variant-selector-color.tsx` – Farb-Selector (Color Swatches, 4 States)
- [ ] `frontend/components/product/add-to-cart-button.tsx` – AddToCart Button (Disabled/Default, Vorbereitung Slice 3)

### Frontend Category Components
- [ ] `frontend/components/category/filter-chips.tsx` – Horizontale Filter-Chips mit Scroll

### Frontend Library
- [ ] `frontend/lib/graphql/queries.ts` – Alle 6 GraphQL Queries (GET_PRODUCTS, GET_FEATURED_PRODUCTS, GET_PRODUCT, GET_PRODUCT_CATEGORIES, GET_ALL_PRODUCT_SLUGS, GET_ALL_CATEGORY_SLUGS)
- [ ] `frontend/lib/graphql/fragments.ts` – Alle 3 Fragments (PRODUCT_CARD_FRAGMENT, PRODUCT_DETAIL_FRAGMENT, CATEGORY_FRAGMENT)
- [ ] `frontend/lib/graphql/types.ts` – TypeScript Typen (ProductCardData, ProductDetailData, ProductVariation, VariantOptions, SelectedVariant, ProductImage, ProductCategory, ProductAttribute)
- [ ] `frontend/lib/apollo/server-client.ts` – Apollo Server Client für RSC (registerApolloClient)
- [ ] `frontend/lib/product/variant-utils.ts` – extractVariantOptions() + findVariation() + COLOR_HEX_MAP
- [ ] `frontend/lib/seo/json-ld.ts` – generateProductJsonLd() für Product Schema

### Root Layout Update
- [ ] `frontend/app/layout.tsx` – Update: Header + Footer einbinden, skip-link sicherstellen

### Package.json Update
- [ ] `frontend/package.json` – `@apollo/experimental-nextjs-app-support: ^0.11.0` hinzufügen

### Tests
- [ ] `tests/slices/pod-shop-mvp/slice-02-produktkatalog-frontend.test.ts` – Unit Tests für extractVariantOptions (7 Tests), findVariation (5 Tests), generateProductJsonLd (6 Tests)
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind **Pflicht**
- `AddToCartButton.onAddToCart` Callback ist in diesem Slice `undefined` – wird in Slice 3 implementiert
- `Header` Warenkorb-Badge-Counter ist in diesem Slice leer – wird in Slice 3 implementiert
- WooGraphQL antwortet mit `... on VariableProduct` und `... on SimpleProduct` – Fragments müssen diese Inline-Fragments verwenden
- `@apollo/experimental-nextjs-app-support` ist für React Server Components mit Apollo Client v4 + Next.js App Router erforderlich
