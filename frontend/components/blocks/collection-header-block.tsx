// frontend/components/blocks/collection-header-block.tsx
import Image from 'next/image'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { CollectionHeaderData } from '@/lib/blocks/types'

export function CollectionHeaderBlock({
  data,
}: BlockComponentProps<CollectionHeaderData>) {
  return (
    <div className="relative w-full min-h-[40vh] flex items-end">
      {data.image ? (
        <>
          <Image
            src={data.image.sourceUrl}
            alt={data.image.altText}
            fill
            className="object-cover"
            priority
          />
          {/* Dunkles Overlay für Lesbarkeit */}
          <div className="absolute inset-0 bg-black/40" aria-hidden="true" />
        </>
      ) : (
        <div className="absolute inset-0 bg-secondary" aria-hidden="true" />
      )}
      <div className="relative z-10 container mx-auto px-4 py-10">
        <h1
          className={`text-3xl md:text-5xl font-bold ${
            data.image ? 'text-white' : 'text-text-primary'
          }`}
        >
          {data.name}
        </h1>
        {data.description && (
          <div
            className={`mt-3 text-lg max-w-2xl ${
              data.image ? 'text-white/90' : 'text-text-secondary'
            }`}
            dangerouslySetInnerHTML={{ __html: data.description }}
          />
        )}
      </div>
    </div>
  )
}

export function CollectionHeaderBlockSkeleton() {
  return (
    <div className="relative w-full min-h-[40vh] bg-gray-100 animate-pulse" />
  )
}
