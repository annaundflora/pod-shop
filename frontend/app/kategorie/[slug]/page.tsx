import { getClient } from '@/lib/apollo/server-client'
import {
  GET_PRODUCT_CATEGORIES,
  GET_ALL_CATEGORY_SLUGS,
} from '@/lib/graphql/queries'
import { redirect } from 'next/navigation'
import { CategoryPageClient } from './category-page-client'
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import type { ProductCategory } from '@/lib/graphql/types'

export const revalidate = 60

export async function generateStaticParams() {
  const { data } = await getClient().query<{
    productCategories: { nodes: Array<{ slug: string }> }
  }>({
    query: GET_ALL_CATEGORY_SLUGS,
  })

  return (data?.productCategories?.nodes ?? []).map((cat) => ({
    slug: cat.slug,
  }))
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params
  const { data } = await getClient().query<{
    productCategories: { nodes: ProductCategory[] }
  }>({
    query: GET_PRODUCT_CATEGORIES,
  })

  const category = data?.productCategories?.nodes.find((c) => c.slug === slug)

  return {
    title: category
      ? `${category.name} | ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`
      : `Kategorie | ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`,
    description: category
      ? `Alle ${category.name} im ${process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}`
      : undefined,
  }
}

interface CategoryPageProps {
  params: Promise<{ slug: string }>
  searchParams: Promise<{ page?: string; sort?: string }>
}

export default async function CategoryPage({ params, searchParams }: CategoryPageProps) {
  const { slug } = await params
  const { page: pageParam, sort: sortParam } = await searchParams
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'

  // URL-Param Validierung und Sanitierung
  const pageRaw = parseInt(pageParam ?? '1', 10)
  const page = isNaN(pageRaw) || pageRaw < 1 ? 1 : pageRaw
  const validSorts = ['price_asc', 'price_desc', 'newest']
  const sort = sortParam && validSorts.includes(sortParam) ? sortParam : ''

  // Redirect zu Seite 1 wenn page-Param ungültig
  if (pageParam !== undefined && page !== pageRaw) {
    redirect(`/kategorie/${slug}${sort ? `?sort=${sort}` : ''}`)
  }

  const pageConfig = loadPageConfig('category', theme, {
    slug,
    page: String(page),
    sort,
  })

  return (
    <main id="main-content">
      {/* Pinterest Tracking: page_visit + view_category Events — unveraendert */}
      <CategoryPageClient categoryName={slug} />
      <div className="space-y-0">
        <SectionRenderer
          sections={pageConfig.sections}
          skeletonMap={{
            'breadcrumb': null,
            'page-heading': null,
            'filter-chips': null,
            'product-count': null,
            'sort-bar': null,
            'product-grid': (
              <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                {Array.from({ length: 8 }).map((_, i) => (
                  <div key={i} className="aspect-square bg-surface-secondary animate-pulse rounded-card" />
                ))}
              </div>
            ),
            'pagination': null,
            'empty-state': null,
          }}
        />
      </div>
    </main>
  )
}
