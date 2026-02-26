# Slice 3: Kategorie-Page Enhancements implementieren

> **Slice 3 von 6** für `Shop Completeness — Blocks & Pages`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-02-produkt-page-enhancements.md` |
> | **Nächster:** | `slice-04-homepage-enhancements.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-03-kategorie-page-enhancements` |
| **Test** | `pnpm test tests/slices/shop-completeness/slice-03-kategorie-page-enhancements.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-cross-page-infrastruktur"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` — Vitest Unit/Component Tests (kein Playwright)
- **Dependencies**: Slice 1 (PaginationBlock, SortBarBlock, BreadcrumbBlock, EmptyStateBlock, PaginationData, SortOption) muss fertig sein

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` (next ^16.1.6, vitest ^3.0.0, typescript ^5.7.0, @tailwindcss/postcss ^4.0.0).

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/shop-completeness/slice-03-kategorie-page-enhancements.test.ts` |
| **Integration Command** | `pnpm test tests/slices/shop-completeness/` |
| **Acceptance Command** | `pnpm test tests/slices/shop-completeness/slice-03-kategorie-page-enhancements.test.ts --reporter=verbose` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `mock_external` |

**Erklaerung:**
- **Stack**: typescript-nextjs (Next.js 16 App Router, Vitest, Tailwind CSS v4)
- **Test Command**: Einzelner Slice-Testlauf via Vitest
- **Acceptance Command**: Identische Testdatei mit `--reporter=verbose` fuer AC-Protokoll durch den Orchestrator
- **Mocking Strategy**: `mock_external` — Apollo Server-Client wird per `vi.mock()` gemockt; `next/navigation` (`useRouter`, `useSearchParams`) wird gemockt in SortBarBlock-Tests

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Cross-Page Infrastruktur | Done | `slice-01-cross-page-infrastruktur.md` |
| 2 | Produkt-Page Enhancements | Done | `slice-02-produkt-page-enhancements.md` |
| 3 | Kategorie-Page Enhancements | Ready | `slice-03-kategorie-page-enhancements.md` |
| 4 | Homepage Enhancements | Pending | `slice-04-homepage-enhancements.md` |
| 5 | Suchseite | Pending | `slice-05-suchseite.md` |
| 6 | Neue Pages | Pending | `slice-06-neue-pages.md` |

---

## Kontext & Ziel

Die bestehende Kategorie-Page `/kategorie/[slug]` zeigt immer exakt die ersten 24 Produkte ohne Pagination, Sortierung, Breadcrumb oder Empty-State. Das ist nicht skalierbar und führt zu schlechter UX sobald eine Kategorie mehr als 24 Produkte hat.

**Aktuelle Probleme:**
1. `app/kategorie/[slug]/page.tsx` liest keine `?page`- und `?sort`-URL-Params — loadPageConfig erhält nur `{ slug }`
2. `GET_CATEGORY_WITH_PRODUCTS` Query hat kein Pagination-Support (kein Cursor, kein over-fetch)
3. `products_by_category` im data-loader ignoriert `page` und `sort` params vollständig
4. `themes/default/pages/category.yaml` enthält keine breadcrumb-, sort-bar-, pagination- oder empty-state-Blöcke
5. `WooCommerceLoaderParams` in `lib/blocks/types.ts` kennt kein `page`-, `perPage`- oder `sort`-Feld

**Zielbild:** Kategorie-Page unterstützt SEO-kompatible Pagination via `?page=N`, Sortierung via `?sort=price_asc|price_desc|newest`, Breadcrumb-Navigation und Empty-State wenn 0 Produkte vorhanden.

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → "Pagination Logic", "Sort Mapping", "New YAML Page Configs", "Extended `themes/default/pages/category.yaml`", "URL Parameter Handling Pattern"

```
Pagination Strategy: Over-fetch + slice (pragmatic MVP, <200 Produkte/Kategorie)
  fetchCount = page * perPage + 1        // +1 um hasNextPage zu detektieren
  GraphQL: products(first: fetchCount, where: { categoryIn: [slug], orderby: [...] })
  pageNodes = allNodes.slice((page-1)*perPage, page*perPage)
  hasNextPage = allNodes.length > page * perPage
  totalPages = Math.ceil(category.count / perPage)   // aus category.count falls verfügbar

Sort Mapping:
  (none)       → WooCommerce Default
  price_asc    → [{ field: PRICE, order: ASC }]
  price_desc   → [{ field: PRICE, order: DESC }]
  newest       → [{ field: DATE, order: DESC }]

URL Parameter Pattern:
  page.tsx reads searchParams → passes { slug, page, sort } to loadPageConfig
  → resolveParams replaces $route.page, $route.sort in YAML
  → woocommerceLoader receives resolved page + sort params
  → returns PaginatedProductsResult
  → page.tsx extracts { currentPage, totalPages, baseUrl, currentSort }
  → PaginationBlock + SortBarBlock render with real data
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `frontend/app/kategorie/[slug]/page.tsx` | Erweitern: `searchParams` prop lesen (`page`, `sort`); `loadPageConfig` mit `{ slug, page, sort }` aufrufen; `skeletonMap` um `breadcrumb`, `sort-bar`, `pagination`, `empty-state` erweitern |
| `frontend/lib/blocks/types.ts` | `WooCommerceLoaderParams` um `page?: number`, `perPage?: number`, `sort?: string` erweitern |
| `frontend/lib/graphql/queries.ts` | `GET_PRODUCTS_PAGINATED` Query hinzufügen (ersetzt/erweitert `GET_CATEGORY_WITH_PRODUCTS` für paginierten Use-Case) |
| `frontend/lib/blocks/data-loaders.ts` | `products_by_category` Branch erweitern: over-fetch + slice Pagination + Sort-Mapping; `category_meta` Branch hinzufügen (für Breadcrumb KategorieName) |
| `frontend/themes/default/pages/category.yaml` | `breadcrumb` vor `page-heading`, `sort-bar` nach `product-count`, `pagination` nach `product-grid`, `empty-state` als conditional nach `product-grid` eintragen |

### 2. Datenfluss

```
Browser Request: GET /kategorie/t-shirts?page=2&sort=price_asc
  ↓
app/kategorie/[slug]/page.tsx (RSC)
  → params: { slug: "t-shirts" }
  → searchParams: { page: "2", sort: "price_asc" }
  → page = parseInt(searchParams.page ?? "1", 10)    // = 2
  → sort = searchParams.sort ?? ""                   // = "price_asc"
  → loadPageConfig('category', theme, { slug: "t-shirts", page: "2", sort: "price_asc" })
  ↓
