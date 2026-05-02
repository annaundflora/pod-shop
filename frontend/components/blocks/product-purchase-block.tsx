'use client'

import { ProductVariantSelector } from '@/app/produkt/[slug]/product-variant-selector'
import { ServiceBoxBlock } from '@/components/blocks/service-box-block'
import { formatPrice } from '@/lib/format-price'
import { extractVariantOptions } from '@/lib/product/variant-utils'
import type { BlockComponentProps, ServiceBoxData } from '@/lib/blocks/types'
import type { ProductDetailData } from '@/lib/graphql/types'

type ProductPurchaseData = ProductDetailData & {
  withDescription?: boolean
  serviceBox?: ServiceBoxData
}

export function ProductPurchaseBlock({ data }: BlockComponentProps<ProductPurchaseData | null>) {
  if (!data) {
    return (
      <div className="text-text-secondary">Produkt nicht gefunden</div>
    )
  }

  const variantOptions = data.variations
    ? extractVariantOptions(data.variations.nodes)
    : { sizes: [], colors: [] }

  const hasDescription = Boolean(data.withDescription) && Boolean(data.description)
  const hasServiceBox = Boolean(
    data.serviceBox && Array.isArray(data.serviceBox.items) && data.serviceBox.items.length > 0
  )

  return (
    <div className="mt-8 md:mt-0">
      <h1 className="text-3xl font-bold tracking-tight text-text-primary">
        {data.name}
      </h1>

      <div className="mt-4 flex items-baseline gap-3">
        <p className="text-3xl font-bold text-accent">
          {formatPrice(data.price)}
        </p>
      </div>
      <p className="mt-1 text-sm text-text-secondary">inkl. Versandkosten</p>

      <hr className="my-8 border-border" />

      <ProductVariantSelector
        product={data}
        variantOptions={variantOptions}
      />

      {hasDescription && (
        <div
          className="mt-8 prose prose-sm text-text-secondary max-w-none"
          dangerouslySetInnerHTML={{ __html: data.description }}
        />
      )}

      {hasServiceBox && (
        <div className="mt-8">
          <ServiceBoxBlock data={data.serviceBox ?? null} />
        </div>
      )}
    </div>
  )
}

export function ProductPurchaseBlockSkeleton() {
  return (
    <div className="mt-8 md:mt-0 space-y-4">
      <div className="h-9 bg-surface-elevated rounded-card animate-pulse w-3/4" />
      <div className="h-8 bg-surface-elevated rounded-card animate-pulse w-1/3" />
      <div className="h-px bg-border my-8" />
      <div className="h-12 bg-surface-elevated rounded-card animate-pulse w-full" />
    </div>
  )
}
