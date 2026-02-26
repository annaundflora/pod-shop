// frontend/app/suche/page.tsx
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import { redirect } from 'next/navigation'
import type { Metadata } from 'next'

export const revalidate = 60

export async function generateMetadata({
  searchParams,
}: {
  searchParams: Promise<{ q?: string }>
}): Promise<Metadata> {
  const { q } = await searchParams
  const query = q?.trim() ?? ''
  return {
    title: query
      ? `Suche: "${query}" | ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`
      : `Suche | ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`,
    description: query
      ? `Suchergebnisse für "${query}" im ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`
      : 'Produktsuche',
    robots: { index: false, follow: true },
  }
}

interface SearchPageProps {
  searchParams: Promise<{ q?: string; page?: string; sort?: string }>
}

export default async function SearchPage({ searchParams }: SearchPageProps) {
  const { q: qParam, page: pageParam, sort: sortParam } = await searchParams
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'

  // Validierung
  const q = qParam?.trim() ?? ''
  const pageRaw = parseInt(pageParam ?? '1', 10)
  const page = isNaN(pageRaw) || pageRaw < 1 ? 1 : pageRaw
  const validSorts = ['price_asc', 'price_desc', 'newest']
  const sort = sortParam && validSorts.includes(sortParam) ? sortParam : ''

  // Redirect zu Seite 1 wenn page-Param ungültig
  if (pageParam !== undefined && page !== pageRaw) {
    redirect(`/suche${q ? `?q=${encodeURIComponent(q)}` : ''}${sort ? `&sort=${sort}` : ''}`)
  }

  const pageConfig = loadPageConfig('search', theme, {
    q,
    page: String(page),
    sort,
  })

  return (
    <main id="main-content" className="container mx-auto px-4 py-8">
      <SectionRenderer
        sections={pageConfig.sections}
        skeletonMap={{
          'search-bar': null,
          'product-count': null,
          'sort-bar': null,
          'search-results': (
            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
              {Array.from({ length: 8 }).map((_, i) => (
                <div key={i} className="aspect-square bg-gray-100 rounded-card animate-pulse" />
              ))}
            </div>
          ),
          'pagination': null,
          'empty-state': null,
        }}
      />
    </main>
  )
}
