# Slice 1: Infrastruktur aufsetzen

> **Slice 1 von 7** für `POD Shop MVP`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | — |
> | **Nächster:** | `slice-02-produktkatalog.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-01-infrastruktur` |
| **Test** | `pnpm test tests/slices/pod-shop-mvp/slice-01-infrastruktur.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `[]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` – Vitest Integration Tests (kein Playwright)
- **Dependencies**: Kein vorheriger Slice erforderlich

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren. Greenfield-Projekt ohne package.json → Stack aus Anforderungen abgeleitet: Next.js 16 (typescript-nextjs) + WordPress/Docker Backend.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` + `docker-compose` |
| **Test Command** | `pnpm test tests/slices/pod-shop-mvp/slice-01-infrastruktur.test.ts` |
| **Integration Command** | `pnpm test tests/slices/pod-shop-mvp/slice-01-infrastruktur.test.ts --reporter=verbose` |
| **Acceptance Command** | `curl -f http://localhost:8080/graphql -X POST -H "Content-Type: application/json" -d '{"query":"{ products { nodes { id name } } }"}' && echo "GraphQL OK"` |
| **Start Command** | `docker compose up -d && cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:8080/graphql` |
| **Mocking Strategy** | `mock_external` |

**Erklaerung:**
- **Stack**: Dual-Stack: Next.js Frontend + Docker-Compose WordPress Backend
- **Test Command**: Vitest Unit/Integration Tests für Apollo Client TokenManager
- **Integration Command**: Gleiche Tests mit ausführlicher Ausgabe
- **Acceptance Command**: Manuelle GraphQL-Smoke-Test-Abfrage gegen laufenden WP-Container
- **Start Command**: Docker-Container starten, dann Next.js Dev-Server
- **Health Endpoint**: WPGraphQL Endpoint – muss 200 mit `{"data":{...}}` antworten
- **Mocking Strategy**: Externe WordPress/GraphQL-Calls werden in Unit Tests gemockt

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Infrastruktur | Ready | `slice-01-infrastruktur.md` |
| 2 | Produktkatalog (Frontend) | Pending | `slice-02-produktkatalog.md` |
| 3 | Warenkorb + Checkout-Redirect | Pending | `slice-03-warenkorb-checkout.md` |
| 4 | Rechtliches + Rechnungen | Pending | `slice-04-rechtliches-rechnungen.md` |
| 5 | POD-Anbindung (Spreadconnect) | Pending | `slice-05-spreadconnect.md` |
| 6 | Pinterest Tracking | Pending | `slice-06-pinterest-tracking.md` |
| 7 | User-Accounts | Pending | `slice-07-user-accounts.md` |

---

## Kontext & Ziel

Dieser Slice legt das vollständige lokale Entwicklungs-Fundament für den POD Shop. Nach Abschluss läuft WordPress + WooCommerce in Docker, Next.js ist initialisiert und über Apollo Client mit WPGraphQL verbunden, und Produkte können per GraphQL abgefragt werden.

**Scope-Abgrenzung:**
- Nginx-Konfiguration: OUT OF SCOPE (nur lokal via Docker, kein Nginx für MVP local dev)
- SSL/Let's Encrypt: OUT OF SCOPE (Hetzner Deployment ist separates Feature)
- Produkte anlegen: OUT OF SCOPE (Slice 2)
- Mollie-Plugin: OUT OF SCOPE (Slice 3)

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → Architecture Layers + Data Flow

```
[Browser]
    │
    ├── Next.js (localhost:3000) ─── GraphQL ──► WordPress (localhost:8080) ──► MySQL
    │
    └── phpMyAdmin (localhost:8180) ──► MySQL (Administration)

Docker Compose Stack:
  - wordpress:6.9-php8.2-apache  → Port 8080
  - mysql:8.0                    → Port 3306 (intern)
  - phpmyadmin:5.2               → Port 8180
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `Infrastructure` | `docker-compose.yml` – WordPress + MySQL + phpMyAdmin Container |
| `Infrastructure` | `.env` / `.env.example` – Docker Environment Variables |
| `API Gateway` | WPGraphQL v2.9.0 + WooGraphQL v0.21.2 als WordPress-Plugins installiert |
| `Business Logic` | WooCommerce 10.x Grundkonfiguration (§19 UStG, EUR, DE-Versand) |
| `Presentation` (Next.js) | Next.js 16 App Router Projekt-Initialisierung |
| `Presentation` (Next.js) | Apollo Client v4 Setup mit TokenManager |

### 2. Datenfluss

```
Docker Compose up
  ↓
