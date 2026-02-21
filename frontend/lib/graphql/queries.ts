import { gql } from '@apollo/client'
import { PRODUCT_CARD_FRAGMENT, PRODUCT_DETAIL_FRAGMENT, CATEGORY_FRAGMENT } from './fragments'

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
