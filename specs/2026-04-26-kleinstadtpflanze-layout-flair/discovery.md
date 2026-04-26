# Feature: Kleinstadtpflanze — Layout & Components (Phase 2, ehrlich-klein)

**Epic:** --
**Issue:** -- _(ohne Issue nach User-Entscheidung; Traceability eingeschränkt)_
**Status:** Ready
**Wireframes:** N/A (Design-E-HTML aus `/tmp/kp-design-e/` ist visuelle Referenz; keine ASCII-Wireframes nötig)

---

## Problem & Solution

**Problem:**
- Phase 1 (Style-Tokens) ist gemerged (PR #5). Layout/Components aus Design E wurden bewusst NICHT übernommen.
- Mit dem Launch-Inventar (4–5 Kategorien × Produkttypen, Handvoll Produkte mit jeweils 1 Motiv pro Eintrag, gleiches Motiv erscheint mehrfach auf verschiedenen Produkttypen) wirken Default-Pages strukturell **leer**: Hero generisch, Homepage-Mid-Sections (Testimonials/Featured-Collection/Newsletter) sind Marketing-Inszenierung ohne authentische Basis, PDP wirkt nüchtern.
- Naive 1:1-Übernahme von Design E (DealBar mit Countdown, Editorial "Werkstatt besuchen", Reviews-Summary "4,9★ aus 2.180 Bewertungen", Trust-Theater "Klimaneutral / Made in Germany") ist **inhaltlich unehrlich** (POD via Spreadshirt, kein Familienbetrieb, keine Reviews-Basis, kein eigener Druck).

**Solution:**
- **Selektive Übernahme** der Design-E-Patterns, die zu "klein-und-ehrlich" passen + Erfindung von 4 neuen Patterns, die zu echtem Inventar passen (Mini-About, FAQ-Accordion, Motiv-Tile-Grid, Brand-Recap).
- Homepage-Stack ersetzt Marketing-Sections durch Storytelling-light + Service-Info + Motiv-Galerie.
- PDP-Refactor: Beschreibung in Purchase-Spalte, Galerie auf Vertical-Thumbs-Layout, neue Service-Box (Material/Pflege/Größen) + Cross-Sell "Dieses Motiv auch auf …" + Brand-Recap.
- Kategorie-Bottom: drei Flair-Sections unter Pagination (Cross-Sell, Mini-FAQ, Brand-Recap).
- **Override per Theme:** Implementiert primär als `themes/kleinstadtpflanze/pages/*.yaml` Overrides + neue Block-Components, die theme-agnostisch sind.

**Business Value:**
- Shop sieht beim Launch nicht "leer" aus, ohne Marketing-Lügen zu erzeugen.
- Klares "Look & Feel" passend zum lokalen Charakter der Marke (kein DTC-Marketing-Theater).
- Mehrfach-Verwendung des gleichen Motivs auf Produkttypen wird via Cross-Sell sichtbar gemacht (Browse-Anreiz erhöht).

---

## Scope & Boundaries

| In Scope |
|----------|
| Neue Block-Components: `editorial-mini` (Mini-About, 2-col Image+Text), `faq-accordion` (Service-FAQ), `motif-grid` (Motiv-Tile-Grid mit Cross-Link), `service-box` (Material/Pflege/Größen-Box, kategorie-spezifisch), `motif-cross-sell` ("Dieses Motiv auch auf …"), `brand-recap` (schmale Brand-Bar) |
| HeroBlock-Erweiterung: 2-col-Variante (Tag, Headline, Subline, CTA + Ghost-CTA, Visual rechts, schmale Trust-Row mit ehrlichen Kurz-Infos) — **steuerbar via YAML-`variant: "two-col"`**, default-Variante bleibt unverändert |
| ProductImageGallery-Erweiterung: Vertical-Thumbs-Layout auf Desktop (80px Spalte links, Main rechts); Mobile bleibt unverändert (Touch-Swipe + Dots) |
| PDP-Layout-Refactor: `product-description` aus eigener Section unten in `product-purchase`-Spalte integrieren (oder als neuer kombinierter Block); untere `product-description`-Section entfernt |
| Theme-Override: `themes/kleinstadtpflanze/pages/home.yaml`, `themes/kleinstadtpflanze/pages/product.yaml`, `themes/kleinstadtpflanze/pages/category.yaml` |
| Trust-Badges-Block (existiert): Texte für Kleinstadtpflanze ehrlich kürzen ("Versand DE 3–5 Werktage", "30 Tage Rückgabe", "Sichere Zahlung") |
| Aus default-`home.yaml` bleibende Default-Sections: nicht ändern (anderes Theme nutzt sie evtl.) |

| Out of Scope |
|--------------|
| **DealBar mit Countdown** (Marketing-Theater, fake urgency) |
| **Editorial-Block "Werkstatt besuchen / Familienbetrieb"** (unehrlich bei POD) |
| **Reviews-Summary mit Rating-Distribution-Bars** (keine Bewertungs-Basis) |
| **Trust-Theater** ("4,9★ aus 2.180 Bewertungen", "Klimaneutral", "Made in Germany", "Familienbetrieb seit …") |
| **Newsletter-Block für kleinstadtpflanze-Theme** (User explizit raus); existierender `newsletter-signup` Block bleibt verfügbar für andere Themes |
| **Testimonials-Block für kleinstadtpflanze-Theme** (keine echten Testimonials zum Launch); Block bleibt im Registry für andere Themes |
| **Featured-Collection für kleinstadtpflanze-Theme** (zu wenig Produkte) |
| **UtilBar (Phone/Mail + Secondary-Nav-Row über Header)** — separates Chrome-Polish-Feature, falls überhaupt |
| **MenuRow (Inline-Kategorien-Nav als 2. Header-Row)** — separates Chrome-Polish-Feature |
| **HeroC (1 big + 4 small editorial tiles)** — User wählte HeroA |
| **Sticky-ATC-Bar (PDP)** — vorhanden in Design E (`.e-sticky`), aber separate Concern |
| **Tabs auf PDP** (Beschreibung / Material / Versand) — User wählte direkte Integration in Purchase-Spalte, nicht Tabs |
| Hero-Visual-Asset-Pipeline (das Bild rechts im Hero ist Content/Asset, kein Code-Feature) |
| Motiv-Taxonomie in WooCommerce (Tags / Custom Taxonomy für "Motiv-ID") — wird in Slice 2 als **technische Abhängigkeit** geprüft, ggf. eigenes Feature (siehe Open Questions Q2) |
| Spreadshirt-Farbvariant-Erweiterungen (existiert bereits via `extractVariantOptions`) |

---

## Current State Reference

> Wird unverändert weitergenutzt:

- **Theming-Engine** (`frontend/scripts/generate-theme.mjs` + `themes/default/theme.yaml` + `themes/kleinstadtpflanze/theme.yaml`): Style-Tokens (Forest, Terracotta, Cream, Ink-Stufen, Border-Stufen, Radius, Shadows) — Phase 1 abgeschlossen
- **Block-Pipeline** (`lib/blocks/registry.ts`, `lib/blocks/page-config.ts`, `lib/blocks/data-loaders.ts`, `lib/blocks/section-renderer.tsx`): YAML-konfigurierte Sections + Blocks, 3-Tier-Lookup (slug → theme → default)
- **Existierende Blocks (27)** im Registry — 6 davon sind direkt für dieses Feature relevant:
  - `hero` (single-col Default; wird erweitert)
  - `category-showcase` (Tile-Grid für Produkttypen)
  - `product-grid` (4-col Cards)
  - `trust-badges` (Icon + Text Items)
  - `product-recommendations` (Cross-Sell, source `related`)
  - `breadcrumb`, `page-heading`, `filter-chips`, `product-count`, `sort-bar`, `pagination`, `empty-state` (Kategorie-Stack)
- **PDP-Komponenten**:
  - `ProductImageGallery` (`components/product/product-image-gallery.tsx`): Touch-Swipe + Dots(Mobile) / Thumbnails(Desktop) — Layout wird erweitert um Vertical-Thumbs-Variante
  - `ProductVariantSelector` + `extractVariantOptions` (`lib/product/variant-utils.ts`): Größe + Farbe via WC-Attributes (`pa_size`/`pa_groesse`, `pa_color`/`pa_farbe`) — funktional komplett vorhanden
  - `product-purchase-block`: Name + Preis + Variants (existing); wird um Beschreibungs-Slot erweitert
  - `product-description-block`: Card mit `prose`-Styling (existing); bleibt als Component, aber wird auf PDP **nicht** mehr als eigene Section gerendert
- **Layout-Komponenten**: `Header` (sticky, Logo + Search-Link + CartIcon + MobileMenu), `Footer` — bleiben unangetastet (UtilBar/MenuRow sind Out-of-Scope)
- **GraphQL-Queries**: `GET_PRODUCT`, `GET_PRODUCT_CATEGORIES`, `productCategories`, `products_by_category` — existieren; ggf. neue Query für Motiv-basierten Cross-Sell (siehe Slice 2)

---

## UI Patterns

### Reused Patterns

| Pattern Type | Component | Usage in this Feature |
|--------------|-----------|------------------------|
| YAML-Block-Config | `themes/kleinstadtpflanze/pages/*.yaml` | Override für home, product, category — neue Sections + Reorder |
| Section-Renderer | `lib/blocks/section-renderer.tsx` | Neue Blocks werden via Registry registriert, Renderer bleibt unverändert |
| Suspense + Skeleton | `app/page.tsx` SKELETON_MAP-Pattern | Neue Blocks bekommen analoge Skeleton-Exports |
| Trust-Badges-Block | `components/blocks/trust-badges-block.tsx` | Texte ehrlich kürzen, sonst unverändert |
| Product-Recommendations-Block | `components/blocks/product-recommendations-block.tsx` | Auf PDP unter Hauptbereich + auf Kategorie-Bottom (cross-category) — ggf. Heading-Param verschieden |
| ProductVariantSelector | `app/produkt/[slug]/product-variant-selector.tsx` | Keine Änderung — Größe + Farbe schon abgedeckt |

### New Patterns

| Pattern Type | Description | Rationale |
|--------------|-------------|-----------|
| **Editorial-Mini** (2-col Image+Text-Section) | Section mit `surface-alt`-Hintergrund, links Bild, rechts Tag + Headline + 2 Sätze + 1 CTA. Höhe deutlich kleiner als Design-E `.e-editor` (kein "Werkstatt"-Theater). | Mini-About füllt Section-Höhe ohne Storytelling-Lüge. Inhalt: "Was ist Kleinstadtpflanze" — 2-3 Sätze, neutral. |
| **FAQ-Accordion** (Service-FAQ) | Liste aufklappbarer Q/A-Rows, Border-Top + Border-Bottom + 1 Sek Animation. Inline-konfigurierbar via YAML (Headline + Items als `[{q, a}]`). | Ehrlich-nützlich (Versand, Druck-Partner-neutral, Rückgabe, Pflege). Füllt Homepage-Section + dient als Service-Touchpoint auf Kategorie/PDP-Snippet. |
| **Motif-Tile-Grid** (5-6 Tiles) | Grid (Desktop 3-col / Mobile 2-col), jede Tile = Motiv-Bild + Motiv-Name + Cross-Link. Tile-Style analog `.e-cat` (cream-Bg, hover lift). | Macht das Motiv-zu-Produkt-Mapping (1 Motiv → mehrere Produkttypen) sichtbar als Browsing-Einstieg; ersetzt fehlende Produkt-Vielfalt durch Motiv-Vielfalt. |
| **Service-Box** (Material/Pflege/Größen) | Box mit `surface-alt`-Bg, Icon + bold Label + 1 Zeile Detail je Item. 2-3 Items (kategorie-spezifisch via YAML-Config oder pro Produkt-Meta). | Kategorie-Kontext: Tasse → "Spülmaschinenfest", Beutel → "Tragkraft 8 kg", Shirt → "Größentabelle". Service statt Marketing. |
| **Motif-Cross-Sell** ("Dieses Motiv auch auf …") | 2-4 Tiles (kleines Format), Produkte mit gleichem Motiv aber anderem Produkttyp. Inkl. Produkttyp-Label ("Tasse", "Beutel"). | Nutzt das Motiv-Mapping (1 Motiv mehrfach), erhöht Cross-Sell-Chance ohne neue Produkte zu erfinden. **Technische Abhängigkeit:** Motiv-Identität in WC → siehe Open Questions Q2. |
| **Brand-Recap** (schmale Bar) | 1-zeilige Section, zentriert: kleines Logo-Mark + Brand-Name + Tagline (1 Satz). Border-Top + Border-Bottom, `surface-alt`-Bg. | Wiederkehrender Brand-Touch vor Footer auf PDP/Kategorie/Homepage; sehr ruhig, kein CTA. |
| **Hero-Variante "two-col"** | Bestehender `hero`-Block bekommt YAML-Schalter `variant: "two-col"`: 2-spaltiges Grid (Tag, Headline mit brand-color span, Subline, 2 CTAs, schmale Trust-Row → Visual rechts mit Image/Placeholder). | Default-Hero bleibt unverändert für andere Themes; neue Variante deckt Design-E `.e-hero-a`-Layout ab; `Trust-Row` Items YAML-konfigurierbar (3 ehrliche Kurz-Infos). |

> Codebase-Recherche durchgeführt vor Pattern-Entscheidung:
> - `components/blocks/*-block.tsx` (27 Blocks gelistet)
> - `components/blocks/testimonials-block.tsx` (Card-Muster für Service-Box wiederverwendbar)
> - `components/blocks/category-showcase-block.tsx` (Tile-Pattern für Motif-Grid)
> - `components/blocks/product-recommendations-block.tsx` (Slider/Grid-Pattern für Motif-Cross-Sell)

---

## User Flow

> Reines Layout/Composition-Feature. Nutzerseitige Flows ändern sich nur **passiv** (mehr Sections sichtbar). Kein neuer State, keine neuen Interaktionen außer FAQ-Accordion-Toggle.

### FAQ-Accordion (einzige neue Interaktion)
1. Nutzer scrollt zur FAQ-Section auf Homepage (oder Snippet auf Kategorie-Bottom).
2. Nutzer klickt auf Frage-Row → Antwort-Block fährt aus (max-height-Transition 250ms).
3. Nutzer klickt auf gleiche Row erneut → Antwort fährt zu.
4. Nutzer klickt auf andere Row → bisherige bleibt offen ODER schließt sich (Single-Open-Mode? siehe Open Questions Q3).

**Error Paths:**
- N/A (kein async, kein Form, kein Submit).

---

## UI Layout & Context

### Screen: Homepage (Theme `kleinstadtpflanze`)

**Position:** `/` (Root)
**When:** Initial Landing, Logo-Klick, Brand-Recap-Klick

**Section-Reihenfolge** (von oben nach unten):
1. **Hero (variant `two-col`)** — Tag (Cream-Bg, Terracotta-Text) • h1 (Brand-Color-Span) • Subline (text-secondary) • CTA + Ghost-CTA • schmale Trust-Row (3 ehrliche Items) | Visual rechts (Image oder Placeholder)
2. **Category-Showcase** (existiert) — 4-5 Kategorie-Tiles (1 Row wenn ≤5)
3. **Product-Grid** (existiert) — Bestseller / Featured (3-4 Produkte)
4. **Motif-Tile-Grid** (NEU) — 5-6 Motive (Stadt/Thema), 3-col Desktop / 2-col Mobile
5. **Editorial-Mini** (NEU, "Was ist Kleinstadtpflanze") — 2-col mit `surface-alt`-Bg
6. **FAQ-Accordion** (NEU) — 4-6 Service-Fragen
7. **Trust-Badges** (existiert, Texte ehrlich gekürzt) — Versand • Rückgabe • Bezahlung
8. **Brand-Recap** (NEU) — schmale Bar vor Footer

### Screen: PDP (Theme `kleinstadtpflanze`)

**Position:** `/produkt/[slug]`
**When:** Nach Klick auf Produkt-Card

**Section-Reihenfolge:**
1. **Hauptbereich** (2-col, `gap-16`):
   - **Links:** ProductGallery mit Vertical-Thumbs-Layout (Desktop). Spalten-Grid: 80px Thumbs | 1fr Main. Mobile bleibt Touch-Swipe + Dots.
   - **Rechts:** Product-Purchase-Block erweitert: h1 Name • Preis • Variants (Größe + Farbe) • ATC-Button • **Beschreibung (NEU integriert)** • **Service-Box (NEU, Material/Pflege/Größen)**
2. **Trust-Badges** (existiert, Texte gekürzt) — 1-col Section
3. **Motif-Cross-Sell** (NEU, "Dieses Motiv auch auf …") — 2-4 Tiles
4. **Product-Recommendations** (existiert, "Vielleicht auch was für dich") — 4 Produkte, source `related`
5. **Mini-FAQ-Snippet** (NEU, Subset des Homepage-FAQ; 3 Fragen, kategorie-relevant)
6. **Brand-Recap** (NEU)

> **Entfernt:** `product-description` als eigene 1-col-Section unter Galerie+Purchase. `product-reviews` bleibt im Registry, aber nicht mehr in `kleinstadtpflanze/pages/product.yaml`.

### Screen: Kategorie (Theme `kleinstadtpflanze`)

**Position:** `/kategorie/[slug]`
**When:** Nach Klick auf Kategorie

**Section-Reihenfolge** (von oben nach unten):
1. Breadcrumb (existiert)
2. Page-Heading (existiert)
3. Filter-Chips (existiert)
4. Product-Count (existiert)
5. Sort-Bar (existiert)
6. Product-Grid (existiert)
7. Pagination (existiert)
8. Empty-State (existiert, fallback)
9. **Product-Recommendations** (existiert, "Vielleicht auch was für dich" — cross-category, NEU **unter** Pagination)
10. **Mini-FAQ-Snippet** (NEU, kategorie-relevante Fragen; 3 Items)
11. **Brand-Recap** (NEU)

---

## UI Components & States

| Element | Type | Location | States | Behavior |
|---------|------|----------|--------|----------|
| `hero[variant=two-col]` | Block-Variante | Homepage | static (kein State) | Render 2-col-Layout. CTA → href aus YAML. Ghost-CTA → href aus YAML (optional). |
| `editorial-mini` | Block | Homepage | static | Render 2-col Image+Text. CTA → href aus YAML. Bild via `image_url` YAML-Param oder default-Placeholder. |
| `faq-accordion .row` | Block-Item | Homepage, PDP-Snippet, Kategorie-Snippet | `closed`, `open` | Klick auf `.h` (Header) → toggle. `open`: max-height auf Inhalt-Höhe (250ms ease). Plus-Icon rotiert/wechselt zu Minus. **Single-Open-Mode** (siehe Q3). |
| `motif-grid .tile` | Block-Item | Homepage | `default`, `hover` | Hover: 2px translate + box-shadow. Klick → Navigation zu Motiv-Filter (URL aus YAML-`href`-Pattern, z.B. `/suche?motiv={slug}`). |
| `service-box .item` | Block-Item | PDP (in Purchase-Spalte) | static | Render Icon + Label + Detail-Text. Items aus YAML-Config (kategorie-spezifisch) oder Produkt-Meta-Feld (siehe Q4). |
| `motif-cross-sell .tile` | Block-Item | PDP | `default`, `hover` | Klick → Navigation zu zugehörigem Produkt-PDP. Hover analog product-card. |
| `brand-recap` | Block | Homepage, PDP, Kategorie | static | Statisch, kein State. Klick auf Brand-Name → `/`. |
| `trust-badges` (existiert, neue Texte) | Block | Homepage, PDP | static | Wie aktuell, nur YAML-Inhalt aktualisiert |
| `product-gallery[layout=vertical-thumbs]` | Layout-Variante | PDP | active-thumb-index | Vertical-Thumbs-Spalte 80px links. Klick auf Thumb → Main-Bild wechselt. Mobile: bleibt Layout `default` (Touch-Swipe + Dots unten). |

---

## Feature State Machine

> Reines Composition-Feature, keine globale State-Machine. Einzige Feature-Lokal-States:

### FAQ-Accordion (per Item)

| State | UI | Available Actions |
|-------|----|--------------------|
| `closed` | Frage sichtbar, `+`-Icon rechts, `max-height: 0` | Klick auf Header |
| `open` | Frage sichtbar, `−`-Icon rechts, `max-height: <content>` mit 250ms-ease | Klick auf Header (toggles back) |

### Transitions (FAQ-Item)

| Current State | Trigger | UI Feedback | Next State | Business Rules |
|---------------|---------|-------------|------------|----------------|
| `closed` | `.h` → click | Plus → Minus, Inhalt fährt aus (max-height 0 → content-height, 250ms ease) | `open` | Single-Open-Mode: schließt andere offene Items vorher (siehe Q3) |
| `open` | `.h` → click | Minus → Plus, Inhalt fährt zu | `closed` | -- |

### Image-Gallery (Vertical-Thumbs auf Desktop)

| State | UI | Available Actions |
|-------|----|--------------------|
| `idle` | Main = images[activeIndex], Thumbs zeigen alle, aktiver Thumb hat `border-brand` | Klick auf Thumb |
| `transitioning` | (kein eigener State — Image-Switch ist instant) | -- |

### Transitions (Image-Gallery)

| Current State | Trigger | UI Feedback | Next State | Business Rules |
|---------------|---------|-------------|------------|----------------|
| `idle` | `.thumb[i]` → click | activeIndex = i, Main wechselt instant, alter Thumb verliert `on`-Class, neuer bekommt sie | `idle` | -- |

---

## Business Rules

- **Theme-Isolation:** Alle YAML-Overrides liegen unter `themes/kleinstadtpflanze/pages/` — Default-Theme bleibt unverändert (`zweiter-shop` und `default` nutzen weiter Testimonials/Newsletter/Featured-Collection).
- **Block-Registry-Konvention:** Neue Blocks (`editorial-mini`, `faq-accordion`, `motif-grid`, `service-box`, `motif-cross-sell`, `brand-recap`) sind theme-agnostisch — also als generische Patterns nutzbar (auch Default-Theme könnte sie später nutzen).
- **HeroBlock-Variant ist additiv:** `variant: "two-col"` ist optional. Ohne Param → bestehendes Single-Col-Verhalten (Backward Compatible).
- **ProductGallery-Variant ist additiv:** Layout-Switch `layout: "vertical-thumbs"` (oder analog) als Param. Ohne Param → bestehendes Layout (Backward Compatible).
- **Motif-Cross-Sell hängt von Motiv-Identität in WC ab.** Wenn Motiv-Tag/Custom-Field nicht existiert → Block rendert `null` (graceful degradation). **Datenmodell-Klärung in Slice 2.**
- **Service-Box-Inhalt:** Entweder YAML-statisch pro Kategorie ODER Produkt-Meta-Feld in WC. Entscheidung in Slice 2 (siehe Q4).
- **Trust-Row im Hero:** YAML-konfigurierbar (Items als `[{icon, text}]`). Default-Set für `kleinstadtpflanze`: 3 ehrliche Kurz-Infos. Keine Numeric-Claims (keine `4,9★`, keine `2.180 Bewertungen`, keine `Klimaneutral`).
- **FAQ-Single-Open-Mode:** Nur ein Item gleichzeitig offen (analog Design-E-`.e-acc`-Behavior). Tracking via lokalem State (kein URL-State, kein localStorage).
- **Brand-Recap-Tagline:** YAML-konfigurierbar. Default-Tagline-Vorschlag: TBD (User-Entscheidung in Slice 1).

---

## Data

| Field | Required | Validation | Notes |
|-------|----------|------------|-------|
| `hero.variant` | No | Enum: `default` (or absent) \| `two-col` | YAML-Param auf existierendem `hero`-Block |
| `hero.tag` | No | string | Kleines Label über h1 (only `two-col`-Variante) |
| `hero.ctaSecondary` | No | `{ text, href }` | Ghost-CTA neben Primär-CTA |
| `hero.trustItems` | No | `[{icon, text}]` (max. 3-4) | Trust-Row Items, ehrliche Kurz-Infos |
| `hero.visual.imageUrl` | No | URL | Visual rechts; fallback: SVG-Placeholder |
| `editorial-mini.tag` | No | string | Optional Label-Pill |
| `editorial-mini.headline` | Yes | string | h2 |
| `editorial-mini.text` | Yes | string (1-3 Sätze) | Body-Text |
| `editorial-mini.cta` | No | `{ text, href }` | Optional CTA |
| `editorial-mini.image_url` | No | URL | Visual links |
| `faq-accordion.headline` | No | string | Section-Headline |
| `faq-accordion.items` | Yes | `[{q, a}]` (min. 1) | Q/A-Liste; `a` ist plain text oder Markdown-rendered (Entscheidung Slice 1) |
| `motif-grid.headline` | No | string | Section-Headline |
| `motif-grid.items` | Yes | `[{name, image_url, href}]` (min. 4) | Motiv-Tiles |
| `service-box.items` | Yes | `[{icon, label, detail}]` (min. 2) | Service-Items pro PDP |
| `service-box.source` | No | Enum: `inline` \| `category` \| `product` | Wo kommen Items her? Default `inline`. Bei `category`/`product`: Lookup über WC-Meta (siehe Q4) |
| `motif-cross-sell.heading` | No | string | Default: "Dieses Motiv auch auf …" |
| `motif-cross-sell.source` | -- | -- | Datenquelle: gleicher Motiv-Tag/-ID, andere Produkttypen (siehe Q2) |
| `brand-recap.tagline` | Yes | string | 1 Satz |
| `brand-recap.logoText` | No | string | Default: `process.env.NEXT_PUBLIC_SHOP_NAME` |
| `trust-badges.items[].text` | -- | -- | Texte für `kleinstadtpflanze` ändern (Inhalt) |
| Galerie `layout` Param | No | Enum: `default` \| `vertical-thumbs` | YAML-Param auf `product-gallery`-Block |

---

## Implementation Slices

> Testbare, deploybare Inkremente. Jeder Slice liefert einen abgeschlossenen User-Value.

### Dependencies

```
Slice 1 (Homepage-Stack + neue Blocks: editorial-mini, faq-accordion, motif-grid, brand-recap, hero two-col)
   │
   ├── Slice 2 (PDP-Refactor: Galerie vertical-thumbs, Beschreibung integriert, service-box, motif-cross-sell, mini-FAQ, brand-recap)
   │
   └── Slice 3 (Kategorie-Bottom-Flair: recommendations + mini-FAQ + brand-recap)
```

> **Slice 2 hängt von Slice 1 ab** für: `faq-accordion` (wird auf PDP als Mini-FAQ subset benutzt), `brand-recap`. **Slice 3 hängt von Slice 1 ab** für: `faq-accordion`, `brand-recap`. Slice 2 und Slice 3 sind voneinander unabhängig.

### Slices

| # | Name | Scope | Testability | Dependencies |
|---|------|-------|-------------|--------------|
| 1 | **Homepage-Stack** | Neue Blocks: `editorial-mini`, `faq-accordion`, `motif-grid`, `brand-recap`. HeroBlock-Erweiterung um `variant: "two-col"` mit Tag/Subline/CTAs/Trust-Row/Visual. `themes/kleinstadtpflanze/pages/home.yaml` mit neuer Section-Reihenfolge (Hero two-col, Categories, Products, Motif-Grid, Editorial-Mini, FAQ, Trust-Badges, Brand-Recap). Trust-Badges-Texte für kleinstadtpflanze ehrlich kürzen via YAML. Default-`home.yaml` bleibt unverändert. | Visual: `NEXT_PUBLIC_THEME=kleinstadtpflanze pnpm dev` zeigt neue Homepage-Sequenz. Funktional: FAQ-Accordion expandiert/collapsed Items mit Animation. Motif-Grid-Tiles linken auf YAML-href. Hero two-col responsive (Mobile: Stack, Desktop: 2-col). Default-Theme rendert weiterhin alte Sequenz. Unit-Tests für jede neue Block-Component. | -- |
| 2 | **PDP-Refactor + Flair** | `themes/kleinstadtpflanze/pages/product.yaml` Override. ProductImageGallery erweitern: `layout: "vertical-thumbs"` Param, Desktop 80px-Thumbs-Spalte links + Main rechts. ProductPurchase erweitern: Beschreibung integrieren (description aus `data.description` rendern; HTML-`prose`-Styling beibehalten). Neue Blocks: `service-box` (kategorie-spezifisch via inline-YAML als Default; ggf. Produkt-Meta in Iteration), `motif-cross-sell` (graceful null wenn Motiv-Tag fehlt). PDP-Section-Reihenfolge: Hauptbereich(2-col) → Trust-Badges → Motif-Cross-Sell → Product-Recommendations → Mini-FAQ → Brand-Recap. `product-description` als untere Section entfernt. `product-reviews` aus kleinstadtpflanze-PDP entfernt. | Visual: `kleinstadtpflanze`-PDP zeigt Vertical-Thumbs (Desktop), Beschreibung in Purchase-Spalte unter ATC, Service-Box mit kategorie-relevanten Items. Mobile: Galerie bleibt Touch-Swipe+Dots. Funktional: Variant-Selector (Größe/Farbe) funktioniert wie bisher. Motif-Cross-Sell rendert `null` wenn keine Motiv-Daten — kein Layout-Bruch. Default-PDP unverändert. Unit-Tests für service-box, motif-cross-sell. Integration-Test für Galerie-Layout-Switch. | Slice 1 (für `faq-accordion`, `brand-recap`) |
| 3 | **Kategorie-Bottom-Flair** | `themes/kleinstadtpflanze/pages/category.yaml` Override: aktuelle 8 Sections beibehalten, ergänzen um Sections 9–11 (Product-Recommendations cross-category, Mini-FAQ, Brand-Recap) **unter** Pagination/Empty-State. Product-Recommendations-Block ggf. um neuen Source-Mode `cross-category` (Produkte aus anderen Kategorien) — alternativ `featured`-Mode wiederverwenden. | Visual: `kleinstadtpflanze`-Kategorie-Page zeigt unter Pagination 3 zusätzliche Sections. Funktional: Recommendations rendert auch wenn aktuelle Kategorie leer ist (zeigt cross-category Suggestions). Default-Kategorie-Page unverändert. | Slice 1 (für `faq-accordion`, `brand-recap`) |

### Recommended Order

1. **Slice 1: Homepage-Stack** — Liefert sofort sichtbaren Wert (User sieht den Shop "voller"). Etabliert die 3 wiederverwendbaren neuen Blocks (`faq-accordion`, `brand-recap`, `editorial-mini`) und Hero-Variant-Pattern. Voraussetzung für Slice 2 + 3.
2. **Slice 2: PDP-Refactor + Flair** — Hochwertigster Touchpoint nach Homepage-Wirkung. Galerie-Layout + Beschreibungs-Integration sind der größte UX-Sprung. Service-Box + Motif-Cross-Sell sind add-ons (graceful degradation, falls Daten fehlen).
3. **Slice 3: Kategorie-Bottom-Flair** — Niedrigste Priorität, aber kleinster Aufwand (nur YAML + ggf. ein Block-Param). Schließt das Theme-weite Feel ab.

### Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| Motiv-Identität fehlt in WC → Motif-Cross-Sell + Motif-Grid sind blind | Slice 2 prüft als erstes WC-Datenmodell. Falls keine Motiv-Taxonomie: Motif-Cross-Sell rendert `null` (graceful), Motif-Grid bekommt Inline-YAML-Items + freie Hrefs (z.B. `/suche?q={motivname}` als Fallback). Echte Motiv-Taxonomie wird Folge-Feature. |
| Service-Box-Inhalt pro Produkt zu pflegen ist Aufwand | Default: kategorie-statisch via Inline-YAML pro Kategorie-Slug (z.B. `themes/kleinstadtpflanze/pages/product/tasse.yaml`). Produkt-Meta-Feld als spätere Iteration. |
| Galerie-Layout-Switch könnte bestehende Tests brechen | Default-Layout bleibt unverändert; Switch nur bei explizitem `layout="vertical-thumbs"`-Param. Snapshot-Tests beider Layouts. |
| Beschreibungs-HTML in Purchase-Spalte überschreitet Spaltenbreite | `prose prose-sm` + `max-w-prose` als Container-Klasse. Falls Beschreibung sehr lang: Read-More-Toggle als spätere Iteration (out of scope hier). |
| FAQ-Accordion-Animation bei Mobile-Tap zu langsam | 250ms ist Design-E-Wert; bei Bedarf auf 200ms reduzieren. Reduced-Motion respektieren (Prefers-Reduced-Motion → Snap-Toggle). |
| Hero-Visual-Asset: User hat noch kein Bild | Fallback: existing `Placeholder`-Pattern (SVG mit Brand-Color). Block rendert immer, auch ohne `image_url`. |
| Brand-Recap-Tagline: User hat keine Tagline definiert | Slice 1 fragt User nach Tagline beim Implementieren ODER nutzt Platzhalter (`process.env.NEXT_PUBLIC_SHOP_TAGLINE` mit sinnvollem Default). |
| `kleinstadtpflanze` ist nicht der Default-Theme im Repo | Tests müssen `NEXT_PUBLIC_THEME=kleinstadtpflanze` setzen. Vitest-Setup erweitern, falls nötig. |

---

## Context & Research

### Similar Patterns in Codebase

| Feature | Location | Relevant because |
|---------|----------|------------------|
| Phase-1 Style-Tokens (gemerged) | `specs/2026-04-16-kleinstadtpflanze-design-e/` | Setzt Forest-Brand-Color + Cream-Surface + Terracotta-Accent + Source-Serif-Heading; alle neuen Blocks nutzen diese Tokens automatisch |
| Block-Registry + YAML-Page-Config | `lib/blocks/registry.ts` + `lib/blocks/page-config.ts` + `themes/default/pages/*.yaml` | Etabliertes Muster für additive Block-Konfiguration; neue Blocks folgen dem identischen Pattern (Component + Skeleton + Registry-Entry + Type-Definition) |
| Testimonials-Block (3-col Card-Pattern) | `components/blocks/testimonials-block.tsx` | Pattern-Vorlage für `service-box`-Items (Icon + Label + Body in Card) |
| Category-Showcase (Tile-Grid) | `components/blocks/category-showcase-block.tsx` | Pattern-Vorlage für `motif-grid` (Tile mit Image + Name + Cross-Link) |
| Product-Recommendations (Cross-Sell) | `components/blocks/product-recommendations-block.tsx` | Wiederverwendbar für `motif-cross-sell` (gleicher Aufbau, andere Datenquelle) |
| ProductImageGallery (Touch + Dots + Thumbs) | `components/product/product-image-gallery.tsx` | Existierender Code wird um `layout`-Variante erweitert; Mobile-Verhalten unverändert |
| ProductVariantSelector + extractVariantOptions | `app/produkt/[slug]/product-variant-selector.tsx` + `lib/product/variant-utils.ts` | Spreadshirt-Farben + Größen funktionieren bereits; KEINE Änderung nötig |
| AnnouncementBar mit dismissible localStorage | `components/blocks/announcement-bar-block.tsx` | Pattern für `'use client'` + localStorage in einem Block (für FAQ-Accordion-State, falls persistent gewünscht) |

### Web Research / External

| Source | Finding |
|--------|---------|
| Design-E-Bundle (`/home/dev/pod-shop/.tmp/Kleinstadtpflanze Shop E _Standalone_.html`) | Bundler-HTML mit base64+gzip Manifest. Extrahiert nach `/tmp/kp-design-e/`: `template.html` (35KB) + 4 JS-Files (Source-Code) + 9 woff2-Fonts. Komplette Section-Sequenz aus React-Source rekonstruiert: Announce → UtilBar → Nav → MenuRow → HeroA/B/C → USPs → Categories → Bestsellers → DealBar → Editorial → Neu → CityGrid → Reviews → TrustBar → Newsletter → Footer |
| Design-E React-Source (`/tmp/kp-design-e/assets/bbb7ae74.js`) | Layout-Constants: 4-col Product-Grid, 6-col Categories, 5-col Cities, 80px Vertical-Thumbs für PDP. Hero-A: 2-col 1.1fr/1fr mit Trust-Row. FAQ-Accordion: max-height-Transition 250ms. Sticky-ATC: appears after `scrollY > 600`. |
| Anthropic Design API (`api.anthropic.com/v1/design/h/...`) | URLs sind session-/auth-gebunden (404 ohne Cookie); curl/WebFetch von außerhalb der Browser-Session geht nicht. User muss HTML lokal speichern. |
| Spreadshirt POD-Reality | Standard-Service: Print-on-Demand, mehrere Farben + Größen pro Produkt. Versand DE 3-5 Werktage. Keine Klimaneutral-Behauptung von Default. **Keine Marketing-Lüge erforderlich, wenn man ehrlich-neutral kommuniziert.** |

### Realitäts-Check (User-Feedback während Discovery)

> Quote (User, 2026-04-26): _"Das Problem: Das sind tolle Features, aber das ist ein lokaler merch shop mit mit einer handvoll produkte, die bei einem vermutlich nicht extra nachhaltigem pod provider gedrckt werden.. da brauche ich kein editorial block, keine testimonials etc. ich brauche was nettes, was dazu passt"_

→ Konsequenz: Design-E-Marketing-Sections (DealBar, Editorial-Werkstatt, Reviews-Summary, Trust-Theater) sind **Out-of-Scope** zugunsten von 4 ehrlichen Patterns (Mini-About, FAQ, Motiv-Grid, Brand-Recap) + Service-orientierter PDP-Flair.

---

## Open Questions

| # | Question | Options | Recommended | Decision |
|---|----------|---------|-------------|----------|
| 1 | Brand-Recap-Tagline: welcher Satz? | A) Vorschlag vom User in Slice 1 / B) Platzhalter mit Env-Var (`NEXT_PUBLIC_SHOP_TAGLINE`) | A — User soll während Implementation bestimmen | -- |
| 2 | Motiv-Identität in WooCommerce — wie sind Motive aktuell modelliert? Tag, Custom-Taxonomy, Custom-Field, gar nicht? | A) WC-Tag (`product_tag`) / B) Custom-Taxonomy `motif` / C) Produkt-Meta-Feld / D) Aktuell gar nicht — separates Setup-Feature nötig | Slice 2 prüft via WP-Admin oder Schema-Inspektion bevor `motif-cross-sell`-Implementation startet | -- |
| 3 | FAQ-Accordion: Single-Open-Mode (max. 1 Item offen) oder Multi-Open (mehrere gleichzeitig)? | A) Single-Open (Design-E-Verhalten) / B) Multi-Open | A — entspricht Design-E-Vorlage und reduziert visuellen Lärm | -- |
| 4 | Service-Box-Inhalt — woher kommen die Items? | A) Statisch pro Kategorie via separater YAML pro Kategorie-Slug / B) WC-Produkt-Meta (zusätzliches Custom-Field-Setup nötig) / C) Hybrid: Default per Kategorie + Override per Produkt | A für Slice 2 (schnell, keine WC-Erweiterung) — C als spätere Iteration | -- |
| 5 | Motif-Grid-Tile-Click — Ziel-Route? | A) `/suche?motiv={slug}` (nutzt Suchseite) / B) eigene Route `/motiv/{slug}` / C) WC-Tag-Filter-Route `/produkt-tag/{slug}` (WP-Default) | Hängt von Q2 ab — wenn Tag-basiert: C; wenn Custom-Taxonomy: B; sonst A als Fallback | -- |
| 6 | Beschreibung in Purchase-Spalte: rendert HTML (WP-Editor-Output) — wie viel Vertikal-Platz darf es brauchen? | A) Voll rendern (kann sehr lang werden) / B) Initial 4-5 Zeilen + "Mehr lesen"-Toggle / C) Read-Limit auf 200 Wörter mit fade-out | A für Slice 2 (Simplest); B als Iteration falls Beschreibungen sehr lang | -- |
| 7 | Mini-FAQ-Snippet auf PDP/Kategorie: gleiche Items wie Homepage oder kuratiertes Subset? | A) Festes Subset (3 Items: Versand, Rückgabe, Pflege) / B) Per Kategorie unterschiedlich / C) Per YAML konfigurierbar mit Default | C — flexibel, mit Default = Subset-3-Items aus Homepage-FAQ | -- |
| 8 | `kleinstadtpflanze`-Theme aktuell aktiviert? | A) Ja, `NEXT_PUBLIC_THEME=kleinstadtpflanze` ist gesetzt / B) Default ist noch aktiv | Vor Slice 1: User-Confirm | -- |

