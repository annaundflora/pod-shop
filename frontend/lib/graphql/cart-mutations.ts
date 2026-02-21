import { gql } from '@apollo/client'

export const CART_FIELDS = gql`
  fragment CartFields on Cart {
    contents {
      nodes {
        key
        quantity
        subtotal
        product {
          node {
            databaseId
            name
            slug
            ... on SimpleProduct {
              price
            }
            ... on VariableProduct {
              price
            }
            image {
              sourceUrl(size: THUMBNAIL)
              altText
            }
          }
        }
        variation {
          node {
            databaseId
          }
          attributes {
            id
            label
            value
          }
        }
      }
      itemCount
    }
    subtotal
    shippingTotal
    total
  }
`

export const ADD_TO_CART = gql`
  ${CART_FIELDS}
  mutation AddToCart($productId: Int!, $variationId: Int, $quantity: Int, $variation: [ProductAttributeInput]) {
    addToCart(
      input: {
        productId: $productId
        variationId: $variationId
        quantity: $quantity
        variation: $variation
      }
    ) {
      cart {
        ...CartFields
      }
      cartItem {
        key
        quantity
      }
    }
  }
`

export const UPDATE_CART_ITEM_QUANTITIES = gql`
  ${CART_FIELDS}
  mutation UpdateCartItemQuantities($items: [CartItemQuantityInput]!) {
    updateCartItemQuantities(input: { items: $items }) {
      cart {
        ...CartFields
      }
    }
  }
`

export const REMOVE_ITEMS_FROM_CART = gql`
  ${CART_FIELDS}
  mutation RemoveItemsFromCart($keys: [ID]!) {
    removeItemsFromCart(input: { keys: $keys }) {
      cart {
        ...CartFields
      }
    }
  }
`

export const GET_CART = gql`
  ${CART_FIELDS}
  query GetCart {
    cart {
      ...CartFields
    }
  }
`
