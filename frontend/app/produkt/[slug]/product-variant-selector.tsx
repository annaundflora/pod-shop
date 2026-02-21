'use client'

import { useState } from 'react'
import { useCart } from '@/contexts/cart-context'
import { VariantSelectorSize } from '@/components/product/variant-selector-size'
import { VariantSelectorColor } from '@/components/product/variant-selector-color'
import { AddToCartButton } from '@/components/product/add-to-cart-button'
import { findVariation } from '@/lib/product/variant-utils'
import type { ProductDetailData, VariantOptions, SelectedVariant } from '@/lib/graphql/types'

interface ProductVariantSelectorProps {
  product: ProductDetailData
  variantOptions: VariantOptions
}

export function ProductVariantSelector({ product, variantOptions }: ProductVariantSelectorProps) {
  const [selectedVariant, setSelectedVariant] = useState<SelectedVariant>({
    size: null,
    color: null,
  })
  const { addToCart, isLoading } = useCart()

  const matchedVariation = findVariation(
    product.variations?.nodes ?? [],
    selectedVariant.size,
    selectedVariant.color,
  )

  const canAddToCart =
    matchedVariation !== null ||
    (variantOptions.sizes.length === 0 && variantOptions.colors.length === 0)

  const handleAddToCart = async (productId: number, variationId: number | null) => {
    await addToCart(productId, variationId, 1)
  }

  return (
    <div className="space-y-6">
      {/* Farb-Selector */}
      {variantOptions.colors.length > 0 && (
        <VariantSelectorColor
          colors={variantOptions.colors}
          selectedColor={selectedVariant.color}
          onColorSelect={(color) => setSelectedVariant((prev) => ({ ...prev, color }))}
        />
      )}

      {/* Groessen-Selector */}
      {variantOptions.sizes.length > 0 && (
        <VariantSelectorSize
          sizes={variantOptions.sizes}
          selectedSize={selectedVariant.size}
          onSizeSelect={(size) => setSelectedVariant((prev) => ({ ...prev, size }))}
        />
      )}

      {/* "In den Warenkorb"-Button */}
      <AddToCartButton
        productId={product.databaseId}
        variationId={matchedVariation?.databaseId ?? null}
        canAdd={canAddToCart}
        hasVariants={variantOptions.sizes.length > 0 || variantOptions.colors.length > 0}
        onAddToCart={handleAddToCart}
        isLoading={isLoading}
      />
    </div>
  )
}
