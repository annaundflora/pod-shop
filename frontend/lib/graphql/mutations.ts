// frontend/lib/graphql/mutations.ts
import { gql } from '@apollo/client'

export const WRITE_REVIEW = gql`
  mutation WriteReview(
    $productId: Int!
    $content: String!
    $author: String!
    $authorEmail: String!
    $rating: Int!
  ) {
    writeReview(
      input: {
        commentOn: $productId
        content: $content
        author: $author
        authorEmail: $authorEmail
        rating: $rating
      }
    ) {
      rating
      review {
        id
        content
        date
      }
    }
  }
`
