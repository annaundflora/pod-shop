// frontend/components/blocks/motif-cross-sell-block.tsx
import Image from 'next/image'
import Link from 'next/link'
import { Card } from '@/components/ui/card'
import { ProductGridBlockSkeleton } from '@/components/blocks/product-grid-block'
import type { BlockComponentProps, MotifCrossSellData } from '@/lib/blocks/types'

export function MotifCrossSellBlock({ data }: BlockComponentProps<MotifCrossSellData | null>) {
  // Graceful-null contract: tiles === null OR tiles === [] -> render nothing.
  if (!data || !data.tiles || data.tiles.length === 0) return null

  // Block-level guard: only internal product routes ("/produkt/...") are accepted.
  const safeTiles = data.tiles.filter(
    (tile) => typeof tile.href === 'string' && tile.href.startsWith('/produkt/')
  )

  if (safeTiles.length === 0) return null

  const heading = data.heading ?? 'Dieses Motiv auch auf …'

  return (
    <section aria-labelledby="motif-cross-sell-heading">
      <h2
        id="motif-cross-sell-heading"
        className="font-heading text-2xl sm:text-3xl text-text-primary mb-6"
      >
        {heading}
      </h2>
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {safeTiles.map((tile, index) => (
          <Card
            key={`${tile.href}-${index}`}
            variant="interactive"
            asChild
          >
            <Link
              href={tile.href}
              className="flex flex-col bg-surface-alt"
              style={{ touchAction: 'manipulation' } as React.CSSProperties}
            >
              <div className="relative aspect-[4/3] w-full overflow-hidden">
                <Image
                  src={tile.image_url}
                  alt={tile.image_alt ?? `${tile.productName} – ${tile.productType}`}
                  fill
                  className="object-cover"
                  sizes="(min-width: 1024px) 25vw, 50vw"
                />
              </div>
              <div className="flex flex-col gap-1 p-3">
                <span className="font-medium text-text-primary truncate">
                  {tile.productName}
                </span>
                <span className="text-sm text-text-secondary">
                  {tile.productType}
                </span>
              </div>
            </Link>
          </Card>
        ))}
      </div>
    </section>
  )
}

export { ProductGridBlockSkeleton as MotifCrossSellBlockSkeleton }
