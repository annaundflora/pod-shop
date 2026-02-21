// frontend/components/product/product-card.tsx
import Image from 'next/image'
import Link from 'next/link'
import type { ProductCardData } from '@/lib/graphql/types'

interface ProductCardProps {
  product: ProductCardData
}

export function ProductCard({ product }: ProductCardProps) {
  const isSoldOut = product.stockStatus === 'OUT_OF_STOCK'

  return (
    <Link
      href={`/produkt/${product.slug}`}
      className="group block rounded-xl overflow-hidden border border-border bg-surface hover:shadow-md hover:-translate-y-0.5 transition-all duration-200 focus-visible:ring-2 focus-visible:ring-primary"
    >
      {/* Produktbild */}
      <div className="relative aspect-square bg-surface-elevated overflow-hidden">
        {product.image ? (
          <Image
            src={product.image.sourceUrl}
            alt={product.image.altText || product.name}
            fill
            sizes="(max-width: 640px) 50vw, (max-width: 1024px) 33vw, 25vw"
            className="object-cover group-hover:scale-105 transition-transform duration-300"
          />
        ) : (
          <div className="w-full h-full flex items-center justify-center text-text-secondary text-sm">
            Kein Bild
          </div>
        )}

        {/* Sold-Out Overlay */}
        {isSoldOut && (
          <div className="absolute inset-0 bg-black/50 flex items-center justify-center">
            <span className="text-white font-medium text-sm px-3 py-1 bg-black/70 rounded">
              Ausverkauft
            </span>
          </div>
        )}
      </div>

      {/* Produktinfos */}
      <div className="p-3">
        <h3 className="text-sm font-medium text-text-primary line-clamp-2 min-w-0">
          {product.name}
        </h3>
        <p className="mt-1 text-sm font-semibold text-text-primary tabular-nums">
          {product.price ?? '—'}
        </p>
      </div>
    </Link>
  )
}
