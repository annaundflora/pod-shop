'use client'

import { useCart } from '@/contexts/cart-context'

interface QuantityStepperProps {
  itemKey: string
  quantity: number
  disabled: boolean
}

export function QuantityStepper({ itemKey, quantity, disabled }: QuantityStepperProps) {
  const { updateQuantity } = useCart()

  return (
    <div className="flex items-center gap-1" role="group" aria-label="Menge aendern">
      <button
        onClick={() => updateQuantity(itemKey, quantity - 1)}
        disabled={disabled || quantity <= 1}
        aria-label="Menge verringern"
        className="w-8 h-8 flex items-center justify-center border border-border rounded hover:bg-surface-elevated focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        style={{ touchAction: 'manipulation', minWidth: '2rem', minHeight: '2rem' }}
      >
        <span aria-hidden="true">&#8722;</span>
      </button>

      <span
        className="w-8 text-center tabular-nums text-sm font-medium"
        aria-live="polite"
        aria-label={`Menge: ${quantity}`}
      >
        {quantity}
      </span>

      <button
        onClick={() => updateQuantity(itemKey, quantity + 1)}
        disabled={disabled || quantity >= 99}
        aria-label="Menge erhoehen"
        className="w-8 h-8 flex items-center justify-center border border-border rounded hover:bg-surface-elevated focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        style={{ touchAction: 'manipulation', minWidth: '2rem', minHeight: '2rem' }}
      >
        <span aria-hidden="true">+</span>
      </button>
    </div>
  )
}
