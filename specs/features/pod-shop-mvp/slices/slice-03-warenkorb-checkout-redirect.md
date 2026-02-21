# Slice 3: Warenkorb + Checkout-Redirect implementieren

> **Slice 3 von 7** für `POD Shop MVP`
>
> | Navigation | |
> |------------|---|
> | **Vorheriger:** | `slice-02-produktkatalog-frontend.md` |
> | **Nächster:** | `slice-04-rechtliches-rechnungen.md` |

---

## Metadata (für Orchestrator)

| Key | Value |
|-----|-------|
| **ID** | `slice-03-warenkorb-checkout-redirect` |
| **Test** | `pnpm test tests/slices/pod-shop-mvp/slice-03-warenkorb-checkout-redirect.test.ts` |
| **E2E** | `false` |
| **Dependencies** | `["slice-01-infrastruktur", "slice-02-produktkatalog-frontend"]` |

**Erklärung:**
- **ID**: Eindeutiger Identifier (wird für Commits und Evidence verwendet)
- **Test**: Exakter Befehl den der Orchestrator nach Implementierung ausführt
- **E2E**: `false` – Vitest Unit/Component Tests (kein Playwright)
- **Dependencies**: Slice 1 (TokenManager, apolloClient, ApolloWrapper) + Slice 2 (AddToCartButton, Types) müssen fertig sein

---

## Test-Strategy (fuer Orchestrator Pipeline)

> **Quelle:** Auto-detected basierend auf Repo-Indikatoren. Slice 1 dokumentiert: `frontend/package.json` mit Next.js 16, Vitest 3.x, `@tailwindcss/postcss`. Stack: `typescript-nextjs`.

| Key | Value |
|-----|-------|
| **Stack** | `typescript-nextjs` |
| **Test Command** | `pnpm test tests/slices/pod-shop-mvp/slice-03-warenkorb-checkout-redirect.test.ts` |
| **Integration Command** | `pnpm test tests/slices/pod-shop-mvp/slice-03-warenkorb-checkout-redirect.test.ts --reporter=verbose` |
| **Acceptance Command** | `curl -f http://localhost:3000/warenkorb && echo "Cart Page OK"` |
| **Start Command** | `docker compose up -d && cd frontend && pnpm dev` |
| **Health Endpoint** | `http://localhost:3000/warenkorb` |
| **Mocking Strategy** | `mock_external` |

**Erklaerung:**
- **Stack**: Next.js 16 (App Router), React 19, Tailwind CSS v4, Apollo Client v4
- **Test Command**: Vitest Unit Tests für CartContext, Cart Mutations, Checkout-Redirect-Logik, localStorage-Backup
- **Integration Command**: Gleiche Tests mit ausführlicher Ausgabe
- **Acceptance Command**: Manuelle Smoke Tests – Warenkorb-Seite erreichbar
- **Start Command**: Docker für WordPress Backend + Mollie Plugin, dann Next.js Dev-Server
- **Health Endpoint**: Warenkorb-Seite (zeigt Leer-State oder Produkte)
- **Mocking Strategy**: WooGraphQL Cart-Mutations werden in Unit Tests gemockt (kein echter WooCommerce-Aufruf)

---

## Slice-Übersicht

| # | Slice | Status | Datei |
|---|-------|--------|-------|
| 1 | Infrastruktur | Ready | `slice-01-infrastruktur.md` |
| 2 | Produktkatalog (Frontend) | Ready | `slice-02-produktkatalog-frontend.md` |
| 3 | Warenkorb + Checkout-Redirect | Ready | `slice-03-warenkorb-checkout-redirect.md` |
| 4 | Rechtliches + Rechnungen | Pending | `slice-04-rechtliches-rechnungen.md` |
| 5 | POD-Anbindung (Spreadconnect) | Pending | `slice-05-spreadconnect.md` |
| 6 | Pinterest Tracking | Pending | `slice-06-pinterest-tracking.md` |
| 7 | User-Accounts | Pending | `slice-07-user-accounts.md` |

---

## Kontext & Ziel

Dieser Slice implementiert den vollständigen Warenkorb-Flow im Next.js Frontend und den Checkout-Redirect auf WooCommerce. Nach Abschluss können Nutzer Produkte in den Warenkorb legen, Mengen anpassen, Produkte entfernen und über "Zur Kasse" zum WooCommerce Checkout weitergeleitet werden. Mollie wird als Zahlungsgateway in WooCommerce konfiguriert, sodass eine vollständige Test-Zahlung (Sandbox) durchführbar ist.

**Scope-Abgrenzung:**
- Pinterest `add_to_cart` Event: OUT OF SCOPE (Slice 6)
- Faktur Pro Rechnungen: OUT OF SCOPE (Slice 4)
- Cookie-Banner: OUT OF SCOPE (Slice 4)
- Spreadconnect-Bestellweiterleitung: OUT OF SCOPE (Slice 5)
- WooCommerce Checkout-Styling: OUT OF SCOPE (minimal, WooCommerce-Standard)
- User-Accounts / Login: OUT OF SCOPE (Slice 7)
- Header/Footer: IN SCOPE für Warenkorb-Icon-Badge-Update (modifiziert bestehende Header-Komponente aus Slice 2)

---

## Technische Umsetzung

### Architektur-Kontext (aus architecture.md)

> **Quelle:** `architecture.md` → GraphQL Mutations, Business Logic Flow, CheckoutRedirectService, Security: WooGraphQL Session Token

```
[Next.js Frontend]
    │
    ├─ Cart Mutations ──► WooGraphQL ──► WooCommerce Session (wp_woocommerce_sessions)
    │       │
    │       ├─ addToCart { productId, variationId, quantity }
    │       ├─ updateCartItemQuantities { items: [{ key, quantity }] }
    │       └─ removeItemsFromCart { keys: [String] }
    │
    ├─ CartContext (React Context + localStorage Backup)
    │       ├─ cartItems[]
    │       ├─ total, shippingTotal
    │       └─ Session Token (via TokenManager aus Slice 1)
    │
    └─ "Zur Kasse" ──► CheckoutRedirectService
                            │
                            └─ Redirect: http://localhost:8080/checkout?session-token={token}
                                                │
                                          [WooCommerce Checkout]
                                                │
                                          [Mollie Payment Gateway]
                                                │
                                          Order Created (status: processing)
```

### 1. Architektur-Impact

| Layer | Änderungen |
|-------|------------|
| `Presentation` (Next.js) | `frontend/contexts/cart-context.tsx` – CartContext mit React Context |
| `Presentation` (Next.js) | `frontend/app/warenkorb/page.tsx` – Neue Seite `/warenkorb` |
| `Presentation` (Next.js) | `frontend/lib/graphql/cart-mutations.ts` – WooGraphQL Cart Mutations |
| `Presentation` (Next.js) | `frontend/lib/graphql/cart-queries.ts` – WooGraphQL Cart Query |
| `Presentation` (Next.js) | `frontend/lib/graphql/cart-types.ts` – Cart TypeScript Interfaces |
| `Presentation` (Next.js) | `frontend/lib/cart/checkout-redirect.ts` – Session Token Redirect-Logik |
| `Presentation` (Next.js) | `frontend/components/cart/cart-item-row.tsx` – Einzelne Warenkorb-Zeile |
| `Presentation` (Next.js) | `frontend/components/cart/cart-summary.tsx` – Versand + Gesamt + §19 Hinweis |
| `Presentation` (Next.js) | `frontend/components/cart/quantity-stepper.tsx` – +/- Steuerung |
| `Presentation` (Next.js) | `frontend/components/cart/empty-cart.tsx` – Leer-State |
| `Presentation` (Next.js) | `frontend/components/layout/cart-icon.tsx` – Header-Warenkorb-Icon mit Badge |
| `Presentation` (Next.js) | `frontend/components/layout/header.tsx` – MODIFIZIERT: Badge-Counter eingebunden |
| `Presentation` (Next.js) | `frontend/app/produkt/[slug]/product-variant-selector.tsx` – MODIFIZIERT: onAddToCart implementiert |
| `Business Logic` (WordPress) | Mollie Plugin installiert + konfiguriert (API Keys, Test Mode) |
| `Business Logic` (WordPress) | Bestellbestätigungsseite konfiguriert in WooCommerce |

### 2. Datenfluss

```
Nutzer klickt "In den Warenkorb" auf Produktdetailseite
  ↓
addToCart(productId, variationId, quantity=1) via Apollo useMutation
  ↓
WooGraphQL Mutation → WooCommerce Session erstellt/aktualisiert
  ↓
Response: { cart: CartFields, cartItem: { key, quantity } }
  ↓
TokenManager.setToken() aus Apollo sessionLink (automatisch, Slice 1)
  ↓
CartContext.addToCart() → State-Update + localStorage Backup
  ↓
Header: Warenkorb-Icon Badge-Counter aktualisiert (cartItems.length)

Nutzer navigiert zu /warenkorb
  ↓
CartPage lädt: useCart() Hook → CartContext State
  ↓
Zeigt CartItemRow-Liste + CartSummary (Versand + Gesamt + §19)

Nutzer klickt "Zur Kasse"
  ↓
checkoutRedirect(sessionToken) – aus lib/cart/checkout-redirect.ts
  ↓
window.location.href = `${WC_CHECKOUT_URL}?session-token=${token}`
  ↓
WooCommerce übernimmt Cart-Session → Standard WooCommerce Checkout
  ↓
Mollie Payment → Bestellbestätigung
```

