// tests/slices/kleinstadtpflanze-layout-flair/theme-overrides-home.test.ts
//
// Slice-01: Homepage YAML, registry, SKELETON_MAP, default-theme isolation, test setup
// Spec: specs/2026-04-26-kleinstadtpflanze-layout-flair/slim-spec.md (Slice 1)
//
// Acceptance Criteria covered:
//   AC-1.17: Registry registration (4 new block types resolveBlock(...) → defined component)
//   AC-1.18: Skeleton map entries (entries for 4 new types in app/page.tsx SKELETON_MAP)
//   AC-1.19: Homepage YAML section order (8 sections in exact order, hero variant=two-col,
//            every type registered)
//   AC-1.20: Trust-badges honesty (no forbidden marketing strings; honest set present)
//   AC-1.21: Default theme isolation (themes/default/pages/home.yaml byte-identical
//            with master branch — no Slice-1 lines changed in default theme)
//   AC-1.22: Test setup theme env (NEXT_PUBLIC_THEME defaults to "kleinstadtpflanze"
//            in tests/setup.ts)
//   AC-1.23 (smoke): kleinstadtpflanze pages directory + 3 YAML files exist (full
//            assertion lives in tests/slices/kleinstadtpflanze-design-e/slice-03-theme.test.ts)
//
// Strategy: real fs reads, real YAML parse, real `git show` for AC-1.21.

