# Slice 1: Cross-Page Infrastruktur implementieren

> **Slice 1 von 6** für `Shop Completeness — Blocks & Pages`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | — |
> | **Nächster:** | `slice-02-produkt-enhancements.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-01-cross-page-infrastruktur` |
| **Test** | `pnpm test tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `[]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` — Vitest Unit/Component Tests (kein Playwright)
- **Dependencies**: Kein vorheriger Slice erforderlich

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` (next ^16.1.6, vitest ^3.0.0, typescript ^5.7.0, @tailwindcss/postcss ^4.0.0).

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts` |
| **Integration Command** | `pnpm test tests/slices/shop-completeness/` |
| **Acceptance Command** | `pnpm test tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts --reporter=verbose` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `mock_external` |

**Erklaerung:**
- **Stack**: typescript-nextjs (Next.js 16 App Router, Vitest, Tailwind CSS v4)
- **Test Command**: Einzelner Slice-Testlauf via Vitest (Standard-Reporter)
- **Acceptance Command**: Identische Testdatei, aber mit `--reporter=verbose` fuer vollstaendige AC-Ausgabe (lesbar als Acceptance-Protokoll durch den Orchestrator)
- **Mocking Strategy**: `mock_external` — `next/navigation` (`useRouter`, `useSearchParams`) wird per `vi.mock()` gemockt in den SortBarBlock-Tests. Alle anderen 5 Blocks nutzen inline content_source ohne externe Calls.

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Cross-Page Infrastruktur | Ready | `slice-01-cross-page-infrastruktur.md` |
| 2 | Produkt-Page Enhancements | Pending | `slice-02-produkt-enhancements.md` |
| 3 | Kategorie-Page Enhancements | Pending | `slice-03-kategorie-enhancements.md` |
| 4 | Homepage Enhancements | Pending | `slice-04-homepage-enhancements.md` |
| 5 | Suchseite | Pending | `slice-05-suchseite.md` |
| 6 | Neue Pages | Pending | `slice-06-neue-pages.md` |

---

## Kontext & Ziel

Dieser Slice implementiert 6 wiederverwendbare Block-Typen, die auf mehreren Pages eingesetzt werden. Ohne diese Infrastruktur-Blöcke können die nachfolgenden Slices (2–6) nicht vollständig implementiert werden.

**Problem:**
1. Keine Pagination — Kategorie-Pages zeigen immer nur die ersten 24 Produkte (kein Scale)
2. Keine Sortierung — User können keine Produkte nach Preis/Neuheit sortieren
3. Kein Announcement Bar — Promotions/Versandkonditionen nicht kommunizierbar
4. Keine Breadcrumb-Navigation — User wissen nicht wo sie sich im Shop befinden
5. Keine Trust Badges — fehlende Kaufsicherheit-Signale auf Produkt- und Kategorieseiten
6. Kein Empty State — bei 0 Produkten bricht die UI

**Zielbild:** 6 voll funktionsfähige, YAML-konfigurierbare Block-Typen im Block-System registriert, die Slice 2–6 als Basis nutzen können.

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → "New Block Components", "Global Blocks", "Architecture Layers"

```
Block-System Pattern (bestehend, wird erweitert):
  YAML Config → loadPageConfig() → resolveParams($route.*) → SectionRenderer
    → resolveBlock(type) → Component aus registry.ts
    → loadBlockData(content_source, params) → data-loaders.ts
    → <BlockComponent data={data} />

6 neue Blocks in diesem Slice:
  announcement-bar  → Client Component, inline source, dismissible via localStorage
  breadcrumb        → Server Component, inline source (labels aus YAML, route-aware)
  trust-badges      → Server Component, inline source (Icon-Liste wie usp-bar)
  pagination        → Server Component, inline source (props aus PaginatedProductsResult)
  sort-bar          → Client Component, inline source (URL-Param Handler)
  empty-state       → Server Component, inline source (konfigurierbare Suggestions)

Global Config Pattern (neu in diesem Slice):
  themes/default/pages/global.yaml → loadGlobalConfig(theme) → layout.tsx renders global blocks
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `frontend/lib/blocks/registry.ts` | 6 neue Block-Typen registrieren: `announcement-bar`, `breadcrumb`, `trust-badges`, `pagination`, `sort-bar`, `empty-state` |
| `frontend/lib/blocks/page-config.ts` | `loadGlobalConfig(theme)` Funktion hinzufügen (lädt `global.yaml`) |
| `frontend/lib/blocks/types.ts` | 6 neue Data-Typen: `AnnouncementBarData`, `BreadcrumbData`, `TrustBadgeData`, `PaginationData`, `SortBarData`, `EmptyStateData` |
| `frontend/components/blocks/` | 6 neue Block-Components (je 1 Datei pro Block) |
| `frontend/app/layout.tsx` | `loadGlobalConfig()` aufrufen, Global Blocks über `{children}` rendern |
| `frontend/themes/default/pages/global.yaml` | Neue Datei: Announcement Bar Konfiguration |

### 2. Datenfluss

```
Browser Request (beliebige Page)
  ↓
layout.tsx (RSC)
  → loadGlobalConfig(theme)
  → global.yaml: announcement-bar Block
  → <AnnouncementBarBlock data={inlineData} /> (Client Component)
     → prüft localStorage['announcement-dismissed-{id}']
     → zeigt Bar oder nichts
  ↓
page.tsx (RSC) mit searchParams: { page, sort }
  → loadPageConfig('category', theme, { slug, page, sort })
  → YAML enthält: breadcrumb, sort-bar, product-grid, pagination, empty-state
  ↓
SectionRenderer
  → breadcrumb: inline data (YAML-Labels) → BreadcrumbBlock (Server)
  → sort-bar: inline data (currentSort, baseUrl) → SortBarBlock (Client)
  → pagination: inline data (currentPage, totalPages, baseUrl) → PaginationBlock (Server)
  → empty-state: inline data (headline, text, links) → EmptyStateBlock (Server)
  → trust-badges: inline data (icon-Liste) → TrustBadgesBlock (Server)
```

### 3. Block-Spezifikationen

#### 3.1 `announcement-bar` Block

**Typ:** Client Component (benötigt localStorage-Zugriff)
**Content Source:** `inline`
**Data Type:** `AnnouncementBarData`

```
AnnouncementBarData:
  id: string          (eindeutige ID für dismissed-State, z.B. "launch-2026")
  text: string        (Anzeigetext)
  link?: string       (URL wenn gesamte Bar klickbar)
  dismissible?: boolean (default: true)
  bgColor?: string    (Theme-Token, default: "primary")
```

**State Machine:**
- `visible`: Bar am Top, dismissible X-Button sichtbar (wenn `dismissible: true`)
- `dismissed`: Bar ausgeblendet; `localStorage['announcement-dismissed-{id}']` gesetzt

**Render-Regeln:**
- Beim Mount: prüfe `localStorage['announcement-dismissed-{id}']` — wenn gesetzt, render null
- X-Button Klick: setze localStorage-Key, fade-out Animation, render null
- Wenn `link` gesetzt: gesamte Bar ist ein `<a>`-Tag (klickbar)
- `bgColor` mappt auf Tailwind-Klasse: `bg-primary`, `bg-secondary` etc.
- Position: über dem Header (in layout.tsx über `<Header />` gerendert)

#### 3.2 `breadcrumb` Block

**Typ:** Server Component
**Content Source:** `inline`
**Data Type:** `BreadcrumbData`

```
BreadcrumbData:
  items: { label: string, href?: string }[]
  (letztes Item hat kein href = aktuelle Seite)
```

