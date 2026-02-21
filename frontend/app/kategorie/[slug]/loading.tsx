// frontend/app/kategorie/[slug]/loading.tsx
import { ProductCardSkeleton } from '@/components/product/product-card-skeleton'

export default function CategoryPageLoading() {
  return (
    <main id="main-content">
      {/* Titel-Skeleton */}
      <div className="mb-6">
        <div className="h-9 w-48 bg-surface-elevated rounded animate-pulse" />
      </div>

      {/* Filter-Chips-Skeleton */}
      <div className="mb-6 flex gap-2 overflow-x-auto pb-2">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} className="h-10 w-24 bg-surface-elevated rounded-full flex-shrink-0 animate-pulse" />
        ))}
      </div>

      {/* Produktanzahl-Skeleton */}
      <div className="h-4 w-24 bg-surface-elevated rounded mb-6 animate-pulse" />

      {/* Produktgrid-Skeleton */}
      <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        {Array.from({ length: 8 }).map((_, i) => (
          <ProductCardSkeleton key={i} />
        ))}
      </div>
    </main>
  )
}
