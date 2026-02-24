// frontend/stories/product/VariantSelectorSize.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { VariantSelectorSize } from '@/components/product/variant-selector-size'

const allSizes = [
  { value: 'XS', available: true },
  { value: 'S', available: true },
  { value: 'M', available: true },
  { value: 'L', available: true },
  { value: 'XL', available: true },
  { value: 'XXL', available: false },
]

const meta: Meta<typeof VariantSelectorSize> = {
  title: 'Product/Variant Selector Size',
  component: VariantSelectorSize,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof VariantSelectorSize>

export const Unselected: Story = {
  args: {
    sizes: allSizes,
    selectedSize: null,
    onSizeSelect: () => {},
  },
}

export const Selected: Story = {
  args: {
    sizes: allSizes,
    selectedSize: 'M',
    onSizeSelect: () => {},
  },
}

export const WithUnavailable: Story = {
  args: {
    sizes: allSizes,
    selectedSize: 'L',
    onSizeSelect: () => {},
  },
}

export const AllUnavailable: Story = {
  args: {
    sizes: allSizes.map((s) => ({ ...s, available: false })),
    selectedSize: null,
    onSizeSelect: () => {},
  },
}
