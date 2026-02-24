'use client'

import { useCart } from '@/contexts/cart-context'
import { CartItemRow } from '@/components/cart/cart-item-row'
import { CartSummary } from '@/components/cart/cart-summary'
import { EmptyCart } from '@/components/cart/empty-cart'
import { Button } from '@/components/ui/button'

export function CartPageClient() {
  const { cartItems, isLoading, error, clearError } = useCart()

  return (
    <main className="max-w-2xl mx-auto px-4 py-8" id="main-content">
      <h1 className="text-2xl font-semibold tracking-tight mb-6">
        Warenkorb
        {cartItems.length > 0 && (
          <span className="text-text-secondary font-normal text-lg ml-2">
            ({cartItems.reduce((sum, i) => sum + i.quantity, 0)} Artikel)
          </span>
        )}
      </h1>

      {error && (
        <div
          role="alert"
          aria-live="polite"
          className="mb-4 p-3 [background-color:color-mix(in_oklch,var(--color-error)_10%,transparent)] border border-error text-error rounded-lg text-sm flex items-center justify-between"
        >
          <span>{error}</span>
          <Button
            variant="ghost"
            size="icon"
            onClick={clearError}
            aria-label="Fehlermeldung schliessen"
            className="ml-2 text-error hover:text-error opacity-80 hover:opacity-100"
          >
            &#10005;
          </Button>
        </div>
      )}

      {cartItems.length === 0 ? (
        <EmptyCart />
      ) : (
        <div className="space-y-4">
          {cartItems.map((item) => (
            <CartItemRow key={item.key} item={item} isLoading={isLoading} />
          ))}
          <hr className="border-border" />
          <CartSummary />
        </div>
      )}
    </main>
  )
}