**Render-Regeln:**
- Semantisches `<nav aria-label="Breadcrumb">` mit `<ol>` Liste
- Trennzeichen: `›` (nicht `/`) zwischen Items
- Letztes Item: `aria-current="page"`, nicht klickbar
- Structured Data: `JSON-LD` BreadcrumbList Schema für SEO

**YAML-Beispiel:**
```yaml
- type: breadcrumb
  content_source: inline
  params:
    props:
      items:
        - label: "Home"
          href: "/"
        - label: "$route.categoryName"
          href: null
```

#### 3.3 `trust-badges` Block

**Typ:** Server Component
**Content Source:** `inline`
**Data Type:** `TrustBadgeData`

```
TrustBadgeData:
  items: { icon: string, text: string }[]
```

**Render-Regeln:**
- Horizontale Liste, zentriert, flex-wrap für Mobile
- Icons aus `lucide-react` (gleicher Icon-Map-Pattern wie `usp-bar-block.tsx`)
- Icon-Map: `truck` → `Truck`, `shield` → `Shield`, `lock` → `Lock`, `refresh` → `RefreshCw`, `star` → `Star`, `check` → `Check`, `package` → `Package`
- Icons haben `aria-hidden={true}`, Text sichtbar
- Unterschied zu `usp-bar`: `trust-badges` hat leicht anderen visuellen Stil (border, padding, card-ähnlich)

**Standard-Badges (YAML-Beispiel):**
```yaml
items:
  - icon: "truck"
    text: "Versand in 3-5 Tagen"
  - icon: "refresh"
    text: "30 Tage Rückgabe"
  - icon: "lock"
    text: "Sichere Zahlung"
```

#### 3.4 `pagination` Block

**Typ:** Server Component
**Content Source:** `inline` (Slice 1) → `woocommerce` (ab Slice 3, kein Interface-Bruch — siehe Transition unten)
**Data Type:** `PaginationData` (Interim-DTO fuer Slice 1)

```
PaginationData:
  currentPage: number     (1-indexed, aus $route.page)
  totalPages: number      (berechnet aus PaginatedProductsResult.pagination.totalPages in Slice 3)
  baseUrl: string         (z.B. "/kategorie/t-shirts" oder "/suche")
  currentSort?: string    (aktueller sort-Param, wird in Pagination-URLs beibehalten)
  currentQuery?: string   (aktueller q-Param fuer Suche, wird in Pagination-URLs beibehalten)
```

> **Interim-DTO Erklaerung:** `PaginationData` ist ein eigenstaendiger Block-Data-Type fuer Slice 1. Er ist ein Subset-Projektion des `PaginatedProductsResult.pagination` Sub-Objekts aus der Architecture (Zeile 371-386). Die Felder `currentPage`, `totalPages` und `baseUrl` entsprechen exakt den gleichnamigen Feldern in `PaginatedProductsResult.pagination`.
>
> **Transition zu Slice 3:** In Slice 3 wechselt der `pagination`-Block auf `content_source: woocommerce`. Die Page-Logic in Slice 3 extrahiert `{ currentPage, totalPages, baseUrl, currentSort, currentQuery }` aus dem `PaginatedProductsResult` und befuellt `PaginationBlock` mit einem `PaginationData`-kompatiblen Objekt. Da der Block-Interface unveraendert bleibt, entsteht kein Breaking Change. `PaginationData` bleibt als TypeScript-Interface in `lib/blocks/types.ts` erhalten.
>
> **Architecture-Abweichung:** Architecture Block Inventory (Zeile 327) listet `content_source: woocommerce` und `PaginatedProductsResult`. Diese Abweichung ist beabsichtigt fuer Slice 1 — der Block rendert in Slice 1 ausschliesslich UI-Logik (Prev/Next/Ellipsis aus vorberechneten Werten), ohne GraphQL-Calls. Ab Slice 3 ist der Block architecture-konform.

**Render-Regeln:**
- Format: `‹ 1 2 3 … 8 ›` — immer erste + letzte Seite sichtbar, Ellipsis bei Lücken
- Wenn totalPages <= 1: render null (kein leerer Container)
- Prev-Button: disabled auf Seite 1 (`aria-disabled="true"`, kein `href`)
- Next-Button: disabled auf letzter Seite (`aria-disabled="true"`, kein `href`)
- Aktuelle Seite: `aria-current="page"`, nicht klickbar
- URL-Generierung: `${baseUrl}?page=${n}` — behält `sort` und `q` Params bei
- Alle Pagination-Links sind echte `<a href>` Links (SEO-kompatibel, kein onClick-only)
- Semantik: `<nav aria-label="Seitennavigation">` mit `<ol>` Liste

**Ellipsis-Logik:**
- Zeige immer: Seite 1, letzte Seite, currentPage-1, currentPage, currentPage+1
- Ellipsis wenn Lücke > 1 zwischen angezeigten Seiten

#### 3.5 `sort-bar` Block

**Typ:** Client Component (benötigt `useRouter` für URL-Updates)
**Content Source:** `inline`
**Data Type:** `SortBarData`

```
SortBarData:
  currentSort: SortOption    (aus $route.sort — 'default' | 'price_asc' | 'price_desc' | 'newest')
  baseUrl: string            (z.B. "/kategorie/t-shirts" oder "/suche")

SortOption = 'default' | 'price_asc' | 'price_desc' | 'newest'
  (Architecture-Definition: architecture.md Zeile 95)
  'default' = keine Sortierung (WooCommerce-Default, URL-Param wird weggelassen)
```

**Sort-Optionen:**
| Value | Label (Deutsch) | URL-Param |
|-------|-----------------|-----------|
| `default` | Empfohlen | (kein param) |
| `price_asc` | Preis: aufsteigend | `?sort=price_asc` |
| `price_desc` | Preis: absteigend | `?sort=price_desc` |
| `newest` | Neueste zuerst | `?sort=newest` |

**Render-Regeln:**
- Label "Sortieren nach:" + Native `<select>` Dropdown (Accessibility > Custom Dropdown)
- Auswahl → `router.push(`${baseUrl}?sort=${value}`)` (page-Param wird auf 1 zurückgesetzt)
- `useTransition` für non-blocking UI-Update während Navigation
- `<select>` hat `aria-label="Produkte sortieren"`
- Beibehaltung anderer URL-Params (z.B. `q` bei Suche)

**State Machine (discovery.md):**
- `no_sort`: Dropdown zeigt "Empfohlen"
- `sorted_price_asc` / `sorted_price_desc` / `sorted_newest`: entsprechende Label-Anzeige

#### 3.6 `empty-state` Block

**Typ:** Server Component
**Content Source:** `inline`
**Data Type:** `EmptyStateData`

```
EmptyStateData:
  headline: string          (z.B. "Keine Produkte gefunden")
  text: string              (z.B. "Versuche eine andere Kategorie oder Suche")
  links?: { label: string, href: string }[]  (YAML-konfigurierbare Suggestions)
```

**Render-Regeln:**
- Zentrierter Container, vertikal gestapelt
- Icon: `SearchX` oder `PackageOpen` aus lucide-react (aria-hidden)
- Headline: `<h2>`, Text: `<p>`
- Links-Liste: falls vorhanden, horizontale Link-Liste zu Top-Kategorien
- Keine Interaktion außer den Links

### 4. Global Config Pattern (neu)

**Neue Funktion in `lib/blocks/page-config.ts`:**

```typescript
// Lädt global.yaml für alle Pages
export function loadGlobalConfig(theme: string): PageConfig {
  // 2-Tier: themes/{theme}/pages/global.yaml → themes/default/pages/global.yaml
}
```

**Erweiterung `app/layout.tsx`:**
- `loadGlobalConfig(theme)` aufrufen (async möglich da layout.tsx RSC)
- Global Blocks rendern: oberhalb von `<Header />` (für Announcement Bar)
- `SectionRenderer` für globale Sections nutzen (kein separates Renderer nötig)