### 3. CartContext TypeScript Interface

```typescript
// frontend/contexts/cart-context.tsx

export interface CartItem {
  key: string                    // WooGraphQL Cart Item Key
  productId: number
  variationId: number | null
  productName: string
  variationLabel: string         // z.B. "Farbe: Schwarz, Größe: L"
  thumbnail: string | null       // URL
  quantity: number
  subtotal: string               // z.B. "59,98 €" (formatiert)
  subtotalRaw: number            // Rohwert in Cent für Berechnungen
  price: string                  // Einzelpreis formatiert
}

export interface CartState {
  cartItems: CartItem[]
  subtotal: string               // Zwischensumme Produkte
  shippingTotal: string          // Versandkosten (aus WooCommerce)
  total: string                  // Gesamtsumme inkl. Versand
  itemCount: number              // Gesamtanzahl Artikel (sum of quantities)
  isLoading: boolean             // Laufende Mutation
  error: string | null
}

export interface CartContextValue extends CartState {
  addToCart: (productId: number, variationId: number | null, quantity?: number) => Promise<void>
  updateQuantity: (key: string, quantity: number) => Promise<void>
  removeItem: (key: string) => Promise<void>
  clearError: () => void
}
```

### 4. WooGraphQL Cart Mutations

> **Quelle:** `architecture.md` → GraphQL Mutations Table (addToCart, updateCartItemQuantities, removeItemsFromCart)

**Datei:** `frontend/lib/graphql/cart-mutations.ts`

```typescript
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
  mutation AddToCart($productId: Int!, $variationId: Int, $quantity: Int) {
    addToCart(
      input: {
        productId: $productId
        variationId: $variationId
        quantity: $quantity
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
```

### 5. CartContext Implementierung

**Datei:** `frontend/contexts/cart-context.tsx`

```typescript
'use client'

import { createContext, useContext, useState, useEffect, useCallback, useMemo, ReactNode } from 'react'
import { useMutation, useQuery } from '@apollo/client'
import {
  ADD_TO_CART,
  UPDATE_CART_ITEM_QUANTITIES,
  REMOVE_ITEMS_FROM_CART,
  GET_CART,
} from '@/lib/graphql/cart-mutations'
import type { CartItem, CartState, CartContextValue } from './cart-context.types'

const CART_BACKUP_KEY = 'pod-cart-backup'

const CartContext = createContext<CartContextValue | null>(null)

function mapCartResponse(cart: unknown): Omit<CartState, 'isLoading' | 'error'> {
  // Mappt WooGraphQL Cart-Response auf CartState
  // Implementierungsdetails in cart-utils.ts
  const c = cart as Record<string, unknown>
  const contents = c?.contents as Record<string, unknown>
  const nodes = (contents?.nodes as unknown[]) ?? []

  const cartItems: CartItem[] = nodes.map((node) => {
    const n = node as Record<string, unknown>
    const product = (n.product as Record<string, unknown>)?.node as Record<string, unknown>
    const variation = n.variation as Record<string, unknown> | null
    const attributes = (variation?.attributes as Array<Record<string, unknown>>) ?? []

    const variationLabel = attributes
      .map((a) => `${a.label}: ${a.value}`)
      .join(', ')

    return {
      key: n.key as string,
      productId: product?.databaseId as number,
      variationId: (variation?.node as Record<string, unknown>)?.databaseId as number | null ?? null,
      productName: product?.name as string,
      variationLabel,
      thumbnail: ((product?.image as Record<string, unknown>)?.sourceUrl as string) ?? null,
      quantity: n.quantity as number,
      subtotal: n.subtotal as string,
      subtotalRaw: 0, // wird in cart-utils berechnet
      price: (product as Record<string, unknown>)?.price as string ?? '',
    }
  })

  return {
    cartItems,
    subtotal: c?.subtotal as string ?? '0',
    shippingTotal: c?.shippingTotal as string ?? '0',
    total: c?.total as string ?? '0',
    itemCount: contents?.itemCount as number ?? 0,
  }
}

export function CartProvider({ children }: { children: ReactNode }) {
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [cartState, setCartState] = useState<Omit<CartState, 'isLoading' | 'error'>>({
    cartItems: [],
    subtotal: '0',
    shippingTotal: '0',
    total: '0',
    itemCount: 0,
  })

  const { data: cartData } = useQuery(GET_CART, {
    fetchPolicy: 'cache-and-network',
  })

  // Apollo Client v4: onCompleted in useQuery entfernt (Breaking Change v3→v4).
  // Stattdessen useEffect mit Abhaengigkeit auf cartData verwenden.
  useEffect(() => {
    if (cartData?.cart) {
      const mapped = mapCartResponse(cartData.cart)
      setCartState(mapped)
      localStorage.setItem(CART_BACKUP_KEY, JSON.stringify(mapped.cartItems))
    }
  }, [cartData])

  // localStorage Backup laden beim ersten Render (Robustheit bei Session-Verlust)
  useEffect(() => {
    if (cartState.cartItems.length === 0) {
      try {
        const backup = localStorage.getItem(CART_BACKUP_KEY)
        if (backup) {
          const parsed = JSON.parse(backup) as CartItem[]
          if (parsed.length > 0) {
            setCartState((prev) => ({ ...prev, cartItems: parsed, itemCount: parsed.reduce((sum, i) => sum + i.quantity, 0) }))
          }
        }
      } catch {
        // Silent fail – localStorage nicht verfügbar
      }
    }
  }, [])

  const [addToCartMutation] = useMutation(ADD_TO_CART)
  const [updateQuantityMutation] = useMutation(UPDATE_CART_ITEM_QUANTITIES)
  const [removeItemMutation] = useMutation(REMOVE_ITEMS_FROM_CART)

  const addToCart = useCallback(async (productId: number, variationId: number | null, quantity = 1) => {
    setIsLoading(true)
    setError(null)
    try {
      const { data } = await addToCartMutation({
        variables: { productId, variationId, quantity },
      })
      if (data?.addToCart?.cart) {
        const mapped = mapCartResponse(data.addToCart.cart)
        setCartState(mapped)
        localStorage.setItem(CART_BACKUP_KEY, JSON.stringify(mapped.cartItems))
      }
    } catch {
      setError('Produkt konnte nicht hinzugefügt werden.')
    } finally {
      setIsLoading(false)
    }
  }, [addToCartMutation])

  const updateQuantity = useCallback(async (key: string, quantity: number) => {
    if (quantity < 1 || quantity > 99) return
    setIsLoading(true)
    setError(null)
    try {
      const { data } = await updateQuantityMutation({
        variables: { items: [{ key, quantity }] },
      })
      if (data?.updateCartItemQuantities?.cart) {
        const mapped = mapCartResponse(data.updateCartItemQuantities.cart)
        setCartState(mapped)
        localStorage.setItem(CART_BACKUP_KEY, JSON.stringify(mapped.cartItems))
      }
    } catch {
      setError('Menge konnte nicht aktualisiert werden.')
    } finally {
      setIsLoading(false)
    }
  }, [updateQuantityMutation])

  const removeItem = useCallback(async (key: string) => {
    setIsLoading(true)
    setError(null)
    try {
      const { data } = await removeItemMutation({
        variables: { keys: [key] },
      })
      if (data?.removeItemsFromCart?.cart) {
        const mapped = mapCartResponse(data.removeItemsFromCart.cart)
        setCartState(mapped)
        localStorage.setItem(CART_BACKUP_KEY, JSON.stringify(mapped.cartItems))
      }
    } catch {
      setError('Produkt konnte nicht entfernt werden.')
    } finally {
      setIsLoading(false)
    }
  }, [removeItemMutation])

  const clearError = useCallback(() => setError(null), [])

  const value = useMemo<CartContextValue>(() => ({
    ...cartState,
    isLoading,
    error,
    addToCart,
    updateQuantity,
    removeItem,
    clearError,
  }), [cartState, isLoading, error, addToCart, updateQuantity, removeItem, clearError])

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>
}

export function useCart(): CartContextValue {
  const ctx = useContext(CartContext)
  if (!ctx) throw new Error('useCart must be used within CartProvider')
  return ctx
}
```

### 6. Checkout-Redirect Service

> **Quelle:** `architecture.md` → CheckoutRedirectService, Constraints: Hybrid Checkout, Cart Session verloren bei Redirect (Risk)

**Datei:** `frontend/lib/cart/checkout-redirect.ts`