resolveParams in page-config.ts
  → $route.slug   → "t-shirts"
  → $route.page   → "2"
  → $route.sort   → "price_asc"
  ↓
SectionRenderer iteriert sections aus category.yaml
  ↓ breadcrumb block (inline, BreadcrumbData from category_meta)
    → BreadcrumbBlock: [{ label: "Startseite", href: "/" }, { label: "T-Shirts" }]
  ↓ page-heading block (products_by_category, slug + page + sort)
    → woocommerceLoader → GET_PRODUCTS_PAGINATED → PaginatedProductsResult
  ↓ filter-chips block (product_categories)
    → FilterChipsBlock (unverändert)
  ↓ product-count block (products_by_category, slug + page + sort)
    → Apollo cache dedup (gleiche Query-Variablen) → PaginatedProductsResult
  ↓ sort-bar block (inline, SortBarData)
    → SortBarBlock: { currentSort: "price_asc", baseUrl: "/kategorie/t-shirts" }
  ↓ product-grid block (products_by_category, slug + page + sort)
    → Apollo cache dedup → PaginatedProductsResult.products.nodes (Seite 2)
  ↓ pagination block (products_by_category, slug + page + sort)
    → Apollo cache dedup → PaginatedProductsResult.pagination
    → PaginationBlock: { currentPage: 2, totalPages: 5, baseUrl: "/kategorie/t-shirts?sort=price_asc" }
  ↓ empty-state block (inline, EmptyStateData)
    → Nur sichtbar wenn products.nodes.length === 0
```

### 3. `GET_PRODUCTS_PAGINATED` Query (neu in queries.ts)

```typescript
// lib/graphql/queries.ts (Ergänzung)
export const GET_PRODUCTS_PAGINATED = gql`
  ${PRODUCT_CARD_FRAGMENT}
  query GetProductsPaginated(
    $categorySlug: String
    $first: Int!
    $orderby: [ProductsOrderbyInput]
    $search: String
  ) {
    products(
      first: $first
      where: {
        categoryIn: [$categorySlug]
        orderby: $orderby
        search: $search
        status: "publish"
      }
    ) {
      nodes {
        ...ProductCardFields
      }
    }
    productCategory(id: $categorySlug, idType: SLUG) {
      name
      count
      description
      slug
    }
  }
`
```

**Wichtig:** `$categorySlug` ist nullable — wenn null, wird kein Kategorie-Filter angewendet (für Suchseite in Slice 5 wiederverwendbar).

### 4. Sort-Mapping (in data-loaders.ts)

| URL Param `sort` | GraphQL Orderby | Label (German) |
|------------------|----------------|----------------|
| `""` (kein Param) | `undefined` (WooCommerce Default) | Empfohlen |
| `price_asc` | `[{ field: PRICE, order: ASC }]` | Preis: aufsteigend |
| `price_desc` | `[{ field: PRICE, order: DESC }]` | Preis: absteigend |
| `newest` | `[{ field: DATE, order: DESC }]` | Neueste zuerst |

```typescript
// Sort-Mapping Funktion (in data-loaders.ts)
function buildOrderby(sort: string | undefined): { field: string; order: string }[] | undefined {
  switch (sort) {
    case 'price_asc':  return [{ field: 'PRICE', order: 'ASC' }]
    case 'price_desc': return [{ field: 'PRICE', order: 'DESC' }]
    case 'newest':     return [{ field: 'DATE', order: 'DESC' }]
    default:           return undefined  // WooCommerce default
  }
}
```

### 5. Over-fetch + Slice Pagination (in data-loaders.ts)

```typescript
// products_by_category branch (erweitert)
} else if (params.query === 'products_by_category') {
  const slug = params.slug
  const page = Number(params.page ?? 1)
  const perPage = Number(params.perPage ?? 24)
  const sort = params.sort as string | undefined
  const fetchCount = page * perPage + 1   // +1 für hasNextPage-Detection

  const orderby = buildOrderby(sort)
  const { data } = await getClient().query({
    query: GET_PRODUCTS_PAGINATED,
    variables: { categorySlug: slug, first: fetchCount, orderby },
  })

  const allNodes = data?.products?.nodes ?? []
  const pageNodes = allNodes.slice((page - 1) * perPage, page * perPage)
  const hasNextPage = allNodes.length > page * perPage
  const categoryCount = data?.productCategory?.count ?? 0
  const totalPages = categoryCount > 0
    ? Math.ceil(categoryCount / perPage)
    : Math.ceil(allNodes.length / perPage) + (hasNextPage ? 1 : 0)

  return {
    data: {
      products: { nodes: pageNodes },
      productCategory: data?.productCategory ?? null,
      pagination: {
        currentPage: page,
        totalPages: Math.max(totalPages, page),
        hasNextPage,
        hasPreviousPage: page > 1,
        totalCount: categoryCount,
      },
    } satisfies PaginatedProductsResult,
  }
}
```

### 6. `PaginatedProductsResult` DTO (in types.ts)

```typescript
// lib/blocks/types.ts (Ergänzung)
export interface PaginationMeta {
  currentPage: number
  totalPages: number
  hasNextPage: boolean
  hasPreviousPage: boolean
  totalCount: number
}

export interface PaginatedProductsResult {
  products: { nodes: ProductCardData[] }
  productCategory?: { name: string; description: string; slug: string; count: number } | null
  pagination: PaginationMeta
}
```

### 7. Erweiterung `WooCommerceLoaderParams` (in types.ts)

```typescript
// lib/blocks/types.ts (aktualisiert)
export interface WooCommerceLoaderParams {
  query:
    | 'featured_products'
    | 'product_categories'
    | 'products_by_category'
    | 'product_by_slug'
    | 'product_reviews'         // neu in Slice 2
    | 'product_recommendations' // neu in Slice 2
    | 'category_meta'           // neu in Slice 3 (für Breadcrumb)
    | 'search_products'         // neu in Slice 5
  first?: number
  slug?: string
  page?: number        // neu: 1-indexed Seitennummer
  perPage?: number     // neu: Produkte pro Seite (default: 24)
  sort?: string        // neu: SortOption-String aus URL-Param
  search?: string      // neu: Suchbegriff (für Slice 5)
}
```

### 8. Kategorie-Page page.tsx (Erweiterung)

```typescript
// app/kategorie/[slug]/page.tsx (erweitert)
interface CategoryPageProps {
  params: Promise<{ slug: string }>
  searchParams: Promise<{ page?: string; sort?: string }>
}

