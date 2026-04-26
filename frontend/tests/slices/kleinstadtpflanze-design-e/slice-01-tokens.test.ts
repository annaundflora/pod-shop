// tests/slices/kleinstadtpflanze-design-e/slice-01-tokens.test.ts
//
// Slice-01: Token-System im Default-Theme + @theme-Block erweitern
// Spec: specs/2026-04-16-kleinstadtpflanze-design-e/slim-spec.md (Section: Slice 1)
//
// Acceptance Criteria:
//   AC-1: generate-theme.mjs emits all new --theme-* CSS variables in :root
//   AC-2: all color slots in themes/default/theme.yaml are valid OKLCH
//   AC-3: globals.css @theme block maps the 9 new Tailwind tokens to var(--theme-*)
//   AC-4: visual parity preserved — default radius.pill === "999px", colors unchanged
//   AC-5: zweiter-shop (no overrides) deep-merges default values for new slots
//
// Testing strategy: no-mocks. Parse real YAML files, read real generated CSS,
// spawn real `node scripts/generate-theme.mjs`. Restore generated CSS in afterAll.

import { describe, it, expect, afterAll } from 'vitest'
import { readFileSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { execSync } from 'node:child_process'
import { parse as parseYaml } from 'yaml'

const FRONTEND_ROOT = resolve(__dirname, '..', '..', '..')
const SCRIPT_PATH = resolve(FRONTEND_ROOT, 'scripts/generate-theme.mjs')
const DEFAULT_THEME_YAML = resolve(FRONTEND_ROOT, 'themes/default/theme.yaml')
const ZWEITER_SHOP_YAML = resolve(FRONTEND_ROOT, 'themes/zweiter-shop/theme.yaml')
const GENERATED_CSS = resolve(FRONTEND_ROOT, 'app/generated-theme.css')
const GLOBALS_CSS = resolve(FRONTEND_ROOT, 'app/globals.css')

// OKLCH-Regex: oklch(L C H)  oder  oklch(L C H / A)
const OKLCH_REGEX = /^oklch\(\s*[\d.]+\s+[\d.]+\s+[\d.]+(\s*\/\s*[\d.]+)?\s*\)$/

// Neue Slots laut Spec (Slice 1, Goal & AC-1)
const NEW_COLOR_SLOTS = [
  'text-tertiary',
  'text-muted',
  'border-strong',
  'surface-alt',
  'primary-dark',
]
const NEW_RADIUS_SLOTS = ['card-lg', 'hero', 'pill']
const NEW_SHADOW_SLOTS = ['sticky']

const REQUIRED_THEME_VARS = [
  '--theme-color-text-tertiary',
  '--theme-color-text-muted',
  '--theme-color-border-strong',
  '--theme-color-surface-alt',
  '--theme-color-primary-dark',
  '--theme-radius-card-lg',
  '--theme-radius-hero',
  '--theme-radius-pill',
  '--theme-shadow-sticky',
]

// @theme-Block-Mappings laut AC-3: Tailwind-Utility-Tokens → var(--theme-*)
const THEME_BLOCK_MAPPINGS: Array<{ token: string; source: string }> = [
  { token: '--color-text-tertiary', source: 'var(--theme-color-text-tertiary)' },
  { token: '--color-text-muted', source: 'var(--theme-color-text-muted)' },
  { token: '--color-border-strong', source: 'var(--theme-color-border-strong)' },
  { token: '--color-surface-alt', source: 'var(--theme-color-surface-alt)' },
  { token: '--color-primary-dark', source: 'var(--theme-color-primary-dark)' },
  { token: '--radius-card-lg', source: 'var(--theme-radius-card-lg)' },
  { token: '--radius-hero', source: 'var(--theme-radius-hero)' },
  { token: '--radius-pill', source: 'var(--theme-radius-pill)' },
  { token: '--shadow-sticky', source: 'var(--theme-shadow-sticky)' },
]

function runGenerator(theme: string): { status: number; stdout: string; stderr: string } {
  try {
    const stdout = execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: theme },
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
 * Extract a CSS custom-property's value from a `:root { ... }` block.
 * Returns the raw value (everything after `:` until `;`), trimmed.
 */
function getCssVarValue(css: string, name: string): string | null {
  const re = new RegExp(`${name}\\s*:\\s*([^;\\n]+);`)
  const m = css.match(re)
  return m ? m[1].trim() : null
}

describe('slice-01: Token-System im Default-Theme + @theme-Block erweitern', () => {
  // Restore a canonical generated-theme.css after the suite so dev server / other tests
  // see the default theme.
  afterAll(() => {
    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: 'default' },
    })
  })

  describe('AC-1: generate-theme.mjs emits all new --theme-* variables in :root', () => {
    it(
      'AC-1: GIVEN default theme.yaml with new slots, WHEN generate-theme.mjs runs, ' +
        'THEN exit code is 0 AND generated-theme.css contains all 9 new --theme-* variables in :root',
      () => {
        const result = runGenerator('default')
        expect(result.status, `generator failed: ${result.stderr}`).toBe(0)
        expect(existsSync(GENERATED_CSS)).toBe(true)

        const css = readFileSync(GENERATED_CSS, 'utf-8')
        expect(css).toContain(':root {')

        // Every required variable must be present inside the :root block.
        const rootMatch = css.match(/:root\s*\{([\s\S]*?)\}/)
        expect(rootMatch, ':root block missing in generated-theme.css').not.toBeNull()
        const rootBody = rootMatch![1]

        for (const varName of REQUIRED_THEME_VARS) {
          expect(
            rootBody,
            `Missing ${varName} in :root block of generated-theme.css`,
          ).toContain(varName)
        }
      },
    )
  })

  describe('AC-2: all color slots in themes/default/theme.yaml are valid OKLCH', () => {
    it(
      'AC-2: GIVEN themes/default/theme.yaml, WHEN parsed, ' +
        'THEN every colors.* value matches the OKLCH regex (oklch(L C H) or oklch(L C H / A))',
      () => {
        const yamlContent = readFileSync(DEFAULT_THEME_YAML, 'utf-8')
        const cfg = parseYaml(yamlContent) as { colors: Record<string, string> }
        expect(cfg.colors, 'colors block missing in default theme.yaml').toBeDefined()

        // All new color slots must exist.
        for (const slot of NEW_COLOR_SLOTS) {
          expect(cfg.colors[slot], `Missing new color slot: ${slot}`).toBeDefined()
          expect(cfg.colors[slot], `Empty color slot: ${slot}`).not.toBe('')
        }

        // Every color value (new and existing) must match OKLCH format.
        for (const [key, value] of Object.entries(cfg.colors)) {
          expect(
            OKLCH_REGEX.test(value),
            `Color "${key}" = "${value}" is not in OKLCH format`,
          ).toBe(true)
        }
      },
    )

    it(
      'AC-2 (runtime): WHEN generate-theme.mjs validates default theme, ' +
        'THEN no color is rejected (exit code 0, no "Invalid color format" in stderr)',
      () => {
        const result = runGenerator('default')
        expect(result.status).toBe(0)
        expect(result.stderr).not.toContain('Invalid color format')
      },
    )
  })

  describe('AC-3: globals.css @theme block maps 9 new Tailwind tokens to var(--theme-*)', () => {
    it(
      'AC-3: GIVEN globals.css, WHEN parsed, ' +
        'THEN the @theme { ... } block contains the 9 new token→var mappings',
      () => {
        const css = readFileSync(GLOBALS_CSS, 'utf-8')

        // Locate the (first) @theme block. globals.css uses only one @theme block.
        const themeBlockMatch = css.match(/@theme\s*\{([\s\S]*?)\n\}/)
        expect(themeBlockMatch, '@theme block missing in globals.css').not.toBeNull()
        const themeBody = themeBlockMatch![1]

        for (const { token, source } of THEME_BLOCK_MAPPINGS) {
          // Assert the mapping line (token : source) is literally present in the @theme block.
          const mappingRegex = new RegExp(
            `${token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*:\\s*${source.replace(
              /[.*+?^${}()|[\]\\]/g,
              '\\$&',
            )}\\s*;`,
          )
          expect(
            mappingRegex.test(themeBody),
            `@theme block missing mapping: ${token}: ${source};`,
          ).toBe(true)
        }
      },
    )
  })

  describe('AC-4: default-theme preserves visual parity', () => {
    it(
      'AC-4a: GIVEN default theme, WHEN generated-theme.css is inspected, ' +
        'THEN --theme-radius-pill is literally "999px"',
      () => {
        runGenerator('default')
        const css = readFileSync(GENERATED_CSS, 'utf-8')
        expect(css).toContain('--theme-radius-pill: 999px;')
      },
    )

    it(
      'AC-4b: GIVEN default theme, WHEN generated-theme.css is inspected, ' +
        'THEN legacy tokens (primary, border, surface, radius.card/button, card/card-hover shadows) ' +
        'still have their known-good default values (no visual regression)',
      () => {
        runGenerator('default')
        const css = readFileSync(GENERATED_CSS, 'utf-8')

        // Known-good snapshot values from pre-slice state.
        const expected: Record<string, string> = {
          '--theme-color-primary': 'oklch(0.45 0.18 150)',
          '--theme-color-primary-hover': 'oklch(0.38 0.2 270)',
          '--theme-color-accent': 'oklch(0.65 0.15 270)',
          '--theme-color-surface': 'oklch(1 0 0)',
          '--theme-color-surface-elevated': 'oklch(0.98 0 0)',
          '--theme-color-text-primary': 'oklch(0.15 0 0)',
          '--theme-color-text-secondary': 'oklch(0.45 0 0)',
          '--theme-color-border': 'oklch(0.88 0 0)',
          '--theme-radius-card': '0.75rem',
          '--theme-radius-button': '0.5rem',
          '--theme-shadow-card': '0 1px 3px oklch(0 0 0 / 0.08)',
          '--theme-shadow-card-hover': '0 8px 25px oklch(0 0 0 / 0.12)',
        }

        for (const [name, value] of Object.entries(expected)) {
          const actual = getCssVarValue(css, name)
          expect(actual, `CSS var ${name} missing in generated-theme.css`).not.toBeNull()
          expect(
            actual,
            `CSS var ${name} changed (visual regression): expected "${value}", got "${actual}"`,
          ).toBe(value)
        }
      },
    )

    it(
      'AC-4c: GIVEN default theme.yaml, WHEN parsed, ' +
        'THEN radius.pill === "999px" at YAML level (source of truth for pipeline)',
      () => {
        const cfg = parseYaml(readFileSync(DEFAULT_THEME_YAML, 'utf-8')) as {
          radius: Record<string, string>
        }
        expect(cfg.radius?.pill).toBe('999px')
      },
    )
  })

  describe('AC-5: zweiter-shop (no overrides for new slots) deep-merges default values', () => {
    it(
      'AC-5a: GIVEN themes/zweiter-shop/theme.yaml does NOT override the new slots, ' +
        'WHEN parsed, ' +
        'THEN none of the 9 new slots are present in the shop theme (deep-merge is required)',
      () => {
        expect(existsSync(ZWEITER_SHOP_YAML), 'zweiter-shop theme.yaml missing').toBe(true)
        const shopCfg = parseYaml(readFileSync(ZWEITER_SHOP_YAML, 'utf-8')) as {
          colors?: Record<string, string>
          radius?: Record<string, string>
          shadows?: Record<string, string>
        }

        for (const slot of NEW_COLOR_SLOTS) {
          expect(
            shopCfg.colors?.[slot],
            `zweiter-shop unexpectedly overrides color.${slot} — test premise broken`,
          ).toBeUndefined()
        }
        for (const slot of NEW_RADIUS_SLOTS) {
          expect(
            shopCfg.radius?.[slot],
            `zweiter-shop unexpectedly overrides radius.${slot} — test premise broken`,
          ).toBeUndefined()
        }
        for (const slot of NEW_SHADOW_SLOTS) {
          expect(
            shopCfg.shadows?.[slot],
            `zweiter-shop unexpectedly overrides shadows.${slot} — test premise broken`,
          ).toBeUndefined()
        }
      },
    )

    it(
      'AC-5b: GIVEN NEXT_PUBLIC_THEME=zweiter-shop (no overrides for new slots), ' +
        'WHEN generate-theme.mjs runs, ' +
        'THEN exit code is 0 AND generated-theme.css still contains the 9 new --theme-* vars ' +
        'falling back to default values (deep-merge)',
      () => {
        const defaultCfg = parseYaml(readFileSync(DEFAULT_THEME_YAML, 'utf-8')) as {
          colors: Record<string, string>
          radius: Record<string, string>
          shadows: Record<string, string>
        }

        const result = runGenerator('zweiter-shop')
        expect(result.status, `zweiter-shop build failed: ${result.stderr}`).toBe(0)
        expect(existsSync(GENERATED_CSS)).toBe(true)

        const css = readFileSync(GENERATED_CSS, 'utf-8')

        // All new vars must still be present.
        for (const varName of REQUIRED_THEME_VARS) {
          expect(css, `${varName} missing after zweiter-shop build`).toContain(varName)
        }

        // New color slots must fall back to the exact default value (deep-merge).
        for (const slot of NEW_COLOR_SLOTS) {
          const varName = `--theme-color-${slot}`
          const actual = getCssVarValue(css, varName)
          expect(actual, `${varName} missing in zweiter-shop output`).toBe(defaultCfg.colors[slot])
        }

        // New radius slots fall back to default.
        for (const slot of NEW_RADIUS_SLOTS) {
          const varName = `--theme-radius-${slot}`
          const actual = getCssVarValue(css, varName)
          expect(actual, `${varName} missing in zweiter-shop output`).toBe(defaultCfg.radius[slot])
        }

        // New shadow slots fall back to default.
        for (const slot of NEW_SHADOW_SLOTS) {
          const varName = `--theme-shadow-${slot}`
          const actual = getCssVarValue(css, varName)
          expect(actual, `${varName} missing in zweiter-shop output`).toBe(defaultCfg.shadows[slot])
        }
      },
    )

    it(
      'AC-5c: GIVEN zweiter-shop is active, WHEN generate-theme.mjs runs, ' +
        'THEN shop-specific overrides (primary, primary-hover, accent, fonts.heading) ' +
        'ARE applied (proof the merge is actually running, not just copying default)',
      () => {
        const shopCfg = parseYaml(readFileSync(ZWEITER_SHOP_YAML, 'utf-8')) as {
          colors: Record<string, string>
          fonts?: Record<string, string>
        }

        const result = runGenerator('zweiter-shop')
        expect(result.status).toBe(0)
        const css = readFileSync(GENERATED_CSS, 'utf-8')

        expect(getCssVarValue(css, '--theme-color-primary')).toBe(shopCfg.colors.primary)
        expect(getCssVarValue(css, '--theme-color-primary-hover')).toBe(
          shopCfg.colors['primary-hover'],
        )
        expect(getCssVarValue(css, '--theme-color-accent')).toBe(shopCfg.colors.accent)
        if (shopCfg.fonts?.heading) {
          expect(getCssVarValue(css, '--theme-font-heading')).toBe(shopCfg.fonts.heading)
        }
      },
    )
  })
})
