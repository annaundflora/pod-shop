// frontend/components/category/filter-chips.tsx
import Link from 'next/link'
import type { ProductCategory } from '@/lib/graphql/types'

interface FilterChipsProps {
  categories: ProductCategory[]
  currentSlug: string
}

export function FilterChips({ categories, currentSlug }: FilterChipsProps) {
  return (
    <nav
      aria-label="Kategorie-Filter"
      className="mb-6"
    >
      <div className="flex gap-2 overflow-x-auto pb-2 scrollbar-none -mx-4 px-4">
        <Link
          href="/produkte"
          aria-current={currentSlug === 'alle' ? 'page' : undefined}
          className={[
            'flex-shrink-0 px-4 py-2 rounded-full text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center',
            currentSlug === 'alle'
              ? 'bg-text-primary text-surface'
              : 'bg-surface-elevated text-text-primary hover:bg-border',
          ].join(' ')}
        >
          Alle
        </Link>
        {categories.map((category) => (
          <Link
            key={category.id}
            href={`/kategorie/${category.slug}`}
            aria-current={category.slug === currentSlug ? 'page' : undefined}
            className={[
              'flex-shrink-0 px-4 py-2 rounded-full text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] flex items-center',
              category.slug === currentSlug
                ? 'bg-text-primary text-surface'
                : 'bg-surface-elevated text-text-primary hover:bg-border',
            ].join(' ')}
          >
            {category.name}
          </Link>
        ))}
      </div>
    </nav>
  )
}
