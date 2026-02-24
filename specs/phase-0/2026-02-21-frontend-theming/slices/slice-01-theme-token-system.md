# Slice 1: Theme Token System einrichten

> **Slice 1 von 5** für `Frontend-Architektur: Theming (P0.2)`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | — |
> | **Nächster:** | `slice-02-shadcn-primitives.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-01-theme-token-system` |
| **Test** | `pnpm test tests/slices/frontend-theming/slice-01-theme-token-system.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `[]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` — Vitest Unit Tests (`.test.ts`)
- **Dependencies**: Keine — dieser Slice ist das Fundament

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren.
> `frontend/package.json` enthält `next`, `vitest`, `@tailwindcss/postcss` → Stack: typescript-nextjs

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `cd frontend && pnpm test tests/slices/frontend-theming/slice-01-theme-token-system.test.ts` |
| **Integration Command** | `cd frontend && pnpm test tests/slices/frontend-theming/` |
| **Acceptance Command** | `cd frontend && node scripts/generate-theme.mjs && cat app/generated-theme.css` |
| **Start Command** | `cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000` |
| **Mocking Strategy** | `no_mocks` |

**Erklaerung:**
- **Stack**: Next.js 16 + Vitest v3 + Tailwind v4 CSS-first
- **Test Command**: Unit Tests für Build-Script-Logik (YAML-Parsing, Validierung, CSS-Generierung)
- **Integration Command**: Alle Theming-Slice-Tests zusammen
- **Acceptance Command**: Build-Script direkt ausführen, Output prüfen
- **Mocking Strategy**: `no_mocks` — das Build-Script arbeitet auf dem Dateisystem, keine externen Services

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Theme Token System | **Ready** | `slice-01-theme-token-system.md` |
| 2 | shadcn/ui Primitives | Pending | `slice-02-shadcn-primitives.md` |
| 3 | Component Migration (Big Bang) | Pending | `slice-03-component-migration.md` |
| 4 | Block Registry + Page Renderer | Pending | `slice-04-block-registry.md` |
| 5 | Theme Config + Multi-Shop | Pending | `slice-05-multi-shop.md` |

---

## Kontext & Ziel

Dieser Slice legt das Fundament für das gesamte Theming-System. Aktuell sind Farben in `globals.css` via `[data-theme]` CSS-Blöcken mit Runtime-Switching implementiert — das wird durch ein Build-Time-System ersetzt.

**Aktuelle Probleme:**
1. `--radius-card`, `--radius-button`, `--shadow-card`, `--shadow-card-hover` sind nur in `[data-theme]` Blöcken definiert, nicht im `@theme` Block — daher nicht als Tailwind-Utility-Klassen verfügbar
2. Keine Status-Tokens: `error`, `success`, `warning`, `overlay` fehlen im `@theme` Block
3. Runtime ThemeProvider/ThemeSwitcher + `[data-theme]` Blöcke existieren — werden durch Build-Time YAML ersetzt
4. Kein `lib/utils.ts` mit `cn()` Utility (clsx + tailwind-merge) vorhanden
5. Kein `themes/default/theme.yaml` Schema vorhanden
6. Kein Build-Script `scripts/generate-theme.mjs` vorhanden

**Zielbild nach diesem Slice:**
- `themes/default/theme.yaml` definiert alle Token-Werte (Farben, Fonts, Radius, Shadows)
- `scripts/generate-theme.mjs` liest `NEXT_PUBLIC_THEME` Env-Variable, validiert YAML, generiert `app/generated-theme.css`
- `app/globals.css` importiert `app/generated-theme.css` statt Runtime `[data-theme]` Blöcke
- `@theme` Block in `globals.css` registriert alle Tokens inkl. error, success, warning, overlay, radius, shadow
- `lib/utils.ts` stellt `cn()` Utility bereit (clsx + tailwind-merge)

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Build-Time Theme Pipeline

```
ENV (NEXT_PUBLIC_THEME) → Read themes/{theme}/theme.yaml
                        → Read themes/default/theme.yaml  (Fallback)
                        → Deep Merge (shop overrides default)
                        → Validate merged config
                        → Generate CSS Custom Properties
                        → Write app/generated-theme.css
                        → globals.css @imports generated-theme.css
                        → Tailwind @theme consumes CSS vars
                        → Next.js build
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `frontend/themes/` | NEU: Verzeichnis mit `default/theme.yaml` |
| `frontend/scripts/generate-theme.mjs` | NEU: Build-Script (YAML → CSS) |
| `frontend/app/generated-theme.css` | NEU: Build-Output (gitignored) |
| `frontend/app/globals.css` | GEÄNDERT: `@import "generated-theme.css"` hinzufügen, `[data-theme]` Blöcke entfernen, `@theme` Block um neue Tokens erweitern |
| `frontend/lib/utils.ts` | NEU: `cn()` Utility (clsx + tailwind-merge) |
| `frontend/package.json` | GEÄNDERT: `yaml`, `clsx`, `tailwind-merge` als Dependencies hinzufügen; `prebuild` Script für Theme-Generierung |

