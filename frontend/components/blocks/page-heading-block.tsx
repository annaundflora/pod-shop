// frontend/components/blocks/page-heading-block.tsx
import type { BlockComponentProps } from '@/lib/blocks/types'
import type { CategoryWithProducts, WPPageContent } from '@/lib/blocks/types'

type PageHeadingData = CategoryWithProducts | WPPageContent | null

export function PageHeadingBlock({ data }: BlockComponentProps<PageHeadingData>) {
  // WPPageContent hat { title, content } — fuer Rechtsseiten
  // CategoryWithProducts hat { productCategory: { name } } — fuer Kategorieseiten
  const title = data
    ? 'title' in data
      ? (data as WPPageContent).title
      : (data as CategoryWithProducts).productCategory?.name
    : null

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
