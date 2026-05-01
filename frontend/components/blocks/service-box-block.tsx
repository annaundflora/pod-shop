// frontend/components/blocks/service-box-block.tsx
import {
  Coffee,
  Ruler,
  Leaf,
  Droplet,
  Package,
  Truck,
  RefreshCw,
  Lock,
  ShoppingBag,
  type LucideProps,
} from 'lucide-react'
import type { BlockComponentProps, ServiceBoxData } from '@/lib/blocks/types'

// Local ICON_MAP per repo convention (each block carries its own icon set).
// Keys are kebab-case YAML strings; values are lucide-react components.
const ICON_MAP: Record<string, React.ComponentType<LucideProps>> = {
  coffee: Coffee,
  ruler: Ruler,
  leaf: Leaf,
  droplet: Droplet,
  package: Package,
  truck: Truck,
  refresh: RefreshCw,
  lock: Lock,
  'shopping-bag': ShoppingBag,
}

export function ServiceBoxBlock({ data }: BlockComponentProps<ServiceBoxData | null>) {
  const items = data?.items ?? []

  if (!data || items.length === 0) return null

  return (
    <section
      aria-label="Produkt-Service-Hinweise"
      className="bg-surface-alt rounded-card shadow-card p-6"
    >
      <ul className="flex flex-col gap-3">
        {items.map((item, index) => {
          const Icon = ICON_MAP[item.icon] ?? null
          return (
            <li key={index} className="flex items-start gap-3">
              {Icon && (
                <Icon
                  className="h-5 w-5 text-primary flex-shrink-0 mt-0.5"
                  aria-hidden="true"
                />
              )}
              <div className="min-w-0">
                <strong className="text-text-primary block">{item.label}</strong>
                <p className="text-sm text-text-secondary">{item.detail}</p>
              </div>
            </li>
          )
        })}
      </ul>
    </section>
  )
}