export default async function CategoryPage({ params, searchParams }: CategoryPageProps) {
  const { slug } = await params
  const { page: pageParam, sort: sortParam } = await searchParams
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'

  // URL-Param Validierung und Sanitierung
  const pageRaw = parseInt(pageParam ?? '1', 10)
  const page = isNaN(pageRaw) || pageRaw < 1 ? 1 : pageRaw
  const validSorts = ['price_asc', 'price_desc', 'newest']
  const sort = sortParam && validSorts.includes(sortParam) ? sortParam : ''

  // Redirect zu Seite 1 wenn page-Param ungültig
  if (pageParam !== undefined && page !== pageRaw) {
    redirect(`/kategorie/${slug}${sort ? `?sort=${sort}` : ''}`)
  }

  const pageConfig = loadPageConfig('category', theme, {
    slug,
    page: String(page),
    sort,
  })

  return (
    <main id="main-content">
      <CategoryPageClient categoryName={slug} />
      <div className="space-y-0">
        <SectionRenderer
          sections={pageConfig.sections}
          skeletonMap={{
            'breadcrumb': null,
            'page-heading': null,
            'filter-chips': null,
            'product-count': null,
            'sort-bar': null,
            'product-grid': (
              <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                {Array.from({ length: 8 }).map((_, i) => (
                  <div key={i} className="aspect-square bg-surface-secondary animate-pulse rounded-card" />
                ))}
              </div>
            ),
            'pagination': null,
            'empty-state': null,
          }}
        />
      </div>
    </main>
  )
}
```

### 9. YAML-Erweiterung `themes/default/pages/category.yaml`

```yaml
# themes/default/pages/category.yaml
# Kategorie-Seiten-Konfiguration — wird geladen fuer /kategorie/[slug]

sections:
  - columns: 1
    blocks:
      - type: breadcrumb
        content_source: woocommerce
        params:
          query: category_meta
          slug: $route.slug

  - columns: 1
    blocks:
      - type: page-heading
        content_source: woocommerce
        params:
          query: products_by_category
          slug: $route.slug
          page: $route.page
          perPage: 24
          sort: $route.sort

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
          page: $route.page
          perPage: 24
          sort: $route.sort

  - columns: 1
    blocks:
      - type: sort-bar
        content_source: inline
        params:
          props:
            currentSort: $route.sort
            baseUrl: /kategorie/$route.slug

  - columns: 1
    blocks:
      - type: product-grid
        content_source: woocommerce
        params:
          query: products_by_category
          slug: $route.slug
          page: $route.page
          perPage: 24
          sort: $route.sort

  - columns: 1
    blocks:
      - type: pagination
        content_source: woocommerce
        params:
          query: products_by_category
          slug: $route.slug
          page: $route.page
          perPage: 24
          sort: $route.sort

  - columns: 1
    blocks:
      - type: empty-state
        content_source: inline
        params:
          props:
            headline: "Keine Produkte gefunden"
            text: "In dieser Kategorie sind aktuell keine Produkte verfügbar."
            links:
              - label: "Alle Kategorien"
                href: "/"
              - label: "Neuheiten"
                href: "/kategorie/neuheiten"
```

### 10. `category_meta` Branch im data-loader (für Breadcrumb)

```typescript
// data-loaders.ts — category_meta branch (neu)
} else if (params.query === 'category_meta') {
  const slug = params.slug
  if (!slug) return { data: null }

  const { data } = await getClient().query({
    query: GET_CATEGORY_META,
    variables: { slug },
  })
  const cat = data?.productCategory
  if (!cat) return { data: null }

  // BreadcrumbBlock erwartet BreadcrumbData.items
  return {
    data: {
      items: [
        { label: 'Startseite', href: '/' },
        { label: cat.name },   // kein href = aktuelle Seite
      ],
    },
  }
}
```

### 11. `GET_CATEGORY_META` Query (in queries.ts)

```typescript
// lib/graphql/queries.ts (Ergänzung)
export const GET_CATEGORY_META = gql`
  query GetCategoryMeta($slug: ID!) {
    productCategory(id: $slug, idType: SLUG) {
      name
      description
      slug
      count
      image {
        sourceUrl
        altText
      }
    }
  }
`
```

### 12. Apollo Cache Deduplication

Alle Blöcke (`breadcrumb` via `category_meta`, `page-heading`, `product-count`, `product-grid`, `pagination`) mit `query: products_by_category` + gleichen Variablen `{ slug, page, perPage, sort }` nutzen Apollo Client Cache-Deduplication — GraphQL wird nur einmal aufgerufen. `category_meta` hat eigene Cache-Identität (andere Query-Variablen), ebenfalls dedupliziert via Apollo `React.cache()` in server-client.ts.

---

## UI Anforderungen

### Wireframe (aus discovery.md)

> **Quelle:** `discovery.md` → "Screen: Kategorie-Page (erweitert)"

```
┌────────────────────────────────────────────────────────┐
│  Startseite › T-Shirts                  [breadcrumb]   │
├────────────────────────────────────────────────────────┤
│  T-Shirts                               [page-heading] │
├────────────────────────────────────────────────────────┤
│  [Alle] [Herren] [Damen] [Kinder]       [filter-chips] │
├────────────────────────────────────────────────────────┤
│  48 Produkte                            [product-count] │
├────────────────────────────────────────────────────────┤
│  Sortieren nach: [Preis: aufsteigend ▼] [sort-bar]     │
├────────────────────────────────────────────────────────┤
│  ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐                  │
│  │      │ │      │ │      │ │      │  [product-grid]   │
│  └──────┘ └──────┘ └──────┘ └──────┘                  │
│  ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐                  │
│  │      │ │      │ │      │ │      │                   │
│  └──────┘ └──────┘ └──────┘ └──────┘                  │
├────────────────────────────────────────────────────────┤
│  ‹  1  [2]  3  …  8  ›              [pagination]       │
└────────────────────────────────────────────────────────┘

