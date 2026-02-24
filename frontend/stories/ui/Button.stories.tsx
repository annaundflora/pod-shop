// frontend/stories/ui/Button.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { Button } from '@/components/ui/button'

const meta: Meta<typeof Button> = {
  title: 'UI Primitives/Button',
  component: Button,
  tags: ['autodocs'],
  argTypes: {
    variant: {
      control: 'select',
      options: ['default', 'destructive', 'outline', 'ghost', 'link', 'secondary'],
    },
    size: {
      control: 'select',
      options: ['default', 'sm', 'lg', 'icon'],
    },
    isLoading: { control: 'boolean' },
    disabled: { control: 'boolean' },
  },
}

export default meta
type Story = StoryObj<typeof Button>

export const Default: Story = {
  args: {
    children: 'In den Warenkorb',
    variant: 'default',
    size: 'default',
  },
}

export const Destructive: Story = {
  args: {
    children: 'Entfernen',
    variant: 'destructive',
    size: 'default',
  },
}

export const Outline: Story = {
  args: {
    children: 'Groesse L',
    variant: 'outline',
    size: 'default',
  },
}

export const Ghost: Story = {
  args: {
    children: 'Abbrechen',
    variant: 'ghost',
    size: 'default',
  },
}

export const Link: Story = {
  args: {
    children: 'Mehr anzeigen',
    variant: 'link',
    size: 'default',
  },
}

export const Secondary: Story = {
  args: {
    children: 'Alle Produkte',
    variant: 'secondary',
    size: 'default',
  },
}

export const Loading: Story = {
  args: {
    children: 'Wird geladen',
    variant: 'default',
    size: 'default',
    isLoading: true,
  },
}

export const Disabled: Story = {
  args: {
    children: 'Nicht verfuegbar',
    variant: 'default',
    size: 'default',
    disabled: true,
  },
}

export const SizeSmall: Story = {
  args: {
    children: 'Klein',
    variant: 'default',
    size: 'sm',
  },
}

export const SizeLarge: Story = {
  args: {
    children: 'Gross',
    variant: 'default',
    size: 'lg',
  },
}

export const SizeIcon: Story = {
  args: {
    children: '×',
    variant: 'ghost',
    size: 'icon',
    'aria-label': 'Schliessen',
  },
}
