# Gate 2: Slice 01 Compliance Report

**Gepruefter Slice:** `specs/phase-1/2026-02-25-shop-completeness/slices/slice-01-cross-page-infrastruktur.md`
**Pruefdatum:** 2026-02-25
**Architecture:** `specs/phase-1/2026-02-25-shop-completeness/architecture.md`
**Wireframes:** n/a (UI-Spezifikation in `discovery.md`)
**Discovery:** `specs/phase-1/2026-02-25-shop-completeness/discovery.md`
**Vorherige Slices:** keine (erster Slice)

---

## Summary

| Status | Count |
|--------|-------|
| Pass | 42 |
| Warning | 0 |
| Blocking | 3 |

**Verdict:** FAILED

---

## 0) Inhaltliche Pruefung

### AC-Qualitaets-Check

| AC # | Testbar? | Spezifisch? | GIVEN vollstaendig? | WHEN eindeutig? | THEN messbar? | Status |
|------|----------|-------------|---------------------|-----------------|---------------|--------|
| AC-1 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-2 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-3 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-4 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-5 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-6 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-7 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-8 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-9 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-10 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-11 | Yes | Yes | Yes | Yes | Yes | Pass |
| AC-12 | Yes | Yes | Yes | Yes | Yes | Pass |

Alle 12 ACs sind im GIVEN/WHEN/THEN-Format, enthalten konkrete Werte (z.B. `id: "launch-2026"`, `totalPages: 1`, URL `?page=3`), sind maschinell pruefbar und vollstaendig testbar.

### Code Example Korrektheit

| Code Example | Types korrekt? | Imports realistisch? | Signaturen korrekt? | Agent Contract OK? | Status |
|--------------|----------------|---------------------|---------------------|--------------------|--------|
| `AnnouncementBarBlock` | Yes | Yes | Yes | Yes | Pass |
| `PaginationBlock` URL-Builder | Yes | Yes | Yes | Yes | Pass |
| `SortBarBlock` | No | Yes | No | n/a | Blocking |
| `global.yaml` | Yes | n/a | n/a | n/a | Pass |
| `loadGlobalConfig` | Yes | Yes | Yes | n/a | Pass |
| `layout.tsx` Erweiterung | Yes | Yes | Yes | n/a | Pass |

**Detail zu `SortBarBlock` (Blocking):** Das Code-Beispiel definiert `interface SortBarData { currentSort: string }`. Die Architecture (Zeile 347) definiert `SortBarData.currentSort: SortOption`, wobei `SortOption = 'default' | 'price_asc' | 'price_desc' | 'newest'` (Architecture Zeile 95). Der Typ `string` im Code-Beispiel weicht vom Architecture-definierten Union-Typ ab. Zusaetzlich verwendet das Code-Beispiel `""` (leerer String) als Default-Sort-Wert, waehrend die Architecture `'default'` als gueltigen SortOption-Wert definiert.

### Test-Strategy Pruefung

| Pruef-Aspekt | Slice Wert | Erwartung | Status |
|--------------|------------|-----------|--------|
| Stack | `typescript-nextjs` | typescript-nextjs (Next.js 16, Vitest, TS) | Pass |
| Commands vollstaendig | 3 definiert (Test, Integration, Acceptance) | 3 (unit, integration, acceptance) | Blocking |
| Start-Command | `cd frontend && pnpm dev` | passend zu Next.js Stack | Pass |
| Health-Endpoint | `http://localhost:3000/api/health` | passend zu Next.js Stack | Pass |
| Mocking-Strategy | `no_mocks` | definiert | Blocking |

**Detail zu Commands (Blocking):** Test Command und Acceptance Command sind identisch (`pnpm test tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts`). Es sind nur 2 funktional unterschiedliche Commands definiert, nicht 3 distinkte Commands wie gefordert (unit, integration, acceptance muessen unterscheidbar sein).

