// frontend/lib/blocks/registry.ts
import type { ComponentType } from 'react'
import type { BlockComponentProps } from './types'
import { HeroBlock } from '@/components/blocks/hero-block'
import { ProductGridBlock } from '@/components/blocks/product-grid-block'
import { CategoryShowcaseBlock } from '@/components/blocks/category-showcase-block'
import { UspBarBlock } from '@/components/blocks/usp-bar-block'
import { PageHeadingBlock } from '@/components/blocks/page-heading-block'
import { FilterChipsBlock } from '@/components/blocks/filter-chips-block'
import { ProductCountBlock } from '@/components/blocks/product-count-block'
import { ProductGalleryBlock } from '@/components/blocks/product-gallery-block'
import { ProductPurchaseBlock } from '@/components/blocks/product-purchase-block'
import { ProductDescriptionBlock } from '@/components/blocks/product-description-block'
import { LegalContentBlock } from '@/components/blocks/legal-content-block'
// Slice 01 — Cross-Page Infrastruktur:
import { AnnouncementBarBlock } from '@/components/blocks/announcement-bar-block'
import { BreadcrumbBlock } from '@/components/blocks/breadcrumb-block'
import { TrustBadgesBlock } from '@/components/blocks/trust-badges-block'
import { PaginationBlock } from '@/components/blocks/pagination-block'
import { SortBarBlock } from '@/components/blocks/sort-bar-block'
import { EmptyStateBlock } from '@/components/blocks/empty-state-block'
// Slice 02 — Produkt-Page Enhancements:
import { ProductReviewsBlock } from '@/components/blocks/product-reviews-block'
import { ProductRecommendationsBlock } from '@/components/blocks/product-recommendations-block'

// Typisiertes Registry-Map
type BlockComponent = ComponentType<BlockComponentProps<unknown>>

const registry: Record<string, BlockComponent> = {
  'hero': HeroBlock as BlockComponent,
  'product-grid': ProductGridBlock as BlockComponent,
  'category-showcase': CategoryShowcaseBlock as BlockComponent,
  'usp-bar': UspBarBlock as BlockComponent,
  // Neu in Slice 2:
  'page-heading': PageHeadingBlock as BlockComponent,
  'filter-chips': FilterChipsBlock as BlockComponent,
  'product-count': ProductCountBlock as BlockComponent,
  // Neu in Slice 3:
  'product-gallery': ProductGalleryBlock as BlockComponent,
  'product-purchase': ProductPurchaseBlock as BlockComponent,
  'product-description': ProductDescriptionBlock as BlockComponent,
  // Neu in Slice 4:
  'legal-content': LegalContentBlock as BlockComponent,
  // Neu in Slice 01 (Cross-Page Infrastruktur):
  'announcement-bar': AnnouncementBarBlock as BlockComponent,
  'breadcrumb': BreadcrumbBlock as BlockComponent,
  'trust-badges': TrustBadgesBlock as BlockComponent,
  'pagination': PaginationBlock as BlockComponent,
  'sort-bar': SortBarBlock as BlockComponent,
  'empty-state': EmptyStateBlock as BlockComponent,
  // Neu in Slice 02 (Produkt-Page Enhancements):
  'product-reviews': ProductReviewsBlock as BlockComponent,
  'product-recommendations': ProductRecommendationsBlock as BlockComponent,
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
