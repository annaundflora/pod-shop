# Slice 5: Suchseite implementieren

> **Slice 5 von 6** für `Shop Completeness — Blocks & Pages`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-04-homepage-enhancements.md` |
> | **Nächster:** | `slice-06-neue-pages.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-05-suchseite` |
| **Test** | `pnpm test tests/slices/shop-completeness/slice-05-suchseite.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-cross-page-infrastruktur", "slice-03-kategorie-page-enhancements"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` — Vitest Unit/Component Tests (kein Playwright)
- **Dependencies**: Slice 1 (`PaginationBlock`, `SortBarBlock`, `EmptyStateBlock`, `loadGlobalConfig`, Block-Registry-Pattern) und Slice 3 (`GET_PRODUCTS_PAGINATED`, `PaginatedProductsResult`, `PaginationMeta`, `WooCommerceLoaderParams` mit `search`/`page`/`sort`-Feldern, `buildOrderby`-Funktion in data-loaders.ts) müssen fertig sein

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` (next ^16.1.6, vitest ^3.0.0, typescript ^5.7.0, @tailwindcss/postcss ^4.0.0).

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/shop-completeness/slice-05-suchseite.test.ts` |
| **Integration Command** | `pnpm test tests/slices/shop-completeness/` |
| **Acceptance Command** | `pnpm test tests/slices/shop-completeness/slice-05-suchseite.test.ts --reporter=verbose` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `mock_external` |

**Erklaerung:**
- **Stack**: typescript-nextjs (Next.js 16 App Router, Vitest, Tailwind CSS v4)
- **Test Command**: Einzelner Slice-Testlauf via Vitest (Standard-Reporter)
- **Acceptance Command**: Identische Testdatei mit `--reporter=verbose` fuer vollstaendige AC-Ausgabe (lesbar als Acceptance-Protokoll durch den Orchestrator)
- **Mocking Strategy**: `mock_external` — Apollo Server Client (`lib/apollo/server-client.ts`) wird per `vi.mock()` gemockt. `next/navigation` (`useRouter`, `useSearchParams`) wird fuer `SearchBarBlock`-Tests gemockt. `lib/blocks/page-config.ts` (`loadPageConfig`) wird gemockt um YAML-Laden zu umgehen. `localStorage` ist via jsdom (bereits konfiguriert) verfuegbar.

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Cross-Page Infrastruktur | Done | `slice-01-cross-page-infrastruktur.md` |
| 2 | Produkt-Page Enhancements | Done | `slice-02-produkt-page-enhancements.md` |
| 3 | Kategorie-Page Enhancements | Done | `slice-03-kategorie-page-enhancements.md` |
| 4 | Homepage Enhancements | Done | `slice-04-homepage-enhancements.md` |
| 5 | Suchseite | Ready | `slice-05-suchseite.md` |
| 6 | Neue Pages | Pending | `slice-06-neue-pages.md` |

---

## Kontext & Ziel

Aktuell hat der Shop keine Suchfunktion. Nutzer mit konkretem Kaufwunsch (z.B. "Katzen-Shirt") können Produkte nicht direkt finden — sie müssen manuell durch Kategorien navigieren oder verlassen den Shop. Dies führt direkt zu höherer Bounce Rate und niedrigerer Conversion.

**Aktuelle Probleme:**
1. Route `/suche` existiert nicht — 404 für jede Suchanfrage
2. Kein `search-bar` Block — kein Sucheingabefeld im Block-System registriert
3. Kein `search-results` Block — keine Darstellung von Suchergebnissen
4. `woocommerceLoader` kennt keinen `search_products`-Query-Typ
5. `GET_PRODUCTS_PAGINATED` aus Slice 3 unterstützt bereits `$search`-Variable — wird wiederverwendet
6. Kein `themes/default/pages/search.yaml` — kein Block-Config für die Suchseite
7. Header-Suchfeld fehlt: User haben keinen Einstiegspunkt zur Suche (kein globaler Search-Trigger)

**Zielbild:** Route `/suche?q=shirt` zeigt Suchergebnisse mit Echtzeit-Suchfeld (URL-basiert), Ergebniszähler, Sortierung, Pagination und Empty-State. Header bekommt ein Suchicon das `/suche` mit aktuellem Query öffnet.

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → "New Page Routes", "Extended woocommerceLoader Dispatch", "Search Logic", "New Block Components", "URL Parameter Handling Pattern", "search.yaml"

```
Search Page Pattern:
  Client-Side Search Input (SearchBarBlock: 'use client')
    → Enter/Button-Click → router.push('/suche?q=...')
    → URL changes → RSC re-render (ISR)
    → SearchResultsBlock fetches via woocommerceLoader('search_products')
    → GET_PRODUCTS_PAGINATED with search variable
    → PaginatedProductsResult → ProductGrid + Pagination

Search Logic in data-loader:
  If q.length < 2: return empty PaginatedProductsResult (no query)
  If q.length >= 2: GET_PRODUCTS_PAGINATED(first: page * perPage + 1, search: q, orderby)
  Over-fetch + slice (identisch wie products_by_category in Slice 3)

URL Parameter Pattern:
  /suche?q=shirt&page=2&sort=price_asc
  → page.tsx reads searchParams: { q, page, sort }
  → loadPageConfig('search', theme, { q, page, sort })
  → resolveParams: $route.q → "shirt", $route.page → "2", $route.sort → "price_asc"
  → SectionRenderer iteriert search.yaml sections
```

### 1. Architektur-Impact

