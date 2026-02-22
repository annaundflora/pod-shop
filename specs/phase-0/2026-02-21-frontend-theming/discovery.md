# Feature: Frontend-Architektur für Theming (P0.2)

**Epic:** --
**Status:** Ready
**Wireframes:** Nicht nötig (Architektur-Feature)

---

## Problem & Solution

**Problem:**
- Shop-Name, Farben, Fonts sind in 4+ Dateien hardcoded — Rebranding erfordert manuelle Suche
- 4 Components nutzen hardcoded Tailwind-Farben (`red-*`, `green-*`, `black/70`) statt semantischer Tokens
- Keine Status-Tokens (error, success, warning) definiert
- Kein Font-System — nur System-Fonts
- Keine Möglichkeit, mehrere Shops mit unterschiedlichem Branding aus derselben Codebase zu betreiben
- Keine wiederverwendbaren UI-Primitives — jede Component implementiert eigene Button-/Card-/Input-Patterns

**Solution:**
- Theme Token System mit YAML-Config pro Shop, Build-Time CSS-Generierung per Env-Variable
- shadcn/ui als Basis-Component-Library mit Theme-Token-Anbindung
- Block Registry + Page Renderer für flexible, config-getriebene Seitenlayouts
- Hybrid-Content: Layout-Struktur aus Config (YAML), editierbare Texte aus WP Custom Fields, Produkt-Daten aus WooCommerce GraphQL

**Business Value:**
- Ermöglicht 2-3 White-Label Shops aus einer Codebase (kein Code-Fork pro Shop)
- Reduziert Aufwand für neuen Shop auf: Theme-Config + Assets + WP-Instanz
- Zukunftssicher: Block Registry kann später zu visuellem Editor erweitert werden

---

## Scope & Boundaries

| In Scope |
|----------|
| Theme Token System (YAML → CSS Variables → Tailwind @theme) |
| Build-Pipeline: Env-Variable → YAML lesen → Deep Merge → CSS generieren |
| shadcn/ui Primitives: Button, Input, Card, Badge, Skeleton, Dialog, Sheet |
| Big Bang Migration aller 18 bestehenden Components auf shadcn/ui Primitives |
| Block Registry: type → React Component Mapping |
| Data Loaders: wordpress / woocommerce / inline Content-Quellen |
| Page Renderer: YAML Config → Block-Liste → gerenderte Seite |
| 4 Block-Typen: Hero, ProductGrid, CategoryShowcase, UspBar |
| WP Custom Fields Plugin (PHP): Hero-Felder + SEO Meta Description |
| Theme Config Ordnerstruktur: `themes/{shop}/` mit theme.yaml, pages/, assets/ |
| Deep Merge Vererbung: Shop-Config überschreibt Default-Config |
| Shop-spezifische Assets (Logo, Favicon) |

| Out of Scope |
|--------------|
| Dark Mode / Light-Dark Toggle |
| Visueller Page Builder / Drag & Drop Editor in WP-Admin |
| Gutenberg Block Rendering (zu komplex für 2-3 Shops) |
| ACF Pro oder andere kostenpflichtige Plugins |
| Runtime Theme-Switching (Domain-basiert) — nur Build-Time per Env |
| WooCommerce Multisite |

---

## Current State Reference

> Bestehende Funktionalität die wiederverwendet wird (unverändert).

- **Tailwind v4 CSS-first** mit `@theme` Block in `frontend/app/globals.css` — 7 semantische Color-Tokens bereits definiert (primary, primary-hover, surface, surface-elevated, text-primary, text-secondary, border)
- **OKLch Farbraum** für alle Farb-Tokens
- **Mobile-first Touch Targets** (`--min-touch-target: 2.75rem`)
- **Accessibility-Pattern:** `focus-visible:ring-2 focus-visible:ring-primary` auf allen interaktiven Elementen
- **Motion-safe Support:** `prefers-reduced-motion` respektiert
- **Apollo Client Dual-Setup:** Server-Client (RSC) + Client-Client (mit Session)
- **WPGraphQL + WooGraphQL** für Produkt- und Kategorie-Daten
- **Suspense + Skeleton Pattern** für async RSC Sections
- **ISR auf Homepage** (`revalidate = 60`)
- **18 bestehende Components** (5 Layout, 6 Product, 4 Cart, 1 Category, 1 Tracking, 1 Context)
- **PostCSS Config** (`@tailwindcss/postcss`)
- **Base Layer Styles** (body, headings, touch-action)
- **Cookie Consent Banner** mit slide-up Animation

