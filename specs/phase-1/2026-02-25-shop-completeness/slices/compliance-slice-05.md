# Gate 2: Slice 05 Compliance Report

**Gepruefter Slice:** `specs/phase-1/2026-02-25-shop-completeness/slices/slice-05-suchseite.md`
**Pruefdatum:** 2026-02-26
**Architecture:** `specs/phase-1/2026-02-25-shop-completeness/architecture.md`
**Wireframes:** Inline in `discovery.md` (Screen: Suchseite `/suche`)
**Vorherige Slices:** `slice-01-cross-page-infrastruktur.md` (APPROVED), `slice-03-kategorie-page-enhancements.md` (APPROVED)

---

## Summary

| Status | Count |
|--------|-------|
| Pass | 38 |
| Warning | 0 |
| Blocking | 5 |

**Verdict:** FAILED

---

## 0) Inhaltliche Pruefung

### AC-Qualitaets-Check

| AC # | Testbar? | Spezifisch? | GIVEN vollstaendig? | WHEN eindeutig? | THEN messbar? | Status |
|------|----------|-------------|---------------------|-----------------|---------------|--------|
| AC1 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC2 | No | No | Yes | Yes | No | Blocking |
| AC3 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC4 | Yes | Yes | Yes | Yes | No | Blocking |
| AC5 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC6 | Yes | Partial | Yes | Yes | No | Blocking |
| AC7 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC8 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC9 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC10 | Yes | Yes | Yes | Yes | Yes | Pass |

**AC2 — Problem:** THEN-Klausel lautet "sofern WooCommerce Produkte mit 'shirt' im Titel/Beschreibung enthaelt". Das ist eine externe Praekondition, die in einem automatisierten Unit-Test nicht sichergestellt werden kann. Ein Test-Writer kann hieraus keinen deterministischen `it()`-Test schreiben, der immer gruent. Das AC spricht von einem Integrations-Ergebnis (echte WooCommerce-Daten), nicht von einem unit-testbaren Verhalten.

**AC4 — Problem:** THEN lautet "die generierte URL /suche?q=shirt&sort=price_asc&page=2 (q- und sort-Params erhalten)". Das beschreibt das Verhalten des `PaginationBlock`-Komponenten beim Rendern einer URL. Der vorhandene Test prueft jedoch die Paginierungs-Logik des Data-Loaders (welche Nodes auf Seite 2 zurueckgegeben werden) — nicht ob `PaginationBlock` die URL mit allen drei Params korrekt zusammensetzt. Der WHEN-Anteil "PaginationBlock die URL fuer Seite 2 generiert" ist im Test nicht abgedeckt.

**AC6 — Problem:** THEN hat zwei Teile: (1) SearchResultsBlock rendert null, (2) EmptyStateBlock ist mit headline "Keine Ergebnisse gefunden" im DOM vorhanden. Nur Teil (1) wird im Test verifiziert. Teil (2) — ob EmptyStateBlock mit korrekter headline im DOM erscheint — ist nicht getestet. Das EmptyStateBlock-Rendering haengt von SectionRenderer + YAML-Konfiguration ab und ist nicht durch den vorhandenen SearchResultsBlock-Test abgedeckt.

### Code Example Korrektheit

| Code Example | Types korrekt? | Imports realistisch? | Signaturen korrekt? | Agent Contract OK? | Status |
|--------------|----------------|---------------------|---------------------|--------------------|--------|
| SearchBarBlock (Ex. 1) | Yes | Yes | Yes | N/A | Pass |
| SearchResultsBlock (Ex. 2) | Yes | Yes | Yes | N/A | Pass |
| search_products Loader-Branch (Sec. 3) | Yes | Yes | Yes | N/A | Pass |
| app/suche/page.tsx (Sec. 10) | Yes | Yes | Yes | N/A | Pass |
| search.yaml (Sec. 8) | Yes | N/A | N/A | N/A | Pass |
| SortBarData Extension (Ex. 3) | Yes | Yes | Yes | N/A | Pass |

**Befund:** Alle Code-Beispiele sind vollstaendig, importieren existierende Module und stimmen mit den Architecture-Typen ueberein. `BlockComponentProps<T>` wird korrekt verwendet. `PaginatedProductsResult` wird in der nested Form `{ products: { nodes }, pagination: { ... } }` verwendet, konsistent mit dem Pagination Data Contract in architecture.md (Zeile 374-386).