---

## Research Log

| Date | Area | Finding |
|------|------|---------|
| 2026-04-26 | External | Design-Bundle ist Anthropic-Design-Tool-Standalone-HTML (Bundler-Format mit base64+gzip Manifest in `<script type="__bundler/manifest">`). Vom Server (curl/WebFetch) nicht ladbar — auth-bound. |
| 2026-04-26 | Local | User legte HTML unter `.tmp/Kleinstadtpflanze Shop E _Standalone_.html` (1.66 MB). Python-Skript extrahierte Manifest + Template + 4 JS + 9 Fonts nach `/tmp/kp-design-e/`. |
| 2026-04-26 | Codebase | 27 Block-Components im Registry. Existierende Header/Footer/PDP-Komponenten gelesen. Variant-Selector funktional komplett (Größe + Farbe). |
| 2026-04-26 | Codebase | Default-Pages-YAMLs: home.yaml hat 7 Sections, product.yaml 5, category.yaml 8. Block-Pipeline ist additiv via `themes/{theme}/pages/*.yaml` Override. |
| 2026-04-26 | Design-E-CSS | 50+ `.e-*`-Klassen identifiziert: `.e-hero-a/b/c`, `.e-cats`, `.e-products`, `.e-editor`, `.e-cities`, `.e-rev-top/.e-revs`, `.e-deal`, `.e-trust`, `.e-nl`, `.e-foot`, `.e-pdp-*` (8 Stück), `.e-tabs/.e-tab-body`, `.e-acc`, `.e-sticky`, `.e-util/.e-util-in`, `.e-menu/.e-menu-in`, `.e-search`, `.e-pswitch`. |
| 2026-04-26 | Design-E-JS | Vollständige Homepage-Sequenz aus React-Source rekonstruiert (Announce→UtilBar→Nav→MenuRow→Hero→USPs→Categories→Bestsellers→DealBar→Editorial→Neu→CityGrid→Reviews→TrustBar→Newsletter→Footer). Brand-Konzept im Mockup: Schleswig-Holstein-Kleinstädte, Stadt-Motive auf POD-typischen Produkten. |
| 2026-04-26 | User-Brand | User klärte: Kategorien = Produkttypen (Tasse, Flasche, Beutel, …); 1 Motiv pro Produkt-Eintrag; gleiches Motiv erscheint auf mehreren Produkttypen → Motif-Cross-Sell ist sinnvoll. POD-Honesty: neutral umgehen (kein expliziter Spreadshirt-Hinweis, aber auch keine Lüge). |
| 2026-04-26 | User-Realitäts-Check | User wies Marketing-Theater-Patterns (Editorial "Werkstatt", Testimonials, DealBar-Countdown, Trust-Theater) explizit zurück. Stack neu konzipiert auf 4 ehrliche Patterns + Service-orientierte PDP-Flair. |

