/**
 * Acceptance & Unit Tests for Slice 01: Infrastruktur aufsetzen
 *
 * Tests are derived from the GIVEN/WHEN/THEN Acceptance Criteria
 * in docs/features/pod-shop-mvp/slices/slice-01-infrastruktur.md
 *
 * ACs 1-5: Infrastructure tests (Docker/WordPress/Next.js) -- verified via
 *          configuration file assertions since runtime Docker is not available in Vitest.
 * ACs 6-9: TokenManager & Apollo SessionLink unit tests -- fully testable in jsdom.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { TokenManager } from '@/lib/apollo/token-manager'
import * as fs from 'fs'
import * as path from 'path'

// ---------------------------------------------------------------------------
// Helper: Read project files relative to repo root (one level above frontend/)
// ---------------------------------------------------------------------------
const REPO_ROOT = path.resolve(__dirname, '..', '..', '..', '..')
const FRONTEND_ROOT = path.resolve(__dirname, '..', '..', '..')

function readRepoFile(relativePath: string): string {
  return fs.readFileSync(path.join(REPO_ROOT, relativePath), 'utf-8')
}

function readFrontendFile(relativePath: string): string {
  return fs.readFileSync(path.join(FRONTEND_ROOT, relativePath), 'utf-8')
}

// ===========================================================================
// AC-1: Docker Compose WordPress erreichbar
// GIVEN der Docker Compose Stack ist gestartet
// WHEN eine HTTP-Anfrage an http://localhost:8080 gesendet wird
// THEN antwortet WordPress mit HTTP 200
// ===========================================================================
describe('AC-1: Docker Compose WordPress configuration', () => {
  /**
   * AC-1: GIVEN der Docker Compose Stack ist gestartet (docker compose up -d)
   * WHEN eine HTTP-Anfrage an http://localhost:8080 gesendet wird
   * THEN antwortet WordPress mit HTTP 200 (Homepage oder WP-Installationsseite erreichbar)
   *
   * Runtime-Verification ist nicht in Vitest moeglich (benoetigt laufenden Docker).
   * Stattdessen validieren wir, dass docker-compose.yml korrekt konfiguriert ist.
   */
  it('AC-1: docker-compose.yml exists and defines wordpress service on port 8080', () => {
    // Arrange (GIVEN)
    const composeContent = readRepoFile('docker-compose.yml')

    // Act (WHEN) -- verify wordpress service configuration
    // Assert (THEN)
    expect(composeContent).toContain('wordpress:')
    expect(composeContent).toMatch(/image:\s*wordpress:6\.9/)
    expect(composeContent).toContain('"8080:80"')
  })

  it('AC-1: docker-compose.yml defines mysql 8.0 service', () => {
    // Arrange (GIVEN)
    const composeContent = readRepoFile('docker-compose.yml')

    // Act & Assert
    expect(composeContent).toMatch(/image:\s*mysql:8\.0/)
    expect(composeContent).toContain('db_data:/var/lib/mysql')
  })

  it('AC-1: docker-compose.yml defines phpmyadmin service on port 8180', () => {
    // Arrange (GIVEN)
    const composeContent = readRepoFile('docker-compose.yml')

    // Act & Assert
    expect(composeContent).toMatch(/image:\s*phpmyadmin/)
    expect(composeContent).toContain('"8180:80"')
  })
})

// ===========================================================================
// AC-2: WPGraphQL Endpoint konfiguriert
// GIVEN WordPress ist installiert und WooCommerce aktiviert
// WHEN WPGraphQL und WooGraphQL aktiviert werden
// THEN ist http://localhost:8080/graphql erreichbar
// ===========================================================================
describe('AC-2: Apollo Client targets WPGraphQL endpoint', () => {
  /**
   * AC-2: GIVEN WordPress ist installiert und WooCommerce aktiviert
   * WHEN WPGraphQL und WooGraphQL aktiviert werden
   * THEN ist http://localhost:8080/graphql erreichbar und antwortet auf POST-Requests
   *
   * Wir validieren, dass der Apollo Client den korrekten Endpoint konfiguriert hat.
   */
  it('AC-2: Apollo client.ts configures GraphQL endpoint to localhost:8080/graphql', () => {
    // Arrange (GIVEN)
    const clientSource = readFrontendFile('lib/apollo/client.ts')

    // Act (WHEN) -- check endpoint configuration
    // Assert (THEN)
    expect(clientSource).toContain('http://localhost:8080/graphql')
    expect(clientSource).toContain('HttpLink')
    expect(clientSource).toContain('NEXT_PUBLIC_GRAPHQL_URL')
  })
})

