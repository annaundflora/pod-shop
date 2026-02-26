# Gate 2: Slice 03 Compliance Report

**Gepruefter Slice:** `specs/phase-1/2026-02-25-shop-completeness/slices/slice-03-kategorie-page-enhancements.md`
**Pruefdatum:** 2026-02-26
**Architecture:** `specs/phase-1/2026-02-25-shop-completeness/architecture.md`
**Wireframes:** `specs/phase-1/2026-02-25-shop-completeness/discovery.md` (UI Layout & Context Section)
**Vorherige Slices:** `slice-01-cross-page-infrastruktur.md`, `slice-02-produkt-page-enhancements.md`

---

## Summary

| Status | Count |
|--------|-------|
| PASS | 47 |
| WARNING | 0 |
| BLOCKING | 3 |

**Verdict:** FAILED

---

## 0) Inhaltliche Pruefung

### AC-Qualitaets-Check

| AC # | Testbar? | Spezifisch? | GIVEN vollstaendig? | WHEN eindeutig? | THEN messbar? | Status |
|------|----------|-------------|---------------------|-----------------|---------------|--------|
| AC-1 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-2 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-3 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-4 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-5 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-6 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-7 | Yes | Yes | Yes | Yes | Yes | PASS |
| AC-8 | Yes | Yes | Yes | Yes | Yes | PASS |

Alle 8 ACs sind im GIVEN/WHEN/THEN-Format verfasst, enthalten konkrete Werte (URL-Parameter, Seitenzahlen, aria-Attribute) und sind maschinell pruefbar. Keine vagen Formulierungen.

### Code Example Korrektheit

| Code Example | Types korrekt? | Imports realistisch? | Signaturen korrekt? | Agent Contract OK? | Status |
|--------------|----------------|---------------------|---------------------|--------------------|--------|
| `GET_PRODUCTS_PAGINATED` Query (Abschnitt 3) | Teilweise | Yes | No | No | BLOCKING |
| `GET_CATEGORY_META` Query (Abschnitt 11) | Yes | Yes | Yes | Yes | PASS |
| `buildOrderby()` Funktion (Abschnitt 4) | Yes | Yes | Yes | N/A | PASS |
| `products_by_category` Branch (Abschnitt 5) | Yes | Yes | Yes | Yes | PASS |
| `category_meta` Branch (Abschnitt 10) | Yes | Yes | Yes | Yes | PASS |
| `PaginatedProductsResult` DTO (Abschnitt 6) | Teilweise | Yes | N/A | No | BLOCKING |
| `PaginationMeta` DTO (Abschnitt 6) | Yes | Yes | N/A | Yes | PASS |
| `WooCommerceLoaderParams` Erweiterung (Abschnitt 7) | Teilweise | Yes | N/A | No | BLOCKING |
| `CategoryPage` page.tsx (Abschnitt 8) | Yes | Yes | Yes | Yes | PASS |
| `category.yaml` (Abschnitt 9) | Yes | Yes | N/A | Yes | PASS |

### Test-Strategy Pruefung

| Pruef-Aspekt | Slice Wert | Erwartung | Status |
|--------------|------------|-----------|--------|
| Stack | `typescript-nextjs` | `typescript-nextjs` (Next.js 16, Vitest, Tailwind v4) | PASS |
| Commands vollstaendig | 3 Commands vorhanden (Test, Integration, Acceptance) | 3 (unit, integration, acceptance) | PASS |
| Start-Command | `cd frontend && pnpm dev` | `cd frontend && pnpm dev` (Next.js-Projekt im `frontend/`-Verzeichnis) | PASS |
| Health-Endpoint | `http://localhost:3000/api/health` | Passend zu Next.js Stack auf Port 3000 | PASS |
| Mocking-Strategy | `mock_external` (Apollo Server-Client per `vi.mock()`, `next/navigation` per `vi.mock()`) | Definiert und korrekt beschrieben | PASS |

---

## A) Architecture Compliance

### Schema Check

Kein eigenes DB-Schema. Alle Daten aus WooCommerce. Architecture bestätigt: "Kein neues DB-Schema". Nicht anwendbar.

