// frontend/components/blocks/hero-block.tsx
import Link from 'next/link'
import Image from 'next/image'
import { Skeleton } from '@/components/ui/skeleton'
import type { BlockComponentProps } from '@/lib/blocks/types'

const SHOP_NAME = process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'

interface HeroBlockData {
  heroHeadline?: string | null
  heroSubline?: string | null
  heroCtaText?: string | null
  heroCtaLink?: string | null
  heroBackgroundImage?: string | null
}

export function HeroBlock({ data }: BlockComponentProps<HeroBlockData | null>) {
  const headline = data?.heroHeadline ?? 'Einzigartige Designs auf Premium-Produkten'
  const subline = data?.heroSubline ?? 'Print-on-Demand – jedes Stück wird eigens für dich produziert.'
  const ctaText = data?.heroCtaText ?? 'Jetzt shoppen'
  const ctaLink = data?.heroCtaLink ?? '/kategorie/t-shirts'
  const bgImage = data?.heroBackgroundImage ?? null

  return (
    <section
      className="relative bg-surface-elevated rounded-2xl overflow-hidden mb-12 p-8 sm:p-12"
      aria-labelledby="hero-heading"
    >
      {bgImage && (
        <Image
          src={bgImage}
          alt=""
          fill
          className="object-cover opacity-20"
          sizes="100vw"
          priority
          aria-hidden="true"
        />
      )}
      <div className="relative max-w-lg">
        <p className="text-sm font-semibold text-primary uppercase tracking-widest mb-2">
          {SHOP_NAME}
        </p>
        <h1
          id="hero-heading"
          className="text-3xl sm:text-4xl font-bold tracking-tight text-text-primary mb-4"
          style={{ textWrap: 'balance' } as React.CSSProperties}
        >
          {headline}
        </h1>
        <p className="text-text-secondary mb-6">{subline}</p>
        <Link
          href={ctaLink}
          className="inline-flex items-center justify-center px-6 py-3 bg-primary text-white rounded-[var(--radius-button,0.5rem)] font-medium hover:bg-primary-hover transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          style={{ touchAction: 'manipulation' } as React.CSSProperties}
        >
          {ctaText}
        </Link>
      </div>
    </section>
  )
}

export function HeroBlockSkeleton() {
  return (
    <section className="relative bg-surface-elevated rounded-2xl overflow-hidden mb-12 p-8 sm:p-12">
      <div className="max-w-lg space-y-4">
        <Skeleton className="h-4 w-24" />
        <Skeleton className="h-10 w-3/4" />
        <Skeleton className="h-4 w-full" />
        <Skeleton className="h-12 w-36" />
      </div>
    </section>
  )
}
