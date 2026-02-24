# Slice 4: Block Registry + Page Renderer einrichten

> **Slice 4 von 5** für `Frontend-Architektur: Theming (P0.2)`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-03-component-migration.md` |
> | **Nächster:** | `slice-05-multi-shop.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-04-block-registry-page-renderer` |
| **Test** | `pnpm test tests/slices/frontend-theming/slice-04-block-registry-page-renderer.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-theme-token-system", "slice-02-shadcn-ui-primitives", "slice-03-component-migration"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` — Vitest Unit Tests (`.test.ts`), kein Browser erforderlich
- **Dependencies**: Slice 1 (Theme Tokens), Slice 2 (shadcn/ui Primitives), Slice 3 (migrierte Components) müssen fertig sein

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren.
> `frontend/package.json` enthält `next`, `vitest`, `@tailwindcss/postcss` → Stack: typescript-nextjs

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `cd frontend && pnpm test tests/slices/frontend-theming/slice-04-block-registry-page-renderer.test.ts` |
| **Integration Command** | `cd frontend && pnpm test tests/slices/frontend-theming/` |
| **Acceptance Command** | `cd frontend && pnpm build` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000` |
| **Mocking Strategy** | `mock_external` |

**Erklaerung:**
- **Stack**: Next.js 16 + React 19 + Vitest v3 + TypeScript strict
- **Test Command**: Unit Tests für Block Registry, Data Loaders, Page Config Loader, Block Components
- **Integration Command**: Alle Theming-Slice-Tests zusammen
- **Acceptance Command**: `pnpm build` — stellt sicher, dass TypeScript-Kompilierung und Build fehlerfrei durchlaufen
- **Mocking Strategy**: `mock_external` — GraphQL-Calls (WP Custom Fields, WooCommerce) werden in Tests via `vi.mock()` gemockt; `fs.readFileSync` für YAML-Dateien ebenfalls gemockt

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Theme Token System | Done | `slice-01-theme-token-system.md` |
| 2 | shadcn/ui Primitives | Done | `slice-02-shadcn-ui-primitives.md` |
| 3 | Component Migration (Big Bang) | Done | `slice-03-component-migration.md` |
| 4 | Block Registry + Page Renderer | **Ready** | `slice-04-block-registry-page-renderer.md` |
| 5 | Theme Config + Multi-Shop | Pending | `slice-05-multi-shop.md` |

---

## Kontext & Ziel

Slices 1–3 haben die Infrastruktur bereitgestellt: Theme Tokens, shadcn/ui Primitives und migrierte Components. Die Homepage (`app/page.tsx`) ist aktuell noch hardcoded — Hero-Text, Produkt-Grid und Kategorie-Übersicht sind direkt in der Datei fest verdrahtet. Editierbare Texte erfordern Code-Änderungen.

Dieser Slice führt ein flexibles Block-System ein, das die Homepage auf eine deklarative YAML-Config umstellt. Texte kommen aus WordPress Custom Fields (editierbar im WP-Admin), Produkt-Daten aus WooCommerce GraphQL, und statische Inhalte (USP-Bar) aus der YAML-Config selbst.

**Aktuelle Probleme:**
1. Hero-Text ("Einzigartige Designs auf Premium-Produkten") ist hardcoded in `app/page.tsx` — kein WP-Admin-Editing möglich
2. `app/page.tsx` hat keine YAML-gesteuerte Block-Struktur — Homepage-Layout erfordert Code-Änderungen
3. Keine `lib/blocks/` Module vorhanden (registry.ts, data-loaders.ts, page-config.ts, types.ts)
4. Keine `components/blocks/` Verzeichnis mit Block-Komponenten
5. Kein `themes/default/pages/home.yaml` für Homepage-Block-Config
6. Kein `wordpress/plugins/wp-custom-fields/` Plugin für Hero-Felder + SEO Meta Description
7. WP Custom Fields (`hero_headline`, `hero_subline`, `hero_cta_text`, `hero_cta_link`, `hero_background_image`, `seo_meta_description`) sind in WordPress nicht registriert — daher nicht via GraphQL querybar

**Zielbild nach diesem Slice:**
- `lib/blocks/types.ts` definiert alle TypeScript-Typen: `BlockConfig`, `PageConfig`, `ContentSource`
- `lib/blocks/registry.ts` mappt Block-Typen (`hero`, `product-grid`, `category-showcase`, `usp-bar`) auf React-Components
- `lib/blocks/data-loaders.ts` stellt drei Data Loaders bereit: `wordpress`, `woocommerce`, `inline`
- `lib/blocks/page-config.ts` lädt YAML-Page-Config via `fs.readFileSync` (RSC, Node.js runtime)
- `components/blocks/hero-block.tsx`, `product-grid-block.tsx`, `category-showcase-block.tsx`, `usp-bar-block.tsx` rendern die jeweiligen Inhalte
- `themes/default/pages/home.yaml` konfiguriert die Homepage-Blöcke deklarativ
- `app/page.tsx` ist ersetzt durch `<PageRenderer>` der die YAML-Config konsumiert
- `wordpress/plugins/wp-custom-fields/` PHP-Plugin registriert alle Hero-Felder und SEO-Meta-Description via `register_post_meta()` + `register_graphql_field()`
- Homepage rendert identisch wie vorher — nur die Texte kommen jetzt aus WP, Produkt-Daten aus WooCommerce, statisches aus YAML

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Block Rendering Flow, Runtime Services, Data Flow

```
Runtime (RSC):
  Page Route (app/page.tsx)
    → loadPageConfig("home", theme) → reads themes/{theme}/pages/home.yaml via fs.readFileSync
    → For each block in config:
      → BlockRegistry.resolve(type) → React Component (null + console.warn für unknown types)
      → DataLoader[content_source](params) → Data (GraphQL or inline)
    → <Suspense fallback={<Skeleton />}><Block data={data} /></Suspense>
    → ISR revalidate = 60

WordPress:
  WP Admin → Edit Page → Hero Fields (native meta box)
    → Saved via register_post_meta()
    → Exposed via WPGraphQL (show_in_graphql: true + register_graphql_field)
    → Queried by DataLoader.wordpress

Error Handling:
  Unknown block type → Skip + console.warn('Unknown block type: {type}')
  GraphQL error → Error boundary → Block not shown, rest of page renders
  Missing theme config → Fallback to themes/default/pages/{slug}.yaml
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `frontend/lib/blocks/types.ts` | NEU: BlockConfig, PageConfig, ContentSource, BlockComponentProps Typen |
| `frontend/lib/blocks/registry.ts` | NEU: Block type → Component Mapping; resolve() gibt null + console.warn für unbekannte Typen |
| `frontend/lib/blocks/data-loaders.ts` | NEU: wordpress / woocommerce / inline Data Loaders; nutzen getClient() aus lib/apollo/server-client.ts |
| `frontend/lib/blocks/page-config.ts` | NEU: loadPageConfig(slug, theme) via fs.readFileSync; Fallback auf themes/default/ |
| `frontend/components/blocks/hero-block.tsx` | NEU: Hero-Section mit WP Custom Fields oder inline Props |
| `frontend/components/blocks/product-grid-block.tsx` | NEU: Produkt-Grid mit WooCommerce-Daten; nutzt migrierten ProductCard aus Slice 3 |
| `frontend/components/blocks/category-showcase-block.tsx` | NEU: Kategorie-Übersicht mit WooCommerce-Daten |
| `frontend/components/blocks/usp-bar-block.tsx` | NEU: USP-Bar mit inline-Props aus YAML |
| `frontend/themes/default/pages/home.yaml` | NEU: Homepage-Block-Konfiguration |
| `frontend/app/page.tsx` | ERSETZT: hardcoded Homepage → PageRenderer mit home.yaml |
| `wordpress/plugins/wp-custom-fields/` | NEU: PHP-Plugin für register_post_meta() + register_graphql_field() |
| `wordpress/plugins/wp-custom-fields/wp-custom-fields.php` | NEU: Plugin-Hauptdatei |
| `wordpress/plugins/wp-custom-fields/includes/class-custom-fields.php` | NEU: Field-Registrierung |

### 2. Datenfluss

```
app/page.tsx (RSC, Node.js runtime)
  ↓ loadPageConfig("home", process.env.NEXT_PUBLIC_THEME ?? "default")
themes/default/pages/home.yaml
  ↓ yaml.parse()
PageConfig: { blocks: [{ type, content_source, params }] }
  ↓ For each block:

[content_source === "wordpress"]
  ↓ DataLoader.wordpress({ page_slug: "/" })
  ↓ getClient().query(GET_PAGE_CUSTOM_FIELDS, { slug: "/" })
  ↓ WPGraphQL → pageBy(uri: "/") → heroHeadline, heroSubline, heroCtaText, heroCtaLink, heroBackgroundImage, seoMetaDescription
  ↓ { heroHeadline, heroSubline, heroCtaText, heroCtaLink, heroBackgroundImage }
  ↓ BlockRegistry.resolve("hero") → HeroBlock
  ↓ <Suspense fallback={<HeroBlockSkeleton />}><HeroBlock data={wpData} /></Suspense>

[content_source === "woocommerce"]
  ↓ DataLoader.woocommerce({ query: "featured_products", first: 4 })
  ↓ getClient().query(GET_FEATURED_PRODUCTS, { first: 4 })
  ↓ { products: { nodes: [...] } }
  ↓ BlockRegistry.resolve("product-grid") → ProductGridBlock
  ↓ <Suspense fallback={<ProductGridSkeleton />}><ProductGridBlock data={wooData} /></Suspense>

[content_source === "inline"]
  ↓ DataLoader.inline({ props: { items: [...] } })
  ↓ Props direkt aus YAML (kein GraphQL-Aufruf)
  ↓ BlockRegistry.resolve("usp-bar") → UspBarBlock
  ↓ <UspBarBlock data={inlineProps} /> (kein Suspense nötig — synchron)
```

