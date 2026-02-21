// frontend/components/product/add-to-cart-button.tsx
'use client'

import { useState, useEffect } from 'react'

interface AddToCartButtonProps {
  productId: number
  variationId: number | null
  canAdd: boolean
  hasVariants: boolean
  onAddToCart?: (productId: number, variationId: number | null) => Promise<void>
  isLoading?: boolean
}

const SUCCESS_DURATION_MS = 2000

export function AddToCartButton({
  productId,
  variationId,
  canAdd,
  hasVariants,
  onAddToCart,
  isLoading = false,
}: AddToCartButtonProps) {
  const [isSuccess, setIsSuccess] = useState(false)

  // Erfolgs-State nach SUCCESS_DURATION_MS automatisch zuruecksetzen
  useEffect(() => {
    if (!isSuccess) return
    const timer = setTimeout(() => setIsSuccess(false), SUCCESS_DURATION_MS)
    return () => clearTimeout(timer)
  }, [isSuccess])

  const handleClick = async () => {
    if (!canAdd || isLoading || isSuccess) return
    if (onAddToCart) {
      await onAddToCart(productId, variationId)
      setIsSuccess(true)
    }
  }

  const isDisabled = !canAdd || isLoading || isSuccess

  let label: string
  if (isSuccess) {
    label = 'Hinzugefuegt!'
  } else if (isLoading) {
    label = 'Wird hinzugefuegt...'
  } else if (!canAdd && hasVariants) {
    label = 'Variante waehlen'
  } else {
    label = 'In den Warenkorb'
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      disabled={isDisabled}
      aria-disabled={isDisabled}
      aria-live="polite"
      className={[
        'w-full py-3 px-6 font-medium rounded-lg transition-colors',
        'focus-visible:ring-2 focus-visible:ring-offset-2',
        'disabled:cursor-not-allowed',
        isSuccess
          ? 'bg-green-600 text-white focus-visible:ring-green-500'
          : 'bg-primary text-white hover:bg-primary-hover focus-visible:ring-primary disabled:opacity-50',
      ].join(' ')}
      style={{ touchAction: 'manipulation', minHeight: '2.75rem' }}
    >
      {isSuccess ? (
        <span className="flex items-center justify-center gap-2">
          {/* Checkmark Icon */}
          <svg
            aria-hidden="true"
            width="18"
            height="18"
            viewBox="0 0 18 18"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <polyline points="3 9 7 13 15 5" />
          </svg>
          {label}
        </span>
      ) : (
        label
      )}
    </button>
  )
}
