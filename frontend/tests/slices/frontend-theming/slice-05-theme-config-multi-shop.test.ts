// tests/slices/frontend-theming/slice-05-theme-config-multi-shop.test.ts
/**
 * Acceptance Tests for Slice 05: Theme Config + Multi-Shop.
 * Derived from GIVEN/WHEN/THEN Acceptance Criteria in the Slice-Spec.
 *
 * AC-1: generate-theme.mjs with NEXT_PUBLIC_THEME=zweiter-shop produces shop-specific CSS + default fallbacks
 * AC-2: deepMerge() handles null/empty shop config gracefully (no crash)
 * AC-3: themes/zweiter-shop/theme.yaml contains ONLY overrides (minimal config)
 * AC-4: themes/zweiter-shop/pages/home.yaml exists and is valid page config
 * AC-5: public/themes/default/assets/logo.svg and public/themes/zweiter-shop/assets/logo.svg exist
 * AC-6: getLogoPath('default') returns correct path; getLogoPath('non-existent') returns default path
 * AC-7: getFaviconPath('zweiter-shop') returns correct path
 * AC-8: lib/theme/logo.ts uses fs.existsSync (no 'use client' directive)
 */
import { describe, it, expect, afterEach } from 'vitest'
import { readFileSync, existsSync, writeFileSync, mkdirSync, rmSync, unlinkSync } from 'fs'
import { resolve } from 'path'
import { execSync } from 'child_process'

const FRONTEND_ROOT = resolve(__dirname, '../../..')
const GENERATED_CSS = resolve(FRONTEND_ROOT, 'app/generated-theme.css')
const SCRIPT_PATH = resolve(FRONTEND_ROOT, 'scripts/generate-theme.mjs')
const DEFAULT_THEME_YAML = resolve(FRONTEND_ROOT, 'themes/default/theme.yaml')
const SHOP_THEME_DIR = resolve(FRONTEND_ROOT, 'themes/zweiter-shop')
const SHOP_THEME_YAML = resolve(FRONTEND_ROOT, 'themes/zweiter-shop/theme.yaml')

// --- Helpers ---

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

// --- Datei-Existenz Tests (AC-3, AC-4, AC-5, AC-8) ---

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

// --- Deep Merge Logik Tests (AC-1) ---

describe('generate-theme.mjs — Deep Merge Logik', () => {
  afterEach(cleanupGeneratedCSS)

  it('AC-1: should merge shop colors over default colors (shop value wins)', () => {
    /**
     * AC-1: GIVEN NEXT_PUBLIC_THEME=zweiter-shop
     * WHEN node scripts/generate-theme.mjs runs
     * THEN CSS contains shop-specific primary color AND default values for unoverridden tokens
     */
    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: 'zweiter-shop' },
    })
    const css = readFileSync(GENERATED_CSS, 'utf-8')
    // Shop-Override: different primary than Default (0.45 0.2 270)
    expect(css).toContain('--theme-color-primary: oklch(0.55 0.18 30)')
    expect(css).toContain('--theme-color-primary-hover: oklch(0.45 0.18 30)')
  })

  it('AC-1: should keep default values for keys not overridden by shop', () => {
    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: 'zweiter-shop' },
    })
    const css = readFileSync(GENERATED_CSS, 'utf-8')
    // Default values: surface, error, success, warning remain as in Default
    expect(css).toContain('--theme-color-surface: oklch(1 0 0)')
    expect(css).toContain('--theme-color-error: oklch(0.55 0.2 25)')
    expect(css).toContain('--theme-color-success: oklch(0.55 0.15 145)')
    expect(css).toContain('--theme-color-warning: oklch(0.7 0.15 85)')
  })

  it('AC-1: should override font.heading but keep font.body from default', () => {
    execSync(`node ${SCRIPT_PATH}`, {
      cwd: FRONTEND_ROOT,
      env: { ...process.env, NEXT_PUBLIC_THEME: 'zweiter-shop' },
    })
    const css = readFileSync(GENERATED_CSS, 'utf-8')
    // Shop has only overridden heading
    expect(css).toContain('--theme-font-heading: Playfair Display')
    // body remains default
    expect(css).toContain('--theme-font-body: Work Sans')
  })

  it('AC-1: should keep all radius and shadow tokens from default when not overridden', () => {
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

  it('AC-1: should generate comment header with shop theme name', () => {
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

    // Generates anyway (fallback to Default)
    expect(existsSync(GENERATED_CSS)).toBe(true)
    // Fallback message on stdout
    expect(stdout).toContain('shop-does-not-exist-xyz')
  })
})

