// frontend/components/blocks/search-results-block.tsx
import type { PaginatedProductsResult } from '@/lib/blocks/types'
import type { BlockComponentProps } from '@/lib/blocks/types'
import { ProductCard } from '@/components/product/product-card'

export function SearchResultsBlock({ data }: BlockComponentProps<PaginatedProductsResult>) {
  const nodes = data?.products?.nodes ?? []

  if (nodes.length === 0) return null

  return (
    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
      {nodes.map((product) => (
        <ProductCard key={product.id} product={product} />
      ))}
    </div>
  )
}

// Reuse existing ProductGridBlockSkeleton for Suspense fallback
export { ProductGridBlockSkeleton as SearchResultsBlockSkeleton } from './product-grid-block'
