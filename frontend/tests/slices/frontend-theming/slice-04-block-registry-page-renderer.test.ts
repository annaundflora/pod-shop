// tests/slices/frontend-theming/slice-04-block-registry-page-renderer.test.ts
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { existsSync, readFileSync } from 'fs'
import { resolve } from 'path'

const FRONTEND_ROOT = resolve(__dirname, '..', '..', '..')
const PROJECT_ROOT = resolve(FRONTEND_ROOT, '..')

// --- Helper ---

function readFile(relativePath: string): string {
  return readFileSync(resolve(FRONTEND_ROOT, relativePath), 'utf-8')
}

function fileExists(relativePath: string): boolean {
  return existsSync(resolve(FRONTEND_ROOT, relativePath))
}

// --- Datei-Existenz Tests ---

describe('Slice 4 — Datei-Existenz', () => {
  const requiredFiles = [
    'lib/blocks/types.ts',
    'lib/blocks/registry.ts',
    'lib/blocks/data-loaders.ts',
    'lib/blocks/page-config.ts',
    'components/blocks/hero-block.tsx',
    'components/blocks/product-grid-block.tsx',
    'components/blocks/category-showcase-block.tsx',
    'components/blocks/usp-bar-block.tsx',
    'themes/default/pages/home.yaml',
  ]

  for (const file of requiredFiles) {
    it(`should have ${file}`, () => {
      expect(fileExists(file)).toBe(true)
    })
  }

  it('should have wordpress/plugins/wp-custom-fields/wp-custom-fields.php', () => {
    expect(
      existsSync(resolve(PROJECT_ROOT, 'wordpress/plugins/wp-custom-fields/wp-custom-fields.php'))
    ).toBe(true)
  })

  it('should have wordpress/plugins/wp-custom-fields/includes/class-custom-fields.php', () => {
    expect(
      existsSync(resolve(PROJECT_ROOT, 'wordpress/plugins/wp-custom-fields/includes/class-custom-fields.php'))
    ).toBe(true)
  })
})

// --- Types Tests ---

describe('lib/blocks/types.ts — TypeScript-Typen vorhanden', () => {
  it('should export ContentSource type', () => {
    const content = readFile('lib/blocks/types.ts')
    expect(content).toContain('ContentSource')
    expect(content).toContain("'wordpress'")
    expect(content).toContain("'woocommerce'")
    expect(content).toContain("'inline'")
  })

  it('should export BlockConfig interface', () => {
    const content = readFile('lib/blocks/types.ts')
    expect(content).toContain('BlockConfig')
    expect(content).toContain('content_source')
    expect(content).toContain('params')
  })

  it('should export PageConfig interface with blocks array', () => {
    const content = readFile('lib/blocks/types.ts')
    expect(content).toContain('PageConfig')
    expect(content).toContain('blocks')
  })

  it('should export WPCustomFieldsData interface with all hero fields', () => {
    const content = readFile('lib/blocks/types.ts')
    expect(content).toContain('WPCustomFieldsData')
    expect(content).toContain('heroHeadline')
    expect(content).toContain('heroSubline')
    expect(content).toContain('heroCtaText')
    expect(content).toContain('heroCtaLink')
    expect(content).toContain('heroBackgroundImage')
    expect(content).toContain('seoMetaDescription')
  })
})

// --- Block Registry Tests ---
// AC-1: resolveBlock('hero') gibt HeroBlock Component zurueck; resolveBlock('unknown-type') gibt null zurueck UND ruft console.warn auf

