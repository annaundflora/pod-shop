// frontend/components/blocks/trust-badges-block.tsx
import { Truck, Shield, Lock, RefreshCw, Star, Check, Package, type LucideProps } from 'lucide-react'
import type { BlockComponentProps } from '@/lib/blocks/types'

const ICON_MAP: Record<string, React.ComponentType<LucideProps>> = {
  truck: Truck,
  shield: Shield,
  lock: Lock,
  refresh: RefreshCw,
  star: Star,
  check: Check,
  package: Package,
}

interface TrustBadgeItem {
  icon: string
  text: string
}

interface TrustBadgeData {
  items: TrustBadgeItem[]
}

export function TrustBadgesBlock({ data }: BlockComponentProps<TrustBadgeData>) {
  const items = data?.items ?? []

  if (items.length === 0) return null

  return (
    <section aria-label="Vertrauenssiegel">
      <ul className="flex flex-wrap justify-center gap-6 py-4">
        {items.map((item, index) => {
          const IconComponent = ICON_MAP[item.icon] ?? null
          return (
            <li
              key={index}
              className="flex items-center gap-2 border border-border rounded-card p-3"
            >
              {IconComponent && (
                <IconComponent
                  className="h-5 w-5 text-primary flex-shrink-0"
                  aria-hidden={true}
                />
              )}
              <span className="text-sm text-text-secondary">{item.text}</span>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