### 2. Datenfluss

```
NEXT_PUBLIC_THEME (Env-Variable, default: "default")
  ↓
generate-theme.mjs (Node.js Script)
  ↓ fs.readFileSync
themes/default/theme.yaml (vollständige Default-Konfiguration)
  ↓ yaml.parse()
Merged Config Object (colors, fonts, radius, shadows)
  ↓ validateConfig() — Exit Code 1 bei Fehler
Validiertes Config Object
  ↓ generateCSS()
CSS String mit :root { --color-primary: oklch(...); ... }
  ↓ fs.writeFileSync
app/generated-theme.css
  ↓ @import in globals.css
Tailwind v4 @theme Block (konsumiert via var())
  ↓ pnpm build
Finale CSS-Output mit allen Token-Werten
```

### 3. theme.yaml Schema

Das Default-Theme definiert alle verfügbaren Tokens. Shop-Themes können einzelne Werte überschreiben (Deep Merge in Slice 5).

**Vollständiges Default-Schema:**

```yaml
# themes/default/theme.yaml

colors:
  primary: "oklch(0.45 0.2 270)"
  primary-hover: "oklch(0.38 0.2 270)"
  accent: "oklch(0.65 0.15 270)"
  surface: "oklch(1 0 0)"
  surface-elevated: "oklch(0.98 0 0)"
  text-primary: "oklch(0.15 0 0)"
  text-secondary: "oklch(0.45 0 0)"
  border: "oklch(0.88 0 0)"
  # NEU: Status-Tokens
  error: "oklch(0.55 0.2 25)"
  success: "oklch(0.55 0.15 145)"
  warning: "oklch(0.7 0.15 85)"
  # NEU: Overlay-Token (mit Alpha)
  overlay: "oklch(0 0 0 / 0.5)"

fonts:
  heading: "Inter"
  body: "Inter"

radius:
  card: "0.75rem"
  button: "0.5rem"

shadows:
  card: "0 1px 3px oklch(0 0 0 / 0.08)"
  card-hover: "0 8px 25px oklch(0 0 0 / 0.12)"
```

### 4. generate-theme.mjs — Build Script

Das Script liest YAML, validiert alle Werte, und generiert CSS Custom Properties.

**Validierungsregeln (aus architecture.md):**

| Feld | Regel | Fehler |
|------|-------|--------|
| `colors.*` | OKLch Format: `oklch(L C H)` oder `oklch(L C H / A)` | `Invalid color format for {key}: expected oklch()` |
| `fonts.*` | Non-empty String | `Font name required for {key}` |
| `radius.*` | Non-empty String (z.B. `"0.75rem"`) | `Radius value required for {key}` |
| `shadows.*` | Non-empty String | `Shadow value required for {key}` |

**Fehlerverhalten:**
- Valide config → generiert `app/generated-theme.css`, Exit Code 0
- Invalide config → gibt Fehlermeldung aus, Exit Code 1, stoppt den Build

**Vollstaendiges Code-Beispiel:**

```javascript
// scripts/generate-theme.mjs
import { readFileSync, writeFileSync, existsSync } from 'fs'
import { resolve, dirname } from 'path'
import { fileURLToPath } from 'url'
import { parse } from 'yaml'

const __dirname = dirname(fileURLToPath(import.meta.url))
const FRONTEND_ROOT = resolve(__dirname, '..')

const OKLCH_REGEX = /^oklch\(\s*[\d.]+\s+[\d.]+\s+[\d.]+(\s*\/\s*[\d.]+)?\s*\)$/

/**
 * Validates a theme config object.
 * Exits with code 1 and prints error message on first validation failure.
 * @param {Record<string, any>} config
 */
function validateConfig(config) {
  // Validate colors
  if (config.colors) {
    for (const [key, value] of Object.entries(config.colors)) {
      if (typeof value !== 'string' || !OKLCH_REGEX.test(value.trim())) {
        process.stderr.write(`Invalid color format for ${key}: expected oklch()\n`)
        process.exit(1)
      }
    }
  }

  // Validate fonts
  if (config.fonts) {
    for (const [key, value] of Object.entries(config.fonts)) {
      if (!value || typeof value !== 'string' || value.trim() === '') {
        process.stderr.write(`Font name required for ${key}\n`)
        process.exit(1)
      }
    }
  }

  // Validate radius
  if (config.radius) {
    for (const [key, value] of Object.entries(config.radius)) {
      if (!value || typeof value !== 'string' || value.trim() === '') {
        process.stderr.write(`Radius value required for ${key}\n`)
        process.exit(1)
      }
    }
  }

  // Validate shadows
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

// Determine which theme.yaml to load — fallback to default if theme folder does not exist
let resolvedThemeDir = themeDir
if (!existsSync(resolve(themeDir, 'theme.yaml'))) {
  resolvedThemeDir = defaultThemeDir
}

const yamlPath = resolve(resolvedThemeDir, 'theme.yaml')
const yamlContent = readFileSync(yamlPath, 'utf-8')
const config = parse(yamlContent)

validateConfig(config)

const resolvedThemeName = resolvedThemeDir === defaultThemeDir ? 'default' : themeName
const css = generateCSS(config, resolvedThemeName)

writeFileSync(outputPath, css, 'utf-8')
process.stdout.write(`Theme generated: ${outputPath} (theme: ${resolvedThemeName})\n`)
```

