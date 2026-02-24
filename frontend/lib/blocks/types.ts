// frontend/lib/blocks/types.ts

export type ContentSource = 'wordpress' | 'woocommerce' | 'inline'

export interface WordPressLoaderParams {
  page_slug: string
}

export interface WooCommerceLoaderParams {
  query: 'featured_products' | 'product_categories'
  first?: number
}

export interface InlineLoaderParams {
  props: Record<string, unknown>
}

export type LoaderParams = WordPressLoaderParams | WooCommerceLoaderParams | InlineLoaderParams

export interface BlockConfig {
  type: string
  content_source: ContentSource
  params: LoaderParams
}

export interface PageConfig {
  blocks: BlockConfig[]
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

// Props für Block-Components
export interface BlockComponentProps<T = unknown> {
  data: T
}
