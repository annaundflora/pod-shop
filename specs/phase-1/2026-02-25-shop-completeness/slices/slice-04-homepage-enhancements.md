# Slice 4: Homepage Enhancements implementieren

> **Slice 4 von 6** für `Shop Completeness — Blocks & Pages`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-03-kategorie-page-enhancements.md` |
> | **Nächster:** | `slice-05-suchseite.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-04-homepage-enhancements` |
| **Test** | `pnpm test tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-cross-page-infrastruktur", "slice-03-kategorie-page-enhancements"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` — Vitest Unit/Component Tests (kein Playwright)
- **Dependencies**: Slice 1 (`loadGlobalConfig`, `announcement-bar` Block, `lib/blocks/registry.ts` Pattern) und Slice 3 (`GET_CATEGORY_META` Query, `GET_PRODUCTS_PAGINATED` Query, `PaginatedProductsResult` DTO, `WooCommerceLoaderParams` mit `source`/`slug` Feldern) müssen fertig sein

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren: `frontend/package.json` (next ^16.1.6, vitest ^3.0.0, typescript ^5.7.0, @tailwindcss/postcss ^4.0.0).

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts` |
| **Integration Command** | `pnpm test tests/slices/shop-completeness/` |
| **Acceptance Command** | `pnpm test tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts --reporter=verbose` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/api/health` |
| **Mocking Strategy** | `mock_external` |

**Erklaerung:**
- **Stack**: typescript-nextjs (Next.js 16 App Router, Vitest, Tailwind CSS v4)
- **Test Command**: Einzelner Slice-Testlauf via Vitest (Standard-Reporter)
- **Acceptance Command**: Identische Testdatei, aber mit `--reporter=verbose` fuer vollstaendige AC-Ausgabe (lesbar als Acceptance-Protokoll durch den Orchestrator)
- **Mocking Strategy**: `mock_external` — Apollo Server Client (`lib/apollo/server-client.ts`) wird per `vi.mock()` gemockt. `next/navigation` (`useRouter`) fuer NewsletterSignupBlock gemockt. `localStorage` wird via `jsdom` (bereits konfiguriert) bereitgestellt.

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Cross-Page Infrastruktur | Done | `slice-01-cross-page-infrastruktur.md` |
| 2 | Produkt-Page Enhancements | Done | `slice-02-produkt-page-enhancements.md` |
| 3 | Kategorie-Page Enhancements | Done | `slice-03-kategorie-page-enhancements.md` |
| 4 | Homepage Enhancements | Ready | `slice-04-homepage-enhancements.md` |
| 5 | Suchseite | Pending | `slice-05-suchseite.md` |
| 6 | Neue Pages | Pending | `slice-06-neue-pages.md` |

---

## Kontext & Ziel

Dieser Slice implementiert 3 neue Block-Typen für die Homepage sowie die `global.yaml`-Konfigurationsdatei für den `announcement-bar` Block aus Slice 1. Die Homepage erhält damit Trust-Signale (Testimonials), Newsletter-Capture und eine kuratierte Product-Collection.

**Problem:**
1. Keine Kundenzitate — fehlende Social Proof auf der Homepage
2. Kein Newsletter-Formular — keine E-Mail-Capture-Möglichkeit
3. Kein Featured-Collection Block — keine kuratierte Produkt-Präsentation
4. `announcement-bar` aus Slice 1 ist implementiert aber noch nicht konfiguriert (global.yaml fehlt) und in `layout.tsx` noch nicht eingebunden

**Zielbild:** 3 neue Block-Typen registriert, `global.yaml` erstellt, `app/layout.tsx` erweitert, `home.yaml` um alle neuen Blocks ergänzt — Homepage ist vollständig verkaufsbereit.

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → "New Block Components", "Global Blocks", "Extended home.yaml"

```
Block-System Pattern (bestehend):
  YAML Config → loadPageConfig() → resolveParams($route.*) → SectionRenderer
    → resolveBlock(type) → Component aus registry.ts
    → loadBlockData(content_source, params) → data-loaders.ts
    → <BlockComponent data={data} />

3 neue Blocks in diesem Slice:
  testimonials        → Server Component, inline source, Karten-Grid
  newsletter-signup   → Client Component, inline source, E-Mail-Formular
  featured-collection → Server Component, woocommerce source (featured_collection query)

Global Config Pattern (aus Slice 1 — wird hier AKTIVIERT):
  themes/default/pages/global.yaml → loadGlobalConfig(theme) → layout.tsx renders global blocks
  (loadGlobalConfig() ist in Slice 1 definiert, global.yaml + layout.tsx-Integration ist Deliverable dieses Slices)

featured_collection data-loader Branch (neu in diesem Slice):
  query: 'featured_collection' → GET_CATEGORY_META(slug) + GET_PRODUCTS_PAGINATED(categorySlug, first)
  → combined FeaturedCollectionData return
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `frontend/lib/blocks/registry.ts` | 3 neue Block-Typen registrieren: `testimonials`, `newsletter-signup`, `featured-collection` |
| `frontend/lib/blocks/data-loaders.ts` | `featured_collection` Branch hinzufügen: `GET_CATEGORY_META` + `GET_PRODUCTS_PAGINATED` parallel, kombiniert zu `FeaturedCollectionData` |
| `frontend/lib/blocks/types.ts` | `TestimonialsData`, `NewsletterSignupData`, `FeaturedCollectionData` Interfaces hinzufügen; `WooCommerceLoaderParams` query-Union um `'featured_collection'` erweitern |
| `frontend/components/blocks/testimonials-block.tsx` | Neue Datei: Server Component |
| `frontend/components/blocks/newsletter-signup-block.tsx` | Neue Datei: Client Component |
| `frontend/components/blocks/featured-collection-block.tsx` | Neue Datei: Server Component mit Skeleton |
| `frontend/app/layout.tsx` | `loadGlobalConfig(theme)` aufrufen, Global Blocks oberhalb `<Header />` rendern |
| `frontend/themes/default/pages/global.yaml` | Neue Datei: `announcement-bar` Block-Konfiguration |
| `frontend/themes/default/pages/home.yaml` | `testimonials`, `featured-collection`, `newsletter-signup` Sections hinzufügen |
| `frontend/app/page.tsx` | `skeletonMap` um `testimonials`, `newsletter-signup`, `featured-collection` erweitern |

### 2. Datenfluss

```
Browser Request: GET /
  ↓
app/layout.tsx (RSC)
  → loadGlobalConfig(theme)                         [aus Slice 1]
  → global.yaml: announcement-bar Block (inline)
  → <AnnouncementBarBlock data={...} />             [Client Component aus Slice 1]
     → prüft localStorage['announcement-dismissed-launch-2026']
     → zeigt Bar oder nichts
  ↓
