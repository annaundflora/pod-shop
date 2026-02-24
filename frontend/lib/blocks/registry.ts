// frontend/lib/blocks/registry.ts
import type { ComponentType } from 'react'
import type { BlockComponentProps } from './types'
import { HeroBlock } from '@/components/blocks/hero-block'
import { ProductGridBlock } from '@/components/blocks/product-grid-block'
import { CategoryShowcaseBlock } from '@/components/blocks/category-showcase-block'
import { UspBarBlock } from '@/components/blocks/usp-bar-block'

// Typisiertes Registry-Map
type BlockComponent = ComponentType<BlockComponentProps<unknown>>

const registry: Record<string, BlockComponent> = {
  'hero': HeroBlock as BlockComponent,
  'product-grid': ProductGridBlock as BlockComponent,
  'category-showcase': CategoryShowcaseBlock as BlockComponent,
  'usp-bar': UspBarBlock as BlockComponent,
}

/**
 * Resolves a block type string to a React Component.
 * Returns null and logs a warning for unknown types.
 */
export function resolveBlock(type: string): BlockComponent | null {
  const component = registry[type]
  if (!component) {
    console.warn(`Unknown block type: ${type}`)
    return null
  }
  return component
}

export { registry }
