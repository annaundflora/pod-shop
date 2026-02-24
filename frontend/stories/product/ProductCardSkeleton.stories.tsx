// frontend/stories/product/ProductCardSkeleton.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { ProductCardSkeleton } from '@/components/product/product-card-skeleton'

const meta: Meta<typeof ProductCardSkeleton> = {
  title: 'Product/Product Card Skeleton',
  component: ProductCardSkeleton,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof ProductCardSkeleton>

export const Default: Story = {}

export const Grid: Story = {
  render: () => (
    <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      {Array.from({ length: 4 }).map((_, i) => (
        <ProductCardSkeleton key={i} />
      ))}
    </div>
  ),
}
