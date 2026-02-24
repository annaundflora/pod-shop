// frontend/stories/ui/Sheet.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import {
  Sheet,
  SheetTrigger,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'

const meta: Meta = {
  title: 'UI Primitives/Sheet',
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj

export const FromRight: Story = {
  render: () => (
    <Sheet>
      <SheetTrigger asChild>
        <Button variant="outline">Sheet von rechts</Button>
      </SheetTrigger>
      <SheetContent side="right">
        <SheetHeader className="mt-8">
          <SheetTitle>Navigation</SheetTitle>
          <SheetDescription>Seitennavigation</SheetDescription>
        </SheetHeader>
        <div className="py-4">
          <p className="text-text-secondary text-sm">Navigationsinhalte hier...</p>
        </div>
      </SheetContent>
    </Sheet>
  ),
}

export const FromLeft: Story = {
  render: () => (
    <Sheet>
      <SheetTrigger asChild>
        <Button variant="outline">Sheet von links</Button>
      </SheetTrigger>
      <SheetContent side="left">
        <SheetHeader className="mt-8">
          <SheetTitle>Menue</SheetTitle>
          <SheetDescription>Hauptnavigation</SheetDescription>
        </SheetHeader>
        <div className="py-4">
          <p className="text-text-secondary text-sm">Menue-Inhalte hier...</p>
        </div>
      </SheetContent>
    </Sheet>
  ),
}

export const FromTop: Story = {
  render: () => (
    <Sheet>
      <SheetTrigger asChild>
        <Button variant="outline">Sheet von oben</Button>
      </SheetTrigger>
      <SheetContent side="top">
        <SheetHeader>
          <SheetTitle>Benachrichtigung</SheetTitle>
          <SheetDescription>Neues Sheet von oben</SheetDescription>
        </SheetHeader>
      </SheetContent>
    </Sheet>
  ),
}

export const FromBottom: Story = {
  render: () => (
    <Sheet>
      <SheetTrigger asChild>
        <Button variant="outline">Sheet von unten</Button>
      </SheetTrigger>
      <SheetContent side="bottom">
        <SheetHeader>
          <SheetTitle>Filteroptionen</SheetTitle>
          <SheetDescription>Produkte filtern</SheetDescription>
        </SheetHeader>
        <div className="py-4">
          <p className="text-text-secondary text-sm">Filter-Optionen hier...</p>
        </div>
      </SheetContent>
    </Sheet>
  ),
}
