// frontend/components/blocks/legal-content-block.tsx
import type { WPPageContent } from '@/lib/blocks/types'

interface LegalContentBlockProps {
  data: WPPageContent | null
}

export function LegalContentBlock({ data }: LegalContentBlockProps) {
  if (!data) {
    return (
      <div className="rounded-card bg-surface border border-border p-8 text-center text-text-secondary">
        Inhalt konnte nicht geladen werden.
      </div>
    )
  }

  if (!data.content) {
    return (
      <div className="rounded-card bg-surface border border-border p-8 text-center text-text-secondary">
        Kein Inhalt vorhanden.
      </div>
    )
  }

  return (
    <div
      className="prose prose-sm max-w-none text-text-primary [&_h2]:text-text-primary [&_h3]:text-text-primary [&_a]:text-primary [&_a:hover]:underline"
      dangerouslySetInnerHTML={{ __html: data.content }}
    />
  )
}

export function LegalContentBlockSkeleton() {
  return (
    <div className="space-y-4 animate-pulse">
      <div className="h-4 bg-surface-elevated rounded w-full" />
      <div className="h-4 bg-surface-elevated rounded w-5/6" />
      <div className="h-4 bg-surface-elevated rounded w-full" />
      <div className="h-4 bg-surface-elevated rounded w-4/6" />
      <div className="h-4 bg-surface-elevated rounded w-full" />
      <div className="h-4 bg-surface-elevated rounded w-3/4" />
    </div>
  )
}