### 5. Neue `WooCommerceLoaderParams` Query-Typen

**Erweiterung `lib/blocks/types.ts`:**

Die `WooCommerceLoaderParams` Interface erhält neue Query-Typen für spätere Slices (werden hier definiert, aber erst ab Slice 2 genutzt):

```
query: 'products_paginated'  (für Slice 3: Kategorie mit Pagination)
page?: number
perPage?: number
sort?: string
```

Dieser Slice nutzt ausschliesslich `content_source: inline` für alle 6 Blöcke — keine neuen GraphQL-Queries in Slice 1.

### 6. Abhängigkeiten

- **Bestehend (wiederverwendet):** `lucide-react ^0.575.0`, `lib/blocks/registry.ts`, `lib/blocks/types.ts`, `lib/blocks/page-config.ts`
- **Neu (keine neuen npm-Pakete):** Nur neue Komponenten-Dateien

---

## UI Anforderungen

### Wireframe (aus discovery.md)

> **Quelle:** `discovery.md` → "UI Layout & Context"

```
ANNOUNCEMENT BAR (Global, über Header)
┌─────────────────────────────────────────────────────────┐
│  Kostenloser Versand ab 100€ | Code: SOMMER10        [X] │
└─────────────────────────────────────────────────────────┘

BREADCRUMB (Kategorie-Page, oberhalb page-heading)
Home › T-Shirts

TRUST BADGES (Produkt-Page, horizontal)
🚚 Versand in 3-5 Tagen   ↩ 30 Tage Rückgabe   🔒 Sichere Zahlung

SORT BAR (Kategorie-/Suche-Page)
Sortieren nach: [Empfohlen ▾]

PAGINATION (Kategorie-/Suche-Page, unterhalb product-grid)
‹ 1 2 3 … 8 ›

EMPTY STATE (bei 0 Produkten)
      [Lupe-Icon]
  Keine Produkte gefunden
  Versuche eine andere Kategorie
  → T-Shirts   → Hoodies   → Accessoires
```

**Referenz Skills für UI-Implementation:**
- `.claude/skills/react-best-practices/SKILL.md` — `async-suspense-boundaries`, `rerender-transitions`
- `.claude/skills/web-design/SKILL.md` — Accessibility (nav aria-label, aria-current), Touch targets
- `.claude/skills/tailwind-v4/SKILL.md` — Tailwind Token-System (bg-primary, text-text-primary)

### 1. AnnouncementBarBlock

**Komponenten & Dateien:**
- `frontend/components/blocks/announcement-bar-block.tsx` — Client Component

**Verhalten:**
- Mount: localStorage prüfen; wenn `announcement-dismissed-{id}` gesetzt → render null ohne Flicker
- X-Button: setze localStorage, setState(dismissed), transition opacity-0 → remove
- `suppressHydrationWarning` auf dem äußeren div (localStorage-Zustand kann abweichen)
- Wenn `link` in data: `<a href={link}>` als Wrapper (gesamte Bar klickbar)

**Zustände:**
- `visible`: Bar sichtbar mit Text und optionalem X-Button
- `dismissed`: `null` returned (keine DOM-Präsenz)

**Design:**
- Volle Breite, `bg-primary` (oder YAML-konfiguriertes bgColor)
- Text: `text-primary-foreground` (heller Text auf primary Hintergrund)
- Padding: `py-2 px-4`
- X-Button: `aria-label="Ankündigung schliessen"`, `focus-visible:ring-2`
- `touch-action: manipulation` auf X-Button

### 2. BreadcrumbBlock

**Komponenten & Dateien:**
- `frontend/components/blocks/breadcrumb-block.tsx` — Server Component

**Verhalten:**
- Statisches Rendering, kein JS nötig
- JSON-LD BreadcrumbList Schema als `<script type="application/ld+json">`

**Zustände:**
- Loading: kein Skeleton nötig (inline, instantan)
- Empty: wenn `items.length === 0` → `return null`

**Design:**
- `<nav aria-label="Breadcrumb">` mit `<ol>` Liste
- Items horizontal, `flex items-center gap-1`
- Trennzeichen `›` als `aria-hidden` span
- Letztes Item: `aria-current="page"`, `text-text-primary font-medium`
- Vorherige Items: `text-text-secondary hover:text-text-primary`
- Schriftgröße: `text-sm`

### 3. TrustBadgesBlock

**Komponenten & Dateien:**
- `frontend/components/blocks/trust-badges-block.tsx` — Server Component

**Verhalten:**
- Rein dekorativ, keine Interaktion

**Zustände:**
- Empty: wenn `items.length === 0` → `return null`

**Design:**
- `<section aria-label="Vertrauenssiegel">`
- Horizontal, `flex flex-wrap justify-center gap-6 py-4`
- Pro Badge: Icon + Text nebeneinander, `flex items-center gap-2`
- Icon: `h-5 w-5 text-primary`, `aria-hidden={true}`
- Text: `text-sm text-text-secondary`
- Optional Border: `border border-border rounded-card p-3` pro Badge-Item

### 4. PaginationBlock

**Komponenten & Dateien:**
- `frontend/components/blocks/pagination-block.tsx` — Server Component

**Verhalten:**
- Alle Links sind echte `<a href>` Tags (SSR, SEO-kompatibel)
- Keine `router.push()` oder JavaScript für Navigation (pure HTML)
- `buildPageUrl(baseUrl, page, sort?, q?)` Hilfsfunktion in der Komponente

**Zustände:**
- Loading: kein Skeleton nötig (inline, lightweight)
- Wenn `totalPages <= 1`: `return null`

**Design:**
- `<nav aria-label="Seitennavigation">`
- `<ol>` mit `flex items-center gap-1`
- Prev: `‹` Link, disabled = `aria-disabled="true" pointer-events-none opacity-40`
- Next: `›` Link, disabled = analog
- Aktuelle Seite: `bg-primary text-primary-foreground rounded-card`, `aria-current="page"`
- Andere Seiten: `hover:bg-surface-secondary rounded-card`
- Ellipsis `…`: `aria-hidden="true"`, nicht klickbar
- Mindest-Touch-Target: `min-h-[2.75rem] min-w-[2.75rem]` (44px)

### 5. SortBarBlock

**Komponenten & Dateien:**
- `frontend/components/blocks/sort-bar-block.tsx` — Client Component

**Verhalten:**
- `onChange` des `<select>`: berechne neue URL mit `currentSort` ersetzt, `page` auf 1 zurückgesetzt
- `useRouter().push(newUrl)` — ganze Page wird neu gerendert (Server Component re-render)
- `useTransition` für `isPending` Loading-State während Navigation

**Zustände:**
- Default: Dropdown zeigt aktuellen Sort-Wert (oder "Empfohlen" wenn leer)
- Pending: `opacity-70 pointer-events-none` während Navigation läuft

**Design:**
- `<div>` mit `flex items-center gap-2`
- Label: `<label htmlFor="sort-select">Sortieren nach:</label>` (Text links)
- `<select id="sort-select" aria-label="Produkte sortieren">`
- `border border-border rounded-card px-3 py-1.5 text-sm`
- Native `<select>` (Accessibility > Custom Dropdown für MVP)
- `dark:` Modifier: `dark:bg-surface-elevated dark:text-text-primary`

### 6. EmptyStateBlock

**Komponenten & Dateien:**
- `frontend/components/blocks/empty-state-block.tsx` — Server Component

**Verhalten:**
- Rein deklarativ, keine Client-Interaktion außer Links

**Zustände:**
- Nur ein Zustand (immer vollständig gerendert wenn sichtbar)

