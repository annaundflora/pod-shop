// frontend/components/blocks/announcement-bar-block.tsx
'use client'

import { useState, useEffect } from 'react'
import { X } from 'lucide-react'
import type { BlockComponentProps } from '@/lib/blocks/types'

interface AnnouncementBarData {
  id: string
  text: string
  link?: string
  dismissible?: boolean
  bgColor?: string
}

const BG_COLOR_MAP: Record<string, string> = {
  primary: 'bg-primary',
  secondary: 'bg-secondary',
}

export function AnnouncementBarBlock({ data }: BlockComponentProps<AnnouncementBarData>) {
  const [dismissed, setDismissed] = useState(false)
  const [mounted, setMounted] = useState(false)

  useEffect(() => {
    setMounted(true)
    const isDismissed = localStorage.getItem(`announcement-dismissed-${data.id}`)
    if (isDismissed) setDismissed(true)
  }, [data.id])

  // Vor Mount: render null (verhindert Hydration-Mismatch)
  if (!mounted || dismissed) return null

  const bgClass = BG_COLOR_MAP[data.bgColor ?? 'primary'] ?? 'bg-primary'
  const isDismissible = data.dismissible !== false

  const handleDismiss = () => {
    localStorage.setItem(`announcement-dismissed-${data.id}`, '1')
    setDismissed(true)
  }

  const content = (
    <div className={`${bgClass} text-primary-foreground py-2 px-4`}>
      <div className="max-w-7xl mx-auto flex items-center justify-center gap-2 relative">
        <span className="text-sm text-center">{data.text}</span>
        {isDismissible && (
          <button
            onClick={handleDismiss}
            aria-label="Ankündigung schliessen"
            className="absolute right-0 p-1 hover:opacity-70 focus-visible:ring-2 focus-visible:ring-offset-1 rounded touch-manipulation"
            style={{ touchAction: 'manipulation' }}
          >
            <X className="h-4 w-4" aria-hidden={true} />
          </button>
        )}
      </div>
    </div>
  )

  if (data.link) {
    return (
      <a href={data.link} className="block hover:opacity-90 transition-opacity">
        {content}
      </a>
    )
  }

  return content
}
