# Feature: Block-basierte Seiten-Migration

**Epic:** P0.3
**Status:** Ready
**Wireframes:** --

---

## Problem & Solution

**Problem:**
- Nur die Homepage ist Block-basiert (YAML-konfigurierbar)
- Kategorie-, Produkt- und Rechtsseiten sind hardcoded in page.tsx
- Ein zweiter Shop kann diese Seiten nicht per Theme anpassen (nur Farben/Fonts)
- Rechtsseiten existieren nicht in Next.js (Footer linkt zu WordPress)

**Solution:**
- Alle verbleibenden Seiten (ausser Warenkorb) als Block-basierte YAML-Pages aufbauen
- Block-System um Sections-Ebene, Layout-Renderer und Param-Resolver erweitern
- Neue Block-Components fuer Kategorie, Produkt und Rechtsseiten erstellen

**Business Value:**
- Multi-Shop-Faehigkeit: Jeder Shop kann Seitenstruktur per YAML anpassen
- Konsistenz: Einheitliches Kompositions-Pattern fuer alle Seiten
- Exit-Kriterium Phase 0 + Phase 1: "Alle Seiten als Block-basierte YAML-Pages"

---

## Scope & Boundaries

| In Scope |
|----------|
| Block-System Upgrade: Sections-Format, Spalten-Layout (columns + span), Param-Resolver, Template-Override |
| Homepage auf Sections-Format migrieren |
| Kategorie-Seite (`/kategorie/[slug]`) als Block-Page |
| Produkt-Seite (`/produkt/[slug]`) als Block-Page mit Client-Islands |
| Rechtsseiten (`/impressum`, `/agb`, `/datenschutz`, `/widerruf`) als Block-Pages |
| Footer-Links von WordPress-URLs auf Next.js-Routen umstellen |
| Platzhalter-Rechtstexte (echte Texte kommen in P0.5) |

| Out of Scope |
|--------------|
| Warenkorb-Seite (bleibt hardcoded — fast 100% Client-seitig) |
| "Alle Produkte"-Seite (`/produkte`) |
| Echte Rechtstexte (kommt in P0.5) |
| Neue Content-Sources (z.B. Markdown-Dateien) |
| Visueller Page-Builder / Drag & Drop |
| Slug-spezifische Layouts erstellen (System wird gebaut, aber keine konkreten Overrides) |

---

## Current State Reference

> Existing functionality that will be reused (unchanged). NOT documented again in detail sections below.

- Block-Registry-Mechanismus (`lib/blocks/registry.ts`) — resolveBlock() Lookup
- Data-Loader-Dispatch (`lib/blocks/data-loaders.ts`) — loadBlockData() mit 3 Sources
- YAML-Loader (`lib/blocks/page-config.ts`) — loadPageConfig() mit Theme-Fallback
- 4 bestehende Block-Components: `hero`, `product-grid`, `category-showcase`, `usp-bar`
- Alle UI-Komponenten: ProductCard, ProductImageGallery, VariantSelector, AddToCartButton, FilterChips, CartItemRow, CartSummary
- Suspense/Skeleton-Pattern fuer async Blocks
- GraphQL Queries: GET_PRODUCTS, GET_PRODUCT, GET_PRODUCT_CATEGORIES, GET_FEATURED_PRODUCTS
- CartProvider, ApolloWrapper, Session-Token-Management
- generateStaticParams / generateMetadata Pattern in page.tsx
- Theme-Token-System (bg-primary, text-text-primary, rounded-card, etc.)

---

## UI Patterns

### Reused Patterns

| Pattern Type | Component | Usage in this Feature |
|--------------|-----------|----------------------|
| Product Card | `components/product/product-card.tsx` | Wiederverwendet in product-grid Block (Kategorie) |
| Image Gallery | `components/product/product-image-gallery.tsx` | Wird product-gallery Block (Client-Island) |
| Variant Selector | `components/product/variant-selector-*.tsx` | Teil des product-purchase Blocks (Client-Island) |
| Add to Cart | `components/product/add-to-cart-button.tsx` | Teil des product-purchase Blocks (Client-Island) |
| Filter Chips | `components/category/filter-chips.tsx` | Wird filter-chips Block |
| Card | `components/ui/card.tsx` | Wiederverwendet in product-description Block |
| Skeleton | Alle *-skeleton Komponenten | Wiederverwendet in SKELETON_MAP |

