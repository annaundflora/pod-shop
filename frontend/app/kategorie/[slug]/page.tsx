import { Suspense } from 'react'
import { notFound } from 'next/navigation'
import { getClient } from '@/lib/apollo/server-client'
import {
  GET_PRODUCTS,
  GET_PRODUCT_CATEGORIES,
  GET_ALL_CATEGORY_SLUGS,
} from '@/lib/graphql/queries'
import { ProductCard } from '@/components/product/product-card'
import { ProductCardSkeleton } from '@/components/product/product-card-skeleton'
import { FilterChips } from '@/components/category/filter-chips'
import { CategoryPageClient } from './category-page-client'
import type { ProductCardData, ProductCategory } from '@/lib/graphql/types'

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
    title: category ? `${category.name} | POD Shop` : 'Kategorie | POD Shop',
    description: category ? `Alle ${category.name} im POD Shop` : undefined,
  }
}

interface CategoryPageProps {
  params: Promise<{ slug: string }>
}

export default async function CategoryPage({ params }: CategoryPageProps) {
  const { slug } = await params

  // Produkte dieser Kategorie laden
  const [productsResult, categoriesResult] = await Promise.all([
    getClient().query<{ products: { nodes: ProductCardData[] } }>({
      query: GET_PRODUCTS,
      variables: { categorySlug: slug, first: 24 },
    }),
    getClient().query<{ productCategories: { nodes: ProductCategory[] } }>({
      query: GET_PRODUCT_CATEGORIES,
    }),
  ])

  const products = productsResult.data?.products?.nodes ?? []
  const categories = categoriesResult.data?.productCategories?.nodes ?? []
  const currentCategory = categories.find((c) => c.slug === slug)

  if (!currentCategory) {
    notFound()
  }

  return (
    <main id="main-content">
      {/* Pinterest Tracking: page_visit + view_category Events */}
      <CategoryPageClient categoryName={currentCategory.name} />
      <div className="mb-6">
        <h1 className="text-3xl font-bold tracking-tight text-text-primary">
          {currentCategory.name}
        </h1>
      </div>

      {/* Horizontale Filter-Chips */}
      <FilterChips
        categories={categories}
        currentSlug={slug}
      />

      {/* Produktanzahl */}
      <p className="text-sm text-text-secondary mb-6">
        {products.length === 0
          ? 'Keine Produkte'
          : `${products.length} ${products.length === 1 ? 'Produkt' : 'Produkte'}`}
      </p>

      {/* Produktgrid */}
      {products.length === 0 ? (
        <div className="text-center py-16">
          <p className="text-text-secondary">Keine Produkte in dieser Kategorie</p>
        </div>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
          {products.map((product) => (
            <ProductCard key={product.id} product={product} />
          ))}
        </div>
      )}
    </main>
  )
}