**Design:**
- Zentrierter Container: `text-center py-16 px-4`
- Icon: `SearchX` (lucide-react), `h-12 w-12 text-text-tertiary mx-auto mb-4`, `aria-hidden={true}`
- Headline: `<h2 className="text-xl font-semibold text-text-primary mb-2">`
- Text: `<p className="text-text-secondary mb-6">`
- Links: `flex flex-wrap justify-center gap-3` mit `<a>` Tags
- Link-Style: `text-primary underline hover:no-underline`

### N. Accessibility

- [x] `<nav aria-label="Breadcrumb">` auf Breadcrumb
- [x] `<nav aria-label="Seitennavigation">` auf Pagination
- [x] `aria-current="page"` auf aktiver Pagination-Seite und letztem Breadcrumb-Item
- [x] `aria-disabled="true"` auf deaktivierten Prev/Next-Buttons
- [x] `aria-label="Ankündigung schliessen"` auf X-Button der Announcement Bar
- [x] `aria-label="Produkte sortieren"` auf Sort-Select
- [x] `aria-label="Vertrauenssiegel"` auf Trust Badges Section
- [x] Alle Icons haben `aria-hidden={true}`
- [x] Focus-visible states auf allen interaktiven Elementen (`focus-visible:ring-2`)
- [x] Touch targets min 44x44px (via `min-h-[2.75rem] min-w-[2.75rem]`)
- [x] `touch-action: manipulation` auf Buttons (kein double-tap zoom)

---

## Acceptance Criteria

1) GIVEN der User lädt eine beliebige Page des Shops
   WHEN `global.yaml` eine `announcement-bar` mit `id: "launch-2026"` und `dismissible: true` konfiguriert
   THEN wird die Announcement Bar oberhalb des Headers angezeigt mit dem konfigurierten Text und einem X-Button

2) GIVEN die Announcement Bar ist sichtbar
   WHEN der User auf den X-Button klickt
   THEN verschwindet die Bar, und `localStorage['announcement-dismissed-launch-2026']` ist gesetzt; beim nächsten Seitenaufruf bleibt die Bar ausgeblendet

3) GIVEN die Announcement Bar wurde für `id: "launch-2026"` dismissed
   WHEN der User die Seite neu lädt
   THEN ist die Bar nicht sichtbar (keine Flicker/Flash before render)

4) GIVEN ein Block mit `type: breadcrumb` ist in der YAML-Konfiguration einer Page
   WHEN die Page gerendert wird
   THEN zeigt der Breadcrumb die konfigurierten Items als klickbare Links mit dem letzten Item als nicht-klickbarem aktuellen Seitennamen (`aria-current="page"`)

5) GIVEN ein Block mit `type: trust-badges` ist in der YAML-Konfiguration
   WHEN die Page gerendert wird
   THEN werden die konfigurierten Icon+Text Badges horizontal nebeneinander angezeigt mit korrekten lucide-react Icons

6) GIVEN ein Block mit `type: pagination` mit `currentPage: 2`, `totalPages: 5`, `baseUrl: "/kategorie/t-shirts"` ist konfiguriert
   WHEN die Page gerendert wird
   THEN zeigt die Pagination: `‹ 1 [2] 3 4 5 ›` — der Prev-Button verlinkt auf `?page=1`, der Next-Button auf `?page=3`, Seite 2 hat `aria-current="page"`

7) GIVEN ein Block mit `type: pagination` mit `totalPages: 1` ist konfiguriert
   WHEN die Page gerendert wird
   THEN wird kein Pagination-Element gerendert (render null)

8) GIVEN ein Block mit `type: sort-bar` mit `currentSort: ""`, `baseUrl: "/kategorie/t-shirts"` ist konfiguriert
   WHEN der User "Preis: aufsteigend" im Dropdown auswählt
   THEN wird die Navigation zu `/kategorie/t-shirts?sort=price_asc&page=1` ausgelöst

9) GIVEN ein Block mit `type: sort-bar` und ein Pagination-Block sind gemeinsam auf einer Page
   WHEN der User die Sortierung ändert
   THEN wird der `page`-URL-Parameter auf 1 zurückgesetzt (kein verbleiben auf Seite 3 mit neuer Sortierung)

10) GIVEN ein Block mit `type: empty-state` mit Headline, Text und 3 Suggestion-Links ist konfiguriert
    WHEN die Page gerendert wird
    THEN werden Headline, Text, und alle 3 Links als klickbare `<a href>` Elemente angezeigt

11) GIVEN alle 6 neuen Block-Typen sind in `registry.ts` registriert
    WHEN `resolveBlock('announcement-bar')` / `resolveBlock('breadcrumb')` / `resolveBlock('trust-badges')` / `resolveBlock('pagination')` / `resolveBlock('sort-bar')` / `resolveBlock('empty-state')` aufgerufen wird
    THEN gibt jede Funktion die korrekte React-Component zurück (kein `null`, kein `console.warn`)

12) GIVEN das `themes/default/pages/global.yaml` File existiert
    WHEN `loadGlobalConfig('default')` aufgerufen wird
    THEN wird eine gültige `PageConfig` mit mindestens einem Block zurückgegeben

---

## Testfälle

**WICHTIG:** Tests müssen VOR der Implementierung definiert werden! Der Orchestrator führt diese Tests automatisch nach der Slice-Implementierung aus.

### Test-Datei