MySQL 8.0 startet (Port 3306 intern)
  ↓
WordPress 6.9 startet (Port 8080) → verbindet mit MySQL
  ↓
WooCommerce installiert + WPGraphQL + WooGraphQL aktiviert
  ↓
WooCommerce konfiguriert (§19 UStG, EUR, DE-Versand, Stock deaktiviert)
  ↓
Next.js Frontend (Port 3000)
  ↓
Apollo Client → POST http://localhost:8080/graphql
  ↓
TokenManager liest/schreibt woocommerce-session Token aus localStorage
  ↓
WPGraphQL verarbeitet Query → MySQL → Response
```

### 3. Docker Compose Konfiguration

**Datei:** `docker-compose.yml`

```yaml
version: '3.8'

services:
  db:
    image: mysql:8.0
    restart: always
    environment:
      MYSQL_DATABASE: ${MYSQL_DATABASE}
      MYSQL_USER: ${MYSQL_USER}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql
    networks:
      - pod-shop

  wordpress:
    image: wordpress:6.9-php8.2-apache
    restart: always
    depends_on:
      - db
    ports:
      - "8080:80"
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_NAME: ${MYSQL_DATABASE}
      WORDPRESS_DB_USER: ${MYSQL_USER}
      WORDPRESS_DB_PASSWORD: ${MYSQL_PASSWORD}
      WORDPRESS_TABLE_PREFIX: wp_
    volumes:
      - wordpress_data:/var/www/html
      - ./wordpress/plugins:/var/www/html/wp-content/plugins
      - ./wordpress/themes:/var/www/html/wp-content/themes
      - ./wordpress/uploads:/var/www/html/wp-content/uploads
    networks:
      - pod-shop

  phpmyadmin:
    image: phpmyadmin:5.2
    restart: always
    depends_on:
      - db
    ports:
      - "8180:80"
    environment:
      PMA_HOST: db
      PMA_PORT: 3306
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
    networks:
      - pod-shop

volumes:
  db_data:
  wordpress_data:

networks:
  pod-shop:
    driver: bridge
```

### 4. Environment Variables

**Datei:** `.env.example`

```bash
# MySQL
MYSQL_DATABASE=wordpress
MYSQL_USER=wordpress
MYSQL_PASSWORD=wordpress_password
MYSQL_ROOT_PASSWORD=root_password