### New Patterns

| Pattern Type | Description | Rationale |
|--------------|-------------|-----------|
| Sections-Renderer | Iteriert ueber sections[], rendert Spalten-Layout + Block-Renderer pro Section | Neue Kompositions-Ebene ueber Blocks |
| 2D-Grid-Layout | CSS Grid mit `columns: 1/2/3/4` pro Section + optionalem `span` (Spalten) und `row-span` (Zeilen) pro Block. `grid-template-columns: repeat(N, 1fr)`. Default: columns=1 (fullwidth), span=1, row-span=1. | Flexibles 2D-Grid fuer Magazine-Layouts, Editorial, Produkt-Seiten |
| Client-Island Block | Server-Block-Wrapper rendert Client-Komponente als Leaf | Ermoeglicht interaktive Blocks im Server-Block-System |
| Param-Resolver | Ersetzt `$route.slug` Platzhalter in YAML-Params mit URL-Parametern | Dynamische Routen mit statischem YAML |
| Template-Override | 3-stufige YAML-Suche: slug-spezifisch > theme-generisch > default | Verschiedene Layouts pro Kategorie/Produkt moeglich |

---

## User Flow

Kein neuer User-Flow — das Feature aendert die interne Architektur, nicht das User-Verhalten. Alle Seiten sehen nach der Migration identisch aus.

1. User oeffnet `/kategorie/t-shirts` → Section-Renderer laedt `category.yaml`, rendert Blocks → gleiche UI wie heute
2. User oeffnet `/produkt/premium-hoodie` → Section-Renderer laedt `product.yaml`, rendert Blocks inkl. Client-Islands → gleiche UI wie heute
3. User oeffnet `/impressum` → Section-Renderer laedt `impressum.yaml`, rendert legal-content Block → NEU: Seite existiert jetzt in Next.js

**Error Paths:**
- YAML fuer Seite nicht gefunden → Throw Error (Build-Time fuer statische Seiten, Runtime fuer dynamische)
- Block-Type unbekannt → console.warn, Block wird uebersprungen (bestehendes Verhalten)
- GraphQL-Fehler → Block rendert mit `null` data (bestehendes Verhalten)

---

## UI Layout & Context

### Screen: Kategorie-Seite (Migration)

**Position:** `/kategorie/[slug]`
**When:** User navigiert zu einer Produktkategorie

**Layout (unveraendert):**
- Section 1 (columns: 1): page-heading Block — h1 mit Kategoriename
- Section 2 (columns: 1): filter-chips Block — horizontale Kategorie-Pills
- Section 3 (columns: 1): product-count Block — "N Produkte" Text
- Section 4 (columns: 1): product-grid Block — Grid mit ProductCards (2/2/3/4 Spalten)

### Screen: Produkt-Seite (Migration)

**Position:** `/produkt/[slug]`
**When:** User oeffnet ein Produkt

**Layout (unveraendert):**
- Section 1 (columns: 2): product-gallery Block (links) + product-purchase Block (rechts)
  - Mobile: gestapelt (Gallery oben, Purchase unten)
  - Desktop: 50/50 Grid
- Section 2 (columns: 1): product-description Block — Beschreibungstext in Card

### Screen: Rechtsseiten (NEU)

**Position:** `/impressum`, `/agb`, `/datenschutz`, `/widerruf`
**When:** User klickt Link im Footer oder Mobile-Menu

**Layout:**
- Section 1 (columns: 1): page-heading Block — h1 mit Seitentitel
- Section 2 (columns: 1): legal-content Block — HTML-Content aus WordPress

---

## UI Components & States

> Nur NEUE oder GEAENDERTE Komponenten. Bestehende Components siehe "Current State Reference".