app/page.tsx (RSC, ISR revalidate=60)
  → loadPageConfig('home', theme, {})
  → SectionRenderer iteriert sections aus home.yaml
  ↓
  → hero block (wordpress) — unverändert
  → product-grid block (woocommerce: featured_products) — unverändert
  → category-showcase block — unverändert
  → testimonials block (inline)
     → inlineLoader: TestimonialsData aus YAML props
     → TestimonialsBlock (Server): Karten-Grid
  → featured-collection block (woocommerce: featured_collection)
     → woocommerceLoader: GET_CATEGORY_META(slug) parallel GET_PRODUCTS_PAGINATED(slug, first)
     → FeaturedCollectionData: { category: {...}, products: { nodes: [...] } }
     → FeaturedCollectionBlock (Server): Fullwidth Bild + 4 Produkt-Cards
  → newsletter-signup block (inline)
     → inlineLoader: NewsletterSignupData aus YAML props
     → NewsletterSignupBlock (Client): E-Mail-Formular mit Validierung
  → usp-bar block — unverändert
```

### 3. Block-Spezifikationen

#### 3.1 `testimonials` Block

**Typ:** Server Component
**Content Source:** `inline`
**Data Type:** `TestimonialsData`

```typescript
// Architecture-Definition (architecture.md Zeile 349)
interface TestimonialsData {
  items: {
    name: string
    text: string
    rating: number       // 1–5 Integer
    location?: string    // optional (z.B. "Berlin")
  }[]
}
```

**Render-Regeln:**
- Grid-Layout: 1 Spalte Mobile / 2 Spalten Tablet (`sm:grid-cols-2`) / 3 Spalten Desktop (`lg:grid-cols-3`)
- Jede Karte: Sterne-Anzeige oben (ausgefüllte/leere Sterne als SVG oder Unicode), dann Zitat-Text, dann Name + Location
- Sterne: Exakt `rating` gefüllte Sterne + `5 - rating` leere Sterne (aria-label: "N von 5 Sternen")
- Karte: `rounded-card`, `shadow-card`, `bg-surface`, `p-6`
- Kein Hover-State (statischer Content)
- Zitat-Text: `<blockquote>` Semantik, kursiv
- Name + Location: `<footer>` innerhalb `<figure>` — z.B. "— Max Mustermann, Berlin"

**YAML-Beispiel:**
```yaml
- type: testimonials
  content_source: inline
  params:
    props:
      items:
        - name: "Maria K."
          text: "Absolut begeistert von der Qualität! Das Shirt sitzt perfekt und die Farben sind leuchtend."
          rating: 5
          location: "München"
        - name: "Thomas B."
          text: "Schnelle Lieferung und tolles Produkt. Werde definitiv wieder bestellen."
          rating: 4
          location: "Hamburg"
        - name: "Sandra M."
          text: "Schönes Design, gute Verarbeitung. Genau das was ich gesucht habe."
          rating: 5
```

#### 3.2 `newsletter-signup` Block

**Typ:** Client Component (`'use client'`)
**Content Source:** `inline`
**Data Type:** `NewsletterSignupData`

```typescript
// Architecture-Definition (architecture.md Zeile 350)
interface NewsletterSignupData {
  headline: string
  text: string
  buttonText: string
  placeholder: string
}
```

**State Machine (discovery.md):**
- `idle`: Leeres Formular, Button aktiv
- `loading`: Submit gedrückt, E-Mail valide — Button disabled, Spinner sichtbar
- `success`: `console.log` abgeschlossen, Erfolgsmeldung angezeigt, Formular ausgeblendet
- `error`: Fehler aufgetreten (theoretisch, da nur console.log), Fehlermeldung inline

**Validierung (client-seitig, vor Submit):**
- E-Mail-Format: RFC 5322-kompatibel via `/^[^\s@]+@[^\s@]+\.[^\s@]+$/` RegExp
- Leer: Inline-Fehler "Bitte gib deine E-Mail-Adresse ein"
- Ungültiges Format: Inline-Fehler "Bitte gib eine gültige E-Mail-Adresse ein"

**Submit-Logik:**
```
1. Validierung → bei Fehler: setError(...), return
2. setState('loading')
3. console.log('[Newsletter] E-Mail eingereicht:', email)  // Platzhalter
4. await new Promise(resolve => setTimeout(resolve, 500))  // Simuliertes Delay
5. setState('success')
```

**Render-Regeln:**
- Section mit `bg-surface-secondary` Hintergrund (abgesetzt vom Rest der Page)
- Zentriert, max-width begrenzt (`max-w-lg mx-auto`)
- Headline: `<h2>`, Text: `<p>`
- Input: `type="email"`, `autocomplete="email"`, `aria-label` für Accessibility, `spellcheck={false}`
- Button + Input in `<form onSubmit>`, semantisch korrekt
- Submit-Button: `type="submit"`, Spinner während loading
- Inline-Fehler: `role="alert"` unterhalb des Inputs
- Erfolgsmeldung: `role="status"`, `aria-live="polite"`, ersetzt das Formular

**YAML-Beispiel:**
```yaml
- type: newsletter-signup
  content_source: inline
  params:
    props:
      headline: "Bleib auf dem Laufenden"
      text: "Erhalte exklusive Angebote und neue Designs direkt in dein Postfach."
      buttonText: "Anmelden"
      placeholder: "deine@email.de…"
```

#### 3.3 `featured-collection` Block

**Typ:** Server Component
**Content Source:** `woocommerce`
**Data Type:** `FeaturedCollectionData`

```typescript
// Architecture-Definition (architecture.md Zeile 352)
interface FeaturedCollectionData {
  category: {
    name: string
    description: string
    slug: string
    image?: { sourceUrl: string; altText: string } | null
  }
  products: {
    nodes: ProductCardData[]   // exakt 4 Produkte (first: 4)
  }
}
```

**Skeleton:** `FeaturedCollectionBlockSkeleton` (animate-pulse, Fullwidth-Bild-Placeholder + 4 Karten-Placeholder)

**Data-Loader:** `featured_collection` Branch in `data-loaders.ts`
- Ruft parallel auf: `GET_CATEGORY_META(slug)` + `GET_PRODUCTS_PAGINATED(categorySlug: slug, first: 4)`
- Bei leerer Produktliste: Block rendert null (kein leerer Container)
- Bei fehlendem Kategorie-Bild: Section ohne Bild rendern (Titel + Produkte bleiben)

**Render-Regeln:**
- Layout: Fullwidth Bild oben (wenn vorhanden), darunter Kategorie-Name als `<h2>`, optional Beschreibung, darunter 4 Produkt-Cards horizontal
- Bild: `next/image`, `width={1200}`, `height={400}`, `object-cover`, `alt={category.image.altText}`
- Produkt-Cards: 1 Spalte Mobile / 2 Spalten Tablet / 4 Spalten Desktop
- "Alle ansehen" Link: `/kategorie/{category.slug}` — `<a href>`, nicht `onClick`
- Kein Hover auf der Section selbst; individuelle Karten-Hover via existierendes ProductCard-Pattern

**YAML-Beispiel:**
```yaml
- type: featured-collection
  content_source: woocommerce
  params:
    query: featured_collection
    slug: "t-shirts"
    first: 4