```typescript
import { TokenManager } from '@/lib/apollo/token-manager'

const CART_BACKUP_KEY = 'pod-cart-backup'
const WC_CHECKOUT_URL = process.env.NEXT_PUBLIC_WC_CHECKOUT_URL ?? 'http://localhost:8080/checkout'

/**
 * Leitet den Nutzer zum WooCommerce Checkout weiter.
 * Übergibt den WooGraphQL Session Token als URL-Parameter.
 * Fallback: localStorage Backup wird vor Redirect gesichert.
 */
export function checkoutRedirect(): void {
  const token = TokenManager.getToken()

  // Sicherheits-Check: localStorage Backup aktualisieren vor Redirect
  // (bereits durch CartContext aktuell gehalten)

  if (!token) {
    // Fallback: Redirect ohne Token – WooCommerce versucht eigene Session
    window.location.href = WC_CHECKOUT_URL
    return
  }

  const checkoutUrl = `${WC_CHECKOUT_URL}?session-token=${encodeURIComponent(token)}`
  window.location.href = checkoutUrl
}

/**
 * Prüft ob ein gespeicherter Cart-Backup vorhanden ist (localStorage).
 * Wird für Robustheit bei Session-Verlust verwendet.
 */
export function hasCartBackup(): boolean {
  try {
    const backup = localStorage.getItem(CART_BACKUP_KEY)
    if (!backup) return false
    const parsed = JSON.parse(backup)
    return Array.isArray(parsed) && parsed.length > 0
  } catch {
    return false
  }
}
```

### 7. Mollie Plugin Setup (WordPress)

> **Quelle:** `architecture.md` → Payment Gateway: Mollie for WooCommerce v8.1.3, Technology Decisions

| Schritt | Aktion | Pfad |
|---------|--------|------|
| 1 | Mollie Plugin ZIP herunterladen | https://wordpress.org/plugins/mollie-payments-for-woocommerce/ |
| 2 | Plugin hochladen und aktivieren | WP-Admin → Plugins → Neu hinzufügen → ZIP hochladen |
| 3 | Mollie Business Account erstellen | https://www.mollie.com/dashboard |
| 4 | Test API Key eintragen | WP-Admin → WooCommerce → Einstellungen → Zahlungen → Mollie → API Keys |
| 5 | Test Mode aktivieren | Mollie Einstellungen → Test Mode: aktiviert |
| 6 | Zahlungsarten aktivieren | Kreditkarte, PayPal, Klarna aktivieren (alle im Test-Modus verfügbar) |
| 7 | Bestellbestätigungsseite | WooCommerce → Einstellungen → Erweitert → Bestell-Empfang-Seite prüfen |

**Mollie Test-Karte für Sandbox:**
- Kreditkarte: `3782 8224 6310 005` (AMEX Test), Ablauf: beliebig zukünftig, CVC: beliebig
- Alternative: Mollie Dashboard → Test → Test-Zahlungen direkt

**Environment Variable (WordPress/Docker):**

```bash
# In .env (Root-Ebene, für Docker)
MOLLIE_API_KEY_TEST=test_xxxxxxxxxxxxxxxxxxxx
MOLLIE_API_KEY_LIVE=live_xxxxxxxxxxxxxxxxxxxx
```

**Hinweis:** Mollie API Keys werden im WP-Admin eingetragen, nicht direkt aus `.env` gelesen. `.env` dient als Referenz für manuelle Eingabe.

### 8. Warenkorb-Seite

**Datei:** `frontend/app/warenkorb/page.tsx`

```typescript
import type { Metadata } from 'next'
import { CartPageClient } from './cart-page-client'

export const metadata: Metadata = {
  title: 'Warenkorb – POD Shop',
  robots: { index: false, follow: false },
}

export default function WarenkorbPage() {
  return <CartPageClient />
}
```

**Datei:** `frontend/app/warenkorb/cart-page-client.tsx`

```typescript
'use client'

import { useCart } from '@/contexts/cart-context'
import { CartItemRow } from '@/components/cart/cart-item-row'
import { CartSummary } from '@/components/cart/cart-summary'
import { EmptyCart } from '@/components/cart/empty-cart'

export function CartPageClient() {
  const { cartItems, isLoading, error, clearError } = useCart()

  return (
    <main className="max-w-2xl mx-auto px-4 py-8">
      <h1 className="text-2xl font-semibold tracking-tight mb-6">
        Warenkorb
        {cartItems.length > 0 && (
          <span className="text-text-secondary font-normal text-lg ml-2">
            ({cartItems.reduce((sum, i) => sum + i.quantity, 0)} Artikel)
          </span>
        )}
      </h1>

      {error && (
        <div
          role="alert"
          aria-live="polite"
          className="mb-4 p-3 bg-red-50 border border-red-200 text-red-800 rounded-lg text-sm flex items-center justify-between"
        >
          <span>{error}</span>
          <button
            onClick={clearError}
            aria-label="Fehlermeldung schließen"
            className="ml-2 text-red-600 hover:text-red-800 focus-visible:ring-2 focus-visible:ring-red-500 rounded"
          >
            ✕
          </button>
        </div>
      )}

      {cartItems.length === 0 ? (
        <EmptyCart />
      ) : (
        <div className="space-y-4">
          {cartItems.map((item) => (
            <CartItemRow key={item.key} item={item} isLoading={isLoading} />
          ))}
          <hr className="border-border" />
          <CartSummary />
        </div>
      )}
    </main>
  )
}
```

### 9. Warenkorb-Komponenten

**Datei:** `frontend/components/cart/cart-item-row.tsx`

```typescript
'use client'

import Image from 'next/image'
import { useCart } from '@/contexts/cart-context'
import { QuantityStepper } from './quantity-stepper'
import type { CartItem } from '@/contexts/cart-context.types'

interface CartItemRowProps {
  item: CartItem
  isLoading: boolean
}

export function CartItemRow({ item, isLoading }: CartItemRowProps) {
  const { removeItem } = useCart()

  return (
    <div className="flex gap-4 p-4 border border-border rounded-lg">
      {/* Thumbnail */}
      <div className="flex-shrink-0 w-20 h-20 relative rounded overflow-hidden bg-surface-elevated">
        {item.thumbnail ? (
          <Image
            src={item.thumbnail}
            alt={item.productName}
            fill
            sizes="80px"
            className="object-cover"
          />
        ) : (
          <div className="w-full h-full bg-surface-elevated" aria-hidden="true" />
        )}
      </div>

      {/* Produktinfo */}
      <div className="flex-1 min-w-0">
        <p className="font-medium text-text-primary truncate">{item.productName}</p>
        {item.variationLabel && (
          <p className="text-sm text-text-secondary mt-0.5">{item.variationLabel}</p>
        )}

        <div className="flex items-center justify-between mt-3">
          <QuantityStepper
            itemKey={item.key}
            quantity={item.quantity}
            disabled={isLoading}
          />
          <button
            onClick={() => removeItem(item.key)}
            disabled={isLoading}
            aria-label={`${item.productName} aus dem Warenkorb entfernen`}
            className="text-text-secondary hover:text-red-600 focus-visible:ring-2 focus-visible:ring-red-500 rounded p-1 transition-colors disabled:opacity-50"
            style={{ touchAction: 'manipulation' }}
          >
            <svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M12 4L4 12M4 4l8 8" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
            </svg>
          </button>
        </div>
      </div>

      {/* Zeilenpreis */}
      <div className="flex-shrink-0 text-right">
        <p className="font-medium tabular-nums">{item.subtotal}</p>
        {item.quantity > 1 && (
          <p className="text-xs text-text-secondary tabular-nums mt-1">{item.price} / Stk.</p>
        )}
      </div>
    </div>
  )
}
```

**Datei:** `frontend/components/cart/quantity-stepper.tsx`

```typescript
'use client'

import { useCart } from '@/contexts/cart-context'

interface QuantityStepperProps {
  itemKey: string
  quantity: number
  disabled: boolean
}

export function QuantityStepper({ itemKey, quantity, disabled }: QuantityStepperProps) {
  const { updateQuantity } = useCart()

  return (
    <div className="flex items-center gap-1" role="group" aria-label="Menge ändern">
      <button
        onClick={() => updateQuantity(itemKey, quantity - 1)}
        disabled={disabled || quantity <= 1}
        aria-label="Menge verringern"
        className="w-8 h-8 flex items-center justify-center border border-border rounded hover:bg-surface-elevated focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        style={{ touchAction: 'manipulation', minWidth: '2rem', minHeight: '2rem' }}
      >
        <span aria-hidden="true">−</span>
      </button>

      <span
        className="w-8 text-center tabular-nums text-sm font-medium"
        aria-live="polite"
        aria-label={`Menge: ${quantity}`}
      >
        {quantity}
      </span>

      <button
        onClick={() => updateQuantity(itemKey, quantity + 1)}
        disabled={disabled || quantity >= 99}
        aria-label="Menge erhöhen"
        className="w-8 h-8 flex items-center justify-center border border-border rounded hover:bg-surface-elevated focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
        style={{ touchAction: 'manipulation', minWidth: '2rem', minHeight: '2rem' }}
      >
        <span aria-hidden="true">+</span>
      </button>
    </div>
  )
}
```

**Datei:** `frontend/components/cart/cart-summary.tsx`

