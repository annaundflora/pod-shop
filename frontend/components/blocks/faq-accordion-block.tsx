// frontend/components/blocks/faq-accordion-block.tsx
'use client'

import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react'
import { Plus, Minus } from 'lucide-react'
import type { BlockComponentProps, FaqAccordionData } from '@/lib/blocks/types'

export function FaqAccordionBlock({ data }: BlockComponentProps<FaqAccordionData | null>) {
  const items = data?.items ?? []
  const headline = data?.headline
  const initialOpen = typeof data?.defaultOpen === 'number' ? data.defaultOpen : -1

  const [openIndex, setOpenIndex] = useState<number>(initialOpen)
  const [reducedMotion, setReducedMotion] = useState<boolean>(false)
  const panelRefs = useRef<Array<HTMLDivElement | null>>([])
  const baseId = useId()

  useEffect(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return
    const mq = window.matchMedia('(prefers-reduced-motion: reduce)')
    setReducedMotion(mq.matches)
    const handler = (e: MediaQueryListEvent) => setReducedMotion(e.matches)
    if (mq.addEventListener) {
      mq.addEventListener('change', handler)
      return () => mq.removeEventListener('change', handler)
    }
    // Safari-fallback (older API)
    mq.addListener(handler)
    return () => mq.removeListener(handler)
  }, [])

  if (!data || items.length === 0) return null

  const toggle = (i: number) => {
    setOpenIndex(prev => (prev === i ? -1 : i))
  }

  const handleSectionKeyDown = (e: KeyboardEvent<HTMLElement>) => {
    if (e.key === 'Escape' && openIndex !== -1) {
      e.preventDefault()
      setOpenIndex(-1)
    }
  }

  return (
    <section
      aria-labelledby={headline ? `${baseId}-heading` : undefined}
      onKeyDown={handleSectionKeyDown}
    >
      {headline && (
        <h2
          id={`${baseId}-heading`}
          className="font-heading text-2xl sm:text-3xl text-text-primary mb-6"
        >
          {headline}
        </h2>
      )}
      <ul className="border-t border-border">
        {items.map((item, index) => {
          const isOpen = openIndex === index
          const headerId = `${baseId}-h-${index}`
          const panelId = `${baseId}-p-${index}`
          const panelEl = panelRefs.current[index]
          const scrollHeight = panelEl?.scrollHeight ?? 0
          const Icon = isOpen ? Minus : Plus

          return (
            <li
              key={index}
              className="border-b border-border"
            >
              <button
                type="button"
                id={headerId}
                aria-expanded={isOpen}
                aria-controls={panelId}
                onClick={() => toggle(index)}
                className="w-full text-left flex items-center justify-between gap-4 py-4 text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
              >
                <span className="font-medium">{item.q}</span>
                <Icon
                  aria-hidden="true"
                  className="h-5 w-5 text-primary flex-shrink-0"
                />
              </button>
              <div
                id={panelId}
                ref={(el) => {
                  panelRefs.current[index] = el
                }}
                role="region"
                aria-labelledby={headerId}
                className="overflow-hidden motion-reduce:transition-none transition-[max-height] duration-[250ms] ease-out"
                style={{
                  maxHeight: reducedMotion
                    ? (isOpen ? 'none' : 0)
                    : (isOpen ? `${scrollHeight}px` : 0),
                  transition: reducedMotion ? 'none' : undefined,
                }}
              >
                <p className="text-text-secondary pb-4 pr-8 leading-relaxed">
                  {item.a}
                </p>
              </div>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