```

#### 3.4 `global.yaml` + `layout.tsx` Integration

**Neue Datei:** `themes/default/pages/global.yaml`
- Enthält ausschliesslich den `announcement-bar` Block (ein Section-Eintrag)
- Konfiguration gemäss Architecture-Spec (Zeile 436–451)

**Erweiterung `app/layout.tsx`:**
- `loadGlobalConfig(theme)` aus Slice 1 importieren und aufrufen (async RSC)
- Global Sections rendern über dem `<Header />` — d.h. als erstes Element in `<body>`
- `SectionRenderer` für globale Sections verwenden (selbes Pattern wie page.tsx)

### 4. `featured_collection` Branch im data-loader

```typescript
// lib/blocks/data-loaders.ts — featured_collection branch (neu)
} else if (params.query === 'featured_collection') {
  const slug = params.slug
  const first = Number(params.first ?? 4)
  if (!slug) return { data: null }

  // Parallel fetch (async-parallel Pattern)
  const [categoryResult, productsResult] = await Promise.all([
    getClient().query({
      query: GET_CATEGORY_META,
      variables: { slug },
    }),
    getClient().query({
      query: GET_PRODUCTS_PAGINATED,
      variables: { categorySlug: slug, first, orderby: undefined },
    }),
  ])

  const cat = categoryResult.data?.productCategory
  const products = productsResult.data?.products?.nodes ?? []

  if (!cat || products.length === 0) return { data: null }

  return {
    data: {
      category: {
        name: cat.name,
        description: cat.description ?? '',
        slug: cat.slug,
        image: cat.image ?? null,
      },
      products: { nodes: products.slice(0, first) },
    } satisfies FeaturedCollectionData,
  }
}
```

### 5. Neue TypeScript-Types in `lib/blocks/types.ts`

```typescript
// lib/blocks/types.ts (Ergänzungen)

export interface TestimonialsItem {
  name: string
  text: string
  rating: number
  location?: string
}

export interface TestimonialsData {
  items: TestimonialsItem[]
}

export interface NewsletterSignupData {
  headline: string
  text: string
  buttonText: string
  placeholder: string
}

export interface FeaturedCollectionData {
  category: {
    name: string
    description: string
    slug: string
    image?: { sourceUrl: string; altText: string } | null
  }
  products: {
    nodes: ProductCardData[]
  }
}
```

`WooCommerceLoaderParams.query` Union erhält `'featured_collection'` als neuen Wert.

### 6. Erweiterung `lib/blocks/registry.ts`

```typescript
// lib/blocks/registry.ts (Ergänzungen nach Slice 1-3 Registrierungen)
import { TestimonialsBlock } from '@/components/blocks/testimonials-block'
import { NewsletterSignupBlock } from '@/components/blocks/newsletter-signup-block'
import { FeaturedCollectionBlock, FeaturedCollectionBlockSkeleton } from '@/components/blocks/featured-collection-block'

// In blockRegistry Map:
'testimonials': TestimonialsBlock,
'newsletter-signup': NewsletterSignupBlock,
'featured-collection': FeaturedCollectionBlock,
```

### 7. Erweiterung `themes/default/pages/home.yaml`

Neue Sections werden in bestehende `home.yaml` eingefügt. Reihenfolge gemäss Architecture-Spec (architecture.md Zeile 590–598):

```yaml
# themes/default/pages/home.yaml — Vollständige Datei nach Erweiterung

sections:
  # Bestehend (unverändert)
  - columns: 1
    blocks:
      - type: hero
        content_source: wordpress
        params:
          page_slug: "/"

  # Bestehend (unverändert)
  - columns: 1
    blocks:
      - type: product-grid
        content_source: woocommerce
        params:
          query: featured_products
          first: 4

  # Bestehend (unverändert)
  - columns: 2
    blocks:
      - type: category-showcase
        content_source: woocommerce
        params:
          query: product_categories
          first: 6
      - type: category-showcase
        content_source: woocommerce
        params:
          query: product_categories
          first: 6

  # NEU: Testimonials (nach category-showcase)
  - columns: 1
    blocks:
      - type: testimonials
        content_source: inline
        params:
          props:
            items:
              - name: "Maria K."
                text: "Absolut begeistert von der Qualität! Das Shirt sitzt perfekt und die Farben sind leuchtend."
                rating: 5
                location: "München"
              - name: "Thomas B."
                text: "Schnelle Lieferung und tolles Produkt. Werde definitiv wieder bestellen."
                rating: 4
                location: "Hamburg"
              - name: "Sandra M."
                text: "Schönes Design, gute Verarbeitung. Genau das was ich gesucht habe."
                rating: 5

  # NEU: Featured Collection (nach testimonials)
  - columns: 1
    blocks:
      - type: featured-collection
        content_source: woocommerce
        params:
          query: featured_collection
          slug: "t-shirts"
          first: 4

  # NEU: Newsletter Signup (vor usp-bar)
  - columns: 1
    blocks:
      - type: newsletter-signup
        content_source: inline
        params:
          props:
            headline: "Bleib auf dem Laufenden"
            text: "Erhalte exklusive Angebote und neue Designs direkt in dein Postfach."
            buttonText: "Anmelden"
            placeholder: "deine@email.de…"

  # Bestehend (unverändert)
  - columns: 1
    blocks:
      - type: usp-bar
        content_source: inline
        params:
          props:
            items:
              - icon: "truck"
                text: "Kostenloser Versand ab 100€"
              - icon: "shield"
                text: "Sichere Zahlung"
              - icon: "refresh"
                text: "30 Tage Rückgaberecht"
              - icon: "star"
                text: "Premium Qualität"
```

### 8. `themes/default/pages/global.yaml` (neue Datei)

```yaml
# themes/default/pages/global.yaml
# Globale Blocks — werden in layout.tsx oberhalb der Navbar gerendert
# Nur Blocks die auf ALLEN Pages erscheinen sollen hier konfigurieren

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

### 9. Erweiterung `app/layout.tsx`

```typescript
// app/layout.tsx (Erweiterung — Auszug, relevante Änderungen)
import { loadGlobalConfig } from '@/lib/blocks/page-config'
import { SectionRenderer } from '@/lib/blocks/section-renderer'

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const globalConfig = await loadGlobalConfig(theme)

  return (
    <html lang="de">
      <body>
        {/* Global Blocks — z.B. announcement-bar */}
        <SectionRenderer sections={globalConfig.sections} skeletonMap={{}} />
        <Header />
        <main id="main-content">
          {children}
        </main>
        <Footer />
      </body>
    </html>
  )
}
```

> **Hinweis:** `loadGlobalConfig(theme)` ist in Slice 1 als neue Funktion in `lib/blocks/page-config.ts` definiert. Falls `global.yaml` nicht existiert oder leer ist, gibt sie `{ sections: [] }` zurück — kein Fehler.

### 10. Erweiterung `app/page.tsx` (skeletonMap)

```typescript
// app/page.tsx — skeletonMap Erweiterung
skeletonMap={{
  // ... bestehende Einträge ...
  'testimonials': null,           // inline, kein async — kein Skeleton nötig
  'newsletter-signup': null,      // inline, kein async — kein Skeleton nötig
  'featured-collection': <FeaturedCollectionBlockSkeleton />,
}}
```

### 11. Abhängigkeiten

- **Bestehend (wiederverwendet):** `lucide-react ^0.575.0`, `next/image`, Apollo Server Client (`lib/apollo/server-client.ts`), `GET_CATEGORY_META` Query (Slice 3), `GET_PRODUCTS_PAGINATED` Query (Slice 3), `loadGlobalConfig()` (Slice 1), `AnnouncementBarBlock` (Slice 1)
- **Neu (keine neuen npm-Pakete):** Nur neue Komponenten-Dateien und YAML-Dateien