| Arch Field | Arch Type | Slice Spec | Status | Issue |
|------------|-----------|------------|--------|-------|
| `wp_comments` (reviews) | WooCommerce type:review | Nicht verwendet in Slice 3 (Slice 2) | PASS | N/A — Reviews are Slice 2 scope |
| `wp_terms` / `wp_term_taxonomy` | WooCommerce categories | Genutzt via `productCategory` GraphQL field | PASS | — |

### API Check

| Endpoint | Arch Method/Variables | Slice Method/Variables | Status | Issue |
|----------|-----------------------|------------------------|--------|-------|
| `GET_PRODUCTS_PAGINATED` | `first: Int!, categorySlug: String, search: String, orderby: [ProductsOrderbyInput]` | `$categorySlug: String, $first: Int!, $orderby: [ProductsOrderbyInput], $search: String` | PASS | Variable-Namen stimmen ueberein |
| `GET_PRODUCTS_PAGINATED` Response | `products { nodes { ...ProductCardFields } pageInfo { hasNextPage endCursor } }` | `products { nodes { ...ProductCardFields } }` + `productCategory { name count description slug }` | BLOCKING | Slice-Query fehlt `pageInfo { hasNextPage endCursor }` — siehe Issue 1 |
| `GET_CATEGORY_META` | `slug: ID!` | `$slug: ID!` — `productCategory(id: $slug, idType: SLUG)` | PASS | — |
| `GET_CATEGORY_META` Response | `productCategory { name description slug count image { sourceUrl altText } }` | `productCategory { name description slug count image { sourceUrl altText } }` | PASS | Vollstaendig korrekt |

### Security Check

| Requirement | Arch Spec | Slice Implementation | Status |
|-------------|-----------|---------------------|--------|
| Product queries (read) | Unauthenticated, RSC Server Client | RSC via `getClient()`, kein Session-Token | PASS |
| URL param validation | Invalid page -> redirect page 1; invalid sort -> ignore, use default | `parsePageParam` + `parseSortParam` mit korrekter Sanitierung, Redirect bei invaliden page-Param | PASS |
| Input sanitization (URL params) | URL params type check + allowed values — no injection possible | `validSorts.includes(sortParam)`, `parseInt()` — kein direktes SQL | PASS |
| Search query validation | Min 2 chars, max 100 chars | Nicht in Slice 3 (Slice 5 Scope) | PASS (N/A) |

---

## B) Wireframe Compliance

**Quelle:** `discovery.md` → "Screen: Kategorie-Page (erweitert)"

### UI Elements

| Wireframe Element | Annotation | Slice Component | Status |
|-------------------|------------|-----------------|--------|
| `breadcrumb` | "Startseite › T-Shirts [breadcrumb]" | `BreadcrumbBlock` aus Slice 1, `category_meta` Datenquelle | PASS |
| `page-heading` | "[page-heading]" | Bestehendes Block, erweitert mit page/sort params | PASS |
| `filter-chips` | "[Alle] [Herren] [Damen] [Kinder] [filter-chips]" | Bestehendes Block, unveraendert | PASS |
| `product-count` | "48 Produkte [product-count]" | Bestehendes Block, erweitert mit page/sort params | PASS |
| `sort-bar` | "Sortieren nach: [Preis: aufsteigend] [sort-bar]" | `SortBarBlock` aus Slice 1, `currentSort` + `baseUrl` via YAML | PASS |
| `product-grid` | "4 Spalten Desktop [product-grid]" | Bestehendes Block mit paginierten Daten | PASS |
| `pagination` | "< 1 [2] 3 ... 8 > [pagination]" | `PaginationBlock` aus Slice 1, `woocommerceLoader` Daten | PASS |
| `empty-state` | "Keine Produkte gefunden [empty-state]" | `EmptyStateBlock` aus Slice 1, YAML-konfiguriert | PASS |

### State Variations