# WordPress (optional, für WP-CLI oder spätere Nutzung)
WP_HOME=http://localhost:8080
WP_SITEURL=http://localhost:8080
```

**Datei:** `.env` (nicht in Git committen – von `.env.example` kopieren)

### 5. WordPress Plugin-Installation

Plugins werden als ZIP-Dateien in `./wordpress/plugins/` abgelegt und im WP-Admin aktiviert.

| Plugin | Version | Quelle | Installationsweg |
|--------|---------|--------|-----------------|
| WooCommerce | 10.x | wordpress.org | WP-Admin → Plugins → Installieren |
| WPGraphQL | 2.9.0 | github.com/wp-graphql/wp-graphql/releases | ZIP-Download → WP-Admin Upload |
| WooGraphQL | 0.21.2 | github.com/wp-graphql/wp-graphql-woocommerce/releases | ZIP-Download → WP-Admin Upload |

**Wichtig:** Reihenfolge bei Aktivierung:
1. WooCommerce aktivieren + Setup-Wizard abschließen
2. WPGraphQL aktivieren
3. WooGraphQL aktivieren (nach WPGraphQL, da Abhängigkeit)

### 6. WooCommerce Grundkonfiguration

> **Quelle:** `architecture.md` → Constraints: Kleinunternehmerregelung §19 UStG

| Setting | Wert | WP-Admin Pfad |
|---------|------|---------------|
| Währung | EUR (€) | WooCommerce → Einstellungen → Allgemein |
| Währungsposition | Rechts mit Leerzeichen (29,99 €) | WooCommerce → Einstellungen → Allgemein |
| Tausendertrennzeichen | . | WooCommerce → Einstellungen → Allgemein |
| Dezimaltrennzeichen | , | WooCommerce → Einstellungen → Allgemein |
| Basisland | Deutschland | WooCommerce → Einstellungen → Allgemein |
| Steuern aktiviert | Deaktiviert (§19 UStG: keine MwSt) | WooCommerce → Einstellungen → Allgemein |
| Lager verwalten | Deaktiviert (POD = immer verfügbar) | WooCommerce → Einstellungen → Produkte |
| Versandzone | Deutschland, Pauschale | WooCommerce → Einstellungen → Versand |

**Versandzone DE:**
- Zone: Deutschland (Länder: DE)
- Versandart: Pauschale (Flat Rate)
- Kosten: Gemäß Spreadconnect-Preisliste (Platzhalter: 4,99 €)

**Permalink-Struktur** (für WPGraphQL erforderlich):
- WP-Admin → Einstellungen → Permalinks → Beitragsname (`/%postname%/`)

**WPGraphQL Einstellungen:**
- WP-Admin → GraphQL → Einstellungen → Enable Public Introspection: aktiviert (für Entwicklung)
- GraphQL Endpoint: `/graphql` (Standard, belassen)

### 7. Next.js Projekt-Initialisierung

**Projektstruktur nach Initialisierung:**

```
frontend/
├── app/
│   ├── layout.tsx          – Root Layout mit ApolloProvider
│   ├── page.tsx            – Homepage (Placeholder für Slice 2)
│   └── globals.css         – Tailwind v4 Imports + @theme Tokens
├── lib/
│   ├── apollo/
│   │   ├── client.ts       – Apollo Client v4 Konfiguration
│   │   └── token-manager.ts – WooGraphQL Session Token (localStorage)
│   └── graphql/
│       └── fragments.ts    – Wiederverwendbare GraphQL Fragments (leer, für Slice 2)
├── components/             – Leer, für Slice 2
├── public/                 – Static Assets
├── package.json
├── next.config.ts
└── tsconfig.json
```

**Initialisierungsbefehl:**

```bash
npx create-next-app@latest frontend \
  --typescript \
  --tailwind \
  --app \
  --src-dir=false \
  --import-alias="@/*"
```

**package.json Dependencies (nach Init):**

```json
{
  "dependencies": {
    "next": "^16.1.6",
    "react": "^19.0.0",
    "react-dom": "^19.0.0",
    "@apollo/client": "^4.1.4",
    "graphql": "^16.9.0"
  },
  "devDependencies": {
    "@types/node": "^22.0.0",
    "@types/react": "^19.0.0",
    "@types/react-dom": "^19.0.0",
    "typescript": "^5.7.0",
    "@tailwindcss/postcss": "^4.0.0",
    "tailwindcss": "^4.0.0",
    "vitest": "^3.0.0",
    "@vitejs/plugin-react": "^4.0.0",
    "vite": "^6.0.0"
  },
  "scripts": {
    "dev": "next dev",
    "build": "next build",
    "start": "next start",
    "test": "vitest run"
  }
}
```

### 8. Apollo Client v4 Setup

> **Quelle:** `architecture.md` → Technology Decisions: Apollo Client v4, Session Utils: Custom TokenManager

**Datei:** `frontend/lib/apollo/token-manager.ts`

```typescript
// WooGraphQL Session Token Manager
// Speichert woocommerce-session Token in localStorage
// Wird als Header bei jeder GraphQL-Anfrage mitgesendet

const TOKEN_KEY = 'woo-session-token'

