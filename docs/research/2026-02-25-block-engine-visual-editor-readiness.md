# Research: Block-Engine Visual Editor & AI Readiness

**Date:** 2026-02-25
**Context:** Review der `block-page-migration` Spec (P0.3) gegen die Zukunftsvision: Visual Editor + AI Support fuer Non-Technical Users
**Status:** Erkenntnisse dokumentiert, keine Aenderungen an laufender Spec

---

## Ausgangslage

Die `block-page-migration` (P0.3) migriert alle Seiten von hardcoded JSX auf YAML-basierte Block-Komposition:

```
Page (YAML) -> Sections[columns 1-4] -> Blocks[span, row-span]
```

Die Frage: Sind diese Architektur-Entscheidungen zukunftsfaehig fuer einen Visual Editor + AI-gestuetzte Seitenerstellung?

---

## Industrie-Analyse: Schema-Patterns

### Shopify Online Store 2.0

**Hierarchie:** Template (.json) -> Sections -> Blocks (max 8 Ebenen tief)

**Kern-Pattern: Typisierte Settings pro Ebene**

```json
{
  "name": "Slideshow",
  "tag": "section",
  "class": "slideshow",
  "limit": 1,
  "max_blocks": 5,
  "settings": [
    { "type": "text", "id": "title", "label": "Slideshow" },
    { "type": "color", "id": "bg_color", "label": "Background" },
    { "type": "range", "id": "speed", "label": "Speed", "min": 1, "max": 10, "default": 5 }
  ],
  "blocks": [
    {
      "name": "Slide",
      "type": "slide",
      "settings": [
        { "type": "image_picker", "id": "image", "label": "Image" },
        { "type": "text", "id": "heading", "label": "Heading" }
      ]
    }
  ],
  "presets": [
    {
      "name": "Slideshow",
      "settings": { "title": "Slideshow" },
      "blocks": [{ "type": "slide" }, { "type": "slide" }]
    }
  ]
}
```

**Wichtige Erkenntnisse:**
- Settings sind First-Class Citizen — jede Section UND jeder Block hat eigene `settings[]`
- Settings sind typisiert: `text`, `color`, `image_picker`, `range`, `select`, `checkbox`, `url`, `richtext`
- Presets = vorgefertigte Konfigurationen (Merchant waehlt, passt an)
- Blocks nesten bis 8 Ebenen tief
- Kein Layout-System in der Config — Layout ist CSS im Theme-Code
- Sections sind rein vertikal (1D)

**Quellen:**
- https://shopify.dev/docs/storefronts/themes/architecture/sections/section-schema
- https://shopify.dev/docs/storefronts/themes/architecture/blocks/theme-blocks/schema

### Builder.io

**Hierarchie:** Block -> Block (rekursiv, keine feste Tiefe)

**Kern-Pattern: Responsive Styles + Component Options**

```typescript
interface BuilderBlock {
  '@type': '@builder.io/sdk:Element'
  id?: string
  tagName?: string
  children?: BuilderBlock[]              // Rekursiv!
  responsiveStyles?: {
    large?: Partial<CSSStyleDeclaration>  // Desktop
    medium?: Partial<CSSStyleDeclaration> // Tablet
    small?: Partial<CSSStyleDeclaration>  // Mobile
    xsmall?: Partial<CSSStyleDeclaration> // Small Mobile
  }
  component?: {
    name: string
    options?: any                          // Block-spezifische Settings
  }
  bindings?: { [key: string]: string }    // Data Bindings (Expressions)
  actions?: { [key: string]: string }     // Event Handlers
  repeat?: { collection: string }         // Loop/Repeat
  hide?: boolean                          // Conditional Visibility
  show?: boolean
  animations?: BuilderAnimation[]
  style?: Partial<CSSStyleDeclaration>    // Inline Styles
}
```

**Wichtige Erkenntnisse:**
- Styles sind per-Breakpoint gespeichert (large/medium/small/xsmall)
- `component.options` = Settings-Aequivalent (freiformig)
- Rekursive `children[]` — keine Hierarchie-Beschraenkung
- `bindings` = Data-Binding-Expressions (Block bindet an externe Datenquelle)
- `hide/show` = Visibility-Toggle fuer conditional Rendering
- Kein "Section"-Konzept — alles sind Blocks

**Quellen:**
- https://github.com/BuilderIO/builder/blob/main/packages/sdks/src/types/builder-block.ts
- https://www.builder.io/c/docs/how-builder-works-technical

### Webflow

**Hierarchie:** Component Definition -> Instances mit Properties

**Kern-Pattern: Blueprint + Customizable Properties**

