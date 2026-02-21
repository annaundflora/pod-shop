// frontend/components/product/variant-selector-size.tsx
'use client'

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
            <button
              key={value}
              type="button"
              onClick={() => available && onSizeSelect(value)}
              aria-pressed={isSelected}
              aria-disabled={!available}
              disabled={!available}
              className={[
                'min-h-[44px] min-w-[44px] px-4 py-2 rounded-lg border text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1',
                isSelected
                  ? 'bg-text-primary text-surface border-text-primary'
                  : available
                    ? 'bg-surface text-text-primary border-border hover:border-text-primary'
                    : 'bg-surface-elevated text-text-secondary border-border opacity-50 cursor-not-allowed',
              ].join(' ')}
            >
              {value}
            </button>
          )
        })}
      </div>
    </fieldset>
  )
}