| Layer | Art | Änderung |
|-------|-----|----------|
| `frontend/app/suche/page.tsx` | NEU | Route, searchParams lesen (`q`, `page`, `sort`), loadPageConfig aufrufen, skeletonMap mit search-relevanten Blocks |
| `frontend/components/blocks/search-bar-block.tsx` | NEU | Client Component, URL-Steuerung, Input mit Enter-Handler, Search-Clear-Button |
| `frontend/components/blocks/search-results-block.tsx` | NEU | Server Component, delegiert an ProductGrid-Darstellung, nutzt `PaginatedProductsResult` |
| `frontend/lib/blocks/data-loaders.ts` | MODIFY | Branch `search_products` hinzufügen: Over-fetch + Slice mit `search`-Variable |
| `frontend/lib/blocks/registry.ts` | MODIFY | `search-bar` und `search-results` registrieren |
| `frontend/lib/blocks/types.ts` | MODIFY | `SearchBarData`-Interface hinzufügen; `WooCommerceLoaderParams.query` um `'search_products'` erweitern (bereits in Slice 3 als Platzhalter dokumentiert) |
| `frontend/themes/default/pages/search.yaml` | NEU | Block-Konfiguration für Suchseite (search-bar, product-count, sort-bar, search-results, pagination, empty-state) |
| `frontend/components/layout/header.tsx` | MODIFY | Suchicon hinzufügen: klickt → navigiert zu `/suche` (ggf. mit aktuellem Query) |

### 2. Datenfluss

```
Browser: GET /suche?q=shirt&page=1&sort=price_asc
  ↓
app/suche/page.tsx (RSC, revalidate=60)
  → searchParams: { q: "shirt", page: "1", sort: "price_asc" }
  → Validierung: page >= 1, sort in ['price_asc','price_desc','newest',''], q.trim()
  → loadPageConfig('search', theme, { q: "shirt", page: "1", sort: "price_asc" })
  ↓
search.yaml via resolveParams
  → $route.q   → "shirt"
  → $route.page → "1"
  → $route.sort → "price_asc"
  ↓
SectionRenderer iteriert sections aus search.yaml
  ↓
  Section 1: search-bar (inline)
    → SearchBarBlock (Client Component)
    → zeigt Input mit value="shirt"
    → Enter / Button-Klick → router.push('/suche?q=...')
  ↓
  Section 2: product-count (woocommerce, query: search_products)
    → woocommerceLoader('search_products', { search: "shirt", page: "1", perPage: 24, sort: "price_asc" })
    → q.length >= 2 → GET_PRODUCTS_PAGINATED(first: 25, search: "shirt", orderby: PRICE_ASC)
    → PaginatedProductsResult { products: { nodes: [...] }, pagination: { totalCount: 47, ... } }
    → ProductCountBlock: "47 Ergebnisse für 'shirt'"
  ↓
  Section 3: sort-bar (inline)
    → SortBarBlock (Client Component, aus Slice 1)
    → baseUrl: "/suche", currentSort: "price_asc", preserves ?q=shirt in URL
  ↓
  Section 4: search-results (woocommerce, query: search_products)
    → Apollo RSC cache dedup (gleiche Variablen wie product-count)
    → SearchResultsBlock → rendert ProductCard-Grid (gleiche Komponenten wie product-grid)
  ↓
  Section 5: pagination (woocommerce, query: search_products)
    → Apollo RSC cache dedup
    → PaginationBlock: { currentPage: 1, totalPages: 2, baseUrl: "/suche?q=shirt&sort=price_asc" }
  ↓
  [Wenn products.nodes.length === 0]:
  Section 6: empty-state (inline)
    → EmptyStateBlock: "Keine Ergebnisse für 'shirt'" + Kategorie-Links
```

### 3. `search_products` Branch in data-loaders.ts

```typescript
// lib/blocks/data-loaders.ts (Ergänzung in woocommerceLoader)
} else if (params.query === 'search_products') {
  const searchQuery = (params.search as string | undefined) ?? ''
  const page = Number(params.page ?? 1)
  const perPage = Number(params.perPage ?? 24)
  const sort = params.sort as string | undefined

  // Business Rule: Min. 2 Zeichen für Query-Ausführung
  if (searchQuery.trim().length < 2) {
    return {
      data: {
        products: { nodes: [] },
        pagination: {
          currentPage: page,
          totalPages: 0,
          hasNextPage: false,
          hasPreviousPage: false,
          totalCount: 0,
        },
      } satisfies PaginatedProductsResult,
    }
  }

  const fetchCount = page * perPage + 1  // +1 für hasNextPage-Detection
  const orderby = buildOrderby(sort)      // buildOrderby aus Slice 3 wiederverwendet

  const { data } = await getClient().query({
    query: GET_PRODUCTS_PAGINATED,  // aus Slice 3, unterstützt $search-Variable
    variables: {
      search: searchQuery.trim(),
      first: fetchCount,
      orderby,
      categorySlug: null,  // null = kein Kategorie-Filter
    },
  })

  const allNodes = data?.products?.nodes ?? []
  const pageNodes = allNodes.slice((page - 1) * perPage, page * perPage)
  const hasNextPage = allNodes.length > page * perPage
  // Kein category.count verfügbar bei Suche → totalpages aus allNodes inferiert
  const totalPages = hasNextPage
    ? page + 1  // Mindestens eine weitere Seite
    : Math.ceil(allNodes.length / perPage) || (page > 1 ? page : 0)

  return {
    data: {
      products: { nodes: pageNodes },
      pagination: {
        currentPage: page,
        totalPages: Math.max(totalPages, page),
        hasNextPage,
        hasPreviousPage: page > 1,
        totalCount: allNodes.length,  // Approximate: over-fetched nodes count
      },
    } satisfies PaginatedProductsResult,
  }
}
```

### 4. TypeScript Interfaces

> Alle Interfaces aus architecture.md übernommen. `SearchBarData` ist neu definiert — entspricht `Block Data Type Definitions` Zeile 351 in architecture.md.

```typescript
// lib/blocks/types.ts (Ergänzungen)

// SearchBarData: YAML-konfigurierbar, currentQuery aus $route.q
export interface SearchBarData {
  placeholder: string         // z.B. "Was suchst du?"
  currentQuery?: string       // Aktueller Suchwert aus URL (?q=...), optional (leer bei erstem Aufruf)
}

// WooCommerceLoaderParams.query (Ergänzung zu Slice-3-Definition):
// 'search_products' war bereits in Slice-3-Spezifikation als "neu in Slice 5" deklariert.
// In diesem Slice wird er implementiert.
```