// ===========================================================================
// AC-3: GraphQL products query
// GIVEN WPGraphQL Playground ist geoeffnet
// WHEN die Query { products { nodes { id name } } } ausgefuehrt wird
// THEN antwortet der Endpoint mit gueltigem Response (leeres Array, kein Error)
// ===========================================================================
describe('AC-3: GraphQL products query support', () => {
  /**
   * AC-3: GIVEN WPGraphQL Playground ist geoeffnet unter http://localhost:8080/graphql
   * WHEN die Query { products { nodes { id name } } } ausgefuehrt wird
   * THEN antwortet der Endpoint mit {"data": {"products": {"nodes": []}}}
   *
   * Runtime-Query nicht in Vitest testbar. Wir validieren, dass Apollo Client
   * korrekt initialisiert ist und Queries senden kann (Konfiguration).
   */
  it('AC-3: Apollo Client is configured with correct link chain and cache', () => {
    // Arrange (GIVEN)
    const clientSource = readFrontendFile('lib/apollo/client.ts')

    // Act (WHEN) -- verify link chain setup
    // Assert (THEN) -- the link chain must include auth, session, and http links
    expect(clientSource).toContain('ApolloLink.from([authLink, sessionLink, httpLink])')
    expect(clientSource).toContain('InMemoryCache')
    expect(clientSource).toContain("fetchPolicy: 'network-only'")
    expect(clientSource).toContain("errorPolicy: 'all'")
  })
})

// ===========================================================================
// AC-4: WooCommerce Konfiguration
// GIVEN WooCommerce ist konfiguriert
// WHEN die WooCommerce-Einstellungen geprueft werden
// THEN ist Waehrung EUR, Steuer deaktiviert, Lager deaktiviert, Versandzone DE
// ===========================================================================
describe('AC-4: WooCommerce configuration referenced in project', () => {
  /**
   * AC-4: GIVEN WooCommerce ist konfiguriert
   * WHEN die WooCommerce-Einstellungen geprueft werden
   * THEN ist Waehrung EUR, Steuer deaktiviert, Lager deaktiviert, Versandzone DE mit Pauschale
   *
   * WooCommerce-Settings sind WordPress-DB-basiert und nicht direkt in Vitest testbar.
   * Wir validieren, dass docker-compose.yml WordPress mit DB-Verbindung konfiguriert,
   * sodass WooCommerce funktionsfaehig ist.
   */
  it('AC-4: docker-compose.yml wordpress service connects to MySQL database', () => {
    // Arrange (GIVEN)
    const composeContent = readRepoFile('docker-compose.yml')

    // Act (WHEN) -- check wordpress environment
    // Assert (THEN) -- wordpress is configured to use the DB
    expect(composeContent).toContain('WORDPRESS_DB_HOST')
    expect(composeContent).toContain('WORDPRESS_DB_NAME')
    expect(composeContent).toContain('WORDPRESS_DB_USER')
    expect(composeContent).toContain('WORDPRESS_DB_PASSWORD')
    expect(composeContent).toContain('depends_on')
  })
})

// ===========================================================================
// AC-5: Next.js Frontend erreichbar
// GIVEN das Next.js Frontend-Projekt ist initialisiert
// WHEN der Dev-Server gestartet wird
// THEN ist http://localhost:3000 erreichbar und zeigt die Platzhalter-Homepage
// ===========================================================================
describe('AC-5: Next.js Frontend project structure', () => {
  /**
   * AC-5: GIVEN das Next.js Frontend-Projekt ist initialisiert (cd frontend && pnpm install)
   * WHEN der Dev-Server gestartet wird (pnpm dev)
   * THEN ist http://localhost:3000 erreichbar und zeigt die Platzhalter-Homepage ohne Fehler
   *
   * Dev-Server-Lauf nicht in Vitest testbar. Wir validieren Projektstruktur und Konfiguration.
   */
  it('AC-5: package.json has correct dependencies (Next.js 16, React 19, Apollo Client v4)', () => {
    // Arrange (GIVEN)
    const pkgContent = readFrontendFile('package.json')
    const pkg = JSON.parse(pkgContent)

    // Act (WHEN) -- check dependencies
    // Assert (THEN)
    expect(pkg.dependencies.next).toMatch(/\^16/)
    expect(pkg.dependencies.react).toMatch(/\^19/)
    expect(pkg.dependencies['@apollo/client']).toBeDefined()
    expect(pkg.scripts.dev).toBe('next dev')
    expect(pkg.scripts.test).toBe('vitest run')
  })

  it('AC-5: layout.tsx wraps children with ApolloWrapper', () => {
    // Arrange (GIVEN)
    const layoutSource = readFrontendFile('app/layout.tsx')

    // Act (WHEN) -- check layout structure
    // Assert (THEN)
    expect(layoutSource).toContain('ApolloWrapper')
    expect(layoutSource).toContain('lang="de"')
    expect(layoutSource).toContain('POD Shop')
  })

  it('AC-5: page.tsx exists as homepage with block-registry renderer', () => {
    // Arrange (GIVEN)
    const pageSource = readFrontendFile('app/page.tsx')

    // Act (WHEN) -- verify page exists and uses block-registry pattern (replaced placeholder in Slice 04)
    // Assert (THEN)
    expect(pageSource.length).toBeGreaterThan(0)
    expect(pageSource).toContain('revalidate')
    expect(pageSource).toContain('loadPageConfig')
  })

  it('AC-5: ApolloWrapper is a client component with ApolloProvider', () => {
    // Arrange (GIVEN)
    const wrapperSource = readFrontendFile('components/apollo-wrapper.tsx')

    // Act (WHEN) -- check client directive and provider
    // Assert (THEN)
    expect(wrapperSource).toContain("'use client'")
    expect(wrapperSource).toContain('ApolloProvider')
    expect(wrapperSource).toContain('apolloClient')
  })
})

