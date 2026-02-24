// frontend/stories/layout/Header.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import Link from 'next/link'
import { CartIcon } from '@/components/layout/cart-icon'
import { MobileMenu } from '@/components/layout/mobile-menu'
import { CartContext } from '@/contexts/cart-context'
import type { CartContextValue } from '@/contexts/cart-context.types'
import React from 'react'

// Header uses Apollo/GraphQL for categories, so we render a static mock version for Storybook
function HeaderMock({ itemCount = 0 }: { itemCount?: number }) {
  const mockContext: CartContextValue = {
    cartItems: [],
    subtotal: '0',
    shippingTotal: '0',
    total: '0',
    itemCount,
    isLoading: false,
    error: null,
    addToCart: async () => {},
    updateQuantity: async () => {},
    removeItem: async () => {},
    clearError: () => {},
  }

  const categories = [
    { id: '1', slug: 't-shirts', name: 'T-Shirts', count: 12 },
    { id: '2', slug: 'pullover', name: 'Pullover', count: 8 },
    { id: '3', slug: 'tassen', name: 'Tassen', count: 5 },
  ]

  return (
    <CartContext.Provider value={mockContext}>
      <header className="sticky top-0 z-40 bg-surface border-b border-border">
        <div className="max-w-7xl mx-auto px-4 h-14 flex items-center justify-between">
          <div className="flex lg:hidden">
            <MobileMenu categories={categories} />
          </div>

          <Link href="/" className="font-bold text-lg text-text-primary">
            POD Shop
          </Link>

          <nav aria-label="Hauptnavigation" className="hidden lg:flex items-center gap-6">
            {categories.map((category) => (
              <Link
                key={category.id}
                href={`/kategorie/${category.slug}`}
                className="text-sm text-text-secondary hover:text-text-primary transition-colors"
              >
                {category.name}
              </Link>
            ))}
          </nav>

          <div className="flex items-center gap-3">
            <CartIcon />
          </div>
        </div>
      </header>
    </CartContext.Provider>
  )
}

const meta: Meta<typeof HeaderMock> = {
  title: 'Layout/Header',
  component: HeaderMock,
  tags: ['autodocs'],
  parameters: {
    layout: 'fullscreen',
  },
}

export default meta
type Story = StoryObj<typeof HeaderMock>

export const Default: Story = {
  args: {
    itemCount: 0,
  },
}

export const WithCartItems: Story = {
  args: {
    itemCount: 3,
  },
}
