// frontend/components/blocks/motif-grid-block.tsx
import Image from 'next/image'
import Link from 'next/link'
import { Card } from '@/components/ui/card'
import type { BlockComponentProps, MotifGridData } from '@/lib/blocks/types'

export function MotifGridBlock({ data }: BlockComponentProps<MotifGridData | null>) {
  const items = data?.items ?? []
  // Block-level guard: skip items whose href is not an internal route ("/...")
  const safeItems = items.filter((item) => typeof item.href === 'string' && item.href.startsWith('/'))

  if (!data || safeItems.length === 0) return null

  const headline = data.headline

  return (
    <section aria-labelledby={headline ? 'motif-grid-heading' : undefined}>
      {headline && (
        <h2
          id="motif-grid-heading"
          className="font-heading text-2xl sm:text-3xl text-text-primary mb-6"
        >
          {headline}
        </h2>
      )}
      <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
        {safeItems.map((item, index) => (
          <Card
            key={`${item.href}-${index}`}
            variant="interactive"
            asChild
          >
            <Link
              href={item.href}
              className="flex flex-col bg-surface-alt"
              style={{ touchAction: 'manipulation' } as React.CSSProperties}
            >
              <div className="relative aspect-[4/3] w-full overflow-hidden">
                <Image
                  src={item.image_url}
                  alt={item.image_alt ?? item.name}
                  fill
                  className="object-cover"
                  sizes="(min-width: 1024px) 33vw, 50vw"
                />
              </div>
              <span className="font-medium text-text-primary p-3 truncate">
                {item.name}
              </span>
            </Link>
          </Card>
        ))}
      </div>
    </section>
  )
}