---

## UI Anforderungen

### Wireframe (aus discovery.md)

> **Quelle:** `discovery.md` → "UI Layout & Context" → "Screen: Homepage (erweitert)"

```
ANNOUNCEMENT BAR (Global, über Header — bereits aus Slice 1, jetzt aktiviert)
┌──────────────────────────────────────────────────────────────────────────┐
│  Kostenloser Versand ab 100 Euro | Code: SOMMER10                    [X] │
└──────────────────────────────────────────────────────────────────────────┘

TESTIMONIALS (neue Section, nach category-showcase)
┌─────────────────┐ ┌─────────────────┐ ┌─────────────────┐
│  ★★★★★          │ │  ★★★★☆          │ │  ★★★★★          │
│  "Absolut       │ │  "Schnelle      │ │  "Schönes       │
│   begeistert"   │ │   Lieferung"    │ │   Design"       │
│  — Maria K.     │ │  — Thomas B.    │ │  — Sandra M.    │
│    München      │ │    Hamburg      │ │                 │
└─────────────────┘ └─────────────────┘ └─────────────────┘

FEATURED COLLECTION (neue Section, nach testimonials)
┌──────────────────────────────────────────────────────────────────────────┐
│                     [Kategorie-Bild Fullwidth]                           │
│                          T-Shirts                                        │
│            Die besten Shirts in Premium-Qualität                         │
│                       [Alle ansehen →]                                   │
├──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐
│ Produkt 1│ │ Produkt 2│ │ Produkt 3│ │ Produkt 4│
│  [Bild]  │ │  [Bild]  │ │  [Bild]  │ │  [Bild]  │
│  Titel   │ │  Titel   │ │  Titel   │ │  Titel   │
│  €XX,XX  │ │  €XX,XX  │ │  €XX,XX  │ │  €XX,XX  │
└──────────┘ └──────────┘ └──────────┘ └──────────┘

NEWSLETTER SIGNUP (neue Section, vor usp-bar)
┌──────────────────────────────────────────────────────────────────────────┐
│                     Bleib auf dem Laufenden                              │
│        Erhalte exklusive Angebote und neue Designs direkt in             │
│                      dein Postfach.                                      │
│         ┌─────────────────────────────────┐  ┌──────────┐              │
│         │ deine@email.de…                 │  │ Anmelden │              │
│         └─────────────────────────────────┘  └──────────┘              │
└──────────────────────────────────────────────────────────────────────────┘
```

**Referenz Skills für UI-Implementation:**
- `.claude/skills/react-best-practices/SKILL.md` — `async-parallel` (featured_collection), `async-suspense-boundaries`, `rerender-transitions` (newsletter loading state)
- `.claude/skills/web-design/SKILL.md` — Accessibility (form labels, aria-live, aria-label), Forms (submit button state, inline errors), Animation (prefers-reduced-motion)
- `.claude/skills/tailwind-v4/SKILL.md` — Design Tokens (`bg-surface`, `rounded-card`, `shadow-card`), Container Queries, Mobile-first

### 1. TestimonialsBlock

**Komponenten & Dateien:**
- `frontend/components/blocks/testimonials-block.tsx` — Server Component

**Verhalten:**
- Rendert `items` als Karten-Grid
- Sterne-Rating: Loop über 1–5, gefüllt wenn `i <= rating`

**Zustände:**
- Loading: nicht anwendbar (inline, synchron)
- Error: nicht anwendbar
- Empty: wenn `items.length === 0` → Block rendert null

**Design Patterns:**
- [x] Accessibility: `<figure>` + `<blockquote>` + `<figcaption>` Semantik; Sterne-Icon `aria-hidden={true}`; Container `aria-label="Kundenbewertungen"` auf umschliessender Section
- [x] Responsive: `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3`, gap-6
- [x] Performance: Server Component, kein Client-Bundle-Impact
- [x] Theme Tokens: `bg-surface`, `rounded-card`, `shadow-card`, `text-text-primary`, `text-text-secondary`

### 2. NewsletterSignupBlock

**Komponenten & Dateien:**
- `frontend/components/blocks/newsletter-signup-block.tsx` — Client Component (`'use client'`)

**Verhalten:**
- `useState` für: `email`, `status: 'idle' | 'loading' | 'success' | 'error'`, `errorMessage`
- `handleSubmit`: Validierung → setState loading → console.log → setTimeout(500ms) → setState success
- Bei success: Formular ausblenden, Erfolgsmeldung mit Checkmark-Icon zeigen

**Zustände:**
- `idle`: Formular mit E-Mail-Input + Button
- `loading`: Button disabled + Spinner, Input readonly
- `success`: Formular ersetzt durch "Danke für deine Anmeldung!" Text + Check-Icon
- `error`: Inline-Fehler unter Input, Formular bleibt aktiv

**Design Patterns:**
- [x] Accessibility: `<label htmlFor>` für E-Mail-Input; `aria-live="polite"` auf Status-Container; `aria-invalid` auf Input bei Fehler; `aria-describedby` auf Input wenn Fehler vorhanden; Submit-Button `aria-label` wenn nur Icon
- [x] Forms: `type="email"`, `autocomplete="email"`, `spellCheck={false}`, kein `onPaste` Block; Submit Button bleibt enabled bis Validierungsfehler
- [x] Animation: `prefers-reduced-motion` beachten für success-Transition; nur `opacity` / `transform` animieren
- [x] Touch: `touch-action: manipulation` auf Button; min. 44px Touch-Target

### 3. FeaturedCollectionBlock

**Komponenten & Dateien:**
- `frontend/components/blocks/featured-collection-block.tsx` — Server Component + `FeaturedCollectionBlockSkeleton` Export

**Verhalten:**
- Rendert Kategorie-Bild (wenn vorhanden) + Name + Beschreibung + 4 Produkt-Cards
- "Alle ansehen" Link: semantisches `<a href>` zu `/kategorie/{slug}`
- Wenn `data === null`: Block rendert null (kein leerer Container)

**Zustände:**
- Loading: `FeaturedCollectionBlockSkeleton` — animate-pulse Fullwidth-Bar (h-48) + 4 Karten-Placeholder
- Error: null (kein sichtbarer Fehler-State)
- Empty (0 Produkte): null

**Design Patterns:**
- [x] Accessibility: `<section aria-label={category.name}>`; Bild mit explizitem `alt`; "Alle ansehen" Link `aria-label="Alle {name} ansehen"`
- [x] Images: `next/image` mit `width={1200}` `height={400}`, `loading="lazy"` (below fold), `sizes` Attribut für responsive Breiten
- [x] Performance: `async-parallel` in data-loader (GET_CATEGORY_META + GET_PRODUCTS_PAGINATED parallel)
- [x] Responsive: `grid-cols-1 sm:grid-cols-2 lg:grid-cols-4` für Produkt-Cards; Bild `object-cover w-full`

### 4. Accessibility-Checkliste

- [x] Alle interaktiven Elemente haben focus-visible states
- [x] Icon-only buttons haben aria-label (X-Button im AnnouncementBar — Slice 1 Concern)
- [x] Form inputs haben labels (newsletter-signup: `<label htmlFor="newsletter-email">`)
- [x] Images haben alt text und dimensions (featured-collection: `next/image` mit `alt`)
- [x] Sterne-Bewertung nicht nur via Farbe kommuniziert (aria-label "N von 5 Sternen")

