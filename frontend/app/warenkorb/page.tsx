import type { Metadata } from 'next'
import { CartPageClient } from './cart-page-client'

const SHOP_NAME = process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'

export const metadata: Metadata = {
  title: `Warenkorb – ${SHOP_NAME}`,
  robots: { index: false, follow: false },
}

export default function WarenkorbPage() {
  return <CartPageClient />
}
