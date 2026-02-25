// frontend/components/blocks/filter-chips-block.tsx
import { FilterChips } from '@/components/category/filter-chips'
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { ProductCategory } from '@/lib/graphql/types'

interface FilterChipsData {
  nodes: ProductCategory[]
  currentSlug?: string
}

export function FilterChipsBlock({ data }: BlockComponentProps<FilterChipsData | null>) {
  if (!data?.nodes) return null

  return (
    <FilterChips
      categories={data.nodes}
      currentSlug={data.currentSlug ?? ''}
    />
  )
}