> **Hinweis:** `PaginatedProductsResult`, `PaginationMeta`, `WooCommerceLoaderParams` (mit `search`, `page`, `sort`, `perPage`-Feldern) wurden vollständig in Slice 3 definiert. Keine Redefinition nötig.

### 5. `SearchBarBlock` — Client Component Spezifikation

**Typ:** Client Component (`'use client'`)
**Content Source:** `inline`
**Data Type:** `SearchBarData`

```
State Machine:
  empty:     Input leer, Clear-Button hidden, Suchen-Button disabled (optional)
  typing:    Input hat Wert < 2 Zeichen, Clear-Button visible, kein Auto-Submit
  searching: Input hat Wert >= 2 Zeichen, Clear-Button visible, Enter/Button aktiv
```

**Render-Regeln:**
- `<form onSubmit>` mit `role="search"` und `aria-label="Produktsuche"`
- Input: `type="search"`, `aria-label="Suchbegriff eingeben"`, `placeholder={data.placeholder}`
- `defaultValue={data.currentQuery}` (unkontrolliert — Hydration-sicher, kein Flicker)
- Bei Submit (Enter oder Button): `router.push('/suche?q=' + encodeURIComponent(value.trim()))` — nur wenn `value.trim().length >= 2`
- Clear-Button: erscheint wenn Input nicht leer ist. Klick → `router.push('/suche')` + Input leeren
- Kein Debounce / Autocomplete / Typeahead — nur explizite Suche per Submit
- Suchicon: `lucide-react` `<Search />` (aria-hidden)
- Clear-Icon: `lucide-react` `<X />` mit `aria-label="Suche löschen"`

### 6. `SearchResultsBlock` — Server Component Spezifikation

**Typ:** Server Component
**Content Source:** `woocommerce`
**Data Type:** `PaginatedProductsResult` (aus Slice 3)

**Render-Regeln:**
- Rendert `products.nodes` als Produkt-Grid (identisches Layout wie `ProductGridBlock`)
- Nutzt die bestehenden `ProductCard`-Komponenten — kein eigener Card-Code
- Grid: `grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4` (identisch zu product-grid)
- Wenn `products.nodes.length === 0` UND `q` gesetzt (q.length >= 2): rendert `null` (EmptyStateBlock übernimmt via YAML)
- Wenn `q.length < 2`: rendert `null` (EmptyStateBlock mit Aufforderung zur Eingabe)
- Kein eigenes Skeleton (nutzt `ProductGridBlockSkeleton` aus product-grid für Suspense)

### 7. Header-Suchintegration

**Datei:** `frontend/components/layout/header.tsx` (MODIFY)

**Änderung:** Suchicon in die Header-Navigation einfügen.

```
Render-Regel:
  <Link href="/suche" aria-label="Suche öffnen">
    <Search aria-hidden="true" />
  </Link>
```

- Kein Overlay/Modal — Link navigiert direkt zu `/suche`
- Position: neben Cart-Icon in der Navigationsleiste (rechte Seite)
- Mobile: Icon sichtbar, kein ausgeschriebener Text (Platzersparnis)
- Desktop: Icon + optionaler Text "Suche" (YAML-konfigurierbar via Header-Config, falls vorhanden; sonst hardcoded Icon)

### 8. `themes/default/pages/search.yaml`

```yaml
sections:
  - columns: 1
    blocks:
      - type: search-bar
        content_source: inline
        params:
          props:
            placeholder: "Was suchst du?"
            currentQuery: "$route.q"
  - columns: 1
    blocks:
      - type: product-count
        content_source: woocommerce
        params:
          query: search_products
          search: "$route.q"
          page: "$route.page"
          perPage: 24
          sort: "$route.sort"
  - columns: 1
    blocks:
      - type: sort-bar
        content_source: inline
        params:
          props:
            currentSort: "$route.sort"
            baseUrl: "/suche"
            currentQuery: "$route.q"
  - columns: 1
    blocks:
      - type: search-results
        content_source: woocommerce
        params:
          query: search_products
          search: "$route.q"
          page: "$route.page"
          perPage: 24
          sort: "$route.sort"
  - columns: 1
    blocks:
      - type: pagination
        content_source: woocommerce
        params:
          query: search_products
          search: "$route.q"
          page: "$route.page"
          perPage: 24
          sort: "$route.sort"
  - columns: 1
    blocks:
      - type: empty-state
        content_source: inline
        params:
          props:
            headline: "Keine Ergebnisse gefunden"
            text: "Versuche es mit einem anderen Suchbegriff oder stöbere in unseren Kategorien."
            links:
              - label: "Alle Produkte"
                href: "/kategorie/alle"
              - label: "Neue Produkte"
                href: "/kategorie/neue-produkte"
```

> **Hinweis zu `currentQuery` in `sort-bar`:** Der `SortBarBlock` muss den `?q=`-Parameter beim Aufbau der Sort-URLs beibehalten. `baseUrl: "/suche"` + `currentQuery: "$route.q"` ergibt URLs wie `/suche?q=shirt&sort=price_asc`. Die `SortBarBlock`-Implementierung aus Slice 1 muss dieses `currentQuery`-Param unterstützen — das ist eine Erweiterung von `SortBarData` (Architecture Extension, siehe unten).

### 9. Architecture-Extension: SortBarData mit `currentQuery`

> **Architecture-Extension (bewusst):** Architecture `SortBarData` (architecture.md Zeile 347) definiert `currentSort: SortOption, baseUrl: string`. Für die Suchseite muss die Sort-URL auch den `?q=`-Parameter beibehalten, sonst geht der Suchbegriff bei Sortierungswechsel verloren. Erweiterung: `currentQuery?: string` — optionales Feld, rückwärtskompatibel.

```typescript
// lib/blocks/types.ts (Erweiterung von SortBarData aus Slice 1)
export interface SortBarData {
  currentSort: SortOption
  baseUrl: string
  currentQuery?: string  // Architecture Extension: Q-Param für Suchseite beibehalten
}
```

