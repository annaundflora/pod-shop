// frontend/components/blocks/product-recommendations-block.tsx
import { ProductCard } from '@/components/product/product-card'
import { ProductGridBlockSkeleton } from '@/components/blocks/product-grid-block'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductCardData } from '@/lib/graphql/types'

interface ProductRecommendationsData {
  heading: string
  products: { nodes: ProductCardData[] } | null
}

export function ProductRecommendationsBlock({ data }: BlockComponentProps<ProductRecommendationsData | null>) {
  const products = data?.products?.nodes ?? []

  // Guard: kein Heading ohne Inhalt, kein leerer Container
  if (!data || products.length === 0) return null

  const headingId = 'recommendations-heading'

  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} className="text-2xl font-semibold tracking-tight mb-6">
        {data.heading}
      </h2>
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {products.map((product) => (
          <ProductCard key={product.id} product={product} />
        ))}
      </div>
    </section>
  )
}

export { ProductGridBlockSkeleton as ProductRecommendationsBlockSkeleton }
