// frontend/stories/blocks/HeroBlock.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { HeroBlock, HeroBlockSkeleton } from '@/components/blocks/hero-block'

const meta: Meta<typeof HeroBlock> = {
  title: 'Blocks/Hero Block',
  component: HeroBlock,
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj<typeof HeroBlock>

export const Loaded: Story = {
  args: {
    data: {
      heroHeadline: 'Einzigartige Designs auf Premium-Produkten',
      heroSubline: 'Print-on-Demand – jedes Stueck wird eigens fuer dich produziert.',
      heroCtaText: 'Jetzt shoppen',
      heroCtaLink: '/kategorie/t-shirts',
      heroBackgroundImage: null,
    },
  },
}

export const WithBackgroundImage: Story = {
  args: {
    data: {
      heroHeadline: 'Sommerkollektion 2026',
      heroSubline: 'Neue Designs jetzt entdecken.',
      heroCtaText: 'Kollektion ansehen',
      heroCtaLink: '/kategorie/sommer',
      heroBackgroundImage: 'https://placehold.co/1280x400/e2e8f0/64748b?text=Hero+Bild',
    },
  },
}

export const Loading: Story = {
  render: () => <HeroBlockSkeleton />,
}

export const WithDefaults: Story = {
  args: {
    data: null,
  },
}
