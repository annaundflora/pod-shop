// frontend/stories/cart/EmptyCart.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import React from 'react'
import { EmptyCart } from '@/components/cart/empty-cart'
import { CartContext } from '@/contexts/cart-context'
import type { CartContextValue } from '@/contexts/cart-context.types'

const mockContextBase: CartContextValue = {
  cartItems: [],
  subtotal: '0',
  shippingTotal: '0',
  total: '0',
  itemCount: 0,
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

const meta: Meta<typeof EmptyCart> = {
  title: 'Cart/Empty Cart',
  component: EmptyCart,
  tags: ['autodocs'],
  decorators: [withCartContext(mockContextBase)],
}

export default meta
type Story = StoryObj<typeof EmptyCart>

export const Default: Story = {}