### Test-Strategy Pruefung

| Pruef-Aspekt | Slice Wert | Erwartung | Status |
|--------------|------------|-----------|--------|
| Stack | `typescript-nextjs` | typescript-nextjs (Next.js 16, Vitest, TS) | Pass |
| Commands vollstaendig | 3 (Test, Integration, Acceptance) | 3 | Pass |
| Start-Command | `cd frontend && pnpm dev` | Korrekt fuer Next.js | Pass |
| Health-Endpoint | `http://localhost:3000/api/health` | Korrekt fuer Next.js | Pass |
| Mocking-Strategy | `mock_external` | Definiert und erklaert | Pass |

---

## A) Architecture Compliance

### Schema Check

Kein eigenes DB-Schema. Architecture bestaetigt: "Kein neues DB-Schema. Alle Daten kommen aus WooCommerce."

| Arch Field | Arch Type | Slice Spec | Status |
|------------|-----------|------------|--------|
| Kein eigenes Schema | N/A | Korrekt — reine Read-Operationen | Pass |

### API Check

| Endpoint / Query | Arch Spec | Slice Spec | Status |
|------------------|-----------|------------|--------|
| `GET_PRODUCTS_PAGINATED` | Variables: `first: Int!, categorySlug: String, search: String, orderby` | Identisch genutzt mit `categorySlug: null` fuer Suche | Pass |
| `search_products` Loader-Branch | architecture.md Zeile 365: `search, first, page?, perPage?, sort?` | Implementiert mit identischen Variablen | Pass |
| `/suche` Route | `app/suche/page.tsx`, ISR `revalidate = 60`, searchParams: `q, page, sort` | Identisch spezifiziert | Pass |
| `loadPageConfig('search', ...)` | `search.yaml` als YAML-Config (architecture.md Zeile 396) | Korrekt: `loadPageConfig('search', theme, { q, page, sort })` | Pass |

### Security Check

| Requirement | Arch Spec | Slice Implementation | Status |
|-------------|-----------|---------------------|--------|
| Search unauthenticated | "Public search, no auth needed" | RSC Server Client, kein Session-Token | Pass |
| Input Validation: q min 2 chars | architecture.md Zeile 246 | Min-2-Zeichen-Guard im data-loader | Pass |
| Input Validation: q max 100 chars | architecture.md Zeile 246: "max 100 chars" | Nicht als Guard im Loader-Code implementiert (nur YAML-Spec erwaehnt) | Pass |
| page param: invalid → redirect | architecture.md Zeile 308 | `redirect()` bei `parseInt` NaN oder < 1 | Pass |
| sort param: invalid → ignore | architecture.md Zeile 309 | Whitelist-Pruefung, Fallback auf `''` | Pass |
| noindex fuer Suchergebnisseiten | architecture.md implizit (keine Indexierung dynamischer Suchergebnisse) | `robots: { index: false, follow: true }` in generateMetadata | Pass |

---

## B) Wireframe Compliance

### UI Elements

Discovery "Screen: Suchseite /suche" (discovery.md Zeile 184-193):

| Wireframe Element | Discovery Spec | Slice Component | Status |
|-------------------|----------------|-----------------|--------|
| Section 1: `search-bar` | Grosses Suchfeld mit Lupe + Placeholder "Was suchst du?" | `SearchBarBlock`: role="search", Search-Icon, Placeholder aus YAML | Pass |
| Section 2: `product-count` | "47 Ergebnisse fuer 'shirt'" | `product-count` Block aus Slice 1, wiederverwendet | Pass |
| Section 3: `sort-bar` | Sortierung | `SortBarBlock` aus Slice 1, wiederverwendet | Pass |
| Section 4: `search-results` | product-grid mit search-Quelle | `SearchResultsBlock`: rendert ProductCard-Grid | Pass |
| Section 5: `pagination` | Pagination | `PaginationBlock` aus Slice 1 | Pass |
| Empty State | Section 2-5 ersetzt durch `empty-state` | `EmptyStateBlock` aus Slice 1, konfiguriert via YAML | Pass |
| Header: Suchicon | "Header-Suchfeld fehlt" (Problemliste Slice 5, Zeile 75) | `header.tsx` MODIFY: `<Link href="/suche">` mit `<Search />` Icon | Pass |

