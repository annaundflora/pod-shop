import type { Metadata } from 'next'
import { ApolloWrapper } from '@/components/apollo-wrapper'
import { CartProvider } from '@/contexts/cart-context'
import { ThemeProvider } from '@/contexts/theme-context'
import { Header } from '@/components/layout/header'
import { Footer } from '@/components/layout/footer'
import { CookieConsentBanner } from '@/components/layout/cookie-consent-banner'
import { PinterestTagInit } from '@/components/tracking/pinterest-tag-init'
import './globals.css'

export const metadata: Metadata = {
  title: 'POD Shop',
  description: 'Print-on-Demand Shop',
}

export default function RootLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <html lang="de" suppressHydrationWarning>
      <head>
        <script
          dangerouslySetInnerHTML={{
            __html: `try{document.documentElement.dataset.theme=localStorage.getItem('pod-theme')||'modern'}catch(e){}`,
          }}
        />
      </head>
      <body>
        <ApolloWrapper>
          <CartProvider>
            <ThemeProvider>
              <Header />
              <div className="max-w-7xl mx-auto px-4 py-8">
                {children}
              </div>
              <Footer />
              {/* Cookie Consent Banner – übernimmt Kontrolle über Pinterest Tag (Slice 6) */}
              <CookieConsentBanner />
              {/* Pinterest Tag: NUR nach Consent geladen (Client Component) */}
              <PinterestTagInit />
            </ThemeProvider>
          </CartProvider>
        </ApolloWrapper>
      </body>
    </html>
  )
}
