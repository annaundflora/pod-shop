'use client'

import { useCart } from '@/contexts/cart-context'
import { checkoutRedirect } from '@/lib/cart/checkout-redirect'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter } from '@/components/ui/card'

export function CartSummary() {
  const { subtotal, shippingTotal, total, cartItems, isLoading } = useCart()

  const isEmpty = cartItems.length === 0

  return (
    <Card className="mt-6">
      <CardContent className="pt-6 space-y-3">
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
      </CardContent>

      <CardFooter className="flex-col items-stretch gap-2 pt-0 pb-6 px-6">
        {/* §19 UStG Hinweis */}
        <p className="text-xs text-text-secondary">
          Gemäß §19 UStG wird keine Umsatzsteuer berechnet.
        </p>

        {/* Zur Kasse Button */}
        <Button
          onClick={checkoutRedirect}
          disabled={isEmpty || isLoading}
          aria-label="Zur Kasse"
          className="w-full"
        >
          Zur Kasse
        </Button>
      </CardFooter>
    </Card>
  )
}