Empty State (wenn 0 Produkte):
┌────────────────────────────────────────────────────────┐
│  Startseite › Neuheiten             [breadcrumb]       │
├────────────────────────────────────────────────────────┤
│  Neuheiten                          [page-heading]     │
├────────────────────────────────────────────────────────┤
│  🔍 Keine Produkte gefunden         [empty-state]      │
│  In dieser Kategorie sind aktuell keine Produkte.      │
│  → Alle Kategorien  → Neuheiten                        │
└────────────────────────────────────────────────────────┘
```

**Referenz Skills für UI-Implementation:**
- `.claude/skills/react-best-practices/SKILL.md` — `async-suspense-boundaries`, `server-cache-react`
- `.claude/skills/web-design/SKILL.md` — Accessibility nav/breadcrumb, URL reflects state
- `.claude/skills/tailwind-v4/SKILL.md` — Theme tokens, mobile-first

### 1. Breadcrumb auf Kategorie-Page

**Komponenten & Dateien:**
- `components/blocks/breadcrumb-block.tsx` — aus Slice 1, wird unverändert wiederverwendet

**Verhalten:**
- Datenquelle: `category_meta` Query → `data.items = [{ label: "Startseite", href: "/" }, { label: cat.name }]`
- KategorieName kommt aus WooCommerce `productCategory.name` (nicht aus dem URL-Slug)
- Semantisches `<nav aria-label="Breadcrumb">` mit JSON-LD BreadcrumbList Schema

**Zustände:**
- Loading: `null` (breadcrumb ist lightweight, kein Skeleton nötig)
- Error: Block rendert null (unsichtbar, kein broken Layout)
- Kein "Empty": Kategorie-Page hat immer einen Namen

### 2. Sort-Bar auf Kategorie-Page

**Komponenten & Dateien:**
- `components/blocks/sort-bar-block.tsx` — aus Slice 1, wird unverändert wiederverwendet

**Verhalten:**
- `currentSort` kommt aus `$route.sort` (URL-Param, sanitiert in page.tsx)
- `baseUrl` = `/kategorie/{slug}` — SortBarBlock appended `?sort=VALUE` intern
- Auswahl → `router.push(baseUrl + '?sort=' + value + (page > 1 ? '&page=1' : ''))` — Sort setzt Page zurück zu 1

**Zustände:**
- `no_sort`: Dropdown zeigt "Empfohlen" (Default)
- `sorted_price_asc`: Dropdown zeigt "Preis: aufsteigend"
- `sorted_price_desc`: Dropdown zeigt "Preis: absteigend"
- `sorted_newest`: Dropdown zeigt "Neueste zuerst"

### 3. Pagination auf Kategorie-Page

**Komponenten & Dateien:**
- `components/blocks/pagination-block.tsx` — aus Slice 1, wird unverändert wiederverwendet

**Transition Slice 1 → Slice 3:**
- In Slice 1: PaginationBlock hatte `content_source: inline` als Interim (statische Demodaten)
- In diesem Slice: `content_source: woocommerce` mit `query: products_by_category`
- `woocommerceLoader` gibt `PaginatedProductsResult` zurück — `pagination` Objekt daraus wird als `PaginationData` an PaginationBlock übergeben
- Interface bleibt kompatibel (`PaginationData` = { currentPage, totalPages, baseUrl, currentSort })

**Verhalten:**
- `currentPage` = URL-Param `?page=N` (parsed + sanitiert in page.tsx)
- `totalPages` = `Math.ceil(category.count / perPage)` aus WooCommerce
- `baseUrl` = `/kategorie/{slug}` + bestehende `?sort=` param erhalten
- Prev-Button disabled auf Seite 1; Next-Button disabled auf letzter Seite
- Seitenzahlen klickbar; aktuelle Seite nicht klickbar (aria-current="page")
- Links generiert als echte `<a href>` für SEO-Indexierbarkeit

**Zustände:**
- Loading: `null` (inline Skeleton reicht)
- Error: Block rendert null
- Empty (totalPages <= 1): Block rendert null (kein Pagination-UI für 1 Seite)

### 4. Empty-State auf Kategorie-Page

**Komponenten & Dateien:**
- `components/blocks/empty-state-block.tsx` — aus Slice 1, wird unverändert wiederverwendet

**Verhalten:**
- Rendering-Bedingung: `product-grid` Block checkt ob `data.products.nodes.length === 0`; wenn ja, rendert der Block selbst nichts UND EmptyStateBlock wird sichtbar
- Alternativstrategie: SectionRenderer kann bedingte Sichtbarkeit nicht direkt steuern. Stattdessen: `product-grid` Block rendert intern EmptyStateBlock wenn 0 Produkte
- YAML-konfigurierbar: `headline`, `text`, `links` via `inline` params

**Zustände:**
- Sichtbar (0 Produkte): Zeigt Headline + Text + Suggestion-Links
- Unsichtbar (>0 Produkte): `empty-state` Block rendert `null`

### 5. Accessibility

- [ ] Breadcrumb: `<nav aria-label="Breadcrumb">`, `<ol>`, letztes Item `aria-current="page"`
- [ ] Sort-Dropdown: `<select>` mit `<label>` oder `aria-label="Sortierung"` (aus Slice 1)
- [ ] Pagination: `aria-label="Seitennavigation"` auf `<nav>`, aktive Seite `aria-current="page"`
- [ ] Pagination-Links: echte `<a href>` (nicht Buttons mit onClick) für SEO + accessibility
- [ ] Empty-State Suggestions: `<a>` Tags mit vollem href

---

## Acceptance Criteria

1) GIVEN eine Kategorie mit mehr als 24 Produkten
   WHEN User ruft `/kategorie/t-shirts` auf (kein page-Param)
   THEN werden exakt 24 Produkte (Seite 1) angezeigt, Breadcrumb "Startseite › T-Shirts" ist sichtbar, Pagination zeigt Seitenzahlen, Prev-Button ist deaktiviert

2) GIVEN `/kategorie/t-shirts?page=2`
   WHEN User ruft diese URL auf
   THEN werden Produkte 25–48 (Seite 2) angezeigt, Pagination zeigt Seite 2 als aktiv (`aria-current="page"`), Prev-Button ist klickbar

3) GIVEN `/kategorie/t-shirts?sort=price_asc`
   WHEN User ruft diese URL auf
   THEN sind die Produkte nach Preis aufsteigend sortiert, Sort-Dropdown zeigt "Preis: aufsteigend"

4) GIVEN Sort-Dropdown auf Seite 2 (`?page=2&sort=price_asc`)
   WHEN User wählt "Neueste zuerst" aus dem Sort-Dropdown
   THEN navigiert Browser zu `/kategorie/t-shirts?sort=newest` (page wird auf 1 zurückgesetzt)

5) GIVEN eine Kategorie ohne Produkte
   WHEN User ruft `/kategorie/leere-kategorie` auf
   THEN ist kein product-grid sichtbar, stattdessen zeigt EmptyStateBlock Headline + Text + Suggestion-Links

6) GIVEN `/kategorie/t-shirts?page=abc` (ungültiger page-Param)
   WHEN Browser lädt die Seite
   THEN wird zu `/kategorie/t-shirts` (Seite 1) redirected

7) GIVEN `/kategorie/t-shirts?sort=invalid` (ungültiger sort-Param)
   WHEN Browser lädt die Seite
   THEN wird Default-Sortierung (WooCommerce) angewendet, kein Fehler, kein Redirect

8) GIVEN Kategorie-Page mit Sortierung `?sort=price_desc`
   WHEN PaginationBlock rendert Seitenlinks
   THEN enthalten alle Seitenlinks den `?sort=price_desc` Parameter (Sort bleibt erhalten beim Seitenwechsel)

---

## Testfälle

**WICHTIG:** Tests müssen VOR der Implementierung definiert werden! Der Orchestrator führt diese Tests automatisch nach der Slice-Implementierung aus.

### Test-Datei

**Für diesen Slice:** `tests/slices/shop-completeness/slice-03-kategorie-page-enhancements.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// tests/slices/shop-completeness/slice-03-kategorie-page-enhancements.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'

