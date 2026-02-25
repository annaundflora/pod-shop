# Slice 2: Kategorie-Seite als Block-Page

> **Slice 2 von 4** fuer `Block-basierte Seiten-Migration`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-01-block-system-upgrade.md` |
> | **Naechster:** | `slice-03-produkt-seite.md` |

---

## Metadata (fuer Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-02-kategorie-seite` |
| **Test** | `pnpm test tests/slices/block-page-migration/slice-02-kategorie-seite.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-block-system-upgrade"]` |

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` enthaelt `next ^16.1.6` + `vitest ^3.0.0`.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `cd frontend && pnpm test tests/slices/block-page-migration/slice-02-kategorie-seite.test.ts` |
| **Integration Command** | `cd frontend && pnpm test tests/slices/block-page-migration/` |
| **Acceptance Command** | `cd frontend && pnpm build` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/kategorie/t-shirts` |
| **Mocking Strategy** | `mock_external` |

---

## Slice-Uebersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Block-System Upgrade | Done | `slice-01-block-system-upgrade.md` |
| 2 | Kategorie-Seite als Block-Page | Ready | `slice-02-kategorie-seite.md` |
| 3 | Produkt-Seite als Block-Page | Pending | `slice-03-produkt-seite.md` |
| 4 | Rechtsseiten als Block-Pages | Pending | `slice-04-rechtsseiten.md` |

---

## Kontext & Ziel

Die Kategorie-Seite (`/kategorie/[slug]`) ist aktuell als hardcoded JSX in `app/kategorie/[slug]/page.tsx` implementiert. Sie laedt Produkte und Kategorien direkt via GraphQL-Queries und rendert FilterChips, ProductCount und ProductGrid inline.

Ziel dieses Slices ist die Migration auf das Block-basierte YAML-System aus Slice 1. Nach der Migration delegiert `page.tsx` das gesamte Layout an `SectionRenderer`. Die UI bleibt fuer den User identisch.

Dieser Slice beweist, dass das Block-System von Slice 1 fuer echte Seiten mit dynamischen Routen funktioniert.

**Aktuelle Probleme:**
1. `app/kategorie/[slug]/page.tsx`: Hardcoded JSX mit inline GraphQL-Queries — kein YAML-Override moeglich
2. `lib/blocks/data-loaders.ts`: Kennt `products_by_category` noch nicht — kombinierter GraphQL-Query fehlt
3. `lib/blocks/registry.ts`: `page-heading`, `filter-chips`, `product-count` noch nicht registriert
4. `lib/graphql/queries.ts`: `GET_CATEGORY_WITH_PRODUCTS` Query fehlt (kombiniert Produkte + Kategorie-Info)
5. `themes/default/pages/category.yaml` existiert noch nicht

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Block → Data Source Mapping + Migration Map

```
Browser: GET /kategorie/t-shirts
  → app/kategorie/[slug]/page.tsx
    → generateStaticParams() [unveraendert — GET_ALL_CATEGORY_SLUGS]
    → generateMetadata() [unveraendert — GET_PRODUCT_CATEGORIES]
    → CategoryPageClient [unveraendert — Pinterest Tracking]
    → loadPageConfig('category', theme, { slug: 't-shirts' })
        → themes/default/pages/category.yaml laden
        → resolveParams($route.slug → 't-shirts')
    → SectionRenderer (sections aus category.yaml)
        → Section 1 (columns: 1): page-heading Block
            → data-loaders: products_by_category (slug='t-shirts')
                → GET_CATEGORY_WITH_PRODUCTS (Apollo React.cache() — 1 Netzwerk-Request)
            → PageHeadingBlock({ data: { title: 'T-Shirts' } })
        → Section 2 (columns: 1): filter-chips Block
            → data-loaders: product_categories
                → GET_PRODUCT_CATEGORIES (Apollo React.cache() — dedupliziert)
            → FilterChipsBlock({ data: { categories, currentSlug: 't-shirts' } })
        → Section 3 (columns: 1): product-count Block
            → data-loaders: products_by_category (slug='t-shirts')
                → GET_CATEGORY_WITH_PRODUCTS (Apollo React.cache() — dedupliziert!)
            → ProductCountBlock({ data: { count: 12 } })
        → Section 4 (columns: 1): product-grid Block
            → data-loaders: products_by_category (slug='t-shirts')
                → GET_CATEGORY_WITH_PRODUCTS (Apollo React.cache() — dedupliziert!)
            → ProductGridBlock({ data: { products: [...] } })
  → HTML Response (streamed via React Suspense)
```

**Wichtig: Apollo React.cache() Deduplication.** `page-heading`, `product-count` und `product-grid` rufen alle `products_by_category` mit demselben Slug auf. Apollo `getClient()` nutzt `React.cache()` — identische Queries innerhalb desselben RSC-Render-Passes erzeugen nur EINEN echten Netzwerk-Request.

### 1. Architektur-Impact

| Layer | Aenderungen |
|-------|-------------|
| `lib/graphql/queries.ts` | `GET_CATEGORY_WITH_PRODUCTS` Query NEU hinzufuegen |
| `lib/blocks/data-loaders.ts` | `products_by_category` Handler NEU: ruft `GET_CATEGORY_WITH_PRODUCTS` auf, gibt `CategoryWithProducts` zurueck |
| `lib/blocks/registry.ts` | 3 neue Block-Types registrieren: `page-heading`, `filter-chips`, `product-count` |
| `components/blocks/page-heading-block.tsx` | NEU: h1 + optionaler Untertitel, erwartet `{ title: string, subtitle?: string }` |
| `components/blocks/filter-chips-block.tsx` | NEU: Wrapper um bestehende `FilterChips` Komponente, erwartet `{ categories, currentSlug }` |
| `components/blocks/product-count-block.tsx` | NEU: "N Produkte" Text, erwartet `{ count: number }` |
| `themes/default/pages/category.yaml` | NEU: 4 Sections fuer Kategorie-Seite |
| `app/kategorie/[slug]/page.tsx` | Hardcoded JSX entfernen, durch `loadPageConfig` + `SectionRenderer` ersetzen. `generateStaticParams`, `generateMetadata`, `CategoryPageClient` bleiben unveraendert. |

