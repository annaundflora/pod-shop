'use client'

import { ProductImageGallery } from '@/components/product/product-image-gallery'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductDetailData } from '@/lib/graphql/types'

type ProductGalleryData = ProductDetailData & {
  layout?: 'default' | 'vertical-thumbs'
}

export function ProductGalleryBlock({ data }: BlockComponentProps<ProductGalleryData | null>) {
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

  const layout = data.layout === 'vertical-thumbs' ? 'vertical-thumbs' : 'default'

  return (
    <ProductImageGallery
      images={allImages}
      productName={data.name}
      layout={layout}
    />
  )
}

export function ProductGalleryBlockSkeleton() {
  return (
    <div className="aspect-product bg-surface-elevated rounded-card animate-pulse" />
  )
}
