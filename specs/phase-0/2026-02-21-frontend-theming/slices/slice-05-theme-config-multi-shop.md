# Slice 5: Theme Config + Multi-Shop einrichten

> **Slice 5 von 5** für `Frontend-Architektur: Theming (P0.2)`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-04-block-registry-page-renderer.md` |
> | **Nächster:** | — |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-05-theme-config-multi-shop` |
| **Test** | `pnpm test tests/slices/frontend-theming/slice-05-theme-config-multi-shop.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-theme-token-system", "slice-02-shadcn-ui-primitives", "slice-03-component-migration", "slice-04-block-registry-page-renderer"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` — Vitest Unit Tests (`.test.ts`), kein Browser erforderlich
- **Dependencies**: Alle vier vorherigen Slices müssen fertig sein — Slice 1 liefert das Build-Script mit dem initialen Deep-Merge-Platzhalter, Slice 4 liefert `loadPageConfig()` und `themes/default/pages/home.yaml`

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren.
> `frontend/package.json` enthält `next`, `vitest`, `@tailwindcss/postcss` → Stack: typescript-nextjs

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `cd frontend && pnpm test tests/slices/frontend-theming/slice-05-theme-config-multi-shop.test.ts` |
| **Integration Command** | `cd frontend && pnpm test tests/slices/frontend-theming/` |
| **Acceptance Command** | `cd frontend && NEXT_PUBLIC_THEME=zweiter-shop node scripts/generate-theme.mjs && cat app/generated-theme.css` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000` |
| **Mocking Strategy** | `no_mocks` |

**Erklaerung:**
- **Stack**: Next.js 16 + Vitest v3 + Tailwind v4 CSS-first
- **Test Command**: Unit Tests für Deep Merge Logik, Fallback-Verhalten, YAML-Validierung, Asset-Pfade, `.env.local.example`
- **Integration Command**: Alle Theming-Slice-Tests zusammen
- **Acceptance Command**: Build-Script mit zweitem Theme ausführen, CSS-Output enthält Shop-spezifische Farben
- **Mocking Strategy**: `no_mocks` — Tests arbeiten direkt auf dem Dateisystem (YAML-Dateien, Assets)

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Theme Token System | Done | `slice-01-theme-token-system.md` |
| 2 | shadcn/ui Primitives | Done | `slice-02-shadcn-ui-primitives.md` |
| 3 | Component Migration (Big Bang) | Done | `slice-03-component-migration.md` |
| 4 | Block Registry + Page Renderer | Done | `slice-04-block-registry-page-renderer.md` |
| 5 | Theme Config + Multi-Shop | **Ready** | `slice-05-theme-config-multi-shop.md` |

---

## Kontext & Ziel

Slices 1–4 haben das komplette Theming-Fundament gebaut: Token-System, shadcn/ui Primitives, migrierte Components und Block Registry. Das Build-Script (`generate-theme.mjs`) aus Slice 1 liest bisher nur ein einzelnes `theme.yaml` ohne Deep Merge. `themes/default/` existiert als vollständiges Schema.

Dieser Slice vervollständigt das Multi-Shop-Versprechen: Ein zweiter Shop (`zweiter-shop`) erhält ein eigenes Theme-Verzeichnis mit minimalen Overrides. Das Build-Script bekommt die vollständige Deep Merge Logik. Shop-spezifische Assets (Logo SVG, Favicon) landen in `themes/{shop}/assets/`. YAML-Schema-Validierung ist bereits im Build-Script vorhanden — dieser Slice stellt sicher, dass sie auch für den gemergten Config greift.

**Aktuelle Probleme:**
1. `generate-theme.mjs` (Slice 1) hat keinen Deep Merge — liest nur ein einzelnes theme.yaml
2. Kein zweites Theme-Verzeichnis vorhanden (`themes/zweiter-shop/` fehlt)
3. Keine `themes/{shop}/assets/` Struktur mit Logo/Favicon
4. Kein `frontend/.env.local.example` mit `NEXT_PUBLIC_THEME=default` Eintrag
5. `loadPageConfig()` in Slice 4 fällt schon korrekt auf Default zurück — aber ein Shop kann kein eigenes `home.yaml` haben ohne eine vollständige YAML-Datei anlegen zu müssen

**Zielbild nach diesem Slice:**
- `generate-theme.mjs` führt Deep Merge durch: Default-Werte werden mit Shop-Overrides zusammengeführt; nur überschriebene Werte müssen in Shop-YAML stehen
- `themes/zweiter-shop/theme.yaml` enthält nur 3-4 Farb-Overrides (Beweis: Minimal-Config reicht)
- `themes/zweiter-shop/pages/home.yaml` enthält eine Shop-spezifische Seiten-Konfiguration (andere USP-Texte als Default)
- `themes/zweiter-shop/assets/logo.svg` und `themes/zweiter-shop/assets/favicon.ico` sind vorhanden (Platzhalter-SVG reicht)
- `themes/default/assets/logo.svg` und `themes/default/assets/favicon.ico` als Fallback vorhanden
- `lib/theme/logo.ts` stellt `getLogoPath(theme)` bereit — gibt Asset-Pfad relativ zu `public/` zurück; Fallback auf Default-Assets wenn Shop-Asset nicht existiert
- `frontend/.env.local.example` enthält `NEXT_PUBLIC_THEME=default`

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Build-Time Theme Pipeline, File Structure

```
Build-Time:
  NEXT_PUBLIC_THEME (z.B. "zweiter-shop")
    → generate-theme.mjs
      → readFileSync("themes/default/theme.yaml")    → defaultConfig
      → readFileSync("themes/zweiter-shop/theme.yaml") → shopConfig (nur Overrides)
      → deepMerge(defaultConfig, shopConfig)          → mergedConfig
      → validateConfig(mergedConfig)                  → Exit 1 bei Fehler
      → generateCSS(mergedConfig, "zweiter-shop")
      → writeFileSync("app/generated-theme.css")

Runtime (RSC):
  loadPageConfig("home", "zweiter-shop")
    → existsSync("themes/zweiter-shop/pages/home.yaml") → true
    → readFileSync("themes/zweiter-shop/pages/home.yaml")
    → PageConfig mit Shop-spezifischen Block-Props

Assets:
  getLogoPath("zweiter-shop")
    → existsSync("themes/zweiter-shop/assets/logo.svg") → "/themes/zweiter-shop/assets/logo.svg"
    → Fallback: "/themes/default/assets/logo.svg"
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `frontend/scripts/generate-theme.mjs` | ERSETZT: Das Code-Beispiel in Abschnitt 3 zeigt das vollständige `generate-theme.mjs` nach diesem Slice — es ersetzt die Slice-1-Version komplett. `deepMerge()` ist neu; `validateConfig()` und `generateCSS()` aus Slice 1 sind identisch beibehalten; Main-Flow: Default laden → Shop laden → deepMerge → validateConfig → generateCSS |
| `frontend/themes/default/assets/` | NEU: `logo.svg` (Platzhalter), `favicon.ico` (Platzhalter) |
| `frontend/themes/zweiter-shop/` | NEU: Vollständiges Theme-Verzeichnis mit `theme.yaml` (nur Overrides), `pages/home.yaml`, `assets/logo.svg`, `assets/favicon.ico` |
| `frontend/lib/theme/logo.ts` | NEU: `getLogoPath(theme)` Funktion — Asset-Pfad-Resolver mit Default-Fallback |
| `frontend/.env.local.example` | NEU oder ERWEITERT: `NEXT_PUBLIC_THEME=default` Eintrag |
| `frontend/public/themes/` | NEU: Symlink oder Copy der themes-Assets — da Next.js nur `public/` serviert; Assets müssen unter `public/themes/{shop}/assets/` liegen |

