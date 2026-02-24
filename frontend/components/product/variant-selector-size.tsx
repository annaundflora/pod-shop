// frontend/components/product/variant-selector-size.tsx
'use client'

import { Button } from '@/components/ui/button'

interface SizeOption {
  value: string
  available: boolean
}

interface VariantSelectorSizeProps {
  sizes: SizeOption[]
  selectedSize: string | null
  onSizeSelect: (size: string) => void
}

export function VariantSelectorSize({
  sizes,
  selectedSize,
  onSizeSelect,
}: VariantSelectorSizeProps) {
  return (
    <fieldset>
      <legend className="text-sm font-medium text-text-primary mb-3">Größe</legend>
      <div className="flex flex-wrap gap-2" role="group" aria-label="Größe auswählen">
        {sizes.map(({ value, available }) => {
          const isSelected = selectedSize === value
          return (
            <Button
              key={value}
              type="button"
              variant={isSelected ? 'default' : 'outline'}
              onClick={() => available && onSizeSelect(value)}
              aria-pressed={isSelected}
              aria-disabled={!available}
              disabled={!available}
              className="min-h-[44px] min-w-[44px] px-4"
            >
              {value}
            </Button>
          )
        })}
      </div>
    </fieldset>
  )
}