---

## Acceptance Criteria

1) GIVEN die Homepage wird geladen
   WHEN `global.yaml` einen `announcement-bar` Block mit `id: "launch-2026"` konfiguriert
   THEN rendert `layout.tsx` die Announcement Bar oberhalb des `<Header />` auf der Homepage und auf allen anderen Pages

2) GIVEN die Announcement Bar ist sichtbar
   WHEN der User auf den X-Button klickt
   THEN wird `localStorage['announcement-dismissed-launch-2026']` gesetzt und die Bar verschwindet ohne Page-Reload

3) GIVEN der User hat die Announcement Bar bereits dismissed (localStorage gesetzt)
   WHEN die Homepage neu geladen wird
   THEN wird die Announcement Bar nicht angezeigt (kein Flicker)

4) GIVEN die Homepage wird geladen
   WHEN der `testimonials` Block in `home.yaml` konfiguriert ist
   THEN werden 3 Testimonial-Karten in einem 1/2/3-Spalten-Grid angezeigt mit Name, Zitat und korrekter Sterne-Anzahl

5) GIVEN der `testimonials` Block hat `items` mit `rating: 5`
   WHEN die Karte gerendert wird
   THEN zeigt sie exakt 5 gefüllte Sterne und das aria-label lautet "5 von 5 Sternen"

6) GIVEN die Homepage wird geladen
   WHEN der `featured-collection` Block mit `slug: "t-shirts"` konfiguriert ist
   THEN wird das Kategorie-Bild (wenn vorhanden), der Kategorie-Titel, die Beschreibung und exakt 4 Produkt-Cards angezeigt

7) GIVEN der `featured-collection` Block ist geladen
   WHEN der User auf "Alle ansehen" klickt
   THEN navigiert der Browser zu `/kategorie/t-shirts` (semantischer `<a href>` Link, kein onClick)

8) GIVEN die Homepage wird geladen
   WHEN der `newsletter-signup` Block konfiguriert ist
   THEN sind Headline, Beschreibungstext, E-Mail-Input und Submit-Button sichtbar

9) GIVEN das Newsletter-Formular ist im Zustand `idle`
   WHEN der User eine ungültige E-Mail eingibt (z.B. "kein-at-zeichen") und auf "Anmelden" klickt
   THEN erscheint ein Inline-Fehler "Bitte gib eine gültige E-Mail-Adresse ein" und kein `console.log` wird ausgelöst

10) GIVEN das Newsletter-Formular ist im Zustand `idle`
    WHEN der User eine valide E-Mail eingibt (z.B. "test@example.com") und auf "Anmelden" klickt
    THEN wechselt der Button zu Loading-State (Spinner, disabled), danach erscheint die Erfolgsmeldung und das Formular wird ausgeblendet

11) GIVEN die Homepage wird geladen
    WHEN der `featured-collection` Block für eine Kategorie ohne Bild konfiguriert ist
    THEN wird der Block ohne Bild gerendert (Titel + Produkt-Cards bleiben sichtbar)

12) GIVEN die `themes/default/pages/global.yaml` Datei nicht existiert
    WHEN `loadGlobalConfig(theme)` aufgerufen wird
    THEN wird `{ sections: [] }` zurückgegeben (kein Fehler, keine leere Bar)

---

## Testfälle

**WICHTIG:** Tests müssen VOR der Implementierung definiert werden. Der Orchestrator führt diese Tests automatisch nach der Slice-Implementierung aus.

### Test-Datei

