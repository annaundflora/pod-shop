import type { Metadata } from 'next'
import { CartPageClient } from './cart-page-client'

export const metadata: Metadata = {
  title: 'Warenkorb – POD Shop',
  robots: { index: false, follow: false },
}

export default function WarenkorbPage() {
  return <CartPageClient />
}
