import { ApolloClient, InMemoryCache, HttpLink } from '@apollo/client'
import { cache } from 'react'
import { possibleTypes } from './possible-types'

// Server-seitiger Apollo Client für React Server Components (kein localStorage/Token nötig)
// React.cache() stellt sicher, dass pro Request nur ein Client erstellt wird (per-request Deduplication)
const makeClient = () => {
  return new ApolloClient({
    cache: new InMemoryCache({ possibleTypes }),
    link: new HttpLink({
      uri: process.env.NEXT_PUBLIC_GRAPHQL_URL ?? 'http://localhost:8080/graphql',
    }),
  })
}

export const getClient = cache(makeClient)