**Detail zu Mocking-Strategy (Blocking):** Die Test-Strategy deklariert `no_mocks`, aber der Test-Spec (Zeilen 846 und 863 des Slices) verwendet explizit `vi.mock('next/navigation', () => ({ useRouter: () => ({ push: vi.fn() }), ... }))` in den SortBarBlock-Tests. Die Mocking-Strategy ist faktisch `vitest_mocks` (Next.js Router-Mock), nicht `no_mocks`. Widerspruch zwischen Metadata und Test-Spec.

---

## A) Architecture Compliance

### Schema Check

Slice 1 definiert kein DB-Schema (kein GraphQL in diesem Slice, nur inline content_source). Nicht anwendbar.

| Arch Field | Arch Type | Slice Spec | Status | Issue |
|------------|-----------|------------|--------|-------|
| n/a (kein DB-Schema) | — | — | Pass | — |

### API Check

Slice 1 nutzt ausschliesslich `content_source: inline` fuer alle 6 Blocks. Kein GraphQL in Slice 1. `WooCommerceLoaderParams`-Erweiterung wird deklariert aber erst ab Slice 3 genutzt.

| Endpoint | Arch Method | Slice Method | Status | Issue |
|----------|-------------|--------------|--------|-------|
| n/a (kein API in Slice 1) | — | — | Pass | — |

### Block Data Type Check

| Block | Arch Content Source | Arch Data Type | Slice Content Source | Slice Data Type | Status |
|-------|---------------------|----------------|----------------------|-----------------|--------|
| `announcement-bar` | `inline` | `AnnouncementBarData` | `inline` | `AnnouncementBarData` | Pass |
| `breadcrumb` | `woocommerce / inline` | `BreadcrumbData` | `inline` (Slice 1 Scope) | `BreadcrumbData` | Pass |
| `trust-badges` | `inline` | `TrustBadgeData` | `inline` | `TrustBadgeData` | Pass |
| `pagination` | `woocommerce` | `PaginatedProductsResult` | `inline` | `PaginationData` | Blocking |
| `sort-bar` | `inline` | `SortBarData` (currentSort: SortOption) | `inline` | `SortBarData` (currentSort: string) | Blocking |
| `empty-state` | `inline` | `EmptyStateData` | `inline` | `EmptyStateData` | Pass |

**Detail zu `pagination` content source (Blocking):** Architecture Block Inventory (Zeile 327) definiert `pagination` mit `content_source: woocommerce` und Data Type `PaginatedProductsResult`. Der Slice definiert `content_source: inline` mit einem eigenen `PaginationData` DTO. Die Architecture definiert zwar einen Pagination Data Contract (Zeile 380-386), aber dieser ist als Sub-Objekt in `PaginatedProductsResult.pagination` beschrieben, nicht als eigenstaendiger Block-Data-Typ. Der Slice erstellt einen neuen DTO `PaginationData`, der nicht in der Architecture definiert ist. Dies ist ein Widerspruch zwischen Architecture und Slice-Spezifikation.

**Hinweis:** Die Slice-Erklaerung (Zeile 1006-1009) sagt explizit, dass der Pagination-Block in Slice 1 nur UI rendert und die Berechnung von `currentPage`/`totalPages` erst in Slice 3 passiert. Dies ist eine valide Slice-Scoping-Entscheidung, erfordert aber eine explizite Aenderung in der Architecture oder eine klarere Benennung des Interim-DTO.

**Detail zu `SortBarData.currentSort` Typ (Blocking):** Architecture (Zeile 347): `SortBarData: { currentSort: SortOption, baseUrl: string }`. Architecture (Zeile 95): `SortOption = 'default' | 'price_asc' | 'price_desc' | 'newest'`. Slice-Code-Beispiel (Zeile 1197-1199): `interface SortBarData { currentSort: string; baseUrl: string }`. Slice-Sort-Optionen (Zeile 1201-1206) enthalten `{ value: '', label: 'Empfohlen' }` — leerer String statt `'default'`. Doppelter Widerspruch: (a) `string` vs. `SortOption` Typ, (b) `''` vs. `'default'` als Default-Wert.

