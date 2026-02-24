// frontend/stories/ui/Card.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { Card, CardHeader, CardTitle, CardContent, CardFooter, CardDescription } from '@/components/ui/card'

const meta: Meta<typeof Card> = {
  title: 'UI Primitives/Card',
  component: Card,
  tags: ['autodocs'],
  argTypes: {
    variant: {
      control: 'select',
      options: ['default', 'interactive'],
    },
  },
}

export default meta
type Story = StoryObj<typeof Card>

export const Default: Story = {
  args: {
    variant: 'default',
  },
  render: (args) => (
    <Card {...args} className="max-w-sm">
      <CardHeader>
        <CardTitle>Produktbeschreibung</CardTitle>
        <CardDescription>Details zu diesem Produkt</CardDescription>
      </CardHeader>
      <CardContent>
        <p className="text-sm text-text-secondary">
          Dies ist ein tolles Print-on-Demand Produkt mit einzigartigem Design.
        </p>
      </CardContent>
      <CardFooter>
        <p className="text-xs text-text-secondary">Gemaess §19 UStG keine Umsatzsteuer</p>
      </CardFooter>
    </Card>
  ),
}

export const Interactive: Story = {
  args: {
    variant: 'interactive',
  },
  render: (args) => (
    <Card {...args} className="max-w-sm cursor-pointer">
      <CardContent className="pt-6">
        <p className="text-text-primary font-medium">Interaktive Karte</p>
        <p className="text-sm text-text-secondary mt-1">Hover um den Effekt zu sehen</p>
      </CardContent>
    </Card>
  ),
}

export const WithAllSubComponents: Story = {
  render: () => (
    <Card className="max-w-sm">
      <CardHeader>
        <CardTitle>Titel der Karte</CardTitle>
        <CardDescription>Beschreibung der Karte</CardDescription>
      </CardHeader>
      <CardContent>
        <p className="text-sm text-text-secondary">Inhalt der Karte</p>
      </CardContent>
      <CardFooter className="justify-end gap-2">
        <p className="text-xs text-text-secondary flex-1">Fusszeile</p>
      </CardFooter>
    </Card>
  ),
}
