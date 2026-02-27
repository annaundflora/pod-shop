// frontend/components/blocks/empty-state-block.tsx
import { SearchX } from 'lucide-react'
import type { BlockComponentProps } from '@/lib/blocks/types'

interface EmptyStateLink {
  label: string
  href: string
}

interface EmptyStateData {
  headline: string
  text: string
  links?: EmptyStateLink[]
}

export function EmptyStateBlock({ data }: BlockComponentProps<EmptyStateData>) {
  const links = data?.links ?? []

  return (
    <div className="text-center py-16 px-4">
      <SearchX
        className="h-12 w-12 text-text-tertiary mx-auto mb-4"
        aria-hidden={true}
      />
      <h2 className="text-xl font-semibold text-text-primary mb-2">
        {data.headline}
      </h2>
      <p className="text-text-secondary mb-6">{data.text}</p>
      {links.length > 0 && (
        <div className="flex flex-wrap justify-center gap-3">
          {links.map((link, index) => (
            <a
              key={index}
              href={link.href}
              className="text-primary underline hover:no-underline"
            >
              {link.label}
            </a>
          ))}
        </div>
      )}
    </div>
  )
}