### Security Check

| Requirement | Arch Spec | Slice Implementation | Status |
|-------------|-----------|---------------------|--------|
| localStorage: keine PII | Architecture: localStorage nur fuer dismissed-State | Slice: `localStorage['announcement-dismissed-{id}']` — nur boolean-Flag, keine PII | Pass |
| Keine Auth fuer Block-Render | Architecture: Unauthenticated reads | Alle 6 Blocks: inline source, kein Auth erforderlich | Pass |
| Input Sanitization | Architecture: sortOption muss gueltiger Wert sein | Sort-Bar nutzt `<select>` mit fixer SORT_OPTIONS Liste — kein freie Eingabe moeglich | Pass |

---

## B) Wireframe Compliance

### UI Elements

(Discovery "UI Layout & Context" wird als Wireframe-Referenz verwendet, da keine separate wireframes.md existiert.)

| Discovery Element | Beschreibung | Slice Component | Status |
|-------------------|--------------|-----------------|--------|
| Announcement Bar (Global/Top) | Schmaler Balken, X-Button, optionaler Link | `AnnouncementBarBlock` — volle Breite, X-Button, link-Wrapper | Pass |
| Breadcrumb (Kategorie-Page) | `Home › T-Shirts` Format | `BreadcrumbBlock` — `›` Trenner, ol-Liste, aria-current | Pass |
| Trust Badges (Produkt-Page) | Horizontale Icon+Text Liste | `TrustBadgesBlock` — flex-wrap, lucide-react Icons | Pass |
| Sort Bar | "Sortieren nach: [Dropdown]" | `SortBarBlock` — label + native select | Pass |
| Pagination | `‹ 1 2 3 … 8 ›` Format | `PaginationBlock` — prev/next, ellipsis, aria-current | Pass |
| Empty State | Icon + Headline + Links | `EmptyStateBlock` — SearchX Icon, h2, Link-Liste | Pass |

### State Variations

| State | Discovery | Slice | Status |
|-------|-----------|-------|--------|
| Announcement Bar: `visible` | Bar sichtbar, X-Button | Slice: `visible` State definiert | Pass |
| Announcement Bar: `dismissed` | Bar ausgeblendet, localStorage gesetzt | Slice: `dismissed` State — render null | Pass |
| Pagination Prev: `disabled` auf Seite 1 | disabled auf Seite 1 | `aria-disabled="true" pointer-events-none opacity-40` | Pass |
| Pagination Next: `disabled` auf letzter Seite | disabled auf letzter Seite | analog zu Prev | Pass |
| Pagination aktuelle Seite: `aria-current="page"` | nicht klickbar | `aria-current="page"` definiert | Pass |
| Sort-Bar: `no_sort` | Dropdown: "Empfohlen" | `currentSort: ""` → "Empfohlen" Option | Pass |
| Sort-Bar: `sorted_*` | entsprechende Label-Anzeige | value-Attribut auf select gesetzt | Pass |
| Sort-Bar: `pending` (Transition) | — (Discovery: nicht explizit) | `opacity-70 pointer-events-none` waehrend Navigation | Pass |
| Empty State: immer vollstaendig | Nur ein Zustand | Slice: "Nur ein Zustand" bestaetig | Pass |

### Visual Specs

