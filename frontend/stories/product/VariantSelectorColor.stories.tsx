// frontend/stories/product/VariantSelectorColor.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { VariantSelectorColor } from '@/components/product/variant-selector-color'

const mockColors = [
  { value: 'Schwarz', hexCode: '#1a1a1a', available: true },
  { value: 'Weiss', hexCode: '#ffffff', available: true },
  { value: 'Rot', hexCode: '#dc2626', available: true },
  { value: 'Blau', hexCode: '#2563eb', available: true },
  { value: 'Gruen', hexCode: '#16a34a', available: false },
]

const meta: Meta<typeof VariantSelectorColor> = {
  title: 'Product/Variant Selector Color',
  component: VariantSelectorColor,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof VariantSelectorColor>

export const Default: Story = {
  args: {
    colors: mockColors,
    selectedColor: null,
    onColorSelect: () => {},
  },
}

export const Selected: Story = {
  args: {
    colors: mockColors,
    selectedColor: 'Rot',
    onColorSelect: () => {},
  },
}

export const WithUnavailable: Story = {
  args: {
    colors: mockColors,
    selectedColor: 'Blau',
    onColorSelect: () => {},
  },
}