**SortBarBlock URL-Logik (erweitert):**
- Sortierung-Auswahl → `router.push(baseUrl + '?q=' + currentQuery + '&sort=' + newSort)` (wenn `currentQuery` gesetzt)
- Ohne `currentQuery`: `router.push(baseUrl + '?sort=' + newSort)` (bisheriges Verhalten, rückwärtskompatibel)

### 10. `app/suche/page.tsx` (RSC)

```typescript
// app/suche/page.tsx
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import { redirect } from 'next/navigation'
import type { Metadata } from 'next'

export const revalidate = 60

export async function generateMetadata({
  searchParams,
}: {
  searchParams: Promise<{ q?: string }>
}): Promise<Metadata> {
  const { q } = await searchParams
  const query = q?.trim() ?? ''
  return {
    title: query
      ? `Suche: "${query}" | ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`
      : `Suche | ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`,
    description: query
      ? `Suchergebnisse für "${query}" im ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`
      : 'Produktsuche',
    robots: { index: false, follow: true },  // noindex: Suchergebnisseiten nicht indexieren
  }
}

interface SearchPageProps {
  searchParams: Promise<{ q?: string; page?: string; sort?: string }>
}

export default async function SearchPage({ searchParams }: SearchPageProps) {
  const { q: qParam, page: pageParam, sort: sortParam } = await searchParams
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'

  // Validierung
  const q = qParam?.trim() ?? ''
  const pageRaw = parseInt(pageParam ?? '1', 10)
  const page = isNaN(pageRaw) || pageRaw < 1 ? 1 : pageRaw
  const validSorts = ['price_asc', 'price_desc', 'newest']
  const sort = sortParam && validSorts.includes(sortParam) ? sortParam : ''

  // Redirect zu Seite 1 wenn page-Param ungültig
  if (pageParam !== undefined && page !== pageRaw) {
    redirect(`/suche${q ? `?q=${encodeURIComponent(q)}` : ''}${sort ? `&sort=${sort}` : ''}`)
  }

  const pageConfig = loadPageConfig('search', theme, {
    q,
    page: String(page),
    sort,
  })

  return (
    <main id="main-content" className="container mx-auto px-4 py-8">
      <SectionRenderer
        sections={pageConfig.sections}
        skeletonMap={{
          'search-bar': null,
          'product-count': null,
          'sort-bar': null,
          'search-results': (
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
              {Array.from({ length: 8 }).map((_, i) => (
                <div key={i} className="aspect-square bg-gray-100 rounded-card animate-pulse" />
              ))}
            </div>
          ),
          'pagination': null,
          'empty-state': null,
        }}
      />
    </main>
  )
}
```

---

## Wireframe-Beschreibung

### Desktop `/suche?q=shirt` (1024px+)

```
┌─────────────────────────────────────────────────────────────┐
│ HEADER: Logo | Nav | [🔍 Suche] | Cart                      │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────────────────────────────────┐               │
│  │  [🔍]  shirt                      [✕]  │  [Suchen]     │
│  └─────────────────────────────────────────┘               │
│                                                             │
│  47 Ergebnisse für "shirt"                                  │
│                                                 [Sortieren ▼]│
│                                                             │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐      │
│  │ [Bild]  │ │ [Bild]  │ │ [Bild]  │ │ [Bild]  │      │
│  │ Produktname│ │ Produktname│ │ Produktname│ │ Produktname│      │
│  │ 24,99 € │ │ 19,99 € │ │ 29,99 € │ │ 22,99 € │      │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘      │
│  [weitere 4 Produkt-Cards in Zeile 2]                       │
│                                                             │
│              ‹  1  2  ›                                     │
│                                                             │
├─────────────────────────────────────────────────────────────┤
│ FOOTER                                                      │
└─────────────────────────────────────────────────────────────┘
```

### Mobile `/suche?q=shirt` (< 768px)

```
┌───────────────────────────┐
│ Logo     [🔍]  [Cart]     │
├───────────────────────────┤
│                           │
│ ┌─────────────────────┐   │
│ │ [🔍] shirt   [✕]   │   │
│ └─────────────────────┘   │
│ [Suchen Button – Full Width]│
│                           │
│ 47 Ergebnisse für "shirt" │
│ [Sortieren ▼]             │
│                           │
│ ┌──────────┐ ┌──────────┐ │
│ │ [Bild]  │ │ [Bild]  │ │
│ │ Produkt  │ │ Produkt  │ │
│ │ 24,99 € │ │ 19,99 € │ │
│ └──────────┘ └──────────┘ │
│ ┌──────────┐ ┌──────────┐ │
│ │ [Bild]  │ │ [Bild]  │ │
│ └──────────┘ └──────────┘ │
│                           │
│         ‹  1  2  ›        │
│                           │
└───────────────────────────┘
```

### Empty State `/suche?q=xyz` (keine Ergebnisse)

```
┌─────────────────────────────────────────────────────────────┐
│ HEADER                                                      │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────┐               │
│  │  [🔍]  xyz                        [✕]  │  [Suchen]     │
│  └─────────────────────────────────────────┘               │
│                                                             │
│            Keine Ergebnisse für "xyz"                       │
│                                                             │
│   Versuche es mit einem anderen Suchbegriff oder           │
│   stöbere in unseren Kategorien.                            │
│                                                             │
│   → Alle Produkte      → Neue Produkte                      │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Leere Suchseite `/suche` (kein Query)

```
┌─────────────────────────────────────────────────────────────┐
│ HEADER                                                      │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────────────────────────────────┐               │
│  │  [🔍]  Was suchst du?                   │  [Suchen]     │
│  └─────────────────────────────────────────┘               │
│                                                             │
│       Bitte gib einen Suchbegriff ein (min. 2 Zeichen)     │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## Acceptance Criteria

**AC1: Suchseite erreichbar**
```
GIVEN der User navigiert zu /suche
WHEN die Seite geladen wird
THEN wird HTTP 200 zurückgegeben und ein Element mit role="search" ist im DOM vorhanden
```

