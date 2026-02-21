import { ApolloClient, InMemoryCache, HttpLink, ApolloLink } from '@apollo/client'
import { Observable } from '@apollo/client/utilities'
import { TokenManager } from './token-manager'
import { possibleTypes } from './possible-types'

const GRAPHQL_ENDPOINT = process.env.NEXT_PUBLIC_GRAPHQL_URL ?? 'http://localhost:8080/graphql'

// Auth Link: Fügt woocommerce-session Header zu jeder Anfrage hinzu
const authLink = new ApolloLink((operation, forward) => {
  const token = TokenManager.getToken()

  operation.setContext(({ headers = {} }: { headers: Record<string, string> }) => ({
    headers: {
      ...headers,
      ...(token ? { 'woocommerce-session': `Session ${token}` } : {}),
    },
  }))

  return forward(operation)
})

// Session Refresh Link: Speichert neuen Token aus Response-Header
const sessionLink = new ApolloLink((operation, forward) => {
  return new Observable<ApolloLink.Result>((observer) => {
    forward(operation).subscribe({
      next: (response) => {
        const context = operation.getContext()
        const {
          response: { headers },
        } = context

        if (headers) {
          const sessionToken = headers.get('woocommerce-session')
          if (sessionToken && sessionToken !== TokenManager.getToken()) {
            TokenManager.setToken(sessionToken)
          }
        }

        observer.next(response)
      },
      error: (err) => observer.error(err),
      complete: () => observer.complete(),
    })
  })
})

const httpLink = new HttpLink({
  uri: GRAPHQL_ENDPOINT,
  credentials: 'include',
  fetchOptions: {
    // Erlaube Zugriff auf Response-Header (für woocommerce-session Token)
  },
})

export const apolloClient = new ApolloClient({
  link: ApolloLink.from([authLink, sessionLink, httpLink]),
  cache: new InMemoryCache({ possibleTypes }),
  defaultOptions: {
    watchQuery: {
      fetchPolicy: 'cache-and-network',
    },
    query: {
      fetchPolicy: 'network-only',
      errorPolicy: 'all',
    },
  },
})
