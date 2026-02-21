'use client'

import { useEffect } from 'react'
import { usePinterestTag } from '@/hooks/use-pinterest-tag'

interface CategoryPageClientProps {
  categoryName: string
}

export function CategoryPageClient({ categoryName }: CategoryPageClientProps) {
  const { trackPageVisit, trackViewCategory } = usePinterestTag()

  useEffect(() => {
    trackPageVisit()
    trackViewCategory(categoryName)
    // Nur beim ersten Render der Seite feuern
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [categoryName])

  // Kein UI-Output – nur Tracking-Seiteneffekt
  return null
}