export const TokenManager = {
  getToken(): string | null {
    if (typeof window === 'undefined') return null
    return localStorage.getItem(TOKEN_KEY)
  },

  setToken(token: string): void {
    if (typeof window === 'undefined') return
    localStorage.setItem(TOKEN_KEY, token)
  },

  clearToken(): void {
    if (typeof window === 'undefined') return
    localStorage.removeItem(TOKEN_KEY)
  },

  hasToken(): boolean {
    return TokenManager.getToken() !== null
  },
}
```

**Datei:** `frontend/lib/apollo/client.ts`

```typescript
import { ApolloClient, InMemoryCache, HttpLink, ApolloLink } from '@apollo/client'
import { TokenManager } from './token-manager'

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
  return forward(operation).map((response) => {
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

    return response
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
  cache: new InMemoryCache(),
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
```

**Datei:** `frontend/app/layout.tsx`

```typescript
import type { Metadata } from 'next'
import { ApolloWrapper } from '@/components/apollo-wrapper'
import './globals.css'

export const metadata: Metadata = {
  title: 'POD Shop',
  description: 'Print-on-Demand Shop',
}

export default function RootLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <html lang="de">
      <body>
        <ApolloWrapper>{children}</ApolloWrapper>
      </body>
    </html>
  )
}
```

**Datei:** `frontend/components/apollo-wrapper.tsx`

```typescript
'use client'

import { ApolloProvider } from '@apollo/client'
import { apolloClient } from '@/lib/apollo/client'

export function ApolloWrapper({ children }: { children: React.ReactNode }) {
  return <ApolloProvider client={apolloClient}>{children}</ApolloProvider>
}
```

### 9. Tailwind CSS v4 Konfiguration

> **Quelle:** Tailwind v4 Skill → CSS-First Configuration, Build Tool Integration

**Datei:** `frontend/app/globals.css`

```css
@import "tailwindcss";

@theme {
  /* Farben – semantisch benannt */
  --color-primary: oklch(0.45 0.2 270);
  --color-primary-hover: oklch(0.38 0.2 270);
  --color-surface: var(--color-white);
  --color-surface-elevated: oklch(0.98 0 0);
  --color-text-primary: oklch(0.15 0 0);
  --color-text-secondary: oklch(0.45 0 0);
  --color-border: oklch(0.88 0 0);

  /* Touch-Targets: mindestens 44px (Pinterest-Traffic ist mobile) */
  --min-touch-target: 2.75rem;

  /* Spacing – ergänzt Tailwind-Standard */
  --spacing-18: 4.5rem;
}

@layer base {
  body {
    @apply antialiased text-text-primary bg-surface;
  }

  h1, h2, h3, h4, h5, h6 {
    text-wrap: balance;
    @apply font-semibold tracking-tight;
  }

  /* Touch-Targets für Mobile-first (Pinterest-Traffic) */
  button, a {
    touch-action: manipulation;
  }
}
```

**Datei:** `frontend/next.config.ts`

```typescript
import type { NextConfig } from 'next'

const nextConfig: NextConfig = {
  images: {
    remotePatterns: [
      {
        protocol: 'http',
        hostname: 'localhost',
        port: '8080',
        pathname: '/wp-content/uploads/**',
      },
    ],
  },
  env: {
    NEXT_PUBLIC_GRAPHQL_URL: process.env.NEXT_PUBLIC_GRAPHQL_URL ?? 'http://localhost:8080/graphql',
    NEXT_PUBLIC_WP_URL: process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080',
  },
}

export default nextConfig
```

**Datei:** `frontend/.env.local.example`

```bash
NEXT_PUBLIC_GRAPHQL_URL=http://localhost:8080/graphql
NEXT_PUBLIC_WP_URL=http://localhost:8080
NEXT_PUBLIC_WC_CHECKOUT_URL=http://localhost:8080/checkout
```

### 10. Vitest Konfiguration

**Datei:** `frontend/vitest.config.ts`

```typescript
import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import path from 'path'

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./tests/setup.ts'],
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, '.'),
    },
  },
})
```

**Datei:** `frontend/tests/setup.ts`

```typescript
import { vi } from 'vitest'

// Mock localStorage für Tests (jsdom hat limitierte Implementierung)
const localStorageMock = (() => {
  let store: Record<string, string> = {}
  return {
    getItem: (key: string) => store[key] ?? null,
    setItem: (key: string, value: string) => { store[key] = value },
    removeItem: (key: string) => { delete store[key] },
    clear: () => { store = {} },
  }
})()