// --- Deep Merge Unit Tests (isoliert) (AC-2) ---

describe('generate-theme.mjs — deepMerge() Unit Tests', () => {
  // deepMerge is an internal function in the script, so we test behavior
  // indirectly via temporary theme files with known values

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
    // Override applied
    expect(css).toContain('--theme-color-primary: oklch(0.6 0.2 120)')
    // Default colors preserved
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
    expect(css).toContain('--theme-font-body: Work Sans')
  })

  it('AC-2: should merge empty shop config — all defaults kept (null config graceful)', () => {
    /**
     * AC-2: GIVEN a shop theme.yaml with only comments (parsed as null)
     * WHEN generate-theme.mjs runs with that theme
     * THEN no crash occurs and all default values are in the CSS output
     */
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
    // Dynamically read the actual primary color from default theme.yaml
    const defaultYaml = readFileSync(DEFAULT_THEME_YAML, 'utf-8')
    const primaryMatch = defaultYaml.match(/^\s*primary:\s*"([^"]+)"/m)
    const defaultPrimary = primaryMatch?.[1] ?? ''
    // All default colors are in the output
    expect(css).toContain(`--theme-color-primary: ${defaultPrimary}`)
    expect(css).toContain('--theme-font-heading: Source Serif 4')
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

// --- Shop Theme YAML Schema Tests (AC-3) ---

describe('themes/zweiter-shop/theme.yaml — Schema', () => {
  it('AC-3: should parse as valid YAML', async () => {
    /**
     * AC-3: GIVEN themes/zweiter-shop/theme.yaml exists
     * WHEN parsed as YAML
     * THEN it is a valid config object with ONLY overrides (fewer than 18 token fields)
     */
    const { parse } = await import('yaml')
    const content = readFile('themes/zweiter-shop/theme.yaml')
    const config = parse(content)
    expect(config).toBeDefined()
  })

  it('AC-3: should have at least one color override', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/zweiter-shop/theme.yaml')
    const config = parse(content)
    expect(config.colors).toBeDefined()
    expect(Object.keys(config.colors ?? {}).length).toBeGreaterThan(0)
  })

  it('AC-3: all defined colors should be in oklch format', async () => {
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

  it('AC-3: should NOT contain all default keys (proves it is minimal)', async () => {
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

// --- Shop Page Config YAML Tests (AC-4) ---

describe('themes/zweiter-shop/pages/home.yaml — Schema', () => {
  it('AC-4: should parse as valid YAML with blocks array', async () => {
    /**
     * AC-4: GIVEN themes/zweiter-shop/pages/home.yaml exists
     * WHEN parsed as YAML
     * THEN it contains a valid blocks array with at least one block
     */
    const { parse } = await import('yaml')
    const content = readFile('themes/zweiter-shop/pages/home.yaml')
    const config = parse(content)
    expect(config).toBeDefined()
    expect(Array.isArray(config.blocks)).toBe(true)
    expect(config.blocks.length).toBeGreaterThan(0)
  })

  it('AC-4: all blocks should have required fields: type, content_source, params', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/zweiter-shop/pages/home.yaml')
    const config = parse(content)

    for (const block of config.blocks) {
      expect(block.type, `Block missing type`).toBeDefined()
      expect(block.content_source, `Block "${block.type}" missing content_source`).toBeDefined()
      expect(block.params, `Block "${block.type}" missing params`).toBeDefined()
    }
  })

  it('AC-4: should differ from default home.yaml (shop has customized page config)', async () => {
    const { parse } = await import('yaml')
    const shopContent = readFile('themes/zweiter-shop/pages/home.yaml')
    const defaultContent = readFile('themes/default/pages/home.yaml')
    const shopConfig = parse(shopContent)
    const defaultConfig = parse(defaultContent)

    // zweiter-shop uses flat blocks[] format; default home.yaml uses sections[] format
    // Flatten default sections to blocks for comparison
    const defaultBlocks: { type: string; content_source: string; params: Record<string, unknown> }[] =
      defaultConfig.sections
        ? defaultConfig.sections.flatMap((s: { blocks: { type: string; content_source: string; params: Record<string, unknown> }[] }) => s.blocks)
        : (defaultConfig.blocks ?? [])

    // At least one difference must be detectable
    const shopBlockTypes = shopConfig.blocks.map((b: { type: string }) => b.type).join(',')
    const defaultBlockTypes = defaultBlocks.map((b: { type: string }) => b.type).join(',')

    const shopUsps = shopConfig.blocks.find((b: { type: string }) => b.type === 'usp-bar')
    const defaultUsps = defaultBlocks.find((b: { type: string }) => b.type === 'usp-bar')

    const configsDiffer =
      shopBlockTypes !== defaultBlockTypes ||
      JSON.stringify((shopUsps?.params?.['props'] as Record<string, unknown>)?.['items']) !== JSON.stringify((defaultUsps?.params?.['props'] as Record<string, unknown>)?.['items'])

    expect(
      configsDiffer,
      'Shop home.yaml must differ from default home.yaml to prove customization'
    ).toBe(true)
  })
})

// --- Logo Resolver Tests (AC-6, AC-7, AC-8) ---

describe('lib/theme/logo.ts — getLogoPath() und getFaviconPath()', () => {
  it('AC-8: should export getLogoPath function', () => {
    /**
     * AC-8: GIVEN lib/theme/logo.ts source code
     * WHEN inspected
     * THEN it uses fs.existsSync and does NOT have 'use client' directive
     */
    const content = readFile('lib/theme/logo.ts')
    expect(content).toContain('export function getLogoPath')
  })

  it('AC-8: should export getFaviconPath function', () => {
    const content = readFile('lib/theme/logo.ts')
    expect(content).toContain('export function getFaviconPath')
  })

  it('AC-8: should use fs.existsSync and NOT have use client directive', () => {
    const content = readFile('lib/theme/logo.ts')
    expect(content).toContain('existsSync')
    expect(content).not.toContain("'use client'")
    expect(content).not.toContain('"use client"')
  })

  it('AC-6: getLogoPath should return shop logo path when shop logo exists', async () => {
    /**
     * AC-6: GIVEN getLogoPath('zweiter-shop') is called
     * WHEN the shop logo file exists
     * THEN it returns '/themes/zweiter-shop/assets/logo.svg'
     */
    const { getLogoPath } = await import('../../../lib/theme/logo')
    const result = getLogoPath('zweiter-shop')
    expect(result).toBe('/themes/zweiter-shop/assets/logo.svg')
  })

  it('AC-6: getLogoPath should return default logo path when called with "default"', async () => {
    const { getLogoPath } = await import('../../../lib/theme/logo')
    const result = getLogoPath('default')
    expect(result).toBe('/themes/default/assets/logo.svg')
  })

  it('AC-6: getLogoPath should return default logo path when shop logo does not exist', async () => {
    /**
     * AC-6: GIVEN getLogoPath('non-existent') is called
     * WHEN the shop logo file does NOT exist
     * THEN it returns the default path '/themes/default/assets/logo.svg'
     */
    const { getLogoPath } = await import('../../../lib/theme/logo')
    const result = getLogoPath('theme-without-logo-xyz')
    expect(result).toBe('/themes/default/assets/logo.svg')
  })

  it('AC-7: getFaviconPath should return shop favicon path when shop favicon exists', async () => {
    /**
     * AC-7: GIVEN getFaviconPath('zweiter-shop') is called
     * WHEN the shop favicon file exists
     * THEN it returns '/themes/zweiter-shop/assets/favicon.ico'
     */
    const { getFaviconPath } = await import('../../../lib/theme/logo')
    const result = getFaviconPath('zweiter-shop')
    expect(result).toBe('/themes/zweiter-shop/assets/favicon.ico')
  })

  it('AC-7: getFaviconPath should return default favicon path when shop favicon does not exist', async () => {
    const { getFaviconPath } = await import('../../../lib/theme/logo')
    const result = getFaviconPath('theme-without-favicon-xyz')
    expect(result).toBe('/themes/default/assets/favicon.ico')
  })
})

// --- Assets Tests (AC-5) ---

describe('Assets — Logo und Favicon Existenz und Format', () => {
  it('AC-5: default logo.svg should be valid SVG', () => {
    /**
     * AC-5: GIVEN public/themes/default/assets/logo.svg exists
     * WHEN read
     * THEN it contains valid SVG markup
     */
    const content = readFile('public/themes/default/assets/logo.svg')
    expect(content).toContain('<svg')
    expect(content).toContain('</svg>')
  })

  it('AC-5: zweiter-shop logo.svg should be valid SVG', () => {
    const content = readFile('public/themes/zweiter-shop/assets/logo.svg')
    expect(content).toContain('<svg')
    expect(content).toContain('</svg>')
  })

  it('AC-5: default logo.svg should have aria-label for accessibility', () => {
    const content = readFile('public/themes/default/assets/logo.svg')
    expect(content).toContain('aria-label')
  })

  it('AC-5: zweiter-shop logo.svg should have aria-label for accessibility', () => {
    const content = readFile('public/themes/zweiter-shop/assets/logo.svg')
    expect(content).toContain('aria-label')
  })

  it('AC-5: default favicon.ico should exist (as binary file)', () => {
    expect(existsSync(resolve(FRONTEND_ROOT, 'public/themes/default/assets/favicon.ico'))).toBe(true)
  })

  it('AC-5: zweiter-shop favicon.ico should exist (as binary file)', () => {
    expect(existsSync(resolve(FRONTEND_ROOT, 'public/themes/zweiter-shop/assets/favicon.ico'))).toBe(true)
  })
})

// --- .env.local.example Tests ---

describe('.env.local.example — NEXT_PUBLIC_THEME Eintrag', () => {
  it('should contain NEXT_PUBLIC_THEME=default', () => {
    const content = readFile('.env.local.example')
    expect(content).toContain('NEXT_PUBLIC_THEME=default')
  })
})

// --- generate-theme.mjs — Script enthält deepMerge ---

describe('generate-theme.mjs — deepMerge Funktion vorhanden', () => {
  it('should contain deepMerge function definition', () => {
    const content = readFile('scripts/generate-theme.mjs')
    expect(content).toContain('deepMerge')
  })

  it('should load default theme.yaml in main flow', () => {
    const content = readFile('scripts/generate-theme.mjs')
    // Script resolves default theme dir via resolve(FRONTEND_ROOT, 'themes', 'default')
    expect(content).toContain("'default'")
    expect(content).toContain("'themes'")
    expect(content).toContain('defaultThemeDir')
  })

  it('should call deepMerge to combine default and shop config', () => {
    const content = readFile('scripts/generate-theme.mjs')
    expect(content).toContain('deepMerge(')
  })

  it('should handle case when shop theme.yaml does not exist (fallback)', () => {
    const content = readFile('scripts/generate-theme.mjs')
    expect(content).toContain('existsSync')
    expect(content).toContain('falling back')
  })
})