### 5. globals.css Änderungen

Die bestehenden `[data-theme]` Blöcke werden entfernt. Der `@theme` Block wird um alle fehlenden Tokens erweitert.

**Neuer @theme Block (vollständig):**

```css
@import "./generated-theme.css";
@import "tailwindcss";

@theme {
  /* Farben aus generated-theme.css via var() */
  --color-primary: var(--theme-color-primary);
  --color-primary-hover: var(--theme-color-primary-hover);
  --color-accent: var(--theme-color-accent);
  --color-surface: var(--theme-color-surface);
  --color-surface-elevated: var(--theme-color-surface-elevated);
  --color-text-primary: var(--theme-color-text-primary);
  --color-text-secondary: var(--theme-color-text-secondary);
  --color-border: var(--theme-color-border);
  /* NEU: Status-Tokens */
  --color-error: var(--theme-color-error);
  --color-success: var(--theme-color-success);
  --color-warning: var(--theme-color-warning);
  --color-overlay: var(--theme-color-overlay);

  /* Touch-Targets */
  --min-touch-target: 2.75rem;

  /* Spacing */
  --spacing-18: 4.5rem;

  /* Animations */
  --animate-slide-up: slide-up 0.25s ease-out;

  /* NEU: Radius-Tokens (aus [data-theme] in @theme verschoben) */
  --radius-card: var(--theme-radius-card);
  --radius-button: var(--theme-radius-button);

  /* NEU: Shadow-Tokens (aus [data-theme] in @theme verschoben) */
  --shadow-card: var(--theme-shadow-card);
  --shadow-card-hover: var(--theme-shadow-card-hover);
}
```

**WICHTIG:** `generated-theme.css` verwendet `--theme-*` Präfix um Konflikte mit Tailwind-Defaults zu vermeiden. Der `@theme` Block mappt diese auf die Token-Namen ohne Präfix.

### 6. generated-theme.css Format

Das Build-Script generiert dieses Format:

```css
/* Auto-generated by scripts/generate-theme.mjs — DO NOT EDIT */
/* Theme: default */
:root {
  --theme-color-primary: oklch(0.45 0.2 270);
  --theme-color-primary-hover: oklch(0.38 0.2 270);
  --theme-color-accent: oklch(0.65 0.15 270);
  --theme-color-surface: oklch(1 0 0);
  --theme-color-surface-elevated: oklch(0.98 0 0);
  --theme-color-text-primary: oklch(0.15 0 0);
  --theme-color-text-secondary: oklch(0.45 0 0);
  --theme-color-border: oklch(0.88 0 0);
  --theme-color-error: oklch(0.55 0.2 25);
  --theme-color-success: oklch(0.55 0.15 145);
  --theme-color-warning: oklch(0.7 0.15 85);
  --theme-color-overlay: oklch(0 0 0 / 0.5);
  --theme-font-heading: Inter;
  --theme-font-body: Inter;
  --theme-radius-card: 0.75rem;
  --theme-radius-button: 0.5rem;
  --theme-shadow-card: 0 1px 3px oklch(0 0 0 / 0.08);
  --theme-shadow-card-hover: 0 8px 25px oklch(0 0 0 / 0.12);
}
```

### 7. cn() Utility

```typescript
// frontend/lib/utils.ts
import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}
```

### 8. package.json Änderungen

**Neue Dependencies:**
```json
{
  "dependencies": {
    "clsx": "^2.0.0",
    "tailwind-merge": "^3.5.0",
    "yaml": "^2.7.0"
  },
  "scripts": {
    "generate-theme": "node scripts/generate-theme.mjs",
    "prebuild": "node scripts/generate-theme.mjs",
    "predev": "node scripts/generate-theme.mjs"
  }
}
```

**WICHTIG:** `yaml` ist eine runtime-dependency (wird auch im ISR-Kontext für Page-Config-Loading in Slice 4 gebraucht). `clsx` und `tailwind-merge` sind runtime-dependencies (werden in Components genutzt).

### 9. .gitignore Eintrag

```
# Theme Build Output
frontend/app/generated-theme.css
```

### 10. Abhängigkeiten

