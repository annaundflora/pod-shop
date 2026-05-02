// tests/slices/kleinstadtpflanze-layout-flair/theme-overrides-category.test.ts
//
// Slice-03: Category-Bottom-Flair — pure YAML composition + SKELETON_MAP wiring.
// Spec: specs/2026-04-26-kleinstadtpflanze-layout-flair/slim-spec.md (Slice 3)
//
// Acceptance Criteria covered:
//   AC-3.1: Category YAML section order (8 default sections verbatim, then
//           product-recommendations, faq-accordion, brand-recap; every type registered)
//   AC-3.2: Recommendations cross-category source (query=product_recommendations,
//           source=bestsellers, first=4, heading="Vielleicht auch was für dich")
//   AC-3.3: Recommendations renders even when category empty (structural assertion:
//           recommendations + faq + brand-recap come AFTER empty-state in section order;
//           SectionRenderer renders all sections regardless of upstream block data)
//   AC-3.4: Mini-FAQ subset (exactly 3 items, each with non-empty q AND a, plain strings)
//   AC-3.5: Brand-Recap parity with homepage (tagline string equals home.yaml brand-recap
//           tagline → consistency)
//   AC-3.6: Default category isolation (themes/default/pages/category.yaml byte-identical
//           with master branch — no Slice-3 lines changed in default theme)
//   AC-3.7: Skeleton map entries (entries for product-recommendations, faq-accordion,
//           brand-recap in app/kategorie/[slug]/page.tsx SKELETON_MAP)
//   AC-3.8: Marketing-honesty (no forbidden strings in category.yaml)
//   AC-3.9: No new components (no files added/modified under components/blocks/ for Slice 3;
//           only category.yaml + page.tsx + tests in the diff)
//
// Strategy: real fs reads, real YAML parse, real `git show`/`git diff` for AC-3.6 and AC-3.9.
// Per Test-Strategy mock_external — but Slice 3 has no GraphQL surface to mock; pure YAML.

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { execSync } from 'node:child_process'
import { parse as parseYaml } from 'yaml'
import { resolveBlock } from '@/lib/blocks/registry'

const FRONTEND_ROOT = resolve(__dirname, '..', '..', '..')
const REPO_ROOT = resolve(FRONTEND_ROOT, '..')
const KP_CATEGORY_YAML = resolve(
  FRONTEND_ROOT,
  'themes/kleinstadtpflanze/pages/category.yaml',
)
const KP_HOME_YAML = resolve(
  FRONTEND_ROOT,
  'themes/kleinstadtpflanze/pages/home.yaml',
)
const DEFAULT_CATEGORY_YAML = resolve(
  FRONTEND_ROOT,
  'themes/default/pages/category.yaml',
)
const APP_CATEGORY_PAGE = resolve(FRONTEND_ROOT, 'app/kategorie/[slug]/page.tsx')

// Forbidden marketing strings: per AC-3.8 — fixed brand-violation set + dynamic
// review/rating patterns (regex-tested separately below).
const FORBIDDEN_MARKETING_STRINGS = [
  'Klimaneutral',
  'Made in Germany',
  'Familienbetrieb',
] as const

// Regex patterns for review-count / star-rating violations.
// "X,X★" → digits, comma, digit, star  |  "N Bewertungen" → digits + space + word
const RATING_PATTERN = /\d+,\d+★/
const REVIEW_COUNT_PATTERN = /\d+\s*Bewertungen/

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

function loadKpCategory(): PageYaml {
  return parseYaml(readFileSync(KP_CATEGORY_YAML, 'utf-8')) as PageYaml
}

function loadKpHome(): PageYaml {
  return parseYaml(readFileSync(KP_HOME_YAML, 'utf-8')) as PageYaml
}