describe('lib/blocks/registry.ts — Block Registry', () => {
  it('should export resolveBlock function', () => {
    const content = readFile('lib/blocks/registry.ts')
    expect(content).toContain('resolveBlock')
  })

  it('should register all 4 block types', () => {
    const content = readFile('lib/blocks/registry.ts')
    expect(content).toContain("'hero'")
    expect(content).toContain("'product-grid'")
    expect(content).toContain("'category-showcase'")
    expect(content).toContain("'usp-bar'")
  })

  it('AC-1: resolveBlock should return null and console.warn for unknown type', async () => {
    /**
     * AC-1: GIVEN resolveBlock is called
     * WHEN the type is 'unknown-type'
     * THEN it returns null AND calls console.warn('Unknown block type: unknown-type')
     */
    const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const { resolveBlock } = await import('@/lib/blocks/registry')

    const result = resolveBlock('unknown-block-xyz')
    expect(result).toBeNull()
    expect(consoleSpy).toHaveBeenCalledWith(expect.stringContaining('Unknown block type'))
    expect(consoleSpy).toHaveBeenCalledWith(expect.stringContaining('unknown-block-xyz'))

    consoleSpy.mockRestore()
  })

  it('AC-1: resolveBlock should return a component for known block types', async () => {
    /**
     * AC-1: GIVEN resolveBlock is called
     * WHEN the type is 'hero'
     * THEN it returns the HeroBlock component (not null, typeof function)
     */
    const { resolveBlock } = await import('@/lib/blocks/registry')

    const heroComponent = resolveBlock('hero')
    expect(heroComponent).not.toBeNull()
    expect(typeof heroComponent).toBe('function')

    const productGridComponent = resolveBlock('product-grid')
    expect(productGridComponent).not.toBeNull()

    const categoryShowcaseComponent = resolveBlock('category-showcase')
    expect(categoryShowcaseComponent).not.toBeNull()

    const uspBarComponent = resolveBlock('usp-bar')
    expect(uspBarComponent).not.toBeNull()
  })
})

// --- Data Loaders Tests ---
// AC-8: lib/blocks/data-loaders.ts enthaelt GET_PAGE_CUSTOM_FIELDS Query LOKAL (nicht importiert aus lib/graphql/queries.ts)

describe('lib/blocks/data-loaders.ts — Data Loaders', () => {
  it('should export loadBlockData function', () => {
    const content = readFile('lib/blocks/data-loaders.ts')
    expect(content).toContain('loadBlockData')
  })

  it('should import getClient from lib/apollo/server-client', () => {
    const content = readFile('lib/blocks/data-loaders.ts')
    expect(content).toContain("from '@/lib/apollo/server-client'")
  })

  it('should reuse GET_FEATURED_PRODUCTS and GET_PRODUCT_CATEGORIES queries', () => {
    const content = readFile('lib/blocks/data-loaders.ts')
    expect(content).toContain('GET_FEATURED_PRODUCTS')
    expect(content).toContain('GET_PRODUCT_CATEGORIES')
  })

  it('AC-8: GET_PAGE_CUSTOM_FIELDS query should be defined locally, not imported from lib/graphql/queries.ts', () => {
    /**
     * AC-8: GIVEN lib/blocks/data-loaders.ts exists
     * WHEN we inspect the source
     * THEN GET_PAGE_CUSTOM_FIELDS is defined locally (const GET_PAGE_CUSTOM_FIELDS = gql`...)
     * AND it is NOT imported from lib/graphql/queries.ts
     */
    const content = readFile('lib/blocks/data-loaders.ts')
    expect(content).toContain('GET_PAGE_CUSTOM_FIELDS')
    // Must be locally defined with gql tag
    expect(content).toMatch(/const\s+GET_PAGE_CUSTOM_FIELDS\s*=\s*gql/)
    // Must NOT be imported from queries.ts
    const importLines = content.split('\n').filter(line => line.includes('import') && line.includes('GET_PAGE_CUSTOM_FIELDS'))
    expect(importLines.length).toBe(0)
  })

  it('inlineLoader: should return props directly without GraphQL call', async () => {
    const { inlineLoader } = await import('@/lib/blocks/data-loaders')
    const params = { props: { items: [{ icon: 'truck', text: 'Kostenloser Versand' }] } }
    const result = inlineLoader(params)
    expect(result.data).toEqual(params.props)
  })

  it('loadBlockData with inline source: should call inlineLoader', async () => {
    const { loadBlockData } = await import('@/lib/blocks/data-loaders')
    const params = { props: { items: [] } }
    const result = await loadBlockData('inline', params)
    expect(result.data).toEqual(params.props)
    expect(result.error).toBeUndefined()
  })

  it('loadBlockData with wordpress source: source code should use getClient and GraphQL', () => {
    // Verify the wordpress loader path uses getClient and GET_PAGE_CUSTOM_FIELDS
    const content = readFile('lib/blocks/data-loaders.ts')
    expect(content).toContain("case 'wordpress'")
    expect(content).toContain('wordpressLoader')
    expect(content).toContain('getClient().query')
    expect(content).toContain('GET_PAGE_CUSTOM_FIELDS')
  })

  it('loadBlockData with woocommerce source: source code should use GET_FEATURED_PRODUCTS', () => {
    // Verify the woocommerce loader path uses correct queries
    const content = readFile('lib/blocks/data-loaders.ts')
    expect(content).toContain("case 'woocommerce'")
    expect(content).toContain('woocommerceLoader')
    expect(content).toContain('GET_FEATURED_PRODUCTS')
    expect(content).toContain('GET_PRODUCT_CATEGORIES')
  })

  it('loadBlockData with invalid source: should return null data with error', async () => {
    const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const { loadBlockData } = await import('@/lib/blocks/data-loaders')

    const result = await loadBlockData('invalid-source' as unknown as 'inline', { props: {} })
    expect(result.data).toBeNull()
    expect(result.error).toBeDefined()

    consoleSpy.mockRestore()
  })
})

