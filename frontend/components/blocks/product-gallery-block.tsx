'use client'

import { ProductImageGallery } from '@/components/product/product-image-gallery'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductDetailData } from '@/lib/graphql/types'

export function ProductGalleryBlock({ data }: BlockComponentProps<ProductDetailData | null>) {
  if (!data) {
    return (
      <div className="aspect-product bg-surface-elevated rounded-card flex items-center justify-center text-text-secondary">
        Produkt nicht gefunden
      </div>
    )
  }

  const allImages = [
    ...(data.image ? [data.image] : []),
    ...(data.galleryImages?.nodes ?? []),
  ]

  return (
    <ProductImageGallery
      images={allImages}
      productName={data.name}
    />
  )
}

export function ProductGalleryBlockSkeleton() {
  return (
    <div className="aspect-product bg-surface-elevated rounded-card animate-pulse" />
  )
}
