// frontend/lib/blocks/types.ts

export type ContentSource = 'wordpress' | 'woocommerce' | 'inline'

export interface WordPressLoaderParams {
  page_slug: string
  query?: 'custom_fields' | 'page_content'  // Default: 'custom_fields'
}

export interface WooCommerceLoaderParams {
  query: 'featured_products' | 'product_categories' | 'products_by_category' | 'product_by_slug'
  first?: number
  slug?: string
}

export interface InlineLoaderParams {
  props: Record<string, unknown>
}

export type LoaderParams = WordPressLoaderParams | WooCommerceLoaderParams | InlineLoaderParams

export interface BlockConfig {
  type: string
  content_source: ContentSource
  params: LoaderParams
  span?: number       // Spalten-Spanning (1-4), Default: 1
  row_span?: number   // Zeilen-Spanning, Default: 1
}

export interface SectionConfig {
  columns?: 1 | 2 | 3 | 4     // Default: 1 (fullwidth)
  gap?: string                  // Default: "gap-8" (Tailwind class)
  blocks: BlockConfig[]
}

export interface PageConfig {
  sections: SectionConfig[]
}

// WP Custom Fields Response (von DataLoader.wordpress)
export interface WPCustomFieldsData {
  heroHeadline?: string | null
  heroSubline?: string | null
  heroCtaText?: string | null
  heroCtaLink?: string | null
  heroBackgroundImage?: string | null
  seoMetaDescription?: string | null
}

// WordPress Page Content Response (von DataLoader.wordpress mit query: 'page_content')
export interface WPPageContent {
  title: string
  content: string
}

// Kombiniertes Response-DTO für Kategorie-Seiten
export interface CategoryWithProducts {
  products: { nodes: ProductCardData[] }
  productCategory: { name: string; description: string; slug: string }
}

// ProductCardData (minimal für CategoryWithProducts)
export interface ProductCardData {
  id: string
  slug: string
  name: string
  price: string
  regularPrice?: string | null
  onSale: boolean
  stockStatus: string
  image?: { sourceUrl: string; altText: string } | null
  productCategories?: { nodes: { slug: string; name: string }[] }
}

// Props für Block-Components
export interface BlockComponentProps<T = unknown> {
  data: T
}
