// frontend/components/blocks/editorial-mini-block.tsx
import Image from 'next/image'
import Link from 'next/link'
import { Button } from '@/components/ui/button'
import type { BlockComponentProps, EditorialMiniData } from '@/lib/blocks/types'

export function EditorialMiniBlock({ data }: BlockComponentProps<EditorialMiniData | null>) {
  if (!data || !data.headline) return null

  const tag = data.tag
  const headline = data.headline
  const text = data.text
  const cta = data.cta
  const imageUrl = data.image_url
  const imageAlt = data.image_alt ?? ''

  return (
    <section
      aria-labelledby="editorial-mini-heading"
      className="bg-surface-alt rounded-card shadow-card overflow-hidden grid grid-cols-1 md:grid-cols-2 gap-8 p-6 md:p-10 items-center"
    >
      {/* Left column: image or placeholder */}
      <div className="relative aspect-[4/3] w-full overflow-hidden rounded-card">
        {imageUrl ? (
          <Image
            src={imageUrl}
            alt={imageAlt}
            fill
            className="object-cover"
            sizes="(min-width: 768px) 50vw, 100vw"
          />
        ) : (
          <div
            className="w-full h-full bg-surface-elevated"
            aria-hidden="true"
          />
        )}
      </div>

      {/* Right column: content */}
      <div className="flex flex-col gap-3">
        {tag && (
          <span className="inline-block w-fit text-xs uppercase tracking-widest font-semibold text-accent">
            {tag}
          </span>
        )}
        <h2
          id="editorial-mini-heading"
          className="font-heading text-2xl sm:text-3xl text-text-primary"
          style={{ textWrap: 'balance' } as React.CSSProperties}
        >
          {headline}
        </h2>
        <p className="text-text-secondary leading-relaxed">{text}</p>
        {cta && (
          <div className="mt-2">
            <Button asChild variant="outline">
              <Link href={cta.href}>{cta.text}</Link>
            </Button>
          </div>
        )}
      </div>
    </section>
  )
}