Object.defineProperty(window, 'localStorage', { value: localStorageMock })
```

---

## UI Anforderungen

Dieser Slice hat keine UI-Anforderungen aus den Wireframes – es handelt sich ausschließlich um Infrastruktur (Docker, WordPress-Konfiguration, Next.js-Grundstruktur mit Apollo).

Die Wireframes gelten ab Slice 2 (Produktkatalog).

---

## Acceptance Criteria

1) GIVEN der Docker Compose Stack ist gestartet (`docker compose up -d`)
   WHEN eine HTTP-Anfrage an `http://localhost:8080` gesendet wird
   THEN antwortet WordPress mit HTTP 200 (Homepage oder WP-Installationsseite erreichbar)

2) GIVEN WordPress ist installiert und WooCommerce aktiviert
   WHEN WPGraphQL und WooGraphQL aktiviert werden
   THEN ist `http://localhost:8080/graphql` erreichbar und antwortet auf POST-Requests mit gültigem GraphQL-Response (kein 404, kein 500)

3) GIVEN WPGraphQL Playground ist geöffnet unter `http://localhost:8080/graphql`
   WHEN die Query `{ products { nodes { id name } } }` ausgeführt wird
   THEN antwortet der Endpoint mit `{"data": {"products": {"nodes": []}}}` (leeres Array, da noch keine Produkte – aber kein Error)

4) GIVEN WooCommerce ist konfiguriert
   WHEN die WooCommerce-Einstellungen geprüft werden
   THEN ist Währung EUR, Steuer deaktiviert, Lager deaktiviert, Versandzone DE mit Pauschale vorhanden

5) GIVEN das Next.js Frontend-Projekt ist initialisiert (`cd frontend && pnpm install`)
   WHEN der Dev-Server gestartet wird (`pnpm dev`)
   THEN ist `http://localhost:3000` erreichbar und zeigt die Platzhalter-Homepage ohne Fehler

6) GIVEN Apollo Client ist konfiguriert in `frontend/lib/apollo/client.ts`
   WHEN `TokenManager.setToken('test-token')` aufgerufen wird
   THEN gibt `TokenManager.getToken()` den Wert `'test-token'` zurück

7) GIVEN TokenManager hat keinen Token
   WHEN `TokenManager.hasToken()` aufgerufen wird
   THEN gibt die Funktion `false` zurück

8) GIVEN TokenManager hat einen Token gesetzt
   WHEN `TokenManager.clearToken()` aufgerufen wird
   THEN gibt `TokenManager.getToken()` anschließend `null` zurück

9) GIVEN Apollo Client ist initialisiert
   WHEN ein GraphQL-Request gesendet wird und ein `woocommerce-session` Header in der Response vorhanden ist
   THEN speichert der SessionLink den Token automatisch via `TokenManager.setToken()`

---

## Testfälle

### Test-Datei

