// tests/slices/kleinstadtpflanze-layout-flair/theme-overrides-product.test.ts
//
// Slice-02: PDP YAML override + data-loader passthrough merge + skeleton-map +
//           default-theme isolation + marketing-honesty
// Spec: specs/2026-04-26-kleinstadtpflanze-layout-flair/slim-spec.md (Slice 2)
//
// Acceptance Criteria covered:
//   AC-2.14: Loader param merge (woocommerceLoader product_by_slug branch merges YAML
//            params layout / withDescription / serviceBox into returned product)
//   AC-2.15: PDP YAML section order (product-gallery + product-purchase 2-col, trust-badges,
//            motif-cross-sell, product-recommendations, faq-accordion, brand-recap;
//            no product-description, no product-reviews; gallery layout=vertical-thumbs;
//            purchase withDescription=true + serviceBox.items non-empty)
//   AC-2.16: PDP YAML graceful-null wiring (motif-cross-sell tiles=null + content_source=inline)
//   AC-2.17: Default PDP isolation (themes/default/pages/product.yaml byte-identical with master)
//   AC-2.18: Variant selector unchanged (structural — kleinstadtpflanze PDP uses unchanged
//            ProductPurchaseBlock + ProductVariantSelector imports; existing tests still pass)
//   AC-2.19: Skeleton map entries (service-box, motif-cross-sell + faq-accordion, brand-recap
//            in app/produkt/[slug]/page.tsx SKELETON_MAP)
//   AC-2.20: Marketing-honesty in PDP YAML (no forbidden strings)
//
// Strategy: real fs reads, real YAML parse, real `git show` for AC-2.17, real Apollo mock
// for AC-2.14 (per Test-Strategy mock_external).

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { execSync } from 'node:child_process'
import { parse as parseYaml } from 'yaml'
import { resolveBlock } from '@/lib/blocks/registry'

const FRONTEND_ROOT = resolve(__dirname, '..', '..', '..')
const REPO_ROOT = resolve(FRONTEND_ROOT, '..')
const KP_PRODUCT_YAML = resolve(FRONTEND_ROOT, 'themes/kleinstadtpflanze/pages/product.yaml')
const DEFAULT_PRODUCT_YAML = resolve(FRONTEND_ROOT, 'themes/default/pages/product.yaml')
const APP_PDP_PAGE = resolve(FRONTEND_ROOT, 'app/produkt/[slug]/page.tsx')
const PRODUCT_PURCHASE_BLOCK = resolve(FRONTEND_ROOT, 'components/blocks/product-purchase-block.tsx')

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

function loadKpProduct(): PageYaml {
  return parseYaml(readFileSync(KP_PRODUCT_YAML, 'utf-8')) as PageYaml
}