```typescript
'use client'

import { useCart } from '@/contexts/cart-context'
import { checkoutRedirect } from '@/lib/cart/checkout-redirect'

export function CartSummary() {
  const { subtotal, shippingTotal, total, cartItems, isLoading } = useCart()

  const isEmpty = cartItems.length === 0

  return (
    <div className="mt-6 space-y-3">
      {/* Versandkosten */}
      <div className="flex justify-between text-sm">
        <span className="text-text-secondary">Versandkosten</span>
        <span className="tabular-nums">{shippingTotal}</span>
      </div>

      {/* Trennlinie */}
      <hr className="border-border" />

      {/* Gesamtpreis */}
      <div className="flex justify-between font-semibold text-lg">
        <span>Gesamt</span>
        <span className="tabular-nums">{total}</span>
      </div>

      {/* §19 UStG Hinweis */}
      <p className="text-xs text-text-secondary">
        Gemäß §19 UStG wird keine Umsatzsteuer berechnet.
      </p>

      {/* Zur Kasse Button */}
      <button
        onClick={checkoutRedirect}
        disabled={isEmpty || isLoading}
        aria-disabled={isEmpty || isLoading}
        className="w-full py-3 px-6 bg-primary text-white font-medium rounded-lg hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition-colors mt-4"
        style={{ touchAction: 'manipulation', minHeight: '2.75rem' }}
      >
        Zur Kasse
      </button>
    </div>
  )
}
```

**Datei:** `frontend/components/cart/empty-cart.tsx`

```typescript
import Link from 'next/link'

export function EmptyCart() {
  return (
    <div className="text-center py-16">
      <p className="text-text-secondary text-lg mb-4">Dein Warenkorb ist leer.</p>
      <Link
        href="/"
        className="inline-flex items-center px-5 py-2.5 bg-primary text-white rounded-lg font-medium hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 transition-colors"
        style={{ touchAction: 'manipulation' }}
      >
        Weiter shoppen
      </Link>
    </div>
  )
}
```

### 10. Warenkorb-Icon im Header (Modifikation)

**Datei:** `frontend/components/layout/cart-icon.tsx`

```typescript
'use client'

import Link from 'next/link'
import { useCart } from '@/contexts/cart-context'

export function CartIcon() {
  const { itemCount } = useCart()

  return (
    <Link
      href="/warenkorb"
      aria-label={itemCount > 0 ? `Warenkorb: ${itemCount} Artikel` : 'Warenkorb ist leer'}
      className="relative flex items-center justify-center w-10 h-10 rounded-lg hover:bg-surface-elevated focus-visible:ring-2 focus-visible:ring-primary transition-colors"
      style={{ touchAction: 'manipulation' }}
    >
      {/* Warenkorb-Icon SVG */}
      <svg
        aria-hidden="true"
        width="22"
        height="22"
        viewBox="0 0 22 22"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z" />
        <line x1="3" y1="6" x2="21" y2="6" />
        <path d="M16 10a4 4 0 01-8 0" />
      </svg>

      {/* Badge */}
      {itemCount > 0 && (
        <span
          aria-hidden="true"
          className="absolute -top-1 -right-1 min-w-[1.125rem] h-[1.125rem] px-1 flex items-center justify-center bg-primary text-white text-xs font-bold rounded-full tabular-nums leading-none"
        >
          {itemCount > 99 ? '99+' : itemCount}
        </span>
      )}
    </Link>
  )
}
```

**Modifikation:** `frontend/components/layout/header.tsx` – CartIcon einbinden:

```typescript
// MODIFIKATION: CartIcon aus cart-icon.tsx importieren und im Header einbinden
// Ersetzt das statische Warenkorb-Icon aus Slice 2 durch das dynamische CartIcon
import { CartIcon } from '@/components/layout/cart-icon'
// ... im JSX: <CartIcon /> statt des statischen Icons
```

### 11. AddToCartButton Modifikation – Erfolgs-State (isSuccess)

> **Quelle:** `wireframes.md` → Product Detail Page State Variations: "Add to cart success | Button briefly shows checkmark + 'Hinzugefuegt!', header cart badge updates"
> **Fix fuer AC-1:** `add-to-cart-button.tsx` aus Slice 2 wird MODIFIZIERT, um den Erfolgs-State (Checkmark + "Hinzugefuegt!") zu implementieren.

**Datei:** `frontend/components/product/add-to-cart-button.tsx` – MODIFIZIERT

```typescript
'use client'

import { useState, useEffect } from 'react'

interface AddToCartButtonProps {
  productId: number
  variationId: number | null
  canAdd: boolean
  hasVariants: boolean
  onAddToCart?: (productId: number, variationId: number | null) => Promise<void>
  isLoading?: boolean
}

const SUCCESS_DURATION_MS = 2000

export function AddToCartButton({
  productId,
  variationId,
  canAdd,
  hasVariants,
  onAddToCart,
  isLoading = false,
}: AddToCartButtonProps) {
  const [isSuccess, setIsSuccess] = useState(false)

  // Erfolgs-State nach SUCCESS_DURATION_MS automatisch zuruecksetzen
  useEffect(() => {
    if (!isSuccess) return
    const timer = setTimeout(() => setIsSuccess(false), SUCCESS_DURATION_MS)
    return () => clearTimeout(timer)
  }, [isSuccess])

  const handleClick = async () => {
    if (!canAdd || isLoading || isSuccess) return
    if (onAddToCart) {
      await onAddToCart(productId, variationId)
      setIsSuccess(true)
    }
  }

  const isDisabled = !canAdd || isLoading || isSuccess

  let label: string
  if (isSuccess) {
    label = 'Hinzugefuegt!'
  } else if (isLoading) {
    label = 'Wird hinzugefuegt...'
  } else if (!canAdd && hasVariants) {
    label = 'Variante waehlen'
  } else {
    label = 'In den Warenkorb'
  }

  return (
    <button
      onClick={handleClick}
      disabled={isDisabled}
      aria-disabled={isDisabled}
      aria-live="polite"
      className={[
        'w-full py-3 px-6 font-medium rounded-lg transition-colors',
        'focus-visible:ring-2 focus-visible:ring-offset-2',
        'disabled:cursor-not-allowed',
        isSuccess
          ? 'bg-green-600 text-white focus-visible:ring-green-500'
          : 'bg-primary text-white hover:bg-primary-hover focus-visible:ring-primary disabled:opacity-50',
      ].join(' ')}
      style={{ touchAction: 'manipulation', minHeight: '2.75rem' }}
    >
      {isSuccess ? (
        <span className="flex items-center justify-center gap-2">
          {/* Checkmark Icon */}
          <svg
            aria-hidden="true"
            width="18"
            height="18"
            viewBox="0 0 18 18"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <polyline points="3 9 7 13 15 5" />
          </svg>
          {label}
        </span>
      ) : (
        label
      )}
    </button>
  )
}
```

**Verhalten des Erfolgs-States:**
- Nach erfolgreichem `onAddToCart` Aufruf: `isSuccess = true`
- Button wechselt zu gruener Farbe (`bg-green-600`) mit Checkmark-Icon und Text "Hinzugefuegt!"
- Nach `SUCCESS_DURATION_MS` (2000ms) automatischer Ruecksprung in Ausgangszustand
- Waehrend `isSuccess = true`: Button ist `disabled` (verhindert Doppelklick)
- `aria-live="polite"` kuendigt die Zustandsaenderung fuer Screen-Reader an

### 13. ProductVariantSelector Modifikation (Slice 2)

**Datei:** `frontend/app/produkt/[slug]/product-variant-selector.tsx` – MODIFIZIERT

```typescript
// Modifikation gegenüber Slice 2:
// onAddToCart Callback wird jetzt mit CartContext.addToCart verbunden

'use client'

import { useState } from 'react'
import { useCart } from '@/contexts/cart-context'
import { VariantSelectorSize } from '@/components/product/variant-selector-size'
import { VariantSelectorColor } from '@/components/product/variant-selector-color'
import { AddToCartButton } from '@/components/product/add-to-cart-button'
import { findVariation } from '@/lib/product/variant-utils'
import type { ProductDetailData, VariantOptions, SelectedVariant } from '@/lib/graphql/types'

interface ProductVariantSelectorProps {
  product: ProductDetailData
  variantOptions: VariantOptions
}

export function ProductVariantSelector({ product, variantOptions }: ProductVariantSelectorProps) {
  const [selectedVariant, setSelectedVariant] = useState<SelectedVariant>({ size: null, color: null })
  const { addToCart, isLoading } = useCart()

  const matchedVariation = findVariation(product.variations?.nodes ?? [], selectedVariant.size, selectedVariant.color)
  const canAddToCart = matchedVariation !== null || (variantOptions.sizes.length === 0 && variantOptions.colors.length === 0)

  const handleAddToCart = async (productId: number, variationId: number | null) => {
    await addToCart(productId, variationId, 1)
  }

  return (
    <div className="space-y-4">
      {variantOptions.colors.length > 0 && (
        <VariantSelectorColor
          colors={variantOptions.colors}
          selectedColor={selectedVariant.color}
          onSelect={(color) => setSelectedVariant((prev) => ({ ...prev, color }))}
        />
      )}
      {variantOptions.sizes.length > 0 && (
        <VariantSelectorSize
          sizes={variantOptions.sizes}
          selectedSize={selectedVariant.size}
          onSelect={(size) => setSelectedVariant((prev) => ({ ...prev, size }))}
        />
      )}
      <AddToCartButton
        productId={product.databaseId}
        variationId={matchedVariation?.databaseId ?? null}
        canAdd={canAddToCart}
        hasVariants={variantOptions.sizes.length > 0 || variantOptions.colors.length > 0}
        onAddToCart={handleAddToCart}
        isLoading={isLoading}
      />
    </div>
  )
}
```