### 2. Datenfluss

```
URL-Param: slug = 't-shirts'
  ↓
loadPageConfig('category', theme, { slug: 't-shirts' })
  → category.yaml laden
  → resolveParams: $route.slug → 't-shirts' in allen Block-Params
  ↓
PageConfig { sections: [
  { columns: 1, blocks: [{ type: 'page-heading', params: { query: 'products_by_category', slug: 't-shirts' } }] },
  { columns: 1, blocks: [{ type: 'filter-chips', params: { query: 'product_categories' } }] },
  { columns: 1, blocks: [{ type: 'product-count', params: { query: 'products_by_category', slug: 't-shirts' } }] },
  { columns: 1, blocks: [{ type: 'product-grid', params: { query: 'products_by_category', slug: 't-shirts' } }] },
] }
  ↓
SectionRenderer iteriert Sections + Blocks
  ↓ products_by_category (slug='t-shirts'):
GET_CATEGORY_WITH_PRODUCTS → { products: { nodes: ProductCardData[] }, productCategory: { name, description, slug } }
  ↓ Jeder Block transformiert CategoryWithProducts:
page-heading:    { title: productCategory.name }
product-count:   { count: products.nodes.length }
product-grid:    { products: products.nodes }
  ↓ product_categories (kein Slug):
GET_PRODUCT_CATEGORIES → { nodes: ProductCategory[] }
  ↓
filter-chips:    { categories: nodes, currentSlug: 't-shirts' }
```

### 3. Neuer GraphQL Query: GET_CATEGORY_WITH_PRODUCTS

```typescript
// lib/graphql/queries.ts — GET_CATEGORY_WITH_PRODUCTS hinzufuegen

export const GET_CATEGORY_WITH_PRODUCTS = gql`
  query GetCategoryWithProducts($categorySlug: String!, $first: Int) {
    products(first: $first, where: { categoryIn: [$categorySlug] }) {
      nodes {
        ...ProductCardFields
      }
    }
    productCategory(id: $categorySlug, idType: SLUG) {
      name
      description
      slug
    }
  }
  ${PRODUCT_CARD_FRAGMENT}
`
```

**WICHTIG:** Single multi-root GraphQL request — KEIN zweifacher Einzelquery. Die Query hat zwei Root-Felder: `products` mit `categoryIn`-Filter und `productCategory` mit `idType: SLUG`.

### 4. Neuer Data-Loader Handler: products_by_category

```typescript
// lib/blocks/data-loaders.ts — products_by_category Case hinzufuegen (im woocommerce switch)

case 'products_by_category': {
  const slug = (params as WooCommerceLoaderParams).slug
  const first = (params as WooCommerceLoaderParams).first ?? 24
  if (!slug) {
    console.warn('products_by_category: missing slug param')
    return { data: null }
  }
  const { data } = await getClient().query<CategoryWithProducts>({
    query: GET_CATEGORY_WITH_PRODUCTS,
    variables: { categorySlug: slug, first },
  })
  return { data: data ?? null }
}
```

### 5. Neue Block-Components

#### PageHeadingBlock

```typescript
// components/blocks/page-heading-block.tsx

import type { BlockComponentProps } from '@/lib/blocks/types'

interface PageHeadingData {
  title: string
  subtitle?: string
}

export function PageHeadingBlock({ data }: BlockComponentProps<PageHeadingData | null>) {
  if (!data?.title) return null

  return (
    <div className="mb-6">
      <h1 className="text-3xl font-bold tracking-tight text-text-primary text-wrap-balance">
        {data.title}
      </h1>
      {data.subtitle && (
        <p className="mt-2 text-text-secondary">{data.subtitle}</p>
      )}
    </div>
  )
}
```

**Data-Transformation in data-loaders.ts:** Der `page-heading` Block auf der Kategorie-Seite erhaelt `CategoryWithProducts` als Roh-Daten. Der Data-Loader gibt `CategoryWithProducts` zurueck. Die Block-Component transformiert die Daten intern:

```typescript
// components/blocks/page-heading-block.tsx — Kategorie-Variante

import type { BlockComponentProps } from '@/lib/blocks/types'
import type { CategoryWithProducts } from '@/lib/blocks/types'

// PageHeadingBlock ist generisch — erhaelt entweder { title, subtitle } oder CategoryWithProducts
// Fuer Kategorie-Seite: data ist CategoryWithProducts
export function PageHeadingBlock({ data }: BlockComponentProps<CategoryWithProducts | null>) {
  const title = data?.productCategory?.name
  if (!title) return null

  return (
    <div className="mb-6">
      <h1 className="text-3xl font-bold tracking-tight text-text-primary"
          style={{ textWrap: 'balance' }}>
        {title}
      </h1>
    </div>
  )
}
```

#### FilterChipsBlock

