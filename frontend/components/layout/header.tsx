// frontend/components/layout/header.tsx
import Link from 'next/link'
import { getClient } from '@/lib/apollo/server-client'
import { GET_PRODUCT_CATEGORIES } from '@/lib/graphql/queries'
import { MobileMenu } from './mobile-menu'
import { CartIcon } from './cart-icon'
import type { ProductCategory } from '@/lib/graphql/types'

export async function Header() {
  const { data } = await getClient().query<{
    productCategories: { nodes: ProductCategory[] }
  }>({
    query: GET_PRODUCT_CATEGORIES,
  })

  const categories = data?.productCategories?.nodes ?? []

  return (
    <header className="sticky top-0 z-40 bg-surface border-b border-border">
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:px-4 focus:py-2 focus:bg-primary focus:text-white focus:rounded"
      >
        Zum Hauptinhalt springen
      </a>

      <div className="max-w-7xl mx-auto px-4 h-14 flex items-center justify-between">
        {/* Mobile: Hamburger */}
        <div className="flex lg:hidden">
          <MobileMenu categories={categories} />
        </div>

        {/* Logo */}
        <Link
          href="/"
          className="font-bold text-lg text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded"
        >
          POD Shop
        </Link>

        {/* Desktop: Kategorie-Navigation */}
        <nav aria-label="Hauptnavigation" className="hidden lg:flex items-center gap-6">
          {categories.map((category) => (
            <Link
              key={category.id}
              href={`/kategorie/${category.slug}`}
              className="text-sm text-text-secondary hover:text-text-primary transition-colors focus-visible:ring-2 focus-visible:ring-primary rounded"
            >
              {category.name}
            </Link>
          ))}
        </nav>

        {/* Warenkorb-Icon mit dynamischem Badge-Counter */}
        <CartIcon />
      </div>
    </header>
  )
}
