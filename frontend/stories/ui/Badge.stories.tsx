// frontend/stories/ui/Badge.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { Badge } from '@/components/ui/badge'

const meta: Meta<typeof Badge> = {
  title: 'UI Primitives/Badge',
  component: Badge,
  tags: ['autodocs'],
  argTypes: {
    variant: {
      control: 'select',
      options: ['default', 'secondary', 'destructive', 'outline'],
    },
  },
}

export default meta
type Story = StoryObj<typeof Badge>

export const Default: Story = {
  args: {
    children: '3',
    variant: 'default',
  },
}

export const Secondary: Story = {
  args: {
    children: 'Neu',
    variant: 'secondary',
  },
}

export const Destructive: Story = {
  args: {
    children: 'Ausverkauft',
    variant: 'destructive',
  },
}

export const Outline: Story = {
  args: {
    children: 'Sale',
    variant: 'outline',
  },
}

export const CartCounter: Story = {
  args: {
    children: '12',
    variant: 'default',
    className: 'absolute -top-1 -right-1 min-w-[1.125rem] h-[1.125rem] px-1 tabular-nums leading-none',
  },
  render: (args) => (
    <div className="relative inline-flex">
      <div className="w-10 h-10 bg-surface-elevated rounded-lg flex items-center justify-center">
        <svg aria-hidden="true" width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" strokeWidth="1.5">
          <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z" />
          <line x1="3" y1="6" x2="21" y2="6" />
          <path d="M16 10a4 4 0 01-8 0" />
        </svg>
      </div>
      <Badge {...args} />
    </div>
  ),
}