- Component Definition = Blueprint (Struktur + Properties)
- Component Instance = Kopie mit eigenen Property-Werten
- Properties aendern Text, Bilder, Links, Appearance — ohne Grunddesign zu brechen
- Layout/Styling ueber Designer API, nicht in Schema

**Quellen:**
- https://developers.webflow.com/designer/reference/components-overview

### Notion

**Hierarchie:** Page -> Blocks[] (rekursiv)

- Alles ist ein Block (Paragraph, Heading, Image, Database, Page)
- `type` + type-spezifische Properties
- Flache Liste mit optionalen `children[]`
- Kein Layout-Konzept (Content-first, nicht Design-first)

**Quellen:**
- https://www.notion.com/blog/data-model-behind-notion
- https://developers.notion.com/reference/block

---

## Vergleich: Industrie vs. aktuelle Spec

| Aspekt | Shopify | Builder.io | Aktuelle Spec (P0.3) |
|--------|---------|------------|----------------------|
| **Hierarchie** | Template > Section > Block (8 deep) | Block > Block (rekursiv) | Page > Section > Block (2 deep) |
| **Settings/Props** | Typisierte `settings[]` pro Section + Block | `component.options` (frei) | **Fehlt komplett** |
| **Layout in Config** | Nein (CSS im Theme) | `responsiveStyles` per Block | `columns: 1-4` + `span` + `row-span` |
| **Responsive** | CSS im Theme-Code | Per-Breakpoint Styles | "Mobile = immer Stack" |
| **Presets** | First-Class (`presets[]`) | Ueber Models | Fehlt |
| **Visibility** | Template-Restrictions | `hide/show` per Block | Fehlt |
| **Data Binding** | Liquid `{{ settings.x }}` | `bindings` Expressions | `$route.slug` Platzhalter |
| **Section visuelle Props** | Section hat Settings (bg, padding etc.) | Jeder Block hat `responsiveStyles` | Section = nur `columns + gap` |
| **Nesting-Tiefe** | 8 Ebenen | Unbegrenzt | 1 Ebene (Block hat keine children) |

---

## Identifizierte Gaps

### Gap 1: Kein Settings-Konzept (KRITISCH fuer Visual Editor + AI)

**Problem:** Ein Block hat `type` + `content_source` + `params`. Aber:
- `params` ist System-intern (GraphQL-Query-Parameter)
- Ein Non-Technical User kann/will nicht `params.query: products_by_category` konfigurieren
- Ein AI-Agent braucht ein typisiertes Schema um sinnvolle Seiten zu generieren

**Industriestandard:** Zwei getrennte Concerns:
1. `settings` = User-facing Konfiguration (was der Editor zeigt)
2. `params` / `bindings` = System-interne Datenanbindung

**Beispiel wie es aussehen koennte:**
```yaml
blocks:
  - type: product-grid
    settings:
      heading: "Unsere T-Shirts"
      columns: 3
      show_price: true
      sort_by: newest
      max_items: 8
    content_source: woocommerce
    params:
      query: products_by_category
      slug: "$route.slug"
      first: 8
```

**Impact:** Ohne Settings-Konzept ist weder ein Visual Editor noch AI-Generierung moeglich. Dies ist das #1 Gap.

### Gap 2: Sections ohne visuelle Eigenschaften

**Problem:** Section = `columns + gap + blocks[]`. Kein:
- Background (Farbe, Bild, Gradient)
- Padding / Spacing
- Max-Width (contained vs. full-bleed)
- ID (fuer Anchor-Links, Editor-Targeting)

**Industriestandard:**
- Shopify: Section hat eigene `settings[]` (Background-Color, Padding, etc.)
- Builder.io: Jeder Block (auch Container) hat `responsiveStyles`

**Beispiel wie es aussehen koennte:**
```yaml
sections:
  - settings:
      background: surface     # Theme-Token
      padding: lg             # sm/md/lg/xl
      max_width: contained    # contained | full-bleed
      id: featured-products   # Anchor + Editor-Target
    columns: 2
    gap: gap-8
    blocks: [...]
```

### Gap 3: `row-span` ist premature Complexity

**Problem:** Die Spec baut ein 2D-Grid (columns + span + row-span).

**Industrievergleich:**
- Shopify: Sections sind 1D (vertikal), Layout ist CSS
- Builder.io: Grid nur per `responsiveStyles`, nicht in der Schema-Config
- Notion: Rein vertikal

**Kein einziger** der grossen Player hat ein 2D-Grid in der Block-Config-Ebene. 2D-Grid-Editing in einem Visual Editor ist eines der schwierigsten UX-Probleme. Selbst Webflow hat es erst nach Jahren eingefuehrt und es bleibt kompliziert.