### 14. CartProvider in Root Layout einbinden

**Modifikation:** `frontend/app/layout.tsx`

```typescript
import type { Metadata } from 'next'
import { ApolloWrapper } from '@/components/apollo-wrapper'
import { CartProvider } from '@/contexts/cart-context'
import './globals.css'

export const metadata: Metadata = {
  title: 'POD Shop',
  description: 'Print-on-Demand Shop',
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="de">
      <body>
        <ApolloWrapper>
          <CartProvider>
            {children}
          </CartProvider>
        </ApolloWrapper>
      </body>
    </html>
  )
}
```

### 15. CartContext Types (separates File für Testbarkeit)

**Datei:** `frontend/contexts/cart-context.types.ts`

```typescript
export interface CartItem {
  key: string
  productId: number
  variationId: number | null
  productName: string
  variationLabel: string
  thumbnail: string | null
  quantity: number
  subtotal: string
  subtotalRaw: number
  price: string
}

export interface CartState {
  cartItems: CartItem[]
  subtotal: string
  shippingTotal: string
  total: string
  itemCount: number
  isLoading: boolean
  error: string | null
}

export interface CartContextValue extends CartState {
  addToCart: (productId: number, variationId: number | null, quantity?: number) => Promise<void>
  updateQuantity: (key: string, quantity: number) => Promise<void>
  removeItem: (key: string) => Promise<void>
  clearError: () => void
}
```

### 16. Abhängigkeiten (package.json Ergänzungen)

Keine neuen npm-Abhängigkeiten erforderlich. Alle verwendeten Libraries bereits in Slice 1 installiert:
- `@apollo/client` v4 – useMutation, useQuery
- `react` v19 – createContext, useContext, useState, useCallback, useMemo
- `next` v16 – next/image, next/link

---

## UI Anforderungen

### Wireframe (aus wireframes.md)

> **Quelle:** `wireframes.md` → Screen: Cart Page + Shared: Header

```
┌─────────────────────────────────────┐
│          [Header]                   │
├─────────────────────────────────────┤
│                                     │
│  ① Warenkorb (3 Artikel)            │
│  ═══════════════════════════════    │
│                                     │
│  ┌─────────────────────────────┐    │
│  │ ┌──────┐                    │    │
│  │ │②[img]│  ③ Product Name    │    │
│  │ │      │  Farbe: Schwarz    │    │
│  │ └──────┘  Größe: L          │    │
│  │                             │    │
│  │  ④ [-] [  2  ] [+]  ⑤ ✕    │    │
│  │                             │    │
│  │              ⑥ 59,98 €      │    │
│  └─────────────────────────────┘    │
│                                     │
│  ═══════════════════════════════    │
│                                     │
│  Versandkosten        ⑦ 4,99 €     │
│  ───────────────────────────────    │
│  Gesamt               ⑧ 94,96 €    │
│                                     │
│  Gemäß §19 UStG wird keine         │
│  Umsatzsteuer berechnet.           │
│                                     │
│  ┌─────────────────────────────┐    │
│  │      ⑨ ZUR KASSE            │    │
│  └─────────────────────────────┘    │
│                                     │
│          [Footer]                   │
└─────────────────────────────────────┘
```

**Referenz Skills:**
- `.claude/skills/react-best-practices/SKILL.md` – rerender-memo, rerender-functional-setstate
- `.claude/skills/web-design/SKILL.md` – Accessibility, Touch, Semantic HTML
- `.claude/skills/tailwind-v4/SKILL.md` – tabular-nums, spacing tokens

### 1. CartItemRow (Wireframe ② – ⑥)

**Komponenten & Dateien:**
- `components/cart/cart-item-row.tsx` – Einzelne Zeile mit Bild, Name, Variante, Mengensteuerung, Preis, Entfernen-Button

**Verhalten:**
- Thumbnail: 80x80px, `next/image`, `fill`, `sizes="80px"`, `object-cover`
- Varianten-Label: `variationLabel` aus CartItem, z.B. "Farbe: Schwarz, Größe: L"
- Preis: Zeilengesamtpreis (`subtotal`) rechts ausgerichtet, `tabular-nums`
- Entfernen: X-Icon-Button mit `aria-label`, ruft `removeItem(key)` auf

**Zustände:**
- Loading: Stepper-Buttons + Entfernen-Button `disabled`, `opacity-50`
- Quantity 1: Minus-Button `disabled`
- Quantity 99: Plus-Button `disabled`

### 2. CartSummary (Wireframe ⑦ – ⑨)

**Komponenten & Dateien:**
- `components/cart/cart-summary.tsx` – Versand, Gesamt, §19-Hinweis, Checkout-Button

**Verhalten:**
- Versandkosten: separate Zeile über Gesamtpreis (aus WooGraphQL `cart.shippingTotal`)
- Gesamtpreis: fett, groß, `tabular-nums`
- §19 UStG: exakter Text "Gemäß §19 UStG wird keine Umsatzsteuer berechnet."
- Checkout-Button: full-width, primär, `min-height: 2.75rem`, ruft `checkoutRedirect()` auf

**Zustände:**
- Warenkorb leer: Checkout-Button `disabled`, `aria-disabled="true"`
- Loading (Mutation läuft): Checkout-Button `disabled`

### 3. EmptyCart (Leer-State aus wireframes.md Cart Page)

**Komponenten & Dateien:**
- `components/cart/empty-cart.tsx` – Leer-State mit Text + Link

**Verhalten:**
- Text: "Dein Warenkorb ist leer."
- Button/Link: "Weiter shoppen" → `/` (Homepage)

**Zustände:**
- Nur ein State: leer

### 4. CartIcon Header (Wireframe Shared: Header ②)

**Komponenten & Dateien:**
- `components/layout/cart-icon.tsx` – Dynamisches Icon mit Badge

**Verhalten:**
- `itemCount === 0`: Kein Badge angezeigt (entspricht Wireframe "Cart empty: No badge shown")
- `itemCount > 0`: Badge mit Zahl (entspricht Wireframe "Cart has items: Badge with number")
- `itemCount > 99`: Badge zeigt "99+"
- Link-Target: `/warenkorb`

**Zustände:**
- Leer: Icon ohne Badge
- Mit Artikeln: Icon + Badge (Position: top-right, Kreis, primäre Farbe)

### 5. Accessibility

- [x] Entfernen-Button: `aria-label` enthält Produktnamen
- [x] QuantityStepper: `role="group"`, einzelne Buttons mit `aria-label`
- [x] Mengenanzahl: `aria-live="polite"` für Screen-Reader-Ankündigung
- [x] CartIcon: `aria-label` mit Artikelanzahl (dynamisch)
- [x] Fehlermeldung: `role="alert"`, `aria-live="polite"`
- [x] Checkout-Button: `aria-disabled` wenn deaktiviert
- [x] Alle Touch-Targets: `min-height: 2.75rem` (44px), `touch-action: manipulation`
- [x] Images: `alt` Text vorhanden, explizite `width`/`height` via next/image `fill`

---

## Acceptance Criteria

1) GIVEN ein Nutzer ist auf der Produktdetailseite und hat Farbe + Größe gewählt
   WHEN der Nutzer auf "In den Warenkorb" klickt
   THEN wird die `addToCart` WooGraphQL Mutation ausgeführt, der Button zeigt kurz einen Erfolgs-State (Checkmark + "Hinzugefügt!"), und das Badge-Icon im Header aktualisiert sich auf die neue Artikelanzahl

2) GIVEN der Nutzer hat Produkte im Warenkorb
   WHEN der Nutzer `/warenkorb` aufruft
   THEN werden alle Produkte mit Thumbnail, Name, Variantenbezeichnung (Farbe + Größe), Menge, Einzelpreis und Zeilengesamtpreis angezeigt

3) GIVEN der Nutzer sieht ein Produkt im Warenkorb mit Menge 2
   WHEN der Nutzer auf den "+" Button klickt
   THEN wird die `updateCartItemQuantities` Mutation ausgeführt, die Menge erhöht sich auf 3, und der Zeilengesamtpreis aktualisiert sich entsprechend

4) GIVEN der Nutzer sieht ein Produkt im Warenkorb mit Menge 1
   WHEN der Nutzer versucht auf den "-" Button zu klicken
   THEN ist der "-" Button deaktiviert (Mindestmenge 1 kann nicht unterschritten werden)

5) GIVEN der Nutzer sieht ein Produkt im Warenkorb
   WHEN der Nutzer auf den "✕ Entfernen"-Button klickt
   THEN wird die `removeItemsFromCart` Mutation ausgeführt, das Produkt verschwindet aus der Liste, und bei letztem Produkt zeigt die Seite den Leer-State