**Für diesen Slice:** `tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import React from 'react'

// -------------------------------------------------------------------
// Hilfsfunktionen (aus den Block-Komponenten re-exportiert)
// -------------------------------------------------------------------

describe('Slice 01 — Cross-Page Infrastruktur', () => {

  // ---------------------------------------------------------------
  // 1. Block Registry
  // ---------------------------------------------------------------
  describe('registry.ts — neue Blocks registriert', () => {
    it('should resolve announcement-bar block', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('announcement-bar')
      expect(component).not.toBeNull()
    })

    it('should resolve breadcrumb block', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('breadcrumb')
      expect(component).not.toBeNull()
    })

    it('should resolve trust-badges block', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('trust-badges')
      expect(component).not.toBeNull()
    })

    it('should resolve pagination block', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('pagination')
      expect(component).not.toBeNull()
    })

    it('should resolve sort-bar block', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('sort-bar')
      expect(component).not.toBeNull()
    })

    it('should resolve empty-state block', async () => {
      const { resolveBlock } = await import('@/lib/blocks/registry')
      const component = resolveBlock('empty-state')
      expect(component).not.toBeNull()
    })
  })

  // ---------------------------------------------------------------
  // 2. AnnouncementBarBlock
  // ---------------------------------------------------------------
  describe('AnnouncementBarBlock', () => {
    beforeEach(() => {
      localStorage.clear()
    })

    it('should render announcement bar when not dismissed', async () => {
      const { AnnouncementBarBlock } = await import('@/components/blocks/announcement-bar-block')
      render(
        <AnnouncementBarBlock data={{
          id: 'test-bar-1',
          text: 'Kostenloser Versand ab 100€',
          dismissible: true,
        }} />
      )
      expect(screen.getByText('Kostenloser Versand ab 100€')).toBeInTheDocument()
    })

    it('should not render when already dismissed in localStorage', async () => {
      localStorage.setItem('announcement-dismissed-test-bar-1', '1')
      const { AnnouncementBarBlock } = await import('@/components/blocks/announcement-bar-block')
      render(
        <AnnouncementBarBlock data={{
          id: 'test-bar-1',
          text: 'Kostenloser Versand ab 100€',
          dismissible: true,
        }} />
      )
      expect(screen.queryByText('Kostenloser Versand ab 100€')).toBeNull()
    })

    it('should dismiss bar and set localStorage on X-button click', async () => {
      const { AnnouncementBarBlock } = await import('@/components/blocks/announcement-bar-block')
      render(
        <AnnouncementBarBlock data={{
          id: 'test-bar-2',
          text: 'Test Bar Text',
          dismissible: true,
        }} />
      )
      const closeButton = screen.getByRole('button', { name: /ankündigung schliessen/i })
      fireEvent.click(closeButton)
      expect(localStorage.getItem('announcement-dismissed-test-bar-2')).not.toBeNull()
      expect(screen.queryByText('Test Bar Text')).toBeNull()
    })

    it('should not show close button when dismissible is false', async () => {
      const { AnnouncementBarBlock } = await import('@/components/blocks/announcement-bar-block')
      render(
        <AnnouncementBarBlock data={{
          id: 'test-bar-3',
          text: 'Pflicht-Hinweis',
          dismissible: false,
        }} />
      )
      expect(screen.queryByRole('button', { name: /ankündigung schliessen/i })).toBeNull()
      expect(screen.getByText('Pflicht-Hinweis')).toBeInTheDocument()
    })
  })

  // ---------------------------------------------------------------
  // 3. BreadcrumbBlock
  // ---------------------------------------------------------------
  describe('BreadcrumbBlock', () => {
    it('should render breadcrumb with correct links', async () => {
      const { BreadcrumbBlock } = await import('@/components/blocks/breadcrumb-block')
      render(
        <BreadcrumbBlock data={{
          items: [
            { label: 'Home', href: '/' },
            { label: 'T-Shirts' },
          ],
        }} />
      )
      const nav = screen.getByRole('navigation', { name: /breadcrumb/i })
      expect(nav).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'Home' })).toHaveAttribute('href', '/')
      expect(screen.getByText('T-Shirts')).toBeInTheDocument()
    })

    it('should set aria-current="page" on last breadcrumb item', async () => {
      const { BreadcrumbBlock } = await import('@/components/blocks/breadcrumb-block')
      render(
        <BreadcrumbBlock data={{
          items: [
            { label: 'Home', href: '/' },
            { label: 'T-Shirts' },
          ],
        }} />
      )
      const currentItem = screen.getByText('T-Shirts').closest('[aria-current="page"]')
      expect(currentItem).not.toBeNull()
    })

    it('should return null for empty items', async () => {
      const { BreadcrumbBlock } = await import('@/components/blocks/breadcrumb-block')
      const { container } = render(<BreadcrumbBlock data={{ items: [] }} />)
      expect(container.firstChild).toBeNull()
    })
  })

  // ---------------------------------------------------------------
  // 4. TrustBadgesBlock
  // ---------------------------------------------------------------
  describe('TrustBadgesBlock', () => {
    it('should render all badge items', async () => {
      const { TrustBadgesBlock } = await import('@/components/blocks/trust-badges-block')
      render(
        <TrustBadgesBlock data={{
          items: [
            { icon: 'truck', text: 'Versand in 3-5 Tagen' },
            { icon: 'refresh', text: '30 Tage Rückgabe' },
            { icon: 'lock', text: 'Sichere Zahlung' },
          ],
        }} />
      )
      expect(screen.getByText('Versand in 3-5 Tagen')).toBeInTheDocument()
      expect(screen.getByText('30 Tage Rückgabe')).toBeInTheDocument()
      expect(screen.getByText('Sichere Zahlung')).toBeInTheDocument()
    })

    it('should return null for empty items', async () => {
      const { TrustBadgesBlock } = await import('@/components/blocks/trust-badges-block')
      const { container } = render(<TrustBadgesBlock data={{ items: [] }} />)
      expect(container.firstChild).toBeNull()
    })
  })

  // ---------------------------------------------------------------
  // 5. PaginationBlock
  // ---------------------------------------------------------------
  describe('PaginationBlock', () => {
    it('should render pagination with correct page links', async () => {
      const { PaginationBlock } = await import('@/components/blocks/pagination-block')
      render(
        <PaginationBlock data={{
          currentPage: 2,
          totalPages: 5,
          baseUrl: '/kategorie/t-shirts',
        }} />
      )
      const nav = screen.getByRole('navigation', { name: /seitennavigation/i })
      expect(nav).toBeInTheDocument()
      // Seite 2 ist aktiv
      const activePage = screen.getByText('2').closest('[aria-current="page"]')
      expect(activePage).not.toBeNull()
    })

    it('should return null when totalPages is 1', async () => {
      const { PaginationBlock } = await import('@/components/blocks/pagination-block')
      const { container } = render(
        <PaginationBlock data={{ currentPage: 1, totalPages: 1, baseUrl: '/kategorie/t-shirts' }} />
      )
      expect(container.firstChild).toBeNull()
    })

    it('should return null when totalPages is 0', async () => {
      const { PaginationBlock } = await import('@/components/blocks/pagination-block')
      const { container } = render(
        <PaginationBlock data={{ currentPage: 1, totalPages: 0, baseUrl: '/kategorie/t-shirts' }} />
      )
      expect(container.firstChild).toBeNull()
    })

    it('should disable prev button on first page', async () => {
      const { PaginationBlock } = await import('@/components/blocks/pagination-block')
      render(
        <PaginationBlock data={{ currentPage: 1, totalPages: 3, baseUrl: '/suche' }} />
      )
      const prevLink = screen.getByRole('link', { name: /vorige seite/i })
      expect(prevLink).toHaveAttribute('aria-disabled', 'true')
    })

    it('should disable next button on last page', async () => {
      const { PaginationBlock } = await import('@/components/blocks/pagination-block')
      render(
        <PaginationBlock data={{ currentPage: 3, totalPages: 3, baseUrl: '/suche' }} />
      )
      const nextLink = screen.getByRole('link', { name: /nächste seite/i })
      expect(nextLink).toHaveAttribute('aria-disabled', 'true')
    })

    it('should build correct page URLs including sort param', async () => {
      const { PaginationBlock } = await import('@/components/blocks/pagination-block')
      render(
        <PaginationBlock data={{
          currentPage: 1,
          totalPages: 3,
          baseUrl: '/kategorie/t-shirts',
          currentSort: 'price_asc',
        }} />
      )
      const page2Link = screen.getByRole('link', { name: '2' })
      expect(page2Link).toHaveAttribute('href', '/kategorie/t-shirts?page=2&sort=price_asc')
    })
  })

  // ---------------------------------------------------------------
  // 6. SortBarBlock
  // ---------------------------------------------------------------
  describe('SortBarBlock', () => {
    it('should render sort dropdown with all options', async () => {
      const { SortBarBlock } = await import('@/components/blocks/sort-bar-block')
      // Mock useRouter — next/navigation wird per vi.mock gemockt (mock_external Strategie)
      vi.mock('next/navigation', () => ({
        useRouter: () => ({ push: vi.fn() }),
        useSearchParams: () => new URLSearchParams(),
      }))
      render(
        <SortBarBlock data={{ currentSort: 'default', baseUrl: '/kategorie/t-shirts' }} />
      )
      const select = screen.getByRole('combobox', { name: /produkte sortieren/i })
      expect(select).toBeInTheDocument()
      expect(screen.getByText('Empfohlen')).toBeInTheDocument()
      expect(screen.getByText('Preis: aufsteigend')).toBeInTheDocument()
      expect(screen.getByText('Preis: absteigend')).toBeInTheDocument()
      expect(screen.getByText('Neueste zuerst')).toBeInTheDocument()
    })

    it('should show correct selected option for currentSort', async () => {
      const { SortBarBlock } = await import('@/components/blocks/sort-bar-block')
      vi.mock('next/navigation', () => ({
        useRouter: () => ({ push: vi.fn() }),
        useSearchParams: () => new URLSearchParams('sort=price_desc'),
      }))
      render(
        <SortBarBlock data={{ currentSort: 'price_desc', baseUrl: '/suche' }} />
      )
      const select = screen.getByRole('combobox') as HTMLSelectElement
      expect(select.value).toBe('price_desc')
    })

    it('should show "Empfohlen" as selected when currentSort is default', async () => {
      const { SortBarBlock } = await import('@/components/blocks/sort-bar-block')
      vi.mock('next/navigation', () => ({
        useRouter: () => ({ push: vi.fn() }),
        useSearchParams: () => new URLSearchParams(),
      }))
      render(
        <SortBarBlock data={{ currentSort: 'default', baseUrl: '/kategorie/t-shirts' }} />
      )
      const select = screen.getByRole('combobox') as HTMLSelectElement
      expect(select.value).toBe('default')
    })
  })

  // ---------------------------------------------------------------
  // 7. EmptyStateBlock
  // ---------------------------------------------------------------
  describe('EmptyStateBlock', () => {
    it('should render headline, text and suggestion links', async () => {
      const { EmptyStateBlock } = await import('@/components/blocks/empty-state-block')
      render(
        <EmptyStateBlock data={{
          headline: 'Keine Produkte gefunden',
          text: 'Versuche eine andere Kategorie',
          links: [
            { label: 'T-Shirts', href: '/kategorie/t-shirts' },
            { label: 'Hoodies', href: '/kategorie/hoodies' },
          ],
        }} />
      )
      expect(screen.getByRole('heading', { name: 'Keine Produkte gefunden' })).toBeInTheDocument()
      expect(screen.getByText('Versuche eine andere Kategorie')).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'T-Shirts' })).toHaveAttribute('href', '/kategorie/t-shirts')
      expect(screen.getByRole('link', { name: 'Hoodies' })).toHaveAttribute('href', '/kategorie/hoodies')
    })

    it('should render without links when links array is empty', async () => {
      const { EmptyStateBlock } = await import('@/components/blocks/empty-state-block')
      render(
        <EmptyStateBlock data={{
          headline: 'Leer',
          text: 'Nichts hier',
        }} />
      )
      expect(screen.getByRole('heading', { name: 'Leer' })).toBeInTheDocument()
      expect(screen.queryAllByRole('link')).toHaveLength(0)
    })
  })

  // ---------------------------------------------------------------
  // 8. loadGlobalConfig
  // ---------------------------------------------------------------
  describe('loadGlobalConfig', () => {
    it('should return a valid PageConfig from global.yaml', async () => {
      const { loadGlobalConfig } = await import('@/lib/blocks/page-config')
      const config = loadGlobalConfig('default')
      expect(config).toHaveProperty('sections')
      expect(Array.isArray(config.sections)).toBe(true)
    })

    it('should contain announcement-bar block in default global config', async () => {
      const { loadGlobalConfig } = await import('@/lib/blocks/page-config')
      const config = loadGlobalConfig('default')
      const allBlocks = config.sections.flatMap(s => s.blocks)
      const announcementBar = allBlocks.find(b => b.type === 'announcement-bar')
      expect(announcementBar).toBeDefined()
    })
  })

})
```
</test_spec>

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig & vollständig
- [x] Sicherheits-/Privacy-Aspekte bedacht (localStorage nur für dismissed-State, keine PII)
- [x] UX/Copy final (alle deutschen Labels definiert)
- [x] Rollout-/Rollback-Plan: Feature ist additiv (neue Files, keine Breaking Changes an bestehenden Blöcken)