| State | Wireframe | Slice | Status |
|-------|-----------|-------|--------|
| Normal (>0 Produkte, Seite 1) | Vollstaendige Ansicht mit allen Blocks | AC-1: 24 Produkte, Breadcrumb, Pagination, Prev disabled | PASS |
| Seite 2 | Seite 2 aktiv, Prev enabled | AC-2: Produkte 25-48, aria-current="page", Prev klickbar | PASS |
| Sortiert (`?sort=price_asc`) | Sort-Dropdown zeigt "Preis: aufsteigend" | AC-3: Produkte sortiert, Dropdown-Label korrekt | PASS |
| Sort-Wechsel auf Seite 2 | URL reset zu ?sort=newest (page=1) | AC-4: Navigation zu /kategorie/t-shirts?sort=newest | PASS |
| Empty State (0 Produkte) | EmptyStateBlock mit Links | AC-5: EmptyStateBlock sichtbar, product-grid unsichtbar | PASS |
| Ungueltige page-Param | Redirect zu Seite 1 | AC-6: Redirect zu /kategorie/t-shirts | PASS |
| Ungueltige sort-Param | Default-Sortierung, kein Fehler | AC-7: WooCommerce Default, kein Redirect | PASS |
| Sort-Param bei Seitenwechsel | Sort-Param in Pagination-Links erhalten | AC-8: alle Seitenlinks enthalten ?sort=price_desc | PASS |

### Visual Specs

| Spec | Wireframe / Discovery Value | Slice Value | Status |
|------|-----------------------------|-------------|--------|
| Grid Layout | 4 Spalten Desktop, 2 Mobile (Mobile-first) | `grid-cols-2 md:grid-cols-3 lg:grid-cols-4` (Abschnitt 8, skeletonMap) | PASS |
| Produkte pro Seite | 24 (aus discovery.md "Aktuelle Probleme: fix: first: 24") | `perPage: 24` (YAML und data-loader) | PASS |
| Pagination Format | "< 1 [2] 3 ... 8 >" | PaginationBlock Render-Regeln aus Slice 1 (Ellipsis, aria-current) | PASS |
| Touch targets | Min. 44px (CLAUDE.md) | `--min-touch-target: 2.75rem` aus Slice 1 Blocks | PASS |
| Nur Theme-Tokens | Alle Farben via Theme-Tokens | `bg-surface-secondary`, `rounded-card` (Abschnitt 8) | PASS |

---

## C) Integration Contract

### Inputs (Dependencies)

| Resource | Source Slice | Slice Reference | Status |
|----------|--------------|-----------------|--------|
| `BreadcrumbBlock` Component | slice-01-cross-page-infrastruktur | Integration Contract "Requires", Abschnitt 1 UI-Anforderungen | PASS |
| `SortBarBlock` Component | slice-01-cross-page-infrastruktur | Integration Contract "Requires", Abschnitt 2 UI-Anforderungen | PASS |
| `PaginationBlock` Component | slice-01-cross-page-infrastruktur | Integration Contract "Requires", Abschnitt 3 UI-Anforderungen | PASS |
| `EmptyStateBlock` Component | slice-01-cross-page-infrastruktur | Integration Contract "Requires", Abschnitt 4 UI-Anforderungen | PASS |
| `PaginationData` Type | slice-01-cross-page-infrastruktur | Integration Contract "Requires", Kompatibilitaets-Hinweis Abschnitt 3 | PASS |
| `SortOption` Type | slice-01-cross-page-infrastruktur | Integration Contract "Requires", WooCommerceLoaderParams Abschnitt 7 | PASS |
| `BreadcrumbData` Type | slice-01-cross-page-infrastruktur | Integration Contract "Requires" | PASS |
| `EmptyStateData` Type | slice-01-cross-page-infrastruktur | Integration Contract "Requires" | PASS |
| `SortBarData` Type | slice-01-cross-page-infrastruktur | Integration Contract "Requires" | PASS |

### Outputs (Provides)

| Resource | Consumer | Documentation | Status |
|----------|----------|---------------|--------|
| `GET_PRODUCTS_PAGINATED` Query | slice-05-suchseite | Integration Contract "Provides", Variablen dokumentiert | PASS |
| `GET_CATEGORY_META` Query | slice-06-neue-pages (`collection-header`) | Integration Contract "Provides", Variablen dokumentiert | PASS |
| `PaginatedProductsResult` DTO | slice-05-suchseite, slice-06-neue-pages | Integration Contract "Provides", Fields dokumentiert | PASS |
| `PaginationMeta` DTO | slice-05-suchseite, slice-06-neue-pages | Integration Contract "Provides", Fields dokumentiert | PASS |
| `products_by_category` (paginated) | slice-05-suchseite | Integration Contract "Provides" | PASS |
| `buildOrderby()` Funktion | slice-05-suchseite | Integration Contract "Provides", Signatur dokumentiert | PASS |