**AC2: Suchanfrage mit Ergebnissen**
```
GIVEN /suche?q=shirt wird aufgerufen
WHEN woocommerceLoader('search_products') mit search="shirt" ausgeführt wird
THEN gibt der Loader ein PaginatedProductsResult mit products.nodes.length > 0 zurück,
     sofern WooCommerce Produkte mit "shirt" im Titel/Beschreibung enthält
```

**AC3: Mindestzeichenregel**
```
GIVEN woocommerceLoader('search_products') wird aufgerufen
WHEN search parameter weniger als 2 Zeichen lang ist (z.B. "" oder "a")
THEN gibt der Loader PaginatedProductsResult mit products.nodes = [] und pagination.totalCount = 0 zurück,
     ohne eine GraphQL-Query an WooCommerce zu senden
```

**AC4: URL-Parameter werden beibehalten beim Seitenwechsel**
```
GIVEN /suche?q=shirt&sort=price_asc&page=1 ist aktiv
WHEN PaginationBlock die URL für Seite 2 generiert
THEN ist die generierte URL /suche?q=shirt&sort=price_asc&page=2 (q- und sort-Params erhalten)
```

**AC5: Sortierung behält Suchbegriff**
```
GIVEN SortBarBlock rendert mit baseUrl="/suche" und currentQuery="shirt" und currentSort=""
WHEN sort "price_asc" ausgewählt wird
THEN navigiert router.push zu /suche?q=shirt&sort=price_asc
```

**AC6: Empty State bei 0 Ergebnissen**
```
GIVEN /suche?q=xyzunbekannt123 wird aufgerufen
WHEN woocommerceLoader('search_products') 0 Produkte zurückgibt
THEN hat SearchResultsBlock null-Return (rendert nichts)
     UND EmptyStateBlock ist mit headline "Keine Ergebnisse gefunden" im DOM vorhanden
```

**AC7: Ungültige page-Param führt zu Redirect**
```
GIVEN /suche?q=shirt&page=abc wird aufgerufen
WHEN SearchPage die searchParams liest und parseInt("abc") NaN ergibt
THEN wird redirect("/suche?q=shirt") ausgeführt (Weiterleitung zu Seite 1)
```

**AC8: SearchBar Clear-Button löscht Query**
```
GIVEN SearchBarBlock rendert mit currentQuery="shirt"
WHEN der Clear-Button geklickt wird
THEN ruft router.push('/suche') auf (Query-Parameter entfernt)
```

**AC9: Header enthält Suchlink**
```
GIVEN Header-Komponente rendert
WHEN die Komponente gerendert wird
THEN ist ein <a>- oder <Link>-Element mit href="/suche" und aria-label="Suche öffnen" vorhanden
```

**AC10: generateMetadata setzt noindex**
```
GIVEN generateMetadata wird für /suche?q=shirt aufgerufen
WHEN Metadata generiert wird
THEN enthält robots: { index: false, follow: true }
     UND title enthält den Suchbegriff "shirt"
```

---

## Integration Contract (GATE 2 PFLICHT)

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-cross-page-infrastruktur | `PaginationBlock` | React Component | IMPORTED — `components/blocks/pagination-block.tsx` existiert |
| slice-01-cross-page-infrastruktur | `SortBarBlock` | React Component | IMPORTED — `components/blocks/sort-bar-block.tsx` existiert |
| slice-01-cross-page-infrastruktur | `EmptyStateBlock` | React Component | IMPORTED — `components/blocks/empty-state-block.tsx` existiert |
| slice-01-cross-page-infrastruktur | `SortBarData` Interface | TypeScript Type | EXTENDS — dieser Slice ergänzt `currentQuery?: string` (rückwärtskompatibel) |
| slice-03-kategorie-page-enhancements | `GET_PRODUCTS_PAGINATED` | GraphQL Query | IMPORTED — `lib/graphql/queries.ts` exportiert die Query mit `$search`-Variable |
| slice-03-kategorie-page-enhancements | `PaginatedProductsResult` | TypeScript Interface | IMPORTED — `lib/blocks/types.ts` exportiert das Interface |
| slice-03-kategorie-page-enhancements | `PaginationMeta` | TypeScript Interface | IMPORTED — `lib/blocks/types.ts` exportiert das Interface |
| slice-03-kategorie-page-enhancements | `buildOrderby` | Function | IMPORTED — `lib/blocks/data-loaders.ts` definiert die Funktion (intern oder exportiert) |
| slice-03-kategorie-page-enhancements | `WooCommerceLoaderParams` | TypeScript Interface | EXTENDS — `search?: string` bereits in Slice 3 hinzugefügt |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `SearchBarBlock` | React Component | slice-06-neue-pages (ggf. für Collections-Suche) | `BlockComponentProps<SearchBarData>` |
| `SearchResultsBlock` | React Component | slice-06 (ggf.) | `BlockComponentProps<PaginatedProductsResult>` |
| `search_products` loader branch | woocommerceLoader dispatch | zukünftige Slices | `(params: WooCommerceLoaderParams) => PaginatedProductsResult` |
| `SearchBarData` Interface | TypeScript Type | slice-06 (ggf.) | `{ placeholder: string, currentQuery?: string }` |
| `/suche` Route | Next.js Page | Header-Integration | GET `/suche?q=<term>&page=<n>&sort=<sort>` |
| `SortBarData` (erweitert) | TypeScript Interface | slice-01 (Modifikation) | `{ currentSort, baseUrl, currentQuery?: string }` |

### Breaking Changes

| Change | Affected | Migration |
|--------|----------|-----------|
| `SortBarData` um `currentQuery?: string` erweitert | `SortBarBlock` aus Slice 1 | `currentQuery` ist optional — kein Breaking Change. Slice-1-Implementierung muss ggf. angepasst werden um den Param bei URL-Konstruktion zu berücksichtigen. |

---

## Testfälle

### Test-Datei
`tests/slices/shop-completeness/slice-05-suchseite.test.ts`