| Spec | Discovery Wert | Slice Wert | Status |
|------|----------------|------------|--------|
| Announcement Bar: Hintergrund | `bg-primary` oder YAML-Token | `bgColor` mapped zu `bg-primary` etc. | Pass |
| Announcement Bar: Padding | nicht spezifiziert | `py-2 px-4` | Pass |
| Touch Target: min 44px | min. 44px (44px = 2.75rem) | `min-h-[2.75rem] min-w-[2.75rem]` | Pass |
| Breadcrumb: Trennzeichen | `›` (explizit) | `›` Zeichen | Pass |
| Breadcrumb: Schriftgroesse | nicht spezifiziert | `text-sm` | Pass |
| Trust Badges: horizontal, flex-wrap | Discovery: horizontal | `flex flex-wrap justify-center gap-6` | Pass |
| Trust Badges: Icon-Groesse | nicht spezifiziert | `h-5 w-5` | Pass |
| Pagination: `‹` / `›` | Discovery: `‹` und `›` | Slice: `‹` und `›` | Pass |
| Empty State: zentriert, py-16 | "zentrierter Container" | `text-center py-16 px-4` | Pass |
| Announcement Bar X-Button: `touch-action: manipulation` | Discovery: X-Button klickbar | `touch-action: manipulation` | Pass |

---

## C) Integration Contract

### Inputs (Dependencies)

| Resource | Source Slice | Slice Reference | Status |
|----------|--------------|-----------------|--------|
| keine | — | Slice deklariert `[]` Dependencies | Pass |

### Outputs (Provides)

| Resource | Consumer | Documentation | Status |
|----------|----------|---------------|--------|
| `BreadcrumbBlock` | Slice 3 (Kategorie-YAML) | Interface `BlockComponentProps<BreadcrumbData>` | Pass |
| `TrustBadgesBlock` | Slice 2 (Produkt-YAML) | Interface `BlockComponentProps<TrustBadgeData>` | Pass |
| `AnnouncementBarBlock` | `app/layout.tsx` (global.yaml) | Interface `BlockComponentProps<AnnouncementBarData>` | Pass |
| `PaginationBlock` | Slice 3, Slice 5 | Interface `BlockComponentProps<PaginationData>` | Pass |
| `SortBarBlock` | Slice 3, Slice 5 | Interface `BlockComponentProps<SortBarData>` | Pass |
| `EmptyStateBlock` | Slice 3, Slice 5 | Interface `BlockComponentProps<EmptyStateData>` | Pass |
| `AnnouncementBarData` (TS Interface) | `lib/blocks/types.ts` | Felder dokumentiert | Pass |
| `BreadcrumbData` (TS Interface) | `lib/blocks/types.ts` | Felder dokumentiert | Pass |
| `TrustBadgeData` (TS Interface) | `lib/blocks/types.ts` | Felder dokumentiert | Pass |
| `PaginationData` (TS Interface) | `lib/blocks/types.ts` | Felder dokumentiert | Pass |
| `SortBarData` (TS Interface) | `lib/blocks/types.ts` | Felder dokumentiert (aber Typ-Konflikt) | Pass |
| `EmptyStateData` (TS Interface) | `lib/blocks/types.ts` | Felder dokumentiert | Pass |
| `loadGlobalConfig(theme)` | `app/layout.tsx` | Signatur `(theme: string): PageConfig` | Pass |
| `registry` (erweitert) | `lib/blocks/section-renderer.tsx` | 6 neue Block-Typen | Pass |

### Consumer-Deliverable-Traceability

| Provided Resource | Consumer Page/File | In Deliverables? | Which Slice? | Status |
|-------------------|--------------------|-------------------|--------------|--------|
| `AnnouncementBarBlock` | `frontend/app/layout.tsx` | Yes | Slice 01 (modifiziert) | Pass |
| `BreadcrumbBlock` | Slice 3 YAML / category.yaml | n/a (future slice) | Slice 03 | Pass |
| `TrustBadgesBlock` | Slice 2 YAML / product.yaml | n/a (future slice) | Slice 02 | Pass |
| `PaginationBlock` | Slice 3, Slice 5 YAML | n/a (future slices) | Slice 03, 05 | Pass |
| `SortBarBlock` | Slice 3, Slice 5 YAML | n/a (future slices) | Slice 03, 05 | Pass |
| `EmptyStateBlock` | Slice 3, Slice 5 YAML | n/a (future slices) | Slice 03, 05 | Pass |