import { describe, it, expect } from 'vitest'
import { readFileSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { execSync } from 'node:child_process'
import { parse as parseYaml } from 'yaml'
import { resolveBlock, registry } from '@/lib/blocks/registry'

const FRONTEND_ROOT = resolve(__dirname, '..', '..', '..')
const REPO_ROOT = resolve(FRONTEND_ROOT, '..')
const KP_HOME_YAML = resolve(FRONTEND_ROOT, 'themes/kleinstadtpflanze/pages/home.yaml')
const DEFAULT_HOME_YAML = resolve(FRONTEND_ROOT, 'themes/default/pages/home.yaml')
const KP_PAGES_DIR = resolve(FRONTEND_ROOT, 'themes/kleinstadtpflanze/pages')
const SETUP_TS = resolve(FRONTEND_ROOT, 'tests/setup.ts')
const APP_PAGE_TSX = resolve(FRONTEND_ROOT, 'app/page.tsx')

const NEW_BLOCK_TYPES = ['editorial-mini', 'faq-accordion', 'motif-grid', 'brand-recap'] as const

const FORBIDDEN_MARKETING_STRINGS = [
  'Klimaneutral',
  'Made in Germany',
  'Familienbetrieb',
  '4,9★',
  'Bewertungen',
] as const

interface BlockYaml {
  type: string
  content_source: string
  params: {
    props?: Record<string, unknown>
    [k: string]: unknown
  }
}

interface SectionYaml {
  columns?: number
  blocks: BlockYaml[]
}

interface PageYaml {
  sections: SectionYaml[]
}

function loadKpHome(): PageYaml {
  return parseYaml(readFileSync(KP_HOME_YAML, 'utf-8')) as PageYaml
}

describe('Slice 01: Homepage-Stack — YAML, registry, skeleton map, isolation, setup', () => {
  describe('AC-1.17: Registry registration', () => {
    it.each(NEW_BLOCK_TYPES)(
      'AC-1.17[%s]: GIVEN lib/blocks/registry.ts is imported ' +
        'WHEN resolveBlock("%s") is called ' +
        'THEN it returns a defined React component (no undefined)',
      (type) => {
        const component = resolveBlock(type)
        expect(component, `resolveBlock("${type}") must not return null/undefined`).not.toBeNull()
        expect(typeof component).toBe('function')
        // Sanity: also present in the raw registry map.
        expect(registry[type]).toBeDefined()
      },
    )
  })

  describe('AC-1.18: SKELETON_MAP entries in app/page.tsx', () => {
    it(
      'AC-1.18: GIVEN app/page.tsx source is read ' +
        'WHEN SKELETON_MAP is inspected (textually) ' +
        'THEN it contains entries for all 4 new block types ' +
        '(each entry may be `null` for inline-sync blocks; entry must exist)',
      () => {
        const source = readFileSync(APP_PAGE_TSX, 'utf-8')
        for (const type of NEW_BLOCK_TYPES) {
          // Match e.g. `'editorial-mini': null,` or `'faq-accordion': <FaqAccordionBlockSkeleton />,`
          const re = new RegExp(`['"]${type}['"]\\s*:`, 'm')
          expect(
            re.test(source),
            `SKELETON_MAP in app/page.tsx must include key "${type}"`,
          ).toBe(true)
        }
      },
    )
  })

  describe('AC-1.19: Homepage YAML section order', () => {
    const EXPECTED_ORDER = [
      'hero',
      'category-showcase',
      'product-grid',
      'motif-grid',
      'editorial-mini',
      'faq-accordion',
      'trust-badges',
      'brand-recap',
    ] as const

    it(
      'AC-1.19a: GIVEN themes/kleinstadtpflanze/pages/home.yaml is parsed ' +
        'WHEN section order is extracted ' +
        'THEN block types appear in this exact order: ' +
        '[hero, category-showcase, product-grid, motif-grid, editorial-mini, faq-accordion, trust-badges, brand-recap]',
      () => {
        const cfg = loadKpHome()
        expect(Array.isArray(cfg.sections)).toBe(true)
        expect(cfg.sections.length).toBe(EXPECTED_ORDER.length)

        const actualOrder = cfg.sections.map((s) => s.blocks[0]?.type)
        expect(actualOrder).toEqual(EXPECTED_ORDER)
      },
    )

    it(
      'AC-1.19b: GIVEN the hero block in kleinstadtpflanze home.yaml ' +
        'WHEN params.props.variant is read ' +
        'THEN it equals "two-col"',
      () => {
        const cfg = loadKpHome()
        const heroBlock = cfg.sections[0].blocks[0]
        expect(heroBlock.type).toBe('hero')
        const props = heroBlock.params.props as { variant?: string } | undefined
        expect(props, 'hero block must have params.props').toBeDefined()
        expect(props!.variant).toBe('two-col')
      },
    )

    it(
      'AC-1.19c: GIVEN every block type referenced in kleinstadtpflanze home.yaml ' +
        'WHEN cross-checked against registry ' +
        'THEN each type resolves to a defined component (no Unknown block type warnings)',
      () => {
        const cfg = loadKpHome()
        for (const section of cfg.sections) {
          for (const block of section.blocks) {
            const component = resolveBlock(block.type)
            expect(
              component,
              `block type "${block.type}" referenced in home.yaml is NOT registered`,
            ).not.toBeNull()
          }
        }
      },
    )
  })

  describe('AC-1.20: Trust-badges honesty', () => {
    it(
      'AC-1.20a: GIVEN trust-badges block items in kleinstadtpflanze home.yaml ' +
        'WHEN every item.text is concatenated ' +
        'THEN none of the forbidden marketing strings appear ' +
        '(Klimaneutral, Made in Germany, Familienbetrieb, 4,9★, Bewertungen)',
      () => {
        const cfg = loadKpHome()
        const trustSection = cfg.sections.find((s) => s.blocks[0]?.type === 'trust-badges')
        expect(trustSection, 'trust-badges section must exist').toBeDefined()
        const items =
          (trustSection!.blocks[0].params.props as { items?: { text: string }[] } | undefined)
            ?.items ?? []

        const concatenated = items.map((i) => i.text).join(' || ')
        for (const forbidden of FORBIDDEN_MARKETING_STRINGS) {
          expect(
            concatenated.includes(forbidden),
            `trust-badges contains forbidden string "${forbidden}". Items: ${concatenated}`,
          ).toBe(false)
        }
      },
    )

    it(
      'AC-1.20b: GIVEN trust-badges items ' +
        'WHEN inspected ' +
        'THEN they include the honest set: ' +
        '"Versand DE 3–5 Werktage", "30 Tage Rückgabe", "Sichere Zahlung"',
      () => {
        const cfg = loadKpHome()
        const trustSection = cfg.sections.find((s) => s.blocks[0]?.type === 'trust-badges')
        const items =
          (trustSection!.blocks[0].params.props as { items?: { text: string }[] } | undefined)
            ?.items ?? []
        const texts = items.map((i) => i.text)
        expect(texts).toContain('Versand DE 3–5 Werktage')
        expect(texts).toContain('30 Tage Rückgabe')
        expect(texts).toContain('Sichere Zahlung')
      },
    )

    it(
      'AC-1.20c: GIVEN the entire kleinstadtpflanze home.yaml file (all string values) ' +
        'WHEN scanned for forbidden marketing strings ' +
        'THEN none of the forbidden strings appear anywhere in the file ' +
        '(catches honesty violations even outside trust-badges block)',
      () => {
        const raw = readFileSync(KP_HOME_YAML, 'utf-8')
        for (const forbidden of FORBIDDEN_MARKETING_STRINGS) {
          expect(
            raw.includes(forbidden),
            `home.yaml contains forbidden string "${forbidden}"`,
          ).toBe(false)
        }
      },
    )
  })

  describe('AC-1.21: Default theme isolation (byte-identical with master)', () => {
    it(
      'AC-1.21: GIVEN themes/default/pages/home.yaml ' +
        'WHEN diffed against master:frontend/themes/default/pages/home.yaml (pre-Slice-1 state) ' +
        'THEN no lines have changed (byte-identical)',
      () => {
        const workingCopy = readFileSync(DEFAULT_HOME_YAML, 'utf-8')
        const masterContent = execSync(
          'git show master:frontend/themes/default/pages/home.yaml',
          { cwd: REPO_ROOT, encoding: 'utf-8' },
        )
        expect(
          workingCopy,
          'themes/default/pages/home.yaml must be byte-identical with master ' +
            '(Slice 1 must not modify default-theme YAML).',
        ).toBe(masterContent)
      },
    )
  })

  describe('AC-1.22: Test setup does not globally pollute NEXT_PUBLIC_THEME', () => {
    // Slice 01 originally set NEXT_PUBLIC_THEME=kleinstadtpflanze globally in
    // tests/setup.ts, which broke pre-existing tests in block-page-migration and
    // frontend-theming that rely on the unset/default theme. The fix: keep
    // tests/setup.ts theme-agnostic and have layout-flair tests opt-in per-file
    // when they actually need the env (e.g. for fonts.ts gating). Pure YAML/fs
    // assertions and direct component rendering do not require the env.
    it(
      'AC-1.22a: GIVEN tests/setup.ts is loaded by Vitest ' +
        'WHEN scanned ' +
        'THEN it does NOT set process.env.NEXT_PUBLIC_THEME globally ' +
        '(prevents regressions in pre-existing tests under block-page-migration / frontend-theming)',
      () => {
        const source = readFileSync(SETUP_TS, 'utf-8')
        expect(
          source.match(/process\.env\.NEXT_PUBLIC_THEME\s*=/),
          'tests/setup.ts must not assign NEXT_PUBLIC_THEME globally — ' +
            'scope theme env per-test-file via beforeAll() if needed',
        ).toBeNull()
      },
    )

    it(
      'AC-1.22b: GIVEN tests/setup.ts source ' +
        'WHEN scanned ' +
        'THEN it sets up only test-runtime concerns (e.g. localStorage mock) ' +
        'without leaking shop-specific theme defaults',
      () => {
        const source = readFileSync(SETUP_TS, 'utf-8')
        // Structural guard: setup.ts must not contain a hard-coded shop name.
        expect(source).not.toContain('kleinstadtpflanze')
        // Sanity: it should still install the localStorage mock that other
        // tests in the suite implicitly depend on.
        expect(source).toContain('localStorage')
      },
    )
  })

  describe('AC-1.23: Smoke check — kleinstadtpflanze pages directory exists', () => {
    it(
      'AC-1.23: GIVEN Slice 1 introduces themes/kleinstadtpflanze/pages/ ' +
        'WHEN the directory is inspected ' +
        'THEN home.yaml exists and parses to a valid sections array ' +
        '(full assertion suite lives in tests/slices/kleinstadtpflanze-design-e/slice-03-theme.test.ts AC-5b)',
      () => {
        expect(existsSync(KP_PAGES_DIR)).toBe(true)
        expect(existsSync(KP_HOME_YAML)).toBe(true)
        const cfg = loadKpHome()
        expect(Array.isArray(cfg.sections)).toBe(true)
        expect(cfg.sections.length).toBeGreaterThan(0)
      },
    )
  })
})