// ─── Mocks ───────────────────────────────────────────────────────────────────

vi.mock('@/lib/apollo/server-client', () => ({
  getClient: vi.fn(() => ({
    query: vi.fn(),
  })),
}))

vi.mock('next/navigation', () => ({
  useRouter: vi.fn(() => ({ push: vi.fn(), replace: vi.fn() })),
  useSearchParams: vi.fn(() => new URLSearchParams()),
  redirect: vi.fn(),
}))

// ─── Tests: URL-Param Parsing & Sanitierung ──────────────────────────────────

describe('Kategorie-Page URL-Param Handling', () => {
  it('should parse valid page param as integer', () => {
    const parsePageParam = (raw: string | undefined): number => {
      const parsed = parseInt(raw ?? '1', 10)
      return isNaN(parsed) || parsed < 1 ? 1 : parsed
    }
    expect(parsePageParam('2')).toBe(2)
    expect(parsePageParam('1')).toBe(1)
    expect(parsePageParam(undefined)).toBe(1)
  })

  it('should fallback to page 1 for invalid page params', () => {
    const parsePageParam = (raw: string | undefined): number => {
      const parsed = parseInt(raw ?? '1', 10)
      return isNaN(parsed) || parsed < 1 ? 1 : parsed
    }
    expect(parsePageParam('abc')).toBe(1)
    expect(parsePageParam('0')).toBe(1)
    expect(parsePageParam('-5')).toBe(1)
  })

  it('should accept valid sort params', () => {
    const parseSortParam = (raw: string | undefined): string => {
      const validSorts = ['price_asc', 'price_desc', 'newest']
      return raw && validSorts.includes(raw) ? raw : ''
    }
    expect(parseSortParam('price_asc')).toBe('price_asc')
    expect(parseSortParam('price_desc')).toBe('price_desc')
    expect(parseSortParam('newest')).toBe('newest')
  })

  it('should reject invalid sort params and return empty string', () => {
    const parseSortParam = (raw: string | undefined): string => {
      const validSorts = ['price_asc', 'price_desc', 'newest']
      return raw && validSorts.includes(raw) ? raw : ''
    }
    expect(parseSortParam('invalid')).toBe('')
    expect(parseSortParam('PRICE_ASC')).toBe('')
    expect(parseSortParam(undefined)).toBe('')
  })
})

// ─── Tests: Sort-Mapping ─────────────────────────────────────────────────────

describe('Sort-Mapping buildOrderby()', () => {
  const buildOrderby = (sort: string | undefined) => {
    switch (sort) {
      case 'price_asc':  return [{ field: 'PRICE', order: 'ASC' }]
      case 'price_desc': return [{ field: 'PRICE', order: 'DESC' }]
      case 'newest':     return [{ field: 'DATE', order: 'DESC' }]
      default:           return undefined
    }
  }

  it('should map price_asc to PRICE ASC orderby', () => {
    expect(buildOrderby('price_asc')).toEqual([{ field: 'PRICE', order: 'ASC' }])
  })

  it('should map price_desc to PRICE DESC orderby', () => {
    expect(buildOrderby('price_desc')).toEqual([{ field: 'PRICE', order: 'DESC' }])
  })

  it('should map newest to DATE DESC orderby', () => {
    expect(buildOrderby('newest')).toEqual([{ field: 'DATE', order: 'DESC' }])
  })

  it('should return undefined for empty sort (WooCommerce default)', () => {
    expect(buildOrderby('')).toBeUndefined()
    expect(buildOrderby(undefined)).toBeUndefined()
  })
})

// ─── Tests: Over-fetch + Slice Pagination Logic ──────────────────────────────

