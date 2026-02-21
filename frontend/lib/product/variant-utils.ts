import type { ProductVariation, VariantOptions } from '../graphql/types'

// Bekannte WooCommerce-Attribut-Slugs für Größe und Farbe
const SIZE_ATTR_NAMES = ['pa_size', 'pa_groesse', 'size', 'größe', 'groesse']
const COLOR_ATTR_NAMES = ['pa_color', 'pa_farbe', 'color', 'farbe']

// Bekannte Farben → Hex-Codes (erweiterbar)
const COLOR_HEX_MAP: Record<string, string> = {
  schwarz: '#000000',
  black: '#000000',
  weiß: '#FFFFFF',
  weiss: '#FFFFFF',
  weis: '#FFFFFF',
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

      if (SIZE_ATTR_NAMES.includes(attrName)) {
        // Verfügbar wenn mindestens eine Variation mit dieser Größe IN_STOCK ist
        const existing = sizeMap.get(attrValue)
        sizeMap.set(attrValue, existing === true ? true : isAvailable)
      }

      if (COLOR_ATTR_NAMES.includes(attrName)) {
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
 * Findet die passende Variation basierend auf ausgewählter Größe und/oder Farbe.
 * Wenn eine Variation ein Attribut besitzt, muss es ausgewählt sein und matchen.
 * Gibt null zurück wenn keine Auswahl getroffen wurde oder keine Variation passt.
 */
export function findVariation(
  variations: ProductVariation[],
  selectedSize: string | null,
  selectedColor: string | null
): ProductVariation | null {
  if (!selectedSize && !selectedColor) return null

  return (
    variations.find((variation) => {
      const attrs = variation.attributes.nodes

      const sizeAttr = attrs.find((a) => SIZE_ATTR_NAMES.includes(a.name.toLowerCase()))
      const colorAttr = attrs.find((a) => COLOR_ATTR_NAMES.includes(a.name.toLowerCase()))

      // Wenn die Variation ein Größen-Attribut hat, muss es ausgewählt sein und matchen
      if (sizeAttr && sizeAttr.value !== selectedSize) return false
      // Wenn die Variation ein Farb-Attribut hat, muss es ausgewählt sein und matchen
      if (colorAttr && colorAttr.value !== selectedColor) return false

      return true
    }) ?? null
  )
}