### 3. TypeScript-Typen (lib/blocks/types.ts)

```typescript
// frontend/lib/blocks/types.ts

export type ContentSource = 'wordpress' | 'woocommerce' | 'inline'

export interface WordPressLoaderParams {
  page_slug: string
}

export interface WooCommerceLoaderParams {
  query: 'featured_products' | 'product_categories'
  first?: number
}

export interface InlineLoaderParams {
  props: Record<string, unknown>
}

export type LoaderParams = WordPressLoaderParams | WooCommerceLoaderParams | InlineLoaderParams

export interface BlockConfig {
  type: string
  content_source: ContentSource
  params: LoaderParams
}

export interface PageConfig {
  blocks: BlockConfig[]
}

// WP Custom Fields Response (von DataLoader.wordpress)
export interface WPCustomFieldsData {
  heroHeadline?: string | null
  heroSubline?: string | null
  heroCtaText?: string | null
  heroCtaLink?: string | null
  heroBackgroundImage?: string | null
  seoMetaDescription?: string | null
}

// Props für Block-Components
export interface BlockComponentProps<T = unknown> {
  data: T
}
```

### 4. Block Registry (lib/blocks/registry.ts)

Die Registry mappt Block-Typen auf React-Components. Unbekannte Typen geben `null` zurück und loggen ein Warning.

```typescript
// frontend/lib/blocks/registry.ts
import type { ComponentType } from 'react'
import type { BlockComponentProps } from './types'
import { HeroBlock } from '@/components/blocks/hero-block'
import { ProductGridBlock } from '@/components/blocks/product-grid-block'
import { CategoryShowcaseBlock } from '@/components/blocks/category-showcase-block'
import { UspBarBlock } from '@/components/blocks/usp-bar-block'

// Typisiertes Registry-Map
type BlockComponent = ComponentType<BlockComponentProps<unknown>>

const registry: Record<string, BlockComponent> = {
  'hero': HeroBlock as BlockComponent,
  'product-grid': ProductGridBlock as BlockComponent,
  'category-showcase': CategoryShowcaseBlock as BlockComponent,
  'usp-bar': UspBarBlock as BlockComponent,
}

/**
 * Resolves a block type string to a React Component.
 * Returns null and logs a warning for unknown types.
 */
export function resolveBlock(type: string): BlockComponent | null {
  const component = registry[type]
  if (!component) {
    console.warn(`Unknown block type: ${type}`)
    return null
  }
  return component
}

export { registry }
```

### 5. Data Loaders (lib/blocks/data-loaders.ts)

```typescript
// frontend/lib/blocks/data-loaders.ts
import { getClient } from '@/lib/apollo/server-client'
import { GET_FEATURED_PRODUCTS, GET_PRODUCT_CATEGORIES } from '@/lib/graphql/queries'
import { gql } from '@apollo/client'
import type {
  WPCustomFieldsData,
  WordPressLoaderParams,
  WooCommerceLoaderParams,
  InlineLoaderParams,
  LoaderParams,
  ContentSource,
} from './types'
import type { ProductCardData, ProductCategory } from '@/lib/graphql/types'

// GraphQL Query für WP Custom Fields (architecture.md definierte Query)
const GET_PAGE_CUSTOM_FIELDS = gql`
  query GetPageCustomFields($slug: String!) {
    pageBy(uri: $slug) {
      heroHeadline
      heroSubline
      heroCtaText
      heroCtaLink
      heroBackgroundImage
      seoMetaDescription
    }
  }
`

interface WordPressLoaderResult {
  data: WPCustomFieldsData | null
  error?: string
}

interface WooCommerceLoaderResult {
  data: { products?: { nodes: ProductCardData[] } } | { productCategories?: { nodes: ProductCategory[] } } | null
  error?: string
}

interface InlineLoaderResult {
  data: Record<string, unknown>
}

/**
 * Loads WP Custom Fields for a page via GraphQL.
 * Returns null data on GraphQL error (Error Boundary handles rendering).
 */
async function wordpressLoader(params: WordPressLoaderParams): Promise<WordPressLoaderResult> {
  try {
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

/**
 * Loads WooCommerce product or category data via GraphQL.
 * Reuses existing GET_FEATURED_PRODUCTS and GET_PRODUCT_CATEGORIES queries.
 */
async function woocommerceLoader(params: WooCommerceLoaderParams): Promise<WooCommerceLoaderResult> {
  try {
    const first = params.first ?? 4
    if (params.query === 'featured_products') {
      const { data } = await getClient().query<{ products: { nodes: ProductCardData[] } }>({
        query: GET_FEATURED_PRODUCTS,
        variables: { first },
      })
      return { data }
    } else if (params.query === 'product_categories') {
      const { data } = await getClient().query<{ productCategories: { nodes: ProductCategory[] } }>({
        query: GET_PRODUCT_CATEGORIES,
        variables: { first },
      })
      return { data }
    }
    return { data: null, error: `Unknown woocommerce query: ${params.query}` }
  } catch (error) {
    console.error('DataLoader.woocommerce error:', error)
    return { data: null, error: String(error) }
  }
}

/**
 * Returns inline props directly from YAML config — no network request.
 */
function inlineLoader(params: InlineLoaderParams): InlineLoaderResult {
  return { data: params.props }
}

/**
 * Dispatches to the correct loader based on content_source.
 */
export async function loadBlockData(
  contentSource: ContentSource,
  params: LoaderParams
): Promise<{ data: unknown; error?: string }> {
  switch (contentSource) {
    case 'wordpress':
      return wordpressLoader(params as WordPressLoaderParams)
    case 'woocommerce':
      return woocommerceLoader(params as WooCommerceLoaderParams)
    case 'inline':
      return inlineLoader(params as InlineLoaderParams)
    default:
      console.warn(`Invalid content source: ${contentSource}`)
      return { data: null, error: `Invalid content source: ${contentSource}` }
  }
}

export { wordpressLoader, woocommerceLoader, inlineLoader }
```

### 6. Page Config Loader (lib/blocks/page-config.ts)

`fs.readFileSync` läuft nur in Node.js RSC-Kontext (NICHT Edge Runtime). Die Datei prüft das Runtime zur Laufzeit nicht explizit — der Implementierungs-Agent muss sicherstellen, dass `app/page.tsx` und alle Seiten, die `loadPageConfig` aufrufen, `export const runtime = 'nodejs'` NICHT durch Edge-Runtime-Konfiguration überschreiben.

```typescript
// frontend/lib/blocks/page-config.ts
import { readFileSync, existsSync } from 'fs'
import { resolve } from 'path'
import { parse } from 'yaml'
import type { PageConfig } from './types'

const FRONTEND_ROOT = resolve(process.cwd())

/**
 * Loads page block configuration from YAML.
 * Falls back to themes/default/pages/{slug}.yaml if shop theme config is missing.
 *
 * IMPORTANT: Uses fs.readFileSync — only works in Node.js RSC context, NOT Edge runtime.
 */
export function loadPageConfig(slug: string, theme: string = 'default'): PageConfig {
  const themePath = resolve(FRONTEND_ROOT, 'themes', theme, 'pages', `${slug}.yaml`)
  const defaultPath = resolve(FRONTEND_ROOT, 'themes', 'default', 'pages', `${slug}.yaml`)

  let configPath: string

  if (existsSync(themePath)) {
    configPath = themePath
  } else if (existsSync(defaultPath)) {
    if (theme !== 'default') {
      console.warn(`Page config for "${slug}" not found in theme "${theme}", using default`)
    }
    configPath = defaultPath
  } else {
    throw new Error(`Page config not found for slug "${slug}" in theme "${theme}" or default`)
  }

  const content = readFileSync(configPath, 'utf-8')
  const config = parse(content) as PageConfig

  if (!config.blocks || !Array.isArray(config.blocks) || config.blocks.length === 0) {
    throw new Error(`Page config must have at least one block (slug: ${slug})`)
  }

  return config
}
```

### 7. Homepage YAML (themes/default/pages/home.yaml)

```yaml
# themes/default/pages/home.yaml
# Homepage Block-Konfiguration
# content_source: wordpress | woocommerce | inline

blocks:
  - type: hero
    content_source: wordpress
    params:
      page_slug: "/"

  - type: product-grid
    content_source: woocommerce
    params:
      query: featured_products
      first: 4

  - type: category-showcase
    content_source: woocommerce
    params:
      query: product_categories
      first: 6

  - type: usp-bar
    content_source: inline
    params:
      props:
        items:
          - icon: "truck"
            text: "Kostenloser Versand ab 50€"
          - icon: "shield"
            text: "Sichere Zahlung"
          - icon: "refresh"
            text: "30 Tage Rückgaberecht"
          - icon: "star"
            text: "Premium Qualität"
```

### 8. HeroBlock Component (components/blocks/hero-block.tsx)