**Hinweis zu Asset-Serving:** Next.js serviert statische Dateien nur aus `public/`. Logo und Favicon müssen daher unter `frontend/public/themes/{shop}/assets/` liegen (nicht unter `frontend/themes/{shop}/assets/`). Das Build-Script kopiert Assets NICHT — die Assets liegen direkt in `public/themes/`.

**Revidierte Dateistruktur für Assets:**
```
frontend/
├── public/
│   └── themes/
│       ├── default/
│       │   └── assets/
│       │       ├── logo.svg
│       │       └── favicon.ico
│       └── zweiter-shop/
│           └── assets/
│               ├── logo.svg
│               └── favicon.ico
├── themes/
│   ├── default/
│   │   ├── theme.yaml          (aus Slice 1)
│   │   └── pages/
│   │       └── home.yaml       (aus Slice 4)
│   └── zweiter-shop/
│       ├── theme.yaml          (nur Overrides)
│       └── pages/
│           └── home.yaml       (Shop-spezifische Page Config)
```

### 2. Datenfluss — Deep Merge Build Pipeline

```
NEXT_PUBLIC_THEME = "zweiter-shop"
  ↓
generate-theme.mjs
  ↓ readFileSync("themes/default/theme.yaml")
defaultConfig = {
  colors: { primary: "oklch(0.45 0.2 270)", primary-hover: "oklch(0.38 0.2 270)", ...12 Farben },
  fonts: { heading: "Inter", body: "Inter" },
  radius: { card: "0.75rem", button: "0.5rem" },
  shadows: { card: "...", card-hover: "..." }
}
  ↓ readFileSync("themes/zweiter-shop/theme.yaml")
shopConfig = {
  colors: { primary: "oklch(0.55 0.18 30)", primary-hover: "oklch(0.45 0.18 30)", accent: "oklch(0.7 0.15 30)" },
  fonts: { heading: "Playfair Display" }
}
  ↓ deepMerge(defaultConfig, shopConfig)
mergedConfig = {
  colors: { primary: "oklch(0.55 0.18 30)", primary-hover: "oklch(0.45 0.18 30)", accent: "oklch(0.7 0.15 30)",
            surface: "oklch(1 0 0)", error: "oklch(0.55 0.2 25)", ...alle 12 Farben },
  fonts: { heading: "Playfair Display", body: "Inter" },
  radius: { card: "0.75rem", button: "0.5rem" },
  shadows: { card: "...", card-hover: "..." }
}
  ↓ validateConfig(mergedConfig)  →  alle Farben OKLch, alle Fonts non-empty
  ↓ generateCSS(mergedConfig, "zweiter-shop")
app/generated-theme.css:
  :root {
    --theme-color-primary: oklch(0.55 0.18 30);  ← Shop-Override
    --theme-color-primary-hover: oklch(0.45 0.18 30);  ← Shop-Override
    --theme-color-surface: oklch(1 0 0);  ← Default (nicht überschrieben)
    --theme-font-heading: Playfair Display;  ← Shop-Override
    --theme-font-body: Inter;  ← Default
    ...
  }
```

### 3. Deep Merge Algorithmus

Deep Merge ist ein rekursives Zusammenführen zweier Objekte. Für theme.yaml-Strukturen bedeutet das:

- Jede Kategorie (`colors`, `fonts`, `radius`, `shadows`) wird unabhängig gemergt
- Innerhalb einer Kategorie: Shop-Wert überschreibt Default-Wert für denselben Key
- Keys die im Shop-YAML fehlen, behalten den Default-Wert
- Keys die im Shop-YAML vorhanden sind aber nicht im Default existieren, werden hinzugefügt

**Vollständiges Code-Beispiel — erweitertes `generate-theme.mjs`:**

```javascript
// scripts/generate-theme.mjs (ERSETZT die Slice-1-Version vollständig)
// Dieses Script ist das vollständige generate-theme.mjs nach Slice 5.
// Die Funktionen validateConfig() und generateCSS() aus Slice 1 sind hier beibehalten
// und identisch übernommen. Neu hinzugekommen ist deepMerge() sowie der erweiterte
// Main-Flow der Default-YAML und Shop-YAML lädt und merged.
import { readFileSync, writeFileSync, existsSync } from 'fs'
import { resolve, dirname } from 'path'
import { fileURLToPath } from 'url'
import { parse } from 'yaml'

const __dirname = dirname(fileURLToPath(import.meta.url))
const FRONTEND_ROOT = resolve(__dirname, '..')

const OKLCH_REGEX = /^oklch\(\s*[\d.]+\s+[\d.]+\s+[\d.]+(\s*\/\s*[\d.]+)?\s*\)$/

/**
 * Deep merges two config objects.
 * Values from override take precedence over base values.
 * Only supports one level of nesting (categories like colors, fonts, etc.).
 * @param {Record<string, any>} base
 * @param {Record<string, any>} override
 * @returns {Record<string, any>}
 */
function deepMerge(base, override) {
  const result = {}

  // Collect all keys from both objects
  const allKeys = new Set([...Object.keys(base), ...Object.keys(override)])

  for (const key of allKeys) {
    const baseVal = base[key]
    const overrideVal = override[key]

    if (
      overrideVal !== undefined &&
      overrideVal !== null &&
      typeof overrideVal === 'object' &&
      !Array.isArray(overrideVal) &&
      typeof baseVal === 'object' &&
      baseVal !== null &&
      !Array.isArray(baseVal)
    ) {
      // Both are plain objects → merge recursively (one level deep)
      result[key] = { ...baseVal, ...overrideVal }
    } else if (overrideVal !== undefined && overrideVal !== null) {
      // Override has a value → use it
      result[key] = overrideVal
    } else {
      // Only base has a value → use it
      result[key] = baseVal
    }
  }

  return result
}

/**
 * Validates a theme config object.
 * Exits with code 1 and prints error message on first validation failure.
 * @param {Record<string, any>} config
 */
function validateConfig(config) {
  if (config.colors) {
    for (const [key, value] of Object.entries(config.colors)) {
      if (typeof value !== 'string' || !OKLCH_REGEX.test(value.trim())) {
        process.stderr.write(`Invalid color format for ${key}: expected oklch()\n`)
        process.exit(1)
      }
    }
  }

  if (config.fonts) {
    for (const [key, value] of Object.entries(config.fonts)) {
      if (!value || typeof value !== 'string' || value.trim() === '') {
        process.stderr.write(`Font name required for ${key}\n`)
        process.exit(1)
      }
    }
  }

  if (config.radius) {
    for (const [key, value] of Object.entries(config.radius)) {
      if (!value || typeof value !== 'string' || value.trim() === '') {
        process.stderr.write(`Radius value required for ${key}\n`)
        process.exit(1)
      }
    }
  }

  if (config.shadows) {
    for (const [key, value] of Object.entries(config.shadows)) {
      if (!value || typeof value !== 'string' || value.trim() === '') {
        process.stderr.write(`Shadow value required for ${key}\n`)
        process.exit(1)
      }
    }
  }
}

/**
 * Generates a CSS :root block with --theme-* custom properties from a config object.
 * @param {Record<string, any>} config
 * @param {string} themeName
 * @returns {string}
 */
function generateCSS(config, themeName) {
  const lines = []
  lines.push(`/* Auto-generated by scripts/generate-theme.mjs — DO NOT EDIT */`)
  lines.push(`/* Theme: ${themeName} */`)
  lines.push(`:root {`)

  if (config.colors) {
    for (const [key, value] of Object.entries(config.colors)) {
      lines.push(`  --theme-color-${key}: ${value};`)
    }
  }

  if (config.fonts) {
    for (const [key, value] of Object.entries(config.fonts)) {
      lines.push(`  --theme-font-${key}: ${value};`)
    }
  }

  if (config.radius) {
    for (const [key, value] of Object.entries(config.radius)) {
      lines.push(`  --theme-radius-${key}: ${value};`)
    }
  }

  if (config.shadows) {
    for (const [key, value] of Object.entries(config.shadows)) {
      lines.push(`  --theme-shadow-${key}: ${value};`)
    }
  }

  lines.push(`}`)
  return lines.join('\n') + '\n'
}

// --- Main ---

const themeName = process.env.NEXT_PUBLIC_THEME || 'default'
const themeDir = resolve(FRONTEND_ROOT, 'themes', themeName)
const defaultThemeDir = resolve(FRONTEND_ROOT, 'themes', 'default')
const outputPath = resolve(FRONTEND_ROOT, 'app', 'generated-theme.css')

// Load default config (always required as base)
const defaultYamlPath = resolve(defaultThemeDir, 'theme.yaml')
if (!existsSync(defaultYamlPath)) {
  process.stderr.write(`Default theme.yaml not found at: ${defaultYamlPath}\n`)
  process.exit(1)
}
const defaultConfig = parse(readFileSync(defaultYamlPath, 'utf-8'))

// Load shop config if different from default
let resolvedThemeName = themeName
let mergedConfig = defaultConfig

if (themeName !== 'default') {
  const shopYamlPath = resolve(themeDir, 'theme.yaml')

  if (existsSync(shopYamlPath)) {
    const shopConfig = parse(readFileSync(shopYamlPath, 'utf-8'))
    if (shopConfig && typeof shopConfig === 'object') {
      mergedConfig = deepMerge(defaultConfig, shopConfig)
    }
    resolvedThemeName = themeName
  } else {
    // Fallback: use default only
    process.stdout.write(`Theme "${themeName}" not found, falling back to default\n`)
    resolvedThemeName = 'default'
  }
}

validateConfig(mergedConfig)

const css = generateCSS(mergedConfig, resolvedThemeName)
writeFileSync(outputPath, css, 'utf-8')
process.stdout.write(`Theme generated: ${outputPath} (theme: ${resolvedThemeName})\n`)
```