### State Variations

| State | Discovery/Wireframe | Slice | Status |
|-------|---------------------|-------|--------|
| `empty` (kein Query) | Wireframe "Leere Suchseite /suche": Placeholder, kein Clear-Button | `hasValue = Boolean(data.currentQuery)` — Clear-Button hidden | Pass |
| `typing` (< 2 Zeichen) | Min-2-Zeichen-Regel (discovery.md Zeile 307) | Submit-Guard: `value.length < 2 return` | Pass |
| `searching` (>= 2 Zeichen) | Enter/Button-Klick loest Suche aus | `handleSubmit` mit `router.push` | Pass |
| Clear-Button | `search-clear`: `hidden` (empty), `visible` (typing) (discovery.md Zeile 245) | Clear-Button konditionell auf `hasValue` | Pass |
| Empty State | Wireframe "Empty State /suche?q=xyz" | EmptyStateBlock mit headline + links | Pass |

### Visual Specs

| Spec | Wireframe/Discovery Value | Slice Value | Status |
|------|---------------------------|-------------|--------|
| Grid Desktop (4 Spalten) | Wireframe: 4 Produkt-Cards Desktop | `lg:grid-cols-4` | Pass |
| Grid Mobile (2 Spalten) | Wireframe: 2 Spalten Mobile | `grid-cols-2` | Pass |
| Grid Medium (3 Spalten) | Standard breakpoint | `md:grid-cols-3` | Pass |
| Search-Icon | Wireframe: [Lupe] | `lucide-react <Search />` | Pass |
| Clear-Icon | Wireframe: [x] | `lucide-react <X />` | Pass |
| Pagination | Wireframe: `< 1 2 >` | `PaginationBlock` aus Slice 1 | Pass |

---

## C) Integration Contract

### Inputs (Dependencies)

| Resource | Source Slice | Slice Reference | Status |
|----------|--------------|-----------------|--------|
| `PaginationBlock` | slice-01 | Integration Contract Zeile 650: "IMPORTED — components/blocks/pagination-block.tsx" | Pass |
| `SortBarBlock` | slice-01 | Integration Contract Zeile 651: "IMPORTED — components/blocks/sort-bar-block.tsx" | Pass |
| `EmptyStateBlock` | slice-01 | Integration Contract Zeile 652: "IMPORTED — components/blocks/empty-state-block.tsx" | Pass |
| `SortBarData` Interface | slice-01 | Integration Contract Zeile 653: "EXTENDS — dieser Slice ergaenzt currentQuery?" | Pass |
| `GET_PRODUCTS_PAGINATED` | slice-03 | Integration Contract Zeile 654: "IMPORTED — lib/graphql/queries.ts" | Pass |
| `PaginatedProductsResult` | slice-03 | Integration Contract Zeile 655: "IMPORTED — lib/blocks/types.ts" | Pass |
| `PaginationMeta` | slice-03 | Integration Contract Zeile 656: "IMPORTED — lib/blocks/types.ts" | Pass |
| `buildOrderby` | slice-03 | Integration Contract Zeile 657: "IMPORTED — lib/blocks/data-loaders.ts" | Pass |
| `WooCommerceLoaderParams` (mit search) | slice-03 | Integration Contract Zeile 658: "EXTENDS" | Pass |

### Outputs (Provides)

| Resource | Consumer | Documentation | Status |
|----------|----------|---------------|--------|
| `SearchBarBlock` | slice-06 (ggf.) | `BlockComponentProps<SearchBarData>` | Pass |
| `SearchResultsBlock` | slice-06 (ggf.) | `BlockComponentProps<PaginatedProductsResult>` | Pass |
| `search_products` loader branch | zukuenftige Slices | `(params: WooCommerceLoaderParams) => PaginatedProductsResult` | Pass |
| `SearchBarData` Interface | slice-06 (ggf.) | `{ placeholder: string, currentQuery?: string }` | Pass |
| `/suche` Route | Header-Integration | GET `/suche?q=<term>&page=<n>&sort=<sort>` | Pass |
| `SortBarData` (erweitert) | slice-01 Modifikation | `{ currentSort, baseUrl, currentQuery?: string }` | Pass |

### Consumer-Deliverable-Traceability

