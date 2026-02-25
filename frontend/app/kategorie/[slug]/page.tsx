import { getClient } from '@/lib/apollo/server-client'
import {
  GET_PRODUCT_CATEGORIES,
  GET_ALL_CATEGORY_SLUGS,
} from '@/lib/graphql/queries'
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
}

export default async function CategoryPage({ params }: CategoryPageProps) {
  const { slug } = await params
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'

  const pageConfig = loadPageConfig('category', theme, { slug })

  return (
    <main id="main-content">
      {/* Pinterest Tracking: page_visit + view_category Events — unveraendert */}
      <CategoryPageClient categoryName={slug} />
      <div className="space-y-0">
        <SectionRenderer
          sections={pageConfig.sections}
          skeletonMap={{
            'page-heading': null,
            'filter-chips': null,
            'product-count': null,
            'product-grid': (
              <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                {Array.from({ length: 8 }).map((_, i) => (
                  <div key={i} className="aspect-square bg-surface-secondary animate-pulse rounded-card" />
                ))}
              </div>
            ),
          }}
        />
      </div>
    </main>
  )
}