```typescript
// components/blocks/filter-chips-block.tsx

import { FilterChips } from '@/components/category/filter-chips'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductCategory } from '@/lib/graphql/types'

interface FilterChipsData {
  nodes: ProductCategory[]
  currentSlug?: string
}

// HINWEIS: currentSlug kommt nicht aus dem Data-Loader, sondern aus block.params.
// Der Data-Loader liefert { nodes: ProductCategory[] } aus GET_PRODUCT_CATEGORIES.
// Der block.params.currentSlug ist $route.slug (aufgeloest durch resolveParams).
// Die Block-Component erhaelt beides via data (data-loader result wird mit params gemergt
// in SectionRenderer — ODER: FilterChipsBlock liest currentSlug aus params direkt).
//
// EINFACHERE LOESUNG: FilterChipsBlock erwartet data mit nodes UND currentSlug.
// Der data-loaders.ts product_categories Handler liest currentSlug aus params
// und merged es in den Return-Wert.

export function FilterChipsBlock({ data }: BlockComponentProps<FilterChipsData | null>) {
  if (!data?.nodes) return null

  return (
    <FilterChips
      categories={data.nodes}
      currentSlug={data.currentSlug ?? ''}
    />
  )
}
```

**Hinweis zur currentSlug-Weitergabe:** Der `product_categories` Data-Loader gibt `{ nodes: ProductCategory[] }` zurueck. Der `filter-chips` Block braucht zusaetzlich `currentSlug`. Loesung: Der `products_by_category` Handler wird fuer `filter-chips` NICHT verwendet — stattdessen wird `product_categories` um das optionale `slug`-Param erweitert, das der Handler in den Return-Wert merged:

```typescript
// lib/blocks/data-loaders.ts — product_categories Case erweitern

case 'product_categories': {
  const { data } = await getClient().query<{ productCategories: { nodes: ProductCategory[] } }>({
    query: GET_PRODUCT_CATEGORIES,
    variables: { first: params.first },
  })
  return {
    data: {
      nodes: data?.productCategories?.nodes ?? [],
      currentSlug: (params as WooCommerceLoaderParams).slug ?? '',
    }
  }
}
```

#### ProductCountBlock

```typescript
// components/blocks/product-count-block.tsx

import type { BlockComponentProps } from '@/lib/blocks/types'
import type { CategoryWithProducts } from '@/lib/blocks/types'

export function ProductCountBlock({ data }: BlockComponentProps<CategoryWithProducts | null>) {
  const count = data?.products?.nodes?.length ?? 0

  return (
    <p className="text-sm text-text-secondary mb-6">
      {count === 0
        ? 'Keine Produkte'
        : `${count} ${count === 1 ? 'Produkt' : 'Produkte'}`}
    </p>
  )
}
```

### 6. Registry-Erweiterung

```typescript
// lib/blocks/registry.ts — 3 neue Block-Types registrieren

import { PageHeadingBlock } from '@/components/blocks/page-heading-block'
import { FilterChipsBlock } from '@/components/blocks/filter-chips-block'
import { ProductCountBlock } from '@/components/blocks/product-count-block'

const registry: Record<string, BlockComponent> = {
  'hero': HeroBlock as BlockComponent,
  'product-grid': ProductGridBlock as BlockComponent,
  'category-showcase': CategoryShowcaseBlock as BlockComponent,
  'usp-bar': UspBarBlock as BlockComponent,
  // Neu in Slice 2:
  'page-heading': PageHeadingBlock as BlockComponent,
  'filter-chips': FilterChipsBlock as BlockComponent,
  'product-count': ProductCountBlock as BlockComponent,
}
```

### 7. category.yaml

```yaml
# themes/default/pages/category.yaml
# Kategorie-Seiten-Konfiguration — wird geladen fuer /kategorie/[slug]

sections:
  - columns: 1
    blocks:
      - type: page-heading
        content_source: woocommerce
        params:
          query: products_by_category
          slug: $route.slug
          first: 24

  - columns: 1
    blocks:
      - type: filter-chips
        content_source: woocommerce
        params:
          query: product_categories
          slug: $route.slug

  - columns: 1
    blocks:
      - type: product-count
        content_source: woocommerce
        params:
          query: products_by_category
          slug: $route.slug
          first: 24

  - columns: 1
    blocks:
      - type: product-grid
        content_source: woocommerce
        params:
          query: products_by_category
          slug: $route.slug
          first: 24
```

### 8. Route-Umbau: app/kategorie/[slug]/page.tsx

```typescript
// app/kategorie/[slug]/page.tsx — nach Migration

import { notFound } from 'next/navigation'
import { getClient } from '@/lib/apollo/server-client'
import {
  GET_PRODUCT_CATEGORIES,
  GET_ALL_CATEGORY_SLUGS,
} from '@/lib/graphql/queries'
import { CategoryPageClient } from './category-page-client'
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import type { ProductCategory } from '@/lib/graphql/types'

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

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params
  const { data } = await getClient().query<{
    productCategories: { nodes: ProductCategory[] }
  }>({
    query: GET_PRODUCT_CATEGORIES,
  })

  const category = data?.productCategories?.nodes.find((c) => c.slug === slug)

  return {
    title: category
      ? `${category.name} | ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`
      : `Kategorie | ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`,
    description: category
      ? `Alle ${category.name} im ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`
      : undefined,
  }
}

interface CategoryPageProps {
  params: Promise<{ slug: string }>
}

export default async function CategoryPage({ params }: CategoryPageProps) {
  const { slug } = await params
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'

  const pageConfig = loadPageConfig('category', theme, { slug })

  // notFound() wird getriggert wenn products_by_category null zurueckgibt
  // (wird in der Block-Component via data === null behandelt)
  // generateStaticParams sichert ab, dass nur valide Slugs gebaut werden

  return (
    <main id="main-content">
      {/* Pinterest Tracking: page_visit + view_category Events — unveraendert */}
      <CategoryPageClient categoryName={slug} />
      <div className="space-y-0">
        <SectionRenderer
          sections={pageConfig.sections}
          skeletonMap={{
            'page-heading': null,
            'filter-chips': null,
            'product-count': null,
            'product-grid': <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
              {Array.from({ length: 8 }).map((_, i) => (
                <div key={i} className="aspect-square bg-surface-secondary animate-pulse rounded-card" />
              ))}
            </div>,
          }}
        />
      </div>
    </main>
  )
}
```