```typescript
// frontend/components/blocks/hero-block.tsx
import Link from 'next/link'
import Image from 'next/image'
import { Skeleton } from '@/components/ui/skeleton'
import type { BlockComponentProps, WPCustomFieldsData } from '@/lib/blocks/types'

const SHOP_NAME = process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'

interface HeroBlockData {
  heroHeadline?: string | null
  heroSubline?: string | null
  heroCtaText?: string | null
  heroCtaLink?: string | null
  heroBackgroundImage?: string | null
}

export function HeroBlock({ data }: BlockComponentProps<HeroBlockData | null>) {
  const headline = data?.heroHeadline ?? 'Einzigartige Designs auf Premium-Produkten'
  const subline = data?.heroSubline ?? 'Print-on-Demand – jedes Stück wird eigens für dich produziert.'
  const ctaText = data?.heroCtaText ?? 'Jetzt shoppen'
  const ctaLink = data?.heroCtaLink ?? '/kategorie/t-shirts'
  const bgImage = data?.heroBackgroundImage ?? null

  return (
    <section
      className="relative bg-surface-elevated rounded-2xl overflow-hidden mb-12 p-8 sm:p-12"
      aria-labelledby="hero-heading"
    >
      {bgImage && (
        <Image
          src={bgImage}
          alt=""
          fill
          className="object-cover opacity-20"
          sizes="100vw"
          priority
          aria-hidden="true"
        />
      )}
      <div className="relative max-w-lg">
        <p className="text-sm font-semibold text-primary uppercase tracking-widest mb-2">
          {SHOP_NAME}
        </p>
        <h1
          id="hero-heading"
          className="text-3xl sm:text-4xl font-bold tracking-tight text-text-primary mb-4"
          style={{ textWrap: 'balance' } as React.CSSProperties}
        >
          {headline}
        </h1>
        <p className="text-text-secondary mb-6">{subline}</p>
        <Link
          href={ctaLink}
          className="inline-flex items-center justify-center px-6 py-3 bg-primary text-white rounded-[var(--radius-button,0.5rem)] font-medium hover:bg-primary-hover transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          style={{ touchAction: 'manipulation' } as React.CSSProperties}
        >
          {ctaText}
        </Link>
      </div>
    </section>
  )
}

export function HeroBlockSkeleton() {
  return (
    <section className="relative bg-surface-elevated rounded-2xl overflow-hidden mb-12 p-8 sm:p-12">
      <div className="max-w-lg space-y-4">
        <Skeleton className="h-4 w-24" />
        <Skeleton className="h-10 w-3/4" />
        <Skeleton className="h-4 w-full" />
        <Skeleton className="h-12 w-36" />
      </div>
    </section>
  )
}
```

### 9. ProductGridBlock Component (components/blocks/product-grid-block.tsx)

```typescript
// frontend/components/blocks/product-grid-block.tsx
import { ProductCard } from '@/components/product/product-card'
import { ProductCardSkeleton } from '@/components/product/product-card-skeleton'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductCardData } from '@/lib/graphql/types'

interface ProductGridBlockData {
  products?: {
    nodes: ProductCardData[]
  } | null
}

export function ProductGridBlock({ data }: BlockComponentProps<ProductGridBlockData | null>) {
  const products = data?.products?.nodes ?? []

  if (products.length === 0) return null

  return (
    <section aria-labelledby="product-grid-heading">
      <h2
        id="product-grid-heading"
        className="text-2xl font-semibold tracking-tight mb-6"
      >
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

export function ProductGridBlockSkeleton({ count = 4 }: { count?: number }) {
  return (
    <section aria-labelledby="product-grid-heading-loading">
      <h2
        id="product-grid-heading-loading"
        className="text-2xl font-semibold tracking-tight mb-6"
      >
        Ausgewählte Produkte
      </h2>
      <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {Array.from({ length: count }).map((_, i) => (
          <ProductCardSkeleton key={i} />
        ))}
      </div>
    </section>
  )
}
```

### 10. CategoryShowcaseBlock Component (components/blocks/category-showcase-block.tsx)

```typescript
// frontend/components/blocks/category-showcase-block.tsx
import Image from 'next/image'
import Link from 'next/link'
import { Skeleton } from '@/components/ui/skeleton'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductCategory } from '@/lib/graphql/types'

interface CategoryShowcaseBlockData {
  productCategories?: {
    nodes: ProductCategory[]
  } | null
}

export function CategoryShowcaseBlock({ data }: BlockComponentProps<CategoryShowcaseBlockData | null>) {
  const categories = data?.productCategories?.nodes ?? []

  if (categories.length === 0) return null

  return (
    <section aria-labelledby="categories-heading">
      <h2
        id="categories-heading"
        className="text-2xl font-semibold tracking-tight mb-6"
      >
        Kategorien
      </h2>
      <div className="flex flex-col gap-3">
        {categories.map((category) => (
          <Link
            key={category.id}
            href={`/kategorie/${category.slug}`}
            className="flex items-center gap-4 p-4 rounded-xl border border-border bg-surface-elevated hover:bg-surface-elevated transition-colors focus-visible:ring-2 focus-visible:ring-primary"
            style={{ touchAction: 'manipulation' } as React.CSSProperties}
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
            <span className="font-medium text-text-primary flex-1 min-w-0 truncate">
              {category.name}
            </span>
            <span aria-hidden="true" className="text-text-secondary flex-shrink-0">
              →
            </span>
          </Link>
        ))}
      </div>
    </section>
  )
}

export function CategoryShowcaseBlockSkeleton({ count = 3 }: { count?: number }) {
  return (
    <section aria-labelledby="categories-heading-loading">
      <Skeleton className="h-8 w-32 mb-6" />
      <div className="flex flex-col gap-3">
        {Array.from({ length: count }).map((_, i) => (
          <Skeleton key={i} className="h-16 w-full rounded-xl" />
        ))}
      </div>
    </section>
  )
}
```

### 11. UspBarBlock Component (components/blocks/usp-bar-block.tsx)

```typescript
// frontend/components/blocks/usp-bar-block.tsx
import { Truck, Shield, RefreshCw, Star } from 'lucide-react'
import type { BlockComponentProps } from '@/lib/blocks/types'

// Mapping von YAML icon-Namen auf lucide-react Components
const ICON_MAP: Record<string, React.ComponentType<{ className?: string; 'aria-hidden'?: string }>> = {
  truck: Truck,
  shield: Shield,
  refresh: RefreshCw,
  star: Star,
}

interface UspItem {
  icon: string
  text: string
}

interface UspBarBlockData {
  items: UspItem[]
}

export function UspBarBlock({ data }: BlockComponentProps<UspBarBlockData>) {
  const items = data?.items ?? []

  if (items.length === 0) return null

  return (
    <section aria-label="Unsere Vorteile">
      <ul className="flex flex-wrap justify-center gap-4 sm:gap-8 py-4">
        {items.map((item, index) => {
          const IconComponent = ICON_MAP[item.icon] ?? null
          return (
            <li
              key={index}
              className="flex items-center gap-2 text-sm text-text-secondary"
            >
              {IconComponent && (
                <IconComponent
                  className="h-4 w-4 text-primary flex-shrink-0"
                  aria-hidden="true"
                />
              )}
              <span>{item.text}</span>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
```

### 12. Neue app/page.tsx mit PageRenderer

```typescript
// frontend/app/page.tsx — ERSETZT
import { Suspense } from 'react'
import { loadPageConfig } from '@/lib/blocks/page-config'
import { resolveBlock } from '@/lib/blocks/registry'
import { loadBlockData } from '@/lib/blocks/data-loaders'
import { HeroBlockSkeleton } from '@/components/blocks/hero-block'
import { ProductGridBlockSkeleton } from '@/components/blocks/product-grid-block'
import { CategoryShowcaseBlockSkeleton } from '@/components/blocks/category-showcase-block'
import type { BlockConfig } from '@/lib/blocks/types'

// ISR: Seite wird alle 60 Sekunden neu gebaut
export const revalidate = 60

// Skeleton-Mapping: Block-Typ → Skeleton-Component (für Suspense fallback)
const SKELETON_MAP: Record<string, React.ReactNode> = {
  'hero': <HeroBlockSkeleton />,
  'product-grid': <ProductGridBlockSkeleton />,
  'category-showcase': <CategoryShowcaseBlockSkeleton />,
  'usp-bar': null, // UspBar ist inline/synchron — kein Skeleton nötig
}

async function BlockRenderer({ block }: { block: BlockConfig }) {
  const BlockComponent = resolveBlock(block.type)

  // Unbekannter Block-Typ → überspringen (console.warn wurde bereits in resolveBlock() aufgerufen)
  if (!BlockComponent) return null

  const { data } = await loadBlockData(block.content_source, block.params)

  return <BlockComponent data={data} />
}

export default function HomePage() {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const pageConfig = loadPageConfig('home', theme)

  return (
    <main id="main-content">
      <div className="space-y-12">
        {pageConfig.blocks.map((block, index) => {
          const skeleton = SKELETON_MAP[block.type] ?? null

          // inline Blöcke brauchen kein Suspense (synchron)
          if (block.content_source === 'inline') {
            return (
              <BlockRenderer key={`${block.type}-${index}`} block={block} />
            )
          }

          return (
            <Suspense key={`${block.type}-${index}`} fallback={skeleton}>
              <BlockRenderer block={block} />
            </Suspense>
          )
        })}
      </div>
    </main>
  )
}
```

### 13. WP Custom Fields PHP Plugin

Das Plugin wird in `wordpress/plugins/wp-custom-fields/` erstellt. Es registriert alle Hero-Felder und SEO Meta Description via `register_post_meta()` und `register_graphql_field()`.

**Plugin-Header-Datei:**