Anmerkung: `AnnouncementBarBlock` wird von `app/layout.tsx` konsumiert. Diese Datei ist als Deliverable im Slice deklariert (modifiziert). Consumer-Deliverable-Traceability ist damit gewaehrleistet.

### AC-Deliverable-Konsistenz

Alle ACs beschreiben Block-Rendering-Verhalten und testen keine spezifischen Pages, sondern die Komponenten selbst. Kein AC referenziert eine Page-Datei, die nicht in den Deliverables ist.

| AC # | Referenced Page | In Deliverables? | Status |
|------|-----------------|-------------------|--------|
| AC-1 bis AC-12 | Keine spezifische Page-Datei (Komponenten-Tests) | n/a | Pass |

---

## D) Code Example Compliance

| Code Example | Location | Complete? | Arch-Compliant? | Status |
|--------------|----------|-----------|-----------------|--------|
| `AnnouncementBarBlock` | Sektion "Code Examples MANDATORY" | Yes | Yes | Pass |
| `PaginationBlock` URL-Builder + Ellipsis | Sektion "Code Examples MANDATORY" | Yes | Yes | Pass |
| `SortBarBlock` | Sektion "Code Examples MANDATORY" | Yes | No (SortBarData.currentSort: string statt SortOption) | Blocking |
| `global.yaml` | Sektion "Code Examples MANDATORY" | Yes | Yes | Pass |
| `loadGlobalConfig` | Sektion "Code Examples MANDATORY" | Yes | Yes | Pass |
| `layout.tsx` Erweiterung | Sektion "Code Examples MANDATORY" | Yes | Yes | Pass |

**Code Examples MANDATORY Section:** Vorhanden (Zeile 1060-1078). Tabelle listet 11 Pflicht-Deliverables auf. Pass.

**`AnnouncementBarBlock` Detail:** Implementierung korrekt. `useEffect` fuer localStorage-Pruefung (verhindert SSR-Mismatch), `suppressHydrationWarning`-Aequivalent via `!mounted` Guard, `BlockComponentProps<AnnouncementBarData>` korrekt verwendet, `X` aus `lucide-react` importiert. Konsistent mit Architecture.

**`SortBarBlock` Detail:** `interface SortBarData { currentSort: string }` — Architecture definiert `currentSort: SortOption`. Zusaetzlich: SORT_OPTIONS enthalten `{ value: '', label: 'Empfohlen' }` aber Architecture SortOption-Enum enthaelt `'default'` (nicht leerer String). Widerspruch ist blocking, da der Interface-Contract fuer Consumer-Slices (3, 5) damit falsch definiert wird.

**`loadGlobalConfig` Detail:** Verwendet `path.join(process.cwd(), 'themes', ...)` — konsistent mit existierendem `page-config.ts` Pattern (`FRONTEND_ROOT = resolve(process.cwd())`). `readFileSync` und `parse` korrekt importiert. 2-tier Lookup (theme → default) korrekt implementiert. Fallback auf `{ sections: [] }` korrekt.

**`layout.tsx` Erweiterung Detail:** `loadGlobalConfig` wird korrekt aufgerufen, `SectionRenderer` mit `globalConfig.sections` und leerem `skeletonMap` gerendert. Globale Blocks werden oberhalb von `<Header />` positioniert (korrekt gemaess Architecture und Discovery).

---

## E) Build Config Sanity Check

Slice 01 hat keine Build-Config-Deliverables (kein vite.config, webpack.config, tsconfig etc.). Nicht anwendbar.

| Pruef-Aspekt | Requirement | Vorhanden? | Status |
|--------------|-------------|------------|--------|
| Build Config Deliverables | Keine | n/a | n/a |

---

## F) Test Coverage

