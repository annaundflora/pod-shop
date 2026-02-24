import type { Metadata } from 'next'
import { headingFont, bodyFont } from '@/lib/theme/fonts'
import { ApolloWrapper } from '@/components/apollo-wrapper'
import { CartProvider } from '@/contexts/cart-context'
import { Header } from '@/components/layout/header'
import { Footer } from '@/components/layout/footer'
import { CookieConsentBanner } from '@/components/layout/cookie-consent-banner'
import { PinterestTagInit } from '@/components/tracking/pinterest-tag-init'
import './globals.css'

const SHOP_NAME = process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'

export const metadata: Metadata = {
  title: SHOP_NAME,
  description: 'Print-on-Demand Shop',
}

export default function RootLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <html lang="de" className={`${headingFont.variable} ${bodyFont.variable}`}>
      <body>
        <ApolloWrapper>
          <CartProvider>
            <Header />
            <div className="max-w-7xl mx-auto px-4 py-8">
              {children}
            </div>
            <Footer />
            {/* Cookie Consent Banner – übernimmt Kontrolle über Pinterest Tag (Slice 6) */}
            <CookieConsentBanner />
            {/* Pinterest Tag: NUR nach Consent geladen (Client Component) */}
            <PinterestTagInit />
          </CartProvider>
        </ApolloWrapper>
      </body>
    </html>
  )
}
