// frontend/app/impressum/page.tsx
import { SectionRenderer } from '@/lib/blocks/section-renderer'
import { loadPageConfig } from '@/lib/blocks/page-config'
import { LegalContentBlockSkeleton } from '@/components/blocks/legal-content-block'
import { PageHeadingBlockSkeleton } from '@/components/blocks/page-heading-block'
import type { Metadata } from 'next'
import type React from 'react'

export const metadata: Metadata = {
  title: 'Impressum',
}

const SKELETON_MAP: Record<string, React.ReactNode> = {
  'page-heading': <PageHeadingBlockSkeleton />,
  'legal-content': <LegalContentBlockSkeleton />,
}

export default function ImpressumPage() {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const pageConfig = loadPageConfig('legal', theme, { slug: 'impressum' })

  return (
    <main id="main-content" className="max-w-3xl mx-auto px-4 py-12 space-y-8">
      <SectionRenderer
        sections={pageConfig.sections}
        skeletonMap={SKELETON_MAP}
      />
    </main>
  )
}