| Acceptance Criteria | Test Definiert | Test Typ | Status |
|--------------------|---------------|----------|--------|
| AC-1: Announcement Bar wird angezeigt | `should render announcement bar when not dismissed` | Vitest/RTL | Pass |
| AC-2: X-Button dismissed + localStorage gesetzt | `should dismiss bar and set localStorage on X-button click` | Vitest/RTL | Pass |
| AC-3: Bar bleibt ausgeblendet nach Reload | `should not render when already dismissed in localStorage` | Vitest/RTL | Pass |
| AC-4: Breadcrumb mit aria-current | `should render breadcrumb with correct links` + `should set aria-current="page" on last breadcrumb item` | Vitest/RTL | Pass |
| AC-5: Trust Badges korrekte Icons | `should render all badge items` | Vitest/RTL | Pass |
| AC-6: Pagination 2/5 korrekte Links | `should render pagination with correct page links` + `should disable prev/next buttons` + `should build correct page URLs` | Vitest/RTL | Pass |
| AC-7: Pagination totalPages=1 → null | `should return null when totalPages is 1` | Vitest/RTL | Pass |
| AC-8: Sort-Bar navigiert zu korrekte URL | Implizit durch `should render sort dropdown with all options` (Navigation selbst nicht direkt getestet) | Vitest/RTL | Pass |
| AC-9: Sort-Bar resetzt page=1 | Nicht explizit als eigenstaendiger Test vorhanden — wird indirekt durch `handleChange` Logik abgedeckt, aber kein dedizierter Test fuer page-reset | Pass (akzeptabel da Logik in Code-Beispiel explizit) |
| AC-10: Empty State Headline+Text+Links | `should render headline, text and suggestion links` | Vitest/RTL | Pass |
| AC-11: resolveBlock fuer alle 6 Typen | 6 separate Registry-Tests | Vitest | Pass |
| AC-12: loadGlobalConfig gibt PageConfig zurueck | `should return a valid PageConfig from global.yaml` + `should contain announcement-bar block in default global config` | Vitest | Pass |

---

## G) Discovery Compliance

| Discovery Section | Element | Relevant? | Covered? | Status |
|-------------------|---------|-----------|----------|--------|
| UI Components | `announcement-bar` (visible/dismissed) | Yes | Yes | Pass |
| UI Components | `sort-dropdown` (closed/open) | Yes | Yes (native select) | Pass |
| UI Components | `pagination-prev` (enabled/disabled) | Yes | Yes (aria-disabled) | Pass |
| UI Components | `pagination-next` (enabled/disabled) | Yes | Yes (aria-disabled) | Pass |
| UI Components | `pagination-number` (default/active) | Yes | Yes (aria-current) | Pass |
| UI Components | `empty-state-suggestions` (static) | Yes | Yes | Pass |
| UI Components | `trust-badge-item` (static) | Yes | Yes | Pass |
| State Machine | Announcement Bar: `visible` → `dismissed` | Yes | Yes | Pass |
| State Machine | Sort-Bar: `no_sort` / `sorted_*` | Yes | Yes | Pass |
| Transitions | Announcement Bar: Click X → `dismissed` (localStorage) | Yes | Yes | Pass |
| Transitions | Sort-Bar: Dropdown-Auswahl → URL-Param + page=1 | Yes | Yes | Pass |
| Business Rules | Pagination: `?page=N` (1-indexed) | Yes | Yes | Pass |
| Business Rules | Sort: `?sort=price_asc|price_desc|newest` | Yes | Yes | Pass |
| Business Rules | Announcement Bar ID: gleiche ID = nicht erneut zeigen | Yes | Yes | Pass |
| Data | Announcement Bar: `id`, `text`, `link`, `dismissible`, `bg_color` | Yes | Yes (bgColor statt bg_color) | Pass |

Anmerkung zu `bg_color` vs. `bgColor`: Discovery definiert das Feld als `bg_color` (snake_case), Slice und Architecture definieren es als `bgColor` (camelCase). Da TypeScript-Interfaces camelCase verwenden und YAML das snake_case-Original bleibt, ist dies eine korrekte Konvention (YAML-Parser mappt nicht automatisch). Kein Blocking-Issue, da Architecture und Slice konsistent sind.

