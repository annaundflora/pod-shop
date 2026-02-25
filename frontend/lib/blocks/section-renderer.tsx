// frontend/lib/blocks/section-renderer.tsx

import { Suspense } from 'react'
import { SectionLayout } from './section-layout'
import { resolveBlock } from './registry'
import { loadBlockData } from './data-loaders'
import type { SectionConfig, BlockConfig } from './types'

interface SectionRendererProps {
  sections: SectionConfig[]
  skeletonMap?: Record<string, React.ReactNode>
}

async function BlockRenderer({ block }: { block: BlockConfig }) {
  const BlockComponent = resolveBlock(block.type)
  if (!BlockComponent) return null

  const { data } = await loadBlockData(block.content_source, block.params)
  return <BlockComponent data={data} />
}

export function SectionRenderer({ sections, skeletonMap = {} }: SectionRendererProps) {
  return (
    <>
      {sections.map((section, sectionIndex) => (
        <SectionLayout
          key={sectionIndex}
          columns={section.columns ?? 1}
          gap={section.gap ?? 'gap-8'}
        >
          {section.blocks.map((block, blockIndex) => {
            const skeleton = skeletonMap[block.type] ?? null
            const key = `${block.type}-${sectionIndex}-${blockIndex}`

            // Design-Entscheidung: Einheitliches Suspense-Wrapping fuer alle Block-Types (auch inline).
            // Konsistentes Pattern vereinfacht SectionRenderer-Code und ermoeglicht kuenftige
            // loadBlockData()-Aenderungen ohne Suspense-Refactoring. Inline-Blocks loesen ohne
            // Netzwerk-Request auf (vernachlaessigbarer Overhead).
            return (
              <div
                key={key}
                style={{
                  gridColumn: block.span && block.span > 1 ? `span ${block.span}` : undefined,
                  gridRow: block.row_span && block.row_span > 1 ? `span ${block.row_span}` : undefined,
                }}
              >
                <Suspense fallback={skeleton}>
                  <BlockRenderer block={block} />
                </Suspense>
              </div>
            )
          })}
        </SectionLayout>
      ))}
    </>
  )
}
