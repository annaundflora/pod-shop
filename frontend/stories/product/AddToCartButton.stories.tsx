// frontend/stories/product/AddToCartButton.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { AddToCartButton } from '@/components/product/add-to-cart-button'

const meta: Meta<typeof AddToCartButton> = {
  title: 'Product/Add to Cart Button',
  component: AddToCartButton,
  tags: ['autodocs'],
  argTypes: {
    canAdd: { control: 'boolean' },
    hasVariants: { control: 'boolean' },
    isLoading: { control: 'boolean' },
  },
}

export default meta
type Story = StoryObj<typeof AddToCartButton>

export const Default: Story = {
  args: {
    productId: 1,
    variationId: null,
    canAdd: true,
    hasVariants: false,
    isLoading: false,
    onAddToCart: async () => {},
  },
}

export const Loading: Story = {
  args: {
    productId: 1,
    variationId: null,
    canAdd: true,
    hasVariants: false,
    isLoading: true,
    onAddToCart: async () => {},
  },
}

export const Disabled: Story = {
  args: {
    productId: 1,
    variationId: null,
    canAdd: false,
    hasVariants: false,
    isLoading: false,
    onAddToCart: async () => {},
  },
}

export const VariantWaehlen: Story = {
  args: {
    productId: 1,
    variationId: null,
    canAdd: false,
    hasVariants: true,
    isLoading: false,
    onAddToCart: async () => {},
  },
}
