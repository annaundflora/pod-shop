// frontend/app/kollektion/[slug]/page.tsx
import { notFound, redirect } from 'next/navigation'
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import type { Metadata } from 'next'
import { getClient } from '@/lib/apollo/server-client'
import { GET_CATEGORY_META } from '@/lib/graphql/queries'
import { CollectionHeaderBlockSkeleton } from '@/components/blocks/collection-header-block'
import { ProductGridBlockSkeleton } from '@/components/blocks/product-grid-block'

export const revalidate = 60

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>
}): Promise<Metadata> {
  const { slug } = await params
  const { data } = await getClient().query({
    query: GET_CATEGORY_META,
    variables: { slug },
  })
  const category = data?.productCategory
  if (!category) {
    return { title: 'Kollektion nicht gefunden' }
  }
  const shopName = process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'
  return {
    title: `${category.name} | ${shopName}`,
    description: category.description
      ? category.description.replace(/<[^>]*>/g, '').slice(0, 160)
      : `${category.name} — Alle Produkte im ${shopName}`,
    alternates: {
      canonical: `/kollektion/${slug}`,
    },
  }
}

interface CollectionPageProps {
  params: Promise<{ slug: string }>
  searchParams: Promise<{ page?: string; sort?: string }>
}

export default async function CollectionPage({
  params,
  searchParams,
}: CollectionPageProps) {
  const { slug } = await params
  const { page: pageParam, sort: sortParam } = await searchParams
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'

  // Validierung
  const pageRaw = parseInt(pageParam ?? '1', 10)
  const page = isNaN(pageRaw) || pageRaw < 1 ? 1 : pageRaw
  const validSorts = ['price_asc', 'price_desc', 'newest']
  const sort = sortParam && validSorts.includes(sortParam) ? sortParam : ''

  // Redirect zu Seite 1 wenn page-Param ungültig
  if (pageParam !== undefined && page !== pageRaw) {
    redirect(`/kollektion/${slug}${sort ? `?sort=${sort}` : ''}`)
  }

  // Kategorie-Existenz prüfen (404 wenn nicht gefunden)
  const { data } = await getClient().query({
    query: GET_CATEGORY_META,
    variables: { slug },
  })
  if (!data?.productCategory) {
    notFound()
  }

  const pageConfig = loadPageConfig('collection', theme, {
    slug,
    page: String(page),
    sort,
  })

  return (
    <main id="main-content">
      <SectionRenderer
        sections={pageConfig.sections}
        skeletonMap={{
          'collection-header': <CollectionHeaderBlockSkeleton />,
          'product-count': null,
          'product-grid': <ProductGridBlockSkeleton />,
          'pagination': null,
        }}
      />
    </main>
  )
}