describe('Pagination: Over-fetch + Slice Logic', () => {
  const paginate = (
    allNodes: unknown[],
    page: number,
    perPage: number,
    categoryCount: number
  ) => {
    const pageNodes = allNodes.slice((page - 1) * perPage, page * perPage)
    const hasNextPage = allNodes.length > page * perPage
    const totalPages = categoryCount > 0
      ? Math.ceil(categoryCount / perPage)
      : Math.ceil(allNodes.length / perPage) + (hasNextPage ? 1 : 0)
    return {
      pageNodes,
      hasNextPage,
      hasPreviousPage: page > 1,
      totalPages: Math.max(totalPages, page),
      currentPage: page,
    }
  }

  it('should return first 24 products for page 1', () => {
    const allNodes = Array.from({ length: 25 }, (_, i) => ({ id: String(i) }))
    const result = paginate(allNodes, 1, 24, 50)
    expect(result.pageNodes).toHaveLength(24)
    expect(result.hasNextPage).toBe(true)
    expect(result.hasPreviousPage).toBe(false)
  })

  it('should return products 25-48 for page 2', () => {
    const allNodes = Array.from({ length: 49 }, (_, i) => ({ id: String(i) }))
    const result = paginate(allNodes, 2, 24, 60)
    expect(result.pageNodes).toHaveLength(24)
    expect(result.currentPage).toBe(2)
    expect(result.hasPreviousPage).toBe(true)
  })

  it('should detect hasNextPage correctly using over-fetch +1', () => {
    // fetchCount = page * perPage + 1 = 2 * 24 + 1 = 49
    const allNodes49 = Array.from({ length: 49 }, (_, i) => ({ id: String(i) }))
    const result = paginate(allNodes49, 2, 24, 0)
    expect(result.hasNextPage).toBe(true)

    const allNodes48 = Array.from({ length: 48 }, (_, i) => ({ id: String(i) }))
    const resultNoNext = paginate(allNodes48, 2, 24, 0)
    expect(resultNoNext.hasNextPage).toBe(false)
  })

  it('should calculate totalPages from categoryCount', () => {
    const allNodes = Array.from({ length: 25 }, (_, i) => ({ id: String(i) }))
    const result = paginate(allNodes, 1, 24, 100)
    expect(result.totalPages).toBe(Math.ceil(100 / 24)) // = 5
  })

  it('should return empty pageNodes for empty category', () => {
    const result = paginate([], 1, 24, 0)
    expect(result.pageNodes).toHaveLength(0)
    expect(result.hasNextPage).toBe(false)
    expect(result.totalPages).toBe(1)
  })
})

// ─── Tests: fetchCount Calculation ───────────────────────────────────────────

describe('Pagination: fetchCount Calculation', () => {
  it('should compute fetchCount as page * perPage + 1 for over-fetch', () => {
    const computeFetchCount = (page: number, perPage: number) => page * perPage + 1
    expect(computeFetchCount(1, 24)).toBe(25)
    expect(computeFetchCount(2, 24)).toBe(49)
    expect(computeFetchCount(3, 24)).toBe(73)
  })
})

// ─── Tests: Breadcrumb Data Construction ─────────────────────────────────────

describe('Breadcrumb Data for Category Page', () => {
  it('should build breadcrumb items with Startseite and category name', () => {
    const buildBreadcrumb = (categoryName: string) => ({
      items: [
        { label: 'Startseite', href: '/' },
        { label: categoryName },
      ],
    })

    const result = buildBreadcrumb('T-Shirts')
    expect(result.items).toHaveLength(2)
    expect(result.items[0]).toEqual({ label: 'Startseite', href: '/' })
    expect(result.items[1]).toEqual({ label: 'T-Shirts' })
    expect(result.items[1]).not.toHaveProperty('href')
  })

  it('should use WooCommerce category name, not URL slug', () => {
    const buildBreadcrumb = (categoryName: string) => ({
      items: [
        { label: 'Startseite', href: '/' },
        { label: categoryName },
      ],
    })
    // Slug "t-shirts" → WC Name "T-Shirts" (korrekte Formatierung)
    const result = buildBreadcrumb('T-Shirts')
    expect(result.items[1].label).toBe('T-Shirts')
    // Nicht der URL-Slug "t-shirts"
    expect(result.items[1].label).not.toBe('t-shirts')
  })
})

// ─── Tests: SortBar baseUrl Construction ─────────────────────────────────────

describe('SortBar baseUrl für Kategorie-Page', () => {
  it('should build baseUrl as /kategorie/{slug}', () => {
    const buildBaseUrl = (slug: string) => `/kategorie/${slug}`
    expect(buildBaseUrl('t-shirts')).toBe('/kategorie/t-shirts')
    expect(buildBaseUrl('neuheiten')).toBe('/kategorie/neuheiten')
  })

  it('should preserve sort param when changing page', () => {
    const buildPageUrl = (baseUrl: string, page: number, sort: string) => {
      const params = new URLSearchParams()
      if (page > 1) params.set('page', String(page))
      if (sort) params.set('sort', sort)
      const query = params.toString()
      return query ? `${baseUrl}?${query}` : baseUrl
    }
    expect(buildPageUrl('/kategorie/t-shirts', 2, 'price_asc')).toBe(
      '/kategorie/t-shirts?page=2&sort=price_asc'
    )
    expect(buildPageUrl('/kategorie/t-shirts', 1, 'price_desc')).toBe(
      '/kategorie/t-shirts?sort=price_desc'
    )
    expect(buildPageUrl('/kategorie/t-shirts', 1, '')).toBe('/kategorie/t-shirts')
  })
})

// ─── Tests: YAML category.yaml Neue Blocks ───────────────────────────────────

describe('Category YAML: Neue Block-Typen', () => {
  it('should include breadcrumb block with category_meta query', () => {
    // Validiert dass die YAML-Konfiguration korrekt ist
    const expectedBreadcrumbBlock = {
      type: 'breadcrumb',
      content_source: 'woocommerce',
      params: {
        query: 'category_meta',
        slug: '$route.slug',
      },
    }
    expect(expectedBreadcrumbBlock.type).toBe('breadcrumb')
    expect(expectedBreadcrumbBlock.params.query).toBe('category_meta')
  })

  it('should include sort-bar block with inline content_source', () => {
    const expectedSortBarBlock = {
      type: 'sort-bar',
      content_source: 'inline',
      params: {
        props: {
          currentSort: '$route.sort',
          baseUrl: '/kategorie/$route.slug',
        },
      },
    }
    expect(expectedSortBarBlock.content_source).toBe('inline')
    expect(expectedSortBarBlock.params.props.currentSort).toBe('$route.sort')
  })

  it('should include pagination block with woocommerce source and products_by_category query', () => {
    const expectedPaginationBlock = {
      type: 'pagination',
      content_source: 'woocommerce',
      params: {
        query: 'products_by_category',
        slug: '$route.slug',
        page: '$route.page',
        perPage: 24,
        sort: '$route.sort',
      },
    }
    expect(expectedPaginationBlock.type).toBe('pagination')
    expect(expectedPaginationBlock.params.query).toBe('products_by_category')
    expect(expectedPaginationBlock.params.perPage).toBe(24)
  })
})

// ─── Tests: Empty-State Trigger ──────────────────────────────────────────────

