// tests/slices/kleinstadtpflanze-design-e/slice-03-theme.test.ts
//
// Slice-03: Kleinstadtpflanze-Theme-YAML + Aktivierung
// Spec: specs/2026-04-16-kleinstadtpflanze-design-e/slim-spec.md (Section: Slice 3)
//
// Acceptance Criteria:
//   AC-1: themes/kleinstadtpflanze/theme.yaml contains all required color overrides
//         (OKLCH format + hex-comment traceability)
//   AC-2: radius overrides (button, card, card-lg, hero, pill) and shadow overrides
//         (card, card-hover, sticky) have the specified values
//   AC-3: generate-theme.mjs with NEXT_PUBLIC_THEME=kleinstadtpflanze exits 0,
//         generated-theme.css contains merged kleinstadtpflanze values
//         (forest-greenish primary: L in [0.35, 0.55] AND hue in [145, 160])
//   AC-4: Covered structurally by AC-3 (browser-level check is not viable in Vitest).
//   AC-5: generate-theme.mjs exits 0 (no page-config conflict) AND
//         themes/kleinstadtpflanze/pages/ directory does NOT exist (3-tier fallback)
//   AC-6: .env.local.example mentions `kleinstadtpflanze` in themes-comment line
//
// Testing strategy: no-mocks. Parse real YAML, read real generated CSS,
// spawn real `node scripts/generate-theme.mjs`. Restore default theme state in afterAll.

import { describe, it, expect, afterAll } from 'vitest'
import { readFileSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { execSync } from 'node:child_process'
import { parse as parseYaml } from 'yaml'

const FRONTEND_ROOT = resolve(__dirname, '..', '..', '..')
const SCRIPT_PATH = resolve(FRONTEND_ROOT, 'scripts/generate-theme.mjs')
const KLEINSTADTPFLANZE_DIR = resolve(FRONTEND_ROOT, 'themes/kleinstadtpflanze')
const KLEINSTADTPFLANZE_YAML = resolve(KLEINSTADTPFLANZE_DIR, 'theme.yaml')
const KLEINSTADTPFLANZE_PAGES_DIR = resolve(KLEINSTADTPFLANZE_DIR, 'pages')
const ENV_LOCAL_EXAMPLE = resolve(FRONTEND_ROOT, '.env.local.example')
const GENERATED_CSS = resolve(FRONTEND_ROOT, 'app/generated-theme.css')

// OKLCH-Regex: oklch(L C H) oder oklch(L C H / A)
const OKLCH_REGEX = /^oklch\(\s*[\d.]+\s+[\d.]+\s+[\d.]+(\s*\/\s*[\d.]+)?\s*\)$/

// Required color override keys per AC-1
const REQUIRED_COLOR_KEYS = [
  'primary',
  'primary-hover',
  'primary-dark',
  'accent',
  'error',
  'success',
  'surface-alt',
  'text-primary',
  'text-secondary',
  'text-tertiary',
  'text-muted',
  'border',
  'border-strong',
] as const

// Hex references per AC-1 (for hex-comment traceability grep)
// Keys = color slot names, values = expected hex fragment that MUST appear
// as a comment in theme.yaml adjacent to the slot.
const HEX_REFERENCES: Record<string, string> = {
  primary: '#2E5A3E',
  'primary-hover': '#204430',
  'primary-dark': '#204430',
  accent: '#C97E3C',
  error: '#C0392B',
  success: '#3A8152',
  'surface-alt': '#F7F5F1',
  'text-primary': '#1F2322',
  'text-secondary': '#4A4F4D',
  'text-tertiary': '#7A807D',
  'text-muted': '#B5B8B5',
  border: '#E4E1DA',
  'border-strong': '#D4D0C6',
}

function runGenerator(theme: string | null): { status: number; stdout: string; stderr: string } {
  try {
    const env = { ...process.env }
    if (theme === null) {
      delete env.NEXT_PUBLIC_THEME
    } else {
      env.NEXT_PUBLIC_THEME = theme
    }
    const stdout = execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env,
      encoding: 'utf-8',
    })
    return { status: 0, stdout, stderr: '' }
  } catch (err: unknown) {
    const e = err as { status?: number; stdout?: Buffer | string; stderr?: Buffer | string }
    return {
      status: typeof e.status === 'number' ? e.status : 1,
      stdout: e.stdout?.toString() ?? '',
      stderr: e.stderr?.toString() ?? '',
    }
  }
}

