'use client'

import { useCart } from '@/contexts/cart-context'
import { checkoutRedirect } from '@/lib/cart/checkout-redirect'

export function CartSummary() {
  const { subtotal, shippingTotal, total, cartItems, isLoading } = useCart()

  const isEmpty = cartItems.length === 0

  return (
    <div className="mt-6 space-y-3">
      {/* Zwischensumme */}
      <div className="flex justify-between text-sm">
        <span className="text-text-secondary">Zwischensumme</span>
        <span className="tabular-nums">{subtotal}</span>
      </div>

      {/* Versandkosten */}
      <div className="flex justify-between text-sm">
        <span className="text-text-secondary">Versandkosten</span>
        <span className="tabular-nums">{shippingTotal}</span>
      </div>

      {/* Trennlinie */}
      <hr className="border-border" />

      {/* Gesamtpreis */}
      <div className="flex justify-between font-semibold text-lg">
        <span>Gesamt</span>
        <span className="tabular-nums">{total}</span>
      </div>

      {/* §19 UStG Hinweis */}
      <p className="text-xs text-text-secondary">
        Gemäß §19 UStG wird keine Umsatzsteuer berechnet.
      </p>

      {/* Zur Kasse Button */}
      <button
        onClick={checkoutRedirect}
        disabled={isEmpty || isLoading}
        aria-disabled={isEmpty || isLoading}
        className="w-full py-3 px-6 bg-primary text-white font-medium rounded-lg hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition-colors mt-4"
        style={{ touchAction: 'manipulation', minHeight: '2.75rem' }}
      >
        Zur Kasse
      </button>
    </div>
  )
}