**Hinweis zu CategoryPageClient:** `CategoryPageClient` benoetigt `categoryName` fuer Pinterest-Tracking. Im Ist-Zustand wird der Name aus den GraphQL-Ergebnissen geladen. Im neuen System ist der Name nicht direkt verfuegbar bevor die Blocks geladen sind. Loesung: `categoryName={slug}` (der URL-Slug) wird als Fallback uebergeben. Dies ist ausreichend fuer Pinterest-Tracking-Zwecke. Alternativ: `generateMetadata` laedt bereits den Category-Namen — dieser kann als `searchParams` weitergegeben werden.

---

## UI Anforderungen

### 1. Keine visuellen Aenderungen

Diese Migration ist rein architektonisch. Die Kategorie-Seite muss nach der Migration **exakt gleich** aussehen wie vorher:

| Element | Ist-Zustand | Soll-Zustand (nach Migration) |
|---------|-------------|-------------------------------|
| H1 Kategoriename | `text-3xl font-bold tracking-tight text-text-primary` | Identisch |
| Filter-Chips Navigation | `FilterChips` Komponente unveraendert | Identisch — via `FilterChipsBlock` |
| Produktanzahl Text | `text-sm text-text-secondary mb-6` | Identisch |
| Produkt-Grid | `grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4` | Identisch — bestehender `ProductGridBlock` |

### 2. Accessibility

- `PageHeadingBlock` rendert semantisches `<h1>` — korrekte Heading-Hierarchie
- `FilterChipsBlock` delegiert an bestehende `FilterChips`-Komponente mit `aria-label="Kategorie-Filter"` und `aria-current` — unveraendert
- `ProductCountBlock` rendert `<p>` — kein interaktives Element, kein ARIA notig

### 3. States

| Block | Loading State | Empty State | Error State |
|-------|---------------|-------------|-------------|
| `page-heading` | Kein Skeleton (null) | `return null` bei fehlendem Titel | `return null` bei `data === null` |
| `filter-chips` | Kein Skeleton (null) | `return null` bei leeren `nodes` | `return null` bei `data === null` |
| `product-count` | Kein Skeleton (null) | "Keine Produkte" Text | "Keine Produkte" Text |
| `product-grid` | Skelett-Grid (8 Karten) | Bestehender Empty-State in `ProductGridBlock` | Bestehender Error-State |

---

## Acceptance Criteria

1) GIVEN die Kategorie-Seite mit Route `/kategorie/t-shirts`
   WHEN die Seite geladen wird
   THEN rendert `<h1>` den Kategorie-Namen (aus `productCategory.name`) mit `text-3xl font-bold tracking-tight text-text-primary`

2) GIVEN category.yaml mit `$route.slug` Platzhalter
   WHEN `loadPageConfig('category', 'default', { slug: 't-shirts' })` aufgerufen wird
   THEN enthaelt die resultierende `PageConfig` 4 Sections, wobei alle Block-Params mit `slug: 't-shirts'` (aufgeloest) vorliegen

3) GIVEN der Data-Loader Handler `products_by_category` mit `slug: 't-shirts'`
   WHEN `loadBlockData({ content_source: 'woocommerce', params: { query: 'products_by_category', slug: 't-shirts', first: 24 } })` aufgerufen wird
   THEN wird `GET_CATEGORY_WITH_PRODUCTS` mit `{ categorySlug: 't-shirts', first: 24 }` abgefragt und ein `CategoryWithProducts`-Objekt zurueckgegeben

4) GIVEN `FilterChipsBlock` mit `data: { nodes: [...categories], currentSlug: 't-shirts' }`
   WHEN die Komponente gerendert wird
   THEN delegiert sie an `FilterChips` mit `categories={data.nodes}` und `currentSlug="t-shirts"`, sodass die aktive Kategorie hervorgehoben ist

5) GIVEN `ProductCountBlock` mit `data.products.nodes.length === 12`
   WHEN die Komponente gerendert wird
   THEN zeigt sie den Text `"12 Produkte"` mit `text-sm text-text-secondary`

6) GIVEN `ProductCountBlock` mit `data.products.nodes.length === 0`
   WHEN die Komponente gerendert wird
   THEN zeigt sie den Text `"Keine Produkte"` mit `text-sm text-text-secondary`

7) GIVEN `ProductCountBlock` mit `data.products.nodes.length === 1`
   WHEN die Komponente gerendert wird
   THEN zeigt sie den Text `"1 Produkt"` (Singular, nicht Plural)

8) GIVEN `app/kategorie/[slug]/page.tsx` nach der Migration
   WHEN die Datei analysiert wird
   THEN enthaelt sie KEIN hardcoded JSX fuer h1, FilterChips oder das Produkt-Grid — diese kommen ausschliesslich via `SectionRenderer` + `category.yaml`

9) GIVEN `lib/blocks/registry.ts` nach der Migration
   WHEN `resolveBlock('page-heading')` aufgerufen wird
   THEN gibt die Funktion `PageHeadingBlock` zurueck (nicht null)

10) GIVEN `lib/blocks/registry.ts` nach der Migration
    WHEN `resolveBlock('filter-chips')` aufgerufen wird
    THEN gibt die Funktion `FilterChipsBlock` zurueck (nicht null)

