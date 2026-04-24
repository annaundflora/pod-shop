// frontend/app/produkt/[slug]/loading.tsx
export default function ProductPageLoading() {
  return (
    <main id="main-content">
      <div className="lg:grid lg:grid-cols-2 lg:gap-16">
        {/* Bild-Skeleton */}
        <div
          className="aspect-product bg-surface-elevated rounded-[var(--radius-card,0.75rem)] animate-pulse"
          style={{ boxShadow: 'var(--shadow-card)' }}
        />

        {/* Produkt-Info-Skeleton */}
        <div className="mt-8 lg:mt-0 space-y-4">
          {/* Titel */}
          <div className="h-9 w-3/4 bg-surface-elevated rounded-[var(--radius-button,0.5rem)] animate-pulse" />
          {/* Preis */}
          <div className="h-9 w-1/3 bg-surface-elevated rounded-[var(--radius-button,0.5rem)] animate-pulse" />
          {/* Versandkosten */}
          <div className="h-4 w-40 bg-surface-elevated rounded animate-pulse" />

          <hr className="border-border" />

          {/* Farb-Selector-Skeleton */}
          <div className="space-y-3">
            <div className="h-4 w-16 bg-surface-elevated rounded animate-pulse" />
            <div className="flex gap-3">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="w-10 h-10 rounded-full bg-surface-elevated animate-pulse" />
              ))}
            </div>
          </div>

          {/* Groessen-Selector-Skeleton */}
          <div className="space-y-3">
            <div className="h-4 w-12 bg-surface-elevated rounded animate-pulse" />
            <div className="flex gap-2 flex-wrap">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="h-11 w-14 bg-surface-elevated rounded-[var(--radius-button,0.5rem)] animate-pulse" />
              ))}
            </div>
          </div>

          {/* Button-Skeleton */}
          <div className="h-13 w-full bg-surface-elevated rounded-[var(--radius-button,0.5rem)] animate-pulse" />
        </div>
      </div>
    </main>
  )
}
