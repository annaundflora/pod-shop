'use client'

import Image from 'next/image'
import { useCart } from '@/contexts/cart-context'
import { QuantityStepper } from './quantity-stepper'
import type { CartItem } from '@/contexts/cart-context.types'

interface CartItemRowProps {
  item: CartItem
  isLoading: boolean
}

export function CartItemRow({ item, isLoading }: CartItemRowProps) {
  const { removeItem } = useCart()

  return (
    <div className="flex gap-4 p-4 border border-border rounded-lg">
      {/* Thumbnail */}
      <div className="flex-shrink-0 w-20 h-20 relative rounded overflow-hidden bg-surface-elevated">
        {item.thumbnail ? (
          <Image
            src={item.thumbnail}
            alt={item.productName}
            fill
            sizes="80px"
            className="object-cover"
          />
        ) : (
          <div className="w-full h-full bg-surface-elevated" aria-hidden="true" />
        )}
      </div>

      {/* Produktinfo */}
      <div className="flex-1 min-w-0">
        <p className="font-medium text-text-primary truncate">{item.productName}</p>
        {item.variationLabel && (
          <p className="text-sm text-text-secondary mt-0.5">{item.variationLabel}</p>
        )}

        <div className="flex items-center justify-between mt-3">
          <QuantityStepper
            itemKey={item.key}
            quantity={item.quantity}
            disabled={isLoading}
          />
          <button
            onClick={() => removeItem(item.key)}
            disabled={isLoading}
            aria-label={`${item.productName} aus dem Warenkorb entfernen`}
            className="text-text-secondary hover:text-red-600 focus-visible:ring-2 focus-visible:ring-red-500 rounded p-1 transition-colors disabled:opacity-50"
            style={{ touchAction: 'manipulation' }}
          >
            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M12 4L4 12M4 4l8 8" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
            </svg>
          </button>
        </div>
      </div>

      {/* Zeilenpreis */}
      <div className="flex-shrink-0 text-right">
        <p className="font-medium tabular-nums">{item.subtotal}</p>
        {item.quantity > 1 && (
          <p className="text-xs text-text-secondary tabular-nums mt-1">{item.price} / Stk.</p>
        )}
      </div>
    </div>
  )
}