```php
<?php
/**
 * Plugin Name: WP Custom Fields for Headless
 * Plugin URI:  https://github.com/pod-shop
 * Description: Registriert Hero-Felder und SEO Meta Description fuer WPGraphQL
 * Version:     1.0.0
 * Author:      POD Shop
 * Text Domain: wp-custom-fields
 *
 * @package WpCustomFields
 */

declare(strict_types=1);

namespace WpCustomFields;

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/class-custom-fields.php';

add_action('init', [CustomFields::class, 'register_post_meta_fields']);
add_action('graphql_register_types', [CustomFields::class, 'register_graphql_fields']);
```

**includes/class-custom-fields.php:**

```php
<?php

declare(strict_types=1);

namespace WpCustomFields;

if (! defined('ABSPATH')) {
    exit;
}

class CustomFields
{
    /**
     * Meta key → config mapping.
     * Alle Felder fuer register_post_meta().
     */
    private const FIELDS = [
        'hero_headline' => [
            'post_types'        => ['page'],
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'graphql_field'     => 'heroHeadline',
            'graphql_type'      => 'String',
            'description'       => 'Hero section headline',
        ],
        'hero_subline' => [
            'post_types'        => ['page'],
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'graphql_field'     => 'heroSubline',
            'graphql_type'      => 'String',
            'description'       => 'Hero section subline',
        ],
        'hero_cta_text' => [
            'post_types'        => ['page'],
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'graphql_field'     => 'heroCtaText',
            'graphql_type'      => 'String',
            'description'       => 'Hero CTA button text',
        ],
        'hero_cta_link' => [
            'post_types'        => ['page'],
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'graphql_field'     => 'heroCtaLink',
            'graphql_type'      => 'String',
            'description'       => 'Hero CTA button URL',
        ],
        'hero_background_image' => [
            'post_types'        => ['page'],
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'graphql_field'     => 'heroBackgroundImage',
            'graphql_type'      => 'String',
            'description'       => 'Hero background image URL',
        ],
        'seo_meta_description' => [
            'post_types'        => ['page', 'post'],
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'graphql_field'     => 'seoMetaDescription',
            'graphql_type'      => 'String',
            'description'       => 'SEO meta description',
        ],
    ];

    /**
     * Registriert alle Custom Fields via register_post_meta().
     * Hook: init
     */
    public static function register_post_meta_fields(): void
    {
        foreach (self::FIELDS as $meta_key => $config) {
            foreach ($config['post_types'] as $post_type) {
                register_post_meta($post_type, $meta_key, [
                    'type'              => $config['type'],
                    'single'            => true,
                    'show_in_rest'      => true,
                    'show_in_graphql'   => true,
                    'sanitize_callback' => $config['sanitize_callback'],
                    'auth_callback'     => fn() => current_user_can('edit_posts'),
                ]);
            }
        }
    }

    /**
     * Registriert GraphQL-Felder via register_graphql_field().
     * Hook: graphql_register_types
     */
    public static function register_graphql_fields(): void
    {
        foreach (self::FIELDS as $meta_key => $config) {
            $graphql_types = array_map(
                fn(string $pt) => $pt === 'post' ? 'Post' : 'Page',
                $config['post_types']
            );

            // Deduplizieren (seo_meta_description gilt fuer page UND post)
            $graphql_types = array_unique($graphql_types);

            foreach ($graphql_types as $graphql_type) {
                register_graphql_field($graphql_type, $config['graphql_field'], [
                    'type'        => $config['graphql_type'],
                    'description' => $config['description'],
                    'resolve'     => function ($object) use ($meta_key): ?string {
                        $value = get_post_meta($object->databaseId, $meta_key, true);
                        return $value !== '' ? $value : null;
                    },
                ]);
            }
        }
    }
}
```

---

## UI Anforderungen

> **Hinweis:** Keine neuen UI-Screens — die Homepage sieht nach der Migration identisch aus. Die Block-Components rendern exakt dieselben Inhalte wie die hardcoded `app/page.tsx`.

### 1. HeroBlock

**Verhalten:**
- Rendert dieselbe Hero-Section wie die aktuelle hardcoded `app/page.tsx` (optisch identisch)
- `heroHeadline` aus WP-Admin: Ersetzt hardcoded "Einzigartige Designs auf Premium-Produkten"
- `heroSubline` aus WP-Admin: Ersetzt hardcoded Subtext
- `heroCtaText` aus WP-Admin: Button-Text (Fallback: "Jetzt shoppen")
- `heroCtaLink` aus WP-Admin: Button-URL (Fallback: "/kategorie/t-shirts")
- `heroBackgroundImage` aus WP-Admin: Optionales Hintergrundbild (opacity-20)
- Wenn WP Custom Fields leer oder nicht gesetzt → alle Fallback-Werte greifen

**Zustände:**
- Loading: `HeroBlockSkeleton` (via Suspense fallback) — animiertes Skeleton-Layout
- Loaded: Hero mit WP-Daten oder Fallback-Werten
- Error: Block nicht angezeigt (Error Boundary) — Rest der Seite rendert weiter

**Accessibility:**
- `aria-labelledby="hero-heading"` auf Section
- CTA-Link hat `touch-action: manipulation` und `focus-visible:ring-2`
- Hintergrundbild mit `alt=""` und `aria-hidden="true"` (dekorativ)

### 2. ProductGridBlock

**Verhalten:**
- Rendert identisches Produkt-Grid wie aktuell (4 Spalten auf Desktop, 2 auf Mobile)
- Nutzt die migrierten `ProductCard` und `ProductCardSkeleton` Components aus Slice 3
- Leerer Zustand (keine Produkte) → Block wird nicht gerendert (`return null`)

**Zustände:**
- Loading: `ProductGridBlockSkeleton` (4 × ProductCardSkeleton)
- Loaded: Grid mit ProductCard-Components
- Empty: Block nicht sichtbar (kein leerer State im UI)
- Error: Block nicht angezeigt (Error Boundary)

### 3. CategoryShowcaseBlock

**Verhalten:**
- Rendert identische Kategorie-Linkliste wie aktuell
- Kategorien mit Bild zeigen 48×48px-Thumbnail
- `min-w-0 truncate` auf Kategorie-Name für lange Namen

**Zustände:**
- Loading: `CategoryShowcaseBlockSkeleton` (Skeleton für Heading + 3 Kategorie-Zeilen)
- Loaded: Kategorie-Links
- Empty: Block nicht sichtbar
- Error: Block nicht angezeigt (Error Boundary)

### 4. UspBarBlock

**Verhalten:**
- Rendert USP-Items aus YAML inline-Props (kein GraphQL, kein Suspense)
- Icon-Mapping: `truck` → Truck, `shield` → Shield, `refresh` → RefreshCw, `star` → Star (lucide-react)
- Icons sind `aria-hidden="true"` — dekorativ

**Zustände:**
- Immer sofort geladen (inline-Daten aus YAML)
- Empty (keine items): Block nicht sichtbar

---

## Acceptance Criteria

1) GIVEN `themes/default/pages/home.yaml` existiert mit 4 Blöcken (hero, product-grid, category-showcase, usp-bar)
   WHEN `app/page.tsx` aufgerufen wird
   THEN lädt `loadPageConfig('home', 'default')` die Config und rendert alle 4 Blöcke in Reihenfolge

2) GIVEN `DataLoader.wordpress` wird mit `page_slug: "/"` aufgerufen
   WHEN WPGraphQL `GetPageCustomFields` Query ausgeführt wird
   THEN gibt der Loader `{ heroHeadline, heroSubline, heroCtaText, heroCtaLink, heroBackgroundImage, seoMetaDescription }` zurück (oder `null` bei leeren WP-Feldern)

3) GIVEN WP Custom Fields nicht gesetzt (leere Felder in WP-Admin)
   WHEN `HeroBlock` gerendert wird
   THEN werden Fallback-Werte angezeigt: Headline "Einzigartige Designs auf Premium-Produkten", Subline, CTA "Jetzt shoppen"

4) GIVEN `DataLoader.woocommerce` wird mit `{ query: "featured_products", first: 4 }` aufgerufen
   WHEN WooGraphQL Query ausgeführt wird
   THEN gibt der Loader `{ products: { nodes: [...] } }` zurück

5) GIVEN `resolveBlock('unknown-type')` wird aufgerufen
   WHEN die Block Registry resolvet
   THEN gibt die Funktion `null` zurück und ruft `console.warn('Unknown block type: unknown-type')` auf

6) GIVEN `loadPageConfig('home', 'non-existent-theme')` wird aufgerufen
   WHEN keine `themes/non-existent-theme/pages/home.yaml` existiert
   THEN wird auf `themes/default/pages/home.yaml` zurückgegriffen und ein `console.warn` ausgegeben

7) GIVEN `themes/default/pages/home.yaml` enthält einen Block mit `content_source: "inline"` und `props: { items: [...] }`
   WHEN `DataLoader.inline` aufgerufen wird
   THEN gibt der Loader die `props` direkt zurück ohne GraphQL-Aufruf

8) GIVEN das WP Custom Fields Plugin ist aktiviert
   WHEN `register_post_meta()` auf Hook `init` ausgeführt wird
   THEN sind alle 6 Meta Keys (`hero_headline`, `hero_subline`, `hero_cta_text`, `hero_cta_link`, `hero_background_image`, `seo_meta_description`) in WordPress registriert

9) GIVEN das WP Custom Fields Plugin ist aktiviert
   WHEN WPGraphQL die Typen registriert (Hook `graphql_register_types`)
   THEN sind `heroHeadline`, `heroSubline`, `heroCtaText`, `heroCtaLink`, `heroBackgroundImage`, `seoMetaDescription` als GraphQL-Felder auf dem `Page`-Typ querybar; `seoMetaDescription` auch auf `Post`