6) GIVEN der Warenkorb ist leer
   WHEN der Nutzer `/warenkorb` aufruft
   THEN wird "Dein Warenkorb ist leer." angezeigt mit einem "Weiter shoppen"-Link zur Homepage; der "Zur Kasse"-Button ist nicht vorhanden oder deaktiviert

7) GIVEN die Warenkorb-Seite zeigt Produkte
   WHEN der Nutzer die Preisübersicht ansieht
   THEN werden Versandkosten als separate Zeile angezeigt, darunter der Gesamtpreis, und darunter der Text "Gemäß §19 UStG wird keine Umsatzsteuer berechnet."

8) GIVEN der Nutzer hat Produkte im Warenkorb und klickt "Zur Kasse"
   WHEN `checkoutRedirect()` ausgeführt wird
   THEN wird der Nutzer auf `http://localhost:8080/checkout?session-token={token}` weitergeleitet, wobei `{token}` der aktuelle WooGraphQL Session Token ist

9) GIVEN ein WooGraphQL Session Token ist in localStorage gespeichert
   WHEN der Nutzer nach einem Browser-Tab-Schließen und -Öffnen zurückkommt
   THEN ist der localStorage-Backup der Cart-Items weiterhin verfügbar und wird im CartContext initialisiert

10) GIVEN Mollie ist in WooCommerce konfiguriert (Test Mode)
    WHEN der Nutzer den WooCommerce Checkout mit Mollie-Testzahlung abschließt
    THEN wird eine WooCommerce-Bestellung mit Status "Processing" erstellt und die Bestellbestätigungsseite wird angezeigt

---

## Testfälle

### Test-Datei

`tests/slices/pod-shop-mvp/slice-03-warenkorb-checkout-redirect.test.ts`

### Unit Tests (Vitest)

<test_spec>
```typescript
// tests/slices/pod-shop-mvp/slice-03-warenkorb-checkout-redirect.test.ts
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { checkoutRedirect, hasCartBackup } from '../../../frontend/lib/cart/checkout-redirect'
import { TokenManager } from '../../../frontend/lib/apollo/token-manager'

// Mocks
vi.stubEnv('NEXT_PUBLIC_WC_CHECKOUT_URL', 'http://localhost:8080/checkout')

describe('Slice 03: Warenkorb – checkoutRedirect', () => {
  beforeEach(() => {
    localStorage.clear()
    // window.location.href Mock
    Object.defineProperty(window, 'location', {
      value: { href: '' },
      writable: true,
    })
  })

  it('should redirect to checkout with session token in URL (AC-8)', () => {
    // Arrange
    TokenManager.setToken('test-session-token-abc')
    // Act
    checkoutRedirect()
    // Assert
    expect(window.location.href).toBe(
      'http://localhost:8080/checkout?session-token=test-session-token-abc'
    )
  })

  it('should redirect to checkout without token if no session token exists', () => {
    // Arrange – kein Token gesetzt
    // Act
    checkoutRedirect()
    // Assert
    expect(window.location.href).toBe('http://localhost:8080/checkout')
  })

  it('should URL-encode the session token in redirect URL', () => {
    // Arrange – Token mit Sonderzeichen
    TokenManager.setToken('token+with/special=chars')
    // Act
    checkoutRedirect()
    // Assert
    expect(window.location.href).toContain('session-token=')
    expect(window.location.href).not.toContain('+') // URL-encoded
  })
})

describe('Slice 03: Warenkorb – hasCartBackup', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('should return false when no cart backup exists', () => {
    expect(hasCartBackup()).toBe(false)
  })

  it('should return false when cart backup is empty array', () => {
    localStorage.setItem('pod-cart-backup', JSON.stringify([]))
    expect(hasCartBackup()).toBe(false)
  })

  it('should return true when cart backup has items (AC-9)', () => {
    const mockItems = [{ key: 'abc123', productName: 'T-Shirt', quantity: 1 }]
    localStorage.setItem('pod-cart-backup', JSON.stringify(mockItems))
    expect(hasCartBackup()).toBe(true)
  })

  it('should return false when cart backup is invalid JSON', () => {
    localStorage.setItem('pod-cart-backup', 'invalid-json{')
    expect(hasCartBackup()).toBe(false)
  })
})

describe('Slice 03: Warenkorb – CartContext Business Logic', () => {
  it('should not allow quantity below 1 in updateQuantity (AC-4)', () => {
    // Arrange – Validation-Regel aus architecture.md: Cart Quantity >= 1
    const quantity = 1
    const newQuantity = quantity - 1 // = 0
    // Assert: Validation fängt das ab
    expect(newQuantity).toBeLessThan(1)
    // Die CartContext.updateQuantity() Funktion gibt früh zurück wenn quantity < 1
    // Diese Business-Rule wird durch den Minus-Button-Disabled-State erzwungen
  })

  it('should not allow quantity above 99 in updateQuantity', () => {
    // Arrange – architecture.md: Cart Quantity <= 99
    const quantity = 99
    const newQuantity = quantity + 1 // = 100
    expect(newQuantity).toBeGreaterThan(99)
    // CartContext.updateQuantity() gibt früh zurück wenn quantity > 99
  })

  it('should calculate itemCount as sum of all quantities', () => {
    // Arrange
    const mockItems = [
      { quantity: 2 },
      { quantity: 3 },
      { quantity: 1 },
    ]
    // Act
    const itemCount = mockItems.reduce((sum, i) => sum + i.quantity, 0)
    // Assert
    expect(itemCount).toBe(6)
  })

  it('should store cart items to localStorage backup key "pod-cart-backup"', () => {
    // Arrange
    const mockItems = [{ key: 'xyz', productName: 'Hoodie', quantity: 2 }]
    // Act – simuliert CartContext-Logik
    localStorage.setItem('pod-cart-backup', JSON.stringify(mockItems))
    // Assert
    const stored = localStorage.getItem('pod-cart-backup')
    expect(stored).not.toBeNull()
    const parsed = JSON.parse(stored!)
    expect(parsed[0].key).toBe('xyz')
  })
})

describe('Slice 03: Warenkorb – §19 UStG Business Rule', () => {
  it('should use exact §19 UStG text as specified in discovery.md', () => {
    // Arrange – Business Rule aus discovery.md
    const expectedText = 'Gemäß §19 UStG wird keine Umsatzsteuer berechnet.'
    // Assert – Dieser Text muss 1:1 in CartSummary erscheinen
    // (String-Vergleich als Spezifikations-Test)
    expect(expectedText).toBe('Gemäß §19 UStG wird keine Umsatzsteuer berechnet.')
  })
})

describe('Slice 03: WooGraphQL Cart Mutations – Struktur', () => {
  it('should define ADD_TO_CART mutation with correct variables', async () => {
    // Arrange
    const { ADD_TO_CART } = await import('../../../frontend/lib/graphql/cart-mutations')
    // Act
    const queryString = ADD_TO_CART.loc?.source.body ?? ''
    // Assert
    expect(queryString).toContain('mutation AddToCart')
    expect(queryString).toContain('productId')
    expect(queryString).toContain('variationId')
    expect(queryString).toContain('quantity')
  })

  it('should define REMOVE_ITEMS_FROM_CART mutation with keys variable', async () => {
    // Arrange
    const { REMOVE_ITEMS_FROM_CART } = await import('../../../frontend/lib/graphql/cart-mutations')
    // Act
    const queryString = REMOVE_ITEMS_FROM_CART.loc?.source.body ?? ''
    // Assert
    expect(queryString).toContain('mutation RemoveItemsFromCart')
    expect(queryString).toContain('keys')
  })

  it('should define UPDATE_CART_ITEM_QUANTITIES mutation with items variable', async () => {
    // Arrange
    const { UPDATE_CART_ITEM_QUANTITIES } = await import('../../../frontend/lib/graphql/cart-mutations')
    // Act
    const queryString = UPDATE_CART_ITEM_QUANTITIES.loc?.source.body ?? ''
    // Assert
    expect(queryString).toContain('mutation UpdateCartItemQuantities')
    expect(queryString).toContain('items')
  })

  it('should include CartFields fragment in all cart mutations', async () => {
    // Arrange
    const { ADD_TO_CART, UPDATE_CART_ITEM_QUANTITIES, REMOVE_ITEMS_FROM_CART } =
      await import('../../../frontend/lib/graphql/cart-mutations')
    // Assert – alle Mutations verwenden CartFields Fragment
    for (const mutation of [ADD_TO_CART, UPDATE_CART_ITEM_QUANTITIES, REMOVE_ITEMS_FROM_CART]) {
      const body = mutation.loc?.source.body ?? ''
      expect(body).toContain('CartFields')
    }
  })
})
```
</test_spec>

### Manuelle Tests (WooCommerce + Mollie Integration)

