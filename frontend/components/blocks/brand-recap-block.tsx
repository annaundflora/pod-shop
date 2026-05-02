// frontend/components/blocks/brand-recap-block.tsx
import Link from 'next/link'
import type { BlockComponentProps, BrandRecapData } from '@/lib/blocks/types'

const ENV_SHOP_NAME = process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'

export function BrandRecapBlock({ data }: BlockComponentProps<BrandRecapData | null>) {
  if (!data || !data.tagline) return null

  const logoText = data.logoText ?? ENV_SHOP_NAME
  const href = data.href ?? '/'
  const tagline = data.tagline

  return (
    <section
      aria-label="Marke"
      className="border-y border-border bg-surface-alt py-6 text-center"
    >
      <div className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 px-4">
        <Link
          href={href}
          className="font-heading text-lg text-text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary rounded-card"
        >
          {logoText}
        </Link>
        <span aria-hidden="true" className="text-text-secondary">·</span>
        <span className="text-sm text-text-secondary">{tagline}</span>
      </div>
    </section>
  )
}