10) GIVEN `app/page.tsx` nutzt den BlockRenderer
    WHEN `pnpm build` ausgeführt wird
    THEN kompiliert die Seite ohne TypeScript-Fehler und der Build schlägt nicht fehl

---

## Testfälle

### Test-Datei

`tests/slices/frontend-theming/slice-04-block-registry-page-renderer.test.ts`

<test_spec>
```typescript
// tests/slices/frontend-theming/slice-04-block-registry-page-renderer.test.ts
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { existsSync, readFileSync } from 'fs'
import { resolve } from 'path'

const FRONTEND_ROOT = resolve(__dirname, '../../../frontend')
const SPEC_ROOT = resolve(__dirname, '../../../')

// ─── Helper ─────────────────────────────────────────────────────────────────

function readFile(relativePath: string): string {
  return readFileSync(resolve(FRONTEND_ROOT, relativePath), 'utf-8')
}

function fileExists(relativePath: string): boolean {
  return existsSync(resolve(FRONTEND_ROOT, relativePath))
}

// ─── Datei-Existenz Tests ────────────────────────────────────────────────────

describe('Slice 4 — Datei-Existenz', () => {
  const requiredFiles = [
    'lib/blocks/types.ts',
    'lib/blocks/registry.ts',
    'lib/blocks/data-loaders.ts',
    'lib/blocks/page-config.ts',
    'components/blocks/hero-block.tsx',
    'components/blocks/product-grid-block.tsx',
    'components/blocks/category-showcase-block.tsx',
    'components/blocks/usp-bar-block.tsx',
    'themes/default/pages/home.yaml',
  ]

  for (const file of requiredFiles) {
    it(`should have ${file}`, () => {
      expect(fileExists(file)).toBe(true)
    })
  }

  it('should have wordpress/plugins/wp-custom-fields/wp-custom-fields.php', () => {
    expect(
      existsSync(resolve(SPEC_ROOT, '../wordpress/plugins/wp-custom-fields/wp-custom-fields.php'))
    ).toBe(true)
  })

  it('should have wordpress/plugins/wp-custom-fields/includes/class-custom-fields.php', () => {
    expect(
      existsSync(resolve(SPEC_ROOT, '../wordpress/plugins/wp-custom-fields/includes/class-custom-fields.php'))
    ).toBe(true)
  })
})

// ─── Types Tests ─────────────────────────────────────────────────────────────

describe('lib/blocks/types.ts — TypeScript-Typen vorhanden', () => {
  it('should export ContentSource type', () => {
    const content = readFile('lib/blocks/types.ts')
    expect(content).toContain('ContentSource')
    expect(content).toContain("'wordpress'")
    expect(content).toContain("'woocommerce'")
    expect(content).toContain("'inline'")
  })

  it('should export BlockConfig interface', () => {
    const content = readFile('lib/blocks/types.ts')
    expect(content).toContain('BlockConfig')
    expect(content).toContain('content_source')
    expect(content).toContain('params')
  })

  it('should export PageConfig interface with blocks array', () => {
    const content = readFile('lib/blocks/types.ts')
    expect(content).toContain('PageConfig')
    expect(content).toContain('blocks')
  })

  it('should export WPCustomFieldsData interface with all hero fields', () => {
    const content = readFile('lib/blocks/types.ts')
    expect(content).toContain('WPCustomFieldsData')
    expect(content).toContain('heroHeadline')
    expect(content).toContain('heroSubline')
    expect(content).toContain('heroCtaText')
    expect(content).toContain('heroCtaLink')
    expect(content).toContain('heroBackgroundImage')
    expect(content).toContain('seoMetaDescription')
  })
})

// ─── Block Registry Tests ────────────────────────────────────────────────────

describe('lib/blocks/registry.ts — Block Registry', () => {
  it('should export resolveBlock function', () => {
    const content = readFile('lib/blocks/registry.ts')
    expect(content).toContain('resolveBlock')
  })

  it('should register all 4 block types', () => {
    const content = readFile('lib/blocks/registry.ts')
    expect(content).toContain("'hero'")
    expect(content).toContain("'product-grid'")
    expect(content).toContain("'category-showcase'")
    expect(content).toContain("'usp-bar'")
  })

  it('resolveBlock: should return null and console.warn for unknown type', async () => {
    const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const { resolveBlock } = await import('../../../frontend/lib/blocks/registry')

    const result = resolveBlock('unknown-block-xyz')
    expect(result).toBeNull()
    expect(consoleSpy).toHaveBeenCalledWith(expect.stringContaining('Unknown block type'))
    expect(consoleSpy).toHaveBeenCalledWith(expect.stringContaining('unknown-block-xyz'))

    consoleSpy.mockRestore()
  })

  it('resolveBlock: should return a component for known block types', async () => {
    const { resolveBlock } = await import('../../../frontend/lib/blocks/registry')

    const heroComponent = resolveBlock('hero')
    expect(heroComponent).not.toBeNull()
    expect(typeof heroComponent).toBe('function')

    const productGridComponent = resolveBlock('product-grid')
    expect(productGridComponent).not.toBeNull()

    const categoryShowcaseComponent = resolveBlock('category-showcase')
    expect(categoryShowcaseComponent).not.toBeNull()

    const uspBarComponent = resolveBlock('usp-bar')
    expect(uspBarComponent).not.toBeNull()
  })
})

// ─── Data Loaders Tests ──────────────────────────────────────────────────────

describe('lib/blocks/data-loaders.ts — Data Loaders', () => {
  it('should export loadBlockData function', () => {
    const content = readFile('lib/blocks/data-loaders.ts')
    expect(content).toContain('loadBlockData')
  })

  it('should import getClient from lib/apollo/server-client', () => {
    const content = readFile('lib/blocks/data-loaders.ts')
    expect(content).toContain("from '@/lib/apollo/server-client'")
  })

  it('should reuse GET_FEATURED_PRODUCTS and GET_PRODUCT_CATEGORIES queries', () => {
    const content = readFile('lib/blocks/data-loaders.ts')
    expect(content).toContain('GET_FEATURED_PRODUCTS')
    expect(content).toContain('GET_PRODUCT_CATEGORIES')
  })

  it('inlineLoader: should return props directly without GraphQL call', async () => {
    const { inlineLoader } = await import('../../../frontend/lib/blocks/data-loaders')
    const params = { props: { items: [{ icon: 'truck', text: 'Kostenloser Versand' }] } }
    const result = inlineLoader(params)
    expect(result.data).toEqual(params.props)
  })

  it('loadBlockData with inline source: should call inlineLoader', async () => {
    const { loadBlockData } = await import('../../../frontend/lib/blocks/data-loaders')
    const params = { props: { items: [] } }
    const result = await loadBlockData('inline', params)
    expect(result.data).toEqual(params.props)
    expect(result.error).toBeUndefined()
  })

  it('loadBlockData with wordpress source: should use getClient and GraphQL', async () => {
    // Mock getClient to avoid real GraphQL calls
    vi.mock('../../../frontend/lib/apollo/server-client', () => ({
      getClient: vi.fn(() => ({
        query: vi.fn().mockResolvedValue({
          data: {
            pageBy: {
              heroHeadline: 'Test Headline',
              heroSubline: 'Test Subline',
              heroCtaText: 'Test CTA',
              heroCtaLink: '/test',
              heroBackgroundImage: null,
              seoMetaDescription: 'Test SEO',
            }
          }
        })
      }))
    }))

    const { loadBlockData } = await import('../../../frontend/lib/blocks/data-loaders')
    const result = await loadBlockData('wordpress', { page_slug: '/' })

    expect(result.data).toBeDefined()
    expect((result.data as { heroHeadline?: string })?.heroHeadline).toBe('Test Headline')

    vi.restoreAllMocks()
  })

  it('loadBlockData with woocommerce source featured_products: should use GET_FEATURED_PRODUCTS', async () => {
    vi.mock('../../../frontend/lib/apollo/server-client', () => ({
      getClient: vi.fn(() => ({
        query: vi.fn().mockResolvedValue({
          data: {
            products: { nodes: [{ id: '1', name: 'Test', slug: 'test', price: '10' }] }
          }
        })
      }))
    }))

    const { loadBlockData } = await import('../../../frontend/lib/blocks/data-loaders')
    const result = await loadBlockData('woocommerce', { query: 'featured_products', first: 4 })

    expect(result.data).toBeDefined()
    expect((result.data as { products?: { nodes: unknown[] } })?.products?.nodes).toHaveLength(1)

    vi.restoreAllMocks()
  })

  it('loadBlockData with invalid source: should return null data with error', async () => {
    const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const { loadBlockData } = await import('../../../frontend/lib/blocks/data-loaders')

    const result = await loadBlockData('invalid-source' as unknown as 'inline', { props: {} })
    expect(result.data).toBeNull()
    expect(result.error).toBeDefined()

    consoleSpy.mockRestore()
  })
})

// ─── Page Config Loader Tests ─────────────────────────────────────────────────

describe('lib/blocks/page-config.ts — Page Config Loader', () => {
  it('should export loadPageConfig function', () => {
    const content = readFile('lib/blocks/page-config.ts')
    expect(content).toContain('loadPageConfig')
  })

  it('should use fs.readFileSync (NOT fetch) for YAML loading', () => {
    const content = readFile('lib/blocks/page-config.ts')
    expect(content).toContain('readFileSync')
    expect(content).not.toContain('fetch(')
  })

  it('should import yaml parse for YAML parsing', () => {
    const content = readFile('lib/blocks/page-config.ts')
    expect(content).toContain('yaml')
    expect(content).toContain('parse')
  })

  it('loadPageConfig: should load and parse home.yaml', async () => {
    const { loadPageConfig } = await import('../../../frontend/lib/blocks/page-config')
    const config = loadPageConfig('home', 'default')

    expect(config).toBeDefined()
    expect(config.blocks).toBeDefined()
    expect(Array.isArray(config.blocks)).toBe(true)
    expect(config.blocks.length).toBeGreaterThan(0)
  })

  it('loadPageConfig: home.yaml should have hero block as first block', async () => {
    const { loadPageConfig } = await import('../../../frontend/lib/blocks/page-config')
    const config = loadPageConfig('home', 'default')

    const firstBlock = config.blocks[0]
    expect(firstBlock.type).toBe('hero')
    expect(firstBlock.content_source).toBe('wordpress')
  })

  it('loadPageConfig: home.yaml should have product-grid block', async () => {
    const { loadPageConfig } = await import('../../../frontend/lib/blocks/page-config')
    const config = loadPageConfig('home', 'default')

    const productGridBlock = config.blocks.find(b => b.type === 'product-grid')
    expect(productGridBlock).toBeDefined()
    expect(productGridBlock?.content_source).toBe('woocommerce')
  })

  it('loadPageConfig: home.yaml should have usp-bar block with inline content_source', async () => {
    const { loadPageConfig } = await import('../../../frontend/lib/blocks/page-config')
    const config = loadPageConfig('home', 'default')

    const uspBlock = config.blocks.find(b => b.type === 'usp-bar')
    expect(uspBlock).toBeDefined()
    expect(uspBlock?.content_source).toBe('inline')
  })

  it('loadPageConfig: should fall back to default theme when non-existent theme is given', async () => {
    const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const { loadPageConfig } = await import('../../../frontend/lib/blocks/page-config')

    const config = loadPageConfig('home', 'non-existent-theme-xyz')
    expect(config).toBeDefined()
    expect(config.blocks.length).toBeGreaterThan(0)
    expect(consoleSpy).toHaveBeenCalledWith(expect.stringContaining('non-existent-theme-xyz'))

    consoleSpy.mockRestore()
  })
})

// ─── home.yaml Schema Tests ───────────────────────────────────────────────────

describe('themes/default/pages/home.yaml — Schema', () => {
  it('should parse as valid YAML with blocks array', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/default/pages/home.yaml')
    const config = parse(content)

    expect(config).toBeDefined()
    expect(Array.isArray(config.blocks)).toBe(true)
    expect(config.blocks.length).toBeGreaterThanOrEqual(3)
  })

  it('all blocks should have required fields: type, content_source, params', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/default/pages/home.yaml')
    const config = parse(content)

    for (const block of config.blocks) {
      expect(block.type, `Block missing type`).toBeDefined()
      expect(block.content_source, `Block "${block.type}" missing content_source`).toBeDefined()
      expect(block.params, `Block "${block.type}" missing params`).toBeDefined()
    }
  })

  it('content_source must be one of wordpress | woocommerce | inline', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/default/pages/home.yaml')
    const config = parse(content)
    const validSources = ['wordpress', 'woocommerce', 'inline']

    for (const block of config.blocks) {
      expect(
        validSources.includes(block.content_source),
        `Invalid content_source "${block.content_source}" for block "${block.type}"`
      ).toBe(true)
    }
  })

  it('wordpress blocks must have page_slug param', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/default/pages/home.yaml')
    const config = parse(content)

    for (const block of config.blocks) {
      if (block.content_source === 'wordpress') {
        expect(
          block.params.page_slug,
          `Block "${block.type}" with content_source "wordpress" must have page_slug param`
        ).toBeDefined()
      }
    }
  })

  it('woocommerce blocks must have query param', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/default/pages/home.yaml')
    const config = parse(content)

    for (const block of config.blocks) {
      if (block.content_source === 'woocommerce') {
        expect(
          block.params.query,
          `Block "${block.type}" with content_source "woocommerce" must have query param`
        ).toBeDefined()
      }
    }
  })

  it('inline blocks must have props param', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/default/pages/home.yaml')
    const config = parse(content)

    for (const block of config.blocks) {
      if (block.content_source === 'inline') {
        expect(
          block.params.props,
          `Block "${block.type}" with content_source "inline" must have props param`
        ).toBeDefined()
      }
    }
  })
})

// ─── Block Components Tests ────────────────────────────────────────────────────

describe('Block Components — Datei-Inhalt-Assertions', () => {
  it('hero-block.tsx: should export HeroBlock and HeroBlockSkeleton', () => {
    const content = readFile('components/blocks/hero-block.tsx')
    expect(content).toContain('export function HeroBlock')
    expect(content).toContain('export function HeroBlockSkeleton')
  })

  it('hero-block.tsx: should use Skeleton from ui/skeleton', () => {
    const content = readFile('components/blocks/hero-block.tsx')
    expect(content).toContain("from '@/components/ui/skeleton'")
  })

  it('hero-block.tsx: should have fallback values for empty WP fields', () => {
    const content = readFile('components/blocks/hero-block.tsx')
    // Fallback-Werte für alle Hero-Felder
    expect(content).toContain('??')
    expect(content).toContain('heroHeadline')
    expect(content).toContain('heroSubline')
    expect(content).toContain('heroCtaText')
    expect(content).toContain('heroCtaLink')
  })

  it('hero-block.tsx: should NOT use hardcoded Tailwind color classes', () => {
    const content = readFile('components/blocks/hero-block.tsx')
    expect(content).not.toMatch(/\b(bg|text|border|ring)-(red|green|blue|black)-\d{3}\b/)
    expect(content).not.toContain('bg-black/')
  })

  it('product-grid-block.tsx: should export ProductGridBlock and ProductGridBlockSkeleton', () => {
    const content = readFile('components/blocks/product-grid-block.tsx')
    expect(content).toContain('export function ProductGridBlock')
    expect(content).toContain('export function ProductGridBlockSkeleton')
  })

  it('product-grid-block.tsx: should import ProductCard from components/product', () => {
    const content = readFile('components/blocks/product-grid-block.tsx')
    expect(content).toContain("from '@/components/product/product-card'")
  })

  it('category-showcase-block.tsx: should export CategoryShowcaseBlock and CategoryShowcaseBlockSkeleton', () => {
    const content = readFile('components/blocks/category-showcase-block.tsx')
    expect(content).toContain('export function CategoryShowcaseBlock')
    expect(content).toContain('export function CategoryShowcaseBlockSkeleton')
  })

  it('usp-bar-block.tsx: should export UspBarBlock', () => {
    const content = readFile('components/blocks/usp-bar-block.tsx')
    expect(content).toContain('export function UspBarBlock')
  })

  it('usp-bar-block.tsx: should import lucide-react icons', () => {
    const content = readFile('components/blocks/usp-bar-block.tsx')
    expect(content).toContain("from 'lucide-react'")
  })

  it('usp-bar-block.tsx: icons have aria-hidden="true"', () => {
    const content = readFile('components/blocks/usp-bar-block.tsx')
    expect(content).toContain('aria-hidden')
  })
})

// ─── app/page.tsx — PageRenderer Tests ───────────────────────────────────────

describe('app/page.tsx — PageRenderer (Block-System)', () => {
  it('should import loadPageConfig from lib/blocks/page-config', () => {
    const content = readFile('app/page.tsx')
    expect(content).toContain('loadPageConfig')
    expect(content).toContain("lib/blocks/page-config")
  })

  it('should import resolveBlock from lib/blocks/registry', () => {
    const content = readFile('app/page.tsx')
    expect(content).toContain('resolveBlock')
  })

  it('should import loadBlockData from lib/blocks/data-loaders', () => {
    const content = readFile('app/page.tsx')
    expect(content).toContain('loadBlockData')
  })

  it('should still have ISR revalidate = 60', () => {
    const content = readFile('app/page.tsx')
    expect(content).toContain('revalidate')
    expect(content).toContain('60')
  })

  it('should wrap async blocks in Suspense', () => {
    const content = readFile('app/page.tsx')
    expect(content).toContain('Suspense')
  })

  it('should NOT have hardcoded Hero-Text anymore', () => {
    const content = readFile('app/page.tsx')
    // Hardcoded Hero-Inhalte wurden in HeroBlock-Component ausgelagert
    expect(content).not.toContain('Einzigartige Designs')
    expect(content).not.toContain('Print-on-Demand')
  })
})

// ─── WP Custom Fields Plugin Tests ───────────────────────────────────────────

describe('WP Custom Fields Plugin — PHP-Dateien', () => {
  const PLUGIN_ROOT = resolve(SPEC_ROOT, '../wordpress/plugins/wp-custom-fields')

  it('wp-custom-fields.php: should have Plugin Name header', () => {
    const content = readFileSync(resolve(PLUGIN_ROOT, 'wp-custom-fields.php'), 'utf-8')
    expect(content).toContain('Plugin Name')
  })

  it('wp-custom-fields.php: should hook into init for register_post_meta', () => {
    const content = readFileSync(resolve(PLUGIN_ROOT, 'wp-custom-fields.php'), 'utf-8')
    expect(content).toContain('init')
    expect(content).toContain('register_post_meta_fields')
  })

  it('wp-custom-fields.php: should hook into graphql_register_types', () => {
    const content = readFileSync(resolve(PLUGIN_ROOT, 'wp-custom-fields.php'), 'utf-8')
    expect(content).toContain('graphql_register_types')
    expect(content).toContain('register_graphql_fields')
  })

  it('class-custom-fields.php: should register all 6 meta keys', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    expect(content).toContain('hero_headline')
    expect(content).toContain('hero_subline')
    expect(content).toContain('hero_cta_text')
    expect(content).toContain('hero_cta_link')
    expect(content).toContain('hero_background_image')
    expect(content).toContain('seo_meta_description')
  })

  it('class-custom-fields.php: should call register_post_meta()', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    expect(content).toContain('register_post_meta')
  })

  it('class-custom-fields.php: should call register_graphql_field()', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    expect(content).toContain('register_graphql_field')
  })

  it('class-custom-fields.php: should use sanitize_text_field for text fields', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    expect(content).toContain('sanitize_text_field')
  })

  it('class-custom-fields.php: should use esc_url_raw for URL fields', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    expect(content).toContain('esc_url_raw')
  })

  it('class-custom-fields.php: seo_meta_description should be registered for both page and post types', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    // seo_meta_description gilt fuer page UND post
    const seoIndex = content.indexOf('seo_meta_description')
    expect(seoIndex).toBeGreaterThan(-1)
    // Prüfe dass 'post' und 'page' in post_types für diesen Schlüssel vorkommen
    expect(content).toContain("'page'")
    expect(content).toContain("'post'")
  })

  it('class-custom-fields.php: hero fields graphql names match architecture.md spec', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    expect(content).toContain('heroHeadline')
    expect(content).toContain('heroSubline')
    expect(content).toContain('heroCtaText')
    expect(content).toContain('heroCtaLink')
    expect(content).toContain('heroBackgroundImage')
    expect(content).toContain('seoMetaDescription')
  })
})
```
</test_spec>

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-theme-token-system | `yaml` npm Paket | Dependency | `parse()` aus `yaml` in `page-config.ts` importiert |
| slice-01-theme-token-system | `--color-primary`, `--color-text-primary`, `--color-surface-elevated`, `--color-border` | CSS Custom Properties in `@theme` | Als Tailwind-Klassen verfügbar: `bg-primary`, `text-text-primary`, `bg-surface-elevated`, `border-border` |
| slice-01-theme-token-system | `--radius-button`, `--shadow-card`, `--shadow-card-hover` | CSS Custom Properties | Als inline CSS-Variable: `rounded-[var(--radius-button)]`, `shadow-[var(--shadow-card)]` |
| slice-02-shadcn-ui-primitives | `Skeleton` | Component | Importiert in `hero-block.tsx`, `category-showcase-block.tsx` |
| slice-02-shadcn-ui-primitives | `lucide-react` Abhängigkeit | npm Paket | `Truck`, `Shield`, `RefreshCw`, `Star` Icons in `usp-bar-block.tsx` |
| slice-03-component-migration | `ProductCard` | Component | Importiert in `product-grid-block.tsx` via `@/components/product/product-card` |
| slice-03-component-migration | `ProductCardSkeleton` | Component | Importiert in `product-grid-block.tsx` via `@/components/product/product-card-skeleton` |
| slice-03-component-migration | Migriertes `app/page.tsx` | RSC | Wird in diesem Slice vollständig ersetzt — muss nach Slice 3 verfügbar sein |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `loadPageConfig(slug, theme)` | Function | slice-05 (Multi-Shop YAML per Theme) | `(slug: string, theme?: string) => PageConfig` |
| `resolveBlock(type)` | Function | slice-05 (kann neue Block-Typen registrieren) | `(type: string) => BlockComponent \| null` |
| `loadBlockData(source, params)` | Function | slice-05 (Shop-spezifische Data Loader Erweiterungen) | `(source: ContentSource, params: LoaderParams) => Promise<{ data: unknown; error?: string }>` |
| `BlockConfig`, `PageConfig`, `ContentSource` | TypeScript Types | slice-05 (Multi-Shop page configs) | Aus `lib/blocks/types.ts` |
| `themes/default/pages/home.yaml` | YAML Config | slice-05 (als Default-Template für Shop-Overrides) | Schema: `{ blocks: [{ type, content_source, params }] }` |
| WP Custom Fields Plugin | WordPress Plugin | WordPress (sofort einsatzbereit) | `heroHeadline`, `heroSubline`, `heroCtaText`, `heroCtaLink`, `heroBackgroundImage`, `seoMetaDescription` via GraphQL |

