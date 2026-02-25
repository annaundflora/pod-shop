// frontend/lib/blocks/section-layout.tsx

interface SectionLayoutProps {
  columns: 1 | 2 | 3 | 4
  gap?: string
  children: React.ReactNode
}

const COLUMN_CLASSES: Record<number, string> = {
  1: 'grid-cols-1',
  2: 'grid-cols-1 md:grid-cols-2',
  3: 'grid-cols-1 md:grid-cols-3',
  4: 'grid-cols-1 md:grid-cols-4',
}

export function SectionLayout({ columns, gap = 'gap-8', children }: SectionLayoutProps) {
  const colClass = COLUMN_CLASSES[columns] ?? 'grid-cols-1'

  return (
    <section className={`grid ${colClass} ${gap}`}>
      {children}
    </section>
  )
}
