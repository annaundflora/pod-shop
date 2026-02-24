// frontend/stories/cart/CartSummary.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import React from 'react'
import { CartSummary } from '@/components/cart/cart-summary'
import { CartContext } from '@/contexts/cart-context'
import type { CartContextValue } from '@/contexts/cart-context.types'

const mockCartContextBase: CartContextValue = {
  cartItems: [
    {
      key: 'item-1',
      productId: 123,
      variationId: null,
      productName: 'Muster T-Shirt',
      variationLabel: 'Groesse: M',
      thumbnail: null,
      quantity: 1,
      subtotal: '29,99 €',
      subtotalRaw: 29.99,
      price: '29,99 €',
    },
  ],
  subtotal: '29,99 €',
  shippingTotal: 'Kostenlos',
  total: '29,99 €',
  itemCount: 1,
  isLoading: false,
  error: null,
  addToCart: async () => {},
  updateQuantity: async () => {},
  removeItem: async () => {},
  clearError: () => {},
}

function withCartContext(contextValue: CartContextValue) {
  return function Decorator(Story: React.ComponentType) {
    return (
      <CartContext.Provider value={contextValue}>
        <Story />
      </CartContext.Provider>
    )
  }
}

const meta: Meta<typeof CartSummary> = {
  title: 'Cart/Cart Summary',
  component: CartSummary,
  tags: ['autodocs'],
  decorators: [withCartContext(mockCartContextBase)],
}

export default meta
type Story = StoryObj<typeof CartSummary>

export const WithItems: Story = {}

export const Empty: Story = {
  decorators: [
    withCartContext({
      ...mockCartContextBase,
      cartItems: [],
      subtotal: '0,00 €',
      total: '0,00 €',
      itemCount: 0,
    }),
  ],
}

export const Loading: Story = {
  decorators: [
    withCartContext({
      ...mockCartContextBase,
      isLoading: true,
    }),
  ],
}