| Element | Type | Location | States | Behavior |
|---------|------|----------|--------|----------|
| `SectionRenderer` | Server Component | `lib/blocks/section-renderer.tsx` | `loading` (Suspense), `loaded`, `error` (null data) | Iteriert sections, wendet Layout an, rendert Blocks |
| `SectionLayout` | Server Component | `lib/blocks/section-layout.tsx` | -- | CSS Grid mit `columns` Property: `grid-template-columns: repeat(N, 1fr)`. Unterstuetzt `span` (Spalten) und `row-span` (Zeilen) pro Block. Default: columns=1 (fullwidth). Mobile: immer Stack. |
| `PageHeadingBlock` | Server Component | `components/blocks/page-heading-block.tsx` | `with-subtitle`, `without-subtitle` | Rendert h1 + optionalen p-Tag |
| `FilterChipsBlock` | Server Component | `components/blocks/filter-chips-block.tsx` | `loading` (skeleton), `loaded` | Wrapper um bestehende FilterChips-Komponente |
| `ProductCountBlock` | Server Component | `components/blocks/product-count-block.tsx` | `loading` (skeleton), `loaded` | Rendert "N Produkte" Text |
| `ProductGalleryBlock` | Client-Island | `components/blocks/product-gallery-block.tsx` | `loading` (skeleton), `loaded`, `no-images` | Wrapper um bestehende ProductImageGallery |
| `ProductPurchaseBlock` | Client-Island | `components/blocks/product-purchase-block.tsx` | `loading` (skeleton), `loaded`, `no-variants` | Wrapper um bestehende ProductVariantSelector |
| `ProductDescriptionBlock` | Server Component | `components/blocks/product-description-block.tsx` | `loading` (skeleton), `has-description`, `no-description` | Beschreibung in Card, oder nichts wenn leer |
| `LegalContentBlock` | Server Component | `components/blocks/legal-content-block.tsx` | `loading` (skeleton), `loaded`, `not-found` | HTML-Content aus WordPress rendern |

---

## Feature State Machine

Nicht anwendbar — kein interaktiver Feature-State. Das Feature ist eine Architektur-Migration. Die State Machines der einzelnen Komponenten (Gallery-Swipe, Variant-Selection, Cart) bleiben unveraendert.

---

## Business Rules

- Template-Override-Reihenfolge: slug-spezifisch → theme-generisch → default (immer in dieser Reihenfolge)
- Sections ohne explizites `columns:` Property verwenden `columns: 1` als Default (fullwidth)
- Blocks koennen optional `span: N` (Spaltenbreite) und `row-span: N` (Zeilenhoehe) definieren (default: jeweils 1)
- Alle Sections werden auf Mobile zu `columns: 1` (Stack), unabhaengig vom Desktop-Wert
- `$route.slug` Platzhalter werden vor dem Data-Loading aufgeloest
- Bestehende `generateStaticParams` und `generateMetadata` bleiben in page.tsx (nicht im Block-System)
- JSON-LD Schema-Markup bleibt in page.tsx (nicht im Block-System)
- Pinterest-Tracking Side-Effects bleiben in page.tsx (nicht im Block-System)
- Warenkorb-Seite ist explizit ausgeschlossen (bleibt hardcoded)
- Rechtsseiten verwenden Platzhalter-Content (echte Texte in P0.5)
- Alle Farben/Radii/Shadows muessen Theme-Tokens verwenden (keine Hardcoded-Werte)
- `app/generated-theme.css` wird nie manuell editiert

---

## Data

### Neue WooCommerce Data-Loader Queries

| Query | Variables | Returns | Used by |
|-------|-----------|---------|---------|
| `products_by_category` | `slug: string`, `first: number` | `{ products: { nodes: ProductCardData[] }, category: { name, description } }` | Kategorie-Seite (product-grid, page-heading, product-count) |
| `product_by_slug` | `slug: string` | `{ product: ProductDetailData }` | Produkt-Seite (product-gallery, product-purchase, product-description) |

### Neue WordPress Data-Loader Queries

| Query | Variables | Returns | Used by |
|-------|-----------|---------|---------|
| `page_content` | `slug: string` | `{ page: { title, content } }` | Rechtsseiten (page-heading, legal-content) |

### Neue Content-Source

| Source | Params | Behavior |
|--------|--------|----------|
| `dynamic` | `source: 'route'` | Liest Daten aus dem Route-Kontext (slug, metadata). Kein GraphQL. |

---

## Implementation Slices

### Dependencies

