// frontend/stories/layout/CartIcon.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import React from 'react'
import { CartIcon } from '@/components/layout/cart-icon'
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

function withCartContext(itemCount: number) {
  return function Decorator(Story: React.ComponentType) {
    return (
      <CartContext.Provider value={{ ...mockContextBase, itemCount }}>
        <Story />
      </CartContext.Provider>
    )
  }
}

const meta: Meta<typeof CartIcon> = {
  title: 'Layout/Cart Icon',
  component: CartIcon,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof CartIcon>

export const WithoutBadge: Story = {
  decorators: [withCartContext(0)],
}

export const WithBadge: Story = {
  decorators: [withCartContext(3)],
}

export const WithManyItems: Story = {
  decorators: [withCartContext(99)],
}

export const WithTooManyItems: Story = {
  decorators: [withCartContext(100)],
}