---

## Skill Verification (UI-Implementation)

### React Best Practices Verification

**Critical Priority:**
- [x] `async-parallel`: Nicht anwendbar (alle 6 Blocks sind inline-source, kein async)
- [x] `bundle-dynamic-imports`: Nicht anwendbar in diesem Slice (keine heavy Components)

**High Priority:**
- [x] `server-cache-react`: Nicht anwendbar (kein Server-Fetching in diesem Slice)
- [x] `async-suspense-boundaries`: `loadGlobalConfig()` in layout.tsx: kein async nötig (YAML sync); inline Blocks brauchen kein Suspense

**Medium Priority:**
- [x] `rerender-transitions`: `useTransition` in SortBarBlock für non-blocking Navigation
- [x] `rerender-dependencies`: SortBarBlock nutzt primitive `currentSort` string als Dependency
- [x] `rendering-conditional-render`: `{items.length > 0 ? <List /> : null}` — kein `&&` mit Zahlen

### Web Design Guidelines Verification

**Accessibility:**
- [x] Icon-only buttons haben `aria-label` (X-Button: "Ankündigung schliessen")
- [x] Form inputs haben assoziierte Labels (`<label htmlFor="sort-select">`)
- [x] Keine neuen Images in diesem Slice
- [x] Keyboard handler: alle Interaktionen via native `<button>`, `<a>`, `<select>` (kein custom handler nötig)
- [x] Focus-visible states: `focus-visible:ring-2 focus-visible:ring-offset-2` auf allen interaktiven Elementen

**Animation & Motion:**
- [x] `prefers-reduced-motion` beachtet: Announcement Bar Dismiss-Animation via CSS transition (wird via `@media (prefers-reduced-motion: reduce)` deaktiviert)
- [x] Nur `opacity` und `transform` für Animationen (keine Layout-triggernden Properties)

**Touch & Mobile:**
- [x] `touch-action: manipulation` auf Announcement Bar X-Button und Pagination-Buttons
- [x] Touch targets mindestens 44x44px (via `min-h-[2.75rem] min-w-[2.75rem]`)

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [x] Keine hardcoded Werte: `bg-primary`, `text-text-primary`, `text-text-secondary`, `rounded-card`, `border-border`, `bg-surface-secondary`
- [x] Kein hardcoded Hex oder px-Farben
- [x] Semantic color naming (primary, text-primary, surface-secondary)

**Responsive:**
- [x] Mobile-first: alle Layouts starten mit Mobile-Breakpoint
- [x] Trust Badges: `flex-wrap` für Mobile (2-Spalten), Desktop: alle in einer Reihe
- [x] Pagination: `gap-1` auf Mobile, ausreichende Touch-Targets

**Dark Mode:**
- [x] `dark:` Modifier für Sort-Select: `dark:bg-surface-elevated dark:text-text-primary`

---

## Constraints & Hinweise

**Betrifft:**
- `app/layout.tsx` wird modifiziert (Global Blocks hinzufügen) — keine Breaking Changes, nur Erweiterung
- `lib/blocks/registry.ts` wird um 6 Einträge erweitert
- `lib/blocks/page-config.ts` erhält neue `loadGlobalConfig()` Funktion
- `lib/blocks/types.ts` erhält 6 neue Interfaces

**API Contract:**
- Alle 6 Blocks nutzen `content_source: inline` — kein GraphQL in diesem Slice
- `PaginationData` wird von Slice 3 (Kategorie) und Slice 5 (Suche) befüllt — der Block selbst rechnet nicht, er empfängt nur fertig berechnete `currentPage`/`totalPages`