| Paket | Version | Zweck |
|-------|---------|-------|
| `yaml` | `^2.7.0` | YAML-Parsing im Build-Script und ISR-Runtime |
| `clsx` | `^2.0.0` | Conditional class merging |
| `tailwind-merge` | `^3.5.0` | Tailwind class conflict resolution |

---

## Acceptance Criteria

1) GIVEN `NEXT_PUBLIC_THEME` ist nicht gesetzt
   WHEN `node scripts/generate-theme.mjs` ausgeführt wird
   THEN wird `themes/default/theme.yaml` geladen und `app/generated-theme.css` wird erstellt mit allen 12 Farb-Tokens, 2 Font-Tokens, 2 Radius-Tokens, 2 Shadow-Tokens

2) GIVEN `NEXT_PUBLIC_THEME=default`
   WHEN `node scripts/generate-theme.mjs` ausgeführt wird
   THEN enthält `app/generated-theme.css` exakt: `--theme-color-error: oklch(0.55 0.2 25)`, `--theme-color-success: oklch(0.55 0.15 145)`, `--theme-color-warning: oklch(0.7 0.15 85)`, `--theme-color-overlay: oklch(0 0 0 / 0.5)`

3) GIVEN `themes/default/theme.yaml` enthält einen ungültigen Farbwert (z.B. `primary: "#FF0000"`)
   WHEN `node scripts/generate-theme.mjs` ausgeführt wird
   THEN gibt das Script eine Fehlermeldung aus (`Invalid color format for primary: expected oklch()`) und beendet sich mit Exit Code 1

4) GIVEN `themes/default/theme.yaml` enthält einen leeren Font-Namen (`heading: ""`)
   WHEN `node scripts/generate-theme.mjs` ausgeführt wird
   THEN gibt das Script eine Fehlermeldung aus (`Font name required for heading`) und beendet sich mit Exit Code 1

5) GIVEN `app/generated-theme.css` wurde erfolgreich generiert
   WHEN `globals.css` importiert wird
   THEN sind die CSS Custom Properties `--color-error`, `--color-success`, `--color-warning`, `--color-overlay`, `--radius-card`, `--radius-button`, `--shadow-card`, `--shadow-card-hover` als Tailwind-Utility-Klassen verfügbar (z.B. `bg-error`, `rounded-[var(--radius-card)]`)

6) GIVEN `app/globals.css` wurde angepasst
   WHEN die `[data-theme]` Blöcke entfernt wurden
   THEN existieren im `globals.css` keine `[data-theme="modern"]`, `[data-theme="bold"]`, `[data-theme="soft"]` Blöcke mehr

7) GIVEN `lib/utils.ts` existiert
   WHEN `cn('bg-primary', 'bg-error')` aufgerufen wird
   THEN gibt `cn()` den korrekten merged String zurück (tailwind-merge löst Konflikte auf)

8) GIVEN `pnpm build` ausgeführt wird
   WHEN `prebuild` Script läuft
   THEN wird `generate-theme.mjs` automatisch vor dem Next.js Build ausgeführt

---

## Testfälle

### Test-Datei