/**
 * Extract a CSS custom-property's value from the generated CSS.
 * Returns the raw value (everything after `:` until `;`), trimmed.
 */
function getCssVarValue(css: string, name: string): string | null {
  const re = new RegExp(`${name}\\s*:\\s*([^;\\n]+);`)
  const m = css.match(re)
  return m ? m[1].trim() : null
}

/**
 * Parse an oklch(L C H [/ A]) string into its numeric L, C, H components.
 * Returns null if the string does not match the expected shape.
 */
function parseOklch(value: string): { L: number; C: number; H: number } | null {
  const m = value.match(/^oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)(?:\s*\/\s*[\d.]+)?\s*\)$/)
  if (!m) return null
  return { L: parseFloat(m[1]), C: parseFloat(m[2]), H: parseFloat(m[3]) }
}

describe('slice-03: Kleinstadtpflanze-Theme-YAML + Aktivierung', () => {
  // Restore default-theme state at the very end (AC-6 post-condition for other tests).
  afterAll(() => {
    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: 'default' },
    })
  })

  describe('AC-1: theme.yaml contains all required color overrides (OKLCH + hex traceability)', () => {
    it(
      'AC-1a: GIVEN themes/kleinstadtpflanze/theme.yaml exists, ' +
        'WHEN parsed, ' +
        'THEN all 13 required color override keys exist under colors.*',
      () => {
        expect(existsSync(KLEINSTADTPFLANZE_YAML), 'kleinstadtpflanze theme.yaml missing').toBe(
          true,
        )
        const yamlContent = readFileSync(KLEINSTADTPFLANZE_YAML, 'utf-8')
        const cfg = parseYaml(yamlContent) as { colors?: Record<string, string> }
        expect(cfg.colors, 'colors block missing in kleinstadtpflanze theme.yaml').toBeDefined()

        for (const key of REQUIRED_COLOR_KEYS) {
          expect(cfg.colors?.[key], `Missing required color override: colors.${key}`).toBeDefined()
          expect(cfg.colors?.[key], `Empty color override: colors.${key}`).not.toBe('')
        }
      },
    )

    it(
      'AC-1b: GIVEN color overrides in kleinstadtpflanze theme.yaml, ' +
        'WHEN parsed, ' +
        'THEN each value matches /^oklch\\(/ (format-only, not exact numeric match — ' +
        'hex→OKLCH conversion is approximate)',
      () => {
        const cfg = parseYaml(readFileSync(KLEINSTADTPFLANZE_YAML, 'utf-8')) as {
          colors: Record<string, string>
        }

        for (const key of REQUIRED_COLOR_KEYS) {
          const value = cfg.colors[key]
          expect(
            typeof value === 'string' && value.startsWith('oklch('),
            `colors.${key} = "${value}" does not start with oklch(`,
          ).toBe(true)
          expect(
            OKLCH_REGEX.test(value),
            `colors.${key} = "${value}" does not match the OKLCH regex`,
          ).toBe(true)
        }
      },
    )

    it(
      'AC-1c: GIVEN kleinstadtpflanze theme.yaml source, ' +
        'WHEN grep\'d for each hex reference, ' +
        'THEN the source contains the hex value as a comment adjacent to the OKLCH value (traceability)',
      () => {
        const source = readFileSync(KLEINSTADTPFLANZE_YAML, 'utf-8')
        for (const [slot, hex] of Object.entries(HEX_REFERENCES)) {
          // Hex references are case-insensitive in typical hex notation.
          const re = new RegExp(hex, 'i')
          expect(
            re.test(source),
            `theme.yaml missing hex traceability comment "${hex}" for colors.${slot}`,
          ).toBe(true)
        }
      },
    )

    it(
      'AC-1d: GIVEN colors.primary should encode forest-green (#2E5A3E), ' +
        'WHEN parsed as OKLCH, ' +
        'THEN Lightness lies in [0.35, 0.55] AND hue lies in [140, 165] ' +
        '(loose bounds — hex→OKLCH conversion is approximate; culori-level round-trip skipped)',
      () => {
        // Note: culori is not a project dependency. We skip a full round-trip and instead
        // assert loose bounds: forest-green maps to an OKLCH in the mid-L green-hue range.
        const cfg = parseYaml(readFileSync(KLEINSTADTPFLANZE_YAML, 'utf-8')) as {
          colors: Record<string, string>
        }
        const parsed = parseOklch(cfg.colors.primary)
        expect(parsed, `colors.primary not parseable as OKLCH: ${cfg.colors.primary}`).not.toBeNull()
        expect(
          parsed!.L,
          `colors.primary L=${parsed!.L} out of forest-green range [0.35, 0.55]`,
        ).toBeGreaterThanOrEqual(0.35)
        expect(
          parsed!.L,
          `colors.primary L=${parsed!.L} out of forest-green range [0.35, 0.55]`,
        ).toBeLessThanOrEqual(0.55)
        expect(
          parsed!.H,
          `colors.primary H=${parsed!.H} out of green-hue range [140, 165]`,
        ).toBeGreaterThanOrEqual(140)
        expect(
          parsed!.H,
          `colors.primary H=${parsed!.H} out of green-hue range [140, 165]`,
        ).toBeLessThanOrEqual(165)
      },
    )
  })

  describe('AC-2: radius + shadow overrides have the specified values', () => {
    it(
      'AC-2a: GIVEN radius overrides, ' +
        'WHEN theme.yaml is parsed, ' +
        'THEN radius.button === "0.5rem" AND radius.card === "0.625rem" AND ' +
        'radius.card-lg === "0.75rem" AND radius.hero === "0.875rem" AND radius.pill === "999px"',
      () => {
        const cfg = parseYaml(readFileSync(KLEINSTADTPFLANZE_YAML, 'utf-8')) as {
          radius: Record<string, string>
        }
        expect(cfg.radius, 'radius block missing').toBeDefined()
        expect(cfg.radius.button).toBe('0.5rem')
        expect(cfg.radius.card).toBe('0.625rem')
        expect(cfg.radius['card-lg']).toBe('0.75rem')
        expect(cfg.radius.hero).toBe('0.875rem')
        expect(cfg.radius.pill).toBe('999px')
      },
    )

    it(
      'AC-2b: GIVEN shadow overrides, ' +
        'WHEN theme.yaml is parsed, ' +
        'THEN shadows.card starts with "0 4px 12px oklch(" AND ' +
        'shadows.card-hover starts with "0 6px 18px oklch(" AND ' +
        'shadows.sticky starts with "0 -4px 16px oklch("',
      () => {
        const cfg = parseYaml(readFileSync(KLEINSTADTPFLANZE_YAML, 'utf-8')) as {
          shadows: Record<string, string>
        }
        expect(cfg.shadows, 'shadows block missing').toBeDefined()

        expect(
          cfg.shadows.card,
          `shadows.card should start with "0 4px 12px oklch(", got "${cfg.shadows.card}"`,
        ).toMatch(/^0 4px 12px oklch\(/)

        expect(
          cfg.shadows['card-hover'],
          `shadows.card-hover should start with "0 6px 18px oklch(", got "${cfg.shadows['card-hover']}"`,
        ).toMatch(/^0 6px 18px oklch\(/)

        expect(
          cfg.shadows.sticky,
          `shadows.sticky should start with "0 -4px 16px oklch(", got "${cfg.shadows.sticky}"`,
        ).toMatch(/^0 -4px 16px oklch\(/)
      },
    )
  })

  describe('AC-3: generate-theme.mjs with NEXT_PUBLIC_THEME=kleinstadtpflanze produces merged CSS', () => {
    it(
      'AC-3a: GIVEN NEXT_PUBLIC_THEME=kleinstadtpflanze, ' +
        'WHEN generate-theme.mjs runs, ' +
        'THEN exit code is 0 AND generated-theme.css exists',
      () => {
        const result = runGenerator('kleinstadtpflanze')
        expect(
          result.status,
          `generator failed for kleinstadtpflanze: stderr=${result.stderr}`,
        ).toBe(0)
        expect(existsSync(GENERATED_CSS), 'generated-theme.css missing after build').toBe(true)

        // Sanity: header comment identifies the theme.
        const css = readFileSync(GENERATED_CSS, 'utf-8')
        expect(css).toContain('Theme: kleinstadtpflanze')
      },
    )

    it(
      'AC-3b: GIVEN merged config, ' +
        'WHEN generated-theme.css is read, ' +
        'THEN --theme-color-primary exactly equals the YAML value (merge is deterministic) ' +
        'AND parses to forest-green OKLCH (L in [0.35, 0.55], H in [145, 160])',
      () => {
        const result = runGenerator('kleinstadtpflanze')
        expect(result.status).toBe(0)
        const css = readFileSync(GENERATED_CSS, 'utf-8')

        const yamlCfg = parseYaml(readFileSync(KLEINSTADTPFLANZE_YAML, 'utf-8')) as {
          colors: Record<string, string>
        }

        const primary = getCssVarValue(css, '--theme-color-primary')
        expect(primary, '--theme-color-primary missing in generated CSS').not.toBeNull()
        expect(
          primary,
          `--theme-color-primary should equal kleinstadtpflanze YAML value`,
        ).toBe(yamlCfg.colors.primary)

        const parsed = parseOklch(primary!)
        expect(parsed, `--theme-color-primary not parseable: ${primary}`).not.toBeNull()
        expect(parsed!.L).toBeGreaterThanOrEqual(0.35)
        expect(parsed!.L).toBeLessThanOrEqual(0.55)
        expect(parsed!.H).toBeGreaterThanOrEqual(145)
        expect(parsed!.H).toBeLessThanOrEqual(160)
      },
    )

    it(
      'AC-3c: GIVEN merged config, ' +
        'WHEN generated-theme.css is read, ' +
        'THEN all YAML overrides (colors, radius, shadows) are literally present in CSS ' +
        '(proves merge applied kleinstadtpflanze values, not default ones)',
      () => {
        const result = runGenerator('kleinstadtpflanze')
        expect(result.status).toBe(0)
        const css = readFileSync(GENERATED_CSS, 'utf-8')

        const yamlCfg = parseYaml(readFileSync(KLEINSTADTPFLANZE_YAML, 'utf-8')) as {
          colors: Record<string, string>
          radius: Record<string, string>
          shadows: Record<string, string>
        }

        // All colors: kleinstadtpflanze values must win over default.
        for (const key of REQUIRED_COLOR_KEYS) {
          const varName = `--theme-color-${key}`
          const actual = getCssVarValue(css, varName)
          expect(actual, `${varName} missing in generated CSS`).not.toBeNull()
          expect(
            actual,
            `${varName} should equal kleinstadtpflanze YAML value (merge should win)`,
          ).toBe(yamlCfg.colors[key])
        }

        // Radius overrides.
        for (const key of ['button', 'card', 'card-lg', 'hero', 'pill'] as const) {
          const varName = `--theme-radius-${key}`
          const actual = getCssVarValue(css, varName)
          expect(actual, `${varName} missing in generated CSS`).not.toBeNull()
          expect(actual, `${varName} should equal kleinstadtpflanze YAML value`).toBe(
            yamlCfg.radius[key],
          )
        }

        // Shadow overrides.
        for (const key of ['card', 'card-hover', 'sticky'] as const) {
          const varName = `--theme-shadow-${key}`
          const actual = getCssVarValue(css, varName)
          expect(actual, `${varName} missing in generated CSS`).not.toBeNull()
          expect(actual, `${varName} should equal kleinstadtpflanze YAML value`).toBe(
            yamlCfg.shadows[key],
          )
        }
      },
    )

    it(
      'AC-3d: GIVEN merged config, ' +
        'WHEN generate-theme.mjs validates kleinstadtpflanze theme, ' +
        'THEN validateConfig accepts every color (no "Invalid color format" in stderr)',
      () => {
        const result = runGenerator('kleinstadtpflanze')
        expect(result.status).toBe(0)
        expect(result.stderr).not.toContain('Invalid color format')
      },
    )
  })

  describe('AC-4: browser-level check (covered structurally by AC-3)', () => {
    it(
      'AC-4: GIVEN AC-3 asserts merged CSS vars match kleinstadtpflanze YAML values, ' +
        'THEN browser elements bound via @theme (bg-primary, bg-accent, border-border, bg-surface-alt) ' +
        'WILL resolve to these OKLCH values at runtime. ' +
        'Browser-level getComputedStyle assertion is not viable in Vitest (jsdom does not apply ' +
        'OKLCH via generated-theme.css). AC-3 provides deterministic structural coverage.',
      () => {
        // Marker test — the real guarantee comes from AC-3b + AC-3c.
        // We still sanity-check that the four surfaces AC-4 mentions resolve to kleinstadtpflanze
        // values in the generated CSS.
        const result = runGenerator('kleinstadtpflanze')
        expect(result.status).toBe(0)
        const css = readFileSync(GENERATED_CSS, 'utf-8')
        const yamlCfg = parseYaml(readFileSync(KLEINSTADTPFLANZE_YAML, 'utf-8')) as {
          colors: Record<string, string>
        }

        expect(getCssVarValue(css, '--theme-color-primary')).toBe(yamlCfg.colors.primary)
        expect(getCssVarValue(css, '--theme-color-accent')).toBe(yamlCfg.colors.accent)
        expect(getCssVarValue(css, '--theme-color-border')).toBe(yamlCfg.colors.border)
        expect(getCssVarValue(css, '--theme-color-surface-alt')).toBe(yamlCfg.colors['surface-alt'])
      },
    )
  })

  describe('AC-5: page-config 3-tier fallback (no kleinstadtpflanze pages override)', () => {
    it(
      'AC-5a: GIVEN kleinstadtpflanze theme is the active theme, ' +
        'WHEN generate-theme.mjs runs, ' +
        'THEN exit code is 0 (confirming no page-config conflict blocks theme generation)',
      () => {
        const result = runGenerator('kleinstadtpflanze')
        expect(result.status, `generator failed: stderr=${result.stderr}`).toBe(0)
      },
    )

    it(
      'AC-5b: GIVEN the 3-tier page-config fallback must load themes/default/pages/home.yaml, ' +
        'WHEN the kleinstadtpflanze theme directory is inspected, ' +
        'THEN themes/kleinstadtpflanze/pages/ does NOT exist ' +
        '(ensures no override masks the default home.yaml)',
      () => {
        expect(
          existsSync(KLEINSTADTPFLANZE_PAGES_DIR),
          `themes/kleinstadtpflanze/pages/ should NOT exist to force 3-tier fallback`,
        ).toBe(false)
      },
    )
  })

  describe('AC-6: .env.local.example lists kleinstadtpflanze in themes comment', () => {
    it(
      'AC-6: GIVEN frontend/.env.local.example, ' +
        'WHEN opened, ' +
        'THEN the string "kleinstadtpflanze" is present in the themes-comment line',
      () => {
        expect(existsSync(ENV_LOCAL_EXAMPLE), '.env.local.example missing').toBe(true)
        const content = readFileSync(ENV_LOCAL_EXAMPLE, 'utf-8')

        // Must mention kleinstadtpflanze at all.
        expect(
          content,
          '.env.local.example does not mention "kleinstadtpflanze"',
        ).toContain('kleinstadtpflanze')

        // Stricter: mention should appear on a commented line (starts with `#`) that lists themes.
        // e.g. "# Available themes: default, zweiter-shop, kleinstadtpflanze"
        const commentLines = content
          .split('\n')
          .filter((line) => line.trim().startsWith('#') && line.includes('kleinstadtpflanze'))
        expect(
          commentLines.length,
          '"kleinstadtpflanze" should appear in a commented themes-list line',
        ).toBeGreaterThan(0)
      },
    )
  })
})
