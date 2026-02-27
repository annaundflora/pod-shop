// frontend/stories/blocks/CategoryShowcaseBlock.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import {
  CategoryShowcaseBlock,
  CategoryShowcaseBlockSkeleton,
} from '@/components/blocks/category-showcase-block'
import type { ProductCategory } from '@/lib/graphql/types'

const mockCategories: ProductCategory[] = [
  {
    id: '1',
    slug: 't-shirts',
    name: 'T-Shirts',
    count: 12,
    image: {
      sourceUrl: 'https://placehold.co/48x48/e2e8f0/64748b?text=T',
      altText: 'T-Shirts Kategorie',
      mediaDetails: { width: 48, height: 48 },
    },
  },
  {
    id: '2',
    slug: 'hoodies',
    name: 'Hoodies',
    count: 8,
    image: {
      sourceUrl: 'https://placehold.co/48x48/dbeafe/3b82f6?text=H',
      altText: 'Hoodies Kategorie',
      mediaDetails: { width: 48, height: 48 },
    },
  },
  {
    id: '3',
    slug: 'tassen',
    name: 'Tassen',
    count: 5,
    image: null,
  },
]

const meta: Meta<typeof CategoryShowcaseBlock> = {
  title: 'Blocks/Category Showcase Block',
  component: CategoryShowcaseBlock,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof CategoryShowcaseBlock>

export const Loaded: Story = {
  args: {
    data: {
      nodes: mockCategories,
    },
  },
}

export const Loading: Story = {
  render: () => <CategoryShowcaseBlockSkeleton count={3} />,
}

export const Empty: Story = {
  args: {
    data: {
      nodes: [],
    },
  },
}