### 4. Shop-Theme YAML (themes/zweiter-shop/theme.yaml)

Das Shop-Theme enthält nur die Werte die vom Default abweichen. Alle anderen Werte werden per Deep Merge vom Default übernommen.

```yaml
# themes/zweiter-shop/theme.yaml
# Zweiter Shop — NUR Overrides gegenüber Default.
# Fehlende Werte werden automatisch aus themes/default/theme.yaml übernommen.

colors:
  primary: "oklch(0.55 0.18 30)"
  primary-hover: "oklch(0.45 0.18 30)"
  accent: "oklch(0.7 0.15 30)"

fonts:
  heading: "Playfair Display"
```

### 5. Shop-spezifische Page Config (themes/zweiter-shop/pages/home.yaml)

```yaml
# themes/zweiter-shop/pages/home.yaml
# Zweiter Shop — Homepage Block-Konfiguration
# Überschreibt themes/default/pages/home.yaml vollständig (kein Merge bei Page Configs)

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
          - icon: "shield"
            text: "Zufriedenheitsgarantie"
```

### 6. Logo-Pfad-Resolver (lib/theme/logo.ts)

`next/font` kann nicht dynamisch sein — Fonts werden separat behandelt (statische Imports bereits durch `lib/theme/fonts.ts` aus Slice 2/3 abgedeckt). Für Logo und Favicon brauchen wir einen Laufzeit-Pfad-Resolver.

```typescript
// frontend/lib/theme/logo.ts
import { existsSync } from 'fs'
import { resolve } from 'path'

/**
 * Returns the public URL path to the shop logo SVG.
 * Falls back to the default theme logo if the shop-specific logo doesn't exist.
 *
 * IMPORTANT: Assets must be placed in frontend/public/themes/{shop}/assets/
 * Next.js serves files from public/ only.
 *
 * @param theme - Theme name from NEXT_PUBLIC_THEME (e.g. "zweiter-shop" or "default")
 * @returns Public URL path (e.g. "/themes/zweiter-shop/assets/logo.svg")
 */
export function getLogoPath(theme: string = 'default'): string {
  const shopLogoPublic = resolve(process.cwd(), 'public', 'themes', theme, 'assets', 'logo.svg')
  const defaultLogoPublic = resolve(process.cwd(), 'public', 'themes', 'default', 'assets', 'logo.svg')

  if (theme !== 'default' && existsSync(shopLogoPublic)) {
    return `/themes/${theme}/assets/logo.svg`
  }

  if (existsSync(defaultLogoPublic)) {
    return `/themes/default/assets/logo.svg`
  }

  // No logo found — return empty string (caller must handle gracefully)
  return ''
}

/**
 * Returns the public URL path to the shop favicon.
 * Falls back to the default theme favicon if the shop-specific one doesn't exist.
 *
 * @param theme - Theme name from NEXT_PUBLIC_THEME
 * @returns Public URL path (e.g. "/themes/zweiter-shop/assets/favicon.ico")
 */
export function getFaviconPath(theme: string = 'default'): string {
  const shopFaviconPublic = resolve(process.cwd(), 'public', 'themes', theme, 'assets', 'favicon.ico')
  const defaultFaviconPublic = resolve(process.cwd(), 'public', 'themes', 'default', 'assets', 'favicon.ico')

  if (theme !== 'default' && existsSync(shopFaviconPublic)) {
    return `/themes/${theme}/assets/favicon.ico`
  }

  if (existsSync(defaultFaviconPublic)) {
    return `/themes/default/assets/favicon.ico`
  }

  return ''
}
```

### 7. Platzhalter-Assets

