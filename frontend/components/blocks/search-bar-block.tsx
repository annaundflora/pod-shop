// frontend/components/blocks/search-bar-block.tsx
'use client'

import { useRef, useState } from 'react'
import { useRouter } from 'next/navigation'
import { Search, X } from 'lucide-react'
import type { SearchBarData } from '@/lib/blocks/types'
import type { BlockComponentProps } from '@/lib/blocks/types'

export function SearchBarBlock({ data }: BlockComponentProps<SearchBarData>) {
  const router = useRouter()
  const inputRef = useRef<HTMLInputElement>(null)
  const [hasValue, setHasValue] = useState(Boolean(data.currentQuery))

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    const value = inputRef.current?.value?.trim() ?? ''
    if (value.length < 2) return
    router.push(`/suche?q=${encodeURIComponent(value)}`)
  }

  function handleClear() {
    if (inputRef.current) inputRef.current.value = ''
    setHasValue(false)
    router.push('/suche')
  }

  return (
    <form role="search" aria-label="Produktsuche" onSubmit={handleSubmit}
      className="flex gap-2 w-full max-w-2xl mx-auto">
      <div className="relative flex-1">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-text-secondary"
          aria-hidden={true} />
        <input
          ref={inputRef}
          type="search"
          name="q"
          aria-label="Suchbegriff eingeben"
          placeholder={data.placeholder}
          defaultValue={data.currentQuery}
          onChange={e => setHasValue(e.target.value.length > 0)}
          className="w-full pl-10 pr-10 py-3 border border-border rounded-card text-text-primary bg-white focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
        />
        {hasValue && (
          <button
            type="button"
            onClick={handleClear}
            aria-label="Suche löschen"
            className="absolute right-3 top-1/2 -translate-y-1/2 text-text-secondary hover:text-text-primary"
            style={{ touchAction: 'manipulation' }}
          >
            <X className="w-5 h-5" aria-hidden={true} />
          </button>
        )}
      </div>
      <button
        type="submit"
        className="px-6 py-3 bg-primary text-white rounded-card hover:opacity-90 focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
        style={{ touchAction: 'manipulation' }}
      >
        Suchen
      </button>
    </form>
  )
}