```
Slice 1 (Block-System Upgrade)
   |
   ├── Slice 2 (Kategorie-Seite)
   ├── Slice 3 (Produkt-Seite)
   └── Slice 4 (Rechtsseiten)
```

Slices 2, 3, 4 haengen von Slice 1 ab, sind untereinander unabhaengig.

### Slices

| # | Name | Scope | Testability | Dependencies |
|---|------|-------|-------------|--------------|
| 1 | Block-System Upgrade | Sections-Format, 2D-Grid-Layout (columns + span + row-span), Param-Resolver, Template-Override, Homepage-Migration | Unit-Tests fuer Param-Resolver, Template-Override, Grid-Layout. Homepage rendert identisch. | -- |
| 2 | Kategorie-Seite als Block-Page | page-heading, filter-chips, product-count Blocks. DataLoader: products_by_category. category.yaml. Route-Umbau. | Kategorie-Seite rendert identisch zum Ist-Zustand. Neues YAML wird geladen. | Slice 1 |
| 3 | Produkt-Seite als Block-Page | product-gallery, product-purchase, product-description Blocks (Client-Islands). DataLoader: product_by_slug. product.yaml. Route-Umbau. | Produkt-Seite rendert identisch. Gallery-Swipe, Varianten-Auswahl, AddToCart funktionieren. | Slice 1 |
| 4 | Rechtsseiten als Block-Pages | legal-content Block. DataLoader: page_content. 4x YAML-Dateien. Neue Routen. Footer-Links-Umbau. | Rechtsseiten oeffnen in Next.js. Content wird aus WordPress geladen. Footer-Links zeigen auf Next.js. | Slice 1 |

### Recommended Order

1. **Slice 1: Block-System Upgrade** — Foundation fuer alles Weitere. Kein neuer User-Value, aber notwendige Infrastruktur.
2. **Slice 2: Kategorie-Seite** — Einfachster Migrationsfall (nur Server-Blocks). Beweist das System.
3. **Slice 3: Produkt-Seite** — Komplexester Fall (Client-Islands). Beweist dass interaktive Seiten funktionieren.
4. **Slice 4: Rechtsseiten** — Einfach, aber benoetigt neuen WordPress-Content-Loader. Bringt Seiten die bisher fehlten.

---

## Context & Research

### Similar Patterns in Codebase

| Feature | Location | Relevant because |
|---------|----------|------------------|
| Homepage Block-Rendering | `app/page.tsx` | Bestehendes Pattern das erweitert wird |
| Block Registry | `lib/blocks/registry.ts` | Wird um neue Block-Types erweitert |
| Data Loaders | `lib/blocks/data-loaders.ts` | Wird um neue Queries erweitert |
| Page Config | `lib/blocks/page-config.ts` | Bekommt Sections-Support + Template-Override |
| Theme YAML Merge | `scripts/generate-theme.mjs` | Aehnliches Override-Pattern (deepMerge) |

### Web Research