describe('Slice 02: PDP — YAML, registry, skeleton map, default-theme isolation, honesty', () => {
  describe('AC-2.14: Loader param merge in product_by_slug branch', () => {
    beforeEach(() => {
      vi.resetModules()
    })

    afterEach(() => {
      vi.restoreAllMocks()
    })

    it(
      'AC-2.14a: GIVEN YAML params include withDescription=true AND serviceBox.items=[1] ' +
        'WHEN woocommerceLoader runs the product_by_slug branch ' +
        'THEN the returned data contains the WC product fields PLUS withDescription=true ' +
        'AND serviceBox: { items: [...] } (passthrough merge)',
      async () => {
        const mockProduct = {
          databaseId: 1,
          slug: 'test-product',
          name: 'Test',
          description: '<p>Hi</p>',
          price: '19,99 €',
        }
        vi.doMock('@/lib/apollo/server-client', () => ({
          getClient: () => ({
            query: vi.fn().mockResolvedValue({ data: { product: mockProduct } }),
          }),
        }))
        const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')

        const serviceBox = {
          items: [{ icon: 'package', label: 'A', detail: 'B' }],
        }
        const result = await woocommerceLoader({
          query: 'product_by_slug',
          slug: 'test-product',
          withDescription: true,
          serviceBox,
        } as Parameters<typeof woocommerceLoader>[0])

        expect(result.error).toBeUndefined()
        const data = result.data as Record<string, unknown>
        // WC fields present
        expect(data.databaseId).toBe(1)
        expect(data.slug).toBe('test-product')
        expect(data.name).toBe('Test')
        expect(data.price).toBe('19,99 €')
        // Passthrough merge present
        expect(data.withDescription).toBe(true)
        expect(data.serviceBox).toEqual(serviceBox)
      },
    )

    it(
      'AC-2.14b: GIVEN YAML params include layout="vertical-thumbs" ' +
        'WHEN woocommerceLoader runs the product_by_slug branch ' +
        'THEN data.layout === "vertical-thumbs" is set on the returned product',
      async () => {
        const mockProduct = {
          databaseId: 2,
          slug: 'gallery-test',
          name: 'Gallery Test',
          description: '',
          price: '',
        }
        vi.doMock('@/lib/apollo/server-client', () => ({
          getClient: () => ({
            query: vi.fn().mockResolvedValue({ data: { product: mockProduct } }),
          }),
        }))
        const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')

        const result = await woocommerceLoader({
          query: 'product_by_slug',
          slug: 'gallery-test',
          layout: 'vertical-thumbs',
        } as Parameters<typeof woocommerceLoader>[0])

        const data = result.data as Record<string, unknown>
        expect(data.layout).toBe('vertical-thumbs')
        // WC fields preserved
        expect(data.databaseId).toBe(2)
      },
    )

    it(
      'AC-2.14c: GIVEN YAML params include NO passthrough fields ' +
        '(no layout, no withDescription, no serviceBox) ' +
        'WHEN woocommerceLoader runs ' +
        'THEN none of those keys are added to the returned data ' +
        '(backward-compat with default theme product.yaml)',
      async () => {
        const mockProduct = {
          databaseId: 3,
          slug: 'plain',
          name: 'Plain',
          description: '<p>x</p>',
          price: '5,00 €',
        }
        vi.doMock('@/lib/apollo/server-client', () => ({
          getClient: () => ({
            query: vi.fn().mockResolvedValue({ data: { product: mockProduct } }),
          }),
        }))
        const { woocommerceLoader } = await import('@/lib/blocks/data-loaders')

        const result = await woocommerceLoader({
          query: 'product_by_slug',
          slug: 'plain',
        } as Parameters<typeof woocommerceLoader>[0])

        const data = result.data as Record<string, unknown>
        expect(data.databaseId).toBe(3)
        expect('layout' in data).toBe(false)
        expect('withDescription' in data).toBe(false)
        expect('serviceBox' in data).toBe(false)
      },
    )
  })

  describe('AC-2.15: PDP YAML section order + key params', () => {
    const EXPECTED_FIRST_SECTION_TYPES = ['product-gallery', 'product-purchase'] as const
    const EXPECTED_SECTION_HEADS = [
      // Section 1 has 2 blocks (gallery + purchase). Subsequent sections have 1 block each.
      'product-gallery', // section 0 first block
      'trust-badges',
      'motif-cross-sell',
      'product-recommendations',
      'faq-accordion',
      'brand-recap',
    ] as const

    it(
      'AC-2.15a: GIVEN themes/kleinstadtpflanze/pages/product.yaml is parsed ' +
        'WHEN section order is extracted ' +
        'THEN sections lead with [product-gallery, trust-badges, motif-cross-sell, ' +
        'product-recommendations, faq-accordion, brand-recap]',
      () => {
        const cfg = loadKpProduct()
        expect(Array.isArray(cfg.sections)).toBe(true)
        expect(cfg.sections.length).toBe(EXPECTED_SECTION_HEADS.length)

        const actual = cfg.sections.map((s) => s.blocks[0]?.type)
        expect(actual).toEqual(EXPECTED_SECTION_HEADS)
      },
    )

    it(
      'AC-2.15b: GIVEN the 2-col first section ' +
        'WHEN inspected ' +
        'THEN it has columns=2 AND contains exactly 2 blocks: [product-gallery, product-purchase]',
      () => {
        const cfg = loadKpProduct()
        const sec0 = cfg.sections[0]
        expect(sec0.columns).toBe(2)
        expect(sec0.blocks.length).toBe(2)
        expect(sec0.blocks.map((b) => b.type)).toEqual(EXPECTED_FIRST_SECTION_TYPES)
      },
    )

    it(
      'AC-2.15c: GIVEN the kleinstadtpflanze product.yaml ' +
        'WHEN scanned ' +
        'THEN no block of type "product-description" appears anywhere ' +
        'AND no block of type "product-reviews" appears anywhere',
      () => {
        const cfg = loadKpProduct()
        const allTypes = cfg.sections.flatMap((s) => s.blocks.map((b) => b.type))
        expect(allTypes).not.toContain('product-description')
        expect(allTypes).not.toContain('product-reviews')
      },
    )

    it(
      'AC-2.15d: GIVEN the product-gallery block ' +
        'WHEN params.layout is read ' +
        'THEN it equals "vertical-thumbs"',
      () => {
        const cfg = loadKpProduct()
        const gallery = cfg.sections[0].blocks.find((b) => b.type === 'product-gallery')
        expect(gallery, 'product-gallery block must exist').toBeTruthy()
        expect(gallery!.params.layout).toBe('vertical-thumbs')
      },
    )

    it(
      'AC-2.15e: GIVEN the product-purchase block ' +
        'WHEN params.withDescription is read ' +
        'THEN it equals true',
      () => {
        const cfg = loadKpProduct()
        const purchase = cfg.sections[0].blocks.find((b) => b.type === 'product-purchase')
        expect(purchase, 'product-purchase block must exist').toBeTruthy()
        expect(purchase!.params.withDescription).toBe(true)
      },
    )

    it(
      'AC-2.15f: GIVEN the product-purchase block ' +
        'WHEN params.serviceBox.items is read ' +
        'THEN it is a non-empty array',
      () => {
        const cfg = loadKpProduct()
        const purchase = cfg.sections[0].blocks.find((b) => b.type === 'product-purchase')
        expect(purchase).toBeTruthy()
        const sb = purchase!.params.serviceBox as { items?: unknown[] } | undefined
        expect(sb, 'serviceBox must exist').toBeTruthy()
        expect(Array.isArray(sb!.items)).toBe(true)
        expect((sb!.items as unknown[]).length).toBeGreaterThan(0)
      },
    )

    it(
      'AC-2.15g: GIVEN every block type referenced in the kleinstadtpflanze product.yaml ' +
        'WHEN cross-checked against registry ' +
        'THEN each type resolves to a defined component',
      () => {
        const cfg = loadKpProduct()
        for (const section of cfg.sections) {
          for (const block of section.blocks) {
            const component = resolveBlock(block.type)
            expect(
              component,
              `block type "${block.type}" referenced in product.yaml is NOT registered`,
            ).not.toBeNull()
          }
        }
      },
    )
  })

  describe('AC-2.16: motif-cross-sell graceful-null wiring', () => {
    it(
      'AC-2.16: GIVEN the motif-cross-sell block in product.yaml ' +
        'WHEN inspected ' +
        'THEN params.props.tiles === null AND content_source === "inline"',
      () => {
        const cfg = loadKpProduct()
        const mcsSection = cfg.sections.find((s) => s.blocks[0]?.type === 'motif-cross-sell')
        expect(mcsSection, 'motif-cross-sell section must exist').toBeTruthy()
        const mcsBlock = mcsSection!.blocks[0]
        expect(mcsBlock.content_source).toBe('inline')
        const props = mcsBlock.params.props as { tiles?: unknown } | undefined
        expect(props).toBeTruthy()
        expect(props!.tiles).toBeNull()
      },
    )
  })

  describe('AC-2.17: Default PDP isolation (byte-identical with master)', () => {
    it(
      'AC-2.17: GIVEN themes/default/pages/product.yaml ' +
        'WHEN diffed against master:frontend/themes/default/pages/product.yaml ' +
        'THEN no lines have changed (byte-identical)',
      () => {
        const workingCopy = readFileSync(DEFAULT_PRODUCT_YAML, 'utf-8')
        const masterContent = execSync(
          'git show master:frontend/themes/default/pages/product.yaml',
          { cwd: REPO_ROOT, encoding: 'utf-8' },
        )
        expect(
          workingCopy,
          'themes/default/pages/product.yaml must be byte-identical with master ' +
            '(Slice 2 must not modify default-theme YAML).',
        ).toBe(masterContent)
      },
    )
  })

  describe('AC-2.18: Variant selector unchanged (structural)', () => {
    it(
      'AC-2.18a: GIVEN ProductPurchaseBlock source ' +
        'WHEN inspected ' +
        'THEN it still imports ProductVariantSelector from app/produkt/[slug]/product-variant-selector ' +
        '(no changes to variant-selector behavior; existing pod-shop-mvp tests still apply)',
      () => {
        const source = readFileSync(PRODUCT_PURCHASE_BLOCK, 'utf-8')
        expect(source).toMatch(
          /import\s*\{\s*ProductVariantSelector\s*\}\s*from\s*['"]@\/app\/produkt\/\[slug\]\/product-variant-selector['"]/,
        )
        // Block still passes product + variantOptions to the selector (no signature change).
        expect(source).toContain('<ProductVariantSelector')
        expect(source).toContain('variantOptions={variantOptions}')
        expect(source).toContain('product={data}')
      },
    )

    it(
      'AC-2.18b: GIVEN ProductPurchaseBlock source ' +
        'WHEN inspected ' +
        'THEN it still uses extractVariantOptions to build variantOptions ' +
        '(same logic as default theme)',
      () => {
        const source = readFileSync(PRODUCT_PURCHASE_BLOCK, 'utf-8')
        expect(source).toMatch(/extractVariantOptions/)
      },
    )
  })

  describe('AC-2.19: Skeleton map entries on PDP page', () => {
    const REQUIRED_KEYS = [
      'service-box',
      'motif-cross-sell',
      'faq-accordion',
      'brand-recap',
    ] as const

    it(
      'AC-2.19: GIVEN app/produkt/[slug]/page.tsx source ' +
        'WHEN SKELETON_MAP is inspected (textually) ' +
        'THEN it contains entries for service-box, motif-cross-sell, faq-accordion, brand-recap',
      () => {
        const source = readFileSync(APP_PDP_PAGE, 'utf-8')
        for (const key of REQUIRED_KEYS) {
          const re = new RegExp(`['"]${key}['"]\\s*:`, 'm')
          expect(
            re.test(source),
            `SKELETON_MAP in app/produkt/[slug]/page.tsx must include key "${key}"`,
          ).toBe(true)
        }
      },
    )
  })

  describe('AC-2.20: Marketing-honesty in PDP YAML', () => {
    it(
      'AC-2.20a: GIVEN themes/kleinstadtpflanze/pages/product.yaml is parsed ' +
        'WHEN service-box, trust-badges, faq-accordion, brand-recap text fields are concatenated ' +
        'THEN none of the forbidden marketing strings appear ' +
        '(Klimaneutral, Made in Germany, Familienbetrieb, 4,9★, Bewertungen)',
      () => {
        const cfg = loadKpProduct()

        const collected: string[] = []
        for (const section of cfg.sections) {
          for (const block of section.blocks) {
            const props = block.params.props as Record<string, unknown> | undefined
            // service-box (which lives inside product-purchase params, NOT props)
            const sb = block.params.serviceBox as
              | { items?: { icon: string; label: string; detail: string }[] }
              | undefined
            if (sb?.items) {
              for (const item of sb.items) {
                collected.push(item.label, item.detail)
              }
            }
            if (block.type === 'trust-badges' && props) {
              const items = props.items as { text: string }[] | undefined
              if (items) {
                for (const i of items) collected.push(i.text)
              }
            }
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
          }
        }

        const concatenated = collected.join(' || ')
        for (const forbidden of FORBIDDEN_MARKETING_STRINGS) {
          expect(
            concatenated.includes(forbidden),
            `PDP YAML contains forbidden string "${forbidden}". Collected: ${concatenated}`,
          ).toBe(false)
        }
      },
    )

    it(
      'AC-2.20b: GIVEN the entire kleinstadtpflanze product.yaml file (all string values) ' +
        'WHEN scanned for forbidden marketing strings ' +
        'THEN none of the forbidden strings appear anywhere in the file ' +
        '(catches honesty violations even outside structured fields)',
      () => {
        const raw = readFileSync(KP_PRODUCT_YAML, 'utf-8')
        for (const forbidden of FORBIDDEN_MARKETING_STRINGS) {
          expect(
            raw.includes(forbidden),
            `product.yaml contains forbidden string "${forbidden}"`,
          ).toBe(false)
        }
      },
    )
  })
})