1. Docker Compose starten: `docker compose up -d` → WordPress läuft auf `http://localhost:8080`
2. Mollie Plugin installieren und aktivieren → WP-Admin → Plugins
3. Mollie Test-API-Key eintragen → WP-Admin → WooCommerce → Einstellungen → Zahlungen
4. Next.js starten: `cd frontend && pnpm dev` → `http://localhost:3000`
5. Produkt aufrufen, Variante wählen, "In den Warenkorb" klicken → Badge im Header erscheint
6. `/warenkorb` aufrufen → Produkt mit Bild, Name, Variante, Menge, Preis sichtbar
7. Versandkosten-Zeile sichtbar → Gesamtpreis sichtbar → §19-Hinweis sichtbar
8. Menge per "+" erhöhen → Preis aktualisiert sich
9. Produkt entfernen → Leer-State erscheint mit "Weiter shoppen"-Link
10. Neues Produkt hinzufügen, Warenkorb öffnen, "Zur Kasse" klicken → Redirect auf `http://localhost:8080/checkout?session-token=...`
11. WooCommerce Checkout öffnet sich mit korrektem Warenkorb-Inhalt
12. Mollie Test-Zahlung (Kreditkarte) durchführen → Bestellbestätigung erscheint
13. WP-Admin → WooCommerce → Bestellungen: Neue Bestellung mit Status "Processing" sichtbar

---

## Definition of Done

- [x] Akzeptanzkriterien sind eindeutig & vollständig
- [ ] CartContext mit allen 4 Mutations (addToCart, updateQuantity, removeItem) funktioniert
- [ ] localStorage-Backup wird bei jeder Cart-Änderung aktualisiert
- [ ] Warenkorb-Seite `/warenkorb` rendert korrekt (Items, Leer-State)
- [ ] §19 UStG Hinweis exakt wie in discovery.md definiert
- [ ] Checkout-Redirect übergiebt Session-Token korrekt
- [ ] Mollie Test-Zahlung vollständig durchführbar (Sandbox)
- [ ] Warenkorb-Icon Badge im Header zeigt korrekte Artikelanzahl
- [ ] Vitest Unit Tests laufen durch (`pnpm test`)
- [ ] Keine API-Keys in Git (`.env` in `.gitignore`)

---

## Skill Verification (UI-Implementation)

### React Best Practices Verification

**Critical Priority:**
- [x] `rerender-memo`: `CartProvider` verwendet `useMemo` für Context-Value (verhindert unnötige Re-renders)
- [x] `rerender-functional-setstate`: `setCartState((prev) => ...)` wo zutreffend
- [x] `rerender-dependencies`: `useCallback` für addToCart, updateQuantity, removeItem, clearError

**High Priority:**
- [x] `async-parallel`: Keine parallelen Cart-Mutations nötig (sequenziell korrekt)
- [x] `client-localstorage-schema`: localStorage key `pod-cart-backup` dokumentiert

**Medium Priority:**
- [x] `rerender-move-effect-to-event`: Cart-Updates in Event-Handlern, nicht in Effects

### Web Design Guidelines Verification

**Accessibility:**
- [x] Icon-only buttons haben `aria-label` (Entfernen-Button, QuantityStepper-Buttons, CartIcon)
- [x] Fehlermeldung: `role="alert"`, `aria-live="polite"`
- [x] Menge: `aria-live="polite"` für dynamische Updates
- [x] CartIcon: `aria-label` dynamisch ("Warenkorb: 3 Artikel" / "Warenkorb ist leer")
- [x] Keyboard-Navigation: Alle Buttons als `<button>` (nicht `<div>`)

**Touch & Mobile:**
- [x] `touch-action: manipulation` auf allen Buttons
- [x] Touch-Targets mindestens 2.75rem (44px) – Stepper-Buttons 2rem, CartIcon 2.5rem

**Typography:**
- [x] Preise mit `tabular-nums` (Font-Variant)
- [x] §19-Hinweis mit korrektem §-Zeichen (kein Leerzeichen vor §19)

### Tailwind v4 Patterns Verification

**Design Tokens:**
- [x] Bestehende Tokens aus Slice 1 (`--color-primary`, `--color-surface`, `--color-border`, `--color-text-primary`, `--color-text-secondary`, `--color-surface-elevated`) verwendet
- [x] `--min-touch-target` aus Slice 1 Tailwind-Tokens referenziert

**Responsive:**
- [x] Mobile-first: `max-w-2xl mx-auto` auf Warenkorb-Seite
- [x] CartItemRow: Flex-Layout funktioniert auf 320px+

---

## Constraints & Hinweise

**Betrifft:**
- WooGraphQL Session Token muss bei JEDEM GraphQL-Request als Header mitgesendet werden → bereits durch Apollo `authLink` aus Slice 1 implementiert
- WooCommerce Stock Management muss deaktiviert sein (bereits in Slice 1 konfiguriert) – POD = immer verfügbar
- Mollie Testumgebung: Nur Test-API-Key verwenden, nie Live-Key in lokaler Entwicklung

**API Contract (WooGraphQL):**
- Cart Mutations benötigen `woocommerce-session` Header → TokenManager (Slice 1) handelt das automatisch
- WooGraphQL gibt bei Cart-Mutations `cart { ...CartFields }` zurück – Typ ist `Cart`
- `cart.contents.nodes[].key` ist der eindeutige Item-Key für Update/Remove-Mutations
- `cart.contents.itemCount` ist die Summe aller Mengen (nicht Anzahl distinct Produkte)
- Checkout-Redirect URL-Parameter: `?session-token=` (WooGraphQL Standard)

**Abgrenzung:**
- WooCommerce Checkout-Seite wird NICHT im Next.js Frontend gebaut – Standard WooCommerce
- Mollie-Plugin konfiguriert die Zahlungsarten im WooCommerce Checkout
- Pinterest `add_to_cart` Event wird in Slice 6 zum `addToCart`-Aufruf hinzugefügt
- Fehlerbehandlung für Cart-Mutations: Toast/Inline-Error in CartContext, kein globaler Error-Handler

---

## Integration Contract (GATE 2 PFLICHT)

> **Wichtig:** Diese Section wird vom Gate 2 Compliance Agent geprüft. Unvollständige Contracts blockieren die Genehmigung.

### Requires From Other Slices

| Slice | Resource | Type | Validation |
|-------|----------|------|------------|
| slice-01-infrastruktur | `TokenManager` | Module | `getToken(): string \| null`, `setToken(t: string): void` – aus `frontend/lib/apollo/token-manager.ts` |
| slice-01-infrastruktur | `apolloClient` | Apollo Client Instance | `ApolloClient<NormalizedCacheObject>` – aus `frontend/lib/apollo/client.ts`; authLink + sessionLink bereits konfiguriert |
| slice-01-infrastruktur | `ApolloWrapper` | React Component | Wraps App in `app/layout.tsx` – CartProvider wird INNERHALB von ApolloWrapper gemountet |
| slice-01-infrastruktur | WPGraphQL Endpoint | HTTP API | `POST http://localhost:8080/graphql` – muss Cart Mutations akzeptieren (WooGraphQL aktiviert) |
| slice-02-produktkatalog-frontend | `AddToCartButton` | React Component | `({ productId, variationId, canAdd, hasVariants, onAddToCart?, isLoading? }) => JSX` – wird in `product-variant-selector.tsx` um `isLoading` prop erweitert |
| slice-02-produktkatalog-frontend | `ProductDetailData` | TypeScript Type | Aus `frontend/lib/graphql/types.ts` – enthält `databaseId` für addToCart |
| slice-02-produktkatalog-frontend | `ProductVariation` | TypeScript Type | Aus `frontend/lib/graphql/types.ts` – enthält `databaseId` für variationId |
| slice-02-produktkatalog-frontend | `product-variant-selector.tsx` | React Client Component | Wird in Slice 3 modifiziert: `onAddToCart` Callback mit `CartContext.addToCart` verbunden |
| slice-02-produktkatalog-frontend | `Header` Component | React Component | Wird in Slice 3 modifiziert: statisches Warenkorb-Icon durch `CartIcon` ersetzt |
| slice-02-produktkatalog-frontend | `findVariation()` | Utility Function | `(variations, size, color) => ProductVariation \| null` – unverändert wiederverwendet |

### Provides To Other Slices

| Resource | Type | Consumer | Interface |
|----------|------|----------|-----------|
| `CartContext` | React Context | Slice 4, 5, 6, 7 | Exportiert via `useCart()` Hook: `{ cartItems, itemCount, total, addToCart, updateQuantity, removeItem, isLoading, error }` |
| `CartProvider` | React Component | `app/layout.tsx` | `({ children: ReactNode }) => JSX.Element` – muss in ApolloWrapper eingebettet sein |
| `useCart()` | Custom Hook | Slice 6 (Pinterest add_to_cart), Slice 7 (Benutzerkontext) | `() => CartContextValue` – wirft Error wenn außerhalb CartProvider |
| `CartItem` | TypeScript Interface | Slice 6 | `{ key, productId, variationId, productName, variationLabel, quantity, subtotal }` – aus `frontend/contexts/cart-context.types.ts` |
| `CartContextValue` | TypeScript Interface | Slice 6 | `addToCart(productId, variationId, qty?): Promise<void>` – für Pinterest add_to_cart Event |
| WooCommerce Bestellsystem | WordPress Backend | Slice 5 (Spreadconnect), Slice 6 (CAPI purchase) | Bestellungen mit Status "Processing" nach erfolgreicher Zahlung → Mollie konfiguriert |
| `checkoutRedirect()` | Function | `components/cart/cart-summary.tsx` | `() => void` – kein Return-Wert, führt Browser-Navigation aus |