**themes/default/assets/** und **themes/zweiter-shop/assets/** werden als `public/themes/` abgelegt.

**`frontend/public/themes/default/assets/logo.svg` (Platzhalter):**

```xml
<svg xmlns="http://www.w3.org/2000/svg" width="120" height="32" viewBox="0 0 120 32" role="img" aria-label="POD Shop Logo">
  <rect width="120" height="32" rx="4" fill="currentColor" opacity="0.1"/>
  <text x="8" y="22" font-family="system-ui, sans-serif" font-size="14" font-weight="600" fill="currentColor">POD Shop</text>
</svg>
```

**`frontend/public/themes/zweiter-shop/assets/logo.svg` (Platzhalter):**

```xml
<svg xmlns="http://www.w3.org/2000/svg" width="140" height="32" viewBox="0 0 140 32" role="img" aria-label="Zweiter Shop Logo">
  <rect width="140" height="32" rx="4" fill="currentColor" opacity="0.1"/>
  <text x="8" y="22" font-family="Georgia, serif" font-size="14" font-weight="600" fill="currentColor">Zweiter Shop</text>
</svg>
```

**`frontend/public/themes/default/assets/favicon.ico` und `frontend/public/themes/zweiter-shop/assets/favicon.ico` (Platzhalter):**

Da `.ico` ein Binärformat ist, wird eine leere Datei als Platzhalter erstellt. Browser akzeptieren eine leere `.ico`-Datei ohne Fehler (kein Favicon wird angezeigt). Die Tests in diesem Slice prüfen nur die Existenz der Datei via `existsSync`, nicht den Inhalt.

```bash
# Shell-Befehl zum Erstellen der Platzhalter-Favicon-Dateien
touch frontend/public/themes/default/assets/favicon.ico
touch frontend/public/themes/zweiter-shop/assets/favicon.ico
```

Alternativ kann eine beliebige echte `.ico`-Datei kopiert werden — für die Platzhalter-Implementierung reicht die leere Datei. Ein echter Favicon wird in einem späteren Schritt (ausserhalb dieser Spec) durch ein echtes Icon ersetzt.

### 8. .env.local.example Eintrag

```bash
# frontend/.env.local.example (ERWEITERT um NEXT_PUBLIC_THEME)

# Theme Selection
# Selects the theme folder from frontend/themes/{NEXT_PUBLIC_THEME}/
# Available themes: default, zweiter-shop
NEXT_PUBLIC_THEME=default
```

### 9. Abhängigkeiten

| Paket | Version | Zweck | Status |
|-------|---------|-------|--------|
| `yaml` | `^2.7.0` | YAML-Parsing im Build-Script | Aus Slice 1 bereits vorhanden |
| `clsx` | `^2.0.0` | Class merging in cn() | Aus Slice 1 bereits vorhanden |
| `tailwind-merge` | `^3.5.0` | Tailwind conflict resolution | Aus Slice 1 bereits vorhanden |

Keine neuen Dependencies für diesen Slice.

---

## Acceptance Criteria

1) GIVEN `NEXT_PUBLIC_THEME=zweiter-shop` und `themes/zweiter-shop/theme.yaml` enthält nur `colors.primary: "oklch(0.55 0.18 30)"`
   WHEN `node scripts/generate-theme.mjs` ausgeführt wird
   THEN enthält `app/generated-theme.css` `--theme-color-primary: oklch(0.55 0.18 30)` (Shop-Override) UND `--theme-color-surface: oklch(1 0 0)` (Default-Fallback aus Deep Merge)

2) GIVEN `NEXT_PUBLIC_THEME=zweiter-shop` und `themes/zweiter-shop/theme.yaml` enthält `fonts.heading: "Playfair Display"`
   WHEN `node scripts/generate-theme.mjs` ausgeführt wird
   THEN enthält `app/generated-theme.css` `--theme-font-heading: Playfair Display` (Shop-Override) UND `--theme-font-body: Inter` (Default-Fallback)

3) GIVEN `NEXT_PUBLIC_THEME=nicht-existierender-shop`
   WHEN `node scripts/generate-theme.mjs` ausgeführt wird
   THEN fällt das Script auf Default-Theme zurück, generiert `app/generated-theme.css` mit Default-Werten und gibt eine Fallback-Meldung auf stdout aus

4) GIVEN `themes/zweiter-shop/theme.yaml` enthält einen ungültigen Farbwert `primary: "red"`
   WHEN `node scripts/generate-theme.mjs` mit `NEXT_PUBLIC_THEME=zweiter-shop` ausgeführt wird
   THEN gibt das Script eine Fehlermeldung aus (`Invalid color format for primary: expected oklch()`) und beendet sich mit Exit Code 1

5) GIVEN `themes/zweiter-shop/pages/home.yaml` existiert mit Shop-spezifischen USP-Texten
   WHEN `loadPageConfig('home', 'zweiter-shop')` aufgerufen wird
   THEN wird die Shop-spezifische `home.yaml` geladen (nicht die Default-Datei)

6) GIVEN nur `themes/default/pages/home.yaml` existiert und kein `themes/zweiter-shop/pages/home.yaml`
   WHEN `loadPageConfig('home', 'zweiter-shop')` aufgerufen wird
   THEN wird auf `themes/default/pages/home.yaml` zurückgegriffen (bereits in Slice 4 implementiertes Fallback-Verhalten)

7) GIVEN `public/themes/zweiter-shop/assets/logo.svg` existiert
   WHEN `getLogoPath('zweiter-shop')` aufgerufen wird
   THEN gibt die Funktion `/themes/zweiter-shop/assets/logo.svg` zurück

8) GIVEN `public/themes/zweiter-shop/assets/logo.svg` existiert NICHT, aber `public/themes/default/assets/logo.svg` existiert
   WHEN `getLogoPath('zweiter-shop')` aufgerufen wird
   THEN gibt die Funktion `/themes/default/assets/logo.svg` zurück (Fallback auf Default)

9) GIVEN `frontend/.env.local.example` existiert
   WHEN die Datei gelesen wird
   THEN enthält sie `NEXT_PUBLIC_THEME=default`

10) GIVEN `deepMerge({ colors: { primary: "oklch(0.45 0.2 270)", surface: "oklch(1 0 0)" } }, { colors: { primary: "oklch(0.55 0.18 30)" } })`
    WHEN `deepMerge()` aufgerufen wird
    THEN gibt die Funktion `{ colors: { primary: "oklch(0.55 0.18 30)", surface: "oklch(1 0 0)" } }` zurück — Shop-Wert überschreibt, Default-Wert bleibt erhalten

---

## Testfälle

### Test-Datei

`tests/slices/frontend-theming/slice-05-theme-config-multi-shop.test.ts`

<test_spec>
```typescript
// tests/slices/frontend-theming/slice-05-theme-config-multi-shop.test.ts
import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { readFileSync, existsSync, writeFileSync, mkdirSync, rmSync, unlinkSync } from 'fs'
import { resolve } from 'path'
import { execSync } from 'child_process'

const FRONTEND_ROOT = resolve(__dirname, '../../../frontend')
const GENERATED_CSS = resolve(FRONTEND_ROOT, 'app/generated-theme.css')
const SCRIPT_PATH = resolve(FRONTEND_ROOT, 'scripts/generate-theme.mjs')
const DEFAULT_THEME_YAML = resolve(FRONTEND_ROOT, 'themes/default/theme.yaml')
const SHOP_THEME_DIR = resolve(FRONTEND_ROOT, 'themes/zweiter-shop')
const SHOP_THEME_YAML = resolve(FRONTEND_ROOT, 'themes/zweiter-shop/theme.yaml')

// ─── Helpers ─────────────────────────────────────────────────────────────────

function fileExists(relativePath: string): boolean {
  return existsSync(resolve(FRONTEND_ROOT, relativePath))
}

function readFile(relativePath: string): string {
  return readFileSync(resolve(FRONTEND_ROOT, relativePath), 'utf-8')
}

function cleanupGeneratedCSS(): void {
  if (existsSync(GENERATED_CSS)) {
    unlinkSync(GENERATED_CSS)
  }
}

// ─── Datei-Existenz Tests ─────────────────────────────────────────────────────

describe('Slice 5 — Datei-Existenz', () => {
  const requiredFiles = [
    'themes/zweiter-shop/theme.yaml',
    'themes/zweiter-shop/pages/home.yaml',
    'public/themes/default/assets/logo.svg',
    'public/themes/default/assets/favicon.ico',
    'public/themes/zweiter-shop/assets/logo.svg',
    'public/themes/zweiter-shop/assets/favicon.ico',
    'lib/theme/logo.ts',
    '.env.local.example',
  ]

  for (const file of requiredFiles) {
    it(`should have ${file}`, () => {
      expect(fileExists(file)).toBe(true)
    })
  }
})

// ─── Deep Merge Logik Tests ───────────────────────────────────────────────────

describe('generate-theme.mjs — Deep Merge Logik', () => {
  afterEach(cleanupGeneratedCSS)

  it('should merge shop colors over default colors (shop value wins)', () => {
    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: 'zweiter-shop' },
    })
    const css = readFileSync(GENERATED_CSS, 'utf-8')
    // Shop-Override: anderes Primary als Default (0.45 0.2 270)
    expect(css).toContain('--theme-color-primary: oklch(0.55 0.18 30)')
    expect(css).toContain('--theme-color-primary-hover: oklch(0.45 0.18 30)')
  })

  it('should keep default values for keys not overridden by shop', () => {
    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: 'zweiter-shop' },
    })
    const css = readFileSync(GENERATED_CSS, 'utf-8')
    // Default-Werte: surface, error, success, warning bleiben wie im Default
    expect(css).toContain('--theme-color-surface: oklch(1 0 0)')
    expect(css).toContain('--theme-color-error: oklch(0.55 0.2 25)')
    expect(css).toContain('--theme-color-success: oklch(0.55 0.15 145)')
    expect(css).toContain('--theme-color-warning: oklch(0.7 0.15 85)')
  })

  it('should override font.heading but keep font.body from default', () => {
    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: 'zweiter-shop' },
    })
    const css = readFileSync(GENERATED_CSS, 'utf-8')
    // Shop hat nur heading überschrieben
    expect(css).toContain('--theme-font-heading: Playfair Display')
    // body bleibt Default
    expect(css).toContain('--theme-font-body: Inter')
  })

  it('should keep all radius and shadow tokens from default when not overridden', () => {
    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: 'zweiter-shop' },
    })
    const css = readFileSync(GENERATED_CSS, 'utf-8')
    expect(css).toContain('--theme-radius-card: 0.75rem')
    expect(css).toContain('--theme-radius-button: 0.5rem')
    expect(css).toContain('--theme-shadow-card:')
    expect(css).toContain('--theme-shadow-card-hover:')
  })

  it('should generate comment header with shop theme name', () => {
    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: 'zweiter-shop' },
    })
    const css = readFileSync(GENERATED_CSS, 'utf-8')
    expect(css).toContain('/* Theme: zweiter-shop */')
  })

  it('should fall back to default and print message when shop folder does not exist', () => {
    let stdout = ''
    stdout = execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: 'shop-does-not-exist-xyz' },
    }).toString()

    // Generiert trotzdem (Fallback auf Default)
    expect(existsSync(GENERATED_CSS)).toBe(true)
    // Fallback-Meldung auf stdout
    expect(stdout).toContain('shop-does-not-exist-xyz')
  })
})