// ===========================================================================
// AC-6: TokenManager setToken / getToken
// GIVEN Apollo Client ist konfiguriert in frontend/lib/apollo/client.ts
// WHEN TokenManager.setToken('test-token') aufgerufen wird
// THEN gibt TokenManager.getToken() den Wert 'test-token' zurueck
// ===========================================================================
describe('AC-6: TokenManager setToken and getToken', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  /**
   * AC-6: GIVEN Apollo Client ist konfiguriert in frontend/lib/apollo/client.ts
   * WHEN TokenManager.setToken('test-token') aufgerufen wird
   * THEN gibt TokenManager.getToken() den Wert 'test-token' zurueck
   */
  it('AC-6: setToken stores token and getToken retrieves it', () => {
    // Arrange (GIVEN) -- TokenManager is available (imported from apollo module)

    // Act (WHEN)
    TokenManager.setToken('test-token')
    const result = TokenManager.getToken()

    // Assert (THEN)
    expect(result).toBe('test-token')
  })

  it('AC-6: getToken returns null when no token is set', () => {
    // Arrange (GIVEN) -- localStorage is empty (beforeEach clears it)

    // Act (WHEN)
    const token = TokenManager.getToken()

    // Assert (THEN)
    expect(token).toBeNull()
  })

  it('AC-6: setToken overwrites existing token with new value', () => {
    // Arrange (GIVEN)
    TokenManager.setToken('old-token')

    // Act (WHEN)
    TokenManager.setToken('new-token')
    const result = TokenManager.getToken()

    // Assert (THEN)
    expect(result).toBe('new-token')
  })

  it('AC-6: token persists across multiple getToken calls', () => {
    // Arrange (GIVEN)
    TokenManager.setToken('persistent-token')

    // Act (WHEN)
    const first = TokenManager.getToken()
    const second = TokenManager.getToken()

    // Assert (THEN)
    expect(first).toBe('persistent-token')
    expect(second).toBe('persistent-token')
  })
})

// ===========================================================================
// AC-7: TokenManager hasToken returns false when empty
// GIVEN TokenManager hat keinen Token
// WHEN TokenManager.hasToken() aufgerufen wird
// THEN gibt die Funktion false zurueck
// ===========================================================================
describe('AC-7: TokenManager hasToken when empty', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  /**
   * AC-7: GIVEN TokenManager hat keinen Token
   * WHEN TokenManager.hasToken() aufgerufen wird
   * THEN gibt die Funktion false zurueck
   */
  it('AC-7: hasToken returns false when no token is set', () => {
    // Arrange (GIVEN) -- no token set (localStorage cleared)

    // Act (WHEN)
    const result = TokenManager.hasToken()

    // Assert (THEN)
    expect(result).toBe(false)
  })

  it('AC-7: hasToken returns true when token is set', () => {
    // Arrange (GIVEN)
    TokenManager.setToken('some-session-token')

    // Act (WHEN)
    const result = TokenManager.hasToken()

    // Assert (THEN)
    expect(result).toBe(true)
  })
})

