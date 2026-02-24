// frontend/components/blocks/product-grid-block.tsx
import { ProductCard } from '@/components/product/product-card'
import { ProductCardSkeleton } from '@/components/product/product-card-skeleton'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductCardData } from '@/lib/graphql/types'

interface ProductGridBlockData {
  products?: {
    nodes: ProductCardData[]
  } | null
}

export function ProductGridBlock({ data }: BlockComponentProps<ProductGridBlockData | null>) {
  const products = data?.products?.nodes ?? []

  if (products.length === 0) return null

  return (
    <section aria-labelledby="product-grid-heading">
      <h2
        id="product-grid-heading"
        className="text-2xl font-semibold tracking-tight mb-6"
      >
        Ausgewählte Produkte
      </h2>
      <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {products.map((product) => (
          <ProductCard key={product.id} product={product} />
        ))}
      </div>
    </section>
  )
}

export function ProductGridBlockSkeleton({ count = 4 }: { count?: number }) {
  return (
    <section aria-labelledby="product-grid-heading-loading">
      <h2
        id="product-grid-heading-loading"
        className="text-2xl font-semibold tracking-tight mb-6"
      >
        Ausgewählte Produkte
      </h2>
      <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {Array.from({ length: count }).map((_, i) => (
          <ProductCardSkeleton key={i} />
        ))}
      </div>
    </section>
  )
}