### Consumer-Deliverable-Traceability

Slice 3 stellt keine neuen Page-Files bereit (es erweitert bestehende Pages). Die Outputs (`GET_PRODUCTS_PAGINATED`, `PaginatedProductsResult` etc.) sind Query/Type-Artefakte die in `lib/`-Dateien leben — keine Mount-Point-Problematik.

| Provided Resource | Consumer Page/File | In Deliverables? | Which Slice? | Status |
|-------------------|--------------------|-------------------|--------------|--------|
| `GET_PRODUCTS_PAGINATED` | `lib/graphql/queries.ts` | Yes | Slice 03 Deliverable | PASS |
| `GET_CATEGORY_META` | `lib/graphql/queries.ts` | Yes | Slice 03 Deliverable | PASS |
| `PaginatedProductsResult` | `lib/blocks/types.ts` | Yes | Slice 03 Deliverable | PASS |
| `PaginationMeta` | `lib/blocks/types.ts` | Yes | Slice 03 Deliverable | PASS |

### AC-Deliverable-Konsistenz

| AC # | Referenced Page / File | In Deliverables? | Status |
|------|------------------------|-------------------|--------|
| AC-1 | `/kategorie/t-shirts` (via `app/kategorie/[slug]/page.tsx`) | Yes — `frontend/app/kategorie/[slug]/page.tsx` in Deliverables | PASS |
| AC-2 | `/kategorie/t-shirts?page=2` (via gleiche Page) | Yes | PASS |
| AC-3 | `/kategorie/t-shirts?sort=price_asc` | Yes | PASS |
| AC-4 | Sort-Dropdown in Kategorie-Page | Yes | PASS |
| AC-5 | `/kategorie/leere-kategorie` | Yes | PASS |
| AC-6 | `/kategorie/t-shirts?page=abc` | Yes | PASS |
| AC-7 | `/kategorie/t-shirts?sort=invalid` | Yes | PASS |
| AC-8 | PaginationBlock Seitenlinks | Yes | PASS |

---

## D) Code Example Compliance

| Code Example | Location | Complete? | Arch-Compliant? | Status |
|--------------|----------|-----------|-----------------|--------|
| `GET_PRODUCTS_PAGINATED` Query | Abschnitt 3 | Teilweise | No | BLOCKING (siehe Issue 1) |
| `GET_CATEGORY_META` Query | Abschnitt 11 | Yes | Yes | PASS |
| `buildOrderby()` Funktion | Abschnitt 4 | Yes | Yes | PASS |
| `products_by_category` Branch (paginated) | Abschnitt 5 | Yes | Yes | PASS |
| `category_meta` Branch | Abschnitt 10 | Yes | Yes | PASS |
| `PaginatedProductsResult` DTO | Abschnitt 6 | Teilweise | No | BLOCKING (siehe Issue 2) |
| `PaginationMeta` DTO | Abschnitt 6 | Yes | Yes | PASS |
| `WooCommerceLoaderParams` Erweiterung | Abschnitt 7 | Teilweise | No | BLOCKING (siehe Issue 3) |
| `CategoryPage` page.tsx Erweiterung | Abschnitt 8 | Yes | Yes | PASS |
| `category.yaml` Erweiterung | Abschnitt 9 | Yes | Yes | PASS |

---

## E) Build Config Sanity Check

N/A — Slice 03 hat keine Build-Config-Deliverables (kein vite.config, webpack.config, tsconfig etc.).

---

## F) Test Coverage