---

## Q&A Log

| # | Question | Answer |
|---|----------|--------|
| 1 | Gibt es ein GitHub Issue für dieses Feature? | Nein, kein Issue. Traceability eingeschränkt akzeptiert. Branch ohne Issue-ID-Präfix. |
| 2 | Wie kommen wir an den Design-E-HTML-Inhalt (URL liefert 404)? | User legte Datei unter `.tmp/Kleinstadtpflanze Shop E _Standalone_.html` ab. |
| 3 | Welche Anti-Leere-Patterns rein? (initiale Auswahl Editorial/DealBar/Themen-Grid/Reviews-Summary) | User-Realitäts-Check: Editorial + DealBar + Reviews-Summary + Trust-Theater raus (unehrlich bei kleinem POD-Shop). Themen-Grid bleibt als "Motif-Tile-Grid". |
| 4 | Welche Hero/Chrome-Patterns rein? | HeroA-Erweiterung (2-col, Trust-Row) + HeroC initial gewählt; nach Realitäts-Check: nur HeroA bleibt. UtilBar/MenuRow Out-of-Scope. |
| 5 | Reichweite des Features? | Fokus-Slice (Top 3-4 Patterns), nicht Komplett-Übernahme. → 3 Slices entworfen. |
| 6 | Wireframes erstellen? | Nein, Design-E-HTML ist visuelle Referenz. |
| 7 | Wie ist Kleinstadtpflanze strukturiert? | Kategorien = Produkttypen (Tasse, Flasche, Beutel, …); pro Produkt-Eintrag 1 Motiv. |
| 8 | Wie ehrlich/transparent über POD-Partner? | Neutral umgehen (kein "wir drucken selbst", kein expliziter Partner-Name). |
| 9 | Welche ehrlichen Patterns ziehen wir? | Mini-About + FAQ-Accordion + Motiv-Tile-Grid + KEIN Newsletter. Plus Bedarf: PDP + Kategorie-unten brauchen "Flair". |
| 10 | Erscheint ein Motiv auf mehreren Produkttypen? | Ja, gleiches Motiv mehrfach → Motif-Cross-Sell sinnvoll. |
| 11 | Welcher Hero passt? | HeroA (2-col klassisch). |
| 12 | Was sollte auf PDP + Kategorie-unten erscheinen? | Vielleicht-auch-Empfehlungen + Mini-FAQ + Material-/Pflege-/Größen-Box + Brand-Recap + Beschreibung neben Galerie + Galerie-Variante + Farbvarianten (existieren). |
| 13 | Beschreibung-Position auf PDP? | In Purchase-Spalte integriert (untere `product-description`-Section entfällt). |
| 14 | Trust-Row im Hero — was passt ehrlich? | Schmal & ehrlich (z.B. "Versand DE 3–5 Tage · 30 Tage Rückgabe · Faire Auflage"). |
| 15 | Sign-Off? | Ja, Discovery schreiben. |