---

## UI Patterns

### Reused Patterns

| Pattern Type | Component | Usage in this Feature |
|--------------|-----------|----------------------|
| Semantic Color Tokens | `globals.css` @theme Block | Erweitert um Status-Tokens, Shadows |
| ISR / Suspense / Skeleton | `app/page.tsx`, `product-card-skeleton.tsx` | Block-Typen nutzen selbes Pattern |
| Apollo GraphQL Queries | `lib/apollo/server-client.ts` | Data Loaders für wordpress/woocommerce Quellen |
| Touch Target Pattern | Alle interaktiven Elemente | Übernommen in shadcn/ui Primitives |

### New Patterns

| Pattern Type | Description | Rationale |
|--------------|-------------|-----------|
| Theme Config (YAML) | Shop-spezifische Config-Dateien in `themes/{shop}/` | Zentraler Ort für alle Theme-Werte; ermöglicht Multi-Shop |
| Build-Time CSS Generation | Script liest YAML → generiert CSS Custom Properties | Tailwind v4 @theme konsumiert CSS Variables |
| Block Registry | Type-to-Component Mapping mit Data Loader Layer | Flexible Page-Komposition ohne CMS-Overhead |
| Page Config (YAML) | Block-Liste mit Typ, Datenquelle und Props pro Seite | Deklarative Seitendefinition pro Shop |
| Deep Merge Config | Shop-Config überschreibt Default-Config selektiv | Weniger Duplikation bei ähnlichen Shops |
| shadcn/ui Primitives | Wiederverwendbare UI-Bausteine mit CVA Variants | Konsistente Basis für alle Components |
| WP Custom Fields (native) | `register_post_meta()` mit `show_in_graphql: true` | Editierbare Texte ohne ACF-Kosten |

---

## User Flow

### Flow 1: Neuen Shop anlegen (Entwickler)

1. `themes/{shop-name}/` Ordner erstellen
2. `theme.yaml` anlegen — nur Werte die vom Default abweichen (Farben, Fonts)
3. `pages/home.yaml` anlegen — Block-Layout für Homepage definieren
4. `assets/` füllen — Logo (SVG), Favicon
5. `.env` setzen: `NEXT_PUBLIC_THEME=shop-name`
6. `pnpm build` — Build-Script merged Config, generiert CSS, baut Next.js
7. Deployment

### Flow 2: Build-Pipeline (automatisch)

1. Build-Script liest `NEXT_PUBLIC_THEME` aus Env
2. Lädt `themes/{theme}/theme.yaml`
3. Lädt `themes/default/theme.yaml`
4. Deep Merge: Shop überschreibt Default
5. Generiert `app/generated-theme.css` mit CSS Custom Properties
6. `globals.css` importiert `generated-theme.css`
7. Tailwind v4 @theme konsumiert die CSS Variables
8. Next.js Build kompiliert mit finalen Token-Werten

### Flow 3: Page Rendering (Runtime)

1. Next.js Route wird aufgerufen (z.B. `/`)
2. Page-Component lädt Block-Config für diese Seite (`themes/{theme}/pages/home.yaml`)
3. Für jeden Block in der Config:
   - Block Registry resolves Block-Typ → React Component
   - Data Loader lädt Daten je nach `content_source`:
     - `wordpress` → GraphQL Query an WP Custom Fields
     - `woocommerce` → GraphQL Query an WooGraphQL
     - `inline` → Props direkt aus Config