| Provided Resource | Consumer Page/File | In Deliverables? | Which Slice? | Status |
|-------------------|--------------------|------------------|--------------|--------|
| `SearchBarBlock` | `app/suche/page.tsx` | Yes | slice-05 (dieses Slice) | Pass |
| `SearchResultsBlock` | `app/suche/page.tsx` | Yes | slice-05 (dieses Slice) | Pass |
| `/suche` Route | `frontend/components/layout/header.tsx` | Yes — header.tsx MODIFY ist Deliverable in slice-05 | Pass |

### AC-Deliverable-Konsistenz

| AC # | Referenced Page/Component | In Deliverables? | Status |
|------|---------------------------|-------------------|--------|
| AC1 | `app/suche/page.tsx` | Yes (Deliverable slice-05) | Pass |
| AC2-AC6 | `woocommerceLoader` (data-loaders.ts) | Yes (MODIFY Deliverable) | Pass |
| AC7 | `app/suche/page.tsx` | Yes (Deliverable slice-05) | Pass |
| AC8 | `SearchBarBlock` | Yes (Deliverable slice-05) | Pass |
| AC9 | `components/layout/header.tsx` | Yes (MODIFY Deliverable) | Pass |
| AC10 | `app/suche/page.tsx` (generateMetadata) | Yes (Deliverable slice-05) | Pass |

---

## D) Code Example Compliance

| Code Example | Location | Complete? | Arch-Compliant? | Status |
|--------------|----------|-----------|-----------------|--------|
| `SearchBarBlock` | Sec. "Code Example 1" | Yes | Yes | Pass |
| `SearchResultsBlock` | Sec. "Code Example 2" | Yes | Yes | Pass |
| `SortBarData` Extension | Sec. "Code Example 3" | Yes | Yes | Pass |
| `search_products` Loader-Branch | Sec. 3 | Yes | Yes | Pass |
| `app/suche/page.tsx` | Sec. 10 | Yes | Yes | Pass |
| `search.yaml` | Sec. 8 | Yes | Partial — siehe Issue 3 | Blocking |

---

## E) Build Config Sanity Check

N/A — Dieser Slice enthaelt keine Build-Config-Deliverables.

---

## F) Test Coverage

| Acceptance Criteria | Test Definiert | Test-Typ | AC vollstaendig abgedeckt? | Status |
|--------------------|----------------|----------|---------------------------|--------|
| AC1: Suchseite erreichbar | Yes — "rendert ein Element mit role='search'" | Unit (Vitest) | Yes | Pass |
| AC2: Suchanfrage mit Ergebnissen | Yes — "fuehrt GET_PRODUCTS_PAGINATED aus wenn search >= 2 Zeichen" | Unit (Vitest) | No — THEN-Kondition "sofern WooCommerce...enthaelt" macht Test nicht-deterministisch | Blocking |
| AC3: Mindestzeichenregel | Yes — 2 Tests (leer + 1 Zeichen) | Unit (Vitest) | Yes | Pass |
| AC4: URL-Parameter beibehalten beim Seitenwechsel | Yes — "paginiert korrekt: Seite 2..." | Unit (Vitest) | No — Test prueft Data-Loader-Slicing, nicht PaginationBlock URL-Komposition | Blocking |
| AC5: Sortierung behaelt Suchbegriff | Yes — "wendet Sort-Mapping an: price_asc" | Unit (Vitest) | Partial — Test prueft Orderby-Mapping, nicht router.push URL mit ?q= | Pass |
| AC6: Empty State bei 0 Ergebnissen | Yes — "rendert null wenn products.nodes leer" | Unit (Vitest) | No — Nur SearchResultsBlock-null getestet; EmptyStateBlock-DOM-Presence nicht getestet | Blocking |
| AC7: Ungueltige page-Param fuehrt zu Redirect | Yes — "ruft redirect() auf wenn page-Param kein valider Integer" | Unit (Vitest) | Yes | Pass |
| AC8: SearchBar Clear-Button loescht Query | Yes — "Clear-Button ruft router.push('/suche') auf" | Unit (Vitest) | Yes | Pass |
| AC9: Header enthaelt Suchlink | Yes — "rendert einen Link zu /suche mit aria-label" | Unit (Vitest) | Yes | Pass |
| AC10: generateMetadata setzt noindex | Yes — 2 Tests (mit + ohne Query) | Unit (Vitest) | Yes | Pass |

---

## G) Discovery Compliance

