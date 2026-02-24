// frontend/stories/product/ImageGallery.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { ProductImageGallery } from '@/components/product/product-image-gallery'
import type { ProductImage } from '@/lib/graphql/types'

const mockImages: ProductImage[] = [
  {
    sourceUrl: 'https://placehold.co/600x600/e2e8f0/64748b?text=Bild+1',
    altText: 'Produkt Hauptbild',
    mediaDetails: { width: 600, height: 600 },
  },
  {
    sourceUrl: 'https://placehold.co/600x600/dbeafe/3b82f6?text=Bild+2',
    altText: 'Produkt Ansicht 2',
    mediaDetails: { width: 600, height: 600 },
  },
  {
    sourceUrl: 'https://placehold.co/600x600/dcfce7/16a34a?text=Bild+3',
    altText: 'Produkt Ansicht 3',
    mediaDetails: { width: 600, height: 600 },
  },
]

const meta: Meta<typeof ProductImageGallery> = {
  title: 'Product/Image Gallery',
  component: ProductImageGallery,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof ProductImageGallery>

export const Default: Story = {
  args: {
    images: mockImages,
    productName: 'Muster T-Shirt',
  },
}

export const SingleImage: Story = {
  args: {
    images: [mockImages[0]],
    productName: 'Muster T-Shirt',
  },
}

export const NoImages: Story = {
  args: {
    images: [],
    productName: 'Muster T-Shirt',
  },
}
