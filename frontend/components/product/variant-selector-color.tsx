// frontend/components/product/variant-selector-color.tsx
'use client'

interface ColorOption {
  value: string
  hexCode: string | null
  available: boolean
}

interface VariantSelectorColorProps {
  colors: ColorOption[]
  selectedColor: string | null
  onColorSelect: (color: string) => void
}

export function VariantSelectorColor({
  colors,
  selectedColor,
  onColorSelect,
}: VariantSelectorColorProps) {
  return (
    <fieldset>
      <legend className="text-sm font-medium text-text-primary mb-3">
        Farbe: <span className="font-normal">{selectedColor ?? 'Bitte wählen'}</span>
      </legend>
      <div className="flex flex-wrap gap-3" role="group" aria-label="Farbe auswählen">
        {colors.map(({ value, hexCode, available }) => {
          const isSelected = selectedColor === value
          return (
            <button
              key={value}
              type="button"
              onClick={() => available && onColorSelect(value)}
              aria-label={`${value}${!available ? ' (nicht verfügbar)' : ''}`}
              aria-pressed={isSelected}
              aria-disabled={!available}
              disabled={!available}
              className={[
                'relative w-10 h-10 rounded-full border-2 transition-all duration-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
                isSelected ? 'ring-2 ring-primary ring-offset-2 scale-110 shadow-[var(--shadow-card)]' : '',
                !available ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:scale-110 hover:shadow-[var(--shadow-card)]',
                hexCode ? 'border-border' : 'border-border',
              ].join(' ')}
              style={hexCode ? { backgroundColor: hexCode } : undefined}
            >
              {!hexCode && (
                <span className="sr-only">{value}</span>
              )}
              {/* Strikethrough-Overlay für nicht verfügbare Farben */}
              {!available && (
                <span
                  aria-hidden="true"
                  className="absolute inset-0 flex items-center justify-center"
                >
                  <svg
                    className="w-full h-full text-text-secondary opacity-70"
                    viewBox="0 0 36 36"
                    fill="none"
                  >
                    <line x1="6" y1="6" x2="30" y2="30" stroke="currentColor" strokeWidth="2" />
                  </svg>
                </span>
              )}
            </button>
          )
        })}
      </div>
    </fieldset>
  )
}
