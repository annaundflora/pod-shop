// frontend/lib/blocks/data-loaders.ts
import { getClient } from '@/lib/apollo/server-client'
import {
  GET_FEATURED_PRODUCTS,
  GET_PRODUCT_CATEGORIES,
  GET_PRODUCT,
  GET_PAGE_CONTENT,
  GET_PRODUCT_REVIEWS,
  GET_RELATED_PRODUCTS,
  GET_BESTSELLER_PRODUCTS,
  GET_PRODUCTS_BY_IDS,
  GET_PRODUCT_CATEGORY,
  GET_PRODUCTS_PAGINATED,
  GET_CATEGORY_META,
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
  ProductReviewsResult,
  ProductRecommendationsData,
  PaginatedProductsResult,
  FeaturedCollectionData,
} from './types'
import type { ProductCardData, ProductCategory, ProductDetailData } from '@/lib/graphql/types'

// GraphQL Query für WP Custom Fields (lokal definiert)
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
  data: unknown
  error?: string
}

interface InlineLoaderResult {
  data: Record<string, unknown>
}

// ─── Sort-Mapping ─────────────────────────────────────────────────────────────
export function buildOrderby(sort: string | undefined): { field: string; order: string }[] | undefined {
  switch (sort) {
    case 'price_asc':  return [{ field: 'PRICE', order: 'ASC' }]
    case 'price_desc': return [{ field: 'PRICE', order: 'DESC' }]
    case 'newest':     return [{ field: 'DATE', order: 'DESC' }]
    default:           return undefined
  }
}

async function wordpressLoader(params: WordPressLoaderParams): Promise<WordPressLoaderResult> {
  try {
    if (params.query === 'page_content') {
      const { data } = await getClient().query<{ pageBy: { title: string; content: string } | null }>({
        query: GET_PAGE_CONTENT,
        variables: { slug: params.page_slug },
      })
      if (!data?.pageBy) return { data: null }
      return {
        data: { title: data.pageBy.title, content: data.pageBy.content } satisfies WPPageContent,
      }
    }
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
        data: { nodes: data?.productCategories?.nodes ?? [], currentSlug: params.slug ?? '' },
      }
    } else if (params.query === 'products_by_category') {
      const slug = params.slug
      const page = Number(params.page ?? 1)
      const perPage = Number(params.perPage ?? 24)
      const sort = params.sort as string | undefined
      const fetchCount = page * perPage + 1
      const orderby = buildOrderby(sort)
      const { data } = await getClient().query({
        query: GET_PRODUCTS_PAGINATED,
        variables: { categorySlug: slug, first: fetchCount, orderby },
      })
      const allNodes: ProductCardData[] = data?.products?.nodes ?? []
      const pageNodes = allNodes.slice((page - 1) * perPage, page * perPage)
      const hasNextPage = allNodes.length > page * perPage
      const categoryCount = data?.productCategory?.count ?? 0
      const totalPages = categoryCount > 0
        ? Math.ceil(categoryCount / perPage)
        : Math.ceil(allNodes.length / perPage) + (hasNextPage ? 1 : 0)
      return {
        data: {
          products: { nodes: pageNodes },
          productCategory: data?.productCategory ?? null,
          pagination: {
            currentPage: page,
            totalPages: Math.max(totalPages, page),
            hasNextPage,
            hasPreviousPage: page > 1,
            totalCount: categoryCount,
          },
        } satisfies PaginatedProductsResult,
      }
    } else if (params.query === 'product_by_slug') {
      const slug = params.slug
      if (!slug) return { data: null }
      const { data } = await getClient().query<{ product: ProductDetailData | null }>({
        query: GET_PRODUCT,
        variables: { slug },
      })
      return { data: data?.product ?? null }
    } else if (params.query === 'category_meta') {
      const slug = params.slug
      if (!slug) return { data: null }
      const { data } = await getClient().query({ query: GET_CATEGORY_META, variables: { slug } })
      const cat = data?.productCategory
      if (!cat) return { data: null }
      return { data: { items: [{ label: 'Startseite', href: '/' }, { label: cat.name }] } }
    } else if (params.query === 'product_reviews') {
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
    } else if (params.query === 'product_recommendations') {
      const source = (params as Record<string, unknown>).source as string ?? 'related'
      const productSlug = params.slug
      const perPage = params.first ?? 4
      const { data: productData } = await getClient().query({
        query: GET_PRODUCT_CATEGORY,
        variables: { slug: productSlug },
      })
      const productId = productData?.product?.databaseId
      let nodes: ProductCardData[] = []
      if (source === 'related' && productId) {
        const { data: relData } = await getClient().query({
          query: GET_RELATED_PRODUCTS,
          variables: { productId: String(productId), first: perPage },
        })
        nodes = relData?.product?.related?.nodes ?? []
        if (nodes.length === 0) {
          const { data: catData } = await getClient().query({
            query: GET_FEATURED_PRODUCTS,
            variables: { first: perPage + 1 },
          })
          nodes = (catData?.products?.nodes ?? []).filter((p: ProductCardData) => p.slug !== productSlug).slice(0, perPage)
        }
      } else if (source === 'bestsellers') {
        const { data: bsData } = await getClient().query({
          query: GET_BESTSELLER_PRODUCTS, variables: { first: perPage },
        })
        nodes = bsData?.products?.nodes ?? []
      } else if (source === 'custom' && (params as Record<string, unknown>).customIds) {
        const ids = String((params as Record<string, unknown>).customIds).split(',').map(Number).filter(Boolean)
        const { data: customData } = await getClient().query({
          query: GET_PRODUCTS_BY_IDS, variables: { include: ids, first: perPage },
        })
        nodes = customData?.products?.nodes ?? []
      }
      if (nodes.length === 0) return { data: null }
      const heading = (params as Record<string, unknown>).heading as string ?? 'Das könnte dir auch gefallen'
      return { data: { heading, products: { nodes } } satisfies ProductRecommendationsData }
    } else if (params.query === 'featured_collection') {
      // ─── Branch: featured_collection ──────────────────────────────────────
      const slug = params.slug
      if (!slug) return { data: null }
      const collectionFirst = Number(params.first ?? 4)

      // Parallel fetch (async-parallel Pattern)
      const [categoryResult, productsResult] = await Promise.all([
        getClient().query({ query: GET_CATEGORY_META, variables: { slug } }),
        getClient().query({
          query: GET_PRODUCTS_PAGINATED,
          variables: { categorySlug: slug, first: collectionFirst, orderby: undefined },
        }),
      ])

      const cat = categoryResult.data?.productCategory
      const products = productsResult.data?.products?.nodes ?? []

      if (!cat || products.length === 0) return { data: null }

      return {
        data: {
          category: {
            name: cat.name,
            description: cat.description ?? '',
            slug: cat.slug,
            image: cat.image ?? null,
          },
          products: { nodes: products.slice(0, collectionFirst) },
        } satisfies FeaturedCollectionData,
      }
    }
    return { data: null, error: `Unknown woocommerce query: ${params.query}` }
  } catch (error) {
    console.error('DataLoader.woocommerce error:', error)
    return { data: null, error: String(error) }
  }
}

function inlineLoader(params: InlineLoaderParams): InlineLoaderResult {
  return { data: params.props }
}

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