4. Page Renderer rendert alle Blocks in Reihenfolge
5. Jeder Block nutzt shadcn/ui Primitives + Theme Tokens

**Error Paths:**
- Unbekannter Block-Typ → Block wird übersprungen, Warning in Console
- GraphQL-Fehler (WP/WooCommerce) → Fallback: Block nicht rendern, Error-Boundary
- Fehlende Theme-Config → Fallback auf `themes/default/`
- Fehlende Page-Config → Fallback auf Default-Page-Config

---

## UI Layout & Context

### Kein neuer Screen

Dieses Feature ändert keine sichtbare UI. Die bestehenden Seiten (Homepage, Kategorie, Produkt, Warenkorb) sehen identisch aus — aber:
- Components nutzen shadcn/ui Primitives statt eigener Implementierungen
- Farben kommen aus Theme Tokens statt hardcoded Tailwind-Klassen
- Homepage-Layout wird durch Block-Config gesteuert statt hardcoded in `page.tsx`

---

## UI Components & States

### Neue shadcn/ui Primitives

| Element | Type | Variants | States |
|---------|------|----------|--------|
| `Button` | Interactive | `default`, `destructive`, `outline`, `ghost`, `link` | `default`, `hover`, `focus-visible`, `disabled`, `loading` |
| `Input` | Form | `default`, `error` | `default`, `focus`, `disabled`, `error` |
| `Card` | Container | `default`, `interactive` (hover effect) | `default`, `hover` |
| `Badge` | Display | `default`, `secondary`, `destructive`, `outline` | `default` |
| `Skeleton` | Loading | -- | `animating` |
| `Dialog` | Overlay | -- | `open`, `closed` |
| `Sheet` | Overlay/Drawer | `top`, `right`, `bottom`, `left` | `open`, `closed` |

### Neue Block Components

| Element | Type | Content Source | States |
|---------|------|---------------|--------|
| `HeroBlock` | Page Block | wordpress, inline | `loaded`, `loading` (Skeleton), `error` (hidden) |
| `ProductGridBlock` | Page Block | woocommerce | `loaded`, `loading` (Skeletons), `empty`, `error` |
| `CategoryShowcaseBlock` | Page Block | woocommerce | `loaded`, `loading`, `empty`, `error` |
| `UspBarBlock` | Page Block | inline | `loaded` (always — inline data) |

---

## Feature State Machine

### Build-Time Theme Pipeline

| State | Beschreibung | Available Actions |
|-------|-------------|-------------------|
| `env_read` | NEXT_PUBLIC_THEME aus Env gelesen | Load Config |
| `config_loaded` | Shop + Default YAML geladen | Merge |
| `config_merged` | Deep Merge abgeschlossen | Generate CSS |
| `css_generated` | generated-theme.css geschrieben | Next.js Build |
| `build_complete` | Build mit Theme-Tokens fertig | Deploy |
| `error_no_config` | Theme-Ordner nicht gefunden | Fallback auf Default |

### Transitions

| Current State | Trigger | Next State | Business Rules |
|---------------|---------|------------|----------------|
| `env_read` | Config-Dateien existieren | `config_loaded` | -- |
| `env_read` | Config-Ordner fehlt | `error_no_config` | Warning loggen |
| `error_no_config` | Automatisch | `config_loaded` | Fallback: `themes/default/` laden |
| `config_loaded` | Merge-Script | `config_merged` | Deep Merge: Shop-Werte überschreiben Default |
| `config_merged` | CSS-Generator | `css_generated` | Alle Token-Kategorien: colors, fonts, spacing, shadows |
| `css_generated` | `next build` | `build_complete` | -- |

### Runtime Block Rendering

| State | Beschreibung | Available Actions |
|-------|-------------|-------------------|
| `page_loading` | Page-Config wird geladen | -- |
| `blocks_resolving` | Blocks werden aufgelöst (Registry + Data Loaders) | -- |
| `blocks_rendered` | Alle Blocks gerendert | User-Interaktion |
| `block_error` | Ein Block konnte nicht geladen werden | Skip, Retry |

---

## Business Rules