// --- Page Config Loader Tests ---
// AC-2: loadPageConfigFromYaml('default', 'home') liest themes/default/pages/home.yaml und gibt gueltiges PageConfig-Objekt zurueck

describe('lib/blocks/page-config.ts — Page Config Loader', () => {
  it('should export loadPageConfig function', () => {
    const content = readFile('lib/blocks/page-config.ts')
    expect(content).toContain('loadPageConfig')
  })

  it('should use fs.readFileSync (NOT fetch) for YAML loading', () => {
    const content = readFile('lib/blocks/page-config.ts')
    expect(content).toContain('readFileSync')
    expect(content).not.toContain('fetch(')
  })

  it('should import yaml parse for YAML parsing', () => {
    const content = readFile('lib/blocks/page-config.ts')
    expect(content).toContain('yaml')
    expect(content).toContain('parse')
  })

  it('AC-2: loadPageConfig should load and parse home.yaml', async () => {
    /**
     * AC-2: GIVEN loadPageConfig is called with ('home', 'default')
     * WHEN the function reads themes/default/pages/home.yaml
     * THEN it returns a valid PageConfig object with blocks array
     */
    const { loadPageConfig } = await import('@/lib/blocks/page-config')
    const config = loadPageConfig('home', 'default')

    const allBlocks = config.sections.flatMap(s => s.blocks)
    expect(config).toBeDefined()
    expect(config.sections).toBeDefined()
    expect(Array.isArray(config.sections)).toBe(true)
    expect(allBlocks.length).toBeGreaterThan(0)
  })

  it('AC-2: loadPageConfig home.yaml should have hero block as first block', async () => {
    const { loadPageConfig } = await import('@/lib/blocks/page-config')
    const config = loadPageConfig('home', 'default')

    const firstBlock = config.sections[0].blocks[0]
    expect(firstBlock.type).toBe('hero')
    expect(firstBlock.content_source).toBe('wordpress')
  })

  it('AC-2: loadPageConfig home.yaml should have product-grid block', async () => {
    const { loadPageConfig } = await import('@/lib/blocks/page-config')
    const config = loadPageConfig('home', 'default')

    const allBlocks = config.sections.flatMap(s => s.blocks)
    const productGridBlock = allBlocks.find(b => b.type === 'product-grid')
    expect(productGridBlock).toBeDefined()
    expect(productGridBlock?.content_source).toBe('woocommerce')
  })

  it('AC-2: loadPageConfig home.yaml should have usp-bar block with inline content_source', async () => {
    const { loadPageConfig } = await import('@/lib/blocks/page-config')
    const config = loadPageConfig('home', 'default')

    const allBlocks = config.sections.flatMap(s => s.blocks)
    const uspBlock = allBlocks.find(b => b.type === 'usp-bar')
    expect(uspBlock).toBeDefined()
    expect(uspBlock?.content_source).toBe('inline')
  })

  it('loadPageConfig: should fall back to default theme when non-existent theme is given', async () => {
    const consoleSpy = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const { loadPageConfig } = await import('@/lib/blocks/page-config')

    const config = loadPageConfig('home', 'non-existent-theme-xyz')
    const allBlocks = config.sections.flatMap(s => s.blocks)
    expect(config).toBeDefined()
    expect(allBlocks.length).toBeGreaterThan(0)
    expect(consoleSpy).toHaveBeenCalledWith(expect.stringContaining('non-existent-theme-xyz'))

    consoleSpy.mockRestore()
  })
})

