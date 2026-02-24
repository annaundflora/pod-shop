// frontend/stories/product/ProductCard.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { ProductCard } from '@/components/product/product-card'
import type { ProductCardData } from '@/lib/graphql/types'

const mockProduct: ProductCardData = {
  id: 'product-1',
  slug: 'muster-t-shirt',
  name: 'Muster T-Shirt mit einzigartigem Design',
  price: '29,99 €',
  regularPrice: '29,99 €',
  onSale: false,
  stockStatus: 'IN_STOCK',
  image: {
    sourceUrl: 'https://placehold.co/400x400/e2e8f0/64748b?text=T-Shirt',
    altText: 'Muster T-Shirt',
    mediaDetails: { width: 400, height: 400 },
  },
  productCategories: {
    nodes: [{ id: '1', slug: 't-shirts', name: 'T-Shirts' }],
  },
}

const meta: Meta<typeof ProductCard> = {
  title: 'Product/Product Card',
  component: ProductCard,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof ProductCard>

export const Default: Story = {
  args: {
    product: mockProduct,
  },
}

export const SoldOut: Story = {
  args: {
    product: {
      ...mockProduct,
      stockStatus: 'OUT_OF_STOCK',
    },
  },
}

export const NoImage: Story = {
  args: {
    product: {
      ...mockProduct,
      image: null,
    },
  },
}

export const OnSale: Story = {
  args: {
    product: {
      ...mockProduct,
      name: 'Sale T-Shirt',
      price: '19,99 €',
      regularPrice: '29,99 €',
      onSale: true,
    },
  },
}

export const LongTitle: Story = {
  args: {
    product: {
      ...mockProduct,
      name: 'Sehr langer Produktname der moeglicherweise zwei Zeilen benoetigt und gekuerzt werden sollte',
    },
  },
}
