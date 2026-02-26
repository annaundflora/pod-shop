// frontend/lib/blocks/data-loaders.ts
import { getClient } from '@/lib/apollo/server-client'
import {
  GET_FEATURED_PRODUCTS,
  GET_PRODUCT_CATEGORIES,
  GET_CATEGORY_WITH_PRODUCTS,
  GET_PRODUCT,
  GET_PAGE_CONTENT,
  GET_PRODUCT_REVIEWS,
  GET_RELATED_PRODUCTS,
  GET_BESTSELLER_PRODUCTS,
  GET_PRODUCTS_BY_IDS,
  GET_PRODUCT_CATEGORY,
} from '@/lib/graphql/queries'
import { gql } from '@apollo/client'
import type {
  WPCustomFieldsData,
  WPPageContent,
  WordPressLoaderParams,
  WooCommerceLoaderParams,
  InlineLoaderParams,
  LoaderParams,
  ContentSource,
  CategoryWithProducts,
  ProductReviewsResult,
  ProductRecommendationsData,
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
  data: WPCustomFieldsData | WPPageContent | null
  error?: string
}

interface WooCommerceLoaderResult {
  data: { products?: { nodes: ProductCardData[] } } | { productCategories?: { nodes: ProductCategory[] } } | CategoryWithProducts | { nodes: ProductCategory[]; currentSlug: string } | ProductDetailData | ProductReviewsResult | ProductRecommendationsData | null
  error?: string
}

interface InlineLoaderResult {
  data: Record<string, unknown>
}

/**
 * Loads WP Custom Fields or page content for a page via GraphQL.
 * Returns null data on GraphQL error (Error Boundary handles rendering).
 */
async function wordpressLoader(params: WordPressLoaderParams): Promise<WordPressLoaderResult> {
  try {
    if (params.query === 'page_content') {
      const { data } = await getClient().query<{ pageBy: { title: string; content: string } | null }>({
        query: GET_PAGE_CONTENT,
        variables: { slug: params.page_slug },
      })
      if (!data?.pageBy) return { data: null }
      return {
        data: {
          title: data.pageBy.title,
          content: data.pageBy.content,
        } satisfies WPPageContent,
      }
    }

    // Default: custom_fields (bestehendes Verhalten)
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
        variables: { categorySlug: slug, categoryId: slug, first },
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
    // ─── Branch: product_reviews ─────────────────────────────────────────────
    else if (params.query === 'product_reviews') {
      const { data } = await getClient().query({
        query: GET_PRODUCT_REVIEWS,
        variables: { productSlug: params.slug },
      })

      if (!data?.product) return { data: null }

      const product = data.product
      return {
        data: {
          averageRating: product.averageRating ?? 0,
          reviewCount: product.reviewCount ?? 0,
          reviewsAllowed: product.reviewsAllowed ?? false,
          reviews: { edges: product.reviews?.edges ?? [] },
          productId: product.databaseId,
        } satisfies ProductReviewsResult,
      }
    }
    // ─── Branch: product_recommendations ─────────────────────────────────────
    else if (params.query === 'product_recommendations') {
      const source = (params as Record<string, unknown>).source as string ?? 'related'
      const productSlug = params.slug

      // Produktdaten für productId + categorySlug laden
      const { data: productData } = await getClient().query({
        query: GET_PRODUCT_CATEGORY,
        variables: { slug: productSlug },
      })
      const productId = productData?.product?.databaseId
      const categorySlug = productData?.product?.productCategories?.nodes?.[0]?.slug

      let nodes: ProductCardData[] = []

      if (source === 'related' && productId) {
        const { data: relData } = await getClient().query({
          query: GET_RELATED_PRODUCTS,
          variables: { productId: String(productId), first },
        })
        nodes = relData?.product?.related?.nodes ?? []

        // Fallback: category
        if (nodes.length === 0 && categorySlug) {
          const { data: catData } = await getClient().query({
            query: GET_FEATURED_PRODUCTS,
            variables: { first: first + 1 },
          })
          nodes = (catData?.products?.nodes ?? []).filter((p: ProductCardData) => p.slug !== productSlug).slice(0, first)
        }
      } else if (source === 'category' && categorySlug) {
        const { data: catData } = await getClient().query({
          query: GET_FEATURED_PRODUCTS,
          variables: { first: first + 1 },
        })
        nodes = (catData?.products?.nodes ?? []).filter((p: ProductCardData) => p.slug !== productSlug).slice(0, first)
      } else if (source === 'bestsellers') {
        const { data: bsData } = await getClient().query({
          query: GET_BESTSELLER_PRODUCTS,
          variables: { first },
        })
        nodes = bsData?.products?.nodes ?? []
      } else if (source === 'custom' && (params as Record<string, unknown>).customIds) {
        const ids = String((params as Record<string, unknown>).customIds).split(',').map(Number).filter(Boolean)
        const { data: customData } = await getClient().query({
          query: GET_PRODUCTS_BY_IDS,
          variables: { include: ids, first },
        })
        nodes = customData?.products?.nodes ?? []
      }

      if (nodes.length === 0) return { data: null }

      const heading = (params as Record<string, unknown>).heading as string ?? 'Das könnte dir auch gefallen'
      return {
        data: {
          heading,
          products: { nodes },
        } satisfies ProductRecommendationsData,
      }
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