// --- home.yaml Schema Tests ---
// AC-3: themes/default/pages/home.yaml enthaelt mindestens einen Block-Eintrag

describe('themes/default/pages/home.yaml — Schema', () => {
  it('AC-3: should parse as valid YAML with blocks array (at least one block)', async () => {
    /**
     * AC-3: GIVEN themes/default/pages/home.yaml exists
     * WHEN parsed as YAML
     * THEN it contains at least one block entry
     */
    const { parse } = await import('yaml')
    const content = readFile('themes/default/pages/home.yaml')
    const config = parse(content)

    expect(config).toBeDefined()
    expect(Array.isArray(config.blocks)).toBe(true)
    expect(config.blocks.length).toBeGreaterThanOrEqual(3)
  })

  it('all blocks should have required fields: type, content_source, params', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/default/pages/home.yaml')
    const config = parse(content)

    for (const block of config.blocks) {
      expect(block.type, `Block missing type`).toBeDefined()
      expect(block.content_source, `Block "${block.type}" missing content_source`).toBeDefined()
      expect(block.params, `Block "${block.type}" missing params`).toBeDefined()
    }
  })

  it('content_source must be one of wordpress | woocommerce | inline', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/default/pages/home.yaml')
    const config = parse(content)
    const validSources = ['wordpress', 'woocommerce', 'inline']

    for (const block of config.blocks) {
      expect(
        validSources.includes(block.content_source),
        `Invalid content_source "${block.content_source}" for block "${block.type}"`
      ).toBe(true)
    }
  })

  it('wordpress blocks must have page_slug param', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/default/pages/home.yaml')
    const config = parse(content)

    for (const block of config.blocks) {
      if (block.content_source === 'wordpress') {
        expect(
          block.params.page_slug,
          `Block "${block.type}" with content_source "wordpress" must have page_slug param`
        ).toBeDefined()
      }
    }
  })

  it('woocommerce blocks must have query param', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/default/pages/home.yaml')
    const config = parse(content)

    for (const block of config.blocks) {
      if (block.content_source === 'woocommerce') {
        expect(
          block.params.query,
          `Block "${block.type}" with content_source "woocommerce" must have query param`
        ).toBeDefined()
      }
    }
  })

  it('inline blocks must have props param', async () => {
    const { parse } = await import('yaml')
    const content = readFile('themes/default/pages/home.yaml')
    const config = parse(content)

    for (const block of config.blocks) {
      if (block.content_source === 'inline') {
        expect(
          block.params.props,
          `Block "${block.type}" with content_source "inline" must have props param`
        ).toBeDefined()
      }
    }
  })
})

// --- Block Components Tests ---
// AC-4: HeroBlock rendert mit Fallback-Werten wenn keine Props uebergeben werden
// AC-5: UspBarBlock rendert ohne Suspense-Wrapper

