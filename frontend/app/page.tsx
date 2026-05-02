// frontend/app/page.tsx
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import { HeroBlockSkeleton } from '@/components/blocks/hero-block'
import { ProductGridBlockSkeleton } from '@/components/blocks/product-grid-block'
import { CategoryShowcaseBlockSkeleton } from '@/components/blocks/category-showcase-block'
import { FeaturedCollectionBlockSkeleton } from '@/components/blocks/featured-collection-block'

// ISR: Seite wird alle 60 Sekunden neu gebaut
export const revalidate = 60

// Skeleton-Mapping: Block-Typ → Skeleton-Component (für Suspense fallback)
const SKELETON_MAP: Record<string, React.ReactNode> = {
  'hero': <HeroBlockSkeleton />,
  'product-grid': <ProductGridBlockSkeleton />,
  'category-showcase': <CategoryShowcaseBlockSkeleton />,
  'usp-bar': null,
  // Slice 04 — Homepage Enhancements:
  'testimonials': null,           // inline, kein async — kein Skeleton nötig
  'newsletter-signup': null,      // inline, kein async — kein Skeleton nötig
  'featured-collection': <FeaturedCollectionBlockSkeleton />,
  // Slice 07 — Kleinstadtpflanze Layout-Flair:
  'editorial-mini': null,         // inline-sync, kein Skeleton nötig
  'faq-accordion': null,          // inline-sync, kein Skeleton nötig
  'motif-grid': null,             // inline-sync, kein Skeleton nötig
  'brand-recap': null,            // inline-sync, kein Skeleton nötig
  'trust-badges': null,           // inline-sync, kein Skeleton nötig
}

export default function HomePage() {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const pageConfig = loadPageConfig('home', theme)

  return (
    <main id="main-content">
      <div className="space-y-12">
        <SectionRenderer
          sections={pageConfig.sections}
          skeletonMap={SKELETON_MAP}
        />
      </div>
    </main>
  )
}