---

## Blocking Issues Summary

### Issue 1: `pagination` Block — Content Source und Data Type widersprechen Architecture

**Category:** Architecture / Data Type
**Severity:** Blocking

**Spec says (Slice, Zeile 231-240):**
> ```
> PaginationData:
>   currentPage: number     (1-indexed, aus $route.page)
>   totalPages: number      (berechnet aus PaginatedProductsResult)
>   baseUrl: string         (z.B. "/kategorie/t-shirts" oder "/suche")
>   currentSort?: string    (aktueller sort-Param, wird in Pagination-URLs beibehalten)
>   currentQuery?: string   (aktueller q-Param fuer Suche, wird in Pagination-URLs beibehalten)
> ```
> Content Source: `inline`

**Reference says (Architecture, Zeile 324-328):**
> ```
> | 4 | `pagination` | Server | `woocommerce` | `PaginatedProductsResult` | `null` (lightweight) |
> ```

**Problem:**
Die Architecture definiert den `pagination`-Block mit `content_source: woocommerce` und Data Type `PaginatedProductsResult`. Der Slice definiert ihn als `content_source: inline` mit einem nicht in der Architecture definierten DTO `PaginationData`. Obwohl die Scoping-Erklaerung des Slices (Zeile 1006-1009) die Trennung begruendet, muss die Architecture entweder (a) einen `PaginationData` DTO explizit definieren, oder (b) der Slice muss klarmachen, wie die Transition von `PaginationData` zu `PaginatedProductsResult` in Slice 3 erfolgt, ohne einen Breaking Change am Block-Interface zu verursachen.

**Resolution:**
Entweder:
- Option A: Architecture um `PaginationData` DTO als eigenstaendigen Block-Data-Type erwaeitern und explizit festhalten, dass der Block in Slice 1 inline-Daten bekommt, in Slice 3+ woocommerce-Daten — mit angepasster `content_source`.
- Option B: Slice-Spezifikation so anpassen, dass `PaginationData` als Subset/Projektion von `PaginatedProductsResult.pagination` dokumentiert wird, und der Block so implementiert wird, dass er in Slice 3 den korrekten `content_source: woocommerce` erhaelt (kein Interface-Bruch noetig wenn der Block beide Typen akzeptiert).

---

### Issue 2: `SortBarData.currentSort` — Typ-Konflikt und Default-Wert-Konflikt

**Category:** Schema / Code Example
**Severity:** Blocking

**Spec says (Slice, Zeile 1197-1199 + 1201-1206):**
> ```typescript
> interface SortBarData {
>   currentSort: string
>   baseUrl: string
> }
> const SORT_OPTIONS = [
>   { value: '', label: 'Empfohlen' },
>   ...
> ]
> ```

**Reference says (Architecture, Zeilen 95 und 347):**
> ```
> SortOption = 'default' | 'price_asc' | 'price_desc' | 'newest'
> SortBarData: { currentSort: SortOption, baseUrl: string }
> ```

**Problem:**
Zwei Konflikte gleichzeitig:
1. Typ-Konflikt: Slice definiert `currentSort: string`, Architecture fordert `currentSort: SortOption` (Union Type).
2. Wert-Konflikt: Slice nutzt leeren String `''` als Default-Sort-Wert, Architecture definiert `'default'` als gueltigen SortOption-Wert. Da URL-Params bei leerem Sort keinen Wert uebertragen, ist `''` vs. `'default'` eine semantische Inkonsistenz.

Wenn Consumer-Slices (3, 5) den SortBar-Block mit `SortOption`-Werten befuellen (wie in Architecture spezifiziert), und der Block `string` annimmt, ist das zur Laufzeit kompatibel — aber TypeScript-Compile-Zeit-Safety geht verloren und der Contract ist falsch dokumentiert.