- **Theme-Vererbung:** Shop-Config wird per Deep Merge über Default-Config gelegt. Fehlende Werte fallen auf Default zurück.
- **Env-Variable Pflicht:** `NEXT_PUBLIC_THEME` muss beim Build gesetzt sein. Fehlt sie → Default-Theme.
- **Block Registry Fallback:** Unbekannte Block-Typen werden übersprungen (kein Crash). Console Warning.
- **Content Source Validierung:** Nur `wordpress`, `woocommerce`, `inline` sind gültige Quellen. Ungültige → Block übersprungen.
- **WP Custom Fields:** Müssen per `register_post_meta()` mit `show_in_graphql: true` registriert sein, sonst nicht querybar.
- **Font Loading:** Fonts aus theme.yaml werden via `next/font/google` oder `next/font/local` geladen. Fallback: System-Fonts.
- **Logo-Format:** SVG bevorzugt (skalierbar). PNG als Fallback mit `width`/`height` in theme.yaml.
- **YAML-Schema-Validierung:** Theme- und Page-Configs werden beim Build validiert. Ungültige Config → Build-Error.

---

## Data

### theme.yaml Schema

| Field | Required | Validation | Notes |
|-------|----------|------------|-------|
| `colors.primary` | No | OKLch format: `oklch(L C H)` | Default: `oklch(0.45 0.2 270)` |
| `colors.primary-hover` | No | OKLch format | Default: automatisch dunkler als primary |
| `colors.error` | No | OKLch format | Default: `oklch(0.55 0.2 25)` (rot) |
| `colors.success` | No | OKLch format | Default: `oklch(0.55 0.15 145)` (grün) |
| `colors.warning` | No | OKLch format | Default: `oklch(0.7 0.15 85)` (gelb) |
| `colors.surface` | No | OKLch format | Default: white |
| `colors.surface-elevated` | No | OKLch format | Default: near-white |
| `colors.text-primary` | No | OKLch format | Default: near-black |
| `colors.text-secondary` | No | OKLch format | Default: medium-gray |
| `colors.border` | No | OKLch format | Default: light-gray |
| `colors.overlay` | No | OKLch format mit Alpha | Default: `oklch(0 0 0 / 0.5)` |
| `fonts.heading` | No | Google Font Name oder lokaler Pfad | Default: `"Inter"` |
| `fonts.body` | No | Google Font Name oder lokaler Pfad | Default: `"Inter"` |
| `assets.logo` | No | Pfad relativ zu `themes/{shop}/assets/` | Default: Text-Logo (Shop-Name) |
| `assets.favicon` | No | Pfad relativ zu `themes/{shop}/assets/` | Default: Next.js Default |

### Page Config Schema (z.B. home.yaml)

| Field | Required | Validation | Notes |
|-------|----------|------------|-------|
| `blocks` | Yes | Array, min 1 Block | Leere Seite nicht erlaubt |
| `blocks[].type` | Yes | Muss in Block Registry existieren | hero, product-grid, category-showcase, usp-bar |
| `blocks[].content_source` | Yes | `wordpress` \| `woocommerce` \| `inline` | Bestimmt Data Loader |
| `blocks[].page_slug` | Conditional | String | Pflicht wenn content_source = wordpress |
| `blocks[].query` | Conditional | Object | Pflicht wenn content_source = woocommerce |
| `blocks[].props` | Conditional | Object | Pflicht wenn content_source = inline |

### WP Custom Fields (register_post_meta)

| Field | Post Type | Type | Required | GraphQL Name |
|-------|-----------|------|----------|-------------|
| `hero_headline` | page | string | No | `heroHeadline` |
| `hero_subline` | page | string | No | `heroSubline` |
| `hero_cta_text` | page | string | No | `heroCtaText` |
| `hero_cta_link` | page | string | No | `heroCtaLink` |
| `hero_background_image` | page | string (URL) | No | `heroBackgroundImage` |
| `seo_meta_description` | page, post | string | No | `seoMetaDescription` |

---

## Implementation Slices

