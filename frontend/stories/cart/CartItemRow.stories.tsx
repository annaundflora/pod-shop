// frontend/stories/cart/CartItemRow.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import React from 'react'
import { CartItemRow } from '@/components/cart/cart-item-row'
import { CartContext } from '@/contexts/cart-context'
import type { CartContextValue } from '@/contexts/cart-context.types'
import type { CartItem } from '@/contexts/cart-context.types'

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

const mockItem: CartItem = {
  key: 'item-1',
  productId: 123,
  variationId: 456,
  productName: 'Muster T-Shirt',
  variationLabel: 'Groesse: M, Farbe: Schwarz',
  thumbnail: 'https://placehold.co/80x80/e2e8f0/64748b?text=T',
  quantity: 1,
  subtotal: '29,99 €',
  subtotalRaw: 29.99,
  price: '29,99 €',
}

const meta: Meta<typeof CartItemRow> = {
  title: 'Cart/Cart Item Row',
  component: CartItemRow,
  tags: ['autodocs'],
  decorators: [withCartContext(mockContextBase)],
}

export default meta
type Story = StoryObj<typeof CartItemRow>

export const Default: Story = {
  args: {
    item: mockItem,
    isLoading: false,
  },
}

export const WithMultipleQuantity: Story = {
  args: {
    item: {
      ...mockItem,
      quantity: 3,
      subtotal: '89,97 €',
      subtotalRaw: 89.97,
    },
    isLoading: false,
  },
}

export const Loading: Story = {
  args: {
    item: mockItem,
    isLoading: true,
  },
  decorators: [withCartContext({ ...mockContextBase, isLoading: true })],
}

export const NoThumbnail: Story = {
  args: {
    item: {
      ...mockItem,
      thumbnail: null,
    },
    isLoading: false,
  },
}

export const NoVariationLabel: Story = {
  args: {
    item: {
      ...mockItem,
      variationLabel: '',
    },
    isLoading: false,
  },
}
