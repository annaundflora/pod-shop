// frontend/lib/blocks/types.ts

export type { ProductCardData } from '@/lib/graphql/types'
import type { ProductCardData } from '@/lib/graphql/types'

export type ContentSource = 'wordpress' | 'woocommerce' | 'inline'

export interface WordPressLoaderParams {
  page_slug: string
  query?: 'custom_fields' | 'page_content'  // Default: 'custom_fields'
}

export interface WooCommerceLoaderParams {
  query:
    | 'featured_products'
    | 'product_categories'
    | 'products_by_category'
    | 'product_by_slug'
    | 'products_paginated'
    | 'product_reviews'
    | 'product_recommendations'
    | 'featured_collection'
    | 'search_products'
    | 'category_meta'
    | 'collection_products'
    | 'collection_header'
  first?: number
  slug?: string
  page?: string        // 1-indexed Seitennummer als String (aus $route.page)
  perPage?: number     // Produkte pro Seite (default: 24)
  sort?: string        // SortOption-String aus URL-Param
  search?: string      // Suchbegriff (fuer Slice 5)
  source?: string      // product_recommendations Quelle (related|category|bestsellers|custom)
  productSlug?: string // Produkt-Slug fuer related/category Fallback
  customIds?: string   // Komma-getrennte WC-Produkt-IDs fuer source=custom
  heading?: string     // Ueberschrift fuer Recommendations-Block
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
// Slice 02 — Produkt-Page: Review Types
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

// ============================================================
// Slice 03 — Kategorie-Page Pagination Types
// ============================================================

export interface PaginationMeta {
  currentPage: number
  totalPages: number
  hasNextPage: boolean
  hasPreviousPage: boolean
  totalCount: number
}

export interface PaginatedProductsResult {
  products: { nodes: ProductCardData[] }
  productCategory?: { name: string; description: string; slug: string; count: number } | null
  pagination: PaginationMeta
}

// ============================================================
// Slice 04 — Homepage Enhancements: Types
// ============================================================

export interface TestimonialsItem {
  name: string
  text: string
  rating: number
  location?: string
}

export interface TestimonialsData {
  items: TestimonialsItem[]
}

export interface NewsletterSignupData {
  headline: string
  text: string
  buttonText: string
  placeholder: string
}

export interface FeaturedCollectionData {
  category: {
    name: string
    description: string
    slug: string
    image?: { sourceUrl: string; altText: string } | null
  }
  products: {
    nodes: ProductCardData[]
  }
}

// ============================================================
// Slice 05 — Suchseite: Types
// ============================================================

export interface SearchBarData {
  placeholder: string
  currentQuery?: string
}

// ============================================================
// Slice 06 — Neue Pages: Types
// ============================================================

export interface CollectionHeaderData {
  name: string
  description: string
  image?: {
    sourceUrl: string
    altText: string
  }
}

export interface OrderConfirmationData {
  headline: string
  text: string
  emailText: string
  ctaText: string
  ctaLink: string
  // orderId wird NICHT hier definiert — wird client-seitig aus window.location.search gelesen
}
