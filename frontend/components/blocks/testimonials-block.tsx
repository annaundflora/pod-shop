// frontend/components/blocks/testimonials-block.tsx
import { Star } from 'lucide-react'
import type { BlockComponentProps } from '@/lib/blocks/types'

interface TestimonialsItem {
  name: string
  text: string
  rating: number
  location?: string
}

interface TestimonialsData {
  items: TestimonialsItem[]
}

export function TestimonialsBlock({ data }: BlockComponentProps<TestimonialsData>) {
  const items = data?.items ?? []

  if (items.length === 0) return null

  return (
    <section aria-label="Kundenbewertungen">
      <ul className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        {items.map((item, index) => (
          <li key={index}>
            <figure
              className="bg-surface rounded-card shadow-card p-6 h-full flex flex-col gap-3"
            >
              {/* Sterne-Rating */}
              <div
                aria-label={`${item.rating} von 5 Sternen`}
                className="flex items-center gap-0.5"
              >
                {Array.from({ length: 5 }, (_, i) => (
                  <Star
                    key={i}
                    aria-hidden={true}
                    className={`h-4 w-4 ${i < item.rating ? 'text-yellow-400 fill-yellow-400' : 'text-text-tertiary'}`}
                  />
                ))}
              </div>

              {/* Zitat */}
              <blockquote className="flex-1">
                <p className="text-text-secondary italic text-sm leading-relaxed">
                  {item.text}
                </p>
              </blockquote>

              {/* Name + Location */}
              <figcaption className="text-sm font-medium text-text-primary">
                — {item.name}{item.location ? `, ${item.location}` : ''}
              </figcaption>
            </figure>
          </li>
        ))}
      </ul>
    </section>
  )
}
