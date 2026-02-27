// frontend/components/blocks/sort-bar-block.tsx
'use client'

import { useTransition } from 'react'
import { useRouter } from 'next/navigation'
import type { BlockComponentProps, SortOption } from '@/lib/blocks/types'

interface SortBarData {
  currentSort: SortOption
  baseUrl: string
  currentQuery?: string
}

const SORT_OPTIONS: { value: SortOption; label: string }[] = [
  { value: 'default', label: 'Empfohlen' },
  { value: 'price_asc', label: 'Preis: aufsteigend' },
  { value: 'price_desc', label: 'Preis: absteigend' },
  { value: 'newest', label: 'Neueste zuerst' },
]

export function SortBarBlock({ data }: BlockComponentProps<SortBarData>) {
  const router = useRouter()
  const [isPending, startTransition] = useTransition()

  const handleChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const newSort = e.target.value as SortOption
    const params = new URLSearchParams()
    if (newSort !== 'default') params.set('sort', newSort)
    params.set('page', '1')
    if (data.currentQuery) params.set('q', data.currentQuery)
    const url = `${data.baseUrl}?${params.toString()}`
    startTransition(() => {
      router.push(url)
    })
  }

  return (
    <div className={`flex items-center gap-2 ${isPending ? 'opacity-70 pointer-events-none' : ''}`}>
      <label htmlFor="sort-select" className="text-sm text-text-secondary whitespace-nowrap">
        Sortieren nach:
      </label>
      <select
        id="sort-select"
        aria-label="Produkte sortieren"
        value={data.currentSort}
        onChange={handleChange}
        className="border border-border rounded-card px-3 py-1.5 text-sm bg-white dark:bg-surface-elevated dark:text-text-primary focus-visible:ring-2 focus-visible:ring-primary"
      >
        {SORT_OPTIONS.map(opt => (
          <option key={opt.value} value={opt.value}>{opt.label}</option>
        ))}
      </select>
    </div>
  )
}