### Dependencies

```
Slice 1 (Token System) → Slice 2 (shadcn/ui) → Slice 3 (Migration) → Slice 4 (Block Registry) → Slice 5 (Multi-Shop)
```

### Slices

| # | Name | Scope | Testability | Dependencies |
|---|------|-------|-------------|--------------|
| 1 | Theme Token System | Default theme.yaml Schema, Build-Script (YAML → CSS), @theme Block erweitern (error, success, warning, overlay, shadow Tokens), Env-Variable `NEXT_PUBLIC_THEME`, generated-theme.css Output | Build ausführen mit/ohne Env → CSS prüfen. Token-Werte in Browser DevTools verifizieren. YAML-Validierung testen. | -- |
| 2 | shadcn/ui Primitives | shadcn/ui installieren + Tailwind v4 konfigurieren, Button (5 Variants), Input (2 Variants), Card (2 Variants), Badge (4 Variants), Skeleton, Dialog, Sheet einführen, alle Primitives auf Theme Tokens anbinden | Storybook: Stories pro Primitive mit allen Variants und States. Visuell: Primitives rendern mit Default-Theme. Unit: Variants, States, Accessibility (ARIA). | Slice 1 |
| 3 | Component Migration (Big Bang) | Alle 18 Components auf shadcn/ui Primitives umstellen, Hardcoded Colors (red, green, black) → Semantic Tokens (error, success, overlay), alle bestehenden Vitest Tests anpassen, Font-Loading via next/font einführen | Alle 151 bestehenden Tests müssen grün sein. Visueller Vergleich: Seiten sehen identisch aus. Lighthouse Score nicht verschlechtert. | Slice 2 |
| 4 | Block Registry + Page Renderer | Block Registry (type → Component) als einfaches `Record<string, React.ComponentType>` — kein Block-Framework, kein CMS. Data Loaders (wordpress, woocommerce, inline), Page Renderer Component, 4 Block-Typen (HeroBlock, ProductGridBlock, CategoryShowcaseBlock, UspBarBlock) als plain `.tsx` Dateien, WP Custom Fields PHP Plugin (register_post_meta), Homepage auf Block-Config umstellen | Homepage rendert identisch über Block-System. WP Custom Fields in GraphQL querybar. Fallback bei fehlendem Block. Data Loader Tests. | Slice 3 |
| 5 | Theme Config + Multi-Shop | themes/ Ordnerstruktur (default + mindestens 1 Shop), Deep Merge Logic (Shop über Default), Page Configs (home.yaml pro Shop), Shop-spezifische Assets (Logo, Favicon), YAML-Schema-Validierung beim Build | Zweites Theme anlegen → Build → visuell anderes Branding. Deep Merge Unit Tests. Fehlende Config → Fallback auf Default. | Slice 4 |

### Recommended Order

1. **Slice 1: Theme Token System** — Fundament für alles. Ohne Tokens kein Theming.
2. **Slice 2: shadcn/ui Primitives** — Bausteine die auf den Tokens aufbauen.
3. **Slice 3: Component Migration** — Bestehende UI auf neue Primitives umstellen. Danach ist die Codebase "theme-ready".
4. **Slice 4: Block Registry** — Flexible Seitenkomposition. Nutzt die migrierten Components.
5. **Slice 5: Multi-Shop** — Krönung: Zweiter Shop mit eigenem Theme beweist das System.

---

## Context & Research

### Similar Patterns in Codebase

| Feature | Location | Relevant because |
|---------|----------|------------------|
| Existing @theme Block | `frontend/app/globals.css` | 7 Tokens bereits definiert — wird erweitert, nicht ersetzt |
| Apollo Server Client | `frontend/lib/apollo/server-client.ts` | Data Loaders nutzen selben Client für WP/WooCommerce Queries |
| Product Card Skeleton | `frontend/components/product/product-card-skeleton.tsx` | Pattern wird für Block-Loading-States wiederverwendet |
| Cookie Consent Banner | `frontend/components/layout/cookie-consent-banner.tsx` | Nutzt bereits Animation Token (`--animate-slide-up`) |

