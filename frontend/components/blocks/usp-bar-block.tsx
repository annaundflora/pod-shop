// frontend/components/blocks/usp-bar-block.tsx
import { Truck, Shield, RefreshCw, Star, type LucideProps } from 'lucide-react'
import type { BlockComponentProps } from '@/lib/blocks/types'

// Mapping von YAML icon-Namen auf lucide-react Components
const ICON_MAP: Record<string, React.ComponentType<LucideProps>> = {
  truck: Truck,
  shield: Shield,
  refresh: RefreshCw,
  star: Star,
}

interface UspItem {
  icon: string
  text: string
}

interface UspBarBlockData {
  items: UspItem[]
}

export function UspBarBlock({ data }: BlockComponentProps<UspBarBlockData>) {
  const items = data?.items ?? []

  if (items.length === 0) return null

  return (
    <section aria-label="Unsere Vorteile">
      <ul className="flex flex-wrap justify-center gap-4 sm:gap-8 py-4">
        {items.map((item, index) => {
          const IconComponent = ICON_MAP[item.icon] ?? null
          return (
            <li
              key={index}
              className="flex items-center gap-2 text-sm text-text-secondary"
            >
              {IconComponent && (
                <IconComponent
                  className="h-4 w-4 text-primary flex-shrink-0"
                  aria-hidden={true}
                />
              )}
              <span>{item.text}</span>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
