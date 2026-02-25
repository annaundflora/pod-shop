// frontend/components/blocks/product-count-block.tsx
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { CategoryWithProducts } from '@/lib/blocks/types'

export function ProductCountBlock({ data }: BlockComponentProps<CategoryWithProducts | null>) {
  const count = (data as CategoryWithProducts | null)?.products?.nodes?.length ?? 0

  return (
    <p className="text-sm text-text-secondary mb-6">
      {count === 0
        ? 'Keine Produkte'
        : `${count} ${count === 1 ? 'Produkt' : 'Produkte'}`}
    </p>
  )
}