<test_spec>
```typescript
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

// Mocks
vi.mock('next/navigation', () => ({
  useRouter: () => ({ push: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}))

vi.mock('@/lib/apollo/server-client', () => ({
  getClient: () => ({
    query: vi.fn().mockResolvedValue({ data: { products: { nodes: [] } } }),
  }),
}))

vi.mock('@/lib/blocks/page-config', () => ({
  loadPageConfig: vi.fn(() => ({ sections: [] })),
}))

// ---------------------------------------------------------------------------
// AC1: Suchseite erreichbar — SearchBar rendert role="search"
// ---------------------------------------------------------------------------
describe('SearchBarBlock', () => {
  it('rendert ein Element mit role="search" (AC1)', async () => {
    const { SearchBarBlock } = await import(
      '@/components/blocks/search-bar-block'
    )
    render(
      <SearchBarBlock
        data={{ placeholder: 'Was suchst du?', currentQuery: '' }}
      />
    )
    expect(screen.getByRole('search')).toBeDefined()
  })

  it('zeigt den currentQuery als Input-Wert (AC1 + AC8)', async () => {
    const { SearchBarBlock } = await import(
      '@/components/blocks/search-bar-block'
    )
    render(
      <SearchBarBlock
        data={{ placeholder: 'Was suchst du?', currentQuery: 'shirt' }}
      />
    )
    const input = screen.getByRole('searchbox')
    expect((input as HTMLInputElement).defaultValue).toBe('shirt')
  })

  it('Clear-Button ruft router.push(/suche) auf (AC8)', async () => {
    const { useRouter } = await import('next/navigation')
    const mockPush = vi.fn()
    vi.mocked(useRouter).mockReturnValue({ push: mockPush } as ReturnType<typeof useRouter>)

    const { SearchBarBlock } = await import(
      '@/components/blocks/search-bar-block'
    )
    render(
      <SearchBarBlock
        data={{ placeholder: 'Was suchst du?', currentQuery: 'shirt' }}
      />
    )
    const clearButton = screen.getByRole('button', { name: /suche löschen/i })
    await userEvent.click(clearButton)
    expect(mockPush).toHaveBeenCalledWith('/suche')
  })

  it('Submit mit weniger als 2 Zeichen löst keinen router.push aus', async () => {
    const { useRouter } = await import('next/navigation')
    const mockPush = vi.fn()
    vi.mocked(useRouter).mockReturnValue({ push: mockPush } as ReturnType<typeof useRouter>)

    const { SearchBarBlock } = await import(
      '@/components/blocks/search-bar-block'
    )
    render(
      <SearchBarBlock
        data={{ placeholder: 'Was suchst du?', currentQuery: '' }}
      />
    )
    const input = screen.getByRole('searchbox')
    await userEvent.type(input, 'a')
    await userEvent.keyboard('{Enter}')
    expect(mockPush).not.toHaveBeenCalled()
  })
})

// ---------------------------------------------------------------------------
// AC3: Mindestzeichenregel — data-loader
// ---------------------------------------------------------------------------
describe('woocommerceLoader search_products', () => {
  it('gibt leeres PaginatedProductsResult zurück wenn search < 2 Zeichen (AC3)', async () => {
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({
      query: 'search_products',
      search: '',
      page: '1',
      perPage: 24,
      sort: '',
    })
    expect(result.data.products.nodes).toHaveLength(0)
    expect(result.data.pagination.totalCount).toBe(0)
  })

  it('gibt leeres Ergebnis bei 1-Zeichen-Query zurück (AC3)', async () => {
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({
      query: 'search_products',
      search: 'a',
      page: '1',
      perPage: 24,
      sort: '',
    })
    expect(result.data.products.nodes).toHaveLength(0)
    expect(result.data.pagination.totalCount).toBe(0)
  })

  it('führt GET_PRODUCTS_PAGINATED aus wenn search >= 2 Zeichen (AC2)', async () => {
    const mockQuery = vi.fn().mockResolvedValue({
      data: {
        products: {
          nodes: [
            { id: '1', name: 'Shirt 1', slug: 'shirt-1', price: '24.99', image: null },
            { id: '2', name: 'Shirt 2', slug: 'shirt-2', price: '19.99', image: null },
          ],
        },
      },
    })
    const { getClient } = await import('@/lib/apollo/server-client')
    vi.mocked(getClient).mockReturnValue({ query: mockQuery } as ReturnType<typeof getClient>)

    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({
      query: 'search_products',
      search: 'shirt',
      page: '1',
      perPage: 24,
      sort: '',
    })

    expect(mockQuery).toHaveBeenCalledWith(
      expect.objectContaining({
        variables: expect.objectContaining({ search: 'shirt' }),
      })
    )
    expect(result.data.products.nodes).toHaveLength(2)
  })

  it('paginiert korrekt: Seite 2 mit perPage=1 aus 3 Produkten (AC4)', async () => {
    const allNodes = [
      { id: '1', slug: 'p1' },
      { id: '2', slug: 'p2' },
      { id: '3', slug: 'p3' },
    ]
    // perPage=1, page=2, fetchCount = 2*1+1 = 3 → gibt alle 3 zurück
    const mockQuery = vi.fn().mockResolvedValue({
      data: { products: { nodes: allNodes } },
    })
    const { getClient } = await import('@/lib/apollo/server-client')
    vi.mocked(getClient).mockReturnValue({ query: mockQuery } as ReturnType<typeof getClient>)

    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({
      query: 'search_products',
      search: 'shirt',
      page: '2',
      perPage: 1,
      sort: '',
    })

    // Seite 2 = slice([1, 2]) = nur Produkt id='2'
    expect(result.data.products.nodes).toHaveLength(1)
    expect(result.data.products.nodes[0].id).toBe('2')
    expect(result.data.pagination.currentPage).toBe(2)
    expect(result.data.pagination.hasPreviousPage).toBe(true)
  })

  it('wendet Sort-Mapping an: price_asc → PRICE ASC orderby (AC5)', async () => {
    const mockQuery = vi.fn().mockResolvedValue({
      data: { products: { nodes: [] } },
    })
    const { getClient } = await import('@/lib/apollo/server-client')
    vi.mocked(getClient).mockReturnValue({ query: mockQuery } as ReturnType<typeof getClient>)

    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    await woocommerceLoader({
      query: 'search_products',
      search: 'shirt',
      page: '1',
      perPage: 24,
      sort: 'price_asc',
    })

    expect(mockQuery).toHaveBeenCalledWith(
      expect.objectContaining({
        variables: expect.objectContaining({
          orderby: [{ field: 'PRICE', order: 'ASC' }],
        }),
      })
    )
  })
})

// ---------------------------------------------------------------------------
// AC6: SearchResultsBlock rendert null bei 0 Ergebnissen
// ---------------------------------------------------------------------------
describe('SearchResultsBlock', () => {
  it('rendert null wenn products.nodes leer ist (AC6)', async () => {
    const { SearchResultsBlock } = await import(
      '@/components/blocks/search-results-block'
    )
    const { container } = render(
      <SearchResultsBlock
        data={{
          products: { nodes: [] },
          pagination: {
            currentPage: 1,
            totalPages: 0,
            hasNextPage: false,
            hasPreviousPage: false,
            totalCount: 0,
          },
        }}
      />
    )
    expect(container.firstChild).toBeNull()
  })

  it('rendert Produkt-Grid wenn nodes vorhanden (AC2)', async () => {
    const { SearchResultsBlock } = await import(
      '@/components/blocks/search-results-block'
    )
    const mockNodes = [
      {
        id: '1',
        name: 'Shirt Blau',
        slug: 'shirt-blau',
        price: '24.99',
        regularPrice: '24.99',
        salePrice: null,
        image: { sourceUrl: '/img.jpg', altText: 'Shirt' },
        productCategories: { nodes: [] },
      },
    ]
    render(
      <SearchResultsBlock
        data={{
          products: { nodes: mockNodes },
          pagination: {
            currentPage: 1,
            totalPages: 1,
            hasNextPage: false,
            hasPreviousPage: false,
            totalCount: 1,
          },
        }}
      />
    )
    expect(screen.getByText('Shirt Blau')).toBeDefined()
  })
})

// ---------------------------------------------------------------------------
// AC7: Redirect bei ungültigem page-Param
// ---------------------------------------------------------------------------
describe('SearchPage (page.tsx)', () => {
  it('ruft redirect() auf wenn page-Param kein valider Integer ist (AC7)', async () => {
    const mockRedirect = vi.fn()
    vi.mock('next/navigation', () => ({
      redirect: mockRedirect,
      useRouter: () => ({ push: vi.fn() }),
      useSearchParams: () => new URLSearchParams(),
    }))

    const { default: SearchPage } = await import('@/app/suche/page')
    await SearchPage({
      searchParams: Promise.resolve({ q: 'shirt', page: 'abc', sort: '' }),
    })

    expect(mockRedirect).toHaveBeenCalledWith('/suche?q=shirt')
  })
})

// ---------------------------------------------------------------------------
// AC9: Header enthält Suchlink
// ---------------------------------------------------------------------------
describe('Header', () => {
  it('rendert einen Link zu /suche mit aria-label (AC9)', async () => {
    const { Header } = await import('@/components/layout/header')
    render(<Header />)
    const searchLink = screen.getByRole('link', { name: /suche öffnen/i })
    expect(searchLink).toBeDefined()
    expect(searchLink.getAttribute('href')).toBe('/suche')
  })
})

// ---------------------------------------------------------------------------
// AC10: generateMetadata setzt noindex und Suchbegriff in title
// ---------------------------------------------------------------------------
describe('SearchPage generateMetadata', () => {
  it('setzt robots noindex und enthält Suchbegriff im title (AC10)', async () => {
    const { generateMetadata } = await import('@/app/suche/page')
    const metadata = await generateMetadata({
      searchParams: Promise.resolve({ q: 'shirt' }),
    })
    expect(metadata.robots).toEqual({ index: false, follow: true })
    expect(String(metadata.title)).toContain('shirt')
  })

  it('setzt generischen title ohne Suchbegriff (AC10)', async () => {
    const { generateMetadata } = await import('@/app/suche/page')
    const metadata = await generateMetadata({
      searchParams: Promise.resolve({ q: '' }),
    })
    expect(String(metadata.title)).toContain('Suche')
    expect(String(metadata.title)).not.toContain('""')
  })
})
```
</test_spec>