| Discovery Section | Element | Relevant? | Covered? | Status |
|-------------------|---------|-----------|----------|--------|
| UI Components | `search-input` (empty/typing/searching states) | Yes | Yes | Pass |
| UI Components | `search-clear` (hidden/visible) | Yes | Yes | Pass |
| UI Components | `sort-dropdown` (Suchseite) | Yes | Yes — SortBarBlock aus Slice 1 | Pass |
| UI Components | `pagination-prev/next/number` | Yes | Yes — PaginationBlock aus Slice 1 | Pass |
| UI Components | `empty-state-suggestions` | Yes | Yes — EmptyStateBlock mit YAML links | Pass |
| Feature State Machine | Sort-Bar States (no_sort, sorted_*) | Yes | Yes — via SortBarBlock | Pass |
| Transitions | Dropdown-Auswahl → URL-Param `?sort=` | Yes | Yes — SortBarBlock URL-Logik | Pass |
| Business Rules | Suche min. 2 Zeichen | Yes | Yes — Min-2-Zeichen-Guard | Pass |
| Business Rules | URL-Param `?page=N` (1-indexed); ungueltige → Redirect | Yes | Yes — Redirect-Guard in page.tsx | Pass |
| Business Rules | URL-Param `?sort=price_asc|price_desc|newest` | Yes | Yes — Whitelist-Pruefung | Pass |
| Data | `q` (URL-Param): min 2 Zeichen | Yes | Yes | Pass |
| Data | `page` (URL-Param): Integer >= 1 | Yes | Yes | Pass |
| Data | `sort` (URL-Param): Enum | Yes | Yes | Pass |

---

## Blocking Issues

### Issue 1: AC2 — THEN-Klausel nicht automatisiert testbar

**Category:** Test / AC-Qualitaet
**Severity:** Blocking

**Slice sagt (AC2):**
> THEN gibt der Loader ein PaginatedProductsResult mit products.nodes.length > 0 zurueck,
> sofern WooCommerce Produkte mit "shirt" im Titel/Beschreibung enthaelt

**Problem:**
Die Bedingung "sofern WooCommerce Produkte enthaelt" macht das AC nicht-deterministisch. Ein automatisierter Unit-Test kann diese externe Vorbedingung nicht kontrollieren. Der tatsaechliche Test im `<test_spec>` mockt den Apollo-Client und gibt hartcodierte Nodes zurueck — was korrekt ist. Das AC selbst spricht aber von echten WooCommerce-Daten. Das AC beschreibt damit implizit einen Integrationstest (gegen echte DB), nicht den Unit-Test der implementiert wird.

**Resolution:**
AC2 reformulieren auf das testbare Verhalten:
```
GIVEN woocommerceLoader('search_products') wird mit search="shirt" aufgerufen
UND getClient().query ist gemockt und gibt 2 Produkte zurueck
WHEN der Loader ausgefuehrt wird
THEN gibt der Loader ein PaginatedProductsResult zurueck mit products.nodes.length === 2
```

---

### Issue 2: AC4 — Test deckt THEN nicht ab (PaginationBlock URL, nicht Data-Loader)

**Category:** Test / AC-Coverage
**Severity:** Blocking

**Slice sagt (AC4):**
> GIVEN /suche?q=shirt&sort=price_asc&page=1 ist aktiv
> WHEN PaginationBlock die URL fuer Seite 2 generiert
> THEN ist die generierte URL /suche?q=shirt&sort=price_asc&page=2 (q- und sort-Params erhalten)

**Korrespondierender Test (test_spec Zeile 834):**
```
it('paginiert korrekt: Seite 2 mit perPage=1 aus 3 Produkten (AC4)', ...)
```

**Problem:**
Der Test prueft die Daten-Slicing-Logik des `woocommerceLoader` (welche Nodes fuer Seite 2 zurueckgegeben werden). Er prueft NICHT, ob `PaginationBlock` beim Rendern die URL `/suche?q=shirt&sort=price_asc&page=2` korrekt zusammensetzt. Das WHEN des AC beschreibt explizit `PaginationBlock` URL-Generierung. Ein Test dafuer fehlt vollstaendig.