### Web Research

| Source | Finding |
|--------|---------|
| shadcn/ui Docs: Tailwind v4 | Volle Kompatibilität, CSS-first Config, OKLCH Support |
| Shopify Hydrogen Sections | Block-Registry Pattern ist Industrie-Standard für flexible Layouts |
| Brad Frost: Themeable Design Systems | Multi-Brand Systems nutzen Global Tokens + Brand-spezifische Overrides |
| Headless WP + Gutenberg | Zu komplex für 2-3 Shops — ACF oder Custom Fields besser |
| wp-graphql-content-blocks | Existiert, aber wartungsintensiv. Native Custom Fields sind leichtgewichtiger |
| Faust.js | App Router Support experimentell. Nicht empfohlen für Produktion |
| ACF Flexible Content | Gute Option, aber ACF Pro Lizenz nötig — durch native Custom Fields ersetzt |

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| -- | Keine offenen Fragen | -- | -- | -- |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-02-21 | Codebase | 7 Color-Tokens in @theme, 4 Files mit hardcoded Colors, kein Font-System, "POD Shop" in 4 Files hardcoded |
| 2026-02-21 | Codebase | 18 Components (5 Layout, 6 Product, 4 Cart, 1 Category, 1 Tracking, 1 Context), 86% nutzen Tokens |
| 2026-02-21 | Web | shadcn/ui: Tailwind v4 ready, CSS Variables Theming, Radix-basiert (Accessibility), kein E-Commerce-spezifisch |
| 2026-02-21 | Web | Gutenberg → Next.js: wp-graphql-content-blocks existiert, aber 50+ Block Types mappen = hoher Aufwand |
| 2026-02-21 | Web | Faust.js: App Router experimental, turbulente Projekthistorie, nicht empfohlen |
| 2026-02-21 | Web | Custom Block Registry: Industrie-Pattern (Shopify Hydrogen, Storyblok, Sanity), framework-agnostisch |
| 2026-02-21 | Web | ACF Flexible Content: Guter Ansatz aber $49/Jahr. Native register_post_meta() ist kostenlos + reicht für den Start |
| 2026-02-21 | Web | Multi-Brand Design Systems: Global Tokens + Brand Overrides = etabliertes Pattern (Brad Frost, Supernova) |

---

## Q&A Log

