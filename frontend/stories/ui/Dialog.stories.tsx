// frontend/stories/ui/Dialog.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import {
  Dialog,
  DialogTrigger,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'

const meta: Meta = {
  title: 'UI Primitives/Dialog',
  tags: ['autodocs'],
}

export default meta
type Story = StoryObj

export const OpenState: Story = {
  render: () => (
    <Dialog defaultOpen>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Artikel entfernen?</DialogTitle>
          <DialogDescription>
            Moechtest du diesen Artikel wirklich aus dem Warenkorb entfernen?
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline">Abbrechen</Button>
          <Button variant="destructive">Entfernen</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  ),
}

export const ClosedState: Story = {
  render: () => (
    <Dialog>
      <DialogTrigger asChild>
        <Button variant="outline">Dialog oeffnen</Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Dialog Titel</DialogTitle>
          <DialogDescription>
            Dies ist der Dialog-Inhalt. Klicke auf Schliessen um den Dialog zu beenden.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="default">Bestaetigen</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  ),
}
