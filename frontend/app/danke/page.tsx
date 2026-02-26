// frontend/app/danke/page.tsx
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import type { Metadata } from 'next'

// Keine ISR — statische Seite, order_id wird client-seitig gelesen
export const dynamic = 'force-static'

export const metadata: Metadata = {
  title: 'Vielen Dank für deine Bestellung!',
  robots: { index: false, follow: false },
}

interface ThanksPageProps {
  searchParams: Promise<{ order_id?: string; key?: string }>
}

export default async function ThanksPage({ searchParams }: ThanksPageProps) {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const { order_id } = await searchParams

  const pageConfig = loadPageConfig('thanks', theme, {
    order_id: order_id ?? '',
  })

  return (
    <main id="main-content" className="container mx-auto px-4 py-16">
      <SectionRenderer
        sections={pageConfig.sections}
        skeletonMap={{
          'order-confirmation': null,
        }}
      />
    </main>
  )
}
