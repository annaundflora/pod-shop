// frontend/components/blocks/newsletter-signup-block.tsx
'use client'

import { useState } from 'react'
import type { BlockComponentProps } from '@/lib/blocks/types'

interface NewsletterSignupData {
  headline: string
  text: string
  buttonText: string
  placeholder: string
}

type FormStatus = 'idle' | 'loading' | 'success' | 'error'

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export function NewsletterSignupBlock({ data }: BlockComponentProps<NewsletterSignupData>) {
  const [email, setEmail] = useState('')
  const [status, setStatus] = useState<FormStatus>('idle')
  const [errorMessage, setErrorMessage] = useState('')

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    // Validation
    if (!email.trim()) {
      setErrorMessage('Bitte gib deine E-Mail-Adresse ein')
      setStatus('error')
      return
    }

    if (!EMAIL_REGEX.test(email.trim())) {
      setErrorMessage('Bitte gib eine gültige E-Mail-Adresse ein')
      setStatus('error')
      return
    }

    setStatus('loading')
    setErrorMessage('')
    console.log('[Newsletter] E-Mail eingereicht:', email)
    await new Promise(resolve => setTimeout(resolve, 500))
    setStatus('success')
  }

  if (status === 'success') {
    return (
      <section className="bg-surface-secondary py-12 px-4">
        <div className="max-w-lg mx-auto text-center">
          <div role="status" aria-live="polite" className="flex flex-col items-center gap-3">
            <svg
              className="h-12 w-12 text-green-600"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              aria-hidden={true}
            >
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
            </svg>
            <p className="text-lg font-medium text-text-primary">
              Danke für deine Anmeldung!
            </p>
            <p className="text-text-secondary text-sm">
              Wir halten dich auf dem Laufenden.
            </p>
          </div>
        </div>
      </section>
    )
  }

  return (
    <section className="bg-surface-secondary py-12 px-4">
      <div className="max-w-lg mx-auto text-center">
        <h2 className="text-2xl font-semibold text-text-primary mb-2">
          {data.headline}
        </h2>
        <p className="text-text-secondary mb-6 text-sm">
          {data.text}
        </p>

        <form onSubmit={handleSubmit} noValidate>
          <div className="flex gap-2">
            <label htmlFor="newsletter-email" className="sr-only">
              E-Mail-Adresse
            </label>
            <input
              id="newsletter-email"
              type="email"
              value={email}
              onChange={e => {
                setEmail(e.target.value)
                if (status === 'error') {
                  setStatus('idle')
                  setErrorMessage('')
                }
              }}
              placeholder={data.placeholder}
              disabled={status === 'loading'}
              aria-invalid={status === 'error'}
              aria-describedby={status === 'error' ? 'newsletter-error' : undefined}
              autoComplete="email"
              spellCheck={false}
              className="flex-1 border border-border rounded-card px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50"
            />
            <button
              type="submit"
              disabled={status === 'loading'}
              className="px-4 py-2 text-sm bg-primary text-primary-foreground rounded-card hover:opacity-90 disabled:opacity-50 flex items-center gap-2 whitespace-nowrap"
              style={{ touchAction: 'manipulation' }}
            >
              {status === 'loading' && (
                <span
                  className="h-4 w-4 border-2 border-white border-t-transparent rounded-full animate-spin"
                  aria-hidden={true}
                />
              )}
              {data.buttonText}
            </button>
          </div>

          {status === 'error' && errorMessage && (
            <p
              id="newsletter-error"
              role="alert"
              className="mt-2 text-xs text-red-600 text-left"
            >
              {errorMessage}
            </p>
          )}
        </form>
      </div>
    </section>
  )
}