### Integration Validation Tasks

- [ ] `yaml ^2.7.0` npm Paket in `frontend/package.json` unter `dependencies` vorhanden (von diesem Slice als Deliverable hinzugefuegt)
- [ ] `lucide-react ^0.575.0` npm Paket in `frontend/package.json` unter `dependencies` vorhanden (von Slice 2 oder diesem Slice als Deliverable hinzugefuegt)
- [ ] `Skeleton` Component aus Slice 2 importierbar in Block-Components
- [ ] `ProductCard` und `ProductCardSkeleton` aus Slice 3 importierbar in `product-grid-block.tsx`
- [ ] `getClient()` aus `lib/apollo/server-client.ts` (bestehendes Fundament) importierbar in `data-loaders.ts`
- [ ] `GET_FEATURED_PRODUCTS` und `GET_PRODUCT_CATEGORIES` aus `lib/graphql/queries.ts` (bestehendes Fundament) wiederverwendet
- [ ] `themes/default/pages/home.yaml` existiert und ist valides YAML mit mind. 3 Blöcken
- [ ] `app/page.tsx` ruft `loadPageConfig('home', ...)` auf — nicht mehr hardcoded
- [ ] WP Custom Fields Plugin aktivierbar in WordPress-Docker-Instanz

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind PFLICHT-Deliverables.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `lib/blocks/types.ts` | Abschnitt 3 | YES | ContentSource, BlockConfig, PageConfig, WPCustomFieldsData, BlockComponentProps |
| `lib/blocks/registry.ts` | Abschnitt 4 | YES | resolveBlock(), null + console.warn für unbekannte Typen, alle 4 Block-Typen registriert |
| `lib/blocks/data-loaders.ts` | Abschnitt 5 | YES | loadBlockData(), wordpressLoader(), woocommerceLoader(), inlineLoader(); GET_PAGE_CUSTOM_FIELDS Query; bestehende Queries wiederverwendet |
| `lib/blocks/page-config.ts` | Abschnitt 6 | YES | loadPageConfig(); fs.readFileSync (kein fetch); Fallback auf default; console.warn |
| `themes/default/pages/home.yaml` | Abschnitt 7 | YES | 4 Blöcke: hero (wordpress), product-grid (woocommerce), category-showcase (woocommerce), usp-bar (inline) |
| `components/blocks/hero-block.tsx` | Abschnitt 8 | YES | HeroBlock + HeroBlockSkeleton; Fallback-Werte für alle Felder; Skeleton aus ui/skeleton |
| `components/blocks/product-grid-block.tsx` | Abschnitt 9 | YES | ProductGridBlock + ProductGridBlockSkeleton; nutzt ProductCard aus Slice 3 |
| `components/blocks/category-showcase-block.tsx` | Abschnitt 10 | YES | CategoryShowcaseBlock + CategoryShowcaseBlockSkeleton; Skeleton aus ui/skeleton; min-w-0 truncate |
| `components/blocks/usp-bar-block.tsx` | Abschnitt 11 | YES | UspBarBlock; lucide-react Icons; aria-hidden auf Icons; ICON_MAP |
| `app/page.tsx` | Abschnitt 12 | YES | BlockRenderer async RSC; Suspense pro Block; inline ohne Suspense; revalidate=60; KEIN hardcoded Hero-Text |
| `wordpress/plugins/wp-custom-fields/wp-custom-fields.php` | Abschnitt 13 | YES | Plugin-Header; init + graphql_register_types Hooks |
| `wordpress/plugins/wp-custom-fields/includes/class-custom-fields.php` | Abschnitt 13 | YES | register_post_meta() für alle 6 Felder; register_graphql_field() mit korrekten camelCase Namen; sanitize_text_field + esc_url_raw |