describe('Slice 03: Category-Bottom-Flair — YAML, registry, skeleton map, isolation, honesty', () => {
  describe('AC-3.1: Category YAML section order', () => {
    // First 8 block types — must mirror themes/default/pages/category.yaml verbatim.
    const EXPECTED_FIRST_8 = [
      'breadcrumb',
      'page-heading',
      'filter-chips',
      'product-count',
      'sort-bar',
      'product-grid',
      'pagination',
      'empty-state',
    ] as const

    // Sections 9, 10, 11 — Slice-3 additions, in this exact order.
    const EXPECTED_FLAIR = [
      'product-recommendations',
      'faq-accordion',
      'brand-recap',
    ] as const

    const EXPECTED_FULL_ORDER = [...EXPECTED_FIRST_8, ...EXPECTED_FLAIR] as const

    it(
      'AC-3.1a: GIVEN themes/kleinstadtpflanze/pages/category.yaml is parsed ' +
        'WHEN section order is extracted ' +
        'THEN the first 8 block types match themes/default/pages/category.yaml verbatim ' +
        '(breadcrumb, page-heading, filter-chips, product-count, sort-bar, product-grid, pagination, empty-state)',
      () => {
        const cfg = loadKpCategory()
        expect(Array.isArray(cfg.sections)).toBe(true)
        const actual = cfg.sections.map((s) => s.blocks[0]?.type)
        expect(actual.slice(0, 8)).toEqual(EXPECTED_FIRST_8)
      },
    )

    it(
      'AC-3.1b: GIVEN themes/kleinstadtpflanze/pages/category.yaml is parsed ' +
        'WHEN sections 9, 10, 11 are inspected ' +
        'THEN they are product-recommendations, faq-accordion, brand-recap (in that order)',
      () => {
        const cfg = loadKpCategory()
        const actual = cfg.sections.map((s) => s.blocks[0]?.type)
        expect(actual.length).toBe(EXPECTED_FULL_ORDER.length)
        expect(actual.slice(8)).toEqual(EXPECTED_FLAIR)
      },
    )

    it(
      'AC-3.1c: GIVEN the first 8 sections of kleinstadtpflanze category.yaml ' +
        'WHEN compared to themes/default/pages/category.yaml ' +
        'THEN the block-type sequence is verbatim identical',
      () => {
        const kpCfg = loadKpCategory()
        const defaultCfg = parseYaml(
          readFileSync(DEFAULT_CATEGORY_YAML, 'utf-8'),
        ) as PageYaml
        const kpFirst8 = kpCfg.sections.slice(0, 8).map((s) => s.blocks[0]?.type)
        const defaultTypes = defaultCfg.sections.map((s) => s.blocks[0]?.type)
        expect(kpFirst8).toEqual(defaultTypes)
      },
    )

    it(
      'AC-3.1d: GIVEN every block type referenced in kleinstadtpflanze category.yaml ' +
        'WHEN cross-checked against registry ' +
        'THEN each type resolves to a defined component (no Unknown block type warnings)',
      () => {
        const cfg = loadKpCategory()
        for (const section of cfg.sections) {
          for (const block of section.blocks) {
            const component = resolveBlock(block.type)
            expect(
              component,
              `block type "${block.type}" referenced in category.yaml is NOT registered`,
            ).not.toBeNull()
          }
        }
      },
    )
  })

  describe('AC-3.2: Recommendations cross-category source', () => {
    it(
      'AC-3.2a: GIVEN the category-page product-recommendations block in YAML ' +
        'WHEN params are inspected ' +
        'THEN params.query === "product_recommendations"',
      () => {
        const cfg = loadKpCategory()
        const recSection = cfg.sections.find(
          (s) => s.blocks[0]?.type === 'product-recommendations',
        )
        expect(recSection, 'product-recommendations section must exist').toBeTruthy()
        expect(recSection!.blocks[0].params.query).toBe('product_recommendations')
      },
    )

    it(
      'AC-3.2b: GIVEN the category-page product-recommendations block ' +
        'WHEN params.source is read ' +
        'THEN it equals "bestsellers" (cross-category mode in data-loaders.ts)',
      () => {
        const cfg = loadKpCategory()
        const recBlock = cfg.sections.find(
          (s) => s.blocks[0]?.type === 'product-recommendations',
        )!.blocks[0]
        expect(recBlock.params.source).toBe('bestsellers')
      },
    )

    it(
      'AC-3.2c: GIVEN the category-page product-recommendations block ' +
        'WHEN params.first is read ' +
        'THEN it equals 4',
      () => {
        const cfg = loadKpCategory()
        const recBlock = cfg.sections.find(
          (s) => s.blocks[0]?.type === 'product-recommendations',
        )!.blocks[0]
        expect(recBlock.params.first).toBe(4)
      },
    )

    it(
      'AC-3.2d: GIVEN the category-page product-recommendations block ' +
        'WHEN params.heading is read ' +
        'THEN it equals "Vielleicht auch was für dich"',
      () => {
        const cfg = loadKpCategory()
        const recBlock = cfg.sections.find(
          (s) => s.blocks[0]?.type === 'product-recommendations',
        )!.blocks[0]
        expect(recBlock.params.heading).toBe('Vielleicht auch was für dich')
      },
    )
  })

  describe('AC-3.3: Recommendations renders even when category empty', () => {
    it(
      'AC-3.3: GIVEN a category that has zero products (empty-state path) ' +
        'WHEN the section sequence is inspected ' +
        'THEN empty-state is followed by product-recommendations, faq-accordion, brand-recap ' +
        '(SectionRenderer renders all sections regardless of upstream block data; ' +
        'recommendations data source is bestsellers, not the current category)',
      () => {
        const cfg = loadKpCategory()
        const types = cfg.sections.map((s) => s.blocks[0]?.type)

        // Find empty-state index — must come BEFORE the 3 flair sections.
        const emptyIdx = types.indexOf('empty-state')
        const recIdx = types.indexOf('product-recommendations')
        const faqIdx = types.indexOf('faq-accordion')
        const brandIdx = types.indexOf('brand-recap')

        expect(emptyIdx, 'empty-state must exist').toBeGreaterThanOrEqual(0)
        expect(recIdx, 'product-recommendations must exist').toBeGreaterThanOrEqual(0)
        expect(faqIdx, 'faq-accordion must exist').toBeGreaterThanOrEqual(0)
        expect(brandIdx, 'brand-recap must exist').toBeGreaterThanOrEqual(0)

        // Strict ordering: empty-state < recommendations < faq < brand-recap
        expect(emptyIdx).toBeLessThan(recIdx)
        expect(recIdx).toBeLessThan(faqIdx)
        expect(faqIdx).toBeLessThan(brandIdx)

        // The recommendations block must NOT depend on the category slug for its data
        // (its `source` is bestsellers, not slug-based).
        const recBlock = cfg.sections[recIdx].blocks[0]
        expect(recBlock.params.source).toBe('bestsellers')
        expect(recBlock.params.slug).toBeUndefined()
      },
    )
  })

  describe('AC-3.4: Mini-FAQ subset', () => {
    it(
      'AC-3.4a: GIVEN the category-page faq-accordion block ' +
        'WHEN params.props.items is inspected ' +
        'THEN it contains exactly 3 items',
      () => {
        const cfg = loadKpCategory()
        const faqBlock = cfg.sections.find(
          (s) => s.blocks[0]?.type === 'faq-accordion',
        )!.blocks[0]
        const props = faqBlock.params.props as
          | { items?: { q: string; a: string }[] }
          | undefined
        expect(props, 'faq-accordion must have params.props').toBeTruthy()
        expect(Array.isArray(props!.items)).toBe(true)
        expect(props!.items!.length).toBe(3)
      },
    )

    it(
      'AC-3.4b: GIVEN the category-page faq-accordion items ' +
        'WHEN each item is inspected ' +
        'THEN every item has a non-empty `q` AND a non-empty `a` (plain strings, no HTML)',
      () => {
        const cfg = loadKpCategory()
        const faqBlock = cfg.sections.find(
          (s) => s.blocks[0]?.type === 'faq-accordion',
        )!.blocks[0]
        const items = (
          faqBlock.params.props as { items: { q: string; a: string }[] }
        ).items

        for (const [idx, item] of items.entries()) {
          expect(typeof item.q, `item[${idx}].q must be a string`).toBe('string')
          expect(typeof item.a, `item[${idx}].a must be a string`).toBe('string')
          expect(item.q.trim().length, `item[${idx}].q must be non-empty`).toBeGreaterThan(0)
          expect(item.a.trim().length, `item[${idx}].a must be non-empty`).toBeGreaterThan(0)
          // Plain strings: no HTML tags
          expect(item.q, `item[${idx}].q must be plain text (no HTML)`).not.toMatch(/<[^>]+>/)
          expect(item.a, `item[${idx}].a must be plain text (no HTML)`).not.toMatch(/<[^>]+>/)
        }
      },
    )
  })

  describe('AC-3.5: Brand-Recap parity with homepage', () => {
    it(
      'AC-3.5a: GIVEN the category-page brand-recap block ' +
        'WHEN params.props is inspected ' +
        'THEN it has tagline AND href fields (same shape as homepage brand-recap)',
      () => {
        const cfg = loadKpCategory()
        const brandBlock = cfg.sections.find(
          (s) => s.blocks[0]?.type === 'brand-recap',
        )!.blocks[0]
        const props = brandBlock.params.props as
          | { tagline?: string; href?: string }
          | undefined
        expect(props, 'brand-recap must have params.props').toBeTruthy()
        expect(typeof props!.tagline).toBe('string')
        expect(typeof props!.href).toBe('string')
        expect(props!.tagline!.trim().length).toBeGreaterThan(0)
      },
    )

    it(
      'AC-3.5b: GIVEN the category-page brand-recap block AND the homepage brand-recap block ' +
        'WHEN both taglines are read ' +
        'THEN they match (consistency between home + category)',
      () => {
        const catCfg = loadKpCategory()
        const homeCfg = loadKpHome()

        const catBrand = catCfg.sections.find(
          (s) => s.blocks[0]?.type === 'brand-recap',
        )!.blocks[0]
        const homeBrand = homeCfg.sections.find(
          (s) => s.blocks[0]?.type === 'brand-recap',
        )!.blocks[0]

        const catTagline = (catBrand.params.props as { tagline: string }).tagline
        const homeTagline = (homeBrand.params.props as { tagline: string }).tagline

        expect(
          catTagline,
          'category brand-recap tagline must equal homepage brand-recap tagline',
        ).toBe(homeTagline)
      },
    )

    it(
      'AC-3.5c: GIVEN the category-page brand-recap block ' +
        'WHEN content_source is inspected ' +
        'THEN it is "inline" (renders synchronously, same as homepage)',
      () => {
        const cfg = loadKpCategory()
        const brandBlock = cfg.sections.find(
          (s) => s.blocks[0]?.type === 'brand-recap',
        )!.blocks[0]
        expect(brandBlock.content_source).toBe('inline')
      },
    )
  })

  describe('AC-3.6: Default category isolation (byte-identical with master)', () => {
    it(
      'AC-3.6: GIVEN themes/default/pages/category.yaml ' +
        'WHEN diffed against master:frontend/themes/default/pages/category.yaml (pre-Slice-3 state) ' +
        'THEN no lines have changed (byte-identical)',
      () => {
        const workingCopy = readFileSync(DEFAULT_CATEGORY_YAML, 'utf-8')
        const masterContent = execSync(
          'git show master:frontend/themes/default/pages/category.yaml',
          { cwd: REPO_ROOT, encoding: 'utf-8' },
        )
        expect(
          workingCopy,
          'themes/default/pages/category.yaml must be byte-identical with master ' +
            '(Slice 3 must not modify default-theme YAML).',
        ).toBe(masterContent)
      },
    )
  })

  describe('AC-3.7: Skeleton map entries on category page', () => {
    const REQUIRED_KEYS = [
      'product-recommendations',
      'faq-accordion',
      'brand-recap',
    ] as const

    it(
      'AC-3.7: GIVEN app/kategorie/[slug]/page.tsx source is read ' +
        'WHEN SKELETON_MAP is inspected (textually) ' +
        'THEN it contains entries for product-recommendations, faq-accordion, brand-recap',
      () => {
        const source = readFileSync(APP_CATEGORY_PAGE, 'utf-8')
        for (const key of REQUIRED_KEYS) {
          // Match e.g. `'product-recommendations': null,` or `'faq-accordion': <Skeleton />,`
          const re = new RegExp(`['"]${key}['"]\\s*:`, 'm')
          expect(
            re.test(source),
            `SKELETON_MAP in app/kategorie/[slug]/page.tsx must include key "${key}"`,
          ).toBe(true)
        }
      },
    )
  })

  describe('AC-3.8: Marketing-honesty in category YAML', () => {
    it(
      'AC-3.8a: GIVEN themes/kleinstadtpflanze/pages/category.yaml is parsed ' +
        'WHEN faq-accordion items, brand-recap tagline, product-recommendations heading ' +
        'are concatenated ' +
        'THEN the concatenated string contains none of: ' +
        'Klimaneutral, Made in Germany, Familienbetrieb',
      () => {
        const cfg = loadKpCategory()

        const collected: string[] = []
        for (const section of cfg.sections) {
          for (const block of section.blocks) {
            const props = block.params.props as Record<string, unknown> | undefined

            if (block.type === 'faq-accordion' && props) {
              const items = props.items as { q: string; a: string }[] | undefined
              if (items) {
                for (const i of items) {
                  collected.push(i.q, i.a)
                }
              }
            }
            if (block.type === 'brand-recap' && props) {
              const tagline = props.tagline as string | undefined
              if (tagline) collected.push(tagline)
            }
            if (block.type === 'product-recommendations') {
              const heading = block.params.heading as string | undefined
              if (heading) collected.push(heading)
            }
          }
        }

        const concatenated = collected.join(' || ')
        for (const forbidden of FORBIDDEN_MARKETING_STRINGS) {
          expect(
            concatenated.includes(forbidden),
            `category YAML structured fields contain forbidden string "${forbidden}". ` +
              `Collected: ${concatenated}`,
          ).toBe(false)
        }
      },
    )

    it(
      'AC-3.8b: GIVEN the same concatenated structured fields ' +
        'WHEN scanned for rating + review-count patterns ' +
        'THEN no string matches "X,X★" (rating) AND no string matches "N Bewertungen" (review-count)',
      () => {
        const cfg = loadKpCategory()
        const collected: string[] = []
        for (const section of cfg.sections) {
          for (const block of section.blocks) {
            const props = block.params.props as Record<string, unknown> | undefined
            if (block.type === 'faq-accordion' && props) {
              const items = props.items as { q: string; a: string }[] | undefined
              if (items) {
                for (const i of items) collected.push(i.q, i.a)
              }
            }
            if (block.type === 'brand-recap' && props) {
              const tagline = props.tagline as string | undefined
              if (tagline) collected.push(tagline)
            }
            if (block.type === 'product-recommendations') {
              const heading = block.params.heading as string | undefined
              if (heading) collected.push(heading)
            }
          }
        }

        const concatenated = collected.join(' || ')
        expect(
          RATING_PATTERN.test(concatenated),
          `category YAML contains a rating pattern (X,X★). Collected: ${concatenated}`,
        ).toBe(false)
        expect(
          REVIEW_COUNT_PATTERN.test(concatenated),
          `category YAML contains a review-count pattern (N Bewertungen). Collected: ${concatenated}`,
        ).toBe(false)
      },
    )

    it(
      'AC-3.8c: GIVEN the entire kleinstadtpflanze category.yaml file (raw text) ' +
        'WHEN scanned for forbidden marketing strings + patterns ' +
        'THEN none appear anywhere in the file ' +
        '(catches honesty violations even outside structured fields)',
      () => {
        const raw = readFileSync(KP_CATEGORY_YAML, 'utf-8')
        for (const forbidden of FORBIDDEN_MARKETING_STRINGS) {
          expect(
            raw.includes(forbidden),
            `category.yaml contains forbidden string "${forbidden}"`,
          ).toBe(false)
        }
        expect(
          RATING_PATTERN.test(raw),
          'category.yaml contains a rating pattern (X,X★) somewhere in the raw file',
        ).toBe(false)
        expect(
          REVIEW_COUNT_PATTERN.test(raw),
          'category.yaml contains a review-count pattern (N Bewertungen) somewhere in the raw file',
        ).toBe(false)
      },
    )
  })

  describe('AC-3.9: No new components introduced for Slice 3', () => {
    // Slice 3 commit (introducing Category-Bottom-Flair). Its diff must NOT touch
    // anything under components/blocks/ or lib/blocks/data-loaders.ts.
    //
    // Strategy: ask git for the diff between master and HEAD, restricted to the
    // Slice-3 changeset's relevant paths. We verify file-level constraints, not
    // line counts — the spec says "no files added or modified" under those paths
    // for this slice's contribution.
    //
    // We use `git log --name-status` for the slice-03 feat commit specifically.
    // Falling back to `git diff` between master and HEAD is acceptable when the
    // slice-03 feat commit is the only diff in components/blocks/ and lib/blocks/.

    it(
      'AC-3.9a: GIVEN the slice-03 feat commit ' +
        'WHEN file paths are inspected ' +
        'THEN no files under frontend/components/blocks/ are added or modified',
      () => {
        // Find the Slice-3 feat commit by message — robust to commit hash drift.
        let sliceCommit = ''
        try {
          sliceCommit = execSync(
            'git log --pretty=format:"%H %s" master..HEAD',
            { cwd: REPO_ROOT, encoding: 'utf-8' },
          )
            .split('\n')
            .find((line) => line.includes('feat(slice-03)'))
            ?.split(' ')[0] ?? ''
        } catch {
          // master..HEAD failed; treat as no slice-3 commit found yet.
        }
        expect(
          sliceCommit,
          'A "feat(slice-03)" commit must exist between master and HEAD',
        ).not.toBe('')

        const changed = execSync(
          `git show --pretty=format: --name-only ${sliceCommit}`,
          { cwd: REPO_ROOT, encoding: 'utf-8' },
        )
          .split('\n')
          .map((l) => l.trim())
          .filter(Boolean)

        const blocksTouched = changed.filter((p) =>
          p.startsWith('frontend/components/blocks/'),
        )
        expect(
          blocksTouched,
          `Slice 3 must NOT touch components/blocks/. Found: ${blocksTouched.join(', ')}`,
        ).toEqual([])
      },
    )

    it(
      'AC-3.9b: GIVEN the slice-03 feat commit ' +
        'WHEN file paths are inspected ' +
        'THEN no files under frontend/lib/blocks/ are modified ' +
        '(registry.ts allowed only IF a new entry is needed — none expected for Slice 3)',
      () => {
        let sliceCommit = ''
        try {
          sliceCommit = execSync(
            'git log --pretty=format:"%H %s" master..HEAD',
            { cwd: REPO_ROOT, encoding: 'utf-8' },
          )
            .split('\n')
            .find((line) => line.includes('feat(slice-03)'))
            ?.split(' ')[0] ?? ''
        } catch {
          /* fallthrough */
        }
        expect(sliceCommit).not.toBe('')

        const changed = execSync(
          `git show --pretty=format: --name-only ${sliceCommit}`,
          { cwd: REPO_ROOT, encoding: 'utf-8' },
        )
          .split('\n')
          .map((l) => l.trim())
          .filter(Boolean)

        const libBlocksTouched = changed.filter((p) =>
          p.startsWith('frontend/lib/blocks/'),
        )
        // Slice 3 reuses existing block types — registry should NOT need new entries.
        // (If a future change adds one, this test will surface that decision.)
        expect(
          libBlocksTouched,
          `Slice 3 must NOT modify lib/blocks/. Found: ${libBlocksTouched.join(', ')}`,
        ).toEqual([])
      },
    )

    it(
      'AC-3.9c: GIVEN the slice-03 feat commit ' +
        'WHEN file paths are inspected ' +
        'THEN the only changes are: ' +
        '1 new YAML file (themes/kleinstadtpflanze/pages/category.yaml), ' +
        '1 modified app/kategorie/[slug]/page.tsx, ' +
        'plus optional doc/spec files (no test files in feat commit; tests live in a separate commit)',
      () => {
        let sliceCommit = ''
        try {
          sliceCommit = execSync(
            'git log --pretty=format:"%H %s" master..HEAD',
            { cwd: REPO_ROOT, encoding: 'utf-8' },
          )
            .split('\n')
            .find((line) => line.includes('feat(slice-03)'))
            ?.split(' ')[0] ?? ''
        } catch {
          /* fallthrough */
        }
        expect(sliceCommit).not.toBe('')

        const changed = execSync(
          `git show --pretty=format: --name-only ${sliceCommit}`,
          { cwd: REPO_ROOT, encoding: 'utf-8' },
        )
          .split('\n')
          .map((l) => l.trim())
          .filter(Boolean)

        // The two REQUIRED files must be present in the feat commit.
        expect(
          changed,
          `Slice 3 feat commit must include themes/kleinstadtpflanze/pages/category.yaml. Got: ${changed.join(', ')}`,
        ).toContain('frontend/themes/kleinstadtpflanze/pages/category.yaml')
        expect(
          changed,
          `Slice 3 feat commit must include app/kategorie/[slug]/page.tsx. Got: ${changed.join(', ')}`,
        ).toContain('frontend/app/kategorie/[slug]/page.tsx')

        // Frontend code changes are limited to the two paths above (specs/docs/state
        // outside frontend/ are allowed; tests/ live in a separate test commit).
        const frontendChanges = changed.filter((p) => p.startsWith('frontend/'))
        const allowedFrontendPaths = new Set([
          'frontend/themes/kleinstadtpflanze/pages/category.yaml',
          'frontend/app/kategorie/[slug]/page.tsx',
        ])
        for (const path of frontendChanges) {
          // Allow generated-theme.css regeneration as a side-effect of theme changes.
          if (path === 'frontend/app/generated-theme.css') continue
          expect(
            allowedFrontendPaths.has(path),
            `Slice 3 feat commit touched unexpected frontend file: ${path}. ` +
              `Allowed: ${[...allowedFrontendPaths].join(', ')}`,
          ).toBe(true)
        }
      },
    )
  })
})