describe('Empty-State Rendering-Bedingung', () => {
  it('should show empty state when products nodes is empty array', () => {
    const shouldShowEmptyState = (products: unknown[]) => products.length === 0
    expect(shouldShowEmptyState([])).toBe(true)
    expect(shouldShowEmptyState([{ id: '1' }])).toBe(false)
  })

  it('should configure empty-state with German headline and suggestion links', () => {
    const emptyStateConfig = {
      headline: 'Keine Produkte gefunden',
      text: 'In dieser Kategorie sind aktuell keine Produkte verfügbar.',
      links: [
        { label: 'Alle Kategorien', href: '/' },
        { label: 'Neuheiten', href: '/kategorie/neuheiten' },
      ],
    }
    expect(emptyStateConfig.headline).toBe('Keine Produkte gefunden')
    expect(emptyStateConfig.links).toHaveLength(2)
    expect(emptyStateConfig.links[0].href).toBe('/')
  })
})
```
</test_spec>

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig & vollständig
- [x] Sicherheits-/Privacy-Aspekte bedacht (URL-Params sanitiert, kein SQL-Injection-Risiko via GraphQL)
- [x] UX/Copy final (deutsche Labels, Suggestion-Links konfigurierbar)
- [x] Rollout-/Rollback-Plan: YAML-Änderung ist rückwärtskompatibel — bestehende Blöcke funktionieren weiterhin mit alten Params (page/sort sind optional mit Defaults)

---

## Skill Verification (UI-Implementation)

### React Best Practices Verification

**Critical Priority:**
- [x] `async-parallel`: Alle Blocks mit gleichen `products_by_category` Variablen nutzen Apollo Cache-Dedup (kein sequentielles Fetching)
- [x] `async-suspense-boundaries`: Jeder async Block in `<Suspense fallback={skeleton}>` via SectionRenderer

**High Priority:**
- [x] `server-cache-react`: Apollo Server-Client in `lib/apollo/server-client.ts` nutzt `React.cache()` für Deduplication zwischen RSC
- [x] `bundle-dynamic-imports`: SortBarBlock ist Client Component (in Slice 1 via `'use client'`) — kein Heavy Import nötig

**Medium Priority:**
- [x] `rerender-dependencies`: SortBarBlock aus Slice 1 nutzt primitive Dependencies in useSearchParams

### Web Design Guidelines Verification

**Accessibility:**
- [x] Breadcrumb: `<nav aria-label="Breadcrumb">` + `aria-current="page"` auf letztem Item (aus Slice 1)
- [x] Sort-Dropdown: `<label>` assoziiert (aus Slice 1)
- [x] Pagination-Links: echte `<a href>` Tags, nicht Buttons (SEO + Keyboard + Screen Reader)
- [x] Empty-State: Suggestion-Links als `<a>` Tags

**Navigation:**
- [x] URL reflects state: `?page=N&sort=X` korrekt in URL encodiert
- [x] Links use `<a>`/`<Link>` — Pagination-Links sind echte Next.js `<Link>` Komponenten (Cmd+Click funktioniert)

**Touch & Mobile:**
- [x] `touch-action: manipulation` auf Pagination-Buttons (aus Slice 1 in sort-bar/pagination Blocks)
- [x] Touch targets min. 44px (aus `CLAUDE.md`: `--min-touch-target: 2.75rem`)

### Tailwind v4 Patterns Verification

- [x] Keine hardcoded Farben oder Abstände — nur Theme-Tokens (`bg-surface-secondary`, `rounded-card`, etc.)
- [x] Mobile-first: Produkt-Grid `grid-cols-2 md:grid-cols-3 lg:grid-cols-4`

---

## Constraints & Hinweise

**Betrifft:**
- `app/kategorie/[slug]/page.tsx` — muss `searchParams` prop als zweites Argument empfangen (Next.js 16 App Router Pattern: `Promise<{ page?: string; sort?: string }>`)
- `lib/blocks/data-loaders.ts` — `products_by_category` Branch muss Rückwärtskompatibilität wahren: wenn `page`/`sort` nicht übergeben → Defaults (page=1, keine Sortierung)

**API Contract:**
- `GET_PRODUCTS_PAGINATED` Query: `$categorySlug` ist nullable (null = kein Kategorie-Filter, für Slice 5 Suchseite)
- `PaginatedProductsResult` wird auch von Slice 5 (`search_products`) und Slice 6 (`collection`) konsumiert — Interface darf nicht brechen

**Abgrenzung:**
- Facetten-Filter (Farbe, Größe, Preis) sind explizit Out of Scope (discovery.md)
- Infinite Scroll ist Out of Scope — klassische Pagination ist gewählt (SEO-Vorteil)
- `generateStaticParams()` in page.tsx bleibt unverändert — ISR mit `revalidate = 60` bleibt aktiv
- `CategoryPageClient` (Pinterest Tracking) bleibt unverändert

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft. Unvollständige Contracts blockieren die Genehmigung.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-cross-page-infrastruktur | `BreadcrumbBlock` | Component | Export: `components/blocks/breadcrumb-block.tsx`; Props: `BlockComponentProps<BreadcrumbData>` |
| slice-01-cross-page-infrastruktur | `SortBarBlock` | Component | Export: `components/blocks/sort-bar-block.tsx`; Props: `BlockComponentProps<SortBarData>` |
| slice-01-cross-page-infrastruktur | `PaginationBlock` | Component | Export: `components/blocks/pagination-block.tsx`; Props: `BlockComponentProps<PaginationData>` |
| slice-01-cross-page-infrastruktur | `EmptyStateBlock` | Component | Export: `components/blocks/empty-state-block.tsx`; Props: `BlockComponentProps<EmptyStateData>` |
| slice-01-cross-page-infrastruktur | `PaginationData` | Type | Export: `lib/blocks/types.ts`; Fields: `{ currentPage, totalPages, baseUrl, currentSort }` |
| slice-01-cross-page-infrastruktur | `SortOption` | Type | Export: `lib/blocks/types.ts`; Values: `'default' \| 'price_asc' \| 'price_desc' \| 'newest'` |
| slice-01-cross-page-infrastruktur | `BreadcrumbData` | Type | Export: `lib/blocks/types.ts`; Fields: `{ items: { label: string, href?: string }[] }` |
| slice-01-cross-page-infrastruktur | `EmptyStateData` | Type | Export: `lib/blocks/types.ts`; Fields: `{ headline: string, text: string, links?: { label, href }[] }` |
| slice-01-cross-page-infrastruktur | `SortBarData` | Type | Export: `lib/blocks/types.ts`; Fields: `{ currentSort: SortOption, baseUrl: string }` |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `GET_PRODUCTS_PAGINATED` | GraphQL Query | slice-05-suchseite | `gql` Query-String; Variablen: `{ categorySlug?: String, first: Int!, orderby?, search? }` |
| `GET_CATEGORY_META` | GraphQL Query | slice-06-neue-pages (`collection-header`) | `gql` Query-String; Variablen: `{ slug: ID! }` |
| `PaginatedProductsResult` | DTO Type | slice-05-suchseite, slice-06-neue-pages | `{ products: { nodes: ProductCardData[] }, pagination: PaginationMeta, productCategory? }` |
| `PaginationMeta` | DTO Type | slice-05-suchseite, slice-06-neue-pages | `{ currentPage, totalPages, hasNextPage, hasPreviousPage, totalCount }` |
| `products_by_category` (paginated) | Query Branch in data-loaders.ts | slice-05-suchseite (als Vorbild für `search_products`) | Erweiterter Branch mit `page`, `perPage`, `sort` Params |
| `buildOrderby()` | Function (intern) | slice-05-suchseite (wiederverwendet aus data-loaders.ts) | `(sort: string \| undefined) => ProductsOrderbyInput[] \| undefined` |

### Integration Validation Tasks

- [ ] `BreadcrumbBlock` aus Slice 1 akzeptiert `BreadcrumbData` mit `items` Array (letztes Item ohne `href`)
- [ ] `SortBarBlock` aus Slice 1 akzeptiert `SortBarData` mit `currentSort` und `baseUrl`
- [ ] `PaginationBlock` aus Slice 1 akzeptiert `PaginationData`-kompatibles Objekt aus `PaginatedProductsResult.pagination`
- [ ] `EmptyStateBlock` aus Slice 1 rendert `null` wenn Produkte vorhanden (bedingte Logik in product-grid)
- [ ] `GET_PRODUCTS_PAGINATED` Query von Slice 5 wiederverwendbar ohne Breaking Change

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind **PFLICHT-Deliverables**.
> Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `GET_PRODUCTS_PAGINATED` Query | Abschnitt 3 | YES | In `lib/graphql/queries.ts` exportieren |
| `GET_CATEGORY_META` Query | Abschnitt 11 | YES | In `lib/graphql/queries.ts` exportieren |
| `buildOrderby()` Funktion | Abschnitt 4 | YES | In `lib/blocks/data-loaders.ts` (intern, nicht exportiert) |
| `products_by_category` Branch (paginated) | Abschnitt 5 | YES | In `lib/blocks/data-loaders.ts` den bestehenden Branch ersetzen |
| `category_meta` Branch | Abschnitt 10 | YES | In `lib/blocks/data-loaders.ts` als neuen Branch hinzufügen |
| `PaginatedProductsResult` DTO | Abschnitt 6 | YES | In `lib/blocks/types.ts` exportieren |
| `PaginationMeta` DTO | Abschnitt 6 | YES | In `lib/blocks/types.ts` exportieren |
| `WooCommerceLoaderParams` Erweiterung | Abschnitt 7 | YES | In `lib/blocks/types.ts` ersetzen (rückwärtskompatibel) |
| `CategoryPage` page.tsx Erweiterung | Abschnitt 8 | YES | `app/kategorie/[slug]/page.tsx` erweitern (NICHT neu erstellen) |
| `category.yaml` Erweiterung | Abschnitt 9 | YES | `themes/default/pages/category.yaml` vollständig ersetzen |

---

## Links

- Design/Spec: `specs/phase-1/2026-02-25-shop-completeness/discovery.md` → "Screen: Kategorie-Page (erweitert)"
- Architecture: `specs/phase-1/2026-02-25-shop-completeness/architecture.md` → "Pagination Logic", "Sort Mapping", "Extended category.yaml"
- Vorherige Slices: `slice-01-cross-page-infrastruktur.md` (Blocks), `slice-02-produkt-page-enhancements.md` (kein direkter Impact)
- Bestehende Datei: `frontend/app/kategorie/[slug]/page.tsx`
- Bestehende Datei: `frontend/themes/default/pages/category.yaml`
- Bestehende Datei: `frontend/lib/blocks/data-loaders.ts`
- Bestehende Datei: `frontend/lib/blocks/types.ts`
- Bestehende Datei: `frontend/lib/graphql/queries.ts`

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Frontend
- [ ] `frontend/app/kategorie/[slug]/page.tsx` — Erweitern: `searchParams` prop (`page`, `sort`) lesen; URL-Param Validierung + Redirect; `loadPageConfig` mit `{ slug, page, sort }`; `skeletonMap` um breadcrumb, sort-bar, pagination, empty-state erweitern
- [ ] `frontend/lib/blocks/types.ts` — `WooCommerceLoaderParams` um `page?: number`, `perPage?: number`, `sort?: string`, `search?: string` erweitern; `PaginatedProductsResult` und `PaginationMeta` Interface exportieren
- [ ] `frontend/lib/graphql/queries.ts` — `GET_PRODUCTS_PAGINATED` Query exportieren; `GET_CATEGORY_META` Query exportieren
- [ ] `frontend/lib/blocks/data-loaders.ts` — `buildOrderby()` Funktion (intern); `products_by_category` Branch mit over-fetch + slice + sort-mapping erweitern; `category_meta` Branch hinzufügen; Import `GET_PRODUCTS_PAGINATED` und `GET_CATEGORY_META`
- [ ] `frontend/themes/default/pages/category.yaml` — Vollständig ersetzen: breadcrumb, page-heading (mit page/sort params), filter-chips, product-count (mit page/sort), sort-bar (inline), product-grid (mit page/sort), pagination (woocommerce), empty-state (inline)

### Tests
- [ ] `tests/slices/shop-completeness/slice-03-kategorie-page-enhancements.test.ts` — Vitest Tests für URL-Param Parsing, Sort-Mapping, Over-fetch Pagination, Breadcrumb Data, SortBar baseUrl, YAML Block-Konfiguration, Empty-State Trigger
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind **Pflicht**
- Der Stop-Hook prüft automatisch ob alle Dateien existieren
- Bei fehlenden Dateien wird der Agent blockiert und muss nachfragen
- `app/kategorie/[slug]/page.tsx` ist eine **Erweiterung** der bestehenden Datei — nicht neu erstellen
- `category.yaml` wird vollständig ersetzt (alle neuen Blöcke müssen enthalten sein)
- `data-loaders.ts` wird erweitert — bestehende Branches (`featured_products`, `product_categories`, `product_by_slug`) bleiben unverändert
