// frontend/stories/ui/Input.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { Input } from '@/components/ui/input'

const meta: Meta<typeof Input> = {
  title: 'UI Primitives/Input',
  component: Input,
  tags: ['autodocs'],
  argTypes: {
    disabled: { control: 'boolean' },
    placeholder: { control: 'text' },
    type: {
      control: 'select',
      options: ['text', 'email', 'password', 'number', 'search'],
    },
  },
}

export default meta
type Story = StoryObj<typeof Input>

export const Default: Story = {
  args: {
    placeholder: 'E-Mail eingeben',
    type: 'email',
  },
}

export const WithValue: Story = {
  args: {
    defaultValue: 'max.mustermann@example.de',
    type: 'email',
  },
}

export const Disabled: Story = {
  args: {
    placeholder: 'Deaktiviertes Feld',
    disabled: true,
  },
}

export const WithError: Story = {
  args: {
    placeholder: 'Ungueltige Eingabe',
    'aria-invalid': true,
    'aria-describedby': 'error-msg',
  },
  render: (args) => (
    <div className="space-y-1 max-w-xs">
      <Input {...args} />
      <p id="error-msg" className="text-xs text-error">
        Bitte gib eine gueltige E-Mail ein.
      </p>
    </div>
  ),
}

export const Password: Story = {
  args: {
    type: 'password',
    placeholder: 'Passwort eingeben',
  },
}