---

## Code Examples (MANDATORY — GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind PFLICHT-Deliverables.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `SearchBarBlock` | UI — Client Component | YES | Exakt wie spezifiziert (role="search", Clear-Button, Enter-Handler) |
| `SearchResultsBlock` | UI — Server Component | YES | Rendert null bei 0 Ergebnissen |
| `search_products` Loader-Branch | Data Layer | YES | Min-2-Zeichen-Guard, Over-fetch, Slice |
| `app/suche/page.tsx` | Route | YES | generateMetadata mit noindex, Redirect-Guard |
| `search.yaml` | YAML Config | YES | Exakt wie in Abschnitt 8 spezifiziert |
| `SortBarData` Extension | Types | YES | `currentQuery?: string` optional |

### Code Example 1: `SearchBarBlock` (vereinfacht)

```typescript
// frontend/components/blocks/search-bar-block.tsx
'use client'

import { useRef } from 'react'
import { useRouter } from 'next/navigation'
import { Search, X } from 'lucide-react'
import type { SearchBarData } from '@/lib/blocks/types'
import type { BlockComponentProps } from '@/lib/blocks/types'

export function SearchBarBlock({ data }: BlockComponentProps<SearchBarData>) {
  const router = useRouter()
  const inputRef = useRef<HTMLInputElement>(null)

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    const value = inputRef.current?.value?.trim() ?? ''
    if (value.length < 2) return
    router.push(`/suche?q=${encodeURIComponent(value)}`)
  }

  function handleClear() {
    if (inputRef.current) inputRef.current.value = ''
    router.push('/suche')
  }

  const hasValue = Boolean(data.currentQuery)

  return (
    <form role="search" aria-label="Produktsuche" onSubmit={handleSubmit}
      className="flex gap-2 w-full max-w-2xl mx-auto">
      <div className="relative flex-1">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-text-secondary"
          aria-hidden="true" />
        <input
          ref={inputRef}
          type="search"
          name="q"
          aria-label="Suchbegriff eingeben"
          placeholder={data.placeholder}
          defaultValue={data.currentQuery}
          className="w-full pl-10 pr-10 py-3 border border-border rounded-card
                     text-text-primary bg-white focus-visible:ring-2
                     focus-visible:ring-primary focus-visible:outline-none"
        />
        {hasValue && (
          <button
            type="button"
            onClick={handleClear}
            aria-label="Suche löschen"
            className="absolute right-3 top-1/2 -translate-y-1/2 text-text-secondary
                       hover:text-text-primary touch-action-manipulation"
          >
            <X className="w-5 h-5" aria-hidden="true" />
          </button>
        )}
      </div>
      <button
        type="submit"
        className="px-6 py-3 bg-primary text-white rounded-card
                   hover:opacity-90 touch-action-manipulation
                   focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
      >
        Suchen
      </button>
    </form>
  )
}
```