`tests/slices/frontend-theming/slice-01-theme-token-system.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// tests/slices/frontend-theming/slice-01-theme-token-system.test.ts
import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { readFileSync, writeFileSync, existsSync, unlinkSync, mkdirSync, rmSync } from 'fs'
import { resolve } from 'path'
import { execSync } from 'child_process'

const FRONTEND_ROOT = resolve(__dirname, '../../../frontend')
const GENERATED_CSS = resolve(FRONTEND_ROOT, 'app/generated-theme.css')
const SCRIPT_PATH = resolve(FRONTEND_ROOT, 'scripts/generate-theme.mjs')
const DEFAULT_THEME = resolve(FRONTEND_ROOT, 'themes/default/theme.yaml')

describe('Theme Token System — generate-theme.mjs', () => {
  afterEach(() => {
    // Cleanup: generated-theme.css nach jedem Test löschen
    if (existsSync(GENERATED_CSS)) {
      unlinkSync(GENERATED_CSS)
    }
  })

  describe('Script existiert', () => {
    it('should have generate-theme.mjs in frontend/scripts/', () => {
      expect(existsSync(SCRIPT_PATH)).toBe(true)
    })

    it('should have themes/default/theme.yaml', () => {
      expect(existsSync(DEFAULT_THEME)).toBe(true)
    })
  })

  describe('Erfolgreiche CSS-Generierung', () => {
    it('should generate app/generated-theme.css when no NEXT_PUBLIC_THEME is set', () => {
      execSync(`node ${SCRIPT_PATH}`, { cwd: FRONTEND_ROOT, env: { ...process.env, NEXT_PUBLIC_THEME: '' } })
      expect(existsSync(GENERATED_CSS)).toBe(true)
    })

    it('should include all required color tokens in generated CSS', () => {
      execSync(`node ${SCRIPT_PATH}`, { cwd: FRONTEND_ROOT })
      const css = readFileSync(GENERATED_CSS, 'utf-8')
      expect(css).toContain('--theme-color-primary')
      expect(css).toContain('--theme-color-primary-hover')
      expect(css).toContain('--theme-color-accent')
      expect(css).toContain('--theme-color-surface')
      expect(css).toContain('--theme-color-surface-elevated')
      expect(css).toContain('--theme-color-text-primary')
      expect(css).toContain('--theme-color-text-secondary')
      expect(css).toContain('--theme-color-border')
    })

    it('should include new status color tokens (error, success, warning)', () => {
      execSync(`node ${SCRIPT_PATH}`, { cwd: FRONTEND_ROOT })
      const css = readFileSync(GENERATED_CSS, 'utf-8')
      expect(css).toContain('--theme-color-error: oklch(0.55 0.2 25)')
      expect(css).toContain('--theme-color-success: oklch(0.55 0.15 145)')
      expect(css).toContain('--theme-color-warning: oklch(0.7 0.15 85)')
    })

    it('should include overlay token with alpha channel', () => {
      execSync(`node ${SCRIPT_PATH}`, { cwd: FRONTEND_ROOT })
      const css = readFileSync(GENERATED_CSS, 'utf-8')
      expect(css).toContain('--theme-color-overlay: oklch(0 0 0 / 0.5)')
    })

    it('should include radius tokens', () => {
      execSync(`node ${SCRIPT_PATH}`, { cwd: FRONTEND_ROOT })
      const css = readFileSync(GENERATED_CSS, 'utf-8')
      expect(css).toContain('--theme-radius-card: 0.75rem')
      expect(css).toContain('--theme-radius-button: 0.5rem')
    })

    it('should include shadow tokens', () => {
      execSync(`node ${SCRIPT_PATH}`, { cwd: FRONTEND_ROOT })
      const css = readFileSync(GENERATED_CSS, 'utf-8')
      expect(css).toContain('--theme-shadow-card:')
      expect(css).toContain('--theme-shadow-card-hover:')
    })

    it('should include font tokens', () => {
      execSync(`node ${SCRIPT_PATH}`, { cwd: FRONTEND_ROOT })
      const css = readFileSync(GENERATED_CSS, 'utf-8')
      expect(css).toContain('--theme-font-heading: Inter')
      expect(css).toContain('--theme-font-body: Inter')
    })

    it('should wrap all tokens in :root block', () => {
      execSync(`node ${SCRIPT_PATH}`, { cwd: FRONTEND_ROOT })
      const css = readFileSync(GENERATED_CSS, 'utf-8')
      expect(css).toContain(':root {')
      expect(css).toContain('}')
    })

    it('should include auto-generated comment header', () => {
      execSync(`node ${SCRIPT_PATH}`, { cwd: FRONTEND_ROOT })
      const css = readFileSync(GENERATED_CSS, 'utf-8')
      expect(css).toContain('Auto-generated by scripts/generate-theme.mjs')
    })
  })

  describe('YAML-Validierung — Farbformat', () => {
    it('should exit with code 1 when color is not oklch format', () => {
      // Temporäres kaputtes theme.yaml
      const invalidYaml = `
colors:
  primary: "#FF0000"
  error: "oklch(0.55 0.2 25)"
fonts:
  heading: "Inter"
  body: "Inter"
radius:
  card: "0.75rem"
  button: "0.5rem"
shadows:
  card: "0 1px 3px oklch(0 0 0 / 0.08)"
  card-hover: "0 8px 25px oklch(0 0 0 / 0.12)"
`
      const tempThemeDir = resolve(FRONTEND_ROOT, 'themes/__test_invalid__')
      mkdirSync(tempThemeDir, { recursive: true })
      writeFileSync(resolve(tempThemeDir, 'theme.yaml'), invalidYaml)

      let exitCode: number | null = null
      let errorOutput = ''
      try {
        execSync(`node ${SCRIPT_PATH}`, {
          cwd: FRONTEND_ROOT,
          env: { ...process.env, NEXT_PUBLIC_THEME: '__test_invalid__' }
        })
      } catch (err: any) {
        exitCode = err.status
        errorOutput = err.stderr?.toString() ?? ''
      } finally {
        // Cleanup
        rmSync(tempThemeDir, { recursive: true, force: true })
      }

      expect(exitCode).toBe(1)
      expect(errorOutput).toContain('Invalid color format for primary')
    })

    it('should exit with code 1 when font name is empty', () => {
      const invalidYaml = `
colors:
  primary: "oklch(0.45 0.2 270)"
  error: "oklch(0.55 0.2 25)"
  success: "oklch(0.55 0.15 145)"
  warning: "oklch(0.7 0.15 85)"
  overlay: "oklch(0 0 0 / 0.5)"
fonts:
  heading: ""
  body: "Inter"
radius:
  card: "0.75rem"
  button: "0.5rem"