**Für diesen Slice:** `tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import React from 'react'

// --- TestimonialsBlock ---

describe('TestimonialsBlock', () => {
  it('should render the correct number of testimonial cards', async () => {
    const { TestimonialsBlock } = await import('@/components/blocks/testimonials-block')
    const data = {
      items: [
        { name: 'Maria K.', text: 'Super Qualität!', rating: 5, location: 'München' },
        { name: 'Thomas B.', text: 'Schnelle Lieferung.', rating: 4 },
        { name: 'Sandra M.', text: 'Tolles Design.', rating: 5 },
      ],
    }
    render(React.createElement(TestimonialsBlock, { data }))
    expect(screen.getAllByRole('figure').length).toBe(3)
  })

  it('should render the correct number of filled stars for rating 4', async () => {
    const { TestimonialsBlock } = await import('@/components/blocks/testimonials-block')
    const data = {
      items: [{ name: 'Test User', text: 'Gutes Produkt.', rating: 4 }],
    }
    render(React.createElement(TestimonialsBlock, { data }))
    // aria-label auf Sterne-Container
    expect(screen.getByLabelText('4 von 5 Sternen')).toBeTruthy()
  })

  it('should render testimonial text and author name', async () => {
    const { TestimonialsBlock } = await import('@/components/blocks/testimonials-block')
    const data = {
      items: [{ name: 'Anna L.', text: 'Wirklich beeindruckend!', rating: 5 }],
    }
    render(React.createElement(TestimonialsBlock, { data }))
    expect(screen.getByText('Wirklich beeindruckend!')).toBeTruthy()
    expect(screen.getByText(/Anna L\./)).toBeTruthy()
  })

  it('should render null when items array is empty', async () => {
    const { TestimonialsBlock } = await import('@/components/blocks/testimonials-block')
    const data = { items: [] }
    const { container } = render(React.createElement(TestimonialsBlock, { data }))
    expect(container.firstChild).toBeNull()
  })

  it('should render location when provided', async () => {
    const { TestimonialsBlock } = await import('@/components/blocks/testimonials-block')
    const data = {
      items: [{ name: 'Maria K.', text: 'Super!', rating: 5, location: 'München' }],
    }
    render(React.createElement(TestimonialsBlock, { data }))
    expect(screen.getByText(/München/)).toBeTruthy()
  })
})

// --- NewsletterSignupBlock ---

describe('NewsletterSignupBlock', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(console, 'log').mockImplementation(() => {})
  })

  it('should render headline, input and submit button in idle state', async () => {
    const { NewsletterSignupBlock } = await import('@/components/blocks/newsletter-signup-block')
    const data = {
      headline: 'Bleib auf dem Laufenden',
      text: 'Exklusive Angebote.',
      buttonText: 'Anmelden',
      placeholder: 'deine@email.de…',
    }
    render(React.createElement(NewsletterSignupBlock, { data }))
    expect(screen.getByRole('heading', { name: 'Bleib auf dem Laufenden' })).toBeTruthy()
    expect(screen.getByRole('textbox')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Anmelden' })).toBeTruthy()
  })

  it('should show inline error for empty email on submit', async () => {
    const { NewsletterSignupBlock } = await import('@/components/blocks/newsletter-signup-block')
    const data = {
      headline: 'Newsletter',
      text: 'Anmelden.',
      buttonText: 'Anmelden',
      placeholder: 'email…',
    }
    render(React.createElement(NewsletterSignupBlock, { data }))
    fireEvent.click(screen.getByRole('button', { name: 'Anmelden' }))
    expect(screen.getByRole('alert')).toBeTruthy()
    expect(screen.getByText(/E-Mail-Adresse ein/)).toBeTruthy()
  })

  it('should show inline error for invalid email format on submit', async () => {
    const { NewsletterSignupBlock } = await import('@/components/blocks/newsletter-signup-block')
    const data = {
      headline: 'Newsletter',
      text: 'Anmelden.',
      buttonText: 'Anmelden',
      placeholder: 'email…',
    }
    render(React.createElement(NewsletterSignupBlock, { data }))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'kein-at-zeichen' } })
    fireEvent.click(screen.getByRole('button', { name: 'Anmelden' }))
    expect(screen.getByText(/gültige E-Mail-Adresse/)).toBeTruthy()
  })

  it('should call console.log with email on valid submit', async () => {
    vi.useFakeTimers()
    const { NewsletterSignupBlock } = await import('@/components/blocks/newsletter-signup-block')
    const data = {
      headline: 'Newsletter',
      text: 'Anmelden.',
      buttonText: 'Anmelden',
      placeholder: 'email…',
    }
    render(React.createElement(NewsletterSignupBlock, { data }))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'test@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Anmelden' }))
    expect(console.log).toHaveBeenCalledWith('[Newsletter] E-Mail eingereicht:', 'test@example.com')
    vi.useRealTimers()
  })

  it('should show success message after valid submit', async () => {
    vi.useFakeTimers()
    const { NewsletterSignupBlock } = await import('@/components/blocks/newsletter-signup-block')
    const data = {
      headline: 'Newsletter',
      text: 'Anmelden.',
      buttonText: 'Anmelden',
      placeholder: 'email…',
    }
    render(React.createElement(NewsletterSignupBlock, { data }))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'test@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Anmelden' }))
    // Advance timer to complete the 500ms delay
    await vi.runAllTimersAsync()
    await waitFor(() => {
      expect(screen.getByRole('status')).toBeTruthy()
    })
    vi.useRealTimers()
  })

  it('should disable submit button in loading state', async () => {
    vi.useFakeTimers()
    const { NewsletterSignupBlock } = await import('@/components/blocks/newsletter-signup-block')
    const data = {
      headline: 'Newsletter',
      text: 'Anmelden.',
      buttonText: 'Anmelden',
      placeholder: 'email…',
    }
    render(React.createElement(NewsletterSignupBlock, { data }))
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'test@example.com' } })
    fireEvent.click(screen.getByRole('button', { name: 'Anmelden' }))
    // Immediately after click, before timer resolves
    expect(screen.getByRole('button').hasAttribute('disabled')).toBe(true)
    vi.useRealTimers()
  })
})

// --- FeaturedCollectionBlock ---

describe('FeaturedCollectionBlock', () => {
  it('should render category name and products', async () => {
    const { FeaturedCollectionBlock } = await import('@/components/blocks/featured-collection-block')
    const data = {
      category: {
        name: 'T-Shirts',
        description: 'Premium T-Shirts',
        slug: 't-shirts',
        image: null,
      },
      products: {
        nodes: [
          { slug: 'shirt-1', name: 'Shirt 1', price: '19.99', image: { sourceUrl: '/1.jpg', altText: 'Shirt 1' } },
          { slug: 'shirt-2', name: 'Shirt 2', price: '24.99', image: { sourceUrl: '/2.jpg', altText: 'Shirt 2' } },
          { slug: 'shirt-3', name: 'Shirt 3', price: '19.99', image: { sourceUrl: '/3.jpg', altText: 'Shirt 3' } },
          { slug: 'shirt-4', name: 'Shirt 4', price: '29.99', image: { sourceUrl: '/4.jpg', altText: 'Shirt 4' } },
        ],
      },
    }
    render(React.createElement(FeaturedCollectionBlock, { data }))
    expect(screen.getByRole('heading', { name: 'T-Shirts' })).toBeTruthy()
    // 4 Produkt-Cards
    expect(screen.getAllByRole('link').length).toBeGreaterThanOrEqual(4)
  })

  it('should render "Alle ansehen" link pointing to /kategorie/t-shirts', async () => {
    const { FeaturedCollectionBlock } = await import('@/components/blocks/featured-collection-block')
    const data = {
      category: {
        name: 'T-Shirts',
        description: '',
        slug: 't-shirts',
        image: null,
      },
      products: {
        nodes: [
          { slug: 'shirt-1', name: 'Shirt 1', price: '19.99', image: null },
        ],
      },
    }
    render(React.createElement(FeaturedCollectionBlock, { data }))
    const alleAnsehenLink = screen.getByRole('link', { name: /Alle.*T-Shirts.*ansehen/i })
    expect(alleAnsehenLink.getAttribute('href')).toBe('/kategorie/t-shirts')
  })

  it('should render null when data is null', async () => {
    const { FeaturedCollectionBlock } = await import('@/components/blocks/featured-collection-block')
    const { container } = render(React.createElement(FeaturedCollectionBlock, { data: null }))
    expect(container.firstChild).toBeNull()
  })

  it('should render FeaturedCollectionBlockSkeleton as animate-pulse placeholder', async () => {
    const { FeaturedCollectionBlockSkeleton } = await import('@/components/blocks/featured-collection-block')
    const { container } = render(React.createElement(FeaturedCollectionBlockSkeleton))
    // Skeleton contains animate-pulse elements
    expect(container.querySelector('.animate-pulse')).toBeTruthy()
  })
})

// --- featured_collection data-loader ---

describe('featured_collection data-loader', () => {
  it('should return FeaturedCollectionData combining category and products', async () => {
    vi.mock('@/lib/apollo/server-client', () => ({
      getClient: () => ({
        query: vi.fn().mockImplementation(({ query }) => {
          // Distinguish GET_CATEGORY_META vs GET_PRODUCTS_PAGINATED by operation name
          if (query?.definitions?.[0]?.name?.value === 'GetCategoryMeta') {
            return Promise.resolve({
              data: {
                productCategory: {
                  name: 'T-Shirts',
                  description: 'Premium Shirts',
                  slug: 't-shirts',
                  count: 12,
                  image: { sourceUrl: '/cat.jpg', altText: 'T-Shirts' },
                },
              },
            })
          }
          return Promise.resolve({
            data: {
              products: {
                nodes: [
                  { slug: 'shirt-1', name: 'Shirt 1', price: '19.99' },
                  { slug: 'shirt-2', name: 'Shirt 2', price: '24.99' },
                  { slug: 'shirt-3', name: 'Shirt 3', price: '19.99' },
                  { slug: 'shirt-4', name: 'Shirt 4', price: '29.99' },
                ],
              },
              productCategory: { name: 'T-Shirts', count: 12 },
            },
          })
        }),
      }),
    }))
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({ query: 'featured_collection', slug: 't-shirts', first: 4 })
    expect(result.data).not.toBeNull()
    expect(result.data?.category?.name).toBe('T-Shirts')
    expect(result.data?.products?.nodes?.length).toBe(4)
  })

  it('should return null data when no products are found', async () => {
    vi.mock('@/lib/apollo/server-client', () => ({
      getClient: () => ({
        query: vi.fn().mockResolvedValue({
          data: {
            productCategory: { name: 'Leer', description: '', slug: 'leer', count: 0, image: null },
            products: { nodes: [] },
          },
        }),
      }),
    }))
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({ query: 'featured_collection', slug: 'leer', first: 4 })
    expect(result.data).toBeNull()
  })

  it('should return null data when slug is missing', async () => {
    const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')
    const result = await woocommerceLoader({ query: 'featured_collection' })
    expect(result.data).toBeNull()
  })
})

// --- global.yaml / loadGlobalConfig ---

describe('loadGlobalConfig', () => {
  it('should load global.yaml and return sections with announcement-bar', async () => {
    const { loadGlobalConfig } = await import('@/lib/blocks/page-config')
    const config = await loadGlobalConfig('default')
    expect(config.sections).toBeDefined()
    expect(config.sections.length).toBeGreaterThanOrEqual(1)
    const announcementBlock = config.sections[0]?.blocks?.find(
      (b: { type: string }) => b.type === 'announcement-bar'
    )
    expect(announcementBlock).toBeDefined()
  })

  it('should return empty sections when global.yaml does not exist', async () => {
    const { loadGlobalConfig } = await import('@/lib/blocks/page-config')
    // 'nonexistent' theme has no global.yaml — falls back gracefully
    const config = await loadGlobalConfig('nonexistent-theme-xyz')
    expect(config.sections).toEqual([])
  })
})
```
</test_spec>

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig und vollständig
- [x] Sicherheits-/Privacy-Aspekte bedacht (Newsletter: nur console.log, kein Backend-Call, keine E-Mail-Speicherung)
- [x] UX/Copy final: deutsche UI-Texte, Zitate im YAML konfigurierbar
- [x] Rollout-Plan: Kein Feature-Flag nötig — neue YAML-Sections sind additiv; `home.yaml` Erweiterung bricht nichts

