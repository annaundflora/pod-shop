// frontend/lib/blocks/types.ts

export type ContentSource = 'wordpress' | 'woocommerce' | 'inline'

export interface WordPressLoaderParams {
  page_slug: string
  query?: 'custom_fields' | 'page_content'  // Default: 'custom_fields'
}

export interface WooCommerceLoaderParams {
  query: 'featured_products' | 'product_categories' | 'products_by_category' | 'product_by_slug' | 'products_paginated' | 'product_reviews' | 'product_recommendations' | 'featured_collection' | 'search_products' | 'category_meta'
  first?: number
  slug?: string
  page?: number
  perPage?: number
  sort?: string
  search?: string
  source?: string
  productSlug?: string
  customIds?: string[]
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

// ============================================================
// Slice 01 — Cross-Page Infrastruktur: Neue Data-Types
// ============================================================

export interface AnnouncementBarData {
  id: string
  text: string
  link?: string
  dismissible?: boolean
  bgColor?: string
}

export interface BreadcrumbData {
  items: { label: string; href?: string }[]
}

export interface TrustBadgeData {
  items: { icon: string; text: string }[]
}

export interface PaginationData {
  currentPage: number
  totalPages: number
  baseUrl: string
  currentSort?: string
  currentQuery?: string
}

export type SortOption = 'default' | 'price_asc' | 'price_desc' | 'newest'

export interface SortBarData {
  currentSort: SortOption
  baseUrl: string
  currentQuery?: string
}

export interface EmptyStateData {
  headline: string
  text: string
  links?: { label: string; href: string }[]
}

// ============================================================
// Slice 02 — Produkt-Page: Types (placeholder for slice-02)
// ============================================================

export interface PaginationMeta {
  currentPage: number
  totalPages: number
  totalItems: number
  perPage: number
}

export interface PaginatedProductsResult {
  products: { nodes: ProductCardData[] }
  pagination: PaginationMeta
}

// ============================================================
// Slice 02 — Produkt-Page: Neue Types
// ============================================================

export interface ReviewEdge {
  rating: number  // WICHTIG: rating ist auf dem Edge, NICHT auf dem Node (WooGraphQL-Quirk)
  node: {
    id: string
    content: string
    date: string
    author: {
      node: {
        name: string
      }
    }
  }
}

export interface ProductReviewsResult {
  averageRating: number
  reviewCount: number
  reviewsAllowed: boolean
  reviews: {
    edges: ReviewEdge[]
  }
  productId: number  // WooCommerce Database ID (für writeReview commentOn: Int!)
}

export interface WriteReviewInput {
  productId: number
  content: string
  author: string
  authorEmail: string
  rating: number  // Integer 1-5
}

export interface ProductRecommendationsData {
  heading: string
  products: { nodes: ProductCardData[] } | null
}