**Abgrenzung:**
- Pagination-Block rendern nur UI — die Berechnung von `currentPage`/`totalPages` aus GraphQL-Responses passiert in Slice 3 (data-loader Erweiterung)
- Sort-Bar sendet nur URL-Updates — die Sortierung der Produkte passiert in Slice 3 (data-loader Erweiterung)
- Breadcrumb nutzt in diesem Slice nur inline-Data aus YAML — dynamische Breadcrumbs (aus GraphQL category_meta) kommen in Slice 3
- `announcement-bar` in `global.yaml` ist YAML-konfiguriert — kein CMS-Backend nötig

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| — | — | — | Kein vorheriger Slice erforderlich |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `BreadcrumbBlock` | Component | Slice 3 (Kategorie-YAML) | `BlockComponentProps<BreadcrumbData>` |
| `TrustBadgesBlock` | Component | Slice 2 (Produkt-YAML) | `BlockComponentProps<TrustBadgeData>` |
| `AnnouncementBarBlock` | Component | `app/layout.tsx` (global.yaml) | `BlockComponentProps<AnnouncementBarData>` |
| `PaginationBlock` | Component | Slice 3 (Kategorie-YAML), Slice 5 (Suche-YAML) | `BlockComponentProps<PaginationData>` |
| `SortBarBlock` | Component | Slice 3 (Kategorie-YAML), Slice 5 (Suche-YAML) | `BlockComponentProps<SortBarData>` |
| `EmptyStateBlock` | Component | Slice 3 (Kategorie-YAML), Slice 5 (Suche-YAML) | `BlockComponentProps<EmptyStateData>` |
| `AnnouncementBarData` | TypeScript Interface | `lib/blocks/types.ts` | `{ id, text, link?, dismissible?, bgColor? }` |
| `BreadcrumbData` | TypeScript Interface | `lib/blocks/types.ts` | `{ items: { label, href? }[] }` |
| `TrustBadgeData` | TypeScript Interface | `lib/blocks/types.ts` | `{ items: { icon, text }[] }` |
| `PaginationData` | TypeScript Interface | `lib/blocks/types.ts` | `{ currentPage, totalPages, baseUrl, currentSort?, currentQuery? }` |
| `SortBarData` | TypeScript Interface | `lib/blocks/types.ts` | `{ currentSort, baseUrl }` |
| `EmptyStateData` | TypeScript Interface | `lib/blocks/types.ts` | `{ headline, text, links? }` |
| `loadGlobalConfig(theme)` | Function | `app/layout.tsx` | `(theme: string) => PageConfig` |
| `registry` (erweitert) | Registry Map | `lib/blocks/section-renderer.tsx` | 6 neue Block-Typen registriert |

### Integration Validation Tasks

- [x] `BreadcrumbBlock` exportiert und in registry.ts als `'breadcrumb'` registriert
- [x] `TrustBadgesBlock` exportiert und in registry.ts als `'trust-badges'` registriert
- [x] `AnnouncementBarBlock` exportiert und in registry.ts als `'announcement-bar'` registriert
- [x] `PaginationBlock` exportiert und in registry.ts als `'pagination'` registriert
- [x] `SortBarBlock` exportiert und in registry.ts als `'sort-bar'` registriert
- [x] `EmptyStateBlock` exportiert und in registry.ts als `'empty-state'` registriert
- [x] `loadGlobalConfig` exportiert aus `lib/blocks/page-config.ts`
- [x] `global.yaml` existiert unter `themes/default/pages/global.yaml`
- [x] `app/layout.tsx` ruft `loadGlobalConfig` auf und rendert global blocks
- [x] Alle 6 Data-Interfaces in `lib/blocks/types.ts` exportiert

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind **PFLICHT-Deliverables**.
> Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `AnnouncementBarBlock` | Technische Umsetzung 3.1 + UI 1 | YES | Client Component, localStorage, dismissible |
| `BreadcrumbBlock` | Technische Umsetzung 3.2 + UI 2 | YES | Server Component, aria-current, JSON-LD |
| `TrustBadgesBlock` | Technische Umsetzung 3.3 + UI 3 | YES | Server Component, lucide-react Icon-Map |
| `PaginationBlock` | Technische Umsetzung 3.4 + UI 4 | YES | Server Component, pure HTML links, ellipsis |
| `SortBarBlock` | Technische Umsetzung 3.5 + UI 5 | YES | Client Component, useRouter, useTransition |
| `EmptyStateBlock` | Technische Umsetzung 3.6 + UI 6 | YES | Server Component, konfigurierbare Links |
| `loadGlobalConfig` | Technische Umsetzung 4 | YES | Neue Funktion in page-config.ts |
| `global.yaml` | Technische Umsetzung 4 | YES | themes/default/pages/global.yaml |
| `registry.ts` (Erweiterung) | Integration Contract | YES | 6 neue Einträge |
| `types.ts` (Erweiterung) | Integration Contract | YES | 6 neue Interfaces |
| `layout.tsx` (Erweiterung) | Integration Contract | YES | loadGlobalConfig + global blocks render |

### Referenz-Implementierung: AnnouncementBarBlock

```typescript
// frontend/components/blocks/announcement-bar-block.tsx
'use client'

import { useState, useEffect } from 'react'
import { X } from 'lucide-react'
import type { BlockComponentProps } from '@/lib/blocks/types'

interface AnnouncementBarData {
  id: string
  text: string
  link?: string
  dismissible?: boolean
  bgColor?: string
}

const BG_COLOR_MAP: Record<string, string> = {
  primary: 'bg-primary',
  secondary: 'bg-secondary',
}

export function AnnouncementBarBlock({ data }: BlockComponentProps<AnnouncementBarData>) {
  const [dismissed, setDismissed] = useState(false)
  const [mounted, setMounted] = useState(false)

  useEffect(() => {
    setMounted(true)
    const isDismissed = localStorage.getItem(`announcement-dismissed-${data.id}`)
    if (isDismissed) setDismissed(true)
  }, [data.id])

  // Vor Mount: render null (verhindert Hydration-Mismatch)
  if (!mounted || dismissed) return null

  const bgClass = BG_COLOR_MAP[data.bgColor ?? 'primary'] ?? 'bg-primary'
  const isDismissible = data.dismissible !== false

  const handleDismiss = () => {
    localStorage.setItem(`announcement-dismissed-${data.id}`, '1')
    setDismissed(true)
  }

  const content = (
    <div className={`${bgClass} text-primary-foreground py-2 px-4`}>
      <div className="max-w-7xl mx-auto flex items-center justify-center gap-2 relative">
        <span className="text-sm text-center">{data.text}</span>
        {isDismissible && (
          <button
            onClick={handleDismiss}
            aria-label="Ankündigung schliessen"
            className="absolute right-0 p-1 hover:opacity-70 focus-visible:ring-2 focus-visible:ring-offset-1 rounded touch-manipulation"
            style={{ touchAction: 'manipulation' }}
          >
            <X className="h-4 w-4" aria-hidden={true} />
          </button>
        )}
      </div>
    </div>
  )

  if (data.link) {
    return (
      <a href={data.link} className="block hover:opacity-90 transition-opacity">
        {content}
      </a>
    )
  }

  return content
}
```

### Referenz-Implementierung: PaginationBlock (URL-Builder)

```typescript
// Hilfsfunktion in frontend/components/blocks/pagination-block.tsx
function buildPageUrl(
  baseUrl: string,
  page: number,
  currentSort?: string,
  currentQuery?: string
): string {
  const params = new URLSearchParams()
  params.set('page', String(page))
  if (currentSort) params.set('sort', currentSort)
  if (currentQuery) params.set('q', currentQuery)
  return `${baseUrl}?${params.toString()}`
}

// Ellipsis-Logik: Seiten die immer angezeigt werden
function getVisiblePages(currentPage: number, totalPages: number): (number | '...')[] {
  if (totalPages <= 7) {
    return Array.from({ length: totalPages }, (_, i) => i + 1)
  }
  const pages: (number | '...')[] = [1]
  if (currentPage > 3) pages.push('...')
  for (let p = Math.max(2, currentPage - 1); p <= Math.min(totalPages - 1, currentPage + 1); p++) {
    pages.push(p)
  }
  if (currentPage < totalPages - 2) pages.push('...')
  pages.push(totalPages)
  return pages
}
```

### Referenz-Implementierung: SortBarBlock (useTransition)

