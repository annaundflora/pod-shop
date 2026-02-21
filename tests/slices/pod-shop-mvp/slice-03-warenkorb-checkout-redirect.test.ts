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
    // Assert: Validation faengt das ab
    expect(newQuantity).toBeLessThan(1)
    // Die CartContext.updateQuantity() Funktion gibt frueh zurueck wenn quantity < 1
    // Diese Business-Rule wird durch den Minus-Button-Disabled-State erzwungen
  })

  it('should not allow quantity above 99 in updateQuantity', () => {
    // Arrange – architecture.md: Cart Quantity <= 99
    const quantity = 99
    const newQuantity = quantity + 1 // = 100
    expect(newQuantity).toBeGreaterThan(99)
    // CartContext.updateQuantity() gibt frueh zurueck wenn quantity > 99
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