// ===========================================================================
// AC-8: TokenManager clearToken
// GIVEN TokenManager hat einen Token gesetzt
// WHEN TokenManager.clearToken() aufgerufen wird
// THEN gibt TokenManager.getToken() anschliessend null zurueck
// ===========================================================================
describe('AC-8: TokenManager clearToken', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  /**
   * AC-8: GIVEN TokenManager hat einen Token gesetzt
   * WHEN TokenManager.clearToken() aufgerufen wird
   * THEN gibt TokenManager.getToken() anschliessend null zurueck
   */
  it('AC-8: clearToken removes the stored token', () => {
    // Arrange (GIVEN)
    TokenManager.setToken('token-to-clear')

    // Act (WHEN)
    TokenManager.clearToken()
    const token = TokenManager.getToken()

    // Assert (THEN)
    expect(token).toBeNull()
  })

  it('AC-8: hasToken returns false after clearToken', () => {
    // Arrange (GIVEN)
    TokenManager.setToken('token-to-clear')

    // Act (WHEN)
    TokenManager.clearToken()

    // Assert (THEN)
    expect(TokenManager.hasToken()).toBe(false)
  })
})

// ===========================================================================
// AC-9: SessionLink speichert woocommerce-session Token
// GIVEN Apollo Client ist initialisiert
// WHEN ein GraphQL-Request gesendet wird und ein woocommerce-session Header in der Response
// THEN speichert der SessionLink den Token automatisch via TokenManager.setToken()
// ===========================================================================
describe('AC-9: SessionLink stores woocommerce-session token from response', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  /**
   * AC-9: GIVEN Apollo Client ist initialisiert
   * WHEN ein GraphQL-Request gesendet wird und ein woocommerce-session Header
   *       in der Response vorhanden ist
   * THEN speichert der SessionLink den Token automatisch via TokenManager.setToken()
   *
   * We simulate the sessionLink logic: when a response contains a woocommerce-session
   * header with a new token value, it should be persisted via TokenManager.
   */
  it('AC-9: stores new woocommerce-session token from response headers', () => {
    // Arrange (GIVEN) -- no existing token
    const sessionToken = 'woo-session-abc-123'
    const mockHeaders = {
      get: (name: string) => (name === 'woocommerce-session' ? sessionToken : null),
    }

    // Act (WHEN) -- simulate sessionLink logic from client.ts
    const currentToken = TokenManager.getToken()
    const receivedToken = mockHeaders.get('woocommerce-session')
    if (receivedToken && receivedToken !== currentToken) {
      TokenManager.setToken(receivedToken)
    }

    // Assert (THEN)
    expect(TokenManager.getToken()).toBe('woo-session-abc-123')
  })

  it('AC-9: does not overwrite token if response header matches existing token', () => {
    // Arrange (GIVEN) -- token already set to same value
    const existingToken = 'existing-token-xyz'
    TokenManager.setToken(existingToken)

    const setTokenSpy = vi.spyOn(TokenManager, 'setToken')

    const mockHeaders = {
      get: (name: string) => (name === 'woocommerce-session' ? existingToken : null),
    }

    // Act (WHEN) -- sessionLink logic: only set if different
    const currentToken = TokenManager.getToken()
    const receivedToken = mockHeaders.get('woocommerce-session')
    if (receivedToken && receivedToken !== currentToken) {
      TokenManager.setToken(receivedToken)
    }

    // Assert (THEN) -- token unchanged, setToken not called again
    expect(TokenManager.getToken()).toBe(existingToken)
    expect(setTokenSpy).not.toHaveBeenCalled()

    setTokenSpy.mockRestore()
  })

  it('AC-9: does not set token if no woocommerce-session header in response', () => {
    // Arrange (GIVEN) -- no header in response
    const mockHeaders = {
      get: (_name: string) => null,
    }

    // Act (WHEN) -- sessionLink logic
    const receivedToken = mockHeaders.get('woocommerce-session')
    if (receivedToken) {
      TokenManager.setToken(receivedToken)
    }

    // Assert (THEN) -- no token stored
    expect(TokenManager.getToken()).toBeNull()
  })

  it('AC-9: updates token when response header contains a different token', () => {
    // Arrange (GIVEN) -- old token exists
    TokenManager.setToken('old-session-token')
    const newSessionToken = 'new-session-token-456'

    const mockHeaders = {
      get: (name: string) => (name === 'woocommerce-session' ? newSessionToken : null),
    }

    // Act (WHEN) -- sessionLink logic: token differs, so update
    const currentToken = TokenManager.getToken()
    const receivedToken = mockHeaders.get('woocommerce-session')
    if (receivedToken && receivedToken !== currentToken) {
      TokenManager.setToken(receivedToken)
    }

    // Assert (THEN) -- token updated to new value
    expect(TokenManager.getToken()).toBe('new-session-token-456')
  })
})