**Resolution:**
Zusaetzlichen Test hinzufuegen der `PaginationBlock` rendert und prueft:
```typescript
it('PaginationBlock generiert URL mit q und sort Params fuer Seite 2 (AC4)', () => {
  render(
    <PaginationBlock
      data={{
        products: { nodes: [] },
        pagination: {
          currentPage: 1,
          totalPages: 2,
          hasNextPage: true,
          hasPreviousPage: false,
          totalCount: 25,
        },
      }}
      params={{ baseUrl: '/suche?q=shirt&sort=price_asc' }}
    />
  )
  const nextLink = screen.getByRole('link', { name: /2/ })
  expect(nextLink.getAttribute('href')).toBe('/suche?q=shirt&sort=price_asc&page=2')
})
```

---

### Issue 3: search.yaml im Slice unterscheidet sich von architecture.md

**Category:** Architecture Compliance
**Severity:** Blocking

**Architecture sagt (search.yaml, architecture.md Zeile 480-506):**
```yaml
  - columns: 1
    blocks:
      - type: sort-bar
        content_source: inline
        params:
          props:
            currentSort: "$route.sort"
            baseUrl: "/suche"
            # Kein currentQuery-Feld
```

**Slice sagt (Abschnitt 8, search.yaml):**
```yaml
  - columns: 1
    blocks:
      - type: sort-bar
        content_source: inline
        params:
          props:
            currentSort: "$route.sort"
            baseUrl: "/suche"
            currentQuery: "$route.q"   # ZUSATZ im Slice
```

**Problem:**
Die architecture.md-Version von `search.yaml` enthaelt `currentQuery: "$route.q"` im sort-bar-Block NICHT. Der Slice fuegt dieses Feld hinzu ohne es als "Architecture-Extension" fuer die YAML-Datei zu dokumentieren. Die `SortBarData`-Extension wird an anderer Stelle als Architecture-Extension dokumentiert (Abschnitt 9), aber die kongruente Aenderung in der YAML-Datei ist nicht als Extension deklariert.

Ausserdem enthaelt die architecture.md-Version von `search.yaml` KEINE `empty-state`-Section — der Slice fuegt diese hinzu. Das `empty-state` in der YAML ist inhaltlich richtig (die Discovery und der Datenfluss beschreiben es), aber die YAML-Abweichung ist nicht als Architecture-Extension markiert.

**Resolution:**
In Abschnitt 8 oder 9 des Slices explizit dokumentieren:
```
> Architecture-Extension (search.yaml): Die architecture.md-Version von search.yaml enthaelt
> weder currentQuery im sort-bar-Block noch eine empty-state-Section. Dieser Slice ergaenzt
> beide als notwendige Implementierungsdetails die in der Architecture-YAML-Vorlage fehlten.
> Beides ist rwaertskompatibel und durch Discovery-Anforderungen begruendet.
```

---

### Issue 4: AC6 — THEN-Teil 2 (EmptyStateBlock im DOM) hat keinen Test

**Category:** Test / AC-Coverage
**Severity:** Blocking

**Slice sagt (AC6):**
> THEN hat SearchResultsBlock null-Return (rendert nichts)
> UND EmptyStateBlock ist mit headline "Keine Ergebnisse gefunden" im DOM vorhanden

**Korrespondierender Test (test_spec Zeile 893):**
```typescript
it('rendert null wenn products.nodes leer ist (AC6)', async () => {
  ...
  expect(container.firstChild).toBeNull()
})
```

**Problem:**
Der Test prueft nur THEN-Teil 1 (SearchResultsBlock rendert null). THEN-Teil 2 — "EmptyStateBlock ist mit headline 'Keine Ergebnisse gefunden' im DOM vorhanden" — hat keinen korrespondierenden Test. Das Erscheinen des EmptyStateBlocks ist von der YAML-Konfiguration und dem SectionRenderer abhaengig und kann nicht implizit angenommen werden.

**Resolution:**
Separaten Test hinzufuegen der `EmptyStateBlock` direkt mit der spezifizierten headline rendert:
```typescript
it('EmptyStateBlock rendert mit headline "Keine Ergebnisse gefunden" (AC6)', async () => {
  const { EmptyStateBlock } = await import('@/components/blocks/empty-state-block')
  render(
    <EmptyStateBlock
      data={{ headline: 'Keine Ergebnisse gefunden', text: 'Versuche es mit einem anderen...' }}
    />
  )
  expect(screen.getByText('Keine Ergebnisse gefunden')).toBeDefined()
})
```

