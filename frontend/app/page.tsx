import { Suspense } from 'react'
import Image from 'next/image'
import Link from 'next/link'
import { getClient } from '@/lib/apollo/server-client'
import { GET_FEATURED_PRODUCTS, GET_PRODUCT_CATEGORIES } from '@/lib/graphql/queries'
import { ProductCard } from '@/components/product/product-card'
import { ProductCardSkeleton } from '@/components/product/product-card-skeleton'
import type { ProductCardData, ProductCategory } from '@/lib/graphql/types'

// ISR: Seite wird alle 60 Minuten neu gebaut
export const revalidate = 60

async function FeaturedProducts() {
  const { data } = await getClient().query<{ products: { nodes: ProductCardData[] } }>({
    query: GET_FEATURED_PRODUCTS,
    variables: { first: 4 },
  })

  const products = data?.products?.nodes ?? []

  if (products.length === 0) return null

  return (
    <section aria-labelledby="featured-heading">
      <h2 id="featured-heading" className="text-2xl font-semibold tracking-tight mb-6">
        Ausgewählte Produkte
      </h2>
      <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {products.map((product) => (
          <ProductCard key={product.id} product={product} />
        ))}
      </div>
    </section>
  )
}

async function CategoryOverview() {
  const { data } = await getClient().query<{
    productCategories: { nodes: ProductCategory[] }
  }>({
    query: GET_PRODUCT_CATEGORIES,
    variables: { first: 6 },
  })

  const categories = data?.productCategories?.nodes ?? []

  return (
    <section aria-labelledby="categories-heading">
      <h2 id="categories-heading" className="text-2xl font-semibold tracking-tight mb-6">
        Kategorien
      </h2>
      <div className="flex flex-col gap-3">
        {categories.map((category) => (
          <Link
            key={category.id}
            href={`/kategorie/${category.slug}`}
            className="flex items-center gap-4 p-4 rounded-xl border border-border bg-surface-elevated hover:bg-surface-elevated/80 transition-colors"
          >
            {category.image && (
              <div className="relative w-12 h-12 flex-shrink-0 rounded-lg overflow-hidden">
                <Image
                  src={category.image.sourceUrl}
                  alt={category.image.altText || category.name}
                  fill
                  className="object-cover"
                  sizes="48px"
                />
              </div>
            )}
            <span className="font-medium text-text-primary flex-1">{category.name}</span>
            <span aria-hidden="true" className="text-text-secondary">
              →
            </span>
          </Link>
        ))}
      </div>
    </section>
  )
}

export default function HomePage() {
  return (
    <main id="main-content">
      {/* Hero Section */}
      <section className="relative bg-surface-elevated rounded-2xl overflow-hidden mb-12 p-8 sm:p-12">
        <div className="max-w-lg">
          <p className="text-sm font-semibold text-primary uppercase tracking-widest mb-2">
            {process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'}
          </p>
          <h1 className="text-3xl sm:text-4xl font-bold tracking-tight text-text-primary mb-4">
            Einzigartige Designs
            <br />
            auf Premium-Produkten
          </h1>
          <p className="text-text-secondary mb-6">
            Print-on-Demand – jedes Stück wird eigens für dich produziert.
          </p>
          <Link
            href="/kategorie/t-shirts"
            className="inline-flex items-center justify-center px-6 py-3 bg-primary text-white rounded-lg font-medium hover:bg-primary-hover transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          >
            Jetzt shoppen
          </Link>
        </div>
      </section>

      <div className="space-y-12">
        {/* Featured Products mit Suspense Skeleton */}
        <Suspense
          fallback={
            <section aria-labelledby="featured-heading-loading">
              <h2
                id="featured-heading-loading"
                className="text-2xl font-semibold tracking-tight mb-6"
              >
                Ausgewählte Produkte
              </h2>
              <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                {Array.from({ length: 4 }).map((_, i) => (
                  <ProductCardSkeleton key={i} />
                ))}
              </div>
            </section>
          }
        >
          <FeaturedProducts />
        </Suspense>

        {/* Kategorie-Übersicht */}
        <Suspense
          fallback={
            <section>
              <div className="h-8 w-32 bg-surface-elevated rounded animate-pulse mb-6" />
              <div className="flex flex-col gap-3">
                {Array.from({ length: 3 }).map((_, i) => (
                  <div key={i} className="h-16 bg-surface-elevated rounded-xl animate-pulse" />
                ))}
              </div>
            </section>
          }
        >
          <CategoryOverview />
        </Suspense>
      </div>
    </main>
  )
}
