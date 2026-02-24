// frontend/stories/layout/MobileMenu.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { MobileMenu } from '@/components/layout/mobile-menu'

const mockCategories = [
  { id: '1', slug: 't-shirts', name: 'T-Shirts', count: 12 },
  { id: '2', slug: 'pullover', name: 'Pullover', count: 8 },
  { id: '3', slug: 'tassen', name: 'Tassen', count: 5 },
  { id: '4', slug: 'hoodies', name: 'Hoodies', count: 6 },
]

const meta: Meta<typeof MobileMenu> = {
  title: 'Layout/Mobile Menu',
  component: MobileMenu,
  tags: ['autodocs'],
  args: {
    categories: mockCategories,
  },
}

export default meta
type Story = StoryObj<typeof MobileMenu>

export const Closed: Story = {}

export const WithCategories: Story = {
  args: {
    categories: mockCategories,
  },
}

export const Empty: Story = {
  args: {
    categories: [],
  },
}