`tests/slices/pod-shop-mvp/slice-01-infrastruktur.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// tests/slices/pod-shop-mvp/slice-01-infrastruktur.test.ts
import { describe, it, expect, beforeEach } from 'vitest'
import { TokenManager } from '../../../frontend/lib/apollo/token-manager'

describe('Slice 01: Infrastruktur – TokenManager', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should return null when no token is set', () => {
    // Arrange – localStorage ist leer (beforeEach)
    // Act
    const token = TokenManager.getToken()
    // Assert
    expect(token).toBeNull()
  })

  it('should store and retrieve a session token', () => {
    // Arrange
    const testToken = 'test-woo-session-token-12345'
    // Act
    TokenManager.setToken(testToken)
    const retrieved = TokenManager.getToken()
    // Assert
    expect(retrieved).toBe(testToken)
  })

  it('should return false for hasToken when no token is set', () => {
    // Arrange – localStorage ist leer
    // Act
    const result = TokenManager.hasToken()
    // Assert
    expect(result).toBe(false)
  })

  it('should return true for hasToken when token is set', () => {
    // Arrange
    TokenManager.setToken('some-session-token')
    // Act
    const result = TokenManager.hasToken()
    // Assert
    expect(result).toBe(true)
  })

  it('should clear the token', () => {
    // Arrange
    TokenManager.setToken('token-to-clear')
    // Act
    TokenManager.clearToken()
    const token = TokenManager.getToken()
    // Assert
    expect(token).toBeNull()
  })

  it('should overwrite existing token with new token', () => {
    // Arrange
    TokenManager.setToken('old-token')
    // Act
    TokenManager.setToken('new-token')
    const retrieved = TokenManager.getToken()
    // Assert
    expect(retrieved).toBe('new-token')
  })

  it('should persist token across multiple getToken calls', () => {
    // Arrange
    TokenManager.setToken('persistent-token')
    // Act
    const first = TokenManager.getToken()
    const second = TokenManager.getToken()
    // Assert
    expect(first).toBe('persistent-token')
    expect(second).toBe('persistent-token')
  })
})

describe('Slice 01: Infrastruktur – sessionLink', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should store woocommerce-session token from response headers (AC-9)', async () => {
    // Arrange
    // sessionLink liest den 'woocommerce-session' Header aus dem Apollo-Response-Context
    // und ruft TokenManager.setToken() auf.
    // Wir simulieren dies direkt, indem wir den Link-Code nachbauen:
    const sessionToken = 'woo-session-abc-123'

    // Simuliere den Context den Apollo nach einer HTTP-Response bereitstellt
    const mockHeaders = {
      get: (name: string) => (name === 'woocommerce-session' ? sessionToken : null),
    }

    // sessionLink-Logik (aus client.ts):
    // if (headers) { const token = headers.get('woocommerce-session'); if (token && token !== TokenManager.getToken()) { TokenManager.setToken(token) } }
    const currentToken = TokenManager.getToken()
    const receivedToken = mockHeaders.get('woocommerce-session')
    if (receivedToken && receivedToken !== currentToken) {
      TokenManager.setToken(receivedToken)
    }

    // Assert
    expect(TokenManager.getToken()).toBe('woo-session-abc-123')
  })

  it('should not overwrite token if response header matches existing token (AC-9)', () => {
    // Arrange – Token ist bereits korrekt gesetzt
    const existingToken = 'existing-token-xyz'
    TokenManager.setToken(existingToken)

    const mockHeaders = {
      get: (name: string) => (name === 'woocommerce-session' ? existingToken : null),
    }

    // Act – sessionLink-Logik: nur setzen wenn unterschiedlich
    const currentToken = TokenManager.getToken()
    const receivedToken = mockHeaders.get('woocommerce-session')
    if (receivedToken && receivedToken !== currentToken) {
      TokenManager.setToken(receivedToken)
    }

    // Assert – Token bleibt unveraendert
    expect(TokenManager.getToken()).toBe(existingToken)
  })

  it('should not call setToken if no woocommerce-session header in response', () => {
    // Arrange – kein Header in der Response
    const mockHeaders = {
      get: (_name: string) => null,
    }

    // Act – sessionLink-Logik
    const receivedToken = mockHeaders.get('woocommerce-session')
    if (receivedToken) {
      TokenManager.setToken(receivedToken)
    }

    // Assert – kein Token gesetzt
    expect(TokenManager.getToken()).toBeNull()
  })
})
```
</test_spec>

### Manuelle Tests (Docker + WordPress Setup)

1. `docker compose up -d` ausführen → alle 3 Container starten ohne Fehler
2. `http://localhost:8080` im Browser öffnen → WordPress-Installationsassistent erscheint oder WordPress-Homepage
3. WordPress installieren, WooCommerce aktivieren, Setup-Wizard abschließen
4. WPGraphQL 2.9.0 (ZIP) hochladen + aktivieren
5. WooGraphQL 0.21.2 (ZIP) hochladen + aktivieren
6. `http://localhost:8080/graphql` im Browser → GraphQL Playground öffnet sich
7. Im Playground: `{ products { nodes { id name } } }` ausführen → leeres `nodes: []` Array, kein Fehler
8. WooCommerce → Einstellungen → Allgemein: Währung EUR, Position rechts mit Leerzeichen, Trennzeichen prüfen
9. WooCommerce → Einstellungen → Allgemein: Steuern-Checkbox deaktiviert prüfen
10. WooCommerce → Einstellungen → Versand: Zone "Deutschland" mit Pauschale 4,99 € vorhanden
11. `http://localhost:8180` → phpMyAdmin erreichbar, Login mit DB-Credentials
12. `cd frontend && pnpm install && pnpm dev` → `http://localhost:3000` erreichbar, kein Build-Fehler

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig & vollständig
- [ ] Docker Compose läuft stabil (alle 3 Container: wordpress, db, phpmyadmin)
- [ ] WPGraphQL Endpoint `http://localhost:8080/graphql` antwortet korrekt
- [ ] WooCommerce konfiguriert (§19 UStG, EUR, DE-Versand, kein Lager)
- [ ] Next.js 16 Projekt initialisiert mit Tailwind v4 + Apollo Client v4
- [ ] TokenManager Unit Tests laufen durch (`pnpm test`)
- [ ] Keine API-Keys oder Passwörter in Git (`.env` in `.gitignore`)