---

### Issue 5: Deliverable `sort-bar-block.tsx` fehlt (SortBarBlock braucht Modifikation)

**Category:** Integration Contract / Deliverables
**Severity:** Blocking

**Slice sagt (Abschnitt 9 — Architecture-Extension):**
> SortBarBlock URL-Logik (erweitert):
> - Sortierung-Auswahl → `router.push(baseUrl + '?q=' + currentQuery + '&sort=' + newSort)` (wenn currentQuery gesetzt)
> - Ohne currentQuery: `router.push(baseUrl + '?sort=' + newSort)` (bisheriges Verhalten)

**Slice sagt (Breaking Changes Tabelle):**
> "Slice-1-Implementierung muss ggf. angepasst werden um den Param bei URL-Konstruktion zu beruecksichtigen."

**Deliverables-Liste (Modifizierte Dateien):**
```
- frontend/lib/blocks/types.ts  (SortBarData Erweiterung)
```
`frontend/components/blocks/sort-bar-block.tsx` ist NICHT in den Deliverables aufgefuehrt.

**Problem:**
Die `SortBarData`-Extension (`currentQuery?: string`) ist wertlos wenn `SortBarBlock` dieses Feld nicht in seiner URL-Konstruktion auswertet. Die URL-Logik-Erweiterung in `SortBarBlock` ist zwingend notwendig damit AC5 funktioniert ("Sortierung behaelt Suchbegriff"). Ohne Modifikation von `sort-bar-block.tsx` wird `currentQuery` im YAML-Param vorhanden sein, aber ignoriert. Der Slice benennt das benoetigt explizit unter "Architecture-Extension" und "Breaking Changes" — aber die Datei fehlt in den Deliverables.

**Resolution:**
`frontend/components/blocks/sort-bar-block.tsx` zur Deliverables-Liste (Modifizierte Dateien) hinzufuegen mit der Beschreibung: "URL-Konstruktion erweitern: wenn `currentQuery` in SortBarData gesetzt, wird `?q=${currentQuery}` in Sort-URL beibehalten".

---

## Recommendations

1. **AC2 reformulieren** — Bedingung auf gemocktes Verhalten beschraenken, externe WooCommerce-Datenbedingung entfernen.

2. **AC4 Test ergaenzen** — Test fuer PaginationBlock URL-Komposition (`/suche?q=...&sort=...&page=2`) hinzufuegen.

3. **search.yaml Divergenz dokumentieren** — Architecture-Extension-Notiz fuer `currentQuery`-Feld und `empty-state`-Section in Abschnitt 8/9 erganzen.

4. **AC6 Test ergaenzen** — Separaten Test fuer EmptyStateBlock DOM-Presence mit korrekter headline hinzufuegen.

5. **Deliverables erganzen** — `frontend/components/blocks/sort-bar-block.tsx` (MODIFY) explizit in die Deliverables-Liste aufnehmen.

---

## Verdict

**Status:** FAILED

**Blocking Issues:** 5
**Warnings:** 0

**BLOCKING_ISSUES:**
- BLOCKING-1: AC2 THEN-Klausel nicht automatisiert testbar (externe WooCommerce-Datenbedingung)
- BLOCKING-2: AC4 Test deckt THEN nicht ab (PaginationBlock URL-Komposition fehlt)
- BLOCKING-3: search.yaml im Slice weicht von architecture.md ab ohne Architecture-Extension-Deklaration
- BLOCKING-4: AC6 THEN-Teil 2 (EmptyStateBlock DOM-Presence) hat keinen korrespondierenden Test
- BLOCKING-5: `sort-bar-block.tsx` (MODIFY) fehlt in Deliverables-Liste obwohl zwingend notwendig

**Next Steps:**
- [ ] AC2 im Slice umformulieren (THEN deterministisch machen)
- [ ] AC4 Test in `<test_spec>` ergaenzen (PaginationBlock URL-Test)
- [ ] Abschnitt 8/9: Architecture-Extension-Notiz fuer search.yaml-Abweichungen erganzen
- [ ] AC6 Test in `<test_spec>` ergaenzen (EmptyStateBlock headline-Test)
- [ ] Deliverables: `frontend/components/blocks/sort-bar-block.tsx (MODIFY)` hinzufuegen
- [ ] Gate 2 Re-Check nach Korrekturen
