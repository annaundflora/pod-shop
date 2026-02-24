// frontend/stories/layout/CookieConsentBanner.stories.tsx
import type { Meta, StoryObj } from '@storybook/react'
import { Button } from '@/components/ui/button'

// CookieConsentBanner uses localStorage to decide if it should show.
// We render a static mock version for Storybook since the real component
// hides itself when localStorage has a consent decision.
function CookieConsentBannerMock() {
  return (
    <div
      role="dialog"
      aria-modal="false"
      aria-label="Cookie-Einstellungen"
      className="fixed bottom-0 left-0 right-0 z-50 bg-surface border-t border-border shadow-[var(--shadow-card-hover)] p-4 md:p-6"
    >
      <div className="max-w-2xl mx-auto">
        <p className="text-sm text-text-primary mb-1">
          <strong>Wir verwenden Cookies</strong>
        </p>
        <p className="text-sm text-text-secondary mb-4">
          Diese Website nutzt Cookies fuer Analyse und Marketing.{' '}
          <a href="#" className="underline hover:text-text-primary">
            Mehr in der Datenschutzerklaerung.
          </a>
        </p>

        <div className="flex flex-col sm:flex-row gap-2">
          <Button
            variant="default"
            className="flex-1"
            style={{ touchAction: 'manipulation' }}
          >
            Alle Akzeptieren
          </Button>

          <Button
            variant="outline"
            className="flex-1 bg-surface-elevated"
            style={{ touchAction: 'manipulation' }}
          >
            Nur Notwendige
          </Button>
        </div>
      </div>
    </div>
  )
}

const meta: Meta<typeof CookieConsentBannerMock> = {
  title: 'Layout/Cookie Consent Banner',
  component: CookieConsentBannerMock,
  tags: ['autodocs'],
  parameters: {
    layout: 'fullscreen',
  },
}

export default meta
type Story = StoryObj<typeof CookieConsentBannerMock>

export const Default: Story = {}
