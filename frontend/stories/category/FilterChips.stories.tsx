// frontend/stories/category/FilterChips.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { FilterChips } from '@/components/category/filter-chips'
import type { ProductCategory } from '@/lib/graphql/types'

const mockCategories: ProductCategory[] = [
  { id: '1', slug: 't-shirts', name: 'T-Shirts', count: 12 },
  { id: '2', slug: 'pullover', name: 'Pullover', count: 8 },
  { id: '3', slug: 'tassen', name: 'Tassen', count: 5 },
  { id: '4', slug: 'hoodies', name: 'Hoodies', count: 6 },
]

const meta: Meta<typeof FilterChips> = {
  title: 'Category/Filter Chips',
  component: FilterChips,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof FilterChips>

export const AllActive: Story = {
  args: {
    categories: mockCategories,
    currentSlug: 'alle',
  },
}

export const CategoryActive: Story = {
  args: {
    categories: mockCategories,
    currentSlug: 't-shirts',
  },
}

export const NoneActive: Story = {
  args: {
    categories: mockCategories,
    currentSlug: 'nicht-vorhanden',
  },
}

export const MixedActive: Story = {
  args: {
    categories: mockCategories,
    currentSlug: 'pullover',
  },
}
