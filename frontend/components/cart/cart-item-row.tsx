'use client'

import Image from 'next/image'
import { useCart } from '@/contexts/cart-context'
import { QuantityStepper } from './quantity-stepper'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { formatPrice } from '@/lib/format-price'
import type { CartItem } from '@/contexts/cart-context.types'

interface CartItemRowProps {
  item: CartItem
  isLoading: boolean
}

export function CartItemRow({ item, isLoading }: CartItemRowProps) {
  const { removeItem } = useCart()

  return (
    <Card>
      <CardContent className="flex gap-4 p-4">
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
            <Button
              variant="ghost"
              size="icon"
              onClick={() => removeItem(item.key)}
              disabled={isLoading}
              aria-label={`${item.productName} aus dem Warenkorb entfernen`}
              className="text-text-secondary hover:text-error focus-visible:ring-error"
            >
              <svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M12 4L4 12M4 4l8 8" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
              </svg>
            </Button>
          </div>
        </div>

        {/* Zeilenpreis */}
        <div className="flex-shrink-0 text-right">
          <p className="font-medium tabular-nums">{formatPrice(item.subtotal)}</p>
          {item.quantity > 1 && (
            <p className="text-xs text-text-secondary tabular-nums mt-1">{formatPrice(item.price)} / Stk.</p>
          )}
        </div>
      </CardContent>
    </Card>
  )
}
