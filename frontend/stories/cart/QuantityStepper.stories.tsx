// frontend/stories/cart/QuantityStepper.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import React from 'react'
import { QuantityStepper } from '@/components/cart/quantity-stepper'
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

const meta: Meta<typeof QuantityStepper> = {
  title: 'Cart/Quantity Stepper',
  component: QuantityStepper,
  tags: ['autodocs'],
  decorators: [withCartContext(mockContextBase)],
}

export default meta
type Story = StoryObj<typeof QuantityStepper>

export const Default: Story = {
  args: {
    itemKey: 'item-1',
    quantity: 1,
    disabled: false,
  },
}

export const MiddleQuantity: Story = {
  args: {
    itemKey: 'item-1',
    quantity: 5,
    disabled: false,
  },
}

export const MaxQuantity: Story = {
  args: {
    itemKey: 'item-1',
    quantity: 99,
    disabled: false,
  },
}

export const Disabled: Story = {
  args: {
    itemKey: 'item-1',
    quantity: 2,
    disabled: true,
  },
}