| # | Frage | Antwort |
|---|-------|---------|
| 1 | Was ist das Ziel des Themings? (White-Label, Ein Shop, Dark Mode, Zukunftssicherung) | White-Label / Multi-Shop — verschiedene Shops mit unterschiedlichen Marken sollen aus derselben Codebase laufen |
| 2 | Wie soll der Theme-Wechsel technisch erfolgen? (Build-Time, Runtime, Unklar) | Build-Time per Env-Variable — jeder Shop ist ein separates Deployment |
| 3 | Was gehört zu einem Shop-Theme? (Farben, Fonts, Logo, Texte) | Farben (Primary, Accent), Fonts/Typografie, Logo+Favicon. Texte gehören NICHT ins Theme — kommen aus WordPress |
| 4 | Wo sollen Shop-Name, Copyright und Meta-Texte herkommen? (Theme-Config, Env, WordPress) | Aus WordPress/WooCommerce per GraphQL — Single Source of Truth im Backend |
| 5 | Wie viele Shops sind realistisch in den nächsten 6 Monaten? (2-3, 5-10, nur 1 vorbereitet) | 2-3 Shops |
| 6 | Welcher Ansatz für die Component Library? (shadcn/ui, Custom, Noch recherchieren) | shadcn/ui als Basis-Primitives, darauf eigene E-Commerce Components |
| 7 | Was schwebt dir beim Design System vor? Sollte es Teil des Themings sein? Gibt es E-Commerce Libraries? | Design System JA als Teil des Themings. shadcn/ui als Basis-Primitives. Tokens auf jeden Fall. |
| 8 | Sollen alle Shops dasselbe Layout haben oder können sie strukturell unterschiedlich sein? | Flexible Layouts durch Page Templates und ggf. Blocks. Recherche zu Möglichkeiten gewünscht. |
| 9 | Gibt es bereits eine Template/Block Engine für Next.js E-Commerce? Wie funktioniert Custom Registry mit Content? | Recherche ergab: Gutenberg zu komplex, ACF kostet extra. Custom Block Registry mit 3 Content-Quellen (WP Custom Fields, WooCommerce, Inline Config) ist pragmatischste Lösung. |
| 10 | Ist es okay, dass Page-Layouts per Config-Datei im Repo liegen statt im WP-Editor? | Hybrid: Config (JSON/YAML) für Struktur, WP Custom Fields für editierbare Texte. Kein Extra-Plugin-Kosten. |
| 11 | Ist der Hybrid-Ansatz (Config + Custom Fields + WooCommerce) ein gangbarer Weg? Widerspricht es Prinzipien? | Ja, gangbar. Pattern-konform (Separation of Concerns, Config-as-Code, Headless CMS Pattern). Erweiterbar zu visuellem Editor. Industrie-Beispiele: Shopify Hydrogen, Vercel Commerce, Medusa. |
| 12 | Woher kommt das UI und die Datenanbindung für die Blocks? | 4-Schichten-Architektur: Config (YAML) → Registry (Type→Component) → Data Loader (GraphQL/Inline) → UI Component (React + Tailwind Tokens) |
| 13 | Wie tief soll die Discovery gehen? (Kurz, Standard, Detailliert) | Standard — alle Pflicht-Sections |
| 14 | Was gehört IN den Scope? | Alle 4: Theme Token System, shadcn/ui Integration, Block Registry + Page Renderer, Theme Config pro Shop |
| 15 | Was ist explizit OUT of Scope? | Dark Mode, Visueller Page Builder / Drag & Drop. Migration + WP Custom Fields sind IN Scope. |
| 16 | Welche shadcn/ui Primitives werden gebraucht? | Alle 4 Gruppen: Button/Link-Button, Input/Quantity Stepper, Card/Badge/Skeleton, Dialog/Sheet |
| 17 | Welche Block-Typen für den Start? | Alle 4: Hero Banner, Product Grid, Category Showcase, USP Bar |
| 18 | Wie soll die Theme-Config-Datei strukturiert sein? | Ein Ordner pro Shop: themes/{shop}/ mit theme.yaml, pages/, assets/ |
| 19 | Bestehende Components schrittweise oder auf einmal migrieren? | Big Bang — alle 18 Components in einem Durchgang |
| 20 | Theme-Vererbung: Shop erbt von Default mit Deep Merge? | Ja, Deep Merge. Shop-Config überschreibt nur was anders ist. |
| 21 | Wie sollen Theme-Tokens zur Build-Time in CSS landen? | YAML → CSS Variables in generated-theme.css. globals.css importiert diese. |
| 22 | Welche WP Custom Fields braucht die Homepage initial? | Hero: Headline, Subline, CTA Text, CTA Link, Background Image. Plus SEO: Meta Description pro Seite. |
| 23 | Sollen Wireframes erstellt werden? | Nein — Architektur-Feature, kein UI-Feature |
| 24 | Passt die Slice-Aufteilung in 5 Slices? (Token System → shadcn/ui → Migration → Block Registry → Multi-Shop) | Ja, passt so |
| 25 | Wie sollen die Block Frontend Components (HeroBlock, ProductGridBlock etc.) technisch gebaut werden? Einfache React Components, Block-Framework (Storyblok, Contentlayer) oder Hybrid mit MDX? | Einfache React Components — plain `.tsx` Dateien, Block Registry als `Record<string, React.ComponentType>`. Kein externes Framework, kein Lock-in. |
| 26 | Soll Storybook für Component-Entwicklung und visuelle Tests verwendet werden? | Ja. Storybook wird für shadcn/ui Primitives und Block Components genutzt. Stories pro Component mit allen Variants und States. |
