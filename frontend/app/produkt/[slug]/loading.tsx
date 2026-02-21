// frontend/app/produkt/[slug]/loading.tsx
export default function ProductPageLoading() {
  return (
    <main id="main-content">
      <div className="lg:grid lg:grid-cols-2 lg:gap-12">
        {/* Bild-Skeleton */}
        <div className="aspect-square bg-surface-elevated rounded-xl animate-pulse" />

        {/* Produkt-Info-Skeleton */}
        <div className="mt-6 lg:mt-0 space-y-4">
          {/* Titel */}
          <div className="h-8 w-3/4 bg-surface-elevated rounded animate-pulse" />
          {/* Preis */}
          <div className="h-8 w-1/3 bg-surface-elevated rounded animate-pulse" />
          {/* Versandkosten */}
          <div className="h-4 w-40 bg-surface-elevated rounded animate-pulse" />

          <hr className="border-border" />

          {/* Farb-Selector-Skeleton */}
          <div className="space-y-3">
            <div className="h-4 w-16 bg-surface-elevated rounded animate-pulse" />
            <div className="flex gap-3">
              {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="w-9 h-9 rounded-full bg-surface-elevated animate-pulse" />
              ))}
            </div>
          </div>

          {/* Groessen-Selector-Skeleton */}
          <div className="space-y-3">
            <div className="h-4 w-12 bg-surface-elevated rounded animate-pulse" />
            <div className="flex gap-2 flex-wrap">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="h-11 w-14 bg-surface-elevated rounded-lg animate-pulse" />
              ))}
            </div>
          </div>

          {/* Button-Skeleton */}
          <div className="h-13 w-full bg-surface-elevated rounded-xl animate-pulse" />
        </div>
      </div>
    </main>
  )
}
