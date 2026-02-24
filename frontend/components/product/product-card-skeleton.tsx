// frontend/components/product/product-card-skeleton.tsx
import { Skeleton } from '@/components/ui/skeleton'

export function ProductCardSkeleton() {
  return (
    <div
      className="rounded-[var(--radius-card,0.75rem)] overflow-hidden border border-border bg-surface"
      aria-label="Produkt wird geladen"
    >
      <Skeleton className="aspect-square w-full rounded-none" />
      <div className="p-3 space-y-2">
        <Skeleton className="h-4 w-3/4" />
        <Skeleton className="h-4 w-1/3" />
      </div>
    </div>
  )
}
