'use client'

import { useState, useEffect } from 'react'
import {
  hasConsentDecision,
  setConsentAccepted,
  setConsentRejected,
} from '@/lib/consent/cookie-consent'

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
        'bg-white border-t border-border shadow-lg',
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
          <button
            onClick={handleAccept}
            className={[
              'flex-1 py-2.5 px-4 rounded-lg font-medium text-sm',
              'bg-primary text-white',
              'hover:bg-primary-hover',
              'focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
              'transition-colors',
            ].join(' ')}
            style={{ touchAction: 'manipulation', minHeight: '2.75rem' }}
          >
            Alle Akzeptieren
          </button>

          <button
            onClick={handleReject}
            className={[
              'flex-1 py-2.5 px-4 rounded-lg font-medium text-sm',
              'bg-white text-text-primary border border-border',
              'hover:bg-surface-elevated',
              'focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2',
              'transition-colors',
            ].join(' ')}
            style={{ touchAction: 'manipulation', minHeight: '2.75rem' }}
          >
            Nur Notwendige
          </button>
        </div>
      </div>
    </div>
  )
}