| Acceptance Criteria | Test Defined | Test Type | Status |
|--------------------|--------------|-----------|--------|
| AC-1: 24 Produkte Seite 1, Breadcrumb, Pagination | Yes — `paginate(allNodes, 1, 24, 50)` in "Over-fetch + Slice Logic" | Unit | PASS |
| AC-2: Produkte 25-48 Seite 2, aria-current | Yes — `paginate(allNodes, 2, 24, 60)` + hasPreviousPage check | Unit | PASS |
| AC-3: Sort price_asc, Dropdown-Label | Yes — `buildOrderby('price_asc')` + `parseSortParam` Tests | Unit | PASS |
| AC-4: Sort-Wechsel setzt page auf 1 | Yes — `buildPageUrl` Test mit sort-Param | Unit | PASS |
| AC-5: Empty-State bei 0 Produkten | Yes — `shouldShowEmptyState([])` Test | Unit | PASS |
| AC-6: Redirect bei ungueltigem page-Param | Yes — `parsePageParam('abc')` -> 1 Test | Unit | PASS |
| AC-7: Ungueltige sort ignoriert | Yes — `parseSortParam('invalid')` -> '' Test | Unit | PASS |
| AC-8: Sort-Param in Pagination-Links | Yes — `buildPageUrl('/kategorie/t-shirts', 2, 'price_asc')` Test | Unit | PASS |

Alle 8 ACs haben korrespondierende Unit-Tests in der Test-Spec. Tests sind in `<test_spec>` Block vollstaendig als ausfuehrbarer Vitest-Code definiert.

---

## G) Discovery Compliance

| Discovery Section | Element | Relevant? | Covered? | Status |
|-------------------|---------|-----------|----------|--------|
| UI Components | `sort-dropdown` (`closed`, `open`) | Yes | Yes — SortBarBlock aus Slice 1, states beschrieben in Abschnitt 2 | PASS |
| UI Components | `pagination-prev` (`enabled`, `disabled`) | Yes | Yes — PaginationBlock, Prev disabled auf Seite 1 (AC-1) | PASS |
| UI Components | `pagination-next` (`enabled`, `disabled`) | Yes | Yes — PaginationBlock Render-Regeln aus Slice 1 | PASS |
| UI Components | `pagination-number` (`default`, `active`) | Yes | Yes — `aria-current="page"` auf aktiver Seite (AC-2) | PASS |
| UI Components | `empty-state-suggestions` | Yes | Yes — YAML-konfigurierte Links in EmptyStateBlock (AC-5) | PASS |
| State Machine | Sort-Bar: `no_sort` -> `sorted_*` | Yes | Yes — Abschnitt 2 "Zustaende", alle 4 States | PASS |
| State Machine | Sort-Bar Transitions | Yes | Yes — URL-Param Update via `router.push`, page-Reset | PASS |
| Transitions | `no_sort` → Dropdown-Auswahl → `sorted_*` | Yes | Yes — AC-3, SortBarBlock-Verhalten Abschnitt 2 | PASS |
| Transitions | `sorted_*` → andere Auswahl → Seite 1 Reset | Yes | Yes — AC-4 | PASS |
| Business Rules | Pagination `?page=N` 1-indexed, ungueltig -> Seite 1 | Yes | Yes — AC-6, `parsePageParam()` | PASS |
| Business Rules | Sort `?sort=price_asc\|price_desc\|newest` | Yes | Yes — AC-3, AC-7, `parseSortParam()` | PASS |
| Business Rules | Empty State bei 0 Produkten | Yes | Yes — AC-5, `shouldShowEmptyState()` | PASS |
| Data | `page` URL-Param: Integer >= 1 | Yes | Yes | PASS |
| Data | `sort` URL-Param: `price_asc\|price_desc\|newest` | Yes | Yes | PASS |

---

## Blocking Issues Summary

### Issue 1: `GET_PRODUCTS_PAGINATED` Query fehlt `pageInfo` Response-Felder

**Category:** API / Code Example
**Severity:** BLOCKING

**Spec says (Slice 3, Abschnitt 3):**
```graphql
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
```

**Reference says (architecture.md, New GraphQL Queries):**
> `GET_PRODUCTS_PAGINATED` Response Fields: `products { nodes { ...ProductCardFields } pageInfo { hasNextPage endCursor } }`

**Problem:**
Die Query-Definition im Slice-Code-Beispiel (Abschnitt 3) enthält kein `pageInfo { hasNextPage endCursor }` im Response-Body. Die Architecture definiert diese Felder explizit als Teil der Response. Ohne `pageInfo` ist der Query nicht architecture-konform und koennte bei zukuenftiger Nutzung (Slice 5: Suchseite) Breaking Changes verursachen, wenn `endCursor` fuer Cursor-basierte Navigation benoetigt wird. Das Code-Beispiel ist ein PFLICHT-Deliverable (Code Examples MANDATORY Section).

