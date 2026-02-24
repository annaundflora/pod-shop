// frontend/stories/ui/Skeleton.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { Skeleton } from '@/components/ui/skeleton'

const meta: Meta<typeof Skeleton> = {
  title: 'UI Primitives/Skeleton',
  component: Skeleton,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof Skeleton>

export const TextLine: Story = {
  args: {
    className: 'h-4 w-3/4',
  },
}

export const Heading: Story = {
  args: {
    className: 'h-8 w-1/2',
  },
}

export const CardSkeleton: Story = {
  render: () => (
    <div className="max-w-xs border border-border rounded-[var(--radius-card)] overflow-hidden bg-surface">
      <Skeleton className="aspect-square w-full rounded-none" />
      <div className="p-3 space-y-2">
        <Skeleton className="h-4 w-3/4" />
        <Skeleton className="h-4 w-1/3" />
      </div>
    </div>
  ),
}

export const TextBlock: Story = {
  render: () => (
    <div className="space-y-2 max-w-sm">
      <Skeleton className="h-4 w-full" />
      <Skeleton className="h-4 w-5/6" />
      <Skeleton className="h-4 w-4/6" />
    </div>
  ),
}

export const HeroSkeleton: Story = {
  render: () => (
    <div className="bg-surface-elevated rounded-2xl p-8 max-w-lg space-y-4">
      <Skeleton className="h-4 w-24" />
      <Skeleton className="h-10 w-3/4" />
      <Skeleton className="h-4 w-full" />
      <Skeleton className="h-12 w-36" />
    </div>
  ),
}