```typescript
// frontend/components/blocks/sort-bar-block.tsx
'use client'

import { useTransition } from 'react'
import { useRouter } from 'next/navigation'
import type { BlockComponentProps, SortOption } from '@/lib/blocks/types'

interface SortBarData {
  currentSort: SortOption  // 'default' | 'price_asc' | 'price_desc' | 'newest' — Architecture-konform
  baseUrl: string
}

const SORT_OPTIONS: { value: SortOption; label: string }[] = [
  { value: 'default', label: 'Empfohlen' },
  { value: 'price_asc', label: 'Preis: aufsteigend' },
  { value: 'price_desc', label: 'Preis: absteigend' },
  { value: 'newest', label: 'Neueste zuerst' },
]

export function SortBarBlock({ data }: BlockComponentProps<SortBarData>) {
  const router = useRouter()
  const [isPending, startTransition] = useTransition()

  const handleChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const newSort = e.target.value as SortOption
    const params = new URLSearchParams()
    if (newSort !== 'default') params.set('sort', newSort)
    params.set('page', '1')
    const url = `${data.baseUrl}?${params.toString()}`
    startTransition(() => {
      router.push(url)
    })
  }

  return (
    <div className={`flex items-center gap-2 ${isPending ? 'opacity-70 pointer-events-none' : ''}`}>
      <label htmlFor="sort-select" className="text-sm text-text-secondary whitespace-nowrap">
        Sortieren nach:
      </label>
      <select
        id="sort-select"
        aria-label="Produkte sortieren"
        value={data.currentSort}
        onChange={handleChange}
        className="border border-border rounded-card px-3 py-1.5 text-sm bg-white dark:bg-surface-elevated dark:text-text-primary focus-visible:ring-2 focus-visible:ring-primary"
      >
        {SORT_OPTIONS.map(opt => (
          <option key={opt.value} value={opt.value}>{opt.label}</option>
        ))}
      </select>
    </div>
  )
}
```

### Referenz-Implementierung: global.yaml

```yaml
# themes/default/pages/global.yaml
sections:
  - columns: 1
    blocks:
      - type: announcement-bar
        content_source: inline
        params:
          props:
            id: "launch-2026"
            text: "Kostenloser Versand ab 100 Euro | Code: SOMMER10"
            link: "/kollektion/sale"
            dismissible: true
            bgColor: "primary"
```

### Referenz-Implementierung: loadGlobalConfig in page-config.ts

```typescript
// Neue Funktion hinzufügen zu frontend/lib/blocks/page-config.ts
import path from 'path'
import { readFileSync } from 'fs'
import { parse as parseYaml } from 'yaml'
import type { PageConfig } from './types'

export function loadGlobalConfig(theme: string): PageConfig {
  // 2-Tier: theme-spezifisch → default Fallback
  const candidates = [
    path.join(process.cwd(), 'themes', theme, 'pages', 'global.yaml'),
    path.join(process.cwd(), 'themes', 'default', 'pages', 'global.yaml'),
  ]
  for (const candidate of candidates) {
    try {
      const raw = readFileSync(candidate, 'utf-8')
      return parseYaml(raw) as PageConfig
    } catch {
      // Datei nicht gefunden, nächste versuchen
    }
  }
  // Kein global.yaml gefunden → leere Config (keine globalen Blocks)
  return { sections: [] }
}
```

### Referenz-Implementierung: layout.tsx Erweiterung

```typescript
// Erweiterung von frontend/app/layout.tsx
import { loadGlobalConfig } from '@/lib/blocks/page-config'
import { SectionRenderer } from '@/lib/blocks/section-renderer'

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const globalConfig = loadGlobalConfig(theme)

  return (
    <html lang="de" className={`${headingFont.variable} ${bodyFont.variable}`}>
      <body>
        <ApolloWrapper>
          <CartProvider>
            {/* Global Blocks (z.B. Announcement Bar) — oberhalb Header */}
            {globalConfig.sections.length > 0 && (
              <SectionRenderer
                sections={globalConfig.sections}
                skeletonMap={{}}
              />
            )}
            <Header />
            <div className="max-w-7xl mx-auto px-4 py-8">
              {children}
            </div>
            <Footer />
            <CookieConsentBanner />
            <PinterestTagInit />
          </CartProvider>
        </ApolloWrapper>
      </body>
    </html>
  )
}
```

---

## Links

- Discovery: `specs/phase-1/2026-02-25-shop-completeness/discovery.md`
- Architecture: `specs/phase-1/2026-02-25-shop-completeness/architecture.md`
- Referenz-Block (usp-bar): `frontend/components/blocks/usp-bar-block.tsx`
- Referenz-Registry: `frontend/lib/blocks/registry.ts`
- Referenz-Page (URL-Params): `frontend/app/kategorie/[slug]/page.tsx`
- Block-Types: `frontend/lib/blocks/types.ts`

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Frontend — Block Components (6 neue Dateien)
- [ ] `frontend/components/blocks/announcement-bar-block.tsx` — Client Component, `AnnouncementBarBlock`, localStorage-Dismiss, dismissible X-Button
- [ ] `frontend/components/blocks/breadcrumb-block.tsx` — Server Component, `BreadcrumbBlock`, aria-current, JSON-LD BreadcrumbList Schema
- [ ] `frontend/components/blocks/trust-badges-block.tsx` — Server Component, `TrustBadgesBlock`, lucide-react Icon-Map (truck/refresh/lock/shield/star/check/package)
- [ ] `frontend/components/blocks/pagination-block.tsx` — Server Component, `PaginationBlock`, pure HTML links, ellipsis, buildPageUrl helper
- [ ] `frontend/components/blocks/sort-bar-block.tsx` — Client Component, `SortBarBlock`, useRouter, useTransition, SORT_OPTIONS const
- [ ] `frontend/components/blocks/empty-state-block.tsx` — Server Component, `EmptyStateBlock`, SearchX icon, konfigurierbare Suggestion-Links

### Frontend — Block System Erweiterungen
- [ ] `frontend/lib/blocks/registry.ts` — 6 neue Einträge: `'announcement-bar'`, `'breadcrumb'`, `'trust-badges'`, `'pagination'`, `'sort-bar'`, `'empty-state'`
- [ ] `frontend/lib/blocks/types.ts` — 6 neue Interfaces: `AnnouncementBarData`, `BreadcrumbData`, `TrustBadgeData`, `PaginationData`, `SortBarData`, `EmptyStateData`; `InlineLoaderParams` Kompatibilität prüfen
- [ ] `frontend/lib/blocks/page-config.ts` — neue `loadGlobalConfig(theme: string): PageConfig` Funktion

### Frontend — Konfigurationsdateien (2 neue / 1 geändert)
- [ ] `frontend/themes/default/pages/global.yaml` — Global Config mit announcement-bar Block (`id: "launch-2026"`, dismissible, link, bgColor)
- [ ] `frontend/app/layout.tsx` — Erweiterung: `loadGlobalConfig()` aufrufen, `SectionRenderer` für global sections oberhalb `<Header />`

### Tests
- [ ] `tests/slices/shop-completeness/slice-01-cross-page-infrastruktur.test.ts` — Vitest Unit Tests für alle 6 Blocks + registry + loadGlobalConfig (exakt wie in `<test_spec>` definiert)
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind **Pflicht**
- Der Stop-Hook prüft automatisch ob alle Dateien existieren
- Bei fehlenden Dateien wird der Agent blockiert und muss nachfragen
- `layout.tsx` ist eine Modifikation (nicht neu erstellen, nur erweitern)
- `registry.ts`, `types.ts`, `page-config.ts` sind Modifikationen (nicht neu erstellen, nur erweitern)
