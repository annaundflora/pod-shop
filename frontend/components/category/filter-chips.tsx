// frontend/components/category/filter-chips.tsx
import Link from 'next/link'
import { Button } from '@/components/ui/button'
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
        <Button
          asChild
          variant={currentSlug === 'alle' ? 'default' : 'secondary'}
          className="flex-shrink-0 rounded-full"
        >
          <Link
            href="/produkte"
            aria-current={currentSlug === 'alle' ? 'page' : undefined}
          >
            Alle
          </Link>
        </Button>
        {categories.map((category) => (
          <Button
            key={category.id}
            asChild
            variant={category.slug === currentSlug ? 'default' : 'secondary'}
            className="flex-shrink-0 rounded-full"
          >
            <Link
              href={`/kategorie/${category.slug}`}
              aria-current={category.slug === currentSlug ? 'page' : undefined}
            >
              {category.name}
            </Link>
          </Button>
        ))}
      </div>
    </nav>
  )
}
