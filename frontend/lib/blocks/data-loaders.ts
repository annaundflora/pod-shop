// frontend/lib/blocks/data-loaders.ts
import { getClient } from '@/lib/apollo/server-client'
import { GET_FEATURED_PRODUCTS, GET_PRODUCT_CATEGORIES, GET_CATEGORY_WITH_PRODUCTS, GET_PRODUCT } from '@/lib/graphql/queries'
import { gql } from '@apollo/client'
import type {
  WPCustomFieldsData,
  WordPressLoaderParams,
  WooCommerceLoaderParams,
  InlineLoaderParams,
  LoaderParams,
  ContentSource,
  CategoryWithProducts,
} from './types'
import type { ProductCardData, ProductCategory, ProductDetailData } from '@/lib/graphql/types'

// GraphQL Query für WP Custom Fields (lokal definiert — NICHT in lib/graphql/queries.ts)
const GET_PAGE_CUSTOM_FIELDS = gql`
  query GetPageCustomFields($slug: String!) {
    pageBy(uri: $slug) {
      heroHeadline
      heroSubline
      heroCtaText
      heroCtaLink
      heroBackgroundImage
      seoMetaDescription
    }
  }
`

interface WordPressLoaderResult {
  data: WPCustomFieldsData | null
  error?: string
}

interface WooCommerceLoaderResult {
  data: { products?: { nodes: ProductCardData[] } } | { productCategories?: { nodes: ProductCategory[] } } | CategoryWithProducts | { nodes: ProductCategory[]; currentSlug: string } | ProductDetailData | null
  error?: string
}

interface InlineLoaderResult {
  data: Record<string, unknown>
}

/**
 * Loads WP Custom Fields for a page via GraphQL.
 * Returns null data on GraphQL error (Error Boundary handles rendering).
 */
async function wordpressLoader(params: WordPressLoaderParams): Promise<WordPressLoaderResult> {
  try {
    const { data } = await getClient().query<{ pageBy: WPCustomFieldsData | null }>({
      query: GET_PAGE_CUSTOM_FIELDS,
      variables: { slug: params.page_slug },
    })
    return { data: data?.pageBy ?? null }
  } catch (error) {
    console.error('DataLoader.wordpress error:', error)
    return { data: null, error: String(error) }
  }
}

/**
 * Loads WooCommerce product or category data via GraphQL.
 * Reuses existing GET_FEATURED_PRODUCTS and GET_PRODUCT_CATEGORIES queries.
 */
async function woocommerceLoader(params: WooCommerceLoaderParams): Promise<WooCommerceLoaderResult> {
  try {
    const first = params.first ?? 4
    if (params.query === 'featured_products') {
      const { data } = await getClient().query<{ products: { nodes: ProductCardData[] } }>({
        query: GET_FEATURED_PRODUCTS,
        variables: { first },
      })
      return { data: data ?? null }
    } else if (params.query === 'product_categories') {
      const { data } = await getClient().query<{ productCategories: { nodes: ProductCategory[] } }>({
        query: GET_PRODUCT_CATEGORIES,
        variables: { first },
      })
      return {
        data: {
          nodes: data?.productCategories?.nodes ?? [],
          currentSlug: params.slug ?? '',
        }
      }
    } else if (params.query === 'products_by_category') {
      const slug = params.slug
      if (!slug) {
        console.warn('products_by_category: missing slug param')
        return { data: null }
      }
      const { data } = await getClient().query<CategoryWithProducts>({
        query: GET_CATEGORY_WITH_PRODUCTS,
        variables: { categorySlug: slug, first },
      })
      return { data: data ?? null }
    } else if (params.query === 'product_by_slug') {
      const slug = params.slug
      if (!slug) {
        console.warn('product_by_slug: missing slug param')
        return { data: null }
      }
      const { data } = await getClient().query<{ product: ProductDetailData | null }>({
        query: GET_PRODUCT,
        variables: { slug },
      })
      return { data: data?.product ?? null }
    }
    return { data: null, error: `Unknown woocommerce query: ${params.query}` }
  } catch (error) {
    console.error('DataLoader.woocommerce error:', error)
    return { data: null, error: String(error) }
  }
}

/**
 * Returns inline props directly from YAML config — no network request.
 */
function inlineLoader(params: InlineLoaderParams): InlineLoaderResult {
  return { data: params.props }
}

/**
 * Dispatches to the correct loader based on content_source.
 */
export async function loadBlockData(
  contentSource: ContentSource,
  params: LoaderParams
): Promise<{ data: unknown; error?: string }> {
  switch (contentSource) {
    case 'wordpress':
      return wordpressLoader(params as WordPressLoaderParams)
    case 'woocommerce':
      return woocommerceLoader(params as WooCommerceLoaderParams)
    case 'inline':
      return inlineLoader(params as InlineLoaderParams)
    default:
      console.warn(`Invalid content source: ${contentSource}`)
      return { data: null, error: `Invalid content source: ${contentSource}` }
  }
}

export { wordpressLoader, woocommerceLoader, inlineLoader }