| Source | Finding |
|--------|---------|
| [Shopify Sections Architecture](https://shopify.dev/docs/storefronts/themes/architecture/sections) | Sections als vertikale Ordnungseinheit ist Industriestandard. Template → Sections → Blocks Hierarchie. |
| [Shopify Best Practices](https://shopify.dev/docs/storefronts/themes/best-practices/templates-sections-blocks) | Max 25 Sections/Template, 50 Blocks/Section. Modular, merchant-customizable. |
| [Headless CMS Best Practices](https://www.afteractive.com/blog/best-practices-for-structuring-content-in-a-headless-cms) | Modulare Block-Komposition, Content von Presentation trennen, Slice Variations. |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| -- | Alle Fragen geklaert | -- | -- | -- |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-02-25 | Codebase | Block-System hat 4 Block-Types, nur Homepage als YAML-Page, alle anderen Seiten hardcoded |
| 2026-02-25 | Codebase | Block-System ist Server-only, Client-Island-Pattern existiert noch nicht in Blocks |
| 2026-02-25 | Codebase | Rechtsseiten existieren nicht in Next.js, Footer linkt zu WordPress |
| 2026-02-25 | Codebase | Warenkorb ist fast 100% Client-seitig (useCart Context) |
| 2026-02-25 | Codebase | Kategorie-Seite verwendet FilterChips + ProductCard, einfach migrierbar |
| 2026-02-25 | Codebase | Produkt-Seite hat 3 Client-Islands: Gallery, VariantSelector, AddToCart |
| 2026-02-25 | Web | Shopify Online Store 2.0: Template → Sections → Blocks ist Industriestandard |
| 2026-02-25 | Web | Headless CMS Best Practices: Modulare Block-Komposition, Content/Presentation trennen |

---

## Q&A Log

| # | Frage | Antwort |
|---|-------|---------|
| 1 | Soll ich direkt mit Fragen starten oder erst Web-Recherche machen? | Direkt starten, aber erst die Client-Island-Herausforderung einfach erklaeren |
| 2 | Wie weit soll YAML-Konfigurierbarkeit bei interaktiven Seiten gehen? | Layout-Only: YAML definiert nur Block-Reihenfolge, Logik bleibt in React |
| 3 | Soll der Warenkorb eine Block-Page werden oder hardcoded bleiben? | Hardcoded — fast 100% Client-seitig, Block-System bringt keinen Mehrwert |
| 4 | Soll das Block-System ein Layout-Konzept bekommen? | Ja, einfaches Layout-System mit Section-Wrappern |
| 5 | Woher sollen die Rechtstexte kommen? | Aus WordPress (GraphQL), Platzhalter reichen fuer die Block-Migration |
| 6 | Sollen die Rechtsseiten sofort echte Texte bekommen? | Platzhalter reichen, echte Texte in P0.5 |
| 7 | Wie soll der Page-Renderer mit dynamischen Routen umgehen? | Generisches Page-Template mit $route.slug Param-Resolver |
| 8 | Kann man mit der Loesung verschiedenen Kategorien verschiedene Layouts geben? | Ja, ueber 3-stufigen Template-Override (slug-spezifisch → generisch → default) |
| 9 | Soll der slug-spezifische Template-Override sofort eingebaut werden? | Ja, gleich mit Override |
| 10 | Layout als Section-Ebene oder als spezieller Block-Type? | Sections-Ebene: Klare Hierarchie Section > Block (Shopify-Pattern) |
| 11 | Wie soll der product-info Block heissen? | product-purchase — betont den Kaufvorgang |
| 12 | Soll die Homepage auf das neue Sections-Format migriert werden? | Ja, Homepage migrieren (einheitliches Format) |
| 13 | Welche Layout-Typen brauchen wir? | Spaltenbasiert: columns 1/2/3/4 + optionaler span pro Block. Keine benannten Layouts. |
| 14 | Ist das Layout-System auf Page-Ebene, Grids innerhalb von Blocks sind Block-intern? | Ja, Section-Layout (columns) vs. Block-internes Layout (CSS/Tailwind) sind getrennt |
| 15 | Ist die Block-Inventur vollstaendig? | Ja, passt. 4 bestehende + 9 neue Blocks |
| 16 | Wie sollen wir die Arbeit aufteilen? | 4 Slices: System-Upgrade → Kategorie → Produkt → Rechtsseiten |
| 17 | Sollen Rechtsseiten eigene Routen oder gemeinsamen Prefix haben? | Eigene Routen (/impressum, /agb, /datenschutz, /widerruf) |
| 18 | Soll eine "Alle Produkte"-Seite eingebaut werden? | Nicht jetzt — Fokus auf Block-Migration |
| 19 | Brauchen wir Page-Level-Layout oder reicht Section-Level-Layout? | Nur Section-Level. Kein Page-Layout noetig. |
| 20 | Sollen Sidebar-Layouts (30/70) als Basis-Layouts aufgenommen werden? | Nein — stattdessen spaltenbasiert: columns 1/2/3/4 mit optionalem span. Flexibler und intuitiver. |
| 21 | Brauchen wir ein 2D-Grid mit row-span (Zeilen-Spanning)? | Ja, vollstaendiges 2D-Grid: columns + span (Spalten) + row-span (Zeilen). Fuer Magazine/Editorial-Layouts. |
| 22 | Soll row-span jetzt implementiert oder nur vorgesehen werden? | Gleich implementieren. Vollstaendiges 2D-Grid von Anfang an. |