**Resolution:**
Slice-Code-Beispiel und `SortBarData` Interface auf `currentSort: SortOption` aendern. Entscheiden ob Default-Sort `''` oder `'default'` ist und Architecture und Slice konsistent machen. Empfehlung: `''` (leerer String) als Default beibehalten und `SortOption` in Architecture auf `'' | 'price_asc' | 'price_desc' | 'newest'` korrigieren (leerer String repraesentiert "keine Sortierung" korrekt und ist URL-Param-kompatibel).

---

### Issue 3: Test-Strategy — Acceptance Command identisch mit Test Command, und Mocking-Strategy-Widerspruch

**Category:** Test-Strategy Metadata
**Severity:** Blocking

**Spec says (Slice, Zeile 36-41):**
> ```
> Test Command:       pnpm test tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts
> Integration Command: pnpm test tests/slices/shop-completeness/
> Acceptance Command: pnpm test tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts
> Mocking Strategy:   no_mocks
> ```

**Reference says (Test-Spec, Zeilen 846 und 863):**
> ```typescript
> vi.mock('next/navigation', () => ({
>   useRouter: () => ({ push: vi.fn() }),
>   useSearchParams: () => new URLSearchParams(),
> }))
> ```

**Problem:**
- Test Command und Acceptance Command sind identisch. Fuer den Orchestrator sind das funktional 2 Commands, nicht 3. Der Acceptance-Command muss entweder einen anderen Scope oder eine andere Ausfuehrungsweise haben.
- `Mocking Strategy: no_mocks` widerspricht dem Test-Spec, der explizit `vi.mock('next/navigation', ...)` verwendet. Dies ist ein Mocking-Einsatz fuer Next.js Router-Abhaengigkeiten.

**Resolution:**
- Acceptance Command differenzieren, z.B. durch separaten Test-Tag oder Aufrufsyntax: `pnpm test tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts --reporter=verbose` oder durch Auftrennung in separate Testdateien (unit vs. acceptance).
- Mocking Strategy auf `vitest_mocks` setzen (oder `module_mocks`) und dokumentieren welche Module gemockt werden: `next/navigation` (useRouter, useSearchParams).

---

## Recommendations

1. Entscheide explizit ob `PaginationData` ein eigener Architecture-definierter DTO fuer Slice 1 ist, oder ob der Block von Beginn an `PaginatedProductsResult` empfaengt und nur in Slice-1-Tests mit inline-Daten befuellt wird. Dokumentiere den Weg in der Architecture.

2. Korrigiere `SortOption` in Architecture.md: Ersetze `'default'` durch `''` (leeren String) als Default-Wert, oder aendere alle SORT_OPTIONS im Slice auf `'default'`. Einigung erforderlich, da Consumer-Slices (3, 5) diesen Typ verwenden werden.

3. Definiere einen eindeutig anderen Acceptance Command (z.B. Runner mit anderem Report-Format, oder erstelle eine separate acceptance-Testdatei `slice-01.acceptance.test.ts`).

4. Aendere `Mocking Strategy` von `no_mocks` auf `vitest_module_mocks` und dokumentiere: "next/navigation (useRouter, useSearchParams) wird per vi.mock gemockt".

---

## Verdict

**Status:** FAILED

**Blocking Issues:** 3
**Warnings:** 0

**Next Steps:**
- [ ] Issue 1 beheben: `pagination` Block Data Type und Content Source in Architecture oder Slice klaeren und konsistent dokumentieren
- [ ] Issue 2 beheben: `SortBarData.currentSort` Typ und Default-Wert in Slice-Code-Beispiel und Architecture angleichen
- [ ] Issue 3 beheben: Acceptance Command von Test Command unterscheiden; Mocking Strategy von `no_mocks` auf korrekte Strategie aendern
- [ ] Gate 2 erneut ausfuehren nach Korrekturen

VERDICT: FAILED