// ─── Deep Merge Unit Tests (isoliert) ────────────────────────────────────────

describe('generate-theme.mjs — deepMerge() Unit Tests', () => {
  // deepMerge ist eine interne Funktion im Skript, daher testen wir das Verhalten
  // indirekt über temporäre Theme-Dateien mit bekannten Werten

  const TEMP_THEME = 'themes/__test_merge__'
  const TEMP_THEME_DIR = resolve(FRONTEND_ROOT, TEMP_THEME)

  afterEach(() => {
    cleanupGeneratedCSS()
    if (existsSync(TEMP_THEME_DIR)) {
      rmSync(TEMP_THEME_DIR, { recursive: true, force: true })
    }
  })

  it('should apply shop color override while keeping all default colors', () => {
    mkdirSync(TEMP_THEME_DIR, { recursive: true })
    writeFileSync(
      resolve(TEMP_THEME_DIR, 'theme.yaml'),
      `colors:\n  primary: "oklch(0.6 0.2 120)"\n`
    )

    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: '__test_merge__' },
    })

    const css = readFileSync(GENERATED_CSS, 'utf-8')
    // Override gesetzt
    expect(css).toContain('--theme-color-primary: oklch(0.6 0.2 120)')
    // Default-Farben erhalten
    expect(css).toContain('--theme-color-surface:')
    expect(css).toContain('--theme-color-error:')
    expect(css).toContain('--theme-color-text-primary:')
  })

  it('should apply partial font override — only heading changed', () => {
    mkdirSync(TEMP_THEME_DIR, { recursive: true })
    writeFileSync(
      resolve(TEMP_THEME_DIR, 'theme.yaml'),
      `fonts:\n  heading: "Merriweather"\n`
    )

    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: '__test_merge__' },
    })

    const css = readFileSync(GENERATED_CSS, 'utf-8')
    expect(css).toContain('--theme-font-heading: Merriweather')
    expect(css).toContain('--theme-font-body: Inter')
  })

  it('should merge empty shop config — all defaults kept', () => {
    mkdirSync(TEMP_THEME_DIR, { recursive: true })
    writeFileSync(
      resolve(TEMP_THEME_DIR, 'theme.yaml'),
      `# Empty shop config — alle Defaults behalten\n`
    )

    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: '__test_merge__' },
    })

    const css = readFileSync(GENERATED_CSS, 'utf-8')
    // Alle Default-Farben sind im Output
    expect(css).toContain('--theme-color-primary: oklch(0.45 0.2 270)')
    expect(css).toContain('--theme-font-heading: Inter')
  })

  it('should fail validation if shop overrides a color with invalid format', () => {
    mkdirSync(TEMP_THEME_DIR, { recursive: true })
    writeFileSync(
      resolve(TEMP_THEME_DIR, 'theme.yaml'),
      `colors:\n  primary: "#FF0000"\n`
    )

    let exitCode: number | null = null
    let errorOutput = ''
    try {
      execSync(`node ${SCRIPT_PATH}`, {
        cwd: FRONTEND_ROOT,
        env: { ...process.env, NEXT_PUBLIC_THEME: '__test_merge__' },
      })
    } catch (err: any) {
      exitCode = err.status
      errorOutput = err.stderr?.toString() ?? ''
    }

    expect(exitCode).toBe(1)
    expect(errorOutput).toContain('Invalid color format for primary')
  })
})

// ─── Shop Theme YAML Schema Tests ─────────────────────────────────────────────

