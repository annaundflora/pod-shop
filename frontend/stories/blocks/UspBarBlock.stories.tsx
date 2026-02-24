// frontend/stories/blocks/UspBarBlock.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { UspBarBlock } from '@/components/blocks/usp-bar-block'

const mockUsps = [
  { icon: 'truck', text: 'Kostenloser Versand ab 50 €' },
  { icon: 'shield', text: '30 Tage Rueckgaberecht' },
  { icon: 'refresh', text: 'Nachhaltige Produktion' },
  { icon: 'star', text: 'Premium Qualitaet' },
]

const meta: Meta<typeof UspBarBlock> = {
  title: 'Blocks/USP Bar Block',
  component: UspBarBlock,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof UspBarBlock>

export const Default: Story = {
  args: {
    data: {
      items: mockUsps,
    },
  },
}

export const Minimal: Story = {
  args: {
    data: {
      items: [
        { icon: 'truck', text: 'Gratis Versand' },
        { icon: 'shield', text: 'Sicher kaufen' },
      ],
    },
  },
}

export const Empty: Story = {
  args: {
    data: {
      items: [],
    },
  },
}