shadows:
  card: "0 1px 3px oklch(0 0 0 / 0.08)"
  card-hover: "0 8px 25px oklch(0 0 0 / 0.12)"
`
      const tempThemeDir = resolve(FRONTEND_ROOT, 'themes/__test_empty_font__')
      mkdirSync(tempThemeDir, { recursive: true })
      writeFileSync(resolve(tempThemeDir, 'theme.yaml'), invalidYaml)

      let exitCode: number | null = null
      let errorOutput = ''
      try {
        execSync(`node ${SCRIPT_PATH}`, {
          cwd: FRONTEND_ROOT,
          env: { ...process.env, NEXT_PUBLIC_THEME: '__test_empty_font__' }
        })
      } catch (err: any) {
        exitCode = err.status
        errorOutput = err.stderr?.toString() ?? ''
      } finally {
        rmSync(tempThemeDir, { recursive: true, force: true })
      }

      expect(exitCode).toBe(1)
      expect(errorOutput).toContain('Font name required for heading')
    })
  })

  describe('Fallback auf Default-Theme', () => {
    it('should fall back to default theme when NEXT_PUBLIC_THEME folder does not exist', () => {
      // Nicht-existierendes Theme angeben
      execSync(`node ${SCRIPT_PATH}`, {
        cwd: FRONTEND_ROOT,
        env: { ...process.env, NEXT_PUBLIC_THEME: 'non-existent-shop-xyz' }
      })
      // Soll trotzdem generieren (Fallback auf default)
      expect(existsSync(GENERATED_CSS)).toBe(true)
    })
  })
})

describe('cn() Utility', () => {
  it('should exist in lib/utils.ts', async () => {
    const { cn } = await import('../../../frontend/lib/utils')
    expect(typeof cn).toBe('function')
  })

  it('should merge class names correctly', async () => {
    const { cn } = await import('../../../frontend/lib/utils')
    const result = cn('bg-primary', 'text-white')
    expect(result).toBe('bg-primary text-white')
  })

  it('should resolve tailwind conflicts (last wins)', async () => {
    const { cn } = await import('../../../frontend/lib/utils')
    const result = cn('bg-primary', 'bg-error')
    // tailwind-merge: bg-error gewinnt (letzter Wert)
    expect(result).toBe('bg-error')
  })

  it('should handle conditional classes', async () => {
    const { cn } = await import('../../../frontend/lib/utils')
    const isActive = true
    const result = cn('base-class', isActive && 'active-class', !isActive && 'inactive-class')
    expect(result).toBe('base-class active-class')
  })

  it('should handle undefined and null values', async () => {
    const { cn } = await import('../../../frontend/lib/utils')
    const result = cn('base', undefined, null, false, 'end')
    expect(result).toBe('base end')
  })
})

describe('globals.css — Token Registration', () => {
  const globalsPath = resolve(FRONTEND_ROOT, 'app/globals.css')

  it('should import generated-theme.css', () => {
    const css = readFileSync(globalsPath, 'utf-8')
    expect(css).toContain('@import "./generated-theme.css"')
  })

  it('should NOT contain [data-theme] blocks anymore', () => {
    const css = readFileSync(globalsPath, 'utf-8')
    expect(css).not.toContain('[data-theme=')
  })

  it('should register --color-error in @theme block', () => {
    const css = readFileSync(globalsPath, 'utf-8')
    expect(css).toContain('--color-error')
  })

  it('should register --color-success in @theme block', () => {
    const css = readFileSync(globalsPath, 'utf-8')
    expect(css).toContain('--color-success')
  })

  it('should register --color-warning in @theme block', () => {
    const css = readFileSync(globalsPath, 'utf-8')
    expect(css).toContain('--color-warning')
  })

  it('should register --color-overlay in @theme block', () => {
    const css = readFileSync(globalsPath, 'utf-8')
    expect(css).toContain('--color-overlay')
  })

  it('should register --radius-card in @theme block', () => {
    const css = readFileSync(globalsPath, 'utf-8')
    expect(css).toContain('--radius-card')
  })

  it('should register --radius-button in @theme block', () => {
    const css = readFileSync(globalsPath, 'utf-8')
    expect(css).toContain('--radius-button')
  })

  it('should register --shadow-card in @theme block', () => {
    const css = readFileSync(globalsPath, 'utf-8')
    expect(css).toContain('--shadow-card')
  })

  it('should register --shadow-card-hover in @theme block', () => {
    const css = readFileSync(globalsPath, 'utf-8')
    expect(css).toContain('--shadow-card-hover')
  })
})

describe('theme.yaml — Default Schema', () => {
  it('should have all required color keys', async () => {
    const { parse } = await import('yaml')
    const content = readFileSync(DEFAULT_THEME, 'utf-8')
    const config = parse(content)
    const requiredColors = [
      'primary', 'primary-hover', 'accent', 'surface', 'surface-elevated',
      'text-primary', 'text-secondary', 'border',
      'error', 'success', 'warning', 'overlay'
    ]
    for (const key of requiredColors) {
      expect(config.colors[key], `Missing color: ${key}`).toBeDefined()
      expect(config.colors[key], `Empty color: ${key}`).not.toBe('')
    }
  })

  it('should have all colors in oklch format', async () => {
    const { parse } = await import('yaml')
    const content = readFileSync(DEFAULT_THEME, 'utf-8')
    const config = parse(content)
    const oklchRegex = /^oklch\(\s*[\d.]+\s+[\d.]+\s+[\d.]+(\s*\/\s*[\d.]+)?\s*\)$/
    for (const [key, value] of Object.entries(config.colors as Record<string, string>)) {
      expect(oklchRegex.test(value), `Color "${key}" is not in oklch format: "${value}"`).toBe(true)
    }
  })

  it('should have fonts.heading and fonts.body defined and non-empty', async () => {
    const { parse } = await import('yaml')
    const content = readFileSync(DEFAULT_THEME, 'utf-8')
    const config = parse(content)
    expect(config.fonts?.heading).toBeTruthy()
    expect(config.fonts?.body).toBeTruthy()
  })

  it('should have radius.card and radius.button defined', async () => {
    const { parse } = await import('yaml')
    const content = readFileSync(DEFAULT_THEME, 'utf-8')
    const config = parse(content)
    expect(config.radius?.card).toBeTruthy()
    expect(config.radius?.button).toBeTruthy()
  })

  it('should have shadows.card and shadows.card-hover defined', async () => {
    const { parse } = await import('yaml')
    const content = readFileSync(DEFAULT_THEME, 'utf-8')
    const config = parse(content)
    expect(config.shadows?.card).toBeTruthy()
    expect(config.shadows?.['card-hover']).toBeTruthy()
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
| — | — | — | Keine Dependencies |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `app/generated-theme.css` | CSS File | `globals.css`, Slice 2 | `:root { --theme-color-*: oklch(...); }` |
| `--color-error`, `--color-success`, `--color-warning`, `--color-overlay` | CSS Custom Properties in `@theme` | Slice 2 (shadcn), Slice 3 (Migration) | Tailwind-Utility-Klassen: `bg-error`, `text-success`, `bg-warning`, `bg-overlay` |
| `--radius-card`, `--radius-button` | CSS Custom Properties in `@theme` | Slice 2 (shadcn Card/Button) | `var(--radius-card)`, `var(--radius-button)` |
| `--shadow-card`, `--shadow-card-hover` | CSS Custom Properties in `@theme` | Slice 2 (shadcn Card) | `var(--shadow-card)`, `var(--shadow-card-hover)` |
| `cn()` | Function in `lib/utils.ts` | Slice 2 (alle shadcn Komponenten) | `(...inputs: ClassValue[]) => string` |
| `themes/default/theme.yaml` | YAML Config File | Slice 5 (Multi-Shop Deep Merge) | Schema: `{ colors, fonts, radius, shadows }` |
| `scripts/generate-theme.mjs` | Build Script | Slice 5 (Shop-Theme Override) | Liest `NEXT_PUBLIC_THEME`, führt Deep Merge aus |

### Integration Validation Tasks

- [ ] `app/generated-theme.css` wird generiert bevor `pnpm build` startet (via `prebuild` Script)
- [ ] Alle neuen `--color-*`, `--radius-*`, `--shadow-*` Tokens sind im `@theme` Block registriert und als Tailwind-Klassen verfügbar
- [ ] `cn()` aus `lib/utils.ts` ist importierbar und liefert korrekten Output
- [ ] `themes/default/theme.yaml` hat das vollständige Schema für spätere Shop-Theme-Vererbung

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele sind PFLICHT-Deliverables. Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `themes/default/theme.yaml` | Abschnitt 3 | YES | Alle 12 Farb-Tokens + fonts + radius + shadows |
| `scripts/generate-theme.mjs` | Abschnitt 4 | YES | Validierung + CSS-Generierung + Exit Code 1 bei Fehler |
| `app/globals.css` (neuer @theme Block) | Abschnitt 5 | YES | Import generated-theme.css + alle neuen Tokens registrieren + keine [data-theme] Blöcke |
| `app/generated-theme.css` (Format) | Abschnitt 6 | YES | :root Block mit --theme-* Präfix |
| `lib/utils.ts` (cn Utility) | Abschnitt 7 | YES | clsx + tailwind-merge |
| `package.json` (scripts + deps) | Abschnitt 8 | YES | prebuild + predev Scripts + yaml/clsx/tailwind-merge dependencies |

---

## Constraints & Hinweise

**Betrifft:**
- `frontend/app/globals.css` — bestehende Datei wird modifiziert
- `frontend/package.json` — neue Dependencies + Scripts
- `.gitignore` (Root) — `frontend/app/generated-theme.css` muss gitignored werden

**API Contract:**
- `generated-theme.css` verwendet `--theme-*` Präfix (nicht direkt `--color-*`) um Konflikte mit Tailwind-internen CSS-Variablen zu vermeiden
- `@theme` Block in `globals.css` mappt `--theme-*` → `--color-*` / `--radius-*` / `--shadow-*` für Tailwind-Utility-Klassen
- Build-Script muss als **ES-Modul** (`generate-theme.mjs`) geschrieben sein (kein CommonJS) weil `yaml` nur ESM-Exports hat

**Abgrenzung:**
- Dieser Slice entfernt Runtime ThemeProvider/ThemeSwitcher NICHT aktiv — die `[data-theme]` CSS-Blöcke werden aus `globals.css` entfernt, aber `components/layout/theme-switcher.tsx` und `contexts/` werden erst in Slice 3 (Component Migration) bereinigt
- Font-Loading via `next/font` ist OUT of Scope für diesen Slice — wird in Slice 2 (shadcn/ui) eingeführt. Slice 1 definiert nur die `fonts.*` Tokens im YAML.
- Deep Merge Logik (Shop überschreibt Default) ist OUT of Scope für diesen Slice — wird in Slice 5 implementiert. Das Build-Script liest in Slice 1 nur `themes/default/theme.yaml`.

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig und vollständig
- [ ] Build-Script erzeugt valides CSS (manuell verifizierbar mit `node scripts/generate-theme.mjs`)
- [ ] `pnpm build` schlägt fehl wenn `theme.yaml` invalid ist
- [ ] Keine `[data-theme]` Blöcke mehr in `globals.css`
- [ ] Alle neuen Tokens als Tailwind-Utility-Klassen verfügbar (DevTools Verifizierung)
- [ ] `cn()` aus `lib/utils.ts` importierbar

---

## Skill Verification

### React Best Practices Verification

**Critical Priority:**
- [x] `async-parallel`: Nicht relevant — kein React-Code in diesem Slice
- [x] `bundle-dynamic-imports`: Nicht relevant — kein Component-Code in diesem Slice

**High Priority:**
- [x] `server-cache-react`: Nicht relevant — Build-Script ist Node.js, kein RSC

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [x] Keine hardcoded Werte — alle Farben in OKLch via YAML-Tokens
- [x] `@theme` Tokens für Custom-Designs — alle neuen Tokens registriert
- [x] Semantic color naming — `error`, `success`, `warning`, `overlay` semantisch benannt

**Build Tool Integration:**
- [x] `@tailwindcss/postcss` bereits in devDependencies — kein Plugin-Wechsel nötig
- [x] `globals.css` importiert `@import "tailwindcss"` — bestehendes Pattern beibehalten

---

## Links

- Design/Spec: `specs/phase-0/2026-02-21-frontend-theming/architecture.md`
- Discovery: `specs/phase-0/2026-02-21-frontend-theming/discovery.md`
- Bestehende globals.css: `frontend/app/globals.css`
- Tailwind v4 CSS-First Docs: https://tailwindcss.com/docs/v4-beta

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert.**

<!-- DELIVERABLES_START -->
### Build Infrastructure
- [ ] `frontend/scripts/generate-theme.mjs` — Build-Script: liest NEXT_PUBLIC_THEME, parst YAML, validiert OKLch-Format + Font-Namen, generiert CSS, Exit Code 1 bei Fehler
- [ ] `frontend/themes/default/theme.yaml` — Default-Theme-Konfiguration mit 12 Farb-Tokens (inkl. error, success, warning, overlay), fonts, radius, shadows

### Frontend
- [ ] `frontend/app/globals.css` — MODIFIZIERT: `@import "./generated-theme.css"` hinzugefügt, `[data-theme]` Blöcke entfernt, `@theme` Block um error/success/warning/overlay/radius-card/radius-button/shadow-card/shadow-card-hover Tokens erweitert
- [ ] `frontend/lib/utils.ts` — NEU: `cn()` Utility (clsx + tailwind-merge)
- [ ] `frontend/package.json` — MODIFIZIERT: `yaml`, `clsx`, `tailwind-merge` als Dependencies; `prebuild` und `predev` Scripts

### Config
- [ ] Root `.gitignore` — `frontend/app/generated-theme.css` gitignored

### Tests
- [ ] `frontend/tests/slices/frontend-theming/slice-01-theme-token-system.test.ts` — Unit Tests für Build-Script (Validierung, CSS-Output, Fallback), cn() Utility Tests, globals.css Token-Registration Tests, theme.yaml Schema-Tests
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind Pflicht
- `app/generated-theme.css` ist KEIN Deliverable — wird automatisch generiert und ist gitignored
- Der `prebuild` Script sorgt dafür, dass `generated-theme.css` vor jedem `pnpm build` existiert
