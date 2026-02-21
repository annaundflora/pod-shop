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

export interface ProductAttributeInput {
  attributeName: string
  attributeValue: string
}

export interface CartContextValue extends CartState {
  addToCart: (productId: number, variationId: number | null, quantity?: number, variationAttributes?: ProductAttributeInput[]) => Promise<void>
  updateQuantity: (key: string, quantity: number) => Promise<void>
  removeItem: (key: string) => Promise<void>
  clearError: () => void
}