Zusaetzlich enthaelt das Slice-Query-Beispiel `productCategory(id: $categorySlug, idType: SLUG)` — dieses Feld fehlt in den Architecture Response Fields fuer `GET_PRODUCTS_PAGINATED`. Dort ist `GET_CATEGORY_META` das fuer category-Metadaten vorgesehene Query.

**Resolution:**
`GET_PRODUCTS_PAGINATED` Query-Definition in Abschnitt 3 um `pageInfo { hasNextPage endCursor }` ergaenzen:
```graphql
products(first: $first, where: { ... }) {
  nodes {
    ...ProductCardFields
  }
  pageInfo {
    hasNextPage
    endCursor
  }
}
```
Das eingebettete `productCategory`-Feld kann im Query bleiben (es ist ein pragmatisches Combined-Query fuer den Category-Page-Use-Case), muss aber in der Code-Example-Tabelle als "Combined Query" dokumentiert werden.

---

### Issue 2: `PaginatedProductsResult` DTO weicht von Architecture-Definition ab

**Category:** Schema / Code Example
**Severity:** BLOCKING

**Spec says (Slice 3, Abschnitt 6):**
```typescript
export interface PaginatedProductsResult {
  products: { nodes: ProductCardData[] }
  productCategory?: { name: string; description: string; slug: string; count: number } | null
  pagination: PaginationMeta
}
```

**Reference says (architecture.md, Data Transfer Objects):**
> `PaginatedProductsResult` Fields: `products: ProductCardData[], currentPage: number, totalPages: number, hasNextPage: boolean, totalCount: number`

**Problem:**
Die Architecture definiert `PaginatedProductsResult` mit `products: ProductCardData[]` (flaches Array) und `currentPage`, `totalPages`, `hasNextPage`, `totalCount` als direkte Top-Level-Felder. Die Slice-Definition strukturiert es als `products: { nodes: ProductCardData[] }` (verschachteltes Objekt) mit einem separaten `pagination: PaginationMeta` Sub-Objekt.

Zusaetzlich fuegt der Slice ein `productCategory?` Feld hinzu, das in der Architecture-DTO-Definition nicht vorhanden ist.

Die Divergenz ist nicht trivial: `PaginatedProductsResult` wird als Schnittstelle zu Slice 5 (Suchseite) und Slice 6 (Collections) im Integration Contract dokumentiert. Wenn Slice 5 die Architecture-Definition implementiert und Slice 3 die Slice-Definition ausliefert, entsteht ein Typ-Konflikt.

**Resolution:**
Entscheidung treffen und dokumentieren: Entweder
- (A) `PaginatedProductsResult` exakt wie Architecture definieren (`products: ProductCardData[]`, direkte Pagination-Felder) — dann muss `products_by_category` Branch in Abschnitt 5 entsprechend angepasst werden
- (B) Die Slice-Definition (`products: { nodes: ProductCardData[] }`, `pagination: PaginationMeta`) als verbindlich erklaeren und die Architecture als Tippfehler korrigieren

Da alle anderen Block-Components (product-grid, pagination) bereits auf `data.products.nodes` zugreifen (WooGraphQL-Konvention), ist Option (B) praxisnaher. In diesem Fall muss die Architecture-Inkonsistenz explizit als Architecture-Korrektur im Slice dokumentiert werden und das `productCategory?`-Feld begruendet werden (notwendig fuer `totalPages` via `category.count`).

---

### Issue 3: `WooCommerceLoaderParams` — `page` Typ-Inkonsistenz (Architecture vs. Slice)

**Category:** Schema / Code Example
**Severity:** BLOCKING

**Spec says (Slice 3, Abschnitt 7):**
```typescript
export interface WooCommerceLoaderParams {
  ...
  page?: number        // neu: 1-indexed Seitennummer
  perPage?: number     // neu: Produkte pro Seite (default: 24)
  sort?: string        // neu: SortOption-String aus URL-Param
  search?: string      // neu: Suchbegriff (für Slice 5)
}
```

