// frontend/stories/blocks/ProductGridBlock.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { ProductGridBlock, ProductGridBlockSkeleton } from '@/components/blocks/product-grid-block'
import type { ProductCardData } from '@/lib/graphql/types'

const mockProducts: ProductCardData[] = [
  {
    id: 'p1',
    slug: 'muster-t-shirt-schwarz',
    name: 'Muster T-Shirt Schwarz',
    price: '29,99 €',
    regularPrice: '29,99 €',
    onSale: false,
    stockStatus: 'IN_STOCK',
    image: {
      sourceUrl: 'https://placehold.co/400x400/1a1a1a/ffffff?text=T-Shirt',
      altText: 'T-Shirt Schwarz',
      mediaDetails: { width: 400, height: 400 },
    },
    productCategories: { nodes: [{ id: '1', slug: 't-shirts', name: 'T-Shirts' }] },
  },
  {
    id: 'p2',
    slug: 'muster-hoodie-grau',
    name: 'Muster Hoodie Grau',
    price: '49,99 €',
    regularPrice: '49,99 €',
    onSale: false,
    stockStatus: 'IN_STOCK',
    image: {
      sourceUrl: 'https://placehold.co/400x400/9ca3af/ffffff?text=Hoodie',
      altText: 'Hoodie Grau',
      mediaDetails: { width: 400, height: 400 },
    },
    productCategories: { nodes: [{ id: '2', slug: 'hoodies', name: 'Hoodies' }] },
  },
  {
    id: 'p3',
    slug: 'muster-tasse-weiss',
    name: 'Muster Tasse Weiss',
    price: '14,99 €',
    regularPrice: '14,99 €',
    onSale: false,
    stockStatus: 'IN_STOCK',
    image: {
      sourceUrl: 'https://placehold.co/400x400/f3f4f6/374151?text=Tasse',
      altText: 'Tasse Weiss',
      mediaDetails: { width: 400, height: 400 },
    },
    productCategories: { nodes: [{ id: '3', slug: 'tassen', name: 'Tassen' }] },
  },
  {
    id: 'p4',
    slug: 'muster-pullover-blau',
    name: 'Muster Pullover Blau',
    price: '39,99 €',
    regularPrice: '49,99 €',
    onSale: true,
    stockStatus: 'IN_STOCK',
    image: {
      sourceUrl: 'https://placehold.co/400x400/2563eb/ffffff?text=Pullover',
      altText: 'Pullover Blau',
      mediaDetails: { width: 400, height: 400 },
    },
    productCategories: { nodes: [{ id: '4', slug: 'pullover', name: 'Pullover' }] },
  },
]

const meta: Meta<typeof ProductGridBlock> = {
  title: 'Blocks/Product Grid Block',
  component: ProductGridBlock,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof ProductGridBlock>

export const Loaded: Story = {
  args: {
    data: {
      products: {
        nodes: mockProducts,
      },
    },
  },
}

export const Loading: Story = {
  render: () => <ProductGridBlockSkeleton count={4} />,
}

export const Empty: Story = {
  args: {
    data: {
      products: {
        nodes: [],
      },
    },
  },
}
