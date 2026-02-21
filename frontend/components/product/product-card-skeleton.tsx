// frontend/components/product/product-card-skeleton.tsx
export function ProductCardSkeleton() {
  return (
    <div
      className="rounded-xl overflow-hidden border border-border bg-surface animate-pulse"
      aria-label="loading"
    >
      <div className="aspect-square bg-surface-elevated" />
      <div className="p-3 space-y-2">
        <div className="h-4 bg-surface-elevated rounded w-3/4" />
        <div className="h-4 bg-surface-elevated rounded w-1/3" />
      </div>
    </div>
  )
}