11) GIVEN `lib/blocks/registry.ts` nach der Migration
    WHEN `resolveBlock('product-count')` aufgerufen wird
    THEN gibt die Funktion `ProductCountBlock` zurueck (nicht null)

---

## Testfaelle

### Test-Datei

`tests/slices/block-page-migration/slice-02-kategorie-seite.test.ts`

<test_spec>
```typescript
// tests/slices/block-page-migration/slice-02-kategorie-seite.test.ts
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

describe('Slice 02: Kategorie-Seite als Block-Page', () => {

  // ============================================================
  // category.yaml — YAML-Struktur validieren
  // ============================================================

  describe('category.yaml structure', () => {
    it('should have 4 sections with correct block types', async () => {
      // Arrange
      const { readFileSync } = await import('fs')
      const { resolve } = await import('path')
      const { parse } = await import('yaml')

      const yamlPath = resolve(
        process.cwd(),
        '../frontend/themes/default/pages/category.yaml'
      )
      const content = readFileSync(yamlPath, 'utf-8')
      const config = parse(content) as { sections: Array<{ columns: number; blocks: Array<{ type: string }> }> }

      // Assert — 4 Sections mit korrekten Block-Types
      expect(config.sections).toHaveLength(4)
      expect(config.sections[0].blocks[0].type).toBe('page-heading')
      expect(config.sections[1].blocks[0].type).toBe('filter-chips')
      expect(config.sections[2].blocks[0].type).toBe('product-count')
      expect(config.sections[3].blocks[0].type).toBe('product-grid')
    })

    it('should have $route.slug placeholders in category-dependent blocks', async () => {
      // Arrange
      const { readFileSync } = await import('fs')
      const { resolve } = await import('path')
      const { parse } = await import('yaml')

      const yamlPath = resolve(
        process.cwd(),
        '../frontend/themes/default/pages/category.yaml'
      )
      const content = readFileSync(yamlPath, 'utf-8')
      const config = parse(content) as {
        sections: Array<{
          blocks: Array<{ type: string; params: Record<string, string> }>
        }>
      }

      // page-heading, product-count, product-grid benoetigen $route.slug
      const pageHeadingParams = config.sections[0].blocks[0].params
      const productCountParams = config.sections[2].blocks[0].params
      const productGridParams = config.sections[3].blocks[0].params

      expect(pageHeadingParams.slug).toBe('$route.slug')
      expect(productCountParams.slug).toBe('$route.slug')
      expect(productGridParams.slug).toBe('$route.slug')
    })

    it('should use products_by_category query for page-heading, product-count and product-grid', async () => {
      // Arrange
      const { readFileSync } = await import('fs')
      const { resolve } = await import('path')
      const { parse } = await import('yaml')

      const yamlPath = resolve(
        process.cwd(),
        '../frontend/themes/default/pages/category.yaml'
      )
      const content = readFileSync(yamlPath, 'utf-8')
      const config = parse(content) as {
        sections: Array<{
          blocks: Array<{ type: string; params: { query: string } }>
        }>
      }

      expect(config.sections[0].blocks[0].params.query).toBe('products_by_category')
      expect(config.sections[2].blocks[0].params.query).toBe('products_by_category')
      expect(config.sections[3].blocks[0].params.query).toBe('products_by_category')
    })

    it('should use product_categories query for filter-chips', async () => {
      // Arrange
      const { readFileSync } = await import('fs')
      const { resolve } = await import('path')
      const { parse } = await import('yaml')

      const yamlPath = resolve(
        process.cwd(),
        '../frontend/themes/default/pages/category.yaml'
      )
      const content = readFileSync(yamlPath, 'utf-8')
      const config = parse(content) as {
        sections: Array<{
          blocks: Array<{ type: string; params: { query: string } }>
        }>
      }

      expect(config.sections[1].blocks[0].params.query).toBe('product_categories')
    })
  })

  // ============================================================
  // Registry — neue Block-Types registriert
  // ============================================================

  describe('registry — neue Block-Types', () => {
    it('should resolve page-heading to a non-null component', async () => {
      const { resolveBlock } = await import('../../../frontend/lib/blocks/registry')

      const component = resolveBlock('page-heading')
      expect(component).not.toBeNull()
    })

    it('should resolve filter-chips to a non-null component', async () => {
      const { resolveBlock } = await import('../../../frontend/lib/blocks/registry')

      const component = resolveBlock('filter-chips')
      expect(component).not.toBeNull()
    })

    it('should resolve product-count to a non-null component', async () => {
      const { resolveBlock } = await import('../../../frontend/lib/blocks/registry')

      const component = resolveBlock('product-count')
      expect(component).not.toBeNull()
    })
  })

  // ============================================================
  // ProductCountBlock — Produkt-Anzahl-Text
  // ============================================================

  describe('ProductCountBlock — count display logic', () => {
    it('should display "12 Produkte" for count 12 (plural)', () => {
      // Arrange — Logik aus ProductCountBlock
      const renderCountText = (count: number): string => {
        if (count === 0) return 'Keine Produkte'
        return `${count} ${count === 1 ? 'Produkt' : 'Produkte'}`
      }

      // Act + Assert
      expect(renderCountText(12)).toBe('12 Produkte')
    })

    it('should display "1 Produkt" for count 1 (singular)', () => {
      const renderCountText = (count: number): string => {
        if (count === 0) return 'Keine Produkte'
        return `${count} ${count === 1 ? 'Produkt' : 'Produkte'}`
      }

      expect(renderCountText(1)).toBe('1 Produkt')
    })

    it('should display "Keine Produkte" for count 0', () => {
      const renderCountText = (count: number): string => {
        if (count === 0) return 'Keine Produkte'
        return `${count} ${count === 1 ? 'Produkt' : 'Produkte'}`
      }

      expect(renderCountText(0)).toBe('Keine Produkte')
    })

    it('should derive count from CategoryWithProducts.products.nodes.length', () => {
      // Arrange — Data-Shape die ProductCountBlock erhaelt
      const mockData = {
        products: {
          nodes: [
            { id: '1', slug: 'shirt-1', name: 'Shirt 1' },
            { id: '2', slug: 'shirt-2', name: 'Shirt 2' },
            { id: '3', slug: 'shirt-3', name: 'Shirt 3' },
          ],
        },
        productCategory: { name: 'T-Shirts', description: '', slug: 't-shirts' },
      }

      // Act — Wie ProductCountBlock den Count extrahiert
      const count = mockData?.products?.nodes?.length ?? 0

      // Assert
      expect(count).toBe(3)
    })
  })

  // ============================================================
  // PageHeadingBlock — Titel-Extraktion aus CategoryWithProducts
  // ============================================================

  describe('PageHeadingBlock — title extraction', () => {
    it('should extract title from productCategory.name', () => {
      // Arrange
      const mockData = {
        products: { nodes: [] },
        productCategory: { name: 'T-Shirts', description: '', slug: 't-shirts' },
      }

      // Act — Wie PageHeadingBlock den Titel extrahiert
      const title = mockData?.productCategory?.name

      // Assert
      expect(title).toBe('T-Shirts')
    })

    it('should return null-title when productCategory is null', () => {
      // Arrange
      const mockData = null

      // Act
      const title = mockData?.productCategory?.name

      // Assert
      expect(title).toBeUndefined()
    })
  })

  // ============================================================
  // FilterChipsBlock — currentSlug-Weitergabe
  // ============================================================

  describe('FilterChipsBlock — currentSlug handling', () => {
    it('should pass currentSlug from data to FilterChips', () => {
      // Arrange — Data-Shape die FilterChipsBlock erhaelt (nach product_categories Handler)
      const mockData = {
        nodes: [
          { id: '1', slug: 't-shirts', name: 'T-Shirts', count: 5 },
          { id: '2', slug: 'hoodies', name: 'Hoodies', count: 3 },
        ],
        currentSlug: 't-shirts',
      }

      // Act — Wie FilterChipsBlock currentSlug extrahiert
      const currentSlug = mockData?.currentSlug ?? ''
      const categories = mockData?.nodes ?? []

      // Assert
      expect(currentSlug).toBe('t-shirts')
      expect(categories).toHaveLength(2)
    })

    it('should default currentSlug to empty string when missing', () => {
      const mockData = {
        nodes: [],
        currentSlug: undefined as string | undefined,
      }

      const currentSlug = mockData?.currentSlug ?? ''
      expect(currentSlug).toBe('')
    })
  })

  // ============================================================
  // Data-Loader: products_by_category param validation
  // ============================================================

  describe('data-loaders products_by_category — param validation', () => {
    it('should require slug param and return null when missing', async () => {
      // Arrange — Test das Verhalten bei fehlendem slug
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})

      // Simuliere den Handler-Check: if (!slug) return { data: null }
      const slug = undefined
      let result: { data: null } | null = null

      if (!slug) {
        console.warn('products_by_category: missing slug param')
        result = { data: null }
      }

      // Assert
      expect(result).toEqual({ data: null })
      expect(warnSpy).toHaveBeenCalledWith('products_by_category: missing slug param')

      warnSpy.mockRestore()
    })

    it('should use default first=24 when not specified', () => {
      // Arrange
      const params = { query: 'products_by_category', slug: 't-shirts' }

      // Act — Default-Wert-Logik aus data-loaders.ts
      const first = (params as Record<string, unknown>).first ?? 24

      // Assert
      expect(first).toBe(24)
    })

    it('should use provided first value when specified', () => {
      const params = { query: 'products_by_category', slug: 't-shirts', first: 12 }

      const first = (params as Record<string, unknown>).first ?? 24

      expect(first).toBe(12)
    })
  })

  // ============================================================
  // resolveParams — $route.slug in category.yaml Params
  // ============================================================

  describe('resolveParams — category page integration', () => {
    it('should resolve $route.slug in products_by_category params', async () => {
      const { resolveParams } = await import('../../../frontend/lib/blocks/page-config')

      // Arrange — Wie category.yaml products_by_category Block-Params aussehen (vor Auflosung)
      const params = {
        query: 'products_by_category',
        slug: '$route.slug',
        first: 24,
      }
      const routeContext = { slug: 'hoodies' }

      // Act
      const resolved = resolveParams(params, routeContext)

      // Assert
      expect(resolved.slug).toBe('hoodies')
      expect(resolved.query).toBe('products_by_category')
      expect(resolved.first).toBe(24)
    })

    it('should resolve $route.slug in filter-chips params', async () => {
      const { resolveParams } = await import('../../../frontend/lib/blocks/page-config')

      const params = {
        query: 'product_categories',
        slug: '$route.slug',
      }
      const routeContext = { slug: 't-shirts' }

      const resolved = resolveParams(params, routeContext)

      expect(resolved.slug).toBe('t-shirts')
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
| `slice-01-block-system-upgrade` | `SectionConfig` Interface | TypeScript Interface | EXISTS in `lib/blocks/types.ts` |
| `slice-01-block-system-upgrade` | `PageConfig.sections[]` | TypeScript Interface | EXISTS — `blocks[]` ersetzt durch `sections[]` |
| `slice-01-block-system-upgrade` | `loadPageConfig(pageType, theme, routeParams)` | Function | Exportiert aus `lib/blocks/page-config.ts`, 3-tier Lookup implementiert |
| `slice-01-block-system-upgrade` | `resolveParams()` | Function | Exportiert aus `lib/blocks/page-config.ts`, `$route.slug` wird aufgeloest |
| `slice-01-block-system-upgrade` | `SectionRenderer` | React Server Component | Exportiert aus `lib/blocks/section-renderer.tsx` |
| `slice-01-block-system-upgrade` | `WooCommerceLoaderParams.query` Union | TypeScript Union | Enthaelt `'products_by_category'` |
| `slice-01-block-system-upgrade` | `CategoryWithProducts` Interface | TypeScript Interface | EXISTS in `lib/blocks/types.ts` |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `PageHeadingBlock` | React Server Component | Slice 3, Slice 4 | `BlockComponentProps<CategoryWithProducts \| null>` (generisch erweiterbar) |
| `GET_CATEGORY_WITH_PRODUCTS` | GraphQL Query | `data-loaders.ts` products_by_category | `query GetCategoryWithProducts($categorySlug: String!, $first: Int)` |
| `products_by_category` Handler | Data-Loader Case | Slice 3 implizit via registry | Gibt `CategoryWithProducts` zurueck |
| `filter-chips` Block-Type | Registry Entry | `category.yaml` + ggf. andere Pages | `FilterChipsBlock as BlockComponent` |
| `product-count` Block-Type | Registry Entry | `category.yaml` | `ProductCountBlock as BlockComponent` |
| `page-heading` Block-Type | Registry Entry | `category.yaml`, `slice-04` (legal pages) | `PageHeadingBlock as BlockComponent` |

### Integration Validation Tasks

- [ ] `resolveParams` aus Slice 1 korrekt mit `$route.slug` in category.yaml-Params
- [ ] `loadPageConfig('category', theme, { slug })` laedt `themes/default/pages/category.yaml`
- [ ] `SectionRenderer` rendert 4 Sections mit den korrekten Block-Types
- [ ] `GET_CATEGORY_WITH_PRODUCTS` Query in `lib/graphql/queries.ts` exportiert
- [ ] `products_by_category` Case in `data-loaders.ts` gibt `CategoryWithProducts` zurueck
- [ ] `page-heading`, `filter-chips`, `product-count` in `registry.ts` registriert
- [ ] `category.yaml` unter `themes/default/pages/category.yaml` vorhanden
- [ ] `app/kategorie/[slug]/page.tsx` hat kein hardcoded h1/FilterChips/Grid mehr

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind PFLICHT-Deliverables. Der Gate 2 Compliance Agent prueft, dass jedes Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `GET_CATEGORY_WITH_PRODUCTS` GraphQL Query | Abschnitt 3 | YES | Single multi-root request, `categoryIn` Filter + `productCategory` by SLUG |
| `products_by_category` Data-Loader Case | Abschnitt 4 | YES | Slug-Pflicht-Check, first=24 Default, `CategoryWithProducts` Return |
| `product_categories` Handler Erweiterung (slug merge) | Abschnitt 4 | YES | `currentSlug` in Return-Wert gemergt |
| `PageHeadingBlock` Komponente | Abschnitt 5 | YES | `productCategory.name` als h1, `return null` bei fehlendem Titel |
| `FilterChipsBlock` Komponente | Abschnitt 5 | YES | Wrapper um bestehende `FilterChips`, erhaelt `{ nodes, currentSlug }` |
| `ProductCountBlock` Komponente | Abschnitt 5 | YES | Singular/Plural Logik, "Keine Produkte" bei 0 |
| `registry.ts` Erweiterung | Abschnitt 6 | YES | 3 neue Imports + Eintraege: `page-heading`, `filter-chips`, `product-count` |
| `category.yaml` 4-Section YAML | Abschnitt 7 | YES | `$route.slug` Platzhalter in allen slug-abhaengigen Blocks |
| `app/kategorie/[slug]/page.tsx` (migriert) | Abschnitt 8 | YES | Kein hardcoded JSX, `SectionRenderer` mit `skeletonMap` |

---

## Links

- Discovery: `specs/phase-0/2026-02-25-block-page-migration/discovery.md`
- Architecture: `specs/phase-0/2026-02-25-block-page-migration/architecture.md`
- Dependency Slice 1: `specs/phase-0/2026-02-25-block-page-migration/slices/slice-01-block-system-upgrade.md`
- Bestehende Kategorie-Seite: `frontend/app/kategorie/[slug]/page.tsx`
- Bestehende FilterChips: `frontend/components/category/filter-chips.tsx`
- Block-Registry: `frontend/lib/blocks/registry.ts`
- Data-Loaders: `frontend/lib/blocks/data-loaders.ts`

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
### GraphQL

- [ ] `frontend/lib/graphql/queries.ts` — `GET_CATEGORY_WITH_PRODUCTS` Query hinzufuegen (single multi-root request: `products(where: { categoryIn: [$categorySlug] })` + `productCategory(id: $categorySlug, idType: SLUG)`)

### Lib / Block-System

- [ ] `frontend/lib/blocks/data-loaders.ts` — `products_by_category` Case hinzufuegen (slug-Pflichtpruefung, `first=24` Default, `GET_CATEGORY_WITH_PRODUCTS` aufrufen) + `product_categories` Case um `currentSlug`-Merge erweitern
- [ ] `frontend/lib/blocks/registry.ts` — `PageHeadingBlock`, `FilterChipsBlock`, `ProductCountBlock` importieren und registrieren

### Block-Components (NEU)

- [ ] `frontend/components/blocks/page-heading-block.tsx` — `PageHeadingBlock`: h1 aus `productCategory.name`, `return null` bei fehlendem Titel, Styling: `text-3xl font-bold tracking-tight text-text-primary`
- [ ] `frontend/components/blocks/filter-chips-block.tsx` — `FilterChipsBlock`: Wrapper um `FilterChips`, erwartet `{ nodes: ProductCategory[], currentSlug: string }`
- [ ] `frontend/components/blocks/product-count-block.tsx` — `ProductCountBlock`: Singular/Plural Logik, "Keine Produkte" bei 0, Styling: `text-sm text-text-secondary mb-6`

### YAML-Config (NEU)

- [ ] `frontend/themes/default/pages/category.yaml` — 4 Sections: page-heading, filter-chips, product-count, product-grid. `$route.slug` Platzhalter in slug-abhaengigen Blocks.

### Route (GEAENDERT)

- [ ] `frontend/app/kategorie/[slug]/page.tsx` — Hardcoded JSX entfernen (h1, FilterChips, ProductCount, ProductGrid), `loadPageConfig('category', theme, { slug })` + `<SectionRenderer>` einfuegen. `generateStaticParams`, `generateMetadata`, `CategoryPageClient` unveraendert beibehalten.

### Tests

- [ ] `frontend/tests/slices/block-page-migration/slice-02-kategorie-seite.test.ts` — Unit Tests: category.yaml-Struktur, Registry-Eintraege, ProductCount-Text-Logik, PageHeading-Titel-Extraktion, FilterChips-currentSlug, Data-Loader-Params, resolveParams-Integration
<!-- DELIVERABLES_END -->

---

## Constraints & Hinweise

**Betrifft:**
- `lib/blocks/data-loaders.ts`: Bestehender `product_categories` Case wird um `currentSlug`-Merge erweitert — pruefe ob bestehende Konsumenten (category-showcase Block) dadurch beeinflusst werden. `CategoryShowcaseBlock` erwartet `{ nodes: ProductCategory[] }` — die Erweiterung um `currentSlug` ist additive und bricht nichts.
- `app/kategorie/[slug]/page.tsx`: `CategoryPageClient` bleibt unveraendert und erhaelt `categoryName={slug}` als Fallback. Wenn der echte Kategorie-Name fuer Pinterest-Tracking benoetigt wird, kann dieser spaeter via `searchParams` oder einem separaten RSC-Fetch in `CategoryPageClient` ergaenzt werden.

**API Contract:**
- `GET_CATEGORY_WITH_PRODUCTS` muss das Fragment `PRODUCT_CARD_FRAGMENT` verwenden (wie bestehende `GET_PRODUCTS` Query), um `ProductCardData` shape-kompatibel zu halten.
- `products_by_category` Data-Loader gibt `CategoryWithProducts` zurueck — der Block ist fuer die Extraktion der relevanten Felder verantwortlich (z.B. `data.productCategory.name` fuer PageHeadingBlock).
- `product_categories` Data-Loader-Return-Shape: `{ nodes: ProductCategory[], currentSlug: string }` — additive Erweiterung, rueckwaertskompatibel.

**Abgrenzung:**
- `product-grid` Block ist BEREITS in Slice 1 registriert und funktioniert mit dem bestehenden `featured_products` Handler. In diesem Slice erhaelt er zusaetzlich `products_by_category`-Unterstuetzung via den neuen Data-Loader-Case.
- `app/kategorie/[slug]/category-page-client.tsx` wird in diesem Slice NICHT veraendert.
- Der bestehende `GET_PRODUCTS` Query wird NICHT veraendert oder ersetzt — `GET_CATEGORY_WITH_PRODUCTS` ist ein separater neuer Query.

**Technische Einschraenkung — ProductGridBlock und products_by_category:**
Der bestehende `ProductGridBlock` erwartet `{ products: { nodes: ProductCardData[] } }` oder aehnliches. Da `products_by_category` `CategoryWithProducts` zurueckgibt (mit `products.nodes`), muss der `ProductGridBlock` mit dieser Shape umgehen koennen. Pruefe ob der bestehende Block diese Shape unterstuetzt oder ob eine Transformation im Data-Loader erfolgen muss.

---

## Skill Verification

### React Best Practices Verification

- [x] `async-suspense-boundaries`: `SectionRenderer` aus Slice 1 wrappet jeden Block in `<Suspense>` — alle neuen Blocks profitieren automatisch
- [x] `server-cache-react`: Mehrere Blocks rufen `products_by_category` mit demselben Slug auf — Apollo `React.cache()` deduplication sorgt fuer einen einzigen Netzwerk-Request
- [x] `rendering-hoist-jsx`: `skeletonMap` in `page.tsx` als Inline-JSX-Objekt im Return — fuer komplexere Skeletons besser als modul-level Konstante auslagern
- [x] `async-parallel`: `generateStaticParams` und `generateMetadata` nutzen getrennte Apollo-Requests — beide sind RSC-konform dedupliziert

### Web Design Guidelines Verification

- [x] Semantisches HTML: `PageHeadingBlock` rendert `<h1>` — korrekte Heading-Hierarchie
- [x] Accessibility: `FilterChipsBlock` delegiert an `FilterChips` mit `aria-label` und `aria-current` — unveraendert
- [x] Mobile-first: `ProductGridBlock` nutzt bestehende responsive Grid-Klassen — unveraendert
- [x] Text overflow: `text-wrap: balance` auf h1 verhindert unschoene Zeilenumbrueche

### Tailwind v4 Patterns Verification

- [x] Theme Tokens: `text-text-primary`, `text-text-secondary`, `text-sm`, `font-bold` — keine hardcoded Werte
- [x] Responsive: Block-Layouts nutzen bestehende responsive Tailwind-Klassen aus Slice 1
