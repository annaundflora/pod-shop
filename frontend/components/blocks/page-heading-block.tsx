// frontend/components/blocks/page-heading-block.tsx
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { CategoryWithProducts } from '@/lib/blocks/types'

export function PageHeadingBlock({ data }: BlockComponentProps<CategoryWithProducts | null>) {
  const title = (data as CategoryWithProducts | null)?.productCategory?.name

  if (!title) return null

  return (
    <div className="mb-6">
      <h1
        className="text-3xl font-bold tracking-tight text-text-primary"
        style={{ textWrap: 'balance' } as React.CSSProperties}
      >
        {title}
      </h1>
    </div>
  )
}

export function PageHeadingBlockSkeleton() {
  return (
    <div className="mb-6">
      <div className="h-9 w-48 bg-surface-secondary animate-pulse rounded-card" />
    </div>
  )
}