describe('Block Components — Datei-Inhalt-Assertions', () => {
  it('hero-block.tsx: should export HeroBlock and HeroBlockSkeleton', () => {
    const content = readFile('components/blocks/hero-block.tsx')
    expect(content).toContain('export function HeroBlock')
    expect(content).toContain('export function HeroBlockSkeleton')
  })

  it('hero-block.tsx: should use Skeleton from ui/skeleton', () => {
    const content = readFile('components/blocks/hero-block.tsx')
    expect(content).toContain("from '@/components/ui/skeleton'")
  })

  it('AC-4: hero-block.tsx should have fallback values for empty WP fields', () => {
    /**
     * AC-4: GIVEN HeroBlock receives no props (null data)
     * WHEN it renders
     * THEN it uses fallback values for heroHeadline, heroSubline, heroCtaText, heroCtaLink
     */
    const content = readFile('components/blocks/hero-block.tsx')
    // Fallback-Werte fuer alle Hero-Felder
    expect(content).toContain('??')
    expect(content).toContain('heroHeadline')
    expect(content).toContain('heroSubline')
    expect(content).toContain('heroCtaText')
    expect(content).toContain('heroCtaLink')
  })

  it('hero-block.tsx: should NOT use hardcoded Tailwind color classes', () => {
    const content = readFile('components/blocks/hero-block.tsx')
    expect(content).not.toMatch(/\b(bg|text|border|ring)-(red|green|blue|black)-\d{3}\b/)
    expect(content).not.toContain('bg-black/')
  })

  it('product-grid-block.tsx: should export ProductGridBlock and ProductGridBlockSkeleton', () => {
    const content = readFile('components/blocks/product-grid-block.tsx')
    expect(content).toContain('export function ProductGridBlock')
    expect(content).toContain('export function ProductGridBlockSkeleton')
  })

  it('product-grid-block.tsx: should import ProductCard from components/product', () => {
    const content = readFile('components/blocks/product-grid-block.tsx')
    expect(content).toContain("from '@/components/product/product-card'")
  })

  it('category-showcase-block.tsx: should export CategoryShowcaseBlock and CategoryShowcaseBlockSkeleton', () => {
    const content = readFile('components/blocks/category-showcase-block.tsx')
    expect(content).toContain('export function CategoryShowcaseBlock')
    expect(content).toContain('export function CategoryShowcaseBlockSkeleton')
  })

  it('AC-5: usp-bar-block.tsx should export UspBarBlock (renders without Suspense wrapper)', () => {
    /**
     * AC-5: GIVEN UspBarBlock is an inline/synchronous block
     * WHEN rendered
     * THEN it does NOT need a Suspense wrapper (no async data loading)
     */
    const content = readFile('components/blocks/usp-bar-block.tsx')
    expect(content).toContain('export function UspBarBlock')
    // UspBarBlock should NOT contain Suspense
    expect(content).not.toContain('Suspense')
  })

  it('usp-bar-block.tsx: should import lucide-react icons', () => {
    const content = readFile('components/blocks/usp-bar-block.tsx')
    expect(content).toContain("from 'lucide-react'")
  })

  it('usp-bar-block.tsx: icons have aria-hidden="true"', () => {
    const content = readFile('components/blocks/usp-bar-block.tsx')
    expect(content).toContain('aria-hidden')
  })
})

// --- app/page.tsx — PageRenderer Tests ---
// AC-6: app/page.tsx enthaelt export const revalidate = 60

