// Produkt-Attribute (Größe, Farbe)
export interface ProductAttribute {
  name: string   // z.B. "pa_size" oder "pa_color"
  value: string  // z.B. "L" oder "Schwarz"
}

// Produktvariation
export interface ProductVariation {
  id: string
  databaseId: number
  stockStatus: 'IN_STOCK' | 'OUT_OF_STOCK' | 'ON_BACKORDER'
  attributes: {
    nodes: ProductAttribute[]
  }
}

// Produktbild
export interface ProductImage {
  sourceUrl: string
  altText: string
  mediaDetails: {
    width: number
    height: number
  }
}

// Produktkategorie
export interface ProductCategory {
  id: string
  slug: string
  name: string
  count?: number
  image?: ProductImage | null
}

// Produkt-Card (für Listen)
export interface ProductCardData {
  id: string
  slug: string
  name: string
  price: string | null
  regularPrice: string | null
  onSale: boolean
  stockStatus?: 'IN_STOCK' | 'OUT_OF_STOCK'
  image: ProductImage | null
  productCategories: {
    nodes: ProductCategory[]
  }
}

// Produkt-Detail (für Detailseite)
export interface ProductDetailData {
  id: string
  databaseId: number
  slug: string
  name: string
  description: string
  shortDescription: string | null
  price: string | null
  regularPrice: string | null
  onSale: boolean
  variations?: {
    nodes: ProductVariation[]
  }
  image: ProductImage | null
  galleryImages: {
    nodes: ProductImage[]
  }
  productCategories: {
    nodes: ProductCategory[]
  }
}

// Extrahierte Varianten-Optionen (aus ProductVariation[])
export interface VariantOptions {
  sizes: Array<{
    value: string
    available: boolean
  }>
  colors: Array<{
    value: string
    hexCode: string | null
    available: boolean
  }>
}

// Ausgewählte Variante
export interface SelectedVariant {
  size: string | null
  color: string | null
}
