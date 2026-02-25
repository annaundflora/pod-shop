# Theming-System

> Dokumentation für Anwender und Entwickler

---

## Überblick

Das Theming-System erlaubt es, mehrere Shops mit unterschiedlichem Erscheinungsbild aus derselben Codebasis zu betreiben. Jeder Shop bekommt ein eigenes **Theme-Verzeichnis** mit Farben, Fonts, Seitenaufbau und Logo — ohne dass der Anwendungscode angefasst werden muss.

Das Aktivieren eines anderen Themes erfordert nur eine Zeile in der Konfigurationsdatei.

---

## Architektur

```
NEXT_PUBLIC_THEME=zweiter-shop (env-Variable)
         │
         ▼
scripts/generate-theme.mjs          ← Bau-Zeit: YAML → CSS
         │
         ├── themes/default/theme.yaml    (Basis-Werte)
         ├── themes/zweiter-shop/theme.yaml  (nur Overrides)
         │       └── deepMerge() → mergedConfig
         │
         ▼
app/generated-theme.css             ← :root { --theme-color-primary: … }
         │
         ▼
app/globals.css (@theme Block)      ← Tailwind-Tokens: color-primary, …
         │
         ▼
React-Komponenten                   ← bg-primary, text-text-primary, …
```

**Seiten-Konfiguration (zur Laufzeit):**

```
NEXT_PUBLIC_THEME=zweiter-shop
         │
         ▼
lib/blocks/page-config.ts
         ├── themes/zweiter-shop/pages/home.yaml   (Shop-spezifisch)
         │   └── (falls nicht vorhanden → themes/default/pages/home.yaml)
         │
         ▼
lib/blocks/registry.ts (Block-Typen → React-Komponenten)
         │
         ▼
lib/blocks/data-loaders.ts (WordPress / WooCommerce / Inline)
         │
         ▼
Homepage wird gerendert
```

---

## Für Anwender: Ein neues Theme aktivieren

### 1. Theme-Variable setzen

In `frontend/.env.local` den Wert `NEXT_PUBLIC_THEME` auf den gewünschten Theme-Ordner setzen:

```env
NEXT_PUBLIC_THEME=zweiter-shop
```

Verfügbare Themes: `default`, `zweiter-shop`

### 2. CSS generieren

Nach jeder Änderung an einer `theme.yaml` muss das CSS neu generiert werden:

```bash
cd frontend
node scripts/generate-theme.mjs
```

Das Ergebnis landet in `frontend/app/generated-theme.css` (auto-generiert, nicht manuell bearbeiten).

### 3. Dev-Server starten

```bash
pnpm dev
```

---

## Für Entwickler: Neues Theme erstellen

### Schritt 1 — Verzeichnisstruktur anlegen

```
frontend/
  themes/
    mein-shop/
      theme.yaml          ← Farben, Fonts, etc. (nur Overrides)
      pages/
        home.yaml         ← Homepage-Blöcke (vollständige Konfiguration)
  public/
    themes/
      mein-shop/
        assets/
          logo.svg        ← Shop-Logo (mit aria-label)
          favicon.ico     ← Favicon
```

### Schritt 2 — `theme.yaml` befüllen (nur Overrides)

Das neue Theme erbt **alle Werte** aus `themes/default/theme.yaml`. In der Shop-YAML nur die Werte eintragen, die sich unterscheiden sollen:

```yaml
# themes/mein-shop/theme.yaml
# Nur Overrides! Fehlende Werte kommen automatisch aus default.

colors:
  primary: "oklch(0.55 0.18 30)"
  primary-hover: "oklch(0.45 0.18 30)"
  accent: "oklch(0.7 0.15 30)"

fonts:
  heading: "Playfair Display"
```

**Wichtig:** Farben müssen im `oklch()`-Format angegeben werden. Andere Formate (z.B. `#FF0000`) werden vom Generator abgelehnt.

### Schritt 3 — `pages/home.yaml` befüllen

Die Seiten-Konfiguration wird **nicht** gemergt — sie ersetzt die Default-Konfiguration vollständig.

```yaml
# themes/mein-shop/pages/home.yaml

blocks:
  - type: hero
    content_source: wordpress
    params:
      page_slug: "/"

  - type: product-grid
    content_source: woocommerce
    params:
      query: featured_products
      first: 6

  - type: usp-bar
    content_source: inline
    params:
      props:
        items:
          - icon: "star"
            text: "Exklusive Designs"
          - icon: "truck"
            text: "Express Versand"
```

---

## Referenz: `theme.yaml`

### Farben (`colors`)

| Token | Verwendung | Default |
|-------|-----------|---------|
| `primary` | Hauptfarbe (Buttons, Links) | `oklch(0.45 0.18 150)` |
| `primary-hover` | Hover-Zustand von `primary` | `oklch(0.38 0.2 270)` |
| `accent` | Akzentfarbe | `oklch(0.65 0.15 270)` |
| `surface` | Seitenhintergrund | `oklch(1 0 0)` (Weiß) |
| `surface-elevated` | Karten-Hintergrund | `oklch(0.98 0 0)` |
| `text-primary` | Haupttextfarbe | `oklch(0.15 0 0)` |
| `text-secondary` | Sekundärtextfarbe | `oklch(0.45 0 0)` |
| `border` | Rahmenfarbe | `oklch(0.88 0 0)` |
| `error` | Fehlermeldungen | `oklch(0.55 0.2 25)` |
| `success` | Erfolgsmeldungen | `oklch(0.55 0.15 145)` |
| `warning` | Warnmeldungen | `oklch(0.7 0.15 85)` |
| `overlay` | Modal-Hintergrundschleier | `oklch(0 0 0 / 0.5)` |

### Schriften (`fonts`)

