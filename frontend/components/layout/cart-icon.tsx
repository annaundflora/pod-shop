'use client'

import Link from 'next/link'
import { useCart } from '@/contexts/cart-context'
import { Badge } from '@/components/ui/badge'

export function CartIcon() {
  const { itemCount } = useCart()

  return (
    <Link
      href="/warenkorb"
      aria-label={itemCount > 0 ? `Warenkorb: ${itemCount} Artikel` : 'Warenkorb ist leer'}
      className="relative flex items-center justify-center w-10 h-10 rounded-lg hover:bg-surface-elevated focus-visible:ring-2 focus-visible:ring-primary transition-colors"
      style={{ touchAction: 'manipulation' }}
    >
      {/* Warenkorb-Icon SVG */}
      <svg
        aria-hidden="true"
        width="22"
        height="22"
        viewBox="0 0 22 22"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z" />
        <line x1="3" y1="6" x2="21" y2="6" />
        <path d="M16 10a4 4 0 01-8 0" />
      </svg>

      {/* Badge */}
      {itemCount > 0 && (
        <Badge
          aria-hidden="true"
          className="absolute -top-1 -right-1 min-w-[1.125rem] h-[1.125rem] px-1 tabular-nums leading-none"
        >
          {itemCount > 99 ? '99+' : itemCount}
        </Badge>
      )}
    </Link>
  )
}
