// frontend/components/blocks/category-showcase-block.tsx
import Image from 'next/image'
import Link from 'next/link'
import { Skeleton } from '@/components/ui/skeleton'
import { Card } from '@/components/ui/card'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductCategory } from '@/lib/graphql/types'

interface CategoryShowcaseBlockData {
  nodes: ProductCategory[]
  currentSlug?: string
}

export function CategoryShowcaseBlock({ data }: BlockComponentProps<CategoryShowcaseBlockData | null>) {
  const categories = data?.nodes ?? []

  if (categories.length === 0) return null

  return (
    <section aria-labelledby="categories-heading">
      <h2
        id="categories-heading"
        className="text-2xl font-semibold tracking-tight mb-6"
      >
        Kategorien
      </h2>
      <div className="flex flex-col gap-3">
        {categories.map((category) => (
          <Card
            key={category.id}
            variant="interactive"
            asChild
          >
            <Link
              href={`/kategorie/${category.slug}`}
              className="flex items-center gap-4 p-4"
              style={{ touchAction: 'manipulation' } as React.CSSProperties}
            >
              {category.image && (
                <div className="relative w-12 h-12 flex-shrink-0 rounded-lg overflow-hidden">
                  <Image
                    src={category.image.sourceUrl}
                    alt={category.image.altText || category.name}
                    fill
                    className="object-cover"
                    sizes="48px"
                  />
                </div>
              )}
              <span className="font-medium text-text-primary flex-1 min-w-0 truncate">
                {category.name}
              </span>
              <span aria-hidden="true" className="text-text-secondary flex-shrink-0">
                →
              </span>
            </Link>
          </Card>
        ))}
      </div>
    </section>
  )
}

export function CategoryShowcaseBlockSkeleton({ count = 3 }: { count?: number }) {
  return (
    <section aria-labelledby="categories-heading-loading">
      <Skeleton className="h-8 w-32 mb-6" />
      <div className="flex flex-col gap-3">
        {Array.from({ length: count }).map((_, i) => (
          <Skeleton key={i} className="h-16 w-full rounded-xl" />
        ))}
      </div>
    </section>
  )
}