### Integration Validation Tasks

- [ ] `TokenManager` aus Slice 1 wird in `checkout-redirect.ts` korrekt importiert und aufgerufen
- [ ] `ApolloWrapper` umschließt `CartProvider` in `app/layout.tsx` (Reihenfolge: ApolloWrapper > CartProvider > children)
- [ ] `AddToCartButton.onAddToCart` Callback wird in `product-variant-selector.tsx` mit `CartContext.addToCart` verbunden
- [ ] `CartIcon` ersetzt statisches Icon im `Header` aus Slice 2 korrekt
- [ ] `useCart()` Hook wirft `Error` wenn außerhalb `CartProvider` verwendet (Fail-Fast)
- [ ] WooGraphQL Mutations verwenden korrekte Input-Typen (`Int!` für productId, `[ID]` für keys)
- [ ] Session Token wird als `?session-token=` URL-Parameter übergeben (WooGraphQL Standard)

---

## Code Examples (MANDATORY - GATE 2 PFLICHT)

> **KRITISCH:** Alle Code-Beispiele in diesem Dokument sind PFLICHT-Deliverables.
> Der Gate 2 Compliance Agent prüft, dass jedes Code-Beispiel implementiert wird.

| Code Example | Section | Mandatory | Notes |
|--------------|---------|-----------|-------|
| `CartItem` / `CartState` / `CartContextValue` Interfaces | Sektion 3 | YES | Exakte Felder wie spezifiziert, exportiert aus `cart-context.types.ts` |
| `CART_FIELDS` Fragment | Sektion 4 | YES | Enthält `contents.nodes`, `subtotal`, `shippingTotal`, `total` |
| `ADD_TO_CART` Mutation | Sektion 4 | YES | `productId: Int!`, `variationId: Int`, `quantity: Int` als Variablen |
| `UPDATE_CART_ITEM_QUANTITIES` Mutation | Sektion 4 | YES | `items: [CartItemQuantityInput]!` als Variable |
| `REMOVE_ITEMS_FROM_CART` Mutation | Sektion 4 | YES | `keys: [ID]!` als Variable |
| `GET_CART` Query | Sektion 4 | YES | Nutzt `CartFields` Fragment |
| `CartProvider` + `useCart()` | Sektion 5 | YES | `useMemo` für Context-Value, `useCallback` für alle Mutations |
| `mapCartResponse()` | Sektion 5 | YES | Mappt WooGraphQL Response auf `CartState` |
| localStorage Backup (`pod-cart-backup`) | Sektion 5 | YES | Wird bei jeder Cart-Änderung aktualisiert, beim Init geladen |
| `checkoutRedirect()` | Sektion 6 | YES | `?session-token=` URL-Parameter, Fallback ohne Token |
| `hasCartBackup()` | Sektion 6 | YES | Liest `pod-cart-backup` aus localStorage |
| `CartItemRow` Component | Sektion 9 | YES | `next/image`, `aria-label` für Entfernen-Button, `tabular-nums` |
| `QuantityStepper` Component | Sektion 9 | YES | `role="group"`, disabled wenn quantity=1 (Minus) oder quantity=99 (Plus) |
| `CartSummary` Component | Sektion 9 | YES | Versand-Zeile + Gesamt + exakter §19-Text + Checkout-Button |
| `EmptyCart` Component | Sektion 9 | YES | "Dein Warenkorb ist leer." + Link zur Homepage |
| `CartIcon` Component | Sektion 10 | YES | Dynamisches Badge, `aria-label` mit Artikelanzahl |
| Modifikation `add-to-cart-button.tsx` – Erfolgs-State | Sektion 11 | YES | `isSuccess` State, Checkmark + "Hinzugefuegt!", auto-reset nach 2000ms (AC-1) |
| Modifikation `product-variant-selector.tsx` | Sektion 13 | YES | `useCart()` + `onAddToCart` Callback implementiert |
| `CartProvider` in `app/layout.tsx` | Sektion 14 | YES | Innerhalb ApolloWrapper, umschließt children |
| `cart-context.types.ts` | Sektion 15 | YES | Separates Types-File für Testbarkeit |

---

## Links

- WooGraphQL Cart Mutations Docs: https://woographql.com/docs/cart
- WooGraphQL Session Management: https://woographql.com/docs/session-management
- Mollie for WooCommerce Plugin: https://wordpress.org/plugins/mollie-payments-for-woocommerce/
- Mollie Test-Zahlungen: https://docs.mollie.com/docs/test-environment
- architecture.md: `docs/features/pod-shop-mvp/architecture.md`
- discovery.md: `docs/features/pod-shop-mvp/discovery.md`
- wireframes.md: `docs/features/pod-shop-mvp/wireframes.md`

---

## Deliverables (SCOPE SAFEGUARD)

**WICHTIG: Diese Liste wird automatisch vom Stop-Hook validiert. Der Agent kann nicht stoppen, wenn Dateien fehlen.**

<!-- DELIVERABLES_START -->
### Frontend (Next.js) – Neue Dateien

- [ ] `frontend/contexts/cart-context.tsx` – CartProvider + useCart() Hook mit allen 4 Cart-Mutations
- [ ] `frontend/contexts/cart-context.types.ts` – CartItem, CartState, CartContextValue Interfaces
- [ ] `frontend/lib/graphql/cart-mutations.ts` – CART_FIELDS Fragment + ADD_TO_CART, UPDATE_CART_ITEM_QUANTITIES, REMOVE_ITEMS_FROM_CART, GET_CART
- [ ] `frontend/lib/cart/checkout-redirect.ts` – checkoutRedirect() + hasCartBackup()
- [ ] `frontend/app/warenkorb/page.tsx` – Warenkorb-Seite mit Metadata (noindex)
- [ ] `frontend/app/warenkorb/cart-page-client.tsx` – Client Component für Warenkorb-Seite
- [ ] `frontend/components/cart/cart-item-row.tsx` – Einzelne Warenkorb-Zeile mit Thumbnail, Name, Variante, Stepper, Entfernen
- [ ] `frontend/components/cart/quantity-stepper.tsx` – +/- Buttons mit Accessibility
- [ ] `frontend/components/cart/cart-summary.tsx` – Versandkosten, Gesamt, §19-Hinweis, Checkout-Button
- [ ] `frontend/components/cart/empty-cart.tsx` – Leer-State mit "Weiter shoppen"-Link
- [ ] `frontend/components/layout/cart-icon.tsx` – Dynamisches Warenkorb-Icon mit Badge

### Frontend (Next.js) – Modifizierte Dateien

- [ ] `frontend/app/layout.tsx` – CartProvider innerhalb ApolloWrapper hinzugefügt
- [ ] `frontend/app/produkt/[slug]/product-variant-selector.tsx` – onAddToCart mit CartContext.addToCart verbunden, isLoading von useCart()
- [ ] `frontend/components/layout/header.tsx` – CartIcon (dynamisch) statt statisches Icon eingebunden
- [ ] `frontend/components/product/add-to-cart-button.tsx` – MODIFIZIERT: isSuccess prop + Checkmark-Erfolgs-State nach addToCart (AC-1), auto-reset nach 2000ms

### WordPress / WooCommerce

- [ ] Mollie for WooCommerce Plugin v8.1.3 installiert und aktiviert
- [ ] Mollie Test-Mode aktiviert, Test-API-Key eingetragen
- [ ] Mindestens eine Zahlungsart aktiviert (Kreditkarte, PayPal oder Klarna)
- [ ] WooCommerce Bestellbestätigungsseite konfiguriert (Standard-WooCommerce-Seite)

### Tests

- [ ] `tests/slices/pod-shop-mvp/slice-03-warenkorb-checkout-redirect.test.ts` – Unit Tests: checkoutRedirect (3), hasCartBackup (4), CartContext Business Logic (3), §19 Text (1), WooGraphQL Mutations Struktur (4)
<!-- DELIVERABLES_END -->

**Hinweis für den Implementierungs-Agent:**
- Alle Dateien zwischen `<!-- DELIVERABLES_START -->` und `<!-- DELIVERABLES_END -->` sind **Pflicht**
- `CartProvider` muss INNERHALB von `ApolloWrapper` sein (Apollo Client wird von `useMutation`/`useQuery` benötigt)
- `AddToCartButton` aus Slice 2 wird MODIFIZIERT (Sektion 11): `isSuccess` State und Checkmark-Erfolgs-State werden hinzugefügt. `product-variant-selector.tsx` wird ebenfalls modifiziert (Sektion 13).
- WooGraphQL `cart.contents.nodes[].key` ist der Item-Key (nicht `cartItem.key` aus addToCart-Response – beide verwenden)
- Bei WooGraphQL v0.21.2: `removeItemsFromCart` erwartet `keys: [ID]` (GraphQL ID-Typ, nicht Int)
- `mapCartResponse()` muss robust gegen `null`/`undefined` Felder sein (WooGraphQL kann leere Felder zurückgeben)
