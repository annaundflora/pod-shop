// frontend/app/page.tsx
import { Suspense } from 'react'
import { loadPageConfig } from '@/lib/blocks/page-config'
import { resolveBlock } from '@/lib/blocks/registry'
import { loadBlockData } from '@/lib/blocks/data-loaders'
import { HeroBlockSkeleton } from '@/components/blocks/hero-block'
import { ProductGridBlockSkeleton } from '@/components/blocks/product-grid-block'
import { CategoryShowcaseBlockSkeleton } from '@/components/blocks/category-showcase-block'
import type { BlockConfig } from '@/lib/blocks/types'

// ISR: Seite wird alle 60 Sekunden neu gebaut
export const revalidate = 60

// Skeleton-Mapping: Block-Typ → Skeleton-Component (für Suspense fallback)
const SKELETON_MAP: Record<string, React.ReactNode> = {
  'hero': <HeroBlockSkeleton />,
  'product-grid': <ProductGridBlockSkeleton />,
  'category-showcase': <CategoryShowcaseBlockSkeleton />,
  'usp-bar': null, // UspBar ist inline/synchron — kein Skeleton nötig
}

async function BlockRenderer({ block }: { block: BlockConfig }) {
  const BlockComponent = resolveBlock(block.type)

  // Unbekannter Block-Typ → überspringen (console.warn wurde bereits in resolveBlock() aufgerufen)
  if (!BlockComponent) return null

  const { data } = await loadBlockData(block.content_source, block.params)

  return <BlockComponent data={data} />
}

export default function HomePage() {
  const theme = process.env.NEXT_PUBLIC_THEME ?? 'default'
  const pageConfig = loadPageConfig('home', theme)

  return (
    <main id="main-content">
      <div className="space-y-12">
        {pageConfig.blocks.map((block, index) => {
          const skeleton = SKELETON_MAP[block.type] ?? null

          // inline Blöcke brauchen kein Suspense (synchron)
          if (block.content_source === 'inline') {
            return (
              <BlockRenderer key={`${block.type}-${index}`} block={block} />
            )
          }

          return (
            <Suspense key={`${block.type}-${index}`} fallback={skeleton}>
              <BlockRenderer block={block} />
            </Suspense>
          )
        })}
      </div>
    </main>
  )
}
