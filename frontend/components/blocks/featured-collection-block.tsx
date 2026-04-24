// frontend/components/blocks/featured-collection-block.tsx
import Image from 'next/image'
import { ProductCard } from '@/components/product/product-card'
import type { BlockComponentProps, FeaturedCollectionData } from '@/lib/blocks/types'

export function FeaturedCollectionBlock({ data }: BlockComponentProps<FeaturedCollectionData | null>) {
  const products = data?.products?.nodes ?? []

  if (!data || products.length === 0) return null

  const { category } = data

  return (
    <section aria-label={category.name}>
      {/* Kategorie-Bild */}
      {category.image?.sourceUrl && (
        <div className="relative w-full overflow-hidden rounded-card mb-6" style={{ height: '400px' }}>
          <Image
            src={category.image.sourceUrl}
            alt={category.image.altText || category.name}
            fill
            sizes="(max-width: 768px) 100vw, 1200px"
            className="object-cover"
            loading="lazy"
          />
        </div>
      )}

      {/* Kategorie-Info */}
      <div className="mb-6">
        <h2 className="text-2xl font-semibold text-text-primary mb-2">
          {category.name}
        </h2>
        {category.description && (
          <p className="text-text-secondary text-sm">{category.description}</p>
        )}
        <a
          href={`/kategorie/${category.slug}`}
          aria-label={`Alle ${category.name} ansehen`}
          className="inline-block mt-3 text-primary text-sm font-medium hover:underline"
        >
          Alle ansehen →
        </a>
      </div>

      {/* Produkt-Grid */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {products.map((product) => (
          <ProductCard key={product.id} product={product} />
        ))}
      </div>
    </section>
  )
}

export function FeaturedCollectionBlockSkeleton() {
  return (
    <div className="space-y-4">
      <div className="w-full h-48 bg-surface-sunken rounded-card animate-pulse" />
      <div className="h-6 w-48 bg-surface-sunken rounded animate-pulse" />
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {[1, 2, 3, 4].map(i => (
          <div key={i} className="aspect-product bg-surface-sunken rounded-card animate-pulse" />
        ))}
      </div>
    </div>
  )
}