### Code Example 2: `SearchResultsBlock` (vereinfacht)

```typescript
// frontend/components/blocks/search-results-block.tsx
import type { PaginatedProductsResult } from '@/lib/blocks/types'
import type { BlockComponentProps } from '@/lib/blocks/types'
import { ProductCard } from '@/components/product-card'

export function SearchResultsBlock({ data }: BlockComponentProps<PaginatedProductsResult>) {
  const nodes = data.products?.nodes ?? []

  if (nodes.length === 0) return null

  return (
    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
      {nodes.map((product) => (
        <ProductCard key={product.id} product={product} />
      ))}
    </div>
  )
}

// Reuse existing ProductGridBlockSkeleton for Suspense fallback
export { ProductGridBlockSkeleton as SearchResultsBlockSkeleton } from './product-grid-block'
```

### Code Example 3: `SortBarData` Extension (in types.ts)

```typescript
// frontend/lib/blocks/types.ts (Ergänzung zu Slice-1-Definition)
export interface SortBarData {
  currentSort: SortOption     // bestehend aus Slice 1
  baseUrl: string             // bestehend aus Slice 1
  currentQuery?: string       // NEU in Slice 5: Q-Param für Suchseite beibehalten
}
```

---

<!-- DELIVERABLES_START -->
## Deliverables (SCOPE SAFEGUARD)

### Neue Dateien (Frontend)

- [ ] `frontend/app/suche/page.tsx` — RSC Route `/suche`, liest `q`/`page`/`sort` searchParams, loadPageConfig('search'), generateMetadata mit noindex, Redirect-Guard für ungültige page
- [ ] `frontend/components/blocks/search-bar-block.tsx` — Client Component, role="search", Submit-Handler (min 2 Zeichen), Clear-Button, defaultValue (Hydration-sicher)
- [ ] `frontend/components/blocks/search-results-block.tsx` — Server Component, Product-Grid Darstellung, returns null bei 0 Ergebnissen, re-exportiert `SearchResultsBlockSkeleton`
- [ ] `frontend/themes/default/pages/search.yaml` — YAML-Konfiguration exakt wie in Abschnitt 8 spezifiziert (6 Sections: search-bar, product-count, sort-bar, search-results, pagination, empty-state)
- [ ] `tests/slices/shop-completeness/slice-05-suchseite.test.ts` — Vitest-Testdatei mit allen 10 ACs

### Modifizierte Dateien (Frontend)

- [ ] `frontend/lib/blocks/data-loaders.ts` — Branch `search_products` hinzufügen (Min-2-Zeichen-Guard, Over-fetch, Slice, buildOrderby wiederverwendet)
- [ ] `frontend/lib/blocks/registry.ts` — `search-bar` und `search-results` Block-Typen registrieren (Import + Eintrag in Registry-Map)
- [ ] `frontend/lib/blocks/types.ts` — `SearchBarData` Interface hinzufügen; `SortBarData` um `currentQuery?: string` erweitern; `WooCommerceLoaderParams.query` Union um `'search_products'` ergänzen (falls noch nicht durch Slice 3 geschehen)
- [ ] `frontend/components/layout/header.tsx` — Suchicon (`<Search />`) als `<Link href="/suche" aria-label="Suche öffnen">` in Navigation einfügen (neben Cart-Icon)

### YAML-Konfiguration

- [ ] `frontend/themes/default/pages/search.yaml` — NEU, Inhalt exakt wie in Abschnitt 8

### Tests

- [ ] `tests/slices/shop-completeness/slice-05-suchseite.test.ts` — NEU, alle ACs abgedeckt (AC1–AC10)

<!-- DELIVERABLES_END -->

---

## Definition of Done

| Kriterium | Prüfung |
|-----------|---------|
| Alle 10 ACs haben einen korrespondierenden Testfall | 1:1 Mapping AC → `it(...)` Block |
| `pnpm test tests/slices/shop-completeness/slice-05-suchseite.test.ts` läuft durch ohne Fehler | CI-grün |
| `/suche` Route antwortet mit HTTP 200 | `curl http://localhost:3000/suche` |
| `/suche?q=shirt` zeigt Produktergebnisse | Manuelle Verifikation im Browser |
| `/suche?q=xyz123unbekannt` zeigt EmptyStateBlock | Manuelle Verifikation |
| `/suche?q=a` zeigt EmptyStateBlock (min 2 Zeichen) | Manuelle Verifikation |
| Header-Suchicon navigiert zu `/suche` | Manuelle Verifikation |
| `pnpm build` ohne TypeScript-Fehler | CI-Build grün |
| Alle Tailwind-Klassen sind Theme-Tokens (kein hardcoded Farben) | Code Review |
| `search-bar` und `search-results` in `registry.ts` registriert | grep-Verifikation |
| `SortBarBlock` behält `?q=`-Param bei Sort-Wechsel auf Suchseite bei | Manuelle Verifikation |