---

## Constraints & Hinweise

**Betrifft:**
- `frontend/app/page.tsx` — bestehende Datei wird vollständig ersetzt (nicht nur modifiziert)
- `frontend/lib/blocks/` — neues Verzeichnis mit 4 Dateien
- `frontend/components/blocks/` — neues Verzeichnis mit 4 Block-Components
- `frontend/themes/default/pages/` — neues Unterverzeichnis in bestehendem `themes/default/`
- `wordpress/plugins/wp-custom-fields/` — neues WordPress-Plugin

**API Contract:**
- `GET_PAGE_CUSTOM_FIELDS` GraphQL-Query: nutzt `pageBy(uri: $slug)` (nicht `page(id: $id)`) — `uri` ist der Pfad, z.B. `"/"` für die Startseite
- `loadPageConfig` ist synchron via `fs.readFileSync` — darf NICHT in Edge-Runtime-Routen verwendet werden. `app/page.tsx` ist eine Node.js RSC (kein `export const runtime = 'edge'`).
- `inlineLoader` ist synchron — `loadBlockData('inline', ...)` gibt ein aufgelöstes Promise zurück, kein echter async-Aufruf nötig
- Block-Fallback: Unbekannte Block-Typen → `resolveBlock()` gibt `null` zurück → `BlockRenderer` gibt `null` zurück → Block wird nicht gerendert. Kein Crash.
- `bg-surface-elevated/80` DARF NICHT verwendet werden (Opacity-Modifier auf CSS-Variable-basierte Tokens — siehe Slice 3 Constraints). In `category-showcase-block.tsx` wird stattdessen `bg-surface-elevated` ohne Modifier verwendet.