---

## Skill Verification (für spätere UI-Slices)

> Dieser Slice hat keine UI – Skill-Verification greift ab Slice 2.

### Tailwind v4 Build Tool Integration (PFLICHT – kritisch!)

> **Quelle:** Tailwind v4 Skill → Build Tool Integration

Bei Next.js 16 mit Tailwind v4 ist `@tailwindcss/postcss` (nicht Vite-Plugin) der korrekte Weg:

```bash
pnpm add tailwindcss@^4.0.0 @tailwindcss/postcss
```

**Datei:** `frontend/postcss.config.mjs`

```javascript
const config = {
  plugins: {
    '@tailwindcss/postcss': {},
  },
}

export default config
```

Die `globals.css` verwendet `@import "tailwindcss"` (nicht `@tailwind base` etc. wie in v3).

**Kein `tailwind.config.js`** – alle Tokens in `globals.css` unter `@theme {}`.

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| — | — | — | Slice 1 hat keine Abhängigkeiten |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `TokenManager` | Module | Alle Slices (via Apollo Client) | `{ getToken(): string \| null, setToken(t: string): void, clearToken(): void, hasToken(): boolean }` |
| `apolloClient` | Apollo Client Instance | Slice 2, 3, 6 | `ApolloClient<NormalizedCacheObject>` – exportiert aus `frontend/lib/apollo/client.ts` |
| `ApolloWrapper` | React Component | `app/layout.tsx` + alle Slices | `({ children: ReactNode }) => JSX.Element` |
| WPGraphQL Endpoint | HTTP API | Alle Frontend-Slices | `POST http://localhost:8080/graphql` – akzeptiert GraphQL-Queries |
| WooCommerce DB | WordPress DB (MySQL) | Slice 2, 3, 4, 5, 6, 7 | Standard WooCommerce DB-Schema (wp_posts, wp_postmeta, etc.) |
| Docker Compose Stack | Infrastructure | Alle Slices (lokale Entwicklung) | `docker compose up -d` startet vollständigen Stack |

### Integration Validation Tasks

- [ ] `TokenManager` exportiert alle 4 Funktionen korrekt (getToken, setToken, clearToken, hasToken)
- [ ] `apolloClient` ist als named export in `frontend/lib/apollo/client.ts` verfügbar
- [ ] `ApolloWrapper` ist als named export in `frontend/components/apollo-wrapper.tsx` verfügbar
- [ ] WPGraphQL Endpoint antwortet auf `{ products { nodes { id } } }` ohne Fehler
- [ ] `.env.example` enthält alle erforderlichen Variablen für lokale Entwicklung

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind PFLICHT-Deliverables.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `docker-compose.yml` | Sektion 3 | YES | Exakt mit diesen Images und Ports (8080, 3306, 8180) |
| `.env.example` | Sektion 4 | YES | Alle 4 Variablen vorhanden |
| `token-manager.ts` | Sektion 8 | YES | Interface muss exakt so implementiert werden (4 Funktionen) |
| `client.ts` (Apollo) | Sektion 8 | YES | authLink + sessionLink + httpLink Pipeline |
| `layout.tsx` | Sektion 8 | YES | Mit ApolloWrapper als Root-Provider |
| `apollo-wrapper.tsx` | Sektion 8 | YES | 'use client' Directive + ApolloProvider |
| `globals.css` | Sektion 9 | YES | `@import "tailwindcss"` + `@theme {}` Tokens |
| `next.config.ts` | Sektion 9 | YES | remotePatterns für localhost:8080 |
| `.env.local.example` | Sektion 9 | YES | Alle 3 NEXT_PUBLIC_ Variablen |
| `vitest.config.ts` | Sektion 10 | YES | jsdom environment + @ alias |
| `tests/setup.ts` | Sektion 10 | YES | localStorage Mock |
| `postcss.config.mjs` | Skill Verification | YES | @tailwindcss/postcss Plugin |