describe('themes/zweiter-shop/theme.yaml — Schema', () => {
  it('should parse as valid YAML', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/zweiter-shop/theme.yaml')
    const config = parse(content)
    expect(config).toBeDefined()
  })

  it('should have at least one color override', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/zweiter-shop/theme.yaml')
    const config = parse(content)
    expect(config.colors).toBeDefined()
    expect(Object.keys(config.colors ?? {}).length).toBeGreaterThan(0)
  })

  it('all defined colors should be in oklch format', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/zweiter-shop/theme.yaml')
    const config = parse(content)
    const oklchRegex = /^oklch\(\s*[\d.]+\s+[\d.]+\s+[\d.]+(\s*\/\s*[\d.]+)?\s*\)$/

    for (const [key, value] of Object.entries(config.colors as Record<string, string> ?? {})) {
      expect(
        oklchRegex.test(value),
        `Shop color "${key}" is not in oklch format: "${value}"`
      ).toBe(true)
    }
  })

  it('should NOT contain all default keys (proves it is minimal)', async () => {
    const { parse } = await import('yaml')
    const shopContent = readFile('themes/zweiter-shop/theme.yaml')
    const defaultContent = readFile('themes/default/theme.yaml')
    const shopConfig = parse(shopContent)
    const defaultConfig = parse(defaultContent)

    const defaultColorKeys = Object.keys(defaultConfig.colors ?? {})
    const shopColorKeys = Object.keys(shopConfig.colors ?? {})

    // Shop YAML should override FEWER keys than default (it is a partial override)
    expect(shopColorKeys.length).toBeLessThan(defaultColorKeys.length)
  })
})

// ─── Shop Page Config YAML Tests ──────────────────────────────────────────────

describe('themes/zweiter-shop/pages/home.yaml — Schema', () => {
  it('should parse as valid YAML with blocks array', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/zweiter-shop/pages/home.yaml')
    const config = parse(content)
    expect(config).toBeDefined()
    expect(Array.isArray(config.blocks)).toBe(true)
    expect(config.blocks.length).toBeGreaterThan(0)
  })

  it('all blocks should have required fields: type, content_source, params', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/zweiter-shop/pages/home.yaml')
    const config = parse(content)

    for (const block of config.blocks) {
      expect(block.type, `Block missing type`).toBeDefined()
      expect(block.content_source, `Block "${block.type}" missing content_source`).toBeDefined()
      expect(block.params, `Block "${block.type}" missing params`).toBeDefined()
    }
  })

  it('should differ from default home.yaml (shop has customized page config)', async () => {
    const { parse } = await import('yaml')
    const shopContent = readFile('themes/zweiter-shop/pages/home.yaml')
    const defaultContent = readFile('themes/default/pages/home.yaml')
    const shopConfig = parse(shopContent)
    const defaultConfig = parse(defaultContent)

    // Mindestens eine Differenz muss erkennbar sein (z.B. andere Anzahl Blöcke oder andere USP-Texte)
    const shopBlockTypes = shopConfig.blocks.map((b: { type: string }) => b.type).join(',')
    const defaultBlockTypes = defaultConfig.blocks.map((b: { type: string }) => b.type).join(',')

    // Entweder andere Block-Reihenfolge/Anzahl oder mindestens ein USP-Block mit anderem Inhalt
    const shopUsps = shopConfig.blocks.find((b: { type: string }) => b.type === 'usp-bar')
    const defaultUsps = defaultConfig.blocks.find((b: { type: string }) => b.type === 'usp-bar')

    const configsDiffer =
      shopBlockTypes !== defaultBlockTypes ||
      JSON.stringify(shopUsps?.params?.props?.items) !== JSON.stringify(defaultUsps?.params?.props?.items)

    expect(
      configsDiffer,
      'Shop home.yaml must differ from default home.yaml to prove customization'
    ).toBe(true)
  })
})

// ─── Logo Resolver Tests ──────────────────────────────────────────────────────

describe('lib/theme/logo.ts — getLogoPath() und getFaviconPath()', () => {
  it('should export getLogoPath function', () => {
    const content = readFile('lib/theme/logo.ts')
    expect(content).toContain('export function getLogoPath')
  })

  it('should export getFaviconPath function', () => {
    const content = readFile('lib/theme/logo.ts')
    expect(content).toContain('export function getFaviconPath')
  })

  it('getLogoPath: should return shop logo path when shop logo exists', async () => {
    const { getLogoPath } = await import('../../../frontend/lib/theme/logo')
    const result = getLogoPath('zweiter-shop')
    expect(result).toBe('/themes/zweiter-shop/assets/logo.svg')
  })

  it('getLogoPath: should return default logo path when called with "default"', async () => {
    const { getLogoPath } = await import('../../../frontend/lib/theme/logo')
    const result = getLogoPath('default')
    expect(result).toBe('/themes/default/assets/logo.svg')
  })

  it('getLogoPath: should return default logo path when shop logo does not exist', async () => {
    const { getLogoPath } = await import('../../../frontend/lib/theme/logo')
    const result = getLogoPath('theme-without-logo-xyz')
    expect(result).toBe('/themes/default/assets/logo.svg')
  })

  it('getFaviconPath: should return shop favicon path when shop favicon exists', async () => {
    const { getFaviconPath } = await import('../../../frontend/lib/theme/logo')
    const result = getFaviconPath('zweiter-shop')
    expect(result).toBe('/themes/zweiter-shop/assets/favicon.ico')
  })

  it('getFaviconPath: should return default favicon path when shop favicon does not exist', async () => {
    const { getFaviconPath } = await import('../../../frontend/lib/theme/logo')
    const result = getFaviconPath('theme-without-favicon-xyz')
    expect(result).toBe('/themes/default/assets/favicon.ico')
  })
})

// ─── Assets Tests ─────────────────────────────────────────────────────────────

describe('Assets — Logo und Favicon Existenz und Format', () => {
  it('default logo.svg should be valid SVG', () => {
    const content = readFile('public/themes/default/assets/logo.svg')
    expect(content).toContain('<svg')
    expect(content).toContain('</svg>')
  })

  it('zweiter-shop logo.svg should be valid SVG', () => {
    const content = readFile('public/themes/zweiter-shop/assets/logo.svg')
    expect(content).toContain('<svg')
    expect(content).toContain('</svg>')
  })

  it('default logo.svg should have aria-label for accessibility', () => {
    const content = readFile('public/themes/default/assets/logo.svg')
    expect(content).toContain('aria-label')
  })

  it('zweiter-shop logo.svg should have aria-label for accessibility', () => {
    const content = readFile('public/themes/zweiter-shop/assets/logo.svg')
    expect(content).toContain('aria-label')
  })

  it('default favicon.ico should exist (as binary file)', () => {
    expect(existsSync(resolve(FRONTEND_ROOT, 'public/themes/default/assets/favicon.ico'))).toBe(true)
  })

  it('zweiter-shop favicon.ico should exist (as binary file)', () => {
    expect(existsSync(resolve(FRONTEND_ROOT, 'public/themes/zweiter-shop/assets/favicon.ico'))).toBe(true)
  })
})

// ─── .env.local.example Tests ─────────────────────────────────────────────────

describe('.env.local.example — NEXT_PUBLIC_THEME Eintrag', () => {
  it('should contain NEXT_PUBLIC_THEME=default', () => {
    const content = readFile('.env.local.example')
    expect(content).toContain('NEXT_PUBLIC_THEME=default')
  })
})