**Reference says (architecture.md, Migration Map):**
> `WooCommerceLoaderParams` Extended with: `page?: string, perPage?: number, sort?: string, search?: string, source?: string, productSlug?: string, customIds?: string`

**Problem:**
Die Architecture definiert `page?: string` (String), der Slice-Code definiert `page?: number` (Number). Diese Inkonsistenz ist nicht trivial:

1. In der YAML-Konfiguration (`category.yaml`, Abschnitt 9) wird `page: $route.page` eingetragen — `$route.page` ist ein String (aus `searchParams`).
2. Das `page.tsx` in Abschnitt 8 uebergibt `page: String(page)` (also explizit String-Konvertierung) an `loadPageConfig`.
3. Der `products_by_category` Branch in Abschnitt 5 macht `const page = Number(params.page ?? 1)` — das erfordert `params.page` als `string | undefined`, nicht `number | undefined`.

Die Kombination zeigt: Im Datenfluss wird `page` als String durch YAML und `loadPageConfig` weitergegeben und erst im data-loader zu einer Number konvertiert. Der Typ `page?: number` im Interface stimmt nicht mit dem tatsaechlichen Verwendungsmuster ueberein und wird beim Implementierungs-Agent zu Typ-Fehlern fuehren.

Zudem fehlen im Slice-Interface die von der Architecture geforderten Felder `source?: string`, `productSlug?: string`, und `customIds?: string` (diese werden in Slice 2 benoetigt, sind aber Teil des gemeinsamen Interface).

**Resolution:**
`WooCommerceLoaderParams` in Abschnitt 7 korrigieren:
```typescript
export interface WooCommerceLoaderParams {
  ...
  page?: string        // String aus YAML/$route.page; data-loader konvertiert zu Number
  perPage?: number     // Bleibt number (direkt aus YAML als Zahl)
  sort?: string
  search?: string
  source?: string      // fuer product_recommendations (Slice 2)
  productSlug?: string // fuer product_recommendations (Slice 2)
  customIds?: string   // fuer product_recommendations (Slice 2)
}
```

---

## Recommendations

1. `GET_PRODUCTS_PAGINATED` Query-Code-Beispiel (Abschnitt 3) um `pageInfo { hasNextPage endCursor }` ergaenzen, um Architecture-Konformitaet herzustellen.

2. `PaginatedProductsResult` DTO-Divergenz klaeren: Entweder Architecture-Korrektur dokumentieren (empfohlen: `{ nodes: ProductCardData[] }` Pattern beibehalten, da WooGraphQL-konform) oder Interface auf Architecture-Definition anpassen. In jedem Fall muss die Entscheidung explizit im Slice-Dokument begruendet sein.

3. `WooCommerceLoaderParams.page` Typ von `number` auf `string` korrigieren, passend zum YAML-Param-Datenfluss. Fehlende Felder (`source?`, `productSlug?`, `customIds?`) aus Architecture ergaenzen.

4. Optional (kein Blocking): In Abschnitt 3 erwaehnen, dass `productCategory` im `GET_PRODUCTS_PAGINATED` Query ein Combined-Query-Ansatz ist (nicht in Architecture-Definition, aber pragmatisch fuer Category-Page). Vermeidet spaetere Verwirrung bei Slice 5, das die Query ohne `productCategory` wiederverwendet.

---

## Verdict

**Status:** FAILED

**Blocking Issues:** 3
**Warnings:** 0

**BLOCKING_ISSUES:**
- BLOCKING-1: `GET_PRODUCTS_PAGINATED` Query fehlt `pageInfo { hasNextPage endCursor }` Response-Felder (Architecture-Abweichung in PFLICHT-Deliverable)
- BLOCKING-2: `PaginatedProductsResult` DTO-Struktur weicht von Architecture-Definition ab (`products: { nodes: [] }` vs. `products: ProductCardData[]`) — Typ-Konflikt fuer Consumer-Slices 5 und 6
- BLOCKING-3: `WooCommerceLoaderParams.page` ist als `number` definiert, muss laut Architecture und Datenfluss `string` sein — fuehrt zu Typ-Fehler bei Implementierung

**Next Steps:**
- [ ] Blocking Issues 1–3 in `slice-03-kategorie-page-enhancements.md` beheben
- [ ] Re-run Gate 2 Compliance Check nach Fixes