**Abgrenzung:**
- Deep Merge Logik für Shop-spezifische `pages/home.yaml` ist OUT of Scope — wird in Slice 5 implementiert. In diesem Slice liest `loadPageConfig` nur das exakte Theme-YAML oder fällt auf Default zurück.
- Meta Box UI für WP-Admin (editierbare Felder sichtbar im Backend) ist NICHT explizit im Scope — `register_post_meta` mit `show_in_rest: true` macht die Felder aber via REST API und Gutenberg-Sidebar editierbar.
- SEO Meta Description wird in WP registriert (Plugin Deliverable), aber `app/page.tsx` nutzt sie noch nicht für `<head>` Metadata — das ist OUT of Scope für diesen Slice (wäre in einem separaten SEO-Slice).
- `app/page.tsx` Suspense für `inline` Blöcke: Inline-Blöcke benötigen kein Suspense (synchron) — der PageRenderer rendert sie direkt ohne Suspense-Wrapper.

---

## Skill Verification (UI-Implementation)

### React Best Practices Verification

**Critical Priority:**
- [x] `async-parallel`: DataLoader-Calls werden sequenziell pro Block aufgerufen. Da Blöcke als separate `<Suspense>` Boundaries gerendert werden, startet jeder Block-Fetch unabhängig (React Concurrent Mode Streaming). Kein explizites `Promise.all` nötig für die Block-Ebene.
- [x] `async-suspense-boundaries`: Jeder async Block ist in `<Suspense fallback={<Skeleton />}>` gewrappt — progressive Seiten-Hydration

**High Priority:**
- [x] `server-cache-react`: `getClient()` nutzt `React.cache()` aus `lib/apollo/server-client.ts` — Deduplizierung pro Request bereits implementiert
- [x] `server-parallel-fetching`: Blocks sind separate Suspense Boundaries — Next.js streamt sie parallel

**Medium Priority:**
- [x] `rerender-memo`: Block-Components sind Server Components (RSC) oder einfache Presentational Components — kein Client-State, kein Re-Render-Problem
- [x] `rendering-conditional-render`: `if (products.length === 0) return null` — ternary-Pattern vermieden wo sinnvoll

### Web Design Guidelines Verification

**Accessibility:**
- [x] CTA-Link in HeroBlock ist semantisches `<a>` via Next.js `<Link>` — kein `<div onClick>`
- [x] Decorative images haben `alt=""` und `aria-hidden="true"` — Hero background image
- [x] Section headings (`h1`, `h2`) mit `id` + `aria-labelledby` auf `<section>` — alle 4 Block-Components
- [x] Icon-only nicht vorhanden — alle Icons haben begleitenden Text
- [x] Kategorie-Links haben `touch-action: manipulation` — Mobile-Optimierung
- [x] USP-Bar: `<ul>` mit `<li>` für semantische Liste

**Animation & Motion:**
- [x] Skeleton-Animation: `animate-pulse` (Tailwind) — respektiert `prefers-reduced-motion` über globale CSS-Regel
- [x] Keine direkten Animations-Deklarationen in Block-Components

**Touch & Mobile:**
- [x] `touch-action: manipulation` auf CTA-Link (HeroBlock) und Kategorie-Links
- [x] Touch targets: CTA-Link mit `px-6 py-3` hat ausreichende Touch-Fläche (> 44px)

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [x] Keine hardcoded Farben — alle Farben via Token-Klassen (`bg-primary`, `bg-surface-elevated`, `text-text-primary`, `border-border`, `text-text-secondary`)
- [x] CSS Custom Property Pattern für Radius: `rounded-[var(--radius-button,0.5rem)]`
- [x] `bg-surface-elevated/80` NICHT verwendet (Opacity-Modifier auf var()-Token nicht erlaubt) — stattdessen `bg-surface-elevated`

**Build Tool Integration:**
- [x] Tailwind v4 CSS-first — kein `tailwind.config.ts`, alle Tokens aus `@theme` Block (Slice 1)

---

## Links

- Design/Spec: `specs/phase-0/2026-02-21-frontend-theming/architecture.md`
- Discovery: `specs/phase-0/2026-02-21-frontend-theming/discovery.md`
- Dependency Slice 1: `specs/phase-0/2026-02-21-frontend-theming/slices/slice-01-theme-token-system.md`
- Dependency Slice 2: `specs/phase-0/2026-02-21-frontend-theming/slices/slice-02-shadcn-ui-primitives.md`
- Dependency Slice 3: `specs/phase-0/2026-02-21-frontend-theming/slices/slice-03-component-migration.md`
- WPGraphQL `register_graphql_field`: https://www.wpgraphql.com/functions/register_graphql_field
- Next.js RSC + fs.readFileSync: https://nextjs.org/docs/app/building-your-application/rendering/server-components

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
### Frontend — Neue Dateien
- [ ] `frontend/lib/blocks/types.ts` — TypeScript-Typen: ContentSource, BlockConfig, PageConfig, WPCustomFieldsData, WordPressLoaderParams, WooCommerceLoaderParams, InlineLoaderParams, LoaderParams, BlockComponentProps
- [ ] `frontend/lib/blocks/registry.ts` — Block Registry: resolveBlock(type) → Component | null; alle 4 Typen registriert (hero, product-grid, category-showcase, usp-bar); console.warn für unbekannte Typen
- [ ] `frontend/lib/blocks/data-loaders.ts` — Data Loaders: loadBlockData(), wordpressLoader() mit GET_PAGE_CUSTOM_FIELDS Query, woocommerceLoader() mit bestehenden GET_FEATURED_PRODUCTS + GET_PRODUCT_CATEGORIES Queries, inlineLoader() synchron
- [ ] `frontend/lib/blocks/page-config.ts` — Page Config Loader: loadPageConfig(slug, theme) via fs.readFileSync; yaml.parse(); Fallback auf default theme; console.warn; Node.js runtime only
- [ ] `frontend/components/blocks/hero-block.tsx` — HeroBlock Component + HeroBlockSkeleton; Fallback-Werte für alle Hero-Felder; Skeleton aus ui/skeleton; keine hardcoded Farben; aria-labelledby
- [ ] `frontend/components/blocks/product-grid-block.tsx` — ProductGridBlock Component + ProductGridBlockSkeleton; nutzt ProductCard + ProductCardSkeleton aus Slice 3; empty state = null
- [ ] `frontend/components/blocks/category-showcase-block.tsx` — CategoryShowcaseBlock Component + CategoryShowcaseBlockSkeleton; Skeleton aus ui/skeleton; min-w-0 truncate; touch-action
- [ ] `frontend/components/blocks/usp-bar-block.tsx` — UspBarBlock Component; ICON_MAP mit lucide-react; aria-hidden auf Icons; items aus inline props; empty state = null
- [ ] `frontend/themes/default/pages/home.yaml` — Homepage-Block-Config: 4 Blöcke (hero/wordpress, product-grid/woocommerce, category-showcase/woocommerce, usp-bar/inline mit USP-Items)

### Frontend — Modifizierte Dateien
- [ ] `frontend/app/page.tsx` — ERSETZT: hardcoded Homepage durch PageRenderer; loadPageConfig + resolveBlock + loadBlockData; Suspense pro async Block; revalidate=60; KEIN hardcoded Hero-Text mehr
- [ ] `frontend/package.json` — MODIFIZIERT: `"yaml": "^2.7.0"` zu `dependencies` hinzufuegen (benoetigt von `lib/blocks/page-config.ts`); `"lucide-react": "^0.575.0"` zu `dependencies` hinzufuegen falls nicht durch Slice 2 geliefert (benoetigt von `components/blocks/usp-bar-block.tsx`)

### WordPress — Neue Dateien
- [ ] `wordpress/plugins/wp-custom-fields/wp-custom-fields.php` — Plugin-Header; require includes/class-custom-fields.php; add_action('init', ...) + add_action('graphql_register_types', ...)
- [ ] `wordpress/plugins/wp-custom-fields/includes/class-custom-fields.php` — CustomFields Klasse; register_post_meta_fields() für alle 6 Meta Keys mit sanitize_callback; register_graphql_fields() mit camelCase GraphQL-Feld-Namen; seo_meta_description für page + post

### Tests
- [ ] `frontend/tests/slices/frontend-theming/slice-04-block-registry-page-renderer.test.ts` — Datei-Existenz-Tests, Types-Assertions, Registry-Tests (resolveBlock bekannte + unbekannte Typen), Data-Loader-Tests (inline synchron, mocked wordpress/woocommerce), Page-Config-Tests (YAML laden, Fallback), home.yaml-Schema-Tests, Block-Component-Assertions, page.tsx-Assertions, WP-Plugin-PHP-Assertions
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind Pflicht
- `app/page.tsx` wird vollständig neu geschrieben — der Inhalt nach Slice 3 wird komplett ersetzt
- `fs.readFileSync` in `page-config.ts` setzt voraus, dass `process.cwd()` das `frontend/` Verzeichnis ist (Next.js Standard)
- Das WP Custom Fields Plugin muss nach Implementierung in WordPress aktiviert werden (via WP-CLI oder Admin-Interface)
- `GET_PAGE_CUSTOM_FIELDS` ist eine neue GraphQL-Query — sie muss in `lib/blocks/data-loaders.ts` lokal definiert werden (nicht in `lib/graphql/queries.ts`), da sie spezifisch für den Data Loader ist
- `bg-surface-elevated/80` NICHT verwenden (Slice 3 Constraint: Opacity-Modifier auf CSS-Variable-Tokens nicht erlaubt)