// ─── generate-theme.mjs — Script enthält deepMerge ───────────────────────────

describe('generate-theme.mjs — deepMerge Funktion vorhanden', () => {
  it('should contain deepMerge function definition', () => {
    const content = readFile('scripts/generate-theme.mjs')
    expect(content).toContain('deepMerge')
  })

  it('should load default theme.yaml in main flow', () => {
    const content = readFile('scripts/generate-theme.mjs')
    expect(content).toContain('themes/default')
  })

  it('should call deepMerge to combine default and shop config', () => {
    const content = readFile('scripts/generate-theme.mjs')
    // Der Script muss beide Configs laden und dann mergen
    expect(content).toContain('deepMerge(')
  })

  it('should handle case when shop theme.yaml does not exist (fallback)', () => {
    const content = readFile('scripts/generate-theme.mjs')
    // Fallback-Logik muss im Script vorhanden sein
    expect(content).toContain('existsSync')
    expect(content).toContain('falling back')
  })
})
```
</test_spec>

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-theme-token-system | `scripts/generate-theme.mjs` | Build Script | Muss vorhanden sein — dieser Slice ersetzt es vollständig (Code-Beispiel Abschnitt 3 ist das fertige Script) |
| slice-01-theme-token-system | `themes/default/theme.yaml` | YAML Config | Vollständiges Default-Schema (12 Farben, fonts, radius, shadows) muss existieren |
| slice-04-block-registry-page-renderer | `loadPageConfig(slug, theme)` | Function | Bereits implementierter Fallback auf Default-Pages — funktioniert mit Shop-spezifischen `home.yaml` |
| slice-04-block-registry-page-renderer | `themes/default/pages/home.yaml` | YAML Config | Default-Page-Config als Basis für Shop-Vergleich in Tests |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `deepMerge()` in `generate-theme.mjs` | Build Script Funktion | Zukünftige weitere Shops | Implizit: neues `themes/{shop}/theme.yaml` anlegen + Build ausführen |
| `getLogoPath(theme)` | Function in `lib/theme/logo.ts` | `app/layout.tsx` (Header-Component), Favicon-Metadata | `(theme?: string) => string` — Returns `/themes/{shop}/assets/logo.svg` |
| `getFaviconPath(theme)` | Function in `lib/theme/logo.ts` | `app/layout.tsx` Metadata | `(theme?: string) => string` — Returns `/themes/{shop}/assets/favicon.ico` |
| `themes/zweiter-shop/` Ordnerstruktur | Theme-Verzeichnis | Beweis: Multi-Shop funktioniert | Vollständig: `theme.yaml` + `pages/home.yaml` + `assets/` |
| `public/themes/{shop}/assets/` | Static Asset Verzeichnis | Next.js Static Serving | Logo SVG und Favicon ICO |

### Integration Validation Tasks

- [ ] `generate-theme.mjs` führt Deep Merge durch — Default-Werte bleiben wenn nicht überschrieben
- [ ] `themes/zweiter-shop/theme.yaml` ist ein minimaler Override (weniger Keys als Default) und produziert valide CSS
- [ ] `getLogoPath('zweiter-shop')` gibt korrekten Pfad zurück
- [ ] `getLogoPath('nicht-existent')` fällt auf Default zurück (kein Crash, kein leerer String wenn Default vorhanden)
- [ ] `themes/zweiter-shop/pages/home.yaml` ist via `loadPageConfig('home', 'zweiter-shop')` ladbar
- [ ] `frontend/.env.local.example` enthält `NEXT_PUBLIC_THEME=default`
- [ ] Alle Platzhalter-Assets sind valide (SVG hat `<svg>` Tag, ICO-Datei existiert)

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind PFLICHT-Deliverables. Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `scripts/generate-theme.mjs` (mit deepMerge) | Abschnitt 3 | YES | Vollständiges Script das die Slice-1-Version ersetzt: deepMerge(), validateConfig(), generateCSS(), Main-Flow mit Default laden + Shop laden + Merge |
| `themes/zweiter-shop/theme.yaml` | Abschnitt 4 | YES | Minimale Overrides: primary + primary-hover + accent Farben + heading Font |
| `themes/zweiter-shop/pages/home.yaml` | Abschnitt 5 | YES | 3 Blöcke (hero/wordpress, product-grid/woocommerce, usp-bar/inline mit eigenen Texten) |
| `lib/theme/logo.ts` | Abschnitt 6 | YES | getLogoPath() + getFaviconPath() mit Fallback auf Default; fs.existsSync; öffentliche URL-Pfade |
| `public/themes/default/assets/logo.svg` | Abschnitt 7 | YES | Valides SVG mit aria-label; currentColor für Theming |
| `public/themes/zweiter-shop/assets/logo.svg` | Abschnitt 7 | YES | Valides SVG mit anderem Text und aria-label |
| `public/themes/default/assets/favicon.ico` | Abschnitt 7 | YES | Platzhalter-ICO: leere Datei die per Shell-Befehl erstellt wird; Tests prüfen nur Existenz |
| `public/themes/zweiter-shop/assets/favicon.ico` | Abschnitt 7 | YES | Platzhalter-ICO: leere Datei die per Shell-Befehl erstellt wird; Tests prüfen nur Existenz |
| `frontend/.env.local.example` (NEXT_PUBLIC_THEME) | Abschnitt 8 | YES | NEXT_PUBLIC_THEME=default Eintrag mit Kommentar |

---

## Constraints & Hinweise

**Betrifft:**
- `frontend/scripts/generate-theme.mjs` — wird durch das vollständige Code-Beispiel in Abschnitt 3 ERSETZT (nicht gepatcht)
- `frontend/public/` — neues `themes/` Unterverzeichnis für statische Assets
- `frontend/lib/theme/` — neues Unterverzeichnis (Slice 2 hat ggf. bereits `fonts.ts` angelegt)
- `frontend/.env.local.example` — neue oder erweiterte Datei

**API Contract:**
- Page Configs (`themes/{shop}/pages/*.yaml`) werden NICHT gemergt — jede Shop-Page-Config überschreibt die Default-Page-Config vollständig. Nur `theme.yaml` wird Deep Merged.
- Logo-Assets müssen unter `frontend/public/themes/` liegen, NICHT unter `frontend/themes/`. Next.js serviert nur Dateien aus `public/`.
- `getLogoPath()` und `getFaviconPath()` verwenden `fs.existsSync` — dürfen nur in Node.js RSC-Kontext oder Build-Time aufgerufen werden. NICHT in Client Components.
- `generate-theme.mjs` lädt immer zuerst `themes/default/theme.yaml` als Basis — auch wenn `NEXT_PUBLIC_THEME=default`. Für Default wird kein Merge-Schritt ausgeführt (identisches Objekt).
- Favicon-Platzhalter: Da `.ico` ein Binärformat ist, reicht eine leere Datei oder ein minimales ICO für den Platzhalter. Für die echte Implementierung muss ein gültiges ICO verwendet werden.

**Abgrenzung:**
- Font-Loading via `next/font` ist OUT of Scope für diesen Slice — wurde in Slice 2/3 implementiert. `lib/theme/fonts.ts` ist ein Deliverable von Slice 2, nicht Slice 5. Slice 5 schreibt nur `lib/theme/logo.ts`.
- `app/layout.tsx` Integration von Logo/Favicon (tatsächliches Einbinden von `getLogoPath()` in den Header) ist OUT of Scope für diesen Slice — würde Slice 3 (Component Migration) berühren und ist eine separate Aufgabe.
- Runtime Domain-basiertes Theme-Switching ist explizit OUT of Scope (architecture.md). Nur Build-Time per Env-Variable.
- Die ThemeSwitcher-Component (aus dem Runtime-System) wird in diesem Slice NICHT entfernt — das war ein Deliverable von Slice 3 (Component Migration).
- Mehr als 2 Themes anlegen ist NICHT erforderlich — `default` + `zweiter-shop` reicht als Beweis des Multi-Shop-Systems.

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig & vollständig
- [ ] `NEXT_PUBLIC_THEME=zweiter-shop pnpm build` erzeugt visuelle Differenz gegenüber Default-Build (andere Primärfarbe, anderer Heading-Font im CSS Output)
- [ ] Deep Merge Unit Tests grün
- [ ] Asset-Pfad-Resolver gibt korrekten Pfad zurück (manuell verifizierbar)
- [ ] `themes/zweiter-shop/theme.yaml` ist minimal — beweist dass Deep Merge keine vollständige Config erfordert

---

## Skill Verification (UI-Implementation)

### React Best Practices Verification

**Critical Priority:**
- [x] `async-parallel`: Nicht relevant — kein React-Component-Code in diesem Slice (nur Build-Script + Resolver-Funktion)
- [x] `bundle-dynamic-imports`: Nicht relevant — kein Client-Component-Code

**High Priority:**
- [x] `server-cache-react`: `getLogoPath()` und `getFaviconPath()` sind reine Funktionen ohne State — keine Caching-Problematik
- [x] `server-serialization`: Logo-Pfad ist ein String — keine großen Objekte an Client übergeben

### Web Design Guidelines Verification

**Accessibility:**
- [x] Logo SVGs haben `role="img"` und `aria-label` — Screen Reader kann Shop-Name lesen
- [x] `currentColor` in SVG — respektiert CSS-Farb-Tokens ohne hardcoded Farben

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [x] Shop-YAML verwendet OKLch Farben — konsistent mit Slice 1 Schema
- [x] Keine hardcoded Hex-Werte in Shop-YAML oder Assets

---

## Links

- Design/Spec: `specs/phase-0/2026-02-21-frontend-theming/architecture.md`
- Discovery: `specs/phase-0/2026-02-21-frontend-theming/discovery.md`
- Dependency Slice 1: `specs/phase-0/2026-02-21-frontend-theming/slices/slice-01-theme-token-system.md`
- Dependency Slice 4: `specs/phase-0/2026-02-21-frontend-theming/slices/slice-04-block-registry-page-renderer.md`
- Next.js Static File Serving: https://nextjs.org/docs/app/api-reference/file-conventions/public-folder

---

## Deliverables (SCOPE SAFEGUARD)

<!-- DELIVERABLES_START -->
### Build Infrastructure
- [ ] `frontend/scripts/generate-theme.mjs` — ERSETZT durch vollständiges Code-Beispiel aus Abschnitt 3: enthält `deepMerge(base, override)` (neu), `validateConfig()` und `generateCSS()` (aus Slice 1 beibehalten, identisch); Main-Flow lädt Default-YAML + Shop-YAML (falls vorhanden) → deepMerge → validateConfig → generateCSS; Fallback-Meldung wenn Shop-Verzeichnis fehlt

### Theme Config — Zweiter Shop
- [ ] `frontend/themes/zweiter-shop/theme.yaml` — Minimale Overrides: `colors.primary`, `colors.primary-hover`, `colors.accent` (OKLch-Werte abweichend von Default), `fonts.heading` (anderer Font als Default); KEINE vollständige Kopie des Default-Schemas
- [ ] `frontend/themes/zweiter-shop/pages/home.yaml` — Shop-spezifische Homepage-Block-Config: mind. hero (wordpress) + product-grid (woocommerce) + usp-bar (inline mit anderen USP-Texten als Default); unterscheidet sich von `themes/default/pages/home.yaml`

### Assets (Public Serving)
- [ ] `frontend/public/themes/default/assets/logo.svg` — Platzhalter-SVG mit `role="img"`, `aria-label="POD Shop Logo"`, `currentColor` für Farb-Theming
- [ ] `frontend/public/themes/default/assets/favicon.ico` — Platzhalter-Favicon (leere oder minimale ICO-Datei)
- [ ] `frontend/public/themes/zweiter-shop/assets/logo.svg` — Platzhalter-SVG mit `role="img"`, `aria-label="Zweiter Shop Logo"`, anderer Text als Default
- [ ] `frontend/public/themes/zweiter-shop/assets/favicon.ico` — Platzhalter-Favicon für zweiten Shop

### Frontend — Neue Dateien
- [ ] `frontend/lib/theme/logo.ts` — `getLogoPath(theme?: string) => string`: prüft `public/themes/{theme}/assets/logo.svg` via `existsSync`, Fallback auf Default; `getFaviconPath(theme?: string) => string`: analog für favicon.ico; Node.js only (fs.existsSync)

### Config
- [ ] `frontend/.env.local.example` — Enthält `NEXT_PUBLIC_THEME=default` mit erklärendem Kommentar (neue Datei oder Erweiterung der bestehenden)

### Tests
- [ ] `frontend/tests/slices/frontend-theming/slice-05-theme-config-multi-shop.test.ts` — Datei-Existenz-Tests, Deep Merge Integrationstests (Shop-Override + Default-Fallback), isolierte deepMerge Unit Tests via Temp-Themes, YAML-Schema-Tests für zweiter-shop theme.yaml und home.yaml, Logo-Resolver-Tests (Shop vorhanden + Fallback), Asset-Validierungs-Tests (SVG-Format, aria-label), .env.local.example Test, generate-theme.mjs Code-Assertions (deepMerge vorhanden)
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind Pflicht
- `generate-theme.mjs` wird durch das vollständige Code-Beispiel in Abschnitt 3 ERSETZT — das Code-Beispiel ist das fertige Script nach diesem Slice. Die Funktionen `validateConfig()` und `generateCSS()` aus Slice 1 werden beibehalten und sind im Code-Beispiel identisch enthalten. Neu hinzugekommen ist `deepMerge()` sowie der erweiterte Main-Flow. Der Implementierungs-Agent soll das gesamte Script mit dem Code-Beispiel ersetzen.
- `themes/zweiter-shop/theme.yaml` muss MINIMAL sein — der Sinn ist der Beweis dass Deep Merge funktioniert. Nur überschriebene Werte eintragen.
- Favicon-Placeholder: Eine leere `.ico`-Datei oder eine Datei mit einem minimalen ICO-Header reicht für den Platzhalter. Echter Favicon kommt später.
- `getLogoPath()` und `getFaviconPath()` sind Server-Side only (Node.js `fs.existsSync`) — dürfen nicht in `'use client'` Components importiert werden
- Page Configs (`home.yaml`) werden NICHT gemergt — nur `theme.yaml` wird Deep Merged. `loadPageConfig()` aus Slice 4 lädt entweder die Shop-YAML oder fällt auf Default zurück (bereits implementiert).
