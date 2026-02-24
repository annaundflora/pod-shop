'use client'

import { useCart } from '@/contexts/cart-context'
import { Button } from '@/components/ui/button'

interface QuantityStepperProps {
  itemKey: string
  quantity: number
  disabled: boolean
}

export function QuantityStepper({ itemKey, quantity, disabled }: QuantityStepperProps) {
  const { updateQuantity } = useCart()

  return (
    <div className="flex items-center gap-1" role="group" aria-label="Menge aendern">
      <Button
        variant="outline"
        size="icon"
        onClick={() => updateQuantity(itemKey, quantity - 1)}
        disabled={disabled || quantity <= 1}
        aria-label="Menge verringern"
        className="w-8 h-8"
      >
        <span aria-hidden="true">&#8722;</span>
      </Button>

      <span
        className="w-8 text-center tabular-nums text-sm font-medium"
        aria-live="polite"
        aria-label={`Menge: ${quantity}`}
      >
        {quantity}
      </span>

      <Button
        variant="outline"
        size="icon"
        onClick={() => updateQuantity(itemKey, quantity + 1)}
        disabled={disabled || quantity >= 99}
        aria-label="Menge erhoehen"
        className="w-8 h-8"
      >
        <span aria-hidden="true">+</span>
      </Button>
    </div>
  )
}
