import { gql } from '@apollo/client'
import { PRODUCT_CARD_FRAGMENT, PRODUCT_DETAIL_FRAGMENT, CATEGORY_FRAGMENT } from './fragments'

// Query: Kategorie + Produkte in einem Request (fuer Block-System: products_by_category)
export const GET_CATEGORY_WITH_PRODUCTS = gql`
  ${PRODUCT_CARD_FRAGMENT}
  query GetCategoryWithProducts($categorySlug: String!, $categoryId: ID!, $first: Int) {
    products(first: $first, where: { categoryIn: [$categorySlug] }) {
      nodes {
        ...ProductCardFields
      }
    }
    productCategory(id: $categoryId, idType: SLUG) {
      name
      description
      slug
    }
  }
`

// Query: Alle Produkte für Kategorieseite
export const GET_PRODUCTS = gql`
  ${PRODUCT_CARD_FRAGMENT}
  query GetProducts($categorySlug: String, $first: Int = 24) {
    products(
      first: $first
      where: {
        categoryIn: [$categorySlug]
        orderby: { field: DATE, order: DESC }
        status: "publish"
      }
    ) {
      nodes {
        ...ProductCardFields
      }
    }
  }
`

// Query: Featured Products für Homepage (tag: "featured" oder custom Selektion)
export const GET_FEATURED_PRODUCTS = gql`
  ${PRODUCT_CARD_FRAGMENT}
  query GetFeaturedProducts($first: Int = 4) {
    products(
      first: $first
      where: {
        featured: true
        status: "publish"
        orderby: { field: DATE, order: DESC }
      }
    ) {
      nodes {
        ...ProductCardFields
      }
    }
  }
`

// Query: Einzelnes Produkt für Produktdetailseite
export const GET_PRODUCT = gql`
  ${PRODUCT_DETAIL_FRAGMENT}
  query GetProduct($slug: ID!) {
    product(id: $slug, idType: SLUG) {
      ...ProductDetailFields
    }
  }
`

// Query: Alle Produktkategorien für Navigation + Homepage
export const GET_PRODUCT_CATEGORIES = gql`
  ${CATEGORY_FRAGMENT}
  query GetProductCategories($first: Int = 10) {
    productCategories(first: $first, where: { parent: 0, hideEmpty: true }) {
      nodes {
        ...CategoryFields
      }
    }
  }
`

// Query: Alle Produkt-Slugs für generateStaticParams
export const GET_ALL_PRODUCT_SLUGS = gql`
  query GetAllProductSlugs {
    products(first: 100, where: { status: "publish" }) {
      nodes {
        slug
      }
    }
  }
`

// Query: Alle Kategorie-Slugs für generateStaticParams
export const GET_ALL_CATEGORY_SLUGS = gql`
  query GetAllCategorySlugs {
    productCategories(first: 50, where: { hideEmpty: true }) {
      nodes {
        slug
      }
    }
  }
`

// Query: WordPress-Seiteninhalt für Rechtsseiten (Impressum, AGB, Datenschutz, Widerruf)
export const GET_PAGE_CONTENT = gql`
  query GetPageContent($slug: String!) {
    pageBy(uri: $slug) {
      title
      content
    }
  }
`

// ============================================================
// Slice 02 — Produkt-Page Enhancements
// ============================================================

// Query: Produkt-Bewertungen für ProductReviewsBlock
export const GET_PRODUCT_REVIEWS = gql`
  query GetProductReviews($productSlug: ID!) {
    product(id: $productSlug, idType: SLUG) {
      databaseId
      averageRating
      reviewCount
      reviewsAllowed
      reviews {
        edges {
          rating
          node {
            id
            content
            date
            author {
              node {
                name
              }
            }
          }
        }
      }
    }
  }
`

// Query: Verwandte Produkte für Recommendations
export const GET_RELATED_PRODUCTS = gql`
  ${PRODUCT_CARD_FRAGMENT}
  query GetRelatedProducts($productId: ID!, $first: Int) {
    product(id: $productId, idType: DATABASE_ID) {
      related(first: $first) {
        nodes {
          ...ProductCardFields
        }
      }
    }
  }
`

// Query: Bestseller-Produkte
export const GET_BESTSELLER_PRODUCTS = gql`
  ${PRODUCT_CARD_FRAGMENT}
  query GetBestsellerProducts($first: Int) {
    products(
      first: $first
      where: { orderby: [{ field: TOTAL_SALES, order: DESC }] }
    ) {
      nodes {
        ...ProductCardFields
      }
    }
  }
`

// Query: Produkte nach IDs
export const GET_PRODUCTS_BY_IDS = gql`
  ${PRODUCT_CARD_FRAGMENT}
  query GetProductsByIds($include: [Int!]!, $first: Int) {
    products(
      first: $first
      where: { include: $include }
    ) {
      nodes {
        ...ProductCardFields
      }
    }
  }
`

// Query: Produkt-Kategorie (schlanke Query nur für Recommendations-Loader)
export const GET_PRODUCT_CATEGORY = gql`
  query GetProductCategory($slug: ID!) {
    product(id: $slug, idType: SLUG) {
      databaseId
      productCategories {
        nodes {
          slug
        }
      }
    }
  }
`

// ============================================================
// Slice 03 — Kategorie-Page Enhancements
// ============================================================

// Query: Produkte mit Pagination fuer Kategorie-Seiten und Suchseite
export const GET_PRODUCTS_PAGINATED = gql`
  ${PRODUCT_CARD_FRAGMENT}
  query GetProductsPaginated(
    $categorySlug: String
    $first: Int!
    $orderby: [ProductsOrderbyInput]
    $search: String
  ) {
    products(
      first: $first
      where: {
        categoryIn: [$categorySlug]
        orderby: $orderby
        search: $search
        status: "publish"
      }
    ) {
      nodes {
        ...ProductCardFields
      }
      pageInfo {
        hasNextPage
        endCursor
      }
    }
    productCategory(id: $categorySlug, idType: SLUG) {
      name
      count
      description
      slug
    }
  }
`

// Query: Kategorie-Metadaten (fuer Breadcrumb und CollectionHeader)
export const GET_CATEGORY_META = gql`
  query GetCategoryMeta($slug: ID!) {
    productCategory(id: $slug, idType: SLUG) {
      name
      description
      slug
      count
      image {
        sourceUrl
        altText
      }
    }
  }
`