---

## Skill Verification (UI-Implementation)

### React Best Practices Verification

**Critical Priority:**
- [x] `async-parallel`: `Promise.all([GET_CATEGORY_META, GET_PRODUCTS_PAGINATED])` im `featured_collection` data-loader Branch
- [x] `bundle-dynamic-imports`: `FeaturedCollectionBlock` hat Skeleton; async Blocks sind in Suspense — kein separates dynamic import nötig (Server Components)

**High Priority:**
- [x] `server-cache-react`: Apollo `getClient()` nutzt `React.cache()` bereits (bestehend in `lib/apollo/server-client.ts`)
- [x] `async-suspense-boundaries`: `FeaturedCollectionBlock` mit `FeaturedCollectionBlockSkeleton` in Suspense via `SectionRenderer`

**Medium Priority:**
- [x] `rerender-transitions`: `useTransition` in `NewsletterSignupBlock` fuer Loading-State-Update
- [x] `rerender-dependencies`: Primitive State-Werte (`email: string`, `status: string`) statt Objekte in `useState`

### Web Design Guidelines Verification

**Accessibility:**
- [x] Icon-only buttons haben `aria-label` (falls vorhanden — kein reiner Icon-Button in diesem Slice)
- [x] Form inputs haben assoziierte Labels (`newsletter-signup`: `<label htmlFor="newsletter-email">`)
- [x] Images haben `width`/`height` (featured-collection: `next/image` mit expliziten Dimensionen)
- [x] Keyboard handler: `<form onSubmit>` statt nur Button-onClick
- [x] Focus-visible states: Tailwind `focus-visible:ring-2` auf interaktiven Elementen

**Animation & Motion:**
- [x] `prefers-reduced-motion` beachtet in Newsletter-Signup success-Transition
- [x] Nur `opacity` / `transform` fuer Animationen (kein `height`, `width`)

**Touch & Mobile:**
- [x] `touch-action: manipulation` auf Newsletter-Button
- [x] Touch targets mindestens 44px (Tailwind `min-h-[44px]` auf Button)

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [x] Keine hardcoded Farben oder Radii — nur `bg-surface`, `bg-surface-secondary`, `rounded-card`, `shadow-card`, `text-text-primary`, `text-text-secondary`, `bg-primary`, `text-primary`
- [x] Semantic color naming

**Responsive:**
- [x] Mobile-first: `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3` (Testimonials), `grid-cols-1 sm:grid-cols-2 lg:grid-cols-4` (Featured Collection Products)

---

## Constraints & Hinweise

**Betrifft:**
- `app/layout.tsx` (bestehende Datei, minimale Änderung)
- `themes/default/pages/home.yaml` (bestehende Datei, additive Erweiterung)
- `app/page.tsx` (bestehende Datei, skeletonMap Erweiterung)

**Newsletter Backend:**
- Explizit Out of Scope: Kein Mailchimp, kein WooCommerce-Kunden-API-Call
- Submit löst ausschliesslich `console.log('[Newsletter] E-Mail eingereicht:', email)` aus

**ISR auf Homepage:**
- `revalidate = 60` ist bereits in `app/page.tsx` konfiguriert — kein Change nötig
- `featured-collection` profitiert automatisch vom ISR-Cache

**announcement-bar in home.yaml:**
- `announcement-bar` Block darf NICHT in `home.yaml` konfiguriert werden (ist global via `global.yaml`)
- `home.yaml` enthält ausschliesslich: `testimonials`, `featured-collection`, `newsletter-signup` als neue Blocks

**FeaturedCollectionData type safety:**
- `data: null` Return bei fehlendem Slug, fehlender Kategorie oder 0 Produkten — Block rendert dann `null`

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| `slice-01-cross-page-infrastruktur` | `loadGlobalConfig(theme: string): Promise<PageConfig>` | Function | Exportiert aus `lib/blocks/page-config.ts`; gibt `{ sections: [] }` zurück wenn `global.yaml` fehlt |
| `slice-01-cross-page-infrastruktur` | `AnnouncementBarBlock` | Component | Registriert in `lib/blocks/registry.ts`; `content_source: inline`; `AnnouncementBarData` Type |
| `slice-01-cross-page-infrastruktur` | `lib/blocks/registry.ts` Pattern | Module | Block-Registration Pattern; `SectionRenderer` unterstützt `skeletonMap` |
| `slice-03-kategorie-page-enhancements` | `GET_CATEGORY_META` | GraphQL Query | Exportiert aus `lib/graphql/queries.ts`; Signature: `query GetCategoryMeta($slug: ID!)` |
| `slice-03-kategorie-page-enhancements` | `GET_PRODUCTS_PAGINATED` | GraphQL Query | Exportiert aus `lib/graphql/queries.ts`; nullable `$categorySlug: String` |
| `slice-03-kategorie-page-enhancements` | `PaginatedProductsResult` | TypeScript Interface | Exportiert aus `lib/blocks/types.ts`; `products: { nodes: ProductCardData[] }` |
| `slice-03-kategorie-page-enhancements` | `WooCommerceLoaderParams` mit `query`, `slug`, `first`, `sort` | TypeScript Interface | Wird um `'featured_collection'` erweitert |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `TestimonialsBlock` | Component | `app/page.tsx` (via SectionRenderer + registry) | `BlockComponentProps<TestimonialsData>` |
| `NewsletterSignupBlock` | Component | `app/page.tsx` (via SectionRenderer + registry) | `BlockComponentProps<NewsletterSignupData>` |
| `FeaturedCollectionBlock` | Component | `app/page.tsx` (via SectionRenderer + registry) | `BlockComponentProps<FeaturedCollectionData>` |
| `FeaturedCollectionBlockSkeleton` | Component | `app/page.tsx` (skeletonMap) | `() => React.ReactElement` |
| `TestimonialsData` | TypeScript Interface | `lib/blocks/types.ts` | `{ items: TestimonialsItem[] }` |
| `NewsletterSignupData` | TypeScript Interface | `lib/blocks/types.ts` | `{ headline, text, buttonText, placeholder }` |
| `FeaturedCollectionData` | TypeScript Interface | `lib/blocks/types.ts` | `{ category: {...}, products: { nodes: ProductCardData[] } }` |
| `themes/default/pages/global.yaml` | YAML Config | `app/layout.tsx` via `loadGlobalConfig()` | `sections[0].blocks[0].type === 'announcement-bar'` |
| `featured_collection` query branch | data-loader dispatch | `featured-collection-block.tsx` | `woocommerceLoader({ query: 'featured_collection', slug, first })` |