describe('app/page.tsx — PageRenderer (Block-System)', () => {
  it('should import loadPageConfig from lib/blocks/page-config', () => {
    const content = readFile('app/page.tsx')
    expect(content).toContain('loadPageConfig')
    expect(content).toContain('lib/blocks/page-config')
  })

  it('should import resolveBlock from lib/blocks/registry', () => {
    const content = readFile('app/page.tsx')
    expect(content).toContain('resolveBlock')
  })

  it('should import loadBlockData from lib/blocks/data-loaders', () => {
    const content = readFile('app/page.tsx')
    expect(content).toContain('loadBlockData')
  })

  it('AC-6: should still have ISR revalidate = 60', () => {
    /**
     * AC-6: GIVEN app/page.tsx is the homepage
     * WHEN we inspect the source
     * THEN it contains export const revalidate = 60
     */
    const content = readFile('app/page.tsx')
    expect(content).toContain('revalidate')
    expect(content).toContain('60')
  })

  it('should wrap async blocks in Suspense', () => {
    const content = readFile('app/page.tsx')
    expect(content).toContain('Suspense')
  })

  it('should NOT have hardcoded Hero-Text anymore', () => {
    const content = readFile('app/page.tsx')
    // Hardcoded Hero-Inhalte wurden in HeroBlock-Component ausgelagert
    expect(content).not.toContain('Einzigartige Designs')
    expect(content).not.toContain('Print-on-Demand')
  })
})

// --- WP Custom Fields Plugin Tests ---
// AC-7: WP Plugin wp-custom-fields.php existiert und enthaelt register_post_meta und register_graphql_field Calls

describe('WP Custom Fields Plugin — PHP-Dateien', () => {
  const PLUGIN_ROOT = resolve(PROJECT_ROOT, 'wordpress/plugins/wp-custom-fields')

  it('AC-7: wp-custom-fields.php should have Plugin Name header', () => {
    /**
     * AC-7: GIVEN wordpress/plugins/wp-custom-fields/wp-custom-fields.php exists
     * WHEN we inspect its contents
     * THEN it contains register_post_meta and register_graphql_field calls
     */
    const content = readFileSync(resolve(PLUGIN_ROOT, 'wp-custom-fields.php'), 'utf-8')
    expect(content).toContain('Plugin Name')
  })

  it('AC-7: wp-custom-fields.php should hook into init for register_post_meta', () => {
    const content = readFileSync(resolve(PLUGIN_ROOT, 'wp-custom-fields.php'), 'utf-8')
    expect(content).toContain('init')
    expect(content).toContain('register_post_meta_fields')
  })

  it('AC-7: wp-custom-fields.php should hook into graphql_register_types', () => {
    const content = readFileSync(resolve(PLUGIN_ROOT, 'wp-custom-fields.php'), 'utf-8')
    expect(content).toContain('graphql_register_types')
    expect(content).toContain('register_graphql_fields')
  })

  it('class-custom-fields.php: should register all 6 meta keys', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    expect(content).toContain('hero_headline')
    expect(content).toContain('hero_subline')
    expect(content).toContain('hero_cta_text')
    expect(content).toContain('hero_cta_link')
    expect(content).toContain('hero_background_image')
    expect(content).toContain('seo_meta_description')
  })

  it('class-custom-fields.php: should call register_post_meta()', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    expect(content).toContain('register_post_meta')
  })

  it('class-custom-fields.php: should call register_graphql_field()', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    expect(content).toContain('register_graphql_field')
  })

  it('class-custom-fields.php: should use sanitize_text_field for text fields', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    expect(content).toContain('sanitize_text_field')
  })

  it('class-custom-fields.php: should use esc_url_raw for URL fields', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    expect(content).toContain('esc_url_raw')
  })

  it('class-custom-fields.php: seo_meta_description should be registered for both page and post types', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    const seoIndex = content.indexOf('seo_meta_description')
    expect(seoIndex).toBeGreaterThan(-1)
    expect(content).toContain("'page'")
    expect(content).toContain("'post'")
  })

  it('class-custom-fields.php: hero fields graphql names match architecture.md spec', () => {
    const content = readFileSync(
      resolve(PLUGIN_ROOT, 'includes/class-custom-fields.php'),
      'utf-8'
    )
    expect(content).toContain('heroHeadline')
    expect(content).toContain('heroSubline')
    expect(content).toContain('heroCtaText')
    expect(content).toContain('heroCtaLink')
    expect(content).toContain('heroBackgroundImage')
    expect(content).toContain('seoMetaDescription')
  })
})
