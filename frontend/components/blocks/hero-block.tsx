// frontend/components/blocks/hero-block.tsx
import Link from 'next/link'
import Image from 'next/image'
import { Truck, Shield, Lock, RefreshCw, Star, Check, Package, type LucideProps } from 'lucide-react'
import { Skeleton } from '@/components/ui/skeleton'
import { Button } from '@/components/ui/button'
import type { BlockComponentProps } from '@/lib/blocks/types'

const SHOP_NAME = process.env.NEXT_PUBLIC_SHOP_NAME ?? 'POD Shop'

// Local ICON_MAP mirrors the trust-badges set so the hero two-col trust-row stays self-contained.
const ICON_MAP: Record<string, React.ComponentType<LucideProps>> = {
  truck: Truck,
  shield: Shield,
  lock: Lock,
  refresh: RefreshCw,
  star: Star,
  check: Check,
  package: Package,
}

interface HeroTrustItem {
  icon: string
  text: string
}

interface HeroVisual {
  imageUrl?: string
  alt?: string
}

interface HeroCtaSecondary {
  text: string
  href: string
}

interface HeroBlockData {
  // Existing (default variant, WP source)
  heroHeadline?: string | null
  heroSubline?: string | null
  heroCtaText?: string | null
  heroCtaLink?: string | null
  heroBackgroundImage?: string | null
  // NEW (two-col variant, inline YAML source)
  variant?: 'default' | 'two-col'
  tag?: string
  ctaSecondary?: HeroCtaSecondary
  trustItems?: HeroTrustItem[]
  visual?: HeroVisual
}

export function HeroBlock({ data }: BlockComponentProps<HeroBlockData | null>) {
  if (data?.variant === 'two-col') {
    return <HeroTwoCol data={data} />
  }

  // Existing single-column render path — unchanged for default theme.
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
        <Button asChild>
          <Link href={ctaLink}>
            {ctaText}
          </Link>
        </Button>
      </div>
    </section>
  )
}

function HeroTwoCol({ data }: { data: HeroBlockData }) {
  const headline = data.heroHeadline ?? ''
  const subline = data.heroSubline ?? ''
  const ctaText = data.heroCtaText ?? ''
  const ctaLink = data.heroCtaLink ?? '/'
  const tag = data.tag
  const ctaSecondary = data.ctaSecondary
  const trustItems = (data.trustItems ?? []).slice(0, 3)
  const visual = data.visual ?? {}
  const visualUrl = typeof visual.imageUrl === 'string' && visual.imageUrl.length > 0
    ? visual.imageUrl
    : null
  const visualAlt = visual.alt ?? ''

  return (
    <section
      className="grid grid-cols-1 md:grid-cols-[1.1fr_1fr] gap-8 lg:gap-12 items-center"
      aria-labelledby="hero-heading"
    >
      <div className="flex flex-col gap-4">
        {tag && (
          <span className="inline-block w-fit text-xs uppercase tracking-widest font-semibold text-accent bg-surface px-3 py-1 rounded-pill border border-border">
            {tag}
          </span>
        )}
        <h1
          id="hero-heading"
          className="font-heading text-4xl sm:text-5xl text-primary"
          style={{ textWrap: 'balance' } as React.CSSProperties}
        >
          {headline}
        </h1>
        {subline && (
          <p className="text-text-secondary text-base sm:text-lg leading-relaxed">
            {subline}
          </p>
        )}
        <div className="flex flex-wrap items-center gap-3 mt-2">
          {ctaText && (
            <Button asChild>
              <Link href={ctaLink}>{ctaText}</Link>
            </Button>
          )}
          {ctaSecondary && (
            <Button asChild variant="ghost">
              <Link href={ctaSecondary.href}>{ctaSecondary.text}</Link>
            </Button>
          )}
        </div>
        {trustItems.length > 0 && (
          <ul className="flex flex-wrap gap-x-6 gap-y-2 mt-6 pt-6 border-t border-border">
            {trustItems.map((item, index) => {
              const Icon = ICON_MAP[item.icon] ?? null
              return (
                <li
                  key={index}
                  className="flex items-center gap-2 text-sm text-text-secondary"
                >
                  {Icon && (
                    <Icon
                      className="h-4 w-4 text-primary flex-shrink-0"
                      aria-hidden="true"
                    />
                  )}
                  <span>{item.text}</span>
                </li>
              )
            })}
          </ul>
        )}
      </div>

      {/* Right column: visual */}
      <div className="relative w-full aspect-[4/3] rounded-card overflow-hidden bg-surface-alt">
        {visualUrl ? (
          <Image
            src={visualUrl}
            alt={visualAlt}
            fill
            className="object-cover"
            sizes="(min-width: 768px) 50vw, 100vw"
            priority
          />
        ) : (
          <svg
            aria-hidden="true"
            className="absolute inset-0 w-full h-full"
            viewBox="0 0 400 300"
            preserveAspectRatio="xMidYMid slice"
          >
            <rect width="400" height="300" fill="currentColor" className="text-surface-alt" />
            <g
              className="text-primary"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              opacity="0.35"
            >
              <path d="M200 80 C 230 110, 250 140, 220 180 C 190 220, 150 220, 130 190 C 110 160, 130 120, 170 100 C 185 92, 195 88, 200 80 Z" />
              <path d="M200 80 L200 220" />
            </g>
          </svg>
        )}
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
