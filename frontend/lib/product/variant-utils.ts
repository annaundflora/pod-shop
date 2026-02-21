import type { ProductVariation, VariantOptions } from '../graphql/types'

// Bekannte Farben → Hex-Codes (erweiterbar)
const COLOR_HEX_MAP: Record<string, string> = {
  schwarz: '#000000',
  black: '#000000',
  weiß: '#FFFFFF',
  weiss: '#FFFFFF',
  white: '#FFFFFF',
  grau: '#808080',
  gray: '#808080',
  grey: '#808080',
  rot: '#CC0000',
  red: '#CC0000',
  blau: '#0066CC',
  blue: '#0066CC',
  navy: '#001F5B',
  grün: '#228B22',
  green: '#228B22',
}

/**
 * Extrahiert alle einzigartigen Größen und Farben aus den Produktvariationen.
 * Markiert verfügbare/nicht-verfügbare Optionen basierend auf stockStatus.
 */
export function extractVariantOptions(variations: ProductVariation[]): VariantOptions {
  const sizeMap = new Map<string, boolean>()
  const colorMap = new Map<string, boolean>()

  for (const variation of variations) {
    const isAvailable = variation.stockStatus === 'IN_STOCK'

    for (const attr of variation.attributes.nodes) {
      const attrName = attr.name.toLowerCase()
      const attrValue = attr.value

      if (attrName === 'pa_size' || attrName === 'size' || attrName === 'größe') {
        // Verfügbar wenn mindestens eine Variation mit dieser Größe IN_STOCK ist
        const existing = sizeMap.get(attrValue)
        sizeMap.set(attrValue, existing === true ? true : isAvailable)
      }

      if (attrName === 'pa_color' || attrName === 'color' || attrName === 'farbe') {
        const existing = colorMap.get(attrValue)
        colorMap.set(attrValue, existing === true ? true : isAvailable)
      }
    }
  }

  const sizes = Array.from(sizeMap.entries()).map(([value, available]) => ({
    value,
    available,
  }))

  const colors = Array.from(colorMap.entries()).map(([value, available]) => ({
    value,
    hexCode: COLOR_HEX_MAP[value.toLowerCase()] ?? null,
    available,
  }))

  return { sizes, colors }
}

/**
 * Findet die passende Variation basierend auf ausgewählter Größe und Farbe.
 * Gibt null zurück wenn keine passende Variation gefunden.
 */
export function findVariation(
  variations: ProductVariation[],
  selectedSize: string | null,
  selectedColor: string | null
): ProductVariation | null {
  if (!selectedSize || !selectedColor) return null

  return (
    variations.find((variation) => {
      const attrs = variation.attributes.nodes
      const hasSize = attrs.some(
        (a) =>
          (a.name.toLowerCase() === 'pa_size' ||
            a.name.toLowerCase() === 'size' ||
            a.name.toLowerCase() === 'größe') &&
          a.value === selectedSize
      )
      const hasColor = attrs.some(
        (a) =>
          (a.name.toLowerCase() === 'pa_color' ||
            a.name.toLowerCase() === 'color' ||
            a.name.toLowerCase() === 'farbe') &&
          a.value === selectedColor
      )
      return hasSize && hasColor
    }) ?? null
  )
}
