// frontend/components/blocks/breadcrumb-block.tsx
import type { BlockComponentProps } from '@/lib/blocks/types'

interface BreadcrumbItem {
  label: string
  href?: string
}

interface BreadcrumbData {
  items: BreadcrumbItem[]
}

export function BreadcrumbBlock({ data }: BlockComponentProps<BreadcrumbData>) {
  const items = data?.items ?? []

  if (items.length === 0) return null

  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: item.label,
      ...(item.href ? { item: item.href } : {}),
    })),
  }

  return (
    <nav aria-label="Breadcrumb">
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
      />
      <ol className="flex items-center gap-1 text-sm flex-wrap">
        {items.map((item, index) => {
          const isLast = index === items.length - 1
          return (
            <li key={index} className="flex items-center gap-1">
              {isLast ? (
                <span
                  aria-current="page"
                  className="text-text-primary font-medium"
                >
                  {item.label}
                </span>
              ) : (
                <>
                  <a
                    href={item.href}
                    className="text-text-secondary hover:text-text-primary transition-colors"
                  >
                    {item.label}
                  </a>
                  <span aria-hidden="true" className="text-text-secondary">
                    ›
                  </span>
                </>
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}