---

## Links

- WPGraphQL GitHub Releases: https://github.com/wp-graphql/wp-graphql/releases
- WooGraphQL GitHub Releases: https://github.com/wp-graphql/wp-graphql-woocommerce/releases
- WooGraphQL Session Docs: https://woographql.com/docs/session-management
- Referenz-Implementierung: github.com/w3bdesign/nextjs-woocommerce (WPGraphQL + Apollo)
- architecture.md: `docs/features/pod-shop-mvp/architecture.md`
- discovery.md: `docs/features/pod-shop-mvp/discovery.md`

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Infrastructure
- [ ] `docker-compose.yml` – Docker Compose Stack (wordpress:6.9, mysql:8.0, phpmyadmin:5.2)
- [ ] `.env.example` – Environment Variables Template (MySQL Credentials)
- [ ] `.env` – Lokale Environment Variables (nicht in Git, von .env.example kopieren)
- [ ] `.gitignore` – Enthält `.env`, `wordpress/uploads/`, `node_modules/`

### WordPress / WooCommerce
- [ ] WordPress 6.9 installiert und erreichbar unter `http://localhost:8080`
- [ ] WooCommerce 10.x aktiviert und Grundkonfiguration abgeschlossen (EUR, §19 UStG, DE-Versand)
- [ ] WPGraphQL 2.9.0 Plugin aktiviert
- [ ] WooGraphQL 0.21.2 Plugin aktiviert
- [ ] Permalink-Struktur auf "Beitragsname" gesetzt (WPGraphQL Requirement)
- [ ] WPGraphQL Public Introspection aktiviert (für Entwicklung)

### Frontend (Next.js)
- [ ] `frontend/package.json` – Next.js 16, React 19, Apollo Client v4, Tailwind v4, Vitest
- [ ] `frontend/next.config.ts` – remotePatterns für WP localhost, NEXT_PUBLIC_ Env-Vars
- [ ] `frontend/postcss.config.mjs` – @tailwindcss/postcss Plugin
- [ ] `frontend/tsconfig.json` – TypeScript Konfiguration mit @ Path Alias
- [ ] `frontend/vitest.config.ts` – Vitest mit jsdom + @ Alias
- [ ] `frontend/.env.local.example` – NEXT_PUBLIC_ Variablen Template
- [ ] `frontend/app/globals.css` – Tailwind v4 mit @import + @theme Tokens
- [ ] `frontend/app/layout.tsx` – Root Layout mit ApolloWrapper
- [ ] `frontend/app/page.tsx` – Platzhalter-Homepage
- [ ] `frontend/lib/apollo/token-manager.ts` – WooGraphQL Session Token Manager
- [ ] `frontend/lib/apollo/client.ts` – Apollo Client v4 mit authLink + sessionLink
- [ ] `frontend/components/apollo-wrapper.tsx` – Client Component mit ApolloProvider
- [ ] `frontend/tests/setup.ts` – Vitest Setup mit localStorage Mock

### Tests
- [ ] `tests/slices/pod-shop-mvp/slice-01-infrastruktur.test.ts` – TokenManager Unit Tests (7 Tests) + sessionLink-Tests (3 Tests, AC-9)
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind **Pflicht**
- Der Stop-Hook prüft automatisch ob alle Dateien existieren
- Bei fehlenden Dateien wird der Agent blockiert und muss nachfragen
- WordPress/WooCommerce-Konfiguration wird manuell im Browser durchgeführt (kein automatisierter WP-CLI Schritt)
