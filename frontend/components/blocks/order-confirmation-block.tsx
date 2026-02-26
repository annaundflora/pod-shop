// frontend/components/blocks/order-confirmation-block.tsx
'use client'

import { useState, useEffect } from 'react'
import Link from 'next/link'
import { CheckCircle2 } from 'lucide-react'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { OrderConfirmationData } from '@/lib/blocks/types'

export function OrderConfirmationBlock({
  data,
}: BlockComponentProps<OrderConfirmationData>) {
  const [orderId, setOrderId] = useState<string | null>(null)

  useEffect(() => {
    // Client-seitig: order_id aus URL lesen (Hydration-sicher)
    const params = new URLSearchParams(window.location.search)
    const id = params.get('order_id')
    if (id) setOrderId(id)
  }, [])

  return (
    <div className="max-w-lg mx-auto text-center py-16 px-8">
      <CheckCircle2
        className="w-16 h-16 text-green-500 mx-auto mb-6"
        aria-hidden="true"
      />
      <h1 className="text-2xl font-bold text-text-primary mb-4">
        {data.headline}
      </h1>
      {orderId && (
        <p className="text-text-secondary mb-2" suppressHydrationWarning>
          Bestellnummer: #{orderId}
        </p>
      )}
      <p className="text-text-secondary mb-2">{data.text}</p>
      <p className="text-text-secondary text-sm mb-8">{data.emailText}</p>
      <Link
        href={data.ctaLink}
        className="inline-block px-6 py-3 bg-primary text-white rounded-card hover:opacity-90 focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
        style={{ touchAction: 'manipulation' }}
      >
        {data.ctaText}
      </Link>
    </div>
  )
}