| Token | Verwendung | Default |
|-------|-----------|---------|
| `heading` | Überschriften (h1–h6) | `Inter` |
| `body` | Fließtext | `Inter` |

Nur Google Fonts werden unterstützt (Next.js lädt sie automatisch via `next/font/google`).

> **Hinweis:** Aktuell ist die Font-Auswahl in `lib/theme/fonts.ts` noch auf `Inter` hart codiert. Für andere Fonts muss diese Datei manuell angepasst werden.

### Eckenradius (`radius`)

| Token | Verwendung | Default |
|-------|-----------|---------|
| `card` | Produktkarten, Container | `0.75rem` |
| `button` | Buttons | `0.5rem` |

### Schatten (`shadows`)

| Token | Verwendung | Default |
|-------|-----------|---------|
| `card` | Ruhezustand von Karten | `0 1px 3px oklch(0 0 0 / 0.08)` |
| `card-hover` | Hover-Zustand von Karten | `0 8px 25px oklch(0 0 0 / 0.12)` |

---

## Referenz: `pages/home.yaml` — Block-Typen

### `hero`

Hero-Banner der Homepage. Inhalte (Überschrift, CTA, Hintergrundbild) kommen aus WordPress Custom Fields.

```yaml
- type: hero
  content_source: wordpress
  params:
    page_slug: "/"   # WordPress-Seiten-Slug
```

### `product-grid`

Produktraster. Holt Daten aus WooCommerce.

```yaml
- type: product-grid
  content_source: woocommerce
  params:
    query: featured_products   # Nur dieser Wert ist aktuell unterstützt
    first: 4                   # Anzahl der Produkte (Standard: 4)
```

### `category-showcase`

Kategorie-Übersicht mit Kacheln.

```yaml
- type: category-showcase
  content_source: woocommerce
  params:
    query: product_categories
    first: 6
```

### `usp-bar`

Leiste mit Verkaufsargumenten (z.B. "Kostenloser Versand"). Inhalte direkt im YAML definiert.

```yaml
- type: usp-bar
  content_source: inline
  params:
    props:
      items:
        - icon: "truck"
          text: "Kostenloser Versand ab 100€"
        - icon: "shield"
          text: "Sichere Zahlung"
```

Verfügbare Icons: `truck`, `shield`, `refresh`, `star`

---

## Referenz: Assets

### Logo (`logo.svg`)

- Pfad: `frontend/public/themes/{theme}/assets/logo.svg`
- Muss gültiges SVG mit `aria-label` für Barrierefreiheit enthalten
- Fallback: Wenn das Shop-Logo nicht existiert, wird `themes/default/assets/logo.svg` verwendet

### Favicon (`favicon.ico`)

- Pfad: `frontend/public/themes/{theme}/assets/favicon.ico`
- Fallback: Wenn der Shop-Favicon nicht existiert, wird `themes/default/assets/favicon.ico` verwendet

---

## Merge-Verhalten im Überblick

| Was | Verhalten |
|-----|-----------|
| `theme.yaml` Farben | Deep-Merge: Shop-Werte überschreiben Default-Werte, Rest bleibt |
| `theme.yaml` Fonts | Deep-Merge: Nur überschriebene Keys ändern sich |
| `theme.yaml` Radius/Shadows | Deep-Merge: Shop-Overrides haben Vorrang |
| `pages/{slug}.yaml` | **Kein Merge** — vollständige Ersetzung. Default als Fallback wenn keine Shop-Datei. |
| Logo / Favicon | Fallback auf Default wenn Shop-Asset nicht vorhanden |

---

## Wichtige Dateipfade

| Datei | Zweck |
|-------|-------|
| `frontend/.env.local` | `NEXT_PUBLIC_THEME` setzen |
| `frontend/themes/default/theme.yaml` | Basis-Theme (vollständige Werte) |
| `frontend/themes/{shop}/theme.yaml` | Shop-Overrides |
| `frontend/themes/default/pages/home.yaml` | Default-Homepage-Blöcke |
| `frontend/themes/{shop}/pages/home.yaml` | Shop-Homepage-Blöcke |
| `frontend/public/themes/{shop}/assets/` | Logo + Favicon |
| `frontend/scripts/generate-theme.mjs` | CSS-Generator (Bau-Zeit) |
| `frontend/app/generated-theme.css` | Auto-generiert — nicht bearbeiten |
| `frontend/app/globals.css` | Tailwind `@theme` Block |
| `frontend/lib/blocks/registry.ts` | Block-Typen → React-Komponenten |
| `frontend/lib/blocks/page-config.ts` | YAML-Loader für Seiten |
| `frontend/lib/theme/logo.ts` | Logo/Favicon-Pfad-Resolver |

---

## Erweiterungspunkte

### Neuen Block-Typ hinzufügen

1. React-Komponente in `frontend/components/blocks/` erstellen
2. In `frontend/lib/blocks/registry.ts` registrieren:
   ```ts
   'mein-block': MeinBlock as BlockComponent,
   ```
3. Typ-Definitionen ggf. in `frontend/lib/blocks/types.ts` ergänzen
4. In `pages/home.yaml` verwenden

### Neues CSS-Token hinzufügen

1. In `themes/default/theme.yaml` eintragen (Basis-Wert)
2. In `scripts/generate-theme.mjs` → `generateCSS()` ausgeben lassen (z.B. `--theme-color-{key}`)
3. In `frontend/app/globals.css` → `@theme` als Tailwind-Variable registrieren

### Andere Seiten thembar machen

Aktuell ist nur `home.yaml` implementiert. Weitere Seiten folgen demselben Muster: `themes/{shop}/pages/{slug}.yaml` anlegen, `loadPageConfig(slug, theme)` im Page-Component aufrufen.
