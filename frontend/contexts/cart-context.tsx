'use client'

import { createContext, useContext, useState, useEffect, useCallback, useMemo, type ReactNode } from 'react'
import { useMutation, useQuery } from '@apollo/client/react'
import {
  ADD_TO_CART,
  UPDATE_CART_ITEM_QUANTITIES,
  REMOVE_ITEMS_FROM_CART,
  GET_CART,
} from '@/lib/graphql/cart-mutations'
import { fireAddToCart } from '@/lib/tracking/pinterest-tag'
import { generateEventId, storeLastEventId } from '@/lib/tracking/event-id'
import type { CartItem, CartState, CartContextValue, ProductAttributeInput } from './cart-context.types'

const CART_BACKUP_KEY = 'pod-cart-backup'

const CartContext = createContext<CartContextValue | null>(null)

function mapCartResponse(cart: unknown): Omit<CartState, 'isLoading' | 'error'> {
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
      variationId: ((variation?.node as Record<string, unknown>)?.databaseId as number | null) ?? null,
      productName: product?.name as string,
      variationLabel,
      thumbnail: ((product?.image as Record<string, unknown>)?.sourceUrl as string) ?? null,
      quantity: n.quantity as number,
      subtotal: n.subtotal as string,
      subtotalRaw: 0,
      price: (product?.price as string) ?? '',
    }
  })

  return {
    cartItems,
    subtotal: (c?.subtotal as string) ?? '0',
    shippingTotal: (c?.shippingTotal as string) ?? '0',
    total: (c?.total as string) ?? '0',
    itemCount: (contents?.itemCount as number) ?? 0,
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

  const { data: cartData } = useQuery<{ cart: unknown }>(GET_CART, {
    fetchPolicy: 'cache-and-network',
  })

  // Apollo Client v4: onCompleted in useQuery entfernt (Breaking Change v3->v4).
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
            setCartState((prev) => ({
              ...prev,
              cartItems: parsed,
              itemCount: parsed.reduce((sum, i) => sum + i.quantity, 0),
            }))
          }
        }
      } catch {
        // Silent fail - localStorage nicht verfuegbar
      }
    }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const [addToCartMutation] = useMutation<{ addToCart: { cart: unknown } }>(ADD_TO_CART)
  const [updateQuantityMutation] = useMutation<{ updateCartItemQuantities: { cart: unknown } }>(UPDATE_CART_ITEM_QUANTITIES)
  const [removeItemMutation] = useMutation<{ removeItemsFromCart: { cart: unknown } }>(REMOVE_ITEMS_FROM_CART)

  const addToCart = useCallback(async (productId: number, variationId: number | null, quantity = 1, variationAttributes?: ProductAttributeInput[]) => {
    setIsLoading(true)
    setError(null)
    try {
      const { data } = await addToCartMutation({
        variables: {
          productId,
          variationId,
          quantity,
          ...(variationAttributes && variationAttributes.length > 0 ? { variation: variationAttributes } : {}),
        },
      })
      if (data?.addToCart?.cart) {
        const mapped = mapCartResponse(data.addToCart.cart)
        setCartState(mapped)
        localStorage.setItem(CART_BACKUP_KEY, JSON.stringify(mapped.cartItems))

        // Pinterest Tag: add_to_cart Event (NUR nach Consent, handled in fireAddToCart)
        const eventId = generateEventId()
        storeLastEventId(eventId)
        const addedItem = (data.addToCart as Record<string, unknown>).cartItem as Record<string, unknown> | undefined
        if (addedItem) {
          // Rohwert aus WooGraphQL subtotal (formatierter String wie "29.99")
          const rawValue = parseFloat(
            mapped.cartItems
              .find((i) => i.key === (addedItem.key as string))
              ?.subtotal?.replace(/[^0-9.,]/g, '')
              .replace(',', '.') ?? '0'
          )
          fireAddToCart({
            event_id: eventId,
            value: rawValue,
            quantity: addedItem.quantity as number,
            order_quantity: addedItem.quantity as number,
            currency: 'EUR',
          })
        } else {
          // Kein spezifisches cartItem: trotzdem event_id speichern für Checkout-Redirect
          fireAddToCart({
            event_id: eventId,
            value: parseFloat(
              mapped.cartItems[mapped.cartItems.length - 1]?.subtotal?.replace(/[^0-9.,]/g, '').replace(',', '.') ?? '0'
            ),
            quantity: quantity,
            order_quantity: quantity,
            currency: 'EUR',
          })
        }
      }
    } catch {
      setError('Produkt konnte nicht hinzugefuegt werden.')
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

  const value = useMemo<CartContextValue>(
    () => ({
      ...cartState,
      isLoading,
      error,
      addToCart,
      updateQuantity,
      removeItem,
      clearError,
    }),
    [cartState, isLoading, error, addToCart, updateQuantity, removeItem, clearError],
  )

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>
}

export function useCart(): CartContextValue {
  const ctx = useContext(CartContext)
  if (!ctx) throw new Error('useCart must be used within CartProvider')
  return ctx
}
