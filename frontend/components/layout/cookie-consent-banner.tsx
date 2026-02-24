'use client'

import { useState, useEffect } from 'react'
import {
  hasConsentDecision,
  setConsentAccepted,
  setConsentRejected,
} from '@/lib/consent/cookie-consent'
import { Button } from '@/components/ui/button'

// WP_URL aus env – zeigt auf WooCommerce WordPress-Seite
const WP_URL = process.env.NEXT_PUBLIC_WP_URL ?? 'http://localhost:8080'

export function CookieConsentBanner() {
  const [isVisible, setIsVisible] = useState(false)

  useEffect(() => {
    // Banner nur anzeigen wenn noch keine Entscheidung getroffen wurde
    if (!hasConsentDecision()) {
      setIsVisible(true)
    }
  }, [])

  const handleAccept = () => {
    setConsentAccepted()
    setIsVisible(false)
  }

  const handleReject = () => {
    setConsentRejected()
    setIsVisible(false)
  }

  if (!isVisible) return null

  return (
    <div
      role="dialog"
      aria-modal="false"
      aria-label="Cookie-Einstellungen"
      aria-live="polite"
      className={[
        'fixed bottom-0 left-0 right-0 z-50',
        'bg-surface border-t border-border shadow-[var(--shadow-card-hover)]',
        'p-4 md:p-6',
        // prefers-reduced-motion: keine Slide-Animation
        'motion-safe:animate-slide-up',
      ].join(' ')}
    >
      <div className="max-w-2xl mx-auto">
        {/* Text */}
        <p className="text-sm text-text-primary mb-1">
          <strong>Wir verwenden Cookies</strong>
        </p>
        <p className="text-sm text-text-secondary mb-4">
          Diese Website nutzt Cookies für Analyse und Marketing.{' '}
          <a
            href={`${WP_URL}/datenschutz`}
            className="underline hover:text-text-primary focus-visible:ring-2 focus-visible:ring-primary rounded"
          >
            Mehr in der Datenschutzerklärung.
          </a>
        </p>

        {/* Buttons */}
        <div className="flex flex-col sm:flex-row gap-2">
          <Button
            onClick={handleAccept}
            variant="default"
            className="flex-1"
            style={{ touchAction: 'manipulation' }}
          >
            Alle Akzeptieren
          </Button>

          <Button
            onClick={handleReject}
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