**Risiko:** `row-span` baut Komplexitaet ein die den Visual Editor spaeter ERSCHWERT statt erleichtert. Magazine-Layouts (der genannte Use Case) lassen sich besser ueber verschachtelte Container oder CSS-only loesen.

### Gap 4: Keine Presets (relevant fuer AI)

**Problem:** Die Spec hat keine Presets. Presets sind bei Shopify die Grundlage dafuer, dass Merchants Sections hinzufuegen koennen — und fuer AI die Grundlage um sinnvolle Default-Konfigurationen zu generieren.

**Nicht kritisch jetzt**, aber relevant fuer AI-Readiness: Ein AI-Agent braucht "bekannte gute Konfigurationen" als Ausgangspunkt.

### Gap 5: Keine Visibility-Steuerung

**Problem:** Keine Moeglichkeit Blocks/Sections per Config ein-/auszublenden. Builder.io hat `hide/show`, Shopify hat `enabled_on/disabled_on`.

**Nicht kritisch jetzt**, aber Standard-Feature in Visual Editors.

---

## Bewertung: Was ist gut

| Aspekt | Bewertung | Begruendung |
|--------|-----------|-------------|
| Section > Block Hierarchie | Richtig | Shopify-bewaehrtes Pattern, Industriestandard |
| YAML als Config-Format | Ideal fuer AI | Strukturiert, menschenlesbar, LLM-generierbar |
| Template-Override (3-tier) | Solide | Multi-Shop-Pattern, erweiterbar |
| Block-Registry + Data-Loaders | Sauber | Abstraktion entkoppelt Daten von Darstellung |
| Param-Resolver ($route.slug) | Gut | Einfach, effektiv, AI-freundlich |
| Content-Source-Abstraktion | Richtig | wordpress/woocommerce/inline ist erweiterbar |
| Migration hardcoded > YAML | Absolut richtig | Voraussetzung fuer alles Weitere |

---

## Empfehlung: Minimale Aenderungen fuer Zukunftsfaehigkeit

### Prioritaet 1: `settings` auf Block + Section (Klein, Impact hoch)

Optionales `settings`-Objekt in den TypeScript-Types vorsehen. Muss jetzt nicht gefuellt werden — aber das Schema existiert und der Visual Editor kann spaeter darauf aufbauen.

```typescript
interface BlockConfig {
  type: string
  content_source: ContentSource
  params: LoaderParams
  settings?: Record<string, unknown>  // <-- NEU, optional
  span?: number
}

interface SectionConfig {
  columns?: 1 | 2 | 3 | 4
  gap?: string
  settings?: Record<string, unknown>  // <-- NEU, optional
  blocks: BlockConfig[]
}
```

### Prioritaet 2: Section-Properties (Klein, Impact mittel)

Minimale Section-Properties die jetzt schon nuetzlich sind:

```typescript
interface SectionConfig {
  id?: string           // Anchor-Links, Editor-Targeting
  columns?: 1 | 2 | 3 | 4
  gap?: string
  settings?: Record<string, unknown>
  blocks: BlockConfig[]
}
```

### Prioritaet 3: `row-span` streichen (Negativ-Aufwand)

Weniger Code, weniger Komplexitaet, kein Feature-Verlust fuer aktuelle Use Cases. Kann spaeter bei Bedarf eingefuehrt werden — aber dann mit Visual-Editor-UX im Hinterkopf.

---

## Naechste Schritte (nicht jetzt)

1. Discovery/Architecture mit Settings-Konzept ueberarbeiten
2. Settings-Schema definieren (typisiert wie Shopify oder frei wie Builder.io?)
3. Presets-Konzept fuer AI-Readiness planen
4. Responsive-Strategie ueber "Mobile = Stack" hinaus definieren
5. Visual Editor MVP als eigene Phase planen

---

## Quellen

- [Shopify Section Schema](https://shopify.dev/docs/storefronts/themes/architecture/sections/section-schema)
- [Shopify Block Schema](https://shopify.dev/docs/storefronts/themes/architecture/blocks/theme-blocks/schema)
- [Shopify Settings Reference](https://shopify.dev/docs/storefronts/themes/architecture/settings)
- [Builder.io BuilderBlock Type (GitHub)](https://github.com/BuilderIO/builder/blob/main/packages/sdks/src/types/builder-block.ts)
- [Builder.io Technical Overview](https://www.builder.io/c/docs/how-builder-works-technical)
- [Builder.io Section Building](https://www.builder.io/c/docs/integrate-section-building)
- [Webflow Component Architecture](https://developers.webflow.com/designer/reference/components-overview)
- [Notion Data Model](https://www.notion.com/blog/data-model-behind-notion)
- [Notion Block API Reference](https://developers.notion.com/reference/block)
