import { gql } from '@apollo/client'

// Fragment: Felder für Produkt-Card (Homepage + Kategorieseite)
export const PRODUCT_CARD_FRAGMENT = gql`
  fragment ProductCardFields on Product {
    id
    slug
    name
    ... on SimpleProduct {
      price(format: FORMATTED)
      regularPrice(format: FORMATTED)
      onSale
      stockStatus
    }
    ... on VariableProduct {
      price(format: FORMATTED)
      regularPrice(format: FORMATTED)
      onSale
    }
    image {
      sourceUrl
      altText
      mediaDetails {
        width
        height
      }
    }
    productCategories {
      nodes {
        id
        slug
        name
      }
    }
  }
`

// Fragment: Felder für Produktdetailseite (inkl. Varianten)
export const PRODUCT_DETAIL_FRAGMENT = gql`
  fragment ProductDetailFields on Product {
    id
    databaseId
    slug
    name
    description
    shortDescription
    ... on SimpleProduct {
      price(format: FORMATTED)
      regularPrice(format: FORMATTED)
      onSale
      stockStatus
    }
    ... on VariableProduct {
      price(format: FORMATTED)
      regularPrice(format: FORMATTED)
      onSale
      variations(first: 50) {
        nodes {
          id
          databaseId
          stockStatus
          attributes {
            nodes {
              name
              value
            }
          }
        }
      }
    }
    image {
      sourceUrl
      altText
      mediaDetails {
        width
        height
      }
    }
    galleryImages {
      nodes {
        sourceUrl
        altText
        mediaDetails {
          width
          height
        }
      }
    }
    productCategories {
      nodes {
        id
        slug
        name
      }
    }
  }
`

// Fragment: Felder für Kategorie
export const CATEGORY_FRAGMENT = gql`
  fragment CategoryFields on ProductCategory {
    id
    slug
    name
    count
    image {
      sourceUrl
      altText
      mediaDetails {
        width
        height
      }
    }
  }
`