### Integration Validation Tasks

- [x] `loadGlobalConfig('default')` gibt `{ sections: [...] }` mit `announcement-bar` Block zurück
- [x] `GET_CATEGORY_META` und `GET_PRODUCTS_PAGINATED` sind in `lib/graphql/queries.ts` exportiert (Slice 3 Prerequisite)
- [x] `woocommerceLoader({ query: 'featured_collection', slug: 't-shirts', first: 4 })` gibt `FeaturedCollectionData` zurück
- [x] `registry.ts` enthält `'testimonials'`, `'newsletter-signup'`, `'featured-collection'` nach diesem Slice
- [x] `home.yaml` enthält keine `announcement-bar` Section (ist in `global.yaml`)
- [x] `app/layout.tsx` ruft `loadGlobalConfig()` auf und rendert global sections vor `<Header />`

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind PFLICHT-Deliverables. Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird. Abweichung nur mit expliziter Begründung im Commit erlaubt.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `TestimonialsData` Interface | Technische Umsetzung § 5 | YES | Exakt diese Felder: `items: TestimonialsItem[]` mit `name`, `text`, `rating`, `location?` |
| `NewsletterSignupData` Interface | Technische Umsetzung § 5 | YES | Exakt diese Felder: `headline`, `text`, `buttonText`, `placeholder` |
| `FeaturedCollectionData` Interface | Technische Umsetzung § 5 | YES | Exakt diese Felder: `category` (mit `image?`) + `products.nodes` |
| `featured_collection` data-loader Branch | Technische Umsetzung § 4 | YES | `Promise.all([GET_CATEGORY_META, GET_PRODUCTS_PAGINATED])`, `data: null` bei 0 Produkten |
| `global.yaml` Struktur | Technische Umsetzung § 8 | YES | `id: "launch-2026"`, `dismissible: true`, `bgColor: "primary"` |
| `layout.tsx` Erweiterung (loadGlobalConfig + SectionRenderer) | Technische Umsetzung § 9 | YES | `loadGlobalConfig(theme)` vor `<Header />`; `<SectionRenderer sections={globalConfig.sections}>` |
| `home.yaml` vollständige Datei | Technische Umsetzung § 7 | YES | Alle 7 Sections in korrekter Reihenfolge; `announcement-bar` nicht vorhanden |
| Newsletter Submit-Logik (console.log + 500ms delay + setState) | Technische Umsetzung § 3.2 | YES | Exakte Sequenz; kein echter API-Call |
| `app/page.tsx` skeletonMap Erweiterung | Technische Umsetzung § 10 | YES | `'testimonials': null`, `'newsletter-signup': null`, `'featured-collection': <FeaturedCollectionBlockSkeleton />` |

---

## Links

- Architecture: `specs/phase-1/2026-02-25-shop-completeness/architecture.md`
- Discovery: `specs/phase-1/2026-02-25-shop-completeness/discovery.md`
- Slice 1 (Dependency): `specs/phase-1/2026-02-25-shop-completeness/slices/slice-01-cross-page-infrastruktur.md`
- Slice 3 (Dependency): `specs/phase-1/2026-02-25-shop-completeness/slices/slice-03-kategorie-page-enhancements.md`
- Referenz-Block: `frontend/components/blocks/usp-bar-block.tsx` (Icon-Pattern für Testimonials-Sterne)
- Referenz-Block: `frontend/components/blocks/product-grid-block.tsx` (Suspense + Skeleton Pattern)
- Bestehendes home.yaml: `frontend/themes/default/pages/home.yaml`

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Frontend — Neue Dateien

- [ ] `frontend/components/blocks/testimonials-block.tsx` — Server Component; exportiert `TestimonialsBlock`; Grid-Layout 1/2/3 Spalten; Sterne-Rendering; `blockquote`/`figure` Semantik
- [ ] `frontend/components/blocks/newsletter-signup-block.tsx` — Client Component (`'use client'`); exportiert `NewsletterSignupBlock`; States: idle/loading/success/error; E-Mail-Validierung; `console.log` Platzhalter
- [ ] `frontend/components/blocks/featured-collection-block.tsx` — Server Component; exportiert `FeaturedCollectionBlock` und `FeaturedCollectionBlockSkeleton`; rendert Kategorie-Bild + 4 Produkt-Cards; null bei leerem data
- [ ] `frontend/themes/default/pages/global.yaml` — Neue Datei; enthält `announcement-bar` Block mit `id: "launch-2026"`, `dismissible: true`, `bgColor: "primary"`

### Frontend — Geänderte Dateien

- [ ] `frontend/lib/blocks/registry.ts` — 3 neue Einträge: `'testimonials'`, `'newsletter-signup'`, `'featured-collection'` mit korrekten Importen
- [ ] `frontend/lib/blocks/data-loaders.ts` — `featured_collection` Branch: `Promise.all([GET_CATEGORY_META, GET_PRODUCTS_PAGINATED])`, `data: null` bei 0 Produkten oder fehlendem Slug
- [ ] `frontend/lib/blocks/types.ts` — 3 neue Interfaces: `TestimonialsData`, `TestimonialsItem`, `NewsletterSignupData`, `FeaturedCollectionData`; `WooCommerceLoaderParams.query` Union erweitert um `'featured_collection'`
- [ ] `frontend/app/layout.tsx` — `loadGlobalConfig(theme)` Import und Aufruf; `<SectionRenderer sections={globalConfig.sections} skeletonMap={{}} />` vor `<Header />`
- [ ] `frontend/themes/default/pages/home.yaml` — 3 neue Sections hinzugefügt: `testimonials` (nach category-showcase), `featured-collection` (nach testimonials), `newsletter-signup` (vor usp-bar); kein `announcement-bar` Block
- [ ] `frontend/app/page.tsx` — `skeletonMap` um `'testimonials': null`, `'newsletter-signup': null`, `'featured-collection': <FeaturedCollectionBlockSkeleton />` erweitert

### Tests

- [ ] `frontend/tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts` — Vitest Unit Tests; alle 5 Test-Suiten mit je 4–6 Tests implementiert; Tests laufen grün mit `pnpm test tests/slices/shop-completeness/slice-04-homepage-enhancements.test.ts`
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind Pflicht
- Der Stop-Hook prüft automatisch ob alle Dateien existieren
- Bei fehlenden Dateien wird der Agent blockiert und muss nachfragen
